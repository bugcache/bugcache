<?php

const BUGCACHE = [
    "mysql" => "host=localhost;user=bugcache;pass=;db=bugcache",
    "redis" => "tcp://localhost:6379",
];

(new Aerys\Host)
    ->name("localhost")
    ->expose("127.0.0.1", 80)
    ->expose("::1", 80)
    ->use(require 'src/router.php');