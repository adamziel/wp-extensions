<?php
declare(strict_types=1);

/** A document cannot be analyzed safely inside the supported host envelope. */
final class WP_FTS_Analysis_Limit_Exceeded extends LengthException
{
    public function __construct(public readonly string $reason_code, string $message)
    {
        parent::__construct($message);
    }
}

/** Hard, non-configurable bounds for one storage-ready document. */
final class WP_FTS_Analysis_Limits
{
    public const MAX_SOURCE_BYTES = 2097152;
    public const MAX_LEXICAL_RUN_BYTES = 4096;
    public const MAX_DOCUMENT_OCCURRENCES = 20000;
    public const MAX_DOCUMENT_DISTINCT_TERMS = 4096;
    public const MAX_DOCUMENT_DISTINCT_SURFACES = self::MAX_DOCUMENT_DISTINCT_TERMS;
    public const MAX_HTML_MARKUP_TOKENS = 20000;
    public const MAX_HTML_ELEMENT_DEPTH = 256;
    public const MAX_HTML_TAG_BYTES = 16384;
    public const MAX_HTML_ATTRIBUTES_PER_TAG = 128;
    public const MAX_HTML_ATTRIBUTE_BYTES = 4096;
    public const MAX_HTML_LANGUAGE_ATTRIBUTE_BYTES = 64;
    public const MAX_LANGUAGE_SUBTAGS = 8;

    public static function assert_source_bytes(string $source): void
    {
        self::assert_document_source_bytes(strlen($source));
    }

    public static function assert_document_source_bytes(int $bytes): void
    {
        if ($bytes > self::MAX_SOURCE_BYTES) {
            throw new WP_FTS_Analysis_Limit_Exceeded(
                'source_bytes',
                'FTS document source exceeds the 2 MiB analysis limit.'
            );
        }
    }

    public static function assert_lexical_run_bytes(int $bytes): void
    {
        if ($bytes > self::MAX_LEXICAL_RUN_BYTES) {
            throw new WP_FTS_Analysis_Limit_Exceeded(
                'lexical_run_bytes',
                'FTS lexical input exceeds the 4 KiB per-run analysis limit.'
            );
        }
    }

    public static function assert_html_markup_tokens(int $tokens): void
    {
        if ($tokens > self::MAX_HTML_MARKUP_TOKENS) {
            throw new WP_FTS_Analysis_Limit_Exceeded(
                'html_markup_tokens',
                'FTS document HTML exceeds the 20,000-markup-token limit.'
            );
        }
    }

    public static function assert_html_element_depth(int $depth): void
    {
        if ($depth > self::MAX_HTML_ELEMENT_DEPTH) {
            throw new WP_FTS_Analysis_Limit_Exceeded(
                'html_element_depth',
                'FTS document HTML exceeds the 256-element nesting limit.'
            );
        }
    }

    public static function assert_html_tag_bytes(int $bytes): void
    {
        if ($bytes > self::MAX_HTML_TAG_BYTES) {
            throw new WP_FTS_Analysis_Limit_Exceeded(
                'html_tag_bytes',
                'FTS document HTML contains an element tag above 16 KiB.'
            );
        }
    }

    public static function assert_html_attributes_per_tag(int $attributes): void
    {
        if ($attributes > self::MAX_HTML_ATTRIBUTES_PER_TAG) {
            throw new WP_FTS_Analysis_Limit_Exceeded(
                'html_attributes_per_tag',
                'FTS document HTML contains more than 128 attributes on one element.'
            );
        }
    }

    public static function assert_html_attribute_bytes(int $bytes): void
    {
        if ($bytes > self::MAX_HTML_ATTRIBUTE_BYTES) {
            throw new WP_FTS_Analysis_Limit_Exceeded(
                'html_attribute_bytes',
                'FTS document HTML contains an attribute above 4 KiB.'
            );
        }
    }

    public static function assert_html_language_attribute(string $value): void
    {
        self::assert_html_language_attribute_bytes(strlen($value));

        $value = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $subtags = 0;
        $insideSubtag = false;
        $length = strlen($value);
        for ($offset = 0; $offset < $length; $offset++) {
            if ($value[$offset] === '-' || $value[$offset] === '_') {
                $insideSubtag = false;
                continue;
            }
            if ($insideSubtag) {
                continue;
            }

            $insideSubtag = true;
            $subtags++;
            if ($subtags > self::MAX_LANGUAGE_SUBTAGS) {
                throw new WP_FTS_Analysis_Limit_Exceeded(
                    'html_language_subtags',
                    'FTS document HTML contains a language attribute above eight subtags.'
                );
            }
        }
    }

    public static function assert_html_language_attribute_bytes(int $bytes): void
    {
        if ($bytes > self::MAX_HTML_LANGUAGE_ATTRIBUTE_BYTES) {
            throw new WP_FTS_Analysis_Limit_Exceeded(
                'html_language_attribute_bytes',
                'FTS document HTML contains a language attribute above 64 bytes.'
            );
        }
    }
}
