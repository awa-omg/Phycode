<?php

declare(strict_types=1);

namespace Phycode\Tests\Unit\Cli;

use Phycode\Cli\ChatSession;
use Phycode\Cli\Commands\CommandContext;
use Phycode\Cli\Commands\CommandProvider;
use Phycode\Cli\Commands\CommandRegistry;
use Phycode\Config\Config;
use Phycode\Llm\ClientProvider;
use Phycode\Support\PhycodeException;
use Phycode\Tests\Support\RecordingUi;
use Phycode\Tests\Support\RequestSpy;
use Phycode\Tests\Support\ScriptedClient;
use Phycode\Tests\Support\SessionHarness;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CommandRegistryTest extends TestCase
{
    private const CONFIG = [
        'default_profile' => 'local',
        'profiles' => [
            'local' => ['base_url' => 'http://127.0.0.1:9', 'model' => 'qwen', 'tool_mode' => 'text', 'context_limit' => 4096],
        ],
    ];

    private SessionHarness $harness;
    private RecordingUi $ui;

    protected function setUp(): void
    {
        $this->harness = new SessionHarness(self::CONFIG);
        $this->ui = new RecordingUi();
    }

    protected function tearDown(): void
    {
        $this->harness->end();
    }

    public function testDispatchGivesTheWordAndTheArgumentsToItsProvider(): void
    {
        $registry = new CommandRegistry();
        $review = new FakeCommands([['name' => '/review [PATH]', 'description' => 'review a path']]);
        $registry->add($review);

        self::assertTrue($registry->has('/review'));
        self::assertFalse($registry->has('/review [PATH]'), 'the word is the name of a command, the usage is not');
        $registry->dispatch('/review', ' src/a.php ', $this->context());

        self::assertSame([['name' => '/review', 'args' => 'src/a.php']], $review->calls);
    }

    public function testMatchTakesArgumentsOnlyWhenTheNameDeclaresThem(): void
    {
        $registry = new CommandRegistry();
        $registry->add(new FakeCommands([['name' => '/review [PATH]', 'description' => 'review'], ['name' => '/notes', 'description' => 'notes']]));

        self::assertSame(['/review', ''], $registry->match('/review'));
        self::assertSame(['/review', 'src/a.php'], $registry->match('  /review   src/a.php '));
        self::assertSame(['/notes', ''], $registry->match('/notes'));
        self::assertNull($registry->match('/notes extra'), 'a command that declares no arguments does not take them');
        self::assertNull($registry->match('/reviewer'), 'a word that only starts like a command is not one');
        self::assertNull($registry->match('plain text'));
    }

    public function testCommandsAreListedInTheOrderTheyWereAdded(): void
    {
        $registry = new CommandRegistry();
        $registry->add(new FakeCommands([['name' => '/review [PATH]', 'description' => 'review a path']]));
        $registry->add(new FakeCommands([['name' => '/notes', 'description' => 'notes']]));

        self::assertSame([
            ['name' => '/review [PATH]', 'description' => 'review a path'],
            ['name' => '/notes', 'description' => 'notes'],
        ], $registry->commands());
    }

    public static function invalidLists(): iterable
    {
        yield 'a name without a slash' => [[['name' => 'review', 'description' => 'x']]];
        yield 'a name with capitals' => [[['name' => '/Review', 'description' => 'x']]];
        yield 'an empty description' => [[['name' => '/review', 'description' => '  ']]];
        yield 'a word named twice by one provider' => [[['name' => '/review', 'description' => 'x'], ['name' => '/review [PATH]', 'description' => 'y']]];
    }

    #[DataProvider('invalidLists')]
    public function testAnInvalidListIsRefusedAndNoneOfItsCommandsIsAdded(array $list): void
    {
        $registry = new CommandRegistry();
        $registry->add(new FakeCommands([['name' => '/first', 'description' => 'first']]));

        try {
            $registry->add(new FakeCommands($list));
            self::fail('the list was accepted');
        } catch (PhycodeException) {
            self::assertSame(['/first'], array_column($registry->commands(), 'name'));
            self::assertFalse($registry->has('/review'));
        }
    }

    public function testAWordBelongsToOneProviderOnly(): void
    {
        $registry = new CommandRegistry();
        $first = new FakeCommands([['name' => '/review', 'description' => 'first']]);
        $registry->add($first);

        try {
            $registry->add(new FakeCommands([['name' => '/review [PATH]', 'description' => 'second']]));
            self::fail('the word was taken twice');
        } catch (PhycodeException $error) {
            self::assertSame('the command /review is provided twice', $error->getMessage());
        }
        $registry->dispatch('/review', '', $this->context());

        self::assertSame([['name' => '/review', 'args' => '']], $first->calls);
    }

    public function testDispatchOfAWordNobodyNamesIsRefused(): void
    {
        $this->expectException(PhycodeException::class);
        $this->expectExceptionMessage('no command named /nothing');

        (new CommandRegistry())->dispatch('/nothing', '', $this->context());
    }

    public function testTheCommandsOfASessionAreTheBuiltInOnesThenTheRegistryOnesAndABuiltInWordIsListedOnce(): void
    {
        $session = $this->session(new RequestSpy(['local' => 'qwen']), new FakeCommands([
            ['name' => '/digest [PATH]', 'description' => 'digest a path'],
            ['name' => '/help', 'description' => 'shadowed'],
        ]));

        $names = array_column($session->commands(), 'name');
        self::assertSame('/digest [PATH]', end($names));
        self::assertSame(1, count(array_keys($names, '/help', true)));
        self::assertSame('show the commands', $this->description($session, '/help'));
    }

    public function testIsCommandRecognisesTheRegistryWordAndTheArgumentsItsNameDeclares(): void
    {
        $session = $this->session(new RequestSpy(['local' => 'qwen']), new FakeCommands([
            ['name' => '/digest [PATH]', 'description' => 'digest'],
            ['name' => '/notes', 'description' => 'notes'],
        ]));

        self::assertTrue($session->isCommand('/digest'));
        self::assertTrue($session->isCommand('/digest src'));
        self::assertTrue($session->isCommand('/notes'));
        self::assertFalse($session->isCommand('/notes extra'));
        self::assertFalse($session->isCommand('/digester'));
    }

    public function testARegistryCommandAnswersWithoutATurn(): void
    {
        $spy = new RequestSpy(['local' => 'qwen']);
        $notes = new FakeCommands(
            [['name' => '/notes', 'description' => 'notes']],
            static fn (string $name, string $args, CommandContext $ctx) => $ctx->panel('Notes', 'three notes'),
        );
        $session = $this->session($spy, $notes);

        self::assertTrue($session->handle('/notes'));

        self::assertSame([['name' => '/notes', 'args' => '']], $notes->calls);
        self::assertContains(['panel', 'Notes', 'three notes'], $this->ui->events);
        self::assertSame([], $spy->sent, 'no model was asked');
        self::assertNull($session->lastStatus());
    }

    public function testACommandThatRunsATurnRunsItInTheSession(): void
    {
        $spy = new RequestSpy(['local' => 'qwen'], [], [ScriptedClient::text('the review')]);
        $digest = new FakeCommands(
            [['name' => '/digest [PATH]', 'description' => 'digest']],
            static fn (string $name, string $args, CommandContext $ctx) => $ctx->runTurn("digest {$args}"),
        );
        $session = $this->session($spy, $digest);

        self::assertTrue($session->handle('/digest src/a.php'));

        self::assertSame('digest src/a.php', end($spy->sent[0]['messages'])['content']);
        self::assertSame('completed', $session->lastStatus());
        self::assertSame(3, $session->contextState()['messages'], 'the system message, the prompt and the reply');
    }

    public function testTheTextsOfACommandAreRedacted(): void
    {
        $key = 'fakekey-7f3a9c2e51b8d4';
        $keyFile = $this->harness->home . '/key.txt';
        file_put_contents($keyFile, $key);
        file_put_contents($this->harness->configFile, json_encode(['default_profile' => 'local', 'profiles' => [
            'local' => ['base_url' => 'http://127.0.0.1:9', 'model' => 'qwen', 'tool_mode' => 'text', 'api_key_file' => $keyFile],
        ]], JSON_THROW_ON_ERROR));
        $show = new FakeCommands(
            [['name' => '/show', 'description' => 'show']],
            static function (string $name, string $args, CommandContext $ctx) use ($key): void {
                $ctx->panel('Show', "the key is {$key}");
                $ctx->notice('warn', "key {$key}");
                $ctx->notice('bogus', 'x');
            },
        );

        self::assertTrue($this->session(new RequestSpy(['local' => 'qwen']), $show)->handle('/show'));

        self::assertContains(['panel', 'Show', 'the key is [redacted]'], $this->ui->events);
        self::assertContains(['notice', 'warn', 'key [redacted]'], $this->ui->events);
        self::assertContains(['notice', 'error', 'a notice level is info, warn or error, not bogus'], $this->ui->events);
    }

    public function testAFailingCommandIsANoticeAndTheSessionGoesOn(): void
    {
        $boom = new FakeCommands(
            [['name' => '/boom', 'description' => 'boom']],
            static function (): void {
                throw new PhycodeException('the command broke');
            },
        );
        $session = $this->session(new RequestSpy(['local' => 'qwen']), $boom);

        self::assertTrue($session->handle('/boom'));
        self::assertContains(['notice', 'error', 'the command broke'], $this->ui->events);
        self::assertTrue($session->handle('/help'));
    }

    public function testABuiltInCommandWinsOverARegistryCommandOfTheSameWord(): void
    {
        $shadow = new FakeCommands([['name' => '/help', 'description' => 'shadowed']]);
        $session = $this->session(new RequestSpy(['local' => 'qwen']), $shadow);

        self::assertTrue($session->handle('/help'));

        self::assertSame([], $shadow->calls);
        self::assertContains('Help', array_column(array_filter($this->ui->events, static fn (array $event): bool => $event[0] === 'panel'), 1));
    }

    public function testTheHelpPanelListsTheRegistryCommands(): void
    {
        $session = $this->session(new RequestSpy(['local' => 'qwen']), new FakeCommands([['name' => '/digest [PATH]', 'description' => 'digest a path']]));

        $session->handle('/help');

        $help = array_values(array_filter($this->ui->events, static fn (array $event): bool => $event[0] === 'panel'))[0][2];
        self::assertStringContainsString('/digest [PATH]', $help);
        self::assertStringContainsString('digest a path', $help);
    }

    private function session(ClientProvider $models, CommandProvider ...$providers): ChatSession
    {
        $kernel = $this->harness->kernel($models, $this->ui);
        foreach ($providers as $provider) {
            $kernel->commands->add($provider);
        }

        return $this->harness->session($kernel);
    }

    private function description(ChatSession $session, string $name): string
    {
        foreach ($session->commands() as $command) {
            if ($command['name'] === $name) {
                return $command['description'];
            }
        }
        self::fail("no command {$name}");
    }

    private function context(): CommandContext
    {
        $config = Config::fromArrays(['profiles' => ['local' => ['base_url' => 'http://127.0.0.1:8082', 'model' => 'qwen']]], null, [], '/nonexistent');

        return new CommandContext($this->ui, '/project', $config, static function (string $prompt): void {}, static fn (string $text): string => $text);
    }
}
