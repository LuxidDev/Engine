<?php

declare(strict_types=1);

namespace Luxid\Console\Commands;

use Luxid\Console\Command;
use Luxid\Foundation\PackageManifest;
use Luxid\Foundation\Preloader;

/**
 * Prepares an application for production.
 *
 * Rebuilds the package manifest, dumps an optimised autoloader and reports
 * whether opcache is configured to keep the result. Everything here is safe to
 * run repeatedly and safe to run in development, though the settings it reports
 * on belong in production.
 *
 * @package Luxid\Console\Commands
 */
class OptimizeCommand extends Command
{
    protected string $description = 'Prepare the application for production';

    /**
     * Opcache settings that materially change request cost.
     *
     * @var array<string, array{want: string, why: string}>
     */
    private const RECOMMENDED = [
        'opcache.enable' => [
            'want' => '1',
            'why' => 'compile each file once instead of on every request',
        ],
        'opcache.validate_timestamps' => [
            'want' => '0',
            'why' => 'stop stat-ing every file on every request',
        ],
        'opcache.memory_consumption' => [
            'want' => '256',
            'why' => 'hold the whole application without evicting',
        ],
        'opcache.max_accelerated_files' => [
            'want' => '20000',
            'why' => 'hold every class without evicting',
        ],
        'opcache.preload' => [
            'want' => '<project>/preload.php',
            'why' => 'link classes at server start rather than per process',
        ],
    ];

    /**
     * Run the command.
     *
     * @param list<string> $argv Raw console arguments
     */
    public function handle(array $argv): int
    {
        $this->parseArguments($argv);

        $this->line('⚡ Optimizing application');
        $this->line(str_repeat('─', 60));

        $this->rebuildManifest();
        $this->dumpAutoloader();
        $this->reportPreloadScript();
        $this->reportOpcache();

        $this->line('');
        $this->success('Optimization complete.');

        return 0;
    }

    /**
     * Recompile the package manifest from Composer's installed packages.
     */
    private function rebuildManifest(): void
    {
        $manifest = new PackageManifest($this->getProjectRoot() . '/vendor');
        $built = $manifest->rebuild();

        $this->info(sprintf(
            'Package manifest rebuilt: %d provider(s), %d command(s)',
            count($built['providers']),
            count($built['commands'])
        ));
    }

    /**
     * Ask Composer for a classmap-optimised autoloader.
     */
    private function dumpAutoloader(): void
    {
        if (!is_file($this->getProjectRoot() . '/composer.json')) {
            $this->warning('No composer.json found; skipping autoloader dump');

            return;
        }

        exec('composer dump-autoload --optimize --no-interaction 2>&1', $output, $status);

        if ($status === 0) {
            $this->info('Autoloader optimized (classmap)');

            return;
        }

        $this->warning('Could not dump the autoloader; run: composer dump-autoload --optimize');
    }

    /**
     * Check that a preload script exists, and offer to create one.
     */
    private function reportPreloadScript(): void
    {
        $path = $this->getProjectRoot() . '/preload.php';

        if (is_file($path)) {
            $this->info('Preload script present: preload.php');

            return;
        }

        $this->warning('No preload.php found');

        if (!$this->confirm('Create one now?', true)) {
            return;
        }

        file_put_contents($path, $this->preloadStub());
        $this->info('Created preload.php');
    }

    /**
     * Report which opcache settings differ from the recommendation.
     */
    private function reportOpcache(): void
    {
        $this->line('');
        $this->line("\033[1;34mOpcache settings\033[0m");

        if (!extension_loaded('Zend OPcache')) {
            $this->error('Opcache is not loaded. This is the single largest win available.');
            $this->line('  Install it, then set the values below in php.ini.');
        }

        foreach (self::RECOMMENDED as $setting => $advice) {
            $current = ini_get($setting);
            $current = $current === false || $current === '' ? '(unset)' : $current;

            // The preload path is per-project, so only its presence is checked.
            $ok = $setting === 'opcache.preload'
                ? $current !== '(unset)'
                : $this->meetsRecommendation($setting, $current, $advice['want']);

            $this->line(sprintf(
                '  %s %-32s %-24s %s',
                $ok ? "\033[32m✓\033[0m" : "\033[33m!\033[0m",
                $setting,
                $current,
                $ok ? '' : "\033[90mwant {$advice['want']} — {$advice['why']}\033[0m"
            ));
        }

        $this->line('');
        $this->line("\033[90mThese belong in production only: with validate_timestamps=0 and\033[0m");
        $this->line("\033[90mpreloading on, code changes need a PHP-FPM reload to take effect.\033[0m");
    }

    /**
     * Check whether a numeric setting meets or exceeds the recommendation.
     *
     * @param string $setting Setting name
     * @param string $current Current value
     * @param string $want    Recommended value
     */
    private function meetsRecommendation(string $setting, string $current, string $want): bool
    {
        if ($setting === 'opcache.validate_timestamps') {
            return $current === '0' || $current === '';
        }

        if (!is_numeric($current) || !is_numeric($want)) {
            return $current === $want;
        }

        return (float) $current >= (float) $want;
    }

    /**
     * Get the contents of a default preload script.
     */
    private function preloadStub(): string
    {
        return <<<'PHP'
        <?php

        declare(strict_types=1);

        /**
         * Opcache preload script.
         *
         * Enable in php.ini:
         *
         *     opcache.preload=/path/to/your-app/preload.php
         *     opcache.preload_user=www-data
         *
         * Production only: preloaded files are frozen until PHP-FPM restarts.
         */

        require __DIR__ . '/vendor/autoload.php';

        $result = (new Luxid\Foundation\Preloader(__DIR__))->load();

        if (PHP_SAPI === 'cli') {
            printf("Luxid preloaded %d files (%d skipped).%s", $result['compiled'], $result['skipped'], PHP_EOL);
        }

        PHP;
    }
}
