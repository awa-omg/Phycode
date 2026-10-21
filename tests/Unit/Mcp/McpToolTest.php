<?php

declare(strict_types=1);

namespace Phycode\Tests\Unit\Mcp;

use Phycode\Mcp\McpClient;
use Phycode\Mcp\McpTool;
use Phycode\Support\PhycodeException;
use Phycode\Tests\Support\Kit;
use Phycode\Tests\Support\RecordingUi;
use Phycode\Tools\ToolCategory;
use Phycode\Tools\ToolContext;
use PHPUnit\Framework\TestCase;

final class McpToolTest extends TestCase
{
    private array $clients = [];

    protected function tearDown(): void
    {
        foreach ($this->clients as $client) {
            $client->close();
        }
        $this->clients = [];
    }

    public function testTheNameJoinsTheServerAndTheTool(): void
    {
        $tool = new McpTool(new McpClient('github', ['/bin/true']), self::definition('create_issue'));

        self::assertSame('mcp__github__create_issue', $tool->name());
        self::assertSame('mcp__github__create_issue', McpTool::nameFor('github', 'create_issue'));
    }

    public function testCharactersOutsideTheNameAlphabetAreReplaced(): void
    {
        self::assertSame('mcp__my_server__read_file_v2', McpTool::nameFor('my server', 'read.file/v2'));
    }

    public function testLongNamesAreCutToSixtyFourCharactersWithAHash(): void
    {
        $long = str_repeat('tool', 30);
        $name = McpTool::nameFor('server', $long);

        self::assertSame(64, strlen($name));
        self::assertStringStartsWith('mcp__server__tooltool', $name);
        self::assertSame($name, McpTool::nameFor('server', $long));
        self::assertNotSame($name, McpTool::nameFor('server', $long . 'x'));
    }

    public function testItIsAnExecTool(): void
    {
        $tool = new McpTool(new McpClient('github', ['/bin/true']), self::definition('create_issue'));

        self::assertSame(ToolCategory::Exec, $tool->category());
    }

    public function testAMissingDescriptionFallsBackToAPlainOne(): void
    {
        $tool = new McpTool(new McpClient('github', ['/bin/true']), self::definition('create_issue', ''));

        self::assertSame('Tool create_issue of the MCP server github.', $tool->description());
    }

    public function testParametersAreTheInputSchemaAndAnEmptyPropertyListIsAnObject(): void
    {
        $client = new McpClient('github', ['/bin/true']);

        self::assertSame(
            ['type' => 'object', 'properties' => ['text' => ['type' => 'string']], 'required' => ['text']],
            (new McpTool($client, self::definition('echo')))->parameters(),
        );
        $parameters = (new McpTool($client, self::definition('ping', 'Pings.', ['type' => 'object'])))->parameters();
        self::assertSame('object', $parameters['type']);
        self::assertInstanceOf(\stdClass::class, $parameters['properties']);
    }

    public function testTheSummaryNamesTheToolAndShowsEveryArgument(): void
    {
        $tool = new McpTool(new McpClient('echo', ['/bin/true']), self::definition('echo'));

        self::assertSame(
            'mcp__echo__echo text=hi count=3 settings={"verbose":true} long=' . str_repeat('a', 79) . '…',
            $tool->summarize(['text' => 'hi', 'count' => 3, 'settings' => ['verbose' => true], 'long' => str_repeat('a', 200)]),
        );
        self::assertSame('mcp__echo__echo', $tool->summarize([]));
    }

    public function testValidateRejectsAMissingRequiredArgument(): void
    {
        $tool = new McpTool(new McpClient('echo', ['/bin/true']), self::definition('echo'));

        $this->expectException(PhycodeException::class);
        $this->expectExceptionMessage("invalid arguments: $: the property 'text' is required");

        $tool->validate([], $this->context());
    }

    public function testValidateAcceptsTheRequiredArguments(): void
    {
        $tool = new McpTool(new McpClient('echo', ['/bin/true']), self::definition('echo'));

        self::assertNull($tool->validate(['text' => 'x'], $this->context()));
    }

    public function testDiscoverListsEveryToolOfTheServer(): void
    {
        $names = array_map(
            static fn (McpTool $tool): string => $tool->name(),
            McpTool::discover($this->client('paged', 'paged')),
        );

        self::assertSame(['mcp__paged__echo', 'mcp__paged__upper', 'mcp__paged__options'], $names);
    }

    public function testTheDescriptionComesFromTheServer(): void
    {
        $tools = McpTool::discover($this->client());

        self::assertSame('Echoes the text argument back.', $tools[0]->description());
    }

    public function testExecuteSendsTheArgumentsAndReturnsWhatTheServerAnswers(): void
    {
        $tool = McpTool::discover($this->client())[0];

        $result = $tool->execute(['text' => 'round trip'], $this->context());

        self::assertTrue($result->ok);
        self::assertSame('round trip', $result->content);
    }

    public function testExecuteJoinsTheTextBlocksAndSummarisesTheOtherBlocks(): void
    {
        $tool = McpTool::discover($this->client('mixed', 'mixed'))[0];

        $result = $tool->execute(['text' => 'x'], $this->context());

        self::assertTrue($result->ok);
        self::assertSame(
            "first line\n[image: image/png, about 11 bytes]\n[resource_link: notes, file:///notes.md]\nsecond line",
            $result->content,
        );
    }

    public function testAResultWithNoContentIsAnEmptyOkResult(): void
    {
        $tool = McpTool::discover($this->client('empty-result', 'empty'))[0];

        $result = $tool->execute(['text' => 'x'], $this->context());

        self::assertTrue($result->ok);
        self::assertSame('', $result->content);
    }

    public function testAToolErrorIsAFailedResultWithTheServersText(): void
    {
        $tool = McpTool::discover($this->client('failing', 'failing'))[0];

        $result = $tool->execute(['text' => 'x'], $this->context());

        self::assertFalse($result->ok);
        self::assertSame('the file is locked', $result->content);
    }

    public function testACrashedServerIsAFailedResultNotAnException(): void
    {
        $tool = McpTool::discover($this->client('crash', 'crash'))[0];

        $result = $tool->execute(['text' => 'x'], $this->context());

        self::assertFalse($result->ok);
        self::assertSame('MCP server crash exited with code 3 while waiting for tools/call: fatal: echo tool exploded', $result->content);
    }

    public function testAnEmptyObjectInTheSchemaGoesOutAsAnObject(): void
    {
        $log = FakeMcpServer::logFile();
        $options = McpTool::discover($this->client('paged', 'paged', ['PHYCODE_FAKE_MCP_LOG' => $log]))[2];

        $result = $options->execute(['settings' => [], 'tags' => []], $this->context());

        self::assertTrue($result->ok);
        $lines = FakeMcpServer::rawLines($log);
        self::assertStringContainsString('"name":"options","arguments":{"settings":{},"tags":[]}', $lines[array_key_last($lines)]);
    }

    private function client(string $scenario = '', string $name = 'echo', array $env = []): McpClient
    {
        $client = FakeMcpServer::client($scenario, $name, env: $env);
        $this->clients[] = $client;

        return $client;
    }

    private function context(): ToolContext
    {
        return Kit::context(Kit::folder('mcp-tool'), Kit::agent(), Kit::policy(), new RecordingUi());
    }

    private static function definition(
        string $name,
        string $description = 'Does it.',
        array $schema = ['type' => 'object', 'properties' => ['text' => ['type' => 'string']], 'required' => ['text']],
    ): array {
        return ['name' => $name, 'description' => $description, 'inputSchema' => $schema];
    }
}
