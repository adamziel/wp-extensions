<?php
declare(strict_types=1);

/** Invoke one private plugin search-output boundary. */
function wp_fts_strict_search_output_private(string $method, mixed ...$arguments): mixed
{
    $reflection = new ReflectionMethod(WP_FTS_Plugin::class, $method);
    $reflection->setAccessible(true);

    return $reflection->invoke(null, ...$arguments);
}

/** Capture one strict search-output rejection. */
function wp_fts_strict_search_output_caught(callable $callback): ?Throwable
{
    try {
        $callback();
    } catch (Throwable $error) {
        return $error;
    }

    return null;
}

test_case('strict search output requires the exact normalized component page', function (): void {
    $validRow = ['doc_id' => 7, 'score' => 1.5];
    $validPayload = [
        'query_lang' => 'en',
        'has_more' => false,
        'next_cursor' => null,
        'previous_cursor' => null,
        'results' => [$validRow],
    ];

    assert_same(
        null,
        wp_fts_strict_search_output_private(
            'assert_normalized_search_payload',
            $validPayload,
            1,
            false,
            false
        ),
        'the exact normalized component page should pass unchanged'
    );

    $invalidPayloads = [
        'a missing field' => array_diff_key($validPayload, ['has_more' => true]),
        'an extra field' => $validPayload + ['unknown' => true],
        'reordered fields' => [
            'has_more' => false,
            'query_lang' => 'en',
            'next_cursor' => null,
            'previous_cursor' => null,
            'results' => [$validRow],
        ],
        'a non-boolean continuation flag' => array_replace($validPayload, ['has_more' => 0]),
        'an integer cursor' => array_replace($validPayload, ['next_cursor' => 1]),
        'an empty cursor' => array_replace($validPayload, ['next_cursor' => '']),
        'a padded cursor' => array_replace($validPayload, ['next_cursor' => ' opaque ']),
        'an oversized cursor' => array_replace($validPayload, ['next_cursor' => str_repeat('c', 2049)]),
        'a non-string language' => array_replace($validPayload, ['query_lang' => 1]),
        'a padded language' => array_replace($validPayload, ['query_lang' => ' en']),
        'a noncanonical language' => array_replace($validPayload, ['query_lang' => 'EN']),
        'a malformed language' => array_replace($validPayload, ['query_lang' => 'e!n']),
        'an associative result collection' => array_replace($validPayload, [
            'results' => ['first' => $validRow],
        ]),
        'a page above its limit' => array_replace($validPayload, [
            'results' => [$validRow, ['doc_id' => 8, 'score' => 1.0]],
        ]),
        'a scalar result row' => array_replace($validPayload, ['results' => ['row']]),
        'a string document ID' => array_replace($validPayload, [
            'results' => [['doc_id' => '7', 'score' => 1.5]],
        ]),
        'a zero document ID' => array_replace($validPayload, [
            'results' => [['doc_id' => 0, 'score' => 1.5]],
        ]),
        'an integer score' => array_replace($validPayload, [
            'results' => [['doc_id' => 7, 'score' => 1]],
        ]),
        'a string score' => array_replace($validPayload, [
            'results' => [['doc_id' => 7, 'score' => '1.5']],
        ]),
        'a NAN score' => array_replace($validPayload, [
            'results' => [['doc_id' => 7, 'score' => NAN]],
        ]),
        'an infinite score' => array_replace($validPayload, [
            'results' => [['doc_id' => 7, 'score' => INF]],
        ]),
        'an unrequested canonical post row' => array_replace($validPayload, [
            'results' => [[
                'doc_id' => 7,
                'score' => 1.5,
                '_canonical_post_row' => [],
            ]],
        ]),
    ];
    foreach ($invalidPayloads as $label => $payload) {
        $error = wp_fts_strict_search_output_caught(
            static fn(): mixed => wp_fts_strict_search_output_private(
                'assert_normalized_search_payload',
                $payload,
                1,
                false,
                false
            )
        );
        assert_true(
            $error instanceof LogicException,
            "the plugin must reject a normalized component page with {$label}"
        );
    }
    $duplicatePayload = array_replace($validPayload, ['results' => [$validRow, $validRow]]);
    assert_true(
        wp_fts_strict_search_output_caught(
            static fn(): mixed => wp_fts_strict_search_output_private(
                'assert_normalized_search_payload',
                $duplicatePayload,
                2,
                false,
                false
            )
        ) instanceof LogicException,
        'the plugin must reject duplicate document IDs inside one admitted page'
    );

    $validExplain = [
        'storage' => 'set_oriented',
        'logical_group_count' => 1,
        'resolved_alternatives' => 1,
        'anchor_group' => 0,
        'prefix_range' => false,
        'prefix_strategy' => 'none',
        'query_statements' => 2,
        'interactive_total' => 'unknown',
        'recency_boost' => [
            'enabled' => false,
            'strength' => 0.0,
            'half_life_days' => 30.0,
            'scoring_now_gmt' => '',
        ],
        'canonical_page_bytes' => 0,
    ];
    $explainPayload = $validPayload + ['explain' => $validExplain];
    assert_same(
        null,
        wp_fts_strict_search_output_private(
            'assert_normalized_search_payload',
            $explainPayload,
            1,
            true,
            false
        ),
        'an exact requested explain payload should remain valid'
    );
    $invalidExplain = array_replace($explainPayload, ['explain' => 'explain']);
    assert_true(
        wp_fts_strict_search_output_caught(
            static fn(): mixed => wp_fts_strict_search_output_private(
                'assert_normalized_search_payload',
                $invalidExplain,
                1,
                true,
                false
            )
        ) instanceof LogicException,
        'the plugin must reject a non-array requested explain payload'
    );
    $partialExplain = array_replace($explainPayload, ['explain' => ['storage' => 'set_oriented']]);
    assert_true(
        wp_fts_strict_search_output_caught(
            static fn(): mixed => wp_fts_strict_search_output_private(
                'assert_normalized_search_payload',
                $partialExplain,
                1,
                true,
                false
            )
        ) instanceof LogicException,
        'the plugin must reject a partial explain map'
    );

    $canonicalPostRow = array_fill_keys(WP_FTS_Index_Queue::CANONICAL_POST_COLUMNS, '');
    $canonicalPostRow['ID'] = 7;
    $canonicalPayload = array_replace($validPayload, [
        'results' => [[
            'doc_id' => 7,
            'score' => 1.5,
            '_canonical_post_row' => $canonicalPostRow,
        ]],
    ]);
    assert_same(
        null,
        wp_fts_strict_search_output_private(
            'assert_normalized_search_payload',
            $canonicalPayload,
            1,
            false,
            true
        ),
        'an exact canonical post sidecar should remain valid for the private adapter'
    );
    $invalidCanonicalPayload = array_replace($canonicalPayload, [
        'results' => [[
            'doc_id' => 7,
            'score' => 1.5,
            '_canonical_post_row' => $canonicalPostRow + ['unknown' => 'field'],
        ]],
    ]);
    assert_true(
        wp_fts_strict_search_output_caught(
            static fn(): mixed => wp_fts_strict_search_output_private(
                'assert_normalized_search_payload',
                $invalidCanonicalPayload,
                1,
                false,
                true
            )
        ) instanceof LogicException,
        'the private adapter must reject a canonical post sidecar with an extra field'
    );
    $mismatchedCanonicalPayload = $canonicalPayload;
    $mismatchedCanonicalPayload['results'][0]['_canonical_post_row']['ID'] = 8;
    assert_true(
        wp_fts_strict_search_output_caught(
            static fn(): mixed => wp_fts_strict_search_output_private(
                'assert_normalized_search_payload',
                $mismatchedCanonicalPayload,
                1,
                false,
                true
            )
        ) instanceof LogicException,
        'the private adapter must reject a mismatched canonical post ID'
    );
});
