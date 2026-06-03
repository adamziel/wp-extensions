<?php
declare(strict_types=1);

final class WP_FTS_WPCLI_Command
{
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
     * [--batch_size=<n>]
     * : Batch size. Default: 500.
     */
    public function reindex(array $args, array $assoc_args): void
    {
        $indexer = $this->indexer();
        $count = $indexer->reindex_all([
            'post_status' => $this->csv_arg($assoc_args['post_status'] ?? 'publish', 'publish'),
            'post_type' => $this->csv_arg($assoc_args['post_type'] ?? 'post', 'post'),
            'batch_size' => isset($assoc_args['batch_size']) ? (int) $assoc_args['batch_size'] : 500,
        ]);

        WP_CLI::success("Indexed {$count} posts.");
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
     */
    public function search(array $args, array $assoc_args): void
    {
        $query = (string) ($args[0] ?? '');
        $searcher = new WP_FTS_Searcher($this->storage(), new WP_FTS_Analyzer());
        $results = $searcher->search($query, [
            'mode' => (string) ($assoc_args['mode'] ?? 'OR'),
            'limit' => isset($assoc_args['limit']) ? (int) $assoc_args['limit'] : 10,
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
     */
    public function optimize(array $args, array $assoc_args): void
    {
        $this->indexer()->optimize();
        WP_CLI::success('Optimized FTS index.');
    }

    private function indexer(): WP_FTS_Indexer
    {
        return new WP_FTS_Indexer($this->storage(), new WP_FTS_Analyzer());
    }

    private function storage(): WP_FTS_Storage_Mysql
    {
        global $wpdb;

        if (!isset($wpdb) || !is_object($wpdb)) {
            throw new RuntimeException('WP-CLI command requires $wpdb.');
        }

        $storage = new WP_FTS_Storage_Mysql($wpdb);
        $storage->create_tables();

        return $storage;
    }

    /**
     * @return string[]
     */
    private function csv_arg(string $value, string $fallback): array
    {
        $items = array_map('trim', explode(',', $value));
        $items = array_values(array_filter($items, static fn(string $item): bool => $item !== ''));

        return $items === [] ? [$fallback] : $items;
    }
}
