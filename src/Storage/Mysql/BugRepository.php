<?php

namespace Bugcache\Storage\Mysql;

use Amp\Promise;

class BugRepository implements \Bugcache\Storage\BugRepository {
	private $db;
	
	public function __construct(\Amp\Mysql\Pool $db) {
		$this->db = $db;
	}

	public function storeBug(int $id, string $title, string $data, int $uid, array $attributes): Promise {
		return \Amp\resolve($this->updateBug($id, $title, $data, $uid, $attributes));
	}
	
	private function updateBug(int $id, string $title, string $data, int $uid, array $attributes) {
		$conn = yield $this->db->getConnection();
		$conn->query("START TRANSACTION");

		if ($id) {
			$info = yield $conn->prepare("UPDATE bugs SET title = :title, data = :data WHERE id = :id", ["id" => $id, "title" => $title, "data" => $data]);
			if (!$info->affectedRows) {
				preg_match('(matched: (\d))i', $info->statusInfo, $m);
				if (!$m[1]) {
					return null;
				}
			}
			yield $conn->prepare("DELETE FROM bugattrs WHERE bug = :id", ["id" => $id]);
		} else {
			$info = yield $conn->prepare("INSERT INTO bugs (title, data, submitter) VALUES (:title, :data, :submitter)", ["title" => $title, "data" => $data, "submitter" => $uid]);
			$id = $info->insertId;
		}

		if ($attributes) {
			$values = count($attributes["value"]);
			\assert($values == count($attributes["attribute"]) && $values == count($attributes["type"]));
			$attributes["id"] = array_fill(0, $values, $id);
			
			// store type in denormalized format for quick fetches
			yield $conn->prepare("INSERT INTO bugattrs (bug, attribute, type, value) VALUES (:id, :attribute, :type, :value)" . str_repeat(", (:id, :attribute, :type, :value)", count($attributes["id"]) - 1), $attributes);
		}

		yield $conn->query("COMMIT");

		return $id;
	}

	private function fetchObjects($query, $args) {
		return \Amp\pipe($this->db->prepare($query, $args), function ($rows) {
			return $rows->fetchObjects();
		});
	}
	
	private function fetchObject($query, $args) {
		return \Amp\pipe($this->db->prepare($query, $args), function ($rows) {
			return $rows->fetchObject();
		});
	}

	function listDesc(int $start, int $num): Promise {
		return $this->fetchObjects("SELECT id, title FROM bugs WHERE id < ? ORDER BY id DESC LIMIT ?", [$start, $num]);
	}

	function listAsc(int $start, int $num): Promise {
		return $this->fetchObjects("SELECT id, title FROM bugs WHERE id > ? ORDER BY id ASC LIMIT ?", [$start, $num]);
	}

	function fetchBug(int $id): Promise {
		return $this->fetchObject("SELECT title, data, submitter, IF(ISNULL(name), '".addslashes(\Bugcache\ANONYMOUS_USER)."', name) AS submittername FROM bugs LEFT JOIN users ON (users.id = submitter) WHERE bugs.id = ?", [$id]);
	}

	function fetchAttrs(int $id): Promise {
		return $this->fetchObjects("SELECT attribute, value, name AS username FROM bugattrs LEFT JOIN users ON (type = ".\Bugcache\BugManager::USER." AND id = value) WHERE bug = ?", [$id]);
	}

	function delete(int $id): Promise {
		return $this->db->prepare("DELETE FROM bugs WHERE id = ?", [$id]);
	}
}