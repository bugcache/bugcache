<?php

namespace Bugcache;

use Aerys\{ Request, Response, Session };

class BugDisplay {
	const TYPE_MAP = [
		BugManager::INT => "number",
	];

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

			$attributes = $data->attributes;
			$data->attributes = [];
			foreach ($attributes as $attr => $value) {
				$data->attributes[] = $value + ["field" => $attr];
			}

			$data->id = (int) $routerInfo["id"];
			$res->end($this->mustache->render("bug.mustache", new TemplateContext($req, $data)));
		};
	}

	public function submitBug() {
		return function (Request $req, Response $res, $routerInfo) {
			$data = yield from $this->manager->submit($req, $routerInfo);
			if ($data["success"] ?? false) {
				$res->setStatus(302);
				$res->addHeader("Location", "/{$data['id']}");
				$res->end();
			} else {
				yield from $this->editBug()($req, $res, $routerInfo);
			}
		};
	}

	public function editBug() {
		return function (Request $req, Response $res, $routerInfo) {
			$fields = $this->manager->getFields();

			if (isset($routerInfo["id"])) {
				$data = yield from $this->manager->fetchBug($req, $routerInfo);
				if ($data === null) {
					$res->setStatus(403);
					$res->end("Bad request");
					return;
				}
				$data->id = $routerInfo["id"];
			} else {
				$data = new \stdClass;
				$data->attributes = $fields;
			}

			$body = yield \Aerys\parseBody($req);

			if (($title = $body->get("title")) !== null) {
				$data->title = $title;
			}
			if (($title = $body->get("data")) !== null) {
				$data->title = $title;
			}

			$attributes = $data->attributes;
			$data->attributes = [];
			foreach ($fields as $attr => $field) {
				if ($values = $body->getArray($attr)) {
					$attributes[$attr]["value"] = $values;
				}
				if (isset($attributes[$attr])) {
					$field += $attributes[$attr];
					if (is_array($field["values"] ?? null)) {
						foreach ($field["values"] as &$value) {
							unset($value["default"]);
							if (in_array($value["name"], $field["value"])) {
								$value["default"] = true;
							}
						}
					}
				}
				$field["type"] = self::TYPE_MAP[$field["type"]] ?? "text";
				$data->attributes[] = $field + ["field" => $attr];
			}

			$res->end($this->mustache->render("edit.mustache", new TemplateContext($req, $data)));
		};
	}
}