<?php
declare(strict_types=1);

/**
 * Durable generation-aware indexing work queue backed by one WordPress table.
 *
 * Every enqueue atomically advances a post's generation. Workers claim the
 * generation they observed and may only acknowledge that exact generation.
 * A save that lands during indexing therefore remains queued instead of being
 * removed by the older worker. Claims are leased so another process can recover
 * work after a crash, and failures return the claimed generation with bounded
 * exponential backoff.
 */
final class WP_FTS_Foreground_Owner_Guard_Busy extends RuntimeException
{
}

final class WP_FTS_Index_Queue
{
    /** Singleton row used only to invalidate stale search-after cursors. */
    public const SEARCH_EPOCH_JOB_KEY = 'meta:search-epoch';
    /** Every corpus-wide reason shares one durable generation frontier. */
    public const GLOBAL_CORPUS_SCOPE_KEY = 'global-corpus';
    public const SCOPE_COVERAGE_CORPUS = 'corpus';
    public const SCOPE_COVERAGE_GLOBAL = 'global';
    public const SCOPE_COVERAGE_TARGETED = 'targeted';
    public const SCOPE_COVERAGE_FILTERED = 'filtered';
    public const DEFAULT_LEASE_SECONDS = 300;
    /** A claim may be renewed, but one call cannot hide work for over an hour. */
    public const MAX_LEASE_SECONDS = 3600;
    /** Maximum canonical source materialized by one claim confirmation. */
    public const MAX_SOURCE_SNAPSHOT_BYTES = 8388608;
    public const CANONICAL_POST_COLUMNS = [
        'ID',
        'post_author',
        'post_date',
        'post_date_gmt',
        'post_content',
        'post_title',
        'post_excerpt',
        'post_status',
        'comment_status',
        'ping_status',
        'post_password',
        'post_name',
        'to_ping',
        'pinged',
        'post_modified',
        'post_modified_gmt',
        'post_content_filtered',
        'post_parent',
        'guid',
        'menu_order',
        'post_type',
        'post_mime_type',
        'comment_count',
    ];
    public const BASE_BACKOFF_SECONDS = 300;
    public const MAX_BACKOFF_SECONDS = 3600;
    /** Legacy compatibility only; failures are no longer terminal. */
    public const DEAD_AFTER_ATTEMPTS = 3;
    public const MAX_ENQUEUE_POSTS = 1000;
    public const MAX_CLAIM_POSTS = 100;
    /** Persisted fair-turn marker for a scope co-claimed with direct posts. */
    public const SCOPE_EXPANSION_TURN_CODE = 'scope_turn';
    /** A live foreground request is polled at most once per fence interval. */
    public const FOREGROUND_OWNER_WATCHDOG_SECONDS = 300;
    /** A hostile exclusive file lock must not hang a canonical WordPress write. */
    private const FOREGROUND_OWNER_GUARD_WAIT_MICROSECONDS = 50000;
    /** Operator counts stop after one more row than a complete worker claim. */
    public const STATUS_COUNT_LIMIT = self::MAX_CLAIM_POSTS + 1;
    /** Bulk mode begins before a second distinct direct scope can be written. */
    public const MAX_FOREGROUND_DIRECT_SCOPES = 1;
    private const MAX_ENQUEUE_SQL_BYTES = 1048576;
    private const MAX_PAYLOAD_NODES = 256;
    private const MAX_PAYLOAD_DEPTH = 8;
    private const MAX_PAYLOAD_BYTES = 8192;

    private object $wpdb;
    private string $table;
    private string $postsTable;
    private string $documentsTable;
    private ?bool $sqliteRuntime = null;
    private string $foregroundOwnerGuardProbeState = 'unknown';

    /** @var array<string,int> Shared guards held by this PHP process. */
    private static array $foregroundOwnerGuardPaths = [];

    /** @var array<string,int> Exclusive lifecycle guards held by this PHP process. */
    private static array $foregroundOwnerExclusiveGuardPaths = [];

    /** Bind queue, posts, and document tables to one WordPress site prefix. */
    public function __construct(object $wpdb, ?string $prefix = null)
    {
        $this->wpdb = $wpdb;
        $prefixWasExplicit = $prefix !== null;
        $prefix = $prefix ?? (string) ($wpdb->prefix ?? '');
        $this->table = $prefix . 'fts_work';
        $this->postsTable = !$prefixWasExplicit && isset($wpdb->posts) && is_scalar($wpdb->posts)
            ? (string) $wpdb->posts
            : $prefix . 'posts';
        $this->documentsTable = $prefix . 'fts_documents';
    }

    /**
     * Hold one request-lifetime shared guard before canonical mutation.
     *
     * The fixed file is shared by every PHP process for this site. Multiple
     * foreground requests may hold it concurrently; process death closes the
     * descriptor. Workers take it exclusively only for nonblocking probes
     * around a claim write, so database reconnects and persistent sessions
     * are irrelevant to owner liveness.
     *
     * @return array{kind:'flock',path:string,handle:resource}
     */
    public function acquire_foreground_owner_guard(): array
    {
        $path = $this->foreground_owner_guard_path();
        if ((self::$foregroundOwnerExclusiveGuardPaths[$path] ?? 0) > 0) {
            throw new WP_FTS_Foreground_Owner_Guard_Busy(
                'The FTS foreground owner guard is held by lifecycle cleanup.'
            );
        }
        $handle = $this->open_foreground_owner_guard($path);
        // A worker uses LOCK_EX only as a nonblocking liveness probe. Bound the
        // wait anyway: an unrelated process must not hang a caller that already
        // owns database locks and is entering a canonical hook.
        $locked = false;
        $deadline = hrtime(true) + self::FOREGROUND_OWNER_GUARD_WAIT_MICROSECONDS * 1000;
        do {
            $wouldBlock = 0;
            $locked = is_resource($handle) && @flock($handle, LOCK_SH | LOCK_NB, $wouldBlock);
            if ($locked || $wouldBlock !== 1 || hrtime(true) >= $deadline) {
                break;
            }
            usleep(1000);
        } while (true);
        if (!$locked || !$this->foreground_owner_guard_handle_matches_path($handle, $path)) {
            if (is_resource($handle)) {
                if ($locked) {
                    @flock($handle, LOCK_UN);
                }
                @fclose($handle);
            }
            if (!$locked && $wouldBlock === 1) {
                throw new WP_FTS_Foreground_Owner_Guard_Busy(
                    'The FTS foreground owner guard is held by lifecycle cleanup.'
                );
            }
            throw new RuntimeException('Failed to acquire the FTS foreground owner guard.');
        }
        self::$foregroundOwnerGuardPaths[$path] = (self::$foregroundOwnerGuardPaths[$path] ?? 0) + 1;

        return ['kind' => 'flock', 'path' => $path, 'handle' => $handle];
    }

    /**
     * Exclude every foreground mutation while uninstall destroys site state.
     *
     * The wait is bounded for the same reason as foreground acquisition:
     * uninstall must be retried rather than hang behind an active request.
     *
     * @return array{kind:'flock-exclusive',path:string,handle:resource}
     */
    public function acquire_exclusive_foreground_owner_guard(): array
    {
        $path = $this->foreground_owner_guard_path();
        if (
            (self::$foregroundOwnerGuardPaths[$path] ?? 0) > 0
            || (self::$foregroundOwnerExclusiveGuardPaths[$path] ?? 0) > 0
        ) {
            throw new WP_FTS_Foreground_Owner_Guard_Busy(
                'Active FTS foreground work prevented lifecycle cleanup.'
            );
        }
        $handle = $this->open_foreground_owner_guard($path);
        $locked = false;
        $wouldBlock = 0;
        $deadline = hrtime(true) + self::FOREGROUND_OWNER_GUARD_WAIT_MICROSECONDS * 1000;
        do {
            $wouldBlock = 0;
            $locked = is_resource($handle) && @flock($handle, LOCK_EX | LOCK_NB, $wouldBlock);
            if ($locked || $wouldBlock !== 1 || hrtime(true) >= $deadline) {
                break;
            }
            usleep(1000);
        } while (true);
        if (!$locked || !$this->foreground_owner_guard_handle_matches_path($handle, $path)) {
            if (is_resource($handle)) {
                if ($locked) {
                    @flock($handle, LOCK_UN);
                }
                @fclose($handle);
            }
            if (!$locked && $wouldBlock === 1) {
                throw new WP_FTS_Foreground_Owner_Guard_Busy(
                    'Active FTS foreground work prevented lifecycle cleanup.'
                );
            }
            throw new RuntimeException('Failed to acquire the exclusive FTS foreground owner guard.');
        }
        self::$foregroundOwnerExclusiveGuardPaths[$path] =
            (self::$foregroundOwnerExclusiveGuardPaths[$path] ?? 0) + 1;

        return ['kind' => 'flock-exclusive', 'path' => $path, 'handle' => $handle];
    }

    /**
     * Release exactly the request guard acquired above.
     *
     * @param array<string,mixed> $guard
     */
    public function release_foreground_owner_guard(array $guard): void
    {
        $path = $guard['path'] ?? null;
        $handle = $guard['handle'] ?? null;
        if (($guard['kind'] ?? '') !== 'flock' || !is_string($path)) {
            throw new InvalidArgumentException('Invalid FTS foreground owner guard.');
        }
        if (is_resource($handle)) {
            @flock($handle, LOCK_UN);
            @fclose($handle);
        }
        $remaining = max(0, (self::$foregroundOwnerGuardPaths[$path] ?? 0) - 1);
        if ($remaining === 0) {
            unset(self::$foregroundOwnerGuardPaths[$path]);
        } else {
            self::$foregroundOwnerGuardPaths[$path] = $remaining;
        }
    }

    /** @param array<string,mixed> $guard */
    public function release_exclusive_foreground_owner_guard(array $guard): void
    {
        $path = $guard['path'] ?? null;
        $handle = $guard['handle'] ?? null;
        if (($guard['kind'] ?? '') !== 'flock-exclusive' || !is_string($path)) {
            throw new InvalidArgumentException('Invalid exclusive FTS foreground owner guard.');
        }
        if (is_resource($handle)) {
            @flock($handle, LOCK_UN);
            @fclose($handle);
        }
        $remaining = max(0, (self::$foregroundOwnerExclusiveGuardPaths[$path] ?? 0) - 1);
        if ($remaining === 0) {
            unset(self::$foregroundOwnerExclusiveGuardPaths[$path]);
        } else {
            self::$foregroundOwnerExclusiveGuardPaths[$path] = $remaining;
        }
    }

    /** @param array<string,mixed> $guard */
    public function foreground_owner_guard_is_current(array $guard): bool
    {
        $path = $guard['path'] ?? null;
        $handle = $guard['handle'] ?? null;

        return ($guard['kind'] ?? '') === 'flock'
            && is_string($path)
            && is_resource($handle)
            && (self::$foregroundOwnerGuardPaths[$path] ?? 0) > 0
            && $this->foreground_owner_guard_handle_matches_path($handle, $path);
    }

    /** Last worker probe result, for fail-closed health diagnostics. */
    public function foreground_owner_guard_probe_state(): string
    {
        return $this->foregroundOwnerGuardProbeState;
    }

    /**
     * Atomically coalesce a post save into the latest queued generation.
     */
    public function enqueue(int $post_id, ?int $now = null): void
    {
        $this->enqueue_many([$post_id], $now);
    }

    /**
     * Atomically coalesce any number of post mutations in one statement.
     *
     * The stable `post:<id>` key lets direct work and scope work share one
     * durable table without nullable or overloaded primary keys. A generation
     * that arrives while an older lease is active clears that lease while
     * advancing the generation. The old worker's generation-aware delete then
     * cannot remove the newer ready generation.
     *
     * @param int[] $post_ids
     * @return int Number of unique positive post ids accepted.
     */
    public function enqueue_many(array $post_ids, ?int $now = null, array $payload = []): int
    {
        if (count($post_ids) > self::MAX_ENQUEUE_POSTS) {
            throw new InvalidArgumentException(
                'FTS queue batch exceeds the ' . self::MAX_ENQUEUE_POSTS . '-post enqueue contract.'
            );
        }
        $ids = [];
        foreach ($post_ids as $post_id) {
            if (!is_scalar($post_id) || (is_string($post_id) && strlen($post_id) > 64)) {
                throw new InvalidArgumentException('FTS queue post ids must be bounded scalar values.');
            }
            $post_id = (int) $post_id;
            if ($post_id > 0) {
                $ids[$post_id] = true;
            }
        }
        if ($ids === []) {
            return 0;
        }
        if (count($ids) > self::MAX_ENQUEUE_POSTS) {
            throw new InvalidArgumentException(
                'FTS queue batch exceeds the ' . self::MAX_ENQUEUE_POSTS . '-post enqueue contract.'
            );
        }

        $now = $this->timestamp($now);
        $this->assert_bounded_payload($payload);
        $encodedPayload = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        if (strlen($encodedPayload) > self::MAX_PAYLOAD_BYTES) {
            throw new InvalidArgumentException('FTS post work payload exceeds 8192 bytes.');
        }
        $values = [];
        $args = [];
        foreach (array_keys($ids) as $post_id) {
            $values[] = "(%s, 'post', %d, 1, 'ready', %d, 0, '', 0, 0, 0, %s, '', 0)";
            $args[] = $this->post_job_key($post_id);
            $args[] = $post_id;
            $args[] = $now;
            $args[] = $encodedPayload;
        }
        // Advance the singleton cursor epoch in the same UPSERT that makes a
        // canonical post dirty. No search can observe one side without the
        // other, and foreground saves retain their one-data-statement shape.
        $values[] = "(%s, 'meta', 0, 1, 'meta', 0, 0, '', 0, 0, 0, %s, '', 0)";
        $args[] = self::SEARCH_EPOCH_JOB_KEY;
        $args[] = $this->new_search_epoch_incarnation();

        // Reject an oversized caller batch before SQL. Silently replacing an
        // exact dirty set with a corpus scope would hide unrelated search and
        // turn one crashed request into an unbounded reconciliation.
        $estimatedBytes = strlen(implode(",\n", $values))
            + count($ids) * (2 * strlen($encodedPayload) + 256);
        if ($estimatedBytes > self::MAX_ENQUEUE_SQL_BYTES) {
            throw new InvalidArgumentException('FTS queue batch exceeds the one-megabyte enqueue statement contract.');
        }

        $this->query($this->wpdb->prepare(
            "INSERT INTO {$this->table}
    (job_key, kind, post_id, generation, state, available_at, attempts, claim_token, claimed_generation, claim_expires_at, cursor_post_id, payload, last_error_code, last_error_at)
VALUES " . implode(",\n", $values) . "
ON DUPLICATE KEY UPDATE
    generation = generation + 1,
    available_at = IF(kind = 'meta', 0, IF(state IN ('fenced','guarded'), available_at, VALUES(available_at))),
    attempts = IF(kind = 'meta', 0, IF(state IN ('fenced','guarded'), attempts, 0)),
    claim_token = IF(kind = 'meta', '', IF(state IN ('fenced','guarded'), claim_token, '')),
    claimed_generation = IF(kind = 'meta', 0, IF(state IN ('fenced','guarded'), claimed_generation, 0)),
    claim_expires_at = IF(kind = 'meta', 0, IF(state IN ('fenced','guarded'), claim_expires_at, 0)),
    payload = IF(kind = 'meta', payload, VALUES(payload)),
    state = IF(kind = 'meta', 'meta', IF(state IN ('fenced','guarded'), state, 'ready'))",
            ...$args
        ), 'enqueue FTS indexing work');

        return count($ids);
    }

    /**
     * Cross one post's dirty boundary with one durable generation advance.
     *
     * Normal claims never consume operator-only `fenced` rows. Guard-backed
     * rows use the separately indexed `guarded` state. A later fence replaces
     * the token and generation atomically, so an older post hook cannot clear
     * it. `available_at` is the bounded crash-recovery delay for `guarded` work.
     *
     * @param array<string,mixed> $payload
     */
    public function fence_post(int $post_id, string $mutation_token, int $available_at, array $payload = []): void
    {
        if ($post_id <= 0 || $mutation_token === '' || strlen($mutation_token) > 64) {
            throw new InvalidArgumentException('FTS post mutation fences require a positive post id and bounded token.');
        }
        $this->assert_bounded_payload($payload);
        $encoded = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        if (strlen($encoded) > self::MAX_PAYLOAD_BYTES) {
            throw new InvalidArgumentException('FTS post work payload exceeds 8192 bytes.');
        }

        $job_key = $this->post_job_key($post_id);
        $epoch_incarnation = $this->new_search_epoch_incarnation();
        $fence_state = $this->mutation_fence_state($mutation_token);
        // Leave an unclaimable row until the matching post hook promotes it.
        $this->query($this->wpdb->prepare(
            "INSERT INTO {$this->table}
(job_key, kind, post_id, generation, state, available_at, attempts, claim_token, claimed_generation, claim_expires_at, cursor_post_id, payload, last_error_code, last_error_at)
VALUES (%s, 'post', %d, 1, %s, %d, 0, %s, 1, 0, 0, %s, '', 0)
, (%s, 'meta', 0, 1, 'meta', 0, 0, '', 0, 0, 0, %s, '', 0)
/* wp_fts:mutation-fence */
ON DUPLICATE KEY UPDATE
claimed_generation = CASE WHEN kind = 'meta' THEN 0 ELSE generation + 1 END,
generation = generation + 1,
state = CASE WHEN kind = 'meta' THEN 'meta' ELSE VALUES(state) END,
available_at = CASE WHEN kind = 'meta' THEN 0 ELSE VALUES(available_at) END,
attempts = 0,
claim_token = CASE WHEN kind = 'meta' THEN '' ELSE VALUES(claim_token) END,
claim_expires_at = 0,
payload = CASE WHEN kind = 'meta' THEN payload ELSE VALUES(payload) END,
last_error_code = '', last_error_at = 0",
            $job_key,
            $post_id,
            $fence_state,
            $this->timestamp($available_at),
            $mutation_token,
            $encoded,
            self::SEARCH_EPOCH_JOB_KEY,
            $epoch_incarnation
        ), 'fence FTS post mutation');
    }

    /**
     * Promote only the foreground mutation generation owned by this token.
     *
     * A null payload means the lifecycle hook has no replacement payload. It
     * must preserve newer one-off reindex options that coalesced into the row,
     * including after a guarded fence deadline let a worker recover the
     * generation.
     *
     * @param array<string,mixed>|null $payload
     */
    public function promote_post(int $post_id, string $mutation_token, ?int $now = null, ?array $payload = null): void
    {
        if ($post_id <= 0 || $mutation_token === '' || strlen($mutation_token) > 64) {
            throw new InvalidArgumentException('FTS post mutation promotion requires a positive post id and bounded token.');
        }
        $this->assert_bounded_payload($payload ?? []);
        $encoded = json_encode($payload ?? [], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        if (strlen($encoded) > self::MAX_PAYLOAD_BYTES) {
            throw new InvalidArgumentException('FTS post work payload exceeds 8192 bytes.');
        }

        $job_key = $this->post_job_key($post_id);
        $epoch_incarnation = $this->new_search_epoch_incarnation();
        $now = $this->timestamp($now);
        // Compare the original token in every expression. An older completion
        // can advance ready/leased work, but cannot make a newer fence
        // claimable or replace its payload.
        $owned = "(state NOT IN ('fenced','guarded') OR claim_token = VALUES(claim_token))";
        $owns_protected_generation = "(state IN ('fenced','guarded') AND claim_token = VALUES(claim_token) AND generation = claimed_generation)";
        $payload_assignment = $payload === null
            ? 'payload'
            : "CASE
    WHEN kind = 'meta' THEN payload
    WHEN {$owns_protected_generation} THEN VALUES(payload)
    ELSE payload
END";
        $payload_marker = $payload === null ? "\n/* wp_fts:preserve-existing-payload */" : '';
        $this->query($this->wpdb->prepare(
            "INSERT INTO {$this->table}
(job_key, kind, post_id, generation, state, available_at, attempts, claim_token, claimed_generation, claim_expires_at, cursor_post_id, payload, last_error_code, last_error_at)
VALUES (%s, 'post', %d, 1, 'ready', %d, 0, %s, 0, 0, 0, %s, '', 0)
, (%s, 'meta', 0, 1, 'meta', 0, 0, '', 0, 0, 0, %s, '', 0)
/* wp_fts:mutation-promote */{$payload_marker}
ON DUPLICATE KEY UPDATE
payload = {$payload_assignment},
available_at = CASE WHEN {$owned} THEN VALUES(available_at) ELSE available_at END,
attempts = CASE WHEN {$owned} THEN 0 ELSE attempts END,
claimed_generation = CASE WHEN {$owned} THEN 0 ELSE claimed_generation END,
claim_expires_at = CASE WHEN {$owned} THEN 0 ELSE claim_expires_at END,
last_error_code = CASE WHEN {$owned} THEN '' ELSE last_error_code END,
last_error_at = CASE WHEN {$owned} THEN 0 ELSE last_error_at END,
generation = generation + CASE WHEN state NOT IN ('fenced','guarded') THEN 1 ELSE 0 END,
state = CASE
    WHEN kind = 'meta' THEN 'meta'
    WHEN state IN ('fenced','guarded') AND claim_token = VALUES(claim_token) THEN 'ready'
    WHEN state NOT IN ('fenced','guarded') THEN 'ready'
    ELSE state
END,
claim_token = CASE WHEN {$owned} THEN '' ELSE claim_token END",
            $job_key,
            $post_id,
            $now,
            $mutation_token,
            $encoded,
            self::SEARCH_EPOCH_JOB_KEY,
            $epoch_incarnation
        ), 'promote FTS post mutation');
    }

    /**
     * Coalesce one corpus/taxonomy reconciliation without foreground fan-out.
     *
     * Scope work disables FTS takeover until a worker has keyset-expanded and
     * acknowledged the current generation. Payloads are bounded configuration,
     * never an unbounded list of affected post ids.
     *
     * @param array<string,mixed> $payload
     */
    public function enqueue_scope(
        string $scope_key,
        array $payload = [],
        ?int $now = null,
        string $scope_coverage = self::SCOPE_COVERAGE_FILTERED,
        string $scope_subject_type = '',
        int $scope_subject_id = 0,
        string $scope_incarnation = ''
    ): void
    {
        $this->enqueue_scope_internal(
            $scope_key,
            $payload,
            $now,
            $scope_coverage,
            $scope_subject_type,
            $scope_subject_id,
            $scope_incarnation,
            false
        );
    }

    /**
     * Advance canonical corpus debt without replacing its active incarnation.
     *
     * Abandoned request sentinels and overlapping bulk requests all converge
     * here. If the current canonical row is protected for this incarnation,
     * its token and payload remain authoritative while the desired generation
     * advances; an ordinary scope enqueue deliberately has no such behavior.
     *
     * @param array<string,mixed> $payload
     */
    public function coalesce_corpus_successor(
        array $payload,
        ?int $now,
        string $scope_incarnation
    ): void {
        $this->enqueue_scope_internal(
            self::GLOBAL_CORPUS_SCOPE_KEY,
            $payload,
            $now,
            self::SCOPE_COVERAGE_CORPUS,
            '',
            0,
            $scope_incarnation,
            true
        );
    }

    /** @param array<string,mixed> $payload */
    private function enqueue_scope_internal(
        string $scope_key,
        array $payload,
        ?int $now,
        string $scope_coverage,
        string $scope_subject_type,
        int $scope_subject_id,
        string $scope_incarnation,
        bool $preserve_matching_protected_authority
    ): void {
        $job_key = $this->scope_job_key($this->validated_scope_key($scope_key));
        $encoded = $this->encoded_scope_payload($payload);
        [$scope_coverage, $scope_subject_type, $scope_subject_id, $scope_incarnation]
            = $this->validated_scope_authority(
                $scope_coverage,
                $scope_subject_type,
                $scope_subject_id,
                $scope_incarnation
            );
        $epoch_incarnation = $this->new_search_epoch_incarnation();
        $preserve_protected_authority = $preserve_matching_protected_authority
            ? "(state IN ('fenced','guarded') AND scope_incarnation = VALUES(scope_incarnation))"
            : '0 = 1';

        $this->query($this->wpdb->prepare(
            "INSERT INTO {$this->table}
    (job_key, kind, post_id, generation, state, available_at, attempts, claim_token, claimed_generation, claim_expires_at, cursor_post_id, scope_coverage, scope_incarnation, scope_subject_type, scope_subject_id, payload, last_error_code, last_error_at)
VALUES (%s, 'scope', 0, 1, %s, %d, 0, '', 0, 0, 0, %s, %s, %s, %d, %s, '', 0)
, (%s, 'meta', 0, 1, 'meta', 0, 0, '', 0, 0, 0, '', '', '', 0, %s, '', 0)
ON DUPLICATE KEY UPDATE
    generation = generation + 1,
    available_at = CASE WHEN kind = 'meta' THEN 0 WHEN state IN ('fenced','guarded') THEN available_at ELSE VALUES(available_at) END,
    attempts = CASE WHEN kind = 'meta' THEN 0 WHEN state IN ('fenced','guarded') THEN attempts ELSE 0 END,
    claim_token = CASE WHEN kind = 'meta' THEN '' WHEN state IN ('fenced','guarded') THEN claim_token ELSE '' END,
    claimed_generation = CASE WHEN kind = 'meta' THEN 0 WHEN state IN ('fenced','guarded') THEN claimed_generation ELSE 0 END,
    claim_expires_at = CASE WHEN kind = 'meta' THEN 0 WHEN state IN ('fenced','guarded') THEN claim_expires_at ELSE 0 END,
    cursor_post_id = 0,
    scope_coverage = CASE WHEN kind = 'meta' THEN '' WHEN {$preserve_protected_authority} THEN scope_coverage ELSE VALUES(scope_coverage) END,
    scope_incarnation = CASE WHEN kind = 'meta' THEN '' WHEN {$preserve_protected_authority} THEN scope_incarnation ELSE VALUES(scope_incarnation) END,
    scope_subject_type = CASE WHEN kind = 'meta' THEN '' WHEN {$preserve_protected_authority} THEN scope_subject_type ELSE VALUES(scope_subject_type) END,
    scope_subject_id = CASE WHEN kind = 'meta' THEN 0 WHEN {$preserve_protected_authority} THEN scope_subject_id ELSE VALUES(scope_subject_id) END,
    payload = CASE WHEN kind = 'meta' THEN payload WHEN {$preserve_protected_authority} THEN payload ELSE VALUES(payload) END,
    last_error_code = CASE WHEN kind = 'meta' THEN '' WHEN state IN ('fenced','guarded') THEN last_error_code ELSE '' END,
    last_error_at = CASE WHEN kind = 'meta' THEN 0 WHEN state IN ('fenced','guarded') THEN last_error_at ELSE 0 END,
    state = CASE WHEN kind = 'meta' THEN 'meta' WHEN state IN ('fenced','guarded') THEN state ELSE VALUES(state) END",
            $job_key,
            'ready',
            $this->timestamp($now),
            $scope_coverage,
            $scope_incarnation,
            $scope_subject_type,
            $scope_subject_id,
            $encoded,
            self::SEARCH_EPOCH_JOB_KEY,
            $epoch_incarnation
        ), 'enqueue FTS scope work');
    }

    /**
     * Confirm the one exact corpus generation that owns profile reconciliation.
     *
     * This primary-key probe prevents an unrelated targeted scope from being
     * mistaken for the current analyzer rebuild.
     */
    public function corpus_scope_matches(
        string $scope_key,
        string $scope_incarnation,
        string $profile_hash
    ): bool {
        $scope_incarnation = strtolower(trim($scope_incarnation));
        $profile_hash = strtolower(trim($profile_hash));
        if (
            preg_match('/^[a-f0-9]{32}$/D', $scope_incarnation) !== 1
            || preg_match('/^[a-f0-9]{40}$/D', $profile_hash) !== 1
        ) {
            return false;
        }
        $job_key = $this->scope_job_key($this->validated_scope_key($scope_key));
        $rows = $this->get_results($this->wpdb->prepare(
            "SELECT scope_incarnation, payload
FROM {$this->table}
WHERE job_key = %s AND kind = 'scope' AND scope_coverage = 'corpus'
LIMIT 1",
            $job_key
        ), 'confirm exact FTS profile scope');
        $row = $rows[0] ?? null;
        if (!is_object($row) || !hash_equals($scope_incarnation, (string) ($row->scope_incarnation ?? ''))) {
            return false;
        }
        try {
            $payload = json_decode((string) ($row->payload ?? ''), true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return false;
        }
        $stored_profile = is_array($payload) && is_scalar($payload['profile_hash'] ?? null)
            ? strtolower(trim((string) $payload['profile_hash']))
            : '';

        return preg_match('/^[a-f0-9]{40}$/D', $stored_profile) === 1
            && hash_equals($profile_hash, $stored_profile);
    }

    /** Install one unclaimable scope boundary before canonical mutation. */
    public function fence_scope(
        string $scope_key,
        string $mutation_token,
        array $payload,
        int $available_at,
        string $scope_coverage = self::SCOPE_COVERAGE_FILTERED,
        string $scope_subject_type = '',
        int $scope_subject_id = 0,
        string $scope_incarnation = ''
    ): void {
        if ($mutation_token === '' || strlen($mutation_token) > 64) {
            throw new InvalidArgumentException('FTS scope mutation fences require a bounded token.');
        }
        $job_key = $this->scope_job_key($this->validated_scope_key($scope_key));
        $encoded = $this->encoded_scope_payload($payload);
        [$scope_coverage, $scope_subject_type, $scope_subject_id, $scope_incarnation]
            = $this->validated_scope_authority(
                $scope_coverage,
                $scope_subject_type,
                $scope_subject_id,
                $scope_incarnation
            );
        $epoch_incarnation = $this->new_search_epoch_incarnation();
        $fence_state = $this->mutation_fence_state($mutation_token);
        $this->query($this->wpdb->prepare(
            "INSERT INTO {$this->table}
(job_key, kind, post_id, generation, state, available_at, attempts, claim_token, claimed_generation, claim_expires_at, cursor_post_id, scope_coverage, scope_incarnation, scope_subject_type, scope_subject_id, payload, last_error_code, last_error_at)
VALUES (%s, 'scope', 0, 1, %s, %d, 0, %s, 1, 0, 0, %s, %s, %s, %d, %s, '', 0)
, (%s, 'meta', 0, 1, 'meta', 0, 0, '', 0, 0, 0, '', '', '', 0, %s, '', 0)
/* wp_fts:mutation-fence */
ON DUPLICATE KEY UPDATE
claimed_generation = CASE WHEN kind = 'meta' THEN 0 ELSE generation + 1 END,
generation = generation + 1,
state = CASE WHEN kind = 'meta' THEN 'meta' ELSE VALUES(state) END,
available_at = CASE WHEN kind = 'meta' THEN 0 ELSE VALUES(available_at) END, attempts = 0,
claim_token = CASE WHEN kind = 'meta' THEN '' ELSE VALUES(claim_token) END,
claim_expires_at = 0,
cursor_post_id = 0,
scope_coverage = CASE WHEN kind = 'meta' THEN '' ELSE VALUES(scope_coverage) END,
scope_incarnation = CASE WHEN kind = 'meta' THEN '' ELSE VALUES(scope_incarnation) END,
scope_subject_type = CASE WHEN kind = 'meta' THEN '' ELSE VALUES(scope_subject_type) END,
scope_subject_id = CASE WHEN kind = 'meta' THEN 0 ELSE VALUES(scope_subject_id) END,
payload = CASE WHEN kind = 'meta' THEN payload ELSE VALUES(payload) END,
last_error_code = '', last_error_at = 0",
            $job_key,
            $fence_state,
            $this->timestamp($available_at),
            $mutation_token,
            $scope_coverage,
            $scope_incarnation,
            $scope_subject_type,
            $scope_subject_id,
            $encoded,
            self::SEARCH_EPOCH_JOB_KEY,
            $epoch_incarnation
        ), 'fence FTS scope mutation');
    }

    /** Promote only the scope mutation generation owned by this request. */
    public function promote_scope(
        string $scope_key,
        string $mutation_token,
        array $payload,
        ?int $now = null,
        string $scope_coverage = self::SCOPE_COVERAGE_FILTERED,
        string $scope_subject_type = '',
        int $scope_subject_id = 0,
        string $scope_incarnation = ''
    ): void {
        if ($mutation_token === '' || strlen($mutation_token) > 64) {
            throw new InvalidArgumentException('FTS scope mutation promotion requires a bounded token.');
        }
        $job_key = $this->scope_job_key($this->validated_scope_key($scope_key));
        $encoded = $this->encoded_scope_payload($payload);
        [$scope_coverage, $scope_subject_type, $scope_subject_id, $scope_incarnation]
            = $this->validated_scope_authority(
                $scope_coverage,
                $scope_subject_type,
                $scope_subject_id,
                $scope_incarnation
            );
        $epoch_incarnation = $this->new_search_epoch_incarnation();
        $now = $this->timestamp($now);
        $owned = "(state NOT IN ('fenced','guarded') OR claim_token = VALUES(claim_token))";
        $owns_protected_generation = "(state IN ('fenced','guarded') AND claim_token = VALUES(claim_token) AND generation = claimed_generation)";
        // MySQL evaluates UPSERT assignments left-to-right. Keep the ownership
        // columns unchanged until every authority and payload expression has
        // compared the original row, then clear the matching request token.
        $this->query($this->wpdb->prepare(
            "INSERT INTO {$this->table}
(job_key, kind, post_id, generation, state, available_at, attempts, claim_token, claimed_generation, claim_expires_at, cursor_post_id, scope_coverage, scope_incarnation, scope_subject_type, scope_subject_id, payload, last_error_code, last_error_at)
VALUES (%s, 'scope', 0, 1, 'ready', %d, 0, %s, 0, 0, 0, %s, %s, %s, %d, %s, '', 0)
, (%s, 'meta', 0, 1, 'meta', 0, 0, '', 0, 0, 0, '', '', '', 0, %s, '', 0)
/* wp_fts:mutation-promote */
ON DUPLICATE KEY UPDATE
available_at = CASE WHEN {$owned} THEN VALUES(available_at) ELSE available_at END,
attempts = CASE WHEN {$owned} THEN 0 ELSE attempts END,
claim_expires_at = CASE WHEN {$owned} THEN 0 ELSE claim_expires_at END,
cursor_post_id = CASE WHEN {$owned} THEN 0 ELSE cursor_post_id END,
scope_coverage = CASE WHEN {$owns_protected_generation} THEN VALUES(scope_coverage) ELSE scope_coverage END,
scope_incarnation = CASE WHEN {$owns_protected_generation} THEN VALUES(scope_incarnation) ELSE scope_incarnation END,
scope_subject_type = CASE WHEN {$owns_protected_generation} THEN VALUES(scope_subject_type) ELSE scope_subject_type END,
scope_subject_id = CASE WHEN {$owns_protected_generation} THEN VALUES(scope_subject_id) ELSE scope_subject_id END,
payload = CASE
    WHEN kind = 'meta' THEN payload
    WHEN {$owns_protected_generation} THEN VALUES(payload)
    ELSE payload
END,
claimed_generation = CASE WHEN {$owned} THEN 0 ELSE claimed_generation END,
last_error_code = CASE WHEN {$owned} THEN '' ELSE last_error_code END,
last_error_at = CASE WHEN {$owned} THEN 0 ELSE last_error_at END,
generation = generation + CASE WHEN state NOT IN ('fenced','guarded') THEN 1 ELSE 0 END,
state = CASE
    WHEN kind = 'meta' THEN 'meta'
    WHEN state IN ('fenced','guarded') AND {$owned} THEN 'ready'
    WHEN state NOT IN ('fenced','guarded') THEN 'ready'
    ELSE state
END,
claim_token = CASE WHEN {$owned} THEN '' ELSE claim_token END",
            $job_key,
            $now,
            $mutation_token,
            $scope_coverage,
            $scope_incarnation,
            $scope_subject_type,
            $scope_subject_id,
            $encoded,
            self::SEARCH_EPOCH_JOB_KEY,
            $epoch_incarnation
        ), 'promote FTS scope mutation');
    }

    /**
     * Replace one request-global mutation fence with bounded work.
     *
     * Foreground WordPress APIs can emit pre/post hooks once per affected
     * object. The second distinct object installs one global scope fence, then
     * request shutdown publishes every retained post in one UPSERT and then
     * deletes that request-unique sentinel. If the retained set overflows,
     * canonical corpus work is published before that sentinel is deleted.
     * Every intermediate state remains fail-closed, concurrent requests
     * coalesce onto one corpus generation, and another request's fence is
     * never cleared.
     *
     * @param int[] $post_ids
     * @param array<int,string> $owned_post_tokens Tokens keyed by post id.
     * @param array<string,string> $owned_scope_tokens Active tokens keyed by the original scope key.
     * @param array<string,mixed> $scope_payload
     * @return array{post_count:int,global_scope_released:bool} The retained
     *         post count and whether this request removed its exact global
     *         sentinel. A false release result is not a persistence failure:
     *         guarded abandonment recovery or a newer token owns the sentinel.
     */
    public function handoff_foreground_mutation_scope(
        string $scope_key,
        string $mutation_token,
        array $post_ids,
        array $owned_post_tokens = [],
        array $owned_scope_tokens = [],
        bool $overflow = false,
        array $scope_payload = [],
        ?int $now = null,
        string $scope_incarnation = ''
    ): array {
        if ($mutation_token === '' || strlen($mutation_token) > 64) {
            throw new InvalidArgumentException('FTS foreground mutation handoff requires a bounded scope token.');
        }
        if (count($post_ids) > self::MAX_ENQUEUE_POSTS || count($owned_post_tokens) > self::MAX_ENQUEUE_POSTS) {
            throw new InvalidArgumentException(
                'FTS foreground mutation handoff exceeds the ' . self::MAX_ENQUEUE_POSTS . '-post contract.'
            );
        }

        $ids = [];
        foreach ($post_ids as $post_id) {
            if (!is_scalar($post_id) || (is_string($post_id) && strlen($post_id) > 64)) {
                throw new InvalidArgumentException('FTS foreground mutation post ids must be bounded scalar values.');
            }
            $post_id = (int) $post_id;
            if ($post_id > 0) {
                $ids[$post_id] = true;
            }
        }
        if (count($ids) > self::MAX_ENQUEUE_POSTS) {
            throw new InvalidArgumentException(
                'FTS foreground mutation handoff exceeds the ' . self::MAX_ENQUEUE_POSTS . '-post contract.'
            );
        }

        $tokens = [];
        foreach ($owned_post_tokens as $post_id => $token) {
            $post_id = (int) $post_id;
            if (
                $post_id <= 0
                || !isset($ids[$post_id])
                || !is_string($token)
                || $token === ''
                || strlen($token) > 64
            ) {
                throw new InvalidArgumentException('FTS foreground mutation post tokens must identify retained posts.');
            }
            $tokens[$post_id] = $token;
        }

        if (count($owned_scope_tokens) > self::MAX_FOREGROUND_DIRECT_SCOPES) {
            throw new InvalidArgumentException('FTS foreground mutation handoff accepts at most one direct scope.');
        }
        $scopeTokens = [];
        foreach ($owned_scope_tokens as $owned_scope_key => $token) {
            if (
                !is_string($owned_scope_key)
                || !is_string($token)
                || strlen($token) > 64
            ) {
                throw new InvalidArgumentException('FTS foreground mutation scope tokens must be bounded strings.');
            }
            $owned_scope_key = $this->validated_scope_key($owned_scope_key);
            if ($token !== '') {
                $scopeTokens[$owned_scope_key] = $token;
            }
        }

        $scope_key = $this->validated_scope_key($scope_key);
        $this->encoded_scope_payload($scope_payload);
        $globalScopeReleased = false;
        if ($overflow) {
            $this->validated_scope_authority(
                self::SCOPE_COVERAGE_CORPUS,
                '',
                0,
                $scope_incarnation
            );
        } elseif ($scope_incarnation !== '') {
            throw new InvalidArgumentException('Exact foreground handoff cannot carry a corpus incarnation.');
        }

        $now = $this->timestamp($now);
        if ($ids !== []) {
            $this->handoff_foreground_posts($ids, $tokens, $now);
        }

        if ($overflow) {
            // A crash between these statements leaves both scopes, never a
            // visibility gap; guarded abandonment recovery coalesces the
            // sentinel onto the same canonical row before deleting it.
            $this->coalesce_corpus_successor(
                $scope_payload,
                $now,
                $scope_incarnation
            );
            $globalScopeReleased = $this->delete_foreground_global_scope($scope_key, $mutation_token);
            // Active targeted fences are redundant once corpus work exists.
            // Already-ready scopes remain because an empty token cannot prove
            // that a concurrent request has not advanced their generation.
            $this->discard_owned_scope_fences($scopeTokens);
        } else {
            if ($ids === []) {
                throw new InvalidArgumentException('An exact FTS foreground handoff requires at least one retained post.');
            }
            // Post rows are durable before this delete. Therefore every state
            // between these two statements is fail-closed, while removing the
            // sentinel rather than changing its kind avoids an immortal random
            // metadata row for every bulk request.
            $globalScopeReleased = $this->delete_foreground_global_scope($scope_key, $mutation_token);
        }
        return [
            'post_count' => count($ids),
            'global_scope_released' => $globalScopeReleased,
        ];
    }

    /**
     * Publish a bounded exact post frontier without relying on assignment order.
     *
     * SQLite evaluates UPSERT expressions from the original row while MySQL and
     * MariaDB expose left-to-right assignments. Conditions that distinguish an
     * owned fence from a concurrent fence all run before `state` and
     * `claim_token` change, so both models preserve the same compare-and-swap.
     *
     * @param array<int,bool> $ids
     * @param array<int,string> $tokens
     */
    private function handoff_foreground_posts(array $ids, array $tokens, int $now): void
    {
        $values = [];
        $args = [];
        foreach (array_keys($ids) as $post_id) {
            $token = $tokens[$post_id] ?? '';
            $available = '%d';
            $values[] = "(%s, 'post', %d, 1, 'ready', {$available}, 0, %s, 0, 0, 0, '', 0, %s, '', 0)";
            $args[] = $this->post_job_key($post_id);
            $args[] = $post_id;
            $args[] = $now;
            array_push($args, $token, '[]');
        }
        $values[] = "(%s, 'meta', 0, 1, 'meta', 0, 0, '', 0, 0, 0, '', 0, %s, '', 0)";
        $args[] = self::SEARCH_EPOCH_JOB_KEY;
        $args[] = $this->new_search_epoch_incarnation();

        $estimatedBytes = strlen(implode(",\n", $values)) + count($ids) * 384 + 1024;
        if ($estimatedBytes > self::MAX_ENQUEUE_SQL_BYTES) {
            throw new InvalidArgumentException('FTS foreground mutation handoff exceeds the one-megabyte statement contract.');
        }

        $incomingEpoch = "VALUES(kind) = 'meta'";
        $incomingPost = "VALUES(kind) = 'post'";
        $postOwned = "kind = 'post' AND state IN ('fenced','guarded') AND VALUES(claim_token) <> '' AND claim_token = VALUES(claim_token)";
        $postWritable = "kind = 'post' AND (state NOT IN ('fenced','guarded') OR ({$postOwned}))";
        $latestPayload = "state NOT IN ('fenced','guarded') OR (({$postOwned}) AND generation = claimed_generation)";

        $this->query($this->wpdb->prepare(
            "INSERT INTO {$this->table}
    (job_key, kind, post_id, generation, state, available_at, attempts, claim_token, claimed_generation, claim_expires_at, cursor_post_id, scope_subject_type, scope_subject_id, payload, last_error_code, last_error_at)
VALUES " . implode(",\n", $values) . "
/* wp_fts:foreground-post-handoff */
ON DUPLICATE KEY UPDATE
    payload = CASE
        WHEN {$incomingEpoch} THEN payload
        WHEN {$incomingPost} AND ({$postWritable}) AND ({$latestPayload}) THEN VALUES(payload)
        ELSE payload
    END,
    available_at = CASE WHEN {$incomingEpoch} THEN 0 WHEN {$incomingPost} AND ({$postWritable}) THEN VALUES(available_at) ELSE available_at END,
    attempts = CASE WHEN {$incomingEpoch} THEN 0 WHEN {$incomingPost} AND ({$postWritable}) THEN 0 ELSE attempts END,
    claimed_generation = CASE WHEN {$incomingEpoch} THEN 0 WHEN {$incomingPost} AND ({$postWritable}) THEN 0 ELSE claimed_generation END,
    claim_expires_at = CASE WHEN {$incomingEpoch} THEN 0 WHEN {$incomingPost} AND ({$postWritable}) THEN 0 ELSE claim_expires_at END,
    cursor_post_id = CASE WHEN {$incomingEpoch} THEN 0 WHEN {$incomingPost} AND ({$postWritable}) THEN 0 ELSE cursor_post_id END,
    scope_subject_type = CASE WHEN {$incomingEpoch} THEN '' WHEN {$incomingPost} AND ({$postWritable}) THEN '' ELSE scope_subject_type END,
    scope_subject_id = CASE WHEN {$incomingEpoch} THEN 0 WHEN {$incomingPost} AND ({$postWritable}) THEN 0 ELSE scope_subject_id END,
    last_error_code = CASE WHEN {$incomingEpoch} THEN '' WHEN {$incomingPost} AND ({$postWritable}) THEN '' ELSE last_error_code END,
    last_error_at = CASE WHEN {$incomingEpoch} THEN 0 WHEN {$incomingPost} AND ({$postWritable}) THEN 0 ELSE last_error_at END,
    generation = generation + CASE
        WHEN {$incomingEpoch} THEN 1
        WHEN {$incomingPost} AND kind = 'post' AND state NOT IN ('fenced','guarded') THEN 1
        ELSE 0
    END,
    state = CASE WHEN {$incomingEpoch} THEN 'meta' WHEN {$incomingPost} AND ({$postWritable}) THEN 'ready' ELSE state END,
    claim_token = CASE WHEN {$incomingEpoch} THEN '' WHEN {$incomingPost} AND ({$postWritable}) THEN '' ELSE claim_token END,
    kind = CASE WHEN {$incomingEpoch} THEN 'meta' WHEN {$incomingPost} AND ({$postWritable}) THEN 'post' ELSE kind END",
                ...$args
            ), 'handoff exact FTS foreground posts');
    }

    /** Delete the exact request sentinel after all replacement post rows exist. */
    private function delete_foreground_global_scope(string $scope_key, string $mutation_token): bool
    {
        $jobKey = $this->scope_job_key($scope_key);
        $args = [$jobKey, $mutation_token];
        return $this->query($this->wpdb->prepare(
            "DELETE FROM {$this->table}
WHERE job_key = %s AND kind = 'scope' AND state IN ('fenced','guarded') AND claim_token = %s
/* wp_fts:foreground-global-delete */",
                ...$args
            ), 'delete FTS foreground global scope') === 1;
    }

    /** Delete one claimed visibility sentinel after canonical replacement exists. */
    public function discard_replaced_scope(array $claim): bool
    {
        $claim = $this->normalize_scope_claim($claim);
        if ($claim === null) {
            return false;
        }

        return $this->query($this->wpdb->prepare(
            "DELETE FROM {$this->table}
WHERE job_key = %s AND claim_token = %s
  AND claimed_generation = %d AND generation = %d
/* wp_fts:replaced-scope-delete */",
            $claim['job_key'],
            $claim['token'],
            $claim['generation'],
            $claim['generation']
        ), 'discard replaced FTS scope') === 1;
    }

    /** Whether a durable scope currently suppresses the whole corpus. */
    public function global_visibility_scope_exists(): bool
    {
        $value = $this->get_var(
            "SELECT 1 FROM {$this->table}
WHERE kind = 'scope' AND scope_coverage IN ('global','corpus')
LIMIT 1",
            'check global FTS visibility scope'
        );

        return is_numeric($value) && (int) $value === 1;
    }

    /**
     * Drop only still-owned targeted generations after corpus work is ready.
     *
     * Only an unpromoted fence retains the request token. Promotion clears it,
     * while a worker claim or concurrent enqueue replaces it, so the protected
     * state and token identify only this request's active generation.
     *
     * @param array<string,string> $scopeTokens
     */
    private function discard_owned_scope_fences(array $scopeTokens): void
    {
        if ($scopeTokens === []) {
            return;
        }
        $scopeKey = (string) array_key_first($scopeTokens);
        $token = (string) $scopeTokens[$scopeKey];
        $this->query($this->wpdb->prepare(
            "DELETE FROM {$this->table}
WHERE job_key = %s AND claim_token = %s
  AND kind = 'scope' AND state IN ('fenced','guarded')
/* wp_fts:foreground-owned-scope-delete */",
            $this->scope_job_key($scopeKey),
            $token
        ), 'discard covered FTS foreground scope generation');
    }

    /**
     * Make an existing failed generation available as new desired work.
     *
     * Explicit recovery advances the fencing generation and clears any old
     * lease. Otherwise a worker holding the pre-retry generation could still
     * acknowledge and delete the operator's retry.
     */
    public function retry(int $post_id, ?int $now = null): void
    {
        $this->retry_many([$post_id], $now);
    }

    /**
     * Make a bounded set of failed generations available in one statement.
     *
     * @param int[] $post_ids
     * @return int Number of unique positive post ids accepted.
     */
    public function retry_many(array $post_ids, ?int $now = null): int
    {
        if (count($post_ids) > self::MAX_ENQUEUE_POSTS) {
            throw new InvalidArgumentException(
                'FTS retry batch exceeds the ' . self::MAX_ENQUEUE_POSTS . '-post enqueue contract.'
            );
        }
        $ids = [];
        foreach ($post_ids as $post_id) {
            if (!is_scalar($post_id) || (is_string($post_id) && strlen($post_id) > 64)) {
                throw new InvalidArgumentException('FTS retry post ids must be bounded scalar values.');
            }
            $post_id = (int) $post_id;
            if ($post_id > 0) {
                $ids[$post_id] = true;
            }
        }
        if ($ids === []) {
            return 0;
        }

        $available_at = $this->timestamp($now);
        $values = [];
        $args = [];
        foreach (array_keys($ids) as $post_id) {
            $values[] = "(%s, 'post', %d, 1, 'ready', %d, 0, '', 0, 0, 0, '', 'operator_retry', 0)";
            $args[] = $this->post_job_key($post_id);
            $args[] = $post_id;
            $args[] = $available_at;
        }
        $values[] = "(%s, 'meta', 0, 1, 'meta', 0, 0, '', 0, 0, 0, %s, '', 0)";
        $args[] = self::SEARCH_EPOCH_JOB_KEY;
        $args[] = $this->new_search_epoch_incarnation();
        $this->query($this->wpdb->prepare(
            "INSERT INTO {$this->table}
    (job_key, kind, post_id, generation, state, available_at, attempts, claim_token, claimed_generation, claim_expires_at, cursor_post_id, payload, last_error_code, last_error_at)
VALUES " . implode(",\n", $values) . "
ON DUPLICATE KEY UPDATE
    generation = generation + 1,
    available_at = IF(kind = 'meta', 0, IF(state IN ('fenced','guarded'), available_at, VALUES(available_at))),
    attempts = IF(kind = 'meta', 0, IF(state IN ('fenced','guarded'), attempts, 0)),
    claim_token = IF(kind = 'meta', '', IF(state IN ('fenced','guarded'), claim_token, '')),
    claimed_generation = IF(kind = 'meta', 0, IF(state IN ('fenced','guarded'), claimed_generation, 0)),
    claim_expires_at = IF(kind = 'meta', 0, IF(state IN ('fenced','guarded'), claim_expires_at, 0)),
    last_error_code = IF(kind = 'meta', '', IF(state IN ('fenced','guarded'), last_error_code, 'operator_retry')),
    last_error_at = IF(kind = 'meta', 0, IF(state IN ('fenced','guarded'), last_error_at, 0)),
    state = IF(kind = 'meta', 'meta', IF(state IN ('fenced','guarded'), state, 'ready'))",
            ...$args
        ), 'retry FTS indexing work');

        return count($ids);
    }

    /**
     * Import normalized post ids from the legacy option queue.
     *
     * @param int[] $post_ids
     */
    public function import(array $post_ids, ?int $now = null): void
    {
        $this->enqueue_many($post_ids, $now);
    }

    /**
     * Claim at most one scope generation and one bounded direct-post batch.
     *
     * A single token lets production workers discover both work kinds with one
     * atomic UPDATE and one indexed confirmation read. Compatibility callers
     * may continue using the kind-specific claim methods below.
     *
     * When `$source_snapshot_limit` is positive, the confirmation read also
     * returns canonical source fields only if the complete claimed post set
     * fits that aggregate byte bound. A simultaneously claimed scope does not
     * count toward that post-only bound. Larger post sets return measurements
     * without LOBs so the worker can issue its conditional prefix fallback.
     *
     * @return array<int,array<string,mixed>>
     */
    public function claim_batch(
        int $post_limit,
        ?int $now = null,
        int $lease_seconds = self::DEFAULT_LEASE_SECONDS,
        int $source_snapshot_limit = 0
    ): array {
        if ($post_limit > self::MAX_CLAIM_POSTS) {
            throw new InvalidArgumentException('FTS work claims may contain at most 100 posts.');
        }
        if ($source_snapshot_limit < 0 || $source_snapshot_limit > self::MAX_SOURCE_SNAPSHOT_BYTES) {
            throw new InvalidArgumentException(
                'FTS source snapshots must be between zero and ' . self::MAX_SOURCE_SNAPSHOT_BYTES . ' bytes.'
            );
        }
        $post_limit = max(0, $post_limit);
        [$now, $lease_expires_at] = $this->lease_window($now, $lease_seconds);
        $claimGuard = $this->begin_foreground_fence_claim();
        $recoverGuardedFences = $claimGuard['state'] === 'free';
        $this->end_foreground_fence_claim($claimGuard);
        $token = bin2hex(random_bytes(16));
        $canonical_bytes_sql = $this->canonical_post_bytes_sql('source');
        $choices = [];
        $choiceArgs = [];
        $scopeChoice = $this->bounded_claim_choice('scope', 1, $now, $recoverGuardedFences);
        $choices[] = "SELECT job_key, generation FROM (\n{$scopeChoice['sql']}\n) scope_choice";
        $choiceArgs = $scopeChoice['args'];
        if ($post_limit > 0) {
            $postChoice = $this->bounded_claim_choice('post', $post_limit, $now, $recoverGuardedFences);
            $choices[] = "SELECT job_key, generation FROM (\n{$postChoice['sql']}\n) post_choice";
            array_push($choiceArgs, ...$postChoice['args']);
        }
        $choiceSql = implode("\n        UNION ALL\n        ", $choices);
        if ($this->is_sqlite_runtime()) {
            $guardedClaimSql = $recoverGuardedFences
                ? "\n      OR (state = 'guarded' AND available_at <= %d) /* wp_fts:only-guarded-fence-recovery */"
                : "\n      /* wp_fts:fences-require-free-guard */";
            $claimSql = "UPDATE /* wp_fts:claim-batch */ {$this->table}
SET state = 'leased', claim_token = %s,
    claimed_generation = generation, claim_expires_at = %d
WHERE (job_key, generation) IN (
    SELECT job_key, generation FROM (
        {$choiceSql}
    ) chosen_fts_work
)
  AND (
      (state = 'leased' AND claim_expires_at <= %d AND available_at <= %d)
      {$guardedClaimSql}
      OR (state IN ('ready','retry','dead') AND available_at <= %d)
  )";
            $claimArgs = [$token, $lease_expires_at, ...$choiceArgs, $now, $now];
            if ($recoverGuardedFences) {
                $claimArgs[] = $now;
            }
            $claimArgs[] = $now;
        } else {
            // MariaDB otherwise chooses the mutable table as the outer side of
            // `WHERE key IN (derived)` and scans the whole backlog. A
            // non-mergeable, at-most-101-row derived relation drives exact
            // PRIMARY-key probes instead. STRAIGHT_JOIN makes that bound part
            // of the statement rather than an optimizer-version preference.
            $guardedClaimSql = $recoverGuardedFences
                ? "\n      OR (claim_target.state = 'guarded' AND claim_target.available_at <= %d) /* wp_fts:only-guarded-fence-recovery */"
                : "\n      /* wp_fts:fences-require-free-guard */";
            $claimSql = "UPDATE /* wp_fts:claim-batch */ (
    SELECT chosen_fts_work.job_key, chosen_fts_work.generation,
           %s AS new_claim_token, %d AS new_claim_expires_at
    FROM (
        {$choiceSql}
    ) chosen_fts_work
) claim_driver
STRAIGHT_JOIN {$this->table} claim_target
        ON claim_target.job_key = claim_driver.job_key
       AND claim_target.generation = claim_driver.generation
SET claim_target.state = 'leased',
    claim_target.claim_token = claim_driver.new_claim_token,
    claim_target.claimed_generation = claim_target.generation,
    claim_target.claim_expires_at = claim_driver.new_claim_expires_at
WHERE (
      (claim_target.state = 'leased' AND claim_target.claim_expires_at <= %d AND claim_target.available_at <= %d)
      {$guardedClaimSql}
      OR (claim_target.state IN ('ready','retry','dead') AND claim_target.available_at <= %d)
  )";
            $claimArgs = [$token, $lease_expires_at, ...$choiceArgs, $now, $now];
            if ($recoverGuardedFences) {
                $claimArgs[] = $now;
            }
            $claimArgs[] = $now;
        }
        $this->query(
            $this->wpdb->prepare($claimSql, ...$claimArgs),
            'claim FTS work batch'
        );
        if (!$this->foreground_fence_claim_remains_free($recoverGuardedFences)) {
            // A foreground owner started after the first probe. Do not expose
            // any token-owned generation from this batch. A synthetic guarded
            // fence prevents the expired lease from becoming claimable while
            // that owner remains alive; its real fence supersedes this one, or
            // the next free worker recovers it immediately.
            $this->refence_interrupted_claim($token);
            return [];
        }

        $rows = $this->get_results($this->wpdb->prepare(
            "SELECT w.job_key, w.kind, w.post_id, w.generation, w.attempts, w.last_error_code,
                    w.claim_expires_at, w.cursor_post_id, w.scope_coverage, w.scope_incarnation,
                    w.scope_subject_type, w.scope_subject_id, w.payload,
                    (source.ID IS NOT NULL) AS source_exists,
                    OCTET_LENGTH(COALESCE(source.post_title, ''))
                      + OCTET_LENGTH(COALESCE(source.post_content, ''))
                      + OCTET_LENGTH(COALESCE(source.post_excerpt, '')) AS source_bytes,
                    {$canonical_bytes_sql} AS canonical_bytes,
                    COALESCE(source_batch.snapshot_complete, 0) AS source_snapshot_complete,
                    source.ID AS source_id,
                    CASE WHEN source_batch.snapshot_complete = 1 THEN source.post_title ELSE '' END AS source_post_title,
                    CASE WHEN source_batch.snapshot_complete = 1 THEN source.post_content ELSE '' END AS source_post_content,
                    CASE WHEN source_batch.snapshot_complete = 1 THEN source.post_excerpt ELSE '' END AS source_post_excerpt,
                    source.post_type AS source_post_type,
                    source.post_status AS source_post_status,
                    source.post_date_gmt AS source_post_date_gmt,
                    source.post_password AS source_post_password,
                    document.content_hash AS source_existing_hash
FROM {$this->table} w
LEFT JOIN {$this->postsTable} source ON source.ID = w.post_id AND w.kind = 'post'
LEFT JOIN {$this->documentsTable} document ON document.post_id = w.post_id AND w.kind = 'post'
LEFT JOIN (
    SELECT batch_work.claim_token,
           (COALESCE(SUM(
               OCTET_LENGTH(COALESCE(batch_source.post_title, ''))
               + OCTET_LENGTH(COALESCE(batch_source.post_content, ''))
               + OCTET_LENGTH(COALESCE(batch_source.post_excerpt, ''))
           ), 0) <= {$source_snapshot_limit}) AS snapshot_complete
    FROM {$this->table} batch_work
    LEFT JOIN {$this->postsTable} batch_source
           ON batch_source.ID = batch_work.post_id AND batch_work.kind = 'post'
    WHERE batch_work.claim_token = %s
      AND batch_work.claimed_generation = batch_work.generation
      AND batch_work.kind = 'post'
    GROUP BY batch_work.claim_token
) source_batch ON source_batch.claim_token = w.claim_token
WHERE w.claim_token = %s AND w.claimed_generation = w.generation
ORDER BY w.kind DESC, w.post_id ASC, w.job_key ASC",
            $token,
            $token
        ), 'confirm claimed FTS work batch');
        $claims = [];
        foreach ($rows as $row) {
            $job_key = isset($row->job_key) && is_scalar($row->job_key) ? (string) $row->job_key : '';
            $kind = (string) ($row->kind ?? '');
            $generation = max(0, (int) ($row->generation ?? 0));
            if ($job_key === '' || $generation <= 0 || !in_array($kind, ['post', 'scope'], true)) {
                continue;
            }
            $scopeAuthority = ['', '', 0, ''];
            if ($kind === 'scope') {
                $scopeAuthority = $this->validated_scope_authority(
                    (string) ($row->scope_coverage ?? ''),
                    (string) ($row->scope_subject_type ?? ''),
                    max(0, (int) ($row->scope_subject_id ?? 0)),
                    (string) ($row->scope_incarnation ?? '')
                );
            }
            $payload = [];
            if (isset($row->payload) && is_string($row->payload) && $row->payload !== '') {
                try {
                    $decoded = json_decode($row->payload, true, flags: JSON_THROW_ON_ERROR);
                    $payload = is_array($decoded) ? $decoded : [];
                } catch (JsonException) {
                    $payload = [];
                }
            }
            $source_snapshot = null;
            if ($kind === 'post' && !empty($row->source_exists) && !empty($row->source_snapshot_complete)) {
                $source_snapshot = (object) [
                    'ID' => max(0, (int) ($row->source_id ?? 0)),
                    'post_title' => (string) ($row->source_post_title ?? ''),
                    'post_content' => (string) ($row->source_post_content ?? ''),
                    'post_excerpt' => (string) ($row->source_post_excerpt ?? ''),
                    'post_type' => (string) ($row->source_post_type ?? ''),
                    'post_status' => (string) ($row->source_post_status ?? ''),
                    'post_date_gmt' => (string) ($row->source_post_date_gmt ?? ''),
                    'post_password' => (string) ($row->source_post_password ?? ''),
                    'fts_post_source_bytes' => max(0, (int) ($row->source_bytes ?? 0)),
                    'fts_canonical_post_bytes' => max(0, (int) ($row->canonical_bytes ?? 0)),
                    'fts_existing_hash' => isset($row->source_existing_hash) && is_scalar($row->source_existing_hash)
                        ? (string) $row->source_existing_hash
                        : null,
                ];
            }
            $claims[] = [
                'job_key' => $job_key,
                'kind' => $kind,
                'post_id' => max(0, (int) ($row->post_id ?? 0)),
                'generation' => $generation,
                'attempts' => max(0, (int) ($row->attempts ?? 0)),
                'last_error_code' => isset($row->last_error_code) && is_scalar($row->last_error_code)
                    ? substr((string) $row->last_error_code, 0, 40)
                    : '',
                'token' => $token,
                'claim_expires_at' => $lease_expires_at,
                'cursor_post_id' => max(0, (int) ($row->cursor_post_id ?? 0)),
                'scope_coverage' => $scopeAuthority[0],
                'scope_subject_type' => $scopeAuthority[1],
                'scope_subject_id' => $scopeAuthority[2],
                'scope_incarnation' => $scopeAuthority[3],
                'payload' => $payload,
                'source_exists' => !empty($row->source_exists),
                'source_bytes' => max(0, (int) ($row->source_bytes ?? 0)),
                'canonical_bytes' => max(0, (int) ($row->canonical_bytes ?? 0)),
                'source_snapshot' => $source_snapshot,
            ];
        }

        return $claims;
    }

    /** Build the bounded canonical-source byte expression used during claims. */
    private function canonical_post_bytes_sql(string $alias): string
    {
        return implode(' + ', array_map(
            static fn(string $column): string => "OCTET_LENGTH(COALESCE({$alias}.{$column}, ''))",
            self::CANONICAL_POST_COLUMNS
        ));
    }

    /**
     * Claim currently available generations with one set-oriented compare-and-swap.
     *
     * Expired leases satisfy the same predicate as unclaimed rows. A stale
     * worker's later acknowledgement fails because its token no longer owns the
     * row.
     *
     * @return array<int,array{post_id:int,generation:int,attempts:int,token:string,claim_expires_at:int}>
     */
    public function claim(int $limit, ?int $now = null, int $lease_seconds = self::DEFAULT_LEASE_SECONDS): array
    {
        if ($limit > self::MAX_CLAIM_POSTS) {
            throw new InvalidArgumentException('FTS work claims may contain at most 100 posts.');
        }
        $limit = max(0, $limit);
        [$now, $lease_expires_at] = $this->lease_window($now, $lease_seconds);
        if ($limit === 0) {
            return [];
        }

        $claimGuard = $this->begin_foreground_fence_claim();
        $recoverGuardedFences = $claimGuard['state'] === 'free';
        $this->end_foreground_fence_claim($claimGuard);
        $token = bin2hex(random_bytes(16));
        $choice = $this->bounded_claim_choice('post', $limit, $now, $recoverGuardedFences);
        // Every state arm reaches the ready/recoverable index and contributes
        // at most N candidates before the fixed outer priority sort.
        if ($this->is_sqlite_runtime()) {
            $guardedClaimSql = $recoverGuardedFences
                ? "\n      OR (state = 'guarded' AND available_at <= %d) /* wp_fts:only-guarded-fence-recovery */"
                : "\n      /* wp_fts:fences-require-free-guard */";
            $claimSql = "UPDATE /* wp_fts:claim-posts */ {$this->table}
SET state = 'leased',
    claim_token = %s,
    claimed_generation = generation,
    claim_expires_at = %d
WHERE kind = 'post'
  AND (job_key, generation) IN (
      SELECT job_key, generation FROM (
          {$choice['sql']}
      ) claimable_fts_work
  )
      AND (
          (state = 'leased' AND claim_expires_at <= %d AND available_at <= %d)
          {$guardedClaimSql}
          OR (state IN ('ready','retry','dead') AND available_at <= %d)
      )";
        } else {
            $guardedClaimSql = $recoverGuardedFences
                ? "\n      OR (claim_target.state = 'guarded' AND claim_target.available_at <= %d) /* wp_fts:only-guarded-fence-recovery */"
                : "\n      /* wp_fts:fences-require-free-guard */";
            $claimSql = "UPDATE /* wp_fts:claim-posts */ (
    SELECT claimable_fts_work.job_key, claimable_fts_work.generation,
           %s AS new_claim_token, %d AS new_claim_expires_at
    FROM (
        {$choice['sql']}
    ) claimable_fts_work
) claim_driver
STRAIGHT_JOIN {$this->table} claim_target
        ON claim_target.job_key = claim_driver.job_key
       AND claim_target.generation = claim_driver.generation
SET claim_target.state = 'leased',
    claim_target.claim_token = claim_driver.new_claim_token,
    claim_target.claimed_generation = claim_target.generation,
    claim_target.claim_expires_at = claim_driver.new_claim_expires_at
    WHERE claim_target.kind = 'post'
      AND (
          (claim_target.state = 'leased' AND claim_target.claim_expires_at <= %d AND claim_target.available_at <= %d)
          {$guardedClaimSql}
          OR (claim_target.state IN ('ready','retry','dead') AND claim_target.available_at <= %d)
      )";
        }
        $claimArgs = [$token, $lease_expires_at, ...$choice['args'], $now, $now];
        if ($recoverGuardedFences) {
            $claimArgs[] = $now;
        }
        $claimArgs[] = $now;
        $this->query($this->wpdb->prepare($claimSql, ...$claimArgs), 'claim FTS indexing work');
        if (!$this->foreground_fence_claim_remains_free($recoverGuardedFences)) {
            $this->refence_interrupted_claim($token);
            return [];
        }

        $claimedRows = $this->get_results($this->wpdb->prepare(
            "SELECT job_key, post_id, generation, attempts FROM {$this->table}
WHERE claim_token = %s AND claimed_generation = generation
ORDER BY post_id ASC",
            $token
        ), 'confirm claimed FTS indexing work');
        $claims = [];
        foreach ($claimedRows as $row) {
            $job_key = isset($row->job_key) && is_scalar($row->job_key) ? (string) $row->job_key : '';
            $post_id = max(0, (int) ($row->post_id ?? 0));
            $generation = max(0, (int) ($row->generation ?? 0));
            if (!self::is_post_job_key($job_key, $post_id) || $generation <= 0) {
                continue;
            }
            $claims[] = [
                'job_key' => $job_key,
                'post_id' => $post_id,
                'generation' => $generation,
                'attempts' => max(0, (int) ($row->attempts ?? 0)),
                'token' => $token,
                'claim_expires_at' => $lease_expires_at,
            ];
        }

        return $claims;
    }

    /**
     * Claim one global/scope reconciliation generation.
     *
     * Scope work is deliberately serialized by the plugin's existing writer
     * lease. It keyset-expands into direct post work without loading the whole
     * affected corpus into either the foreground request or PHP memory.
     *
     * @return array{job_key:string,kind:string,generation:int,attempts:int,token:string,claim_expires_at:int,cursor_post_id:int,payload:array<string,mixed>}|null
     */
    public function claim_scope(?int $now = null, int $lease_seconds = self::DEFAULT_LEASE_SECONDS): ?array
    {
        [$now, $lease_expires_at] = $this->lease_window($now, $lease_seconds);
        $claimGuard = $this->begin_foreground_fence_claim();
        $recoverGuardedFences = $claimGuard['state'] === 'free';
        $this->end_foreground_fence_claim($claimGuard);
        $token = bin2hex(random_bytes(16));
        $choice = $this->bounded_claim_choice('scope', 1, $now, $recoverGuardedFences);
        $rows = $this->get_results($this->wpdb->prepare(
            "SELECT job_key, kind, generation, attempts, cursor_post_id,
                    scope_coverage, scope_incarnation, scope_subject_type, scope_subject_id, payload,
                    state, available_at, claim_token
FROM {$this->table}
WHERE kind = 'scope' AND (job_key, generation) IN (
    SELECT job_key, generation FROM (
        {$choice['sql']}
    ) claimable_fts_scope
)
LIMIT 1",
            ...$choice['args']
        ), 'read claimable FTS scope work');
        $row = $rows[0] ?? null;
        if (!is_object($row)) {
            return null;
        }

        $job_key = isset($row->job_key) && is_scalar($row->job_key) ? (string) $row->job_key : '';
        $generation = max(0, (int) ($row->generation ?? 0));
        if ($job_key === '' || $generation <= 0) {
            return null;
        }
        $guardedClaimSql = $recoverGuardedFences
            ? "\n      OR (state = 'guarded' AND available_at <= %d) /* wp_fts:only-guarded-fence-recovery */"
            : "\n      /* wp_fts:fences-require-free-guard */";
        $claimArgs = [
            $token,
            $lease_expires_at,
            $job_key,
            $generation,
            $now,
            $now,
        ];
        if ($recoverGuardedFences) {
            $claimArgs[] = $now;
        }
        $claimArgs[] = $now;
        $affected = $this->query($this->wpdb->prepare(
            "UPDATE {$this->table}
SET state = 'leased', claim_token = %s,
    claimed_generation = generation, claim_expires_at = %d
WHERE job_key = %s AND generation = %d
  AND (
      (state = 'leased' AND claim_expires_at <= %d AND available_at <= %d)
      {$guardedClaimSql}
      OR (state IN ('ready','retry','dead') AND available_at <= %d)
  )",
            ...$claimArgs
        ), 'claim FTS scope work');
        if ($affected !== 1) {
            return null;
        }
        if (!$this->foreground_fence_claim_remains_free($recoverGuardedFences)) {
            $this->refence_interrupted_claim($token);
            return null;
        }

        $payload = [];
        if (isset($row->payload) && is_string($row->payload) && $row->payload !== '') {
            try {
                $decoded = json_decode($row->payload, true, flags: JSON_THROW_ON_ERROR);
                $payload = is_array($decoded) ? $decoded : [];
            } catch (JsonException) {
                $payload = [];
            }
        }
        $scopeAuthority = $this->validated_scope_authority(
            (string) ($row->scope_coverage ?? ''),
            (string) ($row->scope_subject_type ?? ''),
            max(0, (int) ($row->scope_subject_id ?? 0)),
            (string) ($row->scope_incarnation ?? '')
        );

        return [
            'job_key' => $job_key,
            'kind' => 'scope',
            'generation' => $generation,
            'attempts' => max(0, (int) ($row->attempts ?? 0)),
            'token' => $token,
            'claim_expires_at' => $lease_expires_at,
            'cursor_post_id' => max(0, (int) ($row->cursor_post_id ?? 0)),
            'scope_coverage' => $scopeAuthority[0],
            'scope_subject_type' => $scopeAuthority[1],
            'scope_subject_id' => $scopeAuthority[2],
            'scope_incarnation' => $scopeAuthority[3],
            'payload' => $payload,
        ];
    }

    /**
     * Build an at-most-five-arm candidate relation over the ready index.
     *
     * Each state arm materializes at most the requested batch size before the
     * outer priority sort. Consequently an eligible guarded abandonment wins
     * without a CASE filesort over the complete ready backlog, and the derived
     * relation never exceeds 500 post rows (five scope rows for a scope claim).
     *
     * @return array{sql:string,args:array<int,int>}
     */
    private function bounded_claim_choice(
        string $kind,
        int $limit,
        int $now,
        bool $recover_guarded_fences = true
    ): array
    {
        $kind = $kind === 'scope' ? 'scope' : 'post';
        $limit = max(1, min(self::MAX_CLAIM_POSTS, $limit));
        $order = 'available_at ASC, post_id ASC, job_key ASC';
        $states = $recover_guarded_fences
            ? ['guarded', 'ready', 'retry', 'leased', 'dead']
            : ['ready', 'retry', 'leased', 'dead'];
        $arms = [];
        $args = [];
        foreach ($states as $state) {
            $priority = $state === 'guarded' ? 0 : 1;
            $alias = "{$kind}_{$state}_candidates";
            $dueExpression = $state === 'leased' ? 'claim_expires_at' : 'available_at';
            $duePredicate = $state === 'leased'
                ? 'claim_expires_at <= %d AND available_at <= %d'
                : 'available_at <= %d';
            $innerOrder = $state === 'leased'
                ? 'claim_expires_at ASC, available_at ASC, post_id ASC, job_key ASC'
                : $order;
            $arms[] = "SELECT {$alias}.job_key, {$alias}.generation, {$priority} AS state_priority,
       {$alias}.due_at, {$alias}.post_id
    FROM (
        SELECT job_key, generation, {$dueExpression} AS due_at, post_id
        FROM {$this->table}
        WHERE kind = '{$kind}' AND state = '{$state}'
          AND {$duePredicate}
        ORDER BY {$innerOrder}
        LIMIT {$limit}
    ) {$alias}";
            $args[] = $now;
            if ($state === 'leased') {
                $args[] = $now;
            }
        }

        return [
            'sql' => ($recover_guarded_fences
                ? "/* wp_fts:only-guarded-fence-recovery */\n"
                : "/* wp_fts:fences-require-free-guard */\n") . "SELECT job_key, generation FROM (
" . implode("\nUNION ALL\n", $arms) . "
) bounded_{$kind}_candidates
ORDER BY state_priority ASC, due_at ASC, post_id ASC, job_key ASC
LIMIT {$limit}",
            'args' => $args,
        ];
    }

    /**
     * Atomically enqueue one exact scope page and persist its keyset cursor.
     *
     * The scope compare-and-swap runs first so a superseding generation cannot
     * receive stale fan-out. Its row lock remains held until the post UPSERT and
     * cursor advance commit together, leaving no durable cursor gap after a
     * crash or statement failure.
     *
     * @param array<string,mixed> $claim
     * @param int[] $post_ids
     */
    public function commit_scope_page(
        array $claim,
        array $post_ids,
        int $cursor_post_id,
        ?int $now = null,
        array $post_payload = [],
        ?array $next_scope_payload = null
    ): bool {
        $claim = $this->normalize_scope_claim($claim);
        if ($claim === null) {
            return false;
        }
        if (count($post_ids) > self::MAX_ENQUEUE_POSTS) {
            throw new InvalidArgumentException(
                'FTS scope page exceeds the ' . self::MAX_ENQUEUE_POSTS . '-post enqueue contract.'
            );
        }

        $ids = [];
        foreach ($post_ids as $post_id) {
            if (!is_scalar($post_id) || (is_string($post_id) && strlen($post_id) > 64)) {
                throw new InvalidArgumentException('FTS scope page post ids must be bounded scalar values.');
            }
            $post_id = (int) $post_id;
            if ($post_id <= 0) {
                throw new InvalidArgumentException('FTS scope pages require positive post ids.');
            }
            $ids[$post_id] = true;
        }
        if (count($ids) > self::MAX_ENQUEUE_POSTS) {
            throw new InvalidArgumentException(
                'FTS scope page exceeds the ' . self::MAX_ENQUEUE_POSTS . '-post enqueue contract.'
            );
        }
        $ids = array_map('intval', array_keys($ids));
        sort($ids, SORT_NUMERIC);
        $previous_cursor = max(0, (int) ($claim['cursor_post_id'] ?? 0));
        if (
            $cursor_post_id <= $previous_cursor
            || ($ids !== [] && max($ids) > $cursor_post_id)
        ) {
            throw new InvalidArgumentException(
                'FTS scope cursor must advance beyond its previous high-water and cover every page post id.'
            );
        }
        $this->assert_bounded_payload($post_payload);
        $scope_payload_assignment = '';
        $scope_payload_args = [];
        if ($next_scope_payload !== null) {
            $this->assert_bounded_payload($next_scope_payload);
            $encoded_scope_payload = json_encode($next_scope_payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            if (strlen($encoded_scope_payload) > self::MAX_PAYLOAD_BYTES) {
                throw new InvalidArgumentException('FTS scope work payload exceeds 8192 bytes.');
            }
            $scope_payload_assignment = ",\n    payload = %s";
            $scope_payload_args[] = $encoded_scope_payload;
        }

        $this->query('START TRANSACTION', 'start FTS scope page transaction');
        try {
            $advance_args = [
                $cursor_post_id,
                ...$scope_payload_args,
                $claim['job_key'],
                $claim['token'],
                $claim['generation'],
                $claim['generation'],
            ];
            $advanced = $this->query($this->wpdb->prepare(
                "UPDATE {$this->table}
SET cursor_post_id = %d,
    state = 'ready',
    claim_token = '',
    claimed_generation = 0,
    claim_expires_at = 0,
    last_error_code = '',
    last_error_at = 0{$scope_payload_assignment}
WHERE job_key = %s AND claim_token = %s
  AND claimed_generation = %d AND generation = %d",
                ...$advance_args
            ), 'fence and advance FTS scope page');
            if ($advanced !== 1) {
                $this->query('ROLLBACK', 'roll back superseded FTS scope page');
                return false;
            }

            if ($ids !== [] && $this->enqueue_many($ids, $now, $post_payload) !== count($ids)) {
                throw new RuntimeException('FTS scope page enqueue did not accept every post id.');
            }
            $this->query('COMMIT', 'commit FTS scope page transaction');
            return true;
        } catch (Throwable $error) {
            try {
                $this->query('ROLLBACK', 'roll back failed FTS scope page transaction');
            } catch (Throwable) {
                // Preserve the statement failure that made the transaction unsafe.
            }
            throw $error;
        }
    }

    /**
     * Acknowledge one completed scope or release a superseding generation.
     *
     * @param array<string,mixed> $claim
     */
    public function acknowledge_scope(array $claim, ?int $now = null): bool
    {
        $claim = $this->normalize_scope_claim($claim);
        if ($claim === null) {
            return false;
        }
        $this->query('START TRANSACTION', 'start FTS scope acknowledgement transaction');
        try {
            $this->advance_search_epoch();
            $deleted = $this->query($this->wpdb->prepare(
                "DELETE FROM {$this->table}
WHERE job_key = %s AND claim_token = %s
  AND claimed_generation = %d AND generation = %d",
                $claim['job_key'],
                $claim['token'],
                $claim['generation'],
                $claim['generation']
            ), 'acknowledge FTS scope work');
            if ($deleted === 1) {
                $this->query('COMMIT', 'commit FTS scope acknowledgement transaction');
                return true;
            }
            $this->query('ROLLBACK', 'roll back superseded FTS scope acknowledgement');
        } catch (Throwable $error) {
            try {
                $this->query('ROLLBACK', 'roll back failed FTS scope acknowledgement');
            } catch (Throwable) {
                // Preserve the acknowledgement failure.
            }
            throw $error;
        }

        return $this->query($this->wpdb->prepare(
            "UPDATE {$this->table}
SET state = 'ready', attempts = 0, available_at = %d,
    claim_token = '', claimed_generation = 0, claim_expires_at = 0,
    cursor_post_id = 0
WHERE job_key = %s AND claim_token = %s
  AND claimed_generation = %d AND generation > %d",
            $this->timestamp($now),
            $claim['job_key'],
            $claim['token'],
            $claim['generation'],
            $claim['generation']
        ), 'release superseded FTS scope work') === 1;
    }

    /** Release an unprocessed scope generation after a transient batch error. */
    public function release_scope(array $claim, ?int $now = null): bool
    {
        $claim = $this->normalize_scope_claim($claim);
        if ($claim === null) {
            return false;
        }

        return $this->query($this->wpdb->prepare(
            "UPDATE {$this->table}
SET state = 'ready', available_at = %d,
    claim_token = '', claimed_generation = 0, claim_expires_at = 0
WHERE job_key = %s AND claim_token = %s AND claimed_generation = %d",
            $this->timestamp($now),
            $claim['job_key'],
            $claim['token'],
            $claim['generation']
        ), 'release FTS scope work') === 1;
    }

    /**
     * Yield one mixed claim to direct posts and reserve the next collision for scope expansion.
     *
     * The marker lives on the existing durable scope row, so continuous post
     * arrivals cannot starve its keyset cursor and no second coordination row
     * or query is needed. A superseding generation clears claim ownership and
     * makes this exact compare-and-swap a harmless miss.
     */
    public function yield_scope_to_posts(array $claim, ?int $now = null): bool
    {
        $claim = $this->normalize_scope_claim($claim);
        if ($claim === null) {
            return false;
        }

        return $this->query($this->wpdb->prepare(
            "UPDATE {$this->table}
SET state = 'ready', available_at = %d,
    claim_token = '', claimed_generation = 0, claim_expires_at = 0,
    last_error_code = %s, last_error_at = 0
/* wp_fts:scope-yield-to-posts */
WHERE job_key = %s AND claim_token = %s
  AND claimed_generation = %d AND generation = %d",
            $this->timestamp($now),
            self::SCOPE_EXPANSION_TURN_CODE,
            $claim['job_key'],
            $claim['token'],
            $claim['generation'],
            $claim['generation']
        ), 'yield mixed FTS scope to direct posts') === 1;
    }

    /**
     * Persist the next scope turn and release a deferred post suffix together.
     *
     * @param array<string,mixed> $scope_claim
     * @param array<int,array<string,mixed>> $post_claims
     */
    public function yield_scope_and_release_posts(
        array $scope_claim,
        array $post_claims,
        ?int $now = null
    ): int {
        $scope = $this->normalize_scope_claim($scope_claim);
        $posts = $this->normalize_claims($post_claims);
        if ($scope === null) {
            return $this->release_many($post_claims, $now);
        }
        if ($posts === []) {
            return $this->yield_scope_to_posts($scope_claim, $now) ? 1 : 0;
        }

        $released_at = $this->timestamp($now);
        if ($this->is_sqlite_runtime()) {
            $predicates = [
                '(job_key = %s AND claim_token = %s AND claimed_generation = %d AND generation = %d)',
            ];
            $args = [
                $released_at,
                $scope['job_key'],
                self::SCOPE_EXPANSION_TURN_CODE,
                $scope['job_key'],
                $scope['token'],
                $scope['generation'],
                $scope['generation'],
            ];
            foreach ($posts as $claim) {
                $predicates[] = '(job_key = %s AND claim_token = %s AND claimed_generation = %d AND generation = %d)';
                array_push($args, $claim['job_key'], $claim['token'], $claim['generation'], $claim['generation']);
            }
            $sql = "UPDATE /* wp_fts:yield-scope-release-posts */ {$this->table}
SET state = 'ready', available_at = %d,
    claim_token = '', claimed_generation = 0, claim_expires_at = 0,
    last_error_code = CASE WHEN job_key = %s THEN %s ELSE last_error_code END,
    last_error_at = CASE WHEN job_key = %s THEN 0 ELSE last_error_at END
WHERE " . implode(' OR ', $predicates);
            array_splice($args, 3, 0, [$scope['job_key']]);
        } else {
            $rows = [
                'SELECT %s AS job_key, %s AS claim_token, %d AS claimed_generation, %d AS generation, 1 AS scope_turn',
            ];
            $args = [
                $released_at,
                $scope['job_key'],
                $scope['token'],
                $scope['generation'],
                $scope['generation'],
            ];
            foreach ($posts as $claim) {
                $rows[] = 'SELECT %s, %s, %d, %d, 0';
                array_push($args, $claim['job_key'], $claim['token'], $claim['generation'], $claim['generation']);
            }
            $limit = count($rows);
            $driver = "SELECT %d AS released_at, bounded_claims.*
FROM (" . implode("\nUNION ALL\n", $rows) . ") bounded_claims
LIMIT {$limit}";
            $sql = "UPDATE /* wp_fts:yield-scope-release-posts */ ({$driver}) claim_driver
STRAIGHT_JOIN {$this->table} work_target
        ON work_target.job_key = claim_driver.job_key
       AND work_target.claim_token = claim_driver.claim_token
       AND work_target.claimed_generation = claim_driver.claimed_generation
       AND work_target.generation = claim_driver.generation
SET work_target.state = 'ready', work_target.available_at = claim_driver.released_at,
    work_target.claim_token = '', work_target.claimed_generation = 0,
    work_target.claim_expires_at = 0,
    work_target.last_error_code = CASE
        WHEN claim_driver.scope_turn = 1 THEN '" . self::SCOPE_EXPANSION_TURN_CODE . "'
        ELSE work_target.last_error_code
    END,
    work_target.last_error_at = CASE
        WHEN claim_driver.scope_turn = 1 THEN 0
        ELSE work_target.last_error_at
    END";
        }

        return $this->query(
            $this->wpdb->prepare($sql, ...$args),
            'yield mixed FTS scope and release deferred posts'
        );
    }

    /** Return one failed scope generation with the same capped retry policy. */
    public function fail_scope(array $claim, ?int $now = null): array
    {
        $rawAttempts = $claim['attempts'] ?? 0;
        if (!is_scalar($rawAttempts) || (is_string($rawAttempts) && strlen($rawAttempts) > 64)) {
            return ['status' => 'lost', 'attempts' => 0, 'available_at' => 0];
        }
        $attempts = max(0, (int) $rawAttempts) + 1;
        $claim = $this->normalize_scope_claim($claim);
        if ($claim === null) {
            return ['status' => 'lost', 'attempts' => 0, 'available_at' => 0];
        }

        $now = $this->timestamp($now);
        $availableAt = $now + $this->backoff_seconds($attempts);
        $failed = $this->query($this->wpdb->prepare(
            "UPDATE {$this->table}
SET state = 'retry', attempts = %d, available_at = %d,
    claim_token = '', claimed_generation = 0, claim_expires_at = 0,
    last_error_code = 'scope_failure', last_error_at = %d
WHERE job_key = %s AND claim_token = %s
  AND claimed_generation = %d AND generation = %d",
            $attempts,
            $availableAt,
            $now,
            $claim['job_key'],
            $claim['token'],
            $claim['generation'],
            $claim['generation']
        ), 'defer failed FTS scope work');

        return $failed === 1
            ? ['status' => 'backoff', 'attempts' => $attempts, 'available_at' => $availableAt]
            : ['status' => 'superseded', 'attempts' => 0, 'available_at' => $now];
    }

    /**
     * Acknowledge only the generation owned by this claim.
     *
     * Enqueue already makes a superseding generation ready, so an exact delete
     * miss reports false without issuing a follow-up release statement.
     *
     * @param array{post_id:int,generation:int,token:string} $claim
     */
    public function acknowledge(array $claim, ?int $now = null): bool
    {
        $result = $this->acknowledge_many([$claim], $now);

        return $result['acknowledged'] === 1;
    }

    /**
     * Acknowledge a whole successfully committed batch in one statement.
     *
     * Enqueue clears an older lease while advancing the generation, so a
     * superseded claim simply fails this exact delete and the newer ready row
     * is already claimable. No follow-up release statement is necessary.
     *
     * @param array<int,array<string,mixed>> $claims
     * @return array{acknowledged:int,superseded:int}
     */
    public function acknowledge_many(array $claims, ?int $now = null): array
    {
        $this->assert_bounded_claim_count($claims);
        $normalized = [];
        foreach ($claims as $claim) {
            if (is_array($claim)) {
                $claim = $this->normalize_claim($claim);
                if ($claim !== null) {
                    $normalized[$claim['job_key']] = $claim;
                }
            }
        }
        if ($normalized === []) {
            return ['acknowledged' => 0, 'superseded' => 0];
        }

        $exact = [];
        $exactArgs = [];
        foreach ($normalized as $claim) {
            $exact[] = '(job_key = %s AND claim_token = %s AND claimed_generation = %d AND generation = %d)';
            array_push($exactArgs, $claim['job_key'], $claim['token'], $claim['generation'], $claim['generation']);
        }
        if ($this->is_sqlite_runtime()) {
            $deleteSql = "DELETE FROM {$this->table} WHERE " . implode(' OR ', $exact);
            $deleteArgs = $exactArgs;
        } else {
            $driver = $this->claim_identity_relation(array_values($normalized));
            $deleteSql = "DELETE /* wp_fts:acknowledge-batch */ work_target
FROM ({$driver['sql']}) claim_driver
STRAIGHT_JOIN {$this->table} work_target
        ON work_target.job_key = claim_driver.job_key
       AND work_target.claim_token = claim_driver.claim_token
       AND work_target.claimed_generation = claim_driver.claimed_generation
       AND work_target.generation = claim_driver.generation";
            $deleteArgs = $driver['args'];
        }

        $this->query('START TRANSACTION', 'start FTS acknowledgement transaction');
        try {
            $this->advance_search_epoch();
            $deleted = $this->query($this->wpdb->prepare(
                $deleteSql,
                ...$deleteArgs
            ), 'acknowledge FTS indexing batch');
            if ($deleted > 0) {
                $this->query('COMMIT', 'commit FTS acknowledgement transaction');
            } else {
                // A superseding generation remains dirty. Do not invalidate
                // cursors for a transition that did not become visible.
                $this->query('ROLLBACK', 'roll back superseded FTS acknowledgement');
            }
        } catch (Throwable $error) {
            try {
                $this->query('ROLLBACK', 'roll back failed FTS acknowledgement');
            } catch (Throwable) {
                // Preserve the statement failure that made the transition unsafe.
            }
            throw $error;
        }
        $acknowledged = min(count($normalized), max(0, $deleted));

        return [
            'acknowledged' => $acknowledged,
            'superseded' => count($normalized) - $acknowledged,
        ];
    }

    /**
     * Return a failed generation with bounded exponential backoff.
     *
     * A newer generation is released immediately and does not inherit the old
     * generation's failure count.
     *
     * @param array{post_id:int,generation:int,attempts?:int,token:string} $claim
     * @return array{status:string,attempts:int,available_at:int}
     */
    public function fail(array $claim, ?int $now = null): array
    {
        $claim = $this->normalize_claim($claim);
        if ($claim === null) {
            return ['status' => 'lost', 'attempts' => 0, 'available_at' => 0];
        }

        $now = $this->timestamp($now);
        $attempts = max(0, (int) ($claim['attempts'] ?? 0)) + 1;
        $available_at = $now + $this->backoff_seconds($attempts);
        $failed = $this->query($this->wpdb->prepare(
            "UPDATE {$this->table}
SET state = 'retry',
    attempts = %d,
    available_at = %d,
    claim_token = '',
    claimed_generation = 0,
    claim_expires_at = 0,
    last_error_code = 'content_failure',
    last_error_at = %d
WHERE job_key = %s
  AND claim_token = %s
  AND claimed_generation = %d
  AND generation = %d",
            $attempts,
            $available_at,
            $now,
            $claim['job_key'],
            $claim['token'],
            $claim['generation'],
            $claim['generation']
        ), 'defer failed FTS indexing work');
        if ($failed === 1) {
            return [
                'status' => 'backoff',
                'attempts' => $attempts,
                'available_at' => $available_at,
            ];
        }

        if ($this->release_superseded_claim($claim, $now) === 1) {
            return ['status' => 'superseded', 'attempts' => 0, 'available_at' => $now];
        }

        return ['status' => 'lost', 'attempts' => 0, 'available_at' => 0];
    }

    /**
     * Defer every content-analysis failure in one statement.
     *
     * @param array<int,array<string,mixed>> $claims
     * @return int Number of still-owned generations updated.
     */
    public function fail_many(array $claims, ?int $now = null): int
    {
        $normalized = $this->normalize_claims($claims);
        if ($normalized === []) {
            return 0;
        }
        $now = $this->timestamp($now);
        if ($this->is_sqlite_runtime()) {
            $predicates = [];
            $args = [];
            foreach ($normalized as $claim) {
                $predicates[] = '(job_key = %s AND claim_token = %s AND claimed_generation = %d AND generation = %d)';
                array_push($args, $claim['job_key'], $claim['token'], $claim['generation'], $claim['generation']);
            }
            $sql = "UPDATE {$this->table}
SET available_at = %d + CASE
        WHEN attempts <= 0 THEN " . self::BASE_BACKOFF_SECONDS . "
        WHEN attempts = 1 THEN " . (self::BASE_BACKOFF_SECONDS * 2) . "
        WHEN attempts = 2 THEN " . (self::BASE_BACKOFF_SECONDS * 4) . "
        WHEN attempts = 3 THEN " . (self::BASE_BACKOFF_SECONDS * 8) . "
        ELSE " . self::MAX_BACKOFF_SECONDS . " END,
    state = 'retry',
    attempts = attempts + 1,
    claim_token = '', claimed_generation = 0, claim_expires_at = 0,
    last_error_code = 'content_failure', last_error_at = %d
WHERE " . implode(' OR ', $predicates);
            $preparedArgs = [$now, $now, ...$args];
        } else {
            $driver = $this->claim_identity_relation($normalized, $now, 'failure_at');
            $sql = "UPDATE /* wp_fts:fail-batch */ ({$driver['sql']}) claim_driver
STRAIGHT_JOIN {$this->table} work_target
        ON work_target.job_key = claim_driver.job_key
       AND work_target.claim_token = claim_driver.claim_token
       AND work_target.claimed_generation = claim_driver.claimed_generation
       AND work_target.generation = claim_driver.generation
SET work_target.available_at = claim_driver.failure_at + CASE
        WHEN work_target.attempts <= 0 THEN " . self::BASE_BACKOFF_SECONDS . "
        WHEN work_target.attempts = 1 THEN " . (self::BASE_BACKOFF_SECONDS * 2) . "
        WHEN work_target.attempts = 2 THEN " . (self::BASE_BACKOFF_SECONDS * 4) . "
        WHEN work_target.attempts = 3 THEN " . (self::BASE_BACKOFF_SECONDS * 8) . "
        ELSE " . self::MAX_BACKOFF_SECONDS . " END,
    work_target.state = 'retry',
    work_target.attempts = work_target.attempts + 1,
    work_target.claim_token = '', work_target.claimed_generation = 0,
    work_target.claim_expires_at = 0,
    work_target.last_error_code = 'content_failure',
    work_target.last_error_at = claim_driver.failure_at";
            $preparedArgs = $driver['args'];
        }

        return $this->query(
            $this->wpdb->prepare($sql, ...$preparedArgs),
            'defer failed FTS indexing batch'
        );
    }

    /**
     * Release an unprocessed claim without acknowledging its generation.
     *
     * @param array{post_id:int,generation:int,token:string} $claim
     */
    public function release(array $claim, ?int $now = null): bool
    {
        $claim = $this->normalize_claim($claim);
        if ($claim === null) {
            return false;
        }

        $affected = $this->query($this->wpdb->prepare(
            "UPDATE {$this->table}
SET state = 'ready',
    available_at = %d,
    claim_token = '',
    claimed_generation = 0,
    claim_expires_at = 0
WHERE job_key = %s
  AND claim_token = %s
  AND claimed_generation = %d",
            $this->timestamp($now),
            $claim['job_key'],
            $claim['token'],
            $claim['generation']
        ), 'release FTS indexing work');

        return $affected === 1;
    }

    /**
     * Release a whole unprocessed batch in one statement.
     *
     * @param array<int,array<string,mixed>> $claims
     */
    public function release_many(array $claims, ?int $now = null): int
    {
        $normalized = $this->normalize_claims($claims);
        if ($normalized === []) {
            return 0;
        }
        $releasedAt = $this->timestamp($now);
        if ($this->is_sqlite_runtime()) {
            $predicates = [];
            $args = [];
            foreach ($normalized as $claim) {
                $predicates[] = '(job_key = %s AND claim_token = %s AND claimed_generation = %d)';
                array_push($args, $claim['job_key'], $claim['token'], $claim['generation']);
            }
            $sql = "UPDATE {$this->table}
SET state = 'ready', available_at = %d,
    claim_token = '', claimed_generation = 0, claim_expires_at = 0
WHERE " . implode(' OR ', $predicates);
            $preparedArgs = [$releasedAt, ...$args];
        } else {
            $driver = $this->claim_identity_relation($normalized, $releasedAt, 'released_at');
            $sql = "UPDATE /* wp_fts:release-batch */ ({$driver['sql']}) claim_driver
STRAIGHT_JOIN {$this->table} work_target
        ON work_target.job_key = claim_driver.job_key
       AND work_target.claim_token = claim_driver.claim_token
       AND work_target.claimed_generation = claim_driver.claimed_generation
SET work_target.state = 'ready', work_target.available_at = claim_driver.released_at,
    work_target.claim_token = '', work_target.claimed_generation = 0,
    work_target.claim_expires_at = 0";
            $preparedArgs = $driver['args'];
        }

        return $this->query(
            $this->wpdb->prepare($sql, ...$preparedArgs),
            'release FTS indexing batch'
        );
    }

    /** Return a bounded pending count; the limit value means "at least". */
    public function count(): int
    {
        $status = $this->status();

        return min(
            self::STATUS_COUNT_LIMIT,
            $status['post_count'] + $status['scope_count']
        );
    }

    /**
     * Prove whether any user work exists with two one-row index probes.
     *
     * Visitor and maintenance control flow must not count an arbitrarily large
     * backlog merely to answer a boolean question. `kind_job` can stop each
     * branch at its first matching key on every supported relational engine.
     */
    public function has_work(): bool
    {
        $value = $this->get_var(
            "SELECT 1 FROM (
    SELECT post_work.job_key FROM (
        SELECT job_key FROM {$this->table} WHERE kind = 'post' LIMIT 1
    ) post_work
    UNION ALL
    SELECT scope_work.job_key FROM (
        SELECT job_key FROM {$this->table} WHERE kind = 'scope' LIMIT 1
    ) scope_work
) bounded_pending_work
LIMIT 1",
            'check for pending FTS indexing work'
        );

        return is_numeric($value) && (int) $value === 1;
    }

    /**
     * Return bounded operator counts over at most 101 rows of each work kind.
     *
     * @return array{post_count:int,scope_count:int,scope_cursor_post_id:?int,post_count_relation:string,scope_count_relation:string,counts_capped:bool}
     */
    public function status(): array
    {
        $limit = self::STATUS_COUNT_LIMIT;
        $rows = $this->get_results(
            "SELECT 'post' AS kind, COUNT(*) AS work_count, 0 AS max_cursor_post_id
FROM (
    SELECT job_key FROM {$this->table}
    WHERE kind = 'post'
    ORDER BY job_key
    LIMIT {$limit}
) bounded_post_work
UNION ALL
SELECT 'scope' AS kind, COUNT(*) AS work_count,
       COALESCE(MAX(cursor_post_id), 0) AS max_cursor_post_id
FROM (
    SELECT job_key, cursor_post_id FROM {$this->table}
    WHERE kind = 'scope'
    ORDER BY job_key
    LIMIT {$limit}
) bounded_scope_work",
            'read bounded FTS work status'
        );
        $status = [
            'post_count' => 0,
            'scope_count' => 0,
            'scope_cursor_post_id' => 0,
            'post_count_relation' => 'exact',
            'scope_count_relation' => 'exact',
            'counts_capped' => false,
        ];
        foreach ($rows as $row) {
            $kind = isset($row->kind) && is_scalar($row->kind) ? (string) $row->kind : '';
            if ($kind === 'post') {
                $status['post_count'] = max(0, (int) ($row->work_count ?? 0));
            } elseif ($kind === 'scope') {
                $status['scope_count'] = max(0, (int) ($row->work_count ?? 0));
                $status['scope_cursor_post_id'] = max(0, (int) ($row->max_cursor_post_id ?? 0));
            }
        }
        $status['post_count_relation'] = $status['post_count'] >= $limit ? 'at_least' : 'exact';
        $status['scope_count_relation'] = $status['scope_count'] >= $limit ? 'at_least' : 'exact';
        $status['counts_capped'] = $status['post_count_relation'] !== 'exact'
            || $status['scope_count_relation'] !== 'exact';
        if ($status['scope_count_relation'] !== 'exact') {
            $status['scope_cursor_post_id'] = null;
        }

        return $status;
    }

    /**
     * Return the earliest instant at which any durable generation can run.
     *
     * A future retry uses `available_at`; an active lease cannot be stolen
     * before `claim_expires_at`. Legacy dead rows remain automatically
     * recoverable instead of requiring an operator to know they exist. A
     * non-free owner probe projects one bounded watchdog arm for each protected
     * state. A free probe includes only indexed `guarded` work and omits
     * operator-only `fenced` debt, so that debt cannot create a recurring cron
     * wake or hide guarded recovery behind an outer token filter.
     */
    public function next_available_at(): ?int
    {
        $arms = [];
        $watchdog_at = time() + self::FOREGROUND_OWNER_WATCHDOG_SECONDS;
        $claimGuard = $this->begin_foreground_fence_claim();
        $recoverGuardedFences = $claimGuard['state'] === 'free';
        $this->end_foreground_fence_claim($claimGuard);
        $states = $recoverGuardedFences
            ? ['guarded', 'ready', 'retry', 'leased', 'dead']
            : ['guarded', 'fenced', 'ready', 'retry', 'leased', 'dead'];
        foreach (['post', 'scope'] as $kind) {
            foreach ($states as $state) {
                $due = $state === 'leased' ? 'claim_expires_at' : 'available_at';
                $order = $state === 'leased'
                    ? 'claim_expires_at ASC, available_at ASC, post_id ASC, job_key ASC'
                    : 'available_at ASC, post_id ASC, job_key ASC';
                $alias = "next_{$kind}_{$state}";
                $protected = $state === 'guarded' || $state === 'fenced';
                if ($protected && !$recoverGuardedFences) {
                    $projected_due = "CASE /* wp_fts:nonfree-fence-watchdog */ WHEN {$alias}.due_at < {$watchdog_at}"
                        . " THEN {$watchdog_at} ELSE {$alias}.due_at END";
                } else {
                    $projected_due = "{$alias}.due_at";
                }
                $arms[] = "SELECT {$projected_due} AS due_at FROM (
        SELECT {$due} AS due_at
        FROM {$this->table}
        WHERE kind = '{$kind}' AND state = '{$state}'
        ORDER BY {$order}
        LIMIT 1
    ) {$alias}";
            }
        }
        $value = $this->get_var(
            ($recoverGuardedFences
                ? "/* wp_fts:only-guarded-fence-recovery */\n"
                : "/* wp_fts:nonfree-fence-watchdog */\n") . "SELECT MIN(due_at) FROM (
" . implode("\nUNION ALL\n", $arms) . "
) bounded_next_available",
            'read next available FTS work time'
        );

        return is_numeric($value) ? max(1, (int) $value) : null;
    }

    /**
     * Clear queued work unless an interrupted installation never created it.
     *
     * The table probe, rather than a database error message, distinguishes the
     * compatible missing-table state. Probe and DELETE failures remain visible.
     */
    public function clear_if_table_exists(): void
    {
        $table = $this->get_var($this->wpdb->prepare(
            'SHOW TABLES LIKE %s',
            $this->wpdb->esc_like($this->table)
        ), 'inspect the FTS indexing queue table');
        if (!is_scalar($table) || (string) $table !== $this->table) {
            return;
        }

        $this->clear();
    }

    /**
     * Clear all pending work while retaining the queue schema.
     */
    public function clear(): int
    {
        $this->query('START TRANSACTION', 'start FTS work reset transaction');
        try {
            $this->advance_search_epoch();
            $deleted = $this->query(
                "DELETE FROM {$this->table}
WHERE kind IN ('post','scope')
   OR (kind = 'meta' AND job_key <> '" . self::SEARCH_EPOCH_JOB_KEY . "')",
                'clear FTS indexing work'
            );
            $this->query('COMMIT', 'commit FTS work reset transaction');
            return $deleted;
        } catch (Throwable $error) {
            try {
                $this->query('ROLLBACK', 'roll back failed FTS work reset');
            } catch (Throwable) {
                // Preserve the reset failure.
            }
            throw $error;
        }
    }

    /**
     * Advance the durable cursor invalidation boundary by one cheap PK UPSERT.
     *
     * Callers that publish a visibility transition invoke this inside the same
     * transaction as that transition. Foreground enqueue/fence statements fold
     * the same row into their existing multi-row UPSERT instead.
     */
    public function advance_search_epoch(): int
    {
        return $this->query($this->wpdb->prepare(
            "INSERT INTO {$this->table}
    (job_key, kind, post_id, generation, state, available_at, attempts, claim_token, claimed_generation, claim_expires_at, cursor_post_id, scope_subject_type, scope_subject_id, payload, last_error_code, last_error_at)
VALUES (%s, 'meta', 0, 1, 'meta', 0, 0, '', 0, 0, 0, '', 0, %s, '', 0)
ON DUPLICATE KEY UPDATE
    generation = generation + 1,
    kind = 'meta', post_id = 0, state = 'meta', available_at = 0,
    attempts = 0, claim_token = '', claimed_generation = 0,
    claim_expires_at = 0, cursor_post_id = 0,
    scope_subject_type = '', scope_subject_id = 0,
    last_error_code = '', last_error_at = 0",
            self::SEARCH_EPOCH_JOB_KEY,
            $this->new_search_epoch_incarnation()
        ), 'advance FTS search epoch');
    }

    /**
     * @param array<string,mixed> $claim
     * @return array{job_key:string,post_id:int,generation:int,attempts:int,token:string}|null
     */
    private function normalize_claim(array $claim): ?array
    {
        if (count($claim) > 24) {
            return null;
        }
        foreach (['post_id', 'generation', 'attempts'] as $key) {
            if (
                array_key_exists($key, $claim)
                && (!is_scalar($claim[$key]) || (is_string($claim[$key]) && strlen($claim[$key]) > 64))
            ) {
                return null;
            }
        }
        foreach (['job_key' => 191, 'token' => 64] as $key => $maxBytes) {
            if (
                array_key_exists($key, $claim)
                && (!is_scalar($claim[$key]) || strlen((string) $claim[$key]) > $maxBytes)
            ) {
                return null;
            }
        }
        $post_id = max(0, (int) ($claim['post_id'] ?? 0));
        $job_key = isset($claim['job_key']) && is_scalar($claim['job_key'])
            ? (string) $claim['job_key']
            : $this->post_job_key($post_id);
        $generation = max(0, (int) ($claim['generation'] ?? 0));
        $token = isset($claim['token']) && is_scalar($claim['token']) ? (string) $claim['token'] : '';
        if ($post_id <= 0 || !self::is_post_job_key($job_key, $post_id) || $generation <= 0 || $token === '' || strlen($token) > 64) {
            return null;
        }

        return [
            'job_key' => $job_key,
            'post_id' => $post_id,
            'generation' => $generation,
            'attempts' => max(0, (int) ($claim['attempts'] ?? 0)),
            'token' => $token,
        ];
    }

    /**
     * @param array<int,array<string,mixed>> $claims
     * @return array<string,array{job_key:string,post_id:int,generation:int,attempts:int,token:string}>
     */
    private function normalize_claims(array $claims): array
    {
        $this->assert_bounded_claim_count($claims);
        $normalized = [];
        foreach ($claims as $claim) {
            if (!is_array($claim)) {
                continue;
            }
            $claim = $this->normalize_claim($claim);
            if ($claim !== null) {
                $normalized[$claim['job_key']] = $claim;
            }
        }

        return $normalized;
    }

    /**
     * Materialize at most 100 exact claim identities before a mutable-table join.
     *
     * @param array<int|string,array{job_key:string,generation:int,token:string}> $claims
     * @return array{sql:string,args:array<int,mixed>}
     */
    private function claim_identity_relation(
        array $claims,
        ?int $timestamp = null,
        string $timestampAlias = ''
    ): array {
        $rows = [];
        $args = [];
        foreach (array_values($claims) as $offset => $claim) {
            $aliases = $offset === 0
                ? ' AS job_key, %s AS claim_token, %d AS claimed_generation, %d AS generation'
                : ', %s, %d, %d';
            $rows[] = 'SELECT %s' . $aliases;
            array_push(
                $args,
                $claim['job_key'],
                $claim['token'],
                $claim['generation'],
                $claim['generation']
            );
        }
        if ($rows === []) {
            throw new LogicException('A bounded claim identity relation requires at least one claim.');
        }
        $limit = count($rows);
        $inner = implode("\nUNION ALL\n", $rows);
        if ($timestamp !== null) {
            if (!in_array($timestampAlias, ['failure_at', 'released_at'], true)) {
                throw new LogicException('Invalid bounded claim timestamp alias.');
            }
            $sql = "SELECT %d AS {$timestampAlias}, bounded_claims.*
FROM ({$inner}) bounded_claims
LIMIT {$limit}";
            array_unshift($args, $timestamp);
        } else {
            $sql = "SELECT bounded_claims.*
FROM ({$inner}) bounded_claims
LIMIT {$limit}";
        }

        return ['sql' => $sql, 'args' => $args];
    }

    /** Reject caller-sized OR predicates before normalizing the claims. */
    private function assert_bounded_claim_count(array $claims): void
    {
        if (count($claims) > self::MAX_CLAIM_POSTS) {
            throw new InvalidArgumentException('FTS work claim batches may contain at most 100 posts.');
        }
    }

    /**
     * Reject a recursive or object-bearing payload before JSON walks it.
     *
     * @param array<string|int,mixed> $payload
     */
    private function assert_bounded_payload(array $payload): void
    {
        $nodes = 0;
        $bytes = 0;
        $visit = function (mixed $value, int $depth) use (&$visit, &$nodes, &$bytes): void {
            if (++$nodes > self::MAX_PAYLOAD_NODES) {
                throw new InvalidArgumentException('FTS work payload exceeds 256 structured values.');
            }
            if (is_array($value)) {
                if ($depth >= self::MAX_PAYLOAD_DEPTH) {
                    throw new InvalidArgumentException('FTS work payload exceeds eight nesting levels.');
                }
                if (count($value) > self::MAX_PAYLOAD_NODES) {
                    throw new InvalidArgumentException('FTS work payload exceeds 256 structured values.');
                }
                foreach ($value as $key => $child) {
                    $bytes += strlen((string) $key);
                    if ($bytes > self::MAX_PAYLOAD_BYTES) {
                        throw new InvalidArgumentException('FTS work payload exceeds 8192 bytes.');
                    }
                    $visit($child, $depth + 1);
                }
                return;
            }
            if (!is_scalar($value) && $value !== null) {
                throw new InvalidArgumentException('FTS work payloads may contain only arrays and scalar values.');
            }
            if (is_string($value)) {
                $bytes += strlen($value);
                if ($bytes > self::MAX_PAYLOAD_BYTES) {
                    throw new InvalidArgumentException('FTS work payload exceeds 8192 bytes.');
                }
            }
        };
        $visit($payload, 0);
    }

    /**
     * @param array<string,mixed> $claim
     * @return array{job_key:string,generation:int,token:string,scope_coverage:string,scope_subject_type:string,scope_subject_id:int,scope_incarnation:string}|null
     */
    private function normalize_scope_claim(array $claim): ?array
    {
        if (count($claim) > 24) {
            return null;
        }
        if (
            (array_key_exists('job_key', $claim) && (!is_scalar($claim['job_key']) || strlen((string) $claim['job_key']) > 191))
            || (array_key_exists('generation', $claim) && (!is_scalar($claim['generation']) || (is_string($claim['generation']) && strlen($claim['generation']) > 64)))
            || (array_key_exists('token', $claim) && (!is_scalar($claim['token']) || strlen((string) $claim['token']) > 64))
        ) {
            return null;
        }
        $job_key = isset($claim['job_key']) && is_scalar($claim['job_key']) ? (string) $claim['job_key'] : '';
        $generation = max(0, (int) ($claim['generation'] ?? 0));
        $token = isset($claim['token']) && is_scalar($claim['token']) ? (string) $claim['token'] : '';
        if (!str_starts_with($job_key, 'scope:') || strlen($job_key) > 191 || $generation <= 0 || $token === '' || strlen($token) > 64) {
            return null;
        }
        try {
            $scopeAuthority = $this->validated_scope_authority(
                is_scalar($claim['scope_coverage'] ?? null) ? (string) $claim['scope_coverage'] : '',
                is_scalar($claim['scope_subject_type'] ?? null) ? (string) $claim['scope_subject_type'] : '',
                is_scalar($claim['scope_subject_id'] ?? null) ? max(0, (int) $claim['scope_subject_id']) : 0,
                is_scalar($claim['scope_incarnation'] ?? null) ? (string) $claim['scope_incarnation'] : ''
            );
        } catch (InvalidArgumentException) {
            return null;
        }

        return [
            'job_key' => $job_key,
            'generation' => $generation,
            'token' => $token,
            'scope_coverage' => $scopeAuthority[0],
            'scope_subject_type' => $scopeAuthority[1],
            'scope_subject_id' => $scopeAuthority[2],
            'scope_incarnation' => $scopeAuthority[3],
        ];
    }

    /**
     * Release a claim after a newer generation superseded it.
     *
     * @param array{post_id:int,generation:int,token:string} $claim
     */
    private function release_superseded_claim(array $claim, int $now): int
    {
        return $this->query($this->wpdb->prepare(
            "UPDATE {$this->table}
SET state = 'ready',
    attempts = 0,
    available_at = %d,
    claim_token = '',
    claimed_generation = 0,
    claim_expires_at = 0
WHERE job_key = %s
  AND claim_token = %s
  AND claimed_generation = %d
  AND generation > %d",
            $now,
            $claim['job_key'],
            $claim['token'],
            $claim['generation'],
            $claim['generation']
        ), 'release superseded FTS indexing work');
    }

    /** Encode the public post id as the queue's exact direct-work identity. */
    private function post_job_key(int $post_id): string
    {
        return 'post:' . max(0, $post_id);
    }

    /** Hide an arbitrarily shaped scope identity behind one fixed-width key. */
    private function scope_job_key(string $scope_key): string
    {
        return 'scope:' . hash('sha256', $scope_key);
    }

    /** New singleton payload used only when the cursor epoch row does not yet exist. */
    private function new_search_epoch_incarnation(): string
    {
        return bin2hex(random_bytes(16));
    }

    /** Accept only the canonical exact-post identity. */
    public static function is_post_job_key(string $job_key, int $post_id): bool
    {
        return hash_equals('post:' . max(0, $post_id), $job_key);
    }

    /** Reject scope identities that cannot fit the bounded queue contract. */
    private function validated_scope_key(string $scope_key): string
    {
        if (strlen($scope_key) > 1024) {
            throw new InvalidArgumentException('FTS scope work keys may contain at most 1,024 bytes.');
        }
        $scope_key = trim($scope_key);
        if ($scope_key === '') {
            throw new InvalidArgumentException('FTS scope work requires a non-empty key.');
        }

        return $scope_key;
    }

    /** Encode non-authoritative scope hints only after structural containment. */
    private function encoded_scope_payload(array $payload): string
    {
        $this->assert_bounded_payload($payload);
        foreach (['scope_coverage', 'scope_incarnation', 'scope_subject_type', 'scope_subject_id'] as $reserved) {
            if (array_key_exists($reserved, $payload)) {
                throw new InvalidArgumentException(
                    'FTS scope authority must use durable queue columns, not payload hints.'
                );
            }
        }
        $encoded = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        if (strlen($encoded) > self::MAX_PAYLOAD_BYTES) {
            throw new InvalidArgumentException('FTS scope work payload exceeds 8192 bytes.');
        }

        return $encoded;
    }

    /** @return array{0:string,1:string,2:int,3:string} */
    private function validated_scope_authority(
        string $coverage,
        string $subjectType,
        int $subjectId,
        string $incarnation
    ): array {
        if (!in_array($coverage, [
            self::SCOPE_COVERAGE_CORPUS,
            self::SCOPE_COVERAGE_GLOBAL,
            self::SCOPE_COVERAGE_TARGETED,
            self::SCOPE_COVERAGE_FILTERED,
        ], true)) {
            throw new InvalidArgumentException('FTS scope coverage must be corpus, global, targeted, or filtered.');
        }
        if (strlen($subjectType) > 24 || $subjectId < 0) {
            throw new InvalidArgumentException('FTS scope subjects must use bounded durable values.');
        }
        if ($coverage === self::SCOPE_COVERAGE_TARGETED) {
            if ($subjectType !== 'term_taxonomy' || $subjectId <= 0) {
                throw new InvalidArgumentException('Targeted FTS scopes require one positive term-taxonomy subject.');
            }
        } elseif ($subjectType !== '' || $subjectId !== 0) {
            throw new InvalidArgumentException('Only targeted FTS scopes may carry a durable subject.');
        }

        if ($coverage === self::SCOPE_COVERAGE_CORPUS) {
            if (preg_match('/^[a-f0-9]{32}$/D', $incarnation) !== 1) {
                throw new InvalidArgumentException('Corpus FTS scopes require a bound readiness incarnation.');
            }
        } elseif ($incarnation !== '') {
            throw new InvalidArgumentException('Only corpus FTS scopes may carry a readiness incarnation.');
        }

        return [$coverage, $subjectType, $subjectId, $incarnation];
    }

    private function backoff_seconds(int $attempts): int
    {
        $power = max(0, min(20, $attempts - 1));

        return min(self::MAX_BACKOFF_SECONDS, self::BASE_BACKOFF_SECONDS * (2 ** $power));
    }

    private function timestamp(?int $value): int
    {
        return max(1, $value ?? time());
    }

    /** Use indexed state, not a token scan, as the worker eligibility source. */
    private function mutation_fence_state(string $mutation_token): string
    {
        return str_starts_with($mutation_token, 'guard:') ? 'guarded' : 'fenced';
    }

    /** @return array{0:int,1:int} Current time and bounded expiration. */
    private function lease_window(?int $now, int $lease_seconds): array
    {
        if ($lease_seconds < 1 || $lease_seconds > self::MAX_LEASE_SECONDS) {
            throw new InvalidArgumentException(
                'FTS claim leases must be between one and ' . self::MAX_LEASE_SECONDS . ' seconds.'
            );
        }
        $now = $this->timestamp($now);
        if ($now > PHP_INT_MAX - $lease_seconds) {
            throw new InvalidArgumentException('FTS claim lease expiration exceeds the platform integer range.');
        }

        return [$now, $now + $lease_seconds];
    }

    /** One deterministic inode must be shared by web, cron, and WP-CLI. */
    private function foreground_owner_guard_path(): string
    {
        $directory = '';
        $usePrivateSiteDirectory = false;
        $databaseIdentity = (defined('DB_HOST') && is_scalar(constant('DB_HOST'))
                ? (string) constant('DB_HOST')
                : '')
            . "\0"
            . (defined('DB_NAME') && is_scalar(constant('DB_NAME'))
                ? (string) constant('DB_NAME')
                : '');
        if (defined('WP_FTS_FOREGROUND_LOCK_DIR') && is_scalar(constant('WP_FTS_FOREGROUND_LOCK_DIR'))) {
            $directory = trim((string) constant('WP_FTS_FOREGROUND_LOCK_DIR'));
        }
        if ($this->is_sqlite_runtime()) {
            [$sqliteIdentity, $sqliteDirectory] = $this->sqlite_foreground_guard_identity();
            if ($sqliteIdentity !== '') {
                $databaseIdentity = $sqliteIdentity;
            }
            if ($directory === '' && $sqliteDirectory !== '') {
                $directory = $sqliteDirectory;
            }
        }
        if ($directory === '' && defined('WP_CONTENT_DIR') && is_scalar(constant('WP_CONTENT_DIR'))) {
            $directory = rtrim((string) constant('WP_CONTENT_DIR'), '/\\') . DIRECTORY_SEPARATOR . 'uploads';
            $usePrivateSiteDirectory = true;
        }
        if ($directory === '') {
            // Non-WordPress harnesses share one host temp directory. A real
            // WordPress runtime never chooses temp opportunistically.
            $directory = sys_get_temp_dir();
        }
        $isAbsoluteDirectory = str_starts_with($directory, '/')
            || str_starts_with($directory, '\\')
            || preg_match('/^[a-zA-Z]:[\\\\\/]/D', $directory) === 1;
        if (!$isAbsoluteDirectory && defined('ABSPATH') && is_scalar(constant('ABSPATH'))) {
            $directory = rtrim((string) constant('ABSPATH'), '/\\')
                . DIRECTORY_SEPARATOR . $directory;
        }
        $siteIdentity = substr(hash('sha256', $databaseIdentity . "\0" . $this->table), 0, 32);
        if ($usePrivateSiteDirectory) {
            // Do not create WordPress's public uploads parent with the private
            // guard mode. On a fresh site that would make media inaccessible
            // to the web server or a distinct cron/CLI SAPI. WordPress's helper
            // preserves the parent convention; the fallback requests the
            // ordinary shared-content mode subject to the host umask.
            if (!is_dir($directory)) {
                if (function_exists('wp_mkdir_p')) {
                    @wp_mkdir_p($directory);
                } else {
                    @mkdir($directory, 0775, true);
                }
            }
            $directory = rtrim($directory, '/\\') . DIRECTORY_SEPARATOR
                . '.wp-fts-runtime-' . $siteIdentity;
        }
        if (!is_dir($directory)) {
            @mkdir($directory, $usePrivateSiteDirectory ? 0700 : 0770, !$usePrivateSiteDirectory);
        }
        if ($usePrivateSiteDirectory) {
            clearstatcache(true, $directory);
            $privateDirectoryStat = @lstat($directory);
            if (
                !is_array($privateDirectoryStat)
                || (($privateDirectoryStat['mode'] ?? 0) & 0170000) !== 0040000
                || ((int) ($privateDirectoryStat['mode'] ?? 0) & 0077) !== 0
            ) {
                throw new RuntimeException('The FTS foreground owner guard runtime directory is not private.');
            }
        }
        $canonicalDirectory = realpath($directory);
        if (is_string($canonicalDirectory) && $canonicalDirectory !== '') {
            $directory = $canonicalDirectory;
        }
        return rtrim($directory, '/\\') . DIRECTORY_SEPARATOR . '.wp-fts-foreground-'
            . $siteIdentity . '.lock';
    }

    /** @return array{0:string,1:string} Stable SQLite identity and adjacent directory. */
    private function sqlite_foreground_guard_identity(): array
    {
        $databaseFile = '';
        $databaseDirectory = '';
        if (defined('FQDB') && is_scalar(constant('FQDB'))) {
            $databaseFile = trim((string) constant('FQDB'));
        } elseif (defined('DB_FILE') && is_scalar(constant('DB_FILE'))) {
            $databaseFile = trim((string) constant('DB_FILE'));
            foreach (['DB_DIR', 'FQDBDIR'] as $constant) {
                if (defined($constant) && is_scalar(constant($constant))) {
                    $databaseDirectory = trim((string) constant($constant));
                    if ($databaseDirectory !== '') {
                        break;
                    }
                }
            }
        }
        if ($databaseFile === '' || str_contains($databaseFile, "\0")) {
            return ['', ''];
        }
        if (str_starts_with($databaseFile, 'sqlite:')) {
            $databaseFile = substr($databaseFile, strlen('sqlite:'));
        }
        if ($databaseFile === ':memory:') {
            return ['sqlite::memory:', ''];
        }

        $isAbsolute = str_starts_with($databaseFile, '/')
            || str_starts_with($databaseFile, '\\')
            || preg_match('/^[a-zA-Z]:[\\\\\/]/D', $databaseFile) === 1;
        if (!$isAbsolute) {
            if ($databaseDirectory === '' && defined('ABSPATH') && is_scalar(constant('ABSPATH'))) {
                $databaseDirectory = trim((string) constant('ABSPATH'));
            }
            if ($databaseDirectory === '' && defined('WP_CONTENT_DIR') && is_scalar(constant('WP_CONTENT_DIR'))) {
                $databaseDirectory = trim((string) constant('WP_CONTENT_DIR'));
            }
            if ($databaseDirectory === '') {
                return ['', ''];
            }
            $databaseFile = rtrim($databaseDirectory, '/\\')
                . DIRECTORY_SEPARATOR . $databaseFile;
        }
        $canonicalFile = realpath($databaseFile);
        if (is_string($canonicalFile) && $canonicalFile !== '') {
            $databaseFile = $canonicalFile;
        } else {
            $canonicalDirectory = realpath(dirname($databaseFile));
            if (is_string($canonicalDirectory) && $canonicalDirectory !== '') {
                $databaseFile = rtrim($canonicalDirectory, '/\\')
                    . DIRECTORY_SEPARATOR . basename($databaseFile);
            }
        }

        return ['sqlite:' . $databaseFile, dirname($databaseFile)];
    }

    /** @return resource|null */
    private function open_foreground_owner_guard(string $path): mixed
    {
        $handle = @fopen($path, 'c+');
        if (!is_resource($handle)) {
            $handle = @fopen($path, 'r');
        }
        if (!is_resource($handle)) {
            return null;
        }
        if (!$this->foreground_owner_guard_handle_matches_path($handle, $path)) {
            @fclose($handle);
            return null;
        }
        return $handle;
    }

    /** Reject symbolic, hard-linked, or path-replaced lock descriptors. */
    private function foreground_owner_guard_handle_matches_path(mixed $handle, string $path): bool
    {
        clearstatcache(true, $path);
        $pathStat = @lstat($path);
        $handleStat = is_resource($handle) ? @fstat($handle) : false;

        return is_array($pathStat)
            && is_array($handleStat)
            && (($pathStat['mode'] ?? 0) & 0170000) === 0100000
            && ($pathStat['dev'] ?? null) === ($handleStat['dev'] ?? null)
            && ($pathStat['ino'] ?? null) === ($handleStat['ino'] ?? null)
            && (int) ($handleStat['nlink'] ?? 0) === 1;
    }

    /**
     * Acquire the worker side of the guard without waiting.
     *
     * @return array{state:'free'|'busy'|'unavailable',handle:resource|null}
     */
    private function begin_foreground_fence_claim(): array
    {
        try {
            $path = $this->foreground_owner_guard_path();
        } catch (Throwable) {
            $this->foregroundOwnerGuardProbeState = 'unavailable';
            return ['state' => 'unavailable', 'handle' => null];
        }
        if (
            (self::$foregroundOwnerGuardPaths[$path] ?? 0) > 0
            || (self::$foregroundOwnerExclusiveGuardPaths[$path] ?? 0) > 0
        ) {
            $this->foregroundOwnerGuardProbeState = 'busy';
            return ['state' => 'busy', 'handle' => null];
        }
        $handle = $this->open_foreground_owner_guard($path);
        if (!is_resource($handle)) {
            $this->foregroundOwnerGuardProbeState = 'unavailable';
            return ['state' => 'unavailable', 'handle' => null];
        }
        $wouldBlock = 0;
        if (@flock($handle, LOCK_EX | LOCK_NB, $wouldBlock)) {
            if (!$this->foreground_owner_guard_handle_matches_path($handle, $path)) {
                @flock($handle, LOCK_UN);
                @fclose($handle);
                $this->foregroundOwnerGuardProbeState = 'unavailable';
                return ['state' => 'unavailable', 'handle' => null];
            }
            $this->foregroundOwnerGuardProbeState = 'free';
            return ['state' => 'free', 'handle' => $handle];
        }
        @fclose($handle);

        $this->foregroundOwnerGuardProbeState = $wouldBlock === 1 ? 'busy' : 'unavailable';

        return ['state' => $this->foregroundOwnerGuardProbeState, 'handle' => null];
    }

    /** @param array{state:string,handle:mixed} $claimGuard */
    private function end_foreground_fence_claim(array $claimGuard): void
    {
        $handle = $claimGuard['handle'] ?? null;
        if (!is_resource($handle)) {
            return;
        }
        @flock($handle, LOCK_UN);
        @fclose($handle);
    }

    /** Revalidate an initially free observation after its bounded claim write. */
    private function foreground_fence_claim_remains_free(bool $required): bool
    {
        if (!$required) {
            return true;
        }
        $claimGuard = $this->begin_foreground_fence_claim();
        $isFree = $claimGuard['state'] === 'free';
        $this->end_foreground_fence_claim($claimGuard);

        return $isFree;
    }

    /**
     * Conservatively restore claim ownership when a request starts mid-claim.
     *
     * Only rows still owned at the exact claimed generation are changed. A
     * concurrent foreground fence has already advanced the generation and is
     * therefore left intact. `available_at` stays due so the next worker whose
     * exclusive probe succeeds can recover this synthetic fence immediately.
     */
    private function refence_interrupted_claim(string $claimToken): void
    {
        $guardToken = 'guard:' . bin2hex(random_bytes(16));
        $this->query($this->wpdb->prepare(
            "UPDATE /* wp_fts:refence-interrupted-claim */ {$this->table}
SET state = 'guarded', claim_token = %s,
    claimed_generation = generation, claim_expires_at = 0
WHERE state = 'leased' AND claim_token = %s
  AND claimed_generation = generation",
            $guardToken,
            $claimToken
        ), 'refence interrupted FTS claim');
    }

    /** SQLite has no multi-table UPDATE; its bounded IN form remains indexed. */
    private function is_sqlite_runtime(): bool
    {
        if ($this->sqliteRuntime !== null) {
            return $this->sqliteRuntime;
        }
        $signals = [get_class($this->wpdb)];
        if (isset($this->wpdb->dbh) && is_object($this->wpdb->dbh)) {
            $signals[] = get_class($this->wpdb->dbh);
        }
        foreach (['SQLITE_MAIN_FILE', 'SQLITE_PLUGIN', 'SQLITE_DB_DROPIN_VERSION', 'DB_ENGINE'] as $constant) {
            if (defined($constant)) {
                $signals[] = (string) constant($constant);
            }
        }
        foreach ($signals as $signal) {
            if (stripos($signal, 'sqlite') !== false) {
                return $this->sqliteRuntime = true;
            }
        }

        return $this->sqliteRuntime = false;
    }

    /**
     * Run a write query with explicit failure visibility and return affected rows.
     */
    private function query(mixed $statement, string $context): int
    {
        $result = $this->wpdb->query($statement);
        if ($result === false) {
            throw $this->database_exception($context);
        }
        $this->assert_no_database_error($context);

        return is_int($result) ? max(0, $result) : 0;
    }

    /**
     * @return object[]
     */
    private function get_results(mixed $statement, string $context): array
    {
        $rows = $this->wpdb->get_results($statement);
        $this->assert_no_database_error($context);

        return is_array($rows) ? $rows : [];
    }

    private function get_var(mixed $statement, string $context): mixed
    {
        $value = $this->wpdb->get_var($statement);
        $this->assert_no_database_error($context);

        return $value;
    }

    private function assert_no_database_error(string $context): void
    {
        if (isset($this->wpdb->last_error) && trim((string) $this->wpdb->last_error) !== '') {
            throw $this->database_exception($context);
        }
    }

    private function database_exception(string $context): RuntimeException
    {
        $error = isset($this->wpdb->last_error) ? trim((string) $this->wpdb->last_error) : '';
        $suffix = $error !== '' ? ": {$error}" : '.';

        return new RuntimeException("Failed to {$context}{$suffix}");
    }
}
