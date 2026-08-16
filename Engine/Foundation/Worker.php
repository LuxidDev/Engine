<?php

declare(strict_types=1);

namespace Luxid\Foundation;

use Throwable;

/**
 * Long-lived request handler for worker runtimes.
 *
 * Boots the application and the route table once, then serves many requests
 * from the same process. That removes the per-request cost of autoloading,
 * compiling, provider discovery, route registration and connecting to the
 * database — which is most of what a request actually spends its time on.
 *
 * The runtime-specific part is deliberately thin: this class knows nothing
 * about FrankenPHP, and is driven by calling {@see Worker::handle()} in a loop.
 * That keeps the whole lifecycle testable by simulating requests, rather than
 * only being verifiable against a running server.
 *
 * @package Luxid\Foundation
 */
class Worker
{
    /**
     * The application, booted once and reused.
     */
    protected Application $app;

    /**
     * Requests served since boot.
     */
    protected int $handled = 0;

    /**
     * Requests to serve before asking to be recycled.
     */
    protected int $maxRequests;

    /**
     * Bytes of memory beyond which the worker asks to be recycled.
     */
    protected int $memoryLimit;

    /**
     * Requests between garbage collection runs.
     */
    protected int $collectEvery;

    /**
     * @param Application          $app     The booted application
     * @param array{
     *     max_requests?: int,
     *     memory_limit?: int,
     *     collect_every?: int
     * } $options Recycling thresholds
     */
    public function __construct(Application $app, array $options = [])
    {
        $this->app = $app;
        $this->maxRequests = $options['max_requests'] ?? 1000;
        $this->memoryLimit = $options['memory_limit'] ?? 128 * 1024 * 1024;
        $this->collectEvery = max(1, $options['collect_every'] ?? 100);
    }

    /**
     * Boot an application and its routes for worker use.
     *
     * @param string               $rootPath Absolute path to the project root
     * @param array<string, mixed> $config   Application configuration
     * @param array<string, mixed> $options  Recycling thresholds
     */
    public static function boot(string $rootPath, array $config, array $options = []): self
    {
        $app = new Application($rootPath, $config);

        foreach (['/routes/api.php', '/routes/web.php'] as $routeFile) {
            if (is_file($rootPath . $routeFile)) {
                require_once $rootPath . $routeFile;
            }
        }

        return new self($app, $options);
    }

    /**
     * Serve one request and return its body.
     *
     * State is cleared before the request rather than after, so a request that
     * dies without unwinding cannot poison the next one — the cleanup happens
     * on the way in, where it is guaranteed to run.
     */
    public function handle(): string
    {
        $this->app->prepareForNextRequest();

        ++$this->handled;

        try {
            return $this->app->handle();
        } catch (Throwable $e) {
            // handle() already converts exceptions into responses; reaching here
            // means the error path itself failed. Report a 500 and stay alive.
            error_log('[Luxid] Worker request failed: ' . $e->getMessage());
            $this->app->response->setStatusCode(500);

            return '';
        } finally {
            $this->collectIfDue();
        }
    }

    /**
     * Serve one request and flush it to the client.
     */
    public function serve(): void
    {
        $body = $this->handle();
        $this->app->response->send($body);
    }

    /**
     * Check whether the worker should be recycled.
     *
     * A long-lived PHP process will eventually accumulate fragmentation and
     * whatever state the application forgot to clear, so workers are retired on
     * a schedule rather than trusted to run forever.
     */
    public function shouldRecycle(): bool
    {
        return $this->handled >= $this->maxRequests
            || memory_get_usage(true) >= $this->memoryLimit;
    }

    /**
     * Run cycle collection when enough requests have passed.
     *
     * PHP's collector is triggered by allocation pressure, which a worker may
     * not reach for a long time while still holding cycles from every request
     * it has served.
     */
    protected function collectIfDue(): void
    {
        if ($this->handled % $this->collectEvery === 0) {
            gc_collect_cycles();
        }
    }

    /**
     * Get the application this worker serves.
     */
    public function app(): Application
    {
        return $this->app;
    }

    /**
     * Get the number of requests served since boot.
     */
    public function handled(): int
    {
        return $this->handled;
    }

    /**
     * Get a snapshot of the worker's health, for a status endpoint or log line.
     *
     * @return array{handled: int, memory: int, peak: int, resetters: list<string>, open_buffers: int}
     */
    public function stats(): array
    {
        return [
            'handled' => $this->handled,
            'memory' => memory_get_usage(true),
            'peak' => memory_get_peak_usage(true),
            'resetters' => RequestScope::registered(),
            'open_buffers' => ob_get_level(),
        ];
    }
}
