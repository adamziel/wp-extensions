# Polish Morfologik/PoliMorf Packs

The bundled Polish analyzer pack is an opt-in fixture pack. It proves the
runtime contract for a Morfologik/PoliMorf-compatible dictionary lemmatizer, but
it is not a full Polish morphological dictionary and is not enabled by default.

Enable the fixture in programmatic analyzer construction:

```php
$analyzer = new WP_FTS_Analyzer([
    'default_lang' => 'pl',
    'polish_lemma_pack' => true,
]);
```

When enabled and valid, the pack maps normalized Polish surface forms from its
local TSV rows to normalized lemma keys. Ambiguous rows and missing forms return
the original normalized token. When the pack is disabled, missing, or invalid,
the selected `polish_stemming` mode runs; without an explicit mode, that remains
the existing conservative Polish suffix stemmer.

Validate the bundled fixture locally:

```sh
php tools/validate-analyzer-pack.php
php -n tools/validate-analyzer-pack.php
```

The repository also includes a package-safe external builder for the full
CLARIN-PL PoliMorf TSV artifact. It verifies the exact approved source
SHA-256 and byte count, checks gzip integrity when gzip tooling is available,
runs the deterministic importer and validator, and writes the generated runtime
pack to an operator-chosen directory outside the plugin package:

```sh
php tools/build-polish-polimorf-external-pack.php \
  --source=/tmp/polimorf-20180722.tab.gz \
  --out=/tmp/pl-polimorf-20180722-full
```

The builder can also download the approved public CLARIN-PL artifact into a
caller-chosen cache directory, but only with an explicit license
acknowledgement:

```sh
php tools/build-polish-polimorf-external-pack.php \
  --download \
  --cache-dir=/tmp/wp-fts-polimorf-cache \
  --out=/tmp/pl-polimorf-20180722-full \
  --acknowledge-license=BSD-2-Clause
```

The builder writes a full-pack manifest, notice, source-lock evidence, and
sharded runtime TSV files. The summary includes the generated manifest path,
source-lock path, runtime row/file/byte counts, runtime digest, and
configuration examples for `polish_lemma_pack` and `polish_lemmatizer_pack`.
Generated full packs remain opt-in and `default_enabled: false`.

Configure an externally generated pack by path:

```php
$analyzer = new WP_FTS_Analyzer([
    'default_lang' => 'pl',
    'polish_lemma_pack' => '/tmp/pl-polimorf-20180722-full/manifest.json',
]);
```

The full source archive, extracted TSV, and generated third-party runtime pack
are not committed or bundled in this repository. Users or build systems must
generate and install the pack externally before enabling it.

Before a real Morfologik/PoliMorf import can be distributed or default-enabled,
the project still needs:

- final redistribution review for the generated notice and attribution text;
- larger reviewed gold fixtures for ambiguous and inflected forms;
- WordPress Playground smoke coverage;
- production review of relevance and update workflow.
