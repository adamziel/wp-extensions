<?php
declare(strict_types=1);

/**
 * Opt-in Polish dictionary lemmatizer backed by a validated local fixture pack.
 *
 * The adapter consumes normalized runtime rows. It deliberately no-ops
 * ambiguous and missing forms so a partial fixture pack cannot over-stem terms.
 */
final class WP_FTS_PolishMorfologikLemmatizer implements WP_FTS_Stemmer
{
    /** @var array<string,string> */
    private array $lemmaBySurface = [];
    /** @var array<string,bool> */
    private array $ambiguousSurfaces = [];
    private string $indexSignature;

    /**
     * @param array<string,mixed> $validation Result from WP_FTS_AnalyzerPackValidator::validate().
     */
    private function __construct(private array $validation)
    {
        $lemmasBySurface = [];
        foreach ($validation['rows'] as $row) {
            $lemmasBySurface[$row['surface']][$row['lemma']] = true;
        }

        foreach ($lemmasBySurface as $surface => $lemmas) {
            $lemmaList = array_keys($lemmas);
            sort($lemmaList, SORT_STRING);
            if (count($lemmaList) === 1) {
                $this->lemmaBySurface[$surface] = $lemmaList[0];
                continue;
            }

            $this->ambiguousSurfaces[$surface] = true;
        }

        $this->indexSignature = $this->build_index_signature($validation);
    }

    /**
     * Load a lemmatizer from one manifest file.
     */
    public static function from_manifest_file(string $manifestPath, ?WP_FTS_AnalyzerPackValidator $validator = null): self
    {
        $validator ??= new WP_FTS_AnalyzerPackValidator();

        return new self($validator->validate($manifestPath));
    }

    /**
     * Try to load a lemmatizer from the public analyzer option shape.
     *
     * Invalid or missing packs return null so callers can fall back to the
     * conservative Polish suffix stemmer without making indexing/search fatal.
     */
    public static function from_pack_option(mixed $option): ?self
    {
        $manifestPath = self::manifest_path_from_option($option);
        if ($manifestPath === null) {
            return null;
        }

        try {
            return self::from_manifest_file($manifestPath);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Lemmatize one normalized Polish token.
     */
    public function stem(string $term, string $language): string
    {
        if ($this->base_language($language) !== 'pl') {
            return $term;
        }

        if (isset($this->ambiguousSurfaces[$term])) {
            return $term;
        }

        return $this->lemmaBySurface[$term] ?? $term;
    }

    /**
     * Return a stable analyzer signature component for stale-document checks.
     */
    public function index_signature(): string
    {
        return $this->indexSignature;
    }

    /**
     * Expose pack identity for tests and diagnostics.
     */
    public function pack_id(): string
    {
        return (string) $this->validation['manifest']['pack_id'];
    }

    /**
     * Expose fixture-only status for tests and diagnostics.
     */
    public function is_fixture_only(): bool
    {
        return (bool) $this->validation['manifest']['fixture_only'];
    }

    private static function manifest_path_from_option(mixed $option): ?string
    {
        if ($option === false || $option === null) {
            return null;
        }

        if (is_string($option)) {
            $option = trim($option);
            if ($option === '' || in_array(strtolower($option), ['0', 'false', 'no', 'off'], true)) {
                return null;
            }

            return is_dir($option) ? $option . DIRECTORY_SEPARATOR . 'manifest.json' : $option;
        }

        if ($option === true) {
            return WP_FTS_AnalyzerPackValidator::default_polish_fixture_manifest();
        }

        if (is_array($option)) {
            foreach (['manifest', 'manifest_path', 'path'] as $key) {
                if (!isset($option[$key]) || !is_scalar($option[$key])) {
                    continue;
                }
                $path = trim((string) $option[$key]);
                if ($path === '') {
                    continue;
                }

                return is_dir($path) ? $path . DIRECTORY_SEPARATOR . 'manifest.json' : $path;
            }
        }

        return null;
    }

    /**
     * @param array<string,mixed> $validation
     */
    private function build_index_signature(array $validation): string
    {
        $runtime = [];
        foreach ($validation['runtime_files'] as $relativePath => $file) {
            $runtime[$relativePath] = [
                'sha256' => $file['sha256'],
                'rows' => $file['rows'],
            ];
        }
        ksort($runtime, SORT_STRING);

        $payload = [
            'contract' => 'wp-fts-polish-morfologik-lemma-pack',
            'version' => 1,
            'pack_id' => (string) $validation['manifest']['pack_id'],
            'pack_version' => (string) $validation['manifest']['version'],
            'language' => (string) $validation['manifest']['language'],
            'fixture_only' => (bool) $validation['manifest']['fixture_only'],
            'manifest_sha256' => (string) $validation['manifest_sha256'],
            'runtime' => $runtime,
        ];

        return 'wp-fts-polish-morfologik-lemma-pack-v1:' . sha1($this->stable_json($payload));
    }

    /**
     * Encode arrays in a stable order for signatures.
     */
    private function stable_json(mixed $value): string
    {
        if (is_array($value)) {
            if (array_keys($value) !== range(0, count($value) - 1)) {
                ksort($value, SORT_STRING);
            }
            foreach ($value as $key => $child) {
                $value[$key] = $this->stable_json_value($child);
            }
        }

        return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    private function stable_json_value(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }

        if (array_keys($value) !== range(0, count($value) - 1)) {
            ksort($value, SORT_STRING);
        }
        foreach ($value as $key => $child) {
            $value[$key] = $this->stable_json_value($child);
        }

        return $value;
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
