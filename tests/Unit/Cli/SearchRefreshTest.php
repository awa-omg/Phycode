<?php

declare(strict_types=1);

namespace Phycode\Tests\Unit\Cli;

use Phycode\Config\SearchProviderWriter;
use Phycode\Support\Vault;
use Phycode\Tests\Support\Kit;
use Phycode\Tests\Support\RecordingUi;
use Phycode\Tests\Support\RequestSpy;
use Phycode\Tests\Support\SessionHarness;
use PHPUnit\Framework\TestCase;

final class SearchRefreshTest extends TestCase
{
    private SessionHarness $harness;

    protected function setUp(): void
    {
        $this->harness = new SessionHarness([
            'default_profile' => 'local',
            'profiles' => ['local' => ['base_url' => 'http://127.0.0.1:9', 'model' => 'qwen', 'tool_mode' => 'text']],
            'web' => ['search' => ['active' => null, 'providers' => ['lan' => ['backend' => 'searxng', 'url' => 'http://127.0.0.1:9']]]],
        ]);
    }

    protected function tearDown(): void
    {
        $this->harness->end();
    }

    public function testAProviderChosenAfterTheSessionBeganIsOfferedAfterTheReload(): void
    {
        $kernel = $this->harness->kernel(new RequestSpy(['local' => 'qwen']), new RecordingUi());
        self::assertFalse($kernel->webOffered()['search'], 'no search in use at the start');
        self::assertFalse($kernel->tools->get('web_search')?->isAvailable(Kit::policy()));

        SearchProviderWriter::setActive($this->harness->configFile, 'lan');
        $kernel->reloadConfig();

        self::assertTrue($kernel->webOffered()['search']);
        self::assertTrue($kernel->tools->get('web_search')?->isAvailable(Kit::policy()));
        self::assertContains('web_search', $kernel->turnTools(true, false)->available(Kit::policy()), 'a turn that asks for the web gets the new search');
    }

    public function testTheSearchGoesBackOffWhenNoProviderIsInUse(): void
    {
        SearchProviderWriter::setActive($this->harness->configFile, 'lan');
        $kernel = $this->harness->kernel(new RequestSpy(['local' => 'qwen']), new RecordingUi());
        self::assertTrue($kernel->webOffered()['search']);

        SearchProviderWriter::setActive($this->harness->configFile, null);
        $kernel->reloadConfig();

        self::assertFalse($kernel->webOffered()['search']);
        self::assertTrue($kernel->webOffered()['fetch'], 'the web section still offers fetch');
    }

    public function testTheKeysOfTheProvidersAreSecretsSoMessagesHideThem(): void
    {
        $key = 'exa-secret-SESSION-2718';
        (new Vault(dirname($this->harness->configFile)))->storeSearchKey('exa-main', $key);
        Kit::write($this->harness->configFile, json_encode([
            'default_profile' => 'local',
            'profiles' => ['local' => ['base_url' => 'http://127.0.0.1:9', 'model' => 'qwen', 'tool_mode' => 'text']],
            'web' => ['search' => ['active' => 'exa-main', 'providers' => ['exa-main' => ['backend' => 'exa', 'api_key_store' => true]]]],
        ], JSON_THROW_ON_ERROR));

        $kernel = $this->harness->kernel(new RequestSpy(['local' => 'qwen']), new RecordingUi());

        self::assertContains($key, $kernel->secrets());
    }
}
