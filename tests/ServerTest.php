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

    #[DataProvider('modes')]
    public function testStreamedResponsesArriveWhileTheyAreMade(string $mode): void
    {
        $this->start($mode);
        [$early, $body] = $this->timed('/octane-test/stream');
        self::assertSame("part 1\npart 2 for nobody\n", $body);
        self::assertLessThan(0.25, $early, 'the first part waited for the second');

        [$early, $body] = $this->timed('/octane-test/events');
        self::assertStringStartsWith("event: update\ndata: one\n\nevent: update\ndata: two\n\n", $body);
        self::assertLessThan(0.25, $early, 'the first event waited for the second');

        $file = (string) file_get_contents(getenv('CAIRO_OCTANE_TEST_APP') . '/composer.json');
        self::assertSame([200, $file], $this->get('/octane-test/download'));
        self::assertSame([206, substr($file, 3, 5)], $this->get('/octane-test/download', ['Range: bytes=3-7']));
        // The app that streamed is as clean for the next request as any.
        self::assertSame(['user' => null], $this->json('/octane-test/whoami'));
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

    public function testIsolatedModeReplacesAThreadAfterMaxRequests(): void
    {
        $this->start('isolated', '--max-requests=3');
        self::assertNotNull($this->servedByOneThread(50, 400), 'the thread was never replaced');
    }

    public function testFiberModeCountsMaxRequestsForEachApp(): void
    {
        // One thread with 4 apps: replaced after 3 x 4 requests, one of
        // which the start has made.
        $this->start('fibers', '--max-requests=3', '--concurrency=4');
        $served = $this->servedByOneThread(100, 200);
        self::assertNotNull($served, 'the thread was never replaced');
        self::assertGreaterThanOrEqual(11, $served);
    }

    public function testMaxRequestsOfZeroNeverReplacesAThread(): void
    {
        $this->start('isolated', '--max-requests=0');
        self::assertNull($this->servedByOneThread(2, 700));
    }

    #[DataProvider('modes')]
    public function testReloadGivesTheWorkersNewCode(string $mode): void
    {
        $this->probe('one', time() - 10);
        $this->start($mode);
        self::assertSame('one', $this->get('/octane-test/probe')[1]);

        $this->probe('two', time());
        self::assertSame('one', $this->get('/octane-test/probe')[1], 'a worker keeps the code it has loaded');
        $reload = new Process([PHP_BINARY, 'artisan', 'octane:reload', '--server=cairo'], (string) getenv('CAIRO_OCTANE_TEST_APP'));
        self::assertSame(0, $reload->run(), $reload->getOutput() . $reload->getErrorOutput());
        $this->waitForProbe('two');
        self::assertSame(['user' => null], $this->json('/octane-test/whoami'));
    }

    #[DataProvider('modes')]
    public function testWatchReloadsWhenAFileChanges(string $mode): void
    {
        $this->probe('one', time() - 10);
        $this->start($mode, '--watch');
        self::assertSame('one', $this->get('/octane-test/probe')[1]);
        $this->probe('two', time());
        $this->waitForProbe('two');
    }

    protected function tearDown(): void
    {
        self::$server?->stop(10);
        self::$server = null;
        $probe = getenv('CAIRO_OCTANE_TEST_APP') . '/app/ReloadProbe.php';
        if (is_file($probe)) {
            unlink($probe);
        }
    }

    /** Writes app/ReloadProbe.php with $value, changed at $time. */
    private function probe(string $value, int $time): void
    {
        $app = getenv('CAIRO_OCTANE_TEST_APP');
        if (!$app) {
            self::markTestSkipped('set CAIRO_OCTANE_TEST_APP (see tests/fixture/setup.sh) and CAIRO_BINARY');
        }
        $file = $app . '/app/ReloadProbe.php';
        file_put_contents($file . '.next', "<?php\n\nnamespace App;\n\nfinal class ReloadProbe\n{\n    public const VALUE = '{$value}';\n}\n");
        touch($file . '.next', $time);
        rename($file . '.next', $file);
    }

    private function waitForProbe(string $value): void
    {
        $deadline = microtime(true) + 15;
        while (($got = $this->get('/octane-test/probe')[1]) !== $value) {
            if (microtime(true) > $deadline) {
                self::fail("the workers still answer '{$got}', not '{$value}':\n" . self::$server?->getOutput() . self::$server?->getErrorOutput());
            }
            usleep(50_000);
        }
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

    /**
     * @param list<string> $headers
     * @return array{0: int, 1: string} status and body
     */
    private function get(string $path, array $headers = []): array
    {
        $h = curl_init(self::$base . $path);
        curl_setopt($h, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($h, CURLOPT_HTTPHEADER, $headers);
        $body = (string) curl_exec($h);
        return [(int) curl_getinfo($h, CURLINFO_RESPONSE_CODE), $body];
    }

    /** @return array{0: float, 1: string} the seconds until the body's first bytes, and the body */
    private function timed(string $path): array
    {
        $start = microtime(true);
        $early = null;
        $body = '';
        $h = curl_init(self::$base . $path);
        curl_setopt($h, CURLOPT_WRITEFUNCTION, static function ($h, string $piece) use (&$early, &$body, $start): int {
            $early ??= microtime(true) - $start;
            $body .= $piece;
            return strlen($piece);
        });
        curl_exec($h);
        return [(float) $early, $body];
    }

    /** @return array<string, mixed> */
    private function json(string $path): array
    {
        [$status, $body] = $this->get($path);
        self::assertSame(200, $status, $body);
        return json_decode($body, true, flags: JSON_THROW_ON_ERROR);
    }

    /**
     * How many requests in a row, each waiting $ms, the thread that answers
     * the first one answers before another thread does; null when it
     * answered all $most.
     */
    private function servedByOneThread(int $ms, int $most): ?int
    {
        $first = $this->json("/octane-test/thread/{$ms}")['thread'];
        for ($n = 1; $n < $most; $n++) {
            if ($this->json("/octane-test/thread/{$ms}")['thread'] !== $first) {
                return $n;
            }
        }
        return null;
    }

    private static function freePort(): int
    {
        $s = stream_socket_server('tcp://127.0.0.1:0');
        $port = (int) substr(strrchr((string) stream_socket_get_name($s, false), ':'), 1);
        fclose($s);
        return $port;
    }
}
