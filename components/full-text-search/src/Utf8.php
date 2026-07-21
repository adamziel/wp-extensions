<?php
declare(strict_types=1);

/**
 * Small UTF-8 safety helpers for metadata that must survive JSON encoding.
 */
final class WP_FTS_Utf8
{
    /**
     * Count Unicode code points without depending on optional extensions.
     */
    public static function length(string $text): int
    {
        if ($text === '') {
            return 0;
        }

        if (function_exists('mb_strlen')) {
            return mb_strlen($text, 'UTF-8');
        }

        $matched = @preg_match_all('/./us', $text, $matches);
        if (is_int($matched)) {
            return $matched;
        }

        return self::count_codepoints($text);
    }

    /**
     * Remove malformed UTF-8 byte sequences while preserving valid text.
     */
    public static function repair(string $text): string
    {
        if (self::is_valid($text)) {
            return $text;
        }

        if (function_exists('iconv')) {
            $converted = @iconv('UTF-8', 'UTF-8//IGNORE', $text);
            if (is_string($converted) && self::is_valid($converted)) {
                return $converted;
            }
        }

        return self::strip_invalid_sequences($text);
    }

    /**
     * Replace malformed byte sequences with spaces before lexical analysis.
     *
     * Dropping an invalid byte can join the words on either side into a term
     * that never appeared in the source. A space preserves the conservative
     * word boundary while the byte-wise decoder keeps this path independent of
     * iconv and mbstring.
     */
    public static function repair_word_boundaries(string $text): string
    {
        if (self::is_valid($text)) {
            return $text;
        }

        return self::strip_invalid_sequences($text, ' ');
    }

    /** Truncate to at most `$limit` bytes without splitting a UTF-8 character. */
    public static function truncate_bytes(string $text, int $limit): string
    {
        $text = self::repair($text);
        $limit = max(0, $limit);
        if (strlen($text) <= $limit) {
            return $text;
        }

        $truncated = substr($text, 0, $limit);
        while ($truncated !== '' && !self::is_valid($truncated)) {
            $truncated = substr($truncated, 0, -1);
        }

        return $truncated;
    }

    /**
     * Slice by Unicode code points without requiring mbstring.
     */
    public static function slice(string $text, int $start, ?int $length = null): string
    {
        $text = self::repair($text);
        $start = max(0, $start);
        $length = $length === null ? null : max(0, $length);
        if ($length === 0 || $text === '') {
            return '';
        }

        if (function_exists('mb_substr')) {
            return $length === null
                ? mb_substr($text, $start, null, 'UTF-8')
                : mb_substr($text, $start, $length, 'UTF-8');
        }

        if (preg_match_all('/./us', $text, $matches) === false) {
            throw new RuntimeException('UTF-8 slicing failed after input repair.');
        }

        return implode('', array_slice($matches[0], $start, $length));
    }

    /**
     * Check whether a string is valid UTF-8.
     */
    private static function is_valid(string $text): bool
    {
        return preg_match('//u', $text) === 1;
    }

    /**
     * Byte-wise UTF-8 code point counter used when PCRE UTF-8 matching is not available.
     */
    private static function count_codepoints(string $text): int
    {
        $count = 0;
        $length = strlen($text);

        for ($i = 0; $i < $length; $i++) {
            $count++;
            $byte = ord($text[$i]);
            if ($byte <= 0x7F) {
                continue;
            }

            $needed = 0;
            $codepoint = 0;
            if ($byte >= 0xC2 && $byte <= 0xDF) {
                $needed = 1;
                $codepoint = $byte & 0x1F;
            } elseif ($byte >= 0xE0 && $byte <= 0xEF) {
                $needed = 2;
                $codepoint = $byte & 0x0F;
            } elseif ($byte >= 0xF0 && $byte <= 0xF4) {
                $needed = 3;
                $codepoint = $byte & 0x07;
            } else {
                continue;
            }

            if ($i + $needed >= $length) {
                continue;
            }

            $valid = true;
            for ($j = 1; $j <= $needed; $j++) {
                $next = ord($text[$i + $j]);
                if (($next & 0xC0) !== 0x80) {
                    $valid = false;
                    break;
                }
                $codepoint = ($codepoint << 6) | ($next & 0x3F);
            }

            if (!$valid || !self::is_valid_codepoint($codepoint, $needed)) {
                continue;
            }

            $i += $needed;
        }

        return $count;
    }

    /**
     * Byte-wise UTF-8 decoder that drops invalid sequences without extensions.
     */
    private static function strip_invalid_sequences(string $text, string $replacement = ''): string
    {
        $result = '';
        $length = strlen($text);

        for ($i = 0; $i < $length; $i++) {
            $byte = ord($text[$i]);
            if ($byte <= 0x7F) {
                $result .= $text[$i];
                continue;
            }

            $needed = 0;
            $codepoint = 0;
            if ($byte >= 0xC2 && $byte <= 0xDF) {
                $needed = 1;
                $codepoint = $byte & 0x1F;
            } elseif ($byte >= 0xE0 && $byte <= 0xEF) {
                $needed = 2;
                $codepoint = $byte & 0x0F;
            } elseif ($byte >= 0xF0 && $byte <= 0xF4) {
                $needed = 3;
                $codepoint = $byte & 0x07;
            } else {
                $result .= $replacement;
                continue;
            }

            if ($i + $needed >= $length) {
                $result .= $replacement;
                break;
            }

            $sequence = $text[$i];
            $valid = true;
            for ($j = 1; $j <= $needed; $j++) {
                $next = ord($text[$i + $j]);
                if (($next & 0xC0) !== 0x80) {
                    $valid = false;
                    break;
                }
                $sequence .= $text[$i + $j];
                $codepoint = ($codepoint << 6) | ($next & 0x3F);
            }

            if (!$valid || !self::is_valid_codepoint($codepoint, $needed)) {
                $result .= $replacement;
                continue;
            }

            $result .= $sequence;
            $i += $needed;
        }

        return $result;
    }

    /**
     * Reject overlong encodings, surrogates, and values outside Unicode range.
     */
    private static function is_valid_codepoint(int $codepoint, int $continuationBytes): bool
    {
        if ($continuationBytes === 1) {
            return $codepoint >= 0x80;
        }

        if ($continuationBytes === 2) {
            return $codepoint >= 0x800 && ($codepoint < 0xD800 || $codepoint > 0xDFFF);
        }

        return $codepoint >= 0x10000 && $codepoint <= 0x10FFFF;
    }
}
