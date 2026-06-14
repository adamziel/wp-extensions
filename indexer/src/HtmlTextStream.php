<?php
declare(strict_types=1);

/**
 * Scans HTML as syntax tokens and visible text without depending on DOMDocument.
 *
 * The scanner keeps source byte offsets for decoded text so callers can compare
 * rendered Unicode words while preserving the original markup when inserting
 * highlights. It is intentionally small and conservative: malformed tags fall
 * back to text, comments are ignored, and script/style-like descendants are not
 * emitted as visible text.
 */
final class WP_FTS_Html_Text_Stream
{
    /** @var array<string,bool> */
    private const HIDDEN_TAGS = [
        'SCRIPT' => true,
        'STYLE' => true,
        'NOSCRIPT' => true,
        'TEMPLATE' => true,
    ];

    /** @var array<string,bool> */
    private const BOUNDARY_TAGS = [
        'ADDRESS' => true,
        'ARTICLE' => true,
        'ASIDE' => true,
        'BLOCKQUOTE' => true,
        'BODY' => true,
        'BR' => true,
        'CAPTION' => true,
        'DD' => true,
        'DETAILS' => true,
        'DIALOG' => true,
        'DIV' => true,
        'DL' => true,
        'DT' => true,
        'FIELDSET' => true,
        'FIGCAPTION' => true,
        'FIGURE' => true,
        'FOOTER' => true,
        'FORM' => true,
        'H1' => true,
        'H2' => true,
        'H3' => true,
        'H4' => true,
        'H5' => true,
        'H6' => true,
        'HEADER' => true,
        'HGROUP' => true,
        'HR' => true,
        'LI' => true,
        'MAIN' => true,
        'MENU' => true,
        'NAV' => true,
        'OL' => true,
        'OPTION' => true,
        'P' => true,
        'PRE' => true,
        'SECTION' => true,
        'TABLE' => true,
        'TBODY' => true,
        'TD' => true,
        'TFOOT' => true,
        'TH' => true,
        'THEAD' => true,
        'TITLE' => true,
        'TR' => true,
        'UL' => true,
    ];

    /** @var array<string,bool> */
    private const VOID_TAGS = [
        'AREA' => true,
        'BASE' => true,
        'BR' => true,
        'COL' => true,
        'EMBED' => true,
        'HR' => true,
        'IMG' => true,
        'INPUT' => true,
        'LINK' => true,
        'META' => true,
        'PARAM' => true,
        'SOURCE' => true,
        'TRACK' => true,
        'WBR' => true,
    ];

    /**
     * Extract rendered text, preserving inline adjacency and separating blocks.
     */
    public static function visible_text(string $html): string
    {
        $text = '';
        $lastGroup = null;
        foreach (self::visible_characters($html) as $char) {
            if ($lastGroup !== null && $char['group'] !== $lastGroup && $text !== '' && !self::ends_with_whitespace($text)) {
                $text .= ' ';
            }

            $text .= $char['text'];
            $lastGroup = $char['group'];
        }

        $text = WP_FTS_Utf8::repair($text);
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;

        return trim($text);
    }

    /**
     * Return visible lexical words with their original source byte ranges.
     *
     * @return array<int,array{text:string,source_start:int,source_end:int,group:int}>
     */
    public static function visible_words(string $html): array
    {
        $words = [];
        $current = null;

        foreach (self::visible_characters($html) as $char) {
            if (!self::is_word_character($char['text'])) {
                if ($current !== null) {
                    $words[] = $current;
                    $current = null;
                }
                continue;
            }

            if ($current !== null && $current['group'] === $char['group']) {
                $current['text'] .= $char['text'];
                $current['source_end'] = $char['source_end'];
                continue;
            }

            if ($current !== null) {
                $words[] = $current;
            }
            $current = [
                'text' => $char['text'],
                'source_start' => $char['source_start'],
                'source_end' => $char['source_end'],
                'group' => $char['group'],
            ];
        }

        if ($current !== null) {
            $words[] = $current;
        }

        return $words;
    }

    /**
     * Insert <mark> tags at source ranges, preserving existing markup.
     *
     * @param array<int,array{start:int,end:int}> $ranges
     */
    public static function mark_ranges(string $html, array $ranges): string
    {
        if ($ranges === []) {
            return $html;
        }

        usort($ranges, static fn(array $a, array $b): int => $b['start'] <=> $a['start']);
        foreach ($ranges as $range) {
            $start = max(0, min(strlen($html), (int) $range['start']));
            $end = max($start, min(strlen($html), (int) $range['end']));
            $html = substr($html, 0, $end) . '</mark>' . substr($html, $end);
            $html = substr($html, 0, $start) . '<mark>' . substr($html, $start);
        }

        return $html;
    }

    /**
     * Expand a text range over adjacent inline wrappers to keep markup valid.
     *
     * @return array{start:int,end:int}
     */
    public static function expand_inline_range(string $html, int $start, int $end): array
    {
        $start = max(0, min(strlen($html), $start));
        $end = max($start, min(strlen($html), $end));

        do {
            $expanded = false;
            $previous = self::previous_immediate_tag($html, $start);
            if (
                $previous !== null
                && !$previous['closing']
                && self::is_inline_wrapper_tag($previous['name'])
            ) {
                $start = $previous['start'];
                $expanded = true;
            }

            $next = self::next_immediate_tag($html, $end);
            if (
                $next !== null
                && $next['closing']
                && self::is_inline_wrapper_tag($next['name'])
            ) {
                $end = $next['end'];
                $expanded = true;
            }
        } while ($expanded);

        return ['start' => $start, 'end' => $end];
    }

    /**
     * @return array<int,array{text:string,source_start:int,source_end:int,group:int}>
     */
    private static function visible_characters(string $html): array
    {
        $html = WP_FTS_Utf8::repair($html);
        $characters = [];
        $stack = [];
        $hiddenDepth = 0;
        $group = 1;
        $length = strlen($html);
        $offset = 0;
        $textStart = 0;

        while ($offset < $length) {
            if ($html[$offset] !== '<') {
                $offset += self::utf8_char_length($html, $offset);
                continue;
            }

            if ($offset > $textStart && $hiddenDepth === 0) {
                array_push(
                    $characters,
                    ...self::decoded_characters(substr($html, $textStart, $offset - $textStart), $textStart, $group)
                );
            }

            if (substr_compare($html, '<!--', $offset, 4) === 0) {
                $end = strpos($html, '-->', $offset + 4);
                $offset = $end === false ? $length : $end + 3;
                $textStart = $offset;
                continue;
            }

            $tag = self::read_tag($html, $offset);
            if ($tag === null) {
                $offset++;
                $textStart = $offset - 1;
                continue;
            }

            $name = $tag['name'];
            if ($name !== '') {
                if ($tag['closing']) {
                    $popped = self::pop_tag($stack, $name);
                    if ($popped !== null && isset(self::HIDDEN_TAGS[$popped])) {
                        $hiddenDepth = max(0, $hiddenDepth - 1);
                    }
                    if (isset(self::BOUNDARY_TAGS[$name])) {
                        $group++;
                    }
                } else {
                    if (isset(self::BOUNDARY_TAGS[$name])) {
                        $group++;
                    }
                    if (!isset(self::VOID_TAGS[$name]) && !$tag['self_closing']) {
                        $stack[] = $name;
                        if (isset(self::HIDDEN_TAGS[$name])) {
                            $hiddenDepth++;
                        }
                    }
                }
            }

            $offset = $tag['end'];
            $textStart = $offset;
        }

        if ($offset > $textStart && $hiddenDepth === 0) {
            array_push(
                $characters,
                ...self::decoded_characters(substr($html, $textStart, $offset - $textStart), $textStart, $group)
            );
        }

        return $characters;
    }

    /**
     * @return array{name:string,closing:bool,self_closing:bool,end:int}|null
     */
    private static function read_tag(string $html, int $offset): ?array
    {
        $length = strlen($html);
        if ($offset >= $length || $html[$offset] !== '<') {
            return null;
        }

        $cursor = $offset + 1;
        while ($cursor < $length && self::is_html_whitespace($html[$cursor])) {
            $cursor++;
        }

        $closing = false;
        if ($cursor < $length && $html[$cursor] === '/') {
            $closing = true;
            $cursor++;
            while ($cursor < $length && self::is_html_whitespace($html[$cursor])) {
                $cursor++;
            }
        }

        $nameStart = $cursor;
        while ($cursor < $length && self::is_tag_name_character($html[$cursor])) {
            $cursor++;
        }
        if ($cursor === $nameStart) {
            return null;
        }

        $name = strtoupper(substr($html, $nameStart, $cursor - $nameStart));
        $quote = null;
        while ($cursor < $length) {
            $char = $html[$cursor];
            if ($quote !== null) {
                if ($char === $quote) {
                    $quote = null;
                }
                $cursor++;
                continue;
            }

            if ($char === '"' || $char === "'") {
                $quote = $char;
                $cursor++;
                continue;
            }

            if ($char === '>') {
                $raw = substr($html, $offset, $cursor - $offset);
                return [
                    'name' => $name,
                    'closing' => $closing,
                    'self_closing' => !$closing && self::raw_tag_is_self_closing($raw),
                    'end' => $cursor + 1,
                ];
            }

            $cursor++;
        }

        return null;
    }

    /**
     * @return array{name:string,closing:bool,self_closing:bool,start:int,end:int}|null
     */
    private static function previous_immediate_tag(string $html, int $offset): ?array
    {
        if ($offset <= 0 || $offset > strlen($html) || $html[$offset - 1] !== '>') {
            return null;
        }

        $start = strrpos(substr($html, 0, $offset), '<');
        if ($start === false) {
            return null;
        }

        $tag = self::read_tag($html, $start);
        if ($tag === null || $tag['end'] !== $offset) {
            return null;
        }

        $tag['start'] = $start;

        return $tag;
    }

    /**
     * @return array{name:string,closing:bool,self_closing:bool,start:int,end:int}|null
     */
    private static function next_immediate_tag(string $html, int $offset): ?array
    {
        if ($offset < 0 || $offset >= strlen($html) || $html[$offset] !== '<') {
            return null;
        }

        $tag = self::read_tag($html, $offset);
        if ($tag === null) {
            return null;
        }

        $tag['start'] = $offset;

        return $tag;
    }

    private static function is_inline_wrapper_tag(string $tag): bool
    {
        return !isset(self::BOUNDARY_TAGS[$tag])
            && !isset(self::HIDDEN_TAGS[$tag])
            && !isset(self::VOID_TAGS[$tag]);
    }

    /**
     * @param string[] $stack
     */
    private static function pop_tag(array &$stack, string $name): ?string
    {
        for ($i = count($stack) - 1; $i >= 0; $i--) {
            if ($stack[$i] !== $name) {
                continue;
            }

            $popped = null;
            while (count($stack) > $i) {
                $popped = array_pop($stack);
            }

            return $popped;
        }

        return null;
    }

    /**
     * @return array<int,array{text:string,source_start:int,source_end:int,group:int}>
     */
    private static function decoded_characters(string $raw, int $sourceOffset, int $group): array
    {
        $characters = [];
        $length = strlen($raw);
        $offset = 0;

        while ($offset < $length) {
            if ($raw[$offset] === '&') {
                $semicolon = strpos($raw, ';', $offset + 1);
                if ($semicolon !== false) {
                    $entity = substr($raw, $offset, $semicolon - $offset + 1);
                    $decoded = html_entity_decode($entity, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
                    if ($decoded !== $entity) {
                        foreach (self::utf8_characters($decoded) as $decodedChar) {
                            $characters[] = [
                                'text' => $decodedChar,
                                'source_start' => $sourceOffset + $offset,
                                'source_end' => $sourceOffset + $semicolon + 1,
                                'group' => $group,
                            ];
                        }
                        $offset = $semicolon + 1;
                        continue;
                    }
                }
            }

            $charLength = self::utf8_char_length($raw, $offset);
            $characters[] = [
                'text' => substr($raw, $offset, $charLength),
                'source_start' => $sourceOffset + $offset,
                'source_end' => $sourceOffset + $offset + $charLength,
                'group' => $group,
            ];
            $offset += $charLength;
        }

        return $characters;
    }

    /**
     * @return string[]
     */
    private static function utf8_characters(string $text): array
    {
        if (!preg_match_all('/./us', $text, $matches)) {
            return [];
        }

        return $matches[0];
    }

    private static function utf8_char_length(string $text, int $offset): int
    {
        $byte = ord($text[$offset]);
        if ($byte < 0x80) {
            return 1;
        }
        if (($byte & 0xE0) === 0xC0) {
            return 2;
        }
        if (($byte & 0xF0) === 0xE0) {
            return 3;
        }
        if (($byte & 0xF8) === 0xF0) {
            return 4;
        }

        return 1;
    }

    private static function is_word_character(string $char): bool
    {
        return $char !== '' && preg_match('/^[\p{L}\p{M}\p{N}_]$/u', $char) === 1;
    }

    private static function raw_tag_is_self_closing(string $raw): bool
    {
        $offset = strlen($raw) - 1;
        while ($offset >= 0 && self::is_html_whitespace($raw[$offset])) {
            $offset--;
        }

        return $offset >= 0 && $raw[$offset] === '/';
    }

    private static function is_tag_name_character(string $char): bool
    {
        $ord = ord($char);

        return ($ord >= 65 && $ord <= 90)
            || ($ord >= 97 && $ord <= 122)
            || ($ord >= 48 && $ord <= 57)
            || $char === ':'
            || $char === '-';
    }

    private static function is_html_whitespace(string $char): bool
    {
        return $char === ' '
            || $char === "\t"
            || $char === "\n"
            || $char === "\r"
            || $char === "\f";
    }

    private static function ends_with_whitespace(string $text): bool
    {
        if ($text === '') {
            return false;
        }

        return preg_match('/\s$/u', $text) === 1;
    }
}
