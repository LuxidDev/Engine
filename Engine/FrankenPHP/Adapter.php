<?php

declare(strict_types=1);

namespace Luxid\FrankenPHP;

use Luxid\Foundation\Application;
use Luxid\Http\Request;
use Luxid\Http\Response;

/**
 * Worker-mode adapter for FrankenPHP.
 *
 * The application boots once and then serves many requests from the same
 * process, so every piece of per-request state has to be replaced on each
 * iteration. Anything left behind — a resolved action, a hydrated user, a stale
 * superglobal — leaks from one visitor's request into the next one.
 *
 * @package Luxid\FrankenPHP
 */
class Adapter
{
    /**
     * The long-lived application kernel.
     */
    public Application $app;

    /**
     * Boot the application and load the route table once.
     *
     * @param string               $rootPath Absolute path to the project root
     * @param array<string, mixed> $config   Application configuration
     */
    public function __construct(string $rootPath, array $config)
    {
        $this->app = new Application($rootPath, $config);

        require_once $rootPath . '/routes/api.php';
        require_once $rootPath . '/routes/web.php';
    }

    /**
     * Get a request handler closure for the FrankenPHP worker loop.
     *
     * @return callable(object): string
     */
    public function getHandler(): callable
    {
        return fn (object $request): string => $this->handle($request);
    }

    /**
     * Handle one request and return its body.
     *
     * @param object $request PSR-7 style request supplied by the runtime
     */
    public function handle(object $request): string
    {
        $this->resetRequestState();

        $this->app->request = $this->toLuxidRequest($request);
        $this->app->response = new Response();
        $this->app->router->request = $this->app->request;
        $this->app->router->response = $this->app->response;

        $body = $this->app->handle();

        $this->app->response->sendHeaders();

        return $body;
    }

    /**
     * Clear everything the previous request left on the kernel.
     */
    private function resetRequestState(): void
    {
        $this->app->action = null;
        $this->app->user = null;
        $this->app->session = null;

        $_GET = [];
        $_POST = [];
    }

    /**
     * Convert the runtime's request object into a Luxid request.
     *
     * @param object $frankenRequest PSR-7 style request supplied by the runtime
     */
    private function toLuxidRequest(object $frankenRequest): Request
    {
        $request = new Request();

        $uri = $frankenRequest->getUri();
        $request->setPath($uri->getPath());
        $request->setMethod($frankenRequest->getMethod());

        foreach ($frankenRequest->getHeaders() as $name => $values) {
            $request->setHeader((string) $name, implode(', ', $values));
        }

        parse_str($uri->getQuery(), $query);
        $_GET = $query;

        $body = (string) $frankenRequest->getBody();

        if ($body !== '') {
            $request->setBody($body);

            $contentType = $frankenRequest->getHeaderLine('Content-Type');

            if (!str_contains($contentType, 'application/json')) {
                parse_str($body, $form);
                $_POST = $form;
            }
        }

        return $request;
    }
}
