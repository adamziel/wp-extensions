# Polish Morfologik/PoliMorf Packs

The source tree has one shipped Polish runtime pack and one test-only contract
pack:

- `resources/analyzer-packs/pl-polimorf-20180722-full/` is the compressed full
  runtime used by WordPress when gzip support is available;
- `tests/fixtures/analyzer-packs/pl-morfologik-polimorf-fixture/` contains the
  small reviewed contract rows used by tests. It is not shipped and never
  participates in runtime fallback.

The WordPress plugin selects the full bundled manifest automatically. A
framework-neutral caller can select the same manifest explicitly:

```php
$analyzer = new WP_FTS_Analyzer([
    'default_lang' => 'pl',
    'lemma_packs_by_lang' => [
        'pl' => WP_FTS_AnalyzerPackValidator::default_polish_manifest(),
    ],
]);
```

When enabled and valid, the pack maps normalized Polish surface forms from its
local TSV rows to normalized lemma keys. Ambiguous rows and missing forms return
the original normalized token. When the pack is disabled, missing, invalid, or
unreadable without gzip, the conservative Polish suffix stemmer runs. There
is no fixture-pack fallback.

Validate each manifest explicitly:

```sh
php tools/validate-analyzer-pack.php \
  resources/analyzer-packs/pl-polimorf-20180722-full/manifest.json
php tools/validate-analyzer-pack.php \
  tests/fixtures/analyzer-packs/pl-morfologik-polimorf-fixture/manifest.json
php -n tools/validate-analyzer-pack.php \
  tests/fixtures/analyzer-packs/pl-morfologik-polimorf-fixture/manifest.json
```

The repository also includes a package-safe external builder for the full
CLARIN-PL PoliMorf TSV artifact. It verifies the exact approved source
SHA-256 and byte count, checks gzip integrity, runs the deterministic importer
and full validator, verifies that the generated pack can be activated, and
writes it to an operator-chosen directory outside the plugin package:

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

The builder writes a full-pack manifest, notice, source-lock record, and
indexed-gzip runtime shards with one digest-attested lookup sidecar per shard.
The summary includes the generated manifest path, source-lock path, runtime
row/file/byte counts, lookup file/block/byte counts, runtime digest, activation
result, and a `lemma_packs_by_lang` configuration example.
Externally generated full packs activate only through plugin configuration after
an operator installs and configures them.

Configure an externally generated pack by path:

```php
$analyzer = new WP_FTS_Analyzer([
    'default_lang' => 'pl',
    'lemma_packs_by_lang' => [
        'pl' => '/tmp/pl-polimorf-20180722-full/manifest.json',
    ],
]);
```

The full source archive and extracted TSV are not committed or bundled in this
repository. The separately generated external pack workflow remains outside the
release package; users or build systems that need their own generated pack must
generate and install it externally before enabling it.

Before a broader public distribution or default policy change for external
Morfologik/PoliMorf packs, the project still needs:

- final redistribution review for the generated notice and attribution text;
- larger reviewed gold fixtures for ambiguous and inflected forms;
- WordPress Playground smoke coverage;
- production review of relevance and update workflow.
