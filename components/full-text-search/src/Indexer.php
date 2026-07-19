<?php
declare(strict_types=1);

/**
 * Analyzes documents and coordinates storage-backed FTS mutations.
 *
 * The indexer accepts HTML documents, delegates text analysis to the analyzer,
 * and stores terms under language namespaces. Storage backends own their score
 * representation and lifecycle: legacy fixtures retain document-length BM25
 * state, while relational production storage persists quantized impacts.
 */
final class WP_FTS_Indexer
{
    private const INDEX_SIGNATURE_VERSION = 'wp-fts-indexer-v6';
    private const FIELD_METADATA_MAX_FIELDS = 16;
    private const FIELD_METADATA_TEXT_BYTES = 2000;
    private const FIELD_METADATA_HTML_BYTES = 2000;
    private const MAX_INDEX_FIELDS = 32;
    private const MAX_FIELD_BOOSTS = 32;
    private const MAX_FIELD_NAME_BYTES = 191;
    private const MAX_OPTION_SCALAR_BYTES = 64;
    private const MAX_OPTION_KEYS = 64;
    private const MAX_OPTION_NODES = 4096;
    private const MAX_OPTION_SOURCE_BYTES = 524288;
    private const MAX_METADATA_KEYS = 32;
    private const MAX_OCCURRENCE_SOURCE_BYTES = 256;

    /**
     * @param WP_FTS_Storage $storage Storage backend for terms, documents, and
     *        metadata. Backends may be language-aware or legacy aggregate-only.
     * @param object $analyzer Analyzer object exposing `analyze_content()`.
     * @param object|null $postContentExtractor Optional adapter exposing
     *        `extract(object $post, array $opts): array` for post preparation.
     *        New framework-neutral callers should use `index_document()` or
     *        `index_document_fields()`.
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
     * partition or analyzer output changes. Replacements publish the backend's
     * posting, document, metadata, and collection-statistic changes in one
     * storage transaction.
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
        if ($this->storage instanceof WP_FTS_Set_Oriented_Search_Storage) {
            throw new LogicException('Set-oriented storage mutations must use the bounded batch writer.');
        }
        if ($doc_id < 0) {
            throw new InvalidArgumentException('Document id must be non-negative.');
        }
        WP_FTS_Analysis_Limits::assert_source_bytes($html);
        WP_FTS_Html_Text_Stream::assert_analysis_markup_limits($html);
        $this->assert_recognized_option_bounds($opts);

        $primaryLang = $this->resolve_document_language($opts);
        $hash = $this->content_hash($html, $primaryLang);
        $metadataWasProvided = isset($opts['metadata']) && is_array($opts['metadata']);
        $metadata = $metadataWasProvided
            ? $opts['metadata']
            : [];
        if (count($metadata) > self::MAX_METADATA_KEYS) {
            throw new WP_FTS_Analysis_Limit_Exceeded(
                'metadata_keys',
                'FTS document metadata must contain at most 32 keys.'
            );
        }
        $normalizedMetadata = WP_FTS_StorageCompat::normalize_doc_metadata($metadata);
        if (!array_key_exists('search_text', $metadata)) {
            $searchText = rtrim(WP_FTS_Utf8::truncate_bytes(
                WP_FTS_Html_Text_Stream::visible_text($html),
                $this->bounded_positive_option(
                    $opts['metadata_text_limit'] ?? null,
                    20000,
                    1,
                    20000
                )
            ));
            if ($searchText !== '') {
                $normalizedMetadata['search_text'] = $searchText;
            }
        }

        $analysisOptions = $opts;
        $analysisOptions['_max_document_occurrences'] = WP_FTS_Analysis_Limits::MAX_DOCUMENT_OCCURRENCES;
        $indexSurfaces = is_callable([$this->storage, 'indexes_surface_postings'])
            && $this->storage->indexes_surface_postings() === true;
        if ($indexSurfaces) {
            $analysisOptions['_include_document_surface'] = true;
        }
        $analyzedOccurrences = $this->analyze_content($html, $analysisOptions, $primaryLang);
        if (count($analyzedOccurrences) > WP_FTS_Analysis_Limits::MAX_DOCUMENT_OCCURRENCES) {
            throw new WP_FTS_Analysis_Limit_Exceeded(
                'occurrences',
                'FTS document analysis exceeds the 20,000-occurrence limit.'
            );
        }
        $this->assert_analyzer_occurrence_bounds($analyzedOccurrences);
        $nextAlternativeGroup = 0;
        $occurrences = $this->mark_alternative_groups($analyzedOccurrences, $nextAlternativeGroup);

        [$termFrequencies, $langLengths, $surfaceFrequencies] = $this->weighted_term_frequencies_by_language(
            $occurrences,
            $primaryLang,
            $indexSurfaces
        );

        return $this->index_prepared_document([
            'doc_id' => $doc_id,
            'primary_lang' => $primaryLang,
            'content_hash' => $hash,
            'term_frequencies' => $termFrequencies,
            'surface_frequencies' => $surfaceFrequencies,
            'lang_lengths' => $langLengths,
            'metadata' => $normalizedMetadata,
            'replace_metadata_on_hash_match' => $metadataWasProvided,
        ]);
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
        if ($this->storage instanceof WP_FTS_Set_Oriented_Search_Storage) {
            throw new LogicException('Set-oriented storage mutations must use the bounded batch writer.');
        }
        return $this->index_prepared_document($this->prepare_document_fields($doc_id, $fields, $opts));
    }

    /**
     * Analyze weighted fields without reading or mutating storage.
     *
     * Batch writers can prepare many posts in PHP, then apply the returned term
     * frequencies, document hash, language lengths, and metadata with set-oriented
     * storage statements. `index_document_fields()` consumes this exact payload,
     * so batch and legacy single-document writes cannot drift in analysis.
     *
     * @param int $doc_id Stable non-negative document identifier.
     * @param array<int,array<string,mixed>|string> $fields Weighted fields or
     *        legacy string fields.
     * @param array<string,mixed> $opts Document options plus optional metadata.
     * @return array{doc_id:int,primary_lang:string,content_hash:string,term_frequencies:array<string,int>,surface_frequencies:array<string,int>,lang_lengths:array<string,int>,metadata:?array<string,mixed>,replace_metadata_on_hash_match:bool}
     */
    public function prepare_document_fields(int $doc_id, array $fields, array $opts = []): array
    {
        if ($doc_id < 0) {
            throw new InvalidArgumentException('Document id must be non-negative.');
        }

        return $this->analyze_index_source($this->prepare_index_source($doc_id, $fields, $opts));
    }

    /**
     * Delete one active document through the configured storage backend.
     *
     * A backend may physically remove rows immediately or retain a tombstone for
     * later compaction. The indexer also applies any compatibility collection
     * statistics and metadata lifecycle required by that backend.
     *
     * @param int $doc_id Document identifier previously passed to
     *        `index_document()`.
     * @return bool True when an active indexed document was deleted; false when
     *         the id was unknown or already deleted.
     * @throws Throwable Re-throws storage failures after rollback.
     */
    public function delete_document(int $doc_id): bool
    {
        if ($this->storage instanceof WP_FTS_Set_Oriented_Search_Storage) {
            throw new LogicException('Set-oriented storage mutations must use the bounded batch writer.');
        }
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
        if ($this->storage instanceof WP_FTS_Set_Oriented_Search_Storage) {
            throw new LogicException('Set-oriented storage mutations must use the bounded batch writer.');
        }
        return $this->index_prepared_document($this->prepare_post($post, $opts));
    }

    /**
     * Apply one storage-ready preparation payload through the legacy writer.
     *
     * @param array{doc_id:int,primary_lang:string,content_hash:string,term_frequencies:array<string,int>,surface_frequencies?:array<string,int>,lang_lengths:array<string,int>,metadata:?array<string,mixed>,replace_metadata_on_hash_match:bool} $prepared
     */
    private function index_prepared_document(array $prepared): bool
    {
        $doc_id = $prepared['doc_id'];
        $primaryLang = $prepared['primary_lang'];
        $hash = $prepared['content_hash'];
        $termFrequencies = $prepared['term_frequencies'];
        $langLengths = $prepared['lang_lengths'];
        $metadata = $prepared['metadata'];
        $existing = $this->storage->get_doc($doc_id);
        if ($existing !== null && !$existing['deleted'] && $existing['content_hash'] === $hash) {
            if ($metadata !== null && $prepared['replace_metadata_on_hash_match']) {
                WP_FTS_StorageCompat::put_doc_metadata($this->storage, $doc_id, $metadata);
            }
            return false;
        }

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
     * Extract and analyze one post without reading or mutating storage.
     *
     * The returned payload is suitable for a batch storage writer and is the
     * same payload consumed by `index_post()`.
     *
     * @param object $post Object with `ID` and WordPress post-like properties.
     * @param array<string,mixed> $opts Optional language, extraction, field
     *        boost, and metadata overrides.
     * @return array{doc_id:int,primary_lang:string,content_hash:string,term_frequencies:array<string,int>,surface_frequencies:array<string,int>,lang_lengths:array<string,int>,metadata:?array<string,mixed>,replace_metadata_on_hash_match:bool}
     */
    public function prepare_post(object $post, array $opts = []): array
    {
        return $this->prepare_post_from_source($this->prepare_post_source($post, $opts));
    }

    /**
     * Extract, normalize, and fingerprint one post without analyzing its text.
     *
     * Batch workers can compare `content_hash` with the stored document before
     * calling `prepare_post_from_source()`. The returned normalized fields and
     * options are the exact inputs that method will analyze, so the unchanged
     * fast path neither repeats extraction nor risks hashing a different source.
     * Computing the hash reads the analyzer's cheap behavior signature but does
     * not invoke either content-analysis method.
     *
     * @param object $post Object with `ID` and WordPress post-like properties.
     * @param array<string,mixed> $opts Optional language, extraction, field
     *        boost, and metadata overrides.
     * @return array{doc_id:int,primary_lang:string,content_hash:string,fields:array<int,array{name:string,text:string,html?:string,boost:float}>,analysis_options:array<string,mixed>,metadata:?array<string,mixed>,replace_metadata_on_hash_match:bool}
     */
    public function prepare_post_source(object $post, array $opts = []): array
    {
        if (
            $this->storage instanceof WP_FTS_Set_Oriented_Search_Storage
            && (
                !empty($opts['render_blocks'])
                || !empty($opts['render_shortcodes'])
                || (
                    array_key_exists('render_content_callback', $opts)
                    && $opts['render_content_callback'] !== null
                )
            )
        ) {
            throw new WP_FTS_Analysis_Limit_Exceeded(
                'dynamic_rendering_not_set_oriented',
                'Dynamic rendering is unavailable in the bounded relational worker; index static post_content or provide precomputed attached fields.'
            );
        }
        if (
            $this->storage instanceof WP_FTS_Set_Oriented_Search_Storage
            && (
                !property_exists($post, 'terms')
                || !is_array($post->terms)
                || !property_exists($post, 'custom_fields')
                || !is_array($post->custom_fields)
            )
        ) {
            throw new LogicException('Set-oriented post preparation requires authoritative terms and custom_fields arrays.');
        }
        $postProperties = get_object_vars($post);
        $rawPostId = $postProperties['ID'] ?? null;
        if (!is_scalar($rawPostId) || (is_string($rawPostId) && strlen($rawPostId) > self::MAX_OPTION_SCALAR_BYTES)) {
            throw new InvalidArgumentException('Post object IDs must be bounded scalar values.');
        }
        $postId = (int) $rawPostId;
        if ($postId <= 0) {
            throw new InvalidArgumentException('Post object must provide a positive ID.');
        }
        $this->assert_recognized_option_bounds($opts);

        $indexOptions = $opts;
        if (
            $this->storage instanceof WP_FTS_Set_Oriented_Search_Storage
            && !array_key_exists('custom_fields', $indexOptions)
            && !array_key_exists('custom_field_keys', $indexOptions)
        ) {
            $indexOptions['custom_fields'] = array_keys($post->custom_fields);
        }
        $indexOptions['post_id'] = $postId;

        $extractor = $this->postContentExtractor ?? $this->default_post_content_extractor();
        if (!method_exists($extractor, 'extract')) {
            throw new LogicException('Post content extractor must expose extract(object $post, array $opts).');
        }

        $extracted = $extractor->extract($post, $indexOptions);
        $metadata = $extracted['metadata'] ?? null;
        if (!is_array($metadata) || count($metadata) > self::MAX_METADATA_KEYS) {
            throw new WP_FTS_Analysis_Limit_Exceeded(
                'metadata_keys',
                'Extracted FTS metadata must contain at most 32 keys.'
            );
        }
        $this->assert_metadata_map_keys($metadata, 'Extracted FTS metadata');
        if (isset($opts['metadata']) && is_array($opts['metadata'])) {
            if (count($opts['metadata']) > self::MAX_METADATA_KEYS) {
                throw new WP_FTS_Analysis_Limit_Exceeded(
                    'metadata_keys',
                    'FTS metadata overrides must contain at most 32 keys.'
                );
            }
            $this->assert_metadata_map_keys($opts['metadata'], 'FTS metadata overrides');
            $combinedKeys = [];
            foreach ($metadata as $key => $_value) {
                $combinedKeys[(string) $key] = true;
            }
            foreach ($opts['metadata'] as $key => $_value) {
                $combinedKeys[$key] = true;
                if (count($combinedKeys) > self::MAX_METADATA_KEYS) {
                    throw new WP_FTS_Analysis_Limit_Exceeded(
                        'metadata_keys',
                        'Combined FTS metadata must contain at most 32 keys.'
                    );
                }
            }
            $metadata = array_replace($metadata, $opts['metadata']);
        }
        $indexOptions['metadata'] = $metadata;
        $indexOptions['field_boosts'] = $extracted['field_boosts'];

        return $this->prepare_index_source($postId, $extracted['fields'], $indexOptions);
    }

    /**
     * Analyze a payload previously returned by `prepare_post_source()`.
     *
     * @param array{doc_id:int,primary_lang:string,content_hash:string,fields:array<int,array{name:string,text:string,html?:string,boost:float}>,analysis_options:array<string,mixed>,metadata:?array<string,mixed>,replace_metadata_on_hash_match:bool} $source
     * @return array{doc_id:int,primary_lang:string,content_hash:string,term_frequencies:array<string,int>,surface_frequencies:array<string,int>,lang_lengths:array<string,int>,metadata:?array<string,mixed>,replace_metadata_on_hash_match:bool}
     */
    public function prepare_post_from_source(array $source): array
    {
        return $this->analyze_index_source($source);
    }

    /**
     * Normalize and fingerprint fields before any content analysis occurs.
     *
     * @param array<int,array<string,mixed>|string> $fields
     * @param array<string,mixed> $opts
     * @return array{doc_id:int,primary_lang:string,content_hash:string,fields:array<int,array{name:string,text:string,html?:string,boost:float}>,analysis_options:array<string,mixed>,metadata:?array<string,mixed>,replace_metadata_on_hash_match:bool}
     */
    private function prepare_index_source(int $doc_id, array $fields, array $opts): array
    {
        $this->assert_recognized_option_bounds($opts);
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
        if ($metadata !== null && !array_key_exists('search_fields', $metadata)) {
            $searchFields = $this->fields_search_metadata($fields, $opts);
            if ($searchFields !== []) {
                $metadata['search_fields'] = $searchFields;
            }
        }
        $normalizedMetadata = $metadata === null ? null : WP_FTS_StorageCompat::normalize_doc_metadata($metadata);
        $snippetHashSource = is_array($normalizedMetadata)
            ? (string) ($normalizedMetadata['content_search_text'] ?? $normalizedMetadata['search_text'] ?? '')
            : '';
        $hash = $this->content_hash(
            $this->fields_hash_source($fields) . "\0snippet\0" . $snippetHashSource,
            $primaryLang
        );

        return [
            'doc_id' => $doc_id,
            'primary_lang' => $primaryLang,
            'content_hash' => $hash,
            'fields' => $fields,
            'analysis_options' => $opts,
            'metadata' => $normalizedMetadata,
            'replace_metadata_on_hash_match' => true,
        ];
    }

    /**
     * Analyze one normalized source payload into storage-ready frequencies.
     *
     * @param array{doc_id:int,primary_lang:string,content_hash:string,fields:array<int,array{name:string,text:string,html?:string,boost:float}>,analysis_options:array<string,mixed>,metadata:?array<string,mixed>,replace_metadata_on_hash_match:bool} $source
     * @return array{doc_id:int,primary_lang:string,content_hash:string,term_frequencies:array<string,int>,surface_frequencies:array<string,int>,lang_lengths:array<string,int>,metadata:?array<string,mixed>,replace_metadata_on_hash_match:bool}
     */
    private function analyze_index_source(array $source): array
    {
        if (count($source) > 7) {
            throw new InvalidArgumentException('Prepared post source payloads may contain only the seven documented fields.');
        }
        foreach (['doc_id', 'primary_lang', 'content_hash', 'fields', 'analysis_options', 'metadata', 'replace_metadata_on_hash_match'] as $requiredKey) {
            if (!array_key_exists($requiredKey, $source)) {
                throw new InvalidArgumentException('Invalid prepared post source payload.');
            }
        }
        if (!is_int($source['doc_id'] ?? null) || $source['doc_id'] < 0
            || !is_string($source['primary_lang'] ?? null)
            || !is_string($source['content_hash'] ?? null)
            || !is_array($source['fields'] ?? null)
            || !is_array($source['analysis_options'] ?? null)
            || (!is_array($source['metadata'] ?? null) && ($source['metadata'] ?? null) !== null)
        ) {
            throw new InvalidArgumentException('Invalid prepared post source payload.');
        }
        if (
            strlen($source['primary_lang']) > self::MAX_OPTION_SCALAR_BYTES
            || strlen($source['content_hash']) > self::MAX_OPTION_SCALAR_BYTES
        ) {
            throw new InvalidArgumentException('Prepared post language and hash values may contain at most 64 bytes.');
        }

        $doc_id = $source['doc_id'];
        $primaryLang = $source['primary_lang'];
        $hash = $source['content_hash'];
        $opts = $source['analysis_options'];
        $this->assert_recognized_option_bounds($opts);
        $fields = $this->normalize_index_fields($source['fields'], $opts);
        $source['metadata'] = $source['metadata'] === null
            ? null
            : WP_FTS_StorageCompat::normalize_doc_metadata($source['metadata']);
        $snippetHashSource = is_array($source['metadata'])
            ? (string) ($source['metadata']['content_search_text'] ?? $source['metadata']['search_text'] ?? '')
            : '';
        $expectedHash = $this->content_hash(
            $this->fields_hash_source($fields) . "\0snippet\0" . $snippetHashSource,
            $primaryLang
        );
        if (!hash_equals($expectedHash, $hash)) {
            throw new InvalidArgumentException('Prepared post source content hash does not match its normalized fields and metadata.');
        }
        $hash = $expectedHash;

        $occurrences = [];
        $nextAlternativeGroup = 0;
        $indexSurfaces = is_callable([$this->storage, 'indexes_surface_postings'])
            && $this->storage->indexes_surface_postings() === true;
        foreach ($fields as $field) {
            $fieldOpts = $opts;
            $fieldOpts['field_name'] = $field['name'];
            $fieldOpts['_max_document_occurrences'] = WP_FTS_Analysis_Limits::MAX_DOCUMENT_OCCURRENCES - count($occurrences);
            if ($indexSurfaces) {
                $fieldOpts['_include_document_surface'] = true;
            }
            $fieldOccurrences = isset($field['html'])
                ? $this->analyze_content((string) $field['html'], $fieldOpts, $primaryLang)
                : $this->analyze_plain_content((string) $field['text'], $fieldOpts, $primaryLang);
            if (count($fieldOccurrences) > $fieldOpts['_max_document_occurrences']) {
                throw new WP_FTS_Analysis_Limit_Exceeded(
                    'occurrences',
                    'FTS document analysis exceeds the 20,000-occurrence limit.'
                );
            }
            $this->assert_analyzer_occurrence_bounds($fieldOccurrences);

            foreach ($this->mark_alternative_groups($fieldOccurrences, $nextAlternativeGroup) as $occurrence) {
                if (is_array($occurrence)) {
                    $occurrence['weight'] = (float) ($occurrence['weight'] ?? 1.0) * $field['boost'];
                }
                $occurrences[] = $occurrence;
            }
        }

        [$termFrequencies, $langLengths, $surfaceFrequencies] = $this->weighted_term_frequencies_by_language(
            $occurrences,
            $primaryLang,
            $indexSurfaces
        );

        return [
            'doc_id' => $doc_id,
            'primary_lang' => $primaryLang,
            'content_hash' => $hash,
            'term_frequencies' => $termFrequencies,
            'surface_frequencies' => $surfaceFrequencies,
            'lang_lengths' => $langLengths,
            'metadata' => $source['metadata'],
            'replace_metadata_on_hash_match' => (bool) ($source['replace_metadata_on_hash_match'] ?? true),
        ];
    }

    /** Bound custom analyzer rows before trimming, canonicalization, or copies. */
    private function assert_analyzer_occurrence_bounds(array $occurrences): void
    {
        $allowedKeys = [
            'term' => true,
            'weight' => true,
            'lang' => true,
            'position' => true,
            'rank' => true,
            'source' => true,
            'surface' => true,
            'normalized_surface' => true,
            '_alternative_group' => true,
        ];
        foreach ($occurrences as $occurrence) {
            if (is_string($occurrence)) {
                if (strlen($occurrence) > WP_FTS_TermNamespace::MAX_TERM_KEY_BYTES) {
                    throw new WP_FTS_Analysis_Limit_Exceeded('occurrence_bytes', 'An FTS analyzer term exceeds the lexical key limit.');
                }
                continue;
            }
            if (!is_array($occurrence)) {
                throw new WP_FTS_Analysis_Limit_Exceeded('occurrence_shape', 'FTS analyzer occurrences must be strings or arrays.');
            }
            if (count($occurrence) > count($allowedKeys)) {
                throw new WP_FTS_Analysis_Limit_Exceeded('occurrence_shape', 'An FTS analyzer occurrence contains too many fields.');
            }
            foreach ($occurrence as $key => $_value) {
                if (!is_string($key) || !isset($allowedKeys[$key])) {
                    throw new WP_FTS_Analysis_Limit_Exceeded('occurrence_shape', 'An FTS analyzer occurrence contains an unsupported field.');
                }
            }
            if (
                array_key_exists('term', $occurrence)
                && (!is_scalar($occurrence['term']) || strlen((string) $occurrence['term']) > WP_FTS_TermNamespace::MAX_TERM_KEY_BYTES)
            ) {
                throw new WP_FTS_Analysis_Limit_Exceeded('occurrence_bytes', 'An FTS analyzer term exceeds the lexical key limit.');
            }
            if (
                array_key_exists('lang', $occurrence)
                && (!is_scalar($occurrence['lang']) || strlen((string) $occurrence['lang']) > self::MAX_OPTION_SCALAR_BYTES)
            ) {
                throw new WP_FTS_Analysis_Limit_Exceeded('occurrence_bytes', 'An FTS analyzer language exceeds 64 bytes.');
            }
            foreach (['position', 'rank', 'weight', '_alternative_group'] as $key) {
                if (!array_key_exists($key, $occurrence)) {
                    continue;
                }
                if (!is_scalar($occurrence[$key]) || (is_string($occurrence[$key]) && strlen($occurrence[$key]) > self::MAX_OPTION_SCALAR_BYTES)) {
                    throw new WP_FTS_Analysis_Limit_Exceeded('occurrence_bytes', 'An FTS analyzer numeric field exceeds 64 bytes.');
                }
            }
            if (
                array_key_exists('source', $occurrence)
                && (!is_scalar($occurrence['source']) || strlen((string) $occurrence['source']) > self::MAX_OCCURRENCE_SOURCE_BYTES)
            ) {
                throw new WP_FTS_Analysis_Limit_Exceeded('occurrence_bytes', 'An FTS analyzer source label exceeds 256 bytes.');
            }
            foreach (['surface', 'normalized_surface'] as $key) {
                if (!array_key_exists($key, $occurrence)) {
                    continue;
                }
                if (!is_scalar($occurrence[$key]) || strlen((string) $occurrence[$key]) > WP_FTS_Analysis_Limits::MAX_LEXICAL_RUN_BYTES) {
                    throw new WP_FTS_Analysis_Limit_Exceeded('occurrence_bytes', 'An FTS analyzer surface exceeds the lexical-run limit.');
                }
            }
        }
    }

    /**
     * Give alternatives emitted for one source token an indexer-local group id.
     *
     * Analyzer positions restart for every independently analyzed field, so the
     * monotonically increasing id prevents adjacent fields with the same local
     * position from being collapsed into one logical occurrence.
     *
     * @param array<int,array<string,mixed>|string> $occurrences
     * @return array<int,array<string,mixed>|string>
     */
    private function mark_alternative_groups(array $occurrences, int &$nextGroup): array
    {
        $groupsByPosition = [];
        foreach ($occurrences as &$occurrence) {
            if (!is_array($occurrence) || !isset($occurrence['position']) || !is_scalar($occurrence['position'])) {
                continue;
            }

            $position = (string) $occurrence['position'];
            if (!isset($groupsByPosition[$position])) {
                $groupsByPosition[$position] = $nextGroup++;
            }
            $occurrence['_alternative_group'] = $groupsByPosition[$position];
        }
        unset($occurrence);

        return $occurrences;
    }

    /**
     * Resolve the plugin-provided extractor for legacy `index_post()` callers.
     */
    private function default_post_content_extractor(): object
    {
        if (!class_exists('WP_FTS_PostContentExtractor')) {
            throw new LogicException('Post preparation requires a post content extractor adapter. Pass one to WP_FTS_Indexer or use index_document_fields().');
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
                if (is_scalar($signature)) {
                    $signature = (string) $signature;
                    if (strlen($signature) > self::MAX_OCCURRENCE_SOURCE_BYTES) {
                        throw new WP_FTS_Analysis_Limit_Exceeded(
                            'analyzer_signature_bytes',
                            'An FTS analyzer signature exceeds 256 bytes.'
                        );
                    }
                    if (trim($signature) !== '') {
                        return $signature;
                    }
                }
            } catch (WP_FTS_Analysis_Limit_Exceeded $error) {
                throw $error;
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

        return $this->analyzer->analyze_content($html, $this->analysis_options($opts, $primaryLang));
    }

    /**
     * Analyze a field value that is already plain text.
     *
     * New analyzers can skip HTML segmentation for these fields. Older analyzer
     * objects fall back to the existing HTML wrapper so the public indexer
     * contract stays compatible.
     *
     * @return array<int,array<string,mixed>>
     */
    private function analyze_plain_content(string $text, array $opts, string $primaryLang): array
    {
        $analysisOpts = $this->analysis_options($opts, $primaryLang);
        if (is_callable([$this->analyzer, 'analyze_plain_content'])) {
            return $this->analyzer->analyze_plain_content($text, $analysisOpts);
        }

        return $this->analyze_content(
            '<div>' . htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8') . '</div>',
            $opts,
            $primaryLang
        );
    }

    /**
     * Fill analyzer options with the resolved document language.
     *
     * @param array<string,mixed> $opts
     * @return array<string,mixed>
     */
    private function analysis_options(array $opts, string $primaryLang): array
    {
        $analysisOpts = $opts;
        $analysisOpts['default_lang'] = $primaryLang;
        if (WP_FTS_TermNamespace::language_from_options($opts, null, ['lang', 'language', 'primary_lang', 'document_lang']) !== null) {
            $analysisOpts['document_lang'] = $primaryLang;
            $analysisOpts['lang'] = $primaryLang;
            $analysisOpts['language'] = $primaryLang;
        }

        return $analysisOpts;
    }

    /** Reject direct options before copies, canonicalization, or integer casts. */
    private function assert_recognized_option_bounds(array $opts): void
    {
        if (count($opts) > self::MAX_OPTION_KEYS) {
            throw new WP_FTS_Analysis_Limit_Exceeded('option_keys', 'FTS document options may contain at most 64 keys.');
        }
        $nodes = 0;
        $sourceBytes = 0;
        $stack = [[$opts, 0]];
        while ($stack !== []) {
            [$map, $depth] = array_pop($stack);
            if ($depth > 16) {
                throw new WP_FTS_Analysis_Limit_Exceeded('option_depth', 'FTS document options may contain at most 16 nested levels.');
            }
            if (count($map) > self::MAX_OPTION_NODES) {
                throw new WP_FTS_Analysis_Limit_Exceeded('option_nodes', 'FTS document options exceed the 4,096-node limit.');
            }
            foreach ($map as $key => $value) {
                if (++$nodes > self::MAX_OPTION_NODES) {
                    throw new WP_FTS_Analysis_Limit_Exceeded('option_nodes', 'FTS document options exceed the 4,096-node limit.');
                }
                if (is_string($key)) {
                    if (strlen($key) > self::MAX_FIELD_NAME_BYTES) {
                        throw new WP_FTS_Analysis_Limit_Exceeded('option_key_bytes', 'An FTS document option key exceeds 191 bytes.');
                    }
                    $sourceBytes += strlen($key);
                }
                if (is_string($value)) {
                    $sourceBytes += strlen($value);
                } elseif (is_array($value)) {
                    $stack[] = [$value, $depth + 1];
                }
                if ($sourceBytes > self::MAX_OPTION_SOURCE_BYTES) {
                    throw new WP_FTS_Analysis_Limit_Exceeded('option_source_bytes', 'FTS document options exceed the 512 KiB source limit.');
                }
            }
        }

        foreach (['lang', 'language', 'primary_lang', 'document_lang', 'default_lang', 'locale'] as $key) {
            if (!array_key_exists($key, $opts) || $opts[$key] === null) {
                continue;
            }
            if (!is_scalar($opts[$key]) || strlen((string) $opts[$key]) > self::MAX_OPTION_SCALAR_BYTES) {
                throw new InvalidArgumentException("FTS {$key} options may contain at most 64 bytes.");
            }
        }
        foreach (['metadata_text_limit', 'metadata_html_limit', 'metadata_field_limit', 'metadata_field_text_limit', 'metadata_field_html_limit'] as $key) {
            if (!array_key_exists($key, $opts) || $opts[$key] === null) {
                continue;
            }
            if (!is_scalar($opts[$key]) || (is_string($opts[$key]) && strlen($opts[$key]) > self::MAX_OPTION_SCALAR_BYTES)) {
                throw new InvalidArgumentException("FTS {$key} options must be bounded scalar values.");
            }
        }
    }

    /** Bound raw metadata keys before union construction or array replacement. */
    private function assert_metadata_map_keys(array $metadata, string $context): void
    {
        foreach ($metadata as $key => $_value) {
            if (strlen((string) $key) > self::MAX_FIELD_NAME_BYTES) {
                throw new WP_FTS_Analysis_Limit_Exceeded(
                    'structured_key_bytes',
                    "{$context} keys may contain at most 191 bytes."
                );
            }
        }
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
        if (count($fields) > self::MAX_INDEX_FIELDS) {
            throw new WP_FTS_Analysis_Limit_Exceeded(
                'index_fields',
                'An FTS document contains more than 32 index fields.'
            );
        }

        $rawBoosts = $opts['field_boosts'] ?? [];
        if (!is_array($rawBoosts)) {
            $rawBoosts = [];
        }
        if (count($rawBoosts) > self::MAX_FIELD_BOOSTS) {
            throw new WP_FTS_Analysis_Limit_Exceeded(
                'field_boosts',
                'An FTS document contains more than 32 field boosts.'
            );
        }
        $boosts = [];
        foreach ($rawBoosts as $field => $boost) {
            $field = (string) $field;
            if (strlen($field) > self::MAX_FIELD_NAME_BYTES) {
                throw new WP_FTS_Analysis_Limit_Exceeded(
                    'field_boost_name_bytes',
                    'An FTS field-boost name exceeds the 191-byte limit.'
                );
            }
            if (is_string($boost) && strlen($boost) > self::MAX_OPTION_SCALAR_BYTES) {
                throw new WP_FTS_Analysis_Limit_Exceeded(
                    'field_boost_value_bytes',
                    'An FTS field-boost value exceeds the 64-byte limit.'
                );
            }
            if (is_numeric($boost)) {
                $boosts[$field] = $this->normalize_field_boost((float) $boost);
            }
        }

        $normalized = [];
        $documentSourceBytes = 0;
        $fieldNumber = 0;
        foreach ($fields as $field) {
            $fieldNumber++;
            if (is_string($field)) {
                $field = ['name' => 'content', 'text' => $field];
            }
            if (!is_array($field)) {
                continue;
            }

            $rawName = $field['name'] ?? 'field_' . $fieldNumber;
            $rawText = $field['text'] ?? ($field['html'] ?? '');
            $rawHtml = $field['html'] ?? null;
            if (!is_scalar($rawName) || !is_scalar($rawText) || ($rawHtml !== null && !is_scalar($rawHtml))) {
                throw new WP_FTS_Analysis_Limit_Exceeded(
                    'index_field_shape',
                    'FTS index field names and sources must be scalar.'
                );
            }
            $rawName = (string) $rawName;
            if (strlen($rawName) > self::MAX_FIELD_NAME_BYTES) {
                throw new WP_FTS_Analysis_Limit_Exceeded(
                    'index_field_name_bytes',
                    'An FTS index field name exceeds the 191-byte limit.'
                );
            }
            $rawText = (string) $rawText;
            $rawHtml = $rawHtml !== null ? (string) $rawHtml : null;
            $documentSourceBytes += strlen($rawText) + ($rawHtml === null ? 0 : strlen($rawHtml));
            WP_FTS_Analysis_Limits::assert_document_source_bytes($documentSourceBytes);
            if ($rawHtml !== null && trim($rawHtml) !== '') {
                // Source preparation builds snippet metadata before invoking
                // the analyzer. Enforce the same syntax envelope here so a
                // custom analyzer cannot make that earlier parser traverse an
                // unbounded tag stack or attribute list.
                WP_FTS_Html_Text_Stream::assert_analysis_markup_limits($rawHtml);
            }

            $name = trim($rawName);
            $text = trim($rawText);
            $html = $rawHtml;
            if ($name === '' || ($text === '' && trim((string) $html) === '')) {
                continue;
            }

            $rawBoost = $field['boost'] ?? ($boosts[$name] ?? 1.0);
            if (!is_scalar($rawBoost) || (is_string($rawBoost) && strlen($rawBoost) > self::MAX_OPTION_SCALAR_BYTES)) {
                throw new WP_FTS_Analysis_Limit_Exceeded(
                    'field_boost_value_bytes',
                    'An FTS field boost must be a bounded scalar value.'
                );
            }

            $row = [
                'name' => $name,
                'text' => $text,
                'boost' => $this->normalize_field_boost((float) $rawBoost),
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
     * Build a bounded HTML source for fallback extraction and diagnostics.
     *
     * This is an optional presentation sidecar, not the indexed source. Hitting
     * its byte limit stops only this copy; `analyze_index_source()` still reads
     * every normalized field and rejects any semantic analysis overflow with a
     * typed limit. Other metadata fields are built independently below.
     *
     * @param array<int,array{name:string,text:string,html?:string,boost:float}> $fields
     */
    private function fields_search_html(array $fields, int $limit): string
    {
        $fragments = [];
        $bytes = 0;
        foreach ($fields as $field) {
            if (isset($field['html']) && trim((string) $field['html']) !== '') {
                foreach ($this->compact_html_metadata_fragments((string) $field['html']) as $fragment) {
                    if (isset($fragments[$fragment])) {
                        continue;
                    }

                    $separatorBytes = $fragments === [] ? 0 : 1;
                    $remaining = max(0, $limit - $bytes - $separatorBytes);
                    if ($remaining === 0) {
                        return rtrim(implode(' ', array_keys($fragments)));
                    }

                    $bounded = WP_FTS_Utf8::truncate_bytes($fragment, $remaining);
                    if ($bounded !== '') {
                        $fragments[$bounded] = true;
                        $bytes += $separatorBytes + strlen($bounded);
                    }
                    if (strlen($bounded) < strlen($fragment) || $bytes >= $limit) {
                        return rtrim(implode(' ', array_keys($fragments)));
                    }
                }
            }
        }

        if ($fragments === []) {
            return '';
        }

        return rtrim(implode(' ', array_keys($fragments)));
    }

    /**
     * Store a compact per-field source sidecar for explain-only diagnostics.
     *
     * @param array<int,array{name:string,text:string,html?:string,boost:float}> $fields
     * @return array<int,array{name:string,text:string,boost:float,html?:string}>
     */
    private function fields_search_metadata(array $fields, array $opts): array
    {
        $fieldLimit = $this->bounded_positive_option(
            $opts['metadata_field_limit'] ?? null,
            self::FIELD_METADATA_MAX_FIELDS,
            1,
            100
        );
        $textLimit = $this->bounded_positive_option(
            $opts['metadata_field_text_limit'] ?? null,
            self::FIELD_METADATA_TEXT_BYTES,
            1,
            20000
        );
        $htmlLimit = $this->bounded_positive_option(
            $opts['metadata_field_html_limit'] ?? null,
            self::FIELD_METADATA_HTML_BYTES,
            1,
            20000
        );

        $metadata = [];
        foreach ($fields as $field) {
            $name = trim((string) ($field['name'] ?? ''));
            $text = rtrim(WP_FTS_Utf8::truncate_bytes((string) ($field['text'] ?? ''), $textLimit));
            $html = isset($field['html'])
                ? rtrim(WP_FTS_Utf8::truncate_bytes((string) $field['html'], $htmlLimit))
                : '';
            if ($name === '' || ($text === '' && trim($html) === '')) {
                continue;
            }

            $row = [
                'name' => $name,
                'text' => $text,
                'boost' => $this->normalize_field_boost((float) ($field['boost'] ?? 1.0)),
            ];
            if (trim($html) !== '') {
                $row['html'] = $html;
            }
            $metadata[] = $row;
            if (count($metadata) >= $fieldLimit) {
                break;
            }
        }

        return $metadata;
    }

    private function bounded_positive_option(mixed $value, int $default, int $min, int $max): int
    {
        $resolved = is_numeric($value) ? (int) $value : $default;

        return max($min, min($max, $resolved));
    }

    /**
     * Keep only HTML fragments needed for fallback extraction and diagnostics.
     *
     * Plain snippet text already lives in `search_text`. This sidecar preserves
     * bounded field evidence without exposing source markup in returned snippets.
     *
     * Fragments are emitted in source order and overlapping inline ranges are
     * merged as the word stream advances. This must remain a generator: a
     * document containing hundreds of thousands of encoded one-character words
     * is still below the source-byte limit, but retaining one PHP range array
     * per word would exhaust a low-end host before the analyzer can apply its
     * occurrence limit.
     *
     * @return iterable<int,string>
     */
    private function compact_html_metadata_fragments(string $html): iterable
    {
        $currentRange = null;
        foreach (WP_FTS_Html_Text_Stream::visible_word_stream($html) as $word) {
            $sourceStart = (int) $word['source_start'];
            $sourceEnd = (int) $word['source_end'];
            $source = substr($html, $sourceStart, max(0, $sourceEnd - $sourceStart));
            if (!str_contains($source, '<') && !str_contains($source, '&')) {
                continue;
            }

            $range = WP_FTS_Html_Text_Stream::expand_inline_range($html, $sourceStart, $sourceEnd);
            if ($currentRange !== null && $range['start'] <= $currentRange['end']) {
                $currentRange['end'] = max($currentRange['end'], $range['end']);
                continue;
            }

            if ($currentRange !== null) {
                $fragment = trim(substr(
                    $html,
                    $currentRange['start'],
                    max(0, $currentRange['end'] - $currentRange['start'])
                ));
                if ($fragment !== '') {
                    yield $fragment;
                }
            }
            $currentRange = $range;
        }

        if ($currentRange !== null) {
            $fragment = trim(substr(
                $html,
                $currentRange['start'],
                max(0, $currentRange['end'] - $currentRange['start'])
            ));
            if ($fragment !== '') {
                yield $fragment;
            }
        }
    }

    /**
     * Build a deterministic hash source for field-based indexing.
     *
     * Canonical WordPress metadata is deliberately excluded. The persisted
     * content-snippet source is appended by the caller so a filter-only snippet
     * change cannot take the relational unchanged fast path.
     *
     * @param array<int,array{name:string,text:string,html?:string,boost:float}> $fields
     */
    private function fields_hash_source(array $fields): string
    {
        return json_encode($fields, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    /**
     * Collapse analyzer occurrences into namespaced term frequencies and lengths.
     *
     * Occurrences may be structured rows or legacy strings. Terms that already
     * contain a namespace take precedence over the row language. Every stored key
     * is normalized to `lang . "\\x1e" . term`. Alternative lemmas retain
     * separate postings for recall, but one source-token group contributes only
     * its strongest weight to document length rather than pretending every
     * interpretation was another token.
     *
     * @param array<int,array<string,mixed>|string> $occurrences
     * @return array{0:array<string,int>,1:array<string,int>,2:array<string,int>}
     */
    private function weighted_term_frequencies_by_language(
        array $occurrences,
        string $defaultLang,
        bool $indexSurfaces
    ): array
    {
        $candidates = [];
        $alternativeGroups = [];
        $distinctKeys = [];
        $surfaceWeights = [];
        $sequence = 0;
        foreach ($occurrences as $occurrence) {
            $term = is_array($occurrence)
                ? trim((string) ($occurrence['term'] ?? ''))
                : trim((string) $occurrence);
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

            $group = is_array($occurrence) && isset($occurrence['_alternative_group']) && is_numeric($occurrence['_alternative_group'])
                ? (string) (int) $occurrence['_alternative_group']
                : null;
            $rank = is_array($occurrence) && isset($occurrence['rank']) && is_numeric($occurrence['rank'])
                ? max(0, (int) $occurrence['rank'])
                : 0;
            $surface = '';
            if (is_array($occurrence) && isset($occurrence['normalized_surface']) && is_scalar($occurrence['normalized_surface'])) {
                $surface = trim((string) $occurrence['normalized_surface']);
            } elseif (
                is_array($occurrence)
                && isset($occurrence['surface'])
                && is_scalar($occurrence['surface'])
                && trim((string) $occurrence['surface']) === $term
            ) {
                $surface = $term;
            }
            if ($indexSurfaces && $surface !== '') {
                // Long lexical runs remain searchable by every representable
                // prefix. Two runs sharing all storable bytes are equivalent
                // because no longer query identity can fit the dictionary.
                $surfaceBytes = WP_FTS_TermNamespace::MAX_TERM_KEY_BYTES
                    - strlen(WP_FTS_TermNamespace::namespace_term($lang, ''));
                $surface = $surfaceBytes > 0
                    ? WP_FTS_Utf8::truncate_bytes($surface, $surfaceBytes)
                    : '';
            }
            if ($indexSurfaces && $surface !== '') {
                $surfaceKey = $group === null ? 'occurrence:' . $sequence : 'alternative:' . $group;
                $surfaceIdentity = WP_FTS_TermNamespace::namespace_term($lang, $surface);
                if (!isset($surfaceWeights[$surfaceIdentity])) {
                    if (count($surfaceWeights) >= WP_FTS_Analysis_Limits::MAX_DOCUMENT_DISTINCT_SURFACES) {
                        throw new WP_FTS_Analysis_Limit_Exceeded(
                            'distinct_surfaces',
                            'FTS document analysis exceeds the 4,096-distinct-surface limit.'
                        );
                    }
                    $surfaceWeights[$surfaceIdentity] = [];
                }
                $surfaceWeights[$surfaceIdentity][$surfaceKey] = max(
                    $surfaceWeights[$surfaceIdentity][$surfaceKey] ?? 0.0,
                    $weight
                );
            }

            if ($term === '' || !WP_FTS_TermNamespace::term_key_fits($term, $lang)) {
                $sequence++;
                continue;
            }
            $key = WP_FTS_TermNamespace::namespace_term($lang, $term);
            if (!isset($distinctKeys[$key])) {
                $distinctKeys[$key] = true;
                if (count($distinctKeys) > WP_FTS_Analysis_Limits::MAX_DOCUMENT_DISTINCT_TERMS) {
                    throw new WP_FTS_Analysis_Limit_Exceeded(
                        'distinct_terms',
                        'FTS document analysis exceeds the 4,096-distinct-term limit.'
                    );
                }
            }
            $candidates[] = [
                'key' => $key,
                'lang' => $lang,
                'weight' => $weight,
                'length_key' => $group === null ? 'occurrence:' . $sequence : 'alternative:' . $group,
                'group' => $group,
                'rank' => $rank,
                'source' => is_array($occurrence) ? (string) ($occurrence['source'] ?? '') : '',
            ];
            $sequence++;

            if ($group === null) {
                continue;
            }
            if (!isset($alternativeGroups[$group])) {
                $alternativeGroups[$group] = ['count' => 1, 'min_rank' => $rank, 'min_rank_count' => 1];
                continue;
            }
            $alternativeGroups[$group]['count']++;
            if ($rank < $alternativeGroups[$group]['min_rank']) {
                $alternativeGroups[$group]['min_rank'] = $rank;
                $alternativeGroups[$group]['min_rank_count'] = 1;
            } elseif ($rank === $alternativeGroups[$group]['min_rank']) {
                $alternativeGroups[$group]['min_rank_count']++;
            }
        }

        $weights = [];
        $lengthWeights = [];
        foreach ($candidates as $candidate) {
            $weight = $candidate['weight'];
            $group = $candidate['group'];
            if (
                $group !== null
                && $candidate['source'] === 'lemma-pack'
                && ($alternativeGroups[$group]['count'] ?? 0) > 1
                && ($alternativeGroups[$group]['min_rank_count'] ?? 0) === 1
                && $candidate['rank'] === ($alternativeGroups[$group]['min_rank'] ?? -1)
            ) {
                $weight *= 2.0;
            }

            $weights[$candidate['key']] = ($weights[$candidate['key']] ?? 0.0) + $weight;
            $lengthKey = $candidate['length_key'];
            $lang = $candidate['lang'];
            $lengthWeights[$lang][$lengthKey] = max($lengthWeights[$lang][$lengthKey] ?? 0.0, $weight);
        }

        $frequencies = [];
        foreach ($weights as $term => $weight) {
            $frequencies[$term] = max(1, (int) round($weight));
        }

        $langLengths = [];
        foreach ($lengthWeights as $lang => $occurrenceWeights) {
            foreach ($occurrenceWeights as $weight) {
                $langLengths[$lang] = ($langLengths[$lang] ?? 0) + max(1, (int) round($weight));
            }
        }

        $surfaceFrequencies = [];
        foreach ($surfaceWeights as $surface => $occurrenceWeights) {
            $surfaceFrequencies[$surface] = max(1, (int) round(array_sum($occurrenceWeights)));
        }

        ksort($frequencies, SORT_STRING);
        ksort($surfaceFrequencies, SORT_STRING);
        ksort($langLengths, SORT_STRING);

        return [$frequencies, $langLengths, $surfaceFrequencies];
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
