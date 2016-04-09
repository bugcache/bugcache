<?php

namespace Bugcache\Storage;

use Amp\Promise;

interface AuthenticationRepository {
    const TYPE_PASSWORD = "password";
    const TYPE_REMEMBER_ME = "rememberme";

    public function findByIdentity(int $userId, string $identity, string $type): Promise;
    public function findByUser(int $userId, string $type): Promise;
    public function store(int $userId, string $type, string $token, string $identity = "", int $validUntil = 0): Promise;
    public function delete(int $userId, string $type, string $identity = ""): Promise;
}