# Lexical Resource Packs

Language FTS Playground keeps language analysis data in local resources under
`language-fts-playground/resources/languages/<language>/`. Runtime loading is
pure PHP and uses keyed maps after each language profile is parsed, so query
expansion does not scan resource files.

The repository separates two concerns:

- Runtime packs are compact local files: `stopwords.txt`, `lexemes.tsv`,
  `synonyms.tsv`, optional `synsets.tsv`, optional `synonym_phrases.tsv`,
  optional `term_rules.tsv`, optional `protected_terms.txt`, and `pack.php`
  provenance metadata.
- Build-time imports convert source-specific fixtures or pre-extracted lexical
  database exports into those compact runtime files.

The shipped resources are curated seed data. They are not a comprehensive synonym database.
Do not treat the current English, German, or Polish resources as a vendored
Open English WordNet, OpenThesaurus, or plWordNet distribution.
The bundled seed packs use `term_rules.tsv` for conservative suffix keys, and
English also uses `protected_terms.txt` for terms that should stay exact except
for explicit lexeme rows.

## Profile Contract

`profile.php` returns the language id, label, optional order, optional
tokenizer contract, optional normalization folds, optional language signal
regexes, and resource file names:

```php
'tokenizer' => [
    'id' => 'unicode_words_v1',
    'type' => 'unicode_words',
    'resources' => [],
    'capabilities' => [
        'emits_offsets' => true,
        'emits_positions' => true,
        'supports_fuzzy' => true,
        'supports_overlaps' => false,
    ],
],
```

Profiles may omit `tokenizer`; the runtime defaults to `unicode_words_v1`.
That adapter preserves the current Unicode letter/number tokenization behavior.
No dictionary segmenter or non-space language tokenizer is shipped or supported
by this baseline.

```php
'resources' => [
    'stopwords' => 'stopwords.txt',
    'lexemes' => 'lexemes.tsv',
    'synonyms' => 'synonyms.tsv',
    'synsets' => 'synsets.tsv',
    'synonym_phrases' => 'synonym_phrases.tsv',
    'term_rules' => 'term_rules.tsv',
    'protected_terms' => 'protected_terms.txt',
],
```

`synsets`, `synonym_phrases`, `term_rules`, and `protected_terms` keys may be
omitted. When one of those keys is declared, the named local file must exist.
`synonyms.tsv` remains required for compatibility, even when it contains only
the header.

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
        'synonym_phrases.tsv',
        'term_rules.tsv',
        'protected_terms.txt',
    ],
    'data_kind' => 'curated_seed',
];
```

`data_kind` is `curated_seed` for hand-maintained demo data and
`imported_comprehensive` only after a reviewed full-size source pack has been
generated and committed.

### Comprehensive Pack Metadata

`imported_comprehensive` packs must use the v2 audit schema. Existing
`curated_seed` packs may keep the lightweight metadata above, but comprehensive
packs must also declare source artifact, license, provenance allow-list,
normalization, importer, and runtime digest sections:

```php
return [
    'metadata_schema' => 'language-fts-playground-pack-metadata-v2',
    'language_id' => 'en',
    'pack_version' => 'oewn-2024-lexical-pack-1',
    'pack_date' => '2026-06-09',
    'data_kind' => 'imported_comprehensive',
    'source_name' => 'Open English WordNet',
    'source_url' => 'https://github.com/globalwordnet/english-wordnet',
    'license_name' => 'Creative Commons Attribution 4.0 International',
    'attribution_text' => 'Open English WordNet contributors, used under CC BY 4.0.',
    'provenance' => 'oewn-2024',
    'files' => [
        'profile.php',
        'stopwords.txt',
        'lexemes.tsv',
        'synonyms.tsv',
        'synsets.tsv',
        'LICENSE.oewn.txt',
    ],
    'source' => [
        'name' => 'Open English WordNet',
        'version' => '2024',
        'retrieved_at' => '2026-06-09',
        'artifacts' => [
            [
                'name' => 'english-wordnet-2024.json',
                'url' => 'https://example.test/english-wordnet-2024.json',
                'sha256' => '0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef',
                'bytes' => 123456,
            ],
        ],
    ],
    'license' => [
        'identifier' => 'CC-BY-4.0',
        'name' => 'Creative Commons Attribution 4.0 International',
        'url' => 'https://creativecommons.org/licenses/by/4.0/',
        'text_url' => 'https://creativecommons.org/licenses/by/4.0/legalcode.txt',
        'text_file' => 'LICENSE.oewn.txt',
        'attribution' => 'Open English WordNet contributors, used under CC BY 4.0.',
    ],
    'provenance_ids' => [
        'oewn-2024' => [
            'source' => 'Open English WordNet',
            'source_version' => '2024',
            'description' => 'Generated rows from the reviewed OEWN source snapshot.',
        ],
    ],
    'normalization' => [
        'profile_id' => 'en',
        'profile_version' => 'language-fts-playground-normalization-v1',
        'profile_file' => 'profile.php',
        'profile_sha256' => '0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef',
    ],
    'importer' => [
        'name' => 'language-fts-playground/tools/import-lexical-source.php',
        'version' => 'language-fts-playground-lexical-importer-v2',
        'format' => 'wordnet-json',
        'command' => 'php language-fts-playground/tools/import-lexical-source.php wordnet-json <source-artifact> <output-dir> ...',
        'options' => [
            'data_kind' => 'imported_comprehensive',
            'language' => 'en',
        ],
    ],
    'runtime_files' => [
        [
            'resource' => 'profile',
            'file' => 'profile.php',
            'sha256' => '0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef',
            'bytes' => 1234,
            'generated' => false,
        ],
        [
            'resource' => 'synsets',
            'file' => 'synsets.tsv',
            'sha256' => '0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef',
            'bytes' => 45678,
            'generated' => true,
        ],
    ],
];
```

The validator does not fetch URLs or read source database archives. It checks
that v2 dates, HTTP(S) URLs, lowercase SHA-256 digests, positive byte counts,
local file names, license text files, runtime file coverage, and row provenance
ids are internally consistent. It also compares every declared
`runtime_files` digest and byte count against the installed local file. Source
artifact digest verification happens in the importer or CI where the original
source artifact is available.

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

`synonym_phrases.tsv` maps analyzed query key sequences to one or more target
key sequences:

```text
# source_terms<TAB>target_terms<TAB>direction<TAB>weight<TAB>provenance
full text search	fts	query_to_index	0.82	language-fts-playground-english-curated_seed
site search	search site	bidirectional	0.72	language-fts-playground-english-curated_seed
```

Both `source_terms` and `target_terms` are single-space-separated normalized
canonical analyzer keys. Runtime search matches source sequences over the
ordered query token-key sequence after tokenization, lexeme mapping, stemming,
and stopword removal. A one-key target scores as a weighted phrase-synonym
candidate. A multi-key target must match adjacent indexed positions; it is not
treated as unrelated loose terms. Exact canonical matches still rank above
phrase-synonym-only matches.

The parser rejects malformed column counts, empty source or target sequences,
broken spacing, duplicate terms inside one sequence, uppercase terms, invalid
directions, invalid weights, empty provenance, no-op source/target pairs, and
duplicate source/target phrase pairs. Bidirectional rows materialize both
directions deterministically.

`term_rules.tsv` adds generic profile-backed keys from one normalized term:

```text
# id<TAB>min_term_length<TAB>pattern<TAB>strip_prefix<TAB>strip_suffix<TAB>append<TAB>min_key_length<TAB>flags<TAB>alternate_pattern<TAB>alternate_replacement<TAB>provenance
drop-ing	5	/ing$/u		ing		3	trim_doubled_final_consonant,require_vowel			example-curated-rules
make-e	5	/ing$/u		ing		4	append_e_if_cvc			example-curated-rules
folded-umlaut-e	6	/^[a-z]+e$/u		e		4		/aeu/u	au	example-curated-rules
```

The analyzer keeps the exact normalized term and any `lexemes.tsv` keys, then
applies term rules unless the term is protected. A rule first checks
`min_term_length` and `pattern`, strips an optional literal prefix and suffix,
appends optional literal text, applies optional flags, and keeps the result only
when it reaches `min_key_length` and the normal 255-byte key limit. If
`alternate_pattern` is non-empty, the analyzer also applies that regex to the
generated key and emits the replaced key when it differs and still passes the
length guard. Use blank `alternate_pattern` and `alternate_replacement` columns
when a rule does not need an alternate key.

Supported flags are `trim_doubled_final_consonant`, `require_vowel`,
`require_vowel_or_y`, `append_e_if_cvc`, and `stop_after_match`. The analyzer
honors `stop_after_match` only after that rule emits at least one key, then it
skips later term rules for the same term. The other flags are deliberately
ASCII-oriented foundation helpers; language-specific broad stemming still needs
reviewed rules and relevance tests.

`protected_terms.txt` stores one normalized lowercase term per line. Protected
terms still receive exact and lexeme keys, but skip `term_rules.tsv`; use this
for words where a broad generic rule would produce misleading keys.

## Validation And Admin Status

Run the pack validator before committing generated resources:

```sh
php language-fts-playground/tools/validate-lexical-packs.php
php language-fts-playground/tools/validate-lexical-packs.php --json
php language-fts-playground/tools/validate-lexical-packs.php --max-synset-size=64 --max-expansions-per-term=128
```

The default output is human-readable. `--json` emits deterministic JSON for CI
or release checks, and the command exits nonzero when metadata is invalid,
listed runtime files are missing, resource rows are malformed or duplicated, or
thresholds are exceeded. The validator is pure PHP and is expected to work with
`php -n`.

The validator reports, per language:

- language id and label,
- `pack.php` source name, source URL, license, version/date, data kind, and provenance,
- whether every runtime file listed in `pack.php` exists,
- stopword rows, lexeme rows, pairwise synonym rows/expansions, synset rows,
  concept-derived expansions, phrase synonym rows, phrase synonym expansions,
  term rule rows, and protected term rows,
- max synset size and max unique expansion fanout for any one term,
- warnings for invalid metadata, missing files, malformed rows, duplicate rows, and broad synsets.

`--max-synset-size` limits how many canonical keys one concept can contain.
`--max-expansions-per-term` limits how many unique targets any source term can
expand to after pairwise rows and concept rows are considered.
Broad synsets are dangerous for search quality.
Each term expands to every other term in that concept; a large or vague concept
can make precise searches match loosely related documents and push exact
results down.

The WordPress admin page at `Tools -> Language FTS` includes a compact
Lexical pack status table with the language, `curated_seed` or
`imported_comprehensive` data kind, source, license, source version, license id,
runtime digest status, importer version, version/date, lexeme/synset/term-rule/
expansion counts, warnings, audit metadata details, and the effective local
resource root.
Read that table as a provenance and quality signal: current shipped packs are
`curated_seed` demo data, not comprehensive WordNet, OpenThesaurus, or
plWordNet databases.

## Comprehensive Imports And Promotion

`import-lexical-source.php` defaults to `curated_seed` when `--data-kind` is
omitted. To produce `imported_comprehensive` metadata, pass the deterministic
audit options and keep the source artifact outside the runtime pack:

```sh
php language-fts-playground/tools/import-lexical-source.php wordnet-json \
  <source-artifact> <output-dir> \
  --language=en \
  --data-kind=imported_comprehensive \
  --source-name='Open English WordNet' \
  --source-url=https://github.com/globalwordnet/english-wordnet \
  --source-version=2024 \
  --source-retrieved-at=2026-06-09 \
  --source-artifact-name=english-wordnet-2024.json \
  --source-artifact-url=https://example.test/english-wordnet-2024.json \
  --source-artifact-sha256=0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef \
  --source-artifact-bytes=123456 \
  --license-name='Creative Commons Attribution 4.0 International' \
  --license-id=CC-BY-4.0 \
  --license-url=https://creativecommons.org/licenses/by/4.0/ \
  --license-text-url=https://creativecommons.org/licenses/by/4.0/legalcode.txt \
  --license-text-file=LICENSE.oewn.txt \
  --normalization-profile-version=language-fts-playground-normalization-v1 \
  --attribution='Open English WordNet contributors, used under CC BY 4.0.' \
  --pack-version=oewn-2024-lexical-pack-1 \
  --pack-date=2026-06-09 \
  --provenance=oewn-2024
```

The importer recomputes the input artifact SHA-256 and byte count and fails if
they do not match the provided values. It records generated runtime files and
preexisting profile-declared runtime files in `runtime_files`; license text must
already exist as a local file in the output directory.

Do not promote a generated pack as `imported_comprehensive` until review
artifacts exist for validation output, deterministic re-import output, source
artifact digest evidence, license and redistribution review, source-specific
filter decisions, relevance evaluator results, fanout/memory checks, and admin
status or validator JSON evidence. Do not download or vendor third-party lexical
databases into this repository without a separate review and promotion decision.

## Relevance Evaluation

Validation proves the compact files are well-formed. Relevance evaluation
proves a generated pack improves search behavior on a reviewed corpus/query
fixture before it ships or becomes a custom production root:

```sh
php language-fts-playground/tools/evaluate-lexical-pack.php \
  language-fts-playground/tests/fixtures/lexical-eval/demo-suite.json

php language-fts-playground/tools/evaluate-lexical-pack.php \
  language-fts-playground/tests/fixtures/lexical-eval/demo-suite.json \
  --json \
  --min-recall-at-5=1.0 \
  --min-precision-at-5=0.2 \
  --min-mrr=1.0 \
  --min-ndcg-at-5=1.0
```

Use `--resource-root=/srv/language-packs` to evaluate an external generated
root. The evaluator is pure PHP, builds an in-memory index with the normal
analyzer, indexer, and searcher classes, and is expected to run under `php -n`.
It does not use WordPress runtime functions, database-native FTS, external
services, or source database formats.

The fixture root is a JSON object:

```json
{
  "name": "Reviewed English WordNet smoke suite",
  "source": "Maintainer-reviewed query set",
  "language_pack_expectations": {
    "en": "Imported synsets should recover search-intent documents."
  },
  "thresholds": {
    "recall_at_5": 0.95,
    "precision_at_5": 0.2,
    "mrr": 0.9,
    "ndcg_at_5": 0.9
  },
  "documents": [
    {
      "id": "doc-search",
      "language": "en",
      "title": "Search guide",
      "excerpt": "Optional summary text.",
      "content": "<p>Visible searchable HTML.</p>",
      "image_alt": ["optional image alt text"],
      "notes": "Optional maintainer notes."
    }
  ],
  "queries": [
    {
      "query": "lookup",
      "language": "auto",
      "relevant": ["doc-search"],
      "irrelevant": ["doc-broad-synset-bait"],
      "notes": "Optional query provenance."
    }
  ]
}
```

`language` on queries defaults to `auto`. `image_alt` may be a string or an
array of strings. `searchable_html` can be used when fixture authors want to
append extra HTML separately from `content`. `relevant` document ids contribute
to recall@5, precision@5, MRR, and nDCG@5. `irrelevant` ids are guard rails:
if any of them appear in the top five, the evaluator reports an unexpected hit
and exits nonzero. Human output lists misses and unexpected top-5 ids per
query; `--json` emits deterministic machine-readable metrics for CI.

The committed `phrase-suite.json` fixture is a small smoke test for
resource-backed phrase synonyms. It checks `full text search -> fts` recall and
guards against a separated partial-acronym bait document.

## Custom Resource Roots

Generated packs do not need to be committed into the plugin directory. Create a
complete `resources/languages`-style root, validate it, install it anywhere PHP
can read locally, and then configure the plugin to use that root.

Validate the external root before activating it:

```sh
php language-fts-playground/tools/validate-lexical-packs.php --resource-root=/srv/language-packs
php language-fts-playground/tools/validate-lexical-packs.php --resource-root=/srv/language-packs --json
```

Use a constant when the path is deployment configuration:

```php
define('LANGUAGE_FTS_PLAYGROUND_LEXICAL_RESOURCE_ROOT', '/srv/language-packs');
```

Use the filter when another plugin or environment layer owns the path decision:

```php
add_filter(
    'language_fts_playground_lexical_resource_root',
    static fn(string $root, string $default_root): string => '/srv/language-packs',
    10,
    2
);
```

The value must be a string local filesystem path. URL-like roots are rejected;
lexical packs are never downloaded at indexing or query time. The filter runs
after the constant/default selection, so it can replace either path. Non-string
filter returns are ignored.

The analyzer fingerprint includes the effective root plus per-language
`pack.php` metadata such as `language_id`, `pack_version`, `pack_date`,
`provenance`, `data_kind`, source/license fields, attribution text, and listed
file names. It also hashes profile-declared runtime resource files, so local
TSV/text content changes are detected without scanning unrelated directories.
When the fingerprint changes, `ensure_schema()` stores the new fingerprint,
marks the index as requiring a rebuild, and records the root/fingerprint in
admin-visible status. If the custom root is missing or pack metadata cannot be
read, the schema check records an error instead of silently accepting stale
analyzer assumptions.

## Morphology Fixture Compiler

Use `tools/compile-morphology-fixture.php` to turn a small reviewed JSON
morphology fixture into compact runtime files:

```sh
php language-fts-playground/tools/compile-morphology-fixture.php \
  language-fts-playground/tests/fixtures/morphology-sources/en-seed.json \
  /tmp/language-fts-morphology-en
```

The fixture schema is
`language-fts-playground-morphology-fixture-v1`. It describes synthetic or
sample behavior: source/provenance metadata, normalized stopword excerpts,
protected terms, reviewed term-rule rows, sample stemming pairs, and analyzer
expectations. The compiler writes `profile.php`, `stopwords.txt`,
`lexemes.tsv`, `synonyms.tsv`, `term_rules.tsv`, `protected_terms.txt`, and
`pack.php`. Pass `--file-only` when a maintainer only wants refreshed
`term_rules.tsv`, `protected_terms.txt`, and `stopwords.txt` files for review.

The compiler validates fixture shape before writing resources. It rejects an
unknown schema, duplicate rule or expectation ids, invalid regex patterns,
unknown term-rule flags, rule arrays whose IDs are not already in sortable
runtime order, non-normalized stopword/protected terms, duplicate normalized
terms, and malformed analyzer expectations. Sorting happens after
normalization checks, so case or fold mistakes fail clearly instead of being
silently collapsed.

These fixtures are not Snowball compliance tests and are not vendored
morphology datasets. They are deliberately tiny, synthetic or sample behavior
checks that prove the generic analyzer path handles positive suffix keys,
over-stemming bait, protected terms, stopword removal, alternate keys, and
`stop_after_match` ordering.

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
- `wordnet-json`: small, reviewed JSON fixtures. It supports Global Wordnet /
  Open English WordNet JSON-LD excerpts with an `@graph`, lexical `entry`
  records, `lemma.writtenForm` values, entry `sense` ids, and synset `members`
  that reference those sense ids. It also supports a simple project fixture
  shape where synset `members` are already literal canonical terms.

The importer writes `synsets.tsv`, `pack.php`, and `lexemes.tsv` when source
rows include observed/canonical form pairs. It validates malformed rows, empty
concepts, concepts with fewer than two usable terms, invalid whitespace-bearing
terms, invalid weights, duplicate JSON synset ids, and fixed output file paths
inside the requested output directory. Generated `pack.php` metadata defaults to
`curated_seed`; pass `--data-kind=imported_comprehensive` only after the source
pack has had full license, provenance, scale, and relevance review. When the
output directory already contains `profile.php`, the generated file list also
includes every profile-declared runtime resource so validator and fingerprint
checks can catch missing or stale pack contents.

Line-oriented formats stream through the input file. The `wordnet-json` route
uses PHP's core JSON decoder for small fixtures and reviewed excerpts. It
resolves Global Wordnet-style member ids through lexical entries before writing
`synsets.tsv`, and rejects unresolved member ids instead of writing them as
searchable terms. Full Open English WordNet imports may still need a streaming
JSON reader or a pre-extraction step before this repository commits
comprehensive data.

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

Copy the generated runtime files into either the bundled
`resources/languages/<language>/` directory or a validated custom resource root
only after checking license obligations, attribution text, pack size, analyzer
quality, and deterministic test output.

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
8. Run `validate-lexical-packs.php` against the generated runtime root.
9. Run `evaluate-lexical-pack.php` against a relevance fixture with explicit
   quality gates.
10. Install the validated root as a custom resource root and rebuild the index.
11. Run the PHP test harness under normal PHP and `php -n`.

The runtime stays pure PHP and fast because none of this source-specific work
happens during indexing or search. Queries load only compact local resource
files, then use parsed maps for stopwords, lexeme aliases, and query-time
expansions.

The helper at `tools/compile-synsets.php` remains a small compatibility helper
for the historical membership format, but `tools/import-lexical-source.php` is
the canonical importer for new source work.
