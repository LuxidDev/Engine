<?php

namespace Luxid\Routing;

use Luxid\Exceptions\NotFoundException;
use Luxid\Http\Response;
use Luxid\Http\Request;
use Luxid\Foundation\Application;
use Luxid\Middleware\BaseMiddleware;

class Router
{
    public Request $request;
    public Response $response;
    protected array $routes = [];
    protected ?array $lastRoute = null;
    protected array $middlewareStack = [];
    protected array $globalMiddleware = [];
    protected array $apiGlobalMiddleware = [];

    /**
     * @var array Group context stack
     */
    private array $groupStack = [];

    /**
     * @var array Cached middleware per group stack hash
     */
    private array $groupMiddlewareCache = [];

    /**
     * @var array Pre-instantiated middleware instances
     */
    private array $middlewareInstances = [];

    /**
     * @var array Flattened middleware per route for faster resolution
     */
    private array $flattenedMiddleware = [];

    public function __construct(Request $request, Response $response)
    {
        $this->request = $request;
        $this->response = $response;

        // Initialize all HTTP method arrays
        $this->routes = [
            'get' => [],
            'post' => [],
            'put' => [],
            'patch' => [],
            'delete' => [],
        ];
    }

    public function get($path, $callback)
    {
        $path = $this->applyGroupPrefix($path);
        $groupMiddleware = $this->getCachedGroupMiddleware();

        $this->routes['get'][$path] = [
            'callback' => $callback,
            'middleware' => [],
            'groupMiddleware' => $groupMiddleware,
            'groupAuth' => $this->getGroupAuth(),
            'groupOpen' => $this->getGroupOpen(),
        ];

        // Pre-flatten middleware for this route
        $this->flattenedMiddleware['get:' . $path] = array_merge(
            $this->middlewareStack,
            $groupMiddleware
        );

        $this->lastRoute = ['method' => 'get', 'path' => $path];
        return $this;
    }

    public function post($path, $callback)
    {
        $path = $this->applyGroupPrefix($path);
        $groupMiddleware = $this->getCachedGroupMiddleware();

        $this->routes['post'][$path] = [
            'callback' => $callback,
            'middleware' => [],
            'groupMiddleware' => $groupMiddleware,
            'groupAuth' => $this->getGroupAuth(),
            'groupOpen' => $this->getGroupOpen(),
        ];

        $this->flattenedMiddleware['post:' . $path] = array_merge(
            $this->middlewareStack,
            $groupMiddleware
        );

        $this->lastRoute = ['method' => 'post', 'path' => $path];
        return $this;
    }

    public function put($path, $callback)
    {
        $path = $this->applyGroupPrefix($path);
        $groupMiddleware = $this->getCachedGroupMiddleware();

        $this->routes['put'][$path] = [
            'callback' => $callback,
            'middleware' => [],
            'groupMiddleware' => $groupMiddleware,
            'groupAuth' => $this->getGroupAuth(),
            'groupOpen' => $this->getGroupOpen(),
        ];

        $this->flattenedMiddleware['put:' . $path] = array_merge(
            $this->middlewareStack,
            $groupMiddleware
        );

        $this->lastRoute = ['method' => 'put', 'path' => $path];
        return $this;
    }

    public function patch($path, $callback)
    {
        $path = $this->applyGroupPrefix($path);
        $groupMiddleware = $this->getCachedGroupMiddleware();

        $this->routes['patch'][$path] = [
            'callback' => $callback,
            'middleware' => [],
            'groupMiddleware' => $groupMiddleware,
            'groupAuth' => $this->getGroupAuth(),
            'groupOpen' => $this->getGroupOpen(),
        ];

        $this->flattenedMiddleware['patch:' . $path] = array_merge(
            $this->middlewareStack,
            $groupMiddleware
        );

        $this->lastRoute = ['method' => 'patch', 'path' => $path];
        return $this;
    }

    public function delete($path, $callback)
    {
        $path = $this->applyGroupPrefix($path);
        $groupMiddleware = $this->getCachedGroupMiddleware();

        $this->routes['delete'][$path] = [
            'callback' => $callback,
            'middleware' => [],
            'groupMiddleware' => $groupMiddleware,
            'groupAuth' => $this->getGroupAuth(),
            'groupOpen' => $this->getGroupOpen(),
        ];

        $this->flattenedMiddleware['delete:' . $path] = array_merge(
            $this->middlewareStack,
            $groupMiddleware
        );

        $this->lastRoute = ['method' => 'delete', 'path' => $path];
        return $this;
    }

    /**
     * Add middleware to the last registered route
     */
    public function middleware(BaseMiddleware $middleware)
    {
        if ($this->lastRoute !== null) {
            $method = $this->lastRoute['method'];
            $path = $this->lastRoute['path'];

            if (isset($this->routes[$method][$path])) {
                $this->routes[$method][$path]['middleware'][] = $middleware;
                // Update flattened middleware
                $cacheKey = $method . ':' . $path;
                if (isset($this->flattenedMiddleware[$cacheKey])) {
                    $this->flattenedMiddleware[$cacheKey][] = $middleware;
                }
            }

            $this->lastRoute = null;
        }

        return $this;
    }

    public function addGlobalMiddleware(BaseMiddleware $middleware)
    {
        $this->globalMiddleware[] = $middleware;
    }

    public function addApiGlobalMiddleware(BaseMiddleware $middleware)
    {
        $this->apiGlobalMiddleware[] = $middleware;
    }

    /**
     * Register multiple routes with group configuration
     */
    public function group(array $options, callable $callback)
    {
        // Handle shorthand: ['auth'] -> ['auth' => true]
        if (count($options) === 1 && isset($options[0]) && $options[0] === 'auth') {
            $options = ['auth' => true];
        }

        // Normalize options with inheritance
        $currentGroup = $this->getCurrentGroup();
        $group = [
            'prefix' => $this->mergePrefix($currentGroup['prefix'] ?? '', $options['prefix'] ?? ''),
            'auth' => $options['auth'] ?? $currentGroup['auth'] ?? false,
            'open' => $options['open'] ?? $currentGroup['open'] ?? null,
            'middleware' => array_merge(
                $currentGroup['middleware'] ?? [],
                $this->normalizeMiddleware($options['middleware'] ?? [])
            ),
        ];

        // Push group onto stack
        $this->groupStack[] = $group;
        // Clear caches since groups changed
        $this->groupMiddlewareCache = [];

        try {
            // Execute callback with group context
            call_user_func($callback, $this);
        } finally {
            // Pop group from stack
            array_pop($this->groupStack);
            // Clear caches again
            $this->groupMiddlewareCache = [];
        }
    }

    /**
     * Get cached group middleware with instantiation optimization
     */
    private function getCachedGroupMiddleware(): array
    {
        $cacheKey = $this->getGroupStackCacheKey();

        if (isset($this->groupMiddlewareCache[$cacheKey])) {
            return $this->groupMiddlewareCache[$cacheKey];
        }

        $middleware = [];
        foreach ($this->groupStack as $group) {
            foreach ($group['middleware'] as $mw) {
                $middleware[] = $mw;
            }
        }

        $this->groupMiddlewareCache[$cacheKey] = $middleware;

        return $middleware;
    }

    /**
     * Generate cache key from group stack state
     */
    private function getGroupStackCacheKey(): string
    {
        if (empty($this->groupStack)) {
            return 'empty';
        }

        $keys = [];
        foreach ($this->groupStack as $index => $group) {
            // Create a fingerprint of middleware classes (not instances)
            $mwClasses = [];
            foreach ($group['middleware'] as $mw) {
                $mwClasses[] = get_class($mw);
            }
            $keys[] = $index . ':' . md5(serialize($mwClasses));
        }

        return implode('|', $keys);
    }

    /**
     * Clear middleware cache for a specific route
     */
    private function clearMiddlewareCache(string $method, string $path): void
    {
        $key = $method . ':' . $path;
        unset($this->middlewareCache[$key]);
    }

    /**
     * Merge prefixes for nested groups
     */
    private function mergePrefix(string $parent, string $child): string
    {
        if (empty($parent)) {
            return $child;
        }
        if (empty($child)) {
            return $parent;
        }

        return rtrim($parent, '/') . '/' . ltrim($child, '/');
    }

    /**
     * Get current group from stack
     */
    private function getCurrentGroup(): array
    {
        if (empty($this->groupStack)) {
            return [];
        }

        return end($this->groupStack);
    }

    /**
     * Get current group stack (public for RouteBuilder)
     */
    public function getGroupStack(): array
    {
        return $this->groupStack;
    }

    /**
     * Normalize middleware array with instantiation optimization
     */
    private function normalizeMiddleware($middleware): array
    {
        if (!is_array($middleware)) {
            $middleware = [$middleware];
        }

        $normalized = [];
        foreach ($middleware as $item) {
            if (is_string($item)) {
                // Check if we already have an instance of this middleware
                if (!isset($this->middlewareInstances[$item])) {
                    if (!class_exists($item)) {
                        throw new \InvalidArgumentException(
                            sprintf('Middleware class "%s" does not exist', $item)
                        );
                    }

                    if (!is_subclass_of($item, BaseMiddleware::class)) {
                        throw new \InvalidArgumentException(
                            sprintf('Middleware "%s" must extend BaseMiddleware', $item)
                        );
                    }

                    $this->middlewareInstances[$item] = new $item();
                }

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
     * Get current group middleware
     */
    private function getGroupMiddleware(): array
    {
        $middleware = [];

        foreach ($this->groupStack as $group) {
            $middleware = array_merge($middleware, $group['middleware']);
        }

        return $middleware;
    }

    /**
     * Get current group auth configuration
     */
    private function getGroupAuth(): bool
    {
        if (empty($this->groupStack)) {
            return false;
        }

        $current = end($this->groupStack);
        return $current['auth'] ?? false;
    }

    /**
     * Get current group open configuration
     */
    private function getGroupOpen(): ?array
    {
        if (empty($this->groupStack)) {
            return null;
        }

        $current = end($this->groupStack);
        return $current['open'] ?? null;
    }

    /**
     * Apply group prefix to path
     */
    private function applyGroupPrefix(string $path): string
    {
        $prefix = '';

        foreach ($this->groupStack as $group) {
            if (!empty($group['prefix'])) {
                $prefix .= rtrim($group['prefix'], '/') . '/';
            }
        }

        if ($prefix !== '') {
            $path = ltrim($path, '/');
            return rtrim($prefix, '/') . '/' . $path;
        }

        return $path;
    }

    public function resolve()
    {
        $path = $this->request->getPath();
        $method = $this->request->method();

        $sessionRoutes = [
            '/api/auth/me',
            '/api/auth/logout',
        ];

        $needsSession = in_array($path, $sessionRoutes);

        // Only start session if needed
        if ($needsSession && php_sapi_name() !== 'cli') {
            Application::$app->getSession();
        }

        if (isset($this->routes[$method][$path])) {
            $route = $this->routes[$method][$path];
            $callback = $route['callback'];
            $params = [];
            $matchedRoutePath = $path;
        } else {
            // Try to find a parameterized route match
            $foundRoute = null;
            $params = [];
            $matchedRoutePath = null;

            foreach ($this->routes[$method] as $routePath => $routeData) {
                // Only check routes with parameters
                if (strpos($routePath, '{') === false) {
                    continue;
                }

                // Try to extract parameters using the ROUTE PATTERN
                $extractedParams = $this->extractRouteParams($routePath);
                if (!empty($extractedParams)) {
                    $foundRoute = $routeData;
                    $params = $extractedParams;
                    $matchedRoutePath = $routePath;
                    break;
                }
            }

            if (!$foundRoute) {
                throw new NotFoundException();
            }

            $route = $foundRoute;
            $callback = $route['callback'];
        }

        // Determine if this is an API request
        $isApiRequest = strpos($path, '/api/') === 0
          || (isset($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false)
          || (isset($_SERVER['CONTENT_TYPE']) && strpos($_SERVER['CONTENT_TYPE'], 'application/json') !== false);

        // Skip all middleware for fast path
        if ($path === '/api/health') {
            // No middleware, direct to response
        } else {
            foreach ($this->globalMiddleware as $middleware) {
                $middleware->execute();
            }

            if ($isApiRequest) {
                foreach ($this->apiGlobalMiddleware as $middleware) {
                    $middleware->execute();
                }
            }
        }

        // Combine flattened route middleware
        $cacheKey = $method . ':' . $path;
        $middlewares = isset($this->flattenedMiddleware[$cacheKey])
          ? array_merge($this->flattenedMiddleware[$cacheKey], $route['middleware'])
          : array_merge($this->middlewareStack, $route['groupMiddleware'] ?? [], $route['middleware']);

        if (is_string($callback)) {
            return Application::$app->screen->renderScreen($callback);
        }

        if (is_array($callback)) {
            // Validate callback class exists and is instantiable
            if (!class_exists($callback[0])) {
                throw new \RuntimeException(
                    sprintf('Action class "%s" does not exist', $callback[0])
                );
            }

            if (!is_subclass_of($callback[0], '\\Luxid\\Foundation\\Action')) {
                throw new \RuntimeException(
                    sprintf('Class "%s" must extend \\Luxid\\Foundation\\Action', $callback[0])
                );
            }

            $action = new $callback[0]();
            Application::$app->action = $action;
            $action->activity = $callback[1];
            $callback[0] = $action;

            // Execute route middleware
            foreach ($middlewares as $middleware) {
                $middleware->execute();
            }

            // Execute action middleware
            foreach ($action->getMiddlewares() as $middleware) {
                $middleware->execute();
            }
        }

        if (!empty($params)) {
            // Use reflection to properly call the method
            if (is_array($callback)) {
                $reflection = new \ReflectionMethod($callback[0], $callback[1]);
                $parameters = $reflection->getParameters();

                // Build arguments array
                $args = [];

                // First two parameters should be Request and Response
                if (count($parameters) > 0 && $parameters[0]->getName() === 'request') {
                    $args[] = $this->request;
                }
                if (count($parameters) > 1 && $parameters[1]->getName() === 'response') {
                    $args[] = $this->response;
                }

                // Add route parameters
                $args = array_merge($args, array_values($params));

                return $reflection->invokeArgs($callback[0], $args);
            } else {
                // For closures, use the old method
                return call_user_func_array($callback, array_merge(
                    [$this->request, $this->response],
                    array_values($params)
                ));
            }
        }

        return call_user_func($callback, $this->request, $this->response);
    }

    /**
     * Extract parameters from route path with trailing slash normalization
     */
    private function extractRouteParams(string $routePath): array
    {
        $actualPath = $this->request->getPath();

        // Normalize trailing slashes
        $routePath = trim($routePath, '/');
        $actualPath = trim($actualPath, '/');

        // Handle empty paths
        if ($routePath === '' && $actualPath === '') {
            return [];
        }

        if ($routePath === '' || $actualPath === '') {
            return [];
        }

        $routeParts = explode('/', $routePath);
        $actualParts = explode('/', $actualPath);

        // Match segments
        $routeIndex = 0;
        $actualIndex = 0;

        while ($routeIndex < count($routeParts) && $actualIndex < count($actualParts)) {
            $routePart = $routeParts[$routeIndex];

            if ($this->isParameter($routePart)) {
                $paramName = $this->extractParamName($routePart);
                $isOptional = $this->isOptionalParam($routePart);

                // Store parameter if we have actual segment
                if ($actualIndex < count($actualParts)) {
                    $params[$paramName] = $actualParts[$actualIndex];
                    $actualIndex++;
                } elseif (!$isOptional) {
                    // Required parameter missing
                    return [];
                }
                // Optional parameter missing - skip it
            } else {
                // Static segment must match
                if ($routePart !== $actualParts[$actualIndex]) {
                    // Check if this mismatch could be due to skipped optional params
                    if ($this->canSkipToNextMatch($routeParts, $routeIndex, $actualParts, $actualIndex)) {
                        // Skip this route segment and try to match next actual segment
                        $routeIndex++;
                        continue;
                    }
                    return [];
                }
                $actualIndex++;
            }

            $routeIndex++;
        }

        // Check if we've consumed all route parts
        if ($routeIndex < count($routeParts)) {
            // Remaining parts must all be optional
            for ($i = $routeIndex; $i < count($routeParts); $i++) {
                if (!$this->isOptionalParam($routeParts[$i])) {
                    return [];
                }
            }
        }

        // Check if we have extra actual segments (should match wildcards if we had them)
        if ($actualIndex < count($actualParts)) {
            return [];
        }

        return $params ?? [];
    }


    /**
     * Check if we can skip to next matching segment
     */
    private function canSkipToNextMatch(array $routeParts, int $routeIndex, array $actualParts, int $actualIndex): bool
    {
        // Look ahead to see if skipping optional params helps
        $nextRouteIndex = $routeIndex + 1;
        while ($nextRouteIndex < count($routeParts)) {
            if ($this->isOptionalParam($routeParts[$nextRouteIndex])) {
                $nextRouteIndex++;
            } else {
                break;
            }
        }

        // If all remaining are optional, we can skip
        if ($nextRouteIndex >= count($routeParts)) {
            return true;
        }

        // Check if skipping optional params would allow a match
        for ($i = $actualIndex; $i < count($actualParts); $i++) {
            if ($routeParts[$nextRouteIndex] === $actualParts[$i]) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if route part is a parameter
     */
    private function isParameter(string $part): bool
    {
        return preg_match('/^{([^}]+)}$/', $part);
    }

    /**
     * Extract parameter name from {param} or {param?}
     */
    private function extractParamName(string $part): string
    {
        preg_match('/^{([^}]+)}$/', $part, $matches);
        $name = $matches[1];

        // Remove optional marker
        if (substr($name, -1) === '?') {
            $name = substr($name, 0, -1);
        }

        return $name;
    }


    /**
     * Check if parameter is optional
     */
    private function isOptionalParam(string $part): bool
    {
        return preg_match('/^{([^}]+)\?}$/', $part);
    }

    public function getRoutesForInspection(): array
    {
        $formattedRoutes = [];

        foreach ($this->routes as $method => $methodRoutes) {
            foreach ($methodRoutes as $path => $route) {
                $formattedRoutes[] = [
                    'method' => $method,
                    'path' => $path,
                    'callback' => $route['callback'],
                    'middleware' => $route['middleware'] ?? [],
                    'groupMiddleware' => $route['groupMiddleware'] ?? [],
                    'groupAuth' => $route['groupAuth'] ?? false,
                    'groupOpen' => $route['groupOpen'] ?? null,
                ];
            }
        }

        return $formattedRoutes;
    }
}
