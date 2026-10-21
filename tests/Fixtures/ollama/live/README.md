# Live recordings: gemma4:e2b on Ollama 0.32.15

Recorded on 2026-10-19, between 11:04 and 11:15 UTC, from the Ollama daemon at `http://127.0.0.1:11434` (version 0.32.15). The model is `gemma4:e2b`: 4.6B parameters, Q4_K_M, 4.6 GB, digest `b37049369adf`. The coordinator pulled it between 10:59 and 11:03, and nothing else was pulled. The chat ran on a `llama-server` subprocess on the CPU (`size_vram` 0).

Each file is the raw response body of the request named in the table, unedited. `requests/` holds the request bodies, byte for byte as they were sent with `curl --data-binary`. They have the fields and the order that `OllamaClient::requestBody` builds for the default profile (`num_ctx` 16384, `num_predict` 2048, the default sampling, `keep_alive` 5m), with the `think` value the row names. `OllamaRequestTest::testTheRequestForTheRecordedPlainAnswerIsTheRecordedRequest` checks one of them against the client.

The chat requests were sent one after another, and the model stayed loaded between them. The first request loaded it, which is why its `load_duration` is about 34 s.

## Files

| File | Request | Status | What the body shows |
| --- | --- | --- | --- |
| `chat-text.ndjson` | `requests/chat-text.json`: "di hola en una palabra", think false | 200 | Four lines of `content`: `"**"`, `"Hola"`, `"**"`, then the done line. `done_reason` `stop`, `prompt_eval_count` 14, `eval_count` 4 in 5.13 s. No `prompt_eval_cached_count`. |
| `chat-thinking.ndjson` | `requests/chat-thinking.json`: "¿Cuánto es 17 por 23? Razona brevemente.", think true | 200 | 410 lines with `message.thinking` set and `content` "", then 121 lines with `content` and no `thinking`. The done line has `done_reason` `stop`, `prompt_eval_count` 32 and `eval_count` 651, which includes the reasoning tokens. |
| `chat-tool.ndjson` | `requests/chat-tool.json`: "¿qué tiempo hace en Madrid?", think false, one tool `get_weather(city)` | 200 | Line 1: `content` "" and `tool_calls` `[{"id":"call_meht2eww","function":{"index":0,"name":"get_weather","arguments":{"city":"Madrid"}}}]`. Line 2 is the done line: `done_reason` `stop`, `prompt_eval_count` 74, `eval_count` 15. |
| `chat-length.ndjson` | `requests/chat-length.json`: "Explica en detalle cómo funciona la fotosíntesis.", `num_predict` 8 | 200 | Eight lines of text, `"## La Fotosíntesis: El Pro"`, then the done line: `done_reason` `length`, `eval_count` 8. |
| `chat-cached-first.ndjson` | `requests/chat-cached.json`, first send | 200 | `"**Acueductos**"`. `prompt_eval_count` 479, `prompt_eval_duration` 5.53 s. |
| `chat-cached-second.ndjson` | `requests/chat-cached.json`, the same bytes sent again | 200 | `"**Acueductos** (o **Ingeniería**)."`. `prompt_eval_count` is 479 again, but `prompt_eval_duration` is 0.13 s: the prompt came from the cache. No `prompt_eval_cached_count`. |
| `chat-cached-prefix.ndjson` | `requests/chat-cached-prefix.json`: the same text, asking for two words | 200 | `"**Ingeniería hidráulica**"`. `prompt_eval_count` 478 and `prompt_eval_duration` 5.60 s: the shared prefix was not reused. No `prompt_eval_cached_count`. |
| `chat-think-high.ndjson` | `requests/chat-think-high.json`: "di hola en una palabra", think `"high"` | 200 | The level is accepted although `/api/show` names no levels for this model. 179 lines: 706 characters of `thinking`, then `content` `"**Hola**"`. `done_reason` `stop`, `eval_count` 201. |
| `chat-not-found.json` | `requests/chat-not-found.json`: model `no-such-model:latest` | 404 | `{"error":"model 'no-such-model:latest' not found"}`, with no trailing newline. Nothing was pulled. |
| `chat-error.ndjson` | `requests/chat-error.json`: the photosynthesis question, `num_predict` 2048 | 200 | Five lines of text, then `{"error":"an error was encountered while running the model: unexpected EOF"}`. There is no done line. The stream was cut on purpose: the gemma4:e2b `llama-server` was killed with SIGKILL while it was streaming. The status stays 200 because the stream had started. |
| `show-gemma4-e2b.json` | `POST /api/show` with body `{"model":"gemma4:e2b"}` | 200 | `capabilities` `["completion","vision","audio","tools","thinking"]`. No `thinking` object, so there are no named levels. `model_info["gemma4.context_length"]` is 131072, and `details.quantization_level` is `Q4_K_M`. 67 KB, and most of it is the licence text. |
| `tags-gemma4-e2b.json` | `GET /api/tags`, after the pull | 200 | `gemma4:e2b` with `capabilities` `["completion","tools","thinking","vision"]` and `details.context_length` 131072. The other model is `nomic-embed-text:latest`, with `["embedding"]`. |
| `ps-gemma4-e2b.json` | `GET /api/ps`, after the chat requests | 200 | `gemma4:e2b` is loaded: `context_length` 16384 (the `num_ctx` of the requests), `size_vram` 0, and `expires_at` five minutes after the last request. |

## What the recordings settle

- **Tool calls.** A call comes whole, in one line, with its own `id` and `function.index`. The client keeps the `id`. The reply ends with `done_reason` `stop`, so a stop with calls is a tool-call reply.
- **Done reasons.** `stop` and `length` appeared. No other value appeared, and the unload and load reasons were not provoked. `think` with a level that `/api/show` does not name was accepted and streamed reasoning.
- **Thinking.** Reasoning arrives in `message.thinking` lines with empty `content`. The answer starts once the lines carry `content`.
- **Prompt counts.** `prompt_eval_count` is the whole prompt. The repeated prompt has 479 both times, and its second evaluation was served from the cache. `prompt_eval_cached_count` was not sent in any of these replies, so the client keeps `cachedTokens` null unless a server sends it.
- **Mid-stream failure.** The error line is the last line, and it has no done line. The client must report it, not treat the stream as finished.

## Reproduce

```sh
curl -sS -N -X POST -H 'Content-Type: application/json' --data-binary @requests/chat-text.json http://127.0.0.1:11434/api/chat -o chat-text.ndjson
curl -sS -X POST -H 'Content-Type: application/json' -d '{"model":"gemma4:e2b"}' http://127.0.0.1:11434/api/show -o show-gemma4-e2b.json
curl -sS http://127.0.0.1:11434/api/tags -o tags-gemma4-e2b.json
curl -sS http://127.0.0.1:11434/api/ps -o ps-gemma4-e2b.json
```

The other chat files come from the matching request file in `requests/`, with the same command and a different file name. The cache rows depend on the order of the requests: the repeated request hit the cache because it followed the first send while the model was still loaded.

## Request bodies

### `requests/chat-text.json`

Used by: `chat-text.ndjson`, plain answer

```json
{"model":"gemma4:e2b","messages":[{"role":"user","content":"di hola en una palabra"}],"stream":true,"think":false,"options":{"num_predict":2048,"num_ctx":16384,"temperature":0.6,"top_p":0.95,"top_k":20,"min_p":0.0,"presence_penalty":0.0},"keep_alive":"5m"}
```

### `requests/chat-thinking.json`

Used by: `chat-thinking.ndjson`, think true

```json
{"model":"gemma4:e2b","messages":[{"role":"user","content":"¿Cuánto es 17 por 23? Razona brevemente."}],"stream":true,"think":true,"options":{"num_predict":2048,"num_ctx":16384,"temperature":0.6,"top_p":0.95,"top_k":20,"min_p":0.0,"presence_penalty":0.0},"keep_alive":"5m"}
```

### `requests/chat-tool.json`

Used by: `chat-tool.ndjson`, native tool call (the tool is defined here, for this test only)

```json
{"model":"gemma4:e2b","messages":[{"role":"user","content":"¿qué tiempo hace en Madrid?"}],"stream":true,"think":false,"options":{"num_predict":2048,"num_ctx":16384,"temperature":0.6,"top_p":0.95,"top_k":20,"min_p":0.0,"presence_penalty":0.0},"keep_alive":"5m","tools":[{"type":"function","function":{"name":"get_weather","description":"Get the current weather for a city.","parameters":{"type":"object","properties":{"city":{"type":"string","description":"The city name"}},"required":["city"]}}}]}
```

### `requests/chat-length.json`

Used by: `chat-length.ndjson`, token limit

```json
{"model":"gemma4:e2b","messages":[{"role":"user","content":"Explica en detalle cómo funciona la fotosíntesis."}],"stream":true,"think":false,"options":{"num_predict":8,"num_ctx":16384,"temperature":0.6,"top_p":0.95,"top_k":20,"min_p":0.0,"presence_penalty":0.0},"keep_alive":"5m"}
```

### `requests/chat-cached.json`

Used by: `chat-cached-first.ndjson` and `chat-cached-second.ndjson`, the same request sent twice

```json
{"model":"gemma4:e2b","messages":[{"role":"user","content":"Los acueductos romanos fueron una de las obras de ingeniería hidráulica más notables de la Antigüedad. Transportaban agua desde manantiales y ríos situados a decenas de kilómetros hasta las ciudades, donde alimentaban fuentes, termas, letrinas públicas y casas particulares. Su trazado aprovechaba la gravedad: el canal descendía de forma casi imperceptible, con una pendiente de unos pocos centímetros por kilómetro, y para salvar los valles se construían arcadas de piedra, ladrillo y mortero. El de Segovia, por ejemplo, sigue en pie casi dos mil años después, y sus arcos principales se levantaron sin argamasa. Los ingenieros medían los desniveles con instrumentos como la groma y el chorobates, y controlaban el caudal con piezas calibradas de plomo o de bronce. Roma llegó a recibir más de un millón de metros cúbicos de agua al día a través de once acueductos principales, una cifra que no volvió a alcanzarse hasta el siglo diecinueve. El mantenimiento correspondía a cuadrillas públicas que limpiaban los canales de sedimentos y reparaban las fisuras con mortero de cal. Muchos acueductos de provincias, como los de Tarragona o Mérida, combinaban tramos subterráneos, puentes monumentales y depósitos de distribución. Cuando el Imperio se debilitó, buena parte de estas redes se abandonó, y en la Edad Media algunos tramos se reutilizaron como canteras de piedra. Hoy los estudian historiadores, arqueólogos e ingenieros civiles, que encuentran en ellos lecciones sobre la gestión del agua a gran escala, la topografía sin instrumentos modernos y la durabilidad de los materiales. Otros acueductos siguen funcionando, como el Aqua Virgo, que todavía abastece fuentes de Roma con parte de su antiguo trazado. La construcción exigía planificar durante años, medir con precisión y coordinar a miles de trabajadores, esclavos y artesanos especializados. En muchas ciudades, el agua que llegaba por el acueducto se repartía en depósitos con varios conductos, de modo que se distribuía según un orden de prioridades que a veces quedaba documentado en inscripciones públicas.\n\nResume el texto anterior en una sola palabra."}],"stream":true,"think":false,"options":{"num_predict":2048,"num_ctx":16384,"temperature":0.6,"top_p":0.95,"top_k":20,"min_p":0.0,"presence_penalty":0.0},"keep_alive":"5m"}
```

### `requests/chat-cached-prefix.json`

Used by: `chat-cached-prefix.ndjson`, shared prefix, different end

```json
{"model":"gemma4:e2b","messages":[{"role":"user","content":"Los acueductos romanos fueron una de las obras de ingeniería hidráulica más notables de la Antigüedad. Transportaban agua desde manantiales y ríos situados a decenas de kilómetros hasta las ciudades, donde alimentaban fuentes, termas, letrinas públicas y casas particulares. Su trazado aprovechaba la gravedad: el canal descendía de forma casi imperceptible, con una pendiente de unos pocos centímetros por kilómetro, y para salvar los valles se construían arcadas de piedra, ladrillo y mortero. El de Segovia, por ejemplo, sigue en pie casi dos mil años después, y sus arcos principales se levantaron sin argamasa. Los ingenieros medían los desniveles con instrumentos como la groma y el chorobates, y controlaban el caudal con piezas calibradas de plomo o de bronce. Roma llegó a recibir más de un millón de metros cúbicos de agua al día a través de once acueductos principales, una cifra que no volvió a alcanzarse hasta el siglo diecinueve. El mantenimiento correspondía a cuadrillas públicas que limpiaban los canales de sedimentos y reparaban las fisuras con mortero de cal. Muchos acueductos de provincias, como los de Tarragona o Mérida, combinaban tramos subterráneos, puentes monumentales y depósitos de distribución. Cuando el Imperio se debilitó, buena parte de estas redes se abandonó, y en la Edad Media algunos tramos se reutilizaron como canteras de piedra. Hoy los estudian historiadores, arqueólogos e ingenieros civiles, que encuentran en ellos lecciones sobre la gestión del agua a gran escala, la topografía sin instrumentos modernos y la durabilidad de los materiales. Otros acueductos siguen funcionando, como el Aqua Virgo, que todavía abastece fuentes de Roma con parte de su antiguo trazado. La construcción exigía planificar durante años, medir con precisión y coordinar a miles de trabajadores, esclavos y artesanos especializados. En muchas ciudades, el agua que llegaba por el acueducto se repartía en depósitos con varios conductos, de modo que se distribuía según un orden de prioridades que a veces quedaba documentado en inscripciones públicas.\n\nResume el texto anterior en dos palabras."}],"stream":true,"think":false,"options":{"num_predict":2048,"num_ctx":16384,"temperature":0.6,"top_p":0.95,"top_k":20,"min_p":0.0,"presence_penalty":0.0},"keep_alive":"5m"}
```

### `requests/chat-think-high.json`

Used by: `chat-think-high.ndjson`, think `"high"`

```json
{"model":"gemma4:e2b","messages":[{"role":"user","content":"di hola en una palabra"}],"stream":true,"think":"high","options":{"num_predict":2048,"num_ctx":16384,"temperature":0.6,"top_p":0.95,"top_k":20,"min_p":0.0,"presence_penalty":0.0},"keep_alive":"5m"}
```

### `requests/chat-not-found.json`

Used by: `chat-not-found.json`, unknown model

```json
{"model":"no-such-model:latest","messages":[{"role":"user","content":"hola"}],"stream":true,"think":false,"options":{"num_predict":2048,"num_ctx":16384,"temperature":0.6,"top_p":0.95,"top_k":20,"min_p":0.0,"presence_penalty":0.0},"keep_alive":"5m"}
```

### `requests/chat-error.json`

Used by: `chat-error.ndjson`, killed runner

```json
{"model":"gemma4:e2b","messages":[{"role":"user","content":"Explica en detalle cómo funciona la fotosíntesis, paso a paso, con todas sus fases y sus productos."}],"stream":true,"think":false,"options":{"num_predict":2048,"num_ctx":16384,"temperature":0.6,"top_p":0.95,"top_k":20,"min_p":0.0,"presence_penalty":0.0},"keep_alive":"5m"}
```
