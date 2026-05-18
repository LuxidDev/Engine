<?php

namespace Luxid\Console\Commands;

use Luxid\Console\Command;

class FrankenInstallCommand extends Command
{
    protected string $description = 'Install FrankenPHP binary and worker file';

    public function handle(array $argv): int
    {
        $this->parseArguments($argv);
        $projectRoot = $this->getProjectRoot();

        $this->line("Installing FrankenPHP for Luxid...");
        $this->line("");

        // Download FrankenPHP binary
        $os = php_uname('s');
        $arch = php_uname('m');

        $binary = "frankenphp-{$os}-{$arch}";
        $downloadUrl = "https://github.com/dunglas/frankenphp/releases/latest/download/{$binary}";

        $this->line("Downloading from: {$downloadUrl}");

        $frankenphpPath = $projectRoot . '/frankenphp';
        $cmd = "curl -L " . escapeshellarg($downloadUrl) . " -o " . escapeshellarg($frankenphpPath);
        exec($cmd, $output, $returnCode);

        if ($returnCode !== 0) {
            $this->error("Failed to download FrankenPHP. Please download manually from:");
            $this->line("  https://github.com/dunglas/frankenphp/releases");
            return 1;
        }

        chmod($frankenphpPath, 0755);
        $this->info("✓ FrankenPHP binary downloaded");

        // Create worker file
        $workerContent = <<<'PHP'
<?php
// frankenphp-worker.php - FrankenPHP worker entry point

use Luxid\FrankenPHP\Adapter;

require_once __DIR__ . '/vendor/autoload.php';

// Load environment variables
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
$dotenv->load();

// Load configuration
$config = require_once __DIR__ . '/config/config.php';

// Create adapter (boots Luxid once and keeps in memory)
$adapter = new Adapter(__DIR__, $config);

// Return the request handler for FrankenPHP
return $adapter->getHandler();
PHP;

        file_put_contents($projectRoot . '/frankenphp-worker.php', $workerContent);
        $this->info("✓ Worker file created: frankenphp-worker.php");

        $this->success("FrankenPHP installed successfully!");
        $this->line("");
        $this->line("Usage:");
        $this->line("  php juice franken:serve");
        $this->line("  php juice franken:serve --port=8080 --workers=8");
        $this->line("");
        $this->line("Benchmark:");
        $this->line("  ab -n 10000 -c 100 http://localhost:8080/api/health");

        return 0;
    }
}