# OpenAI Responses fixtures

Two groups. Read the group a file belongs to before you trust it.

- **Recorded (live)**: the raw body of `POST /v1/responses` on llama-server b11721 at `127.0.0.1:8082` (alias `qwen35-2b`, `--jinja`, stateless: every request carries `store: false`). Each `*.sse` is the body that the request file beside it produced, unedited. The request files are the bodies that `ResponsesClient` built for those recordings, sent with curl.
- **Documented (hand-written)**: files under `documented/`, written from the Responses streaming reference (`docs/research/providers.md` section 3). llama-server's binary has no strings for `response.incomplete`, `response.failed` or `response.function_call_arguments.done`, and no live reply has shown them (providers.md, section 6), so those files show the documented shapes. The ids in them are invented.

## Recorded

| File | Request file | What it shows |
| --- | --- | --- |
| `text.sse` | `text.request.json` | Plain text, "Blue". `status` `completed`, `input_tokens` 33 with `cached_tokens` 29. Thinking off: `chat_template_kwargs` sent through `extra_body`. |
| `tool.sse` | `tool.request.json` | A `function_call` item, `get_weather`, arguments streamed in five `function_call_arguments.delta`s and complete at `output_item.done`. `status` `completed`. |
| `tool-result.sse` | `tool-result.request.json` | The reply after a tool result: text. The request holds the `function_call` and `function_call_output` items. |
| `reasoning.sse` | `reasoning.request.json` | `reasoning: {effort: low}` with `include: [reasoning.encrypted_content]`. A `reasoning` item streamed as `reasoning_text.delta`s, kept whole with an empty `encrypted_content`, then the answer "391". 200 output tokens. |
| `truncated.sse` | `truncated.request.json` | `max_output_tokens` 8, cut mid-count at "One, Two, Three, Four,". The status is **`completed`** and `output_tokens` is 8. llama-server does not say that the limit cut the reply, so `ResponsesClient` compares the count with the limit. |

Re-sent on 2026-10-19, later the same day, with the same request bodies: `text.request.json` gave the same events (`response.created` to `response.completed`, one `response.output_text.delta` "Blue", `status` `completed`), and `tool.request.json` gave one `function_call` item with `call_id` and `name` `get_weather`, with five argument deltas. The ids and the cache counts differ from the recordings, as expected.

## Documented

| File | What it shows |
| --- | --- |
| `documented/reasoning-encrypted.sse` | A reasoning item with an `encrypted_content` and a summary, then a `function_call` with its arguments. |
| `documented/message-without-deltas.sse` | A message whose text arrives only in `output_item.done`. |
| `documented/incomplete-max-output-tokens.sse` | `response.incomplete` with `incomplete_details.reason` `max_output_tokens`. |
| `documented/incomplete-content-filter.sse` | `response.incomplete` with reason `content_filter`. |
| `documented/failed.sse` | `response.failed` with `error.message`. |
| `documented/error.sse` | A stream `error` event (`rate_limit_exceeded`). |
| `documented/no-completed.sse` | A stream cut before any final event. |

## Requests

`*.request.json` are the bodies. The client's own request form is checked by `tests/Unit/Responses/ResponsesRequestTest.php`, which compares what `ResponsesClient` sends with `text.request.json`, `tool.request.json`, `tool-result.request.json` and `reasoning.request.json`.

## Tests that read these

- `tests/Unit/Responses/ResponsesReplyTest.php` reads the events: text, calls, reasoning, usage, every final status and the errors.
- `tests/Unit/Responses/ResponsesClientTest.php` covers the finish reasons (including the truncated recording as a length), the reasoning replay across a tool round, the retries and the errors.
- `tests/Unit/Responses/ResponsesInputTest.php` covers the conversion of the history into input items and the function tools.
- `tests/Unit/Responses/ResponsesModelsTest.php` reads `tests/Fixtures/llama/v1-models.json`, the recorded `GET /v1/models` of the same server.
