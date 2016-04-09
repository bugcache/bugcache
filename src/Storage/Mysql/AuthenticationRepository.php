<?php

namespace Bugcache\Storage\Mysql;

use Amp\Mysql;
use Amp\Promise;
use Bugcache\Storage;
use function Amp\{ pipe, resolve };

class AuthenticationRepository implements Storage\AuthenticationRepository {
    private $mysql;

    public function __construct(Mysql\Pool $mysql) {
        $this->mysql = $mysql;
    }

    public function findByUser(int $userId, string $type): Promise {
        return resolve($this->doFindByUser($userId, $type));
    }

    private function doFindByUser(int $userId, string $type): \Generator {
        try {
            /** @var Mysql\ResultSet $result */
            $result = yield $this->mysql->prepare("SELECT user, type, token, identity, valid_until FROM authenticators WHERE user = ? && type = ? LIMIT 1", [$userId, $type]);
            $auth = null;

            if (yield $result->rowCount()) {
                $auth = (array) yield $result->fetchObject();
            }

            return $auth;
        } catch (Mysql\Exception $e) {
            throw new Storage\StorageException($e->getMessage(), 0, $e);
        }
    }

    public function findByIdentity(int $userId, string $identity, string $type): Promise {
        return resolve($this->doFindByIdentity($userId, $identity, $type));
    }

    private function doFindByIdentity(int $userId, string $identity, string $type): \Generator {
        try {
            /** @var Mysql\ResultSet $result */
            $result = yield $this->mysql->prepare("SELECT user, type, token, identity, valid_until FROM authenticators WHERE user = ? && identity = ? && type = ? LIMIT 1", [$userId, $identity, $type]);
            $auth = null;

            if (yield $result->rowCount()) {
                $auth = (array) yield $result->fetchObject();
            }

            return $auth;
        } catch (Mysql\Exception $e) {
            throw new Storage\StorageException($e->getMessage(), 0, $e);
        }
    }

    public function store(int $userId, string $type, string $token, string $identity = "", int $validUntil = 0): Promise {
        return resolve($this->doStore($userId, $type, $token, $identity, $validUntil));
    }

    private function doStore(int $userId, string $type, string $token, string $identity, int $validUntil): \Generator {
        try {
            yield $this->mysql->prepare("REPLACE INTO authenticators (user, type, token, identity, valid_until) VALUES (?, ?, ?, ?, ?)", [$userId, $type, $token, $identity, $validUntil]);
        } catch (Mysql\Exception $e) {
            throw new Storage\StorageException($e->getMessage(), 0, $e);
        }
    }

    public function delete(int $userId, string $type, string $identity = ""): Promise {
        return resolve($this->doDelete($userId, $type, $identity));
    }

    private function doDelete(int $userId, string $type, string $identity): \Generator {
        try {
            yield $this->mysql->prepare("DELETE FROM authenticators WHERE user = ? && type = ? && identity = ? LIMIT 1", [$userId, $type, $identity]);
        } catch (Mysql\Exception $e) {
            throw new Storage\StorageException($e->getMessage(), 0, $e);
        }
    }
}