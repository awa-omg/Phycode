<?php

declare(strict_types=1);

namespace Phycode\Tools;

use Phycode\Agent\RunPolicy;
use Phycode\Lsp\LspFiles;
use Phycode\Lsp\LspPositions;
use Phycode\Lsp\LspReport;
use Phycode\Lsp\LspServers;
use Phycode\Security\SecretFiles;
use Phycode\Support\PhycodeException;
use Phycode\Support\Redaction;
use Phycode\Support\Text;

final class LspTool extends BaseTool
{
    private const ACTIONS = ['definition', 'references', 'hover', 'document_symbols', 'workspace_symbols', 'diagnostics'];
    private const POSITIONAL = ['definition', 'references', 'hover'];
    private const MAX_FILE_BYTES = 5242880;
    private const MIN_REPORT_BYTES = 1000;
    private const RESERVED_BYTES = 300;

    public function __construct(private readonly LspServers $servers)
    {
    }

    public function name(): string
    {
        return 'lsp';
    }

    public function description(): string
    {
        return "Ask a language server about code, which is more exact than grep. Actions: definition (where the symbol at path, line and column is defined), references (where it is used), hover (its type and documentation), document_symbols (the outline of path), workspace_symbols (find symbols by name across the project; path only picks the server), diagnostics (the errors and warnings the server reports for path).\nline and column start at 1, and column counts the characters of the line; read the file first to find them. Files are sent to the server from disk, so save your edits before asking.";
    }

    public function category(): ToolCategory
    {
        return ToolCategory::Read;
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'action' => ['type' => 'string', 'enum' => self::ACTIONS, 'description' => 'What to ask the language server'],
                'path' => ['type' => 'string', 'description' => 'File path relative to the project root'],
                'line' => ['type' => 'integer', 'description' => 'Line, starting at 1 (definition, references, hover)'],
                'column' => ['type' => 'integer', 'description' => 'Column in characters, starting at 1 (definition, references, hover)'],
                'query' => ['type' => 'string', 'description' => 'Symbol name or part of it (workspace_symbols)'],
            ],
            'required' => ['action', 'path'],
        ];
    }

    public function isAvailable(RunPolicy $policy): bool
    {
        return !$this->servers->isEmpty();
    }

    public function forcesApproval(array $args): bool
    {
        return is_string($args['path'] ?? null) && SecretFiles::guarded($args['path']);
    }

    public function execute(array $args, ToolContext $context): ToolResult
    {
        try {
            return $this->ask($args, $context);
        } catch (PhycodeException $error) {
            return ToolResult::fail(Redaction::text($error->getMessage(), $context->secrets));
        }
    }

    private function ask(array $args, ToolContext $context): ToolResult
    {
        $action = Args::string($args, 'action');
        if (!in_array($action, self::ACTIONS, true)) {
            return ToolResult::fail("unknown action '{$action}'. Use one of: " . implode(', ', self::ACTIONS));
        }
        $path = Args::string($args, 'path');
        $file = $context->workspace->resolve($path);
        $relative = $context->workspace->relative($file);
        if (SecretFiles::guarded($relative) && !SecretFiles::guarded($path)) {
            return ToolResult::fail("refused: {$path} leads to {$relative}, which usually holds secrets or phycode's own files; ask about {$relative} by its own name, which asks first");
        }
        if (!is_file($file)) {
            return ToolResult::fail("not a file: {$path}. Use list or grep to find files.");
        }
        if ((int) filesize($file) > self::MAX_FILE_BYTES) {
            return ToolResult::fail("file is larger than 5 MB: {$path}. Ask about a smaller file.");
        }
        $text = (string) file_get_contents($file);
        if (Text::isBinary(substr($text, 0, 8192))) {
            return ToolResult::fail("binary file: {$path}");
        }

        $query = trim(Args::string($args, 'query', false));
        if ($action === 'workspace_symbols' && $query === '') {
            return ToolResult::fail('workspace_symbols needs a query: the name, or part of the name, of the symbols to find');
        }
        $position = null;
        $where = $relative;
        if (in_array($action, self::POSITIONAL, true)) {
            if (($args['line'] ?? null) === null || ($args['column'] ?? null) === null) {
                return ToolResult::fail("{$action} needs line and column, both starting at 1; read {$path} to find the symbol");
            }
            $line = Args::int($args, 'line', 1, 100000000, 1);
            $column = Args::int($args, 'column', 1, 100000000, 1);
            $lines = LspFiles::lines($text);
            if ($line > count($lines)) {
                return ToolResult::fail("line {$line} is past the end of {$path} (" . count($lines) . ' lines)');
            }
            $position = ['line' => $line - 1, 'character' => LspPositions::toUtf16Units($lines[$line - 1], $column - 1)];
            $where = "{$relative}:{$line}:{$column}";
        }

        $client = $this->servers->clientFor($file, $context->workspace, $context->cancel);
        $client->refreshDocuments();
        $client->syncDocument($file, $text);
        if ($action !== 'diagnostics') {
            $client->warmUp($file);
        }
        $document = ['textDocument' => ['uri' => LspFiles::uri($file)]];
        $report = new LspReport($context->workspace, max(self::MIN_REPORT_BYTES, $context->limits->toolOutputBytes - self::RESERVED_BYTES));

        [$content, $empty] = match ($action) {
            'definition' => [$report->definitions($client->request('textDocument/definition', $document + ['position' => $position])), "no definition found at {$where}"],
            'references' => [$report->references($client->request('textDocument/references', $document + ['position' => $position, 'context' => ['includeDeclaration' => true]])), "no references found at {$where}"],
            'hover' => [$report->hover($client->request('textDocument/hover', $document + ['position' => $position])), "no hover information at {$where}"],
            'document_symbols' => [$report->documentSymbols($client->request('textDocument/documentSymbol', $document)), "the server found no symbols in {$relative}"],
            'workspace_symbols' => [$report->workspaceSymbols($client->request('workspace/symbol', ['query' => $query])), "no symbols match '{$query}'"],
            'diagnostics' => $this->diagnostics($client->diagnostics($file), $report, $file, $relative),
        };

        return ToolResult::ok($content === '' ? $empty : $content, ['server' => $client->name()]);
    }

    private function diagnostics(array $found, LspReport $report, string $file, string $relative): array
    {
        $silent = "no diagnostics were published for {$relative} in time. The server may still be analysing the project, or it publishes nothing for a clean file; call again to check";

        return [$report->diagnostics($found['items'], $file), $found['via'] === null ? $silent : "no diagnostics in {$relative}"];
    }
}
