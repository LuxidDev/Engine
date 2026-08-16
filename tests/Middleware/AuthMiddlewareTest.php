<?php

declare(strict_types=1);

namespace Luxid\Tests\Middleware;

use Luxid\Exceptions\ForbiddenException;
use Luxid\Foundation\Action;
use Luxid\Middleware\AuthMiddleware;
use Luxid\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * Tests for the authentication gate.
 *
 * @package Luxid\Tests\Middleware
 */
final class AuthMiddlewareTest extends TestCase
{
    #[Test]
    public function it_rejects_a_guest(): void
    {
        $this->expectException(ForbiddenException::class);

        (new AuthMiddleware())->execute();
    }

    #[Test]
    public function it_rejects_a_guest_even_when_no_action_is_resolved(): void
    {
        // Regression: the middleware returned early when no action had been
        // resolved, which silently admitted guests to closure routes.
        $this->app->action = null;

        $this->expectException(ForbiddenException::class);

        (new AuthMiddleware(['login']))->execute();
    }

    #[Test]
    public function it_admits_a_signed_in_user(): void
    {
        $this->app->user = new \stdClass();

        (new AuthMiddleware())->execute();

        $this->assertNotNull($this->app->user);
    }

    #[Test]
    public function it_admits_an_activity_named_as_public(): void
    {
        $this->app->action = $this->actionRunning('login');

        (new AuthMiddleware(['login']))->execute();

        $this->assertSame('login', $this->app->action->activity);
    }

    #[Test]
    public function it_rejects_an_activity_that_was_not_named_as_public(): void
    {
        $this->app->action = $this->actionRunning('dashboard');

        $this->expectException(ForbiddenException::class);

        (new AuthMiddleware(['login']))->execute();
    }

    #[Test]
    public function it_accepts_public_activities_as_the_second_argument(): void
    {
        $middleware = new AuthMiddleware(null, ['login']);

        $this->assertSame(['login'], $middleware->publicActivities);
    }

    #[Test]
    public function it_raises_a_forbidden_exception_with_a_403_code(): void
    {
        try {
            (new AuthMiddleware())->execute();
            $this->fail('Expected a ForbiddenException.');
        } catch (ForbiddenException $e) {
            $this->assertSame(403, $e->getCode());
        }
    }

    /**
     * Build an action standing in as the one handling the request.
     *
     * @param string $activity Activity name to report
     */
    private function actionRunning(string $activity): Action
    {
        $action = new class () extends Action {
        };
        $action->activity = $activity;

        return $action;
    }
}
