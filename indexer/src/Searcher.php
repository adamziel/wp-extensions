<?php
declare(strict_types=1);

/**
 * Scores indexed documents for a query using language-aware BM25.
 *
 * The searcher analyzes query text, resolves one query language partition, reads
 * matching postings and active document lengths, then scores only documents that
 * are active in that partition.
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
     * `mode` may be `OR` or `AND`; `AND` requires every query term to have a
     * posting for a document. `limit` is clamped to at least 1. Language can be
     * supplied with `query_lang`, `lang`, or `language`; otherwise the analyzer
     * occurrence language or default language is used.
     *
     * @param array{mode?:string,limit?:int,query_lang?:string,lang?:string,language?:string,default_lang?:string,locale?:string} $opts
     * @return array<int,array{doc_id:int,score:float}> Results sorted by
     *         descending score and ascending doc id for ties.
     * @throws InvalidArgumentException If `mode` is not `OR` or `AND`.
     * @throws LogicException If the analyzer does not provide a query analyzer.
     */
    public function search(string $query, array $opts = []): array
    {
        $queryOccurrences = $this->analyze_query($query, $opts);
        $queryLang = $this->resolve_query_language($opts, $queryOccurrences);
        $terms = $this->namespace_query_terms($queryOccurrences, $queryLang);
        if ($terms === []) {
            return [];
        }

        $mode = strtoupper((string) ($opts['mode'] ?? 'OR'));
        if (!in_array($mode, ['OR', 'AND'], true)) {
            throw new InvalidArgumentException('Search mode must be OR or AND.');
        }
        $limit = max(1, (int) ($opts['limit'] ?? 10));

        $postingsByTerm = WP_FTS_StorageCompat::get_postings($this->storage, $terms);
        if ($postingsByTerm === [] || ($mode === 'AND' && count($postingsByTerm) < count($terms))) {
            return [];
        }

        /** @var array<int,array<string,int>> $candidateTermTfs */
        $candidateTermTfs = [];
        /** @var array<string,array<int,int>> $decodedByTerm */
        $decodedByTerm = [];

        foreach ($terms as $term) {
            if (!isset($postingsByTerm[$term])) {
                continue;
            }

            $postings = $postingsByTerm[$term];
            $decodedByTerm[$term] = $postings;
            foreach ($postings as $docId => $tf) {
                $candidateTermTfs[$docId][$term] = $tf;
            }
        }

        if ($candidateTermTfs === []) {
            return [];
        }

        $docLengths = WP_FTS_StorageCompat::get_doc_lengths($this->storage, array_keys($candidateTermTfs), $queryLang);
        if ($docLengths === []) {
            return [];
        }

        $meta = WP_FTS_StorageCompat::get_meta($this->storage, $queryLang);
        $docCount = max(0, (int) $meta['doc_count']);
        if ($docCount === 0) {
            return [];
        }

        $avgDocLen = $meta['len_sum'] > 0 ? $meta['len_sum'] / $docCount : 1.0;
        $activeDf = $this->active_doc_freqs($decodedByTerm, $docLengths);

        $results = [];
        foreach ($candidateTermTfs as $docId => $termTfs) {
            if (!isset($docLengths[$docId])) {
                continue;
            }
            if ($mode === 'AND' && count($termTfs) < count($terms)) {
                continue;
            }

            $score = 0.0;
            foreach ($termTfs as $term => $tf) {
                $df = $activeDf[$term] ?? 0;
                if ($df <= 0) {
                    continue;
                }
                $score += $this->bm25($tf, $docLengths[$docId], $docCount, $df, $avgDocLen);
            }

            if ($score > 0.0) {
                $results[] = [
                    'doc_id' => (int) $docId,
                    'score' => $score,
                ];
            }
        }

        usort($results, static function (array $a, array $b): int {
            $scoreOrder = $b['score'] <=> $a['score'];
            return $scoreOrder !== 0 ? $scoreOrder : ($a['doc_id'] <=> $b['doc_id']);
        });

        return array_slice($results, 0, $limit);
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
     * Resolve the language partition for the whole query.
     *
     * Explicit search options win. Otherwise the first structured occurrence
     * language or first already-namespaced term decides the partition. This keeps
     * mixed analyzer output from accidentally querying multiple language stats in
     * one BM25 calculation.
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
     * Convert analyzer query terms into stored term keys for one language.
     *
     * Terms already namespaced for another language are ignored. Unnamespaced
     * terms inherit either their occurrence language or `$queryLang`. The result
     * is unique and sorted so storage reads and tests are deterministic.
     *
     * @param array<int,array<string,mixed>|string> $queryOccurrences
     * @return string[]
     */
    private function namespace_query_terms(array $queryOccurrences, string $queryLang): array
    {
        $terms = [];
        $queryLang = WP_FTS_TermNamespace::canonicalize_lang($queryLang);

        foreach ($queryOccurrences as $occurrence) {
            $term = is_array($occurrence)
                ? trim((string) ($occurrence['term'] ?? ''))
                : trim((string) $occurrence);
            if ($term === '') {
                continue;
            }

            $split = WP_FTS_TermNamespace::split_term($term);
            if ($split !== null) {
                if ($split['lang'] !== $queryLang || $split['term'] === '') {
                    continue;
                }
                $terms[] = WP_FTS_TermNamespace::namespace_term($queryLang, $split['term']);
                continue;
            }

            $termLang = is_array($occurrence) && isset($occurrence['lang'])
                ? WP_FTS_TermNamespace::canonicalize_lang((string) $occurrence['lang'], $queryLang)
                : $queryLang;
            if ($termLang !== $queryLang) {
                continue;
            }

            $terms[] = WP_FTS_TermNamespace::namespace_term($queryLang, $term);
        }

        $terms = array_values(array_unique($terms));
        sort($terms, SORT_STRING);

        return $terms;
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
