<?php

declare(strict_types=1);

namespace Cairo\Octane\Commands;

use Cairo\Octane\ServerState;
use Laravel\Octane\Commands\ReloadCommand as OctaneReloadCommand;

/** octane:reload, which also knows --server=cairo. */
final class ReloadCommand extends OctaneReloadCommand
{
    public function handle()
    {
        if (($this->option('server') ?: config('octane.server')) !== 'cairo') {
            return parent::handle();
        }
        // Cairo replaces each PHP thread with one that has booted the app
        // anew, and refuses no request meanwhile.
        if (!app(ServerState::class)->reload()) {
            $this->components->error('Cairo server is not running.');
            return 1;
        }
        $this->components->info('Reloading workers...');
        return 0;
    }
}
