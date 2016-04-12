<?php

namespace Bugcache\Authentication;

use Aerys\Session;
use Bugcache\SessionKeys;

class LoginManager {
    public function loginUser(Session $session, int $userId): \Generator {
        yield $session->open();

        // Regenerate ID to prevent session fixation
        yield $session->regenerate();

        $session->set(SessionKeys::LOGIN, $userId);
        $session->set(SessionKeys::LOGIN_TIME, time());

        yield $session->save();
    }

    public function logoutUser(Session $session): \Generator {
        yield $session->open();

        $preserve = [
            SessionKeys::LAST_CAPTCHA,
            SessionKeys::USERAGENT,
        ];

        $preserveData = [];

        foreach ($preserve as $key) {
            $preserveData[$key] = $session->get($key);
        }

        yield $session->destroy();
        yield $session->open();

        foreach ($preserveData as $key => $value) {
            $session->set($key, $value);
        }

        yield $session->save();
    }
}