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
- Polish concept synonyms: search `szukaj`, `szukanie`, `wyszukiwarka`, or `odnajdywanie` in Automatic or Polish mode to match indexed content containing `wyszukiwania`.
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

Synonym expansion is also query-time analyzer behavior. The demo ships a small
curated Polish concept pack that groups canonical keys such as `szukac`,
`wyszukiwac`, `wyszukiwarka`, and `odnajdywac`. Query expansion derives the
other keys from that concept map, so forms such as `szukaj`, `szukanie`,
`wyszukiwarka`, and `odnajdywanie` can match indexed `wyszukiwania`.
Synonym-only matches are downweighted, stay in the same language partition, and
highlight the matched source token normally.

## Lexical Profiles

The analyzer loads language behavior from plugin-local resources under
`language-fts-playground/resources/languages/`:

```text
resources/languages/
  en/
    profile.php
    pack.php
    stopwords.txt
    lexemes.tsv
    synonyms.tsv
  pl/
    profile.php
    pack.php
    stopwords.txt
    lexemes.tsv
    synonyms.tsv
    synsets.tsv
  de/
    profile.php
    pack.php
    stopwords.txt
    lexemes.tsv
    synonyms.tsv
```

`profile.php` declares the language id, label, load order, optional character
folds, optional language-detection signal regexes, and resource file names.
`pack.php` records source, license, attribution, provenance, generated file
list, pack version/date, and whether the resource pack is curated seed data or
an imported comprehensive pack. The analyzer does not load `pack.php` during
normal query analysis.
`stopwords.txt` stores one normalized stopword per line. `lexemes.tsv` maps
observed normalized forms to canonical keys, for example Polish `szukaj` to
`szukac`, `wyszukiwania` to `wyszukiwac`, and `odnajdywanie` to `odnajdywac`.
`synsets.tsv` groups canonical keys by concept with a weight and provenance,
without enumerating every pair in the file. `synonyms.tsv` remains the pairwise
override/escape hatch for asymmetric fixes or targeted compatibility rows. The
parser validates row shapes and malformed resources fail during profile
loading.

Profiles are parsed lazily and cached on the analyzer's profile repository.
Stopwords, lexeme aliases, and concept-derived synonym expansions are stored as
keyed maps, so the analyzer does not scan resource files while analyzing each
token or expanding each query.

The included resources are a curated demo seed, not a comprehensive synonym
database. To add demo synonyms without editing PHP, add normalized observed
forms to `lexemes.tsv`, add canonical keys to a `synsets.tsv` concept row, and
declare the optional `synsets` resource in `profile.php` if that language does
not already have one. Use `synonyms.tsv` only when an explicit pair should
override or supplement concept-derived expansions.

For build-time imports, use the PHP-only importer:

```sh
php language-fts-playground/tools/import-lexical-source.php \
  <format> <input> <output-dir> \
  --language=<id> \
  --source-name=<name> \
  --source-url=<url> \
  --license-name=<name> \
  --attribution=<text> \
  --pack-version=<version> \
  --pack-date=<YYYY-MM-DD> \
  --provenance=<provenance-id>
```

Supported importer formats are `membership-tsv`, `wordnet-membership-tsv`,
`openthesaurus-text`, and `wordnet-json`. The importer writes compact runtime
`synsets.tsv`, generated `pack.php` metadata, and `lexemes.tsv` when source
rows include observed/canonical forms. Runtime search remains pure PHP and fast
because the plugin reads only compact local resource files, not source database
formats.

See `docs/lexical-resources.md` for the resource contract and
`tools/import-lexical-source.php` for source import usage. Open English WordNet
is CC-BY 4.0 according to its GitHub README, OpenThesaurus German publishes
downloads under CC BY-SA 4.0 or LGPL, and plWordNet license information from
CLARIN allows use, copying, modification, and distribution subject to preserving
copyright and disclaimer notices. Those sources are not bundled here. Current
shipped resources remain seed data unless a generated comprehensive pack is
committed after source-specific normalization, license review, attribution
review, pack-size review, and quality testing.

## Supported Analyzer Behavior

The analyzer removes profile-backed English, Polish, and German stopwords,
keeps exact terms, applies profile-backed lexeme aliases first, and then adds a
small set of language-scoped conservative fallback stem keys:

- English lowercases terms and includes resource rows plus conservative keys for regular forms and a few guarded irregulars: `search` matches `searching`, `searched`, and `searches`; `story` matches `stories`; `make` matches `making`; `run` matches `running`; `child` matches `children`.
- English keeps sensitive terms such as `news`, `bus`, and `analysis` exact, and avoids broad noun-to-verb collapses such as `runner` to `run`.
- Polish folds diacritics from profile data, uses curated resource keys and the demo concept pack for `szukaj`/`szukanie`/`wyszukiwarka`/`wyszukiwanie`/`wyszukiwania`/`odnajdywanie`, and keeps fallback suffix keys for forms such as `polska`/`polskiej`, `partycja`/`partycji`, and `lodz`/`Łódź`.
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
scores across languages or unrelated queries. The lexical resources and concept
pack are curated for the demo and are not full dictionaries or a shipped
WordNet/plWordNet database; they are intended to be expanded or replaced later
by generated resources from licensed linguistic sources. The fallback suffix
rules are still conservative handwritten heuristics. The plugin does not
implement full stemming, full lemmatization, multi-edit fuzzy search, or
unconfigured cross-language fallback.
Snippets are built from normalized field text, not from full-fidelity rendered
HTML, and use a small fixed excerpt window for long fields.
