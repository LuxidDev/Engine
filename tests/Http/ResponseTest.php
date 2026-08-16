<?php

declare(strict_types=1);

namespace Luxid\Tests\Http;

use Luxid\Http\Response;
use Luxid\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * Behavioural tests for the response object.
 *
 * @package Luxid\Tests\Http
 */
final class ResponseTest extends TestCase
{
    #[Test]
    public function it_defaults_to_a_two_hundred_status(): void
    {
        $this->assertSame(200, (new Response())->getStatusCode());
    }

    #[Test]
    public function it_stores_and_replaces_headers(): void
    {
        $response = new Response();
        $response->setHeader('X-Test', 'one');
        $response->setHeader('X-Test', 'two');

        $this->assertSame('two', $response->getHeader('X-Test'));
        $this->assertSame(['X-Test' => 'two'], $response->getHeaders());
    }

    #[Test]
    public function it_removes_a_header(): void
    {
        $response = new Response();
        $response->setHeader('X-Test', 'one')->removeHeader('X-Test');

        $this->assertNull($response->getHeader('X-Test'));
    }

    #[Test]
    public function it_sets_the_json_content_type_and_status(): void
    {
        $response = new Response();
        $body = $response->json(['a' => 1], 201);

        $this->assertSame(201, $response->getStatusCode());
        $this->assertSame('application/json', $response->getHeader('Content-Type'));
        $this->assertSame(['a' => 1], json_decode($body, true));
    }

    #[Test]
    public function it_wraps_a_successful_payload(): void
    {
        $decoded = json_decode((new Response())->success(['id' => 1], 'Created', 201), true);

        $this->assertTrue($decoded['success']);
        $this->assertSame('Created', $decoded['message']);
        $this->assertSame(['id' => 1], $decoded['data']);
    }

    #[Test]
    public function it_wraps_an_error_payload(): void
    {
        $response = new Response();
        $decoded = json_decode($response->error('Nope', ['field' => 'bad'], 422), true);

        $this->assertFalse($decoded['success']);
        $this->assertSame(422, $response->getStatusCode());
        $this->assertSame(['field' => 'bad'], $decoded['errors']);
    }

    #[Test]
    public function it_queues_a_redirect(): void
    {
        $response = new Response();
        $response->warp('/login');

        $this->assertSame('/login', $response->getHeader('Location'));
        $this->assertSame(302, $response->getStatusCode());
    }

    #[Test]
    public function it_queues_a_permanent_redirect(): void
    {
        $response = new Response();
        $response->warp('/new', 301);

        $this->assertSame(301, $response->getStatusCode());
    }

    #[Test]
    public function it_writes_the_body_when_sent(): void
    {
        $response = new Response();

        ob_start();
        $response->send('hello');
        $output = ob_get_clean();

        $this->assertSame('hello', $output);
        $this->assertTrue($response->isSent());
    }

    #[Test]
    public function it_only_sends_once(): void
    {
        $response = new Response();

        ob_start();
        $response->send('first');
        $response->send('second');
        $output = ob_get_clean();

        $this->assertSame('first', $output);
    }

    #[Test]
    public function it_flashes_a_message_before_redirecting(): void
    {
        $this->app->response->redirectWith('/todos', 'status', 'Saved');

        $this->assertSame('/todos', $this->app->response->getHeader('Location'));
        $this->assertSame(302, $this->app->response->getStatusCode());
    }
}
