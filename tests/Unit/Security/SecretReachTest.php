<?php

declare(strict_types=1);

namespace Phycode\Tests\Unit\Security;

use Phycode\Security\Decision;
use Phycode\Security\Permissions;
use Phycode\Security\Rules;
use Phycode\Security\SecretFiles;
use Phycode\Tests\Support\Kit;
use Phycode\Tests\Support\RecordingUi;
use Phycode\Tools\DeleteTool;
use Phycode\Tools\EditTool;
use Phycode\Tools\GrepTool;
use Phycode\Tools\MoveTool;
use Phycode\Tools\ReadTool;
use Phycode\Tools\RunCodeTool;
use Phycode\Tools\ToolContext;
use Phycode\Tools\WebFetchTool;
use Phycode\Tools\WriteTool;
use PHPUnit\Framework\TestCase;

final class SecretReachTest extends TestCase
{
    private const KEY = 'sk-test-0123456789abcdef';

    private string $root;

    protected function setUp(): void
    {
        $this->root = Kit::folder('secret-reach');
        Kit::write($this->root . '/.env', 'API_KEY=' . self::KEY . "\n");
        Kit::write($this->root . '/src/app.php', "<?php\n");
        symlink('.env', $this->root . '/notes.txt');
    }

    private function context(string $mode, RecordingUi $ui): ToolContext
    {
        return Kit::context($this->root, Kit::agent(), Kit::policy($mode), $ui);
    }

    public function testALinkWithAnInnocentNameDoesNotReturnTheSecretItPointsTo(): void
    {
        $result = (new ReadTool())->execute(['path' => 'notes.txt'], $this->context('yolo', new RecordingUi()));

        self::assertFalse($result->ok);
        self::assertStringNotContainsString(self::KEY, $result->content);
        self::assertStringContainsString('.env', $result->content);
    }

    public function testASearchThroughALinkToASecretIsRefused(): void
    {
        $result = (new GrepTool())->execute(['pattern' => 'API_KEY', 'path' => 'notes.txt'], $this->context('yolo', new RecordingUi()));

        self::assertFalse($result->ok);
        self::assertStringNotContainsString(self::KEY, $result->content);
    }

    public function testMovingOrDeletingASecretFileIsAskedAboutInEveryMode(): void
    {
        self::assertTrue((new MoveTool())->forcesApproval(['from' => '.env', 'to' => 'config.txt']));
        self::assertTrue((new MoveTool())->forcesApproval(['from' => 'config.txt', 'to' => '.env']));
        self::assertFalse((new MoveTool())->forcesApproval(['from' => 'src/app.php', 'to' => 'src/main.php']));
        self::assertTrue((new DeleteTool())->forcesApproval(['path' => '.env']));
        self::assertFalse((new DeleteTool())->forcesApproval(['path' => 'src/app.php']));

        $ui = new RecordingUi();
        $ui->decisions = [Decision::Deny];
        $reason = (new Permissions($ui, new Rules(['allow' => ['*']])))->check(
            new MoveTool(),
            $this->context('accept_edits', $ui),
            'move .env to config.txt',
            ['from' => '.env', 'to' => 'config.txt'],
        );

        self::assertSame('the user denied this action', $reason);
        self::assertFileExists($this->root . '/.env');
    }

    public function testWritingOrEditingASecretFileIsAskedAbout(): void
    {
        self::assertTrue((new WriteTool())->forcesApproval(['path' => '.env', 'content' => 'x']));
        self::assertTrue((new EditTool())->forcesApproval(['path' => 'deploy/.env.production']));
        self::assertFalse((new WriteTool())->forcesApproval(['path' => '.env.example', 'content' => 'x']));
    }

    public function testANewSecretFileIsCreatedReadableByTheOwnerOnly(): void
    {
        $result = (new WriteTool())->execute(['path' => 'config/prod.env', 'content' => "A=1\n"], $this->context('yolo', new RecordingUi()));

        self::assertTrue($result->ok, $result->content);
        self::assertSame(0600, fileperms($this->root . '/config/prod.env') & 0777);
    }

    public function testPhycodeRuntimeFilesAreAskedAboutBeforeTheyAreRead(): void
    {
        foreach (['.phycode/trash/20261021-010101-abc/src/app.php', '.phycode/checkpoints/blobs/0f', 'sub/../.phycode/sessions/x.jsonl'] as $path) {
            self::assertTrue((new ReadTool())->forcesApproval(['path' => $path]), $path);
            self::assertTrue((new GrepTool())->forcesApproval(['pattern' => 'x', 'path' => $path]), $path);
        }
        self::assertFalse((new ReadTool())->forcesApproval(['path' => '.phycode/config.json']));
    }

    public function testRuntimeStateIsRecognisedWhereverItIs(): void
    {
        self::assertTrue(SecretFiles::runtimeState('.phycode/trash/x'));
        self::assertTrue(SecretFiles::runtimeState('/home/me/project/.phycode/tmp/run.py'));
        self::assertFalse(SecretFiles::runtimeState('.phycode/config.json'));
        self::assertFalse(SecretFiles::runtimeState('src/phycode/trash.txt'));
    }

    public function testMoreKindsOfCredentialFilesAreRecognised(): void
    {
        foreach (['prod.env', 'config/.envrc', 'infra/terraform.tfvars', '.kube/config', '.docker/config.json', '.config/phycode/keys.json', '.yarnrc.yml', 'application_default_credentials.json'] as $path) {
            self::assertTrue(SecretFiles::matches($path), $path);
        }
        foreach (['prod.env.example', 'docker/config.json', 'kube/config', 'config.json', 'src/env.php'] as $path) {
            self::assertFalse(SecretFiles::matches($path), $path);
        }
    }

    public function testASearchLineIsDroppedWhenAnyPartOfItsPathIsASecretFile(): void
    {
        [$kept, $dropped] = SecretFiles::withoutSecrets(['x:1:y/secrets.yaml:1:TOKEN=abc', 'src/a.php:3: ok']);

        self::assertSame(['src/a.php:3: ok'], $kept);
        self::assertSame(1, $dropped);
    }

    public static function addresses(): iterable
    {
        yield 'a plain page' => ['https://example.com/docs/install', false];
        yield 'a query string' => ['https://example.com/search?token=abc', true];
        yield 'user information' => ['https://user:pw@example.com/', true];
        yield 'a very long path' => ['https://example.com/' . str_repeat('a', 400), true];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('addresses')]
    public function testAnAddressThatCarriesDataAsksBeforeItIsFetched(string $url, bool $asks): void
    {
        $tool = (new \ReflectionClass(WebFetchTool::class))->newInstanceWithoutConstructor();

        self::assertSame($asks, $tool->forcesApproval(['url' => $url]));
    }

    public function testTheCodeOfARunIsShownToThePersonWhoApprovesIt(): void
    {
        $code = "import os\nprint('hello')\n";

        $preview = (new RunCodeTool())->preview(['language' => 'python', 'code' => $code], $this->context('ask', new RecordingUi()));

        self::assertNotNull($preview);
        self::assertStringContainsString("print('hello')", (string) json_encode($preview));
    }

    public function testAGitCommandThatSetsItsConfigIsNotCoveredByAnAllowRule(): void
    {
        $rules = new Rules(['allow' => ['bash:git *']]);

        self::assertNull($rules->allowing('bash', 'bash: git -c alias.x=!id x'));
        self::assertNull($rules->allowing('bash', 'bash: git --git-dir=/tmp/other status'));
        self::assertSame('bash:git *', $rules->allowing('bash', 'bash: git status'));
    }
}
