<?php

namespace Luxid\Console\Commands;

use Luxid\Console\Command;
use Rocket\Connection\Connection;
use Rocket\Migration\Migrator;
use Rocket\Migration\Rocket;

class MigrateCommand extends Command
{
  protected string $description = 'Run database migrations';

  public function handle(array $argv): int
  {
    $this->parseArguments($argv);

    try {
      $connection = $this->getDatabaseConnection();

      // Set the connection for Rocket
      Rocket::setConnection($connection);

      $migrationsPath = $this->getMigrationsPath();
      $migrator = new Migrator($connection, $migrationsPath);

      $this->line("🔄 Running migrations...");
      $migrator->run();

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
    // Check if Rocket connection is already initialized
    try {
      $connection = Connection::getInstance();
      return $connection;
    } catch (\RuntimeException $e) {
      // Connection not initialized, try to initialize from config
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

  protected function getMigrationsPath(): string
  {
    return $this->getProjectRoot() . '/migrations';
  }

  protected function getConfigPath(): string
  {
    return $this->getProjectRoot() . '/config';
  }
}
