<?php

declare(strict_types=1);

namespace Phycode\Tests\Unit\Cli;

use Phycode\Gentle\Persona;
use Phycode\Security\Decision;
use Phycode\Tests\Support\Kit;
use Phycode\Tests\Support\RecordingUi;
use Phycode\Tests\Support\RequestSpy;
use Phycode\Tests\Support\ScriptedClient;
use Phycode\Tests\Support\SessionHarness;
use PHPUnit\Framework\TestCase;

final class PersonaPromptTest extends TestCase
{
    private const CONFIG = [
        'default_profile' => 'local',
        'profiles' => [
            'local' => ['base_url' => 'http://127.0.0.1:9', 'model' => 'qwen', 'tool_mode' => 'text'],
        ],
    ];

    private SessionHarness $harness;

    protected function setUp(): void
    {
        $this->harness = new SessionHarness(self::CONFIG);
    }

    protected function tearDown(): void
    {
        $this->harness->end();
    }

    public function testThePersonaOfTheConfigIsAppendedAndNoPersonaLeavesThePromptAsItWas(): void
    {
        $kernel = $this->harness->kernel(new RequestSpy(['local' => 'qwen']), new RecordingUi());
        $plain = $kernel->systemPrompt(Kit::agent(), 'text', Kit::policy());
        self::assertStringEndsWith("Agent instructions (build):\nYou are the build agent.", $plain);
        self::assertStringNotContainsString('Chat persona:', $plain);

        $this->setPersona('neutral');
        self::assertSame($plain . "\n\n" . Persona::promptBlock('neutral'), $kernel->systemPrompt(Kit::agent(), 'text', Kit::policy()));

        $this->setPersona('gentleman');
        self::assertSame($plain . "\n\n" . Persona::promptBlock('gentleman'), $kernel->systemPrompt(Kit::agent(), 'text', Kit::policy()));

        $this->setPersona('bossy');
        self::assertSame($plain, $kernel->systemPrompt(Kit::agent(), 'text', Kit::policy()), 'a name that is not a persona adds nothing');
    }

    public function testTheRepliesOfASessionGetThePersonaInTheirSystemMessage(): void
    {
        $this->setPersona('gentleman');
        $spy = new RequestSpy(['local' => 'qwen'], [], [ScriptedClient::text('Claro.')]);
        $session = $this->harness->session($this->harness->kernel($spy, new RecordingUi()));

        $session->handle('explain the layout of this project');

        self::assertSame('completed', $session->lastStatus());
        self::assertStringEndsWith(Persona::promptBlock('gentleman'), (string) $spy->sent[0]['messages'][0]['content']);
    }

    public function testThePersonaCommandChangesTheNextRequestAndKeepsTheMessages(): void
    {
        $ui = new RecordingUi();
        $ui->decisions = [Decision::AllowOnce, Decision::AllowOnce];
        $spy = new RequestSpy(['local' => 'qwen'], [], [ScriptedClient::text('Claro.'), ScriptedClient::text('Hecho.'), ScriptedClient::text('Listo.')]);
        $session = $this->harness->session($this->harness->kernel($spy, $ui));

        $session->handle('explain the layout of this project');
        $session->handle('/persona neutral');
        $session->handle('and the tests?');
        $session->handle('/persona off');
        $session->handle('and the build?');

        self::assertCount(3, $spy->sent);
        self::assertStringNotContainsString('Chat persona:', (string) $spy->sent[0]['messages'][0]['content']);
        self::assertStringEndsWith(Persona::promptBlock('neutral'), (string) $spy->sent[1]['messages'][0]['content'], 'the request after /persona carries the persona');
        self::assertSame($spy->sent[0]['messages'][1], $spy->sent[1]['messages'][1], 'the conversation keeps its messages');
        self::assertStringNotContainsString('Chat persona:', (string) $spy->sent[2]['messages'][0]['content'], '/persona off takes the persona out of the next request');
        self::assertCount(6, $spy->sent[2]['messages'], 'the system message, the three questions and the two replies before the last question');
    }

    private function setPersona(string $name): void
    {
        file_put_contents($this->harness->configFile, json_encode(self::CONFIG + ['gentle_ai' => ['persona' => $name]], JSON_THROW_ON_ERROR));
    }
}
