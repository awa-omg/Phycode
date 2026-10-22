<?php

declare(strict_types=1);

namespace Phycode\Tests\Unit\Plugin;

use Phycode\Plugin\PluginCli;
use Phycode\Plugin\Plugins;
use Phycode\Support\PhycodeException;
use Phycode\Tests\Support\Kit;
use PHPUnit\Framework\TestCase;

final class PluginsTest extends TestCase
{
    private string $config;
    private string $source;

    protected function setUp(): void
    {
        $this->config = Kit::folder('plugins-config');
        $this->source = realpath(Kit::folder('plugins-source')) . '/Review-Kit';
        Kit::write($this->source . '/plugin.json', json_encode(['name' => 'review-kit', 'description' => 'Review helpers', 'version' => '1.2.0']));
        Kit::write($this->source . '/agents/auditor.md', "---\nname: auditor\ndescription: Audits the change\nmode: subagent\ntools: [read, grep]\n---\nAudit.\n");
        Kit::write($this->source . '/skills/triage/SKILL.md', "---\nname: triage\ndescription: Triage a bug report\n---\nSteps.\n");
        Kit::write($this->source . '/prompts/summarize.md', "Summarize \$ARGUMENTS.\n");
    }

    public function testAFolderIsCopiedUnderTheNameOfItsManifestAndListedWithWhatItBrings(): void
    {
        $plugin = (new Plugins($this->config))->add($this->source);

        self::assertSame(['name' => 'review-kit', 'description' => 'Review helpers', 'version' => '1.2.0', 'parts' => ['agents', 'skills', 'prompts']], $plugin);
        self::assertFileExists($this->config . '/plugins/review-kit/agents/auditor.md');
        self::assertSame(0600, fileperms($this->config . '/plugins/review-kit/agents/auditor.md') & 0777);
        self::assertSame(['review-kit' => $this->config . '/plugins/review-kit/agents'], Plugins::agentFolders($this->config));
        self::assertSame(['review-kit' => $this->config . '/plugins/review-kit/skills'], Plugins::skillFolders($this->config));
        self::assertSame(['review-kit' => $this->config . '/plugins/review-kit/prompts'], Plugins::promptFolders($this->config));
    }

    public function testWithoutAManifestTheFolderNameIsTheName(): void
    {
        unlink($this->source . '/plugin.json');

        $plugin = (new Plugins($this->config))->add($this->source);

        self::assertSame('review-kit', $plugin['name']);
        self::assertSame('', $plugin['version']);
    }

    public function testInstallingTwiceIsRefusedAndRemovingDeletesTheFolder(): void
    {
        $plugins = new Plugins($this->config);
        $plugins->add($this->source);

        try {
            $plugins->add($this->source);
            self::fail('the plugin is installed already');
        } catch (PhycodeException $error) {
            self::assertStringContainsString('installed already: phycode plugin remove review-kit first', $error->getMessage());
        }
        $plugins->remove('review-kit');

        self::assertDirectoryDoesNotExist($this->config . '/plugins/review-kit');
        self::assertSame([], $plugins->all());
        $this->expectException(PhycodeException::class);
        $this->expectExceptionMessage('no plugin named review-kit');
        $plugins->remove('review-kit');
    }

    public function testAFolderWithNothingToLoadASymbolicLinkAndABadSourceLeaveNothingBehind(): void
    {
        $empty = realpath(Kit::folder('plugins-empty')) . '/empty';
        Kit::write($empty . '/README.md', 'nothing');
        symlink('/etc/passwd', $this->source . '/prompts/link.md');
        $plugins = new Plugins($this->config);

        foreach ([$empty => 'there is nothing to load', $this->source => 'symbolic links', 'ftp://x/y' => 'neither a folder nor a git address'] as $source => $message) {
            try {
                $plugins->add($source);
                self::fail("{$source} must be refused");
            } catch (PhycodeException $error) {
                self::assertStringContainsString($message, $error->getMessage());
            }
        }
        self::assertSame([], glob($this->config . '/plugins/*') ?: []);
        self::assertSame([], glob($this->config . '/plugins/.staging*') ?: []);
    }

    public function testAPluginThatIsTooBigIsRefused(): void
    {
        for ($i = 0; $i <= Plugins::MAX_FILES; $i++) {
            file_put_contents($this->source . '/prompts/p' . $i . '.md', 'x');
        }

        $this->expectException(PhycodeException::class);
        $this->expectExceptionMessage('at most 1000 files');
        (new Plugins($this->config))->add($this->source);
    }

    public function testAGitRepositoryIsClonedWithoutItsGitFolderOrItsHooks(): void
    {
        $repository = realpath(Kit::folder('plugins-git')) . '/remote-kit.git';
        $git = static fn (string ...$arguments): string => (string) shell_exec('git -C ' . escapeshellarg($repository) . ' -c user.name=T -c user.email=t@example.invalid ' . implode(' ', array_map('escapeshellarg', $arguments)) . ' 2>&1');
        mkdir($repository, 0700, true);
        $git('init', '-q', '-b', 'main');
        Kit::write($repository . '/prompts/hello.md', "Hello.\n");
        $git('add', '.');
        $git('commit', '-q', '-m', 'one');

        $plugin = (new Plugins($this->config))->add('file://' . $repository);

        self::assertSame('remote-kit', $plugin['name']);
        self::assertFileExists($this->config . '/plugins/remote-kit/prompts/hello.md');
        self::assertDirectoryDoesNotExist($this->config . '/plugins/remote-kit/.git');
        self::assertSame([], glob($this->config . '/plugins/.staging*') ?: []);
    }

    public function testAGitAddressThatCannotBeClonedSaysWhy(): void
    {
        $this->expectException(PhycodeException::class);
        $this->expectExceptionMessage('git clone failed');
        (new Plugins($this->config))->add('file:///no/such/repository.git');
    }

    public function testTheCommandInstallsListsAndRemoves(): void
    {
        $run = function (array $args): array {
            $out = fopen('php://memory', 'w+');
            $err = fopen('php://memory', 'w+');
            $status = PluginCli::run($args, $this->config, $out, $err);
            rewind($out);
            rewind($err);

            return [$status, (string) stream_get_contents($out), (string) stream_get_contents($err)];
        };

        self::assertSame([0, "No plugins. phycode plugin add ./my-plugin installs one.\n", ''], $run([]));
        self::assertSame([0, "installed review-kit: agents, skills, prompts\n", ''], $run(['add', $this->source]));
        self::assertMatchesRegularExpression('/^review-kit\s+1\.2\.0\s+agents, skills, prompts\s+Review helpers$/m', $run(['list'])[1]);
        self::assertSame(1, $run(['add'])[0]);
        self::assertSame(1, $run(['remove', 'ghost'])[0]);
        self::assertStringContainsString("unknown plugin command 'x'", $run(['x'])[2]);
        self::assertSame([0, "removed review-kit\n", ''], $run(['remove', 'review-kit']));
    }
}
