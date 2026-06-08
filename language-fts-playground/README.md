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
- English stem keys: search `search` for `searching`, `searched`, and `searches`; search `make`, `run`, or `child` for visible `making`, `running`, and `children`.
- English image alt stem keys: search `story` for image alt text containing `stories`.
- English image alt text: search `falconalt` in English.
- Markup/CSS/script/comment noise: search `ghostmarkup` in English and expect no matches.
- Polish folding: search `lodz` in Polish for content containing `Łódź`.
- Polish stem keys: search `polska`, `dom`, or `zielony` in Polish for inflected forms in the Polish demo post.
- German folding: search `strasse` or `fuehrung` in German for content containing `Straßen` and `Führung`.
- German stem keys: search `deutsch`, `schnell`, `baum`, or `spiel` in German for inflected forms in the German demo post.

The language selector searches only the selected language partition by default.

## Supported Analyzer Behavior

Indexing and searching use the same analyzer path. Documents call
`analyze_text()` and queries call `analyze_query()`, which runs the same
normalization and then de-duplicates keys for lookup. Each indexed token keeps
its exact normalized key unless it is a stopword, and may also add
language-scoped stem keys.

English:

- Lowercases terms and removes stopwords:
  `a`, `an`, `and`, `are`, `as`, `at`, `be`, `been`, `being`, `but`, `by`,
  `for`, `from`, `has`, `have`, `had`, `he`, `her`, `his`, `i`, `in`, `is`,
  `it`, `its`, `of`, `on`, `or`, `our`, `s`, `she`, `that`, `the`, `their`,
  `them`, `this`, `to`, `was`, `we`, `were`, `with`, `you`, `your`.
- Adds guarded stem keys for `ies` -> `y`, `ves` -> `f`/`fe`, sibilant
  `es`, guarded plain trailing `s`, `ing`, `ed`, `ied`, and `eed`.
- Handles doubled-consonant verb forms such as `running` -> `run` and
  dropped-e forms such as `making` -> `make`.
- Adds a small irregular plural map: `children` -> `child`, `people` ->
  `person`, `men` -> `man`, `women` -> `woman`, `mice` -> `mouse`, `geese` ->
  `goose`, `feet` -> `foot`, and `teeth` -> `tooth`.
- Keeps sensitive exact terms such as `news`, `bus`, `analysis`, `series`,
  `species`, and `basis` from plain trailing-s stemming. It also keeps
  `runner` separate from `run` and `university` separate from `universe`.

Polish:

- Folds `ą ć ę ł ń ó ś ź ż` to `a c e l n o s z z` and removes stopwords:
  `a`, `aby`, `ale`, `bo`, `byc`, `byl`, `byla`, `bylo`, `czy`, `dla`, `do`,
  `i`, `ich`, `jak`, `jest`, `ma`, `na`, `nie`, `o`, `od`, `oraz`, `po`,
  `pod`, `przez`, `sie`, `ta`, `ten`, `to`, `w`, `we`, `z`, `za`, `ze`.
- Adds conservative case/adjective stem keys for endings such as `ami`, `ach`,
  `ego`, `emu`, `iej`, `iego`, `ymi`, `imi`, `ych`, `ich`, `owi`, `iem`,
  `om`, `ow`, `em`, `ia`, `ie`, and `iu`.
- Allows three-letter stems for multi-letter case endings, so `domami`,
  `domach`, and `domem` can match `dom`.
- Trims final `a`, `i`, `e`, `y`, `u`, and `o` only when the resulting stem is
  at least five characters, so short forms such as `rama` do not match `ram`.

German:

- Folds `ä`, `ö`, `ü`, and `ß` to `ae`, `oe`, `ue`, and `ss`, then removes
  stopwords: `aber`, `am`, `an`, `auf`, `aus`, `bei`, `das`, `dem`, `den`,
  `der`, `des`, `die`, `ein`, `eine`, `einem`, `einen`, `einer`, `eines`,
  `er`, `es`, `fuer`, `hat`, `im`, `in`, `ist`, `mit`, `nicht`, `sie`, `und`,
  `von`, `war`, `wir`, `zu`, `zum`, `zur`.
- Adds stem keys for adjective/noun endings including `ern`, `ten`, `en`,
  `er`, `em`, `es`, `te`, `est`, `st`, `t`, and guarded final `e`/`n`.
- Handles common `ge-...-t` participles such as `gespielt` -> `spiel`.
- Converts conservative umlauted plural stems containing `aeu` back to `au`,
  so `Bäume` and `Häuser` can match `baum` and `haus`.
- Short forms stay guarded; for example `arm` and `arme` remain exact while
  longer adjective forms such as `schnelle` match `schnell`.

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

This is still a compact Playground implementation. The custom tables are
intentionally simple and portable, with no production indexing optimizations.
Ranking is meant for relative ordering inside one query and one language
partition, not for comparing scores across languages or unrelated queries.

The analyzers are conservative rule-based normalizers, not dictionary
lemmatizers. They do not split German compounds, model Polish consonant
alternations, handle every English irregular form, add synonyms, provide phrase
search, typo tolerance, snippets/highlighting, or multilingual fallback.
