# Language FTS Playground

Stable demo:

[![Try it in WordPress Playground](https://playground.wordpress.net/badge.svg)](https://playground.wordpress.net/?blueprint-url=https://raw.githubusercontent.com/adamziel/wp-extensions/main/language-fts-playground/playground/blueprint.json)

The badge always launches the `main` branch Blueprint. Use it for the published
demo only. For branch QA, use a local Playground mount or a temporary branch
Blueprint whose `installPlugin.pluginData.ref` points at the branch being
tested.

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

For user distribution, build the installable plugin zip with:

```sh
php language-fts-playground/tools/build-release.php
```

The zip is written to `language-fts-playground/dist/` by default and contains
one top-level `language-fts-playground/` directory with runtime PHP, bundled
lexical resources, docs, README, WordPress.org `readme.txt`, and the Playground
Blueprint. Tests, source tools, fixtures, logs, review artifacts, and generated
build outputs are excluded. See `docs/release-packaging.md` for the release
checklist and package policy.

Search results show the score, matched fields, matched normalized terms, and a
safe snippet with matched source terms highlighted with `<mark>`. Snippet text
is escaped before marks are inserted, so visible post text that looks like HTML
is not emitted as raw markup.

The seeded posts cover:

- Automatic language routing: search `orchard`, `lodz`, `fuehrung`, or `szukanie` with `Automatic` selected and the searcher ranks likely language partitions from profile-backed signals and lexical evidence before searching.
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

The language selector defaults to `Automatic`, which ranks likely language
partitions from profile-backed language signals, lexeme/canonical-key coverage,
synonym/synset source keys, and stopword evidence that only boosts candidates
after non-stopword evidence exists. When that evidence produces confident
candidates, the searcher queries only those selected partitions. When evidence
is ambiguous or missing, automatic mode uses bounded fallback: the bundled
three-language demo can search all enabled seed partitions, while larger local
roots use storage-backed preflight term hits and search only the bounded
selected set. Results still report the partition that matched. Choosing English,
Polish, German, or a custom language id keeps the precision-filter behavior and
searches only that partition.

## Search Diagnostics

The searcher exposes a PHP-only explain API:

```php
$diagnostics = $searcher->explain($query, $language, $limit);
```

The `Tools -> Language FTS` admin page renders the same diagnostics as escaped
JSON after each search. The payload is deterministic and JSON-serializable, so
it can be copied into regression fixtures or compared in tests without a live
WordPress/MySQL search backend.

Diagnostics show the original query, requested language mode, automatic routing
evidence and selected partitions, analyzed query tokens and lookup terms per
searched language, single-token synonym expansions, multiword phrase synonym
expansions, fuzzy candidates with edit distance, phrase filter pass/fail
results, matched fields, matched terms, match classes, and score contribution
breakdowns by term, class, and field. No-result responses include coarse causes
such as an empty analyzed query after stopwords, no postings for searched terms,
phrase filters removing candidates, or no scored results after candidate
collection.

Use diagnostics to answer why automatic mode searched a specific language, why
`szukanie` expanded toward indexed Polish `wyszukiwania`, whether
`full text search` came from `synonym_phrases.tsv`, which `orchrd~` fuzzy
candidate was selected, which field boost influenced ranking, and where a
no-result query stopped. This is a debugging aid for maintainers and lexical
pack authors; it is not a replacement for offline relevance evaluation with a
representative query suite.

## Search Benchmark Counters

The repository includes a pure-PHP synthetic benchmark fixture for search
materialization counters. It builds a small in-memory `bm` language profile and
documents with controlled common terms, quoted phrases, fuzzy typo candidates,
single-token synonyms, phrase synonyms, and mixed title/excerpt/content/alt
field distributions. The command reports lookup terms by class plus candidate,
postings, document length, position, field text, field metadata, gate, timing,
and peak-memory counters without MySQL, database-native FTS, or committed large
corpora.

Run the PR-smoke gate suite under normal PHP and `php -n`:

```sh
php language-fts-playground/tools/search-benchmark-counters.php --suite=pr-smoke --json --fail-on-gate
php -n language-fts-playground/tools/search-benchmark-counters.php --suite=pr-smoke --json --fail-on-gate
```

Run individual probes under `php -n` when investigating a specific materialized
shape:

```sh
php -n language-fts-playground/tools/search-benchmark-counters.php --scenario=common-term --documents=64 --limit=5 --json
php -n language-fts-playground/tools/search-benchmark-counters.php --scenario=phrase-heavy --documents=64 --limit=5
php -n language-fts-playground/tools/search-benchmark-counters.php --scenario=fuzzy-heavy --documents=64 --limit=5 --json
```

Use `--scenario=all` to print every committed probe, including the synonym,
phrase-synonym, and mixed-field fixtures. Use `--suite=scheduled --documents=5000
--languages=8 --json --fail-on-gate` for a larger deterministic CI or nightly
run, and `--suite=manual-stress --documents=25000 --languages=16 --json
--fail-on-gate` for manual pre-release stress evidence. When `--languages` is
greater than one, the fixture indexes generated language profiles and searches
with automatic language routing, so `selected_partitions` and materialization
counters reflect bounded multi-partition search fanout. Public-search probes are
gated so field text rows stay within the final result count, field metadata rows
remain zero, postings materialization counters participate in hard gates, and
timing remains reported rather than the primary PR failure signal.

The PR-smoke suite is intentionally fixture-sized and machine-stable: it is meant
to catch materialization, fanout, and ranking-shape regressions during ordinary
review. Scheduled and manual-stress suites raise document and language counts for
trend evidence and exercise the automatic-routing cap, but they are still
pure-PHP in-memory checks. They do not prove MySQL query plans, live WordPress
latency, or production-scale relevance on a large real corpus; package smoke,
Playground smoke, and site-specific profiling remain separate release evidence.

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
The English seed also includes a small `synonym_phrases.tsv` resource for
multiword query phrases such as `full text search` to `fts`. Phrase synonym
targets with one key score as weighted synonym candidates; targets with
multiple keys must match adjacent indexed positions rather than loose terms.
Synonym-only and phrase-synonym-only matches are downweighted, stay in the same
language partition, and highlight the matched source token normally.

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
    synonym_phrases.tsv
    term_rules.tsv
    protected_terms.txt
  pl/
    profile.php
    pack.php
    stopwords.txt
    lexemes.tsv
    synonyms.tsv
    synsets.tsv
    term_rules.tsv
  de/
    profile.php
    pack.php
    stopwords.txt
    lexemes.tsv
    synonyms.tsv
    term_rules.tsv
```

`profile.php` declares the language id, label, load order, optional tokenizer
contract, optional character folds, optional language signal regexes, and
resource file names. Profiles may omit the tokenizer declaration; the runtime
defaults to `unicode_words_v1`, which preserves the current Unicode
letter/number tokenization behavior. Tokenizer declarations are registry-backed
and may reference profile-local resources; today the non-space-capable path is a
generic synthetic readiness fixture used by tests to prove offsets, positions,
phrase matching, snippets, and diagnostics. This baseline does not bundle real
CJK, Thai, or other dictionary resources, and it should not be described as real
language segmentation until reviewed tokenizer data is supplied.
`pack.php` records source, license, attribution, provenance, generated file
list, pack version/date, and whether the resource pack is curated seed data or
an imported comprehensive pack. The analyzer does not load `pack.php` during
normal query analysis.
`stopwords.txt` stores one normalized stopword per line. `lexemes.tsv` maps
observed normalized forms to canonical keys, for example Polish `szukaj` to
`szukac`, `wyszukiwania` to `wyszukiwac`, and `odnajdywanie` to `odnajdywac`.
`synsets.tsv` groups canonical keys by concept with a weight and provenance,
without enumerating every pair in the file. `synonyms.tsv` remains the pairwise
override/escape hatch for asymmetric fixes or targeted compatibility rows.
`synonym_phrases.tsv` is optional and maps space-separated canonical key
sequences to target key sequences with a direction, weight, and provenance. The
parser validates row shapes and malformed resources fail during profile
loading.

Profiles are parsed lazily and cached on the analyzer's profile repository.
Stopwords, lexeme aliases, and concept-derived synonym expansions are stored as
keyed maps, so the analyzer does not scan resource files while analyzing each
token or expanding each query.

Automatic language routing uses those same compact runtime maps. It does not
load validator metadata, generated source import files, or external services at
query time, and it does not require PHP code changes for new language ids. Add
profile `language_signals`, lexeme rows, and synonym/synset source keys to give
the router confidence evidence for custom packs. Phrase synonym source
sequences also contribute confidence when their analyzed non-stopword keys
match the query. Stopwords are useful only as a tie-breaker/booster once that
non-stopword evidence exists.

Automatic mode is bounded and deterministic: confident profile evidence selects
the top matching partitions, while weak, ambiguous, or missing profile evidence
uses storage-backed preflight term hits to avoid blindly fetching every
partition when many languages are enabled. When automatic mode searches more
than one partition, public results keep the raw partition-local `score`, but
ordering uses an internal normalized rank score with the routing prior as a
small deterministic tie-breaker. This keeps title/content BM25 differences
meaningful inside each partition without letting one partition's raw score
scale dominate all cross-partition results.

The included resources are a curated demo seed, not a comprehensive synonym
database. To add demo synonyms without editing PHP, add normalized observed
forms to `lexemes.tsv`, add canonical keys to a `synsets.tsv` concept row, and
declare the optional `synsets` resource in `profile.php` if that language does
not already have one. Use `synonyms.tsv` only when an explicit pair should
override or supplement concept-derived expansions. Use `synonym_phrases.tsv`
when the relationship needs a source or target sequence such as an acronym,
product alias, organization name, or phrase.

The OEWN comprehensive-pack lane has only a source-lock preflight artifact at
`language-fts-playground/review-artifacts/source-locks/oewn-comprehensive-preflight.json`.
Current bundled/indexer data is not a comprehensive OEWN pack. No full-source
OEWN import has begun, and comprehensive English lexical support is not claimed.
Verify the preflight gate with:

```sh
php language-fts-playground/tools/verify-lexical-source-lock.php \
  language-fts-playground/review-artifacts/source-locks/oewn-comprehensive-preflight.json \
  --allow-pending-artifact-values
```

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
formats. Omitted `--data-kind` values default to `curated_seed`; use
`imported_comprehensive` only for reviewed full-size source packs. If the
output directory already has `profile.php`, generated metadata lists every
profile-declared runtime resource.

Validate lexical packs before committing generated resources:

```sh
php language-fts-playground/tools/validate-lexical-packs.php
php language-fts-playground/tools/validate-lexical-packs.php --json
php language-fts-playground/tools/validate-lexical-packs.php --max-synset-size=64 --max-expansions-per-term=128 --max-phrase-expansions-per-source=64
```

The validator reports pack provenance, whether every file listed in `pack.php`
exists and whether profile-declared resources are listed, stopword/lexeme/
synset/phrase/expansion counts, max synset size, max per-term expansion
fanout, max phrase-source expansion fanout, and warnings for invalid metadata
or malformed resource rows.
Broad synsets are dangerous for search quality because each term expands to
every other term in the concept; a large or loosely related concept can turn a
precise query into many weak matches. The threshold options make those cases
fail in CI before they reach the admin UI or search tests. Runtime profile
loading enforces the same hard caps and rejects oversized packs instead of
silently truncating expansion maps.

Evaluate relevance before installing or committing a larger generated pack:

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

The evaluator is pure PHP, builds an in-memory index with the normal analyzer,
indexer, and searcher, and works with bundled or custom `--resource-root`
packs. Fixture documents include `id`, `language`, `title`, optional `excerpt`,
`content`, optional searchable HTML or image alt text, and optional notes.
Fixture queries include `query`, optional `language` (`auto` by default),
`relevant` ids, optional `irrelevant` ids that must not appear in the top five,
optional `expect` assertions, and optional notes/provenance. The evaluator
reports recall@5, precision@5, MRR, nDCG@5, misses, unexpected top-5 hits,
selected partitions, expectation failures, and deterministic explain summaries,
then exits nonzero when a configured threshold or guard fails. Use
`expect.no_results` for no-result guards, `expect.top_ids` for ordered rank
prefix checks, `expect.selected_partitions` for automatic routing assertions,
`expect.diagnostics_contains`/`expect.diagnostics_not_contains` for explain
payload checks, and document-id maps under `expect.matched_fields`,
`expect.matched_terms`, `expect.match_classes`, `expect.snippet_contains`, and
`expect.snippet_not_contains` for matched metadata and snippet safety checks.

The committed `phrase-suite.json` fixture checks the bundled
`full text search -> fts` phrase synonym and includes a false-positive guard for
partial acronym/token matches. The committed `coverage-suite.json` fixture
exercises ordered ranking, no-result false-positive guards, explain
diagnostics, matched fields/terms/classes, snippets, morphology, synonyms,
multiword phrase synonyms, fuzzy search, no-evidence automatic fallback,
field-aware ranking, and explicit-vs-auto language behavior.

The `Tools -> Language FTS` admin page includes a compact lexical pack status
table with language, data kind, source, license, version/date, counts, and
warnings. It also shows the effective local resource root being validated.
The shipped English, Polish, and German packs are still labeled `curated_seed`;
they are not comprehensive lexical databases.

Bundled and custom packs may declare `term_rules.tsv` and
`protected_terms.txt` resources. Term rules add generic normalized term keys
using a reviewed strip/append rule format with optional alternate regex
replacement keys and flags such as `require_vowel_or_y` and
`stop_after_match`.
Protected terms keep exact and lexeme keys while opting out of those broad
rules. Omit those profile resource keys when a pack does not use them; declared
keys must point at existing local files.

Generated packs can live outside this plugin. Install a complete
`resources/languages`-style directory anywhere readable by PHP, validate it
first, then point the plugin at that local path:

```sh
php language-fts-playground/tools/validate-lexical-packs.php --resource-root=/srv/language-packs
php language-fts-playground/tools/evaluate-lexical-pack.php path/to/relevance-fixture.json --resource-root=/srv/language-packs
```

```php
define('LANGUAGE_FTS_PLAYGROUND_LEXICAL_RESOURCE_ROOT', '/srv/language-packs');

add_filter(
    'language_fts_playground_lexical_resource_root',
    static fn(string $root): string => '/srv/language-packs'
);
```

The root must be a local filesystem path, not a URL or runtime download source.
Changing pack metadata such as `pack_version`, `pack_date`, provenance, data
kind, or listed files changes the analyzer fingerprint. The fingerprint also
includes content hashes for profile-declared runtime resources, so local TSV
or text file changes trigger the next schema check to mark the index as
requiring a rebuild.

See `docs/lexical-resources.md` for the resource contract and
`tools/import-lexical-source.php` for source import usage. The maintainer flow
for comprehensive packs is import, validate, evaluate a relevance fixture,
install the custom root, and rebuild the index. Open English WordNet is CC-BY
4.0 according to its GitHub README, OpenThesaurus German publishes downloads
under CC BY-SA 4.0 or LGPL, and plWordNet license information from CLARIN
allows use, copying, modification, and distribution subject to preserving
copyright and disclaimer notices. Those sources are not bundled here. Current
shipped resources remain seed data unless a generated comprehensive pack is
committed after source-specific normalization, license review, attribution
review, pack-size review, and relevance testing.

## Supported Analyzer Behavior

The analyzer removes profile-backed English, Polish, and German stopwords,
keeps exact terms, applies profile-backed lexeme aliases first, applies optional
profile term rules for unprotected terms, and uses bundled lexeme rows for
irregular or high-signal forms:

- English lowercases terms and includes resource rows plus conservative term-rule keys for regular forms and a few guarded lexeme irregulars: `search` matches `searching`, `searched`, and `searches`; `story` matches `stories`; `make` matches `making`; `run` matches `running`; `child` matches `children`.
- English keeps sensitive terms such as `news`, `bus`, and `analysis` exact, and avoids broad noun-to-verb collapses such as `runner` to `run`.
- Polish folds diacritics from profile data, uses curated resource keys and the demo concept pack for `szukaj`/`szukanie`/`wyszukiwarka`/`wyszukiwanie`/`wyszukiwania`/`odnajdywanie`, and keeps term-rule suffix keys for forms such as `polska`/`polskiej`, `partycja`/`partycji`, and `lodz`/`Łódź`.
- German folds `ä`, `ö`, `ü`, and `ß` from profile data to `ae`, `oe`, `ue`, and `ss`, then uses resource rows and term-rule keys for demo forms such as `deutsch`/`deutschen`/`deutscher`/`deutsche`, `fuehrung`/`Führungen`, `strasse`/`Straßen`, `baum`/`Bäume`, and `spiel`/`gespielt`.
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

Open the stable browser demo:

```text
https://playground.wordpress.net/?blueprint-url=https://raw.githubusercontent.com/adamziel/wp-extensions/main/language-fts-playground/playground/blueprint.json
```

The stable Blueprint installs this monorepo subdirectory from `main` with
`installPlugin` using `git:directory`, activates the plugin, seeds demo posts,
rebuilds the PHP index, and lands on the admin search page.

For branch QA, do not use the stable badge unless the change has already landed
on `main`. Run the current checkout locally instead:

```sh
npx --yes @wp-playground/cli@latest server \
  --login \
  --mount=./language-fts-playground:/wordpress/wp-content/plugins/language-fts-playground
```

Then activate `Language FTS Playground` in `/wp-admin/plugins.php` and open
`Tools -> Language FTS`.

If you need a shareable branch QA link after pushing a branch, create a
temporary copy of `playground/blueprint.json`, change
`steps[0].pluginData.ref` from `main` to that branch name, and publish the
branch-specific Blueprint URL. Keep the public badge pointed at the stable
`main` Blueprint.

## Development Checks

```sh
php language-fts-playground/tests/run.php
php -n language-fts-playground/tests/run.php
php language-fts-playground/tools/validate-lexical-packs.php
php -n language-fts-playground/tools/validate-lexical-packs.php
php language-fts-playground/tools/validate-lexical-packs.php --json
php language-fts-playground/tools/evaluate-lexical-pack.php language-fts-playground/tests/fixtures/lexical-eval/demo-suite.json
php -n language-fts-playground/tools/evaluate-lexical-pack.php language-fts-playground/tests/fixtures/lexical-eval/demo-suite.json
php language-fts-playground/tools/evaluate-lexical-pack.php language-fts-playground/tests/fixtures/lexical-eval/demo-suite.json --json
php language-fts-playground/tools/evaluate-lexical-pack.php language-fts-playground/tests/fixtures/lexical-eval/phrase-suite.json
php -n language-fts-playground/tools/evaluate-lexical-pack.php language-fts-playground/tests/fixtures/lexical-eval/phrase-suite.json
php language-fts-playground/tools/evaluate-lexical-pack.php language-fts-playground/tests/fixtures/lexical-eval/phrase-suite.json --json
php language-fts-playground/tools/evaluate-lexical-pack.php language-fts-playground/tests/fixtures/lexical-eval/coverage-suite.json
php -n language-fts-playground/tools/evaluate-lexical-pack.php language-fts-playground/tests/fixtures/lexical-eval/coverage-suite.json
php language-fts-playground/tools/evaluate-lexical-pack.php language-fts-playground/tests/fixtures/lexical-eval/coverage-suite.json --json
php -r "json_decode(file_get_contents('language-fts-playground/playground/blueprint.json')); if (json_last_error()) { fwrite(STDERR, json_last_error_msg() . PHP_EOL); exit(1); }"
find language-fts-playground -name "*.php" -print0 | xargs -0 -n1 php -l
```

## Limitations

This is a demo-sized implementation. The custom tables are intentionally simple
and portable, with no production indexing optimizations. Ranking is meant for
relative ordering inside one query; public raw scores remain partition-local,
while automatic cross-partition ordering uses the internal normalized rank
described above. Automatic routing is deterministic profile/storage-backed
routing, not statistical language detection. The lexical resources and concept
pack are curated for the demo and are not full dictionaries or a shipped
WordNet/plWordNet database; they are intended to be expanded or replaced later
by generated resources from licensed linguistic sources. The bundled suffix
term rules are still conservative handwritten heuristics. The plugin does not
implement full stemming, full lemmatization, multi-edit fuzzy search, or
unconfigured cross-language fallback.
Resource-backed term rules are a foundation for reviewed packs, not a full
stemmer; their generic flags are ASCII-oriented and should be validated against
relevance fixtures before use in broad language packs.
Snippets are built from normalized field text, not from full-fidelity rendered
HTML, and use a small fixed excerpt window for long fields.
