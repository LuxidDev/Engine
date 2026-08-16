<?php

declare(strict_types=1);

namespace Luxid\Tests\Foundation;

use Luxid\Foundation\Application;
use Luxid\Foundation\RequestScope;
use Luxid\Foundation\Worker;
use Luxid\Tests\Fixtures\AccountAction;
use Luxid\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * Tests for long-lived request handling.
 *
 * These simulate what a worker runtime does — many sequential requests through
 * one booted application — so state leaks are caught here rather than in
 * production, where they surface as one visitor seeing another's data.
 *
 * @package Luxid\Tests\Foundation
 */
final class WorkerTest extends TestCase
{
    /**
     * The worker under test.
     */
    private Worker $worker;

    protected function setUp(): void
    {
        parent::setUp();

        RequestScope::flush();

        // Rebuild the kernel so its reset callback registers against the fresh
        // registry, then wrap it in a worker.
        $this->app = new class (__DIR__, ['userClass' => '']) extends Application {
            /**
             * Skip provider discovery; this test is about request lifecycle.
             */
            protected function discoverProviders(): void
            {
            }
        };

        $this->worker = new Worker($this->app, ['collect_every' => 1000]);
    }

    protected function tearDown(): void
    {
        RequestScope::flush();

        parent::tearDown();
    }

    #[Test]
    public function it_serves_many_requests_from_one_boot(): void
    {
        $this->app->router->get('/ping', fn (): string => 'pong');

        for ($i = 0; $i < 50; $i++) {
            $this->request('GET', '/ping');
            $this->assertSame('pong', $this->worker->handle());
        }

        $this->assertSame(50, $this->worker->handled());
    }

    #[Test]
    public function each_request_gets_a_fresh_response(): void
    {
        $this->app->router->get('/created', function ($request, $response): string {
            $response->setStatusCode(201);

            return 'made';
        });
        $this->app->router->get('/plain', fn (): string => 'ok');

        $this->request('GET', '/created');
        $this->worker->handle();
        $this->assertSame(201, $this->app->response->getStatusCode());

        // A 201 set by one request must not carry into the next.
        $this->request('GET', '/plain');
        $this->worker->handle();
        $this->assertSame(200, $this->app->response->getStatusCode());
    }

    #[Test]
    public function headers_do_not_leak_between_requests(): void
    {
        $this->app->router->get('/download', function ($request, $response): string {
            $response->setHeader('Content-Disposition', 'attachment');

            return 'file';
        });
        $this->app->router->get('/page', fn (): string => 'page');

        $this->request('GET', '/download');
        $this->worker->handle();
        $this->assertSame('attachment', $this->app->response->getHeader('Content-Disposition'));

        $this->request('GET', '/page');
        $this->worker->handle();
        $this->assertNull($this->app->response->getHeader('Content-Disposition'));
    }

    #[Test]
    public function the_authenticated_user_does_not_leak_between_requests(): void
    {
        // The leak that matters most: one visitor's identity reaching the next.
        // The first request signs a user in the way session hydration would.
        $this->app->router->get('/login', function (): string {
            Application::$app->user = new \stdClass();

            return 'signed-in';
        });

        $this->app->router->get('/whoami', fn (): string => Application::$app->user === null ? 'guest' : 'user');

        $this->request('GET', '/login');
        $this->assertSame('signed-in', $this->worker->handle());
        $this->assertNotNull($this->app->user);

        $this->request('GET', '/whoami');
        $this->assertSame('guest', $this->worker->handle());
    }

    #[Test]
    public function the_resolved_action_does_not_leak_between_requests(): void
    {
        $this->app->router->get('/account', [AccountAction::class, 'dashboard']);
        $this->app->router->get('/closure', fn (): string => Application::$app->action === null ? 'none' : 'stale');

        $this->request('GET', '/account');
        $this->worker->handle();
        $this->assertNotNull($this->app->action);

        $this->request('GET', '/closure');
        $this->assertSame('none', $this->worker->handle());
    }

    #[Test]
    public function the_request_is_reparsed_for_each_call(): void
    {
        $this->app->router->get('/search', fn (): string => (string) Application::$app->request->query('q'));

        $this->request('GET', '/search', ['q' => 'first']);
        $this->assertSame('first', $this->worker->handle());

        $this->request('GET', '/search', ['q' => 'second']);
        $this->assertSame('second', $this->worker->handle());
    }

    #[Test]
    public function it_runs_every_registered_resetter(): void
    {
        $calls = 0;
        RequestScope::onReset(function () use (&$calls): void {
            ++$calls;
        }, 'test.counter');

        $this->app->router->get('/ping', fn (): string => 'pong');

        for ($i = 0; $i < 3; $i++) {
            $this->request('GET', '/ping');
            $this->worker->handle();
        }

        $this->assertSame(3, $calls);
    }

    #[Test]
    public function a_failing_resetter_does_not_stop_the_worker(): void
    {
        RequestScope::onReset(static function (): void {
            throw new \RuntimeException('reset exploded');
        }, 'test.broken');

        $this->app->router->get('/ping', fn (): string => 'pong');
        $this->request('GET', '/ping');

        // The failure is reported through error_log; silence it for this test.
        $previous = ini_set('error_log', '/dev/null');

        try {
            $this->assertSame('pong', $this->worker->handle());
        } finally {
            ini_set('error_log', $previous === false ? '' : $previous);
        }
    }

    #[Test]
    public function a_throwing_route_does_not_poison_the_next_request(): void
    {
        $this->app->router->get('/boom', function (): string {
            throw new \RuntimeException('kaboom');
        });
        $this->app->router->get('/ping', fn (): string => 'pong');

        $this->request('GET', '/boom');
        $this->worker->handle();

        $this->request('GET', '/ping');
        $this->assertSame('pong', $this->worker->handle());
        $this->assertSame(200, $this->app->response->getStatusCode());
    }

    #[Test]
    public function the_output_buffer_level_stays_flat_across_requests(): void
    {
        // A drifting buffer level means a render path is leaking an ob_start().
        $this->app->router->get('/ping', fn (): string => 'pong');

        $this->request('GET', '/ping');
        $this->worker->handle();
        $baseline = ob_get_level();

        for ($i = 0; $i < 20; $i++) {
            $this->request('GET', '/ping');
            $this->worker->handle();
        }

        $this->assertSame($baseline, ob_get_level());
    }

    #[Test]
    public function memory_does_not_grow_without_bound(): void
    {
        $this->app->router->get('/users/{id}', fn ($request, $response, $id): string => "user:{$id}");

        // Warm up so first-call allocations are not counted as growth.
        for ($i = 0; $i < 200; $i++) {
            $this->request('GET', "/users/{$i}");
            $this->worker->handle();
        }

        gc_collect_cycles();
        $before = memory_get_usage();

        for ($i = 0; $i < 2000; $i++) {
            $this->request('GET', "/users/{$i}");
            $this->worker->handle();
        }

        gc_collect_cycles();
        $growth = memory_get_usage() - $before;

        // Some growth is expected from interned strings; runaway growth is not.
        $this->assertLessThan(512 * 1024, $growth, sprintf('Worker grew by %d bytes over 2000 requests', $growth));
    }

    #[Test]
    public function it_asks_to_be_recycled_after_the_request_limit(): void
    {
        $worker = new Worker($this->app, ['max_requests' => 3]);
        $this->app->router->get('/ping', fn (): string => 'pong');

        for ($i = 0; $i < 2; $i++) {
            $this->request('GET', '/ping');
            $worker->handle();
        }

        $this->assertFalse($worker->shouldRecycle());

        $this->request('GET', '/ping');
        $worker->handle();

        $this->assertTrue($worker->shouldRecycle());
    }

    #[Test]
    public function it_asks_to_be_recycled_past_the_memory_limit(): void
    {
        $worker = new Worker($this->app, ['memory_limit' => 1]);

        $this->assertTrue($worker->shouldRecycle());
    }

    #[Test]
    public function it_reports_its_health(): void
    {
        $this->app->router->get('/ping', fn (): string => 'pong');
        $this->request('GET', '/ping');
        $this->worker->handle();

        $stats = $this->worker->stats();

        $this->assertSame(1, $stats['handled']);
        $this->assertGreaterThan(0, $stats['memory']);
        $this->assertContains('engine.application', $stats['resetters']);
    }
}
