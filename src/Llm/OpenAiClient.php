<?php

declare(strict_types=1);

namespace Phycode\Llm;

use Phycode\Config\Profile;
use Phycode\Llm\Canonical\FinishReason;
use Phycode\Llm\Canonical\ListedModel;
use Phycode\Llm\Canonical\ModelInfo;
use Phycode\Llm\Canonical\Usage;
use Phycode\Llm\Llama\LlamaDiscovery;
use Phycode\Support\Cancellation;
use Phycode\Support\Json;
use Phycode\Support\PhycodeException;

final class OpenAiClient implements ChatClient
{
    private const MAX_RAW_BYTES = 65536;
    private const MAX_WHOLE_BYTES = 4194304;
    public const EXTRA = 'openai-chat';
    private const WAIT_SECONDS = 0.1;
    private const RETRY_DELAYS = [0.5, 1.5];
    private const RETRY_STATUSES = [429, 502, 503, 504];

    private static array $loadingSteps = Retry::LOADING_STEPS;
    private static float $loadingLimit = Retry::LOADING_LIMIT;

    private const NEEDED = ['model', 'messages', 'stream', 'tools'];
    private const MAX_ADJUSTMENTS = 8;
    public const FUNCTION_TOOLS_REFUSAL = 'Function tools with reasoning_effort';

    private ?LlamaDiscovery $llama = null;
    private bool $llamaChecked = false;

    private array $dropped = [];
    private ?string $tokenParam = null;

    private bool $levelsAsked = false;

    public function __construct(private readonly Profile $profile, private readonly array $retryDelays = self::RETRY_DELAYS, private readonly ?CapabilityCache $cache = null)
    {
    }

    public static function setLoadingWait(?float $pause, ?float $limit): void
    {
        self::$loadingSteps = $pause === null ? Retry::LOADING_STEPS : [$pause];
        self::$loadingLimit = $limit ?? Retry::LOADING_LIMIT;
    }

    public function profile(): Profile
    {
        return $this->profile;
    }

    public function chat(array $messages, array $tools, ChatOptions $options): LlmResponse
    {
        $url = $this->profile->url('/v1/chat/completions');
        $fields = $this->requestBody($messages, $tools, $options);
        $headers = $this->headers('Content-Type: application/json', 'Accept: text/event-stream', ...PromptCache::headers($this->profile, $options->cacheKey));
        $cancel = $options->cancel;
        $pauses = Retry::loadingPauses(self::$loadingSteps, self::$loadingLimit);

        $retry = 0;
        $waits = 0;
        $waited = 0.0;
        $adjustments = [];
        while (true) {
            $result = $this->send($url, $headers, Json::encode($this->accepted($fields)), $options);
            $this->failIfCancelled($cancel);
            $adjustment = count($adjustments) < self::MAX_ADJUSTMENTS ? $this->refusedField($result, $fields) : null;
            if ($adjustment !== null) {
                $adjustments[] = $adjustment;
                continue;
            }
            if ($this->loading($result)) {
                if ($waits === count($pauses)) {
                    throw Retry::loadingTimeout(self::$loadingLimit);
                }
                $pause = $pauses[$waits++];
                $waited += $pause;
                $options->emit('progress', "llama-server is still loading the model: waited {$waited} of " . self::$loadingLimit . ' s');
                $this->pause($pause, $cancel);
                $this->failIfCancelled($cancel);
                continue;
            }
            if (!$this->retryable($result) || $retry === count($this->retryDelays)) {
                if ($adjustments !== []) {
                    $options->emit('progress', self::told($adjustments));
                }

                return $this->response($result);
            }
            $this->pause($this->retryDelays[$retry++], $cancel);
            $this->failIfCancelled($cancel);
        }
    }

    public function listModels(): array
    {
        $llama = $this->llama();
        if ($llama !== null) {
            return $llama->models();
        }

        $entries = $this->listing();
        $this->keepLevels($entries);

        return array_map(
            fn (array $entry): ModelInfo => ListedModel::from($entry, $this->profile->name, $this->profile->protocol, ListedModel::efforts($entry)['effort'] ?? []),
            $entries,
        );
    }

    private function listing(): array
    {
        $url = $this->profile->url('/v1/models');
        $reply = Retry::whileLoading(
            fn (): array => Http::get($url, 10, $this->headers('Accept: application/json'), $this->profile->unixSocket()),
            self::$loadingSteps,
            self::$loadingLimit,
        );
        if (Retry::isLoading($reply['status'], $reply['body'])) {
            throw Retry::loadingTimeout(self::$loadingLimit);
        }
        if ($reply['status'] >= 400) {
            throw Http::error($reply['status'], $reply['body']);
        }
        $data = (Json::object($reply['body']) ?? [])['data'] ?? null;
        if (!is_array($data)) {
            throw new LlmException("the server at {$url} did not return a model list");
        }

        $entries = [];
        foreach ($data as $entry) {
            if (is_array($entry) && is_string($entry['id'] ?? null) && $entry['id'] !== '') {
                $entries[] = $entry;
            }
        }

        return $entries;
    }

    private function keepLevels(array $entries): void
    {
        $levels = [];
        foreach ($entries as $entry) {
            $reported = ListedModel::efforts($entry);
            if ($reported !== null) {
                $levels[(string) $entry['id']] = $reported;
            }
        }
        $this->cache?->putLevels(CapabilityCache::key($this->profile), $levels);
    }

    private function reportedLevels(): ?array
    {
        if ($this->cache === null || !EffortMapping::readsListing($this->profile)) {
            return null;
        }
        $key = CapabilityCache::key($this->profile);
        $levels = $this->cache->levels($key, $this->profile->model);
        if ($levels === null && !$this->levelsAsked) {
            $this->levelsAsked = true;
            try {
                $this->keepLevels($this->listing());
            } catch (PhycodeException) {
            }
            $levels = $this->cache->levels($key, $this->profile->model);
        }

        return $levels;
    }

    public function describe(string $model): ModelInfo
    {
        $llama = $this->llama();
        if ($llama !== null) {
            return $llama->describe($model);
        }

        foreach ($this->listModels() as $info) {
            if ($info->id === $model) {
                return $info;
            }
        }

        return new ModelInfo(id: $model, profile: $this->profile->name, protocol: $this->profile->protocol);
    }

    private function llama(): ?LlamaDiscovery
    {
        if ($this->llamaChecked) {
            return $this->llama;
        }
        $discovery = new LlamaDiscovery($this->profile, self::$loadingSteps, self::$loadingLimit);
        $answer = $discovery->llamaServer();
        if ($answer === null) {
            return null;
        }
        $this->llamaChecked = true;
        $this->llama = $answer ? $discovery : null;

        return $this->llama;
    }

    private function requestBody(array $messages, array $tools, ChatOptions $options): array
    {
        $thinking = EffortMapping::chatFields($this->profile, $this->reportedLevels());
        $extra = $this->profile->extraBody;
        if (is_array($extra['chat_template_kwargs'] ?? null)) {
            $extra['chat_template_kwargs'] = array_merge($thinking['chat_template_kwargs'] ?? [], $extra['chat_template_kwargs']);
        }

        $wire = array_map(fn (array $message): array => $this->wireMessage($message, $tools !== []), $messages);
        if (PromptCache::marksChat($this->profile)) {
            $wire = PromptCache::markChat($wire, $this->profile->promptCacheTtl);
        }
        $body = array_merge(
            [
                'model' => $this->profile->model,
                'messages' => $wire,
                'stream' => true,
                'stream_options' => ['include_usage' => true],
                $this->profile->tokenParam => $this->profile->maxOutputTokens,
            ],
            $this->profile->sampling,
            $options->overrides,
            PromptCache::bodyFields($this->profile, $options->cacheKey),
            $thinking,
            $extra,
        );
        if ($tools !== []) {
            $body['tools'] = $tools;
            $body['tool_choice'] = 'auto';
            $body['parallel_tool_calls'] = false;
        }

        return EffortMapping::withoutSampling(EffortMapping::withoutReasoningSampling($body, $thinking), $this->profile);
    }

    private function wireMessage(array $message, bool $tools): array
    {
        $replay = is_array($message['extra'][self::EXTRA] ?? null) ? $message['extra'][self::EXTRA] : [];
        $message = array_diff_key($message, ['extra' => true]);
        if (($message['role'] ?? null) === 'user' && isset($message['images'])) {
            $images = Image::of($message);
            unset($message['images']);
            if ($images !== [] && $this->profile->supportsVision()) {
                $message['content'] = [
                    ...(($message['content'] ?? '') === '' ? [] : [['type' => 'text', 'text' => (string) $message['content']]]),
                    ...array_map(static fn (Image $image): array => ['type' => 'image_url', 'image_url' => ['url' => 'data:' . $image->mime . ';base64,' . $image->data]], $images),
                ];
            } else {
                $message['content'] = Image::textWithPlaceholders(['content' => $message['content'] ?? ''] + ['images' => array_map(static fn (Image $image): array => $image->toArray(), $images)]);
            }

            return $message;
        }
        if (($message['role'] ?? null) !== 'assistant') {
            return $message;
        }

        return match (EffortMapping::format($this->profile)) {
            'openrouter' => isset($message['tool_calls'], $replay['reasoning_details'])
                ? $message + ['reasoning_details' => $replay['reasoning_details']]
                : $message,
            'deepseek' => $tools ? $message + ['reasoning_content' => (string) ($replay['reasoning_content'] ?? '')] : $message,
            default => $message,
        };
    }

    private function accepted(array $fields): array
    {
        $fields = array_diff_key($fields, array_flip($this->dropped));
        if ($this->tokenParam !== null && array_key_exists($this->profile->tokenParam, $fields)) {
            $limit = $fields[$this->profile->tokenParam];
            unset($fields[$this->profile->tokenParam]);
            $fields[$this->tokenParam] = $limit;
        }

        return $fields;
    }

    private function refusedField(array $result, array $fields): ?array
    {
        if ($result['streamed'] || !$result['ok'] || $result['error'] !== null || !in_array($result['status'], [400, 422], true)) {
            return null;
        }
        if (str_contains($result['raw'], self::FUNCTION_TOOLS_REFUSAL)) {
            return null;
        }
        $refused = RejectedParameter::inReply($result['raw']);
        if ($refused === null) {
            return null;
        }
        $param = $refused['param'];
        $limit = $this->tokenParam ?? $this->profile->tokenParam;
        if ($refused['use'] !== null && $param === $limit && array_key_exists($param, $this->accepted($fields))) {
            $this->tokenParam = $refused['use'];

            return ['limit' => $refused['use']];
        }
        if (in_array($param, self::NEEDED, true) || in_array($param, $this->dropped, true) || !array_key_exists($param, $fields)) {
            return null;
        }
        $this->dropped[] = $param;

        return ['drop' => $param];
    }

    private static function told(array $adjustments): string
    {
        $dropped = array_column($adjustments, 'drop');
        $limit = array_column($adjustments, 'limit');
        $parts = [];
        if ($dropped !== []) {
            $parts[] = 'does not take ' . implode(', ', $dropped);
        }
        if ($limit !== []) {
            $parts[] = "wants {$limit[0]} for the limit of the reply";
        }

        return 'the server ' . implode(' and ', $parts) . ': the request was changed and sent again, and stays changed for this session';
    }

    private function headers(string ...$lines): array
    {
        return [...$lines, ...Http::authorization($this->profile)];
    }

    private function send(string $url, array $headers, string $body, ChatOptions $options): array
    {
        $parser = new SseParser();
        $deltas = new ToolCallAccumulator();
        $state = [
            'content' => '', 'reasoning' => '', 'finish' => null, 'usage' => [], 'timings' => [], 'raw' => '', 'bytes' => 0, 'error' => null,
            'events' => 0, 'whole' => '', 'replay' => ['reasoning_content' => '', 'reasoning_details' => []],
        ];
        $cancel = $options->cancel;

        $curl = curl_init($url);
        if ($curl === false) {
            throw new LlmException('cannot start an HTTP request');
        }
        curl_setopt_array($curl, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_LOW_SPEED_LIMIT => 1,
            CURLOPT_LOW_SPEED_TIME => 180,
            CURLOPT_NOPROGRESS => false,
            CURLOPT_PROGRESSFUNCTION => static fn (): int => $cancel !== null && $cancel->isCancelled() ? 1 : 0,
            CURLOPT_WRITEFUNCTION => function ($handle, string $chunk) use (&$state, $parser, $deltas, $options, $cancel): int {
                if ($cancel !== null && $cancel->isCancelled()) {
                    return 0;
                }
                $state['bytes'] += strlen($chunk);
                if (strlen($state['raw']) < self::MAX_RAW_BYTES) {
                    $state['raw'] .= $chunk;
                }
                if ($state['events'] === 0 && strlen($state['whole']) < self::MAX_WHOLE_BYTES) {
                    $state['whole'] .= $chunk;
                }
                foreach ($parser->feed($chunk) as $payload) {
                    $state['events']++;
                    if (!$this->consume($payload, $state, $deltas, $options)) {
                        return 0;
                    }
                }

                return strlen($chunk);
            },
        ]);

        $socket = $this->profile->unixSocket();
        if ($socket !== null) {
            curl_setopt($curl, CURLOPT_UNIX_SOCKET_PATH, $socket);
        }

        $ok = $this->transfer($curl, $cancel);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $transport = curl_error($curl);
        curl_close($curl);

        if ($ok && $state['error'] === null && $status >= 200 && $status < 300) {
            foreach ($parser->flush() as $payload) {
                $state['events']++;
                if (!$this->consume($payload, $state, $deltas, $options)) {
                    break;
                }
            }
            $event = $state['events'] === 0 ? self::asEvent($state['whole']) : null;
            if ($event !== null) {
                $state['events']++;
                $this->consume($event, $state, $deltas, $options);
            }
        }

        return [
            'status' => $status,
            'ok' => $ok,
            'transport' => $transport,
            'streamed' => $state['bytes'] > 0 && $status >= 200 && $status < 300,
            'raw' => $state['raw'],
            'error' => $state['error'],
            'content' => $state['content'],
            'reasoning' => $state['reasoning'],
            'finish' => $state['finish'],
            'calls' => $deltas->calls(),
            'usage' => $state['usage'],
            'timings' => $state['timings'],
            'events' => $state['events'],
            'replay' => $state['replay'],
        ];
    }

    private function loading(array $result): bool
    {
        return $result['ok'] && $result['error'] === null && Retry::isLoading($result['status'], $result['raw']);
    }

    private function retryable(array $result): bool
    {
        if ($result['streamed'] || $result['error'] !== null) {
            return false;
        }

        return !$result['ok'] || in_array($result['status'], self::RETRY_STATUSES, true);
    }

    private function response(array $result): LlmResponse
    {
        if ($result['error'] !== null) {
            throw new LlmException('server error: ' . $result['error']);
        }
        if (!$result['ok']) {
            throw new LlmException('connection to the model server failed: ' . $result['transport']);
        }
        if ($result['status'] >= 400) {
            throw Http::error($result['status'], $result['raw']);
        }
        if (($result['finish'] ?? '') === '') {
            throw new LlmException($result['events'] === 0 ? 'the reply is neither an event stream nor a chat completion' : 'the reply ended without a finish reason');
        }
        $finish = FinishReason::normalize($result['finish'], $result['calls'] !== []);
        if ($finish === FinishReason::ERROR) {
            throw new LlmException("the reply ended with the finish reason {$result['finish']}, which this client does not know");
        }

        return new LlmResponse(
            $result['content'],
            $result['reasoning'],
            $result['calls'],
            $finish,
            self::usage($result['usage'], $result['timings']),
            $this->replayOf($result),
        );
    }

    private function replayOf(array $result): array
    {
        $replay = match (EffortMapping::format($this->profile)) {
            'deepseek' => ['reasoning_content' => $result['replay']['reasoning_content']],
            'openrouter' => $result['calls'] === [] || $result['replay']['reasoning_details'] === []
                ? []
                : ['reasoning_details' => $result['replay']['reasoning_details']],
            default => [],
        };

        return $replay === [] ? [] : [self::EXTRA => $replay];
    }

    private static function usage(array $usage, array $timings): Usage
    {
        $details = is_array($usage['prompt_tokens_details'] ?? null) ? $usage['prompt_tokens_details'] : [];
        $cached = self::intValue($details, 'cached_tokens') ?? self::intValue($usage, 'prompt_cache_hit_tokens') ?? self::intValue($timings, 'cache_n');
        $promptN = self::intValue($timings, 'prompt_n');
        $speed = $timings['predicted_per_second'] ?? null;

        return new Usage(
            promptTokens: self::intValue($usage, 'prompt_tokens') ?? ($promptN === null ? null : $promptN + ($cached ?? 0)),
            completionTokens: self::intValue($usage, 'completion_tokens') ?? self::intValue($timings, 'predicted_n'),
            cachedTokens: $cached,
            tokensPerSecond: self::finiteFloat($speed),
        );
    }

    private static function intValue(array $data, string $key): ?int
    {
        return is_int($data[$key] ?? null) ? $data[$key] : null;
    }

    private static function finiteFloat(mixed $value): ?float
    {
        return (is_int($value) || is_float($value)) && is_finite((float) $value) ? (float) $value : null;
    }

    private function failIfCancelled(?Cancellation $cancel): void
    {
        if ($cancel !== null && $cancel->isCancelled()) {
            throw new LlmException('cancelled: ' . $cancel->reason());
        }
    }

    private function pause(float $seconds, ?Cancellation $cancel): void
    {
        $until = microtime(true) + $seconds;
        while (($left = $until - microtime(true)) > 0) {
            if ($cancel !== null && $cancel->isCancelled()) {
                return;
            }
            usleep((int) (min(self::WAIT_SECONDS, $left) * 1000000));
        }
    }

    private function transfer(\CurlHandle $curl, ?Cancellation $cancel): bool
    {
        $multi = curl_multi_init();
        curl_multi_add_handle($multi, $curl);
        $running = 1;
        while ($running > 0 && ($cancel === null || !$cancel->isCancelled())) {
            curl_multi_exec($multi, $running);
            if ($running > 0) {
                curl_multi_select($multi, self::WAIT_SECONDS);
            }
        }
        $done = curl_multi_info_read($multi);
        curl_multi_remove_handle($multi, $curl);
        curl_multi_close($multi);

        return is_array($done) && $done['result'] === CURLE_OK;
    }

    private function consume(string $payload, array &$state, ToolCallAccumulator $calls, ChatOptions $options): bool
    {
        if ($payload === '[DONE]') {
            return true;
        }
        $event = Json::object($payload);
        if ($event === null) {
            return true;
        }
        if (isset($event['error'])) {
            $error = $event['error'];
            $state['error'] = is_array($error) ? (string) ($error['message'] ?? 'unknown error') : (string) $error;

            return false;
        }
        if (isset($event['timings']) && is_array($event['timings'])) {
            $state['timings'] = $event['timings'];
        }
        if (isset($event['usage']) && is_array($event['usage'])) {
            $state['usage'] = array_merge($state['usage'], $event['usage']);
        }

        $choice = $event['choices'][0] ?? null;
        if (!is_array($choice)) {
            return true;
        }
        if (is_string($choice['finish_reason'] ?? null)) {
            $state['finish'] = $choice['finish_reason'];
        }
        $delta = is_array($choice['delta'] ?? null) ? $choice['delta'] : [];

        $content = self::parts($delta['content'] ?? null);
        if ($content['text'] !== '') {
            $state['content'] .= $content['text'];
            $options->emit('text', $content['text']);
        }
        $thought = self::thoughtOf($delta) . $content['thinking'];
        if ($thought !== '') {
            $state['reasoning'] .= $thought;
            $options->emit('reasoning', $thought);
        }
        if (is_string($delta['reasoning_content'] ?? null)) {
            $state['replay']['reasoning_content'] .= $delta['reasoning_content'];
        }
        if (is_array($delta['reasoning_details'] ?? null)) {
            $state['replay']['reasoning_details'] = ReasoningDetails::join($state['replay']['reasoning_details'], $delta['reasoning_details']);
        }
        if (is_array($delta['tool_calls'] ?? null)) {
            $calls->merge($delta['tool_calls']);
        }

        return true;
    }

    private static function parts(mixed $content): array
    {
        if (is_string($content)) {
            return ['text' => $content, 'thinking' => ''];
        }
        $text = '';
        $thinking = '';
        foreach (is_array($content) ? $content : [] as $part) {
            if (!is_array($part)) {
                continue;
            }
            if (($part['type'] ?? null) === 'text' && is_string($part['text'] ?? null)) {
                $text .= $part['text'];
            } elseif (($part['type'] ?? null) === 'thinking') {
                $inner = is_array($part['thinking'] ?? null) ? $part['thinking'] : [$part['thinking'] ?? null];
                foreach ($inner as $item) {
                    if (is_string($item)) {
                        $thinking .= $item;
                    } elseif (is_array($item) && ($item['type'] ?? null) === 'text' && is_string($item['text'] ?? null)) {
                        $thinking .= $item['text'];
                    }
                }
            }
        }

        return ['text' => $text, 'thinking' => $thinking];
    }

    private static function thoughtOf(array $delta): string
    {
        foreach (['reasoning_content', 'reasoning'] as $field) {
            if (is_string($delta[$field] ?? null) && $delta[$field] !== '') {
                return $delta[$field];
            }
        }

        return '';
    }

    private static function asEvent(string $whole): ?string
    {
        $reply = Json::object($whole);
        $choice = $reply['choices'][0] ?? null;
        if (!is_array($choice) || !is_array($choice['message'] ?? null)) {
            return null;
        }

        return Json::encode([
            'choices' => [['delta' => $choice['message'], 'finish_reason' => $choice['finish_reason'] ?? null]],
            'usage' => $reply['usage'] ?? [],
            'timings' => $reply['timings'] ?? [],
        ]);
    }
}
