<?php

declare(strict_types=1);

namespace Phycode\Btw;

use Phycode\Support\Json;
use Phycode\Support\PhycodeException;
use Phycode\Support\Text;

final class BtwJob
{
    private const READ_CHUNK = 65536;
    private const OUTPUT_LIMIT = 1048576;
    private const ERROR_TAIL = 4096;

    private $stdin;
    private $stdout;
    private $stderr;
    private string $output = '';
    private string $errors = '';
    private ?int $exitCode = null;
    private bool $timedOut = false;
    private bool $closed = false;
    private float $startedAt;

    private function __construct(
        private readonly string $question,
        private readonly mixed $process,
        array $pipes,
        private string $pending,
        private readonly float $timeout,
    ) {
        $this->startedAt = microtime(true);
        $this->stdin = $pipes[0];
        $this->stdout = $pipes[1];
        $this->stderr = $pipes[2];
        foreach ([$this->stdin, $this->stdout, $this->stderr] as $pipe) {
            stream_set_blocking($pipe, false);
        }
    }

    public static function start(array $command, string $question, array $request, float $timeout): self
    {
        $cwd = is_string($request['cwd'] ?? null) ? $request['cwd'] : null;
        $pipes = [];
        $process = @proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $cwd);
        if (!is_resource($process)) {
            throw new PhycodeException('the side question process cannot be started');
        }

        return new self($question, $process, $pipes, Json::encode($request), $timeout);
    }

    public function pump(): bool
    {
        if ($this->closed) {
            return true;
        }
        if (is_resource($this->stdin)) {
            $this->sendRequest();
        }
        $this->collect();
        $status = proc_get_status($this->process);
        if (!$status['running']) {
            $this->exitCode = $status['exitcode'];
            $this->collect();
            $this->release();

            return true;
        }
        if (microtime(true) - $this->startedAt > $this->timeout) {
            $this->timedOut = true;
            $this->release();

            return true;
        }

        return false;
    }

    public function terminate(): void
    {
        if (!$this->closed) {
            $this->release();
        }
    }

    public function result(): array
    {
        if ($this->timedOut) {
            return $this->failed("the side question took longer than {$this->timeout} seconds");
        }
        $reply = Json::object($this->output);
        if ($this->exitCode === 0 && ($reply['ok'] ?? null) === true && is_string($reply['answer'] ?? null)) {
            return ['question' => $this->question, 'ok' => true, 'answer' => $reply['answer'], 'error' => null];
        }
        $message = $reply['error'] ?? null;
        if (is_string($message) && $message !== '') {
            return $this->failed($message);
        }

        return $this->failed($this->stopped());
    }

    private function sendRequest(): void
    {
        if ($this->pending !== '') {
            $written = @fwrite($this->stdin, $this->pending);
            $this->pending = $written === false ? '' : (string) substr($this->pending, $written);
        }
        if ($this->pending === '') {
            fclose($this->stdin);
            $this->stdin = null;
        }
    }

    private function collect(): void
    {
        $output = self::read($this->stdout);
        if (strlen($this->output) < self::OUTPUT_LIMIT) {
            $this->output = substr($this->output . $output, 0, self::OUTPUT_LIMIT);
        }
        $this->errors = substr($this->errors . self::read($this->stderr), -self::ERROR_TAIL);
    }

    private static function read($stream): string
    {
        $data = '';
        while (is_resource($stream) && ($chunk = fread($stream, self::READ_CHUNK)) !== false && $chunk !== '') {
            $data .= $chunk;
        }

        return $data;
    }

    private function release(): void
    {
        foreach ([$this->stdin, $this->stdout, $this->stderr] as $pipe) {
            if (is_resource($pipe)) {
                fclose($pipe);
            }
        }
        $this->stdin = null;
        $this->stdout = null;
        $this->stderr = null;
        if (proc_get_status($this->process)['running']) {
            proc_terminate($this->process, 9);
        }
        proc_close($this->process);
        $this->closed = true;
    }

    private function failed(string $error): array
    {
        return ['question' => $this->question, 'ok' => false, 'answer' => null, 'error' => $error];
    }

    private function stopped(): string
    {
        $reason = $this->exitCode === 0
            ? 'the side question process printed no answer'
            : "the side question process ended with status {$this->exitCode}";
        $tail = trim($this->errors);

        return $tail === '' ? $reason : $reason . ': ' . Text::oneLine($tail, 200);
    }
}
