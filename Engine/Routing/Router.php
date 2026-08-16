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
     * The same methods as a lookup set, so validation is a hash hit rather than
     * a linear scan on every registration.
     */
    private const METHOD_SET = ['get' => 0, 'post' => 1, 'put' => 2, 'patch' => 3, 'delete' => 4];

    /**
     * The request being routed.
     */
    public Request $request;

    /**
     * The response being built.
     */
    public Response $response;

    /**
     * Every registered route, keyed by an incrementing id.
     *
     * Routes live here once and the indexes below hold ids, so matching never
     * copies a route array. Copying one meant duplicating its callback and
     * middleware lists on every request.
     *
     * @var array<int, array<string, mixed>>
     */
    protected array $routes = [];

    /**
     * Ids of placeholder-free routes, keyed by method then path.
     *
     * @var array<string, array<string, int>>
     */
    protected array $staticIndex = [];

    /**
     * Ids of placeholder routes, keyed by method, segment count and first segment.
     *
     * Bucketing by segment count and leading literal means a request only tests
     * the handful of patterns that could possibly match, rather than every
     * dynamic route in the table.
     *
     * @var array<string, array<int, array<string, list<int>>>>
     */
    protected array $dynamicIndex = [];

    /**
     * Resolved middleware chains, keyed by route id.
     *
     * @var array<int, list<BaseMiddleware>>
     */
    protected array $chainCache = [];

    /**
     * Id assigned to the next registered route.
     */
    protected int $nextRouteId = 0;

    /**
     * Id of the most recently registered route.
     */
    protected ?int $lastRoute = null;

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
     * The innermost group, kept in sync with the stack.
     *
     * Registration needs the prefix, middleware, auth flag and open list; each
     * used to walk the stack separately, so a single route resolved the group
     * four times.
     *
     * @var array{prefix: string, auth: bool, open: list<string>|null, middleware: list<BaseMiddleware>}
     */
    private array $activeGroup = ['prefix' => '', 'auth' => false, 'open' => null, 'middleware' => []];

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
        if (!isset(self::METHOD_SET[$method])) {
            $method = strtolower($method);

            if (!isset(self::METHOD_SET[$method])) {
                throw new \InvalidArgumentException(sprintf('Unsupported HTTP method "%s"', $method));
            }
        }

        $group = $this->activeGroup;

        $path = $group['prefix'] === ''
            ? $this->normalizePath($path)
            : $this->normalizePath(rtrim($group['prefix'], '/') . '/' . ltrim($path, '/'));

        $id = $this->nextRouteId++;

        $route = [
            'id' => $id,
            'method' => $method,
            'path' => $path,
            'callback' => $callback,
            'middleware' => [],
            'groupMiddleware' => $group['middleware'],
            'groupAuth' => $group['auth'],
            'groupOpen' => $group['open'],
        ];

        if (str_contains($path, '{')) {
            $compiled = $this->compilePattern($path);
            $route['regex'] = $compiled['regex'];
            $route['params'] = $compiled['params'];

            $this->routes[$id] = $route;
            $this->indexDynamic($method, $id, $compiled);
        } else {
            $this->routes[$id] = $route;
            $this->staticIndex[$method][$path] = $id;
        }

        $this->lastRoute = $id;

        return $this;
    }

    /**
     * File a dynamic route under every segment count it can match.
     *
     * A route with optional placeholders matches a range of lengths, so it is
     * filed under each one. The second level keys on the leading literal
     * segment where there is one, which is the common case.
     *
     * @param string                                                             $method   Lowercased HTTP method
     * @param int                                                                $id       Route id
     * @param array{regex: string, params: list<string>, min: int, max: int, head: string} $compiled Compiled pattern
     */
    protected function indexDynamic(string $method, int $id, array $compiled): void
    {
        for ($count = $compiled['min']; $count <= $compiled['max']; $count++) {
            $this->dynamicIndex[$method][$count][$compiled['head']][] = $id;
        }
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

        $this->routes[$this->lastRoute]['middleware'][] = $middleware;
        unset($this->chainCache[$this->lastRoute]);

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
        $this->chainCache = [];
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

        $parent = $this->activeGroup;

        $this->groupStack[] = $this->activeGroup = [
            'prefix' => $this->mergePrefix($parent['prefix'], $options['prefix'] ?? ''),
            'auth' => (bool) ($options['auth'] ?? $parent['auth']),
            'open' => $options['open'] ?? $parent['open'],
            'middleware' => array_merge(
                $parent['middleware'],
                $this->normalizeMiddleware($options['middleware'] ?? [])
            ),
        ];

        try {
            $callback($this);
        } finally {
            array_pop($this->groupStack);
            $this->activeGroup = $this->groupStack === []
                ? ['prefix' => '', 'auth' => false, 'open' => null, 'middleware' => []]
                : $this->groupStack[array_key_last($this->groupStack)];
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

        $matched = $this->match($method, $path);

        if ($matched === null) {
            $this->guardAgainstMethodMismatch($method, $path);

            throw new NotFoundException();
        }

        [$id, $params] = $matched;

        $callback = $this->routes[$id]['callback'];
        $action = $this->resolveAction($callback);

        foreach ($this->middlewareFor($id) as $middleware) {
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
     * Static paths resolve through a hash lookup. Dynamic paths are narrowed by
     * segment count and leading literal before any pattern is tested, so a large
     * route table costs about as much to match as a small one.
     *
     * @param string $method Lowercased HTTP method
     * @param string $path   Normalized request path
     *
     * @return array{0: int, 1: array<string, string|null>}|null Route id and matched parameters
     */
    protected function match(string $method, string $path): ?array
    {
        $id = $this->staticIndex[$method][$path] ?? null;

        if ($id !== null) {
            return [$id, []];
        }

        $buckets = $this->dynamicIndex[$method] ?? null;

        if ($buckets === null) {
            return null;
        }

        $segments = $path === '/' ? [] : explode('/', substr($path, 1));
        $candidates = $buckets[count($segments)] ?? null;

        if ($candidates === null) {
            return null;
        }

        $head = $segments[0] ?? '';

        // Patterns whose first segment is a literal live under that literal;
        // everything else shares the wildcard bucket.
        foreach ([$head, '*'] as $key) {
            foreach ($candidates[$key] ?? [] as $candidateId) {
                $route = $this->routes[$candidateId];

                if (preg_match($route['regex'], $path, $matches) !== 1) {
                    continue;
                }

                $params = [];

                foreach ($route['params'] as $name) {
                    $value = $matches[$name] ?? '';
                    $params[$name] = $value === '' ? null : $value;
                }

                return [$candidateId, $params];
            }

            if ($head === '*') {
                break;
            }
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
            if ($candidate === $method) {
                continue;
            }

            // Skip methods with no routes at all before doing any matching;
            // most tables only register two or three of the five.
            if (!isset($this->staticIndex[$candidate]) && !isset($this->dynamicIndex[$candidate])) {
                continue;
            }

            if ($this->match($candidate, $path) !== null) {
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
     * The route-scoped half of the chain never changes once registration is
     * done, so it is merged once and reused. Only the API-global segment
     * depends on the request, and it is appended without rebuilding the rest.
     *
     * @param int $id Matched route id
     *
     * @return list<BaseMiddleware>
     */
    protected function middlewareFor(int $id): array
    {
        $chain = $this->chainCache[$id] ??= array_merge(
            $this->globalMiddleware,
            $this->routes[$id]['groupMiddleware'],
            $this->routes[$id]['middleware']
        );

        if ($this->apiGlobalMiddleware === [] || !$this->isApiRequest($this->routes[$id]['path'])) {
            return $chain;
        }

        // API middleware runs after global but before the route's own, matching
        // the order a single merge would produce.
        return array_merge(
            $this->globalMiddleware,
            $this->apiGlobalMiddleware,
            $this->routes[$id]['groupMiddleware'],
            $this->routes[$id]['middleware']
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
     * @return array{regex: string, params: list<string>, min: int, max: int, head: string}
     */
    protected function compilePattern(string $path): array
    {
        $params = [];
        $regex = '';
        $required = 0;
        $optional = 0;
        $head = null;

        foreach (explode('/', trim($path, '/')) as $segment) {
            if ($segment === '') {
                continue;
            }

            // Placeholders are recognised by their delimiters rather than a
            // regex, so compiling a route table costs no pattern matching.
            $isPlaceholder = $segment[0] === '{' && str_ends_with($segment, '}');
            $name = $isPlaceholder ? substr($segment, 1, -1) : '';
            $isOptional = $name !== '' && $name[-1] === '?';

            if ($isOptional) {
                $name = substr($name, 0, -1);
            }

            if (!$isPlaceholder || !self::isParameterName($name)) {
                $regex .= '/' . (ctype_alnum(str_replace(['_', '-'], '', $segment))
                    ? $segment
                    : preg_quote($segment, '#'));
                $head ??= $segment;
                ++$required;

                continue;
            }

            $params[] = $name;
            $piece = '/(?P<' . $name . '>[^/]+)';
            $head ??= '*';

            if ($isOptional) {
                $regex .= '(?:' . $piece . ')?';
                ++$optional;
            } else {
                $regex .= $piece;
                ++$required;
            }
        }

        return [
            'regex' => '#^' . ($regex === '' ? '/' : $regex) . '$#',
            'params' => $params,
            'min' => $required,
            'max' => $required + $optional,
            'head' => $head ?? '*',
        ];
    }

    /**
     * Check whether a placeholder name is a usable capture group name.
     *
     * PCRE group names must start with a letter or underscore and contain only
     * word characters; anything else is treated as a literal segment.
     *
     * @param string $name Candidate placeholder name
     */
    private static function isParameterName(string $name): bool
    {
        if ($name === '' || !(ctype_alpha($name[0]) || $name[0] === '_')) {
            return false;
        }

        return ctype_alnum(str_replace('_', '', $name)) || str_replace('_', '', $name) === '';
    }

    /**
     * Normalize a path to a leading slash with no trailing slash.
     *
     * @param string $path Raw path
     */
    protected function normalizePath(string $path): string
    {
        // trim already removed any trailing slash, so one pass is enough.
        return $path === '/' || $path === '' ? '/' : '/' . trim($path, '/');
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
        return $this->activeGroup;
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

        foreach ($this->routes as $route) {
            $formatted[] = [
                'method' => $route['method'],
                'path' => $route['path'],
                'callback' => $route['callback'],
                'middleware' => $route['middleware'],
                'groupMiddleware' => $route['groupMiddleware'],
                'groupAuth' => $route['groupAuth'],
                'groupOpen' => $route['groupOpen'],
            ];
        }

        // Grouped by method so the CLI inspector's output stays stable.
        usort(
            $formatted,
            static fn (array $a, array $b): int => self::METHOD_SET[$a['method']] <=> self::METHOD_SET[$b['method']]
        );

        return $formatted;
    }
}
