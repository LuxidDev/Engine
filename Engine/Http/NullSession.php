<?php

declare(strict_types=1);

namespace Luxid\Http;

/**
 * No-op session driver.
 *
 * Used under the CLI SAPI and in tests so code that reaches for the session does
 * not have to branch on whether a real session exists.
 *
 * @package Luxid\Http
 */
class NullSession implements SessionInterface
{
    /**
     * {@inheritDoc}
     */
    public function get($key): mixed
    {
        return null;
    }

    /**
     * {@inheritDoc}
     */
    public function set($key, $value): void
    {
    }

    /**
     * {@inheritDoc}
     */
    public function remove($key): void
    {
    }

    /**
     * {@inheritDoc}
     */
    public function has(string $key): bool
    {
        return false;
    }

    /**
     * {@inheritDoc}
     */
    public function setFlash($key, $message): void
    {
    }

    /**
     * {@inheritDoc}
     */
    public function getFlash($key): mixed
    {
        return false;
    }

    /**
     * {@inheritDoc}
     */
    public function hasFlash(string $key): bool
    {
        return false;
    }

    /**
     * {@inheritDoc}
     */
    public function regenerate(bool $deleteOldSession = true): bool
    {
        return false;
    }

    /**
     * {@inheritDoc}
     */
    public function clear(): void
    {
    }

    /**
     * {@inheritDoc}
     */
    public function isStarted(): bool
    {
        return false;
    }
}
