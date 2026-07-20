<?php
declare(strict_types=1);

/**
 * Builds and reads language-prefixed relational dictionary keys.
 *
 * The index stores every lexical term under a language partition so the same
 * spelling in different languages remains a distinct analyzer and ranking
 * identity. The key format is `lang . "\\x1e" . term`, for example
 * `en\\x1ecolor`.
 */
final class WP_FTS_TermNamespace
{
    public const DEFAULT_LANG = 'und';
    public const MAX_TERM_KEY_BYTES = 255;
    public const SEPARATOR = "\x1e";
    private const MAX_LANGUAGE_TAG_BYTES = 64;
    private const MAX_LANGUAGE_PARTS = 8;

    /**
     * Parse one language tag supplied at a public API boundary.
     *
     * WordPress-style underscores are accepted as hyphens. The primary
     * language must contain two or three ASCII letters; each later subtag must
     * contain two through eight ASCII letters or digits. At most eight parts
     * are accepted in total.
     *
     * @throws InvalidArgumentException For values outside the public contract.
     */
    public static function parse_language_tag(mixed $lang): string
    {
        if (
            !is_string($lang)
            || $lang === ''
            || trim($lang) !== $lang
            || strlen($lang) > self::MAX_LANGUAGE_TAG_BYTES
        ) {
            throw new InvalidArgumentException('Language tags must be unpadded nonempty strings of at most 64 bytes.');
        }

        $parts = explode('-', str_replace('_', '-', $lang));
        if (count($parts) > self::MAX_LANGUAGE_PARTS) {
            throw new InvalidArgumentException('Language tags may contain at most eight parts.');
        }

        $primary = $parts[0];
        if (strlen($primary) < 2 || strlen($primary) > 3 || !self::is_ascii_alpha($primary)) {
            throw new InvalidArgumentException('Language tag primary subtags must contain two or three ASCII letters.');
        }
        foreach (array_slice($parts, 1) as $part) {
            if (strlen($part) < 2 || strlen($part) > 8 || !self::is_ascii_alphanumeric($part)) {
                throw new InvalidArgumentException('Language tag subtags must contain two through eight ASCII letters or digits.');
            }
        }

        return self::canonicalize_lang(implode('-', $parts), self::DEFAULT_LANG);
    }

    /**
     * Normalize a trusted locale, parsed markup, or storage value.
     *
     * Empty values fall back to `$fallback`. This deliberately lenient path
     * accepts WordPress locale-style underscores and strips punctuation inside
     * subtags. Public caller input must use `parse_language_tag()` instead.
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

        if (($canonical[0] ?? null) === 'zh') {
            return self::canonicalize_chinese($canonical);
        }

        return $canonical !== [] ? implode('-', $canonical) : self::canonicalize_lang($fallback, 'en');
    }

    /**
     * Canonicalize Chinese language tags to their analyzer partition.
     *
     * Script subtags win over regions. Regions using Simplified Chinese share
     * `zh-Hans`; regions using Traditional Chinese share `zh-Hant`. A tag with
     * neither hint remains in the generic `zh` partition.
     *
     * @param string[] $parts Canonical language subtags.
     * @return string `zh`, `zh-Hans`, or `zh-Hant`.
     */
    private static function canonicalize_chinese(array $parts): string
    {
        $subtags = array_map('strtolower', array_slice($parts, 1));
        foreach ($subtags as $subtag) {
            if ($subtag === 'hans') {
                return 'zh-Hans';
            }
            if ($subtag === 'hant') {
                return 'zh-Hant';
            }
        }

        foreach ($subtags as $subtag) {
            if (in_array($subtag, ['cn', 'sg'], true)) {
                return 'zh-Hans';
            }
            if (in_array($subtag, ['tw', 'hk', 'mo'], true)) {
                return 'zh-Hant';
            }
        }

        return 'zh';
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

    /** Check whether a language subtag contains only ASCII letters or digits. */
    private static function is_ascii_alphanumeric(string $value): bool
    {
        return $value !== ''
            && strspn($value, 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789') === strlen($value);
    }

    /**
     * Prefix a normalized term with its canonical language partition.
     *
     * Call this before relational dictionary resolution or persistence.
     * `$term` should already be normalized by the analyzer; this helper only
     * canonicalizes `$lang` and inserts the `\\x1e` separator.
     *
     * @param string $lang Language partition for the analyzed term.
     * @param string $term Normalized lexical term without a namespace prefix.
     * @return string Namespaced key, for example `de\\x1ekind`.
     */
    public static function namespace_term(string $lang, string $term): string
    {
        return self::canonicalize_lang($lang) . self::SEPARATOR . $term;
    }

    /**
     * Report whether a namespaced term fits the relational dictionary key.
     *
     * Use this before persisting custom analyzer output. The check is
     * byte-oriented because the storage contract permits 255-byte keys.
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
     * or invalid terms return null so callers can decide how to reject them.
     *
     * @param string $term Stored key, usually `lang . "\\x1e" . term`.
     * @return array{lang:string,term:string}|null Parsed namespace, or null for
     *         invalid keys.
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
     * `$keys` is ordered by the caller's precedence.
     *
     * @param array<string,mixed> $opts Options from public APIs or WP-CLI.
     * @param string|null $fallback Optional fallback returned when none of the
     *        named keys is present.
     * @param string[] $keys Option names to inspect in priority order.
     * @return string|null Canonical language, fallback language, or null when
     *         no language could be resolved.
     */
    public static function language_from_options(array $opts, ?string $fallback, array $keys): ?string
    {
        foreach ($keys as $key) {
            if (!array_key_exists($key, $opts)) {
                continue;
            }

            return self::parse_language_tag($opts[$key]);
        }

        return $fallback !== null ? self::parse_language_tag($fallback) : null;
    }

    /**
     * Resolve the default language for analysis when a document/query is silent.
     *
     * An explicit `default_lang` option wins. Otherwise the reusable library
     * returns `en`; framework adapters should pass their site or application
     * language explicitly.
     *
     * @param array<string,mixed> $opts Optional caller-supplied language hints.
     * @return string Canonical default language.
     */
    public static function default_language(array $opts = []): string
    {
        $configured = self::language_from_options($opts, null, ['default_lang']);
        if ($configured !== null) {
            return $configured;
        }

        return 'en';
    }

}
