<?php

declare(strict_types=1);

namespace Phycode\Tests\Unit\Llama;

use Phycode\Tests\Support\Kit;
use Phycode\Tests\Unit\Cli\PhycodeRun;
use PHPUnit\Framework\TestCase;

final class DoctorOutputTest extends TestCase
{
    private ?LlamaFake $server = null;

    protected function tearDown(): void
    {
        $this->server?->stop();
    }

    public function testTheDoctorPrintsTheLlamaBlockForALocalProfileAndExitsZero(): void
    {
        $server = $this->serve($this->healthyRoutes());
        $url = $server->url();

        [$output, $status] = $this->doctor($server);

        self::assertSame(0, $status, $output);
        self::assertStringContainsString(sprintf("[ok] %-22s %s\n", 'llama health', "test: ok at {$url}"), $output);
        self::assertStringContainsString(sprintf("[ok] %-22s %s\n", 'llama tools', 'native tool calls work'), $output);
        self::assertStringContainsString(sprintf("[--] %-22s %s\n", 'llama router mode', 'no (single-model server)'), $output);
    }

    public function testAServerWithoutJinjaPrintsTheFixAndExitsOne(): void
    {
        $port = parse_url($this->serve($this->healthyRoutes([
            'GET /props' => [['fixture' => 'props-no-jinja.json']],
            'POST /v1/chat/completions' => [['fixture' => 'tool-no-jinja.json', 'status' => 500]],
        ]))->url(), PHP_URL_PORT);

        [$output, $status] = $this->doctor($this->server);

        self::assertSame(1, $status);
        self::assertStringContainsString(sprintf("[FAIL] %-22s %s\n", 'llama tools', 'native tool calls need --jinja (HTTP 500: tools param requires --jinja flag)'), $output);
        self::assertStringContainsString(sprintf("[--] %-22s %s\n", 'fix', "restart llama-server with --jinja on port {$port} (this build enables --jinja by default; check with llama-server --help)"), $output);
    }

    private function healthyRoutes(array $overrides = []): array
    {
        return $overrides + [
            'GET /health' => [['fixture' => 'health.json']],
            'GET /props' => [['fixture' => 'props.json']],
            'GET /v1/models' => [['fixture' => 'v1-models.json']],
            'GET /slots' => [['fixture' => 'slots.json']],
            'POST /v1/chat/completions' => [['fixture' => 'probe-native.sse']],
        ];
    }

    private function serve(array $routes): LlamaFake
    {
        $this->server?->stop();

        return $this->server = new LlamaFake($routes);
    }

    private function doctor(LlamaFake $server): array
    {
        $run = PhycodeRun::run(['doctor', '--cwd', Kit::folder('doctor-project')], ['base_url' => $server->url(), 'model' => 'qwen35-2b']);

        return [$run->stdout, $run->status];
    }
}
