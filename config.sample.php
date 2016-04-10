<?php

const BUGCACHE = [
    "mysql" => "host=localhost;user=bugcache;pass=;db=bugcache",
    "redis" => "tcp://localhost:6379",
    /* https://developers.google.com/recaptcha/docs/faq */
    "recaptchaKey" => "6LeIxAcTAAAAAJcZVRqyHh71UMIEGNQ_MXjiZKhI",
    "recaptchaSecret" => "6LeIxAcTAAAAAGG-vFI1TnRWxMZNFuojJ4WifJWe",
];

$host = (new Aerys\Host)
    ->name("localhost")
    ->expose("127.0.0.1", 80)
    ->expose("::1", 80)
    ->use(require __DIR__ . "/src/router.php");