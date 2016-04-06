<?php

namespace Bugcache;

use Aerys\{ Request, Response };

class BugDisplay {
	private $manager;
	private $mustache;

	public function __construct(BugManager $manager, \Mustache_Engine $mustache) {
		$this->manager = $manager;
		$this->mustache = $mustache;
	}
	
	public function index() {
		return function(Request $req, Response $res) {
			$res->end($this->mustache->render("index.mustache"));
		};
	}
	
	public function recent() {
		return function(Request $req, Response $res) {
			$data = yield from $this->manager->recent($req);
			if (!$data) {
				$res->setStatus(403);
				$res->end("Bad request");
				return;
			}
			$res->end($this->mustache->render("list.mustache", ["data" => $data]));
		};
	}

	public function displayBug() {
		return function(Request $req, Response $res, $routerInfo) {
			$data = yield from $this->manager->fetchBug($req, $routerInfo);
			if (!$data) {
				$res->setStatus(403);
				$res->end("Bad request");
				return;
			}

			$data->id = (int) $routerInfo["id"];
			$res->end($this->mustache->render("bug.mustache", $data));
		};
	}
}