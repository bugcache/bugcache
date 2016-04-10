<?php

namespace Bugcache;

use Aerys\{ Request, Response, Session };

class BugDisplay {
	private $manager;
	private $mustache;

	public function __construct(BugManager $manager, Mustache $mustache) {
		$this->manager = $manager;
		$this->mustache = $mustache;
	}

	public function index() {
		return function(Request $req, Response $res) {
			$session = $req->getLocalVar(RequestKeys::SESSION);

			$res->end($this->mustache->render("index.mustache", new TemplateContext($req)));
		};
	}

	public function recent() {
		return function(Request $req, Response $res) {
			$data = yield from $this->manager->recent($req);
			if ($data === null) {
				$res->setStatus(403);
				$res->end("Bad request");
				return;
			}
			$res->end($this->mustache->render("list.mustache", new TemplateContext($req, ["data" => $data])));
		};
	}

	public function displayBug() {
		return function(Request $req, Response $res, $routerInfo) {
			$data = yield from $this->manager->fetchBug($req, $routerInfo);
			if ($data === null) {
				$res->setStatus(403);
				$res->end("Bad request");
				return;
			}

			$data->id = (int) $routerInfo["id"];
			$res->end($this->mustache->render("bug.mustache", new TemplateContext($req, $data)));
		};
	}
}