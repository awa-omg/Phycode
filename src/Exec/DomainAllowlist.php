<?php

declare(strict_types=1);

namespace Phycode\Exec;

use Phycode\Support\PhycodeException;

final class DomainAllowlist
{
    private array $entries = [];

    public function __construct(array $entries)
    {
        foreach ($entries as $entry) {
            $this->entries[] = self::parse($entry);
        }
    }

    public function isEmpty(): bool
    {
        return $this->entries === [];
    }

    public function allows(string $host, int $port): bool
    {
        $host = strtolower(rtrim($host, '.'));
        foreach ($this->entries as $entry) {
            $portFits = $entry['port'] === null ? in_array($port, [80, 443], true) : $port === $entry['port'];
            if (!$portFits) {
                continue;
            }
            if ($entry['wildcard'] ? str_ends_with($host, '.' . $entry['pattern']) : $host === $entry['pattern']) {
                return true;
            }
        }

        return false;
    }

    private static function parse(string $entry): array
    {
        $text = strtolower(trim($entry));
        $port = null;
        if (preg_match('/^(.+):(\d{1,5})$/D', $text, $match) === 1) {
            $text = $match[1];
            $port = (int) $match[2];
            if ($port < 1 || $port > 65535) {
                throw new PhycodeException("sandbox.allow_domains: the port of '{$entry}' is not from 1 to 65535");
            }
        }
        $wildcard = str_starts_with($text, '*.');
        $name = $wildcard ? substr($text, 2) : $text;
        $label = '[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?';
        if (preg_match("/^{$label}(\\.{$label})+$/D", $name) !== 1 && $name !== 'localhost') {
            throw new PhycodeException("sandbox.allow_domains: '{$entry}' is not a host name (use github.com, or *.github.com for the hosts under it)");
        }
        if (filter_var($name, FILTER_VALIDATE_IP) !== false) {
            throw new PhycodeException("sandbox.allow_domains: '{$entry}' is an address; name the host instead, because the sandbox never reaches a machine by its address");
        }

        return ['pattern' => $name, 'wildcard' => $wildcard, 'port' => $port];
    }
}
