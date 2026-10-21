# Ollama fixtures

Three groups of files live here. Read the group that a file belongs to before you trust it.

- `live/`: recorded from Ollama 0.32.15 with `gemma4:e2b`. Every chat reply is here, and `live/README.md` lists the exact request of each file. Nothing in this group is hand-written.
- Top level, recorded earlier: `version.json`, `tags.json`, `show-nomic-embed-text.json`, `show-not-found.json` and `ps-empty.json`. They come from the same daemon on 2026-10-19, before the model was pulled, when it had only `nomic-embed-text:latest` (embedding only) and nothing loaded. Each file is the raw response body.
- Top level, hand-written: `show-qwen3-thinking.json`, `show-gpt-oss.json` and `ps-loaded.json`. They are written from the documented format, because the daemon has no such model. Use them only for the shapes they show.

| File | Request | Reply |
| --- | --- | --- |
| `version.json` | `GET /api/version` | 200 `{"version":"0.32.15"}`. |
| `tags.json` | `GET /api/tags` | 200. One model, `nomic-embed-text:latest`, with `capabilities: ["embedding"]` and `details.context_length` 2048. |
| `show-nomic-embed-text.json` | `POST /api/show` with `{"model":"nomic-embed-text:latest"}` | 200, 32 KB (the Apache licence text is most of it). Capabilities `["embedding"]`, no `thinking` field, `model_info["nomic-bert.context_length"]` 2048. |
| `show-not-found.json` | `POST /api/show` with `{"model":"no-such-model:latest"}` | 404 `{"error":"model 'no-such-model:latest' not found"}`. Nothing is pulled by `show`. |
| `ps-empty.json` | `GET /api/ps` | 200 `{"models":[]}`. |

| File | What it shows (hand-written) |
| --- | --- |
| `show-qwen3-thinking.json` | `/api/show` for a model with `capabilities` including `thinking` and `thinking.values` `[false, true]`. |
| `show-gpt-oss.json` | `/api/show` for a model whose `thinking.values` are named levels, `["low","medium","high"]`. |
| `ps-loaded.json` | `/api/ps` with `qwen3:4b` and `nomic-embed-text:latest` loaded. |

The chat replies that used to be hand-written (`chat-text`, `chat-thinking`, `chat-tool`, `chat-length`, `chat-error`) are replaced by the recordings in `live/`. The gemma4:e2b show, tags and ps bodies are in `live/` too.

Still unverified against a real server:

- `done_reason` values other than `stop` and `length`. The load and unload reasons were not provoked.
- Tool calls split over several lines, and a tool call cut by the token limit. Every live call came whole, in one line.
- Named thinking levels (`thinking.values` with strings). Only `show-gpt-oss.json` shows them, and it is hand-written.
- Thinking replay. Not sent back, because the `ChatMessage` schema has no thinking field.
- `prompt_eval_cached_count`. No live reply sent it. `OllamaReply::usage()` explains how promptTokens is counted.
- `details.context_length` in `/api/tags`. It is seen live (`live/tags-gemma4-e2b.json`), but the client does not read it: the window comes from `/api/show`.

Re-record the live group with the commands in `live/README.md`. The earlier top-level recordings come from:
`curl -sS http://127.0.0.1:11434/api/tags`, the same for `/api/version` and `/api/ps`, and
`curl -sS -X POST -H 'Content-Type: application/json' -d '{"model":"nomic-embed-text:latest"}' http://127.0.0.1:11434/api/show`.
