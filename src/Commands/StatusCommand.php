<?php

declare(strict_types=1);

namespace Cairo\Octane\Commands;

use Cairo\Octane\ServerState;
use Laravel\Octane\Commands\StatusCommand as OctaneStatusCommand;

/** octane:status, which also knows --server=cairo. */
final class StatusCommand extends OctaneStatusCommand
{
    public function handle()
    {
        if (($this->option('server') ?: config('octane.server')) !== 'cairo') {
            return parent::handle();
        }
        $running = app(ServerState::class)->running();
        $this->components->info($running ? 'Octane server is running.' : 'Octane server is not running.');
        return $running ? 0 : 1;
    }
}
