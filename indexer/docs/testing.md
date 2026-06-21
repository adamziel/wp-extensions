# Testing

Run these commands from the `indexer` directory unless noted otherwise.

## Normal PHP Harness

The main no-WordPress harness exercises analyzer, storage, search, MySQL fake,
WP-CLI fake, and quality tests.

```sh
php tests/run.php
```

Composer exposes the same default harness:

```sh
composer test
```

The harness discovers `tests/quality/*.php` automatically and enforces the
default minimum check count.

## No-Extension Smoke Test

Run the PHP harness with PHP extensions disabled:

```sh
php -n tests/run.php
```

This verifies the fallback paths used when optional extensions are missing.

## Release Readiness

Run the release-readiness contracts directly when changing packaging,
operator docs, or public-distribution policy:

```sh
php tests/quality/release-readiness-contracts.php
php -n tests/quality/release-readiness-contracts.php
```

Before publishing a direct-install ZIP, run the readiness checker from the
monorepo root:

```sh
php indexer/tools/check-release-readiness.php --target=direct-install
php indexer/tools/check-release-readiness.php --target=public-submission
```

`direct-install` proves the supported `indexer/` ZIP path and production
dependency boundary; unchanged default runs must produce identical JSON.
`public-submission` is a separate authority gate and is expected to fail on
current main until WordPress.org-style `readme.txt`, GPL-compatible package
license evidence, valid directory assets, public redistribution policy, and
public-submission authority evidence are supplied and reviewed.

## Analyzer Language Quality

Run the analyzer language corpus directly when changing language tokenization,
normalization, or CJK fallback behavior:

```sh
php tests/quality/analyzer-language-corpus.php
```

Chinese/CJK fallback expectations cover one-character runs plus deterministic
overlapping n-grams up to 4 characters. The main harness also covers the
optional source-backed Jieba Chinese segmenter with project-owned synthetic
fixture rows: configured long-word matching, retained unknown/subword n-gram
recall, missing/hash-mismatched source fallback, sandbox defaults, and analyzer
signature changes when the verified source hash changes. The same focused lane
covers the current Bengali and Urdu light suffix baselines, their analyzer
signatures, and language-partition isolation.

## Native BM25 Reference Gate

The deterministic BM25 gate is included in the main PHP harness and can also be
run directly for a focused JSON report:

```sh
php tests/bm25-reference-gate.php --json
composer test:bm25-reference
```

It indexes a fixed four-document field fixture through the production native
indexer/searcher and compares weighted postings, OR rankings, AND narrowing, and
scores against a local Lucene-style BM25 oracle. This proves the native scoring
boundary for a small auditable case; it does not replace the broader native
relevance fixture or the optional external Python/library reference.

## Cranfield Relevance Quality Gate

The main harness includes source-shaped Cranfield parser and metric tests using
project-owned synthetic fixtures. The full Cranfield relevance-quality gate is
explicitly pending unless an operator supplies a local corpus path:

```sh
php tests/cranfield-relevance-gate.php
WP_FTS_CRANFIELD_DIR=/path/to/cranfield php tests/cranfield-relevance-gate.php
WP_FTS_CRANFIELD_DIR=/path/to/cranfield php tests/cranfield-relevance-gate.php --json
```

The gate expects local documents, queries, and relevance judgments. In a
directory, the accepted classic names are `cran.all.1400` or `cran.all`,
`cran.qry`, and `qrels.text`, `cranqrel`, `qrels.txt`, or `cran.qrel`.
It does not download data and this repository does not bundle the full
Cranfield corpus until redistribution license and provenance are reviewed.
Without local data the command exits with pending/NO-GO status `2`, and the
main harness reports the full-data check as `[PEND]` rather than silently
passing it.

Build a reusable native relevance suite JSON from local source files when a CI
or review lane wants to separate import from scoring:

```sh
php tools/build-cranfield-relevance-suite.php \
  --cranfield-dir=/path/to/cranfield \
  --out=/tmp/wp-fts-cranfield-suite.json
php tests/cranfield-relevance-gate.php \
  --suite=/tmp/wp-fts-cranfield-suite.json \
  --json
```

The gate indexes the parsed corpus through the production analyzer, indexer,
storage, and searcher path, then compares native rankings with a local
Lucene-style BM25 reference over the same analyzer terms. It reports nDCG@10,
MAP, and P@5 for both native and reference results plus absolute deltas.
Allowed deltas default to `0.05` and can be overridden with
`WP_FTS_CRANFIELD_MAX_NDCG_DELTA`, `WP_FTS_CRANFIELD_MAX_MAP_DELTA`, and
`WP_FTS_CRANFIELD_MAX_PRECISION_AT_5_DELTA`, or the matching CLI flags.

## Native Relevance Gold Benchmark

The main harness includes the committed native relevance fixture automatically.
Run the evaluator directly when you need the per-query metrics table:

```sh
php tests/relevance-benchmark.php --suite=tests/fixtures/relevance/native-core.json
php tests/relevance-benchmark.php --suite=tests/fixtures/relevance/native-core.json --json
php -n tests/relevance-benchmark.php --suite=tests/fixtures/relevance/native-core.json
```

The fixture is a modest regression gate for the current analyzer/searcher
contract. It reports recall@5, precision@5, MRR, nDCG@5, and cross-language
false positives; it is not a production relevance-quality claim.

## Native Production-Scale Generated Benchmark

The main harness includes the PR-safe native production-scale benchmark gates.
Run the benchmark directly when you need the indexed-document, token, postings,
materialized-row, result-window hydration, and memory-delta counters:

```sh
php tests/production-scale-benchmark.php
php tests/production-scale-benchmark.php --profile=expanded
php tests/production-scale-benchmark.php --json
php -n tests/production-scale-benchmark.php
```

Both profiles generate deterministic WordPress-shaped documents across title,
body, excerpt, and content fields. The benchmark is pure-PHP generated evidence
only: it does not use live MySQL, does not replay production traffic, and does
not commit generated corpora, caches, logs, or archives.

## Large Search Corpus Generator

Use the large corpus generator when you need deterministic multilingual JSONL
shards for demos or external benchmark indexing:

```sh
php tools/generate-large-search-corpus.php \
  --output=/home/claude/indexer/.cao/generated/search-corpus-v1 \
  --seed=wp-fts-large-search-corpus-v1 \
  --english-docs=100000 \
  --per-language-docs=30000
```

The focused tests are discovered by the main harness in
`tests/quality/search-corpus-generator.php` and can also be run directly:

```sh
php tests/search-corpus-generator.php
php -n tests/search-corpus-generator.php
```

The default language scope and output contract are documented in
[`docs/search-corpus-generator.md`](search-corpus-generator.md). Generated
corpora are rebuildable artifacts and should stay under `.cao/generated/` or
another untracked output directory. Length-focused tests cover the 200-token
floor, the roughly 750-word modal bucket, and the deterministic 5,000+ token
long tail.

## Analyzer Source-Lock Manifests

Analyzer, stemmer, tokenizer, and lemmatizer packs must have a source-lock
manifest before real lexical data is imported. Validate the committed synthetic
no-op fixture and any future manifests with:

```sh
php tools/validate-analyzer-source-lock.php
php tools/validate-analyzer-source-lock.php tests/fixtures/analyzer-source-locks/noop-en.source-lock.json
```

The normal PHP harness also includes the source-lock quality test through
`tests/quality/*.php` discovery.

## Polish Analyzer Pack Validation

Validate the opt-in Polish Morfologik/PoliMorf fixture pack directly:

```sh
php tools/validate-analyzer-pack.php resources/analyzer-packs/pl-morfologik-polimorf-fixture/manifest.json
php -n tools/validate-analyzer-pack.php resources/analyzer-packs/pl-morfologik-polimorf-fixture/manifest.json
```

The validator checks manifest shape, runtime row normalization, duplicate rows,
ambiguous no-op handling, and declared checksums for the bundled fixture pack.

Validate the generic non-Polish lemma-pack runtime with the bundled synthetic
Bengali contract fixture:

```sh
php tools/validate-analyzer-pack.php resources/analyzer-packs/bn-synthetic-lemma-fixture/manifest.json
php -n tools/validate-analyzer-pack.php resources/analyzer-packs/bn-synthetic-lemma-fixture/manifest.json
```

This fixture is project-owned artificial data for runtime-contract testing
only. It does not claim Bengali dictionary quality, Bengali lemmatization, or
source-backed lexical coverage.

Validate the bundled UniMorph-derived top-language packs with normal PHP gzip
support:

```sh
for manifest in resources/analyzer-packs/*-unimorph-*/manifest.json; do
  php tools/validate-analyzer-pack.php "$manifest"
done
php tools/audit-top-language-lemma-packs.php \
  --pack-root=resources/analyzer-packs \
  --json \
  --require-pack-backed
```

Those packs are source-backed, opt-in, default-disabled, and carry their
upstream license/provenance in each pack manifest and source lock. Run the
next-language support harness when changing Russian, German, Japanese, Korean,
Telugu, Turkish, Italian, Persian, Ukrainian, or Dutch routing and packs:

```sh
php tests/quality/next-10-language-support.php
php -n tests/quality/next-10-language-support.php
```

Chinese, Japanese, and Korean are tokenizer lanes rather than missing UniMorph
lemma packs. Chinese can optionally use Jieba; Japanese and Korean currently
use deterministic fallback n-grams. Source repositories are pinned as git
submodules, not copied dictionary rows:

```sh
git submodule update --init --recursive indexer/resources/sources/jieba
git -C indexer/resources/sources/jieba rev-parse HEAD
sha256sum indexer/resources/sources/jieba/jieba/dict.txt
wc -c indexer/resources/sources/jieba/jieba/dict.txt
```

The expected commit is `67fa2e36e72f69d9134b8a1037b83fbb070b9775`, SHA-256 is
`7197c3211ddd98962b036cdf40324d1ea2bfaa12bd028e68faa70111a88e12a8`, and byte
size is `5071852`. Urdu is audited as license-blocked until `unimorph/urd` has
clear redistribution evidence, so no generated Urdu pack is bundled.

Japanese and Korean pack experiments should stay external until a source-backed
word segmenter is wired into the PHP pipeline. Initialize the pinned sources,
build any trial runtime into an external directory, validate it there, and only
promote it after `tests/quality/next-10-language-support.php` proves that
document/query variants meet through the configured tokenizer or pack:

```sh
git submodule update --init --recursive \
  indexer/resources/sources/unimorph/jpn \
  indexer/resources/sources/unimorph/kor
php tools/validate-analyzer-pack.php /srv/wp-fts-packs/example-ja-or-ko/manifest.json
```

Run the package-safe external pack workflow tests directly when changing the
builder, importer options, validation boundary, or docs:

```sh
php tests/quality/polish-polimorf-external-pack-workflow.php
php -n tests/quality/polish-polimorf-external-pack-workflow.php
```

Those focused tests use the synthetic source-shaped PoliMorf fixture. They
cover local fixture builds, source hash and byte-count mismatches, missing
download acknowledgement, non-empty output refusal, generated pack validation,
lemmatizer lookup from the generated pack, and the no-runtime-network-access
boundary.

Build the synthetic fixture pack from the command line into a disposable
external directory:

```sh
php tools/build-polish-polimorf-external-pack.php \
  --source=tests/fixtures/polimorf-importer/sample-polimorf.tab \
  --out=/tmp/pl-polimorf-fixture-external \
  --expect-source-sha256="$(php -r 'echo hash_file("sha256", "tests/fixtures/polimorf-importer/sample-polimorf.tab");')" \
  --expect-source-bytes="$(php -r 'echo filesize("tests/fixtures/polimorf-importer/sample-polimorf.tab");')" \
  --pack-id=pl-polimorf-external-fixture \
  --version=fixture-external-v1 \
  --source-url=urn:wp-fts:test:polimorf-external-pack \
  --source-name="WP FTS source-shaped PoliMorf external pack fixture" \
  --source-version=fixture \
  --max-rows-per-file=2 \
  --chunk-rows=2
php tools/validate-analyzer-pack.php \
  /tmp/pl-polimorf-fixture-external/manifest.json
```

Run the full external builder against a disposable local copy of the CLARIN-PL
artifact, never inside the repository:

```sh
php tools/build-polish-polimorf-external-pack.php \
  --source=/tmp/polimorf-20180722.tab.gz \
  --out=/tmp/pl-polimorf-20180722-full
php tools/validate-analyzer-pack.php \
  /tmp/pl-polimorf-20180722-full/manifest.json --metadata-only
```

The builder can optionally download the approved public artifact only with an
explicit license acknowledgement:

```sh
php tools/build-polish-polimorf-external-pack.php \
  --download \
  --cache-dir=/tmp/wp-fts-polimorf-cache \
  --out=/tmp/pl-polimorf-20180722-full \
  --acknowledge-license=BSD-2-Clause
```

Repeat the build into a second disposable directory and compare `manifest.json`,
`SOURCE.lock.json`, and runtime shards when changing importer logic. The source
archive, extracted TSV, generated runtime pack, cache files, and temporary files
are third-party or generated data and must not be committed or bundled.

## Polish Verified Stemmer Fixtures

The opt-in Polish verified stemmer slice has a standalone fixture validator:

```sh
php tests/polish-verified-stemmer-fixtures.php
php -n tests/polish-verified-stemmer-fixtures.php
```

The same fixture rows are also covered by `tests/run.php` through
`tests/quality/polish-verified-stemmer.php`.

## Explicit Check Gate

The integrated quality harness is expected to meet at least 1500 checks:

```sh
WP_FTS_MIN_CHECKS=1500 php tests/run.php
```

Use a higher number only when a lane intentionally raises the target.

## Snowball Compliance

The Snowball harness compares supported stemmers against a local checkout of the
official Snowball data.

```sh
SNOWBALL_DATA_DIR=/home/claude/.cache/snowball-data php tests/snowball-compliance.php
```

Composer also exposes:

```sh
SNOWBALL_DATA_DIR=/home/claude/.cache/snowball-data composer test:snowball
```

The harness reports unsupported Snowball languages as skipped. Skips are
expected for languages that are not advertised by `WP_FTS_SnowballStemmer`.
Arabic (`ar`), English (`en`), Spanish (`es`), French (`fr`), Hindi (`hi`),
Portuguese (`pt`), and Indonesian (`id`) should pass from the bundled generated
Snowball implementations even in a bare checkout; Wamania-backed Catalan (`ca`)
and Dutch Porter (`nl`) skip when optional Composer dependencies are absent.
With the current official Snowball data checkout, a source tree without `vendor/`
should report `7 pass, 30 skip, 0 fail`; after installing production
dependencies from `composer.lock`, Arabic, English, Spanish, French, Hindi,
Portuguese, Indonesian, Catalan, and Dutch Porter should pass, for
`9 pass, 28 skip, 0 fail`.

The bundled Arabic, Hindi, Portuguese, and Indonesian ports also have direct
full-fixture validators:

```sh
php tests/arabic-snowball-fixtures.php
php -n tests/arabic-snowball-fixtures.php
php tests/hindi-snowball-fixtures.php
php -n tests/hindi-snowball-fixtures.php
php tests/portuguese-snowball-fixtures.php
php -n tests/portuguese-snowball-fixtures.php
php tests/indonesian-snowball-fixtures.php
php -n tests/indonesian-snowball-fixtures.php
```

## Polish Lemmatizer Source-Lock Pilot

The Polish source-lock pilot verifies metadata gates for a future
Morfologik-style lemmatizer candidate without downloading or committing lexical
data:

```sh
php tests/quality/polish-lemmatizer-source-lock.php
```

The main harness discovers the same verifier automatically.

## Tokenizer Source-Lock Verifier

Run the Thai tokenizer source-lock verifier directly when changing the
source-lock schema, fixtures, or future candidate metadata:

```sh
php tools/verify-tokenizer-source-lock.php --allow-test-fixtures tests/fixtures/tokenizer-source-lock/complete-test-fixture.json
php tools/verify-tokenizer-source-lock.php --expect-invalid tests/fixtures/tokenizer-source-lock/incomplete-missing-approval.json
```

The committed fixtures are metadata-only. They do not include Thai dictionary
rows, TCC rules, third-party tokenizer data, or a production tokenizer adapter.

## Thai Tokenizer Source-Candidate Lock

Run the metadata-only Thai tokenizer source-candidate verifier when changing the
candidate lock, schema, or future source-lock docs:

```sh
php indexer/tools/verify-thai-tokenizer-source-candidate-lock.php \
  indexer/review-artifacts/source-locks/thai-tokenizer-source-candidate-preflight.json \
  --allow-pending-exact-values
```

This preflight does not currently ship real Thai segmentation. It records the
preferred source family and the exact artifact, license, source-chain, and
clean-room fields still missing before adapter work. No dictionary rows,
TCC/TCC+ rules, or tokenizer adapter are committed.

## WordPress Playground SQLite Smoke

Run the committed Playground smoke from the repository worktree root:

```sh
npx @wp-playground/cli@latest run-blueprint --blueprint=indexer/playground/sqlite-smoke-blueprint.json --mount="$(pwd)/indexer:/wordpress/wp-content/plugins/indexer" --blueprint-may-read-adjacent-files
```

The smoke activates the mounted `indexer` plugin in WordPress Playground,
asserts SQLite runtime evidence, inserts a small multilingual post set, indexes
through `WP_FTS_Indexer`, and searches through `WP_FTS_Searcher`. It probes
Polish stemming/detection, German detection, explicit language override, and
fallback behavior for text without detector evidence. It also covers the public
REST search route (`q`, `query`, invalid `mode`, missing query, and visible
result refill after hidden stale rows) plus WP-CLI `wp fts reindex` and
`wp fts search` when the Playground WP-CLI library is available.

## Optional BM25 Python Reference

Run the Python reference only when the environment has the optional virtualenv
and native library path used by the hardening contract:

```sh
LD_LIBRARY_PATH=/nix/store/f2q5ld1nipl8w1r2w8m6azhlm2varqgb-zlib-1.3.1/lib:/nix/store/cf1a53iqg6ncnygl698c4v0l8qam5a2q-gcc-14.3.0-lib/lib /home/claude/.cache/indexer-bm25s-venv/bin/python tests/bm25_lucene_reference.py
```

If `bm25s` is not installed, the script exits as an explicit optional skip.

## PHP Syntax Check

Run a syntax pass over source and tests:

```sh
find . -path ./vendor -prune -o -name '*.php' -print0 | xargs -0 -n1 php -l
```

## WordPress And MySQL Integration Harness

This branch includes an optional real WordPress/MySQL integration harness and a
quality skip-contract that proves the harness exits clearly when WordPress is
not configured. The real harness is composer-addressable:

```sh
composer test:integration:real
```

Configure it only against a disposable WordPress database, not a production
database. In the default unconfigured environment, `tests/run.php` still uses
MySQL and WP-CLI fakes/contracts for broad coverage and the real integration
contract safely reports an explicit skip.

## Real MySQL Production-Path Proof

Use the disposable Docker lane when Docker image pulls and the local daemon are
available:

```sh
tools/run-real-mysql-production-proof.sh
```

The helper copies this plugin into a temporary directory, installs Composer
dependencies there, starts WordPress on MariaDB, activates the plugin, runs the
existing real-MySQL integration harness, and then runs the production-path proof.
The proof writes only sanitized evidence: source SHA, MySQL/MariaDB runtime,
InnoDB table engines, WP-CLI/REST probe status, row counts, EXPLAIN JSON, and
timing summaries. It destroys the Docker volume and temporary plugin copy on
exit.

For an already installed disposable WordPress site backed by MySQL/MariaDB, run:

```sh
WP_FTS_WP_PATH=/path/to/wordpress \
WP_FTS_WP_CLI=wp \
WP_FTS_WP_URL=http://127.0.0.1:8088 \
WP_FTS_PROOF_HTTP_BASE=http://127.0.0.1:8088 \
WP_FTS_MYSQL_PROOF_ALLOW_DISPOSABLE=1 \
php tests/integration/real-mysql-production-proof.php
```

Do not set `WP_FTS_MYSQL_PROOF_ALLOW_DISPOSABLE=1` for production or shared
staging data. Without that explicit opt-in the proof exits with `SKIP:`.

## Diff And Status Checks

From the repository worktree root, always run:

```sh
git diff --check
git status --short --branch
```
