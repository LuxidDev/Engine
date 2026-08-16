<?php

declare(strict_types=1);

namespace Luxid\Foundation;

use Luxid\Contracts\Auth\AuthManager;
use Luxid\Database\DbEntity;
use Luxid\Exceptions\MethodNotAllowedException;
use Luxid\Exceptions\NotFoundException;
use Luxid\Http\NullSession;
use Luxid\Http\Request;
use Luxid\Http\Response;
use Luxid\Http\Session;
use Luxid\Http\SessionInterface;
use Luxid\Middleware\CorsMiddleware;
use Luxid\Middleware\SessionMiddleware;
use Luxid\Routing\Router;
use Rocket\Connection\Connection;
use Throwable;

/**
 * The application kernel.
 *
 * Owns the request/response pair, the router, the database connection and the
 * package providers discovered from Composer metadata.
 *
 * @package Luxid\Foundation
 */
class Application
{
    /**
     * Absolute path to the project root.
     */
    public static string $ROOT_DIR;

    /**
     * The active application instance.
     */
    public static Application $app;

    /**
     * Default frame (layout) used when an action does not pick one.
     */
    public string $frame = 'app';

    /**
     * Entity class backing the authenticated user.
     *
     * @var class-string
     */
    public string $userClass;

    /**
     * The router handling this request.
     */
    public Router $router;

    /**
     * The current request.
     */
    public Request $request;

    /**
     * The current response.
     */
    public Response $response;

    /**
     * The session, resolved lazily by {@see Application::getSession()}.
     */
    public ?SessionInterface $session = null;

    /**
     * The action handling the current request, once resolved.
     */
    public ?Action $action = null;

    /**
     * The database connection, once something has needed it.
     *
     * Prefer {@see Application::db()}, which opens the connection on demand.
     */
    public ?Connection $db = null;

    /**
     * The authenticated user, when one is signed in.
     *
     * Typed loosely because the user entity is application defined: it may
     * extend the legacy {@see DbEntity} or Rocket's `Entity`. Both expose the
     * static `primaryKey()` and `findOne()` this class relies on.
     *
     * @var DbEntity|\Rocket\ORM\Entity|null
     */
    public ?object $user = null;

    /**
     * The legacy screen renderer.
     */
    public Screen $screen;

    /**
     * The auth manager contributed by a package such as Haven.
     */
    public ?AuthManager $auth = null;

    /**
     * Whether uncaught exceptions should render their trace.
     */
    public bool $debug = false;

    /**
     * Provider classes discovered from installed packages.
     *
     * @var list<class-string>
     */
    protected array $providers = [];

    /**
     * @param string               $rootPath Absolute path to the project root
     * @param array<string, mixed> $config   Application configuration
     */
    public function __construct(string $rootPath, array $config)
    {
        self::$ROOT_DIR = $rootPath;
        self::$app = $this;

        $this->userClass = $config['userClass'] ?? '';
        $this->debug = (bool) ($config['debug'] ?? false);

        $this->request = new Request();
        $this->response = new Response();
        $this->screen = new Screen();

        $this->router = new Router($this->request, $this->response);
        $this->router->addGlobalMiddleware(new SessionMiddleware());
        $this->router->addApiGlobalMiddleware(new CorsMiddleware($config['cors'] ?? []));

        if (isset($config['db'])) {
            // Configured, not opened: a route that never queries the database
            // should not fail because the database is unreachable.
            Connection::configure($config['db']);
        }

        $this->discoverProviders();
        $this->registerProviders();
    }

    /**
     * Discover provider classes declared under `extra.luxid.providers`.
     */
    protected function discoverProviders(): void
    {
        $installedPath = self::$ROOT_DIR . '/vendor/composer/installed.json';

        if (!is_file($installedPath)) {
            return;
        }

        $installed = json_decode((string) file_get_contents($installedPath), true);

        if (!is_array($installed)) {
            return;
        }

        foreach ($installed['packages'] ?? $installed as $package) {
            foreach ($package['extra']['luxid']['providers'] ?? [] as $provider) {
                if (class_exists($provider)) {
                    $this->providers[] = $provider;
                }
            }
        }
    }

    /**
     * Register then boot every discovered provider.
     *
     * Providers are instantiated once and reused across both phases so state set
     * during `register()` survives into `boot()`.
     */
    protected function registerProviders(): void
    {
        $instances = [];

        foreach ($this->providers as $provider) {
            $instance = new $provider();
            $instances[] = $instance;

            if (method_exists($instance, 'register')) {
                $instance->register($this);
            }
        }

        foreach ($instances as $instance) {
            if (method_exists($instance, 'boot')) {
                $instance->boot($this);
            }
        }
    }

    /**
     * Bind an auth manager contributed by a package.
     *
     * @param AuthManager $auth Auth manager implementation
     */
    public function registerAuth(AuthManager $auth): void
    {
        $this->auth = $auth;
    }

    /**
     * Check whether the current request is unauthenticated.
     */
    public static function isGuest(): bool
    {
        return !isset(self::$app) || self::$app->user === null;
    }

    /**
     * Handle the current request and flush the response.
     *
     * Returns the body as well so adapters that manage their own output, such as
     * the FrankenPHP worker, can take it without a second render.
     */
    public function run(): string
    {
        $body = $this->handle();

        $this->response->send($body);

        return $body;
    }

    /**
     * Resolve the current request into a response body.
     */
    public function handle(): string
    {
        try {
            return $this->router->resolve();
        } catch (Throwable $e) {
            return $this->handleException($e);
        }
    }

    /**
     * Convert an uncaught exception into a response body.
     *
     * @param Throwable $e The uncaught exception
     */
    protected function handleException(Throwable $e): string
    {
        $code = $this->getHttpCode($e);
        $this->response->setStatusCode($code);

        if ($this->request->wantsJson() || str_starts_with($this->request->getPath(), '/api/')) {
            return $this->response->json([
                'success' => false,
                'message' => $e->getMessage(),
                'code' => $code,
            ], $code);
        }

        return (string) $this->screen->renderScreen('_error', [
            'exception' => $e,
            'code' => $code,
            'debug' => $this->debug,
        ]);
    }

    /**
     * Map an exception onto an HTTP status code.
     *
     * @param Throwable $e The uncaught exception
     */
    protected function getHttpCode(Throwable $e): int
    {
        $code = (int) $e->getCode();

        if ($code >= 100 && $code <= 599) {
            return $code;
        }

        return match (true) {
            $e instanceof NotFoundException => 404,
            $e instanceof MethodNotAllowedException => 405,
            $e instanceof \Luxid\Exceptions\UnauthorizedException => 401,
            $e instanceof \Luxid\Exceptions\ForbiddenException => 403,
            default => 500,
        };
    }

    /**
     * Get the action handling the current request.
     */
    public function getAction(): ?Action
    {
        return $this->action;
    }

    /**
     * Set the action handling the current request.
     *
     * @param Action $action Resolved action
     */
    public function setAction(Action $action): void
    {
        $this->action = $action;
    }

    /**
     * Sign a user in for the current session.
     *
     * The session id is rotated first so a fixated id cannot survive the
     * privilege change.
     *
     * @param DbEntity|\Rocket\ORM\Entity $user The user to sign in
     */
    public function login(object $user): bool
    {
        $session = $this->getSession();
        $session->regenerate();

        $this->user = $user;
        $session->set('user', $user->{$user::primaryKey()});

        return true;
    }

    /**
     * Sign the current user out and rotate the session id.
     */
    public function logout(): void
    {
        $session = $this->getSession();

        $this->user = null;
        $session->remove('user');
        $session->regenerate();
    }

    /**
     * Get the database connection, opening it on first use.
     *
     * @throws \RuntimeException When no database is configured
     */
    public function db(): Connection
    {
        return $this->db ??= Connection::getInstance();
    }

    /**
     * Resolve the session, hydrating the authenticated user on first access.
     */
    public function getSession(): SessionInterface
    {
        if ($this->session !== null) {
            return $this->session;
        }

        $this->session = PHP_SAPI === 'cli' ? new NullSession() : new Session();

        if ($this->session->isStarted()) {
            $this->hydrateUser();
        }

        return $this->session;
    }

    /**
     * Load the authenticated user referenced by the session, if any.
     */
    protected function hydrateUser(): void
    {
        $identifier = $this->session?->get('user');

        if ($identifier === null || $this->userClass === '' || !class_exists($this->userClass)) {
            return;
        }

        $primaryKey = $this->userClass::primaryKey();
        $this->user = $this->userClass::findOne([$primaryKey => $identifier]) ?: null;
    }
}
