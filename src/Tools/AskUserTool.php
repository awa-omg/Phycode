<?php

declare(strict_types=1);

namespace Phycode\Tools;

use Phycode\Agent\RunPolicy;
use Phycode\Support\PhycodeException;
use Phycode\Support\Text;

final class AskUserTool extends BaseTool
{
    private const MAX_QUESTION_BYTES = 1000;
    private const MAX_OPTIONS = 6;
    private const MAX_OPTION_BYTES = 200;

    public function __construct(private readonly bool $enabled)
    {
    }

    public function name(): string
    {
        return 'ask_user';
    }

    public function description(): string
    {
        return 'Ask the person using phycode a question and wait for the answer. Use it only when a decision needs their input. Options are optional choices; the person may type another answer.';
    }

    public function category(): ToolCategory
    {
        return ToolCategory::Read;
    }

    public function isAvailable(RunPolicy $policy): bool
    {
        return $this->enabled;
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'question' => ['type' => 'string', 'description' => 'The question, in one or two sentences'],
                'options' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Up to six choices'],
            ],
            'required' => ['question'],
        ];
    }

    public function summarize(array $args): string
    {
        return 'ask_user: ' . Text::oneLine(is_string($args['question'] ?? null) ? $args['question'] : '?', 100);
    }

    public function execute(array $args, ToolContext $context): ToolResult
    {
        $ui = $context->ui;
        if (!$ui->interactive()) {
            return ToolResult::fail('no user is available to answer');
        }
        $question = trim(Args::string($args, 'question'));
        if ($question === '' || strlen($question) > self::MAX_QUESTION_BYTES) {
            throw new PhycodeException("argument 'question' must have 1 to " . self::MAX_QUESTION_BYTES . ' bytes');
        }

        $answer = $ui->ask($question, self::options($args), true);
        if ($answer === null || trim($answer) === '') {
            return ToolResult::ok('the user gave no answer');
        }

        return ToolResult::ok('The user answered: ' . trim($answer));
    }

    private static function options(array $args): array
    {
        $value = $args['options'] ?? [];
        if (!is_array($value) || !array_is_list($value) || count($value) > self::MAX_OPTIONS) {
            throw new PhycodeException("argument 'options' must be a list of up to " . self::MAX_OPTIONS . ' strings');
        }
        $options = [];
        foreach ($value as $option) {
            if (!is_string($option) || trim($option) === '' || strlen($option) > self::MAX_OPTION_BYTES) {
                throw new PhycodeException("argument 'options' must hold strings of 1 to " . self::MAX_OPTION_BYTES . ' bytes');
            }
            $options[] = trim($option);
        }

        return $options;
    }
}
