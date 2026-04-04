<?php

namespace Luxid\Routing;

use Luxid\Foundation\Application;
use Luxid\Middleware\AuthMiddleware;
use Luxid\Middleware\BaseMiddleware;
use Luxid\Middleware\PublicMiddleware;

/**
 * RouteBuilder provides a fluent, action-first DSL for routing
 */
class RouteBuilder
{
  private string $name;
  private string $method;
  private string $path;
  private $callback;
  private Router $router;
  private array $middleware = [];
  private bool $securityConfigured = false;
  private bool $routeRegistered = false;
  private bool $inheritGroupSecurity = true;

  public function __construct(Router $router, string $name)
  {
    $this->router = $router;
    $this->name = $name;
  }

  /**
   * Define a GET route
   */
  public function get(string $path): self
  {
    $this->method = 'get';
    $this->path = $path;
    return $this;
  }

  /**
   * Define a POST route
   */
  public function post(string $path): self
  {
    $this->method = 'post';
    $this->path = $path;
    return $this;
  }

  /**
   * Define a PUT route
   */
  public function put(string $path): self
  {
    $this->method = 'put';
    $this->path = $path;
    return $this;
  }

  /**
   * Define a PATCH route
   */
  public function patch(string $path): self
  {
    $this->method = 'patch';
    $this->path = $path;
    return $this;
  }

  /**
   * Define a DELETE route
   */
  public function delete(string $path): self
  {
    $this->method = 'delete';
    $this->path = $path;
    return $this;
  }

  /**
   * Bind the route to an Action class
   */
  public function uses(string $actionClass, string $method = 'index'): self
  {
    $this->callback = [$actionClass, $method];
    return $this;
  }

  /**
   * Disable group security inheritance for this route
   */
  public function withoutInheritance(): self
  {
    $this->inheritGroupSecurity = false;
    return $this;
  }

  /**
   * Mark route as secure (requires authentication)
   */
  public function secure(array $publicActivities = []): self
  {
    return $this->auth($publicActivities);
  }

  /**
   * Mark route as requiring authentication
   */
  public function auth(array $publicActivities = []): self
  {
    // Check if an auth manager is registered in the container
    $authManager = null;

    if (isset(Application::$app) && Application::$app->auth) {
      $authManager = Application::$app->auth;
    } elseif (class_exists('Luxid\Haven\Haven')) {
      try {
        $havenClass = 'Luxid\Haven\Haven';
        if (method_exists($havenClass, 'getManager')) {
          $authManager = $havenClass::getManager();
        }
      } catch (\Exception $e) {
        // Haven not initialized
      }
    }

    if ($authManager) {
      $this->with(new AuthMiddleware($authManager, $publicActivities));
    } else {
      $this->addAuthMiddleware($publicActivities);
    }

    $this->securityConfigured = true;
    $this->registerRouteIfNeeded();
    return $this;
  }

  /**
   * Mark route as completely public (no auth checks at all)
   * Uses PublicMiddleware
   */
  public function public(): self
  {
    $this->addPublicMiddleware();
    $this->securityConfigured = true;
    $this->registerRouteIfNeeded();
    return $this;
  }

  /**
   * Mark route as open with specific public activities
   * Uses AuthMiddleware with public activities list
   */
  public function open(array $activities = []): self
  {
    $this->addPublicMiddleware();
    $this->securityConfigured = true;
    $this->registerRouteIfNeeded();
    return $this;
  }

  /**
   * Add generic middleware to the route
   */
  public function with($middleware): self
  {
    if (is_string($middleware)) {
      if (!class_exists($middleware)) {
        throw new \InvalidArgumentException(
          sprintf('Middleware class "%s" does not exist', $middleware)
        );
      }

      if (!is_subclass_of($middleware, BaseMiddleware::class)) {
        throw new \InvalidArgumentException(
          sprintf('Middleware "%s" must extend BaseMiddleware', $middleware)
        );
      }

      $middleware = new $middleware();
    }

    if (!$middleware instanceof BaseMiddleware) {
      throw new \InvalidArgumentException(
        'Middleware must be an instance of BaseMiddleware or a class name string'
      );
    }

    $this->middleware[] = $middleware;
    return $this;
  }

  /**
   * Force route registration (for CLI commands)
   */
  public function register(): self
  {
    $this->registerRouteIfNeeded();
    return $this;
  }

  /**
   * Register the route with the router
   */
  private function registerRouteIfNeeded(): void
  {
    if ($this->routeRegistered) {
      return;
    }

    // Validate route is complete before registering
    if (!isset($this->method) || !isset($this->path) || !isset($this->callback)) {
      throw new \RuntimeException(
        sprintf('Route "%s" definition incomplete. Must specify method, path, and uses()', $this->name)
      );
    }

    // Apply group security inheritance if enabled
    if (!$this->securityConfigured && $this->inheritGroupSecurity) {
      $groupInfo = $this->getCurrentGroupInfo();

      if ($groupInfo && $groupInfo['auth'] === true) {
        // Auto-apply auth from group
        $this->addAuthMiddleware([]);
        $this->securityConfigured = true;
      } elseif ($groupInfo && $groupInfo['open'] !== null) {
        // Auto-apply open from group
        $this->addAuthMiddleware($groupInfo['open']);
        $this->securityConfigured = true;
      }
    }

    // For CLI commands, allow routes without explicit security
    $isCli = php_sapi_name() === 'cli';
    if (!$this->securityConfigured && !$isCli) {
      throw new \RuntimeException(
        sprintf(
          'Route "%s" must explicitly declare security with secure() or open()',
          $this->name
        )
      );
    }

    // Register the route with the router
    call_user_func([$this->router, $this->method], $this->path, $this->callback);

    // Attach route-specific middleware
    foreach ($this->middleware as $middleware) {
      $this->router->middleware($middleware);
    }

    $this->routeRegistered = true;
  }

  /**
   * Get current group information from router
   */
  private function getCurrentGroupInfo(): ?array
  {
    $groupStack = $this->router->getGroupStack();

    if (empty($groupStack)) {
      return null;
    }

    $currentGroup = end($groupStack);

    return [
      'auth' => $currentGroup['auth'] ?? false,
      'open' => $currentGroup['open'] ?? null
    ];
  }

  /**
   * Extract activity name from callback
   */
  private function extractActivityFromCallback(): string
  {
    if (is_array($this->callback) && isset($this->callback[1])) {
      return $this->callback[1];
    }

    return 'index';
  }

  /**
   * Add AuthMiddleware with appropriate configuration
   * This is the legacy method for backward compatibility
   */
  private function addAuthMiddleware(array $publicActivities = []): void
  {
    // For backward compatibility, create AuthMiddleware without AuthManager
    // The AuthMiddleware will need to handle null AuthManager gracefully
    $this->middleware[] = new AuthMiddleware($publicActivities);
  }

  /**
   * Add PublicMiddleware for truly public routes
   */
  private function addPublicMiddleware(): void
  {
    $this->middleware[] = new PublicMiddleware();
  }

  /**
   * Get the route name
   */
  public function getName(): string
  {
    return $this->name;
  }
}
