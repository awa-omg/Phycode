<?php

declare(strict_types=1);

namespace Phycode\Tests\Unit\Review;

use Phycode\Cli\ChatSession;
use Phycode\Review\ReviewCommands;
use Phycode\Tests\Support\Kit;
use Phycode\Tests\Support\RecordingUi;
use Phycode\Tests\Support\ScriptedProvider;
use Phycode\Tests\Support\SessionHarness;
use PHPUnit\Framework\TestCase;

final class ReviewCommandsTest extends TestCase
{
    private const CONFIG = [
        'default_profile' => 'local',
        'profiles' => [
            'local' => ['base_url' => 'http://127.0.0.1:9', 'model' => 'qwen', 'tool_mode' => 'text', 'context_limit' => 4096],
        ],
    ];

    private SessionHarness $harness;
    private RecordingUi $ui;

    protected function setUp(): void
    {
        $this->harness = new SessionHarness(self::CONFIG);
        $this->ui = new RecordingUi();
    }

    protected function tearDown(): void
    {
        $this->harness->end();
    }

    public function testTheFindingsAreGroupedBySeverityAndTheVerdictIsInTheHint(): void
    {
        $this->project();
        $model = new ReviewModel([...ReviewModel::cleanLenses(),
            'reviewer-risk' => ReviewModel::reads(ReviewModel::findings(ReviewModel::claim('error', 'src/a.php', 4, 'Echoes raw input'))),
            'reviewer-readability' => ReviewModel::reads(ReviewModel::findings(ReviewModel::claim('suggestion', 'src/a.php', 4, 'Name the function'))),
            'reviewer-refuter' => ReviewModel::reads(ReviewModel::verdicts(['F1' => 'corroborated', 'F2' => 'corroborated'])),
        ]);

        self::assertTrue($this->session($model)->handle('/review'));

        [, , $items, $hint] = $this->panel('Review');
        self::assertSame(['Errors', 'Suggestions'], array_column($items, 'category'));
        self::assertSame(['error', ''], array_column($items, 'status'), 'the status marks an error only; the category names the rest');
        self::assertSame('F1 src/a.php:4 Echoes raw input', $items[0]['title']);
        self::assertSame("Verdict: blocking: 1 error finding survived the refuter.\nBase: uncommitted changes against HEAD; files: 2; refuted: 0; dropped as incomplete: 0.\nValidator: skipped.", $hint);
        [, , $edits] = $this->panel('Correction plan');
        self::assertSame(['src/a.php:4'], array_column($edits, 'title'));
        self::assertContains('info: refuter: 0 refuted', $this->notices(), 'the progress of the refuter is shown');
    }

    public function testAReviewWithNothingFoundShowsAPassVerdict(): void
    {
        $this->project();
        $model = new ReviewModel([...ReviewModel::cleanLenses(),
            'reviewer-risk' => ReviewModel::reads(ReviewModel::findings(ReviewModel::claim('warning', 'src/a.php', 4, 'Echoes raw input'))),
            'reviewer-refuter' => ReviewModel::reads(ReviewModel::verdicts(['F1' => 'corroborated'])),
        ]);

        self::assertTrue($this->session($model)->handle('/review'));

        [, , $items, $hint] = $this->panel('Review');
        self::assertSame(['Warnings'], array_column($items, 'category'));
        self::assertStringStartsWith("Verdict: pass: no error finding survived the refuter and the test command did not fail.\n", $hint);
        self::assertCount(1, $this->events('list_panel'), 'no correction plan without an error');
    }

    public function testAFailedTestCommandShowsItsOutput(): void
    {
        $this->project();
        $this->configure(['review' => ['test' => [realpath($this->harness->cwd) => [PHP_BINARY, '-r', 'fwrite(STDERR, "one test failed\n"); exit(1);']]]]);
        $model = new ReviewModel(ReviewModel::cleanLenses());

        self::assertTrue($this->session($model)->handle('/review'));

        [, , , $hint] = $this->panel('Review');
        self::assertStringStartsWith("Verdict: blocking: the test command failed: exit code 1.\n", $hint);
        $output = array_values(array_filter($this->events('panel'), static fn (array $event): bool => $event[1] === 'Test output'));
        self::assertCount(1, $output);
        self::assertStringContainsString('one test failed', $output[0][2]);
    }

    public function testWhileTheSwitchIsOffReviewSaysSoAndDoesNothing(): void
    {
        $this->project();
        $this->configure(['review' => ['mode' => 'disabled']]);
        $model = new ReviewModel([]);

        self::assertTrue($this->session($model)->handle('/review'));

        self::assertContains('info: reviews are off (review.mode is disabled), so /review did nothing. /review mode on turns them on.', $this->notices());
        self::assertSame([], $model->requests, 'no reviewer was asked');
        self::assertSame([], $this->events('list_panel'));
    }

    public function testModeOffWritesTheGlobalConfigWithoutAskingForApproval(): void
    {
        $this->configure([]);
        $session = $this->session(new ReviewModel([]));

        self::assertTrue($session->handle('/review mode off'));
        self::assertContains('info: reviews are off: /review does nothing until /review mode on', $this->notices());
        self::assertSame('disabled', $this->savedMode());
        self::assertSame([], $this->ui->requests, 'turning reviews off asks for no approval');

        self::assertTrue($session->handle('/review mode status'));
        self::assertContains('info: reviews are off (review.mode in ' . $this->harness->configFile . ')', $this->notices());
    }

    public function testModeOnWithoutTheGlobalConfigSaysWhatToCreateAndWritesNothing(): void
    {
        $session = $this->session(new ReviewModel([]));
        unlink($this->harness->configFile);

        self::assertTrue($session->handle('/review mode on'));

        $notices = $this->notices();
        self::assertCount(1, $notices);
        self::assertStringStartsWith('error: cannot change review.mode: ' . $this->harness->configFile . ' does not exist. Create it with: mkdir -p ', $notices[0]);
        self::assertFileDoesNotExist($this->harness->configFile);
    }

    public function testModeOnSaysSoAndReviewsRunAgain(): void
    {
        $this->configure(['review' => ['mode' => 'disabled']]);
        $session = $this->session(new ReviewModel([]));

        self::assertTrue($session->handle('/review mode on'));

        self::assertContains('info: reviews are on: /review runs four reviewers, a refuter and the test command on the changes', $this->notices());
        self::assertSame('enabled', $this->savedMode());
        self::assertSame([], $this->ui->requests);
    }

    public function testTheStatusSaysThatTheDefaultAppliesWhenTheSettingIsNotSet(): void
    {
        $this->configure([]);

        self::assertTrue($this->session(new ReviewModel([]))->handle('/review mode'));

        self::assertContains('info: reviews are on (review.mode is not set, so the default applies)', $this->notices());
    }

    public function testAnUnknownModeIsAUsageError(): void
    {
        $this->configure([]);

        self::assertTrue($this->session(new ReviewModel([]))->handle('/review mode maybe'));

        self::assertContains('error: usage: /review mode on|off|status', $this->notices());
    }

    public function testAnInvalidModeFailsClosed(): void
    {
        $this->project();
        $this->configure(['review' => ['mode' => 'maybe']]);
        $model = new ReviewModel([]);

        self::assertTrue($this->session($model)->handle('/review'));

        self::assertContains('error: review.mode must be enabled or disabled in ' . $this->harness->configFile, $this->notices());
        self::assertSame([], $model->requests);
    }

    public function testABaseRefThatNamesNoCommitIsAnErrorAndNothingRuns(): void
    {
        $this->project();
        $model = new ReviewModel([]);

        self::assertTrue($this->session($model)->handle('/review no-such-ref'));

        self::assertContains("error: 'no-such-ref' does not name a commit", $this->notices());
        self::assertSame([], $model->requests);
    }

    public function testTheBaseRefIsTheBaseOfTheReview(): void
    {
        $root = $this->harness->cwd;
        ReviewRepo::initialize($root, ['src/a.php' => "<?php\n\$a = 1;\n"]);
        Kit::write($root . '/src/a.php', "<?php\n\$a = 2; // committed change\n");
        ReviewRepo::commit($root, 'change a');
        Kit::write($root . '/src/a.php', "<?php\n\$a = 2; // committed change\n\$a = 3; // uncommitted change\n");
        $model = new ReviewModel(ReviewModel::cleanLenses());

        self::assertTrue($this->session($model)->handle('/review HEAD~1'));

        self::assertStringContainsString('Base: changes against HEAD~1 (', $model->taskOf('reviewer-risk'));
        self::assertStringContainsString('+$a = 3; // uncommitted change', $model->taskOf('reviewer-risk'));
        self::assertStringContainsString('+$a = 2; // committed change', $model->taskOf('reviewer-risk'), 'the commit since the base is part of the change');
    }

    public function testAnEmptyChangeSetSaysSoAndNothingRuns(): void
    {
        ReviewRepo::initialize($this->harness->cwd, ['src/a.php' => "<?php\n"]);
        $model = new ReviewModel([]);

        self::assertTrue($this->session($model)->handle('/review'));

        self::assertContains('info: nothing to review: no changes uncommitted changes against HEAD', $this->notices());
        self::assertSame([], $model->requests);
    }

    public function testWithSubagentsSwitchedOffTheReviewIsRefused(): void
    {
        $this->project();
        $this->configure(['subagents' => false]);
        $model = new ReviewModel([]);

        self::assertTrue($this->session($model)->handle('/review'));

        self::assertContains('error: subagents are switched off in the config, and the review runs on them', $this->notices());
        self::assertSame([], $model->requests);
    }

    private function project(): void
    {
        $root = $this->harness->cwd;
        ReviewRepo::initialize($root, ['src/a.php' => "<?php\n\nfunction show(\$name)\n{\n    return \$name;\n}\n"]);
        Kit::write($root . '/src/a.php', "<?php\n\nfunction show(\$name)\n{\n    echo \$_GET['name'];\n    return \$name;\n}\n");
        Kit::write($root . '/src/b.php', "<?php\n\nreturn 1;\n");
    }

    private function configure(array $extra): void
    {
        Kit::write($this->harness->configFile, json_encode(self::CONFIG + $extra, JSON_THROW_ON_ERROR));
    }

    private function savedMode(): mixed
    {
        $config = json_decode((string) file_get_contents($this->harness->configFile), true, 512, JSON_THROW_ON_ERROR);

        return $config['review']['mode'] ?? null;
    }

    private function session(ReviewModel $model): ChatSession
    {
        $kernel = $this->harness->kernel(new ScriptedProvider(['local' => $model]), $this->ui);
        if (!$kernel->commands->has('/review')) {
            $kernel->commands->add(ReviewCommands::fromKernel($kernel));
        }

        return $this->harness->session($kernel);
    }

    private function events(string $type): array
    {
        return array_values(array_filter($this->ui->events, static fn (array $event): bool => $event[0] === $type));
    }

    private function panel(string $title): array
    {
        foreach ($this->events('list_panel') as $event) {
            if ($event[1] === $title) {
                return $event;
            }
        }
        self::fail("no panel named {$title}");
    }

    private function notices(): array
    {
        return array_map(static fn (array $event): string => "{$event[1]}: {$event[2]}", $this->events('notice'));
    }
}
