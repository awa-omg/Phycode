<?php

declare(strict_types=1);

namespace Phycode\Tests\Unit\Session;

use Phycode\Session\SessionStore;
use Phycode\Support\PhycodeException;
use Phycode\Tests\Support\Kit;
use PHPUnit\Framework\TestCase;

final class SessionStoreTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = Kit::folder('session-store');
    }

    private static function session(string $id, string $updatedAt): array
    {
        return [
            'id' => $id,
            'title' => 'a title',
            'createdAt' => '2026-10-19T10:00:00.000000Z',
            'updatedAt' => $updatedAt,
            'profile' => 'local',
            'model' => 'qwen',
            'thinking' => 'off',
            'messages' => [
                ['role' => 'system', 'content' => 'You are the build agent.'],
                ['role' => 'user', 'content' => 'hello'],
                ['role' => 'assistant', 'content' => 'hi'],
            ],
        ];
    }

    public function testASavedSessionReadsBackAndIsWrittenPrivately(): void
    {
        $store = new SessionStore($this->root);

        $store->save(self::session('ses-1', '2026-10-19T10:01:00.000000Z'));

        $loaded = $store->load('ses-1');
        self::assertSame(['qwen', 'off'], [$loaded['model'], $loaded['thinking']]);
        self::assertSame(['system', 'user', 'assistant'], array_column($loaded['messages'], 'role'));
        self::assertSame(0600, fileperms($this->root . '/.phycode/sessions/ses-1.json') & 0777);
        self::assertFileExists($this->root . '/.phycode/.gitignore');
    }

    public function testAllListsTheSessionsNewestFirstAndSkipsWhatIsNotASession(): void
    {
        $store = new SessionStore($this->root);
        $store->save(self::session('ses-old', '2026-10-19T09:00:00.000000Z'));
        $store->save(self::session('ses-new', '2026-10-19T11:00:00.000000Z'));
        $folder = $this->root . '/.phycode/sessions';
        file_put_contents($folder . '/notes.json', '{"hello":1}');
        file_put_contents($folder . '/20261019-100000-abcdef.jsonl', "{}\n");
        file_put_contents($folder . '/bad.name.json', json_encode(self::session('bad.name', '2026-10-19T12:00:00.000000Z'), JSON_THROW_ON_ERROR));

        self::assertSame(['ses-new', 'ses-old'], array_column($store->all(), 'id'));
    }

    public function testLoadRefusesAMalformedIdAndAnUnknownOne(): void
    {
        $store = new SessionStore($this->root);

        foreach (['../secret', 'a/b', '', '.hidden', 'ses 1', "ses-1\n"] as $id) {
            self::assertSame('malformed session id; /sessions lists the ids', self::refusal(fn () => $store->load($id)), $id);
        }
        self::assertSame('no session named ses-missing; /sessions lists the saved ones', self::refusal(fn () => $store->load('ses-missing')));
    }

    public function testTheIdRuleDoesNotAcceptATrailingNewline(): void
    {
        self::assertTrue(SessionStore::validId('ses-1'));
        self::assertFalse(SessionStore::validId("ses-1\n"));
        self::assertMatchesRegularExpression('/^\d{8}-\d{6}-[0-9a-f]{6}$/', SessionStore::newId());
    }

    public function testLoadRefusesAFileThatIsNotASession(): void
    {
        $store = new SessionStore($this->root);
        $store->save(self::session('ses-1', '2026-10-19T10:01:00.000000Z'));
        file_put_contents($this->root . '/.phycode/sessions/broken.json', '{"id":"broken","messages":"nope"}');

        self::assertSame('session broken cannot be read: its file is not a saved session', self::refusal(fn () => $store->load('broken')));
        self::assertSame(['ses-1'], array_column($store->all(), 'id'));
    }

    public function testSaveRefusesASessionThatWouldNotReadBack(): void
    {
        $store = new SessionStore($this->root);
        $invalid = self::session('ses-2', '2026-10-19T10:01:00.000000Z');
        $invalid['messages'] = 'nope';

        self::assertSame('the session is not valid, so it is not saved', self::refusal(fn () => $store->save($invalid)));
        self::assertFileDoesNotExist($this->root . '/.phycode/sessions/ses-2.json');
    }

    public function testASymbolicLinkAtPhycodeIsRefused(): void
    {
        symlink(Kit::folder('elsewhere'), $this->root . '/.phycode');

        $message = self::refusal(fn () => (new SessionStore($this->root))->all());

        self::assertStringContainsString('.phycode is a symbolic link', $message);
    }

    private static function refusal(\Closure $action): string
    {
        try {
            $action();
        } catch (PhycodeException $error) {
            return $error->getMessage();
        }
        self::fail('the action was not refused');
    }
}
