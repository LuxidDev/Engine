# Changelog

## 0.8.0

Pre-release. This version fixes defects that made the previous release unusable
end to end, so several of them change behaviour on purpose.

### Breaking

- **`Application::run()` now sends the response.** It flushes the status code,
  headers and body, and still returns the body for adapters that manage their
  own output. Front controllers must drop any `echo $app->run()` they added as a
  workaround. Use `Application::handle()` to render without sending.
- **`SessionInterface` gained `regenerate()`, `has()`, `hasFlash()` and
  `clear()`.** Custom session drivers must implement them.
- **`Routes` now requires a security posture.** A collection must call
  `secure()`, `open([...])` or `public()` before `register()`, matching the rule
  `RouteBuilder` already enforced. Console runs are exempt so `juice routes` can
  still inspect an application mid-refactor.
- **`RouteBuilder::open([...])` protects the activities it did not name.** It
  previously installed a blanket `PublicMiddleware` and opened the whole route.
- **`Application::$user` is typed `?object`** rather than `?DbEntity`, because
  the configured user entity is usually a `Rocket\ORM\Entity`. Assigning one
  previously raised a `TypeError`.
- **`Application::$db` is no longer opened during boot.** Use `Application::db()`
  or the `db()` action helper, which connect on first use.
- **`BaseMiddleware::execute()` is typed `: void`.** Middleware that declared a
  return type must drop it.
- **Minimum PHP is now 8.1.**
- **`Luxid\Database\Database` and `Luxid\Http\ResponseHelper` were removed.**
  Both were unused and duplicated `Rocket\Connection\Connection` and
  `Luxid\Http\Response`.
- **`HavenInstallBridge` was removed.** Package commands are discovered from
  `extra.luxid.commands`, so Haven no longer writes a file into this package.

### Fixed

- Route and group middleware now run for closure and screen routes. They only
  ran for action routes, so a closure route silently skipped its auth check.
- The session is booted by `SessionMiddleware` on every route. The router used
  to start it for two hardcoded paths, leaving `Application::$user` null — and
  therefore every session-authenticated route rejecting signed-in users.
- `Request::query()` returns the requested parameter. The loop that filled the
  cache shadowed the lookup key, so the first call returned the *last* query
  parameter's value.
- `Request::method()` honours `_method` and `X-HTTP-Method-Override`. The guard
  checked `$_POST['method']` while reading `$_POST['_method']`.
- Flash messages expire after one request. `setFlash()` wrote a `removed` key
  while cleanup read `remove`.
- `Response::redirectWith()` no longer fatals on a null session.
- `AuthMiddleware` fails closed when no action has been resolved.
- CORS never pairs a wildcard origin with credentials, a combination browsers
  reject outright. Origins are allowlisted through the `cors` config key.
- `DbEntity` prepares statements through `Connection::getPdo()` instead of a
  protected property, and validates column names against `attributes()`.
- `Screen` uses `include` rather than `include_once`, so a partial can render
  more than once per request.
- The FrankenPHP adapter resets per-request state between worker iterations.

### Added

- `MethodNotAllowedException` and `UnauthorizedException`, mapped to 405 and 401.
- The router answers 405 with an `Allow` header when a path exists under another
  method.
- Route paths are compiled to patterns once at registration instead of being
  re-parsed per request.
- `Router::has()`, `Request::except()`, `Request::filled()`, `Request::header()`,
  `Request::wantsJson()`, `Request::isAjax()`, `Response::send()` and the `e()`
  escaping helper.
- A PHPUnit suite covering routing, middleware ordering, request parsing and the
  security posture rules.
