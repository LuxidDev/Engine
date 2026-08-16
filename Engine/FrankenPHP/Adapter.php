<?php

declare(strict_types=1);

namespace Luxid\FrankenPHP;

use Luxid\Foundation\Worker;

/**
 * FrankenPHP worker-mode entry point.
 *
 * FrankenPHP is the PHP SAPI, not a proxy in front of one: inside the handler
 * the superglobals are repopulated for the current request and output goes out
 * through `echo` and `header()` exactly as under PHP-FPM. Nothing here converts
 * request objects, because there are none to convert.
 *
 * A previous version of this adapter accepted a PSR-7 style request and mapped
 * it onto the superglobals. That is RoadRunner's model, not FrankenPHP's, and
 * the handler signature it expected is never called by the runtime.
 *
 * Usage, as `web/worker.php`:
 *
 * ```php
 * require __DIR__ . '/../vendor/autoload.php';
 *
 * Dotenv\Dotenv::createImmutable(dirname(__DIR__))->safeLoad();
 *
 * \Luxid\FrankenPHP\Adapter::run(
 *     dirname(__DIR__),
 *     require dirname(__DIR__) . '/config/config.php'
 * );
 * ```
 *
 * Then point FrankenPHP at it:
 *
 * ```
 * frankenphp run --config Caddyfile
 * # or
 * FRANKENPHP_CONFIG="worker ./web/worker.php" frankenphp php-server -r web/
 * ```
 *
 * @package Luxid\FrankenPHP
 */
final class Adapter
{
    /**
     * The worker serving requests.
     */
    private Worker $worker;

    /**
     * @param Worker $worker A booted worker
     */
    public function __construct(Worker $worker)
    {
        $this->worker = $worker;
    }

    /**
     * Boot the application and serve requests until the runtime stops.
     *
     * Returns normally when FrankenPHP shuts the worker down, or when the
     * recycling thresholds are reached — the runtime then starts a fresh one.
     *
     * @param string               $rootPath Absolute path to the project root
     * @param array<string, mixed> $config   Application configuration
     * @param array<string, mixed> $options  Worker recycling thresholds
     */
    public static function run(string $rootPath, array $config, array $options = []): void
    {
        (new self(Worker::boot($rootPath, $config, $options)))->loop();
    }

    /**
     * Check whether the process is running under FrankenPHP's worker mode.
     */
    public static function isSupported(): bool
    {
        return function_exists('frankenphp_handle_request');
    }

    /**
     * Serve requests until the runtime stops or the worker should be recycled.
     *
     * @throws \RuntimeException When not running under FrankenPHP worker mode
     */
    public function loop(): void
    {
        if (!self::isSupported()) {
            throw new \RuntimeException(
                'frankenphp_handle_request() is unavailable. '
                    . 'Run this script through FrankenPHP in worker mode, not the PHP CLI.'
            );
        }

        $handler = $this->handler();

        // frankenphp_handle_request() blocks until a request arrives, runs the
        // handler, and returns false once the runtime is shutting down.
        do {
            $keepRunning = \frankenphp_handle_request($handler);
        } while ($keepRunning && !$this->worker->shouldRecycle());
    }

    /**
     * Build the closure FrankenPHP invokes per request.
     *
     * @return callable(): void
     */
    public function handler(): callable
    {
        return function (): void {
            $this->worker->serve();
        };
    }

    /**
     * Get the underlying worker.
     */
    public function worker(): Worker
    {
        return $this->worker;
    }
}
