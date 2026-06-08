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
        private float $b = 0.75
    ) {
    }

    /**
     * @return array<int,array{post_id:int,score:float,matched_terms:string[]}>
     */
    public function search(string $query, string $language, int $limit = 10): array
    {
        $language = $this->analyzer->canonical_language($language);
        $terms = $this->analyzer->analyze_query($query, $language);
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

        $average_length = array_sum($document_lengths) / max(1, count($document_lengths));
        $results = [];
        foreach (array_keys($candidate_ids) as $post_id) {
            if (!isset($document_lengths[$post_id])) {
                continue;
            }

            $score = 0.0;
            $matched_terms = [];
            foreach ($terms as $term) {
                $tf = $postings[$term][$post_id] ?? 0;
                if ($tf <= 0) {
                    continue;
                }

                $document_frequency = count($postings[$term] ?? []);
                $score += $this->bm25($tf, $document_lengths[$post_id], $document_count, $document_frequency, $average_length);
                $matched_terms[$term] = true;
            }

            if ($score > 0.0) {
                $results[] = [
                    'post_id' => (int) $post_id,
                    'score' => $score,
                    'matched_terms' => array_keys($matched_terms),
                ];
            }
        }

        usort(
            $results,
            static fn(array $a, array $b): int => ($b['score'] <=> $a['score']) ?: ($a['post_id'] <=> $b['post_id'])
        );

        return array_slice($results, 0, max(1, $limit));
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
