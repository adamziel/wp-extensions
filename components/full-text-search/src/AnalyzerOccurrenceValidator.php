<?php
declare(strict_types=1);

/** Exact output contract shared by query and document analyzers. */
final class WP_FTS_Analyzer_Occurrence_Validator
{
    private const MAX_LANGUAGE_BYTES = 64;
    private const MAX_SOURCE_BYTES = 256;
    private const MAX_SURFACE_BYTES = 4096;
    private const MAX_WEIGHT = 100;

    /** Validate one query occurrence, including the intentional surface-only row. */
    public static function assert_query(mixed $occurrence): void
    {
        self::assert_common(
            $occurrence,
            ['term', 'lang', 'position', 'rank', 'source', 'surface', 'normalized_surface']
        );

        if ($occurrence['term'] !== '') {
            return;
        }
        if (!isset($occurrence['normalized_surface'])
            || array_key_exists('position', $occurrence)
            || array_key_exists('rank', $occurrence)
            || array_key_exists('source', $occurrence)
        ) {
            throw new InvalidArgumentException('An empty analyzer term is valid only for a surface-only query row.');
        }
    }

    /** Validate one document occurrence before the indexer adds its local group. */
    public static function assert_document(mixed $occurrence): void
    {
        self::assert_common(
            $occurrence,
            ['term', 'weight', 'lang', 'position', 'rank', 'source', 'surface', 'normalized_surface']
        );
        if ($occurrence['term'] === '') {
            throw new InvalidArgumentException('Document analyzer terms must not be empty.');
        }
        if (!array_key_exists('weight', $occurrence)) {
            throw new InvalidArgumentException('Document analyzer occurrences must contain weight.');
        }
        $weight = $occurrence['weight'];
        if (
            (!is_int($weight) && !is_float($weight))
            || !is_finite((float) $weight)
            || $weight <= 0
            || $weight > self::MAX_WEIGHT
        ) {
            throw new InvalidArgumentException('Document analyzer weights must be finite positive numbers no greater than 100.');
        }
    }

    /** Validate the fields common to both analyzer result shapes. */
    private static function assert_common(mixed $occurrence, array $allowedKeys): void
    {
        if (!is_array($occurrence)) {
            throw new InvalidArgumentException('Analyzer occurrences must be arrays.');
        }
        $allowed = array_fill_keys($allowedKeys, true);
        foreach ($occurrence as $key => $_value) {
            if (!is_string($key) || !isset($allowed[$key])) {
                throw new InvalidArgumentException('Analyzer occurrences contain an unsupported field.');
            }
        }

        if (!array_key_exists('term', $occurrence)
            || !is_string($occurrence['term'])
            || trim($occurrence['term']) !== $occurrence['term']
            || strlen($occurrence['term']) > WP_FTS_TermNamespace::MAX_TERM_KEY_BYTES
            || str_contains($occurrence['term'], WP_FTS_TermNamespace::SEPARATOR)
            || self::contains_ascii_whitespace($occurrence['term'])
        ) {
            throw new InvalidArgumentException('Analyzer occurrences must contain one normalized bounded term string.');
        }
        if (!array_key_exists('lang', $occurrence)
            || !is_string($occurrence['lang'])
            || trim($occurrence['lang']) === ''
            || trim($occurrence['lang']) !== $occurrence['lang']
            || strlen($occurrence['lang']) > self::MAX_LANGUAGE_BYTES
        ) {
            throw new InvalidArgumentException('Analyzer occurrences must contain one bounded language string.');
        }
        try {
            $canonicalLanguage = WP_FTS_TermNamespace::parse_language_tag($occurrence['lang']);
        } catch (InvalidArgumentException $error) {
            throw new InvalidArgumentException('Analyzer occurrence language must be a canonical language tag.', 0, $error);
        }
        if ($canonicalLanguage !== $occurrence['lang']) {
            throw new InvalidArgumentException('Analyzer occurrence language must already be canonical.');
        }

        foreach (['position', 'rank'] as $key) {
            if (array_key_exists($key, $occurrence)
                && (!is_int($occurrence[$key]) || $occurrence[$key] < 0)
            ) {
                throw new InvalidArgumentException("Analyzer occurrence {$key} must be a nonnegative integer.");
            }
        }
        if (array_key_exists('source', $occurrence)
            && (!is_string($occurrence['source'])
                || trim($occurrence['source']) === ''
                || trim($occurrence['source']) !== $occurrence['source']
                || strlen($occurrence['source']) > self::MAX_SOURCE_BYTES)
        ) {
            throw new InvalidArgumentException('Analyzer occurrence source must be a nonempty bounded string.');
        }
        foreach (['surface', 'normalized_surface'] as $key) {
            if (array_key_exists($key, $occurrence)
                && (!is_string($occurrence[$key])
                    || trim($occurrence[$key]) === ''
                    || trim($occurrence[$key]) !== $occurrence[$key]
                    || strlen($occurrence[$key]) > self::MAX_SURFACE_BYTES)
            ) {
                throw new InvalidArgumentException("Analyzer occurrence {$key} must be a nonempty bounded string.");
            }
        }
        if (array_key_exists('normalized_surface', $occurrence)
            && (str_contains($occurrence['normalized_surface'], WP_FTS_TermNamespace::SEPARATOR)
                || self::contains_ascii_whitespace($occurrence['normalized_surface']))
        ) {
            throw new InvalidArgumentException('Analyzer occurrence normalized_surface must contain one normalized lexical token.');
        }
    }

    /** Return whether a normalized lexical value contains ASCII whitespace. */
    private static function contains_ascii_whitespace(string $value): bool
    {
        return strpbrk($value, " \t\r\n\f\v") !== false;
    }
}
