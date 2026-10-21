<?php

declare(strict_types=1);

namespace Phycode\Gentle;

use Phycode\Support\PhycodeException;

final class Persona
{
    public const NAMES = ['gentleman', 'neutral'];

    public static function text(string $name): ?string
    {
        if (!in_array($name, self::NAMES, true)) {
            return null;
        }
        $file = self::folder() . "/{$name}.md";
        $raw = is_file($file) ? @file_get_contents($file) : false;
        if (!is_string($raw)) {
            throw new PhycodeException("the persona text {$file} cannot be read");
        }

        return trim((string) preg_replace('/\A<!--.*?-->\s*/s', '', $raw, 1));
    }

    public static function promptBlock(?string $name): ?string
    {
        $text = $name === null ? null : self::text($name);
        if ($text === null) {
            return null;
        }

        return "Chat persona: {$name}. Follow it in your replies to the user. Its Persona Scope section says what it covers: "
            . "it never changes code, identifiers, comments or other artifacts.\n\n{$text}";
    }

    public static function folder(): string
    {
        return dirname(__DIR__, 2) . '/resources/personas';
    }

    public static function configured(GlobalConfig $config): ?string
    {
        return $config->gentleAiValue('persona');
    }

    public static function set(GlobalConfig $config, ?string $name, Approval $approve): string
    {
        if ($name !== null && !in_array($name, self::NAMES, true)) {
            throw new PhycodeException('the personas are ' . implode(', ', self::NAMES) . ' or off');
        }
        $label = $name ?? 'off';
        [$content, $data] = $config->read();
        $section = $data->gentle_ai ?? new \stdClass();
        if (!$section instanceof \stdClass) {
            throw new PhycodeException("gentle_ai in {$config->file()} must be an object");
        }
        $current = is_string($section->persona ?? null) ? $section->persona : null;
        if ($current === $name) {
            return "the persona is already {$label}; nothing changed.";
        }
        if ($name === null) {
            unset($section->persona);
        } else {
            $section->persona = $name;
        }
        $data->gentle_ai = $section;
        $config->validate($data);
        $approved = $approve(
            "/persona {$label}",
            "set gentle_ai.persona to {$label} in {$config->file()} (the old file is kept as {$config->file()}.bak)\nnothing else in the file changes",
            "set the persona to {$label}",
        );
        if (!$approved) {
            return 'not approved: nothing was changed.';
        }
        $config->save($content, $data);

        return "saved gentle_ai.persona = {$label} in {$config->file()}; it applies from the next reply of this conversation.";
    }
}
