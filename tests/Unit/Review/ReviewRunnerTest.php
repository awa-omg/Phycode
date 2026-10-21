<?php

declare(strict_types=1);

namespace Phycode\Tests\Unit\Review;

use Phycode\Agent\AgentRegistry;
use Phycode\Agent\AgentRunner;
use Phycode\Agent\Delegation;
use Phycode\Agent\PromptBuilder;
use Phycode\Agent\RunPolicy;
use Phycode\Config\Config;
use Phycode\Llm\LlmException;
use Phycode\Review\ReviewRunner;
use Phycode\Tests\Support\Kit;
use Phycode\Tests\Support\RecordingUi;
use Phycode\Tests\Support\ScriptedClient;
use Phycode\Tests\Support\ScriptedProvider;
use Phycode\Tools\BashTool;
use Phycode\Tools\EditTool;
use Phycode\Tools\GrepTool;
use Phycode\Tools\ListTool;
use Phycode\Tools\ReadTool;
use Phycode\Tools\TaskTool;
use Phycode\Tools\ToolRegistry;
use Phycode\Tools\ToolResult;
use Phycode\Tools\WriteTool;
use PHPUnit\Framework\TestCase;

final class ReviewRunnerTest extends TestCase
{
    private const LENS_TEXT = [
        'reviewer-risk' => 'Look for defects that a change introduces in security',
        'reviewer-resilience' => 'Look for defects that a change introduces in failure handling',
        'reviewer-readability' => 'Look for maintainability defects that hide behaviour',
        'reviewer-reliability' => 'Look for defects in the behaviour a change introduces',
    ];

    private const ADDED = "+    echo \$_GET['name'];";

    private function project(): string
    {
        $root = ReviewRepo::create(['src/a.php' => "<?php\n\nfunction show(\$name)\n{\n    return \$name;\n}\n"]);
        Kit::write($root . '/src/a.php', "<?php\n\nfunction show(\$name)\n{\n    echo \$_GET['name'];\n    return \$name;\n}\n");
        Kit::write($root . '/src/b.php', "<?php\n\nreturn 1;\n");

        return $root;
    }

    private function runner(string $root, ?ReviewModel $model = null, ?array $testCommand = null): ReviewRunner
    {
        $model ??= new ReviewModel([]);
        $config = Config::fromArrays(['profiles' => ['test' => ['base_url' => 'http://127.0.0.1:9', 'model' => 'scripted', 'tool_mode' => 'native']]], null, [], '/nonexistent');
        $agents = AgentRegistry::load($root, $root, '/nonexistent', '/nonexistent', Kit::builtinAgents());
        $tools = new ToolRegistry();
        foreach ([new ReadTool(), new ListTool(), new GrepTool(), new WriteTool(), new EditTool(), new BashTool()] as $tool) {
            $tools->register($tool);
        }
        $tools->register(new TaskTool($agents, true));
        $delegation = new Delegation($agents, new ScriptedProvider(['test' => $model]), $tools, new AgentRunner(), new PromptBuilder(), $config, $root, null);
        $parent = Kit::context($root, Kit::agent('build', 'primary'), RunPolicy::primary(Kit::agent(), 'yolo'), new RecordingUi(), $delegation);

        return new ReviewRunner(static fn (string $agent, string $task): ToolResult => $delegation->delegate($agent, $task, $parent), $testCommand);
    }

    private array $calls = [];

    private function progress(): \Closure
    {
        return function (string $step, string $detail, bool $done): void {
            $this->calls[] = [$step, $detail, $done];
        };
    }

    public function testEachLensIsAReviewerWithItsOwnLensTextAndTheDiff(): void
    {
        $root = $this->project();
        $model = new ReviewModel(ReviewModel::cleanLenses());

        $this->runner($root, $model)->run($root, null, $this->progress());

        self::assertSame(['reviewer-risk', 'reviewer-resilience', 'reviewer-readability', 'reviewer-reliability'], array_values(array_unique($model->agents())));
        foreach (self::LENS_TEXT as $agent => $lensText) {
            self::assertStringContainsString($lensText, $model->systemOf($agent), "{$agent} is given its lens text");
            self::assertStringContainsString(self::ADDED, $model->taskOf($agent), "{$agent} is given the change");
            self::assertStringContainsString('+return 1;', $model->taskOf($agent), "{$agent} is given the untracked file");
            self::assertStringContainsString('Changed files: src/a.php, src/b.php', $model->taskOf($agent));
        }
    }

    public function testTheRefuterRemovesARefutedFindingAndKeepsTheOthers(): void
    {
        $root = $this->project();
        $model = new ReviewModel([...ReviewModel::cleanLenses(),
            'reviewer-risk' => ReviewModel::reads(ReviewModel::findings(ReviewModel::claim('error', 'src/a.php', 4, 'Echoes raw input'))),
            'reviewer-readability' => ReviewModel::reads(ReviewModel::findings(ReviewModel::claim('suggestion', 'src/a.php', 4, 'Name the function'))),
            'reviewer-refuter' => ReviewModel::reads(ReviewModel::verdicts(['F1' => 'refuted', 'F2' => 'corroborated'])),
        ]);

        $report = $this->runner($root, $model)->run($root, null, $this->progress());

        self::assertStringContainsString('F1 [error] src/a.php:4 Echoes raw input', $model->taskOf('reviewer-refuter'));
        self::assertStringContainsString('F2 [suggestion] src/a.php:4 Name the function', $model->taskOf('reviewer-refuter'));
        self::assertSame(['F2'], array_column($report['findings'], 'id'));
        self::assertSame('corroborated', $report['findings'][0]['check']);
        self::assertSame(['F1'], array_column($report['refuted'], 'id'));
        self::assertSame('refuted', $report['refuted'][0]['check']);
        self::assertStringStartsWith('the code at the claimed place', $report['refuted'][0]['check_evidence']);
        self::assertSame('pass', $report['verdict'], 'the only error was refuted');
    }

    public function testAnErrorThatSurvivesTheRefuterBlocksTheGateAndIsPlanned(): void
    {
        $root = $this->project();
        $before = file_get_contents($root . '/src/a.php');
        $model = new ReviewModel([...ReviewModel::cleanLenses(),
            'reviewer-risk' => ReviewModel::reads(ReviewModel::findings(ReviewModel::claim('error', 'src/a.php', 4, 'Echoes raw input'))),
            'reviewer-refuter' => ReviewModel::reads(ReviewModel::verdicts(['F1' => 'corroborated'])),
        ]);

        $report = $this->runner($root, $model)->run($root, null, $this->progress());

        self::assertSame('blocking', $report['verdict']);
        self::assertTrue($report['gate']['blocking']);
        self::assertSame(['1 error finding survived the refuter'], $report['gate']['reasons']);
        self::assertSame([['finding' => 'F1', 'file' => 'src/a.php', 'line' => 4, 'reason' => 'Echoes raw input']], $report['plan']);
        self::assertSame($before, file_get_contents($root . '/src/a.php'), 'the plan proposes edits and edits nothing');
    }

    public function testAnErrorThatTheRefuterCannotDecideStillBlocks(): void
    {
        $root = $this->project();
        $model = new ReviewModel([...ReviewModel::cleanLenses(),
            'reviewer-risk' => ReviewModel::reads(ReviewModel::findings(ReviewModel::claim('error', 'src/a.php', 4, 'Echoes raw input'))),
            'reviewer-refuter' => ReviewModel::reads(ReviewModel::verdicts(['F1' => 'inconclusive'])),
        ]);

        $report = $this->runner($root, $model)->run($root, null, $this->progress());

        self::assertSame('blocking', $report['verdict']);
        self::assertSame('inconclusive', $report['findings'][0]['check']);
    }

    public function testAnErrorStandsWhenTheRefuterFails(): void
    {
        $root = $this->project();
        $model = new ReviewModel([...ReviewModel::cleanLenses(),
            'reviewer-risk' => ReviewModel::reads(ReviewModel::findings(ReviewModel::claim('error', 'src/a.php', 4, 'Echoes raw input'))),
            'reviewer-refuter' => [new LlmException('the refuter lost its model')],
        ]);

        $report = $this->runner($root, $model)->run($root, null, $this->progress());

        self::assertSame('failed', $report['refuter']['status']);
        self::assertStringContainsString('the refuter lost its model', (string) $report['refuter']['reason']);
        self::assertSame('blocking', $report['verdict']);
        self::assertSame('unchecked', $report['findings'][0]['check']);
    }

    public function testWarningsAndSuggestionsDoNotBlockTheGate(): void
    {
        $root = $this->project();
        $model = new ReviewModel([...ReviewModel::cleanLenses(),
            'reviewer-risk' => ReviewModel::reads(ReviewModel::findings(ReviewModel::claim('warning', 'src/a.php', 4, 'Echoes raw input'))),
            'reviewer-readability' => ReviewModel::reads(ReviewModel::findings(ReviewModel::claim('suggestion', 'src/a.php', 4, 'Name the function'))),
            'reviewer-refuter' => ReviewModel::reads(ReviewModel::verdicts(['F1' => 'corroborated', 'F2' => 'corroborated'])),
        ]);

        $report = $this->runner($root, $model)->run($root, null, $this->progress());

        self::assertSame('pass', $report['verdict']);
        self::assertFalse($report['gate']['blocking']);
        self::assertSame(['warning', 'suggestion'], array_column($report['findings'], 'severity'), 'the surviving findings are sorted by severity');
        self::assertSame([], $report['plan'], 'only errors are planned');
    }

    public function testAFailedTestCommandBlocksTheGate(): void
    {
        $root = $this->project();
        $command = [PHP_BINARY, '-r', 'fwrite(STDERR, "one test failed\n"); exit(1);'];

        $report = $this->runner($root, new ReviewModel(ReviewModel::cleanLenses()), $command)->run($root, null, $this->progress());

        self::assertSame('blocking', $report['verdict']);
        self::assertSame(['the test command failed: exit code 1'], $report['gate']['reasons']);
        self::assertSame('failed', $report['validator']['status']);
        self::assertSame(1, $report['validator']['exit']);
        self::assertStringContainsString('one test failed', $report['validator']['output']);
    }

    public function testAPassingTestCommandLeavesTheVerdictAtPass(): void
    {
        $root = $this->project();

        $report = $this->runner($root, new ReviewModel(ReviewModel::cleanLenses()), [PHP_BINARY, '-r', 'exit(0);'])->run($root, null, $this->progress());

        self::assertSame('pass', $report['verdict']);
        self::assertSame('passed', $report['validator']['status']);
        self::assertSame(0, $report['validator']['exit']);
    }

    public function testWithoutATestCommandTheValidatorIsSkipped(): void
    {
        $root = $this->project();

        $report = $this->runner($root, new ReviewModel(ReviewModel::cleanLenses()))->run($root, null, $this->progress());

        self::assertSame('skipped', $report['validator']['status']);
        self::assertSame('pass', $report['verdict']);
    }

    public function testAnIncompleteFindingIsDroppedAndCounted(): void
    {
        $root = $this->project();
        $reply = ReviewModel::findings(
            ['severity' => 'error', 'line' => 4, 'title' => 'no file', 'detail' => 'd', 'evidence' => 'e'],
            ['severity' => 'error', 'file' => 'src/a.php', 'line' => 4, 'title' => 'no evidence', 'detail' => 'd'],
            ReviewModel::claim('warning', 'src/a.php', 4, 'Echoes raw input'),
        );
        $model = new ReviewModel([...ReviewModel::cleanLenses(),
            'reviewer-risk' => ReviewModel::reads($reply),
            'reviewer-refuter' => ReviewModel::reads(ReviewModel::verdicts(['F1' => 'corroborated'])),
        ]);

        $report = $this->runner($root, $model)->run($root, null, $this->progress());

        self::assertSame(2, $report['dropped']);
        self::assertSame(1, $report['lenses'][0]['findings']);
        self::assertSame(['F1'], array_column($report['findings'], 'id'));
        self::assertSame('pass', $report['verdict']);
    }

    public function testTheProgressCallsComeInOrder(): void
    {
        $root = $this->project();
        $model = new ReviewModel([...ReviewModel::cleanLenses(),
            'reviewer-risk' => ReviewModel::reads(ReviewModel::findings(ReviewModel::claim('warning', 'src/a.php', 4, 'Echoes raw input'))),
            'reviewer-refuter' => ReviewModel::reads(ReviewModel::verdicts(['F1' => 'corroborated'])),
        ]);

        $this->runner($root, $model, [PHP_BINARY, '-r', 'exit(0);'])->run($root, null, $this->progress());

        self::assertSame([
            ['changes', false], ['changes', true],
            ['risk', false], ['risk', true],
            ['resilience', false], ['resilience', true],
            ['readability', false], ['readability', true],
            ['reliability', false], ['reliability', true],
            ['refuter', false], ['refuter', true],
            ['validator', false], ['validator', true],
            ['plan', false], ['plan', true],
        ], array_map(static fn (array $call): array => [$call[0], $call[2]], $this->calls));
    }

    public function testAnEmptyChangeSetRunsNoReviewer(): void
    {
        $root = ReviewRepo::create(['src/a.php' => "<?php\n"]);
        $model = new ReviewModel([]);

        $report = $this->runner($root, $model)->run($root, null, $this->progress());

        self::assertSame('empty', $report['verdict']);
        self::assertSame([], $model->requests);
        self::assertSame([['changes', false], ['changes', true]], array_map(static fn (array $call): array => [$call[0], $call[2]], $this->calls));
    }

    public function testAFailedLensIsReportedAndTheVerdictIsIncomplete(): void
    {
        $root = $this->project();
        $model = new ReviewModel([...ReviewModel::cleanLenses(), 'reviewer-readability' => [new LlmException('the model went away')]]);

        $report = $this->runner($root, $model)->run($root, null, $this->progress());

        self::assertSame('failed', $report['lenses'][2]['status']);
        self::assertSame('the model went away', $report['lenses'][2]['reason']);
        self::assertSame('incomplete', $report['verdict'], 'a lens that did not finish does not block by itself, but the verdict says so');
        self::assertFalse($report['gate']['blocking']);
        self::assertContains(['readability', 'failed: the model went away', true], $this->calls);
    }

    public function testALensThatReachedItsTurnBudgetIsPartialAndTheVerdictIsIncomplete(): void
    {
        $root = $this->project();
        $fake = static fn (string $agent, string $task): ToolResult => $agent === 'reviewer-risk'
            ? ToolResult::ok("[subagent reviewer-risk reached its turn budget; partial report: 10 turns, 9 tool calls]\n" . '{"findings":[]}', ['status' => 'budget'])
            : ToolResult::ok('{"findings":[]}', ['status' => 'completed']);

        $report = (new ReviewRunner($fake))->run($root, null, $this->progress());

        self::assertSame('partial', $report['lenses'][0]['status']);
        self::assertSame('incomplete', $report['verdict']);
    }

    public function testACutLensReplyIsPartialAndKeepsItsCompleteFindings(): void
    {
        $root = $this->project();
        $complete = json_encode(ReviewModel::claim('warning', 'src/a.php', 4, 'Echoes raw input'), JSON_THROW_ON_ERROR);
        $cut = '{"findings":[' . $complete . ',{"severity":"error","file":"src/b.php","li' . "\n[... truncated, 700 bytes omitted]";
        $model = new ReviewModel([...ReviewModel::cleanLenses(),
            'reviewer-risk' => ReviewModel::reads($cut),
            'reviewer-refuter' => ReviewModel::reads(ReviewModel::verdicts(['F1' => 'corroborated'])),
        ]);

        $report = $this->runner($root, $model)->run($root, null, $this->progress());

        self::assertSame(['F1'], array_column($report['findings'], 'id'));
        self::assertSame('partial', $report['lenses'][0]['status']);
        self::assertSame('the reply was cut at the report limit; 1 complete finding was kept', $report['lenses'][0]['reason']);
        self::assertSame('incomplete', $report['verdict']);
    }

    public function testACutRefuterReplyIsPartialAndTheFindingsItDidNotReachStand(): void
    {
        $root = $this->project();
        $cut = '{"results":[{"id":"F1","outcome":"refuted","evidence":"the caller clamps it"},{"id":"F2","outcome":"corroborated","evi'
            . "\n[... truncated, 300 bytes omitted]";
        $model = new ReviewModel([...ReviewModel::cleanLenses(),
            'reviewer-risk' => ReviewModel::reads(ReviewModel::findings(ReviewModel::claim('warning', 'src/a.php', 4, 'Echoes raw input'))),
            'reviewer-readability' => ReviewModel::reads(ReviewModel::findings(ReviewModel::claim('suggestion', 'src/a.php', 4, 'Name the function'))),
            'reviewer-refuter' => ReviewModel::reads($cut),
        ]);

        $report = $this->runner($root, $model)->run($root, null, $this->progress());

        self::assertSame('partial', $report['refuter']['status']);
        self::assertSame(['F1'], array_column($report['refuted'], 'id'));
        self::assertSame(['F2'], array_column($report['findings'], 'id'), 'F2 had no verdict, so it stands');
        self::assertSame('unchecked', $report['findings'][0]['check']);
        self::assertSame('incomplete', $report['verdict']);
    }

    public function testAChangeSetCutAt60KbIsNotedAndTheReviewersGetTheCut(): void
    {
        $root = ReviewRepo::create(['src/a.php' => "<?php\n"]);
        Kit::write($root . '/src/a.php', "<?php\n" . str_repeat("echo 'a line of the change';\n", 6000));
        $model = new ReviewModel(ReviewModel::cleanLenses());

        $report = $this->runner($root, $model)->run($root, null, $this->progress());

        self::assertTrue($report['cut']);
        self::assertContains('the change set was cut to 60 KB; the reviewers read the first part of it', $report['notes']);
        self::assertStringContainsString('[... change set cut at 60 KB:', $model->taskOf('reviewer-risk'));
    }

    public function testALensWithoutAFindingsListFails(): void
    {
        $root = $this->project();
        $model = new ReviewModel([...ReviewModel::cleanLenses(), 'reviewer-risk' => ReviewModel::reads('I found nothing wrong.')]);

        $report = $this->runner($root, $model)->run($root, null, $this->progress());

        self::assertSame('failed', $report['lenses'][0]['status']);
        self::assertSame('the reply had no findings list', $report['lenses'][0]['reason']);
        self::assertSame('incomplete', $report['verdict']);
    }
}
