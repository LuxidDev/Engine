<?php

namespace Luxid\Foundation;

use Luxid\Routing\Router;
use Luxid\Http\Response;
use Luxid\Http\Request;
use Luxid\Http\SessionInterface;
use Rocket\Connection\Connection;
use Luxid\Database\DbEntity;
use Luxid\Contracts\Auth\AuthManager;

class Application
{
    public static string $ROOT_DIR;
    public string $frame = 'app';
    public string $userClass;
    public Router $router;
    public Request $request;
    public Response $response;
    public ?SessionInterface $session = null;
    public static Application $app;
    public ?Action $action = null;
    public ?Connection $db = null;
    public ?DbEntity $user = null;
    public Screen $screen;
    public ?AuthManager $auth = null;

    /**
     * Registered package providers
     */
    protected array $providers = [];

    public function __construct($rootPath, array $config)
    {
        $this->userClass = $config['userClass'];

        self::$ROOT_DIR = $rootPath;
        self::$app = $this;

        $this->request = new Request();
        $this->response = new Response();

        $this->session = null;

        $this->router = new Router($this->request, $this->response);
        $this->router->addApiGlobalMiddleware(new \Luxid\Middleware\CorsMiddleware());
        $this->screen = new Screen();

        // Initialize Rocket connection
        if (isset($config['db'])) {
            Connection::initialize($config['db']);
            $this->db = Connection::getInstance();
        }

        $this->discoverProviders();
        $this->registerProviders();
    }

    /**
     * Discover providers from installed packages
     */
    protected function discoverProviders(): void
    {
        $vendorDir = self::$ROOT_DIR . '/vendor';
        $installedPath = $vendorDir . '/composer/installed.json';

        if (!file_exists($installedPath)) {
            return;
        }

        $installed = json_decode(file_get_contents($installedPath), true);

        // Handle different composer.json formats
        $packages = $installed['packages'] ?? $installed;

        foreach ($packages as $package) {
            if (isset($package['extra']['luxid']['providers'])) {
                foreach ($package['extra']['luxid']['providers'] as $provider) {
                    if (class_exists($provider)) {
                        $this->providers[] = $provider;
                    }
                }
            }
        }
    }

    /**
     * Register all discovered providers
     */
    protected function registerProviders(): void
    {
        foreach ($this->providers as $provider) {
            $instance = new $provider();

            if (method_exists($instance, 'register')) {
                $instance->register($this);
            }
        }

        // Boot providers after all are registered
        foreach ($this->providers as $provider) {
            $instance = new $provider();

            if (method_exists($instance, 'boot')) {
                $instance->boot($this);
            }
        }
    }

    public function registerAuth(AuthManager $auth): void
    {
        $this->auth = $auth;
    }

    public static function isGuest()
    {
        return !self::$app->user;
    }

    public function run()
    {
        try {
            return $this->router->resolve();
        } catch (\Exception $e) {
            return $this->handleException($e);
        }
    }

    protected function handleException(\Exception $e): string
    {
        $code = $this->getHttpCode($e);
        $this->response->setStatusCode($code);

        $path = $this->request->getPath();
        $isApiRequest = strpos($path, '/api/') === 0;

        if ($isApiRequest) {
            return json_encode([
                'success' => false,
                'message' => $e->getMessage(),
                'code' => $code,
            ]);
        }

        return $this->screen->renderScreen('_error', ['exception' => $e]);
    }

    protected function getHttpCode(\Exception $e): int
    {
        $code = $e->getCode();

        if (!is_int($code)) {
            $code = (int) $code;
        }

        if ($code < 100 || $code > 599) {
            if ($e instanceof \PDOException) {
                return 500;
            }
            return $e instanceof \Luxid\Exceptions\NotFoundException ? 404 : 500;
        }

        return $code;
    }

    // getter | setter ==================================
    public function getAction()
    {
        return $this->action;
    }

    public function setAction(Action $action)
    {
        $this->action = $action;
    }
    // =============================================

    public function login(DbEntity $user)
    {
        $this->user = $user;
        $primaryKey = $user->primaryKey();
        $primaryValue = $user->{$primaryKey};

        $this->getSession()->set('user', $primaryValue);

        return true;
    }

    public function logout()
    {
        $this->user = null;
        $this->getSession()->remove('user');
    }

    public function getSession(): SessionInterface
    {
        if ($this->session === null) {
            if (php_sapi_name() !== 'cli') {
                $this->session = new \Luxid\Http\Session();
            } else {
                $this->session = new \Luxid\Http\NullSession();
            }

            // Load user from session if available
            if ($this->session->isStarted()) {
                $primaryValue = $this->session->get('user');
                if ($primaryValue !== null) {
                    $primaryKey = $this->userClass::primaryKey();
                    $this->user = $this->userClass::findOne([$primaryKey => $primaryValue]) ?? null;
                }
            }
        }
        return $this->session;
    }
}