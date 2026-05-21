# QA notes - v2 importer prototype

Date: 2026-05-21

Scope: QA follow-up only. Implementation was not edited. I served `docs/importer/progress-flow-explorations/v2` locally and exercised `index.html` in headless Chromium through the Chrome DevTools Protocol.

## Environment and evidence

- Local server: `python3 -m http.server 8787 --bind 127.0.0.1`
- Browser: `/run/current-system/sw/bin/chromium --headless=new --remote-debugging-port=9223`
- Page: `http://127.0.0.1:8787/index.html`
- Initial load: title `Universal Importer - WordPress Admin Prototype`, `h1` `Universal Importer`, visible primary button `Review import`, status `Idle`, progress `aria-valuenow="0"`, review state `Not run`.
- Served assets observed: `index.html`, `styles.css`, `scenarios.js`, and `app.js` loaded with `200 OK`; `favicon.ico` returned `404`, which did not affect the prototype checks.

## Follow-up checks

All requested follow-up checks passed.

1. Clicking `Review import` no longer POSTs or navigates.

   Evidence: after setting `#source-input` to a GitHub docs source and clicking the visible `#import-button`, `location.href` remained `http://127.0.0.1:8787/index.html`, the page title and `h1` remained unchanged, and the status moved to `queued`. The captured browser network events for the click contained no `POST` requests, no document requests, no frame navigations, and no same-document navigations.

2. Feature tab clicks reveal the matching panels.

   Evidence: clicking `Detect`, `Map fields`, `Media`, `Conflicts`, and `Review` set the clicked tab to `aria-selected="true"` and revealed only the matching panel: `feature-panel-detect`, `feature-panel-map`, `feature-panel-media`, `feature-panel-conflicts`, and `feature-panel-review`. Each matching panel had `hidden === false`, `aria-hidden="false"`, and `is-active`.

3. Progress reaches complete from the visible primary button.

   Evidence: the same visible `Review import` click advanced status from `queued` through the progress sequence to `complete`. At completion, `#progress-meter` had `aria-valuenow="100"` and inline style `width: 100%;`; the activity log ended with `importing media`, `writing pages`, and `complete`.

4. Review result updates.

   Evidence: after completion, `#review-result-state` changed to `Complete` and `#review-result-summary` changed to `12 pages, 18 media, 0 warnings.`

## Summary

The previous blockers for the requested path are resolved in this follow-up run: the primary button no longer submits the form, feature tabs now switch visible content, the visible primary path completes progress, and the review result updates after completion.
