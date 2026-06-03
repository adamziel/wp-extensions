<?php
declare(strict_types=1);

final class WP_FTS_Storage_InMemory implements WP_FTS_Storage
{
    /** @var array<string,array{df:int,postings:string}> */
    private array $terms = [];

    /** @var array<int,array{doc_len:int,content_hash:?string,deleted:bool}> */
    private array $docs = [];

    /** @var array{doc_count:int,len_sum:int} */
    private array $meta = ['doc_count' => 0, 'len_sum' => 0];

    /** @var array<int,array{terms:array<string,array{df:int,postings:string}>,docs:array<int,array{doc_len:int,content_hash:?string,deleted:bool}>,meta:array{doc_count:int,len_sum:int}}> */
    private array $snapshots = [];

    public function get_terms(array $terms): array
    {
        $result = [];
        foreach (array_unique($terms) as $term) {
            if (isset($this->terms[$term])) {
                $result[$term] = $this->terms[$term];
            }
        }

        return $result;
    }

    public function put_term(string $term, int $df, string $postings): void
    {
        if ($df <= 0) {
            unset($this->terms[$term]);
            return;
        }

        $this->terms[$term] = [
            'df' => $df,
            'postings' => $postings,
        ];
        ksort($this->terms, SORT_STRING);
    }

    public function delete_term(string $term): void
    {
        unset($this->terms[$term]);
    }

    public function get_doc_lengths(array $doc_ids): array
    {
        $lengths = [];
        foreach (array_unique(array_map('intval', $doc_ids)) as $docId) {
            if (isset($this->docs[$docId]) && !$this->docs[$docId]['deleted']) {
                $lengths[$docId] = $this->docs[$docId]['doc_len'];
            }
        }
        ksort($lengths, SORT_NUMERIC);

        return $lengths;
    }

    public function get_doc(int $doc_id): ?array
    {
        return $this->docs[$doc_id] ?? null;
    }

    public function put_doc(int $doc_id, int $doc_len, string $hash): void
    {
        $this->docs[$doc_id] = [
            'doc_len' => max(0, $doc_len),
            'content_hash' => $hash,
            'deleted' => false,
        ];
        ksort($this->docs, SORT_NUMERIC);
    }

    public function delete_doc(int $doc_id): void
    {
        if (!isset($this->docs[$doc_id])) {
            $this->docs[$doc_id] = [
                'doc_len' => 0,
                'content_hash' => null,
                'deleted' => true,
            ];
            ksort($this->docs, SORT_NUMERIC);
            return;
        }

        $this->docs[$doc_id]['deleted'] = true;
    }

    public function get_meta(): array
    {
        return $this->meta;
    }

    public function add_meta(int $d_docs, int $d_len): void
    {
        $this->meta['doc_count'] = max(0, $this->meta['doc_count'] + $d_docs);
        $this->meta['len_sum'] = max(0, $this->meta['len_sum'] + $d_len);
    }

    public function all_terms(): array
    {
        $terms = array_keys($this->terms);
        sort($terms, SORT_STRING);

        return $terms;
    }

    public function all_doc_ids(bool $include_deleted = false): array
    {
        $ids = [];
        foreach ($this->docs as $docId => $doc) {
            if ($include_deleted || !$doc['deleted']) {
                $ids[] = (int) $docId;
            }
        }
        sort($ids, SORT_NUMERIC);

        return $ids;
    }

    public function begin_transaction(): void
    {
        $this->snapshots[] = [
            'terms' => $this->terms,
            'docs' => $this->docs,
            'meta' => $this->meta,
        ];
    }

    public function commit(): void
    {
        array_pop($this->snapshots);
    }

    public function rollback(): void
    {
        $snapshot = array_pop($this->snapshots);
        if ($snapshot === null) {
            return;
        }

        $this->terms = $snapshot['terms'];
        $this->docs = $snapshot['docs'];
        $this->meta = $snapshot['meta'];
    }

    public function flush(): void
    {
    }

    public function optimize(): void
    {
        $deleted = [];
        foreach ($this->docs as $docId => $doc) {
            if ($doc['deleted']) {
                $deleted[(int) $docId] = true;
            }
        }

        if ($deleted !== []) {
            foreach ($this->terms as $term => $row) {
                $postings = WP_FTS_PostingsCodec::decode($row['postings']);
                foreach ($deleted as $docId => $_) {
                    unset($postings[$docId]);
                }

                if ($postings === []) {
                    unset($this->terms[$term]);
                    continue;
                }

                $this->terms[$term] = [
                    'df' => count($postings),
                    'postings' => WP_FTS_PostingsCodec::encode($postings),
                ];
            }
        }

        foreach ($deleted as $docId => $_) {
            unset($this->docs[$docId]);
        }

        $this->recompute_meta();
        ksort($this->terms, SORT_STRING);
        ksort($this->docs, SORT_NUMERIC);
    }

    private function recompute_meta(): void
    {
        $docCount = 0;
        $lenSum = 0;
        foreach ($this->docs as $doc) {
            if ($doc['deleted']) {
                continue;
            }
            $docCount++;
            $lenSum += $doc['doc_len'];
        }

        $this->meta = [
            'doc_count' => $docCount,
            'len_sum' => $lenSum,
        ];
    }
}
