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
            echo $this->router->resolve();
        } catch (\Exception $e) {
            // Get the exception code and ensure it's a valid HTTP status code
            $code = $e->getCode();

            // Convert to integer if needed
            if (!is_int($code)) {
                $code = (int) $code;
            }

            // Validate it's a proper HTTP status code (100-599)
            if ($code < 100 || $code > 599) {
                // For PDOExceptions and other non-HTTP exceptions, use 500
                if ($e instanceof \PDOException) {
                    $code = 500; // Internal Server Error for database issues
                } else {
                    $code = $e instanceof \Luxid\Exceptions\NotFoundException ? 404 : 500;
                }
            }

            $this->response->setStatusCode($code);

            // Check if this is an API request
            $path = $this->request->getPath();
            $acceptHeader = $_SERVER['HTTP_ACCEPT'] ?? '';
            $contentType = $_SERVER['CONTENT_TYPE'] ?? '';

            $isApiRequest = strpos($path, '/api/') === 0
              || strpos($acceptHeader, 'application/json') !== false
              || strpos($contentType, 'application/json') !== false;

            if ($isApiRequest) {
                // Return JSON error for API requests
                header('Content-Type: application/json');
                echo json_encode([
                    'success' => false,
                    'message' => $e->getMessage(),
                    'error' => $e->getMessage(),
                    'code' => $code,
                ]);
            } else {
                // Return HTML error for web requests
                echo $this->screen->renderScreen('_error', [
                    'exception' => $e,
                ]);
            }
        }
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

        $this->session->set('user', $primaryValue);

        return true;
    }

    public function logout()
    {
        $this->user = null;
        $this->session->remove('user');
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
