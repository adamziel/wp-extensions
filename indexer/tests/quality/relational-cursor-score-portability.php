<?php
declare(strict_types=1);

test_case_with_pdo_sqlite_fixture('relational cursor scores remain exact above the 32-bit PHP integer ceiling', function (): void {
    [$wpdb, $storage] = wp_fts_relational_regression_search_fixture();
    $groups = [];
    for ($postId = 1; $postId <= 4; $postId++) {
        wp_fts_relational_regression_add_post($wpdb, $postId, '2026-07-19 00:00:00');
        $term = "rare{$postId}";
        wp_fts_relational_regression_add_term($wpdb, $term, [$postId => 4096.0]);
        $groups[] = [[
            'key' => WP_FTS_TermNamespace::namespace_term('en', $term),
            'rank' => 0,
        ]];
    }

    $options = wp_fts_relational_regression_search_options(2);
    $first = $storage->search_page($groups, $options);
    assert_same([4, 3], array_column($first['results'], 'doc_id'), 'the first equal-score page should use the date/id tiebreakers');
    assert_true(is_string($first['next_cursor'] ?? null), 'the first rare-term page should issue a continuation cursor');

    $payload = wp_fts_cursor_score_payload((string) $first['next_cursor']);
    assert_same('4096000000', $payload['s'] ?? null, 'scores above INT32_MAX must be encoded as exact decimal text');
    assert_true(is_string($payload['s'] ?? null), 'cursor JSON must not ask platform-sized PHP integers to carry SQL scores');

    $second = $storage->search_page($groups, array_replace($options, [
        'cursor' => $first['next_cursor'],
    ]));
    assert_same([2, 1], array_column($second['results'], 'doc_id'), 'an above-INT32 cursor must not skip the remaining equal-score rows');
    assert_same(false, $second['has_more'], 'the second rare-term page should exhaust the four-row fixture');
    $rankSql = wp_fts_relational_regression_last_rank_sql($wpdb);
    assert_contains("CAST('4096000000' AS INTEGER)", $rankSql, 'SQLite must compare the authenticated decimal boundary as an exact SQL integer');
    assert_true(!str_contains($rankSql, '2147483647'), 'cursor preparation must never clamp a SQL score to the 32-bit PHP integer ceiling');

    $mysqlStorage = new WP_FTS_Relational_Storage(new WP_FTS_Test_WPDB());
    $buildRankQuery = new ReflectionMethod(WP_FTS_Relational_Storage::class, 'build_rank_query');
    $rank = $buildRankQuery->invoke(
        $mysqlStorage,
        [[['term_id' => 1, 'weight' => 1000000, 'doc_freq' => 1]]],
        1,
        null,
        'OR',
        array_replace($options, [
            'page_size' => 2,
            '_search_epoch_generation' => 1,
            '_search_epoch_incarnation' => wp_fts_relational_regression_epoch_incarnation(),
        ]),
        [
            'score' => '4096000000',
            'date' => '2026-07-19 00:00:00',
            'post_id' => 3,
            'now_gmt' => '',
        ]
    );
    assert_same(3, substr_count((string) $rank['sql'], 'CAST(%s AS SIGNED)'), 'MySQL must bind each lexicographic score comparison through a decimal-string placeholder');
    assert_same(3, count(array_filter($rank['args'], static fn(mixed $arg): bool => $arg === '4096000000')), 'all MySQL score bindings must preserve the exact authenticated decimal');
    assert_true(!str_contains((string) $rank['sql'], 'scored.score < %d'), 'MySQL score predicates must not use platform-sized integer formatting');
});

test_case('relational cursor score payloads reject malformed and out-of-range decimals', function (): void {
    $storage = new WP_FTS_Relational_Storage(new WP_FTS_Test_WPDB());
    $decode = new ReflectionMethod(WP_FTS_Relational_Storage::class, 'decode_cursor');
    $fingerprint = str_repeat('f', 64);

    foreach (['', '-1', '+1', '01', '1.0', '1e3', '9007199254740992', '9999999999999999'] as $score) {
        $cursor = wp_fts_cursor_score_signed_cursor($storage, $score, $fingerprint);
        $error = null;
        try {
            $decode->invoke($storage, $cursor, $fingerprint);
        } catch (Throwable $caught) {
            $error = $caught;
        }
        assert_true($error instanceof InvalidArgumentException, "signed cursor score " . var_export($score, true) . ' must fail closed');
        assert_contains('cursor', strtolower($error instanceof Throwable ? $error->getMessage() : ''), 'malformed cursor scores should remain cursor validation failures');
    }

    $fractional = wp_fts_cursor_score_signed_cursor($storage, 1.5, $fingerprint);
    $fractionalError = null;
    try {
        $decode->invoke($storage, $fractional, $fingerprint);
    } catch (Throwable $caught) {
        $fractionalError = $caught;
    }
    assert_true($fractionalError instanceof InvalidArgumentException, 'signed JSON numbers with fractional score semantics must fail closed');

    $maximum = wp_fts_cursor_score_signed_cursor($storage, '9007199254740991', $fingerprint);
    $decodedMaximum = $decode->invoke($storage, $maximum, $fingerprint);
    assert_same('9007199254740991', $decodedMaximum['score'] ?? null, 'the cross-driver exact-integer ceiling must remain a portable decimal string');

});

test_case('relational cursor score ceiling stays exact across supported database drivers', function (): void {
    $storage = new WP_FTS_Relational_Storage(new WP_FTS_Test_WPDB());
    $reflection = new ReflectionClass(WP_FTS_Relational_Storage::class);
    $constant = static function (ReflectionClass $class, string $name): mixed {
        $value = $class->getReflectionConstant($name);
        if (!$value instanceof ReflectionClassConstant) {
            throw new RuntimeException("Missing cursor score invariant constant {$name}.");
        }

        return $value->getValue();
    };

    $maximumImpact = (int) $constant($reflection, 'MAX_POSTING_IMPACT');
    $rarityScale = (int) $constant($reflection, 'RARITY_SCALE');
    $primaryWeight = (int) $constant($reflection, 'PRIMARY_WEIGHT');
    $secondaryWeight = (int) $constant($reflection, 'SECONDARY_WEIGHT');
    $prefixWeight = (int) $constant($reflection, 'PREFIX_WEIGHT');
    $maximumRecencyStrength = (float) $constant($reflection, 'MAX_RECENCY_BOOST_STRENGTH');
    $cursorMaximum = (string) $constant($reflection, 'MAX_CURSOR_SCORE');

    assert_same(65535, $maximumImpact, 'the cursor envelope should remain tied to the unsigned SMALLINT posting impact');
    assert_same($primaryWeight, max($primaryWeight, $secondaryWeight, $prefixWeight), 'the primary tier must remain the maximum ranking weight used by the cursor envelope');
    $calculatedMaximum = sprintf(
        '%.0F',
        $maximumImpact
            * $rarityScale
            * ($primaryWeight / 1000)
            * WP_FTS_Set_Oriented_Search_Storage::MAX_QUERY_GROUPS
            * (1.0 + $maximumRecencyStrength)
    );
    assert_same('2359260000000', $calculatedMaximum, 'the current physical and scoring envelopes must retain their measured maximum');
    assert_same('9007199254740991', $cursorMaximum, 'the cursor ceiling must remain the largest integer every supported driver carries exactly');

    $schemaContract = new ReflectionMethod(WP_FTS_Relational_Storage::class, 'schema_contract');
    $schema = $schemaContract->invoke($storage);
    assert_same('smallint unsigned', $schema['wp_fts_postings']['mysql_definitions']['impact']['type'] ?? null, 'the physical impact type must retain the range used by the cursor ceiling');
});

/** @return array<string,mixed> */
function wp_fts_cursor_score_payload(string $cursor): array
{
    $decoded = base64_decode(strtr($cursor, '-_', '+/'), true);
    if (!is_string($decoded) || !str_contains($decoded, '.')) {
        return [];
    }
    [$json] = explode('.', $decoded, 2);
    $payload = json_decode($json, true);

    return is_array($payload) ? $payload : [];
}

/** Build a valid cursor whose score encoding can exercise portability boundaries. */
function wp_fts_cursor_score_signed_cursor(
    WP_FTS_Relational_Storage $storage,
    mixed $score,
    string $fingerprint
): string {
    $secretMethod = new ReflectionMethod(WP_FTS_Relational_Storage::class, 'cursor_secret');
    $secret = (string) $secretMethod->invoke($storage);
    $json = json_encode([
        's' => $score,
        'd' => '2026-07-19 00:00:00',
        'i' => 1,
        'q' => $fingerprint,
    ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    $signed = $json . '.' . hash_hmac('sha256', $json, $secret);

    return rtrim(strtr(base64_encode($signed), '+/', '-_'), '=');
}
