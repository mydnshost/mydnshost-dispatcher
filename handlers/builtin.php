<?php
	use shanemcc\phpdb\DB;

	EventQueue::get()->subscribe('mail.send', function($to, $subject, $message, $htmlmessage = NULL) {
		dispatchJob(createJob('sendmail', ['to' => $to, 'subject' => $subject, 'message' => $message, 'htmlmessage' => $htmlmessage], 'Send email'));
	});

	EventQueue::get()->subscribe('2fa.push.verify', function($keyid, $message) {
		$key = TwoFactorKey::load(DB::get(), $keyid);

		dispatchJob(createJob('verify_2fa_push', ['keyid' => $key->getID(), 'userid' => $key->getUserID(), 'message' => $message], '2FA push verification'));
	});

	EventQueue::get()->subscribe('domain.verify', function($domainid) {
		$domain = Domain::load(DB::get(), $domainid);

		dispatchJob(createJob('verify_domain', ['domain' => $domain->getDomainRaw()], 'Domain verification requested' . actorSuffix()));
	});

	EventQueue::get()->subscribe('cron.hourly', function() {
		dispatchJob(createJob('verify_domains', [], 'Hourly verification sweep'));
	});
