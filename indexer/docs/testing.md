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
default minimum check count. Operator-owned Snowball, Cranfield, and full
PoliMorf corpora are separate lanes: the normal harness runs their hermetic
contracts, but reads those corpora only when their documented environment
variables are set. The required extension-enabled CI matrix also sets
`WP_FTS_FAIL_ON_PENDING=1`, so a newly deferred test cannot pass the gate
silently.

## No-Extension Smoke Test

Run the PHP harness with PHP extensions disabled:

```sh
php -n tests/run.php
```

This verifies the fallback paths used when shared optional extensions are
missing. Some PHP builds compile zlib or other capabilities into the binary, so
the deterministic gzip-unavailable contract also uses the generator's existing
capability seam instead of assuming `php -n` removed a compiled-in extension.

## Provider Compatibility Evidence

Run the deterministic provider compatibility contract directly when changing
search-provider precedence controls, `posts_pre_query` diagnostics, known
provider advisory labels, or provider compatibility documentation:

```sh
php tests/quality/provider-compatibility-certification-contracts.php
php -n tests/quality/provider-compatibility-certification-contracts.php
composer test:provider-compatibility
```

The optional live/disposable smoke exits with `SKIP:` and status 0 when no
WordPress path is configured. It is not a broad version-by-version certification
of third-party providers:

```sh
php tools/smoke-search-provider-compatibility.php
composer test:smoke:provider-compatibility
```

See [`docs/provider-compatibility.md`](provider-compatibility.md) for the
provider interference matrix, certification boundary, and disposable-site
configuration. The matrix covers repo-owned provider-family simulations for a
theme/custom earlier provider in `respect_existing` mode, a SearchWP-shaped
earlier provider in `prefer_fts` mode, a Relevanssi-shaped later provider, and
Jetpack Search / Jetpack plus ElasticPress advisory signals. It is not a broad
version-by-version certification of those providers, themes, or custom
callbacks.

## Release Readiness

Run the release-readiness contracts directly when changing packaging,
operator docs, or public-distribution policy:

```sh
php tests/quality/release-readiness-contracts.php
php -n tests/quality/release-readiness-contracts.php
```

When `ZipArchive` is unavailable, the no-extension run reports the ZIP-building
contract as `[PEND]` and exits cleanly instead of treating the missing extension
as a fatal error.

Before publishing a direct-install ZIP, run the readiness checker from the
monorepo root:

```sh
php indexer/tools/check-release-readiness.php --target=direct-install
php indexer/tools/check-release-readiness.php --target=public-submission
```

`direct-install` proves the supported `indexer/` ZIP path and production
dependency boundary; unchanged default runs must produce identical JSON. The
default direct-install build directory is protected by an advisory lock, so
overlapping readiness runs serialize around staging and validation instead of
racing on the same temporary package tree.
`public-submission` is a separate authority gate and is expected to fail on
current main until WordPress.org-style `readme.txt`, GPL-compatible package
license evidence, valid directory assets, public redistribution policy, and
public-submission authority evidence are supplied and reviewed.

The public asset contracts cover the required WordPress.org-style PNGs:
`assets/banner-772x250.png` must be exactly 772 by 250 pixels and
`assets/icon-128x128.png` must be exactly 128 by 128 pixels. The focused
release-readiness tests reject malformed or non-PNG files with those names,
1x1 fixtures, wrong dimensions, and blank single-color placeholder images.

## Release Evidence Bundle

Use the release evidence collector when a release review needs one sanitized
current-checkout report instead of separate command transcripts:

```sh
php tools/collect-release-evidence.php --release-target=direct-install
composer release:evidence
php -n tools/collect-release-evidence.php
```

The default collector target is `direct-install`, matching the documented
direct-install ZIP release boundary. It records Git source metadata, keeps the
direct-install readiness build lane blocked until explicitly opted in, captures
public-submission blockers as non-target evidence, runs the optional
WordPress/MySQL lanes only through their existing skip-first guards, and runs
the PR-safe pure-PHP production-scale benchmark, including conservative
generated-corpus performance budget gates for index and search/read timings.
The JSON statuses are:

- `pass`: the lane completed and its evidence passed.
- `skip`: the lane was not configured or was intentionally deferred.
- `unavailable`: the lane was explicitly requested but required disposable
  evidence inputs, such as a previous direct-install package, were missing or invalid.
- `blocked`: the lane found expected release policy blockers.
- `fail`: the lane attempted to run and failed unexpectedly.

The report proves the current checkout's release evidence posture for the
selected release target, disposable WordPress smoke readiness,
provider-compatibility smoke readiness, real WordPress/MySQL proof availability,
real MySQL production proof availability, public-submission blockers, and the
generated-corpus benchmark's structural and performance-budget gates. It does
not create a release ZIP by default, does not approve WordPress.org/SVN
submission, does not replace a configured disposable WordPress/MySQL run, and
does not prove live MySQL behavior or live production traffic.

Optional write-enabled evidence remains explicit:

```sh
php tools/collect-release-evidence.php \
  --release-target=direct-install \
  --run-direct-install-readiness
php tools/collect-release-evidence.php \
  --release-target=direct-install \
  --direct-package-dir=/path/to/staged/indexer
php tools/collect-release-evidence.php \
  --release-target=direct-install \
  --run-docker-disposable-smokes
php tools/collect-release-evidence.php \
  --release-target=direct-install \
  --run-docker-lifecycle-smokes
php tools/collect-release-evidence.php \
  --release-target=direct-install \
  --run-docker-upgrade-multisite-smoke \
  --previous-direct-package=/path/to/previous-wp-fts-indexer.zip
php tools/collect-release-evidence.php \
  --release-target=direct-install \
  --run-docker-upgrade-multisite-smoke \
  --previous-direct-package-ref=PREVIOUS_LOCAL_REF_OR_SHA
WP_FTS_EVIDENCE_RUN_REAL_WORDPRESS_MYSQL=1 php tools/collect-release-evidence.php
```

Use the public-submission target when the review is explicitly about
WordPress.org/SVN or broader public-marketplace submission readiness:

```sh
php tools/collect-release-evidence.php --release-target=public-submission
```

That target is expected to stay `blocked` until the project resolves product
policy, public assets, license, readme, and authority-evidence requirements.
Do not use the direct-install or real WordPress/MySQL opt-ins against production
or shared staging data. A `pass` for `--release-target=direct-install` is only
evidence for the supported direct-install ZIP path; it does not approve public
submission.

## Disposable Release Smoke

Run the disposable WordPress release smoke only against a throwaway WordPress
site. It installs and activates a direct-install ZIP, checks operator status,
repairs schema without indexing content, creates one generated post fixture,
runs one bounded indexing batch, verifies `wp fts search --format=json`, and
then deletes only that generated fixture.

The command exits with `SKIP:` and status 0 when WP-CLI or `WP_FTS_WP_PATH` is
not configured, when `WP_FTS_WP_PATH` is not an installed WordPress root, or
when the explicit disposable-site write guard is absent:

```sh
composer test:smoke:release
```

Minimal disposable-site run with an existing release ZIP:

```sh
touch /path/to/wordpress/.wp-fts-disposable-smoke
WP_FTS_WP_PATH=/path/to/wordpress \
WP_FTS_WP_CLI=wp \
WP_FTS_RELEASE_ZIP=/path/to/wp-fts-indexer.zip \
WP_FTS_DISPOSABLE_SMOKE_ALLOW=1 \
php tools/smoke-disposable-wordpress-release.php
```

If `WP_FTS_RELEASE_ZIP` or `--zip=PATH` is omitted, the smoke builds a temporary
direct-install ZIP with `tools/build-release-zip.php` from the current checkout
and removes the temporary build directory on exit. For disposable roots where a
marker file is inconvenient, set
`WP_FTS_DISPOSABLE_SMOKE_CONFIRM_PATH=/path/to/wordpress` to the exact same
real path as `WP_FTS_WP_PATH`.

Do not set `WP_FTS_DISPOSABLE_SMOKE_ALLOW=1` for production or shared staging
data. The smoke is an operator-facing release/package proof; it does not
replace the normal PHP harness, the Playground SQLite smoke, or the optional
real WordPress/MySQL integration and production-path proofs.

## Docker Disposable Release/Provider Smoke

Use the Docker-backed wrapper when a release review needs direct-install
release smoke and provider-compatibility smoke evidence but no host-provided
WordPress root is configured:

```sh
tools/run-disposable-release-provider-smoke.sh
composer test:smoke:release-provider:docker
```

The wrapper builds the direct-install ZIP in temporary storage, starts
disposable WordPress and MariaDB containers, installs WordPress, creates the
existing smoke marker files, runs `tools/smoke-disposable-wordpress-release.php`
against the ZIP, deactivates that installed release, and then runs
`tools/smoke-search-provider-compatibility.php` against the same disposable
WordPress database. It destroys the Docker containers, volume, temporary source
copy, temporary release ZIP, and compose file on success and failure.

This differs from the host-provided WordPress root smoke: operators do not need
to provide `WP_FTS_WP_PATH`, host WP-CLI, or a host database, and the wrapper
sets the inner write guards only for its disposable container root. It is still
optional release evidence, not a public-submission approval, not a
WordPress.org/SVN packaging step, and not a broad version-by-version
certification of third-party provider plugins. The provider smoke evidence is
the same repo-owned provider-family simulations described in the provider
interference matrix documentation.

The release evidence collector records this Docker lane as skipped by default.
Run it only when Docker image pulls and the local daemon are available:

```sh
php tools/collect-release-evidence.php \
  --release-target=direct-install \
  --run-docker-disposable-smokes
```

## Docker Disposable Lifecycle Smoke

Use the Docker-backed lifecycle wrapper when a release review needs
direct-install/operator lifecycle evidence without targeting a host-provided
WordPress root:

```sh
tools/run-disposable-lifecycle-smoke.sh
composer test:smoke:lifecycle:docker
```

The wrapper starts disposable WordPress and MariaDB containers, installs a
source-copy plugin with production Composer dependencies, marks the WordPress
root with `.wp-fts-lifecycle-smoke`, and runs
`tools/smoke-disposable-wordpress-lifecycle.php`. The inner runner is also
skip-first for host-provided disposable sites:

```sh
touch /path/to/wordpress/.wp-fts-lifecycle-smoke
WP_FTS_WP_PATH=/path/to/wordpress \
WP_FTS_WP_CLI=wp \
WP_FTS_LIFECYCLE_SMOKE_ALLOW=1 \
php tools/smoke-disposable-wordpress-lifecycle.php
composer test:smoke:lifecycle
```

The smoke proves that activation creates the FTS schema, repair restores a
missing plugin table, activation and repair do not index pre-existing content
or create demo posts, `wp fts status --format=json` and
`wp fts repair --format=json` report schema state, deactivation clears
scheduled queue processing while retaining index tables/data. It proves
deactivation clears scheduled queue processing, and uninstall clears plugin-owned operational options and queue state and retains the `fts_*` tables and data. It
does not build a ZIP, does not create
public-submission artifacts, and is not public-submission readiness.

The release evidence collector records this Docker lifecycle lane as skipped by
default:

```sh
php tools/collect-release-evidence.php \
  --release-target=direct-install \
  --run-docker-lifecycle-smokes
```

Multisite lifecycle proof is explicitly not run by this lane. The command and
collector record that boundary instead of treating single-site proof as
multisite evidence.

## Docker Disposable Upgrade/Multisite Smoke

Use the Docker-backed upgrade wrapper when a direct-install release review needs
runtime evidence that the current package upgrades cleanly from a previous
direct-install package:

```sh
tools/run-disposable-upgrade-multisite-smoke.sh --previous-package=/path/to/previous-wp-fts-indexer.zip
composer test:smoke:upgrade-multisite:docker -- --previous-package=/path/to/previous-wp-fts-indexer.zip
```

The wrapper copies the supplied previous ZIP into temporary storage, builds the
current direct-install ZIP in temporary storage, starts disposable WordPress and
MariaDB containers, installs WordPress as a multisite network, marks the
WordPress root with `.wp-fts-upgrade-smoke`, and runs
`tools/smoke-disposable-wordpress-upgrade.php`. The inner runner
network-activates the previous package, creates disposable fixture content,
indexes and searches that fixture, installs the current package over it,
verifies schema version/status after upgrade, runs repair twice for repair
idempotence after upgrade, checks search continuity and queue health after
upgrade, creates an additional disposable site, proves that non-main site's six
`fts_*` tables use the subsite table prefix, proves subsite
indexing/search/queue/repair behavior, proves the WordPress deletion-table
filter contributes the target site's FTS tables, and deletes generated fixture
posts before the wrapper destroys containers, volumes, temporary roots,
temporary ZIPs, and reports.

The release evidence collector records this Docker upgrade/multisite lane as
skipped by default:

```sh
php tools/collect-release-evidence.php \
  --release-target=direct-install \
  --run-docker-upgrade-multisite-smoke \
  --previous-direct-package=/path/to/previous-wp-fts-indexer.zip
php tools/collect-release-evidence.php \
  --release-target=direct-install \
  --run-docker-upgrade-multisite-smoke \
  --previous-direct-package-ref=PREVIOUS_LOCAL_REF_OR_SHA
```

A missing previous package or previous local ref is reported as `unavailable`,
not as a pass. The `--previous-direct-package-ref` form resolves a local Git
ref/SHA without fetching, rejects the current target commit, requires the
previous ref to contain the release ZIP builder and Composer lockfile, archives
only package source paths into temporary storage, and builds the previous ZIP
with isolated Composer home/auth, an existing local Composer package cache when
available, network access disabled, and credential-capable environment variables
scrubbed before the historical builder or nested Composer process can inherit
them. Previous refs containing Composer auth files such as `indexer/auth.json`
or `indexer/.composer/auth.json` are rejected before checkout/archive. Missing
Docker, Docker Compose, or daemon access remains a wrapper `SKIP:` and is
recorded by the collector as non-pass/unavailable because no disposable runtime
proof was possible. The collector only passes the lane when the decoded wrapper
proof includes `multisite_evidence_status` as `passed`; an upgrade-only report
without multisite runtime evidence is not a pass. This is
direct-install/operator evidence only and is not public-submission readiness.

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
project-owned synthetic fixtures. It treats the missing-corpus response as a
passing hermetic contract rather than a pending test. The separate full
Cranfield relevance-quality command requires an operator-supplied local corpus:

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
Without local data the standalone command exits with pending/NO-GO status `2`.
The main harness verifies that explicit response but does not claim the full
external corpus ran.

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
materialized-row, result-window hydration, memory-delta, and conservative
index/search timing budget counters:

```sh
php tests/production-scale-benchmark.php
php tests/production-scale-benchmark.php --profile=expanded
php tests/production-scale-benchmark.php --json
php -n tests/production-scale-benchmark.php
```

Both profiles generate deterministic WordPress-shaped documents across title,
body, excerpt, and content fields. The benchmark reports bounded query-check
and result-window timings, plus pass/fail performance gates for the generated
corpus. The release evidence collector surfaces those gates in the
`production_scale_benchmark` lane and fails that required lane when benchmark
JSON reports a failed duration gate. This is pure-PHP generated evidence only:
it does not use live MySQL, does not replay production traffic, does not certify
public-submission readiness, and does not commit generated corpora, caches,
logs, or archives.

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

The product-search check against a full generated PoliMorf pack is registered
only when its external manifest is supplied:

```sh
WP_FTS_FULL_POLIMORF_MANIFEST=/path/to/full-pack/manifest.json \
  php tests/quality/rigorous-fts-search-behavior.php
```

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
SNOWBALL_DATA_DIR=/path/to/snowball-data php tests/snowball-compliance.php
SNOWBALL_DATA_DIR=/path/to/snowball-data php tests/quality/external-reference-suite.php
```

There is no machine-specific default. The compliance command exits with status
`2` for an unset or unreadable `SNOWBALL_DATA_DIR`; the normal harness still
runs the hermetic reference checks without pretending the official data was
present.
The focused external-reference command adds sampled official-row and
unsupported-language boundary checks to its local BM25 and analyzer references.

Composer also exposes:

```sh
SNOWBALL_DATA_DIR=/path/to/snowball-data composer test:snowball
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

The bundled Arabic, Spanish, French, Hindi, Portuguese, and Indonesian ports
also have direct full-fixture validators. Point all of them at the same
operator-provided checkout:

```sh
SNOWBALL_DATA_DIR=/path/to/snowball-data
export SNOWBALL_DATA_DIR
php tests/arabic-snowball-fixtures.php
php -n tests/arabic-snowball-fixtures.php
php tests/spanish-snowball-fixtures.php
php -n tests/spanish-snowball-fixtures.php
php tests/french-snowball-fixtures.php
php -n tests/french-snowball-fixtures.php
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

Build the direct-install ZIP, place the smoke Blueprint next to it as a
Blueprint bundle, and run that exact artifact from the repository worktree
root:

```sh
SMOKE="$(mktemp -d)"
trap 'rm -rf "$SMOKE"' EXIT
php indexer/tools/build-release-zip.php \
  --build-dir="$SMOKE/build" \
  --output="$SMOKE/wp-fts-indexer.zip"
cp indexer/playground/sqlite-smoke-blueprint.json "$SMOKE/blueprint.json"
npx @wp-playground/cli@latest run-blueprint \
  --blueprint="$SMOKE/blueprint.json" \
  --blueprint-may-read-adjacent-files
```

The smoke installs and activates the self-contained release ZIP in WordPress
Playground without mounting the source checkout, asserts SQLite runtime
evidence, inserts a small multilingual post set, indexes through
`WP_FTS_Indexer`, and searches through `WP_FTS_Searcher`. It probes Polish
stemming/detection, German detection, explicit language override, and fallback
behavior for text without detector evidence. It explicitly enables and covers
the otherwise-disabled public REST search route (`q`, `query`, invalid
`mode`, missing query, and visible result refill after hidden stale rows) plus
WP-CLI `wp fts reindex` and `wp fts search` when the Playground WP-CLI library
is available.

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
