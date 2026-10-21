<?php

declare(strict_types=1);

namespace Phycode\Llm;

use Phycode\Support\Cancellation;

final class StreamTransfer
{
    private const WAIT_SECONDS = 0.1;

    public static function post(string $url, array $headers, string $body, ?string $unixSocket, ?Cancellation $cancel, \Closure $onChunk): array
    {
        $curl = curl_init($url);
        if ($curl === false) {
            throw new LlmException('cannot start an HTTP request');
        }
        $bytes = 0;
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
            CURLOPT_WRITEFUNCTION => static function (\CurlHandle $handle, string $chunk) use (&$bytes, $onChunk, $cancel): int {
                if ($cancel !== null && $cancel->isCancelled()) {
                    return 0;
                }
                $bytes += strlen($chunk);
                $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);

                return $onChunk($chunk, $status) ? strlen($chunk) : 0;
            },
        ]);
        if ($unixSocket !== null) {
            curl_setopt($curl, CURLOPT_UNIX_SOCKET_PATH, $unixSocket);
        }

        $ok = self::transfer($curl, $cancel);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $transport = curl_error($curl);
        curl_close($curl);

        return ['status' => $status, 'ok' => $ok, 'transport' => $transport, 'bytes' => $bytes];
    }

    public static function get(string $url, array $headers, ?string $unixSocket, ?Cancellation $cancel, int $timeout): array
    {
        $curl = curl_init($url);
        if ($curl === false) {
            throw new LlmException('cannot start an HTTP request');
        }
        $body = '';
        curl_setopt_array($curl, [
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_CONNECTTIMEOUT => $timeout,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_NOPROGRESS => false,
            CURLOPT_PROGRESSFUNCTION => static fn (): int => $cancel !== null && $cancel->isCancelled() ? 1 : 0,
            CURLOPT_WRITEFUNCTION => static function (\CurlHandle $handle, string $chunk) use (&$body, $cancel): int {
                if ($cancel !== null && $cancel->isCancelled()) {
                    return 0;
                }
                $body .= $chunk;

                return strlen($chunk);
            },
        ]);
        if ($unixSocket !== null) {
            curl_setopt($curl, CURLOPT_UNIX_SOCKET_PATH, $unixSocket);
        }

        $ok = self::transfer($curl, $cancel);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $transport = curl_error($curl);
        curl_close($curl);

        return ['status' => $status, 'ok' => $ok, 'transport' => $transport, 'body' => $body];
    }

    private static function transfer(\CurlHandle $curl, ?Cancellation $cancel): bool
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
}
