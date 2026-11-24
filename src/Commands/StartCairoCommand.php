<?php

declare(strict_types=1);

namespace Cairo\Octane\Commands;

use Cairo\Octane\ServerState;
use Illuminate\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\SignalableCommandInterface;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

#[AsCommand(name: 'octane:cairo')]
final class StartCairoCommand extends Command implements SignalableCommandInterface
{
    /** @var string */
    protected $signature = 'octane:cairo
                    {--host= : The IP address the server should bind to}
                    {--port= : The port the server should be available on}
                    {--workers=auto : The number of PHP threads that handle requests}
                    {--max-requests=500 : The number of requests a thread serves before it is replaced}
                    {--mode= : isolated (one request per thread at a time, the default) or fibers}
                    {--concurrency=8 : In fiber mode, the requests (and booted apps) each thread runs at once}
                    {--detect-leaks : Log static properties that a request changed but that are not request-local (development)}
                    {--watch : Reload the workers when a file of the application changes (development)}
                    {--binary= : The path to the cairo binary}';

    /** @var string */
    protected $description = 'Start the Octane Cairo server';

    /** @var bool */
    protected $hidden = true;

    private ?Process $server = null;

    public function handle(ServerState $state): int
    {
        if (!extension_loaded('pcntl')) {
            // Without it a stopped command would leave the server running.
            $this->components->error('octane:cairo needs the pcntl extension.');
            return 1;
        }
        if ($state->running()) {
            $this->components->error('Cairo server is already running.');
            return 1;
        }
        $mode = $this->option('mode') ?: config('octane.cairo.mode', 'isolated');
        if (!in_array($mode, ['isolated', 'fibers'], true)) {
            $this->components->error('--mode must be isolated or fibers.');
            return 1;
        }
        $host = $this->option('host') ?? config('octane.host') ?? $_ENV['OCTANE_HOST'] ?? '127.0.0.1';
        $port = $this->option('port') ?? config('octane.port') ?? $_ENV['OCTANE_PORT'] ?? '8000';

        $this->server = new Process([$this->binary(), 'serve', dirname(__DIR__, 2) . '/bin/cairo-worker.php', ...$this->watch()], base_path(), [
            'APP_ENV' => app()->environment(),
            'APP_BASE_PATH' => base_path(),
            'LARAVEL_OCTANE' => 1,
            'CAIRO_OCTANE_HOST' => $host,
            'CAIRO_OCTANE_PORT' => $port,
            'CAIRO_OCTANE_WORKERS' => $this->option('workers') === 'auto' ? '' : $this->option('workers'),
            'CAIRO_OCTANE_MAX_REQUESTS' => $this->option('max-requests'),
            'CAIRO_OCTANE_MODE' => $mode,
            'CAIRO_OCTANE_CONCURRENCY' => $this->option('concurrency'),
            'CAIRO_OCTANE_DETECT_LEAKS' => $this->option('detect-leaks') || config('octane.cairo.detect_leaks') ? '1' : '',
            'CAIRO_OCTANE_PUBLIC_PATH' => public_path(),
        ], null, null);
        $this->server->start();
        $state->write($this->server->getPid() ?? 0, $host, (int) $port, $mode);
        $this->components->info("Server running on http://{$host}:{$port} ({$mode} mode).");

        try {
            foreach ($this->server as $output) {
                $this->output->write($output);
            }
            return $this->server->getExitCode() ?? 0;
        } finally {
            $state->delete();
        }
    }

    /** @return list<int> */
    public function getSubscribedSignals(): array
    {
        return defined('SIGINT') ? [SIGINT, SIGTERM] : [];
    }

    public function handleSignal(int $signal, int|false $previousExitCode = 0): int|false
    {
        $this->server?->signal(SIGTERM);
        return false;   // keep running until Cairo has drained its requests
    }

    /**
     * With --watch, a --watch-path for each entry of config('octane.watch').
     * Cairo watches a directory with what is under it, so an entry such as
     * "config/**\/*.php" is its directory, and what does not exist is left
     * out. Cairo does the watching itself: no Node, no chokidar.
     *
     * @return list<string>
     */
    private function watch(): array
    {
        if (!$this->option('watch')) {
            return [];
        }
        $paths = [];
        foreach ((array) config('octane.watch', ['app', 'bootstrap', 'config', 'database', 'public', 'resources', 'routes', 'composer.lock', '.env']) as $entry) {
            $path = base_path(rtrim((string) preg_replace('~[^/]*[*?\[{].*$~', '', (string) $entry), '/'));
            if (file_exists($path)) {
                $paths[$path] = "--watch-path={$path}";
            }
        }
        return array_values($paths);
    }

    private function binary(): string
    {
        $binary = $this->option('binary') ?: config('octane.cairo.binary') ?: ($_ENV['CAIRO_BINARY'] ?? null)
            ?: (new ExecutableFinder())->find('cairo', null, [base_path()]);
        if (!is_string($binary) || !is_executable($binary)) {
            throw new \RuntimeException('The cairo binary was not found: put it on PATH or in the project root, or pass --binary.');
        }
        return $binary;
    }
}
