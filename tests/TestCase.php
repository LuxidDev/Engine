<?php

declare(strict_types=1);

namespace Luxid\Tests;

use Luxid\Console\CliApplication;
use Luxid\Foundation\Application;
use PHPUnit\Framework\TestCase as BaseTestCase;

/**
 * Shared base for engine tests.
 *
 * Boots a console kernel per test so `Application::$app` is populated without
 * needing a database, a session or a web SAPI, and resets the superglobals the
 * request object reads from.
 *
 * @package Luxid\Tests
 */
abstract class TestCase extends BaseTestCase
{
    /**
     * The kernel under test.
     *
     * Typed to the base kernel so a test can swap in a web-flavoured one.
     */
    protected Application $app;

    /**
     * Boot a fresh kernel and clear request state.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $_GET = [];
        $_POST = [];
        $_SERVER['REQUEST_URI'] = '/';
        $_SERVER['REQUEST_METHOD'] = 'GET';
        unset($_SERVER['CONTENT_TYPE'], $_SERVER['HTTP_ACCEPT'], $_SERVER['HTTP_X_HTTP_METHOD_OVERRIDE']);

        $this->app = new CliApplication(__DIR__, ['userClass' => '']);
    }

    /**
     * Point the request at a path and method.
     *
     * @param string               $method HTTP method
     * @param string               $path   Request path
     * @param array<string, mixed> $query  Query string parameters
     */
    protected function request(string $method, string $path, array $query = []): void
    {
        $_SERVER['REQUEST_METHOD'] = strtoupper($method);
        $_SERVER['REQUEST_URI'] = $path;
        $_GET = $query;

        $this->app->request->clearCache();
    }
}
