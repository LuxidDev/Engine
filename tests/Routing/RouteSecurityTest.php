<?php

declare(strict_types=1);

namespace Luxid\Tests\Routing;

use Luxid\Exceptions\ForbiddenException;
use Luxid\Middleware\AuthMiddleware;
use Luxid\Middleware\PublicMiddleware;
use Luxid\Routing\Routes;
use Luxid\Tests\Fixtures\AccountAction;
use Luxid\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * Tests for the "every route declares its security" guarantee.
 *
 * @package Luxid\Tests\Routing
 */
final class RouteSecurityTest extends TestCase
{
    #[Test]
    public function a_public_collection_attaches_public_middleware(): void
    {
        Routes::new()
            ->add('/login', get('login'))
            ->public()
            ->register(AccountAction::class);

        $middleware = $this->middlewareForPath('/login');

        $this->assertInstanceOf(PublicMiddleware::class, $middleware[0]);
    }

    #[Test]
    public function a_secure_collection_attaches_auth_middleware(): void
    {
        Routes::new()
            ->add('/dashboard', get('dashboard'))
            ->secure()
            ->register(AccountAction::class);

        $middleware = $this->middlewareForPath('/dashboard');

        $this->assertInstanceOf(AuthMiddleware::class, $middleware[0]);
        $this->assertSame([], $middleware[0]->publicActivities);
    }

    #[Test]
    public function an_open_collection_records_its_public_activities(): void
    {
        // Regression: open() previously ignored its argument and installed a
        // blanket PublicMiddleware, opening every activity on the collection.
        Routes::new()
            ->add('/login', get('login'))
            ->add('/dashboard', get('dashboard'))
            ->open(['login'])
            ->register(AccountAction::class);

        $middleware = $this->middlewareForPath('/dashboard');

        $this->assertInstanceOf(AuthMiddleware::class, $middleware[0]);
        $this->assertSame(['login'], $middleware[0]->publicActivities);
    }

    #[Test]
    public function an_open_route_still_guards_activities_it_did_not_name(): void
    {
        Routes::new()
            ->add('/dashboard', get('dashboard'))
            ->open(['login'])
            ->register(AccountAction::class);

        $this->request('GET', '/dashboard');

        $this->expectException(ForbiddenException::class);
        $this->app->router->resolve();
    }

    #[Test]
    public function an_open_route_admits_the_activities_it_named(): void
    {
        Routes::new()
            ->add('/login', get('login'))
            ->open(['login'])
            ->register(AccountAction::class);

        $this->request('GET', '/login');

        $this->assertSame('account:login', $this->app->router->resolve());
    }

    #[Test]
    public function a_public_route_admits_a_guest(): void
    {
        Routes::new()
            ->add('/login', get('login'))
            ->public()
            ->register(AccountAction::class);

        $this->request('GET', '/login');

        $this->assertSame('account:login', $this->app->router->resolve());
    }

    #[Test]
    public function a_secure_route_rejects_a_guest(): void
    {
        Routes::new()
            ->add('/dashboard', get('dashboard'))
            ->secure()
            ->register(AccountAction::class);

        $this->request('GET', '/dashboard');

        $this->expectException(ForbiddenException::class);
        $this->app->router->resolve();
    }

    #[Test]
    public function a_secure_route_admits_a_signed_in_user(): void
    {
        Routes::new()
            ->add('/dashboard', get('dashboard'))
            ->secure()
            ->register(AccountAction::class);

        $this->app->user = new \stdClass();
        $this->request('GET', '/dashboard');

        $this->assertSame('account:dashboard', $this->app->router->resolve());
    }

    #[Test]
    public function it_rejects_a_collection_bound_to_a_non_action_class(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('must extend');

        Routes::new()->add('/x', get('index'))->public()->register(\stdClass::class);
    }

    #[Test]
    public function it_rejects_a_collection_bound_to_a_missing_class(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('does not exist');

        Routes::new()->add('/x', get('index'))->public()->register('App\\Nope');
    }

    #[Test]
    public function it_applies_the_collection_prefix(): void
    {
        Routes::new()
            ->prefix('api')
            ->add('/login', get('login'))
            ->public()
            ->register(AccountAction::class);

        $this->assertTrue($this->app->router->has('GET', '/api/login'));
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
