<?php

declare(strict_types=1);

namespace Phycode\Tests\Unit\Security;

use Phycode\Agent\AgentRunner;
use Phycode\Agent\Conversation;
use Phycode\Agent\RunSetup;
use Phycode\Security\Decision;
use Phycode\Security\Permissions;
use Phycode\Security\Rules;
use Phycode\Session\Transcript;
use Phycode\Support\Redaction;
use Phycode\Tests\Support\Kit;
use Phycode\Tests\Support\RecordingUi;
use Phycode\Tests\Support\ScriptedClient;
use Phycode\Tools\BaseTool;
use Phycode\Tools\GrepTool;
use Phycode\Tools\ReadTool;
use Phycode\Tools\ToolCategory;
use Phycode\Tools\ToolContext;
use Phycode\Tools\ToolRegistry;
use Phycode\Tools\ToolResult;
use PHPUnit\Framework\TestCase;

final class SecretsOutOfContextTest extends TestCase
{
    public const KEY = 'sk-live-0123456789abcdef-XYZ';

    private string $root;

    protected function setUp(): void
    {
        $this->root = realpath(Kit::folder('secrets-out'));
        Kit::write($this->root . '/.env', "API_KEY=" . self::KEY . "\nDEBUG=1\n");
        Kit::write($this->root . '/.env.example', "API_KEY=\n");
        Kit::write($this->root . '/src/app.php', "<?php\n\$k = getenv('API_KEY');\n");
        Kit::write($this->root . '/docs/setup.md', "Put API_KEY in .env\n");
        Kit::write($this->root . '/deploy/server.pem', "-----BEGIN PRIVATE KEY-----\nAAA\n");
        Kit::write($this->root . '/config/credentials.json', '{"API_KEY": "' . self::KEY . "\"}\nONLY_HERE=1\n");
    }

    public function testExactRedactionChangesOnlyTheSecretValuesAndLeavesShortOnesAlone(): void
    {
        self::assertSame('token=[redacted] and [redacted]', Redaction::exact('token=' . self::KEY . ' and other-secret-value', [self::KEY, 'other-secret-value']));
        self::assertSame('api_key = get_key()', Redaction::exact('api_key = get_key()', [self::KEY]), 'code that looks like a key is code');
        self::assertSame('a is a word', Redaction::exact('a is a word', ['a', 'is', '']), 'a short value would cut up the text');
        self::assertSame('x', Redaction::exact('x', []));
    }

    public function testReadingASecretFileAsksInEveryModeAndATemplateIsFree(): void
    {
        $read = new ReadTool();

        self::assertTrue($read->forcesApproval(['path' => '.env']));
        self::assertTrue($read->forcesApproval(['path' => 'deploy/server.pem']));
        self::assertFalse($read->forcesApproval(['path' => '.env.example']));
        self::assertFalse($read->forcesApproval(['path' => 'src/app.php']));
        self::assertFalse($read->forcesApproval([]));

        foreach (['ask', 'accept_edits', 'plan', 'yolo'] as $mode) {
            $ui = new RecordingUi();
            $ui->decisions = [Decision::AllowOnce];
            $context = Kit::context($this->root, Kit::agent(), Kit::policy($mode), $ui);

            $reason = (new Permissions($ui, new Rules(['allow' => ['*']])))->check($read, $context, 'read .env', ['path' => '.env']);

            self::assertNull($reason, $mode);
            self::assertCount(1, array_filter($ui->events, static fn (array $event): bool => $event[0] === 'confirm'), "{$mode}: asked, even with an allow rule");
        }

        $ui = new RecordingUi();
        $context = Kit::context($this->root, Kit::agent(), Kit::policy('ask'), $ui);
        self::assertNull((new Permissions($ui))->check($read, $context, 'read src/app.php', ['path' => 'src/app.php']));
        self::assertSame([], array_filter($ui->events, static fn (array $event): bool => $event[0] === 'confirm'));
    }

    public function testASecretFileIsRefusedWhereNobodyCanAnswer(): void
    {
        $ui = new RecordingUi();
        $ui->terminal = false;
        $context = Kit::context($this->root, Kit::agent(), Kit::policy('ask'), $ui);

        $reason = (new Permissions($ui))->check(new ReadTool(), $context, 'read .env', ['path' => '.env']);

        self::assertStringContainsString('no terminal', (string) $reason);
    }

    public function testASearchOfTheProjectDoesNotShowWhatTheSecretFilesHold(): void
    {
        $context = Kit::context($this->root, Kit::agent(), Kit::policy('yolo'), new RecordingUi());

        $result = (new GrepTool())->execute(['pattern' => 'API_KEY'], $context);

        self::assertTrue($result->ok);
        self::assertStringContainsString('src/app.php:2:', $result->content);
        self::assertStringContainsString('docs/setup.md:1:', $result->content);
        self::assertStringNotContainsString(self::KEY, $result->content);
        self::assertStringNotContainsString('credentials.json', $result->content);
        self::assertMatchesRegularExpression('/\[\d+ matches in files that usually hold secrets \(\.env, keys\) are left out\]/', $result->content);
    }

    public function testASearchWhoseOnlyMatchesAreSecretsSaysSoWithoutShowingThem(): void
    {
        $context = Kit::context($this->root, Kit::agent(), Kit::policy('yolo'), new RecordingUi());

        $result = (new GrepTool())->execute(['pattern' => 'ONLY_HERE'], $context);

        self::assertTrue($result->ok);
        self::assertSame('no matches outside the files that usually hold secrets (1 lines left out)', $result->content);
    }

    public function testASearchThatNamesASecretFileIsAskedAbout(): void
    {
        self::assertTrue((new GrepTool())->forcesApproval(['pattern' => 'x', 'path' => '.env']));
        self::assertFalse((new GrepTool())->forcesApproval(['pattern' => 'x', 'path' => 'src']));
        $context = Kit::context($this->root, Kit::agent(), Kit::policy('yolo'), new RecordingUi());

        $result = (new GrepTool())->execute(['pattern' => 'API_KEY', 'path' => '.env'], $context);

        self::assertStringContainsString(self::KEY, $result->content, 'once the person said yes, the file is searched');
    }

    public function testAKeyThatAToolPrintsNeverReachesTheModelOrTheTranscript(): void
    {
        $leak = new class () extends BaseTool {
            public function name(): string
            {
                return 'leak';
            }

            public function description(): string
            {
                return 'Prints a key (test double).';
            }

            public function category(): ToolCategory
            {
                return ToolCategory::Read;
            }

            public function parameters(): array
            {
                return ['type' => 'object', 'properties' => []];
            }

            public function execute(array $args, ToolContext $context): ToolResult
            {
                return ToolResult::ok('the key is ' . SecretsOutOfContextTest::KEY . ' and DEBUG=1');
            }
        };
        $registry = new ToolRegistry();
        $registry->register($leak);
        $transcript = Transcript::memory();
        $base = Kit::context($this->root, Kit::agent(), Kit::policy('yolo'), new RecordingUi());
        $context = new ToolContext($base->workspace, $base->permissions, $base->processes, $base->ui, $transcript, $base->limits, $base->agent, $base->policy, $base->cancel, 'test', null, $base->changes, $base->sandbox, null, [self::KEY]);
        $conversation = new Conversation();
        $conversation->setSystem('system');
        $conversation->addUser('task');
        $client = new ScriptedClient([ScriptedClient::call('leak', []), ScriptedClient::text('done')]);

        (new AgentRunner())->run($conversation, new RunSetup($client, 'native', $registry, $context, 4, false));

        $reply = array_values(array_filter($client->requests[1]['messages'], static fn (array $m): bool => ($m['role'] ?? '') === 'tool'))[0]['content'];
        self::assertSame('the key is [redacted] and DEBUG=1', $reply);
        self::assertStringNotContainsString(self::KEY, $transcript->contents());
        self::assertStringNotContainsString(self::KEY, json_encode($conversation->messages()));
    }

    public function testASubagentContextKeepsTheSecretsToo(): void
    {
        $base = Kit::context($this->root, Kit::agent(), Kit::policy('yolo'), new RecordingUi());
        $context = new ToolContext($base->workspace, $base->permissions, $base->processes, $base->ui, $base->transcript, $base->limits, $base->agent, $base->policy, $base->cancel, 'test', null, $base->changes, $base->sandbox, null, [self::KEY]);

        $child = $context->forAgent(Kit::agent('explore'), Kit::policy('yolo'), new \Phycode\Support\Cancellation(), 'test', $base->sandbox);

        self::assertSame([self::KEY], $child->secrets);
    }
}
