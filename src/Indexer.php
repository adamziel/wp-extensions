<?php
declare(strict_types=1);

final class WP_FTS_Indexer
{
    public function __construct(
        private WP_FTS_Storage $storage,
        private WP_FTS_Analyzer $analyzer,
    ) {
    }

    /**
     * Index or replace a document.
     *
     * @return bool true when the index changed, false when the content hash matched
     */
    public function index_document(int $doc_id, string $html, array $opts = []): bool
    {
        if ($doc_id < 0) {
            throw new InvalidArgumentException('Document id must be non-negative.');
        }

        $hash = sha1($html);
        $existing = $this->storage->get_doc($doc_id);
        if ($existing !== null && !$existing['deleted'] && $existing['content_hash'] === $hash) {
            return false;
        }

        $termFrequencies = $this->analyzer->weighted_term_frequencies(
            $this->analyzer->analyze_content($html)
        );
        $docLen = array_sum($termFrequencies);

        $this->storage->begin_transaction();
        try {
            if ($existing !== null) {
                $this->remove_doc_from_all_terms($doc_id);
                if (!$existing['deleted']) {
                    $this->storage->add_meta(-1, -$existing['doc_len']);
                }
            }

            if ($termFrequencies !== []) {
                $rows = $this->storage->get_terms(array_keys($termFrequencies));
                foreach ($termFrequencies as $term => $weightedTf) {
                    $postings = isset($rows[$term])
                        ? WP_FTS_PostingsCodec::decode($rows[$term]['postings'])
                        : [];
                    $postings[$doc_id] = $weightedTf;
                    ksort($postings, SORT_NUMERIC);
                    $this->storage->put_term(
                        $term,
                        count($postings),
                        WP_FTS_PostingsCodec::encode($postings)
                    );
                }
            }

            $this->storage->put_doc($doc_id, $docLen, $hash);
            $this->storage->add_meta(1, $docLen);
            $this->storage->commit();
        } catch (Throwable $e) {
            $this->storage->rollback();
            throw $e;
        }

        return true;
    }

    public function delete_document(int $doc_id): bool
    {
        $existing = $this->storage->get_doc($doc_id);
        if ($existing === null || $existing['deleted']) {
            return false;
        }

        $this->storage->begin_transaction();
        try {
            $this->storage->delete_doc($doc_id);
            $this->storage->add_meta(-1, -$existing['doc_len']);
            $this->storage->commit();
        } catch (Throwable $e) {
            $this->storage->rollback();
            throw $e;
        }

        return true;
    }

    /**
     * Reindex published posts directly from WordPress. Defaults match the v1 spec.
     *
     * @param array{post_status?:string|string[],post_type?:string|string[],batch_size?:int} $opts
     */
    public function reindex_all(array $opts = []): int
    {
        global $wpdb;

        if (!isset($wpdb) || !is_object($wpdb)) {
            throw new RuntimeException('reindex_all requires the WordPress $wpdb global.');
        }

        $postStatuses = $this->normalize_list_option($opts['post_status'] ?? 'publish');
        $postTypes = $this->normalize_list_option($opts['post_type'] ?? 'post');
        $batchSize = max(1, (int) ($opts['batch_size'] ?? 500));
        $last = 0;
        $count = 0;

        do {
            $statusPlaceholders = implode(',', array_fill(0, count($postStatuses), '%s'));
            $typePlaceholders = implode(',', array_fill(0, count($postTypes), '%s'));
            $args = array_merge($postStatuses, $postTypes, [$last, $batchSize]);

            $sql = $wpdb->prepare(
                "SELECT ID, post_content, post_title
FROM {$wpdb->posts}
WHERE post_status IN ({$statusPlaceholders})
  AND post_type IN ({$typePlaceholders})
  AND ID > %d
ORDER BY ID ASC
LIMIT %d",
                ...$args
            );

            $rows = $wpdb->get_results($sql);
            foreach ($rows ?: [] as $row) {
                $last = (int) $row->ID;
                $html = $this->compose_post_html((string) $row->post_title, (string) $row->post_content);
                $this->index_document($last, $html);
                $count++;
            }
        } while (!empty($rows));

        $this->flush();

        return $count;
    }

    public function flush(): void
    {
        $this->storage->flush();
    }

    public function optimize(): void
    {
        $this->storage->optimize();
    }

    private function remove_doc_from_all_terms(int $doc_id): void
    {
        $terms = $this->storage->all_terms();
        if ($terms === []) {
            return;
        }

        foreach ($this->storage->get_terms($terms) as $term => $row) {
            $postings = WP_FTS_PostingsCodec::decode($row['postings']);
            if (!array_key_exists($doc_id, $postings)) {
                continue;
            }

            unset($postings[$doc_id]);
            if ($postings === []) {
                $this->storage->delete_term($term);
                continue;
            }

            $this->storage->put_term(
                $term,
                count($postings),
                WP_FTS_PostingsCodec::encode($postings)
            );
        }
    }

    /**
     * @return string[]
     */
    private function normalize_list_option(string|array $value): array
    {
        $items = is_array($value) ? $value : [$value];
        $items = array_values(array_filter(array_map('strval', $items), static fn(string $item): bool => $item !== ''));
        if ($items === []) {
            throw new InvalidArgumentException('List options must contain at least one value.');
        }

        return $items;
    }

    private function compose_post_html(string $title, string $content): string
    {
        $title = htmlspecialchars($title, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');

        return "<!doctype html><html><head><title>{$title}</title></head><body>{$content}</body></html>";
    }
}
