#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Generate temporary lexical packs and exercise automatic routing diagnostics.
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "probe-auto-routing-live.php must run from the command line.\n");
    exit(1);
}

require_once __DIR__ . '/../src/bootstrap.php';

final class Language_FTS_Playground_Auto_Routing_Probe_Failure extends RuntimeException
{
}

final class Language_FTS_Playground_Auto_Routing_Probe_Storage implements Language_FTS_Playground_Storage_Interface
{
    private Language_FTS_Playground_In_Memory_Storage $storage;

    /** @var string[] */
    private array $fetch_postings_languages = [];

    /** @var array<int,array{language:string,terms:string[]}> */
    private array $fetch_postings_requests = [];

    /** @var array<int,array<string,string[]>> */
    private array $fetch_term_language_hits_requests = [];

    /** @var array<int,array{language:string,term:string,max_distance:int,limit:int,candidates:string[]}> */
    private array $fetch_candidate_terms_requests = [];

    public function __construct()
    {
        $this->storage = new Language_FTS_Playground_In_Memory_Storage();
    }

    public function reset_probe_counters(): void
    {
        $this->fetch_postings_languages = [];
        $this->fetch_postings_requests = [];
        $this->fetch_term_language_hits_requests = [];
        $this->fetch_candidate_terms_requests = [];
    }

    /**
     * @return array<string,mixed>
     */
    public function probe_counters(): array
    {
        return [
            'fetch_postings_languages' => $this->fetch_postings_languages,
            'fetch_postings_requests' => $this->fetch_postings_requests,
            'fetch_term_language_hits_count' => count($this->fetch_term_language_hits_requests),
            'fetch_term_language_hits_requests' => $this->fetch_term_language_hits_requests,
            'fetch_candidate_terms_requests' => $this->fetch_candidate_terms_requests,
        ];
    }

    public function install(): void
    {
        $this->storage->install();
    }

    public function clear(): void
    {
        $this->storage->clear();
        $this->reset_probe_counters();
    }

    public function replace_document(
        int $post_id,
        string $language,
        string $title,
        string $status,
        int $document_length,
        array $field_term_frequencies,
        array $field_texts,
        array $term_positions
    ): void {
        $this->storage->replace_document(
            $post_id,
            $language,
            $title,
            $status,
            $document_length,
            $field_term_frequencies,
            $field_texts,
            $term_positions
        );
    }

    public function replace_document_partitions(int $post_id, array $partitions): void
    {
        $this->storage->replace_document_partitions($post_id, $partitions);
    }

    public function delete_document(int $post_id): void
    {
        $this->storage->delete_document($post_id);
    }

    public function fetch_postings(string $language, array $terms): array
    {
        $terms = array_values(array_unique(array_map('strval', $terms)));
        $this->fetch_postings_languages[] = $language;
        $this->fetch_postings_requests[] = [
            'language' => $language,
            'terms' => $terms,
        ];

        return $this->storage->fetch_postings($language, $terms);
    }

    public function fetch_term_language_hits(array $language_terms): array
    {
        $normalized = [];
        foreach ($language_terms as $language => $terms) {
            $normalized[(string) $language] = array_values(array_unique(array_map('strval', (array) $terms)));
        }
        $this->fetch_term_language_hits_requests[] = $normalized;

        return $this->storage->fetch_term_language_hits($language_terms);
    }

    public function fetch_positions(string $language, array $terms, array $post_ids): array
    {
        return $this->storage->fetch_positions($language, $terms, $post_ids);
    }

    public function fetch_candidate_terms(string $language, string $term, int $max_distance, int $limit): array
    {
        $candidates = $this->storage->fetch_candidate_terms($language, $term, $max_distance, $limit);
        $this->fetch_candidate_terms_requests[] = [
            'language' => $language,
            'term' => $term,
            'max_distance' => $max_distance,
            'limit' => $limit,
            'candidates' => array_values(array_map('strval', $candidates)),
        ];

        return $candidates;
    }

    public function fetch_document_lengths(string $language, array $post_ids): array
    {
        return $this->storage->fetch_document_lengths($language, $post_ids);
    }

    public function fetch_document_fields(string $language, array $post_ids): array
    {
        return $this->storage->fetch_document_fields($language, $post_ids);
    }

    public function fetch_document_field_metadata(string $language, array $post_ids): array
    {
        return $this->storage->fetch_document_field_metadata($language, $post_ids);
    }

    public function document_count(string $language): int
    {
        return $this->storage->document_count($language);
    }

    public function all_documents(): array
    {
        return $this->storage->all_documents();
    }
}

final class Language_FTS_Playground_Auto_Routing_Probe_Runner
{
    /** @var array{json:bool,help:bool,keep_temp:bool,resource_root:string|null} */
    private array $options;

    private string $work_root = '';
    private bool $cleanup_work_root = true;

    /**
     * @param array{json:bool,help:bool,keep_temp:bool,resource_root:string|null} $options
     */
    public function __construct(array $options)
    {
        $this->options = $options;
    }

    /**
     * @return array<string,mixed>
     */
    public function run(): array
    {
        $this->prepare_work_root();

        $probes = [];
        $failures = [];
        $probe_runners = [
            'no-evidence-fallback' => fn(): array => $this->run_no_evidence_fallback(),
            'stopword-only-fallback' => fn(): array => $this->run_stopword_only_fallback(),
            'stopword-empty-query-diagnostics' => fn(): array => $this->run_stopword_empty_query_diagnostics(),
            'exact-evidence' => fn(): array => $this->run_exact_evidence(),
            'synonym-target-evidence' => fn(): array => $this->run_synonym_target_evidence(),
            'synonym-target-over-cap' => fn(): array => $this->run_synonym_target_over_cap(),
            'phrase-synonym-source-evidence' => fn(): array => $this->run_phrase_synonym_source_evidence(),
            'phrase-synonym-target-over-cap' => fn(): array => $this->run_phrase_synonym_target_over_cap(),
            'fuzzy-evidence-over-cap' => fn(): array => $this->run_fuzzy_evidence_over_cap(),
            'term-rule-evidence' => fn(): array => $this->run_term_rule_evidence(),
            'term-rule-guard' => fn(): array => $this->run_term_rule_guard(),
            'over-cap-selected-partition-fallback' => fn(): array => $this->run_over_cap_selected_partition_fallback(),
            'over-cap-all-hit-fallback' => fn(): array => $this->run_over_cap_all_hit_fallback(),
        ];

        try {
            foreach ($probe_runners as $name => $runner) {
                try {
                    $probes[] = $runner();
                } catch (Throwable $throwable) {
                    $failures[] = [
                        'probe' => $name,
                        'message' => $throwable->getMessage(),
                    ];
                }
            }

            return [
                'status' => $failures === [] ? 'passed' : 'failed',
                'probe_count' => count($probe_runners),
                'passed_count' => count($probes),
                'failed_count' => count($failures),
                'work_root' => $this->work_root,
                'work_root_cleaned' => $this->cleanup_work_root && empty($this->options['keep_temp']),
                'probes' => $probes,
                'failures' => $failures,
            ];
        } finally {
            if ($this->cleanup_work_root && empty($this->options['keep_temp'])) {
                $this->remove_tree($this->work_root);
            }
        }
    }

    private function run_no_evidence_fallback(): array
    {
        $profiles = [
            'probe-a' => $this->language(10, ['lexemes' => $this->lexemes([['alphaterm', 'alpha']])]),
            'probe-b' => $this->language(20, ['lexemes' => $this->lexemes([['betaterm', 'beta']])]),
            'probe-c' => $this->language(30, ['lexemes' => $this->lexemes([['gammaterm', 'gamma']])]),
        ];
        $documents = [
            $this->document(101, 'probe-b', 'novelterm target document'),
            $this->document(102, 'probe-a', 'unrelated bait document'),
            $this->document(103, 'probe-c', 'another unrelated bait document'),
        ];

        return $this->run_case(
            'no-evidence-fallback',
            $profiles,
            $documents,
            'novelterm',
            function (array $case): void {
                $this->assert_same([], $case['ranked'], 'No-evidence query should have no ranked profile candidates.');
                $this->assert_common_explain(
                    $case,
                    ['probe-a', 'probe-b', 'probe-c'],
                    'auto_fallback_no_evidence_bounded_preflight',
                    ['probe-a', 'probe-b', 'probe-c']
                );
                $this->assert_same(false, $case['explain']['language_routing']['preflight']['evaluated'] ?? null, 'Under-cap fallback should not evaluate storage preflight.');
                $this->assert_same(0, $case['explain_counters']['fetch_term_language_hits_count'] ?? null, 'Under-cap fallback should skip term-language preflight calls.');
                $this->assert_same(['probe-a', 'probe-b', 'probe-c'], $case['explain_counters']['fetch_postings_languages'] ?? [], 'No-evidence fallback should search every under-cap partition.');
                $this->assert_result_ids($case, [101]);
                $this->assert_result_languages($case, ['probe-b']);
            }
        );
    }

    private function run_stopword_only_fallback(): array
    {
        $profiles = [
            'probe-stop' => $this->language(10, [
                'stopwords' => $this->stopwords(['the', 'and', 'of', 'to']),
                'lexemes' => $this->lexemes([['stopalpha', 'stopalpha']]),
            ]),
            'probe-target' => $this->language(20, ['lexemes' => $this->lexemes([['targetalpha', 'targetalpha']])]),
        ];
        $documents = [
            $this->document(201, 'probe-target', 'novelterm target document'),
            $this->document(202, 'probe-stop', 'stop bait document'),
        ];

        return $this->run_case(
            'stopword-only-fallback',
            $profiles,
            $documents,
            'the and of to novelterm',
            function (array $case): void {
                $this->assert_same([], $case['ranked'], 'Stopword-only evidence should not create a ranked automatic candidate.');
                $this->assert_common_explain(
                    $case,
                    ['probe-stop', 'probe-target'],
                    'auto_fallback_no_evidence_bounded_preflight',
                    ['probe-stop', 'probe-target']
                );
                $this->assert_same(false, $case['explain']['language_routing']['preflight']['evaluated'] ?? null, 'Under-cap stopword fallback should not evaluate storage preflight.');
                $this->assert_result_ids($case, [201]);
                $this->assert_result_languages($case, ['probe-target']);
            }
        );
    }

    private function run_stopword_empty_query_diagnostics(): array
    {
        $profiles = [
            'probe-stop' => $this->language(10, [
                'stopwords' => $this->stopwords(['the', 'and', 'of', 'to']),
                'lexemes' => $this->lexemes([['stopalpha', 'stopalpha']]),
            ]),
            'probe-target' => $this->language(20, [
                'stopwords' => $this->stopwords(['the', 'and', 'of', 'to']),
                'lexemes' => $this->lexemes([['targetalpha', 'targetalpha']]),
            ]),
        ];

        return $this->run_case(
            'stopword-empty-query-diagnostics',
            $profiles,
            [],
            'the and of to',
            function (array $case): void {
                $this->assert_same([], $case['ranked'], 'Pure stopword query should have no ranked profile candidates.');
                $this->assert_common_explain(
                    $case,
                    ['probe-stop', 'probe-target'],
                    'auto_fallback_no_evidence_bounded_preflight',
                    ['probe-stop', 'probe-target']
                );
                foreach ($case['explain']['partitions'] as $partition) {
                    $this->assert_same([], $partition['analyzed_query']['exact_terms'] ?? null, 'Pure stopword partitions should have an empty analyzed query.');
                    $this->assert_contains('analyzed_query_empty_after_stopwords', $partition['no_result_causes'] ?? [], 'Pure stopword partitions should report the empty-query diagnostic.');
                }
                $this->assert_result_ids($case, []);
            }
        );
    }

    private function run_exact_evidence(): array
    {
        $profiles = [
            'probe-exact' => $this->language(10, ['lexemes' => $this->lexemes([['amberform', 'amber']])]),
            'probe-bait-a' => $this->language(20, ['lexemes' => $this->lexemes([['baitalpha', 'baitalpha']])]),
            'probe-bait-b' => $this->language(30, ['lexemes' => $this->lexemes([['baitbeta', 'baitbeta']])]),
        ];
        $documents = [
            $this->document(301, 'probe-exact', 'amberform target document'),
            $this->document(302, 'probe-bait-a', 'amberform bait document'),
            $this->document(303, 'probe-bait-b', 'amberform bait document'),
        ];

        return $this->run_case(
            'exact-evidence',
            $profiles,
            $documents,
            'amberform',
            function (array $case): void {
                $this->assert_ranked_top($case, 'probe-exact');
                $candidate = $case['ranked'][0] ?? [];
                $this->assert_contains('amberform', $candidate['reasons']['lexeme_forms'] ?? [], 'Exact evidence should record the observed lexeme form.');
                $this->assert_contains('amber', $candidate['reasons']['canonical_keys'] ?? [], 'Exact evidence should record the canonical lexeme key.');
                $this->assert_common_explain(
                    $case,
                    ['probe-exact', 'probe-bait-a', 'probe-bait-b'],
                    'auto_confident_profile_evidence',
                    ['probe-exact']
                );
                $this->assert_same([], $case['explain']['language_routing']['preflight'] ?? null, 'Confident exact routing should not run bounded preflight.');
                $this->assert_same(['probe-exact'], $case['explain_counters']['fetch_postings_languages'] ?? [], 'Confident exact routing should search only the selected partition.');
                $this->assert_result_ids($case, [301]);
                $this->assert_result_languages($case, ['probe-exact']);
            }
        );
    }

    private function run_synonym_target_evidence(): array
    {
        $profiles = [
            'probe-syn' => $this->language(10, [
                'lexemes' => $this->lexemes([
                    ['seekalpha', 'seekalpha'],
                    ['findalpha', 'findalpha'],
                ]),
                'synonyms' => $this->synonyms([['seekalpha', 'findalpha', 'query_to_index', '1.0']]),
            ]),
            'probe-bait-a' => $this->language(20, ['lexemes' => $this->lexemes([['baitalpha', 'baitalpha']])]),
            'probe-bait-b' => $this->language(30, ['lexemes' => $this->lexemes([['baitbeta', 'baitbeta']])]),
        ];
        $documents = [
            $this->document(401, 'probe-syn', 'findalpha target document'),
            $this->document(402, 'probe-bait-a', 'seekalpha bait document'),
            $this->document(403, 'probe-bait-b', 'seekalpha bait document'),
        ];

        return $this->run_case(
            'synonym-target-evidence',
            $profiles,
            $documents,
            'seekalpha',
            function (array $case): void {
                $this->assert_ranked_top($case, 'probe-syn');
                $candidate = $case['ranked'][0] ?? [];
                $this->assert_contains('seekalpha', $candidate['reasons']['lexeme_forms'] ?? [], 'Synonym source query should record source lexeme evidence.');
                $this->assert_contains('seekalpha', $candidate['reasons']['synonym_sources'] ?? [], 'Synonym source query should record synonym-source evidence.');
                $this->assert_common_explain(
                    $case,
                    ['probe-syn', 'probe-bait-a', 'probe-bait-b'],
                    'auto_confident_profile_evidence',
                    ['probe-syn']
                );
                $partition = $this->partition($case, 'probe-syn');
                $this->assert_contains('findalpha', $partition['lookup_terms']['single_token_synonyms'] ?? [], 'Selected diagnostics should include the synonym target lookup term.');
                $this->assert_expansion($partition['synonym_expansions'] ?? [], 'source_key', 'seekalpha', 'target_key', 'findalpha', 'Synonym diagnostics should connect source and target keys.');
                $this->assert_result_ids($case, [401]);
                $this->assert_result_languages($case, ['probe-syn']);
                $this->assert_result_match_class($case, 'synonym');
            }
        );
    }

    private function run_synonym_target_over_cap(): array
    {
        $profiles = [];
        foreach ($this->letter_languages() as $index => $language) {
            $profiles[$language] = $this->language(($index + 1) * 10, [
                'lexemes' => $this->lexemes([
                    ['seekalpha', 'seekalpha'],
                    ['findalpha', 'findalpha'],
                ]),
                'synonyms' => $this->synonyms([['seekalpha', 'findalpha', 'query_to_index', '1.0']]),
            ]);
        }
        $documents = [
            $this->document(451, 'probe-g', 'findalpha over cap synonym target'),
            $this->document(452, 'probe-a', 'unrelated bait document'),
        ];

        return $this->run_case(
            'synonym-target-over-cap',
            $profiles,
            $documents,
            'seekalpha',
            function (array $case): void {
                $this->assert_common_explain(
                    $case,
                    $this->letter_languages(),
                    'auto_fallback_ambiguous_evidence_bounded_preflight',
                    ['probe-g', 'probe-a', 'probe-b', 'probe-c', 'probe-d']
                );
                $scored = $this->preflight_language($case, 'probe-g');
                $this->assert_same(1, $scored['synonym_hit_count'] ?? null, 'Over-cap synonym target should be selected by synonym preflight hits.');
                $this->assert_same(1, $scored['hit_count'] ?? null, 'Over-cap synonym target should have exactly one preflight hit.');
                $this->assert_result_ids($case, [451]);
                $this->assert_result_match_class($case, 'synonym');
            }
        );
    }

    private function run_phrase_synonym_source_evidence(): array
    {
        $profiles = [
            'probe-phrase' => $this->language(10, [
                'synonym_phrases' => $this->phrase_synonyms([['portal lookup', 'search site', 'query_to_index', '1.0']]),
            ]),
            'probe-bait-a' => $this->language(20, ['lexemes' => $this->lexemes([['baitalpha', 'baitalpha']])]),
            'probe-bait-b' => $this->language(30, ['lexemes' => $this->lexemes([['baitbeta', 'baitbeta']])]),
        ];
        $documents = [
            $this->document(501, 'probe-phrase', 'search site target document'),
            $this->document(502, 'probe-bait-a', 'portal lookup bait document'),
            $this->document(503, 'probe-bait-b', 'portal lookup bait document'),
        ];

        return $this->run_case(
            'phrase-synonym-source-evidence',
            $profiles,
            $documents,
            'portal lookup',
            function (array $case): void {
                $this->assert_ranked_top($case, 'probe-phrase');
                $candidate = $case['ranked'][0] ?? [];
                $this->assert_contains('portal lookup', $candidate['reasons']['synonym_sources'] ?? [], 'Phrase synonym source should be recorded as routing evidence.');
                $this->assert_common_explain(
                    $case,
                    ['probe-phrase', 'probe-bait-a', 'probe-bait-b'],
                    'auto_confident_profile_evidence',
                    ['probe-phrase']
                );
                $partition = $this->partition($case, 'probe-phrase');
                $this->assert_expansion($partition['phrase_synonym_expansions'] ?? [], 'source_phrase', 'portal lookup', 'target_phrase', 'search site', 'Phrase synonym diagnostics should include source and target phrases.');
                $this->assert_result_ids($case, [501]);
                $this->assert_result_match_class($case, 'phrase_synonym');
            }
        );
    }

    private function run_phrase_synonym_target_over_cap(): array
    {
        $profiles = [];
        foreach ($this->letter_languages() as $index => $language) {
            $profiles[$language] = $this->language(($index + 1) * 10, [
                'synonym_phrases' => $this->phrase_synonyms([['portal lookup', 'search site', 'query_to_index', '1.0']]),
            ]);
        }
        $documents = [
            $this->document(551, 'probe-g', 'search site over cap phrase target'),
            $this->document(552, 'probe-a', 'unrelated bait document'),
        ];

        return $this->run_case(
            'phrase-synonym-target-over-cap',
            $profiles,
            $documents,
            'portal lookup',
            function (array $case): void {
                $this->assert_common_explain(
                    $case,
                    $this->letter_languages(),
                    'auto_fallback_ambiguous_evidence_bounded_preflight',
                    ['probe-g', 'probe-a', 'probe-b', 'probe-c', 'probe-d']
                );
                $scored = $this->preflight_language($case, 'probe-g');
                $this->assert_same(2, $scored['phrase_synonym_hit_count'] ?? null, 'Current preflight diagnostics count both target terms in the phrase synonym target.');
                $this->assert_contains('search', $scored['terms']['phrase_synonyms'] ?? [], 'Phrase preflight terms should include the target phrase term search.');
                $this->assert_contains('site', $scored['terms']['phrase_synonyms'] ?? [], 'Phrase preflight terms should include the target phrase term site.');
                $this->assert_result_ids($case, [551]);
                $this->assert_result_match_class($case, 'phrase_synonym');
            }
        );
    }

    private function run_fuzzy_evidence_over_cap(): array
    {
        $profiles = [];
        foreach ($this->letter_languages() as $index => $language) {
            $profiles[$language] = $this->language(($index + 1) * 10);
        }
        $documents = [
            $this->document(601, 'probe-g', 'needleterm over cap fuzzy target'),
            $this->document(602, 'probe-a', 'aaaaa bbbbb bait terms'),
            $this->document(603, 'probe-b', 'ccccc ddddd bait terms'),
        ];

        return $this->run_case(
            'fuzzy-evidence-over-cap',
            $profiles,
            $documents,
            'needletrm~',
            function (array $case): void {
                $this->assert_same([], $case['ranked'], 'Fuzzy typo query should have no profile evidence before storage preflight.');
                $this->assert_common_explain(
                    $case,
                    $this->letter_languages(),
                    'auto_fallback_no_evidence_bounded_preflight',
                    ['probe-g', 'probe-a', 'probe-b', 'probe-c', 'probe-d']
                );
                $scored = $this->preflight_language($case, 'probe-g');
                $this->assert_same(1, $scored['fuzzy_hit_count'] ?? null, 'Over-cap fuzzy target should be selected by one post-filter fuzzy hit.');
                $partition = $this->partition($case, 'probe-g');
                $this->assert_expansion($partition['fuzzy_expansions'] ?? [], 'query_term', 'needletrm', 'candidate_term', 'needleterm', 'Fuzzy diagnostics should include the post-filter edit-distance match.');
                $fuzzy_candidates = [];
                foreach ($case['explain_counters']['fetch_candidate_terms_requests'] ?? [] as $request) {
                    foreach ((array) ($request['candidates'] ?? []) as $candidate) {
                        $fuzzy_candidates[] = (string) $candidate;
                    }
                }
                $this->assert_contains('needleterm', $fuzzy_candidates, 'Instrumented storage should record the fuzzy target candidate.');
                $this->assert_true(!in_array('aaaaa', $fuzzy_candidates, true), 'Length/edit-distance bait term aaaaa should not be reported as a fuzzy candidate.');
                $this->assert_true(!in_array('bbbbb', $fuzzy_candidates, true), 'Length/edit-distance bait term bbbbb should not be reported as a fuzzy candidate.');
                $this->assert_result_ids($case, [601]);
                $this->assert_result_match_class($case, 'fuzzy');
            }
        );
    }

    private function run_term_rule_evidence(): array
    {
        $profiles = [
            'probe-rule' => $this->language(10, [
                'term_rules' => $this->term_rules([
                    ['probe-rule-drop-ed', '5', '/^[a-z]+ed$/u', '', 'ed', '', '3', 'require_vowel', '', '', 'probe-auto-routing-term-rule'],
                    ['probe-rule-drop-ing', '6', '/^[a-z]+ing$/u', '', 'ing', '', '3', 'require_vowel', '', '', 'probe-auto-routing-term-rule'],
                ]),
            ]),
            'probe-bait' => $this->language(20, ['lexemes' => $this->lexemes([['baitalpha', 'baitalpha']])]),
        ];
        $documents = [
            $this->document(701, 'probe-rule', 'glimmer shimmer base target'),
            $this->document(702, 'probe-bait', 'glimmering shimmered bait document'),
        ];

        return $this->run_case(
            'term-rule-evidence',
            $profiles,
            $documents,
            'glimmering shimmered',
            function (array $case): void {
                $this->assert_ranked_top($case, 'probe-rule');
                $candidate = $case['ranked'][0] ?? [];
                $this->assert_same(3.0, $candidate['score'] ?? null, 'Two term-rule keys should meet the automatic routing confidence threshold.');
                $this->assert_contains('glimmering=>glimmer', $candidate['reasons']['term_rule_keys'] ?? [], 'Term-rule evidence should record glimmering=>glimmer.');
                $this->assert_contains('shimmered=>shimmer', $candidate['reasons']['term_rule_keys'] ?? [], 'Term-rule evidence should record shimmered=>shimmer.');
                $this->assert_common_explain(
                    $case,
                    ['probe-rule', 'probe-bait'],
                    'auto_confident_profile_evidence',
                    ['probe-rule']
                );
                $this->assert_result_ids($case, [701]);
                $this->assert_result_languages($case, ['probe-rule']);
            }
        );
    }

    private function run_term_rule_guard(): array
    {
        $profiles = [
            'probe-rule' => $this->language(10, [
                'term_rules' => $this->term_rules([
                    ['probe-rule-drop-ing', '4', '/^[a-z]+ing$/u', '', 'ing', '', '3', 'require_vowel', '', '', 'probe-auto-routing-term-rule'],
                ]),
            ]),
            'probe-bait' => $this->language(20),
        ];

        return $this->run_case(
            'term-rule-guard',
            $profiles,
            [],
            'aing',
            function (array $case): void {
                $this->assert_same([], $case['ranked'], 'Generated one-character term-rule keys should not create confident automatic routing evidence.');
                $this->assert_common_explain(
                    $case,
                    ['probe-rule', 'probe-bait'],
                    'auto_fallback_no_evidence_bounded_preflight',
                    ['probe-rule', 'probe-bait']
                );
                $this->assert_result_ids($case, []);
            }
        );
    }

    private function run_over_cap_selected_partition_fallback(): array
    {
        $profiles = [];
        foreach ($this->letter_languages() as $index => $language) {
            $profiles[$language] = $this->language(($index + 1) * 10);
        }
        $documents = [
            $this->document(801, 'probe-g', 'routefill over cap target'),
            $this->document(802, 'probe-a', 'unrelated bait document'),
            $this->document(803, 'probe-b', 'another unrelated bait document'),
        ];

        return $this->run_case(
            'over-cap-selected-partition-fallback',
            $profiles,
            $documents,
            'routefill',
            function (array $case): void {
                $this->assert_same([], $case['ranked'], 'Over-cap unknown query should have no profile evidence.');
                $this->assert_common_explain(
                    $case,
                    $this->letter_languages(),
                    'auto_fallback_no_evidence_bounded_preflight',
                    ['probe-g', 'probe-a', 'probe-b', 'probe-c', 'probe-d']
                );
                $scored = $this->preflight_language($case, 'probe-g');
                $this->assert_same(1, $scored['hit_count'] ?? null, 'The over-cap target partition should record its exact preflight hit.');
                $this->assert_same(1, $scored['exact_hit_count'] ?? null, 'The over-cap target partition should record an exact preflight hit.');
                foreach (['probe-a', 'probe-b', 'probe-c', 'probe-d'] as $language) {
                    $filled = $this->preflight_language($case, $language);
                    $this->assert_same(0, $filled['hit_count'] ?? null, 'Order-filled partition should have zero preflight hits: ' . $language);
                    $this->assert_same(true, $filled['selected'] ?? null, 'Order-filled partition should be selected: ' . $language);
                }
                foreach (['probe-e', 'probe-f'] as $language) {
                    $unselected = $this->preflight_language($case, $language);
                    $this->assert_same(false, $unselected['selected'] ?? null, 'Over-cap zero-hit partition should remain unselected: ' . $language);
                }
                $this->assert_same(['probe-g', 'probe-a', 'probe-b', 'probe-c', 'probe-d'], $case['search_counters']['fetch_postings_languages'] ?? [], 'Public search should fetch postings only for selected partitions.');
                $this->assert_result_ids($case, [801]);
            }
        );
    }

    private function run_over_cap_all_hit_fallback(): array
    {
        $profiles = [];
        $documents = [];
        foreach ($this->letter_languages() as $index => $language) {
            $profiles[$language] = $this->language(($index + 1) * 10);
            $documents[] = $this->document(850 + $index, $language, 'routeprobe all hit target');
        }

        return $this->run_case(
            'over-cap-all-hit-fallback',
            $profiles,
            $documents,
            'routeprobe',
            function (array $case): void {
                $this->assert_common_explain(
                    $case,
                    $this->letter_languages(),
                    'auto_fallback_no_evidence_bounded_preflight',
                    ['probe-a', 'probe-b', 'probe-c', 'probe-d', 'probe-e']
                );
                $this->assert_result_ids($case, [850, 851, 852, 853, 854]);
                $this->assert_same(['probe-a', 'probe-b', 'probe-c', 'probe-d', 'probe-e'], $case['search_counters']['fetch_postings_languages'] ?? [], 'All-hit fallback should not search over-cap unselected partitions.');
            }
        );
    }

    /**
     * @param array<string,array<string,mixed>> $profiles
     * @param object[] $documents
     * @return array<string,mixed>
     */
    private function run_case(string $name, array $profiles, array $documents, string $query, callable $assertions, int $limit = 10): array
    {
        $resource_root = $this->work_root . DIRECTORY_SEPARATOR . $name;
        $this->make_directory($resource_root);
        $this->assert_not_bundled_resource_root($resource_root);
        $this->write_profile_set($resource_root, $profiles);
        $validations = [
            $this->validate_generated_root($resource_root, false),
            $this->validate_generated_root($resource_root, true),
        ];

        $repository = new Language_FTS_Playground_Lexical_Profile_Repository($resource_root);
        $analyzer = new Language_FTS_Playground_Analyzer($repository);
        $storage = new Language_FTS_Playground_Auto_Routing_Probe_Storage();
        $indexer = new Language_FTS_Playground_Indexer($storage, $analyzer);
        $searcher = new Language_FTS_Playground_Searcher($storage, $analyzer);

        foreach ($documents as $document) {
            $indexer->index_post($document);
        }

        $ranked = $analyzer->rank_query_languages($query);

        $storage->reset_probe_counters();
        $explain = $searcher->explain($query, 'auto', $limit);
        $explain_counters = $storage->probe_counters();

        $storage->reset_probe_counters();
        $results = $searcher->search($query, 'auto', $limit);
        $search_counters = $storage->probe_counters();

        $case = [
            'name' => $name,
            'query' => $query,
            'resource_root' => $resource_root,
            'ranked' => $ranked,
            'explain' => $explain,
            'results' => $results,
            'explain_counters' => $explain_counters,
            'search_counters' => $search_counters,
            'validations' => $validations,
        ];
        $assertions($case);

        return [
            'name' => $name,
            'status' => 'passed',
            'query' => $query,
            'resource_root' => $resource_root,
            'validation_runtimes' => array_values(array_map(static fn(array $validation): string => (string) $validation['runtime'], $validations)),
            'enabled_languages' => $explain['language_routing']['enabled_languages'] ?? [],
            'strategy' => $explain['language_routing']['strategy'] ?? '',
            'selected_partitions' => $explain['language_routing']['selected_partitions'] ?? [],
            'ranked_candidates' => $this->summarize_ranked_candidates($ranked),
            'result_post_ids' => array_values(array_map('intval', array_column($results, 'post_id'))),
            'result_languages' => array_values(array_map('strval', array_column($results, 'matched_language'))),
            'explain_fetch_postings_languages' => $explain_counters['fetch_postings_languages'] ?? [],
            'search_fetch_postings_languages' => $search_counters['fetch_postings_languages'] ?? [],
        ];
    }

    private function prepare_work_root(): void
    {
        $resource_root = $this->options['resource_root'];
        if ($resource_root === null) {
            $this->work_root = sys_get_temp_dir() . '/language-fts-auto-routing-probe-' . str_replace('.', '-', uniqid('', true));
            $this->cleanup_work_root = true;
        } else {
            $this->work_root = Language_FTS_Playground_Lexical_Profile_Repository::normalize_resource_root($resource_root);
            $this->cleanup_work_root = false;
        }

        if (is_dir($this->work_root)) {
            $entries = array_values(array_diff(scandir($this->work_root) ?: [], ['.', '..']));
            if ($entries !== []) {
                throw new Language_FTS_Playground_Auto_Routing_Probe_Failure('Probe resource work root must be empty: ' . $this->work_root);
            }
        } else {
            $this->make_directory($this->work_root);
        }
        $this->assert_not_bundled_resource_root($this->work_root);
    }

    /**
     * @param array<string,array<string,mixed>> $profiles
     */
    private function write_profile_set(string $root, array $profiles): void
    {
        foreach ($profiles as $language => $definition) {
            $language = (string) $language;
            if (preg_match('/^probe-[a-z0-9-]+$/', $language) !== 1) {
                throw new Language_FTS_Playground_Auto_Routing_Probe_Failure('Synthetic probe language ids must start with probe-: ' . $language);
            }

            $language_dir = $root . DIRECTORY_SEPARATOR . $language;
            $this->make_directory($language_dir);
            $resources = [
                'stopwords' => 'stopwords.txt',
                'lexemes' => 'lexemes.tsv',
                'synonyms' => 'synonyms.tsv',
                'synonym_phrases' => 'synonym_phrases.tsv',
                'term_rules' => 'term_rules.tsv',
                'protected_terms' => 'protected_terms.txt',
            ];
            $profile = [
                'id' => $language,
                'label' => (string) ($definition['label'] ?? ('Auto routing probe ' . $language . ' (test only)')),
                'order' => (int) ($definition['order'] ?? 1000),
                'resources' => $resources,
            ];
            if (isset($definition['folds']) && is_array($definition['folds'])) {
                $profile['normalization'] = ['fold' => $definition['folds']];
            }
            if (isset($definition['signals']) && is_array($definition['signals'])) {
                $profile['language_signals'] = array_values(array_map('strval', $definition['signals']));
            }

            $this->write_file($language_dir . DIRECTORY_SEPARATOR . 'profile.php', "<?php\nreturn " . var_export($profile, true) . ";\n");
            $this->write_file($language_dir . DIRECTORY_SEPARATOR . 'stopwords.txt', (string) ($definition['stopwords'] ?? $this->stopwords([])));
            $this->write_file($language_dir . DIRECTORY_SEPARATOR . 'lexemes.tsv', (string) ($definition['lexemes'] ?? $this->lexemes([])));
            $this->write_file($language_dir . DIRECTORY_SEPARATOR . 'synonyms.tsv', (string) ($definition['synonyms'] ?? $this->synonyms([])));
            $this->write_file($language_dir . DIRECTORY_SEPARATOR . 'synonym_phrases.tsv', (string) ($definition['synonym_phrases'] ?? $this->phrase_synonyms([])));
            $this->write_file($language_dir . DIRECTORY_SEPARATOR . 'term_rules.tsv', (string) ($definition['term_rules'] ?? $this->term_rules([])));
            $this->write_file($language_dir . DIRECTORY_SEPARATOR . 'protected_terms.txt', (string) ($definition['protected_terms'] ?? "# protected terms\n"));

            $metadata = [
                'language_id' => $language,
                'pack_version' => '2026-06-09-auto-routing-probe',
                'pack_date' => '2026-06-09',
                'source_name' => 'Language FTS Playground auto-routing live probe fixture',
                'source_url' => 'https://example.test/language-fts-auto-routing-live-probe',
                'license_name' => 'GPL-2.0-or-later',
                'attribution_text' => 'Synthetic test-only lexical resources generated by the auto-routing live probe harness.',
                'provenance' => 'language-fts-playground-auto-routing-live-probe',
                'files' => array_merge(['profile.php'], array_values($resources)),
                'data_kind' => 'curated_seed',
            ];
            $this->write_file($language_dir . DIRECTORY_SEPARATOR . 'pack.php', "<?php\nreturn " . var_export($metadata, true) . ";\n");
        }
    }

    /**
     * @return array<string,mixed>
     */
    private function validate_generated_root(string $resource_root, bool $no_ini): array
    {
        $command = [PHP_BINARY];
        if ($no_ini) {
            $command[] = '-n';
        }
        $command[] = __DIR__ . '/validate-lexical-packs.php';
        $command[] = '--resource-root=' . $resource_root;
        $command[] = '--json';

        $lines = [];
        $exit_code = 0;
        exec(implode(' ', array_map('escapeshellarg', $command)) . ' 2>&1', $lines, $exit_code);
        $output = implode("\n", $lines);
        $decoded = json_decode($output, true);
        if ($exit_code !== 0 || !is_array($decoded) || empty($decoded['valid'])) {
            throw new Language_FTS_Playground_Auto_Routing_Probe_Failure(
                'Generated pack validation failed for ' . $resource_root . ' under ' . ($no_ini ? 'php -n' : 'php') . ': ' . substr($output, 0, 1200)
            );
        }

        return [
            'runtime' => $no_ini ? 'php -n' : 'php',
            'exit_code' => $exit_code,
            'valid' => true,
            'language_count' => count((array) ($decoded['languages'] ?? [])),
            'resource_root' => $resource_root,
        ];
    }

    /**
     * @param array<string,mixed> $overrides
     * @return array<string,mixed>
     */
    private function language(int $order, array $overrides = []): array
    {
        return array_merge(['order' => $order], $overrides);
    }

    /**
     * @return string[]
     */
    private function letter_languages(): array
    {
        return ['probe-a', 'probe-b', 'probe-c', 'probe-d', 'probe-e', 'probe-f', 'probe-g'];
    }

    /**
     * @param string[] $words
     */
    private function stopwords(array $words): string
    {
        $lines = ["# stopwords"];
        foreach ($words as $word) {
            $lines[] = (string) $word;
        }

        return implode("\n", $lines) . "\n";
    }

    /**
     * @param array<int,array{0:string,1:string,2?:string}> $rows
     */
    private function lexemes(array $rows): string
    {
        $lines = ["# observed\tcanonical\tprovenance"];
        foreach ($rows as $row) {
            $lines[] = (string) $row[0] . "\t" . (string) $row[1] . "\t" . (string) ($row[2] ?? 'probe-auto-routing-lexeme');
        }

        return implode("\n", $lines) . "\n";
    }

    /**
     * @param array<int,array{0:string,1:string,2:string,3:string,4?:string}> $rows
     */
    private function synonyms(array $rows): string
    {
        $lines = ["# source\ttarget\tdirection\tweight\tprovenance"];
        foreach ($rows as $row) {
            $lines[] = (string) $row[0] . "\t" . (string) $row[1] . "\t" . (string) $row[2] . "\t" . (string) $row[3] . "\t" . (string) ($row[4] ?? 'probe-auto-routing-synonym');
        }

        return implode("\n", $lines) . "\n";
    }

    /**
     * @param array<int,array{0:string,1:string,2:string,3:string,4?:string}> $rows
     */
    private function phrase_synonyms(array $rows): string
    {
        $lines = ["# source_terms\ttarget_terms\tdirection\tweight\tprovenance"];
        foreach ($rows as $row) {
            $lines[] = (string) $row[0] . "\t" . (string) $row[1] . "\t" . (string) $row[2] . "\t" . (string) $row[3] . "\t" . (string) ($row[4] ?? 'probe-auto-routing-phrase-synonym');
        }

        return implode("\n", $lines) . "\n";
    }

    /**
     * @param array<int,array{0:string,1:string,2:string,3:string,4:string,5:string,6:string,7:string,8:string,9:string,10:string}> $rows
     */
    private function term_rules(array $rows): string
    {
        $lines = ["# id\tmin_term_length\tpattern\tstrip_prefix\tstrip_suffix\tappend\tmin_key_length\tflags\talternate_pattern\talternate_replacement\tprovenance"];
        foreach ($rows as $row) {
            $lines[] = implode("\t", array_map('strval', $row));
        }

        return implode("\n", $lines) . "\n";
    }

    private function document(int $id, string $language, string $content, string $title = ''): object
    {
        return (object) [
            'ID' => $id,
            'language' => $language,
            'post_title' => $title !== '' ? $title : 'Probe document',
            'post_excerpt' => '',
            'post_content' => $content,
            'post_status' => 'publish',
            'post_password' => '',
        ];
    }

    /**
     * @param array<string,mixed> $case
     * @param string[] $enabled
     * @param string[] $selected
     */
    private function assert_common_explain(array $case, array $enabled, string $strategy, array $selected): void
    {
        $explain = $case['explain'];
        $routing = (array) ($explain['language_routing'] ?? []);
        $this->assert_same($case['query'], $explain['query'] ?? null, 'Explain query should echo the original query.');
        $this->assert_same('auto', $explain['requested_language'] ?? null, 'Explain should record requested language auto.');
        $this->assert_same('auto', $explain['resolved_language'] ?? null, 'Explain should resolve automatic routing as auto.');
        $this->assert_same($enabled, $routing['enabled_languages'] ?? null, 'Enabled language diagnostics should match generated profile order.');
        $this->assert_same($strategy, $routing['strategy'] ?? null, 'Routing strategy should match the expected query class.');
        $this->assert_same($selected, $routing['selected_partitions'] ?? null, 'Selected partitions should match the expected routing plan.');
        $thresholds = (array) ($routing['thresholds'] ?? []);
        foreach (['min_score', 'min_lead', 'min_ratio', 'max_partitions'] as $key) {
            $this->assert_true(array_key_exists($key, $thresholds), 'Routing thresholds should include ' . $key . '.');
        }
        $this->assert_same($selected, array_values(array_map('strval', array_column((array) ($explain['partitions'] ?? []), 'language'))), 'Partition diagnostics should follow selected partition order.');

        $preflight = (array) ($routing['preflight'] ?? []);
        if (!empty($preflight['evaluated'])) {
            $this->assert_same(5, $preflight['max_partitions'] ?? null, 'Evaluated preflight should expose the automatic partition cap.');
            $scored = (array) ($preflight['scored_languages'] ?? []);
            $this->assert_same(count($enabled), count($scored), 'Evaluated preflight should score every enabled language.');
            $selected_lookup = array_fill_keys($selected, true);
            foreach ($scored as $candidate) {
                $language = (string) ($candidate['language'] ?? '');
                foreach (['hit_count', 'exact_hit_count', 'synonym_hit_count', 'phrase_synonym_hit_count', 'fuzzy_hit_count', 'terms', 'enabled_index', 'selected'] as $key) {
                    $this->assert_true(array_key_exists($key, (array) $candidate), 'Preflight candidate should include ' . $key . ' for ' . $language . '.');
                }
                $this->assert_same(isset($selected_lookup[$language]), (bool) ($candidate['selected'] ?? false), 'Preflight selected flag should match selected partitions for ' . $language . '.');
            }
        }

        foreach ((array) ($explain['results'] ?? []) as $result) {
            foreach (['raw_score', 'normalized_score', 'rank_score', 'routing_prior', 'partition_max_score'] as $key) {
                $this->assert_true(array_key_exists($key, (array) $result), 'Automatic explain results should include ranking diagnostic ' . $key . '.');
            }
        }
    }

    /**
     * @param array<string,mixed> $case
     */
    private function assert_ranked_top(array $case, string $language): void
    {
        $this->assert_same($language, $case['ranked'][0]['language'] ?? null, 'Top ranked profile candidate should be ' . $language . '.');
    }

    /**
     * @param array<string,mixed> $case
     * @return array<string,mixed>
     */
    private function partition(array $case, string $language): array
    {
        foreach ((array) ($case['explain']['partitions'] ?? []) as $partition) {
            if ((string) ($partition['language'] ?? '') === $language) {
                return (array) $partition;
            }
        }

        throw new Language_FTS_Playground_Auto_Routing_Probe_Failure('Missing partition diagnostics for ' . $language . '.');
    }

    /**
     * @param array<string,mixed> $case
     * @return array<string,mixed>
     */
    private function preflight_language(array $case, string $language): array
    {
        foreach ((array) ($case['explain']['language_routing']['preflight']['scored_languages'] ?? []) as $candidate) {
            if ((string) ($candidate['language'] ?? '') === $language) {
                return (array) $candidate;
            }
        }

        throw new Language_FTS_Playground_Auto_Routing_Probe_Failure('Missing preflight diagnostics for ' . $language . '.');
    }

    /**
     * @param array<string,mixed> $case
     * @param int[] $expected
     */
    private function assert_result_ids(array $case, array $expected): void
    {
        $this->assert_same($expected, array_values(array_map('intval', array_column((array) ($case['explain']['results'] ?? []), 'post_id'))), 'Explain result ids should match expected target documents.');
        $this->assert_same($expected, array_values(array_map('intval', array_column((array) ($case['results'] ?? []), 'post_id'))), 'Public result ids should match expected target documents.');
    }

    /**
     * @param array<string,mixed> $case
     * @param string[] $expected
     */
    private function assert_result_languages(array $case, array $expected): void
    {
        $this->assert_same($expected, array_values(array_map('strval', array_column((array) ($case['explain']['results'] ?? []), 'matched_language'))), 'Explain result matched languages should match expected partitions.');
    }

    /**
     * @param array<string,mixed> $case
     */
    private function assert_result_match_class(array $case, string $class): void
    {
        foreach ((array) ($case['explain']['results'] ?? []) as $result) {
            if (in_array($class, (array) ($result['match_classes'] ?? []), true)) {
                return;
            }
        }

        throw new Language_FTS_Playground_Auto_Routing_Probe_Failure('Expected explain results to include match class: ' . $class);
    }

    /**
     * @param array<int,array<string,mixed>> $expansions
     */
    private function assert_expansion(array $expansions, string $source_key, string $source_value, string $target_key, string $target_value, string $message): void
    {
        foreach ($expansions as $expansion) {
            if ((string) ($expansion[$source_key] ?? '') === $source_value && (string) ($expansion[$target_key] ?? '') === $target_value) {
                return;
            }
        }

        throw new Language_FTS_Playground_Auto_Routing_Probe_Failure($message);
    }

    /**
     * @param array<int,array<string,mixed>> $ranked
     * @return array<int,array<string,mixed>>
     */
    private function summarize_ranked_candidates(array $ranked): array
    {
        $summary = [];
        foreach ($ranked as $candidate) {
            $summary[] = [
                'language' => (string) ($candidate['language'] ?? ''),
                'score' => (float) ($candidate['score'] ?? 0.0),
                'reasons' => $candidate['reasons'] ?? [],
            ];
        }

        return $summary;
    }

    private function make_directory(string $path): void
    {
        if (is_dir($path)) {
            return;
        }

        if (!mkdir($path, 0777, true) && !is_dir($path)) {
            throw new Language_FTS_Playground_Auto_Routing_Probe_Failure('Could not create directory: ' . $path);
        }
    }

    private function write_file(string $path, string $contents): void
    {
        if (file_put_contents($path, $contents) === false) {
            throw new Language_FTS_Playground_Auto_Routing_Probe_Failure('Could not write generated probe file: ' . $path);
        }
    }

    private function assert_not_bundled_resource_root(string $path): void
    {
        $default_root = realpath(Language_FTS_Playground_Lexical_Profile_Repository::default_resource_root());
        $target = realpath($path);
        if ($default_root === false || $target === false) {
            return;
        }

        if ($target === $default_root || str_starts_with($target, $default_root . DIRECTORY_SEPARATOR)) {
            throw new Language_FTS_Playground_Auto_Routing_Probe_Failure('Generated probe roots must not be inside bundled lexical resources: ' . $path);
        }
    }

    private function remove_tree(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $entries = scandir($path);
        foreach ($entries === false ? [] : $entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $child = $path . DIRECTORY_SEPARATOR . $entry;
            if (is_dir($child)) {
                $this->remove_tree($child);
            } else {
                unlink($child);
            }
        }

        rmdir($path);
    }

    private function assert_same(mixed $expected, mixed $actual, string $message): void
    {
        if ($expected !== $actual) {
            throw new Language_FTS_Playground_Auto_Routing_Probe_Failure(
                $message . ' Expected ' . $this->describe($expected) . ', got ' . $this->describe($actual) . '.'
            );
        }
    }

    private function assert_true(bool $condition, string $message): void
    {
        if (!$condition) {
            throw new Language_FTS_Playground_Auto_Routing_Probe_Failure($message);
        }
    }

    /**
     * @param mixed[] $haystack
     */
    private function assert_contains(mixed $needle, array $haystack, string $message): void
    {
        if (!in_array($needle, $haystack, true)) {
            throw new Language_FTS_Playground_Auto_Routing_Probe_Failure($message . ' Missing ' . $this->describe($needle) . ' in ' . $this->describe($haystack) . '.');
        }
    }

    private function describe(mixed $value): string
    {
        $json = json_encode($value, JSON_UNESCAPED_SLASHES);

        return is_string($json) ? $json : get_debug_type($value);
    }
}

/**
 * @param string[] $argv
 * @return array{json:bool,help:bool,keep_temp:bool,resource_root:string|null}
 */
function language_fts_auto_routing_probe_parse_args(array $argv): array
{
    $options = [
        'json' => false,
        'help' => false,
        'keep_temp' => false,
        'resource_root' => null,
    ];

    for ($i = 1; $i < count($argv); $i++) {
        $arg = (string) $argv[$i];
        if ($arg === '--help' || $arg === '-h') {
            $options['help'] = true;
            continue;
        }
        if ($arg === '--json') {
            $options['json'] = true;
            continue;
        }
        if ($arg === '--keep-temp') {
            $options['keep_temp'] = true;
            continue;
        }

        if (preg_match('/^--([^=]+)=(.*)$/', $arg, $matches) !== 1) {
            throw new InvalidArgumentException('Unknown option syntax: ' . $arg);
        }

        $name = str_replace('-', '_', (string) $matches[1]);
        $value = (string) $matches[2];
        if ($name === 'resource_root') {
            $options['resource_root'] = $value;
        } else {
            throw new InvalidArgumentException('Unknown option: --' . (string) $matches[1]);
        }
    }

    return $options;
}

function language_fts_auto_routing_probe_usage(): string
{
    return implode("\n", [
        'Usage: php language-fts-playground/tools/probe-auto-routing-live.php [options]',
        '',
        'Options:',
        '  --json                   Emit deterministic JSON summary.',
        '  --resource-root=<path>   Empty directory where probe-specific resource roots are generated.',
        '  --keep-temp              Keep generated temporary resource roots after the run.',
        '  --help                   Show this help.',
        '',
        'Examples:',
        '  php language-fts-playground/tools/probe-auto-routing-live.php --json',
        '  php -n language-fts-playground/tools/probe-auto-routing-live.php --json',
    ]) . "\n";
}

/**
 * @param array<string,mixed> $report
 */
function language_fts_auto_routing_probe_print_human(array $report): void
{
    echo 'Auto-routing live probe harness: ' . (string) ($report['status'] ?? '') . "\n";
    echo 'Probes: ' . (int) ($report['passed_count'] ?? 0) . ' passed, ' . (int) ($report['failed_count'] ?? 0) . " failed\n";
    echo 'Work root: ' . (string) ($report['work_root'] ?? '') . "\n";
    foreach ((array) ($report['probes'] ?? []) as $probe) {
        echo '- ' . (string) ($probe['name'] ?? '') . ': '
            . (string) ($probe['strategy'] ?? '') . ' -> ['
            . implode(', ', array_map('strval', (array) ($probe['selected_partitions'] ?? []))) . ']'
            . ' results [' . implode(', ', array_map('strval', (array) ($probe['result_post_ids'] ?? []))) . "]\n";
    }
    foreach ((array) ($report['failures'] ?? []) as $failure) {
        echo 'FAIL ' . (string) ($failure['probe'] ?? '') . ': ' . (string) ($failure['message'] ?? '') . "\n";
    }
}

try {
    $options = language_fts_auto_routing_probe_parse_args($argv);
    if ($options['help']) {
        echo language_fts_auto_routing_probe_usage();
        exit(0);
    }

    $runner = new Language_FTS_Playground_Auto_Routing_Probe_Runner($options);
    $report = $runner->run();
    if ($options['json']) {
        echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
    } else {
        language_fts_auto_routing_probe_print_human($report);
    }

    exit(($report['status'] ?? '') === 'passed' ? 0 : 1);
} catch (Throwable $throwable) {
    fwrite(STDERR, 'Auto-routing live probe failed: ' . $throwable->getMessage() . "\n");
    fwrite(STDERR, language_fts_auto_routing_probe_usage());
    exit(1);
}
