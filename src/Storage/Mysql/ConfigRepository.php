<?php

namespace Bugcache\Storage\Mysql;

use Amp\Mysql;
use Amp\Promise;
use Bugcache\Storage;
use function Amp\{ pipe, resolve };

class ConfigRepository implements Storage\ConfigRepository {
    private $mysql;

    public function __construct(Mysql\Pool $mysql) {
        $this->mysql = $mysql;
    }

    public function fetch(string $key): Promise {
        return resolve($this->doFindByUser($key));
    }

    private function doFindByUser(string $key): \Generator {
        try {
            /** @var Mysql\ResultSet $result */
            $result = yield $this->mysql->prepare("SELECT `value` FROM config WHERE `key` = ? LIMIT 1", [$key]);
            $value = null;

            if (yield $result->rowCount()) {
                $obj = yield $result->fetchObject();
                $value = $obj->value;
            }

            return $value;
        } catch (Mysql\Exception $e) {
            throw new Storage\StorageException($e->getMessage(), 0, $e);
        }
    }

    public function store(string $key, string $value = null): Promise {
        return resolve($this->doStore($key, $value));
    }

    private function doStore(string $key, string $value = null): \Generator {
        try {
            yield $this->mysql->prepare("REPLACE INTO config (`key`, `value`) VALUES (?, ?)", [$key, $value]);
        } catch (Mysql\Exception $e) {
            throw new Storage\StorageException($e->getMessage(), 0, $e);
        }
    }
}