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
    /** @var array<string,array{post_id:int,language:string,title:string,status:string,document_length:int,field_texts:array<string,string>,field_metadata:array<string,array{language:string,language_provenance:string}>,updated_at:string}> */
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
        $this->replace_document_partitions(
            $post_id,
            [
                [
                    'language' => $language,
                    'title' => $title,
                    'status' => $status,
                    'document_length' => $document_length,
                    'field_term_frequencies' => $field_term_frequencies,
                    'field_texts' => $field_texts,
                    'term_positions' => $term_positions,
                ],
            ]
        );
    }

    public function replace_document_partitions(int $post_id, array $partitions): void
    {
        $this->delete_document($post_id);

        foreach ($partitions as $partition) {
            $language = (string) ($partition['language'] ?? '');
            $field_texts = (array) ($partition['field_texts'] ?? []);
            $field_keys = array_values(array_unique(array_merge(
                array_map('strval', array_keys($field_texts)),
                array_map('strval', array_keys((array) ($partition['field_term_frequencies'] ?? [])))
            )));
            $this->documents[$this->document_key($language, $post_id)] = [
                'post_id' => $post_id,
                'language' => $language,
                'title' => (string) ($partition['title'] ?? ''),
                'status' => (string) ($partition['status'] ?? ''),
                'document_length' => max(1, (int) ($partition['document_length'] ?? 0)),
                'field_texts' => $field_texts,
                'field_metadata' => $this->normalize_field_metadata($language, $field_keys, (array) ($partition['field_metadata'] ?? [])),
                'updated_at' => 'memory',
            ];

            foreach ((array) ($partition['field_term_frequencies'] ?? []) as $field => $term_frequencies) {
                foreach ((array) $term_frequencies as $term => $tf) {
                    $term = (string) $term;
                    $field = (string) $field;
                    $this->postings[$language][$term][$post_id][$field] = max(1, (int) $tf);
                    $this->positions[$language][$term][$post_id] = array_values(array_map('intval', (array) ($partition['term_positions'][$term] ?? [])));
                }
            }
        }
    }

    public function delete_document(int $post_id): void
    {
        foreach ($this->documents as $key => $document) {
            if ($document['post_id'] === $post_id) {
                unset($this->documents[$key]);
            }
        }

        foreach (array_keys($this->postings) as $language) {
            foreach (array_keys($this->postings[$language]) as $term) {
                unset($this->postings[$language][$term][$post_id]);
                unset($this->positions[$language][$term][$post_id]);

                if (($this->postings[$language][$term] ?? []) === []) {
                    unset($this->postings[$language][$term]);
                    unset($this->positions[$language][$term]);
                }
            }

            if (($this->postings[$language] ?? []) === []) {
                unset($this->postings[$language]);
            }
            if (($this->positions[$language] ?? []) === []) {
                unset($this->positions[$language]);
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

    public function fetch_term_language_hits(array $language_terms): array
    {
        $hits = [];
        foreach ($language_terms as $language => $terms) {
            $language = (string) $language;
            $hits[$language] = [];

            foreach ($terms as $term) {
                $term = (string) $term;
                $hits[$language][$term] = ($this->postings[$language][$term] ?? []) !== [];
            }
        }

        return $hits;
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
        $max_distance = max(0, $max_distance);
        $min_length = max(1, strlen($term) - $max_distance);
        $max_length = strlen($term) + $max_distance;
        $limit = max(1, $limit);
        $terms = array_keys($this->postings[$language] ?? []);
        sort($terms, SORT_STRING);

        $candidates = [];
        foreach ($terms as $candidate) {
            $candidate = (string) $candidate;
            $length = strlen($candidate);
            if ($length < $min_length || $length > $max_length) {
                continue;
            }

            if ($candidate === $term || levenshtein($term, $candidate) > $max_distance) {
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
            $key = $this->document_key($language, $post_id);
            if (isset($this->documents[$key])) {
                $lengths[$post_id] = $this->documents[$key]['document_length'];
            }
        }

        return $lengths;
    }

    public function fetch_document_fields(string $language, array $post_ids): array
    {
        $fields = [];
        foreach ($post_ids as $post_id) {
            $post_id = (int) $post_id;
            $key = $this->document_key($language, $post_id);
            if (isset($this->documents[$key])) {
                $fields[$post_id] = $this->documents[$key]['field_texts'];
            }
        }

        return $fields;
    }

    public function fetch_document_field_metadata(string $language, array $post_ids): array
    {
        $metadata = [];
        foreach ($post_ids as $post_id) {
            $post_id = (int) $post_id;
            $key = $this->document_key($language, $post_id);
            if (isset($this->documents[$key])) {
                $metadata[$post_id] = $this->documents[$key]['field_metadata'];
            }
        }

        return $metadata;
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

    private function document_key(string $language, int $post_id): string
    {
        return $language . "\t" . $post_id;
    }

    /**
     * @param string[] $field_keys
     * @param array<string,mixed> $field_metadata
     * @return array<string,array{language:string,language_provenance:string}>
     */
    private function normalize_field_metadata(string $language, array $field_keys, array $field_metadata): array
    {
        $metadata = [];
        foreach (array_unique(array_merge($field_keys, array_map('strval', array_keys($field_metadata)))) as $field) {
            $entry = $field_metadata[$field] ?? [];
            $entry = is_array($entry) ? $entry : [];
            $field_language = trim((string) ($entry['language'] ?? $language));
            $provenance = trim((string) ($entry['language_provenance'] ?? 'fallback'));

            $metadata[(string) $field] = [
                'language' => $field_language !== '' ? $field_language : $language,
                'language_provenance' => $provenance !== '' ? $provenance : 'fallback',
            ];
        }

        return $metadata;
    }
}
