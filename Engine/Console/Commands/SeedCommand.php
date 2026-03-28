<?php

namespace Luxid\Console\Commands;

use Luxid\Console\Command;
use Rocket\Connection\Connection;
use Rocket\Seed\SeederRunner;
use Rocket\Migration\Rocket;

class SeedCommand extends Command
{
  protected string $description = 'Run database seeders';

  public function handle(array $argv): int
  {
    $this->parseArguments($argv);

    $seederClass = $this->args[0] ?? null;

    try {
      $connection = $this->getDatabaseConnection();

      // Set the connection for Rocket
      Rocket::setConnection($connection);

      $seedsPath = $this->getSeedsPath();

      if (!is_dir($seedsPath)) {
        $this->error("Seeds directory not found: {$seedsPath}");
        $this->line("Run: php juice make:seeder to create your first seeder");
        return 1;
      }

      $runner = new SeederRunner($connection, $seedsPath);

      $this->line("🌱 Seeding database...\n");

      if ($seederClass) {
        $this->line("Running seeder: {$seederClass}");
        $runner->run($seederClass);
      } else {
        $runner->run();
      }

      $this->line("\n✅ Seeding completed!");

      return 0;
    } catch (\Exception $e) {
      $this->error("Error: " . $e->getMessage());
      $this->line("📋 Stack trace:");
      $this->line($e->getTraceAsString());
      return 1;
    }
  }

  protected function getDatabaseConnection(): Connection
  {
    try {
      return Connection::getInstance();
    } catch (\RuntimeException $e) {
      $this->initializeConnection();
      return Connection::getInstance();
    }
  }

  protected function initializeConnection(): void
  {
    // Load environment
    $rootPath = $this->getProjectRoot();
    $envFile = $rootPath . '/.env';

    if (file_exists($envFile)) {
      $dotenv = \Dotenv\Dotenv::createImmutable($rootPath);
      $dotenv->load();
    }

    // Get database config
    $configFile = $this->getConfigPath() . '/config.php';
    if (file_exists($configFile)) {
      $config = require $configFile;
      if (isset($config['db'])) {
        Connection::initialize($config['db']);
        return;
      }
    }

    // Fallback to environment variables
    $dsn = $_ENV['DB_DSN'] ?? '';
    $user = $_ENV['DB_USER'] ?? 'root';
    $password = $_ENV['DB_PASSWORD'] ?? '';

    Connection::initialize([
      'dsn' => $dsn,
      'user' => $user,
      'password' => $password,
    ]);
  }

  protected function getSeedsPath(): string
  {
    return $this->getProjectRoot() . '/seeds';
  }

  protected function getConfigPath(): string
  {
    return $this->getProjectRoot() . '/config';
  }
}
