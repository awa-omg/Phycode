<?php

declare(strict_types=1);

namespace Phycode\Tests\Support;

use Phycode\Cli\ChatSession;
use Phycode\Cli\Kernel;
use Phycode\Llm\ClientProvider;
use Phycode\Session\Transcript;
use Phycode\Ui\Ui;

final class SessionHarness
{
    private const VARIABLES = ['PATH', 'HOME', 'XDG_CONFIG_HOME', 'XDG_CACHE_HOME', 'PHYCODE_PROFILE', 'PHYCODE_BASE_URL', 'PHYCODE_MODEL'];

    public readonly string $home;
    public readonly string $cwd;
    public readonly string $configFile;

    private array $saved = [];

    public function __construct(array $config)
    {
        foreach (self::VARIABLES as $name) {
            $this->saved[$name] = getenv($name);
        }
        $this->home = Kit::folder('session-home');
        $this->cwd = Kit::folder('session-project');
        $configHome = Kit::folder('session-config');
        $this->configFile = $configHome . '/phycode/config.json';
        Kit::write($this->configFile, json_encode($config, JSON_THROW_ON_ERROR));

        putenv('HOME=' . $this->home);
        putenv('XDG_CONFIG_HOME=' . $configHome);
        putenv('XDG_CACHE_HOME=' . Kit::folder('session-cache'));
        foreach (['PHYCODE_PROFILE', 'PHYCODE_BASE_URL', 'PHYCODE_MODEL'] as $name) {
            putenv($name);
        }
    }

    public function kernel(ClientProvider $providers, Ui $ui): Kernel
    {
        return new Kernel($this->cwd, false, $ui, null, $providers);
    }

    public function session(Kernel $kernel, string $profile = 'local'): ChatSession
    {
        return new ChatSession($kernel, $profile, Kit::agent(), Transcript::memory());
    }

    public function end(): void
    {
        foreach ($this->saved as $name => $value) {
            putenv($value === false ? $name : "{$name}={$value}");
        }
    }
}
