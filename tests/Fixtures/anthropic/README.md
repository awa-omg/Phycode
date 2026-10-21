# Anthropic Messages fixtures

Two groups. Read the group a file belongs to before you trust it.

- **Recorded (live)**: the raw body of `POST /v1/messages` on llama-server b11721 at `127.0.0.1:8082` (alias `qwen35-2b`, `--jinja`). Each `*.sse` is the body that the request file beside it produced, unedited. The request files are the bodies that `AnthropicClient` built for those recordings, sent with curl, so the fields match what phycode sends.
- **Documented (hand-written)**: files under `documented/`. Written from the Messages streaming reference, as summarised in `docs/research/providers.md` section 4. No reply recorded from llama-server has these events or stop reasons (`pause_turn`, `refusal`, real signatures, `stop_sequence`, `model_context_window_exceeded`, mid-stream `error`), so they are written from the documentation. The model names and ids in them are invented.

## Recorded

| File | Request file | What it shows |
| --- | --- | --- |
| `text.sse` | `text.request.json` | Plain text, "Blue". `stop_reason` `end_turn`, `input_tokens` 33, `cache_read_input_tokens` 0. Thinking off: `chat_template_kwargs` sent through `extra_body`. |
| `tool.sse` | `tool.request.json` | Native tool call `get_weather`. `tool_use` block, arguments streamed as `input_json_delta`, `stop_reason` `tool_use`. |
| `tool-result.sse` | `tool-result.request.json` | The reply after a tool result: text, `end_turn`. The request holds the `tool_use` and `tool_result` blocks. |
| `thinking.sse` | `thinking.request.json` | `thinking: {type: adaptive}`. A `thinking` block with `thinking_delta`s, then the answer "391". llama-server sends the signature as an empty string. |
| `max-tokens.sse` | `max-tokens.request.json` | `max_tokens` 16. `stop_reason` `max_tokens`, the text cut at "eight,". |

Re-sent on 2026-10-19, later the same day, with the same request bodies: `text.request.json` gave the same events (`message_start`, `content_block_start`, one `text_delta` "Blue", `content_block_stop`, `message_delta` with `end_turn`, `message_stop`), and `tool.request.json` gave the same events with a `tool_use` block whose arguments streamed in five `input_json_delta`s. The ids and the cache counts differ from the recordings, as expected.

## Documented

| File | What it shows |
| --- | --- |
| `documented/thinking-signature.sse` | A thinking block with a `signature_delta`, a `ping`, then text and a `tool_use` block whose `input` starts empty. Cache creation and cache read counts are set, so the prompt count is 120 + 40 + 300. |
| `documented/pause-turn.sse` | `stop_reason` `pause_turn`, with a `server_tool_use` block and its `web_search_tool_result`. |
| `documented/pause-turn-continued.sse` | The continuation after `pause_turn`: text and `end_turn`. |
| `documented/refusal.sse` | `stop_reason` `refusal` with `stop_details.explanation`. |
| `documented/stop-sequence.sse` | `stop_reason` `stop_sequence` with the sequence. |
| `documented/context-window.sse` | `stop_reason` `model_context_window_exceeded`, no content. |
| `documented/overloaded-mid-stream.sse` | An `error` event (`overloaded_error`) after some text. |
| `documented/no-message-stop.sse` | A stream cut before `message_stop`. |

## Capabilities (hand-written from the schema)

`model-*.json` are replies of `GET /v1/models/{id}`, written from the schema of https://platform.claude.com/docs/en/api/models/retrieve (`capabilities.effort` and `capabilities.thinking.types`, read 2026-10-20), with the levels and types of the per-model tables of the effort and troubleshooting pages. No reply was recorded and no key was used.

| File | What it shows |
| --- | --- |
| `model-opus-4-5.json` | Extended thinking only: `enabled` and `disabled` supported, `adaptive` not. Effort low to high; `xhigh` null, `max` not supported. |
| `model-sonnet-5.json` | Adaptive and `disabled` supported, `enabled` not. Effort low to max. |
| `model-sonnet-5-5.json` | `between_tools` supported, `disabled` not. |
| `model-mythos-preview.json` | Adaptive and `enabled`; `disabled` not supported. Effort without xhigh. |
| `model-sonnet-5-no-disabled.json` | Invented, for `claude-sonnet-5`: it reports `between_tools` and no `disabled`. It shows that the report wins over the table, which lists `disabled` for that model. |

## Tests that read these

- `tests/Unit/Anthropic/AnthropicRequestTest.php` compares the body the client builds with `text.request.json`, `tool-result.request.json` and `thinking.request.json`, and sends the `model-*.json` reports as the capabilities of the model.
- `tests/Unit/Anthropic/AnthropicReplyTest.php` and `AnthropicClientTest.php` read the events, the stop reasons, `pause_turn`, the thinking replay and the errors. `AnthropicClientTest.php` also reads the capabilities once per client and keeps them in the capability cache.
