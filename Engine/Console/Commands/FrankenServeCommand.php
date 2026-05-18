<?php

namespace Luxid\Console\Commands;

use Luxid\Console\Command;

class FrankenServeCommand extends Command
{
    protected string $description = 'Start FrankenPHP server for high performance';

    public function handle(array $argv): int
    {
        $this->parseArguments($argv);

        $host = $this->options['host'] ?? '0.0.0.0';
        $port = $this->options['port'] ?? 8080;
        $workers = $this->options['workers'] ?? 4;

        $projectRoot = $this->getProjectRoot();
        $frankenphp = $projectRoot . '/frankenphp';
        $workerFile = $projectRoot . '/frankenphp-worker.php';

        // Check if FrankenPHP binary exists
        if (!file_exists($frankenphp)) {
            $this->error("FrankenPHP binary not found. Download from: https://github.com/dunglas/frankenphp/releases");
            $this->line("");
            $this->line("Quick download:");
            $this->line("  curl -L https://github.com/dunglas/frankenphp/releases/latest/download/frankenphp-linux-x86_64 -o frankenphp");
            $this->line("  chmod +x frankenphp");
            return 1;
        }

        // Check if worker file exists
        if (!file_exists($workerFile)) {
            $this->error("FrankenPHP worker file not found. Run: php juice franken:install");
            return 1;
        }

        $this->line("Starting Luxid with FrankenPHP...");
        $this->line("Server: http://{$host}:{$port}");
        $this->line("Workers: {$workers}");
        $this->line("Press Ctrl+C to stop");
        $this->line("");

        // Run FrankenPHP
        passthru("{$frankenphp} php-server --worker {$workerFile} --listen {$host}:{$port}");

        return 0;
    }
}