<?php

declare(strict_types=1);

namespace Cairo\Octane\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * Starts `php artisan octane:start --server=cairo` on a Laravel app made by
 * tests/fixture/setup.sh (CAIRO_OCTANE_TEST_APP) with a cairo binary
 * (CAIRO_BINARY), on one PHP thread, and talks to it over HTTP.
 */
final class ServerTest extends TestCase
{
    private static ?Process $server = null;
    private static string $base = '';

    /** @return array<string, array{0: string}> */
    public static function modes(): array
    {
        return ['isolated' => ['isolated'], 'fibers' => ['fibers']];
    }

    #[DataProvider('modes')]
    public function testServesTheApp(string $mode): void
    {
        $this->start($mode);
        self::assertSame('hello', $this->get('/octane-test/hello')[1]);
        self::assertSame("User-agent: *\n", $this->get('/robots.txt')[1], 'public/ files are served');
        self::assertSame(404, $this->get('/missing.css')[0], 'a missing file reaches Laravel');
    }

    #[DataProvider('modes')]
    public function testStateDoesNotCarryOver(string $mode): void
    {
        $this->start($mode);
        self::assertSame(['user' => 7], $this->json('/octane-test/login/7'));
        self::assertSame(['user' => null], $this->json('/octane-test/whoami'));
        self::assertSame(['user' => null], $this->json('/octane-test/whoami'));
    }

    #[DataProvider('modes')]
    public function testConcurrentlyOverlapsTheTasks(string $mode): void
    {
        $this->start($mode);
        $out = $this->json('/octane-test/concurrently');
        self::assertSame(['a' => 1, 'b' => 2, 'c' => 3], $out['results']);
        self::assertLessThan(450, $out['ms'], 'three 200 ms tasks ran one after another');
        self::assertSame(500, $this->get('/octane-test/timeout')[0], 'a task past its wait ends the request');
    }

    public function testFiberModeRunsRequestsAtOnceWithTheirOwnState(): void
    {
        $this->start('fibers', '--detect-leaks');
        $n = 40;
        $multi = curl_multi_init();
        $handles = [];
        for ($i = 1; $i <= $n; $i++) {
            $handles[$i] = curl_init(self::$base . "/octane-test/mixed/{$i}");
            curl_setopt($handles[$i], CURLOPT_RETURNTRANSFER, true);
            curl_multi_add_handle($multi, $handles[$i]);
        }
        $start = microtime(true);
        do {
            curl_multi_exec($multi, $running);
            curl_multi_select($multi, 0.1);
        } while ($running > 0);
        $took = microtime(true) - $start;
        foreach ($handles as $i => $h) {
            $out = json_decode((string) curl_multi_getcontent($h), true);
            self::assertSame(['auth' => $i, 'route' => $i, 'url' => self::$base . "/octane-test/mixed/{$i}", 'db' => $i], $out, "request {$i} saw another request's state");
        }
        // 40 requests waiting 100 ms each, 8 at a time on one thread.
        self::assertLessThan(2.0, $took, sprintf('took %.2fs: requests did not overlap', $took));
        // Each of the 8 apps set its statics when it booted: all are its own.
        self::assertStringNotContainsString('static changed by a request', self::$server->getOutput() . self::$server->getErrorOutput());
    }

    protected function tearDown(): void
    {
        self::$server?->stop(10);
        self::$server = null;
    }

    private function start(string $mode, string ...$options): void
    {
        $app = getenv('CAIRO_OCTANE_TEST_APP');
        $binary = getenv('CAIRO_BINARY');
        if (!$app || !$binary) {
            self::markTestSkipped('set CAIRO_OCTANE_TEST_APP (see tests/fixture/setup.sh) and CAIRO_BINARY');
        }
        $port = self::freePort();
        self::$base = "http://127.0.0.1:{$port}";
        self::$server = new Process([PHP_BINARY, 'artisan', 'octane:cairo', "--port={$port}", '--workers=1', "--mode={$mode}", "--binary={$binary}", ...$options], $app);
        self::$server->start();
        $deadline = microtime(true) + 30;
        while (@file_get_contents(self::$base . '/octane-test/hello') !== 'hello') {
            if (microtime(true) > $deadline || !self::$server->isRunning()) {
                self::fail("the server did not start:\n" . self::$server->getOutput() . self::$server->getErrorOutput());
            }
            usleep(100_000);
        }
    }

    /** @return array{0: int, 1: string} status and body */
    private function get(string $path): array
    {
        $h = curl_init(self::$base . $path);
        curl_setopt($h, CURLOPT_RETURNTRANSFER, true);
        $body = (string) curl_exec($h);
        return [(int) curl_getinfo($h, CURLINFO_RESPONSE_CODE), $body];
    }

    /** @return array<string, mixed> */
    private function json(string $path): array
    {
        [$status, $body] = $this->get($path);
        self::assertSame(200, $status, $body);
        return json_decode($body, true, flags: JSON_THROW_ON_ERROR);
    }

    private static function freePort(): int
    {
        $s = stream_socket_server('tcp://127.0.0.1:0');
        $port = (int) substr(strrchr((string) stream_socket_get_name($s, false), ':'), 1);
        fclose($s);
        return $port;
    }
}
