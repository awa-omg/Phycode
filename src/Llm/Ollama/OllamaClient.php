<?php

declare(strict_types=1);

namespace Phycode\Llm\Ollama;

use Phycode\Config\Profile;
use Phycode\Llm\Canonical\FinishReason;
use Phycode\Llm\Canonical\ModelInfo;
use Phycode\Llm\ChatClient;
use Phycode\Llm\ChatOptions;
use Phycode\Llm\EffortMapping;
use Phycode\Llm\LlmException;
use Phycode\Llm\LlmResponse;
use Phycode\Llm\Retry;
use Phycode\Support\Cancellation;
use Phycode\Support\Json;
use Phycode\Ui\TerminalText;

final class OllamaClient implements ChatClient
{
    private const MAX_RAW_BYTES = 65536;
    private const WAIT_SECONDS = 0.1;
    private const SHOW_TTL_SECONDS = 600.0;

    private readonly Retry $retry;

    private array $shows = [];

    public function __construct(private readonly Profile $profile, array $retryDelays = Retry::DELAYS, private readonly float $showTtl = self::SHOW_TTL_SECONDS)
    {
        $this->retry = new Retry($retryDelays);
    }

    public function profile(): Profile
    {
        return $this->profile;
    }

    public function chat(array $messages, array $tools, ChatOptions $options): LlmResponse
    {
        $url = $this->base() . '/api/chat';
        $body = Json::encode($this->requestBody($messages, $tools, $options));
        $headers = $this->headers('Content-Type: application/json', 'Accept: application/x-ndjson');
        $cancel = $options->cancel;

        $retries = 0;
        while (true) {
            $result = $this->send($url, $headers, $body, $options);
            $this->failIfCancelled($cancel);
            if (!$this->retry->again($result, $retries)) {
                return $this->response($result);
            }
            $this->retry->wait($retries++, $cancel);
            $this->failIfCancelled($cancel);
        }
    }

    public function listModels(): array
    {
        $url = $this->base() . '/api/tags';
        $reply = $this->exchange($url);
        if ($reply['status'] >= 400) {
            throw self::httpError($reply['status'], $reply['body']);
        }
        $models = (Json::object($reply['body']) ?? [])['models'] ?? null;
        if (!is_array($models)) {
            throw new LlmException("the server at {$url} did not return a model list");
        }

        $found = [];
        foreach ($models as $entry) {
            if (is_array($entry) && is_string($entry['name'] ?? null) && $entry['name'] !== '') {
                $found[] = [$entry, $this->shownForListing($entry['name'])];
            }
        }
        $running = $this->running();

        $list = [];
        foreach ($found as [$entry, $shown]) {
            $capabilities = $shown !== null && array_key_exists('capabilities', $shown) ? $shown['capabilities'] : ($entry['capabilities'] ?? null);
            $levels = self::thinkingLevels($capabilities, $shown['thinking'] ?? null);
            $list[] = new ModelInfo(
                id: $entry['name'],
                profile: $this->profile->name,
                protocol: $this->profile->protocol,
                contextWindow: self::contextLength($shown['model_info'] ?? null),
                tools: self::hasTools($capabilities),
                thinkingLevels: $levels,
                loaded: $running === null ? null : in_array(self::fullName($entry['name']), $running, true),
                source: 'tags',
                kind: $capabilities === ['embedding'] ? 'embedding' : null,
                effortLevels: self::effortLevels($levels),
            );
        }

        return $list;
    }

    public function describe(string $model): ModelInfo
    {
        $shown = $this->shown($model);
        if ($shown === null) {
            return new ModelInfo(id: $model, profile: $this->profile->name, protocol: $this->profile->protocol);
        }

        $running = $this->running();
        $capabilities = $shown['capabilities'] ?? null;
        $levels = self::thinkingLevels($capabilities, $shown['thinking'] ?? null);

        return new ModelInfo(
            id: $model,
            profile: $this->profile->name,
            protocol: $this->profile->protocol,
            contextWindow: self::contextLength($shown['model_info'] ?? null),
            tools: self::hasTools($capabilities),
            thinkingLevels: $levels,
            loaded: $running === null ? null : in_array(self::fullName($model), $running, true),
            source: 'show',
            effortLevels: self::effortLevels($levels),
        );
    }

    private function requestBody(array $messages, array $tools, ChatOptions $options): array
    {
        $body = [
            'model' => $this->profile->model,
            'messages' => OllamaMessages::convert($messages, $this->profile->supportsVision()),
            'stream' => true,
            'think' => $this->think(),
            'options' => $this->options($options),
            'keep_alive' => $this->keepAlive(),
        ];
        if ($tools !== []) {
            $body['tools'] = self::tools($tools);
        }

        $extra = $this->profile->extraBody;
        if (is_array($extra['options'] ?? null)) {
            $body['options'] = array_merge($body['options'], $extra['options']);
            unset($extra['options']);
        }

        return array_merge($body, $extra);
    }

    private static function tools(array $tools): array
    {
        return array_map(static function (array $tool): array {
            $parameters = $tool['function']['parameters'] ?? null;
            if (is_array($parameters) && ($parameters['properties'] ?? null) === []) {
                $tool['function']['parameters']['properties'] = new \stdClass();
            }

            return $tool;
        }, $tools);
    }

    private function think(): bool|string
    {
        if (EffortMapping::level($this->profile->thinking) === 'none') {
            return false;
        }

        return EffortMapping::ollama($this->profile->thinking, $this->namedLevels());
    }

    private function namedLevels(): array
    {
        try {
            $shown = $this->shown($this->profile->model);
        } catch (LlmException) {
            return [];
        }
        $values = $shown['thinking']['values'] ?? [];

        return array_values(array_filter(is_array($values) ? $values : [], static fn (mixed $value): bool => is_string($value) && $value !== ''));
    }

    private function options(ChatOptions $options): array
    {
        $values = ['num_predict' => $this->profile->maxOutputTokens];
        if ($this->profile->contextLimit > 0) {
            $values['num_ctx'] = $this->profile->contextLimit;
        }

        return array_merge($values, $this->profile->sampling, $options->overrides);
    }

    private function keepAlive(): int|float|string
    {
        $value = $this->profile->keepAlive;

        return is_numeric($value) ? $value + 0 : $value;
    }

    private function headers(string ...$lines): array
    {
        $key = $this->profile->apiKey();
        if ($key !== null) {
            $lines[] = 'Authorization: Bearer ' . $key;
        }

        return $lines;
    }

    private function exchange(string $url, ?string $json = null): array
    {
        $curl = curl_init($url);
        if ($curl === false) {
            throw new LlmException('cannot start an HTTP request');
        }
        $headers = $json === null
            ? $this->headers('Accept: application/json')
            : $this->headers('Content-Type: application/json', 'Accept: application/json');
        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HTTPHEADER => $headers,
        ]);
        if ($json !== null) {
            curl_setopt_array($curl, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $json]);
        }
        $body = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $error = curl_error($curl);
        curl_close($curl);

        if ($body === false) {
            throw new LlmException("connection to {$url} failed: {$error}");
        }

        return ['status' => $status, 'body' => (string) $body];
    }

    private function running(): ?array
    {
        $reply = $this->exchange($this->base() . '/api/ps');
        if (!self::succeeded($reply['status'])) {
            return null;
        }
        $running = (Json::object($reply['body']) ?? [])['models'] ?? null;
        if (!is_array($running)) {
            return null;
        }

        $names = [];
        foreach ($running as $entry) {
            foreach (['name', 'model'] as $field) {
                $name = is_array($entry) ? self::text($entry[$field] ?? null) : '';
                if ($name !== '') {
                    $names[] = self::fullName($name);
                }
            }
        }

        return $names;
    }

    private function shown(string $model): ?array
    {
        $kept = $this->shows[$model] ?? null;
        if ($kept !== null && $kept['until'] > microtime(true)) {
            return $kept['body'];
        }

        $reply = $this->exchange($this->base() . '/api/show', Json::encode(['model' => $model]));
        if ($reply['status'] >= 400 && $reply['status'] !== 404) {
            throw self::httpError($reply['status'], $reply['body']);
        }
        $body = $reply['status'] === 404 ? null : (Json::object($reply['body']) ?? []);
        $this->shows[$model] = ['until' => microtime(true) + $this->showTtl, 'body' => $body];

        return $body;
    }

    private function shownForListing(string $model): ?array
    {
        try {
            return $this->shown($model);
        } catch (LlmException) {
            return null;
        }
    }

    private function send(string $url, array $headers, string $body, ChatOptions $options): array
    {
        $parser = new NdjsonParser();
        $reply = new OllamaReply();
        $state = ['bytes' => 0, 'raw' => ''];
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
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_LOW_SPEED_LIMIT => 1,
            CURLOPT_LOW_SPEED_TIME => 180,
            CURLOPT_NOPROGRESS => false,
            CURLOPT_PROGRESSFUNCTION => static fn (): int => $cancel !== null && $cancel->isCancelled() ? 1 : 0,
            CURLOPT_WRITEFUNCTION => function (\CurlHandle $handle, string $chunk) use (&$state, $parser, $reply, $options, $cancel): int {
                if ($cancel !== null && $cancel->isCancelled()) {
                    return 0;
                }
                $state['bytes'] += strlen($chunk);
                if (strlen($state['raw']) < self::MAX_RAW_BYTES) {
                    $state['raw'] .= $chunk;
                }
                if (self::succeeded((int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE))) {
                    foreach ($parser->feed($chunk) as $line) {
                        if (!$reply->consume($line, $options)) {
                            return 0;
                        }
                    }
                }

                return strlen($chunk);
            },
        ]);

        $ok = $this->transfer($curl, $cancel);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $transport = curl_error($curl);
        curl_close($curl);

        if ($ok && $reply->error() === null && self::succeeded($status)) {
            foreach ($parser->flush() as $line) {
                if (!$reply->consume($line, $options)) {
                    break;
                }
            }
        }

        return [
            'status' => $status,
            'ok' => $ok,
            'transport' => $transport,
            'streamed' => $state['bytes'] > 0 && self::succeeded($status),
            'raw' => $state['raw'],
            'error' => $reply->error(),
            'reply' => $reply,
        ];
    }

    private function response(array $result): LlmResponse
    {
        if ($result['error'] !== null) {
            throw new LlmException('server error: ' . $result['error']);
        }
        if (!$result['ok']) {
            throw new LlmException('connection to the model server failed: ' . $result['transport']);
        }
        if (!self::succeeded($result['status'])) {
            throw self::httpError($result['status'], $result['raw']);
        }

        $reply = $result['reply'];
        if (!$reply->done()) {
            throw new LlmException('server error: the reply ended before its final line');
        }
        $calls = $reply->calls();
        $reason = $reply->doneReason();
        if ($calls !== [] && $reason === 'stop') {
            $reason = null;
        }

        return new LlmResponse(
            $reply->content(),
            $reply->reasoning(),
            $calls,
            FinishReason::normalize($reason, $calls !== []),
            $reply->usage(),
        );
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

    private static function httpError(int $status, string $body): LlmException
    {
        $error = (Json::object($body) ?? [])['error'] ?? null;
        $message = match (true) {
            is_string($error) => $error,
            is_array($error) && is_string($error['message'] ?? null) => $error['message'],
            default => '',
        };

        $detail = $message !== '' ? TerminalText::clean($message) : trim(TerminalText::clean(substr($body, 0, 300)));

        return new LlmException("HTTP {$status}: " . $detail);
    }

    private function failIfCancelled(?Cancellation $cancel): void
    {
        if ($cancel !== null && $cancel->isCancelled()) {
            throw new LlmException('cancelled: ' . $cancel->reason());
        }
    }

    private function base(): string
    {
        return rtrim($this->profile->url(''), '/');
    }

    private static function succeeded(int $status): bool
    {
        return $status >= 200 && $status < 300;
    }

    private static function fullName(string $name): string
    {
        return preg_match('/:[^\/]*$/', $name) === 1 ? $name : $name . ':latest';
    }

    private static function text(mixed $value): string
    {
        return is_string($value) ? $value : '';
    }

    private static function effortLevels(array $levels): array
    {
        return array_values(array_filter($levels, static fn (string $level): bool => in_array($level, Profile::EFFORTS, true)));
    }

    private static function hasTools(mixed $capabilities): ?bool
    {
        return is_array($capabilities) ? in_array('tools', $capabilities, true) : null;
    }

    private static function contextLength(mixed $info): ?int
    {
        if (!is_array($info)) {
            return null;
        }
        foreach ($info as $key => $value) {
            if (is_string($key) && str_ends_with($key, '.context_length') && is_int($value)) {
                return $value;
            }
        }

        return null;
    }

    private static function thinkingLevels(mixed $capabilities, mixed $thinking): array
    {
        $values = is_array($thinking) ? ($thinking['values'] ?? null) : null;
        if (!is_array($values)) {
            return is_array($capabilities) && in_array('thinking', $capabilities, true) ? ['off', 'on'] : [];
        }

        $levels = [];
        foreach ($values as $value) {
            $level = match (true) {
                is_bool($value) => $value ? 'on' : 'off',
                is_string($value) && $value !== '' => $value,
                default => null,
            };
            if ($level !== null && !in_array($level, $levels, true)) {
                $levels[] = $level;
            }
        }

        return $levels === ['off'] ? [] : $levels;
    }
}
