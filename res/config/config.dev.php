<?php

use Amp\Mysql\Pool;
use Amp\Pause;
use Amp\Socket;
use function Amp\resolve;

const BUGCACHE = [
    "mysql" => "host=mysql:3306;user=bugcache;pass=bugcache;db=bugcache",
    "redis" => "tcp://redis:6379",
    /* @see https://developers.google.com/recaptcha/docs/faq */
    "recaptchaKey" => "6LeIxAcTAAAAAJcZVRqyHh71UMIEGNQ_MXjiZKhI",
    "recaptchaSecret" => "6LeIxAcTAAAAAGG-vFI1TnRWxMZNFuojJ4WifJWe",
];

$host = (new Aerys\Host)
    ->name("localhost")
    ->expose("*", 8000)
    ->use(require __DIR__ . "/../../src/router.php");

$boot = function () {
    $start = time();

    echo "Waiting for MySQL to accept connections ...";

    do {
        try {
            yield Socket\connect("tcp://mysql:3306");

            break;
        } catch (Socket\SocketException $e) {
            yield new Pause(1000);

            echo ".";
        }
    } while (time() < $start + 30);

    echo PHP_EOL;

    if (time() > $start + 30) {
        throw new RuntimeException("MySQL server unavailable.");
    }

    $mysql = new Pool(BUGCACHE["mysql"]);

    yield $mysql->query(file_get_contents(__DIR__ . "/../database/schema.sql"));
    yield $mysql->query(file_get_contents(__DIR__ . "/../database/init.sql"));
};

return resolve($boot());