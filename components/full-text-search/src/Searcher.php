<?php
declare(strict_types=1);

/**
 * Scores indexed documents for a query using language-aware BM25.
 *
 * The searcher builds a language-aware query plan, reads matching postings and
 * active document lengths per partition, then applies optional product-facing
 * filtering and result enrichment.
 */
final class WP_FTS_Searcher
{
    private const DEFAULT_AUTO_FAST_MODE_THRESHOLD = 2000;
    private const DEFAULT_FAST_MODE_CANDIDATE_CAP = 1000;

    /**
     * @param WP_FTS_Storage $storage Storage backend containing postings and
     *        per-language metadata.
     * @param object $analyzer Analyzer object exposing query analysis methods.
     * @param float $k1 BM25 term-frequency saturation parameter.
     * @param float $b BM25 document-length normalization parameter.
     */
    public function __construct(
        private WP_FTS_Storage $storage,
        private object $analyzer,
        private float $k1 = 1.2,
        private float $b = 0.75,
    ) {
    }

    /**
     * Search the index for documents matching a query.
     *
     * `mode` may be `OR` or `AND`; `AND` requires every logical query term to
     * have a posting for a document. `limit` is clamped to at least 1 and
     * `offset` enables pagination. Language can be supplied with `query_lang`,
     * `lang`, or `language` for the legacy single-partition path. `langs` or
     * `languages` accepts a list of exact language partitions. Fallback search is
     * opt-in via `language_fallback`, `fallback_to_default_lang`,
     * `fallback_lang`, or `fallback_languages`, and can be disabled with
     * `disable_language_fallback`.
     *
     * Product options are opt-in to preserve the legacy return shape:
     * `include_total` returns a payload with `total`, `limit`, `offset`, and
     * `results`; `include_metadata` adds WordPress result fields; and
     * `include_snippets` builds bounded snippets from stored extracted text.
     * `post_type`, `post_status`, `date_after`, and `date_before` filter only
     * when the storage backend exposes document metadata. `fast_top_k` or
     * `approximate_top_k` enables an explicit approximate mode that can trade
     * recall, ranking, and total-count accuracy for latency. Broad searches also
     * auto-enable that mode when the estimated candidate count exceeds the
     * configured threshold. Pass `exact_top_k`, `exact`, or an explicit false fast
     * option to force exact scoring. Prefix/phrase search is intentionally not
     * emulated on whole-term postings; pass `search_extension` to provide a backend
     * that can do it honestly.
     *
     * @param array<string,mixed> $opts
     * @return array<int,array<string,mixed>>|array{total:int,limit:int,offset:int,query_lang:string,results:array<int,array<string,mixed>>}
     *         Results sorted by exact/fallback rank, descending score, and
     *         ascending doc id for ties, or a pagination payload when
     *         `include_total` is true.
     * @throws InvalidArgumentException If `mode` is not `OR` or `AND`.
     * @throws LogicException If the analyzer does not provide a query analyzer.
     */
    public function search(string $query, array $opts = []): array
    {
        $extensionResults = $this->extension_results($query, $opts);
        if ($extensionResults !== null) {
            return $extensionResults;
        }

        $mode = strtoupper((string) ($opts['mode'] ?? 'OR'));
        if (!in_array($mode, ['OR', 'AND'], true)) {
            throw new InvalidArgumentException('Search mode must be OR or AND.');
        }
        $limit = max(1, (int) ($opts['limit'] ?? 10));
        $offset = max(0, (int) ($opts['offset'] ?? 0));

        $groups = $this->build_query_groups($query, $opts);
        $responseLang = $this->response_query_language($opts, $groups);
        if ($groups === []) {
            return $this->format_response([], 0, $opts, $responseLang);
        }

        $metadataFilter = $this->has_metadata_filters($opts) ? $this->metadata_filter_values($opts) : null;
        $useBoundedTopK = $this->can_use_bounded_top_k($opts, $offset);
        $candidateCap = $this->fast_candidate_cap($opts, $limit + $offset, $groups, $mode, $metadataFilter);
        $results = $this->score_query_groups($groups, $mode, $useBoundedTopK ? $limit : null, $metadataFilter, $candidateCap);
        if (!$useBoundedTopK) {
            usort($results, [self::class, 'compare_ranked_results']);
        }

        $total = count($results);
        $page = $useBoundedTopK ? $results : array_slice($results, $offset, $limit);
        if ($this->should_enrich_results($opts) && $page !== []) {
            $pageIds = array_column($page, 'doc_id');
            $pageMetadata = WP_FTS_StorageCompat::get_doc_metadata($this->storage, $pageIds);
            $page = $this->enrich_results($page, $pageMetadata, $query, $opts, $groups, $responseLang);
        }

        $this->strip_internal_rank($page);

        return $this->format_response($page, $total, $opts, $responseLang);
    }

    /**
     * Score a language-aware query plan.
     *
     * Each group represents one logical query term. A group may contain multiple
     * language alternatives, for example exact `fr` plus fallback `en`, or the
     * same untagged query term expanded across explicit `langs`.
     *
     * @param array<int,array<int,array{key:string,lang:string,term:string,rank:int}>> $groups
     * @param int|null $topLimit Keep only the best K ranked rows when callers do
     *        not need totals or offsets.
     * @param array{post_types:string[],post_statuses:string[],date_after:?string,date_before:?string}|null $metadataFilter
     *        Optional exact metadata filter to apply before scoring.
     * @param int|null $candidateCap Approximate opt-in cap on candidate ids to
     *        score. Null keeps default exact behavior.
     * @return array<int,array{doc_id:int,score:float,_rank:int}>
     */
    private function score_query_groups(
        array $groups,
        string $mode,
        ?int $topLimit = null,
        ?array $metadataFilter = null,
        ?int $candidateCap = null
    ): array
    {
        $termsByKey = $this->terms_by_key($groups);

        if ($termsByKey === []) {
            return [];
        }

        $postingsByTerm = WP_FTS_StorageCompat::get_postings($this->storage, array_keys($termsByKey), $candidateCap);
        if ($postingsByTerm === []) {
            return [];
        }

        if ($mode === 'AND') {
            foreach ($groups as $alternatives) {
                $hasAvailableTerm = false;
                foreach ($alternatives as $alternative) {
                    if (isset($postingsByTerm[$alternative['key']])) {
                        $hasAvailableTerm = true;
                        break;
                    }
                }
                if (!$hasAvailableTerm) {
                    return [];
                }
            }
        }

        /** @var array<string,array<int,int>> $decodedByTerm */
        $decodedByTerm = [];
        /** @var array<int,bool> $allCandidateDocIds */
        $allCandidateDocIds = [];
        /** @var array<int,array<int,bool>> $groupDocIds */
        $groupDocIds = [];

        foreach ($postingsByTerm as $term => $postings) {
            if (!isset($termsByKey[$term])) {
                continue;
            }

            $decodedByTerm[$term] = $postings;
        }

        foreach ($decodedByTerm as $term => $postings) {
            foreach ($postings as $docId => $tf) {
                $docId = (int) $docId;
                $allCandidateDocIds[$docId] = true;
                if ($mode === 'AND') {
                    foreach ($termsByKey[$term]['groups'] as $groupId => $_rank) {
                        $groupDocIds[$groupId][$docId] = true;
                    }
                }
            }

            if ($candidateCap !== null && $mode === 'OR' && count($allCandidateDocIds) >= $candidateCap) {
                $allCandidateDocIds = $this->cap_doc_id_set($allCandidateDocIds, $candidateCap);
                break;
            }
        }

        if ($allCandidateDocIds === []) {
            return [];
        }

        $scoringDocIds = $mode === 'AND'
            ? $this->intersect_group_doc_ids($groupDocIds, count($groups))
            : $allCandidateDocIds;
        if ($scoringDocIds === []) {
            return [];
        }
        if ($candidateCap !== null) {
            $scoringDocIds = $this->cap_doc_id_set($scoringDocIds, $candidateCap);
        }

        // Default IDF stays based on the full active posting lists, not on the
        // later AND/metadata-restricted scoring set. Fast mode uses an approximate
        // stored posting count to avoid scanning every active row.
        $docLengthCandidateIds = $candidateCap === null ? array_keys($allCandidateDocIds) : array_keys($scoringDocIds);
        $languages = [];
        foreach ($termsByKey as $termInfo) {
            $languages[$termInfo['lang']] = true;
        }

        /** @var array<string,array<int,int>> $docLengthsByLang */
        $docLengthsByLang = [];
        /** @var array<string,array{doc_count:int,len_sum:int}> $metaByLang */
        $metaByLang = [];
        foreach (array_keys($languages) as $lang) {
            $docLengths = WP_FTS_StorageCompat::get_doc_lengths($this->storage, $docLengthCandidateIds, $lang);
            if ($docLengths === []) {
                continue;
            }

            $meta = WP_FTS_StorageCompat::get_meta($this->storage, $lang);
            if ((int) $meta['doc_count'] <= 0) {
                continue;
            }

            $docLengthsByLang[$lang] = $docLengths;
            $metaByLang[$lang] = $meta;
        }

        if ($docLengthsByLang === []) {
            return [];
        }

        /** @var array<string,array<int,bool>> $activeDocIdsByLang */
        $activeDocIdsByLang = [];
        foreach ($docLengthsByLang as $lang => $docLengths) {
            $activeDocIdsByLang[$lang] = array_fill_keys(array_keys($docLengths), true);
        }

        if ($metadataFilter !== null) {
            $matchingDocIds = WP_FTS_StorageCompat::filter_doc_ids_by_metadata(
                $this->storage,
                array_keys($scoringDocIds),
                $metadataFilter['post_types'],
                $metadataFilter['post_statuses'],
                $metadataFilter['date_after'],
                $metadataFilter['date_before']
            );
            $scoringDocIds = array_fill_keys($matchingDocIds, true);
            if ($scoringDocIds === []) {
                return [];
            }
        }

        /** @var array<string,array{lang:string,doc_count:int,avg_doc_len:float,idf:float,groups:array<int,int>,min_rank:int,max_rank:int,multiplier:float}> $scoringTerms */
        $scoringTerms = [];
        foreach ($decodedByTerm as $term => $postings) {
            $lang = $termsByKey[$term]['lang'];
            if (!isset($docLengthsByLang[$lang], $metaByLang[$lang], $activeDocIdsByLang[$lang])) {
                continue;
            }

            if ($candidateCap === null) {
                $df = 0;
                foreach ($postings as $docId => $_tf) {
                    if (isset($activeDocIdsByLang[$lang][(int) $docId])) {
                        $df++;
                    }
                }
            } else {
                $df = count($postings);
            }
            if ($df <= 0) {
                continue;
            }

            $meta = $metaByLang[$lang];
            $docCount = max(0, (int) $meta['doc_count']);
            if ($docCount === 0) {
                continue;
            }

            $groupsForTerm = $termsByKey[$term]['groups'];
            $avgDocLen = $meta['len_sum'] > 0 ? $meta['len_sum'] / $docCount : 1.0;
            $idf = log(1.0 + (($docCount - $df + 0.5) / ($df + 0.5)));
            $multiplier = $this->query_rank_score_multiplier($groupsForTerm);
            $scoringTerms[$term] = [
                'lang' => $lang,
                'doc_count' => $docCount,
                'avg_doc_len' => $avgDocLen,
                'idf' => $idf,
                'groups' => $groupsForTerm,
                'min_rank' => min($groupsForTerm),
                'max_rank' => max($groupsForTerm),
                'multiplier' => $multiplier,
            ];
        }
        if ($scoringTerms === []) {
            return [];
        }

        /** @var array<int,float> $scores */
        $scores = [];
        /** @var array<int,int> $bestRankByDoc */
        $bestRankByDoc = [];
        /** @var array<int,array<int,int>> $matchedGroupRanksByDoc */
        $matchedGroupRanksByDoc = [];
        if ($this->should_score_by_doc($scoringDocIds, $decodedByTerm, $scoringTerms, $mode, $metadataFilter, $candidateCap)) {
            $this->score_candidate_docs(
                $scoringDocIds,
                $decodedByTerm,
                $docLengthsByLang,
                $scoringTerms,
                $mode,
                $scores,
                $bestRankByDoc,
                $matchedGroupRanksByDoc
            );
        } else {
            $this->score_posting_terms(
                $scoringDocIds,
                $decodedByTerm,
                $docLengthsByLang,
                $scoringTerms,
                $mode,
                $scores,
                $bestRankByDoc,
                $matchedGroupRanksByDoc
            );
        }
        if ($scores === []) {
            return [];
        }

        $results = [];
        foreach ($scores as $docId => $score) {
            if ($mode === 'AND' && count($matchedGroupRanksByDoc[$docId] ?? []) < count($groups)) {
                continue;
            }
            if ($score > 0.0) {
                $rank = $mode === 'AND'
                    ? max($matchedGroupRanksByDoc[$docId])
                    : ($bestRankByDoc[$docId] ?? 0);
                $row = [
                    'doc_id' => (int) $docId,
                    'score' => $score,
                    '_rank' => $rank,
                ];
                if ($topLimit === null) {
                    $results[] = $row;
                } else {
                    $this->insert_bounded_top_k_result($results, $row, $topLimit);
                }
            }
        }

        return $results;
    }

    /**
     * Keep the first N candidate ids in deterministic posting order.
     *
     * @param array<int,bool> $docIds
     * @return array<int,bool>
     */
    private function cap_doc_id_set(array $docIds, int $candidateCap): array
    {
        $candidateCap = max(1, $candidateCap);
        if (count($docIds) <= $candidateCap) {
            return $docIds;
        }

        return array_slice($docIds, 0, $candidateCap, true);
    }

    /**
     * Pick a scoring loop that avoids scanning irrelevant posting rows.
     *
     * @param array<int,bool> $scoringDocIds
     * @param array<string,array<int,int>> $decodedByTerm
     * @param array<string,array{lang:string,doc_count:int,avg_doc_len:float,idf:float,groups:array<int,int>,min_rank:int,max_rank:int,multiplier:float}> $scoringTerms
     * @param array{post_types:string[],post_statuses:string[],date_after:?string,date_before:?string}|null $metadataFilter
     */
    private function should_score_by_doc(
        array $scoringDocIds,
        array $decodedByTerm,
        array $scoringTerms,
        string $mode,
        ?array $metadataFilter,
        ?int $candidateCap
    ): bool {
        if ($candidateCap !== null || $mode === 'AND' || $metadataFilter !== null) {
            return true;
        }

        return count($scoringDocIds) * max(1, count($scoringTerms)) < $this->posting_row_count($decodedByTerm, $scoringTerms);
    }

    /**
     * @param array<string,array<int,int>> $decodedByTerm
     * @param array<string,array{lang:string,doc_count:int,avg_doc_len:float,idf:float,groups:array<int,int>,min_rank:int,max_rank:int,multiplier:float}> $scoringTerms
     */
    private function posting_row_count(array $decodedByTerm, array $scoringTerms): int
    {
        $count = 0;
        foreach (array_keys($scoringTerms) as $term) {
            $count += count($decodedByTerm[$term] ?? []);
        }

        return $count;
    }

    /**
     * Score by candidate document when the candidate set is narrower than the
     * fetched postings.
     *
     * @param array<int,bool> $scoringDocIds
     * @param array<string,array<int,int>> $decodedByTerm
     * @param array<string,array<int,int>> $docLengthsByLang
     * @param array<string,array{lang:string,doc_count:int,avg_doc_len:float,idf:float,groups:array<int,int>,min_rank:int,max_rank:int,multiplier:float}> $scoringTerms
     * @param array<int,float> $scores
     * @param array<int,int> $bestRankByDoc
     * @param array<int,array<int,int>> $matchedGroupRanksByDoc
     */
    private function score_candidate_docs(
        array $scoringDocIds,
        array $decodedByTerm,
        array $docLengthsByLang,
        array $scoringTerms,
        string $mode,
        array &$scores,
        array &$bestRankByDoc,
        array &$matchedGroupRanksByDoc
    ): void {
        foreach ($scoringDocIds as $docId => $_present) {
            $docId = (int) $docId;
            foreach ($scoringTerms as $term => $termInfo) {
                $lang = $termInfo['lang'];
                if (!isset($decodedByTerm[$term][$docId], $docLengthsByLang[$lang][$docId])) {
                    continue;
                }

                $score = $this->bm25_with_idf(
                    (int) $decodedByTerm[$term][$docId],
                    $docLengthsByLang[$lang][$docId],
                    $termInfo['idf'],
                    $termInfo['avg_doc_len']
                ) * $termInfo['multiplier'];
                if ($score <= 0.0) {
                    continue;
                }

                $scores[$docId] = ($scores[$docId] ?? 0.0) + $score;
                if ($mode === 'AND') {
                    foreach ($termInfo['groups'] as $groupId => $rank) {
                        $matchedGroupRanksByDoc[$docId][$groupId] = min(
                            $matchedGroupRanksByDoc[$docId][$groupId] ?? $rank,
                            $rank
                        );
                    }
                } else {
                    $bestRankByDoc[$docId] = min($bestRankByDoc[$docId] ?? $termInfo['min_rank'], $termInfo['min_rank']);
                }
            }
        }
    }

    /**
     * Score by posting row for broad exact queries where every fetched row is a
     * real candidate.
     *
     * @param array<int,bool> $scoringDocIds
     * @param array<string,array<int,int>> $decodedByTerm
     * @param array<string,array<int,int>> $docLengthsByLang
     * @param array<string,array{lang:string,doc_count:int,avg_doc_len:float,idf:float,groups:array<int,int>,min_rank:int,max_rank:int,multiplier:float}> $scoringTerms
     * @param array<int,float> $scores
     * @param array<int,int> $bestRankByDoc
     * @param array<int,array<int,int>> $matchedGroupRanksByDoc
     */
    private function score_posting_terms(
        array $scoringDocIds,
        array $decodedByTerm,
        array $docLengthsByLang,
        array $scoringTerms,
        string $mode,
        array &$scores,
        array &$bestRankByDoc,
        array &$matchedGroupRanksByDoc
    ): void {
        foreach ($scoringTerms as $term => $termInfo) {
            $lang = $termInfo['lang'];
            $docLengths = $docLengthsByLang[$lang];
            foreach ($decodedByTerm[$term] as $docId => $tf) {
                $docId = (int) $docId;
                if (!isset($scoringDocIds[$docId], $docLengths[$docId])) {
                    continue;
                }

                $score = $this->bm25_with_idf(
                    (int) $tf,
                    $docLengths[$docId],
                    $termInfo['idf'],
                    $termInfo['avg_doc_len']
                ) * $termInfo['multiplier'];
                if ($score <= 0.0) {
                    continue;
                }

                $scores[$docId] = ($scores[$docId] ?? 0.0) + $score;
                if ($mode === 'AND') {
                    foreach ($termInfo['groups'] as $groupId => $rank) {
                        $matchedGroupRanksByDoc[$docId][$groupId] = min(
                            $matchedGroupRanksByDoc[$docId][$groupId] ?? $rank,
                            $rank
                        );
                    }
                } else {
                    $bestRankByDoc[$docId] = min($bestRankByDoc[$docId] ?? $termInfo['min_rank'], $termInfo['min_rank']);
                }
            }
        }
    }

    /**
     * Intersect AND query candidate groups, starting with the rarest group.
     *
     * @param array<int,array<int,bool>> $groupDocIds
     * @return array<int,bool>
     */
    private function intersect_group_doc_ids(array $groupDocIds, int $groupCount): array
    {
        if (count($groupDocIds) < $groupCount) {
            return [];
        }

        uasort($groupDocIds, static fn(array $a, array $b): int => count($a) <=> count($b));
        $intersected = null;
        foreach ($groupDocIds as $docIds) {
            if ($docIds === []) {
                return [];
            }

            if ($intersected === null) {
                $intersected = $docIds;
                continue;
            }

            foreach (array_keys($intersected) as $docId) {
                if (!isset($docIds[(int) $docId])) {
                    unset($intersected[$docId]);
                }
            }
            if ($intersected === []) {
                return [];
            }
        }

        return $intersected ?? [];
    }

    /**
     * Flatten logical query groups into stored term metadata for scoring/probing.
     *
     * @param array<int,array<int,array{key:string,lang:string,term:string,rank:int}>> $groups
     * @return array<string,array{lang:string,groups:array<int,int>}>
     */
    private function terms_by_key(array $groups): array
    {
        $termsByKey = [];
        foreach ($groups as $groupId => $alternatives) {
            foreach ($alternatives as $alternative) {
                $key = $alternative['key'];
                $termsByKey[$key]['lang'] = $alternative['lang'];
                $termsByKey[$key]['groups'][$groupId] = min(
                    $termsByKey[$key]['groups'][$groupId] ?? $alternative['rank'],
                    $alternative['rank']
                );
            }
        }

        return $termsByKey;
    }

    /**
     * Use bounded top-K only when callers need the first page by ranking.
     */
    private function can_use_bounded_top_k(array $opts, int $offset): bool
    {
        return $offset === 0
            && empty($opts['include_total']);
    }

    /**
     * Resolve the approximate candidate cap for explicit or automatic fast mode.
     *
     * `candidate_cap`/`max_candidates` alone are inert so callers cannot
     * accidentally degrade recall. `fast_top_k` may be boolean or an integer cap.
     *
     * @param array<int,array<int,array{key:string,lang:string,term:string,rank:int}>> $groups
     * @param array{post_types:string[],post_statuses:string[],date_after:?string,date_before:?string}|null $metadataFilter
     */
    private function fast_candidate_cap(
        array $opts,
        int $minimumCandidates,
        array $groups,
        string $mode,
        ?array $metadataFilter
    ): ?int
    {
        if ($this->explicit_exact_top_k_requested($opts)) {
            return null;
        }

        $fastTopK = $this->explicit_fast_top_k_value($opts);
        if ($fastTopK !== null) {
            return $this->resolved_fast_candidate_cap($opts, $minimumCandidates, $fastTopK);
        }

        if (!$this->auto_fast_mode_enabled()) {
            return null;
        }

        $threshold = $this->auto_fast_mode_threshold();
        $estimatedCandidates = $this->estimate_candidate_count($groups, $mode, $metadataFilter, $threshold);
        if ($estimatedCandidates <= $threshold) {
            return null;
        }

        return $this->resolved_fast_candidate_cap($opts, $minimumCandidates, null);
    }

    /**
     * Return a caller-requested fast option value, or null when fast mode was not
     * explicitly requested.
     */
    private function explicit_fast_top_k_value(array $opts): mixed
    {
        foreach (['fast_top_k', 'approximate_top_k'] as $key) {
            if (array_key_exists($key, $opts) && $this->truthy_option($opts[$key])) {
                return $opts[$key];
            }
        }

        return null;
    }

    /**
     * Detect public options that deliberately force exact scoring.
     */
    private function explicit_exact_top_k_requested(array $opts): bool
    {
        if ($this->truthy_option($opts['exact_top_k'] ?? false) || $this->truthy_option($opts['exact'] ?? false)) {
            return true;
        }

        $hasFastOption = false;
        foreach (['fast_top_k', 'approximate_top_k'] as $key) {
            if (array_key_exists($key, $opts)) {
                $hasFastOption = true;
                if ($this->truthy_option($opts[$key])) {
                    return false;
                }
            }
        }

        return $hasFastOption;
    }

    /**
     * Resolve the cap used once fast/approximate mode is active.
     */
    private function resolved_fast_candidate_cap(array $opts, int $minimumCandidates, mixed $fastTopK): int
    {
        $cap = $this->positive_int_option($opts['candidate_cap'] ?? $opts['max_candidates'] ?? null);
        if ($cap === null && is_numeric($fastTopK) && (int) $fastTopK > 1) {
            $cap = (int) $fastTopK;
        }
        if ($cap === null) {
            $cap = $this->default_fast_candidate_cap();
        }

        return max(max(1, $minimumCandidates), $cap);
    }

    /**
     * Keep auto fast mode enabled by default, with a constant kill switch.
     */
    private function auto_fast_mode_enabled(): bool
    {
        if (!defined('WP_FTS_FAST_MODE_ENABLED')) {
            return true;
        }

        return $this->truthy_option(constant('WP_FTS_FAST_MODE_ENABLED'));
    }

    /**
     * Candidate threshold above which broad searches switch to approximate mode.
     */
    private function auto_fast_mode_threshold(): int
    {
        if (defined('WP_FTS_FAST_MODE_THRESHOLD')) {
            $threshold = $this->non_negative_int_option(constant('WP_FTS_FAST_MODE_THRESHOLD'));
            if ($threshold !== null) {
                return $threshold;
            }
        }

        return self::DEFAULT_AUTO_FAST_MODE_THRESHOLD;
    }

    /**
     * Default cap used when fast mode is active and the caller did not supply one.
     */
    private function default_fast_candidate_cap(): int
    {
        if (defined('WP_FTS_FAST_MODE_CANDIDATE_CAP')) {
            $cap = $this->positive_int_option(constant('WP_FTS_FAST_MODE_CANDIDATE_CAP'));
            if ($cap !== null) {
                return $cap;
            }
        }

        return self::DEFAULT_FAST_MODE_CANDIDATE_CAP;
    }

    /**
     * Estimate candidates for the analyzed query, capped at threshold + 1.
     *
     * The probe uses deterministic capped postings when available. Metadata
     * filters are applied to the probed active candidate ids, so filtered searches
     * stay exact whenever the filtered probe does not cross the threshold.
     *
     * @param array<int,array<int,array{key:string,lang:string,term:string,rank:int}>> $groups
     * @param array{post_types:string[],post_statuses:string[],date_after:?string,date_before:?string}|null $metadataFilter
     */
    private function estimate_candidate_count(array $groups, string $mode, ?array $metadataFilter, int $threshold): int
    {
        $termsByKey = $this->terms_by_key($groups);
        if ($termsByKey === []) {
            return 0;
        }

        $probeLimit = $threshold >= PHP_INT_MAX ? PHP_INT_MAX : $threshold + 1;
        $postingsByTerm = WP_FTS_StorageCompat::get_postings($this->storage, array_keys($termsByKey), $probeLimit);
        if ($postingsByTerm === []) {
            return 0;
        }

        return $this->count_active_candidates_from_postings(
            $groups,
            $termsByKey,
            $postingsByTerm,
            $mode,
            $metadataFilter,
            $probeLimit
        );
    }

    /**
     * Count active query candidates from already-probed postings.
     *
     * @param array<int,array<int,array{key:string,lang:string,term:string,rank:int}>> $groups
     * @param array<string,array{lang:string,groups:array<int,int>}> $termsByKey
     * @param array<string,array<int,int>> $postingsByTerm
     * @param array{post_types:string[],post_statuses:string[],date_after:?string,date_before:?string}|null $metadataFilter
     */
    private function count_active_candidates_from_postings(
        array $groups,
        array $termsByKey,
        array $postingsByTerm,
        string $mode,
        ?array $metadataFilter,
        int $limit
    ): int {
        $activeDocIdsByLang = $this->active_probe_doc_ids_by_lang($postingsByTerm, $termsByKey);
        if ($activeDocIdsByLang === []) {
            return 0;
        }

        /** @var array<int,bool> $candidateDocIds */
        $candidateDocIds = [];
        /** @var array<int,array<int,bool>> $groupDocIds */
        $groupDocIds = [];
        foreach ($postingsByTerm as $term => $postings) {
            if (!isset($termsByKey[$term])) {
                continue;
            }

            $lang = $termsByKey[$term]['lang'];
            foreach ($postings as $docId => $_tf) {
                $docId = (int) $docId;
                if (!isset($activeDocIdsByLang[$lang][$docId])) {
                    continue;
                }

                if ($mode === 'AND') {
                    foreach ($termsByKey[$term]['groups'] as $groupId => $_rank) {
                        $groupDocIds[$groupId][$docId] = true;
                    }
                    continue;
                }

                $candidateDocIds[$docId] = true;
                if ($metadataFilter === null && count($candidateDocIds) >= $limit) {
                    return $limit;
                }
            }
        }

        if ($mode === 'AND') {
            $candidateDocIds = $this->intersect_group_doc_ids($groupDocIds, count($groups));
            if ($metadataFilter === null && count($candidateDocIds) >= $limit) {
                return $limit;
            }
        }

        if ($candidateDocIds === []) {
            return 0;
        }

        if ($metadataFilter !== null) {
            $matchingDocIds = WP_FTS_StorageCompat::filter_doc_ids_by_metadata(
                $this->storage,
                array_keys($candidateDocIds),
                $metadataFilter['post_types'],
                $metadataFilter['post_statuses'],
                $metadataFilter['date_after'],
                $metadataFilter['date_before']
            );
            $candidateDocIds = array_fill_keys($matchingDocIds, true);
        }

        return min(count($candidateDocIds), $limit);
    }

    /**
     * Resolve active candidate ids by language partition for probed postings.
     *
     * @param array<string,array<int,int>> $postingsByTerm
     * @param array<string,array{lang:string,groups:array<int,int>}> $termsByKey
     * @return array<string,array<int,bool>>
     */
    private function active_probe_doc_ids_by_lang(array $postingsByTerm, array $termsByKey): array
    {
        /** @var array<string,array<int,bool>> $probeDocIdsByLang */
        $probeDocIdsByLang = [];
        foreach ($postingsByTerm as $term => $postings) {
            $lang = $termsByKey[$term]['lang'] ?? null;
            if ($lang === null) {
                continue;
            }

            foreach (array_keys($postings) as $docId) {
                $probeDocIdsByLang[$lang][(int) $docId] = true;
            }
        }

        $activeDocIdsByLang = [];
        foreach ($probeDocIdsByLang as $lang => $docIds) {
            $lengths = WP_FTS_StorageCompat::get_doc_lengths($this->storage, array_keys($docIds), $lang);
            foreach (array_keys($lengths) as $docId) {
                $activeDocIdsByLang[$lang][(int) $docId] = true;
            }
        }

        return $activeDocIdsByLang;
    }

    /**
     * Return a positive integer option or null for unset/invalid values.
     */
    private function positive_int_option(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value > 0 ? $value : null;
        }

        if (is_string($value) && preg_match('/^[1-9][0-9]*$/', trim($value)) === 1) {
            return (int) trim($value);
        }

        if (is_float($value) && $value >= 1.0) {
            return (int) $value;
        }

        return null;
    }

    /**
     * Return a non-negative integer option or null for unset/invalid values.
     */
    private function non_negative_int_option(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value >= 0 ? $value : null;
        }

        if (is_string($value) && preg_match('/^(0|[1-9][0-9]*)$/', trim($value)) === 1) {
            return (int) trim($value);
        }

        if (is_float($value) && $value >= 0.0) {
            return (int) $value;
        }

        return null;
    }

    /**
     * Compare rows by public search ordering: exact/fallback rank, score, doc id.
     *
     * @param array{doc_id:int,score:float,_rank?:int} $a
     * @param array{doc_id:int,score:float,_rank?:int} $b
     */
    private static function compare_ranked_results(array $a, array $b): int
    {
        $rankOrder = ($a['_rank'] ?? 0) <=> ($b['_rank'] ?? 0);
        if ($rankOrder !== 0) {
            return $rankOrder;
        }

        $scoreOrder = $b['score'] <=> $a['score'];

        return $scoreOrder !== 0 ? $scoreOrder : ($a['doc_id'] <=> $b['doc_id']);
    }

    /**
     * Keep exact query candidates strongest while allowing secondary pack
     * candidates to contribute recall.
     *
     * @param array<int,int> $groupRanks
     */
    private function query_rank_score_multiplier(array $groupRanks): float
    {
        if ($groupRanks === []) {
            return 1.0;
        }

        $rank = min(array_map('intval', $groupRanks));

        return 1.0 / (1.0 + max(0, $rank));
    }

    /**
     * Maintain a sorted bounded result page without materializing all scored rows.
     *
     * @param array<int,array{doc_id:int,score:float,_rank:int}> $topResults
     * @param array{doc_id:int,score:float,_rank:int} $row
     */
    private function insert_bounded_top_k_result(array &$topResults, array $row, int $limit): void
    {
        $limit = max(1, $limit);
        $count = count($topResults);
        if ($count >= $limit && self::compare_ranked_results($row, $topResults[$count - 1]) >= 0) {
            return;
        }

        $insertAt = $count;
        while ($insertAt > 0 && self::compare_ranked_results($row, $topResults[$insertAt - 1]) < 0) {
            $insertAt--;
        }

        array_splice($topResults, $insertAt, 0, [$row]);
        if (count($topResults) > $limit) {
            array_pop($topResults);
        }
    }

    /**
     * Build logical query term groups from explicit languages or analyzer output.
     *
     * The legacy path analyzes once and therefore preserves existing callers'
     * single-language behavior. `langs` and `languages` intentionally analyze
     * the same query under each requested language, adding those language terms
     * as alternatives for each logical query term.
     *
     * @return array<int,array<int,array{key:string,lang:string,term:string,rank:int}>>
     */
    private function build_query_groups(string $query, array $opts): array
    {
        $requestedLangs = $this->languages_from_options($opts, ['langs', 'languages']);
        if ($requestedLangs !== []) {
            $groups = [];
            foreach ($requestedLangs as $lang) {
                $this->merge_language_groups($groups, $query, $opts, $lang, 0);
            }

            foreach ($this->fallback_languages($opts, $requestedLangs) as $fallbackLang) {
                $this->merge_language_groups($groups, $query, $opts, $fallbackLang, 1);
            }

            return $this->dedupe_query_groups($groups);
        }

        $explicitQueryLang = WP_FTS_TermNamespace::language_from_options($opts, null, ['query_lang', 'lang', 'language']);
        $queryOccurrences = $explicitQueryLang === null
            ? $this->analyze_query($query, $opts)
            : $this->analyze_query($query, $this->with_query_language($opts, $explicitQueryLang));
        $queryLang = $explicitQueryLang ?? $this->resolve_query_language($opts, $queryOccurrences);
        $groups = $this->groups_from_occurrences($queryOccurrences, $queryLang, 0, $explicitQueryLang);
        $exactLangs = $explicitQueryLang === null ? [] : [$queryLang];

        foreach ($this->fallback_languages($opts, $exactLangs) as $fallbackLang) {
            $this->merge_fallback_language_groups($groups, $query, $opts, $fallbackLang);
        }

        return $this->dedupe_query_groups($groups);
    }

    /**
     * Analyze a query under one language and merge it by term position.
     *
     * @param array<int,array<int,array{key:string,lang:string,term:string,rank:int}>> $groups
     */
    private function merge_language_groups(array &$groups, string $query, array $opts, string $lang, int $rank): void
    {
        $occurrences = $this->analyze_query($query, $this->with_query_language($opts, $lang));
        $newGroups = $this->groups_from_occurrences($occurrences, $lang, $rank, $lang);

        foreach ($newGroups as $index => $newGroup) {
            if (isset($groups[$index])) {
                array_push($groups[$index], ...$newGroup);
                continue;
            }

            $groups[$index] = $newGroup;
        }
    }

    /**
     * Add fallback-language alternatives without query-wide exact-language suppression.
     *
     * Inline tags and resolver-selected languages can mix partitions in one
     * query. A fallback candidate is therefore skipped only when the same
     * logical group already has the exact candidate key.
     *
     * @param array<int,array<int,array{key:string,lang:string,term:string,rank:int}>> $groups
     */
    private function merge_fallback_language_groups(array &$groups, string $query, array $opts, string $lang): void
    {
        $occurrences = $this->analyze_query($query, $this->with_query_language($opts, $lang));
        $fallbackGroups = $this->groups_from_occurrences($occurrences, $lang, 1, $lang);

        foreach ($fallbackGroups as $index => $fallbackGroup) {
            if (!isset($groups[$index])) {
                $groups[$index] = $fallbackGroup;
                continue;
            }

            $exactKeys = [];
            foreach ($groups[$index] as $candidate) {
                if ($candidate['rank'] === 0) {
                    $exactKeys[$candidate['key']] = true;
                }
            }

            foreach ($fallbackGroup as $candidate) {
                if (!isset($exactKeys[$candidate['key']])) {
                    $groups[$index][] = $candidate;
                }
            }
        }
    }

    /**
     * Force analyzer options to a specific query language.
     *
     * @return array<string,mixed>
     */
    private function with_query_language(array $opts, string $lang): array
    {
        $opts['query_lang'] = $lang;
        $opts['lang'] = $lang;
        $opts['language'] = $lang;
        $opts['_force_query_lang'] = true;

        return $opts;
    }

    /**
     * Convert analyzer occurrences into one-language alternatives.
     *
     * @param array<int,array<string,mixed>|string> $occurrences
     * @param string|null $authoritativeLang Language partition that overrides
     *        inline or analyzer-selected languages for explicit/fallback passes.
     * @return array<int,array<int,array{key:string,lang:string,term:string,rank:int}>>
     */
    private function groups_from_occurrences(array $occurrences, string $defaultLang, int $rank, ?string $authoritativeLang = null): array
    {
        $groups = [];
        $currentPosition = null;
        $currentGroupIndex = null;
        foreach ($occurrences as $occurrence) {
            $candidate = $this->candidate_from_occurrence($occurrence, $defaultLang, $rank, $authoritativeLang);
            if ($candidate !== null) {
                $position = $this->occurrence_position($occurrence);
                if ($position !== null && $currentGroupIndex !== null && $currentPosition === $position) {
                    $groups[$currentGroupIndex][] = $candidate;
                    continue;
                }

                $groups[] = [$candidate];
                if ($position === null) {
                    $currentPosition = null;
                    $currentGroupIndex = null;
                } else {
                    $currentPosition = $position;
                    $currentGroupIndex = count($groups) - 1;
                }
            }
        }

        return $groups;
    }

    /**
     * Return the analyzer token-position marker when present.
     */
    private function occurrence_position(array|string $occurrence): ?string
    {
        if (!is_array($occurrence) || !isset($occurrence['position']) || !is_scalar($occurrence['position'])) {
            return null;
        }

        return (string) $occurrence['position'];
    }

    /**
     * Normalize one analyzer occurrence into a stored term-key candidate.
     *
     * @param array<string,mixed>|string $occurrence
     * @param string|null $authoritativeLang Language partition that must own the
     *        candidate, regardless of inline tags or namespaced legacy terms.
     * @return array{key:string,lang:string,term:string,rank:int}|null
     */
    private function candidate_from_occurrence(array|string $occurrence, string $defaultLang, int $rank, ?string $authoritativeLang = null): ?array
    {
        $term = is_array($occurrence)
            ? trim((string) ($occurrence['term'] ?? ''))
            : trim((string) $occurrence);
        if ($term === '') {
            return null;
        }

        $defaultLang = WP_FTS_TermNamespace::canonicalize_lang($defaultLang);
        $authoritativeLang = $authoritativeLang === null
            ? null
            : WP_FTS_TermNamespace::canonicalize_lang($authoritativeLang, $defaultLang);
        $split = WP_FTS_TermNamespace::split_term($term);
        if ($split !== null) {
            $lang = $authoritativeLang ?? $split['lang'];
            $term = $split['term'];
        } else {
            $lang = $authoritativeLang ?? (is_array($occurrence) && isset($occurrence['lang'])
                ? WP_FTS_TermNamespace::canonicalize_lang((string) $occurrence['lang'], $defaultLang)
                : $defaultLang);
        }

        if ($term === '') {
            return null;
        }

        if (!WP_FTS_TermNamespace::term_key_fits($term, $lang)) {
            return null;
        }

        $occurrenceRank = is_array($occurrence) && isset($occurrence['rank']) && is_numeric($occurrence['rank'])
            ? max(0, (int) $occurrence['rank'])
            : 0;

        return [
            'key' => WP_FTS_TermNamespace::namespace_term($lang, $term),
            'lang' => $lang,
            'term' => $term,
            'rank' => $rank + $occurrenceRank,
        ];
    }

    /**
     * Parse a list of exact query languages from public search options.
     *
     * @param string[] $keys
     * @return string[]
     */
    private function languages_from_options(array $opts, array $keys): array
    {
        $languages = [];
        foreach ($keys as $key) {
            if (!array_key_exists($key, $opts)) {
                continue;
            }

            foreach ($this->languages_from_value($opts[$key]) as $lang) {
                $languages[$lang] = true;
            }
        }

        return array_keys($languages);
    }

    /**
     * Normalize one scalar or array language-list value.
     *
     * @return string[]
     */
    private function languages_from_value(mixed $value): array
    {
        if ($value === false || $value === null) {
            return [];
        }

        $items = is_array($value) ? $value : [$value];
        $languages = [];
        foreach ($items as $item) {
            if (!is_scalar($item)) {
                continue;
            }

            foreach (preg_split('/[\s,|]+/', trim((string) $item)) ?: [] as $part) {
                if ($part !== '') {
                    $languages[] = WP_FTS_TermNamespace::canonicalize_lang($part);
                }
            }
        }

        return array_values(array_unique($languages));
    }

    /**
     * Resolve opt-in fallback languages, excluding exact languages.
     *
     * @param string[] $exactLangs
     * @return string[]
     */
    private function fallback_languages(array $opts, array $exactLangs): array
    {
        if ($this->truthy_option($opts['disable_language_fallback'] ?? false)) {
            return [];
        }

        $fallbacks = $this->languages_from_options($opts, ['fallback_lang', 'fallback_language', 'fallback_languages']);
        if ($fallbacks === [] && $this->truthy_option($opts['language_fallback'] ?? $opts['fallback_to_default_lang'] ?? false)) {
            $fallbacks = [WP_FTS_TermNamespace::default_language($opts)];
        }

        $exact = array_fill_keys(array_map(
            static fn(string $lang): string => WP_FTS_TermNamespace::canonicalize_lang($lang),
            $exactLangs
        ), true);

        $result = [];
        foreach ($fallbacks as $lang) {
            $lang = WP_FTS_TermNamespace::canonicalize_lang($lang);
            if (!isset($exact[$lang])) {
                $result[$lang] = true;
            }
        }

        return array_keys($result);
    }

    /**
     * Interpret public boolean-ish options without treating arbitrary strings as false.
     */
    private function truthy_option(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value) || is_float($value)) {
            return $value !== 0;
        }

        if (is_string($value)) {
            return !in_array(strtolower(trim($value)), ['', '0', 'false', 'no', 'off'], true);
        }

        return false;
    }

    /**
     * Dedupe alternatives inside groups and duplicate logical groups.
     *
     * @param array<int,array<int,array{key:string,lang:string,term:string,rank:int}>> $groups
     * @return array<int,array<int,array{key:string,lang:string,term:string,rank:int}>>
     */
    private function dedupe_query_groups(array $groups): array
    {
        $deduped = [];
        $seenGroups = [];
        foreach ($groups as $group) {
            $byKey = [];
            foreach ($group as $candidate) {
                $key = $candidate['key'];
                if (!isset($byKey[$key]) || $candidate['rank'] < $byKey[$key]['rank']) {
                    $byKey[$key] = $candidate;
                }
            }

            if ($byKey === []) {
                continue;
            }

            ksort($byKey, SORT_STRING);
            $signature = implode("\0", array_keys($byKey));
            if (isset($seenGroups[$signature])) {
                continue;
            }

            $seenGroups[$signature] = true;
            $deduped[] = array_values($byKey);
        }

        return $deduped;
    }

    /**
     * Pick a representative language for include_total response metadata.
     *
     * @param array<int,array<int,array{key:string,lang:string,term:string,rank:int}>> $groups
     * @return string Canonical language tag.
     */
    private function response_query_language(array $opts, array $groups): string
    {
        $explicitLang = WP_FTS_TermNamespace::language_from_options($opts, null, ['query_lang', 'lang', 'language']);
        if ($explicitLang !== null) {
            return $explicitLang;
        }

        foreach ($groups as $group) {
            foreach ($group as $candidate) {
                if ($candidate['rank'] === 0) {
                    return $candidate['lang'];
                }
            }
        }

        return WP_FTS_TermNamespace::default_language($opts);
    }

    /**
     * Remove exact/fallback ranking internals from public result rows.
     *
     * @param array<int,array<string,mixed>> $results
     */
    private function strip_internal_rank(array &$results): void
    {
        foreach ($results as &$result) {
            unset($result['_rank']);
        }
        unset($result);
    }

    /**
     * Call a real prefix/phrase extension when requested.
     *
     * The built-in posting lists are whole-term only. Returning an empty or fuzzy
     * approximation for prefix/phrase would be misleading, so these modes require
     * an explicit extension callback that owns the storage contract.
     *
     * @return array<string,mixed>|array<int,array<string,mixed>>|null
     */
    private function extension_results(string $query, array $opts): ?array
    {
        $requested = !empty($opts['prefix']) || !empty($opts['phrase']);
        if (!$requested) {
            return null;
        }

        if (isset($opts['search_extension']) && is_callable($opts['search_extension'])) {
            $results = ($opts['search_extension'])($query, $opts, $this->storage, $this->analyzer);
            return is_array($results) ? $results : [];
        }

        throw new InvalidArgumentException('Prefix and phrase search require a search_extension callback for the active storage backend.');
    }

    /**
     * Preserve legacy list results unless callers request pagination metadata.
     *
     * @param array<int,array<string,mixed>> $results
     * @return array<int,array<string,mixed>>|array{total:int,limit:int,offset:int,query_lang:string,results:array<int,array<string,mixed>>}
     */
    private function format_response(array $results, int $total, array $opts, string $queryLang): array
    {
        if (empty($opts['include_total'])) {
            return $results;
        }

        return [
            'total' => $total,
            'limit' => max(1, (int) ($opts['limit'] ?? 10)),
            'offset' => max(0, (int) ($opts['offset'] ?? 0)),
            'query_lang' => WP_FTS_TermNamespace::canonicalize_lang($queryLang),
            'results' => $results,
        ];
    }

    /**
     * Check whether result rows need metadata/snippet enrichment.
     */
    private function should_enrich_results(array $opts): bool
    {
        return !empty($opts['include_metadata']) || !empty($opts['include_snippets']) || !empty($opts['snippets']);
    }

    /**
     * Add stored metadata and optional snippets to result rows.
     *
     * @param array<int,array<string,mixed>> $results
     * @param array<int,array<string,mixed>> $metadata
     * @param array<int,array<int,array{key:string,lang:string,term:string,rank:int}>> $queryGroups
     * @return array<int,array<string,mixed>>
     */
    private function enrich_results(array $results, array $metadata, string $query, array $opts, array $queryGroups, string $queryLang): array
    {
        $includeMetadata = !empty($opts['include_metadata']);
        $includeSnippets = !empty($opts['include_snippets']) || !empty($opts['snippets']);
        foreach ($results as &$row) {
            $docId = (int) $row['doc_id'];
            $meta = $metadata[$docId] ?? [];
            $doc = $includeSnippets ? $this->storage->get_doc($docId) : null;
            if ($includeMetadata) {
                foreach (['post_id', 'post_type', 'post_status', 'post_date_gmt', 'title', 'excerpt'] as $key) {
                    $row[$key] = $meta[$key] ?? ($key === 'post_id' ? 0 : '');
                }
            }
            if ($includeSnippets) {
                $resultLang = $this->snippet_result_language($row, $meta, $doc, $opts, $queryLang);
                $snippetLength = max(40, (int) ($opts['snippet_length'] ?? 180));
                $highlight = !empty($opts['highlight']);
                $searchHtml = (string) ($meta['search_html'] ?? '');
                if ($highlight && $searchHtml !== '' && (str_contains($searchHtml, '<') || str_contains($searchHtml, '&'))) {
                    $htmlSnippet = $this->html_snippet(
                        $searchHtml,
                        $query,
                        $snippetLength,
                        $opts,
                        $queryGroups,
                        $queryLang,
                        $resultLang
                    );
                    if ($htmlSnippet !== null) {
                        $row['snippet'] = $htmlSnippet;
                        continue;
                    }
                }

                $snippetSource = (string) ($meta['search_text'] ?? $meta['excerpt'] ?? $meta['title'] ?? '');
                if ($snippetSource === '') {
                    $snippetSource = $searchHtml;
                }

                $row['snippet'] = $this->snippet(
                    $snippetSource,
                    $query,
                    $snippetLength,
                    $highlight,
                    $opts,
                    $queryGroups,
                    $queryLang,
                    $resultLang
                );
            }
        }
        unset($row);

        return $results;
    }

    /**
     * Determine whether metadata filters are present.
     */
    private function has_metadata_filters(array $opts): bool
    {
        $filter = $this->metadata_filter_values($opts);

        return $filter['post_types'] !== []
            || $filter['post_statuses'] !== []
            || $filter['date_after'] !== null
            || $filter['date_before'] !== null;
    }

    /**
     * Normalize public metadata filter options once per search path.
     *
     * @return array{post_types:string[],post_statuses:string[],date_after:?string,date_before:?string}
     */
    private function metadata_filter_values(array $opts): array
    {
        return [
            'post_types' => $this->normalize_filter_list($opts['post_type'] ?? $opts['post_types'] ?? []),
            'post_statuses' => $this->normalize_filter_list($opts['post_status'] ?? $opts['post_statuses'] ?? []),
            'date_after' => $this->date_filter($opts['date_after'] ?? $opts['after'] ?? $opts['post_date_after'] ?? null, false),
            'date_before' => $this->date_filter($opts['date_before'] ?? $opts['before'] ?? $opts['post_date_before'] ?? null, true),
        ];
    }

    /**
     * Normalize comma-separated or array filters.
     *
     * @return string[]
     */
    private function normalize_filter_list(mixed $value): array
    {
        return WP_FTS_StorageCompat::normalize_metadata_filter_values($value);
    }

    /**
     * Normalize date-only filters to lexicographic SQL datetime boundaries.
     */
    private function date_filter(mixed $value, bool $endOfDay): ?string
    {
        return WP_FTS_StorageCompat::normalize_metadata_filter_date($value, $endOfDay);
    }

    /**
     * Build a highlighted snippet from a caller-supplied source using the same
     * analyzer/query plan as normal search result enrichment.
     *
     * This is intended for WordPress render surfaces that need a field-specific
     * preview, for example highlighting only the actual post content or only the
     * title instead of the aggregate indexed metadata.
     *
     * @param array<string,mixed> $opts
     */
    public function snippet_for_text(string $text, string $query, array $opts = []): string
    {
        $query = trim($query);
        if ($query === '' || trim($text) === '') {
            return '';
        }

        $groups = $this->build_query_groups($query, $opts);
        $queryLang = $this->response_query_language($opts, $groups);
        $resultLang = WP_FTS_TermNamespace::language_from_options($opts, null, ['result_lang', 'document_lang', 'lang', 'language'])
            ?? $queryLang;

        return $this->snippet(
            $text,
            $query,
            max(40, (int) ($opts['snippet_length'] ?? 180)),
            !empty($opts['highlight']),
            $opts,
            $groups,
            $queryLang,
            $resultLang
        );
    }

    /**
     * Build a compact snippet from stored plain text.
     */
    private function snippet(string $text, string $query, int $length, bool $highlight, array $opts = [], array $queryGroups = [], string $queryLang = '', string $resultLang = ''): string
    {
        if ($highlight && str_contains($text, '<')) {
            $htmlSnippet = $this->html_snippet($text, $query, $length, $opts, $queryGroups, $queryLang, $resultLang);
            if ($htmlSnippet !== null) {
                return $htmlSnippet;
            }
        }

        $text = WP_FTS_Html_Text_Stream::visible_text($text);
        if ($text === '') {
            return '';
        }

        $terms = $this->snippet_terms($query);
        $queryKeys = $highlight ? $this->snippet_query_keys($queryGroups) : [];
        $analysisCache = [];
        $start = 0;
        $literalPositionFound = false;
        foreach ($terms as $term) {
            $position = stripos($text, $term);
            if ($position !== false) {
                $start = max(0, $position - intdiv($length, 3));
                $literalPositionFound = true;
                break;
            }
        }
        if (!$literalPositionFound && $highlight && $queryKeys !== []) {
            $position = $this->first_analyzed_snippet_match_position(
                $text,
                $queryKeys,
                $opts,
                $queryGroups,
                $queryLang,
                $resultLang,
                $analysisCache
            );
            if ($position !== null) {
                $start = max(0, $position - intdiv($length, 3));
            }
        }

        $snippet = trim(substr($text, $start, $length));
        if ($start > 0) {
            $snippet = '...' . ltrim($snippet);
        }
        if ($start + $length < strlen($text)) {
            $snippet = rtrim($snippet) . '...';
        }

        if (!$highlight || $terms === []) {
            return $snippet;
        }

        return $this->highlight_snippet_terms(
            $snippet,
            array_fill_keys($terms, true),
            $queryKeys,
            $opts,
            $queryGroups,
            $queryLang,
            $resultLang,
            $analysisCache
        );
    }

    /**
     * Highlight analyzed visible words in an HTML snippet source.
     *
     * The source is scanned as HTML tokens. Matching is done on decoded visible
     * words, while the returned snippet is the original HTML with <mark> tags
     * inserted at source offsets expanded over inline wrappers.
     *
     * @param array<int,array<int,array{key:string,lang:string,term:string,rank:int}>> $queryGroups
     */
    private function html_snippet(string $html, string $query, int $length, array $opts, array $queryGroups, string $queryLang, string $resultLang): ?string
    {
        $words = WP_FTS_Html_Text_Stream::visible_words($html);
        if ($words === []) {
            return null;
        }

        $literalTerms = array_fill_keys($this->snippet_terms($query), true);
        $queryKeys = $this->snippet_query_keys($queryGroups);
        if ($literalTerms === [] && $queryKeys === []) {
            return null;
        }

        $analysisCache = [];
        $matches = [];
        foreach ($words as $word) {
            $surface = (string) $word['text'];
            $matchesLiteral = isset($literalTerms[strtolower($surface)]);
            $matchesAnalyzer = $queryKeys !== []
                && $this->snippet_token_matches_query($surface, $queryKeys, $opts, $queryGroups, $queryLang, $resultLang, $analysisCache);
            if (!$matchesLiteral && !$matchesAnalyzer) {
                continue;
            }

            $range = WP_FTS_Html_Text_Stream::expand_inline_range(
                $html,
                (int) $word['source_start'],
                (int) $word['source_end']
            );
            $matches[] = [
                'range' => ['start' => $range['start'], 'end' => $range['end']],
                'visible_start' => (int) $word['visible_start'],
                'visible_end' => (int) $word['visible_end'],
            ];
        }

        if ($matches === []) {
            return null;
        }

        $selected = $matches[0];
        $length = max(1, $length);
        $windowStart = max(0, $selected['visible_start'] - intdiv($length, 3));
        $windowEnd = max($selected['visible_end'], $windowStart + $length);
        $window = WP_FTS_Html_Text_Stream::visible_source_window($html, $windowStart, $windowEnd);
        if ($window === null) {
            return null;
        }

        $sourceStart = min((int) $window['source_start'], (int) $selected['range']['start']);
        $sourceEnd = max((int) $window['source_end'], (int) $selected['range']['end']);
        foreach ($matches as $match) {
            if ($match['visible_end'] <= $window['visible_start'] || $match['visible_start'] >= $window['visible_end']) {
                continue;
            }

            $sourceStart = min($sourceStart, (int) $match['range']['start']);
            $sourceEnd = max($sourceEnd, (int) $match['range']['end']);
        }
        $sourceStart = max(0, min(strlen($html), $sourceStart));
        $sourceEnd = max($sourceStart, min(strlen($html), $sourceEnd));
        if ($sourceEnd <= $sourceStart) {
            return null;
        }
        $sourceRange = WP_FTS_Html_Text_Stream::expand_inline_range($html, $sourceStart, $sourceEnd);
        $sourceStart = $sourceRange['start'];
        $sourceEnd = $sourceRange['end'];

        $fragment = substr($html, $sourceStart, $sourceEnd - $sourceStart);
        if (strlen($fragment) > $this->max_html_snippet_source_bytes($length)) {
            return null;
        }

        $markRanges = [];
        foreach ($matches as $match) {
            $range = $match['range'];
            if ($range['start'] < $sourceStart || $range['end'] > $sourceEnd) {
                continue;
            }

            $markRanges[] = [
                'start' => $range['start'] - $sourceStart,
                'end' => $range['end'] - $sourceStart,
            ];
        }

        if ($markRanges === []) {
            return null;
        }

        $fragment = WP_FTS_Html_Text_Stream::mark_ranges($fragment, $this->merge_snippet_mark_ranges($markRanges));
        if ($window['visible_start'] > 0) {
            $fragment = '...' . ltrim($fragment);
        }
        if ($window['visible_end'] < $window['total_visible']) {
            $fragment = rtrim($fragment) . '...';
        }

        return $fragment;
    }

    private function max_html_snippet_source_bytes(int $length): int
    {
        $length = max(1, $length);

        return max($length + 96, $length * 3);
    }

    /**
     * @param array<int,array{start:int,end:int}> $ranges
     * @return array<int,array{start:int,end:int}>
     */
    private function merge_snippet_mark_ranges(array $ranges): array
    {
        usort($ranges, static fn(array $a, array $b): int => $a['start'] <=> $b['start']);

        $merged = [];
        foreach ($ranges as $range) {
            if ($range['end'] <= $range['start']) {
                continue;
            }

            $lastIndex = count($merged) - 1;
            if ($lastIndex >= 0 && $range['start'] <= $merged[$lastIndex]['end']) {
                $merged[$lastIndex]['end'] = max($merged[$lastIndex]['end'], $range['end']);
                continue;
            }

            $merged[] = $range;
        }

        return $merged;
    }

    /**
     * Resolve the best language hint for analyzing plain snippet tokens.
     *
     * @param array<string,mixed> $row
     * @param array<string,mixed> $meta
     * @param array<string,mixed>|null $doc
     */
    private function snippet_result_language(array $row, array $meta, ?array $doc, array $opts, string $queryLang): string
    {
        foreach ([
            $row['language'] ?? null,
            $row['lang'] ?? null,
            $row['primary_lang'] ?? null,
            $meta['language'] ?? null,
            $meta['lang'] ?? null,
            $meta['primary_lang'] ?? null,
            $doc['primary_lang'] ?? null,
            $doc['lang'] ?? null,
            $doc['language'] ?? null,
            WP_FTS_TermNamespace::language_from_options($opts, null, ['query_lang', 'lang', 'language']),
            $queryLang,
        ] as $candidate) {
            if (is_scalar($candidate) && trim((string) $candidate) !== '') {
                return WP_FTS_TermNamespace::canonicalize_lang((string) $candidate);
            }
        }

        return WP_FTS_TermNamespace::default_language($opts);
    }

    /**
     * Flatten analyzed query alternatives into the stored term-key lookup.
     *
     * @param array<int,array<int,array{key:string,lang:string,term:string,rank:int}>> $queryGroups
     * @return array<string,bool>
     */
    private function snippet_query_keys(array $queryGroups): array
    {
        $keys = [];
        foreach ($queryGroups as $group) {
            foreach ($group as $candidate) {
                if (($candidate['key'] ?? '') !== '') {
                    $keys[$candidate['key']] = true;
                }
            }
        }

        return $keys;
    }

    /**
     * Find the first snippet token whose analyzed key matches the query plan.
     *
     * @param array<string,bool> $queryKeys
     * @param array<int,array<int,array{key:string,lang:string,term:string,rank:int}>> $queryGroups
     * @param array<string,array<string,bool>> $analysisCache
     */
    private function first_analyzed_snippet_match_position(string $text, array $queryKeys, array $opts, array $queryGroups, string $queryLang, string $resultLang, array &$analysisCache): ?int
    {
        $matched = preg_match_all('/[\p{L}\p{N}_-]+/u', $text, $matches, PREG_OFFSET_CAPTURE);
        if ($matched === false || $matched === 0) {
            return null;
        }

        foreach ($matches[0] as $match) {
            $token = (string) $match[0];
            if ($this->snippet_token_matches_query($token, $queryKeys, $opts, $queryGroups, $queryLang, $resultLang, $analysisCache)) {
                return (int) $match[1];
            }
        }

        return null;
    }

    /**
     * Wrap matching surface tokens without reprocessing already inserted tags.
     *
     * @param array<string,bool> $literalTerms
     * @param array<string,bool> $queryKeys
     * @param array<int,array<int,array{key:string,lang:string,term:string,rank:int}>> $queryGroups
     * @param array<string,array<string,bool>> $analysisCache
     */
    private function highlight_snippet_terms(string $snippet, array $literalTerms, array $queryKeys, array $opts, array $queryGroups, string $queryLang, string $resultLang, array &$analysisCache): string
    {
        $matched = preg_match_all('/[\p{L}\p{N}_-]+/u', $snippet, $matches, PREG_OFFSET_CAPTURE);
        if ($matched === false || $matched === 0) {
            return $snippet;
        }

        $highlighted = '';
        $cursor = 0;
        foreach ($matches[0] as $match) {
            $token = (string) $match[0];
            $offset = (int) $match[1];
            $length = strlen($token);
            $matchesLiteral = isset($literalTerms[strtolower($token)]);
            $matchesAnalyzer = $queryKeys !== []
                && $this->snippet_token_matches_query($token, $queryKeys, $opts, $queryGroups, $queryLang, $resultLang, $analysisCache);

            $highlighted .= substr($snippet, $cursor, $offset - $cursor);
            $highlighted .= ($matchesLiteral || $matchesAnalyzer) ? '<mark>' . $token . '</mark>' : $token;
            $cursor = $offset + $length;
        }

        return $highlighted . substr($snippet, $cursor);
    }

    /**
     * Compare one original snippet token against analyzed query term keys.
     *
     * @param array<string,bool> $queryKeys
     * @param array<int,array<int,array{key:string,lang:string,term:string,rank:int}>> $queryGroups
     * @param array<string,array<string,bool>> $analysisCache
     */
    private function snippet_token_matches_query(string $token, array $queryKeys, array $opts, array $queryGroups, string $queryLang, string $resultLang, array &$analysisCache): bool
    {
        foreach ($this->snippet_analysis_languages($opts, $queryGroups, $queryLang, $resultLang) as $lang) {
            foreach ($this->snippet_token_keys($token, $lang, $opts, $analysisCache) as $key => $_) {
                if (isset($queryKeys[$key])) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Return explicit/result languages only; do not guess every supported language.
     *
     * @param array<int,array<int,array{key:string,lang:string,term:string,rank:int}>> $queryGroups
     * @return string[]
     */
    private function snippet_analysis_languages(array $opts, array $queryGroups, string $queryLang, string $resultLang): array
    {
        $languages = [];
        foreach ([$resultLang, WP_FTS_TermNamespace::language_from_options($opts, null, ['query_lang', 'lang', 'language']), $queryLang] as $candidate) {
            if (is_scalar($candidate) && trim((string) $candidate) !== '') {
                $languages[WP_FTS_TermNamespace::canonicalize_lang((string) $candidate)] = true;
            }
        }

        foreach ($this->languages_from_options($opts, ['langs', 'languages']) as $lang) {
            $languages[$lang] = true;
        }

        foreach ($queryGroups as $group) {
            foreach ($group as $candidate) {
                if (($candidate['lang'] ?? '') !== '') {
                    $languages[WP_FTS_TermNamespace::canonicalize_lang($candidate['lang'])] = true;
                }
            }
        }

        return array_keys($languages);
    }

    /**
     * Analyze one plain snippet token under an explicit language and return term keys.
     *
     * @param array<string,array<string,bool>> $analysisCache
     * @return array<string,bool>
     */
    private function snippet_token_keys(string $token, string $lang, array $opts, array &$analysisCache): array
    {
        $cacheKey = $lang . "\0" . strtolower($token);
        if (isset($analysisCache[$cacheKey])) {
            return $analysisCache[$cacheKey];
        }

        $keys = [];
        $occurrences = $this->analyze_query($token, $this->with_query_language($opts, $lang));
        foreach ($this->groups_from_occurrences($occurrences, $lang, 0, $lang) as $group) {
            foreach ($group as $candidate) {
                $keys[$candidate['key']] = true;
            }
        }

        $analysisCache[$cacheKey] = $keys;

        return $keys;
    }

    /**
     * Extract raw query words for snippet positioning/highlighting.
     *
     * @return string[]
     */
    private function snippet_terms(string $query): array
    {
        preg_match_all('/[\p{L}\p{N}_-]+/u', $query, $matches);
        $terms = [];
        foreach ($matches[0] ?? [] as $term) {
            $term = trim((string) $term);
            if (strlen($term) >= 2) {
                $terms[strtolower($term)] = true;
            }
        }

        return array_keys($terms);
    }

    /**
     * Analyze a query while supporting both current and legacy analyzer APIs.
     *
     * The preferred API is `analyze_query_occurrences()`. For older analyzers,
     * this method tries `analyze_query()` with both `return` and `format`
     * occurrence hints before falling back to whatever array that method returns.
     *
     * @return array<int,array<string,mixed>|string>
     */
    private function analyze_query(string $query, array $opts): array
    {
        $analysisOpts = $this->query_analysis_options($opts);
        $analysisOpts['return'] = 'occurrences';

        if (method_exists($this->analyzer, 'analyze_query_occurrences')) {
            return $this->normalize_query_analysis(
                $this->analyzer->analyze_query_occurrences($query, $analysisOpts)
            );
        }

        if (!is_callable([$this->analyzer, 'analyze_query'])) {
            throw new LogicException('Analyzer must provide analyze_query().');
        }

        $returnOpts = $analysisOpts;
        $returnOpts['return'] = 'occurrences';
        $occurrences = $this->normalize_query_analysis($this->analyzer->analyze_query($query, $returnOpts));
        if ($this->has_occurrence_rows($occurrences)) {
            return $occurrences;
        }

        $formatOpts = $analysisOpts;
        $formatOpts['format'] = 'occurrences';
        $occurrences = $this->normalize_query_analysis($this->analyzer->analyze_query($query, $formatOpts));
        if ($this->has_occurrence_rows($occurrences)) {
            return $occurrences;
        }

        return $this->normalize_query_analysis($this->analyzer->analyze_query($query, $analysisOpts));
    }

    /**
     * Prepare analyzer options with explicit query language aliases filled in.
     *
     * Older analyzers may read `lang` or `language` instead of `query_lang`, so
     * an explicit query language is mirrored across all three names.
     *
     * @param array<string,mixed> $opts Public search options.
     * @return array<string,mixed> Options passed to analyzer methods.
     */
    private function query_analysis_options(array $opts): array
    {
        $analysisOpts = $opts;
        $explicitLang = WP_FTS_TermNamespace::language_from_options($opts, null, ['query_lang', 'lang', 'language']);
        if ($explicitLang !== null) {
            $analysisOpts['query_lang'] = $explicitLang;
            $analysisOpts['lang'] = $explicitLang;
            $analysisOpts['language'] = $explicitLang;
        }

        return $analysisOpts;
    }

    /**
     * Normalize analyzer output to a numerically indexed array.
     *
     * Non-array analyzer returns are treated as no query terms.
     *
     * @param mixed $analysis Raw analyzer result.
     * @return array<int,array<string,mixed>|string>
     */
    private function normalize_query_analysis(mixed $analysis): array
    {
        return is_array($analysis) ? array_values($analysis) : [];
    }

    /**
     * Check whether analyzer output contains structured occurrence rows.
     *
     * @param array<int,array<string,mixed>|string> $analysis
     * @return bool True when at least one item is an array.
     */
    private function has_occurrence_rows(array $analysis): bool
    {
        foreach ($analysis as $occurrence) {
            if (is_array($occurrence)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Resolve the default language for untagged query occurrences.
     *
     * Explicit search options win. Otherwise the first structured occurrence
     * language or first already-namespaced term supplies the default used by
     * legacy string-only analyzer output. Mixed occurrence rows still keep their
     * own languages when query groups are built.
     *
     * @param array<int,array<string,mixed>|string> $queryOccurrences
     * @return string Canonical query language.
     */
    private function resolve_query_language(array $opts, array $queryOccurrences): string
    {
        $explicitLang = WP_FTS_TermNamespace::language_from_options($opts, null, ['query_lang', 'lang', 'language']);
        if ($explicitLang !== null) {
            return $explicitLang;
        }

        foreach ($queryOccurrences as $occurrence) {
            if (is_array($occurrence) && isset($occurrence['lang']) && trim((string) $occurrence['lang']) !== '') {
                return WP_FTS_TermNamespace::canonicalize_lang((string) $occurrence['lang']);
            }

            $term = is_array($occurrence) ? (string) ($occurrence['term'] ?? '') : (string) $occurrence;
            $split = WP_FTS_TermNamespace::split_term($term);
            if ($split !== null) {
                return $split['lang'];
            }
        }

        return WP_FTS_TermNamespace::default_language($opts);
    }

    /**
     * Compute one term's BM25 contribution for one active document.
     *
     * @param int $tf Weighted term frequency in the document.
     * @param int $docLen Document length for the query language partition.
     * @param int $docCount Number of active documents in the partition.
     * @param int $docFreq Active document frequency for the term.
     * @param float $avgDocLen Average document length in the partition.
     * @return float Positive BM25 contribution.
     */
    private function bm25(int $tf, int $docLen, int $docCount, int $docFreq, float $avgDocLen): float
    {
        $idf = log(1.0 + (($docCount - $docFreq + 0.5) / ($docFreq + 0.5)));
        $normalizer = $tf + $this->k1 * (1.0 - $this->b + $this->b * ($docLen / max(1.0, $avgDocLen)));

        return $idf * (($tf * ($this->k1 + 1.0)) / $normalizer);
    }

    /**
     * Compute a BM25 contribution when the query-level IDF is already cached.
     */
    private function bm25_with_idf(int $tf, int $docLen, float $idf, float $avgDocLen): float
    {
        $normalizer = $tf + $this->k1 * (1.0 - $this->b + $this->b * ($docLen / max(1.0, $avgDocLen)));

        return $idf * (($tf * ($this->k1 + 1.0)) / $normalizer);
    }

    /**
     * Recompute document frequencies against active documents only.
     *
     * Stored `df` values may include tombstoned documents until `optimize()`
     * compacts postings. Search scoring uses this filtered frequency so deleted
     * documents do not affect IDF.
     *
     * @param array<string,array<int,int>> $decodedByTerm
     * @param array<int,int> $activeDocLengths
     * @return array<string,int>
     */
    private function active_doc_freqs(array $decodedByTerm, array $activeDocLengths): array
    {
        $active = array_fill_keys(array_keys($activeDocLengths), true);
        $dfs = [];
        foreach ($decodedByTerm as $term => $postings) {
            $df = 0;
            foreach ($postings as $docId => $_) {
                if (isset($active[$docId])) {
                    $df++;
                }
            }
            $dfs[$term] = $df;
        }

        return $dfs;
    }
}
