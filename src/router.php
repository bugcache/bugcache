<?php

namespace Bugcache;

use Aerys;
use Aerys\Request;
use Aerys\Response;
use Aerys\Session;
use Amp\Mysql;
use Amp\Redis;
use Bugcache\Authentication\LoginHandler;
use Bugcache\Authentication\LoginManager;
use Bugcache\Storage\Mysql\AuthenticationRepository;
use Bugcache\Storage\Mysql\UserRepository;

$mysql = new Mysql\Pool(BUGCACHE["mysql"]);
$redis = new Redis\Client(BUGCACHE["redis"]);
$mutex = new Redis\Mutex(BUGCACHE["redis"], []);

$bugmanager = new BugManager($mysql);
$loginHandler = new LoginHandler(new UserRepository($mysql), new AuthenticationRepository($mysql), new LoginManager(), new Mustache(new \Mustache_Engine));

$api = Aerys\router()
    // @TODO ->use($token_validation)
    ->put("/bugs", jsonify([$bugmanager, "submit"]))
    ->get("/bugs", jsonify([$bugmanager, "list"]))
    ->get("/bugs/recent", jsonify([$bugmanager, "recent"]))
    ->get("/bugs/{id:[1-9][0-9]*}", jsonify([$bugmanager, "fetchBug"]))
    ->delete("/bugs/{id:[1-9][0-9]*}", jsonify([$bugmanager, "delete"]))
;

$mustache = new \Mustache_Engine([
    'loader' => new \Mustache_Loader_FilesystemLoader(__DIR__ . '/../public/templates'),
]);

$bugdisplay = new BugDisplay($bugmanager, $mustache);

$router = Aerys\router()
    ->get("/", function (Request $request, Response $response) {
        /** @var Session $session */
        $session = yield (new Session($request))->read();

        if ($session->get(SessionKeys::LOGIN)) {
            $response->end("<form action='/logout' method='POST'><button type='submit'>logout</button></form>");
        } else {
            $response->end("<a href='/login'>login</a>");
        }
    })
    ->get("/dashboard", $bugdisplay->index())
    ->get("/recent", $bugdisplay->recent())
    ->get("/{id:[1-9][0-9]*}", $bugdisplay->displayBug())
    ->get("/login", [$loginHandler, "showLogin"])
    ->post("/login", [$loginHandler, "processPasswordLogin"])
    ->post("/logout", [$loginHandler, "processLogout"])
    ->use(Aerys\root(__DIR__ . "/../public"))
    ->use($api->prefix("/api"))
    ->use($loginHandler)
    ->use(Aerys\session([
        "driver" => new Aerys\Session\Redis($redis, $mutex)
    ]))
;

return $router;