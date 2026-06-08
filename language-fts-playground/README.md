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

- Automatic language routing: search `orchard`, `lodz`, `fuehrung`, or `szukanie` with `Automatic` selected and the searcher checks every enabled language partition.
- English visible text: search `orchard` in English.
- English excerpt text: search `summary` in English.
- English demo inflection keys: search `search` in English for visible `searching`, `searched`, and `searches`; search `story` for image alt text containing `stories`.
- English phrase search: search `"search pages"` in English to require adjacent analyzed positions.
- English fuzzy typo tolerance: search `orchrd~` in English to opt into one-edit fuzzy matching for `orchard`.
- English image alt text: search `falconalt` in English.
- Markup/CSS/script/comment noise: search `ghostmarkup` in English and expect no matches.
- Polish folding: search `lodz` in Polish for content containing `Łódź`.
- Polish inflection keys: search `polska` or `partycja` in Polish for `polskiej partycji`.
- Polish query-time synonyms: search `szukaj`, `szukanie`, or `wyszukiwarka` in Automatic or Polish mode to match indexed content containing `wyszukiwania`.
- German folding: search `fuehrung` in German for content containing `Führung`.
- German demo inflection keys: search `deutsch` for `deutschen` or `deutscher`, and `suche` for `Suchen`.

The language selector defaults to `Automatic`, which searches all enabled
language partitions and reports the partition that matched. Choosing English,
Polish, or German keeps the previous precision-filter behavior and searches
only that partition.

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

Synonym expansion is also query-time analyzer behavior. The demo ships curated
Polish resource rows that map command/search noun keys such as `szukac` and
`wyszukiwarka` to the indexed `wyszukiwac` key used by forms such as
`wyszukiwania`; synonym-only matches are downweighted, stay in the same
language partition, and highlight the matched source token normally.

## Lexical Profiles

The analyzer loads language behavior from plugin-local resources under
`language-fts-playground/resources/languages/`:

```text
resources/languages/
  en/
    profile.php
    stopwords.txt
    lexemes.tsv
    synonyms.tsv
  pl/
    profile.php
    stopwords.txt
    lexemes.tsv
    synonyms.tsv
  de/
    profile.php
    stopwords.txt
    lexemes.tsv
    synonyms.tsv
```

`profile.php` declares the language id, label, load order, optional character
folds, optional language-detection signal regexes, and resource file names.
`stopwords.txt` stores one normalized stopword per line. `lexemes.tsv` maps
observed normalized forms to canonical keys, for example Polish `szukaj` to
`szukac` and `wyszukiwania` to `wyszukiwac`. `synonyms.tsv` maps canonical query
keys to canonical index keys with a weight and provenance. The parser validates
row shapes and malformed resources fail during profile loading.

Profiles are parsed lazily and cached on the analyzer's profile repository.
Stopwords, lexeme aliases, and synonym expansions are stored as keyed maps, so
the analyzer does not scan resource files while analyzing each token.

## Supported Analyzer Behavior

The analyzer removes profile-backed English, Polish, and German stopwords,
keeps exact terms, applies profile-backed lexeme aliases first, and then adds a
small set of language-scoped conservative fallback stem keys:

- English lowercases terms and includes resource rows plus conservative keys for regular forms and a few guarded irregulars: `search` matches `searching`, `searched`, and `searches`; `story` matches `stories`; `make` matches `making`; `run` matches `running`; `child` matches `children`.
- English keeps sensitive terms such as `news`, `bus`, and `analysis` exact, and avoids broad noun-to-verb collapses such as `runner` to `run`.
- Polish folds diacritics from profile data, uses curated resource keys for `szukaj`/`szukanie`/`wyszukiwarka`/`wyszukiwanie`/`wyszukiwania`, and keeps fallback suffix keys for forms such as `polska`/`polskiej`, `partycja`/`partycji`, and `lodz`/`Łódź`.
- German folds `ä`, `ö`, `ü`, and `ß` from profile data to `ae`, `oe`, `ue`, and `ss`, then uses resource rows and fallback keys for demo forms such as `deutsch`/`deutschen`/`deutscher`/`deutsche`, `fuehrung`/`Führungen`, `strasse`/`Straßen`, `baum`/`Bäume`, and `spiel`/`gespielt`.
- Short/common terms stay exact in every language to reduce noisy matches.

## Phrase And Fuzzy Search

Quoted phrases are parsed into analyzed token positions and must match adjacent
ordered positions in the indexed document. Positions are document-level, so a
phrase can match inside visible content or image alt text, but skipped
script/style/comment/template markup creates a gap and does not become phrase
content.

One-edit typo tolerance is opt-in with a trailing `~`, such as `orchrd~`.
Fuzzy matching is disabled for short terms, uses only same-language indexed
candidate terms within one edit, and ranks exact matches ahead of fuzzy-only or
synonym-only matches.

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
scores across languages or unrelated queries. The lexical resources are curated
for the demo and are not full dictionaries; they are intended to be expanded or
replaced later by generated resources from canonical linguistic sources. The
fallback suffix rules are still conservative handwritten heuristics. The plugin
does not implement full stemming, full lemmatization, multi-edit fuzzy search,
or unconfigured cross-language fallback.
Snippets are built from normalized field text, not from full-fidelity rendered
HTML, and use a small fixed excerpt window for long fields.
