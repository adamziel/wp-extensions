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

        $primaryLang = $this->resolve_document_language($opts);
        $hash = $this->content_hash($html, $primaryLang);
        $existing = $this->storage->get_doc($doc_id);
        if ($existing !== null && !$existing['deleted'] && $existing['content_hash'] === $hash) {
            return false;
        }

        [$termFrequencies, $langLengths] = $this->weighted_term_frequencies_by_language(
            $this->analyze_content($html, $opts, $primaryLang),
            $primaryLang
        );

        $this->storage->begin_transaction();
        try {
            if ($existing !== null) {
                $this->remove_doc_from_all_terms($doc_id);
                if (!$existing['deleted']) {
                    $this->add_meta_deltas(
                        WP_FTS_StorageCompat::doc_lang_lengths($existing, $primaryLang),
                        -1
                    );
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

            WP_FTS_StorageCompat::put_doc($this->storage, $doc_id, $primaryLang, $langLengths, $hash);
            $this->add_meta_deltas($langLengths, 1);
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
            $this->add_meta_deltas(
                WP_FTS_StorageCompat::doc_lang_lengths($existing, WP_FTS_StorageCompat::doc_primary_lang($existing, 'en')),
                -1
            );
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

    private function resolve_document_language(array $opts): string
    {
        $default = WP_FTS_TermNamespace::default_language($opts);

        return WP_FTS_TermNamespace::language_from_options(
            $opts,
            $default,
            ['lang', 'language', 'primary_lang', 'document_lang', 'locale']
        ) ?? $default;
    }

    private function content_hash(string $html, string $primaryLang): string
    {
        return sha1(WP_FTS_TermNamespace::canonicalize_lang($primaryLang) . "\0" . $html);
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function analyze_content(string $html, array $opts, string $primaryLang): array
    {
        $analysisOpts = $opts;
        $analysisOpts['default_lang'] = $primaryLang;
        $analysisOpts['document_lang'] = $primaryLang;
        if (WP_FTS_TermNamespace::language_from_options($opts, null, ['lang', 'language', 'primary_lang', 'document_lang']) !== null) {
            $analysisOpts['lang'] = $primaryLang;
            $analysisOpts['language'] = $primaryLang;
        }

        return $this->analyzer->analyze_content($html, $analysisOpts);
    }

    /**
     * @param array<int,array<string,mixed>|string> $occurrences
     * @return array{0:array<string,int>,1:array<string,int>}
     */
    private function weighted_term_frequencies_by_language(array $occurrences, string $defaultLang): array
    {
        $weights = [];
        foreach ($occurrences as $occurrence) {
            $term = is_array($occurrence)
                ? trim((string) ($occurrence['term'] ?? ''))
                : trim((string) $occurrence);
            if ($term === '') {
                continue;
            }

            $split = WP_FTS_TermNamespace::split_term($term);
            $lang = is_array($occurrence) && isset($occurrence['lang'])
                ? WP_FTS_TermNamespace::canonicalize_lang((string) $occurrence['lang'], $defaultLang)
                : WP_FTS_TermNamespace::canonicalize_lang($defaultLang);
            if ($split !== null) {
                $lang = $split['lang'];
                $term = $split['term'];
            }

            $weight = is_array($occurrence) ? (float) ($occurrence['weight'] ?? 1.0) : 1.0;
            if ($weight <= 0.0) {
                continue;
            }

            $namespacedTerm = WP_FTS_TermNamespace::namespace_term($lang, $term);
            $weights[$namespacedTerm] = ($weights[$namespacedTerm] ?? 0.0) + $weight;
        }

        $frequencies = [];
        $langLengths = [];
        foreach ($weights as $term => $weight) {
            $weightedTf = max(1, (int) round($weight));
            $frequencies[$term] = $weightedTf;

            $split = WP_FTS_TermNamespace::split_term($term);
            $lang = $split !== null ? $split['lang'] : WP_FTS_TermNamespace::canonicalize_lang($defaultLang);
            $langLengths[$lang] = ($langLengths[$lang] ?? 0) + $weightedTf;
        }

        ksort($frequencies, SORT_STRING);
        ksort($langLengths, SORT_STRING);

        return [$frequencies, $langLengths];
    }

    /**
     * @param array<string,int> $langLengths
     */
    private function add_meta_deltas(array $langLengths, int $docDelta): void
    {
        $langLengths = WP_FTS_StorageCompat::normalize_lang_lengths($langLengths);
        if ($langLengths === []) {
            return;
        }

        if (!WP_FTS_StorageCompat::supports_language_meta($this->storage)) {
            WP_FTS_StorageCompat::add_meta($this->storage, 'en', $docDelta, $docDelta * array_sum($langLengths));
            return;
        }

        foreach ($langLengths as $lang => $length) {
            WP_FTS_StorageCompat::add_meta($this->storage, $lang, $docDelta, $docDelta * $length);
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
