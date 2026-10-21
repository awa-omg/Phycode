<?php

declare(strict_types=1);

namespace Phycode\Tests\Unit\Security;

use Phycode\Security\SecretFiles;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SecretFilesTest extends TestCase
{
    public static function secrets(): iterable
    {
        foreach (['.env', 'app/.env', '.env.local', '.env.production', '.env.test', '.ENV', 'server.pem', 'keys/private.key', 'cert.p12', 'cert.pfx', 'release.keystore', 'a.jks', 'putty.ppk',
            'id_rsa', '.ssh/id_ed25519', 'id_ecdsa', '.netrc', '.npmrc', '.pypirc', '.git-credentials', '.htpasswd', '.pgpass', 'credentials', 'aws/credentials.json', 'gcp-credentials.json',
            'service-account.json', 'service-account-prod.json', 'secrets.json', 'config/secrets.yml', 'terraform.tfstate', '.git/config', 'sub/.git/config', './.env', 'win\\.env'] as $path) {
            yield $path => [$path];
        }
    }

    #[DataProvider('secrets')]
    public function testFilesThatUsuallyHoldSecretsAreRecognised(string $path): void
    {
        self::assertTrue(SecretFiles::matches($path), $path);
    }

    public static function ordinary(): iterable
    {
        foreach (['.env.example', '.env.sample', '.env.template', '.env.dist', '.env.defaults', 'config/.ENV.EXAMPLE', 'README.md', 'src/env.php', 'environment.ts', 'envfile', 'id_rsa.pub', 'key.txt', 'keyboard.php',
            'monkey.php', 'config', '.git/HEAD', 'src/config', 'credentials.md', 'secrets.md', 'cert.crt', 'public.pem.txt', '', '.', '..', 'service.json', 'composer.json'] as $path) {
            yield $path === '' ? '(empty)' : $path => [$path];
        }
    }

    #[DataProvider('ordinary')]
    public function testOrdinaryFilesAndTemplatesAreNot(string $path): void
    {
        self::assertFalse(SecretFiles::matches($path), $path);
    }

    public function testASearchDropsTheLinesOfSecretFilesAndCountsThem(): void
    {
        $lines = [
            'src/a.php:3: $key = env("KEY");',
            '.env:1: KEY=abc123',
            'app/.env.local:9: KEY=def456',
            '.env.example:1: KEY=',
            'docs/a.md:2: set KEY in .env',
            'keys/server.pem:4: MIIB',
        ];

        [$kept, $dropped] = SecretFiles::withoutSecrets($lines);

        self::assertSame(['src/a.php:3: $key = env("KEY");', '.env.example:1: KEY=', 'docs/a.md:2: set KEY in .env'], $kept);
        self::assertSame(3, $dropped);
        self::assertSame([[], 0], SecretFiles::withoutSecrets([]));
        self::assertSame([['plain line'], 0], SecretFiles::withoutSecrets(['plain line']));
    }
}
