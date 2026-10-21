# OpenRouter fixtures

These files are rebuilt from the documented schema, not recordings of a live server. Nothing here was sent to openrouter.ai.

- `models.json`: the body of `GET {base}/v1/models`, shaped as the reasoning page describes the per-model `reasoning` object
  (https://openrouter.ai/docs/guides/best-practices/reasoning-tokens, read 2026-10-20): `supported_efforts` in descending order,
  `null` for every level, `mandatory`, and a model with no `reasoning` object. The ids are examples.
- `tool-call.sse`: a stream in the shape of the "Reasoning Details API Shape" section of the same page: `reasoning` and
  `reasoning_details` in each delta, pieces that share an `index`, a signature sent in a later piece, an encrypted detail, then a tool
  call and a usage chunk.
