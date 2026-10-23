<?php

/**
 * A tiny HTTP/1.1 server on a loopback socket, run as a child process by the
 * real-transport tests (tests/Support/Loopback.php). No php -S: it must be
 * able to stall a body, drop a connection mid-body, close without a reply,
 * and record exactly what each request carried.
 *
 * Usage: php loopback-server.php <log file>
 * Prints "PORT <n>" on stdout once it listens. Every request is appended to
 * the log file as one JSON line: {"method","path","headers","body"}.
 *
 * The behaviour is chosen by the first path segment, so a test points the
 * client's baseUrl at http://127.0.0.1:<port>/<scenario>:
 *   /ok             200 {"ok":true}, or the scenario's own answer below
 *   /echo           200 with the request echoed as JSON
 *   /stall          headers, the start of a body, then nothing until the client leaves
 *   /break          headers announcing 100 bytes, 9 bytes, then the socket closed
 *   /break-once     as /break on the first hit of the path, {"rooms":[]} after
 *   /drop-once      the connection closed with no reply on the first hit, then
 *                   404 {"error":"no-room","message":"room not found"}
 *   /redirect       302 to /landed<path> on every method
 *   /landed         200 {"landed":true}
 *   /rates-redirect 302 to /rates<path>
 *   /rates          200 {"success":true,"data":{...}} (the website envelope)
 *   /html           200 text/html, not JSON
 *   /slow           headers, half a body, 60 ms, the rest
 *   /status/<n>     that status, {"error":"from-the-server","message":"m"}
 */

declare(strict_types=1);

$logFile = $argv[1] ?? null;
if (!is_string($logFile)) {
    fwrite(STDERR, "usage: php loopback-server.php <log file>\n");
    exit(2);
}

$server = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
if ($server === false) {
    fwrite(STDERR, "cannot listen: $errstr\n");
    exit(1);
}
$name = stream_socket_get_name($server, false);
$port = is_string($name) ? (int) substr($name, (int) strrpos($name, ':') + 1) : 0;
fwrite(STDOUT, "PORT $port\n");
fflush(STDOUT);

/** @var array<string, int> $hits */
$hits = [];

/**
 * @param resource $client
 * @param array<string, string> $headers
 */
function reply($client, int $status, array $headers, string $body, bool $announceLength = true): void
{
    $lines = ["HTTP/1.1 $status " . ($status === 200 ? 'OK' : 'Status')];
    foreach ($headers as $k => $v) {
        $lines[] = "$k: $v";
    }
    if ($announceLength) {
        $lines[] = 'Content-Length: ' . strlen($body);
    }
    $lines[] = 'Connection: close';
    fwrite($client, implode("\r\n", $lines) . "\r\n\r\n" . $body);
    fflush($client);
}

function json(mixed $value): string
{
    return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
}

/**
 * Wait until the client goes away (closes the connection), at most $seconds.
 *
 * @param resource $client
 */
function waitForClientToLeave($client, float $seconds): void
{
    $deadline = microtime(true) + $seconds;
    while (microtime(true) < $deadline) {
        $read = [$client];
        $write = null;
        $except = null;
        $left = max(0.01, $deadline - microtime(true));
        $n = @stream_select($read, $write, $except, (int) $left, (int) (($left - (int) $left) * 1_000_000));
        if ($n === false) {
            return;
        }
        if ($n > 0) {
            $chunk = fread($client, 1024);
            if ($chunk === false || $chunk === '' || feof($client)) {
                return;
            }
        }
    }
}

for (;;) {
    $client = @stream_socket_accept($server, 3600);
    if ($client === false) {
        continue;
    }
    stream_set_timeout($client, 5);
    $head = '';
    while (!str_contains($head, "\r\n\r\n")) {
        $chunk = fread($client, 8192);
        if ($chunk === false || $chunk === '') {
            break;
        }
        $head .= $chunk;
    }
    if (!str_contains($head, "\r\n\r\n")) {
        fclose($client);
        continue;
    }
    [$headText, $rest] = explode("\r\n\r\n", $head, 2);
    $headLines = explode("\r\n", $headText);
    $requestLine = array_shift($headLines);
    $parts = explode(' ', $requestLine);
    $method = $parts[0] ?? '';
    $target = $parts[1] ?? '/';
    /** @var array<string, string> $headers */
    $headers = [];
    foreach ($headLines as $line) {
        $colon = strpos($line, ':');
        if ($colon === false) {
            continue;
        }
        $headers[strtolower(substr($line, 0, $colon))] = ltrim(substr($line, $colon + 1));
    }
    $length = (int) ($headers['content-length'] ?? '0');
    $body = $rest;
    while (strlen($body) < $length) {
        $chunk = fread($client, max(1, $length - strlen($body)));
        if ($chunk === false || $chunk === '') {
            break;
        }
        $body .= $chunk;
    }
    file_put_contents(
        $logFile,
        json(['method' => $method, 'path' => $target, 'headers' => $headers, 'body' => $body]) . "\n",
        FILE_APPEND | LOCK_EX,
    );

    $segments = explode('/', ltrim(explode('?', $target, 2)[0], '/'));
    $scenario = $segments[0] ?? '';
    $restOfPath = substr($target, strlen('/' . $scenario));
    $hits[$target] = ($hits[$target] ?? 0) + 1;
    $json = ['Content-Type' => 'application/json'];

    switch ($scenario) {
        case 'echo':
            reply($client, 200, $json, json(['method' => $method, 'path' => $target, 'headers' => $headers, 'body' => $body]));
            break;
        case 'stall':
            fwrite($client, "HTTP/1.1 200 OK\r\nContent-Type: application/json\r\nContent-Length: 100\r\nConnection: close\r\n\r\n{\"rooms\":");
            fflush($client);
            waitForClientToLeave($client, 3.0);
            break;
        case 'break':
            fwrite($client, "HTTP/1.1 200 OK\r\nContent-Type: application/json\r\nContent-Length: 100\r\nConnection: close\r\n\r\n{\"rooms\":");
            fflush($client);
            break;
        case 'break-once':
            if ($hits[$target] === 1) {
                fwrite($client, "HTTP/1.1 200 OK\r\nContent-Type: application/json\r\nContent-Length: 100\r\nConnection: close\r\n\r\n{\"rooms\":");
                fflush($client);
            } else {
                reply($client, 200, $json, '{"rooms":[]}');
            }
            break;
        case 'drop-once':
            if ($hits[$target] === 1) {
                // deleted... and the reply is lost
            } else {
                reply($client, 404, $json, '{"error":"no-room","message":"room not found"}');
            }
            break;
        case 'redirect':
            reply($client, 302, ['Location' => "http://127.0.0.1:$port/landed$restOfPath"], '');
            break;
        case 'landed':
            reply($client, 200, $json, '{"landed":true}');
            break;
        case 'rates-redirect':
            reply($client, 302, ['Location' => "http://127.0.0.1:$port/rates$restOfPath"], '');
            break;
        case 'rates':
            reply($client, 200, $json, json(['success' => true, 'data' => ['version' => 3, 'kind' => 'standard', 'tiers' => []]]));
            break;
        case 'html':
            reply($client, 200, ['Content-Type' => 'text/html'], '<html>proxy</html>');
            break;
        case 'slow':
            fwrite($client, "HTTP/1.1 200 OK\r\nContent-Type: application/json\r\nContent-Length: 12\r\nConnection: close\r\n\r\n{\"rooms\":");
            fflush($client);
            usleep(60_000);
            fwrite($client, '[]}');
            fflush($client);
            break;
        case 'status':
            $status = (int) ($segments[1] ?? '500');
            reply($client, $status, $json, '{"error":"from-the-server","message":"m"}');
            break;
        default:
            reply($client, 200, $json, '{"ok":true}');
    }
    fclose($client);
}
