<?php
declare(strict_types=1);

final class WP_FTS_Searcher
{
    public function __construct(
        private WP_FTS_Storage $storage,
        private WP_FTS_Analyzer $analyzer,
        private float $k1 = 1.2,
        private float $b = 0.75,
    ) {
    }

    /**
     * @param array{mode?:string,limit?:int} $opts
     * @return array<int,array{doc_id:int,score:float}>
     */
    public function search(string $query, array $opts = []): array
    {
        $terms = array_values(array_unique($this->analyzer->analyze_query($query)));
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

        $docLengths = $this->storage->get_doc_lengths(array_keys($candidateTermTfs));
        if ($docLengths === []) {
            return [];
        }

        $meta = $this->storage->get_meta();
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
