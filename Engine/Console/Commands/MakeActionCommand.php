<?php

namespace Luxid\Console\Commands;

use Luxid\Console\Command;

class MakeActionCommand extends Command
{
    protected string $description = 'Create a new Action class with Loco-style routing';

    public function handle(array $argv): int
    {
        $this->parseArguments($argv);

        if (empty($this->args)) {
            $this->error('Please provide an action name');
            $this->line('Usage: php juice make:action <ActionName>');
            return 1;
        }

        $actionName = $this->args[0];
        $actionPath = $this->getAppPath() . '/Actions/' . $actionName . '.php';
        $actionNamespace = $this->getNamespaceFromPath($actionPath);
        $actionClass = basename($actionName, '.php');

        // Determine if this is an API or Web action
        $isApi = str_contains($actionName, 'Api') || $this->options['api'] ?? false;

        $content = $this->generateActionContent($actionNamespace, $actionClass, $isApi);

        if ($this->createFile($actionPath, $content)) {
            $this->info("Action created: app/Actions/{$actionName}.php");

            // Ask if they want to register it
            if ($this->confirm('Do you want to register this action in routes file?', true)) {
                $this->registerInRoutes($actionClass, $isApi);
            }

            return 0;
        }

        return 1;
    }

    private function generateActionContent(string $namespace, string $className, bool $isApi): string
    {
        $methodExample = $isApi ?
            'return Response::success([\'message\' => \'Your logic here\']);' :
            'return Nova::render(\'Welcome\', [\'title\' => \'' . $className . '\']);';

        $useStatements = $isApi ?
            "use Luxid\Nodes\Response;\nuse Luxid\Routing\Routes;" :
            "use Luxid\Nodes\Nova;\nuse Luxid\Routing\Routes;";

        $routesMethod = $this->generateRoutesMethod($className, $isApi);

        return <<<PHP
<?php

namespace {$namespace};

use App\Actions\LuxidAction;
{$useStatements}

class {$className} extends LuxidAction
{
    {$routesMethod}

    public function index()
    {
        {$methodExample}
    }
}

PHP;
    }

    private function generateRoutesMethod(string $className, bool $isApi): string
    {
        $methodName = strtolower(str_replace('Action', '', $className));
        $path = $isApi ? "/api/{$methodName}" : "/{$methodName}";

        if ($isApi) {
            return <<<'PHP'
    public static function routes(): Routes
    {
        return Routes::new()
            ->prefix('api')
            ->add('/RESOURCE_NAME', get('index'))
            ->add('/RESOURCE_NAME/{id}', get('show'))
            ->add('/RESOURCE_NAME', post('store'))
            ->add('/RESOURCE_NAME/{id}', put('update'))
            ->add('/RESOURCE_NAME/{id}', delete('destroy'));
    }
PHP;
        }

        return <<<PHP
    public static function routes(): Routes
    {
        return Routes::new()
            ->add('/', get('index'));
    }
PHP;
    }

    private function registerInRoutes(string $className, bool $isApi): void
    {
        $routesFile = $isApi ?
            $this->getProjectRoot() . '/routes/api.php' :
            $this->getProjectRoot() . '/routes/web.php';

        $registrationLine = "{$className}::routes()->register({$className}::class);";
        $useStatement = "use App\\Actions\\{$className};";

        if (!file_exists($routesFile)) {
            $content = "<?php\n\n{$useStatement}\n\n{$registrationLine}\n";
            file_put_contents($routesFile, $content);
            $this->info("Created routes file and registered {$className}");
            return;
        }

        $content = file_get_contents($routesFile);

        if (strpos($content, $registrationLine) !== false) {
            $this->warning("{$className} already registered");
            return;
        }

        if (strpos($content, $useStatement) === false) {
            $content = preg_replace('/^<\?php/', "<?php\n\n{$useStatement}", $content, 1);
        }

        // Add registration before the last ?>
        if (strpos($content, '?>') !== false) {
            $content = str_replace('?>', "{$registrationLine}\n\n?>", $content);
        } else {
            $content = rtrim($content) . "\n\n{$registrationLine}\n";
        }

        file_put_contents($routesFile, $content);
        $this->info("Registered {$className} in " . ($isApi ? 'routes/api.php' : 'routes/web.php'));
    }

    private function getNamespaceFromPath(string $path): string
    {
        $relativePath = str_replace($this->getAppPath(), '', $path);
        $relativePath = dirname($relativePath);
        $relativePath = str_replace('/', '\\', $relativePath);
        $relativePath = trim($relativePath, '\\');

        return $relativePath ? 'App\\Actions\\' . $relativePath : 'App\\Actions';
    }

    public function getDescription(): string
    {
        return $this->description;
    }
}
