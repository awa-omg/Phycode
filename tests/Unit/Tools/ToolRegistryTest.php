<?php

declare(strict_types=1);

namespace Phycode\Tests\Unit\Tools;

use Phycode\Agent\AgentRunner;
use Phycode\Agent\Conversation;
use Phycode\Agent\RunSetup;
use Phycode\Tests\Support\Kit;
use Phycode\Tests\Support\RecordingUi;
use Phycode\Tests\Support\ScriptedClient;
use Phycode\Tools\BashTool;
use Phycode\Tools\ReadTool;
use Phycode\Tools\ToolRegistry;
use PHPUnit\Framework\TestCase;

final class ToolRegistryTest extends TestCase
{
    private function registry(array $disabled): ToolRegistry
    {
        $registry = new ToolRegistry($disabled);
        $registry->register(new ReadTool());
        $registry->register(new BashTool());

        return $registry;
    }

    public function testDisabledToolsAreHiddenFromTheModel(): void
    {
        $registry = $this->registry(['bash']);
        $policy = Kit::policy('yolo');

        self::assertSame(['read'], $registry->available($policy));
        self::assertSame(['read'], array_column(array_map(
            static fn (array $spec): array => $spec['function'],
            $registry->specs($policy),
        ), 'name'));
        self::assertStringNotContainsString('bash', $registry->signatures($policy));
    }

    public function testWithoutAListEveryToolIsOffered(): void
    {
        self::assertSame(['read', 'bash'], $this->registry([])->available(Kit::policy('yolo')));
    }

    public function testACallToADisabledToolIsRefusedAsUnavailable(): void
    {
        $registry = $this->registry(['bash']);
        $ui = new RecordingUi();
        $context = Kit::context(Kit::folder('registry'), Kit::agent(), Kit::policy('yolo'), $ui);
        $conversation = new Conversation();
        $conversation->setSystem('system');
        $conversation->addUser('task');
        $client = new ScriptedClient([ScriptedClient::call('bash', ['command' => 'echo hi']), ScriptedClient::text('done')]);

        (new AgentRunner())->run($conversation, new RunSetup($client, 'native', $registry, $context, 5, false));

        self::assertSame([], $ui->startedTools(), 'the disabled tool never starts');
        self::assertStringStartsWith("ERROR: tool 'bash' is not available here. Available tools: read", $client->requests[1]['messages'][count($client->requests[1]['messages']) - 1]['content']);
    }
}
