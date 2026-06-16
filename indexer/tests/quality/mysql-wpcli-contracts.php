<?php
declare(strict_types=1);

namespace WP_CLI\Utils {
    if (!function_exists(__NAMESPACE__ . '\format_items')) {
        /**
         * @param array<int,array<string,mixed>> $items
         * @param string[] $fields
         */
        function format_items(string $format, array $items, array $fields): void
        {
            $GLOBALS['wp_fts_quality_cli_format_items'][] = [
                'format' => $format,
                'items' => $items,
                'fields' => $fields,
            ];
        }
    }
}

namespace {
    if (!function_exists('add_action')) {
        function add_action(string $hook_name, mixed $callback, int $priority = 10, int $accepted_args = 1): bool
        {
            $GLOBALS['wp_fts_quality_add_action_calls'][] = [
                'hook' => $hook_name,
                'callback' => $callback,
                'priority' => $priority,
                'accepted_args' => $accepted_args,
            ];

            return true;
        }
    }

    /**
     * @return array<int,array{sql:string,args:array<int,mixed>}>
     */
    function wp_fts_quality_prepared_like(WP_FTS_Test_WPDB $wpdb, string $prefix): array
    {
        return array_values(array_filter(
            $wpdb->prepared,
            static fn(array $entry): bool => str_starts_with($entry['sql'], $prefix)
        ));
    }

    /**
     * @return array{sql:string,args:array<int,mixed>}
     */
    function wp_fts_quality_last_prepared_like(WP_FTS_Test_WPDB $wpdb, string $prefix): array
    {
        $entries = wp_fts_quality_prepared_like($wpdb, $prefix);
        assert_true($entries !== [], "expected prepared SQL beginning with {$prefix}");

        return $entries[count($entries) - 1];
    }

    function wp_fts_quality_reset_cli(): void
    {
        if (class_exists('WP_CLI')) {
            WP_CLI::$successMessages = [];
            WP_CLI::$commands = [];
        }

        $GLOBALS['wp_fts_quality_cli_format_items'] = [];
    }

    function wp_fts_quality_with_wpdb(WP_FTS_Test_WPDB $wpdb, callable $callback): mixed
    {
        $hadWpdb = array_key_exists('wpdb', $GLOBALS);
        $oldWpdb = $GLOBALS['wpdb'] ?? null;
        $GLOBALS['wpdb'] = $wpdb;

        try {
            return $callback($wpdb);
        } finally {
            if ($hadWpdb) {
                $GLOBALS['wpdb'] = $oldWpdb;
            } else {
                unset($GLOBALS['wpdb']);
            }
        }
    }

    /**
     * @return array{lang:string,doc_len:int,content_hash:?string,is_deleted:int}
     */
    function wp_fts_quality_doc_row(string $lang, int $length, ?string $hash = null, int $deleted = 0): array
    {
        return [
            'lang' => $lang,
            'doc_len' => $length,
            'content_hash' => $hash ?? sha1($lang . ':' . $length),
            'is_deleted' => $deleted,
        ];
    }

    test_case('quality mysql schema declares binary language partition contracts', function (): void {
        $wpdb = new WP_FTS_Test_WPDB();
        $storage = new WP_FTS_Storage_Mysql($wpdb);
        $storage->create_tables();

        $schemaSql = implode("\n\n", $wpdb->queries);
        $schemaNeedles = [
            'CREATE TABLE wp_fts_terms',
            'term varbinary(255) NOT NULL',
            'doc_freq int unsigned NOT NULL',
            'PRIMARY KEY  (term)',
            'ROW_FORMAT=DYNAMIC DEFAULT CHARSET=binary',
            'CREATE TABLE wp_fts_postings',
            'doc_id bigint unsigned NOT NULL',
            'tf int unsigned NOT NULL',
            'PRIMARY KEY  (term,doc_id)',
            'KEY doc_id (doc_id)',
            'CREATE TABLE wp_fts_docs',
            "lang varchar(16) NOT NULL DEFAULT 'und'",
            'doc_len int unsigned NOT NULL DEFAULT 0',
            'content_hash char(40) NULL',
            'is_deleted tinyint(1) NOT NULL DEFAULT 0',
            'PRIMARY KEY  (doc_id)',
            'KEY lang (lang)',
            'KEY is_deleted (is_deleted)',
            'CREATE TABLE wp_fts_doc_lengths',
            'PRIMARY KEY  (doc_id,lang)',
            'KEY lang (lang)',
            'CREATE TABLE wp_fts_docmeta',
            'post_type varchar(32) NOT NULL DEFAULT',
            'post_status varchar(20) NOT NULL DEFAULT',
            'post_date_gmt varchar(19) NOT NULL DEFAULT',
            'KEY post_type_status_date (post_type,post_status,post_date_gmt)',
            'CREATE TABLE wp_fts_meta',
            'k varchar(64) NOT NULL',
            'v bigint NOT NULL',
            'PRIMARY KEY  (lang,k)',
        ];

        assert_same(6, count(array_filter($wpdb->queries, static fn(string $sql): bool => str_starts_with($sql, 'CREATE TABLE'))), 'schema should create exactly six FTS tables');
        foreach ($schemaNeedles as $needle) {
            assert_contains($needle, $schemaSql, "schema should contain {$needle}");
        }

        foreach (['postings longblob', 'FULLTEXT', 'ENGINE=MyISAM', 'utf8mb4_general_ci'] as $forbidden) {
            assert_true(!str_contains(strtoupper($schemaSql), strtoupper($forbidden)), "schema should not contain {$forbidden}");
        }

        $customWpdb = new WP_FTS_Test_WPDB();
        $custom = new WP_FTS_Storage_Mysql($customWpdb, 'custom_');
        $custom->create_tables();
        $customSql = implode("\n", $customWpdb->queries);
        foreach (['custom_fts_terms', 'custom_fts_postings', 'custom_fts_docs', 'custom_fts_doc_lengths', 'custom_fts_docmeta', 'custom_fts_meta'] as $table) {
            assert_contains($table, $customSql, "custom prefix schema should create {$table}");
        }
    });

    test_case('quality mysql row postings preserve binary namespaces and compatibility upserts', function (): void {
        $wpdb = new WP_FTS_Test_WPDB();
        $storage = new WP_FTS_Storage_Mysql($wpdb);
        $terms = [
            ['en', 'shared', [11 => 2]],
            ['pl', 'shared', [12 => 3]],
            ['pt_BR', 'organizar', [13 => 4]],
            ['sr_Cyrl_RS', 'grad', [14 => 1]],
            ['zh_Hant', 'search', [15 => 5]],
            ['es_419', 'color', [16 => 2]],
            ['de-DE', 'strasse', [17 => 7]],
            ['tr_TR', 'istanbul', [18 => 2]],
        ];

        foreach ($terms as [$language, $term, $postings]) {
            $key = WP_FTS_TermNamespace::namespace_term($language, $term);
            $encoded = WP_FTS_PostingsCodec::encode($postings);
            $storage->put_term($key, count($postings), $encoded);

            $postingInsert = wp_fts_quality_last_prepared_like($wpdb, 'INSERT INTO wp_fts_postings');
            assert_contains('ON DUPLICATE KEY UPDATE tf = VALUES(tf)', $postingInsert['sql'], "{$language} posting upsert should update only the row tf");
            assert_same($key, $postingInsert['args'][0], "{$language} posting term arg should keep the binary namespace");
            assert_same((int) array_key_first($postings), $postingInsert['args'][1], "{$language} posting doc arg should match the row doc");
            assert_same(reset($postings), $postingInsert['args'][2], "{$language} posting tf arg should match the row tf");

            $termUpsert = wp_fts_quality_last_prepared_like($wpdb, 'INSERT INTO wp_fts_terms');
            assert_contains('ON DUPLICATE KEY UPDATE doc_freq = VALUES(doc_freq)', $termUpsert['sql'], "{$language} term upsert should update df only");
            assert_true(!str_contains($termUpsert['sql'], 'postings'), "{$language} term upsert should not write a postings blob");
            assert_same($key, $termUpsert['args'][0], "{$language} prepared term arg should keep the binary namespace");
            assert_same(count($postings), $termUpsert['args'][1], "{$language} prepared df arg should match postings");
            assert_contains('1e', bin2hex($key), "{$language} term key should contain the byte-stable namespace separator");

            $row = $storage->get_terms([$key])[$key] ?? null;
            assert_true($row !== null, "{$language} term should be readable after upsert");
            assert_same(count($postings), $row['df'], "{$language} read df should match write df");
            assert_same($postings, WP_FTS_PostingsCodec::decode($row['postings']), "{$language} postings should round trip");
        }

        $plKey = WP_FTS_TermNamespace::namespace_term('pl', 'shared');
        $enKey = WP_FTS_TermNamespace::namespace_term('en', 'shared');
        assert_true($plKey !== $enKey, 'same token in different languages should not share a MySQL key');
        assert_same([$enKey, $plKey], array_values(array_intersect($storage->all_terms(), [$enKey, $plKey])), 'binary namespaced keys should remain independently sorted');

        $storage->put_term($plKey, 0, '');
        $postingsDelete = wp_fts_quality_last_prepared_like($wpdb, 'DELETE FROM wp_fts_postings WHERE term = %s');
        assert_same($plKey, $postingsDelete['args'][0], 'zero-df term writes should delete postings rows for the exact namespaced key');
        $delete = wp_fts_quality_last_prepared_like($wpdb, 'DELETE FROM wp_fts_terms WHERE term = %s');
        assert_same($plKey, $delete['args'][0], 'zero-df term writes should prepare a delete for the exact namespaced key');
        assert_true(!isset($wpdb->terms[$plKey]), 'zero-df term should be removed from fake MySQL state');
        assert_true(!isset($wpdb->postings[$plKey]), 'zero-df term should remove row postings from fake MySQL state');

        $tooLong = WP_FTS_TermNamespace::namespace_term('en', str_repeat('x', WP_FTS_TermNamespace::MAX_TERM_KEY_BYTES));
        $thrown = false;
        try {
            $storage->put_term($tooLong, 1, WP_FTS_PostingsCodec::encode([1 => 1]));
        } catch (InvalidArgumentException) {
            $thrown = true;
        }
        assert_true($thrown, 'terms exceeding the varbinary key limit should be rejected before SQL');
    });

    test_case('quality mysql replace_doc_postings keeps shared term rows without blob overwrite', function (): void {
        $wpdb = new WP_FTS_Test_WPDB();
        $storage = new WP_FTS_Storage_Mysql($wpdb);
        $shared = WP_FTS_TermNamespace::namespace_term('en', 'shared');
        $unique = WP_FTS_TermNamespace::namespace_term('en', 'unique');

        $storage->begin_transaction();
        $storage->replace_doc_postings(1001, [$shared => 2]);
        $storage->replace_doc_postings(1002, [$shared => 3]);
        $storage->commit();

        $row = $storage->get_terms([$shared])[$shared] ?? null;
        assert_true($row !== null, 'shared term should exist after two document posting replacements');
        assert_same(2, $row['df'], 'shared term df should count both documents');
        assert_same([1001 => 2, 1002 => 3], WP_FTS_PostingsCodec::decode($row['postings']), 'shared term should keep both row postings');

        $postingDeletes = wp_fts_quality_prepared_like($wpdb, 'DELETE FROM wp_fts_postings WHERE doc_id = %d');
        assert_same([1001], $postingDeletes[0]['args'], 'first replacement should delete old rows by doc id');
        assert_same([1002], $postingDeletes[1]['args'], 'second replacement should delete old rows by doc id');

        $postingInserts = wp_fts_quality_prepared_like($wpdb, 'INSERT INTO wp_fts_postings');
        assert_same([$shared, 1001, 2], $postingInserts[0]['args'], 'first replacement should insert one row posting');
        assert_same([$shared, 1002, 3], $postingInserts[1]['args'], 'second replacement should insert an independent row posting');
        foreach ($postingInserts as $insert) {
            assert_contains('ON DUPLICATE KEY UPDATE tf = VALUES(tf)', $insert['sql'], 'posting writes should upsert tf at row granularity');
        }

        $termIncrements = wp_fts_quality_prepared_like($wpdb, 'INSERT INTO wp_fts_terms');
        assert_contains('doc_freq = doc_freq + VALUES(doc_freq)', $termIncrements[0]['sql'], 'doc frequency should use atomic increments');
        assert_true(!str_contains(implode("\n", array_column($termIncrements, 'sql')), 'postings'), 'doc-frequency upserts should not include postings blobs');

        $storage->replace_doc_postings(1001, [$unique => 4]);
        assert_same([1002 => 3], WP_FTS_PostingsCodec::decode($storage->get_terms([$shared])[$shared]['postings']), 'replacing one doc should leave the other shared posting intact');
        assert_same(1, $storage->get_terms([$shared])[$shared]['df'], 'shared df should decrement only the replaced document');
        assert_same([1001 => 4], WP_FTS_PostingsCodec::decode($storage->get_terms([$unique])[$unique]['postings']), 'new term should receive the moved document posting');

        $negativeUpdate = wp_fts_quality_last_prepared_like($wpdb, 'UPDATE wp_fts_terms');
        assert_same([1, $shared], $negativeUpdate['args'], 'old shared term df should be decremented atomically');
    });

    test_case('quality mysql sqlite fallback reads binary namespaced term rows', function (): void {
        $wpdb = new WP_FTS_Test_WPDB();
        $wpdb->dbh = new WP_FTS_Test_SQLite_Driver();
        $wpdb->missPreparedTermLookups = true;
        $storage = new WP_FTS_Storage_Mysql($wpdb);
        $plKey = WP_FTS_TermNamespace::namespace_term('pl', 'wroclaw');
        $deKey = WP_FTS_TermNamespace::namespace_term('de', 'fuehrung');

        $storage->put_term($plKey, 1, WP_FTS_PostingsCodec::encode([401 => 2]));
        $storage->put_term($deKey, 1, WP_FTS_PostingsCodec::encode([402 => 3]));

        assert_same([$plKey => [401 => 2]], $storage->get_postings([$plKey]), 'SQLite fallback should recover postings when prepared namespaced-key lookup misses');

        $row = $storage->get_terms([$plKey])[$plKey] ?? null;
        assert_true($row !== null, 'SQLite fallback should recover doc frequency and postings for get_terms');
        assert_same(1, $row['df'], 'SQLite fallback should preserve term document frequency');
        assert_same([401 => 2], WP_FTS_PostingsCodec::decode($row['postings']), 'SQLite fallback should preserve exact posting rows');

        $preparedPostings = wp_fts_quality_last_prepared_like($wpdb, 'SELECT term, doc_id, tf FROM wp_fts_postings');
        assert_same([$plKey], $preparedPostings['args'], 'storage should still attempt the indexed prepared lookup before SQLite fallback');
    });

    test_case('quality mysql document and meta overloads preserve language partitions', function (): void {
        $wpdb = new WP_FTS_Test_WPDB();
        $storage = new WP_FTS_Storage_Mysql($wpdb);

        $storage->put_doc(101, 5, 'legacy-hash');
        $legacy = $storage->get_doc(101);
        assert_same('und', $legacy['primary_lang'], 'legacy put_doc should use the unspecified partition');
        assert_same(['und' => 5], $legacy['lang_lengths'], 'legacy put_doc should create an unspecified doc length');
        assert_same([101 => 5], $storage->get_doc_lengths([101], 'und'), 'legacy length should be queryable through language overload');
        assert_same([101 => 5], $storage->get_doc_lengths([101]), 'legacy length should be queryable through aggregate overload');

        $docs = [
            [201, 'pl_PL', ['pl_PL' => 4, 'en' => 2, 'empty' => 0], 'hash-pl', 'pl-PL', ['en' => 2, 'pl-PL' => 4]],
            [202, 'pt_BR', ['pt_BR' => 3, 'es_419' => 1], 'hash-pt', 'pt-BR', ['es-419' => 1, 'pt-BR' => 3]],
            [203, 'sr_Cyrl_RS', ['sr_Cyrl_RS' => 6], 'hash-sr', 'sr-Cyrl-RS', ['sr-Cyrl-RS' => 6]],
        ];

        foreach ($docs as [$docId, $primary, $lengths, $hash, $expectedPrimary, $expectedLengths]) {
            $storage->put_doc($docId, $primary, $lengths, $hash);
            $doc = $storage->get_doc($docId);
            assert_same($expectedPrimary, $doc['primary_lang'], "{$docId} primary language should be canonicalized");
            assert_same($expectedLengths, $doc['lang_lengths'], "{$docId} per-language lengths should be normalized and sorted");
            assert_same(array_sum($expectedLengths), $doc['doc_len'], "{$docId} aggregate doc length should sum partitions");
            assert_same($hash, $doc['content_hash'], "{$docId} content hash should round trip");
            assert_true(!$doc['deleted'], "{$docId} new doc should clear tombstone state");

            foreach ($expectedLengths as $lang => $length) {
                assert_same([$docId => $length], $storage->get_doc_lengths([$docId], $lang), "{$docId} {$lang} length lookup should use language param");
            }
        }

        $putSql = wp_fts_quality_last_prepared_like($wpdb, 'INSERT INTO wp_fts_docs');
        assert_contains('ON DUPLICATE KEY UPDATE lang = VALUES(lang), doc_len = VALUES(doc_len), content_hash = VALUES(content_hash), is_deleted = 0', $putSql['sql'], 'put_doc should upsert docs and clear tombstones');

        $lengthSql = wp_fts_quality_last_prepared_like($wpdb, 'SELECT dl.doc_id, dl.doc_len FROM wp_fts_doc_lengths');
        assert_contains('dl.lang = %s', $lengthSql['sql'], 'language-aware length lookup should prepare a language predicate');
        assert_same('sr-Cyrl-RS', $lengthSql['args'][0], 'language-aware length lookup should canonicalize the language argument');

        foreach ([['und', 1, 5], ['pl_PL', 1, 4], ['pl_PL', 1, 2], ['pt_BR', 1, 3], ['es_419', 1, 1]] as [$lang, $docsDelta, $lenDelta]) {
            $storage->add_meta($lang, $docsDelta, $lenDelta);
        }
        $storage->add_meta(1, 5);
        assert_same(['doc_count' => 2, 'len_sum' => 10], $storage->get_meta('und'), 'legacy and language-aware meta should meet in und');
        assert_same(['doc_count' => 2, 'len_sum' => 6], $storage->get_meta('pl-PL'), 'language-aware add_meta should accumulate per canonical language');
        assert_same(['doc_count' => 1, 'len_sum' => 1], $storage->get_meta('es-419'), 'numeric region language meta should canonicalize');
        assert_same(['doc_count' => 6, 'len_sum' => 20], $storage->get_meta(), 'aggregate meta should sum all language partitions');

        $storage->add_meta('pl_PL', -10, -20);
        assert_same(['doc_count' => 0, 'len_sum' => 0], $storage->get_meta('pl-PL'), 'negative meta deltas should clamp at zero');

        $storage->delete_doc(201);
        $deleteSql = wp_fts_quality_last_prepared_like($wpdb, 'INSERT INTO wp_fts_docs');
        assert_contains('ON DUPLICATE KEY UPDATE is_deleted = 1', $deleteSql['sql'], 'delete_doc should tombstone through an upsert');
        assert_true($storage->get_doc(201)['deleted'], 'delete_doc should mark an existing doc as deleted');
        assert_same([], $storage->get_doc_lengths([201], 'pl_PL'), 'tombstoned docs should be hidden from language length lookups');
        assert_same([101, 202, 203], $storage->all_doc_ids(), 'all_doc_ids should exclude tombstones by default');
        assert_same([101, 201, 202, 203], $storage->all_doc_ids(true), 'all_doc_ids(true) should include tombstones');
    });

    test_case('quality mysql optimize and delete SQL compact tombstoned postings', function (): void {
        $wpdb = new WP_FTS_Test_WPDB();
        $storage = new WP_FTS_Storage_Mysql($wpdb);
        $plAlpha = WP_FTS_TermNamespace::namespace_term('pl', 'alpha');
        $plBeta = WP_FTS_TermNamespace::namespace_term('pl', 'beta');
        $enAlpha = WP_FTS_TermNamespace::namespace_term('en', 'alpha');

        $storage->put_doc(301, 'pl', ['pl' => 3], 'hash-301');
        $storage->put_doc(302, 'pl', ['pl' => 2, 'en' => 1], 'hash-302');
        $storage->put_doc(303, 'en', ['en' => 4], 'hash-303');
        $storage->put_term($plAlpha, 2, WP_FTS_PostingsCodec::encode([301 => 2, 302 => 1]));
        $storage->put_term($plBeta, 1, WP_FTS_PostingsCodec::encode([301 => 1]));
        $storage->put_term($enAlpha, 2, WP_FTS_PostingsCodec::encode([302 => 1, 303 => 3]));
        $storage->delete_doc(301);

        assert_same([302 => 2], $storage->get_doc_lengths([301, 302], 'pl'), 'tombstone should be hidden before optimize');
        assert_true(isset($wpdb->terms[$plBeta]), 'term with only deleted postings should exist before optimize');

        $storage->optimize();

        assert_same([302, 303], $storage->all_doc_ids(true), 'optimize should remove tombstoned docs');
        assert_true(!isset($wpdb->docLengths[301]), 'optimize should delete doc-length rows for tombstones');
        assert_same([302 => 1], WP_FTS_PostingsCodec::decode($storage->get_terms([$plAlpha])[$plAlpha]['postings']), 'optimize should remove deleted postings from surviving terms');
        assert_true(!isset($wpdb->terms[$plBeta]), 'optimize should delete terms left with no postings');
        assert_true(!isset($wpdb->postings[$plBeta]), 'optimize should delete postings rows left with no active docs');
        assert_same([302 => 1, 303 => 3], WP_FTS_PostingsCodec::decode($storage->get_terms([$enAlpha])[$enAlpha]['postings']), 'optimize should preserve active postings in other languages');
        assert_same(['doc_count' => 1, 'len_sum' => 2], $storage->get_meta('pl'), 'optimize should rebuild Polish meta from active doc lengths');
        assert_same(['doc_count' => 2, 'len_sum' => 5], $storage->get_meta('en'), 'optimize should rebuild English meta from active doc lengths');

        $docLengthDelete = wp_fts_quality_last_prepared_like($wpdb, 'DELETE FROM wp_fts_doc_lengths WHERE doc_id IN');
        $docDelete = wp_fts_quality_last_prepared_like($wpdb, 'DELETE FROM wp_fts_docs WHERE doc_id IN');
        assert_same([301], $docLengthDelete['args'], 'optimize should prepare doc-length deletion for the tombstone');
        assert_same([301], $docDelete['args'], 'optimize should prepare doc deletion for the tombstone');

        $queryLog = implode("\n", $wpdb->queries);
        foreach ([
            'DELETE FROM wp_fts_postings WHERE doc_id IN',
            'DELETE FROM wp_fts_doc_lengths WHERE doc_id IN',
            'DELETE FROM wp_fts_docs WHERE doc_id IN',
            'DELETE FROM wp_fts_meta',
            'INSERT INTO wp_fts_meta',
        ] as $needle) {
            assert_contains($needle, $queryLog, "optimize should issue {$needle}");
        }
    });

    test_case('quality language inputs canonicalize consistently across mysql and cli', function (): void {
        $cases = [
            [' pl_PL ', 'pl-PL'],
            ['PT_br', 'pt-BR'],
            ['sr_Cyrl_RS', 'sr-Cyrl-RS'],
            ['zh-hant', 'zh-Hant'],
            ['es_419', 'es-419'],
            ['fr--CA', 'fr-CA'],
            ['x-private', 'x-private'],
            ['!!!', 'en'],
            ['', 'en'],
        ];

        foreach ($cases as [$input, $expected]) {
            assert_same($expected, WP_FTS_TermNamespace::canonicalize_lang($input, 'en'), "{$input} should canonicalize through TermNamespace");

            $wpdb = new WP_FTS_Test_WPDB();
            $storage = new WP_FTS_Storage_Mysql($wpdb);
            $storage->put_doc(400, $input, [$input === '' ? 'und' : $input => 1], 'hash-' . md5($input));
            $doc = $storage->get_doc(400);
            assert_same($expected, $doc['primary_lang'], "{$input} should canonicalize on put_doc primary language");

            $fake = new WP_FTS_Test_WPDB();
            $fake->postRows = [
                (object) ['ID' => 401, 'post_title' => 'Title', 'post_content' => '<p>alpha beta</p>'],
            ];
            wp_fts_quality_reset_cli();
            wp_fts_quality_with_wpdb($fake, static function () use ($input): void {
                $command = new WP_FTS_WPCLI_Command();
                $command->reindex([], ['language' => $input, 'limit' => '1']);
            });

            $cliExpected = trim($input) === '' ? WP_FTS_TermNamespace::DEFAULT_LANG : $expected;
            assert_same($cliExpected, $fake->docs[401]['lang'], "{$input} should canonicalize through WP-CLI language alias");
            assert_same(["Indexed 1 posts in {$cliExpected}."], WP_CLI::$successMessages, "{$input} CLI success should report canonical language");
        }
    });

    test_case('quality wp cli reindex covers aliases limits batches and post filters', function (): void {
        $scenarios = [
            [
                'label' => 'missing filters default admin searchable statuses',
                'args' => [
                    'language' => 'en',
                    'limit' => '5',
                    'batch_size' => '5',
                ],
                'rows' => [
                    (object) ['ID' => 451, 'post_title' => 'Published', 'post_content' => '<p>alpha status</p>', 'post_excerpt' => '', 'post_type' => 'post', 'post_status' => 'publish'],
                    (object) ['ID' => 452, 'post_title' => 'Draft', 'post_content' => '<p>beta status</p>', 'post_excerpt' => '', 'post_type' => 'post', 'post_status' => 'draft'],
                    (object) ['ID' => 453, 'post_title' => 'Pending', 'post_content' => '<p>gamma status</p>', 'post_excerpt' => '', 'post_type' => 'post', 'post_status' => 'pending'],
                    (object) ['ID' => 454, 'post_title' => 'Future', 'post_content' => '<p>delta status</p>', 'post_excerpt' => '', 'post_type' => 'post', 'post_status' => 'future'],
                    (object) ['ID' => 455, 'post_title' => 'Private', 'post_content' => '<p>epsilon status</p>', 'post_excerpt' => '', 'post_type' => 'post', 'post_status' => 'private'],
                ],
                'expected_docs' => [451, 452, 453, 454, 455],
                'expected_first_args' => ['publish', 'draft', 'pending', 'future', 'private', 'post', 0, 5],
                'expected_last_args' => ['publish', 'draft', 'pending', 'future', 'private', 'post', 0, 5],
                'message' => 'Indexed 5 posts in en.',
                'lang' => 'en',
            ],
            [
                'label' => 'explicit publish remains narrow',
                'args' => [
                    'post_status' => 'publish',
                    'post_type' => 'post',
                    'language' => 'en',
                    'limit' => '1',
                    'batch_size' => '1',
                ],
                'rows' => [
                    (object) ['ID' => 461, 'post_title' => 'Published', 'post_content' => '<p>alpha public</p>', 'post_excerpt' => '', 'post_type' => 'post', 'post_status' => 'publish'],
                ],
                'expected_docs' => [461],
                'expected_first_args' => ['publish', 'post', 0, 1],
                'expected_last_args' => ['publish', 'post', 0, 1],
                'message' => 'Indexed 1 posts in en.',
                'lang' => 'en',
            ],
            [
                'label' => 'dash aliases limited batches',
                'args' => [
                    'post-status' => 'publish, private ,,',
                    'post-type' => 'post,page',
                    'language' => 'pt_BR',
                    'limit' => '2',
                    'batch-size' => '1',
                ],
                'rows' => [
                    (object) ['ID' => 501, 'post_title' => 'First', 'post_content' => '<p>alpha beta</p>'],
                    (object) ['ID' => 502, 'post_title' => 'Second', 'post_content' => '<p>gamma delta</p>'],
                    (object) ['ID' => 503, 'post_title' => 'Third', 'post_content' => '<p>epsilon zeta</p>'],
                ],
                'expected_docs' => [501, 502],
                'expected_first_args' => ['publish', 'private', 'post', 'page', 0, 1],
                'expected_last_args' => ['publish', 'private', 'post', 'page', 501, 1],
                'message' => 'Indexed 2 posts in pt-BR.',
                'lang' => 'pt-BR',
            ],
            [
                'label' => 'underscore aliases unlimited',
                'args' => [
                    'post_status' => 'draft',
                    'post_type' => 'book,movie',
                    'lang' => 'es_419',
                    'limit' => '0',
                    'batch_size' => '3',
                ],
                'rows' => [
                    (object) ['ID' => 601, 'post_title' => 'Libro', 'post_content' => '<p>color forma</p>'],
                    (object) ['ID' => 602, 'post_title' => 'Pelicula', 'post_content' => '<p>color ritmo</p>'],
                ],
                'expected_docs' => [601, 602],
                'expected_first_args' => ['draft', 'book', 'movie', 0, 3],
                'expected_last_args' => ['draft', 'book', 'movie', 602, 3],
                'message' => 'Indexed 2 posts in es-419.',
                'lang' => 'es-419',
            ],
            [
                'label' => 'invalid numbers and empty filters fall back',
                'args' => [
                    'post_status' => ',,',
                    'post_type' => ',,',
                    'lang' => 'de_DE',
                    'limit' => 'not-a-number',
                    'batch_size' => '-10',
                ],
                'rows' => [
                    (object) ['ID' => 701, 'post_title' => 'Ein', 'post_content' => '<p>alpha beta</p>'],
                ],
                'expected_docs' => [701],
                'expected_first_args' => ['publish', 'draft', 'pending', 'future', 'private', 'post', 0, 1],
                'expected_last_args' => ['publish', 'draft', 'pending', 'future', 'private', 'post', 701, 1],
                'message' => 'Indexed 1 posts in de-DE.',
                'lang' => 'de-DE',
            ],
        ];

        foreach ($scenarios as $scenario) {
            $fake = new WP_FTS_Test_WPDB();
            $fake->postRows = $scenario['rows'];
            wp_fts_quality_reset_cli();
            wp_fts_quality_with_wpdb($fake, static function () use ($scenario): void {
                $command = new WP_FTS_WPCLI_Command();
                $command->reindex([], $scenario['args']);
            });

            assert_same($scenario['expected_docs'], array_keys($fake->docs), "{$scenario['label']} should index the expected post IDs");
            foreach ($scenario['expected_docs'] as $docId) {
                assert_same($scenario['lang'], $fake->docs[$docId]['lang'], "{$scenario['label']} should store canonical language for {$docId}");
                assert_true(($fake->docs[$docId]['doc_len'] ?? 0) > 0, "{$scenario['label']} should index non-empty content for {$docId}");
            }
            assert_same([$scenario['message']], WP_CLI::$successMessages, "{$scenario['label']} should report indexed count");

            $selects = wp_fts_quality_prepared_like($fake, 'SELECT ID, post_content, post_title');
            assert_true($selects !== [], "{$scenario['label']} should prepare at least one source query");
            assert_same($scenario['expected_first_args'], $selects[0]['args'], "{$scenario['label']} first batch args should include filters and batch size");
            assert_same($scenario['expected_last_args'], $selects[count($selects) - 1]['args'], "{$scenario['label']} last batch args should track last ID and remaining limit");
        }

        $empty = new WP_FTS_Test_WPDB();
        wp_fts_quality_reset_cli();
        wp_fts_quality_with_wpdb($empty, static function (): void {
            $command = new WP_FTS_WPCLI_Command();
            $command->reindex([], ['lang' => 'pl', 'limit' => '5', 'batch_size' => '2']);
        });
        assert_same([], $empty->docs, 'reindex should tolerate missing posts without writes');
        assert_same(['Indexed 0 posts in pl.'], WP_CLI::$successMessages, 'reindex should report zero missing posts');
    });

    test_case('quality wp cli handles empty mixed deleted and reindexed posts', function (): void {
        $fake = new WP_FTS_Test_WPDB();
        $fake->postRows = [
            (object) ['ID' => 801, 'post_title' => '', 'post_content' => ''],
            (object) ['ID' => 802, 'post_title' => 'Mixed', 'post_content' => '<p lang="pl">zamek alfa</p><p lang="en">castle beta</p>'],
        ];
        wp_fts_quality_reset_cli();
        wp_fts_quality_with_wpdb($fake, static function (): void {
            $command = new WP_FTS_WPCLI_Command();
            $command->reindex([], ['limit' => '2', 'batch_size' => '2']);
        });

        assert_same([801, 802], array_keys($fake->docs), 'CLI should write docs for empty and mixed-language posts');
        assert_same(0, $fake->docs[801]['doc_len'], 'empty post should write a zero-length doc record');
        assert_same([], $fake->docLengths[801] ?? [], 'empty post should not create language length rows');
        assert_true(isset($fake->docLengths[802]['pl']), 'mixed post should create a Polish length partition');
        assert_true(isset($fake->docLengths[802]['en']), 'mixed post should create an English length partition');
        assert_true($fake->docLengths[802]['pl'] > 0, 'mixed post Polish partition should have positive length');
        assert_true($fake->docLengths[802]['en'] > 0, 'mixed post English partition should have positive length');

        wp_fts_quality_reset_cli();
        wp_fts_quality_with_wpdb($fake, static function (): void {
            $command = new WP_FTS_WPCLI_Command();
            $command->delete([802], []);
            $command->delete([999], []);
        });
        assert_true($fake->docs[802]['is_deleted'] === 1, 'CLI delete should tombstone indexed posts');
        assert_same(['Deleted document 802.'], WP_CLI::$successMessages, 'CLI delete should report existing post deletion');
        assert_true(!isset($fake->docs[999]), 'CLI delete should leave missing posts absent');

        $fake->postRows = [
            (object) ['ID' => 802, 'post_title' => 'Reindexed', 'post_content' => '<p lang="pl">zamek nowy</p>'],
        ];
        wp_fts_quality_reset_cli();
        wp_fts_quality_with_wpdb($fake, static function (): void {
            $command = new WP_FTS_WPCLI_Command();
            $command->reindex([], ['lang' => 'pl', 'limit' => '1']);
        });
        assert_true($fake->docs[802]['is_deleted'] === 0, 'reindex should clear a prior tombstone');
        assert_same('pl', $fake->docs[802]['lang'], 'reindex should rewrite the requested language');
        assert_true(isset($fake->docLengths[802]['pl']), 'reindex should restore language lengths');
    });

    test_case('quality wp cli search prepares language limit mode and formats rows', function (): void {
        $fake = new WP_FTS_Test_WPDB();
        $plTerm = WP_FTS_TermNamespace::namespace_term('pl-PL', 'zamek');
        $enTerm = WP_FTS_TermNamespace::namespace_term('en', 'castl');
        $fake->terms[$plTerm] = ['doc_freq' => 2];
        $fake->terms[$enTerm] = ['doc_freq' => 1];
        $fake->postings[$plTerm] = [901 => 3, 902 => 1];
        $fake->postings[$enTerm] = [903 => 2];
        $fake->docs[901] = wp_fts_quality_doc_row('pl-PL', 3, 'hash-901');
        $fake->docs[902] = wp_fts_quality_doc_row('pl-PL', 7, 'hash-902');
        $fake->docs[903] = wp_fts_quality_doc_row('en', 2, 'hash-903');
        $fake->docLengths[901] = ['pl-PL' => 3];
        $fake->docLengths[902] = ['pl-PL' => 7];
        $fake->docLengths[903] = ['en' => 2];
        $fake->meta['pl-PL'] = ['doc_count' => 2, 'len_sum' => 10];
        $fake->meta['en'] = ['doc_count' => 1, 'len_sum' => 2];
        $fake->docMeta[901] = [
            'doc_id' => 901,
            'post_id' => 901,
            'post_type' => 'post',
            'post_status' => 'publish',
            'post_date_gmt' => '2026-04-01 00:00:00',
            'title' => 'Zamek Published',
            'excerpt' => '',
            'search_text' => 'Zamek published snippet source',
            'data' => json_encode([
                'post_id' => 901,
                'post_type' => 'post',
                'post_status' => 'publish',
                'post_date_gmt' => '2026-04-01 00:00:00',
                'title' => 'Zamek Published',
                'search_text' => 'Zamek published snippet source',
            ], JSON_THROW_ON_ERROR),
        ];
        $fake->docMeta[902] = [
            'doc_id' => 902,
            'post_id' => 902,
            'post_type' => 'page',
            'post_status' => 'draft',
            'post_date_gmt' => '2026-04-02 00:00:00',
            'title' => 'Zamek Draft',
            'excerpt' => '',
            'search_text' => 'Zamek draft snippet source',
            'data' => json_encode([
                'post_id' => 902,
                'post_type' => 'page',
                'post_status' => 'draft',
                'post_date_gmt' => '2026-04-02 00:00:00',
                'title' => 'Zamek Draft',
                'search_text' => 'Zamek draft snippet source',
            ], JSON_THROW_ON_ERROR),
        ];
        $fake->docMeta[903] = [
            'doc_id' => 903,
            'post_id' => 903,
            'post_type' => 'post',
            'post_status' => 'publish',
            'post_date_gmt' => '2026-04-03 00:00:00',
            'title' => 'Castle Published',
            'excerpt' => '',
            'search_text' => 'Castle published snippet source',
            'data' => json_encode([
                'post_id' => 903,
                'post_type' => 'post',
                'post_status' => 'publish',
                'post_date_gmt' => '2026-04-03 00:00:00',
                'title' => 'Castle Published',
                'search_text' => 'Castle published snippet source',
            ], JSON_THROW_ON_ERROR),
        ];

        wp_fts_quality_reset_cli();
        wp_fts_quality_with_wpdb($fake, static function (): void {
            $command = new WP_FTS_WPCLI_Command();
            $command->search(['zamek'], [
                'language' => 'pl_PL',
                'limit' => '1',
                'mode' => 'OR',
                'post_status' => 'publish',
                'post_type' => 'post',
                'snippet' => true,
            ]);
        });

        $formats = $GLOBALS['wp_fts_quality_cli_format_items'] ?? [];
        assert_same(1, count($formats), 'search should format one result table');
        assert_same('table', $formats[0]['format'], 'search should request table output');
        assert_same(['doc_id', 'score', 'total', 'post_id', 'post_type', 'post_status', 'post_date_gmt', 'title', 'snippet'], $formats[0]['fields'], 'search should expose product metadata fields');
        assert_same([901], array_column($formats[0]['items'], 'doc_id'), 'search filters should keep the published Polish post');
        assert_same(1, $formats[0]['items'][0]['total'], 'search total should reflect metadata filters');
        assert_same('Zamek Published', $formats[0]['items'][0]['title'], 'search rows should include stored title metadata');
        assert_contains('Zamek published', $formats[0]['items'][0]['snippet'], 'search rows should include snippets when requested');

        $postingSelect = wp_fts_quality_last_prepared_like($fake, 'SELECT term, doc_id, tf FROM wp_fts_postings');
        assert_same([$plTerm], $postingSelect['args'], 'search should prepare a language-namespaced postings lookup');
        $lengthSelect = wp_fts_quality_last_prepared_like($fake, 'SELECT dl.doc_id, dl.doc_len FROM wp_fts_doc_lengths');
        assert_same('pl-PL', $lengthSelect['args'][0], 'search language alias should canonicalize before doc-length lookup');

        wp_fts_quality_reset_cli();
        wp_fts_quality_with_wpdb($fake, static function (): void {
            $command = new WP_FTS_WPCLI_Command();
            $command->search(['castle'], ['lang' => 'en', 'limit' => '5', 'mode' => 'AND']);
        });
        $formats = $GLOBALS['wp_fts_quality_cli_format_items'] ?? [];
        assert_same([903], array_column($formats[0]['items'], 'doc_id'), 'search should use --lang alias for English partition');

        $thrown = false;
        wp_fts_quality_with_wpdb($fake, static function () use (&$thrown): void {
            try {
                $command = new WP_FTS_WPCLI_Command();
                $command->search(['zamek'], ['lang' => 'pl', 'mode' => 'XOR']);
            } catch (InvalidArgumentException) {
                $thrown = true;
            }
        });
        assert_true($thrown, 'invalid search mode should be rejected');

        wp_fts_quality_reset_cli();
        wp_fts_quality_with_wpdb($fake, static function (): void {
            $command = new WP_FTS_WPCLI_Command();
            $command->search(['missing'], ['lang' => 'pl']);
        });
        $formats = $GLOBALS['wp_fts_quality_cli_format_items'] ?? [];
        assert_same([], $formats[0]['items'], 'missing search terms should format an empty result set');
    });

    test_case('quality wp cli register is explicit and plugin runtime hooks are WordPress scoped', function (): void {
        $GLOBALS['wp_fts_quality_add_action_calls'] = [];
        wp_fts_quality_reset_cli();

        require_once dirname(__DIR__, 2) . '/indexer.php';
        WP_FTS_Plugin::register_hooks();
        $hooks = array_column($GLOBALS['wp_fts_quality_add_action_calls'], 'hook');
        sort($hooks, SORT_STRING);
        $expectedHooks = [
            WP_FTS_Plugin::CRON_HOOK,
            'add_meta_boxes',
            'admin_menu',
            'before_delete_post',
            'loop_end',
            'loop_start',
            'pre_get_posts',
            'pre_get_posts',
            'rest_api_init',
            'save_post',
            'save_post',
            'transition_post_status',
            'trashed_post',
            'wp_after_insert_post',
        ];
        sort($expectedHooks, SORT_STRING);
        assert_same($expectedHooks, $hooks, 'plugin runtime hooks should be registered through WordPress add_action');

        WP_FTS_WPCLI_Command::register();
        assert_same(['fts' => WP_FTS_WPCLI_Command::class], WP_CLI::$commands, 'explicit WP-CLI registration should keep the command control plane');
        $afterCliHooks = array_column($GLOBALS['wp_fts_quality_add_action_calls'], 'hook');
        sort($afterCliHooks, SORT_STRING);
        assert_same($hooks, $afterCliHooks, 'explicit WP-CLI registration should not add indexing hooks');
    });
}
