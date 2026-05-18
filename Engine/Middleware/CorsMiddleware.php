<?php

namespace Luxid\Middleware;

use Luxid\Foundation\Application;

class CorsMiddleware extends BaseMiddleware
{
    public array $allowedOrigins = ['*'];

    public function execute()
    {
        $response = Application::$app->response;
        $response->setHeader('Access-Control-Allow-Origin', '*');
        $response->setHeader('Access-Control-Allow-Methods', 'GET, POST, PUT, PATCH, DELETE, OPTIONS');
        $response->setHeader('Access-Control-Allow-Headers', 'Content-Type, Authorization, X-Requested-With');
        $response->setHeader('Access-Control-Allow-Credentials', 'true');

        $request = Application::$app->request;
        if ($request->method() === 'options') {
            $response->setStatusCode(200);

            return;
        }
    }
}