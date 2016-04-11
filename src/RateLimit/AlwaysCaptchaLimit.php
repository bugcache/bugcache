<?php

namespace Bugcache\RateLimit;

use Aerys\Request;
use Amp\Promise;
use Amp\Success;
use Bugcache\Authentication\Captcha\RecaptchaVerifier;
use Bugcache\Mustache;
use Kelunik\RateLimit\RateLimit;

class AlwaysCaptchaLimit extends CaptchaLimit {
    public function __construct(RecaptchaVerifier $captchaVerifier, Mustache $mustache) {
        parent::__construct($captchaVerifier, $mustache, new class implements RateLimit {
            public function get(string $id): Promise {
                return new Success(1);
            }

            public function increment(string $id): Promise {
                return new Success(1);
            }

            public function ttl(string $id): Promise {
                return new Success(0);
            }
        }, 0);
    }

    protected function getRateLimitId(Request $request) {
        return "";
    }
}