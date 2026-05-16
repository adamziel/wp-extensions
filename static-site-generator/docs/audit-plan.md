# Static Site Generator Audit Plan

This plan tracks the gaps found while testing richer WordPress exports,
especially Playground-scoped sites and WooCommerce demos.

## Completed during this audit

- Removed the exporter-level replacement-character repair for
  `You may be interested in...`.
- Confirmed the root cause was corrupted upstream BrewCommerce WXR content that
  already contained U+FFFD replacement characters before static export.
- Moved the BrewCommerce punctuation cleanup into the demo Blueprint import
  preparation step.
- Added `_static-export-preview.txt` to exports so users understand when to
  use a local HTTP preview instead of `file://`.
- Moved the static generator Playground button into this repository.
- Added export warnings for static snapshots of dynamic behavior such as POST
  forms, search forms, WooCommerce cart-like pages, and REST API references.
- Added deterministic commerce artifact validation for representative exported
  directory and ZIP files, including shop, cart, product detail, product
  category, copied CSS, copied product image, distinct page content, shortcode
  absence, replacement-character absence, and file-preview-safe URLs.

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

Planned improvements:

- Expand dynamic warnings as more real-world frontend patterns appear.
- Document which WooCommerce pages are exported as rendered snapshots and which
  interactions still need a live WordPress backend.

## Preview and hosting

`file://` previews are useful for basic HTML and CSS, but browser module
scripts cannot run there. Keep improving the supported preview path:

- Add an optional WP-CLI directory export mode for easier `python3 -m
  http.server 8080` previews without manually unzipping.
- Add testing instructions that exercise both direct file inspection and local
  HTTP previews.
- Consider a generated `serve-static-site.sh` helper only if it stays portable
  and does not obscure the plain command.

## CI coverage

The current PHP tests cover URL parsing, export internals, Blueprint shape, and
fixtures. The next coverage increase should use generated export artifacts,
not only static fixture strings.

Planned checks:

- Build a Playground-driven BrewCommerce export in CI if runtime cost is
  acceptable.
- Keep the deterministic PHP fixture that mirrors BrewCommerce page shapes and
  verifies ZIP contents.
- Validate the local Playground button SVG and README links.
- Add a docs/pages build check once GitHub Pages content lands.

## Documentation and demos

The README is useful but not enough for a project-level docs surface. The Pages
site should include:

- a quickstart for browser Playground
- a quickstart for Playground CLI
- a regular WordPress admin workflow
- a regular WordPress WP-CLI workflow
- a preview troubleshooting guide for `file://` versus local HTTP
- a WooCommerce/BrewCommerce demo walkthrough
- a limitations page for dynamic WordPress features

## Branding and GitHub Pages

The final project needs a name and visual identity that do not collide with
common static WordPress products. Branding work must include live research
before choosing a name.

Planned output:

- a short naming shortlist with conflict notes
- a chosen name
- an SVG logo owned by this repository
- a GitHub Pages landing page with demos, docs, and the Playground launch
  button
- a Pages deployment workflow for `adamziel/wp-extensions`
