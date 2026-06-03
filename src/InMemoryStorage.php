<?php
declare(strict_types=1);

final class WP_FTS_Storage_InMemory implements WP_FTS_Storage
{
    /** @var array<string,array{df:int,postings:string}> */
    private array $terms = [];

    /** @var array<int,array{primary_lang:string,lang_lengths:array<string,int>,doc_len:int,content_hash:?string,deleted:bool}> */
    private array $docs = [];

    /** @var array<string,array{doc_count:int,len_sum:int}> */
    private array $meta = [];

    /** @var array<int,array{terms:array<string,array{df:int,postings:string}>,docs:array<int,array{primary_lang:string,lang_lengths:array<string,int>,doc_len:int,content_hash:?string,deleted:bool}>,meta:array<string,array{doc_count:int,len_sum:int}>}> */
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

    public function get_doc_lengths(array $doc_ids, ?string $lang = null): array
    {
        $lang = $lang === null ? null : $this->normalize_lang($lang);
        $lengths = [];
        foreach (array_unique(array_map('intval', $doc_ids)) as $docId) {
            if (isset($this->docs[$docId]) && !$this->docs[$docId]['deleted']) {
                $length = $lang === null
                    ? $this->docs[$docId]['doc_len']
                    : ($this->docs[$docId]['lang_lengths'][$lang] ?? null);
                if ($length !== null) {
                    $lengths[$docId] = $length;
                }
            }
        }
        ksort($lengths, SORT_NUMERIC);

        return $lengths;
    }

    public function get_doc(int $doc_id): ?array
    {
        return $this->docs[$doc_id] ?? null;
    }

    public function put_doc(int $doc_id, string|int $primary_lang, array|string $lang_lengths, ?string $hash = null): void
    {
        [$primaryLang, $normalizedLengths, $contentHash] = $this->normalize_put_doc_args(
            $primary_lang,
            $lang_lengths,
            $hash
        );

        $this->docs[$doc_id] = [
            'primary_lang' => $primaryLang,
            'lang_lengths' => $normalizedLengths,
            'doc_len' => array_sum($normalizedLengths),
            'content_hash' => $contentHash,
            'deleted' => false,
        ];
        ksort($this->docs, SORT_NUMERIC);
    }

    public function delete_doc(int $doc_id): void
    {
        if (!isset($this->docs[$doc_id])) {
            $this->docs[$doc_id] = [
                'primary_lang' => '',
                'lang_lengths' => [],
                'doc_len' => 0,
                'content_hash' => null,
                'deleted' => true,
            ];
            ksort($this->docs, SORT_NUMERIC);
            return;
        }

        $this->docs[$doc_id]['deleted'] = true;
    }

    public function get_meta(?string $lang = null): array
    {
        $this->sync_meta_from_docs();
        if ($lang === null) {
            return $this->aggregate_meta();
        }

        $lang = $this->normalize_lang($lang);

        return $this->meta[$lang] ?? ['doc_count' => 0, 'len_sum' => 0];
    }

    public function add_meta(string|int $lang, int $d_docs, ?int $d_len = null): void
    {
        [$normalizedLang, $docDelta, $lenDelta] = $this->normalize_meta_args($lang, $d_docs, $d_len);
        $current = $this->meta[$normalizedLang] ?? ['doc_count' => 0, 'len_sum' => 0];
        $this->meta[$normalizedLang] = [
            'doc_count' => max(0, $current['doc_count'] + $docDelta),
            'len_sum' => max(0, $current['len_sum'] + $lenDelta),
        ];
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

        $this->sync_meta_from_docs();
        ksort($this->terms, SORT_STRING);
        ksort($this->docs, SORT_NUMERIC);
    }

    /**
     * @return array{string,array<string,int>,string}
     */
    private function normalize_put_doc_args(string|int $primary_lang, array|string $lang_lengths, ?string $hash): array
    {
        if (is_int($primary_lang) && is_string($lang_lengths) && $hash === null) {
            return [
                '',
                $this->normalize_lang_lengths(['' => $primary_lang]),
                $lang_lengths,
            ];
        }

        if (!is_string($primary_lang) || !is_array($lang_lengths) || $hash === null) {
            throw new InvalidArgumentException('put_doc expects ($doc_id, $primary_lang, $lang_lengths, $hash).');
        }

        return [
            $this->normalize_lang($primary_lang),
            $this->normalize_lang_lengths($lang_lengths),
            $hash,
        ];
    }

    /**
     * @param array<string,int> $lang_lengths
     * @return array<string,int>
     */
    private function normalize_lang_lengths(array $lang_lengths): array
    {
        $normalized = [];
        foreach ($lang_lengths as $lang => $length) {
            $length = max(0, (int) $length);
            if ($length <= 0) {
                continue;
            }
            $normalized[$this->normalize_lang((string) $lang)] = $length;
        }
        ksort($normalized, SORT_STRING);

        return $normalized;
    }

    private function normalize_lang(string $lang): string
    {
        return trim($lang);
    }

    /**
     * @return array{string,int,int}
     */
    private function normalize_meta_args(string|int $lang, int $d_docs, ?int $d_len): array
    {
        if (is_int($lang) && $d_len === null) {
            return ['', $lang, $d_docs];
        }

        if (!is_string($lang) || $d_len === null) {
            throw new InvalidArgumentException('add_meta expects ($lang, $d_docs, $d_len).');
        }

        return [$this->normalize_lang($lang), $d_docs, $d_len];
    }

    private function sync_meta_from_docs(): void
    {
        $meta = [];
        foreach ($this->docs as $doc) {
            if ($doc['deleted']) {
                continue;
            }
            foreach ($doc['lang_lengths'] as $lang => $length) {
                if ($length <= 0) {
                    continue;
                }
                $meta[$lang] ??= ['doc_count' => 0, 'len_sum' => 0];
                $meta[$lang]['doc_count']++;
                $meta[$lang]['len_sum'] += $length;
            }
        }
        ksort($meta, SORT_STRING);

        $this->meta = $meta;
    }

    /**
     * @return array{doc_count:int,len_sum:int}
     */
    private function aggregate_meta(): array
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

        return ['doc_count' => $docCount, 'len_sum' => $lenSum];
    }
}
