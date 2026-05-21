# QA redo notes

Date: 2026-05-21

## Checks

- Rendered local gallery screenshot: /tmp/importer-v2-render/redone-index.png. The gallery is styled and shows multiple iframe proposals instead of raw WordPress admin list markup.
- HTTP check: local index returned 200 and contains 20 iframe previews, 20 proposal sections, and option-20.html.
- Rendered sample option screenshot: /tmp/importer-v2-render/redone-option-07.png.
- Interaction check through headless Chromium DevTools: option 07 feature click changed visible result text; clicking Review import advanced status to complete, count to 42 / 42, and result to Complete: 31 draft pages, 18 media items, 3 URL decisions saved.

## Notes

The public preview URL will show the updated gallery after this branch is pushed. In this environment the public HTTPS host needs certificate errors ignored for headless Chromium, so local HTTP rendering was used as the authoritative layout check before push.
