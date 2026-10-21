<?php

declare(strict_types=1);

namespace Phycode\Tests\Unit\Cli;

use Phycode\Tests\Support\Kit;
use Phycode\Tests\Unit\Llama\LlamaFake;
use PHPUnit\Framework\TestCase;

final class DoctorTest extends TestCase
{
    private ?LlamaFake $server = null;

    protected function tearDown(): void
    {
        $this->server?->stop();
    }

    public function testASocketProfileIsCheckedThroughTheSocketAndPasses(): void
    {
        $this->server = new LlamaFake($this->healthy(), socket: true);

        $run = $this->doctor(['base_url' => $this->server->url(), 'model' => 'qwen35-2b']);

        self::assertSame(0, $run->status, $run->stdout);
        self::assertNull(self::line($run->stdout, 'server reachable'), 'the socket answers, so the server is not unreachable');
        self::assertSame(['ok', 'HTTP 200'], self::line($run->stdout, 'server health'));
        self::assertSame(['ok', 'qwen35-2b'], self::line($run->stdout, 'model listed'));
        self::assertSame(['--', 'no (single-model server)'], self::line($run->stdout, 'router mode'), 'the models key of a single-model server is no sign of a router');
        self::assertSame(['ok', 'native'], self::line($run->stdout, 'tool calling'));
        foreach ($this->server->requests() as $request) {
            self::assertSame('localhost', $request['host'], "{$request['method']} {$request['path']} goes through the socket");
        }
    }

    public function testAServerThatCannotAnswerGetPropsGetsNoTestRequest(): void
    {
        $this->server = new LlamaFake([
            'GET /health' => [['fixture' => 'health.json']],
            'GET /props' => [['status' => 500, 'body' => '{"error":{"message":"still starting"}}']],
            'GET /v1/models' => [['fixture' => 'v1-models.json']],
            'GET /models' => [['fixture' => 'models-single.json']],
            'POST /v1/chat/completions' => [['fixture' => 'probe-native.sse']],
        ]);

        $run = $this->doctor(['base_url' => $this->server->url(), 'model' => 'qwen35-2b']);

        self::assertSame(['--', 'unknown (GET /props failed)'], self::line($run->stdout, 'router mode'));
        self::assertSame(['--', 'skipped: the router mode is unknown, so doctor sends no test request'], self::line($run->stdout, 'tool calling'));
        self::assertNotContains('POST', array_column($this->server->requests(), 'method'));
    }

    public function testARouterWithItsModelLoadedIsRecognisedAndTheModelIsProbed(): void
    {
        $this->server = new LlamaFake([
            'GET /health' => [['fixture' => 'health.json']],
            'GET /props' => [['fixture' => 'props-router.json'], ['fixture' => 'props-router.json'], ['fixture' => 'props-router.json'], ['fixture' => 'props-model.json']],
            'GET /v1/models' => [['fixture' => 'v1-models-router-loaded.json']],
            'GET /models' => [['fixture' => 'models-router-loaded.json']],
            'GET /slots' => [['fixture' => 'slots-router.json']],
            'POST /v1/chat/completions' => [['fixture' => 'probe-native.sse']],
        ]);

        $run = $this->doctor(['base_url' => $this->server->url(), 'model' => 'Qwen3.5-2B-Q8_0']);

        self::assertSame(0, $run->status, $run->stdout);
        self::assertSame(['--', 'yes (models report load status)'], self::line($run->stdout, 'router mode'));
        self::assertSame(['--', 'yes (1 models, 1 loaded)'], self::line($run->stdout, 'llama router mode'));
        self::assertSame(['ok', 'native'], self::line($run->stdout, 'tool calling'));
        $requests = $this->server->requests();
        $chats = array_values(array_filter($requests, static fn (array $request): bool => $request['method'] === 'POST'));
        self::assertNotEmpty($chats);
        foreach ($chats as $chat) {
            self::assertSame('Qwen3.5-2B-Q8_0', $chat['json']['model'] ?? null, 'the chat names the loaded model');
        }
        foreach ($requests as $request) {
            if ($request['query'] !== []) {
                self::assertSame(['model' => 'Qwen3.5-2B-Q8_0'], $request['query'], 'a model-specific read is only made for the loaded model');
            }
        }
    }

    public function testAnUnloadedRouterModelIsNotLoadedByTheDoctor(): void
    {
        $this->server = new LlamaFake([
            'GET /health' => [['fixture' => 'health.json']],
            'GET /props' => [['fixture' => 'props-router.json']],
            'GET /v1/models' => [['fixture' => 'models-router-unloaded.json']],
            'GET /models' => [['fixture' => 'models-router-unloaded.json']],
            'POST /v1/chat/completions' => [['fixture' => 'probe-native.sse']],
        ]);

        $run = $this->doctor(['base_url' => $this->server->url(), 'model' => 'Qwen3.5-2B-Q8_0']);

        self::assertSame(0, $run->status, $run->stdout);
        self::assertSame(['--', 'yes (models report load status)'], self::line($run->stdout, 'router mode'));
        self::assertSame(['--', 'unloaded (doctor does not load models)'], self::line($run->stdout, 'tool calling'));
        foreach ($this->server->requests() as $request) {
            self::assertNotSame('POST', $request['method'], 'a chat request would load the model');
            self::assertSame([], $request['query'], 'a model-specific read would load the model');
        }
    }

    public function testAModelTheRouterDoesNotListIsNotProbedEither(): void
    {
        $this->server = new LlamaFake([
            'GET /health' => [['fixture' => 'health.json']],
            'GET /props' => [['fixture' => 'props-router.json']],
            'GET /v1/models' => [['fixture' => 'v1-models-router-loaded.json']],
            'GET /models' => [['fixture' => 'models-router-loaded.json']],
            'POST /v1/chat/completions' => [['fixture' => 'probe-native.sse']],
        ]);

        $run = $this->doctor(['base_url' => $this->server->url(), 'model' => 'other-model']);

        self::assertSame(1, $run->status, $run->stdout);
        self::assertSame(['FAIL', 'Qwen3.5-2B-Q8_0'], self::line($run->stdout, 'model listed'));
        self::assertSame(['--', 'not listed (doctor does not load models)'], self::line($run->stdout, 'tool calling'));
        self::assertNotContains('POST', array_column($this->server->requests(), 'method'));
    }

    public function testTheApiKeyIsSentWithEveryRequestAndIsNeverPrinted(): void
    {
        $key = 'key-' . bin2hex(random_bytes(8));
        $this->server = new LlamaFake($this->healthy(), apiKey: $key);

        $run = $this->doctor(['base_url' => $this->server->url(), 'model' => 'qwen35-2b', 'api_key_env' => 'PHYCODE_DOCTOR_KEY'], ['PHYCODE_DOCTOR_KEY' => $key]);

        self::assertSame(0, $run->status, $run->stdout);
        self::assertSame(['ok', 'qwen35-2b'], self::line($run->stdout, 'model listed'));
        self::assertSame(['ok', 'native'], self::line($run->stdout, 'tool calling'));
        self::assertStringNotContainsString($key, $run->stdout . $run->stderr);
        self::assertNotEmpty($this->server->requests());
        foreach ($this->server->requests() as $request) {
            self::assertSame("Bearer {$key}", $request['authorization'], "{$request['method']} {$request['path']}");
        }
    }

    public function testAServerThatRejectsTheKeyFailsTheModelListingWithTheServersMessage(): void
    {
        $this->server = new LlamaFake($this->healthy(), apiKey: 'key-the-server-wants');

        $run = $this->doctor(['base_url' => $this->server->url(), 'model' => 'qwen35-2b']);

        self::assertSame(1, $run->status, $run->stdout);
        self::assertSame(['FAIL', 'HTTP 401: Invalid API Key'], self::line($run->stdout, 'model listed'));
        self::assertSame(['--', 'unknown (HTTP 401: Invalid API Key)'], self::line($run->stdout, 'router mode'));
        self::assertSame(['--', 'skipped: the router mode is unknown, so doctor sends no test request'], self::line($run->stdout, 'tool calling'));
        self::assertSame(['FAIL', 'the server rejects the API key (HTTP 401: Invalid API Key)'], self::line($run->stdout, 'llama server'));
        self::assertNotContains('POST', array_column($this->server->requests(), 'method'), 'a chat request could load a model of a router that is not known to be one');
        foreach ($this->server->requests() as $request) {
            if ($request['path'] !== '/health') {
                self::assertNull($request['authorization'], 'no key is configured, so none is sent');
            }
        }
    }

    public function testTheDoctorChecksTheGentleAiBinaryThatTheConfigNames(): void
    {
        $this->server = new LlamaFake($this->healthy());
        $bin = Kit::folder('gentle-fake') . '/gentle-ai';
        Kit::write($bin, "#!/bin/sh\n" . 'if [ "$1" = version ]; then echo "gentle-ai v4.2.0"; else echo "[]"; fi' . "\n");
        chmod($bin, 0755);

        $run = $this->doctor(['base_url' => $this->server->url(), 'model' => 'qwen35-2b'], [], ['gentle_ai' => ['bin' => $bin]]);

        self::assertSame(0, $run->status, $run->stdout);
        self::assertStringContainsString("[--] gentle-ai: v4.2.0 at {$bin}; ", $run->stdout);
        self::assertStringContainsString("; skills: 0\n", $run->stdout);
    }

    public function testTheVaultLineNamesTheProfilesWithAStoredKeyAndNeverShowsTheKey(): void
    {
        $key = 'sk-test-DOCTOR-' . bin2hex(random_bytes(6));
        $profile = ['base_url' => 'http://127.0.0.1:9', 'model' => 'qwen35-2b', 'api_key_store' => true];
        $home = PhycodeRun::run(['keys', 'set', 'test'], $profile, $key)->home;

        $run = $this->doctorAt($home, $profile);

        self::assertSame(['ok', 'keys stored for test (values are never shown)'], self::line($run->stdout, 'vault'));
        self::assertStringNotContainsString($key, $run->stdout . $run->stderr);
    }

    public function testAProfileThatReadsTheVaultWithoutAStoredKeyFailsTheCheck(): void
    {
        $profile = ['base_url' => 'http://127.0.0.1:9', 'model' => 'qwen35-2b', 'api_key_store' => true];

        $run = $this->doctorAt(null, $profile);

        self::assertSame(1, $run->status, $run->stdout);
        self::assertSame(['FAIL', 'no key is stored for test; set one with phycode keys set <profile>'], self::line($run->stdout, 'vault'));
    }

    public function testWithoutAVaultProfileTheVaultLineSaysNoKeysAreStored(): void
    {
        $run = $this->doctorAt(null, ['base_url' => 'http://127.0.0.1:9', 'model' => 'qwen35-2b']);

        self::assertSame(['--', 'no keys stored'], self::line($run->stdout, 'vault'));
    }

    public function testAVaultThatCannotBeReadIsAFailureWithItsReason(): void
    {
        $profile = ['base_url' => 'http://127.0.0.1:9', 'model' => 'qwen35-2b', 'api_key_store' => true];
        $home = PhycodeRun::run(['keys', 'set', 'test'], $profile, 'sk-test-BROKEN-123456')->home;
        chmod("{$home}/config/phycode/keys.json", 0644);

        $run = $this->doctorAt($home, $profile);

        self::assertSame(1, $run->status, $run->stdout);
        $vault = self::line($run->stdout, 'vault');
        self::assertSame('FAIL', $vault[0] ?? null);
        self::assertStringContainsString('must be readable only by its owner', $vault[1] ?? '');
        self::assertStringNotContainsString('sk-test-BROKEN', $run->stdout);
    }

    private function doctorAt(?string $home, array $profile): PhycodeRun
    {
        return PhycodeRun::run(['doctor', '--cwd', Kit::folder('doctor-project')], $profile, '', [], [], $home);
    }

    public function testAGentleAiBinaryThatIsNotExecutableIsAFailureAndTheOtherChecksStillRun(): void
    {
        $run = $this->doctor(['base_url' => 'http://127.0.0.1:9', 'model' => 'qwen35-2b'], [], ['gentle_ai' => ['bin' => '/nonexistent/gentle-ai']]);

        self::assertSame(1, $run->status, $run->stdout);
        self::assertSame(['FAIL', 'gentle_ai.bin is not an executable file: /nonexistent/gentle-ai'], self::line($run->stdout, 'gentle-ai'));
        self::assertNotNull(self::line($run->stdout, 'profile'), 'the profile is still checked');
        self::assertNotNull(self::line($run->stdout, 'llama health'), 'the llama checks, which come after the gentle-ai line, still run');
        self::assertStringNotContainsString('[--] gentle-ai:', $run->stdout, 'no other gentle-ai is looked up when the configured one is invalid');
        self::assertStringNotContainsString('gentle_ai.bin', $run->stderr, 'the failure is a line of the report, not an error');
    }

    public function testAProfileWithAUserNameAndAPasswordIsPrintedWithoutThem(): void
    {
        $run = $this->doctor(['base_url' => 'http://u:p@127.0.0.1:9', 'model' => 'qwen35-2b']);

        self::assertSame(1, $run->status, $run->stdout);
        self::assertSame(['ok', 'test -> http://127.0.0.1:9 model=qwen35-2b tool_mode=auto'], self::line($run->stdout, 'profile'));
        self::assertStringNotContainsString('u:p', $run->stdout . $run->stderr);
    }

    public function testAnOllamaProfileReceivesNoRequestAndIsSkipped(): void
    {
        $this->server = new LlamaFake($this->healthy());

        $run = $this->doctor(['base_url' => $this->server->url(), 'model' => 'qwen35-2b', 'protocol' => 'ollama']);

        self::assertSame(0, $run->status, $run->stdout);
        self::assertSame([], $this->server->requests(), 'no health check and no chat request reach an Ollama server');
        self::assertSame(['--', 'skipped: protocol ollama; doctor checks llama.cpp servers only'], self::line($run->stdout, 'server health'));
        self::assertNull(self::line($run->stdout, 'tool calling'));
    }

    public function testAServerOnAnotherHostIsNotProbedAndTheDoctorSaysSo(): void
    {
        $run = $this->doctor(['base_url' => 'http://192.168.1.10:8080', 'model' => 'qwen35-2b']);

        self::assertSame(0, $run->status, $run->stdout);
        self::assertSame(['--', 'skipped: remote server (use phycode run to test it)'], self::line($run->stdout, 'server health'));
        self::assertSame(['--', 'skipped: remote server (use phycode run to test it)'], self::line($run->stdout, 'llama health'));
        self::assertNull(self::line($run->stdout, 'tool calling'));
        self::assertStringNotContainsString('cannot reach', $run->stdout);
    }

    public function testRemoteProfilesOtherThanTheDefaultAreListedAsSkippedInTheDefaultOutput(): void
    {
        $this->server = new LlamaFake($this->healthy());

        $run = $this->doctor(
            ['base_url' => $this->server->url(), 'model' => 'qwen35-2b'],
            [],
            ['profiles' => [
                'cloud' => ['base_url' => 'http://192.0.2.1:8080', 'model' => 'gpt'],
                'gpu' => ['base_url' => 'http://192.0.2.2:11434', 'model' => 'llama3', 'protocol' => 'ollama'],
            ]],
        );

        self::assertSame(0, $run->status, $run->stdout);
        self::assertSame(2, preg_match_all('/^\[--\] llama health +skipped: remote server \(use phycode run to test it\)$/m', $run->stdout), $run->stdout);
        self::assertStringNotContainsString('cannot reach', $run->stdout, 'the remote servers are not contacted');
    }

    private function healthy(): array
    {
        return [
            'GET /health' => [['fixture' => 'health.json']],
            'GET /props' => [['fixture' => 'props.json']],
            'GET /v1/models' => [['fixture' => 'v1-models.json']],
            'GET /models' => [['fixture' => 'models-single.json']],
            'GET /slots' => [['fixture' => 'slots.json']],
            'POST /v1/chat/completions' => [['fixture' => 'probe-native.sse']],
        ];
    }

    private function doctor(array $profile, array $env = [], array $global = []): PhycodeRun
    {
        return PhycodeRun::run(['doctor', '--cwd', Kit::folder('doctor-project')], $profile, '', $env, $global);
    }

    private static function line(string $output, string $label): ?array
    {
        if (preg_match('/^\[(ok|FAIL|--)\] ' . preg_quote($label, '/') . ' +(.*)$/m', $output, $match) !== 1) {
            return null;
        }

        return [$match[1], $match[2]];
    }
}
