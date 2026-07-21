<?php
declare(strict_types=1);

/**
 * Contract for language-aware term stemming.
 *
 * Stemmers receive normalized terms from the language pipeline and must return
 * a normalized term string. They should be conservative: returning the original
 * term is preferred when a language is unsupported.
 */
interface WP_FTS_Stemmer
{
    /**
     * Stem one normalized term for a language partition.
     *
     * @param string $term Normalized term text, not namespaced.
     * @param string $language Canonical language tag such as `en` or `pl`.
     * @return string Stemmed term, or the original term for an unsupported
     *         language.
     */
    public function stem(string $term, string $language): string;
}

/**
 * Deterministic baseline stemmer for top spoken languages without dictionary packs.
 *
 * This is intentionally smaller than a Snowball or dictionary lemmatizer. It
 * applies conservative suffix/affix rules that improve first-pass recall for
 * common forms while avoiding word-family dictionaries or query expansion.
 */
final class WP_FTS_BaselineLanguageStemmer implements WP_FTS_Stemmer
{
    /**
     * Stem one normalized term for supported baseline languages.
     *
     * @param string $term Normalized term text.
     * @param string $language Canonical or locale-style language tag.
     * @return string Baseline stem, or original term for unsupported languages.
     */
    public function stem(string $term, string $language): string
    {
        return match ($this->base_language($language)) {
            'bn' => $this->stem_bengali($term),
            'ur' => $this->stem_urdu($term),
            default => $term,
        };
    }

    /**
     * Stable descriptor for stale-index detection.
     */
    public function index_signature(): string
    {
        return 'wp-fts-baseline-language-stemmer:v10:' . sha1(implode('|', [
            'bn=suffix:classifier-plural-case:v2',
            'ur=suffix:plural-oblique:v2',
        ]));
    }

    /**
     * Urdu: keep letters intact and only strip common plural/oblique suffixes.
     */
    private function stem_urdu(string $term): string
    {
        return $this->strip_suffix_rules($term, [
            ['یاں', 'ی', 3],
            ['وں', '', 3],
            ['یں', '', 3],
            ['ات', '', 3],
            ['ے', '', 3],
        ]);
    }

    /**
     * Bengali: strip common classifier, plural, and case suffixes only.
     */
    private function stem_bengali(string $term): string
    {
        return $this->strip_suffix_rules($term, [
            ['গুলোকে', '', 2],
            ['গুলোতে', '', 2],
            ['গুলিকে', '', 2],
            ['গুলিতে', '', 2],
            ['গুলোর', '', 2],
            ['গুলির', '', 2],
            ['গুলো', '', 2],
            ['গুলি', '', 2],
            ['দেরকে', '', 3],
            ['দের', '', 3],
            ['টিকে', '', 2],
            ['টিতে', '', 2],
            ['টির', '', 2],
            ['টাকে', '', 2],
            ['টাতে', '', 2],
            ['টার', '', 2],
            ['টি', '', 2],
            ['টা', '', 2],
            ['ের', '', 3],
            ['কে', '', 3],
            ['রা', '', 3],
            ['তে', '', 3],
        ]);
    }

    /**
     * @param array<int,array{0:string,1:string,2:int,3?:string[]}> $rules
     */
    private function strip_suffix_rules(string $term, array $rules): string
    {
        foreach ($rules as $rule) {
            [$suffix, $replacement, $minStemLength] = $rule;
            $protectedEndings = $rule[3] ?? [];
            if (!str_ends_with($term, $suffix)) {
                continue;
            }
            if ($this->has_any_suffix($term, $protectedEndings)) {
                continue;
            }

            $candidate = substr($term, 0, -strlen($suffix)) . $replacement;
            if ($this->char_length($candidate) >= $minStemLength) {
                return $candidate;
            }
        }

        return $term;
    }

    /**
     * @param string[] $suffixes
     */
    private function has_any_suffix(string $term, array $suffixes): bool
    {
        foreach ($suffixes as $suffix) {
            if ($suffix !== '' && str_ends_with($term, $suffix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Count UTF-8 characters without treating multibyte scripts as bytes.
     */
    private function char_length(string $term): int
    {
        return WP_FTS_Utf8::length($term);
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

/** Adapts a language-aware user callable to the stemmer interface. */
final class WP_FTS_CallbackStemmer implements WP_FTS_Stemmer
{
    /** @var callable */
    private $callback;

    /**
     * @param callable $callback Function accepting `($term, $language)` and
     *        returning one unpadded non-empty replacement string.
     */
    public function __construct(callable $callback)
    {
        $this->callback = $callback;
    }

    /**
     * Run the configured callback with the current two-argument contract.
     *
     * @param string $term Normalized term text.
     * @param string $language Canonical language tag.
     * @return string Exact replacement term returned by the callback.
     */
    public function stem(string $term, string $language): string
    {
        $stemmed = ($this->callback)($term, $language);
        if (!is_string($stemmed)) {
            throw new UnexpectedValueException('A stemmer callback must return a string.');
        }
        if ($stemmed === '' || trim($stemmed) !== $stemmed) {
            throw new UnexpectedValueException('A stemmer callback must return an unpadded non-empty string.');
        }

        return $stemmed;
    }
}

/**
 * Uses the required Wamania Snowball package for languages verified by tests.
 *
 * Unsupported languages are no-ops. The allowlist is intentionally narrow so
 * enabling stemming does not silently switch to algorithms that diverge from
 * the bundled Snowball compliance data.
 */
final class WP_FTS_SnowballStemmer implements WP_FTS_Stemmer
{
    /** @var array<string,bool> */
    private array $supportedLanguages;
    private WP_FTS_EnglishSnowballStemmer $englishStemmer;
    private WP_FTS_ArabicSnowballStemmer $arabicStemmer;
    private WP_FTS_SpanishSnowballStemmer $spanishStemmer;
    private WP_FTS_FrenchSnowballStemmer $frenchStemmer;
    private WP_FTS_PortugueseSnowballStemmer $portugueseStemmer;
    private WP_FTS_IndonesianSnowballStemmer $indonesianStemmer;
    private WP_FTS_HindiSnowballStemmer $hindiStemmer;
    private \Wamania\Snowball\Stemmer\Stemmer $catalanStemmer;
    private \Wamania\Snowball\Stemmer\Stemmer $dutchStemmer;

    /**
     * Initialize the set of Snowball languages accepted by this adapter.
     */
    public function __construct(
        ?WP_FTS_EnglishSnowballStemmer $englishStemmer = null,
        ?WP_FTS_SpanishSnowballStemmer $spanishStemmer = null,
        ?WP_FTS_FrenchSnowballStemmer $frenchStemmer = null,
        ?WP_FTS_PortugueseSnowballStemmer $portugueseStemmer = null,
        ?WP_FTS_IndonesianSnowballStemmer $indonesianStemmer = null,
        ?WP_FTS_HindiSnowballStemmer $hindiStemmer = null,
        ?WP_FTS_ArabicSnowballStemmer $arabicStemmer = null
    )
    {
        if (!class_exists(\Wamania\Snowball\StemmerFactory::class)) {
            throw new LogicException(
                'WP_FTS_SnowballStemmer requires wamania/php-stemmer. Install Composer dependencies before constructing the analyzer.'
            );
        }

        // Expose only implementations that match the official Snowball
        // fixtures exactly. Arabic, English, Spanish, French, Hindi,
        // Portuguese, and Indonesian are local generated Snowball ports;
        // Catalan and Dutch Porter are Wamania-backed paths. Other
        // Wamania classes currently diverge from the current snowball-data
        // outputs and are treated as no-ops until their algorithms are
        // replaced or patched.
        $this->supportedLanguages = array_fill_keys([
            'ar',
            'ca',
            'en',
            'es',
            'fr',
            'hi',
            'id',
            'nl',
            'pt',
        ], true);
        $this->englishStemmer = $englishStemmer ?? new WP_FTS_EnglishSnowballStemmer();
        $this->arabicStemmer = $arabicStemmer ?? new WP_FTS_ArabicSnowballStemmer();
        $this->spanishStemmer = $spanishStemmer ?? new WP_FTS_SpanishSnowballStemmer();
        $this->frenchStemmer = $frenchStemmer ?? new WP_FTS_FrenchSnowballStemmer();
        $this->portugueseStemmer = $portugueseStemmer ?? new WP_FTS_PortugueseSnowballStemmer();
        $this->indonesianStemmer = $indonesianStemmer ?? new WP_FTS_IndonesianSnowballStemmer();
        $this->hindiStemmer = $hindiStemmer ?? new WP_FTS_HindiSnowballStemmer();
        $this->catalanStemmer = \Wamania\Snowball\StemmerFactory::create('ca');
        $this->dutchStemmer = \Wamania\Snowball\StemmerFactory::create('nl');
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

        if ($language === 'ar') {
            return $this->arabicStemmer->source_identity();
        }

        if ($language === 'es') {
            return $this->spanishStemmer->source_identity();
        }

        if ($language === 'fr') {
            return $this->frenchStemmer->source_identity();
        }

        if ($language === 'hi') {
            return $this->hindiStemmer->source_identity();
        }

        if ($language === 'pt') {
            return $this->portugueseStemmer->source_identity();
        }

        if ($language === 'id') {
            return $this->indonesianStemmer->source_identity();
        }

        if ($language === 'nl') {
            return 'wamania/php-stemmer Dutch Porter mapped to nl; verified against snowball-data dutch_porter fixtures';
        }

        if ($language === 'ca') {
            return 'wamania/php-stemmer Catalan; verified against snowball-data catalan fixtures';
        }

        return 'unsupported Snowball language; no-op';
    }

    /**
     * Stable descriptor for stale-index detection.
     */
    public function index_signature(): string
    {
        return 'wp-fts-snowball-stemmer:v8:' . sha1(implode('|', [
            'ar=' . WP_FTS_ArabicSnowballStemmer::VARIANT . '@snowball-data-arabic-9196214-gzip',
            'ca=wamania-catalan',
            'en=' . WP_FTS_EnglishSnowballStemmer::VARIANT . '@snowball-data-13803281',
            'es=' . WP_FTS_SpanishSnowballStemmer::VARIANT . '@snowball-data-spanish-28378',
            'fr=' . WP_FTS_FrenchSnowballStemmer::VARIANT . '@snowball-data-french-21653',
            'hi=' . WP_FTS_HindiSnowballStemmer::VARIANT . '@snowball-data-hindi-65118',
            'id=' . WP_FTS_IndonesianSnowballStemmer::VARIANT . '@snowball-data-indonesian-64586',
            'nl=wamania-dutch-porter',
            'pt=' . WP_FTS_PortugueseSnowballStemmer::VARIANT . '@snowball-data-portuguese-32016',
        ]));
    }

    /**
     * Stem one supported language with its verified Snowball implementation.
     *
     * @param string $term Normalized term text.
     * @param string $language Canonical or locale-style language tag.
     * @return string Stemmed term, or the original term for an unsupported
     *         language.
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

        if ($language === 'ar') {
            return $this->arabicStemmer->stem_word($term);
        }

        if ($language === 'es') {
            return $this->spanishStemmer->stem_word($term);
        }

        if ($language === 'fr') {
            return $this->frenchStemmer->stem_word($term);
        }

        if ($language === 'hi') {
            return $this->hindiStemmer->stem_word($term);
        }

        if ($language === 'pt') {
            return $this->portugueseStemmer->stem_word($term);
        }

        if ($language === 'id') {
            return $this->indonesianStemmer->stem_word($term);
        }

        return match ($language) {
            'ca' => $this->catalanStemmer->stem($term),
            'nl' => $this->dutchStemmer->stem($term),
            default => throw new LogicException("Missing Snowball implementation for supported language {$language}."),
        };
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

/** Polish stemmer with bounded suffix-only rules. */
final class WP_FTS_PolishStemmer implements WP_FTS_Stemmer
{
    /**
     * Stem Polish terms and leave every other language unchanged.
     *
     * @param string $term Normalized term text.
     * @param string $language Canonical or locale-style language tag.
     * @return string Stemmed term or original term.
     */
    public function stem(string $term, string $language): string
    {
        if ($this->base_language($language) !== 'pl') {
            return $term;
        }

        return $this->conservative_suffix_stem($term);
    }

    /**
     * Return a stable descriptor for analyzer/index stale-document checks.
     */
    public function index_signature(): string
    {
        return 'wp-fts-polish-stemmer:conservative';
    }

    /**
     * Remove one known suffix only when the remaining stem stays meaningful.
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
     * Count UTF-8 characters without treating multibyte scripts as bytes.
     */
    private function char_length(string $term): int
    {
        return WP_FTS_Utf8::length($term);
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
