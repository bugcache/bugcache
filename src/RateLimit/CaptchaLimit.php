<?php

namespace Bugcache\RateLimit;

use Aerys\ParsedBody;
use Aerys\Request;
use Aerys\Response;
use Aerys\Session;
use Bugcache\Captcha\RecaptchaVerifier;
use Bugcache\Mustache;
use Bugcache\RequestKeys;
use Bugcache\SessionKeys;
use Bugcache\TemplateContext;
use Kelunik\RateLimit\RateLimit;

abstract class CaptchaLimit {
    private $captchaVerifier;
    private $mustache;
    private $rateLimit;
    private $limit;

    public function __construct(RecaptchaVerifier $captchaVerifier, Mustache $mustache, RateLimit $rateLimit, int $limit) {
        $this->captchaVerifier = $captchaVerifier;
        $this->mustache = $mustache;
        $this->rateLimit = $rateLimit;
        $this->limit = $limit;
    }

    public function __invoke(Request $request, Response $response) {
        $id = $this->getRateLimitId($request);
        $path = strtok($request->getUri(), "?");
        $method = $request->getMethod();

        $current = yield $this->rateLimit->increment("captcha:{$id}:{$method}:{$path}");

        if ($current > $this->limit) {
            yield from $this->showCaptcha($request, $response);
        }
    }

    protected abstract function getRateLimitId(Request $request);

    private function showCaptcha(Request $request, Response $response): \Generator {
        /** @var Session $session */
        $session = $request->getLocalVar(RequestKeys::SESSION);

        if ($session->get(SessionKeys::LAST_CAPTCHA) > time() - 15 * 60) {
            // Don't ask for a captcha again for 15 minutes if the user already solved a captcha recently.
            return;
        }

        /** @var ParsedBody $body */
        $body = yield \Aerys\parseBody($request);

        $captchaResponse = $body->get("g-recaptcha-response");

        // No captcha solved yet, show captcha and include old form again.
        if ($captchaResponse === null) {
            $inputs = $body->getAll()["fields"];
            $inputs = $this->flattenInputs($inputs);

            $response->setHeader("cache-control", "no-cache, no-store");
            $response->setHeader("pragma", "no-cache");

            $response->end($this->mustache->render("recaptcha.mustache", new TemplateContext($request, [
                "inputs" => $inputs,
            ])));

            return;
        }

        $valid = yield $this->captchaVerifier->verify($captchaResponse);

        if (!$valid) {
            // Wrong captcha, show captcha again and include old form again, but remove captcha response.
            $inputs = $body->getAll()["fields"];
            $inputs = $this->flattenInputs($inputs);
            $inputs = array_filter($inputs, function($input) {
                return $input["name"] !== "g-recaptcha-response";
            });

            $response->setHeader("cache-control", "no-cache, no-store");
            $response->setHeader("pragma", "no-cache");

            $response->end($this->mustache->render("recaptcha.mustache", new TemplateContext($request, [
                "inputs" => $inputs,
                "error" => "Wrong captcha, please try again.",
            ])));

            return;
        }

        yield $session->open();
        $session->set(SessionKeys::LAST_CAPTCHA, time());
        yield $session->save();
    }

    private function flattenInputs(array $inputs) {
        $result = [];

        foreach ($inputs as $key => $values) {
            foreach ($values as $value) {
                $result[] = [
                    "name" => $key,
                    "value" => $value,
                ];
            }
        }

        return $result;
    }
}