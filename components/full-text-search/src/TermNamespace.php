<?php
declare(strict_types=1);

/**
 * Builds and reads the language-prefixed term keys stored in postings.
 *
 * The index stores every lexical term under a language partition so the same
 * spelling in different languages can score against different document-length
 * statistics. The key format is `lang . "\\x1e" . term`, for example
 * `en\\x1ecolor`.
 */
final class WP_FTS_TermNamespace
{
    public const DEFAULT_LANG = 'und';
    public const MAX_TERM_KEY_BYTES = 255;
    public const SEPARATOR = "\x1e";

    /**
     * Normalize a user, locale, or storage language value into a stable tag.
     *
     * Empty values fall back to `$fallback`. The method accepts WordPress
     * locale-style underscores and strips punctuation inside subtags, but it
     * does not validate against a registry of real BCP 47 tags.
     *
     * @param string|null $lang Language candidate such as `en_US`, `pl`, or
     *        `zh-Hant`. Null and blank strings use `$fallback`.
     * @param string $fallback Language to use when `$lang` does not contain a
     *        usable primary subtag.
     * @return string Canonicalized tag with lower-case primary language,
     *        title-case script, and upper-case region where recognizable.
     */
    public static function canonicalize_lang(?string $lang, string $fallback = 'en'): string
    {
        $lang = trim((string) $lang);
        if ($lang === '') {
            $lang = $fallback;
        }

        $lang = str_replace('_', '-', trim($lang));
        $parts = preg_split('/-+/', $lang) ?: [];
        $parts = array_values(array_filter($parts, static fn(string $part): bool => $part !== ''));
        if ($parts === []) {
            return self::canonicalize_lang($fallback, 'en');
        }

        $canonical = [];
        foreach ($parts as $i => $part) {
            $part = preg_replace('/[^A-Za-z0-9]/', '', $part) ?? '';
            if ($part === '') {
                continue;
            }

            if ($i === 0) {
                $canonical[] = strtolower($part);
            } elseif (strlen($part) === 4 && self::is_ascii_alpha($part)) {
                $canonical[] = ucfirst(strtolower($part));
            } elseif ((strlen($part) === 2 && self::is_ascii_alpha($part)) || (strlen($part) === 3 && self::is_ascii_digit($part))) {
                $canonical[] = strtoupper($part);
            } else {
                $canonical[] = strtolower($part);
            }
        }

        return $canonical !== [] ? implode('-', $canonical) : self::canonicalize_lang($fallback, 'en');
    }

    /**
     * Check whether a language subtag contains only ASCII letters.
     */
    private static function is_ascii_alpha(string $value): bool
    {
        return $value !== '' && preg_match('/^[A-Za-z]+$/', $value) === 1;
    }

    /**
     * Check whether a language subtag contains only ASCII digits.
     */
    private static function is_ascii_digit(string $value): bool
    {
        return $value !== '' && preg_match('/^[0-9]+$/', $value) === 1;
    }

    /**
     * Prefix a normalized term with its canonical language partition.
     *
     * Call this before reading or writing postings. `$term` should already be
     * normalized by the analyzer; this helper only canonicalizes `$lang` and
     * inserts the `\\x1e` separator.
     *
     * @param string $lang Language partition for BM25 statistics.
     * @param string $term Normalized lexical term without a namespace prefix.
     * @return string Namespaced key, for example `de\\x1ekind`.
     */
    public static function namespace_term(string $lang, string $term): string
    {
        return self::canonicalize_lang($lang) . self::SEPARATOR . $term;
    }

    /**
     * Backward-compatible alias for `namespace_term()`.
     *
     * Older callers pass term first and language second. New code should prefer
     * `namespace_term()` because its argument order mirrors the stored key.
     *
     * @param string $term Normalized lexical term without namespace.
     * @param string $lang Language partition.
     * @return string Namespaced term key.
     */
    public static function term_key(string $term, string $lang): string
    {
        return self::namespace_term($lang, $term);
    }

    /**
     * Report whether a namespaced term fits the MySQL varbinary key.
     *
     * Use this before persisting custom analyzer output to MySQL. The check is
     * byte-oriented because the storage key is `varbinary(255)`.
     *
     * @param string $term Normalized lexical term.
     * @param string $lang Language partition.
     * @return bool True when `lang . "\\x1e" . term` is at most 255 bytes.
     */
    public static function term_key_fits(string $term, string $lang): bool
    {
        return strlen(self::namespace_term($lang, $term)) <= self::MAX_TERM_KEY_BYTES;
    }

    /**
     * Split a stored term key into language and lexical term parts.
     *
     * The separator must appear after at least one language byte. Unnamespaced
     * legacy terms return null so callers can decide which fallback language to
     * use.
     *
     * @param string $term Stored key, usually `lang . "\\x1e" . term`.
     * @return array{lang:string,term:string}|null Parsed namespace, or null for
     *         legacy/invalid keys.
     */
    public static function split_term(string $term): ?array
    {
        $pos = strpos($term, self::SEPARATOR);
        if ($pos === false || $pos === 0) {
            return null;
        }

        return [
            'lang' => self::canonicalize_lang(substr($term, 0, $pos)),
            'term' => substr($term, $pos + strlen(self::SEPARATOR)),
        ];
    }

    /**
     * Resolve the first usable language value from an options array.
     *
     * `$keys` is ordered by the caller's precedence. For example the indexer
     * asks for `lang`, `language`, `primary_lang`, `document_lang`, then
     * `locale`, while search prefers `query_lang` first.
     *
     * @param array<string,mixed> $opts Options from public APIs or WP-CLI.
     * @param string|null $fallback Optional fallback returned when none of the
     *        named keys contains a scalar non-empty value.
     * @param string[] $keys Option names to inspect in priority order.
     * @return string|null Canonical language, fallback language, or null when
     *         no language could be resolved.
     */
    public static function language_from_options(array $opts, ?string $fallback = null, array $keys = ['lang', 'language', 'primary_lang', 'query_lang', 'default_lang', 'locale']): ?string
    {
        foreach ($keys as $key) {
            if (!isset($opts[$key])) {
                continue;
            }

            $value = trim((string) $opts[$key]);
            if ($value !== '') {
                return self::canonicalize_lang($value, $fallback ?? 'en');
            }
        }

        return $fallback !== null ? self::canonicalize_lang($fallback) : null;
    }

    /**
     * Resolve the default language for analysis when a document/query is silent.
     *
     * Explicit `default_lang` or `locale` options win. Otherwise the reusable
     * library returns `en`; framework adapters should pass their site or
     * application language explicitly.
     *
     * @param array<string,mixed> $opts Optional caller-supplied language hints.
     * @return string Canonical default language.
     */
    public static function default_language(array $opts = []): string
    {
        $configured = self::language_from_options($opts, null, ['default_lang', 'locale']);
        if ($configured !== null) {
            return $configured;
        }

        return 'en';
    }

    /**
     * Canonicalize and aggregate per-language document lengths.
     *
     * Non-positive lengths are dropped. Duplicate language spellings such as
     * `en_US` and `en-US` are merged after canonicalization.
     *
     * @param array<string|int,mixed> $lengths Map of language to token count.
     * @return array<string,int> Sorted canonical language to positive length.
     */
    public static function normalize_lengths(array $lengths): array
    {
        $normalized = [];
        foreach ($lengths as $lang => $length) {
            $length = max(0, (int) $length);
            if ($length === 0) {
                continue;
            }

            $canonicalLang = self::canonicalize_lang((string) $lang, self::DEFAULT_LANG);
            $normalized[$canonicalLang] = ($normalized[$canonicalLang] ?? 0) + $length;
        }
        ksort($normalized, SORT_STRING);

        return $normalized;
    }
}
