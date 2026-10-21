<?php

declare(strict_types=1);

namespace Phycode\Tests\Integration;

use Phycode\Session\SessionStore;
use Phycode\Tests\Support\Kit;
use Phycode\Tests\Support\RpcProcess;
use PHPUnit\Framework\TestCase;

final class RpcProcessTest extends TestCase
{
    private string $home;
    private string $cwd;

    protected function setUp(): void
    {
        $this->home = Kit::folder('rpc-home');
        $this->cwd = Kit::folder('rpc-cwd');
    }

    public function testHelpAndAgentsAreAnsweredAndExitEndsTheSession(): void
    {
        $this->configure('text');

        $result = $this->backend([
            self::command('input', ['text' => '/help']),
            self::command('input', ['text' => '/agents']),
            self::command('input', ['text' => '/exit']),
        ]);

        self::assertSame(0, $result['exit'], $result['stderr']);
        self::assertSame(['ready', 'panel', 'panel', 'bye'], self::types($result['events']));
        $ready = $result['events'][0];
        self::assertSame(2, $ready['protocol']);
        self::assertContains('ask', $ready['capabilities']);
        self::assertContains('/help', array_column($ready['commands'], 'name'));
        self::assertContains('/exit', array_column($ready['commands'], 'name'));
        self::assertSame(['profile' => 'test', 'model' => 'scripted', 'toolMode' => 'text', 'permissionMode' => 'ask', 'git' => false], [
            'profile' => $ready['app']['profile'],
            'model' => $ready['app']['model'],
            'toolMode' => $ready['app']['toolMode'],
            'permissionMode' => $ready['app']['permissionMode'],
            'git' => $ready['app']['git'],
        ]);
        self::assertSame(realpath($this->cwd), $ready['app']['cwd']);
        self::assertSame(['type' => 'panel', 'title' => 'Help', 'kind' => 'text'], array_intersect_key($result['events'][1], ['type' => true, 'title' => true, 'kind' => true]));
        self::assertStringContainsString('/help', $result['events'][1]['text']);
        self::assertSame(['type' => 'panel', 'title' => 'Agents', 'kind' => 'text'], array_intersect_key($result['events'][2], ['type' => true, 'title' => true, 'kind' => true]));
        self::assertStringContainsString('build', $result['events'][2]['text']);
        self::assertSame(['type' => 'bye', 'reason' => 'exit'], $result['events'][3]);
    }

    public function testStatusIsAPanelThatNeedsNoTurn(): void
    {
        $this->configure('text');

        $result = $this->backend([
            self::command('input', ['text' => '/status']),
            self::command('input', ['text' => '/exit']),
        ]);

        self::assertSame(0, $result['exit'], $result['stderr']);
        self::assertSame(['ready', 'panel', 'bye'], self::types($result['events']));
        $status = $result['events'][1];
        self::assertSame(['Status', 'text'], [$status['title'], $status['kind']]);
        self::assertStringContainsString('version', $status['text']);
        self::assertStringContainsString('transcript', $status['text']);
        self::assertStringContainsString('ask', $status['text']);
    }

    public function testQuitEndsTheSessionWithBye(): void
    {
        $this->configure('text');

        $result = $this->backend([self::command('quit')]);

        self::assertSame(0, $result['exit'], $result['stderr']);
        self::assertSame(['ready', 'bye'], self::types($result['events']));
        self::assertSame(['type' => 'bye', 'reason' => 'quit'], $result['events'][1]);
    }

    public function testEndOfInputEndsTheSessionWithBye(): void
    {
        $this->configure('text');

        $result = $this->backend([]);

        self::assertSame(0, $result['exit'], $result['stderr']);
        self::assertSame(['ready', 'bye'], self::types($result['events']));
        self::assertSame(['type' => 'bye', 'reason' => 'eof'], $result['events'][1]);
    }

    public function testCommandsThatDoNothingWhileIdleProduceNoEvents(): void
    {
        $this->configure('text');

        $result = $this->backend([
            self::command('input', ['text' => " \t "]),
            self::command('interrupt'),
            self::command('confirm_reply', ['id' => 'c9', 'decision' => 'once']),
            self::command('something_new'),
            'this line is not JSON',
            self::command('quit'),
        ]);

        self::assertSame(0, $result['exit'], $result['stderr']);
        self::assertSame(['ready', 'bye'], self::types($result['events']));
        self::assertSame('quit', $result['events'][1]['reason']);
    }

    public function testAPlainLineRunsATurnThatEndsWhenTheServerIsUnreachable(): void
    {
        $this->configure('text');
        $rpc = RpcProcess::start($this->home, $this->cwd);
        self::assertNotNull($rpc->until('ready', 20.0));

        $rpc->send(['type' => 'input', 'text' => 'list the files']);
        $events = $rpc->until('turn_end', 20.0);
        $rpc->send(['type' => 'quit']);
        $bye = $rpc->until('bye', 20.0);
        $exit = $rpc->close();

        self::assertSame(0, $exit);
        self::assertNotNull($events);
        self::assertSame(['turn_start', 'text', 'notice', 'turn_end'], self::types($events));
        $turnEnd = $events[3];
        self::assertSame('error', $turnEnd['status']);
        self::assertStringStartsWith('connection to the model server failed', (string) $turnEnd['reason']);
        self::assertSame([], $turnEnd['changes']);
        self::assertSame(['type' => 'bye', 'reason' => 'quit'], end($bye));
    }

    public function testWithAutoToolModeTheServerIsNotAskedAtStartupAndTheFirstTurnFailsWithItsReason(): void
    {
        $this->configure('auto');
        $rpc = RpcProcess::start($this->home, $this->cwd);
        self::assertNotNull($rpc->until('ready', 20.0));

        $rpc->send(['type' => 'input', 'text' => 'list the files']);
        $events = $rpc->until('turn_end', 20.0);
        $rpc->send(['type' => 'quit']);
        $bye = $rpc->until('bye', 20.0);
        $exit = $rpc->close();

        self::assertSame(0, $exit);
        self::assertNotNull($events);
        self::assertSame('error', $events[array_key_last($events)]['status']);
        self::assertStringStartsWith('connection to the model server failed', (string) $events[array_key_last($events)]['reason']);
        self::assertSame(['type' => 'bye', 'reason' => 'quit'], end($bye));
    }

    public function testASessionSwitchAndANewSessionTellTheModelAndTheEffortInUse(): void
    {
        $this->configure('text');
        (new SessionStore($this->cwd))->save([
            'id' => 'ses-other-model', 'title' => 'another model', 'createdAt' => SessionStore::now(), 'updatedAt' => SessionStore::now(),
            'profile' => 'test', 'model' => 'other', 'thinking' => 'high',
            'messages' => [['role' => 'user', 'content' => 'a question'], ['role' => 'assistant', 'content' => 'an answer']],
        ]);
        $rpc = RpcProcess::start($this->home, $this->cwd);
        self::assertNotNull($rpc->until('ready', 20.0));

        $rpc->send(['type' => 'session_switch', 'id' => 'ses-other-model']);
        $switched = $rpc->until('context', 20.0) ?? [];
        $rpc->send(['type' => 'session_new']);
        $fresh = $rpc->until('context', 20.0) ?? [];
        $rpc->send(['type' => 'quit']);
        self::assertNotNull($rpc->until('bye', 20.0));
        self::assertSame(0, $rpc->close());

        $model = ['type' => 'model_changed', 'profile' => 'test', 'model' => 'other', 'thinking' => 'high', 'saved' => false];
        self::assertSame(['notice', 'sessions', 'model_changed', 'effort', 'context'], self::types($switched));
        self::assertSame([$model, 'high'], [$switched[2], $switched[3]['level']], 'the session switched to has its model and its effort');
        self::assertSame(['sessions', 'model_changed', 'effort', 'context'], self::types($fresh));
        self::assertSame([$model, 'high'], [$fresh[1], $fresh[2]['level']], 'a new session keeps the model and the effort in use');
    }

    private function configure(string $toolMode): void
    {
        RpcProcess::configure($this->home, 'http://127.0.0.1:' . self::closedPort(), $toolMode);
    }

    private function backend(array $lines): array
    {
        $root = dirname(__DIR__, 2);
        $errors = $this->home . '/stderr.txt';
        $env = [
            'PATH' => (string) getenv('PATH'),
            'HOME' => $this->home,
            'XDG_CONFIG_HOME' => $this->home . '/config',
            'XDG_CACHE_HOME' => $this->home . '/cache',
        ];
        $process = proc_open(
            [PHP_BINARY, $root . '/bin/phycode', 'rpc', '--cwd', $this->cwd],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['file', $errors, 'w']],
            $pipes,
            $root,
            $env,
        );
        self::assertIsResource($process);
        @fwrite($pipes[0], implode('', array_map(static fn (string $line): string => $line . "\n", $lines)));
        fclose($pipes[0]);
        stream_set_timeout($pipes[1], 60);
        $stdout = (string) stream_get_contents($pipes[1]);
        $timedOut = stream_get_meta_data($pipes[1])['timed_out'];
        fclose($pipes[1]);
        if ($timedOut) {
            proc_terminate($process);
            proc_close($process);
            self::fail('the backend printed nothing for 60 seconds');
        }
        $exit = proc_close($process);

        return [
            'events' => array_map(
                static fn (string $line): array => json_decode($line, true, 512, JSON_THROW_ON_ERROR),
                array_values(array_filter(explode("\n", $stdout), static fn (string $line): bool => $line !== '')),
            ),
            'exit' => $exit,
            'stderr' => (string) file_get_contents($errors),
        ];
    }

    private static function command(string $type, array $fields = []): string
    {
        return json_encode(['type' => $type] + $fields, JSON_THROW_ON_ERROR);
    }

    private static function types(array $events): array
    {
        return array_column($events, 'type');
    }

    private static function closedPort(): int
    {
        $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $message);
        self::assertIsResource($server, $message);
        $address = (string) stream_socket_get_name($server, false);
        fclose($server);

        return (int) substr($address, (int) strrpos($address, ':') + 1);
    }
}
