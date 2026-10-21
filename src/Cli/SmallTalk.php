<?php

declare(strict_types=1);

namespace Phycode\Cli;

final class SmallTalk
{
    private const PHRASES = [
        'hola', 'buenas', 'buenos dias', 'buenas tardes', 'buenas noches', 'buen dia', 'hey', 'hi', 'hello',
        'gracias', 'muchas gracias', 'thanks', 'thank you', 'ok', 'okay', 'vale', 'perfecto', 'genial',
        'adios', 'chao', 'hasta luego', 'bye', 'goodbye', 'saludos',
        'que tal', 'hola que tal', 'como estas', 'como va', 'estas ahi', 'me oyes',
        'que puedes hacer', 'quien eres', 'que eres',
    ];

    public static function isGreeting(string $input): bool
    {
        $split = preg_split('/[,;:.!?¡¿]/u', self::fold($input));
        if ($split === false) {
            return false;
        }
        $parts = array_filter(array_map(trim(...), $split), static fn (string $part): bool => $part !== '');
        if ($parts === []) {
            return false;
        }

        $phrases = array_map(self::fold(...), self::PHRASES);
        foreach ($parts as $part) {
            if (!in_array(trim($part), $phrases, true)) {
                return false;
            }
        }

        return true;
    }

    private static function fold(string $text): string
    {
        return strtr(mb_strtolower(trim($text)), ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n']);
    }
}
