<?php
declare(strict_types=1);

test_case('generation-aware queue rejects oversized exact batches before SQL without a corpus fallback', function (): void {
    $fake = new WP_FTS_Test_WPDB();
    $queue = new WP_FTS_Index_Queue($fake);

    $tooMany = null;
    try {
        $queue->enqueue_many(range(1, WP_FTS_Index_Queue::MAX_ENQUEUE_POSTS + 1));
    } catch (InvalidArgumentException $error) {
        $tooMany = $error;
    }
    assert_true($tooMany instanceof InvalidArgumentException, 'the 1,001st unique post should reject the exact foreground batch');
    assert_contains('1000-post enqueue contract', $tooMany?->getMessage() ?? '', 'post-count rejection should name the hard caller contract');
    assert_same([], $fake->prepared, 'the over-count batch must fail before preparing SQL');
    assert_same([], $fake->queue, 'the over-count batch must not substitute a global reconciliation scope');

    $tooLarge = null;
    try {
        $queue->enqueue_many(
            range(1, WP_FTS_Index_Queue::MAX_ENQUEUE_POSTS),
            time(),
            ['caller_context' => str_repeat('x', 7900)]
        );
    } catch (InvalidArgumentException $error) {
        $tooLarge = $error;
    }
    assert_true($tooLarge instanceof InvalidArgumentException, 'a sub-8KiB payload repeated across 1,000 rows should reject the >1MiB statement estimate');
    assert_contains('one-megabyte enqueue statement contract', $tooLarge?->getMessage() ?? '', 'packet rejection should name the one-megabyte SQL contract');
    assert_same([], $fake->prepared, 'the over-byte batch must fail before preparing SQL');
    assert_same([], $fake->queue, 'the over-byte batch must leave no direct or scope work behind');

    assert_same(
        WP_FTS_Index_Queue::MAX_ENQUEUE_POSTS,
        $queue->enqueue_many(range(1, WP_FTS_Index_Queue::MAX_ENQUEUE_POSTS)),
        'the exact 1,000-post boundary with an empty payload should remain usable'
    );
    assert_same(1, count($fake->prepared), 'the valid 1,000-post boundary should use one set-oriented UPSERT');
    assert_same(WP_FTS_Index_Queue::MAX_ENQUEUE_POSTS, count($fake->queue), 'the valid boundary should persist every exact post and no corpus scope');
});

test_case('generation-aware queue rejects caller-shaped expansion before SQL', function (): void {
    $fake = new WP_FTS_Test_WPDB();
    $queue = new WP_FTS_Index_Queue($fake);
    $rejections = [
        'duplicate enqueue input' => static fn() => $queue->enqueue_many(array_fill(0, WP_FTS_Index_Queue::MAX_ENQUEUE_POSTS + 1, 7)),
        'claim limit' => static fn() => $queue->claim_batch(WP_FTS_Index_Queue::MAX_CLAIM_POSTS + 1),
        'empty enqueue timestamp' => static fn() => $queue->enqueue_many([], 0),
        'empty release timestamp' => static fn() => $queue->release_many([], 0),
        'empty scope key' => static fn() => $queue->enqueue_scope(''),
        'padded scope key' => static fn() => $queue->enqueue_scope(' padded '),
        'acknowledgement predicates' => static fn() => $queue->acknowledge_many(array_fill(0, WP_FTS_Index_Queue::MAX_CLAIM_POSTS + 1, [
            'post_id' => 7,
            'generation' => 1,
            'token' => 'owned',
        ])),
        'object payload' => static fn() => $queue->enqueue_many([7], null, ['unsafe' => new stdClass()]),
    ];
    foreach ($rejections as $label => $operation) {
        $fake->queries = [];
        $fake->prepared = [];
        $thrown = null;
        try {
            $operation();
        } catch (InvalidArgumentException $error) {
            $thrown = $error;
        }
        assert_true($thrown instanceof InvalidArgumentException, "{$label} should reject rather than clamp or expand");
        assert_same([], $fake->queries, "{$label} should reject before executing SQL");
        assert_same([], $fake->prepared, "{$label} should reject before preparing SQL");
    }
});

test_case('generation-aware queue accepts only positive canonical post job keys', function (): void {
    assert_same(false, WP_FTS_Index_Queue::is_post_job_key('post:0', 0), 'zero must never identify post work');
    assert_same(false, WP_FTS_Index_Queue::is_post_job_key('post:-1', -1), 'negative IDs must never identify post work');
    assert_same(false, WP_FTS_Index_Queue::is_post_job_key('post:01', 1), 'padded job keys must not identify a post');
    assert_same(true, WP_FTS_Index_Queue::is_post_job_key('post:1', 1), 'a positive canonical key must identify its exact post');

    $queue = new WP_FTS_Index_Queue(new WP_FTS_Test_WPDB());
    $method = new ReflectionMethod(WP_FTS_Index_Queue::class, 'post_job_key');
    $thrown = null;
    try {
        $method->invoke($queue, 0);
    } catch (LogicException $error) {
        $thrown = $error;
    }
    assert_true($thrown instanceof LogicException, 'the private encoder must reject zero instead of clamping it');
});

test_case('generation-aware queue rejects malformed database adapter names', function (): void {
    $badPrefix = new class {
        public mixed $prefix = 1;
        public string $posts = 'wp_posts';
    };
    $badPosts = new class {
        public string $prefix = 'wp_';
        public mixed $posts = [];
    };
    $hiddenPrefix = new class {
        private string $prefix = 'wp_';
        public string $posts = 'wp_posts';
    };
    foreach ([$badPrefix, $badPosts, $hiddenPrefix] as $adapter) {
        $thrown = null;
        try {
            new WP_FTS_Index_Queue($adapter);
        } catch (UnexpectedValueException $error) {
            $thrown = $error;
        }
        assert_true($thrown instanceof UnexpectedValueException, 'adapter table names must use native text instead of scalar repair');
    }
});

test_case('queue batch claims reject unsafe lease and source bounds before SQL', function (): void {
    $fake = new WP_FTS_Test_WPDB();
    $queue = new WP_FTS_Index_Queue($fake);
    $rejections = [
        'negative source snapshot' => static fn() => $queue->claim_batch(1, 1000, 300, -1),
        'source snapshot boundary plus one' => static fn() => $queue->claim_batch(
            1,
            1000,
            300,
            WP_FTS_Index_Queue::MAX_SOURCE_SNAPSHOT_BYTES + 1
        ),
        'zero batch lease' => static fn() => $queue->claim_batch(1, 1000, 0),
        'oversized batch lease' => static fn() => $queue->claim_batch(
            1,
            1000,
            WP_FTS_Index_Queue::MAX_LEASE_SECONDS + 1
        ),
        'batch expiration overflow' => static fn() => $queue->claim_batch(1, PHP_INT_MAX, 1),
    ];
    foreach ($rejections as $label => $operation) {
        $fake->queries = [];
        $fake->prepared = [];
        $thrown = null;
        try {
            $operation();
        } catch (InvalidArgumentException $error) {
            $thrown = $error;
        }
        assert_true($thrown instanceof InvalidArgumentException, "{$label} should reject instead of clamping or overflowing");
        assert_same([], $fake->queries, "{$label} should reject before executing SQL");
        assert_same([], $fake->prepared, "{$label} should reject before preparing SQL");
    }

});

test_case('generation-aware queue rejects malformed claim confirmation rows', function (): void {
    $corruptions = [
        'missing payload alias' => static function (object $row): void {
            unset($row->payload);
        },
        'reordered payload alias' => static function (object $row): void {
            $payload = $row->payload;
            unset($row->payload);
            $row->payload = $payload;
        },
        'unknown row kind' => static function (object $row): void {
            $row->kind = 'unknown';
        },
        'noncanonical generation' => static function (object $row): void {
            $row->generation = '01';
        },
        'truthy source flag' => static function (object $row): void {
            $row->source_exists = 2;
        },
    ];

    $postId = 7800;
    foreach ($corruptions as $label => $mutate) {
        $fake = new WP_FTS_Test_WPDB();
        unset($GLOBALS['wp_fts_test_posts'][$postId]);
        $queue = new WP_FTS_Index_Queue($fake);
        $queue->enqueue_many([$postId], 1000);
        $fake->claimConfirmationRowMutator = $mutate;

        $thrown = null;
        try {
            $queue->claim_batch(1, 1000, 30);
        } catch (UnexpectedValueException $error) {
            $thrown = $error;
        }

        assert_true($thrown instanceof UnexpectedValueException, "{$label} must abort the whole claim response");
        assert_contains('Malformed FTS claim confirmation row', $thrown?->getMessage() ?? '', "{$label} should identify the failed database boundary");
        assert_same('leased', $fake->queue[$postId]['state'] ?? null, "{$label} must not publish a repaired PHP claim");
        $postId++;
    }

    $fake = new WP_FTS_Test_WPDB();
    $queue = new WP_FTS_Index_Queue($fake);
    $queue->enqueue_scope('malformed-scope-payload', [], 1000);
    $fake->claimConfirmationRowMutator = static function (object $row): void {
        $row->payload = '{';
    };
    $thrown = null;
    try {
        $queue->claim_batch(0, 1000, 30);
    } catch (UnexpectedValueException $error) {
        $thrown = $error;
    }
    assert_true($thrown instanceof UnexpectedValueException, 'invalid scope JSON must abort the whole claim response');
    assert_contains('valid bounded JSON', $thrown?->getMessage() ?? '', 'invalid scope JSON should identify the exact failed contract');
});

test_case('generation-aware queue accepts only canonical database integer strings', function (): void {
    $fake = new WP_FTS_Test_WPDB();
    $postId = 7810;
    unset($GLOBALS['wp_fts_test_posts'][$postId]);
    $queue = new WP_FTS_Index_Queue($fake);
    $queue->enqueue_many([$postId], 1000);
    $fake->claimConfirmationRowMutator = static function (object $row): void {
        foreach ([
            'post_id',
            'generation',
            'attempts',
            'claim_expires_at',
            'cursor_post_id',
            'scope_subject_id',
            'source_exists',
            'source_bytes',
            'canonical_bytes',
            'source_snapshot_complete',
        ] as $alias) {
            $row->{$alias} = (string) $row->{$alias};
        }
    };

    $claim = $queue->claim_batch(1, 1000, 30)[0] ?? null;
    assert_true(is_array($claim), 'canonical decimal strings from wpdb should decode into one claim');
    assert_same($postId, $claim['post_id'] ?? null, 'canonical wpdb post IDs should become native integers');
    assert_same(1, $claim['generation'] ?? null, 'canonical wpdb generations should become native integers');
    assert_same(false, $claim['source_exists'] ?? null, 'canonical zero flags should become native booleans');
});

test_case('generation-aware queue rejects malformed settlement capabilities before SQL', function (): void {
    $fake = new WP_FTS_Test_WPDB();
    $queue = new WP_FTS_Index_Queue($fake);
    $queue->enqueue_many([7811], 1000);
    $claim = $queue->claim_batch(1, 1000, 30)[0] ?? null;
    assert_true(is_array($claim), 'the fixture must mint one complete post capability');

    $malformed = $claim;
    $malformed['generation'] = '1';
    $fake->queries = [];
    $fake->prepared = [];
    $thrown = null;
    try {
        $queue->release_many([$malformed], 1001);
    } catch (InvalidArgumentException $error) {
        $thrown = $error;
    }
    assert_true($thrown instanceof InvalidArgumentException, 'a repaired generation type must not become a settlement capability');
    assert_same([], $fake->queries, 'a malformed capability must fail before SQL');
    assert_same([], $fake->prepared, 'a malformed capability must fail before SQL preparation');
    assert_same('leased', $fake->queue[7811]['state'] ?? null, 'a malformed capability must leave its lease untouched');

    foreach ([
        'duplicate job key' => [$claim, $claim],
        'non-list collection' => [1 => $claim],
        'non-array member' => [$claim, 'not-a-claim'],
    ] as $label => $claims) {
        $fake->queries = [];
        $fake->prepared = [];
        $thrown = null;
        try {
            $queue->release_many($claims, 1001);
        } catch (InvalidArgumentException $error) {
            $thrown = $error;
        }
        assert_true($thrown instanceof InvalidArgumentException, "{$label} must reject the whole settlement batch");
        assert_same([], $fake->queries, "{$label} must reject before SQL");
        assert_same([], $fake->prepared, "{$label} must reject before SQL preparation");
    }
});

test_case('generation-aware queue rejects mutated claim source sidecars before SQL', function (): void {
    $postId = 7813;
    $post = wp_fts_test_backfill_post($postId, 'post', 'publish', 'Strict source sidecar');
    $post->post_content = 'Canonical body';
    $GLOBALS['wp_fts_test_posts'][$postId] = $post;
    $fake = new WP_FTS_Test_WPDB();
    $queue = new WP_FTS_Index_Queue($fake);
    try {
        $queue->enqueue_many([$postId], 1000);
        $claim = $queue->claim_batch(1, 1000, 30, 1048576)[0] ?? null;
        assert_true(is_array($claim), 'the fixture must mint one source-backed claim');
        assert_true(($claim['source_snapshot'] ?? null) instanceof stdClass, 'the fixture must include its complete native source snapshot');

        $mutations = [
            'absent source with bytes' => static function (array &$candidate): void {
                $candidate['source_exists'] = false;
            },
            'outer byte mismatch' => static function (array &$candidate): void {
                $candidate['source_bytes']++;
            },
            'missing snapshot alias' => static function (array &$candidate): void {
                unset($candidate['source_snapshot']->post_title);
            },
            'repaired snapshot ID type' => static function (array &$candidate): void {
                $candidate['source_snapshot']->ID = (string) $candidate['source_snapshot']->ID;
            },
            'snapshot byte mismatch' => static function (array &$candidate): void {
                $candidate['source_snapshot']->fts_post_source_bytes++;
            },
        ];
        foreach ($mutations as $label => $mutate) {
            $candidate = $claim;
            $candidate['source_snapshot'] = clone $claim['source_snapshot'];
            $mutate($candidate);
            $fake->queries = [];
            $fake->prepared = [];
            $thrown = null;
            try {
                $queue->release_many([$candidate], 1001);
            } catch (InvalidArgumentException $error) {
                $thrown = $error;
            }
            assert_true($thrown instanceof InvalidArgumentException, "{$label} must reject the whole source capability");
            assert_same([], $fake->queries, "{$label} must reject before SQL");
            assert_same([], $fake->prepared, "{$label} must reject before SQL preparation");
            assert_same('leased', $fake->queue[$postId]['state'] ?? null, "{$label} must leave the durable lease untouched");
        }
    } finally {
        unset($GLOBALS['wp_fts_test_posts'][$postId]);
    }
});

test_case('generation-aware queue preserves the scope cursor and rejects authority payloads before SQL', function (): void {
    $fake = new WP_FTS_Test_WPDB();
    $queue = new WP_FTS_Index_Queue($fake);
    $queue->enqueue_scope('strict-cursor-scope', [], 1000);
    $first = $queue->claim_batch(0, 1000, 30)[0] ?? null;
    assert_true(is_array($first), 'the fixture must claim its first scope turn');
    assert_same(true, $queue->commit_scope_page($first, [81, 82], 82, 1000), 'the first scope turn must persist cursor 82');

    $second = $queue->claim_batch(0, 1001, 30)[0] ?? null;
    assert_true(is_array($second), 'the scope must remain claimable for its next turn');
    assert_same(82, $second['cursor_post_id'] ?? null, 'the next capability must retain the durable cursor');
    $scopeJobKey = (string) ($second['job_key'] ?? '');

    $fake->queries = [];
    $fake->prepared = [];
    $regression = null;
    try {
        $queue->commit_scope_page($second, [], 81, 1001);
    } catch (InvalidArgumentException $error) {
        $regression = $error;
    }
    assert_true($regression instanceof InvalidArgumentException, 'a scope cursor may not move behind its prior high-water');
    assert_same([], $fake->queries, 'a cursor regression must fail before opening a transaction');
    assert_same([], $fake->prepared, 'a cursor regression must fail before preparing SQL');
    assert_same(82, $fake->queue[$scopeJobKey]['cursor_post_id'] ?? null, 'a rejected cursor must leave the durable high-water unchanged');

    $fake->queries = [];
    $fake->prepared = [];
    $reserved = null;
    try {
        $queue->commit_scope_page($second, [], 83, 1001, [], ['scope_coverage' => 'global']);
    } catch (InvalidArgumentException $error) {
        $reserved = $error;
    }
    assert_true($reserved instanceof InvalidArgumentException, 'next-page payload cannot smuggle scope authority');
    assert_same([], $fake->queries, 'reserved next-page payload must fail before opening a transaction');
    assert_same([], $fake->prepared, 'reserved next-page payload must fail before preparing SQL');
    assert_same(82, $fake->queue[$scopeJobKey]['cursor_post_id'] ?? null, 'a rejected next-page payload must not advance the cursor');

    $fake->queries = [];
    $fake->prepared = [];
    $timestamp = null;
    try {
        $queue->acknowledge_scope($second, 0);
    } catch (InvalidArgumentException $error) {
        $timestamp = $error;
    }
    assert_true($timestamp instanceof InvalidArgumentException, 'scope acknowledgement must reject an explicit zero timestamp');
    assert_same([], $fake->queries, 'an invalid acknowledgement timestamp must fail before opening a transaction');
    assert_same([], $fake->prepared, 'an invalid acknowledgement timestamp must fail before preparing SQL');
});

test_case('generation-aware queue rejects malformed foreground handoff capabilities before SQL', function (): void {
    $fake = new WP_FTS_Test_WPDB();
    $queue = new WP_FTS_Index_Queue($fake);
    $token = str_repeat('a', 32);
    $cases = [
        'non-native post key' => [['one' => $token], [], false, [], ''],
        'post outside retained set' => [[2 => $token], [], false, [], ''],
        'invalid post token' => [[1 => str_repeat('A', 32)], [], false, [], ''],
        'empty scope token' => [[], ['targeted-scope' => ''], false, [], ''],
        'non-native scope key' => [[], [0 => $token], false, [], ''],
        'exact payload' => [[], [], false, ['reason' => 'not-exact'], ''],
        'exact incarnation' => [[], [], false, [], str_repeat('1', 32)],
    ];
    foreach ($cases as $label => [$postTokens, $scopeTokens, $overflow, $payload, $incarnation]) {
        $fake->queries = [];
        $fake->prepared = [];
        $thrown = null;
        try {
            $queue->handoff_foreground_mutation_scope(
                'strict-handoff-sentinel',
                $token,
                [1],
                $postTokens,
                $scopeTokens,
                $overflow,
                $payload,
                1000,
                $incarnation
            );
        } catch (InvalidArgumentException $error) {
            $thrown = $error;
        }
        assert_true($thrown instanceof InvalidArgumentException, "{$label} must reject the foreground handoff");
        assert_same([], $fake->queries, "{$label} must reject before SQL");
        assert_same([], $fake->prepared, "{$label} must reject before SQL preparation");
    }
});

test_case('generation-aware queue rejects retry arithmetic overflow before SQL', function (): void {
    $fake = new WP_FTS_Test_WPDB();
    $queue = new WP_FTS_Index_Queue($fake);
    $queue->enqueue_many([7815], 1000);
    $queue->enqueue_scope('strict-retry-overflow', [], 1000);
    $claims = $queue->claim_batch(1, 1000, 30);
    $post = null;
    $scope = null;
    foreach ($claims as $claim) {
        if (($claim['kind'] ?? '') === 'post') {
            $post = $claim;
        } elseif (($claim['kind'] ?? '') === 'scope') {
            $scope = $claim;
        }
    }
    assert_true(is_array($post) && is_array($scope), 'the overflow fixture must mint one post and one scope capability');

    $postOverflow = $post;
    $postOverflow['attempts'] = PHP_INT_MAX;
    $scopeOverflow = $scope;
    $scopeOverflow['attempts'] = PHP_INT_MAX;
    $operations = [
        'post attempt overflow' => static fn(): int => $queue->fail_many([$postOverflow], 1001),
        'scope attempt overflow' => static fn(): array => $queue->fail_scope($scopeOverflow, 1001),
        'post time overflow' => static fn(): int => $queue->fail_many([$post], PHP_INT_MAX),
        'scope time overflow' => static fn(): array => $queue->fail_scope($scope, PHP_INT_MAX),
    ];
    foreach ($operations as $label => $operation) {
        $fake->queries = [];
        $fake->prepared = [];
        $thrown = null;
        try {
            $operation();
        } catch (InvalidArgumentException $error) {
            $thrown = $error;
        }
        assert_true($thrown instanceof InvalidArgumentException, "{$label} must reject without arithmetic repair");
        assert_same([], $fake->queries, "{$label} must reject before SQL");
        assert_same([], $fake->prepared, "{$label} must reject before SQL preparation");
    }
});

test_case('generation-aware queue rejects malformed database collection and mutation transports', function (): void {
    foreach (['1', true, -1, 999] as $result) {
        $fake = new WP_FTS_Test_WPDB();
        $queue = new WP_FTS_Index_Queue($fake);
        $fake->overrideNextQueryResult = true;
        $fake->nextQueryResult = $result;
        $thrown = null;
        try {
            $queue->enqueue_many([7812], 1000);
        } catch (UnexpectedValueException $error) {
            $thrown = $error;
        }
        assert_true($thrown instanceof UnexpectedValueException, 'DML transport must return a native in-range affected-row count');
        assert_same([], $fake->queue, 'a malformed DML transport must publish no fake durable row');
    }

    foreach ([null, [1 => new stdClass()], [new stdClass(), []]] as $result) {
        $fake = new WP_FTS_Test_WPDB();
        $queue = new WP_FTS_Index_Queue($fake);
        $fake->overrideNextResults = true;
        $fake->nextResults = $result;
        $thrown = null;
        try {
            $queue->status();
        } catch (UnexpectedValueException $error) {
            $thrown = $error;
        }
        assert_true($thrown instanceof UnexpectedValueException, 'row collections must be native lists containing only objects');
    }

    $adapter = new class {
        public string $prefix = 'wp_';
        public string $posts = 'wp_posts';
        public mixed $last_error = [];

        public function prepare(string $sql, mixed ...$args): string
        {
            return $sql;
        }

        public function query(mixed $statement): int
        {
            return 1;
        }
    };
    $queue = new WP_FTS_Index_Queue($adapter);
    $thrown = null;
    try {
        $queue->enqueue_many([7814], 1000);
    } catch (UnexpectedValueException $error) {
        $thrown = $error;
    }
    assert_true($thrown instanceof UnexpectedValueException, 'an exposed database error field must contain native text');

    $fake = new WP_FTS_Test_WPDB();
    $queue = new WP_FTS_Index_Queue($fake);
    $queue->enqueue_scope('strict-control-result', [], 1000);
    $scope = $queue->claim_batch(0, 1000, 30)[0] ?? null;
    assert_true(is_array($scope), 'the control transport fixture must mint one scope capability');
    $fake->overrideNextQueryResult = true;
    $fake->nextQueryResult = '0';
    $thrown = null;
    try {
        $queue->acknowledge_scope($scope, 1001);
    } catch (UnexpectedValueException $error) {
        $thrown = $error;
    }
    assert_true($thrown instanceof UnexpectedValueException, 'transaction control must reject a string result instead of coercing it');
    assert_same('leased', $fake->queue[(string) ($scope['job_key'] ?? '')]['state'] ?? null, 'a malformed transaction result must leave the scope lease untouched');
});

test_case('generation-aware queue decodes exact bounded status rows', function (): void {
    $fake = new WP_FTS_Test_WPDB();
    $queue = new WP_FTS_Index_Queue($fake);
    $fake->overrideNextResults = true;
    $fake->nextResults = [
        (object) ['kind' => 'post', 'work_count' => '2', 'max_cursor_post_id' => '0'],
        (object) ['kind' => 'scope', 'work_count' => '1', 'max_cursor_post_id' => '42'],
    ];
    $status = $queue->status();
    assert_same(2, $status['post_count'] ?? null, 'canonical database text must decode to a native post count');
    assert_same(1, $status['scope_count'] ?? null, 'canonical database text must decode to a native scope count');
    assert_same(42, $status['scope_cursor_post_id'] ?? null, 'canonical database text must decode to a native scope cursor');

    $malformedResults = [
        'wrong cardinality' => [
            (object) ['kind' => 'post', 'work_count' => 0, 'max_cursor_post_id' => 0],
        ],
        'duplicate kind' => [
            (object) ['kind' => 'post', 'work_count' => 0, 'max_cursor_post_id' => 0],
            (object) ['kind' => 'post', 'work_count' => 0, 'max_cursor_post_id' => 0],
        ],
        'noncanonical integer' => [
            (object) ['kind' => 'post', 'work_count' => '01', 'max_cursor_post_id' => '0'],
            (object) ['kind' => 'scope', 'work_count' => '0', 'max_cursor_post_id' => '0'],
        ],
        'count above query limit' => [
            (object) ['kind' => 'post', 'work_count' => WP_FTS_Index_Queue::STATUS_COUNT_LIMIT + 1, 'max_cursor_post_id' => 0],
            (object) ['kind' => 'scope', 'work_count' => 0, 'max_cursor_post_id' => 0],
        ],
        'post cursor' => [
            (object) ['kind' => 'post', 'work_count' => 0, 'max_cursor_post_id' => 1],
            (object) ['kind' => 'scope', 'work_count' => 0, 'max_cursor_post_id' => 0],
        ],
        'empty scope cursor' => [
            (object) ['kind' => 'post', 'work_count' => 0, 'max_cursor_post_id' => 0],
            (object) ['kind' => 'scope', 'work_count' => 0, 'max_cursor_post_id' => 1],
        ],
        'extra alias' => [
            (object) ['kind' => 'post', 'work_count' => 0, 'max_cursor_post_id' => 0, 'extra' => 0],
            (object) ['kind' => 'scope', 'work_count' => 0, 'max_cursor_post_id' => 0],
        ],
    ];
    foreach ($malformedResults as $label => $rows) {
        $fake->overrideNextResults = true;
        $fake->nextResults = $rows;
        $thrown = null;
        try {
            $queue->status();
        } catch (UnexpectedValueException $error) {
            $thrown = $error;
        }
        assert_true($thrown instanceof UnexpectedValueException, "{$label} must reject the entire status result");
    }
});

test_case('generation-aware queue decodes exact presence and schedule scalars', function (): void {
    $fake = new WP_FTS_Test_WPDB();
    $queue = new WP_FTS_Index_Queue($fake);
    foreach ([[null, false], [1, true], ['1', true]] as [$value, $expected]) {
        $fake->overrideNextVar = true;
        $fake->nextVar = $value;
        assert_same($expected, $queue->has_work(), 'presence probes must accept only null and exact one');
    }
    foreach ([0, '0', true, '01', 2] as $value) {
        $fake->overrideNextVar = true;
        $fake->nextVar = $value;
        $thrown = null;
        try {
            $queue->has_work();
        } catch (UnexpectedValueException $error) {
            $thrown = $error;
        }
        assert_true($thrown instanceof UnexpectedValueException, 'a noncanonical presence scalar must reject the database response');
    }

    $fake->overrideNextVar = true;
    $fake->nextVar = '1';
    assert_same(true, $queue->global_visibility_scope_exists(), 'the global-scope probe must share the exact presence decoder');
    $fake->overrideNextVar = true;
    $fake->nextVar = false;
    $thrown = null;
    try {
        $queue->global_visibility_scope_exists();
    } catch (UnexpectedValueException $error) {
        $thrown = $error;
    }
    assert_true($thrown instanceof UnexpectedValueException, 'a boolean global-scope response must reject instead of coercing');

    foreach ([[null, null], [123, 123], ['123', 123]] as [$value, $expected]) {
        $fake->overrideNextVar = true;
        $fake->nextVar = $value;
        assert_same($expected, $queue->next_available_at(), 'next-work probes must decode only null or a positive canonical integer');
    }
    foreach ([0, '01', true, -1] as $value) {
        $fake->overrideNextVar = true;
        $fake->nextVar = $value;
        $thrown = null;
        try {
            $queue->next_available_at();
        } catch (UnexpectedValueException $error) {
            $thrown = $error;
        }
        assert_true($thrown instanceof UnexpectedValueException, 'a malformed next-work scalar must reject the database response');
    }
});

test_case('generation-aware queue validates exact corpus scope rows', function (): void {
    $fake = new WP_FTS_Test_WPDB();
    $queue = new WP_FTS_Index_Queue($fake);
    $incarnation = str_repeat('1', 32);
    $profile = str_repeat('2', 40);
    $payload = json_encode(['profile_hash' => $profile], JSON_THROW_ON_ERROR);

    $fake->overrideNextResults = true;
    $fake->nextResults = [];
    assert_same(false, $queue->corpus_scope_matches('strict-corpus', $incarnation, $profile), 'an absent exact corpus row must return false');

    $fake->overrideNextResults = true;
    $fake->nextResults = [(object) ['scope_incarnation' => $incarnation, 'payload' => $payload]];
    assert_same(true, $queue->corpus_scope_matches('strict-corpus', $incarnation, $profile), 'an exact corpus capability and profile must match');

    $queue->enqueue_scope(
        'strict-corpus-live',
        ['profile_hash' => $profile],
        1000,
        WP_FTS_Index_Queue::SCOPE_COVERAGE_CORPUS,
        '',
        0,
        $incarnation
    );
    assert_same(true, $queue->corpus_scope_matches('strict-corpus-live', $incarnation, $profile), 'the fake adapter must preserve the normal exact corpus transport');

    $fake->overrideNextResults = true;
    $fake->nextResults = [(object) ['scope_incarnation' => str_repeat('3', 32), 'payload' => '{']];
    assert_same(false, $queue->corpus_scope_matches('strict-corpus', $incarnation, $profile), 'a valid foreign incarnation must return false without decoding its payload');

    $malformedResults = [
        'cardinality' => [
            (object) ['scope_incarnation' => $incarnation, 'payload' => $payload],
            (object) ['scope_incarnation' => $incarnation, 'payload' => $payload],
        ],
        'aliases' => [(object) ['payload' => $payload, 'scope_incarnation' => $incarnation]],
        'native types' => [(object) ['scope_incarnation' => $incarnation, 'payload' => []]],
        'incarnation' => [(object) ['scope_incarnation' => 'A' . substr($incarnation, 1), 'payload' => $payload]],
        'JSON' => [(object) ['scope_incarnation' => $incarnation, 'payload' => '{']],
        'profile' => [(object) ['scope_incarnation' => $incarnation, 'payload' => '{"profile_hash":"no"}']],
        'profile case' => [(object) ['scope_incarnation' => $incarnation, 'payload' => '{"profile_hash":"' . str_repeat('A', 40) . '"}']],
    ];
    foreach ($malformedResults as $label => $rows) {
        $fake->overrideNextResults = true;
        $fake->nextResults = $rows;
        $thrown = null;
        try {
            $queue->corpus_scope_matches('strict-corpus', $incarnation, $profile);
        } catch (UnexpectedValueException $error) {
            $thrown = $error;
        }
        assert_true($thrown instanceof UnexpectedValueException, "malformed corpus {$label} must reject the database result");
    }
});

test_case('generation-aware queue snapshots an under-budget claim and safely falls back above eight MiB', function (): void {
    global $wpdb;

    $oldWpdb = $wpdb ?? null;
    $fake = new WP_FTS_Test_WPDB();
    $wpdb = $fake;
    wp_fts_test_reset_wordpress_fakes();
    $ids = range(7601, 7605);
    foreach ($ids as $postId) {
        $post = wp_fts_test_backfill_post($postId, 'post', 'publish', 'S' . $postId);
        $post->post_content = str_repeat('x', WP_FTS_Analysis_Limits::MAX_SOURCE_BYTES - strlen($post->post_title));
        $GLOBALS['wp_fts_test_posts'][$postId] = $post;
    }

    try {
        $queue = new WP_FTS_Index_Queue($fake);
        $queue->enqueue_many($ids, 1000);
        $fake->prepared = [];
        $claims = $queue->claim_batch(
            5,
            1000,
            300,
            WP_FTS_Index_Queue::MAX_SOURCE_SNAPSHOT_BYTES
        );
        assert_same(5, count($claims), 'the aggregate adversary should still claim every exact generation in one batch');
        foreach ($claims as $claim) {
            assert_same(WP_FTS_Analysis_Limits::MAX_SOURCE_BYTES, $claim['source_bytes'] ?? null, 'claim confirmation should retain each exact source measurement');
            assert_true((int) ($claim['canonical_bytes'] ?? 0) >= WP_FTS_Analysis_Limits::MAX_SOURCE_BYTES, 'claim confirmation should also retain the complete canonical transport measurement');
            assert_same(null, $claim['source_snapshot'] ?? null, 'a ten-MiB claim must withhold all source LOBs instead of partially materializing them');
        }

        $confirm = array_values(array_filter(
            $fake->prepared,
            static fn(array $statement): bool => str_starts_with((string) ($statement['sql'] ?? ''), 'SELECT w.job_key, w.kind, w.post_id')
        ));
        assert_same(1, count($confirm), 'claim and aggregate source preflight should share one indexed confirmation statement');
        assert_contains(
            '<= ' . WP_FTS_Index_Queue::MAX_SOURCE_SNAPSHOT_BYTES,
            (string) ($confirm[0]['sql'] ?? ''),
            'claim confirmation should enforce the hard aggregate snapshot cap before projecting LOBs'
        );

        $measurements = [];
        foreach ($claims as $claim) {
            $measurements[(int) $claim['post_id']] = [
                'exists' => !empty($claim['source_exists']),
                'bytes' => (int) ($claim['source_bytes'] ?? 0),
                'canonical_bytes' => (int) ($claim['canonical_bytes'] ?? 0),
            ];
        }
        $load = new ReflectionMethod(WP_FTS_Plugin::class, 'load_posts_for_indexing');
        $posts = $load->invoke(null, $ids, $measurements, []);
        foreach (array_slice($ids, 0, 4) as $postId) {
            assert_same(
                WP_FTS_Analysis_Limits::MAX_SOURCE_BYTES,
                strlen((string) ($posts[$postId]->post_title ?? '')) + strlen((string) ($posts[$postId]->post_content ?? '')),
                "fallback should load exact source {$postId} without truncation"
            );
            assert_true(empty($posts[$postId]->fts_index_deferred), "fallback should admit source {$postId} inside the eight-MiB prefix");
        }
        assert_same(true, $posts[7605]->fts_index_deferred ?? null, 'the first source beyond the aggregate cap should defer as a whole generation');

        $fallbacks = array_values(array_filter(
            $fake->prepared,
            static fn(array $statement): bool => str_starts_with((string) ($statement['sql'] ?? ''), "SELECT p.ID,\n       CASE WHEN")
        ));
        assert_same(1, count($fallbacks), 'an over-budget snapshot should use one bounded conditional source fallback');
        assert_same(4, substr_count((string) ($fallbacks[0]['sql'] ?? ''), ' AS post_id,'), 'fallback SQL should project only the four-document eight-MiB prefix');
    } finally {
        $wpdb = $oldWpdb;
    }
});

/**
 * Durable generation-aware queue contracts.
 *
 * Direct execution re-enters the shared harness with a focused filter. Normal
 * tests/run.php discovery registers these tests alongside the rest of the suite.
 */

function wp_fts_index_queue_contract_direct(): int
{
    if (!function_exists('proc_open')) {
        fwrite(STDOUT, "SKIP: proc_open() is unavailable, so the focused queue contract cannot launch tests/run.php.\n");
        return 0;
    }

    $root = dirname(__DIR__, 2);
    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $environment = getenv();
    if (!is_array($environment)) {
        $environment = [];
    }
    $process = proc_open(
        [PHP_BINARY, $root . '/tests/run.php'],
        $descriptors,
        $pipes,
        $root,
        array_merge($environment, [
            'WP_FTS_TEST_FILTER' => 'generation-aware queue',
            'WP_FTS_MIN_CHECKS' => '0',
        ])
    );
    if (!is_resource($process)) {
        fwrite(STDERR, "FAIL: Could not launch the focused queue contract.\n");
        return 1;
    }

    fclose($pipes[0]);
    $stdout = (string) stream_get_contents($pipes[1]);
    $stderr = (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exit = proc_close($process);
    if ($stdout !== '') {
        fwrite(STDOUT, $stdout);
    }
    if ($stderr !== '') {
        fwrite(STDERR, $stderr);
    }

    return is_int($exit) ? $exit : 1;
}

if (!function_exists('test_case')) {
    exit(wp_fts_index_queue_contract_direct());
}

test_case('generation-aware queue atomically coalesces duplicate post saves', function (): void {
    $wpdb = new WP_FTS_Test_WPDB();
    $queue = new WP_FTS_Index_Queue($wpdb);

    $queue->enqueue_many([41], 1000);
    $queue->enqueue_many([41], 1001);

    assert_same(1, $queue->count(), 'duplicate saves should occupy one durable queue row');
    assert_same(2, $wpdb->queue[41]['generation'] ?? null, 'duplicate saves should advance the queued generation');
    assert_same(1001, $wpdb->queue[41]['available_at'] ?? null, 'the latest save should be available immediately');
    assert_true(
        str_contains($wpdb->prepared[0]['sql'] ?? '', 'ON DUPLICATE KEY UPDATE'),
        'enqueue should use one atomic database upsert'
    );
});

test_case('generation-aware queue acknowledgement cannot erase a newer save', function (): void {
    $wpdb = new WP_FTS_Test_WPDB();
    $queue = new WP_FTS_Index_Queue($wpdb);

    $queue->enqueue_many([42], 1000);
    $first = $queue->claim_batch(1, 1000, 30)[0] ?? null;
    assert_true(is_array($first), 'the first generation should be claimable');

    $queue->enqueue_many([42], 1001);
    assert_same(0, $queue->acknowledge_many([$first])['acknowledged'], 'enqueue should clear the old lease so its later acknowledgement loses ownership');
    assert_same(2, $wpdb->queue[42]['generation'] ?? null, 'acknowledging generation one should preserve generation two');
    assert_same('', $wpdb->queue[42]['claim_token'] ?? null, 'the newer generation should be released for another worker');

    $second = $queue->claim_batch(1, 1002, 30)[0] ?? null;
    assert_same(2, $second['generation'] ?? null, 'the next worker should claim the newer generation');
    assert_same(1, $queue->acknowledge_many([$second])['acknowledged'], 'the current generation owner should acknowledge its work');
    assert_same(0, $queue->count(), 'the row should disappear only after its latest generation finishes');
});

test_case('generation-aware queue recovers expired claims without accepting stale acknowledgement', function (): void {
    $wpdb = new WP_FTS_Test_WPDB();
    $queue = new WP_FTS_Index_Queue($wpdb);

    $queue->enqueue_many([43], 1000);
    $stale = $queue->claim_batch(1, 1000, 10)[0] ?? null;
    assert_true(is_array($stale), 'the initial worker should claim the row');
    assert_same([], $queue->claim_batch(1, 1009, 10), 'an active lease should prevent duplicate processing');

    $recovered = $queue->claim_batch(1, 1010, 10)[0] ?? null;
    assert_true(is_array($recovered), 'an expired lease should be recoverable after a worker crash');
    assert_true(($recovered['token'] ?? '') !== ($stale['token'] ?? ''), 'recovery should transfer ownership to a new token');
    assert_same(0, $queue->acknowledge_many([$stale])['acknowledged'], 'the stale worker should no longer be allowed to acknowledge');
    assert_same(1, $queue->acknowledge_many([$recovered])['acknowledged'], 'the recovery worker should retain acknowledgement ownership');
});

test_case('generation-aware queue retries failures with bounded backoff and clean explicit generations', function (): void {
    $wpdb = new WP_FTS_Test_WPDB();
    $queue = new WP_FTS_Index_Queue($wpdb);

    $queue->enqueue_many([44], 1000);
    $first = $queue->claim_batch(1, 1000, 30)[0] ?? null;
    assert_same(1, $queue->fail_many([$first], 1000), 'a failed current generation should enter backoff');
    assert_same('retry', $wpdb->queue[44]['state'] ?? null, 'a failed current generation should retain durable retry state');
    assert_same(1300, $wpdb->queue[44]['available_at'] ?? null, 'the first failure should use the base retry delay');
    assert_same([], $queue->claim_batch(1, 1299, 30), 'a deferred row should not be claimable early');

    $second = $queue->claim_batch(1, 1300, 30)[0] ?? null;
    assert_same(1, $second['attempts'] ?? null, 'an automatic retry should carry the prior failure count');
    assert_same(1, $second['generation'] ?? null, 'automatic retry should retain the desired generation');
    assert_same(1, $queue->fail_many([$second], 1300), 'the second same-generation failure should remain owned');
    assert_same(1900, $wpdb->queue[44]['available_at'] ?? null, 'the second same-generation failure should double the retry delay');

    $queue->retry_many([44], 1400);
    $explicit = $queue->claim_batch(1, 1400, 30)[0] ?? null;
    assert_same(0, $explicit['attempts'] ?? null, 'an explicit operator retry should start a clean failure budget');
    assert_same(2, $explicit['generation'] ?? null, 'an explicit operator retry should fence old workers with a new desired generation');
    assert_same(1, $queue->fail_many([$explicit], 1400), 'the clean generation should retain retry ownership');
    assert_same(1700, $wpdb->queue[44]['available_at'] ?? null, 'the clean generation should restart at the base retry delay');
});

test_case('generation-aware queue gives a newer save a clean retry state', function (): void {
    $wpdb = new WP_FTS_Test_WPDB();
    $queue = new WP_FTS_Index_Queue($wpdb);

    $queue->enqueue_many([45], 1000);
    $old = $queue->claim_batch(1, 1000, 30)[0] ?? null;
    $queue->enqueue_many([45], 1001);
    $failed = $queue->fail_many([$old], 1002);

    assert_same(0, $failed, 'enqueue clears the old lease, so its later failure report no longer owns any row');
    assert_same(2, $wpdb->queue[45]['generation'] ?? null, 'the newer save should remain pending');
    assert_same(0, $wpdb->queue[45]['attempts'] ?? null, 'the newer save should not inherit an older failure count');
    assert_same(1001, $wpdb->queue[45]['available_at'] ?? null, 'the newer save should retain its own immediate availability without failure backoff');
});

test_case('generation-aware queue backs off every claim when the shared dependency preload fails', function (): void {
    global $wpdb;

    $oldWpdb = $wpdb ?? null;
    $wpdb = new WP_FTS_Test_WPDB();
    wp_fts_test_reset_wordpress_fakes();
    $GLOBALS['wp_fts_test_options'][WP_FTS_Plugin::SCHEMA_VERSION_OPTION] = WP_FTS_Plugin::SCHEMA_VERSION;
    $GLOBALS['wp_fts_test_posts'][46] = wp_fts_test_backfill_post(46, 'post', 'publish', 'Shared preload failure');
    wp_fts_test_seed_queue($wpdb, [46]);
    $wpdb->failReadQueryPrefix = 'SELECT bounded.post_order, bounded.row_order, bounded.source_kind,';
    $startedAt = time();

    try {
        $thrown = null;
        try {
            WP_FTS_Plugin::process_manual_index_batch(['batch_size' => 1]);
        } catch (RuntimeException $error) {
            $thrown = $error;
        }
        assert_true($thrown instanceof RuntimeException, 'a shared dependency preload failure should escape the batch boundary');
        assert_same(1, $wpdb->queue[46]['attempts'] ?? null, 'a shared dependency failure should consume one bounded retry attempt');
        assert_same('retry', $wpdb->queue[46]['state'] ?? null, 'the failed preload should return the generation to durable retry state');
        assert_same('', $wpdb->queue[46]['claim_token'] ?? null, 'the failed preload should release worker ownership immediately');
        assert_true(
            ($wpdb->queue[46]['available_at'] ?? 0) >= $startedAt + WP_FTS_Index_Queue::BASE_BACKOFF_SECONDS,
            'the failed preload should wait for bounded backoff instead of forming a hot retry loop'
        );
        assert_same('content_failure', $wpdb->queue[46]['last_error_code'] ?? null, 'shared preload failure should retain a bounded reason code');
        assert_same([], $wpdb->docs, 'a failed shared preload should perform no index write');
    } finally {
        $wpdb = $oldWpdb;
    }
});

test_case('generation-aware queue keeps repeated failures durable and lets a new save reset them', function (): void {
    $wpdb = new WP_FTS_Test_WPDB();
    $queue = new WP_FTS_Index_Queue($wpdb);
    $queue->enqueue_many([47], 1000);

    $now = 1000;
    $lastDelay = 0;
    $attempts = 6;
    for ($attempt = 1; $attempt <= $attempts; $attempt++) {
        $claim = $queue->claim_batch(1, $now, 30)[0] ?? null;
        assert_true(is_array($claim), "failure attempt {$attempt} should claim the same desired generation");
        assert_same(1, $queue->fail_many([$claim], $now), "failure attempt {$attempt} should stay durable instead of becoming terminal");
        assert_same('retry', $wpdb->queue[47]['state'] ?? null, "failure attempt {$attempt} should remain in the retry state");
        $availableAt = (int) ($wpdb->queue[47]['available_at'] ?? 0);
        $lastDelay = $availableAt - $now;
        $now = $availableAt;
    }
    assert_same($attempts, $wpdb->queue[47]['attempts'] ?? null, 'repeated failures should retain their diagnostic attempt count');
    assert_same(WP_FTS_Index_Queue::MAX_BACKOFF_SECONDS, $lastDelay, 'repeated failures should cap delay without dropping the durable generation');

    $queue->enqueue_many([47], $now + 1);
    assert_same(2, $wpdb->queue[47]['generation'] ?? null, 'a later save should advance beyond the repeatedly failed generation');
    assert_same('ready', $wpdb->queue[47]['state'] ?? null, 'a later save should make corrected content immediately claimable');
    assert_same(0, $wpdb->queue[47]['attempts'] ?? null, 'a later save should receive a clean failure budget');
    $current = $queue->claim_batch(1, $now + 1, 30)[0] ?? null;
    assert_true(is_array($current), 'the corrected desired generation should be claimable');
    assert_same(1, $queue->acknowledge_many([$current])['acknowledged'], 'the corrected generation should acknowledge normally');
    assert_same(0, $queue->count(), 'successful corrected work should remove the durable row');
});

test_case('generation-aware queue uninstall surfaces destructive table cleanup failures', function (): void {
    global $wpdb;

    $oldWpdb = $wpdb ?? null;
    $wpdb = new WP_FTS_Test_WPDB();
    wp_fts_test_reset_wordpress_fakes();
    $GLOBALS['wp_fts_test_options'][WP_FTS_Plugin::SCHEMA_VERSION_OPTION] = WP_FTS_Plugin::SCHEMA_VERSION;
    wp_fts_test_seed_queue($wpdb, [54]);
    $wpdb->failQueryPrefix = 'DROP TABLE IF EXISTS ';
    $scheduleCallsBefore = count($GLOBALS['wp_fts_test_schedule_calls']);

    try {
        $thrown = null;
        try {
            WP_FTS_Plugin::uninstall();
        } catch (RuntimeException $e) {
            $thrown = $e;
        }

        assert_true($thrown instanceof RuntimeException, 'a current install should surface a destructive table cleanup failure');
        assert_same([54], array_keys($wpdb->queue), 'failed table cleanup should leave durable work visible for a retry');
        assert_same(WP_FTS_Plugin::SCHEMA_VERSION, $GLOBALS['wp_fts_test_options'][WP_FTS_Plugin::SCHEMA_VERSION_OPTION] ?? null, 'failed table cleanup should not delete schema state and report success');
        assert_same(WP_FTS_Plugin::UNINSTALL_FENCE_VALUE, $GLOBALS['wp_fts_test_options'][WP_FTS_Plugin::UNINSTALL_FENCE_OPTION] ?? null, 'failed table cleanup should retain the exact fail-closed uninstall fence');
        assert_same($scheduleCallsBefore, count($GLOBALS['wp_fts_test_schedule_calls']), 'failed table cleanup should not schedule repair through the retained fence');
    } finally {
        $wpdb = $oldWpdb;
    }
});

test_case('generation-aware queue uninstall uses idempotent table removal for a partial install', function (): void {
    global $wpdb;

    $oldWpdb = $wpdb ?? null;
    $wpdb = new WP_FTS_Test_WPDB();
    $wpdb->queueTableExists = false;
    wp_fts_test_reset_wordpress_fakes();
    $GLOBALS['wp_fts_test_options'][WP_FTS_Plugin::SCHEMA_VERSION_OPTION] = WP_FTS_Plugin::SCHEMA_VERSION;

    try {
        WP_FTS_Plugin::uninstall();

        assert_true(!isset($GLOBALS['wp_fts_test_options'][WP_FTS_Plugin::SCHEMA_VERSION_OPTION]), 'a partial install without a queue table should still remove its schema state');
        assert_same(WP_FTS_Plugin::UNINSTALL_FENCE_VALUE, $GLOBALS['wp_fts_test_options'][WP_FTS_Plugin::UNINSTALL_FENCE_OPTION] ?? null, 'partial-install cleanup should retain only its lifecycle fence');
        assert_same([], $wpdb->prepared, 'DROP TABLE IF EXISTS should not require a metadata probe for a partial install');
        assert_same(1, count($wpdb->queries), 'partial-install uninstall should remain one database statement');
        assert_true(str_starts_with($wpdb->queries[0] ?? '', 'DROP TABLE IF EXISTS '), 'partial-install uninstall should use idempotent table removal');
        assert_contains('wp_fts_work', $wpdb->queries[0] ?? '', 'partial-install uninstall should include the durable work table');
    } finally {
        $wpdb = $oldWpdb;
    }
});
