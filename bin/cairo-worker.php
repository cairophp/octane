<?php

// The server.php that `php artisan octane:cairo` gives to `cairo serve`.
// Isolated mode: each PHP thread boots one Octane worker and runs one request
// at a time, which gets a fresh sandbox of the booted app, as on FrankenPHP.
// Fiber mode: each thread runs several requests at once, each on its own
// booted app from the thread's WorkerPool.

declare(strict_types=1);

use Cairo\App;
use Cairo\Http\Request;
use Cairo\Http\Response;
use Cairo\Octane\AppStatics;
use Cairo\Octane\CairoClient;
use Cairo\Octane\CairoTaskDispatcher;
use Cairo\Octane\WorkerPool;
use Laravel\Octane\ApplicationFactory;
use Laravel\Octane\Contracts\DispatchesTasks;
use Laravel\Octane\Worker;

$basePath = getenv('APP_BASE_PATH') ?: dirname(__DIR__, 4);
require_once (getenv('COMPOSER_VENDOR_DIR') ?: $basePath . '/vendor') . '/autoload.php';

$_ENV['APP_RUNNING_IN_CONSOLE'] = false;
$fibers = getenv('CAIRO_OCTANE_MODE') === 'fibers';
$client = new CairoClient();

$app = new App();
$app->threads((int) getenv('CAIRO_OCTANE_WORKERS') ?: 'auto');
$app->concurrency($fibers ? 'fibers' : 'isolated');
$app->maxRequests((int) (getenv('CAIRO_OCTANE_MAX_REQUESTS') ?: 500));

$http = $app->http((getenv('CAIRO_OCTANE_HOST') ?: '127.0.0.1') . ':' . (getenv('CAIRO_OCTANE_PORT') ?: '8000'));
if (($public = getenv('CAIRO_OCTANE_PUBLIC_PATH')) && method_exists($http, 'publicDir')) {
    $http->publicDir($public);
}

$fail = static function (Throwable $e): Response {
    report($e);
    return CairoClient::serverError('Internal Server Error');
};

if ($fibers) {
    if (!class_exists(\Cairo\RequestLocal::class)) {
        throw new RuntimeException('fiber mode needs Cairo 0.5 or later (Cairo\\RequestLocal)');
    }
    AppStatics::register();
    $app->maxInFlight((int) (getenv('CAIRO_OCTANE_CONCURRENCY') ?: 8));
    $pool = new WorkerPool($basePath, $client);
    $http->fallback(static function (Request $req) use ($pool, $client, $fail): Response {
        [$worker, $statics] = $pool->take();
        AppStatics::restore($statics);
        try {
            return $client->handle($worker, $req);
        } catch (Throwable $e) {
            return $fail($e);
        } finally {
            $worker->operationReleased();
            $pool->give($worker, AppStatics::save());
        }
    });
    return $app;
}

$worker = new Worker(new ApplicationFactory($basePath), $client);
$worker->boot([DispatchesTasks::class => new CairoTaskDispatcher()]);
$http->fallback(static function (Request $req) use ($worker, $client, $fail): Response {
    try {
        return $client->handle($worker, $req);
    } catch (Throwable $e) {
        return $fail($e);
    } finally {
        $worker->operationReleased();
    }
});

return $app;
