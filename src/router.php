<?php

namespace Bugcache;

use Aerys as aerys;

$mysql = new \Amp\Mysql\Pool(BUGCACHE["mysql"]);

$bugmanager = new BugManager($mysql);

$api = aerys\router()
	// @TODO ->use($token_validation)
	->put("/bug", jsonify([$bugmanager, "submit"]))
	->get("/list", jsonify([$bugmanager, "list"]))
	->get("/recent", jsonify([$bugmanager, "recent"]))
	->get("/bug/{id:[1-9][0-9]*}", jsonify([$bugmanager, "fetchBug"]))
	->delete("/bug/{id:[1-9][0-9]*}", jsonify([$bugmanager, "delete"]))
;

$mustache = new \Mustache_Engine([
	'loader' => new \Mustache_Loader_FilesystemLoader(__DIR__ . '/../public/templates'),
]);

$bugdisplay = new BugDisplay($bugmanager, $mustache);

$router = aerys\router()
	->use($api->prefix("/api"))
	->get("/", $bugdisplay->index())
	->get("/recent", $bugdisplay->recent())
	->get("/{id:[1-9][0-9]*}", $bugdisplay->displayBug())
	->use(aerys\root(__DIR__."/../public"))
;

return $router;