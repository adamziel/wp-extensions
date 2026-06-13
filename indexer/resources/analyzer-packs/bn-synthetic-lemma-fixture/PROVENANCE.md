# Synthetic Bengali-Script Lemma Fixture

This fixture is project-owned synthetic test data for the generic analyzer-pack
runtime contract. It has a synthetic-only boundary. It is not a Bengali dictionary,
corpus excerpt, morphology resource, transliteration table, or quality benchmark.

The `runtime.tsv` rows use repeated Bengali-script characters as artificial
keys. They exist only to prove these infrastructure behaviors:

- one normalized surface can map to one normalized lemma;
- one ambiguous surface maps to multiple lemmas and must no-op;
- missing surfaces no-op;
- the manifest `language` field routes the pack only to the Bengali partition;
- a configured pack takes precedence over the existing Bengali suffix baseline.

The row containing `গুলো` is still synthetic. It is not a lexical claim; it is a
test key chosen so the fixture can prove precedence over the baseline suffix
path without adding real Bengali vocabulary or source-backed rows.

No Bengali, Urdu, CJK, Jieba, Anvay, UrduHack, spaCy, Apertium, Universal
Dependencies, or other third-party lexical data is included here.
