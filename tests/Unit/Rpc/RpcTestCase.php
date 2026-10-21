<?php

declare(strict_types=1);

namespace Phycode\Tests\Unit\Rpc;

use Phycode\Btw\BtwJobs;
use Phycode\Cli\ChatSession;
use Phycode\Llm\ClientProvider;
use Phycode\Rpc\JsonLines;
use Phycode\Rpc\RpcServer;
use Phycode\Rpc\RpcUi;
use Phycode\Support\Cancellation;
use Phycode\Tests\Support\Kit;
use Phycode\Tests\Support\SessionHarness;
use PHPUnit\Framework\TestCase;

abstract class RpcTestCase extends TestCase
{
    protected SessionHarness $harness;

    protected string $state;

    protected ?ChatSession $session = null;

    protected array $turnWords = [];

    private $writer = null;

    private string|false $previousStateHome;

    private \Closure $endInput;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousStateHome = getenv('XDG_STATE_HOME');
        $stateHome = Kit::folder('rpc-state');
        putenv('XDG_STATE_HOME=' . $stateHome);
        $this->state = $stateHome . '/phycode/state.json';
        mkdir(dirname($this->state), 0700);
    }

    protected function tearDown(): void
    {
        Cancellation::setPoll(null);
        if (isset($this->harness)) {
            $this->harness->end();
        }
        if (isset($this->previousStateHome)) {
            if ($this->previousStateHome === false) {
                putenv('XDG_STATE_HOME');
            } else {
                putenv('XDG_STATE_HOME=' . $this->previousStateHome);
            }
        }
        parent::tearDown();
    }

    protected function play(array $commands, ClientProvider $providers, ?\Closure $beforeRun = null, ?BtwJobs $btw = null, ?\Closure $research = null): array
    {
        [$input, $writer] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        foreach ($commands as $command) {
            fwrite($writer, json_encode($command, JSON_THROW_ON_ERROR) . "\n");
        }
        $this->writer = $writer;
        $this->endInput = static function () use ($writer): void {
            if (is_resource($writer)) {
                fclose($writer);
            }
        };

        $output = fopen('php://temp', 'r+');
        $channel = new JsonLines($input, $output);
        $ui = new RpcUi($channel);
        $kernel = $this->harness->kernel($providers, $ui);
        $session = $this->harness->session($kernel);
        $this->session = $session;
        $ui->attach(static fn (): string => $session->primary()->name, static fn () => $session->interrupt());
        if ($beforeRun !== null) {
            $beforeRun($kernel);
        }
        $turns = array_filter($commands, fn (array $command): bool => $this->startsTurn($command, $session));
        if ($turns === []) {
            ($this->endInput)();
        }
        $exit = (new RpcServer($channel, $ui, $kernel, $session, '0.0.0-test', $btw, $research))->run();

        rewind($output);
        $events = [];
        foreach (explode("\n", (string) stream_get_contents($output)) as $line) {
            if ($line !== '') {
                $events[] = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
            }
        }

        return ['exit' => $exit, 'events' => $events];
    }

    private function startsTurn(array $command, ChatSession $session): bool
    {
        $type = $command['type'] ?? null;
        if ($type === 'compact' || $type === 'deepresearch') {
            return true;
        }
        if ($type !== 'input') {
            return false;
        }
        $line = trim((string) $command['text']);
        if (in_array(explode(' ', $line, 2)[0], ['/init', '/compact', ...$this->turnWords], true)) {
            return true;
        }
        $answeredHere = $line === '/btw' || str_starts_with($line, '/btw ') || in_array($line, ['/config', '/sessions'], true);

        return !$answeredHere && !$session->isCommand($line);
    }

    protected function endingInput(): \Closure
    {
        return fn () => ($this->endInput)();
    }

    protected function send(array $command): void
    {
        fwrite($this->writer, json_encode($command, JSON_THROW_ON_ERROR) . "\n");
    }

    protected static function first(array $events, string $type): array
    {
        $matches = self::of($events, $type);
        self::assertNotEmpty($matches, "no {$type} event");

        return $matches[0];
    }

    protected static function of(array $events, string $type): array
    {
        return array_values(array_filter($events, static fn (array $event): bool => ($event['type'] ?? null) === $type));
    }

    protected static function types(array $events): array
    {
        return array_column($events, 'type');
    }
}
