<?php
declare(strict_types=1);

test_case('bounded term previews use deterministic per-document limits', function (): void {
    $wpdb = new WP_FTS_Test_WPDB();
    $storage = new WP_FTS_Storage_Mysql($wpdb);

    $storage->terms_for_docs([9, 3, 9, 0, -4], 300);

    assert_same(1, count($wpdb->prepared), 'one bounded term-preview call should prepare one statement');
    $prepared = $wpdb->prepared[0];
    $sql = $prepared['sql'];

    assert_same(2, substr_count($sql, 'WHERE p.post_id = %d'), 'duplicate and non-positive document ids should not add UNION branches');
    assert_same(2, substr_count($sql, "ORDER BY t.lang, t.term\nLIMIT %d"), 'each document branch should enforce its own lexical limit');
    assert_same(1, substr_count($sql, 'UNION ALL'), 'two requested documents should be combined in one statement');
    assert_contains('ORDER BY bounded.post_id, bounded.lang, bounded.term', $sql, 'the bounded union should have deterministic result ordering');
    assert_same([9, 256, 3, 256], $prepared['args'], 'document ids and the clamped per-document limit should bind branch by branch');

    foreach (['@wp_fts_', 'ROW_NUMBER()', 'term_rank', 'CROSS JOIN'] as $optimizerSensitiveShape) {
        assert_true(
            !str_contains($sql, $optimizerSensitiveShape),
            "bounded term previews should not contain {$optimizerSensitiveShape}"
        );
    }
});

test_case('bounded term previews avoid a database call for an empty document set', function (): void {
    $wpdb = new WP_FTS_Test_WPDB();
    $storage = new WP_FTS_Storage_Mysql($wpdb);

    assert_same([], $storage->terms_for_docs([0, -1], 24), 'an empty normalized document set should return no previews');
    assert_same([], $wpdb->prepared, 'an empty normalized document set should prepare no SQL');
});
