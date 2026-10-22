# Changelog

## 0.2.0 (2026-10-22)

The first tagged release, with a PHAR archive and a FreeBSD port. Version 0.1.0 was the version of the code before it, and it was never released.

### Added

- `glob` tool: finds files by pattern, such as `src/**/*.php`, over the files of the project. `list` shares the same listing.
- `session_search` tool: searches the recent sessions of the project for what was said or decided there.
- `memory` and `memory_save` tools: agents read the notes of the project and of the user, and save new ones. `/memory` shows the notes and forgets one. `memory.autonomy` decides whether a save asks first (`ask`, the default) or not (`auto`), and `memory.enabled` turns notes off.
- `lsp` tool: asks the language servers in the `lsp` section for diagnostics, definitions, references, hover text, and symbols.
- `browser` tool: drives a headless Chrome or Chromium to check a page or your dev server. It is off until `browser.enabled` is `true`, its profile folder is thrown away, and it never outlives phycode.
- `@path` in a chat message attaches that file. `@agent` still calls an agent.
- `/fork [N]` and `phycode run --fork ID` continue in a copy of a session, and `/tree` shows each fork under the session it came from.
- `/goal [--check COMMAND] [--max N] CONDITION` works turn after turn until the command exits with 0, or a model judge accepts the report.
- `phycode plugin`: adds agents, skills, and prompt templates from a folder or a Git repository, without running its hooks.
- `phycode schedule`: runs tasks on a cron schedule, with a foreground scheduler.
- `phycode run --worktree NAME`: works in a new git worktree on the branch `phycode/NAME`, which is removed when the run changes nothing. `phycode worktree` lists and removes them.
- When a deploy `apply` fails after it changed something, and the target has a `rollback`, phycode says how to roll back, and asks whether to do it now. It never rolls back on its own.

### Changed

- A command now ends when phycode ends, also when phycode is killed. On Linux, phycode starts each command through `setpriv --pdeathsig TERM`. On FreeBSD and macOS, a watcher in the wrapper of the command stops it.
- A time limit, a cancel, or a stopped background job now also stops the processes that left the process tree, such as a daemon started with `setsid`, `daemon(8)`, or `env -i`. On FreeBSD, each command runs under the base `timeout` as a reaper; on Linux and macOS, phycode finds them by a mark in their environment and an open file descriptor.
- A background command keeps its time limit when phycode is not looking, or not running.
- macOS no longer uses `gtimeout`.

### Security

- The hard line judges an `ssh` command, and a deploy step on an SSH host, with the users, the mounts, and the `PATH` of that host.
- The hard line reads every text argument of an MCP tool.
- In a `run_code` program on the host, the hard line reads the shell commands that Python or JavaScript runs, and the recursive deletes of a literal path or of the home.
- `~/bin` and other `PATH` folders inside a home are refused themselves, while the rest of the home stays usable.
- A quoted path with spaces is read as one path.

### Platforms

- phycode runs on Linux, macOS, and FreeBSD. CI tests Ubuntu, macOS on Apple silicon and on Intel, with and without coreutils, and FreeBSD 15.1.
- The FreeBSD port has a maintainer and the checksum of the release archive.
