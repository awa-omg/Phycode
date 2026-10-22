<?php

declare(strict_types=1);

namespace Phycode\Session;

use Phycode\Support\PhycodeException;

final class SessionFork
{
    public static function userTurns(array $messages): int
    {
        return count(array_filter($messages, static fn (array $message): bool => $message['role'] === 'user'));
    }

    public static function keep(array $messages, int $turns): array
    {
        if ($turns < 1) {
            throw new PhycodeException('give a number of messages from 1; /sessions new starts from nothing');
        }
        $seen = 0;
        foreach ($messages as $index => $message) {
            if ($message['role'] === 'user' && ++$seen > $turns) {
                return array_slice($messages, 0, $index);
            }
        }

        return $messages;
    }
}
