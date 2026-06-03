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
    public function get_doc_lengths(array $doc_ids): array;

    /**
     * @return array{doc_len:int,content_hash:?string,deleted:bool}|null
     */
    public function get_doc(int $doc_id): ?array;

    public function put_doc(int $doc_id, int $doc_len, string $hash): void;

    public function delete_doc(int $doc_id): void;

    /**
     * @return array{doc_count:int,len_sum:int}
     */
    public function get_meta(): array;

    public function add_meta(int $d_docs, int $d_len): void;

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
