<?php
declare(strict_types=1);

interface WP_FTS_Storage
{
    /**
     * @param string[] $terms
     * @return array<string,array{df:int,postings:string}>
     */
    public function get_terms(array $terms): array;

    public function put_term(string $term, int $df, string $postings): void;

    public function delete_term(string $term): void;

    /**
     * @param int[] $doc_ids
     * @return array<int,int> doc_id => doc length in $lang, or total length when $lang is null
     */
    public function get_doc_lengths(array $doc_ids, ?string $lang = null): array;

    /**
     * @return array{primary_lang:string,lang_lengths:array<string,int>,doc_len:int,content_hash:?string,deleted:bool}|null
     */
    public function get_doc(int $doc_id): ?array;

    /**
     * New callers pass ($doc_id, $primary_lang, $lang_lengths, $hash).
     * Legacy callers may still pass ($doc_id, $doc_len, $hash), which maps to the
     * aggregate/unspecified language partition.
     *
     * @param string|int $primary_lang
     * @param array<string,int>|string $lang_lengths
     */
    public function put_doc(int $doc_id, string|int $primary_lang, array|string $lang_lengths, ?string $hash = null): void;

    public function delete_doc(int $doc_id): void;

    /**
     * @return array{doc_count:int,len_sum:int}
     */
    public function get_meta(?string $lang = null): array;

    /**
     * New callers pass ($lang, $d_docs, $d_len). Legacy callers may still pass
     * ($d_docs, $d_len), which updates the aggregate/unspecified partition.
     */
    public function add_meta(string|int $lang, int $d_docs, ?int $d_len = null): void;

    /**
     * @return string[]
     */
    public function all_terms(): array;

    /**
     * @return int[]
     */
    public function all_doc_ids(bool $include_deleted = false): array;

    public function begin_transaction(): void;

    public function commit(): void;

    public function rollback(): void;

    public function flush(): void;

    public function optimize(): void;
}
