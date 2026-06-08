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

    public function __construct(
        private Language_FTS_Playground_Storage_Interface $storage,
        private Language_FTS_Playground_Analyzer $analyzer,
        private float $k1 = 1.2,
        private float $b = 0.75
    ) {
    }

    /**
     * @return array<int,array{post_id:int,score:float,matched_terms:string[],matched_fields:string[],snippet:string}>
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

        $document_fields = $this->storage->fetch_document_fields($language, array_keys($candidate_ids));
        $average_length = array_sum($document_lengths) / max(1, count($document_lengths));
        $results = [];
        foreach (array_keys($candidate_ids) as $post_id) {
            if (!isset($document_lengths[$post_id])) {
                continue;
            }

            $score = 0.0;
            $matched_terms = [];
            $matched_fields = [];
            foreach ($terms as $term) {
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
                foreach ($field_tfs as $field => $tf) {
                    if ((int) $tf > 0) {
                        $matched_fields[(string) $field] = true;
                    }
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
                ];
            }
        }

        usort(
            $results,
            static fn(array $a, array $b): int => ($b['score'] <=> $a['score']) ?: ($a['post_id'] <=> $b['post_id'])
        );

        return array_slice($results, 0, max(1, $limit));
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
        if (strlen($source) <= self::SNIPPET_MAX_LENGTH) {
            return $source;
        }

        $offset = $this->first_match_offset($source, $query_terms, $language) ?? 0;
        $radius = intdiv(self::SNIPPET_MAX_LENGTH, 2);
        $start = max(0, $offset - $radius);
        $excerpt = substr($source, $start, self::SNIPPET_MAX_LENGTH);
        if (!is_string($excerpt)) {
            return $source;
        }

        return ($start > 0 ? '... ' : '') . $excerpt . ($start + self::SNIPPET_MAX_LENGTH < strlen($source) ? ' ...' : '');
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
