# Pure PHP FTS Indexer

[![Preview in WordPress Playground](https://playground.wordpress.net/badge.svg)](https://playground.wordpress.net/?blueprint-url=https://raw.githubusercontent.com/adamziel/wp-extensions/main/indexer/playground/blueprint.json)

The Playground preview installs and activates the plugin from the `indexer/`
subdirectory and opens the logged-in Plugins screen. Playground runs on SQLite;
the production indexing workflow still depends on WordPress with MySQL and
WP-CLI for schema creation, reindexing, and search operations.

Pure PHP FTS Indexer is an experimental WordPress plugin that builds a custom
full-text index over WordPress posts. It can be managed through WP-CLI, updated
incrementally by WordPress post lifecycle hooks, queried through `wp fts search`,
and exposed through the plugin's REST/search helpers with WordPress visibility
checks.

The plugin stores its own MySQL tables. It does not use MySQL `FULLTEXT`, does
not replace WordPress core search automatically, and keeps the index as derived
data that can be rebuilt from WordPress content.

## Quickstart

Install the `indexer` directory as the plugin root. Do not install the whole
monorepo under `wp-content/plugins`, because WordPress will not discover
`indexer/indexer.php` from a nested monorepo checkout.

```sh
rsync -a --delete /path/to/wp-extensions/indexer/ /path/to/wordpress/wp-content/plugins/indexer/
cd /path/to/wordpress/wp-content/plugins/indexer
composer install --no-dev --optimize-autoloader
wp plugin activate indexer
```

Activation creates or repairs the `fts_*` tables and schedules the bounded
runtime queue processor. Run the first reindex to backfill existing posts:

```sh
wp fts reindex --post_type=post,page --post_status=publish --batch_size=200
```

The command reports the number of posts it processed:

```text
Success: Indexed 42 posts.
```

Run a search:

```sh
wp fts search "example query" --lang=en --limit=5
```

Search output is a table with WordPress post IDs, BM25 scores, totals, and
stored post metadata:

```text
+--------+--------------------+-------+---------+-----------+-------------+---------------------+---------------+
| doc_id | score              | total | post_id | post_type | post_status | post_date_gmt       | title         |
+--------+--------------------+-------+---------+-----------+-------------+---------------------+---------------+
| 123    | 1.742318907412998  | 2     | 123     | post      | publish     | 2026-06-07 00:00:00 | Example Post  |
| 98     | 0.9146134028443131 | 2     | 98      | page      | publish     | 2026-06-06 00:00:00 | Example Page  |
+--------+--------------------+-------+---------+-----------+-------------+---------------------+---------------+
```

`doc_id` is the WordPress post ID. `score` is a relative BM25 score for that
query and language partition; it is not a percentage and should not be compared
across unrelated queries. Use WordPress to inspect a result:

```sh
wp post get 123 --field=post_title
```

## Common Commands

```sh
# Index only published posts, using the site or multilingual plugin language.
wp fts reindex

# Index posts and pages in a forced language partition.
wp fts reindex --post_type=post,page --lang=pl-PL

# Limit a catch-up run while testing.
wp fts reindex --limit=100 --batch_size=25

# Require all query terms.
wp fts search "fast durable search" --mode=AND --limit=10 --lang=en

# Tombstone one document and later compact tombstones out of postings.
wp fts delete 123
wp fts optimize

# Filter by stored product metadata and include snippets.
wp fts search "fast durable search" --post_type=post,page --post_status=publish --snippet
```

## Documentation

- [Configuration](docs/configuration.md) covers languages, analyzers,
  stemmers, content extraction, and BM25 options.
- [Operations](docs/operations.md) covers schema creation, reindexing,
  optimization, backups, restores, and sizing notes.
- [Limitations](docs/limitations.md) lists current behavior that production
  operators need to account for.
- [Testing](docs/testing.md) documents the PHP, Snowball, BM25, and conditional
  integration test harnesses.
- [Release packaging](docs/release-packaging.md) describes what should ship in
  a plugin archive and how Composer dependencies are handled.
- [Snowball compliance](docs/snowball-compliance.md) explains the dedicated
  Snowball fixture harness.
- [Polish fixture pack](docs/polish-morfologik-fixture-pack.md) explains the
  opt-in Morfologik/PoliMorf-compatible lemmatizer contract slice.
- [Polish verified stemmer](docs/polish-verified-stemmer.md) explains the
  opt-in fixture-backed Polish stemmer slice and how it differs from
  dictionary lemmatization.

## Current Caveats

The current implementation is suitable for development and hardening work, not
for an unattended large production rollout. The plugin now registers activation,
deactivation, uninstall, post-save/status/delete, cron, REST, and WP-CLI hooks;
runtime saves queue bounded incremental indexing and tombstone invisible or
protected posts. Full and incremental post indexing use the same extractor path
for title, content, excerpt, rendered block deltas, terms, selected custom
fields, field boosts, and stored metadata. MySQL postings are row based to avoid
whole-blob term rewrites, and schema repair/version checks surface database
write failures.

Remaining caveats still matter: it does not replace core front-end search by
itself, there is no settings screen, custom field indexing must be configured,
shortcode rendering is opt-in, and live WordPress/MySQL behavior still needs
environment-specific validation before production rollout.
