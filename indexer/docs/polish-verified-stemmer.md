# Polish Verified Stemmer

The Polish verified stemmer is an opt-in analyzer slice enabled with:

```php
$analyzer = new WP_FTS_Analyzer([
    'default_lang' => 'pl',
    'polish_stemming' => 'verified',
]);
```

Default runtime behavior remains `polish_stemming => 'conservative'`. The
verified mode is deliberately separate so existing indexes and tests keep the
same suffix-only behavior unless a caller explicitly selects the fixture-backed
path.

## Scope

This slice is a stemmer port, not dictionary lemmatization. It maps a compact set
of reviewed Polish inflection rows to stems, protects ambiguous/no-op rows, and
falls back to the existing conservative suffix stemmer for unknown forms.

It is separate from the Task 565 Morfologik/PoliMorf lemmatizer-pack lane. That
future pack can carry dictionary resources, lemmas, and richer metadata behind a
different analyzer resource contract. This stemmer branch does not vendor a full
third-party dictionary dump.

## Provenance

Task 568 has licensing signoff for this lane. The source data still preserves
provenance metadata in `WP_FTS_PolishVerifiedStemmerData::manifest()` and keeps
the runtime fixture rows reviewable in source.

The runtime map stores normalized folded terms, because `WP_FTS_Normalizer` owns
case handling and diacritic folding before stemming. The fixture validator
checks every raw Polish source form against the normalizer so the stemmer does
not silently take ownership of folding.

## Verification

Run the standalone fixture validator from the plugin directory:

```sh
php tests/polish-verified-stemmer-fixtures.php
php -n tests/polish-verified-stemmer-fixtures.php
```

The main harness also discovers `tests/quality/polish-verified-stemmer.php`,
which covers direct stemmer output, conservative-baseline improvements,
protected rows, fallback rows, `WP_FTS_LanguagePipeline` parity,
`WP_FTS_Analyzer` document/query parity, and indexed AND-search recall.
