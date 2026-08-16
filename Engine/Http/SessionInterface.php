<?php

declare(strict_types=1);

namespace Luxid\Http;

/**
 * Contract every session driver must satisfy.
 *
 * Implementations are expected to degrade gracefully rather than throw when no
 * session is available, so console commands can share code with web requests.
 *
 * @package Luxid\Http
 */
interface SessionInterface
{
    /**
     * Read a value from the session.
     *
     * @param string $key Session key
     */
    public function get($key): mixed;

    /**
     * Store a value in the session.
     *
     * @param string $key   Session key
     * @param mixed  $value Value to store
     */
    public function set($key, $value): void;

    /**
     * Remove a value from the session.
     *
     * @param string $key Session key
     */
    public function remove($key): void;

    /**
     * Check whether the session holds the given key.
     *
     * @param string $key Session key
     */
    public function has(string $key): bool;

    /**
     * Write a flash message readable on the next request.
     *
     * @param string $key     Flash key
     * @param mixed  $message Flash payload
     */
    public function setFlash($key, $message): void;

    /**
     * Read a flash message.
     *
     * @param string $key Flash key
     *
     * @return mixed The stored value, or false when absent
     */
    public function getFlash($key): mixed;

    /**
     * Check whether a flash message exists.
     *
     * @param string $key Flash key
     */
    public function hasFlash(string $key): bool;

    /**
     * Issue a fresh session id while preserving the session payload.
     *
     * @param bool $deleteOldSession Whether to destroy the previous session file
     *
     * @return bool True when the id was rotated
     */
    public function regenerate(bool $deleteOldSession = true): bool;

    /**
     * Clear every value in the session.
     */
    public function clear(): void;

    /**
     * Check whether the session is active.
     */
    public function isStarted(): bool;
}
