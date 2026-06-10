# Configuration

This branch has no settings screen for analyzer, search, or extractor
configuration. WP-CLI commands use the default analyzer and MySQL storage.
Operational options such as schema version and pending queue state are managed
internally, and selected custom fields can be supplied through an option or
filters. More advanced configuration is available to PHP callers that
instantiate `WP_FTS_Analyzer`, `WP_FTS_LanguagePipeline`, `WP_FTS_Searcher`, or
`WP_FTS_Storage_Mysql` directly.

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
- drops non-CJK terms shorter than 2 characters;
- rejects stored term keys over 255 bytes;
- tokenizes CJK script runs into single characters for one-character runs and
  overlapping bigrams for longer runs.

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

The WP-CLI commands currently create `new WP_FTS_Analyzer()` with no custom
options.

Analyzer behavior participates in stale-document detection. A reindex skips
unchanged content only when the source content, primary language, and
analyzer/index signature still match; stemming or language-pipeline changes
force existing documents to be rewritten.

## Stemmers

Stemming is enabled by default. The pipeline uses:

- bundled generated Snowball English/Porter2 for English (`en` and English
  locale tags), verified against the official `english` fixture data;
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

The WP-CLI default analyzer does not configure stopwords.

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

Current search does not support phrases, positions, field-specific result
explanations, facets, snippets, or cross-language score merging.

## Storage Prefix

The WP-CLI path uses the active WordPress `$wpdb->prefix`, creating tables such
as `wp_fts_terms` and `wp_fts_docs`. Programmatic callers can pass a custom
prefix:

```php
$storage = new WP_FTS_Storage_Mysql($wpdb, 'custom_');
$storage->create_tables();
```
