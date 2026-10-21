<?php

declare(strict_types=1);

namespace Phycode\Tests\Unit\Llm;

use Phycode\Config\Profile;
use Phycode\Llm\CapabilityCache;
use Phycode\Tests\Support\Kit;
use PHPUnit\Framework\TestCase;

final class CapabilityCacheTest extends TestCase
{
    public function testTheCacheIsPrivateAndAnAnswerIsRemembered(): void
    {
        $file = Kit::folder('capability') . '/capabilities.json';
        $cache = new CapabilityCache($file);

        $cache->put('server|model', 'native', null);

        self::assertSame(['mode' => 'native', 'error' => null], $cache->get('server|model'));
        self::assertSame(0600, fileperms($file) & 0777);
    }

    public function testACacheThatIsALinkIsNeitherReadNorWrittenThrough(): void
    {
        $outside = Kit::folder('capability-outside') . '/target.json';
        $original = json_encode(['old' => ['mode' => 'text', 'error' => null]], JSON_THROW_ON_ERROR);
        Kit::write($outside, $original);
        $file = Kit::folder('capability') . '/capabilities.json';
        symlink($outside, $file);
        $cache = new CapabilityCache($file);

        self::assertNull($cache->get('old'), 'a link is not read');
        $cache->put('new', 'native', null);

        self::assertSame($original, file_get_contents($outside), 'the target of the link is not written');
        self::assertTrue(is_link($file));
    }

    public function testAProbeKeepsTheRouteOfTheEntryAndARouteKeepsItsMode(): void
    {
        $file = Kit::folder('capability') . '/capabilities.json';
        $cache = new CapabilityCache($file);

        $cache->merge('server|model', ['route' => 'responses']);
        self::assertNull($cache->get('server|model'), 'a route alone is not a probe result');
        $cache->put('server|model', 'native', null);

        self::assertSame(['mode' => 'native', 'error' => null], $cache->get('server|model'));
        self::assertSame('responses', $cache->route('server|model'), 'the probe keeps the route');
    }

    public function testAnEntryWithoutTheNewFieldsIsStillValid(): void
    {
        $file = Kit::folder('capability') . '/capabilities.json';
        Kit::write($file, json_encode(['old' => ['mode' => 'text', 'error' => 'refused', 'checked' => '2026-10-01T00:00:00+00:00']], JSON_THROW_ON_ERROR));
        $cache = new CapabilityCache($file);

        self::assertSame(['mode' => 'text', 'error' => 'refused'], $cache->get('old'));
        self::assertNull($cache->route('old'));
        self::assertNull($cache->capabilities('old'));
    }

    public function testOnlyTheResponsesRouteIsReadAndTheCapabilitiesComeBackAsTheyWereKept(): void
    {
        $file = Kit::folder('capability') . '/capabilities.json';
        $cache = new CapabilityCache($file);
        $cache->merge('chat', ['route' => 'chat']);
        $cache->merge('model', ['capabilities' => ['effort' => ['low', 'high'], 'thinking' => ['adaptive']]]);

        self::assertNull($cache->route('chat'));
        self::assertSame(['effort' => ['low', 'high'], 'thinking' => ['adaptive']], (new CapabilityCache($file))->capabilities('model'), 'the next process reads them');
        self::assertNull($cache->capabilities('chat'));
    }

    public function testTheLevelsOfAListingAreKeptByModelAndAnotherListingReplacesThem(): void
    {
        $file = Kit::folder('capability') . '/capabilities.json';
        $cache = new CapabilityCache($file);

        $cache->putLevels('server|list', ['a' => ['effort' => ['low', 'high'], 'mandatory' => false], 'b' => ['effort' => ['high'], 'mandatory' => true]]);

        self::assertSame(['effort' => ['low', 'high'], 'mandatory' => false], (new CapabilityCache($file))->levels('server|list', 'a'), 'the next process reads them');
        self::assertNull($cache->levels('server|list', 'c'), 'a model the listing does not name keeps nothing');
        $cache->merge('server|list', ['route' => 'responses']);
        self::assertSame(['effort' => ['high'], 'mandatory' => true], $cache->levels('server|list', 'b'), 'another field of the entry keeps the levels');
        $cache->putLevels('server|list', ['a' => ['effort' => ['max'], 'mandatory' => false]]);
        self::assertNull($cache->levels('server|list', 'b'), 'a new listing replaces the old one');
    }

    public function testTheKeyIsTheProtocolTheBaseUrlAndTheModel(): void
    {
        $profile = new Profile(name: 'a', baseUrl: 'https://api.example.test', model: 'm', toolMode: 'auto', contextLimit: 1, maxOutputTokens: 1, sampling: [], extraBody: [], apiKeyFile: null, apiKeyEnv: null, protocol: 'anthropic');

        self::assertSame(CapabilityCache::key($profile), CapabilityCache::key($profile->withThinking('high')), 'the thinking level is not part of the key');
        self::assertNotSame(CapabilityCache::key($profile), CapabilityCache::key($profile->withModel('other')));
        self::assertNotSame(CapabilityCache::key($profile), CapabilityCache::key($profile->withProtocol('openai')));
    }
}
