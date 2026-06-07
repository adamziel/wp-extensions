# Configuration

This branch has no settings screen and no persisted WordPress options for the
indexer. WP-CLI commands use the default analyzer and MySQL storage. More
advanced configuration is available to PHP callers that instantiate
`WP_FTS_Analyzer`, `WP_FTS_LanguagePipeline`, `WP_FTS_Searcher`, or
`WP_FTS_Storage_Mysql` directly.

## Languages

Every stored term is namespaced by language. The stored key shape is:

```text
<language>\x1e<term>
```

Language tags are canonicalized from WordPress-style locales and BCP 47-style
input. For example, `en_US` becomes `en-US`, `pl_PL` becomes `pl-PL`, and empty
values fall back to the caller's default language.

Document language resolution during `wp fts reindex` follows this order:

1. Explicit `--lang` or `--language`.
2. Polylang `pll_get_post_language( $post_id, 'locale' )`, when available.
3. WPML `wpml_post_language_details`, when available.
4. The WordPress site locale from `get_locale()` or `get_bloginfo( 'language' )`.
5. The indexer default.

HTML `lang` and `xml:lang` attributes can route individual content segments into
their own language partition while the post still has one primary language for
metadata and change detection.

Query language resolution follows the same idea. Prefer passing `--lang` on
operational searches so the query and indexed documents use the same partition:

```sh
wp fts search "zamek" --lang=pl-PL
wp fts search "castle" --lang=en-US
```

The current searcher scores one language partition per query. It does not merge
results across languages.

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

## Stemmers

Stemming is disabled by default. If a PHP caller enables stemming, the pipeline
uses:

- Snowball through `wamania/php-stemmer` for the allowlisted languages that pass
  the bundled compliance harness: Catalan (`ca`) and Dutch Porter (`nl`);
- a small conservative Polish suffix stemmer for `pl`;
- no-op behavior for unsupported languages or missing optional dependencies.

Enable the built-in stemming path programmatically:

```php
$analyzer = new WP_FTS_Analyzer([
    'default_lang' => 'nl',
    'enable_stemming' => true,
]);
```

For Polish, the current mode is intentionally conservative:

```php
$analyzer = new WP_FTS_Analyzer([
    'default_lang' => 'pl',
    'enable_stemming' => true,
    'polish_stemming' => 'conservative',
]);
```

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

Bulk reindexing currently indexes only `post_title` and `post_content`. There is
no public WordPress filter yet for adding custom fields, taxonomies, excerpts, or
template-rendered content to the WP-CLI reindex payload.

Programmatic callers can still compose their own HTML and index it directly:

```php
global $wpdb;

$storage = new WP_FTS_Storage_Mysql($wpdb);
$storage->create_tables();

$indexer = new WP_FTS_Indexer($storage, new WP_FTS_Analyzer());
$html = sprintf(
    '<!doctype html><html><head><title>%s</title></head><body>%s<section>%s</section></body></html>',
    esc_html(get_the_title($post_id)),
    (string) get_post_field('post_content', $post_id),
    esc_html((string) get_post_meta($post_id, 'subtitle', true))
);

$indexer->index_document($post_id, $html, [
    'post_id' => $post_id,
    'lang' => 'en-US',
]);
```

The expected future WordPress extension point is a filter around the composed
post HTML before `index_document()` is called. That filter is not present on this
branch.

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
