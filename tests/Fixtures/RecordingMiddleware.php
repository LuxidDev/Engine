<?php

declare(strict_types=1);

namespace Luxid\Tests\Fixtures;

use Luxid\Middleware\BaseMiddleware;

/**
 * Middleware that records the order in which it ran.
 *
 * @package Luxid\Tests\Fixtures
 */
class RecordingMiddleware extends BaseMiddleware
{
    /**
     * Labels of every middleware executed since the last reset.
     *
     * @var list<string>
     */
    public static array $calls = [];

    /**
     * @param string $label Identifier recorded when this middleware runs
     */
    public function __construct(private readonly string $label)
    {
    }

    /**
     * Record that this middleware ran.
     */
    public function execute(): void
    {
        self::$calls[] = $this->label;
    }
}
