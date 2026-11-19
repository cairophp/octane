<?php

declare(strict_types=1);

namespace Cairo\Octane;

use Cairo\Context;
use Cairo\Context\DeadlineExceeded;
use Laravel\Octane\Contracts\DispatchesTasks;
use Laravel\Octane\Exceptions\TaskTimeoutException;
use Throwable;

use function Cairo\async;
use function Cairo\await;

/**
 * Octane::concurrently() on Cairo: each task runs in its own fiber of the
 * current request, so their I/O waits overlap. Unlike Swoole's task workers,
 * nothing is serialized and the tasks see the request's app.
 */
final class CairoTaskDispatcher implements DispatchesTasks
{
    /**
     * @param array<array-key, callable> $tasks
     * @return array<array-key, mixed>
     */
    public function resolve(array $tasks, int $waitMilliseconds = 3000): array
    {
        $ctx = Context::current()->withTimeout($waitMilliseconds / 1000);
        try {
            return $ctx->run(static function () use ($tasks): array {
                $running = array_map(static fn (callable $task) => async($task), $tasks);
                $results = [];
                $error = null;
                foreach ($running as $key => $task) {
                    try {
                        $results[$key] = await($task);
                    } catch (Throwable $e) {
                        $error ??= $e;   // wait for the rest before throwing, as Octane does
                    }
                }
                if ($error !== null) {
                    throw $error;
                }
                return $results;
            });
        } catch (DeadlineExceeded) {
            throw TaskTimeoutException::after($waitMilliseconds);
        } finally {
            $ctx->cancel();
        }
    }

    /**
     * Runs the tasks concurrently and waits for them: they must not outlive
     * the request, whose app Octane flushes once it ends.
     *
     * @param array<array-key, callable> $tasks
     */
    public function dispatch(array $tasks): void
    {
        try {
            $this->resolve($tasks, 60_000);
        } catch (Throwable $e) {
            report($e);
        }
    }
}
