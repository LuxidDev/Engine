<?php

declare(strict_types=1);

namespace Luxid\Nodes;

use Luxid\Foundation\Application;
use Luxid\Foundation\Screen;

/**
 * Static facade for rendering views.
 *
 * Prefers the `luxid/nova` component engine when it is installed and falls back
 * to the legacy {@see Screen} renderer otherwise, so an application can adopt
 * Nova incrementally.
 *
 * @package Luxid\Nodes
 */
class Nova
{
    /**
     * Layout used for pages when the application configures none.
     */
    private const FALLBACK_LAYOUT = 'AppLayout';

    /**
     * Cached `nova/nova.json` contents.
     *
     * @var array<string, mixed>|null
     */
    private static ?array $config = null;

    /**
     * Render a page or component.
     *
     * Pages are wrapped in a layout unless one is explicitly suppressed by
     * passing `layout: null` together with a non-page `$type`.
     *
     * @param string               $view   Component name, dot notation permitted
     * @param array<string, mixed> $data   Props passed to the component
     * @param string               $type   Component directory: `pages`, `components` or `layouts`
     * @param string|null          $layout Layout to wrap a page in, or null for the configured default
     */
    public static function render(string $view, array $data = [], string $type = 'pages', ?string $layout = null): string
    {
        if (!self::hasNovaPackage()) {
            return self::screen()->renderScreen($view, $data);
        }

        if ($type !== 'pages') {
            return self::renderComponent($view, $data, $type);
        }

        $layout ??= self::config()['default_layout'] ?? self::FALLBACK_LAYOUT;

        // Capture the page into the layout's `content` slot, then render the
        // layout around it.
        \Luxid\Nova\Slot::start('content');
        echo self::renderComponent($view, $data, $type);
        \Luxid\Nova\Slot::end();

        return self::renderComponent($layout, $data, 'layouts');
    }

    /**
     * Render content that has already been produced, inside the active frame.
     *
     * @param string $content Rendered body
     */
    public static function content(string $content): string
    {
        return self::screen()->renderContent($content);
    }

    /**
     * Check whether a component is registered.
     *
     * @param string $name Component name, dot notation permitted
     * @param string $type Component directory
     */
    public static function exists(string $name, string $type = 'pages'): bool
    {
        if (!self::hasNovaPackage()) {
            return false;
        }

        return \Luxid\Nova\ComponentManager::has(self::qualify($name, $type));
    }

    /**
     * Check whether the `luxid/nova` package is installed.
     */
    protected static function hasNovaPackage(): bool
    {
        return class_exists(\Luxid\Nova\ComponentManager::class);
    }

    /**
     * Render a registered Nova component.
     *
     * @param string               $name Component name, dot notation permitted
     * @param array<string, mixed> $data Props passed to the component
     * @param string               $type Component directory
     */
    protected static function renderComponent(string $name, array $data, string $type): string
    {
        return \nova(self::qualify($name, $type), $data);
    }

    /**
     * Turn a dotted component name into its registered path.
     *
     * @param string $name Component name, dot notation permitted
     * @param string $type Component directory
     */
    private static function qualify(string $name, string $type): string
    {
        return $type . '/' . str_replace('.', '/', $name);
    }

    /**
     * Read and cache `nova/nova.json`.
     *
     * @return array<string, mixed>
     */
    private static function config(): array
    {
        if (self::$config !== null) {
            return self::$config;
        }

        $path = Application::$ROOT_DIR . '/nova/nova.json';

        if (!is_file($path)) {
            return self::$config = [];
        }

        $decoded = json_decode((string) file_get_contents($path), true);

        return self::$config = is_array($decoded) ? $decoded : [];
    }

    /**
     * Resolve the legacy screen renderer.
     *
     * @throws \RuntimeException When the application has not booted yet
     */
    protected static function screen(): Screen
    {
        if (!isset(Application::$app)) {
            throw new \RuntimeException('No screen renderer available; the application has not booted.');
        }

        return Application::$app->screen;
    }
}
