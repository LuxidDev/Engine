<?php

declare(strict_types=1);

namespace Luxid\Routing;

use Luxid\Foundation\Action;
use Luxid\Foundation\Application;
use Luxid\Middleware\AuthMiddleware;
use Luxid\Middleware\BaseMiddleware;
use Luxid\Middleware\PublicMiddleware;

/**
 * Action-scoped route collection.
 *
 * Returned from `Action::routes()` so a route table lives next to the code it
 * dispatches to:
 *
 * ```php
 * public static function routes(): Routes
 * {
 *     return Routes::new()
 *         ->prefix('api')
 *         ->add('/todos', get('index'))
 *         ->add('/todos', post('store'))
 *         ->secure();
 * }
 * ```
 *
 * Like {@see RouteBuilder}, a collection must declare a security posture before
 * it registers. The two DSLs previously disagreed on this, which meant the
 * starter template bypassed the guarantee the framework advertised.
 *
 * @package Luxid\Routing
 */
class Routes
{
    /**
     * Security posture: every activity requires authentication.
     */
    private const POSTURE_SECURE = 'secure';

    /**
     * Security posture: no activity requires authentication.
     */
    private const POSTURE_PUBLIC = 'public';

    /**
     * Routes declared on this collection.
     *
     * @var list<array{method: string, path: string, handler: string, middleware: list<BaseMiddleware>, name?: string}>
     */
    private array $routes = [];

    /**
     * Path prefix applied to every route in the collection.
     */
    private string $prefix = '';

    /**
     * Middleware applied to every route added after the call.
     *
     * @var list<BaseMiddleware>
     */
    private array $middlewares = [];

    /**
     * Declared posture, or null when none has been chosen.
     */
    private ?string $posture = null;

    /**
     * Activities exempt from authentication under the secure posture.
     *
     * @var list<string>
     */
    private array $publicActivities = [];

    /**
     * Start a new route collection.
     */
    public static function new(): self
    {
        return new self();
    }

    /**
     * Prefix every route in this collection.
     *
     * @param string $prefix Path prefix, with or without slashes
     */
    public function prefix(string $prefix): self
    {
        $this->prefix = $prefix;

        return $this;
    }

    /**
     * Attach middleware to every route added after this call.
     *
     * @param BaseMiddleware|class-string<BaseMiddleware>|list<BaseMiddleware|class-string<BaseMiddleware>> $middleware
     *
     * @throws \InvalidArgumentException When an entry is not usable as middleware
     */
    public function middleware(BaseMiddleware|string|array $middleware): self
    {
        foreach (is_array($middleware) ? $middleware : [$middleware] as $item) {
            $this->middlewares[] = $this->toMiddleware($item);
        }

        return $this;
    }

    /**
     * Add a route to the collection.
     *
     * @param string      $path   Route path, relative to the collection prefix
     * @param RouteMethod $method Method/activity pair from `get()`, `post()`, ...
     */
    public function add(string $path, RouteMethod $method): self
    {
        $this->routes[] = [
            'method' => $method->getMethod(),
            'path' => $path,
            'handler' => $method->getHandler(),
            'middleware' => $this->middlewares,
        ];

        return $this;
    }

    /**
     * Name the most recently added route.
     *
     * @param string $name Route name
     */
    public function name(string $name): self
    {
        if ($this->routes !== []) {
            $this->routes[array_key_last($this->routes)]['name'] = $name;
        }

        return $this;
    }

    /**
     * Require authentication for every activity in this collection.
     *
     * @param list<string> $publicActivities Activities reachable without auth
     */
    public function secure(array $publicActivities = []): self
    {
        $this->posture = self::POSTURE_SECURE;
        $this->publicActivities = $publicActivities;

        return $this;
    }

    /**
     * Require authentication except for the named activities.
     *
     * @param list<string> $activities Activities reachable without auth
     */
    public function open(array $activities = []): self
    {
        return $this->secure($activities);
    }

    /**
     * Waive authentication for every activity in this collection.
     */
    public function public(): self
    {
        $this->posture = self::POSTURE_PUBLIC;
        $this->publicActivities = [];

        return $this;
    }

    /**
     * Register every route in the collection against the application router.
     *
     * @param class-string<Action> $actionClass Action that handles these routes
     *
     * @throws \RuntimeException When the action is invalid or no posture was declared
     */
    public function register(string $actionClass): void
    {
        if (!class_exists($actionClass)) {
            throw new \RuntimeException(sprintf('Action class "%s" does not exist', $actionClass));
        }

        if (!is_subclass_of($actionClass, Action::class)) {
            throw new \RuntimeException(
                sprintf('Class "%s" must extend %s', $actionClass, Action::class)
            );
        }

        if (!isset(Application::$app)) {
            throw new \RuntimeException('Application not initialized');
        }

        $this->assertPostureDeclared($actionClass);

        $router = Application::$app->router;

        foreach ($this->routes as $route) {
            $router->addRoute(
                $route['method'],
                $this->buildPath($route['path']),
                [$actionClass, $route['handler']]
            );

            $router->middleware($this->securityMiddleware());

            foreach ($route['middleware'] as $middleware) {
                $router->middleware($middleware);
            }
        }
    }

    /**
     * Build the middleware enforcing the declared posture.
     */
    private function securityMiddleware(): BaseMiddleware
    {
        if ($this->posture === self::POSTURE_PUBLIC) {
            return new PublicMiddleware();
        }

        return new AuthMiddleware(Application::$app->auth, $this->publicActivities);
    }

    /**
     * Fail loudly when a web collection never declared a security posture.
     *
     * Console runs are exempt so `juice routes` can inspect an application whose
     * routes are mid-refactor.
     *
     * @param class-string $actionClass Action being registered
     *
     * @throws \RuntimeException When a web collection has no posture
     */
    private function assertPostureDeclared(string $actionClass): void
    {
        if ($this->posture !== null || PHP_SAPI === 'cli') {
            return;
        }

        throw new \RuntimeException(sprintf(
            'Routes for "%s" must declare their security with secure(), open() or public().',
            $actionClass
        ));
    }

    /**
     * Join the collection prefix onto a route path.
     *
     * @param string $path Route path as declared
     */
    private function buildPath(string $path): string
    {
        $full = $this->prefix === ''
            ? $path
            : rtrim($this->prefix, '/') . '/' . ltrim($path, '/');

        return '/' . ltrim($full, '/');
    }

    /**
     * Coerce a middleware class name into an instance.
     *
     * @param BaseMiddleware|class-string<BaseMiddleware> $middleware Middleware or class name
     *
     * @throws \InvalidArgumentException When the argument is not usable as middleware
     */
    private function toMiddleware(BaseMiddleware|string $middleware): BaseMiddleware
    {
        if ($middleware instanceof BaseMiddleware) {
            return $middleware;
        }

        if (!class_exists($middleware) || !is_subclass_of($middleware, BaseMiddleware::class)) {
            throw new \InvalidArgumentException(
                sprintf('Middleware "%s" must be a class extending %s', $middleware, BaseMiddleware::class)
            );
        }

        return new $middleware();
    }

    /**
     * Get the declared routes, for inspection and testing.
     *
     * @return list<array{method: string, path: string, handler: string, middleware: list<BaseMiddleware>, name?: string}>
     */
    public function all(): array
    {
        return $this->routes;
    }
}
