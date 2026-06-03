<?php
declare(strict_types=1);

final class WP_FTS_Searcher
{
    public function __construct(
        private WP_FTS_Storage $storage,
        private object $analyzer,
        private float $k1 = 1.2,
        private float $b = 0.75,
    ) {
    }

    /**
     * @param array{mode?:string,limit?:int,lang?:string,language?:string} $opts
     * @return array<int,array{doc_id:int,score:float}>
     */
    public function search(string $query, array $opts = []): array
    {
        $queryLang = $this->resolve_language($opts['lang'] ?? $opts['language'] ?? null);
        $terms = $this->query_term_keys($this->analyze_query($query, $queryLang), $queryLang);
        if ($terms === []) {
            return [];
        }

        $mode = strtoupper((string) ($opts['mode'] ?? 'OR'));
        if (!in_array($mode, ['OR', 'AND'], true)) {
            throw new InvalidArgumentException('Search mode must be OR or AND.');
        }
        $limit = max(1, (int) ($opts['limit'] ?? 10));

        $termRows = $this->storage->get_terms($terms);
        if ($termRows === [] || ($mode === 'AND' && count($termRows) < count($terms))) {
            return [];
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

        $docLengths = $this->storage->get_doc_lengths(array_keys($candidateTermTfs), $queryLang);
        if ($docLengths === []) {
            return [];
        }

        $meta = $this->storage->get_meta($queryLang);
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

    private function bm25(int $tf, int $docLen, int $docCount, int $docFreq, float $avgDocLen): float
    {
        $idf = log(1.0 + (($docCount - $docFreq + 0.5) / ($docFreq + 0.5)));
        $normalizer = $tf + $this->k1 * (1.0 - $this->b + $this->b * ($docLen / max(1.0, $avgDocLen)));

        return $idf * (($tf * ($this->k1 + 1.0)) / $normalizer);
    }

    /**
     * @return array<int,string|array{term?:string,lang?:string}>
     */
    private function analyze_query(string $query, string $queryLang): array
    {
        $options = [
            'lang' => $queryLang,
            'language' => $queryLang,
            'query_lang' => $queryLang,
            'return' => 'occurrences',
        ];

        if (is_callable([$this->analyzer, 'analyze_query_occurrences'])) {
            return $this->analyzer->analyze_query_occurrences($query, $options);
        }

        if (!is_callable([$this->analyzer, 'analyze_query'])) {
            throw new LogicException('Analyzer must provide analyze_query().');
        }

        return $this->analyzer->analyze_query($query, $options);
    }

    /**
     * @param array<int,string|array{term?:string,lang?:string}> $queryTerms
     * @return string[]
     */
    private function query_term_keys(array $queryTerms, string $queryLang): array
    {
        $keys = [];
        foreach ($queryTerms as $queryTerm) {
            if (is_array($queryTerm)) {
                $term = (string) ($queryTerm['term'] ?? '');
                $lang = $this->resolve_language($queryTerm['lang'] ?? $queryLang);
            } else {
                $term = (string) $queryTerm;
                $lang = $queryLang;
            }

            if ($term === '' || !WP_FTS_Language::term_key_fits($term, $lang)) {
                continue;
            }

            $keys[] = WP_FTS_Language::term_key($term, $lang);
        }

        return array_values(array_unique($keys));
    }

    private function resolve_language(mixed $lang): string
    {
        if (is_string($lang) && trim($lang) !== '') {
            return WP_FTS_Language::canonicalize($lang);
        }

        if (function_exists('get_locale')) {
            $locale = get_locale();
            if (is_string($locale) && $locale !== '') {
                return WP_FTS_Language::canonicalize($locale);
            }
        }

        if (function_exists('get_bloginfo')) {
            $siteLang = get_bloginfo('language');
            if (is_string($siteLang) && $siteLang !== '') {
                return WP_FTS_Language::canonicalize($siteLang);
            }
        }

        return WP_FTS_Language::DEFAULT_LANG;
    }

    /**
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
