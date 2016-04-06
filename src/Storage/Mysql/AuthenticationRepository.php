<?php

namespace Bugcache\Storage\Mysql;

use Amp\Mysql;
use Amp\Promise;
use Bugcache\Storage;
use Generator;
use function Amp\pipe;
use function Amp\resolve;

class AuthenticationRepository implements Storage\AuthenticationRepository {
    private $mysql;

    public function __construct(Mysql\Pool $mysql) {
        $this->mysql = $mysql;
    }

    public function findByUser(int $userId, string $type): Promise {
        return resolve($this->doFindByUser($userId, $type));
    }

    private function doFindByUser(int $userId, string $type): Generator {
        try {
            /** @var Mysql\ResultSet $result */
            $result = yield $this->mysql->prepare("SELECT token FROM authenticators WHERE user_id = ? && type = ?", [$userId, $type]);
            $hash = null;

            if (yield $result->rowCount()) {
                list($hash) = yield $result->fetchRow();
            }

            return $hash;
        } catch (Mysql\Exception $e) {
            throw new Storage\StorageException($e->getMessage(), 0, $e);
        }
    }

    public function store(int $userId, string $type, string $token): Promise {
        return resolve($this->doStore($userId, $type, $token));
    }

    private function doStore(int $userId, string $type, string $token): Generator {
        try {
            yield $this->mysql->prepare("UPDATE authenticators SET token = ? WHERE user_id = ? && type = ?", [$token, $userId, $type]);
        } catch (Mysql\Exception $e) {
            throw new Storage\StorageException($e->getMessage(), 0, $e);
        }
    }
}