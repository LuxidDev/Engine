<?php

declare(strict_types=1);

namespace Luxid\Foundation;

use Luxid\Database\DbEntity;
use Luxid\Http\Request;
use Luxid\Http\Response;
use Luxid\Http\SessionInterface;
use Luxid\Routing\Router;
use Rocket\Connection\Connection;

/**
 * Convenience accessors shared by every action.
 *
 * Kept as a trait rather than base-class methods so applications can mix them
 * into their own hierarchy.
 *
 * @package Luxid\Foundation
 */
trait ActionHelpers
{
    /**
     * Get the application kernel.
     */
    protected function app(): Application
    {
        return Application::$app;
    }

    /**
     * Get the current request.
     */
    protected function request(): Request
    {
        return Application::$app->request;
    }

    /**
     * Get the current response.
     */
    protected function response(): Response
    {
        return Application::$app->response;
    }

    /**
     * Get the session, booting it if this is the first access.
     *
     * Resolves through the kernel rather than reading the property directly, so
     * the session is guaranteed to exist even outside a web request.
     */
    protected function session(): SessionInterface
    {
        return Application::$app->getSession();
    }

    /**
     * Get the database connection.
     *
     * @throws \RuntimeException When no database is configured
     */
    protected function db(): Connection
    {
        $connection = Application::$app->db;

        if ($connection === null) {
            throw new \RuntimeException('No database connection configured for this application.');
        }

        return $connection;
    }

    /**
     * Get the router.
     */
    protected function router(): Router
    {
        return Application::$app->router;
    }

    /**
     * Get the authenticated user, or null for a guest.
     */
    protected function user(): ?DbEntity
    {
        return Application::$app->user;
    }

    /**
     * Check whether the current visitor is unauthenticated.
     */
    protected function isGuest(): bool
    {
        return Application::isGuest();
    }
}
