<?php

declare(strict_types=1);

namespace Luxid\Http;

use Luxid\Foundation\Application;

/**
 * HTTP response representation.
 *
 * Collects the status code, headers and body for the current request. Nothing is
 * written to the client until {@see Response::send()} is called by the kernel,
 * which keeps actions free to return values instead of echoing them.
 *
 * @package Luxid\Http
 */
class Response
{
    /**
     * HTTP status code to send.
     */
    protected int $statusCode = 200;

    /**
     * Response headers keyed by header name.
     *
     * @var array<string, string>
     */
    protected array $headers = [];

    /**
     * Whether the response has already been flushed to the client.
     */
    protected bool $sent = false;

    /**
     * Whether JSON bodies are formatted for humans.
     *
     * Off by default. Pretty printing an API response costs about a quarter
     * more CPU and roughly triples the bytes on the wire, which every client
     * then pays to download — for whitespace no program reads. The kernel turns
     * it on when the application is in debug mode.
     */
    protected static bool $prettyPrint = false;

    /**
     * Encoding flags applied to every JSON body.
     */
    private const JSON_FLAGS = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR;

    /**
     * Set the HTTP status code.
     *
     * @param int $code Status code between 100 and 599
     */
    public function setStatusCode(int $code): self
    {
        $this->statusCode = $code;

        return $this;
    }

    /**
     * Get the HTTP status code.
     */
    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    /**
     * Set a response header, replacing any header of the same name.
     *
     * @param string $name  Header name
     * @param string $value Header value
     */
    public function setHeader(string $name, string $value): self
    {
        $this->headers[$name] = $value;

        return $this;
    }

    /**
     * Get a single header value.
     *
     * @param string $name Header name
     */
    public function getHeader(string $name): ?string
    {
        return $this->headers[$name] ?? null;
    }

    /**
     * Get all response headers.
     *
     * @return array<string, string>
     */
    public function getHeaders(): array
    {
        return $this->headers;
    }

    /**
     * Remove a previously set header.
     *
     * @param string $name Header name
     */
    public function removeHeader(string $name): self
    {
        unset($this->headers[$name]);

        return $this;
    }

    /**
     * Check whether the response has already been flushed.
     */
    public function isSent(): bool
    {
        return $this->sent;
    }

    /**
     * Flush the status line, headers and body to the client.
     *
     * Safe to call more than once; subsequent calls are ignored. Headers are only
     * emitted when PHP has not already committed them (e.g. after stray output).
     *
     * @param string $body Response body to write
     */
    public function send(string $body = ''): void
    {
        if ($this->sent) {
            return;
        }

        $this->sent = true;
        $this->sendHeaders();

        echo $body;
    }

    /**
     * Emit the status code and every queued header.
     */
    public function sendHeaders(): void
    {
        if (headers_sent() || PHP_SAPI === 'cli') {
            return;
        }

        http_response_code($this->statusCode);

        foreach ($this->headers as $name => $value) {
            header($name . ': ' . $value, true);
        }
    }

    /**
     * Queue a redirect to the given URL.
     *
     * @param string $url    Absolute or relative target URL
     * @param int    $status 301, 302, 303, 307 or 308
     */
    public function warp(string $url, int $status = 302): self
    {
        return $this->setHeader('Location', $url)->setStatusCode($status);
    }

    /**
     * Encode a value as a JSON response body.
     *
     * @param mixed $data       Value to encode
     * @param int   $statusCode HTTP status code
     *
     * @return string Encoded JSON, returned so actions can `return $response->json(...)`
     */
    public function json(mixed $data, int $statusCode = 200): string
    {
        $this->setStatusCode($statusCode);
        $this->setHeader('Content-Type', 'application/json');

        $flags = self::$prettyPrint ? self::JSON_FLAGS | JSON_PRETTY_PRINT : self::JSON_FLAGS;

        return (string) json_encode($data, $flags);
    }

    /**
     * Turn human-readable JSON formatting on or off for every response.
     *
     * @param bool $enabled Whether to pretty print
     */
    public static function prettyPrintJson(bool $enabled = true): void
    {
        self::$prettyPrint = $enabled;
    }

    /**
     * Check whether JSON bodies are pretty printed.
     */
    public static function prettyPrintsJson(): bool
    {
        return self::$prettyPrint;
    }

    /**
     * Build a successful JSON envelope.
     *
     * @param mixed  $data       Payload
     * @param string $message    Human readable message
     * @param int    $statusCode HTTP status code
     */
    public function success(mixed $data = null, string $message = 'Success', int $statusCode = 200): string
    {
        return $this->json([
            'success' => true,
            'message' => $message,
            'data' => $data,
        ], $statusCode);
    }

    /**
     * Build an error JSON envelope.
     *
     * @param string $message    Human readable message
     * @param mixed  $errors     Validation errors or additional detail
     * @param int    $statusCode HTTP status code
     */
    public function error(string $message = 'Error', mixed $errors = null, int $statusCode = 400): string
    {
        return $this->json([
            'success' => false,
            'message' => $message,
            'errors' => $errors,
        ], $statusCode);
    }

    /**
     * Redirect while flashing a message into the session.
     *
     * Uses the lazily resolved session so this works before the session has been
     * touched by the current request.
     *
     * @param string $url     Target URL
     * @param string $key     Flash key
     * @param string $message Flash message
     */
    public function redirectWith(string $url, string $key, string $message): string
    {
        if (isset(Application::$app)) {
            Application::$app->getSession()->setFlash($key, $message);
        }

        $this->warp($url);

        return '';
    }
}
