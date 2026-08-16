<?php

declare(strict_types=1);

namespace Luxid\Exceptions;

use Exception;

/**
 * Thrown when a path is registered but not for the requested HTTP method.
 *
 * The router sets an `Allow` header listing the acceptable methods before
 * throwing, so the 405 response stays RFC compliant.
 *
 * @package Luxid\Exceptions
 */
class MethodNotAllowedException extends Exception
{
    /**
     * @var string
     */
    protected $message = 'Method not allowed for this route.';

    /**
     * @var int
     */
    protected $code = 405;
}
