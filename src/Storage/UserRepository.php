<?php

namespace Bugcache\Storage;

use Amp\Promise;

interface UserRepository {
    public function findByName(string $username): Promise;
    public function findById(int $userId): Promise;
}