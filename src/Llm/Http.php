<?php

declare(strict_types=1);

namespace Phycode\Llm;

use Phycode\Config\Profile;
use Phycode\Support\Json;
use Phycode\Ui\TerminalText;

final class Http
{
    public static function get(string $url, int $timeout = 5, array $headers = [], ?string $unixSocket = null): array
    {
        $curl = curl_init($url);
        if ($curl === false) {
            throw new LlmException('cannot start an HTTP request');
        }
        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => $timeout,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_HTTPHEADER => $headers,
        ] + ($unixSocket === null ? [] : [CURLOPT_UNIX_SOCKET_PATH => $unixSocket]));
        $body = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $error = curl_error($curl);
        curl_close($curl);

        if ($body === false) {
            throw new LlmException("connection to {$url} failed: {$error}");
        }

        return ['status' => $status, 'body' => (string) $body];
    }

    public static function authorization(Profile $profile): array
    {
        $key = $profile->apiKey();

        return $key === null ? [] : ['Authorization: Bearer ' . $key];
    }

    public static function error(int $status, string $body): LlmException
    {
        $decoded = Json::object($body);
        $message = is_array($decoded['error'] ?? null) ? (string) ($decoded['error']['message'] ?? '') : '';
        $detail = $message !== '' ? TerminalText::clean($message) : trim(TerminalText::clean(substr($body, 0, 300)));

        return new LlmException("HTTP {$status}: " . $detail);
    }
}
