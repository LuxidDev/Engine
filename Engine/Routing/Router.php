<?php

declare(strict_types=1);

namespace Luxid\Routing;

use Luxid\Exceptions\MethodNotAllowedException;
use Luxid\Exceptions\NotFoundException;
use Luxid\Foundation\Action;
use Luxid\Foundation\Application;
use Luxid\Http\Request;
use Luxid\Http\Response;
use Luxid\Middleware\BaseMiddleware;

/**
 * HTTP router.
 *
 * Routes are stored per method in two buckets: a static map for literal paths,
 * which resolves in constant time, and a list of compiled patterns for paths
 * carrying `{param}` or `{param?}` placeholders. Patterns are compiled once at
 * registration rather than re-parsed on every request.
 *
 * @package Luxid\Routing
 */
class Router
{
    /**
     * HTTP methods the router accepts registrations for.
     *
     * @var list<string>
     */
    public const METHODS = ['get', 'post', 'put', 'patch', 'delete'];

    /**
     * The request being routed.
     */
    public Request $request;

    /**
     * The response being built.
     */
    public Response $response;

    /**
     * Routes with no placeholders, keyed by method then path.
     *
     * @var array<string, array<string, array<string, mixed>>>
     */
    protected array $staticRoutes = [];

    /**
     * Routes carrying placeholders, keyed by method.
     *
     * @var array<string, list<array<string, mixed>>>
     */
    protected array $dynamicRoutes = [];

    /**
     * Method and path of the most recently registered route.
     *
     * @var array{method: string, path: string}|null
     */
    protected ?array $lastRoute = null;

    /**
     * Middleware run before every route.
     *
     * @var list<BaseMiddleware>
     */
    protected array $globalMiddleware = [];

    /**
     * Middleware run before every route that looks like an API request.
     *
     * @var list<BaseMiddleware>
     */
    protected array $apiGlobalMiddleware = [];

    /**
     * Stack of active route groups.
     *
     * @var list<array<string, mixed>>
     */
    private array $groupStack = [];

    /**
     * Middleware instances reused across registrations, keyed by class name.
     *
     * @var array<class-string<BaseMiddleware>, BaseMiddleware>
     */
    private array $middlewareInstances = [];

    /**
     * @param Request  $request  The request being routed
     * @param Response $response The response being built
     */
    public function __construct(Request $request, Response $response)
    {
        $this->request = $request;
        $this->response = $response;

        foreach (self::METHODS as $method) {
            $this->staticRoutes[$method] = [];
            $this->dynamicRoutes[$method] = [];
        }
    }

    /**
     * Register a GET route.
     *
     * @param string                                $path     Route path
     * @param callable|array{0: class-string, 1: string}|string $callback Route handler
     */
    public function get(string $path, callable|array|string $callback): self
    {
        return $this->addRoute('get', $path, $callback);
    }

    /**
     * Register a POST route.
     *
     * @param string                                $path     Route path
     * @param callable|array{0: class-string, 1: string}|string $callback Route handler
     */
    public function post(string $path, callable|array|string $callback): self
    {
        return $this->addRoute('post', $path, $callback);
    }

    /**
     * Register a PUT route.
     *
     * @param string                                $path     Route path
     * @param callable|array{0: class-string, 1: string}|string $callback Route handler
     */
    public function put(string $path, callable|array|string $callback): self
    {
        return $this->addRoute('put', $path, $callback);
    }

    /**
     * Register a PATCH route.
     *
     * @param string                                $path     Route path
     * @param callable|array{0: class-string, 1: string}|string $callback Route handler
     */
    public function patch(string $path, callable|array|string $callback): self
    {
        return $this->addRoute('patch', $path, $callback);
    }

    /**
     * Register a DELETE route.
     *
     * @param string                                $path     Route path
     * @param callable|array{0: class-string, 1: string}|string $callback Route handler
     */
    public function delete(string $path, callable|array|string $callback): self
    {
        return $this->addRoute('delete', $path, $callback);
    }

    /**
     * Register a route for the given method.
     *
     * Group prefixes and group middleware are folded in at registration time so
     * request handling never has to walk the group stack.
     *
     * @param string                                $method   Lowercased HTTP method
     * @param string                                $path     Route path
     * @param callable|array{0: class-string, 1: string}|string $callback Route handler
     *
     * @throws \InvalidArgumentException When the method is not routable
     */
    public function addRoute(string $method, string $path, callable|array|string $callback): self
    {
        $method = strtolower($method);

        if (!in_array($method, self::METHODS, true)) {
            throw new \InvalidArgumentException(sprintf('Unsupported HTTP method "%s"', $method));
        }

        $path = $this->normalizePath($this->applyGroupPrefix($path));

        $route = [
            'method' => $method,
            'path' => $path,
            'callback' => $callback,
            'middleware' => [],
            'groupMiddleware' => $this->collectGroupMiddleware(),
            'groupAuth' => $this->getGroupAuth(),
            'groupOpen' => $this->getGroupOpen(),
        ];

        if (str_contains($path, '{')) {
            $compiled = $this->compilePattern($path);
            $route['regex'] = $compiled['regex'];
            $route['params'] = $compiled['params'];

            $this->dynamicRoutes[$method][] = $route;
        } else {
            $this->staticRoutes[$method][$path] = $route;
        }

        $this->lastRoute = ['method' => $method, 'path' => $path];

        return $this;
    }

    /**
     * Attach middleware to the most recently registered route.
     *
     * @param BaseMiddleware $middleware Middleware instance
     */
    public function middleware(BaseMiddleware $middleware): self
    {
        if ($this->lastRoute === null) {
            return $this;
        }

        ['method' => $method, 'path' => $path] = $this->lastRoute;

        if (isset($this->staticRoutes[$method][$path])) {
            $this->staticRoutes[$method][$path]['middleware'][] = $middleware;

            return $this;
        }

        foreach ($this->dynamicRoutes[$method] as $index => $route) {
            if ($route['path'] === $path) {
                $this->dynamicRoutes[$method][$index]['middleware'][] = $middleware;
                break;
            }
        }

        return $this;
    }

    /**
     * Register middleware that runs before every route.
     *
     * @param BaseMiddleware $middleware Middleware instance
     */
    public function addGlobalMiddleware(BaseMiddleware $middleware): void
    {
        $this->globalMiddleware[] = $middleware;
    }

    /**
     * Register middleware that runs before every API route.
     *
     * @param BaseMiddleware $middleware Middleware instance
     */
    public function addApiGlobalMiddleware(BaseMiddleware $middleware): void
    {
        $this->apiGlobalMiddleware[] = $middleware;
    }

    /**
     * Register a batch of routes sharing a prefix, middleware and security policy.
     *
     * Groups nest: prefixes concatenate and middleware accumulates.
     *
     * @param array<string, mixed>|list<string> $options  Group options, or the shorthand `['auth']`
     * @param callable(self): void              $callback Registers the grouped routes
     */
    public function group(array $options, callable $callback): void
    {
        if ($options === ['auth']) {
            $options = ['auth' => true];
        }

        $parent = $this->currentGroup();

        $this->groupStack[] = [
            'prefix' => $this->mergePrefix($parent['prefix'] ?? '', $options['prefix'] ?? ''),
            'auth' => $options['auth'] ?? $parent['auth'] ?? false,
            'open' => $options['open'] ?? $parent['open'] ?? null,
            'middleware' => array_merge(
                $parent['middleware'] ?? [],
                $this->normalizeMiddleware($options['middleware'] ?? [])
            ),
        ];

        try {
            $callback($this);
        } finally {
            array_pop($this->groupStack);
        }
    }

    /**
     * Resolve the current request and return the rendered body.
     *
     * @throws NotFoundException         When no route matches the path
     * @throws MethodNotAllowedException When the path matches under another method
     */
    public function resolve(): string
    {
        $path = $this->normalizePath($this->request->getPath());
        $method = $this->request->method();

        $route = $this->match($method, $path);

        if ($route === null) {
            $this->guardAgainstMethodMismatch($method, $path);

            throw new NotFoundException();
        }

        $callback = $route['callback'];
        $params = $route['matchedParams'];

        $action = $this->resolveAction($callback);

        foreach ($this->middlewareFor($route) as $middleware) {
            $middleware->execute();
        }

        if ($action !== null) {
            foreach ($action->getMiddlewares() as $middleware) {
                $middleware->execute();
            }
        }

        return $this->dispatch($callback, $params);
    }

    /**
     * Find the route matching the given method and path.
     *
     * @param string $method Lowercased HTTP method
     * @param string $path   Normalized request path
     *
     * @return array<string, mixed>|null The matched route with its `matchedParams`
     */
    protected function match(string $method, string $path): ?array
    {
        if (!isset($this->staticRoutes[$method])) {
            return null;
        }

        if (isset($this->staticRoutes[$method][$path])) {
            $route = $this->staticRoutes[$method][$path];
            $route['matchedParams'] = [];

            return $route;
        }

        foreach ($this->dynamicRoutes[$method] as $route) {
            if (preg_match($route['regex'], $path, $matches) !== 1) {
                continue;
            }

            $params = [];
            foreach ($route['params'] as $name) {
                $params[$name] = ($matches[$name] ?? '') !== '' ? $matches[$name] : null;
            }

            $route['matchedParams'] = $params;

            return $route;
        }

        return null;
    }

    /**
     * Throw a 405 when the path is registered under a different method.
     *
     * @param string $method Lowercased HTTP method that was requested
     * @param string $path   Normalized request path
     *
     * @throws MethodNotAllowedException When another method would have matched
     */
    protected function guardAgainstMethodMismatch(string $method, string $path): void
    {
        $allowed = [];

        foreach (self::METHODS as $candidate) {
            if ($candidate !== $method && $this->match($candidate, $path) !== null) {
                $allowed[] = strtoupper($candidate);
            }
        }

        if ($allowed !== []) {
            $this->response->setHeader('Allow', implode(', ', $allowed));

            throw new MethodNotAllowedException(
                sprintf('Method not allowed. Try: %s', implode(', ', $allowed))
            );
        }
    }

    /**
     * Instantiate the Action behind an array callback and bind it to the request.
     *
     * Returns null for closure and screen-name callbacks, which have no action.
     *
     * @param callable|array{0: class-string, 1: string}|string $callback Route handler
     *
     * @throws \RuntimeException When the class is missing or is not an Action
     */
    protected function resolveAction(callable|array|string &$callback): ?Action
    {
        if (!is_array($callback) || !is_string($callback[0])) {
            return null;
        }

        [$class, $activity] = $callback;

        if (!class_exists($class)) {
            throw new \RuntimeException(sprintf('Action class "%s" does not exist', $class));
        }

        if (!is_subclass_of($class, Action::class)) {
            throw new \RuntimeException(
                sprintf('Class "%s" must extend %s', $class, Action::class)
            );
        }

        $action = new $class();
        $action->activity = $activity;

        Application::$app->action = $action;
        $callback = [$action, $activity];

        return $action;
    }

    /**
     * Build the middleware chain for a route, in execution order.
     *
     * @param array<string, mixed> $route Matched route
     *
     * @return list<BaseMiddleware>
     */
    protected function middlewareFor(array $route): array
    {
        $middleware = $this->globalMiddleware;

        if ($this->isApiRequest($route['path'])) {
            $middleware = array_merge($middleware, $this->apiGlobalMiddleware);
        }

        return array_merge(
            $middleware,
            $route['groupMiddleware'] ?? [],
            $route['middleware'] ?? []
        );
    }

    /**
     * Determine whether the request should be treated as an API call.
     *
     * @param string $path Normalized route path
     */
    protected function isApiRequest(string $path): bool
    {
        return str_starts_with($path, '/api/')
            || $path === '/api'
            || $this->request->wantsJson();
    }

    /**
     * Invoke the route handler with the matched parameters.
     *
     * Handlers may declare `Request $request` and `Response $response` as their
     * first parameters; route parameters are matched by name and fall back to
     * positional order.
     *
     * @param callable|array{0: object, 1: string}|string $callback Route handler
     * @param array<string, string|null>                  $params   Matched route parameters
     */
    protected function dispatch(callable|array|string $callback, array $params): string
    {
        if (is_string($callback)) {
            return (string) Application::$app->screen->renderScreen($callback);
        }

        if (is_array($callback)) {
            $reflection = new \ReflectionMethod($callback[0], $callback[1]);

            return (string) $reflection->invokeArgs($callback[0], $this->buildArguments($reflection, $params));
        }

        $reflection = new \ReflectionFunction($callback);

        return (string) $reflection->invokeArgs($this->buildArguments($reflection, $params));
    }

    /**
     * Map matched route parameters onto a handler's signature.
     *
     * @param \ReflectionFunctionAbstract $reflection Handler signature
     * @param array<string, string|null>  $params     Matched route parameters
     *
     * @return list<mixed>
     */
    protected function buildArguments(\ReflectionFunctionAbstract $reflection, array $params): array
    {
        $arguments = [];
        $positional = array_values($params);

        foreach ($reflection->getParameters() as $parameter) {
            $name = $parameter->getName();
            $type = $parameter->getType();
            $typeName = $type instanceof \ReflectionNamedType ? $type->getName() : null;

            if ($typeName === Request::class || $name === 'request') {
                $arguments[] = $this->request;
                continue;
            }

            if ($typeName === Response::class || $name === 'response') {
                $arguments[] = $this->response;
                continue;
            }

            if (array_key_exists($name, $params)) {
                $arguments[] = $params[$name];
                continue;
            }

            if ($positional !== []) {
                $arguments[] = array_shift($positional);
                continue;
            }

            if ($parameter->isDefaultValueAvailable()) {
                $arguments[] = $parameter->getDefaultValue();
                continue;
            }

            $arguments[] = null;
        }

        return $arguments;
    }

    /**
     * Compile a route path into a match pattern.
     *
     * `{name}` matches one required segment and `{name?}` makes both the segment
     * and its leading slash optional.
     *
     * @param string $path Normalized route path
     *
     * @return array{regex: string, params: list<string>}
     */
    protected function compilePattern(string $path): array
    {
        $params = [];
        $regex = '';

        foreach (explode('/', trim($path, '/')) as $segment) {
            if ($segment === '') {
                continue;
            }

            if (preg_match('/^\{([a-zA-Z_][a-zA-Z0-9_]*)(\?)?\}$/', $segment, $matches) !== 1) {
                $regex .= '/' . preg_quote($segment, '#');
                continue;
            }

            $params[] = $matches[1];
            $piece = '/(?P<' . $matches[1] . '>[^/]+)';

            $regex .= isset($matches[2]) ? '(?:' . $piece . ')?' : $piece;
        }

        return [
            'regex' => '#^' . ($regex === '' ? '/' : $regex) . '$#',
            'params' => $params,
        ];
    }

    /**
     * Normalize a path to a leading slash with no trailing slash.
     *
     * @param string $path Raw path
     */
    protected function normalizePath(string $path): string
    {
        $path = '/' . trim($path, '/');

        return $path === '/' ? '/' : rtrim($path, '/');
    }

    /**
     * Concatenate a parent and child group prefix.
     *
     * @param string $parent Parent prefix
     * @param string $child  Child prefix
     */
    private function mergePrefix(string $parent, string $child): string
    {
        if ($parent === '') {
            return $child;
        }

        if ($child === '') {
            return $parent;
        }

        return rtrim($parent, '/') . '/' . ltrim($child, '/');
    }

    /**
     * Prefix a path with the innermost group prefix.
     *
     * @param string $path Route path as declared
     */
    private function applyGroupPrefix(string $path): string
    {
        $prefix = $this->currentGroup()['prefix'] ?? '';

        if ($prefix === '') {
            return $path;
        }

        return '/' . ltrim(rtrim($prefix, '/') . '/' . ltrim($path, '/'), '/');
    }

    /**
     * Get the innermost group, or an empty array outside any group.
     *
     * @return array<string, mixed>
     */
    private function currentGroup(): array
    {
        return $this->groupStack === [] ? [] : end($this->groupStack);
    }

    /**
     * Get the full group stack.
     *
     * @return list<array<string, mixed>>
     */
    public function getGroupStack(): array
    {
        return $this->groupStack;
    }

    /**
     * Get the middleware contributed by the innermost group.
     *
     * Group middleware already accumulates through {@see Router::group()}, so the
     * innermost entry carries the full chain.
     *
     * @return list<BaseMiddleware>
     */
    private function collectGroupMiddleware(): array
    {
        return $this->currentGroup()['middleware'] ?? [];
    }

    /**
     * Get the innermost group's auth flag.
     */
    private function getGroupAuth(): bool
    {
        return (bool) ($this->currentGroup()['auth'] ?? false);
    }

    /**
     * Get the innermost group's list of publicly reachable activities.
     *
     * @return list<string>|null
     */
    private function getGroupOpen(): ?array
    {
        return $this->currentGroup()['open'] ?? null;
    }

    /**
     * Coerce middleware class names into shared instances.
     *
     * @param BaseMiddleware|class-string<BaseMiddleware>|list<BaseMiddleware|class-string<BaseMiddleware>> $middleware
     *
     * @return list<BaseMiddleware>
     *
     * @throws \InvalidArgumentException When an entry is not usable as middleware
     */
    private function normalizeMiddleware(mixed $middleware): array
    {
        if (!is_array($middleware)) {
            $middleware = [$middleware];
        }

        $normalized = [];

        foreach ($middleware as $item) {
            if (is_string($item)) {
                if (!class_exists($item)) {
                    throw new \InvalidArgumentException(
                        sprintf('Middleware class "%s" does not exist', $item)
                    );
                }

                if (!is_subclass_of($item, BaseMiddleware::class)) {
                    throw new \InvalidArgumentException(
                        sprintf('Middleware "%s" must extend %s', $item, BaseMiddleware::class)
                    );
                }

                $this->middlewareInstances[$item] ??= new $item();
                $item = $this->middlewareInstances[$item];
            }

            if (!$item instanceof BaseMiddleware) {
                throw new \InvalidArgumentException(
                    'Middleware must be an instance of BaseMiddleware or a class name string'
                );
            }

            $normalized[] = $item;
        }

        return $normalized;
    }

    /**
     * Check whether a route is registered for the given method and path.
     *
     * @param string $method HTTP method, case insensitive
     * @param string $path   Route path
     */
    public function has(string $method, string $path): bool
    {
        return $this->match(strtolower($method), $this->normalizePath($path)) !== null;
    }

    /**
     * Export every registered route for the `juice routes` inspector.
     *
     * @return list<array<string, mixed>>
     */
    public function getRoutesForInspection(): array
    {
        $formatted = [];

        foreach (self::METHODS as $method) {
            $routes = array_merge(
                array_values($this->staticRoutes[$method]),
                $this->dynamicRoutes[$method]
            );

            foreach ($routes as $route) {
                $formatted[] = [
                    'method' => $method,
                    'path' => $route['path'],
                    'callback' => $route['callback'],
                    'middleware' => $route['middleware'],
                    'groupMiddleware' => $route['groupMiddleware'],
                    'groupAuth' => $route['groupAuth'],
                    'groupOpen' => $route['groupOpen'],
                ];
            }
        }

        return $formatted;
    }
}
