<?php
declare(strict_types=1);

/**
 * Maintains postings, document metadata, and collection statistics for the FTS index.
 *
 * The indexer accepts HTML documents, delegates text analysis to the analyzer,
 * stores terms under language namespaces, and keeps per-language document
 * lengths in sync so the searcher can score BM25 inside one language partition.
 */
final class WP_FTS_Indexer
{
    /**
     * @param WP_FTS_Storage $storage Storage backend for terms, documents, and
     *        metadata. Backends may be language-aware or legacy aggregate-only.
     * @param object $analyzer Analyzer object exposing `analyze_content()`.
     */
    public function __construct(
        private WP_FTS_Storage $storage,
        private object $analyzer,
        private ?WP_FTS_PostContentExtractor $postContentExtractor = null,
    ) {
        $this->postContentExtractor ??= new WP_FTS_PostContentExtractor();
    }

    /**
     * Add a document to the index, or replace its existing postings and metadata.
     *
     * Pass the same stable `$doc_id` whenever the same logical document is
     * reindexed. `$html` may be a fragment or a full document; the analyzer reads
     * visible text, applies element boosts, and honors HTML language scopes. Use
     * `$opts['lang']`, `$opts['language']`, `$opts['primary_lang']`,
     * `$opts['document_lang']`, or `$opts['locale']` when the caller already
     * knows the document language.
     *
     * The content hash includes the resolved primary language, so the same HTML
     * can be reindexed when its language partition changes. Replacements remove
     * old postings and subtract old per-language metadata before adding the new
     * postings and document lengths in one storage transaction.
     *
     * @param int $doc_id Stable non-negative document identifier used in
     *        postings.
     * @param string $html HTML fragment or document to analyze.
     * @param array<string,mixed> $opts Document analysis options. Important keys
     *        are `lang`, `language`, `primary_lang`, `document_lang`, `locale`,
     *        and WordPress context such as `post_id`.
     * @return bool True when postings or metadata changed; false when an
     *         existing active document already had the same content hash.
     * @throws InvalidArgumentException If `$doc_id` is negative.
     * @throws LogicException If the analyzer does not provide `analyze_content()`.
     * @throws Throwable Re-throws storage/analyzer failures after rollback.
     */
    public function index_document(int $doc_id, string $html, array $opts = []): bool
    {
        if ($doc_id < 0) {
            throw new InvalidArgumentException('Document id must be non-negative.');
        }

        $primaryLang = $this->resolve_document_language($opts);
        $hash = $this->content_hash($html, $primaryLang);
        $metadata = isset($opts['metadata']) && is_array($opts['metadata'])
            ? $opts['metadata']
            : null;

        return $this->index_occurrences(
            $doc_id,
            $primaryLang,
            $hash,
            $this->analyze_content($html, $opts, $primaryLang),
            $metadata
        );
    }

    /**
     * Index weighted product fields as one document.
     *
     * Each field may include `name`, `text`, optional raw `html`, and `boost`.
     * The analyzer still honors language scopes inside each field; the field
     * boost multiplies analyzer weights before terms are rounded into integer
     * frequencies. This keeps title/excerpt/term/custom-field contributions
     * tunable without changing the postings format.
     *
     * @param int $doc_id Stable non-negative document identifier.
     * @param array<int,array<string,mixed>|string> $fields Weighted fields or
     *        legacy string fields.
     * @param array<string,mixed> $opts Document options plus optional
     *        `metadata` and `field_boosts`.
     */
    public function index_document_fields(int $doc_id, array $fields, array $opts = []): bool
    {
        if ($doc_id < 0) {
            throw new InvalidArgumentException('Document id must be non-negative.');
        }

        $primaryLang = $this->resolve_document_language($opts);
        $fields = $this->normalize_index_fields($fields, $opts);
        $metadata = isset($opts['metadata']) && is_array($opts['metadata'])
            ? $opts['metadata']
            : null;
        $hash = $this->content_hash($this->fields_hash_source($fields, $metadata), $primaryLang);

        $occurrences = [];
        foreach ($fields as $field) {
            $fieldOpts = $opts;
            $fieldOpts['field_name'] = $field['name'];
            $html = isset($field['html'])
                ? (string) $field['html']
                : '<div>' . htmlspecialchars($field['text'], ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8') . '</div>';

            foreach ($this->analyze_content($html, $fieldOpts, $primaryLang) as $occurrence) {
                if (is_array($occurrence)) {
                    $occurrence['weight'] = (float) ($occurrence['weight'] ?? 1.0) * $field['boost'];
                }
                $occurrences[] = $occurrence;
            }
        }

        return $this->index_occurrences($doc_id, $primaryLang, $hash, $occurrences, $metadata);
    }

    /**
     * Store analyzed occurrences, document lengths, and optional metadata.
     *
     * @param array<int,array<string,mixed>|string> $occurrences
     * @param array<string,mixed>|null $metadata
     */
    private function index_occurrences(int $doc_id, string $primaryLang, string $hash, array $occurrences, ?array $metadata): bool
    {
        $existing = $this->storage->get_doc($doc_id);
        if ($existing !== null && !$existing['deleted'] && $existing['content_hash'] === $hash) {
            if ($metadata !== null) {
                WP_FTS_StorageCompat::put_doc_metadata($this->storage, $doc_id, $metadata);
            }
            return false;
        }

        [$termFrequencies, $langLengths] = $this->weighted_term_frequencies_by_language($occurrences, $primaryLang);

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
            if ($metadata !== null) {
                WP_FTS_StorageCompat::put_doc_metadata($this->storage, $doc_id, $metadata);
            }
            $this->add_meta_deltas($langLengths, 1);
            $this->storage->commit();
        } catch (Throwable $e) {
            $this->storage->rollback();
            throw $e;
        }

        return true;
    }

    /**
     * Mark an indexed document as deleted and subtract its metadata contribution.
     *
     * Deletion is a tombstone operation: document metadata is marked deleted, but
     * posting lists are compacted later by `optimize()`. This keeps deletes fast
     * while allowing the searcher to ignore tombstoned docs through doc lengths.
     *
     * @param int $doc_id Document identifier previously passed to
     *        `index_document()`.
     * @return bool True when an active indexed document was tombstoned; false
     *         when the id was unknown or already deleted.
     * @throws Throwable Re-throws storage failures after rollback.
     */
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
     * This method pages through `$wpdb->posts` by ascending ID and indexes title
     * plus content for each row. `post_status` and `post_type` accept comma
     * separated strings or arrays. `limit => 0` means no limit. Language is
     * resolved per post unless an explicit language option is supplied.
     *
     * @param array{post_status?:string|string[],post_type?:string|string[],batch_size?:int,limit?:int,lang?:string,language?:string} $opts
     *        WordPress query and language options.
     * @return int Number of posts processed, including no-op reindexes.
     * @throws RuntimeException Outside WordPress or without a `$wpdb` object.
     * @throws InvalidArgumentException If list options normalize to an empty
     *         list.
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
                "SELECT ID, post_content, post_title, post_excerpt, post_type, post_status, post_date_gmt, post_date
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
                $lang = $this->resolve_post_language($row, $opts);
                $extracted = $this->postContentExtractor->extract($row, $opts);
                $indexOptions = $opts;
                $indexOptions['lang'] = $lang;
                $indexOptions['post_id'] = $last;
                $indexOptions['metadata'] = $extracted['metadata'];
                $indexOptions['field_boosts'] = $extracted['field_boosts'];
                $this->index_document_fields($last, $extracted['fields'], $indexOptions);
                $count++;
            }
        } while (!empty($rows) && ($limit === 0 || $count < $limit));

        $this->flush();

        return $count;
    }

    /**
     * Flush buffered storage changes, if the backend buffers writes.
     */
    public function flush(): void
    {
        $this->storage->flush();
    }

    /**
     * Ask the storage backend to compact deleted documents and posting lists.
     */
    public function optimize(): void
    {
        $this->storage->optimize();
    }

    /**
     * Remove one document id from every posting list that currently references it.
     *
     * Reindexing must do this before writing new postings because a document's
     * language, terms, and weighted frequencies may all change. Empty posting
     * lists are removed entirely.
     */
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
     * Resolve the primary language used for hashing and default term partitioning.
     *
     * Explicit document options win over the environment default. The analyzer
     * may still return segment-level languages for nested HTML scopes.
     *
     * @param array<string,mixed> $opts Public `index_document()` options.
     * @return string Canonical primary language.
     */
    private function resolve_document_language(array $opts): string
    {
        $default = WP_FTS_TermNamespace::default_language($opts);

        return WP_FTS_TermNamespace::language_from_options(
            $opts,
            $default,
            ['lang', 'language', 'primary_lang', 'document_lang', 'locale']
        ) ?? $default;
    }

    /**
     * Hash document content together with its primary language partition.
     *
     * The NUL separator keeps the language and HTML portions unambiguous while
     * preserving the existing SHA-1 storage shape.
     */
    private function content_hash(string $html, string $primaryLang): string
    {
        return sha1(WP_FTS_TermNamespace::canonicalize_lang($primaryLang) . "\0" . $html);
    }

    /**
     * Invoke the analyzer with document-language defaults filled in.
     *
     * When callers supplied an explicit document language, both `lang` and
     * `language` are rewritten to the resolved primary language so older
     * analyzers that only read those keys stay aligned with the indexer's hash
     * and storage partition.
     *
     * @return array<int,array<string,mixed>>
     */
    private function analyze_content(string $html, array $opts, string $primaryLang): array
    {
        if (!is_callable([$this->analyzer, 'analyze_content'])) {
            throw new LogicException('Analyzer must provide analyze_content().');
        }

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
     * Normalize index fields supplied by the extractor or direct callers.
     *
     * @param array<int,array<string,mixed>|string> $fields
     * @param array<string,mixed> $opts
     * @return array<int,array{name:string,text:string,html?:string,boost:float}>
     */
    private function normalize_index_fields(array $fields, array $opts): array
    {
        $boosts = [];
        foreach (($opts['field_boosts'] ?? []) as $field => $boost) {
            if (is_scalar($field) && is_numeric($boost)) {
                $boosts[(string) $field] = $this->normalize_field_boost((float) $boost);
            }
        }

        $normalized = [];
        foreach ($fields as $i => $field) {
            if (is_string($field)) {
                $field = ['name' => 'content', 'text' => $field];
            }
            if (!is_array($field)) {
                continue;
            }

            $name = trim((string) ($field['name'] ?? 'field_' . $i));
            $text = trim((string) ($field['text'] ?? ($field['html'] ?? '')));
            $html = isset($field['html']) ? (string) $field['html'] : null;
            if ($name === '' || ($text === '' && trim((string) $html) === '')) {
                continue;
            }

            $row = [
                'name' => $name,
                'text' => $text,
                'boost' => $this->normalize_field_boost((float) ($field['boost'] ?? ($boosts[$name] ?? 1.0))),
            ];
            if ($html !== null && trim($html) !== '') {
                $row['html'] = $html;
            }
            $normalized[] = $row;
        }

        return $normalized;
    }

    /**
     * Clamp field boosts to a positive bounded range.
     */
    private function normalize_field_boost(float $boost): float
    {
        return $boost > 0.0 ? min(100.0, $boost) : 1.0;
    }

    /**
     * Build a deterministic hash source for field-based indexing.
     *
     * Metadata is included so visibility/status/date changes refresh product
     * filters even when the searchable text stays the same.
     *
     * @param array<int,array{name:string,text:string,html?:string,boost:float}> $fields
     * @param array<string,mixed>|null $metadata
     */
    private function fields_hash_source(array $fields, ?array $metadata): string
    {
        return json_encode([
            'fields' => $fields,
            'metadata' => $metadata === null ? null : WP_FTS_StorageCompat::normalize_doc_metadata($metadata),
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    /**
     * Collapse analyzer occurrences into namespaced term frequencies and lengths.
     *
     * Occurrences may be structured rows or legacy strings. Terms that already
     * contain a namespace take precedence over the row language. Every stored key
     * is normalized to `lang . "\\x1e" . term`, and per-language document
     * lengths are the sum of rounded weighted term frequencies.
     *
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
     * Apply per-language document and token-count deltas to collection metadata.
     *
     * `$docDelta` is `1` for an added active document and `-1` for a replaced or
     * deleted active document. Length deltas are scaled by the same sign. Legacy
     * storage backends receive one aggregate update under the compatibility
     * adapter.
     *
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
     * Normalize a string-or-array option into a non-empty list.
     *
     * Used for WP-CLI and WordPress reindex options. Array values may themselves
     * contain comma-separated strings, matching how WP-CLI passes repeatable
     * options.
     *
     * @param string|array<int|string,mixed> $value Raw option value.
     * @return string[]
     * @throws InvalidArgumentException If no non-empty item remains.
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

    /**
     * Resolve the language for one WordPress post row during bulk reindexing.
     *
     * Explicit options win. Otherwise Polylang and WPML are consulted with the
     * row ID, then the configured default language is used.
     *
     * @param object $row `$wpdb->posts` row with an `ID` property.
     * @param array<string,mixed> $opts Reindex options.
     * @return string Canonical language for this post.
     */
    private function resolve_post_language(object $row, array $opts): string
    {
        $explicit = WP_FTS_TermNamespace::language_from_options($opts, null, ['lang', 'language', 'primary_lang', 'document_lang']);
        if ($explicit !== null) {
            return $explicit;
        }

        $postId = isset($row->ID) ? (int) $row->ID : 0;
        if ($postId > 0 && function_exists('pll_get_post_language')) {
            $lang = pll_get_post_language($postId, 'locale');
            if (is_scalar($lang) && trim((string) $lang) !== '') {
                return WP_FTS_TermNamespace::canonicalize_lang((string) $lang);
            }
        }

        if ($postId > 0 && function_exists('has_filter') && function_exists('apply_filters') && has_filter('wpml_post_language_details')) {
            $details = apply_filters('wpml_post_language_details', null, $postId);
            if (is_array($details)) {
                $lang = $details['locale'] ?? $details['language_code'] ?? null;
                if (is_scalar($lang) && trim((string) $lang) !== '') {
                    return WP_FTS_TermNamespace::canonicalize_lang((string) $lang);
                }
            }
            if (is_object($details)) {
                $lang = $details->locale ?? $details->language_code ?? null;
                if (is_scalar($lang) && trim((string) $lang) !== '') {
                    return WP_FTS_TermNamespace::canonicalize_lang((string) $lang);
                }
            }
        }

        return WP_FTS_TermNamespace::default_language($opts);
    }

    /**
     * Wrap a post title and content in minimal HTML for analyzer boosts.
     *
     * The title is escaped into a `<title>` element so it receives the analyzer's
     * title boost. Post content is inserted as existing WordPress HTML.
     */
    private function compose_post_html(string $title, string $content): string
    {
        $title = htmlspecialchars($title, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');

        return "<!doctype html><html><head><title>{$title}</title></head><body>{$content}</body></html>";
    }
}
