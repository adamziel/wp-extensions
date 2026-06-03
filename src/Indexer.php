<?php
declare(strict_types=1);

final class WP_FTS_Indexer
{
    public function __construct(
        private WP_FTS_Storage $storage,
        private object $analyzer,
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

        $primaryLang = $this->resolve_language($opts['lang'] ?? $opts['language'] ?? null);
        $hash = sha1($primaryLang . WP_FTS_Language::TERM_SEPARATOR . $html);
        $existing = $this->storage->get_doc($doc_id);
        if ($existing !== null && !$existing['deleted'] && $existing['content_hash'] === $hash) {
            return false;
        }

        [$termFrequencies, $langLengths] = $this->weighted_term_frequencies_by_lang(
            $this->analyze_content($html, $primaryLang),
            $primaryLang
        );

        $this->storage->begin_transaction();
        try {
            if ($existing !== null) {
                $this->remove_doc_from_all_terms($doc_id);
                if (!$existing['deleted']) {
                    foreach ($this->existing_lang_lengths($existing) as $lang => $docLen) {
                        $this->storage->add_meta($lang, -1, -$docLen);
                    }
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

            $this->storage->put_doc($doc_id, $primaryLang, $langLengths, $hash);
            foreach ($langLengths as $lang => $docLen) {
                $this->storage->add_meta($lang, 1, $docLen);
            }
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
            foreach ($this->existing_lang_lengths($existing) as $lang => $docLen) {
                $this->storage->add_meta($lang, -1, -$docLen);
            }
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
     * @param array{post_status?:string|string[],post_type?:string|string[],batch_size?:int,limit?:int,lang?:string,language?:string} $opts
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
        $limit = max(0, (int) ($opts['limit'] ?? 0));
        $last = 0;
        $count = 0;

        do {
            $currentBatchSize = $limit > 0 ? min($batchSize, $limit - $count) : $batchSize;
            if ($currentBatchSize <= 0) {
                break;
            }

            $statusPlaceholders = implode(',', array_fill(0, count($postStatuses), '%s'));
            $typePlaceholders = implode(',', array_fill(0, count($postTypes), '%s'));
            $args = array_merge($postStatuses, $postTypes, [$last, $currentBatchSize]);

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
                $this->index_document($last, $html, [
                    'lang' => $this->resolve_post_language($row, $opts),
                ]);
                $count++;
            }
        } while (!empty($rows) && ($limit === 0 || $count < $limit));

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
        $items = [];
        foreach (is_array($value) ? $value : [$value] as $item) {
            foreach (explode(',', (string) $item) as $part) {
                $part = trim($part);
                if ($part !== '') {
                    $items[] = $part;
                }
            }
        }
        $items = array_values(array_unique($items));
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

    /**
     * @return array<int,array{term:string,weight?:float,lang?:string}|string>
     */
    private function analyze_content(string $html, string $primaryLang): array
    {
        if (!is_callable([$this->analyzer, 'analyze_content'])) {
            throw new LogicException('Analyzer must provide analyze_content().');
        }

        return $this->analyzer->analyze_content($html, [
            'lang' => $primaryLang,
            'language' => $primaryLang,
            'document_lang' => $primaryLang,
        ]);
    }

    /**
     * @param array<int,array{term?:string,weight?:float,lang?:string}|string> $occurrences
     * @return array{0:array<string,int>,1:array<string,int>}
     */
    private function weighted_term_frequencies_by_lang(array $occurrences, string $primaryLang): array
    {
        $weighted = [];
        foreach ($occurrences as $occurrence) {
            if (is_array($occurrence)) {
                $term = (string) ($occurrence['term'] ?? '');
                $lang = $this->resolve_language($occurrence['lang'] ?? $primaryLang);
                $weight = (float) ($occurrence['weight'] ?? 1.0);
            } else {
                $term = (string) $occurrence;
                $lang = $primaryLang;
                $weight = 1.0;
            }

            if ($term === '') {
                continue;
            }
            if (!WP_FTS_Language::term_key_fits($term, $lang)) {
                continue;
            }
            $weighted[$lang][$term] = ($weighted[$lang][$term] ?? 0.0) + $weight;
        }

        $termFrequencies = [];
        $langLengths = [];
        foreach ($weighted as $lang => $terms) {
            foreach ($terms as $term => $weight) {
                $tf = max(1, (int) round($weight));
                $termFrequencies[WP_FTS_Language::term_key($term, $lang)] = $tf;
                $langLengths[$lang] = ($langLengths[$lang] ?? 0) + $tf;
            }
        }
        ksort($termFrequencies, SORT_STRING);
        ksort($langLengths, SORT_STRING);

        return [$termFrequencies, $langLengths];
    }

    /**
     * @param array{doc_len:int,lang?:string,primary_lang?:string,lang_lengths?:array<string,int>} $doc
     * @return array<string,int>
     */
    private function existing_lang_lengths(array $doc): array
    {
        if (isset($doc['lang_lengths']) && is_array($doc['lang_lengths'])) {
            return WP_FTS_Language::normalize_lengths($doc['lang_lengths']);
        }

        $docLen = max(0, (int) ($doc['doc_len'] ?? 0));
        if ($docLen === 0) {
            return [];
        }

        return [$this->resolve_language($doc['primary_lang'] ?? $doc['lang'] ?? null) => $docLen];
    }

    private function resolve_post_language(object $row, array $opts): string
    {
        if (isset($opts['lang']) || isset($opts['language'])) {
            return $this->resolve_language($opts['lang'] ?? $opts['language']);
        }

        $postId = isset($row->ID) ? (int) $row->ID : 0;
        if ($postId > 0 && function_exists('pll_get_post_language')) {
            $lang = pll_get_post_language($postId, 'locale');
            if (is_string($lang) && $lang !== '') {
                return $this->resolve_language($lang);
            }
        }

        if ($postId > 0 && function_exists('has_filter') && function_exists('apply_filters') && has_filter('wpml_post_language_details')) {
            $details = apply_filters('wpml_post_language_details', null, $postId);
            if (is_array($details) && isset($details['language_code'])) {
                return $this->resolve_language((string) $details['language_code']);
            }
        }

        return $this->resolve_language(null);
    }

    private function resolve_language(mixed $lang): string
    {
        if (is_string($lang) && trim($lang) !== '') {
            return WP_FTS_Language::canonicalize($lang);
        }

        if (function_exists('get_locale')) {
            $locale = get_locale();
            if (is_string($locale) && $locale !== '') {
                return WP_FTS_Language::canonicalize($locale);
            }
        }

        if (function_exists('get_bloginfo')) {
            $siteLang = get_bloginfo('language');
            if (is_string($siteLang) && $siteLang !== '') {
                return WP_FTS_Language::canonicalize($siteLang);
            }
        }

        return WP_FTS_Language::DEFAULT_LANG;
    }
}
