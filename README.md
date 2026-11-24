# cairophp/laravel-octane

Run [Laravel Octane](https://laravel.com/docs/octane) on the
[Cairo](https://github.com/cairophp/cairo) application server:

```sh
composer require laravel/octane cairophp/laravel-octane
php artisan octane:start --server=cairo
```

Put the `cairo` binary on your `PATH` or in the project root, or pass
`--binary=/path/to/cairo` to `octane:cairo`. Octane's commands need the `pcntl`
extension, as with every Octane server. Isolated mode runs on Cairo 0.4 or
later; fiber mode, `--detect-leaks` and serving `public/` from Go need Cairo
0.5.

`octane:start --server=cairo` (or `OCTANE_SERVER=cairo`) takes Octane's usual
`--host`, `--port`, `--workers` and `--max-requests`; `octane:stop` and
`octane:status` work too. `php artisan octane:cairo` takes the same options plus:

| Option | Default | |
|---|---|---|
| `--mode` | `isolated` | `isolated` or `fibers` (also `octane.cairo.mode` in `config/octane.php`) |
| `--concurrency` | `8` | In fiber mode, the requests each PHP thread runs at once |
| `--detect-leaks` | off | Log static properties that a request changed but that are not request-local (`App::detectLeaks()`; also `octane.cairo.detect_leaks`). For development |
| `--binary` | `cairo` on `PATH` | The Cairo binary |

`--max-requests` (500 unless you say otherwise, 0 for never) is how many
requests one booted app serves before its PHP thread is replaced by a fresh
one, as it is for a worker on Octane's other servers. In fiber mode a thread
holds `--concurrency` apps, so it is replaced after `--max-requests` times
`--concurrency` requests (under a light load its first apps serve most of
them). A thread that is replaced serves until the new one has booted your
app, so no request waits for the boot.

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
database connections. The static properties that belong to an app are made
request-local with `Cairo\RequestLocal` and kept per app: a fixed list (the
container instance, facades, Eloquent's resolver, dispatcher and booted
models, the paginator's resolvers) and every static that booting the
thread's first app changed, found when the server starts. On a new Laravel
13 app that is 40 statics, among them the exception handler's app,
`Request::$macros`, the auth middleware's redirect callbacks and
`Uri::$urlGeneratorResolver`, which service providers set while the app
boots.

### Choosing `--concurrency`

A thread runs up to `--concurrency` requests at once, so the server holds
`--workers` times `--concurrency` requests in flight and the rest wait in
Cairo for a free app. The default of 8 suits requests that mostly compute.
Raise it when your requests mostly wait (slow queries, calls to other
services): how many of them fit in the wait is what limits the server then.

A page with two Eloquent queries and two HTTP calls to another service, on 2
CPUs with 256 connections (Laravel 13, PHP 8.5; requests per second, and
the memory of the server once every app has booted):

| | Calls answer in 20 ms | Calls answer in 200 ms | Memory |
|---|---:|---:|---:|
| isolated, 40 workers | 534 | 95 | 207 MB |
| fibers, 4 workers x 8 | 540 | 76 | 136 MB |
| fibers, 4 x 16 | 565 | 150 | 165 MB |
| fibers, 4 x 32 | 505 | 296 | 264 MB |
| fibers, 4 x 64 | 442 | 527 | 364 MB |
| fibers, 8 x 8 | 548 | 153 | 177 MB |

With the 20 ms calls the CPUs are the limit, and more requests at once only
share them: at 64 the page is 18% slower than at 8 and its 99th percentile
doubles (528 ms to 1030 ms). With the 200 ms calls the wait is the limit, and
each doubling of `--concurrency` doubles the requests per second. Each app
costs 1 to 1.5 MB here and holds its own database connections, so
`--workers` times `--concurrency` must stay under your database's connection
limit. An app is booted when a request first needs it, so a thread that is
never that busy never pays for the apps it does not use.

Fiber mode needs Cairo 0.5 or later. Memory grows with `--concurrency`: one
booted app per request in flight per thread. It is new: test your app with
it first, with `--detect-leaks` on and a load that runs several requests at
once. The detector logs any static that a request changed and that is not
kept per request, such as a package's static set from the request; register
those with `Cairo\RequestLocal`. Output that a request `echo`es (rather than
returning a response) is not supported in this mode.

## Streamed responses

`response()->stream()`, `response()->eventStream()` and
`response()->download()` reach the client while they are made, in both
modes. This needs a Cairo with streamed answers, which is the release after
0.5; an older one sends them whole at the end. A stream holds its worker
until it ends in isolated mode, and one of the thread's `--concurrency`
places in fiber mode.

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
