<?php

namespace Luxid\Console\Commands;

use Luxid\Console\Command;
use Luxid\Foundation\Application;

class RoutesCommand extends Command
{
    protected string $description = 'List all registered routes';

    public function handle(array $argv): int
    {
        $this->parseArguments($argv);

        $this->line("🛣️  Registered Routes");
        $this->line(str_repeat("─", 80));

        $routes = $this->loadRoutesSafely();

        if (empty($routes)) {
            $this->warning("No routes found");
            $this->line("Create routes in: \033[1;34m" . $this->getRoutesPath() . "/api.php\033[0m");
            return 0;
        }

        $tableRows = [];
        foreach ($routes as $route) {
            $tableRows[] = [
                $route['method'],
                $route['path'],
                $route['handler'],
                $route['middleware']
            ];
        }

        $this->table(['Method', 'Path', 'Handler', 'Middleware'], $tableRows);

        $this->line("");
        $this->info("Total: " . count($routes) . " route(s)");

        return 0;
    }

    /**
     * Get every route file in the project's routes directory.
     *
     * The inspector used to read only `api.php`, so web routes never appeared
     * in its output.
     *
     * @return list<string> Absolute paths, in a stable order
     */
    private function getRouteFiles(): array
    {
        $files = glob($this->getRoutesPath() . '/*.php') ?: [];
        sort($files);

        return $files;
    }

    private function loadRoutesSafely(): array
    {
        $routes = [];
        $routeFiles = $this->getRouteFiles();

        if ($routeFiles === []) {
            return $routes;
        }

        try {
            // First, ensure autoloader is loaded
            $autoloadFile = $this->getProjectRoot() . '/vendor/autoload.php';
            if (!file_exists($autoloadFile)) {
                $this->warning("Composer autoloader not found. Run: composer install");
                return [];
            }

            require_once $autoloadFile;

            // Load environment variables if .env exists
            $envFile = $this->getProjectRoot() . '/.env';
            if (file_exists($envFile)) {
                $dotenv = \Dotenv\Dotenv::createImmutable($this->getProjectRoot());
                $dotenv->safeLoad();
            }

            // Try to load the config file
            $configFile = $this->getConfigPath() . '/config.php';
            $config = [];

            if (file_exists($configFile)) {
                $config = require $configFile;
            }

            // Use a mock database config for CLI
            $config['db'] = [
                'dsn' => 'mysql:host=127.0.0.1;dbname=test',
                'user' => 'root',
                'password' => ''
            ];

            // Set a default userClass if not specified
            if (!isset($config['userClass'])) {
                $config['userClass'] = 'App\Models\User';
            }

            // IMPORTANT: Initialize Application::$app before requiring routes
            $this->initializeApplicationForCli($config);

            // Load every route file so web and API routes are both reported.
            foreach ($routeFiles as $routeFile) {
                require $routeFile;
            }

            // Get routes from the global Application instance
            if (isset(Application::$app) && Application::$app !== null) {
                $routerRoutes = Application::$app->router->getRoutesForInspection();

                // Format routes for display
                foreach ($routerRoutes as $routerRoute) {
                    $callback = $routerRoute['callback'];
                    $middleware = $routerRoute['middleware'];

                    // Format the handler for display
                    $handler = $this->formatHandler($callback);

                    // Format middleware for display
                    $middlewareInfo = $this->formatMiddleware($middleware);

                    $routes[] = [
                        'method' => strtoupper($routerRoute['method']),
                        'path' => $routerRoute['path'],
                        'handler' => $handler,
                        'middleware' => $middlewareInfo
                    ];
                }
            }

            // Sort routes by path for better readability
            usort($routes, function($a, $b) {
                return strcmp($a['path'], $b['path']);
            });

        } catch (\Exception $e) {
            $this->warning("Error loading routes: " . $e->getMessage());
            $this->line("Trying fallback parsing...");

            // Try fallback file parsing
            $routes = $this->parseRoutesFromFile($routesFile);
        }

        return $routes;
    }

    /**
     * Initialize Application for CLI without database connection
     */
    private function initializeApplicationForCli(array $config): void
    {
        // Create the application instance using CliApplication
        new \Luxid\Console\CliApplication($this->getProjectRoot(), $config);
    }


    /**
     * Fallback: Parse routes directly from file
     */
    private function parseRoutesFromFile(string $routesFile): array
    {
        $routes = [];
        $content = file_get_contents($routesFile);

        // Look for route() calls
        preg_match_all('/route\(["\']([^"\']+)["\']\)\s*->([^;]+);/s', $content, $matches, PREG_SET_ORDER);

        foreach ($matches as $match) {
            $name = $match[1];
            $chain = $match[2];

            // Extract method and path
            if (preg_match('/->(get|post|put|patch|delete)\(["\']([^"\']+)["\']\)/', $chain, $methodMatch)) {
                $method = strtoupper($methodMatch[1]);
                $path = $methodMatch[2];

                // Extract uses
                if (preg_match('/->uses\(([^,]+),\s*["\']([^"\']+)["\']\)/', $chain, $usesMatch)) {
                    $action = trim($usesMatch[1]);
                    $actionMethod = $usesMatch[2];

                    // Check if has auth/open
                    $hasAuth = strpos($chain, '->auth()') !== false ||
                               strpos($chain, '->secure()') !== false;
                    $hasOpen = strpos($chain, '->open(') !== false ||
                               strpos($chain, '->public()') !== false;

                    $middleware = 'None';
                    if ($hasAuth) {
                        $middleware = 'AuthMiddleware';
                    } elseif ($hasOpen) {
                        $middleware = 'PublicMiddleware';
                    }

                    $routes[] = [
                        'method' => $method,
                        'path' => $path,
                        'handler' => '[' . $action . '::class, \'' . $actionMethod . '\']',
                        'middleware' => $middleware
                    ];
                }
            }
        }

        return $routes;
    }

    private function loadRoutesFromRouter(): array
    {
        $routes = [];
        $routeFiles = $this->getRouteFiles();

        if ($routeFiles === []) {
            return $routes;
        }

        try {
            // First, ensure autoloader is loaded
            $autoloadFile = $this->getProjectRoot() . '/vendor/autoload.php';
            if (!file_exists($autoloadFile)) {
                $this->warning("Composer autoloader not found. Run: composer install");
                return $this->loadRoutesFromFile();
            }

            require_once $autoloadFile;

            // We need to bootstrap the application to load routes properly
            // Load environment variables if .env exists
            $envFile = $this->getProjectRoot() . '/.env';
            if (file_exists($envFile)) {
                $dotenv = \Dotenv\Dotenv::createImmutable($this->getProjectRoot());
                $dotenv->safeLoad();
            }

            // Try to load the config file to get userClass
            $configFile = $this->getConfigPath() . '/config.php';
            $userClass = null;

            if (file_exists($configFile)) {
                $config = require $configFile;
                $userClass = $config['userClass'] ?? null;
            }

            // If we couldn't get userClass from config, use a default or try to autodetect
            if (!$userClass) {
                // Try to autodetect User class
                $possibleUserClasses = [
                    'App\\Entities\\User',
                    'App\\Models\\User',
                    'App\\User'
                ];

                foreach ($possibleUserClasses as $className) {
                    if (class_exists($className)) {
                        $userClass = $className;
                        break;
                    }
                }

                // If still not found, use a mock class
                if (!$userClass) {
                    // Create a simple mock User class for CLI
                    if (!class_exists('App\\Entities\\User')) {
                        eval('namespace App\\Entities;
                        class User extends \Luxid\ORM\UserEntity {
                            public static function tableName(): string { return "users"; }
                            public static function primaryKey(): string { return "id"; }
                            public function attributes(): array { return []; }
                            public function getDisplayName(): string { return ""; }
                        }');
                    }
                    $userClass = 'App\\Entities\\User';
                }
            }

            // Create a minimal configuration for CLI
            $config = [
                'db' => [
                    'dsn' => $_ENV['DB_DSN'] ?? $_ENV['DB_HOST'] ?? 'mysql:host=localhost;dbname=test',
                    'user' => $_ENV['DB_USER'] ?? 'root',
                    'password' => $_ENV['DB_PASSWORD'] ?? ''
                ],
                'userClass' => $userClass
            ];

            // Create Application instance - this will work in CLI because we have NullSession
            $app = new Application($this->getProjectRoot(), $config);

            // Load every route file - this populates the router
            foreach ($routeFiles as $routeFile) {
                require $routeFile;
            }

            // Get routes from router using the new inspection method
            $routerRoutes = $app->router->getRoutesForInspection();

            // Format routes for display
            foreach ($routerRoutes as $routerRoute) {
                $callback = $routerRoute['callback'];
                $middleware = $routerRoute['middleware'];

                // Format the handler for display
                $handler = $this->formatHandler($callback);

                // Format middleware for display
                $middlewareInfo = $this->formatMiddleware($middleware);

                $routes[] = [
                    'method' => strtoupper($routerRoute['method']),
                    'path' => $routerRoute['path'],
                    'handler' => $handler,
                    'middleware' => $middlewareInfo
                ];
            }

            // Sort routes by path for better readability
            usort($routes, function($a, $b) {
                return strcmp($a['path'], $b['path']);
            });

        } catch (\Exception $e) {
            $this->warning("Could not load routes from router: " . $e->getMessage());
            $this->line("Falling back to file parsing...");

            // Fall back to file parsing if something goes wrong
            $routes = $this->loadRoutesFromFile();
        }

        return $routes;
    }

    /**
     * Format a callback for display
     */
    private function formatHandler($callback): string
    {
        if (is_string($callback)) {
            // Screen name (string callback)
            return $callback;
        } elseif (is_array($callback)) {
            // Action class and method
            $className = is_object($callback[0]) ? get_class($callback[0]) : $callback[0];
            $methodName = $callback[1] ?? '';

            // Shorten class name if it's an App\Actions class
            if (strpos($className, 'App\\Actions\\') === 0) {
                $className = str_replace('App\\Actions\\', '', $className);
            }

            return '[' . $className . '::class, \'' . $methodName . '\']';
        } elseif (is_callable($callback)) {
            // Closure or callable
            if ($callback instanceof \Closure) {
                return 'Closure';
            } else {
                return 'Callable';
            }
        }

        return 'Unknown';
    }

    /**
     * Format middleware array for display
     */
    private function formatMiddleware(array $middleware): string
    {
        if (empty($middleware)) {
            return 'No';
        }

        $middlewareNames = [];
        foreach ($middleware as $mw) {
            $className = get_class($mw);

            // Shorten common Luxid middleware names
            if (strpos($className, 'Luxid\\Middleware\\') === 0) {
                $className = str_replace('Luxid\\Middleware\\', '', $className);
            }

            $middlewareNames[] = $className;
        }

        return implode(', ', $middlewareNames);
    }

    /**
     * Fallback method to parse routes from file (regex-based)
     * Used if we can't load the router
     */
    private function loadRoutesFromFile(): array
    {
        $routes = [];
        $content = '';

        foreach ($this->getRouteFiles() as $routeFile) {
            $content .= file_get_contents($routeFile);
        }

        if ($content === '') {
            return $routes;
        }

        // Clean up content for easier parsing
        $content = str_replace(["\r", "\n", "\t"], ' ', $content);
        $content = preg_replace('/\s+/', ' ', $content);

        // Split by $router-> to find route definitions
        $parts = explode('$router->', $content);

        foreach ($parts as $part) {
            // Look for route definitions
            if (preg_match('/^(get|post|put|patch|delete)\(\s*[\'"]([^\'"]+)[\'"]\s*,\s*([^,)]+)/', $part, $match)) {
                // Check if the full route line has ->middleware(
                $routeLine = '$router->' . substr($part, 0, 100); // Check first 100 chars
                $hasMiddleware = strpos($routeLine, '->middleware(') !== false;

                $routes[] = [
                    'method' => strtoupper($match[1]),
                    'path' => $match[2],
                    'handler' => trim($match[3]),
                    'middleware' => $hasMiddleware ? 'Yes' : 'No'
                ];
            }
        }

        return $routes;
    }
}
