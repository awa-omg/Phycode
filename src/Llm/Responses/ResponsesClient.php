<?php

declare(strict_types=1);

namespace Phycode\Llm\Responses;

use Phycode\Config\Profile;
use Phycode\Llm\Canonical\FinishReason;
use Phycode\Llm\Canonical\ListedModel;
use Phycode\Llm\Canonical\ModelInfo;
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

final class ResponsesClient implements ChatClient
{
    private const MAX_RAW_BYTES = 65536;
    private const TIMEOUT = 10;

    private readonly Retry $retry;

    public function __construct(private readonly Profile $profile, array $retryDelays = Retry::DELAYS)
    {
        $this->retry = new Retry($retryDelays);
    }

    public function profile(): Profile
    {
        return $this->profile;
    }

    public function chat(array $messages, array $tools, ChatOptions $options): LlmResponse
    {
        $url = $this->profile->url('/v1/responses');
        $request = $this->requestBody($messages, $tools, $options);

        return $this->response($this->exchange($url, Json::encode($request), $options), $request['max_output_tokens']);
    }

    public function listModels(): array
    {
        $url = $this->profile->url('/v1/models');
        $reply = Http::get($url, self::TIMEOUT, $this->headers('Accept: application/json'), $this->profile->unixSocket());
        if ($reply['status'] >= 400) {
            throw Http::error($reply['status'], $reply['body']);
        }
        $data = (Json::object($reply['body']) ?? [])['data'] ?? null;
        if (!is_array($data)) {
            throw new LlmException("the server at {$url} did not return a model list");
        }

        $models = [];
        foreach ($data as $entry) {
            if (!is_array($entry) || !is_string($entry['id'] ?? null) || $entry['id'] === '') {
                continue;
            }
            $models[] = ListedModel::from($entry, $this->profile->name, $this->profile->protocol, ListedModel::efforts($entry)['effort'] ?? []);
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

    private function requestBody(array $messages, array $tools, ChatOptions $options): array
    {
        $body = [
            'model' => $this->profile->model,
            'input' => ResponsesInput::items($messages, $this->profile->supportsVision()),
            'stream' => true,
            'store' => $this->profile->responsesStore(),
            'max_output_tokens' => $this->profile->maxOutputTokens,
        ];
        $instructions = ResponsesInput::instructions($messages);
        if ($instructions !== null) {
            $body['instructions'] = $instructions;
        }
        if ($tools !== []) {
            $body['tools'] = ResponsesInput::tools($tools);
            $body['tool_choice'] = 'auto';
            $body['parallel_tool_calls'] = false;
        }

        $effort = EffortMapping::responses($this->profile);
        $body['reasoning'] = ['effort' => $effort];
        if ($effort === 'none') {
            $sampling = array_merge($this->profile->sampling, $options->overrides);
            $body = array_merge($body, array_intersect_key($sampling, ['temperature' => true, 'top_p' => true]));
        } else {
            $body['include'] = ['reasoning.encrypted_content'];
        }

        return EffortMapping::withoutSampling(array_merge($body, PromptCache::bodyFields($this->profile, $options->cacheKey, true), $this->profile->extraBody), $this->profile);
    }

    private function headers(string ...$lines): array
    {
        return [...$lines, ...Http::authorization($this->profile)];
    }

    private function exchange(string $url, string $body, ChatOptions $options): ResponsesReply
    {
        $headers = $this->headers('Content-Type: application/json', 'Accept: text/event-stream', ...PromptCache::headers($this->profile, $options->cacheKey));
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
        $reply = new ResponsesReply();
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

    private function checked(array $result): ResponsesReply
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

        return $result['reply'];
    }

    private function response(ResponsesReply $reply, mixed $limit): LlmResponse
    {
        $calls = $reply->calls();
        $usage = $reply->usage();
        $finish = match ($reply->status()) {
            'completed' => self::used($usage->completionTokens, $limit) ? FinishReason::LENGTH : FinishReason::normalize(null, $calls !== []),
            'incomplete' => self::incomplete($reply->reason()),
            default => throw new LlmException('the reply ended before response.completed'),
        };
        $replay = $reply->replay();

        return new LlmResponse(
            $reply->text(),
            $reply->reasoning(),
            $calls,
            $finish,
            $usage,
            $replay === [] ? [] : [ResponsesInput::EXTRA => $replay],
        );
    }

    private static function used(?int $completion, mixed $limit): bool
    {
        return $completion !== null && is_int($limit) && $limit > 0 && $completion >= $limit;
    }

    private static function incomplete(?string $reason): string
    {
        return match ($reason) {
            'max_output_tokens' => FinishReason::LENGTH,
            'content_filter' => throw new LlmException('the reply was stopped by a content filter'),
            default => throw new LlmException('the reply is incomplete: ' . ($reason ?? 'no reason given')),
        };
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
