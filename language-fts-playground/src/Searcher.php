<?php
declare(strict_types=1);

/**
 * Runs a language-scoped BM25 search over field-aware postings loaded from storage.
 */
final class Language_FTS_Playground_Searcher
{
    /**
     * Title matches should beat body matches all else equal; alt text stays
     * searchable but deliberately carries the smallest default boost.
     *
     * @var array<string,float>
     */
    private const FIELD_BOOSTS = [
        'title' => 4.0,
        'excerpt' => 2.0,
        'content' => 2.0,
        'alt' => 1.0,
    ];

    private const SNIPPET_MAX_LENGTH = 280;
    private const AUTO_LANGUAGE_MIN_SCORE = 3.0;
    private const AUTO_LANGUAGE_MIN_LEAD = 1.5;
    private const AUTO_LANGUAGE_MIN_RATIO = 1.35;
    private const AUTO_LANGUAGE_MAX_PARTITIONS = 5;
    private const AUTO_ROUTING_PRIOR_RANK_WEIGHT = 0.000001;

    public function __construct(
        private Language_FTS_Playground_Storage_Interface $storage,
        private Language_FTS_Playground_Analyzer $analyzer,
        private float $k1 = 1.2,
        private float $b = 0.75,
        private int $fuzzy_min_length = 4,
        private int $fuzzy_candidate_limit = 128,
        private int $fuzzy_max_distance = 1,
        private float $fuzzy_score_multiplier = 0.45
    ) {
    }

    /**
     * @return array<int,array{post_id:int,score:float,matched_terms:string[],matched_fields:string[],snippet:string,matched_language:string}>
     */
    public function search(string $query, string $language, int $limit = 10): array
    {
        $limit = max(1, $limit);
        $routing = $this->search_language_plan($query, $language, false);
        $results = [];
        foreach ($routing['selected_partitions'] as $partition) {
            foreach ($this->search_partition($query, $partition, false) as $result) {
                $results[] = $result;
            }
        }
        $results = $this->annotate_auto_ranking_diagnostics($results, $routing);

        return $this->finalize_results($results, $limit);
    }

    /**
     * Build a deterministic diagnostics payload for a query without changing
     * the public search result shape.
     *
     * @return array<string,mixed>
     */
    public function explain(string $query, string $language = 'auto', int $limit = 10): array
    {
        $limit = max(1, $limit);
        $routing = $this->search_language_plan($query, $language);
        $results = [];
        $partitions = [];
        foreach ($routing['selected_partitions'] as $partition) {
            $evaluation = $this->evaluate_partition($query, $partition);
            foreach ($evaluation['results'] as $result) {
                $results[] = $result;
            }
            $partitions[] = $evaluation['diagnostics'];
        }
        $results = $this->annotate_auto_ranking_diagnostics($results, $routing);

        $ranked_results = $this->rank_results($results, $limit);
        $explain_results = [];
        foreach ($ranked_results as $result) {
            $explain_results[] = $this->explain_result($result);
        }

        $no_result_causes = [];
        if ($explain_results === []) {
            foreach ($partitions as $partition) {
                foreach ((array) ($partition['no_result_causes'] ?? []) as $cause) {
                    $no_result_causes[(string) $cause] = true;
                }
            }
        }

        return [
            'query' => $query,
            'requested_language' => (string) $language,
            'resolved_language' => $routing['resolved_language'],
            'limit' => $limit,
            'language_routing' => $routing,
            'partitions' => $partitions,
            'results' => $explain_results,
            'no_result_causes' => array_keys($no_result_causes),
        ];
    }

    /**
     * @return array{requested_language:string,resolved_language:string,enabled_languages:string[],ranked_candidates:array<int,array{language:string,score:float,reasons:array<string,string[]>}>,selected_partitions:string[],strategy:string,thresholds:array{min_score:float,min_lead:float,min_ratio:float,max_partitions:int},preflight:array<string,mixed>}
     */
    private function search_language_plan(string $query, string $language, bool $include_explicit_ranking = true): array
    {
        $requested_language = (string) $language;
        $language = $this->analyzer->canonical_search_language($language);
        $enabled = $this->analyzer->enabled_languages();
        $ranked = ($language === 'auto' || $include_explicit_ranking)
            ? $this->analyzer->rank_query_languages($query)
            : [];
        $thresholds = [
            'min_score' => self::AUTO_LANGUAGE_MIN_SCORE,
            'min_lead' => self::AUTO_LANGUAGE_MIN_LEAD,
            'min_ratio' => self::AUTO_LANGUAGE_MIN_RATIO,
            'max_partitions' => self::AUTO_LANGUAGE_MAX_PARTITIONS,
        ];

        if ($language !== 'auto') {
            return [
                'requested_language' => $requested_language,
                'resolved_language' => $language,
                'enabled_languages' => $enabled,
                'ranked_candidates' => $ranked,
                'selected_partitions' => [$language],
                'strategy' => 'explicit_language',
                'thresholds' => $thresholds,
                'preflight' => [],
            ];
        }

        $strategy = 'auto_fallback_no_evidence_bounded_preflight';
        $selected = null;
        $preflight = [];
        if ($ranked !== []) {
            $top_score = (float) $ranked[0]['score'];
            if ($top_score < self::AUTO_LANGUAGE_MIN_SCORE) {
                $strategy = 'auto_fallback_low_evidence_bounded_preflight';
            } else {
                $runner_up_score = isset($ranked[1]) ? (float) $ranked[1]['score'] : 0.0;
                $has_clear_lead = $runner_up_score <= 0.0
                    || ($top_score - $runner_up_score) >= self::AUTO_LANGUAGE_MIN_LEAD
                    || $top_score >= ($runner_up_score * self::AUTO_LANGUAGE_MIN_RATIO);

                if (!$has_clear_lead) {
                    $strategy = 'auto_fallback_ambiguous_evidence_bounded_preflight';
                } else {
                    $enabled_lookup = array_fill_keys($enabled, true);
                    $selected = [];
                    foreach ($ranked as $candidate) {
                        if ((float) $candidate['score'] < self::AUTO_LANGUAGE_MIN_SCORE) {
                            continue;
                        }

                        $candidate_language = (string) $candidate['language'];
                        if (isset($enabled_lookup[$candidate_language])) {
                            $selected[] = $candidate_language;
                        }
                    }

                    if ($selected === []) {
                        $selected = null;
                        $strategy = 'auto_fallback_no_enabled_candidates_bounded_preflight';
                    } else {
                        $selected = array_slice($selected, 0, self::AUTO_LANGUAGE_MAX_PARTITIONS);
                        $strategy = 'auto_confident_profile_evidence';
                    }
                }
            }
        }

        if ($selected === null) {
            $fallback = $this->bounded_fallback_partitions($query, $enabled);
            $selected = $fallback['selected_partitions'];
            $preflight = $fallback['preflight'];
        }

        return [
            'requested_language' => $requested_language,
            'resolved_language' => $language,
            'enabled_languages' => $enabled,
            'ranked_candidates' => $ranked,
            'selected_partitions' => $selected,
            'strategy' => $strategy,
            'thresholds' => $thresholds,
            'preflight' => $preflight,
        ];
    }

    /**
     * @param string[] $enabled
     * @return array{selected_partitions:string[],preflight:array<string,mixed>}
     */
    private function bounded_fallback_partitions(string $query, array $enabled): array
    {
        $enabled = array_values(array_map('strval', $enabled));
        $preflight = [
            'evaluated' => false,
            'max_partitions' => self::AUTO_LANGUAGE_MAX_PARTITIONS,
            'scored_languages' => [],
        ];

        if (count($enabled) <= self::AUTO_LANGUAGE_MAX_PARTITIONS) {
            return [
                'selected_partitions' => $enabled,
                'preflight' => $preflight,
            ];
        }

        $language_terms = [];
        $language_term_groups = [];
        foreach ($enabled as $language) {
            $plan = $this->parse_query($query, $language);
            $synonym_terms = $this->synonym_terms($this->analyzer->expand_query_synonyms($plan['exact_terms'], $language));
            $phrase_synonym_terms = $this->phrase_synonym_terms($this->analyzer->expand_query_synonym_phrases($plan['query_tokens'], $language));
            $fuzzy_terms = $this->flatten_fuzzy_candidates($this->resolve_fuzzy_candidates($plan['fuzzy_terms'], $language));

            $language_term_groups[$language] = [
                'exact' => $plan['exact_terms'],
                'single_token_synonyms' => $synonym_terms,
                'phrase_synonyms' => $phrase_synonym_terms,
                'fuzzy' => $fuzzy_terms,
            ];
            $language_terms[$language] = $this->unique_terms(array_merge(
                $plan['exact_terms'],
                $synonym_terms,
                $phrase_synonym_terms,
                $fuzzy_terms
            ));
        }

        $hits = $this->storage->fetch_term_language_hits($language_terms);
        $scored_languages = [];
        foreach ($enabled as $index => $language) {
            $groups = $language_term_groups[$language] ?? [
                'exact' => [],
                'single_token_synonyms' => [],
                'phrase_synonyms' => [],
                'fuzzy' => [],
            ];
            $exact_hit_count = $this->preflight_hit_count($groups['exact'], $hits[$language] ?? []);
            $synonym_hit_count = $this->preflight_hit_count($groups['single_token_synonyms'], $hits[$language] ?? []);
            $phrase_synonym_hit_count = $this->preflight_hit_count($groups['phrase_synonyms'], $hits[$language] ?? []);
            $fuzzy_hit_count = $this->preflight_hit_count($groups['fuzzy'], $hits[$language] ?? []);
            $hit_count = $exact_hit_count + $synonym_hit_count + $phrase_synonym_hit_count + $fuzzy_hit_count;

            $scored_languages[] = [
                'language' => $language,
                'hit_count' => $hit_count,
                'exact_hit_count' => $exact_hit_count,
                'synonym_hit_count' => $synonym_hit_count,
                'phrase_synonym_hit_count' => $phrase_synonym_hit_count,
                'fuzzy_hit_count' => $fuzzy_hit_count,
                'terms' => $groups,
                'enabled_index' => $index,
            ];
        }

        usort(
            $scored_languages,
            static function (array $a, array $b): int {
                return ((int) $b['hit_count'] <=> (int) $a['hit_count'])
                    ?: ((int) $b['exact_hit_count'] <=> (int) $a['exact_hit_count'])
                    ?: ((int) $b['synonym_hit_count'] <=> (int) $a['synonym_hit_count'])
                    ?: ((int) $b['phrase_synonym_hit_count'] <=> (int) $a['phrase_synonym_hit_count'])
                    ?: ((int) $b['fuzzy_hit_count'] <=> (int) $a['fuzzy_hit_count'])
                    ?: ((int) $a['enabled_index'] <=> (int) $b['enabled_index']);
            }
        );

        $selected = [];
        $selected_lookup = [];
        foreach ($scored_languages as $candidate) {
            if ((int) $candidate['hit_count'] <= 0) {
                continue;
            }

            $language = (string) $candidate['language'];
            $selected[] = $language;
            $selected_lookup[$language] = true;
            if (count($selected) >= self::AUTO_LANGUAGE_MAX_PARTITIONS) {
                return $selected;
            }
        }

        foreach ($enabled as $language) {
            if (isset($selected_lookup[$language])) {
                continue;
            }

            $selected[] = $language;
            if (count($selected) >= self::AUTO_LANGUAGE_MAX_PARTITIONS) {
                break;
            }
        }

        foreach ($scored_languages as &$candidate) {
            $language = (string) $candidate['language'];
            $candidate['selected'] = isset($selected_lookup[$language]);
        }
        unset($candidate);

        $preflight['evaluated'] = true;
        $preflight['scored_languages'] = $scored_languages;

        return [
            'selected_partitions' => $selected,
            'preflight' => $preflight,
        ];
    }

    /**
     * @param string[] $terms
     * @param array<string,bool> $hits
     */
    private function preflight_hit_count(array $terms, array $hits): int
    {
        $count = 0;
        foreach ($terms as $term) {
            if (!empty($hits[(string) $term])) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * @param array<string,string[]> $fuzzy_candidates
     * @return string[]
     */
    private function flatten_fuzzy_candidates(array $fuzzy_candidates): array
    {
        $terms = [];
        foreach ($fuzzy_candidates as $candidates) {
            foreach ($candidates as $candidate) {
                $terms[] = (string) $candidate;
            }
        }

        return $this->unique_terms($terms);
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function search_partition(string $query, string $language, bool $include_candidate_enrichment = true): array
    {
        return $this->evaluate_partition($query, $language, $include_candidate_enrichment)['results'];
    }

    /**
     * @return array{results:array<int,array<string,mixed>>,diagnostics:array<string,mixed>}
     */
    private function evaluate_partition(string $query, string $language, bool $include_candidate_enrichment = true): array
    {
        $language = $this->analyzer->canonical_language($language);
        $plan = $this->parse_query($query, $language);
        $fuzzy_candidates = $this->resolve_fuzzy_candidates($plan['fuzzy_terms'], $language);
        $fuzzy_terms = [];
        foreach ($fuzzy_candidates as $candidates) {
            foreach ($candidates as $candidate) {
                $fuzzy_terms[] = $candidate;
            }
        }

        $synonym_expansions = $this->analyzer->expand_query_synonyms($plan['exact_terms'], $language);
        $phrase_synonym_expansions = $this->analyzer->expand_query_synonym_phrases($plan['query_tokens'], $language);
        $synonym_terms = $this->synonym_terms($synonym_expansions);
        $phrase_synonym_terms = $this->phrase_synonym_terms($phrase_synonym_expansions);
        $terms = $this->unique_terms(array_merge($plan['exact_terms'], $fuzzy_terms, $synonym_terms, $phrase_synonym_terms));
        $diagnostics = [
            'language' => $language,
            'analyzed_query' => [
                'tokens' => $plan['query_tokens'],
                'exact_terms' => $plan['exact_terms'],
                'phrases' => $plan['phrases'],
                'fuzzy_terms' => $plan['fuzzy_terms'],
            ],
            'lookup_terms' => [
                'exact' => $plan['exact_terms'],
                'single_token_synonyms' => $synonym_terms,
                'phrase_synonyms' => $phrase_synonym_terms,
                'fuzzy' => $fuzzy_terms,
                'all' => $terms,
            ],
            'synonym_expansions' => $this->explain_synonym_expansions($synonym_expansions),
            'phrase_synonym_expansions' => $this->explain_phrase_synonym_expansions($phrase_synonym_expansions, $language),
            'fuzzy_expansions' => $this->explain_fuzzy_expansions($fuzzy_candidates, $language),
            'candidate_post_ids' => [],
            'document_count' => 0,
            'phrase_filters' => [],
            'results' => [],
            'no_result_causes' => [],
        ];

        if ($terms === []) {
            $diagnostics['no_result_causes'][] = 'analyzed_query_empty_after_stopwords';

            return [
                'results' => [],
                'diagnostics' => $diagnostics,
            ];
        }

        $postings = $this->storage->fetch_postings($language, $terms);
        if ($postings === []) {
            $diagnostics['no_result_causes'][] = 'no_postings_for_searched_terms';

            return [
                'results' => [],
                'diagnostics' => $diagnostics,
            ];
        }

        $candidate_lookup = [];
        foreach ($postings as $term_postings) {
            foreach ($term_postings as $post_id => $_field_tfs) {
                $candidate_lookup[(int) $post_id] = true;
            }
        }
        $candidate_ids = array_keys($candidate_lookup);
        sort($candidate_ids, SORT_NUMERIC);
        $diagnostics['candidate_post_ids'] = $candidate_ids;

        $document_lengths = $this->storage->fetch_document_lengths($language, $candidate_ids);
        if ($document_lengths === []) {
            $diagnostics['no_result_causes'][] = 'searched_partitions_contained_no_candidates';

            return [
                'results' => [],
                'diagnostics' => $diagnostics,
            ];
        }

        $document_count = $this->storage->document_count($language);
        $diagnostics['document_count'] = $document_count;
        if ($document_count <= 0) {
            $diagnostics['no_result_causes'][] = 'searched_partitions_contained_no_candidates';

            return [
                'results' => [],
                'diagnostics' => $diagnostics,
            ];
        }

        $positions = [];
        $position_terms = $this->unique_terms(array_merge(
            $this->phrase_terms($plan['phrases']),
            $this->multiword_phrase_synonym_terms($phrase_synonym_expansions)
        ));
        if ($position_terms !== []) {
            $positions = $this->storage->fetch_positions($language, $position_terms, $candidate_ids);
        }
        $diagnostics['phrase_filters'] = array_merge(
            $this->explain_query_phrase_filters($plan['phrases'], $positions, $candidate_ids),
            $this->explain_phrase_synonym_filters($phrase_synonym_expansions, $positions, $candidate_ids)
        );

        $document_fields = [];
        $document_field_metadata = [];
        if ($include_candidate_enrichment) {
            $document_fields = $this->storage->fetch_document_fields($language, $candidate_ids);
            $document_field_metadata = $this->storage->fetch_document_field_metadata($language, $candidate_ids);
        }
        $average_length = array_sum($document_lengths) / max(1, count($document_lengths));
        $has_lower_priority_match = $plan['fuzzy_terms'] !== [] || $synonym_expansions !== [] || $phrase_synonym_expansions !== [];
        $results = [];
        foreach ($candidate_ids as $post_id) {
            if (!isset($document_lengths[$post_id])) {
                continue;
            }

            if (!$this->document_matches_phrases($plan['phrases'], $positions, (int) $post_id)) {
                continue;
            }

            $score = 0.0;
            $matched_terms = [];
            $matched_fields = [];
            $match_classes = [];
            $score_breakdown = $this->empty_score_breakdown();
            $field_metadata = $document_field_metadata[$post_id] ?? [];
            $exact_match_count = 0;
            foreach ($plan['exact_terms'] as $term) {
                $field_tfs = $postings[$term][$post_id] ?? [];
                if ($field_tfs === []) {
                    continue;
                }

                $document_frequency = count($postings[$term] ?? []);
                $term_score = $this->bm25(
                    $this->weighted_term_frequency($field_tfs),
                    $document_lengths[$post_id],
                    $document_count,
                    $document_frequency,
                    $average_length
                );
                $score += $term_score;
                $matched_terms[$term] = true;
                $match_classes['exact'] = true;
                $exact_match_count++;
                $this->add_score_detail(
                    $score_breakdown,
                    $this->score_detail($term, $term, 'exact', $field_tfs, $term_score, $document_frequency, $field_metadata, $language)
                );
                foreach ($field_tfs as $field => $tf) {
                    if ((int) $tf > 0) {
                        $matched_fields[(string) $field] = true;
                    }
                }
            }

            foreach ($fuzzy_candidates as $query_term => $candidates) {
                if (isset($matched_terms[$query_term])) {
                    continue;
                }

                $best_score = 0.0;
                $best_term = '';
                $best_fields = [];
                $best_document_frequency = 0;
                foreach ($candidates as $candidate) {
                    $field_tfs = $postings[$candidate][$post_id] ?? [];
                    if ($field_tfs === []) {
                        continue;
                    }

                    $document_frequency = count($postings[$candidate] ?? []);
                    $candidate_score = $this->bm25(
                        $this->weighted_term_frequency($field_tfs),
                        $document_lengths[$post_id],
                        $document_count,
                        $document_frequency,
                        $average_length
                    ) * $this->fuzzy_score_multiplier;
                    if ($candidate_score > $best_score) {
                        $best_score = $candidate_score;
                        $best_term = $candidate;
                        $best_fields = $field_tfs;
                        $best_document_frequency = $document_frequency;
                    }
                }

                if ($best_score > 0.0) {
                    $score += $best_score;
                    $matched_terms[$query_term . '~' . $best_term] = true;
                    $match_classes['fuzzy'] = true;
                    $this->add_score_detail(
                        $score_breakdown,
                        $this->score_detail(
                            $best_term,
                            $query_term,
                            'fuzzy',
                            $best_fields,
                            $best_score,
                            $best_document_frequency,
                            $field_metadata,
                            $language,
                            [
                                'edit_distance' => levenshtein($query_term, $best_term),
                                'multiplier' => $this->fuzzy_score_multiplier,
                            ]
                        )
                    );
                    foreach ($best_fields as $field => $tf) {
                        if ((int) $tf > 0) {
                            $matched_fields[(string) $field] = true;
                        }
                    }
                }
            }

            foreach ($synonym_expansions as $query_term => $expansions) {
                if (isset($matched_terms[$query_term])) {
                    continue;
                }

                $best_score = 0.0;
                $best_term = '';
                $best_fields = [];
                $best_document_frequency = 0;
                $best_expansion = null;
                foreach ($expansions as $expansion) {
                    $candidate = (string) $expansion['term'];
                    $field_tfs = $postings[$candidate][$post_id] ?? [];
                    if ($field_tfs === []) {
                        continue;
                    }

                    $document_frequency = count($postings[$candidate] ?? []);
                    $candidate_score = $this->bm25(
                        $this->weighted_term_frequency($field_tfs),
                        $document_lengths[$post_id],
                        $document_count,
                        $document_frequency,
                        $average_length
                    ) * (float) $expansion['weight'];
                    if ($candidate_score > $best_score) {
                        $best_score = $candidate_score;
                        $best_term = $candidate;
                        $best_fields = $field_tfs;
                        $best_document_frequency = $document_frequency;
                        $best_expansion = $expansion;
                    }
                }

                if ($best_score > 0.0) {
                    $score += $best_score;
                    $matched_terms[$query_term . '=>' . $best_term] = true;
                    $match_classes['synonym'] = true;
                    $this->add_score_detail(
                        $score_breakdown,
                        $this->score_detail(
                            $best_term,
                            $query_term,
                            'synonym',
                            $best_fields,
                            $best_score,
                            $best_document_frequency,
                            $field_metadata,
                            $language,
                            [
                                'weight' => (float) ($best_expansion['weight'] ?? 0.0),
                                'direction' => (string) ($best_expansion['direction'] ?? ''),
                                'provenance' => (string) ($best_expansion['provenance'] ?? ''),
                            ]
                        )
                    );
                    foreach ($best_fields as $field => $tf) {
                        if ((int) $tf > 0) {
                            $matched_fields[(string) $field] = true;
                        }
                    }
                }
            }

            foreach ($this->best_phrase_synonym_scores($phrase_synonym_expansions, $postings, $positions, (int) $post_id, $field_metadata, $language, $document_lengths[$post_id], $document_count, $average_length) as $match) {
                $score += (float) $match['score'];
                $matched_terms[(string) $match['label']] = true;
                $match_classes['phrase_synonym'] = true;
                foreach ($match['details'] as $detail) {
                    $this->add_score_detail($score_breakdown, $detail);
                }
                foreach ($match['fields'] as $field) {
                    $matched_fields[(string) $field] = true;
                }
            }

            if ($score > 0.0) {
                $matched_field_names = $this->sort_fields(array_keys($matched_fields));
                $results[] = [
                    'post_id' => (int) $post_id,
                    'score' => $score,
                    'matched_terms' => array_keys($matched_terms),
                    'matched_fields' => $matched_field_names,
                    'snippet' => $include_candidate_enrichment
                        ? $this->build_snippet(
                            $document_fields[$post_id] ?? [],
                            $matched_field_names,
                            $terms,
                            $language
                        )
                        : '',
                    'matched_language' => $language,
                    '_snippet_terms' => $terms,
                    '_exact_match_count' => $exact_match_count,
                    '_has_lower_priority_match' => $has_lower_priority_match,
                    '_match_classes' => array_keys($match_classes),
                    '_score_breakdown' => $score_breakdown,
                ];
            }
        }

        if ($results === []) {
            $diagnostics['no_result_causes'][] = $this->phrase_filters_removed_all_candidates($diagnostics['phrase_filters'])
                ? 'phrase_filter_removed_candidates'
                : 'no_scored_results';
        }
        $diagnostics['results'] = array_map([$this, 'explain_result'], $this->rank_results($results, count($results) > 0 ? count($results) : 1));

        return [
            'results' => $results,
            'diagnostics' => $diagnostics,
        ];
    }

    /**
     * @param array<int,array<string,mixed>> $results
     * @return array<int,array{post_id:int,score:float,matched_terms:string[],matched_fields:string[],snippet:string,matched_language:string}>
     */
    private function finalize_results(array $results, int $limit): array
    {
        $results = $this->rank_results($results, $limit);
        $this->hydrate_result_snippets($results);
        foreach ($results as &$result) {
            unset(
                $result['_snippet_terms'],
                $result['_exact_match_count'],
                $result['_has_lower_priority_match'],
                $result['_match_classes'],
                $result['_score_breakdown'],
                $result['raw_score'],
                $result['normalized_score'],
                $result['rank_score'],
                $result['routing_prior'],
                $result['partition_max_score']
            );
        }
        unset($result);

        return $results;
    }

    /**
     * Fetch source field text only after public results have been ranked and
     * limited, so large candidate sets do not force full document hydration.
     *
     * @param array<int,array<string,mixed>> $results
     */
    private function hydrate_result_snippets(array &$results): void
    {
        if ($results === []) {
            return;
        }

        $post_ids_by_language = [];
        foreach ($results as $result) {
            $language = (string) ($result['matched_language'] ?? '');
            $post_id = (int) ($result['post_id'] ?? 0);
            if ($language === '' || $post_id <= 0) {
                continue;
            }

            $post_ids_by_language[$language][$post_id] = $post_id;
        }

        $fields_by_language = [];
        foreach ($post_ids_by_language as $language => $post_ids) {
            $fields_by_language[$language] = $this->storage->fetch_document_fields($language, array_values($post_ids));
        }

        foreach ($results as &$result) {
            $language = (string) ($result['matched_language'] ?? '');
            $post_id = (int) ($result['post_id'] ?? 0);
            if ($language === '' || $post_id <= 0) {
                $result['snippet'] = '';
                continue;
            }

            $result['snippet'] = $this->build_snippet(
                $fields_by_language[$language][$post_id] ?? [],
                array_values(array_map('strval', (array) ($result['matched_fields'] ?? []))),
                array_values(array_map('strval', (array) ($result['_snippet_terms'] ?? []))),
                $language
            );
        }
        unset($result);
    }

    /**
     * @param array<int,array<string,mixed>> $results
     * @param array<string,mixed> $routing
     * @return array<int,array<string,mixed>>
     */
    private function annotate_auto_ranking_diagnostics(array $results, array $routing): array
    {
        if ($results === [] || (string) ($routing['resolved_language'] ?? '') !== 'auto') {
            return $results;
        }

        $partition_max_scores = [];
        foreach ($results as $result) {
            $language = (string) ($result['matched_language'] ?? '');
            if ($language === '') {
                continue;
            }

            $raw_score = (float) ($result['score'] ?? 0.0);
            if (!isset($partition_max_scores[$language]) || $raw_score > $partition_max_scores[$language]) {
                $partition_max_scores[$language] = $raw_score;
            }
        }

        $routing_priors = $this->routing_priors_by_language((array) ($routing['ranked_candidates'] ?? []));
        foreach ($results as &$result) {
            $language = (string) ($result['matched_language'] ?? '');
            $raw_score = (float) ($result['score'] ?? 0.0);
            $partition_max_score = (float) ($partition_max_scores[$language] ?? 0.0);
            $normalized_score = $partition_max_score > 0.0
                ? max(0.0, min(1.0, $raw_score / $partition_max_score))
                : 0.0;
            $routing_prior = (float) ($routing_priors[$language] ?? 0.0);

            $result['raw_score'] = $raw_score;
            $result['partition_max_score'] = $partition_max_score;
            $result['normalized_score'] = $normalized_score;
            $result['routing_prior'] = $routing_prior;
            $result['rank_score'] = $normalized_score + ($routing_prior * self::AUTO_ROUTING_PRIOR_RANK_WEIGHT);
        }
        unset($result);

        return $results;
    }

    /**
     * @param array<int,array<string,mixed>> $ranked_candidates
     * @return array<string,float>
     */
    private function routing_priors_by_language(array $ranked_candidates): array
    {
        $scores = [];
        $max_score = 0.0;
        foreach ($ranked_candidates as $candidate) {
            if (!is_array($candidate)) {
                continue;
            }

            $language = (string) ($candidate['language'] ?? '');
            if ($language === '') {
                continue;
            }

            $score = max(0.0, (float) ($candidate['score'] ?? 0.0));
            $scores[$language] = max((float) ($scores[$language] ?? 0.0), $score);
            $max_score = max($max_score, $scores[$language]);
        }

        if ($max_score <= 0.0) {
            return [];
        }

        foreach ($scores as $language => $score) {
            $scores[$language] = $score / $max_score;
        }

        return $scores;
    }

    /**
     * @param array<int,array<string,mixed>> $results
     * @return array<int,array<string,mixed>>
     */
    private function rank_results(array $results, int $limit): array
    {
        $has_lower_priority_match = false;
        foreach ($results as $result) {
            if (!empty($result['_has_lower_priority_match'])) {
                $has_lower_priority_match = true;
                break;
            }
        }

        usort(
            $results,
            static function (array $a, array $b) use ($has_lower_priority_match): int {
                if ($has_lower_priority_match) {
                    $exact_order = $b['_exact_match_count'] <=> $a['_exact_match_count'];
                    if ($exact_order !== 0) {
                        return $exact_order;
                    }
                }

                $rank_a = (float) ($a['rank_score'] ?? $a['score'] ?? 0.0);
                $rank_b = (float) ($b['rank_score'] ?? $b['score'] ?? 0.0);

                return ($rank_b <=> $rank_a)
                    ?: (strcmp((string) $a['matched_language'], (string) $b['matched_language']))
                    ?: ($a['post_id'] <=> $b['post_id']);
            }
        );

        return array_slice($results, 0, max(1, $limit));
    }

    /**
     * @param array<string,mixed> $result
     * @return array<string,mixed>
     */
    private function explain_result(array $result): array
    {
        return [
            'post_id' => (int) ($result['post_id'] ?? 0),
            'matched_language' => (string) ($result['matched_language'] ?? ''),
            'score' => (float) ($result['score'] ?? 0.0),
            'matched_fields' => array_values(array_map('strval', (array) ($result['matched_fields'] ?? []))),
            'matched_terms' => array_values(array_map('strval', (array) ($result['matched_terms'] ?? []))),
            'match_classes' => array_values(array_map('strval', (array) ($result['_match_classes'] ?? []))),
            'score_breakdown' => is_array($result['_score_breakdown'] ?? null)
                ? $result['_score_breakdown']
                : $this->empty_score_breakdown(),
            'snippet' => (string) ($result['snippet'] ?? ''),
        ] + $this->explain_auto_ranking_diagnostics($result);
    }

    /**
     * @param array<string,mixed> $result
     * @return array<string,float>
     */
    private function explain_auto_ranking_diagnostics(array $result): array
    {
        $diagnostics = [];
        foreach (['raw_score', 'normalized_score', 'rank_score', 'routing_prior', 'partition_max_score'] as $field) {
            if (array_key_exists($field, $result)) {
                $diagnostics[$field] = (float) $result[$field];
            }
        }

        return $diagnostics;
    }

    /**
     * @return array{by_term:array<string,float>,by_class:array<string,float>,by_field:array<string,float>,details:array<int,array<string,mixed>>}
     */
    private function empty_score_breakdown(): array
    {
        return [
            'by_term' => [],
            'by_class' => [],
            'by_field' => [],
            'details' => [],
        ];
    }

    /**
     * @param array<string,mixed> $breakdown
     * @param array<string,mixed> $detail
     */
    private function add_score_detail(array &$breakdown, array $detail): void
    {
        $score = (float) ($detail['score'] ?? 0.0);
        if ($score <= 0.0) {
            return;
        }

        $term = (string) ($detail['term'] ?? '');
        $class = (string) ($detail['class'] ?? '');
        if ($term !== '') {
            $breakdown['by_term'][$term] = (float) ($breakdown['by_term'][$term] ?? 0.0) + $score;
        }
        if ($class !== '') {
            $breakdown['by_class'][$class] = (float) ($breakdown['by_class'][$class] ?? 0.0) + $score;
        }
        foreach ((array) ($detail['fields'] ?? []) as $field_detail) {
            if (!is_array($field_detail)) {
                continue;
            }

            $field = (string) ($field_detail['field'] ?? '');
            if ($field === '') {
                continue;
            }

            $breakdown['by_field'][$field] = (float) ($breakdown['by_field'][$field] ?? 0.0) + (float) ($field_detail['contribution'] ?? 0.0);
        }
        $breakdown['details'][] = $detail;
    }

    /**
     * @param array<string,int> $field_tfs
     * @param array<string,array{language:string,language_provenance:string}> $field_metadata
     * @param array<string,mixed> $extra
     * @return array<string,mixed>
     */
    private function score_detail(
        string $term,
        string $query_term,
        string $class,
        array $field_tfs,
        float $score,
        int $document_frequency,
        array $field_metadata = [],
        string $default_language = '',
        array $extra = []
    ): array {
        $detail = [
            'term' => $term,
            'query_term' => $query_term,
            'class' => $class,
            'score' => $score,
            'document_frequency' => $document_frequency,
            'fields' => $this->score_field_details($field_tfs, $score, $field_metadata, $default_language),
        ];

        foreach ($extra as $key => $value) {
            $detail[(string) $key] = $value;
        }

        return $detail;
    }

    /**
     * @param array<string,int> $field_tfs
     * @param array<string,array{language:string,language_provenance:string}> $field_metadata
     * @return array<int,array{field:string,term_frequency:int,boost:float,weighted_term_frequency:float,contribution:float,language:string,language_provenance:string}>
     */
    private function score_field_details(array $field_tfs, float $score, array $field_metadata = [], string $default_language = ''): array
    {
        $weighted_total = $this->weighted_term_frequency($field_tfs);
        $details = [];
        foreach ($this->sort_fields(array_keys($field_tfs)) as $field) {
            $tf = max(0, (int) ($field_tfs[$field] ?? 0));
            if ($tf <= 0) {
                continue;
            }

            $boost = self::FIELD_BOOSTS[$field] ?? 1.0;
            $weighted_tf = $tf * $boost;
            $metadata = $this->score_field_metadata($field_metadata, $field, $default_language);
            $details[] = [
                'field' => $field,
                'term_frequency' => $tf,
                'boost' => $boost,
                'weighted_term_frequency' => $weighted_tf,
                'contribution' => $weighted_total > 0.0 ? $score * ($weighted_tf / $weighted_total) : 0.0,
                'language' => $metadata['language'],
                'language_provenance' => $metadata['language_provenance'],
            ];
        }

        return $details;
    }

    /**
     * @param array<string,array{language:string,language_provenance:string}> $field_metadata
     * @return array{language:string,language_provenance:string}
     */
    private function score_field_metadata(array $field_metadata, string $field, string $default_language): array
    {
        $metadata = $field_metadata[$field] ?? [];
        $metadata = is_array($metadata) ? $metadata : [];
        $language = trim((string) ($metadata['language'] ?? $default_language));
        $provenance = trim((string) ($metadata['language_provenance'] ?? 'fallback'));

        return [
            'language' => $language !== '' ? $language : $default_language,
            'language_provenance' => $provenance !== '' ? $provenance : 'fallback',
        ];
    }

    /**
     * @param array<string,array<int,array{term:string,weight:float,source:string,direction:string,provenance:string}>> $expansions
     * @return array<int,array{source_key:string,target_key:string,target_keys:string[],direction:string,weight:float,provenance:string}>
     */
    private function explain_synonym_expansions(array $expansions): array
    {
        $explained = [];
        foreach ($expansions as $source => $targets) {
            foreach ($targets as $target) {
                $target_key = (string) $target['term'];
                $explained[] = [
                    'source_key' => (string) $source,
                    'target_key' => $target_key,
                    'target_keys' => [$target_key],
                    'direction' => (string) $target['direction'],
                    'weight' => (float) $target['weight'],
                    'provenance' => (string) $target['provenance'],
                ];
            }
        }

        return $explained;
    }

    /**
     * @param array<int,array{source_terms:string[],target_terms:string[],source:string,target:string,weight:float,direction:string,provenance:string,offset:int}> $expansions
     * @return array<int,array<string,mixed>>
     */
    private function explain_phrase_synonym_expansions(array $expansions, string $language): array
    {
        $explained = [];
        foreach ($expansions as $expansion) {
            $explained[] = [
                'source_phrase' => (string) $expansion['source'],
                'target_phrase' => (string) $expansion['target'],
                'source_terms' => array_values(array_map('strval', $expansion['source_terms'])),
                'target_terms' => array_values(array_map('strval', $expansion['target_terms'])),
                'direction' => (string) $expansion['direction'],
                'weight' => (float) $expansion['weight'],
                'provenance' => (string) $expansion['provenance'],
                'offset' => (int) $expansion['offset'],
                'searched_language' => $language,
            ];
        }

        return $explained;
    }

    /**
     * @param array<string,string[]> $fuzzy_candidates
     * @return array<int,array{query_term:string,candidate_term:string,edit_distance:int,searched_language:string}>
     */
    private function explain_fuzzy_expansions(array $fuzzy_candidates, string $language): array
    {
        $explained = [];
        foreach ($fuzzy_candidates as $query_term => $candidates) {
            foreach ($candidates as $candidate) {
                $explained[] = [
                    'query_term' => (string) $query_term,
                    'candidate_term' => (string) $candidate,
                    'edit_distance' => levenshtein((string) $query_term, (string) $candidate),
                    'searched_language' => $language,
                ];
            }
        }

        return $explained;
    }

    /**
     * @param array<int,array<int,string[]>> $phrases
     * @param array<string,array<int,int[]>> $positions
     * @param int[] $candidate_ids
     * @return array<int,array<string,mixed>>
     */
    private function explain_query_phrase_filters(array $phrases, array $positions, array $candidate_ids): array
    {
        $filters = [];
        foreach ($phrases as $phrase) {
            $filters[] = [
                'type' => 'query_phrase',
                'phrase' => $this->phrase_label($phrase),
                'terms' => $phrase,
                'documents' => $this->explain_phrase_filter_documents($phrase, $positions, $candidate_ids),
            ];
        }

        return $filters;
    }

    /**
     * @param array<int,array{source_terms:string[],target_terms:string[],source:string,target:string,weight:float,direction:string,provenance:string,offset:int}> $expansions
     * @param array<string,array<int,int[]>> $positions
     * @param int[] $candidate_ids
     * @return array<int,array<string,mixed>>
     */
    private function explain_phrase_synonym_filters(array $expansions, array $positions, array $candidate_ids): array
    {
        $filters = [];
        foreach ($expansions as $expansion) {
            $target_terms = $this->unique_terms($expansion['target_terms']);
            if (count($target_terms) < 2) {
                continue;
            }

            $phrase = $this->terms_to_phrase($target_terms);
            $filters[] = [
                'type' => 'phrase_synonym_target',
                'source_phrase' => (string) $expansion['source'],
                'target_phrase' => (string) $expansion['target'],
                'terms' => $phrase,
                'documents' => $this->explain_phrase_filter_documents($phrase, $positions, $candidate_ids),
            ];
        }

        return $filters;
    }

    /**
     * @param array<int,string[]> $phrase
     * @param array<string,array<int,int[]>> $positions
     * @param int[] $candidate_ids
     * @return array<int,array{post_id:int,passed:bool}>
     */
    private function explain_phrase_filter_documents(array $phrase, array $positions, array $candidate_ids): array
    {
        $documents = [];
        foreach ($candidate_ids as $post_id) {
            $documents[] = [
                'post_id' => (int) $post_id,
                'passed' => $this->document_matches_phrase($phrase, $positions, (int) $post_id),
            ];
        }

        return $documents;
    }

    /**
     * @param array<int,string[]> $phrase
     */
    private function phrase_label(array $phrase): string
    {
        $tokens = [];
        foreach ($phrase as $token_keys) {
            $tokens[] = implode('|', array_values(array_map('strval', $token_keys)));
        }

        return implode(' ', $tokens);
    }

    /**
     * @param array<int,array<string,mixed>> $phrase_filters
     */
    private function phrase_filters_removed_all_candidates(array $phrase_filters): bool
    {
        $query_phrase_filters = [];
        foreach ($phrase_filters as $filter) {
            if ((string) ($filter['type'] ?? '') === 'query_phrase') {
                $query_phrase_filters[] = $filter;
            }
        }

        if ($query_phrase_filters !== []) {
            return $this->conjunctive_phrase_filters_removed_all_candidates($query_phrase_filters);
        }

        return $this->individual_phrase_filters_removed_all_candidates($phrase_filters);
    }

    /**
     * @param array<int,array<string,mixed>> $phrase_filters
     */
    private function conjunctive_phrase_filters_removed_all_candidates(array $phrase_filters): bool
    {
        $filter_count = count($phrase_filters);
        $passed_by_post_id = [];
        $saw_document = false;
        foreach ($phrase_filters as $filter) {
            foreach ((array) ($filter['documents'] ?? []) as $document) {
                if (!is_array($document)) {
                    continue;
                }

                $saw_document = true;
                if (!empty($document['passed'])) {
                    $post_id = (int) ($document['post_id'] ?? 0);
                    $passed_by_post_id[$post_id] = (int) ($passed_by_post_id[$post_id] ?? 0) + 1;
                }
            }
        }

        foreach ($passed_by_post_id as $passed_count) {
            if ($passed_count === $filter_count) {
                return false;
            }
        }

        return $saw_document;
    }

    /**
     * @param array<int,array<string,mixed>> $phrase_filters
     */
    private function individual_phrase_filters_removed_all_candidates(array $phrase_filters): bool
    {
        $saw_document = false;
        foreach ($phrase_filters as $filter) {
            foreach ((array) ($filter['documents'] ?? []) as $document) {
                if (!is_array($document)) {
                    continue;
                }

                $saw_document = true;
                if (!empty($document['passed'])) {
                    return false;
                }
            }
        }

        return $saw_document;
    }

    /**
     * @param array<string,array<int,array{term:string,weight:float,source:string,direction:string,provenance:string}>> $expansions
     * @return string[]
     */
    private function synonym_terms(array $expansions): array
    {
        $terms = [];
        foreach ($expansions as $targets) {
            foreach ($targets as $target) {
                $terms[] = (string) $target['term'];
            }
        }

        return $this->unique_terms($terms);
    }

    /**
     * @param array<int,array{source_terms:string[],target_terms:string[],source:string,target:string,weight:float,direction:string,provenance:string,offset:int}> $expansions
     * @return string[]
     */
    private function phrase_synonym_terms(array $expansions): array
    {
        $terms = [];
        foreach ($expansions as $expansion) {
            foreach ($expansion['target_terms'] as $term) {
                $terms[] = (string) $term;
            }
        }

        return $this->unique_terms($terms);
    }

    /**
     * @param array<int,array{source_terms:string[],target_terms:string[],source:string,target:string,weight:float,direction:string,provenance:string,offset:int}> $expansions
     * @return string[]
     */
    private function multiword_phrase_synonym_terms(array $expansions): array
    {
        $terms = [];
        foreach ($expansions as $expansion) {
            if (count($expansion['target_terms']) < 2) {
                continue;
            }

            foreach ($expansion['target_terms'] as $term) {
                $terms[] = (string) $term;
            }
        }

        return $this->unique_terms($terms);
    }

    /**
     * @param array<int,array{source_terms:string[],target_terms:string[],source:string,target:string,weight:float,direction:string,provenance:string,offset:int}> $expansions
     * @param array<string,array<int,array<string,int>>> $postings
     * @param array<string,array<int,int[]>> $positions
     * @param array<string,array{language:string,language_provenance:string}> $field_metadata
     * @return array<int,array{score:float,label:string,fields:string[],details:array<int,array<string,mixed>>}>
     */
    private function best_phrase_synonym_scores(
        array $expansions,
        array $postings,
        array $positions,
        int $post_id,
        array $field_metadata,
        string $language,
        int $document_length,
        int $document_count,
        float $average_length
    ): array {
        $best_by_source = [];
        foreach ($expansions as $expansion) {
            $target_terms = $this->unique_terms($expansion['target_terms']);
            if ($target_terms === []) {
                continue;
            }

            if (count($target_terms) > 1 && !$this->document_matches_phrase($this->terms_to_phrase($target_terms), $positions, $post_id)) {
                continue;
            }

            $score = 0.0;
            $fields = [];
            $details = [];
            $weight = (float) $expansion['weight'];
            foreach ($target_terms as $target_term) {
                $field_tfs = $postings[$target_term][$post_id] ?? [];
                if ($field_tfs === []) {
                    $score = 0.0;
                    $details = [];
                    break;
                }

                $document_frequency = count($postings[$target_term] ?? []);
                $term_score = $this->bm25(
                    $this->weighted_term_frequency($field_tfs),
                    $document_length,
                    $document_count,
                    $document_frequency,
                    $average_length
                ) * $weight;
                $score += $term_score;
                $details[] = $this->score_detail(
                    $target_term,
                    (string) $expansion['source'],
                    'phrase_synonym',
                    $field_tfs,
                    $term_score,
                    $document_frequency,
                    $field_metadata,
                    $language,
                    [
                        'source_phrase' => (string) $expansion['source'],
                        'target_phrase' => (string) $expansion['target'],
                        'weight' => $weight,
                        'direction' => (string) $expansion['direction'],
                        'provenance' => (string) $expansion['provenance'],
                    ]
                );
                foreach ($field_tfs as $field => $tf) {
                    if ((int) $tf > 0) {
                        $fields[(string) $field] = true;
                    }
                }
            }

            if ($score <= 0.0) {
                continue;
            }

            $source_key = (string) ($expansion['offset'] ?? 0) . "\t" . (string) $expansion['source'];
            $label = (string) $expansion['source'] . '=>' . (string) $expansion['target'];
            $candidate = [
                'score' => $score,
                'label' => $label,
                'fields' => $this->sort_fields(array_keys($fields)),
                'details' => $details,
            ];

            $existing = $best_by_source[$source_key] ?? null;
            if (
                !is_array($existing) ||
                $candidate['score'] > (float) $existing['score'] ||
                ($candidate['score'] === (float) $existing['score'] && strcmp($candidate['label'], (string) $existing['label']) < 0)
            ) {
                $best_by_source[$source_key] = $candidate;
            }
        }

        ksort($best_by_source, SORT_STRING);

        return array_values($best_by_source);
    }

    /**
     * @return array{exact_terms:string[],phrases:array<int,array<int,string[]>>,fuzzy_terms:string[],query_tokens:array<int,string[]>}
     */
    private function parse_query(string $query, string $language): array
    {
        $matches = [];
        $matched = preg_match_all('/"([^"]*)"|(\S+)/u', $query, $matches, PREG_SET_ORDER);
        if ($matched === false || $matched === 0) {
            return [
                'exact_terms' => [],
                'phrases' => [],
                'fuzzy_terms' => [],
                'query_tokens' => [],
            ];
        }

        $exact_terms = [];
        $phrases = [];
        $fuzzy_terms = [];
        $query_tokens = [];
        foreach ($matches as $match) {
            if (array_key_exists(1, $match) && trim((string) $match[1]) !== '') {
                $phrase = $this->analyzer->analyze_text_token_keys((string) $match[1], $language);
                if ($phrase === []) {
                    continue;
                }

                $phrases[] = $phrase;
                array_push($query_tokens, ...$phrase);
                foreach ($phrase as $token_keys) {
                    foreach ($token_keys as $key) {
                        $exact_terms[$key] = true;
                    }
                }
                continue;
            }

            $raw = (string) ($match[2] ?? $match[0]);
            $is_fuzzy = str_ends_with($raw, '~');
            $term_query = $is_fuzzy ? substr($raw, 0, -1) : $raw;
            $token_keys_list = $this->analyzer->analyze_text_token_keys($term_query, $language);
            array_push($query_tokens, ...$token_keys_list);
            foreach ($token_keys_list as $token_keys) {
                foreach ($token_keys as $term) {
                    $exact_terms[$term] = true;
                    if ($is_fuzzy && strlen($term) >= $this->fuzzy_min_length) {
                        $fuzzy_terms[$term] = true;
                    }
                }
            }
        }

        return [
            'exact_terms' => array_values(array_map('strval', array_keys($exact_terms))),
            'phrases' => $phrases,
            'fuzzy_terms' => array_values(array_map('strval', array_keys($fuzzy_terms))),
            'query_tokens' => $query_tokens,
        ];
    }

    /**
     * @param string[] $terms
     * @return array<string,string[]>
     */
    private function resolve_fuzzy_candidates(array $terms, string $language): array
    {
        $resolved = [];
        foreach ($this->unique_terms($terms) as $term) {
            if (strlen($term) < $this->fuzzy_min_length) {
                continue;
            }

            $candidates = [];
            foreach ($this->storage->fetch_candidate_terms($language, $term, $this->fuzzy_max_distance, $this->fuzzy_candidate_limit) as $candidate) {
                if ($candidate === $term || abs(strlen($candidate) - strlen($term)) > $this->fuzzy_max_distance) {
                    continue;
                }

                if (levenshtein($term, $candidate) <= $this->fuzzy_max_distance) {
                    $candidates[$candidate] = true;
                    if (count($candidates) >= $this->fuzzy_candidate_limit) {
                        break;
                    }
                }
            }

            if ($candidates !== []) {
                $resolved[$term] = array_keys($candidates);
            }
        }

        return $resolved;
    }

    /**
     * @param array<int,array<int,string[]>> $phrases
     * @return string[]
     */
    private function phrase_terms(array $phrases): array
    {
        $terms = [];
        foreach ($phrases as $phrase) {
            foreach ($phrase as $token_keys) {
                foreach ($token_keys as $key) {
                    $terms[] = $key;
                }
            }
        }

        return $this->unique_terms($terms);
    }

    /**
     * @param string[] $terms
     * @return array<int,string[]>
     */
    private function terms_to_phrase(array $terms): array
    {
        $phrase = [];
        foreach ($terms as $term) {
            $term = trim((string) $term);
            if ($term !== '') {
                $phrase[] = [$term];
            }
        }

        return $phrase;
    }

    /**
     * @param array<int,array<int,string[]>> $phrases
     * @param array<string,array<int,int[]>> $positions
     */
    private function document_matches_phrases(array $phrases, array $positions, int $post_id): bool
    {
        foreach ($phrases as $phrase) {
            if (!$this->document_matches_phrase($phrase, $positions, $post_id)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array<int,string[]> $phrase
     * @param array<string,array<int,int[]>> $positions
     */
    private function document_matches_phrase(array $phrase, array $positions, int $post_id): bool
    {
        if ($phrase === []) {
            return true;
        }

        $start_positions = array_keys($this->position_lookup($phrase[0], $positions, $post_id));
        foreach ($start_positions as $start) {
            $start = (int) $start;
            foreach ($phrase as $offset => $token_keys) {
                if (!$this->token_has_position($token_keys, $positions, $post_id, $start + (int) $offset)) {
                    continue 2;
                }
            }

            return true;
        }

        return false;
    }

    /**
     * @param string[] $token_keys
     * @param array<string,array<int,int[]>> $positions
     * @return array<int,bool>
     */
    private function position_lookup(array $token_keys, array $positions, int $post_id): array
    {
        $lookup = [];
        foreach ($token_keys as $key) {
            foreach ($positions[$key][$post_id] ?? [] as $position) {
                $lookup[(int) $position] = true;
            }
        }

        return $lookup;
    }

    /**
     * @param string[] $token_keys
     * @param array<string,array<int,int[]>> $positions
     */
    private function token_has_position(array $token_keys, array $positions, int $post_id, int $position): bool
    {
        $lookup = $this->position_lookup($token_keys, $positions, $post_id);

        return isset($lookup[$position]);
    }

    /**
     * @param string[] $terms
     * @return string[]
     */
    private function unique_terms(array $terms): array
    {
        $unique = [];
        foreach ($terms as $term) {
            $term = trim((string) $term);
            if ($term !== '') {
                $unique[$term] = true;
            }
        }

        return array_values(array_map('strval', array_keys($unique)));
    }

    /**
     * @param array<string,int> $field_tfs
     */
    private function weighted_term_frequency(array $field_tfs): float
    {
        $tf = 0.0;
        foreach ($field_tfs as $field => $field_tf) {
            $boost = self::FIELD_BOOSTS[(string) $field] ?? 1.0;
            $tf += max(0, (int) $field_tf) * $boost;
        }

        return $tf;
    }

    /**
     * @param string[] $fields
     * @return string[]
     */
    private function sort_fields(array $fields): array
    {
        $present = array_fill_keys($fields, true);
        $sorted = [];
        foreach (array_keys(self::FIELD_BOOSTS) as $field) {
            if (isset($present[$field])) {
                $sorted[] = $field;
                unset($present[$field]);
            }
        }

        $unknown = array_keys($present);
        sort($unknown, SORT_STRING);

        return array_merge($sorted, $unknown);
    }

    /**
     * @param array<string,string> $field_texts
     * @param string[] $matched_fields
     * @param string[] $query_terms
     */
    private function build_snippet(array $field_texts, array $matched_fields, array $query_terms, string $language): string
    {
        foreach ($matched_fields as $field) {
            $source = $this->analyzer->normalize_plain_text((string) ($field_texts[$field] ?? ''));
            if ($source === '') {
                continue;
            }

            if ($this->first_match_offset($source, $query_terms, $language) === null) {
                continue;
            }

            return $this->highlight_terms(
                $this->excerpt_around_first_match($source, $query_terms, $language),
                $query_terms,
                $language
            );
        }

        return '';
    }

    /**
     * @param string[] $query_terms
     */
    private function excerpt_around_first_match(string $source, array $query_terms, string $language): string
    {
        $source_length = strlen($source);
        if ($source_length <= self::SNIPPET_MAX_LENGTH) {
            return $source;
        }

        $offset = $this->first_match_offset($source, $query_terms, $language) ?? 0;
        $radius = intdiv(self::SNIPPET_MAX_LENGTH, 2);
        // Preserve the byte-sized snippet window while moving cuts to valid UTF-8 codepoint boundaries.
        $start = $this->utf8_boundary_at_or_after($source, max(0, $offset - $radius));
        if ($start === null) {
            return $source;
        }

        $end = $this->utf8_boundary_at_or_before($source, min($source_length, $start + self::SNIPPET_MAX_LENGTH));
        if ($end === null || $end <= $start) {
            return $source;
        }

        $excerpt = substr($source, $start, $end - $start);
        if (!is_string($excerpt)) {
            return $source;
        }

        return ($start > 0 ? '... ' : '') . $excerpt . ($end < $source_length ? ' ...' : '');
    }

    private function utf8_boundary_at_or_after(string $source, int $offset): ?int
    {
        $source_length = strlen($source);
        if ($offset <= 0) {
            return 0;
        }

        if ($offset >= $source_length) {
            return $source_length;
        }

        $characters = $this->utf8_characters_with_offsets($source);
        if ($characters === null) {
            return null;
        }

        foreach ($characters as $character) {
            $start = (int) ($character[1] ?? 0);
            $end = $start + strlen((string) ($character[0] ?? ''));
            if ($start >= $offset) {
                return $start;
            }

            if ($end >= $offset) {
                return $end;
            }
        }

        return $source_length;
    }

    private function utf8_boundary_at_or_before(string $source, int $offset): ?int
    {
        $source_length = strlen($source);
        if ($offset <= 0) {
            return 0;
        }

        if ($offset >= $source_length) {
            return $source_length;
        }

        $characters = $this->utf8_characters_with_offsets($source);
        if ($characters === null) {
            return null;
        }

        $boundary = 0;
        foreach ($characters as $character) {
            $start = (int) ($character[1] ?? 0);
            $end = $start + strlen((string) ($character[0] ?? ''));
            if ($start > $offset) {
                return $boundary;
            }

            if ($start === $offset) {
                return $start;
            }

            if ($end > $offset) {
                return $start;
            }

            $boundary = $end;
        }

        return $source_length;
    }

    /**
     * @return array<int,array{0:string,1:int}>|null
     */
    private function utf8_characters_with_offsets(string $source): ?array
    {
        $matches = [];
        $match_count = preg_match_all('/./us', $source, $matches, PREG_OFFSET_CAPTURE);
        if ($match_count === false) {
            return null;
        }

        return $matches[0];
    }

    /**
     * @param string[] $query_terms
     */
    private function first_match_offset(string $source, array $query_terms, string $language): ?int
    {
        $matches = [];
        $match_count = preg_match_all('/[\p{L}\p{N}]+/u', $source, $matches, PREG_OFFSET_CAPTURE);
        if ($match_count === false || $match_count === 0) {
            return null;
        }

        foreach ($matches[0] as $match) {
            $token = (string) ($match[0] ?? '');
            if ($this->token_matches_query($token, $query_terms, $language)) {
                return (int) ($match[1] ?? 0);
            }
        }

        return null;
    }

    /**
     * @param string[] $query_terms
     */
    private function highlight_terms(string $source, array $query_terms, string $language): string
    {
        $matches = [];
        $match_count = preg_match_all('/[\p{L}\p{N}]+/u', $source, $matches, PREG_OFFSET_CAPTURE);
        if ($match_count === false || $match_count === 0) {
            return self::escape_html($source);
        }

        $highlighted = '';
        $cursor = 0;
        foreach ($matches[0] as $match) {
            $token = (string) ($match[0] ?? '');
            $offset = (int) ($match[1] ?? 0);
            $length = strlen($token);

            $highlighted .= self::escape_html(substr($source, $cursor, $offset - $cursor));
            $escaped_token = self::escape_html($token);
            $highlighted .= $this->token_matches_query($token, $query_terms, $language)
                ? '<mark>' . $escaped_token . '</mark>'
                : $escaped_token;
            $cursor = $offset + $length;
        }

        $highlighted .= self::escape_html(substr($source, $cursor));

        return $highlighted;
    }

    /**
     * @param string[] $query_terms
     */
    private function token_matches_query(string $token, array $query_terms, string $language): bool
    {
        $query_lookup = array_fill_keys($query_terms, true);
        foreach ($this->analyzer->analyze_text($token, $language) as $term) {
            if (isset($query_lookup[$term])) {
                return true;
            }
        }

        return false;
    }

    private static function escape_html(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private function bm25(float $tf, int $document_length, int $document_count, int $document_frequency, float $average_length): float
    {
        if ($tf <= 0.0) {
            return 0.0;
        }

        $document_length = max(1, $document_length);
        $document_frequency = max(1, $document_frequency);
        $average_length = max(1.0, $average_length);

        $idf = log(1.0 + (($document_count - $document_frequency + 0.5) / ($document_frequency + 0.5)));
        $normalizer = $this->k1 * (1.0 - $this->b + $this->b * ($document_length / $average_length));

        return $idf * (($tf * ($this->k1 + 1.0)) / ($tf + $normalizer));
    }
}
