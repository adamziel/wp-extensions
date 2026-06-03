<?php
declare(strict_types=1);

final class WP_FTS_StorageCompat
{
    public static function supports_language_docs(WP_FTS_Storage $storage): bool
    {
        return self::method_parameter_count($storage, 'put_doc') >= 4
            && self::method_parameter_count($storage, 'get_doc_lengths') >= 2;
    }

    public static function supports_language_meta(WP_FTS_Storage $storage): bool
    {
        return self::method_parameter_count($storage, 'add_meta') >= 3
            && self::method_parameter_count($storage, 'get_meta') >= 1;
    }

    /**
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

    public static function add_meta(WP_FTS_Storage $storage, string $lang, int $dDocs, int $dLen): void
    {
        if (self::supports_language_meta($storage)) {
            $storage->add_meta(WP_FTS_TermNamespace::canonicalize_lang($lang), $dDocs, $dLen);
            return;
        }

        $storage->add_meta($dDocs, $dLen);
    }

    /**
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
     * @param array<string,mixed>|null $doc
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

    private static function method_parameter_count(WP_FTS_Storage $storage, string $method): int
    {
        try {
            return (new ReflectionMethod($storage, $method))->getNumberOfParameters();
        } catch (ReflectionException) {
            return 0;
        }
    }
}
