<?php

declare(strict_types=1);

namespace Luxid\Http;

/**
 * HTTP request representation.
 *
 * Reads from the PHP superglobals by default, but every source can be overridden
 * so the same object works under PHP-FPM, FrankenPHP and in tests.
 *
 * @package Luxid\Http
 */
class Request
{
    /**
     * HTTP methods this framework routes on.
     *
     * @var list<string>
     */
    private const SUPPORTED_METHODS = ['get', 'post', 'put', 'patch', 'delete', 'head', 'options'];

    /**
     * Parsed request body, cached to avoid re-reading php://input.
     *
     * @var array<string, mixed>|null
     */
    private ?array $cachedBody = null;

    /**
     * Parsed query string parameters.
     *
     * @var array<string, mixed>|null
     */
    private ?array $cachedQuery = null;

    /**
     * Explicit path override, used by non-FPM adapters.
     */
    protected string $customPath = '';

    /**
     * Explicit method override, used by non-FPM adapters.
     */
    protected string $customMethod = '';

    /**
     * Raw body override, used by non-FPM adapters.
     */
    protected string $customBody = '';

    /**
     * Header overrides keyed by lowercased header name.
     *
     * @var array<string, string>
     */
    protected array $customHeaders = [];

    /**
     * Override the request path.
     *
     * @param string $path Path without query string
     */
    public function setPath(string $path): void
    {
        $this->customPath = $path;
    }

    /**
     * Override the request method.
     *
     * @param string $method HTTP method, case insensitive
     */
    public function setMethod(string $method): void
    {
        $this->customMethod = strtolower($method);
    }

    /**
     * Override the raw request body.
     *
     * JSON bodies are decoded eagerly so {@see Request::getBody()} can serve them
     * without touching php://input.
     *
     * @param string $body Raw request body
     */
    public function setBody(string $body): void
    {
        $this->customBody = $body;

        $decoded = json_decode($body, true);
        $this->cachedBody = is_array($decoded) ? $decoded : null;
    }

    /**
     * Override a request header.
     *
     * @param string $name  Header name, case insensitive
     * @param string $value Header value
     */
    public function setHeader(string $name, string $value): void
    {
        $this->customHeaders[strtolower($name)] = $value;
    }

    /**
     * Get a request header.
     *
     * @param string $name    Header name, case insensitive
     * @param string|null $default Value returned when the header is absent
     */
    public function header(string $name, ?string $default = null): ?string
    {
        $name = strtolower($name);

        if (isset($this->customHeaders[$name])) {
            return $this->customHeaders[$name];
        }

        $serverKey = 'HTTP_' . strtoupper(str_replace('-', '_', $name));

        return $_SERVER[$serverKey] ?? $default;
    }

    /**
     * Get the request path, stripped of any query string.
     */
    public function getPath(): string
    {
        if ($this->customPath !== '') {
            return $this->customPath;
        }

        $path = $_SERVER['REQUEST_URI'] ?? '/';
        $position = strpos($path, '?');

        return $position === false ? $path : substr($path, 0, $position);
    }

    /**
     * Get the lowercased HTTP method.
     *
     * Honours the `_method` form field and the `X-HTTP-Method-Override` header so
     * HTML forms can issue PUT/PATCH/DELETE. Overrides are only accepted on POST
     * and only when they name a method the router understands.
     */
    public function method(): string
    {
        if ($this->customMethod !== '') {
            return $this->customMethod;
        }

        $method = strtolower($_SERVER['REQUEST_METHOD'] ?? 'get');

        if ($method !== 'post') {
            return $method;
        }

        $override = $_POST['_method'] ?? $_SERVER['HTTP_X_HTTP_METHOD_OVERRIDE'] ?? null;

        if (is_string($override)) {
            $override = strtolower($override);

            if (in_array($override, self::SUPPORTED_METHODS, true)) {
                return $override;
            }
        }

        return $method;
    }

    /**
     * Get all request data, sanitized and cached.
     *
     * GET requests read the query string; every other method reads the decoded
     * JSON body, the POST fields, or the raw url-encoded body in that order.
     *
     * @return array<string, mixed>
     */
    public function getBody(): array
    {
        if ($this->cachedBody !== null) {
            return $this->cachedBody;
        }

        $this->cachedBody = $this->method() === 'get'
            ? $this->sanitize($_GET)
            : $this->parseRequestBody();

        return $this->cachedBody;
    }

    /**
     * Parse the body of a non-GET request.
     *
     * @return array<string, mixed>
     */
    private function parseRequestBody(): array
    {
        $rawInput = $this->customBody !== '' ? $this->customBody : (string) file_get_contents('php://input');

        if ($this->isJson()) {
            $decoded = json_decode($rawInput, true);

            return is_array($decoded) ? $decoded : [];
        }

        if ($_POST !== []) {
            return $this->sanitize($_POST);
        }

        if ($rawInput !== '') {
            parse_str($rawInput, $parsed);

            return $this->sanitize($parsed);
        }

        return [];
    }

    /**
     * Recursively strip HTML control characters from untrusted input.
     *
     * @param array<array-key, mixed> $input
     *
     * @return array<array-key, mixed>
     */
    private function sanitize(array $input): array
    {
        $sanitized = [];

        foreach ($input as $key => $value) {
            if (is_string($value)) {
                // FILTER_SANITIZE_FULL_SPECIAL_CHARS is htmlspecialchars with
                // ENT_QUOTES; calling it directly skips the filter dispatch and
                // pins the charset instead of inheriting default_charset.
                // ENT_SUBSTITUTE keeps malformed UTF-8 from blanking the value.
                $sanitized[$key] = htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                continue;
            }

            $sanitized[$key] = is_array($value) ? $this->sanitize($value) : $value;
        }

        return $sanitized;
    }

    /**
     * Discard the cached body and query, forcing a re-parse.
     */
    public function clearCache(): void
    {
        $this->cachedBody = null;
        $this->cachedQuery = null;
    }

    /**
     * Check whether the request uses the given method.
     *
     * @param string $method HTTP method, case insensitive
     */
    public function isMethod(string $method): bool
    {
        return $this->method() === strtolower($method);
    }

    /**
     * Check whether this is a GET request.
     */
    public function isGet(): bool
    {
        return $this->isMethod('get');
    }

    /**
     * Check whether this is a POST request.
     */
    public function isPost(): bool
    {
        return $this->isMethod('post');
    }

    /**
     * Check whether this is a PUT request.
     */
    public function isPut(): bool
    {
        return $this->isMethod('put');
    }

    /**
     * Check whether this is a PATCH request.
     */
    public function isPatch(): bool
    {
        return $this->isMethod('patch');
    }

    /**
     * Check whether this is a DELETE request.
     */
    public function isDelete(): bool
    {
        return $this->isMethod('delete');
    }

    /**
     * Check whether the request carries a JSON body.
     */
    public function isJson(): bool
    {
        $contentType = $this->header('content-type', $_SERVER['CONTENT_TYPE'] ?? '') ?? '';

        return str_contains($contentType, 'application/json');
    }

    /**
     * Check whether the client asked for a JSON response.
     */
    public function wantsJson(): bool
    {
        $accept = $this->header('accept', $_SERVER['HTTP_ACCEPT'] ?? '') ?? '';

        return $this->isJson() || str_contains($accept, 'application/json');
    }

    /**
     * Check whether the request was issued by a JavaScript client.
     */
    public function isAjax(): bool
    {
        return strtolower($this->header('x-requested-with', '') ?? '') === 'xmlhttprequest';
    }

    /**
     * Decode the raw body as JSON.
     *
     * @return array<string, mixed>|null Null when the body is absent or malformed
     */
    public function getJson(): ?array
    {
        $rawInput = $this->customBody !== '' ? $this->customBody : (string) file_get_contents('php://input');
        $decoded = json_decode($rawInput, true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * Get a single value from the request data.
     *
     * @param string $key     Parameter name
     * @param mixed  $default Value returned when the key is absent
     */
    public function get(string $key, mixed $default = null): mixed
    {
        return $this->getBody()[$key] ?? $default;
    }

    /**
     * Get every request parameter.
     *
     * @return array<string, mixed>
     */
    public function all(): array
    {
        return $this->getBody();
    }

    /**
     * Get a subset of the request data.
     *
     * @param list<string> $keys Parameter names to keep
     *
     * @return array<string, mixed>
     */
    public function only(array $keys): array
    {
        $body = $this->getBody();
        $result = [];

        foreach ($keys as $key) {
            if (array_key_exists($key, $body)) {
                $result[$key] = $body[$key];
            }
        }

        return $result;
    }

    /**
     * Get the request data with the given keys removed.
     *
     * @param list<string> $keys Parameter names to drop
     *
     * @return array<string, mixed>
     */
    public function except(array $keys): array
    {
        return array_diff_key($this->getBody(), array_flip($keys));
    }

    /**
     * Check whether a key is present in the request data.
     *
     * @param string $key Parameter name
     */
    public function has(string $key): bool
    {
        return array_key_exists($key, $this->getBody());
    }

    /**
     * Check whether a key is present and not empty.
     *
     * @param string $key Parameter name
     */
    public function filled(string $key): bool
    {
        $value = $this->get($key);

        return $value !== null && $value !== '' && $value !== [];
    }

    /**
     * Get one or all query string parameters.
     *
     * @param string|null $key     Parameter name, or null for the whole array
     * @param mixed       $default Value returned when the key is absent
     *
     * @return mixed The value, or array<string, mixed> when $key is null
     */
    public function query(?string $key = null, mixed $default = null): mixed
    {
        $this->cachedQuery ??= $this->sanitize($_GET);

        if ($key === null) {
            return $this->cachedQuery;
        }

        return $this->cachedQuery[$key] ?? $default;
    }

    /**
     * Get one or all body parameters, excluding the query string.
     *
     * @param string|null $key     Parameter name, or null for the whole array
     * @param mixed       $default Value returned when the key is absent
     *
     * @return mixed The value, or array<string, mixed> when $key is null
     */
    public function input(?string $key = null, mixed $default = null): mixed
    {
        if ($this->isGet()) {
            return $key === null ? [] : $default;
        }

        $body = $this->getBody();

        if ($key === null) {
            return $body;
        }

        return $body[$key] ?? $default;
    }
}
