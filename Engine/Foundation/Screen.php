<?php

declare(strict_types=1);

namespace Luxid\Foundation;

use Luxid\Exceptions\NotFoundException;
use Throwable;

/**
 * Legacy screen renderer.
 *
 * Renders plain `.nova.php` files from `screens/` and wraps them in a frame
 * (layout) from `screens/frames/`. Applications using the `luxid/nova` component
 * engine go through {@see \Luxid\Nodes\Nova} instead; this path remains for
 * projects that predate it.
 *
 * @package Luxid\Foundation
 */
class Screen
{
    /**
     * Placeholder a frame uses to mark where the screen body goes.
     */
    private const CONTENT_MARKER = '|| content ||';

    /**
     * Document title exposed to frames.
     */
    public string $title = '';

    /**
     * Render a screen inside the active frame.
     *
     * @param string               $screen Screen name, relative to `screens/`
     * @param array<string, mixed> $data   Variables extracted into the screen scope
     *
     * @throws NotFoundException When the screen file does not exist
     */
    public function renderScreen(string $screen, array $data = []): string
    {
        return $this->renderContent($this->renderOnlyScreen($screen, $data));
    }

    /**
     * Wrap already rendered content in the active frame.
     *
     * @param string $screenContent Rendered screen body
     */
    public function renderContent(string $screenContent): string
    {
        return str_replace(self::CONTENT_MARKER, $screenContent, $this->frameContent());
    }

    /**
     * Render the active frame.
     *
     * Falls back to the bare content marker when no frame file exists, so a
     * frameless project still renders instead of throwing.
     */
    protected function frameContent(): string
    {
        $frame = Application::$app->action?->frame ?? Application::$app->frame;
        $path = Application::$ROOT_DIR . '/screens/frames/' . $frame . '.nova.php';

        if (!is_file($path)) {
            return self::CONTENT_MARKER;
        }

        return $this->capture($path, ['screen' => $this]);
    }

    /**
     * Render a screen without its frame.
     *
     * @param string               $screen Screen name, relative to `screens/`
     * @param array<string, mixed> $data   Variables extracted into the screen scope
     *
     * @throws NotFoundException When the screen file does not exist
     */
    protected function renderOnlyScreen(string $screen, array $data): string
    {
        $path = Application::$ROOT_DIR . '/screens/' . $screen . '.nova.php';

        if (!is_file($path)) {
            throw new NotFoundException(sprintf('Screen "%s" not found.', $screen));
        }

        return $this->capture($path, $data);
    }

    /**
     * Include a template and capture its output.
     *
     * Uses `include` rather than `include_once` so the same partial can be
     * rendered more than once in a single request, and closes the output buffer
     * on failure so a throwing template cannot leak buffered content.
     *
     * @param string               $path Absolute path to the template
     * @param array<string, mixed> $data Variables extracted into the template scope
     *
     * @throws Throwable Whatever the template threw
     */
    private function capture(string $path, array $data): string
    {
        extract($data, EXTR_SKIP);

        ob_start();

        try {
            include $path;
        } catch (Throwable $e) {
            ob_end_clean();

            throw $e;
        }

        return (string) ob_get_clean();
    }
}
