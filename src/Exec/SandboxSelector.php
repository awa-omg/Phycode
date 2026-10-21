<?php

declare(strict_types=1);

namespace Phycode\Exec;

use Phycode\Config\Sandbox;
use Phycode\Support\PhycodeException;

final class SandboxSelector
{
    private array $runners = [];

    public function __construct(
        private readonly Sandbox $settings,
        private readonly ?string $override,
        private readonly ProcessRunner $host,
        private readonly string $root,
    ) {
    }

    public function runnerFor(?string $request): CommandRunner
    {
        if ($this->override !== null) {
            $runtime = $this->override;
        } elseif ($request !== null && $request !== 'host') {
            $runtime = $request;
        } else {
            $runtime = $this->settings->default;
        }

        if ($runtime === 'host') {
            return $this->host;
        }

        return $this->runners[$runtime] ??= $this->container($runtime);
    }

    public function describe(): string
    {
        $runtime = $this->override ?? $this->settings->default;

        if ($runtime === 'bwrap') {
            return 'bwrap (' . ($this->settings->allowDomains !== [] ? 'network limited to ' . count($this->settings->allowDomains) . ' hosts' : ($this->settings->network === 'none' ? 'no network' : 'host network')) . ')';
        }

        return $runtime === 'host' ? 'host' : "{$runtime} ({$this->settings->image})";
    }

    private function container(string $runtime): CommandRunner
    {
        if ($runtime === 'bwrap') {
            return new BwrapRunner($this->host, $this->settings->network, $this->settings->allowDomains, $this->root);
        }
        if ($this->settings->image === null) {
            throw new PhycodeException("sandbox '{$runtime}' needs sandbox.image in config.json");
        }

        return new ContainerRunner($this->host, $runtime, $this->settings->image, $this->settings->network, $this->root);
    }
}
