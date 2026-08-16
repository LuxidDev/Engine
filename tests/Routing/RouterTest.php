<?php

declare(strict_types=1);

namespace Luxid\Tests\Routing;

use Luxid\Exceptions\MethodNotAllowedException;
use Luxid\Exceptions\NotFoundException;
use Luxid\Middleware\BaseMiddleware;
use Luxid\Tests\Fixtures\RecordingMiddleware;
use Luxid\Tests\Fixtures\TodoAction;
use Luxid\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * Behavioural tests for the router.
 *
 * @package Luxid\Tests\Routing
 */
final class RouterTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        RecordingMiddleware::$calls = [];
    }

    #[Test]
    public function it_resolves_a_static_route(): void
    {
        $this->app->router->get('/health', fn (): string => 'ok');
        $this->request('GET', '/health');

        $this->assertSame('ok', $this->app->router->resolve());
    }

    #[Test]
    public function it_treats_trailing_slashes_as_the_same_route(): void
    {
        $this->app->router->get('/health', fn (): string => 'ok');
        $this->request('GET', '/health/');

        $this->assertSame('ok', $this->app->router->resolve());
    }

    #[Test]
    public function it_extracts_a_route_parameter(): void
    {
        $this->app->router->get('/users/{id}', fn ($request, $response, $id): string => "user:{$id}");
        $this->request('GET', '/users/42');

        $this->assertSame('user:42', $this->app->router->resolve());
    }

    #[Test]
    public function it_extracts_several_route_parameters_in_order(): void
    {
        $this->app->router->get(
            '/posts/{post}/comments/{comment}',
            fn ($request, $response, $post, $comment): string => "{$post}-{$comment}"
        );
        $this->request('GET', '/posts/7/comments/9');

        $this->assertSame('7-9', $this->app->router->resolve());
    }

    #[Test]
    public function it_matches_an_optional_parameter_when_present(): void
    {
        $this->app->router->get('/archive/{year?}', fn ($request, $response, $year): string => $year ?? 'all');
        $this->request('GET', '/archive/2026');

        $this->assertSame('2026', $this->app->router->resolve());
    }

    #[Test]
    public function it_matches_an_optional_parameter_when_absent(): void
    {
        $this->app->router->get('/archive/{year?}', fn ($request, $response, $year): string => $year ?? 'all');
        $this->request('GET', '/archive');

        $this->assertSame('all', $this->app->router->resolve());
    }

    #[Test]
    public function it_does_not_let_a_parameter_swallow_extra_segments(): void
    {
        $this->app->router->get('/users/{id}', fn (): string => 'matched');
        $this->request('GET', '/users/42/posts');

        $this->expectException(NotFoundException::class);
        $this->app->router->resolve();
    }

    #[Test]
    public function it_prefers_a_static_route_over_a_dynamic_one(): void
    {
        $this->app->router->get('/users/{id}', fn (): string => 'dynamic');
        $this->app->router->get('/users/me', fn (): string => 'static');
        $this->request('GET', '/users/me');

        $this->assertSame('static', $this->app->router->resolve());
    }

    #[Test]
    public function it_throws_not_found_for_an_unregistered_path(): void
    {
        $this->request('GET', '/nowhere');

        $this->expectException(NotFoundException::class);
        $this->app->router->resolve();
    }

    #[Test]
    public function it_throws_method_not_allowed_when_the_path_exists_under_another_method(): void
    {
        $this->app->router->post('/todos', fn (): string => 'created');
        $this->request('GET', '/todos');

        $this->expectException(MethodNotAllowedException::class);

        try {
            $this->app->router->resolve();
        } finally {
            $this->assertSame('POST', $this->app->response->getHeader('Allow'));
        }
    }

    #[Test]
    public function it_runs_route_middleware_for_closure_routes(): void
    {
        $this->app->router
            ->get('/guarded', fn (): string => 'body')
            ->middleware(new RecordingMiddleware('route'));

        $this->request('GET', '/guarded');
        $this->app->router->resolve();

        $this->assertSame(['route'], RecordingMiddleware::$calls);
    }

    #[Test]
    public function it_runs_route_middleware_for_action_routes(): void
    {
        $this->app->router
            ->get('/todos', [TodoAction::class, 'index'])
            ->middleware(new RecordingMiddleware('route'));

        $this->request('GET', '/todos');

        $this->assertSame('todos:index', $this->app->router->resolve());
        $this->assertSame(['route'], RecordingMiddleware::$calls);
    }

    #[Test]
    public function it_runs_global_middleware_before_route_middleware(): void
    {
        $this->app->router->addGlobalMiddleware(new RecordingMiddleware('global'));
        $this->app->router
            ->get('/guarded', fn (): string => 'body')
            ->middleware(new RecordingMiddleware('route'));

        $this->request('GET', '/guarded');
        $this->app->router->resolve();

        $this->assertSame(['global', 'route'], RecordingMiddleware::$calls);
    }

    #[Test]
    public function it_runs_api_middleware_only_for_api_paths(): void
    {
        $this->app->router->addApiGlobalMiddleware(new RecordingMiddleware('api'));
        $this->app->router->get('/api/health', fn (): string => 'ok');
        $this->app->router->get('/health', fn (): string => 'ok');

        $this->request('GET', '/health');
        $this->app->router->resolve();
        $this->assertSame([], RecordingMiddleware::$calls);

        $this->request('GET', '/api/health');
        $this->app->router->resolve();
        $this->assertSame(['api'], RecordingMiddleware::$calls);
    }

    #[Test]
    public function it_runs_middleware_for_dynamic_routes(): void
    {
        $this->app->router
            ->get('/users/{id}', fn ($request, $response, $id): string => $id)
            ->middleware(new RecordingMiddleware('route'));

        $this->request('GET', '/users/3');
        $this->app->router->resolve();

        $this->assertSame(['route'], RecordingMiddleware::$calls);
    }

    #[Test]
    public function it_applies_group_prefixes_and_middleware(): void
    {
        $this->app->router->group(
            ['prefix' => 'api', 'middleware' => [new RecordingMiddleware('group')]],
            function ($router): void {
                $router->get('/todos', fn (): string => 'listed');
            }
        );

        $this->request('GET', '/api/todos');

        $this->assertSame('listed', $this->app->router->resolve());
        $this->assertSame(['group'], RecordingMiddleware::$calls);
    }

    #[Test]
    public function it_nests_group_prefixes(): void
    {
        $this->app->router->group(['prefix' => 'api'], function ($router): void {
            $router->group(['prefix' => 'v1'], function ($inner): void {
                $inner->get('/todos', fn (): string => 'listed');
            });
        });

        $this->request('GET', '/api/v1/todos');

        $this->assertSame('listed', $this->app->router->resolve());
    }

    #[Test]
    public function it_pops_the_group_stack_after_registration(): void
    {
        $this->app->router->group(['prefix' => 'api'], function ($router): void {
            $router->get('/todos', fn (): string => 'grouped');
        });

        $this->app->router->get('/loose', fn (): string => 'loose');
        $this->request('GET', '/loose');

        $this->assertSame('loose', $this->app->router->resolve());
    }

    #[Test]
    public function it_pops_the_group_stack_even_when_registration_throws(): void
    {
        try {
            $this->app->router->group(['prefix' => 'api'], function (): void {
                throw new \RuntimeException('boom');
            });
        } catch (\RuntimeException) {
            // Expected: the group callback failed mid-registration.
        }

        $this->assertSame([], $this->app->router->getGroupStack());
    }

    #[Test]
    public function it_injects_the_request_and_response_by_type(): void
    {
        $this->app->router->get('/echo', function (\Luxid\Http\Request $request, \Luxid\Http\Response $response): string {
            $response->setStatusCode(201);

            return $request->getPath();
        });

        $this->request('GET', '/echo');

        $this->assertSame('/echo', $this->app->router->resolve());
        $this->assertSame(201, $this->app->response->getStatusCode());
    }

    #[Test]
    public function it_rejects_a_callback_class_that_is_not_an_action(): void
    {
        $this->app->router->get('/bad', [\stdClass::class, 'index']);
        $this->request('GET', '/bad');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('must extend');

        $this->app->router->resolve();
    }

    #[Test]
    public function it_rejects_an_unsupported_http_method(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->app->router->addRoute('connect', '/x', fn (): string => '');
    }

    #[Test]
    public function it_rejects_a_middleware_class_that_is_not_middleware(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->app->router->group(['middleware' => [\stdClass::class]], function (): void {
        });
    }

    #[Test]
    public function it_reports_registered_routes_for_inspection(): void
    {
        $this->app->router->get('/health', fn (): string => 'ok');
        $this->app->router->post('/todos/{id}', fn (): string => 'ok');

        $paths = array_column($this->app->router->getRoutesForInspection(), 'path');

        $this->assertContains('/health', $paths);
        $this->assertContains('/todos/{id}', $paths);
    }

    #[Test]
    public function it_reports_whether_a_route_exists(): void
    {
        $this->app->router->get('/users/{id}', fn (): string => 'ok');

        $this->assertTrue($this->app->router->has('GET', '/users/1'));
        $this->assertFalse($this->app->router->has('POST', '/users/1'));
        $this->assertFalse($this->app->router->has('GET', '/other'));
    }

    #[Test]
    public function it_accepts_a_middleware_instance_shared_between_groups(): void
    {
        $shared = new class ('shared') extends RecordingMiddleware {
        };

        $this->app->router->group(['middleware' => [$shared]], function ($router): void {
            $router->get('/a', fn (): string => 'a');
            $router->get('/b', fn (): string => 'b');
        });

        $this->request('GET', '/a');
        $this->app->router->resolve();
        $this->request('GET', '/b');
        $this->app->router->resolve();

        $this->assertSame(['shared', 'shared'], RecordingMiddleware::$calls);
        $this->assertInstanceOf(BaseMiddleware::class, $shared);
    }
}
