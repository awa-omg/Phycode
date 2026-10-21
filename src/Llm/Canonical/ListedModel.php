<?php

declare(strict_types=1);

namespace Phycode\Llm\Canonical;

use Phycode\Config\Profile;

final class ListedModel
{
    public static function from(array $entry, string $profile, string $protocol, array $effortLevels = []): ModelInfo
    {
        $meta = is_array($entry['meta'] ?? null) ? $entry['meta'] : [];
        $provider = is_array($entry['top_provider'] ?? null) ? $entry['top_provider'] : [];

        return new ModelInfo(
            id: (string) $entry['id'],
            profile: $profile,
            protocol: $protocol,
            contextWindow: self::window([
                $meta['n_ctx'] ?? null, $entry['context_length'] ?? null, $entry['context_window'] ?? null, $entry['max_model_len'] ?? null,
                $entry['max_context_length'] ?? null, $entry['max_input_tokens'] ?? null, $provider['context_length'] ?? null,
            ]),
            tools: self::tools($entry),
            source: 'v1/models',
            kind: self::kind($entry),
            effortLevels: $effortLevels,
        );
    }

    public static function efforts(array $entry): ?array
    {
        $reasoning = is_array($entry['reasoning'] ?? null) ? $entry['reasoning'] : null;
        $named = $reasoning['supported_efforts'] ?? null;
        if ($reasoning !== null && array_key_exists('supported_efforts', $reasoning) && ($named === null || is_array($named))) {
            $mandatory = ($reasoning['mandatory'] ?? false) === true;

            return self::effortsOf($named === null ? Profile::EFFORTS : self::levelsNamed($named), $mandatory);
        }
        $capabilities = is_array($entry['capabilities'] ?? null) ? $entry['capabilities'] : [];
        if (is_array($capabilities['reasoning_effort'] ?? null)) {
            return self::effortsOf(self::levelsNamed($capabilities['reasoning_effort']), false);
        }
        $effort = is_array($entry['effort'] ?? null) ? $entry['effort'] : [];
        if (is_array($effort['supported_levels'] ?? null)) {
            return self::effortsOf([...self::levelsNamed($effort['supported_levels']), 'none'], false);
        }

        return null;
    }

    private static function effortsOf(array $levels, bool $mandatory): ?array
    {
        $levels = array_values(array_filter(Profile::EFFORTS, static fn (string $level): bool => in_array($level, $levels, true)));
        if ($mandatory) {
            $levels = array_values(array_diff($levels, ['none']));
        }

        return $levels === [] ? null : ['effort' => $levels, 'mandatory' => $mandatory];
    }

    private static function levelsNamed(array $names): array
    {
        return array_values(array_filter(Profile::EFFORTS, static fn (string $level): bool => in_array($level, $names, true)));
    }

    public static function kindOfId(string $id): ?string
    {
        return match (true) {
            preg_match('/embed/i', $id) === 1 => 'embedding',
            preg_match('/^(whisper|distil-whisper|tts-|playai-tts|orpheus|gpt-audio|gpt-realtime|gpt-4o(-mini)?-(transcribe|tts|audio|realtime))/i', $id) === 1 => 'audio',
            preg_match('/^(dall-e|gpt-image|sora|imagen|flux)/i', $id) === 1 => 'image',
            preg_match('/moderation/i', $id) === 1 => 'moderation',
            default => null,
        };
    }

    private static function window(array $candidates): ?int
    {
        foreach ($candidates as $value) {
            if (is_int($value) && $value > 0) {
                return $value;
            }
        }

        return null;
    }

    private static function tools(array $entry): ?bool
    {
        if (is_array($entry['supported_parameters'] ?? null)) {
            return in_array('tools', $entry['supported_parameters'], true);
        }
        $capabilities = is_array($entry['capabilities'] ?? null) ? $entry['capabilities'] : [];
        foreach ([$capabilities['function_calling'] ?? null, $entry['supports_tools'] ?? null, $entry['supports_function_calling'] ?? null] as $value) {
            if (is_bool($value)) {
                return $value;
            }
        }

        return null;
    }

    private static function kind(array $entry): ?string
    {
        $architecture = is_array($entry['architecture'] ?? null) ? $entry['architecture'] : [];
        $outputs = $architecture['output_modalities'] ?? null;
        if (is_array($outputs) && $outputs !== [] && !in_array('text', $outputs, true)) {
            return in_array('image', $outputs, true) ? 'image' : (in_array('audio', $outputs, true) ? 'audio' : 'embedding');
        }

        return self::kindOfId((string) $entry['id']);
    }
}
