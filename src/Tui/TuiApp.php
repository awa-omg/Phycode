<?php

declare(strict_types=1);

namespace Phycode\Tui;

use Phycode\Cli\ChatSession;
use Phycode\Cli\Kernel;

final class TuiApp
{
    private const HISTORY_LIMIT = 50;

    private string $input = '';
    private int $cursor = 0;
    private array $history = [];
    private ?int $historyIndex = null;
    private int $scroll = 0;
    private bool $busy = false;
    private bool $quit = false;
    private bool $resized = false;

    public function __construct(
        private readonly Terminal $terminal,
        private readonly TuiUi $ui,
        private readonly ChatSession $session,
        private readonly Kernel $kernel,
    ) {
    }

    public function run(): int
    {
        $this->ui->attach(fn () => $this->draw(), fn () => $this->pump());
        $this->installSignals();
        $this->ui->notice('info', $this->session->banner());
        $this->draw();
        while (!$this->quit) {
            if ($this->resized) {
                $this->resized = false;
                $this->terminal->refreshSize();
                $this->draw();
            }
            foreach (Keys::decode($this->terminal->read(0.2)) as $key) {
                $this->handle($key);
                if ($this->quit) {
                    break;
                }
            }
        }

        return 0;
    }

    private function installSignals(): void
    {
        if (!function_exists('pcntl_signal')) {
            return;
        }
        pcntl_async_signals(true);
        pcntl_signal(SIGINT, fn () => $this->interrupt());
        pcntl_signal(SIGWINCH, function (): void {
            $this->resized = true;
        });
    }

    private function interrupt(): void
    {
        if ($this->busy) {
            $this->ui->requestInterrupt();
            $this->session->interrupt();

            return;
        }
        $this->setInput('');
        $this->draw();
    }

    private function pump(): void
    {
        foreach (Keys::decode($this->terminal->read(0.0)) as $key) {
            $this->handle($key);
        }
    }

    private function handle(array $key): void
    {
        switch ($key['name']) {
            case 'char':
                $this->insert($key['text']);
                break;
            case 'enter':
                $this->submit();
                break;
            case 'backspace':
                $this->delete(-1);
                break;
            case 'delete':
                $this->delete(0);
                break;
            case 'left':
                $this->cursor = max(0, $this->cursor - 1);
                break;
            case 'right':
                $this->cursor = min(mb_strlen($this->input), $this->cursor + 1);
                break;
            case 'home':
                $this->cursor = 0;
                break;
            case 'end':
                if ($this->input === '') {
                    $this->scroll = 0;
                } else {
                    $this->cursor = mb_strlen($this->input);
                }
                break;
            case 'up':
                $this->recall(-1);
                break;
            case 'down':
                $this->recall(1);
                break;
            case 'pageup':
                $this->scroll += $this->viewportHeight();
                break;
            case 'pagedown':
                $this->scroll = max(0, $this->scroll - $this->viewportHeight());
                break;
            case 'ctrl-u':
                $this->setInput('');
                break;
            case 'ctrl-c':
                $this->interrupt();

                return;
            case 'ctrl-d':
                if ($this->input === '' && !$this->busy) {
                    $this->quit = true;
                }
                break;
        }
        $this->draw();
    }

    private function submit(): void
    {
        if ($this->busy) {
            $this->ui->notice('warn', 'wait for the turn to finish, or press Ctrl+C to cancel it');

            return;
        }
        $line = trim($this->input);
        $this->setInput('');
        $this->historyIndex = null;
        $this->scroll = 0;
        if ($line === '') {
            return;
        }
        $this->history[] = $line;
        if (count($this->history) > self::HISTORY_LIMIT) {
            array_shift($this->history);
        }
        $this->ui->addUser($line);
        $this->busy = true;
        try {
            if (!$this->session->handle($line)) {
                $this->quit = true;
            }
        } catch (\Throwable $error) {
            $this->ui->notice('error', $error->getMessage());
        } finally {
            $this->busy = false;
            $this->ui->resetInterrupt();
        }
    }

    private function insert(string $text): void
    {
        $this->input = mb_substr($this->input, 0, $this->cursor) . $text . mb_substr($this->input, $this->cursor);
        $this->cursor += mb_strlen($text);
    }

    private function delete(int $direction): void
    {
        if ($direction < 0) {
            if ($this->cursor === 0) {
                return;
            }
            $this->input = mb_substr($this->input, 0, $this->cursor - 1) . mb_substr($this->input, $this->cursor);
            $this->cursor--;

            return;
        }
        $this->input = mb_substr($this->input, 0, $this->cursor) . mb_substr($this->input, $this->cursor + 1);
    }

    private function recall(int $direction): void
    {
        if ($this->history === []) {
            return;
        }
        $count = count($this->history);
        $index = max(0, min($count, ($this->historyIndex ?? $count) + $direction));
        $this->historyIndex = $index === $count ? null : $index;
        $this->setInput($index === $count ? '' : $this->history[$index]);
    }

    private function setInput(string $text): void
    {
        $this->input = $text;
        $this->cursor = mb_strlen($text);
    }

    private function viewportHeight(): int
    {
        [$rows] = $this->terminal->size();

        return max(1, $rows - 4);
    }

    private function draw(): void
    {
        [$rows, $columns] = $this->terminal->size();
        $agent = $this->session->primary()->name;
        $header = "phycode | {$agent} | {$this->session->profile()} | tools: {$this->session->toolMode()}"
            . ' | sandbox: ' . $this->kernel->sandboxes->describe();
        $status = "permissions: {$this->kernel->permissionMode} | changes: "
            . count($this->kernel->changes->since(0)) . ' | transcript: ' . $this->session->transcriptPath();

        $this->terminal->write(Screen::frame(
            $rows,
            $columns,
            $header,
            $this->ui->transcript(),
            $this->scroll,
            $this->ui->prompt() ?? str_repeat('─', $columns),
            "{$agent}> ",
            $this->input,
            $this->cursor,
            $status,
        ));
    }
}
