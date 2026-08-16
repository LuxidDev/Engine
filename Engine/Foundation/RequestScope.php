<?php

declare(strict_types=1);

namespace Luxid\Foundation;

use Throwable;

/**
 * Registry of state that must be cleared between requests.
 *
 * Under PHP-FPM every request gets a fresh process, so static state is
 * discarded for free and nothing here matters. Under a worker runtime the
 * process is reused, and any static holding request-scoped data leaks into the
 * next visitor's request — which is a correctness bug, not just a memory one.
 *
 * Packages register a reset callback rather than the engine reaching into them,
 * so `luxid/engine` keeps no knowledge of Nova, Haven or anything else that is
 * merely installed alongside it.
 *
 * ```php
 * RequestScope::onReset(static fn () => Slot::reset(), 'nova.slots');
 * ```
 *
 * @package Luxid\Foundation
 */
final class RequestScope
{
    /**
     * Reset callbacks keyed by name, so re-registering replaces rather than
     * stacking when a provider boots more than once.
     *
     * @var array<string, callable(): void>
     */
    private static array $resetters = [];

    /**
     * How many requests this worker has served.
     */
    private static int $requests = 0;

    /**
     * Register a callback that clears request-scoped state.
     *
     * @param callable(): void $callback Clears one package's per-request state
     * @param string|null      $name     Identity, so the same reset is not registered twice
     */
    public static function onReset(callable $callback, ?string $name = null): void
    {
        self::$resetters[$name ?? self::identify($callback)] = $callback;
    }

    /**
     * Remove a previously registered callback.
     *
     * @param string $name Name the callback was registered under
     */
    public static function forget(string $name): void
    {
        unset(self::$resetters[$name]);
    }

    /**
     * Run every registered reset callback.
     *
     * A failing resetter must not take the worker down with it: the request it
     * was cleaning up is already over, and refusing to serve the next one turns
     * a leak into an outage. Failures are logged and the rest still run.
     */
    public static function reset(): void
    {
        ++self::$requests;

        foreach (self::$resetters as $name => $callback) {
            try {
                $callback();
            } catch (Throwable $e) {
                error_log(sprintf('[Luxid] Request scope reset "%s" failed: %s', $name, $e->getMessage()));
            }
        }
    }

    /**
     * Get the number of requests served since this worker booted.
     */
    public static function requestCount(): int
    {
        return self::$requests;
    }

    /**
     * Get the names of every registered resetter.
     *
     * @return list<string>
     */
    public static function registered(): array
    {
        return array_keys(self::$resetters);
    }

    /**
     * Drop every registration, so tests can start from a clean registry.
     */
    public static function flush(): void
    {
        self::$resetters = [];
        self::$requests = 0;
    }

    /**
     * Derive a stable name for an unnamed callback.
     *
     * @param callable $callback The callback to identify
     */
    private static function identify(callable $callback): string
    {
        if (is_string($callback)) {
            return $callback;
        }

        if (is_array($callback)) {
            return (is_object($callback[0]) ? $callback[0]::class : (string) $callback[0]) . '::' . $callback[1];
        }

        return 'closure#' . spl_object_id((object) $callback);
    }
}
