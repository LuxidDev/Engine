<?php

declare(strict_types=1);

namespace Luxid\Tests\Fixtures;

use Luxid\Foundation\Action;

/**
 * Action with both a public and a protected activity, used to exercise the
 * partial-exemption security posture.
 *
 * @package Luxid\Tests\Fixtures
 */
class AccountAction extends Action
{
    /**
     * Publicly reachable sign-in activity.
     */
    public function login(): string
    {
        return 'account:login';
    }

    /**
     * Activity that should require authentication.
     */
    public function dashboard(): string
    {
        return 'account:dashboard';
    }
}
