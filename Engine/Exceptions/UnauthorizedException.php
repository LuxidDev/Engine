<?php

declare(strict_types=1);

namespace Luxid\Exceptions;

use Exception;

/**
 * Thrown when a request carries no valid credentials.
 *
 * Distinct from {@see ForbiddenException}: 401 means "we do not know who you
 * are, authenticate and try again", while 403 means "we know who you are and
 * you may not do this". Conflating them tells a signed-in user to log in again
 * for a resource they will never be allowed to reach.
 *
 * @package Luxid\Exceptions
 */
class UnauthorizedException extends Exception
{
    /**
     * @var string
     */
    protected $message = 'Authentication required.';

    /**
     * @var int
     */
    protected $code = 401;
}
