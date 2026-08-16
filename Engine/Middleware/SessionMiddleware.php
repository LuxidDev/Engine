<?php

declare(strict_types=1);

namespace Luxid\Middleware;

use Luxid\Foundation\Application;

/**
 * Boots the session and hydrates the authenticated user.
 *
 * Registered globally by the kernel. Previously the router started the session
 * for a hardcoded list of two paths, which left `Application::$app->user` null on
 * every other route and made session based authorization fail for logged in
 * users. Booting here means any route can rely on the session being available.
 *
 * @package Luxid\Middleware
 */
class SessionMiddleware extends BaseMiddleware
{
    /**
     * Resolve the session, which also loads the user behind it.
     */
    public function execute(): void
    {
        if (PHP_SAPI === 'cli') {
            return;
        }

        Application::$app->getSession();
    }
}
