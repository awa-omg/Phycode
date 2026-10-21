<?php

declare(strict_types=1);

namespace Phycode\Tests\Unit\Review;

use Phycode\Llm\Canonical\ModelInfo;
use Phycode\Llm\ChatClient;
use Phycode\Llm\ChatOptions;
use Phycode\Llm\LlmException;
use Phycode\Llm\LlmResponse;
use Phycode\Tests\Support\ScriptedClient;

final class ReviewModel implements ChatClient
{
    public array $requests = [];

    public function __construct(private array $scripts)
    {
    }

    public static function reads(string $reply, string $path = 'src/a.php'): array
    {
        return [ScriptedClient::call('read', ['path' => $path]), ScriptedClient::text($reply)];
    }

    public static function findings(array ...$findings): string
    {
        return json_encode(['findings' => array_values($findings)], JSON_THROW_ON_ERROR);
    }

    public static function claim(string $severity, string $file, int $line, string $title): array
    {
        return ['severity' => $severity, 'file' => $file, 'line' => $line, 'title' => $title, 'detail' => "{$title}, as the diff shows", 'evidence' => "{$file}:{$line} echoes the query string"];
    }

    public static function verdicts(array $outcomes): string
    {
        $results = [];
        foreach ($outcomes as $id => $outcome) {
            $results[] = ['id' => $id, 'outcome' => $outcome, 'evidence' => "the code at the claimed place: {$outcome}"];
        }

        return json_encode(['results' => $results], JSON_THROW_ON_ERROR);
    }

    public static function cleanLenses(): array
    {
        $clean = self::findings();

        return [
            'reviewer-risk' => self::reads($clean),
            'reviewer-resilience' => self::reads($clean),
            'reviewer-readability' => self::reads($clean),
            'reviewer-reliability' => self::reads($clean),
        ];
    }

    public function agents(): array
    {
        return array_column($this->requests, 'agent');
    }

    public function systemOf(string $agent): string
    {
        return (string) $this->first($agent)['messages'][0]['content'];
    }

    public function taskOf(string $agent): string
    {
        return (string) $this->first($agent)['messages'][1]['content'];
    }

    public function chat(array $messages, array $tools, ChatOptions $options): LlmResponse
    {
        $system = (string) ($messages[0]['content'] ?? '');
        $agent = preg_match('/subagent "([^"]+)"/', $system, $match) === 1 ? $match[1] : '?';
        $this->requests[] = ['agent' => $agent, 'messages' => $messages];
        if (($this->scripts[$agent] ?? []) === []) {
            throw new LlmException("no reply is scripted for {$agent}");
        }
        $next = array_shift($this->scripts[$agent]);
        if ($next instanceof \Throwable) {
            throw $next;
        }

        return $next;
    }

    public function listModels(): array
    {
        return [new ModelInfo('review', 'test', 'openai')];
    }

    public function describe(string $model): ModelInfo
    {
        return new ModelInfo($model, 'test', 'openai');
    }

    private function first(string $agent): array
    {
        foreach ($this->requests as $request) {
            if ($request['agent'] === $agent) {
                return $request;
            }
        }

        throw new \LogicException("{$agent} was never asked");
    }
}
