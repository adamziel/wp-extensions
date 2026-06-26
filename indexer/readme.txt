=== Language FTS ===
Contributors: adamziel
Tags: search, full-text-search, multilingual, wp-cli
Requires at least: 6.5
Tested up to: 7.0
Requires PHP: 8.1
Stable tag: 0.1.11
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Local full-text search for WordPress with multilingual analysis, WP-CLI tools, and operator diagnostics.

== Description ==

Language FTS adds a local full-text search index for WordPress content. It indexes posts into WordPress database tables, supports language-aware analysis where configured, and provides admin, WP-CLI, REST/search, and diagnostic tools for understanding search behavior.

Use it when you want a self-hosted search layer you can inspect and operate from WordPress. The plugin includes tools for indexing, queue processing, analyzer-pack status, search-provider advisory output, snippets, highlighting, and bounded search diagnostics.

Language behavior depends on the active site language, configured analyzers, available analyzer packs, and fallback rules. Some languages may use baseline or fallback analysis unless a compatible pack is available and enabled.

Evaluate Language FTS on staging or a disposable copy of your site before using it for visitors. Keep backups, a rollback path, and monitoring in place. Production suitability depends on the site, host, database, cron setup, cache behavior, content volume, traffic, and active theme/plugin mix.

Provider-related output is advisory. This plugin does not claim provider/version certification for other search plugins or hosted search services.

Support and feedback are best effort. Safety issues, install or activation failures, data or index corruption risks, fatal errors, security reports, and reproducible bugs are the highest-priority reports.

== Installation ==

1. Install the approved Language FTS plugin package through the WordPress admin plugin screen, or copy the `language-fts` plugin folder to `wp-content/plugins/language-fts`.
2. Activate **Language FTS** from the Plugins screen.
3. Open **Settings > Full-Text Search** and review Health, Settings, Sandbox, Indexed content, and Analyzer packs.
4. Run an initial reindex from WP-CLI, for example: `wp fts reindex --post_type=post --batch_size=200`.
5. Test search results with your own content before enabling search replacement for visitors.
6. Keep backups and a rollback plan, and monitor indexing, queues, cron, database load, and search quality.

== Frequently Asked Questions ==

= Does Language FTS replace WordPress search? =

It can replace eligible front-end and wp-admin post search queries when the related settings are enabled. Test on staging first, especially if another plugin or theme also changes search behavior.

= Does it support multilingual sites? =

It supports language-aware indexing and search where the configured analyzers and packs can handle the language. The admin status screens and `wp fts status --format=json` show the active language-pack and fallback state for the site.

= Does it connect to external services? =

By default, indexing and search run locally. An administrator can choose to fetch optional analyzer language packs from GitHub Releases. That request downloads a signed manifest and ZIP bundle from `github.com`, verifies the Ed25519 signature, SHA-256 hash, declared size, and ZIP contents, then extracts approved data files into the site uploads directory. These optional packs are not hosted, reviewed, or endorsed by WordPress.org.

= What happens on uninstall? =

Uninstall removes the plugin options and scheduled events. The derived full-text index tables are retained so an accidental uninstall does not immediately delete search data. Remove the `fts_*` tables manually if you want to discard the derived index completely.

= Does it certify compatibility with other search plugins or hosted search services? =

No. Language FTS includes advisory diagnostics for search-provider interactions, but provider/version certification requires separate scoped testing and accepted evidence.

= Should I enable it directly on a live site? =

Start with staging or a disposable copy. Confirm backups, rollback, cron behavior, database load, search quality, and interactions with your active theme and plugins before using it for visitor-facing search.

= What should I include in feedback reports? =

Include WordPress, PHP, and database versions; active theme and relevant plugins; exact steps to reproduce; relevant status or diagnostic output; and whether the issue reproduces on staging.

== Changelog ==

= 0.1.11 =

Refines the Dashboard language-pack view so automatic mode lists chosen packs without showing manual pack checkboxes, lets automatic mode use post language metadata and multilingual plugin language signals, and updates the Playground preview with multilingual demo content.

= 0.1.10 =

Adds the settings Dashboard as the default admin tab and verifies optional GitHub language-pack downloads with a signed manifest, SHA-256 hash, size checks, and bounded extraction.

= 0.1.9 =

Initial public readme for the Language FTS direct-install package posture. Adds WordPress.org-style metadata and keeps public claims bounded to local full-text indexing, multilingual analysis, WP-CLI tools, and operator diagnostics.
