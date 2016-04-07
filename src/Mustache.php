<?php

namespace Bugcache;

class Mustache {
    private $mustacheEngine;

    public function __construct(\Mustache_Engine $mustacheEngine) {
        $this->mustacheEngine = $mustacheEngine;
    }

    public function render(string $filename, $context = []) {
        return $this->mustacheEngine->render($filename, $context);
    }
}