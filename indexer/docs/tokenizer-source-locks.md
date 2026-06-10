# Tokenizer Source Locks

The `indexer/` plugin does not currently ship real Thai segmentation. CJK
script runs still use the fallback tokenizer described in
[Limitations](limitations.md), and no Thai dictionary rows, TCC/TCC+ rules, or
tokenizer adapter are committed.

Future `thai_dictionary_tcc_v1` work must start with an approved source lock
before any adapter, importer, bundled dictionary, generated TCC/TCC+ rule file,
or user-facing Thai support claim is added.

This candidate lock is evidence only: no dictionary rows, TCC/TCC+ rules, or tokenizer adapter are committed.

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

## No-Go Conditions

Do not add `thai_dictionary_tcc_v1`, a Thai dictionary importer, generated Thai
tokenizer resources, TCC/TCC+ rule data, or a runtime tokenizer adapter until an
approved source lock replaces the preflight candidate.

Do not copy PyThaiNLP, JTCC, tccseg, ICU, or other third-party implementation
code, regexes, grammars, generated tables, or tokenizer data without separate
approval. TCC and TCC+ boundaries are subword boundaries; they are not Thai word
segmentation by themselves.

Do not bundle CC-BY-SA or other reciprocal dictionary data without explicit
compatibility, attribution, notice, and WordPress.org redistribution approval.
Do not use runtime network fetches or mutable/unpinned source URLs for tokenizer
resources.
