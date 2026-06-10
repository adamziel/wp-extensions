# Snowball Compliance Harness

`tests/snowball-compliance.php` compares the integrated `WP_FTS_SnowballStemmer`
adapter against Snowball's official multilingual stemming data. The harness reads
each dataset's `voc.txt` input file and `output.txt` expected stems line by line.
Languages supported by `WP_FTS_SnowballStemmer` through `wamania/php-stemmer` are
tested; unsupported Snowball languages and alternate algorithm variants are
reported as skipped. The adapter only advertises Wamania algorithms that match
the official fixtures exactly: Catalan and Dutch Porter (`nl`). Wamania exposes
additional language classes, but those implementations currently diverge from
the current official Snowball outputs, so the adapter treats them as unsupported
instead of claiming Snowball compliance.

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

- without `vendor/`: `0 pass, 37 skip, 0 fail`;
- with `vendor/` installed from the committed `composer.lock`: `2 pass, 35 skip, 0 fail`.

The two passing runtime datasets are Catalan and Dutch Porter. Any `fail` result
means a fixture, algorithm, or advertised-language contract regressed; missing
Wamania classes are reported as dependency skips, not algorithm failures.

This is a Snowball stemmer compliance suite for the multilingual full-text
pipeline. Lucene `analysis/common` remains the broader analyzer reference for
tokenization, filtering, and language analysis behavior, but this harness does
not claim compatibility with Lucene's unit test suite unless that suite is run
separately.
