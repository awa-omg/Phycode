# phycode

[![License: Apache 2.0](https://img.shields.io/badge/License-Apache%202.0-blue.svg)](LICENSE)
[![PHP 8.4.1+](https://img.shields.io/badge/PHP-8.4.1%2B-777BB4.svg)](https://www.php.net/)
[![Go 1.26+](https://img.shields.io/badge/Go-1.26%2B-00ADD8.svg)](https://go.dev/)

Local coding agent for llama.cpp servers with multi-agent delegation

phycode runs in your terminal and works on one project folder at a time. It reads and edits files, searches the code, and runs commands. It asks for approval before it changes anything, unless you configure it otherwise. phycode targets local models served by llama.cpp and Ollama. It also works with OpenAI, Anthropic, OpenRouter, Groq, and any server that implements the OpenAI chat completions API.

Version 0.1.0 is an early release. It has been tested with Qwen3.5 0.8B and 2B (Q8_0) on llama.cpp b11721. Small models follow tool-calling protocols unevenly, so review every change they propose.

## Table of contents

- [Security](#security)
- [Background](#background)
- [Install](#install)
- [Usage](#usage)
- [Configuration](#configuration)
- [Models and providers](#models-and-providers)
- [Permissions](#permissions)
- [Sandboxing](#sandboxing)
- [Tools](#tools)
- [Agents and subagents](#agents-and-subagents)
- [Skills](#skills)
- [MCP servers](#mcp-servers)
- [Web access](#web-access)
- [Hooks, checks, and custom tools](#hooks-checks-and-custom-tools)
- [Sessions, context, and compaction](#sessions-context-and-compaction)
- [Integrations](#integrations)
- [Architecture](#architecture)
- [Development](#development)
- [Known limitations](#known-limitations)
- [Comparison with other coding agents](#comparison-with-other-coding-agents)
- [Contributing](#contributing)
- [License](#license)

## Security

phycode treats model output and external content as untrusted input. A model can be steered by instructions hidden in web pages, repository files, MCP responses, or skills. phycode applies the following controls:

- Permission modes decide when a write or a command needs your approval. The default mode, `ask`, asks before each change.
- A hard line refuses destructive commands in every mode. Inside a Podman or Docker sandbox, the container is the boundary instead.
- Reading, searching, writing, moving, or deleting a file with a secret-like name requires approval in every mode, including `yolo`.
- Shell commands can run in a bubblewrap, Podman, or Docker sandbox, which isolates them from the host.
- Configured API keys are redacted from tool output, error messages, and saved sessions.

Approval prompts do not isolate shell commands. For projects you do not trust, use a container sandbox. Run phycode on a PHP release that includes the security fixes that PHP published on 21 October 2026.

## Background

phycode is designed for local models. The agent runtime is written in PHP 8.4. It contains the agent loop, the tools, the model clients, the configuration, the sessions, and the JSON-lines protocol that the full-screen interface uses. The interface is a separate Go program built with Bubble Tea, Lip Gloss, and Glamour. It starts the PHP backend with `phycode rpc` and exchanges JSON lines with it.

Three rules shape the code:

1. A session works inside one project folder. Paths outside that folder are refused, unless you add a read-only folder with `--add-dir`.
2. Settings that widen access, such as servers, keys, permissions, and sandboxes, are read from the global configuration only. A cloned project cannot change them.
3. A subagent starts with a fresh conversation and never receives more permissions than its parent.

The optional [gentle-ai](https://github.com/Gentleman-Programming/gentle-ai) integration adds commands for an MIT-licensed tool that installs skills, personas, and MCP presets for coding agents.

## Install

### Requirements

| Component | Requirement |
| --- | --- |
| PHP | 8.4.1 or later, with the `curl`, `dom`, `filter`, `json`, `mbstring`, `openssl`, and `posix` extensions. The `pcntl` extension is optional, but the `serve` command requires it. Without `pcntl`, Ctrl+C ends the process instead of canceling the current turn. |
| Composer | 2.x |
| Model server | llama.cpp `llama-server` (start it with `--jinja` for native tool calls), Ollama, an OpenAI-compatible endpoint, or a hosted provider with an API key |
| Terminal UI | Go 1.26 or later. With the default `GOTOOLCHAIN=auto`, an older Go downloads the toolchain named in `tui/go.mod`. |
| System commands | `bash` and `git`. Optional: `timeout` from GNU coreutils (phycode uses it on Linux, and on macOS as `gtimeout` after `brew install coreutils`), `rg` (ripgrep), `python3` and `node` (for `run_code`), and `gh` (for the GitHub tools). |
| Sandboxes | `bwrap` (Linux only, with `socat` when you use `sandbox.allow_domains`), Podman, or Docker |

### Install from source

Clone the repository and install the PHP dependencies:

```sh
git clone https://github.com/awa-omg/Phycode.git
cd Phycode
composer install
chmod +x bin/phycode
bin/phycode doctor --cwd /path/to/project
```

`doctor` checks the PHP runtime, the model server of the active profile, the sandbox, and the tools. Resolve each error it reports before you start a session.

Add `bin/` to your `PATH` to run `phycode` from any folder. The examples in this document assume that.

### Build the terminal UI

```sh
cd tui
go build -o ../bin/phycode-tui ./cmd/phycode-tui
```

The Go module lives in `tui/`, so run Go commands from that folder. Without `bin/phycode-tui`, `phycode -t` falls back to the built-in PHP interface.

### Build a PHAR archive

```sh
php bin/build-phar build/phycode.phar
```

The script copies `src/`, `resources/`, and the launcher into a temporary folder, installs the production dependencies there, and writes a single archive to the path you give. Run the script with `php`. Check the result with:

```sh
php build/phycode.phar version
```

### FreeBSD port

`packaging/freebsd/devel/phycode` holds an entry for the FreeBSD ports tree. It installs the PHAR archive of a release as `bin/phycode`, and it depends on PHP 8.4 with the extensions phycode uses, `bash`, and `git`. The full-screen interface is not part of the port.

The port downloads `phycode-VERSION.phar` from the GitHub release `vVERSION`, so that release must exist, with the file built by `php bin/build-phar phycode-VERSION.phar`, before the port builds:

```sh
cp -R packaging/freebsd/devel/phycode /usr/ports/devel/phycode
cd /usr/ports/devel/phycode
make makesum
make install clean
```

### Supported systems

phycode runs on Linux, FreeBSD, and macOS. Windows is not supported. The hard line reads the users, the mounts, and the folder layout of the machine it runs on, so it needs no setting for each system. The workflow in `.github/workflows/ci.yml` runs the test suite for every push on Ubuntu, on macOS with and without coreutils, and in a FreeBSD 15.1 virtual machine, and it builds the terminal interface for Linux, macOS, and FreeBSD.

On 21 October 2026, the whole test suite passed in CI on Ubuntu, macOS, and FreeBSD, and the end-to-end test in [End-to-end test](#end-to-end-test) passed on every system below except macOS, which cannot run it. The four Linux distributions ran the end-to-end test and the security and deploy unit tests in new Podman containers. FreeBSD also ran everything in a new QEMU virtual machine with KVM:

| System | Image | PHP | Packages the test installs | Notes |
| --- | --- | --- | --- | --- |
| Debian 13 (trixie) | `debian:trixie-slim` | 8.4.27 | `php8.4-cli`, `php8.4-curl`, `php8.4-mbstring`, `php8.4-xml`, `composer`, `git` | `/bin`, `/lib`, and `/sbin` are links into `/usr`. `login.defs` sets the UIDs of people to 1000 to 60000. |
| Alpine 3.24 | `alpine:latest` | 8.5.12 | `php85` with `curl`, `mbstring`, `posix`, `pcntl`, `phar`, `openssl`, `iconv`, `tokenizer`, `dom`, `xml`, `xmlwriter`, and `ctype`, then `composer`, `bash`, `git`, `coreutils` | The `composer` package runs on PHP 8.5, so install the `php85-*` extensions, not `php84-*`. `/bin` and `/usr/bin` are separate folders, and both are on `PATH`. There is no `login.defs`, so phycode counts UIDs 1000 to 60000 as people. |
| Arch Linux | `archlinux:latest` | 8.5.12 | `php`, `composer`, `git` | The `php` package already loads `curl`, `mbstring`, `posix`, and `pcntl`. `/bin` and `/sbin` are links to `usr/bin`. |
| openSUSE Tumbleweed | `opensuse/tumbleweed` | 8.5.12 | `php8`, `php8-cli` with `curl`, `mbstring`, `posix`, `pcntl`, `phar`, `openssl`, `tokenizer`, `dom`, `xmlwriter`, `ctype`, `iconv`, and `zip`, then `php-composer2`, `git-core` | `login.defs` is in `/usr/etc`, not in `/etc`. phycode reads it there. |
| FreeBSD 15.1 | `FreeBSD-15.1-RELEASE-amd64-BASIC-CLOUDINIT-ufs.qcow2`, and `vmactions/freebsd-vm` in CI | 8.4.26 | `php84`, `php84-composer`, the extensions named in the CI workflow, `bash`, `git` | `openssl` is part of `php84`, and there is no `php84-openssl`. `/home` is a link to `/usr/home`, and `/proc` is not mounted, so phycode finds the mounts with `/sbin/mount` and its processes with `ps`. The base `timeout` is not used (see below). |
| macOS 26 | GitHub `macos-latest` (`macos-26-arm64`) | 8.4.27 | PHP 8.4 from `shivammathur/setup-php`, and `brew install coreutils` in one of the two runs | The suite passed with and without coreutils. `/tmp` is a link to `/private/tmp`. The end-to-end test does not run. |

On FreeBSD and macOS, phycode differs from Linux in these ways:

- The FreeBSD `timeout` runs as a reaper and waits for every process a command leaves behind, so a command that started a daemon blocked until its time limit. phycode does not use it on FreeBSD. macOS has no `timeout` unless `brew install coreutils` installs `gtimeout`, and phycode uses that. Without a GNU `timeout`, phycode enforces the time limits itself, and it starts each command through a small `sh` wrapper that gives the command a process group, so it can stop what the command leaves behind.
- `bwrap` works only on Linux, and the container sandboxes are tested on Linux only.
- macOS has no `C.UTF-8` locale, so phycode sets `LC_CTYPE=UTF-8` for the commands it runs there, and `LANG=C.UTF-8` with `LC_ALL=C.UTF-8` elsewhere.

## Usage

### Quick start

1. Start a model server. This example uses llama.cpp and a 2B model:

   ```sh
   llama-server -m Qwen3.5-2B-Q8_0.gguf --host 127.0.0.1 --port 8082 -c 16384 --jinja --alias qwen35-2b
   ```

   Keep the KV cache in f16. Quantized KV caches degrade tool calling.

2. Create `~/.config/phycode/config.json`:

   ```json
   {
     "default_profile": "qwen-2b",
     "profiles": {
       "qwen-2b": {
         "base_url": "http://127.0.0.1:8082",
         "model": "qwen35-2b",
         "context_limit": 16384
       }
     }
   }
   ```

3. Open the full-screen interface from your project folder:

   ```sh
   cd /path/to/project
   phycode -t
   ```

   To run one task and print the result instead, use `run`:

   ```sh
   phycode run "Explain what src/Agent/AgentRunner.php does"
   ```

### CLI

| Command | Description |
| --- | --- |
| `phycode run "TASK"` | Run one task and exit. `-p` is an alias. |
| `phycode chat` | Start the line-based chat. |
| `phycode -t` | Start the full-screen interface. It runs the same session as `chat`. |
| `phycode rpc` | Serve the JSON-lines protocol on stdin and stdout. The full-screen interface uses it. |
| `phycode acp` | Serve the Agent Client Protocol for editors such as Zed, JetBrains IDEs, and Neovim. |
| `phycode serve` | Run a daemon that serves several clients over a Unix socket, or over a loopback port with a token. |
| `phycode agents` | List the agents with their tools and restrictions. |
| `phycode doctor` | Check the runtime, the model server of the profile, the sandbox, and the tools. |
| `phycode keys` | Store (`set`), list, or remove (`rm`) API keys for a profile. |
| `phycode shell` | Show (`status`), add (`install`), or remove (`uninstall`) the PATH block in your login shell. |
| `phycode ssh` | Manage the SSH hosts the model may use: `list`, `add`, `remove`, `trust`, `test`. |
| `phycode mcp` | Manage MCP servers: `list`, `login NAME`, `logout NAME`, `test NAME`. |
| `phycode deploy` | Manage deployment targets: `list`, `add`, `remove`, `secret`, `plan`, `history`. |
| `phycode version` | Print the version. |

Common options:

- `--cwd DIR`: the project folder. The default is the current folder.
- `--profile NAME` and `--agent NAME`: the profile and the primary agent to use.
- `--sandbox NAME`: `host`, `podman`, `docker`, or `bwrap`. It overrides the configuration and the agent files.
- `--yolo`: do not ask before writes or commands.
- `--add-dir DIR`: add a read-only folder outside the project. `read`, `list`, and `grep` can use it. Shell tools are not offered while it is set.

Options for `run`:

- `--output-format text|json|stream-json`, or `--json` as a shortcut.
- `--model NAME` and `--max-turns N`.
- `--output-schema FILE|JSON`: require the final answer to match a JSON Schema.
- `--image FILE`: attach an image. The option is repeatable, and the profile needs `vision: true`.
- `-c` or `--continue`: continue the most recent session of the project.
- `-r ID` or `--resume ID`: continue a specific session.

### Terminal UI

- `Enter` sends the message. `Alt+Enter` inserts a line break.
- `Enter` during a turn queues the message. Queued messages run in order when the turn ends. `Alt+Up` moves them back into the input.
- `Esc` interrupts the current turn. `Ctrl+C` also stops a running turn. When no turn runs, `Ctrl+C` clears the input, or exits if the input is empty. `Ctrl+D` exits when the input is empty.
- `PgUp` and `PgDn` scroll the transcript.
- When a tool asks for approval, `y` allows the call once, `a` allows the tool for the session, `n` denies the call, and `Esc` denies only that call.
- Type `/` to list the commands, or `@` to list the subagents.
- `/models` opens the model picker. `Enter` selects a model for the session. `Ctrl+S` also saves the model as the default of its profile. `Ctrl+F` toggles a favorite. `Esc` closes the picker.

### Chat commands

These commands work in `chat`, in the full-screen interface, and over RPC.

| Command | Description |
| --- | --- |
| `/models` | List the models, and select the one for this session. |
| `/effort [LEVEL]` | Show or set the reasoning effort for the next turn. Levels: `none`, `minimal`, `low`, `medium`, `high`, `xhigh`, `max`. |
| `/context` | Show the tokens used against the window, the compaction threshold, and the tokens per category. |
| `/context max <TOKENS>` | Set the window used for the compaction threshold. Use `off` to read the window from the model. Add `--save` to store the value in the configuration. |
| `/compact [INSTRUCTIONS]` | Summarize older turns with the model. The newest turns that fit in the last quarter of the window are kept. |
| `/usage` | Show the tokens spent in this session, by model, and their cost when pricing is configured. |
| `/status` | Show the version, profile, model, sandbox, permissions, and transcript path. |
| `/sessions` | List saved sessions. Use `/sessions switch <ID>` to change session, or `/sessions new` to start one. |
| `/clear` | Start a new conversation. |
| `/handoff` | Write a summary of the session for a new session. The summary is saved in `.phycode/handoffs`. |
| `/diff` | Show the changes made by the last turn that changed files. |
| `/undo` and `/redo` | Restore, or reapply, the files changed by the last turn. Each action asks for confirmation. |
| `/rewind <TURN>` | Restore the files to their state before a turn, undoing that turn and every later one. |
| `/agent <NAME>` and `/agents` | Switch the primary agent, or list the agents. |
| `/plan [TASK]` | Switch to the `plan` agent. You approve the plan before it is built. |
| `/todo [clear]` | Show or clear the task list of the session. |
| `/image <PATH> [TEXT]` | Send an image with a question. The profile needs `vision: true`. Images up to 3.5 MB are accepted. |
| `/skill [NAME]` and `/skills` | List or show skills. `/skills` lists the gentle-ai skills in read-only mode. |
| `/mcp` | List the MCP servers and their tools. |
| `/init` | Explore the project and write `AGENTS.md`, after you approve the change. |
| `/review [BASE-REF]` | Review your changes with four reviewers, a refuter, and the project's test command. Nothing is edited. |
| `/review mode on`, `/review mode off`, `/review mode status` | Enable, disable, or show the automatic review setting. |
| `/deepresearch <QUESTION>` | Research a question on the web in up to five rounds, and answer with numbered sources. |
| `/btw <QUESTION>` | Ask a side question. It runs in a separate process without tools, and it does not change the conversation. |
| `/gentle [SUBCOMMAND]` | Show the gentle-ai status, or run its install, sync, upgrade, uninstall, refresh, or TUI command. Each one asks first. |
| `/mcp preset [NAME]` | Add a gentle-ai MCP preset (`engram`, `context7`, or `codegraph`) to the global configuration. Asks first. |
| `/persona [NAME]` | Set the persona of chat replies: `gentleman`, `neutral`, or `off`. The persona does not change the code. |
| `/shell [ACTION]` | Show, add, or remove the PATH block in your login shell. Asks first when it changes a file. |
| `/odd <FEATURE>` | Create `odd/tasks/FEATURE.md` from the ODD template. Asks first. |
| `/reload` | Read the configuration again without restarting. |
| `/help` and `/exit` | Show the commands, or leave. `/quit` also leaves. |

Use `@NAME TASK` to run a subagent directly. Its report joins the conversation.

### Scripting and output formats

- `run` prints the final answer to stdout. The change summary, the transcript path, and the session ID go to stderr.
- `--output-format json` prints one JSON object with `status`, `text`, `turns`, `tool_calls`, `reason`, `changed`, `transcript`, `session_id`, `last_usage`, and `usage`.
- `--output-format stream-json` prints one event per line.
- With `--output-schema`, the final answer must be JSON that matches the schema. phycode asks again up to two times. If the answer still does not match, phycode exits with code 4.
- Exit codes: `0` completed; `2` stopped, canceled, or failed; `3` turn budget exhausted; `4` the answer does not match the output schema.

## Configuration

phycode reads two configuration files and a small set of environment variables.

### Files and environment

| Source | Scope | Notes |
| --- | --- | --- |
| `~/.config/phycode/config.json` | Global | Or `$XDG_CONFIG_HOME/phycode/config.json`. It can set every option. |
| `<project>/.phycode/config.json` | Project | It can set only `limits`, `default_agent`, and `subagents`. It cannot choose servers, keys, permissions, or sandboxes. |

Environment variables:

- `PHYCODE_BASE_URL` and `PHYCODE_MODEL` replace the matching fields of the default profile.
- `PHYCODE_PROFILE` selects the default profile.
- `PHYCODE_API_KEY` supplies the key of the `env` profile.

When the configuration defines no profiles, `PHYCODE_BASE_URL` and `PHYCODE_MODEL` create a profile named `env`.

### Top-level sections

| Section | Purpose |
| --- | --- |
| `profiles`, `default_profile` | Model servers and their options |
| `permissions` | Permission mode, in `mode` |
| `rules` | `allow`, `ask`, and `deny` rules per tool |
| `sandbox` | Where `bash` and `run_code` run, with the image and network settings |
| `limits` | Turn budgets, timeouts, and size limits |
| `hooks`, `checks` | Commands that run on agent events and after edits |
| `custom_tools` | Custom tools defined by a command |
| `tools` | `disabled`: the tools that are not offered |
| `mcp_servers`, `mcp` | MCP servers and tool search |
| `web` | Web search and page fetching |
| `skills` | `enabled`, `index`, and `autonomy` for skills |
| `context`, `effort` | Window size, automatic compaction, and the default reasoning effort |
| `ui` | Theme, editor, diff view, and subagent display |
| `pricing` | Prices per model, used by `/usage` |
| `oracle` | A stronger model that the agent can consult |
| `ssh`, `deploy` | SSH hosts and deployment targets |
| `gentle_ai` | Path to the gentle-ai binary, and the persona |

### Profile keys

| Key | Description |
| --- | --- |
| `base_url` | Required. Use `http://` only for `localhost`, `127.0.0.1`, or `::1`. A profile with a key must use `https://`. A Unix socket is written as `unix:///absolute/path.sock`. |
| `model` | Required. The model name on the server. |
| `protocol` | `openai` (default), `openai-responses`, `anthropic`, or `ollama`. See [Protocols](#protocols). |
| `tool_mode` | `auto` (default), `native`, `text`, or `none`. See [Tool modes](#tool-modes). |
| `thinking` | Initial reasoning effort: `none` (default), `minimal`, `low`, `medium`, `high`, `xhigh`, `max`, `off`, or `on`. |
| `context_limit` | Context window in tokens. Ollama receives it as `num_ctx`. |
| `max_output_tokens` | Maximum output tokens, from 16 to 100000. The default is 2048. |
| `token_param` | `max_tokens` or `max_completion_tokens`. For `api.openai.com`, the default is `max_completion_tokens`. |
| `api_key_env`, `api_key_file`, `api_key_store`, `api_key_files` | Where the key comes from. Keys are never stored inline. |
| `fallback` | An ordered list of fallback profiles. |
| `vision` | Set to `true` when the model accepts images. Required for `--image` and `/image`. |
| `keep_alive` | Ollama only. The default is `5m`. |
| `extra_body` | Extra fields added to each request. They are merged last, so they can override other fields. |
| `effort_format`, `effort_levels` | How the reasoning effort is sent to the provider. `effort_format` accepts `auto`, `openai`, `openrouter`, `deepseek`, `chat_template`, or `none`. |
| `prompt_cache`, `prompt_cache_ttl`, `prompt_cache_retention` | Prompt caching. TTL is `5m` or `1h`. Retention is `in_memory` or `24h`. |
| `compact_mode` | `auto` (default), `shared`, or `standalone`. |
| `llama` | Settings for a local `llama-server`: `bin`, `model`, `args`, and `env`. |

### API keys

- Use `api_key_env` (the name of an environment variable), `api_key_file` (a file path), or `api_key_store: true` (the phycode vault).
- `phycode keys set <PROFILE>` stores a key in the vault, in `keys.json` inside the configuration folder. It reads the key from hidden input or from stdin.
- A profile that has a key must use `https://`, unless the URL points to `localhost`, `127.0.0.1`, or `::1`.

## Models and providers

### Protocols

| `protocol` | Use it for | Notes |
| --- | --- | --- |
| `openai` (default) | llama-server, LM Studio, vLLM, OpenRouter, Groq, and OpenAI through `/v1/chat/completions` | If the server rejects tools combined with `reasoning_effort`, phycode resends the request to `/v1/responses` and stores that route in the capability cache of the profile. |
| `openai-responses` | The OpenAI Responses API | |
| `anthropic` | The Anthropic Messages API | Supports prompt caching. |
| `ollama` | The native Ollama API (`/api/chat`) | Supports `keep_alive`. Tested only with recorded fixtures, because no chat model was installed for a live test. |

The provider presets in `src/Config/ProviderPresets.php` supply the base URL and the key variable of seven providers: `openai`, `anthropic`, `openrouter`, `groq`, `lmstudio`, `vllm`, and `ollama-cloud`.

A profile can list fallback profiles in `fallback`. If the primary profile fails, phycode tries the next profile in the list.

### Tool modes

- `auto` (default) probes the server once and caches the result. Transient errors are not cached, so a server that recovers works on the next run.
- `native` sends the tools in the API request and reads structured tool calls.
- `text` asks the model to write `<tool_call>` blocks, and returns results in `<tool_response>` blocks.
- `none` disables tools.

### Reasoning effort

Use `/effort` to change the reasoning effort: `none`, `minimal`, `low`, `medium`, `high`, `xhigh`, or `max`. Each provider accepts a subset of these levels, and phycode maps the chosen level to the provider's format (see `src/Llm/EffortMapping.php`). `off` is equivalent to `none`, and `on` is equivalent to `medium`.

### llama.cpp

`phycode doctor` inspects a local llama.cpp server: its properties, its slots, and its models. When the model is loaded, `doctor` also probes tool calling. Unix sockets, TLS, and API keys are supported.

## Permissions

The permission mode comes from `permissions.mode` in the global configuration, or from `--yolo`.

| Mode | Behavior |
| --- | --- |
| `ask` (default) | Asks before file writes, commands, and git operations that write or contact a remote. |
| `accept_edits` | Writes files without asking. Commands still ask. |
| `plan` | Allows read-only tools only. |
| `dont_ask` | Does not ask. A call that would need approval is refused. |
| `yolo` | Does not ask, except for the actions listed below. |

- Some actions always ask, including in `yolo`. These are reads, searches, writes, edits, moves, and deletes of files with secret-like names, such as `.env`, `.envrc`, `terraform.tfvars`, `.kube/config`, and the vault file `keys.json`. Some `ssh`, `ssh_copy`, `deploy`, and `web_fetch` calls also always ask.
- Without a terminal, for example when `run` executes in a script, writes and commands are disabled. Pass `--yolo` to allow them.
- Tools never write to `.git`, `.phycode`, or `.claude`, at any depth. Moving or deleting a folder that contains one of them is refused too.
- Symbolic links are resolved before the check. A link that points outside the project is refused.
- The `rules` section takes `allow`, `ask`, and `deny` lists. Each entry has the form `tool:pattern`, or `*` for every call. For example, `"bash:git status *"` allows `git status`. A chained shell command is allowed only when every segment is allowed. An allow rule never covers `git -c`.
- phycode evaluates a call in this order: `deny` rules, the hard line, agent restrictions, the permission mode, the `ask` and `allow` rules, and finally your answer.

The hard line refuses a fixed set of destructive commands in every mode and with any approval. It covers recursive deletes of system paths, and shell expansions that hide the same operation, such as `rm -rf /`, `find / -delete`, and `wipefs` on a disk. It reads the command of every tool in the `exec` category, whatever the tool's name: `bash`, `ssh`, custom tools, and any exec tool added later. It also reads every step of a deploy target, and refuses the whole deploy before its first step runs. Inside a Podman or Docker sandbox, `bash` is exempt, because the container is the boundary. A tool that runs on the host, such as a custom tool or `ssh`, is not exempt. The hard line does not inspect the code that `run_code` runs.

The target of `rm -r`, `chmod -R`, `chown -R`, `chgrp -R`, or `find -delete` is judged by its shape, not by a list of names. phycode expands `~`, `~USER`, `$HOME`, `..`, symbolic links, and globs, and it resolves a relative path from the project folder. It refuses the target when it is one of these:

- The root, or any folder directly under it, such as `/usr`, `/mnt`, `/media`, or `/srv`.
- A home folder: any home in `/etc/passwd`, your own `HOME`, and any folder directly under a home root, which covers a neighbour whose account is not in `/etc/passwd`. A home root is the parent of each home whose UID is between `UID_MIN` and `UID_MAX` of `login.defs`, and the base folder of `/etc/default/useradd` (`/home` by default). A folder that holds a home is refused too.
- A mount point in `/proc/self/mounts`, or in the output of `/sbin/mount` where `/proc` is not mounted, or a folder that holds one.
- A folder on `PATH`, the target of a top-level link such as `/bin -> usr/bin`, or a folder directly under a top-level folder that holds a `PATH` folder, such as `/usr/lib`. A `PATH` folder inside a home does not count, so `~/bin` does not guard the rest of the home.
- On macOS, the `Library` folder of your home.

phycode reads the command around the target too. It expands globs and judges every match. It follows a `cd`, a variable set earlier in the command or in an `env` prefix, and a symbolic link made in the same command. It reads `find -exec rm`, `rsync --delete`, `shred`, and `git clean -f` like `rm -r`, reads the bodies of `trap` and `alias`, and sees through `parallel` and `ssh-agent`. A command nested more than four shells deep is refused, because the hard line cannot read it. A project below a top-level folder, such as `/srv/myproject` or `/opt/app`, is not on the line, but `/srv` and `/opt` are.

phycode redacts configured API keys everywhere, including tool output and saved sessions. Error messages, notices, the model list, and `doctor` also hide URLs with embedded credentials, `Authorization` headers, and keys with the `sk-` prefix. Terminal control sequences are removed before any text is displayed.

## Sandboxing

`sandbox.default` selects where `bash` and `run_code` run: `host` (default), `bwrap` (Linux only), `podman`, or `docker`. The `--sandbox` option overrides this setting for one run.

- An agent file can request a different sandbox. A request for `host` falls back to the configured sandbox.
- Podman and Docker require `sandbox.image`, for example `docker.io/library/debian:trixie-slim`.
- `sandbox.network` is `none` by default. Set it to `bridge` to allow network access.
- `sandbox.allow_domains` lists the hosts that a sandbox may reach, for example `github.com` or `*.npmjs.org`. It works only with `bwrap` and network `none`, and it requires `socat`.

### bubblewrap

Bubblewrap works only on Linux. On FreeBSD and macOS, phycode refuses `bwrap` and names the alternatives. With bubblewrap, the root filesystem is mounted read-only and the project folder is writable. Common credential paths are hidden, with an empty tmpfs or an empty file: `~/.ssh`, `~/.aws`, `~/.gnupg`, `~/.kube`, `~/.config/gh`, `~/.config/phycode`, and others.

### Podman and Docker

The container sandboxes are tested on Linux only. The project is mounted at `/workspace`. The folders `.git`, `.phycode`, and `.claude` are mounted read-only. The container drops all Linux capabilities, sets `no-new-privileges`, limits memory to 2 GB and processes to 512, and mounts `/tmp` as a 512 MB tmpfs. The image is never pulled implicitly (`--pull=never`). Network access is `none` unless `sandbox.network` is `bridge`.

### Git

Git tools do not use the sandbox. They run on the host, so credentials and SSH agent sockets never enter a container. Git commands pass an allow list. Commands such as `checkout`, which can discard uncommitted changes, and `rebase` are refused. `push` never forces an update and never deletes a remote branch.

A sandbox protects the machine outside the project. It does not protect the project itself. A command can change any file in the project, and anything you run later on the host, such as a build or a test suite, executes that code.

## Tools

phycode offers tools according to the agent, the `tools.disabled` setting, and the features that are configured. For example, `web_search` and `web_fetch` appear only when the `web` section exists, and `ssh` and `deploy` appear only when hosts or targets are defined.

| Tool | Category | Purpose | Approval in `ask` mode |
| --- | --- | --- | --- |
| `read` | read | Read a file, or a range of lines. By default it returns up to 200 lines (`limits.read_max_lines`). | No, except for secret-like files |
| `list` | read | List project files. Inside a Git repository, it uses `git ls-files`. | No |
| `grep` | read | Search file contents. It uses `rg` when `rg` is installed. | No, except for secret-like paths |
| `repo_map` | read | Map classes and functions with their line numbers. Supported languages: PHP, JavaScript and TypeScript, Python, Go, Rust, Java, Kotlin, Scala, C#, Ruby, C, and C++. | No |
| `write` | write | Create or replace a file. A PHP or Python file that fails the syntax check is rolled back. | Yes |
| `edit` | write | Replace text in a file, with the same syntax check. | Yes |
| `move`, `delete` | write | Move or delete a file. Deleted files go to `.phycode/trash`. | Yes |
| `bash` | exec | Run a shell command in the selected sandbox. With `background: true`, the command runs in the background. | Yes |
| `job` | read | Read the output of a background command, or stop it. | No |
| `run_code` | exec | Run a short Python or JavaScript program from the project root. The approval prompt shows the whole program. | Yes |
| `git_read` | read | `status`, `diff`, `log`, `show`, `blame`, and listings of branches, tags, and remotes. | No |
| `git_write` | write | `add`, `commit -m`, create and delete branches, `switch`, tags, `stash`, `merge`, `reset` in `soft` or `mixed` mode, and `restore --staged`. | Yes |
| `git_remote` | exec | `fetch`, `pull --ff-only`, and `push`. Never forces a push or deletes a remote branch. | Yes |
| `gh_read`, `gh_write` | read, exec | Read from GitHub, and write to GitHub, through the `gh` command. | No, and yes |
| `task` | delegate | Start a subagent. Offered only when subagents are enabled. | No |
| `todo` | read | Show or update the task list of the session. | No |
| `web_search` | read | Search the web with the active backend. | No |
| `web_fetch` | read | Fetch a page and return its text. | Yes, when the URL has a query string, user information, or a path longer than 300 characters |
| `skill`, `skill_manage` | read, write | Load a skill, or create and change user skills. | `skill_manage` asks unless `skills.autonomy` is `auto` |
| `ask_user` | read | Ask you a question. Available in interactive sessions only. | Not applicable |
| `oracle` | exec | Ask a stronger model configured in `oracle`. | Yes, unless `oracle.approval` is `allow` |
| `ssh`, `ssh_copy` | exec | Run commands, and copy files, on the hosts in `ssh.hosts`. | Yes |
| `deploy`, `deploy_info` | exec, read | Deploy to the targets in `deploy.targets`. `deploy_info` returns information about them. | `deploy` asks |
| Custom tools | exec | Commands defined in `custom_tools`. | Yes |

The approval column describes the default `ask` mode. In `accept_edits`, write tools run without asking. In `yolo`, only the actions listed under [Permissions](#permissions) ask. In `plan`, only read tools are allowed. A read-only agent (`permission: readonly`) never receives write or exec tools.

## Agents and subagents

An agent is a Markdown file with YAML frontmatter. The body of the file is the agent's system prompt.

| Agent | Mode | Tools | Notes |
| --- | --- | --- | --- |
| `build` | primary | All | The default agent. It answers without tools when the request does not need project work. |
| `plan` | primary | `read`, `list`, `grep`, `task` | Read-only. It writes a plan whose steps another agent can carry out without seeing the investigation. |
| `explore` | subagent | `read`, `list`, `grep` | Read-only. At most 8 turns. Its report should not exceed 200 words. |
| `general` | subagent | `read`, `list`, `grep`, `write`, `edit`, `move`, `delete`, `bash`, `run_code` | At most 12 turns. It cannot delegate. |

The reviewer agents used by `/review` are `reviewer-readability`, `reviewer-reliability`, `reviewer-resilience`, `reviewer-risk`, and `reviewer-refuter`.

phycode looks for custom agents in this order of priority:

1. `.phycode/agents` and `.claude/agents` in the project, from the working folder up to the root.
2. `~/.config/phycode/agents`.
3. `~/.claude/agents`.
4. The built-in agents.

The frontmatter supports these fields:

- `name` and `description` are required.
- `mode`: `primary`, `subagent`, or `all`. The default is `subagent`.
- `tools` and `disallowedTools`: lists of tool names.
- `permission: readonly` or `permissionMode: plan`: restricts the agent to read-only tools.
- `model` or `profile`: honored only in the global configuration. In a project file, phycode ignores them and prints a warning.
- `max_turns` (also `maxTurns` or `steps`): from 1 to 100.
- `temperature`: from 0 to 2.
- `sandbox`: a sandbox request, subject to the rule described in [Sandboxing](#sandboxing).
- `disable: true`: hides the agent.

Delegation follows these rules:

- A subagent starts with a new conversation that contains its system prompt and the task. Only its final report returns to the parent.
- Delegation has one level. A subagent cannot start another subagent.
- A subagent never has more tools than its parent, and it never has fewer restrictions. The permission mode carries over, and the sandbox cannot be weakened.
- Each subagent has a turn budget (`max_turns`) and a time limit (`limits.subagent_timeout`, 300 seconds by default). When the budget runs out, phycode asks for a final report without tools.
- One reply can start at most 3 tasks (`limits.max_tasks_per_turn`). One run can start at most 10 (`limits.max_tasks_per_run`).
- The tool calls in a reply run in order, one after another.

## Skills

- A skill is a folder that contains a `SKILL.md` file. The frontmatter of that file includes a `name` and a `description`.
- phycode searches `.agents/skills` and `.claude/skills` in the project, `~/.agents/skills` and `~/.claude/skills`, and `~/.config/phycode/skills`. The agent saves its own skills in the last folder.
- With `skills.index` set to `on` (the default), the index of available skills is part of the prompt. The full text of a skill loads only when it is needed, through the `skill` tool.
- `skill_manage` creates skills, patches them, and writes their files. Before it saves content, it scans the content for unsafe patterns. It asks for approval unless `skills.autonomy` is `auto`.
- `/skill` lists or shows a skill. `/skills` lists the gentle-ai skills in read-only mode.

## MCP servers

- Define servers under `mcp_servers`. A server has either a `command`, which is a list of the program and its arguments for stdio, or a `url` for HTTP, with optional `headers`, `token_env`, or `oauth`.
- `enabled: false` disables a server without deleting it. `approval` is `ask` (the default) or `allow`. With `allow`, phycode trusts the tools of that server.
- Headers whose names contain `api-key`, `apikey`, `token`, `secret`, `password`, or `authorization` are refused in `config.json`. Use `token_env` or `oauth` for credentials.
- Tools are named `mcp__SERVER__TOOL`.
- `mcp.tool_search` controls deferred tool search. `auto` (the default) enables it above 20 tools or 30,000 bytes of tool schemas. `on` and `off` force the behavior.
- MCP resources and prompts are exposed when the server offers them.
- Use `/mcp` in chat to list the servers and their tools.

## Web access

- `web_search` uses the active backend in `web.search`: `searxng`, `brave`, `tavily`, `exa`, `perplexity`, `ollama`, or `firecrawl`. Define each backend as a named provider in `web.search.providers`, and select one with `web.search.active`. Keys go to the vault (`api_key_store: true`) or to a file (`api_key_file`).
- `web_fetch` retrieves a page and returns its text. The defaults are a 1 MiB size limit (`web.fetch.max_bytes`), a 20-second timeout (`web.fetch.timeout_seconds`), and a 15-minute cache (`web.cache_minutes`).
- `web.allow_hosts` lists the hosts that are allowed for web access, by name or with a `*.domain` pattern.
- Before a connection opens, the SSRF guard validates the destination and pins the addresses it checked. Web content is labeled as untrusted before it enters the context.

## Hooks, checks, and custom tools

- `hooks` defines commands for five events: `PreToolUse`, `PostToolUse`, `UserPromptSubmit`, `Stop`, and `SubagentStop`. Each entry has a `command`, an optional `match` (patterns separated by `|` or `,`), and an optional `timeout` (from 1 to 600 seconds, 10 by default). phycode sends the event to the command as JSON on stdin.
- `PreToolUse` can block a call or change its arguments. `UserPromptSubmit` can block a prompt or add context to it. `PostToolUse` adds notes to a tool result.
- `Stop` and `SubagentStop` can ask the agent to continue. This happens at most 8 times per run.
- `checks` runs commands after each successful `edit` or `write` whose path matches `match`. The placeholder `{file}` is replaced with the path. Checks of `kind: "format"` run before the others and may rewrite the file. The default timeout is 30 seconds, and the maximum is 300.
- `custom_tools` defines tools that run a command. Each one has a `name`, a `description`, a `command`, and `stdin`. They ask for approval like any other command, and the hard line reads their command.
- `tools.disabled` removes tools by name.

## Sessions, context, and compaction

- Each run writes a transcript in JSON Lines format to `.phycode/sessions/`. Resume a session with `-c` (the most recent one), with `-r <ID>`, or from chat with `/sessions`.
- phycode writes a `.gitignore` file that contains `*` inside `.phycode/`, so Git ignores everything the tool writes there.
- `AGENTS.md` or `CLAUDE.md` in the project, and the same files in `~/.config/phycode/`, are added to the system prompt. They are truncated to `limits.agents_md_bytes` (6144 bytes by default). `/init` writes `AGENTS.md` after you approve the change.
- Automatic compaction starts when the context reaches the window minus a buffer. The window is `context.max` when you set it, and the model's window otherwise. The buffer is `context.buffer` when you set it, and the larger of 200 tokens and 2 percent of the window otherwise. phycode summarizes the older turns with the model, and keeps the newest turns that fit in the last quarter of the window. Set `context.auto_compact` to `false` to turn automatic compaction off. Run `/compact` to compact manually.
- `/context max <TOKENS>` changes the window. Add `--save` to store the value in the configuration.
- `/handoff` writes a summary for a new session. The summary is saved in `.phycode/handoffs`.
- Before a turn changes a file, phycode stores the previous contents in `.phycode/checkpoints`. `/diff`, `/undo`, `/redo`, and `/rewind` use these copies.

## Integrations

- Agent Client Protocol (`phycode acp`). The agent speaks protocol version 1 over stdin and stdout. It offers four permission modes: Ask, Accept edits, Plan, and Do not ask.
- RPC (`phycode rpc`). JSON lines over stdin and stdout. The full-screen interface uses it as its backend.
- Serve daemon (`phycode serve`). One process serves several clients. It listens on a Unix socket, by default `$XDG_RUNTIME_DIR/phycode/serve.sock` or `/tmp/phycode-UID/serve.sock`, in a folder that you own with mode 0700. With `--listen`, it can listen on a loopback address instead, which requires a token (`--token-file`). Each client sends a `hello` message with the token and the project folder first. Anyone who connects can use your tools, so protect the socket and the token.
- SSH and deployments. Hosts are defined in `ssh.hosts`, and deployment targets in `deploy.targets`. Targets can use Cloudflare, Vercel, Docker, Modal, SSH, or a custom command. Manage them with `phycode ssh` and `phycode deploy`.
- gentle-ai. The optional integration shows the gentle-ai status with `/gentle`, and runs its commands after you approve each one. `gentle_ai.bin` sets the path to the binary, and `/persona` sets the persona of the chat replies.

## Architecture

| Directory | Responsibility |
| --- | --- |
| `src/Agent` | The agent loop (`AgentRunner`), tool policies, subagents, hooks, checks, and agent definitions |
| `src/Cli` | The entry point, the CLI commands, the `Kernel` that wires the runtime together, chat, and chat commands |
| `src/Config` | Loading and validation of `config.json`, profiles, sandbox, limits, and provider and search presets |
| `src/Llm` | Clients for `openai`, `openai-responses`, `anthropic`, and `ollama`, with retries, server-sent events, a capability cache, pricing, and effort mapping |
| `src/Tools` | The tools that the model can call, and the tool registry |
| `src/Security` | Permissions, rules, the hard line, path containment, secret files, and command analysis |
| `src/Exec` | Command execution on the host and in bubblewrap, Podman, and Docker, with domain filtering and background jobs |
| `src/Git` | The Git and GitHub CLI policies |
| `src/Mcp` | The MCP client (stdio and HTTP), OAuth, and deferred tool search |
| `src/Skills` | The skill catalog, scanner, and writer |
| `src/Web` | Search backends, page fetching, the SSRF guard, and the cache |
| `src/Review`, `src/Research`, `src/Btw` | The `/review`, `/deepresearch`, and `/btw` commands |
| `src/Checkpoint` | File snapshots per turn, for `/undo`, `/redo`, and `/rewind` |
| `src/Session` | Sessions, transcripts, compaction, and the context budget |
| `src/Context` | Loading of `AGENTS.md` and `CLAUDE.md` |
| `src/Rpc`, `src/Acp`, `src/Serve` | Protocols for the full-screen interface, for editors, and for remote clients |
| `src/Tui`, `src/Ui` | The built-in PHP interface, console output, and `stream-json` output |
| `src/Ssh`, `src/Deploy` | SSH hosts, and deployment drivers |
| `src/Gentle` | The gentle-ai integration |
| `src/RepoMap` | The symbol map of the codebase |
| `src/Memory` | Memory notes. Not connected yet. See [Known limitations](#known-limitations). |
| `src/Diff`, `src/Support` | Diffs, the key vault, redaction, JSON helpers, and state storage |
| `tui/` | The full-screen interface in Go, built with Bubble Tea, Lip Gloss, and Glamour |
| `resources/` | The built-in agents and personas |

## Development

### Build

```sh
composer install
(cd tui && go build -o ../bin/phycode-tui ./cmd/phycode-tui)
```

Build the PHAR archive with `php bin/build-phar build/phycode.phar`. See [Build a PHAR archive](#build-a-phar-archive).

### Test

```sh
vendor/bin/phpunit                     # unit and integration suites
vendor/bin/phpunit --testsuite unit    # unit suite only
(cd tui && go test -race ./...)        # Go tests
```

The unit tests use scripted model replies and temporary Git repositories, so they need neither a model server nor a container runtime. The integration tests start the RPC backend as a separate process. Tests that require bubblewrap are skipped when `bwrap` is not installed.

### End-to-end test

```sh
tests/E2e/containers.sh                            # Debian, Alpine, Arch, and openSUSE
tests/E2e/containers.sh debian                     # one distribution: debian, alpine, arch, or opensuse
PHYCODE_E2E_NETWORK=host tests/E2e/containers.sh   # when the container network has no DNS
PHYCODE_E2E_RUNTIME=docker tests/E2e/containers.sh # Docker instead of Podman
```

`tests/E2e/hard-line.php` runs `phycode run --yolo` against a scripted model, and asks for destructive commands through `bash`, a custom tool, and a deploy step. The targets are a neighbour's home (in `/etc/passwd`, missing from it, by `~neighbour`, and by glob), `/mnt`, `/media`, a mounted tmpfs and the folder that holds it, `/usr/bin`, `/usr/lib`, and the contents of the home folder reached from the project with `../*`. The test passes when each call is refused, each folder still exists, and the same three tools still delete a folder of the project. The script deletes real folders when the hard line fails, so it refuses to run outside a container, unless `PHYCODE_E2E_DISPOSABLE=1` says that the machine is throw-away, or as a user other than root.

`containers.sh` starts a new container for each distribution, installs PHP and Composer from the distribution's packages, mounts a tmpfs at `/srv/disk/vol`, and creates the user `neighbour`. It copies the repository without `vendor`, runs `composer install`, and then runs the end-to-end script and the security and deploy unit tests. See [Supported systems](#supported-systems) for the results.

On a machine that is not a container, such as the FreeBSD virtual machine of the CI workflow, mount a tmpfs at `/srv/disk/vol`, create the user `neighbour`, and run `PHYCODE_E2E_DISPOSABLE=1 php tests/E2e/hard-line.php` as root. The script does not run on macOS, whose read-only root volume has no `/srv` or `/mnt`.

### Lint and format

```sh
(cd tui && go vet ./... && gofmt -l .)
```

`gofmt -l` lists the files that need formatting. A clean run prints nothing.

### Smoke test

```sh
bin/phycode version
bin/phycode agents
bin/phycode doctor --cwd .
```

`doctor` exits with status 1 when a check fails, for example when no model server is running. `version` and `agents` do not depend on a model server.

## Known limitations

- Ollama is covered only by recorded fixtures. No chat model was installed for a live test.
- The memory notes in `src/Memory/MemoryStore.php` enforce scope and secret rules, but no tool or prompt uses them yet.
- `run_code` does not pass through the hard line. The sandbox is its protection.
- The hard line judges an `ssh` command with the users, the mounts, and the `PATH` of this machine, not of the remote host.
- A folder two or more levels below the root, such as `/usr/local/lib`, is on the hard line only when it is on `PATH`, is or holds a home, or is or holds a mount point. `/var/lib` is refused on most machines because the home of a system account is inside it, but that depends on the machine.
- The hard line reads the summary of every exec tool as a command, so an `oracle` question that quotes a destructive command, for example in backticks, is refused too. Rephrase the question.
- The hard line resolves links and globs when it reads the command, and the command runs later. A link or a folder that changes in between is not seen. This matters most when phycode runs as root and the model can make links, so run phycode as a normal user, or in a container.
- Without a GNU `timeout`, which is always the case on FreeBSD, a background command stops at its lifetime only while phycode runs. If phycode is killed, the command goes on.
- The container sandboxes and the end-to-end hard-line test have not run on macOS.
- If a secret file is renamed to a name that does not look like a secret, an approved move exposes its content under the new name. The approval prompt shows both names.
- For very long answers (about 120 KB), the Go interface uses a lot of CPU while the answer streams, because it renders Markdown for each chunk. This issue is known, and it is not fixed yet.

## Comparison with other coding agents

The table summarizes what each project's public README and documentation state as of 21 October 2026. The comparison does not describe the other projects' source code. "Not described" means that the public documentation does not cover the topic.

| | phycode | pi | Hermes Agent | OpenCode | Claude Code | Codex |
| --- | --- | --- | --- | --- | --- | --- |
| License | Apache-2.0 | MIT | MIT | MIT | Proprietary, all rights reserved | Apache-2.0 |
| Languages | PHP 8.4, Go | TypeScript | Python | TypeScript | Plugins, examples, and scripts in the public repository | Rust, with TypeScript and Python SDKs |
| Models | llama.cpp, Ollama, OpenAI, Anthropic, OpenRouter, Groq, and compatible servers | Several providers through `pi-ai` | Several, including Nous Portal and OpenRouter | Not described | Claude models | A ChatGPT account, or an OpenAI API key |
| Permissions and sandboxing | Five permission modes, rules, a hard line, and bubblewrap, Podman, or Docker sandboxes | No built-in permission system. Recommends containers | `smart`, `manual`, and `off` approval modes. Seven terminal backends | A plan agent without edits. Bash requires approval | Permission modes. CI workflows run behind an egress firewall | Documented on the OpenAI website |
| Subagents | One level, fresh context, inherited permissions | None, by design | `delegate_task`, up to 10 in parallel by default | The `general` subagent, invoked with `@general` | Agents inside plugins | Not described |
| Skills and memory | `SKILL.md` skills, `AGENTS.md`, and sessions. Memory notes are not connected yet | The Agent Skills specification. A session tree with branches | Skills that the agent creates and improves. `MEMORY.md` and `USER.md` | Not described | Skills and commands inside plugins | `AGENTS.md`. The rest is on the OpenAI website |
| Extensions | Hooks with five events, checks, custom tools, and MCP | TypeScript extensions in the process, npm or git packages, MCP, and codemode | Four hook systems, plugins, and MCP | A plugin API in `packages/plugin` | Plugins with commands, agents, hooks, and MCP | Hooks managed by administrators. The rest is on the OpenAI website |
| Interfaces | Chat, a full-screen Go interface, `run`, RPC, ACP, and `serve` | A full-screen interface, print and JSON modes, RPC, and an SDK | A CLI, messaging gateways, a desktop app, and cron | A terminal interface and a desktop app (beta) | Terminal, IDE, and `@claude` on GitHub | CLI, IDE extension, desktop app, and web |

## Contributing

Report bugs and ask questions by opening an issue in the repository. Before you change the code, run the tests described in [Development](#development). Pull requests are welcome. For large changes, open an issue first so that the design can be discussed.

## License

phycode is licensed under the [Apache License, Version 2.0](LICENSE). The SPDX identifier is `Apache-2.0`.

Copyright 2026 the phycode authors.
