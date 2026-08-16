<?php

declare(strict_types=1);

namespace Luxid\Tests\Fixtures;

use Luxid\Foundation\Action;
use Luxid\Routing\Routes;

/**
 * Minimal action used to exercise action-backed routes.
 *
 * @package Luxid\Tests\Fixtures
 */
class TodoAction extends Action
{
    /**
     * Declare the routes this action serves.
     */
    public static function routes(): Routes
    {
        return Routes::new()
            ->prefix('api')
            ->add('/todos', get('index'))
            ->add('/todos/{id}', get('show'))
            ->public();
    }

    /**
     * List every todo.
     */
    public function index(): string
    {
        return 'todos:index';
    }

    /**
     * Show one todo.
     *
     * @param string|null $id Todo identifier from the route
     */
    public function show(?string $id = null): string
    {
        return 'todos:show:' . $id;
    }
}
