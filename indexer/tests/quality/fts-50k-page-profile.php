<?php
declare(strict_types=1);

require_once __DIR__ . '/../benchmarks/fts-50k-page-profile.php';

test_case('quality 50k page profile exposes CI guard and row-postings indexing path', function (): void {
    $profiles = WP_FTS_FiftyK_Page_Profile_Benchmark::profiles();
    assert_true(isset($profiles['ci'], $profiles['50k']), '50k page profile benchmark should expose CI and manual 50k profiles');
    assert_true($profiles['50k']['documents'] === 50000, 'manual 50k profile should index 50,000 documents');

    $result = WP_FTS_FiftyK_Page_Profile_Benchmark::run('ci', [
        'documents' => 128,
        'iterations' => 1,
        'warmup' => 0,
    ]);

    assert_true((bool) $result['passed'], 'CI-sized 50k page profile should pass');
    assert_same(128, (int) $result['indexing']['indexed_documents'], 'CI-sized profile should index configured documents');
    assert_true((int) $result['storage']['term_count'] > 0, 'CI-sized profile should store terms');
    assert_true((int) $result['storage']['posting_count'] > 0, 'CI-sized profile should store postings');

    $storageTimers = $result['storage_timers'];
    assert_same(128, (int) ($storageTimers['replace_doc_postings']['calls'] ?? 0), 'in-memory profile indexing should use row-postings replacement');
    assert_true(!isset($storageTimers['put_term']), 'in-memory profile indexing should not rewrite per-term posting blobs');

    record_check('50k page profile search queries', count($result['search_queries']));
});

test_case('quality in-memory row postings rollback restores compatibility state', function (): void {
    $storage = new WP_FTS_Storage_InMemory();

    $storage->begin_transaction();
    $storage->replace_doc_postings(101, [
        'en:alpha' => 2,
        'en:beta' => 1,
    ]);
    $storage->put_doc(101, 'en', ['en' => 3], 'hash-a');
    $storage->put_doc_metadata(101, ['title' => 'Original']);
    $storage->commit();

    assert_same(['en:alpha', 'en:beta'], $storage->terms_for_doc(101), 'committed row postings should expose document terms');
    assert_same(['en:alpha' => [101 => 2]], $storage->get_postings(['en:alpha']), 'committed row postings should be readable without encoded term blobs');

    $storage->begin_transaction();
    $storage->replace_doc_postings(101, ['en:gamma' => 4]);
    $storage->put_doc(101, 'en', ['en' => 4], 'hash-b');
    $storage->put_doc_metadata(101, ['title' => 'Changed']);
    $storage->rollback();

    assert_same(['en:alpha', 'en:beta'], $storage->terms_for_doc(101), 'rollback should restore reverse document-term mappings');
    assert_same(['en:alpha' => [101 => 2]], $storage->get_postings(['en:alpha']), 'rollback should restore decoded postings');
    assert_same([], $storage->get_postings(['en:gamma']), 'rollback should remove newly added decoded postings');
    assert_same([101 => 2], WP_FTS_PostingsCodec::decode($storage->get_terms(['en:alpha'])['en:alpha']['postings']), 'rollback should keep encoded compatibility rows coherent');
    assert_same('hash-a', $storage->get_doc(101)['content_hash'] ?? null, 'rollback should restore document rows');
    assert_same('Original', $storage->get_doc_metadata([101])[101]['title'] ?? null, 'rollback should restore document metadata');

    $storage->begin_transaction();
    $storage->delete_doc(101);
    $storage->optimize();
    assert_same([], $storage->all_doc_ids(true), 'optimize inside transaction should compact tombstones before rollback');
    $storage->rollback();

    assert_same([101], $storage->all_doc_ids(true), 'rollback should restore optimized documents');
    assert_same(['en:alpha', 'en:beta'], $storage->terms_for_doc(101), 'rollback should restore optimized reverse term mappings');
    assert_same(['en:beta' => [101 => 1]], $storage->get_postings(['en:beta']), 'rollback should restore optimized postings');
});
