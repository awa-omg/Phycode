<?php

declare(strict_types=1);

namespace Phycode\Tests\Unit\Mcp;

use Phycode\Mcp\McpPrompts;
use Phycode\Mcp\McpServers;
use Phycode\Support\PhycodeException;
use Phycode\Support\Vault;
use Phycode\Tests\Support\Kit;
use Phycode\Tools\Tool;
use PHPUnit\Framework\TestCase;

final class McpServersTest extends TestCase
{
    private ?FakeMcpHttp $http = null;
    private string $cwd;

    protected function setUp(): void
    {
        $this->cwd = Kit::folder('mcp-servers-cwd');
    }

    protected function tearDown(): void
    {
        $this->http?->stop();
        putenv('PHYCODE_TEST_MCP_TOKEN');
    }

    private function stdio(string $scenario = '', array $extra = []): array
    {
        return $extra + ['command' => FakeMcpServer::argv($scenario), 'enabled' => true, 'approval' => 'ask'];
    }

    private function servers(array $servers, string $search = 'auto'): McpServers
    {
        return new McpServers($servers, $this->cwd, new Vault(Kit::folder('mcp-servers-vault')), $search, 5.0);
    }

    private static function names(array $tools): array
    {
        return array_map(static fn (Tool $tool): string => $tool->name(), $tools);
    }

    public function testEveryServerThatStartsGivesItsToolsAndOneThatFailsIsLeftOutWithTheReason(): void
    {
        $servers = $this->servers([
            'legacy' => $this->stdio(),
            'modern' => $this->stdio('modern-full'),
            'missing' => ['command' => ['/nonexistent/phycode-mcp'], 'enabled' => true, 'approval' => 'ask'],
            'off' => ['command' => ['/nonexistent/phycode-mcp'], 'enabled' => false, 'approval' => 'ask'],
        ]);

        $names = self::names($servers->tools());

        self::assertSame(['mcp__legacy__echo', 'mcp__modern__echo', 'mcp_resource'], $names);
        self::assertSame(['missing'], array_keys($servers->problems()));
        self::assertStringContainsString('cannot start /nonexistent/phycode-mcp', $servers->problems()['missing']);
        self::assertFalse($servers->searching());
        $servers->close();
    }

    public function testTheListOfServersWithNoneEnabledIsEmpty(): void
    {
        self::assertTrue($this->servers([])->isEmpty());
        self::assertTrue($this->servers(['off' => $this->stdio(extra: ['enabled' => false])])->isEmpty());
        self::assertFalse($this->servers(['on' => $this->stdio()])->isEmpty());
    }

    public function testASessionAsksUnlessTheServerHasApprovalAllow(): void
    {
        $servers = $this->servers([
            'ask' => $this->stdio(),
            'trusted' => $this->stdio('modern-full', ['approval' => 'allow']),
        ]);
        $tools = array_column(array_map(static fn (Tool $tool): array => [$tool->name(), $tool], $servers->tools()), 1, 0);

        self::assertFalse($tools['mcp__ask__echo']->autoApproved());
        self::assertTrue($tools['mcp__trusted__echo']->autoApproved());
        self::assertTrue($tools['mcp_resource']->autoApproved(), 'the only server with resources is trusted');
        $servers->close();

        $both = $this->servers(['trusted' => $this->stdio('modern-full', ['approval' => 'allow']), 'ask' => $this->stdio('legacy-full')]);
        $resource = array_values(array_filter($both->tools(), static fn (Tool $tool): bool => $tool->name() === 'mcp_resource'))[0];
        self::assertFalse($resource->autoApproved(), 'one server with resources asks');
        $both->close();
    }

    public function testTheToolsOfAServerAtAnAddressWorkWithATokenFromTheEnvironment(): void
    {
        putenv('PHYCODE_TEST_MCP_TOKEN=remote-token-12345');
        $this->http = new FakeMcpHttp(['token' => 'remote-token-12345']);
        $servers = $this->servers(['remote' => ['command' => [], 'url' => $this->http->url(), 'headers' => ['X-Team' => 'a'], 'token_env' => 'PHYCODE_TEST_MCP_TOKEN', 'oauth' => null, 'enabled' => true, 'approval' => 'ask']]);

        self::assertSame(['mcp__remote__echo'], self::names($servers->tools()));
        self::assertSame([], $servers->problems());
        $servers->close();
    }

    public function testAServerThatWantsALoginThatWasNotDoneIsLeftOutWithWhatToDo(): void
    {
        $this->http = new FakeMcpHttp(['oauth' => true]);
        $servers = $this->servers(['remote' => ['command' => [], 'url' => $this->http->url(), 'headers' => [], 'token_env' => null, 'oauth' => ['client_id' => null, 'client_secret_env' => null, 'scopes' => [], 'callback_port' => 0], 'enabled' => true, 'approval' => 'ask']]);

        self::assertSame([], $servers->tools());
        self::assertSame('the MCP server remote needs you to sign in: run phycode mcp login remote', $servers->problems()['remote']);
    }

    public function testWhatServersSayAboutThemselvesIsABlockForThePrompt(): void
    {
        $servers = $this->servers(['docs' => $this->stdio('modern-full'), 'plain' => $this->stdio('modern')]);

        $block = $servers->instructions();

        self::assertSame("Instructions from the MCP servers that are connected:\n\nMCP server docs:\nUse echo for tests.", $block);
        self::assertNull($this->servers(['plain' => $this->stdio('modern')])->instructions());
        $servers->close();
    }

    public function testManyToolsAreFoundWithASearchInsteadOfListedOneByOne(): void
    {
        $definitions = [];
        for ($n = 1; $n <= 25; $n++) {
            $definitions[] = ['name' => "tool_{$n}", 'description' => $n === 7 ? 'Creates a GitHub issue' : "Does thing {$n}", 'inputSchema' => ['type' => 'object', 'properties' => ['title' => ['type' => 'string']], 'required' => ['title']]];
        }
        $dir = Kit::folder('mcp-many');
        file_put_contents($dir . '/many.json', json_encode(['tools' => $definitions]));
        $servers = $this->servers(['big' => ['command' => [PHP_BINARY, dirname(__DIR__, 2) . '/Support/fake-mcp.php', $dir . '/many.json'], 'enabled' => true, 'approval' => 'ask']]);

        $tools = $servers->tools();

        self::assertTrue($servers->searching());
        self::assertSame(['mcp_search', 'mcp_call'], self::names($tools));
        $context = Kit::context($this->cwd, Kit::agent(), Kit::policy('yolo'), new \Phycode\Tests\Support\RecordingUi());

        $found = $tools[0]->execute(['query' => 'github issue'], $context);
        self::assertTrue($found->ok);
        self::assertStringStartsWith('mcp__big__tool_7 - Creates a GitHub issue', $found->content);
        self::assertStringContainsString('"required":["title"]', $found->content);
        self::assertStringContainsString('1 of 26 tools shown', $found->content, '25 tools and echo');

        self::assertSame('no tool matches; there are 26 tools in all, and an empty query lists them', $tools[0]->execute(['query' => 'zzzz'], $context)->content);
        self::assertSame(3, substr_count($tools[0]->execute(['query' => '', 'limit' => 3], $context)->content, ' - '));

        $call = $tools[1];
        self::assertSame('mcp__big__tool_7 title=Fix', $call->summarize(['tool' => 'mcp__big__tool_7', 'arguments' => ['title' => 'Fix']]));
        $call->validate(['tool' => 'mcp__big__tool_7', 'arguments' => ['title' => 'Fix']], $context);
        self::assertTrue($call->execute(['tool' => 'mcp__big__tool_7', 'arguments' => ['title' => 'Fix']], $context)->ok);
        foreach ([[['tool' => 'mcp__big__nothing'], 'there is no MCP tool with that name'], [['tool' => 'mcp__big__tool_7', 'arguments' => []], "the property 'title' is required"], [['tool' => 'mcp__big__tool_7', 'arguments' => 'x'], 'arguments must be an object']] as [$args, $message]) {
            try {
                $call->validate($args, $context);
                self::fail($message);
            } catch (PhycodeException $error) {
                self::assertStringContainsString($message, $error->getMessage());
            }
        }
        $servers->close();
    }

    public function testToolSearchCanBeForcedOnOrOff(): void
    {
        $on = $this->servers(['one' => $this->stdio()], 'on');
        self::assertSame(['mcp_search', 'mcp_call'], self::names($on->tools()));
        $on->close();

        $off = $this->servers(['one' => $this->stdio()], 'off');
        self::assertSame(['mcp__one__echo'], self::names($off->tools()));
        $off->close();
    }

    public function testTheResourceToolListsAndReadsAndExplainsWhatIsWrong(): void
    {
        $servers = $this->servers(['docs' => $this->stdio('modern-full')]);
        $resource = array_values(array_filter($servers->tools(), static fn (Tool $tool): bool => $tool->name() === 'mcp_resource'))[0];
        $context = Kit::context($this->cwd, Kit::agent(), Kit::policy('yolo'), new \Phycode\Tests\Support\RecordingUi());

        $list = $resource->execute(['action' => 'list'], $context);
        self::assertSame(
            "docs  file:///project/README.md  README.md - The readme (text/markdown)\ndocs  file:///project/logo.png  logo.png (image/png)\ndocs  file:///logs/{day}.log  template: Logs - Logs by day",
            $list->content,
        );
        self::assertSame("# Hello\n", $resource->execute(['action' => 'read', 'server' => 'docs', 'uri' => 'file:///project/README.md'], $context)->content);
        self::assertSame('[binary image/png, about 8 bytes, not shown]', $resource->execute(['action' => 'read', 'server' => 'docs', 'uri' => 'file:///project/logo.png'], $context)->content);
        $absent = $resource->execute(['action' => 'read', 'server' => 'docs', 'uri' => 'file:///nothing'], $context);
        self::assertFalse($absent->ok);
        self::assertStringContainsString('Resource not found', $absent->content);

        foreach ([[['action' => 'dance'], 'action must be list or read'], [['action' => 'list', 'server' => 'nope'], 'unknown MCP server'], [['action' => 'read', 'server' => 'docs'], 'read needs the server and the uri']] as [$args, $message]) {
            try {
                $resource->validate($args, $context);
                self::fail($message);
            } catch (PhycodeException $error) {
                self::assertStringContainsString($message, $error->getMessage());
            }
        }
        $servers->close();
    }

    public function testThePromptsOfServersAreCommandsWithArgumentsByNameOrByPlace(): void
    {
        $servers = $this->servers(['docs' => $this->stdio('modern-full')]);
        $prompts = $servers->prompts();

        self::assertSame([
            ['name' => '/mcp__docs__review CODE [FOCUS]', 'description' => 'Review code'],
            ['name' => '/mcp__docs__hello', 'description' => 'prompt of the MCP server docs'],
        ], $prompts->commands());
        self::assertSame('/mcp__docs__review', $prompts->match('/mcp__docs__review code=x'));
        self::assertNull($prompts->match('/mcp__docs__reviewer x'));
        self::assertNull($prompts->match('review'));

        self::assertSame("Please review: echo 1\n\n[the assistant said]\nFocus: style", $prompts->render('/mcp__docs__review code="echo 1" focus=style'));
        self::assertSame("Please review: if\n\n[the assistant said]\nFocus: x then y", $prompts->render('/mcp__docs__review if x then y'), 'the words that are left go to the last argument');
        self::assertSame('Please review: a' . "\n\n" . '[the assistant said]' . "\n" . 'Focus: b c', $prompts->render('/mcp__docs__review a b c'));
        self::assertSame('Hello there', $prompts->render('/mcp__docs__hello'));

        try {
            $prompts->render('/mcp__docs__review focus=style');
            self::fail('no error');
        } catch (PhycodeException $error) {
            self::assertSame('/mcp__docs__review needs the argument code: /mcp__docs__review code=VALUE', $error->getMessage());
        }
        $servers->close();
    }

    public function testAPromptCannotTakeTheWordOfACommandThatExists(): void
    {
        $servers = new McpServers(['docs' => $this->stdio('modern-full')], $this->cwd, new Vault(Kit::folder('mcp-servers-vault')), 'auto', 5.0, '0.1.0', ['/mcp__docs__hello']);

        self::assertSame(['/mcp__docs__review CODE [FOCUS]'], array_column($servers->prompts()->commands(), 'name'));
        self::assertTrue((new McpPrompts([], []))->isEmpty());
        $servers->close();
    }

    public function testThePanelListsEachServerWithItsToolsOrItsReason(): void
    {
        $servers = $this->servers(['docs' => $this->stdio('modern-full'), 'missing' => ['command' => ['/nonexistent/phycode-mcp'], 'enabled' => true, 'approval' => 'ask'], 'off' => $this->stdio(extra: ['enabled' => false])]);

        $items = $servers->items(static fn (string $text): string => $text);

        self::assertSame(['docs', 'docs', 'missing', 'off'], array_column($items, 'category'));
        self::assertSame('connected', $items[0]['title']);
        self::assertSame('stdio, protocol 2026-07-28; 1 tools, 2 prompts, resources', $items[0]['detail']);
        self::assertSame('mcp__docs__echo', $items[1]['title']);
        self::assertSame(['ok', 'ok', 'error', 'off'], array_column($items, 'status'));
        $servers->close();
    }
}
