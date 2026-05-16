# Branding Research

Research date: 2026-05-16.

## Selected Name

**StillPress** is the working name for this static export project.

Why it fits:

- It keeps the WordPress connection through "Press".
- "Still" suggests rendered, durable, no-database HTML without implying that
  WordPress itself disappears.
- It avoids the crowded "static WordPress" naming pattern used by existing
  products.
- It works as a user-facing phrase: "Make a StillPress export" or "Preview the
  StillPress build."

## Conflict Notes

Known WordPress static export products already occupy the obvious naming space:

- [Simply Static](https://wordpress.org/plugins/simply-static/) uses the direct
  "static site generator" positioning for WordPress.
- [Staatic](https://wordpress.org/plugins/staatic/) is another established
  static site generator plugin.
- [WP2Static](https://github.com/elementor/wp2static) is a known WordPress
  static generation project, even though its current status is different from
  active products.
- [Static Cache Wrangler](https://wordpress.org/plugins/static-cache-wrangler/)
  uses a "static cache/export engine" positioning.
- [Freeze](https://wordpress.org/plugins/freeze/) already occupies the
  "freeze WordPress" metaphor in the plugin directory.

## Naming Iterations

The naming pass avoided direct "static WordPress" phrasing because that space is
already crowded and harder to own.

| Candidate | Decision | Notes |
| --- | --- | --- |
| StaticPress | Rejected | Too generic and too close to existing "static" WordPress product language. |
| PressFreeze | Rejected | Conflicts with the "Freeze" metaphor already used by a WordPress plugin. |
| FlatPress Export | Rejected | Too close to FlatPress, an existing flat-file blog/CMS name. |
| SnapshotPress | Rejected | "Snapshot" overlaps heavily with backup, restore, and staging products. |
| StillPress | Selected direction | Clear WordPress signal, static-state metaphor, and less direct conflict with existing static export product names. |

Adjacent names to avoid:

- **SnapshotPress** or **WP Snapshot** because "snapshot" is heavily associated
  with WordPress backup and restore products.
- **Pressbox** because it is already used by WordPress hosting and plugin
  products.
- **FlatPress** because it is an existing flat-file blog/CMS name.
- **Flatpack** because it appears in WordPress themes/plugins and is too close
  to Flatpak/packaging terminology.

## Visual Direction

StillPress should feel practical and export-focused, not like a generic hosting
or security product. The initial logo combines:

- a document/page shape for exported files
- a small press mark for the WordPress publishing origin
- a still/paused line motif for the static result

The first SVG lives at `static-site-generator/assets/stillpress-logo.svg`.

## Logo Iterations

The logo pass stayed in SVG so the repo owns the mark and the same asset can be
used in README files, GitHub Pages, and future plugin screens.

| Iteration | Direction | Result |
| --- | --- | --- |
| 1 | Browser window plus arrow | Rejected because it read like generic deployment or hosting. |
| 2 | WordPress-style circular badge | Rejected because it leaned too close to WordPress core identity. |
| 3 | Document sheet with press lines | Selected direction because it suggests rendered files, publishing origin, and a calm static result. |

The selected direction is published in:

- `static-site-generator/assets/stillpress-logo.svg`
- `docs/assets/stillpress-logo.svg`
