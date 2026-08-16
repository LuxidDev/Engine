<?php

declare(strict_types=1);

use Luxid\Nodes\Route;
use Luxid\Routing\RouteBuilder;
use Luxid\Routing\RouteMethod;

if (!function_exists('route')) {
    /**
     * Start a fluent route definition.
     *
     * @param string $name Human readable route name
     *
     * @throws RuntimeException When the application has not booted yet
     */
    function route(string $name): RouteBuilder
    {
        return Route::make($name);
    }
}

if (!function_exists('route_group')) {
    /**
     * Register a group of routes sharing a prefix, middleware and security.
     *
     * @param array<string, mixed>|list<string> $options  Group options
     * @param callable                          $callback Registers the grouped routes
     */
    function route_group(array $options, callable $callback): void
    {
        Route::group($options, $callback);
    }
}

if (!function_exists('get')) {
    /**
     * Bind a GET route to an activity on the enclosing action.
     *
     * @param string $handler Activity (method) name
     */
    function get(string $handler): RouteMethod
    {
        return new RouteMethod('get', $handler);
    }
}

if (!function_exists('post')) {
    /**
     * Bind a POST route to an activity on the enclosing action.
     *
     * @param string $handler Activity (method) name
     */
    function post(string $handler): RouteMethod
    {
        return new RouteMethod('post', $handler);
    }
}

if (!function_exists('put')) {
    /**
     * Bind a PUT route to an activity on the enclosing action.
     *
     * @param string $handler Activity (method) name
     */
    function put(string $handler): RouteMethod
    {
        return new RouteMethod('put', $handler);
    }
}

if (!function_exists('patch')) {
    /**
     * Bind a PATCH route to an activity on the enclosing action.
     *
     * @param string $handler Activity (method) name
     */
    function patch(string $handler): RouteMethod
    {
        return new RouteMethod('patch', $handler);
    }
}

if (!function_exists('delete')) {
    /**
     * Bind a DELETE route to an activity on the enclosing action.
     *
     * @param string $handler Activity (method) name
     */
    function delete(string $handler): RouteMethod
    {
        return new RouteMethod('delete', $handler);
    }
}

if (!function_exists('e')) {
    /**
     * Escape a value for safe interpolation into HTML.
     *
     * @param mixed $value Value to escape
     */
    function e(mixed $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
