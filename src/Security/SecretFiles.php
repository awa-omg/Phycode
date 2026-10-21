<?php

declare(strict_types=1);

namespace Phycode\Security;

final class SecretFiles
{
    private const TEMPLATES = ['example', 'sample', 'template', 'dist', 'defaults', 'schema'];
    private const NAMES = [
        '.netrc', '_netrc', '.npmrc', '.yarnrc.yml', '.pypirc', '.git-credentials', '.htpasswd', '.pgpass', '.my.cnf', '.dockercfg',
        '.envrc', '.s3cfg', '.vault-token', 'credentials', 'credentials.json', 'secrets.json', 'secrets.yaml', 'secrets.yml',
        'secrets.toml', 'application_default_credentials.json', 'terraform.tfstate', 'terraform.tfstate.backup', 'id_rsa', 'id_dsa',
        'id_ecdsa', 'id_ed25519',
    ];
    private const EXTENSIONS = ['pem', 'key', 'p12', 'pfx', 'jks', 'keystore', 'ppk', 'asc', 'tfvars'];
    private const IN_FOLDER = ['.git' => ['config'], '.kube' => ['config'], '.docker' => ['config.json'], 'phycode' => ['keys.json']];
    private const RUNTIME = '#(^|/)\.phycode/(checkpoints|trash|sessions|tmp)(/|$)#i';

    public static function matches(string $path): bool
    {
        $path = str_replace('\\', '/', trim($path));
        $name = strtolower(basename($path));
        if ($name === '' || $name === '.' || $name === '..') {
            return false;
        }
        if (in_array($name, self::IN_FOLDER[strtolower(basename(dirname($path)))] ?? [], true)) {
            return true;
        }
        if ($name === '.env' || str_starts_with($name, '.env.') || str_ends_with($name, '.env')) {
            return !in_array(substr($name, strrpos($name, '.') + 1), self::TEMPLATES, true);
        }
        if (in_array($name, self::NAMES, true) || str_starts_with($name, 'service-account') && str_ends_with($name, '.json') || str_ends_with($name, '-credentials.json')) {
            return true;
        }
        $dot = strrpos($name, '.');

        return $dot !== false && in_array(substr($name, $dot + 1), self::EXTENSIONS, true);
    }

    public static function runtimeState(string $path): bool
    {
        return preg_match(self::RUNTIME, self::normalize($path)) === 1;
    }

    public static function guarded(string $path): bool
    {
        return self::matches($path) || self::runtimeState($path);
    }

    public static function withoutSecrets(array $lines): array
    {
        $kept = array_values(array_filter($lines, static function (string $line): bool {
            preg_match_all('/:\d+:/', $line, $markers, PREG_OFFSET_CAPTURE);
            foreach ($markers[0] ?? [] as [, $offset]) {
                if ($offset > 0 && self::matches(substr($line, 0, $offset))) {
                    return false;
                }
            }

            return true;
        }));

        return [$kept, count($lines) - count($kept)];
    }

    private static function normalize(string $path): string
    {
        $parts = [];
        foreach (explode('/', str_replace('\\', '/', trim($path))) as $part) {
            if ($part === '' || $part === '.') {
                continue;
            }
            if ($part === '..') {
                array_pop($parts);
                continue;
            }
            $parts[] = $part;
        }

        return implode('/', $parts);
    }
}
