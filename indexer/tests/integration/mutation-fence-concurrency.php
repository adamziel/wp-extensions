<?php
declare(strict_types=1);

/**
 * Three-connection MySQL/MariaDB proof for canonical generation CAS fencing.
 *
 * Run only against a disposable database:
 *   WP_FTS_MYSQL_HOST=127.0.0.1 WP_FTS_MYSQL_PORT=3306 \
 *   WP_FTS_MYSQL_USER=root WP_FTS_MYSQL_PASSWORD=... \
 *   php indexer/tests/integration/mutation-fence-concurrency.php
 */

$queuePath = trim((string) getenv('WP_FTS_MUTATION_QUEUE_PATH'));
if ($queuePath === '') {
    $queuePath = dirname(__DIR__, 2) . '/src/IndexQueue.php';
}
if (!is_file($queuePath)) {
    throw new RuntimeException('Mutation proof cannot load the source-bound IndexQueue.php.');
}
require_once $queuePath;

if (($argv[1] ?? null) === '--foreground-owner-holder') {
    wp_fts_mutation_proof_foreground_owner_holder((string) ($argv[2] ?? ''));
    exit(0);
}

if (!extension_loaded('mysqli')) {
    fwrite(STDOUT, "SKIP: mysqli is unavailable.\n");
    exit(0);
}

$host = trim((string) getenv('WP_FTS_MYSQL_HOST'));
if ($host === '') {
    fwrite(STDOUT, "SKIP: set WP_FTS_MYSQL_HOST for a disposable MySQL/MariaDB database.\n");
    exit(0);
}

$port = max(1, (int) (getenv('WP_FTS_MYSQL_PORT') ?: 3306));
$user = (string) (getenv('WP_FTS_MYSQL_USER') ?: 'root');
$password = (string) getenv('WP_FTS_MYSQL_PASSWORD');
$database = (string) (getenv('WP_FTS_MYSQL_DATABASE') ?: 'test');
$prefix = 'wpftsf_' . substr(hash('sha256', getmypid() . ':' . microtime(true)), 0, 10) . '_';
$table = $prefix . 'fts_work';
$postsTable = $prefix . 'posts';
$documentsTable = $prefix . 'fts_documents';
$aClosedForCrash = false;
$foregroundHolderProcess = null;
$foregroundHolderPipes = [];

try {
    $connections = [];
    for ($index = 0; $index < 3; $index++) {
        $mysqli = mysqli_init();
        if (!$mysqli instanceof mysqli) {
            throw new RuntimeException('Could not initialize mysqli.');
        }
        $mysqli->real_connect($host, $user, $password, $database, $port);
        $connections[] = new WP_FTS_Mutation_Proof_WPDB($mysqli, $prefix);
    }
    [$a, $b, $c] = $connections;
    $a->query("CREATE TABLE `{$postsTable}` (
        ID bigint unsigned NOT NULL,
        post_author bigint unsigned NULL,
        post_date datetime NULL,
        post_date_gmt datetime NULL,
        post_content longtext NULL,
        post_title text NULL,
        post_excerpt text NULL,
        post_status varchar(20) NULL,
        comment_status varchar(20) NULL,
        ping_status varchar(20) NULL,
        post_password varchar(255) NULL,
        post_name varchar(200) NULL,
        to_ping text NULL,
        pinged text NULL,
        post_modified datetime NULL,
        post_modified_gmt datetime NULL,
        post_content_filtered longtext NULL,
        post_parent bigint unsigned NULL,
        guid varchar(255) NULL,
        menu_order int NULL,
        post_type varchar(20) NULL,
        post_mime_type varchar(100) NULL,
        comment_count bigint NULL,
        PRIMARY KEY (ID)
    ) ENGINE=InnoDB");
    $a->query("CREATE TABLE `{$documentsTable}` (
        post_id bigint unsigned NOT NULL,
        content_hash varbinary(64) NULL,
        PRIMARY KEY (post_id)
    ) ENGINE=InnoDB");
    $a->query("CREATE TABLE `{$table}` (
        job_key varbinary(191) NOT NULL,
        kind varchar(16) NOT NULL,
        post_id bigint unsigned NOT NULL DEFAULT 0,
        generation bigint unsigned NOT NULL DEFAULT 1,
        state varchar(12) NOT NULL,
        available_at bigint unsigned NOT NULL DEFAULT 0,
        attempts int unsigned NOT NULL DEFAULT 0,
        claim_token varchar(64) NOT NULL DEFAULT '',
        claimed_generation bigint unsigned NOT NULL DEFAULT 0,
        claim_expires_at bigint unsigned NOT NULL DEFAULT 0,
        cursor_post_id bigint unsigned NOT NULL DEFAULT 0,
        scope_coverage varchar(12) NOT NULL DEFAULT '',
        scope_incarnation varbinary(32) NOT NULL DEFAULT '',
        scope_subject_type varchar(24) NOT NULL DEFAULT '',
        scope_subject_id bigint unsigned NOT NULL DEFAULT 0,
        payload longtext NULL,
        last_error_code varchar(64) NOT NULL DEFAULT '',
        last_error_at bigint unsigned NOT NULL DEFAULT 0,
        PRIMARY KEY (job_key),
        KEY ready (kind, state, available_at, post_id, job_key),
        KEY recoverable (kind, state, claim_expires_at, available_at, post_id, job_key),
        KEY claim_token (claim_token, post_id),
        KEY kind_job (kind, job_key),
        KEY scope_subject (kind, scope_coverage, scope_subject_type, scope_subject_id),
        KEY dirty (post_id, kind)
    ) ENGINE=InnoDB");

    $qa = new WP_FTS_Index_Queue($a, $prefix);
    $qb = new WP_FTS_Index_Queue($b, $prefix);
    $qc = new WP_FTS_Index_Queue($c, $prefix);
    $postId = 41;
    $tokenA = str_repeat('a', 32);
    $tokenB = str_repeat('b', 32);
    $tokenC = str_repeat('c', 32);
    $recoveryAt = time() + 300;
    $boundaryEvidence = [];

    wp_fts_mutation_proof_capture(
        $boundaryEvidence,
        $a,
        'a_fence_generation_1',
        'fence',
        'won_generation_1',
        static fn() => $qa->fence_post($postId, $tokenA, $recoveryAt, ['source' => 'A'])
    );
    wp_fts_mutation_proof_row($a, $table, $postId, 'fenced', 1, $tokenA, 'A owns generation one');

    wp_fts_mutation_proof_capture(
        $boundaryEvidence,
        $b,
        'b_fence_generation_2',
        'fence',
        'won_generation_2',
        static fn() => $qb->fence_post($postId, $tokenB, $recoveryAt, ['source' => 'B'])
    );
    wp_fts_mutation_proof_row($b, $table, $postId, 'fenced', 2, $tokenB, 'B atomically supersedes A');

    wp_fts_mutation_proof_capture(
        $boundaryEvidence,
        $a,
        'a_stale_promote',
        'promote',
        'rejected_by_cas',
        static fn() => $qa->promote_post($postId, $tokenA)
    );
    wp_fts_mutation_proof_row($a, $table, $postId, 'fenced', 2, $tokenB, 'A cannot promote B generation');

    wp_fts_mutation_proof_capture(
        $boundaryEvidence,
        $c,
        'c_fence_generation_3',
        'fence',
        'won_generation_3',
        static fn() => $qc->fence_post($postId, $tokenC, $recoveryAt, ['source' => 'C'])
    );
    wp_fts_mutation_proof_row($c, $table, $postId, 'fenced', 3, $tokenC, 'C atomically supersedes B');

    wp_fts_mutation_proof_capture(
        $boundaryEvidence,
        $b,
        'b_stale_promote',
        'promote',
        'rejected_by_cas',
        static fn() => $qb->promote_post($postId, $tokenB)
    );
    wp_fts_mutation_proof_row($b, $table, $postId, 'fenced', 3, $tokenC, 'B cannot promote C generation');

    wp_fts_mutation_proof_capture(
        $boundaryEvidence,
        $c,
        'c_owned_promote',
        'promote',
        'promoted_generation_3',
        static fn() => $qc->promote_post($postId, $tokenC)
    );
    wp_fts_mutation_proof_row($c, $table, $postId, 'ready', 3, '', 'C promotes exactly its generation');
    $canonicalRows = (int) $c->get_var("SELECT COUNT(*) FROM `{$table}` WHERE kind='post' AND post_id={$postId}");
    wp_fts_mutation_proof_assert($canonicalRows === 1, 'Concurrent fences must retain exactly one canonical row per post.');

    // A generic enqueue that lands inside a foreground mutation owns the newer
    // desired payload. The older hook may release the fence, but its payload
    // cannot overwrite generation two because claimed_generation remains one.
    $coalescedPostId = 42;
    $coalescedToken = str_repeat('d', 32);
    $qa->fence_post($coalescedPostId, $coalescedToken, $recoveryAt, ['source' => 'foreground']);
    $qb->enqueue_many([$coalescedPostId], null, ['index_options' => ['document_lang' => 'pl']]);
    wp_fts_mutation_proof_row($b, $table, $coalescedPostId, 'fenced', 2, $coalescedToken, 'enqueue preserves the active token');
    $qa->promote_post($coalescedPostId, $coalescedToken);
    $coalesced = wp_fts_mutation_proof_row($a, $table, $coalescedPostId, 'ready', 2, '', 'the foreground hook releases the coalesced generation');
    $coalescedPayload = json_decode((string) ($coalesced['payload'] ?? ''), true, flags: JSON_THROW_ON_ERROR);
    wp_fts_mutation_proof_assert(
        ($coalescedPayload['index_options']['document_lang'] ?? null) === 'pl',
        'The newer generic payload must survive an older foreground promotion.'
    );

    $readyClaims = $qc->claim_batch(100, time() + 1, 30);
    wp_fts_mutation_proof_assert(count($readyClaims) === 2, 'The two ready canonical generations should be claimable once each.');
    $readyAck = $qc->acknowledge_many($readyClaims);
    wp_fts_mutation_proof_assert(($readyAck['acknowledged'] ?? -1) === 2, 'The two ready canonical generations should acknowledge once each.');

    // Hold the exact production guard in an independent PHP process. This must
    // exercise the kernel flock path: a same-process guard would be satisfied
    // by IndexQueue's static shortcut and would prove nothing about web/cron or
    // WP-CLI process isolation.
    $guardedPostId = 45;
    $fencedPostId = 46;
    $unrelatedReadyPostId = 47;
    $guardedToken = 'guard:' . str_repeat('8', 32);
    $fencedToken = str_repeat('9', 32);
    $guardNow = time();
    $holder = wp_fts_mutation_proof_start_foreground_holder($prefix);
    $foregroundHolderProcess = $holder['process'];
    $foregroundHolderPipes = $holder['pipes'];
    $holderReady = $holder['ready'];
    $guardPathMethod = new ReflectionMethod(WP_FTS_Index_Queue::class, 'foreground_owner_guard_path');
    $parentGuardPath = (string) $guardPathMethod->invoke($qc);
    clearstatcache(true, $parentGuardPath);
    $parentGuardStat = @lstat($parentGuardPath);
    $guardPathsProperty = new ReflectionProperty(WP_FTS_Index_Queue::class, 'foregroundOwnerGuardPaths');
    $parentGuardPaths = $guardPathsProperty->getValue();
    $parentStaticGuardCount = is_array($parentGuardPaths)
        ? (int) ($parentGuardPaths[$parentGuardPath] ?? 0)
        : -1;
    wp_fts_mutation_proof_assert(
        (int) ($holderReady['pid'] ?? 0) > 0
            && (int) ($holderReady['pid'] ?? 0) !== getmypid()
            && (int) ($holderReady['pid'] ?? 0) === (int) ($holder['runtime_pid'] ?? 0),
        'The foreground owner must be an independently tracked PHP process.'
    );
    wp_fts_mutation_proof_assert(
        is_array($parentGuardStat)
            && ($holderReady['path'] ?? null) === $parentGuardPath
            && (int) ($holderReady['device'] ?? -1) === (int) ($parentGuardStat['dev'] ?? -2)
            && (int) ($holderReady['inode'] ?? -1) === (int) ($parentGuardStat['ino'] ?? -2)
            && $parentStaticGuardCount === 0,
        'The child must hold the parent worker path/inode without populating the parent static guard map.'
    );
    $queueSha256 = hash_file('sha256', $queuePath);
    wp_fts_mutation_proof_assert(
        is_string($queueSha256)
            && ($holderReady['queue_path'] ?? null) === realpath($queuePath)
            && ($holderReady['queue_sha256'] ?? null) === $queueSha256
            && ($holderReady['proof_sha256'] ?? null) === hash_file('sha256', __FILE__),
        'The independent holder must load the same source-bound queue and proof file.'
    );

    $qa->fence_post($guardedPostId, $guardedToken, $guardNow - 2, ['source' => 'live-owner']);
    $qa->fence_post($fencedPostId, $fencedToken, $guardNow - 1, ['source' => 'operator-only']);
    $qa->enqueue_many([$unrelatedReadyPostId], $guardNow - 3);
    $noiseDueAt = $guardNow + 7200;
    $noiseValues = [];
    for ($noiseOffset = 0; $noiseOffset < 512; $noiseOffset++) {
        $noisePostId = 10000 + $noiseOffset;
        $noiseValues[] = "('post:{$noisePostId}','post',{$noisePostId},1,'ready',{$noiseDueAt})";
    }
    $a->query(
        "INSERT INTO `{$table}` (job_key,kind,post_id,generation,state,available_at) VALUES "
        . implode(',', $noiseValues)
    );
    $seededGuardStates = wp_fts_mutation_proof_post_states(
        $c->dbh,
        $table,
        [$guardedPostId, $fencedPostId, $unrelatedReadyPostId]
    );
    wp_fts_mutation_proof_assert(
        $seededGuardStates === [
            ['post_id' => $guardedPostId, 'generation' => 1, 'state' => 'guarded', 'claimed_generation' => 1, 'token_class' => 'guard'],
            ['post_id' => $fencedPostId, 'generation' => 1, 'state' => 'fenced', 'claimed_generation' => 1, 'token_class' => 'unmarked'],
            ['post_id' => $unrelatedReadyPostId, 'generation' => 1, 'state' => 'ready', 'claimed_generation' => 0, 'token_class' => 'empty'],
        ],
        'The real table must distinguish guarded owner work, operator-fenced debt, and unrelated ready work.'
    );

    $guardedClaimMarker = $c->statement_marker();
    $claimsBehindGuard = $qc->claim_batch(100, $guardNow, 30);
    $guardedClaimStatements = $c->statements_since($guardedClaimMarker);
    wp_fts_mutation_proof_assert(
        count($guardedClaimStatements) === 2,
        'A busy foreground guard must add no SQL beyond the bounded claim and confirmation pair.'
    );
    wp_fts_mutation_proof_assert(
        array_column($claimsBehindGuard, 'post_id') === [$unrelatedReadyPostId],
        'A busy cross-process guard must preserve unrelated ready-work progress.'
    );
    $busyProbeState = $qc->foreground_owner_guard_probe_state();
    wp_fts_mutation_proof_assert(
        $busyProbeState === 'busy'
            && str_contains((string) ($guardedClaimStatements[0]['sql'] ?? ''), 'wp_fts:fences-require-free-guard')
            && !str_contains((string) ($guardedClaimStatements[0]['sql'] ?? ''), "state = 'guarded'")
            && !str_contains((string) ($guardedClaimStatements[0]['sql'] ?? ''), "state = 'fenced'"),
        'The real engine busy claim must omit both protected states from its bounded candidate and final CAS.'
    );
    $protectedStatesWhileBusy = wp_fts_mutation_proof_post_states(
        $c->dbh,
        $table,
        [$guardedPostId, $fencedPostId]
    );
    wp_fts_mutation_proof_assert(
        array_column($protectedStatesWhileBusy, 'state', 'post_id') === [
            $guardedPostId => 'guarded',
            $fencedPostId => 'fenced',
        ],
        'The busy real-DB claim must leave guarded and operator-fenced generations unclaimed.'
    );
    $busyClaimEvidence = wp_fts_mutation_proof_operation_evidence(
        $c,
        $guardedClaimStatements,
        $table,
        400,
        ['ready', 'recoverable', 'PRIMARY', 'claim_token'],
        'busy cross-process claim'
    );
    wp_fts_mutation_proof_assert(
        $qc->acknowledge_many([$claimsBehindGuard[0]])['acknowledged'] === 1,
        'The unrelated generation claimed behind the holder should acknowledge once.'
    );

    $nextMarker = $c->statement_marker();
    $watchdogBefore = time();
    $guardedNextAvailable = $qc->next_available_at();
    $watchdogAfter = time();
    $busyScheduleStatements = $c->statements_since($nextMarker);
    $busyScheduleProbeState = $qc->foreground_owner_guard_probe_state();
    wp_fts_mutation_proof_assert(
        count($busyScheduleStatements) === 1
            && $busyScheduleProbeState === 'busy'
            && is_int($guardedNextAvailable)
            && $guardedNextAvailable >= $watchdogBefore + WP_FTS_Index_Queue::FOREGROUND_OWNER_WATCHDOG_SECONDS
            && $guardedNextAvailable <= $watchdogAfter + WP_FTS_Index_Queue::FOREGROUND_OWNER_WATCHDOG_SECONDS,
        'One bounded scheduling statement must project every overdue fence to one watchdog interval.'
    );
    $busyScheduleEvidence = wp_fts_mutation_proof_operation_evidence(
        $c,
        $busyScheduleStatements,
        $table,
        12,
        ['ready', 'recoverable'],
        'busy cross-process schedule'
    );
    $holderAliveAfterBusyQueries = (bool) (proc_get_status($foregroundHolderProcess)['running'] ?? false);
    wp_fts_mutation_proof_assert(
        $holderAliveAfterBusyQueries,
        'The independent holder must remain alive throughout the real claim and scheduling probes.'
    );

    $holderTermination = wp_fts_mutation_proof_kill_foreground_holder(
        $foregroundHolderProcess,
        $foregroundHolderPipes
    );
    $foregroundHolderProcess = null;
    $foregroundHolderPipes = [];
    $recoveredGuardedMarker = $c->statement_marker();
    $recoveredGuardedClaims = $qc->claim_batch(100, $guardNow + 2, 30);
    $recoveredGuardedStatements = $c->statements_since($recoveredGuardedMarker);
    $freeProbeState = $qc->foreground_owner_guard_probe_state();
    wp_fts_mutation_proof_assert(
        count($recoveredGuardedStatements) === 2
            && $freeProbeState === 'free'
            && array_column($recoveredGuardedClaims, 'post_id') === [$guardedPostId],
        'Kernel cleanup after holder death must expose only the guarded generation in one ordinary claim pair.'
    );
    $freeClaimEvidence = wp_fts_mutation_proof_operation_evidence(
        $c,
        $recoveredGuardedStatements,
        $table,
        500,
        ['ready', 'recoverable', 'PRIMARY', 'claim_token'],
        'free guarded recovery claim'
    );
    $fencedStateAfterFreeClaim = wp_fts_mutation_proof_post_states($c->dbh, $table, [$fencedPostId]);
    wp_fts_mutation_proof_assert(
        ($fencedStateAfterFreeClaim[0]['state'] ?? null) === 'fenced',
        'A free probe must never recover operator-fenced debt.'
    );
    wp_fts_mutation_proof_assert(
        $qc->acknowledge_many([$recoveredGuardedClaims[0]])['acknowledged'] === 1,
        'The exact recovered guarded generation should acknowledge once.'
    );
    $blockedScheduleMarker = $c->statement_marker();
    $freeNextAvailable = $qc->next_available_at();
    $freeScheduleStatements = $c->statements_since($blockedScheduleMarker);
    $freeScheduleProbeState = $qc->foreground_owner_guard_probe_state();
    wp_fts_mutation_proof_assert(
        $freeNextAvailable === $noiseDueAt
            && $freeScheduleProbeState === 'free'
            && count($freeScheduleStatements) === 1
            && !str_contains((string) ($freeScheduleStatements[0]['sql'] ?? ''), "state = 'fenced'"),
        'A free scheduling read must omit overdue operator debt and return the unrelated future due time.'
    );
    $freeScheduleEvidence = wp_fts_mutation_proof_operation_evidence(
        $c,
        $freeScheduleStatements,
        $table,
        10,
        ['ready', 'recoverable'],
        'free guarded-only schedule'
    );
    $qa->promote_post($fencedPostId, $fencedToken, $guardNow + 3);
    $promotedFallbackMarker = $c->statement_marker();
    $promotedFallbackClaims = $qc->claim_batch(100, $guardNow + 3, 30);
    $promotedFallbackStatements = $c->statements_since($promotedFallbackMarker);
    wp_fts_mutation_proof_assert(
        count($promotedFallbackStatements) === 2
            && array_column($promotedFallbackClaims, 'post_id') === [$fencedPostId]
            && $qc->acknowledge_many([$promotedFallbackClaims[0]])['acknowledged'] === 1,
        'Only the authoritative post-SQL promotion may make an unmarked fallback claimable.'
    );
    $promotedFencedClaimEvidence = wp_fts_mutation_proof_operation_evidence(
        $c,
        $promotedFallbackStatements,
        $table,
        500,
        ['ready', 'recoverable', 'PRIMARY', 'claim_token'],
        'authoritative fenced promotion claim'
    );
    $crossProcessGuardEvidence = [
        'schema' => 'relational-fts-cross-process-owner-guard-v1',
        'status' => 'PASS',
        'parent_pid' => getmypid(),
        'holder_pid' => (int) $holderReady['pid'],
        'holder_runtime_pid' => (int) $holder['runtime_pid'],
        'process_is_independent' => true,
        'holder_queue_path' => (string) $holderReady['queue_path'],
        'holder_queue_sha256' => (string) $holderReady['queue_sha256'],
        'parent_queue_sha256' => $queueSha256,
        'holder_proof_sha256' => (string) $holderReady['proof_sha256'],
        'holder_lock_path' => (string) $holderReady['path'],
        'parent_lock_path' => $parentGuardPath,
        'holder_lock_device' => (int) $holderReady['device'],
        'parent_lock_device' => (int) $parentGuardStat['dev'],
        'holder_lock_inode' => (int) $holderReady['inode'],
        'parent_lock_inode' => (int) $parentGuardStat['ino'],
        'same_lock_file' => true,
        'parent_static_guard_count' => $parentStaticGuardCount,
        'seeded_post_ids' => [$guardedPostId, $fencedPostId, $unrelatedReadyPostId],
        'seeded_states' => $seededGuardStates,
        'optimizer_noise_rows' => 512,
        'optimizer_noise_due_at' => $noiseDueAt,
        'busy_probe_state' => $busyProbeState,
        'busy_claimed_post_ids' => array_column($claimsBehindGuard, 'post_id'),
        'busy_protected_states' => $protectedStatesWhileBusy,
        'busy_claim_guarded_predicate_count' => substr_count((string) $guardedClaimStatements[0]['sql'], "state = 'guarded'"),
        'busy_claim_fenced_predicate_count' => substr_count((string) $guardedClaimStatements[0]['sql'], "state = 'fenced'"),
        'busy_claim' => $busyClaimEvidence,
        'busy_schedule_probe_state' => $busyScheduleProbeState,
        'busy_next_available' => $guardedNextAvailable,
        'busy_watchdog_min' => $watchdogBefore + WP_FTS_Index_Queue::FOREGROUND_OWNER_WATCHDOG_SECONDS,
        'busy_watchdog_max' => $watchdogAfter + WP_FTS_Index_Queue::FOREGROUND_OWNER_WATCHDOG_SECONDS,
        'busy_schedule_guarded_predicate_count' => substr_count((string) $busyScheduleStatements[0]['sql'], "state = 'guarded'"),
        'busy_schedule_fenced_predicate_count' => substr_count((string) $busyScheduleStatements[0]['sql'], "state = 'fenced'"),
        'busy_schedule' => $busyScheduleEvidence,
        'holder_alive_after_busy_queries' => $holderAliveAfterBusyQueries,
        'kill_signal_requested' => $holderTermination['signal_requested'],
        'kill_observed' => $holderTermination['signaled'],
        'kill_observed_signal' => $holderTermination['term_signal'],
        'process_reaped' => $holderTermination['reaped'],
        'free_probe_state' => $freeProbeState,
        'free_claimed_post_ids' => array_column($recoveredGuardedClaims, 'post_id'),
        'free_claim_guarded_predicate_count' => substr_count((string) $recoveredGuardedStatements[0]['sql'], "state = 'guarded'"),
        'free_claim_fenced_predicate_count' => substr_count((string) $recoveredGuardedStatements[0]['sql'], "state = 'fenced'"),
        'free_claim' => $freeClaimEvidence,
        'fenced_state_after_free_claim' => $fencedStateAfterFreeClaim,
        'free_schedule_probe_state' => $freeScheduleProbeState,
        'free_next_available' => $freeNextAvailable,
        'free_schedule_guarded_predicate_count' => substr_count((string) $freeScheduleStatements[0]['sql'], "state = 'guarded'"),
        'free_schedule_fenced_predicate_count' => substr_count((string) $freeScheduleStatements[0]['sql'], "state = 'fenced'"),
        'free_schedule' => $freeScheduleEvidence,
        'promoted_fenced_claimed_post_ids' => array_column($promotedFallbackClaims, 'post_id'),
        'promoted_fenced_claim' => $promotedFencedClaimEvidence,
    ];

    // A crashed request leaves one durable fenced generation. No session state
    // is required: after available_at the ordinary claim CAS replaces its token.
    $crashPostId = 43;
    $crashToken = 'guard:' . str_repeat('e', 32);
    wp_fts_mutation_proof_capture(
        $boundaryEvidence,
        $a,
        'crash_fence',
        'fence',
        'durable_generation_1',
        static fn() => $qa->fence_post($crashPostId, $crashToken, $recoveryAt, ['source' => 'crash'])
    );
    $a->dbh->close();
    $aClosedForCrash = true;
    wp_fts_mutation_proof_assert($qc->claim_batch(1, $recoveryAt - 1, 30) === [], 'A fenced generation must wait until its durable recovery time.');
    $recoveredClaims = $qc->claim_batch(1, $recoveryAt, 30);
    wp_fts_mutation_proof_assert(count($recoveredClaims) === 1, 'The elapsed fenced generation should be claimed through the ordinary CAS path.');
    $recovered = $recoveredClaims[0];
    wp_fts_mutation_proof_assert(
        (int) ($recovered['post_id'] ?? 0) === $crashPostId
            && (int) ($recovered['generation'] ?? 0) === 1
            && ($recovered['token'] ?? '') !== $crashToken,
        'Crash recovery must own exactly generation one under a fresh claim token.'
    );
    wp_fts_mutation_proof_assert($qc->acknowledge_many([$recovered])['acknowledged'] === 1, 'The recovered generation should acknowledge once.');
    wp_fts_mutation_proof_assert($qc->acknowledge_many([$recovered])['acknowledged'] === 0, 'The same recovered CAS must not acknowledge twice.');
    $crashRows = (int) $c->get_var("SELECT COUNT(*) FROM `{$table}` WHERE job_key='post:{$crashPostId}'");
    wp_fts_mutation_proof_assert($crashRows === 0, 'The recovered crash generation must leave no durable row.');

    // A foreground hook may arrive after the watchdog deadline and after a
    // worker recovered the fenced generation. The hook must revoke that stale
    // lease while preserving intent coalesced by a newer actor.
    $postdeadlineAt = $recoveryAt + 100;
    $postdeadlinePostId = 44;
    $postdeadlinePostToken = 'guard:' . str_repeat('4', 32);
    $postdeadlinePostPayload = ['index_options' => ['document_lang' => 'pl']];
    $qb->fence_post($postdeadlinePostId, $postdeadlinePostToken, $postdeadlineAt, ['source' => 'foreground']);
    $qc->enqueue_many([$postdeadlinePostId], $postdeadlineAt - 1, $postdeadlinePostPayload);
    $postdeadlineRecovered = $qc->claim_batch(1, $postdeadlineAt, 30)[0] ?? null;
    wp_fts_mutation_proof_assert(is_array($postdeadlineRecovered), 'MySQL should recover the coalesced direct generation at its watchdog deadline.');
    $qb->promote_post($postdeadlinePostId, $postdeadlinePostToken, $postdeadlineAt + 1);
    $postdeadlinePostResult = $b->dbh->query(
        "SELECT state,payload FROM `{$table}` WHERE kind='post' AND post_id={$postdeadlinePostId}"
    );
    $postdeadlinePostRow = $postdeadlinePostResult instanceof mysqli_result
        ? $postdeadlinePostResult->fetch_assoc()
        : null;
    if ($postdeadlinePostResult instanceof mysqli_result) {
        $postdeadlinePostResult->free();
    }
    wp_fts_mutation_proof_assert(
        is_array($postdeadlinePostRow)
            && ($postdeadlinePostRow['state'] ?? null) === 'ready'
            && json_decode((string) ($postdeadlinePostRow['payload'] ?? ''), true, flags: JSON_THROW_ON_ERROR) === $postdeadlinePostPayload,
        'Late MySQL post promotion must publish a successor without replacing coalesced index options.'
    );
    wp_fts_mutation_proof_assert(
        $qc->acknowledge_many([$postdeadlineRecovered])['acknowledged'] === 0,
        'The recovered MySQL post worker must not acknowledge the post-hook successor.'
    );
    $postdeadlinePostSuccessor = $qc->claim_batch(1, $postdeadlineAt + 2, 30)[0] ?? null;
    wp_fts_mutation_proof_assert(is_array($postdeadlinePostSuccessor), 'The MySQL post-hook successor should remain claimable.');
    wp_fts_mutation_proof_assert($qc->acknowledge_many([$postdeadlinePostSuccessor])['acknowledged'] === 1, 'Only the MySQL post-hook successor should acknowledge.');

    $postdeadlineScopeKey = 'real-postdeadline-targeted';
    $postdeadlineScopeToken = 'guard:' . str_repeat('5', 32);
    $postdeadlineScopePayload = ['reason' => 'newer-coalesced-scope'];
    $qb->fence_scope(
        $postdeadlineScopeKey,
        $postdeadlineScopeToken,
        ['reason' => 'foreground'],
        $postdeadlineAt,
        WP_FTS_Index_Queue::SCOPE_COVERAGE_TARGETED,
        'term_taxonomy',
        77
    );
    $qc->enqueue_scope(
        $postdeadlineScopeKey,
        $postdeadlineScopePayload,
        $postdeadlineAt - 1,
        WP_FTS_Index_Queue::SCOPE_COVERAGE_TARGETED,
        'term_taxonomy',
        99
    );
    $postdeadlineScopeRecovered = $qc->claim_batch(0, $postdeadlineAt, 30)[0] ?? null;
    wp_fts_mutation_proof_assert(is_array($postdeadlineScopeRecovered), 'MySQL should recover the coalesced scope generation at its watchdog deadline.');
    $qb->promote_scope(
        $postdeadlineScopeKey,
        $postdeadlineScopeToken,
        ['reason' => 'late-hook'],
        $postdeadlineAt + 1,
        WP_FTS_Index_Queue::SCOPE_COVERAGE_TARGETED,
        'term_taxonomy',
        88
    );
    $postdeadlineScopeJob = 'scope:' . hash('sha256', $postdeadlineScopeKey);
    $postdeadlineScopeResult = $b->dbh->query(
        "SELECT state,scope_subject_id,payload FROM `{$table}` WHERE job_key='{$postdeadlineScopeJob}'"
    );
    $postdeadlineScopeRow = $postdeadlineScopeResult instanceof mysqli_result
        ? $postdeadlineScopeResult->fetch_assoc()
        : null;
    if ($postdeadlineScopeResult instanceof mysqli_result) {
        $postdeadlineScopeResult->free();
    }
    wp_fts_mutation_proof_assert(
        is_array($postdeadlineScopeRow)
            && ($postdeadlineScopeRow['state'] ?? null) === 'ready'
            && (int) ($postdeadlineScopeRow['scope_subject_id'] ?? 0) === 99
            && json_decode((string) ($postdeadlineScopeRow['payload'] ?? ''), true, flags: JSON_THROW_ON_ERROR) === $postdeadlineScopePayload,
        'Late MySQL scope promotion must publish a successor without replacing coalesced payload or authority.'
    );
    wp_fts_mutation_proof_assert(
        !$qc->acknowledge_scope($postdeadlineScopeRecovered, $postdeadlineAt + 2),
        'The recovered MySQL scope worker must not acknowledge the scope-hook successor.'
    );
    $postdeadlineScopeSuccessor = $qc->claim_batch(0, $postdeadlineAt + 2, 30)[0] ?? null;
    wp_fts_mutation_proof_assert(
        is_array($postdeadlineScopeSuccessor)
            && (int) ($postdeadlineScopeSuccessor['scope_subject_id'] ?? 0) === 99
            && ($postdeadlineScopeSuccessor['payload'] ?? null) === $postdeadlineScopePayload,
        'The next MySQL scope claim should retain the newer coalesced payload and authority.'
    );
    wp_fts_mutation_proof_assert($qc->acknowledge_scope($postdeadlineScopeSuccessor, $postdeadlineAt + 3), 'Only the MySQL scope-hook successor should acknowledge.');

    // Exercise the production handoff SQL itself. This catches differences in
    // MySQL/MariaDB assignment semantics that a stateful fake cannot model.
    $exactPostId = 501;
    $exactPostToken = str_repeat('f', 32);
    $exactScopeKey = 'real-exact-sentinel';
    $exactScopeToken = str_repeat('1', 32);
    $qb->fence_post($exactPostId, $exactPostToken, $recoveryAt);
    $qb->fence_scope($exactScopeKey, $exactScopeToken, ['reason' => 'exact'], $recoveryAt);
    $exactMarker = $b->statement_marker();
    $qb->handoff_foreground_mutation_scope(
        $exactScopeKey,
        $exactScopeToken,
        [$exactPostId],
        [$exactPostId => $exactPostToken],
        [],
        false,
        [],
        time()
    );
    $exactHandoffStatementCount = count($b->statements_since($exactMarker));
    wp_fts_mutation_proof_assert($exactHandoffStatementCount === 2, 'Exact handoff must use one canonical post UPSERT and one sentinel DELETE.');
    $exactState = (string) $b->get_var("SELECT state FROM `{$table}` WHERE job_key='post:{$exactPostId}'");
    wp_fts_mutation_proof_assert($exactState === 'ready', 'Relational SQL must release the owned post fence.');
    $exactScopeJob = 'scope:' . hash('sha256', $exactScopeKey);
    $exactScopeRows = (int) $b->get_var("SELECT COUNT(*) FROM `{$table}` WHERE job_key='{$exactScopeJob}'");
    wp_fts_mutation_proof_assert($exactScopeRows === 0, 'Exact handoff must remove its request sentinel.');

    $targetScopeKey = 'real-concurrent-targeted-scope';
    $targetScopeToken = str_repeat('2', 32);
    $ownedTargetScopeKey = 'real-owned-targeted-scope';
    $ownedTargetScopeToken = str_repeat('6', 32);
    $staleGlobalScopeKey = 'real-stale-token-global-sentinel';
    $staleGlobalScopeToken = str_repeat('7', 32);
    $globalScopeKey = 'real-global-sentinel';
    $globalScopeToken = str_repeat('3', 32);
    $qb->fence_scope($targetScopeKey, $targetScopeToken, [], $recoveryAt, WP_FTS_Index_Queue::SCOPE_COVERAGE_TARGETED, 'term_taxonomy', 77);
    $qb->promote_scope($targetScopeKey, $targetScopeToken, [], null, WP_FTS_Index_Queue::SCOPE_COVERAGE_TARGETED, 'term_taxonomy', 77);
    $targetScopeJob = 'scope:' . hash('sha256', $targetScopeKey);
    wp_fts_mutation_proof_assert(
        (string) $b->get_var("SELECT claim_token FROM `{$table}` WHERE job_key='{$targetScopeJob}'") === '',
        'A matching MySQL scope promotion must clear its request token.'
    );
    // Advance the completed row outside the foreground request. The original
    // token can no longer prove ownership, so corpus handoff must retain it.
    $qc->enqueue_scope(
        $targetScopeKey,
        ['reason' => 'concurrent-targeted-successor'],
        null,
        WP_FTS_Index_Queue::SCOPE_COVERAGE_TARGETED,
        'term_taxonomy',
        77
    );
    $qb->fence_scope(
        $staleGlobalScopeKey,
        $staleGlobalScopeToken,
        ['reason' => 'stale-token-global'],
        $recoveryAt,
        WP_FTS_Index_Queue::SCOPE_COVERAGE_GLOBAL
    );
    $staleHandoffMarker = $b->statement_marker();
    $qb->handoff_foreground_mutation_scope(
        $staleGlobalScopeKey,
        $staleGlobalScopeToken,
        [],
        [],
        [$targetScopeKey => $targetScopeToken],
        true,
        ['reason' => 'stale-token-global'],
        time(),
        '0123456789abcdef0123456789abcdef'
    );
    $staleHandoffStatements = $b->statements_since($staleHandoffMarker);
    wp_fts_mutation_proof_assert(
        count($staleHandoffStatements) === 3,
        'A stale-token corpus handoff must retain the same three fixed-shape statements.'
    );
    $staleCorpusPublicationProof = wp_fts_mutation_proof_corpus_publication(
        $b,
        $staleHandoffStatements,
        $table,
        $staleGlobalScopeKey,
        1,
        '0123456789abcdef0123456789abcdef',
        'stale-token-global'
    );
    $staleOwnedDeleteStatements = array_values(array_filter(
        $staleHandoffStatements,
        static fn(array $statement): bool => str_contains(
            (string) ($statement['sql'] ?? ''),
            'wp_fts:foreground-owned-scope-delete'
        )
    ));
    wp_fts_mutation_proof_assert(
        count($staleOwnedDeleteStatements) === 1
            && (int) ($staleOwnedDeleteStatements[0]['affected_rows'] ?? -1) === 0,
        'The old token must execute one indexed CAS delete that affects zero newer rows.'
    );
    $staleTargetResult = $b->dbh->query(
        "SELECT generation,state,claim_token,scope_subject_type,scope_subject_id,payload
         FROM `{$table}` WHERE job_key='{$targetScopeJob}'"
    );
    $staleTargetRow = $staleTargetResult instanceof mysqli_result
        ? $staleTargetResult->fetch_assoc()
        : null;
    if ($staleTargetResult instanceof mysqli_result) {
        $staleTargetResult->free();
    }
    $staleTargetPayload = is_array($staleTargetRow)
        ? json_decode((string) ($staleTargetRow['payload'] ?? ''), true, flags: JSON_THROW_ON_ERROR)
        : [];
    wp_fts_mutation_proof_assert(
        is_array($staleTargetRow)
            && (int) ($staleTargetRow['generation'] ?? 0) === 2
            && ($staleTargetRow['state'] ?? null) === 'ready'
            && ($staleTargetRow['claim_token'] ?? null) === ''
            && ($staleTargetRow['scope_subject_type'] ?? null) === 'term_taxonomy'
            && (int) ($staleTargetRow['scope_subject_id'] ?? 0) === 77
            && ($staleTargetPayload['reason'] ?? null) === 'concurrent-targeted-successor',
        'The stale PK/token CAS must preserve the newer targeted generation, authority, and payload.'
    );
    $staleOwnedScopeCasProof = [
        'handoff_statement_count' => count($staleHandoffStatements),
        'delete_statement_count' => count($staleOwnedDeleteStatements),
        'delete_affected_rows' => (int) $staleOwnedDeleteStatements[0]['affected_rows'],
        'delete_sql_bytes' => strlen((string) $staleOwnedDeleteStatements[0]['sql']),
        'delete_elapsed_ms' => round((float) $staleOwnedDeleteStatements[0]['elapsed_ms'], 3),
        'surviving_rows' => 1,
        'surviving_generation' => (int) $staleTargetRow['generation'],
        'newer_payload_preserved' => true,
    ];
    $qb->fence_scope($ownedTargetScopeKey, $ownedTargetScopeToken, [], $recoveryAt, WP_FTS_Index_Queue::SCOPE_COVERAGE_TARGETED, 'term_taxonomy', 78);
    $qb->fence_scope($globalScopeKey, $globalScopeToken, ['reason' => 'global'], $recoveryAt, WP_FTS_Index_Queue::SCOPE_COVERAGE_GLOBAL);
    $globalMarker = $b->statement_marker();
    $qb->handoff_foreground_mutation_scope(
        $globalScopeKey,
        $globalScopeToken,
        [],
        [],
        [$ownedTargetScopeKey => $ownedTargetScopeToken],
        true,
        ['reason' => 'global'],
        time(),
        '0123456789abcdef0123456789abcdef'
    );
    $globalHandoffStatements = $b->statements_since($globalMarker);
    $globalHandoffStatementCount = count($globalHandoffStatements);
    wp_fts_mutation_proof_assert(
        $globalHandoffStatementCount === 3,
        'Corpus handoff at the structural maximum must use one canonical UPSERT and two exact one-row deletes.'
    );
    $ownedCorpusPublicationProof = wp_fts_mutation_proof_corpus_publication(
        $b,
        $globalHandoffStatements,
        $table,
        $globalScopeKey,
        2,
        '0123456789abcdef0123456789abcdef',
        'global'
    );
    $ownedScopeDeleteStatements = array_values(array_filter(
        $globalHandoffStatements,
        static fn(array $statement): bool => str_contains(
            (string) ($statement['sql'] ?? ''),
            'wp_fts:foreground-owned-scope-delete'
        )
    ));
    wp_fts_mutation_proof_assert(
        count($ownedScopeDeleteStatements) === 1,
        'Corpus handoff must execute exactly one production owned-scope delete.'
    );
    $ownedScopeDelete = $ownedScopeDeleteStatements[0];
    $ownedScopeDeleteSql = (string) ($ownedScopeDelete['sql'] ?? '');
    wp_fts_mutation_proof_assert(
        (int) ($ownedScopeDelete['affected_rows'] ?? -1) === 1,
        'The production owned-scope CAS delete must affect exactly one row.'
    );
    wp_fts_mutation_proof_assert(
        strlen($ownedScopeDeleteSql) < 512,
        'The production owned-scope CAS delete must remain below 512 bytes.'
    );
    $ownedDeletePlanResult = $b->dbh->query('EXPLAIN ' . $ownedScopeDeleteSql);
    $ownedDeletePlan = $ownedDeletePlanResult instanceof mysqli_result
        ? $ownedDeletePlanResult->fetch_assoc()
        : null;
    if ($ownedDeletePlanResult instanceof mysqli_result) {
        $ownedDeletePlanResult->free();
    }
    wp_fts_mutation_proof_assert(is_array($ownedDeletePlan), 'The engine must explain the production owned-scope delete.');
    $ownedDeletePlanKey = (string) ($ownedDeletePlan['key'] ?? '');
    $ownedDeletePlanRows = (int) ($ownedDeletePlan['rows'] ?? PHP_INT_MAX);
    $ownedDeletePlanExtra = strtolower((string) ($ownedDeletePlan['Extra'] ?? ''));
    wp_fts_mutation_proof_assert(
        strcasecmp($ownedDeletePlanKey, 'PRIMARY') === 0,
        'The owned-scope delete must use the work-table primary key.'
    );
    wp_fts_mutation_proof_assert($ownedDeletePlanRows <= 1, 'The engine must estimate at most one row for the owned-scope delete.');
    wp_fts_mutation_proof_assert(
        !str_contains($ownedDeletePlanExtra, 'temporary') && !str_contains($ownedDeletePlanExtra, 'filesort'),
        'The owned-scope delete must require neither a temporary table nor a filesort.'
    );
    $ownedTargetScopeJob = 'scope:' . hash('sha256', $ownedTargetScopeKey);
    $ownedTargetScopeResidue = (int) $b->get_var(
        "SELECT COUNT(*) FROM `{$table}` WHERE job_key='{$ownedTargetScopeJob}'"
    );
    wp_fts_mutation_proof_assert($ownedTargetScopeResidue === 0, 'The exact owned targeted generation must leave no residue.');
    $ownedScopeDeleteProof = [
        'statement_count' => count($ownedScopeDeleteStatements),
        'affected_rows' => (int) $ownedScopeDelete['affected_rows'],
        'residue_rows' => $ownedTargetScopeResidue,
        'sql_bytes' => strlen($ownedScopeDeleteSql),
        'elapsed_ms' => round((float) $ownedScopeDelete['elapsed_ms'], 3),
        'sql_sha256' => hash('sha256', $ownedScopeDeleteSql),
        'plan_key' => $ownedDeletePlanKey,
        'plan_rows' => $ownedDeletePlanRows,
        'uses_temporary' => str_contains($ownedDeletePlanExtra, 'temporary'),
        'uses_filesort' => str_contains($ownedDeletePlanExtra, 'filesort'),
    ];
    $targetResult = $b->dbh->query("SELECT generation,state,scope_subject_type,scope_subject_id,payload FROM `{$table}` WHERE job_key='{$targetScopeJob}'");
    $targetRow = $targetResult instanceof mysqli_result ? $targetResult->fetch_assoc() : null;
    if ($targetResult instanceof mysqli_result) {
        $targetResult->free();
    }
    wp_fts_mutation_proof_assert(
        is_array($targetRow)
            && (int) ($targetRow['generation'] ?? 0) === 2
            && ($targetRow['state'] ?? null) === 'ready'
            && ($targetRow['scope_subject_type'] ?? null) === 'term_taxonomy'
            && (int) ($targetRow['scope_subject_id'] ?? 0) === 77,
        'A concurrently advanced targeted scope must never be deleted or rewritten into a corpus scope.'
    );

    for ($offset = 0; $offset < 20; $offset++) {
        $loopPostId = 600 + $offset;
        $loopPostToken = substr(hash('sha256', 'post-' . $offset), 0, 32);
        $loopScopeKey = 'real-exact-loop-' . $offset;
        $loopScopeToken = substr(hash('sha256', 'scope-' . $offset), 0, 32);
        $qb->fence_post($loopPostId, $loopPostToken, $recoveryAt);
        $qb->fence_scope($loopScopeKey, $loopScopeToken, [], $recoveryAt);
        $qb->handoff_foreground_mutation_scope(
            $loopScopeKey,
            $loopScopeToken,
            [$loopPostId],
            [$loopPostId => $loopPostToken],
            [],
            false,
            [],
            time()
        );
    }
    $handoffTombstones = (int) $b->get_var(
        "SELECT COUNT(*) FROM `{$table}` WHERE kind='meta' AND job_key <> 'meta:search-epoch'"
    );
    wp_fts_mutation_proof_assert($handoffTombstones === 0, 'Repeated exact handoffs must not accumulate metadata tombstones.');

    $fenceStatementCounts = wp_fts_mutation_proof_statement_counts($boundaryEvidence, 'fence');
    $promotionStatementCounts = wp_fts_mutation_proof_statement_counts($boundaryEvidence, 'promote');
    $fenceStatementsPerBoundary = wp_fts_mutation_proof_uniform_statement_count(
        $fenceStatementCounts,
        'Every canonical fence'
    );
    wp_fts_mutation_proof_assert($fenceStatementsPerBoundary === 1, 'Every canonical fence must execute exactly one generation UPSERT.');
    $promotionStatementsPerBoundary = wp_fts_mutation_proof_uniform_statement_count(
        $promotionStatementCounts,
        'Every mutation promotion'
    );
    wp_fts_mutation_proof_assert($promotionStatementsPerBoundary === 1, 'Every mutation promotion must execute exactly one CAS UPSERT.');
    foreach ($boundaryEvidence as $boundary) {
        foreach ($boundary['statements'] ?? [] as $statement) {
            $sql = strtoupper((string) ($statement['redacted_sql'] ?? ''));
            wp_fts_mutation_proof_assert(
                !str_contains($sql, 'GET_LOCK') && !str_contains($sql, 'RELEASE_LOCK'),
                'Canonical mutation fencing must not emit session advisory-lock SQL.'
            );
        }
    }

    $wpPath = trim((string) getenv('WP_FTS_WP_PATH'));
    if ($wpPath === '') {
        $wpPath = '/var/www/html';
    }
    $wpLoad = rtrim($wpPath, '/\\') . '/wp-load.php';
    wp_fts_mutation_proof_assert(is_file($wpLoad), 'The production-worker proof requires the disposable WordPress bootstrap.');
    require_once $wpLoad;
    $productionWorker = wp_fts_mutation_proof_production_worker_cas();

    echo json_encode([
        'status' => 'PASS',
        'schema' => 'relational-fts-mutation-generation-cas-v4',
        'engine' => (string) getenv('WP_FTS_WC_ENGINE'),
        'source_sha' => (string) getenv('WP_FTS_SOURCE_SHA'),
        'proof_sha256' => hash_file('sha256', __FILE__),
        'connections' => 3,
        'canonical_rows_after_three_fences' => $canonicalRows,
        'final_generation' => 3,
        'stale_promotions_rejected' => 2,
        'crash_recovered_generation' => 1,
        'crash_remaining_rows' => $crashRows,
        'cross_process_foreground_guard' => $crossProcessGuardEvidence,
        'postdeadline_direct_successor_preserved' => true,
        'postdeadline_scope_successor_preserved' => true,
        'exact_handoff_statements' => $exactHandoffStatementCount,
        'max_owned_scope_corpus_handoff_statements' => $globalHandoffStatementCount,
        'corpus_handoff_publication_proofs' => [
            'stale_owned_token' => $staleCorpusPublicationProof,
            'exact_owned_token' => $ownedCorpusPublicationProof,
        ],
        'owned_scope_delete_proof' => $ownedScopeDeleteProof,
        'stale_owned_scope_cas_proof' => $staleOwnedScopeCasProof,
        'handoff_tombstones_after_20_exact_requests' => $handoffTombstones,
        'ready_targeted_scope_preserved' => true,
        'statement_evidence_schema' => 'relational-fts-mutation-statements-v1',
        'statement_evidence_max_redacted_sql_bytes' => 2048,
        'measured_boundary_count' => count($boundaryEvidence),
        'fence_statements_per_boundary' => $fenceStatementsPerBoundary,
        'promotion_statements_per_boundary' => $promotionStatementsPerBoundary,
        'fence_boundary_statement_counts' => $fenceStatementCounts,
        'promotion_boundary_statement_counts' => $promotionStatementCounts,
        'boundary_statement_evidence' => $boundaryEvidence,
        'production_worker_cas' => $productionWorker,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
} finally {
    if (is_resource($foregroundHolderProcess)) {
        @proc_terminate($foregroundHolderProcess, 9);
        foreach ($foregroundHolderPipes as $pipe) {
            if (is_resource($pipe)) {
                @fclose($pipe);
            }
        }
        @proc_close($foregroundHolderProcess);
    }
    if (isset($connections) && is_array($connections)) {
        foreach ($connections as $connection) {
            if ($connection instanceof WP_FTS_Mutation_Proof_WPDB && (!$aClosedForCrash || $connection !== ($connections[0] ?? null))) {
                $connection->query("DROP TABLE IF EXISTS `{$table}`, `{$documentsTable}`, `{$postsTable}`");
                break;
            }
        }
        foreach ($connections as $connection) {
            if ($connection instanceof WP_FTS_Mutation_Proof_WPDB) {
                if ($aClosedForCrash && $connection === ($connections[0] ?? null)) {
                    continue;
                }
                $connection->dbh->close();
            }
        }
    }
}

/** Run only in the independent child selected before the MySQL proof starts. */
function wp_fts_mutation_proof_foreground_owner_holder(string $prefix): void
{
    if ($prefix === '' || strlen($prefix) > 64) {
        throw new InvalidArgumentException('The foreground owner holder requires the bounded parent table prefix.');
    }
    $wpdb = new class {
        public string $prefix = '';
        public string $posts = '';
    };
    $wpdb->prefix = $prefix;
    $wpdb->posts = $prefix . 'posts';
    $queue = new WP_FTS_Index_Queue($wpdb, $prefix);
    $guard = $queue->acquire_foreground_owner_guard();
    $stat = @fstat($guard['handle']);
    $queueSource = (new ReflectionClass(WP_FTS_Index_Queue::class))->getFileName();
    if (!is_array($stat) || !is_string($queueSource) || $queueSource === '') {
        $queue->release_foreground_owner_guard($guard);
        throw new RuntimeException('The foreground owner holder could not bind its source or lock descriptor.');
    }
    echo json_encode([
        'schema' => 'relational-fts-cross-process-holder-ready-v1',
        'status' => 'READY',
        'pid' => getmypid(),
        'queue_path' => realpath($queueSource),
        'queue_sha256' => hash_file('sha256', $queueSource),
        'proof_sha256' => hash_file('sha256', __FILE__),
        'path' => $guard['path'],
        'device' => (int) ($stat['dev'] ?? -1),
        'inode' => (int) ($stat['ino'] ?? -1),
    ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
    flush();

    // The parent deliberately sends SIGKILL. EOF remains a bounded emergency
    // cleanup path if the parent dies before it can reap this process.
    fgets(STDIN);
    $queue->release_foreground_owner_guard($guard);
}

/** @return array{process:resource,pipes:array<int,resource>,ready:array<string,mixed>,runtime_pid:int} */
function wp_fts_mutation_proof_start_foreground_holder(string $prefix): array
{
    if (!function_exists('proc_open') || !function_exists('proc_terminate')) {
        throw new RuntimeException('The cross-process mutation proof requires proc_open() and proc_terminate().');
    }
    $pipes = [];
    $process = proc_open(
        [PHP_BINARY, __FILE__, '--foreground-owner-holder', $prefix],
        [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ],
        $pipes,
        null,
        null,
        ['bypass_shell' => true]
    );
    if (!is_resource($process) || count($pipes) !== 3) {
        throw new RuntimeException('Could not start the independent foreground owner holder.');
    }
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);
    $status = proc_get_status($process);
    $runtimePid = (int) ($status['pid'] ?? 0);
    $stdout = '';
    $stderr = '';
    $deadline = hrtime(true) + 10_000_000_000;
    $ready = null;
    try {
        do {
            $stdout .= (string) fread($pipes[1], 8192);
            $stderr .= (string) fread($pipes[2], 8192);
            if (strlen($stdout) > 16384 || strlen($stderr) > 16384) {
                throw new RuntimeException('The foreground owner holder exceeded its bounded startup output.');
            }
            $lineEnd = strpos($stdout, "\n");
            if ($lineEnd !== false) {
                $decoded = json_decode(substr($stdout, 0, $lineEnd), true, flags: JSON_THROW_ON_ERROR);
                $ready = is_array($decoded) ? $decoded : null;
                break;
            }
            $status = proc_get_status($process);
            if (!($status['running'] ?? false)) {
                throw new RuntimeException('The foreground owner holder exited before readiness: ' . trim($stderr));
            }
            usleep(1000);
        } while (hrtime(true) < $deadline);
        if (
            !is_array($ready)
            || ($ready['schema'] ?? null) !== 'relational-fts-cross-process-holder-ready-v1'
            || ($ready['status'] ?? null) !== 'READY'
            || (int) ($ready['pid'] ?? 0) <= 0
            || (int) ($ready['pid'] ?? 0) !== $runtimePid
        ) {
            throw new RuntimeException('The foreground owner holder did not publish valid source-bound readiness.');
        }
    } catch (Throwable $error) {
        @proc_terminate($process, 9);
        foreach ($pipes as $pipe) {
            if (is_resource($pipe)) {
                @fclose($pipe);
            }
        }
        @proc_close($process);
        throw $error;
    }

    return ['process' => $process, 'pipes' => $pipes, 'ready' => $ready, 'runtime_pid' => $runtimePid];
}

/** @param resource $process @param array<int,resource> $pipes @return array<string,mixed> */
function wp_fts_mutation_proof_kill_foreground_holder(mixed $process, array $pipes): array
{
    wp_fts_mutation_proof_assert(is_resource($process), 'The foreground holder process resource is missing.');
    $signalRequested = @proc_terminate($process, 9);
    $deadline = hrtime(true) + 5_000_000_000;
    $status = proc_get_status($process);
    while (($status['running'] ?? false) && hrtime(true) < $deadline) {
        usleep(1000);
        $status = proc_get_status($process);
    }
    wp_fts_mutation_proof_assert(
        $signalRequested
            && !($status['running'] ?? true)
            && ($status['signaled'] ?? false) === true
            && (int) ($status['termsig'] ?? 0) === 9,
        'The independent foreground holder must be observed dead from SIGKILL before recovery.'
    );
    foreach ($pipes as $pipe) {
        if (is_resource($pipe)) {
            fclose($pipe);
        }
    }
    $closeExit = proc_close($process);

    return [
        'signal_requested' => 9,
        'signaled' => true,
        'term_signal' => 9,
        'process_exit_code' => (int) ($status['exitcode'] ?? -1),
        'proc_close_exit_code' => $closeExit,
        'reaped' => is_int($closeExit),
    ];
}

/** @param int[] $postIds @return array<int,array<string,mixed>> */
function wp_fts_mutation_proof_post_states(mysqli $database, string $table, array $postIds): array
{
    $ids = array_values(array_unique(array_map('intval', $postIds)));
    wp_fts_mutation_proof_assert($ids !== [], 'The mutation state proof requires at least one post id.');
    $result = $database->query(
        "SELECT post_id,generation,state,claimed_generation,claim_token FROM `{$table}`"
        . ' WHERE post_id IN (' . implode(',', $ids) . ') ORDER BY post_id ASC'
    );
    wp_fts_mutation_proof_assert($result instanceof mysqli_result, 'Could not inspect protected mutation states.');
    $rows = [];
    while ($row = $result->fetch_assoc()) {
        $token = (string) ($row['claim_token'] ?? '');
        $rows[] = [
            'post_id' => (int) ($row['post_id'] ?? 0),
            'generation' => (int) ($row['generation'] ?? 0),
            'state' => (string) ($row['state'] ?? ''),
            'claimed_generation' => (int) ($row['claimed_generation'] ?? 0),
            'token_class' => $token === '' ? 'empty' : (str_starts_with($token, 'guard:') ? 'guard' : 'unmarked'),
        ];
    }
    $result->free();

    return $rows;
}

/**
 * Preserve every measured statement and EXPLAIN row, while separately proving
 * the fixed candidate relation and the required production indexes.
 *
 * @param array<int,array{method:string,sql:string,elapsed_ms:float,affected_rows:int}> $statements
 * @param string[] $requiredPlanKeys
 * @return array<string,mixed>
 */
function wp_fts_mutation_proof_operation_evidence(
    WP_FTS_Mutation_Proof_WPDB $database,
    array $statements,
    string $table,
    int $candidateRowUpperBound,
    array $requiredPlanKeys,
    string $context
): array {
    $statementEvidence = [];
    $allPlanKeys = [];
    $baseFullScans = 0;
    foreach ($statements as $statement) {
        $sql = (string) ($statement['sql'] ?? '');
        wp_fts_mutation_proof_assert($sql !== '', "{$context} emitted an empty statement.");
        $planResult = $database->dbh->query('EXPLAIN ' . $sql);
        wp_fts_mutation_proof_assert(
            $planResult instanceof mysqli_result,
            "{$context} statement could not be explained: {$database->dbh->error}"
        );
        $plan = [];
        $statementPlanKeys = [];
        $statementBaseFullScans = 0;
        $maxEstimatedRows = 0;
        while ($row = $planResult->fetch_assoc()) {
            $planTable = (string) ($row['table'] ?? '');
            $accessType = strtoupper((string) ($row['type'] ?? ''));
            $key = isset($row['key']) && $row['key'] !== null ? (string) $row['key'] : null;
            $estimatedRows = max(0, (int) ($row['rows'] ?? 0));
            if (is_string($key) && $key !== '') {
                $statementPlanKeys[] = $key;
                $allPlanKeys[] = $key;
            }
            if ($accessType === 'ALL' && ($planTable === $table || $planTable === 'claim_target')) {
                $statementBaseFullScans++;
                $baseFullScans++;
            }
            $maxEstimatedRows = max($maxEstimatedRows, $estimatedRows);
            $plan[] = [
                'select_type' => (string) ($row['select_type'] ?? ''),
                'table' => $planTable,
                'access_type' => $accessType,
                'possible_keys' => isset($row['possible_keys']) && $row['possible_keys'] !== null ? (string) $row['possible_keys'] : null,
                'key' => $key,
                'key_len' => isset($row['key_len']) && $row['key_len'] !== null ? (string) $row['key_len'] : null,
                'ref' => isset($row['ref']) && $row['ref'] !== null ? (string) $row['ref'] : null,
                'rows' => $estimatedRows,
                'filtered' => isset($row['filtered']) && is_numeric($row['filtered']) ? (float) $row['filtered'] : null,
                'extra' => (string) ($row['Extra'] ?? ''),
            ];
        }
        $planResult->free();
        wp_fts_mutation_proof_assert($plan !== [], "{$context} statement produced no EXPLAIN rows.");
        $redacted = wp_fts_mutation_proof_redact_sql($sql);
        $statementEvidence[] = [
            'method' => (string) ($statement['method'] ?? ''),
            'elapsed_ms' => round((float) ($statement['elapsed_ms'] ?? 0.0), 3),
            'affected_rows' => (int) ($statement['affected_rows'] ?? -1),
            'sql_bytes' => strlen($sql),
            'sql_sha256' => hash('sha256', $sql),
            'redacted_sql' => substr($redacted, 0, 2048),
            'redacted_sql_bytes' => min(strlen($redacted), 2048),
            'redacted_sql_truncated' => strlen($redacted) > 2048,
            'plan_row_count' => count($plan),
            'plan_keys' => array_values(array_unique($statementPlanKeys)),
            'plan_max_estimated_rows' => $maxEstimatedRows,
            'base_full_scan_count' => $statementBaseFullScans,
            'plan' => $plan,
        ];
    }
    $allPlanKeys = array_values(array_unique($allPlanKeys));
    foreach ($requiredPlanKeys as $requiredPlanKey) {
        wp_fts_mutation_proof_assert(
            in_array($requiredPlanKey, $allPlanKeys, true),
            "{$context} did not use required index {$requiredPlanKey}."
        );
    }
    wp_fts_mutation_proof_assert($baseFullScans === 0, "{$context} scanned the base work table.");
    $shape = array_map(
        static fn(array $statement): array => [
            'method' => $statement['method'],
            'sql_sha256' => $statement['sql_sha256'],
            'sql_bytes' => $statement['sql_bytes'],
            'plan_row_count' => $statement['plan_row_count'],
        ],
        $statementEvidence
    );

    return [
        'statement_count' => count($statementEvidence),
        'candidate_row_upper_bound' => $candidateRowUpperBound,
        'base_table' => $table,
        'required_plan_keys' => array_values($requiredPlanKeys),
        'observed_plan_keys' => $allPlanKeys,
        'base_full_scan_count' => $baseFullScans,
        'statement_shape_sha256' => hash('sha256', json_encode($shape, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)),
        'statements' => $statementEvidence,
    ];
}

/**
 * Prove that one max-shape corpus handoff publishes canonical work and retires
 * only its exact request sentinel.
 *
 * @param array<int,array{method:string,sql:string,elapsed_ms:float,affected_rows:int}> $statements
 * @return array<string,mixed>
 */
function wp_fts_mutation_proof_corpus_publication(
    WP_FTS_Mutation_Proof_WPDB $database,
    array $statements,
    string $table,
    string $sentinelScopeKey,
    int $expectedCanonicalGeneration,
    string $expectedIncarnation,
    string $expectedReason
): array {
    $sentinelDeletes = array_values(array_filter(
        $statements,
        static fn(array $statement): bool => str_contains(
            (string) ($statement['sql'] ?? ''),
            'wp_fts:foreground-global-delete'
        )
    ));
    wp_fts_mutation_proof_assert(
        count($sentinelDeletes) === 1
            && (int) ($sentinelDeletes[0]['affected_rows'] ?? -1) === 1,
        'Corpus handoff must execute one exact request-sentinel delete that affects one row.'
    );
    $sentinelDelete = $sentinelDeletes[0];
    $sentinelDeleteSql = (string) ($sentinelDelete['sql'] ?? '');
    wp_fts_mutation_proof_assert(
        strlen($sentinelDeleteSql) < 512,
        'The request-sentinel delete must remain below 512 bytes.'
    );
    $sentinelPlanResult = $database->dbh->query('EXPLAIN ' . $sentinelDeleteSql);
    $sentinelPlan = $sentinelPlanResult instanceof mysqli_result
        ? $sentinelPlanResult->fetch_assoc()
        : null;
    if ($sentinelPlanResult instanceof mysqli_result) {
        $sentinelPlanResult->free();
    }
    wp_fts_mutation_proof_assert(is_array($sentinelPlan), 'The engine must explain the request-sentinel delete.');
    $sentinelPlanKey = (string) ($sentinelPlan['key'] ?? '');
    $sentinelPlanRows = (int) ($sentinelPlan['rows'] ?? PHP_INT_MAX);
    $sentinelPlanExtra = strtolower((string) ($sentinelPlan['Extra'] ?? ''));
    wp_fts_mutation_proof_assert(
        strcasecmp($sentinelPlanKey, 'PRIMARY') === 0 && $sentinelPlanRows <= 1,
        'The request-sentinel delete must use one primary-key probe.'
    );
    wp_fts_mutation_proof_assert(
        !str_contains($sentinelPlanExtra, 'temporary') && !str_contains($sentinelPlanExtra, 'filesort'),
        'The request-sentinel delete must require neither a temporary table nor a filesort.'
    );

    $sentinelJobKey = 'scope:' . hash('sha256', $sentinelScopeKey);
    $sentinelResidueRows = (int) $database->get_var(
        "SELECT COUNT(*) FROM `{$table}` WHERE job_key='{$sentinelJobKey}'"
    );
    wp_fts_mutation_proof_assert(
        $sentinelResidueRows === 0,
        'Corpus handoff must leave no request-global sentinel row.'
    );

    $canonicalJobKey = 'scope:' . hash('sha256', WP_FTS_Index_Queue::GLOBAL_CORPUS_SCOPE_KEY);
    $canonicalUpserts = array_values(array_filter(
        $statements,
        static fn(array $statement): bool => str_starts_with(
            ltrim((string) ($statement['sql'] ?? '')),
            'INSERT INTO '
        ) && str_contains((string) ($statement['sql'] ?? ''), $canonicalJobKey)
    ));
    wp_fts_mutation_proof_assert(
        count($canonicalUpserts) === 1,
        'Corpus handoff must execute one canonical scope-and-epoch UPSERT.'
    );
    $canonicalResult = $database->dbh->query(
        "SELECT generation,state,claim_token,scope_coverage,scope_incarnation,payload
         FROM `{$table}` WHERE job_key='{$canonicalJobKey}'"
    );
    $canonicalRow = $canonicalResult instanceof mysqli_result
        ? $canonicalResult->fetch_assoc()
        : null;
    if ($canonicalResult instanceof mysqli_result) {
        $canonicalResult->free();
    }
    $canonicalPayload = is_array($canonicalRow)
        ? json_decode((string) ($canonicalRow['payload'] ?? ''), true, flags: JSON_THROW_ON_ERROR)
        : [];
    $canonicalRows = (int) $database->get_var(
        "SELECT COUNT(*) FROM `{$table}` WHERE job_key='{$canonicalJobKey}'"
    );
    wp_fts_mutation_proof_assert(
        $canonicalRows === 1
            && is_array($canonicalRow)
            && (int) ($canonicalRow['generation'] ?? 0) === $expectedCanonicalGeneration
            && ($canonicalRow['state'] ?? null) === 'ready'
            && ($canonicalRow['claim_token'] ?? null) === ''
            && ($canonicalRow['scope_coverage'] ?? null) === WP_FTS_Index_Queue::SCOPE_COVERAGE_CORPUS
            && ($canonicalRow['scope_incarnation'] ?? null) === $expectedIncarnation
            && ($canonicalPayload['reason'] ?? null) === $expectedReason,
        'Corpus handoff must leave exactly one ready canonical generation with the expected authority and payload.'
    );

    $canonicalUpsertSql = (string) ($canonicalUpserts[0]['sql'] ?? '');
    wp_fts_mutation_proof_assert(
        strlen($canonicalUpsertSql) < 4096,
        'The canonical corpus UPSERT must remain below 4 KiB.'
    );
    return [
        'statement_count' => count($statements),
        'canonical_upsert_statement_count' => count($canonicalUpserts),
        'canonical_upsert_sql_bytes' => strlen($canonicalUpsertSql),
        'canonical_upsert_sql_sha256' => hash('sha256', $canonicalUpsertSql),
        'sentinel_delete_statement_count' => count($sentinelDeletes),
        'sentinel_delete_affected_rows' => (int) $sentinelDelete['affected_rows'],
        'sentinel_delete_residue_rows' => $sentinelResidueRows,
        'sentinel_delete_sql_bytes' => strlen($sentinelDeleteSql),
        'sentinel_delete_sql_sha256' => hash('sha256', $sentinelDeleteSql),
        'sentinel_delete_elapsed_ms' => round((float) $sentinelDelete['elapsed_ms'], 3),
        'sentinel_delete_plan_key' => $sentinelPlanKey,
        'sentinel_delete_plan_rows' => $sentinelPlanRows,
        'sentinel_delete_uses_temporary' => str_contains($sentinelPlanExtra, 'temporary'),
        'sentinel_delete_uses_filesort' => str_contains($sentinelPlanExtra, 'filesort'),
        'canonical_rows' => $canonicalRows,
        'canonical_generation' => (int) $canonicalRow['generation'],
        'canonical_state' => (string) $canonicalRow['state'],
        'canonical_claim_token_empty' => (string) $canonicalRow['claim_token'] === '',
        'canonical_scope_coverage' => (string) $canonicalRow['scope_coverage'],
        'canonical_scope_incarnation' => (string) $canonicalRow['scope_incarnation'],
        'canonical_payload_reason' => (string) ($canonicalPayload['reason'] ?? ''),
    ];
}

final class WP_FTS_Mutation_Proof_WPDB
{
    public string $last_error = '';
    /** @var array<int,array{method:string,sql:string,elapsed_ms:float,affected_rows:int}> */
    private array $executedStatements = [];

    /** Expose the minimal wpdb contract while retaining every measured statement. */
    public function __construct(public mysqli $dbh, public string $prefix)
    {
    }

    /** Expand the controlled `%d`/`%s` fixture SQL before it reaches MySQL. */
    public function prepare(string $sql, mixed ...$args): string
    {
        $offset = 0;
        return (string) preg_replace_callback('/%[sd]/', function (array $match) use (&$offset, $args): string {
            $value = $args[$offset++] ?? null;
            if ($match[0] === '%d') {
                return (string) (int) $value;
            }
            return "'" . $this->dbh->real_escape_string((string) $value) . "'";
        }, $sql);
    }

    /** Execute mutations and retain timing plus affected-row evidence. */
    public function query(string $sql): int|bool
    {
        $startedAt = hrtime(true);
        $result = $this->dbh->query($sql);
        $elapsedMs = (hrtime(true) - $startedAt) / 1000000;
        $this->last_error = $result === false ? $this->dbh->error : '';
        if ($result === false) {
            $this->record_statement('query', $sql, $elapsedMs, -1);
            return false;
        }
        if ($result instanceof mysqli_result) {
            $result->free();
            $this->record_statement('query', $sql, $elapsedMs, 0);
            return 0;
        }
        $affectedRows = $this->dbh->affected_rows;
        $this->record_statement('query', $sql, $elapsedMs, $affectedRows);
        return $affectedRows;
    }

    /** Preserve scalar-read SQL in the same ordered evidence stream as writes. */
    public function get_var(string $sql): mixed
    {
        $startedAt = hrtime(true);
        $result = $this->dbh->query($sql);
        $elapsedMs = (hrtime(true) - $startedAt) / 1000000;
        $this->last_error = $result === false ? $this->dbh->error : '';
        if (!$result instanceof mysqli_result) {
            $this->record_statement('get_var', $sql, $elapsedMs, -1);
            return null;
        }
        $row = $result->fetch_row();
        $result->free();
        $this->record_statement('get_var', $sql, $elapsedMs, 0);
        return $row[0] ?? null;
    }

    /** @return object[] */
    public function get_results(string $sql): array
    {
        $startedAt = hrtime(true);
        $result = $this->dbh->query($sql);
        $elapsedMs = (hrtime(true) - $startedAt) / 1000000;
        $this->last_error = $result === false ? $this->dbh->error : '';
        if (!$result instanceof mysqli_result) {
            $this->record_statement('get_results', $sql, $elapsedMs, -1);
            return [];
        }
        $rows = [];
        while ($row = $result->fetch_object()) {
            $rows[] = $row;
        }
        $result->free();
        $this->record_statement('get_results', $sql, $elapsedMs, 0);
        return $rows;
    }

    /** Mark the stream so one concurrency boundary can be measured in isolation. */
    public function statement_marker(): int
    {
        return count($this->executedStatements);
    }

    /** @return array<int,array{method:string,sql:string,elapsed_ms:float,affected_rows:int}> */
    public function statements_since(int $marker): array
    {
        if ($marker < 0 || $marker > count($this->executedStatements)) {
            throw new InvalidArgumentException('Mutation proof statement marker is outside the executed statement log.');
        }

        return array_slice($this->executedStatements, $marker);
    }

    /** Retain exact SQL until the later role, size, and count assertions run. */
    private function record_statement(string $method, string $sql, float $elapsedMs, int $affectedRows): void
    {
        $this->executedStatements[] = [
            'method' => $method,
            'sql' => $sql,
            'elapsed_ms' => $elapsedMs,
            'affected_rows' => $affectedRows,
        ];
    }
}

/**
 * @param array<int,array<string,mixed>> $evidence
 */
function wp_fts_mutation_proof_capture(
    array &$evidence,
    WP_FTS_Mutation_Proof_WPDB $db,
    string $boundaryId,
    string $operationName,
    string $outcome,
    callable $operation
): mixed {
    $marker = $db->statement_marker();
    $result = $operation();
    $statements = $db->statements_since($marker);
    $statementEvidence = [];
    foreach ($statements as $statement) {
        $sql = $statement['sql'];
        $redacted = wp_fts_mutation_proof_redact_sql($sql);
        $statementEvidence[] = [
            'method' => $statement['method'],
            'elapsed_ms' => round((float) $statement['elapsed_ms'], 3),
            'affected_rows' => (int) $statement['affected_rows'],
            'sql_bytes' => strlen($sql),
            'sql_sha256' => hash('sha256', $sql),
            'redacted_sql' => substr($redacted, 0, 2048),
            'redacted_sql_bytes' => min(2048, strlen($redacted)),
            'redacted_sql_truncated' => strlen($redacted) > 2048,
        ];
    }
    $evidence[] = [
        'boundary_id' => $boundaryId,
        'operation' => $operationName,
        'outcome' => $outcome,
        'statement_count' => count($statementEvidence),
        'statements' => $statementEvidence,
    ];

    return $result;
}

/**
 * Redact quoted and numeric SQL literals without retaining payloads or tokens.
 */
function wp_fts_mutation_proof_redact_sql(string $sql): string
{
    $redacted = '';
    $length = strlen($sql);
    for ($offset = 0; $offset < $length;) {
        $character = $sql[$offset];
        if ($character === '`') {
            $start = $offset;
            $offset++;
            while ($offset < $length) {
                if ($sql[$offset] === '`') {
                    $offset++;
                    if ($offset < $length && $sql[$offset] === '`') {
                        $offset++;
                        continue;
                    }
                    break;
                }
                $offset++;
            }
            $redacted .= substr($sql, $start, $offset - $start);
            continue;
        }
        if ($character === "'" || $character === '"') {
            $quote = $character;
            $offset++;
            while ($offset < $length) {
                if ($sql[$offset] === '\\') {
                    $offset += min(2, $length - $offset);
                    continue;
                }
                if ($sql[$offset] === $quote) {
                    $offset++;
                    if ($offset < $length && $sql[$offset] === $quote) {
                        $offset++;
                        continue;
                    }
                    break;
                }
                $offset++;
            }
            $redacted .= '?';
            continue;
        }
        if (wp_fts_mutation_proof_ascii_digit($character)) {
            $previous = $offset > 0 ? $sql[$offset - 1] : '';
            if ($previous === '' || !wp_fts_mutation_proof_identifier_character($previous)) {
                $offset++;
                while ($offset < $length) {
                    $numericCharacter = $sql[$offset];
                    if (!wp_fts_mutation_proof_ascii_digit($numericCharacter) && $numericCharacter !== '.') {
                        break;
                    }
                    $offset++;
                }
                $redacted .= '?';
                continue;
            }
        }
        $redacted .= $character;
        $offset++;
    }

    return $redacted;
}

/** Use an ASCII-only digit predicate so SQL redaction is byte-deterministic. */
function wp_fts_mutation_proof_ascii_digit(string $character): bool
{
    return $character >= '0' && $character <= '9';
}

/** Distinguish numeric literals from digits embedded in SQL identifiers. */
function wp_fts_mutation_proof_identifier_character(string $character): bool
{
    return ($character >= 'a' && $character <= 'z')
        || ($character >= 'A' && $character <= 'Z')
        || wp_fts_mutation_proof_ascii_digit($character)
        || $character === '_';
}

/**
 * @param array<int,array<string,mixed>> $evidence
 * @return array<string,int>
 */
function wp_fts_mutation_proof_statement_counts(array $evidence, string $operation): array
{
    $counts = [];
    foreach ($evidence as $boundary) {
        if (($boundary['operation'] ?? null) === $operation) {
            $counts[(string) ($boundary['boundary_id'] ?? '')] = (int) ($boundary['statement_count'] ?? -1);
        }
    }

    return $counts;
}

/** @param array<string,int> $counts */
function wp_fts_mutation_proof_uniform_statement_count(array $counts, string $context): int
{
    wp_fts_mutation_proof_assert($counts !== [], "{$context} must have measured statement evidence.");
    $unique = array_values(array_unique($counts));
    wp_fts_mutation_proof_assert(count($unique) === 1, "{$context} must have a uniform measured statement count.");

    return $unique[0];
}

/** @return array<string,mixed> */
function wp_fts_mutation_proof_row(
    WP_FTS_Mutation_Proof_WPDB $db,
    string $table,
    int $postId,
    string $state,
    int $generation,
    string $token,
    string $context
): array {
    $result = $db->dbh->query("SELECT * FROM `{$table}` WHERE job_key = 'post:{$postId}'");
    $row = $result instanceof mysqli_result ? $result->fetch_assoc() : null;
    if ($result instanceof mysqli_result) {
        $result->free();
    }
    wp_fts_mutation_proof_assert(is_array($row), "{$context}: row is missing.");
    wp_fts_mutation_proof_assert(($row['state'] ?? null) === $state, "{$context}: state mismatch.");
    wp_fts_mutation_proof_assert((int) ($row['generation'] ?? 0) === $generation, "{$context}: generation mismatch.");
    wp_fts_mutation_proof_assert(($row['claim_token'] ?? null) === $token, "{$context}: token mismatch.");
    return $row;
}

/** Fail a concurrency invariant without bypassing the fixture's outer cleanup. */
function wp_fts_mutation_proof_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

/**
 * Exercise two racing foreground generations through the installed production
 * worker. The canonical primary key is the only serialization primitive: the
 * newer token supersedes the older one, and the worker sees one ready row.
 *
 * @return array<string,mixed>
 */
function wp_fts_mutation_proof_production_worker_cas(): array
{
    global $wpdb;
    wp_fts_mutation_proof_ack_transaction_sequence_self_check();
    wp_fts_mutation_proof_assert(isset($wpdb) && is_object($wpdb), 'WordPress did not expose its production wpdb connection.');
    wp_fts_mutation_proof_assert(class_exists('WP_FTS_Plugin'), 'The installed FTS plugin is not active in the production-worker proof.');
    wp_fts_mutation_proof_assert(method_exists('WP_FTS_Index_Queue', 'is_post_job_key'), 'The installed queue lacks canonical post identity validation.');

    WP_FTS_Plugin::create_or_repair_schema();
    $schema = wp_fts_mutation_proof_storage_fixture()->verify_schema();
    wp_fts_mutation_proof_assert(($schema['valid'] ?? null) === true, 'The production-worker proof requires the exact current relational schema.');

    $workTable = (string) $wpdb->prefix . 'fts_work';
    $termsTable = (string) $wpdb->prefix . 'fts_terms';
    $documentsTable = (string) $wpdb->prefix . 'fts_documents';
    $postingsTable = (string) $wpdb->prefix . 'fts_postings';
    $pendingBefore = (int) $wpdb->get_var("SELECT COUNT(*) FROM `{$workTable}` WHERE kind IN ('post','scope')");
    wp_fts_mutation_proof_assert($pendingBefore === 0, 'The production-worker CAS proof requires an otherwise empty post/scope queue.');
    $writerLockBefore = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name=%s",
        WP_FTS_Plugin::INDEX_LOCK_OPTION
    ));
    wp_fts_mutation_proof_assert($writerLockBefore === 0, 'The production-worker CAS proof requires no pre-existing writer lease.');

    $healthExists = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name=%s",
        WP_FTS_Plugin::INDEX_HEALTH_OPTION
    )) === 1;
    $healthBefore = get_option(WP_FTS_Plugin::INDEX_HEALTH_OPTION, null);
    $termHighWaterValue = $wpdb->get_var("SELECT COALESCE(MAX(term_id),0) FROM `{$termsTable}`");
    wp_fts_mutation_proof_assert(
        is_numeric($termHighWaterValue) && trim((string) $wpdb->last_error) === '',
        'Could not record the dictionary high-water mark before the production-worker fixture.'
    );
    $termHighWaterBefore = (int) $termHighWaterValue;
    $postId = 0;
    $result = null;
    $storageCleanupError = null;
    $token = 'wpftsgenerationcas' . substr(hash('sha256', (string) getenv('WP_FTS_SOURCE_SHA') . '|' . (string) getenv('WP_FTS_WC_ENGINE')), 0, 16);

    try {
        $now = gmdate('Y-m-d H:i:s');
        $inserted = $wpdb->insert($wpdb->posts, [
            'post_author' => 1,
            'post_date' => $now,
            'post_date_gmt' => $now,
            'post_content' => '<p lang="en">' . $token . ' canonical generation projection</p>',
            'post_title' => 'FTS canonical generation CAS worker proof',
            'post_excerpt' => '',
            'post_status' => 'publish',
            'comment_status' => 'closed',
            'ping_status' => 'closed',
            'post_password' => '',
            'post_name' => $token,
            'post_modified' => $now,
            'post_modified_gmt' => $now,
            'post_parent' => 0,
            'guid' => 'https://example.invalid/' . $token,
            'menu_order' => 0,
            'post_type' => 'post',
            'post_mime_type' => '',
            'comment_count' => 0,
        ]);
        wp_fts_mutation_proof_assert($inserted === 1, 'Could not insert the hook-free production-worker fixture post.');
        $postId = (int) $wpdb->insert_id;
        wp_fts_mutation_proof_assert($postId > 0, 'The production-worker fixture did not receive a post id.');

        $queue = new WP_FTS_Index_Queue($wpdb);
        $olderToken = substr(hash('sha256', 'older|' . $token), 0, 32);
        $newerToken = substr(hash('sha256', 'newer|' . $token), 0, 32);
        $canonicalJobKey = 'post:' . $postId;
        $payload = ['index_options' => ['document_lang' => 'en']];
        $recoveryAt = time() + 300;

        $queue->fence_post($postId, $olderToken, $recoveryAt, ['source' => 'older']);
        $queue->fence_post($postId, $newerToken, $recoveryAt, $payload);
        $queue->promote_post($postId, $olderToken, time(), ['source' => 'stale']);
        $fenced = $wpdb->get_row($wpdb->prepare(
            "SELECT job_key,generation,state,claim_token,payload FROM `{$workTable}` WHERE kind='post' AND post_id=%d",
            $postId
        ), ARRAY_A);
        wp_fts_mutation_proof_assert(
            is_array($fenced)
                && ($fenced['job_key'] ?? null) === $canonicalJobKey
                && (int) ($fenced['generation'] ?? 0) === 2
                && ($fenced['state'] ?? null) === 'fenced'
                && ($fenced['claim_token'] ?? null) === $newerToken,
            'The older foreground completion must not release the newer canonical generation.'
        );
        $queue->promote_post($postId, $newerToken, time(), $payload);

        $readyRows = $wpdb->get_results($wpdb->prepare(
            "SELECT job_key,generation,state FROM `{$workTable}` WHERE kind='post' AND post_id=%d ORDER BY job_key",
            $postId
        ), ARRAY_A) ?: [];
        wp_fts_mutation_proof_assert(
            $readyRows === [[
                'job_key' => $canonicalJobKey,
                'generation' => '2',
                'state' => 'ready',
            ]],
            'The production worker must begin with exactly one ready canonical generation.'
        );

        $captured = wp_fts_mutation_proof_capture_wordpress_queries(static fn(): array => WP_FTS_Plugin::process_manual_index_batch([
            'batch_size' => 1,
            'time_budget' => 30.0,
            'source' => 'mutation-fence-production-worker-cas',
        ]));
        $summary = is_array($captured['result'] ?? null) ? $captured['result'] : [];
        $queries = is_array($captured['queries'] ?? null) ? $captured['queries'] : [];
        $sqlEvidence = wp_fts_mutation_proof_worker_sql_evidence($queries);
        wp_fts_mutation_proof_assert((int) ($summary['analyzed'] ?? -1) === 1, 'The canonical claim must produce one production analysis.');
        wp_fts_mutation_proof_assert((int) ($summary['indexed'] ?? -1) === 1, 'The canonical claim must produce one production replacement.');
        wp_fts_mutation_proof_assert((int) ($summary['committed'] ?? -1) === 1, 'The production batch must commit one canonical generation.');
        wp_fts_mutation_proof_assert((int) ($summary['queue_processed'] ?? -1) === 1, 'The production batch must drain one canonical generation.');

        $ackIndexes = [];
        foreach ($queries as $index => $sql) {
            $upper = strtoupper((string) $sql);
            if (str_contains($upper, '/* WP_FTS:ATOMIC-WORKER-ACK */')
                && wp_fts_mutation_proof_sql_is_single_dml((string) $sql, 'DELETE')
                && str_contains($upper, strtoupper($workTable))
            ) {
                $ackIndexes[] = (int) $index;
            }
        }
        wp_fts_mutation_proof_assert(count($ackIndexes) === 1, 'The MySQL/MariaDB worker must issue exactly one atomic acknowledgement DELETE.');
        $ackIndex = $ackIndexes[0];
        $ackSql = (string) ($queries[$ackIndex] ?? '');
        $ackUpper = strtoupper($ackSql);
        $writerLeaseDeleteIndexes = [];
        $writerLeasePayloads = [];
        foreach ($queries as $index => $sql) {
            $leasePayload = wp_fts_mutation_proof_writer_lease_insert_payload(
                (string) $sql,
                (string) $wpdb->options,
                WP_FTS_Plugin::INDEX_LOCK_OPTION
            );
            if ($leasePayload !== null) {
                $writerLeasePayloads[] = $leasePayload;
            }
            if (wp_fts_mutation_proof_writer_lease_delete_candidate(
                (string) $sql,
                (string) $wpdb->options,
                WP_FTS_Plugin::INDEX_LOCK_OPTION
            )) {
                $writerLeaseDeleteIndexes[] = (int) $index;
            }
        }
        $ackTransaction = wp_fts_mutation_proof_ack_transaction_sequence($queries, $ackIndex);
        $transactionStartIndex = $ackTransaction['start_index'];
        $ackSequence = [
            (string) ($queries[$transactionStartIndex] ?? ''),
            $ackSql,
            (string) ($queries[$ackIndex + 1] ?? ''),
            (string) ($queries[$ackIndex + 2] ?? ''),
        ];
        $ackSequenceValid = $ackTransaction['valid']
            && wp_fts_mutation_proof_epoch_upsert_valid($ackSequence[2], $workTable);
        $ackExcludesWriterLease = !str_contains($ackSql, WP_FTS_Plugin::INDEX_LOCK_OPTION)
            && !str_contains($ackUpper, 'LOCK_ROW')
            && !str_contains($ackUpper, 'LEFT JOIN');
        $ackCasValid = wp_fts_mutation_proof_ack_generation_cas_valid($ackSql, $canonicalJobKey, $workTable)
            && $ackExcludesWriterLease;
        $writerLeaseReleasedAfterCommit = count($writerLeaseDeleteIndexes) === 1
            && $writerLeaseDeleteIndexes[0] > $ackIndex + 2;
        $writerLeaseDeleteSql = count($writerLeaseDeleteIndexes) === 1
            ? (string) ($queries[$writerLeaseDeleteIndexes[0]] ?? '')
            : '';
        $writerLeaseDeleteCasValid = $writerLeaseReleasedAfterCommit
            && count($writerLeasePayloads) === 1
            && wp_fts_mutation_proof_writer_lease_delete_valid(
                $writerLeaseDeleteSql,
                (string) $wpdb->options,
                WP_FTS_Plugin::INDEX_LOCK_OPTION,
                (string) ($writerLeasePayloads[0] ?? '')
            );
        wp_fts_mutation_proof_assert($ackSequenceValid, 'Atomic acknowledgement must remain inside one worker transaction immediately before the epoch UPSERT and COMMIT.');
        wp_fts_mutation_proof_assert($ackCasValid, 'The atomic acknowledgement DELETE must CAS only the canonical work generation.');
        wp_fts_mutation_proof_assert($writerLeaseReleasedAfterCommit, 'The exact writer lease must remain owned through the work acknowledgement COMMIT.');
        wp_fts_mutation_proof_assert($writerLeaseDeleteCasValid, 'The post-COMMIT writer lease DELETE must compare both its exact option name and serialized payload.');

        $remainingKeys = array_map('strval', $wpdb->get_col($wpdb->prepare(
            "SELECT job_key FROM `{$workTable}` WHERE kind='post' AND post_id=%d ORDER BY job_key",
            $postId
        )) ?: []);
        wp_fts_mutation_proof_assert($remainingKeys === [], 'The atomic production acknowledgement must remove the canonical generation.');
        $writerLockAfter = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name=%s",
            WP_FTS_Plugin::INDEX_LOCK_OPTION
        ));
        wp_fts_mutation_proof_assert($writerLockAfter === 0, 'The production batch must remove the exact writer lease after acknowledgement commits.');

        $searchRows = WP_FTS_Plugin::search($token, [
            'lang' => 'en',
            'mode' => 'OR',
            'limit' => 10,
            'post_types' => ['post'],
            'post_statuses' => ['publish'],
            'prefix_matching' => false,
            'include_snippets' => false,
        ]);
        $searchIds = array_map('intval', array_column($searchRows, 'doc_id'));
        wp_fts_mutation_proof_assert($searchIds === [$postId], 'The canonical replacement must be visible through production relational search.');

        $result = [
            'post_id' => $postId,
            'ready_job_keys' => [$canonicalJobKey],
            'ready_generation' => 2,
            'stale_promotion_rejected' => true,
            'analyzed' => (int) ($summary['analyzed'] ?? -1),
            'indexed' => (int) ($summary['indexed'] ?? -1),
            'committed' => (int) ($summary['committed'] ?? -1),
            'queue_processed' => (int) ($summary['queue_processed'] ?? -1),
            'remaining_job_keys' => $remainingKeys,
            'search_ids' => $searchIds,
            'captured_worker_statement_count' => count($queries),
            'sql_evidence' => $sqlEvidence,
            'atomic_ack_statement_count' => count($ackIndexes),
            'atomic_ack_sql_bytes' => strlen($ackSql),
            'atomic_ack_sql_sha256' => hash('sha256', $ackSql),
            'atomic_ack_sequence' => ['START TRANSACTION', 'bounded writes then generation CAS DELETE', 'epoch UPSERT', 'COMMIT'],
            'atomic_ack_sequence_valid' => $ackSequenceValid,
            'atomic_ack_generation_cas_valid' => $ackCasValid,
            'atomic_ack_excludes_writer_lease' => $ackExcludesWriterLease,
            'writer_lease_released_after_commit' => $writerLeaseReleasedAfterCommit,
            'writer_lease_delete_cas_valid' => $writerLeaseDeleteCasValid,
            'writer_lock_rows_after_batch' => $writerLockAfter,
        ];
    } finally {
        if ($postId > 0) {
            try {
                // Fixture teardown is intentionally outside the production
                // factory. This explicitly unguarded instance is scoped to the
                // disposable proof and followed by physical row checks.
                $storage = new WP_FTS_Relational_Storage($wpdb);
                $storage->replace_prepared_documents([], [$postId]);
            } catch (Throwable $error) {
                // Finish direct cleanup before surfacing a failed relational cleanup.
                $storageCleanupError = $error;
            }
            $wpdb->query($wpdb->prepare("DELETE FROM `{$postingsTable}` WHERE post_id=%d", $postId));
            $wpdb->query($wpdb->prepare("DELETE FROM `{$documentsTable}` WHERE post_id=%d", $postId));
            $wpdb->query($wpdb->prepare("DELETE FROM `{$workTable}` WHERE kind='post' AND post_id=%d", $postId));
            $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->posts} WHERE ID=%d", $postId));
            $wpdb->query($wpdb->prepare(
                "DELETE dictionary FROM `{$termsTable}` dictionary
                 WHERE dictionary.term_id>%d AND dictionary.doc_freq=0
                   AND NOT EXISTS (
                       SELECT 1 FROM `{$postingsTable}` retained
                       WHERE retained.term_id=dictionary.term_id
                   )",
                $termHighWaterBefore
            ));
        }
        delete_option(WP_FTS_Plugin::INDEX_LOCK_OPTION);
        if ($healthExists) {
            update_option(WP_FTS_Plugin::INDEX_HEALTH_OPTION, $healthBefore, false);
        } else {
            delete_option(WP_FTS_Plugin::INDEX_HEALTH_OPTION);
        }
        WP_FTS_Plugin::reset_request_caches();
    }

    wp_fts_mutation_proof_assert(is_array($result), 'The production-worker CAS proof did not produce evidence.');
    wp_fts_mutation_proof_assert(
        $storageCleanupError === null,
        'The production-worker CAS proof could not remove its derived relational document: '
            . ($storageCleanupError instanceof Throwable ? $storageCleanupError->getMessage() : '')
    );
    $cleanup = [
        'posts' => (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->posts} WHERE ID=%d", $postId)),
        'documents' => (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM `{$documentsTable}` WHERE post_id=%d", $postId)),
        'postings' => (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM `{$postingsTable}` WHERE post_id=%d", $postId)),
        'terms' => (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM `{$termsTable}` WHERE term_id>%d", $termHighWaterBefore)),
        'work' => (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM `{$workTable}` WHERE post_id=%d", $postId)),
        'writer_locks' => (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name=%s",
            WP_FTS_Plugin::INDEX_LOCK_OPTION
        )),
    ];
    wp_fts_mutation_proof_assert($cleanup === ['posts' => 0, 'documents' => 0, 'postings' => 0, 'terms' => 0, 'work' => 0, 'writer_locks' => 0], 'The production-worker CAS fixture must be fully removed.');
    wp_fts_mutation_proof_assert(
        get_option(WP_FTS_Plugin::INDEX_HEALTH_OPTION, null) === $healthBefore,
        'The production-worker CAS proof must restore prior operator health state.'
    );
    $result['fixture_cleanup'] = $cleanup;
    $result['health_restored'] = true;

    return $result;
}

/** Retain the complete bounded worker query stream for independent validation. */
function wp_fts_mutation_proof_worker_sql_evidence(array $queries): array
{
    wp_fts_mutation_proof_assert(
        $queries !== [] && count($queries) <= 32,
        'The production worker SQL evidence must contain between one and 32 statements.'
    );

    $statements = [];
    $totalBytes = 0;
    $maxStatementBytes = 0;
    foreach (array_values($queries) as $index => $sql) {
        wp_fts_mutation_proof_assert(is_string($sql), 'Every captured production worker statement must be a string.');
        $bytes = strlen($sql);
        wp_fts_mutation_proof_assert(
            $bytes > 0 && $bytes <= 1048576,
            'Every production worker SQL evidence statement must be nonempty and at most 1 MiB.'
        );
        $totalBytes += $bytes;
        wp_fts_mutation_proof_assert(
            $totalBytes <= 4194304,
            'The complete production worker SQL evidence must be at most 4 MiB.'
        );
        $maxStatementBytes = max($maxStatementBytes, $bytes);
        $statements[] = [
            'index' => $index,
            'sql' => $sql,
            'bytes' => $bytes,
            'sha256' => hash('sha256', $sql),
        ];
    }

    $evidence = [
        'schema' => 'relational-fts-mutation-worker-sql-v1',
        'statement_count' => count($statements),
        'total_bytes' => $totalBytes,
        'max_statement_bytes' => $maxStatementBytes,
        'statements' => $statements,
    ];
    $evidence['evidence_sha256'] = wp_fts_mutation_proof_canonical_hash($evidence);

    return $evidence;
}

/** Prove the lexical transaction and compare-delete checks fail closed. */
function wp_fts_mutation_proof_ack_transaction_sequence_self_check(): void
{
    $valid = [
        'SELECT writer lease',
        'START TRANSACTION',
        'UPDATE bounded rows',
        'DELETE /* wp_fts:atomic-worker-ack */ FROM work',
        "INSERT INTO wp_fts_work (job_key,generation) VALUES ('meta:search-epoch',1) ON DUPLICATE KEY UPDATE generation=generation+1",
        'COMMIT',
        'DELETE writer lease',
    ];
    wp_fts_mutation_proof_assert(
        wp_fts_mutation_proof_ack_transaction_sequence($valid, 3)['valid'],
        'The atomic acknowledgement transaction-control self-check rejected the canonical sequence.'
    );

    $invalid = [
        ['intermediate COMMIT', ['START TRANSACTION', 'UPDATE bounded rows', 'COMMIT', 'DELETE /* wp_fts:atomic-worker-ack */ FROM work', 'INSERT epoch', 'COMMIT'], 3],
        ['intermediate ROLLBACK', ['START TRANSACTION', 'UPDATE bounded rows', 'ROLLBACK', 'DELETE /* wp_fts:atomic-worker-ack */ FROM work', 'INSERT epoch', 'COMMIT'], 3],
        ['nested START', ['START TRANSACTION', 'UPDATE bounded rows', 'START TRANSACTION', 'DELETE /* wp_fts:atomic-worker-ack */ FROM work', 'INSERT epoch', 'COMMIT'], 3],
        ['savepoint control', ['START TRANSACTION', 'SAVEPOINT hidden', 'DELETE /* wp_fts:atomic-worker-ack */ FROM work', 'INSERT epoch', 'COMMIT'], 2],
        ['ALTER TABLE', ['START TRANSACTION', 'UPDATE bounded rows', 'ALTER TABLE work ADD hidden INT', 'DELETE /* wp_fts:atomic-worker-ack */ FROM work', 'INSERT epoch', 'COMMIT'], 3],
        ['LOCK TABLES', ['START TRANSACTION', 'UPDATE bounded rows', 'LOCK TABLES work WRITE', 'DELETE /* wp_fts:atomic-worker-ack */ FROM work', 'INSERT epoch', 'COMMIT'], 3],
        ['SET SESSION autocommit', ['START TRANSACTION', 'UPDATE bounded rows', 'SET SESSION autocommit=1', 'DELETE /* wp_fts:atomic-worker-ack */ FROM work', 'INSERT epoch', 'COMMIT'], 3],
        ['SET @@session.autocommit', ['START TRANSACTION', 'UPDATE bounded rows', 'SET @@session.autocommit=1', 'DELETE /* wp_fts:atomic-worker-ack */ FROM work', 'INSERT epoch', 'COMMIT'], 3],
        ['leading-comment COMMIT', ['START TRANSACTION', 'UPDATE bounded rows', "/* hidden */ -- boundary\nCOMMIT", 'DELETE /* wp_fts:atomic-worker-ack */ FROM work', 'INSERT epoch', 'COMMIT'], 3],
        ['multi-statement DML', ['START TRANSACTION', 'UPDATE bounded rows; DELETE FROM work', 'DELETE /* wp_fts:atomic-worker-ack */ FROM work', 'INSERT epoch', 'COMMIT'], 2],
        ['non-adjacent COMMIT', ['START TRANSACTION', 'DELETE /* wp_fts:atomic-worker-ack */ FROM work', 'INSERT epoch', 'SELECT after epoch', 'COMMIT'], 1],
        ['missing START', ['DELETE /* wp_fts:atomic-worker-ack */ FROM work', 'INSERT epoch', 'COMMIT'], 0],
    ];
    foreach ($invalid as [$context, $queries, $ackIndex]) {
        wp_fts_mutation_proof_assert(
            !wp_fts_mutation_proof_ack_transaction_sequence($queries, $ackIndex)['valid'],
            "The atomic acknowledgement transaction-control self-check accepted {$context}."
        );
    }

    $epochUpsert = $valid[4];
    wp_fts_mutation_proof_assert(
        wp_fts_mutation_proof_epoch_upsert_valid($epochUpsert, 'wp_fts_work'),
        'The epoch UPSERT self-check rejected the exact singleton increment.'
    );
    foreach ([
        'SELECT text impersonation' => "SELECT 'meta:search-epoch ON DUPLICATE KEY UPDATE generation=generation+1'",
        'wrong work table' => str_replace('wp_fts_work', 'wp_other_work', $epochUpsert),
        'wrong meta key' => str_replace('meta:search-epoch', 'meta:other', $epochUpsert),
        'wrong generation update' => str_replace('generation=generation+1', 'generation=1', $epochUpsert),
        'second VALUES tuple' => str_replace(
            "'meta:search-epoch',1) ON",
            "'meta:search-epoch',1),('meta:other',1) ON",
            $epochUpsert
        ),
        'later generation overwrite' => $epochUpsert . ', generation=1',
    ] as $context => $invalidEpoch) {
        wp_fts_mutation_proof_assert(
            !wp_fts_mutation_proof_epoch_upsert_valid($invalidEpoch, 'wp_fts_work'),
            "The epoch UPSERT self-check accepted {$context}."
        );
    }

    $canonicalJobKey = 'post:7';
    $constantDriver = "SELECT bounded_claims.*
FROM (SELECT '{$canonicalJobKey}' AS job_key, '0123456789abcdef0123456789abcdef' AS claim_token, 2 AS claimed_generation, 2 AS generation) bounded_claims
LIMIT 1";
    $ackSql = "DELETE /* wp_fts:atomic-worker-ack */ work_row
FROM ({$constantDriver}) claim_driver
STRAIGHT_JOIN wp_fts_work work_row
        ON work_row.job_key = claim_driver.job_key
       AND work_row.claim_token = claim_driver.claim_token
       AND work_row.claimed_generation = claim_driver.claimed_generation
       AND work_row.generation = claim_driver.generation";
    wp_fts_mutation_proof_assert(
        wp_fts_mutation_proof_ack_generation_cas_valid($ackSql, $canonicalJobKey, 'wp_fts_work'),
        'The atomic acknowledgement CAS self-check rejected the exact four predicate pairs.'
    );
    foreach (['job_key', 'claim_token', 'claimed_generation', 'generation'] as $field) {
        $wrongRhs = str_replace(
            "work_row.{$field} = claim_driver.{$field}",
            "work_row.{$field} = claim_driver.wrong_{$field}",
            $ackSql
        );
        wp_fts_mutation_proof_assert(
            !wp_fts_mutation_proof_ack_generation_cas_valid($wrongRhs, $canonicalJobKey, 'wp_fts_work'),
            "The atomic acknowledgement CAS self-check accepted the wrong {$field} RHS alias."
        );
    }
    foreach ([
        'OR broadening' => $ackSql . ' OR 1=1',
        'extra predicate' => $ackSql . ' AND 1=1',
        'extra JOIN' => str_replace(
            'STRAIGHT_JOIN wp_fts_work work_row',
            'JOIN wp_fts_work extra_row ON 1=1 STRAIGHT_JOIN wp_fts_work work_row',
            $ackSql
        ),
        'wrong work table' => str_replace('wp_fts_work work_row', 'wp_other_work work_row', $ackSql),
        'live-row-derived driver' => str_replace(
            $constantDriver,
            "SELECT job_key,claim_token,claimed_generation,generation FROM wp_fts_work WHERE job_key='{$canonicalJobKey}'",
            $ackSql
        ),
    ] as $context => $broadenedAck) {
        wp_fts_mutation_proof_assert(
            !wp_fts_mutation_proof_ack_generation_cas_valid(
                $broadenedAck,
                $canonicalJobKey,
                'wp_fts_work'
            ),
            "The atomic acknowledgement CAS self-check accepted {$context}."
        );
    }

    $leasePayload = serialize([
        'token' => '0123456789abcdef01234567',
        'mode' => 'manual',
        'started_at' => 100,
        'heartbeat_at' => 100,
        'expires_at' => 400,
        'renewals' => 0,
    ]);
    $leaseInsert = "INSERT INTO wp_options (option_name,option_value,autoload)
SELECT '_wp_fts_index_lock','{$leasePayload}','no'
WHERE NOT EXISTS (SELECT 1 FROM wp_options uninstall_fence WHERE uninstall_fence.option_name='_wp_fts_uninstall_fence')
ON DUPLICATE KEY UPDATE option_name = option_name";
    $extractedPayload = wp_fts_mutation_proof_writer_lease_insert_payload(
        $leaseInsert,
        'wp_options',
        '_wp_fts_index_lock'
    );
    wp_fts_mutation_proof_assert(
        $extractedPayload === $leasePayload,
        'The writer lease self-check did not recover the exact acquired serialized payload.'
    );
    $leaseDelete = "DELETE FROM wp_options WHERE option_name = '_wp_fts_index_lock' AND option_value = '{$leasePayload}'";
    wp_fts_mutation_proof_assert(
        wp_fts_mutation_proof_writer_lease_delete_valid(
            $leaseDelete,
            'wp_options',
            '_wp_fts_index_lock',
            $leasePayload
        ),
        'The writer lease self-check rejected the exact post-COMMIT compare-delete.'
    );
    foreach ([
        'option-name column RHS' => "DELETE FROM wp_options WHERE option_name = option_name AND option_value = '{$leasePayload}'",
        'payload column RHS' => "DELETE FROM wp_options WHERE option_name = '_wp_fts_index_lock' AND option_value = option_value",
        'wrong payload literal' => "DELETE FROM wp_options WHERE option_name = '_wp_fts_index_lock' AND option_value = 'a:0:{}'",
    ] as $context => $invalidDelete) {
        wp_fts_mutation_proof_assert(
            !wp_fts_mutation_proof_writer_lease_delete_valid(
                $invalidDelete,
                'wp_options',
                '_wp_fts_index_lock',
                $leasePayload
            ),
            "The writer lease compare-delete self-check accepted {$context}."
        );
    }
}

/**
 * Prove the marked ACK closes one uninterrupted DML-only transaction.
 *
 * @param string[] $queries
 * @return array{valid:bool,start_index:int}
 */
function wp_fts_mutation_proof_ack_transaction_sequence(array $queries, int $ackIndex): array
{
    if ($ackIndex < 0 || $ackIndex >= count($queries)) {
        return ['valid' => false, 'start_index' => -1];
    }

    $controls = [];
    $tokensByIndex = [];
    $commentsByIndex = [];
    foreach ($queries as $index => $sql) {
        $comments = [];
        $tokens = wp_fts_mutation_proof_sql_tokens((string) $sql, $comments);
        if ($tokens === null) {
            return ['valid' => false, 'start_index' => -1];
        }
        $tokensByIndex[(int) $index] = $tokens;
        $commentsByIndex[(int) $index] = $comments;
        $control = wp_fts_mutation_proof_transaction_control_tokens($tokens);
        if ($control !== null) {
            $controls[] = ['index' => (int) $index, 'control' => $control];
        }
    }

    $startIndex = (int) ($controls[0]['index'] ?? -1);
    $valid = count($controls) === 2
        && ($controls[0]['control'] ?? null) === 'START TRANSACTION'
        && wp_fts_mutation_proof_sql_tokens_are_exact_keywords(
            $tokensByIndex[$startIndex] ?? [],
            ['START', 'TRANSACTION']
        )
        && $startIndex < $ackIndex
        && ($controls[1]['control'] ?? null) === 'COMMIT'
        && wp_fts_mutation_proof_sql_tokens_are_exact_keywords(
            $tokensByIndex[$ackIndex + 2] ?? [],
            ['COMMIT']
        )
        && ($controls[1]['index'] ?? null) === $ackIndex + 2
        && wp_fts_mutation_proof_sql_comment_count(
            $commentsByIndex[$ackIndex] ?? [],
            'wp_fts:atomic-worker-ack'
        ) === 1;
    if (!$valid) {
        return ['valid' => false, 'start_index' => $startIndex];
    }

    for ($index = $startIndex + 1; $index <= $ackIndex + 1; $index++) {
        if (!wp_fts_mutation_proof_sql_tokens_are_single_dml($tokensByIndex[$index] ?? [])) {
            return ['valid' => false, 'start_index' => $startIndex];
        }
    }

    return ['valid' => true, 'start_index' => $startIndex];
}

/** Prove the post-ACK statement increments the singleton epoch in the work table. */
function wp_fts_mutation_proof_epoch_upsert_valid(string $sql, string $workTable): bool
{
    $tokens = wp_fts_mutation_proof_sql_tokens($sql);

    return $tokens !== null
        && wp_fts_mutation_proof_sql_tokens_are_single_dml($tokens, 'INSERT')
        && wp_fts_mutation_proof_sql_token_is_keyword($tokens[1] ?? null, 'INTO')
        && wp_fts_mutation_proof_sql_identifier_matches($tokens[2] ?? null, $workTable)
        && wp_fts_mutation_proof_sql_token_value_count($tokens, 'JOB_KEY') === 1
        && wp_fts_mutation_proof_sql_string_value_count($tokens, 'meta:search-epoch') === 1
        && wp_fts_mutation_proof_sql_single_values_tuple_before_on_duplicate($tokens)
        && wp_fts_mutation_proof_sql_token_sequence_count(
            $tokens,
            ['ON', 'DUPLICATE', 'KEY', 'UPDATE', 'GENERATION', '=', 'GENERATION', '+', '1']
        ) === 1
        && wp_fts_mutation_proof_sql_token_value_count($tokens, 'GENERATION') === 3
        && wp_fts_mutation_proof_sql_token_value_count($tokens, 'SELECT') === 0;
}

/** @param list<array{type:string,value:string}> $tokens */
function wp_fts_mutation_proof_sql_single_values_tuple_before_on_duplicate(array $tokens): bool
{
    $valuesIndex = null;
    foreach ($tokens as $index => $token) {
        if (wp_fts_mutation_proof_sql_token_is_keyword($token, 'VALUES')) {
            if ($valuesIndex !== null) {
                return false;
            }
            $valuesIndex = (int) $index;
        }
    }
    if ($valuesIndex === null || !wp_fts_mutation_proof_sql_token_is_symbol($tokens[$valuesIndex + 1] ?? null, '(')) {
        return false;
    }

    $depth = 0;
    $afterTuple = null;
    for ($index = $valuesIndex + 1, $count = count($tokens); $index < $count; $index++) {
        if (wp_fts_mutation_proof_sql_token_is_symbol($tokens[$index], '(')) {
            $depth++;
        } elseif (wp_fts_mutation_proof_sql_token_is_symbol($tokens[$index], ')')) {
            $depth--;
            if ($depth === 0) {
                $afterTuple = $index + 1;
                break;
            }
            if ($depth < 0) {
                return false;
            }
        }
    }
    if ($afterTuple === null) {
        return false;
    }

    return wp_fts_mutation_proof_sql_token_sequence_count(
        array_slice($tokens, $afterTuple, 4),
        ['ON', 'DUPLICATE', 'KEY', 'UPDATE']
    ) === 1;
}

/** Require all four generation predicates to compare the exact driver fields. */
function wp_fts_mutation_proof_ack_generation_cas_valid(
    string $sql,
    string $canonicalJobKey,
    string $workTable
): bool
{
    $comments = [];
    $tokens = wp_fts_mutation_proof_sql_tokens($sql, $comments);
    if (
        $tokens === null
        || !wp_fts_mutation_proof_sql_tokens_are_single_dml($tokens, 'DELETE')
        || wp_fts_mutation_proof_sql_comment_count($comments, 'wp_fts:atomic-worker-ack') !== 1
        || !wp_fts_mutation_proof_ack_constant_driver_valid(
            $tokens,
            $canonicalJobKey,
            $workTable
        )
    ) {
        return false;
    }

    $onTail = ['ON'];
    foreach (['JOB_KEY', 'CLAIM_TOKEN', 'CLAIMED_GENERATION', 'GENERATION'] as $field) {
        $lhs = ['WORK_ROW', '.', $field, '='];
        $pair = [...$lhs, 'CLAIM_DRIVER', '.', $field];
        if (
            wp_fts_mutation_proof_sql_token_sequence_count($tokens, $lhs) !== 1
            || wp_fts_mutation_proof_sql_token_sequence_count($tokens, $pair) !== 1
        ) {
            return false;
        }
        if (count($onTail) > 1) {
            $onTail[] = 'AND';
        }
        array_push($onTail, ...$pair);
    }

    return wp_fts_mutation_proof_sql_token_value_count($tokens, 'ON') === 1
        && wp_fts_mutation_proof_sql_token_sequence_count($tokens, $onTail) === 1
        && wp_fts_mutation_proof_sql_token_sequence_is_tail($tokens, $onTail);
}

/**
 * Bind the one-row claim snapshot to constants, never current work-table data.
 *
 * @param list<array{type:string,value:string}> $tokens
 */
function wp_fts_mutation_proof_ack_constant_driver_valid(
    array $tokens,
    string $canonicalJobKey,
    string $workTable
): bool {
    $prefix = ['DELETE', 'WORK_ROW', 'FROM', '(', 'SELECT', 'BOUNDED_CLAIMS', '.', '*', 'FROM', '(', 'SELECT'];
    if (
        wp_fts_mutation_proof_sql_token_sequence_count(array_slice($tokens, 0, 11), $prefix) !== 1
        || ($tokens[11]['type'] ?? null) !== 'string'
        || ($tokens[11]['value'] ?? null) !== $canonicalJobKey
        || !wp_fts_mutation_proof_sql_token_is_keyword($tokens[12] ?? null, 'AS')
        || !wp_fts_mutation_proof_sql_token_is_keyword($tokens[13] ?? null, 'JOB_KEY')
        || !wp_fts_mutation_proof_sql_token_is_symbol($tokens[14] ?? null, ',')
        || ($tokens[15]['type'] ?? null) !== 'string'
        || strlen((string) ($tokens[15]['value'] ?? '')) !== 32
        || !wp_fts_mutation_proof_ascii_hex_string((string) ($tokens[15]['value'] ?? ''))
        || !wp_fts_mutation_proof_sql_token_is_keyword($tokens[16] ?? null, 'AS')
        || !wp_fts_mutation_proof_sql_token_is_keyword($tokens[17] ?? null, 'CLAIM_TOKEN')
        || !wp_fts_mutation_proof_sql_token_is_symbol($tokens[18] ?? null, ',')
        || !wp_fts_mutation_proof_sql_token_is_positive_decimal($tokens[19] ?? null)
        || !wp_fts_mutation_proof_sql_token_is_keyword($tokens[20] ?? null, 'AS')
        || !wp_fts_mutation_proof_sql_token_is_keyword($tokens[21] ?? null, 'CLAIMED_GENERATION')
        || !wp_fts_mutation_proof_sql_token_is_symbol($tokens[22] ?? null, ',')
        || !wp_fts_mutation_proof_sql_token_is_positive_decimal($tokens[23] ?? null)
        || ($tokens[23]['value'] ?? null) !== ($tokens[19]['value'] ?? null)
        || !wp_fts_mutation_proof_sql_token_is_keyword($tokens[24] ?? null, 'AS')
        || !wp_fts_mutation_proof_sql_token_is_keyword($tokens[25] ?? null, 'GENERATION')
        || !wp_fts_mutation_proof_sql_token_is_symbol($tokens[26] ?? null, ')')
        || !wp_fts_mutation_proof_sql_token_is_keyword($tokens[27] ?? null, 'BOUNDED_CLAIMS')
        || !wp_fts_mutation_proof_sql_token_is_keyword($tokens[28] ?? null, 'LIMIT')
        || !wp_fts_mutation_proof_sql_token_is_keyword($tokens[29] ?? null, '1')
        || !wp_fts_mutation_proof_sql_token_is_symbol($tokens[30] ?? null, ')')
        || !wp_fts_mutation_proof_sql_token_is_keyword($tokens[31] ?? null, 'CLAIM_DRIVER')
        || !wp_fts_mutation_proof_sql_token_is_keyword($tokens[32] ?? null, 'STRAIGHT_JOIN')
        || !wp_fts_mutation_proof_sql_identifier_matches($tokens[33] ?? null, $workTable)
        || !wp_fts_mutation_proof_sql_token_is_keyword($tokens[34] ?? null, 'WORK_ROW')
        || !wp_fts_mutation_proof_sql_token_is_keyword($tokens[35] ?? null, 'ON')
    ) {
        return false;
    }

    return wp_fts_mutation_proof_sql_string_value_count($tokens, $canonicalJobKey) === 1
        && wp_fts_mutation_proof_sql_token_value_count($tokens, 'SELECT') === 2
        && wp_fts_mutation_proof_sql_token_value_count($tokens, 'FROM') === 2;
}

/** Return the exact payload literal from the worker's uncontended lease INSERT. */
function wp_fts_mutation_proof_writer_lease_insert_payload(
    string $sql,
    string $optionsTable,
    string $optionName
): ?string {
    $tokens = wp_fts_mutation_proof_sql_tokens($sql);
    if (
        $tokens === null
        || !wp_fts_mutation_proof_sql_tokens_are_single_dml($tokens, 'INSERT')
        || !wp_fts_mutation_proof_sql_token_is_keyword($tokens[1] ?? null, 'INTO')
        || !wp_fts_mutation_proof_sql_identifier_matches($tokens[2] ?? null, $optionsTable)
        || !wp_fts_mutation_proof_sql_token_is_symbol($tokens[3] ?? null, '(')
        || !wp_fts_mutation_proof_sql_token_is_keyword($tokens[4] ?? null, 'OPTION_NAME')
        || !wp_fts_mutation_proof_sql_token_is_symbol($tokens[5] ?? null, ',')
        || !wp_fts_mutation_proof_sql_token_is_keyword($tokens[6] ?? null, 'OPTION_VALUE')
        || !wp_fts_mutation_proof_sql_token_is_symbol($tokens[7] ?? null, ',')
        || !wp_fts_mutation_proof_sql_token_is_keyword($tokens[8] ?? null, 'AUTOLOAD')
        || !wp_fts_mutation_proof_sql_token_is_symbol($tokens[9] ?? null, ')')
    ) {
        return null;
    }

    $valueIndex = null;
    if (wp_fts_mutation_proof_sql_token_is_keyword($tokens[10] ?? null, 'SELECT')) {
        $valueIndex = 11;
    } elseif (
        wp_fts_mutation_proof_sql_token_is_keyword($tokens[10] ?? null, 'VALUES')
        && wp_fts_mutation_proof_sql_token_is_symbol($tokens[11] ?? null, '(')
    ) {
        $valueIndex = 12;
    }
    $suffixIndex = count($tokens) - 7;
    if (
        $valueIndex === null
        || $suffixIndex <= $valueIndex + 4
        || ($tokens[$valueIndex]['type'] ?? null) !== 'string'
        || ($tokens[$valueIndex]['value'] ?? null) !== $optionName
        || !wp_fts_mutation_proof_sql_token_is_symbol($tokens[$valueIndex + 1] ?? null, ',')
        || ($tokens[$valueIndex + 2]['type'] ?? null) !== 'string'
        || !wp_fts_mutation_proof_sql_token_is_symbol($tokens[$valueIndex + 3] ?? null, ',')
        || ($tokens[$valueIndex + 4]['type'] ?? null) !== 'string'
        || ($tokens[$valueIndex + 4]['value'] ?? null) !== 'no'
        || !wp_fts_mutation_proof_sql_token_is_keyword($tokens[$suffixIndex] ?? null, 'ON')
        || !wp_fts_mutation_proof_sql_token_is_keyword($tokens[$suffixIndex + 1] ?? null, 'DUPLICATE')
        || !wp_fts_mutation_proof_sql_token_is_keyword($tokens[$suffixIndex + 2] ?? null, 'KEY')
        || !wp_fts_mutation_proof_sql_token_is_keyword($tokens[$suffixIndex + 3] ?? null, 'UPDATE')
        || !wp_fts_mutation_proof_sql_token_is_keyword($tokens[$suffixIndex + 4] ?? null, 'OPTION_NAME')
        || !wp_fts_mutation_proof_sql_token_is_symbol($tokens[$suffixIndex + 5] ?? null, '=')
        || !wp_fts_mutation_proof_sql_token_is_keyword($tokens[$suffixIndex + 6] ?? null, 'OPTION_NAME')
    ) {
        return null;
    }

    $payload = (string) $tokens[$valueIndex + 2]['value'];

    return wp_fts_mutation_proof_serialized_writer_payload_valid($payload) ? $payload : null;
}

/** Identify an option-table DELETE that names the writer lease literal. */
function wp_fts_mutation_proof_writer_lease_delete_candidate(
    string $sql,
    string $optionsTable,
    string $optionName
): bool {
    $tokens = wp_fts_mutation_proof_sql_tokens($sql);
    if (
        $tokens === null
        || !wp_fts_mutation_proof_sql_tokens_are_single_dml($tokens, 'DELETE')
        || !wp_fts_mutation_proof_sql_token_is_keyword($tokens[1] ?? null, 'FROM')
        || !wp_fts_mutation_proof_sql_identifier_matches($tokens[2] ?? null, $optionsTable)
    ) {
        return false;
    }

    return wp_fts_mutation_proof_sql_string_value_count($tokens, $optionName) === 1;
}

/** Prove the release DELETE compares exact name and acquired payload literals. */
function wp_fts_mutation_proof_writer_lease_delete_valid(
    string $sql,
    string $optionsTable,
    string $optionName,
    string $expectedPayload
): bool {
    $tokens = wp_fts_mutation_proof_sql_tokens($sql);

    return $tokens !== null
        && count($tokens) === 11
        && wp_fts_mutation_proof_sql_tokens_are_single_dml($tokens, 'DELETE')
        && wp_fts_mutation_proof_sql_token_is_keyword($tokens[1] ?? null, 'FROM')
        && wp_fts_mutation_proof_sql_identifier_matches($tokens[2] ?? null, $optionsTable)
        && wp_fts_mutation_proof_sql_token_is_keyword($tokens[3] ?? null, 'WHERE')
        && wp_fts_mutation_proof_sql_token_is_keyword($tokens[4] ?? null, 'OPTION_NAME')
        && wp_fts_mutation_proof_sql_token_is_symbol($tokens[5] ?? null, '=')
        && ($tokens[6]['type'] ?? null) === 'string'
        && ($tokens[6]['value'] ?? null) === $optionName
        && wp_fts_mutation_proof_sql_token_is_keyword($tokens[7] ?? null, 'AND')
        && wp_fts_mutation_proof_sql_token_is_keyword($tokens[8] ?? null, 'OPTION_VALUE')
        && wp_fts_mutation_proof_sql_token_is_symbol($tokens[9] ?? null, '=')
        && ($tokens[10]['type'] ?? null) === 'string'
        && ($tokens[10]['value'] ?? null) === $expectedPayload
        && wp_fts_mutation_proof_serialized_writer_payload_valid($expectedPayload);
}

/** Validate the exact scalar shape serialized by acquire_index_lock(). */
function wp_fts_mutation_proof_serialized_writer_payload_valid(string $serialized): bool
{
    $payload = @unserialize($serialized, ['allowed_classes' => false]);
    if (
        !is_array($payload)
        || array_keys($payload) !== ['token', 'mode', 'started_at', 'heartbeat_at', 'expires_at', 'renewals']
        || serialize($payload) !== $serialized
        || !is_string($payload['token'])
        || strlen($payload['token']) !== 24
        || !wp_fts_mutation_proof_ascii_hex_string($payload['token'])
        || $payload['mode'] !== 'manual'
        || !is_int($payload['started_at'])
        || !is_int($payload['heartbeat_at'])
        || !is_int($payload['expires_at'])
        || !is_int($payload['renewals'])
    ) {
        return false;
    }

    return $payload['started_at'] <= $payload['heartbeat_at']
        && $payload['heartbeat_at'] < $payload['expires_at']
        && $payload['renewals'] >= 0;
}

/** Classify explicit MySQL transaction controls after lexical comment removal. */
function wp_fts_mutation_proof_transaction_control(string $sql): ?string
{
    $tokens = wp_fts_mutation_proof_sql_tokens($sql);

    return $tokens === null ? 'INVALID SQL' : wp_fts_mutation_proof_transaction_control_tokens($tokens);
}

/** @param list<array{type:string,value:string}> $tokens */
function wp_fts_mutation_proof_transaction_control_tokens(array $tokens): ?string
{
    foreach (['COMMIT', 'ROLLBACK', 'BEGIN', 'SAVEPOINT', 'XA'] as $control) {
        if (wp_fts_mutation_proof_sql_token_is_keyword($tokens[0] ?? null, $control)) {
            return $control;
        }
    }
    if (wp_fts_mutation_proof_sql_token_is_keyword($tokens[0] ?? null, 'START')) {
        return wp_fts_mutation_proof_sql_token_is_keyword($tokens[1] ?? null, 'TRANSACTION')
            ? 'START TRANSACTION'
            : 'START';
    }
    if (
        wp_fts_mutation_proof_sql_token_is_keyword($tokens[0] ?? null, 'RELEASE')
        && wp_fts_mutation_proof_sql_token_is_keyword($tokens[1] ?? null, 'SAVEPOINT')
    ) {
        return 'RELEASE SAVEPOINT';
    }
    if (
        wp_fts_mutation_proof_sql_token_is_keyword($tokens[0] ?? null, 'LOCK')
        && wp_fts_mutation_proof_sql_token_is_keyword($tokens[1] ?? null, 'TABLES')
    ) {
        return 'LOCK TABLES';
    }
    if (
        wp_fts_mutation_proof_sql_token_is_keyword($tokens[0] ?? null, 'UNLOCK')
        && wp_fts_mutation_proof_sql_token_is_keyword($tokens[1] ?? null, 'TABLES')
    ) {
        return 'UNLOCK TABLES';
    }
    if (wp_fts_mutation_proof_sql_token_is_keyword($tokens[0] ?? null, 'SET')) {
        foreach ($tokens as $token) {
            if (wp_fts_mutation_proof_sql_token_is_keyword($token, 'AUTOCOMMIT')) {
                return 'SET AUTOCOMMIT';
            }
            if (wp_fts_mutation_proof_sql_token_is_keyword($token, 'TRANSACTION')) {
                return 'SET TRANSACTION';
            }
        }
    }

    return null;
}

/** Require a comment-free, semicolon-free statement headed by one DML verb. */
function wp_fts_mutation_proof_sql_is_single_dml(string $sql, ?string $requiredVerb = null): bool
{
    $tokens = wp_fts_mutation_proof_sql_tokens($sql);

    return $tokens !== null && wp_fts_mutation_proof_sql_tokens_are_single_dml($tokens, $requiredVerb);
}

/** @param list<array{type:string,value:string}> $tokens */
function wp_fts_mutation_proof_sql_tokens_are_single_dml(array $tokens, ?string $requiredVerb = null): bool
{
    $verbs = ['SELECT', 'INSERT', 'UPDATE', 'DELETE', 'REPLACE'];
    $verb = null;
    foreach ($verbs as $candidate) {
        if (wp_fts_mutation_proof_sql_token_is_keyword($tokens[0] ?? null, $candidate)) {
            $verb = $candidate;
            break;
        }
    }
    if ($verb === null || ($requiredVerb !== null && $verb !== strtoupper($requiredVerb))) {
        return false;
    }
    foreach ($tokens as $token) {
        if (wp_fts_mutation_proof_sql_token_is_symbol($token, ';')) {
            return false;
        }
    }

    return true;
}

/** @param list<array{type:string,value:string}> $tokens @param string[] $keywords */
function wp_fts_mutation_proof_sql_tokens_are_exact_keywords(array $tokens, array $keywords): bool
{
    if (count($tokens) !== count($keywords)) {
        return false;
    }
    foreach ($keywords as $index => $keyword) {
        if (!wp_fts_mutation_proof_sql_token_is_keyword($tokens[$index] ?? null, $keyword)) {
            return false;
        }
    }

    return true;
}

/** @param array{type:string,value:string}|null $token */
function wp_fts_mutation_proof_sql_token_is_keyword(?array $token, string $keyword): bool
{
    return in_array($token['type'] ?? null, ['word', 'identifier'], true)
        && strtoupper((string) ($token['value'] ?? '')) === strtoupper($keyword);
}

/** @param array{type:string,value:string}|null $token */
function wp_fts_mutation_proof_sql_token_is_symbol(?array $token, string $symbol): bool
{
    return ($token['type'] ?? null) === 'symbol' && ($token['value'] ?? null) === $symbol;
}

/** @param array{type:string,value:string}|null $token */
function wp_fts_mutation_proof_sql_token_is_positive_decimal(?array $token): bool
{
    $value = (string) ($token['value'] ?? '');
    if (($token['type'] ?? null) !== 'word' || $value === '' || $value[0] === '0') {
        return false;
    }
    for ($offset = 0, $length = strlen($value); $offset < $length; $offset++) {
        if (!str_contains('0123456789', $value[$offset])) {
            return false;
        }
    }

    return true;
}

/** @param array{type:string,value:string}|null $token */
function wp_fts_mutation_proof_sql_identifier_matches(?array $token, string $identifier): bool
{
    return in_array($token['type'] ?? null, ['word', 'identifier'], true)
        && strcasecmp((string) ($token['value'] ?? ''), $identifier) === 0;
}

/** @param list<array{type:string,value:string}> $tokens @param string[] $sequence */
function wp_fts_mutation_proof_sql_token_sequence_count(array $tokens, array $sequence): int
{
    $count = 0;
    $limit = count($tokens) - count($sequence);
    for ($offset = 0; $offset <= $limit; $offset++) {
        $matches = true;
        foreach ($sequence as $index => $expected) {
            $token = $tokens[$offset + $index] ?? null;
            $matches = strlen($expected) === 1 && wp_fts_mutation_proof_sql_is_symbol_character($expected)
                ? wp_fts_mutation_proof_sql_token_is_symbol($token, $expected)
                : wp_fts_mutation_proof_sql_token_is_keyword($token, $expected);
            if (!$matches) {
                break;
            }
        }
        if ($matches) {
            $count++;
        }
    }

    return $count;
}

/** @param list<array{type:string,value:string}> $tokens @param string[] $sequence */
function wp_fts_mutation_proof_sql_token_sequence_is_tail(array $tokens, array $sequence): bool
{
    if (count($tokens) < count($sequence)) {
        return false;
    }

    return wp_fts_mutation_proof_sql_token_sequence_count(
        array_slice($tokens, count($tokens) - count($sequence)),
        $sequence
    ) === 1;
}

/** @param list<array{type:string,value:string}> $tokens */
function wp_fts_mutation_proof_sql_token_value_count(array $tokens, string $value): int
{
    $count = 0;
    foreach ($tokens as $token) {
        if (wp_fts_mutation_proof_sql_token_is_keyword($token, $value)) {
            $count++;
        }
    }

    return $count;
}

/** @param list<array{type:string,value:string}> $tokens */
function wp_fts_mutation_proof_sql_string_value_count(array $tokens, string $value): int
{
    $count = 0;
    foreach ($tokens as $token) {
        if (($token['type'] ?? null) === 'string' && ($token['value'] ?? null) === $value) {
            $count++;
        }
    }

    return $count;
}

/** @param string[] $comments */
function wp_fts_mutation_proof_sql_comment_count(array $comments, string $expected): int
{
    $count = 0;
    foreach ($comments as $comment) {
        if (strcasecmp(trim($comment), $expected) === 0) {
            $count++;
        }
    }

    return $count;
}

/**
 * Tokenize the small SQL surface needed by the transaction proof.
 *
 * Ordinary comments are whitespace. MySQL/MariaDB executable comments are
 * rejected because treating their contents as comments could hide a boundary.
 *
 * @param string[]|null $comments
 * @return list<array{type:string,value:string}>|null
 */
function wp_fts_mutation_proof_sql_tokens(string $sql, ?array &$comments = null): ?array
{
    $tokens = [];
    $comments = [];
    $length = strlen($sql);
    $offset = 0;
    while ($offset < $length) {
        $character = $sql[$offset];
        if (wp_fts_mutation_proof_sql_is_space($character)) {
            $offset++;
            continue;
        }
        if ($character === '#') {
            wp_fts_mutation_proof_sql_skip_line_comment($sql, $offset);
            continue;
        }
        if (
            $character === '-'
            && ($sql[$offset + 1] ?? '') === '-'
            && (($sql[$offset + 2] ?? '') === '' || wp_fts_mutation_proof_sql_is_space($sql[$offset + 2]))
        ) {
            wp_fts_mutation_proof_sql_skip_line_comment($sql, $offset);
            continue;
        }
        if ($character === '/' && ($sql[$offset + 1] ?? '') === '*') {
            $third = $sql[$offset + 2] ?? '';
            $fourth = $sql[$offset + 3] ?? '';
            if ($third === '!' || ((strtoupper($third) === 'M') && $fourth === '!')) {
                return null;
            }
            $end = strpos($sql, '*/', $offset + 2);
            if ($end === false) {
                return null;
            }
            $comments[] = substr($sql, $offset + 2, $end - $offset - 2);
            $offset = $end + 2;
            continue;
        }
        if ($character === "'" || $character === '"') {
            $token = wp_fts_mutation_proof_sql_read_quoted($sql, $offset, $character, 'string');
            if ($token === null) {
                return null;
            }
            $tokens[] = $token;
            continue;
        }
        if ($character === '`') {
            $token = wp_fts_mutation_proof_sql_read_quoted($sql, $offset, '`', 'identifier');
            if ($token === null) {
                return null;
            }
            $tokens[] = $token;
            continue;
        }
        if (wp_fts_mutation_proof_sql_is_symbol_character($character)) {
            $tokens[] = ['type' => 'symbol', 'value' => $character];
            $offset++;
            continue;
        }

        $start = $offset;
        while ($offset < $length) {
            $current = $sql[$offset];
            if (
                wp_fts_mutation_proof_sql_is_space($current)
                || $current === "'"
                || $current === '"'
                || $current === '`'
                || $current === '#'
                || wp_fts_mutation_proof_sql_is_symbol_character($current)
            ) {
                break;
            }
            $offset++;
        }
        if ($offset === $start) {
            return null;
        }
        $tokens[] = ['type' => 'word', 'value' => substr($sql, $start, $offset - $start)];
    }

    return $tokens;
}

/** @return array{type:string,value:string}|null */
function wp_fts_mutation_proof_sql_read_quoted(
    string $sql,
    int &$offset,
    string $quote,
    string $type
): ?array {
    $length = strlen($sql);
    $offset++;
    $value = '';
    while ($offset < $length) {
        $character = $sql[$offset];
        if ($character === $quote) {
            if (($sql[$offset + 1] ?? '') === $quote) {
                $value .= $quote;
                $offset += 2;
                continue;
            }
            $offset++;
            return ['type' => $type, 'value' => $value];
        }
        if ($character === '\\') {
            if ($offset + 1 >= $length) {
                return null;
            }
            $escaped = $sql[$offset + 1];
            $value .= match ($escaped) {
                '0' => "\0",
                'b' => "\x08",
                'n' => "\n",
                'r' => "\r",
                't' => "\t",
                'Z' => chr(26),
                default => $escaped,
            };
            $offset += 2;
            continue;
        }
        $value .= $character;
        $offset++;
    }

    return null;
}

/** Advance to the byte after a line comment's newline, or end of input. */
function wp_fts_mutation_proof_sql_skip_line_comment(string $sql, int &$offset): void
{
    $length = strlen($sql);
    while ($offset < $length && $sql[$offset] !== "\n" && $sql[$offset] !== "\r") {
        $offset++;
    }
    while ($offset < $length && ($sql[$offset] === "\n" || $sql[$offset] === "\r")) {
        $offset++;
    }
}

/** SQL whitespace bytes accepted by MySQL around keywords and comments. */
function wp_fts_mutation_proof_sql_is_space(string $character): bool
{
    return str_contains(" \t\r\n\f\v", $character);
}

/** Split punctuation needed by controls, qualified names, and predicates. */
function wp_fts_mutation_proof_sql_is_symbol_character(string $character): bool
{
    return str_contains('();,.=@+-*/<>!|&:^%?', $character);
}

/** Validate the generated writer token without a regexp parser shortcut. */
function wp_fts_mutation_proof_ascii_hex_string(string $value): bool
{
    if ($value === '') {
        return false;
    }
    for ($offset = 0, $length = strlen($value); $offset < $length; $offset++) {
        if (!str_contains('0123456789abcdef', $value[$offset])) {
            return false;
        }
    }

    return true;
}

/** Hash maps with stable key order while retaining statement-list order. */
function wp_fts_mutation_proof_canonical_hash(mixed $value): string
{
    return hash('sha256', json_encode(
        wp_fts_mutation_proof_canonicalize($value),
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR
    ));
}

/** Sort maps recursively without treating ordered lists as maps. */
function wp_fts_mutation_proof_canonicalize(mixed $value): mixed
{
    if (!is_array($value)) {
        return $value;
    }
    if (array_is_list($value)) {
        return array_map('wp_fts_mutation_proof_canonicalize', $value);
    }
    ksort($value, SORT_STRING);
    foreach ($value as $key => $child) {
        $value[$key] = wp_fts_mutation_proof_canonicalize($child);
    }

    return $value;
}

/** @return array{result:mixed,queries:string[]} */
function wp_fts_mutation_proof_capture_wordpress_queries(callable $operation): array
{
    $queries = [];
    $capture = static function (string $sql) use (&$queries): string {
        $queries[] = $sql;
        return $sql;
    };
    add_filter('query', $capture, PHP_INT_MAX, 1);
    try {
        $result = $operation();
    } finally {
        remove_filter('query', $capture, PHP_INT_MAX);
    }

    return ['result' => $result, 'queries' => $queries];
}

/** Reach the private production storage factory only from this fixture. */
function wp_fts_mutation_proof_storage_fixture(): WP_FTS_Relational_Storage
{
    $method = new ReflectionMethod(WP_FTS_Plugin::class, 'storage');
    $method->setAccessible(true);

    return $method->invoke(null);
}
