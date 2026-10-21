<?php

declare(strict_types=1);

namespace Phycode\Tests\Unit\Tools;

use Phycode\Agent\AgentRunner;
use Phycode\Agent\Conversation;
use Phycode\Agent\RunSetup;
use Phycode\Session\Transcript;
use Phycode\Tests\Support\Kit;
use Phycode\Tests\Support\RecordingUi;
use Phycode\Tests\Support\ScriptedClient;
use Phycode\Tools\ArgumentRepair;
use Phycode\Tools\EditTool;
use Phycode\Tools\ListTool;
use Phycode\Tools\ReadTool;
use Phycode\Tools\ToolRegistry;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ArgumentRepairTest extends TestCase
{
    private const SCHEMA = [
        'type' => 'object',
        'properties' => [
            'path' => ['type' => 'string'],
            'count' => ['type' => 'integer'],
            'all' => ['type' => 'boolean'],
            'mode' => ['type' => 'string', 'enum' => ['python', 'javascript']],
            'names' => ['type' => 'array', 'items' => ['type' => 'string']],
            'options' => ['type' => 'object'],
            'command' => ['type' => 'string'],
        ],
        'required' => ['path'],
    ];

    public static function repaired(): iterable
    {
        yield 'a number written as a string' => [['path' => 'a', 'count' => '10'], ['path' => 'a', 'count' => 10]];
        yield 'a number with spaces' => [['path' => 'a', 'count' => ' 7 '], ['path' => 'a', 'count' => 7]];
        yield 'a number with a zero decimal' => [['path' => 'a', 'count' => '5.0'], ['path' => 'a', 'count' => 5]];
        yield 'a whole float' => [['path' => 'a', 'count' => 3.0], ['path' => 'a', 'count' => 3]];
        yield 'a flag written as a string' => [['path' => 'a', 'all' => 'true'], ['path' => 'a', 'all' => true]];
        yield 'a flag written as no' => [['path' => 'a', 'all' => 'No'], ['path' => 'a', 'all' => false]];
        yield 'a flag written as a number' => [['path' => 'a', 'all' => 1], ['path' => 'a', 'all' => true]];
        yield 'a null for an optional argument' => [['path' => 'a', 'count' => null], ['path' => 'a']];
        yield 'an enum in another case' => [['path' => 'a', 'mode' => ' Python '], ['path' => 'a', 'mode' => 'python']];
        yield 'a list written as a JSON string' => [['path' => 'a', 'names' => '["x","y"]'], ['path' => 'a', 'names' => ['x', 'y']]];
        yield 'a bare string where a list goes' => [['path' => 'a', 'names' => 'x'], ['path' => 'a', 'names' => ['x']]];
        yield 'an empty string where a list goes' => [['path' => 'a', 'names' => ''], ['path' => 'a', 'names' => []]];
        yield 'an object written as a JSON string' => [['path' => 'a', 'options' => '{"k":1}'], ['path' => 'a', 'options' => ['k' => 1]]];
        yield 'a number where a string goes' => [['path' => 'a', 'command' => 42], ['path' => 'a', 'command' => '42']];
        yield 'a list of one string where a string goes' => [['path' => 'a', 'command' => ['ls -la']], ['path' => 'a', 'command' => 'ls -la']];
        yield 'a path in a markdown link' => [['path' => '[notes.md](http://notes.md)'], ['path' => 'notes.md']];
        yield 'a path in a link with https' => [['path' => '[src/a.php](https://src/a.php)'], ['path' => 'src/a.php']];
        yield 'a path in quotes' => [['path' => '"src/a.php"'], ['path' => 'src/a.php']];
        yield 'a path in backticks' => [['path' => '`src/a.php`'], ['path' => 'src/a.php']];
        yield 'a path with a newline at its end' => [['path' => "src/a.php\n"], ['path' => 'src/a.php']];
    }

    #[DataProvider('repaired')]
    public function testASlightMistakeIsRepaired(array $given, array $expected): void
    {
        [$args, $repairs] = ArgumentRepair::apply(self::SCHEMA, $given);

        self::assertSame($expected, $args);
        self::assertNotSame([], $repairs, 'and it is reported');
    }

    public static function untouched(): iterable
    {
        yield 'a path with spaces' => [['path' => 'my notes/a b.md']];
        yield 'a real link' => [['path' => '[click](https://example.com)']];
        yield 'a path with quotes inside' => [['path' => "it's.txt"]];
        yield 'values of the right type' => [['path' => 'a', 'count' => 5, 'all' => false, 'mode' => 'python', 'names' => ['x'], 'command' => 'ls']];
        yield 'a number that is not one' => [['path' => 'a', 'count' => 'ten']];
        yield 'an enum that is not a member' => [['path' => 'a', 'mode' => 'ruby']];
        yield 'a flag that means nothing' => [['path' => 'a', 'all' => 'maybe']];
        yield 'a required argument that is null' => [['path' => null]];
        yield 'an argument the schema does not know' => [['path' => 'a', 'extra' => ' 5 ']];
        yield 'a list of two where a string goes' => [['path' => 'a', 'command' => ['ls', '-la']]];
        yield 'a string that only looks like a list' => [['path' => 'a', 'command' => '[not json']];
    }

    #[DataProvider('untouched')]
    public function testValidInputAndWhatCannotBeRepairedIsLeftAlone(array $given): void
    {
        [$args, $repairs] = ArgumentRepair::apply(self::SCHEMA, $given);

        self::assertSame($given, $args);
        self::assertSame([], $repairs);
    }

    public static function texts(): iterable
    {
        yield 'a plain object' => ['{"path":"a"}', ['path' => 'a']];
        yield 'nothing at all' => ['', []];
        yield 'spaces' => ["  \n", []];
        yield 'an empty object' => ['{}', []];
        yield 'null' => ['null', []];
        yield 'a code fence' => ["```json\n{\"path\": \"a\"}\n```", ['path' => 'a']];
        yield 'a code fence without a language' => ["```\n{\"path\": \"a\"}\n```", ['path' => 'a']];
        yield 'arguments encoded twice' => [json_encode('{"path":"a"}'), ['path' => 'a']];
        yield 'a comma before the closing brace' => ['{"path":"a",}', ['path' => 'a']];
        yield 'a comma before a closing bracket' => ['{"names":["x","y",],"path":"a"}', ['names' => ['x', 'y'], 'path' => 'a']];
        yield 'a list' => ['["a"]', null];
        yield 'a scalar' => ['5', null];
        yield 'text' => ['read the file', null];
        yield 'an object that is cut' => ['{"path":"a', null];
    }

    #[DataProvider('texts')]
    public function testTheArgumentsAreFoundInTheTextOfTheCall(string $raw, ?array $expected): void
    {
        self::assertSame($expected, ArgumentRepair::decode($raw));
    }

    public function testRegistryFindsTheToolAModelMeant(): void
    {
        $registry = new ToolRegistry();
        $registry->register(new ReadTool());
        $registry->register(new \Phycode\Tools\GitReadTool(Kit::folder('resolve')));

        foreach (['read', 'Read', ' READ ', 'functions.read', 'tools.read', 'tool.read', 'default_api:read', 'api.Read'] as $name) {
            self::assertSame('read', $registry->resolve($name), $name);
        }
        self::assertSame('git_read', $registry->resolve('git-read'));
        self::assertSame('git_read', $registry->resolve('Git_Read'));
        foreach (['readfile', 'cat', '', 'functions.', 'read file'] as $name) {
            self::assertNull($registry->resolve($name), "'{$name}'");
        }
    }

    public function testAClumsyCallRunsAndTheTranscriptSaysWhatWasRepaired(): void
    {
        $root = realpath(Kit::folder('repair-loop'));
        file_put_contents($root . '/notes.md', "one\ntwo\nthree\n");
        $registry = new ToolRegistry();
        $registry->register(new ReadTool());
        $registry->register(new ListTool());
        $transcript = Transcript::memory();
        $context = Kit::context($root, Kit::agent(), Kit::policy('yolo'), new RecordingUi());
        $context = new \Phycode\Tools\ToolContext($context->workspace, $context->permissions, $context->processes, $context->ui, $transcript, $context->limits, $context->agent, $context->policy, $context->cancel, 'test', null, $context->changes, $context->sandbox);
        $conversation = new Conversation();
        $conversation->setSystem('system');
        $conversation->addUser('task');
        $client = new ScriptedClient([
            ScriptedClient::callRaw('Read', '```json' . "\n" . '{"path": "[notes.md](http://notes.md)", "offset": "2", "limit": null,}' . "\n" . '```'),
            ScriptedClient::callRaw('functions.list', ''),
            ScriptedClient::text('done'),
        ]);

        $outcome = (new AgentRunner())->run($conversation, new RunSetup($client, 'native', $registry, $context, 6, false));

        self::assertSame('done', $outcome->text);
        $replies = array_values(array_filter($client->requests[2]['messages'], static fn (array $m): bool => ($m['role'] ?? '') === 'tool'));
        self::assertStringContainsString('     2  two', $replies[0]['content']);
        self::assertStringContainsString('notes.md', $replies[1]['content']);
        $events = [];
        foreach (explode("\n", trim($transcript->contents())) as $line) {
            $event = json_decode($line, true);
            if (is_array($event) && ($event['type'] ?? '') === 'tool_call') {
                $events[] = $event['data'];
            }
        }
        self::assertContains('tool name: Read -> read', $events[0]['repaired']);
        self::assertContains('path: "[notes.md](http://notes.md)" -> "notes.md"', $events[0]['repaired']);
        self::assertContains('offset: "2" -> 2', $events[0]['repaired']);
        self::assertContains('limit: null dropped', $events[0]['repaired']);
        self::assertSame(['tool name: functions.list -> list'], $events[1]['repaired'], 'a call with no arguments needed only its name');
    }

    public function testATextThatIsNotAnObjectStillGetsTheOldError(): void
    {
        $registry = new ToolRegistry();
        $registry->register(new ReadTool());
        $root = realpath(Kit::folder('repair-bad'));
        $context = Kit::context($root, Kit::agent(), Kit::policy('yolo'), new RecordingUi());
        $conversation = new Conversation();
        $conversation->setSystem('system');
        $conversation->addUser('task');
        $client = new ScriptedClient([ScriptedClient::callRaw('read', 'read the file please'), ScriptedClient::text('done')]);

        (new AgentRunner())->run($conversation, new RunSetup($client, 'native', $registry, $context, 4, false));

        $replies = array_values(array_filter($client->requests[1]['messages'], static fn (array $m): bool => ($m['role'] ?? '') === 'tool'));
        self::assertStringContainsString('the tool arguments must be a JSON object', $replies[0]['content']);
    }

    public function testEditTellsTheExactTextWhenOnlyTheSpacingDiffers(): void
    {
        $root = realpath(Kit::folder('edit-hint'));
        file_put_contents($root . '/a.php', "<?php\nfunction run()\n{\n\t\$x = 1;\n\treturn \$x;\n}\n");
        $context = Kit::context($root, Kit::agent(), Kit::policy('yolo'), new RecordingUi());

        $result = (new EditTool())->execute(['path' => 'a.php', 'old_string' => "    \$x = 1;\n    return \$x;", 'new_string' => 'return 1;'], $context);

        self::assertFalse($result->ok);
        self::assertStringContainsString('The same text is at lines 4-5 with other spacing', $result->content);
        self::assertStringContainsString("     4  \t\$x = 1;", $result->content);
        self::assertStringContainsString("     5  \treturn \$x;", $result->content);
        self::assertSame("<?php\nfunction run()\n{\n\t\$x = 1;\n\treturn \$x;\n}\n", file_get_contents($root . '/a.php'), 'nothing was changed on a guess');
    }

    public function testEditNamesTheNearestLineWhenThereIsNoSuchBlock(): void
    {
        $root = realpath(Kit::folder('edit-near'));
        file_put_contents($root . '/a.txt', "alpha\nthe quick brown fox jumps\nomega\n");
        $context = Kit::context($root, Kit::agent(), Kit::policy('yolo'), new RecordingUi());

        $near = (new EditTool())->execute(['path' => 'a.txt', 'old_string' => 'the quick brown fox jumped', 'new_string' => 'x'], $context);
        self::assertStringContainsString('The nearest line is line 2: the quick brown fox jumps', $near->content);

        $far = (new EditTool())->execute(['path' => 'a.txt', 'old_string' => 'zzzzzzzzzz', 'new_string' => 'x'], $context);
        self::assertStringContainsString('Read the file again with read and copy the exact text', $far->content);

        $twice = (new EditTool())->execute(['path' => 'a.txt', 'old_string' => '  omega  ', 'new_string' => 'x'], $context);
        self::assertTrue($twice->ok === false || str_contains($twice->content, 'edited'), 'an exact substring still edits');
    }

    public function testEditDoesNotGuessWhenTheSpacedBlockIsInSeveralPlaces(): void
    {
        $root = realpath(Kit::folder('edit-twice'));
        file_put_contents($root . '/a.txt', "\tfoo();\n\tbar();\nx\n    foo();\n    bar();\n");
        $context = Kit::context($root, Kit::agent(), Kit::policy('yolo'), new RecordingUi());

        $result = (new EditTool())->execute(['path' => 'a.txt', 'old_string' => "  foo();\n  bar();", 'new_string' => 'x'], $context);

        self::assertFalse($result->ok);
        self::assertStringNotContainsString('The same text is at lines', $result->content);
    }
}
