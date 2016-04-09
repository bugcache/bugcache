<?php

namespace Bugcache\Storage\Mysql;

use Amp\Mysql;
use Amp\Promise;
use Bugcache\{ Storage, const ANONYMOUS_USER };
use function Amp\resolve;

class UserRepository implements Storage\UserRepository {
    private $mysql;

    public function __construct(Mysql\Pool $mysql) {
        $this->mysql = $mysql;
    }

    public function findByName(string $username): Promise {
        $sql = "SELECT id, name FROM users WHERE name = ?";

        return resolve($this->fetchUser($sql, [$username]));
    }

    public function findById(int $userId): Promise {
        $sql = "SELECT id, name FROM users WHERE id = ?";

        return resolve($this->fetchUser($sql, [$userId]));
    }

    private function fetchUser(string $sql, array $params = []): \Generator {
        /** @var Mysql\ResultSet $result */
        $result = yield $this->mysql->prepare($sql, $params);

        if (yield $result->rowCount()) {
            $record = yield $result->fetchObject();
            return $record;
        }

        return (object) [
            "id" => 0,
            "name" => ANONYMOUS_USER,
        ];
    }

    public function create(string $username): Promise {
        return resolve($this->doCreate($username));
    }

    private function doCreate(string $username): \Generator {
        try {
            /** @var \Amp\Mysql\ConnectionState $result */
            $result = yield $this->mysql->prepare("INSERT INTO users (name) VALUES (?)", [$username]);
            return $result->insertId;
        } catch (Mysql\Exception $e) {
            throw new Storage\ConflictException($e->getMessage(), 0, $e);
        }
    }
}