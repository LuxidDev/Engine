<?php

declare(strict_types=1);

namespace Luxid\Middleware;

/**
 * Base class for every middleware.
 *
 * Middleware run before the action and signal rejection by throwing; returning
 * normally lets the request continue down the chain.
 *
 * @package Luxid\Middleware
 */
abstract class BaseMiddleware
{
    /**
     * Run the middleware.
     *
     * @throws \Exception To halt the request
     */
    abstract public function execute(): void;
}
