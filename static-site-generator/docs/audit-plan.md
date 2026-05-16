# Static Site Generator Audit Plan

This plan tracks the gaps found while testing richer WordPress exports,
especially Playground-scoped sites and WooCommerce demos.

## Completed during this audit

- Removed the exporter-level replacement-character repair for
  `You may be interested in...`.
- Confirmed the root cause was corrupted upstream BrewCommerce WXR content that
  already contained U+FFFD replacement characters before static export.
- Replaced the BrewCommerce demo's corrupted upstream WXR import with a cleaned
  WXR asset in this repository so the source content is valid before import.
- Added `_static-export-preview.txt` to exports so users understand when to
  use a local HTTP preview instead of `file://`.
- Moved the static generator Playground button into this repository.
- Added export warnings for static snapshots of dynamic behavior such as POST
  forms, search forms, WooCommerce cart-like pages, and REST API references.
- Added deterministic commerce artifact validation for representative exported
  directory and ZIP files, including shop, cart, product detail, product
  category, copied CSS, copied product image, distinct page content, shortcode
  absence, replacement-character absence, and file-preview-safe URLs.
- Added a WP-CLI `--output-dir` mode for direct directory exports that can be
  served over local HTTP without manually unzipping.

## Export correctness

The exporter should keep proving that every discovered page receives its own
rendered HTML rather than a reused homepage response. The current deterministic
commerce fixture mirrors the BrewCommerce page shapes and checks representative
directory and ZIP files:

- `index.html`
- `shop/index.html`
- `cart/index.html`
- at least one `product/.../index.html`
- at least one `product-category/.../index.html`

The validation asserts distinct headings, product content, copied product
images, copied WooCommerce CSS, no unresolved shortcodes, no U+FFFD characters,
and no root-relative links that break after extraction.

## Dynamic WordPress behavior

A static export cannot preserve server-side behavior such as carts, checkout,
search, comments, protected forms, REST API writes, or nonce-based actions. The
plugin should make that explicit.

Completion evidence:

- Export warnings are generated for POST forms, search forms, WooCommerce
  cart/checkout/account-like snapshots, and REST API references.
- README and Pages documentation explain that WooCommerce product listings and
  static snapshots can export, while live cart mutations, checkout, accounts,
  payments, forms, search, comments, and REST writes still need a backend or a
  static-compatible service.

## Preview and hosting

`file://` previews are useful for basic HTML and CSS, but browser module
scripts cannot run there. Keep improving the supported preview path:

Completion evidence:

- Exports include `_static-export-preview.txt`.
- README, plugin README, and Pages docs explain direct `file://` limits and the
  `python3 -m http.server 8080` local HTTP preview path.
- WP-CLI supports `--output-dir` for direct directory previews without
  manually unzipping a ZIP.

## CI coverage

The current PHP tests cover URL parsing, export internals, Blueprint shape, and
fixtures. The next coverage increase should use generated export artifacts,
not only static fixture strings.

Completion evidence:

- CI runs deterministic static exporter tests that mirror BrewCommerce page
  shapes and verify directory and ZIP artifacts.
- CI validates Blueprint JSON, Blueprint wiring, local Playground button
  assets, README links, the cleaned BrewCommerce WXR, and Pages documentation.
- A full Playground-driven BrewCommerce export is intentionally left out of CI:
  it depends on browser/Playground networking, third-party package downloads,
  and WooCommerce runtime cost. The deterministic fixture covers the static
  export contract without that external flake surface.

## Documentation and demos

The README is useful but not enough for a project-level docs surface. The Pages
site now includes:

- a quickstart for browser Playground
- a quickstart for Playground CLI
- a regular WordPress admin workflow
- a regular WordPress WP-CLI workflow
- a preview troubleshooting guide for `file://` versus local HTTP
- a WooCommerce/BrewCommerce demo walkthrough
- a limitations page for dynamic WordPress features

## Branding and GitHub Pages

The final project needs a name and visual identity that do not collide with
common static WordPress products. Branding work included live research before
choosing a name.

Completion evidence:

- `static-site-generator/docs/branding-research.md` records competitor checks,
  naming iterations, conflict notes, the selected name, and logo iterations.
- The selected name is **StillPress**.
- The selected SVG logo lives in this repository and is reused by GitHub Pages.
- The GitHub Pages site includes landing, get-started, examples, limitations,
  local logo assets, and the branded Playground launch button.
- `.github/workflows/deploy-pages.yml` publishes the `docs/` site for
  `adamziel/wp-extensions`.

## Optional future work

- Add more dynamic warning patterns as new real-world frontend behaviors are
  reported.
- Add a portable `serve-static-site.sh` helper only if it stays clearer than the
  documented `python3 -m http.server 8080` command.
- Reconsider a Playground-driven commerce export smoke test if it can be made
  fast, deterministic, and independent of third-party network volatility.
