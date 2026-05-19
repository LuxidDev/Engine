<?php

namespace Luxid\RoadRunner;

use Nyholm\Psr7\Factory\Psr17Factory;
use Spiral\RoadRunner\Worker;
use Spiral\RoadRunner\Http\PSR7Worker;

class Adapter
{
    private $app;

    public function __construct(string $rootPath, array $config)
    {
        // Boot Luxid ONCE
        $this->app = new \Luxid\Foundation\Application($rootPath, $config);

        // Load routes ONCE
        require_once $rootPath . '/routes/api.php';
        require_once $rootPath . '/routes/web.php';
    }

    public function run(): void
    {
        $worker = Worker::create();
        $psr17Factory = new Psr17Factory();
        $psr7Worker = new PSR7Worker($worker, $psr17Factory, $psr17Factory, $psr17Factory);

        while ($request = $psr7Worker->waitRequest()) {
            try {
                $luxidReq = $this->toLuxidRequest($request);
                $luxidRes = new \Luxid\Http\Response();

                $this->app->request = $luxidReq;
                $this->app->response = $luxidRes;

                $output = $this->app->run();

                $response = $psr17Factory->createResponse($luxidRes->getStatusCode());
                foreach ($luxidRes->getHeaders() as $name => $value) {
                    $response = $response->withHeader($name, $value);
                }
                $response->getBody()->write($output);

                $psr7Worker->respond($response);
            } catch (\Throwable $e) {
                error_log("Error: " . $e->getMessage());
                $psr7Worker->getWorker()->error((string) $e);
            }
        }
    }

    private function toLuxidRequest($psrRequest): \Luxid\Http\Request
    {
        $luxidReq = new \Luxid\Http\Request();

        $luxidReq->setPath($psrRequest->getUri()->getPath());
        $luxidReq->setMethod(strtolower($psrRequest->getMethod()));

        $body = (string) $psrRequest->getBody();
        if ($body) {
            $luxidReq->setBody($body);
        }

        foreach ($psrRequest->getHeaders() as $name => $values) {
            $luxidReq->setHeader($name, implode(', ', $values));
        }

        parse_str($psrRequest->getUri()->getQuery(), $query);
        if (!empty($query)) {
            $_GET = $query;
        }

        return $luxidReq;
    }
}
