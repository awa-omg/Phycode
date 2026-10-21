<?php

declare(strict_types=1);

namespace Phycode\Tests\Unit\Git;

use Phycode\Git\GitPolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class GitPolicyTest extends TestCase
{
    public static function decisions(): iterable
    {
        yield 'read status' => ['read', ['status'], null];
        yield 'read log with count' => ['read', ['log', '--oneline', '-n', '5'], null];
        yield 'read diff stat' => ['read', ['diff', '--stat', 'HEAD~1'], null];
        yield 'read show blob' => ['read', ['show', 'HEAD:README.md'], null];
        yield 'read blame with copies' => ['read', ['blame', '-C', 'src/a.php'], null];
        yield 'read current branch' => ['read', ['branch', '--show-current'], null];
        yield 'read branch listing' => ['read', ['branch', '-a', '-v'], null];
        yield 'read branch delete refused' => ['read', ['branch', '-d', 'old'], 'does not allow -d'];
        yield 'read branch name refused' => ['read', ['branch', 'feature'], 'takes no names'];
        yield 'read tag listing' => ['read', ['tag', '-l'], null];
        yield 'read tag name refused' => ['read', ['tag', 'v1.0'], 'takes no names'];
        yield 'read remote verbose' => ['read', ['remote', '-v'], null];
        yield 'read remote add refused' => ['read', ['remote', 'add', 'x', 'u'], 'takes no names'];
        yield 'read unknown command' => ['read', ['config', '--list'], 'not allowed'];
        yield 'read output file refused' => ['read', ['log', '--output=/tmp/x'], 'refused in every git tool'];
        yield 'read no-index refused' => ['read', ['diff', '--no-index', 'a', 'b'], 'refused in every git tool'];
        yield 'read push points to remote tool' => ['read', ['push'], 'git_remote'];
        yield 'read git-dir refused' => ['read', ['status', '--git-dir=/x'], 'refused in every git tool'];
        yield 'read force flag refused' => ['read', ['log', '-f'], 'refused in every git tool'];
        yield 'read empty' => ['read', [], 'subcommand'];
        yield 'read option before subcommand' => ['read', ['-c', 'x=y', 'status'], 'subcommand'];

        yield 'write add path' => ['write', ['add', 'src/a.php'], null];
        yield 'write add all' => ['write', ['add', '-A'], null];
        yield 'write add patch refused' => ['write', ['add', '-p'], 'does not allow -p'];
        yield 'write add interactive refused' => ['write', ['add', '--interactive'], 'does not allow --interactive'];
        yield 'write add clustered force refused' => ['write', ['add', '-Af'], 'refused in every git tool'];
        yield 'write commit' => ['write', ['commit', '-m', 'msg'], null];
        yield 'write commit all clustered' => ['write', ['commit', '-am', 'msg'], null];
        yield 'write commit value looks like a flag' => ['write', ['commit', '-m', '-c fix'], null];
        yield 'write commit attached value' => ['write', ['commit', '-mfeature'], null];
        yield 'write commit long form' => ['write', ['commit', '--message=feat: x'], null];
        yield 'write commit without message' => ['write', ['commit'], 'git commit needs -m or --message'];
        yield 'write commit amend refused' => ['write', ['commit', '--amend', '-m', 'x'], 'refused in every git tool'];
        yield 'write commit file refused' => ['write', ['commit', '-F', '/etc/passwd'], 'does not allow -F'];
        yield 'write commit no-verify refused' => ['write', ['commit', '-m', 'x', '--no-verify'], 'refused in every git tool'];
        yield 'write commit dash n refused' => ['write', ['commit', '-n', '-m', 'x'], 'does not allow -n'];
        yield 'write branch create' => ['write', ['branch', 'feature/x'], null];
        yield 'write branch safe delete' => ['write', ['branch', '-d', 'old'], null];
        yield 'write branch force delete refused' => ['write', ['branch', '-D', 'old'], 'refused in every git tool'];
        yield 'write branch rename' => ['write', ['branch', '-m', 'a', 'b'], null];
        yield 'write branch force refused' => ['write', ['branch', '-f', 'x'], 'refused in every git tool'];
        yield 'write switch create' => ['write', ['switch', '-c', 'feature'], null];
        yield 'write switch existing' => ['write', ['switch', 'main'], null];
        yield 'write switch force refused' => ['write', ['switch', '-f', 'main'], 'refused in every git tool'];
        yield 'write switch discard refused' => ['write', ['switch', '--discard-changes', 'main'], 'does not allow --discard-changes'];
        yield 'write switch reset-create refused' => ['write', ['switch', '-C', 'x'], 'does not allow -C'];
        yield 'write checkout refused' => ['write', ['checkout', 'main'], 'git switch'];
        yield 'write tag lightweight' => ['write', ['tag', 'v1.0.0'], null];
        yield 'write tag annotated' => ['write', ['tag', '-a', 'v1.0.0', '-m', 'release'], null];
        yield 'write tag annotated without message' => ['write', ['tag', '-a', 'v1.0.0'], 'needs -m'];
        yield 'write tag delete refused' => ['write', ['tag', '-d', 'v1'], 'does not allow -d'];
        yield 'write tag force refused' => ['write', ['tag', '-f', 'v1'], 'refused in every git tool'];
        yield 'write stash push' => ['write', ['stash', 'push', '-m', 'wip'], null];
        yield 'write stash pop' => ['write', ['stash', 'pop'], null];
        yield 'write stash list' => ['write', ['stash', 'list'], null];
        yield 'write stash drop refused' => ['write', ['stash', 'drop'], 'drop and clear'];
        yield 'write stash clear refused' => ['write', ['stash', 'clear'], 'drop and clear'];
        yield 'write reset soft' => ['write', ['reset', '--soft', 'HEAD~1'], null];
        yield 'write reset mixed' => ['write', ['reset', '--mixed'], null];
        yield 'write reset hard refused' => ['write', ['reset', '--hard', 'HEAD'], 'refused in every git tool'];
        yield 'write reset paths' => ['write', ['reset', 'HEAD', '--', 'src/a.php'], null];
        yield 'write restore staged' => ['write', ['restore', '--staged', 'src/a.php'], null];
        yield 'write restore working tree refused' => ['write', ['restore', 'src/a.php'], 'needs -S or --staged'];
        yield 'write merge no fast forward' => ['write', ['merge', '--no-ff', 'feature'], null];
        yield 'write merge abort refused' => ['write', ['merge', '--abort'], 'refused in every git tool'];
        yield 'write merge strategy refused' => ['write', ['merge', '-X', 'theirs', 'feature'], 'refused in every git tool'];
        yield 'write clean refused' => ['write', ['clean', '-fd'], 'git clean'];
        yield 'write rebase refused' => ['write', ['rebase', 'main'], 'git rebase'];
        yield 'write status points to read tool' => ['write', ['status'], 'git_read'];
        yield 'write push points to remote tool' => ['write', ['push', 'origin', 'main'], 'git_remote'];

        yield 'remote fetch' => ['remote', ['fetch', 'origin'], null];
        yield 'remote fetch prune refused' => ['remote', ['fetch', '--prune', 'origin'], 'refused in every git tool'];
        yield 'remote pull fast forward' => ['remote', ['pull', '--ff-only'], null];
        yield 'remote pull without fast forward' => ['remote', ['pull'], 'needs --ff-only'];
        yield 'remote pull rebase refused' => ['remote', ['pull', '--rebase', '--ff-only'], 'does not allow --rebase'];
        yield 'remote push branch' => ['remote', ['push', 'origin', 'main'], null];
        yield 'remote push upstream' => ['remote', ['push', '-u', 'origin', 'feature'], null];
        yield 'remote push tags' => ['remote', ['push', '--tags'], null];
        yield 'remote push refspec' => ['remote', ['push', 'origin', 'HEAD:refs/heads/x'], null];
        yield 'remote push force refused' => ['remote', ['push', '--force', 'origin', 'main'], 'refused in every git tool'];
        yield 'remote push forced refspec refused' => ['remote', ['push', 'origin', '+main'], 'refspec'];
        yield 'remote push deleting refspec refused' => ['remote', ['push', 'origin', ':old'], 'refspec'];
        yield 'remote push delete refused' => ['remote', ['push', '--delete', 'origin', 'x'], 'does not allow --delete'];
        yield 'remote push short delete refused' => ['remote', ['push', '-d', 'origin', 'x'], 'does not allow -d'];
        yield 'remote push no-verify refused' => ['remote', ['push', '--no-verify', 'origin', 'main'], 'refused in every git tool'];
        yield 'remote commit points to write tool' => ['remote', ['commit', '-m', 'x'], 'git_write'];
    }

    #[DataProvider('decisions')]
    public function testDecisions(string $mode, array $args, ?string $expected): void
    {
        $refusal = GitPolicy::refusal($mode, $args);
        if ($expected === null) {
            self::assertNull($refusal, (string) $refusal);

            return;
        }
        self::assertNotNull($refusal, 'the command should be refused');
        self::assertStringContainsString($expected, $refusal);
    }
}
