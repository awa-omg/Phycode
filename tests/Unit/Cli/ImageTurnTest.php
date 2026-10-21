<?php

declare(strict_types=1);

namespace Phycode\Tests\Unit\Cli;

use Phycode\Agent\AgentRunner;
use Phycode\Agent\Conversation;
use Phycode\Agent\RunSetup;
use Phycode\Config\Config;
use Phycode\Llm\Image;
use Phycode\Session\ContextBudget;
use Phycode\Session\Transcript;
use Phycode\Support\Json;
use Phycode\Tests\Support\Kit;
use Phycode\Tests\Support\Pictures;
use Phycode\Tests\Support\RecordingUi;
use Phycode\Tests\Support\ScriptedClient;
use Phycode\Tests\Support\SessionHarness;
use Phycode\Tools\ReadTool;
use Phycode\Tools\ToolRegistry;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ImageTurnTest extends TestCase
{
    private ?SessionHarness $harness = null;
    private RecordingUi $ui;

    protected function setUp(): void
    {
        $this->ui = new RecordingUi();
    }

    protected function tearDown(): void
    {
        $this->harness?->end();
    }

    private function session(CountingProvider $provider, bool $vision = true): \Phycode\Cli\ChatSession
    {
        $this->harness = new SessionHarness(['default_profile' => 'local', 'profiles' => ['local' => ['base_url' => 'http://127.0.0.1:9', 'model' => 'qwen', 'tool_mode' => 'native', 'vision' => $vision]]]);

        return $this->harness->session($this->harness->kernel($provider, $this->ui));
    }

    public function testTheReadToolAnswersWithTheImageForTheRunnerAndReadsTextAsBefore(): void
    {
        $root = (string) realpath(Kit::folder('image-read'));
        copy(Pictures::file('shot.png'), $root . '/shot.png');
        Kit::write($root . '/notes.txt', "one\ntwo\n");
        $context = Kit::context($root, Kit::agent(), Kit::policy('ask'), $this->ui);

        $image = (new ReadTool())->execute(['path' => 'shot.png'], $context);
        $text = (new ReadTool())->execute(['path' => 'notes.txt'], $context);

        self::assertTrue($image->ok);
        self::assertSame('image shot.png: PNG 1x1, 0 KB. It is shown to you next.', $image->content);
        self::assertSame(['shot.png'], array_column($image->meta['images'], 'name'));
        self::assertArrayNotHasKey('images', $text->meta);
        self::assertStringContainsString('     1  one', $text->content);

        file_put_contents($root . '/huge.png', Pictures::bytes() . str_repeat("\0", Image::MAX_BYTES));
        $huge = (new ReadTool())->execute(['path' => 'huge.png'], $context);
        self::assertFalse($huge->ok);
        self::assertStringContainsString('an image may be at most 3.5 MB', $huge->content);
        file_put_contents($root . '/fake.png', "text with a png name\n");
        self::assertStringContainsString('     1  text with a png name', (new ReadTool())->execute(['path' => 'fake.png'], $context)->content, 'a text file is text, whatever its name');
    }

    public function testTheImagesOfAReplyComeAfterAllItsToolResults(): void
    {
        $root = (string) realpath(Kit::folder('image-runner'));
        copy(Pictures::file('a.png'), $root . '/a.png');
        copy(Pictures::file('b.jpg', 'jpg'), $root . '/b.jpg');
        Kit::write($root . '/notes.txt', "hello\n");
        $registry = new ToolRegistry();
        $registry->register(new ReadTool());
        $client = new ScriptedClient([
            new \Phycode\Llm\LlmResponse('', '', [new \Phycode\Llm\ToolCall('c1', 'read', '{"path":"a.png"}'), new \Phycode\Llm\ToolCall('c2', 'read', '{"path":"notes.txt"}'), new \Phycode\Llm\ToolCall('c3', 'read', '{"path":"b.jpg"}')], \Phycode\Llm\Canonical\FinishReason::TOOL_CALLS, new \Phycode\Llm\Canonical\Usage()),
            ScriptedClient::text('I see two pictures'),
        ]);
        $conversation = new Conversation();
        $conversation->setSystem('system');
        $conversation->addUser('look at the pictures');

        $outcome = (new AgentRunner())->run($conversation, new RunSetup($client, 'native', $registry, Kit::context($root, Kit::agent(), Kit::policy('yolo'), $this->ui), 5, false));

        self::assertSame('I see two pictures', $outcome->text);
        $second = $client->requests[1]['messages'];
        self::assertSame(['system', 'user', 'assistant', 'tool', 'tool', 'tool', 'user'], array_column($second, 'role'));
        $last = $second[6];
        self::assertSame('Images from the tool calls above: a.png, b.jpg', $last['content']);
        self::assertSame(['a.png', 'b.jpg'], array_column($last['images'], 'name'));
    }

    public function testTheImageCommandSendsThePictureWithTheQuestionAndASavedSessionKeepsOnlyALine(): void
    {
        $provider = new CountingProvider(['local' => 'qwen'], ['native'], replies: [ScriptedClient::text('The button is red.')]);
        $session = $this->session($provider);
        copy(Pictures::file('login-error.png'), $this->harness->cwd . '/login-error.png');

        self::assertTrue($session->isCommand('/image login-error.png why red?'));
        $session->handle('/image login-error.png why is the button red?');

        $sent = $provider->sent[0]['messages'];
        $user = $sent[array_key_last($sent)];
        self::assertSame('why is the button red?', $user['content']);
        self::assertSame(['login-error.png'], array_column($user['images'], 'name'));
        self::assertSame(Pictures::base64(), $user['images'][0]['data']);

        $file = $this->harness->cwd . '/.phycode/sessions/' . $session->listSessions()[0]['id'] . '.json';
        $saved = (string) file_get_contents($file);
        self::assertStringNotContainsString(Pictures::base64(), $saved, 'the file does not carry the picture');
        self::assertStringContainsString('why is the button red?\n[image: login-error.png, PNG 1x1]', $saved);
        self::assertStringNotContainsString('"images"', $saved);
    }

    public function testTheImageCommandAcceptsAQuotedPathAndAnAnswerlessQuestion(): void
    {
        $provider = new CountingProvider(['local' => 'qwen'], ['native'], replies: [ScriptedClient::text('A pixel.')]);
        $session = $this->session($provider);
        mkdir($this->harness->cwd . '/my shots');
        copy(Pictures::file('x.png'), $this->harness->cwd . '/my shots/one two.png');

        $session->handle('/image "my shots/one two.png"');

        $sent = $provider->sent[0]['messages'];
        self::assertSame('What do you see in this image?', $sent[array_key_last($sent)]['content']);
        self::assertSame(['one two.png'], array_column($sent[array_key_last($sent)]['images'], 'name'));
    }

    public static function refused(): iterable
    {
        yield 'no path' => ['/image', 'usage: /image PATH [TEXT]'];
        yield 'a file that is not there' => ['/image nothing.png', 'nothing.png does not exist'];
        yield 'text that is not an image' => ['/image notes.txt', 'is not an image that models read'];
    }

    #[DataProvider('refused')]
    public function testTheImageCommandSaysWhatIsWrongAndSendsNothing(string $command, string $message): void
    {
        $provider = new CountingProvider(['local' => 'qwen'], ['native']);
        $session = $this->session($provider);
        file_put_contents($this->harness->cwd . '/notes.txt', "hello\n");

        $session->handle($command);

        self::assertSame([], $provider->sent);
        $errors = array_filter($this->ui->events, static fn (array $event): bool => $event[0] === 'notice' && $event[1] === 'error' && str_contains($event[2], $message));
        self::assertNotSame([], $errors);
    }

    public function testAProfileThatIsNotSetToReadImagesRefusesAndSaysHowToSetIt(): void
    {
        $provider = new CountingProvider(['local' => 'qwen'], ['native']);
        $session = $this->session($provider, false);
        copy(Pictures::file('a.png'), $this->harness->cwd . '/a.png');

        $session->handle('/image a.png what is this?');

        self::assertSame([], $provider->sent);
        self::assertNotSame([], array_filter($this->ui->events, static fn (array $event): bool => $event[0] === 'notice' && str_contains($event[2], 'the profile local is not set to read images: put "vision": true in it')));
    }

    public function testEachPictureCountsAsAboutThirteenHundredTokensInTheEstimate(): void
    {
        $conversation = new Conversation();
        $conversation->addUser('look', [Image::fromBytes(Pictures::bytes(), 'a.png'), Image::fromBytes(Pictures::bytes('gif'), 'b.gif')]);
        $plain = new Conversation();
        $plain->addUser('look');

        self::assertSame(2 * Image::ESTIMATED_TOKENS, ContextBudget::estimate($conversation->messages()) - ContextBudget::estimate($plain->messages()));
    }

    public function testTheVisionSettingAndItsDefaultsByProtocolAndHost(): void
    {
        $profile = static fn (array $fields): \Phycode\Config\Profile => Config::fromArrays(['profiles' => ['p' => $fields + ['model' => 'm']]], null, [], '/nonexistent')->profile('p');

        self::assertTrue($profile(['base_url' => 'https://api.anthropic.com', 'protocol' => 'anthropic'])->supportsVision());
        self::assertTrue($profile(['base_url' => 'https://api.openai.com/v1', 'protocol' => 'openai-responses'])->supportsVision());
        self::assertTrue($profile(['base_url' => 'https://api.openai.com/v1'])->supportsVision());
        self::assertTrue($profile(['base_url' => 'https://openrouter.ai/api/v1'])->supportsVision());
        self::assertFalse($profile(['base_url' => 'http://127.0.0.1:8080'])->supportsVision(), 'a local server does not say');
        self::assertFalse($profile(['base_url' => 'https://api.deepseek.com'])->supportsVision());
        self::assertTrue($profile(['base_url' => 'http://127.0.0.1:8080', 'vision' => true])->supportsVision());
        self::assertFalse($profile(['base_url' => 'https://api.anthropic.com', 'protocol' => 'anthropic', 'vision' => false])->supportsVision());
        $this->expectException(\Phycode\Config\ConfigException::class);
        $this->expectExceptionMessage("profile 'p': vision must be true or false");
        $profile(['base_url' => 'http://127.0.0.1:8080', 'vision' => 'yes']);
    }
}
