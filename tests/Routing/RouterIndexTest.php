<?php

declare(strict_types=1);

namespace Luxid\Tests\Routing;

use Luxid\Exceptions\NotFoundException;
use Luxid\Tests\Fixtures\RecordingMiddleware;
use Luxid\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * Tests for the route index that backs matching.
 *
 * Routes are bucketed by segment count and leading literal, so these cover the
 * cases where that narrowing could wrongly exclude a route.
 *
 * @package Luxid\Tests\Routing
 */
final class RouterIndexTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        RecordingMiddleware::$calls = [];
    }

    #[Test]
    public function it_matches_a_route_whose_first_segment_is_a_parameter(): void
    {
        // This route lands in the wildcard bucket rather than a literal one.
        $this->app->router->get('/{slug}', fn ($request, $response, $slug): string => "slug:{$slug}");
        $this->request('GET', '/about-us');

        $this->assertSame('slug:about-us', $this->app->router->resolve());
    }

    #[Test]
    public function it_matches_when_literal_and_wildcard_buckets_both_apply(): void
    {
        $this->app->router->get('/users/{id}', fn ($request, $response, $id): string => "user:{$id}");
        $this->app->router->get('/{section}/{page}', fn ($request, $response, $s, $p): string => "any:{$s}:{$p}");

        $this->request('GET', '/users/7');
        $this->assertSame('user:7', $this->app->router->resolve());

        $this->request('GET', '/docs/intro');
        $this->assertSame('any:docs:intro', $this->app->router->resolve());
    }

    #[Test]
    public function an_optional_parameter_is_reachable_at_every_length(): void
    {
        $this->app->router->get(
            '/archive/{year?}/{month?}',
            fn ($request, $response, $year, $month): string => ($year ?? '-') . ':' . ($month ?? '-')
        );

        $this->request('GET', '/archive');
        $this->assertSame('-:-', $this->app->router->resolve());

        $this->request('GET', '/archive/2026');
        $this->assertSame('2026:-', $this->app->router->resolve());

        $this->request('GET', '/archive/2026/08');
        $this->assertSame('2026:08', $this->app->router->resolve());
    }

    #[Test]
    public function it_rejects_a_path_longer_than_any_registered_route(): void
    {
        $this->app->router->get('/users/{id}', fn (): string => 'x');
        $this->request('GET', '/users/1/posts/2/comments');

        $this->expectException(NotFoundException::class);
        $this->app->router->resolve();
    }

    #[Test]
    public function it_matches_a_route_with_a_hyphenated_literal_segment(): void
    {
        $this->app->router->get('/api-v2/{id}', fn ($request, $response, $id): string => "v2:{$id}");
        $this->request('GET', '/api-v2/9');

        $this->assertSame('v2:9', $this->app->router->resolve());
    }

    #[Test]
    public function it_treats_a_malformed_placeholder_as_a_literal(): void
    {
        // `{2bad}` is not a usable capture group name, so it stays literal.
        $this->app->router->get('/reports/{2bad}', fn (): string => 'literal');
        $this->request('GET', '/reports/{2bad}');

        $this->assertSame('literal', $this->app->router->resolve());
    }

    #[Test]
    public function middleware_added_after_registration_still_runs(): void
    {
        $this->app->router
            ->get('/late/{id}', fn (): string => 'ok')
            ->middleware(new RecordingMiddleware('late'));

        $this->request('GET', '/late/1');
        $this->app->router->resolve();

        $this->assertSame(['late'], RecordingMiddleware::$calls);
    }

    #[Test]
    public function global_middleware_registered_after_routes_still_runs(): void
    {
        // The chain is memoized per route, so adding to it later must invalidate.
        $this->app->router->get('/late', fn (): string => 'ok');
        $this->app->router->addGlobalMiddleware(new RecordingMiddleware('global'));

        $this->request('GET', '/late');
        $this->app->router->resolve();

        $this->assertSame(['global'], RecordingMiddleware::$calls);
    }

    #[Test]
    public function the_memoized_chain_is_stable_across_repeated_requests(): void
    {
        $this->app->router
            ->get('/repeat', fn (): string => 'ok')
            ->middleware(new RecordingMiddleware('route'));

        $this->request('GET', '/repeat');
        $this->app->router->resolve();
        $this->app->router->resolve();
        $this->app->router->resolve();

        $this->assertSame(['route', 'route', 'route'], RecordingMiddleware::$calls);
    }

    #[Test]
    public function api_middleware_does_not_leak_into_the_memoized_chain(): void
    {
        $this->app->router->addGlobalMiddleware(new RecordingMiddleware('global'));
        $this->app->router->addApiGlobalMiddleware(new RecordingMiddleware('api'));
        $this->app->router->get('/api/thing', fn (): string => 'ok');
        $this->app->router->get('/thing', fn (): string => 'ok');

        $this->request('GET', '/api/thing');
        $this->app->router->resolve();
        $this->assertSame(['global', 'api'], RecordingMiddleware::$calls);

        RecordingMiddleware::$calls = [];
        $this->request('GET', '/thing');
        $this->app->router->resolve();
        $this->assertSame(['global'], RecordingMiddleware::$calls);
    }

    #[Test]
    public function a_group_prefix_is_not_applied_to_later_routes(): void
    {
        $this->app->router->group(['prefix' => 'admin'], function ($router): void {
            $router->get('/panel', fn (): string => 'grouped');
        });

        $this->app->router->get('/panel', fn (): string => 'loose');

        $this->request('GET', '/admin/panel');
        $this->assertSame('grouped', $this->app->router->resolve());

        $this->request('GET', '/panel');
        $this->assertSame('loose', $this->app->router->resolve());
    }

    #[Test]
    public function nested_groups_restore_the_parent_context_on_exit(): void
    {
        $this->app->router->group(['prefix' => 'api', 'middleware' => [new RecordingMiddleware('outer')]], function ($router): void {
            $router->group(['prefix' => 'v1', 'middleware' => [new RecordingMiddleware('inner')]], function ($inner): void {
                $inner->get('/deep', fn (): string => 'deep');
            });

            $router->get('/shallow', fn (): string => 'shallow');
        });

        $this->request('GET', '/api/v1/deep');
        $this->assertSame('deep', $this->app->router->resolve());
        $this->assertSame(['outer', 'inner'], RecordingMiddleware::$calls);

        RecordingMiddleware::$calls = [];
        $this->request('GET', '/api/shallow');
        $this->assertSame('shallow', $this->app->router->resolve());
        $this->assertSame(['outer'], RecordingMiddleware::$calls);
    }

    #[Test]
    public function it_scales_to_a_large_route_table(): void
    {
        for ($i = 0; $i < 300; $i++) {
            $this->app->router->get("/bulk{$i}/{id}/edit", fn ($request, $response, $id): string => "bulk:{$id}");
        }

        $this->request('GET', '/bulk299/42/edit');

        $this->assertSame('bulk:42', $this->app->router->resolve());
    }
}
