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
        $language = $this->analyzer->canonical_search_language($language);
        $limit = max(1, $limit);
        if ($language !== 'auto') {
            return $this->finalize_results($this->search_partition($query, $language), $limit);
        }

        $results = [];
        foreach ($this->automatic_search_partitions($query) as $partition) {
            foreach ($this->search_partition($query, $partition) as $result) {
                $results[] = $result;
            }
        }

        return $this->finalize_results($results, $limit);
    }

    /**
     * @return string[]
     */
    private function automatic_search_partitions(string $query): array
    {
        $enabled = $this->analyzer->enabled_languages();
        $ranked = $this->analyzer->rank_query_languages($query);
        if ($ranked === []) {
            return $enabled;
        }

        $top_score = (float) $ranked[0]['score'];
        if ($top_score < self::AUTO_LANGUAGE_MIN_SCORE) {
            return $enabled;
        }

        $runner_up_score = isset($ranked[1]) ? (float) $ranked[1]['score'] : 0.0;
        $has_clear_lead = $runner_up_score <= 0.0
            || ($top_score - $runner_up_score) >= self::AUTO_LANGUAGE_MIN_LEAD
            || $top_score >= ($runner_up_score * self::AUTO_LANGUAGE_MIN_RATIO);

        if (!$has_clear_lead) {
            return $enabled;
        }

        $enabled_lookup = array_fill_keys($enabled, true);
        $partitions = [];
        foreach ($ranked as $candidate) {
            if ((float) $candidate['score'] < self::AUTO_LANGUAGE_MIN_SCORE) {
                continue;
            }

            $language = (string) $candidate['language'];
            if (isset($enabled_lookup[$language])) {
                $partitions[] = $language;
            }
        }

        return $partitions !== [] ? $partitions : $enabled;
    }

    /**
     * @return array<int,array{post_id:int,score:float,matched_terms:string[],matched_fields:string[],snippet:string,matched_language:string,_exact_match_count:int,_has_lower_priority_match:bool}>
     */
    private function search_partition(string $query, string $language): array
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
        if ($terms === []) {
            return [];
        }

        $postings = $this->storage->fetch_postings($language, $terms);
        if ($postings === []) {
            return [];
        }

        $candidate_ids = [];
        foreach ($postings as $term_postings) {
            foreach ($term_postings as $post_id => $_field_tfs) {
                $candidate_ids[(int) $post_id] = true;
            }
        }

        $document_lengths = $this->storage->fetch_document_lengths($language, array_keys($candidate_ids));
        if ($document_lengths === []) {
            return [];
        }

        $document_count = $this->storage->document_count($language);
        if ($document_count <= 0) {
            return [];
        }

        $positions = [];
        $position_terms = $this->unique_terms(array_merge(
            $this->phrase_terms($plan['phrases']),
            $this->multiword_phrase_synonym_terms($phrase_synonym_expansions)
        ));
        if ($position_terms !== []) {
            $positions = $this->storage->fetch_positions($language, $position_terms, array_keys($candidate_ids));
        }

        $document_fields = $this->storage->fetch_document_fields($language, array_keys($candidate_ids));
        $average_length = array_sum($document_lengths) / max(1, count($document_lengths));
        $has_lower_priority_match = $plan['fuzzy_terms'] !== [] || $synonym_expansions !== [] || $phrase_synonym_expansions !== [];
        $results = [];
        foreach (array_keys($candidate_ids) as $post_id) {
            if (!isset($document_lengths[$post_id])) {
                continue;
            }

            if (!$this->document_matches_phrases($plan['phrases'], $positions, (int) $post_id)) {
                continue;
            }

            $score = 0.0;
            $matched_terms = [];
            $matched_fields = [];
            $exact_match_count = 0;
            foreach ($plan['exact_terms'] as $term) {
                $field_tfs = $postings[$term][$post_id] ?? [];
                if ($field_tfs === []) {
                    continue;
                }

                $document_frequency = count($postings[$term] ?? []);
                $score += $this->bm25(
                    $this->weighted_term_frequency($field_tfs),
                    $document_lengths[$post_id],
                    $document_count,
                    $document_frequency,
                    $average_length
                );
                $matched_terms[$term] = true;
                $exact_match_count++;
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
                    }
                }

                if ($best_score > 0.0) {
                    $score += $best_score;
                    $matched_terms[$query_term . '~' . $best_term] = true;
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
                    }
                }

                if ($best_score > 0.0) {
                    $score += $best_score;
                    $matched_terms[$query_term . '=>' . $best_term] = true;
                    foreach ($best_fields as $field => $tf) {
                        if ((int) $tf > 0) {
                            $matched_fields[(string) $field] = true;
                        }
                    }
                }
            }

            foreach ($this->best_phrase_synonym_scores($phrase_synonym_expansions, $postings, $positions, (int) $post_id, $document_lengths[$post_id], $document_count, $average_length) as $match) {
                $score += (float) $match['score'];
                $matched_terms[(string) $match['label']] = true;
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
                    'snippet' => $this->build_snippet(
                        $document_fields[$post_id] ?? [],
                        $matched_field_names,
                        $terms,
                        $language
                    ),
                    'matched_language' => $language,
                    '_exact_match_count' => $exact_match_count,
                    '_has_lower_priority_match' => $has_lower_priority_match,
                ];
            }
        }

        return $results;
    }

    /**
     * @param array<int,array{post_id:int,score:float,matched_terms:string[],matched_fields:string[],snippet:string,matched_language:string,_exact_match_count:int,_has_lower_priority_match:bool}> $results
     * @return array<int,array{post_id:int,score:float,matched_terms:string[],matched_fields:string[],snippet:string,matched_language:string}>
     */
    private function finalize_results(array $results, int $limit): array
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

                return ($b['score'] <=> $a['score'])
                    ?: (strcmp((string) $a['matched_language'], (string) $b['matched_language']))
                    ?: ($a['post_id'] <=> $b['post_id']);
            }
        );

        $results = array_slice($results, 0, max(1, $limit));
        foreach ($results as &$result) {
            unset($result['_exact_match_count'], $result['_has_lower_priority_match']);
        }
        unset($result);

        return $results;
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
     * @return array<int,array{score:float,label:string,fields:string[]}>
     */
    private function best_phrase_synonym_scores(
        array $expansions,
        array $postings,
        array $positions,
        int $post_id,
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
            foreach ($target_terms as $target_term) {
                $field_tfs = $postings[$target_term][$post_id] ?? [];
                if ($field_tfs === []) {
                    $score = 0.0;
                    break;
                }

                $document_frequency = count($postings[$target_term] ?? []);
                $score += $this->bm25(
                    $this->weighted_term_frequency($field_tfs),
                    $document_length,
                    $document_count,
                    $document_frequency,
                    $average_length
                );
                foreach ($field_tfs as $field => $tf) {
                    if ((int) $tf > 0) {
                        $fields[(string) $field] = true;
                    }
                }
            }

            $score *= (float) $expansion['weight'];
            if ($score <= 0.0) {
                continue;
            }

            $source_key = (string) ($expansion['offset'] ?? 0) . "\t" . (string) $expansion['source'];
            $label = (string) $expansion['source'] . '=>' . (string) $expansion['target'];
            $candidate = [
                'score' => $score,
                'label' => $label,
                'fields' => $this->sort_fields(array_keys($fields)),
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
            'exact_terms' => array_keys($exact_terms),
            'phrases' => $phrases,
            'fuzzy_terms' => array_keys($fuzzy_terms),
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

        return array_keys($unique);
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
