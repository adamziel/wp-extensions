<?php
declare(strict_types=1);

final class WP_FTS_Language
{
    public const DEFAULT_LANG = 'und';
    public const TERM_SEPARATOR = "\x1e";
    public const MAX_TERM_KEY_BYTES = 255;

    public static function canonicalize(null|string|int|float $lang): string
    {
        $value = trim((string) $lang);
        if ($value === '') {
            return self::DEFAULT_LANG;
        }

        $value = str_replace('_', '-', $value);
        $parts = array_values(array_filter(
            explode('-', $value),
            static fn(string $part): bool => $part !== ''
        ));
        if ($parts === []) {
            return self::DEFAULT_LANG;
        }

        $canonical = [];
        foreach ($parts as $index => $part) {
            $part = preg_replace('/[^A-Za-z0-9]/', '', $part) ?? '';
            if ($part === '') {
                continue;
            }

            if ($index === 0) {
                $canonical[] = strtolower($part);
                continue;
            }

            if (strlen($part) === 4 && ctype_alpha($part)) {
                $canonical[] = ucfirst(strtolower($part));
                continue;
            }

            if ((strlen($part) === 2 && ctype_alpha($part)) || (strlen($part) === 3 && ctype_digit($part))) {
                $canonical[] = strtoupper($part);
                continue;
            }

            $canonical[] = strtolower($part);
        }

        return $canonical === [] ? self::DEFAULT_LANG : implode('-', $canonical);
    }

    public static function term_key(string $term, null|string|int|float $lang): string
    {
        return self::canonicalize($lang) . self::TERM_SEPARATOR . $term;
    }

    public static function term_key_fits(string $term, null|string|int|float $lang): bool
    {
        return strlen(self::term_key($term, $lang)) <= self::MAX_TERM_KEY_BYTES;
    }

    /**
     * @param array<string|int,int|float> $lengths
     * @return array<string,int>
     */
    public static function normalize_lengths(array $lengths): array
    {
        $normalized = [];
        foreach ($lengths as $lang => $length) {
            $lang = self::canonicalize((string) $lang);
            $length = max(0, (int) $length);
            if ($length === 0) {
                continue;
            }
            $normalized[$lang] = ($normalized[$lang] ?? 0) + $length;
        }
        ksort($normalized, SORT_STRING);

        return $normalized;
    }
}
