<?php

declare(strict_types=1);

namespace Luxid\Middleware;

use Luxid\Contracts\Auth\AuthManager;
use Luxid\Exceptions\ForbiddenException;
use Luxid\Foundation\Application;

/**
 * Rejects requests from unauthenticated visitors.
 *
 * Individual activities on an otherwise protected action can be exempted by
 * listing them in `$publicActivities`, which is how a login action lives on the
 * same class as the members-only ones.
 *
 * The check fails closed: anything that is not demonstrably public and not
 * demonstrably authenticated is rejected.
 *
 * @package Luxid\Middleware
 */
class AuthMiddleware extends BaseMiddleware
{
    /**
     * The auth manager to consult, when a package such as Haven supplied one.
     */
    protected ?AuthManager $auth = null;

    /**
     * Activities reachable without authentication.
     *
     * @var list<string>
     */
    public array $publicActivities = [];

    /**
     * Accepts either an auth manager or, for the legacy signature, the list of
     * public activities as the first argument.
     *
     * @param AuthManager|list<string>|null $auth             Auth manager, or public activities
     * @param list<string>                  $publicActivities Public activities when $auth is a manager
     */
    public function __construct(AuthManager|array|null $auth = null, array $publicActivities = [])
    {
        if ($auth instanceof AuthManager) {
            $this->auth = $auth;
            $this->publicActivities = $publicActivities;

            return;
        }

        $this->publicActivities = is_array($auth) ? $auth : $publicActivities;
    }

    /**
     * Allow public activities and authenticated users; reject everything else.
     *
     * @throws ForbiddenException When the visitor may not reach this activity
     */
    public function execute(): void
    {
        if ($this->isPublicActivity()) {
            return;
        }

        if ($this->isAuthenticated()) {
            return;
        }

        throw new ForbiddenException();
    }

    /**
     * Check whether the activity being invoked is explicitly public.
     */
    protected function isPublicActivity(): bool
    {
        if ($this->publicActivities === []) {
            return false;
        }

        $activity = Application::$app->action?->activity;

        return $activity !== null && in_array($activity, $this->publicActivities, true);
    }

    /**
     * Check whether the current visitor is signed in.
     *
     * Prefers the auth manager when one is bound and falls back to the session
     * hydrated user so applications without an auth package still work.
     */
    protected function isAuthenticated(): bool
    {
        $manager = $this->auth ?? Application::$app->auth;

        if ($manager !== null) {
            return $manager->check();
        }

        return Application::$app->user !== null;
    }
}
