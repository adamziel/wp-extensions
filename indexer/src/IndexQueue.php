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
    private const CLAIM_KEYS = [
        'job_key',
        'kind',
        'post_id',
        'generation',
        'attempts',
        'last_error_code',
        'token',
        'claim_expires_at',
        'cursor_post_id',
        'scope_coverage',
        'scope_subject_type',
        'scope_subject_id',
        'scope_incarnation',
        'payload',
        'source_exists',
        'source_bytes',
        'canonical_bytes',
        'source_snapshot',
    ];

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

    /** @var array<int,string> Shared guard resource IDs owned by this process. */
    private static array $foregroundOwnerGuardHandles = [];

    /** @var array<int,string> Exclusive guard resource IDs owned by this process. */
    private static array $foregroundOwnerExclusiveGuardHandles = [];

    /** Bind queue, posts, and document tables to one WordPress site prefix. */
    public function __construct(object $wpdb, ?string $prefix = null)
    {
        $this->wpdb = $wpdb;
        $adapterFields = get_object_vars($wpdb);
        $prefixWasExplicit = $prefix !== null;
        if ($prefix === null) {
            if (!array_key_exists('prefix', $adapterFields) || !is_string($adapterFields['prefix'])) {
                throw new UnexpectedValueException('The FTS database adapter must expose a native table prefix.');
            }
            $prefix = $adapterFields['prefix'];
        }
        $this->table = $prefix . 'fts_work';
        if (!$prefixWasExplicit && array_key_exists('posts', $adapterFields)) {
            if (!is_string($adapterFields['posts'])) {
                throw new UnexpectedValueException('The FTS database adapter must expose a native posts table name.');
            }
            $this->postsTable = $adapterFields['posts'];
        } else {
            $this->postsTable = $prefix . 'posts';
        }
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
        self::$foregroundOwnerGuardHandles[get_resource_id($handle)] = $path;

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
        self::$foregroundOwnerExclusiveGuardHandles[get_resource_id($handle)] = $path;

        return ['kind' => 'flock-exclusive', 'path' => $path, 'handle' => $handle];
    }

    /**
     * Release exactly the request guard acquired above.
     *
     * @param array<string,mixed> $guard
     */
    public function release_foreground_owner_guard(array $guard): void
    {
        if (array_keys($guard) !== ['kind', 'path', 'handle']) {
            throw new InvalidArgumentException('Invalid FTS foreground owner guard.');
        }
        $path = $guard['path'];
        $handle = $guard['handle'];
        if (
            $guard['kind'] !== 'flock'
            || !is_string($path)
            || !is_resource($handle)
            || (self::$foregroundOwnerGuardPaths[$path] ?? 0) <= 0
            || (self::$foregroundOwnerGuardHandles[get_resource_id($handle)] ?? null) !== $path
        ) {
            throw new InvalidArgumentException('Invalid FTS foreground owner guard.');
        }
        $handle_id = get_resource_id($handle);
        $matches_path = $this->foreground_owner_guard_handle_matches_path($handle, $path);
        $unlocked = @flock($handle, LOCK_UN);
        $closed = @fclose($handle);
        unset(self::$foregroundOwnerGuardHandles[$handle_id]);
        $remaining = self::$foregroundOwnerGuardPaths[$path] - 1;
        if ($remaining === 0) {
            unset(self::$foregroundOwnerGuardPaths[$path]);
        } else {
            self::$foregroundOwnerGuardPaths[$path] = $remaining;
        }
        if (!$matches_path) {
            throw new InvalidArgumentException('Invalid FTS foreground owner guard.');
        }
        if (!$unlocked || !$closed) {
            throw new RuntimeException('Failed to release the FTS foreground owner guard.');
        }
    }

    /** @param array<string,mixed> $guard */
    public function release_exclusive_foreground_owner_guard(array $guard): void
    {
        if (array_keys($guard) !== ['kind', 'path', 'handle']) {
            throw new InvalidArgumentException('Invalid exclusive FTS foreground owner guard.');
        }
        $path = $guard['path'];
        $handle = $guard['handle'];
        if (
            $guard['kind'] !== 'flock-exclusive'
            || !is_string($path)
            || !is_resource($handle)
            || (self::$foregroundOwnerExclusiveGuardPaths[$path] ?? 0) <= 0
            || (self::$foregroundOwnerExclusiveGuardHandles[get_resource_id($handle)] ?? null) !== $path
        ) {
            throw new InvalidArgumentException('Invalid exclusive FTS foreground owner guard.');
        }
        $handle_id = get_resource_id($handle);
        $matches_path = $this->foreground_owner_guard_handle_matches_path($handle, $path);
        $unlocked = @flock($handle, LOCK_UN);
        $closed = @fclose($handle);
        unset(self::$foregroundOwnerExclusiveGuardHandles[$handle_id]);
        $remaining = self::$foregroundOwnerExclusiveGuardPaths[$path] - 1;
        if ($remaining === 0) {
            unset(self::$foregroundOwnerExclusiveGuardPaths[$path]);
        } else {
            self::$foregroundOwnerExclusiveGuardPaths[$path] = $remaining;
        }
        if (!$matches_path) {
            throw new InvalidArgumentException('Invalid exclusive FTS foreground owner guard.');
        }
        if (!$unlocked || !$closed) {
            throw new RuntimeException('Failed to release the exclusive FTS foreground owner guard.');
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
            && (self::$foregroundOwnerGuardHandles[get_resource_id($handle)] ?? null) === $path
            && $this->foreground_owner_guard_handle_matches_path($handle, $path);
    }

    /** Last worker probe result, for fail-closed health diagnostics. */
    public function foreground_owner_guard_probe_state(): string
    {
        return $this->foregroundOwnerGuardProbeState;
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
        $ids = $this->unique_positive_post_ids($post_ids, 'FTS queue');
        $now = $this->timestamp($now);
        $this->assert_bounded_payload($payload);
        $encodedPayload = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        if (strlen($encodedPayload) > self::MAX_PAYLOAD_BYTES) {
            throw new InvalidArgumentException('FTS post work payload exceeds 8192 bytes.');
        }
        if ($ids === []) {
            return 0;
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

        $affected = $this->query($this->wpdb->prepare(
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
        $this->bounded_affected_rows($affected, 2 * (count($ids) + 1), 'FTS indexing enqueue');

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
        if ($post_id <= 0) {
            throw new InvalidArgumentException('FTS post mutation fences require a positive post id and bounded token.');
        }
        $mutation_token = $this->validated_mutation_token($mutation_token);
        $this->assert_bounded_payload($payload);
        $encoded = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        if (strlen($encoded) > self::MAX_PAYLOAD_BYTES) {
            throw new InvalidArgumentException('FTS post work payload exceeds 8192 bytes.');
        }

        $job_key = $this->post_job_key($post_id);
        $epoch_incarnation = $this->new_search_epoch_incarnation();
        $fence_state = $this->mutation_fence_state($mutation_token);
        // Leave an unclaimable row until the matching post hook promotes it.
        $affected = $this->query($this->wpdb->prepare(
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
        $this->bounded_affected_rows($affected, 4, 'FTS post mutation fence');
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
        if ($post_id <= 0) {
            throw new InvalidArgumentException('FTS post mutation promotion requires a positive post id and bounded token.');
        }
        $mutation_token = $this->validated_mutation_token($mutation_token);
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
        $affected = $this->query($this->wpdb->prepare(
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
        $this->bounded_affected_rows($affected, 4, 'FTS post mutation promotion');
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

        $affected = $this->query($this->wpdb->prepare(
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
        $this->bounded_affected_rows($affected, 4, 'FTS scope enqueue');
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
        if (!$this->is_lower_hex($scope_incarnation, 32) || !$this->is_lower_hex($profile_hash, 40)) {
            throw new InvalidArgumentException('FTS corpus scope matching requires exact lowercase capability hashes.');
        }
        $job_key = $this->scope_job_key($this->validated_scope_key($scope_key));
        $rows = $this->get_results($this->wpdb->prepare(
            "SELECT scope_incarnation, payload
FROM {$this->table}
WHERE job_key = %s AND kind = 'scope' AND scope_coverage = 'corpus'
LIMIT 1",
            $job_key
        ), 'confirm exact FTS profile scope');
        if ($rows === []) {
            return false;
        }
        if (count($rows) !== 1) {
            throw new UnexpectedValueException('The FTS corpus scope query returned invalid cardinality.');
        }
        $row = $rows[0];
        if (array_keys(get_object_vars($row)) !== ['scope_incarnation', 'payload']) {
            throw new UnexpectedValueException('The FTS corpus scope row has invalid aliases.');
        }
        if (!is_string($row->scope_incarnation) || !is_string($row->payload)) {
            throw new UnexpectedValueException('The FTS corpus scope row has invalid native types.');
        }
        if (!$this->is_lower_hex($row->scope_incarnation, 32)) {
            throw new UnexpectedValueException('The FTS corpus scope row has an invalid incarnation.');
        }
        if (!hash_equals($scope_incarnation, $row->scope_incarnation)) {
            return false;
        }
        if (strlen($row->payload) > self::MAX_PAYLOAD_BYTES) {
            throw new UnexpectedValueException('The FTS corpus scope payload exceeds its database contract.');
        }
        try {
            $payload = json_decode($row->payload, true, flags: JSON_THROW_ON_ERROR);
            if (!is_array($payload)) {
                throw new UnexpectedValueException('The FTS corpus scope payload is not an array.');
            }
            $this->assert_bounded_payload($payload);
        } catch (JsonException|InvalidArgumentException $error) {
            throw new UnexpectedValueException('The FTS corpus scope payload is malformed.', 0, $error);
        }
        $stored_profile = $payload['profile_hash'] ?? null;
        if (!is_string($stored_profile) || !$this->is_lower_hex($stored_profile, 40)) {
            throw new UnexpectedValueException('The FTS corpus scope payload has an invalid profile hash.');
        }

        return hash_equals($profile_hash, $stored_profile);
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
        $mutation_token = $this->validated_mutation_token($mutation_token);
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
        $affected = $this->query($this->wpdb->prepare(
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
        $this->bounded_affected_rows($affected, 4, 'FTS scope mutation fence');
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
        $mutation_token = $this->validated_mutation_token($mutation_token);
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
        $affected = $this->query($this->wpdb->prepare(
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
        $this->bounded_affected_rows($affected, 4, 'FTS scope mutation promotion');
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
        $mutation_token = $this->validated_mutation_token($mutation_token);
        if (count($owned_post_tokens) > self::MAX_ENQUEUE_POSTS) {
            throw new InvalidArgumentException(
                'FTS foreground mutation handoff exceeds the ' . self::MAX_ENQUEUE_POSTS . '-post contract.'
            );
        }

        $ids = $this->unique_positive_post_ids($post_ids, 'FTS foreground mutation handoff');

        $tokens = [];
        foreach ($owned_post_tokens as $post_id => $token) {
            if (
                !is_int($post_id)
                || $post_id <= 0
                || !isset($ids[$post_id])
                || !is_string($token)
            ) {
                throw new InvalidArgumentException('FTS foreground mutation post tokens must identify retained posts.');
            }
            $tokens[$post_id] = $this->validated_mutation_token($token);
        }

        if (count($owned_scope_tokens) > self::MAX_FOREGROUND_DIRECT_SCOPES) {
            throw new InvalidArgumentException('FTS foreground mutation handoff accepts at most one direct scope.');
        }
        $scopeTokens = [];
        foreach ($owned_scope_tokens as $owned_scope_key => $token) {
            if (
                !is_string($owned_scope_key)
                || !is_string($token)
            ) {
                throw new InvalidArgumentException('FTS foreground mutation scope tokens must be bounded strings.');
            }
            $owned_scope_key = $this->validated_scope_key($owned_scope_key);
            $scopeTokens[$owned_scope_key] = $this->validated_mutation_token($token);
        }

        $scope_key = $this->validated_scope_key($scope_key);
        $globalScopeReleased = false;
        if ($overflow) {
            $this->encoded_scope_payload($scope_payload);
            $this->validated_scope_authority(
                self::SCOPE_COVERAGE_CORPUS,
                '',
                0,
                $scope_incarnation
            );
        } elseif ($scope_payload !== [] || $scope_incarnation !== '') {
            throw new InvalidArgumentException('Exact foreground handoff cannot carry scope payload or corpus authority.');
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

        $affected = $this->query($this->wpdb->prepare(
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
        $this->bounded_affected_rows(
            $affected,
            2 * (count($ids) + 1),
            'exact FTS foreground post handoff'
        );
    }

    /** Delete the exact request sentinel after all replacement post rows exist. */
    private function delete_foreground_global_scope(string $scope_key, string $mutation_token): bool
    {
        $jobKey = $this->scope_job_key($scope_key);
        $args = [$jobKey, $mutation_token];
        $deleted = $this->query($this->wpdb->prepare(
            "DELETE FROM {$this->table}
WHERE job_key = %s AND kind = 'scope' AND state IN ('fenced','guarded') AND claim_token = %s
/* wp_fts:foreground-global-delete */",
                ...$args
            ), 'delete FTS foreground global scope');

        return $this->bounded_affected_rows($deleted, 1, 'foreground global scope delete') === 1;
    }

    /** Delete one claimed visibility sentinel after canonical replacement exists. */
    public function discard_replaced_scope(array $claim): bool
    {
        $claim = $this->normalize_scope_claim($claim);

        $deleted = $this->query($this->wpdb->prepare(
            "DELETE FROM {$this->table}
WHERE job_key = %s AND claim_token = %s
  AND claimed_generation = %d AND generation = %d
/* wp_fts:replaced-scope-delete */",
            $claim['job_key'],
            $claim['token'],
            $claim['generation'],
            $claim['generation']
        ), 'discard replaced FTS scope');

        return $this->bounded_affected_rows($deleted, 1, 'replaced FTS scope delete') === 1;
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

        return $this->database_presence_value($value, 'global FTS visibility scope');
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
        $deleted = $this->query($this->wpdb->prepare(
            "DELETE FROM {$this->table}
WHERE job_key = %s AND claim_token = %s
  AND kind = 'scope' AND state IN ('fenced','guarded')
/* wp_fts:foreground-owned-scope-delete */",
            $this->scope_job_key($scopeKey),
            $token
        ), 'discard covered FTS foreground scope generation');
        $this->bounded_affected_rows($deleted, 1, 'covered FTS foreground scope discard');
    }

    /**
     * Make a bounded set of failed generations available in one statement.
     *
     * Explicit recovery advances each fencing generation and clears any old
     * lease. A worker holding the pre-retry generation therefore cannot
     * acknowledge and delete the operator's retry.
     *
     * @param int[] $post_ids
     * @return int Number of unique positive post ids accepted.
     */
    public function retry_many(array $post_ids, ?int $now = null): int
    {
        $ids = $this->unique_positive_post_ids($post_ids, 'FTS retry');
        $available_at = $this->timestamp($now);
        if ($ids === []) {
            return 0;
        }

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
        $affected = $this->query($this->wpdb->prepare(
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
        $this->bounded_affected_rows($affected, 2 * (count($ids) + 1), 'FTS indexing retry');

        return count($ids);
    }

    /**
     * Claim at most one scope generation and one bounded direct-post batch.
     *
     * A single token lets workers discover both work kinds with one atomic
     * UPDATE and one indexed confirmation read.
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
        if ($post_limit < 0 || $post_limit > self::MAX_CLAIM_POSTS) {
            throw new InvalidArgumentException('FTS work claim size must be between zero and 100 posts.');
        }
        if ($source_snapshot_limit < 0 || $source_snapshot_limit > self::MAX_SOURCE_SNAPSHOT_BYTES) {
            throw new InvalidArgumentException(
                'FTS source snapshots must be between zero and ' . self::MAX_SOURCE_SNAPSHOT_BYTES . ' bytes.'
            );
        }
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
      OR (state IN ('ready','retry') AND available_at <= %d)
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
      OR (claim_target.state IN ('ready','retry') AND claim_target.available_at <= %d)
  )";
            $claimArgs = [$token, $lease_expires_at, ...$choiceArgs, $now, $now];
            if ($recoverGuardedFences) {
                $claimArgs[] = $now;
            }
            $claimArgs[] = $now;
        }
        $claimed_count = $this->query(
            $this->wpdb->prepare($claimSql, ...$claimArgs),
            'claim FTS work batch'
        );
        $claimed_count = $this->bounded_affected_rows(
            $claimed_count,
            $post_limit + 1,
            'FTS work batch claim'
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
        if ($claimed_count > self::MAX_CLAIM_POSTS + 1 || count($rows) > $claimed_count) {
            throw $this->malformed_claim_row('the confirmation row count exceeds the claim write');
        }

        $claims = [];
        $claimed_job_keys = [];
        $batch_snapshot_complete = null;
        foreach ($rows as $row) {
            if (!is_object($row)) {
                throw $this->malformed_claim_row('the database returned a non-object row');
            }
            [$claim, $source_snapshot_complete] = $this->decode_claim_row(
                $row,
                $token,
                $lease_expires_at
            );
            if (isset($claimed_job_keys[$claim['job_key']])) {
                throw $this->malformed_claim_row('the confirmation query returned a duplicate job key');
            }
            $claimed_job_keys[$claim['job_key']] = true;
            if ($batch_snapshot_complete !== null && $batch_snapshot_complete !== $source_snapshot_complete) {
                throw $this->malformed_claim_row('the confirmation query returned inconsistent snapshot state');
            }
            $batch_snapshot_complete = $source_snapshot_complete;
            $claims[] = $claim;
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
     * Decode one claim confirmation row without repairing database state in PHP.
     *
     * @return array{0:array<string,mixed>,1:bool}
     */
    private function decode_claim_row(object $row, string $token, int $lease_expires_at): array
    {
        $expected_aliases = [
            'job_key',
            'kind',
            'post_id',
            'generation',
            'attempts',
            'last_error_code',
            'claim_expires_at',
            'cursor_post_id',
            'scope_coverage',
            'scope_incarnation',
            'scope_subject_type',
            'scope_subject_id',
            'payload',
            'source_exists',
            'source_bytes',
            'canonical_bytes',
            'source_snapshot_complete',
            'source_id',
            'source_post_title',
            'source_post_content',
            'source_post_excerpt',
            'source_post_type',
            'source_post_status',
            'source_post_date_gmt',
            'source_post_password',
            'source_existing_hash',
        ];
        $actual_aliases = array_keys(get_object_vars($row));
        if ($actual_aliases !== $expected_aliases) {
            throw $this->malformed_claim_row('the selected aliases do not match the claim contract');
        }

        $job_key = $this->claim_row_text($row, 'job_key');
        $kind = $this->claim_row_text($row, 'kind');
        $post_id = $this->claim_row_integer($row, 'post_id');
        $generation = $this->claim_row_integer($row, 'generation');
        $attempts = $this->claim_row_integer($row, 'attempts');
        $last_error_code = $this->claim_row_text($row, 'last_error_code');
        $claim_expires_at = $this->claim_row_integer($row, 'claim_expires_at');
        $cursor_post_id = $this->claim_row_integer($row, 'cursor_post_id');
        $scope_coverage = $this->claim_row_text($row, 'scope_coverage');
        $scope_incarnation = $this->claim_row_text($row, 'scope_incarnation');
        $scope_subject_type = $this->claim_row_text($row, 'scope_subject_type');
        $scope_subject_id = $this->claim_row_integer($row, 'scope_subject_id');
        if ($generation === 0 || $claim_expires_at !== $lease_expires_at) {
            throw $this->malformed_claim_row('the generation or lease boundary is invalid');
        }
        if (strlen($last_error_code) > 64) {
            throw $this->malformed_claim_row('the last error code exceeds its database column');
        }

        if ($kind === 'post') {
            if (
                $post_id === 0
                || !self::is_post_job_key($job_key, $post_id)
                || $cursor_post_id !== 0
                || $scope_coverage !== ''
                || $scope_incarnation !== ''
                || $scope_subject_type !== ''
                || $scope_subject_id !== 0
            ) {
                throw $this->malformed_claim_row('a post claim has an invalid durable identity');
            }
            $scope_authority = ['', '', 0, ''];
        } elseif ($kind === 'scope') {
            if (
                $post_id !== 0
                || strlen($job_key) !== 70
                || !str_starts_with($job_key, 'scope:')
                || strspn(substr($job_key, 6), '0123456789abcdef') !== 64
            ) {
                throw $this->malformed_claim_row('a scope claim has an invalid durable identity');
            }
            try {
                $scope_authority = $this->validated_scope_authority(
                    $scope_coverage,
                    $scope_subject_type,
                    $scope_subject_id,
                    $scope_incarnation
                );
            } catch (InvalidArgumentException $error) {
                throw $this->malformed_claim_row('a scope claim has invalid durable authority', $error);
            }
        } else {
            throw $this->malformed_claim_row('the kind alias is not post or scope');
        }

        $encoded_payload = $this->claim_row_text($row, 'payload');
        if (strlen($encoded_payload) > self::MAX_PAYLOAD_BYTES) {
            throw $this->malformed_claim_row('the payload exceeds its transport bound');
        }
        try {
            $payload = json_decode($encoded_payload, true, flags: JSON_THROW_ON_ERROR);
            if (!is_array($payload)) {
                throw $this->malformed_claim_row('the payload is not an array');
            }
            $this->assert_bounded_payload($payload);
        } catch (JsonException|InvalidArgumentException $error) {
            throw $this->malformed_claim_row('the payload is not valid bounded JSON', $error);
        }
        if ($kind === 'scope') {
            foreach (['scope_coverage', 'scope_incarnation', 'scope_subject_type', 'scope_subject_id'] as $reserved) {
                if (array_key_exists($reserved, $payload)) {
                    throw $this->malformed_claim_row('scope authority appears in the payload');
                }
            }
        }

        $source_exists = $this->claim_row_boolean($row, 'source_exists');
        $source_bytes = $this->claim_row_integer($row, 'source_bytes');
        $canonical_bytes = $this->claim_row_integer($row, 'canonical_bytes');
        $source_snapshot_complete = $this->claim_row_boolean($row, 'source_snapshot_complete');
        $source_id = $this->claim_row_nullable_integer($row, 'source_id');
        $source_post_title = $this->claim_row_nullable_text($row, 'source_post_title');
        $source_post_content = $this->claim_row_nullable_text($row, 'source_post_content');
        $source_post_excerpt = $this->claim_row_nullable_text($row, 'source_post_excerpt');
        $source_post_type = $this->claim_row_nullable_text($row, 'source_post_type');
        $source_post_status = $this->claim_row_nullable_text($row, 'source_post_status');
        $source_post_date_gmt = $this->claim_row_nullable_text($row, 'source_post_date_gmt');
        $source_post_password = $this->claim_row_nullable_text($row, 'source_post_password');
        $source_existing_hash = $this->claim_row_nullable_text($row, 'source_existing_hash');
        if ($source_existing_hash !== null && strlen($source_existing_hash) > 40) {
            throw $this->malformed_claim_row('the existing content hash exceeds its database column');
        }

        if ($source_exists) {
            if (
                $kind !== 'post'
                || $source_id !== $post_id
                || $source_post_type === null
                || $source_post_status === null
                || $source_post_date_gmt === null
                || $source_post_password === null
                || $canonical_bytes < $source_bytes
            ) {
                throw $this->malformed_claim_row('the canonical source identity or metadata is invalid');
            }
            if ($source_snapshot_complete) {
                if (
                    $source_post_title === null
                    || $source_post_content === null
                    || $source_post_excerpt === null
                    || strlen($source_post_title) + strlen($source_post_content) + strlen($source_post_excerpt)
                        !== $source_bytes
                ) {
                    throw $this->malformed_claim_row('the source snapshot does not match its byte measurement');
                }
            } elseif ($source_post_title !== '' || $source_post_content !== '' || $source_post_excerpt !== '') {
                throw $this->malformed_claim_row('an over-budget source claim exposed large-object fields');
            }
        } elseif (
            $source_id !== null
            || $source_bytes !== 0
            || $canonical_bytes !== 0
            || $source_post_type !== null
            || $source_post_status !== null
            || $source_post_date_gmt !== null
            || $source_post_password !== null
            || $source_existing_hash !== null
            || !in_array($source_post_title, [null, ''], true)
            || !in_array($source_post_content, [null, ''], true)
            || !in_array($source_post_excerpt, [null, ''], true)
        ) {
            throw $this->malformed_claim_row('an absent canonical source carried source data');
        }

        $source_snapshot = null;
        if ($kind === 'post' && $source_exists && $source_snapshot_complete) {
            $source_snapshot = (object) [
                'ID' => $source_id,
                'post_title' => $source_post_title,
                'post_content' => $source_post_content,
                'post_excerpt' => $source_post_excerpt,
                'post_type' => $source_post_type,
                'post_status' => $source_post_status,
                'post_date_gmt' => $source_post_date_gmt,
                'post_password' => $source_post_password,
                'fts_post_source_bytes' => $source_bytes,
                'fts_canonical_post_bytes' => $canonical_bytes,
                'fts_existing_hash' => $source_existing_hash,
            ];
        }

        return [[
            'job_key' => $job_key,
            'kind' => $kind,
            'post_id' => $post_id,
            'generation' => $generation,
            'attempts' => $attempts,
            'last_error_code' => $last_error_code,
            'token' => $token,
            'claim_expires_at' => $claim_expires_at,
            'cursor_post_id' => $cursor_post_id,
            'scope_coverage' => $scope_authority[0],
            'scope_subject_type' => $scope_authority[1],
            'scope_subject_id' => $scope_authority[2],
            'scope_incarnation' => $scope_authority[3],
            'payload' => $payload,
            'source_exists' => $source_exists,
            'source_bytes' => $source_bytes,
            'canonical_bytes' => $canonical_bytes,
            'source_snapshot' => $source_snapshot,
        ], $source_snapshot_complete];
    }

    /** Return one required database text value without scalar coercion. */
    private function claim_row_text(object $row, string $alias): string
    {
        $value = $row->{$alias};
        if (!is_string($value)) {
            throw $this->malformed_claim_row("the {$alias} alias is not native text");
        }

        return $value;
    }

    /** Return one nullable database text value without scalar coercion. */
    private function claim_row_nullable_text(object $row, string $alias): ?string
    {
        $value = $row->{$alias};
        if ($value !== null && !is_string($value)) {
            throw $this->malformed_claim_row("the {$alias} alias is not nullable native text");
        }

        return $value;
    }

    /** Return one nonnegative native integer or canonical decimal DB string. */
    private function claim_row_integer(object $row, string $alias): int
    {
        $value = $row->{$alias};
        if (is_int($value)) {
            if ($value >= 0) {
                return $value;
            }
            throw $this->malformed_claim_row("the {$alias} alias is negative");
        }
        if (
            !is_string($value)
            || $value === ''
            || strspn($value, '0123456789') !== strlen($value)
            || ($value !== '0' && $value[0] === '0')
            || strlen($value) > strlen((string) PHP_INT_MAX)
            || (strlen($value) === strlen((string) PHP_INT_MAX) && strcmp($value, (string) PHP_INT_MAX) > 0)
        ) {
            throw $this->malformed_claim_row("the {$alias} alias is not a canonical nonnegative integer");
        }

        return (int) $value;
    }

    /** Return one nullable nonnegative native or canonical database integer. */
    private function claim_row_nullable_integer(object $row, string $alias): ?int
    {
        if ($row->{$alias} === null) {
            return null;
        }

        return $this->claim_row_integer($row, $alias);
    }

    /** Decode only the exact zero and one values emitted by SQL predicates. */
    private function claim_row_boolean(object $row, string $alias): bool
    {
        $value = $this->claim_row_integer($row, $alias);
        if ($value > 1) {
            throw $this->malformed_claim_row("the {$alias} alias is not zero or one");
        }

        return $value === 1;
    }

    /** Decode one nonnegative native integer or canonical decimal DB string. */
    private function database_nonnegative_integer(mixed $value, string $context): int
    {
        if (is_int($value)) {
            if ($value >= 0) {
                return $value;
            }
            throw new UnexpectedValueException("The {$context} database value is negative.");
        }
        if (
            !is_string($value)
            || $value === ''
            || strspn($value, '0123456789') !== strlen($value)
            || ($value !== '0' && $value[0] === '0')
            || strlen($value) > strlen((string) PHP_INT_MAX)
            || (strlen($value) === strlen((string) PHP_INT_MAX) && strcmp($value, (string) PHP_INT_MAX) > 0)
        ) {
            throw new UnexpectedValueException("The {$context} database value is not a canonical nonnegative integer.");
        }

        return (int) $value;
    }

    /** Decode SELECT 1 or a genuine no-row null without truthiness. */
    private function database_presence_value(mixed $value, string $context): bool
    {
        if ($value === null) {
            return false;
        }
        if ($value === 1 || $value === '1') {
            return true;
        }

        throw new UnexpectedValueException("The {$context} presence query returned an invalid value.");
    }

    private function malformed_claim_row(string $detail, ?Throwable $previous = null): UnexpectedValueException
    {
        return new UnexpectedValueException("Malformed FTS claim confirmation row: {$detail}.", 0, $previous);
    }

    /**
     * Build an at-most-four-arm candidate relation over the ready index.
     *
     * Each state arm materializes at most the requested batch size before the
     * outer priority sort. Consequently an eligible guarded abandonment wins
     * without a CASE filesort over the complete ready backlog, and the derived
     * relation never exceeds 400 post rows (four scope rows for a scope claim).
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
            ? ['guarded', 'ready', 'retry', 'leased']
            : ['ready', 'retry', 'leased'];
        // Foreground enqueue inserts PRIMARY and secondary-index records in
        // that order. Skip its uncommitted rows instead of holding a candidate
        // range while waiting back on PRIMARY, which would invert that order.
        $candidate_lock = $this->is_sqlite_runtime() ? '' : ' FOR UPDATE SKIP LOCKED';
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
        LIMIT {$limit}{$candidate_lock}
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
        $ids = $this->unique_positive_post_ids($post_ids, 'FTS scope page');
        $ids = array_keys($ids);
        sort($ids, SORT_NUMERIC);
        $previous_cursor = $claim['cursor_post_id'];
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
            $encoded_scope_payload = $this->encoded_scope_payload($next_scope_payload);
            $scope_payload_assignment = ",\n    payload = %s";
            $scope_payload_args[] = $encoded_scope_payload;
        }
        $now = $this->timestamp($now);

        $this->control_query('START TRANSACTION', 'start FTS scope page transaction');
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
            $advanced = $this->bounded_affected_rows($advanced, 1, 'FTS scope page advance');
            if ($advanced !== 1) {
                $this->control_query('ROLLBACK', 'roll back superseded FTS scope page');
                return false;
            }

            if ($ids !== [] && $this->enqueue_many($ids, $now, $post_payload) !== count($ids)) {
                throw new RuntimeException('FTS scope page enqueue did not accept every post id.');
            }
            $this->control_query('COMMIT', 'commit FTS scope page transaction');
            return true;
        } catch (Throwable $error) {
            try {
                $this->control_query('ROLLBACK', 'roll back failed FTS scope page transaction');
            } catch (Throwable) {
                // Preserve the statement failure that made the transaction unsafe.
            }
            throw $error;
        }
    }

    /** Return a deduplicated map for one exact list of positive post IDs. */
    private function unique_positive_post_ids(array $post_ids, string $surface): array
    {
        if (count($post_ids) > self::MAX_ENQUEUE_POSTS) {
            throw new InvalidArgumentException(
                "{$surface} batch exceeds the " . self::MAX_ENQUEUE_POSTS . '-post enqueue contract.'
            );
        }
        if (!array_is_list($post_ids)) {
            throw new InvalidArgumentException("{$surface} post ids must be a list.");
        }

        $ids = [];
        foreach ($post_ids as $post_id) {
            if (!is_int($post_id) || $post_id <= 0) {
                throw new InvalidArgumentException("{$surface} post ids must be positive integers.");
            }
            $ids[$post_id] = true;
        }

        return $ids;
    }

    /**
     * Acknowledge one completed scope or release a superseding generation.
     *
     * @param array<string,mixed> $claim
     */
    public function acknowledge_scope(array $claim, ?int $now = null): bool
    {
        $claim = $this->normalize_scope_claim($claim);
        $now = $this->timestamp($now);
        $this->control_query('START TRANSACTION', 'start FTS scope acknowledgement transaction');
        try {
            $deleted = $this->query($this->wpdb->prepare(
                "DELETE FROM {$this->table}
WHERE job_key = %s AND claim_token = %s
  AND claimed_generation = %d AND generation = %d",
                $claim['job_key'],
                $claim['token'],
                $claim['generation'],
                $claim['generation']
            ), 'acknowledge FTS scope work');
            $deleted = $this->bounded_affected_rows($deleted, 1, 'FTS scope acknowledgement');
            if ($deleted === 1) {
                $this->advance_search_epoch();
                $this->control_query('COMMIT', 'commit FTS scope acknowledgement transaction');
                return true;
            }
            $this->control_query('ROLLBACK', 'roll back superseded FTS scope acknowledgement');
        } catch (Throwable $error) {
            try {
                $this->control_query('ROLLBACK', 'roll back failed FTS scope acknowledgement');
            } catch (Throwable) {
                // Preserve the acknowledgement failure.
            }
            throw $error;
        }

        $released = $this->query($this->wpdb->prepare(
            "UPDATE {$this->table}
SET state = 'ready', attempts = 0, available_at = %d,
    claim_token = '', claimed_generation = 0, claim_expires_at = 0,
    cursor_post_id = 0
WHERE job_key = %s AND claim_token = %s
  AND claimed_generation = %d AND generation > %d",
            $now,
            $claim['job_key'],
            $claim['token'],
            $claim['generation'],
            $claim['generation']
        ), 'release superseded FTS scope work');

        return $this->bounded_affected_rows($released, 1, 'superseded FTS scope release') === 1;
    }

    /** Release an unprocessed scope generation after a transient batch error. */
    public function release_scope(array $claim, ?int $now = null): bool
    {
        $claim = $this->normalize_scope_claim($claim);

        $released = $this->query($this->wpdb->prepare(
            "UPDATE {$this->table}
SET state = 'ready', available_at = %d,
    claim_token = '', claimed_generation = 0, claim_expires_at = 0
WHERE job_key = %s AND claim_token = %s AND claimed_generation = %d",
            $this->timestamp($now),
            $claim['job_key'],
            $claim['token'],
            $claim['generation']
        ), 'release FTS scope work');

        return $this->bounded_affected_rows($released, 1, 'FTS scope release') === 1;
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

        $yielded = $this->query($this->wpdb->prepare(
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
        ), 'yield mixed FTS scope to direct posts');

        return $this->bounded_affected_rows($yielded, 1, 'mixed FTS scope yield') === 1;
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

        $released = $this->query(
            $this->wpdb->prepare($sql, ...$args),
            'yield mixed FTS scope and release deferred posts'
        );

        return $this->bounded_affected_rows($released, count($posts) + 1, 'mixed FTS claim release');
    }

    /** Return one failed scope generation with the same capped retry policy. */
    public function fail_scope(array $claim, ?int $now = null): array
    {
        $claim = $this->normalize_scope_claim($claim);
        if ($claim['attempts'] === PHP_INT_MAX) {
            throw new InvalidArgumentException('FTS scope claim attempts exceed the platform integer range.');
        }
        $attempts = $claim['attempts'] + 1;

        $now = $this->timestamp($now);
        if ($now > PHP_INT_MAX - self::MAX_BACKOFF_SECONDS) {
            throw new InvalidArgumentException('FTS scope retry time exceeds the platform integer range.');
        }
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
        $failed = $this->bounded_affected_rows($failed, 1, 'failed FTS scope deferral');

        return $failed === 1
            ? ['status' => 'backoff', 'attempts' => $attempts, 'available_at' => $availableAt]
            : ['status' => 'superseded', 'attempts' => 0, 'available_at' => $now];
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
    public function acknowledge_many(array $claims): array
    {
        $normalized = $this->normalize_claims($claims);
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

        $this->control_query('START TRANSACTION', 'start FTS acknowledgement transaction');
        try {
            $deleted = $this->query($this->wpdb->prepare(
                $deleteSql,
                ...$deleteArgs
            ), 'acknowledge FTS indexing batch');
            $deleted = $this->bounded_affected_rows(
                $deleted,
                count($normalized),
                'FTS indexing batch acknowledgement'
            );
            if ($deleted > 0) {
                $this->advance_search_epoch();
                $this->control_query('COMMIT', 'commit FTS acknowledgement transaction');
            } else {
                // A superseding generation remains dirty. Do not invalidate
                // cursors for a transition that did not become visible.
                $this->control_query('ROLLBACK', 'roll back superseded FTS acknowledgement');
            }
        } catch (Throwable $error) {
            try {
                $this->control_query('ROLLBACK', 'roll back failed FTS acknowledgement');
            } catch (Throwable) {
                // Preserve the statement failure that made the transition unsafe.
            }
            throw $error;
        }
        return [
            'acknowledged' => $deleted,
            'superseded' => count($normalized) - $deleted,
        ];
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
        $now = $this->timestamp($now);
        if ($normalized === []) {
            return 0;
        }
        if ($now > PHP_INT_MAX - self::MAX_BACKOFF_SECONDS) {
            throw new InvalidArgumentException('FTS batch retry time exceeds the platform integer range.');
        }
        foreach ($normalized as $claim) {
            if ($claim['attempts'] === PHP_INT_MAX) {
                throw new InvalidArgumentException('FTS claim attempts exceed the platform integer range.');
            }
        }
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

        $failed = $this->query(
            $this->wpdb->prepare($sql, ...$preparedArgs),
            'defer failed FTS indexing batch'
        );

        return $this->bounded_affected_rows($failed, count($normalized), 'failed FTS indexing batch deferral');
    }

    /**
     * Release a whole unprocessed batch in one statement.
     *
     * @param array<int,array<string,mixed>> $claims
     */
    public function release_many(array $claims, ?int $now = null): int
    {
        $normalized = $this->normalize_claims($claims);
        $releasedAt = $this->timestamp($now);
        if ($normalized === []) {
            return 0;
        }
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

        $released = $this->query(
            $this->wpdb->prepare($sql, ...$preparedArgs),
            'release FTS indexing batch'
        );

        return $this->bounded_affected_rows($released, count($normalized), 'FTS indexing batch release');
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

        return $this->database_presence_value($value, 'pending FTS indexing work');
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
        if (count($rows) !== 2) {
            throw new UnexpectedValueException('The FTS work status query must return exactly two rows.');
        }
        $counts = [];
        $cursors = [];
        foreach ($rows as $row) {
            if (array_keys(get_object_vars($row)) !== ['kind', 'work_count', 'max_cursor_post_id']) {
                throw new UnexpectedValueException('An FTS work status row has invalid aliases.');
            }
            if (!is_string($row->kind) || !in_array($row->kind, ['post', 'scope'], true)) {
                throw new UnexpectedValueException('An FTS work status row has an invalid kind.');
            }
            if (isset($counts[$row->kind])) {
                throw new UnexpectedValueException('The FTS work status query returned a duplicate kind.');
            }
            $counts[$row->kind] = $this->database_nonnegative_integer(
                $row->work_count,
                "FTS {$row->kind} work count"
            );
            $cursors[$row->kind] = $this->database_nonnegative_integer(
                $row->max_cursor_post_id,
                "FTS {$row->kind} cursor"
            );
            if ($counts[$row->kind] > $limit) {
                throw new UnexpectedValueException('An FTS work status count exceeds its bounded query.');
            }
        }
        if (!isset($counts['post'], $counts['scope']) || $cursors['post'] !== 0) {
            throw new UnexpectedValueException('The FTS work status rows violate their kind contracts.');
        }
        if ($counts['scope'] === 0 && $cursors['scope'] !== 0) {
            throw new UnexpectedValueException('An empty FTS scope status cannot carry cursor progress.');
        }
        $status = [
            'post_count' => $counts['post'],
            'scope_count' => $counts['scope'],
            'scope_cursor_post_id' => $cursors['scope'],
            'post_count_relation' => 'exact',
            'scope_count_relation' => 'exact',
            'counts_capped' => false,
        ];
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
     * before `claim_expires_at`. A non-free owner probe projects one bounded
     * watchdog arm for each protected state. A free probe includes only indexed
     * `guarded` work and omits operator-only `fenced` debt, so that debt cannot
     * create a recurring cron wake or hide guarded recovery behind an outer
     * token filter.
     */
    public function next_available_at(): ?int
    {
        $arms = [];
        $watchdog_at = time() + self::FOREGROUND_OWNER_WATCHDOG_SECONDS;
        $claimGuard = $this->begin_foreground_fence_claim();
        $recoverGuardedFences = $claimGuard['state'] === 'free';
        $this->end_foreground_fence_claim($claimGuard);
        $states = $recoverGuardedFences
            ? ['guarded', 'ready', 'retry', 'leased']
            : ['guarded', 'fenced', 'ready', 'retry', 'leased'];
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

        if ($value === null) {
            return null;
        }
        $next_available_at = $this->database_nonnegative_integer($value, 'next available FTS work time');
        if ($next_available_at === 0) {
            throw new UnexpectedValueException('The next available FTS work time must be positive.');
        }

        return $next_available_at;
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
        $affected = $this->query($this->wpdb->prepare(
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

        return $this->bounded_affected_rows($affected, 2, 'FTS search epoch advance');
    }

    /**
     * @param array<string,mixed> $claim
     * @return array{job_key:string,post_id:int,generation:int,attempts:int,token:string}
     */
    private function normalize_claim(array $claim): array
    {
        $this->assert_minted_claim_shape($claim);
        if (
            $claim['kind'] !== 'post'
            || $claim['post_id'] <= 0
            || !self::is_post_job_key($claim['job_key'], $claim['post_id'])
            || $claim['cursor_post_id'] !== 0
            || $claim['scope_coverage'] !== ''
            || $claim['scope_subject_type'] !== ''
            || $claim['scope_subject_id'] !== 0
            || $claim['scope_incarnation'] !== ''
        ) {
            throw new InvalidArgumentException('Invalid FTS post claim capability.');
        }

        return [
            'job_key' => $claim['job_key'],
            'post_id' => $claim['post_id'],
            'generation' => $claim['generation'],
            'attempts' => $claim['attempts'],
            'token' => $claim['token'],
        ];
    }

    /**
     * @param array<int,array<string,mixed>> $claims
     * @return array<string,array{job_key:string,post_id:int,generation:int,attempts:int,token:string}>
     */
    private function normalize_claims(array $claims): array
    {
        $this->assert_bounded_claim_count($claims);
        if (!array_is_list($claims)) {
            throw new InvalidArgumentException('FTS work claims must be a list.');
        }
        $normalized = [];
        foreach ($claims as $claim) {
            if (!is_array($claim)) {
                throw new InvalidArgumentException('Every FTS work claim must be a native array.');
            }
            $claim = $this->normalize_claim($claim);
            if (isset($normalized[$claim['job_key']])) {
                throw new InvalidArgumentException('FTS work claims must not contain duplicate job keys.');
            }
            $normalized[$claim['job_key']] = $claim;
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
     * @return array{job_key:string,generation:int,attempts:int,token:string,cursor_post_id:int,scope_coverage:string,scope_subject_type:string,scope_subject_id:int,scope_incarnation:string}
     */
    private function normalize_scope_claim(array $claim): array
    {
        $this->assert_minted_claim_shape($claim);
        if (
            $claim['kind'] !== 'scope'
            || $claim['post_id'] !== 0
            || !$this->is_scope_job_key($claim['job_key'])
        ) {
            throw new InvalidArgumentException('Invalid FTS scope claim capability.');
        }
        $scope_authority = $this->validated_scope_authority(
            $claim['scope_coverage'],
            $claim['scope_subject_type'],
            $claim['scope_subject_id'],
            $claim['scope_incarnation']
        );
        $this->encoded_scope_payload($claim['payload']);

        return [
            'job_key' => $claim['job_key'],
            'generation' => $claim['generation'],
            'attempts' => $claim['attempts'],
            'token' => $claim['token'],
            'cursor_post_id' => $claim['cursor_post_id'],
            'scope_coverage' => $scope_authority[0],
            'scope_subject_type' => $scope_authority[1],
            'scope_subject_id' => $scope_authority[2],
            'scope_incarnation' => $scope_authority[3],
        ];
    }

    /** Require the exact native claim shape minted by claim_batch(). */
    private function assert_minted_claim_shape(array $claim): void
    {
        if (
            count($claim) !== count(self::CLAIM_KEYS)
            || array_keys($claim) !== self::CLAIM_KEYS
            || !is_string($claim['job_key'])
            || !is_string($claim['kind'])
            || !is_int($claim['post_id'])
            || !is_int($claim['generation'])
            || $claim['generation'] <= 0
            || !is_int($claim['attempts'])
            || $claim['attempts'] < 0
            || !is_string($claim['last_error_code'])
            || strlen($claim['last_error_code']) > 64
            || !$this->is_worker_claim_token($claim['token'])
            || !is_int($claim['claim_expires_at'])
            || $claim['claim_expires_at'] <= 0
            || !is_int($claim['cursor_post_id'])
            || $claim['cursor_post_id'] < 0
            || !is_string($claim['scope_coverage'])
            || !is_string($claim['scope_subject_type'])
            || !is_int($claim['scope_subject_id'])
            || $claim['scope_subject_id'] < 0
            || !is_string($claim['scope_incarnation'])
            || !is_array($claim['payload'])
            || !is_bool($claim['source_exists'])
            || !is_int($claim['source_bytes'])
            || $claim['source_bytes'] < 0
            || !is_int($claim['canonical_bytes'])
            || $claim['canonical_bytes'] < 0
            || ($claim['source_snapshot'] !== null && !is_object($claim['source_snapshot']))
        ) {
            throw new InvalidArgumentException('Invalid FTS claim capability.');
        }
        $this->assert_bounded_payload($claim['payload']);
        $this->assert_minted_source_fields($claim);
    }

    /** Require the exact native source sidecar minted by claim_batch(). */
    private function assert_minted_source_fields(array $claim): void
    {
        if (!$claim['source_exists']) {
            if (
                $claim['source_bytes'] !== 0
                || $claim['canonical_bytes'] !== 0
                || $claim['source_snapshot'] !== null
            ) {
                throw new InvalidArgumentException('An absent FTS claim source must have empty sidecars.');
            }
            return;
        }
        if (
            $claim['kind'] !== 'post'
            || $claim['post_id'] <= 0
            || $claim['canonical_bytes'] < $claim['source_bytes']
        ) {
            throw new InvalidArgumentException('An FTS claim source has invalid post identity or byte bounds.');
        }

        $snapshot = $claim['source_snapshot'];
        if ($snapshot === null) {
            return;
        }
        $keys = [
            'ID',
            'post_title',
            'post_content',
            'post_excerpt',
            'post_type',
            'post_status',
            'post_date_gmt',
            'post_password',
            'fts_post_source_bytes',
            'fts_canonical_post_bytes',
            'fts_existing_hash',
        ];
        if (
            $snapshot::class !== stdClass::class
            || array_keys(get_object_vars($snapshot)) !== $keys
            || !is_int($snapshot->ID)
            || $snapshot->ID !== $claim['post_id']
            || !is_string($snapshot->post_title)
            || !is_string($snapshot->post_content)
            || !is_string($snapshot->post_excerpt)
            || !is_string($snapshot->post_type)
            || !is_string($snapshot->post_status)
            || !is_string($snapshot->post_date_gmt)
            || !is_string($snapshot->post_password)
            || !is_int($snapshot->fts_post_source_bytes)
            || $snapshot->fts_post_source_bytes !== $claim['source_bytes']
            || !is_int($snapshot->fts_canonical_post_bytes)
            || $snapshot->fts_canonical_post_bytes !== $claim['canonical_bytes']
            || ($snapshot->fts_existing_hash !== null && !is_string($snapshot->fts_existing_hash))
            || (is_string($snapshot->fts_existing_hash) && strlen($snapshot->fts_existing_hash) > 40)
        ) {
            throw new InvalidArgumentException('An FTS claim source snapshot has invalid aliases or native fields.');
        }

        $source_bytes = strlen($snapshot->post_title)
            + strlen($snapshot->post_content)
            + strlen($snapshot->post_excerpt);
        if ($source_bytes !== $claim['source_bytes']) {
            throw new InvalidArgumentException('An FTS claim source snapshot has inconsistent source bytes.');
        }
    }

    /** Encode the public post id as the queue's exact direct-work identity. */
    private function post_job_key(int $post_id): string
    {
        if ($post_id <= 0) {
            throw new LogicException('FTS post job keys require a positive post ID.');
        }

        return 'post:' . $post_id;
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
        return $post_id > 0 && hash_equals('post:' . $post_id, $job_key);
    }

    /** Accept only the exact hashed identity minted for scope work. */
    private function is_scope_job_key(string $job_key): bool
    {
        return strlen($job_key) === 70
            && str_starts_with($job_key, 'scope:')
            && $this->is_lower_hex(substr($job_key, 6), 64);
    }

    /** Accept only a worker lease minted by claim_batch(). */
    private function is_worker_claim_token(mixed $token): bool
    {
        return is_string($token) && $this->is_lower_hex($token, 32);
    }

    /** Accept only the two durable foreground ownership token forms. */
    private function validated_mutation_token(string $token): string
    {
        $valid = $this->is_lower_hex($token, 32)
            || (
                str_starts_with($token, 'guard:')
                && $this->is_lower_hex(substr($token, 6), 32)
            );
        if (!$valid) {
            throw new InvalidArgumentException('FTS mutation tokens must use the exact durable token grammar.');
        }

        return $token;
    }

    /** Test exact lowercase hexadecimal text without extension dependencies. */
    private function is_lower_hex(string $value, int $bytes): bool
    {
        return strlen($value) === $bytes
            && strspn($value, '0123456789abcdef') === $bytes;
    }

    /** Reject scope identities that cannot fit the bounded queue contract. */
    private function validated_scope_key(string $scope_key): string
    {
        if ($scope_key === '' || trim($scope_key) !== $scope_key || strlen($scope_key) > 1024) {
            if (strlen($scope_key) > 1024) {
                throw new InvalidArgumentException('FTS scope work keys may contain at most 1,024 bytes.');
            }
            throw new InvalidArgumentException('FTS scope work requires an unpadded non-empty key.');
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
        if ($value === null) {
            return time();
        }
        if ($value < 1) {
            throw new InvalidArgumentException('FTS timestamps must be positive integers.');
        }

        return $value;
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
        $affected = $this->query($this->wpdb->prepare(
            "UPDATE /* wp_fts:refence-interrupted-claim */ {$this->table}
SET state = 'guarded', claim_token = %s,
    claimed_generation = generation, claim_expires_at = 0
WHERE state = 'leased' AND claim_token = %s
  AND claimed_generation = generation",
            $guardToken,
            $claimToken
        ), 'refence interrupted FTS claim');
        $this->bounded_affected_rows(
            $affected,
            self::MAX_CLAIM_POSTS + 1,
            'interrupted FTS claim refence'
        );
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
        if (!is_int($result) || $result < 0) {
            throw new UnexpectedValueException("The database returned an invalid affected-row count while attempting to {$context}.");
        }

        return $result;
    }

    /** Reject impossible affected-row counts at a bounded DML boundary. */
    private function bounded_affected_rows(int $affected, int $maximum, string $context): int
    {
        if ($maximum < 0 || $affected > $maximum) {
            throw new UnexpectedValueException("The {$context} affected-row count exceeds its contract.");
        }

        return $affected;
    }

    /** Run transaction control while accepting only its documented success forms. */
    private function control_query(string $statement, string $context): void
    {
        $result = $this->wpdb->query($statement);
        if ($result === false) {
            throw $this->database_exception($context);
        }
        $this->assert_no_database_error($context);
        if ($result !== true && (!is_int($result) || $result < 0)) {
            throw new UnexpectedValueException("The database returned an invalid control result while attempting to {$context}.");
        }
    }

    /**
     * @return object[]
     */
    private function get_results(mixed $statement, string $context): array
    {
        $rows = $this->wpdb->get_results($statement);
        $this->assert_no_database_error($context);
        if (!is_array($rows) || !array_is_list($rows)) {
            throw new UnexpectedValueException("The database returned an invalid row collection while attempting to {$context}.");
        }
        foreach ($rows as $row) {
            if (!is_object($row)) {
                throw new UnexpectedValueException("The database returned a non-object row while attempting to {$context}.");
            }
        }

        return $rows;
    }

    private function get_var(mixed $statement, string $context): mixed
    {
        $value = $this->wpdb->get_var($statement);
        $this->assert_no_database_error($context);

        return $value;
    }

    private function assert_no_database_error(string $context): void
    {
        if (property_exists($this->wpdb, 'last_error') && !is_string($this->wpdb->last_error)) {
            throw new UnexpectedValueException("The database exposed an invalid error value while attempting to {$context}.");
        }
        if (isset($this->wpdb->last_error) && trim($this->wpdb->last_error) !== '') {
            throw $this->database_exception($context);
        }
    }

    private function database_exception(string $context): RuntimeException
    {
        if (property_exists($this->wpdb, 'last_error') && !is_string($this->wpdb->last_error)) {
            throw new UnexpectedValueException("The database exposed an invalid error value while attempting to {$context}.");
        }
        $error = isset($this->wpdb->last_error) ? trim($this->wpdb->last_error) : '';
        $suffix = $error !== '' ? ": {$error}" : '.';

        return new RuntimeException("Failed to {$context}{$suffix}");
    }
}
