# DeepSeek fixtures

These files are rebuilt from the documented schema, not recordings of a live server. Nothing here was sent to api.deepseek.com.

- `models.json`: the listing with the per-model `effort.supported_levels` field that the design reads for DeepSeek. The shape is
  not in the thinking page (https://api-docs.deepseek.com/guides/thinking_mode, read 2026-10-20), which names the levels and the
  mapping but not the listing, so the field is UNVERIFIED against the live listing. `deepseek-chat` has no field, as a model without
  an effort control would not.
- `tool-call.sse`: a thinking-mode stream with `reasoning_content` in each delta and a tool call, in the shape the thinking page
  shows for the tool-call sample.
