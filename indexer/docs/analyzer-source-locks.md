# Analyzer Source-Lock Manifests

Analyzer, stemmer, tokenizer, and lemmatizer packs must be source-locked before
any real lexical data is imported. A source-lock manifest is a review artifact:
it records the upstream source, legal metadata, importer, runtime digest,
fixture gate, no-op behavior, and exact claim boundary for a future pack.

This scaffold intentionally includes only a synthetic no-op fixture under
`tests/fixtures/analyzer-source-locks/`. It does not download, vendor, or import
Stempel, Morfologik, PoliMorf, WordNet, OEWN, Snowball data, or any other
third-party lexical dataset.

Runtime lemma packs use the local analyzer-pack manifest plus normalized
surface-to-lemma TSV rows. New source-approved packs should use the neutral
`wp-fts-lemma-tsv-v1` runtime format and declare the manifest `language` they
serve. The committed synthetic Bengali fixture only proves that runtime
contract; it is not a source lock for real Bengali lexical data.

## Required Manifest Fields

Use schema version `wp-fts-analyzer-source-lock/v1`. The verifier requires these
fields:

- `pack.id`: stable lowercase pack id.
- `pack.language`: BCP 47-style language tag for the pack boundary.
- `pack.kind`: one of `analyzer`, `lemmatizer`, `normalizer`, `noop`,
  `stemmer`, or `tokenizer`.
- `pack.status`: one of `fixture`, `experimental`, or
  `production_candidate`.
- `source.name`, `source.url`, `source.version`, and
  `source.artifact_sha256`: exact upstream identity and artifact hash. Fixture
  manifests may use a synthetic `urn:` source and committed synthetic artifact.
- `source.license.spdx_id` and `source.license.notice_path`: license identifier
  and retained notice path.
- `importer.command` and `importer.commit`: deterministic import command and
  code revision used to produce the runtime file. Synthetic no-op fixtures may
  mark these not applicable, but real packs must use exact commands and SHAs.
- `analyzer.signature`: analyzer contract identity that will participate in
  stale-document detection.
- `analyzer.runtime_digest_sha256`: digest of the runtime artifact included in
  the analyzer signature.
- `runtime.path`, `runtime.digest_sha256`, `runtime.row_count`, and
  `runtime.contains_third_party_data`: runtime artifact identity and data
  boundary.
- `behavior.noop`, `behavior.oov_policy`, and `behavior.ambiguity_policy`:
  explicit behavior for unsupported, OOV, and ambiguous forms.
- `compliance.fixture_path`: expected compliance fixture used by the review
  gate.
- `release.default_enabled` and `release.claim_boundary`: whether the pack may
  be enabled automatically and the exact public claim allowed by the evidence.

## Verification

Validate committed source-lock manifests with:

```sh
php tools/validate-analyzer-source-lock.php
```

Validate a specific manifest with:

```sh
php tools/validate-analyzer-source-lock.php tests/fixtures/analyzer-source-locks/noop-en.source-lock.json
```

The verifier checks JSON shape, required fields, digest syntax, local fixture
paths, committed artifact hashes, no-op row-count boundaries, default-enable
rules, and compliance fixture shape. `tests/run.php` also loads a quality test
that exercises the valid fixture and unsafe no-op metadata.

## Import Boundary

A real analyzer or lemmatizer import cannot start until the manifest names one
canonical source artifact and the review has approved:

- exact upstream URL, version, tag, commit, and artifact SHA-256;
- license and notice files that are compatible with release packaging;
- deterministic importer command and importer commit;
- normalized runtime digest and analyzer signature;
- source-shaped compliance fixtures that include positives, OOV cases,
  ambiguity policy cases, case/diacritic normalization cases, and
  query/document/search parity where applicable;
- a release claim boundary that lists only behavior proven by the fixture gate;
- default-enable status left false until a separate production gate approves
  source, legal, performance, and regression evidence.

Unsupported languages and packs with missing source locks must keep the current
no-op behavior and must not expand documentation or release claims.
