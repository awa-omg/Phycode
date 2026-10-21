# Recorded llama-server fixtures

Recorded on 2026-10-19 from llama-server b11721 (commit ff5a23376) at 127.0.0.1:8082, alias `qwen35-2b`, started with `-c 16384 --jinja`. No API key. Each file is the raw reply body, unedited, except `timings-only.request.json` (see below).

- `*.sse`: the body of a streaming `POST /v1/chat/completions`.
- `*.request.json`: the body that `OpenAiClient::requestBody()` built for that recording. The recorder called the private method by reflection and sent that exact body with curl, so the request fields match what phycode sends.
- `v1-models.json`: the body of `GET /v1/models`.

Most recordings sample at temperature 0.6 (the probes and `text-tool.sse` use 0.0). A recording is one sample, so tests assert what it contains, not what the model would say next time.

| File | How it was recorded |
| --- | --- |
| `text.sse` | (a) Plain text. Default profile: thinking off, `max_tokens` 2048, default sampling, `stream_options.include_usage`. The usage chunk (`choices: []`, usage and timings) arrives just before `[DONE]`. |
| `native-tool.sse` | (b) Native tool call. One tool, `get_weather`, with a JSON schema (`city` required, `unit` enum). The user asks for the weather in Madrid. `tool_choice` auto, `parallel_tool_calls` false. Finish reason `tool_calls`. |
| `reasoning.sse` | (c) Reasoning. Profile thinking `on`, so `chat_template_kwargs.enable_thinking` is `true`. 146 `reasoning_content` chunks, then the answer `51`. The file is large because the thinking trace is kept whole. |
| `cut.sse` | (d) Reply cut by the token limit with `max_tokens` 8. A `Profile` was built directly, because the config minimum is 16. Finish reason `length`. |
| `native-tool-cut.sse` | Extra. The tool call of (b) with `max_tokens` 12. Finish reason `length`, and the call is cut: arguments are only `{`. |
| `timings-only.sse`, `timings-only.request.json` | Extra. The text request without `stream_options`, to see what arrives without usage. Only change to the body: `stream_options` removed. There is no usage chunk; timings arrive on the chunk with finish reason `stop`. |
| `probe-native.sse` | The native probe that `Probe::run` sends (tool `probe_echo`, temperature 0.0). Finish reason `tool_calls` with `probe_echo` and `{"word":"phycode"}`. |
| `probe-text.sse` | The text probe that `Probe::run` sends (no tools, `TextToolProtocol::instructions`, temperature 0.0). The reply is a `<tool_call>` block that names `probe_echo`. |
| `text-tool.sse` | Text-mode tool call. The `read` tool on `src/a.php`, with the text protocol instructions. The reply is a `<tool_call>` block. |
| `v1-models.json` | `GET /v1/models`. One model, `qwen35-2b`, with `meta.n_ctx` 16384. |

Facts seen while recording:

- `stream_options.include_usage` is accepted. The usage chunk has `choices: []`, the usage object and the timings, and it comes just before `[DONE]`.
- Without `include_usage`, no usage is sent. The timings are on the chunk that carries `finish_reason`.
- In the 8 recordings that carry usage, `prompt_tokens` = `timings.prompt_n` + `timings.cache_n` (for example `text.sse`: 34 = 4 + 30), and `cached_tokens` = `timings.cache_n`. `prompt_n` leaves out the cached tokens, so the fallback without usage adds them back.
- Truncated tool arguments are not repaired by the server: `native-tool-cut.sse` ends with the arguments `{`.

## Discovery recordings (F1.2)

Recorded on 2026-10-19 with llama-server b11721. The 8082 server (alias `qwen35-2b`, `-c 16384 --parallel 1 --jinja`) was killed by the kernel's OOM killer during a router test and started again with the same arguments. The discovery recordings from 8082 come from that new process; the earlier files were recorded before the kill. The other recordings come from throwaway instances: the 0.8B model with `-c 2048` (or `4096`), and the 2B model in router mode with `--models-dir` and a preset that caps `ctx-size` at 4096. Each file is the raw reply body, unedited, except the error bodies, which are the body only.

| File | Route and how it was recorded |
| --- | --- |
| `health.json` | `GET /health` on 8082: `{"status":"ok"}`. |
| `health-loading.json` | `GET /health` while the 0.8B model was still loading: HTTP 503. |
| `props.json` | `GET /props` on 8082. `default_generation_settings.n_ctx` 16384, `model_path`, `build_info`, `chat_template`, `chat_template_caps` (`supports_tools` true), `total_slots` 1. |
| `props-no-jinja.json` | `GET /props` on the 0.8B model started with `--no-jinja --no-slots`. Same `chat_template` and `supports_tools: true` as with jinja. |
| `props-router.json` | `GET /props` on a router: `role` "router", `model_path` "none", `n_ctx` 0, no `chat_template`, no `chat_template_caps`. |
| `props-model.json` | `GET /props?model=Qwen3.5-2B-Q8_0` once the model was loaded: the full props of that model, `n_ctx` 4096. |
| `models-single.json` | `GET /models` on 8082. Same shape as `/v1/models` plus a `models` array; entries have no `status`. |
| `models-router-unloaded.json` | `GET /models` on a router with the model unloaded: `data` only (no `models` key); `status.value` "unloaded"; `context_length` 262144 (the trained maximum, not the runtime context); no `meta`. |
| `models-router-loading.json` | `GET /models` right after `POST /models/load`: `status.value` "loading". |
| `models-router-loaded.json` | `GET /models` after the load: `status.value` "loaded", `status.args` with `--ctx-size 4096`, `status.preset`. |
| `v1-models-router-loaded.json` | `GET /v1/models` on the router with the model loaded: `meta.n_ctx` 4096 (the runtime context). |
| `router-load.json`, `router-unload.json` | `POST /models/load` and `POST /models/unload` with `{"model":"Qwen3.5-2B-Q8_0"}`: `{"success":true}`. |
| `slots.json` | `GET /slots` on 8082 while idle: one slot, `is_processing` false. |
| `slots-busy.json` | `GET /slots` on the 0.8B model with `--parallel 2`, polled during a 400-token completion: slot 1 `is_processing` true, slot 0 idle. |
| `slots-router.json` | `GET /slots?model=Qwen3.5-2B-Q8_0` on the router: four slots (the child is started without `--parallel`). |
| `slots-router-missing-model.json` | `GET /slots` on the router without `model`: HTTP 400 "model name is missing from the request". |
| `slots-disabled.json` | `GET /slots` with `--no-slots`: HTTP 501 "This server does not support slots endpoint. Start it with `--slots`". |
| `tool-no-jinja.json` | `POST /v1/chat/completions` with the probe tool on the `--no-jinja` server: HTTP 500 "tools param requires --jinja flag". |

Facts seen while recording (all VERIFIED-LIVE):

- Auth covers the discovery routes too. With `--api-key`, `GET /health` is public, but `/props`, `/slots`, `/models` and `/v1/models` answer 401 "Invalid API Key" without the key, and 200 with `Authorization: Bearer`.
- Loading a model is a side effect of asking for it. `GET /props?model=` and `GET /slots?model=` on an unloaded router model start the load: the child process was started by the first such request, and it grew to 4.7 GB because it took the trained context of 262144. The discovery therefore asks for model-specific data only when `/models` says the model is loaded.
- Router mode is told apart by `GET /props` returning `role: "router"`. The `models` key of `/models` is not a signal: it is present in single-model mode and absent in router mode.
- `--no-jinja` does not change what `/props` reports. Only a request with `tools` shows it, with the 500 above, so the doctor checks tools with one request.
- The unix socket answers through `curl --unix-socket … http://localhost/…`. PHP 8.4's built-in server does not listen on sockets in this build, so the tests use `fake-llama.php`.
- TLS with a self-signed certificate fails in PHP curl with error 60, "SSL certificate problem: self-signed certificate". It works with `curl.cainfo` set to that certificate, which is the path the doctor's fix names.
