<?php

declare(strict_types=1);

namespace Luxid\Nodes;

use Luxid\Foundation\Application;
use Luxid\Routing\RouteBuilder;

/**
 * Static entry point to the fluent route DSL.
 *
 * `Route::todos()` and `Route::make('todos')` are equivalent; the magic call
 * exists so route files read as declarations rather than method calls.
 *
 * @package Luxid\Nodes
 */
class Route
{
    /**
     * Start a new fluent route definition.
     *
     * @param string $name Human readable route name
     *
     * @throws \RuntimeException When the application has not booted yet
     */
    public static function make(string $name): RouteBuilder
    {
        return new RouteBuilder(self::router(), $name);
    }

    /**
     * Alias for {@see Route::make()}.
     *
     * @param string $name Human readable route name
     */
    public static function name(string $name): RouteBuilder
    {
        return self::make($name);
    }

    /**
     * Register a group of routes sharing a prefix, middleware and security.
     *
     * @param array<string, mixed>|list<string> $options  Group options
     * @param callable                          $callback Registers the grouped routes
     *
     * @throws \RuntimeException When the application has not booted yet
     */
    public static function group(array $options, callable $callback): void
    {
        self::router()->group($options, $callback);
    }

    /**
     * Get every registered route, for debugging and the CLI inspector.
     *
     * @return list<array<string, mixed>>
     *
     * @throws \RuntimeException When the application has not booted yet
     */
    public static function all(): array
    {
        return self::router()->getRoutesForInspection();
    }

    /**
     * Resolve the application router.
     *
     * @throws \RuntimeException When the application has not booted yet
     */
    private static function router(): \Luxid\Routing\Router
    {
        if (!isset(Application::$app)) {
            throw new \RuntimeException(
                'Application not initialized. Create an Application instance before defining routes.'
            );
        }

        return Application::$app->router;
    }

    /**
     * Treat any unknown static call as a route name.
     *
     * @param string       $method Route name
     * @param list<mixed>  $args   Ignored
     */
    public static function __callStatic(string $method, array $args): RouteBuilder
    {
        return self::make($method);
    }
}
