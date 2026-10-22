<?php

declare(strict_types=1);

namespace Phycode\Browser;

use Phycode\Exec\Backgroundable;
use Phycode\Exec\RunningProcess;
use Phycode\Support\Json;
use Phycode\Support\PhycodeException;
use Phycode\Support\Text;

final class ChromeProcess
{
    public const LIFETIME_SECONDS = 3600;

    private const WATCHDOG = 'parent=$1; profile=$2; shift 2; "$@" & child=$!; '
        . 'trap \'kill "$child" 2>/dev/null; wait "$child"; sleep 1; case "$profile" in */phycode-browser-*) rm -rf -- "$profile";; esac; exit 0\' TERM INT HUP; '
        . 'gone() { if [ -r /proc/self/stat ]; then [ ! -r "/proc/$parent/stat" ] || grep -q \') Z \' "/proc/$parent/stat"; '
        . 'else case "$(ps -o stat= -p "$parent" 2>/dev/null)" in ""|Z*) return 0;; esac; return 1; fi; }; '
        . 'while kill -0 "$child" 2>/dev/null; do '
        . 'if gone; then '
        . 'kill "$child" 2>/dev/null; wait "$child"; sleep 1; case "$profile" in */phycode-browser-*) rm -rf -- "$profile";; esac; exit 0; fi; '
        . 'sleep 0.5; done; wait "$child"';
    private const PATH_PATTERN = '~^/devtools/browser/[A-Za-z0-9-]+$~D';

    private bool $stopped = false;

    private function __construct(
        private readonly RunningProcess $process,
        private readonly string $profile,
        public readonly int $port,
        public readonly string $webSocketUrl,
    ) {
    }

    public static function start(string $binary, Backgroundable $runner, bool $noSandbox, float $timeoutSeconds = 20.0): self
    {
        $profile = rtrim(sys_get_temp_dir(), '/') . '/phycode-browser-' . bin2hex(random_bytes(8));
        if (!@mkdir($profile, 0700)) {
            throw new PhycodeException("cannot create {$profile}, the throw-away profile folder of the browser; check that the temporary folder is writable");
        }
        $flags = [
            '--headless=new',
            '--remote-debugging-port=0',
            '--user-data-dir=' . $profile,
            '--no-first-run',
            '--no-default-browser-check',
            '--disable-background-networking',
            '--disable-sync',
            '--disable-component-update',
            '--disable-extensions',
            '--disable-dev-shm-usage',
            '--no-proxy-server',
            '--mute-audio',
            '--window-size=1280,800',
            ...($noSandbox ? ['--no-sandbox'] : []),
            'about:blank',
        ];
        $log = $profile . '/browser.log';
        try {
            $process = $runner->start(['/bin/sh', '-c', self::WATCHDOG, 'sh', (string) getmypid(), $profile, $binary, ...$flags], $profile, $log, self::LIFETIME_SECONDS, [
                'XDG_CONFIG_HOME' => $profile . '/config',
                'XDG_CACHE_HOME' => $profile . '/cache',
            ]);
        } catch (\Throwable $error) {
            self::remove($profile);

            throw $error;
        }
        $chrome = null;
        try {
            [$port, $url] = self::await($process, $profile, $log, $timeoutSeconds);
            $chrome = new self($process, $profile, $port, $url);
        } finally {
            if ($chrome === null) {
                $process->stop();
                self::remove($profile);
            }
        }
        register_shutdown_function([$chrome, 'stop']);

        return $chrome;
    }

    public function running(): bool
    {
        return !$this->stopped && $this->process->running();
    }

    public function stop(): void
    {
        if ($this->stopped) {
            return;
        }
        $this->stopped = true;
        $this->process->stop();
        self::remove($this->profile);
    }

    private static function await(RunningProcess $process, string $profile, string $log, float $timeoutSeconds): array
    {
        $deadline = microtime(true) + $timeoutSeconds;
        while (microtime(true) < $deadline) {
            if (!$process->running()) {
                throw new PhycodeException('the browser exited while starting: ' . self::why($log));
            }
            $port = self::announcedPort($profile . '/DevToolsActivePort');
            $url = $port === null ? null : self::webSocketUrl($port);
            if ($port !== null && $url !== null) {
                return [$port, $url];
            }
            usleep(50000);
        }

        throw new PhycodeException("the browser did not open its debugging port within {$timeoutSeconds}s: " . self::why($log));
    }

    private static function announcedPort(string $file): ?int
    {
        $lines = is_file($file) ? explode("\n", trim((string) file_get_contents($file))) : [];
        if (count($lines) < 2 || preg_match('/^[0-9]{1,5}$/D', $lines[0]) !== 1 || preg_match(self::PATH_PATTERN, $lines[1]) !== 1) {
            return null;
        }
        $port = (int) $lines[0];

        return $port >= 1 && $port <= 65535 ? $port : null;
    }

    private static function webSocketUrl(int $port): ?string
    {
        $curl = curl_init("http://127.0.0.1:{$port}/json/version");
        if ($curl === false) {
            return null;
        }
        curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 3, CURLOPT_CONNECTTIMEOUT => 2, CURLOPT_NOPROXY => '*', CURLOPT_PROTOCOLS => CURLPROTO_HTTP, CURLOPT_MAXFILESIZE => 65536]);
        $body = curl_exec($curl);
        $status = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        curl_close($curl);
        $version = is_string($body) && $status === 200 ? Json::object($body) : null;
        $url = is_array($version) ? ($version['webSocketDebuggerUrl'] ?? null) : null;
        $parts = is_string($url) ? parse_url($url) : false;
        $local = is_array($parts) && ($parts['scheme'] ?? '') === 'ws' && in_array($parts['host'] ?? '', ['127.0.0.1', 'localhost'], true) && ($parts['port'] ?? 0) === $port;

        return $local ? $url : null;
    }

    private static function why(string $log): string
    {
        $text = Text::oneLine((string) @file_get_contents($log, false, null, max(0, (int) @filesize($log) - 600)), 400);
        $hint = stripos($text, 'sandbox') !== false ? ' (if it cannot start its sandbox, as in a container or as root, set browser.no_sandbox to true)' : '';

        return ($text === '' ? 'it printed nothing' : $text) . $hint;
    }

    private static function remove(string $folder): void
    {
        if (!is_dir($folder) || is_link($folder)) {
            return;
        }
        $entries = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($folder, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($entries as $entry) {
            $entry->isDir() && !$entry->isLink() ? @rmdir($entry->getPathname()) : @unlink($entry->getPathname());
        }
        @rmdir($folder);
    }
}
