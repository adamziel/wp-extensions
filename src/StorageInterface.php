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
     * @return array<int,int> doc_id => doc_len
     */
    public function get_doc_lengths(array $doc_ids, ?string $lang = null): array;

    /**
     * @return array{doc_len:int,lang:string,primary_lang:string,lang_lengths:array<string,int>,content_hash:?string,deleted:bool}|null
     */
    public function get_doc(int $doc_id): ?array;

    /**
     * Backward compatible forms:
     * - put_doc($doc_id, $doc_len, $hash)
     * - put_doc($doc_id, $primary_lang, $lang_lengths, $hash)
     *
     * @param int|string $doc_len_or_primary_lang
     * @param string|array<string,int> $hash_or_lang_lengths
     */
    public function put_doc(int $doc_id, int|string $doc_len_or_primary_lang, string|array $hash_or_lang_lengths, ?string $hash = null): void;

    public function delete_doc(int $doc_id): void;

    /**
     * @return array{doc_count:int,len_sum:int}
     */
    public function get_meta(?string $lang = null): array;

    /**
     * Backward compatible forms:
     * - add_meta($d_docs, $d_len)
     * - add_meta($lang, $d_docs, $d_len)
     */
    public function add_meta(int|string $lang_or_d_docs, int $d_docs_or_d_len, ?int $d_len = null): void;

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
