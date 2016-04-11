<?php

namespace Bugcache\RateLimit;

use Aerys\ParsedBody;
use Aerys\Request;
use Aerys\Response;
use Bugcache\Authentication\Captcha\RecaptchaVerifier;
use Bugcache\Mustache;
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
        $current = yield $this->rateLimit->increment($this->getRateLimitId($request));

        if ($current > $this->limit) {
            yield from $this->showCaptcha($request, $response);
        }
    }

    protected abstract function getRateLimitId(Request $request);

    private function showCaptcha(Request $request, Response $response): \Generator {
        /** @var ParsedBody $body */
        $body = yield \Aerys\parseBody($request);

        $captchaResponse = $body->get("g-recaptcha-response");

        // No captcha solved yet, show captcha and include old form again.
        if ($captchaResponse === null) {
            $inputs = $body->getAll()["fields"];
            $inputs = $this->flattenInputs($inputs);

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

            $response->end($this->mustache->render("recaptcha.mustache", new TemplateContext($request, [
                "inputs" => $inputs,
                "error" => "Wrong captcha, please try again.",
            ])));

            return;
        }
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