<?php

namespace Bugcache;

use Amp\Mysql as mysql;
use Aerys\Request;
use function Aerys\parseBody;
use Amp as Amp;

class BugManager {
	const USER = 1;
	const TEXT = 2;
	const INT = 3;
	const ENUM = 4;
	
	private $bug;
	private $fields;
	private $user;
	
	public function __construct(array $fields, Storage\BugRepository $bug, Storage\UserRepository $user) {
		$fields["data"]["required"] = 1;
		$fields["title"]["required"] = 1;
		$fields["title"]["minlen"] = $fields["title"]["minlen"] ?? 1;
		
		$this->fields = $fields;
		$this->bug = $bug;
		$this->user = $user;
	}
	
	public function getFields() {
		return $this->fields;
	}
	
	public function submit(Request $req, array $routed = []) {
		$body = yield parseBody($req);

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
				$type = $info["type"] ?? self::TEXT;
				switch ($type) {
					case self::USER:
						if (filter_var($attr, FILTER_VALIDATE_INT)) {
							$promises[] = \Amp\pipe($this->user->findById($attr), function($user) {
								if (!$user["id"]) {
									return 1; // error, add identifier for message
								}
							});
						} else {
							$promises[] = \Amp\pipe($this->user->findByName($attr), function ($user) use (&$attr) {
								if ($user["id"]) {
									$attr = $user["id"];
								} else {
									return 1; // error, add identifier for message
								}
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
					default:
						if (!preg_match("//u", $attr)) {
							return null; // invalid UTF-8
						}
						if (isset($info["maxlen"]) && \strlen($attr) > $info["maxlen"]) {
							return null;
						}
						if (isset($info["minlen"]) && \strlen($attr) < $info["minlen"]) {
							return null;
						}
				}

				if ($field == "title") {
					$title = &$attr;
				} elseif ($field == "data") {
					$data = &$attr;
				} else {
					$add["attribute"][] = $field;
					$add["type"][] = $type;
					$add["value"][] = &$attr;
				}
				unset($attr);
			}
		}

		if ($errors = yield \Amp\filter($promises)) {
			return null;
		}

		$id = yield $this->bug->storeBug($routed["id"] ?? 0, $title, $data, $req->getLocalVar(RequestKeys::USER)["id"], $add);

		return ["id" => $id, "success" => true];
	}
	
	public function recent(Request $req) {
		$num = (int) ($req->getParam("num") ?? 100);

		return yield $this->bug->listDesc(PHP_INT_MAX, $num);
	}
	
	public function list(Request $req) {
		$num = (int) ($req->getParam("num") ?? 100);
		$last = (int) ($req->getParam("last") ?? 0);
		
		if ($req->getParam("order") == "desc") {
			return yield $this->bug->listDesc($last, $num);
		} else {
			return yield $this->bug->listAsc($last, $num);
		}
	}
	
	public function fetchBug(Request $req, array $routed) {
		$id = (int) $routed["id"];
		
		if (!$bugData = yield $this->bug->fetchBug($id)) {
			return ["error" => "no such bug"];
		}

		$bugData->attributes = [];
		$attrs = yield $this->bug->fetchAttrs($id);
		foreach ($attrs as $attr) {
			$name = $attr->attribute;
			if (!isset($bugData->attributes[$name])) {
				$bugData->attributes[$name] = $this->fields[$name];
			}
			$value = ["value" => $attr->value];
			if (isset($attr->username)) {
				$value["username"] = $attr->username;
			}
			$bugData->attributes[$name]["value"][] = $value;
		}

		return $bugData;

	}
	
	public function delete(Request $req, array $routed) {
		$id = (int) $routed["id"];
		
		$info = yield $this->bug->delete($id);
		
		if ($info->affectedRows) {
			return ["deleted" => true];
		} else {
			return ["deleted" => false, "error" => "no such bug"];
		}
	}
}