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

			dispatchJob(createJob('bind_add_domain', ['domain' => $domain->getDomainRaw()]));
		});

		EventQueue::get()->subscribe('domain.rename', function($oldName, $domainid) {
			$domain = Domain::load(DB::get(), $domainid);

			dispatchJob(createJob('bind_rename_domain', ['oldName' => $oldName, 'domain' => $domain->getDomainRaw()]));
		});

		$updateDependants = function($domainid) {
			// Find any domains that also need to change.
			$s = new Search(DB::get()->getPDO(), 'records', ['domain_id']);
			$s->where('remote_domain_id', $domainid);
			// TODO: UNHACK THIS.
			// We're horrible abusing how php-db handles `limit` clauses here and we should
			// not do this.
			$s->addOperation(new class(0) extends shanemcc\phpdb\Operations\Limit { public function __toString() { return ' GROUP BY `domain_id`'; } });

			foreach ($s->getRows() as $r) {
				// Bump the serial and rebuild any domains that also might need
				// to change.
				$dependent = Domain::load(DB::get(), $r['domain_id']);
				$dependent->updateSerial();
				dispatchJob(createJob('bind_records_changed', ['domain' => $dependent->getDomainRaw()]));
			}
		};

		EventQueue::get()->subscribe('domain.delete', function($domainid, $domainRaw) use ($updateDependants) {
			dispatchJob(createJob('bind_delete_domain', ['domain' => $domainRaw]));
			call_user_func_array($updateDependants, [$domainid]);
		});

		EventQueue::get()->subscribe('domain.records.changed', function($domainid) use ($updateDependants) {
			$domain = Domain::load(DB::get(), $domainid);

			$domains = [];
			$domains[] = $domain;

			$checkDomains = $domain->getAliases();

			while ($alias = array_shift($checkDomains)) {
				$domains[] = $alias;
				$checkDomains = array_merge($checkDomains, $alias->getAliases());
			}

			foreach ($domains as $d) {
				dispatchJob(createJob('bind_records_changed', ['domain' => $d->getDomainRaw()]));
			}
			call_user_func_array($updateDependants, [$domainid]);
		});

		EventQueue::get()->subscribe('domain.sync', function($domainid) {
			$domain = Domain::load(DB::get(), $domainid);

			$remove = createJob('bind_zone_changed', ['domain' => $domain->getDomainRaw(), 'change' => 'remove']);

			$change = createJob('bind_records_changed', ['domain' => $domain->getDomainRaw(), '__wait' => 1]);
			$change->addDependency($remove->getID())->setState('blocked')->save();

			$add = createJob('bind_zone_changed', ['domain' => $domain->getDomainRaw(), 'change' => 'add']);
			$add->addDependency($change->getID())->setState('blocked')->save();

			dispatchJob($remove);
		});
	}

/*
	require_once('/dnsapi/functions.php');
	print_r(JobQueue::get()->publishAndWait(JobQueue::get()->create('', [])));
*/
