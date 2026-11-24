<?php

declare(strict_types=1);

namespace Cairo\Octane;

use Illuminate\Support\ServiceProvider;
use Laravel\Octane\Commands as Octane;

final class CairoOctaneServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ServerState::class, static fn () => new ServerState(
            config('octane.state_file', storage_path('logs/octane-server-state.json'))
        ));
        // Artisan resolves Octane's commands from the container, so these
        // bindings add --server=cairo to octane:start, octane:reload,
        // octane:stop and octane:status.
        $this->app->bind(Octane\StartCommand::class, Commands\StartCommand::class);
        $this->app->bind(Octane\ReloadCommand::class, Commands\ReloadCommand::class);
        $this->app->bind(Octane\StopCommand::class, Commands\StopCommand::class);
        $this->app->bind(Octane\StatusCommand::class, Commands\StatusCommand::class);
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([Commands\StartCairoCommand::class]);
        }
    }
}
