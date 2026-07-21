<?php
declare(strict_types=1);

/**
 * Analyzes documents into storage-ready FTS payloads.
 *
 * The indexer delegates text analysis to the analyzer and emits normalized,
 * language-namespaced term frequencies for the bounded relational writer.
 */
final class WP_FTS_Indexer
{
    public const INDEX_SIGNATURE_VERSION = 'wp-fts-indexer-v7';
    private const MAX_INDEX_FIELDS = 32;
    private const MAX_FIELD_NAME_BYTES = 191;
    private const MAX_OPTION_SCALAR_BYTES = 64;
    private const MAX_OCCURRENCE_SOURCE_BYTES = 256;

    /**
     * @param object $analyzer Analyzer object exposing
     *        `analyze_document_fields()` and `index_signature()`.
     * @param object|null $postContentExtractor Optional adapter exposing
     *        `extract(object $post, array $opts): array` for post preparation.
     *        Framework-neutral callers may omit it when preparing fields.
     */
    public function __construct(
        private object $analyzer,
        private ?object $postContentExtractor = null,
    ) {
    }

    /**
     * Analyze weighted fields without reading or mutating storage.
     *
     * Batch writers can prepare many posts in PHP, then apply the returned term
     * frequencies, document hash, and snippet text with set-oriented storage
     * statements.
     *
     * @param int $doc_id Stable positive document identifier.
     * @param array<int,array<string,mixed>> $fields Weighted fields.
     * @param array<string,mixed> $opts Document analysis options.
     * @return array{doc_id:int,primary_lang:string,content_hash:string,snippet_text:string,term_frequencies:array<string,int>,surface_frequencies:array<string,int>}
     */
    public function prepare_document_fields(int $doc_id, array $fields, array $opts = []): array
    {
        if ($doc_id <= 0) {
            throw new InvalidArgumentException('Document id must be positive.');
        }
        $this->assert_option_keys($opts, ['document_lang', 'default_lang'], 'FTS document analysis');
        $this->assert_language_options($opts);

        return $this->analyze_index_source($this->prepare_index_source($doc_id, $fields, $opts));
    }

    /**
     * Extract and analyze one post without reading or mutating storage.
     *
     * The returned payload is suitable for the bounded batch storage writer.
     *
     * @param object $post Object with `ID` and WordPress post-like properties.
     * @param array<string,mixed> $opts Optional language, custom-field, and
     *        field-boost options.
     * @return array{doc_id:int,primary_lang:string,content_hash:string,snippet_text:string,term_frequencies:array<string,int>,surface_frequencies:array<string,int>}
     */
    public function prepare_post(object $post, array $opts = []): array
    {
        return $this->prepare_post_from_source($this->prepare_post_source($post, $opts));
    }

    /**
     * Extract, normalize, and fingerprint one post without analyzing its text.
     *
     * Batch workers can compare `content_hash` with the stored document before
     * calling `prepare_post_from_source()`. The returned normalized fields and
     * options are the exact inputs that method will analyze, so the unchanged
     * fast path neither repeats extraction nor risks hashing a different source.
     * Computing the hash reads the analyzer's cheap behavior signature but does
     * not invoke either content-analysis method.
     *
     * @param object $post Object with `ID` and WordPress post-like properties.
     * @param array<string,mixed> $opts Optional language, custom-field, and
     *        field-boost options.
     * @return array{doc_id:int,primary_lang:string,content_hash:string,fields:array<int,array{name:string,text:string,html?:string,boost:float}>,analysis_options:array<string,mixed>,snippet_text:string}
     */
    public function prepare_post_source(object $post, array $opts = []): array
    {
        if (
            !property_exists($post, 'terms')
            || !is_array($post->terms)
            || !property_exists($post, 'custom_fields')
            || !is_array($post->custom_fields)
        ) {
            throw new LogicException('Set-oriented post preparation requires authoritative terms and custom_fields arrays.');
        }
        $postProperties = get_object_vars($post);
        $postId = $postProperties['ID'] ?? null;
        if (!is_int($postId) || $postId <= 0) {
            throw new InvalidArgumentException('Post object must provide a positive ID.');
        }
        foreach (['post_title', 'post_content', 'post_excerpt'] as $property) {
            if (!array_key_exists($property, $postProperties) || !is_string($postProperties[$property])) {
                throw new InvalidArgumentException(
                    "Post object must provide {$property} as a native string."
                );
            }
        }
        $this->assert_option_keys(
            $opts,
            ['document_lang', 'default_lang', 'custom_field_keys', 'field_boosts'],
            'FTS post preparation'
        );
        $this->assert_language_options($opts);

        $extractionOptions = array_intersect_key($opts, [
            'custom_field_keys' => true,
            'field_boosts' => true,
        ]);
        if (!array_key_exists('custom_field_keys', $extractionOptions)) {
            $extractionOptions['custom_field_keys'] = array_keys($post->custom_fields);
        }

        if ($this->postContentExtractor === null || !method_exists($this->postContentExtractor, 'extract')) {
            throw new LogicException('Post content extractor must expose extract(object $post, array $opts).');
        }

        $extracted = $this->postContentExtractor->extract($post, $extractionOptions);
        if (!is_array($extracted)
            || array_keys($extracted) !== ['fields', 'snippet_text']
            || !is_array($extracted['fields'])
            || !array_is_list($extracted['fields'])
            || !is_string($extracted['snippet_text'])
        ) {
            throw new InvalidArgumentException('Post content extractor must return exactly fields and snippet_text.');
        }

        $analysisOptions = array_intersect_key($opts, [
            'document_lang' => true,
            'default_lang' => true,
        ]);

        return $this->prepare_index_source(
            $postId,
            $extracted['fields'],
            $analysisOptions,
            $extracted['snippet_text']
        );
    }

    /**
     * Analyze a payload previously returned by `prepare_post_source()`.
     *
     * @param array{doc_id:int,primary_lang:string,content_hash:string,fields:array<int,array{name:string,text:string,html?:string,boost:float}>,analysis_options:array<string,mixed>,snippet_text:string} $source
     * @return array{doc_id:int,primary_lang:string,content_hash:string,snippet_text:string,term_frequencies:array<string,int>,surface_frequencies:array<string,int>}
     */
    public function prepare_post_from_source(array $source): array
    {
        return $this->analyze_index_source($source);
    }

    /**
     * Normalize and fingerprint fields before any content analysis occurs.
     *
     * @param array<int,array<string,mixed>> $fields
     * @param array<string,mixed> $opts
     * @return array{doc_id:int,primary_lang:string,content_hash:string,fields:array<int,array{name:string,text:string,html?:string,boost:float}>,analysis_options:array<string,mixed>,snippet_text:string}
     */
    private function prepare_index_source(int $doc_id, array $fields, array $opts, string $snippetText = ''): array
    {
        $this->assert_option_keys($opts, ['document_lang', 'default_lang'], 'FTS document analysis');
        $this->assert_language_options($opts);
        $primaryLang = $this->resolve_document_language($opts);
        $fields = $this->normalize_index_fields($fields);
        if (strlen($snippetText) > WP_FTS_Set_Oriented_Search_Storage::MAX_SNIPPET_SOURCE_BYTES) {
            throw new WP_FTS_Analysis_Limit_Exceeded(
                'snippet_text_bytes',
                'FTS snippet text may contain at most 20,000 bytes.'
            );
        }
        $analysisOptions = array_intersect_key($opts, [
            'document_lang' => true,
            'default_lang' => true,
        ]);
        $hash = $this->content_hash(
            $this->fields_hash_source($fields) . "\0snippet\0" . $snippetText,
            $primaryLang
        );

        return [
            'doc_id' => $doc_id,
            'primary_lang' => $primaryLang,
            'content_hash' => $hash,
            'fields' => $fields,
            'analysis_options' => $analysisOptions,
            'snippet_text' => $snippetText,
        ];
    }

    /**
     * Analyze one normalized source payload into storage-ready frequencies.
     *
     * @param array{doc_id:int,primary_lang:string,content_hash:string,fields:array<int,array{name:string,text:string,html?:string,boost:float}>,analysis_options:array<string,mixed>,snippet_text:string} $source
     * @return array{doc_id:int,primary_lang:string,content_hash:string,snippet_text:string,term_frequencies:array<string,int>,surface_frequencies:array<string,int>}
     */
    private function analyze_index_source(array $source): array
    {
        if (count($source) !== 6) {
            throw new InvalidArgumentException('Prepared post source payloads must contain the six documented fields.');
        }
        foreach (['doc_id', 'primary_lang', 'content_hash', 'fields', 'analysis_options', 'snippet_text'] as $requiredKey) {
            if (!array_key_exists($requiredKey, $source)) {
                throw new InvalidArgumentException('Invalid prepared post source payload.');
            }
        }
        if (!is_int($source['doc_id'] ?? null) || $source['doc_id'] <= 0
            || !is_string($source['primary_lang'] ?? null)
            || !is_string($source['content_hash'] ?? null)
            || !is_array($source['fields'] ?? null)
            || !is_array($source['analysis_options'] ?? null)
            || !is_string($source['snippet_text'] ?? null)
        ) {
            throw new InvalidArgumentException('Invalid prepared post source payload.');
        }
        if (strlen($source['primary_lang']) > self::MAX_OPTION_SCALAR_BYTES
            || strlen($source['snippet_text']) > WP_FTS_Set_Oriented_Search_Storage::MAX_SNIPPET_SOURCE_BYTES
        ) {
            throw new InvalidArgumentException('Prepared post source strings exceed their fixed bounds.');
        }
        if (preg_match('/^[a-f0-9]{40}$/D', $source['content_hash']) !== 1) {
            throw new InvalidArgumentException('Prepared post source content_hash must contain 40 lowercase hexadecimal bytes.');
        }
        if (!hash_equals(
            WP_FTS_TermNamespace::parse_language_tag($source['primary_lang']),
            $source['primary_lang']
        )) {
            throw new InvalidArgumentException('Prepared post source language must already be canonical.');
        }

        $doc_id = $source['doc_id'];
        $primaryLang = $source['primary_lang'];
        $hash = $source['content_hash'];
        $opts = $source['analysis_options'];
        $this->assert_option_keys($opts, ['document_lang', 'default_lang'], 'Prepared post analysis');
        $this->assert_language_options($opts);
        $configuredLanguage = WP_FTS_TermNamespace::language_from_options(
            $opts,
            null,
            ['document_lang', 'default_lang']
        );
        if ($configuredLanguage !== null && $configuredLanguage !== $primaryLang) {
            throw new InvalidArgumentException(
                'Prepared post analysis language must match the canonical primary language.'
            );
        }
        $fields = $this->normalize_index_fields($source['fields']);
        $expectedHash = $this->content_hash(
            $this->fields_hash_source($fields) . "\0snippet\0" . $source['snippet_text'],
            $primaryLang
        );
        if (!hash_equals($expectedHash, $hash)) {
            throw new InvalidArgumentException('Prepared post source content hash does not match its normalized fields and snippet text.');
        }
        $hash = $expectedHash;

        if (!is_callable([$this->analyzer, 'analyze_document_fields'])) {
            throw new LogicException('Analyzer must provide analyze_document_fields().');
        }
        $fieldOpts = $opts;
        $fieldOpts['_max_document_occurrences'] = WP_FTS_Analysis_Limits::MAX_DOCUMENT_OCCURRENCES;
        $fieldOpts['_include_document_surface'] = true;
        $batchedFieldOccurrences = $this->analyzer->analyze_document_fields(
            $fields,
            $this->analysis_options($fieldOpts, $primaryLang)
        );
        if (!is_array($batchedFieldOccurrences)
            || !array_is_list($batchedFieldOccurrences)
            || count($batchedFieldOccurrences) !== count($fields)
        ) {
            throw new WP_FTS_Analysis_Limit_Exceeded(
                'occurrence_shape',
                'Batched field analysis must return one occurrence list per field.'
            );
        }

        $occurrences = [];
        $nextAlternativeGroup = 0;
        foreach ($fields as $fieldIndex => $field) {
            $fieldOpts = $opts;
            $fieldOpts['field_name'] = $field['name'];
            $fieldOpts['_max_document_occurrences'] = WP_FTS_Analysis_Limits::MAX_DOCUMENT_OCCURRENCES - count($occurrences);
            $fieldOpts['_include_document_surface'] = true;
            $fieldOccurrences = $batchedFieldOccurrences[$fieldIndex];
            if (!is_array($fieldOccurrences) || !array_is_list($fieldOccurrences)) {
                throw new WP_FTS_Analysis_Limit_Exceeded(
                    'occurrence_shape',
                    'Batched field analysis occurrence lists must be arrays.'
                );
            }
            if (count($fieldOccurrences) > $fieldOpts['_max_document_occurrences']) {
                throw new WP_FTS_Analysis_Limit_Exceeded(
                    'occurrences',
                    'FTS document analysis exceeds the 20,000-occurrence limit.'
                );
            }
            $this->assert_analyzer_occurrence_bounds($fieldOccurrences);

            foreach ($this->mark_alternative_groups($fieldOccurrences, $nextAlternativeGroup) as $occurrence) {
                $occurrence['weight'] = (float) $occurrence['weight'] * $field['boost'];
                $occurrences[] = $occurrence;
            }
        }

        [$termFrequencies, $surfaceFrequencies] = $this->weighted_term_frequencies_by_language(
            $occurrences,
            $primaryLang
        );

        return [
            'doc_id' => $doc_id,
            'primary_lang' => $primaryLang,
            'content_hash' => $hash,
            'snippet_text' => $source['snippet_text'],
            'term_frequencies' => $termFrequencies,
            'surface_frequencies' => $surfaceFrequencies,
        ];
    }

    /** Bound custom analyzer rows before trimming, canonicalization, or copies. */
    private function assert_analyzer_occurrence_bounds(array $occurrences): void
    {
        if (!array_is_list($occurrences)) {
            throw new InvalidArgumentException('Document analyzer output must be a list of occurrences.');
        }
        foreach ($occurrences as $occurrence) {
            WP_FTS_Analyzer_Occurrence_Validator::assert_document($occurrence);
        }
    }

    /**
     * Give alternatives emitted for one source token an indexer-local group id.
     *
     * Analyzer positions restart for every independently analyzed field, so the
     * monotonically increasing id prevents adjacent fields with the same local
     * position from being collapsed into one logical occurrence.
     *
     * @param array<int,array<string,mixed>|string> $occurrences
     * @return array<int,array<string,mixed>|string>
     */
    private function mark_alternative_groups(array $occurrences, int &$nextGroup): array
    {
        $groupsByPosition = [];
        foreach ($occurrences as &$occurrence) {
            if (!array_key_exists('position', $occurrence)) {
                continue;
            }

            $position = $occurrence['position'];
            if (!isset($groupsByPosition[$position])) {
                $groupsByPosition[$position] = $nextGroup++;
            }
            $occurrence['_alternative_group'] = $groupsByPosition[$position];
        }
        unset($occurrence);

        return $occurrences;
    }

    /**
     * Resolve the primary language used for hashing and default term partitioning.
     *
     * Explicit document options win over the environment default. The analyzer
     * may still return segment-level languages for nested HTML scopes.
     *
     * @param array<string,mixed> $opts Document preparation options.
     * @return string Canonical primary language.
     */
    private function resolve_document_language(array $opts): string
    {
        $default = WP_FTS_TermNamespace::default_language($opts);

        return WP_FTS_TermNamespace::language_from_options($opts, null, ['document_lang']) ?? $default;
    }

    /**
     * Hash document content together with its primary language and analyzer.
     *
     * NUL separators keep portions unambiguous. The analyzer signature makes a
     * runtime behavior change rewrite otherwise unchanged documents.
     */
    private function content_hash(string $html, string $primaryLang): string
    {
        return sha1(implode("\0", [
            self::INDEX_SIGNATURE_VERSION,
            $this->analyzer_index_signature(),
            WP_FTS_TermNamespace::canonicalize_lang($primaryLang),
            $html,
        ]));
    }

    /**
     * Return the analyzer signature used in document fingerprints.
     */
    private function analyzer_index_signature(): string
    {
        if (!is_callable([$this->analyzer, 'index_signature'])) {
            throw new LogicException('Analyzer must provide index_signature().');
        }

        $signature = $this->analyzer->index_signature();
        if (!is_string($signature) || $signature === '' || trim($signature) !== $signature) {
            throw new LogicException('Analyzer index_signature() must return an unpadded nonempty string.');
        }
        if (strlen($signature) > self::MAX_OCCURRENCE_SOURCE_BYTES) {
            throw new WP_FTS_Analysis_Limit_Exceeded(
                'analyzer_signature_bytes',
                'An FTS analyzer signature exceeds 256 bytes.'
            );
        }

        return $signature;
    }

    /**
     * Fill analyzer options with the resolved document language.
     *
     * The analyzer receives only its current per-call language keys. An
     * explicit document language stays authoritative; otherwise the resolved
     * site default remains below analyzer constructor options and resolvers.
     *
     * @param array<string,mixed> $opts
     * @return array<string,mixed>
     */
    private function analysis_options(array $opts, string $primaryLang): array
    {
        $analysisOpts = $opts;
        unset($analysisOpts['default_lang']);
        if (WP_FTS_TermNamespace::language_from_options($opts, null, ['document_lang']) !== null) {
            $analysisOpts['document_lang'] = $primaryLang;
        } else {
            unset($analysisOpts['document_lang']);
            $analysisOpts['_default_document_lang'] = $primaryLang;
        }

        return $analysisOpts;
    }

    /** @param string[] $allowedKeys */
    private function assert_option_keys(array $opts, array $allowedKeys, string $surface): void
    {
        foreach (array_keys($opts) as $key) {
            if (!is_string($key) || !in_array($key, $allowedKeys, true)) {
                throw new InvalidArgumentException("{$surface} options contain an unsupported field.");
            }
        }
    }

    /** @param array<string,mixed> $opts */
    private function assert_language_options(array $opts): void
    {
        foreach (['document_lang', 'default_lang'] as $key) {
            if (!array_key_exists($key, $opts)) {
                continue;
            }
            WP_FTS_TermNamespace::parse_language_tag($opts[$key]);
        }
    }

    /**
     * Normalize index fields supplied by the extractor or direct callers.
     *
     * @param array<int,array<string,mixed>> $fields
     * @return array<int,array{name:string,text:string,html?:string,boost:float}>
     */
    private function normalize_index_fields(array $fields): array
    {
        if (!array_is_list($fields)) {
            throw new InvalidArgumentException('FTS index fields must be a list.');
        }
        if (count($fields) > self::MAX_INDEX_FIELDS) {
            throw new WP_FTS_Analysis_Limit_Exceeded(
                'index_fields',
                'An FTS document contains more than 32 index fields.'
            );
        }

        $normalized = [];
        $documentSourceBytes = 0;
        foreach ($fields as $field) {
            if (!is_array($field)) {
                throw new InvalidArgumentException('FTS index fields must be arrays.');
            }

            if (!array_key_exists('name', $field) || !array_key_exists('text', $field)) {
                throw new InvalidArgumentException('FTS index fields must contain name and text.');
            }
            foreach (array_keys($field) as $key) {
                if (!is_string($key) || !in_array($key, ['name', 'text', 'html', 'boost'], true)) {
                    throw new InvalidArgumentException('FTS index fields contain an unsupported field.');
                }
            }
            $rawName = $field['name'];
            $rawText = $field['text'];
            $hasHtml = array_key_exists('html', $field);
            $rawHtml = $hasHtml ? $field['html'] : null;
            if (!is_string($rawName) || !is_string($rawText) || ($hasHtml && !is_string($rawHtml))) {
                throw new InvalidArgumentException('FTS index field names and sources must be strings.');
            }
            if ($rawName === '' || trim($rawName) !== $rawName) {
                throw new InvalidArgumentException('FTS index field names must be unpadded non-empty strings.');
            }
            if (strlen($rawName) > self::MAX_FIELD_NAME_BYTES) {
                throw new WP_FTS_Analysis_Limit_Exceeded(
                    'index_field_name_bytes',
                    'An FTS index field name exceeds the 191-byte limit.'
                );
            }
            $documentSourceBytes += strlen($rawText) + ($rawHtml === null ? 0 : strlen($rawHtml));
            WP_FTS_Analysis_Limits::assert_document_source_bytes($documentSourceBytes);
            if ($rawHtml !== null && trim($rawHtml) !== '') {
                // Enforce the syntax envelope before a custom analyzer can
                // traverse an unbounded tag stack or attribute list.
                WP_FTS_Html_Text_Stream::assert_analysis_markup_limits($rawHtml);
            }

            $name = $rawName;
            $text = trim($rawText);
            $html = $rawHtml;
            if ($text === '' && trim((string) $html) === '') {
                continue;
            }

            $rawBoost = array_key_exists('boost', $field) ? $field['boost'] : 1.0;
            if ((!is_int($rawBoost) && !is_float($rawBoost))
                || !is_finite((float) $rawBoost)
                || floor((float) $rawBoost) !== (float) $rawBoost
                || $rawBoost < 1
                || $rawBoost > 100
            ) {
                throw new InvalidArgumentException('An FTS field boost must be a whole number from 1 through 100.');
            }

            $row = [
                'name' => $name,
                'text' => $text,
                'boost' => (float) $rawBoost,
            ];
            if ($html !== null && trim($html) !== '') {
                $row['html'] = $html;
            }
            $normalized[] = $row;
        }

        return $normalized;
    }

    /**
     * Build a deterministic hash source for field-based indexing.
     *
     * The persisted content snippet is appended by the caller so a snippet-only
     * change cannot take the relational unchanged fast path.
     *
     * @param array<int,array{name:string,text:string,html?:string,boost:float}> $fields
     */
    private function fields_hash_source(array $fields): string
    {
        return json_encode($fields, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    /**
     * Collapse analyzer occurrences into namespaced term and surface frequencies.
     *
     * Every stored key is normalized to `lang . "\\x1e" . term`. Alternative lemmas retain
     * separate postings for recall.
     *
     * @param array<int,array<string,mixed>> $occurrences
     * @return array{0:array<string,int>,1:array<string,int>}
     */
    private function weighted_term_frequencies_by_language(
        array $occurrences,
        string $defaultLang
    ): array
    {
        $candidates = [];
        $alternativeGroups = [];
        $distinctKeys = [];
        $surfaceWeights = [];
        $sequence = 0;
        foreach ($occurrences as $occurrence) {
            $term = $occurrence['term'];
            $lang = WP_FTS_TermNamespace::canonicalize_lang($occurrence['lang'], $defaultLang);
            $weight = (float) $occurrence['weight'];

            $group = array_key_exists('_alternative_group', $occurrence)
                ? (string) $occurrence['_alternative_group']
                : null;
            $rank = $occurrence['rank'] ?? 0;
            $surface = $occurrence['normalized_surface'] ?? '';
            if ($surface !== '') {
                // Long lexical runs remain searchable by every representable
                // prefix. Two runs sharing all storable bytes are equivalent
                // because no longer query identity can fit the dictionary.
                $surfaceBytes = WP_FTS_TermNamespace::MAX_TERM_KEY_BYTES
                    - strlen(WP_FTS_TermNamespace::namespace_term($lang, ''));
                $surface = $surfaceBytes > 0
                    ? WP_FTS_Utf8::truncate_bytes($surface, $surfaceBytes)
                    : '';
            }
            if ($surface !== '') {
                $surfaceKey = $group === null ? 'occurrence:' . $sequence : 'alternative:' . $group;
                $surfaceIdentity = WP_FTS_TermNamespace::namespace_term($lang, $surface);
                if (!isset($surfaceWeights[$surfaceIdentity])) {
                    if (count($surfaceWeights) >= WP_FTS_Analysis_Limits::MAX_DOCUMENT_DISTINCT_SURFACES) {
                        throw new WP_FTS_Analysis_Limit_Exceeded(
                            'distinct_surfaces',
                            'FTS document analysis exceeds the 4,096-distinct-surface limit.'
                        );
                    }
                    $surfaceWeights[$surfaceIdentity] = [];
                }
                $surfaceWeights[$surfaceIdentity][$surfaceKey] = max(
                    $surfaceWeights[$surfaceIdentity][$surfaceKey] ?? 0.0,
                    $weight
                );
            }

            if (!WP_FTS_TermNamespace::term_key_fits($term, $lang)) {
                throw new WP_FTS_Analysis_Limit_Exceeded(
                    'occurrence_bytes',
                    'An analyzer term exceeds the relational dictionary key limit.'
                );
            }
            $key = WP_FTS_TermNamespace::namespace_term($lang, $term);
            if (!isset($distinctKeys[$key])) {
                $distinctKeys[$key] = true;
                if (count($distinctKeys) > WP_FTS_Analysis_Limits::MAX_DOCUMENT_DISTINCT_TERMS) {
                    throw new WP_FTS_Analysis_Limit_Exceeded(
                        'distinct_terms',
                        'FTS document analysis exceeds the 4,096-distinct-term limit.'
                    );
                }
            }
            $candidates[] = [
                'key' => $key,
                'weight' => $weight,
                'group' => $group,
                'rank' => $rank,
                'source' => $occurrence['source'] ?? '',
            ];
            $sequence++;

            if ($group === null) {
                continue;
            }
            if (!isset($alternativeGroups[$group])) {
                $alternativeGroups[$group] = ['count' => 1, 'min_rank' => $rank, 'min_rank_count' => 1];
                continue;
            }
            $alternativeGroups[$group]['count']++;
            if ($rank < $alternativeGroups[$group]['min_rank']) {
                $alternativeGroups[$group]['min_rank'] = $rank;
                $alternativeGroups[$group]['min_rank_count'] = 1;
            } elseif ($rank === $alternativeGroups[$group]['min_rank']) {
                $alternativeGroups[$group]['min_rank_count']++;
            }
        }

        $weights = [];
        foreach ($candidates as $candidate) {
            $weight = $candidate['weight'];
            $group = $candidate['group'];
            if (
                $group !== null
                && $candidate['source'] === 'lemma-pack'
                && ($alternativeGroups[$group]['count'] ?? 0) > 1
                && ($alternativeGroups[$group]['min_rank_count'] ?? 0) === 1
                && $candidate['rank'] === ($alternativeGroups[$group]['min_rank'] ?? -1)
            ) {
                $weight *= 2.0;
            }

            $weights[$candidate['key']] = ($weights[$candidate['key']] ?? 0.0) + $weight;
        }

        $frequencies = [];
        foreach ($weights as $term => $weight) {
            $frequencies[$term] = max(1, (int) round($weight));
        }

        $surfaceFrequencies = [];
        foreach ($surfaceWeights as $surface => $occurrenceWeights) {
            $surfaceFrequencies[$surface] = max(1, (int) round(array_sum($occurrenceWeights)));
        }

        ksort($frequencies, SORT_STRING);
        ksort($surfaceFrequencies, SORT_STRING);

        return [$frequencies, $surfaceFrequencies];
    }

}
