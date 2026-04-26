<?php

namespace Luxid\Console\Commands;

use Luxid\Console\Command;

class MakeApiCommand extends Command
{
    protected string $description = 'Create a new API Action with full CRUD operations';

    public function handle(array $argv): int
    {
        $this->parseArguments($argv);

        if (empty($this->args)) {
            $this->error('Please provide an API resource name');
            $this->line('Usage: php juice make:api <ResourceName>');
            return 1;
        }

        $resourceName = $this->args[0];
        $actionName = $resourceName . 'Action';
        $actionPath = $this->getAppPath() . '/Actions/' . $actionName . '.php';
        $actionNamespace = $this->getNamespaceFromPath($actionPath);

        $content = $this->generateApiActionContent($actionNamespace, $resourceName);

        if ($this->createFile($actionPath, $content)) {
            $this->info("API Action created: app/Actions/{$actionName}.php");

            if ($this->confirm('Do you want to register this action in routes/api.php?', true)) {
                $this->registerInApiRoutes($actionName);
            }

            return 0;
        }

        return 1;
    }

    private function generateApiActionContent(string $namespace, string $resourceName): string
    {
        $resourceLower = strtolower($resourceName);

        return <<<PHP
<?php

namespace {$namespace};

use App\Actions\LuxidAction;
use Luxid\Nodes\Response;
use Luxid\Routing\Routes;
use App\Entities\\{$resourceName};

class {$resourceName}Action extends LuxidAction
{
    public static function routes(): Routes
    {
        return Routes::new()
            ->prefix('api')
            ->add('/{$resourceLower}', get('index'))
            ->add('/{$resourceLower}/{id}', get('show'))
            ->add('/{$resourceLower}', post('store'))
            ->add('/{$resourceLower}/{id}', put('update'))
            ->add('/{$resourceLower}/{id}', delete('destroy'));
    }

    /**
     * GET /api/{$resourceLower}
     */
    public function index(): string
    {
        \$resources = {$resourceName}::all();
        return Response::success([
            '{$resourceLower}' => \$resources,
            'count' => count(\$resources)
        ]);
    }

    /**
     * GET /api/{$resourceLower}/{id}
     */
    public function show(int \$id): string
    {
        \$resource = {$resourceName}::find(\$id);

        if (!\$resource) {
            return Response::error('{$resourceName} not found', null, 404);
        }

        return Response::success(['{$resourceLower}' => \$resource]);
    }

    /**
     * POST /api/{$resourceLower}
     */
    public function store(): string
    {
        \$data = \$this->request()->input();

        \$resource = new {$resourceName}();
        // Map your fields here
        // \$resource->field = \$data['field'] ?? '';

        if (\$resource->save()) {
            return Response::success(['{$resourceLower}' => \$resource], 201);
        }

        return Response::error('Failed to create {$resourceLower}');
    }

    /**
     * PUT /api/{$resourceLower}/{id}
     */
    public function update(int \$id): string
    {
        \$resource = {$resourceName}::find(\$id);

        if (!\$resource) {
            return Response::error('{$resourceName} not found', null, 404);
        }

        \$data = \$this->request()->input();
        // Update your fields here
        // \$resource->field = \$data['field'] ?? \$resource->field;

        if (\$resource->save()) {
            return Response::success(['{$resourceLower}' => \$resource]);
        }

        return Response::error('Failed to update {$resourceLower}');
    }

    /**
     * DELETE /api/{$resourceLower}/{id}
     */
    public function destroy(int \$id): string
    {
        \$resource = {$resourceName}::find(\$id);

        if (!\$resource) {
            return Response::error('{$resourceName} not found', null, 404);
        }

        \$resource->delete();
        return Response::success(['message' => '{$resourceName} deleted successfully']);
    }
}

PHP;
    }

    private function registerInApiRoutes(string $actionName): void
    {
        $routesFile = $this->getProjectRoot() . '/routes/api.php';
        $registrationLine = "{$actionName}::routes()->register({$actionName}::class);";
        $useStatement = "use App\\Actions\\{$actionName};";

        if (!file_exists($routesFile)) {
            $content = "<?php\n\n{$useStatement}\n\n{$registrationLine}\n";
            file_put_contents($routesFile, $content);
            $this->info("Created routes/api.php and registered {$actionName}");
            return;
        }

        $content = file_get_contents($routesFile);

        if (strpos($content, $registrationLine) !== false) {
            $this->warning("{$actionName} already registered");
            return;
        }

        if (strpos($content, $useStatement) === false) {
            $content = preg_replace('/^<\?php/', "<?php\n\n{$useStatement}", $content, 1);
        }

        if (strpos($content, '?>') !== false) {
            $content = str_replace('?>', "{$registrationLine}\n\n?>", $content);
        } else {
            $content = rtrim($content) . "\n\n{$registrationLine}\n";
        }

        file_put_contents($routesFile, $content);
        $this->info("Registered {$actionName} in routes/api.php");
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
