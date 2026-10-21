<?php

declare(strict_types=1);

namespace Phycode\Tools;

use Phycode\Agent\RunPolicy;
use Phycode\Diff\TextDiff;
use Phycode\Exec\ProcessRunner;
use Phycode\Support\Files;
use Phycode\Support\PhycodeException;

final class RunCodeTool extends BaseTool
{
    private const COMMANDS = ['python' => 'python3', 'javascript' => 'node'];

    private readonly array $languages;

    public function __construct()
    {
        $this->languages = array_values(array_filter(
            array_keys(self::COMMANDS),
            static fn (string $language): bool => ProcessRunner::which(self::COMMANDS[$language]) !== null,
        ));
    }

    public function name(): string
    {
        return 'run_code';
    }

    public function description(): string
    {
        return 'Run a short Python or JavaScript program in the project root and return its output. Needs user approval.';
    }

    public function category(): ToolCategory
    {
        return ToolCategory::Exec;
    }

    public function sandboxed(): bool
    {
        return true;
    }

    public function isAvailable(RunPolicy $policy): bool
    {
        return $this->languages !== [];
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'language' => ['type' => 'string', 'enum' => $this->languages, 'description' => 'Language of the snippet'],
                'code' => ['type' => 'string', 'description' => 'Complete program text'],
                'timeout' => ['type' => 'integer', 'description' => 'Seconds before the program is killed'],
            ],
            'required' => ['language', 'code'],
        ];
    }

    public function summarize(array $args): string
    {
        return 'run_code ' . (is_string($args['language'] ?? null) ? $args['language'] : '?');
    }

    public function preview(array $args, ToolContext $context): ?array
    {
        $language = is_string($args['language'] ?? null) ? $args['language'] : '?';
        $code = is_string($args['code'] ?? null) ? $args['code'] : '';

        return $code === '' ? null : TextDiff::change("run_code ({$language})", null, $code);
    }

    public function validate(array $args, ToolContext $context): void
    {
        $context->workspace->assertNotNamed(Args::string($args, 'code'));
        Files::runtimeFolder($context->workspace->root, 'tmp');
    }

    public function execute(array $args, ToolContext $context): ToolResult
    {
        $language = Args::string($args, 'language');
        $code = Args::string($args, 'code');
        $timeout = Args::int($args, 'timeout', 1, $context->limits->maxBashTimeout, $context->limits->bashTimeout);
        if (!in_array($language, $this->languages, true)) {
            throw new PhycodeException("language '{$language}' is not available on this machine");
        }

        $folder = Files::runtimeFolder($context->workspace->root, 'tmp');
        if (!is_dir($folder) && !mkdir($folder, 0700, true) && !is_dir($folder)) {
            return ToolResult::fail('cannot create the temporary folder');
        }
        $name = 'run-' . bin2hex(random_bytes(6)) . ($language === 'python' ? '.py' : '.js');
        if (file_put_contents($folder . '/' . $name, $code) === false) {
            return ToolResult::fail('cannot write the snippet');
        }

        try {
            $result = $context->sandbox->run(
                [self::COMMANDS[$language], '.phycode/tmp/' . $name],
                $context->workspace->root,
                $timeout,
                $context->limits->toolOutputBytes,
                $context->cancel,
            );
        } finally {
            @unlink($folder . '/' . $name);
        }

        return ProcessReport::toResult($result);
    }
}
