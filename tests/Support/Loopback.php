<?php

declare(strict_types=1);

namespace VoltKit\Rtc\Tests\Support;

/**
 * Starts tests/Support/loopback-server.php as a child process and stops it by
 * PID. A real server on 127.0.0.1 for the real-transport tests (spec 11, item 3).
 */
final class Loopback
{
    /** @var resource */
    private $process;
    private readonly int $pid;
    public readonly int $port;
    public readonly string $logFile;

    private function __construct()
    {
        $log = tempnam(sys_get_temp_dir(), 'voltkit-loopback-');
        if ($log === false) {
            throw new \RuntimeException('cannot create the loopback log file');
        }
        $this->logFile = $log;
        $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $process = proc_open([PHP_BINARY, __DIR__ . '/loopback-server.php', $log], $descriptors, $pipes);
        if ($process === false) {
            throw new \RuntimeException('cannot start the loopback server');
        }
        $this->process = $process;
        $status = proc_get_status($process);
        $this->pid = (int) $status['pid'];
        stream_set_timeout($pipes[1], 10);
        $line = fgets($pipes[1]);
        if (!is_string($line) || !str_starts_with($line, 'PORT ')) {
            $error = stream_get_contents($pipes[2]);
            $this->stop();
            throw new \RuntimeException('the loopback server did not report its port: ' . var_export($line, true) . ' ' . $error);
        }
        $this->port = (int) trim(substr($line, 5));
        fclose($pipes[0]);
    }

    public static function start(): self
    {
        return new self();
    }

    public function baseUrl(string $scenario): string
    {
        return "http://127.0.0.1:{$this->port}/$scenario";
    }

    /**
     * Every request the server has received so far.
     *
     * @return list<array{method: string, path: string, headers: array<string, string>, body: string}>
     */
    public function requests(): array
    {
        clearstatcache(true, $this->logFile);
        $text = file_get_contents($this->logFile);
        if ($text === false || trim($text) === '') {
            return [];
        }
        $out = [];
        foreach (explode("\n", trim($text)) as $line) {
            $decoded = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
            assert(is_array($decoded));
            /** @var array{method: string, path: string, headers: array<string, string>, body: string} $decoded */
            $out[] = $decoded;
        }
        return $out;
    }

    public function forget(): void
    {
        file_put_contents($this->logFile, '');
    }

    public function stop(): void
    {
        if (is_resource($this->process)) {
            proc_terminate($this->process, 15);
            usleep(50_000);
            if (proc_get_status($this->process)['running']) {
                proc_terminate($this->process, 9);
            }
            proc_close($this->process);
        }
        if (function_exists('posix_kill')) {
            @posix_kill($this->pid, 9);
        }
        @unlink($this->logFile);
    }

    public function __destruct()
    {
        $this->stop();
    }
}
