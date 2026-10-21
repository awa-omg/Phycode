<?php

declare(strict_types=1);

namespace Phycode\Tools;

use Phycode\Llm\ChatOptions;
use Phycode\Llm\ClientProvider;
use Phycode\Llm\UsageMeter;
use Phycode\Security\SecretFiles;
use Phycode\Support\PhycodeException;
use Phycode\Support\Redaction;
use Phycode\Support\Text;

final class OracleTool extends BaseTool
{
    public const MAX_FILES = 8;
    public const MAX_FILE_BYTES = 100000;
    public const MAX_TOTAL_BYTES = 300000;
    private const MAX_TEXT = 30000;
    private const PROMPT = <<<'TXT'
        You are the oracle: a senior engineer that a coding agent asks when it is stuck, before a risky change, or for a second opinion.
        You cannot run anything and you cannot see the project beyond what is below. Answer the question directly: start with your
        recommendation, then the reasons, then the risks and what you could not judge from the material. Name files and lines of the
        material when you point at code. Do not ask questions back; if something is missing, say what you assumed.
        TXT;

    private int $calls = 0;

    public function __construct(
        private readonly \Closure $settings,
        private readonly \Closure $providers,
        private readonly \Closure $usage,
    ) {
    }

    public function name(): string
    {
        return 'oracle';
    }

    public function description(): string
    {
        return 'Ask a stronger second model (the oracle) for a diagnosis, a review or a plan when you are stuck or before a risky change. '
            . 'It answers one question and sees only what you send: the question, your context and the files you name; it cannot read '
            . 'the project or run anything, so put the facts in context and the code in files. It is slow and costs tokens, and the number '
            . 'of calls is capped: ask once, with everything it needs, and not for what you can find by reading.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'question' => ['type' => 'string', 'description' => 'What you need to know or decide, as one clear question'],
                'context' => ['type' => 'string', 'description' => 'What you have found out so far: the error, what you tried, the constraints'],
                'files' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                    'description' => 'Paths of project files to send whole, at most ' . self::MAX_FILES . '. Files that hold secrets are refused.',
                ],
            ],
            'required' => ['question'],
        ];
    }

    public function category(): ToolCategory
    {
        return ToolCategory::Exec;
    }

    public function autoApproved(): bool
    {
        return (($this->settings)()['approval'] ?? 'ask') === 'allow';
    }

    public function summarize(array $args): string
    {
        $profile = ($this->settings)()['profile'] ?? '?';
        $files = is_array($args['files'] ?? null) ? array_filter($args['files'], 'is_string') : [];
        $question = is_string($args['question'] ?? null) ? $args['question'] : '';

        return "oracle ({$profile}): " . Text::oneLine($question, 100) . ($files === [] ? '' : ' [files: ' . Text::oneLine(implode(', ', $files), 120) . ']');
    }

    public function validate(array $args, ToolContext $context): void
    {
        if (($this->settings)() === null) {
            throw new PhycodeException('no oracle is set up: the config has no oracle section');
        }
        if (trim(Args::string($args, 'question')) === '') {
            throw new PhycodeException("argument 'question' is empty");
        }
        $this->checked(Args::string($args, 'question'), 'question');
        $this->checked(Args::string($args, 'context', false), 'context');
        $this->files($args, $context);
    }

    public function execute(array $args, ToolContext $context): ToolResult
    {
        $settings = ($this->settings)();
        if ($settings === null) {
            return ToolResult::fail('no oracle is set up: the config has no oracle section');
        }
        if ($this->calls >= $settings['max_calls']) {
            return ToolResult::fail("the oracle was asked {$this->calls} times in this session, which is its limit (oracle.max_calls); decide with what you have");
        }
        $files = $this->files($args, $context);

        $request = "Question:\n" . trim(Args::string($args, 'question'));
        $notes = trim(Args::string($args, 'context', false));
        if ($notes !== '') {
            $request .= "\n\nContext from the agent:\n" . $notes;
        }
        $request = Redaction::text($request, $context->secrets);
        foreach ($files as $path => $text) {
            $request .= "\n\nFile {$path}:\n" . Redaction::exact($text, $context->secrets);
        }

        $this->calls++;
        $profile = $settings['profile'];
        $context->ui->notice('info', "asking the oracle ({$profile}), " . count($files) . ' file(s)');
        try {
            $client = ($this->providers)()->forProfile($profile);
            $response = $client->chat(
                [['role' => 'system', 'content' => self::PROMPT], ['role' => 'user', 'content' => $request]],
                [],
                new ChatOptions(cancel: $context->cancel),
            );
        } catch (PhycodeException $error) {
            return ToolResult::fail("the oracle ({$profile}) could not answer: " . Text::headTail(Redaction::text($error->getMessage(), $context->secrets), 400));
        }
        ($this->usage)()->record($profile, null, $response->usage);

        $answer = trim(Redaction::exact($response->content, $context->secrets));
        if ($answer === '') {
            return ToolResult::fail("the oracle ({$profile}) sent no answer (finish: {$response->finishReason}); ask again with a shorter question");
        }

        return ToolResult::ok($answer, ['profile' => $profile, 'calls' => $this->calls, 'max_calls' => $settings['max_calls'], 'files' => array_keys($files)]);
    }

    private function files(array $args, ToolContext $context): array
    {
        $paths = $args['files'] ?? [];
        if (!is_array($paths) || !array_is_list($paths) || count($paths) > self::MAX_FILES) {
            throw new PhycodeException("argument 'files' must be a list of at most " . self::MAX_FILES . ' paths');
        }
        $files = [];
        $total = 0;
        foreach ($paths as $path) {
            if (!is_string($path) || trim($path) === '') {
                throw new PhycodeException("argument 'files' must contain only paths");
            }
            $file = $context->workspace->resolve($path);
            $real = $context->workspace->relative($file);
            if (SecretFiles::guarded($path) || SecretFiles::guarded($real)) {
                throw new PhycodeException("{$path} usually holds secrets or phycode's own files, so it is never sent to the oracle");
            }
            if (!is_file($file)) {
                throw new PhycodeException("not a file: {$path}");
            }
            $size = (int) filesize($file);
            $text = (string) file_get_contents($file, false, null, 0, self::MAX_FILE_BYTES);
            if (Text::isBinary(substr($text, 0, 8192))) {
                throw new PhycodeException("binary file: {$path}");
            }
            $total += strlen($text);
            if ($total > self::MAX_TOTAL_BYTES) {
                throw new PhycodeException('the files are larger than ' . intdiv(self::MAX_TOTAL_BYTES, 1000) . ' KB together; send fewer or put the relevant part in context');
            }
            $files[$real] = $size > self::MAX_FILE_BYTES ? $text . "\n[clipped: the first " . self::MAX_FILE_BYTES . " of {$size} bytes]" : $text;
        }

        return $files;
    }

    private function checked(string $text, string $name): void
    {
        if (strlen($text) > self::MAX_TEXT) {
            throw new PhycodeException("argument '{$name}' is longer than " . intdiv(self::MAX_TEXT, 1000) . ' KB; keep to what the oracle needs');
        }
    }
}
