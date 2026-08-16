<?php

declare(strict_types=1);

namespace Luxid\Console\Commands;

use Luxid\Console\Command;

/**
 * Scaffolds an Action class and registers its routes.
 *
 * ```
 * php juice make:action Todo            # web action at /todos
 * php juice make:action Todo --api      # RESTful API action under /api/todos
 * php juice make:action Admin/Report    # nested namespace
 * ```
 *
 * @package Luxid\Console\Commands
 */
class MakeActionCommand extends Command
{
    protected string $description = 'Create a new Action class with its route table';

    /**
     * Run the command.
     *
     * @param list<string> $argv Raw console arguments
     */
    public function handle(array $argv): int
    {
        $this->parseArguments($argv);

        if ($this->args === []) {
            $this->error('Please provide an action name');
            $this->line('Usage: php juice make:action <ActionName> [--api]');

            return 1;
        }

        $input = trim(str_replace('\\', '/', $this->args[0]), '/');
        $className = $this->classNameFor($input);
        $subNamespace = $this->subNamespaceFor($input);
        $namespace = $subNamespace === '' ? 'App\\Actions' : 'App\\Actions\\' . $subNamespace;

        // `--api` is explicit; a name containing "Api" is a strong enough hint
        // to default to the API shape.
        $isApi = ($this->options['api'] ?? false) === true || str_contains($className, 'Api');

        $path = $this->getAppPath() . '/Actions/'
            . ($subNamespace === '' ? '' : str_replace('\\', '/', $subNamespace) . '/')
            . $className . '.php';

        $resource = $this->resourceNameFor($className);
        $content = $isApi
            ? $this->buildApiClass($namespace, $className, $resource)
            : $this->buildWebClass($namespace, $className, $resource);

        if (!$this->createFile($path, $content)) {
            return 1;
        }

        $this->info(sprintf('Action created: %s', $this->relative($path)));

        if ($this->confirm('Register this action in the routes file?', true)) {
            $this->registerInRoutes($namespace, $className, $isApi);
        }

        // A web action renders a Nova page that does not exist yet, so say so
        // now rather than letting the first request discover it.
        if (!$isApi) {
            $page = str_replace('-', '', ucwords($resource, '-'));

            if (!is_file($this->getProjectRoot() . '/nova/pages/' . $page . '.nova.php')) {
                $this->line('');
                $this->warning(sprintf('This action renders the "%s" page, which does not exist yet.', $page));
                $this->line(sprintf('  Create it with: php juice make:nova:page %s', $page));
            }
        }

        return 0;
    }

    /**
     * Get the class name from the supplied path-like name.
     *
     * `Admin/Report` yields `ReportAction`; an explicit `Action` suffix is kept
     * rather than doubled.
     *
     * @param string $input Name as typed
     */
    private function classNameFor(string $input): string
    {
        $name = ucfirst(basename($input, '.php'));

        return str_ends_with($name, 'Action') ? $name : $name . 'Action';
    }

    /**
     * Get the namespace suffix implied by a nested name.
     *
     * Previously this was derived by subtracting the app path from the file
     * path, which produced `App\Actions\Actions` for every top-level action.
     *
     * @param string $input Name as typed
     */
    private function subNamespaceFor(string $input): string
    {
        $directory = trim(dirname($input), '.');

        if ($directory === '' || $directory === '/') {
            return '';
        }

        return implode('\\', array_map('ucfirst', explode('/', trim($directory, '/'))));
    }

    /**
     * Derive the URL segment for an action.
     *
     * `TodoAction` becomes `todos`, so generated routes are plausible rather
     * than every action claiming `/`.
     *
     * @param string $className Action class name
     */
    private function resourceNameFor(string $className): string
    {
        $base = (string) preg_replace('/(Api)?Action$/', '', $className);
        $kebab = strtolower((string) preg_replace('/(?<!^)[A-Z]/', '-$0', $base));

        return $this->pluralize($kebab);
    }

    /**
     * Pluralize an English noun well enough for a route path.
     *
     * @param string $word Singular word
     */
    private function pluralize(string $word): string
    {
        if ($word === '' || str_ends_with($word, 's')) {
            return $word;
        }

        if (preg_match('/[^aeiou]y$/', $word) === 1) {
            return substr($word, 0, -1) . 'ies';
        }

        if (preg_match('/(ch|sh|x|z)$/', $word) === 1) {
            return $word . 'es';
        }

        return $word . 's';
    }

    /**
     * Render a JSON action with a full CRUD route table.
     *
     * @param string $namespace Class namespace
     * @param string $className Class name
     * @param string $resource  URL segment for the routes
     */
    private function buildApiClass(string $namespace, string $className, string $resource): string
    {
        return <<<PHP
        <?php

        declare(strict_types=1);

        namespace {$namespace};

        use Luxid\\Foundation\\Action;
        use Luxid\\Nodes\\Response;
        use Luxid\\Routing\\Routes;

        class {$className} extends Action
        {
            /**
             * Declare the routes this action serves.
             *
             * Every collection must state a security posture; swap secure() for
             * open([...]) or public() as needed.
             */
            public static function routes(): Routes
            {
                return Routes::new()
                    ->prefix('api')
                    ->add('/{$resource}', get('index'))
                    ->add('/{$resource}/{id}', get('show'))
                    ->add('/{$resource}', post('store'))
                    ->add('/{$resource}/{id}', put('update'))
                    ->add('/{$resource}/{id}', delete('destroy'))
                    ->secure();
            }

            /**
             * GET /api/{$resource}
             */
            public function index(): string
            {
                return Response::success([]);
            }

            /**
             * GET /api/{$resource}/{id}
             */
            public function show(string \$id): string
            {
                return Response::success(['id' => \$id]);
            }

            /**
             * POST /api/{$resource}
             */
            public function store(): string
            {
                return Response::success(null, 'Created', 201);
            }

            /**
             * PUT /api/{$resource}/{id}
             */
            public function update(string \$id): string
            {
                return Response::success(['id' => \$id], 'Updated');
            }

            /**
             * DELETE /api/{$resource}/{id}
             */
            public function destroy(string \$id): string
            {
                return Response::success(null, 'Deleted');
            }
        }

        PHP;
    }

    /**
     * Render an HTML action backed by a Nova page.
     *
     * @param string $namespace Class namespace
     * @param string $className Class name
     * @param string $resource  URL segment for the route
     */
    private function buildWebClass(string $namespace, string $className, string $resource): string
    {
        $page = str_replace('-', '', ucwords($resource, '-'));

        return <<<PHP
        <?php

        declare(strict_types=1);

        namespace {$namespace};

        use Luxid\\Foundation\\Action;
        use Luxid\\Nodes\\Nova;
        use Luxid\\Routing\\Routes;

        class {$className} extends Action
        {
            /**
             * Declare the routes this action serves.
             *
             * Every collection must state a security posture; swap public() for
             * secure() or open([...]) as needed.
             */
            public static function routes(): Routes
            {
                return Routes::new()
                    ->add('/{$resource}', get('index'))
                    ->public();
            }

            /**
             * GET /{$resource}
             */
            public function index(): string
            {
                return Nova::render('{$page}', [
                    'title' => '{$page}',
                ]);
            }
        }

        PHP;
    }

    /**
     * Append the route registration to the appropriate routes file.
     *
     * @param string $namespace Class namespace
     * @param string $className Class name
     * @param bool   $isApi     Whether the action serves JSON
     */
    private function registerInRoutes(string $namespace, string $className, bool $isApi): void
    {
        $file = $this->getRoutesPath() . '/' . ($isApi ? 'api.php' : 'web.php');
        $useStatement = 'use ' . $namespace . '\\' . $className . ';';
        $registration = $className . '::routes()->register(' . $className . '::class);';

        if (!is_file($file)) {
            $this->ensureDirectory(dirname($file));
            file_put_contents($file, "<?php\n\n{$useStatement}\n\n{$registration}\n");
            $this->info(sprintf('Created %s and registered %s', $this->relative($file), $className));

            return;
        }

        $content = (string) file_get_contents($file);

        if (str_contains($content, $registration)) {
            $this->warning(sprintf('%s is already registered', $className));

            return;
        }

        if (!str_contains($content, $useStatement)) {
            // Keep imports together at the top instead of scattering one
            // through the file on every generation.
            $content = $this->insertImport($content, $useStatement);
        }

        file_put_contents($file, rtrim($content) . "\n\n" . $registration . "\n");
        $this->info(sprintf('Registered %s in %s', $className, $this->relative($file)));
    }

    /**
     * Insert an import after the last existing one, or after the opening tag.
     *
     * @param string $content      Current file contents
     * @param string $useStatement Import to insert
     */
    private function insertImport(string $content, string $useStatement): string
    {
        $lines = explode("\n", $content);
        $lastUse = null;

        foreach ($lines as $index => $line) {
            if (str_starts_with(trim($line), 'use ')) {
                $lastUse = $index;
            }
        }

        if ($lastUse !== null) {
            array_splice($lines, $lastUse + 1, 0, $useStatement);

            return implode("\n", $lines);
        }

        return (string) preg_replace('/^<\?php/', "<?php\n\n" . $useStatement, $content, 1);
    }

    /**
     * Express a path relative to the project root, for readable output.
     *
     * @param string $path Absolute path
     */
    private function relative(string $path): string
    {
        return ltrim(str_replace($this->getProjectRoot(), '', $path), '/');
    }
}
