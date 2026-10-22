<?php

declare(strict_types=1);

namespace Phycode\Lsp;

use Phycode\Security\Workspace;
use Phycode\Support\Cancellation;
use Phycode\Support\PhycodeException;

final class LspServers
{
    private array $clients = [];

    public function __construct(
        private readonly array $specs,
        private readonly LspTimeouts $timeouts = new LspTimeouts(),
        private readonly string $clientVersion = '0.1.0',
    ) {
    }

    public function __destruct()
    {
        $this->close();
    }

    public function isEmpty(): bool
    {
        return $this->specs === [];
    }

    public function clientFor(string $file, Workspace $workspace, Cancellation $cancel): LspClient
    {
        $spec = $this->specFor($file);
        $root = $this->rootFor($spec, $file, $workspace);
        $key = $spec->name . "\0" . $root;
        $client = $this->clients[$key] ?? null;
        if ($client !== null && !$client->alive()) {
            $client->close();
            $client = null;
        }
        if ($client === null) {
            $client = new LspClient($spec->name, $spec->command, $root, $spec->initializationOptions, $this->timeouts, $this->clientVersion);
            $client->bind($cancel)->start();
            $this->clients[$key] = $client;
        }

        return $client->bind($cancel);
    }

    public function close(): void
    {
        $clients = $this->clients;
        $this->clients = [];
        foreach ($clients as $client) {
            $client->close();
        }
    }

    private function specFor(string $file): LspServerSpec
    {
        foreach ($this->specs as $spec) {
            if ($spec->handles($file)) {
                return $spec;
            }
        }
        $extension = pathinfo($file, PATHINFO_EXTENSION);
        $configured = implode('; ', array_map(
            static fn (LspServerSpec $spec): string => $spec->name . ' (' . ($spec->extensions === [] ? 'any file' : implode(', ', $spec->extensions)) . ')',
            $this->specs,
        ));

        throw new PhycodeException('no language server handles ' . ($extension === '' ? 'files without an extension' : ".{$extension} files") . ". Configured: {$configured}. Add the extension to a server's extensions, or add a server, in the lsp section of the global config");
    }

    private function rootFor(LspServerSpec $spec, string $file, Workspace $workspace): string
    {
        $bound = $workspace->readOnlyFolder($file) ?? $workspace->root;
        if ($spec->rootMarkers === []) {
            return $bound;
        }
        $directory = dirname($file);
        while (true) {
            foreach ($spec->rootMarkers as $marker) {
                if (file_exists("{$directory}/{$marker}")) {
                    return $directory;
                }
            }
            if ($directory === $bound || $directory === '/') {
                return $bound;
            }
            $directory = dirname($directory);
        }
    }
}
