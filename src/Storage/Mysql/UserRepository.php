<?php

namespace Bugcache\Storage\Mysql;

use Amp\Mysql\Pool;
use Amp\Mysql\ResultSet;
use Amp\Promise;
use Bugcache\Storage;
use Generator;
use function Amp\resolve;

class UserRepository implements Storage\UserRepository {
    private $mysql;

    public function __construct(Pool $mysql) {
        $this->mysql = $mysql;
    }

    public function findByName(string $username): Promise {
        $sql = "SELECT id, name FROM users WHERE name = ?";

        return resolve($this->fetchUser($sql, [$username]));
    }

    private function fetchUser(string $sql, array $params = []): Generator {
        /** @var ResultSet $result */
        $result = yield $this->mysql->prepare($sql, $params);

        if (yield $result->rowCount()) {
            $record = yield $result->fetchObject();
            return $record;
        } else {
            return (object) [
                "id" => 0,
                "name" => "anonymous",
            ];
        }
    }
}