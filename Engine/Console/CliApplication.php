<?php

declare(strict_types=1);

namespace Luxid\Console;

use Luxid\Foundation\Application;
use Luxid\Foundation\Screen;
use Luxid\Http\NullSession;
use Luxid\Http\Request;
use Luxid\Http\Response;
use Luxid\Http\SessionInterface;
use Luxid\Routing\Router;

/**
 * Application kernel for console runs.
 *
 * Boots the same object graph as the web kernel but skips the database, the
 * session and the provider scan, so commands that only need the router — the
 * route inspector in particular — start instantly and never touch a connection.
 *
 * @package Luxid\Console
 */
class CliApplication extends Application
{
    /**
     * @param string               $rootPath Absolute path to the project root
     * @param array<string, mixed> $config   Application configuration
     */
    public function __construct(string $rootPath, array $config)
    {
        self::$ROOT_DIR = $rootPath;
        self::$app = $this;

        $this->userClass = $config['userClass'] ?? '';
        $this->debug = (bool) ($config['debug'] ?? false);

        $this->request = new Request();
        $this->response = new Response();
        $this->session = new NullSession();
        $this->screen = new Screen();
        $this->router = new Router($this->request, $this->response);

        $this->registerRequestScope();
    }

    /**
     * Console runs are always unauthenticated.
     */
    public static function isGuest(): bool
    {
        return true;
    }

    /**
     * Signing in is meaningless without a session; this is a no-op.
     *
     * @param object $user Ignored
     */
    public function login(object $user): bool
    {
        return true;
    }

    /**
     * Signing out is meaningless without a session; this is a no-op.
     */
    public function logout(): void
    {
    }

    /**
     * Always hand back the null session.
     */
    public function getSession(): SessionInterface
    {
        return $this->session ??= new NullSession();
    }
}
