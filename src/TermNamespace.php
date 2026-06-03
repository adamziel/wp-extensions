<?php
declare(strict_types=1);

final class WP_FTS_TermNamespace
{
    public const SEPARATOR = "\x1e";

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
            } elseif (strlen($part) === 4 && ctype_alpha($part)) {
                $canonical[] = ucfirst(strtolower($part));
            } elseif ((strlen($part) === 2 && ctype_alpha($part)) || (strlen($part) === 3 && ctype_digit($part))) {
                $canonical[] = strtoupper($part);
            } else {
                $canonical[] = strtolower($part);
            }
        }

        return $canonical !== [] ? implode('-', $canonical) : self::canonicalize_lang($fallback, 'en');
    }

    public static function namespace_term(string $lang, string $term): string
    {
        return self::canonicalize_lang($lang) . self::SEPARATOR . $term;
    }

    /**
     * @return array{lang:string,term:string}|null
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
     * @param string[] $keys
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

    public static function default_language(array $opts = []): string
    {
        $configured = self::language_from_options($opts, null, ['default_lang', 'locale']);
        if ($configured !== null) {
            return $configured;
        }

        if (function_exists('get_locale')) {
            $locale = get_locale();
            if (is_string($locale) && trim($locale) !== '') {
                return self::canonicalize_lang($locale);
            }
        }

        if (function_exists('get_bloginfo')) {
            $language = get_bloginfo('language');
            if (is_string($language) && trim($language) !== '') {
                return self::canonicalize_lang($language);
            }
        }

        return 'en';
    }
}
