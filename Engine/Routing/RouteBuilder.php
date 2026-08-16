<?php

declare(strict_types=1);

namespace Luxid\Routing;

use Luxid\Contracts\Auth\AuthManager;
use Luxid\Foundation\Application;
use Luxid\Middleware\AuthMiddleware;
use Luxid\Middleware\BaseMiddleware;
use Luxid\Middleware\PublicMiddleware;

/**
 * Fluent, action-first route definition.
 *
 * Every route must state a security posture before it is registered:
 *
 * - `secure()` / `auth()` require authentication
 * - `open([...])` require authentication except for the named activities
 * - `public()`   waive authentication entirely
 *
 * A route that declares none of these throws at boot rather than silently
 * defaulting to open, which is the single most useful guarantee this router
 * offers over hand-rolled route tables.
 *
 * @package Luxid\Routing
 */
class RouteBuilder
{
    /**
     * Human readable route name, used in error messages.
     */
    private string $name;

    /**
     * Lowercased HTTP method, once one has been chosen.
     */
    private ?string $method = null;

    /**
     * Route path, once one has been chosen.
     */
    private ?string $path = null;

    /**
     * Handler to invoke, as a [class, activity] pair.
     *
     * @var array{0: class-string, 1: string}|null
     */
    private ?array $callback = null;

    /**
     * The router this route registers with.
     */
    private Router $router;

    /**
     * Middleware to attach once the route registers.
     *
     * @var list<BaseMiddleware>
     */
    private array $middleware = [];

    /**
     * Whether a security posture has been declared.
     */
    private bool $securityConfigured = false;

    /**
     * Whether the route has already been handed to the router.
     */
    private bool $routeRegistered = false;

    /**
     * Whether an enclosing group's security posture should be inherited.
     */
    private bool $inheritGroupSecurity = true;

    /**
     * @param Router $router The router to register with
     * @param string $name   Human readable route name
     */
    public function __construct(Router $router, string $name)
    {
        $this->router = $router;
        $this->name = $name;
    }

    /**
     * Define a GET route.
     *
     * @param string $path Route path
     */
    public function get(string $path): self
    {
        return $this->to('get', $path);
    }

    /**
     * Define a POST route.
     *
     * @param string $path Route path
     */
    public function post(string $path): self
    {
        return $this->to('post', $path);
    }

    /**
     * Define a PUT route.
     *
     * @param string $path Route path
     */
    public function put(string $path): self
    {
        return $this->to('put', $path);
    }

    /**
     * Define a PATCH route.
     *
     * @param string $path Route path
     */
    public function patch(string $path): self
    {
        return $this->to('patch', $path);
    }

    /**
     * Define a DELETE route.
     *
     * @param string $path Route path
     */
    public function delete(string $path): self
    {
        return $this->to('delete', $path);
    }

    /**
     * Record the method and path for this route.
     *
     * @param string $method Lowercased HTTP method
     * @param string $path   Route path
     */
    private function to(string $method, string $path): self
    {
        $this->method = $method;
        $this->path = $path;

        return $this;
    }

    /**
     * Bind the route to an action class and activity.
     *
     * @param class-string $actionClass Action to instantiate
     * @param string       $activity    Method on the action to invoke
     */
    public function uses(string $actionClass, string $activity = 'index'): self
    {
        $this->callback = [$actionClass, $activity];

        return $this;
    }

    /**
     * Opt out of inheriting the enclosing group's security posture.
     */
    public function withoutInheritance(): self
    {
        $this->inheritGroupSecurity = false;

        return $this;
    }

    /**
     * Require authentication, optionally exempting named activities.
     *
     * @param list<string> $publicActivities Activities reachable without auth
     */
    public function secure(array $publicActivities = []): self
    {
        return $this->auth($publicActivities);
    }

    /**
     * Require authentication, optionally exempting named activities.
     *
     * @param list<string> $publicActivities Activities reachable without auth
     */
    public function auth(array $publicActivities = []): self
    {
        $this->middleware[] = new AuthMiddleware($this->resolveAuthManager(), $publicActivities);
        $this->securityConfigured = true;

        return $this->register();
    }

    /**
     * Require authentication except for the named activities.
     *
     * Unlike {@see RouteBuilder::public()} this still protects every activity the
     * caller did not name.
     *
     * @param list<string> $activities Activities reachable without auth
     */
    public function open(array $activities = []): self
    {
        return $this->auth($activities);
    }

    /**
     * Waive authentication for this route entirely.
     */
    public function public(): self
    {
        $this->middleware[] = new PublicMiddleware();
        $this->securityConfigured = true;

        return $this->register();
    }

    /**
     * Attach additional middleware to the route.
     *
     * @param BaseMiddleware|class-string<BaseMiddleware> $middleware Middleware to attach
     *
     * @throws \InvalidArgumentException When the argument is not usable as middleware
     */
    public function with(BaseMiddleware|string $middleware): self
    {
        if (is_string($middleware)) {
            if (!class_exists($middleware)) {
                throw new \InvalidArgumentException(
                    sprintf('Middleware class "%s" does not exist', $middleware)
                );
            }

            if (!is_subclass_of($middleware, BaseMiddleware::class)) {
                throw new \InvalidArgumentException(
                    sprintf('Middleware "%s" must extend %s', $middleware, BaseMiddleware::class)
                );
            }

            $middleware = new $middleware();
        }

        $this->middleware[] = $middleware;

        return $this;
    }

    /**
     * Hand the route to the router if it has not been registered yet.
     *
     * @throws \RuntimeException When the definition is incomplete or insecure
     */
    public function register(): self
    {
        if ($this->routeRegistered) {
            return $this;
        }

        if ($this->method === null || $this->path === null || $this->callback === null) {
            throw new \RuntimeException(
                sprintf('Route "%s" is incomplete. Specify a method, a path and uses().', $this->name)
            );
        }

        $this->inheritGroupSecurity();
        $this->assertSecurityDeclared();

        $this->router->addRoute($this->method, $this->path, $this->callback);

        foreach ($this->middleware as $middleware) {
            $this->router->middleware($middleware);
        }

        $this->routeRegistered = true;

        return $this;
    }

    /**
     * Adopt the enclosing group's security posture when the route declared none.
     */
    private function inheritGroupSecurity(): void
    {
        if ($this->securityConfigured || !$this->inheritGroupSecurity) {
            return;
        }

        $group = $this->currentGroup();

        if ($group === null) {
            return;
        }

        if ($group['auth'] === true) {
            $this->middleware[] = new AuthMiddleware($this->resolveAuthManager(), []);
            $this->securityConfigured = true;

            return;
        }

        if ($group['open'] !== null) {
            $this->middleware[] = new AuthMiddleware($this->resolveAuthManager(), $group['open']);
            $this->securityConfigured = true;
        }
    }

    /**
     * Fail loudly when a web route never declared a security posture.
     *
     * Console runs are exempt so `juice routes` can still inspect an application
     * whose routes are mid-refactor; the inspector reports them as undeclared.
     *
     * @throws \RuntimeException When a web route has no security posture
     */
    private function assertSecurityDeclared(): void
    {
        if ($this->securityConfigured || PHP_SAPI === 'cli') {
            return;
        }

        throw new \RuntimeException(sprintf(
            'Route "%s" must declare its security with secure(), open() or public().',
            $this->name
        ));
    }

    /**
     * Resolve the auth manager from the application or from Haven.
     */
    private function resolveAuthManager(): ?AuthManager
    {
        if (isset(Application::$app) && Application::$app->auth !== null) {
            return Application::$app->auth;
        }

        $haven = 'Luxid\\Haven\\Haven';

        if (class_exists($haven) && $haven::isInitialized()) {
            return $haven::getManager();
        }

        return null;
    }

    /**
     * Get the innermost group's security posture.
     *
     * @return array{auth: bool, open: list<string>|null}|null
     */
    private function currentGroup(): ?array
    {
        $stack = $this->router->getGroupStack();

        if ($stack === []) {
            return null;
        }

        $group = end($stack);

        return [
            'auth' => (bool) ($group['auth'] ?? false),
            'open' => $group['open'] ?? null,
        ];
    }

    /**
     * Check whether this route declared a security posture.
     */
    public function isSecurityConfigured(): bool
    {
        return $this->securityConfigured;
    }

    /**
     * Get the route name.
     */
    public function getName(): string
    {
        return $this->name;
    }
}
