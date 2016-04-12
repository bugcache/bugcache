<?php

namespace Bugcache;

use Aerys;
use Aerys\Request;
use Amp\Artax\Client;
use Amp\Artax\Cookie\NullCookieJar;
use Amp\Mysql;
use Amp\Redis;
use Bugcache\Authentication\{ LoginHandler, LoginManager };
use Bugcache\Captcha\RecaptchaVerifier;
use Bugcache\RateLimit\CaptchaProtection;
use Bugcache\Storage\Mysql\{ AuthenticationRepository, ConfigRepository, UserRepository };
use Kelunik\RateLimit;

$mysql = new Mysql\Pool(BUGCACHE["mysql"]);
$redis = new Redis\Client(BUGCACHE["redis"]);
$mutex = new Redis\Mutex(BUGCACHE["redis"], []);

$mustache = new Mustache(new \Mustache_Engine([
    'loader' => new \Mustache_Loader_FilesystemLoader(__DIR__ . '/../res/templates'),
]));

$captchaVerifier = new RecaptchaVerifier(new Client(new NullCookieJar), BUGCACHE["recaptchaSecret"]);

$bugmanager = new BugManager($mysql);
$loginHandler = new LoginHandler(new ConfigRepository($mysql), new UserRepository($mysql), new AuthenticationRepository($mysql), new LoginManager(), $mustache);
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
    ->use(function(Request $request) {
        $request->setLocalVar(RequestKeys::RECAPTCHA, [
            "key" => BUGCACHE["recaptchaKey"]
        ]);
    })
    ->use($loginHandler)
    ->get("/", $bugdisplay->index())
    ->get("/recent", $bugdisplay->recent())
    ->get("/{id:[1-9][0-9]*}", $bugdisplay->displayBug())
    ->get("/login", [$loginHandler, "showLogin"])
    ->post("/login", [$loginHandler, "processPasswordLogin"])
    ->post("/logout", [$loginHandler, "processLogout"])
    ->get("/register", [$loginHandler, "showRegister"])
    ->post("/register", new CaptchaProtection($captchaVerifier, $mustache), [$loginHandler, "processRegister"])
    ->use(Aerys\session([
        "driver" => new Aerys\Session\Redis($redis, $mutex)
    ]))
    ->use(new class implements Aerys\Middleware {
        public function do (Aerys\InternalRequest $request) {
            $headers = yield;

            $headers["x-frame-options"] = ["SAMEORIGIN"];
            $headers["x-xss-protection"] = ["1; mode=block"];
            $headers["x-ua-compatible"] = ["IE=Edge,chrome=1"];
            $headers["x-content-type-options"] = ["nosniff"];

            return $headers;
        }
    })
;

$router = Aerys\router()
    ->use($ui)
    ->use($api->prefix("/api"))
    ->get("/{path:.*}", Aerys\root(dirname(__DIR__) . "/public"))
;

return $router;