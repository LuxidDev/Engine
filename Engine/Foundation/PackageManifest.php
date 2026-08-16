<?php

declare(strict_types=1);

namespace Luxid\Foundation;

use RuntimeException;
use Throwable;

/**
 * Compiled index of what installed packages contribute to the framework.
 *
 * Packages declare providers and console commands under `extra.luxid` in their
 * composer.json. Collecting those means reading `vendor/composer/installed.json`
 * and decoding it — around 30KB of JSON for even a small project, which cost
 * roughly 170µs on every single request.
 *
 * The result is compiled to a plain PHP file instead. Returning an array literal
 * lets opcache keep it compiled in shared memory, so a warm request pays for an
 * `include` of an already-compiled file rather than a filesystem read plus a
 * JSON parse. The manifest is rebuilt automatically whenever `installed.json`
 * changes, so `composer require` needs no extra step.
 *
 * @package Luxid\Foundation
 */
class PackageManifest
{
    /**
     * Absolute path to the project's vendor directory.
     */
    protected string $vendorPath;

    /**
     * Absolute path to the compiled manifest.
     */
    protected string $manifestPath;

    /**
     * The loaded manifest, or null before first access.
     *
     * @var array{providers: list<class-string>, commands: array<string, class-string>}|null
     */
    protected ?array $manifest = null;

    /**
     * Manifests already loaded in this process, keyed by path.
     *
     * Worker runtimes such as FrankenPHP boot many applications in one process;
     * this keeps the freshness stat and the include to once per path.
     *
     * @var array<string, array{providers: list<class-string>, commands: array<string, class-string>}>
     */
    protected static array $loaded = [];

    /**
     * @param string      $vendorPath   Absolute path to the vendor directory
     * @param string|null $manifestPath Where to write the compiled manifest
     */
    public function __construct(string $vendorPath, ?string $manifestPath = null)
    {
        $this->vendorPath = rtrim($vendorPath, '/');
        $this->manifestPath = $manifestPath ?? $this->vendorPath . '/luxid-manifest.php';
    }

    /**
     * Get the provider classes contributed by installed packages.
     *
     * @return list<class-string>
     */
    public function providers(): array
    {
        return $this->manifest()['providers'];
    }

    /**
     * Get the console commands contributed by installed packages.
     *
     * @return array<string, class-string>
     */
    public function commands(): array
    {
        return $this->manifest()['commands'];
    }

    /**
     * Load the manifest, building it when it is missing or stale.
     *
     * @return array{providers: list<class-string>, commands: array<string, class-string>}
     */
    public function manifest(): array
    {
        if ($this->manifest !== null) {
            return $this->manifest;
        }

        if (isset(self::$loaded[$this->manifestPath])) {
            return $this->manifest = self::$loaded[$this->manifestPath];
        }

        if ($this->isFresh()) {
            $loaded = @include $this->manifestPath;

            if (is_array($loaded) && isset($loaded['providers'], $loaded['commands'])) {
                return $this->manifest = self::$loaded[$this->manifestPath] = $loaded;
            }
        }

        return $this->manifest = self::$loaded[$this->manifestPath] = $this->build();
    }

    /**
     * Check whether the compiled manifest is newer than the installed packages.
     */
    protected function isFresh(): bool
    {
        if (!is_file($this->manifestPath)) {
            return false;
        }

        $installed = $this->installedPath();

        if (!is_file($installed)) {
            return true;
        }

        return filemtime($this->manifestPath) >= filemtime($installed);
    }

    /**
     * Read `installed.json` and compile the manifest.
     *
     * @return array{providers: list<class-string>, commands: array<string, class-string>}
     */
    protected function build(): array
    {
        $manifest = ['providers' => [], 'commands' => []];
        $installed = $this->installedPath();

        if (!is_file($installed)) {
            return $manifest;
        }

        $decoded = json_decode((string) file_get_contents($installed), true);

        if (!is_array($decoded)) {
            return $manifest;
        }

        foreach ($decoded['packages'] ?? $decoded as $package) {
            $extra = $package['extra']['luxid'] ?? null;

            if (!is_array($extra)) {
                continue;
            }

            foreach ($extra['providers'] ?? [] as $provider) {
                if (is_string($provider)) {
                    $manifest['providers'][] = $provider;
                }
            }

            foreach ($extra['commands'] ?? [] as $name => $command) {
                if (is_string($command)) {
                    $manifest['commands'][(string) $name] = $command;
                }
            }
        }

        $manifest['providers'] = array_values(array_unique($manifest['providers']));

        $this->write($manifest);

        return $manifest;
    }

    /**
     * Write the compiled manifest to disk.
     *
     * Written to a temporary file and renamed so a concurrent request never
     * includes a half-written manifest. A failure here is not fatal: the
     * manifest is a cache, and the caller already holds the data it needs.
     *
     * @param array{providers: list<class-string>, commands: array<string, class-string>} $manifest
     */
    protected function write(array $manifest): void
    {
        $directory = dirname($this->manifestPath);

        if (!is_dir($directory) || !is_writable($directory)) {
            return;
        }

        $source = "<?php\n\n// Generated by Luxid. Do not edit; rebuilt when composer packages change.\n\nreturn "
            . var_export($manifest, true)
            . ";\n";

        $temporary = $this->manifestPath . '.' . getmypid() . '.tmp';

        try {
            if (file_put_contents($temporary, $source, LOCK_EX) === false) {
                return;
            }

            rename($temporary, $this->manifestPath);
            $this->invalidateOpcache();
        } catch (Throwable) {
            @unlink($temporary);
        }
    }

    /**
     * Drop the stale compiled copy so the next include sees the new manifest.
     */
    protected function invalidateOpcache(): void
    {
        if (function_exists('opcache_invalidate')) {
            @opcache_invalidate($this->manifestPath, true);
        }
    }

    /**
     * Get the path to Composer's installed package index.
     */
    protected function installedPath(): string
    {
        return $this->vendorPath . '/composer/installed.json';
    }

    /**
     * Delete the compiled manifest, forcing a rebuild on next access.
     *
     * @throws RuntimeException When the manifest exists but cannot be removed
     */
    public function forget(): void
    {
        $this->manifest = null;
        unset(self::$loaded[$this->manifestPath]);

        if (is_file($this->manifestPath) && !@unlink($this->manifestPath)) {
            throw new RuntimeException(sprintf('Could not remove %s', $this->manifestPath));
        }

        $this->invalidateOpcache();
    }

    /**
     * Rebuild the manifest from `installed.json`, ignoring any cached copy.
     *
     * @return array{providers: list<class-string>, commands: array<string, class-string>}
     */
    public function rebuild(): array
    {
        $this->manifest = null;
        unset(self::$loaded[$this->manifestPath]);

        return $this->manifest = self::$loaded[$this->manifestPath] = $this->build();
    }

    /**
     * Discard every in-process manifest, so tests can start clean.
     */
    public static function flush(): void
    {
        self::$loaded = [];
    }

    /**
     * Get the path the manifest is compiled to.
     */
    public function path(): string
    {
        return $this->manifestPath;
    }
}
