<?php

declare(strict_types=1);

namespace Phycode\Agent;

use Phycode\Llm\Canonical\FinishReason;
use Phycode\Llm\Canonical\Usage;
use Phycode\Llm\ChatOptions;
use Phycode\Llm\Image;
use Phycode\Llm\PromptCache;
use Phycode\Llm\TextToolProtocol;
use Phycode\Llm\UsageMeter;
use Phycode\Llm\ToolCall;
use Phycode\Support\Json;
use Phycode\Support\PhycodeException;
use Phycode\Support\Redaction;
use Phycode\Support\Text;
use Phycode\Tools\ArgumentRepair;
use Phycode\Tools\Tool;
use Phycode\Tools\ToolCategory;
use Phycode\Tools\ToolContext;
use Phycode\Tools\ToolResult;

final class AgentRunner
{
    private const MAX_PARSE_RETRIES = 2;
    private const MAX_EMPTY_RETRIES = 1;
    private const MAX_DENIALS = 3;
    private const UNKNOWN_FINISH = 'the reply ended with a finish reason this client does not know';
    private const MAX_STOP_CONTINUATIONS = 8;
    private const CHECKED_TOOLS = ['edit', 'write'];

    public function __construct(private readonly Hooks $hooks = new Hooks(), private readonly Checks $checks = new Checks(), private readonly ?UsageMeter $meter = null)
    {
    }

    public function run(Conversation $conversation, RunSetup $setup): Outcome
    {
        $usage = null;
        $turns = 0;
        $calls = 0;
        $continued = 0;
        while (true) {
            $outcome = $this->loop($conversation, $setup, $usage);
            $turns += $outcome->turns;
            $calls += $outcome->toolCalls;
            $again = $this->stopHook($setup->context, $outcome, $continued > 0);
            if ($again === [] || $continued >= self::MAX_STOP_CONTINUATIONS) {
                break;
            }
            $continued++;
            $conversation->addUser("A Stop hook asks you to keep working:\n" . implode("\n", $again));
        }

        return new Outcome($outcome->status, $outcome->text, $turns, $calls, $outcome->reason, $usage);
    }

    public function promptSubmitted(string $prompt, ToolContext $context): HookOutcome
    {
        return $this->hooks->dispatch('UserPromptSubmit', $this->hookEvent('UserPromptSubmit', $context, ['prompt' => $prompt]), $context->workspace->root, 'UserPromptSubmit', $context->ui);
    }

    private function loop(Conversation $conversation, RunSetup $setup, ?Usage &$usage): Outcome
    {
        $context = $setup->context;
        $native = $setup->toolMode === 'native';
        $textMode = $setup->toolMode === 'text';
        $specs = $native ? $setup->registry->specs($context->policy) : [];
        $guard = new LoopGuard($context->limits->loopRepeatLimit);
        $turns = 0;
        $calls = 0;
        $parseRetries = 0;
        $emptyRetries = 0;
        $denials = 0;
        $runTasks = 0;
        $nudged = false;

        $onEvent = $this->events($context, $setup->streamText);

        while (true) {
            if ($context->cancel->isCancelled()) {
                return new Outcome(OutcomeStatus::Cancelled, '', $turns, $calls, $context->cancel->reason());
            }
            if ($turns >= $setup->maxTurns) {
                return $this->finalize($conversation, $setup, $turns, $calls, $usage);
            }
            $turns++;

            try {
                $response = $setup->client->chat(
                    $conversation->messages(),
                    $specs,
                    new ChatOptions($onEvent, $context->cancel, $setup->overrides, PromptCache::key($context->transcript->path, $context->agent->name)),
                );
            } catch (PhycodeException $error) {
                if ($context->cancel->isCancelled()) {
                    return new Outcome(OutcomeStatus::Cancelled, '', $turns, $calls, $context->cancel->reason());
                }

                return new Outcome(OutcomeStatus::Error, '', $turns, $calls, $error->getMessage());
            }
            $usage = $response->usage;
            $this->meter?->record($context->profile, $context->model, $response->usage);

            $context->transcript->event('llm_response', [
                'finish' => $response->finishReason,
                'usage' => $response->usage->toArray(),
                'content' => $response->content,
                'tool_calls' => array_map(static fn (ToolCall $call): string => $call->name, $response->toolCalls),
            ], $context->agent->name, $context->depth());

            if ($native) {
                $toolCalls = $response->toolCalls;
                $problems = [];
            } elseif ($textMode) {
                $parsed = TextToolProtocol::parse($response->content);
                $toolCalls = $parsed['calls'];
                $problems = $parsed['errors'];
            } else {
                $toolCalls = [];
                $problems = [];
            }

            if ($response->finishReason === FinishReason::ERROR) {
                return new Outcome(OutcomeStatus::Error, '', $turns, $calls, self::UNKNOWN_FINISH);
            }
            if ($response->finishReason === FinishReason::LENGTH) {
                if ($toolCalls === []) {
                    $context->ui->notice('warn', 'the reply was cut off by the token limit (max_output_tokens)');
                    $conversation->addAssistant($response->content, [], $response->extra);

                    return new Outcome(OutcomeStatus::Completed, $textMode ? TextToolProtocol::stripCalls($response->content) : trim($response->content), $turns, $calls);
                }
                $context->ui->notice('warn', 'the tool call was cut off by the token limit and was not run');

                return new Outcome(OutcomeStatus::Error, '', $turns, $calls, 'the token limit (max_output_tokens) cut off a tool call');
            }

            if ($toolCalls === [] && $problems === []) {
                if (!$nudged && $context->depth() > 0 && $calls === 0 && $setup->registry->available($context->policy) !== []) {
                    $nudged = true;
                    $conversation->addAssistant($response->content, [], $response->extra);
                    $conversation->addUser('You have tools available. Use them now to inspect the project before you answer. Start with list or grep.');
                    continue;
                }
                $text = $textMode ? TextToolProtocol::stripCalls($response->content) : trim($response->content);
                if ($text === '' && $emptyRetries < self::MAX_EMPTY_RETRIES) {
                    $emptyRetries++;
                    $conversation->addAssistant('', [], $response->extra);
                    $conversation->addUser('Your last reply was empty. Continue the task or give your final answer.');
                    continue;
                }
                $conversation->addAssistant($response->content, [], $response->extra);

                return new Outcome(OutcomeStatus::Completed, $text, $turns, $calls);
            }

            if ($toolCalls === []) {
                $conversation->addAssistant($response->content, [], $response->extra);
                if (++$parseRetries > self::MAX_PARSE_RETRIES) {
                    return new Outcome(OutcomeStatus::Error, '', $turns, $calls, 'malformed tool calls: ' . implode('; ', $problems));
                }
                $conversation->addUser('Tool call error: ' . implode('; ', $problems) . '. Reply again with one valid <tool_call> block.');
                continue;
            }

            $conversation->addAssistant($response->content, $native ? $toolCalls : [], $response->extra);
            $replyTasks = 0;
            $stop = null;
            $seen = [];
            foreach ($toolCalls as $call) {
                $calls++;
                if ($stop !== null || $context->cancel->isCancelled()) {
                    $result = ToolResult::fail('skipped: the run stopped');
                } else {
                    [$result, $repeated] = $this->invoke($call, $setup, $guard, $replyTasks, $runTasks);
                    $refused = !$result->ok && (str_starts_with($result->content, 'permission denied') || str_contains($result->content, 'is not available here'));
                    $denials = $refused ? $denials + 1 : 0;
                    if ($repeated) {
                        $stop = 'the same tool call was repeated without progress';
                    } elseif ($denials >= self::MAX_DENIALS) {
                        $stop = 'the same kind of action was refused repeatedly';
                    }
                    if ($result->ok && is_string($result->meta['unload'] ?? null)) {
                        $conversation->unloadSkill($result->meta['unload'], $result->content, $native);
                    }
                }
                $this->answer($conversation, $native, $call, $result);
                array_push($seen, ...Image::of(['images' => $result->meta['images'] ?? []]));
            }
            if ($seen !== []) {
                $conversation->addUser('Image' . (count($seen) > 1 ? 's' : '') . ' from the tool call' . (count($seen) > 1 ? 's' : '') . ' above: ' . implode(', ', array_map(static fn (Image $image): string => $image->name, $seen)), $seen);
            }
            if ($problems !== []) {
                $conversation->addUser('Note: some tool calls were malformed and were skipped: ' . implode('; ', $problems));
            }
            if ($stop !== null) {
                return new Outcome(OutcomeStatus::Stuck, '', $turns, $calls, $stop);
            }
        }
    }

    private function finalize(Conversation $conversation, RunSetup $setup, int $turns, int $calls, ?Usage &$usage): Outcome
    {
        $conversation->addUser('Turn budget reached. Stop calling tools. Reply now with a short report: what you did, what you found, and what is still unresolved.');
        try {
            $response = $setup->client->chat(
                $conversation->messages(),
                [],
                new ChatOptions($this->events($setup->context, false), $setup->context->cancel, $setup->overrides, PromptCache::key($setup->context->transcript->path, $setup->context->agent->name)),
            );
        } catch (PhycodeException $error) {
            if ($setup->context->cancel->isCancelled()) {
                return new Outcome(OutcomeStatus::Cancelled, '', $turns, $calls, $setup->context->cancel->reason());
            }

            return new Outcome(OutcomeStatus::Budget, '', $turns, $calls, 'turn budget reached; the final report failed: ' . $error->getMessage());
        }

        $usage = $response->usage;
        $this->meter?->record($setup->context->profile, $setup->context->model, $response->usage);
        $conversation->addAssistant($response->content, [], $response->extra);
        if ($response->finishReason === FinishReason::ERROR) {
            return new Outcome(OutcomeStatus::Error, '', $turns, $calls, self::UNKNOWN_FINISH);
        }
        if ($response->finishReason === FinishReason::LENGTH) {
            $setup->context->ui->notice('warn', 'the reply was cut off by the token limit (max_output_tokens)');
        }
        $text = $setup->toolMode === 'text' ? TextToolProtocol::stripCalls($response->content) : trim($response->content);

        return new Outcome(OutcomeStatus::Budget, $text, $turns, $calls, 'turn budget reached');
    }

    private function events(ToolContext $context, bool $streamText): \Closure
    {
        return static function (string $type, string $text) use ($context, $streamText): void {
            if ($type === 'text' && $streamText) {
                $context->ui->text($text);
            } elseif ($type === 'notice') {
                $context->ui->notice('warn', $text);
            } elseif ($type === 'progress') {
                $context->ui->notice('info', $text);
            }
        };
    }

    private function invoke(ToolCall $call, RunSetup $setup, LoopGuard $guard, int &$replyTasks, int &$runTasks): array
    {
        $context = $setup->context;
        $name = $setup->registry->resolve($call->name) ?? $call->name;
        $tool = $setup->registry->get($name);
        $available = $setup->registry->available($context->policy);
        if ($tool === null || !in_array($name, $available, true)) {
            return [ToolResult::fail("tool '{$call->name}' is not available here. Available tools: " . implode(', ', $available)), false];
        }

        $args = ArgumentRepair::decode($call->arguments);
        if ($args === null) {
            return [ToolResult::fail('the tool arguments must be a JSON object'), false];
        }
        [$args, $repairs] = ArgumentRepair::apply($tool->parameters(), $args);
        if ($name !== $call->name) {
            $repairs[] = "tool name: {$call->name} -> {$name}";
        }

        $summary = $tool->summarize($args);
        $agent = $context->agent->name;
        $depth = $context->depth();
        $context->ui->toolStart($agent, $depth, $tool->name(), $summary);
        try {
            [$result, $stuck] = $this->perform($call, $setup, $guard, $tool, $args, $summary, $replyTasks, $runTasks, $repairs);
        } catch (\Throwable $error) {
            $context->ui->toolEnd($agent, $depth, $tool->name(), false, Text::oneLine($error->getMessage()));

            throw $error;
        }

        return $this->finish($context, $tool, $result, $stuck);
    }

    private function perform(ToolCall $call, RunSetup $setup, LoopGuard $guard, Tool $tool, array $args, string $summary, int &$replyTasks, int &$runTasks, array $repairs = []): array
    {
        $context = $setup->context;
        $context->transcript->event('tool_call', ['tool' => $tool->name(), 'arguments' => $args] + ($repairs === [] ? [] : ['repaired' => $repairs]), $context->agent->name, $context->depth());

        if ($tool->name() === 'task') {
            if ($replyTasks >= $context->limits->maxTasksPerTurn) {
                return [ToolResult::fail('too many task calls in one reply; wait for the reports before delegating more'), false];
            }
            if ($runTasks >= $context->limits->maxTasksPerRun) {
                return [ToolResult::fail("this run already used its {$context->limits->maxTasksPerRun} subagent tasks; finish with what you have"), false];
            }
            $replyTasks++;
            $runTasks++;
        }
        $repeats = $guard->record($tool->name(), $call->arguments);
        if ($repeats >= $guard->limit()) {
            $refusal = ToolResult::fail("this exact call was made {$repeats} times in a row without progress; change approach or finish with what you have");

            return [$refusal, $repeats >= $guard->limit() + 2];
        }

        try {
            $tool->validate($args, $context);
        } catch (PhycodeException $error) {
            return [ToolResult::fail($error->getMessage()), false];
        }

        $before = $this->hooks->dispatch('PreToolUse', $this->hookEvent('PreToolUse', $context, [
            'tool_name' => $tool->name(),
            'tool_input' => (object) $args,
            'tool_use_id' => $call->id,
        ]), $context->workspace->root, $tool->name(), $context->ui);
        if ($before->block !== null) {
            return [ToolResult::fail("blocked by a hook: {$before->block}"), false];
        }
        $notes = array_map(static fn (string $text): string => "PreToolUse hook: {$text}", $before->context);
        if ($before->updatedInput !== null) {
            [$args] = ArgumentRepair::apply($tool->parameters(), $before->updatedInput);
            try {
                $tool->validate($args, $context);
            } catch (PhycodeException $error) {
                return [ToolResult::fail('a hook changed the arguments of the call to something that is not valid: ' . $error->getMessage()), false];
            }
            $summary = $tool->summarize($args);
            $context->transcript->event('hook_update', ['tool' => $tool->name(), 'arguments' => $args], $context->agent->name, $context->depth());
        }

        $denial = $context->permissions->check($tool, $context, $summary, $args);
        if ($denial !== null) {
            return [ToolResult::fail("permission denied: {$denial}"), false];
        }

        try {
            $result = $tool->execute($args, $context);
        } catch (PhycodeException $error) {
            $result = ToolResult::fail($error->getMessage());
        } catch (\Throwable $error) {
            $result = ToolResult::fail('internal error: ' . $error->getMessage());
        }

        if ($result->ok && $tool->category() === ToolCategory::Write) {
            $guard->reset();
        }
        if ($result->ok && $this->checks->any() && in_array($tool->name(), self::CHECKED_TOOLS, true)) {
            array_push($notes, ...$this->checked($args, $context));
        }
        $after = $this->hooks->dispatch('PostToolUse', $this->hookEvent('PostToolUse', $context, [
            'tool_name' => $tool->name(),
            'tool_input' => (object) $args,
            'tool_response' => ['ok' => $result->ok, 'content' => Text::headTail($result->content, $context->limits->toolOutputBytes)],
            'tool_use_id' => $call->id,
        ]), $context->workspace->root, $tool->name(), $context->ui);
        array_push($notes, ...array_map(static fn (string $text): string => "PostToolUse hook: {$text}", $after->notes()));
        if ($notes !== []) {
            $result = $result->withContent($result->content . "\n\n" . implode("\n", $notes));
        }

        return [$result, false];
    }

    private function checked(array $args, ToolContext $context): array
    {
        $path = $args['path'] ?? null;
        if (!is_string($path)) {
            return [];
        }
        try {
            $relative = $context->workspace->relative($context->workspace->resolve($path));
        } catch (PhycodeException) {
            return [];
        }

        return $this->checks->after($relative, $context->workspace->root);
    }

    private function hookEvent(string $event, ToolContext $context, array $fields): array
    {
        return ['hook_event_name' => $event, 'agent' => $context->agent->name, 'depth' => $context->depth(), 'cwd' => $context->workspace->root] + $fields;
    }

    private function stopHook(ToolContext $context, Outcome $outcome, bool $active): array
    {
        if ($outcome->status === OutcomeStatus::Cancelled) {
            return [];
        }
        $event = $context->depth() > 0 ? 'SubagentStop' : 'Stop';
        $answer = $this->hooks->dispatch($event, $this->hookEvent($event, $context, [
            'status' => $outcome->status->value,
            'turns' => $outcome->turns,
            'tool_calls' => $outcome->toolCalls,
            'stop_hook_active' => $active,
            'last_assistant_message' => $outcome->text,
        ]), $context->workspace->root, $context->agent->name, $context->ui);

        return $outcome->status === OutcomeStatus::Completed ? $answer->feedback : [];
    }

    private function finish(ToolContext $context, Tool $tool, ToolResult $result, bool $stuck): array
    {
        $clipped = $tool->name() === 'skill'
            ? $result
            : $result->withContent(Text::headTail($result->content, $context->limits->toolOutputBytes));
        $clipped = $clipped->withContent(Redaction::exact($clipped->content, $context->secrets));
        $context->ui->toolEnd($context->agent->name, $context->depth(), $tool->name(), $clipped->ok, Text::oneLine($clipped->content), $clipped->diff);
        $context->transcript->event(
            'tool_result',
            ['tool' => $tool->name(), 'ok' => $clipped->ok, 'content' => $clipped->content],
            $context->agent->name,
            $context->depth(),
        );

        return [$clipped, $stuck];
    }

    private function answer(Conversation $conversation, bool $native, ToolCall $call, ToolResult $result): void
    {
        $content = ($result->ok ? '' : 'ERROR: ') . $result->content;
        if ($native) {
            $conversation->addToolResult($call->id, $content);

            return;
        }
        $conversation->addUser(TextToolProtocol::formatResult($call->name, $content));
    }
}
