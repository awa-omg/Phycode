<?php

declare(strict_types=1);

namespace Phycode\Web;

use Phycode\Support\Redaction;
use Phycode\Support\Text;

final class Transport
{
    private const USER_AGENT = 'Mozilla/5.0 (compatible; phycode)';

    public function __construct(
        private readonly SsrfGuard $guard,
        private readonly int $timeoutSeconds = 15,
        private readonly int $maxBytes = 2_000_000,
    ) {
    }

    public function get(string $url, array $headers = [], ?array $types = null): Reply
    {
        return $this->exchange($url, $headers, null, $types);
    }

    public function post(string $url, array $headers, string $body, ?array $types = null): Reply
    {
        return $this->exchange($url, $headers, $body, $types);
    }

    private function exchange(string $url, array $headers, ?string $body, ?array $types): Reply
    {
        $target = $this->guard->check($url);
        $state = [
            'status' => 0,
            'location' => null,
            'type' => null,
            'headersDone' => false,
            'stopped' => false,
            'refused' => null,
            'content' => '',
            'truncated' => false,
        ];
        $limit = $this->maxBytes;

        $curl = curl_init($target['url']);
        if ($curl === false) {
            throw new WebException('cannot start a request');
        }
        $options = [
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_USERAGENT => self::USER_AGENT,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_NOPROXY => '*',
            CURLOPT_ENCODING => '',
            CURLOPT_CONNECTTIMEOUT => $this->timeoutSeconds,
            CURLOPT_TIMEOUT => $this->timeoutSeconds,
            CURLOPT_HEADERFUNCTION => static function ($curl, string $line) use (&$state, $types): int {
                $length = strlen($line);
                if ($state['headersDone']) {
                    return $length;
                }
                if (preg_match('~^HTTP/\S+\s+(\d{3})~', $line, $match) === 1) {
                    $state['status'] = (int) $match[1];
                    $state['location'] = null;
                    $state['type'] = null;

                    return $length;
                }
                $field = trim($line);
                if ($field === '') {
                    if ($state['status'] < 200) {
                        return $length;
                    }
                    $state['headersDone'] = true;
                    if ($state['status'] > 299) {
                        $state['stopped'] = true;

                        return -1;
                    }
                    if ($types !== null && !in_array(Reply::mediaTypeOf($state['type']), $types, true)) {
                        $state['refused'] = $state['type'] ?? 'no Content-Type';
                        $state['stopped'] = true;

                        return -1;
                    }

                    return $length;
                }
                $colon = strpos($field, ':');
                if ($colon !== false) {
                    $name = strtolower(substr($field, 0, $colon));
                    if ($name === 'location') {
                        $state['location'] = trim(substr($field, $colon + 1));
                    } elseif ($name === 'content-type') {
                        $state['type'] = trim(substr($field, $colon + 1));
                    }
                }

                return $length;
            },
            CURLOPT_WRITEFUNCTION => static function ($curl, string $chunk) use (&$state, $limit): int {
                $room = $limit - strlen($state['content']);
                if (strlen($chunk) <= $room) {
                    $state['content'] .= $chunk;

                    return strlen($chunk);
                }
                $state['content'] .= substr($chunk, 0, $room);
                $state['truncated'] = true;

                return 0;
            },
        ];
        if ($body !== null) {
            $options[CURLOPT_POST] = true;
            $options[CURLOPT_POSTFIELDS] = $body;
        }
        if (filter_var($target['host'], FILTER_VALIDATE_IP) === false) {
            $options[CURLOPT_RESOLVE] = ["{$target['host']}:{$target['port']}:" . implode(',', $target['addresses'])];
        }
        curl_setopt_array($curl, $options);
        $ok = curl_exec($curl);
        $errno = curl_errno($curl);
        $error = curl_error($curl);
        curl_close($curl);

        if ($state['refused'] !== null) {
            throw new WebException('unsupported content type ' . Text::oneLine((string) $state['refused'], 60));
        }
        if ($state['stopped'] || $state['truncated']) {
            return self::reply($state);
        }
        if ($ok === false) {
            if ($errno === CURLE_OPERATION_TIMEDOUT) {
                throw new WebException("timed out after {$this->timeoutSeconds}s");
            }

            throw new WebException('request failed: ' . Text::oneLine(Redaction::text($error), 160));
        }
        if ($state['status'] === 0) {
            throw new WebException('no reply');
        }

        return self::reply($state);
    }

    private static function reply(array $state): Reply
    {
        return new Reply($state['status'], $state['location'], $state['type'], $state['content'], $state['truncated']);
    }
}
