<?php

declare(strict_types=1);

namespace Phycode\Browser;

use Phycode\Exec\Backgroundable;
use Phycode\Llm\Image;
use Phycode\Support\Cancellation;
use Phycode\Support\Json;
use Phycode\Support\PhycodeException;
use Phycode\Support\Text;

final class BrowserSession
{
    private const AUTO_ATTACH = [
        'autoAttach' => true,
        'waitForDebuggerOnStart' => true,
        'flatten' => true,
        'filter' => [['type' => 'page'], ['type' => 'iframe'], ['type' => 'worker'], ['type' => 'shared_worker'], ['type' => 'service_worker'], ['exclude' => true]],
    ];
    private const IDLE_SECONDS = 3.0;
    private const SETTLE_SECONDS = 0.35;
    private const CLICK_NAVIGATION_SECONDS = 15.0;
    private const MAX_TYPED_BYTES = 10000;

    private ?ChromeProcess $chrome = null;
    private ?Cdp $cdp = null;
    private ?Cancellation $cancellation = null;
    private array $pages = [];
    private array $sessions = [];
    private array $stack = [];
    private array $popups = [];

    public function __construct(
        private readonly BrowserSettings $settings,
        private readonly BrowserPolicy $policy,
        private readonly Backgroundable $runner,
        private readonly float $startSeconds = 20.0,
        private readonly float $loadSeconds = 30.0,
    ) {
    }

    public function __destruct()
    {
        $this->reset();
    }

    public function cancelWith(Cancellation $cancellation): void
    {
        $this->cancellation = $cancellation;
        if ($this->cdp !== null) {
            $this->cdp->cancellation = $cancellation;
        }
    }

    public function open(string $url): array
    {
        $refusal = $this->policy->refusal($url);
        if ($refusal !== null) {
            throw new PhycodeException($refusal);
        }
        $this->start();
        if (count($this->stack) >= $this->settings->maxPages) {
            throw new PhycodeException("{$this->settings->maxPages} pages are open, which is browser.max_pages; close one with action=close first");
        }
        $targetId = (string) ($this->cdp->call('Target.createTarget', ['url' => 'about:blank'])['targetId'] ?? '');
        if ($targetId === '') {
            throw new PhycodeException('the browser did not create a page');
        }
        $page = $this->pages[$targetId] ??= new BrowserPage($targetId);
        $this->stack[] = $targetId;
        try {
            if (!$this->cdp->waitFor(static fn (): bool => $page->sessionId !== null, 10.0)) {
                throw new PhycodeException('the browser did not attach to the new page');
            }
            $navigation = $this->cdp->call('Page.navigate', ['url' => $url], $page->sessionId, $this->loadSeconds);
            if (isset($navigation['errorText'])) {
                throw new PhycodeException($this->failure($page, (string) $navigation['errorText']));
            }
            $loaderId = (string) ($navigation['loaderId'] ?? '');
            if ($loaderId !== '') {
                $page->started[$loaderId] = true;
                $this->cdp->waitFor(static fn (): bool => !$page->loading(), $this->loadSeconds);
                $this->cdp->waitFor(static fn (): bool => !$page->busy(), self::IDLE_SECONDS);
            }
            $described = $this->evaluate($page, PageScripts::DESCRIBE);
        } catch (PhycodeException $error) {
            $this->discard($page);

            throw $error;
        }

        $status = (int) ($described['status'] ?? 0);

        return ['url' => (string) ($described['url'] ?? $url), 'title' => (string) ($described['title'] ?? ''), 'status' => $status > 0 ? $status : null, 'loaded' => !$page->loading(), 'blocked' => $page->blocked];
    }

    public function read(): array
    {
        $page = $this->current();
        $this->cdp->pump(0.05);

        return $this->evaluate($page, PageScripts::DESCRIBE);
    }

    public function screenshot(): Image
    {
        $page = $this->current();
        $png = base64_decode((string) ($this->cdp->call('Page.captureScreenshot', ['format' => 'png'], $page->sessionId)['data'] ?? ''), true);
        if ($png !== false && strlen($png) <= Image::MAX_BYTES) {
            return Image::fromBytes($png, 'screenshot.png');
        }
        $jpeg = base64_decode((string) ($this->cdp->call('Page.captureScreenshot', ['format' => 'jpeg', 'quality' => 70], $page->sessionId)['data'] ?? ''), true);

        return Image::fromBytes($jpeg === false ? '' : $jpeg, 'screenshot.jpg');
    }

    public function click(string $selector): array
    {
        $page = $this->current();
        $point = $this->evaluate($page, PageScripts::call(PageScripts::POINT, $selector));
        if (isset($point['error'])) {
            throw new PhycodeException((string) $point['error']);
        }
        $popups = $page->popups;
        $at = ['x' => $point['x'] ?? 0, 'y' => $point['y'] ?? 0];
        $this->cdp->call('Input.dispatchMouseEvent', ['type' => 'mouseMoved'] + $at, $page->sessionId);
        $this->cdp->call('Input.dispatchMouseEvent', ['type' => 'mousePressed', 'button' => 'left', 'buttons' => 1, 'clickCount' => 1] + $at, $page->sessionId);
        $this->cdp->call('Input.dispatchMouseEvent', ['type' => 'mouseReleased', 'button' => 'left', 'buttons' => 0, 'clickCount' => 1] + $at, $page->sessionId);
        $this->cdp->pump(self::SETTLE_SECONDS);
        $this->closePopups();
        $this->cdp->waitFor(static fn (): bool => !$page->loading(), self::CLICK_NAVIGATION_SECONDS);
        $this->cdp->waitFor(static fn (): bool => !$page->busy(), self::IDLE_SECONDS);

        $described = $this->evaluate($page, PageScripts::DESCRIBE);

        return [
            'url' => (string) ($described['url'] ?? ''),
            'title' => (string) ($described['title'] ?? ''),
            'note' => $page->popups > $popups ? 'The click opened a new window, which was closed: use action open with its address.' : '',
        ];
    }

    public function type(string $selector, string $text): int
    {
        if (strlen($text) > self::MAX_TYPED_BYTES) {
            throw new PhycodeException('the text is longer than ' . self::MAX_TYPED_BYTES . ' bytes; type it in pieces');
        }
        $page = $this->current();
        $focus = $this->evaluate($page, PageScripts::call(PageScripts::FOCUS, $selector));
        if (isset($focus['error'])) {
            throw new PhycodeException((string) $focus['error']);
        }
        $this->cdp->call('Input.insertText', ['text' => $text], $page->sessionId);
        $this->cdp->pump(0.1);

        return mb_strlen($text);
    }

    public function console(): array
    {
        $page = $this->current();
        $this->cdp->pump(0.2);

        return ['entries' => $page->console, 'omitted' => $page->consoleOmitted, 'blocked' => $page->blocked];
    }

    public function close(): int
    {
        $this->discard($this->current());
        if ($this->stack === []) {
            $this->reset();
        }

        return count($this->stack);
    }

    public function reset(): void
    {
        $chrome = $this->chrome;
        $cdp = $this->cdp;
        [$this->chrome, $this->cdp, $this->pages, $this->sessions, $this->stack, $this->popups] = [null, null, [], [], [], []];
        try {
            $cdp?->close();
        } catch (PhycodeException) {
        }
        $chrome?->stop();
    }

    private function start(): void
    {
        if ($this->chrome !== null && $this->chrome->running() && $this->cdp?->isOpen()) {
            return;
        }
        $this->reset();
        $binary = $this->settings->binary() ?? throw new PhycodeException('no Chrome or Chromium was found: install one, or set browser.command to its path');
        $this->chrome = ChromeProcess::start($binary, $this->runner, $this->settings->noSandbox, $this->startSeconds);
        try {
            $this->cdp = new Cdp(WebSocket::connect($this->chrome->webSocketUrl));
            $this->cdp->cancellation = $this->cancellation;
            $this->cdp->listen($this->onEvent(...));
            $this->cdp->send('Browser.setDownloadBehavior', ['behavior' => 'deny']);
            $this->cdp->call('Target.setAutoAttach', self::AUTO_ATTACH);
        } catch (\Throwable $error) {
            $this->reset();

            throw $error;
        }
    }

    private function current(): BrowserPage
    {
        if ($this->chrome === null || !$this->chrome->running() || !$this->cdp?->isOpen()) {
            $this->reset();
        }
        $targetId = end($this->stack);
        if ($targetId === false) {
            throw new PhycodeException('no page is open: use action=open with a url first');
        }
        $this->closePopups();

        return $this->pages[$targetId];
    }

    private function closePopups(): void
    {
        foreach ($this->popups as $targetId) {
            $this->cdp->send('Target.closeTarget', ['targetId' => $targetId]);
        }
    }

    private function discard(BrowserPage $page): void
    {
        $this->stack = array_values(array_diff($this->stack, [$page->targetId]));
        unset($this->pages[$page->targetId]);
        if ($page->sessionId !== null) {
            unset($this->sessions[$page->sessionId]);
        }
        try {
            $this->cdp?->call('Target.closeTarget', ['targetId' => $page->targetId], null, 5.0);
        } catch (PhycodeException) {
        }
    }

    private function evaluate(BrowserPage $page, string $expression): array
    {
        $result = $this->cdp->call('Runtime.evaluate', ['expression' => $expression, 'returnByValue' => true], $page->sessionId);
        if (isset($result['exceptionDetails'])) {
            throw new PhycodeException('the script in the page failed: ' . Text::oneLine((string) ($result['exceptionDetails']['exception']['description'] ?? $result['exceptionDetails']['text'] ?? 'unknown error'), 200));
        }
        $value = $result['result']['value'] ?? null;

        return is_array($value) ? $value : [];
    }

    private function failure(BrowserPage $page, string $errorText): string
    {
        if ($page->blocked !== []) {
            return 'blocked: ' . reset($page->blocked);
        }

        return "the page did not load: {$errorText}" . ($errorText === 'net::ERR_CONNECTION_REFUSED' ? ' (nothing listens at that address; is the server running?)' : '');
    }

    private function onEvent(string $method, array $params, ?string $sessionId): void
    {
        if ($method === 'Target.attachedToTarget') {
            $this->attached($params);

            return;
        }
        if ($method === 'Fetch.requestPaused') {
            $this->paused($params, (string) $sessionId);

            return;
        }
        if ($method === 'Target.detachedFromTarget') {
            unset($this->sessions[(string) ($params['sessionId'] ?? '')], $this->popups[(string) ($params['sessionId'] ?? '')]);

            return;
        }
        $page = $sessionId === null ? null : ($this->sessions[$sessionId] ?? null);
        if ($page === null) {
            return;
        }
        match ($method) {
            'Runtime.consoleAPICalled' => $this->consoleCall($page, $params),
            'Runtime.exceptionThrown' => $this->exception($page, is_array($params['exceptionDetails'] ?? null) ? $params['exceptionDetails'] : []),
            'Log.entryAdded' => $this->logged($page, is_array($params['entry'] ?? null) ? $params['entry'] : []),
            'Page.javascriptDialogOpening' => $this->dialog($page, $params),
            'Page.lifecycleEvent' => $this->lifecycle($page, $params),
            default => null,
        };
    }

    private function attached(array $params): void
    {
        $info = is_array($params['targetInfo'] ?? null) ? $params['targetInfo'] : [];
        $sessionId = (string) ($params['sessionId'] ?? '');
        $targetId = (string) ($info['targetId'] ?? '');
        $type = (string) ($info['type'] ?? '');
        if ($sessionId === '' || $targetId === '') {
            return;
        }
        $popup = $type === 'page' && isset($info['openerId']);
        if ($popup) {
            $this->popups[$sessionId] = $targetId;
            $opener = end($this->stack);
            if ($opener !== false) {
                $this->pages[$opener]->popups++;
            }
        }
        $this->cdp->send('Fetch.enable', ['patterns' => [['urlPattern' => '*']]], $sessionId, function (array $result, ?string $error) use ($targetId): void {
            if ($error !== null) {
                $this->cdp->send('Target.closeTarget', ['targetId' => $targetId]);
            }
        });
        $this->cdp->send('Target.setAutoAttach', self::AUTO_ATTACH, $sessionId);
        if ($type === 'page' && !$popup) {
            $page = $this->pages[$targetId] ??= new BrowserPage($targetId);
            $page->sessionId = $sessionId;
            $this->sessions[$sessionId] = $page;
            foreach (['Page.enable', 'Runtime.enable', 'Log.enable'] as $domain) {
                $this->cdp->send($domain, [], $sessionId);
            }
            $this->cdp->send('Page.setLifecycleEventsEnabled', ['enabled' => true], $sessionId);
        }
        $this->cdp->send('Runtime.runIfWaitingForDebugger', [], $sessionId);
    }

    private function paused(array $params, string $sessionId): void
    {
        $url = (string) ($params['request']['url'] ?? '');
        $requestId = (string) ($params['requestId'] ?? '');
        if (isset($this->popups[$sessionId])) {
            $this->cdp->send('Fetch.failRequest', ['requestId' => $requestId, 'errorReason' => 'BlockedByClient'], $sessionId);

            return;
        }
        $refusal = $this->policy->refusal($url, true);
        if ($refusal === null) {
            $this->cdp->send('Fetch.continueRequest', ['requestId' => $requestId], $sessionId);

            return;
        }
        $this->cdp->send('Fetch.failRequest', ['requestId' => $requestId, 'errorReason' => 'BlockedByClient'], $sessionId);
        $current = end($this->stack);
        if ($current !== false) {
            $this->pages[$current]->block(self::shown($url), $refusal);
        }
    }

    private function consoleCall(BrowserPage $page, array $params): void
    {
        $type = (string) ($params['type'] ?? '');
        if (!in_array($type, ['error', 'warning', 'assert'], true)) {
            return;
        }
        $arguments = is_array($params['args'] ?? null) ? $params['args'] : [];
        $text = implode(' ', array_map(static function (mixed $object): string {
            $value = is_array($object) ? ($object['value'] ?? $object['unserializableValue'] ?? $object['description'] ?? $object['type'] ?? '') : '';

            return is_string($value) ? $value : Json::encode($value);
        }, $arguments));
        $frame = $params['stackTrace']['callFrames'][0] ?? null;
        $page->log($type === 'warning' ? 'warning' : 'error', Text::oneLine(($type === 'assert' ? 'Assertion failed: ' : '') . $text . self::where($frame), 500));
    }

    private function exception(BrowserPage $page, array $details): void
    {
        $description = (string) ($details['exception']['description'] ?? $details['text'] ?? 'exception');
        $page->log('error', Text::oneLine('Uncaught ' . strtok($description, "\n") . self::where($details), 500));
    }

    private function logged(BrowserPage $page, array $entry): void
    {
        $level = (string) ($entry['level'] ?? '');
        $text = (string) ($entry['text'] ?? '');
        if (($level === 'error' || $level === 'warning') && !str_contains($text, 'ERR_BLOCKED_BY_CLIENT')) {
            $page->log($level, Text::oneLine($text . self::where($entry), 500));
        }
    }

    private function dialog(BrowserPage $page, array $params): void
    {
        $type = (string) ($params['type'] ?? 'alert');
        $this->cdp->send('Page.handleJavaScriptDialog', ['accept' => $type === 'beforeunload'], $page->sessionId);
        $page->log('warning', Text::oneLine("a {$type} dialog was dismissed: " . (string) ($params['message'] ?? ''), 300));
    }

    private function lifecycle(BrowserPage $page, array $params): void
    {
        if (($params['frameId'] ?? '') !== $page->targetId) {
            return;
        }
        $loaderId = (string) ($params['loaderId'] ?? '');
        match ($params['name'] ?? '') {
            'init' => $page->started[$loaderId] = true,
            'load' => $page->finished[$loaderId] = true,
            'networkIdle' => $page->idle[$loaderId] = true,
            default => null,
        };
    }

    private static function where(mixed $location): string
    {
        $url = is_array($location) ? ($location['url'] ?? '') : '';
        if (!is_string($url) || $url === '') {
            return '';
        }

        return ' (' . self::shown($url) . ':' . ((int) ($location['lineNumber'] ?? 0) + 1) . ')';
    }

    private static function shown(string $url): string
    {
        return Text::oneLine((string) preg_replace('/[?#].*$/s', '', $url), 160);
    }
}
