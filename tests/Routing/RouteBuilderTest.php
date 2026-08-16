<?php

declare(strict_types=1);

namespace Luxid\Tests\Routing;

use Luxid\Middleware\AuthMiddleware;
use Luxid\Middleware\PublicMiddleware;
use Luxid\Routing\RouteBuilder;
use Luxid\Tests\Fixtures\AccountAction;
use Luxid\Tests\Fixtures\RecordingMiddleware;
use Luxid\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * Tests for the fluent route DSL.
 *
 * @package Luxid\Tests\Routing
 */
final class RouteBuilderTest extends TestCase
{
    #[Test]
    public function it_registers_a_route_once_security_is_declared(): void
    {
        route('login')->get('/login')->uses(AccountAction::class, 'login')->public();

        $this->assertTrue($this->app->router->has('GET', '/login'));
    }

    #[Test]
    public function open_attaches_auth_middleware_carrying_the_public_activities(): void
    {
        // Regression: open() used to discard its argument and install a blanket
        // PublicMiddleware, which opened every activity on the route.
        route('account')->get('/account')->uses(AccountAction::class, 'dashboard')->open(['login']);

        $middleware = $this->middlewareForPath('/account');

        $this->assertInstanceOf(AuthMiddleware::class, $middleware[0]);
        $this->assertSame(['login'], $middleware[0]->publicActivities);
    }

    #[Test]
    public function public_attaches_public_middleware(): void
    {
        route('login')->get('/login')->uses(AccountAction::class, 'login')->public();

        $this->assertInstanceOf(PublicMiddleware::class, $this->middlewareForPath('/login')[0]);
    }

    #[Test]
    public function it_rejects_an_incomplete_definition(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('incomplete');

        route('broken')->get('/broken')->public();
    }

    #[Test]
    public function it_registers_a_route_only_once(): void
    {
        $builder = route('login')->get('/login')->uses(AccountAction::class, 'login')->public();
        $builder->register();

        $paths = array_column($this->app->router->getRoutesForInspection(), 'path');

        $this->assertSame(['/login'], array_values(array_filter($paths, fn ($p) => $p === '/login')));
    }

    #[Test]
    public function it_attaches_extra_middleware(): void
    {
        route('login')
            ->get('/login')
            ->uses(AccountAction::class, 'login')
            ->with(new RecordingMiddleware('extra'))
            ->public();

        $labels = array_map(
            static fn (object $m): string => $m::class,
            $this->middlewareForPath('/login')
        );

        $this->assertContains(RecordingMiddleware::class, $labels);
    }

    #[Test]
    public function it_rejects_middleware_that_is_not_middleware(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        route('login')->get('/login')->uses(AccountAction::class, 'login')->with(\stdClass::class);
    }

    #[Test]
    public function it_inherits_auth_from_the_enclosing_group(): void
    {
        $this->app->router->group(['auth' => true], function (): void {
            route('dash')->get('/dash')->uses(AccountAction::class, 'dashboard')->register();
        });

        $this->assertInstanceOf(AuthMiddleware::class, $this->middlewareForPath('/dash')[0]);
    }

    #[Test]
    public function it_reports_the_route_name(): void
    {
        $builder = new RouteBuilder($this->app->router, 'todos.index');

        $this->assertSame('todos.index', $builder->getName());
    }

    /**
     * Get the middleware attached to the route at the given path.
     *
     * @param string $path Registered route path
     *
     * @return list<\Luxid\Middleware\BaseMiddleware>
     */
    private function middlewareForPath(string $path): array
    {
        foreach ($this->app->router->getRoutesForInspection() as $route) {
            if ($route['path'] === $path) {
                return $route['middleware'];
            }
        }

        $this->fail(sprintf('No route registered for "%s"', $path));
    }
}
