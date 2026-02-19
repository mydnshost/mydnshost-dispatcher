<?php
	use shanemcc\phpdb\DB;
	use shanemcc\phpdb\Search;

	// --------------------
	// Bind
	// --------------------
	// This could also be used by other servers that support bind zonefiles
	// --------------------
	// zonedir = Directory to put zone files
	// catalogZone = If non-empty, this file (full path) will be used as a
	//               catalog zone.

	// --------------------
	// $config['hooks']['bind']['enabled'] = 'true';
	// $config['hooks']['bind']['catalogZoneFile'] = '/tmp/bindzones/catalog.db';
	// $config['hooks']['bind']['catalogZoneName'] = 'catalog.invalid';
	// $config['hooks']['bind']['zonedir'] = '/tmp/bindzones';
	// $config['hooks']['bind']['slaveServers'] = ['1.1.1.1', '2.2.2.2', '3.3.3.3'];

	if (isset($config['hooks']['bind']['enabled']) && parseBool($config['hooks']['bind']['enabled'])) {
		EventQueue::get()->subscribe('domain.add', function($domainid) {
			$domain = Domain::load(DB::get(), $domainid);

			dispatchJob(createJob('bind_add_domain', ['domain' => $domain->getDomainRaw()], 'Domain added' . actorSuffix()));
		});

		EventQueue::get()->subscribe('domain.rename', function($oldName, $domainid) {
			$domain = Domain::load(DB::get(), $domainid);

			dispatchJob(createJob('bind_rename_domain', ['oldName' => $oldName, 'domain' => $domain->getDomainRaw()], 'Domain renamed from ' . $oldName . actorSuffix()));
		});

		$updateDependants = function($domainid, $reason, $parentJob = null) {
			// Find any domains that also need to change.
			$s = new Search(DB::get()->getPDO(), 'records', ['domain_id']);
			$s->where('remote_domain_id', $domainid);
			// TODO: UNHACK THIS.
			// We're horrible abusing how php-db handles `limit` clauses here and we should
			// not do this.
			$s->addOperation(new class(0) extends shanemcc\phpdb\Operations\Limit { public function __toString() { return ' GROUP BY `domain_id`'; } });

			$parentId = ($parentJob !== null) ? $parentJob->getID() : null;
			foreach ($s->getRows() as $r) {
				$dependent = Domain::load(DB::get(), $r['domain_id']);
				echo showTime(), ' ', 'Updating dependent domain ', $dependent->getDomainRaw(), ' (has records referencing ', $reason, ')', "\n";
				// Serial bump deferred to worker - only bumped if RRCLONE expansion actually changed
				dispatchJob(createJob('bind_records_changed', ['domain' => $dependent->getDomainRaw(), '__dependant' => true], 'Has records referencing ' . $reason, $parentId));
			}
		};

		EventQueue::get()->subscribe('domain.delete', function($domainid, $domainRaw) use ($updateDependants) {
			$deleteJob = createJob('bind_delete_domain', ['domain' => $domainRaw], 'Domain deleted' . actorSuffix());
			dispatchJob($deleteJob);
			call_user_func_array($updateDependants, [$domainid, $domainRaw, $deleteJob]);
		});

		EventQueue::get()->subscribe('domain.records.changed', function($domainid) use ($updateDependants) {
			$domain = Domain::load(DB::get(), $domainid);

			$domains = [];
			$domains[] = $domain;
			$seen = [$domain->getID() => true];

			$checkDomains = $domain->getAliases();

			while ($alias = array_shift($checkDomains)) {
				if (isset($seen[$alias->getID()])) { continue; }
				$seen[$alias->getID()] = true;
				echo showTime(), ' ', 'Including alias domain ', $alias->getDomainRaw(), ' (alias of ', $domain->getDomainRaw(), ')', "\n";
				$domains[] = $alias;
				$checkDomains = array_merge($checkDomains, $alias->getAliases());
			}

			$suffix = actorSuffix();
			$primaryJob = null;
			foreach ($domains as $d) {
				if ($d === $domain) {
					$jobReason = 'Records changed' . $suffix;
				} else {
					$jobReason = 'Alias of ' . $domain->getDomainRaw() . $suffix;
				}
				$parentId = ($primaryJob !== null) ? $primaryJob->getID() : null;
				$job = createJob('bind_records_changed', ['domain' => $d->getDomainRaw()], $jobReason, $parentId);
				if ($primaryJob === null) { $primaryJob = $job; }
				dispatchJob($job);
				call_user_func_array($updateDependants, [$d->getID(), $d->getDomainRaw(), $primaryJob]);
			}
		});

		EventQueue::get()->subscribe('domain.sync', function($domainid) {
			$domain = Domain::load(DB::get(), $domainid);
			$suffix = actorSuffix();

			$remove = createJob('bind_zone_changed', ['domain' => $domain->getDomainRaw(), 'change' => 'remove'], 'Domain sync' . $suffix . ': remove zone');

			$change = createJob('bind_records_changed', ['domain' => $domain->getDomainRaw(), '__wait' => 1], 'Domain sync' . $suffix . ': rebuild records');
			$change->addDependency($remove->getID())->setState('blocked')->save();

			$add = createJob('bind_zone_changed', ['domain' => $domain->getDomainRaw(), 'change' => 'add'], 'Domain sync' . $suffix . ': re-add zone');
			$add->addDependency($change->getID())->setState('blocked')->save();

			dispatchJob($remove);
		});
	}

/*
	require_once('/dnsapi/functions.php');
	print_r(JobQueue::get()->publishAndWait(JobQueue::get()->create('', [])));
*/
