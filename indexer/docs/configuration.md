# Configuration

This branch has no settings screen for analyzer, search, or extractor
configuration. WordPress runtime indexing, REST/admin search, the PHP plugin
search helper, and WP-CLI use `WP_FTS_Plugin::runtime_analyzer()`.
Per-language lemma packs can be supplied through the `wp_fts_analyzer_options`
option or filter. Operational options such as schema version and pending queue
state are managed internally, and selected custom fields can be supplied
through an option or filters. More advanced configuration is available to PHP
callers that instantiate `WP_FTS_Analyzer`, `WP_FTS_LanguagePipeline`,
`WP_FTS_Searcher`, or `WP_FTS_Storage_Mysql` directly.

## Languages

Every stored term is namespaced by language. The stored key shape is:

```text
<language>\x1e<term>
```

Language tags are canonicalized from WordPress-style locales and BCP 47-style
input. For example, `en_US` becomes `en-US`, `pl_PL` becomes `pl-PL`, and empty
values fall back to the caller's default language.

Primary document language resolution during `wp fts reindex` follows this
order. This primary language is stored as document metadata and participates in
the content hash used to decide whether unchanged documents can be skipped:

1. Explicit `--lang` or `--language`.
2. Polylang `pll_get_post_language( $post_id, 'locale' )`, when available.
3. WPML `wpml_post_language_details`, when available.
4. The default language from `default_lang` or `locale`, then the WordPress site
   locale, then `en`.

Analyzer-level routing happens after that primary-language metadata decision.
HTML `lang` and `xml:lang` attributes can route individual content segments into
their own language partition. For untagged segments, the conservative detector
may fill gaps by script, distinctive Latin letters, and compact lexical evidence
before falling back to the primary document language.

Query analysis follows the same explicit-first rule. Prefer passing `--lang` on
operational searches when the language is known; otherwise the analyzer may use
conservative detector evidence or a custom term-language resolver to route
individual untagged query terms into the same partitions as untagged indexed
content:

```sh
wp fts search "zamek" --lang=pl-PL
wp fts search "castle" --lang=en-US
```

The detector is not statistical language detection. It only uses script ranges,
distinctive Latin letters, and compact lexical evidence to fill gaps. Explicit
caller options, HTML language attributes, Polylang/WPML metadata, and custom
language resolvers remain authoritative.

The built-in baseline detector and admin selectors cover English (`en`),
Mandarin/Chinese (`zh`), Hindi (`hi`), Spanish (`es`), Arabic (`ar`), French
(`fr`), Bengali (`bn`), Portuguese (`pt`), Indonesian (`id`), and Urdu (`ur`).
Polish (`pl`), German (`de`), Russian (`ru`), and other explicit partitions can
be routed when callers provide language hints.

| Language or partition | Routing support | Analyzer tier | Fallback and boundary |
| --- | --- | --- | --- |
| Polish (`pl`) | Explicit routing, detector evidence, multilingual metadata, and HTML scopes. | Strongest path when a valid opt-in analyzer/lemma pack is configured. `polish_lemma_pack` and `polish_lemmatizer_pack` map to the generic pack runtime; `polish_stemming => 'verified'` enables a fixture-backed stemmer slice. | Default behavior remains conservative unless a valid pack or verified mode is enabled. Bundled packs stay opt-in/default-disabled outside the sandbox path. |
| English (`en`), Hindi (`hi`), Spanish (`es`), Arabic (`ar`), French (`fr`), Bengali (`bn`), Portuguese (`pt`), Indonesian (`id`) | Selectable/detectable language partitions. | Source-backed UniMorph lemma packs are bundled as opt-in gzip-sharded analyzer packs. | Configure them through `lemma_packs_by_lang` / `lemmatizer_packs_by_lang`; built-in Snowball or baseline behavior remains the fallback when no pack is configured. |
| Catalan (`ca`), Dutch Porter (`nl`) | Explicit partitions and detector evidence where present. | Optional Wamania-backed Snowball paths when Composer dependencies are installed and compliance checks accept them. | Other Wamania languages stay no-op until verified against the current Snowball fixtures. |
| Chinese (`zh`) | Selectable/detectable CJK partition. | Deterministic fallback CJK tokenization: one-character runs plus overlapping n-grams up to 4 characters. | No bundled dictionary segmentation, word morphology, or CJK lexical pack. |
| Urdu (`ur`) | Selectable/detectable partition. | Arabic-script combining mark/harakat and tatweel normalization plus deterministic light suffix baseline for common plural-oblique forms. | UniMorph Urdu is license-blocked, so no generated Urdu pack is bundled. Persian-like text is not merged into Urdu routing. |
| German (`de`), Russian (`ru`), other explicit partitions | Language namespace/routing support when the caller or detector supplies the language. | Conservative analysis unless a documented analyzer exists. | Unsupported morphology returns the normalized token unchanged. |
| Generic packs | Available through `lemma_packs_by_lang` / `lemmatizer_packs_by_lang`. | Local manifest-backed packs whose manifest `language` matches the configured key. | Invalid, missing, disabled, or language-mismatched packs are ignored and the built-in fallback path remains available. |

Morphology support must come from verified algorithms, analyzers, or
manifest-backed lemmatizer packs. Do not model product behavior with hard-coded
word families.

The current searcher scores each query term inside one resolved language
partition. It can route different terms to different partitions, but it does not
merge one term's scores across multiple languages.

## Analyzer Defaults

The default analyzer:

- strips non-visible HTML regions such as `script`, `style`, `noscript`,
  `template`, `svg`, `nav`, `aside`, `footer`, and `form`;
- applies the strongest matching ancestor boost, not multiplied boosts;
- boosts `title`, `h1`, `h2`, `h3`, `strong`, `em`, and `b`;
- folds diacritics by default;
- strips Arabic-script combining marks/harakat and tatweel for Arabic (`ar`)
  and Urdu (`ur`) only;
- applies configured source-backed lemma packs before built-in language
  fallbacks;
- applies bundled generated Snowball stemming for English (`en`), Arabic (`ar`),
  Spanish (`es`), French (`fr`), Hindi (`hi`), Portuguese (`pt`), and
  Indonesian (`id`) when no lemma pack is configured;
- applies conservative Bengali (`bn`) classifier/plural/genitive/dative/case
  suffix stemming;
- applies conservative Urdu (`ur`) feminine/masculine/Arabic-loan/plural-oblique
  suffix stemming without Arabic/Persian/Urdu letter rewrites;
- drops non-CJK terms shorter than 2 characters;
- rejects stored term keys over 255 bytes;
- tokenizes one-character CJK script runs as-is and longer CJK runs into
  character unigrams plus deterministic overlapping n-grams up to 4 characters.

The CJK path is fallback n-gram retrieval, not dictionary word segmentation.
The plugin does not ship a Thai tokenizer, Thai dictionary, TCC/TCC+ rules, or a
production non-space tokenizer adapter. Any future Thai adapter must pass the
[tokenizer source-lock](tokenizer-source-locks.md) gate first.

Programmatic callers can tune these options:

```php
$analyzer = new WP_FTS_Analyzer([
    'default_lang' => 'en-US',
    'skip_ancestors' => ['SCRIPT', 'STYLE', 'NAV', 'ASIDE', 'FOOTER'],
    'boosts' => [
        'TITLE' => 6.0,
        'H1' => 4.0,
        'H2' => 2.5,
        'STRONG' => 1.5,
    ],
    'min_term_len' => 2,
    'max_term_bytes' => 255,
    'fold_diacritics' => true,
]);
```

WordPress runtime indexing, REST/admin search, the PHP plugin search helper,
the admin sandbox, and WP-CLI use the same plugin runtime analyzer. Reindex
after changing analyzer options so stored terms and document signatures are
rebuilt with the new behavior.

Analyzer behavior participates in stale-document detection. A reindex skips
unchanged content only when the source content, primary language, and
analyzer/index signature still match; stemming or language-pipeline changes
force existing documents to be rewritten.

## Stemmers

Stemming is enabled by default. The pipeline uses:

- bundled generated Snowball English/Porter2 for English (`en` and English
  locale tags), verified against the official `english` fixture data;
- bundled generated Snowball Arabic for Arabic (`ar` and Arabic locale tags),
  verified against the official compressed `arabic` fixture data;
- bundled generated Snowball Spanish for Spanish (`es` and Spanish locale
  tags), verified against the official `spanish` fixture data;
- bundled generated Snowball French for French (`fr` and French locale tags),
  verified against the official `french` fixture data;
- bundled generated Snowball Hindi for Hindi (`hi` and Hindi locale tags),
  verified against the official `hindi` fixture data;
- bundled generated Snowball Portuguese for Portuguese (`pt` and Portuguese
  locale tags), verified against the official `portuguese` fixture data;
- bundled generated Snowball Indonesian for Indonesian (`id` and Indonesian
  locale tags), verified against the official `indonesian` fixture data;
- deterministic Bengali (`bn`) light stemming for common classifier, plural,
  genitive, dative, and case suffixes;
- deterministic Urdu (`ur`) light stemming for common feminine, masculine,
  Arabic-loan, and plural-oblique endings, with Arabic/Persian/Urdu letters
  preserved;
- Snowball through `wamania/php-stemmer` for optional allowlisted languages that
  pass the bundled compliance harness when Composer dependencies are installed:
  Catalan (`ca`) and Dutch Porter (`nl`);
- a small conservative Polish suffix stemmer for `pl` by default;
- an opt-in verified Polish fixture slice with protected ambiguous rows and
  conservative fallback;
- no-op behavior for unsupported languages or missing optional dependencies.

Stemming can be disabled explicitly when exact normalized terms are required:

```php
$analyzer = new WP_FTS_Analyzer([
    'enable_stemming' => false,
]);
```

For Polish, the current mode is intentionally conservative:

```php
$analyzer = new WP_FTS_Analyzer([
    'default_lang' => 'pl',
    'polish_stemming' => 'conservative',
]);
```

The built-in Polish path gives a valid opt-in lemma pack precedence over the
selected `polish_stemming` mode. If no valid pack is configured, the selected
mode runs; unknown mode values normalize to the conservative fallback.

An opt-in Polish fixture pack proves the Morfologik/PoliMorf-compatible
dictionary lemmatizer contract without shipping a full third-party dictionary:

```php
$analyzer = new WP_FTS_Analyzer([
    'default_lang' => 'pl',
    'polish_lemma_pack' => true,
]);
```

The fixture pack maps only its reviewed normalized runtime rows. Ambiguous and
missing forms remain unchanged. If the pack is disabled, missing, or invalid,
the analyzer uses the selected `polish_stemming` mode, which defaults to the
existing conservative Polish suffix stemmer. Validate the fixture with
`php tools/validate-analyzer-pack.php`.

A generated full PoliMorf pack can also be supplied by path after running the
external builder outside the repository:

```sh
php tools/build-polish-polimorf-external-pack.php \
  --source=/tmp/polimorf-20180722.tab.gz \
  --out=/tmp/pl-polimorf-20180722-full
```

```php
$analyzer = new WP_FTS_Analyzer([
    'default_lang' => 'pl',
    'polish_lemma_pack' => '/tmp/pl-polimorf-20180722-full/manifest.json',
]);
```

The alias `polish_lemmatizer_pack` accepts the same boolean, manifest path,
pack directory, or option array shape:

```php
$analyzer = new WP_FTS_Analyzer([
    'default_lang' => 'pl',
    'polish_lemmatizer_pack' => '/tmp/pl-polimorf-20180722-full/manifest.json',
]);
```

The same analyzer-pack runtime can be configured per language for future
source-approved packs:

```php
$analyzer = new WP_FTS_Analyzer([
    'lemma_packs_by_lang' => [
        'bn' => '/srv/wp-fts-packs/bn-approved-lemma-pack/manifest.json',
    ],
]);
```

The alias `lemmatizer_packs_by_lang` accepts the same map shape. Each enabled
pack must validate locally and its manifest `language` must match the configured
language key. A valid pack takes precedence over the built-in baseline or
Snowball path for that language. Missing, invalid, or language-mismatched packs
are ignored so the existing fallback analyzer remains available. Enabled packs
participate in the language-pipeline signature, so unchanged documents are
rewritten when a pack changes.

WordPress runtime configuration uses the same map shape. The plugin starts with
its bundled runtime defaults, merges the `wp_fts_analyzer_options` option, then
applies the `wp_fts_analyzer_options` filter:

```php
update_option(WP_FTS_Plugin::ANALYZER_OPTIONS_OPTION, [
    'lemmatizer_packs_by_lang' => [
        'bn' => '/srv/wp-fts-packs/bn-approved-lemma-pack/manifest.json',
    ],
]);

add_filter(WP_FTS_Plugin::ANALYZER_OPTIONS_FILTER, static function (array $options): array {
    $options['lemma_packs_by_lang']['ur'] = '/srv/wp-fts-packs/ur-approved-lemma-pack/manifest.json';

    return $options;
});
```

`lemma_packs_by_lang` wins over `lemmatizer_packs_by_lang` for the same
language. The legacy `polish_lemma_pack` / `polish_lemmatizer_pack` aliases map
to `pl` when no explicit Polish entry is present. Explicit `false`, `null`,
`"0"`, `"false"`, `"no"`, or `"off"` disables that configured language entry.
Invalid, missing, and language-mismatched manifests are reported as ignored in
the admin sandbox and fall back to the built-in analyzer path for that language.

The Playground/admin runtime auto-loads only the bundled local Polish pack when
its compressed shards can be read; otherwise it falls back to the bundled tiny
Polish fixture pack. The synthetic Bengali pack remains a default-disabled test
fixture and is not product data.

### Importing Normalized Lemma TSV Packs

`tools/import-lemma-tsv-pack.php` adapts a source-approved normalized lemma TSV
into the generic analyzer-pack runtime. The source TSV must be UTF-8 and already
normalized for the target language. Each non-comment row uses
`surface<TAB>lemma`; optional third and fourth columns may carry source tags or
notes. The importer sorts and deduplicates rows, writes runtime shards, and
emits `manifest.json` plus `NOTICE.txt` with source, license, attribution, and
provenance metadata.

```sh
php tools/import-lemma-tsv-pack.php \
  --source=/path/to/approved-normalized-lemmas.tsv \
  --out=/srv/wp-fts-packs/example-lemma-pack \
  --language=bn \
  --pack-id=bn-approved-lemma-pack \
  --version=2026.06-source-v1 \
  --source-name="Approved source dictionary name" \
  --source-url="https://example.test/source-artifact" \
  --license=CC-BY-4.0 \
  --license-url="https://creativecommons.org/licenses/by/4.0/" \
  --attribution="Required upstream attribution text"
```

Validate the generated pack before configuring it:

```sh
php tools/validate-analyzer-pack.php /srv/wp-fts-packs/example-lemma-pack/manifest.json
```

Real dictionary imports require source approval, license compatibility review,
an exact source artifact URL/digest, and required attribution before running the
importer. The repository does not vendor external dictionary data, and generated
packs stay opt-in and default-disabled.

The repository includes a tiny `bn` synthetic fixture only to test this generic
runtime contract. It is project-owned artificial data, default-disabled, and not
Bengali dictionary or morphology coverage. Real Bengali, Urdu, and CJK lexical
packs remain source-lock gated and are not bundled.

### Importing CoNLL-U Lemma Packs

`tools/import-conllu-lemma-pack.php` converts source-approved CoNLL-U or
Universal Dependencies style corpora into the normalized lemma TSV contract, then
uses the same analyzer-pack generation path described above. It reads `FORM` and
`LEMMA`, skips CoNLL-U comments, blank lines, multiword token rows, empty-node
rows, placeholder values, and values that do not normalize to one runtime token.

Use this for reviewed treebanks or build artifacts where the exact source,
license, source version, URL, and attribution are known. It is a pack-generation
path, not bundled broad dictionary coverage, and it does not download data or
hard-code word families.

```sh
php tools/import-conllu-lemma-pack.php \
  --source=/path/to/source-approved-treebank \
  --out=/srv/wp-fts-packs/es-ud-lemma-pack \
  --language=es \
  --pack-id=es-ud-lemma-pack \
  --version=2026.06 \
  --source-name="Reviewed Universal Dependencies source" \
  --source-url="https://example.test/source-artifact" \
  --license=CC-BY-SA-4.0 \
  --license-url="https://creativecommons.org/licenses/by-sa/4.0/" \
  --source-version=2026.06 \
  --attribution="Required upstream attribution text"
```

The same path is available in WordPress through WP-CLI:

```sh
wp fts import-conllu-lemma-pack \
  --source=/path/to/source-approved-treebank \
  --language=es \
  --pack-id=es-ud-lemma-pack \
  --version=2026.06 \
  --source-name="Reviewed Universal Dependencies source" \
  --source-url="https://example.test/source-artifact" \
  --license=CC-BY-SA-4.0 \
  --attribution="Required upstream attribution text" \
  --enable
```

`--source` may point to one file or a directory. Directory imports recursively
read stable-sorted `.conllu` files. `--enable` stores the generated manifest in
the runtime analyzer options; reindex existing content after enabling a new pack
so stored index terms use the new lemmatizer.

### Importing UniMorph-Style Lemma Packs

`tools/import-unimorph-lemma-pack.php` converts source-approved inflection
tables shaped like UniMorph rows into the normalized lemma TSV contract, then
uses the same analyzer-pack generation path described above. Each non-comment
input row must be `lemma<TAB>surface<TAB>features`. Comments, blank rows,
placeholder lemma/surface values, and values that do not normalize to one
runtime token are skipped; rows with any other field count are rejected.

Use this for reviewed dictionary-shaped build artifacts where the exact source,
license, source version, URL, and attribution are known. It is a
pack-generation path, not bundled broad dictionary coverage, and it does not
download data or hard-code word families.

```sh
php tools/import-unimorph-lemma-pack.php \
  --source=/path/to/source-approved-unimorph-table \
  --out=/srv/wp-fts-packs/es-unimorph-lemma-pack \
  --language=es \
  --pack-id=es-unimorph-lemma-pack \
  --version=2026.06 \
  --source-name="Reviewed UniMorph source" \
  --source-url="https://example.test/source-artifact" \
  --license=CC-BY-SA-4.0 \
  --license-url="https://creativecommons.org/licenses/by-sa/4.0/" \
  --source-version=2026.06 \
  --attribution="Required upstream attribution text"
```

The same path is available in WordPress through WP-CLI:

```sh
wp fts import-unimorph-lemma-pack \
  --source=/path/to/source-approved-unimorph-table \
  --language=es \
  --pack-id=es-unimorph-lemma-pack \
  --version=2026.06 \
  --source-name="Reviewed UniMorph source" \
  --source-url="https://example.test/source-artifact" \
  --license=CC-BY-SA-4.0 \
  --attribution="Required upstream attribution text" \
  --enable
```

`--source` may point to one file or a directory. Directory imports recursively
read stable-sorted `.txt`, `.tsv`, and `.unimorph` files. `--enable` stores the
generated manifest in the runtime analyzer options; reindex existing content
after enabling a new pack so stored index terms use the new lemmatizer.

Full generated packs stay opt-in and default-disabled. The full CLARIN-PL
source archive, extracted TSV, and generated runtime shards are not bundled in
this repository or plugin package. Users or build systems must generate and
install the external pack before enabling either `polish_lemma_pack` or
`polish_lemmatizer_pack`.

Enable the verified Polish stemmer slice when fixture-backed stemming is more
important than preserving the exact default suffix-only behavior:

```php
$analyzer = new WP_FTS_Analyzer([
    'default_lang' => 'pl',
    'polish_stemming' => 'verified',
]);
```

The verified mode is still a stemmer. It maps a compact set of reviewed Polish
inflection forms to stems, protects ambiguous rows, and then falls back to the
conservative suffix stemmer for unknown terms. It is separate from a
Morfologik/PoliMorf lemmatizer pack, and it does not vendor a full dictionary.

Disable the Polish suffix stemmer while keeping other analyzer behavior:

```php
$analyzer = new WP_FTS_Analyzer([
    'default_lang' => 'pl',
    'enable_stemming' => true,
    'polish_stemming' => 'none',
]);
```

Use a custom stemmer callback when the built-in adapters are not enough:

```php
$analyzer = new WP_FTS_Analyzer([
    'stemmer' => static function (string $term, string $language): string {
        return $term;
    },
]);
```

Callbacks with a required second parameter receive the canonical language.
One-argument callbacks keep the legacy `($term)` form.

## Stopwords

The analyzer accepts global stopwords and language-specific stopwords. Stopwords
are normalized through the same pipeline before they are compared with indexed
or query terms.

```php
$analyzer = new WP_FTS_Analyzer([
    'default_lang' => 'en',
    'stopwords' => ['the', 'and'],
    'stopwords_by_lang' => [
        'pl' => ['oraz'],
        'de' => ['und'],
    ],
]);
```

The WordPress runtime analyzer does not configure stopwords by default.

## Custom Fields And Content Extraction

Bulk reindexing and runtime post-save indexing both use
`WP_FTS_PostContentExtractor`. The extractor builds weighted fields for title,
content, excerpt, rendered block deltas, taxonomy terms, selected custom fields,
and product metadata used by filters, snippets, and CLI/REST enrichment.

Custom fields can be selected with extractor options:

```php
$indexer->index_post($post, [
    'lang' => 'en-US',
    'custom_fields' => ['subtitle', 'sku'],
]);
```

The default runtime plugin path also reads the `wp_fts_index_custom_fields`
option when present:

```sh
wp option update wp_fts_index_custom_fields '["subtitle","sku"]' --format=json
wp fts reindex --post_type=post,page --post_status=publish
```

Filters can adjust selected fields, metadata, terms, custom field values, and
boosts:

```php
add_filter('wp_fts_post_index_fields', static function (array $fields, object $post, array $opts): array {
    $fields[] = [
        'name' => 'subtitle',
        'text' => (string) get_post_meta((int) $post->ID, 'subtitle', true),
        'boost' => 2.0,
    ];

    return $fields;
}, 10, 3);
```

Rendered block output is included by default when `do_blocks()` is available,
but only the rendered-only delta is added so static block text is not counted
twice. Shortcode rendering is opt-in:

```php
$indexer->index_post($post, [
    'render_shortcodes' => true,
]);
```

Programmatic callers can still compose custom HTML and index it directly with
`index_document()` when they need a non-post document shape. Direct document
indexing is separate from the WordPress post extractor and should supply
metadata explicitly if the result needs post filters or snippets.

## BM25 And Search Options

WP-CLI exposes the supported search options:

```sh
wp fts search "query text" --mode=OR --limit=10 --lang=en
wp fts search "query text" --mode=AND --limit=10 --lang=en
```

`OR` is the default and returns documents matching any query term. `AND` requires
every query term to be present. `limit` is clamped to at least 1.

Programmatic callers can set BM25 parameters in the searcher constructor:

```php
$searcher = new WP_FTS_Searcher(
    $storage,
    $analyzer,
    1.2, // k1: term-frequency saturation
    0.75 // b: document-length normalization
);
```

Snippet generation uses bounded extracted metadata text stored at index time.
When highlighting is enabled, snippet tokens are analyzed before comparison, so
a snippet can highlight a different inflected surface form when the query and
candidate token normalize to the same analyzed key.

Current search does not support phrases, positions, field-specific result
explanations, facets, typo tolerance, query-time synonyms, or cross-language
score merging.

## Storage Prefix

The WP-CLI path uses the active WordPress `$wpdb->prefix`, creating tables such
as `wp_fts_terms` and `wp_fts_docs`. Programmatic callers can pass a custom
prefix:

```php
$storage = new WP_FTS_Storage_Mysql($wpdb, 'custom_');
$storage->create_tables();
```
