<?php

namespace Bugcache;

use Amp\Mysql as mysql;
use Aerys\{ Request, Server };
use Aerys as Aerys;
use Amp as Amp;

class BugManager implements Aerys\ServerObserver, Aerys\Bootable {
	private $db;
	private $submit;
	private $listDesc;
	private $listAsc;
	private $fetchBug;
	private $delete;
	
	public function __construct(mysql\Pool $db) {
		$this->db = $db;
	}
	
	public function boot(Server $server, Aerys\Logger $logger) {
		$server->attach($this);
	}

	public function update(Server $server): Amp\Promise {
		if ($server->state() == Server::STARTING) {
			return Amp\all([
				"submit" => $this->db->prepare("INSERT INTO bugs (title, data) VALUES (:title, :data)"),
				"listDesc" => $this->db->prepare("SELECT id, title FROM bugs WHERE id < ? ORDER BY id DESC LIMIT ?"),
				"listAsc" => $this->db->prepare("SELECT id, title FROM bugs WHERE id > ? ORDER BY id ASC LIMIT ?"),
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

		return new Amp\Success;
	}
	
	public function submit(Request $req) {
		$body = yield Aerys\parseBody($req);

		$title = $body->get("title");
		$data = $body->get("data");

		if (!isset($title, $data)) {
			return null;
		}

		$info = yield $this->submit->execute(["title" => $title, "data" => $data]);

		return ["id" => $info->insertId];
	}
	
	public function recent(Request $req) {
		$num = (int) ($req->getParam("num") ?? 100);

		$result = yield $this->listDesc->execute([PHP_INT_MAX, $num]);

		return yield $result->fetchObjects();
	}
	
	public function list(Request $req) {
		$num = (int) ($req->getParam("num") ?? 100);
		$last = (int) ($req->getParam("last") ?? 0);
		
		if ($req->getParam("order") == "desc") {
			$stmt = $this->listDesc;
		} else {
			$stmt = $this->listAsc;
		}
		
		$result = yield $stmt->execute([$last, $num]);

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