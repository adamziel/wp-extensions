<?php
declare(strict_types=1);

/**
 * Runs a language-scoped BM25 search over postings loaded from storage.
 */
final class Language_FTS_Playground_Searcher
{
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
     * @return array<int,array{post_id:int,score:float,matched_terms:string[]}>
     */
    public function search(string $query, string $language, int $limit = 10): array
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

        $terms = $this->unique_terms(array_merge($plan['exact_terms'], $fuzzy_terms));
        if ($terms === []) {
            return [];
        }

        $postings = $this->storage->fetch_postings($language, $terms);
        if ($postings === []) {
            return [];
        }

        $candidate_ids = [];
        foreach ($postings as $term_postings) {
            foreach ($term_postings as $post_id => $_tf) {
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
        if ($plan['phrases'] !== []) {
            $positions = $this->storage->fetch_positions($language, $this->phrase_terms($plan['phrases']), array_keys($candidate_ids));
        }

        $average_length = array_sum($document_lengths) / max(1, count($document_lengths));
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
            $exact_match_count = 0;
            foreach ($plan['exact_terms'] as $term) {
                $tf = $postings[$term][$post_id] ?? 0;
                if ($tf <= 0) {
                    continue;
                }

                $document_frequency = count($postings[$term] ?? []);
                $score += $this->bm25($tf, $document_lengths[$post_id], $document_count, $document_frequency, $average_length);
                $matched_terms[$term] = true;
                $exact_match_count++;
            }

            foreach ($fuzzy_candidates as $query_term => $candidates) {
                if (isset($matched_terms[$query_term])) {
                    continue;
                }

                $best_score = 0.0;
                $best_term = '';
                foreach ($candidates as $candidate) {
                    $tf = $postings[$candidate][$post_id] ?? 0;
                    if ($tf <= 0) {
                        continue;
                    }

                    $document_frequency = count($postings[$candidate] ?? []);
                    $candidate_score = $this->bm25($tf, $document_lengths[$post_id], $document_count, $document_frequency, $average_length) * $this->fuzzy_score_multiplier;
                    if ($candidate_score > $best_score) {
                        $best_score = $candidate_score;
                        $best_term = $candidate;
                    }
                }

                if ($best_score > 0.0) {
                    $score += $best_score;
                    $matched_terms[$query_term . '~' . $best_term] = true;
                }
            }

            if ($score > 0.0) {
                $results[] = [
                    'post_id' => (int) $post_id,
                    'score' => $score,
                    'matched_terms' => array_keys($matched_terms),
                    '_exact_match_count' => $exact_match_count,
                ];
            }
        }

        $has_fuzzy = $plan['fuzzy_terms'] !== [];
        usort(
            $results,
            static function (array $a, array $b) use ($has_fuzzy): int {
                if ($has_fuzzy) {
                    $exact_order = $b['_exact_match_count'] <=> $a['_exact_match_count'];
                    if ($exact_order !== 0) {
                        return $exact_order;
                    }
                }

                return ($b['score'] <=> $a['score']) ?: ($a['post_id'] <=> $b['post_id']);
            }
        );

        $results = array_slice($results, 0, max(1, $limit));
        foreach ($results as &$result) {
            unset($result['_exact_match_count']);
        }
        unset($result);

        return $results;
    }

    /**
     * @return array{exact_terms:string[],phrases:array<int,array<int,string[]>>,fuzzy_terms:string[]}
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
            ];
        }

        $exact_terms = [];
        $phrases = [];
        $fuzzy_terms = [];
        foreach ($matches as $match) {
            if (array_key_exists(1, $match) && trim((string) $match[1]) !== '') {
                $phrase = $this->analyzer->analyze_text_token_keys((string) $match[1], $language);
                if ($phrase === []) {
                    continue;
                }

                $phrases[] = $phrase;
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
            $terms = $this->analyzer->analyze_query($term_query, $language);
            foreach ($terms as $term) {
                $exact_terms[$term] = true;
                if ($is_fuzzy && strlen($term) >= $this->fuzzy_min_length) {
                    $fuzzy_terms[$term] = true;
                }
            }
        }

        return [
            'exact_terms' => array_keys($exact_terms),
            'phrases' => $phrases,
            'fuzzy_terms' => array_keys($fuzzy_terms),
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

    private function bm25(int $tf, int $document_length, int $document_count, int $document_frequency, float $average_length): float
    {
        $document_length = max(1, $document_length);
        $document_frequency = max(1, $document_frequency);
        $average_length = max(1.0, $average_length);

        $idf = log(1.0 + (($document_count - $document_frequency + 0.5) / ($document_frequency + 0.5)));
        $normalizer = $this->k1 * (1.0 - $this->b + $this->b * ($document_length / $average_length));

        return $idf * (($tf * ($this->k1 + 1.0)) / ($tf + $normalizer));
    }
}
