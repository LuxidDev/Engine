<?php

namespace Luxid\FrankenPHP;

use Luxid\Foundation\Application;

class Adapter
{
    public $app;

    public function __construct(string $rootPath, array $config)
    {
        // Boot Luxid ONCE
        $this->app = new Application($rootPath, $config);

        // Load routes ONCE
        require_once $rootPath . '/routes/api.php';
        require_once $rootPath . '/routes/web.php';
    }

    /**
     * Get the request handler for FrankenPHP
     */
    public function getHandler(): callable
    {
        return function ($request) {
            return $this->handle($request);
        };
    }

    /**
     * Handle a request
     */
    public function handle($request): string
    {
        // Convert FrankenPHP request to Luxid request
        $luxidReq = $this->toLuxidRequest($request);
        $luxidRes = new \Luxid\Http\Response();

        // Set request/response on app
        $this->app->request = $luxidReq;
        $this->app->response = $luxidRes;

        // Handle request
        return $this->app->run();
    }

    private function toLuxidRequest($frankenRequest): \Luxid\Http\Request
    {
        $luxidReq = new \Luxid\Http\Request();

        // Get URI path
        $uri = $frankenRequest->getUri();
        $luxidReq->setPath($uri->getPath());
        $luxidReq->setMethod(strtolower($frankenRequest->getMethod()));

        // Get body
        $body = $frankenRequest->getContent();
        if ($body) {
            $luxidReq->setBody($body);

            // Parse JSON if content-type is JSON
            $contentType = $frankenRequest->getHeaderLine('Content-Type');
            if (strpos($contentType, 'application/json') !== false) {
                $_POST = json_decode($body, true) ?? [];
            }
        }

        // Set query parameters
        parse_str($uri->getQuery(), $query);
        if (!empty($query)) {
            $_GET = $query;
        }

        // Set headers
        foreach ($frankenRequest->getHeaders() as $name => $values) {
            $luxidReq->setHeader($name, implode(', ', $values));
        }

        return $luxidReq;
    }
}

