<?php
declare(strict_types=1);

/**
 * WP-CLI command surface for managing the custom FTS index.
 *
 * The command creates MySQL tables on demand, reindexes WordPress posts, searches
 * the index, tombstones documents, and compacts deleted rows.
 */
final class WP_FTS_WPCLI_Command
{
    /**
     * Register the `wp fts` command when WP-CLI is loaded.
     */
    public static function register(): void
    {
        if (class_exists('WP_CLI')) {
            WP_CLI::add_command('fts', self::class);
        }
    }

    /**
     * Reindex posts into the custom FTS tables.
     *
     * ## OPTIONS
     *
     * [--post_status=<status>]
     * : Comma-separated statuses. Default: publish.
     *
     * [--post_type=<type>]
     * : Comma-separated post types. Default: post.
     *
     * [--lang=<language>]
     * : Force a language partition for indexed posts. Defaults to per-post/site language resolution.
     *
     * [--limit=<n>]
     * : Maximum posts to index. Default: unlimited.
     *
     * [--batch_size=<n>]
     * : Batch size. Default: 500.
     *
     * @param string[] $args Positional arguments; unused.
     * @param array<string,mixed> $assoc_args WP-CLI options. Dashed and
     *        underscored option names are both accepted for post status/type and
     *        batch size.
     */
    public function reindex(array $args, array $assoc_args): void
    {
        $langArg = $this->assoc_arg($assoc_args, ['lang', 'language'], null);
        $lang = $langArg !== null ? $this->language_arg($langArg) : null;
        $indexer = $this->indexer();
        $options = [
            'post_status' => $this->csv_arg((string) $this->assoc_arg($assoc_args, ['post_status', 'post-status'], 'publish'), 'publish'),
            'post_type' => $this->csv_arg((string) $this->assoc_arg($assoc_args, ['post_type', 'post-type'], 'post'), 'post'),
            'limit' => $this->non_negative_int_arg($this->assoc_arg($assoc_args, ['limit'], 0), 0),
            'batch_size' => $this->positive_int_arg($this->assoc_arg($assoc_args, ['batch_size', 'batch-size'], 500), 500),
        ];
        if ($lang !== null) {
            $options['lang'] = $lang;
        }

        $count = $indexer->reindex_all($options);

        WP_CLI::success($lang !== null ? "Indexed {$count} posts in {$lang}." : "Indexed {$count} posts.");
    }

    /**
     * Search the FTS index.
     *
     * ## OPTIONS
     *
     * <query>
     * : Search query.
     *
     * [--mode=<OR|AND>]
     * : Boolean mode. Default: OR.
     *
     * [--limit=<n>]
     * : Result count. Default: 10.
     *
     * [--lang=<language>]
     * : Query language partition. Defaults to site locale.
     *
     * @param string[] $args First positional argument is the query string.
     * @param array<string,mixed> $assoc_args Options for mode, limit, and
     *        language. Missing language falls back to site locale or `und`.
     */
    public function search(array $args, array $assoc_args): void
    {
        $query = (string) ($args[0] ?? '');
        $searcher = new WP_FTS_Searcher($this->storage(), new WP_FTS_Analyzer());
        $results = $searcher->search($query, [
            'mode' => (string) ($assoc_args['mode'] ?? 'OR'),
            'limit' => $this->positive_int_arg($this->assoc_arg($assoc_args, ['limit'], 10), 10),
            'lang' => $this->language_arg($this->assoc_arg($assoc_args, ['lang', 'language'], null)),
        ]);

        WP_CLI\Utils\format_items('table', $results, ['doc_id', 'score']);
    }

    /**
     * Tombstone a document.
     *
     * ## OPTIONS
     *
     * <doc_id>
     * : Document ID to delete.
     *
     * @param string[] $args First positional argument is the document id.
     * @param array<string,mixed> $assoc_args Unused WP-CLI options.
     */
    public function delete(array $args, array $assoc_args): void
    {
        $docId = (int) ($args[0] ?? 0);
        $deleted = $this->indexer()->delete_document($docId);
        if ($deleted) {
            WP_CLI::success("Deleted document {$docId}.");
            return;
        }

        WP_CLI::warning("Document {$docId} was not indexed.");
    }

    /**
     * Compact tombstones out of posting lists.
     *
     * @param string[] $args Positional arguments; unused.
     * @param array<string,mixed> $assoc_args Unused WP-CLI options.
     */
    public function optimize(array $args, array $assoc_args): void
    {
        $this->indexer()->optimize();
        WP_CLI::success('Optimized FTS index.');
    }

    /**
     * Build an indexer wired to MySQL storage and the default analyzer.
     */
    private function indexer(): WP_FTS_Indexer
    {
        return new WP_FTS_Indexer($this->storage(), new WP_FTS_Analyzer());
    }

    /**
     * Create MySQL storage and ensure required tables exist.
     *
     * @return WP_FTS_Storage_Mysql Ready-to-use storage backend.
     * @throws RuntimeException When `$wpdb` is unavailable.
     */
    private function storage(): WP_FTS_Storage_Mysql
    {
        global $wpdb;

        if (!isset($wpdb) || !is_object($wpdb)) {
            throw new RuntimeException('WP-CLI command requires $wpdb.');
        }

        return WP_FTS_Plugin::storage(true);
    }

    /**
     * Parse a comma-separated WP-CLI option into a non-empty list.
     *
     * Empty input falls back to a single default item.
     *
     * @return string[]
     */
    private function csv_arg(string $value, string $fallback): array
    {
        $items = array_map('trim', explode(',', $value));
        $items = array_values(array_filter($items, static fn(string $item): bool => $item !== ''));

        return $items === [] ? [$fallback] : $items;
    }

    /**
     * Return the first present associated argument from a list of accepted names.
     *
     * This lets commands accept both WP-CLI's dashed names and PHP-friendly
     * underscored names.
     *
     * @param array<string,mixed> $assoc_args
     * @param string[] $names
     * @param mixed $default Value returned when none of the names is present.
     * @return mixed Matched value or `$default`.
     */
    private function assoc_arg(array $assoc_args, array $names, mixed $default): mixed
    {
        foreach ($names as $name) {
            if (array_key_exists($name, $assoc_args)) {
                return $assoc_args[$name];
            }
        }

        return $default;
    }

    /**
     * Resolve and canonicalize a CLI language option.
     *
     * A scalar non-empty option wins. Otherwise the command falls back to
     * WordPress site language and finally the storage default `und`.
     *
     * @param mixed $value Raw WP-CLI option value.
     * @return string Canonical language partition.
     */
    private function language_arg(mixed $value): string
    {
        if (is_scalar($value) && trim((string) $value) !== '') {
            return WP_FTS_TermNamespace::canonicalize_lang((string) $value);
        }

        if (function_exists('get_locale')) {
            $locale = get_locale();
            if (is_string($locale) && $locale !== '') {
                return WP_FTS_TermNamespace::canonicalize_lang($locale);
            }
        }

        if (function_exists('get_bloginfo')) {
            $siteLang = get_bloginfo('language');
            if (is_string($siteLang) && $siteLang !== '') {
                return WP_FTS_TermNamespace::canonicalize_lang($siteLang);
            }
        }

        return WP_FTS_TermNamespace::DEFAULT_LANG;
    }

    /**
     * Parse an integer option and clamp it to at least one.
     */
    private function positive_int_arg(mixed $value, int $fallback): int
    {
        $number = is_numeric($value) ? (int) $value : $fallback;
        return max(1, $number);
    }

    /**
     * Parse an integer option and clamp it to zero or greater.
     */
    private function non_negative_int_arg(mixed $value, int $fallback): int
    {
        $number = is_numeric($value) ? (int) $value : $fallback;
        return max(0, $number);
    }
}
