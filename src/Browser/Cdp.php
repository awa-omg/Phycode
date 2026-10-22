<?php

declare(strict_types=1);

namespace Phycode\Browser;

use Phycode\Support\Cancellation;
use Phycode\Support\Json;
use Phycode\Support\PhycodeException;

final class Cdp
{
    private const SLICE_SECONDS = 0.25;

    public ?Cancellation $cancellation = null;

    private int $nextId = 1;
    private array $replies = [];
    private ?\Closure $listener = null;

    public function __construct(private readonly WebSocket $socket)
    {
    }

    public function listen(\Closure $listener): void
    {
        $this->listener = $listener;
    }

    public function isOpen(): bool
    {
        return $this->socket->isOpen();
    }

    public function send(string $method, array $params = [], ?string $sessionId = null, ?\Closure $reply = null): int
    {
        $id = $this->nextId++;
        $command = ['id' => $id, 'method' => $method];
        if ($params !== []) {
            $command['params'] = $params;
        }
        if ($sessionId !== null) {
            $command['sessionId'] = $sessionId;
        }
        if ($reply !== null) {
            $this->replies[$id] = $reply;
        }
        $this->socket->send(Json::encode($command));

        return $id;
    }

    public function call(string $method, array $params = [], ?string $sessionId = null, float $timeoutSeconds = 20.0): array
    {
        $answer = null;
        $this->send($method, $params, $sessionId, static function (array $result, ?string $error) use (&$answer): void {
            $answer = ['result' => $result, 'error' => $error];
        });
        if (!$this->waitFor(static function () use (&$answer): bool {
            return $answer !== null;
        }, $timeoutSeconds)) {
            throw new PhycodeException("the browser did not answer {$method} within {$timeoutSeconds}s");
        }
        if ($answer['error'] !== null) {
            throw new PhycodeException("the browser refused {$method}: {$answer['error']}");
        }

        return $answer['result'];
    }

    public function waitFor(\Closure $done, float $timeoutSeconds): bool
    {
        $deadline = microtime(true) + $timeoutSeconds;
        while (!$done()) {
            if ($this->cancellation?->isCancelled()) {
                throw new PhycodeException('cancelled');
            }
            $remaining = $deadline - microtime(true);
            if ($remaining <= 0) {
                return false;
            }
            $text = $this->socket->receive(min($remaining, self::SLICE_SECONDS));
            if ($text !== null) {
                $this->dispatch($text);
            }
        }

        return true;
    }

    public function pump(float $seconds): void
    {
        $this->waitFor(static fn (): bool => false, $seconds);
    }

    public function close(): void
    {
        $this->socket->close();
    }

    private function dispatch(string $text): void
    {
        $message = Json::object($text);
        if ($message === null) {
            throw new PhycodeException('the browser sent something that is not a JSON object');
        }
        if (isset($message['id']) && is_int($message['id'])) {
            $reply = $this->replies[$message['id']] ?? null;
            unset($this->replies[$message['id']]);
            if ($reply !== null) {
                $error = isset($message['error']) ? (is_array($message['error']) ? (string) ($message['error']['message'] ?? 'error') : 'error') : null;
                $reply(is_array($message['result'] ?? null) ? $message['result'] : [], $error);
            }

            return;
        }
        if (is_string($message['method'] ?? null) && $this->listener !== null) {
            ($this->listener)($message['method'], is_array($message['params'] ?? null) ? $message['params'] : [], is_string($message['sessionId'] ?? null) ? $message['sessionId'] : null);
        }
    }
}
