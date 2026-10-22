<?php

declare(strict_types=1);

namespace Phycode\Tests\Unit\Browser;

use Phycode\Browser\BrowserPolicy;
use Phycode\Browser\BrowserSession;
use Phycode\Browser\BrowserSettings;
use Phycode\Exec\ProcessRunner;
use Phycode\Tests\Support\Kit;
use Phycode\Tests\Support\RecordingUi;
use Phycode\Tests\Unit\Web\FixtureServer;
use Phycode\Tools\BrowserTool;
use Phycode\Tools\ToolContext;
use Phycode\Tools\ToolResult;
use PHPUnit\Framework\TestCase;

final class RealBrowserTest extends TestCase
{
    private ?FixtureServer $app = null;
    private ?FixtureServer $forbidden = null;
    private ?BrowserSession $session = null;
    private ?BrowserTool $tool = null;
    private ToolContext $context;
    private ?string $binary = null;

    protected function setUp(): void
    {
        $this->binary = (new BrowserSettings(true))->binary();
        if ($this->binary === null) {
            self::markTestSkipped('no Chrome or Chromium on the PATH');
        }
        $this->context = Kit::context(Kit::folder('real-browser'), Kit::agent(), Kit::policy('yolo'), new RecordingUi());
    }

    protected function tearDown(): void
    {
        $this->session?->reset();
        $this->app?->stop();
        $this->forbidden?->stop();
    }

    private function boot(array $routes = []): void
    {
        $this->forbidden = new FixtureServer(['/frame' => [['type' => 'text/html', 'body' => 'secret frame']], '/pic.png' => [['body' => 'x']], '/xhr' => [['body' => 'x']], '/landed' => [['body' => 'landed']]]);
        $other = $this->forbidden->port();
        $page = '<!doctype html><html><head><title>Real page</title></head><body><h1>Hello real browser</h1>'
            . '<input id="q"><div id="echo"></div>'
            . '<button id="btn" onclick="document.title=\'clicked\'">Go</button> <a id="next" href="/next">next</a> <button id="pop" onclick="window.open(\'/popup\')">pop</button>'
            . "<iframe src=\"http://localhost:{$other}/frame\"></iframe><img src=\"http://localhost:{$other}/pic.png\">"
            . '<script>document.getElementById("q").addEventListener("input", function (e) { document.getElementById("echo").textContent = "typed:" + e.target.value; });'
            . 'console.error("real boom"); console.warn("real warn"); console.log("quiet");'
            . "fetch('http://localhost:{$other}/xhr').catch(function () { console.error('xhr failed'); });"
            . 'setTimeout(function () { throw new Error("late failure"); }, 20);</script></body></html>';
        $this->app = new FixtureServer($routes + [
            '/' => [['type' => 'text/html', 'body' => $page]],
            '/next' => [['type' => 'text/html', 'body' => '<title>Next page</title><p>second page</p>']],
            '/popup' => [['type' => 'text/html', 'body' => '<title>Popup</title>']],
            '/redirect' => [['status' => 302, 'headers' => ['Location' => "http://localhost:{$other}/landed"], 'body' => '']],
            '/missing' => [['status' => 404, 'type' => 'text/html', 'body' => '<title>Gone</title>nothing here']],
            '/alert' => [['type' => 'text/html', 'body' => '<script>alert("hi"); document.title = "after alert";</script>']],
        ]);
        $settings = new BrowserSettings(true, $this->binary, ['127.0.0.1'], 3, true);
        $policy = new BrowserPolicy($settings->allowHosts);
        $this->session = new BrowserSession($settings, $policy, new ProcessRunner());
        $this->tool = new BrowserTool($this->session, $policy, static fn (string $profile): bool => true);
    }

    private function call(array $arguments): ToolResult
    {
        return $this->tool->execute($arguments, $this->context);
    }

    private function paths(FixtureServer $server): array
    {
        return array_column($server->requests(), 'path');
    }

    public function testAPageIsOpenedReadClickedTypedAndPhotographed(): void
    {
        $this->boot();

        $open = $this->call(['action' => 'open', 'url' => $this->app->url('/')]);
        self::assertTrue($open->ok, $open->content);
        self::assertStringContainsString("Title: Real page\nHTTP status: 200", $open->content);

        $text = $this->call(['action' => 'text']);
        self::assertStringContainsString('Hello real browser', $text->content);

        $typed = $this->call(['action' => 'type', 'selector' => '#q', 'text' => 'héllo']);
        self::assertTrue($typed->ok, $typed->content);
        self::assertStringContainsString('typed:héllo', $this->call(['action' => 'text'])->content);

        $clicked = $this->call(['action' => 'click', 'selector' => '#btn']);
        self::assertStringContainsString('Title: clicked', $clicked->content);

        $shot = $this->call(['action' => 'screenshot']);
        self::assertTrue($shot->ok, $shot->content);
        $image = $shot->meta['images'][0];
        self::assertSame('image/png', $image['mime']);
        self::assertStringStartsWith("\x89PNG", (string) base64_decode($image['data']));
        self::assertGreaterThan(600, $image['width']);
        self::assertGreaterThan(300, $image['height']);

        $moved = $this->call(['action' => 'click', 'selector' => '#next']);
        self::assertStringContainsString('Now at ' . $this->app->url('/next') . "\nTitle: Next page", $moved->content);
        self::assertStringContainsString('second page', $this->call(['action' => 'text'])->content);
    }

    public function testConsoleErrorsAreReadAndRequestsToARefusedAddressNeverLeave(): void
    {
        $this->boot();
        $this->call(['action' => 'open', 'url' => $this->app->url('/')]);

        $console = $this->call(['action' => 'console']);

        self::assertStringContainsString('[error] real boom', $console->content);
        self::assertStringContainsString('[warning] real warn', $console->content);
        self::assertStringContainsString('[error] Uncaught Error: late failure', $console->content);
        self::assertStringContainsString('[error] xhr failed', $console->content);
        self::assertStringNotContainsString('quiet', $console->content);
        self::assertStringContainsString('Requests blocked because their address is refused:', $console->content);
        foreach (['/frame', '/pic.png', '/xhr'] as $path) {
            self::assertStringContainsString('localhost:' . $this->forbidden->port() . $path . ': refused: localhost resolves to 127.0.0.1', $console->content);
        }
        self::assertSame([], $this->forbidden->requests(), 'the page, its iframe, its image and its fetch reached nothing');
    }

    public function testARedirectToARefusedAddressIsBlockedBeforeItIsFollowed(): void
    {
        $this->boot();

        $result = $this->call(['action' => 'open', 'url' => $this->app->url('/redirect')]);

        self::assertFalse($result->ok);
        self::assertStringContainsString('blocked: refused: localhost resolves to 127.0.0.1', $result->content);
        self::assertSame([], $this->forbidden->requests());
    }

    public function testAWindowOpenedByAClickLoadsNothingAndIsClosed(): void
    {
        $this->boot();
        $this->call(['action' => 'open', 'url' => $this->app->url('/')]);

        $result = $this->call(['action' => 'click', 'selector' => '#pop']);

        self::assertTrue($result->ok, $result->content);
        self::assertStringContainsString('The click opened a new window, which was closed', $result->content);
        usleep(300000);
        self::assertNotContains('/popup', $this->paths($this->app));
    }

    public function testStatusesFailuresAndDialogsAreReportedWithoutHanging(): void
    {
        $this->boot();

        $missing = $this->call(['action' => 'open', 'url' => $this->app->url('/missing')]);
        self::assertTrue($missing->ok, $missing->content);
        self::assertStringContainsString("Title: Gone\nHTTP status: 404", $missing->content);

        $alert = $this->call(['action' => 'open', 'url' => $this->app->url('/alert')]);
        self::assertTrue($alert->ok, $alert->content);
        self::assertStringContainsString('Title: after alert', $alert->content);
        self::assertStringContainsString('a alert dialog was dismissed: hi', $this->call(['action' => 'console'])->content);

        $probe = stream_socket_server('tcp://127.0.0.1:0');
        $name = (string) stream_socket_get_name($probe, false);
        fclose($probe);
        $refused = $this->call(['action' => 'open', 'url' => 'http://' . $name . '/']);
        self::assertFalse($refused->ok);
        self::assertStringContainsString('net::ERR_CONNECTION_REFUSED', $refused->content);
    }

    public function testThePrivateAddressesAreRefusedWithoutStartingAnything(): void
    {
        $this->boot();

        foreach (['http://169.254.169.254/latest/meta-data/', 'http://10.0.0.1/', 'http://[::1]/'] as $url) {
            self::assertFalse($this->call(['action' => 'open', 'url' => $url])->ok, $url);
        }
        self::assertSame([], $this->browsers());
    }

    public function testTheBrowserAndItsFolderAreGoneWhenTheLastPageIsClosed(): void
    {
        $this->boot();
        $this->call(['action' => 'open', 'url' => $this->app->url('/next')]);
        $profiles = $this->browsers();
        self::assertNotSame([], $profiles, 'the browser runs while a page is open');
        foreach ($profiles as $profile) {
            self::assertSame('700', decoct(fileperms($profile) & 0777));
        }

        $this->call(['action' => 'close']);

        $this->waitUntilGone($profiles);
        self::assertSame([], $this->browsers());
        clearstatcache();
        foreach ($profiles as $profile) {
            self::assertDirectoryDoesNotExist($profile);
        }
    }

    public function testTheBrowserDoesNotOutliveAProcessThatIsKilled(): void
    {
        $this->boot();
        $child = proc_open([PHP_BINARY, dirname(__DIR__, 2) . '/Support/browser-child.php', (string) $this->binary, 'wait', 'no-sandbox', $this->app->url('/next')], [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
        try {
            self::assertSame("ready\n", fgets($pipes[1]));
            $profiles = $this->browsers();
            self::assertNotSame([], $profiles);

            posix_kill(proc_get_status($child)['pid'], SIGKILL);

            $this->waitUntilGone($profiles);
            self::assertSame([], $this->browsers(), 'no browser process is left');
            clearstatcache();
            foreach ($profiles as $profile) {
                self::assertDirectoryDoesNotExist($profile);
            }
        } finally {
            proc_terminate($child, SIGKILL);
            proc_close($child);
        }
    }

    private function browsers(): array
    {
        $profiles = [];
        foreach (Kit::commandLines() as $line) {
            foreach (explode(' ', $line) as $argument) {
                if (str_starts_with($argument, '--user-data-dir=' . rtrim(sys_get_temp_dir(), '/') . '/phycode-browser-') && is_dir(substr($argument, 16))) {
                    $profiles[substr($argument, 16)] = substr($argument, 16);
                }
            }
        }

        return array_values($profiles);
    }

    private function folders(array $profiles): bool
    {
        clearstatcache();

        return array_filter($profiles, 'is_dir') !== [];
    }

    private function waitUntilGone(array $profiles): void
    {
        $deadline = microtime(true) + 10.0;
        while (($this->browsers() !== [] || $this->folders($profiles)) && microtime(true) < $deadline) {
            usleep(100000);
        }
        clearstatcache();
    }
}
