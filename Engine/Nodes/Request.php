<?php

declare(strict_types=1);

namespace Luxid\Nodes;

use Luxid\Foundation\Application;
use Luxid\Http\Request as HttpRequest;

/**
 * Static facade over the request-scoped {@see HttpRequest}.
 *
 * @package Luxid\Nodes
 */
class Request
{
    /**
     * Resolve the current request.
     *
     * @throws \RuntimeException When the application has not booted yet
     */
    protected static function instance(): HttpRequest
    {
        if (!isset(Application::$app)) {
            throw new \RuntimeException('No request instance available; the application has not booted.');
        }

        return Application::$app->request;
    }

    /**
     * Get one or all query string parameters.
     *
     * @param string|null $key     Parameter name, or null for the whole array
     * @param mixed       $default Value returned when the key is absent
     */
    public static function query(?string $key = null, mixed $default = null): mixed
    {
        return self::instance()->query($key, $default);
    }

    /**
     * Get one or all body parameters.
     *
     * @param string|null $key     Parameter name, or null for the whole array
     * @param mixed       $default Value returned when the key is absent
     */
    public static function input(?string $key = null, mixed $default = null): mixed
    {
        return self::instance()->input($key, $default);
    }

    /**
     * Get a single value from the request data.
     *
     * @param string $key     Parameter name
     * @param mixed  $default Value returned when the key is absent
     */
    public static function get(string $key, mixed $default = null): mixed
    {
        return self::instance()->get($key, $default);
    }

    /**
     * Get every request parameter.
     *
     * @return array<string, mixed>
     */
    public static function all(): array
    {
        return self::instance()->all();
    }

    /**
     * Get a subset of the request data.
     *
     * @param list<string> $keys Parameter names to keep
     *
     * @return array<string, mixed>
     */
    public static function only(array $keys): array
    {
        return self::instance()->only($keys);
    }

    /**
     * Check whether a key is present in the request data.
     *
     * @param string $key Parameter name
     */
    public static function has(string $key): bool
    {
        return self::instance()->has($key);
    }

    /**
     * Get the request path.
     */
    public static function path(): string
    {
        return self::instance()->getPath();
    }

    /**
     * Get the lowercased HTTP method.
     */
    public static function method(): string
    {
        return self::instance()->method();
    }

    /**
     * Check whether this is a GET request.
     */
    public static function isGet(): bool
    {
        return self::instance()->isGet();
    }

    /**
     * Check whether this is a POST request.
     */
    public static function isPost(): bool
    {
        return self::instance()->isPost();
    }

    /**
     * Check whether the request carries a JSON body.
     */
    public static function isJson(): bool
    {
        return self::instance()->isJson();
    }

    /**
     * Check whether the request was issued by a JavaScript client.
     */
    public static function isAjax(): bool
    {
        return self::instance()->isAjax();
    }
}
