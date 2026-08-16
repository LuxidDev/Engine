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
     * Compiled index of what installed packages contribute.
     */
    protected ?PackageManifest $manifest = null;

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

        $this->registerRequestScope();
        $this->discoverProviders();
        $this->registerProviders();
    }

    /**
     * Register the engine's own request-scoped state with the reset registry.
     *
     * Only meaningful under a worker runtime; harmless under PHP-FPM.
     */
    protected function registerRequestScope(): void
    {
        RequestScope::onReset(function (): void {
            $this->action = null;
            $this->user = null;
            $this->session = null;
        }, 'engine.application');
    }

    /**
     * Prepare the kernel to serve another request in the same process.
     *
     * Replaces the request and response, clears everything registered with
     * {@see RequestScope} and rebinds the router. Called by the worker runtime;
     * a classic PHP-FPM request never needs it.
     *
     * @param Request|null  $request  Request to serve, or null for a fresh one
     * @param Response|null $response Response to build into, or null for a fresh one
     */
    public function prepareForNextRequest(?Request $request = null, ?Response $response = null): void
    {
        RequestScope::reset();

        $this->request = $request ?? new Request();
        $this->response = $response ?? new Response();

        $this->router->request = $this->request;
        $this->router->response = $this->response;
    }

    /**
     * Discover provider classes declared under `extra.luxid.providers`.
     *
     * Reads the compiled manifest rather than re-parsing Composer's 30KB
     * `installed.json` on every request.
     */
    protected function discoverProviders(): void
    {
        foreach ($this->packageManifest()->providers() as $provider) {
            if (class_exists($provider)) {
                $this->providers[] = $provider;
            }
        }
    }

    /**
     * Get the compiled package manifest for this application.
     */
    public function packageManifest(): PackageManifest
    {
        return $this->manifest ??= new PackageManifest(self::$ROOT_DIR . '/vendor');
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

        try {
            return (string) $this->screen->renderScreen('_error', [
                'exception' => $e,
                'code' => $code,
                'debug' => $this->debug,
            ]);
        } catch (Throwable $renderFailure) {
            // An error handler that throws is worse than a plain error page, so
            // a missing or broken `_error` screen falls back to built-in markup.
            return $this->fallbackErrorPage($e, $code, $renderFailure);
        }
    }

    /**
     * Render a minimal error page without touching the view layer.
     *
     * @param Throwable      $e             The original exception
     * @param int            $code          Resolved HTTP status code
     * @param Throwable|null $renderFailure Why the error screen could not render
     */
    protected function fallbackErrorPage(Throwable $e, int $code, ?Throwable $renderFailure = null): string
    {
        $escape = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
        $title = $code . ' ' . ($code === 404 ? 'Not Found' : 'Error');

        $body = '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8">'
            . '<title>' . $escape($title) . '</title></head><body>'
            . '<h1>' . $escape($title) . '</h1>';

        // Exception details are only exposed when the application asked for them.
        if ($this->debug) {
            $body .= '<p>' . $escape($e->getMessage()) . '</p>'
                . '<pre>' . $escape($e->getFile() . ':' . $e->getLine()) . "\n"
                . $escape($e->getTraceAsString()) . '</pre>';

            if ($renderFailure !== null) {
                $body .= '<p>The error screen could not be rendered: '
                    . $escape($renderFailure->getMessage()) . '</p>';
            }
        } else {
            $body .= '<p>Something went wrong.</p>';
        }

        return $body . '</body></html>';
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
