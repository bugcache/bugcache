<?php

namespace Bugcache\RateLimit;

use Aerys\Request;

class IpCaptchaLimit extends CaptchaLimit {
    protected function getRateLimitId(Request $request) {
        return $request->getConnectionInfo()["client_addr"];
    }
}