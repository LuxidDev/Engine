<?php

declare(strict_types=1);

namespace Luxid\Middleware;

/**
 * Marks a route as reachable without authentication.
 *
 * Deliberately a no-op. Its value is declarative: `RouteBuilder` requires every
 * route to state a security posture, and this is how a route says "public" in a
 * way the `juice routes` inspector can report.
 *
 * @package Luxid\Middleware
 */
class PublicMiddleware extends BaseMiddleware
{
    /**
     * Allow the request through unconditionally.
     */
    public function execute(): void
    {
    }
}
