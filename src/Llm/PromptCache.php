<?php

declare(strict_types=1);

namespace Phycode\Llm;

use Phycode\Config\Profile;

final class PromptCache
{
    private const OPENAI_HOST = 'api.openai.com';
    private const XAI_HOST = 'api.x.ai';
    private const OPENROUTER_HOST = 'openrouter.ai';

    public static function key(string $transcript, string $agent): string
    {
        return 'phycode-' . substr(sha1($transcript . '|' . $agent), 0, 24);
    }

    public static function bodyFields(Profile $profile, ?string $key, bool $responses = false): array
    {
        if (!$profile->promptCache) {
            return [];
        }
        $host = EffortMapping::hostOf($profile);
        if ($responses && $host === self::XAI_HOST) {
            return $key === null ? [] : ['prompt_cache_key' => $key];
        }
        if ($host !== self::OPENAI_HOST) {
            return [];
        }

        return ($key === null ? [] : ['prompt_cache_key' => $key])
            + ($profile->promptCacheRetention === null ? [] : ['prompt_cache_retention' => $profile->promptCacheRetention]);
    }

    public static function headers(Profile $profile, ?string $key): array
    {
        if (!$profile->promptCache || $key === null) {
            return [];
        }
        $host = EffortMapping::hostOf($profile);

        return match (true) {
            $host === self::XAI_HOST => ['x-grok-conv-id: ' . $key],
            $host === self::OPENROUTER_HOST || str_ends_with($host, '.' . self::OPENROUTER_HOST) => ['x-session-id: ' . $key],
            default => [],
        };
    }

    public static function marksChat(Profile $profile): bool
    {
        $host = EffortMapping::hostOf($profile);

        return $profile->promptCache
            && ($host === self::OPENROUTER_HOST || str_ends_with($host, '.' . self::OPENROUTER_HOST))
            && str_starts_with(strtolower($profile->model), 'anthropic/');
    }

    public static function markChat(array $messages, string $ttl): array
    {
        $mark = self::mark($ttl);
        $system = null;
        $last = null;
        foreach ($messages as $at => $message) {
            $role = $message['role'] ?? null;
            if ($role === 'system' && $system === null && self::hasText($message['content'] ?? null)) {
                $system = $at;
            }
            if (($role === 'user' || $role === 'assistant') && self::hasText($message['content'] ?? null)) {
                $last = $at;
            }
        }
        foreach (array_unique(array_filter([$system, $last], static fn (?int $at): bool => $at !== null)) as $at) {
            $content = $messages[$at]['content'];
            $blocks = is_string($content) ? [['type' => 'text', 'text' => $content]] : $content;
            for ($i = count($blocks) - 1; $i >= 0; $i--) {
                if (($blocks[$i]['type'] ?? null) === 'text' && ($blocks[$i]['text'] ?? '') !== '') {
                    $blocks[$i]['cache_control'] = $mark;
                    break;
                }
            }
            $messages[$at]['content'] = $blocks;
        }

        return $messages;
    }

    public static function markAnthropic(array $body, string $ttl, string $mode = 'full'): array
    {
        $mark = self::mark($ttl);
        if ($mode === 'full') {
            if (is_array($body['tools'] ?? null) && $body['tools'] !== []) {
                $body['tools'][array_key_last($body['tools'])]['cache_control'] = $mark;
            }
            if (is_string($body['system'] ?? null) && $body['system'] !== '') {
                $body['system'] = [['type' => 'text', 'text' => $body['system'], 'cache_control' => $mark]];
            }
        }
        $messages = $body['messages'] ?? [];
        for ($last = is_array($messages) ? array_key_last($messages) : null; $last !== null && $last >= 0; $last--) {
            if (($messages[$last]['role'] ?? null) !== 'user') {
                continue;
            }
            $blocks = $messages[$last]['content'] ?? [];
            for ($i = count($blocks) - 1; $i >= 0; $i--) {
                $type = $blocks[$i]['type'] ?? null;
                if ($type === 'thinking' || $type === 'redacted_thinking' || ($type === 'text' && ($blocks[$i]['text'] ?? '') === '')) {
                    continue;
                }
                $messages[$last]['content'][$i]['cache_control'] = $mark;
                $body['messages'] = $messages;
                break 2;
            }
        }

        return $body;
    }

    private static function mark(string $ttl): array
    {
        return $ttl === '1h' ? ['type' => 'ephemeral', 'ttl' => '1h'] : ['type' => 'ephemeral'];
    }

    private static function hasText(mixed $content): bool
    {
        if (is_string($content)) {
            return $content !== '';
        }
        foreach (is_array($content) ? $content : [] as $block) {
            if (is_array($block) && ($block['type'] ?? null) === 'text' && ($block['text'] ?? '') !== '') {
                return true;
            }
        }

        return false;
    }
}
