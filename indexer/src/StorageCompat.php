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
    /**
     * Replace one document's postings through a row-postings backend when possible.
     *
     * @param array<string,int> $termFrequencies Stored term key => weighted tf.
     * @return bool True when the storage handled the replacement natively.
     */
    public static function replace_doc_postings(WP_FTS_Storage $storage, int $docId, array $termFrequencies): bool
    {
        if (!$storage instanceof WP_FTS_Row_Postings_Storage) {
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
     * @return array<string,array<int,int>>
     */
    public static function get_postings(WP_FTS_Storage $storage, array $terms): array
    {
        $terms = array_values(array_unique(array_map('strval', $terms)));
        if ($terms === []) {
            return [];
        }

        if ($storage instanceof WP_FTS_Row_Postings_Storage) {
            return $storage->get_postings($terms);
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

        return $postingsByTerm;
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
     * Store document metadata when the backend supports the optional capability.
     *
     * @param array<string,mixed> $metadata
     */
    public static function put_doc_metadata(WP_FTS_Storage $storage, int $docId, array $metadata): void
    {
        if (!self::supports_doc_metadata($storage)) {
            return;
        }

        $storage->put_doc_metadata($docId, self::normalize_doc_metadata($metadata));
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
        $normalized = [
            'post_id' => max(0, (int) ($metadata['post_id'] ?? 0)),
            'post_type' => self::metadata_text($metadata['post_type'] ?? ''),
            'post_status' => self::metadata_text($metadata['post_status'] ?? ''),
            'post_date_gmt' => self::metadata_text($metadata['post_date_gmt'] ?? ''),
            'title' => self::metadata_text($metadata['title'] ?? ''),
            'excerpt' => self::metadata_text($metadata['excerpt'] ?? ''),
            'search_text' => self::metadata_text($metadata['search_text'] ?? ''),
            'terms' => self::metadata_string_lists($metadata['terms'] ?? []),
            'custom_fields' => self::metadata_string_lists($metadata['custom_fields'] ?? []),
            'field_boosts' => [],
        ];

        foreach (($metadata['field_boosts'] ?? []) as $field => $boost) {
            if (is_scalar($field) && is_numeric($boost)) {
                $normalized['field_boosts'][(string) $field] = max(0.01, min(100.0, (float) $boost));
            }
        }
        ksort($normalized['field_boosts'], SORT_STRING);

        foreach ($metadata as $key => $value) {
            $metadataKey = WP_FTS_Utf8::repair((string) $key);
            if ($metadataKey !== '' && !array_key_exists($metadataKey, $normalized)) {
                $normalized[$metadataKey] = self::metadata_extra($value);
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
        $text = WP_FTS_Utf8::repair($text);
        $text = strip_tags($text);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
        $text = WP_FTS_Utf8::repair($text);
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;

        return trim($text);
    }

    /**
     * Normalize taxonomy/custom-field metadata maps.
     *
     * @param mixed $lists
     * @return array<string,string[]>
     */
    private static function metadata_string_lists(mixed $lists): array
    {
        $normalized = [];
        foreach (is_array($lists) ? $lists : [] as $key => $values) {
            $key = trim(WP_FTS_Utf8::repair((string) $key));
            if ($key === '') {
                continue;
            }

            $items = [];
            foreach (is_array($values) ? $values : [$values] as $value) {
                if (is_scalar($value)) {
                    $text = self::metadata_text($value);
                    if ($text !== '') {
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
    private static function metadata_extra(mixed $value): mixed
    {
        if (is_string($value)) {
            return WP_FTS_Utf8::repair($value);
        }

        if (is_scalar($value) || $value === null) {
            return $value;
        }

        if (!is_array($value)) {
            return self::metadata_text((string) ($value->name ?? $value->value ?? ''));
        }

        $normalized = [];
        foreach ($value as $key => $item) {
            $normalized[WP_FTS_Utf8::repair((string) $key)] = self::metadata_extra($item);
        }

        return $normalized;
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
