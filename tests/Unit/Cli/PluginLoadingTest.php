<?php

declare(strict_types=1);

namespace Phycode\Tests\Unit\Cli;

use Phycode\Tests\Support\Kit;
use Phycode\Tests\Support\RecordingUi;
use Phycode\Tests\Support\ScriptedClient;
use Phycode\Tests\Support\SessionHarness;
use PHPUnit\Framework\TestCase;

final class PluginLoadingTest extends TestCase
{
    private SessionHarness $harness;

    protected function setUp(): void
    {
        $this->harness = new SessionHarness(['default_profile' => 'local', 'profiles' => ['local' => ['base_url' => 'http://127.0.0.1:9', 'model' => 'qwen', 'tool_mode' => 'native']]]);
        $plugin = dirname($this->harness->configFile) . '/plugins/review-kit';
        Kit::write($plugin . '/agents/auditor.md', "---\nname: auditor\ndescription: Audits the change\nmode: subagent\ntools: [read, grep]\n---\nAudit.\n");
        Kit::write($plugin . '/skills/triage/SKILL.md', "---\nname: triage\ndescription: Triage a bug report\n---\nSteps.\n");
        Kit::write($plugin . '/prompts/summarize.md', "Summarize \$ARGUMENTS in one line.\n");
    }

    protected function tearDown(): void
    {
        $this->harness->end();
    }

    public function testTheAgentOfAPluginIsListedWithItsSource(): void
    {
        $kernel = $this->harness->kernel(new CountingProvider(['local' => 'qwen'], ['native']), new RecordingUi());

        $agent = $kernel->agents->get('auditor');

        self::assertNotNull($agent);
        self::assertSame('plugin:review-kit', $agent->source);
        self::assertMatchesRegularExpression('/^auditor\s+subagent\s+plugin:review-kit\s+tools: read, grep/m', $kernel->agentListing());
        self::assertContains('auditor', array_keys($kernel->agents->subagents()));
    }

    public function testTheSkillOfAPluginIsInTheIndexOfTheSystemPrompt(): void
    {
        $kernel = $this->harness->kernel(new CountingProvider(['local' => 'qwen'], ['native']), new RecordingUi());

        $prompt = $kernel->systemPrompt(Kit::agent(), 'native', Kit::policy());

        self::assertStringContainsString('triage', $prompt);
        self::assertStringContainsString('Triage a bug report', $prompt);
    }

    public function testThePromptTemplateOfAPluginIsACommandThatExpands(): void
    {
        $provider = new CountingProvider(['local' => 'qwen'], ['native'], replies: [ScriptedClient::text('Short.')]);
        $session = $this->harness->session($this->harness->kernel($provider, new RecordingUi()));

        $session->handle('/summarize the release notes');

        self::assertSame('Summarize the release notes in one line.', $provider->sent[0]['messages'][1]['content']);
    }

    public function testAProjectTemplateWinsOverAPluginOneOfTheSameName(): void
    {
        Kit::write($this->harness->cwd . '/.phycode/prompts/summarize.md', "Project summary of \$ARGUMENTS.\n");
        $provider = new CountingProvider(['local' => 'qwen'], ['native'], replies: [ScriptedClient::text('ok')]);
        $ui = new RecordingUi();
        $session = $this->harness->session($this->harness->kernel($provider, $ui));

        $session->handle('/summarize x');

        self::assertSame('Project summary of x.', $provider->sent[0]['messages'][1]['content']);
        $warnings = array_values(array_map(static fn (array $event): string => $event[2], array_filter($ui->events, static fn (array $event): bool => $event[0] === 'notice' && $event[1] === 'warn')));
        self::assertContains('the prompt template summarize.md is not used: a template of that name was loaded before it, from .phycode/prompts', $warnings);
    }
}
