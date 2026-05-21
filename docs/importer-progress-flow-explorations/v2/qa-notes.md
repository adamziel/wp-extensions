# QA notes - v2 importer prototype

Date: 2026-05-21

Scope: focused QA rerun only. Implementation was not edited. This note recreates the result from the latest `qa2.raw` evidence for the v2 importer prototype.

## Evidence source

- Served `docs/importer/progress-flow-explorations/v2` locally.
- Exercised `index.html` in headless Chromium through the Chrome DevTools Protocol.
- Page under test: `http://127.0.0.1:8787/index.html`.
- Initial state: visible primary button `Review import`, status `Idle`, progress value `0`, and review result `Not run`.

## Rerun result

The focused rerun passed.

- Clicking the visible `Review import` primary button did not POST and did not navigate. The page stayed on `http://127.0.0.1:8787/index.html`, and captured browser events showed no `POST` requests, document requests, frame navigations, or same-document navigations from that click.
- The same visible primary button advanced the importer progress to complete. The status reached `complete`, and the progress meter reached `aria-valuenow="100"` with `width: 100%;`.
- The review result updated to `Complete`, with the summary showing `12 pages, 18 media, 0 warnings.`
- Feature tabs revealed their matching panels. `Detect`, `Map fields`, `Media`, `Conflicts`, and `Review` each selected the clicked tab and exposed the corresponding panel while hiding the others.

## Remaining limitation

URL decision controls were not part of this focused rerun.
