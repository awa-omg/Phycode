# Web fixtures

Used by `tests/Unit/Web`. The tests never reach the internet: `FixtureServer` (a loopback `php -S`) serves these files, and the search backends are pointed at it.

## Where each file comes from

| File | Source |
| --- | --- |
| `brave-web-search.json` | **From the docs.** Brave Web Search API reference, `GET /res/v1/web/search`, 200 response: `type`, `query.original`, `query.more_results_available`, `web.type`, `web.results[].title`, `url`, `description` (https://api-dashboard.search.brave.com/api-reference/web/search/get, read 2026-10-19). The `X-Subscription-Token` header is from the same page. The values are made up. |
| `tavily-search.json` | **From the docs.** Tavily OpenAPI, `POST /search`, 200 response: `query`, `answer`, `images`, `results[].title`, `url`, `content`, `score`, `raw_content`, `response_time`, `usage.credits`, `request_id` (https://docs.tavily.com/documentation/api-reference/endpoint/search, read 2026-10-19). The values are made up. |
| `exa-search.json` | **From the docs.** Exa OpenAPI, `POST /search`: `SearchResultsResponse` (`requestId`, `results`, `costDollars`, `searchTime`) and `SearchResultOutput` (`title`, `url`, `publishedDate`, `author`, `highlights`, `highlightScores`), https://docs.exa.ai/reference/search, read 2026-10-19. The `x-api-key` header and `numResults` (1 to 100) are from the same page. The values are made up. |
| `perplexity-search.json` | **From the docs.** Perplexity Search API, `POST /search`: `results[].title`, `url`, `snippet`, `date`, `last_updated`, and `id`. The Bearer header and `max_results` (1 to 20 for web search) are from the same page (https://docs.perplexity.ai/api-reference/search-post, read 2026-10-20). The values are made up. |
| `ollama-search.json` | **From the docs.** Ollama web search, `POST /api/web_search`: `results[].title`, `url`, `content`; `max_results` (default 5, max 10) and the Bearer header (https://docs.ollama.com/capabilities/web-search, read 2026-10-20). The values are made up. |
| `firecrawl-search.json` | **From the docs.** Firecrawl Search, `POST /v2/search`: `success`, `data.web[].title`, `url`, `description`, `creditsUsed`, `id`; `limit` (1 to 100, default 10) and the bearer security scheme (https://docs.firecrawl.dev/api-reference/endpoint/search, read 2026-10-20). The values are made up. |
| `searxng-search.json` | **Partly from the docs.** The result fields are `MainResult` attributes on the SearXNG "Main Results" page (`url`, `title`, `content`, `engine`, `parsed_url`, `score`, ...; https://docs.searxng.org/dev/result_types/main/mainresult.html). The request `GET /search?q=…&format=json` is on https://docs.searxng.org/dev/search_api.html. The envelope (`query`, `results`, `answers`, `corrections`, `infoboxes`, `suggestions`, `unresponsive_engines`) is not on those pages. It comes from `get_json_response` in SearXNG's `searx/webutils.py` (read 2026-10-19). |
| `article.html`, `article.md` | Written for these tests, not taken from any documentation. `article.md` is the expected output of `HtmlMarkdown`, checked by hand against the HTML. |

## Where the docs and the code differ

- Tavily: the key used to go in the body as `api_key`, which the docs do not list (OpenAPI, read 2026-10-20). `TavilyBackend` now sends it only as `Authorization: Bearer <key>`, the scheme of the same OpenAPI document.
