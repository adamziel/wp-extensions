<?php
declare(strict_types=1);

/** @return array<string,int> */
function wp_fts_sqlite_transport_terms(int $count, string $language, int $offset = 0): array
{
    $terms = [];
    for ($index = 0; $index < $count; $index++) {
        $suffix = str_pad((string) ($offset + $index), 8, '0', STR_PAD_LEFT);
        $term = str_repeat('x', 255 - strlen($suffix)) . $suffix;
        $terms[WP_FTS_TermNamespace::namespace_term($language, $term)] = 1;
    }

    return $terms;
}

/** @return array<string,mixed> */
function wp_fts_sqlite_transport_document(int $postId, int $identities, string $language): array
{
    $lexical = min(WP_FTS_Analysis_Limits::MAX_DOCUMENT_DISTINCT_TERMS, $identities);
    $surface = max(0, $identities - $lexical);
    $terms = wp_fts_sqlite_transport_terms($lexical, $language);

    return [
        'doc_id' => $postId,
        'primary_lang' => $language,
        'content_hash' => str_repeat('a', 64),
        'snippet_text' => '',
        'term_frequencies' => $terms,
        'surface_frequencies' => $surface > 0 ? array_slice($terms, 0, $surface, true) : [],
    ];
}

/** @return array<string,mixed> */
function wp_fts_sqlite_transport_measure(WP_FTS_Storage_Mysql $storage, array $documents): array
{
    $method = new ReflectionMethod($storage, 'sqlite_prepared_transport_prefix');
    $result = $method->invoke($storage, $documents);

    return is_array($result) ? $result : [];
}

/** @return array{0:WP_FTS_Test_WPDB,1:WP_FTS_Storage_Mysql} */
function wp_fts_sqlite_transport_storage(): array
{
    $wpdb = new WP_FTS_Test_WPDB();
    $wpdb->dbh = new WP_FTS_Test_SQLite_Driver();

    return [$wpdb, new WP_FTS_Storage_Mysql($wpdb)];
}

function wp_fts_sqlite_transport_method_source(string $class, string $method): string
{
    $reflection = new ReflectionMethod($class, $method);
    $lines = file($reflection->getFileName());
    if (!is_array($lines)) {
        throw new RuntimeException("Could not read {$class}::{$method}() source.");
    }

    return implode('', array_slice(
        $lines,
        $reflection->getStartLine() - 1,
        $reflection->getEndLine() - $reflection->getStartLine() + 1
    ));
}

test_case('SQLite maximum prepared identity document is a permanent pre-SQL rejection', function (): void {
    [$wpdb, $storage] = wp_fts_sqlite_transport_storage();
    $language = str_repeat('a', 32);
    $document = wp_fts_sqlite_transport_document(
        9101,
        WP_FTS_Storage_Mysql::MAX_DOCUMENT_POSTINGS,
        $language
    );

    $partition = $storage->partition_prepared_documents([$document]);
    $rejection = $partition['rejections'][0] ?? null;
    assert_true($rejection instanceof WP_FTS_Prepared_Document_Rejected, '8,192 maximum-width SQLite identities should be a typed poison-document rejection');
    assert_same(9101, $rejection instanceof WP_FTS_Prepared_Document_Rejected ? $rejection->post_id : null, 'the SQLite transport rejection should identify the exact poison generation');
    assert_same('sqlite_transport_limit', $rejection instanceof WP_FTS_Prepared_Document_Rejected ? $rejection->reason_code : null, 'the SQLite transport rejection should expose a stable reason code');
    assert_same([], $partition['documents'], 'the poison document must not remain in the replacement prefix');
    assert_same([], $partition['deferred_post_ids'], 'a single poison document must be rejected rather than deferred forever');
    assert_same([], $wpdb->queries, 'partition transport rejection must issue no SQL');
    assert_same([], $wpdb->prepared, 'partition transport rejection must not ask wpdb to prepare SQL');

    $directError = null;
    try {
        $storage->replace_prepared_documents([$document]);
    } catch (Throwable $error) {
        $directError = $error;
    }
    assert_true($directError instanceof WP_FTS_Prepared_Document_Rejected, 'the direct writer should classify the same SQLite input as permanent');
    assert_same('sqlite_transport_limit', $directError instanceof WP_FTS_Prepared_Document_Rejected ? $directError->reason_code : null, 'direct and partition paths should share the stable SQLite reason');
    assert_same([], $wpdb->queries, 'direct SQLite transport rejection must precede frontier SQL and BEGIN');
    assert_same([], $wpdb->prepared, 'direct SQLite transport rejection must precede wpdb preparation');

    $eventMethod = new ReflectionMethod(WP_FTS_Plugin::class, 'failure_recovery_event_from_failure');
    $event = $eventMethod->invoke(null, [], 9101, null, $directError);
    assert_same('rejected', $event['status'] ?? null, 'the worker recovery classifier should persist SQLite transport poison as rejected, not retry backoff');
});

test_case('SQLite largest maximum-width transport boundary uses one dictionary write and one resolver', function (): void {
    [$wpdb, $storage] = wp_fts_sqlite_transport_storage();
    $language = str_repeat('a', 32);
    $low = 1;
    $high = WP_FTS_Storage_Mysql::MAX_DOCUMENT_POSTINGS;
    while ($low < $high) {
        $candidate = intdiv($low + $high + 1, 2);
        $measure = wp_fts_sqlite_transport_measure(
            $storage,
            [wp_fts_sqlite_transport_document(9201, $candidate, $language)]
        );
        if (($measure['accepted_documents'] ?? 0) === 1) {
            $low = $candidate;
        } else {
            $high = $candidate - 1;
        }
    }
    $largestAccepted = $low;
    assert_true($largestAccepted > 4096 && $largestAccepted < WP_FTS_Storage_Mysql::MAX_DOCUMENT_POSTINGS, 'the hostile SQLite boundary should exercise both lexical and surface identity maps');
    assert_same(7098, $largestAccepted, 'the maximum-width SQLite transport frontier should retain its exact accepted identity count');

    $accepted = wp_fts_sqlite_transport_document(9201, $largestAccepted, $language);
    $acceptedMeasure = wp_fts_sqlite_transport_measure($storage, [$accepted]);
    $rejectedMeasure = wp_fts_sqlite_transport_measure(
        $storage,
        [wp_fts_sqlite_transport_document(9202, $largestAccepted + 1, $language)]
    );
    assert_same(1, $acceptedMeasure['accepted_documents'] ?? null, 'the exact largest uniform-width SQLite identity boundary should fit');
    assert_same(0, $rejectedMeasure['accepted_documents'] ?? null, 'one more maximum-width SQLite identity should cross the transport boundary');
    assert_same(4194195, $acceptedMeasure['resolution_bytes'] ?? null, 'the accepted resolver should remain 109 bytes below the 4 MiB ceiling');
    assert_same(4194786, $rejectedMeasure['resolution_bytes'] ?? null, 'the first rejected resolver should remain 482 bytes above the 4 MiB ceiling');
    assert_true(max((int) $acceptedMeasure['dictionary_bytes'], (int) $acceptedMeasure['resolution_bytes']) <= 4194304, 'both accepted SQLite statements should stay at or below 4 MiB');
    assert_true(max((int) $rejectedMeasure['dictionary_bytes'], (int) $rejectedMeasure['resolution_bytes']) > 4194304, 'the next SQLite identity should cross an exact measured statement boundary');

    $readQueries = [];
    $wpdb->readQueryObserver = static function (string $sql) use (&$readQueries): void {
        $readQueries[] = $sql;
    };
    $plan = $storage->plan_prepared_replacement([9201 => $largestAccepted]);
    $result = $storage->replace_prepared_documents([$accepted], [], $plan);
    assert_same(1, $result['replaced'] ?? null, 'the largest accepted SQLite transport document should publish');
    $dictionaryQueries = array_values(array_filter(
        $wpdb->queries,
        static fn(string $sql): bool => str_contains($sql, 'wp_fts:dictionary-increment')
    ));
    $resolutionQueries = array_values(array_filter(
        $readQueries,
        static fn(string $sql): bool => str_contains($sql, 'wp_fts:resolve-prepared-terms')
    ));
    assert_same(1, count($dictionaryQueries), 'the accepted SQLite boundary must use exactly one dictionary UPSERT');
    assert_same(1, count($resolutionQueries), 'the accepted SQLite boundary must use exactly one dictionary-id resolver');
    assert_true(strlen($dictionaryQueries[0]) <= 4194304, 'the executed SQLite dictionary UPSERT should satisfy the 4 MiB contract');
    assert_true(strlen($resolutionQueries[0]) <= 4194304, 'the executed SQLite resolver should satisfy the 4 MiB contract');
});

test_case('SQLite maximum-width renderers and fake decoders retain no complete row copy', function (): void {
    $preflight = wp_fts_sqlite_transport_method_source(WP_FTS_Storage_Mysql::class, 'sqlite_prepared_transport_prefix');
    $replace = wp_fts_sqlite_transport_method_source(WP_FTS_Storage_Mysql::class, 'replace_prepared_documents');
    $dictionary = wp_fts_sqlite_transport_method_source(WP_FTS_Storage_Mysql::class, 'sqlite_term_identity_increment_statement');
    $resolution = wp_fts_sqlite_transport_method_source(WP_FTS_Storage_Mysql::class, 'sqlite_prepared_term_resolution_sql');
    $fakeDictionary = wp_fts_sqlite_transport_method_source(WP_FTS_Test_WPDB::class, 'v4_inline_dictionary_rows');
    $fakeResolver = wp_fts_sqlite_transport_method_source(WP_FTS_Test_WPDB::class, 'v4_literal_identity_rows');

    assert_true(!str_contains($preflight, '$identities = []'), 'SQLite preflight should retain one deduplication/count map rather than a second complete identity graph');
    assert_contains('unset($batchTerms, $transport);', $replace, 'writer validation should release its maximum-width key map before rendering SQL');
    foreach ([$dictionary, $resolution] as $renderer) {
        assert_true(!str_contains($renderer, '$values[]'), 'SQLite renderers must not retain a complete array of rendered row strings');
    }
    assert_contains('$statement .=', $dictionary, 'the SQLite dictionary statement should be appended directly');
    assert_contains('$statement .= $this->sqlite_dictionary_increment_suffix();', $dictionary, 'the SQLite dictionary suffix should be appended without copying the complete statement');
    assert_true(!str_contains($dictionary, 'return $statement .'), 'the returned SQLite dictionary statement must not concatenate another complete copy');
    assert_contains('$sql .=', $resolution, 'the complete SQLite identity resolver should be appended directly');
    assert_contains('$sql .= $this->sqlite_identity_relation_suffix();', $resolution, 'the SQLite relation suffix should be appended without copying the complete statement');
    assert_contains('$sql .= $this->prepared_term_resolution_suffix();', $resolution, 'the outer resolver suffix should be appended without copying the complete relation');
    assert_true(!str_contains($resolution, '$this->prepared_term_resolution_sql('), 'the SQLite renderer must not pass its complete relation through the wrapping renderer');
    assert_contains('yield [', $fakeDictionary, 'the fake dictionary decoder should yield one bounded row at a time');
    assert_true(!str_contains($fakeDictionary, "explode('),('") && !str_contains($fakeDictionary, '$rows[]'), 'the fake dictionary decoder must not split or retain the complete VALUES clause');
    assert_contains('yield [', $fakeResolver, 'the fake resolver decoder should yield one bounded identity at a time');
    assert_true(!str_contains($fakeResolver, '$identities[]') && !str_contains($fakeResolver, 'foreach (explode("\\n", $sql)'), 'the fake resolver decoder must not retain all decoded identities or split the complete statement into lines');
});

test_case('SQLite maximum-width writer survives retained suite state under 128 MiB', function (): void {
    $runner = dirname(__DIR__) . '/run.php';
    $code = 'register_shutdown_function(static function (): void {'
        . 'fwrite(STDERR, "WP_FTS_SQLITE_COMPOSED_PEAK=" . memory_get_peak_usage(true) . "\\n");'
        . '});'
        . '$retainedSuiteState = str_repeat("r", 60 * 1024 * 1024);'
        . 'putenv("WP_FTS_MIN_CHECKS=1");'
        . 'putenv("WP_FTS_FAIL_ON_PENDING=1");'
        . 'putenv("WP_FTS_TEST_FILTER=SQLite largest maximum-width transport boundary uses one dictionary write and one resolver");'
        . 'require ' . var_export($runner, true) . ';';
    $result = test_run_subprocess([
        PHP_BINARY,
        '-n',
        '-d',
        'memory_limit=128M',
        '-r',
        $code,
    ], dirname(__DIR__, 2));

    assert_same(0, $result['exit'], "the maximum-width SQLite writer should compose with 60 MiB of retained suite state under 128 MiB\n{$result['stderr']}");
    assert_contains('[PASS] SQLite largest maximum-width transport boundary uses one dictionary write and one resolver', $result['stdout'], 'the retained-state subprocess should execute the real maximum-width writer boundary');
    preg_match('/WP_FTS_SQLITE_COMPOSED_PEAK=([0-9]+)/', $result['stderr'], $peakMatch);
    $peakBytes = isset($peakMatch[1]) ? (int) $peakMatch[1] : PHP_INT_MAX;
    assert_true($peakBytes <= 128 * 1024 * 1024, 'the retained-state maximum-width writer should publish an allocated peak within 128 MiB');
});

test_case('SQLite aggregate transport splits once and preflights 100 documents linearly under 128 MiB', function (): void {
    [$wpdb, $storage] = wp_fts_sqlite_transport_storage();
    $languageA = str_repeat('a', 32);
    $languageB = str_repeat('b', 32);
    // Two 3,600-row documents remain individually safe but their 7,200
    // maximum-width identities cross the measured SQLite resolver boundary.
    $perDocument = 3600;
    $documents = [
        wp_fts_sqlite_transport_document(9301, $perDocument, $languageA),
        wp_fts_sqlite_transport_document(9302, $perDocument, $languageB),
    ];

    $partition = $storage->partition_prepared_documents($documents);
    assert_same([9301], array_column($partition['documents'], 'post_id'), 'SQLite partitioning should retain the exact transport-safe prefix');
    assert_same([9302], $partition['deferred_post_ids'], 'SQLite partitioning should defer the complete suffix for the next bounded transaction');
    assert_same([], $partition['rejections'], 'an individually valid suffix should be deferred, not mislabeled poison');
    assert_same([], $wpdb->queries, 'aggregate SQLite transport partitioning must precede frontier SQL');

    $split = null;
    try {
        $storage->replace_prepared_documents($documents);
    } catch (Throwable $error) {
        $split = $error;
    }
    assert_true($split instanceof WP_FTS_Prepared_Batch_Split_Required, 'the direct SQLite writer should return the existing typed split signal');
    assert_same(1, $split instanceof WP_FTS_Prepared_Batch_Split_Required ? $split->split_after_documents : null, 'the SQLite split should name the exact accepted prefix length');
    assert_same('sqlite_transport', $split instanceof WP_FTS_Prepared_Batch_Split_Required ? $split->limit_kind : null, 'the split should identify the SQLite transport frontier');
    assert_same([], $wpdb->queries, 'SQLite aggregate split must precede frontier SQL and BEGIN');

    if (!function_exists('proc_open')) {
        throw new WP_FTS_TestPending('proc_open is unavailable for the 128 MiB SQLite transport fixture.');
    }
    $fixture = dirname(__DIR__) . '/fixtures/sqlite-writer-transport-containment.php';
    $process = proc_open(
        [PHP_BINARY, '-d', 'memory_limit=128M', $fixture],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        dirname(__DIR__, 2)
    );
    assert_true(is_resource($process), 'the 128 MiB SQLite transport fixture should start');
    fclose($pipes[0]);
    $stdout = (string) stream_get_contents($pipes[1]);
    $stderr = (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exit = proc_close($process);
    assert_same(0, $exit, "100-document SQLite transport preflight should finish under 128 MiB\n{$stderr}");
    $evidence = json_decode(trim($stdout), true);
    assert_true(is_array($evidence), 'the SQLite transport fixture should publish structured evidence');
    assert_same(100, $evidence['input_documents'] ?? null, 'the containment fixture should cover the maximum 100-document writer batch');
    assert_same(8192, $evidence['input_identities'] ?? null, 'the containment fixture should cover the maximum aggregate identity count');
    assert_true((int) ($evidence['identity_visits'] ?? PHP_INT_MAX) <= 8192, 'the cumulative preflight must visit no identity more than the bounded input once');
    assert_true((int) ($evidence['peak_bytes'] ?? PHP_INT_MAX) <= 128 * 1024 * 1024, 'the complete hostile transport fixture should stay within 128 MiB');
    assert_true((float) ($evidence['elapsed_seconds'] ?? INF) < 2.0, 'the one-pass 8,192-identity preflight should complete without prefix-rebuild CPU growth');
});
