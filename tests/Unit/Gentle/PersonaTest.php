<?php

declare(strict_types=1);

namespace Phycode\Tests\Unit\Gentle;

use Phycode\Gentle\Approval;
use Phycode\Gentle\GlobalConfig;
use Phycode\Gentle\Persona;
use Phycode\Security\Decision;
use Phycode\Support\PhycodeException;
use Phycode\Tests\Support\Kit;
use Phycode\Tests\Support\RecordingUi;
use PHPUnit\Framework\TestCase;

final class PersonaTest extends TestCase
{
    private string $file;
    private RecordingUi $ui;

    protected function setUp(): void
    {
        $this->file = Kit::folder('persona-config') . '/config.json';
        Kit::write($this->file, json_encode([
            'default_profile' => 'local',
            'profiles' => ['local' => ['base_url' => 'http://127.0.0.1:9/v1', 'model' => 'test-model', 'tool_mode' => 'none']],
            'gentle_ai' => ['bin' => '/usr/local/bin/gentle-ai'],
        ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        $this->ui = new RecordingUi();
    }

    public function testTheTextsAreCopiedWithTheirSourceAndTheLicense(): void
    {
        $folder = Persona::folder();

        foreach (['gentleman', 'neutral'] as $name) {
            $source = (string) file_get_contents("{$folder}/{$name}.md");
            self::assertStringStartsWith('<!-- Source: gentle-ai v4.2.0 (commit 4acaf42), internal/assets/generic/persona-' . $name . '.md. Copyright (c) 2025 Gentleman Programming, MIT License', $source);
        }
        self::assertStringContainsString('Copyright (c) 2025 Gentleman Programming', (string) file_get_contents($folder . '/LICENSE-gentle-ai'));
        self::assertStringContainsString('## Persona Scope', (string) Persona::text('gentleman'));
    }

    public function testThePersonaTextHasNoSourceLineAndKeepsItsRules(): void
    {
        $gentleman = (string) Persona::text('gentleman');
        $neutral = (string) Persona::text('neutral');

        self::assertStringStartsWith('## Rules', $gentleman);
        self::assertStringStartsWith('## Rules', $neutral);
        self::assertStringContainsString('Rioplatense Spanish (voseo)', $gentleman);
        self::assertStringNotContainsString('Rioplatense', $neutral, 'the neutral persona has no regional form');
        self::assertStringNotContainsString('<!--', $gentleman);
    }

    public function testAnUnknownPersonaHasNoText(): void
    {
        self::assertNull(Persona::text('custom'));
        self::assertNull(Persona::promptBlock('custom'));
    }

    public function testThePromptBlockNamesThePersonaAndCarriesItsText(): void
    {
        $block = (string) Persona::promptBlock('neutral');

        self::assertStringStartsWith('Chat persona: neutral. Follow it in your replies to the user.', $block);
        self::assertStringContainsString("\n\n" . Persona::text('neutral'), $block);
        self::assertStringContainsString('it never changes code, identifiers, comments or other artifacts', $block);
        self::assertNull(Persona::promptBlock(null), 'no persona set means no block');
    }

    public function testTheChoiceIsSavedAndKeepsTheOtherKeys(): void
    {
        $this->ui->decisions = [Decision::AllowOnce];

        $message = Persona::set(new GlobalConfig($this->file), 'neutral', $this->approval());

        self::assertStringStartsWith('saved gentle_ai.persona = neutral in ', $message);
        self::assertSame('neutral', Persona::configured(new GlobalConfig($this->file)));
        $data = json_decode((string) file_get_contents($this->file), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(['bin' => '/usr/local/bin/gentle-ai', 'persona' => 'neutral'], $data['gentle_ai']);
        self::assertSame('local', $data['default_profile']);
        self::assertFileExists($this->file . '.bak');
    }

    public function testOffRemovesTheKeyAndKeepsTheSection(): void
    {
        $this->ui->decisions = [Decision::AllowOnce, Decision::AllowOnce];
        Persona::set(new GlobalConfig($this->file), 'gentleman', $this->approval());

        Persona::set(new GlobalConfig($this->file), null, $this->approval());

        self::assertNull(Persona::configured(new GlobalConfig($this->file)));
        $data = json_decode((string) file_get_contents($this->file), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(['bin' => '/usr/local/bin/gentle-ai'], $data['gentle_ai']);
    }

    public function testTheSameChoiceChangesNothingAndAsksNothing(): void
    {
        $this->ui->decisions = [Decision::AllowOnce];
        Persona::set(new GlobalConfig($this->file), 'gentleman', $this->approval());
        $before = file_get_contents($this->file);

        self::assertSame('the persona is already gentleman; nothing changed.', Persona::set(new GlobalConfig($this->file), 'gentleman', $this->approval()));
        self::assertSame($before, file_get_contents($this->file));
        self::assertCount(1, $this->ui->requests);
    }

    public function testWithoutAYesTheChoiceIsNotSaved(): void
    {
        $before = file_get_contents($this->file);
        $this->ui->decisions = [Decision::Deny];

        self::assertSame('not approved: nothing was changed.', Persona::set(new GlobalConfig($this->file), 'gentleman', $this->approval()));
        self::assertSame($before, file_get_contents($this->file));
        self::assertFileDoesNotExist($this->file . '.bak');
        self::assertStringContainsString('set gentle_ai.persona to gentleman', (string) $this->ui->events[0][2]);
    }

    public function testAPersonaOutsideTheListIsRefused(): void
    {
        $this->expectException(PhycodeException::class);
        $this->expectExceptionMessage('the personas are gentleman, neutral or off');

        Persona::set(new GlobalConfig($this->file), 'bossy', $this->approval());
    }

    private function approval(): Approval
    {
        return new Approval($this->ui, fn (string $title, string $text) => $this->ui->panel($title, $text));
    }
}
