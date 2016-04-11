<?php

namespace Bugcache\Authentication;

use Aerys;
use Aerys\{ Request, Response, Server, Session };
use Amp;
use Amp\{ Promise, function resolve };
use Bugcache\{
    Authentication\Captcha\RecaptchaVerifier, ConfigKeys, CookieKeys, Encoding\Base64Url, Mustache, SessionKeys, TemplateContext
};
use Bugcache\RequestKeys;
use Bugcache\Storage\{ AuthenticationRepository, ConfigRepository, ConflictException, UserRepository };

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

    private function parseRememberMe(string $value) {
        $parts = explode(":", $value);

        if (count($parts) !== 4) {
            return null;
        }

        list($userId, $identity, $token, $mac) = $parts;

        if (!hash_equals(Base64Url::decode($mac), hash_hmac("sha256", "{$userId}:{$identity}:{$token}", $this->rememberMeKey, true))) {
            return null;
        }

        $hashedIdentity = Base64Url::encode(hash("sha256", Base64Url::decode($identity), true));

        return [
            "user" => $userId,
            "identity" => $hashedIdentity,
            "token" => $token,
        ];
    }

    private function loginViaRememberToken(Request $request, Session $session) {
        if ($request->getMethod() !== "GET") {
            return;
        }

        $rememberMe = $request->getCookie(CookieKeys::REMEMBER_ME) ?? "";
        $rememberInfo = $this->parseRememberMe($rememberMe);

        if (!$rememberInfo) {
            return;
        }

        $auth = yield $this->authenticationRepository->findByIdentity($rememberInfo["user"], $rememberInfo["identity"], AuthenticationRepository::TYPE_REMEMBER_ME);

        if ($auth["valid_until"] !== 0 && $auth["valid_until"] < time()) {
            return;
        }

        if (hash_equals($auth["token"] ?? "", $rememberInfo["token"])) {
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

        $response->end($this->mustacheEngine->render("login.mustache", new TemplateContext($request)));
    }

    public function processPasswordLogin(Request $request, Response $response) {
        /** @var Session $session */
        $session = $request->getLocalVar(RequestKeys::SESSION);

        if ($session->get(SessionKeys::LOGIN)) {
            $response->setStatus(302);
            $response->setHeader("location", "/");
            $response->end("");

            return;
        }

        /** @var Aerys\ParsedBody $body */
        $body = yield Aerys\parseBody($request);

        $username = $body->get("username") ?? "";
        $password = $body->get("password") ?? "";
        $remember = $body->get("remember") ?? "";

        $user = yield $this->userRepository->findByName($username);
        $auth = yield $this->authenticationRepository->findByUser($user["id"], AuthenticationRepository::TYPE_PASSWORD);

        if ($user["id"] === 0) {
            $response->end($this->mustacheEngine->render("login.mustache", new TemplateContext($request, [
                "error" => "User does not exist.",
                "username" => $username,
                "selectUsername" => true,
            ])));

            return;
        }

        if ($auth && password_verify($password, $auth["token"])) {
            $this->logger->info("Successful password authentication for user '{$user["name"]}' ({$user["id"]}).");

            if (password_needs_rehash($auth["token"], PASSWORD_BCRYPT)) {
                $newHash = password_hash($password, PASSWORD_BCRYPT);
                yield $this->authenticationRepository->store($user["id"], AuthenticationRepository::TYPE_PASSWORD, $newHash);
            }

            yield from $this->loginManager->loginUser($session, $user["id"]);

            if ($remember) {
                $rawIdentity = random_bytes(16);
                $rawToken = random_bytes(32);
                $validTime = 60 * 60 * 24 * 30;

                $hashedIdentity = hash("sha256", $rawIdentity, true);
                $base64HashedIdentity = Base64Url::encode($hashedIdentity);

                $base64Identity = Base64Url::encode($rawIdentity);
                $base64Token = Base64Url::encode($rawToken);

                yield $this->authenticationRepository->store($user["id"], AuthenticationRepository::TYPE_REMEMBER_ME, $base64Token, $base64HashedIdentity, time() + $validTime);

                $mac = Base64Url::encode(hash_hmac("sha256", "{$user["id"]}:{$base64Identity}:{$base64Token}", $this->rememberMeKey, true));
                $cookie = "{$user["id"]}:{$base64Identity}:{$base64Token}:{$mac}";
                $cookieFlags = [
                    "Max-Age" => $validTime,
                    "Path" => "/",
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

        $this->logger->info("Failed password authentication for user '{$user["name"]}' ({$user["id"]}).");

        $response->end($this->mustacheEngine->render("login.mustache", new TemplateContext($request, [
            "error" => "Wrong password.",
            "username" => $username,
            "focusPassword" => true,
        ])));
    }

    public function processLogout(Request $request, Response $response) {
        /** @var Session $session */
        $session = $request->getLocalVar(RequestKeys::SESSION);
        $rememberMe = $request->getCookie(CookieKeys::REMEMBER_ME);

        if ($rememberMe) {
            // If there's a valid remember me cookie, we have to remove it, otherwise a user would get logged in again immediately.
            // We also remove it from our database to ensure it can no longer be used!
            $rememberInfo = $this->parseRememberMe($rememberMe);

            if ($rememberInfo) {
                yield $this->authenticationRepository->delete($session->get(SessionKeys::LOGIN), AuthenticationRepository::TYPE_REMEMBER_ME, $rememberInfo["identity"]);
            }

            $response->setCookie(CookieKeys::REMEMBER_ME, "", ["Max-Age=0", "Path=/", "HttpOnly"]);
        }

        yield from $this->loginManager->logoutUser($session);

        $response->setStatus(302);
        $response->setHeader("location", "/");
        $response->end("");
    }

    public function showRegister(Request $request, Response $response) {
        /** @var Session $session */
        $session = $request->getLocalVar(RequestKeys::SESSION);

        if ($session->get(SessionKeys::LOGIN)) {
            $response->setStatus(302);
            $response->setHeader("location", "/");
            $response->end("");

            return;
        }

        $response->end($this->mustacheEngine->render("register.mustache", new TemplateContext($request)));
    }

    public function processRegister(Request $request, Response $response) {
        /** @var Session $session */
        $session = $request->getLocalVar(RequestKeys::SESSION);

        if ($session->get(SessionKeys::LOGIN)) {
            $response->setStatus(302);
            $response->setHeader("location", "/");
            $response->end("");

            return;
        }

        /** @var Aerys\ParsedBody $body */
        $body = yield Aerys\parseBody($request);

        $username = $body->get("username") ?? "";
        $password = $body->get("password") ?? "";
        $repeat = $body->get("password-repeat") ?? "";

        if ($password !== $repeat) {
            $response->end($this->mustacheEngine->render("register.mustache", new TemplateContext($request, [
                "error" => "Passwords do not match.",
                "username" => $username,
                "focusPassword" => true,
            ])));

            return;
        }

        if (!$this->isValidUsername($username)) {
            $response->end($this->mustacheEngine->render("register.mustache", new TemplateContext($request, [
                "error" => "Username must start with a-z and must consist of alphanumeric characters and dashes only.",
                "username" => $username,
                "selectUsername" => true,
            ])));
        }

        try {
            $userId = yield $this->userRepository->create($username);
            yield $this->authenticationRepository->store($userId, AuthenticationRepository::TYPE_PASSWORD, password_hash($password, PASSWORD_BCRYPT));

            $this->logger->info("New registration: Username = '{$username}', ID = {$userId}");
        } catch (ConflictException $e) {
            $response->end($this->mustacheEngine->render("register.mustache", new TemplateContext($request, [
                "error" => "Username already taken.",
                "username" => $username,
                "selectUsername" => true,
            ])));

            return;
        }

        yield from $this->loginManager->loginUser($session, $userId);

        $response->setStatus(302);
        $response->setHeader("location", "/welcome");
        $response->end("");
    }

    private function isValidUsername(string $username): bool {
        // Must not be longer than 30 characters
        // strlen is fine here, since we only allow ASCII characters
        if (strlen($username) > 30) {
            return false;
        }

        // Usernames must consist of alphanumeric characters, maybe separated with single dashes.
        if (!preg_match("~^[a-z](([a-z0-9]+)-?)*$~", $username)) {
            return false;
        }

        // Must not end with a dash.
        if ($username[strlen($username) - 1] === "-") {
            return false;
        }

        return true;
    }
}