# Language FTS Playground

[![Try it in WordPress Playground](https://playground.wordpress.net/badge.svg)](https://playground.wordpress.net/?blueprint-url=https://raw.githubusercontent.com/adamziel/wp-extensions/main/language-fts-playground/playground/blueprint.json)

Language FTS Playground is a small WordPress plugin that demonstrates
language-partitioned full-text search in WordPress Playground. It works with
Playground's SQLite-backed database because the database stores only simple
document and posting rows; extraction, language normalization, indexing, and
BM25-style ranking all run in PHP.

The plugin does not use MySQL `FULLTEXT`, SQLite FTS tables, SQL `MATCH`, or
database-native ranking.

## Demo

Install and activate the `language-fts-playground/` directory as a WordPress
plugin, then open `Tools -> Language FTS`. Activation seeds three published demo
posts and rebuilds the index.

The seeded posts cover:

- English visible text: search `orchard` in English.
- English image alt text: search `falconalt` in English.
- Markup/CSS/script/comment noise: search `ghostmarkup` in English and expect no matches.
- Polish folding: search `lodz` in Polish for content containing `Łódź`.
- Polish inflection keys: search `polska` or `partycja` in Polish for `polskiej partycji`.
- German folding: search `fuer` or `fuehrung` in German for content containing `für` and `Führung`.

The language selector searches only the selected language partition by default.

## Playground Blueprint

Open the browser demo:

```text
https://playground.wordpress.net/?blueprint-url=https://raw.githubusercontent.com/adamziel/wp-extensions/main/language-fts-playground/playground/blueprint.json
```

The Blueprint installs this monorepo subdirectory with `installPlugin` using
`git:directory`, activates the plugin, seeds demo posts, rebuilds the PHP index,
and lands on the admin search page.

## Development Checks

```sh
php language-fts-playground/tests/run.php
php -r "json_decode(file_get_contents('language-fts-playground/playground/blueprint.json')); if (json_last_error()) { fwrite(STDERR, json_last_error_msg() . PHP_EOL); exit(1); }"
find language-fts-playground -name "*.php" -print0 | xargs -0 -n1 php -l
```

## Limitations

This is a demo-sized implementation. The custom tables are intentionally simple
and portable, with no production indexing optimizations. Ranking is meant for
relative ordering inside one query and one language partition, not for comparing
scores across languages or unrelated queries. The analyzer folds only the
language behavior needed for the demo, including a conservative Polish suffix
normalizer, and does not implement full stemming, synonyms, phrase search, typo
tolerance, or multilingual fallback.
