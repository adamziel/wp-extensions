<?php
declare(strict_types=1);

/**
 * @return array{process:resource|null,pipes:array<int,resource>}
 */
function wp_fts_file_storage_start_worker(string $path, string $barrier, string $workerId, int $docId): array
{
    $pipes = [];
    $process = proc_open([
        PHP_BINARY,
        '-n',
        __DIR__ . '/../fixtures/file-storage-contention-worker.php',
        $path,
        $barrier,
        $workerId,
        (string) $docId,
        '250000',
    ], [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ], $pipes);
    if (!is_resource($process)) {
        throw new RuntimeException('Could not start file-storage contention worker.');
    }
    fclose($pipes[0]);
    unset($pipes[0]);

    return ['process' => $process, 'pipes' => $pipes];
}

/**
 * @param array{process:resource|null,pipes:array<int,resource>} $worker
 * @return array{code:int,stdout:string,stderr:string}
 */
function wp_fts_file_storage_finish_worker(array &$worker): array
{
    $stdout = stream_get_contents($worker['pipes'][1]);
    $stderr = stream_get_contents($worker['pipes'][2]);
    fclose($worker['pipes'][1]);
    fclose($worker['pipes'][2]);
    $code = is_resource($worker['process']) ? proc_close($worker['process']) : -1;
    $worker['process'] = null;
    $worker['pipes'] = [];

    return [
        'code' => $code,
        'stdout' => is_string($stdout) ? $stdout : '',
        'stderr' => is_string($stderr) ? $stderr : '',
    ];
}

/**
 * @param array<int,array{process:resource|null,pipes:array<int,resource>}> $workers
 */
function wp_fts_file_storage_stop_workers(array &$workers): void
{
    foreach ($workers as &$worker) {
        if (is_resource($worker['process'])) {
            @proc_terminate($worker['process']);
        }
        foreach ($worker['pipes'] as $pipe) {
            if (is_resource($pipe)) {
                @fclose($pipe);
            }
        }
        if (is_resource($worker['process'])) {
            @proc_close($worker['process']);
        }
        $worker['process'] = null;
        $worker['pipes'] = [];
    }
    unset($worker);
}

function wp_fts_file_storage_wait_for_files(array $paths): void
{
    $deadline = microtime(true) + 10.0;
    do {
        $missing = array_filter($paths, static fn(string $path): bool => !is_file($path));
        if ($missing === []) {
            return;
        }
        usleep(10000);
    } while (microtime(true) < $deadline);

    throw new RuntimeException('Timed out waiting for file-storage contention workers.');
}

function wp_fts_file_storage_test_directory(string $suffix): string
{
    $path = sys_get_temp_dir() . '/wp-fts-file-storage-' . getmypid() . '-' . $suffix . '-' . bin2hex(random_bytes(4));
    if (!mkdir($path, 0775, true) && !is_dir($path)) {
        throw new RuntimeException('Could not create file-storage test directory.');
    }

    return $path;
}

function wp_fts_file_storage_remove_tree(string $path): void
{
    if (!is_dir($path)) {
        if (file_exists($path) || is_link($path)) {
            @unlink($path);
        }
        return;
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($iterator as $entry) {
        if ($entry->isDir() && !$entry->isLink()) {
            @rmdir($entry->getPathname());
        } else {
            @unlink($entry->getPathname());
        }
    }
    @rmdir($path);
}

test_case('file storage durability serializes real multi-process commits without lost documents', function (): void {
    $directory = wp_fts_file_storage_test_directory('contention');
    $path = $directory . '/index.json';
    $barrier = $directory . '/writers';
    $workers = [];
    try {
        $workers[] = wp_fts_file_storage_start_worker($path, $barrier, 'alpha', 101);
        $workers[] = wp_fts_file_storage_start_worker($path, $barrier, 'beta', 202);
        wp_fts_file_storage_wait_for_files([
            $barrier . '.ready.alpha',
            $barrier . '.ready.beta',
        ]);
        assert_true(file_put_contents($barrier . '.go', 'go') !== false, 'contention barrier should release both constructed workers');

        foreach ($workers as &$worker) {
            $result = wp_fts_file_storage_finish_worker($worker);
            assert_same(0, $result['code'], 'contending file-storage worker should exit successfully: ' . $result['stderr']);
            assert_same('', $result['stderr'], 'contending file-storage worker should not emit warnings or errors');
            assert_contains('committed', $result['stdout'], 'contending file-storage worker should report its commit');
        }
        unset($worker);

        $storage = new WP_FTS_Storage_File($path);
        assert_same([101, 202], $storage->all_doc_ids(), 'serialized writers should retain both documents');
        assert_same([
            WP_FTS_TermNamespace::namespace_term('en', 'worker-alpha'),
            WP_FTS_TermNamespace::namespace_term('en', 'worker-beta'),
        ], $storage->all_terms(), 'serialized writers should retain both term rows');

        $state = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        assert_same(3, $state['version'] ?? null, 'durable file state should use the revisioned format');
        assert_same(2, $state['revision'] ?? null, 'two serialized outer commits should advance two revisions');
        assert_same([], glob($path . '.tmp.*') ?: [], 'successful atomic commits should leave no temporary files');
    } finally {
        wp_fts_file_storage_stop_workers($workers);
        wp_fts_file_storage_remove_tree($directory);
    }
});

test_case('file storage durability bulk transaction performs one whole-index rewrite', function (): void {
    $directory = wp_fts_file_storage_test_directory('bulk');
    $path = $directory . '/index.json';
    try {
        $storage = new WP_FTS_Storage_File($path);
        $indexer = new WP_FTS_Indexer($storage, new WP_FTS_Analyzer(['default_lang' => 'en']));
        $storage->begin_transaction();
        try {
            for ($docId = 1; $docId <= 25; $docId++) {
                assert_true($indexer->index_document($docId, '<p>bulk document ' . $docId . '</p>', ['lang' => 'en']), 'bulk document should be indexed');
            }
            assert_true(!is_file($path), 'nested document commits should not rewrite the target inside an outer transaction');
            $storage->commit();
        } catch (Throwable $error) {
            $storage->rollback();
            throw $error;
        }

        $state = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        assert_same(1, $state['revision'] ?? null, 'one outer bulk commit should produce one persisted revision');
        assert_same(range(1, 25), (new WP_FTS_Storage_File($path))->all_doc_ids(), 'bulk commit should retain every indexed document');

        $storage->begin_transaction();
        $storage->begin_transaction();
        $storage->put_doc(999, 'en', ['en' => 1], 'rolled-back');
        $storage->rollback();
        $storage->commit();
        $afterRollback = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        assert_same(1, $afterRollback['revision'] ?? null, 'rolling back the only nested mutation should preserve the outer clean state');
        assert_true(!in_array(999, (new WP_FTS_Storage_File($path))->all_doc_ids(), true), 'nested rollback should not leak its document');
    } finally {
        wp_fts_file_storage_remove_tree($directory);
    }
});

test_case('file storage durability CAS rejects a writer that bypasses the cooperative lock', function (): void {
    $directory = wp_fts_file_storage_test_directory('cas');
    $path = $directory . '/index.json';
    try {
        $storage = new WP_FTS_Storage_File($path);
        $storage->put_doc(1, 'en', ['en' => 1], 'seed');
        $storage->begin_transaction();
        $storage->put_doc(2, 'en', ['en' => 1], 'uncommitted');

        $external = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        $external['revision'] = 9;
        $external['docs']['99'] = [
            'primary_lang' => 'en',
            'lang_lengths' => ['en' => 1],
            'doc_len' => 1,
            'content_hash' => 'external',
            'deleted' => false,
        ];
        assert_true(file_put_contents($path, json_encode($external, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)) !== false, 'test should emulate a writer that ignored the advisory lock');

        $thrown = null;
        try {
            $storage->commit();
        } catch (RuntimeException $error) {
            $thrown = $error;
        }
        assert_true($thrown instanceof RuntimeException, 'out-of-band replacement should reject the commit');
        assert_contains('changed outside the active transaction', $thrown?->getMessage() ?? '', 'CAS failure should identify the conflicting state change');

        $storage->rollback();
        assert_same([1], $storage->all_doc_ids(), 'failed commit should retain its rollback snapshot in memory');
        $reloaded = new WP_FTS_Storage_File($path);
        assert_same([1, 99], $reloaded->all_doc_ids(), 'failed CAS commit should leave the external file untouched');
        assert_same(9, json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR)['revision'] ?? null, 'failed CAS commit should not advance or replace the external revision');
    } finally {
        wp_fts_file_storage_remove_tree($directory);
    }
});

test_case('file storage durability preserves rollback state when persistence cannot create a temporary file', function (): void {
    $directory = wp_fts_file_storage_test_directory('failure');
    $movedDirectory = $directory . '.moved';
    $path = $directory . '/index.json';
    $directoryMoved = false;
    try {
        $storage = new WP_FTS_Storage_File($path);
        $storage->put_doc(1, 'en', ['en' => 1], 'seed');
        $storage->begin_transaction();
        $storage->put_doc(2, 'en', ['en' => 1], 'must-not-persist');

        assert_true(rename($directory, $movedDirectory), 'test should move the state directory while its lock handle remains open');
        $directoryMoved = true;
        assert_true(file_put_contents($directory, 'not-a-directory') !== false, 'test should block recreation of the state directory');

        $thrown = null;
        try {
            $storage->commit();
        } catch (RuntimeException $error) {
            $thrown = $error;
        }
        assert_true($thrown instanceof RuntimeException, 'temporary-file creation failure should surface from commit');
        assert_contains('directory could not be created', $thrown?->getMessage() ?? '', 'failed commit should identify the unusable state directory');
        $storage->rollback();
        assert_same([1], $storage->all_doc_ids(), 'failed persistence should leave the pre-transaction snapshot available');

        assert_true(unlink($directory), 'test should remove the directory blocker');
        assert_true(rename($movedDirectory, $directory), 'test should restore the original state directory');
        $directoryMoved = false;
        assert_same([1], (new WP_FTS_Storage_File($path))->all_doc_ids(), 'failed persistence should leave the prior durable file unchanged');
        assert_same([], glob($path . '.tmp.*') ?: [], 'failed persistence should not leave a partial temporary file');
    } finally {
        if (is_file($directory)) {
            @unlink($directory);
        }
        if ($directoryMoved && is_dir($movedDirectory)) {
            @rename($movedDirectory, $directory);
        }
        wp_fts_file_storage_remove_tree($directory);
        wp_fts_file_storage_remove_tree($movedDirectory);
    }
});

test_case('file storage durability reports invalid state and directory I/O instead of loading an empty index', function (): void {
    $directory = wp_fts_file_storage_test_directory('invalid-io');
    try {
        $emptyPath = $directory . '/empty.json';
        assert_same(0, file_put_contents($emptyPath, ''), 'test should create an empty state file');
        $emptyError = null;
        try {
            new WP_FTS_Storage_File($emptyPath);
        } catch (UnexpectedValueException $error) {
            $emptyError = $error;
        }
        assert_true($emptyError instanceof UnexpectedValueException, 'an existing empty state should be reported as corrupt instead of treated as a new index');
        assert_contains('state is empty', $emptyError?->getMessage() ?? '', 'empty-state error should identify the corrupt target');

        $parentBlocker = $directory . '/not-a-directory';
        assert_true(file_put_contents($parentBlocker, 'blocker') !== false, 'test should create a parent-directory blocker');
        $directoryError = null;
        try {
            new WP_FTS_Storage_File($parentBlocker . '/index.json');
        } catch (RuntimeException $error) {
            $directoryError = $error;
        }
        assert_true($directoryError instanceof RuntimeException, 'unusable parent path should surface instead of silently continuing');
        assert_contains('directory could not be created', $directoryError?->getMessage() ?? '', 'parent-path error should identify directory creation');
    } finally {
        wp_fts_file_storage_remove_tree($directory);
    }
});
