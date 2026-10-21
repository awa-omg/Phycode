<?php

declare(strict_types=1);

namespace Phycode\Security;

final class ShellCommand
{
    public const TOOLS = ['bash', 'ssh'];

    private const MAX_DEPTH = 6;
    private const SHELLS = ['sh', 'bash', 'zsh', 'dash', 'ksh', 'ash'];
    private const RESERVED = ['do', 'then', 'else', 'elif', 'if', 'while', 'until', '!', '{', '}'];
    private const WRAPPERS = [
        'sudo' => [['-u', '-g', '-h', '-p', '-C', '-D', '-R', '-T', '-U', '-r', '-t'], 0],
        'doas' => [['-u', '-C'], 0],
        'env' => [['-u', '-C', '-S'], 0],
        'command' => [[], 0],
        'builtin' => [[], 0],
        'nohup' => [[], 0],
        'exec' => [['-a'], 0],
        'nice' => [['-n'], 0],
        'timeout' => [['-s', '-k'], 1],
        'stdbuf' => [['-i', '-o', '-e'], 0],
        'xargs' => [['-I', '-n', '-P', '-L', '-s', '-d', '-E', '-a'], 0],
        'setsid' => [[], 0],
        'ionice' => [['-c', '-n', '-p', '-P', '-u'], 0],
        'flock' => [['-w', '-E'], 1],
        'watch' => [['-n', '-d'], 0],
        'time' => [['-f', '-o'], 0],
        'strace' => [['-e', '-o', '-p', '-s', '-u', '-E', '-a', '-I', '-P', '-S', '-O', '-b', '-X'], 0],
        'setpriv' => [[], 0],
        'taskset' => [[], 1],
        'chrt' => [[], 1],
        'script' => [[], 0],
        'busybox' => [[], 0],
        'unshare' => [[], 0],
        'nsenter' => [['-t', '-S', '-G', '-w', '-r', '-D'], 0],
        'chroot' => [[], 1],
        'fakeroot' => [[], 0],
        'su' => [['-s', '-g', '-G', '-w'], 0],
        'runuser' => [['-u', '-g', '-G', '-s'], 0],
        'pkexec' => [['-u'], 0],
    ];

    private int $i = 0;
    private int $n;
    private string $buf = '';
    private array $out = [];
    private array $nested = [];
    private bool $pending = false;
    private bool $sawCommand = false;
    private array $heredocs = [];

    private function __construct(private readonly string $s, private readonly int $depth)
    {
        $this->n = strlen($s);
    }

    public static function of(string $tool, string $summary): ?array
    {
        if (!in_array($tool, self::TOOLS, true)) {
            return null;
        }
        $at = strpos($summary, ': ');

        return $at === false ? null : [substr($summary, 0, $at + 2), substr($summary, $at + 2)];
    }

    public static function segments(string $command): ?array
    {
        return (new self($command, 0))->run();
    }

    public static function words(string $command): ?array
    {
        $words = [];
        $word = '';
        $in = false;
        $n = strlen($command);
        for ($i = 0; $i < $n; $i++) {
            $c = $command[$i];
            if ($c === ' ' || $c === "\t" || $c === "\n") {
                if ($in) {
                    $words[] = $word;
                    $word = '';
                    $in = false;
                }
                continue;
            }
            $in = true;
            if ($c === "'") {
                $end = strpos($command, "'", $i + 1);
                if ($end === false) {
                    return null;
                }
                $word .= substr($command, $i + 1, $end - $i - 1);
                $i = $end;
            } elseif ($c === '"') {
                for ($i++; $i < $n && $command[$i] !== '"'; $i++) {
                    if ($command[$i] === '\\' && $i + 1 < $n && in_array($command[$i + 1], ['"', '\\', '$', '`'], true)) {
                        $i++;
                    }
                    $word .= $command[$i];
                }
                if ($i >= $n) {
                    return null;
                }
            } elseif ($c === '\\') {
                if ($i + 1 < $n) {
                    $word .= $command[++$i];
                }
            } elseif ($c === '$' && ($command[$i + 1] ?? '') === '(') {
                for ($open = 0; $i < $n; $i++) {
                    $word .= $command[$i];
                    if ($command[$i] === '(') {
                        $open++;
                    } elseif ($command[$i] === ')' && --$open === 0) {
                        break;
                    }
                }
            } else {
                $word .= $c;
            }
        }
        if ($in) {
            $words[] = $word;
        }

        return $words;
    }

    public static function views(string $segment, int $depth = 0): array
    {
        $views = [$segment];
        $words = self::words($segment);
        if ($words === null || $words === [] || $depth > 3) {
            return $views;
        }
        $views[] = implode(' ', $words);
        for ($guard = 0; $words !== [] && $guard < 16; $guard++) {
            $name = basename($words[0]);
            if (in_array($words[0], self::RESERVED, true) || preg_match('/^[A-Za-z_][A-Za-z0-9_]*\+?=/', $words[0]) === 1) {
                array_shift($words);
            } elseif (isset(self::WRAPPERS[$name])) {
                array_shift($words);
                $words = self::skipOptions($words, self::WRAPPERS[$name]);
            } else {
                break;
            }
            $views[] = implode(' ', $words);
        }
        if ($words !== []) {
            if (str_contains($words[0], '/')) {
                $words[0] = basename($words[0]);
                $views[] = implode(' ', $words);
            }
            $inner = self::innerCommand($words);
            if ($inner !== null) {
                foreach (self::segments($inner) ?? [$inner] as $segment) {
                    array_push($views, ...self::views($segment, $depth + 1));
                }
            }
        }

        return array_values(array_unique($views));
    }

    private static function skipOptions(array $words, array $wrapper): array
    {
        while ($words !== [] && $words[0] !== '-' && str_starts_with($words[0], '-')) {
            $option = array_shift($words);
            if (in_array($option, $wrapper[0], true)) {
                array_shift($words);
            }
        }
        for ($k = 0; $k < $wrapper[1] && $words !== []; $k++) {
            array_shift($words);
        }

        return $words;
    }

    private static function innerCommand(array $words): ?string
    {
        $program = $words[0];
        $rest = array_slice($words, 1);
        if ($program === 'eval') {
            return $rest === [] ? null : implode(' ', $rest);
        }
        if (!in_array($program, self::SHELLS, true)) {
            return null;
        }
        foreach ($rest as $at => $word) {
            if (preg_match('/^-[A-Za-z]*c[A-Za-z]*$/', $word) === 1) {
                return $rest[$at + 1] ?? null;
            }
        }

        return null;
    }

    private function run(): ?array
    {
        if ($this->depth > self::MAX_DEPTH) {
            return null;
        }
        while ($this->i < $this->n) {
            $c = $this->s[$this->i];
            $next = $this->s[$this->i + 1] ?? '';
            $ok = true;
            if ($c === "'") {
                $ok = $this->single();
            } elseif ($c === '"') {
                $ok = $this->double();
            } elseif ($c === '\\') {
                $this->escape();
            } elseif ($c === '`') {
                $ok = $this->backtick();
            } elseif ($c === '$') {
                $ok = $this->dollar();
            } elseif (($c === '<' || $c === '>') && $next === '(') {
                $this->i += 2;
                $inner = $this->paren();
                $ok = $inner !== null && $this->nest($inner);
                $this->add($c . '(' . $inner . ')');
            } elseif ($c === '<' && $next === '<' && ($this->s[$this->i + 2] ?? '') === '<') {
                $this->add('<<<');
                $this->i += 3;
            } elseif ($c === '<' && $next === '<') {
                $ok = $this->heredocStart();
            } elseif (($c === '<' || $c === '>') && ($next === '&' || ($c === '>' && $next === '|'))) {
                $this->add($c . $next);
                $this->i += 2;
            } elseif ($c === '&' && $next === '>') {
                $this->add('&>');
                $this->i += 2;
            } elseif ($c === '#' && ($this->buf === '' || $this->buf[-1] === ' ' || $this->buf[-1] === "\t")) {
                $end = strpos($this->s, "\n", $this->i);
                $this->i = $end === false ? $this->n : $end;
            } elseif ($c === "\n") {
                $this->i++;
                $this->readHeredocs();
                if (!$this->pending) {
                    $ok = $this->operator(';');
                }
            } elseif ($c === ';') {
                $ok = $this->operator(';');
                $this->i++;
            } elseif ($c === '&') {
                $double = $next === '&';
                $ok = $this->operator($double ? '&&' : '&');
                $this->i += $double ? 2 : 1;
            } elseif ($c === '|') {
                $op = $next === '|' ? '||' : ($next === '&' ? '|&' : '|');
                $ok = $this->operator($op);
                $this->i += strlen($op);
            } elseif ($c === '(') {
                $this->flush();
                $this->pending = false;
                $this->i++;
            } elseif ($c === ')') {
                $this->flush();
                $this->sawCommand = true;
                $this->i++;
            } else {
                $this->add($c);
                $this->i++;
            }
            if (!$ok) {
                return null;
            }
        }
        $this->flush();

        return $this->pending ? null : $this->out;
    }

    private function add(string $text): void
    {
        $this->buf .= $text;
        if (trim($text) !== '') {
            $this->pending = false;
        }
    }

    private function flush(): void
    {
        $command = trim($this->buf);
        $this->buf = '';
        if ($command !== '') {
            $this->out[] = $command;
            array_push($this->out, ...$this->nested);
            $this->sawCommand = true;
        } else {
            array_push($this->out, ...$this->nested);
        }
        $this->nested = [];
    }

    private function operator(string $op): bool
    {
        $this->flush();
        if ($op === '&&' || $op === '||' || $op === '|' || $op === '|&') {
            if (!$this->sawCommand) {
                return false;
            }
            $this->pending = true;
        } else {
            $this->pending = false;
        }
        $this->sawCommand = false;

        return true;
    }

    private function nest(string $inner): bool
    {
        $found = (new self($inner, $this->depth + 1))->run();
        if ($found === null) {
            return false;
        }
        array_push($this->nested, ...$found);

        return true;
    }

    private function nestSubstitutions(string $text): bool
    {
        $found = (new self($text, $this->depth + 1))->substitutions();
        if ($found === null) {
            return false;
        }
        array_push($this->nested, ...$found);

        return true;
    }

    private function substitutions(): ?array
    {
        if ($this->depth > self::MAX_DEPTH) {
            return null;
        }
        while ($this->i < $this->n) {
            $c = $this->s[$this->i];
            if ($c === '\\') {
                $this->i += 2;
            } elseif ($c === '`') {
                if (!$this->backtick()) {
                    return null;
                }
            } elseif ($c === '$' && in_array($this->s[$this->i + 1] ?? '', ['(', '{'], true)) {
                if (!$this->dollar()) {
                    return null;
                }
            } else {
                $this->i++;
            }
        }

        return $this->nested;
    }

    private function single(): bool
    {
        $end = strpos($this->s, "'", $this->i + 1);
        if ($end === false) {
            return false;
        }
        $this->add(substr($this->s, $this->i, $end - $this->i + 1));
        $this->i = $end + 1;

        return true;
    }

    private function double(): bool
    {
        $this->add('"');
        for ($this->i++; $this->i < $this->n; ) {
            $c = $this->s[$this->i];
            if ($c === '"') {
                $this->add('"');
                $this->i++;

                return true;
            }
            if ($c === '\\' && $this->i + 1 < $this->n) {
                $this->add($c . $this->s[$this->i + 1]);
                $this->i += 2;
            } elseif ($c === '`') {
                if (!$this->backtick()) {
                    return false;
                }
            } elseif ($c === '$' && in_array($this->s[$this->i + 1] ?? '', ['(', '{'], true)) {
                if (!$this->dollar()) {
                    return false;
                }
            } else {
                $this->add($c);
                $this->i++;
            }
        }

        return false;
    }

    private function escape(): void
    {
        $next = $this->s[$this->i + 1] ?? null;
        if ($next === null) {
            $this->add('\\');
            $this->i++;
        } elseif ($next === "\n") {
            $this->i += 2;
        } else {
            $this->add('\\' . $next);
            $this->i += 2;
        }
    }

    private function backtick(): bool
    {
        $inner = '';
        for ($this->i++; $this->i < $this->n; $this->i++) {
            $c = $this->s[$this->i];
            if ($c === '`') {
                $this->i++;
                $this->add('`' . $inner . '`');

                return $this->nest($inner);
            }
            if ($c === '\\' && in_array($this->s[$this->i + 1] ?? '', ['`', '\\', '$'], true)) {
                $c = $this->s[++$this->i];
            }
            $inner .= $c;
        }

        return false;
    }

    private function dollar(): bool
    {
        $next = $this->s[$this->i + 1] ?? '';
        if ($next === '(') {
            $arithmetic = ($this->s[$this->i + 2] ?? '') === '(';
            $this->i += 2;
            $inner = $this->paren();
            if ($inner === null) {
                return false;
            }
            $this->add('$(' . $inner . ')');

            return $arithmetic ? $this->nestSubstitutions($inner) : $this->nest($inner);
        }
        if ($next === '{') {
            $this->i += 2;
            $inner = $this->brace();
            if ($inner === null) {
                return false;
            }
            $this->add('${' . $inner . '}');

            return $this->nestSubstitutions($inner);
        }
        if ($next === "'") {
            $start = $this->i;
            for ($this->i += 2; $this->i < $this->n; $this->i++) {
                if ($this->s[$this->i] === '\\') {
                    $this->i++;
                } elseif ($this->s[$this->i] === "'") {
                    $this->i++;
                    $this->add(substr($this->s, $start, $this->i - $start));

                    return true;
                }
            }

            return false;
        }
        $this->add('$');
        $this->i++;

        return true;
    }

    private function paren(): ?string
    {
        $start = $this->i;
        for ($open = 1; $this->i < $this->n; ) {
            $c = $this->s[$this->i];
            if ($c === "'") {
                $end = strpos($this->s, "'", $this->i + 1);
                if ($end === false) {
                    return null;
                }
                $this->i = $end + 1;
            } elseif ($c === '"') {
                if (!$this->skipDouble()) {
                    return null;
                }
            } elseif ($c === '`') {
                if (!$this->skipBacktick()) {
                    return null;
                }
            } elseif ($c === '\\') {
                $this->i += 2;
            } else {
                if ($c === '(') {
                    $open++;
                } elseif ($c === ')' && --$open === 0) {
                    $inner = substr($this->s, $start, $this->i - $start);
                    $this->i++;

                    return $inner;
                }
                $this->i++;
            }
        }

        return null;
    }

    private function brace(): ?string
    {
        $start = $this->i;
        for ($open = 1; $this->i < $this->n; ) {
            $c = $this->s[$this->i];
            if ($c === "'") {
                $end = strpos($this->s, "'", $this->i + 1);
                if ($end === false) {
                    return null;
                }
                $this->i = $end + 1;
            } elseif ($c === '"') {
                if (!$this->skipDouble()) {
                    return null;
                }
            } elseif ($c === '\\') {
                $this->i += 2;
            } else {
                if ($c === '{') {
                    $open++;
                } elseif ($c === '}' && --$open === 0) {
                    $inner = substr($this->s, $start, $this->i - $start);
                    $this->i++;

                    return $inner;
                }
                $this->i++;
            }
        }

        return null;
    }

    private function skipDouble(): bool
    {
        for ($this->i++; $this->i < $this->n; ) {
            $c = $this->s[$this->i];
            if ($c === '"') {
                $this->i++;

                return true;
            }
            if ($c === '\\') {
                $this->i += 2;
            } elseif ($c === '`') {
                if (!$this->skipBacktick()) {
                    return false;
                }
            } elseif ($c === '$' && ($this->s[$this->i + 1] ?? '') === '(') {
                $this->i += 2;
                if ($this->paren() === null) {
                    return false;
                }
            } else {
                $this->i++;
            }
        }

        return false;
    }

    private function skipBacktick(): bool
    {
        for ($this->i++; $this->i < $this->n; ) {
            if ($this->s[$this->i] === '`') {
                $this->i++;

                return true;
            }
            $this->i += $this->s[$this->i] === '\\' ? 2 : 1;
        }

        return false;
    }

    private function heredocStart(): bool
    {
        $start = $this->i;
        $j = $this->i + 2;
        $strip = ($this->s[$j] ?? '') === '-';
        if ($strip) {
            $j++;
        }
        while (($this->s[$j] ?? '') === ' ' || ($this->s[$j] ?? '') === "\t") {
            $j++;
        }
        $delimiter = '';
        $quoted = false;
        $c = $this->s[$j] ?? '';
        if ($c === '"' || $c === "'") {
            $end = strpos($this->s, $c, $j + 1);
            if ($end === false) {
                return false;
            }
            $delimiter = substr($this->s, $j + 1, $end - $j - 1);
            $quoted = true;
            $j = $end + 1;
        } else {
            for (; $j < $this->n && strpos(" \t\n;&|<>()", $this->s[$j]) === false; $j++) {
                if ($this->s[$j] === '\\') {
                    $quoted = true;
                    continue;
                }
                $delimiter .= $this->s[$j];
            }
        }
        if ($delimiter === '') {
            return false;
        }
        $this->heredocs[] = [$delimiter, $strip, $quoted];
        $this->add(substr($this->s, $start, $j - $start));
        $this->i = $j;

        return true;
    }

    private function readHeredocs(): void
    {
        foreach ($this->heredocs as [$delimiter, $strip, $quoted]) {
            $body = '';
            while ($this->i < $this->n) {
                $end = strpos($this->s, "\n", $this->i);
                $line = substr($this->s, $this->i, ($end === false ? $this->n : $end) - $this->i);
                $this->i = $end === false ? $this->n : $end + 1;
                if (($strip ? ltrim($line, "\t") : $line) === $delimiter) {
                    break;
                }
                $body .= $line . "\n";
            }
            if (!$quoted && $body !== '' && !$this->nestSubstitutions($body)) {
                $this->nested[] = $body;
            }
        }
        $this->heredocs = [];
    }
}
