<?php

namespace Luxid\Nodes;

use Luxid\Foundation\Application;

class Nova
{
  /**
   * Get the current Screen instance (legacy)
   */
  protected static function instance()
  {
    if (!Application::$app || !Application::$app->screen) {
      throw new \RuntimeException("Nova/Screen instance is not available.");
    }

    return Application::$app->screen;
  }

  /**
   * Check if luxid/nova is available
   */
  protected static function hasNovaPackage(): bool
  {
    return class_exists('Luxid\Nova\ComponentManager');
  }

  /**
   * Check if a component exists in nova/pages
   */
  protected static function isNovaComponent(string $name): bool
  {
    if (!self::hasNovaPackage()) {
      return false;
    }

    // Convert dot notation to path
    $path = str_replace('.', '/', $name);
    $componentName = 'pages/' . $path;

    return \Luxid\Nova\ComponentManager::has($componentName);
  }

  /**
   * Render a Nova component using luxid/nova
   */
  protected static function renderNovaComponent(string $name, array $data = []): string
  {
    $path = str_replace('.', '/', $name);
    $componentName = 'pages/' . $path;

    // Use the global nova() function from luxid/nova
    return \nova($componentName, $data);
  }

  /**
   * Render a screen or component
   * 
   * This method intelligently chooses between:
   * - New reactive Nova components (if luxid/nova is installed)
   * - Legacy static screens (fallback)
   */
  public static function render(string $screen, array $data = []): string
  {
    // Try to use luxid/nova if available and component exists
    if (self::isNovaComponent($screen)) {
      return self::renderNovaComponent($screen, $data);
    }

    // Fall back to legacy static screens
    return self::instance()->renderScreen($screen, $data);
  }

  /**
   * Render just content without frame
   */
  public static function content(string $screenContent): string
  {
    // If it's a Nova component name
    if (self::isNovaComponent($screenContent)) {
      return self::renderNovaComponent($screenContent, []);
    }

    // Legacy content rendering
    return self::instance()->renderContent($screenContent);
  }
}
