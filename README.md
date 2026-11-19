# cairophp/octane

Run [Laravel Octane](https://laravel.com/docs/octane) on the
[Cairo](https://github.com/cairophp/cairo) application server:

```sh
composer require laravel/octane cairophp/octane
php artisan octane:start --server=cairo
```

Put the `cairo` binary on your `PATH` or in the project root, or pass
`--binary=/path/to/cairo` to `octane:cairo`. Octane's commands need the `pcntl`
extension, as with every Octane server.

`octane:start --server=cairo` (or `OCTANE_SERVER=cairo`) takes Octane's usual
`--host`, `--port`, `--workers` and `--max-requests`; `octane:stop` and
`octane:status` work too. `php artisan octane:cairo` takes the same options plus:

| Option | Default | |
|---|---|---|
| `--mode` | `isolated` | `isolated` or `fibers` (also `octane.cairo.mode` in `config/octane.php`) |
| `--concurrency` | `8` | In fiber mode, the requests each PHP thread runs at once |
| `--binary` | `cairo` on `PATH` | The Cairo binary |

Files under `public/` are served by Cairo (Go) and every other path goes to
Laravel, as with FrankenPHP's Caddy. PHP files and hidden files are never
served.

## Isolated mode (the default)

Each PHP thread boots your app once and runs one request at a time, and each
request gets a fresh sandbox of the booted app, with Octane's listeners
flushing state between requests: the same model as FrankenPHP, Swoole and
RoadRunner under Octane. Requests wait in Cairo in one queue for the next
free thread.

`Octane::concurrently()` runs its tasks at once, each in its own fiber of the
request, so their I/O waits overlap (on FrankenPHP and RoadRunner they run
one after another; on Swoole each goes to a task worker). Tasks see the
request's app. Inside a request you can also use `Cairo\async()` and
`Cairo\await()` directly.

## Fiber mode

Each PHP thread runs several requests at once (`--concurrency`), and while one
waits on I/O (the database, HTTP calls, `sleep()`), the others run. Octane's
per-request sandbox is a shallow clone, so requests cannot share one booted
app: each request in flight on a thread takes its own booted app from that
thread's pool (booted on first need, up to `--concurrency`), with its own
database connections. Laravel's static properties that belong to an app (the
container instance, facades, Eloquent's resolver and dispatcher and booted
models, the paginator's resolvers) are made request-local with
`Cairo\RequestLocal` and kept per app.

Fiber mode needs Cairo 0.5 or later. Memory grows with `--concurrency`: one
booted app per request in flight per thread. It is new: test your app with it
first. Packages that keep request state in their own static properties need
them registered with `Cairo\RequestLocal`, and output that a request
`echo`es (rather than returning a response) is not supported in this mode.

## Not supported yet

- `octane:reload` and `--watch`.
- HTTPS in the server itself (put Cairo behind a proxy that ends TLS, or use
  Cairo's own `server.php` with TLS).

## Tests

`tests/ServerTest.php` starts the server on a real Laravel app, in both modes:

```sh
tests/fixture/setup.sh /tmp/octane-app
CAIRO_OCTANE_TEST_APP=/tmp/octane-app CAIRO_BINARY=/path/to/cairo vendor/bin/phpunit
```

## License

Apache-2.0
