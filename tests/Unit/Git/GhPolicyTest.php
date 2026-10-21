<?php

declare(strict_types=1);

namespace Phycode\Tests\Unit\Git;

use Phycode\Git\GhPolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class GhPolicyTest extends TestCase
{
    public static function allowed(): iterable
    {
        yield 'view a pull request' => ['read', ['pr', 'view', '12', '--json', 'title,state,statusCheckRollup']];
        yield 'view the pull request of the branch' => ['read', ['pr', 'view']];
        yield 'view by address' => ['read', ['pr', 'view', 'https://github.com/acme/app/pull/12', '--comments']];
        yield 'view by branch' => ['read', ['pr', 'view', 'feature/login-fix']];
        yield 'list with filters' => ['read', ['pr', 'list', '--state', 'open', '--author', 'octocat', '--limit', '5', '--json', 'number,title']];
        yield 'list with an equals sign' => ['read', ['pr', 'list', '--state=merged', '-L', '3']];
        yield 'checks' => ['read', ['pr', 'checks', '12', '--required', '--json', 'name,state,bucket']];
        yield 'the diff' => ['read', ['pr', 'diff', '12', '--name-only']];
        yield 'status' => ['read', ['pr', 'status']];
        yield 'an issue' => ['read', ['issue', 'view', '7', '--comments']];
        yield 'issues' => ['read', ['issue', 'list', '--label', 'bug']];
        yield 'runs' => ['read', ['run', 'list', '--branch', 'main', '--limit', '3']];
        yield 'why a run failed' => ['read', ['run', 'view', '123456', '--log-failed']];
        yield 'a repository' => ['read', ['repo', 'view', 'acme/app', '--json', 'name,description']];
        yield 'who is logged in' => ['read', ['auth', 'status']];
        yield 'a pull request with title and body' => ['write', ['pr', 'create', '--title', 'Fix the loader', '--body', "What\nand why", '--base', 'main', '--draft']];
        yield 'a pull request filled from the commits' => ['write', ['pr', 'create', '--fill']];
        yield 'a pull request with a title that starts with a dash' => ['write', ['pr', 'create', '-t', '--odd title', '-b', 'body']];
        yield 'a comment' => ['write', ['pr', 'comment', '12', '--body', 'Looks good']];
        yield 'a new issue' => ['write', ['issue', 'create', '--title', 'Crash on start', '--body', 'Steps', '--label', 'bug']];
        yield 'an issue comment' => ['write', ['issue', 'comment', '7', '-b', 'Fixed in #12']];
    }

    #[DataProvider('allowed')]
    public function testTheCommandsThatReadOrPublishAreAllowed(string $mode, array $args): void
    {
        self::assertNull(GhPolicy::refusal($mode, $args), implode(' ', $args));
    }

    public static function refused(): iterable
    {
        yield 'nothing' => ['read', [], 'the first argument is the group'];
        yield 'an option first' => ['read', ['--version'], 'the first argument is the group'];
        yield 'a group without a command' => ['read', ['pr'], 'pr needs a command after it'];
        yield 'merge' => ['write', ['pr', 'merge', '12'], 'merging is for the person'];
        yield 'close' => ['write', ['pr', 'close', '12'], 'closing a pull request is for the person'];
        yield 'review' => ['write', ['pr', 'review', '12', '--approve'], 'the person writes it'];
        yield 'the api' => ['read', ['api', 'user'], 'can call any endpoint'];
        yield 'the api through a command' => ['read', ['api', 'graphql'], 'can call any endpoint'];
        yield 'deleting a repository' => ['write', ['repo', 'delete', 'acme/app'], 'only repo view is offered'];
        yield 'secrets' => ['read', ['secret', 'list'], 'secrets are not for the model'];
        yield 'logging in' => ['read', ['auth', 'login'], 'only auth status is offered'];
        yield 'a workflow' => ['write', ['workflow', 'run', 'deploy.yml'], 'running a workflow is for the person'];
        yield 'an alias' => ['write', ['alias', 'set', 'x', 'api user'], 'aliases can run programs'];
        yield 'an unknown command' => ['read', ['pr', 'frobnicate'], 'pr frobnicate is not allowed in gh_read'];
        yield 'a write command in the read tool' => ['read', ['pr', 'create', '--fill'], 'pr create is a write command: use gh_write'];
        yield 'a read command in the write tool' => ['write', ['pr', 'view', '1'], 'pr view is a read command: use gh_read'];
        yield 'a jq filter can print the environment' => ['read', ['pr', 'view', '1', '--json', 'title', '--jq', 'env'], 'it can send files, run programs or read the environment'];
        yield 'a short jq filter' => ['read', ['pr', 'list', '-q', '.[]'], 'does not take the option -q'];
        yield 'a template' => ['read', ['pr', 'view', '1', '--template', '{{.title}}'], 'does not take the option --template'];
        yield 'the browser' => ['read', ['pr', 'view', '1', '--web'], 'does not take the option --web'];
        yield 'another repository' => ['read', ['pr', 'view', '1', '--repo', 'evil/x'], 'does not take the option --repo'];
        yield 'another repository, short' => ['read', ['pr', 'list', '-R', 'evil/x'], 'does not take the option -R'];
        yield 'watching for ever' => ['read', ['pr', 'checks', '--watch'], 'does not take the option --watch'];
        yield 'a body from a file' => ['write', ['pr', 'create', '--title', 't', '--body-file', '/etc/passwd'], 'does not take the option --body-file'];
        yield 'a body from a file with an equals sign' => ['write', ['pr', 'comment', '1', '--body-file=.env'], 'does not take the option --body-file'];
        yield 'a template file' => ['write', ['pr', 'create', '--fill', '-T', '.env'], 'does not take the option -T'];
        yield 'an attachment' => ['write', ['pr', 'create', '--fill', '--attach', 'secret.png'], 'does not take the option --attach'];
        yield 'the editor' => ['write', ['pr', 'create', '--editor'], 'does not take the option --editor'];
        yield 'json fields with a filter in them' => ['read', ['pr', 'view', '1', '--json', 'title;rm'], '--json takes field names separated by commas'];
        yield 'json without fields' => ['read', ['pr', 'view', '1', '--json'], 'the option --json needs a value'];
        yield 'a value for a flag' => ['read', ['pr', 'list', '--draft=yes'], 'the option --draft takes no value'];
        yield 'two arguments where one fits' => ['read', ['pr', 'view', '1', '2'], 'takes one argument besides its options'];
        yield 'an argument where none fits' => ['read', ['pr', 'list', '1'], 'takes no argument besides its options'];
        yield 'an argument that is a path' => ['read', ['pr', 'view', '../../etc/passwd'], 'is not a number, a branch'];
        yield 'an argument that is another address' => ['read', ['pr', 'view', 'https://evil.example/x'], 'is not a number, a branch'];
        yield 'a pull request without a title' => ['write', ['pr', 'create', '--body', 'b'], 'pr create cannot ask questions here: it needs --title (or --fill)'];
        yield 'a pull request without a body' => ['write', ['pr', 'create', '--title', 't'], 'pr create cannot ask questions here: it needs --body (or --fill)'];
        yield 'a comment without a body' => ['write', ['pr', 'comment', '1'], 'pr comment cannot ask questions here: it needs --body'];
        yield 'an issue without a title' => ['write', ['issue', 'create', '--body', 'b'], 'issue create cannot ask questions here: it needs --title'];
        yield 'a value that is missing at the end' => ['write', ['pr', 'comment', '1', '--body'], 'the option --body needs a value'];
    }

    #[DataProvider('refused')]
    public function testWhatCouldSendFilesRunProgramsOrChangeWhoMayDoWhatIsRefusedWithAReason(string $mode, array $args, string $reason): void
    {
        $refusal = GhPolicy::refusal($mode, $args);

        self::assertNotNull($refusal, implode(' ', $args));
        self::assertStringContainsString($reason, (string) $refusal);
    }
}
