<?php

declare(strict_types=1);

namespace Phycode\Exec;

use Phycode\Support\PhycodeException;

final class FilteringProxy
{
    private const HEAD_LIMIT = 16384;
    private const IDLE_SECONDS = 600;
    private const CONNECT_SECONDS = 5;
    private const CHUNK = 65536;
    private const BUFFER_LIMIT = 8_388_608;

    private array $connections = [];

    public function __construct(private readonly DomainAllowlist $allow, private readonly bool $allowPrivate = false, private readonly ?string $logFile = null)
    {
    }

    public function serve(string $socketPath): void
    {
        $parent = function_exists('posix_getppid') ? posix_getppid() : null;
        $server = @stream_socket_server('unix://' . $socketPath, $errno, $error);
        if ($server === false) {
            throw new PhycodeException("the filtering proxy cannot listen on {$socketPath}: {$error}");
        }
        chmod($socketPath, 0600);
        stream_set_blocking($server, false);
        while (true) {
            if ($parent !== null && posix_getppid() !== $parent) {
                return;
            }
            $this->step($server);
        }
    }

    private function step($server): void
    {
        $read = [$server];
        $write = [];
        foreach ($this->connections as $connection) {
            if (!$connection['clientEof']) {
                $read[] = $connection['client'];
            }
            if ($connection['upstream'] !== null && !$connection['upstreamEof']) {
                $read[] = $connection['upstream'];
            }
            if ($connection['toClient'] !== '') {
                $write[] = $connection['client'];
            }
            if ($connection['upstream'] !== null && $connection['toUpstream'] !== '') {
                $write[] = $connection['upstream'];
            }
        }
        $except = null;
        if (@stream_select($read, $write, $except, 1) === false) {
            return;
        }
        foreach ($read as $stream) {
            if ($stream === $server) {
                $client = @stream_socket_accept($server, 0);
                if ($client !== false) {
                    stream_set_blocking($client, false);
                    $this->connections[(int) $client] = ['client' => $client, 'upstream' => null, 'head' => '', 'tunnel' => false, 'toClient' => '', 'toUpstream' => '', 'clientEof' => false, 'upstreamEof' => false, 'closeAfterFlush' => false, 'at' => microtime(true)];
                }
                continue;
            }
            $this->readFrom($stream);
        }
        foreach ($write as $stream) {
            $this->writeTo($stream);
        }
        $now = microtime(true);
        foreach ($this->connections as $id => $connection) {
            $idle = $now - $connection['at'] > self::IDLE_SECONDS;
            $done = ($connection['closeAfterFlush'] && $connection['toClient'] === '')
                || ($connection['upstreamEof'] && $connection['toClient'] === '')
                || ($connection['clientEof'] && $connection['upstream'] === null)
                || strlen($connection['toClient']) > self::BUFFER_LIMIT || strlen($connection['toUpstream']) > self::BUFFER_LIMIT;
            if ($idle || $done) {
                $this->close($id);
            }
        }
    }

    private function readFrom($stream): void
    {
        foreach ($this->connections as $id => $connection) {
            $fromClient = $connection['client'] === $stream;
            if (!$fromClient && $connection['upstream'] !== $stream) {
                continue;
            }
            $chunk = @fread($stream, self::CHUNK);
            $ended = $chunk === false || ($chunk === '' && feof($stream));
            $this->connections[$id]['at'] = microtime(true);
            if ($ended) {
                $this->connections[$id][$fromClient ? 'clientEof' : 'upstreamEof'] = true;
                if ($fromClient && $connection['upstream'] !== null && $connection['toUpstream'] === '') {
                    @stream_socket_shutdown($connection['upstream'], STREAM_SHUT_WR);
                }

                return;
            }
            if ($chunk === '' || $chunk === false) {
                return;
            }
            if (!$fromClient) {
                $this->connections[$id]['toClient'] .= $chunk;
            } elseif ($connection['tunnel']) {
                $this->connections[$id]['toUpstream'] .= $chunk;
            } else {
                $this->connections[$id]['head'] .= $chunk;
                $this->head($id);
            }

            return;
        }
    }

    private function writeTo($stream): void
    {
        foreach ($this->connections as $id => $connection) {
            $toClient = $connection['client'] === $stream;
            if (!$toClient && $connection['upstream'] !== $stream) {
                continue;
            }
            $key = $toClient ? 'toClient' : 'toUpstream';
            $written = @fwrite($stream, $connection[$key]);
            if ($written === false) {
                $this->close($id);

                return;
            }
            $this->connections[$id][$key] = (string) substr($connection[$key], $written);
            if (!$toClient && $this->connections[$id][$key] === '' && $connection['clientEof']) {
                @stream_socket_shutdown($stream, STREAM_SHUT_WR);
            }

            return;
        }
    }

    private function head(int $id): void
    {
        $connection = $this->connections[$id];
        $end = strpos($connection['head'], "\r\n\r\n");
        if ($end === false) {
            if (strlen($connection['head']) > self::HEAD_LIMIT) {
                $this->deny($id, 431, 'the request is too large', '-');
            }

            return;
        }
        $headLines = explode("\r\n", substr($connection['head'], 0, $end));
        $rest = (string) substr($connection['head'], $end + 4);
        $line = array_shift($headLines);
        if (preg_match('~^CONNECT ([^\s:/]+):(\d{1,5}) HTTP/1\.[01]$~i', (string) $line, $match) === 1) {
            $this->connect($id, $match[1], (int) $match[2], null, $rest);

            return;
        }
        if (preg_match('~^([A-Z]+) http://([^\s/:]+)(?::(\d{1,5}))?(/\S*)? HTTP/1\.[01]$~i', (string) $line, $match) === 1) {
            $port = ($match[3] ?? '') !== '' ? (int) $match[3] : 80;
            $headers = array_values(array_filter($headLines, static fn (string $header): bool => preg_match('/^(proxy-[a-z-]+|connection|keep-alive):/i', $header) !== 1));
            $forward = $match[1] . ' ' . (($match[4] ?? '') !== '' ? $match[4] : '/') . " HTTP/1.1\r\n" . implode("\r\n", $headers) . "\r\nConnection: close\r\n\r\n" . $rest;
            $this->connect($id, $match[2], $port, $forward, '');

            return;
        }
        $this->deny($id, 405, 'only CONNECT and http:// requests are served by this proxy', (string) $line);
    }

    private function connect(int $id, string $host, int $port, ?string $forward, string $rest): void
    {
        $target = strtolower($host) . ':' . $port;
        if (!$this->allow->allows($host, $port)) {
            $this->deny($id, 403, "{$host}:{$port} is not in sandbox.allow_domains of the phycode config", $target);

            return;
        }
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            $this->deny($id, 403, 'a host is reached by its name, not by its address', $target);

            return;
        }
        $addresses = gethostbynamel($host) ?: [];
        if ($addresses === []) {
            $this->deny($id, 502, "{$host} does not resolve", $target);

            return;
        }
        foreach ($addresses as $address) {
            if (!$this->allowPrivate && self::inside($address)) {
                $this->deny($id, 403, "{$host} points to {$address}, an address on this machine or on a private network", $target);

                return;
            }
        }
        $upstream = @stream_socket_client('tcp://' . $addresses[0] . ':' . $port, $errno, $error, self::CONNECT_SECONDS);
        if ($upstream === false) {
            $this->deny($id, 502, "cannot connect to {$host}:{$port}: {$error}", $target);

            return;
        }
        stream_set_blocking($upstream, false);
        $this->log("allowed {$target}");
        $this->connections[$id]['upstream'] = $upstream;
        $this->connections[$id]['tunnel'] = true;
        $this->connections[$id]['head'] = '';
        if ($forward === null) {
            $this->connections[$id]['toClient'] = "HTTP/1.1 200 Connection Established\r\n\r\n";
            $this->connections[$id]['toUpstream'] = $rest;
        } else {
            $this->connections[$id]['toUpstream'] = $forward;
        }
    }

    private function deny(int $id, int $status, string $reason, string $target): void
    {
        $this->log("denied {$target}: {$reason}");
        $body = "phycode sandbox: {$reason}\n";
        $names = [403 => 'Forbidden', 405 => 'Method Not Allowed', 431 => 'Request Header Fields Too Large', 502 => 'Bad Gateway'];
        $this->connections[$id]['toClient'] = "HTTP/1.1 {$status} " . ($names[$status] ?? 'Error') . "\r\nContent-Type: text/plain\r\nConnection: close\r\nContent-Length: " . strlen($body) . "\r\n\r\n{$body}";
        $this->connections[$id]['closeAfterFlush'] = true;
        $this->connections[$id]['head'] = '';
    }

    private function close(int $id): void
    {
        $connection = $this->connections[$id] ?? null;
        if ($connection === null) {
            return;
        }
        @fclose($connection['client']);
        if ($connection['upstream'] !== null) {
            @fclose($connection['upstream']);
        }
        unset($this->connections[$id]);
    }

    public static function inside(string $address): bool
    {
        if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return true;
        }
        $long = ip2long($address);

        return $long !== false && ($long & 0xFFC00000) === ip2long('100.64.0.0');
    }

    private function log(string $line): void
    {
        if ($this->logFile !== null) {
            @file_put_contents($this->logFile, gmdate('c') . ' ' . $line . "\n", FILE_APPEND);
        }
    }
}
