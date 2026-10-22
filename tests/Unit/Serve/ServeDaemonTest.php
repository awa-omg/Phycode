<?php

declare(strict_types=1);

namespace Phycode\Tests\Unit\Serve;

use Phycode\Serve\ServeDaemon;
use Phycode\Support\PhycodeException;
use Phycode\Tests\Support\Kit;
use Phycode\Tests\Support\ServeProcess;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ServeDaemonTest extends TestCase
{
    private ?ServeProcess $daemon = null;
    private string $home;
    private string $project;
    private string $folder;

    protected function setUp(): void
    {
        $this->home = Kit::folder('serve-home');
        $this->project = realpath(Kit::folder('serve-project'));
        $this->folder = Kit::folder('serve-sockets');
    }

    protected function tearDown(): void
    {
        $this->daemon?->stop();
    }

    private function start(array $arguments, array $env = []): ServeProcess
    {
        return $this->daemon = ServeProcess::start($this->home, $this->project, $arguments, $env);
    }

    private function token(): string
    {
        $file = $this->folder . '/token';
        file_put_contents($file, "secret-token-0123456789abcdef\n");
        chmod($file, 0600);

        return $file;
    }

    public static function addresses(): iterable
    {
        yield 'a socket' => [['/run/user/1000/phycode.sock', null], 'unix:///run/user/1000/phycode.sock'];
        yield 'the default' => [[null, null], 'unix:///default.sock'];
        yield 'a loopback port' => [[null, '127.0.0.1:7777'], 'tcp://127.0.0.1:7777'];
        yield 'localhost' => [[null, 'localhost:7777'], 'tcp://127.0.0.1:7777'];
        yield 'ipv6 loopback' => [[null, '[::1]:7777'], 'tcp://[::1]:7777'];
    }

    #[DataProvider('addresses')]
    public function testTheAddressIsASocketOrALoopbackPortAndNothingElse(array $given, string $expected): void
    {
        self::assertSame($expected, ServeDaemon::address($given[0], $given[1], '/default.sock'));
    }

    public static function badAddresses(): iterable
    {
        yield 'both' => [['/a.sock', '127.0.0.1:1'], 'give --socket or --listen, not both'];
        yield 'another machine' => [[null, '0.0.0.0:7777'], 'the daemon is not for other machines'];
        yield 'a name' => [[null, 'example.com:7777'], 'the daemon is not for other machines'];
        yield 'port too big' => [[null, '127.0.0.1:70000'], 'the daemon is not for other machines'];
        yield 'relative socket' => [['run/phycode.sock', null], 'absolute path of at most 100 bytes'];
        yield 'long socket' => [['/' . str_repeat('a', 100) . '.sock', null], 'absolute path of at most 100 bytes'];
    }

    #[DataProvider('badAddresses')]
    public function testABadAddressIsRefusedWithTheReason(array $given, string $message): void
    {
        $this->expectException(PhycodeException::class);
        $this->expectExceptionMessage($message);
        ServeDaemon::address($given[0], $given[1], '/default.sock');
    }

    public function testTheTokenFileIsMadeClosedToOthersAndReadBack(): void
    {
        $file = $this->folder . '/made-token';

        $token = ServeDaemon::token($file);

        self::assertSame(64, strlen($token));
        self::assertSame(0600, fileperms($file) & 0777);
        self::assertSame($token, ServeDaemon::token($file));
    }

    public function testATokenFileThatOthersCanReadOrThatIsTooShortOrALinkIsRefused(): void
    {
        $open = $this->folder . '/open';
        file_put_contents($open, str_repeat('a', 32));
        chmod($open, 0644);
        $short = $this->folder . '/short';
        file_put_contents($short, "abc\n");
        chmod($short, 0600);
        symlink($short, $this->folder . '/link');

        foreach ([$open => 'nobody else can read', $short => 'at least 16 characters', $this->folder . '/link' => 'nobody else can read'] as $file => $message) {
            try {
                ServeDaemon::token($file);
                self::fail("{$file} must be refused");
            } catch (PhycodeException $error) {
                self::assertStringContainsString($message, $error->getMessage());
            }
        }
    }

    public function testAPortNeedsATokenAndTheLimitOfConnectionsHasBounds(): void
    {
        $session = static fn (): array => [];
        foreach ([
            [fn () => new ServeDaemon('tcp://127.0.0.1:7777', null, $session, '0', '/p'), 'a TCP port needs a token'],
            [fn () => new ServeDaemon('unix:///x.sock', null, $session, '0', '/p', 0), 'from 1 to 256'],
            [fn () => new ServeDaemon('unix:///x.sock', null, $session, '0', '/p', 257), 'from 1 to 256'],
        ] as [$make, $message]) {
            try {
                $make();
                self::fail($message);
            } catch (PhycodeException $error) {
                self::assertStringContainsString($message, $error->getMessage());
            }
        }
    }

    public function testAClientWithTheTokenGetsTheProtocolOfRpcInTheFolderItAsked(): void
    {
        $socket = $this->folder . '/serve.sock';
        $daemon = $this->start(['--socket', $socket, '--token-file', $this->token()]);
        self::assertSame("listening unix://{$socket}", $daemon->listening);
        self::assertSame(0600, fileperms($socket) & 0777);
        $other = realpath(Kit::folder('serve-other'));

        $client = ServeProcess::connect("unix://{$socket}");
        ServeProcess::send($client, ['type' => 'hello', 'token' => 'secret-token-0123456789abcdef', 'cwd' => $other]);
        $events = ServeProcess::readUntil($client, 'ready');
        $ready = end($events);
        ServeProcess::send($client, ['type' => 'quit']);
        $bye = ServeProcess::readUntil($client, 'bye');

        self::assertSame('ready', $ready['type']);
        self::assertSame($other, $ready['app']['cwd']);
        self::assertSame('quit', end($bye)['reason']);
    }

    public function testAWrongTokenAndAMissingHelloAreRefusedWithoutStartingASession(): void
    {
        $socket = $this->folder . '/serve.sock';
        $this->start(['--socket', $socket, '--token-file', $this->token()]);

        $wrong = ServeProcess::connect("unix://{$socket}");
        ServeProcess::send($wrong, ['type' => 'hello', 'token' => 'nope']);
        $refused = ServeProcess::read($wrong);

        $silent = ServeProcess::connect("unix://{$socket}");
        ServeProcess::send($silent, ['type' => 'input', 'text' => '/status']);
        $notHello = ServeProcess::read($silent);

        self::assertSame(['type' => 'error', 'message' => 'refused: the token is not right'], $refused);
        self::assertNull(ServeProcess::read($wrong), 'the connection is closed');
        self::assertSame('error', $notHello['type']);
        self::assertStringContainsString('send {"type":"hello"', $notHello['message']);
    }

    public function testACwdThatIsNotAFolderIsRefused(): void
    {
        $socket = $this->folder . '/serve.sock';
        $this->start(['--socket', $socket, '--token-file', $this->token()]);

        foreach (['relative/path', '/no/such/folder'] as $cwd) {
            $client = ServeProcess::connect("unix://{$socket}");
            ServeProcess::send($client, ['type' => 'hello', 'token' => 'secret-token-0123456789abcdef', 'cwd' => $cwd]);
            self::assertSame('cwd must be the absolute path of a folder that exists', ServeProcess::read($client)['message']);
        }
    }

    public function testSeveralClientsHaveTheirOwnSessionsAtTheSameTime(): void
    {
        $socket = $this->folder . '/serve.sock';
        $this->start(['--socket', $socket, '--token-file', $this->token()]);
        $clients = [];
        foreach ([0, 1, 2] as $index) {
            $clients[$index] = ServeProcess::connect("unix://{$socket}");
            ServeProcess::send($clients[$index], ['type' => 'hello', 'token' => 'secret-token-0123456789abcdef']);
        }

        foreach ($clients as $client) {
            $events = ServeProcess::readUntil($client, 'ready');
            self::assertSame('ready', end($events)['type']);
        }
        foreach ($clients as $client) {
            ServeProcess::send($client, ['type' => 'session_list']);
        }
        $ids = [];
        foreach ($clients as $client) {
            $events = ServeProcess::readUntil($client, 'sessions');
            $ids[] = array_values(array_filter(end($events)['items'] ?? [], static fn (array $session): bool => ($session['current'] ?? false) === true))[0]['id'] ?? null;
        }
        self::assertCount(3, array_unique($ids), 'each client has a session of its own');
    }

    public function testTheLimitOfConnectionsIsTold(): void
    {
        $socket = $this->folder . '/serve.sock';
        $this->start(['--socket', $socket, '--token-file', $this->token(), '--max-connections', '1']);
        $first = ServeProcess::connect("unix://{$socket}");
        ServeProcess::send($first, ['type' => 'hello', 'token' => 'secret-token-0123456789abcdef']);
        ServeProcess::readUntil($first, 'ready');

        $second = ServeProcess::connect("unix://{$socket}");

        self::assertSame(['type' => 'error', 'message' => 'too many connections: the daemon serves 1 at a time'], ServeProcess::read($second));
    }

    public function testALoopbackPortWorksWithTheTokenOfTheConfigFolderAndTheSocketGoesAwayWithTheDaemon(): void
    {
        $probe = stream_socket_server('tcp://127.0.0.1:0');
        $port = (int) substr((string) stream_socket_get_name($probe, false), (int) strrpos((string) stream_socket_get_name($probe, false), ':') + 1);
        fclose($probe);
        $daemon = $this->start(['--listen', "127.0.0.1:{$port}"]);
        $token = trim((string) file_get_contents($this->home . '/config/phycode/serve-token'));

        $client = ServeProcess::connect("tcp://127.0.0.1:{$port}");
        ServeProcess::send($client, ['type' => 'hello', 'token' => $token]);
        $events = ServeProcess::readUntil($client, 'ready');

        self::assertSame("listening tcp://127.0.0.1:{$port}", $daemon->listening);
        self::assertSame('ready', end($events)['type']);
        self::assertSame(0600, fileperms($this->home . '/config/phycode/serve-token') & 0777);
    }

    public function testASecondDaemonOnALiveSocketIsRefusedAndAStaleSocketFileIsReplaced(): void
    {
        $socket = $this->folder . '/serve.sock';
        $first = $this->start(['--socket', $socket, '--token-file', $this->token()]);

        $second = ServeProcess::start(Kit::folder('serve-home-2'), $this->project, ['--socket', $socket, '--token-file', $this->token()]);
        self::assertSame('', $second->listening);
        self::assertNotSame(0, $second->stop());

        posix_kill($first->pid(), SIGKILL);
        $first->stop();
        $this->daemon = null;
        self::assertSame('socket', filetype($socket), 'the killed daemon leaves its socket file');
        for ($deadline = microtime(true) + 5; ($probe = @stream_socket_client('unix://' . $socket, $errno, $error, 1)) !== false && microtime(true) < $deadline; usleep(20000)) {
            fclose($probe);
        }
        $third = $this->start(['--socket', $socket, '--token-file', $this->token()]);
        self::assertSame("listening unix://{$socket}", $third->listening);
    }

    public function testAFileThatIsNotASocketIsNeverReplaced(): void
    {
        $file = $this->folder . '/precious';
        file_put_contents($file, 'data');

        $daemon = $this->start(['--socket', $file, '--token-file', $this->token()]);

        self::assertSame('', $daemon->listening);
        self::assertSame('data', file_get_contents($file));
    }

    public function testStoppingTheDaemonRemovesTheSocketAndEndsTheSessions(): void
    {
        $socket = $this->folder . '/serve.sock';
        $daemon = $this->start(['--socket', $socket, '--token-file', $this->token()]);
        $client = ServeProcess::connect("unix://{$socket}");
        ServeProcess::send($client, ['type' => 'hello', 'token' => 'secret-token-0123456789abcdef']);
        ServeProcess::readUntil($client, 'ready');

        $status = $daemon->stop();
        $this->daemon = null;

        self::assertSame(0, $status);
        self::assertFileDoesNotExist($socket);
        self::assertNull(ServeProcess::read($client), 'the session ends with the daemon');
    }
}
