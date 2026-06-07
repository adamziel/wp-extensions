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
     * posting for a document. `limit` is clamped to at least 1 and `offset`
     * enables pagination. Language can be supplied with `query_lang`, `lang`, or
     * `language`; otherwise the analyzer occurrence language or default language
     * is used.
     *
     * Product options are opt-in to preserve the legacy return shape:
     * `include_total` returns a payload with `total`, `limit`, `offset`, and
     * `results`; `include_metadata` adds WordPress result fields; and
     * `include_snippets` builds bounded snippets from stored extracted text.
     * `post_type`, `post_status`, `date_after`, and `date_before` filter only
     * when the storage backend exposes document metadata. Prefix/phrase search is
     * intentionally not emulated on whole-term postings; pass `search_extension`
     * or use the `wp_fts_search_extension_results` filter to provide a backend
     * that can do it honestly.
     *
     * @param array<string,mixed> $opts
     * @return array<int,array<string,mixed>>|array{total:int,limit:int,offset:int,query_lang:string,results:array<int,array<string,mixed>>}
     *         Results sorted by descending score and ascending doc id for ties,
     *         or a pagination payload when `include_total` is true.
     * @throws InvalidArgumentException If `mode` is not `OR` or `AND`.
     * @throws LogicException If the analyzer does not provide a query analyzer.
     */
    public function search(string $query, array $opts = []): array
    {
        $extensionResults = $this->extension_results($query, $opts);
        if ($extensionResults !== null) {
            return $extensionResults;
        }

        $queryOccurrences = $this->analyze_query($query, $opts);
        $queryLang = $this->resolve_query_language($opts, $queryOccurrences);
        $terms = $this->namespace_query_terms($queryOccurrences, $queryLang);
        if ($terms === []) {
            return $this->format_response([], 0, $opts, $queryLang);
        }

        $mode = strtoupper((string) ($opts['mode'] ?? 'OR'));
        if (!in_array($mode, ['OR', 'AND'], true)) {
            throw new InvalidArgumentException('Search mode must be OR or AND.');
        }
        $limit = max(1, (int) ($opts['limit'] ?? 10));
        $offset = max(0, (int) ($opts['offset'] ?? 0));

        $termRows = $this->storage->get_terms($terms);
        if ($termRows === [] || ($mode === 'AND' && count($termRows) < count($terms))) {
            return $this->format_response([], 0, $opts, $queryLang);
        }

        /** @var array<int,array<string,int>> $candidateTermTfs */
        $candidateTermTfs = [];
        /** @var array<string,array<int,int>> $decodedByTerm */
        $decodedByTerm = [];

        foreach ($terms as $term) {
            if (!isset($termRows[$term])) {
                continue;
            }

            $postings = WP_FTS_PostingsCodec::decode($termRows[$term]['postings']);
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
            return $this->format_response([], 0, $opts, $queryLang);
        }

        $meta = WP_FTS_StorageCompat::get_meta($this->storage, $queryLang);
        $docCount = max(0, (int) $meta['doc_count']);
        if ($docCount === 0) {
            return $this->format_response([], 0, $opts, $queryLang);
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

        $metadata = [];
        if ($this->has_metadata_filters($opts)) {
            $metadata = WP_FTS_StorageCompat::get_doc_metadata($this->storage, array_column($results, 'doc_id'));
            $results = array_values(array_filter(
                $results,
                fn(array $row): bool => $this->metadata_matches($metadata[(int) $row['doc_id']] ?? null, $opts)
            ));
        }

        $total = count($results);
        $page = array_slice($results, $offset, $limit);
        if ($this->should_enrich_results($opts) && $page !== []) {
            $pageIds = array_column($page, 'doc_id');
            $pageMetadata = $metadata;
            if ($pageMetadata === []) {
                $pageMetadata = WP_FTS_StorageCompat::get_doc_metadata($this->storage, $pageIds);
            }
            $page = $this->enrich_results($page, $pageMetadata, $query, $opts);
        }

        return $this->format_response($page, $total, $opts, $queryLang);
    }

    /**
     * Call a real prefix/phrase extension when requested.
     *
     * The built-in posting lists are whole-term only. Returning an empty or fuzzy
     * approximation for prefix/phrase would be misleading, so these modes require
     * an explicit extension callback/filter that owns the storage contract.
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

        if (function_exists('apply_filters')) {
            $results = apply_filters('wp_fts_search_extension_results', null, $query, $opts, $this->storage, $this->analyzer);
            if ($results !== null) {
                return is_array($results) ? $results : [];
            }
        }

        throw new InvalidArgumentException('Prefix and phrase search require a search_extension callback or wp_fts_search_extension_results filter for the active storage backend.');
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
     * @return array<int,array<string,mixed>>
     */
    private function enrich_results(array $results, array $metadata, string $query, array $opts): array
    {
        $includeMetadata = !empty($opts['include_metadata']);
        $includeSnippets = !empty($opts['include_snippets']) || !empty($opts['snippets']);
        foreach ($results as &$row) {
            $docId = (int) $row['doc_id'];
            $meta = $metadata[$docId] ?? [];
            if ($includeMetadata) {
                foreach (['post_id', 'post_type', 'post_status', 'post_date_gmt', 'title', 'excerpt'] as $key) {
                    $row[$key] = $meta[$key] ?? ($key === 'post_id' ? 0 : '');
                }
            }
            if ($includeSnippets) {
                $row['snippet'] = $this->snippet(
                    (string) ($meta['search_text'] ?? $meta['excerpt'] ?? $meta['title'] ?? ''),
                    $query,
                    max(40, (int) ($opts['snippet_length'] ?? 180)),
                    !empty($opts['highlight'])
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
        foreach (['post_type', 'post_types', 'post_status', 'post_statuses', 'date_after', 'after', 'post_date_after', 'date_before', 'before', 'post_date_before'] as $key) {
            if (array_key_exists($key, $opts) && $this->normalize_filter_list($opts[$key]) !== []) {
                return true;
            }
        }

        return false;
    }

    /**
     * Apply post type/status/date filters to one metadata row.
     *
     * @param array<string,mixed>|null $metadata
     */
    private function metadata_matches(?array $metadata, array $opts): bool
    {
        if ($metadata === null) {
            return false;
        }

        $postTypes = $this->normalize_filter_list($opts['post_type'] ?? $opts['post_types'] ?? []);
        if ($postTypes !== [] && !in_array((string) ($metadata['post_type'] ?? ''), $postTypes, true)) {
            return false;
        }

        $postStatuses = $this->normalize_filter_list($opts['post_status'] ?? $opts['post_statuses'] ?? []);
        if ($postStatuses !== [] && !in_array((string) ($metadata['post_status'] ?? ''), $postStatuses, true)) {
            return false;
        }

        $date = (string) ($metadata['post_date_gmt'] ?? '');
        $after = $this->date_filter($opts['date_after'] ?? $opts['after'] ?? $opts['post_date_after'] ?? null, false);
        if ($after !== null && ($date === '' || strcmp($date, $after) < 0)) {
            return false;
        }

        $before = $this->date_filter($opts['date_before'] ?? $opts['before'] ?? $opts['post_date_before'] ?? null, true);
        if ($before !== null && ($date === '' || strcmp($date, $before) > 0)) {
            return false;
        }

        return true;
    }

    /**
     * Normalize comma-separated or array filters.
     *
     * @return string[]
     */
    private function normalize_filter_list(mixed $value): array
    {
        $items = [];
        foreach (is_array($value) ? $value : [$value] as $item) {
            foreach (explode(',', (string) $item) as $part) {
                $part = trim($part);
                if ($part !== '') {
                    $items[$part] = true;
                }
            }
        }

        $result = array_keys($items);
        sort($result, SORT_STRING);

        return $result;
    }

    /**
     * Normalize date-only filters to lexicographic SQL datetime boundaries.
     */
    private function date_filter(mixed $value, bool $endOfDay): ?string
    {
        if (!is_scalar($value) || trim((string) $value) === '') {
            return null;
        }

        $date = trim((string) $value);
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) === 1) {
            return $date . ($endOfDay ? ' 23:59:59' : ' 00:00:00');
        }

        return $date;
    }

    /**
     * Build a compact snippet from stored plain text.
     */
    private function snippet(string $text, string $query, int $length, bool $highlight): string
    {
        $text = trim(preg_replace('/\s+/u', ' ', strip_tags($text)) ?? $text);
        if ($text === '') {
            return '';
        }

        $terms = $this->snippet_terms($query);
        $start = 0;
        foreach ($terms as $term) {
            $position = stripos($text, $term);
            if ($position !== false) {
                $start = max(0, $position - intdiv($length, 3));
                break;
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

        foreach ($terms as $term) {
            $quoted = preg_quote($term, '/');
            $snippet = preg_replace('/(' . $quoted . ')/i', '<mark>$1</mark>', $snippet) ?? $snippet;
        }

        return $snippet;
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
