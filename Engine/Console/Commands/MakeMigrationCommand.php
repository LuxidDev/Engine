<?php

namespace Luxid\Console\Commands;

use Luxid\Console\Command;

class MakeMigrationCommand extends Command
{
    protected string $description = 'Create a new migration file';

    public function handle(array $argv): int
    {
        $this->parseArguments($argv);

        if (empty($this->args)) {
            $this->error("Migration name is required");
            $this->line("Usage: php juice make:migration <name>");
            $this->line("Examples:");
            $this->line("  php juice make:migration create_users_table");
            $this->line("  php juice make:migration add_email_to_users");
            $this->line("  php juice make:migration create_products_table");
            return 1;
        }

        $migrationName = $this->args[0];
        $this->createMigration($migrationName);

        return 0;
    }

    private function createMigration(string $migrationName): void
    {
        $this->line("⚡ Creating migration...");

        // Get next migration number
        $nextNumber = $this->getNextMigrationNumber();
        $className = 'm' . $nextNumber . '_' . $this->sanitizeMigrationName($migrationName);
        $fileName = $className . '.php';
        $filePath = $this->getMigrationsPath() . '/' . $fileName;

        // Determine migration type based on name
        $migrationTemplate = $this->getMigrationTemplate($migrationName, $className);

        if ($this->createFile($filePath, $migrationTemplate)) {
            $relativePath = str_replace($this->getProjectRoot() . '/', '', $filePath);
            $this->success("Migration created successfully!");
            $this->line("📁 Location: \033[1;34m{$relativePath}\033[0m");

            $this->line("");
            $this->line("\033[1;33m💡 Next steps:\033[0m");
            $this->line("1. Edit the migration file to customize the schema");
            $this->line("2. Run migration: \033[1;32mphp juice db:migrate\033[0m");
            $this->line("3. Rollback if needed: \033[1;32mphp juice db:rollback\033[0m");
        } else {
            $this->error("Failed to create migration");
        }
    }

    private function getMigrationTemplate(string $migrationName, string $className): string
    {
        // Check migration type based on naming convention
        if (strpos($migrationName, 'create_') === 0 && strpos($migrationName, '_table') !== false) {
            // Create table migration
            $tableName = str_replace(['create_', '_table'], '', $migrationName);
            return $this->createTableTemplate($className, $tableName);
        } elseif (strpos($migrationName, 'add_') === 0 && strpos($migrationName, '_to_') !== false) {
            // Add column migration
            preg_match('/add_(.+)_to_(.+)/', $migrationName, $matches);
            if (count($matches) === 3) {
                $column = $matches[1];
                $tableName = $matches[2];
                return $this->addColumnTemplate($className, $tableName, $column);
            }
        } elseif (strpos($migrationName, 'drop_') === 0 && strpos($migrationName, '_from_') !== false) {
            // Drop column migration
            preg_match('/drop_(.+)_from_(.+)/', $migrationName, $matches);
            if (count($matches) === 3) {
                $column = $matches[1];
                $tableName = $matches[2];
                return $this->dropColumnTemplate($className, $tableName, $column);
            }
        } elseif (strpos($migrationName, 'alter_') === 0) {
            // Alter table migration
            return $this->alterTableTemplate($className);
        }

        // Default generic migration
        return $this->genericTemplate($className);
    }

    private function createTableTemplate(string $className, string $tableName): string
    {
        return <<<PHP
<?php

use Rocket\Migration\Migration;
use Rocket\Migration\Rocket;
use Rocket\Migration\Column;

class {$className} extends Migration
{
    public function up(): void
    {
        Rocket::table('{$tableName}', function(\$column) {
            \$column->id('id');
            \$column->string('name');
            \$column->timestamps();
        });
    }
    
    public function down(): void
    {
        Rocket::drop('{$tableName}');
    }
}
PHP;
    }

    private function addColumnTemplate(string $className, string $tableName, string $column): string
    {
        $columnType = $this->guessColumnType($column);
        
        return <<<PHP
<?php

use Rocket\Migration\Migration;
use Rocket\Migration\Rocket;
use Rocket\Migration\Column;

class {$className} extends Migration
{
    public function up(): void
    {
        // Add column using Rocket's schema builder
        Rocket::table('{$tableName}', function(\$column) {
            \$column->{$columnType}('{$column}');
        });
        
        // Alternative: Use raw SQL if needed
        // \$this->db->execute("ALTER TABLE {$tableName} ADD COLUMN {$column} {$columnType}");
    }
    
    public function down(): void
    {
        Rocket::table('{$tableName}', function(\$column) {
            // Remove column (Rocket doesn't have direct drop column, use raw SQL)
        });
        
        // Raw SQL rollback
        \$this->db->execute("ALTER TABLE {$tableName} DROP COLUMN {$column}");
    }
}
PHP;
    }

    private function dropColumnTemplate(string $className, string $tableName, string $column): string
    {
        return <<<PHP
<?php

use Rocket\Migration\Migration;
use Rocket\Migration\Rocket;

class {$className} extends Migration
{
    public function up(): void
    {
        // Drop column using raw SQL
        \$this->db->execute("ALTER TABLE {$tableName} DROP COLUMN {$column}");
    }
    
    public function down(): void
    {
        // You need to know the column definition to add it back
        // This is a placeholder - update with the actual column definition
        \$columnType = "VARCHAR(255)";
        \$this->db->execute("ALTER TABLE {$tableName} ADD COLUMN {$column} {$columnType}");
    }
}
PHP;
    }

    private function alterTableTemplate(string $className): string
    {
        return <<<PHP
<?php

use Rocket\Migration\Migration;
use Rocket\Migration\Rocket;

class {$className} extends Migration
{
    public function up(): void
    {
        // Write your migration logic here
        // Example: Modify column, rename table, etc.
        
        // Using raw SQL
        // \$this->db->execute("ALTER TABLE table_name MODIFY COLUMN column_name NEW_TYPE");
        
        // Or use Rocket's schema builder for new tables only
    }
    
    public function down(): void
    {
        // Rollback your changes
        // \$this->db->execute("ALTER TABLE table_name MODIFY COLUMN column_name OLD_TYPE");
    }
}
PHP;
    }

    private function genericTemplate(string $className): string
    {
        return <<<PHP
<?php

use Rocket\Migration\Migration;
use Rocket\Migration\Rocket;

class {$className} extends Migration
{
    public function up(): void
    {
        // Write your migration logic here
        // Example: \$this->db->execute("CREATE TABLE ...");
        // Example: Rocket::table('table_name', function(\$column) { ... });
    }
    
    public function down(): void
    {
        // Rollback your migration logic here
        // Example: \$this->db->execute("DROP TABLE ...");
    }
}
PHP;
    }

    private function guessColumnType(string $columnName): string
    {
        // Simple type guessing based on column name
        if (strpos($columnName, 'email') !== false) {
            return 'string';
        } elseif (strpos($columnName, 'password') !== false) {
            return 'string';
        } elseif (strpos($columnName, 'name') !== false) {
            return 'string';
        } elseif (strpos($columnName, 'description') !== false) {
            return 'text';
        elseif (strpos($columnName, 'content') !== false) {
            return 'text';
        } elseif (strpos($columnName, 'price') !== false || strpos($columnName, 'amount') !== false) {
            return 'decimal';
        } elseif (strpos($columnName, 'quantity') !== false || strpos($columnName, 'count') !== false) {
            return 'integer';
        } elseif (strpos($columnName, 'is_') === 0 || strpos($columnName, 'has_') === 0) {
            return 'boolean';
        } elseif (strpos($columnName, 'date') !== false) {
            return 'datetime';
        } else {
            return 'string';
        }
    }

    private function getNextMigrationNumber(): string
    {
        $migrationsPath = $this->getMigrationsPath();
        $this->ensureDirectory($migrationsPath);

        $files = scandir($migrationsPath);
        $maxNumber = 0;

        foreach ($files as $file) {
            if (preg_match('/^m(\d{5})_/', $file, $matches)) {
                $number = (int) $matches[1];
                if ($number > $maxNumber) {
                    $maxNumber = $number;
                }
            }
        }

        return str_pad($maxNumber + 1, 5, '0', STR_PAD_LEFT);
    }

    private function sanitizeMigrationName(string $name): string
    {
        // Replace non-alphanumeric with underscores
        $name = preg_replace('/[^a-zA-Z0-9]/', '_', $name);
        // Remove multiple underscores
        $name = preg_replace('/_+/', '_', $name);
        // Remove leading/trailing underscores
        $name = trim($name, '_');

        return $name;
    }
}
