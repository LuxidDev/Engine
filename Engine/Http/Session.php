<?php

declare(strict_types=1);

namespace Luxid\Http;

/**
 * Native PHP session driver.
 *
 * Flash messages survive exactly one further request: entries present when the
 * session boots are marked for removal, and anything still marked at shutdown is
 * discarded. Messages written during the current request are therefore readable
 * on the next one.
 *
 * @package Luxid\Http
 */
class Session implements SessionInterface
{
    /**
     * Session key under which flash messages are stored.
     */
    protected const FLASH_KEY = '__luxid_flash';

    /**
     * Whether a PHP session is active for this instance.
     */
    protected bool $started = false;

    /**
     * Whether the process is running outside a web SAPI.
     */
    protected bool $isCli;

    /**
     * Boot the session and age any pending flash messages.
     */
    public function __construct()
    {
        $this->isCli = PHP_SAPI === 'cli' || PHP_SAPI === 'phpdbg';

        if ($this->isCli || headers_sent()) {
            return;
        }

        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        $this->started = session_status() === PHP_SESSION_ACTIVE;

        if ($this->started) {
            $this->ageFlashMessages();
        }
    }

    /**
     * Mark every flash message carried over from the previous request for removal.
     */
    protected function ageFlashMessages(): void
    {
        $flashMessages = $_SESSION[self::FLASH_KEY] ?? [];

        foreach ($flashMessages as $key => $flashMessage) {
            $flashMessages[$key]['remove'] = true;
        }

        $_SESSION[self::FLASH_KEY] = $flashMessages;
    }

    /**
     * Drop every flash message that has already been read once.
     */
    protected function cleanupFlashMessages(): void
    {
        $flashMessages = $_SESSION[self::FLASH_KEY] ?? [];

        foreach ($flashMessages as $key => $flashMessage) {
            if ($flashMessage['remove'] ?? false) {
                unset($flashMessages[$key]);
            }
        }

        $_SESSION[self::FLASH_KEY] = $flashMessages;
    }

    /**
     * Write a flash message readable on the next request.
     *
     * @param string $key     Flash key
     * @param mixed  $message Flash payload
     */
    public function setFlash($key, $message): void
    {
        if (!$this->started) {
            return;
        }

        $_SESSION[self::FLASH_KEY][$key] = [
            'remove' => false,
            'value' => $message,
        ];
    }

    /**
     * Read a flash message.
     *
     * @param string $key Flash key
     *
     * @return mixed The stored value, or false when absent
     */
    public function getFlash($key): mixed
    {
        if (!$this->started) {
            return false;
        }

        return $_SESSION[self::FLASH_KEY][$key]['value'] ?? false;
    }

    /**
     * Check whether a flash message exists.
     *
     * @param string $key Flash key
     */
    public function hasFlash(string $key): bool
    {
        return $this->started && isset($_SESSION[self::FLASH_KEY][$key]);
    }

    /**
     * Store a value in the session.
     *
     * @param string $key   Session key
     * @param mixed  $value Value to store
     */
    public function set($key, $value): void
    {
        if ($this->started) {
            $_SESSION[$key] = $value;
        }
    }

    /**
     * Read a value from the session.
     *
     * @param string $key Session key
     */
    public function get($key): mixed
    {
        return $this->started ? ($_SESSION[$key] ?? null) : null;
    }

    /**
     * Remove a value from the session.
     *
     * @param string $key Session key
     */
    public function remove($key): void
    {
        if ($this->started) {
            unset($_SESSION[$key]);
        }
    }

    /**
     * Check whether the session holds the given key.
     *
     * @param string $key Session key
     */
    public function has(string $key): bool
    {
        return $this->started && isset($_SESSION[$key]);
    }

    /**
     * Issue a fresh session id while preserving the session payload.
     *
     * Call this whenever the privilege level of the session changes, most
     * importantly on login, to defeat session fixation.
     */
    public function regenerate(bool $deleteOldSession = true): bool
    {
        if (!$this->started || headers_sent()) {
            return false;
        }

        return session_regenerate_id($deleteOldSession);
    }

    /**
     * Clear every value in the session.
     */
    public function clear(): void
    {
        if ($this->started) {
            $_SESSION = [];
        }
    }

    /**
     * Check whether the session is active.
     */
    public function isStarted(): bool
    {
        return $this->started;
    }

    /**
     * Expire stale flash messages as the request winds down.
     */
    public function __destruct()
    {
        if ($this->started && session_status() === PHP_SESSION_ACTIVE) {
            $this->cleanupFlashMessages();
        }
    }
}
