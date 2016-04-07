<?php

namespace Bugcache;

use Aerys;
use Amp\Mysql;
use Amp\Redis;
use Bugcache\Authentication\{ LoginHandler, LoginManager };
use Bugcache\Storage\Mysql\{ AuthenticationRepository, UserRepository };

$mysql = new Mysql\Pool(BUGCACHE["mysql"]);
$redis = new Redis\Client(BUGCACHE["redis"]);
$mutex = new Redis\Mutex(BUGCACHE["redis"], []);

$mustache = new Mustache(new \Mustache_Engine([
    'loader' => new \Mustache_Loader_FilesystemLoader(__DIR__ . '/../res/templates'),
]));

$bugmanager = new BugManager($mysql);
$loginHandler = new LoginHandler(new UserRepository($mysql), new AuthenticationRepository($mysql), new LoginManager(), $mustache);
$bugdisplay = new BugDisplay($bugmanager, $mustache);

$api = Aerys\router()
    // @TODO ->use($token_validation)
    ->put("/bugs", jsonify([$bugmanager, "submit"]))
    ->get("/bugs", jsonify([$bugmanager, "list"]))
    ->get("/bugs/recent", jsonify([$bugmanager, "recent"]))
    ->get("/bugs/{id:[1-9][0-9]*}", jsonify([$bugmanager, "fetchBug"]))
    ->delete("/bugs/{id:[1-9][0-9]*}", jsonify([$bugmanager, "delete"]))
;

$ui = Aerys\router()
    ->use($loginHandler)
    ->get("/", $bugdisplay->index())
    ->get("/recent", $bugdisplay->recent())
    ->get("/{id:[1-9][0-9]*}", $bugdisplay->displayBug())
    ->get("/login", [$loginHandler, "showLogin"])
    ->post("/login", [$loginHandler, "processPasswordLogin"])
    ->post("/logout", [$loginHandler, "processLogout"])
    ->use(Aerys\session([
        "driver" => new Aerys\Session\Redis($redis, $mutex)
    ]))
;

$router = Aerys\router()
    ->use($ui)
    ->use($api->prefix("/api"))
    ->use(Aerys\root(__DIR__ . "/../public"))
;

return $router;