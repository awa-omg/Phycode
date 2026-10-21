<?php

declare(strict_types=1);

namespace Phycode\Llm;

use Phycode\Support\Json;

final class ToolCallAccumulator
{
    private array $calls = [];

    public function merge(array $deltas): void
    {
        foreach ($deltas as $position => $delta) {
            if (!is_array($delta)) {
                continue;
            }
            $index = is_int($delta['index'] ?? null) ? $delta['index'] : $position;
            $call = $this->calls[$index] ?? ['id' => '', 'name' => '', 'arguments' => ''];

            if (is_string($delta['id'] ?? null) && $delta['id'] !== '') {
                $call['id'] = $delta['id'];
            }
            $function = is_array($delta['function'] ?? null) ? $delta['function'] : [];
            $name = $function['name'] ?? null;
            if (is_string($name) && $name !== '') {
                $call['name'] = $call['name'] === '' || $call['name'] === $name ? $name : $call['name'] . $name;
            }
            $arguments = $function['arguments'] ?? null;
            if (is_string($arguments)) {
                $call['arguments'] .= $arguments;
            } elseif (is_array($arguments)) {
                $call['arguments'] = Json::encode($arguments);
            }

            $this->calls[$index] = $call;
        }
    }

    public function calls(): array
    {
        ksort($this->calls);
        $calls = [];
        foreach ($this->calls as $call) {
            if ($call['name'] === '') {
                continue;
            }
            $calls[] = new ToolCall(
                $call['id'] !== '' ? $call['id'] : 'call_' . bin2hex(random_bytes(4)),
                $call['name'],
                $call['arguments'] !== '' ? $call['arguments'] : '{}',
            );
        }

        return $calls;
    }
}
