<?php
declare(strict_types=1);

/**
 * Opt-in dictionary lemmatizer backed by a validated local analyzer pack.
 *
 * The adapter consumes normalized surface-to-lemma runtime rows. The legacy
 * `stem()` API still no-ops unsupported language partitions, ambiguous
 * surfaces, and missing forms, while `analyze()` exposes all pack-backed lemma
 * candidates for callers that can treat them as alternatives.
 */
final class WP_FTS_LanguageLemmaPack implements WP_FTS_Stemmer
{
    private const MAX_CACHED_LOOKUPS = 512;

    /** @var array<string,string[]> */
    private array $lemmasBySurface = [];
    /** @var array<string,string[]> */
    private array $lookupCache = [];
    /** @var string[] */
    private array $lookupCacheOrder = [];
    /** @var array{term:string,candidate_files:int,files_opened:int,lines_read:int,bytes_loaded:int,modes:string[]} */
    private array $lastLookupStats = [
        'term' => '',
        'candidate_files' => 0,
        'files_opened' => 0,
        'lines_read' => 0,
        'bytes_loaded' => 0,
        'modes' => [],
    ];
    private bool $lazy;
    private string $indexSignature;
    private string $packLanguage;
    private int $runtimeFileCount;
    private int $lookupBlockCount;
    private int $runtimeLookupBytes;
    private int $eagerRuntimeRows;
    private int $eagerRuntimeBytes;

    /**
     * @param array<string,mixed> $validation Result from WP_FTS_AnalyzerPackValidator::validate().
     */
    private function __construct(
        private array $validation,
        bool $lazy,
        private WP_FTS_AnalyzerPackValidator $validator
    )
    {
        $this->lazy = $lazy;
        $this->packLanguage = self::base_language((string) $validation['manifest']['language']);
        $this->runtimeFileCount = count($validation['runtime_files']);
        $this->runtimeLookupBytes = (int) ($validation['runtime_lookup_bytes'] ?? 0);
        $this->eagerRuntimeRows = $lazy ? 0 : (int) ($validation['runtime_rows'] ?? 0);
        $this->eagerRuntimeBytes = $lazy ? 0 : (int) ($validation['runtime_decoded_bytes'] ?? 0);
        $this->lookupBlockCount = 0;
        foreach ($validation['runtime_files'] as $file) {
            $this->lookupBlockCount += isset($file['lookup']['blocks']) && is_array($file['lookup']['blocks'])
                ? count($file['lookup']['blocks'])
                : 0;
        }
        if ($lazy) {
            self::assert_indexed_runtime_files($validation);
        } else {
            $this->build_eager_lookup($validation['rows']);
            // The eager map is the retained runtime representation. Keeping the
            // validator's row objects as well would nearly double peak residency
            // for the largest accepted fixture.
            $this->validation['rows'] = [];
        }

        $this->indexSignature = $this->build_index_signature($validation);
    }

    /**
     * Load a lemmatizer from one manifest file.
     *
     * A configured admission must have preflighted this physical manifest. It
     * pins the manifest generation and owns aggregate eager-map accounting.
     */
    public static function from_manifest_file(
        string $manifestPath,
        ?WP_FTS_AnalyzerPackValidator $validator = null,
        ?string $expectedLanguage = null,
        ?WP_FTS_ConfiguredLemmaPackAdmission $admission = null
    ): self {
        WP_FTS_Analyzer_Config_Limits::assert_path($manifestPath, 'Lemma-pack manifest path');
        if ($expectedLanguage !== null && strlen($expectedLanguage) > WP_FTS_Analyzer_Config_Limits::MAX_LANGUAGE_BYTES) {
            throw new WP_FTS_Analyzer_Config_Limit_Exceeded(
                'language_bytes',
                'Expected lemma-pack language exceeds the 64-byte limit.'
            );
        }
        $validator ??= new WP_FTS_AnalyzerPackValidator();
        $expectedManifestSha256 = $admission?->expected_manifest_sha256($manifestPath);
        $metadata = $validator->validate_metadata($manifestPath, false, $expectedManifestSha256);
        self::assert_expected_language($metadata, $expectedLanguage);
        $eager = WP_FTS_AnalyzerPackValidator::manifest_can_use_eager_fixture_storage($metadata['manifest'])
            && self::runtime_physical_bytes($metadata)
                <= WP_FTS_LemmaPackLimits::MAX_EAGER_FIXTURE_RUNTIME_BYTES
                    + WP_FTS_LemmaPackLimits::MAX_EAGER_FIXTURE_RUNTIME_FRAMING_BYTES;
        if ($eager) {
            $eagerRuntimeBytes = $admission !== null
                ? $admission->reserve_eager_pack($manifestPath, (int) $metadata['runtime_rows'])
                : WP_FTS_LemmaPackLimits::MAX_EAGER_FIXTURE_RUNTIME_BYTES;
            try {
                // The caller may intentionally use a smaller collection cap for
                // full-pack validation. The product's fixture-only eager boundary
                // is independent of that diagnostic knob and also has a decoded
                // byte ceiling, so use the contract limits for this one attempt.
                $validation = (new WP_FTS_AnalyzerPackValidator(
                    WP_FTS_LemmaPackLimits::MAX_EAGER_FIXTURE_ROWS
                ))->validate(
                    $manifestPath,
                    true,
                    $eagerRuntimeBytes,
                    $expectedManifestSha256
                );
            } catch (WP_FTS_Analyzer_Config_Limit_Exceeded $error) {
                if ($error->reason_code !== 'eager_fixture_bytes') {
                    throw $error;
                }
                if ($admission !== null) {
                    WP_FTS_ConfiguredLemmaPackAdmission::throw_eager_runtime_bytes_exceeded();
                }
                $validation = null;
            }
            if (is_array($validation)) {
                self::assert_expected_language($validation, $expectedLanguage);
                $pack = new self($validation, false, $validator);
                $admission?->consume_eager_pack($manifestPath, $pack);

                return $pack;
            }
        }

        return new self($metadata, true, $validator);
    }

    /**
     * Try to load a lemmatizer from the public analyzer option shape.
     *
     * Missing or structurally invalid packs return null so callers can use the
     * language's existing analyzer path. Runtime bytes are attested lazily;
     * corruption discovered after construction fails closed instead of silently
     * changing analyzer output under the pack's healthy index signature. An
     * optional configured admission applies the same preflight pin and shared
     * eager allowance as direct manifest construction.
     */
    public static function from_pack_option(
        mixed $option,
        ?string $expectedLanguage = null,
        ?string $defaultManifestPath = null,
        ?WP_FTS_ConfiguredLemmaPackAdmission $admission = null
    ): ?self {
        $manifestPath = self::manifest_path_from_option($option, $defaultManifestPath);
        if ($manifestPath === null) {
            return null;
        }

        try {
            return self::from_manifest_file(
                $manifestPath,
                null,
                $expectedLanguage,
                $admission
            );
        } catch (WP_FTS_Analyzer_Config_Limit_Exceeded $error) {
            throw $error;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Lemmatize one normalized token for the pack language.
     */
    public function stem(string $term, string $language): string
    {
        if (self::base_language($language) !== $this->packLanguage) {
            return $term;
        }

        $lemmas = $this->lemmas_for_term($term);
        if (count($lemmas) !== 1) {
            return $term;
        }

        return $lemmas[0];
    }

    /**
     * Return every pack-backed lemma candidate for one normalized token.
     *
     * Missing forms and unsupported language partitions return the original term
     * so callers can use this as a drop-in expansion API. Ambiguous pack rows are
     * returned as alternatives, with an exact normalized lemma ordered first when
     * the pack contains one.
     *
     * @return array<int,array{term:string,rank:int,source:string}>
     */
    public function analyze(string $term, string $language): array
    {
        return $this->analyze_many([$term], $language)[$term];
    }

    /**
     * Return pack-backed analyses for many normalized tokens.
     *
     * Distinct tokens are routed to their one candidate shard and grouped by
     * lookup block before any payload is read. Input order therefore cannot
     * amplify indexed runtime I/O. Results are keyed by the original term.
     *
     * @param string[] $terms
     * @return array<string,array<int,array{term:string,rank:int,source:string}>>
     */
    public function analyze_many(array $terms, string $language): array
    {
        return $this->analyze_many_filtered(
            $terms,
            $language,
            WP_FTS_Analysis_Limits::MAX_DOCUMENT_OCCURRENCES,
            null,
            null
        );
    }

    /**
     * Analyze a bounded token batch using the pipeline's final term filters.
     *
     * Rejected dictionary candidates are never retained, and one analysis-row
     * ceiling is threaded across every shard. Original lemma counts are kept
     * separately so ambiguity and exact-match ranking remain unchanged.
     *
     * @param string[] $terms
     * @param callable(string,string):bool $acceptCandidate
     * @param callable(string,int):bool $rejectAmbiguousSurface
     * @return array<string,array<int,array{term:string,rank:int,source:string}>>
     */
    public function analyze_many_for_pipeline(
        array $terms,
        string $language,
        int $maxAnalyses,
        callable $acceptCandidate,
        callable $rejectAmbiguousSurface
    ): array {
        return $this->analyze_many_filtered(
            $terms,
            $language,
            $maxAnalyses,
            $acceptCandidate,
            $rejectAmbiguousSurface
        );
    }

    /**
     * Share candidate filtering and the aggregate analysis ceiling across the
     * public single-purpose batch entry points.
     *
     * @param string[] $terms
     * @param callable(string,string):bool|null $acceptCandidate
     * @param callable(string,int):bool|null $rejectAmbiguousSurface
     * @return array<string,array<int,array{term:string,rank:int,source:string}>>
     */
    private function analyze_many_filtered(
        array $terms,
        string $language,
        int $maxAnalyses,
        ?callable $acceptCandidate,
        ?callable $rejectAmbiguousSurface
    ): array {
        if (count($terms) > WP_FTS_Analysis_Limits::MAX_DOCUMENT_OCCURRENCES || $maxAnalyses < 0) {
            throw new WP_FTS_Analysis_Limit_Exceeded(
                'occurrences',
                'Lemma analysis input exceeds the 20,000-occurrence limit.'
            );
        }
        $uniqueTerms = [];
        foreach ($terms as $term) {
            if (!is_string($term)) {
                throw new WP_FTS_Analysis_Limit_Exceeded(
                    'occurrence_shape',
                    'Lemma analysis terms must be strings.'
                );
            }
            WP_FTS_Analysis_Limits::assert_lexical_run_bytes(strlen($term));
            $uniqueTerms["\0" . $term] = $term;
            if (count($uniqueTerms) > WP_FTS_Analysis_Limits::MAX_DOCUMENT_DISTINCT_SURFACES) {
                throw new WP_FTS_Analysis_Limit_Exceeded(
                    'distinct_surfaces',
                    'Lemma analysis exceeds the 4,096-distinct-surface limit.'
                );
            }
        }

        $analysesByTerm = [];
        if (self::base_language($language) !== $this->packLanguage) {
            foreach ($uniqueTerms as $term) {
                $analysesByTerm[$term] = $acceptCandidate === null || $acceptCandidate($term, $term) === true
                    ? [$this->analysis_row($term, 0, 'original')]
                    : [];
                $maxAnalyses -= count($analysesByTerm[$term]);
                $this->assert_remaining_analyses($maxAnalyses);
            }
            return $analysesByTerm;
        }

        $lookupTerms = [];
        $detailsByTerm = [];
        foreach ($uniqueTerms as $term) {
            if (WP_FTS_TermNamespace::term_key_fits($term, $this->packLanguage)) {
                $lookupTerms[] = $term;
                continue;
            }
            $detailsByTerm[$term] = [
                'lemmas' => [],
                'lemma_count' => 0,
                'has_exact' => false,
            ];
        }
        if ($lookupTerms !== []) {
            $detailsByTerm += $this->lemma_details_for_terms(
                $lookupTerms,
                $acceptCandidate,
                $maxAnalyses,
                $rejectAmbiguousSurface
            );
        }
        foreach ($uniqueTerms as $term) {
            $details = $detailsByTerm[$term] ?? [
                'lemmas' => [],
                'lemma_count' => 0,
                'has_exact' => false,
            ];
            if (
                $rejectAmbiguousSurface !== null
                && $rejectAmbiguousSurface($term, $details['lemma_count']) === true
            ) {
                $analysesByTerm[$term] = [];
                continue;
            }

            $lemmas = $details['lemmas'];
            if ($lemmas === []) {
                $analysesByTerm[$term] = $details['lemma_count'] === 0
                    && ($acceptCandidate === null || $acceptCandidate($term, $term) === true)
                    ? [$this->analysis_row($term, 0, 'original')]
                    : [];
                $maxAnalyses -= count($analysesByTerm[$term]);
                $this->assert_remaining_analyses($maxAnalyses);
                continue;
            }

            $analyses = [];
            foreach ($lemmas as $lemma) {
                $analyses[] = $this->analysis_row(
                    $lemma,
                    !$details['has_exact'] || $lemma === $term ? 0 : 1,
                    'lemma-pack'
                );
            }
            $analysesByTerm[$term] = $analyses;
            $maxAnalyses -= count($analyses);
            $this->assert_remaining_analyses($maxAnalyses);
        }

        return $analysesByTerm;
    }

    /**
     * Return a stable analyzer signature component for stale-document checks.
     */
    public function index_signature(): string
    {
        return $this->indexSignature;
    }

    /**
     * Expose the manifest language for tests and diagnostics.
     */
    public function language(): string
    {
        return (string) $this->validation['manifest']['language'];
    }

    /**
     * Expose the base manifest language used for runtime routing.
     */
    public function base_language_code(): string
    {
        return $this->packLanguage;
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

    /** Number of runtime shards retained by this configured pack. */
    public function runtime_file_count(): int
    {
        return $this->runtimeFileCount;
    }

    /** Number of seek metadata blocks retained by this configured pack. */
    public function lookup_block_count(): int
    {
        return $this->lookupBlockCount;
    }

    /** Physical runtime and sidecar bytes admitted for this pack. */
    public function runtime_lookup_bytes(): int
    {
        return $this->runtimeLookupBytes;
    }

    /** Rows retained by this pack's eager map; lazy packs retain zero. */
    public function eager_runtime_rows(): int
    {
        return $this->eagerRuntimeRows;
    }

    /** Decoded bytes retained by this pack's eager map; lazy packs retain zero. */
    public function eager_runtime_bytes(): int
    {
        return $this->eagerRuntimeBytes;
    }

    /**
     * Expose the last lazy lookup shape for deterministic performance tests.
     *
     * @return array{term:string,candidate_files:int,files_opened:int,lines_read:int,bytes_loaded:int,modes:string[]}
     */
    public function last_lookup_stats(): array
    {
        return $this->lastLookupStats;
    }

    /** Report complete-file attestation work performed by this pack instance. */
    public function digest_attestation_stats(): array
    {
        return $this->validator->digest_attestation_stats();
    }

    /**
     * Resolve the supported public option shapes without loading pack content.
     */
    public static function manifest_path_from_option(mixed $option, ?string $defaultManifestPath = null): ?string
    {
        WP_FTS_Analyzer_Config_Limits::assert_pack_option($option, 'Lemma-pack option');
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
            if ($defaultManifestPath !== null) {
                WP_FTS_Analyzer_Config_Limits::assert_path($defaultManifestPath, 'Default lemma-pack manifest path');
            }
            return $defaultManifestPath;
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
    private static function assert_expected_language(array $validation, ?string $expectedLanguage): void
    {
        if ($expectedLanguage === null || trim($expectedLanguage) === '') {
            return;
        }

        $expected = self::base_language($expectedLanguage);
        $actual = self::base_language((string) $validation['manifest']['language']);
        if ($expected !== $actual) {
            throw new RuntimeException("Analyzer pack language {$actual} does not match requested language {$expected}.");
        }
    }

    /**
     * Reject obviously oversized fixture payloads before a full digest or gzip
     * expansion. The small gzip framing allowance covers an incompressible
     * decoded payload at the exact 8 MiB eager boundary.
     *
     * @param array<string,mixed> $validation
     */
    private static function runtime_physical_bytes(array $validation): int
    {
        $bytes = 0;
        foreach ($validation['runtime_files'] as $file) {
            $size = @filesize((string) $file['path']);
            if (!is_int($size) || $size < 0) {
                return PHP_INT_MAX;
            }
            $bytes += $size;
            if (
                $bytes > WP_FTS_LemmaPackLimits::MAX_EAGER_FIXTURE_RUNTIME_BYTES
                    + WP_FTS_LemmaPackLimits::MAX_EAGER_FIXTURE_RUNTIME_FRAMING_BYTES
            ) {
                return $bytes;
            }
        }

        return $bytes;
    }

    /**
     * Non-eager packs must make every token lookup one bounded sidecar seek.
     * Tiny fixture-only packs are the sole unindexed exception because their
     * complete reviewed row set is loaded once during construction.
     *
     * @param array<string,mixed> $validation
     */
    private static function assert_indexed_runtime_files(array $validation): void
    {
        foreach ($validation['runtime_files'] as $relativePath => $file) {
            if (isset($file['lookup']) && is_array($file['lookup'])) {
                continue;
            }

            throw new RuntimeException(
                "Non-eager lemma pack runtime shard {$relativePath} requires a validated lookup sidecar. "
                . 'Only fixture-only packs with at most 50,000 rows and 8 MiB of decoded runtime data may use eager unindexed runtime data.'
            );
        }
    }

    /**
     * @param array<int,array{surface:string,lemma:string,file:string,line:int}> $rows
     */
    private function build_eager_lookup(array $rows): void
    {
        $lemmasBySurface = [];
        foreach ($rows as $row) {
            $lemmasBySurface[$row['surface']][$row['lemma']] = true;
        }

        foreach ($lemmasBySurface as $surface => $lemmas) {
            $lemmaList = $this->ordered_lemmas_for_surface($surface, array_keys($lemmas));
            $this->lemmasBySurface[$surface] = $lemmaList;
        }
    }

    /**
     * @return string[]
     */
    private function lemmas_for_term(string $term): array
    {
        return $this->lemmas_for_terms([$term])[$term] ?? [];
    }

    /**
     * Resolve lemma lists for callers that do not need ambiguity metadata.
     *
     * @param string[] $terms
     * @return array<string,string[]>
     */
    private function lemmas_for_terms(array $terms): array
    {
        $lemmas = [];
        foreach ($this->lemma_details_for_terms(
            $terms,
            null,
            WP_FTS_Analysis_Limits::MAX_DOCUMENT_OCCURRENCES,
            null
        ) as $term => $details) {
            $lemmas[$term] = $details['lemmas'];
        }

        return $lemmas;
    }

    /**
     * Resolve accepted lemmas while retaining original ambiguity and exactness.
     *
     * @param string[] $terms
     * @param callable(string,string):bool|null $acceptCandidate
     * @return array<string,array{lemmas:string[],lemma_count:int,has_exact:bool}>
     */
    private function lemma_details_for_terms(
        array $terms,
        ?callable $acceptCandidate,
        int $maxAcceptedLemmas,
        ?callable $rejectSurface
    ): array {
        if ($this->lazy) {
            return $this->lookup_lazy_lemma_details_many(
                $terms,
                $acceptCandidate,
                $maxAcceptedLemmas,
                $rejectSurface
            );
        }

        $details = [];
        $accepted = 0;
        foreach ($terms as $term) {
            $allLemmas = $this->lemmasBySurface[$term] ?? [];
            $lemmas = $acceptCandidate === null
                ? $allLemmas
                : array_values(array_filter(
                    $allLemmas,
                    static fn(string $lemma): bool => $acceptCandidate($lemma, $term) === true
                ));
            if ($rejectSurface !== null && $rejectSurface($term, count($allLemmas)) === true) {
                $lemmas = [];
            }
            $accepted += count($lemmas);
            if ($accepted > $maxAcceptedLemmas) {
                throw new WP_FTS_Analysis_Limit_Exceeded(
                    'occurrences',
                    "FTS analysis exceeds its {$maxAcceptedLemmas}-occurrence limit."
                );
            }
            $details[$term] = [
                'lemmas' => $lemmas,
                'lemma_count' => count($allLemmas),
                'has_exact' => in_array($term, $allLemmas, true),
            ];
        }

        return $details;
    }

    /**
     * Route a complete bounded batch through attested shards and lookup blocks.
     *
     * @param string[] $terms
     * @param callable(string,string):bool|null $acceptCandidate
     * @return array<string,array{lemmas:string[],lemma_count:int,has_exact:bool}>
     */
    private function lookup_lazy_lemma_details_many(
        array $terms,
        ?callable $acceptCandidate,
        int $maxAcceptedLemmas,
        ?callable $rejectSurface
    ): array
    {
        $this->validator->begin_digest_attestation_batch();
        $termCount = count($terms);
        $this->lastLookupStats = [
            'term' => $termCount === 1 ? (string) ($terms[0] ?? '') : "[batch:{$termCount}]",
            'candidate_files' => 0,
            'files_opened' => 0,
            'lines_read' => 0,
            'bytes_loaded' => 0,
            'modes' => [],
        ];

        $filesByIdentity = [];
        $termsByFile = [];
        $detailsByTerm = [];
        foreach ($terms as $term) {
            $detailsByTerm[$term] = [
                'lemmas' => [],
                'lemma_count' => 0,
                'has_exact' => false,
            ];
            $files = $this->candidate_runtime_files($term);
            foreach ($files as $file) {
                $identity = $this->runtime_file_identity($file);
                $filesByIdentity[$identity] = $file;
                $termsByFile[$identity][] = $term;
            }
        }
        $this->lastLookupStats['candidate_files'] = count($filesByIdentity);

        $acceptedLemmas = 0;
        foreach ($terms as $term) {
            if (isset($this->lookupCache[$term])) {
                $allLemmas = $this->lookupCache[$term];
                $lemmas = $acceptCandidate === null
                    ? $allLemmas
                    : array_values(array_filter(
                        $allLemmas,
                        static fn(string $lemma): bool => $acceptCandidate($lemma, $term) === true
                    ));
                if ($rejectSurface !== null && $rejectSurface($term, count($allLemmas)) === true) {
                    $lemmas = [];
                }
                $acceptedLemmas += count($lemmas);
                if ($acceptedLemmas > $maxAcceptedLemmas) {
                    throw new WP_FTS_Analysis_Limit_Exceeded(
                        'occurrences',
                        "FTS analysis exceeds its {$maxAcceptedLemmas}-occurrence limit."
                    );
                }
                $detailsByTerm[$term] = [
                    'lemmas' => $lemmas,
                    'lemma_count' => count($allLemmas),
                    'has_exact' => in_array($term, $allLemmas, true),
                ];
                $this->record_lookup_mode('memory-cache');
            }
        }

        foreach ($filesByIdentity as $identity => $file) {
            try {
                $attestation = $this->open_attested_runtime_file($file);
            } catch (Throwable $e) {
                $this->record_lookup_mode('block-index-failed');
                throw new RuntimeException('Lemma pack candidate integrity verification failed.', 0, $e);
            }
            try {
                $uncachedTerms = [];
                foreach ($termsByFile[$identity] as $term) {
                    if (!isset($this->lookupCache[$term])) {
                        $uncachedTerms[] = $term;
                    }
                }
                if ($uncachedTerms === []) {
                    continue;
                }

                $remainingAcceptedLemmas = max(0, $maxAcceptedLemmas - $acceptedLemmas);
                $fileResult = $this->lookup_terms_in_runtime_file(
                    $uncachedTerms,
                    $file,
                    $acceptCandidate,
                    $remainingAcceptedLemmas,
                    $rejectSurface,
                    $attestation
                );
                foreach ($fileResult['lemmas_by_term'] as $term => $fileLemmas) {
                    $term = (string) $term;
                    $combined = [];
                    foreach ($detailsByTerm[$term]['lemmas'] as $lemma) {
                        $combined[$lemma] = true;
                    }
                    foreach ($fileLemmas as $lemma => $_) {
                        $combined[$lemma] = true;
                        WP_FTS_LemmaPackLimits::assert_surface_lemma_count($term, count($combined));
                    }
                    $acceptedLemmas -= count($detailsByTerm[$term]['lemmas']);
                    $detailsByTerm[$term] = [
                        'lemmas' => array_keys($combined),
                        'lemma_count' => $detailsByTerm[$term]['lemma_count']
                            + (int) ($fileResult['lemma_counts_by_term'][$term] ?? 0),
                        'has_exact' => $detailsByTerm[$term]['has_exact']
                            || (bool) ($fileResult['has_exact_by_term'][$term] ?? false),
                    ];
                    $acceptedLemmas += count($detailsByTerm[$term]['lemmas']);
                    if ($acceptedLemmas > $maxAcceptedLemmas) {
                        throw new WP_FTS_Analysis_Limit_Exceeded(
                            'occurrences',
                            "FTS analysis exceeds its {$maxAcceptedLemmas}-occurrence limit."
                        );
                    }
                }
            } finally {
                fclose($attestation['runtime']);
                fclose($attestation['lookup']);
            }
        }

        foreach ($terms as $term) {
            if (!isset($this->lookupCache[$term])) {
                $detailsByTerm[$term]['lemmas'] = $this->ordered_lemmas_for_surface(
                    $term,
                    $detailsByTerm[$term]['lemmas']
                );
                if ($acceptCandidate === null) {
                    $this->cache_lookup($term, $detailsByTerm[$term]['lemmas']);
                }
            }
        }

        return $detailsByTerm;
    }

    /**
     * @param string[] $lemmas
     * @return string[]
     */
    private function ordered_lemmas_for_surface(string $surface, array $lemmas): array
    {
        return WP_FTS_LemmaPackLimits::ordered_lemmas_for_surface($surface, $lemmas);
    }

    /**
     * @return array{term:string,rank:int,source:string}
     */
    private function analysis_row(string $term, int $rank, string $source): array
    {
        return [
            'term' => $term,
            'rank' => max(0, $rank),
            'source' => $source,
        ];
    }

    /** Reject as soon as one batch consumes more analysis rows than admitted. */
    private function assert_remaining_analyses(int $remaining): void
    {
        if ($remaining < 0) {
            throw new WP_FTS_Analysis_Limit_Exceeded(
                'occurrences',
                'FTS analysis exceeds its occurrence limit.'
            );
        }
    }

    /**
     * @return array<int,array{path:string,rows:int,sha256:string,compression?:string,first_surface?:string,last_surface?:string,lookup?:array<string,mixed>}>
     */
    private function candidate_runtime_files(string $term): array
    {
        $files = array_values($this->validation['runtime_files']);
        if (count($files) <= 1) {
            $file = $files[0] ?? null;
            if (!is_array($file)) {
                return [];
            }
            $first = $file['first_surface'] ?? null;
            $last = $file['last_surface'] ?? null;
            if (is_string($first) && is_string($last) && (strcmp($term, $first) < 0 || strcmp($term, $last) > 0)) {
                return [];
            }

            return [$file];
        }

        $low = 0;
        $high = count($files) - 1;
        while ($low <= $high) {
            $middle = intdiv($low + $high, 2);
            $file = $files[$middle];
            $first = (string) $file['first_surface'];
            $last = (string) $file['last_surface'];
            if (strcmp($term, $first) < 0) {
                $high = $middle - 1;
                continue;
            }
            if (strcmp($term, $last) > 0) {
                $low = $middle + 1;
                continue;
            }

            return [$file];
        }

        return [];
    }

    /**
     * Read all selected terms from one shard in one monotonic sidecar pass.
     *
     * @param string[] $terms
     * @param array{lookup:array<string,mixed>} $file
     * @param callable(string,string):bool|null $acceptCandidate
     * @param array{runtime:resource,lookup:resource,block_sha256:string[]} $attestation
     * @return array{lemmas_by_term:array<string,array<string,bool>>,lemma_counts_by_term:array<string,int>,has_exact_by_term:array<string,bool>,lines_read:int,compressed_bytes:int,decoded_bytes:int,blocks_loaded:int}
     */
    private function lookup_terms_in_runtime_file(
        array $terms,
        array $file,
        ?callable $acceptCandidate,
        int $maxAcceptedLemmas,
        ?callable $rejectSurface,
        array $attestation
    ): array
    {
        if (!isset($file['lookup']) || !is_array($file['lookup'])) {
            throw new LogicException('A non-eager lemma pack reached lookup without its required sidecar.');
        }

        $ioBefore = WP_FTS_LemmaPackLookupIndex::io_diagnostics();
        try {
            $result = WP_FTS_LemmaPackLookupIndex::lookup_many(
                $file['lookup'],
                $terms,
                $acceptCandidate,
                $maxAcceptedLemmas,
                $rejectSurface,
                $attestation
            );
        } catch (WP_FTS_Analysis_Limit_Exceeded $e) {
            throw $e;
        } catch (Throwable $e) {
            $this->record_lookup_mode('block-index-failed');
            throw new RuntimeException('Indexed lemma pack lookup failed.', 0, $e);
        }
        $ioAfter = WP_FTS_LemmaPackLookupIndex::io_diagnostics();
        $this->record_lookup_mode('block-index');
        $this->lastLookupStats['files_opened'] += max(
            0,
            $ioAfter['runtime_file_opens'] - $ioBefore['runtime_file_opens']
        );
        $this->lastLookupStats['lines_read'] += $result['lines_read'];
        $this->lastLookupStats['bytes_loaded'] += max(
            0,
            $ioAfter['decoded_payload_bytes_loaded'] - $ioBefore['decoded_payload_bytes_loaded']
        );

        return $result;
    }

    /**
     * Open the attested runtime/sidecar generation used by this lookup.
     *
     * The batch groups repeated terms before this call. Older stable generations
     * may then reuse the bounded validator cache; current-second generations are
     * hashed once in this batch and re-attested in the next. The returned open
     * descriptors and authenticated block digests remain bound through reads,
     * so neither a pathname replacement nor an in-place write can introduce an
     * unmanifested decoded block into the process cache.
     *
     * @param array{path:string,sha256:string,lookup:array{path:string,sha256:string,blocks:array<int,array{offset:int,length:int}>}} $file
     * @return array{runtime:resource,lookup:resource,block_sha256:string[]}
     */
    private function open_attested_runtime_file(array $file): array
    {
        return $this->validator->open_attested_runtime_file($file);
    }

    /**
     * Deduplicate one physical runtime/sidecar pair inside a lookup batch.
     *
     * @param array{path:string,sha256:string,lookup?:array{path:string,sha256:string}} $file
     */
    private function runtime_file_identity(array $file): string
    {
        return hash('sha256', implode("\0", [
            $file['path'],
            strtolower($file['sha256']),
            (string) ($file['lookup']['path'] ?? ''),
            strtolower((string) ($file['lookup']['sha256'] ?? '')),
        ]));
    }

    private function record_lookup_mode(string $mode): void
    {
        if (!in_array($mode, $this->lastLookupStats['modes'], true)) {
            $this->lastLookupStats['modes'][] = $mode;
        }
    }

    /**
     * @param string[] $result
     * @return string[]
     */
    private function cache_lookup(string $term, array $result): array
    {
        if (!isset($this->lookupCache[$term])) {
            $this->lookupCacheOrder[] = $term;
        }
        $this->lookupCache[$term] = $result;

        while (count($this->lookupCacheOrder) > self::MAX_CACHED_LOOKUPS) {
            $oldest = array_shift($this->lookupCacheOrder);
            if (is_string($oldest)) {
                unset($this->lookupCache[$oldest]);
            }
        }

        return $result;
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
            if (isset($file['compression'])) {
                $runtime[$relativePath]['compression'] = $file['compression'];
            }
        }
        ksort($runtime, SORT_STRING);

        $payload = [
            'contract' => 'wp-fts-language-lemma-pack',
            'version' => 1,
            'pack_id' => (string) $validation['manifest']['pack_id'],
            'pack_version' => (string) $validation['manifest']['version'],
            'language' => (string) $validation['manifest']['language'],
            'fixture_only' => (bool) $validation['manifest']['fixture_only'],
            'manifest_sha256' => (string) $validation['manifest_sha256'],
            'runtime_format' => (string) $validation['manifest']['runtime']['format'],
            'runtime' => $runtime,
        ];

        return 'wp-fts-language-lemma-pack-v1:' . sha1($this->stable_json($payload));
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
    private static function base_language(string $language): string
    {
        $language = strtolower(str_replace('_', '-', trim($language)));
        $separator = strpos($language, '-');

        return $separator === false ? $language : substr($language, 0, $separator);
    }
}
