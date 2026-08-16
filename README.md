<p align="center">
    <img src="https://luxid.dev/lion5.svg" width="120" alt="Luxid Logo">
</p>

<p align="center">
    <strong>Luxid Engine</strong><br>
    A lightweight, expressive PHP framework for developers who value clarity and control.
</p>

<p align="center">
    ⚠️ <strong>Pre-release:</strong> APIs are unstable and subject to change.
</p>

---

## About Luxid

This repository holds the core engine. To build an application, start from the
[starter project](https://github.com/luxid/framework):

```bash
composer create-project luxid/framework my-app
cd my-app
php juice start
```

Luxid organises applications around **SEA**:

> **Screen (views) → Entities (models) → Actions (controllers)**

An **Action** groups the handlers for one slice of the domain and declares its
own routes, so a feature's dispatch table lives beside its behaviour instead of
in a central file. Each handler is an *activity*.

## Requirements

PHP 8.1 or newer.

## The pieces

| Package | Role |
|---|---|
| `luxid/engine` | Routing, HTTP, kernel, `juice` CLI |
| `luxid/rocket` | Attribute-driven ORM, migrations, seeding |
| `luxid/nova` | Server-rendered reactive components |
| `luxid/haven` | Session authentication |

Nova, Rocket and Haven are optional; the engine works without them.

## Actions and routes

```php
namespace App\Actions;

use Luxid\Foundation\Action;
use Luxid\Nodes\Response;
use Luxid\Routing\Routes;

class TodoAction extends Action
{
    public static function routes(): Routes
    {
        return Routes::new()
            ->prefix('api')
            ->add('/todos', get('index'))
            ->add('/todos/{id}', get('show'))
            ->add('/todos', post('store'))
            ->secure();
    }

    public function index(): string
    {
        return Response::success(Todo::findAll());
    }

    public function show(string $id): string
    {
        return Response::success(Todo::find((int) $id));
    }
}
```

Register it in `routes/api.php`:

```php
use App\Actions\TodoAction;

TodoAction::routes()->register(TodoAction::class);
```

### Every route states its security

A route collection must declare a posture before it registers, and registering
without one throws at boot:

| Declaration | Effect |
|---|---|
| `->secure()` | Every activity requires authentication |
| `->open(['login'])` | Every activity requires authentication except those named |
| `->public()` | No activity requires authentication |

This is the guarantee that makes an unprotected endpoint a startup error rather
than something you discover in production. Console runs are exempt so
`juice routes` can inspect an application whose routes are mid-refactor.

The fluent DSL carries the same rule:

```php
route('todos.index')->get('/todos')->uses(TodoAction::class, 'index')->secure();
```

### Route parameters

`{id}` matches one required segment and `{id?}` makes it optional. Paths are
compiled to patterns once at registration, so matching does not re-parse them
per request. Handlers may declare `Request` and `Response` parameters by type or
by name; route parameters are matched by name and fall back to position.

### Groups

```php
route_group(['prefix' => 'admin', 'auth' => true], function (): void {
    route('admin.users')->get('/users')->uses(AdminAction::class, 'users')->register();
});
```

Prefixes concatenate and middleware accumulates through nesting. Routes inside
an `auth` group inherit it unless they declare their own posture or opt out with
`withoutInheritance()`.

## Requests and responses

```php
$request->query('status', 'pending');   // query string only
$request->input('title');               // body only
$request->only(['title', 'body']);
$request->except(['password']);
$request->filled('title');
$request->wantsJson();
```

Input is sanitized on the way in. `_method` and `X-HTTP-Method-Override` are
honoured on POST so HTML forms can issue PUT, PATCH and DELETE.

Actions **return** their body; the kernel flushes the status code, headers and
body once the response is ready:

```php
return Response::success($data);
return Response::error('Not found', null, 404);
return Response::warp('/login');
```

## Middleware

```php
use Luxid\Middleware\BaseMiddleware;

class ThrottleMiddleware extends BaseMiddleware
{
    public function execute(): void
    {
        if ($tooManyRequests) {
            throw new \Luxid\Exceptions\ForbiddenException('Slow down.');
        }
    }
}
```

Middleware halts a request by throwing. Execution order is global → API global
(for `/api/*` and JSON requests) → group → route → action.

## Exceptions

| Exception | Status |
|---|---|
| `NotFoundException` | 404 |
| `MethodNotAllowedException` | 405, with an `Allow` header |
| `UnauthorizedException` | 401 |
| `ForbiddenException` | 403 |

API requests get a JSON envelope; web requests render the `_error` screen.

## The `juice` CLI

```bash
php juice start            # development server
php juice routes           # inspect every registered route
php juice make:action Todo
php juice make:entity Todo
php juice make:migration create_todos_table
php juice migrate
php juice seed
php juice env:check
php juice db:status
```

Packages contribute commands through `extra.luxid.commands` and providers
through `extra.luxid.providers` in their composer.json; both are discovered
automatically.

## Testing

```bash
composer install
composer test
```

## Security

Report vulnerabilities to **jhay@luxid.dev**.

## License

MIT.
