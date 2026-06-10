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

The repository also includes a deterministic local importer for the full
CLARIN-PL PoliMorf TSV artifact:

```sh
php tools/import-polish-polimorf-lemmatizer.php \
  --source=/tmp/polimorf-20180722.tab.gz \
  --out=/tmp/pl-polimorf-20180722-full
php tools/validate-analyzer-pack.php \
  /tmp/pl-polimorf-20180722-full/manifest.json --metadata-only
```

The importer writes a full-pack manifest, notice, source-lock evidence, and
sharded runtime TSV files. Generated full packs remain opt-in and
`default_enabled: false`. The generated third-party runtime pack is not committed
to this repository yet; packaging needs an explicit size and redistribution
review before the runtime data ships in a plugin archive.

Before a real Morfologik/PoliMorf import can be distributed or default-enabled,
the project still needs:

- packaging approval for the generated full runtime size;
- final redistribution review for the generated notice and attribution text;
- larger reviewed gold fixtures for ambiguous and inflected forms;
- WordPress Playground smoke coverage;
- production review of relevance and update workflow.
