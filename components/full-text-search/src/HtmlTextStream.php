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
    // The longest HTML5 named character reference is far shorter than this.
    // A fixed lexical lookahead keeps malformed ampersand runs linear.
    private const MAX_ENTITY_REFERENCE_BYTES = 64;

    /** @var array<string,bool> */
    private const HIDDEN_TAGS = [
        'ASIDE' => true,
        'FOOTER' => true,
        'FORM' => true,
        'NAV' => true,
        'SCRIPT' => true,
        'SVG' => true,
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
     * Reject hostile HTML syntax before either parser builds breadcrumb or
     * segment arrays. This one byte-stream pass is shared by the WordPress HTML
     * processor and the component fallback parser.
     */
    public static function assert_analysis_markup_limits(string $html): void
    {
        $stack = [];
        $stackPositions = [];
        $tokens = 0;
        $length = strlen($html);
        $offset = 0;

        while ($offset < $length) {
            $tagStart = strpos($html, '<', $offset);
            if ($tagStart === false) {
                break;
            }

            if (substr_compare($html, '<!--', $tagStart, 4) === 0) {
                $end = strpos($html, '-->', $tagStart + 4);
                $offset = $end === false ? $length : $end + 3;
                WP_FTS_Analysis_Limits::assert_html_markup_tokens(++$tokens);
                continue;
            }
            if (substr_compare($html, '<![CDATA[', $tagStart, 9) === 0) {
                $end = strpos($html, ']]>', $tagStart + 9);
                $offset = $end === false ? $length : $end + 3;
                WP_FTS_Analysis_Limits::assert_html_markup_tokens(++$tokens);
                continue;
            }

            $marker = $html[$tagStart + 1] ?? '';
            if ($marker === '!' || $marker === '?') {
                $end = self::declaration_end_offset($html, $tagStart + 2);
                $offset = $end === null ? $length : $end + 1;
                WP_FTS_Analysis_Limits::assert_html_markup_tokens(++$tokens);
                continue;
            }

            $tag = self::read_tag($html, $tagStart, true);
            if ($tag === null) {
                $offset = $tagStart + 1;
                continue;
            }

            WP_FTS_Analysis_Limits::assert_html_markup_tokens(++$tokens);
            $name = $tag['name'];
            if ($name !== '') {
                if ($tag['closing']) {
                    self::pop_tag($stack, $stackPositions, $name);
                } elseif (!isset(self::VOID_TAGS[$name]) && !$tag['self_closing']) {
                    self::push_tag($stack, $stackPositions, $name);
                    WP_FTS_Analysis_Limits::assert_html_element_depth(count($stack));
                }
            }
            $offset = $tag['end'];
        }
    }

    /**
     * Extract rendered text, preserving inline adjacency and separating blocks.
     */
    public static function visible_text(string $html): string
    {
        $text = '';
        $lastGroup = null;
        $lastCharacter = '';
        foreach (self::visible_characters($html) as $char) {
            if (
                $lastGroup !== null
                && $char['group'] !== $lastGroup
                && $text !== ''
                && !self::is_whitespace_character($lastCharacter)
            ) {
                $text .= ' ';
            }

            $text .= $char['text'];
            // `visible_characters()` yields one repaired codepoint at a time.
            // Retaining that bounded tail preserves PCRE's Unicode whitespace
            // semantics without rescanning the complete growing output when a
            // later block boundary is encountered.
            $lastCharacter = $char['text'];
            $lastGroup = $char['group'];
        }

        $text = WP_FTS_Utf8::repair($text);
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;

        return trim($text);
    }

    /**
     * Return visible lexical words with their original source byte ranges.
     *
     * @return array<int,array{text:string,source_start:int,source_end:int,group:int,visible_start:int,visible_end:int}>
     */
    public static function visible_words(string $html): array
    {
        return iterator_to_array(self::visible_word_stream($html), false);
    }

    /**
     * Stream visible lexical words with their original source byte ranges.
     *
     * Indexing uses this form so source size does not create one PHP array per
     * decoded character and then another per word. `visible_words()` remains
     * the backwards-compatible materializing adapter for existing callers.
     *
     * @return iterable<int,array{text:string,source_start:int,source_end:int,group:int,visible_start:int,visible_end:int}>
     */
    public static function visible_word_stream(string $html): iterable
    {
        $current = null;
        $visibleOffset = 0;

        foreach (self::visible_characters($html) as $char) {
            $charVisibleStart = $visibleOffset;
            $visibleOffset++;
            if (!self::is_word_character($char['text'])) {
                if ($current !== null) {
                    yield $current;
                    $current = null;
                }
                continue;
            }

            if ($current !== null && $current['group'] === $char['group']) {
                $nextBytes = strlen($current['text']) + strlen($char['text']);
                WP_FTS_Analysis_Limits::assert_lexical_run_bytes($nextBytes);
                $current['text'] .= $char['text'];
                $current['source_end'] = $char['source_end'];
                $current['visible_end'] = $charVisibleStart + 1;
                continue;
            }

            if ($current !== null) {
                yield $current;
            }
            $current = [
                'text' => $char['text'],
                'source_start' => $char['source_start'],
                'source_end' => $char['source_end'],
                'group' => $char['group'],
                'visible_start' => $charVisibleStart,
                'visible_end' => $charVisibleStart + 1,
            ];
        }

        if ($current !== null) {
            yield $current;
        }
    }

    /**
     * Return source byte offsets covering a window of decoded visible text.
     *
     * @return array{source_start:int,source_end:int,visible_start:int,visible_end:int,total_visible:int}|null
     */
    public static function visible_source_window(string $html, int $visibleStart, int $visibleEnd): ?array
    {
        $requestedStart = max(0, $visibleStart);
        $requestedEnd = max($requestedStart + 1, $visibleEnd);
        $sourceStart = null;
        $sourceEnd = null;
        $actualVisibleStart = null;
        $actualVisibleEnd = null;
        $lastCharacter = null;
        $total = 0;

        foreach (self::visible_characters($html) as $char) {
            $index = $total++;
            $lastCharacter = $char;
            if ($index < $requestedStart || $index >= $requestedEnd) {
                continue;
            }

            if ($sourceStart === null) {
                $sourceStart = (int) $char['source_start'];
                $actualVisibleStart = $index;
            }
            $sourceEnd = (int) $char['source_end'];
            $actualVisibleEnd = $index + 1;
        }

        // Preserve the old clamping behavior when the requested start lies
        // beyond the end: return a window covering the final visible codepoint.
        if ($sourceStart === null && $lastCharacter !== null && $requestedStart >= $total) {
            $sourceStart = (int) $lastCharacter['source_start'];
            $sourceEnd = (int) $lastCharacter['source_end'];
            $actualVisibleStart = $total - 1;
            $actualVisibleEnd = $total;
        }

        if ($sourceStart === null || $sourceEnd === null || $actualVisibleStart === null || $actualVisibleEnd === null) {
            return null;
        }

        return [
            'source_start' => $sourceStart,
            'source_end' => $sourceEnd,
            'visible_start' => $actualVisibleStart,
            'visible_end' => $actualVisibleEnd,
            'total_visible' => $total,
        ];
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
     * @return iterable<int,array{text:string,source_start:int,source_end:int,group:int}>
     */
    private static function visible_characters(string $html): iterable
    {
        $html = WP_FTS_Utf8::repair($html);
        $stack = [];
        $stackPositions = [];
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
                yield from self::decoded_characters(
                    substr($html, $textStart, $offset - $textStart),
                    $textStart,
                    $group
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
                    $poppedHidden = self::pop_tag($stack, $stackPositions, $name);
                    if ($poppedHidden !== null) {
                        $hiddenDepth = max(0, $hiddenDepth - $poppedHidden);
                    }
                    if (isset(self::BOUNDARY_TAGS[$name])) {
                        $group++;
                    }
                } else {
                    if (isset(self::BOUNDARY_TAGS[$name])) {
                        $group++;
                    }
                    if (!isset(self::VOID_TAGS[$name]) && !$tag['self_closing']) {
                        self::push_tag($stack, $stackPositions, $name);
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
            yield from self::decoded_characters(
                substr($html, $textStart, $offset - $textStart),
                $textStart,
                $group
            );
        }
    }

    /**
     * @return array{name:string,closing:bool,self_closing:bool,end:int}|null
     */
    private static function read_tag(string $html, int $offset, bool $enforceAnalysisLimits = false): ?array
    {
        $length = strlen($html);
        if ($offset >= $length || $html[$offset] !== '<') {
            return null;
        }

        $cursor = $offset + 1;
        while ($cursor < $length && self::is_html_whitespace($html[$cursor])) {
            $cursor++;
            if ($enforceAnalysisLimits) {
                WP_FTS_Analysis_Limits::assert_html_tag_bytes($cursor - $offset);
            }
        }

        $closing = false;
        if ($cursor < $length && $html[$cursor] === '/') {
            $closing = true;
            $cursor++;
            while ($cursor < $length && self::is_html_whitespace($html[$cursor])) {
                $cursor++;
                if ($enforceAnalysisLimits) {
                    WP_FTS_Analysis_Limits::assert_html_tag_bytes($cursor - $offset);
                }
            }
        }

        $nameStart = $cursor;
        while ($cursor < $length && self::is_tag_name_character($html[$cursor])) {
            $cursor++;
            if ($enforceAnalysisLimits) {
                WP_FTS_Analysis_Limits::assert_html_tag_bytes($cursor - $offset);
            }
        }
        if ($cursor === $nameStart) {
            return null;
        }

        $name = strtoupper(substr($html, $nameStart, $cursor - $nameStart));
        $attributeCount = 0;
        while ($cursor < $length) {
            if ($enforceAnalysisLimits) {
                WP_FTS_Analysis_Limits::assert_html_tag_bytes($cursor - $offset + 1);
            }

            while ($cursor < $length && self::is_html_whitespace($html[$cursor])) {
                $cursor++;
                if ($enforceAnalysisLimits) {
                    WP_FTS_Analysis_Limits::assert_html_tag_bytes($cursor - $offset);
                }
            }
            if ($cursor >= $length) {
                return null;
            }

            $char = $html[$cursor];
            if ($char === '<') {
                // A literal '<' cannot continue an HTML tag token. Stopping
                // also keeps "<a<a<a..." from rescanning to EOF.
                return null;
            }
            if ($char === '>') {
                if ($enforceAnalysisLimits) {
                    WP_FTS_Analysis_Limits::assert_html_tag_bytes($cursor - $offset + 1);
                }
                return [
                    'name' => $name,
                    'closing' => $closing,
                    'self_closing' => !$closing && self::tag_is_self_closing_at($html, $offset, $cursor),
                    'end' => $cursor + 1,
                ];
            }
            if ($char === '/') {
                $cursor++;
                continue;
            }

            $attributeStart = $cursor;
            $attributeNameStart = $cursor;
            while ($cursor < $length && !self::is_attribute_name_delimiter($html[$cursor])) {
                if ($html[$cursor] === '<') {
                    return null;
                }
                $cursor++;
                if ($enforceAnalysisLimits) {
                    WP_FTS_Analysis_Limits::assert_html_tag_bytes($cursor - $offset);
                    WP_FTS_Analysis_Limits::assert_html_attribute_bytes($cursor - $attributeStart);
                }
            }
            if ($cursor === $attributeNameStart) {
                $cursor++;
                continue;
            }

            $attributeName = $enforceAnalysisLimits
                ? strtolower(substr($html, $attributeNameStart, $cursor - $attributeNameStart))
                : '';
            $languageAttribute = $attributeName === 'lang' || $attributeName === 'xml:lang';
            if ($enforceAnalysisLimits) {
                WP_FTS_Analysis_Limits::assert_html_attributes_per_tag(++$attributeCount);
            }

            while ($cursor < $length && self::is_html_whitespace($html[$cursor])) {
                $cursor++;
                if ($enforceAnalysisLimits) {
                    WP_FTS_Analysis_Limits::assert_html_tag_bytes($cursor - $offset);
                    WP_FTS_Analysis_Limits::assert_html_attribute_bytes($cursor - $attributeStart);
                }
            }

            $valueStart = $cursor;
            $valueEnd = $cursor;
            if ($cursor < $length && $html[$cursor] === '=') {
                $cursor++;
                while ($cursor < $length && self::is_html_whitespace($html[$cursor])) {
                    $cursor++;
                    if ($enforceAnalysisLimits) {
                        WP_FTS_Analysis_Limits::assert_html_tag_bytes($cursor - $offset);
                        WP_FTS_Analysis_Limits::assert_html_attribute_bytes($cursor - $attributeStart);
                    }
                }

                if ($cursor < $length && ($html[$cursor] === '"' || $html[$cursor] === "'")) {
                    $quote = $html[$cursor++];
                    $valueStart = $cursor;
                    while ($cursor < $length && $html[$cursor] !== $quote) {
                        if ($html[$cursor] === '<') {
                            return null;
                        }
                        $cursor++;
                        if ($enforceAnalysisLimits) {
                            WP_FTS_Analysis_Limits::assert_html_tag_bytes($cursor - $offset);
                            WP_FTS_Analysis_Limits::assert_html_attribute_bytes($cursor - $attributeStart);
                            if ($languageAttribute) {
                                WP_FTS_Analysis_Limits::assert_html_language_attribute_bytes($cursor - $valueStart);
                            }
                        }
                    }
                    if ($cursor >= $length) {
                        return null;
                    }
                    $valueEnd = $cursor;
                    $cursor++;
                } else {
                    $valueStart = $cursor;
                    while (
                        $cursor < $length
                        && !self::is_html_whitespace($html[$cursor])
                        && $html[$cursor] !== '>'
                    ) {
                        if ($html[$cursor] === '<') {
                            return null;
                        }
                        $cursor++;
                        if ($enforceAnalysisLimits) {
                            WP_FTS_Analysis_Limits::assert_html_tag_bytes($cursor - $offset);
                            WP_FTS_Analysis_Limits::assert_html_attribute_bytes($cursor - $attributeStart);
                            if ($languageAttribute) {
                                WP_FTS_Analysis_Limits::assert_html_language_attribute_bytes($cursor - $valueStart);
                            }
                        }
                    }
                    $valueEnd = $cursor;
                }
            }

            if ($enforceAnalysisLimits) {
                WP_FTS_Analysis_Limits::assert_html_attribute_bytes($cursor - $attributeStart);
                if ($languageAttribute) {
                    WP_FTS_Analysis_Limits::assert_html_language_attribute(
                        substr($html, $valueStart, $valueEnd - $valueStart)
                    );
                }
            }
        }

        return null;
    }

    private static function declaration_end_offset(string $html, int $offset): ?int
    {
        $quote = null;
        $length = strlen($html);
        for (; $offset < $length; $offset++) {
            $char = $html[$offset];
            if ($quote !== null) {
                if ($char === $quote) {
                    $quote = null;
                }
                continue;
            }
            if ($char === '"' || $char === "'") {
                $quote = $char;
                continue;
            }
            if ($char === '>') {
                return $offset;
            }
        }

        return null;
    }

    private static function is_attribute_name_delimiter(string $char): bool
    {
        return self::is_html_whitespace($char)
            || $char === '/'
            || $char === '='
            || $char === '>';
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
     * @param array<string,int[]> $positions
     */
    private static function pop_tag(array &$stack, array &$positions, string $name): ?int
    {
        if (($positions[$name] ?? []) === []) {
            return null;
        }

        $index = $positions[$name][array_key_last($positions[$name])];
        $hidden = 0;
        while (count($stack) > $index) {
            $popped = array_pop($stack);
            if (!is_string($popped)) {
                continue;
            }
            array_pop($positions[$popped]);
            if ($positions[$popped] === []) {
                unset($positions[$popped]);
            }
            if (isset(self::HIDDEN_TAGS[$popped])) {
                $hidden++;
            }
        }

        return $hidden;
    }

    /**
     * @param string[] $stack
     * @param array<string,int[]> $positions
     */
    private static function push_tag(array &$stack, array &$positions, string $name): void
    {
        $positions[$name][] = count($stack);
        $stack[] = $name;
    }

    /**
     * @return iterable<int,array{text:string,source_start:int,source_end:int,group:int}>
     */
    private static function decoded_characters(string $raw, int $sourceOffset, int $group): iterable
    {
        $length = strlen($raw);
        $offset = 0;

        while ($offset < $length) {
            if ($raw[$offset] === '&') {
                $semicolon = self::entity_semicolon_offset($raw, $offset);
                if ($semicolon !== null) {
                    $entity = substr($raw, $offset, $semicolon - $offset + 1);
                    $decoded = html_entity_decode($entity, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
                    if ($decoded !== $entity) {
                        foreach (self::utf8_characters($decoded) as $decodedChar) {
                            yield [
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
            yield [
                'text' => substr($raw, $offset, $charLength),
                'source_start' => $sourceOffset + $offset,
                'source_end' => $sourceOffset + $offset + $charLength,
                'group' => $group,
            ];
            $offset += $charLength;
        }
    }

    private static function entity_semicolon_offset(string $raw, int $ampersandOffset): ?int
    {
        $end = min(strlen($raw), $ampersandOffset + self::MAX_ENTITY_REFERENCE_BYTES);
        for ($cursor = $ampersandOffset + 1; $cursor < $end; $cursor++) {
            $char = $raw[$cursor];
            if ($char === ';') {
                return $cursor;
            }
            if ($char === '&' || $char === '<' || self::is_html_whitespace($char)) {
                return null;
            }
        }

        return null;
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

    private static function tag_is_self_closing_at(string $html, int $tagStart, int $tagEnd): bool
    {
        $offset = $tagEnd - 1;
        while ($offset > $tagStart && self::is_html_whitespace($html[$offset])) {
            $offset--;
        }

        return $offset > $tagStart && $html[$offset] === '/';
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

    private static function is_whitespace_character(string $character): bool
    {
        if ($character === '') {
            return false;
        }

        return preg_match('/^\s$/u', $character) === 1;
    }
}
