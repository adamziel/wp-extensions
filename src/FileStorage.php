<?php
declare(strict_types=1);

final class WP_FTS_Storage_File implements WP_FTS_Storage
{
    private string $path;

    /** @var array<string,array{df:int,postings:string}> */
    private array $terms = [];

    /** @var array<int,array{doc_len:int,content_hash:?string,deleted:bool}> */
    private array $docs = [];

    /** @var array{doc_count:int,len_sum:int} */
    private array $meta = ['doc_count' => 0, 'len_sum' => 0];

    /** @var array<int,array{terms:array<string,array{df:int,postings:string}>,docs:array<int,array{doc_len:int,content_hash:?string,deleted:bool}>,meta:array{doc_count:int,len_sum:int}}> */
    private array $snapshots = [];

    private bool $dirty = false;

    public function __construct(string $path)
    {
        $this->path = $path;
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        $this->load();
    }

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
        } else {
            $this->terms[$term] = [
                'df' => $df,
                'postings' => $postings,
            ];
            ksort($this->terms, SORT_STRING);
        }
        $this->changed();
    }

    public function delete_term(string $term): void
    {
        unset($this->terms[$term]);
        $this->changed();
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
        $this->changed();
    }

    public function delete_doc(int $doc_id): void
    {
        if (!isset($this->docs[$doc_id])) {
            $this->docs[$doc_id] = [
                'doc_len' => 0,
                'content_hash' => null,
                'deleted' => true,
            ];
        } else {
            $this->docs[$doc_id]['deleted'] = true;
        }
        ksort($this->docs, SORT_NUMERIC);
        $this->changed();
    }

    public function get_meta(): array
    {
        return $this->meta;
    }

    public function add_meta(int $d_docs, int $d_len): void
    {
        $this->meta['doc_count'] = max(0, $this->meta['doc_count'] + $d_docs);
        $this->meta['len_sum'] = max(0, $this->meta['len_sum'] + $d_len);
        $this->changed();
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
        if ($this->snapshots === [] && $this->dirty) {
            $this->persist();
            $this->dirty = false;
        }
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
        $this->dirty = $this->snapshots !== [];
        if ($this->snapshots === []) {
            $this->load();
        }
    }

    public function flush(): void
    {
        if ($this->dirty && $this->snapshots === []) {
            $this->persist();
            $this->dirty = false;
        }
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
        $this->changed();
    }

    private function load(): void
    {
        $this->terms = [];
        $this->docs = [];
        $this->meta = ['doc_count' => 0, 'len_sum' => 0];

        if (!is_file($this->path)) {
            return;
        }

        $json = file_get_contents($this->path);
        if (!is_string($json) || trim($json) === '') {
            return;
        }

        $state = json_decode($json, true, flags: JSON_THROW_ON_ERROR);

        foreach (($state['terms'] ?? []) as $term => $row) {
            $postings = base64_decode((string) ($row['postings'] ?? ''), true);
            if ($postings === false) {
                continue;
            }
            $this->terms[(string) $term] = [
                'df' => (int) ($row['df'] ?? 0),
                'postings' => $postings,
            ];
        }

        foreach (($state['docs'] ?? []) as $docId => $doc) {
            $this->docs[(int) $docId] = [
                'doc_len' => max(0, (int) ($doc['doc_len'] ?? 0)),
                'content_hash' => isset($doc['content_hash']) ? (string) $doc['content_hash'] : null,
                'deleted' => (bool) ($doc['deleted'] ?? false),
            ];
        }

        $this->meta = [
            'doc_count' => max(0, (int) ($state['meta']['doc_count'] ?? 0)),
            'len_sum' => max(0, (int) ($state['meta']['len_sum'] ?? 0)),
        ];
        ksort($this->terms, SORT_STRING);
        ksort($this->docs, SORT_NUMERIC);
    }

    private function persist(): void
    {
        $terms = [];
        foreach ($this->terms as $term => $row) {
            $terms[$term] = [
                'df' => $row['df'],
                'postings' => base64_encode($row['postings']),
            ];
        }

        $docs = [];
        foreach ($this->docs as $docId => $doc) {
            $docs[(string) $docId] = $doc;
        }

        $payload = json_encode([
            'version' => 1,
            'terms' => $terms,
            'docs' => $docs,
            'meta' => $this->meta,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        $tmp = $this->path . '.tmp.' . getmypid();
        file_put_contents($tmp, $payload, LOCK_EX);
        rename($tmp, $this->path);
    }

    private function changed(): void
    {
        $this->dirty = true;
        if ($this->snapshots === []) {
            $this->persist();
            $this->dirty = false;
        }
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
