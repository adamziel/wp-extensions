# Pure PHP FTS Indexer

Pure PHP FTS Indexer is an experimental WordPress plugin that builds a custom
full-text index over WordPress posts and searches it through WP-CLI. The current
operational surface is command-line driven: activate the plugin, run a reindex,
then query the index with `wp fts search`.

The plugin stores its own MySQL tables. It does not use MySQL `FULLTEXT`, does
not replace WordPress core search automatically, and does not yet register
automatic post-save hooks.

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

Run the first reindex. This creates or upgrades the `fts_*` tables before it
indexes matching posts.

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

Search output is a table with WordPress post IDs and BM25 scores:

```text
+--------+--------------------+
| doc_id | score              |
+--------+--------------------+
| 123    | 1.742318907412998  |
| 98     | 0.9146134028443131 |
+--------+--------------------+
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

## Current Caveats

The current implementation is suitable for development and hardening work, not
for an unattended large production rollout. Posting lists are stored as whole
binary blobs per term, concurrent index writes can overwrite each other, schema
creation is lazy, and WordPress runtime integration is limited to WP-CLI.
