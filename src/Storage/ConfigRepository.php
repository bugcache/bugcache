<?php

namespace Bugcache\Storage;

use Amp\Promise;

interface ConfigRepository {
    public function fetch(string $key): Promise;
    public function store(string $key, string $value): Promise;
}