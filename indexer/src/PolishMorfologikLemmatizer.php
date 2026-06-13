<?php
declare(strict_types=1);

/**
 * Backward-compatible Polish lemmatizer facade for the generic lemma-pack runtime.
 *
 * Existing callers can keep using the Polish option aliases and class name.
 * The facade only accepts packs whose manifest language resolves to Polish.
 */
final class WP_FTS_PolishMorfologikLemmatizer implements WP_FTS_Stemmer
{
    private function __construct(private WP_FTS_LanguageLemmaPack $pack)
    {
    }

    /**
     * Load a Polish lemmatizer from one manifest file.
     */
    public static function from_manifest_file(string $manifestPath, ?WP_FTS_AnalyzerPackValidator $validator = null): self
    {
        return new self(WP_FTS_LanguageLemmaPack::from_manifest_file($manifestPath, $validator, 'pl'));
    }

    /**
     * Try to load a Polish lemmatizer from the public analyzer option shape.
     */
    public static function from_pack_option(mixed $option): ?self
    {
        $pack = WP_FTS_LanguageLemmaPack::from_pack_option(
            $option,
            'pl',
            WP_FTS_AnalyzerPackValidator::default_polish_fixture_manifest()
        );

        return $pack === null ? null : new self($pack);
    }

    /**
     * Lemmatize one normalized Polish token.
     */
    public function stem(string $term, string $language): string
    {
        return $this->pack->stem($term, $language);
    }

    /**
     * Return a stable analyzer signature component for stale-document checks.
     */
    public function index_signature(): string
    {
        return $this->pack->index_signature();
    }

    /**
     * Expose pack identity for tests and diagnostics.
     */
    public function pack_id(): string
    {
        return $this->pack->pack_id();
    }

    /**
     * Expose the manifest language for tests and diagnostics.
     */
    public function language(): string
    {
        return $this->pack->language();
    }

    /**
     * Expose fixture-only status for tests and diagnostics.
     */
    public function is_fixture_only(): bool
    {
        return $this->pack->is_fixture_only();
    }
}
