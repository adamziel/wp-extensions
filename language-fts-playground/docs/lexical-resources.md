# Lexical Resource Packs

Language FTS Playground keeps language analysis data in local resources under
`language-fts-playground/resources/languages/<language>/`. Runtime loading is
pure PHP and uses keyed maps after each language profile is parsed, so query
expansion does not scan resource files.

The repository separates two concerns:

- Runtime packs are compact local files: `stopwords.txt`, `lexemes.tsv`,
  `synonyms.tsv`, optional `synsets.tsv`, and `pack.php` provenance metadata.
- Build-time imports convert source-specific fixtures or pre-extracted lexical
  database exports into those compact runtime files.

The shipped resources are curated seed data. They are not a comprehensive synonym database.
Do not treat the current English, German, or Polish resources as a vendored
Open English WordNet, OpenThesaurus, or plWordNet distribution.

## Profile Contract

`profile.php` returns the language id, label, optional order, optional
normalization folds, optional language signal regexes, and resource file names:

```php
'resources' => [
    'stopwords' => 'stopwords.txt',
    'lexemes' => 'lexemes.tsv',
    'synonyms' => 'synonyms.tsv',
    'synsets' => 'synsets.tsv',
],
```

`synsets` is optional. `synonyms.tsv` remains required for compatibility, even
when it contains only the header.

`pack.php` returns provenance metadata for maintainers and tests. The runtime
repository loads it only when `pack_metadata()` is called, not during normal
query analysis:

```php
return [
    'language_id' => 'pl',
    'pack_version' => '2026-06-08-seed',
    'pack_date' => '2026-06-08',
    'source_name' => 'Language FTS Playground curated Polish seed data',
    'source_url' => 'https://github.com/adamziel/wp-extensions/tree/main/language-fts-playground/resources/languages/pl',
    'license_name' => 'GPL-2.0-or-later',
    'attribution_text' => 'Curated demo lexical resources maintained in the Language FTS Playground repository.',
    'provenance' => 'language-fts-playground-polish-curated-seed',
    'files' => [
        'profile.php',
        'stopwords.txt',
        'lexemes.tsv',
        'synonyms.tsv',
        'synsets.tsv',
    ],
    'data_kind' => 'curated_seed',
];
```

`data_kind` is `curated_seed` for hand-maintained demo data and
`imported_comprehensive` only after a reviewed full-size source pack has been
generated and committed.

## Runtime Formats

`stopwords.txt` stores one already-normalized stopword per line.

`lexemes.tsv` maps observed normalized forms to canonical lexical keys:

```text
# observed<TAB>canonical<TAB>provenance
szukaj	szukac	language-fts-playground-polish-demo
wyszukiwania	wyszukiwac	language-fts-playground-polish-demo
```

`synsets.tsv` groups canonical keys by concept without pairwise expansion rows:

```text
# concept_id<TAB>weight<TAB>provenance<TAB>terms
search.action	0.62	language-fts-playground-polish-curated-synset	szukac wyszukiwac wyszukiwarka odnajdywac
```

The `terms` column is a single-space-separated list of normalized canonical
keys. Each concept expands every listed key to every other listed key. The
parser rejects malformed column counts, duplicate concept ids, invalid weights,
empty provenance, missing terms, duplicate terms, uppercase terms, and broken
spacing such as double spaces.

`synonyms.tsv` is the pairwise escape hatch:

```text
# source<TAB>target<TAB>direction<TAB>weight<TAB>provenance
source_key	target_key	query_to_index	0.75	curated-override
```

Use it for targeted overrides or asymmetric relationships. `direction` is
`query_to_index` or `bidirectional`; weight must be greater than `0` and no more
than `1`. If a pairwise row duplicates a concept-derived source/target pair,
the explicit pairwise row wins.

## Build-Time Importer

Use the canonical PHP importer to create compact runtime outputs from small
fixtures or pre-extracted source exports:

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

Supported formats:

- `membership-tsv`: `concept_id<TAB>canonical_term`, with optional trailing
  `observed_term` and `weight` columns. This is the simple intermediate format
  used by the earlier `compile-synsets.php` helper.
- `wordnet-membership-tsv`: the same compact membership shape, intended for a
  pre-extracted plWordNet or WordNet membership export after source-specific
  parsing and license review.
- `openthesaurus-text`: delimiter-separated synonym groups such as
  `suche;suchen;finden`. The default delimiter is `;`; use `--delimiter` for
  fixture variants.
- `wordnet-json`: small JSON fixtures with synset objects that contain unique
  ids and member arrays, for example Open English WordNet-style `members`.

The importer writes `synsets.tsv`, `pack.php`, and `lexemes.tsv` when source
rows include observed/canonical form pairs. It validates malformed rows, empty
concepts, concepts with fewer than two usable terms, invalid whitespace-bearing
terms, invalid weights, duplicate JSON synset ids, and fixed output file paths
inside the requested output directory.

Line-oriented formats stream through the input file. The `wordnet-json` route
uses PHP's core JSON decoder for small fixtures and reviewed excerpts; a future
full Open English WordNet import may need a streaming JSON reader or a
pre-extraction step before this repository commits comprehensive data.

Example maintainer flow for a reviewed German fixture:

```sh
php language-fts-playground/tools/import-lexical-source.php \
  openthesaurus-text /tmp/openthesaurus-german-fixture.txt /tmp/de-pack \
  --language=de \
  --source-name="OpenThesaurus German" \
  --source-url="https://www.openthesaurus.de/about/download" \
  --license-name="CC BY-SA 4.0 or LGPL" \
  --attribution="OpenThesaurus German lexical data." \
  --pack-version="2026-06-08-openthesaurus-fixture" \
  --pack-date="2026-06-08" \
  --provenance="openthesaurus-german-fixture" \
  --data-kind=curated_seed
```

Copy the generated runtime files into
`resources/languages/<language>/` only after checking license obligations,
attribution text, pack size, analyzer quality, and deterministic test output.

## Source And License Caveats

This repository does not download, vendor, or ship full third-party lexical
databases in the current seed packs.

Open English WordNet is a lexical network grouping English words into synsets.
Its GitHub README describes GWN-LMF, JSON, RDF, and WNDB formats and identifies
the license as CC-BY 4.0:
`https://github.com/globalwordnet/english-wordnet`.

OpenThesaurus German publishes text-format and MySQL downloads. Its download
page states the data is available under CC BY-SA 4.0 or LGPL:
`https://www.openthesaurus.de/about/download`.

plWordNet license information from CLARIN says plWordNet can be used, copied,
modified, and distributed for any purpose without fee or royalty while
preserving copyright and disclaimer notices; downloaded versions include a
LICENSE file: `https://clarin-pl.eu/license/plwordnet`.

Those source statements are caveats for maintainers. Before committing any
generated comprehensive pack, preserve the exact source version, license text,
attribution, and generated file list in `pack.php`.

## Importing Larger Lexical Databases Later

This repository ships only a tiny curated seed pack for the demo. It does not
ship WordNet, plWordNet, or another comprehensive synonym database.

A real import should happen at build time, not at runtime:

1. Review the source database license and attribution requirements.
2. Record the exact source URL, version/date, license, attribution, provenance,
   and whether the pack is seed or comprehensive in `pack.php`.
3. Normalize source forms using the language profile's fold/lowercase rules.
4. Map observed forms into `lexemes.tsv` canonical keys.
5. Reduce source synsets or related lexical concepts to canonical-key groups.
6. Assign conservative query-expansion weights and provenance ids.
7. Compile groups to `synsets.tsv` with `import-lexical-source.php`.
8. Run the PHP test harness under normal PHP and `php -n`.

The runtime stays pure PHP and fast because none of this source-specific work
happens during indexing or search. Queries load only compact local resource
files, then use parsed maps for stopwords, lexeme aliases, and query-time
expansions.

The helper at `tools/compile-synsets.php` remains a small compatibility helper
for the historical membership format, but `tools/import-lexical-source.php` is
the canonical importer for new source work.
