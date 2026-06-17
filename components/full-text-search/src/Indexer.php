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
    private const INDEX_SIGNATURE_VERSION = 'wp-fts-indexer-v2';

    /**
     * @param WP_FTS_Storage $storage Storage backend for terms, documents, and
     *        metadata. Backends may be language-aware or legacy aggregate-only.
     * @param object $analyzer Analyzer object exposing `analyze_content()`.
     * @param object|null $postContentExtractor Optional adapter exposing
     *        `extract(object $post, array $opts): array` for legacy
     *        `index_post()` callers. New framework-neutral callers should use
     *        `index_document()` or `index_document_fields()`.
     */
    public function __construct(
        private WP_FTS_Storage $storage,
        private object $analyzer,
        private ?object $postContentExtractor = null,
    ) {
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
     * The content hash includes the resolved primary language and analyzer
     * behavior signature, so the same HTML can be reindexed when its language
     * partition or analyzer output changes. Replacements remove old postings
     * and subtract old per-language metadata before adding the new postings and
     * document lengths in one storage transaction.
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
        if ($metadata !== null && !array_key_exists('search_html', $metadata)) {
            $searchHtml = $this->fields_search_html($fields, (int) ($opts['metadata_html_limit'] ?? $opts['metadata_text_limit'] ?? 20000));
            if ($searchHtml !== '') {
                $metadata['search_html'] = $searchHtml;
            }
        }
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
        $metadataToStore = $metadata;
        if ($metadataToStore === null && $existing !== null && !$existing['deleted']) {
            $metadataToStore = WP_FTS_StorageCompat::get_doc_metadata($this->storage, [$doc_id]) !== []
                ? []
                : null;
        }

        $this->storage->begin_transaction();
        try {
            $postingsReplaced = WP_FTS_StorageCompat::replace_doc_postings($this->storage, $doc_id, $termFrequencies);
            if (!$postingsReplaced && $existing !== null) {
                $this->remove_doc_from_all_terms($doc_id);
            }

            if ($existing !== null) {
                if (!$existing['deleted']) {
                    $this->add_meta_deltas(
                        WP_FTS_StorageCompat::doc_lang_lengths($existing, $primaryLang),
                        -1
                    );
                }
            }

            if (!$postingsReplaced && $termFrequencies !== []) {
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
            if ($metadataToStore !== null) {
                WP_FTS_StorageCompat::put_doc_metadata($this->storage, $doc_id, $metadataToStore);
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
        $hadMetadata = WP_FTS_StorageCompat::get_doc_metadata($this->storage, [$doc_id]) !== [];

        $this->storage->begin_transaction();
        try {
            $this->storage->delete_doc($doc_id);
            if ($hadMetadata) {
                WP_FTS_StorageCompat::put_doc_metadata($this->storage, $doc_id, []);
            }
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
     * Index one post-like object through an adapter extractor.
     *
     * This method remains for compatibility with the original plugin API. The
     * reusable library itself does not extract WordPress posts; callers should
     * prefer `index_document_fields()` after their application has converted a
     * domain object into weighted fields and metadata.
     *
     * @param object $post Object with `ID` and WordPress post-like properties.
     * @param array<string,mixed> $opts Optional language, extraction, field
     *        boost, and metadata overrides.
     * @throws LogicException If no compatible extractor is available.
     */
    public function index_post(object $post, array $opts = []): bool
    {
        $postId = isset($post->ID) ? (int) $post->ID : 0;
        if ($postId <= 0) {
            throw new InvalidArgumentException('Post object must provide a positive ID.');
        }

        $indexOptions = $opts;
        $indexOptions['post_id'] = $postId;

        $extractor = $this->postContentExtractor ?? $this->default_post_content_extractor();
        if (!method_exists($extractor, 'extract')) {
            throw new LogicException('Post content extractor must expose extract(object $post, array $opts).');
        }

        $extracted = $extractor->extract($post, $indexOptions);
        $metadata = $extracted['metadata'];
        if (isset($opts['metadata']) && is_array($opts['metadata'])) {
            $metadata = array_replace($metadata, $opts['metadata']);
        }
        $indexOptions['metadata'] = $metadata;
        $indexOptions['field_boosts'] = $extracted['field_boosts'];

        return $this->index_document_fields($postId, $extracted['fields'], $indexOptions);
    }

    /**
     * Resolve the plugin-provided extractor for legacy `index_post()` callers.
     */
    private function default_post_content_extractor(): object
    {
        if (!class_exists('WP_FTS_PostContentExtractor')) {
            throw new LogicException('index_post() requires a post content extractor adapter. Pass one to WP_FTS_Indexer or use index_document_fields().');
        }

        return new WP_FTS_PostContentExtractor();
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
            null,
            ['lang', 'language', 'primary_lang', 'document_lang', 'locale']
        ) ?? $default;
    }

    /**
     * Hash document content together with its primary language and analyzer.
     *
     * The NUL separator keeps portions unambiguous while preserving the
     * existing SHA-1 storage shape. The analyzer signature makes migrations from
     * old no-stem or language-pipeline behavior rewrite unchanged documents.
     */
    private function content_hash(string $html, string $primaryLang): string
    {
        return sha1(implode("\0", [
            self::INDEX_SIGNATURE_VERSION,
            $this->analyzer_index_signature(),
            WP_FTS_TermNamespace::canonicalize_lang($primaryLang),
            $html,
        ]));
    }

    /**
     * Return the analyzer's stale-detection signature when available.
     */
    private function analyzer_index_signature(): string
    {
        if (is_callable([$this->analyzer, 'index_signature'])) {
            try {
                $signature = $this->analyzer->index_signature();
                if (is_scalar($signature) && trim((string) $signature) !== '') {
                    return (string) $signature;
                }
            } catch (Throwable) {
                // Fall through to a conservative class-level signature.
            }
        }

        return 'analyzer:' . get_debug_type($this->analyzer);
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
        if (WP_FTS_TermNamespace::language_from_options($opts, null, ['lang', 'language', 'primary_lang', 'document_lang']) !== null) {
            $analysisOpts['document_lang'] = $primaryLang;
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
     * Build a bounded HTML source for snippets from weighted fields.
     *
     * @param array<int,array{name:string,text:string,html?:string,boost:float}> $fields
     */
    private function fields_search_html(array $fields, int $limit): string
    {
        $fragments = [];
        foreach ($fields as $field) {
            if (isset($field['html']) && trim((string) $field['html']) !== '') {
                foreach ($this->compact_html_metadata_fragments((string) $field['html']) as $fragment) {
                    $fragments[$fragment] = true;
                }
            }
        }

        if ($fragments === []) {
            return '';
        }

        return rtrim(WP_FTS_Utf8::truncate_bytes(implode(' ', array_keys($fragments)), max(1, $limit)));
    }

    /**
     * Keep only HTML fragments needed to preserve rich snippet highlights.
     *
     * Plain snippet text already lives in `search_text`; this sidecar exists for
     * inline markup/entity cases where plain text cannot reconstruct a safe mark
     * range around the original edited HTML.
     *
     * @return string[]
     */
    private function compact_html_metadata_fragments(string $html): array
    {
        $ranges = [];
        foreach (WP_FTS_Html_Text_Stream::visible_words($html) as $word) {
            $sourceStart = (int) $word['source_start'];
            $sourceEnd = (int) $word['source_end'];
            $source = substr($html, $sourceStart, max(0, $sourceEnd - $sourceStart));
            if (!str_contains($source, '<') && !str_contains($source, '&')) {
                continue;
            }

            $range = WP_FTS_Html_Text_Stream::expand_inline_range($html, $sourceStart, $sourceEnd);
            $ranges[] = $range;
        }

        $fragments = [];
        foreach ($this->merge_html_source_ranges($ranges) as $range) {
            $fragment = trim(substr($html, $range['start'], max(0, $range['end'] - $range['start'])));
            if ($fragment !== '') {
                $fragments[] = $fragment;
            }
        }

        return $fragments;
    }

    /**
     * @param array<int,array{start:int,end:int}> $ranges
     * @return array<int,array{start:int,end:int}>
     */
    private function merge_html_source_ranges(array $ranges): array
    {
        usort($ranges, static fn(array $a, array $b): int => $a['start'] <=> $b['start']);

        $merged = [];
        foreach ($ranges as $range) {
            $start = max(0, (int) $range['start']);
            $end = max($start, (int) $range['end']);
            if ($end <= $start) {
                continue;
            }

            $last = count($merged) - 1;
            if ($last >= 0 && $start <= $merged[$last]['end']) {
                $merged[$last]['end'] = max($merged[$last]['end'], $end);
                continue;
            }

            $merged[] = ['start' => $start, 'end' => $end];
        }

        return $merged;
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
            if (
                is_array($occurrence)
                && ($occurrence['source'] ?? '') === 'lemma-pack'
                && isset($occurrence['rank'])
                && is_numeric($occurrence['rank'])
                && (int) $occurrence['rank'] === 0
            ) {
                $weight *= 2.0;
            }
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

}
