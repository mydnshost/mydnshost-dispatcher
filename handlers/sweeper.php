<?php
	use shanemcc\phpdb\DB;

	// Jobs that haven't moved on after this long are considered stuck.
	define('SWEEP_STUCK_AFTER', 300);

	/**
	 * Find jobs in the given states, created within the given range.
	 *
	 * @param $states Array of states.
	 * @param $createdAfter Only jobs created at or after this time.
	 * @param $createdBefore Only jobs created before this time.
	 * @param $limit Maximum number of jobs to return.
	 * @return Array of Jobs.
	 */
	function findJobsByState($states, $createdAfter, $createdBefore, $limit = 1000) {
		$placeholders = implode(', ', array_fill(0, count($states), '?'));
		$query = 'SELECT `id` FROM `jobs` WHERE `state` IN (' . $placeholders . ') AND `created` >= ? AND `created` < ? ORDER BY `id` LIMIT ' . intval($limit);
		$statement = DB::get()->getPDO()->prepare($query);
		$statement->execute(array_merge($states, [$createdAfter, $createdBefore]));

		return Job::findByID(DB::get(), $statement->fetchAll(PDO::FETCH_COLUMN)) ?: [];
	}

	/**
	 * Find jobs that have been missed somewhere along the way and get them
	 * moving again, or expire them if they are too old to run safely.
	 */
	function sweepJobs() {
		static $lastSweep = 0;

		// If we've been down, cron events will have queued up. Don't sweep
		// for every one of them.
		if ($lastSweep > time() - 30) { return; }
		$lastSweep = time();

		$now = time();
		$tooOld = $now - JobQueue::MAX_AGE;
		$stuck = $now - SWEEP_STUCK_AFTER;

		foreach (findJobsByState(['created', 'blocked'], 0, $tooOld) as $job) {
			echo showTime(), ' ', 'Sweeper: expiring ', $job->getState(), ' job: ', $job->getID(), "\n";
			$job->setState('expired')->setFinished($now)->setResult('EXPIRED')->save();
		}

		// Blocked jobs whose dependencies are all done, eg if the job.finished
		// event that should have started them was lost.
		foreach (findJobsByState(['blocked'], $tooOld, $stuck) as $job) {
			$ready = true;
			foreach ($job->getDependsOn() as $parent) {
				if (!in_array($parent->getState(), ['finished', 'error'])) {
					$ready = false;
				}
			}

			if ($ready) {
				echo showTime(), ' ', 'Sweeper: releasing blocked job: ', $job->getID(), "\n";
				tryStartJob($job);
			}
		}

		// Created jobs that never made it to a worker.
		$created = [];
		foreach (findJobsByState(['created'], $tooOld, $stuck) as $job) {
			$created[$job->getName()][] = $job;
		}

		foreach ($created as $name => $jobs) {
			// If messages are still queued, these may just be waiting behind
			// them. Leave them until the queue drains rather than piling up
			// duplicates.
			$queued = JobQueue::get()->getQueuedCount($name);
			if ($queued > 0) {
				echo showTime(), ' ', 'Sweeper: ', count($jobs), ' stuck ', $name, ' job(s), but ', $queued, ' still queued. Waiting.', "\n";
				continue;
			}

			foreach ($jobs as $job) {
				echo showTime(), ' ', 'Sweeper: republishing stuck job: ', $job->getID(), "\n";
				dispatchJob($job);
			}
		}
	}

	EventQueue::get()->subscribe('cron.minutely', function() {
		sweepJobs();
	});
