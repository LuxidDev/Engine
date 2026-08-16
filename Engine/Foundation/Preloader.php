<?php

declare(strict_types=1);

namespace Luxid\Foundation;

use Throwable;

/**
 * Compiles the application into opcache's shared memory at server start.
 *
 * Without preloading, every PHP-FPM process compiles and links each class the
 * first time it touches one. That is the single largest cost of a cold request:
 * booting the starter took roughly 700µs, of which the kernel itself accounted
 * for under 10µs — the rest was class loading. Preloading moves that work to
 * server start, once, for every worker.
 *
 * Files are compiled rather than required. Requiring links classes eagerly,
 * which is marginally faster still, but aborts the whole preload if any class
 * references a parent or interface that is not preloadable — a single optional
 * dependency then costs the entire benefit. Compiling degrades gracefully.
 *
 * Preloaded files are frozen until the server restarts, so this belongs in
 * production only.
 *
 * @package Luxid\Foundation
 */
final class Preloader
{
    /**
     * Absolute path to the project root.
     */
    private string $root;

    /**
     * Path fragments that exclude a file from preloading.
     *
     * @var list<string>
     */
    private array $ignore = [
        '/tests/',
        '/Tests/',
        '/test/',
        '/vendor/phpunit/',
        '/vendor/sebastian/',
        '/vendor/phar-io/',
        '/vendor/myclabs/',
        '/vendor/theseer/',
        '/vendor/nikic/',
        '/vendor/phpstan/',
        '/vendor/friendsofphp/',
        '/vendor/bin/',
    ];

    /**
     * Files successfully compiled.
     */
    private int $compiled = 0;

    /**
     * Files skipped because they could not be compiled.
     */
    private int $skipped = 0;

    /**
     * @param string $root Absolute path to the project root
     */
    public function __construct(string $root)
    {
        $this->root = rtrim($root, '/');
    }

    /**
     * Exclude any file whose path contains one of the given fragments.
     *
     * @param list<string> $fragments Path fragments to skip
     */
    public function ignoring(array $fragments): self
    {
        $this->ignore = array_merge($this->ignore, $fragments);

        return $this;
    }

    /**
     * Compile everything Composer knows about, plus the application's own code.
     *
     * @return array{compiled: int, skipped: int}
     */
    public function load(): array
    {
        if (!$this->isAvailable()) {
            return ['compiled' => 0, 'skipped' => 0];
        }

        foreach ($this->files() as $file) {
            $this->compile($file);
        }

        return ['compiled' => $this->compiled, 'skipped' => $this->skipped];
    }

    /**
     * Check whether preloading can run in this process.
     *
     * `opcache_compile_file()` exists whenever opcache is loaded, but preloading
     * only has an effect during the preload phase itself.
     */
    public function isAvailable(): bool
    {
        return function_exists('opcache_compile_file');
    }

    /**
     * Collect every file worth preloading.
     *
     * Composer's optimised classmap is the authoritative list when it exists;
     * otherwise the application's own directories are walked directly.
     *
     * @return iterable<string>
     */
    public function files(): iterable
    {
        $classmap = $this->root . '/vendor/composer/autoload_classmap.php';

        if (is_file($classmap)) {
            $map = require $classmap;

            if (is_array($map)) {
                foreach ($map as $file) {
                    if (is_string($file) && !$this->isIgnored($file)) {
                        yield $file;
                    }
                }

                return;
            }
        }

        // No optimised classmap: walk the application's own source instead.
        foreach (['app', 'config', 'routes'] as $directory) {
            yield from $this->walk($this->root . '/' . $directory);
        }
    }

    /**
     * Yield every PHP file beneath a directory.
     *
     * @param string $directory Directory to walk
     *
     * @return iterable<string>
     */
    private function walk(string $directory): iterable
    {
        if (!is_dir($directory)) {
            return;
        }

        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($files as $file) {
            if ($file->isFile() && $file->getExtension() === 'php' && !$this->isIgnored($file->getPathname())) {
                yield $file->getPathname();
            }
        }
    }

    /**
     * Compile one file, tolerating anything that will not compile.
     *
     * @param string $file Absolute path to a PHP file
     */
    private function compile(string $file): void
    {
        try {
            if (@opcache_compile_file($file)) {
                ++$this->compiled;

                return;
            }
        } catch (Throwable) {
            // A file that cannot be compiled in isolation is simply not
            // preloaded; it still loads normally at runtime.
        }

        ++$this->skipped;
    }

    /**
     * Check whether a path is excluded.
     *
     * @param string $file Absolute path to a PHP file
     */
    private function isIgnored(string $file): bool
    {
        foreach ($this->ignore as $fragment) {
            if (str_contains($file, $fragment)) {
                return true;
            }
        }

        return false;
    }
}
