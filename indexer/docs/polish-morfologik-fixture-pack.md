# Polish Morfologik/PoliMorf Fixture Pack

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

Before a real Morfologik/PoliMorf import can be distributed or default-enabled,
the project still needs:

- an exact upstream source artifact and recorded digest;
- license compatibility review and attribution text;
- a deterministic importer that produces normalized runtime rows;
- larger reviewed gold fixtures for ambiguous and inflected forms;
- WordPress Playground smoke coverage;
- production review of relevance, size, and update workflow.
