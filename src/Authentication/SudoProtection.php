<?php

namespace Bugcache\Authentication;

use Aerys\ParsedBody;
use Aerys\Request;
use Aerys\Response;
use Aerys\Session;
use Bugcache\Mustache;
use Bugcache\RequestKeys;
use Bugcache\SessionKeys;
use Bugcache\Storage\AuthenticationRepository;
use Bugcache\TemplateContext;

class SudoProtection {
    private $mustache;
    private $authenticationRepository;

    public function __construct(Mustache $mustache, AuthenticationRepository $authenticationRepository) {
        $this->mustache = $mustache;
        $this->authenticationRepository = $authenticationRepository;
    }

    public function __invoke(Request $request, Response $response) {
        /** @var Session $session */
        $session = $request->getLocalVar(RequestKeys::SESSION);

        if (!$session->get(SessionKeys::LOGIN)) {
            $response->setStatus(500);
            $response->end("<h1>Internal Server Error</h1>");
        }

        $lastSudo = $session->get(SessionKeys::LAST_SUDO) ?? 0;

        if ($lastSudo < time() - 60 * 60) { // Ask for password if currently not in sudo mode.
            yield from $this->showSudo($request, $response);
        } else { // Every sudo action extends sudo mode by one hour.
            yield $session->open();
            $session->set(SessionKeys::LAST_SUDO, time());
            yield $session->save();
        }
    }

    private function showSudo(Request $request, Response $response): \Generator {
        /** @var Session $session */
        $session = $request->getLocalVar(RequestKeys::SESSION);

        /** @var ParsedBody $body */
        $body = yield \Aerys\parseBody($request);
        $password = $body->get("password");

        if ($password === null) {
            $inputs = $body->getAll()["fields"];
            $inputs = $this->flattenInputs($inputs);

            $response->setHeader("cache-control", "no-cache, no-store");
            $response->setHeader("pragma", "no-cache");

            $response->end($this->mustache->render("sudo.mustache", new TemplateContext($request, [
                "inputs" => $inputs,
            ])));

            return;
        }

        $auth = yield $this->authenticationRepository->findByUser($session->get(SessionKeys::LOGIN), AuthenticationRepository::TYPE_PASSWORD);

        if ($auth && password_verify($password, $auth["token"])) {
            if (password_needs_rehash($auth["token"], PASSWORD_BCRYPT)) {
                $newHash = password_hash($password, PASSWORD_BCRYPT);
                yield $this->authenticationRepository->store($session->get(SessionKeys::LOGIN), AuthenticationRepository::TYPE_PASSWORD, $newHash);
            }

            yield $session->open();
            $session->set(SessionKeys::LAST_SUDO, time());
            yield $session->save();
        }

        // Wrong password...
        $inputs = $body->getAll()["fields"];
        $inputs = $this->flattenInputs($inputs);
        $inputs = array_filter($inputs, function($input) {
            return $input["name"] !== "password";
        });

        $response->setHeader("cache-control", "no-cache, no-store");
        $response->setHeader("pragma", "no-cache");

        $response->end($this->mustache->render("sudo.mustache", new TemplateContext($request, [
            "inputs" => $inputs,
            "error" => "Wrong password.",
        ])));
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