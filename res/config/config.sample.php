<?php

const BUGCACHE = [
    "mysql" => "host=mysql:3306;user=bugcache;pass=bugcache;db=bugcache",
    "redis" => "tcp://redis:6379",
    /* https://developers.google.com/recaptcha/docs/faq */
    "recaptchaKey" => "6LeIxAcTAAAAAJcZVRqyHh71UMIEGNQ_MXjiZKhI",
    "recaptchaSecret" => "6LeIxAcTAAAAAGG-vFI1TnRWxMZNFuojJ4WifJWe",
];

// Wait for MySQL to start up
sleep(5);

echo `mysql --host mysql -uroot -proot < /app/res/database/schema.sql`;
echo `mysql --host mysql -uroot -proot bugcache < /app/res/database/init.sql`;

$host = (new Aerys\Host)
    ->name("localhost")
    ->expose("*", 8000)
    ->use(require __DIR__ . "/../../src/router.php");