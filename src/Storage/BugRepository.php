<?php

namespace Bugcache\Storage;

use Amp\Promise;

interface BugRepository {
	function storeBug(int $id,string $title, string $data, int $submitter, array $attributes): Promise;
	function listDesc(int $start, int $num): Promise;
	function listAsc(int $start, int $num): Promise;
	function fetchBug(int $id): Promise;
	function fetchAttrs(int $id): Promise;
	function delete(int $id): Promise;
}