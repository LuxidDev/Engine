# Changelog

## 0.9.0

Worker-mode support and production tuning.

### Added

- **`Luxid\Foundation\Preloader`** — compiles the application into opcache at
  server start. Takes a cold request boot from ~700µs to ~105µs. The starter
  ships a `preload.php` that uses it.
- **`Luxid\Foundation\Worker`** — boots the application once and serves many
  requests from the same process, removing per-request autoloading, provider
  discovery, route registration and database connection. Measured at ~168,000
  req/s against ~34,500 with per-request boot.
- **`Luxid\Foundation\RequestScope`** — registry of state that must be cleared
  between requests. Packages register a callback in their provider's `boot()`
  rather than the engine reaching into them.
- **`Application::prepareForNextRequest()`**, which runs the registry and
  installs a fresh request and response.
- **`juice optimize`** — rebuilds the package manifest, dumps a classmap
  autoloader and reports which opcache settings are still costing you.
- `Response::prettyPrintJson()` and a `pretty_json` config key.
- A `cors` config key, so origins are configured rather than hardcoded.

### Changed

- **The FrankenPHP adapter was rewritten against the real API.** It previously
  expected a PSR-7 style request object with `getUri()` and `getHeaders()` —
  that is RoadRunner's model. FrankenPHP is the PHP SAPI: superglobals are
  repopulated per request and output goes through `echo` and `header()`. The
  old handler signature was never called by the runtime.
- **JSON responses are compact by default**, pretty printed only when `debug`
  is on. Pretty printing cost about a quarter more CPU and roughly tripled the
  bytes on the wire.
- `CliApplication` registers its request scope, matching the web kernel.

## 0.8.1

Performance. No API removals; the additions are listed below.

### Performance

- **Package discovery is compiled to a manifest.** Booting the application read
  and JSON-decoded Composer's `installed.json` — around 30KB — on every single
  request. It is now compiled to a PHP array literal that opcache keeps
  resident, and rebuilt automatically when `installed.json` changes.
  `new Application` went from ~174µs to ~7.5µs; a cold request boot went from
  ~1.17ms to ~753µs.
- **Routes are indexed by id.** Matching used to copy the whole route array —
  callback, middleware lists and all — on every request. Routes are stored once
  and the indexes hold ids.
- **Dynamic routes are bucketed by segment count and leading literal.** A
  request now tests the handful of patterns that could match rather than every
  dynamic route. With 40 dynamic routes, matching the last one went from ~8.7µs
  to ~2.1µs and no longer depends on position in the table. A 404 went from
  ~7.5µs to ~1.5µs.
- **Middleware chains are memoized per route** and rebuilt only when global
  middleware is added.
- **Route patterns compile without regex.** Placeholders are recognised by their
  delimiters, and literal segments skip `preg_quote` when they need no escaping.
- **Group context is resolved once per registration** instead of four times.
- **Input sanitization uses `htmlspecialchars` directly** rather than the
  `filter_var` dispatch — the same transformation, about 1.75x faster, with the
  charset pinned to UTF-8 instead of inherited from `default_charset`.

### Added

- `Luxid\Foundation\PackageManifest`, with `providers()`, `commands()`,
  `rebuild()`, `forget()` and `flush()`.
- `Application::packageManifest()`.

### Fixed

- `Router::getRoutesForInspection()` reports routes grouped by method again
  after the storage change.

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
