<?php
declare(strict_types=1);

/**
 * Admits one configured set of lemma packs against its shared resource limits.
 *
 * The manifest pass runs for the complete configuration before any eager map
 * is constructed. A later construction pass uses the pinned manifest digest
 * and reserves only the still-unconsumed decoded eager allowance. Callers keep
 * control of pack lifetime: the language pipeline retains one map per physical
 * manifest, while diagnostics may release each map after validating it.
 */
final class WP_FTS_ConfiguredLemmaPackAdmission
{
    /** @var array<string,array<string,mixed>> */
    private array $resourceEnvelopes = [];
    /** @var array<string,Throwable> */
    private array $resourceEnvelopeFailures = [];
    /** @var array<string,true> */
    private array $admittedManifests = [];
    /** @var array<string,array{declared_rows:int,decoded_byte_limit:int}> */
    private array $eagerReservations = [];
    /** @var array<string,true> */
    private array $consumedEagerManifests = [];
    private int $runtimeFiles = 0;
    private int $lookupBlocks = 0;
    private int $runtimeLookupBytes = 0;
    private int $declaredEagerRows = 0;
    private int $plainEagerRuntimeBytes = 0;
    private int $remainingEagerRows = WP_FTS_LemmaPackLimits::MAX_CONFIGURED_EAGER_FIXTURE_ROWS;
    private int $remainingEagerRuntimeBytes = WP_FTS_LemmaPackLimits::MAX_CONFIGURED_EAGER_FIXTURE_RUNTIME_BYTES;

    /**
     * Inspect and, when language-compatible, charge one physical manifest.
     * Repeated aliases reuse the cached envelope and never charge it twice.
     *
     * @return array{manifest_path:string,language_matches:bool}
     */
    public function preflight_manifest(string $manifestPath, string $expectedLanguage): array
    {
        $realManifestPath = realpath($manifestPath);
        if (!is_string($realManifestPath)) {
            throw new RuntimeException('Configured lemma-pack manifest could not be resolved.');
        }

        if (isset($this->resourceEnvelopeFailures[$realManifestPath])) {
            throw $this->resourceEnvelopeFailures[$realManifestPath];
        }
        if (!isset($this->resourceEnvelopes[$realManifestPath])) {
            try {
                $this->resourceEnvelopes[$realManifestPath] = (new WP_FTS_AnalyzerPackValidator())
                    ->resource_envelope($realManifestPath);
            } catch (Throwable $error) {
                // A physical manifest has one preflight outcome for this
                // configuration. Retrying aliases could repeat I/O or admit a
                // generation repaired after aggregate accounting began.
                $this->resourceEnvelopeFailures[$realManifestPath] = $error;
                throw $error;
            }
        }
        $envelope = $this->resourceEnvelopes[$realManifestPath];
        $languageMatches = self::base_language((string) $envelope['language'])
            === self::base_language($expectedLanguage);
        if ($languageMatches && !isset($this->admittedManifests[$realManifestPath])) {
            $this->admit_resource_envelope($realManifestPath, $envelope);
        }

        return [
            'manifest_path' => $realManifestPath,
            'language_matches' => $languageMatches,
        ];
    }

    /** Return the preflight-pinned digest for one construction attempt. */
    public function expected_manifest_sha256(string $manifestPath): string
    {
        $identity = $this->preflighted_manifest_identity($manifestPath);

        return (string) $this->resourceEnvelopes[$identity]['manifest_sha256'];
    }

    /**
     * Reserve the remaining decoded allowance for one eager validation scan.
     * The allowance is consumed only after the pack has been constructed.
     */
    public function reserve_eager_pack(string $manifestPath, int $declaredRows): int
    {
        $identity = $this->admitted_manifest_identity($manifestPath);
        if (isset($this->consumedEagerManifests[$identity])) {
            throw new LogicException('A configured eager lemma pack must be reused after construction.');
        }
        if (isset($this->eagerReservations[$identity])) {
            return $this->eagerReservations[$identity]['decoded_byte_limit'];
        }
        if ($declaredRows < 0 || $declaredRows > $this->remainingEagerRows) {
            self::throw_eager_rows_exceeded();
        }
        if ($this->remainingEagerRuntimeBytes < 1) {
            self::throw_eager_runtime_bytes_exceeded();
        }

        $decodedByteLimit = min(
            WP_FTS_LemmaPackLimits::MAX_EAGER_FIXTURE_RUNTIME_BYTES,
            $this->remainingEagerRuntimeBytes
        );
        $this->eagerReservations[$identity] = [
            'declared_rows' => $declaredRows,
            'decoded_byte_limit' => $decodedByteLimit,
        ];

        return $decodedByteLimit;
    }

    /** Consume one successful eager construction without retaining its map. */
    public function consume_eager_pack(
        string $manifestPath,
        WP_FTS_LanguageLemmaPack $pack
    ): void {
        $identity = $this->admitted_manifest_identity($manifestPath);
        if (isset($this->consumedEagerManifests[$identity])) {
            return;
        }
        $reservation = $this->eagerReservations[$identity] ?? null;
        if (!is_array($reservation)) {
            throw new LogicException('Configured eager lemma-pack construction was not reserved.');
        }

        $rows = $pack->eager_runtime_rows();
        $bytes = $pack->eager_runtime_bytes();
        if ($rows !== $reservation['declared_rows'] || $rows > $this->remainingEagerRows) {
            self::throw_eager_rows_exceeded();
        }
        if ($bytes > $reservation['decoded_byte_limit'] || $bytes > $this->remainingEagerRuntimeBytes) {
            self::throw_eager_runtime_bytes_exceeded();
        }

        $this->remainingEagerRows -= $rows;
        $this->remainingEagerRuntimeBytes -= $bytes;
        $this->consumedEagerManifests[$identity] = true;
        unset($this->eagerReservations[$identity]);
    }

    /** Throw the stable configured eager-row diagnostic. */
    public static function throw_eager_rows_exceeded(): never
    {
        throw new WP_FTS_Analyzer_Config_Limit_Exceeded(
            'configured_eager_fixture_rows',
            'Configured eager fixture packs exceed the aggregate 50,000-row limit.'
        );
    }

    /** Throw the stable configured eager decoded-byte diagnostic. */
    public static function throw_eager_runtime_bytes_exceeded(): never
    {
        throw new WP_FTS_Analyzer_Config_Limit_Exceeded(
            'configured_eager_fixture_bytes',
            'Configured eager fixture packs exceed the aggregate 8 MiB decoded byte limit.'
        );
    }

    /** Charge one language-compatible resource envelope atomically. */
    private function admit_resource_envelope(string $manifestPath, array $envelope): void
    {
        $runtimeFiles = $this->runtimeFiles + (int) $envelope['runtime_files'];
        $lookupBlocks = $this->lookupBlocks + (int) $envelope['lookup_blocks'];
        $runtimeLookupBytes = $this->runtimeLookupBytes + (int) $envelope['runtime_lookup_bytes'];
        $declaredEagerRows = $this->declaredEagerRows;
        $plainEagerRuntimeBytes = $this->plainEagerRuntimeBytes;
        if ($envelope['eager_fixture_candidate'] === true) {
            // Plain eager runtimes can be charged from physical bytes before
            // construction. Compressed fixtures consume the same shared
            // decoded budget during their bounded validation scan instead.
            $declaredEagerRows += (int) $envelope['runtime_rows'];
            $plainEagerRuntimeBytes += (int) $envelope['eager_fixture_decoded_bytes'];
        }

        if ($runtimeFiles > WP_FTS_Analyzer_Config_Limits::MAX_CONFIGURED_RUNTIME_FILES
            || $lookupBlocks > WP_FTS_Analyzer_Config_Limits::MAX_CONFIGURED_LOOKUP_BLOCKS
            || $runtimeLookupBytes > WP_FTS_Analyzer_Config_Limits::MAX_CONFIGURED_RUNTIME_LOOKUP_BYTES
        ) {
            throw new WP_FTS_Analyzer_Config_Limit_Exceeded(
                'configured_pack_metadata',
                'Configured lemma packs exceed the 128-file, 16,384-block, or 32 MiB runtime envelope.'
            );
        }
        if ($declaredEagerRows > WP_FTS_LemmaPackLimits::MAX_CONFIGURED_EAGER_FIXTURE_ROWS) {
            self::throw_eager_rows_exceeded();
        }
        if ($plainEagerRuntimeBytes > WP_FTS_LemmaPackLimits::MAX_CONFIGURED_EAGER_FIXTURE_RUNTIME_BYTES) {
            self::throw_eager_runtime_bytes_exceeded();
        }

        $this->runtimeFiles = $runtimeFiles;
        $this->lookupBlocks = $lookupBlocks;
        $this->runtimeLookupBytes = $runtimeLookupBytes;
        $this->declaredEagerRows = $declaredEagerRows;
        $this->plainEagerRuntimeBytes = $plainEagerRuntimeBytes;
        $this->admittedManifests[$manifestPath] = true;
    }

    /** Resolve one path back to its successful preflight identity. */
    private function preflighted_manifest_identity(string $manifestPath): string
    {
        if (isset($this->resourceEnvelopes[$manifestPath])) {
            return $manifestPath;
        }
        $realManifestPath = realpath($manifestPath);
        if (is_string($realManifestPath) && isset($this->resourceEnvelopes[$realManifestPath])) {
            return $realManifestPath;
        }

        throw new LogicException('Configured lemma-pack construction requires a successful manifest preflight.');
    }

    /** Resolve one path back to its language-compatible admission identity. */
    private function admitted_manifest_identity(string $manifestPath): string
    {
        $identity = $this->preflighted_manifest_identity($manifestPath);
        if (!isset($this->admittedManifests[$identity])) {
            throw new LogicException('Configured lemma-pack construction requires a language-compatible manifest.');
        }

        return $identity;
    }

    /** Return the canonical primary language subtag. */
    private static function base_language(string $language): string
    {
        $language = WP_FTS_TermNamespace::canonicalize_lang($language, WP_FTS_TermNamespace::DEFAULT_LANG);
        $separator = strpos($language, '-');

        return $separator === false ? $language : substr($language, 0, $separator);
    }
}
