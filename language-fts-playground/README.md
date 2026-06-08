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
- English demo inflection keys: search `search` in English for visible `searching`, `searched`, and `searches`; search `story` for image alt text containing `stories`.
- English image alt text: search `falconalt` in English.
- Markup/CSS/script/comment noise: search `ghostmarkup` in English and expect no matches.
- Polish folding: search `lodz` in Polish for content containing `Łódź`.
- Polish inflection keys: search `polska` or `partycja` in Polish for `polskiej partycji`.
- German folding: search `fuer` or `fuehrung` in German for content containing `für` and `Führung`.
- German demo inflection keys: search `deutsch` for `deutschen` or `deutscher`, and `suche` for `Suchen`.

The language selector searches only the selected language partition by default.

## Supported Analyzer Behavior

The analyzer keeps exact terms and adds a small set of language-scoped demo keys:

- English lowercases terms and adds conservative keys for long regular forms: `search` matches `searching`, `searched`, and `searches`; `story` matches `stories`; `open` matches `opening` and `opened`.
- English does not trim plain trailing `s`, so sensitive terms such as `news`, `bus`, and `analysis` remain exact. Doubled-consonant forms such as `run`/`running`/`runner` also remain exact because the demo does not ship a stem lexicon.
- Polish folds diacritics and adds the existing conservative long-word suffix keys, including `polska`/`polskiej`, `partycja`/`partycji`, `wyszukiwanie`/`wyszukiwania`, and `lodz`/`Łódź`.
- German folds `ä`, `ö`, `ü`, and `ß` to `ae`, `oe`, `ue`, and `ss`, then adds conservative keys for long demo forms such as `deutsch`/`deutschen`/`deutscher`/`deutsche`, `fuehrung`/`Führungen`, and `suche`/`suchen`.
- Short/common terms stay exact in every language to reduce noisy matches.

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
and German suffix-key generators. It does not implement full stemming,
lemmatization, synonyms, phrase search, typo tolerance, or multilingual fallback.
