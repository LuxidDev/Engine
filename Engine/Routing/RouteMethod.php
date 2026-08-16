<?php

declare(strict_types=1);

namespace Luxid\Routing;

/**
 * A method/activity pair produced by the `get()`, `post()`, `put()`, `patch()`
 * and `delete()` helpers.
 *
 * Exists so `Routes::add('/health', get('index'))` reads as a sentence while
 * still carrying both halves of the binding.
 *
 * @package Luxid\Routing
 */
final class RouteMethod
{
    /**
     * @param string $method  Lowercased HTTP method
     * @param string $handler Activity (method name) on the action class
     */
    public function __construct(
        private readonly string $method,
        private readonly string $handler,
    ) {
    }

    /**
     * Get the HTTP method.
     */
    public function getMethod(): string
    {
        return $this->method;
    }

    /**
     * Get the activity name.
     */
    public function getHandler(): string
    {
        return $this->handler;
    }
}
