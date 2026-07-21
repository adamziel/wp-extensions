<?php
declare(strict_types=1);

/**
 * Bridges language-aware indexer/searcher code to legacy storage backends.
 *
 * The integrated indexer expects per-language document lengths and metadata, but
 * older backends accepted only aggregate document length/hash calls. This helper
 * detects method arity and normalizes values so the rest of the code can use one
 * language-aware contract.
 */
final class WP_FTS_StorageCompat
{
    private const METADATA_SEARCH_FIELD_MAX_FIELDS = 32;
    private const METADATA_SEARCH_FIELD_TEXT_BYTES = 2000;
    private const METADATA_SEARCH_FIELD_HTML_BYTES = 2000;
    private const METADATA_MAX_DEPTH = 16;
    private const METADATA_MAX_NODES = 2048;
    private const METADATA_MAX_TEXT_BYTES = 262144;
    private const METADATA_MAX_MAP_KEYS = 32;
    private const METADATA_MAX_KEY_BYTES = 191;
    private const METADATA_MAX_NUMERIC_BYTES = 64;

    /**
     * Replace one document's postings through a row-postings backend when possible.
     *
     * @param array<string,int> $termFrequencies Stored term key => weighted tf.
     * @return bool True when the storage handled the replacement natively.
     */
    public static function replace_doc_postings(WP_FTS_Storage $storage, int $docId, array $termFrequencies): bool
    {
        if (!$storage instanceof WP_FTS_Row_Postings_Writer_Storage) {
            return false;
        }

        $storage->replace_doc_postings($docId, $termFrequencies);

        return true;
    }

    /**
     * Fetch decoded postings for requested terms.
     *
     * Row-postings backends return rows directly. Blob-backed implementations
     * keep using `get_terms()` plus `WP_FTS_PostingsCodec` for compatibility.
     *
     * @param string[] $terms
     * @param int|null $candidateCap Optional approximate per-term document-id
     *        cap.
     * @param int|null $rowCap Optional maximum rows returned across all terms.
     * @return array<string,array<int,int>>
     */
    public static function get_postings(
        WP_FTS_Storage $storage,
        array $terms,
        ?int $candidateCap = null,
        ?int $rowCap = null
    ): array
    {
        $terms = array_values(array_unique(array_map('strval', $terms)));
        if ($terms === []) {
            return [];
        }

        $rowCap = $rowCap !== null ? max(1, $rowCap) : null;
        if ($candidateCap !== null && $candidateCap > 0 && $rowCap !== null) {
            $candidateCap = min($candidateCap, max(1, intdiv($rowCap, count($terms))));
        }
        if ($rowCap !== null && $storage instanceof WP_FTS_Budgeted_Postings_Storage) {
            return self::limit_posting_rows(
                $storage->get_budgeted_postings($terms, $candidateCap, $rowCap),
                $rowCap
            );
        }

        if ($candidateCap !== null && $candidateCap > 0 && $storage instanceof WP_FTS_Capped_Postings_Storage) {
            return self::limit_posting_rows($storage->get_capped_postings($terms, $candidateCap), $rowCap);
        }

        if ($storage instanceof WP_FTS_Row_Postings_Storage) {
            return self::limit_posting_rows($storage->get_postings($terms), $rowCap);
        }

        $postingsByTerm = [];
        foreach ($storage->get_terms($terms) as $term => $row) {
            $postings = WP_FTS_PostingsCodec::decode($row['postings']);
            if ($postings === []) {
                continue;
            }

            ksort($postings, SORT_NUMERIC);
            $postingsByTerm[$term] = $postings;
        }

        return self::limit_posting_rows($postingsByTerm, $rowCap);
    }

    /**
     * Keep a deterministic global prefix of decoded posting rows.
     *
     * @param array<string,array<int,int>> $postingsByTerm
     * @return array<string,array<int,int>>
     */
    private static function limit_posting_rows(array $postingsByTerm, ?int $rowCap): array
    {
        ksort($postingsByTerm, SORT_STRING);
        if ($rowCap === null) {
            return $postingsByTerm;
        }

        $remaining = max(1, $rowCap);
        $bounded = [];
        foreach ($postingsByTerm as $term => $postings) {
            if ($remaining <= 0) {
                break;
            }

            ksort($postings, SORT_NUMERIC);
            $postings = array_slice($postings, 0, $remaining, true);
            if ($postings !== []) {
                $bounded[(string) $term] = $postings;
                $remaining -= count($postings);
            }
        }

        return $bounded;
    }

    /**
     * Return stored term keys that have postings for one document.
     *
     * Backends with row-posting support can answer this directly. Blob-backed
     * compatibility stores fall back to decoded postings so fake and file
     * storage stay useful for admin diagnostics.
     *
     * @return string[] Sorted stored term keys, limited when `$limit > 0`.
     */
    public static function terms_for_doc(WP_FTS_Storage $storage, int $docId, int $limit = 0): array
    {
        if ($docId <= 0) {
            return [];
        }

        if ($storage instanceof WP_FTS_Document_Terms_Storage) {
            return self::normalize_doc_terms($storage->terms_for_doc($docId), $limit);
        }

        $terms = [];
        foreach (array_chunk($storage->all_terms(), 200) as $chunk) {
            foreach (self::get_postings($storage, $chunk) as $term => $postings) {
                if (array_key_exists($docId, $postings)) {
                    $terms[] = $term;
                    if ($limit > 0 && count($terms) >= $limit) {
                        return self::normalize_doc_terms($terms, $limit);
                    }
                }
            }
        }

        return self::normalize_doc_terms($terms, $limit);
    }

    /**
     * Return stored term keys that start with a namespaced prefix.
     *
     * Backends with native prefix support can use indexed range reads. Legacy
     * blob-backed stores fall back to their sorted term list, which is
     * acceptable for in-process/file indexes and keeps the extension optional.
     *
     * @return string[] Sorted stored term keys, capped to `$limit`.
     */
    public static function terms_with_prefix(WP_FTS_Storage $storage, string $prefix, int $limit): array
    {
        $prefix = (string) $prefix;
        $limit = max(1, (int) $limit);
        if ($prefix === '') {
            return [];
        }

        if ($storage instanceof WP_FTS_Prefix_Term_Storage) {
            return self::normalize_prefix_terms($storage->terms_with_prefix($prefix, $limit), $prefix, $limit);
        }

        return self::normalize_prefix_terms($storage->all_terms(), $prefix, $limit);
    }

    /**
     * Detect whether a backend accepts language-aware document payloads.
     *
     * @return bool True when `put_doc()` has the new four-argument shape and
     *         `get_doc_lengths()` accepts a language argument.
     */
    public static function supports_language_docs(WP_FTS_Storage $storage): bool
    {
        return self::method_parameter_count($storage, 'put_doc') >= 4
            && self::method_parameter_count($storage, 'get_doc_lengths') >= 2;
    }

    /**
     * Detect whether a backend accepts language-aware collection metadata.
     *
     * @return bool True when `add_meta()` and `get_meta()` expose language
     *         arguments.
     */
    public static function supports_language_meta(WP_FTS_Storage $storage): bool
    {
        return self::method_parameter_count($storage, 'add_meta') >= 3
            && self::method_parameter_count($storage, 'get_meta') >= 1;
    }

    /**
     * Detect whether a backend can persist product-facing document metadata.
     */
    public static function supports_doc_metadata(WP_FTS_Storage $storage): bool
    {
        return $storage instanceof WP_FTS_DocumentMetadataStorage
            || (is_callable([$storage, 'put_doc_metadata']) && is_callable([$storage, 'get_doc_metadata']));
    }

    /**
     * Fetch positive active document lengths for one language partition.
     *
     * Legacy backends ignore `$lang` and return aggregate lengths. New backends
     * receive canonicalized language tags. Results are normalized to positive
     * integers sorted by document id.
     *
     * @param int[] $docIds
     * @return array<int,int>
     */
    public static function get_doc_lengths(WP_FTS_Storage $storage, array $docIds, string $lang): array
    {
        $lengths = self::supports_language_docs($storage)
            ? $storage->get_doc_lengths($docIds, WP_FTS_TermNamespace::canonicalize_lang($lang))
            : $storage->get_doc_lengths($docIds);

        $normalized = [];
        foreach ($lengths as $docId => $length) {
            $length = max(0, (int) $length);
            if ($length > 0) {
                $normalized[(int) $docId] = $length;
            }
        }
        ksort($normalized, SORT_NUMERIC);

        return $normalized;
    }

    /**
     * Store document metadata through the backend's supported call shape.
     *
     * New backends receive primary language, normalized per-language lengths, and
     * hash. Legacy backends receive aggregate length and hash.
     *
     * @param array<string,int> $langLengths
     */
    public static function put_doc(WP_FTS_Storage $storage, int $docId, string $primaryLang, array $langLengths, string $hash): void
    {
        $langLengths = self::normalize_lang_lengths($langLengths);
        if (self::supports_language_docs($storage)) {
            $storage->put_doc($docId, WP_FTS_TermNamespace::canonicalize_lang($primaryLang), $langLengths, $hash);
            return;
        }

        $storage->put_doc($docId, array_sum($langLengths), $hash);
    }

    /**
     * Fetch BM25 collection metadata for one language partition.
     *
     * Legacy backends return aggregate metadata. Missing or negative values are
     * normalized to zero.
     *
     * @return array{doc_count:int,len_sum:int}
     */
    public static function get_meta(WP_FTS_Storage $storage, string $lang): array
    {
        $meta = self::supports_language_meta($storage)
            ? $storage->get_meta(WP_FTS_TermNamespace::canonicalize_lang($lang))
            : $storage->get_meta();

        return [
            'doc_count' => max(0, (int) ($meta['doc_count'] ?? 0)),
            'len_sum' => max(0, (int) ($meta['len_sum'] ?? 0)),
        ];
    }

    /**
     * Add signed metadata deltas using the backend's supported call shape.
     *
     * @param string $lang Language partition for new backends.
     * @param int $dDocs Signed document-count delta.
     * @param int $dLen Signed token-length delta.
     */
    public static function add_meta(WP_FTS_Storage $storage, string $lang, int $dDocs, int $dLen): void
    {
        if (self::supports_language_meta($storage)) {
            $storage->add_meta(WP_FTS_TermNamespace::canonicalize_lang($lang), $dDocs, $dLen);
            return;
        }

        $storage->add_meta($dDocs, $dLen);
    }

    /**
     * Store or explicitly clear document metadata when supported by the backend.
     *
     * @param array<string,mixed> $metadata
     */
    public static function put_doc_metadata(WP_FTS_Storage $storage, int $docId, array $metadata): void
    {
        if (!self::supports_doc_metadata($storage)) {
            return;
        }

        $storage->put_doc_metadata(
            $docId,
            $metadata === [] ? [] : self::normalize_doc_metadata($metadata)
        );
    }

    /**
     * Fetch normalized metadata for active documents.
     *
     * @param int[] $docIds
     * @return array<int,array<string,mixed>>
     */
    public static function get_doc_metadata(WP_FTS_Storage $storage, array $docIds): array
    {
        if (!self::supports_doc_metadata($storage)) {
            return [];
        }

        $rows = $storage->get_doc_metadata($docIds);
        $normalized = [];
        foreach ($rows as $docId => $metadata) {
            if (is_array($metadata)) {
                $normalized[(int) $docId] = self::normalize_doc_metadata($metadata);
            }
        }
        ksort($normalized, SORT_NUMERIC);

        return $normalized;
    }

    /**
     * Return active candidate ids that match product metadata filters.
     *
     * Backends with a scalar metadata-filter capability can answer this without
     * hydrating large snippet/result metadata. Legacy backends fall back to
     * normal metadata reads, preserving behavior.
     *
     * @param int[] $docIds
     * @param string[] $postTypes
     * @param string[] $postStatuses
     * @return int[] Sorted matching document ids.
     */
    public static function filter_doc_ids_by_metadata(
        WP_FTS_Storage $storage,
        array $docIds,
        array $postTypes = [],
        array $postStatuses = [],
        ?string $dateAfter = null,
        ?string $dateBefore = null
    ): array {
        $docIds = self::normalize_doc_ids($docIds);
        if ($docIds === []) {
            return [];
        }

        $postTypes = self::normalize_metadata_filter_values($postTypes);
        $postStatuses = self::normalize_metadata_filter_values($postStatuses);
        $dateAfter = self::normalize_metadata_filter_date($dateAfter, false);
        $dateBefore = self::normalize_metadata_filter_date($dateBefore, true);

        if ($storage instanceof WP_FTS_DocumentMetadataFilterStorage) {
            return self::normalize_doc_ids($storage->filter_doc_ids_by_metadata(
                $docIds,
                $postTypes,
                $postStatuses,
                $dateAfter,
                $dateBefore
            ));
        }

        $metadata = self::get_doc_metadata($storage, $docIds);
        $matches = [];
        foreach ($docIds as $docId) {
            if (self::metadata_matches_filters($metadata[$docId] ?? null, $postTypes, $postStatuses, $dateAfter, $dateBefore)) {
                $matches[] = $docId;
            }
        }

        return $matches;
    }

    /**
     * Check one metadata row against normalized scalar filters.
     *
     * @param array<string,mixed>|null $metadata
     * @param string[] $postTypes
     * @param string[] $postStatuses
     */
    public static function metadata_matches_filters(
        ?array $metadata,
        array $postTypes = [],
        array $postStatuses = [],
        ?string $dateAfter = null,
        ?string $dateBefore = null
    ): bool {
        if ($metadata === null) {
            return false;
        }

        if ($postTypes !== [] && !in_array((string) ($metadata['post_type'] ?? ''), $postTypes, true)) {
            return false;
        }

        if ($postStatuses !== [] && !in_array((string) ($metadata['post_status'] ?? ''), $postStatuses, true)) {
            return false;
        }

        $date = (string) ($metadata['post_date_gmt'] ?? '');
        if ($dateAfter !== null && ($date === '' || strcmp($date, $dateAfter) < 0)) {
            return false;
        }

        if ($dateBefore !== null && ($date === '' || strcmp($date, $dateBefore) > 0)) {
            return false;
        }

        return true;
    }

    /**
     * @param string[] $terms
     * @return string[]
     */
    private static function normalize_prefix_terms(array $terms, string $prefix, int $limit): array
    {
        $limit = max(1, (int) $limit);
        $matched = [];
        foreach (array_unique(array_map('strval', $terms)) as $term) {
            if (str_starts_with($term, $prefix)) {
                $matched[] = $term;
            }
        }
        sort($matched, SORT_STRING);

        return array_slice($matched, 0, $limit);
    }

    /**
     * Normalize comma-separated metadata filter values.
     *
     * @param mixed $value
     * @return string[]
     */
    public static function normalize_metadata_filter_values(mixed $value): array
    {
        $items = [];
        foreach (is_array($value) ? $value : [$value] as $item) {
            foreach (explode(',', (string) $item) as $part) {
                $part = trim($part);
                if ($part !== '') {
                    $items[$part] = true;
                }
            }
        }

        $result = array_keys($items);
        sort($result, SORT_STRING);

        return $result;
    }

    /**
     * Normalize date-only filters to lexicographic SQL datetime boundaries.
     */
    public static function normalize_metadata_filter_date(mixed $value, bool $endOfDay): ?string
    {
        if (!is_scalar($value) || trim((string) $value) === '') {
            return null;
        }

        $date = trim((string) $value);
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) === 1) {
            return $date . ($endOfDay ? ' 23:59:59' : ' 00:00:00');
        }

        return $date;
    }

    /**
     * Extract per-language lengths from a document row.
     *
     * New rows use `lang_lengths`. Older rows may expose `doc_lengths`,
     * `lengths`, or a single aggregate `doc_len`; aggregate lengths are assigned
     * to the document primary language.
     *
     * @param array<string,mixed>|null $doc
     * @return array<string,int>
     */
    public static function doc_lang_lengths(?array $doc, string $fallbackLang): array
    {
        if ($doc === null || (bool) ($doc['deleted'] ?? false)) {
            return [];
        }

        foreach (['lang_lengths', 'doc_lengths', 'lengths'] as $key) {
            if (isset($doc[$key]) && is_array($doc[$key])) {
                return self::normalize_lang_lengths($doc[$key]);
            }
        }

        $length = max(0, (int) ($doc['doc_len'] ?? 0));
        if ($length === 0) {
            return [];
        }

        return [self::doc_primary_lang($doc, $fallbackLang) => $length];
    }

    /**
     * Extract a document's primary language with a fallback.
     *
     * Accepts the current `primary_lang` key and older `lang`/`language` keys.
     *
     * @param array<string,mixed>|null $doc
     * @return string Canonical language.
     */
    public static function doc_primary_lang(?array $doc, string $fallbackLang): string
    {
        if ($doc !== null) {
            foreach (['primary_lang', 'lang', 'language'] as $key) {
                if (isset($doc[$key]) && trim((string) $doc[$key]) !== '') {
                    return WP_FTS_TermNamespace::canonicalize_lang((string) $doc[$key], $fallbackLang);
                }
            }
        }

        return WP_FTS_TermNamespace::canonicalize_lang($fallbackLang);
    }

    /**
     * Canonicalize, merge, drop zero, and sort language lengths.
     *
     * @param array<string|int,mixed> $langLengths
     * @return array<string,int>
     */
    public static function normalize_lang_lengths(array $langLengths): array
    {
        $normalized = [];
        foreach ($langLengths as $lang => $length) {
            $length = max(0, (int) $length);
            if ($length === 0) {
                continue;
            }

            $canonicalLang = WP_FTS_TermNamespace::canonicalize_lang((string) $lang);
            $normalized[$canonicalLang] = ($normalized[$canonicalLang] ?? 0) + $length;
        }
        ksort($normalized, SORT_STRING);

        return $normalized;
    }

    /**
     * Normalize product metadata used by storage filters, snippets, and CLI rows.
     *
     * @param array<string,mixed> $metadata
     * @return array<string,mixed>
     */
    public static function normalize_doc_metadata(array $metadata): array
    {
        if (count($metadata) > self::METADATA_MAX_MAP_KEYS) {
            self::throw_metadata_limit('metadata_keys', 'FTS metadata contains more than 32 top-level keys.');
        }
        $inputNodes = 0;
        $inputBytes = 0;
        self::assert_metadata_input_bounds($metadata, 0, $inputNodes, $inputBytes);
        $rawPostId = $metadata['post_id'] ?? 0;
        if (!is_scalar($rawPostId) || (is_string($rawPostId) && strlen($rawPostId) > self::METADATA_MAX_NUMERIC_BYTES)) {
            self::throw_metadata_limit('metadata_shape', 'FTS metadata post ids must be bounded scalar values.');
        }
        $normalized = [
            'post_id' => max(0, (int) $rawPostId),
            'post_type' => self::metadata_text($metadata['post_type'] ?? ''),
            'post_status' => self::metadata_text($metadata['post_status'] ?? ''),
            'post_date_gmt' => self::metadata_text($metadata['post_date_gmt'] ?? ''),
            'title' => self::metadata_text($metadata['title'] ?? ''),
            'excerpt' => self::metadata_text($metadata['excerpt'] ?? ''),
            'search_text' => self::metadata_text($metadata['search_text'] ?? ''),
            'terms' => self::metadata_string_lists($metadata['terms'] ?? []),
            'custom_fields' => self::metadata_string_lists($metadata['custom_fields'] ?? []),
            'field_boosts' => [],
            'search_fields' => self::metadata_search_fields($metadata['search_fields'] ?? []),
        ];

        $fieldBoosts = is_array($metadata['field_boosts'] ?? null) ? $metadata['field_boosts'] : [];
        if (count($fieldBoosts) > self::METADATA_MAX_MAP_KEYS) {
            self::throw_metadata_limit('structured_value_nodes', 'FTS metadata contains more than 32 field boosts.');
        }
        foreach ($fieldBoosts as $field => $boost) {
            $field = (string) $field;
            if (strlen($field) > self::METADATA_MAX_KEY_BYTES) {
                self::throw_metadata_limit('structured_key_bytes', 'An FTS metadata field-boost key exceeds 191 bytes.');
            }
            if (is_string($boost) && strlen($boost) > self::METADATA_MAX_NUMERIC_BYTES) {
                self::throw_metadata_limit('metadata_shape', 'FTS metadata field boosts must be bounded numeric values.');
            }
            if (is_numeric($boost)) {
                $normalized['field_boosts'][$field] = max(0.01, min(100.0, (float) $boost));
            }
        }
        ksort($normalized['field_boosts'], SORT_STRING);

        $extraNodes = 0;
        $extraTextBytes = 0;
        foreach ($metadata as $key => $value) {
            if (strlen((string) $key) > self::METADATA_MAX_KEY_BYTES) {
                self::throw_metadata_limit('structured_key_bytes', 'An FTS metadata key exceeds 191 bytes.');
            }
            $metadataKey = WP_FTS_Utf8::repair((string) $key);
            if ($metadataKey !== '' && !array_key_exists($metadataKey, $normalized)) {
                $extraTextBytes += strlen($metadataKey);
                if ($extraTextBytes > self::METADATA_MAX_TEXT_BYTES) {
                    self::throw_metadata_limit('structured_text_bytes', 'FTS metadata exceeds the 256 KiB text limit.');
                }
                $normalized[$metadataKey] = self::metadata_extra($value, 0, $extraNodes, $extraTextBytes);
            }
        }

        return $normalized;
    }

    /** Bound the complete raw metadata graph before UTF-8 repair or HTML parsing. */
    private static function assert_metadata_input_bounds(
        mixed $value,
        int $depth,
        int &$nodes,
        int &$textBytes
    ): void {
        if (++$nodes > self::METADATA_MAX_NODES) {
            self::throw_metadata_limit('structured_value_nodes', 'FTS metadata exceeds the 2,048-node limit.');
        }
        if (is_string($value)) {
            $textBytes += strlen($value);
            if ($textBytes > self::METADATA_MAX_TEXT_BYTES) {
                self::throw_metadata_limit('structured_source_bytes', 'FTS metadata exceeds the 256 KiB source-text limit.');
            }

            return;
        }
        if (is_scalar($value) || $value === null) {
            return;
        }
        if (is_object($value)) {
            if ($depth >= self::METADATA_MAX_DEPTH) {
                self::throw_metadata_limit('structured_value_depth', 'FTS metadata exceeds the 16-level nesting limit.');
            }
            self::assert_metadata_input_bounds(get_object_vars($value), $depth + 1, $nodes, $textBytes);

            return;
        }
        if (!is_array($value)) {
            return;
        }
        if ($depth >= self::METADATA_MAX_DEPTH) {
            self::throw_metadata_limit('structured_value_depth', 'FTS metadata exceeds the 16-level nesting limit.');
        }
        if (count($value) > self::METADATA_MAX_NODES) {
            self::throw_metadata_limit('structured_value_nodes', 'FTS metadata exceeds the 2,048-node limit.');
        }
        foreach ($value as $key => $item) {
            $key = (string) $key;
            if (strlen($key) > self::METADATA_MAX_KEY_BYTES) {
                self::throw_metadata_limit('structured_key_bytes', 'An FTS metadata key exceeds 191 bytes.');
            }
            $textBytes += strlen($key);
            if ($textBytes > self::METADATA_MAX_TEXT_BYTES) {
                self::throw_metadata_limit('structured_source_bytes', 'FTS metadata exceeds the 256 KiB source-text limit.');
            }
            self::assert_metadata_input_bounds($item, $depth + 1, $nodes, $textBytes);
        }
    }

    /**
     * Normalize bounded per-field explain sources.
     *
     * @param mixed $fields
     * @return array<int,array{name:string,text:string,boost:float,html?:string}>
     */
    private static function metadata_search_fields(mixed $fields): array
    {
        if (is_array($fields) && count($fields) > self::METADATA_SEARCH_FIELD_MAX_FIELDS) {
            self::throw_metadata_limit('structured_value_nodes', 'FTS metadata contains more than 32 search fields.');
        }
        $normalized = [];
        foreach (is_array($fields) ? $fields : [] as $field) {
            if (!is_array($field)) {
                continue;
            }

            $rawName = $field['name'] ?? '';
            if (!is_scalar($rawName) || strlen((string) $rawName) > self::METADATA_MAX_KEY_BYTES) {
                self::throw_metadata_limit('structured_key_bytes', 'An FTS metadata search-field name exceeds 191 bytes.');
            }
            $name = self::metadata_text($rawName);
            $text = rtrim(WP_FTS_Utf8::truncate_bytes(
                self::metadata_text($field['text'] ?? ''),
                self::METADATA_SEARCH_FIELD_TEXT_BYTES
            ));
            $html = is_scalar($field['html'] ?? null)
                ? rtrim(WP_FTS_Utf8::truncate_bytes(WP_FTS_Utf8::repair((string) $field['html']), self::METADATA_SEARCH_FIELD_HTML_BYTES))
                : '';
            if ($name === '' || ($text === '' && trim($html) === '')) {
                continue;
            }

            $rawBoost = $field['boost'] ?? 1.0;
            if (!is_scalar($rawBoost) || (is_string($rawBoost) && strlen($rawBoost) > self::METADATA_MAX_NUMERIC_BYTES)) {
                self::throw_metadata_limit('metadata_shape', 'FTS metadata search-field boosts must be bounded scalar values.');
            }
            $row = [
                'name' => $name,
                'text' => $text,
                'boost' => is_numeric($rawBoost)
                    ? max(0.01, min(100.0, (float) $rawBoost))
                    : 1.0,
            ];
            if (trim($html) !== '') {
                $row['html'] = $html;
            }
            $normalized[] = $row;
            if (count($normalized) >= self::METADATA_SEARCH_FIELD_MAX_FIELDS) {
                break;
            }
        }

        return $normalized;
    }

    /**
     * Normalize a scalar metadata string.
     */
    private static function metadata_text(mixed $value): string
    {
        if (!is_scalar($value)) {
            return '';
        }

        $text = (string) $value;
        WP_FTS_Analysis_Limits::assert_source_bytes($text);

        return WP_FTS_Html_Text_Stream::visible_text($text);
    }

    /**
     * Normalize taxonomy/custom-field metadata maps.
     *
     * @param mixed $lists
     * @return array<string,string[]>
     */
    private static function metadata_string_lists(mixed $lists): array
    {
        if (is_array($lists) && count($lists) > self::METADATA_MAX_MAP_KEYS) {
            self::throw_metadata_limit('structured_value_nodes', 'FTS metadata contains more than 32 structured lists.');
        }
        $normalized = [];
        $nodes = 0;
        $textBytes = 0;
        foreach (is_array($lists) ? $lists : [] as $key => $values) {
            if (++$nodes > self::METADATA_MAX_NODES) {
                self::throw_metadata_limit('structured_value_nodes', 'FTS metadata exceeds the 2,048-node limit.');
            }
            if (strlen((string) $key) > self::METADATA_MAX_KEY_BYTES) {
                self::throw_metadata_limit('structured_key_bytes', 'An FTS structured metadata key exceeds 191 bytes.');
            }
            $key = trim(WP_FTS_Utf8::repair((string) $key));
            if ($key === '') {
                continue;
            }
            $textBytes += strlen($key);

            $items = [];
            $values = is_array($values) ? $values : [$values];
            if (count($values) > self::METADATA_MAX_NODES) {
                self::throw_metadata_limit('structured_value_nodes', 'FTS metadata exceeds the 2,048-node limit.');
            }
            foreach ($values as $value) {
                if (++$nodes > self::METADATA_MAX_NODES) {
                    self::throw_metadata_limit('structured_value_nodes', 'FTS metadata exceeds the 2,048-node limit.');
                }
                if (is_scalar($value)) {
                    $text = self::metadata_text($value);
                    if ($text !== '') {
                        $textBytes += strlen($text);
                        if ($textBytes > self::METADATA_MAX_TEXT_BYTES) {
                            self::throw_metadata_limit('structured_text_bytes', 'FTS metadata exceeds the 256 KiB text limit.');
                        }
                        $items[$text] = true;
                    }
                }
            }
            if ($items !== []) {
                $normalized[$key] = array_keys($items);
                sort($normalized[$key], SORT_STRING);
            }
        }
        ksort($normalized, SORT_STRING);

        return $normalized;
    }

    /**
     * Preserve only JSON-serializable metadata extras.
     */
    private static function metadata_extra(mixed $value, int $depth, int &$nodes, int &$textBytes): mixed
    {
        if (++$nodes > self::METADATA_MAX_NODES) {
            self::throw_metadata_limit('structured_value_nodes', 'FTS metadata exceeds the 2,048-node limit.');
        }
        if (is_string($value)) {
            $value = WP_FTS_Utf8::repair($value);
            $textBytes += strlen($value);
            if ($textBytes > self::METADATA_MAX_TEXT_BYTES) {
                self::throw_metadata_limit('structured_text_bytes', 'FTS metadata exceeds the 256 KiB text limit.');
            }

            return $value;
        }

        if (is_scalar($value) || $value === null) {
            return $value;
        }

        if (!is_array($value)) {
            $properties = is_object($value) ? get_object_vars($value) : [];
            return self::metadata_extra($properties['name'] ?? $properties['value'] ?? '', $depth, $nodes, $textBytes);
        }
        if ($depth >= self::METADATA_MAX_DEPTH) {
            self::throw_metadata_limit('structured_value_depth', 'FTS metadata exceeds the 16-level nesting limit.');
        }
        if (count($value) > self::METADATA_MAX_NODES) {
            self::throw_metadata_limit('structured_value_nodes', 'FTS metadata exceeds the 2,048-node limit.');
        }

        $normalized = [];
        foreach ($value as $key => $item) {
            $normalizedKey = WP_FTS_Utf8::repair((string) $key);
            $textBytes += strlen($normalizedKey);
            if ($textBytes > self::METADATA_MAX_TEXT_BYTES) {
                self::throw_metadata_limit('structured_text_bytes', 'FTS metadata exceeds the 256 KiB text limit.');
            }
            $normalized[$normalizedKey] = self::metadata_extra($item, $depth + 1, $nodes, $textBytes);
        }

        return $normalized;
    }

    /** Report metadata amplification through the shared analysis-limit channel. */
    private static function throw_metadata_limit(string $reason, string $message): never
    {
        throw new WP_FTS_Analysis_Limit_Exceeded($reason, $message);
    }

    /**
     * @param string[] $terms
     * @return string[]
     */
    private static function normalize_doc_terms(array $terms, int $limit): array
    {
        $normalized = array_values(array_unique(array_map('strval', $terms)));
        sort($normalized, SORT_STRING);

        return $limit > 0 ? array_slice($normalized, 0, $limit) : $normalized;
    }

    /**
     * Normalize non-negative document ids.
     *
     * @param int[] $docIds
     * @return int[]
     */
    private static function normalize_doc_ids(array $docIds): array
    {
        $normalized = [];
        foreach ($docIds as $docId) {
            $docId = (int) $docId;
            if ($docId >= 0) {
                $normalized[$docId] = true;
            }
        }

        $result = array_keys($normalized);
        sort($result, SORT_NUMERIC);

        return $result;
    }

    /**
     * Count declared parameters for compatibility feature detection.
     *
     * Reflection failures are treated as unsupported legacy behavior rather than
     * fatal errors.
     */
    private static function method_parameter_count(WP_FTS_Storage $storage, string $method): int
    {
        try {
            return (new ReflectionMethod($storage, $method))->getNumberOfParameters();
        } catch (ReflectionException) {
            return 0;
        }
    }
}
