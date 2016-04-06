<?php

namespace Bugcache\Storage;

use Amp\Promise;

interface AuthenticationRepository {
    const TYPE_PASSWORD = "password";

    public function findByUser(int $userId, string $type): Promise;
    public function store(int $userId, string $type, string $token): Promise;
}