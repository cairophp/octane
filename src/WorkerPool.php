<?php

declare(strict_types=1);

namespace Cairo\Octane;

use Laravel\Octane\ApplicationFactory;
use Laravel\Octane\Contracts\DispatchesTasks;
use Laravel\Octane\Worker;

/**
 * Fiber mode's booted apps on one PHP thread: every request in flight takes
 * one, so no two requests share an app (Octane's per-request sandbox is a
 * shallow clone: its managers, router and connections are the app's own).
 * Apps are booted on first need, up to the thread's request limit
 * (App::maxInFlight()), and kept with their statics (AppStatics).
 */
final class WorkerPool
{
    /** @var list<array{0: Worker, 1: list<mixed>}> */
    private array $idle = [];

    public function __construct(private readonly string $basePath, private readonly CairoClient $client)
    {
    }

    /** @return array{0: Worker, 1: list<mixed>} */
    public function take(): array
    {
        return array_pop($this->idle) ?? $this->boot();
    }

    /** @param list<mixed> $statics */
    public function give(Worker $worker, array $statics): void
    {
        $this->idle[] = [$worker, $statics];
    }

    /** @return array{0: Worker, 1: list<mixed>} */
    private function boot(): array
    {
        AppStatics::restore(AppStatics::defaults());
        $worker = new Worker(new ApplicationFactory($this->basePath), $this->client);
        $worker->boot([DispatchesTasks::class => new CairoTaskDispatcher()]);
        return [$worker, AppStatics::save()];
    }
}
