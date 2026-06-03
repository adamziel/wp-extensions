<?php
declare(strict_types=1);

interface WP_FTS_Stemmer
{
    public function stem(string $term, string $language): string;
}

final class WP_FTS_NoopStemmer implements WP_FTS_Stemmer
{
    public function stem(string $term, string $language): string
    {
        return $term;
    }
}

final class WP_FTS_CallbackStemmer implements WP_FTS_Stemmer
{
    /** @var callable */
    private $callback;
    private bool $passesLanguage;

    public function __construct(callable $callback)
    {
        $this->callback = $callback;
        $this->passesLanguage = $this->accepts_language($callback);
    }

    public function stem(string $term, string $language): string
    {
        return (string) (
            $this->passesLanguage
                ? ($this->callback)($term, $language)
                : ($this->callback)($term)
        );
    }

    private function accepts_language(callable $callback): bool
    {
        $reflection = new ReflectionFunction(Closure::fromCallable($callback));

        // Preserve the legacy one-argument stemmer contract for callables such
        // as metaphone(), whose optional second parameter is not a language.
        return $reflection->isVariadic() || $reflection->getNumberOfRequiredParameters() >= 2;
    }
}

final class WP_FTS_SnowballStemmer implements WP_FTS_Stemmer
{
    /** @var array<string,bool> */
    private array $supportedLanguages;

    public function __construct()
    {
        // Expose only Wamania implementations that match the official
        // Snowball fixtures exactly. Other Wamania classes currently diverge
        // from the current snowball-data outputs and are treated as no-ops
        // until their algorithms are replaced or patched.
        $this->supportedLanguages = array_fill_keys([
            'ca',
            'nl',
        ], true);
    }

    public function is_available(): bool
    {
        return class_exists('\\Wamania\\Snowball\\StemmerFactory');
    }

    public function supports_language(string $language): bool
    {
        return isset($this->supportedLanguages[$this->base_language($language)]);
    }

    public function stem(string $term, string $language): string
    {
        $language = $this->base_language($language);
        if (!isset($this->supportedLanguages[$language]) || !$this->is_available()) {
            return $term;
        }

        try {
            $stemmer = \Wamania\Snowball\StemmerFactory::create($language);
            return (string) $stemmer->stem($term);
        } catch (Throwable) {
            return $term;
        }
    }

    private function base_language(string $language): string
    {
        $language = strtolower(str_replace('_', '-', trim($language)));
        $separator = strpos($language, '-');

        return $separator === false ? $language : substr($language, 0, $separator);
    }
}

final class WP_FTS_PolishStemmer implements WP_FTS_Stemmer
{
    private string $mode;

    public function __construct(string $mode = 'conservative')
    {
        $this->mode = $mode;
    }

    public function stem(string $term, string $language): string
    {
        if ($this->base_language($language) !== 'pl' || $this->mode === 'none') {
            return $term;
        }

        return $this->conservative_suffix_stem($term);
    }

    /**
     * Stopgap only. A Stempel port or Morfologik-backed lemmatizer should replace
     * this for serious Polish relevance once those data files are available.
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

    private function char_length(string $term): int
    {
        return function_exists('mb_strlen') ? mb_strlen($term, 'UTF-8') : strlen($term);
    }

    private function base_language(string $language): string
    {
        $language = strtolower(str_replace('_', '-', trim($language)));
        $separator = strpos($language, '-');

        return $separator === false ? $language : substr($language, 0, $separator);
    }
}
