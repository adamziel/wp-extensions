<?php
declare(strict_types=1);

/**
 * Disposable real WordPress/MySQL production-path proof.
 *
 * Direct PHP execution is the public entry point. It requires an explicit
 * disposable-site opt-in, verifies WordPress through WP-CLI, activates this
 * plugin, then re-enters through `wp eval` + `require` so the proof runs inside a real
 * WordPress bootstrap with a real `$wpdb` MySQL/MariaDB connection.
 */

const WP_FTS_MYSQL_PROOF_INSIDE_ENV = 'WP_FTS_REAL_MYSQL_PROOF_INSIDE';
const WP_FTS_MYSQL_PROOF_ALLOW_ENV = 'WP_FTS_MYSQL_PROOF_ALLOW_DISPOSABLE';
const WP_FTS_MYSQL_PROOF_PLUGIN_SLUG = 'indexer';

try {
    exit(wp_fts_mysql_proof_main());
} catch (Throwable $e) {
    fwrite(STDERR, "FAIL: {$e->getMessage()}\n");
    exit(1);
}

function wp_fts_mysql_proof_main(): int
{
    if (getenv(WP_FTS_MYSQL_PROOF_INSIDE_ENV) === '1') {
        wp_fts_mysql_proof_run_inside_wordpress();
        return 0;
    }

    return wp_fts_mysql_proof_run_from_shell();
}

function wp_fts_mysql_proof_run_from_shell(): int
{
    if (!function_exists('proc_open')) {
        return wp_fts_mysql_proof_skip('proc_open() is unavailable; cannot launch WP-CLI.');
    }

    $wpPath = trim((string) getenv('WP_FTS_WP_PATH'));
    if ($wpPath === '') {
        return wp_fts_mysql_proof_skip('Set WP_FTS_WP_PATH to an installed disposable WordPress root backed by MySQL/MariaDB.');
    }

    if (!is_dir($wpPath)) {
        return wp_fts_mysql_proof_skip("WP_FTS_WP_PATH does not exist or is not a directory: {$wpPath}");
    }

    if (getenv(WP_FTS_MYSQL_PROOF_ALLOW_ENV) !== '1') {
        return wp_fts_mysql_proof_skip('Set WP_FTS_MYSQL_PROOF_ALLOW_DISPOSABLE=1 only for a disposable, non-production WordPress database.');
    }

    $baseCommand = wp_fts_mysql_proof_wp_cli_base_command();
    $installed = wp_fts_mysql_proof_process(array_merge($baseCommand, ['core', 'is-installed']));
    if ($installed['exit'] !== 0) {
        $detail = wp_fts_mysql_proof_sanitize_output(trim($installed['stderr'] . "\n" . $installed['stdout']));
        return wp_fts_mysql_proof_skip('WP-CLI is unavailable or WordPress is not installed at WP_FTS_WP_PATH.'
            . ($detail !== '' ? " Detail: {$detail}" : ''));
    }

    $activated = wp_fts_mysql_proof_process(array_merge($baseCommand, ['plugin', 'activate', WP_FTS_MYSQL_PROOF_PLUGIN_SLUG]));
    if ($activated['exit'] !== 0) {
        $detail = wp_fts_mysql_proof_sanitize_output(trim($activated['stderr'] . "\n" . $activated['stdout']));
        throw new RuntimeException("Plugin activation failed for " . WP_FTS_MYSQL_PROOF_PLUGIN_SLUG . ". Output: {$detail}");
    }

    $result = wp_fts_mysql_proof_process(
        array_merge($baseCommand, ['eval', 'require ' . var_export(__FILE__, true) . ';']),
        [WP_FTS_MYSQL_PROOF_INSIDE_ENV => '1']
    );

    echo $result['stdout'];
    if ($result['stderr'] !== '') {
        fwrite(STDERR, $result['stderr']);
    }

    return $result['exit'];
}

/** Run the relational storage proof only inside the explicitly disposable site. */
function wp_fts_mysql_proof_run_inside_wordpress(): void
{
    if (getenv(WP_FTS_MYSQL_PROOF_ALLOW_ENV) !== '1') {
        throw new RuntimeException('Disposable-site opt-in is missing inside WordPress.');
    }

    global $wpdb;

    if (!isset($wpdb) || !is_object($wpdb)) {
        throw new RuntimeException('WordPress loaded without a usable $wpdb object.');
    }

    if (!class_exists('WP_FTS_Plugin')) {
        require_once dirname(__DIR__, 2) . '/indexer.php';
    }

    $runtime = wp_fts_mysql_proof_mysql_runtime($wpdb);
    $prefix = (string) ($wpdb->prefix ?? '');
    wp_fts_mysql_proof_identifier($prefix . 'fts_terms');

    WP_FTS_Plugin::create_or_repair_schema();
    $tables = wp_fts_mysql_proof_tables($prefix);
    wp_fts_mysql_proof_assert_tables($wpdb, $tables);
    wp_fts_mysql_proof_assert_schema($wpdb, $tables);
    wp_fts_mysql_proof_assert_same(true, wp_fts_mysql_proof_storage_fixture(false)->verify_schema()['valid'] ?? null, 'production schema verifier should accept the exact current relations.');
    $tableEngines = wp_fts_mysql_proof_table_engines($wpdb, $tables);

    $token = wp_fts_mysql_proof_token();
    $postIds = [];
    $previousSettings = get_option(WP_FTS_Plugin::SETTINGS_OPTION, null);
    $restSettings = is_array($previousSettings) ? $previousSettings : [];
    $restSettings['rest_api_enabled'] = true;
    update_option(WP_FTS_Plugin::SETTINGS_OPTION, $restSettings, false);
    $evidence = [
        'status' => 'PASS',
        'proof_token' => $token,
        'source_sha' => trim((string) getenv('WP_FTS_SOURCE_SHA')) ?: 'unknown',
        'db_runtime' => $runtime,
        'table_engines' => $tableEngines,
        'timings' => [],
        'wpcli' => [],
        'rest' => [],
        'db_counts' => [],
        'language_counts' => [],
        'query_plans' => [],
        'memory_peak_bytes' => 0,
    ];

    try {
        $fixture = wp_fts_mysql_proof_seed_fixture($token);
        $postIds = $fixture['all_post_ids'];

        $start = microtime(true);
        $reindex = wp_fts_mysql_proof_run_wp_cli([
            'fts',
            'reindex',
            '--post_type=post',
            '--post_status=publish',
            '--format=json',
        ]);
        wp_fts_mysql_proof_assert_success($reindex, 'wp fts reindex should queue one scope against MySQL.');
        wp_fts_mysql_proof_assert_contains('"status":"queued"', $reindex['stdout'], 'reindex output should report queued background work.');
        $workerPasses = wp_fts_mysql_proof_drain_reindex_work();
        $evidence['timings']['wpcli_reindex_and_drain_elapsed_sec'] = wp_fts_mysql_proof_elapsed($start);
        $evidence['wpcli']['reindex'] = wp_fts_mysql_proof_command_summary($reindex);
        $evidence['wpcli']['reindex_worker_passes'] = $workerPasses;

        wp_fts_mysql_proof_make_posts_stale_hidden($wpdb, $fixture['hidden_ids']);

        $queueId = wp_fts_mysql_proof_insert_post(
            $token,
            'Queued proof',
            '<p lang="en">queuepath ' . $token . ' queued public content</p>'
        );
        $postIds[] = $queueId;
        (new WP_FTS_Index_Queue($wpdb))->enqueue_many([$queueId]);
        $batch = WP_FTS_Plugin::process_manual_index_batch(['batch_size' => 1]);
        $processed = (int) ($batch['queue_processed'] ?? 0);
        wp_fts_mysql_proof_assert_same(1, $processed, 'the manual batch should process the generated queued post.');
        $fixture['queue_id'] = $queueId;
        $evidence['queue'] = ['processed' => $processed, 'post_id' => $queueId];

        $evidence['wpcli'] += wp_fts_mysql_proof_wpcli_probes($token, $fixture);
        $evidence['rest'] = wp_fts_mysql_proof_rest_probes($token, $fixture);
        $evidence['db_counts'] = wp_fts_mysql_proof_db_counts($wpdb, $tables);
        wp_fts_mysql_proof_assert_same(0, $evidence['db_counts']['pending_work_rows'], 'The production proof should drain all post and scope work.');
        wp_fts_mysql_proof_assert_same(1, $evidence['db_counts']['search_epoch_rows'], 'The production proof should retain exactly one search-epoch sentinel.');
        $evidence['language_counts'] = wp_fts_mysql_proof_language_counts($wpdb, $tables);
        $evidence['query_plans'] = wp_fts_mysql_proof_query_plans($wpdb, $tables);
        $evidence['memory_peak_bytes'] = memory_get_peak_usage(true);
    } finally {
        wp_fts_mysql_proof_cleanup($postIds);
        if ($previousSettings === null) {
            delete_option(WP_FTS_Plugin::SETTINGS_OPTION);
        } else {
            update_option(WP_FTS_Plugin::SETTINGS_OPTION, $previousSettings, false);
        }
    }

    echo json_encode($evidence, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";
    echo "PASS: real MySQL-backed WordPress production proof completed for token {$token}\n";
}

/**
 * @return array{
 *   all_post_ids:int[],
 *   hidden_ids:int[],
 *   visible_id:int,
 *   password_id:int,
 *   polish_id:int,
 *   german_id:int,
 *   english_override_id:int,
 *   english_fallback_id:int,
 *   mixed_id:int
 * }
 */
function wp_fts_mysql_proof_seed_fixture(string $token): array
{
    if (!function_exists('wp_insert_post')) {
        throw new RuntimeException('wp_insert_post() is unavailable inside WordPress.');
    }

    $hiddenIds = [];
    for ($i = 1; $i <= 12; $i++) {
        $hiddenIds[] = wp_fts_mysql_proof_insert_post(
            $token,
            'Stale hidden ' . $i,
            '<p lang="en">shared ' . $token . '</p>'
        );
    }

    $visibleId = wp_fts_mysql_proof_insert_post(
        $token,
        'Visible English',
        '<p lang="en">shared ' . $token . ' visible english public result with extra body text</p>'
    );
    $passwordId = wp_fts_mysql_proof_insert_post(
        $token,
        'Password English',
        '<p lang="en">shared ' . $token . ' password protected result</p>',
        'publish',
        'wpfts-proof-password'
    );
    $polishId = wp_fts_mysql_proof_insert_post(
        $token,
        'Polish stemming',
        '<p>Wrocław Łódź kotami oraz jest ' . $token . '</p>'
    );
    $germanId = wp_fts_mysql_proof_insert_post(
        $token,
        'German detection',
        '<p>Führung Straße und ist ' . $token . '</p>'
    );
    $englishOverrideId = wp_fts_mysql_proof_insert_post(
        $token,
        'English override',
        '<p lang="en">oraz jest englishoverride ' . $token . '</p>'
    );
    $englishFallbackId = wp_fts_mysql_proof_insert_post(
        $token,
        'English fallback',
        '<p>Beyonce Beyoncé résumé cafe fallbackenglish ' . $token . '</p>'
    );
    $mixedId = wp_fts_mysql_proof_insert_post(
        $token,
        'Mixed language',
        '<p lang="pl">zamek kotami</p><p lang="en">castle visible</p>'
    );

    return [
        'all_post_ids' => array_merge(
            $hiddenIds,
            [$visibleId, $passwordId, $polishId, $germanId, $englishOverrideId, $englishFallbackId, $mixedId]
        ),
        'hidden_ids' => $hiddenIds,
        'visible_id' => $visibleId,
        'password_id' => $passwordId,
        'polish_id' => $polishId,
        'german_id' => $germanId,
        'english_override_id' => $englishOverrideId,
        'english_fallback_id' => $englishFallbackId,
        'mixed_id' => $mixedId,
    ];
}

function wp_fts_mysql_proof_insert_post(
    string $token,
    string $label,
    string $content,
    string $status = 'publish',
    string $password = ''
): int {
    $postId = wp_insert_post([
        'post_title' => "WP FTS proof {$label} {$token}",
        'post_content' => $content,
        'post_excerpt' => "Proof excerpt {$label} {$token}",
        'post_status' => $status,
        'post_type' => 'post',
        'post_password' => $password,
    ], true);

    if (function_exists('is_wp_error') && is_wp_error($postId)) {
        throw new RuntimeException('Could not create proof post: ' . $postId->get_error_message());
    }

    $postId = (int) $postId;
    wp_fts_mysql_proof_assert($postId > 0, 'wp_insert_post() should return a positive post id.');

    return $postId;
}

/**
 * @param int[] $postIds
 */
function wp_fts_mysql_proof_make_posts_stale_hidden(object $wpdb, array $postIds): void
{
    foreach ($postIds as $postId) {
        $updated = $wpdb->update($wpdb->posts, ['post_status' => 'private'], ['ID' => (int) $postId], ['%s'], ['%d']);
        if ($updated === false) {
            $error = trim((string) ($wpdb->last_error ?? ''));
            throw new RuntimeException('Could not make proof post stale-hidden.' . ($error !== '' ? " Database error: {$error}" : ''));
        }

        if (function_exists('clean_post_cache')) {
            clean_post_cache((int) $postId);
        }
    }
}

/**
 * @param array<string,mixed> $fixture
 * @return array<string,array<string,mixed>>
 */
function wp_fts_mysql_proof_wpcli_probes(string $token, array $fixture): array
{
    $probes = [];
    $cases = [
        'search_shared' => [
            ['fts', 'search', 'shared ' . $token, '--lang=en', '--mode=AND', '--limit=20'],
            (int) $fixture['visible_id'],
            'shared/token CLI search should include the visible English post.',
        ],
        'and_snippet' => [
            ['fts', 'search', 'shared visible', '--lang=en', '--mode=AND', '--limit=5', '--snippet'],
            (int) $fixture['visible_id'],
            'AND snippet CLI search should include the visible English post.',
        ],
        'polish_fallback' => [
            ['fts', 'search', 'kot', '--lang=pl', '--limit=5'],
            (int) $fixture['polish_id'],
            'Polish default stemming should match kotami for query kot.',
        ],
        'german_detection' => [
            ['fts', 'search', 'Führung', '--lang=de', '--limit=5'],
            (int) $fixture['german_id'],
            'German detection/indexing should match Führung.',
        ],
        'explicit_english_override' => [
            ['fts', 'search', 'oraz', '--lang=en', '--limit=5'],
            (int) $fixture['english_override_id'],
            'Explicit English lang markup should keep Polish-looking text in English.',
        ],
        'english_fallback' => [
            ['fts', 'search', 'fallbackenglish', '--lang=en-US', '--limit=5'],
            (int) $fixture['english_fallback_id'],
            'Conservative detection should leave accented English loanword text in the site English fallback partition.',
        ],
        'mixed_polish' => [
            ['fts', 'search', 'zamek', '--lang=pl', '--limit=5'],
            (int) $fixture['mixed_id'],
            'Mixed post Polish segment should be searchable in pl.',
        ],
        'mixed_english' => [
            ['fts', 'search', 'castle', '--lang=en', '--limit=5'],
            (int) $fixture['mixed_id'],
            'Mixed post English segment should be searchable in en.',
        ],
        'queued_post' => [
            ['fts', 'search', 'queuepath', '--lang=en', '--limit=5'],
            (int) $fixture['queue_id'],
            'the manually indexed post should be searchable through WP-CLI.',
        ],
    ];

    foreach ($cases as $name => [$command, $expectedId, $message]) {
        $result = wp_fts_mysql_proof_run_wp_cli($command);
        wp_fts_mysql_proof_assert_success($result, $message);
        wp_fts_mysql_proof_assert_output_has_id($result['stdout'], $expectedId, $message);
        $probes[$name] = wp_fts_mysql_proof_command_summary($result, $expectedId);
    }

    $invalid = wp_fts_mysql_proof_run_wp_cli(['fts', 'search', 'shared', '--lang=en', '--mode=XOR']);
    wp_fts_mysql_proof_assert($invalid['exit'] !== 0, 'Invalid CLI search mode should fail.');
    wp_fts_mysql_proof_assert_contains('OR or AND', $invalid['stdout'] . $invalid['stderr'], 'Invalid CLI search mode should explain accepted modes.');
    $probes['invalid_mode'] = wp_fts_mysql_proof_command_summary($invalid);

    return $probes;
}

/**
 * @param array<string,mixed> $fixture
 * @return array<string,mixed>
 */
function wp_fts_mysql_proof_rest_probes(string $token, array $fixture): array
{
    $base = wp_fts_mysql_proof_http_base();
    $query = 'shared ' . $token;
    $expectedVisible = (int) $fixture['visible_id'];

    $probes = [];
    $response = wp_fts_mysql_proof_rest_get($base, [
        'q' => $query,
        'lang' => 'en',
        'mode' => 'AND',
        'limit' => 1,
    ]);
    wp_fts_mysql_proof_assert_same(200, $response['status'], 'REST search should return HTTP 200.');
    $docId = (int) ($response['json']['results'][0]['doc_id'] ?? 0);
    wp_fts_mysql_proof_assert_same($expectedVisible, $docId, 'REST search should apply canonical visibility before LIMIT.');
    $probes['search'] = ['status' => $response['status'], 'doc_id' => $docId];

    $invalid = wp_fts_mysql_proof_rest_get($base, ['q' => 'shared', 'mode' => 'xor']);
    wp_fts_mysql_proof_assert_same(400, $invalid['status'], 'Invalid REST search mode should return HTTP 400.');
    wp_fts_mysql_proof_assert_same('wp_fts_invalid_mode', (string) ($invalid['json']['code'] ?? ''), 'Invalid REST mode should return wp_fts_invalid_mode.');
    $probes['invalid_mode'] = ['status' => $invalid['status'], 'code' => $invalid['json']['code'] ?? null];

    $start = microtime(true);
    $traffic = wp_fts_mysql_proof_rest_traffic($base, [
        'q' => $query,
        'lang' => 'en',
        'mode' => 'AND',
        'limit' => 3,
    ]);
    $traffic['elapsed_sec'] = wp_fts_mysql_proof_elapsed($start);
    $probes['traffic_shape'] = $traffic;

    return $probes;
}

/**
 * @return array<string,mixed>
 */
function wp_fts_mysql_proof_rest_traffic(string $base, array $params): array
{
    $url = $base . '/?' . http_build_query(array_merge(
        ['rest_route' => '/wp-fts/v1/search'],
        $params
    ), '', '&', PHP_QUERY_RFC3986);
    $requests = 40;
    $concurrency = 8;

    if (function_exists('curl_multi_init')) {
        $multi = curl_multi_init();
        if ($multi === false) {
            throw new RuntimeException('curl_multi_init() failed for REST traffic probe.');
        }

        $handles = [];
        for ($i = 0; $i < $requests; $i++) {
            $handle = curl_init($url);
            if ($handle === false) {
                throw new RuntimeException('curl_init() failed for REST traffic probe.');
            }
            curl_setopt_array($handle, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 15,
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_FAILONERROR => false,
            ]);
            $handles[] = $handle;
        }

        $next = 0;
        $active = 0;
        $completed = 0;
        $ok = 0;
        while ($completed < $requests) {
            while ($next < $requests && $active < $concurrency) {
                curl_multi_add_handle($multi, $handles[$next]);
                $next++;
                $active++;
            }

            do {
                $status = curl_multi_exec($multi, $running);
            } while ($status === CURLM_CALL_MULTI_PERFORM);
            if ($status !== CURLM_OK) {
                throw new RuntimeException('curl_multi_exec() failed for REST traffic probe.');
            }

            while ($info = curl_multi_info_read($multi)) {
                $handle = $info['handle'];
                $httpStatus = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
                $body = (string) curl_multi_getcontent($handle);
                if ($info['result'] === CURLE_OK && $httpStatus === 200 && $body !== '') {
                    $ok++;
                }
                curl_multi_remove_handle($multi, $handle);
                $completed++;
                $active--;
            }

            if ($running > 0) {
                curl_multi_select($multi, 1.0);
            }
        }

        foreach ($handles as $handle) {
            curl_close($handle);
        }
        curl_multi_close($multi);

        wp_fts_mysql_proof_assert_same($requests, $ok, 'All concurrent REST traffic probe requests should return HTTP 200.');

        return ['requests' => $requests, 'concurrency' => $concurrency, 'ok' => $ok, 'runner' => 'curl_multi'];
    }

    $ok = 0;
    for ($i = 0; $i < $requests; $i++) {
        $response = wp_fts_mysql_proof_http_get($url);
        if ($response['status'] === 200 && $response['body'] !== '') {
            $ok++;
        }
    }
    wp_fts_mysql_proof_assert_same($requests, $ok, 'All REST traffic probe requests should return HTTP 200.');

    return ['requests' => $requests, 'concurrency' => 1, 'ok' => $ok, 'runner' => 'php_stream_fallback'];
}

/**
 * @return array<string,int>
 */
function wp_fts_mysql_proof_db_counts(object $wpdb, array $tables): array
{
    $terms = wp_fts_mysql_proof_identifier($tables['terms']);
    $postings = wp_fts_mysql_proof_identifier($tables['postings']);
    $documents = wp_fts_mysql_proof_identifier($tables['documents']);
    $work = wp_fts_mysql_proof_identifier($tables['work']);

    $row = $wpdb->get_row($wpdb->prepare(
        "SELECT
  (SELECT COUNT(*) FROM `{$terms}`) AS terms,
  (SELECT COUNT(*) FROM `{$postings}`) AS postings,
  (SELECT COUNT(*) FROM `{$documents}`) AS documents,
  (SELECT COUNT(*) FROM `{$work}` WHERE kind IN ('post','scope')) AS pending_work_rows,
  (SELECT COUNT(*) FROM `{$work}` WHERE job_key = %s AND kind = 'meta' AND state = 'meta') AS search_epoch_rows",
        WP_FTS_Index_Queue::SEARCH_EPOCH_JOB_KEY
    ));
    if (!is_object($row)) {
        throw new RuntimeException('Could not read FTS table counts.');
    }

    return [
        'terms' => (int) $row->terms,
        'postings' => (int) $row->postings,
        'documents' => (int) $row->documents,
        'pending_work_rows' => (int) $row->pending_work_rows,
        'search_epoch_rows' => (int) $row->search_epoch_rows,
    ];
}

/**
 * @return array<int,array{lang:string,docs:int}>
 */
function wp_fts_mysql_proof_language_counts(object $wpdb, array $tables): array
{
    $documents = wp_fts_mysql_proof_identifier($tables['documents']);
    $rows = $wpdb->get_results(
        "SELECT primary_lang AS lang, COUNT(*) AS docs
FROM `{$documents}`
GROUP BY primary_lang
ORDER BY primary_lang ASC"
    );

    $counts = [];
    foreach ($rows ?: [] as $row) {
        $counts[] = [
            'lang' => (string) $row->lang,
            'docs' => (int) $row->docs,
        ];
    }

    return $counts;
}

/**
 * @return array<string,mixed>
 */
function wp_fts_mysql_proof_query_plans(object $wpdb, array $tables): array
{
    $terms = wp_fts_mysql_proof_identifier($tables['terms']);
    $postings = wp_fts_mysql_proof_identifier($tables['postings']);
    $documents = wp_fts_mysql_proof_identifier($tables['documents']);
    $work = wp_fts_mysql_proof_identifier($tables['work']);

    $termId = (int) $wpdb->get_var("SELECT term_id FROM `{$terms}` ORDER BY doc_freq DESC, term_id ASC LIMIT 1");
    wp_fts_mysql_proof_assert($termId > 0, 'At least one indexed term should exist before EXPLAIN probes.');

    return [
        'postings_by_term_id' => wp_fts_mysql_proof_explain_json($wpdb,
            "EXPLAIN FORMAT=JSON
SELECT term_id, post_id, impact
FROM `{$postings}`
WHERE term_id = {$termId}
ORDER BY post_id ASC
LIMIT 20"
        ),
        'documents_by_language' => wp_fts_mysql_proof_explain_json($wpdb,
            "EXPLAIN FORMAT=JSON
SELECT post_id, primary_lang, indexed_at
FROM `{$documents}`
WHERE primary_lang = 'en'
ORDER BY post_id ASC
LIMIT 20"
        ),
        'ready_work' => wp_fts_mysql_proof_explain_json($wpdb,
            "EXPLAIN FORMAT=JSON
SELECT job_key, post_id, generation
FROM `{$work}`
WHERE kind = 'post' AND state = 'ready' AND available_at <= 18446744073709551615
ORDER BY post_id, job_key
LIMIT 20"
        ),
    ];
}

function wp_fts_mysql_proof_explain_json(object $wpdb, string $sql): mixed
{
    $value = $wpdb->get_var($sql);
    if (!is_string($value) || trim($value) === '') {
        $error = trim((string) ($wpdb->last_error ?? ''));
        throw new RuntimeException('EXPLAIN FORMAT=JSON returned no plan.' . ($error !== '' ? " Database error: {$error}" : ''));
    }

    try {
        return json_decode($value, true, flags: JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        return $value;
    }
}

/**
 * @param int[] $postIds
 */
function wp_fts_mysql_proof_cleanup(array $postIds): void
{
    $postIds = array_values(array_unique(array_filter(array_map('intval', $postIds), static fn(int $id): bool => $id > 0)));
    foreach ($postIds as $postId) {
        if (function_exists('wp_delete_post')) {
            wp_delete_post($postId, true);
        }
    }

    if (function_exists('delete_option') && class_exists('WP_FTS_Plugin')) {
    }
    if (class_exists('WP_FTS_Plugin')) {
        $runWriter = new ReflectionMethod(WP_FTS_Plugin::class, 'run_index_writer_with_lock');
        $runWriter->setAccessible(true);
        $cleanup = $runWriter->invoke(
            null,
            'mysql-proof-cleanup',
            static function () use ($postIds): int {
                $workTable = wp_fts_mysql_proof_identifier($GLOBALS['wpdb']->prefix . 'fts_work');
                if ($GLOBALS['wpdb']->query("DELETE FROM {$workTable}") === false) {
                    throw new RuntimeException('Could not clear MySQL proof work rows.');
                }
                $storage = wp_fts_mysql_proof_storage_fixture(false);
                $storage->replace_prepared_documents([], $postIds);
                $storage->optimize();

                return count($postIds);
            },
            ['record_health' => false, 'record_skip' => false]
        );
        if (!$cleanup['acquired']) {
            throw new RuntimeException('Could not acquire the shared writer lease for MySQL proof cleanup.');
        }
    }
}

/**
 * @return array{server_version:string,server_comment:string}
 */
function wp_fts_mysql_proof_mysql_runtime(object $wpdb): array
{
    $row = $wpdb->get_row('SELECT VERSION() AS server_version, @@version_comment AS server_comment');
    if (!is_object($row)) {
        $error = trim((string) ($wpdb->last_error ?? ''));
        throw new RuntimeException('Database runtime is not MySQL/MariaDB or VERSION() probe failed.'
            . ($error !== '' ? " Database error: {$error}" : ''));
    }

    $version = (string) ($row->server_version ?? '');
    $comment = (string) ($row->server_comment ?? '');
    $combined = strtolower($version . ' ' . $comment);
    wp_fts_mysql_proof_assert(
        str_contains($combined, 'mysql') || str_contains($combined, 'mariadb'),
        "Database runtime must identify as MySQL or MariaDB. Got: {$version} {$comment}"
    );

    return ['server_version' => $version, 'server_comment' => $comment];
}

/**
 * @return array<string,string>
 */
function wp_fts_mysql_proof_tables(string $prefix): array
{
    return [
        'terms' => $prefix . 'fts_terms',
        'postings' => $prefix . 'fts_postings',
        'documents' => $prefix . 'fts_documents',
        'work' => $prefix . 'fts_work',
    ];
}

/**
 * @param array<string,string> $tables
 */
function wp_fts_mysql_proof_assert_tables(object $wpdb, array $tables): void
{
    foreach ($tables as $table) {
        wp_fts_mysql_proof_identifier($table);
        $found = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
        wp_fts_mysql_proof_assert_same($table, (string) $found, "table {$table} should exist.");
    }
}

/** @param array<string,string> $tables */
function wp_fts_mysql_proof_assert_schema(object $wpdb, array $tables): void
{
    $contracts = [
        $tables['terms'] => [
            'columns' => ['term_id', 'lang', 'kind', 'term', 'doc_freq'],
            'indexes' => [
                'PRIMARY' => ['unique' => true, 'columns' => ['term_id']],
                'empty_terms' => ['unique' => false, 'columns' => ['doc_freq']],
                'term_identity' => ['unique' => true, 'columns' => ['lang', 'kind', 'term']],
            ],
        ],
        $tables['postings'] => [
            'columns' => ['term_id', 'post_id', 'impact'],
            'indexes' => [
                'PRIMARY' => ['unique' => true, 'columns' => ['term_id', 'post_id']],
                'post_term' => ['unique' => false, 'columns' => ['post_id', 'term_id']],
            ],
        ],
        $tables['documents'] => [
            'columns' => ['post_id', 'primary_lang', 'content_hash', 'snippet_text', 'indexed_at'],
            'indexes' => [
                'PRIMARY' => ['unique' => true, 'columns' => ['post_id']],
            ],
        ],
        $tables['work'] => [
            'columns' => ['job_key', 'kind', 'post_id', 'generation', 'state', 'available_at', 'attempts', 'claim_token', 'claimed_generation', 'claim_expires_at', 'cursor_post_id', 'scope_coverage', 'scope_incarnation', 'scope_subject_type', 'scope_subject_id', 'payload', 'last_error_code', 'last_error_at'],
            'indexes' => [
                'PRIMARY' => ['unique' => true, 'columns' => ['job_key']],
                'claim_token' => ['unique' => false, 'columns' => ['claim_token', 'post_id']],
                'dirty' => ['unique' => false, 'columns' => ['post_id', 'kind']],
                'kind_job' => ['unique' => false, 'columns' => ['kind', 'job_key']],
                'ready' => ['unique' => false, 'columns' => ['kind', 'state', 'available_at', 'post_id', 'job_key']],
                'recoverable' => ['unique' => false, 'columns' => ['kind', 'state', 'claim_expires_at', 'available_at', 'post_id', 'job_key']],
                'scope_subject' => ['unique' => false, 'columns' => ['kind', 'scope_coverage', 'scope_subject_type', 'scope_subject_id']],
            ],
        ],
    ];

    foreach ($contracts as $table => $contract) {
        $identifier = wp_fts_mysql_proof_identifier($table);
        $columnRows = $wpdb->get_results("SHOW COLUMNS FROM `{$identifier}`");
        $actualColumns = array_map(static fn(object $row): string => (string) $row->Field, is_array($columnRows) ? $columnRows : []);
        wp_fts_mysql_proof_assert_same($contract['columns'], $actualColumns, "table {$table} should have only the exact current columns.");

        $indexRows = $wpdb->get_results("SHOW INDEX FROM `{$identifier}`");
        $actualIndexes = [];
        foreach (is_array($indexRows) ? $indexRows : [] as $row) {
            $name = (string) $row->Key_name;
            $actualIndexes[$name] ??= ['unique' => (int) $row->Non_unique === 0, 'columns' => []];
            $actualIndexes[$name]['columns'][(int) $row->Seq_in_index] = (string) $row->Column_name;
        }
        foreach ($actualIndexes as &$index) {
            ksort($index['columns'], SORT_NUMERIC);
            $index['columns'] = array_values($index['columns']);
        }
        unset($index);
        ksort($actualIndexes, SORT_STRING);
        $expectedIndexes = $contract['indexes'];
        ksort($expectedIndexes, SORT_STRING);
        wp_fts_mysql_proof_assert_same($expectedIndexes, $actualIndexes, "table {$table} should have only the exact current indexes.");
    }
}

/**
 * @param array<string,string> $tables
 * @return array<string,string>
 */
function wp_fts_mysql_proof_table_engines(object $wpdb, array $tables): array
{
    $names = array_values($tables);
    $placeholders = implode(',', array_fill(0, count($names), '%s'));
    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT TABLE_NAME, ENGINE
FROM information_schema.TABLES
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME IN ({$placeholders})
ORDER BY TABLE_NAME ASC",
        ...$names
    ));

    $engines = [];
    foreach ($rows ?: [] as $row) {
        $table = (string) $row->TABLE_NAME;
        $engine = (string) $row->ENGINE;
        $engines[$table] = $engine;
        wp_fts_mysql_proof_assert(strtolower($engine) === 'innodb', "table {$table} should use InnoDB, got {$engine}.");
    }

    foreach ($names as $name) {
        wp_fts_mysql_proof_assert(isset($engines[$name]), "table {$name} should be present in information_schema.");
    }

    ksort($engines, SORT_STRING);

    return $engines;
}

/**
 * @param string[] $args
 * @return array{exit:int,stdout:string,stderr:string,command:string}
 */
function wp_fts_mysql_proof_run_wp_cli(array $args): array
{
    return wp_fts_mysql_proof_process(array_merge(wp_fts_mysql_proof_wp_cli_base_command(), $args));
}

/** @return array<int,array<string,mixed>> */
function wp_fts_mysql_proof_drain_reindex_work(): array
{
    $passes = [];
    for ($pass = 1; $pass <= 50; $pass++) {
        $result = wp_fts_mysql_proof_run_wp_cli([
            'fts',
            'process-batch',
            '--batch_size=100',
            '--time_budget=20',
            '--format=json',
        ]);
        wp_fts_mysql_proof_assert_success($result, "wp fts process-batch pass {$pass} should complete against MySQL.");
        $payload = json_decode(trim($result['stdout']), true, 512, JSON_THROW_ON_ERROR);
        wp_fts_mysql_proof_assert(is_array($payload), "wp fts process-batch pass {$pass} should return a JSON object.");
        $passes[] = wp_fts_mysql_proof_command_summary($result) + [
            'pass' => $pass,
            'indexed' => max(0, (int) ($payload['indexed'] ?? 0)),
            'has_more' => !empty($payload['has_more']),
        ];
        if (empty($payload['has_more'])) {
            return $passes;
        }
    }

    throw new RuntimeException('Reindex work remained after 50 explicit bounded WP-CLI worker passes.');
}

function wp_fts_mysql_proof_wp_cli_base_command(): array
{
    $wpCli = trim((string) (getenv('WP_FTS_WP_CLI') ?: 'wp'));
    $wpPath = trim((string) getenv('WP_FTS_WP_PATH'));
    $command = [$wpCli, '--path=' . $wpPath];
    $url = trim((string) getenv('WP_FTS_WP_URL'));
    if ($url !== '') {
        $command[] = '--url=' . $url;
    }

    return $command;
}

/**
 * @param string[] $command
 * @param array<string,string> $env
 * @return array{exit:int,stdout:string,stderr:string,command:string}
 */
function wp_fts_mysql_proof_process(array $command, array $env = []): array
{
    if (!function_exists('proc_open')) {
        throw new RuntimeException('proc_open() is required to launch WP-CLI.');
    }

    $baseEnv = getenv();
    if (!is_array($baseEnv)) {
        $baseEnv = [];
    }

    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $process = @proc_open($command, $descriptors, $pipes, dirname(__DIR__, 2), array_merge($baseEnv, $env));
    $commandString = wp_fts_mysql_proof_command_string($command);
    if (!is_resource($process)) {
        return [
            'exit' => 127,
            'stdout' => '',
            'stderr' => 'Could not start process: ' . $commandString,
            'command' => $commandString,
        ];
    }

    fclose($pipes[0]);
    $stdout = (string) stream_get_contents($pipes[1]);
    $stderr = (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exit = proc_close($process);

    return [
        'exit' => is_int($exit) ? $exit : 1,
        'stdout' => $stdout,
        'stderr' => $stderr,
        'command' => $commandString,
    ];
}

/**
 * @return array{status:int,body:string,json:mixed}
 */
function wp_fts_mysql_proof_rest_get(string $base, array $params): array
{
    $url = $base . '/?' . http_build_query(array_merge(
        ['rest_route' => '/wp-fts/v1/search'],
        $params
    ), '', '&', PHP_QUERY_RFC3986);
    $response = wp_fts_mysql_proof_http_get($url);
    $json = null;
    if ($response['body'] !== '') {
        try {
            $json = json_decode($response['body'], true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new RuntimeException('REST response was not valid JSON: ' . $e->getMessage());
        }
    }

    return ['status' => $response['status'], 'body' => $response['body'], 'json' => $json];
}

/**
 * @return array{status:int,body:string}
 */
function wp_fts_mysql_proof_http_get(string $url): array
{
    wp_fts_mysql_proof_assert_local_url($url);
    $context = stream_context_create([
        'http' => [
            'method' => 'GET',
            'timeout' => 15,
            'ignore_errors' => true,
        ],
    ]);

    $body = @file_get_contents($url, false, $context);
    $headers = isset($http_response_header) && is_array($http_response_header) ? $http_response_header : [];
    $status = 0;
    foreach ($headers as $header) {
        if (preg_match('/^HTTP\/\S+\s+(\d{3})\b/', $header, $match) === 1) {
            $status = (int) $match[1];
        }
    }

    return ['status' => $status, 'body' => is_string($body) ? $body : ''];
}

function wp_fts_mysql_proof_http_base(): string
{
    $base = trim((string) (getenv('WP_FTS_PROOF_HTTP_BASE') ?: getenv('WP_FTS_WP_URL')));
    if ($base === '' && function_exists('get_option')) {
        $siteUrl = get_option('siteurl');
        $base = is_string($siteUrl) ? $siteUrl : '';
    }

    if ($base === '') {
        throw new RuntimeException('Set WP_FTS_PROOF_HTTP_BASE or WP_FTS_WP_URL for REST proof probes.');
    }

    $base = rtrim($base, '/');
    wp_fts_mysql_proof_assert_local_url($base);

    return $base;
}

function wp_fts_mysql_proof_assert_local_url(string $url): void
{
    $host = parse_url($url, PHP_URL_HOST);
    $allowed = ['127.0.0.1', 'localhost', 'wordpress', 'host.docker.internal'];
    if (!is_string($host) || !in_array(strtolower($host), $allowed, true)) {
        throw new RuntimeException("REST proof URL must target a local disposable host, got {$url}.");
    }
}

function wp_fts_mysql_proof_command_summary(array $result, ?int $expectedId = null): array
{
    return [
        'exit' => (int) $result['exit'],
        'expected_doc_id' => $expectedId,
        'stdout_excerpt' => wp_fts_mysql_proof_excerpt($result['stdout']),
        'stderr_excerpt' => wp_fts_mysql_proof_excerpt($result['stderr']),
    ];
}

function wp_fts_mysql_proof_excerpt(string $text): string
{
    $text = wp_fts_mysql_proof_sanitize_output(trim(preg_replace('/\s+/', ' ', $text) ?? $text));

    return strlen($text) > 240 ? substr($text, 0, 237) . '...' : $text;
}

function wp_fts_mysql_proof_sanitize_output(string $text): string
{
    $text = preg_replace('/(password|passwd|pwd)(=|\s+)[^\s]+/i', '$1$2[redacted]', $text) ?? $text;
    $text = preg_replace('/(DB_PASSWORD|WORDPRESS_DB_PASSWORD|MARIADB_PASSWORD|MARIADB_ROOT_PASSWORD)=\S+/i', '$1=[redacted]', $text) ?? $text;

    return $text;
}

function wp_fts_mysql_proof_assert_success(array $result, string $message): void
{
    wp_fts_mysql_proof_assert(
        (int) $result['exit'] === 0,
        $message . ' Output: ' . wp_fts_mysql_proof_sanitize_output(trim($result['stdout'] . "\n" . $result['stderr']))
    );
}

function wp_fts_mysql_proof_assert_output_has_id(string $output, int $docId, string $message): void
{
    wp_fts_mysql_proof_assert(
        preg_match('/(^|\D)' . preg_quote((string) $docId, '/') . '(\D|$)/', $output) === 1,
        $message . ' Output: ' . wp_fts_mysql_proof_excerpt($output)
    );
}

function wp_fts_mysql_proof_identifier(string $identifier): string
{
    if (preg_match('/^[A-Za-z0-9_]+$/', $identifier) !== 1) {
        throw new InvalidArgumentException("Unsafe generated identifier: {$identifier}");
    }

    return $identifier;
}

function wp_fts_mysql_proof_token(): string
{
    return 'wpfts' . substr(hash('sha256', getmypid() . ':' . microtime(true) . ':' . random_int(1, PHP_INT_MAX)), 0, 10);
}

function wp_fts_mysql_proof_elapsed(float $start): float
{
    return round(microtime(true) - $start, 4);
}

function wp_fts_mysql_proof_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function wp_fts_mysql_proof_assert_same(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException($message . "\nExpected: " . var_export($expected, true) . "\nActual: " . var_export($actual, true));
    }
}

function wp_fts_mysql_proof_assert_contains(string $needle, string $haystack, string $message): void
{
    if (!str_contains($haystack, $needle)) {
        throw new RuntimeException($message . "\nMissing: " . var_export($needle, true) . "\nIn: " . wp_fts_mysql_proof_sanitize_output($haystack));
    }
}

function wp_fts_mysql_proof_skip(string $reason): int
{
    echo "SKIP: {$reason}\n";

    return 0;
}

/**
 * @param string[] $command
 */
function wp_fts_mysql_proof_command_string(array $command): string
{
    return implode(' ', array_map(static fn(string $arg): string => escapeshellarg($arg), $command));
}

/** Reach the private production storage factory only from this fixture. */
function wp_fts_mysql_proof_storage_fixture(bool $ensureSchema = false): WP_FTS_Relational_Storage
{
    $method = new ReflectionMethod(WP_FTS_Plugin::class, 'storage');
    $method->setAccessible(true);

    return $method->invoke(null, $ensureSchema);
}
