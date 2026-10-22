<?php

declare(strict_types=1);

namespace Phycode\Agent;

use Phycode\Support\PhycodeException;

final readonly class Goal
{
    public const DEFAULT_TURNS = 8;
    public const MAX_TURNS = 50;
    public const USAGE = 'usage: /goal [--check COMMAND] [--max N] CONDITION, such as /goal --check "vendor/bin/phpunit" the tests pass';

    public function __construct(public string $condition, public ?string $check, public int $turns)
    {
    }

    public static function parse(string $argument): self
    {
        $rest = trim($argument);
        $check = null;
        $turns = self::DEFAULT_TURNS;
        while (preg_match('/^--(check|max)(?:\s+|=)(?:"([^"]*)"|\'([^\']*)\'|(\S+))\s*/', $rest, $match) === 1) {
            $value = $match[4] ?? $match[3] ?? '';
            $value = $value !== '' ? $value : ($match[2] ?? '');
            if ($match[1] === 'check') {
                $check = trim($value);
                if ($check === '') {
                    throw new PhycodeException('--check needs a command: ' . self::USAGE);
                }
            } else {
                if (preg_match('/^[1-9][0-9]?$/D', $value) !== 1 || (int) $value > self::MAX_TURNS) {
                    throw new PhycodeException('--max is a number of turns from 1 to ' . self::MAX_TURNS);
                }
                $turns = (int) $value;
            }
            $rest = substr($rest, strlen($match[0]));
        }
        if ($rest === '') {
            throw new PhycodeException(self::USAGE);
        }

        return new self($rest, $check, $turns);
    }

    public function firstPrompt(): string
    {
        return "Goal: {$this->condition}\n\nWork on it until it is met. "
            . ($this->check === null ? 'Say what you did and how you know the goal is met.' : "The goal counts as met when this command exits with 0: {$this->check}. Run it yourself to check.");
    }

    public function nextPrompt(string $reason): string
    {
        return "The goal is not met yet: {$reason}\n\nGoal: {$this->condition}\n\nContinue working on it.";
    }
}
