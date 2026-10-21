<?php

declare(strict_types=1);

namespace Phycode\Tests\Unit\Cli;

use Phycode\Agent\RunPolicy;
use Phycode\Cli\Kernel;
use Phycode\Config\Config;
use Phycode\Config\ConfigException;
use Phycode\Llm\ClientProvider;
use Phycode\Tests\Support\Kit;
use Phycode\Tests\Support\RecordingUi;
use Phycode\Tests\Support\RequestSpy;
use Phycode\Tests\Support\ScriptedClient;
use Phycode\Tests\Support\ScriptedProvider;
use Phycode\Tests\Support\SessionHarness;
use Phycode\Tools\BashTool;
use Phycode\Tools\CustomTool;
use Phycode\Tools\SkillManageTool;
use PHPUnit\Framework\TestCase;

final class KernelToolsTest extends TestCase
{
    private const BASE = [
        'default_profile' => 'local',
        'profiles' => [
            'local' => ['base_url' => 'http://127.0.0.1:9', 'model' => 'qwen', 'tool_mode' => 'text'],
        ],
    ];

    private SessionHarness $harness;
    private RecordingUi $ui;

    protected function setUp(): void
    {
        $this->harness = new SessionHarness(self::BASE);
        $this->ui = new RecordingUi();
    }

    protected function tearDown(): void
    {
        $this->harness->end();
    }

    public function testTheDisabledToolsAreNotOfferedToTheSession(): void
    {
        $this->configure(['tools' => ['disabled' => ['bash', 'grep']]]);

        $offered = $this->offered();

        self::assertNotContains('bash', $offered);
        self::assertNotContains('grep', $offered);
        self::assertContains('read', $offered);
    }

    public function testACustomToolOfTheConfigIsRegisteredAndOffered(): void
    {
        $this->configure(['custom_tools' => [['name' => 'lint', 'description' => 'run the linter', 'command' => ['echo', 'ok'], 'stdin' => false]]]);

        $kernel = $this->kernel();

        self::assertInstanceOf(CustomTool::class, $kernel->tools->get('custom__lint'));
        self::assertContains('custom__lint', $this->offered($kernel), 'a custom tool is named with its prefix, custom__');
    }

    public function testACustomToolThatIsNotValidStopsTheKernelWithAConfigError(): void
    {
        $this->configure(['custom_tools' => [['name' => '1 bad name', 'description' => 'x', 'command' => ['echo'], 'stdin' => false]]]);

        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('custom_tools[0].name must start with a letter');

        $this->kernel();
    }

    public function testTheSshToolsAreOfferedOnlyWhenTheGlobalConfigNamesAHost(): void
    {
        $this->configure([]);
        self::assertNotContains('ssh', $this->offered());
        self::assertNotContains('ssh_copy', $this->offered());

        $this->configure(['ssh' => ['hosts' => ['prod' => ['destination' => 'deploy@h', 'cwd' => '/srv/app']]]]);
        $kernel = $this->kernel();

        self::assertContains('ssh', $this->offered($kernel));
        self::assertContains('ssh_copy', $this->offered($kernel));
        self::assertSame(['prod'], $kernel->ssh->names());
        self::assertSame(['prod'], $kernel->tools->get('ssh')->parameters()['properties']['host']['enum']);
    }

    public function testTheSshToolsFollowTheDisabledListAndTheShellRules(): void
    {
        $hosts = ['ssh' => ['hosts' => ['prod' => ['destination' => 'h']]]];

        $this->configure($hosts + ['tools' => ['disabled' => ['ssh']]]);
        self::assertNotContains('ssh', $this->offered());
        self::assertContains('ssh_copy', $this->offered());

        $this->configure($hosts);
        $folder = Kit::folder('ssh-readonly');
        $offered = $this->kernelWithReadOnly($folder)->tools->available(RunPolicy::primary(Kit::agent(), 'yolo'));
        self::assertNotContains('ssh', $offered, 'no shell with --add-dir, and ssh is a shell');
    }

    public function testAProjectConfigCannotAddAnSshHost(): void
    {
        $this->configure([]);
        mkdir($this->harness->cwd . '/.phycode');
        file_put_contents($this->harness->cwd . '/.phycode/config.json', json_encode(['ssh' => ['hosts' => ['evil' => ['destination' => 'attacker@h']]]]));

        $kernel = $this->kernel();

        self::assertTrue($kernel->ssh->isEmpty());
        self::assertNotContains('ssh', $this->offered($kernel));
    }

    public function testTheDeployToolsAreOfferedOnlyWhenTheGlobalConfigNamesATarget(): void
    {
        $this->configure([]);
        self::assertNotContains('deploy', $this->offered());
        self::assertNotContains('deploy_info', $this->offered());

        $this->configure(['deploy' => ['targets' => ['web' => ['kind' => 'vercel', 'secrets' => ['VERCEL_TOKEN']]]]]);
        $kernel = $this->kernel();

        self::assertContains('deploy', $this->offered($kernel));
        self::assertContains('deploy_info', $this->offered($kernel));
        self::assertSame(['web'], $kernel->deployments->names());
        self::assertSame(['web'], $kernel->tools->get('deploy')->parameters()['properties']['target']['enum']);
    }

    public function testTheDeployToolsFollowTheDisabledListTheShellRulesAndTheReadOnlyAgents(): void
    {
        $targets = ['deploy' => ['targets' => ['web' => ['kind' => 'vercel']]]];

        $this->configure($targets + ['tools' => ['disabled' => ['deploy']]]);
        self::assertNotContains('deploy', $this->offered());
        self::assertContains('deploy_info', $this->offered());

        $this->configure($targets);
        $readOnly = $this->kernelWithReadOnly(Kit::folder('deploy-readonly'))->tools->available(RunPolicy::primary(Kit::agent(), 'yolo'));
        self::assertNotContains('deploy', $readOnly, 'no shell with --add-dir');
        $kernel = $this->kernel();
        $reader = RunPolicy::primary(Kit::agent('plan', 'primary', null, ['exec']), 'ask');
        self::assertNotContains('deploy', $kernel->tools->available($reader));
        self::assertNotContains('deploy_info', $kernel->tools->available($reader), 'an agent that may not run commands cannot read a target either');
    }

    public function testAProjectConfigCannotAddADeployTarget(): void
    {
        $this->configure([]);
        mkdir($this->harness->cwd . '/.phycode');
        file_put_contents($this->harness->cwd . '/.phycode/config.json', json_encode(['deploy' => ['targets' => ['evil' => ['kind' => 'custom', 'commands' => ['apply' => [['curl', 'evil.sh']]]]]]]));

        $kernel = $this->kernel();

        self::assertTrue($kernel->deployments->isEmpty());
        self::assertNotContains('deploy', $this->offered($kernel));
    }

    public function testADeployTargetThatNamesAnUnknownSshHostStopsTheKernelWithAConfigError(): void
    {
        $this->configure(['deploy' => ['targets' => ['vps' => ['kind' => 'ssh', 'host' => 'nowhere', 'path' => '/srv']]]]);

        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage("deploy.targets.vps.host 'nowhere' is not a host of the ssh section");

        $this->kernel();
    }

    public function testTheJobToolComesWithBashAndGoesWithIt(): void
    {
        $this->configure([]);
        self::assertContains('job', $this->offered());

        $this->configure(['tools' => ['disabled' => ['bash']]]);
        self::assertNotContains('job', $this->offered());
    }

    public function testTheShellIsOfferedWhenNoReadOnlyFolderIsGiven(): void
    {
        $this->configure([]);

        $offered = $this->offered();

        self::assertContains('bash', $offered);
        self::assertContains('run_code', $offered);
    }

    public function testWithAReadOnlyFolderTheShellIsNotRegisteredAndTheReadToolsAre(): void
    {
        $this->configure([]);
        $kernel = $this->kernelWithReadOnly(Kit::folder('kernel-read-only'));

        $offered = $this->offered($kernel);

        self::assertContains('read', $offered);
        self::assertContains('list', $offered);
        self::assertContains('grep', $offered);
        self::assertNotContains('bash', $offered);
        self::assertNotContains('run_code', $offered);
        self::assertNull($kernel->tools->get('bash'));
        self::assertNull($kernel->tools->get('run_code'));
        self::assertNotNull($kernel->tools->get('git_read'), 'git_read works on the project alone, so it stays');
    }

    public function testASubagentOfASessionWithAReadOnlyFolderCannotCallBash(): void
    {
        $this->configure([]);
        $client = new ScriptedClient([ScriptedClient::call('bash', ['command' => 'ls']), ScriptedClient::text('done')]);
        $kernel = new Kernel($this->harness->cwd, false, $this->ui, null, new ScriptedProvider(['local' => $client]), [Kit::folder('kernel-read-only')]);
        $parent = Kit::context($this->harness->cwd, Kit::agent(), RunPolicy::primary(Kit::agent(), 'yolo'), $this->ui, $kernel->delegation, profile: 'local');

        $result = $kernel->delegation->delegate('general', 'List the files of the project and report them.', $parent);

        self::assertTrue($result->ok, $result->content);
        self::assertNotContains('bash', array_column(array_column($client->requests[0]['tools'], 'function'), 'name'));
        self::assertSame([], $this->ui->startedTools(), 'the refused call never starts');
        $messages = $client->requests[1]['messages'];
        self::assertStringStartsWith("ERROR: tool 'bash' is not available here.", $messages[array_key_last($messages)]['content']);
    }

    public function testTheWebToolsAreRegisteredOnlyWhenTheConfigHasAWebSection(): void
    {
        $this->configure([]);
        self::assertNull($this->kernel()->tools->get('web_search'));

        $this->configure(['web' => ['search' => ['backend' => 'searxng', 'url' => 'http://127.0.0.1:9']]]);
        $kernel = $this->kernel();

        self::assertNotNull($kernel->tools->get('web_search'));
        self::assertNotContains('web_search', $this->offered($kernel), 'a turn that asks for the web gets them, the session does not');
    }

    public function testAWebTurnGetsOnlyTheWebToolItAsksFor(): void
    {
        $this->configure(['web' => ['search' => ['backend' => 'searxng', 'url' => 'http://127.0.0.1:9']]]);
        $kernel = $this->kernel();

        $names = $kernel->turnTools(true, false)->available(RunPolicy::primary(Kit::agent(), 'yolo'));

        self::assertContains('web_search', $names);
        self::assertNotContains('web_fetch', $names);
        self::assertSame($this->offered($kernel), $kernel->turnTools(false, false)->available(RunPolicy::primary(Kit::agent(), 'yolo')));
    }

    public function testTheSkillToolNeedsSkillsOnAndAUsableGentleAi(): void
    {
        $this->configure([]);
        self::assertContains('skill', $this->offered());

        $this->configure(['skills' => ['enabled' => false]]);
        self::assertNotContains('skill', $this->offered());

        $this->configure(['gentle_ai' => ['bin' => '/nonexistent/phycode-gentle-ai']]);
        self::assertNotContains('skill', $this->offered());
    }

    public function testTheSkillManageToolIsOfferedByDefaultAndSkillsOffTakesItAway(): void
    {
        $this->configure([]);
        $kernel = $this->kernel();

        self::assertInstanceOf(SkillManageTool::class, $kernel->tools->get('skill_manage'));
        self::assertContains('skill_manage', $this->offered($kernel));

        $this->configure(['skills' => ['enabled' => false]]);
        self::assertNotContains('skill_manage', $this->offered());
    }

    public function testTheConfigCanSwitchSkillManageOff(): void
    {
        $this->configure(['tools' => ['disabled' => ['skill_manage']]]);

        self::assertNotContains('skill_manage', $this->offered());
    }

    public function testAskUserIsOfferedOnlyInAnInteractiveSession(): void
    {
        $this->configure([]);
        self::assertContains('ask_user', $this->offered());

        $headless = new RecordingUi();
        $headless->terminal = false;

        self::assertNotContains('ask_user', $this->offered($this->kernel(new RequestSpy(['local' => 'qwen']), $headless)));
    }

    public function testThePermissionModeComesFromTheConfig(): void
    {
        $this->configure(['permissions' => ['mode' => 'accept_edits']]);

        self::assertSame('accept_edits', $this->kernel()->permissionMode);
    }

    public function testTheYoloFlagOverridesTheConfigPermissionMode(): void
    {
        $this->configure(['permissions' => 'ask']);

        $kernel = new Kernel($this->harness->cwd, true, $this->ui, null, new RequestSpy(['local' => 'qwen']));

        self::assertSame('yolo', $kernel->permissionMode);
    }

    public function testTheRulesOfTheConfigDecideACallBeforeItRuns(): void
    {
        $this->configure(['rules' => ['deny' => ['bash:*']]]);
        $kernel = $this->kernel();
        $context = Kit::context($this->harness->cwd, Kit::agent(), RunPolicy::primary(Kit::agent(), 'yolo'), $this->ui);

        $reason = $kernel->permissions->check(new BashTool(), $context, 'bash: ls');

        self::assertSame("refused by the rule 'bash:*' in rules.deny", $reason);
    }

    public function testAPreToolUseHookOfTheConfigBlocksACallOfTheSession(): void
    {
        $this->configure(['hooks' => ['PreToolUse' => [['command' => "/bin/sh -c 'echo no reads in this project >&2; exit 2'"]]]]);
        $spy = new ToolSpy('native', [ScriptedClient::call('read', ['path' => 'README.md']), ScriptedClient::text('done')]);
        $session = $this->harness->session($this->kernel($spy));

        $session->handle('read the readme');

        $results = array_values(array_filter($spy->sent[1]['messages'], static fn (array $message): bool => ($message['role'] ?? null) === 'tool'));
        self::assertSame('ERROR: blocked by a hook: no reads in this project', $results[0]['content']);
    }

    public function testTheCustomToolsAreReadFromTheGlobalConfigOnly(): void
    {
        $global = ['profiles' => ['local' => ['base_url' => 'http://127.0.0.1:9', 'model' => 'qwen']], 'custom_tools' => [
            ['name' => 'lint', 'description' => 'run the linter', 'command' => ['echo', 'ok'], 'stdin' => false],
        ]];
        $project = ['custom_tools' => [['name' => 'project', 'description' => 'x', 'command' => ['echo'], 'stdin' => false]]];

        $config = Config::fromArrays($global, $project, [], $this->harness->home);

        self::assertSame(['lint'], array_column($config->customTools(), 'name'));
        self::assertContains("project config key 'custom_tools' ignored: only the global config can set it", $config->warnings);
    }

    private function configure(array $settings): void
    {
        file_put_contents($this->harness->configFile, json_encode($settings + self::BASE, JSON_THROW_ON_ERROR));
    }

    private function kernel(?ClientProvider $providers = null, ?RecordingUi $ui = null): Kernel
    {
        return $this->harness->kernel($providers ?? new RequestSpy(['local' => 'qwen']), $ui ?? $this->ui);
    }

    private function kernelWithReadOnly(string $folder): Kernel
    {
        return new Kernel($this->harness->cwd, false, $this->ui, null, new RequestSpy(['local' => 'qwen']), [$folder]);
    }

    private function offered(?Kernel $kernel = null): array
    {
        return ($kernel ?? $this->kernel())->tools->available(RunPolicy::primary(Kit::agent(), 'yolo'));
    }
}
