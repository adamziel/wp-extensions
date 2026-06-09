<?php
declare(strict_types=1);

/**
 * Contract for optional language-aware term stemming.
 *
 * Stemmers receive normalized terms from the language pipeline and must return
 * a normalized term string. They should be conservative: returning the original
 * term is preferred when a language is unsupported or an implementation is not
 * available.
 */
interface WP_FTS_Stemmer
{
    /**
     * Stem one normalized term for a language partition.
     *
     * @param string $term Normalized term text, not namespaced.
     * @param string $language Canonical language tag such as `en` or `pl`.
     * @return string Stemmed term, or the original term when no safe stemming is
     *         available.
     */
    public function stem(string $term, string $language): string;
}

/**
 * Stemmer implementation that deliberately leaves all terms unchanged.
 */
final class WP_FTS_NoopStemmer implements WP_FTS_Stemmer
{
    /**
     * Return the input term exactly as supplied.
     *
     * @param string $term Normalized term text.
     * @param string $language Canonical language tag; accepted for interface
     *        compatibility.
     * @return string The unchanged term.
     */
    public function stem(string $term, string $language): string
    {
        return $term;
    }
}

/**
 * Adapts a user callable to the stemmer interface.
 *
 * Legacy callers often supplied one-argument callbacks such as `metaphone`.
 * This adapter preserves that form and only passes `$language` when the
 * callable requires at least two parameters or is variadic.
 */
final class WP_FTS_CallbackStemmer implements WP_FTS_Stemmer
{
    /** @var callable */
    private $callback;
    private bool $passesLanguage;

    /**
     * @param callable $callback Function accepting either `($term)` or
     *        `($term, $language)` and returning a replacement term.
     */
    public function __construct(callable $callback)
    {
        $this->callback = $callback;
        $this->passesLanguage = $this->accepts_language($callback);
    }

    /**
     * Run the configured callback with the arity it expects.
     *
     * @param string $term Normalized term text.
     * @param string $language Canonical language tag.
     * @return string Callback result cast to string.
     */
    public function stem(string $term, string $language): string
    {
        return (string) (
            $this->passesLanguage
                ? ($this->callback)($term, $language)
                : ($this->callback)($term)
        );
    }

    /**
     * Decide whether the callback should receive the language argument.
     *
     * Optional second parameters are not enough: many PHP callbacks use that
     * slot for unrelated options. Required two-argument and variadic callbacks
     * are treated as language-aware.
     *
     * @param callable $callback User callback being adapted.
     * @return bool True when `stem()` should call it with two arguments.
     */
    private function accepts_language(callable $callback): bool
    {
        $reflection = new ReflectionFunction(Closure::fromCallable($callback));

        // Preserve the legacy one-argument stemmer contract for callables such
        // as metaphone(), whose optional second parameter is not a language.
        return $reflection->isVariadic() || $reflection->getNumberOfRequiredParameters() >= 2;
    }
}

/**
 * Uses the optional Wamania Snowball package for languages verified by tests.
 *
 * Unsupported languages and missing packages are no-ops. The allowlist is
 * intentionally narrow so enabling stemming does not silently switch to
 * algorithms that diverge from the bundled Snowball compliance data.
 */
final class WP_FTS_SnowballStemmer implements WP_FTS_Stemmer
{
    /** @var array<string,bool> */
    private array $supportedLanguages;
    private WP_FTS_EnglishSnowballStemmer $englishStemmer;

    /**
     * Initialize the set of Snowball languages accepted by this adapter.
     */
    public function __construct(?WP_FTS_EnglishSnowballStemmer $englishStemmer = null)
    {
        // Expose only implementations that match the official Snowball
        // fixtures exactly. English is a local generated Snowball/Porter2 port;
        // Catalan and Dutch Porter remain Wamania-backed optional paths.
        // Other Wamania classes currently diverge from the current
        // snowball-data outputs and are treated as no-ops until their
        // algorithms are replaced or patched.
        $this->supportedLanguages = array_fill_keys([
            'ca',
            'en',
            'nl',
        ], true);
        $this->englishStemmer = $englishStemmer ?? new WP_FTS_EnglishSnowballStemmer();
    }

    /**
     * Report whether the optional Snowball dependency is installed.
     *
     * @return bool True when `Wamania\Snowball\StemmerFactory` can be used.
     */
    public function is_available(): bool
    {
        return class_exists('\\Wamania\\Snowball\\StemmerFactory');
    }

    /**
     * Check whether this adapter is allowed to stem the given language.
     *
     * @param string $language Canonical or locale-style language tag.
     * @return bool True for supported base languages only.
     */
    public function supports_language(string $language): bool
    {
        return isset($this->supportedLanguages[$this->base_language($language)]);
    }

    /**
     * Report whether a supported language has an available runtime path.
     *
     * English is bundled as generated PHP; Wamania-backed languages remain
     * optional and no-op safely when Composer packages are absent.
     *
     * @param string $language Canonical or locale-style language tag.
     * @return bool True when `stem()` can apply a verified implementation.
     */
    public function is_language_available(string $language): bool
    {
        $language = $this->base_language($language);
        if ($language === 'en') {
            return true;
        }

        return isset($this->supportedLanguages[$language]) && $this->is_available();
    }

    /**
     * Return the implementation identity used for review and index signatures.
     */
    public function source_identity(string $language = ''): string
    {
        $language = $this->base_language($language);
        if ($language === 'en') {
            return $this->englishStemmer->source_identity();
        }

        if ($language === 'nl') {
            return 'wamania/php-stemmer Dutch Porter mapped to nl; verified against snowball-data dutch_porter fixtures when Wamania is installed';
        }

        if ($language === 'ca') {
            return 'wamania/php-stemmer Catalan; verified against snowball-data catalan fixtures when Wamania is installed';
        }

        return 'unsupported Snowball language; no-op';
    }

    /**
     * Stable descriptor for stale-index detection.
     */
    public function index_signature(): string
    {
        return 'wp-fts-snowball-stemmer:v2:' . sha1(implode('|', [
            'ca=wamania-catalan',
            'en=' . WP_FTS_EnglishSnowballStemmer::VARIANT . '@snowball-data-13803281',
            'nl=wamania-dutch-porter',
        ]));
    }

    /**
     * Stem with Snowball when the package and language support are present.
     *
     * Failures are swallowed and return the original term so indexing/searching
     * does not become dependent on an optional package at runtime.
     *
     * @param string $term Normalized term text.
     * @param string $language Canonical or locale-style language tag.
     * @return string Stemmed term or original term.
     */
    public function stem(string $term, string $language): string
    {
        $language = $this->base_language($language);
        if (!isset($this->supportedLanguages[$language])) {
            return $term;
        }

        if ($language === 'en') {
            return $this->englishStemmer->stem_word($term);
        }

        if (!$this->is_available()) {
            return $term;
        }

        try {
            $stemmer = \Wamania\Snowball\StemmerFactory::create($language);
            return (string) $stemmer->stem($term);
        } catch (Throwable) {
            return $term;
        }
    }

    /**
     * Reduce a language tag to the lower-case primary language subtag.
     */
    private function base_language(string $language): string
    {
        $language = strtolower(str_replace('_', '-', trim($language)));
        $separator = strpos($language, '-');

        return $separator === false ? $language : substr($language, 0, $separator);
    }
}

/**
 * Conservative Polish suffix stemmer used until a fuller lemmatizer is present.
 *
 * The implementation is intentionally small and only removes suffixes when the
 * remaining stem stays at least three characters long.
 */
final class WP_FTS_PolishStemmer implements WP_FTS_Stemmer
{
    private string $mode;

    /**
     * @param string $mode `conservative` enables the suffix list; `none`
     *        disables Polish stemming while preserving the adapter object.
     */
    public function __construct(string $mode = 'conservative')
    {
        $this->mode = $mode;
    }

    /**
     * Stem Polish terms when enabled and leave every other language unchanged.
     *
     * @param string $term Normalized term text.
     * @param string $language Canonical or locale-style language tag.
     * @return string Conservatively stemmed term or original term.
     */
    public function stem(string $term, string $language): string
    {
        if ($this->base_language($language) !== 'pl' || $this->mode === 'none') {
            return $term;
        }

        return $this->conservative_suffix_stem($term);
    }

    /**
     * Remove one known suffix only when the remaining stem stays meaningful.
     *
     * Stopgap only. A Stempel port or Morfologik-backed lemmatizer should
     * replace this for serious Polish relevance once those data files are
     * available.
     *
     * @param string $term Normalized Polish term.
     * @return string Term with one conservative suffix removed, or original.
     */
    private function conservative_suffix_stem(string $term): string
    {
        $suffixes = [
            'owego',
            'owemu',
            'ami',
            'ach',
            'ego',
            'emu',
            'ych',
            'ymi',
            'owa',
            'owe',
            'owi',
            'cie',
            'nia',
            'om',
            'em',
            'ie',
            'iu',
        ];

        foreach ($suffixes as $suffix) {
            if (!str_ends_with($term, $suffix)) {
                continue;
            }

            $candidate = substr($term, 0, -strlen($suffix));
            if ($this->char_length($candidate) >= 3) {
                return $candidate;
            }
        }

        return $term;
    }

    /**
     * Count characters with mbstring when present and bytes otherwise.
     */
    private function char_length(string $term): int
    {
        return function_exists('mb_strlen') ? mb_strlen($term, 'UTF-8') : strlen($term);
    }

    /**
     * Reduce a language tag to the lower-case primary language subtag.
     */
    private function base_language(string $language): string
    {
        $language = strtolower(str_replace('_', '-', trim($language)));
        $separator = strpos($language, '-');

        return $separator === false ? $language : substr($language, 0, $separator);
    }
}
