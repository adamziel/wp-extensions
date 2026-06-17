# Snowball Compliance Harness

`tests/snowball-compliance.php` compares the integrated `WP_FTS_SnowballStemmer`
adapter against Snowball's official multilingual stemming data. The harness reads
each dataset's `voc.txt`/`voc.txt.gz` input file and
`output.txt`/`output.txt.gz` expected stems line by line.
Languages supported by `WP_FTS_SnowballStemmer` are tested; unsupported
Snowball languages and alternate algorithm variants are reported as skipped. The
adapter advertises:

- Arabic (`ar`) through a bundled generated PHP Snowball Arabic path;
- English (`en`) through a bundled generated PHP Snowball English/Porter2 path;
- Spanish (`es`) through a bundled generated PHP Snowball Spanish path;
- French (`fr`) through a bundled generated PHP Snowball French path;
- Hindi (`hi`) through a bundled generated PHP Snowball Hindi path;
- Portuguese (`pt`) through a bundled generated PHP Snowball Portuguese path;
- Indonesian (`id`) through a bundled generated PHP Snowball Indonesian path;
- Catalan (`ca`) and Dutch Porter (`nl`) through `wamania/php-stemmer` only when
  that optional Composer package is installed.

The bundled Arabic, English, Spanish, French, Hindi, Portuguese, and Indonesian
implementations are generated from `algorithms/arabic.sbl`,
`algorithms/english.sbl`, `algorithms/spanish.sbl`, `algorithms/french.sbl`,
`algorithms/hindi.sbl`, `algorithms/portuguese.sbl`, and
`algorithms/indonesian.sbl` by Snowball 3.1.1.
English is verified against
`snowballstem/snowball-data` commit
`13803281da204fbd56be5b6f62d3efb98f4d74c2`; Spanish is verified against the
local official `spanish/voc.txt` and `spanish/output.txt` fixtures with 28,378
line pairs; French is verified against the local official `french/voc.txt` and
`french/output.txt` fixtures with 21,653 line pairs; Hindi is verified against
the local official `/home/claude/.cache/snowball-data/hindi/voc.txt` and
`/home/claude/.cache/snowball-data/hindi/output.txt` fixtures with 65,118 line
pairs; Portuguese is verified against the local official `portuguese/voc.txt`
and `portuguese/output.txt` fixtures with 32,016 line pairs; Indonesian is
verified against the local official `indonesian/voc.txt` and
`indonesian/output.txt` fixtures with 64,586 line pairs; Arabic is verified
against the local official compressed
`arabic/voc.txt.gz` and `arabic/output.txt.gz` fixtures with 9,196,214 line
pairs. The source identities exposed by
`WP_FTS_SnowballStemmer::source_identity('ar')`,
`WP_FTS_SnowballStemmer::source_identity('en')`,
`WP_FTS_SnowballStemmer::source_identity('es')`,
`WP_FTS_SnowballStemmer::source_identity('fr')`,
`WP_FTS_SnowballStemmer::source_identity('hi')`,
`WP_FTS_SnowballStemmer::source_identity('pt')`, and
`WP_FTS_SnowballStemmer::source_identity('id')` record the Snowball source
commit used for the generated PHP reference. Wamania exposes additional language
classes, but those implementations currently diverge from the current official
Snowball outputs, so the adapter treats them as unsupported instead of claiming
Snowball compliance.

Run it with:

```sh
SNOWBALL_DATA_DIR=/home/claude/.cache/snowball-data php tests/snowball-compliance.php
```

The `SNOWBALL_DATA_DIR` environment variable should point at a local checkout of
the official Snowball test data. For a fresh checkout:

```sh
git clone https://github.com/snowballstem/snowball-data /home/claude/.cache/snowball-data
```

For convenience, Composer also exposes:

```sh
SNOWBALL_DATA_DIR=/home/claude/.cache/snowball-data composer test:snowball
```

Expected counts with the current official dataset inventory are:

- without `vendor/`: `7 pass, 30 skip, 0 fail`;
- with `vendor/` installed from the committed `composer.lock`: `9 pass, 28 skip, 0 fail`.

The passing runtime datasets are bundled Arabic, English, Spanish, French,
Hindi, Portuguese, and Indonesian, plus Catalan and Dutch Porter when Wamania is
installed. Any
`fail` result means a fixture, algorithm, or advertised-language contract
regressed; missing Wamania classes are reported as dependency skips, not
algorithm failures.

This is a Snowball stemmer compliance suite for the multilingual full-text
pipeline. Lucene `analysis/common` remains the broader analyzer reference for
tokenization, filtering, and language analysis behavior, but this harness does
not claim compatibility with Lucene's unit test suite unless that suite is run
separately.

The bundled generated Arabic, English, Spanish, French, Hindi, Portuguese, and
Indonesian paths preserve the Snowball BSD-3-Clause notice in
`components/full-text-search/src/ArabicSnowballStemmer.php`,
`components/full-text-search/src/EnglishSnowballStemmer.php`,
`components/full-text-search/src/SpanishSnowballStemmer.php`,
`components/full-text-search/src/FrenchSnowballStemmer.php`,
`components/full-text-search/src/HindiSnowballStemmer.php`,
`components/full-text-search/src/PortugueseSnowballStemmer.php`, and
`components/full-text-search/src/IndonesianSnowballStemmer.php`.
Missing Composer dependencies do not affect bundled Arabic, English, Spanish,
French, Hindi, Portuguese, or Indonesian compliance; they only skip Wamania-backed
Catalan and Dutch Porter runtime comparisons.
