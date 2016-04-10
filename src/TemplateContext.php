<?php

namespace Bugcache;

use Aerys\Request;
use Aerys\Session;

class TemplateContext {
    private $request;
    private $context;

    public function __construct(Request $request, $context = []) {
        $this->request = $request;
        $this->context = $context;
    }

    public function getContext() {
        return [
            "user" => $this->request->getLocalVar(RequestKeys::USER),
            "data" => $this->context,
        ];
    }
}