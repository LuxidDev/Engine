<?php

declare(strict_types=1);

namespace Luxid\Foundation;

use Luxid\Middleware\BaseMiddleware;
use Luxid\Routing\Routes;

/**
 * Base class for actions — the "A" in SEA.
 *
 * An action groups the activities (handler methods) for one slice of the domain
 * and declares its own routes, so a feature's dispatch table lives beside its
 * behaviour rather than in a central file.
 *
 * @package Luxid\Foundation
 */
class Action
{
    use ActionHelpers;

    /**
     * Frame (layout) used when rendering screens from this action.
     */
    public string $frame = 'app';

    /**
     * Name of the activity handling the current request.
     */
    public string $activity = '';

    /**
     * Middleware run after the route middleware, before the activity.
     *
     * @var list<BaseMiddleware>
     */
    protected array $middlewares = [];

    /**
     * Declare the routes this action serves.
     *
     * Override in subclasses; the base implementation registers nothing.
     */
    public static function routes(): Routes
    {
        return Routes::new();
    }

    /**
     * Get the action-level middleware.
     *
     * @return list<BaseMiddleware>
     */
    public function getMiddlewares(): array
    {
        return $this->middlewares;
    }

    /**
     * Attach middleware that runs for every activity on this action.
     *
     * @param BaseMiddleware $middleware Middleware instance
     */
    public function registerMiddleware(BaseMiddleware $middleware): self
    {
        $this->middlewares[] = $middleware;

        return $this;
    }

    /**
     * Choose the frame used when rendering screens from this action.
     *
     * @param string $frame Frame name
     */
    public function setFrame(string $frame): self
    {
        $this->frame = $frame;

        return $this;
    }

    /**
     * Render a screen through the legacy screen renderer.
     *
     * @param string               $screen Screen name
     * @param array<string, mixed> $data   Variables exposed to the screen
     */
    public function nova(string $screen, array $data = []): string
    {
        return $this->app()->screen->renderScreen($screen, $data);
    }
}
