# Tokenizer Source Locks

The `indexer/` plugin does not currently ship real Thai or general non-space
word segmentation. CJK script runs use the built-in fallback described in
[Limitations](limitations.md): one-character runs stay as one token and longer
runs emit character unigrams plus deterministic overlapping n-grams up to 4
characters. That default path is retrieval-oriented n-gram tokenization, not
dictionary word segmentation.

Chinese (`zh`) has a narrow optional exception: the source tree commits a compact
attested lookup beside the pinned
`components/full-text-search/resources/sources/jieba` gitlink. Release builds
verify that checkout and stage `manifest.json`, `dict.txt`, its MIT license, and
the lookup under the curated runtime path; HMM/POS/IDF/model files and the raw
checkout are not packaged. The
[Jieba runtime manifest](../../components/full-text-search/resources/runtime/jieba/manifest.json)
is the single record for the upstream commit and the dictionary, license, and
lookup identities. A mismatch falls back to CJK n-grams. Custom dictionaries
are not supported.

Future support for `thai_dictionary_tcc_v1` must start with a reviewed source
lock before any adapter, importer, bundled Thai dictionary, generated TCC rules,
or production `th` resource pack is committed.

The committed Thai source-candidate lock is evidence only: no dictionary rows, TCC/TCC+ rules, or tokenizer adapter are committed.

## Required Source-Lock Record

The source lock is a local JSON metadata record with this identity:

```json
{
  "metadata_kind": "tokenizer_source_lock",
  "status": "approved",
  "source_lock_version": 1,
  "tokenizer_id": "thai_dictionary_tcc_v1",
  "tokenizer_type": "thai_tcc_dictionary_segmenter",
  "language_id": "th"
}
```

The record must also include:

- dictionary source identity: source id, source name, exact upstream URL,
  upstream owner, upstream path, immutable tag or commit, retrieval date,
  retrieval method, byte count, SHA-256, data-kind classification, and source
  chain evidence;
- dictionary license identity: license id/name/URL, planned local license text
  file, copyright holder, attribution text, and notice obligations;
- TCC/TCC+ rule source identity: variant, local version id, source id, citation
  or URL, source version/date, source SHA-256, rights basis, and a statement that
  TCC boundaries are subword boundaries, not Thai word segmentation;
- clean-room notes: references consulted, local implementation author, and
  explicit confirmation that upstream implementation code, regexes, grammars, or
  rule tables were not copied;
- normalization and import limits: UTF-8 policy, Unicode normalization choice,
  blank/control rejection, duplicate policy, deterministic sort/tie-break rules,
  maximum source bytes, generated rows, token bytes, and Thai run bytes;
- approval: maintainer/legal signoff for GPL-2.0-or-later compatibility,
  WordPress.org package redistribution, approver, approval date, and notes;
- no-go conditions: no runtime network fetches, no unpinned artifacts, no copied
  Apache-2.0 or GPL-3.0 implementation material without separate approval, no
  CC-BY-SA data bundling without separate approval, no production adapter before
  the source lock is approved, and no broad Thai/CJK support claim.

The supported data-kind values are:

- `imported_comprehensive`
- `imported_seed`
- `curated_seed`
- `reference_only`

## Verifier

Run the verifier against a candidate source-lock JSON file from the repository
root:

```sh
php indexer/tools/verify-tokenizer-source-lock.php path/to/source-lock.json
```

The verifier checks only metadata completeness and guardrails. Its classes live
under `indexer/tools/lib/` and are loaded only by the developer CLI wrappers;
they are not component runtime classes or release-package files. The verifier
does not download URLs, read source archives, validate real dictionary rows, or
prove segmentation quality.

The test fixture mode is reserved for committed verifier fixtures:

```sh
php indexer/tools/verify-tokenizer-source-lock.php --allow-test-fixtures indexer/tests/fixtures/tokenizer-source-lock/complete-test-fixture.json
php indexer/tools/verify-tokenizer-source-lock.php --expect-invalid indexer/tests/fixtures/tokenizer-source-lock/incomplete-missing-approval.json
```

Do not use `--allow-test-fixtures` for production source-lock approval.

## Thai Candidate Preflight

The current repository-side artifact is:

```text
indexer/review-artifacts/source-locks/thai-tokenizer-source-candidate-preflight.json
```

It is a candidate preflight only. It names the preferred first source family as
PyThaiNLP's `thai_words()` / `words_th.txt` dictionary family with a future
clean-room TCC or TCC+ boundary implementation. It also records why other
families are not first-choice candidates for this branch.

The artifact intentionally leaves exact identity values pending for a future
approved source lock:

- dictionary immutable release or commit;
- dictionary artifact name, URL, SHA-256, and byte count;
- dictionary license identifier, name, authoritative URL, and text URL;
- exact TCC or TCC+ variant;
- clean-room TCC/TCC+ rule artifact name, URL, SHA-256, and byte count;
- copyright holder, attribution text, source-chain evidence, clean-room author,
  rights basis, and maintainer/legal approval.

Those missing fields are blockers. The preflight may pass only when pending
exact values are explicitly allowed:

```sh
php indexer/tools/verify-thai-tokenizer-source-candidate-lock.php \
  indexer/review-artifacts/source-locks/thai-tokenizer-source-candidate-preflight.json \
  --allow-pending-exact-values
```

Run strict mode without `--allow-pending-exact-values` before adapter work. It
must fail until every pending field is replaced by concrete reviewed evidence.

## No-Go Conditions Before Coding

Do not add `thai_dictionary_tcc_v1`, a Thai dictionary importer, TCC/TCC+ rule
implementation, generated tokenizer resources, or a `th` language pack until an
approved source lock passes the verifier and has review signoff.

Do not copy PyThaiNLP Apache-2.0 code, JTCC GPL-3.0 grammar, CC-BY-SA word
lists, ICU dictionary data, or any other third-party tokenizer data into this
plugin unless the exact artifact, license, notices, clean-room approach, and
WordPress.org redistribution terms are approved in the source lock.

After the source lock is approved, a real tokenizer implementation still needs
deterministic Thai token stream fixtures, search behavior tests, performance and
memory gates, release packaging checks, and narrow documentation wording before
any user-facing support claim changes.
