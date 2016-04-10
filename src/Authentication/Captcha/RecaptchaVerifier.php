<?php

namespace Bugcache\Authentication\Captcha;

use Amp\Artax\ClientException;
use Amp\Artax\FormBody;
use Amp\Artax\HttpClient;
use Amp\Artax\Request;
use Amp\Artax\Response;
use Amp\Promise;
use function Amp\resolve;

class RecaptchaVerifier {
    const URL = "https://www.google.com/recaptcha/api/siteverify";

    private $http;
    private $secret;

    public function __construct(HttpClient $http, string $secret) {
        $this->http = $http;
        $this->secret = $secret;
    }

    public function verify(string $token): Promise {
        return resolve($this->doVerify($token));
    }

    private function doVerify(string $token): \Generator {
        try {
            $form = (new FormBody)
                ->addField("secret", $this->secret)
                ->addField("response", $token);

            /** @var Response $response */
            $response = yield $this->http->request(
                (new Request)
                    ->setMethod("POST")
                    ->setUri(self::URL)
                    ->setBody($form)
            );

            $data = json_decode($response->getBody(), true);

            return $data["success"] ?? false;
        } catch (ClientException $e) {
            return false;
        }
    }
}