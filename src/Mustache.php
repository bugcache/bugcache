<?php

namespace Bugcache;

use Mustache_Engine;

class Mustache {
    private $mustacheEngine;

    public function __construct(Mustache_Engine $mustacheEngine) {
        $this->mustacheEngine = $mustacheEngine;
    }

    public function render(string $content, $context = []) {
        return $this->mustacheEngine->render($content, $context);
    }
}