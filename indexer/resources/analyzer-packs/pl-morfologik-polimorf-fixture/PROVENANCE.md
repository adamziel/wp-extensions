# Polish Morfologik/PoliMorf Fixture Pack Provenance

This directory is an opt-in fixture pack for the Pure PHP FTS Indexer analyzer
pack contract. It is not a full Morfologik, PoliMorf, or SGJP dictionary dump.

The rows in `runtime.tsv` are a tiny reviewed excerpt shaped to exercise the
runtime lemmatizer contract:

- one normalized surface form maps to one normalized lemma key;
- a surface form with conflicting lemma rows is treated as ambiguous;
- missing forms remain unchanged;
- all runtime rows are already normalized with the plugin default Polish token
  normalization (`fold_diacritics => true`).

The `wyszukiwac`, `wpis`, and `kierowac` rows are source-derived excerpts
from locally generated external PoliMorf pack evidence for
`pl-polimorf-20180722-full`:

- `wyszukiwanie	wyszukiwac`
- `wyszukiwania	wyszukiwac`
- `wyszukujemy	wyszukiwac`
- `wyszukiwali	wyszukiwac`
- `wpis	wpis`
- `wpisach	wpis`
- `wpisami	wpis`
- `wpisy	wpis`
- `kierowac	kierowac`
- `kierowania	kierowac`
- `kierowanie	kierowac`
- `kierujemy	kierowac`

That external pack was generated from the PoliMorf Polish morphological
dictionary `polimorf-20180722.tab.gz` with source SHA-256
`2b1f07224c434c8710def382d497cf8221d5764e8d683d2ad34242810ab72746`;
the full generated runtime remains outside the plugin package and is not
bundled by this fixture.

The intended future source family is Morfologik/PoliMorf-compatible Polish
morphological dictionaries. Before a comprehensive pack can ship or become a
default analyzer, the project must lock an exact source artifact, record its
digest, complete license and attribution review, add a deterministic importer,
expand gold fixtures, run WordPress Playground smoke coverage, and complete
review.
