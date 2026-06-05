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
