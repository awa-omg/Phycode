<?php

declare(strict_types=1);

namespace Phycode\Btw;

use Phycode\Cli\Kernel;
use Phycode\Llm\ChatOptions;
use Phycode\Llm\ClientProvider;
use Phycode\Support\PhycodeException;
use Phycode\Support\Redaction;

final class BtwWorker
{
    private const SYSTEM = 'Answer the side question about the conversation that follows. Be brief and direct. '
        . 'You have no tools: answer from the conversation and what you know. Your answer does not change the conversation.';

    public static function run(array $request, ?ClientProvider $providers = null): array
    {
        $secrets = [];
        try {
            $question = self::text($request, 'question');
            $kernel = new Kernel(self::text($request, 'cwd'), false, new SilentUi(), null, $providers);
            $secrets = $kernel->secrets();
            $profile = $kernel->config->profile(is_string($request['profile'] ?? null) ? $request['profile'] : null);
            $client = $kernel->providers->forProfile($profile->name, self::optional($request, 'model'), self::optional($request, 'thinking'));
            $reply = $client->chat(self::messages($request['messages'] ?? null, $question), [], new ChatOptions());
            $answer = trim($reply->content);
            if ($answer === '') {
                throw new PhycodeException('the model gave no answer');
            }

            return ['ok' => true, 'answer' => $answer];
        } catch (\Throwable $error) {
            return ['ok' => false, 'error' => Redaction::text($error->getMessage(), $secrets)];
        }
    }

    private static function messages(mixed $history, string $question): array
    {
        $messages = [['role' => 'system', 'content' => self::SYSTEM]];
        foreach (is_array($history) ? $history : [] as $message) {
            $role = is_array($message) ? ($message['role'] ?? null) : null;
            $content = is_array($message) ? ($message['content'] ?? null) : null;
            if (in_array($role, ['user', 'assistant'], true) && is_string($content) && trim($content) !== '') {
                $messages[] = ['role' => $role, 'content' => $content];
            }
        }
        $messages[] = ['role' => 'user', 'content' => $question];

        return $messages;
    }

    private static function text(array $request, string $key): string
    {
        $value = $request[$key] ?? null;
        if (!is_string($value) || trim($value) === '') {
            throw new PhycodeException("the side question request has no {$key}");
        }

        return trim($value);
    }

    private static function optional(array $request, string $key): ?string
    {
        $value = $request[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }
}
