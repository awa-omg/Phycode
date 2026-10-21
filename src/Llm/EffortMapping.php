<?php

declare(strict_types=1);

namespace Phycode\Llm;

use Phycode\Config\Profile;

final class EffortMapping
{
    private const CLAUDE_ALL = ['low', 'medium', 'high', 'xhigh', 'max'];
    private const ANTHROPIC_DEFAULT = ['effort' => self::CLAUDE_ALL, 'thinking' => ['adaptive', 'disabled']];
    private const MIN_BUDGET = 1024;
    private const OPENAI_HOST = 'api.openai.com';
    private const EFFORT_HOSTS = ['api.groq.com', 'generativelanguage.googleapis.com', 'api.mistral.ai', 'api.x.ai'];
    private const OPENROUTER_HOST = 'openrouter.ai';
    private const DEEPSEEK_HOST = 'api.deepseek.com';
    private const XAI_HOST = 'api.x.ai';

    private static array $hostAliases = [];

    public const WIRE = [
        'openai-responses' => ['none' => 'none', 'minimal' => 'minimal', 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => 'xhigh', 'max' => 'max'],
        'openai' => ['none' => 'none', 'minimal' => 'minimal', 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => 'xhigh', 'max' => 'max'],
        'anthropic' => ['minimal' => 'low', 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => 'xhigh', 'max' => 'max'],
        'llama' => ['none' => false, 'minimal' => true, 'low' => true, 'medium' => true, 'high' => true, 'xhigh' => true, 'max' => true],
        'deepseek' => ['minimal' => 'low', 'low' => 'low', 'medium' => 'high', 'high' => 'high', 'xhigh' => 'high', 'max' => 'max'],
    ];

    public const OPENAI_MODELS = [
        'gpt-6-astra' => ['levels' => ['low', 'medium', 'high', 'xhigh', 'max'], 'chat' => 'no-tools', 'gpt6Sampling' => true],
        'gpt-6.1-sol' => ['levels' => ['low', 'medium', 'high', 'xhigh', 'max'], 'chat' => 'no-tools', 'gpt6Sampling' => true],
        'gpt-6-sol' => ['levels' => ['none', 'low', 'medium', 'high', 'xhigh', 'max'], 'chat' => 'tools-only-with-none', 'gpt6Sampling' => true],
        'gpt-6-luna' => ['levels' => ['none', 'low', 'medium', 'high', 'xhigh', 'max'], 'chat' => 'tools-only-with-none', 'gpt6Sampling' => true],
        'gpt-5.6-sol' => ['levels' => ['none', 'low', 'medium', 'high', 'xhigh', 'max'], 'chat' => 'yes', 'gpt6Sampling' => false],
        'gpt-5.6-terra' => ['levels' => ['none', 'low', 'medium', 'high', 'xhigh', 'max'], 'chat' => 'yes', 'gpt6Sampling' => false],
        'gpt-5.6-luna' => ['levels' => ['none', 'low', 'medium', 'high', 'xhigh', 'max'], 'chat' => 'yes', 'gpt6Sampling' => false],
        'gpt-5.5' => ['levels' => ['none', 'low', 'medium', 'high', 'xhigh'], 'chat' => 'yes', 'gpt6Sampling' => false],
        'gpt-5.5-pro' => ['levels' => ['medium', 'high', 'xhigh'], 'chat' => 'no', 'gpt6Sampling' => false],
        'gpt-5.4-pro' => ['levels' => ['medium', 'high', 'xhigh'], 'chat' => 'no', 'gpt6Sampling' => false],
        'gpt-5.2-pro' => ['levels' => ['medium', 'high', 'xhigh'], 'chat' => 'no', 'gpt6Sampling' => false],
        'gpt-5.3-codex' => ['levels' => ['low', 'medium', 'high', 'xhigh'], 'chat' => 'no', 'gpt6Sampling' => false],
        'gpt-5.4' => ['levels' => ['none', 'low', 'medium', 'high', 'xhigh'], 'chat' => 'yes', 'gpt6Sampling' => false],
        'gpt-5.4-mini' => ['levels' => ['none', 'low', 'medium', 'high', 'xhigh'], 'chat' => 'yes', 'gpt6Sampling' => false],
        'gpt-5.4-nano' => ['levels' => ['none', 'low', 'medium', 'high', 'xhigh'], 'chat' => 'yes', 'gpt6Sampling' => false],
        'gpt-5.2' => ['levels' => ['none', 'low', 'medium', 'high', 'xhigh'], 'chat' => 'yes', 'gpt6Sampling' => false],
        'gpt-5.1' => ['levels' => ['none', 'low', 'medium', 'high'], 'chat' => 'yes', 'gpt6Sampling' => false],
        'gpt-5' => ['levels' => ['minimal', 'low', 'medium', 'high'], 'chat' => 'yes', 'gpt6Sampling' => false],
        'gpt-5-pro' => ['levels' => ['high'], 'chat' => 'no', 'gpt6Sampling' => false],
        'gpt-oss-120b' => ['levels' => ['low', 'medium', 'high'], 'chat' => 'no', 'gpt6Sampling' => false],
        'gpt-oss-20b' => ['levels' => ['low', 'medium', 'high'], 'chat' => 'no', 'gpt6Sampling' => false],
    ];

    public const HOST_MODELS = [
        'api.groq.com' => [
            'openai/gpt-oss-20b' => ['levels' => ['low', 'medium', 'high']],
            'openai/gpt-oss-120b' => ['levels' => ['low', 'medium', 'high']],
            'qwen/qwen3.8-27b' => ['levels' => ['none', 'low', 'medium', 'high']],
            'meta-models/muse-spark-open' => ['levels' => ['low', 'medium', 'high', 'xhigh']],
        ],
        'generativelanguage.googleapis.com' => [
            'gemini-2.5-pro' => ['levels' => ['low', 'medium', 'high']],
            'gemini-2.5-flash' => ['levels' => ['none', 'low', 'medium', 'high']],
            'gemini-2.5-flash-lite' => ['levels' => ['none', 'low', 'medium', 'high']],
        ],
        'api.mistral.ai' => [
            'mistral-small-latest' => ['levels' => ['none', 'high']],
            'mistral-medium-3-5' => ['levels' => ['none', 'high']],
            'mistral-large-4-0' => ['levels' => ['none', 'high']],
            'zai-glm-5-3' => ['levels' => ['low', 'high', 'max']],
        ],
        'api.deepseek.com' => [
            'deepseek-flash' => ['levels' => ['none', 'low', 'high', 'max']],
            'deepseek-v4-pro' => ['levels' => ['none', 'low', 'high', 'max']],
        ],
    ];

    public const ANTHROPIC_MODELS = [
        'claude-haiku-5-5' => ['effort' => self::CLAUDE_ALL, 'thinking' => ['adaptive', 'disabled']],
        'claude-opus-5-5' => ['effort' => self::CLAUDE_ALL, 'thinking' => ['adaptive']],
        'claude-opus-5' => ['effort' => self::CLAUDE_ALL, 'thinking' => ['adaptive', 'disabled']],
        'claude-sonnet-5-5' => ['effort' => self::CLAUDE_ALL, 'thinking' => ['adaptive', 'between_tools']],
        'claude-sonnet-5' => ['effort' => self::CLAUDE_ALL, 'thinking' => ['adaptive', 'disabled']],
        'claude-fable-5-1' => ['effort' => self::CLAUDE_ALL, 'thinking' => ['adaptive']],
        'claude-mythos-5-1' => ['effort' => self::CLAUDE_ALL, 'thinking' => ['adaptive']],
        'claude-fable-5' => ['effort' => self::CLAUDE_ALL, 'thinking' => ['adaptive']],
        'claude-mythos-5' => ['effort' => self::CLAUDE_ALL, 'thinking' => ['adaptive']],
        'claude-mythos-preview' => ['effort' => ['low', 'medium', 'high', 'max'], 'thinking' => ['adaptive', 'enabled']],
        'claude-opus-4-8' => ['effort' => self::CLAUDE_ALL, 'thinking' => ['adaptive', 'disabled']],
        'claude-opus-4-7' => ['effort' => self::CLAUDE_ALL, 'thinking' => ['adaptive', 'disabled']],
        'claude-opus-4-6' => ['effort' => ['low', 'medium', 'high', 'max'], 'thinking' => ['adaptive', 'enabled', 'disabled']],
        'claude-sonnet-4-6' => ['effort' => ['low', 'medium', 'high', 'max'], 'thinking' => ['adaptive', 'enabled', 'disabled']],
        'claude-opus-4-5-20251101' => ['effort' => ['low', 'medium', 'high'], 'thinking' => ['enabled', 'disabled']],
    ];

    public const BUDGETS = ['low' => 1024, 'medium' => 4096, 'high' => 8192];

    public static function level(string $thinking): string
    {
        return Profile::canonical($thinking) ?? throw new LlmException("unknown effort level '{$thinking}'");
    }

    public static function setOpenAiHost(?string $host): void
    {
        self::setTestHost(self::OPENAI_HOST, $host);
    }

    public static function setTestHost(string $known, ?string $host): void
    {
        if ($host === null) {
            unset(self::$hostAliases[$known]);
        } else {
            self::$hostAliases[$known] = $host;
        }
    }

    public static function isOpenAi(Profile $profile): bool
    {
        return self::hostOf($profile) === self::OPENAI_HOST;
    }

    public static function hostOf(Profile $profile): string
    {
        $host = strtolower((string) parse_url($profile->baseUrl, PHP_URL_HOST));

        return (string) (array_search($host, self::$hostAliases, true) ?: $host);
    }

    public static function format(Profile $profile): ?string
    {
        if ($profile->protocol !== 'openai') {
            return null;
        }
        if ($profile->effortFormat !== 'auto') {
            return $profile->effortFormat;
        }
        $host = self::hostOf($profile);

        return match (true) {
            $host === self::OPENAI_HOST => 'openai',
            $host === self::OPENROUTER_HOST || str_ends_with($host, '.' . self::OPENROUTER_HOST) => 'openrouter',
            $host === self::DEEPSEEK_HOST => 'deepseek',
            in_array($host, self::EFFORT_HOSTS, true) => 'openai',
            $profile->isLocal() => 'chat_template',
            default => 'none',
        };
    }

    public static function readsListing(Profile $profile): bool
    {
        return match (self::format($profile)) {
            'openrouter', 'deepseek' => true,
            'openai' => self::hostOf($profile) === self::XAI_HOST,
            default => false,
        };
    }

    public static function clamp(string $level, array $accepted): string
    {
        $levels = array_values(array_filter(Profile::EFFORTS, static fn (string $name): bool => in_array($name, $accepted, true)));
        if (in_array($level, $levels, true)) {
            return $level;
        }
        $named = array_values(array_filter($levels, static fn (string $name): bool => $name !== 'none'));
        if ($named === []) {
            return 'none';
        }
        $at = (int) array_search($level, Profile::EFFORTS, true);
        $below = array_filter($named, static fn (string $name): bool => (int) array_search($name, Profile::EFFORTS, true) < $at);

        return $below === [] ? $named[0] : end($below);
    }

    public static function accepted(Profile $profile, string $model, ?array $reported = null): ?array
    {
        if ($profile->protocol === 'anthropic') {
            $spec = self::ANTHROPIC_MODELS[$model] ?? null;

            return $spec === null ? null : self::anthropicAccepted($spec);
        }
        if ($profile->protocol !== 'openai') {
            $row = self::openAiRow($profile, $model);

            return $row === null ? null : $row['levels'];
        }
        if (!in_array(self::format($profile), ['openai', 'openrouter', 'deepseek'], true)) {
            return null;
        }
        $levels = $profile->effortLevels;
        if ($levels === null && $reported !== null) {
            $levels = $reported['mandatory'] ? array_values(array_diff($reported['effort'], ['none'])) : $reported['effort'];
        }
        $levels ??= self::tableLevels($profile, $model);

        return $levels === [] ? null : $levels;
    }

    private static function tableLevels(Profile $profile, string $model): ?array
    {
        if (self::isOpenAi($profile)) {
            $row = self::openAiRow($profile, $model);

            return $row === null ? null : $row['levels'];
        }

        return self::HOST_MODELS[self::hostOf($profile)][self::bareId($model)]['levels'] ?? null;
    }

    public static function chatFields(Profile $profile, ?array $reported = null): array
    {
        $format = self::format($profile);
        if ($format === 'chat_template') {
            return ['chat_template_kwargs' => ['enable_thinking' => self::WIRE['llama'][self::level($profile->thinking)]]];
        }
        $level = self::sent($profile, self::accepted($profile, $profile->model, $reported));
        if ($level === null) {
            return [];
        }

        return match ($format) {
            'openai' => ['reasoning_effort' => self::WIRE['openai'][$level]],
            'openrouter' => ['reasoning' => ['effort' => $level]],
            'deepseek' => $level === 'none'
                ? ['thinking' => ['type' => 'disabled']]
                : ['thinking' => ['type' => 'enabled'], 'reasoning_effort' => self::WIRE['deepseek'][$level]],
            default => [],
        };
    }

    private static function sent(Profile $profile, ?array $accepted): ?string
    {
        $level = self::level($profile->thinking);
        if ($accepted !== null) {
            return self::clamp($level, $accepted);
        }

        return $profile->effortFormat !== 'auto' || self::isOpenAi($profile) ? $level : null;
    }

    public static function responses(Profile $profile): string
    {
        return self::WIRE['openai-responses'][self::rowSent($profile)];
    }

    public static function viaResponses(Profile $profile, bool $tools): bool
    {
        $row = self::openAiRow($profile, $profile->model);
        if ($row === null) {
            return false;
        }

        return match ($row['chat']) {
            'no' => true,
            'no-tools' => $tools,
            'tools-only-with-none' => $tools && self::rowSent($profile) !== 'none',
            default => false,
        };
    }

    public static function withoutSampling(array $body, Profile $profile): array
    {
        $row = self::openAiRow($profile, $profile->model);
        if ($row === null || !$row['gpt6Sampling'] || self::rowSent($profile) === 'none') {
            return $body;
        }
        $body = array_diff_key($body, array_flip(['temperature', 'top_p', 'top_logprobs', 'logprobs']));
        if (is_array($body['include'] ?? null)) {
            $body['include'] = array_values(array_diff($body['include'], ['message.output_text.logprobs']));
        }

        return $body;
    }

    public static function withoutReasoningSampling(array $body, array $fields): array
    {
        $reasons = (isset($fields['reasoning_effort']) && $fields['reasoning_effort'] !== 'none')
            || (isset($fields['reasoning']['effort']) && $fields['reasoning']['effort'] !== 'none')
            || (($fields['thinking']['type'] ?? null) === 'enabled');

        return $reasons ? array_diff_key($body, array_flip(['presence_penalty', 'frequency_penalty', 'stop'])) : $body;
    }

    public static function anthropic(string $thinking, string $model, int $maxTokens, ?array $reported = null): array
    {
        $level = self::level($thinking);
        $spec = $reported ?? self::ANTHROPIC_MODELS[$model] ?? self::ANTHROPIC_DEFAULT;
        $types = $spec['thinking'];
        if ($level === 'none' && in_array('disabled', $types, true)) {
            return ['thinking' => ['type' => 'disabled']];
        }
        if ($level === 'none' && in_array('between_tools', $types, true)) {
            return ['thinking' => ['type' => 'between_tools']];
        }
        if (!in_array('adaptive', $types, true)) {
            $budgetLevel = self::clamp($level, array_keys(self::BUDGETS));
            $budget = min(self::BUDGETS[$budgetLevel], intdiv($maxTokens, 2));
            if ($budget < self::MIN_BUDGET) {
                return ['thinking' => ['type' => 'disabled']];
            }

            return ['thinking' => ['type' => 'enabled', 'budget_tokens' => $budget]] + self::effortField($budgetLevel, $spec['effort']);
        }
        $wanted = $level === 'none' ? 'none' : self::WIRE['anthropic'][$level];

        return ['thinking' => ['type' => 'adaptive']] + self::effortField($wanted, $spec['effort']);
    }

    public static function ollama(string $thinking, array $names): bool|string
    {
        $level = self::level($thinking);
        if ($level === 'none') {
            return false;
        }
        $named = array_values(array_filter($names, static fn (string $name): bool => in_array($name, Profile::EFFORTS, true)));
        if ($named === []) {
            return true;
        }

        return self::clamp($level, $named);
    }

    public static function note(Profile $profile, string $model, ?array $reported = null): string
    {
        if ($profile->protocol === 'anthropic') {
            $spec = self::ANTHROPIC_MODELS[$model] ?? null;
            if ($spec === null) {
                return 'the model is not in the table: each level is sent as it is, and none turns thinking off where the model allows it';
            }
            $types = $spec['thinking'];
            $tail = match (true) {
                in_array('disabled', $types, true) => 'none turns thinking off',
                in_array('between_tools', $types, true) => 'none turns thinking off with between_tools',
                default => 'thinking cannot be turned off',
            };

            return self::takes($model, self::anthropicAccepted($spec), $tail);
        }
        if ($profile->protocol === 'ollama') {
            return 'on ollama, a level the model does not name is sent as the closest name below it, or else the lowest one it names; a model that names no levels takes thinking on for every level but none';
        }
        if ($profile->protocol === 'openai-responses') {
            $row = self::openAiRow($profile, $model);

            return $row === null ? 'reasoning.effort: the level is sent as it is, and none is no reasoning' : self::takes($model, $row['levels']);
        }
        $format = self::format($profile);
        if ($format === 'chat_template') {
            return 'none turns thinking off; every other level turns it on (enable_thinking)';
        }
        if (!in_array($format, ['openai', 'openrouter', 'deepseek'], true)) {
            return 'this server gets no thinking field, so the level has no effect';
        }
        $field = match ($format) {
            'openai' => 'reasoning_effort',
            'openrouter' => 'reasoning.effort',
            default => 'thinking and reasoning_effort',
        };
        $accepted = self::accepted($profile, $model, $reported);
        if ($accepted === null) {
            if ($profile->effortFormat !== 'auto' || self::isOpenAi($profile)) {
                return "{$field}: the level is sent as it is, and " . ($format === 'deepseek' ? 'none turns thinking off' : 'none is no reasoning');
            }

            return "{$field}: the levels of {$model} are not known here, so no field is sent and the level has no effect";
        }
        $source = match (true) {
            $profile->effortLevels !== null => 'effort_levels',
            $reported !== null => 'the listing',
            default => 'the table',
        };
        $mandatory = $profile->effortLevels === null && ($reported['mandatory'] ?? false);
        $tail = match (true) {
            $format === 'openrouter' && $mandatory => 'reasoning cannot be turned off',
            $format === 'deepseek' && in_array('none', $accepted, true) => 'none turns thinking off',
            default => '',
        };
        $sentence = self::takes($model, $accepted, $tail);
        if (self::isOpenAi($profile) && $source === 'the table') {
            return $sentence;
        }

        return "{$field} ({$source}): {$sentence}";
    }

    private static function rowSent(Profile $profile): string
    {
        $level = self::level($profile->thinking);
        $row = self::openAiRow($profile, $profile->model);

        return $row === null ? $level : self::clamp($level, $row['levels']);
    }

    private static function openAiRow(Profile $profile, string $model): ?array
    {
        if (!self::isOpenAi($profile)) {
            return null;
        }

        return self::OPENAI_MODELS[self::bareId($model)] ?? null;
    }

    private static function bareId(string $model): string
    {
        return (string) preg_replace('/-\d{4}-\d{2}-\d{2}$/', '', $model);
    }

    private static function anthropicAccepted(array $spec): array
    {
        $types = $spec['thinking'];
        $levels = in_array('adaptive', $types, true) ? $spec['effort'] : array_keys(self::BUDGETS);
        $canOff = in_array('disabled', $types, true) || in_array('between_tools', $types, true);

        return [...($canOff ? ['none'] : []), ...$levels];
    }

    private static function effortField(string $level, array $efforts): array
    {
        return $efforts === [] ? [] : ['output_config' => ['effort' => self::clamp($level, $efforts)]];
    }

    private static function takes(string $model, array $accepted, string $tail = ''): string
    {
        $named = array_values(array_filter($accepted, static fn (string $level): bool => $level !== 'none'));
        $range = match (count($named)) {
            0 => '',
            1 => $named[0],
            default => $named[0] . ' to ' . $named[array_key_last($named)],
        };
        if (in_array('none', $accepted, true)) {
            $range = $range === '' ? 'none' : "none and {$range}";
        }
        $groups = [];
        foreach (Profile::EFFORTS as $level) {
            if (!in_array($level, $accepted, true)) {
                $groups[self::clamp($level, $accepted)][] = $level;
            }
        }
        $sent = [];
        foreach ($groups as $target => $levels) {
            $sent[] = self::listed($levels) . (count($levels) === 1 ? ' is' : ' are') . " sent as {$target}";
        }
        $sentence = "{$model} takes {$range}" . ($sent === [] ? '' : ': ' . implode('; ', $sent));

        return $tail === '' ? $sentence : "{$sentence}; {$tail}";
    }

    private static function listed(array $items): string
    {
        $last = (string) array_pop($items);

        return $items === [] ? $last : implode(', ', $items) . " and {$last}";
    }
}
