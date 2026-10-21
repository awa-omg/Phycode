<?php

declare(strict_types=1);

namespace Phycode\Llm;

use Phycode\Support\Json;

final class Probe
{
    public const NATIVE = 'native';
    public const TEXT = 'text';
    public const NONE = 'none';

    public static function run(ChatClient $client): array
    {
        $spec = [
            'type' => 'function',
            'function' => [
                'name' => 'probe_echo',
                'description' => 'Echo one word back. Used only to test tool calling.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => ['word' => ['type' => 'string']],
                    'required' => ['word'],
                ],
            ],
        ];
        $request = 'Call the tool probe_echo with word "phycode". Do not answer in plain text.';
        $options = new ChatOptions(overrides: ['temperature' => 0.0]);

        try {
            $response = $client->chat(
                [['role' => 'system', 'content' => 'You can call tools.'], ['role' => 'user', 'content' => $request]],
                [$spec],
                $options,
            );
            foreach ($response->toolCalls as $call) {
                if ($call->name === 'probe_echo' && Json::object($call->arguments) !== null) {
                    return ['mode' => self::NATIVE, 'error' => null, 'transient' => false];
                }
            }
            $nativeError = 'the model did not return a native tool call';
        } catch (LlmException $error) {
            if (self::isTransient($error)) {
                return ['mode' => self::NONE, 'error' => $error->getMessage(), 'transient' => true];
            }
            $nativeError = $error->getMessage();
        }

        try {
            $response = $client->chat(
                [
                    ['role' => 'system', 'content' => TextToolProtocol::instructions('- probe_echo(word: string): echo one word back')],
                    ['role' => 'user', 'content' => $request],
                ],
                [],
                $options,
            );
            foreach (TextToolProtocol::parse($response->content)['calls'] as $call) {
                if ($call->name === 'probe_echo') {
                    return ['mode' => self::TEXT, 'error' => null, 'transient' => false];
                }
            }

            return ['mode' => self::NONE, 'error' => $nativeError . '; no text tool call either', 'transient' => false];
        } catch (LlmException $error) {
            return ['mode' => self::NONE, 'error' => $error->getMessage(), 'transient' => self::isTransient($error)];
        }
    }

    private static function isTransient(LlmException $error): bool
    {
        $message = $error->getMessage();

        return str_starts_with($message, 'connection')
            || str_starts_with($message, 'cancelled')
            || preg_match('/^HTTP (429|502|503|504): /', $message) === 1;
    }
}
