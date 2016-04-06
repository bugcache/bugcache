<?php

namespace Bugcache;

use Amp\Mysql as mysql;
use Aerys\{ Request, Server };
use Aerys as aerys;
use Amp as amp;

class BugManager implements aerys\ServerObserver, aerys\Bootable {
	private $db;
	private $submit;
	private $list;
	private $fetchBug;
	private $delete;
	
	public function __construct(mysql\Pool $db) {
		$this->db = $db;
	}
	
	public function boot(Server $server, aerys\Logger $logger) {
		$server->attach($this);
	}

	public function update(Server $server): amp\Promise {
		if ($server->state() == Server::STARTING) {
			return amp\all([
				"submit" => $this->db->prepare("INSERT INTO bugs (title, data) VALUES (:title, :data)"),
				"list" => $this->db->prepare("SELECT id, title FROM bugs ORDER BY id DESC LIMIT ? OFFSET ?"),
				"fetchBug" => $this->db->prepare("SELECT title, data FROM bugs WHERE id = ?"),
				"delete" => $this->db->prepare("DELETE FROM bugs WHERE id = ?"),
			])->when(function($e, $data) {
				if (!$e) {
					foreach ($data as $key => $val) {
						$this->$key = $val;
					}
				}
			});
		}

		return new amp\Success;
	}
	
	public function submit(Request $req) {
		$body = yield aerys\parseBody($req);

		$title = $body->get("title");
		$data = $body->get("data");

		if (!isset($title, $data)) {
			return null;
		}

		$info = yield $this->submit->execute(["title" => $title, "data" => $data]);

		return ["id" => $info->insertId];
	}
	
	public function list(Request $req) {
		$num = (int) ($req->getParam("num") ?? 100);
		$offset = (int) ($req->getParam("offset") ?? 0);
		
		$result = yield $this->list->execute([$num, $offset]);

		return yield $result->fetchObjects();
	}
	
	public function fetchBug(Request $req, array $routed) {
		$id = (int) $routed["id"];
		
		$result = yield $this->fetchBug->execute([$id]);
		
		if ($bugData = yield $result->fetchObject()) {
			return $bugData;
		}
		
		return ["error" => "no such bug"];
	}
	
	public function delete(Request $req, array $routed) {
		$id = (int) $routed["id"];
		
		$info = yield $this->delete->execute([$id]);
		
		if ($info->affectedRows) {
			return ["deleted" => true];
		} else {
			return ["deleted" => false, "error" => "no such bug"];
		}
	}
}