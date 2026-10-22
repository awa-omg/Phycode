<?php

declare(strict_types=1);

namespace Phycode\Tests\Unit\Session;

use Phycode\Session\SessionSearch;
use Phycode\Session\SessionStore;
use Phycode\Tests\Support\Kit;
use Phycode\Tests\Support\RecordingUi;
use Phycode\Tools\SessionSearchTool;
use PHPUnit\Framework\TestCase;

final class SessionSearchTest extends TestCase
{
    private string $root;
    private SessionStore $store;
    private SessionSearch $search;

    protected function setUp(): void
    {
        $this->root = realpath(Kit::folder('session-search'));
        $this->store = new SessionStore($this->root);
        $this->search = new SessionSearch($this->store);
    }

    private function session(string $id, string $title, array $messages, string $updatedAt, ?int $modified = null): void
    {
        $this->store->save([
            'id' => $id,
            'title' => $title,
            'createdAt' => $updatedAt,
            'updatedAt' => $updatedAt,
            'profile' => 'local',
            'model' => 'qwen',
            'thinking' => 'off',
            'messages' => array_map(static fn (array $pair): array => ['role' => $pair[0], 'content' => $pair[1]], $messages),
        ]);
        if ($modified !== null) {
            touch($this->root . '/.phycode/sessions/' . $id . '.json', $modified);
        }
    }

    public function testEveryWordMustBeInTheSameMessageAndCaseDoesNotMatter(): void
    {
        $this->session('a', 'Migrations', [['user', 'How do I run the Migration for postgres?'], ['assistant', 'Use artisan migrate.'], ['user', 'and postgres is slow']], '2026-10-01T10:00:00.000000Z');

        $found = $this->search->find('POSTGRES migration', 5);

        self::assertCount(1, $found);
        self::assertSame(1, $found[0]['matches']);
        self::assertSame([['role' => 'user', 'text' => 'How do I run the Migration for postgres?']], $found[0]['snippets']);
        self::assertSame([], $this->search->find('postgres artisan', 5), 'the words are in different messages');
    }

    public function testToolMessagesAndSystemPromptsAreNotSearched(): void
    {
        $this->store->save([
            'id' => 'b', 'title' => 'T', 'createdAt' => '2026-10-01T10:00:00.000000Z', 'updatedAt' => '2026-10-01T10:00:00.000000Z', 'profile' => 'local', 'model' => 'qwen', 'thinking' => 'off',
            'messages' => [['role' => 'system', 'content' => 'secretword in the prompt'], ['role' => 'tool', 'tool_call_id' => 'x', 'content' => 'secretword in a file']],
        ]);

        self::assertSame([], $this->search->find('secretword', 5));
    }

    public function testTheMoreMessagesMatchTheHigherASessionRanksAndATitleCounts(): void
    {
        $this->session('few', 'Other', [['user', 'cache once']], '2026-10-03T10:00:00.000000Z', 1000);
        $this->session('many', 'Other', [['user', 'cache a'], ['assistant', 'cache b'], ['user', 'cache c'], ['assistant', 'cache d']], '2026-10-01T10:00:00.000000Z', 2000);
        $this->session('titled', 'Cache design', [['user', 'unrelated']], '2026-10-02T10:00:00.000000Z', 3000);

        self::assertSame(['many', 'titled', 'few'], array_column($this->search->find('cache', 5), 'id'));
        self::assertSame(['many'], array_column($this->search->find('cache', 1), 'id'));
    }

    public function testASnippetIsCutAroundTheMatchAndSecretsAreHidden(): void
    {
        $long = str_repeat('before ', 60) . 'needle sk-abcdefghijklmnopqrstuvwx ' . str_repeat('after ', 60);
        $this->session('c', 'T', [['user', $long]], '2026-10-01T10:00:00.000000Z');

        $snippet = $this->search->find('needle', 5)[0]['snippets'][0]['text'];

        self::assertStringStartsWith('...', $snippet);
        self::assertStringEndsWith('...', $snippet);
        self::assertStringContainsString('needle', $snippet);
        self::assertStringNotContainsString('sk-abcdefghijklmnopqrstuvwx', $snippet);
        self::assertLessThan(260, mb_strlen($snippet));
    }

    public function testOnlyTheMostRecentSessionsAreScannedAndAnEmptyQueryFindsNothing(): void
    {
        for ($i = 0; $i < SessionSearch::SCANNED_SESSIONS + 5; $i++) {
            $this->session(sprintf('s%03d', $i), 'T', [['user', $i === 0 ? 'oldest needle' : 'plain']], '2026-10-01T10:00:00.000000Z', 1000 + $i);
        }

        self::assertSame([], $this->search->find('needle', 5), 'the oldest session is past the scan limit');
        self::assertSame([], $this->search->find('  ', 5));
        self::assertSame([], $this->search->find('x', 5), 'a word of one letter is not a term');
    }

    public function testASessionFolderThatDoesNotExistGivesNothing(): void
    {
        self::assertSame([], $this->search->find('anything', 5));
    }

    public function testTheToolListsTheSessionsWithIdDateTitleAndExcerpt(): void
    {
        $this->session('20261001-100000-abc123', 'Migrations', [['user', 'How do I run the migration?'], ['assistant', 'Run the migration with artisan.']], '2026-10-01T10:00:00.000000Z');
        $tool = new SessionSearchTool($this->search);
        $context = Kit::context($this->root, Kit::agent(), Kit::policy('ask'), new RecordingUi());

        $result = $tool->execute(['query' => 'migration'], $context);

        self::assertTrue($result->ok);
        self::assertSame(
            "20261001-100000-abc123  2026-10-01T10:00  \"Migrations\"  (2 messages)\n  user: How do I run the migration?\n  assistant: Run the migration with artisan.",
            $result->content,
        );
        self::assertSame('no earlier session matches', $tool->execute(['query' => 'zebra'], $context)->content);
        self::assertSame("missing required argument 'query'", $tool->execute([], $context)->content);
    }
}
