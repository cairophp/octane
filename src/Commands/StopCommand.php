<?php

declare(strict_types=1);

namespace Cairo\Octane\Commands;

use Cairo\Octane\ServerState;
use Laravel\Octane\Commands\StopCommand as OctaneStopCommand;

/** octane:stop, which also knows --server=cairo. */
final class StopCommand extends OctaneStopCommand
{
    public function handle()
    {
        if (($this->option('server') ?: config('octane.server')) !== 'cairo') {
            return parent::handle();
        }
        if (!app(ServerState::class)->stop()) {
            $this->components->error('Cairo server is not running.');
            return 1;
        }
        $this->components->info('Stopping server...');
        return 0;
    }
}
