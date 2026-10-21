# Mistral fixtures

These files are rebuilt from the documented shape, not recordings of a live server. Nothing here was sent to api.mistral.ai.

- `content.sse`: a stream with `reasoning_effort` set to high, in the shape of the "Streaming" section of
  https://docs.mistral.ai/capabilities/reasoning (read 2026-10-20): the content is a list of chunks, a thinking chunk with text parts,
  then a text part; the answer goes on as a string. The file has no blank line after its last event, so the final usage chunk arrives
  only when the stream is flushed.
- `reply.json`: the same reply without streaming, which a server may send when it ignores `stream: true`.
