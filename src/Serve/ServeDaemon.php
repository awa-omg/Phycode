<?php

declare(strict_types=1);

namespace Phycode\Serve;

use Phycode\Cli\ChatSession;
use Phycode\Cli\Kernel;
use Phycode\Rpc\JsonLines;
use Phycode\Rpc\RpcServer;
use Phycode\Rpc\RpcUi;
use Phycode\Support\PhycodeException;

final class ServeDaemon
{
    public const HELLO_SECONDS = 10.0;
    public const MAX_LINE = 16_777_216;
    private const STOP_SECONDS = 5.0;
    private const MIN_TOKEN = 16;

    private array $children = [];
    private bool $stopping = false;

    public function __construct(
        private readonly string $address,
        private readonly ?string $token,
        private readonly \Closure $session,
        private readonly string $version,
        private readonly string $defaultFolder,
        private readonly int $maxConnections = 8,
    ) {
        if (!function_exists('pcntl_fork') || !function_exists('posix_getppid')) {
            throw new PhycodeException('phycode serve needs the pcntl and posix extensions of PHP');
        }
        if (str_starts_with($address, 'tcp://') && $token === null) {
            throw new PhycodeException('a TCP port needs a token: give --token-file');
        }
        if ($maxConnections < 1 || $maxConnections > 256) {
            throw new PhycodeException('--max-connections must be from 1 to 256');
        }
    }

    public static function address(?string $socket, ?string $listen, string $default): string
    {
        if ($socket !== null && $listen !== null) {
            throw new PhycodeException('give --socket or --listen, not both');
        }
        if ($listen !== null) {
            if (preg_match('/^(\[::1\]|127\.0\.0\.1|localhost):(\d{1,5})$/D', $listen, $match) !== 1 || (int) $match[2] > 65535) {
                throw new PhycodeException('--listen takes 127.0.0.1:PORT, localhost:PORT or [::1]:PORT: the daemon is not for other machines (use ssh -L to reach it)');
            }

            return 'tcp://' . ($match[1] === 'localhost' ? '127.0.0.1' : $match[1]) . ':' . $match[2];
        }
        $path = $socket ?? $default;
        if (!str_starts_with($path, '/') || strlen($path) > 100 || str_contains($path, "\0")) {
            throw new PhycodeException('the socket must be an absolute path of at most 100 bytes');
        }

        return 'unix://' . $path;
    }

    public static function defaultSocket(): string
    {
        $runtime = (string) getenv('XDG_RUNTIME_DIR');
        $dir = str_starts_with($runtime, '/') ? rtrim($runtime, '/') . '/phycode' : sys_get_temp_dir() . '/phycode-' . posix_geteuid();
        if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new PhycodeException("cannot make the folder {$dir}");
        }
        if (is_link($dir) || fileowner($dir) !== posix_geteuid() || (fileperms($dir) & 0077) !== 0) {
            throw new PhycodeException("the folder {$dir} must be yours and closed to others (chmod 700), or give --socket");
        }

        return $dir . '/serve.sock';
    }

    public static function token(string $file): string
    {
        if (!file_exists($file)) {
            $handle = @fopen($file, 'xb');
            if ($handle === false) {
                throw new PhycodeException("cannot make the token file {$file}");
            }
            chmod($file, 0600);
            fwrite($handle, bin2hex(random_bytes(32)) . "\n");
            fclose($handle);
        }
        if (is_link($file) || !is_file($file) || (fileperms($file) & 0077) !== 0 || fileowner($file) !== posix_geteuid()) {
            throw new PhycodeException("the token file {$file} must be a file of yours that nobody else can read (chmod 600)");
        }
        $token = trim((string) file_get_contents($file));
        if (strlen($token) < self::MIN_TOKEN) {
            throw new PhycodeException("the token in {$file} must be at least " . self::MIN_TOKEN . ' characters');
        }

        return $token;
    }

    public function run(): int
    {
        $server = $this->bind();
        pcntl_async_signals(true);
        foreach ([SIGTERM, SIGINT, SIGHUP] as $signal) {
            pcntl_signal($signal, function (): void {
                $this->stopping = true;
            });
        }
        $name = (string) stream_socket_get_name($server, false);
        fwrite(STDOUT, 'listening ' . (str_starts_with($this->address, 'unix://') ? $this->address : 'tcp://' . $name) . "\n");
        fflush(STDOUT);
        try {
            while (!$this->stopping) {
                $this->reap();
                $read = [$server];
                $write = null;
                $except = null;
                if (@stream_select($read, $write, $except, 1) !== 1) {
                    continue;
                }
                $connection = @stream_socket_accept($server, 0);
                if ($connection !== false) {
                    $this->accept($connection, $server);
                }
            }
        } finally {
            $this->shutdown($server);
        }

        return 0;
    }

    private function bind()
    {
        if (str_starts_with($this->address, 'unix://')) {
            $path = substr($this->address, 7);
            $this->clearStale($path);
            $old = umask(0177);
            $server = @stream_socket_server($this->address, $errno, $error);
            umask($old);
            if ($server === false) {
                throw new PhycodeException("cannot listen on {$path}: {$error}");
            }
            chmod($path, 0600);

            return $server;
        }
        $server = @stream_socket_server($this->address, $errno, $error);
        if ($server === false) {
            throw new PhycodeException("cannot listen on {$this->address}: {$error}");
        }

        return $server;
    }

    private function clearStale(string $path): void
    {
        if (!file_exists($path) && !is_link($path)) {
            return;
        }
        if (is_link($path) || filetype($path) !== 'socket') {
            throw new PhycodeException("{$path} exists and is not a socket, so phycode serve will not replace it");
        }
        $probe = @stream_socket_client('unix://' . $path, $errno, $error, 1);
        if ($probe !== false) {
            fclose($probe);

            throw new PhycodeException("another phycode serve already listens on {$path}");
        }
        unlink($path);
    }

    private function accept($connection, $server): void
    {
        if (count($this->children) >= $this->maxConnections) {
            self::refuse($connection, 'too many connections: the daemon serves ' . $this->maxConnections . ' at a time');

            return;
        }
        $pid = pcntl_fork();
        if ($pid === -1) {
            self::refuse($connection, 'the daemon cannot start a session now');

            return;
        }
        if ($pid === 0) {
            fclose($server);
            exit($this->serve($connection));
        }
        fclose($connection);
        $this->children[$pid] = true;
    }

    private function serve($connection): int
    {
        $this->children = [];
        pcntl_signal(SIGTERM, static function (): never {
            exit(0);
        });
        pcntl_signal(SIGHUP, static function (): never {
            exit(0);
        });
        pcntl_signal(SIGINT, static function (): void {
        });
        $channel = new JsonLines($connection, $connection, self::MAX_LINE);
        try {
            $hello = $channel->next(self::HELLO_SECONDS);
            if ($hello === null || ($hello['type'] ?? null) !== 'hello') {
                $channel->send(['type' => 'error', 'message' => 'send {"type":"hello", ...} first']);

                return 2;
            }
            if ($this->token !== null && !hash_equals($this->token, is_string($hello['token'] ?? null) ? $hello['token'] : '')) {
                usleep(300000);
                $channel->send(['type' => 'error', 'message' => 'refused: the token is not right']);
                fwrite(STDERR, "serve: a connection was refused (bad token)\n");

                return 3;
            }
            $folder = $hello['cwd'] ?? $this->defaultFolder;
            if (!is_string($folder) || !str_starts_with($folder, '/') || !is_dir($folder)) {
                $channel->send(['type' => 'error', 'message' => 'cwd must be the absolute path of a folder that exists']);

                return 2;
            }
            $names = [];
            foreach (['profile', 'agent'] as $key) {
                $value = $hello[$key] ?? null;
                if ($value !== null && (!is_string($value) || preg_match('/^[A-Za-z0-9_.-]{1,64}$/D', $value) !== 1)) {
                    $channel->send(['type' => 'error', 'message' => "{$key} must be a name"]);

                    return 2;
                }
                $names[$key] = $value;
            }

            $ui = new RpcUi($channel);
            try {
                [$kernel, $chat] = ($this->session)($folder, $names['profile'], $names['agent'], $ui);
            } catch (\Throwable $error) {
                fwrite(STDERR, 'serve: ' . $error->getMessage() . PHP_EOL);
                $channel->send(['type' => 'error', 'message' => $error->getMessage()]);

                return 1;
            }
            $ui->attach(static fn (): string => $chat->primary()->name, static fn () => $chat->interrupt());

            return (new RpcServer($channel, $ui, $kernel, $chat, $this->version))->run();
        } finally {
            @fclose($connection);
        }
    }

    private static function refuse($connection, string $message): void
    {
        @fwrite($connection, json_encode(['type' => 'error', 'message' => $message]) . "\n");
        fclose($connection);
    }

    private function reap(): void
    {
        while (($pid = pcntl_waitpid(-1, $status, WNOHANG)) > 0) {
            unset($this->children[$pid]);
        }
    }

    private function shutdown($server): void
    {
        fclose($server);
        if (str_starts_with($this->address, 'unix://')) {
            @unlink(substr($this->address, 7));
        }
        foreach (array_keys($this->children) as $pid) {
            posix_kill($pid, SIGTERM);
        }
        $deadline = microtime(true) + self::STOP_SECONDS;
        while ($this->children !== [] && microtime(true) < $deadline) {
            $this->reap();
            usleep(50000);
        }
        foreach (array_keys($this->children) as $pid) {
            posix_kill($pid, SIGKILL);
        }
        $this->reap();
    }
}
