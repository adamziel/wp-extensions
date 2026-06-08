# Language FTS Playground

[![Try it in WordPress Playground](https://playground.wordpress.net/badge.svg)](https://playground.wordpress.net/?blueprint-url=https://raw.githubusercontent.com/adamziel/wp-extensions/main/language-fts-playground/playground/blueprint.json)

Language FTS Playground is a small WordPress plugin that demonstrates
language-partitioned full-text search in WordPress Playground. It works with
Playground's SQLite-backed database because the database stores only simple
document and posting rows; extraction, language normalization, field-aware
indexing, phrase filtering, fuzzy term expansion, snippets, highlighting, and
BM25-style ranking all run in PHP.

The plugin does not use MySQL `FULLTEXT`, SQLite FTS tables, SQL `MATCH`, or
database-native ranking.

## Demo

Install and activate the `language-fts-playground/` directory as a WordPress
plugin, then open `Tools -> Language FTS`. Activation seeds three published demo
posts and rebuilds the index.

Search results show the score, matched fields, matched normalized terms, and a
safe snippet with matched source terms highlighted with `<mark>`. Snippet text
is escaped before marks are inserted, so visible post text that looks like HTML
is not emitted as raw markup.

The seeded posts cover:

- English visible text: search `orchard` in English.
- English excerpt text: search `summary` in English.
- English demo inflection keys: search `search` in English for visible `searching`, `searched`, and `searches`; search `story` for image alt text containing `stories`.
- English phrase search: search `"search pages"` in English to require adjacent analyzed positions.
- English fuzzy typo tolerance: search `orchrd~` in English to opt into one-edit fuzzy matching for `orchard`.
- English image alt text: search `falconalt` in English.
- Markup/CSS/script/comment noise: search `ghostmarkup` in English and expect no matches.
- Polish folding: search `lodz` in Polish for content containing `Łódź`.
- Polish inflection keys: search `polska` or `partycja` in Polish for `polskiej partycji`.
- German folding: search `fuehrung` in German for content containing `Führung`.
- German demo inflection keys: search `deutsch` for `deutschen` or `deutscher`, and `suche` for `Suchen`.

The language selector searches only the selected language partition by default.

## Field-Aware Ranking And Snippets

The index stores normalized source text and term frequencies separately for
`title`, `excerpt`, `content`, and `alt` fields. Query scoring uses these
default field boosts:

- `title`: `4.0`
- `excerpt`: `2.0`
- `content`: `2.0`
- `alt`: `1.0`

Those defaults make title hits rank above equal body hits while keeping image
alt text searchable with a lower boost. Snippet highlighting compares query
analysis keys with each source token's analysis keys, so demo inflection keys
can highlight raw source forms such as `stories` for the query `story`.

## Supported Analyzer Behavior

The analyzer removes common English, Polish, and German stopwords, keeps exact
terms, and adds a small set of language-scoped conservative stem keys:

- English lowercases terms and adds conservative keys for regular forms and a few guarded irregulars: `search` matches `searching`, `searched`, and `searches`; `story` matches `stories`; `make` matches `making`; `run` matches `running`; `child` matches `children`.
- English keeps sensitive terms such as `news`, `bus`, and `analysis` exact, and avoids broad noun-to-verb collapses such as `runner` to `run`.
- Polish folds diacritics and adds the existing conservative long-word suffix keys, including `polska`/`polskiej`, `partycja`/`partycji`, `wyszukiwanie`/`wyszukiwania`, and `lodz`/`Łódź`.
- German folds `ä`, `ö`, `ü`, and `ß` to `ae`, `oe`, `ue`, and `ss`, then adds conservative keys for demo forms such as `deutsch`/`deutschen`/`deutscher`/`deutsche`, `fuehrung`/`Führungen`, `strasse`/`Straßen`, `baum`/`Bäume`, and `spiel`/`gespielt`.
- Short/common terms stay exact in every language to reduce noisy matches.

## Phrase And Fuzzy Search

Quoted phrases are parsed into analyzed token positions and must match adjacent
ordered positions in the indexed document. Positions are document-level, so a
phrase can match inside visible content or image alt text, but skipped
script/style/comment/template markup creates a gap and does not become phrase
content.

One-edit typo tolerance is opt-in with a trailing `~`, such as `orchrd~`.
Fuzzy matching is disabled for short terms, uses only same-language indexed
candidate terms within one edit, and ranks exact matches ahead of fuzzy-only
matches.

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
php -n language-fts-playground/tests/run.php
php -r "json_decode(file_get_contents('language-fts-playground/playground/blueprint.json')); if (json_last_error()) { fwrite(STDERR, json_last_error_msg() . PHP_EOL); exit(1); }"
find language-fts-playground -name "*.php" -print0 | xargs -0 -n1 php -l
```

## Limitations

This is a demo-sized implementation. The custom tables are intentionally simple
and portable, with no production indexing optimizations. Ranking is meant for
relative ordering inside one query and one language partition, not for comparing
scores across languages or unrelated queries. The analyzer folds only the
language behavior needed for the demo, including conservative English, Polish,
and German stem-key generators. It does not implement full stemming,
lemmatization, synonyms, multi-edit fuzzy search, or multilingual fallback.
Snippets are built from normalized field text, not from full-fidelity rendered
HTML, and use a small fixed excerpt window for long fields.
