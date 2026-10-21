<?php

declare(strict_types=1);

namespace Phycode\Tests\Unit\Rpc;

use Phycode\Rpc\JsonLines;
use Phycode\Rpc\RpcUi;
use Phycode\Support\Cancellation;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RpcTurnRelayTest extends TestCase
{
    private const REFUSAL = 'a turn is running; try again when it finishes';

    private $output;
    private $terminal;
    private JsonLines $channel;
    private RpcUi $ui;

    protected function tearDown(): void
    {
        Cancellation::setPoll(null);
    }

    public static function stateChanging(): iterable
    {
        yield 'list_models' => ['list_models', ['refresh' => true]];
        yield 'set_model' => ['set_model', ['profile' => 'local', 'model' => 'x', 'thinking' => 'off', 'save' => false]];
        yield 'favorite' => ['favorite', ['profile' => 'local', 'model' => 'x', 'on' => true]];
        yield 'custom_model' => ['custom_model', ['profile' => 'local', 'model' => 'x', 'action' => 'add']];
        yield 'add_provider' => ['add_provider', ['name' => 'remote', 'protocol' => 'openai', 'base_url' => 'http://x', 'api_key_env' => null, 'model' => 'x']];
        yield 'config_set' => ['config_set', ['key' => 'ui.diff_view', 'value' => 'split']];
        yield 'session_switch' => ['session_switch', ['id' => 'ses_1']];
        yield 'session_new' => ['session_new', []];
        yield 'set_effort' => ['set_effort', ['level' => 'high']];
        yield 'set_context' => ['set_context', ['max' => 4000]];
        yield 'compact' => ['compact', []];
        yield 'deepresearch' => ['deepresearch', ['question' => 'x', 'rounds' => 2]];
        yield 'add_search_provider' => ['add_search_provider', ['name' => 'exa', 'backend' => 'exa', 'url' => null, 'api_key' => 'k']];
        yield 'update_search_provider' => ['update_search_provider', ['name' => 'exa', 'url' => 'https://x.example.test/search']];
        yield 'remove_search_provider' => ['remove_search_provider', ['name' => 'exa']];
        yield 'set_search_provider' => ['set_search_provider', ['name' => null]];
    }

    #[DataProvider('stateChanging')]
    public function testAStateChangingCommandReadDuringATurnIsRefusedWithAWarning(string $type, array $fields): void
    {
        $this->connect();
        $this->command($type, $fields);

        $this->turn();

        self::assertSame([
            ['type' => 'turn_start'],
            ['type' => 'notice', 'level' => 'warn', 'message' => self::REFUSAL],
            ['type' => 'text', 'agent' => 'build', 'chunk' => 'answer'],
            ['type' => 'turn_end', 'status' => 'completed', 'reason' => null, 'changes' => [], 'usage' => [], 'branch' => null],
        ], $this->sent());
    }

    public function testAnInputDuringATurnIsRefusedWhenTheServerDoesNotTakeIt(): void
    {
        $this->connect();
        $this->command('input', ['text' => '/deepresearch what is new?']);

        $this->turn();

        self::assertSame(['type' => 'notice', 'level' => 'warn', 'message' => 'a turn is already running'], $this->sent()[1]);
    }

    public function testTheServerTakesTheCommandsItHandlesDuringATurnAndTheRestAreRefused(): void
    {
        $taken = [];
        $this->connect();
        $this->ui->attachServer(static fn () => null, static function (array $command) use (&$taken): bool {
            $taken[] = $command['type'];

            return in_array($command['type'], ['btw', 'session_list'], true);
        });
        $this->command('btw', ['question' => 'what is two plus two?']);
        $this->command('session_list');
        $this->command('config_set', ['key' => 'ui.diff_view', 'value' => 'split']);

        $this->turn();

        self::assertSame(['btw', 'session_list', 'config_set'], $taken, 'the server sees every command, in order');
        self::assertSame(['turn_start', 'notice', 'text', 'turn_end'], array_column($this->sent(), 'type'));
        self::assertSame(self::REFUSAL, $this->sent()[1]['message']);
    }

    public function testAnInputThatIsASideQuestionIsTakenByTheServerDuringATurn(): void
    {
        $taken = [];
        $this->connect();
        $this->ui->attachServer(static fn () => null, static function (array $command) use (&$taken): bool {
            $taken[] = $command;

            return ($command['type'] ?? null) === 'input' && str_starts_with((string) $command['text'], '/btw ');
        });
        $this->command('input', ['text' => '/btw is it raining?']);
        $this->command('input', ['text' => 'a second question']);

        $this->turn();

        self::assertCount(2, $taken);
        self::assertSame(['type' => 'notice', 'level' => 'warn', 'message' => 'a turn is already running'], $this->sent()[1], 'only the line that is no side question is refused');
    }

    public function testAReplyThatNothingWaitsForIsIgnored(): void
    {
        $this->connect();
        $this->command('ask_reply', ['id' => 'a1', 'answer' => 'yes']);
        $this->command('confirm_reply', ['id' => 'c1', 'decision' => 'once']);

        $this->turn();

        self::assertSame(['turn_start', 'text', 'turn_end'], array_column($this->sent(), 'type'));
    }

    public function testTheServerSweepsBeforeEachEventOfATurn(): void
    {
        $sweeps = 0;
        $this->connect();
        $this->ui->attachServer(static function () use (&$sweeps): void {
            $sweeps++;
        }, static fn (): bool => false);

        $this->ui->beginTurn();
        $this->ui->text('one');
        $this->ui->text('two');

        self::assertSame(2, $sweeps);
    }

    public function testAnIdleTickSweepsTheServerAndLeavesTheCommandsToTheServerLoop(): void
    {
        $sweeps = 0;
        $this->connect();
        $this->ui->attachServer(static function () use (&$sweeps): void {
            $sweeps++;
        }, static fn (): bool => false);
        $this->command('interrupt');

        $this->ui->tick();

        self::assertSame(1, $sweeps);
        self::assertSame(['type' => 'interrupt'], $this->channel->next(0.0));
    }

    private function turn(): void
    {
        $this->ui->beginTurn();
        $this->ui->text('answer');
        $this->ui->endTurn('completed', null, [], [], null);
    }

    private function connect(): void
    {
        [$reader, $this->terminal] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        $this->output = fopen('php://temp', 'r+');
        $this->channel = new JsonLines($reader, $this->output);
        $this->ui = new RpcUi($this->channel);
        $this->ui->attach(static fn (): string => 'build', static fn () => null);
        $this->ui->start(['type' => 'ready', 'protocol' => 2]);
    }

    private function command(string $type, array $fields = []): void
    {
        fwrite($this->terminal, json_encode(['type' => $type] + $fields, JSON_THROW_ON_ERROR) . "\n");
    }

    private function sent(): array
    {
        rewind($this->output);
        $lines = array_values(array_filter(explode("\n", (string) stream_get_contents($this->output)), static fn (string $line): bool => $line !== ''));
        $events = array_map(static fn (string $line): array => json_decode($line, true, 512, JSON_THROW_ON_ERROR), $lines);
        self::assertSame('ready', array_shift($events)['type'] ?? null);

        return $events;
    }
}
