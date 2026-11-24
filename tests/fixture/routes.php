<?php

// Routes the driver's tests call; tests/fixture/setup.sh makes the test
// app's routes/web.php require this file.

use Illuminate\Auth\GenericUser;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Laravel\Octane\Facades\Octane;

Route::get('/octane-test/hello', fn () => 'hello');

// A user set in one request must not be seen by the next one.
Route::get('/octane-test/login/{id}', function (int $id) {
    auth()->setUser(new GenericUser(['id' => $id]));
    return ['user' => auth()->id()];
});
Route::get('/octane-test/whoami', fn () => ['user' => auth()->id()]);

// Three 200 ms waits through Octane::concurrently().
Route::get('/octane-test/concurrently', function () {
    $t = microtime(true);
    $r = Octane::concurrently([
        'a' => function () { usleep(200_000); return 1; },
        'b' => function () { usleep(200_000); return 2; },
        'c' => function () { usleep(200_000); return 3; },
    ]);
    return ['results' => $r, 'ms' => (int) ((microtime(true) - $t) * 1000)];
});
Route::get('/octane-test/timeout', fn () => Octane::concurrently([function () { usleep(500_000); }], 100));

// Requests running at once on one thread each keep their own state.
Route::get('/octane-test/mixed/{id}', function (int $id) {
    auth()->setUser(new GenericUser(['id' => $id]));
    usleep(100_000);
    return [
        'auth' => auth()->id(),
        'route' => (int) Route::current()->parameter('id'),
        'url' => url()->current(),
        'db' => (int) DB::selectOne('select ? as id', [$id])->id,
    ];
});

// The thread that answers: a function's static is the thread's, whichever of
// its apps runs the request, and a new thread starts without it.
if (!function_exists('octane_test_thread')) {
    function octane_test_thread(): int
    {
        static $id;
        return $id ??= hrtime(true);
    }
}
Route::get('/octane-test/thread/{ms}', function (int $ms) {
    usleep($ms * 1000);
    return ['thread' => octane_test_thread()];
});

// Responses that are sent while they are made: the client has the first
// part while the callback still works on the second.
Route::get('/octane-test/stream', fn () => response()->stream(function () {
    echo "part 1\n";
    flush();
    usleep(300_000);
    echo 'part 2 for ' . (auth()->id() ?? 'nobody') . "\n";
}, 200, ['Content-Type' => 'text/plain']));
Route::get('/octane-test/events', fn () => response()->eventStream(function () {
    yield 'one';
    usleep(300_000);
    yield 'two';
}));
Route::get('/octane-test/download', fn () => response()->download(base_path('composer.json')));

// What app/ReloadProbe.php says, a file the reload tests write and change:
// a worker keeps the class it loaded until it is replaced.
Route::get('/octane-test/probe', fn () => class_exists(\App\ReloadProbe::class) ? \App\ReloadProbe::VALUE : 'none');
