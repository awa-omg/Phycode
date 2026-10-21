<?php

declare(strict_types=1);

namespace Phycode\Tests\Unit\Security;

use Phycode\Security\ShellCommand;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ShellCommandTest extends TestCase
{
    public static function lines(): iterable
    {
        yield 'one command' => ['git status', ['git status']];
        yield 'and' => ['git status && rm -rf x', ['git status', 'rm -rf x']];
        yield 'or' => ['a || b', ['a', 'b']];
        yield 'semicolon' => ['a; b ;c', ['a', 'b', 'c']];
        yield 'pipe' => ['cat x | grep y', ['cat x', 'grep y']];
        yield 'pipe and stderr' => ['a |& b', ['a', 'b']];
        yield 'background' => ['sleep 5 & echo hi', ['sleep 5', 'echo hi']];
        yield 'newline' => ["a\nb\n\nc", ['a', 'b', 'c']];
        yield 'a line continuation joins' => ["git \\\nstatus", ['git status']];
        yield 'a comment is not a command' => ["ls # && rm -rf x\npwd", ['ls', 'pwd']];
        yield 'a hash inside a word is not a comment' => ['echo a#b; ls', ['echo a#b', 'ls']];
        yield 'quotes hide the operators' => ['echo "a && b" \'c; d\'', ['echo "a && b" \'c; d\'']];
        yield 'an escaped operator' => ['echo a\\;b', ['echo a\\;b']];
        yield 'a redirect with an ampersand is not a separator' => ['make 2>&1 | tee log', ['make 2>&1', 'tee log']];
        yield 'both streams to a file' => ['make &> log; ls', ['make &> log', 'ls']];
        yield 'clobber redirect' => ['echo a >| file', ['echo a >| file']];
        yield 'command substitution' => ['echo $(rm x)', ['echo $(rm x)', 'rm x']];
        yield 'backticks' => ['echo `rm x`', ['echo `rm x`', 'rm x']];
        yield 'substitution in double quotes runs' => ['echo "$(rm x)"', ['echo "$(rm x)"', 'rm x']];
        yield 'substitution in single quotes does not' => ["echo '\$(rm x)'", ["echo '\$(rm x)'"]];
        yield 'nested substitution' => ['a $(b $(c))', ['a $(b $(c))', 'b $(c)', 'c']];
        yield 'a parenthesis inside quotes in a substitution' => ['a $(echo ")")', ['a $(echo ")")', 'echo ")"']];
        yield 'process substitution' => ['diff <(ls a) <(ls b)', ['diff <(ls a) <(ls b)', 'ls a', 'ls b']];
        yield 'a subshell' => ['(cd x && make) && ls', ['cd x', 'make', 'ls']];
        yield 'a group after an operator' => ['a && (b)', ['a', 'b']];
        yield 'a command in a parameter expansion' => ['echo ${x:-$(rm y)}', ['echo ${x:-$(rm y)}', 'rm y']];
        yield 'a plain parameter expansion' => ['echo ${x:-a;b} ${#y}', ['echo ${x:-a;b} ${#y}']];
        yield 'arithmetic is not a command' => ['echo $((1 + 2))', ['echo $((1 + 2))']];
        yield 'a command inside arithmetic' => ['echo $(($(rm x) + 1))', ['echo $(($(rm x) + 1))', 'rm x']];
        yield 'ansi-c string' => ["echo \$'a\\'b; c'; ls", ["echo \$'a\\'b; c'", 'ls']];
        yield 'a here-document body is not commands' => ["cat <<EOF > f\nrm -rf x\nEOF\nls", ['cat <<EOF > f', 'ls']];
        yield 'a here-document with a tab-stripped delimiter' => ["cat <<-EOF\n\trm x\n\tEOF\nls", ['cat <<-EOF', 'ls']];
        yield 'a quoted here-document does not expand' => ["cat <<'EOF'\n\$(rm x)\nEOF", ["cat <<'EOF'"]];
        yield 'an unquoted here-document expands' => ["cat <<EOF\n\$(rm x)\nEOF", ['cat <<EOF', 'rm x']];
        yield 'a here-string' => ['cat <<< "a && b"', ['cat <<< "a && b"']];
        yield 'a control-flow body' => ['for f in a b; do rm $f; done', ['for f in a b', 'do rm $f', 'done']];
        yield 'empty' => ['', []];
        yield 'an operator at the end' => ['npm test &&', null];
        yield 'a pipe at the end' => ['ls |', null];
        yield 'an operator at the start' => ['&& ls', null];
        yield 'two operators' => ['a && && b', null];
        yield 'a continued operator' => ["a &&\nb", ['a', 'b']];
        yield 'a quote that is not closed' => ['echo "a', null];
        yield 'a single quote that is not closed' => ["echo 'a", null];
        yield 'a substitution that is not closed' => ['echo $(a', null];
        yield 'a backtick that is not closed' => ['echo `a', null];
        yield 'a here-document without a delimiter' => ['cat <<', null];
        yield 'nesting that is too deep' => [str_repeat('$(', 12) . 'x' . str_repeat(')', 12), null];
    }

    #[DataProvider('lines')]
    public function testTheLineIsSplitIntoTheCommandsItRuns(string $line, ?array $expected): void
    {
        self::assertSame($expected, ShellCommand::segments($line));
    }

    public static function wordLists(): iterable
    {
        yield 'plain' => ['rm -rf x', ['rm', '-rf', 'x']];
        yield 'quotes come off' => ['git commit -m "a b" \'c d\'', ['git', 'commit', '-m', 'a b', 'c d']];
        yield 'an escaped space' => ['ls a\\ b', ['ls', 'a b']];
        yield 'an escape in double quotes' => ['echo "a\\"b"', ['echo', 'a"b']];
        yield 'a substitution stays in one word' => ['echo $(a b) c', ['echo', '$(a b)', 'c']];
        yield 'an empty quoted word' => ["a '' b", ['a', '', 'b']];
        yield 'a quote that is not closed' => ['a "b', null];
    }

    #[DataProvider('wordLists')]
    public function testWordsTakeTheQuotesOff(string $command, ?array $expected): void
    {
        self::assertSame($expected, ShellCommand::words($command));
    }

    public static function hidden(): iterable
    {
        yield 'sudo' => ['sudo rm -rf x', 'rm -rf x'];
        yield 'sudo with an option that takes a value' => ['sudo -u root rm -rf x', 'rm -rf x'];
        yield 'env and assignments' => ['env A=1 B=2 rm -rf x', 'rm -rf x'];
        yield 'a leading assignment' => ['FOO=bar rm -rf x', 'rm -rf x'];
        yield 'timeout with a duration' => ['timeout -s KILL 30 rm -rf x', 'rm -rf x'];
        yield 'nohup and nice' => ['nohup nice -n 5 rm -rf x', 'rm -rf x'];
        yield 'xargs' => ['xargs -n1 rm -rf', 'rm -rf'];
        yield 'quotes' => ['rm -rf "my dir"', 'rm -rf my dir'];
        yield 'a path to the program' => ['/bin/rm -rf x', 'rm -rf x'];
        yield 'a shell -c' => ['bash -c "git status && rm -rf x"', 'rm -rf x'];
        yield 'a login shell -lc' => ['sh -lc \'rm -rf x\'', 'rm -rf x'];
        yield 'sudo and a shell -c' => ['sudo sh -c "rm -rf x"', 'rm -rf x'];
        yield 'eval' => ['eval rm -rf x', 'rm -rf x'];
        yield 'a loop body' => ['do rm x', 'rm x'];
        yield 'a negation' => ['! rm x', 'rm x'];
    }

    #[DataProvider('hidden')]
    public function testViewsShowACommandBehindItsWrappers(string $command, string $form): void
    {
        self::assertContains($form, ShellCommand::views($command));
        self::assertSame($command, ShellCommand::views($command)[0], 'the command as written comes first');
    }

    public function testACommandThatIsNotAWrapperIsNotPeeled(): void
    {
        self::assertSame(['git push origin main'], ShellCommand::views('git push origin main'));
    }

    public function testOnlyTheShellToolsAreSplit(): void
    {
        self::assertSame(['bash: ', 'a && b'], ShellCommand::of('bash', 'bash: a && b'));
        self::assertNull(ShellCommand::of('write', 'write a.txt: x'));
        self::assertNull(ShellCommand::of('bash', 'bash'));
    }
}
