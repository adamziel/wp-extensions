<?php
declare(strict_types=1);

/**
 * Scores indexed documents for a query using language-aware BM25.
 *
 * The searcher analyzes query text, builds a language-aware query plan, reads
 * matching postings and active document lengths per partition, then scores
 * matching documents with BM25 inside each term's language partition.
 */
final class WP_FTS_Searcher
{
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
     * have a posting for a document. `limit` is clamped to at least 1. Language
     * can be supplied with `query_lang`, `lang`, or `language` for the legacy
     * single-partition path. `langs` or `languages` accepts a list of exact
     * language partitions. Fallback search is opt-in via `language_fallback`,
     * `fallback_to_default_lang`, `fallback_lang`, or `fallback_languages`, and
     * can be disabled with `disable_language_fallback`.
     *
     * @param array{mode?:string,limit?:int,query_lang?:string,lang?:string,language?:string,languages?:mixed,langs?:mixed,default_lang?:string,locale?:string,language_fallback?:bool,fallback_to_default_lang?:bool,disable_language_fallback?:bool,fallback_lang?:mixed,fallback_languages?:mixed} $opts
     * @return array<int,array{doc_id:int,score:float}> Results sorted by
     *         descending score and ascending doc id for ties.
     * @throws InvalidArgumentException If `mode` is not `OR` or `AND`.
     * @throws LogicException If the analyzer does not provide a query analyzer.
     */
    public function search(string $query, array $opts = []): array
    {
        $mode = strtoupper((string) ($opts['mode'] ?? 'OR'));
        if (!in_array($mode, ['OR', 'AND'], true)) {
            throw new InvalidArgumentException('Search mode must be OR or AND.');
        }
        $limit = max(1, (int) ($opts['limit'] ?? 10));

        $groups = $this->build_query_groups($query, $opts);
        if ($groups === []) {
            return [];
        }

        $results = $this->score_query_groups($groups, $mode);
        usort($results, static function (array $a, array $b): int {
            $rankOrder = ($a['_rank'] ?? 0) <=> ($b['_rank'] ?? 0);
            if ($rankOrder !== 0) {
                return $rankOrder;
            }

            $scoreOrder = $b['score'] <=> $a['score'];
            return $scoreOrder !== 0 ? $scoreOrder : ($a['doc_id'] <=> $b['doc_id']);
        });

        $results = array_slice($results, 0, $limit);
        foreach ($results as &$result) {
            unset($result['_rank']);
        }
        unset($result);

        return $results;
    }

    /**
     * Score a language-aware query plan.
     *
     * Each group represents one logical query term. A group may contain multiple
     * language alternatives, for example exact `fr` plus fallback `en`, or the
     * same untagged query term expanded across explicit `langs`.
     *
     * @param array<int,array<int,array{key:string,lang:string,term:string,rank:int}>> $groups
     * @return array<int,array{doc_id:int,score:float,_rank:int}>
     */
    private function score_query_groups(array $groups, string $mode): array
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

        if ($termsByKey === []) {
            return [];
        }

        $termRows = $this->storage->get_terms(array_keys($termsByKey));
        if ($termRows === []) {
            return [];
        }

        if ($mode === 'AND') {
            foreach ($groups as $groupId => $alternatives) {
                $hasAvailableTerm = false;
                foreach ($alternatives as $alternative) {
                    if (isset($termRows[$alternative['key']])) {
                        $hasAvailableTerm = true;
                        break;
                    }
                }
                if (!$hasAvailableTerm) {
                    return [];
                }
            }
        }

        /** @var array<int,array<string,int>> $candidateTermTfs */
        $candidateTermTfs = [];
        /** @var array<string,array<int,int>> $decodedByTerm */
        $decodedByTerm = [];

        foreach ($termRows as $term => $row) {
            $postings = WP_FTS_PostingsCodec::decode($row['postings']);
            $decodedByTerm[$term] = $postings;
            foreach ($postings as $docId => $tf) {
                $candidateTermTfs[$docId][$term] = $tf;
            }
        }

        if ($candidateTermTfs === []) {
            return [];
        }

        $candidateDocIds = array_keys($candidateTermTfs);
        $languages = [];
        foreach ($termsByKey as $termInfo) {
            $languages[$termInfo['lang']] = true;
        }

        /** @var array<string,array<int,int>> $docLengthsByLang */
        $docLengthsByLang = [];
        /** @var array<string,array{doc_count:int,len_sum:int}> $metaByLang */
        $metaByLang = [];
        foreach (array_keys($languages) as $lang) {
            $docLengths = WP_FTS_StorageCompat::get_doc_lengths($this->storage, $candidateDocIds, $lang);
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

        $activeDf = [];
        foreach ($decodedByTerm as $term => $postings) {
            $lang = $termsByKey[$term]['lang'];
            $activeDf[$term] = isset($docLengthsByLang[$lang])
                ? $this->active_doc_freqs([$term => $postings], $docLengthsByLang[$lang])[$term]
                : 0;
        }

        $results = [];
        foreach ($candidateTermTfs as $docId => $termTfs) {
            $score = 0.0;
            $matchedGroups = [];
            foreach ($termTfs as $term => $tf) {
                $lang = $termsByKey[$term]['lang'];
                if (!isset($docLengthsByLang[$lang][$docId], $metaByLang[$lang])) {
                    continue;
                }

                $df = $activeDf[$term] ?? 0;
                if ($df <= 0) {
                    continue;
                }

                $meta = $metaByLang[$lang];
                $docCount = max(0, (int) $meta['doc_count']);
                if ($docCount === 0) {
                    continue;
                }

                $avgDocLen = $meta['len_sum'] > 0 ? $meta['len_sum'] / $docCount : 1.0;
                $score += $this->bm25($tf, $docLengthsByLang[$lang][$docId], $docCount, $df, $avgDocLen);
                foreach ($termsByKey[$term]['groups'] as $groupId => $rank) {
                    $matchedGroups[$groupId] = min($matchedGroups[$groupId] ?? $rank, $rank);
                }
            }

            if ($mode === 'AND' && count($matchedGroups) < count($groups)) {
                continue;
            }
            if ($score > 0.0) {
                $results[] = [
                    'doc_id' => (int) $docId,
                    'score' => $score,
                    '_rank' => $mode === 'AND' ? max($matchedGroups) : min($matchedGroups),
                ];
            }
        }

        return $results;
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
        foreach ($occurrences as $occurrence) {
            $candidate = $this->candidate_from_occurrence($occurrence, $defaultLang, $rank, $authoritativeLang);
            if ($candidate !== null) {
                $groups[] = [$candidate];
            }
        }

        return $groups;
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

        return [
            'key' => WP_FTS_TermNamespace::namespace_term($lang, $term),
            'lang' => $lang,
            'term' => $term,
            'rank' => $rank,
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
