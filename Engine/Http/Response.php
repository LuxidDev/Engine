<?php

namespace Luxid\Http;

use Luxid\Foundation\Application;

class Response
{
    protected int $statusCode = 200;
    protected array $headers = [];

    public function setStatusCode(int $code)
    {
        $this->statusCode = $code;
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    public function setHeader(string $name, string $value): void
    {
        $this->headers[$name] = $value;
    }

    public function getHeaders(): array
    {
        return $this->headers;
    }

    public function warp(string $url)
    {
        $this->setHeader('Location', $url);
        $this->setStatusCode(302);
    }

    /**
     * Send JSON response
     */
    public function json($data, int $statusCode = 200): string
    {
        $this->setStatusCode($statusCode);
        $this->setHeader('Content-Type', 'application/json');
        return json_encode($data, JSON_PRETTY_PRINT);
    }

    /**
     * Send successful JSON response
     */
    public function success($data = null, string $message = 'Success', int $statusCode = 200): string
    {
        return $this->json([
            'success' => true,
            'message' => $message,
            'data' => $data
        ], $statusCode);
    }

    /**
     * Send error JSON response
     */
    public function error(string $message = 'Error', $errors = null, int $statusCode = 400): string
    {
        return $this->json([
            'success' => false,
            'message' => $message,
            'errors' => $errors
        ], $statusCode);
    }

    /**
     * Redirect with flash message
     */
    public function redirectWith(string $url, string $key, string $message)
    {
        if (Application::$app) {
            Application::$app->session->setFlash($key, $message);
        }

        $this->setHeader('Location', $url);
        $this->setStatusCode(302);

        return $this->json(['redirect' => $url], 302);
    }
}