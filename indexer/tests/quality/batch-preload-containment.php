<?php
declare(strict_types=1);

test_case('batch source preload has hard aggregate and dependency envelopes', function (): void {
    $plugin = new ReflectionClass(WP_FTS_Plugin::class);
    $limits = [
        'MAX_INDEX_BATCH_SOURCE_BYTES' => 8 * 1024 * 1024,
        'MAX_INDEX_BATCH_CUSTOM_FIELD_KEY_BYTES' => 256 * 1024,
        'MAX_INDEX_DEPENDENCY_ROWS_PER_DOCUMENT' => 512,
        'MAX_INDEX_BATCH_DEPENDENCY_ROWS' => 2048,
        'MAX_INDEX_BATCH_SELECTED_DEPENDENCIES' => 512,
        'MAX_INDEX_DEPENDENCY_VALUE_BYTES' => 256 * 1024,
        'MAX_INDEX_DEPENDENCY_SQL_BYTES' => 32 * 1024,
        'MAX_INDEX_DEPENDENCY_SQL_SCAFFOLD_BYTES' => 8 * 1024,
        'MAX_INDEX_DEPENDENCY_QUERY_BRANCHES' => 5,
        'MAX_INDEX_DEPENDENCY_VALUE_QUERY_BRANCHES' => 40,
    ];
    foreach ($limits as $name => $expected) {
        assert_same($expected, $plugin->getReflectionConstant($name)?->getValue(), "{$name} should remain a non-configurable low-host safety bound");
    }

    $source = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Plugin.php');
    assert_true(!str_contains($source, 'SELECT p.*, d.content_hash AS fts_existing_hash'), 'the worker must not buffer every wp_posts column');
    foreach ([
        'An FTS queue claim is missing its bounded source measurement.',
        'CASE WHEN {$source_bytes_sql} <= requested.source_budget THEN p.post_content ELSE',
        'load bounded FTS taxonomy and metadata rows',
        'load bounded FTS taxonomy and metadata values',
        'WHERE pm.post_id IN (',
        'WHERE tr.object_id IN (',
        ' FORCE INDEX (post_id)',
        ' FORCE INDEX (PRIMARY)',
        'CASE WHEN pm.meta_key IN (',
        'tt.term_taxonomy_id AS source_id',
        'pm.meta_id AS source_id',
        'bounded_terms',
        'bounded_meta',
        'prepared_dependency_sql_bytes',
        'dependency_value_bucket',
        "self::dependency_text_projection('tt.taxonomy', \$is_sqlite, 255)",
        "self::dependency_text_projection('pm.meta_key', \$is_sqlite, 255)",
        'CAST({$expression} AS CHAR({$max_chars}) CHARACTER SET utf8mb4) COLLATE utf8mb4_bin',
        'WHERE pm.meta_id IN (',
        'incomplete_post_ids',
        'is_selected',
        'fts_index_deferred',
        'fts_index_rejection',
        '$posts[$post_id]->fts_language_override = is_scalar($value)',
    ] as $required) {
        assert_contains($required, $source, "bounded preload should retain {$required}");
    }
    assert_true(!str_contains($source, 'measure FTS source posts'), 'source preload must use the measurements returned by claim_batch()');
    $loadPosts = $plugin->getMethod('load_posts_for_indexing');
    assert_same(2, $loadPosts->getNumberOfRequiredParameters(), 'source measurements must be required at the preload boundary');
    $measurementStart = strpos($source, 'private static function load_bounded_index_dependencies(');
    $valueStart = strpos($source, 'private static function load_bounded_index_dependency_values(');
    assert_true($measurementStart !== false && $valueStart !== false && $valueStart > $measurementStart, 'measurement and value loaders should remain separate ordered phases');
    $measurementSource = substr($source, (int) $measurementStart, (int) $valueStart - (int) $measurementStart);
    assert_contains('OCTET_LENGTH(tt.description) <= 4096', $source, 'the folded Polylang assignment must have a fixed value cap');
    assert_contains('OCTET_LENGTH(wpml_translation.language_code) <= 64', $source, 'the folded WPML assignment must have a fixed value cap');
    assert_true(!str_contains($measurementSource, 'LEFT(CAST(t.name') && !str_contains($measurementSource, 'LEFT(CAST(pm.meta_value'), 'the measurement phase should not materialize taxonomy or metadata LOB prefixes');
    record_check('batch preload containment source contract', 32);
});

test_case('100 authoritative post preparations reopen no WordPress dependency source', function (): void {
    if (!function_exists('proc_open')) {
        throw new WP_FTS_TestPending('proc_open is unavailable for the hostile authoritative-preparation subprocess');
    }
    $fixture = dirname(__DIR__) . '/fixtures/authoritative-post-preparation-containment.php';
    $process = proc_open(
        [PHP_BINARY, $fixture],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        dirname(__DIR__, 2)
    );
    assert_true(is_resource($process), 'the authoritative-preparation fixture should start');
    fclose($pipes[0]);
    $stdout = (string) stream_get_contents($pipes[1]);
    $stderr = (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exit = proc_close($process);
    assert_same(0, $exit, "100 authoritative preparations should finish without a dependency callback\n{$stderr}");
    $result = json_decode(trim($stdout), true);
    assert_true(is_array($result), 'the authoritative-preparation fixture should return structured evidence');
    assert_same(100, $result['prepared'] ?? null, 'the hostile fixture should prepare every authoritative post');
    assert_same([
        'get_object_taxonomies' => 0,
        'wp_get_object_terms' => 0,
        'get_post_meta' => 0,
        'get_option' => 0,
    ], $result['dependency_calls'] ?? null, 'authoritative extraction must perform zero taxonomy, metadata, or option reads');
});

test_case('claim-specific custom-field keys preload 100 posts in a fixed query pair', function (): void {
    global $wpdb;

    $oldWpdb = $wpdb ?? null;
    $fake = new WP_FTS_Test_WPDB();
    $wpdb = $fake;
    wp_fts_test_reset_wordpress_fakes();
    $GLOBALS['wp_fts_test_options'][WP_FTS_PostContentExtractor::CUSTOM_FIELDS_OPTION] = ['global_only'];
    $posts = [];
    $indexOptionsByPost = [];
    foreach (range(7201, 7300) as $postId) {
        $key = 'claim_signal_' . $postId;
        $post = wp_fts_test_backfill_post($postId, 'post', 'publish', 'Claim-specific field ' . $postId);
        $post->terms = [];
        $post->custom_fields = [];
        $post->fts_language_override = '';
        $post->fts_integration_language = '';
        $post->fts_post_source_bytes = strlen($post->post_title)
            + strlen($post->post_content)
            + strlen($post->post_excerpt);
        $GLOBALS['wp_fts_test_posts'][$postId] = $post;
        $GLOBALS['wp_fts_test_post_meta'][$postId][$key] = ['claim-value-' . $postId];
        $GLOBALS['wp_fts_test_post_meta'][$postId]['global_only'] = ['wrong-global-value'];
        $posts[$postId] = $post;
        $indexOptionsByPost[$postId] = ['custom_field_keys' => [$key]];
    }

    try {
        $preload = new ReflectionMethod(WP_FTS_Plugin::class, 'preload_index_dependencies');
        $arguments = [&$posts, $indexOptionsByPost];
        $preload->invokeArgs(null, $arguments);
    } finally {
        $wpdb = $oldWpdb;
    }

    assert_same(2, $fake->num_queries, '100 distinct claim-specific keys should use one measurement and one value query');
    assert_same(100, count($fake->dependencyValueSourceIdsRequested), 'the value query should hydrate exactly one claim-selected identity per post');
    foreach ($posts as $postId => $post) {
        $key = 'claim_signal_' . $postId;
        assert_same(['claim-value-' . $postId], $post->custom_fields[$key] ?? null, 'each post should hydrate its own claim-selected key');
        assert_true(!array_key_exists('global_only', $post->custom_fields), 'an explicit claim key must override the global custom-field selection');
    }
});

test_case('preloaded language absence remains authoritative through analyzer resolution', function (): void {
    wp_fts_test_reset_wordpress_fakes();
    $postId = 7299;
    $GLOBALS['wp_fts_test_post_meta'][$postId][WP_FTS_Plugin::LANGUAGE_META_KEY] = ['pl'];
    $preloadedPost = wp_fts_test_backfill_post($postId, 'post', 'publish', 'Authoritative language snapshot');
    $preloadedPost->fts_language_override = '';
    $preloadedPost->fts_integration_language = '';

    $options = WP_FTS_Plugin::prepare_post_index_options($preloadedPost);
    assert_true(!isset($options['document_lang']), 'an authoritative empty batch language snapshot should preserve automatic analysis');
    assert_true(!isset($options['document_language_resolver']), 'the runtime analyzer must not install a provider-backed document resolver');
    assert_true(!isset($options['query_language_resolver']), 'the runtime analyzer must not install a provider-backed query resolver');

    $missingIntegrationMarker = WP_FTS_Plugin::prepare_post_index_options((object) [
        'ID' => $postId,
        'fts_language_override' => 'fr',
    ]);
    assert_true(!isset($missingIntegrationMarker['document_lang']), 'one preload marker must never be mistaken for an authoritative language snapshot');
});

test_case('100-post preload never fans out through per-post multilingual APIs', function (): void {
    global $wpdb;

    $oldWpdb = $wpdb ?? null;
    $fake = new WP_FTS_Test_WPDB();
    $wpdb = $fake;
    wp_fts_test_reset_wordpress_fakes();
    wp_fts_ldgf_reset_multilingual_globals();
    $GLOBALS['wp_fts_ldgf_polylang_query_per_call'] = true;
    $GLOBALS['polylang'] = (object) [];
    $GLOBALS['sitepress'] = (object) [];
    $GLOBALS['wp_fts_ldgf_polylang_post_languages'][8703] = 'pl_PL';
    $GLOBALS['wp_fts_ldgf_wpml_post_languages'][8704] = 'de_DE';
    $wpmlCalls = 0;
    $GLOBALS['wp_fts_test_filters']['wpml_post_language_details'] = static function (
        mixed $value,
        int $postId
    ) use ($fake, &$wpmlCalls): mixed {
        $wpmlCalls++;
        $fake->get_var('SELECT language_code FROM wp_icl_translations_per_post_stub');

        return $postId === 8802 ? ['locale' => 'de_DE'] : $value;
    };

    $posts = [];
    foreach (range(8701, 8800) as $postId) {
        $post = wp_fts_test_backfill_post($postId, 'post', 'publish', 'Bounded language ' . $postId);
        $post->terms = [];
        $post->custom_fields = [];
        $post->fts_language_override = '';
        $post->fts_post_source_bytes = strlen($post->post_title)
            + strlen($post->post_content)
            + strlen($post->post_excerpt);
        $GLOBALS['wp_fts_test_posts'][$postId] = $post;
        $posts[$postId] = $post;
    }

    try {
        $preload = new ReflectionMethod(WP_FTS_Plugin::class, 'preload_index_dependencies');
        $arguments = [&$posts];
        $preload->invokeArgs(null, $arguments);

        $queriesAfterPreload = $fake->num_queries;
        assert_same(
            100,
            count(array_filter($posts, static fn(object $post): bool => property_exists($post, 'fts_language_override'))),
            'every post should retain the authoritative language snapshot marker'
        );
        foreach ($posts as $post) {
            WP_FTS_Plugin::prepare_post_index_options($post);
        }

        assert_same(1, $queriesAfterPreload, '100 posts and both language integrations should share one bounded dependency read');
        assert_same($queriesAfterPreload, $fake->num_queries, 'final per-document preparation should add no multilingual database calls after preload');
        assert_same(0, $GLOBALS['wp_fts_ldgf_polylang_post_language_calls'] ?? null, 'the worker must not invoke the Polylang per-post API');
        assert_same(0, $wpmlCalls, 'the worker must not invoke the WPML per-post filter');
        $languageStatements = array_values(array_filter(
            $fake->prepared,
            static fn(array $prepared): bool => str_contains((string) ($prepared['sql'] ?? ''), '/* wp_fts:polylang-languages */')
                && str_contains((string) ($prepared['sql'] ?? ''), '/* wp_fts:wpml-languages */')
        ));
        assert_same(1, count($languageStatements), 'both active multilingual integrations should contribute arms to the existing dependency statement');
        foreach ($languageStatements as $prepared) {
            assert_true(strlen((string) $prepared['sql']) < 32768, 'a 100-post multilingual batch statement must remain below 32 KiB');
            assert_true(count($prepared['args'] ?? []) <= 505, 'the combined statement may carry only five bounded copies of the claimed IDs and fixed discriminators');
        }

        assert_same('pl-PL', WP_FTS_Plugin::prepare_post_index_options($posts[8703])['document_lang'] ?? null, 'the batch Polylang snapshot should preserve the assigned locale');
        assert_same('de-DE', WP_FTS_Plugin::prepare_post_index_options($posts[8704])['document_lang'] ?? null, 'the batch WPML snapshot should preserve the assigned language');

        $posts[8701]->fts_language_override = 'fr';
        $ownOverride = WP_FTS_Plugin::prepare_post_index_options($posts[8701]);
        assert_same('fr', $ownOverride['document_lang'] ?? null, 'the set-oriented plugin-owned language override should remain authoritative');
        $explicit = WP_FTS_Plugin::prepare_post_index_options($posts[8702], ['document_lang' => 'es']);
        assert_same('es', $explicit['document_lang'] ?? null, 'an explicit batch language should remain authoritative');
        assert_same($queriesAfterPreload, $fake->num_queries, 'own and explicit batch languages should require no third-party queries');

        $GLOBALS['wp_fts_ldgf_polylang_post_languages'][8801] = 'pl_PL';
        $directPolylang = WP_FTS_Plugin::prepare_post_index_options((object) ['ID' => 8801]);
        $directWpml = WP_FTS_Plugin::prepare_post_index_options((object) ['ID' => 8802]);
        assert_true(!isset($directPolylang['document_lang']) && !isset($directWpml['document_lang']), 'posts without both preload markers must fall through to explicit language, detection, or site default');
        assert_same(0, $GLOBALS['wp_fts_ldgf_polylang_post_language_calls'] ?? null, 'missing preload markers must not invoke the Polylang per-post API');
        assert_same(0, $wpmlCalls, 'missing preload markers must not invoke the WPML per-post filter');
        assert_same($queriesAfterPreload, $fake->num_queries, 'missing preload markers must not add multilingual database calls');
    } finally {
        $GLOBALS['wp_fts_ldgf_polylang_query_per_call'] = false;
        unset($GLOBALS['polylang'], $GLOBALS['sitepress']);
        unset($GLOBALS['wp_fts_test_filters']['wpml_post_language_details']);
        $wpdb = $oldWpdb;
    }
});

test_case('dependency preload retains distinct bounded language-provider statement shapes', function (): void {
    global $wpdb;

    $oldWpdb = $wpdb ?? null;
    $cases = [
        'no provider' => ['polylang' => false, 'wpml' => false, 'branches' => 3, 'placeholders' => 4],
        'Polylang' => ['polylang' => true, 'wpml' => false, 'branches' => 4, 'placeholders' => 7],
        'WPML' => ['polylang' => false, 'wpml' => true, 'branches' => 4, 'placeholders' => 5],
        'Polylang and WPML' => ['polylang' => true, 'wpml' => true, 'branches' => 5, 'placeholders' => 8],
    ];

    try {
        foreach ($cases as $label => $case) {
            $fake = new WP_FTS_Test_WPDB();
            $wpdb = $fake;
            wp_fts_test_reset_wordpress_fakes();
            wp_fts_ldgf_reset_multilingual_globals();
            $postId = 8851;
            $post = wp_fts_test_backfill_post($postId, 'post', 'publish', "{$label} bounded language source");
            $post->terms = [];
            $post->custom_fields = [];
            $post->fts_language_override = '';
            $post->fts_post_source_bytes = strlen($post->post_title)
                + strlen($post->post_content)
                + strlen($post->post_excerpt);
            $posts = [$postId => $post];

            if ($case['polylang']) {
                $GLOBALS['polylang'] = (object) [];
                $GLOBALS['wp_fts_ldgf_polylang_post_languages'][$postId] = 'pl_PL';
            }
            if ($case['wpml']) {
                $GLOBALS['sitepress'] = (object) [];
                $GLOBALS['wp_fts_test_filters']['wpml_post_language_details'] = static fn(mixed $value): mixed => $value;
                $GLOBALS['wp_fts_ldgf_wpml_post_languages'][$postId] = 'de_DE';
            }

            $preload = new ReflectionMethod(WP_FTS_Plugin::class, 'preload_index_dependencies');
            $arguments = [&$posts];
            $preload->invokeArgs(null, $arguments);

            $statements = array_values(array_filter(
                $fake->prepared,
                static fn(array $statement): bool => str_starts_with(
                    (string) ($statement['sql'] ?? ''),
                    'SELECT bounded.post_order, bounded.row_order, bounded.source_kind,'
                )
            ));
            assert_same(1, count($statements), "{$label} should use one dependency measurement statement");
            $sql = (string) ($statements[0]['sql'] ?? '');
            $args = is_array($statements[0]['args'] ?? null) ? $statements[0]['args'] : [];
            assert_same($case['branches'] - 1, substr_count($sql, "\nUNION ALL\n"), "{$label} should have its declared fixed relation branches");
            assert_same($case['polylang'], str_contains($sql, 'wp_fts:polylang-languages'), "{$label} should compile only its declared Polylang arm");
            assert_same($case['wpml'], str_contains($sql, 'wp_fts:wpml-languages'), "{$label} should compile only its declared WPML arm");
            assert_same($case['placeholders'], substr_count($sql, '%d') + substr_count($sql, '%s'), "{$label} should retain a constant placeholder count for one post");
            assert_same($case['placeholders'], count($args), "{$label} should bind exactly one argument per placeholder");
            assert_same((int) $case['wpml'], substr_count($sql, 'LIMIT 101'), "{$label} should bound each unique WPML point source");
            assert_same($case['polylang'], str_contains($sql, 'bounded_polylang'), "{$label} should retain a bounded Polylang relation only when active");
            assert_same($case['wpml'], str_contains($sql, 'bounded_wpml'), "{$label} should retain a bounded WPML relation only when active");
            if ($case['polylang']) {
                assert_contains('FROM wp_term_relationships raw_language_rel FORCE INDEX (PRIMARY)', $sql, "{$label} should drive Polylang from the requested-post relationship key");
                assert_contains("LIMIT 2049\n    ) tr", $sql, "{$label} should cap raw relationship work before filtering for language taxonomy rows");
                assert_true(!str_contains($sql, "WHERE tt.taxonomy = %s"), "{$label} should return the raw Polylang frontier instead of hiding truncation behind a taxonomy filter");
            }
            if ($case['wpml']) {
                assert_contains('FROM wp_posts wpml_post FORCE INDEX (PRIMARY)', $sql, "{$label} should drive WPML from only the requested canonical posts");
                assert_contains('STRAIGHT_JOIN wp_icl_translations wpml_translation FORCE INDEX (el_type_id)', $sql, "{$label} should force WPML's unique element-type/id point key");
                assert_contains("wpml_translation.element_type = CAST(CONCAT('post_', wpml_post.post_type) AS CHAR(60) CHARACTER SET utf8mb4) COLLATE utf8mb4_bin", $sql, "{$label} should probe the exact canonical WPML element type without a LIKE range or cross-table collation dependency");
                assert_contains("CAST(CASE WHEN OCTET_LENGTH(wpml_translation.language_code) <= 64 THEN wpml_translation.language_code ELSE '' END AS CHAR(64) CHARACTER SET utf8mb4) COLLATE utf8mb4_bin", $sql, "{$label} should keep the bounded WPML language projection in VARCHAR metadata");
                assert_true(!str_contains($sql, 'element_type LIKE'), "{$label} should never scan a broad WPML element-type range");
            }
            assert_true(strlen($sql) < 32768, "{$label} should remain below the complete 32 KiB statement limit");
            assert_same(1, $fake->num_queries, "{$label} should not add a per-provider database round trip");

            unset($GLOBALS['polylang'], $GLOBALS['sitepress']);
            unset($GLOBALS['wp_fts_test_filters']['wpml_post_language_details']);
        }
    } finally {
        wp_fts_ldgf_reset_multilingual_globals();
        unset($GLOBALS['polylang'], $GLOBALS['sitepress']);
        unset($GLOBALS['wp_fts_test_filters']['wpml_post_language_details']);
        $wpdb = $oldWpdb;
    }
});

test_case('dependency preload bounds every post scan and defers an incomplete worst-case suffix', function (): void {
    global $wpdb;

    $oldWpdb = $wpdb ?? null;
    $fake = new WP_FTS_Test_WPDB();
    $wpdb = $fake;
    wp_fts_test_reset_wordpress_fakes();
    $GLOBALS['wp_fts_test_options'][WP_FTS_PostContentExtractor::CUSTOM_FIELDS_OPTION] = ['selected_signal'];
    $posts = [];
    $ids = range(7301, 7305);
    foreach ($ids as $postId) {
        $post = wp_fts_test_backfill_post($postId, 'post', 'publish', 'Bounded dependency ' . $postId);
        $post->terms = [];
        $post->custom_fields = [];
        $post->fts_post_source_bytes = strlen($post->post_title)
            + strlen($post->post_content)
            + strlen($post->post_excerpt);
        $GLOBALS['wp_fts_test_posts'][$postId] = $post;
        $GLOBALS['wp_fts_test_post_meta'][$postId] = [
            'selected_signal' => ['SelectedDependency' . $postId],
        ];
        for ($row = 1; $row <= 511; $row++) {
            $GLOBALS['wp_fts_test_post_meta'][$postId]['ignored_' . $row] = ['ignored value ' . $row];
        }
        $posts[$postId] = $post;
    }

    try {
        $preload = new ReflectionMethod(WP_FTS_Plugin::class, 'preload_index_dependencies');
        $arguments = [&$posts];
        $preload->invokeArgs(null, $arguments);
    } finally {
        $wpdb = $oldWpdb;
    }

    $measurementStatements = array_values(array_filter(
        $fake->prepared,
        static fn(array $statement): bool => str_starts_with(
            $statement['sql'] ?? '',
            'SELECT bounded.post_order, bounded.row_order, bounded.source_kind,'
        )
    ));
    assert_same(1, count($measurementStatements), 'the five-post adversary should use one set-oriented measurement statement');
    $statement = $measurementStatements[0] ?? ['sql' => '', 'args' => []];
    $sql = (string) ($statement['sql'] ?? '');
    $args = is_array($statement['args'] ?? null) ? $statement['args'] : [];
    assert_contains('bounded.source_id', $sql, 'measurement SQL should return only stable source identities and lengths');
    assert_true(!str_contains($sql, ' AS item_value,'), 'measurement SQL must not project a taxonomy or metadata LOB');
    assert_true(!str_contains($sql, 'LEFT(') && !str_contains($sql, 'SUBSTR('), 'measurement SQL must not materialize value prefixes inside its bounded UNION');
    assert_true(!str_contains($sql, 'COUNT('), 'dependency SQL must not aggregate an unbounded postmeta range');
    assert_true(!str_contains($sql, 'GROUP BY'), 'dependency SQL must not group an unbounded postmeta range');
    assert_same(1, substr_count($sql, 'FROM wp_postmeta'), 'all requested posts should share one post_id-indexed metadata arm');
    assert_same(1, substr_count($sql, 'FROM wp_term_relationships'), 'all requested posts should share one object_id-indexed taxonomy arm');
    assert_same(1, substr_count($sql, 'FROM wp_posts'), 'all requested posts should share one primary-key sentinel arm');
    assert_same(1, substr_count($sql, 'WHERE pm.post_id IN ('), 'the metadata arm should constrain the constant requested-post relation before its limit');
    assert_same(1, substr_count($sql, 'WHERE tr.object_id IN ('), 'the taxonomy arm should constrain the constant requested-post relation before its limit');
    assert_same(1, substr_count($sql, 'CASE WHEN pm.meta_key IN ('), 'the bounded selected-key union should control metadata length evaluation');
    assert_true(!str_contains($sql, 'WHERE pm.meta_key'), 'selected metadata must not become an unindexed meta_key-first predicate');
    assert_same(2, substr_count($sql, 'LIMIT 2049'), 'each indexed source arm should have one batch-wide 2,049-row stop');
    assert_same(2, substr_count($sql, "\nUNION ALL\n"), 'the complete measurement should have exactly three fixed branches');
    assert_true(strlen($sql) < 32768, 'the fixed-branch measurement template should remain below 32 KiB');
    assert_same(17, count($args), 'five IDs should appear once in each fixed arm beside two globally selected keys');

    $valueStatements = $fake->dependencyValueQueries;
    assert_same(1, count($valueStatements), 'accepted rows should load through one identity-bounded value statement');
    $valueSql = (string) ($valueStatements[0] ?? '');
    assert_contains('WHERE pm.meta_id IN (', $valueSql, 'the second statement should probe selected metadata by primary identity');
    assert_true(!str_contains($valueSql, 'post_id IN ('), 'value loading must not rescan every metadata row for an accepted post');
    assert_same(4, count($fake->dependencyValueSourceIdsRequested), 'the value statement should request only the four accepted selected rows');
    $expectedProjectedBytes = array_sum(array_map(
        static fn(int $postId): int => strlen('SelectedDependency' . $postId),
        array_slice($ids, 0, 4)
    ));
    assert_same($expectedProjectedBytes, $fake->dependencyValueBytesProjected, 'the fake database should project selected values only, never the 2,044 ignored rows');
    assert_contains('LEFT(CAST(pm.meta_value AS BINARY), 32)', $valueSql, 'selected values should use a byte-oriented next-power-of-two cap');

    foreach (array_slice($ids, 0, 4) as $postId) {
        assert_same(
            ['SelectedDependency' . $postId],
            $posts[$postId]->custom_fields['selected_signal'] ?? null,
            "post {$postId} should load its selected row at the exact 512-row boundary"
        );
        assert_true(
            !array_key_exists('ignored_1', $posts[$postId]->custom_fields ?? []),
            "post {$postId} should count but never hydrate unselected metadata"
        );
        assert_true(
            empty($posts[$postId]->fts_index_deferred) && !isset($posts[$postId]->fts_index_rejection),
            "post {$postId} should remain accepted when its completion sentinel fits"
        );
    }
    assert_same(true, $posts[7305]->fts_index_deferred ?? null, 'the first post whose completion sentinel is truncated should defer as a whole');
    assert_true(!isset($posts[7305]->fts_index_rejection), 'an incomplete batch suffix should retry rather than masquerade as permanent source rejection');
});

test_case('100-post 32-key dependency adversary keeps three branches and distinguishes rows 512 and 513', function (): void {
    global $wpdb;

    $oldWpdb = $wpdb ?? null;
    $fake = new WP_FTS_Test_WPDB();
    $wpdb = $fake;
    wp_fts_test_reset_wordpress_fakes();
    $keys = array_map(static fn(int $index): string => 'selected_' . $index, range(1, 32));
    $GLOBALS['wp_fts_test_options'][WP_FTS_PostContentExtractor::CUSTOM_FIELDS_OPTION] = $keys;
    $posts = [];
    foreach (range(7601, 7700) as $postId) {
        $post = wp_fts_test_backfill_post($postId, 'post', 'publish', 'Set dependency ' . $postId);
        $post->terms = [];
        $post->custom_fields = [];
        $post->fts_post_source_bytes = strlen($post->post_title)
            + strlen($post->post_content)
            + strlen($post->post_excerpt);
        $GLOBALS['wp_fts_test_posts'][$postId] = $post;
        $posts[$postId] = $post;
    }
    foreach ($keys as $index => $key) {
        $GLOBALS['wp_fts_test_post_meta'][7601][$key] = ['accepted-' . ($index + 1)];
        $GLOBALS['wp_fts_test_post_meta'][7602][$key] = ['overflow-' . ($index + 1)];
    }
    for ($row = 1; $row <= 480; $row++) {
        $GLOBALS['wp_fts_test_post_meta'][7601]['ignored_' . $row] = ['ignored'];
    }
    for ($row = 1; $row <= 481; $row++) {
        $GLOBALS['wp_fts_test_post_meta'][7602]['ignored_' . $row] = ['ignored'];
    }

    try {
        $preload = new ReflectionMethod(WP_FTS_Plugin::class, 'preload_index_dependencies');
        $arguments = [&$posts];
        $preload->invokeArgs(null, $arguments);
    } finally {
        $wpdb = $oldWpdb;
    }

    $measurementStatements = array_values(array_filter(
        $fake->prepared,
        static fn(array $statement): bool => str_starts_with(
            $statement['sql'] ?? '',
            'SELECT bounded.post_order, bounded.row_order, bounded.source_kind,'
        )
    ));
    assert_same(1, count($measurementStatements), 'the maximum 100-post request should prepare and execute one measurement statement');
    $statement = $measurementStatements[0] ?? ['sql' => '', 'args' => []];
    $sql = (string) ($statement['sql'] ?? '');
    $args = is_array($statement['args'] ?? null) ? $statement['args'] : [];
    $renderedBytes = strlen($sql);
    foreach ($args as $arg) {
        $rendered = is_int($arg) ? (string) $arg : "'" . addslashes((string) $arg) . "'";
        $renderedBytes += max(0, strlen($rendered) - 2);
    }
    assert_true($renderedBytes <= 32768, 'the fully rendered 100-post/32-key measurement should stay below 32 KiB');
    assert_same(2, substr_count($sql, "\nUNION ALL\n"), '100 posts should still use exactly three SQL branches');
    assert_same(1, substr_count($sql, 'FROM wp_postmeta'), '100 posts should share one metadata range scan');
    assert_same(1, substr_count($sql, 'FORCE INDEX (post_id)'), 'MariaDB should be forced onto the bounded native post_id range instead of a full-scan filesort');
    assert_same(1, substr_count($sql, 'FROM wp_term_relationships'), '100 posts should share one taxonomy range scan');
    assert_same(1, substr_count($sql, 'FORCE INDEX (PRIMARY)'), 'the taxonomy source should keep the requested object-ID ranges on its composite primary key');
    assert_same(2, substr_count($sql, 'LIMIT 2049'), 'both dependency sources should retain their batch-wide hard stop');
    foreach (['WITH ', 'ROW_NUMBER', 'LATERAL', 'JSON_TABLE', 'VALUES ', ' OFFSET '] as $unsupported) {
        assert_true(!str_contains(strtoupper($sql), $unsupported), "the MariaDB-compatible statement must not require {$unsupported}");
    }
    assert_same(2, $fake->num_queries, 'the accepted maximum boundary should use one measurement and one selected-value query');
    assert_same(1, count($fake->dependencyValueQueries), 'all 32 selected identities should hydrate in one value statement');
    assert_same(32, count($fake->dependencyValueSourceIdsRequested), 'only the accepted document’s 32 selected identities should be hydrated');
    assert_true(strlen((string) ($fake->dependencyValueQueries[0] ?? '')) <= 32768, 'the selected-value SQL should remain below 32 KiB');

    foreach ($keys as $index => $key) {
        assert_same(
            ['accepted-' . ($index + 1)],
            $posts[7601]->custom_fields[$key] ?? null,
            "the exact 512-row document should retain selected key {$key}"
        );
    }
    assert_true(empty($posts[7601]->fts_index_deferred) && !isset($posts[7601]->fts_index_rejection), 'exactly 512 dependency rows should be accepted');
    assert_same('dependency_rows', $posts[7602]->fts_index_rejection['reason_code'] ?? null, 'the 513th combined dependency row should be a permanent typed rejection');
    assert_true(empty($posts[7602]->fts_index_deferred), 'the known 513-row overflow must not be retried as an incomplete suffix');
    $accepted = array_filter($posts, static fn(object $post): bool => empty($post->fts_index_deferred) && !isset($post->fts_index_rejection));
    $deferred = array_filter($posts, static fn(object $post): bool => !empty($post->fts_index_deferred));
    assert_same(99, count($accepted), 'the exact-boundary document and all 98 empty documents should remain accepted');
    assert_same(0, count($deferred), 'a complete 1,025-row source scan should defer no requested post');
});

test_case('100 posts with distinct worst-size keys never construct an oversized dependency statement', function (): void {
    global $wpdb;

    $oldWpdb = $wpdb ?? null;
    $fake = new WP_FTS_Test_WPDB();
    $wpdb = $fake;
    wp_fts_test_reset_wordpress_fakes();
    $posts = [];
    foreach (range(7801, 7900) as $postId) {
        $post = wp_fts_test_backfill_post($postId, 'post', 'publish', 'Distinct keys ' . $postId);
        $post->terms = [];
        $post->custom_fields = [];
        $post->fts_post_source_bytes = strlen($post->post_title)
            + strlen($post->post_content)
            + strlen($post->post_excerpt);
        $GLOBALS['wp_fts_test_posts'][$postId] = $post;
        $posts[$postId] = $post;
    }
    $GLOBALS['wp_fts_test_filters']['wp_fts_post_custom_fields'] = static function (
        mixed $_keys,
        object $post
    ): array {
        $keys = [];
        foreach (range(1, 32) as $index) {
            $prefix = 'p' . (int) $post->ID . '_k' . $index . '_';
            $keys[] = substr($prefix . str_repeat("\\'", 191), 0, 191);
        }

        return $keys;
    };

    try {
        $preload = new ReflectionMethod(WP_FTS_Plugin::class, 'preload_index_dependencies');
        $arguments = [&$posts];
        $preload->invokeArgs(null, $arguments);
    } finally {
        unset($GLOBALS['wp_fts_test_filters']['wp_fts_post_custom_fields']);
        $wpdb = $oldWpdb;
    }

    $measurementStatements = array_values(array_filter(
        $fake->prepared,
        static fn(array $statement): bool => str_starts_with(
            $statement['sql'] ?? '',
            'SELECT bounded.post_order, bounded.row_order, bounded.source_kind,'
        )
    ));
    assert_same(1, count($measurementStatements), 'the conservative prefix should avoid even preparing an oversized retry');
    $statement = $measurementStatements[0] ?? ['sql' => '', 'args' => []];
    $sql = (string) ($statement['sql'] ?? '');
    $args = is_array($statement['args'] ?? null) ? $statement['args'] : [];
    $renderedBytes = strlen($sql);
    foreach ($args as $arg) {
        $rendered = is_int($arg) ? (string) $arg : "'" . addslashes((string) $arg) . "'";
        $renderedBytes += max(0, strlen($rendered) - 2);
    }
    assert_true($renderedBytes <= 32768, 'quote-heavy 191-byte keys should still produce at most 32 KiB of prepared SQL');
    assert_same(2, substr_count($sql, "\nUNION ALL\n"), 'the worst distinct-key prefix should retain exactly three branches');
    assert_same(1, $fake->num_queries, 'an empty dependency prefix should require one indexed measurement query');
    assert_same([], $fake->dependencyValueQueries, 'empty dependencies should require no selected-value query');
    $accepted = array_filter($posts, static fn(object $post): bool => empty($post->fts_index_deferred));
    $deferred = array_filter($posts, static fn(object $post): bool => !empty($post->fts_index_deferred));
    assert_true(count($accepted) >= 1, 'the SQL byte envelope must always admit at least one maximum-key document');
    assert_same(100, count($accepted) + count($deferred), 'every worst-key document should be explicitly accepted or deferred');
    assert_true(count($deferred) > 0, 'the oversized request suffix should defer rather than expanding the SQL packet');
});

test_case('512 selected identities cap value SQL at 38 branches and 32 KiB', function (): void {
    global $wpdb;

    $oldWpdb = $wpdb ?? null;
    $fake = new WP_FTS_Test_WPDB();
    $wpdb = $fake;
    $rows = [];
    $baseSourceId = 9000000000000000000;
    foreach (range(0, 511) as $index) {
        $kind = $index % 2 === 0 ? 'term' : 'meta';
        $bucket = 1 << ($index % 19);
        $rows[] = (object) [
            'source_kind' => $kind,
            'post_id' => 1,
            'item_key' => $kind . '_' . $index,
            'source_id' => $baseSourceId + $index,
            'item_value_bytes' => $bucket,
            'source_order' => $baseSourceId + $index,
            'is_selected' => 1,
        ];
    }

    try {
        $loader = new ReflectionMethod(WP_FTS_Plugin::class, 'load_bounded_index_dependency_values');
        $result = $loader->invoke(null, $rows);
    } finally {
        $wpdb = $oldWpdb;
    }

    assert_same(1, count($fake->dependencyValueQueries), 'the maximum selected-identity set should remain one value query');
    $sql = (string) ($fake->dependencyValueQueries[0] ?? '');
    assert_same(37, substr_count($sql, "\nUNION ALL\n"), 'nineteen power-of-two buckets for each source kind should cap the statement at 38 branches');
    assert_true(strlen($sql) <= 32768, '512 maximum-width source identities across all 38 buckets should stay below 32 KiB');
    assert_same(512, count($fake->dependencyValueSourceIdsRequested), 'the hard selected-identity boundary should probe every identity exactly once');
    assert_same([], $result['rows'] ?? null, 'the fake has no matching identities to hydrate');
    assert_same([1 => true], $result['incomplete_post_ids'] ?? null, 'missing maximum-boundary identities should defer their complete document');
});

test_case('the 513th selected identity defers a whole-document suffix before value SQL', function (): void {
    global $wpdb;

    $oldWpdb = $wpdb ?? null;
    $fake = new WP_FTS_Test_WPDB();
    $wpdb = $fake;
    wp_fts_test_reset_wordpress_fakes();
    $GLOBALS['wp_fts_test_options'][WP_FTS_PostContentExtractor::CUSTOM_FIELDS_OPTION] = ['selected_signal'];
    $posts = [];
    foreach ([7951, 7952] as $postId) {
        $post = wp_fts_test_backfill_post($postId, 'post', 'publish', 'Selected identity cap ' . $postId);
        $post->terms = [];
        $post->custom_fields = [];
        $post->fts_post_source_bytes = strlen($post->post_title)
            + strlen($post->post_content)
            + strlen($post->post_excerpt);
        $GLOBALS['wp_fts_test_posts'][$postId] = $post;
        $posts[$postId] = $post;
    }
    $GLOBALS['wp_fts_test_post_meta'][7951]['selected_signal'] = array_map(
        static fn(int $index): string => 'value-' . $index,
        range(1, 512)
    );
    $GLOBALS['wp_fts_test_post_meta'][7952]['selected_signal'] = ['suffix'];

    try {
        $preload = new ReflectionMethod(WP_FTS_Plugin::class, 'preload_index_dependencies');
        $arguments = [&$posts];
        $preload->invokeArgs(null, $arguments);
    } finally {
        $wpdb = $oldWpdb;
    }

    assert_same(2, $fake->num_queries, 'the selected-identity overflow should remain one measurement and one value statement');
    assert_same(512, count($fake->dependencyValueSourceIdsRequested), 'the value statement should stop at the exact 512-identity accepted prefix');
    assert_same(512, count($posts[7951]->custom_fields['selected_signal'] ?? []), 'the exact selected-identity boundary should hydrate completely');
    assert_true(empty($posts[7951]->fts_index_deferred) && !isset($posts[7951]->fts_index_rejection), 'the exact 512-identity document should remain accepted');
    assert_same(true, $posts[7952]->fts_index_deferred ?? null, 'selected identity 513 should defer its whole document');
    assert_true(!isset($posts[7952]->fts_index_rejection), 'a batch selected-identity suffix is retryable, not a permanent source rejection');
    assert_true(strlen((string) ($fake->dependencyValueQueries[0] ?? '')) <= 32768, 'the exact accepted value statement should remain below 32 KiB');
});

test_case('mixed taxonomy and metadata rows share the exact 512-row document boundary', function (): void {
    global $wpdb;

    $oldWpdb = $wpdb ?? null;
    $fake = new WP_FTS_Test_WPDB();
    $wpdb = $fake;
    wp_fts_test_reset_wordpress_fakes();
    $posts = [];
    foreach ([8001 => 212, 8002 => 213] as $postId => $termCount) {
        $post = wp_fts_test_backfill_post($postId, 'post', 'publish', 'Mixed dependency ' . $postId);
        $post->terms = [
            'category' => array_map(
                static fn(int $index): string => 'category-' . $index,
                range(1, $termCount)
            ),
        ];
        $post->custom_fields = [];
        $post->fts_post_source_bytes = strlen($post->post_title)
            + strlen($post->post_content)
            + strlen($post->post_excerpt);
        $GLOBALS['wp_fts_test_posts'][$postId] = $post;
        for ($row = 1; $row <= 300; $row++) {
            $GLOBALS['wp_fts_test_post_meta'][$postId]['ignored_' . $row] = ['ignored'];
        }
        $posts[$postId] = $post;
    }

    try {
        $preload = new ReflectionMethod(WP_FTS_Plugin::class, 'preload_index_dependencies');
        $arguments = [&$posts];
        $preload->invokeArgs(null, $arguments);
    } finally {
        $wpdb = $oldWpdb;
    }

    assert_same(2, $fake->num_queries, 'the mixed exact boundary should remain one measurement plus one selected-value statement');
    assert_same(212, count($fake->dependencyValueSourceIdsRequested), 'only the accepted document’s taxonomy identities should reach value hydration');
    assert_same(
        array_map(static fn(int $index): string => 'category-' . $index, range(1, 212)),
        array_slice($posts[8001]->terms['category'] ?? [], -212),
        '300 metadata plus 212 taxonomy rows should hydrate at the exact 512-row boundary'
    );
    assert_true(empty($posts[8001]->fts_index_deferred) && !isset($posts[8001]->fts_index_rejection), 'the mixed 512-row document should remain accepted');
    assert_same('dependency_rows', $posts[8002]->fts_index_rejection['reason_code'] ?? null, '300 metadata plus 213 taxonomy rows should reject at row 513');
    assert_true(empty($posts[8002]->fts_index_deferred), 'the complete mixed overflow should not retry');
});

test_case('dual-source fanout returns at most 4,198 rows and still rejects a proven overflow', function (): void {
    global $wpdb;

    $oldWpdb = $wpdb ?? null;
    $fake = new WP_FTS_Test_WPDB();
    $wpdb = $fake;
    wp_fts_test_reset_wordpress_fakes();
    $posts = [];
    foreach (range(8101, 8200) as $postId) {
        $post = wp_fts_test_backfill_post($postId, 'post', 'publish', 'Dual fanout ' . $postId);
        $post->terms = [];
        $post->custom_fields = [];
        $post->fts_post_source_bytes = strlen($post->post_title)
            + strlen($post->post_content)
            + strlen($post->post_excerpt);
        $GLOBALS['wp_fts_test_posts'][$postId] = $post;
        $posts[$postId] = $post;
    }
    $posts[8101]->terms['category'] = array_map(
        static fn(int $index): string => 'term-' . $index,
        range(1, 3000)
    );
    $GLOBALS['wp_fts_test_posts'][8101] = $posts[8101];
    for ($row = 1; $row <= 3000; $row++) {
        $GLOBALS['wp_fts_test_post_meta'][8101]['ignored_' . $row] = ['ignored'];
    }
    $capturedRows = [];
    $GLOBALS['wp_fts_test_dependency_after_measurement'] = static function (
        WP_FTS_Test_WPDB $_wpdb,
        array $rows
    ) use (&$capturedRows): void {
        $capturedRows = $rows;
    };

    try {
        $preload = new ReflectionMethod(WP_FTS_Plugin::class, 'preload_index_dependencies');
        $arguments = [&$posts];
        $preload->invokeArgs(null, $arguments);
    } finally {
        unset($GLOBALS['wp_fts_test_dependency_after_measurement']);
        $wpdb = $oldWpdb;
    }

    $kinds = array_count_values(array_map(static fn(object $row): string => (string) $row->source_kind, $capturedRows));
    assert_same(4198, count($capturedRows), 'two hostile source fanouts plus 100 sentinels should have an exact 4,198-row result ceiling');
    assert_same(2049, $kinds['term'] ?? null, 'the taxonomy arm should stop at 2,049 rows');
    assert_same(2049, $kinds['meta'] ?? null, 'the metadata arm should stop at 2,049 rows');
    assert_same(100, $kinds['complete'] ?? null, 'the fixed sentinel arm should return one row per requested post');
    assert_same(1, $fake->num_queries, 'a known overflow plus an unproven suffix should need only the one measurement query');
    assert_same([], $fake->dependencyValueQueries, 'no value query should run for a rejected overflow and incomplete suffix');
    assert_same('dependency_rows', $posts[8101]->fts_index_rejection['reason_code'] ?? null, '513 retained combined rows should prove overflow even when both source arms hit their limits');
    assert_true(empty($posts[8101]->fts_index_deferred), 'the proven first-post overflow should not be retried');
    $deferred = array_filter(array_slice($posts, 1, null, true), static fn(object $post): bool => !empty($post->fts_index_deferred));
    assert_same(99, count($deferred), 'every post beyond the truncated numeric frontier should defer safely');
});

test_case('dependency measurement ignores unselected LOB bytes and hydrates only the selected value', function (): void {
    global $wpdb;

    $oldWpdb = $wpdb ?? null;
    $fake = new WP_FTS_Test_WPDB();
    $wpdb = $fake;
    wp_fts_test_reset_wordpress_fakes();
    $GLOBALS['wp_fts_test_options'][WP_FTS_PostContentExtractor::CUSTOM_FIELDS_OPTION] = ['selected_signal'];
    $postId = 7401;
    $post = wp_fts_test_backfill_post($postId, 'post', 'publish', 'Virtual LOB dependency');
    $post->terms = [];
    $post->custom_fields = [];
    $post->fts_post_source_bytes = strlen($post->post_title)
        + strlen($post->post_content)
        + strlen($post->post_excerpt);
    $GLOBALS['wp_fts_test_posts'][$postId] = $post;
    $GLOBALS['wp_fts_test_post_meta'][$postId] = ['selected_signal' => ['selected']];
    for ($row = 1; $row <= 511; $row++) {
        $key = 'ignored_lob_' . $row;
        $GLOBALS['wp_fts_test_post_meta'][$postId][$key] = ['stub'];
        $GLOBALS['wp_fts_test_dependency_virtual_value_bytes'][$postId]['meta'][$key][0] = 256 * 1024;
    }
    $posts = [$postId => $post];

    try {
        $preload = new ReflectionMethod(WP_FTS_Plugin::class, 'preload_index_dependencies');
        $arguments = [&$posts];
        $preload->invokeArgs(null, $arguments);
    } finally {
        $wpdb = $oldWpdb;
    }

    $measurementStatements = array_values(array_filter(
        $fake->prepared,
        static fn(array $statement): bool => str_starts_with(
            $statement['sql'] ?? '',
            'SELECT bounded.post_order, bounded.row_order, bounded.source_kind,'
        )
    ));
    assert_same(1, count($measurementStatements), 'the virtual 128MiB unselected-LOB adversary should require one bounded measurement statement');
    $measurementSql = (string) ($measurementStatements[0]['sql'] ?? '');
    assert_true(!str_contains($measurementSql, ' AS item_value,'), 'measurement must not return any of the 512 adversarial values');
    assert_true(!str_contains($measurementSql, 'LEFT(') && !str_contains($measurementSql, 'SUBSTR('), 'measurement must not ask the database to materialize capped LOB prefixes');
    assert_contains('CASE WHEN pm.meta_key IN (', $measurementSql, 'only the bounded selected-key union should evaluate a metadata LOB length');
    assert_contains('ELSE 0 END AS item_value_bytes', $measurementSql, 'unselected metadata should contribute no value bytes');
    assert_same([1], $fake->dependencyValueSourceIdsRequested, 'only the selected metadata identity should reach the value statement');
    assert_same(strlen('selected'), $fake->dependencyValueBytesProjected, 'none of the 511 unselected 256KiB values should be projected');
    assert_same(['selected'], $posts[$postId]->custom_fields['selected_signal'] ?? null, 'the selected value should remain indexable beside huge unselected metadata');
    assert_true(!isset($posts[$postId]->fts_index_rejection) && empty($posts[$postId]->fts_index_deferred), 'unselected LOBs should not exclude an otherwise bounded post');
});

test_case('dependency measurement rejects an oversized selected value before value projection', function (): void {
    global $wpdb;

    $oldWpdb = $wpdb ?? null;
    $fake = new WP_FTS_Test_WPDB();
    $wpdb = $fake;
    wp_fts_test_reset_wordpress_fakes();
    $GLOBALS['wp_fts_test_options'][WP_FTS_PostContentExtractor::CUSTOM_FIELDS_OPTION] = ['selected_signal'];
    $postId = 7402;
    $post = wp_fts_test_backfill_post($postId, 'post', 'publish', 'Selected LOB dependency');
    $post->terms = [];
    $post->custom_fields = [];
    $post->fts_post_source_bytes = strlen($post->post_title)
        + strlen($post->post_content)
        + strlen($post->post_excerpt);
    $GLOBALS['wp_fts_test_posts'][$postId] = $post;
    $GLOBALS['wp_fts_test_post_meta'][$postId] = ['selected_signal' => ['stub']];
    $GLOBALS['wp_fts_test_dependency_virtual_value_bytes'][$postId]['meta']['selected_signal'][0] = (256 * 1024) + 1;
    $posts = [$postId => $post];

    try {
        $preload = new ReflectionMethod(WP_FTS_Plugin::class, 'preload_index_dependencies');
        $arguments = [&$posts];
        $preload->invokeArgs(null, $arguments);
    } finally {
        $wpdb = $oldWpdb;
    }

    assert_same([], $fake->dependencyValueSourceIdsRequested, 'an oversized selected identity should be rejected before the value statement');
    assert_same(0, $fake->dependencyValueBytesProjected, 'an oversized selected LOB should project no value bytes');
    assert_same('dependency_value_bytes', $posts[$postId]->fts_index_rejection['reason_code'] ?? null, 'selected values above 256 KiB should have a stable rejection code');
    assert_true(empty($posts[$postId]->fts_index_deferred), 'a complete oversized selected measurement should reject rather than retry forever');
});

test_case('dependency value snapshot caps concurrent growth and defers changed or missing identities', function (): void {
    global $wpdb;

    $oldWpdb = $wpdb ?? null;
    $fake = new WP_FTS_Test_WPDB();
    $wpdb = $fake;
    wp_fts_test_reset_wordpress_fakes();
    $GLOBALS['wp_fts_test_options'][WP_FTS_PostContentExtractor::CUSTOM_FIELDS_OPTION] = ['selected_signal'];
    $posts = [];
    foreach ([7501, 7502] as $postId) {
        $post = wp_fts_test_backfill_post($postId, 'post', 'publish', 'Concurrent dependency ' . $postId);
        $post->terms = [];
        $post->custom_fields = [];
        $post->fts_post_source_bytes = strlen($post->post_title)
            + strlen($post->post_content)
            + strlen($post->post_excerpt);
        $GLOBALS['wp_fts_test_posts'][$postId] = $post;
        $GLOBALS['wp_fts_test_post_meta'][$postId] = ['selected_signal' => ['short']];
        $posts[$postId] = $post;
    }
    $GLOBALS['wp_fts_test_dependency_after_measurement'] = static function (): void {
        $GLOBALS['wp_fts_test_post_meta'][7501]['selected_signal'] = [str_repeat('😀', 256 * 1024)];
        unset($GLOBALS['wp_fts_test_post_meta'][7502]['selected_signal']);
    };

    try {
        $preload = new ReflectionMethod(WP_FTS_Plugin::class, 'preload_index_dependencies');
        $arguments = [&$posts];
        $preload->invokeArgs(null, $arguments);
    } finally {
        $wpdb = $oldWpdb;
    }

    assert_same(2, count($fake->dependencyValueSourceIdsRequested), 'the value statement should probe both measured selected identities exactly once');
    assert_same(8, $fake->dependencyValueBytesProjected, 'a value that grows to 1MiB after measurement should still project only its eight-byte measured bucket');
    assert_contains('LEFT(CAST(pm.meta_value AS BINARY), 8)', (string) ($fake->dependencyValueQueries[0] ?? ''), 'concurrent multibyte growth must be sliced in bytes rather than characters');
    assert_same(true, $posts[7501]->fts_index_deferred ?? null, 'a concurrently grown value should defer the complete post generation');
    assert_same(true, $posts[7502]->fts_index_deferred ?? null, 'a concurrently deleted source identity should defer the complete post generation');
    assert_true(!isset($posts[7501]->custom_fields), 'a mismatched value snapshot must not partially hydrate the grown document');
    assert_true(!isset($posts[7502]->custom_fields), 'a missing identity must not partially hydrate the changed document');
});

test_case('dependency value buckets are strict byte bounds including empty values', function (): void {
    $bucket = new ReflectionMethod(WP_FTS_Plugin::class, 'dependency_value_bucket');
    assert_same(0, $bucket->invoke(null, 0), 'an empty measurement must request zero value bytes');
    foreach ([1, 2, 3, 4, 5, 7, 8, 9, 65535, 65536, 131073, 262144] as $bytes) {
        $cap = (int) $bucket->invoke(null, $bytes);
        assert_true($cap >= $bytes, "bucket {$cap} must preserve an unchanged {$bytes}-byte value");
        assert_true($cap < 2 * $bytes, "bucket {$cap} must stay strictly below twice an {$bytes}-byte measurement");
    }
});

test_case('dependency preload preserves exact multibyte term and metadata bytes', function (): void {
    global $wpdb;

    $oldWpdb = $wpdb ?? null;
    $fake = new WP_FTS_Test_WPDB();
    $wpdb = $fake;
    wp_fts_test_reset_wordpress_fakes();
    $GLOBALS['wp_fts_test_options'][WP_FTS_PostContentExtractor::CUSTOM_FIELDS_OPTION] = ['selected_signal'];
    $postId = 7503;
    $multibyte = '😀漢字é';
    $post = wp_fts_test_backfill_post($postId, 'post', 'publish', 'Exact multibyte dependency');
    $post->terms = ['category' => [$multibyte]];
    $post->custom_fields = [];
    $post->fts_post_source_bytes = strlen($post->post_title)
        + strlen($post->post_content)
        + strlen($post->post_excerpt);
    $GLOBALS['wp_fts_test_posts'][$postId] = $post;
    $GLOBALS['wp_fts_test_post_meta'][$postId] = ['selected_signal' => [$multibyte]];
    $posts = [$postId => $post];

    try {
        $preload = new ReflectionMethod(WP_FTS_Plugin::class, 'preload_index_dependencies');
        $arguments = [&$posts];
        $preload->invokeArgs(null, $arguments);
    } finally {
        $wpdb = $oldWpdb;
    }

    $sql = (string) ($fake->dependencyValueQueries[0] ?? '');
    assert_contains('LEFT(CAST(t.name AS BINARY), 16)', $sql, 'taxonomy names must be projected as binary bytes');
    assert_contains('LEFT(CAST(pm.meta_value AS BINARY), 16)', $sql, 'metadata values must be projected as binary bytes');
    assert_same(strlen($multibyte) * 2, $fake->dependencyValueBytesProjected, 'the database transport should preserve the exact two selected byte strings');
    assert_same([$multibyte], array_slice($posts[$postId]->terms['category'] ?? [], -1), 'the hydrated taxonomy value must remain byte-for-byte exact');
    assert_same([$multibyte], $posts[$postId]->custom_fields['selected_signal'] ?? null, 'the hydrated metadata value must remain byte-for-byte exact');
    assert_true(empty($posts[$postId]->fts_index_deferred), 'unchanged multibyte values should not be mistaken for concurrent growth');
});

test_case('an empty dependency transports zero bytes and rejects concurrent growth', function (): void {
    global $wpdb;

    $run = static function (int $postId, bool $grow) use (&$wpdb): array {
        $fake = new WP_FTS_Test_WPDB();
        $wpdb = $fake;
        wp_fts_test_reset_wordpress_fakes();
        $GLOBALS['wp_fts_test_options'][WP_FTS_PostContentExtractor::CUSTOM_FIELDS_OPTION] = ['selected_signal'];
        $post = wp_fts_test_backfill_post($postId, 'post', 'publish', 'Empty dependency');
        $post->terms = [];
        $post->custom_fields = [];
        $post->fts_post_source_bytes = strlen($post->post_title)
            + strlen($post->post_content)
            + strlen($post->post_excerpt);
        $GLOBALS['wp_fts_test_posts'][$postId] = $post;
        $GLOBALS['wp_fts_test_post_meta'][$postId] = ['selected_signal' => ['']];
        if ($grow) {
            $GLOBALS['wp_fts_test_dependency_after_measurement'] = static function () use ($postId): void {
                $GLOBALS['wp_fts_test_post_meta'][$postId]['selected_signal'] = ['😀'];
            };
        }
        $posts = [$postId => $post];
        try {
            $preload = new ReflectionMethod(WP_FTS_Plugin::class, 'preload_index_dependencies');
            $arguments = [&$posts];
            $preload->invokeArgs(null, $arguments);
        } finally {
            unset($GLOBALS['wp_fts_test_dependency_after_measurement']);
        }

        return [$fake, $posts[$postId]];
    };

    $oldWpdb = $wpdb ?? null;
    try {
        [$exactFake, $exactPost] = $run(7504, false);
        assert_contains('LEFT(CAST(pm.meta_value AS BINARY), 0)', (string) ($exactFake->dependencyValueQueries[0] ?? ''), 'empty metadata should have a zero-byte SQL projection');
        assert_same(0, $exactFake->dependencyValueBytesProjected, 'an unchanged empty value should transport zero bytes');
        assert_true(empty($exactPost->fts_index_deferred), 'an unchanged empty selected value should remain accepted');

        [$grownFake, $grownPost] = $run(7505, true);
        assert_same(0, $grownFake->dependencyValueBytesProjected, 'growth from zero bytes must still transport zero value bytes');
        assert_same(true, $grownPost->fts_index_deferred ?? null, 'growth from an empty measurement must defer the whole generation');
    } finally {
        $wpdb = $oldWpdb;
        wp_fts_test_reset_wordpress_fakes();
    }
});

test_case('custom-field selection rejects the thirty-third key without truncating', function (): void {
    $extractor = new WP_FTS_PostContentExtractor();
    $post = (object) ['ID' => 7];
    $accepted = array_map(static fn(int $index): string => 'field_' . $index, range(1, 32));
    $expected = $accepted;
    sort($expected, SORT_STRING);
    assert_same($expected, $extractor->selected_custom_field_keys($post, ['custom_field_keys' => $accepted]), 'the full 32-key boundary should remain usable');

    $thrown = null;
    try {
        $extractor->selected_custom_field_keys($post, ['custom_field_keys' => [...$accepted, 'field_33']]);
    } catch (WP_FTS_Analysis_Limit_Exceeded $error) {
        $thrown = $error;
    }
    assert_true($thrown instanceof WP_FTS_Analysis_Limit_Exceeded, 'the thirty-third key should fail with the typed permanent-rejection boundary');
    assert_same('custom_field_keys', $thrown?->reason_code, 'the rejection should expose a stable operator reason code');
});

test_case('custom-field selection rejects an overlong key before SQL construction', function (): void {
    $extractor = new WP_FTS_PostContentExtractor();
    $thrown = null;
    try {
        $extractor->selected_custom_field_keys((object) ['ID' => 8], ['custom_field_keys' => str_repeat('x', 192)]);
    } catch (WP_FTS_Analysis_Limit_Exceeded $error) {
        $thrown = $error;
    }
    assert_true($thrown instanceof WP_FTS_Analysis_Limit_Exceeded, 'a 192-byte key should fail before it reaches a prepared statement');
    assert_same('custom_field_key_shape', $thrown?->reason_code, 'an overlong key should have a stable reason code');
});
