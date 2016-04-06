<?php

namespace Bugcache;

use Aerys as aerys;

$mysql = new \Amp\Mysql\Pool(BUGCACHE["mysql"]);

$bugmanager = new BugManager($mysql);

$api = aerys\router()
	// @TODO ->use($token_validation)
	->put("/submit", jsonify([$bugmanager, "submit"]))
	->get("/list", jsonify([$bugmanager, "list"]))
	->get("/recent", jsonify([$bugmanager, "recent"]))
	->get("/bug/{id:[1-9][0-9]*}", jsonify([$bugmanager, "fetchBug"]))
	->delete("/bug/{id:[1-9][0-9]*}", jsonify([$bugmanager, "delete"]))
;

$router = aerys\router()
	->use($api->prefix("/api"))
	->get("/", function(){} /* display logic */)
	->use(aerys\root(__DIR__."/../public"))
;

return $router;