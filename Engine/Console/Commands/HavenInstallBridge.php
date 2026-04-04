<?php

declare(strict_types=1);

namespace Luxid\Console\Commands;

use Luxid\Console\Command;

/**
 * Bridge command for Haven installation.
 */
class HavenInstallBridge extends Command
{
  protected string $description = 'Install Haven authentication package';

  public function handle(array $argv): int
  {
    // Use autoloading to get the InstallCommand
    if (!class_exists('\\Luxid\\Haven\\Console\\InstallCommand')) {
      $this->error('Haven InstallCommand not found.');
      $this->line('');
      $this->line('Make sure luxid/haven is installed:');
      $this->line('  composer require luxid/haven');
      $this->line('');
      return 1;
    }

    try {
      $command = new \Luxid\Haven\Console\InstallCommand();
      return $command->handle($argv);
    } catch (\Throwable $e) {
      $this->error('Failed to run install command: ' . $e->getMessage());
      return 1;
    }
  }
}
