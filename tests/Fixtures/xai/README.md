# xAI fixtures

These files are rebuilt from the design, not recordings of a live server. Nothing here was sent to api.x.ai.

- `models.json`: the body of `GET /v1/models`, with `capabilities.reasoning_effort` on each model that takes the parameter. The
  reasoning page (https://docs.x.ai/developers/model-capabilities/text/reasoning, read 2026-10-20) gives the levels of grok-4.5,
  grok-4.6 and grok-4.7 (low, medium, high; xhigh from grok-4.6), says that reasoning cannot be disabled, and that presence and
  frequency penalties and stop are errors with reasoning. It does not show the models listing, so the listing field is UNVERIFIED.
  `grok-4-1-fast` has no such field: a model with no effort control.
