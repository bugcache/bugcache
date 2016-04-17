<?php

namespace Bugcache;

use Amp\Mysql as mysql;
use Aerys\{ Request, Server };
use Aerys as Aerys;
use Amp as Amp;

class BugManager implements Aerys\ServerObserver, Aerys\Bootable {
	const USER = 1;
	const TEXT = 2;
	const INT = 3;
	const ENUM = 4;
	
	private $db;
	private $fields;
	private $listDesc;
	private $listAsc;
	private $fetchBug;
	private $fetchAttrs;
	private $delete;
	
	public function __construct(array $fields, mysql\Pool $db) {
		$this->fields = $fields;
		$this->db = $db;
	}
	
	public function boot(Server $server, Aerys\Logger $logger) {
		$server->attach($this);
	}

	public function update(Server $server): Amp\Promise {
		if ($server->state() == Server::STARTING) {
			return Amp\all([
				"listDesc" => $this->db->prepare("SELECT id, title FROM bugs WHERE id < ? ORDER BY id DESC LIMIT ?"),
				"listAsc" => $this->db->prepare("SELECT id, title FROM bugs WHERE id > ? ORDER BY id ASC LIMIT ?"),
				"fetchBug" => $this->db->prepare("SELECT title, data, submitter, name AS submittername FROM bugs JOIN users ON (users.id = submitter) WHERE bugs.id = ?"),
				"fetchAttrs" => $this->db->prepare("SELECT attribute, value FROM bugattrs WHERE bug = ?"),
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
	
	public function getFields() {
		return $this->fields;
	}
	
	public function submit(Request $req, array $routed = []) {
		$body = yield Aerys\parseBody($req);

		$title = $body->get("title");
		$data = $body->get("data");

		if (!isset($title, $data)) {
			return null;
		}

		$add = [];
		$promises = [];
		foreach ($this->fields as $field => $info) {
			$attrs = array_filter($body->getArray($field), function ($x) { return $x != ""; });

			if (empty($info["multi"]) && count($attrs) > 1) {
				return null;
			}

			if (count($attrs) < ($info["required"] ?? 0)) {
				return null;
			}
			foreach ($attrs as $attr) {
				switch ($info["type"]) {
					case self::USER:
						if (filter_var($attr, FILTER_VALIDATE_INT)) {
							$promises[] = \Amp\pipe($this->db->prepare("SELECT id FROM users WHERE id = ?", [$attr]), function($val) {
								return \Amp\pipe($val->rowCount(), function($count) {
									if ($count == 0) {
										return 1; // error, add identifier for message
									}
								});
							});
						} else {
							$promises[] = \Amp\pipe($this->db->prepare("SELECT id FROM users WHERE name = ?", [$attr]), function ($val) use (&$attr) {
								return \Amp\pipe($val->fetchObject(), function ($row) use (&$attr) {
									if ($row) {
										$attr = $row->id;
									} else {
										return 1; // error, add identifier for message
									}
								});
							});
						}
						break;
					case self::INT:
						if (($attr = filter_var($attr, FILTER_VALIDATE_INT)) === false) {
							return null;
						}
					case self::ENUM:
						foreach ($info["values"] as $val) {
							if ($attr == $val["name"]) {
								break 2;
							}
						}
						return null;
				}

				$add[] = &$id;
				$add[] = $field;
				$add[] = &$attr;
				unset($attr);
			}
		}

		if ($errors = yield \Amp\filter($promises)) {
			return null;
		}

		$conn = yield $this->db->getConnection();
		$conn->query("START TRANSACTION");
		
		if ($id = $routed["id"] ?? 0) {
			$info = yield $conn->prepare("UPDATE bugs SET title = :title, data = :data WHERE id = :id", ["id" => $id, "title" => $title, "data" => $data]);
			if (!$info->affectedRows) {
				preg_match('(matched: (\d))i', $info->statusInfo, $m);
				if (!$m[1]) {
					return null;
				}
			}
			yield $conn->prepare("DELETE FROM bugattrs WHERE bug = :id", ["id" => $id]);
		} else {
			$uid = $req->getLocalVar(RequestKeys::USER);
			$info = yield $conn->prepare("INSERT INTO bugs (title, data, submitter) VALUES (:title, :data, :submitter)", ["title" => $title, "data" => $data, "submitter" => $uid]);
			$id = $info->insertId;
		}

		if ($add) {
			yield $conn->prepare("INSERT INTO bugattrs (bug, attribute, value) VALUES (?, ?, ?)" . str_repeat(", (?, ?, ?)", count($add) / 3 - 1), $add);
		}
		
		yield $conn->query("COMMIT");

		return ["id" => $id, "success" => true];
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
		if (!$bugData = yield $result->fetchObject()) {
			return ["error" => "no such bug"];
		}

		$bugData->attributes = [];
		$attrs = yield (yield $this->fetchAttrs->execute([$id]))->fetchObjects();
		foreach ($attrs as $attr) {
			$name = $attr->attribute;
			if (!isset($bugData->attributes[$name])) {
				$bugData->attributes[$name] = $this->fields[$name];
			}
			$bugData->attributes[$name]["value"][] = $attr->value;
		}
		
		return $bugData;

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