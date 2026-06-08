<?php
declare(strict_types=1);

/**
 * Small deterministic storage adapter for CLI tools and tests.
 *
 * It implements the same storage contract as the WordPress-backed adapter but
 * keeps every posting row in PHP arrays, making it suitable for fixture-sized
 * relevance checks that must run without a WordPress runtime.
 */
final class Language_FTS_Playground_In_Memory_Storage implements Language_FTS_Playground_Storage_Interface
{
    /** @var array<int,array{post_id:int,language:string,title:string,status:string,document_length:int,field_texts:array<string,string>,updated_at:string}> */
    private array $documents = [];

    /** @var array<string,array<string,array<int,array<string,int>>>> */
    private array $postings = [];

    /** @var array<string,array<string,array<int,int[]>>> */
    private array $positions = [];

    public function install(): void
    {
    }

    public function clear(): void
    {
        $this->documents = [];
        $this->postings = [];
        $this->positions = [];
    }

    public function replace_document(
        int $post_id,
        string $language,
        string $title,
        string $status,
        int $document_length,
        array $field_term_frequencies,
        array $field_texts,
        array $term_positions
    ): void {
        $this->delete_document($post_id);
        $this->documents[$post_id] = [
            'post_id' => $post_id,
            'language' => $language,
            'title' => $title,
            'status' => $status,
            'document_length' => max(1, $document_length),
            'field_texts' => $field_texts,
            'updated_at' => 'memory',
        ];

        foreach ($field_term_frequencies as $field => $term_frequencies) {
            foreach ($term_frequencies as $term => $tf) {
                $term = (string) $term;
                $field = (string) $field;
                $this->postings[$language][$term][$post_id][$field] = max(1, (int) $tf);
                $this->positions[$language][$term][$post_id] = array_values(array_map('intval', $term_positions[$term] ?? []));
            }
        }
    }

    public function delete_document(int $post_id): void
    {
        unset($this->documents[$post_id]);

        foreach ($this->postings as $language => $terms) {
            foreach ($terms as $term => $postings) {
                unset($postings[$post_id]);
                unset($this->positions[$language][$term][$post_id]);
                if ($postings === []) {
                    unset($this->postings[$language][$term]);
                    unset($this->positions[$language][$term]);
                } else {
                    $this->postings[$language][$term] = $postings;
                }
            }
        }
    }

    public function fetch_postings(string $language, array $terms): array
    {
        $result = [];
        foreach ($terms as $term) {
            $term = (string) $term;
            if (isset($this->postings[$language][$term])) {
                $result[$term] = $this->postings[$language][$term];
            }
        }

        return $result;
    }

    public function fetch_positions(string $language, array $terms, array $post_ids): array
    {
        $post_id_lookup = [];
        foreach ($post_ids as $post_id) {
            $post_id_lookup[(int) $post_id] = true;
        }

        $result = [];
        foreach ($terms as $term) {
            $term = (string) $term;
            foreach ($this->positions[$language][$term] ?? [] as $post_id => $positions) {
                if (isset($post_id_lookup[(int) $post_id])) {
                    $result[$term][(int) $post_id] = $positions;
                }
            }
        }

        return $result;
    }

    public function fetch_candidate_terms(string $language, string $term, int $max_distance, int $limit): array
    {
        $min_length = max(1, strlen($term) - max(0, $max_distance));
        $max_length = strlen($term) + max(0, $max_distance);
        $limit = max(1, $limit);
        $terms = array_keys($this->postings[$language] ?? []);
        sort($terms, SORT_STRING);

        $candidates = [];
        foreach ($terms as $candidate) {
            $length = strlen($candidate);
            if ($length < $min_length || $length > $max_length) {
                continue;
            }

            $candidates[] = $candidate;
            if (count($candidates) >= $limit) {
                break;
            }
        }

        return $candidates;
    }

    public function fetch_document_lengths(string $language, array $post_ids): array
    {
        $lengths = [];
        foreach ($post_ids as $post_id) {
            $post_id = (int) $post_id;
            if (($this->documents[$post_id]['language'] ?? null) === $language) {
                $lengths[$post_id] = $this->documents[$post_id]['document_length'];
            }
        }

        return $lengths;
    }

    public function fetch_document_fields(string $language, array $post_ids): array
    {
        $fields = [];
        foreach ($post_ids as $post_id) {
            $post_id = (int) $post_id;
            if (($this->documents[$post_id]['language'] ?? null) === $language) {
                $fields[$post_id] = $this->documents[$post_id]['field_texts'];
            }
        }

        return $fields;
    }

    public function document_count(string $language): int
    {
        $count = 0;
        foreach ($this->documents as $document) {
            if ($document['language'] === $language) {
                $count++;
            }
        }

        return $count;
    }

    public function all_documents(): array
    {
        return array_values($this->documents);
    }
}
