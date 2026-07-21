<?php
declare(strict_types=1);

/**
 * Admits one configured set of lemma packs against its shared resource limits.
 *
 * The manifest pass runs for the complete configuration before any pack is
 * constructed. A later construction pass uses the pinned manifest digest.
 * Aliases share one physical manifest envelope, so file, lookup-block, and
 * sidecar-byte limits are charged exactly once.
 */
final class WP_FTS_ConfiguredLemmaPackAdmission
{
    /** @var array<string,array<string,mixed>> */
    private array $resourceEnvelopes = [];
    /** @var array<string,Throwable> */
    private array $resourceEnvelopeFailures = [];
    /** @var array<string,true> */
    private array $admittedManifests = [];
    private int $runtimeFiles = 0;
    private int $lookupBlocks = 0;
    private int $runtimeLookupBytes = 0;

    /**
     * Inspect and, when language-compatible, charge one physical manifest.
     * Repeated aliases reuse the cached envelope and never charge it twice.
     *
     * @return array{manifest_path:string,language_matches:bool}
     */
    public function preflight_manifest(string $manifestPath, string $expectedLanguage): array
    {
        if ($manifestPath === '' || trim($manifestPath) !== $manifestPath) {
            throw new InvalidArgumentException('Configured lemma-pack manifest path must be unpadded and non-empty.');
        }
        WP_FTS_Analyzer_Config_Limits::assert_path($manifestPath, 'Configured lemma-pack manifest path');
        $expectedLanguage = WP_FTS_TermNamespace::parse_language_tag($expectedLanguage);
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
        if ($manifestPath === '' || trim($manifestPath) !== $manifestPath) {
            throw new InvalidArgumentException('Configured lemma-pack manifest path must be unpadded and non-empty.');
        }
        WP_FTS_Analyzer_Config_Limits::assert_path($manifestPath, 'Configured lemma-pack manifest path');
        $identity = $this->preflighted_manifest_identity($manifestPath);

        return (string) $this->resourceEnvelopes[$identity]['manifest_sha256'];
    }

    /** Charge one language-compatible resource envelope atomically. */
    private function admit_resource_envelope(string $manifestPath, array $envelope): void
    {
        $runtimeFiles = $this->runtimeFiles + (int) $envelope['runtime_files'];
        $lookupBlocks = $this->lookupBlocks + (int) $envelope['lookup_blocks'];
        $runtimeLookupBytes = $this->runtimeLookupBytes + (int) $envelope['runtime_lookup_bytes'];
        if ($runtimeFiles > WP_FTS_Analyzer_Config_Limits::MAX_CONFIGURED_RUNTIME_FILES
            || $lookupBlocks > WP_FTS_Analyzer_Config_Limits::MAX_CONFIGURED_LOOKUP_BLOCKS
            || $runtimeLookupBytes > WP_FTS_Analyzer_Config_Limits::MAX_CONFIGURED_RUNTIME_LOOKUP_BYTES
        ) {
            throw new WP_FTS_Analyzer_Config_Limit_Exceeded(
                'configured_pack_metadata',
                'Configured lemma packs exceed the 128-file, 16,384-block, or 32 MiB runtime envelope.'
            );
        }
        $this->runtimeFiles = $runtimeFiles;
        $this->lookupBlocks = $lookupBlocks;
        $this->runtimeLookupBytes = $runtimeLookupBytes;
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

    /** Return the canonical primary language subtag. */
    private static function base_language(string $language): string
    {
        $language = WP_FTS_TermNamespace::canonicalize_lang($language, WP_FTS_TermNamespace::DEFAULT_LANG);
        $separator = strpos($language, '-');

        return $separator === false ? $language : substr($language, 0, $separator);
    }
}
