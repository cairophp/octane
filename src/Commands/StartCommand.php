<?php

declare(strict_types=1);

namespace Cairo\Octane\Commands;

use Laravel\Octane\Commands\StartCommand as OctaneStartCommand;

/** octane:start, which also knows --server=cairo. */
final class StartCommand extends OctaneStartCommand
{
    public function handle()
    {
        if (($this->option('server') ?: config('octane.server')) !== 'cairo') {
            return parent::handle();
        }
        return $this->call('octane:cairo', array_filter([
            '--host' => $this->getHost(),
            '--port' => $this->getPort(),
            '--workers' => $this->option('workers') ?: config('octane.workers', 'auto'),
            '--max-requests' => $this->option('max-requests') ?: config('octane.max_requests', 500),
            '--watch' => $this->option('watch') ?: null,
        ], static fn ($v) => $v !== null));
    }
}
