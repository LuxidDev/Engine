<?php

declare(strict_types=1);

namespace Luxid\Tests\Middleware;

use Luxid\Middleware\CorsMiddleware;
use Luxid\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * Tests for CORS header emission.
 *
 * @package Luxid\Tests\Middleware
 */
final class CorsMiddlewareTest extends TestCase
{
    #[Test]
    public function it_sends_a_wildcard_origin_by_default(): void
    {
        (new CorsMiddleware())->execute();

        $this->assertSame('*', $this->app->response->getHeader('Access-Control-Allow-Origin'));
    }

    #[Test]
    public function it_never_pairs_a_wildcard_origin_with_credentials(): void
    {
        // Regression: the middleware always sent both, a combination browsers
        // reject outright, so credentialed cross-origin calls silently failed.
        (new CorsMiddleware(['allowed_origins' => ['*'], 'supports_credentials' => true]))->execute();

        $origin = $this->app->response->getHeader('Access-Control-Allow-Origin');
        $credentials = $this->app->response->getHeader('Access-Control-Allow-Credentials');

        $this->assertFalse($origin === '*' && $credentials === 'true');
    }

    #[Test]
    public function it_echoes_an_allowlisted_origin(): void
    {
        $this->app->request->setHeader('Origin', 'https://app.example.com');

        (new CorsMiddleware([
            'allowed_origins' => ['https://app.example.com'],
            'supports_credentials' => true,
        ]))->execute();

        $this->assertSame('https://app.example.com', $this->app->response->getHeader('Access-Control-Allow-Origin'));
        $this->assertSame('true', $this->app->response->getHeader('Access-Control-Allow-Credentials'));
        $this->assertSame('Origin', $this->app->response->getHeader('Vary'));
    }

    #[Test]
    public function it_sends_nothing_for_an_origin_that_is_not_allowlisted(): void
    {
        $this->app->request->setHeader('Origin', 'https://evil.example.com');

        (new CorsMiddleware(['allowed_origins' => ['https://app.example.com']]))->execute();

        $this->assertNull($this->app->response->getHeader('Access-Control-Allow-Origin'));
    }

    #[Test]
    public function it_answers_a_preflight_with_no_content(): void
    {
        $this->app->request->setMethod('options');

        (new CorsMiddleware())->execute();

        $this->assertSame(204, $this->app->response->getStatusCode());
        $this->assertSame('86400', $this->app->response->getHeader('Access-Control-Max-Age'));
    }

    #[Test]
    public function it_advertises_the_configured_methods_and_headers(): void
    {
        (new CorsMiddleware([
            'allowed_methods' => ['GET', 'POST'],
            'allowed_headers' => ['Content-Type'],
        ]))->execute();

        $this->assertSame('GET, POST', $this->app->response->getHeader('Access-Control-Allow-Methods'));
        $this->assertSame('Content-Type', $this->app->response->getHeader('Access-Control-Allow-Headers'));
    }
}
