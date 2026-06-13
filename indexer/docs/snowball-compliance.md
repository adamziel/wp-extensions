# Snowball Compliance Harness

`tests/snowball-compliance.php` compares the integrated `WP_FTS_SnowballStemmer`
adapter against Snowball's official multilingual stemming data. The harness reads
each dataset's `voc.txt` input file and `output.txt` expected stems line by line.
Languages supported by `WP_FTS_SnowballStemmer` are tested; unsupported
Snowball languages and alternate algorithm variants are reported as skipped. The
adapter advertises:

- English (`en`) through a bundled generated PHP Snowball English/Porter2 path;
- Spanish (`es`) through a bundled generated PHP Snowball Spanish path;
- Catalan (`ca`) and Dutch Porter (`nl`) through `wamania/php-stemmer` only when
  that optional Composer package is installed.

The bundled English and Spanish implementations are generated from
`algorithms/english.sbl` and `algorithms/spanish.sbl` by Snowball 3.1.1. English
is verified against `snowballstem/snowball-data` commit
`13803281da204fbd56be5b6f62d3efb98f4d74c2`; Spanish is verified against the
local official `spanish/voc.txt` and `spanish/output.txt` fixtures with 28,378
line pairs. The source identities exposed by
`WP_FTS_SnowballStemmer::source_identity('en')` and
`WP_FTS_SnowballStemmer::source_identity('es')` record the Snowball source
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

- without `vendor/`: `2 pass, 35 skip, 0 fail`;
- with `vendor/` installed from the committed `composer.lock`: `4 pass, 33 skip, 0 fail`.

The passing runtime datasets are bundled English and Spanish, plus Catalan and
Dutch Porter when Wamania is installed. Any `fail` result means a fixture,
algorithm, or advertised-language contract regressed; missing Wamania classes
are reported as dependency skips, not algorithm failures.

This is a Snowball stemmer compliance suite for the multilingual full-text
pipeline. Lucene `analysis/common` remains the broader analyzer reference for
tokenization, filtering, and language analysis behavior, but this harness does
not claim compatibility with Lucene's unit test suite unless that suite is run
separately.

The bundled generated English and Spanish paths preserve the Snowball
BSD-3-Clause notice in `src/EnglishSnowballStemmer.php` and
`src/SpanishSnowballStemmer.php`. Missing Composer dependencies do not affect
bundled English or Spanish compliance; they only skip Wamania-backed Catalan and
Dutch Porter runtime comparisons.
