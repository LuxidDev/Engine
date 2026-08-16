<?php

declare(strict_types=1);

namespace Luxid\Middleware;

use Luxid\Foundation\Application;

/**
 * Emits CORS headers for API requests.
 *
 * `Access-Control-Allow-Origin: *` and `Access-Control-Allow-Credentials: true`
 * are mutually exclusive per the Fetch specification: browsers reject the pair.
 * Credentials are therefore only advertised once an explicit origin allowlist is
 * configured, and the echoed origin is always one the application named.
 *
 * @package Luxid\Middleware
 */
class CorsMiddleware extends BaseMiddleware
{
    /**
     * Origins permitted to call the API, or `['*']` for any origin.
     *
     * @var list<string>
     */
    protected array $allowedOrigins;

    /**
     * HTTP methods advertised on preflight.
     *
     * @var list<string>
     */
    protected array $allowedMethods;

    /**
     * Request headers advertised on preflight.
     *
     * @var list<string>
     */
    protected array $allowedHeaders;

    /**
     * Whether credentialed requests are permitted.
     */
    protected bool $supportsCredentials;

    /**
     * How long a preflight result may be cached, in seconds.
     */
    protected int $maxAge;

    /**
     * @param array{
     *     allowed_origins?: list<string>,
     *     allowed_methods?: list<string>,
     *     allowed_headers?: list<string>,
     *     supports_credentials?: bool,
     *     max_age?: int
     * } $config CORS configuration
     */
    public function __construct(array $config = [])
    {
        $this->allowedOrigins = $config['allowed_origins'] ?? ['*'];
        $this->allowedMethods = $config['allowed_methods'] ?? ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'];
        $this->allowedHeaders = $config['allowed_headers'] ?? ['Content-Type', 'Authorization', 'X-Requested-With'];
        $this->supportsCredentials = $config['supports_credentials'] ?? false;
        $this->maxAge = $config['max_age'] ?? 86400;
    }

    /**
     * Apply the CORS headers to the pending response.
     */
    public function execute(): void
    {
        $request = Application::$app->request;
        $response = Application::$app->response;

        $origin = $this->resolveOrigin($request->header('origin'));

        if ($origin === null) {
            return;
        }

        $response->setHeader('Access-Control-Allow-Origin', $origin);
        $response->setHeader('Access-Control-Allow-Methods', implode(', ', $this->allowedMethods));
        $response->setHeader('Access-Control-Allow-Headers', implode(', ', $this->allowedHeaders));

        // Caches must key on Origin whenever the header varies by requester.
        if ($origin !== '*') {
            $response->setHeader('Vary', 'Origin');
        }

        // Credentials are incompatible with a wildcard origin, so only advertise
        // them once the application has named the origins it trusts.
        if ($this->supportsCredentials && $origin !== '*') {
            $response->setHeader('Access-Control-Allow-Credentials', 'true');
        }

        if ($request->isMethod('options')) {
            $response->setHeader('Access-Control-Max-Age', (string) $this->maxAge);
            $response->setStatusCode(204);
        }
    }

    /**
     * Decide which origin to echo back, if any.
     *
     * @param string|null $origin The request's Origin header
     *
     * @return string|null The origin to advertise, or null to send no CORS headers
     */
    protected function resolveOrigin(?string $origin): ?string
    {
        if (in_array('*', $this->allowedOrigins, true)) {
            return $this->supportsCredentials && $origin !== null ? $origin : '*';
        }

        if ($origin !== null && in_array($origin, $this->allowedOrigins, true)) {
            return $origin;
        }

        return null;
    }
}
