<?php

namespace Bugcache\Authentication;

use Aerys\Bootable;
use Aerys\Logger;
use Aerys\ParsedBody;
use Aerys\Request;
use Aerys\Response;
use Aerys\Server;
use Aerys\Session;
use Bugcache\Mustache;
use Bugcache\SessionKeys;
use Bugcache\Storage\AuthenticationRepository;
use Bugcache\Storage\UserRepository;
use function Aerys\parseBody;
use function Amp\resolve;

class LoginHandler implements Bootable {
    private $userRepository;
    private $authenticationRepository;
    private $mustacheEngine;
    private $loginManager;

    /** @var Logger */
    private $logger;

    public function __construct(UserRepository $userRepository, AuthenticationRepository $authenticationRepository, LoginManager $loginManager, Mustache $mustacheEngine) {
        $this->userRepository = $userRepository;
        $this->authenticationRepository = $authenticationRepository;
        $this->mustacheEngine = $mustacheEngine;
        $this->loginManager = $loginManager;
    }

    public function boot(Server $server, Logger $logger) {
        $this->logger = $logger;
    }

    public function showLogin(Request $request, Response $response) {
        /** @var Session $session */
        $session = yield (new Session($request))->read();

        if ($session->get(SessionKeys::LOGIN)) {
            $response->setStatus(302);
            $response->setHeader("location", "/");
            $response->end("");

            return;
        }

        $content = $this->mustacheEngine->render("login.mustache");

        $response->end($content);
    }

    public function processPasswordLogin(Request $request, Response $response) {
        /** @var Session $session */
        $session = yield (new Session($request))->read();

        /** @var ParsedBody $body */
        $body = yield parseBody($request);

        $username = $body->get("username") ?? "";
        $password = $body->get("password") ?? "";

        $user = yield $this->userRepository->findByName($username);
        $storedHash = yield $this->authenticationRepository->findByUser($user->id, AuthenticationRepository::TYPE_PASSWORD);

        if ($user->id === 0) {
            $response->end("User does not exist! <a href='/login'>Retry</a>");

            return;
        }

        if ($storedHash && password_verify($password, $storedHash)) {
            $this->logger->info("Successful password authentication for user '{$user->name}' ({$user->id}).");

            if (password_needs_rehash($storedHash, PASSWORD_BCRYPT)) {
                $newHash = password_hash($password, PASSWORD_BCRYPT);
                yield $this->authenticationRepository->store($user->id, AuthenticationRepository::TYPE_PASSWORD, $newHash);
            }

            yield from $this->loginManager->loginUser($session, $user->id);

            $response->setStatus(302);
            $response->setHeader("location", "/");
            $response->end("");

            return;
        }

        $this->logger->info("Failed password authentication for user '{$user->name}' ({$user->id}).");

        $response->end("Wrong password! <a href='/login'>Retry</a>");
    }

    public function processLogout(Request $request, Response $response) {
        /** @var Session $session */
        $session = yield (new Session($request))->read();

        yield from $this->loginManager->logoutUser($session);

        $response->setStatus(302);
        $response->setHeader("location", "/");
        $response->end("");
    }
}