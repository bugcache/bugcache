<?php

namespace Bugcache\Authentication;

use Aerys;
use Aerys\{ Request, Response, Server, Session };
use Amp;
use Amp\{ Promise, function resolve };
use Bugcache\{ ConfigKeys, CookieKeys, Encoding\Base64Url, Mustache, SessionKeys };
use Bugcache\RequestKeys;
use Bugcache\Storage\{ AuthenticationRepository, ConfigRepository, UserRepository };

class LoginHandler implements Aerys\Bootable, Aerys\ServerObserver {
    private $configRepository;
    private $userRepository;
    private $authenticationRepository;
    private $mustacheEngine;
    private $loginManager;

    /** @var Aerys\Logger */
    private $logger;

    /** Key to protect rememberme cookies. */
    private $rememberMeKey;

    public function __construct(ConfigRepository $configRepository, UserRepository $userRepository, AuthenticationRepository $authenticationRepository, LoginManager $loginManager, Mustache $mustacheEngine) {
        $this->configRepository = $configRepository;
        $this->userRepository = $userRepository;
        $this->authenticationRepository = $authenticationRepository;
        $this->mustacheEngine = $mustacheEngine;
        $this->loginManager = $loginManager;
    }

    public function boot(Aerys\Server $server, Aerys\Logger $logger) {
        $this->logger = $logger;
        $server->attach($this);
    }

    public function update(Server $server): Promise {
        if ($server->state() === Server::STARTING) {
            return resolve($this->loadRememberMeKey());
        }

        return new Amp\Success;
    }

    private function loadRememberMeKey(): \Generator {
        $key = Base64Url::decode((yield $this->configRepository->fetch(ConfigKeys::REMEMBER_ME_KEY)) ?? "");

        if ($key === "") {
            $key = random_bytes(32);
            yield $this->configRepository->store(ConfigKeys::REMEMBER_ME_KEY, Base64Url::encode($key));
        }

        $this->rememberMeKey = $key;
    }

    public function __invoke(Request $request, Response $response) {
        /** @var Session $session */
        $session = yield (new Session($request))->read();

        if (!$session->has(SessionKeys::LOGIN)) {
            yield from $this->loginViaRememberToken($request, $session);
        }

        $user = yield $this->userRepository->findById($session->get(SessionKeys::LOGIN) ?? 0);

        $request->setLocalVar(RequestKeys::USER, $user);
        $request->setLocalVar(RequestKeys::SESSION, $session);
    }

    private function loginViaRememberToken(Request $request, Session $session) {
        if ($request->getMethod() !== "GET") {
            return;
        }

        $rememberMe = $request->getCookie(CookieKeys::REMEMBER_ME) ?? "";
        $rememberMeParts = explode(":", $rememberMe);

        if (count($rememberMeParts) !== 4) {
            return;
        }

        list($userId, $identity, $token, $mac) = $rememberMeParts;

        if (!hash_equals(Base64Url::decode($mac), hash_hmac("sha256", "{$userId}:{$identity}:{$token}", $this->rememberMeKey, true))) {
            return;
        }

        $auth = yield $this->authenticationRepository->findByIdentity($identity, AuthenticationRepository::TYPE_REMEMBER_ME);

        if ($auth["valid_until"] !== 0 && $auth["valid_until"] < time()) {
            return;
        }

        if (hash_equals($auth["token"] ?? "", $token)) {
            $this->logger->info("New login for UID = {$auth["user"]} via remember me token");
            yield from $this->loginManager->loginUser($session, $auth["user"]);
        }
    }

    public function showLogin(Request $request, Response $response) {
        /** @var Session $session */
        $session = $request->getLocalVar(RequestKeys::SESSION);

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
        $session = $request->getLocalVar(RequestKeys::SESSION);

        /** @var Aerys\ParsedBody $body */
        $body = yield Aerys\parseBody($request);

        $username = $body->get("username") ?? "";
        $password = $body->get("password") ?? "";
        $remember = $body->get("remember") ?? "";

        $user = yield $this->userRepository->findByName($username);
        $auth = yield $this->authenticationRepository->findByUser($user->id, AuthenticationRepository::TYPE_PASSWORD);

        if ($user->id === 0) {
            $response->end("User does not exist! <a href='/login'>Retry</a>");

            return;
        }

        if ($auth && password_verify($password, $auth["token"])) {
            $this->logger->info("Successful password authentication for user '{$user->name}' ({$user->id}).");

            if (password_needs_rehash($auth["token"], PASSWORD_BCRYPT)) {
                $newHash = password_hash($password, PASSWORD_BCRYPT);
                yield $this->authenticationRepository->store($user->id, AuthenticationRepository::TYPE_PASSWORD, $newHash);
            }

            yield from $this->loginManager->loginUser($session, $user->id);

            if ($remember) {
                $identity = Base64Url::encode(random_bytes(16));
                $token = Base64Url::encode(random_bytes(32));
                $validTime = 60 * 60 * 24 * 30;

                yield $this->authenticationRepository->store($user->id, AuthenticationRepository::TYPE_REMEMBER_ME, $token, $identity, time() + $validTime);

                $mac = Base64Url::encode(hash_hmac("sha256", "{$user->id}:{$identity}:{$token}", $this->rememberMeKey, true));
                $cookie = "{$user->id}:{$identity}:{$token}:{$mac}";
                $cookieFlags = [
                    "Max-Age=" . $validTime,
                    "Path=/",
                    "HttpOnly",
                ];

                if ($request->getConnectionInfo()["is_encrypted"]) {
                    $cookieFlags[] = "Secure";
                }

                $response->setCookie(CookieKeys::REMEMBER_ME, $cookie, $cookieFlags);
            }

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
        $session = $request->getLocalVar(RequestKeys::SESSION);

        yield from $this->loginManager->logoutUser($session);

        // Remove remember me cookie on explicit logout
        $response->setCookie(CookieKeys::REMEMBER_ME, "", ["Max-Age=0", "Path=/", "HttpOnly"]);

        $response->setStatus(302);
        $response->setHeader("location", "/");
        $response->end("");
    }
}