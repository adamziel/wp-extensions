# Polish Lemmatizer Source-Lock Pilot

This document defines the first repository-side source-lock pilot for a future
Polish lemmatizer pack. It is a guardrail, not a runtime pack.

The current `pl` behavior remains the local conservative suffix fallback in
`WP_FTS_PolishStemmer`. It folds Polish diacritics through the normalizer and
removes a small set of suffixes only when the remaining term stays meaningful.
It is not a verified Stempel stemmer, a Morfologik dictionary lemmatizer, a
PoliMorf import, or an SGJP-derived lexical pack.

## Pilot Fixture

The pilot metadata lives at:

```text
indexer/tests/fixtures/source-lock/polish-lemmatizer-pilot.json
```

The fixture records one Morfologik-style Polish dictionary candidate family and
keeps exact source values pending until a future task is allowed to select and
verify one upstream artifact. It intentionally records:

- no runtime pack;
- no dictionary rows;
- no raw source archive;
- no generated lemmatizer data.

The pending markers are part of this pilot. They mean a future source-lock task
must fill exact values before implementation starts.

## Required Locks Before Import

A real Polish lemmatizer task must replace the pending markers with exact,
reviewed values:

- source identity: one canonical upstream artifact, version, commit, or release;
- license: SPDX ID, license URL, exact license text or committed notice path,
  and redistribution status;
- artifact hash: SHA-256 and byte count for every approved source artifact;
- importer: repository-local command, importer commit, deterministic sort order,
  row count, runtime digest, and analyzer signature component;
- provenance: stable source/provenance ID used by runtime manifests and tests;
- ambiguity policy: deterministic behavior for forms with multiple accepted
  lemmas;
- no-op fallback policy: unsupported languages, missing/invalid packs, OOV
  forms, and unresolved ambiguity return the original normalized term.

The pack must remain fixture-only or experimental until these locks, compliance
fixtures, packaging notices, runtime digest checks, and reindex-signature proof
all pass review.

## Verifier

Run the standalone verifier from the repository worktree root:

```sh
php indexer/tests/quality/polish-lemmatizer-source-lock.php
```

The normal quality harness also discovers the verifier:

```sh
php indexer/tests/run.php
php -n indexer/tests/run.php
```

The verifier rejects missing source identity, license URL/text, artifact hash,
importer command, provenance ID, ambiguity policy, and no-op fallback policy. It
also rejects accidental committed dictionary/runtime keys in the pilot fixture.

## Claim Boundary

Documentation and release notes must continue to distinguish bundled Polish
runtime support from externally generated packs. Current runtime behavior keeps
the bundled compressed full Polish pack when gzip support is available, falling
back to the bundled fixture pack and then conservative stemming when no valid
pack is active. A separate full PoliMorf pack can still be generated with the
external builder after verifying the approved source artifact, but the source
archive and extracted TSV are not committed or bundled, and externally generated
pack copies remain opt-in/default-disabled until an operator installs and
configures them.
