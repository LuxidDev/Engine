<?php

declare(strict_types=1);

namespace Luxid\Nodes;

use Luxid\Foundation\Application;
use Luxid\Http\Response as HttpResponse;

/**
 * Static facade over the request-scoped {@see HttpResponse}.
 *
 * Lets actions write `Response::success($data)` without threading the response
 * object through every call.
 *
 * @package Luxid\Nodes
 */
class Response
{
    /**
     * Resolve the response bound to the current request.
     *
     * @throws \RuntimeException When the application has not booted yet
     */
    protected static function instance(): HttpResponse
    {
        if (!isset(Application::$app)) {
            throw new \RuntimeException('No response instance available; the application has not booted.');
        }

        return Application::$app->response;
    }

    /**
     * Encode a value as a JSON response body.
     *
     * @param mixed $data       Value to encode
     * @param int   $statusCode HTTP status code
     */
    public static function json(mixed $data, int $statusCode = 200): string
    {
        return self::instance()->json($data, $statusCode);
    }

    /**
     * Build a successful JSON envelope.
     *
     * @param mixed  $data       Payload
     * @param string $message    Human readable message
     * @param int    $statusCode HTTP status code
     */
    public static function success(mixed $data = null, string $message = 'Success', int $statusCode = 200): string
    {
        return self::instance()->success($data, $message, $statusCode);
    }

    /**
     * Build an error JSON envelope.
     *
     * @param string $message    Human readable message
     * @param mixed  $errors     Validation errors or additional detail
     * @param int    $statusCode HTTP status code
     */
    public static function error(string $message = 'Error', mixed $errors = null, int $statusCode = 400): string
    {
        return self::instance()->error($message, $errors, $statusCode);
    }

    /**
     * Queue a redirect.
     *
     * @param string $url    Target URL
     * @param int    $status Redirect status code
     */
    public static function warp(string $url, int $status = 302): string
    {
        self::instance()->warp($url, $status);

        return '';
    }

    /**
     * Redirect while flashing a message into the session.
     *
     * @param string $url     Target URL
     * @param string $key     Flash key
     * @param string $message Flash message
     */
    public static function redirectWith(string $url, string $key, string $message): string
    {
        return self::instance()->redirectWith($url, $key, $message);
    }

    /**
     * Set the HTTP status code for the pending response.
     *
     * @param int $code Status code
     */
    public static function status(int $code): void
    {
        self::instance()->setStatusCode($code);
    }

    /**
     * Set a header on the pending response.
     *
     * @param string $name  Header name
     * @param string $value Header value
     */
    public static function header(string $name, string $value): void
    {
        self::instance()->setHeader($name, $value);
    }
}
