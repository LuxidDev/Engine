<?php

namespace Luxid\Routing;

use Luxid\Foundation\Application;

class Routes
{
    private array $routes = [];
    private string $prefix = '';
    private array $middlewares = [];

    public static function new(): self
    {
        return new self();
    }

    public function prefix(string $prefix): self
    {
        $this->prefix = $prefix;
        return $this;
    }

    public function middleware(string|array $middleware): self
    {
        $this->middlewares = array_merge($this->middlewares, (array) $middleware);
        return $this;
    }

    public function add(string $path, RouteMethod $method): self
    {
        $this->routes[] = [
            'method' => $method->getMethod(),
            'path' => $path,
            'handler' => $method->getHandler(),
            'middlewares' => $this->middlewares,
        ];

        return $this;
    }

    public function name(string $name): self
    {
        if (!empty($this->routes)) {
            $lastIndex = count($this->routes) - 1;
            $this->routes[$lastIndex]['name'] = $name;
        }
        return $this;
    }

    public function register(?string $actionClass = null): void
    {
        if (!$actionClass) {
            throw new \RuntimeException(
                'Action class required. Use: ActionClass::routes()->register(ActionClass::class)'
            );
        }

        if (!Application::$app) {
            throw new \RuntimeException('Application not initialized');
        }

        $router = Application::$app->router;

        // If action class not provided, try to determine it from the call stack
        if ($actionClass === null) {
            $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 3);

            // Look for the class that called register
            for ($i = 1; $i < count($trace); $i++) {
                if (isset($trace[$i]['class']) &&
                    $trace[$i]['class'] !== __CLASS__ &&
                    $trace[$i]['class'] !== 'Luxid\\Routing\\Routes') {
                    $actionClass = $trace[$i]['class'];
                    break;
                }
            }

            // If still not found, check the previous frame
            if ($actionClass === null && isset($trace[2]['class'])) {
                $actionClass = $trace[2]['class'];
            }
        }

        if (!$actionClass) {
            throw new \RuntimeException(
                'Could not determine action class for route registration. ' .
                'Please pass the action class explicitly: ActionClass::routes()->register(ActionClass::class)'
            );
        }

        foreach ($this->routes as $route) {
            // Build full path
            $fullPath = $route['path'];
            if ($this->prefix) {
                $fullPath = rtrim($this->prefix, '/') . '/' . ltrim($route['path'], '/');
            }
            $fullPath = '/' . ltrim($fullPath, '/');

            // Register with router
            call_user_func([$router, $route['method']], $fullPath, [$actionClass, $route['handler']]);

            // Add middlewares
            foreach ($route['middlewares'] as $middleware) {
                if (is_string($middleware)) {
                    $middleware = new $middleware();
                }
                $router->middleware($middleware);
            }
        }
    }
}