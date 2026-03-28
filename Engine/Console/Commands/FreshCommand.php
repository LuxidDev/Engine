<?php
// Engine/Console/Commands/FreshCommand.php

namespace Luxid\Console\Commands;

use Luxid\Console\Command;
use Rocket\Connection\Connection;
use Rocket\Migration\Migrator;
use Rocket\Migration\Rocket;

class FreshCommand extends Command
{
  protected string $description = 'Drop all tables and re-run all migrations';

  public function handle(array $argv): int
  {
    $this->parseArguments($argv);

    $this->line("🧹 Fresh migration: dropping all tables and re-running migrations");

    if (!$this->confirm("This will drop all tables! Are you sure?", false)) {
      $this->line("Operation cancelled");
      return 0;
    }

    try {
      $connection = $this->getDatabaseConnection();

      // Set the connection for Rocket
      Rocket::setConnection($connection);

      $migrationsPath = $this->getMigrationsPath();
      $migrator = new Migrator($connection, $migrationsPath);

      $this->line("🔄 Running fresh migration...");
      $migrator->fresh();

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
    $rootPath = $this->getProjectRoot();
    $envFile = $rootPath . '/.env';

    if (file_exists($envFile)) {
      $dotenv = \Dotenv\Dotenv::createImmutable($rootPath);
      $dotenv->load();
    }

    $configFile = $this->getConfigPath() . '/config.php';
    if (file_exists($configFile)) {
      $config = require $configFile;
      if (isset($config['db'])) {
        Connection::initialize($config['db']);
        return;
      }
    }

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
