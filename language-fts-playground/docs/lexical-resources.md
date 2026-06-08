# Lexical Resource Packs

Language FTS Playground keeps language analysis data in local resources under
`language-fts-playground/resources/languages/<language>/`. Runtime loading is
pure PHP and uses keyed maps after each language profile is parsed, so query
expansion does not scan resource files.

## Profile Contract

`profile.php` returns the language id, label, optional order, optional
normalization folds, optional language signal regexes, and resource file names:

```php
'resources' => [
    'stopwords' => 'stopwords.txt',
    'lexemes' => 'lexemes.tsv',
    'synonyms' => 'synonyms.tsv',
    'synsets' => 'synsets.tsv',
],
```

`synsets` is optional. `synonyms.tsv` remains required for compatibility, even
when it contains only the header.

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

## Importing Larger Lexical Databases Later

This repository ships only a tiny curated seed pack for the demo. It does not
ship WordNet, plWordNet, or another comprehensive synonym database.

A real import should happen at build time, not at runtime:

1. Review the source database license and attribution requirements.
2. Normalize source forms using the language profile's fold/lowercase rules.
3. Map observed forms into `lexemes.tsv` canonical keys.
4. Reduce source synsets or related lexical concepts to canonical-key groups.
5. Assign conservative query-expansion weights and provenance ids.
6. Compile groups to `synsets.tsv`.
7. Run the PHP test harness under normal PHP and `php -n`.

The helper at `tools/compile-synsets.php` compiles a simple build-time source
format where each line is `concept_id<TAB>canonical_term`. It expects terms to
already be normalized; source-specific normalization and licensing decisions
belong in the importer that prepares that input.
