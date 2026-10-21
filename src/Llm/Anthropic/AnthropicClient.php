<?php

declare(strict_types=1);

namespace Phycode\Llm\Anthropic;

use Phycode\Config\Profile;
use Phycode\Llm\Canonical\FinishReason;
use Phycode\Llm\Canonical\ModelInfo;
use Phycode\Llm\Canonical\Usage;
use Phycode\Llm\CapabilityCache;
use Phycode\Llm\ChatClient;
use Phycode\Llm\ChatOptions;
use Phycode\Llm\EffortMapping;
use Phycode\Llm\Http;
use Phycode\Llm\LlmException;
use Phycode\Llm\LlmResponse;
use Phycode\Llm\PromptCache;
use Phycode\Llm\Retry;
use Phycode\Llm\SseParser;
use Phycode\Llm\StreamTransfer;
use Phycode\Support\Cancellation;
use Phycode\Support\Json;

final class AnthropicClient implements ChatClient
{
    private const RETRY_STATUSES = [429, 502, 503, 504, 529];
    private const MAX_PAUSES = 3;
    private const MAX_RAW_BYTES = 65536;
    private const TIMEOUT = 10;
    private const MODEL_PAGE_SIZE = 1000;
    private const MODEL_PAGES = 5;

    private readonly Retry $retry;

    private ?array $reported = null;
    private bool $reportedRead = false;

    private string $cacheMode;

    public function __construct(private readonly Profile $profile, array $retryDelays = Retry::DELAYS, private readonly ?CapabilityCache $cache = null)
    {
        $this->retry = new Retry($retryDelays, self::RETRY_STATUSES);
        $this->cacheMode = $profile->promptCache ? 'full' : 'off';
    }

    public function profile(): Profile
    {
        return $this->profile;
    }

    public function chat(array $messages, array $tools, ChatOptions $options): LlmResponse
    {
        $url = $this->profile->url('/v1/messages');
        $system = AnthropicMessages::system($messages);
        $conversation = AnthropicMessages::convert($messages, $tools !== [], $this->profile->supportsVision());

        $replies = [];
        while (true) {
            $body = Json::encode($this->requestBody($system, $conversation, $tools, $options));
            try {
                $reply = $this->exchange($url, $body, $options);
            } catch (LlmException $error) {
                if ($this->cacheMode !== 'off' && str_starts_with($error->getMessage(), 'HTTP 400') && stripos($error->getMessage(), 'cache_control') !== false) {
                    $this->cacheMode = $this->cacheMode === 'full' ? 'messages' : 'off';
                    $options->emit('notice', 'the server refused cache_control; prompt caching is ' . ($this->cacheMode === 'off' ? 'off' : 'kept on the last message only') . ' for this session');
                    continue;
                }

                throw $error;
            }
            $replies[] = $reply;
            if ($reply->stopReason() !== 'pause_turn') {
                return $this->response($replies);
            }
            if (count($replies) > self::MAX_PAUSES) {
                throw new LlmException('the model paused the turn more than ' . self::MAX_PAUSES . ' times');
            }
            $conversation[] = ['role' => 'assistant', 'content' => $reply->blocks()];
        }
    }

    public function listModels(): array
    {
        $models = [];
        $after = null;
        for ($page = 1; $page <= self::MODEL_PAGES; $page++) {
            $query = ['limit' => self::MODEL_PAGE_SIZE] + ($after === null ? [] : ['after_id' => $after]);
            $url = $this->profile->url('/v1/models') . '?' . http_build_query($query);
            $reply = Http::get($url, self::TIMEOUT, $this->headers('Accept: application/json'), $this->profile->unixSocket());
            if ($reply['status'] >= 400) {
                throw Http::error($reply['status'], $reply['body']);
            }
            $listing = Json::object($reply['body']);
            $data = $listing['data'] ?? null;
            if (!is_array($data)) {
                throw new LlmException("the server at {$url} did not return a model list");
            }
            foreach ($data as $entry) {
                if (is_array($entry) && is_string($entry['id'] ?? null) && $entry['id'] !== '') {
                    $models[] = $this->modelInfo($entry);
                }
            }
            if (($listing['has_more'] ?? false) !== true || !is_string($listing['last_id'] ?? null) || $listing['last_id'] === '') {
                break;
            }
            $after = $listing['last_id'];
        }

        return $models;
    }

    public function describe(string $model): ModelInfo
    {
        foreach ($this->listModels() as $info) {
            if ($info->id === $model) {
                return $info;
            }
        }

        return new ModelInfo(id: $model, profile: $this->profile->name, protocol: $this->profile->protocol);
    }

    private function requestBody(?string $system, array $messages, array $tools, ChatOptions $options): array
    {
        $body = [
            'model' => $this->profile->model,
            'max_tokens' => $this->profile->maxOutputTokens,
            'messages' => $messages,
            'stream' => true,
        ];
        if ($system !== null) {
            $body['system'] = $system;
        }
        if ($tools !== []) {
            $body['tools'] = AnthropicMessages::tools($tools);
        }

        $mapped = EffortMapping::anthropic($this->profile->thinking, $this->profile->model, $this->profile->maxOutputTokens, $this->reportedCapabilities($options));
        $body = array_merge($body, $mapped);
        if ($mapped['thinking']['type'] === 'disabled') {
            if (EffortMapping::level($this->profile->thinking) !== 'none') {
                $options->emit('progress', 'thinking off: max_tokens is too small for a thinking budget');
            }
            $sampling = array_merge($this->profile->sampling, $options->overrides);
            $body = array_merge($body, array_intersect_key($sampling, ['temperature' => true, 'top_p' => true, 'top_k' => true]));
        }

        if ($this->cacheMode !== 'off') {
            $body = PromptCache::markAnthropic($body, $this->profile->promptCacheTtl, $this->cacheMode);
        }

        return array_merge($body, $this->profile->extraBody);
    }

    private function reportedCapabilities(ChatOptions $options): ?array
    {
        if ($this->reportedRead) {
            return $this->reported;
        }
        $key = CapabilityCache::key($this->profile);
        $reported = $this->cache?->capabilities($key);
        if ($reported === null) {
            $reported = $this->fetchCapabilities($options);
            if ($reported !== null) {
                $this->cache?->merge($key, ['capabilities' => $reported]);
            }
        }
        $this->reportedRead = true;

        return $this->reported = $reported;
    }

    private function fetchCapabilities(ChatOptions $options): ?array
    {
        $url = $this->profile->url('/v1/models/' . rawurlencode($this->profile->model));
        $reply = StreamTransfer::get($url, $this->headers('Accept: application/json'), $this->profile->unixSocket(), $options->cancel, self::TIMEOUT);
        $this->failIfCancelled($options->cancel);
        if (!$reply['ok'] || !self::succeeded($reply['status'])) {
            return null;
        }
        $model = Json::object($reply['body']);

        return self::reportedOf($model['capabilities'] ?? null);
    }

    private static function reportedOf(mixed $capabilities): ?array
    {
        $effort = is_array($capabilities) && is_array($capabilities['effort'] ?? null) ? $capabilities['effort'] : null;
        $thinking = is_array($capabilities) && is_array($capabilities['thinking'] ?? null) ? $capabilities['thinking'] : null;
        if ($effort === null || $thinking === null) {
            return null;
        }
        $types = is_array($thinking['types'] ?? null) ? $thinking['types'] : [];

        return [
            'effort' => array_values(array_filter(['low', 'medium', 'high', 'xhigh', 'max'], static fn (string $level): bool => self::supported($effort[$level] ?? null))),
            'thinking' => array_values(array_filter(['adaptive', 'between_tools', 'disabled', 'enabled'], static fn (string $type): bool => self::supported($types[$type] ?? null))),
        ];
    }

    private static function supported(mixed $capability): bool
    {
        return is_array($capability) && ($capability['supported'] ?? false) === true;
    }

    private function headers(string ...$lines): array
    {
        $headers = [...$lines, 'anthropic-version: ' . $this->profile->anthropicVersion()];
        if ($this->profile->anthropicBeta() !== []) {
            $headers[] = 'anthropic-beta: ' . implode(',', $this->profile->anthropicBeta());
        }
        $key = $this->profile->apiKey();
        if ($key !== null) {
            $headers[] = 'x-api-key: ' . $key;
        }

        return $headers;
    }

    private function exchange(string $url, string $body, ChatOptions $options): AnthropicReply
    {
        $headers = $this->headers('Content-Type: application/json', 'Accept: text/event-stream');
        $cancel = $options->cancel;

        $retries = 0;
        while (true) {
            $result = $this->send($url, $headers, $body, $options);
            $this->failIfCancelled($cancel);
            if (!$this->retry->again($result, $retries)) {
                return $this->checked($result);
            }
            $this->retry->wait($retries++, $cancel);
            $this->failIfCancelled($cancel);
        }
    }

    private function send(string $url, array $headers, string $body, ChatOptions $options): array
    {
        $parser = new SseParser();
        $reply = new AnthropicReply();
        $raw = '';

        $transfer = StreamTransfer::post(
            $url,
            $headers,
            $body,
            $this->profile->unixSocket(),
            $options->cancel,
            static function (string $chunk, int $status) use ($parser, $reply, $options, &$raw): bool {
                if (!self::succeeded($status)) {
                    if (strlen($raw) < self::MAX_RAW_BYTES) {
                        $raw .= $chunk;
                    }

                    return true;
                }
                foreach ($parser->feed($chunk) as $payload) {
                    if (!$reply->consume($payload, $options)) {
                        return false;
                    }
                }

                return true;
            },
        );
        if ($transfer['ok'] && $reply->error() === null && self::succeeded($transfer['status'])) {
            foreach ($parser->flush() as $payload) {
                if (!$reply->consume($payload, $options)) {
                    break;
                }
            }
        }

        return [
            'status' => $transfer['status'],
            'ok' => $transfer['ok'],
            'transport' => $transfer['transport'],
            'streamed' => $transfer['bytes'] > 0 && self::succeeded($transfer['status']),
            'raw' => $raw,
            'error' => $reply->error(),
            'reply' => $reply,
        ];
    }

    private function checked(array $result): AnthropicReply
    {
        if ($result['error'] !== null) {
            throw new LlmException('server error: ' . $result['error']);
        }
        if (!$result['ok']) {
            throw new LlmException('connection to the model server failed: ' . $result['transport']);
        }
        if (!self::succeeded($result['status'])) {
            throw Http::error($result['status'], $result['raw']);
        }
        if (!$result['reply']->ended()) {
            throw new LlmException('server error: the reply ended before message_stop');
        }

        return $result['reply'];
    }

    private function response(array $replies): LlmResponse
    {
        $reason = $replies[count($replies) - 1]->stopReason();
        if ($reason === 'refusal') {
            $explanation = $replies[count($replies) - 1]->refusal();
            throw new LlmException('the model refused to answer' . ($explanation === null ? '' : ": {$explanation}"));
        }
        if ($reason === null) {
            throw new LlmException('the reply ended without a stop reason');
        }

        $calls = [];
        $content = '';
        $reasoning = '';
        $replay = [];
        foreach ($replies as $reply) {
            $calls = [...$calls, ...$reply->calls()];
            $content .= $reply->text();
            $reasoning .= $reply->thinking();
            $replay = [...$replay, ...$reply->replay()];
        }
        $finish = FinishReason::normalize($reason, $calls !== []);
        if ($finish === FinishReason::ERROR) {
            throw new LlmException("the reply ended with the stop reason {$reason}, which this client does not know");
        }

        return new LlmResponse(
            $content,
            $reasoning,
            $calls,
            $finish,
            self::usage($replies),
            $replay === [] ? [] : [AnthropicMessages::EXTRA => $replay],
        );
    }

    private static function usage(array $replies): Usage
    {
        $last = $replies[count($replies) - 1]->usage();
        $completion = null;
        foreach ($replies as $reply) {
            $count = $reply->usage()->completionTokens;
            if ($count !== null) {
                $completion = ($completion ?? 0) + $count;
            }
        }

        return new Usage(promptTokens: $last->promptTokens, completionTokens: $completion, cachedTokens: $last->cachedTokens, cacheWriteTokens: $last->cacheWriteTokens);
    }

    private function modelInfo(array $entry): ModelInfo
    {
        $meta = is_array($entry['meta'] ?? null) ? $entry['meta'] : [];
        $window = $entry['max_input_tokens'] ?? $meta['n_ctx'] ?? null;

        return new ModelInfo(
            id: $entry['id'],
            profile: $this->profile->name,
            protocol: $this->profile->protocol,
            contextWindow: is_int($window) && $window > 0 ? $window : null,
            thinkingLevels: self::thinkingLevels($entry['capabilities'] ?? null),
            source: 'v1/models',
            effortLevels: self::reportedOf($entry['capabilities'] ?? null)['effort'] ?? [],
        );
    }

    private static function thinkingLevels(mixed $capabilities): array
    {
        $thinking = is_array($capabilities) && is_array($capabilities['thinking'] ?? null) ? $capabilities['thinking'] : [];
        $types = is_array($thinking['types'] ?? null) ? $thinking['types'] : [];
        $supported = ($thinking['supported'] ?? false) === true
            || array_filter($types, static fn (mixed $type): bool => is_array($type) && ($type['supported'] ?? false) === true) !== [];

        return $supported ? ['off', 'on'] : [];
    }

    private function failIfCancelled(?Cancellation $cancel): void
    {
        if ($cancel !== null && $cancel->isCancelled()) {
            throw new LlmException('cancelled: ' . $cancel->reason());
        }
    }

    private static function succeeded(int $status): bool
    {
        return $status >= 200 && $status < 300;
    }
}
