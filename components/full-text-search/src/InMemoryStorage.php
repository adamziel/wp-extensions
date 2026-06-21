<?php
declare(strict_types=1);

/**
 * Volatile storage backend used by tests and embedded in-process indexing.
 *
 * State lives in PHP arrays, supports rollback logs, and mirrors the
 * language-aware storage contract without any persistence layer.
 */
final class WP_FTS_Storage_InMemory implements WP_FTS_Storage, WP_FTS_DocumentMetadataStorage, WP_FTS_DocumentMetadataFilterStorage, WP_FTS_Row_Postings_Storage, WP_FTS_Capped_Postings_Storage, WP_FTS_Document_Terms_Storage, WP_FTS_Prefix_Term_Storage, WP_FTS_Resettable_Storage
{
    /** @var array<string,array{df:int,postings:string}> Encoded row cache for blob-shaped compatibility reads. */
    private array $terms = [];

    /** @var array<string,array<int,int>> Decoded term => doc_id => weighted tf postings. */
    private array $postingsByTerm = [];

    /** @var array<string,bool> True when a decoded posting list is already sorted by doc id. */
    private array $postingsSortedByTerm = [];

    /** @var array<int,array<string,bool>> Reverse map used for fast document replacement. */
    private array $termsByDoc = [];

    /** @var array<int,array{primary_lang:string,lang_lengths:array<string,int>,doc_len:int,content_hash:?string,deleted:bool}> */
    private array $docs = [];

    /** @var int[]|null Sorted active document ids, built lazily for large reads. */
    private ?array $activeDocIdsCache = null;

    /** @var array<string,array<int,int>> Cached all-active document lengths by language key. */
    private array $docLengthsByLangCache = [];

    /** @var array<int,array<string,mixed>> */
    private array $docMetadata = [];

    /** @var array<string,array{doc_count:int,len_sum:int}> */
    private array $meta = [];

    private bool $metaDirty = true;

    /** @var array<int,array<string,mixed>> Lazy rollback logs for active transactions. */
    private array $transactions = [];

    /**
     * Return existing term rows for the requested keys.
     *
     * @param string[] $terms Stored term keys.
     * @return array<string,array{df:int,postings:string}> Rows keyed by term.
     */
    public function get_terms(array $terms): array
    {
        $result = [];
        foreach (array_unique($terms) as $term) {
            $row = $this->encoded_term_row((string) $term);
            if ($row !== null) {
                $result[(string) $term] = $row;
            }
        }

        return $result;
    }

    /**
     * Store or remove one term row.
     *
     * A non-positive document frequency deletes the row to match empty postings.
     */
    public function put_term(string $term, int $df, string $postings): void
    {
        if ($df <= 0) {
            $this->delete_term($term);
            return;
        }

        $decoded = WP_FTS_PostingsCodec::decode($postings);
        $this->replace_term_postings($term, $decoded, $postings);
    }

    /**
     * Remove one term row if it exists.
     */
    public function delete_term(string $term): void
    {
        $this->remember_term($term);
        $this->remember_postings_by_term($term);
        foreach (array_keys($this->postingsByTerm[$term] ?? []) as $docId) {
            $this->remember_terms_by_doc((int) $docId);
            unset($this->termsByDoc[(int) $docId][$term]);
            if (($this->termsByDoc[(int) $docId] ?? []) === []) {
                unset($this->termsByDoc[(int) $docId]);
            }
        }
        unset($this->postingsByTerm[$term]);
        unset($this->terms[$term]);
        unset($this->postingsSortedByTerm[$term]);
    }

    /**
     * Replace all postings for one document without rewriting term blobs.
     *
     * @param array<string,int> $term_frequencies Stored term key => weighted tf.
     */
    public function replace_doc_postings(int $doc_id, array $term_frequencies): void
    {
        $doc_id = (int) $doc_id;
        $this->remember_terms_by_doc($doc_id);
        foreach (array_keys($this->termsByDoc[$doc_id] ?? []) as $term) {
            $this->remember_term($term);
            $this->remember_posting($term, $doc_id);
            $wasSorted = !empty($this->postingsSortedByTerm[$term]);
            unset($this->postingsByTerm[$term][$doc_id]);
            unset($this->terms[$term]);
            if (($this->postingsByTerm[$term] ?? []) === []) {
                unset($this->postingsByTerm[$term], $this->terms[$term]);
                unset($this->postingsSortedByTerm[$term]);
            } else {
                $this->postingsSortedByTerm[$term] = $wasSorted;
            }
        }
        unset($this->termsByDoc[$doc_id]);

        foreach ($term_frequencies as $term => $tf) {
            $tf = max(1, (int) $tf);
            $term = (string) $term;
            if ($term === '') {
                continue;
            }

            $this->remember_term($term);
            $this->remember_posting($term, $doc_id);
            $postings = $this->postingsByTerm[$term] ?? [];
            $wasSorted = $postings === [] || !empty($this->postingsSortedByTerm[$term]);
            $appendsInOrder = $postings === [] || $doc_id > (int) array_key_last($postings);
            $this->postingsByTerm[$term][$doc_id] = $tf;
            $this->postingsSortedByTerm[$term] = $wasSorted && $appendsInOrder;
            $this->termsByDoc[$doc_id][$term] = true;
            unset($this->terms[$term]);
        }
    }

    /**
     * Fetch decoded postings directly for scoring.
     *
     * @param string[] $terms Stored term keys.
     * @return array<string,array<int,int>>
     */
    public function get_postings(array $terms): array
    {
        $result = [];
        foreach (array_unique(array_map('strval', $terms)) as $term) {
            $postings = $this->sorted_postings_for_term($term);
            if ($postings !== []) {
                $result[$term] = $postings;
            }
        }
        ksort($result, SORT_STRING);

        return $result;
    }

    /**
     * Fetch a deterministic prefix of each requested posting list for opt-in
     * approximate fast top-K search.
     *
     * @param string[] $terms Stored term keys.
     * @return array<string,array<int,int>>
     */
    public function get_capped_postings(array $terms, int $candidate_cap): array
    {
        $candidate_cap = max(1, (int) $candidate_cap);
        $result = [];
        foreach (array_unique(array_map('strval', $terms)) as $term) {
            $postings = $this->capped_postings_for_term($term, $candidate_cap);
            if ($postings === []) {
                continue;
            }

            $result[$term] = $postings;
        }
        ksort($result, SORT_STRING);

        return $result;
    }

    /**
     * Return a deterministic doc-id prefix without sorting the full list when a
     * row-posting workload has appended documents in order.
     *
     * @return array<int,int>
     */
    private function capped_postings_for_term(string $term, int $candidateCap): array
    {
        if (!isset($this->postingsByTerm[$term]) || $this->postingsByTerm[$term] === []) {
            return [];
        }

        $postings = $this->postingsByTerm[$term];
        if (count($postings) <= $candidateCap) {
            return $this->sorted_postings_for_term($term);
        }

        if (!empty($this->postingsSortedByTerm[$term])) {
            return array_slice($postings, 0, $candidateCap, true);
        }

        return $this->bounded_sorted_posting_prefix($postings, $candidateCap);
    }

    /**
     * Select the lowest N document ids from an unsorted posting map without a
     * full-array sort. This path is for out-of-order replacements; normal bulk
     * indexing keeps posting maps sorted incrementally.
     *
     * @param array<int,int> $postings
     * @return array<int,int>
     */
    private function bounded_sorted_posting_prefix(array $postings, int $candidateCap): array
    {
        $queue = new SplPriorityQueue();
        $queue->setExtractFlags(SplPriorityQueue::EXTR_BOTH);

        foreach ($postings as $docId => $tf) {
            $docId = (int) $docId;
            if ($queue->count() < $candidateCap) {
                $queue->insert(['doc_id' => $docId, 'tf' => max(1, (int) $tf)], $docId);
                continue;
            }

            $largest = $queue->top();
            if ($docId >= (int) $largest['priority']) {
                continue;
            }

            $queue->extract();
            $queue->insert(['doc_id' => $docId, 'tf' => max(1, (int) $tf)], $docId);
        }

        $prefix = [];
        while (!$queue->isEmpty()) {
            $row = $queue->extract();
            $data = is_array($row['data']) ? $row['data'] : [];
            $docId = (int) ($data['doc_id'] ?? 0);
            $prefix[$docId] = max(1, (int) ($data['tf'] ?? 1));
        }
        ksort($prefix, SORT_NUMERIC);

        return $prefix;
    }

    /**
     * Return one decoded posting list sorted by document id.
     *
     * @return array<int,int>
     */
    private function sorted_postings_for_term(string $term): array
    {
        if (!isset($this->postingsByTerm[$term]) || $this->postingsByTerm[$term] === []) {
            return [];
        }

        if (empty($this->postingsSortedByTerm[$term])) {
            ksort($this->postingsByTerm[$term], SORT_NUMERIC);
            $this->postingsSortedByTerm[$term] = true;
        }

        return $this->postingsByTerm[$term];
    }

    /**
     * Return terms currently posted by one document.
     *
     * @return string[]
     */
    public function terms_for_doc(int $doc_id): array
    {
        $terms = array_keys($this->termsByDoc[(int) $doc_id] ?? []);
        sort($terms, SORT_STRING);

        return $terms;
    }

    /**
     * Return active document lengths, optionally for one language partition.
     *
     * Deleted documents and missing language partitions are omitted.
     *
     * @param int[] $doc_ids Document ids to inspect.
     * @return array<int,int> Positive lengths keyed by document id.
     */
    public function get_doc_lengths(array $doc_ids, ?string $lang = null): array
    {
        $lang = $lang === null ? null : $this->normalize_lang($lang);
        $cachedLengths = $this->cached_all_doc_lengths($doc_ids, $lang);
        if ($cachedLengths !== null) {
            return $cachedLengths;
        }

        $lengths = [];
        foreach (array_unique(array_map('intval', $doc_ids)) as $docId) {
            if (isset($this->docs[$docId]) && !$this->docs[$docId]['deleted']) {
                $length = $lang === null
                    ? $this->docs[$docId]['doc_len']
                    : ($this->docs[$docId]['lang_lengths'][$lang] ?? null);
                if ($length !== null) {
                    $lengths[$docId] = $length;
                }
            }
        }
        ksort($lengths, SORT_NUMERIC);

        return $lengths;
    }

    /**
     * Fetch one document metadata row or tombstone.
     *
     * @return array{primary_lang:string,lang_lengths:array<string,int>,doc_len:int,content_hash:?string,deleted:bool}|null
     */
    public function get_doc(int $doc_id): ?array
    {
        return $this->docs[$doc_id] ?? null;
    }

    /**
     * Store document metadata in either language-aware or legacy shape.
     *
     * New calls pass primary language, per-language lengths, and content hash.
     * Legacy calls pass aggregate doc length and hash.
     */
    public function put_doc(int $doc_id, string|int $primary_lang, array|string $lang_lengths, ?string $hash = null): void
    {
        $this->remember_doc($doc_id);
        [$primaryLang, $normalizedLengths, $contentHash] = $this->normalize_put_doc_args(
            $primary_lang,
            $lang_lengths,
            $hash
        );

        $this->docs[$doc_id] = [
            'primary_lang' => $primaryLang,
            'lang_lengths' => $normalizedLengths,
            'doc_len' => array_sum($normalizedLengths),
            'content_hash' => $contentHash,
            'deleted' => false,
        ];
        $this->clear_document_read_cache();
        $this->metaDirty = true;
    }

    /**
     * Mark a document deleted, creating a tombstone for unknown ids.
     */
    public function delete_doc(int $doc_id): void
    {
        $this->remember_doc($doc_id);
        if (!isset($this->docs[$doc_id])) {
            $this->docs[$doc_id] = [
                'primary_lang' => '',
                'lang_lengths' => [],
                'doc_len' => 0,
                'content_hash' => null,
                'deleted' => true,
            ];
            $this->clear_document_read_cache();
            $this->metaDirty = true;
            return;
        }

        $this->docs[$doc_id]['deleted'] = true;
        $this->clear_document_read_cache();
        $this->metaDirty = true;
    }

    /**
     * Store product-facing document metadata for filters and snippets.
     *
     * @param array<string,mixed> $metadata
     */
    public function put_doc_metadata(int $doc_id, array $metadata): void
    {
        $this->remember_doc_metadata($doc_id);
        $this->docMetadata[$doc_id] = WP_FTS_StorageCompat::normalize_doc_metadata($metadata);
    }

    /**
     * Return metadata only for active documents.
     *
     * @param int[] $doc_ids
     * @return array<int,array<string,mixed>>
     */
    public function get_doc_metadata(array $doc_ids): array
    {
        $metadata = [];
        foreach (array_unique(array_map('intval', $doc_ids)) as $docId) {
            if (isset($this->docs[$docId], $this->docMetadata[$docId]) && !$this->docs[$docId]['deleted']) {
                $metadata[$docId] = $this->docMetadata[$docId];
            }
        }
        ksort($metadata, SORT_NUMERIC);

        return $metadata;
    }

    /**
     * Return active candidate ids whose scalar metadata matches the filters.
     *
     * @param int[] $doc_ids
     * @param string[] $post_types
     * @param string[] $post_statuses
     * @return int[]
     */
    public function filter_doc_ids_by_metadata(
        array $doc_ids,
        array $post_types = [],
        array $post_statuses = [],
        ?string $date_after = null,
        ?string $date_before = null
    ): array {
        $matches = [];
        foreach (array_unique(array_map('intval', $doc_ids)) as $docId) {
            if (!isset($this->docs[$docId], $this->docMetadata[$docId]) || $this->docs[$docId]['deleted']) {
                continue;
            }

            if (WP_FTS_StorageCompat::metadata_matches_filters(
                $this->docMetadata[$docId],
                $post_types,
                $post_statuses,
                $date_after,
                $date_before
            )) {
                $matches[] = $docId;
            }
        }
        sort($matches, SORT_NUMERIC);

        return $matches;
    }

    /**
     * Return derived collection metadata for active documents.
     *
     * Metadata is rebuilt from document rows before every read so test fixtures
     * remain consistent even if callers used direct document writes.
     *
     * @return array{doc_count:int,len_sum:int}
     */
    public function get_meta(?string $lang = null): array
    {
        if ($this->metaDirty) {
            $this->sync_meta_from_docs();
        }
        if ($lang === null) {
            return $this->aggregate_meta();
        }

        $lang = $this->normalize_lang($lang);

        return $this->meta[$lang] ?? ['doc_count' => 0, 'len_sum' => 0];
    }

    /**
     * Add signed deltas to stored metadata.
     *
     * Supports both `($lang, $d_docs, $d_len)` and legacy `($d_docs, $d_len)`.
     */
    public function add_meta(string|int $lang, int $d_docs, ?int $d_len = null): void
    {
        [$normalizedLang, $docDelta, $lenDelta] = $this->normalize_meta_args($lang, $d_docs, $d_len);
        $this->remember_meta($normalizedLang);
        $current = $this->meta[$normalizedLang] ?? ['doc_count' => 0, 'len_sum' => 0];
        $this->meta[$normalizedLang] = [
            'doc_count' => max(0, $current['doc_count'] + $docDelta),
            'len_sum' => max(0, $current['len_sum'] + $lenDelta),
        ];
    }

    /**
     * List all stored term keys in sorted order.
     *
     * @return string[]
     */
    public function all_terms(): array
    {
        $terms = array_keys($this->postingsByTerm);
        sort($terms, SORT_STRING);

        return $terms;
    }

    /**
     * Return stored term keys that start with a namespaced prefix.
     *
     * @return string[]
     */
    public function terms_with_prefix(string $prefix, int $limit): array
    {
        $limit = max(1, (int) $limit);
        if ($prefix === '') {
            return [];
        }

        $terms = [];
        foreach ($this->all_terms() as $term) {
            if (str_starts_with($term, $prefix)) {
                $terms[] = $term;
                if (count($terms) >= $limit) {
                    break;
                }
            }
        }

        return $terms;
    }

    /**
     * List document ids, optionally including tombstones.
     *
     * @return int[]
     */
    public function all_doc_ids(bool $include_deleted = false): array
    {
        $ids = [];
        foreach ($this->docs as $docId => $doc) {
            if ($include_deleted || !$doc['deleted']) {
                $ids[] = (int) $docId;
            }
        }
        sort($ids, SORT_NUMERIC);

        return $ids;
    }

    /**
     * Start an in-memory rollback scope.
     */
    public function begin_transaction(): void
    {
        $this->transactions[] = [
            'terms' => [],
            'postingsByTerm' => [],
            'postingsByTermDoc' => [],
            'termsByDoc' => [],
            'docs' => [],
            'docMetadata' => [],
            'meta' => [],
        ];
    }

    /**
     * Commit the current rollback scope by discarding its change log.
     */
    public function commit(): void
    {
        array_pop($this->transactions);
    }

    /**
     * Restore the latest rollback change log when one exists.
     */
    public function rollback(): void
    {
        $transaction = array_pop($this->transactions);
        if ($transaction === null) {
            return;
        }

        foreach (array_reverse($transaction['terms'], true) as $term => $entry) {
            $this->restore_entry($this->terms, $term, $entry);
        }
        foreach (array_reverse($transaction['postingsByTerm'], true) as $term => $entry) {
            $this->restore_entry($this->postingsByTerm, $term, $entry);
            unset($this->postingsSortedByTerm[(string) $term]);
        }
        foreach (array_reverse($transaction['postingsByTermDoc'], true) as $term => $docEntries) {
            foreach (array_reverse($docEntries, true) as $docId => $entry) {
                if (!empty($entry['exists'])) {
                    $this->postingsByTerm[(string) $term][(int) $docId] = (int) $entry['value'];
                    $this->postingsSortedByTerm[(string) $term] = false;
                    continue;
                }

                unset($this->postingsByTerm[(string) $term][(int) $docId]);
                $this->postingsSortedByTerm[(string) $term] = false;
                if (($this->postingsByTerm[(string) $term] ?? []) === []) {
                    unset($this->postingsByTerm[(string) $term]);
                    unset($this->postingsSortedByTerm[(string) $term]);
                }
            }
        }
        foreach (array_reverse($transaction['termsByDoc'], true) as $docId => $entry) {
            $this->restore_entry($this->termsByDoc, (int) $docId, $entry);
        }
        foreach (array_reverse($transaction['docs'], true) as $docId => $entry) {
            $this->restore_entry($this->docs, (int) $docId, $entry);
        }
        $this->clear_document_read_cache();
        foreach (array_reverse($transaction['docMetadata'], true) as $docId => $entry) {
            $this->restore_entry($this->docMetadata, (int) $docId, $entry);
        }
        foreach (array_reverse($transaction['meta'], true) as $lang => $entry) {
            $this->restore_entry($this->meta, $lang, $entry);
        }
        $this->metaDirty = true;
    }

    /**
     * No-op for volatile storage.
     */
    public function flush(): void
    {
    }

    /**
     * Clear all derived index rows while preserving the storage object itself.
     *
     * @return array<string,int>
     */
    public function reset_index(): array
    {
        $this->remember_full_state();
        $counts = [
            'postings_deleted' => array_sum(array_map('count', $this->postingsByTerm)),
            'terms_deleted' => count($this->postingsByTerm),
            'docs_deleted' => count($this->docs),
            'doc_lengths_deleted' => array_sum(array_map(
                static fn(array $doc): int => count($doc['lang_lengths'] ?? []),
                $this->docs
            )),
            'doc_metadata_deleted' => count($this->docMetadata),
            'collection_metadata_deleted' => count($this->meta),
        ];

        $this->terms = [];
        $this->postingsByTerm = [];
        $this->postingsSortedByTerm = [];
        $this->termsByDoc = [];
        $this->docs = [];
        $this->docMetadata = [];
        $this->meta = [];
        $this->metaDirty = false;
        $this->clear_document_read_cache();

        return $counts;
    }

    /**
     * Remove tombstoned documents from postings and metadata.
     *
     * This compacts delayed deletes by dropping deleted doc ids from every
     * posting list, removing empty term rows, then rebuilding metadata.
     */
    public function optimize(): void
    {
        $this->remember_full_state();
        $deleted = [];
        foreach ($this->docs as $docId => $doc) {
            if ($doc['deleted']) {
                $deleted[(int) $docId] = true;
            }
        }

        if ($deleted !== []) {
            foreach ($this->postingsByTerm as $term => $postings) {
                $wasSorted = !empty($this->postingsSortedByTerm[$term]);
                foreach ($deleted as $docId => $_) {
                    unset($postings[$docId]);
                    unset($this->termsByDoc[$docId][$term]);
                }

                if ($postings === []) {
                    unset($this->postingsByTerm[$term], $this->terms[$term]);
                    unset($this->postingsSortedByTerm[$term]);
                    continue;
                }

                $this->postingsByTerm[$term] = $postings;
                $this->postingsSortedByTerm[$term] = $wasSorted;
                unset($this->terms[$term]);
            }
        }

        foreach ($deleted as $docId => $_) {
            unset($this->docs[$docId]);
            unset($this->docMetadata[$docId]);
            unset($this->termsByDoc[$docId]);
        }

        $this->sync_meta_from_docs();
        ksort($this->docs, SORT_NUMERIC);
        $this->clear_document_read_cache();
    }

    /**
     * Return cached lengths when the caller requests every active document in
     * sorted order; otherwise fall back to the general partial-read path.
     *
     * @param int[] $docIds
     * @return array<int,int>|null
     */
    private function cached_all_doc_lengths(array $docIds, ?string $lang): ?array
    {
        if (count($docIds) < 1024 || $this->memory_limit_bytes() < 256 * 1024 * 1024) {
            return null;
        }

        $activeDocIds = $this->active_doc_ids_cache();
        if (count($docIds) !== count($activeDocIds)) {
            return null;
        }

        foreach ($activeDocIds as $index => $docId) {
            if (!array_key_exists($index, $docIds) || (int) $docIds[$index] !== $docId) {
                return null;
            }
        }

        $cacheKey = $lang ?? "\0";
        if (!array_key_exists($cacheKey, $this->docLengthsByLangCache)) {
            $lengths = [];
            foreach ($activeDocIds as $docId) {
                $doc = $this->docs[$docId] ?? null;
                if ($doc === null || $doc['deleted']) {
                    continue;
                }

                if ($lang === null) {
                    $lengths[$docId] = $doc['doc_len'];
                    continue;
                }

                if (isset($doc['lang_lengths'][$lang])) {
                    $lengths[$docId] = $doc['lang_lengths'][$lang];
                }
            }
            $this->docLengthsByLangCache[$cacheKey] = $lengths;
        }

        return $this->docLengthsByLangCache[$cacheKey];
    }

    /**
     * @return int[]
     */
    private function active_doc_ids_cache(): array
    {
        if ($this->activeDocIdsCache !== null) {
            return $this->activeDocIdsCache;
        }

        $ids = [];
        foreach ($this->docs as $docId => $doc) {
            if (!$doc['deleted']) {
                $ids[] = (int) $docId;
            }
        }
        sort($ids, SORT_NUMERIC);
        $this->activeDocIdsCache = $ids;

        return $this->activeDocIdsCache;
    }

    private function clear_document_read_cache(): void
    {
        $this->activeDocIdsCache = null;
        $this->docLengthsByLangCache = [];
    }

    private function memory_limit_bytes(): int
    {
        $limit = trim((string) ini_get('memory_limit'));
        if ($limit === '' || $limit === '-1') {
            return PHP_INT_MAX;
        }

        $unit = strtolower(substr($limit, -1));
        $number = (float) $limit;
        return match ($unit) {
            'g' => (int) ($number * 1024 * 1024 * 1024),
            'm' => (int) ($number * 1024 * 1024),
            'k' => (int) ($number * 1024),
            default => (int) $number,
        };
    }

    private function remember_term(string $term): void
    {
        $this->remember_entry('terms', $term, $this->terms);
    }

    private function remember_postings_by_term(string $term): void
    {
        $this->remember_entry('postingsByTerm', $term, $this->postingsByTerm);
    }

    private function remember_posting(string $term, int $docId): void
    {
        if ($this->transactions === []) {
            return;
        }

        foreach (array_keys($this->transactions) as $index) {
            if (isset($this->transactions[$index]['postingsByTermDoc'][$term])
                && array_key_exists($docId, $this->transactions[$index]['postingsByTermDoc'][$term])) {
                continue;
            }

            $exists = isset($this->postingsByTerm[$term]) && array_key_exists($docId, $this->postingsByTerm[$term]);
            $this->transactions[$index]['postingsByTermDoc'][$term][$docId] = [
                'exists' => $exists,
                'value' => $exists ? $this->postingsByTerm[$term][$docId] : null,
            ];
        }
    }

    private function remember_terms_by_doc(int $docId): void
    {
        $this->remember_entry('termsByDoc', $docId, $this->termsByDoc);
    }

    private function remember_doc(int $docId): void
    {
        $this->remember_entry('docs', $docId, $this->docs);
    }

    private function remember_doc_metadata(int $docId): void
    {
        $this->remember_entry('docMetadata', $docId, $this->docMetadata);
    }

    private function remember_meta(string $lang): void
    {
        $this->remember_entry('meta', $lang, $this->meta);
    }

    /**
     * Record current values for a bucket key in every active transaction.
     *
     * @param array<string|int,mixed> $source
     */
    private function remember_entry(string $bucket, string|int $key, array $source): void
    {
        if ($this->transactions === []) {
            return;
        }

        foreach (array_keys($this->transactions) as $index) {
            if (array_key_exists($key, $this->transactions[$index][$bucket])) {
                continue;
            }

            $exists = array_key_exists($key, $source);
            $this->transactions[$index][$bucket][$key] = [
                'exists' => $exists,
                'value' => $exists ? $source[$key] : null,
            ];
        }
    }

    /**
     * Record all storage arrays for broad operations such as optimize().
     */
    private function remember_full_state(): void
    {
        if ($this->transactions === []) {
            return;
        }

        foreach (array_keys($this->terms) as $term) {
            $this->remember_term((string) $term);
        }
        foreach (array_keys($this->postingsByTerm) as $term) {
            $this->remember_postings_by_term((string) $term);
        }
        foreach (array_keys($this->termsByDoc) as $docId) {
            $this->remember_terms_by_doc((int) $docId);
        }
        foreach (array_keys($this->docs) as $docId) {
            $this->remember_doc((int) $docId);
        }
        foreach (array_keys($this->docMetadata) as $docId) {
            $this->remember_doc_metadata((int) $docId);
        }
        foreach (array_keys($this->meta) as $lang) {
            $this->remember_meta((string) $lang);
        }
    }

    /**
     * @param array<string|int,mixed> $target
     * @param array{exists:bool,value:mixed} $entry
     */
    private function restore_entry(array &$target, string|int $key, array $entry): void
    {
        if (!empty($entry['exists'])) {
            $target[$key] = $entry['value'];
            return;
        }

        unset($target[$key]);
    }

    /**
     * Return an encoded term row, materializing the compatibility cache lazily.
     *
     * @return array{df:int,postings:string}|null
     */
    private function encoded_term_row(string $term): ?array
    {
        if (!isset($this->postingsByTerm[$term]) || $this->postingsByTerm[$term] === []) {
            unset($this->terms[$term]);
            return null;
        }

        if (!isset($this->terms[$term])) {
            $postings = $this->postingsByTerm[$term];
            if (empty($this->postingsSortedByTerm[$term])) {
                ksort($postings, SORT_NUMERIC);
                $this->postingsByTerm[$term] = $postings;
                $this->postingsSortedByTerm[$term] = true;
            }
            $this->terms[$term] = [
                'df' => count($postings),
                'postings' => WP_FTS_PostingsCodec::encode($postings),
            ];
        }

        return $this->terms[$term];
    }

    /**
     * Replace all postings for one term and keep reverse document mappings in sync.
     *
     * @param array<int,int> $postings doc_id => weighted tf.
     */
    private function replace_term_postings(string $term, array $postings, ?string $encodedPostings = null): void
    {
        $this->remember_term($term);
        $this->remember_postings_by_term($term);
        foreach (array_keys($this->postingsByTerm[$term] ?? []) as $docId) {
            $this->remember_terms_by_doc((int) $docId);
            unset($this->termsByDoc[(int) $docId][$term]);
            if (($this->termsByDoc[(int) $docId] ?? []) === []) {
                unset($this->termsByDoc[(int) $docId]);
            }
        }

        $normalized = [];
        foreach ($postings as $docId => $tf) {
            $docId = (int) $docId;
            $tf = max(1, (int) $tf);
            if ($docId < 0) {
                continue;
            }

            $normalized[$docId] = $tf;
            $this->remember_terms_by_doc($docId);
            $this->termsByDoc[$docId][$term] = true;
        }

        if ($normalized === []) {
            unset($this->postingsByTerm[$term], $this->terms[$term]);
            unset($this->postingsSortedByTerm[$term]);
            return;
        }

        ksort($normalized, SORT_NUMERIC);
        $this->postingsByTerm[$term] = $normalized;
        $this->postingsSortedByTerm[$term] = true;
        if ($encodedPostings !== null) {
            $this->terms[$term] = [
                'df' => count($normalized),
                'postings' => $encodedPostings,
            ];
        } else {
            unset($this->terms[$term]);
        }
    }

    /**
     * Normalize `put_doc()` overloads into one internal payload.
     *
     * @param string|int $primary_lang Primary language or legacy document length.
     * @param array<string,int>|string $lang_lengths Per-language lengths or
     *        legacy content hash.
     * @param string|null $hash New-shape content hash.
     * @return array{string,array<string,int>,string}
     * @throws InvalidArgumentException For unsupported argument combinations.
     */
    private function normalize_put_doc_args(string|int $primary_lang, array|string $lang_lengths, ?string $hash): array
    {
        if (is_int($primary_lang) && is_string($lang_lengths) && $hash === null) {
            return [
                '',
                $this->normalize_lang_lengths(['' => $primary_lang]),
                $lang_lengths,
            ];
        }

        if (!is_string($primary_lang) || !is_array($lang_lengths) || $hash === null) {
            throw new InvalidArgumentException('put_doc expects ($doc_id, $primary_lang, $lang_lengths, $hash).');
        }

        return [
            $this->normalize_lang($primary_lang),
            $this->normalize_lang_lengths($lang_lengths),
            $hash,
        ];
    }

    /**
     * Drop non-positive lengths, normalize language keys, and sort them.
     *
     * @param array<string,int> $lang_lengths
     * @return array<string,int>
     */
    private function normalize_lang_lengths(array $lang_lengths): array
    {
        $normalized = [];
        foreach ($lang_lengths as $lang => $length) {
            $length = max(0, (int) $length);
            if ($length <= 0) {
                continue;
            }
            $normalized[$this->normalize_lang((string) $lang)] = $length;
        }
        ksort($normalized, SORT_STRING);

        return $normalized;
    }

    /**
     * Normalize a storage-local language key.
     *
     * In-memory storage preserves the legacy empty-string aggregate partition and
     * otherwise trims only; canonicalization happens in higher-level adapters.
     */
    private function normalize_lang(string $lang): string
    {
        return trim($lang);
    }

    /**
     * Normalize `add_meta()` overloads into language, doc delta, and length delta.
     *
     * @return array{string,int,int}
     * @throws InvalidArgumentException For unsupported argument combinations.
     */
    private function normalize_meta_args(string|int $lang, int $d_docs, ?int $d_len): array
    {
        if (is_int($lang) && $d_len === null) {
            return ['', $lang, $d_docs];
        }

        if (!is_string($lang) || $d_len === null) {
            throw new InvalidArgumentException('add_meta expects ($lang, $d_docs, $d_len).');
        }

        return [$this->normalize_lang($lang), $d_docs, $d_len];
    }

    /**
     * Rebuild per-language metadata from active document rows.
     *
     * This makes document rows the source of truth and prevents stale deltas
     * after rollback or manual fixture setup.
     */
    private function sync_meta_from_docs(): void
    {
        $meta = [];
        foreach ($this->docs as $doc) {
            if ($doc['deleted']) {
                continue;
            }
            foreach ($doc['lang_lengths'] as $lang => $length) {
                if ($length <= 0) {
                    continue;
                }
                $meta[$lang] ??= ['doc_count' => 0, 'len_sum' => 0];
                $meta[$lang]['doc_count']++;
                $meta[$lang]['len_sum'] += $length;
            }
        }
        ksort($meta, SORT_STRING);

        $this->meta = $meta;
        $this->metaDirty = false;
    }

    /**
     * Compute aggregate metadata across active documents.
     *
     * @return array{doc_count:int,len_sum:int}
     */
    private function aggregate_meta(): array
    {
        $docCount = 0;
        $lenSum = 0;
        foreach ($this->docs as $doc) {
            if ($doc['deleted']) {
                continue;
            }
            $docCount++;
            $lenSum += $doc['doc_len'];
        }

        return ['doc_count' => $docCount, 'len_sum' => $lenSum];
    }
}
