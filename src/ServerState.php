<?php

declare(strict_types=1);

namespace Cairo\Octane;

use Symfony\Component\Process\Process;

/** The running server's process id and address, for octane:stop, octane:reload and octane:status. */
final class ServerState
{
    public function __construct(private readonly string $path)
    {
    }

    public function write(int $pid, string $host, int $port, string $mode): void
    {
        file_put_contents($this->path, json_encode(['server' => 'cairo', 'pid' => $pid, 'host' => $host, 'port' => $port, 'mode' => $mode], JSON_THROW_ON_ERROR));
    }

    /** @return array{pid: int, host: string, port: int, mode: string}|null */
    public function read(): ?array
    {
        if (!is_file($this->path)) {
            return null;
        }
        $state = json_decode((string) file_get_contents($this->path), true);
        return is_array($state) && ($state['server'] ?? null) === 'cairo' && is_int($state['pid'] ?? null) ? $state : null;
    }

    public function running(): bool
    {
        $pid = $this->read()['pid'] ?? 0;
        if ($pid <= 0) {
            return false;
        }
        return function_exists('posix_kill') ? posix_kill($pid, 0) : is_dir("/proc/{$pid}");
    }

    public function stop(): bool
    {
        // SIGTERM: Cairo drains its requests, then exits.
        if ($this->signal(15)) {
            return true;
        }
        $this->delete();
        return false;
    }

    /** Has the server replace its PHP threads with ones that read the code anew. */
    public function reload(): bool
    {
        return $this->signal(defined('SIGUSR1') ? SIGUSR1 : 10);
    }

    private function signal(int $signal): bool
    {
        $pid = $this->read()['pid'] ?? 0;
        if ($pid <= 0 || !$this->running()) {
            return false;
        }
        if (function_exists('posix_kill')) {
            return posix_kill($pid, $signal);
        }
        return (new Process(['kill', "-{$signal}", (string) $pid]))->run() === 0;
    }

    public function delete(): void
    {
        if (is_file($this->path)) {
            unlink($this->path);
        }
    }
}
