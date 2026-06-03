<?php
declare(strict_types=1);

final class WP_FTS_Storage_InMemory implements WP_FTS_Storage
{
    /** @var array<string,array{df:int,postings:string}> */
    private array $terms = [];

    /** @var array<int,array{doc_len:int,lang:string,primary_lang:string,lang_lengths:array<string,int>,content_hash:?string,deleted:bool}> */
    private array $docs = [];

    /** @var array<string,array{doc_count:int,len_sum:int}> */
    private array $meta = [];

    /** @var array<int,array{terms:array<string,array{df:int,postings:string}>,docs:array<int,array{doc_len:int,lang:string,primary_lang:string,lang_lengths:array<string,int>,content_hash:?string,deleted:bool}>,meta:array<string,array{doc_count:int,len_sum:int}>}> */
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
        $lang = $lang !== null ? WP_FTS_Language::canonicalize($lang) : null;
        $lengths = [];
        foreach (array_unique(array_map('intval', $doc_ids)) as $docId) {
            if (!isset($this->docs[$docId]) || $this->docs[$docId]['deleted']) {
                continue;
            }

            $length = $lang === null
                ? $this->docs[$docId]['doc_len']
                : ($this->docs[$docId]['lang_lengths'][$lang] ?? 0);
            if ($length > 0) {
                $lengths[$docId] = $length;
            }

        }
        ksort($lengths, SORT_NUMERIC);

        return $lengths;
    }

    public function get_doc(int $doc_id): ?array
    {
        return $this->docs[$doc_id] ?? null;
    }

    public function put_doc(int $doc_id, int|string $doc_len_or_primary_lang, string|array $hash_or_lang_lengths, ?string $hash = null): void
    {
        [$primaryLang, $langLengths, $contentHash] = $this->normalize_doc_payload(
            $doc_len_or_primary_lang,
            $hash_or_lang_lengths,
            $hash
        );

        $this->docs[$doc_id] = [
            'doc_len' => array_sum($langLengths),
            'lang' => $primaryLang,
            'primary_lang' => $primaryLang,
            'lang_lengths' => $langLengths,
            'content_hash' => $contentHash,
            'deleted' => false,
        ];
        ksort($this->docs, SORT_NUMERIC);
    }

    public function delete_doc(int $doc_id): void
    {
        if (!isset($this->docs[$doc_id])) {
            $this->docs[$doc_id] = [
                'doc_len' => 0,
                'lang' => WP_FTS_Language::DEFAULT_LANG,
                'primary_lang' => WP_FTS_Language::DEFAULT_LANG,
                'lang_lengths' => [],
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
        if ($lang !== null) {
            $lang = WP_FTS_Language::canonicalize($lang);
            return $this->meta[$lang] ?? ['doc_count' => 0, 'len_sum' => 0];
        }

        $aggregate = ['doc_count' => 0, 'len_sum' => 0];
        foreach ($this->meta as $row) {
            $aggregate['doc_count'] += $row['doc_count'];
            $aggregate['len_sum'] += $row['len_sum'];
        }

        return $aggregate;
    }

    public function add_meta(int|string $lang_or_d_docs, int $d_docs_or_d_len, ?int $d_len = null): void
    {
        [$lang, $dDocs, $dLen] = $this->normalize_meta_delta($lang_or_d_docs, $d_docs_or_d_len, $d_len);
        $this->meta[$lang] ??= ['doc_count' => 0, 'len_sum' => 0];
        $this->meta[$lang]['doc_count'] = max(0, $this->meta[$lang]['doc_count'] + $dDocs);
        $this->meta[$lang]['len_sum'] = max(0, $this->meta[$lang]['len_sum'] + $dLen);
        ksort($this->meta, SORT_STRING);
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
     * @param int|string $doc_len_or_primary_lang
     * @param string|array<string,int> $hash_or_lang_lengths
     * @return array{0:string,1:array<string,int>,2:string}
     */
    private function normalize_doc_payload(int|string $doc_len_or_primary_lang, string|array $hash_or_lang_lengths, ?string $hash): array
    {
        if (is_int($doc_len_or_primary_lang)) {
            $length = max(0, $doc_len_or_primary_lang);
            return [
                WP_FTS_Language::DEFAULT_LANG,
                $length > 0 ? [WP_FTS_Language::DEFAULT_LANG => $length] : [],
                (string) $hash_or_lang_lengths,
            ];
        }

        if (!is_array($hash_or_lang_lengths) || $hash === null) {
            throw new InvalidArgumentException('put_doc language form requires language lengths and content hash.');
        }

        return [
            WP_FTS_Language::canonicalize($doc_len_or_primary_lang),
            WP_FTS_Language::normalize_lengths($hash_or_lang_lengths),
            $hash,
        ];
    }

    /**
     * @return array{0:string,1:int,2:int}
     */
    private function normalize_meta_delta(int|string $lang_or_d_docs, int $d_docs_or_d_len, ?int $d_len): array
    {
        if ($d_len === null) {
            return [WP_FTS_Language::DEFAULT_LANG, (int) $lang_or_d_docs, $d_docs_or_d_len];
        }

        return [WP_FTS_Language::canonicalize((string) $lang_or_d_docs), $d_docs_or_d_len, $d_len];
    }
}
