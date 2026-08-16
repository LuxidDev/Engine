<?php

namespace Luxid\Console\Commands;

use Luxid\Console\Command;

class DbStatusCommand extends Command
{
    protected string $description = 'Show database status and tables';

    public function handle(array $argv): int
    {
        $this->parseArguments($argv);

        $this->line("🗄️  Database Status");
        $this->line(str_repeat("─", 50));

        // Try to connect to database
        try {
            $this->setupApplication();

            // db() opens the connection on demand; the property is null until
            // something actually needs the database.
            $pdo = \Luxid\Foundation\Application::$app->db()->getPdo();

            // Get database info
            $stmt = $pdo->query("SELECT DATABASE() as db_name, VERSION() as version");
            $info = $stmt->fetch(\PDO::FETCH_ASSOC);

            $this->line("📊 \033[1;33mDatabase:\033[0m " . ($info['db_name'] ?? 'Unknown'));
            $this->line("📦 \033[1;33mVersion:\033[0m " . ($info['version'] ?? 'Unknown'));

            // Get tables
            $stmt = $pdo->query("SHOW TABLES");
            $tables = $stmt->fetchAll(\PDO::FETCH_COLUMN);

            $this->line("📋 \033[1;33mTables:\033[0m " . count($tables));

            if (!empty($tables)) {
                $this->line("");
                $this->line("\033[1;34mTable List:\033[0m");
                foreach ($tables as $table) {
                    // Get row count for each table
                    $countStmt = $pdo->query("SELECT COUNT(*) as count FROM `{$table}`");
                    $count = $countStmt->fetch(\PDO::FETCH_ASSOC)['count'];
                    $this->line("  📁 {$table} \033[90m({$count} rows)\033[0m");
                }
            }

            // Check migrations table
            $migrationsTableExists = in_array('migrations', $tables);
            if ($migrationsTableExists) {
                $migStmt = $pdo->query("SELECT migration_id, applied_at FROM migrations ORDER BY applied_at DESC LIMIT 5");
                $migrations = $migStmt->fetchAll(\PDO::FETCH_ASSOC);

                $this->line("");
                $this->line("\033[1;34mRecent Migrations:\033[0m");
                if (!empty($migrations)) {
                    foreach ($migrations as $migration) {
                        $this->line("  🕐 {$migration['migration_id']} \033[90m({$migration['applied_at']})\033[0m");
                    }
                }
            }

            $this->success("Database connection successful!");

        } catch (\Rocket\Connection\ConnectionException | \PDOException $e) {
            // Rocket wraps the driver exception, so both types are caught here.
            $this->error($e->getMessage());
            $this->line("");
            $this->line("\033[1;33m💡 Troubleshooting:\033[0m");
            $this->line("1. Check .env file exists");
            $this->line("2. Verify DB_DSN, DB_USER, DB_PASSWORD");
            $this->line("3. Run: \033[1;32mphp juice db:create\033[0m");
            return 1;
        }

        return 0;
    }

    private function setupApplication(): void
    {
        // Load environment
        $rootPath = $this->getProjectRoot();
        $envFile = $rootPath . '/.env';

        if (file_exists($envFile)) {
            $dotenv = \Dotenv\Dotenv::createImmutable($rootPath);
            $dotenv->load();
        }

        // Create config
        $config = [
            'db' => [
                'dsn' => $_ENV['DB_DSN'] ?? '',
                'user' => $_ENV['DB_USER'] ?? '',
                'password' => $_ENV['DB_PASSWORD'] ?? '',
            ],
        ];

        // Create application instance
        new \Luxid\Foundation\Application($rootPath, $config);
    }
}
