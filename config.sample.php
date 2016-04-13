<?php

const BUGCACHE = [
    "mysql" => "host=localhost;user=bugcache;pass=bugcache;db=bugcache",
    "redis" => "tcp://localhost:6379",
    /* @see https://developers.google.com/recaptcha/docs/faq */
    "recaptchaKey" => "6LeIxAcTAAAAAJcZVRqyHh71UMIEGNQ_MXjiZKhI",
    "recaptchaSecret" => "6LeIxAcTAAAAAGG-vFI1TnRWxMZNFuojJ4WifJWe",
	"bugfields" => [
		"type" => [
			"name" => "Bug type",
			"type" => Bugcache\BugManager::ENUM,
			"required" => 1,
			"values" => [
				[
					"name" => "Bug",
				],
				[
					"name" => "Security Bug",
					"secure" => true,
					"default" => true,
				],
			]
		],
		"assignee" => [
			"name" => "Assignee",
			"type" => Bugcache\BugManager::USER,
			"multi" => true,
		],
		"system" => [
			"name" => "Operating System",
			"type" => Bugcache\BugManager::TEXT,
		],
	]
];

$host = (new Aerys\Host)
    ->name("localhost")
    ->expose("127.0.0.1", 8000)
    ->expose("::1", 8000)
    ->use(require __DIR__ . "/../../src/router.php");