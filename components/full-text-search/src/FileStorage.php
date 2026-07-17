<?php
declare(strict_types=1);

/**
 * JSON-file storage backend for tests, demos, and small local indexes.
 *
 * The entire index remains memory-resident and every outer commit rewrites it,
 * so sizable or production indexes need a database backend. Cooperative file
 * writers are serialized through a sidecar lock; each transaction reloads
 * under that lock before changing state.
 */
final class WP_FTS_Storage_File implements WP_FTS_Storage, WP_FTS_DocumentMetadataStorage, WP_FTS_DocumentMetadataFilterStorage, WP_FTS_Prefix_Term_Storage, WP_FTS_Resettable_Storage
{
    private string $path;

    /** @var array<string,array{df:int,postings:string}> */
    private array $terms = [];

    /** @var array<int,array{primary_lang:string,lang_lengths:array<string,int>,doc_len:int,content_hash:?string,deleted:bool}> */
    private array $docs = [];

    /** @var array<int,array<string,mixed>> */
    private array $docMetadata = [];

    /** @var array<string,array{doc_count:int,len_sum:int}> */
    private array $meta = [];

    /** @var array<int,array{terms:array<string,array{df:int,postings:string}>,docs:array<int,array{primary_lang:string,lang_lengths:array<string,int>,doc_len:int,content_hash:?string,deleted:bool}>,docMetadata:array<int,array<string,mixed>>,meta:array<string,array{doc_count:int,len_sum:int}>,dirty:bool}> */
    private array $snapshots = [];

    private bool $dirty = false;

    private int $revision = 0;

    private ?string $fileFingerprint = null;

    /** @var resource|null */
    private $lockHandle = null;

    /**
     * Open or create a file-backed index.
     *
     * The parent directory is created when missing. Existing JSON state is loaded
     * and normalized to the current language-aware shape.
     *
     * @param string $path JSON file path for the index state.
     * @throws JsonException If an existing state file contains invalid JSON.
     * @throws RuntimeException If the directory, lock, or state file cannot be read.
     */
    public function __construct(string $path)
    {
        $this->path = $path;
        $this->ensure_parent_directory();
        $this->acquire_exclusive_lock();
        try {
            $this->load();
        } finally {
            $this->release_lock();
        }
    }

    public function __destruct()
    {
        $this->release_lock();
    }

    /**
     * Return existing term rows for the requested keys.
     *
     * @param string[] $terms Stored term keys.
     * @return array<string,array{df:int,postings:string}> Rows keyed by term.
     */
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

    /**
     * Store or remove one term row and mark the file dirty.
     *
     * A non-positive document frequency deletes the row.
     */
    public function put_term(string $term, int $df, string $postings): void
    {
        $this->mutate(function () use ($term, $df, $postings): void {
            if ($df <= 0) {
                unset($this->terms[$term]);
            } else {
                $this->terms[$term] = [
                    'df' => $df,
                    'postings' => $postings,
                ];
                ksort($this->terms, SORT_STRING);
            }
        });
    }

    /**
     * Remove one term row if it exists.
     */
    public function delete_term(string $term): void
    {
        $this->mutate(function () use ($term): void {
            unset($this->terms[$term]);
        });
    }

    /**
     * Return active document lengths, optionally for one language partition.
     *
     * Deleted documents and missing language partitions are omitted.
     *
     * @param int[] $doc_ids Document ids to inspect.
     * @return array<int,int> Positive lengths keyed by document id.
     */
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

    /**
     * Fetch one document metadata row or tombstone.
     *
     * @return array{primary_lang:string,lang_lengths:array<string,int>,doc_len:int,content_hash:?string,deleted:bool}|null
     */
    public function get_doc(int $doc_id): ?array
    {
        return $this->docs[$doc_id] ?? null;
    }

    /**
     * Store document metadata in either language-aware or legacy shape.
     *
     * New calls pass primary language, per-language lengths, and content hash.
     * Legacy calls pass aggregate doc length and hash.
     */
    public function put_doc(int $doc_id, string|int $primary_lang, array|string $lang_lengths, ?string $hash = null): void
    {
        [$primaryLang, $normalizedLengths, $contentHash] = $this->normalize_put_doc_args(
            $primary_lang,
            $lang_lengths,
            $hash
        );

        $this->mutate(function () use ($doc_id, $primaryLang, $normalizedLengths, $contentHash): void {
            $this->docs[$doc_id] = [
                'primary_lang' => $primaryLang,
                'lang_lengths' => $normalizedLengths,
                'doc_len' => array_sum($normalizedLengths),
                'content_hash' => $contentHash,
                'deleted' => false,
            ];
            ksort($this->docs, SORT_NUMERIC);
        });
    }

    /**
     * Store product-facing document metadata and persist it with the index file.
     *
     * @param array<string,mixed> $metadata
     */
    public function put_doc_metadata(int $doc_id, array $metadata): void
    {
        $metadata = WP_FTS_StorageCompat::normalize_doc_metadata($metadata);
        $this->mutate(function () use ($doc_id, $metadata): void {
            $this->docMetadata[$doc_id] = $metadata;
            ksort($this->docMetadata, SORT_NUMERIC);
        });
    }

    /**
     * Return metadata only for active documents.
     *
     * @param int[] $doc_ids
     * @return array<int,array<string,mixed>>
     */
    public function get_doc_metadata(array $doc_ids): array
    {
        $metadata = [];
        foreach (array_unique(array_map('intval', $doc_ids)) as $docId) {
            if (isset($this->docs[$docId], $this->docMetadata[$docId]) && !$this->docs[$docId]['deleted']) {
                $metadata[$docId] = $this->docMetadata[$docId];
            }
        }
        ksort($metadata, SORT_NUMERIC);

        return $metadata;
    }

    /**
     * Return active candidate ids whose scalar metadata matches the filters.
     *
     * @param int[] $doc_ids
     * @param string[] $post_types
     * @param string[] $post_statuses
     * @return int[]
     */
    public function filter_doc_ids_by_metadata(
        array $doc_ids,
        array $post_types = [],
        array $post_statuses = [],
        ?string $date_after = null,
        ?string $date_before = null
    ): array {
        $matches = [];
        foreach (array_unique(array_map('intval', $doc_ids)) as $docId) {
            if (!isset($this->docs[$docId], $this->docMetadata[$docId]) || $this->docs[$docId]['deleted']) {
                continue;
            }

            if (WP_FTS_StorageCompat::metadata_matches_filters(
                $this->docMetadata[$docId],
                $post_types,
                $post_statuses,
                $date_after,
                $date_before
            )) {
                $matches[] = $docId;
            }
        }
        sort($matches, SORT_NUMERIC);

        return $matches;
    }

    /**
     * Mark a document deleted, creating a tombstone for unknown ids.
     */
    public function delete_doc(int $doc_id): void
    {
        $this->mutate(function () use ($doc_id): void {
            if (!isset($this->docs[$doc_id])) {
                $this->docs[$doc_id] = [
                    'primary_lang' => '',
                    'lang_lengths' => [],
                    'doc_len' => 0,
                    'content_hash' => null,
                    'deleted' => true,
                ];
            } else {
                $this->docs[$doc_id]['deleted'] = true;
            }
            ksort($this->docs, SORT_NUMERIC);
        });
    }

    /**
     * Return derived collection metadata for active documents.
     *
     * Metadata is rebuilt from document rows before every read so on-disk state
     * cannot drift from document lengths.
     *
     * @return array{doc_count:int,len_sum:int}
     */
    public function get_meta(?string $lang = null): array
    {
        $this->sync_meta_from_docs();
        if ($lang === null) {
            return $this->aggregate_meta();
        }

        $lang = $this->normalize_lang($lang);

        return $this->meta[$lang] ?? ['doc_count' => 0, 'len_sum' => 0];
    }

    /**
     * Add signed deltas to stored metadata and mark the file dirty.
     *
     * Supports both `($lang, $d_docs, $d_len)` and legacy `($d_docs, $d_len)`.
     */
    public function add_meta(string|int $lang, int $d_docs, ?int $d_len = null): void
    {
        [$normalizedLang, $docDelta, $lenDelta] = $this->normalize_meta_args($lang, $d_docs, $d_len);
        $this->mutate(function () use ($normalizedLang, $docDelta, $lenDelta): void {
            $current = $this->meta[$normalizedLang] ?? ['doc_count' => 0, 'len_sum' => 0];
            $this->meta[$normalizedLang] = [
                'doc_count' => max(0, $current['doc_count'] + $docDelta),
                'len_sum' => max(0, $current['len_sum'] + $lenDelta),
            ];
        });
    }

    /**
     * List all stored term keys in sorted order.
     *
     * @return string[]
     */
    public function all_terms(): array
    {
        $terms = array_keys($this->terms);
        sort($terms, SORT_STRING);

        return $terms;
    }

    /**
     * Return stored term keys that start with a namespaced prefix.
     *
     * @return string[]
     */
    public function terms_with_prefix(string $prefix, int $limit): array
    {
        $limit = max(1, (int) $limit);
        if ($prefix === '') {
            return [];
        }

        $terms = [];
        foreach ($this->all_terms() as $term) {
            if (str_starts_with($term, $prefix)) {
                $terms[] = $term;
                if (count($terms) >= $limit) {
                    break;
                }
            }
        }

        return $terms;
    }

    /**
     * List document ids, optionally including tombstones.
     *
     * @return int[]
     */
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

    /**
     * Start a rollback scope.
     *
     * The outermost transaction obtains the process lock and reloads the latest
     * committed state. Nested transactions only add rollback savepoints, which
     * lets callers wrap many Indexer operations in one durable file rewrite.
     */
    public function begin_transaction(): void
    {
        if ($this->snapshots === []) {
            $this->acquire_exclusive_lock();
            try {
                $this->load();
            } catch (Throwable $error) {
                $this->release_lock();
                throw $error;
            }
        }

        $this->snapshots[] = $this->capture_snapshot();
    }

    /**
     * Commit a rollback scope and persist when the outermost transaction closes.
     */
    public function commit(): void
    {
        if ($this->snapshots === []) {
            return;
        }

        if (count($this->snapshots) > 1) {
            array_pop($this->snapshots);
            return;
        }

        if ($this->dirty) {
            // Keep the rollback snapshot and lock until persistence succeeds.
            $this->persist();
        }

        array_pop($this->snapshots);
        $this->dirty = false;
        $this->release_lock();
    }

    /**
     * Restore the latest snapshot and release the lock after outer rollback.
     */
    public function rollback(): void
    {
        $snapshot = array_pop($this->snapshots);
        if ($snapshot === null) {
            return;
        }

        $this->restore_snapshot($snapshot);
        if ($this->snapshots === []) {
            $this->release_lock();
        }
    }

    /**
     * Persist dirty changes when not inside a transaction.
     */
    public function flush(): void
    {
        if ($this->dirty && $this->snapshots === []) {
            $this->acquire_exclusive_lock();
            try {
                $this->persist();
                $this->dirty = false;
            } finally {
                $this->release_lock();
            }
        }
    }

    /**
     * Clear all derived index rows while keeping the JSON storage file usable.
     *
     * @return array<string,int>
     */
    public function reset_index(): array
    {
        return $this->mutate(function (): array {
            $counts = [
                'postings_deleted' => array_sum(array_map(
                    static fn(array $row): int => count(WP_FTS_PostingsCodec::decode($row['postings'])),
                    $this->terms
                )),
                'terms_deleted' => count($this->terms),
                'docs_deleted' => count($this->docs),
                'doc_lengths_deleted' => array_sum(array_map(
                    static fn(array $doc): int => count($doc['lang_lengths'] ?? []),
                    $this->docs
                )),
                'doc_metadata_deleted' => count($this->docMetadata),
                'collection_metadata_deleted' => count($this->meta),
            ];

            $this->terms = [];
            $this->docs = [];
            $this->docMetadata = [];
            $this->meta = [];

            return $counts;
        });
    }

    /**
     * Remove tombstoned documents from postings and persist compacted state.
     */
    public function optimize(): void
    {
        $this->mutate(function (): void {
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
                unset($this->docMetadata[$docId]);
            }

            $this->sync_meta_from_docs();
            ksort($this->terms, SORT_STRING);
            ksort($this->docs, SORT_NUMERIC);
        });
    }

    /**
     * Apply one mutation inside the active transaction or one locked commit.
     */
    private function mutate(callable $mutation): mixed
    {
        if ($this->snapshots !== []) {
            $result = $mutation();
            $this->dirty = true;

            return $result;
        }

        $this->acquire_exclusive_lock();
        try {
            $this->load();
            $snapshot = $this->capture_snapshot();
            try {
                $result = $mutation();
                $this->dirty = true;
                $this->persist();
                $this->dirty = false;

                return $result;
            } catch (Throwable $error) {
                $this->restore_snapshot($snapshot);
                throw $error;
            }
        } finally {
            $this->release_lock();
        }
    }

    /**
     * Load and normalize JSON state from disk.
     *
     * Versions without a revision are accepted as revision zero. Version 1
     * aggregate metadata is upgraded in memory. Invalid base64 postings are
     * skipped instead of poisoning the whole index.
     *
     * @throws JsonException If the JSON document cannot be decoded.
     * @throws RuntimeException If the state file cannot be read.
     */
    private function load(): void
    {
        $this->terms = [];
        $this->docs = [];
        $this->docMetadata = [];
        $this->meta = [];
        $this->revision = 0;
        $this->fileFingerprint = null;
        $this->dirty = false;

        $json = $this->read_state_json();
        if ($json === null) {
            return;
        }

        $state = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        if (!is_array($state)) {
            throw new UnexpectedValueException('File storage state must be a JSON object.');
        }

        $revision = $state['revision'] ?? 0;
        if (!is_int($revision) || $revision < 0) {
            throw new UnexpectedValueException('File storage revision must be a non-negative integer.');
        }

        $storedTerms = $state['terms'] ?? [];
        if (!is_array($storedTerms)) {
            throw new UnexpectedValueException('File storage terms must be a JSON object.');
        }
        foreach ($storedTerms as $term => $row) {
            if (!is_array($row)) {
                continue;
            }
            $postings = base64_decode((string) ($row['postings'] ?? ''), true);
            if ($postings === false) {
                continue;
            }
            $this->terms[(string) $term] = [
                'df' => (int) ($row['df'] ?? 0),
                'postings' => $postings,
            ];
        }

        $storedDocs = $state['docs'] ?? [];
        if (!is_array($storedDocs)) {
            throw new UnexpectedValueException('File storage documents must be a JSON object.');
        }
        foreach ($storedDocs as $docId => $doc) {
            if (!is_array($doc)) {
                continue;
            }
            $langLengths = isset($doc['lang_lengths']) && is_array($doc['lang_lengths'])
                ? $this->normalize_lang_lengths($doc['lang_lengths'])
                : $this->normalize_lang_lengths(['' => (int) ($doc['doc_len'] ?? 0)]);
            $this->docs[(int) $docId] = [
                'primary_lang' => $this->normalize_lang((string) ($doc['primary_lang'] ?? ($doc['lang'] ?? ''))),
                'lang_lengths' => $langLengths,
                'doc_len' => array_sum($langLengths),
                'content_hash' => isset($doc['content_hash']) ? (string) $doc['content_hash'] : null,
                'deleted' => (bool) ($doc['deleted'] ?? false),
            ];
        }

        $storedMetadata = $state['doc_meta'] ?? [];
        if (!is_array($storedMetadata)) {
            throw new UnexpectedValueException('File storage document metadata must be a JSON object.');
        }
        foreach ($storedMetadata as $docId => $metadata) {
            if (is_array($metadata)) {
                $this->docMetadata[(int) $docId] = WP_FTS_StorageCompat::normalize_doc_metadata($metadata);
            }
        }

        $this->meta = $this->load_meta($state['meta'] ?? []);
        $this->sync_meta_from_docs();
        ksort($this->terms, SORT_STRING);
        ksort($this->docs, SORT_NUMERIC);
        ksort($this->docMetadata, SORT_NUMERIC);
        $this->revision = $revision;
        $this->fileFingerprint = hash('sha256', $json);
    }

    /**
     * Write the current state through a fully written and fsynced temporary file.
     *
     * The target fingerprint is checked before the atomic rename so an external
     * writer that ignored the sidecar lock is not silently overwritten. Binary
     * postings are base64-encoded because JSON cannot carry raw blobs.
     *
     * @throws JsonException If encoding fails.
     * @throws RuntimeException If any persistence operation fails.
     */
    private function persist(): void
    {
        if (!is_resource($this->lockHandle)) {
            throw new LogicException('File storage persistence requires the exclusive lock.');
        }
        if ($this->revision === PHP_INT_MAX) {
            throw new OverflowException('File storage revision cannot be incremented.');
        }

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

        $docMetadata = [];
        foreach ($this->docMetadata as $docId => $metadata) {
            $docMetadata[(string) $docId] = $metadata;
        }

        $this->sync_meta_from_docs();
        $nextRevision = $this->revision + 1;

        $payload = json_encode([
            'version' => 3,
            'revision' => $nextRevision,
            'terms' => $terms,
            'docs' => $docs,
            'doc_meta' => $docMetadata,
            'meta' => $this->meta,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        $this->ensure_parent_directory();
        $this->assert_file_unchanged();
        $tmp = $this->path . '.tmp.' . getmypid() . '.' . bin2hex(random_bytes(8));
        $handle = @fopen($tmp, 'x+b');
        if (!is_resource($handle)) {
            throw new RuntimeException('File storage temporary file could not be created.');
        }

        $renamed = false;
        try {
            $this->write_all($handle, $payload);
            if (!@fflush($handle)) {
                throw new RuntimeException('File storage temporary file could not be flushed.');
            }
            if (!function_exists('fsync') || !@fsync($handle)) {
                throw new RuntimeException('File storage temporary file could not be synchronized.');
            }
            if (!@fclose($handle)) {
                throw new RuntimeException('File storage temporary file could not be closed.');
            }
            $handle = null;

            $this->assert_file_unchanged();
            if (!@rename($tmp, $this->path)) {
                throw new RuntimeException('File storage state could not be atomically replaced.');
            }
            $renamed = true;
        } finally {
            if (is_resource($handle)) {
                @fclose($handle);
            }
            if (!$renamed && is_file($tmp)) {
                @unlink($tmp);
            }
        }

        // No fallible operation follows the rename: a successful return means
        // memory and the committed file describe the same revision.
        $this->revision = $nextRevision;
        $this->fileFingerprint = hash('sha256', $payload);
    }

    /**
     * Read the current state document, distinguishing a missing index from I/O
     * failure or a corrupt empty target.
     */
    private function read_state_json(): ?string
    {
        clearstatcache(true, $this->path);
        if (!file_exists($this->path) && !is_link($this->path)) {
            return null;
        }
        if (!is_file($this->path)) {
            throw new RuntimeException('File storage state path is not a regular file.');
        }

        $json = @file_get_contents($this->path);
        if (!is_string($json)) {
            throw new RuntimeException('File storage state could not be read.');
        }
        if (trim($json) === '') {
            throw new UnexpectedValueException('File storage state is empty.');
        }

        return $json;
    }

    /**
     * Reject changes made outside the cooperative sidecar lock.
     */
    private function assert_file_unchanged(): void
    {
        $json = $this->read_state_json();
        $fingerprint = $json === null ? null : hash('sha256', $json);
        if ($fingerprint !== $this->fileFingerprint) {
            throw new RuntimeException('File storage state changed outside the active transaction.');
        }
    }

    /**
     * Write the complete payload while treating short writes as normal I/O.
     *
     * @param resource $handle
     */
    private function write_all($handle, string $payload): void
    {
        $offset = 0;
        $length = strlen($payload);
        while ($offset < $length) {
            $chunk = substr($payload, $offset, min(1048576, $length - $offset));
            $written = @fwrite($handle, $chunk);
            if (!is_int($written) || $written <= 0) {
                throw new RuntimeException('File storage temporary file could not be written completely.');
            }
            $offset += $written;
        }
    }

    /**
     * Capture one in-memory rollback point, including its prior dirty state.
     *
     * @return array{terms:array<string,array{df:int,postings:string}>,docs:array<int,array{primary_lang:string,lang_lengths:array<string,int>,doc_len:int,content_hash:?string,deleted:bool}>,docMetadata:array<int,array<string,mixed>>,meta:array<string,array{doc_count:int,len_sum:int}>,dirty:bool}
     */
    private function capture_snapshot(): array
    {
        return [
            'terms' => $this->terms,
            'docs' => $this->docs,
            'docMetadata' => $this->docMetadata,
            'meta' => $this->meta,
            'dirty' => $this->dirty,
        ];
    }

    /**
     * Restore one in-memory rollback point.
     *
     * @param array{terms:array<string,array{df:int,postings:string}>,docs:array<int,array{primary_lang:string,lang_lengths:array<string,int>,doc_len:int,content_hash:?string,deleted:bool}>,docMetadata:array<int,array<string,mixed>>,meta:array<string,array{doc_count:int,len_sum:int}>,dirty:bool} $snapshot
     */
    private function restore_snapshot(array $snapshot): void
    {
        $this->terms = $snapshot['terms'];
        $this->docs = $snapshot['docs'];
        $this->docMetadata = $snapshot['docMetadata'];
        $this->meta = $snapshot['meta'];
        $this->dirty = $snapshot['dirty'];
    }

    /**
     * Create the state directory, treating every failed filesystem result as an
     * error rather than silently falling back to an empty index.
     */
    private function ensure_parent_directory(): void
    {
        $directory = dirname($this->path);
        if (is_dir($directory)) {
            return;
        }
        if (!@mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new RuntimeException('File storage directory could not be created.');
        }
    }

    /**
     * Hold one advisory lock across reload, mutation, and outer commit.
     */
    private function acquire_exclusive_lock(): void
    {
        if (is_resource($this->lockHandle)) {
            return;
        }

        $this->ensure_parent_directory();
        $handle = @fopen($this->path . '.lock', 'c+b');
        if (!is_resource($handle)) {
            throw new RuntimeException('File storage lock file could not be opened.');
        }
        if (!@flock($handle, LOCK_EX)) {
            @fclose($handle);
            throw new RuntimeException('File storage lock could not be acquired.');
        }

        $this->lockHandle = $handle;
    }

    /**
     * Release the sidecar lock. Closing the handle also releases it when an
     * explicit unlock is unavailable during shutdown.
     */
    private function release_lock(): void
    {
        if (!is_resource($this->lockHandle)) {
            $this->lockHandle = null;
            return;
        }

        $handle = $this->lockHandle;
        $this->lockHandle = null;
        @flock($handle, LOCK_UN);
        @fclose($handle);
    }

    /**
     * Normalize metadata loaded from current or legacy JSON state.
     *
     * Legacy aggregate payloads with top-level `doc_count`/`len_sum` are mapped
     * to the empty-language partition until document rows rebuild metadata.
     *
     * @param mixed $meta
     * @return array<string,array{doc_count:int,len_sum:int}>
     */
    private function load_meta(mixed $meta): array
    {
        if (!is_array($meta)) {
            return [];
        }

        if (isset($meta['doc_count']) || isset($meta['len_sum'])) {
            return [
                '' => [
                    'doc_count' => max(0, (int) ($meta['doc_count'] ?? 0)),
                    'len_sum' => max(0, (int) ($meta['len_sum'] ?? 0)),
                ],
            ];
        }

        $loaded = [];
        foreach ($meta as $lang => $row) {
            if (!is_array($row)) {
                continue;
            }
            $loaded[$this->normalize_lang((string) $lang)] = [
                'doc_count' => max(0, (int) ($row['doc_count'] ?? 0)),
                'len_sum' => max(0, (int) ($row['len_sum'] ?? 0)),
            ];
        }
        ksort($loaded, SORT_STRING);

        return $loaded;
    }

    /**
     * Normalize `put_doc()` overloads into one internal payload.
     *
     * @param string|int $primary_lang Primary language or legacy document length.
     * @param array<string,int>|string $lang_lengths Per-language lengths or
     *        legacy content hash.
     * @param string|null $hash New-shape content hash.
     * @return array{string,array<string,int>,string}
     * @throws InvalidArgumentException For unsupported argument combinations.
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
     * Drop non-positive lengths, normalize language keys, and sort them.
     *
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

    /**
     * Normalize a storage-local language key.
     *
     * File storage preserves the legacy empty-string aggregate partition and
     * otherwise trims only; canonicalization happens in higher-level adapters.
     */
    private function normalize_lang(string $lang): string
    {
        return trim($lang);
    }

    /**
     * Normalize `add_meta()` overloads into language, doc delta, and length delta.
     *
     * @return array{string,int,int}
     * @throws InvalidArgumentException For unsupported argument combinations.
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

    /**
     * Rebuild per-language metadata from active document rows.
     */
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
     * Compute aggregate metadata across active documents.
     *
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
