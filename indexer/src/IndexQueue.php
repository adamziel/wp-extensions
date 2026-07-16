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
final class WP_FTS_Index_Queue
{
    public const DEFAULT_LEASE_SECONDS = 300;
    public const BASE_BACKOFF_SECONDS = 300;
    public const MAX_BACKOFF_SECONDS = 3600;

    private object $wpdb;
    private string $table;

    public function __construct(object $wpdb, ?string $prefix = null)
    {
        $this->wpdb = $wpdb;
        $prefix = $prefix ?? (string) ($wpdb->prefix ?? '');
        $this->table = $prefix . 'fts_queue';
    }

    /**
     * Atomically coalesce a post save into the latest queued generation.
     */
    public function enqueue(int $post_id, ?int $now = null): void
    {
        if ($post_id <= 0) {
            return;
        }

        $now = $this->timestamp($now);
        $this->query($this->wpdb->prepare(
            "INSERT INTO {$this->table}
    (post_id, generation, available_at, attempts, claim_token, claimed_generation, claim_expires_at)
VALUES (%d, 1, %d, 0, '', 0, 0)
ON DUPLICATE KEY UPDATE
    generation = generation + 1,
    available_at = VALUES(available_at),
    attempts = 0",
            $post_id,
            $now
        ), 'enqueue FTS indexing work');
    }

    /**
     * Make an existing failed generation available without treating the retry
     * as a new post save. Missing rows are restored for explicit recovery after
     * quarantine.
     */
    public function retry(int $post_id, ?int $now = null): void
    {
        if ($post_id <= 0) {
            return;
        }

        $this->query($this->wpdb->prepare(
            "INSERT INTO {$this->table}
    (post_id, generation, available_at, attempts, claim_token, claimed_generation, claim_expires_at)
VALUES (%d, 1, %d, 0, '', 0, 0)
ON DUPLICATE KEY UPDATE
    available_at = VALUES(available_at)",
            $post_id,
            $this->timestamp($now)
        ), 'retry FTS indexing work');
    }

    /**
     * Import normalized post ids from the legacy option queue.
     *
     * @param int[] $post_ids
     */
    public function import(array $post_ids, ?int $now = null): void
    {
        $ids = [];
        foreach ($post_ids as $post_id) {
            $post_id = (int) $post_id;
            if ($post_id > 0) {
                $ids[$post_id] = true;
            }
        }

        foreach (array_keys($ids) as $post_id) {
            $this->enqueue($post_id, $now);
        }
    }

    /**
     * Claim currently available generations with one compare-and-swap per row.
     *
     * Expired leases satisfy the same predicate as unclaimed rows. A stale
     * worker's later acknowledgement fails because its token no longer owns the
     * row.
     *
     * @return array<int,array{post_id:int,generation:int,attempts:int,token:string,claim_expires_at:int}>
     */
    public function claim(int $limit, ?int $now = null, int $lease_seconds = self::DEFAULT_LEASE_SECONDS): array
    {
        $limit = max(0, $limit);
        if ($limit === 0) {
            return [];
        }

        $now = $this->timestamp($now);
        $lease_expires_at = $now + max(1, $lease_seconds);
        $token = bin2hex(random_bytes(16));
        $rows = $this->get_results($this->wpdb->prepare(
            "SELECT post_id, generation, attempts
FROM {$this->table}
WHERE available_at <= %d
  AND (claim_token = '' OR claim_expires_at <= %d)
ORDER BY available_at ASC, post_id ASC
LIMIT %d",
            $now,
            $now,
            $limit
        ), 'read claimable FTS indexing work');

        $claims = [];
        foreach ($rows as $row) {
            $post_id = max(0, (int) ($row->post_id ?? 0));
            $generation = max(0, (int) ($row->generation ?? 0));
            if ($post_id <= 0 || $generation <= 0) {
                continue;
            }

            $affected = $this->query($this->wpdb->prepare(
                "UPDATE {$this->table}
SET claim_token = %s,
    claimed_generation = generation,
    claim_expires_at = %d
WHERE post_id = %d
  AND generation = %d
  AND available_at <= %d
  AND (claim_token = '' OR claim_expires_at <= %d)",
                $token,
                $lease_expires_at,
                $post_id,
                $generation,
                $now,
                $now
            ), 'claim FTS indexing work');
            if ($affected !== 1) {
                continue;
            }

            $claims[] = [
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
     * Acknowledge only the generation owned by this claim.
     *
     * If a newer save arrived, the first delete cannot match. The second query
     * releases the old claim and makes the newer generation immediately
     * available.
     *
     * @param array{post_id:int,generation:int,token:string} $claim
     */
    public function acknowledge(array $claim, ?int $now = null): bool
    {
        $claim = $this->normalize_claim($claim);
        if ($claim === null) {
            return false;
        }

        $deleted = $this->query($this->wpdb->prepare(
            "DELETE FROM {$this->table}
WHERE post_id = %d
  AND claim_token = %s
  AND claimed_generation = %d
  AND generation = %d",
            $claim['post_id'],
            $claim['token'],
            $claim['generation'],
            $claim['generation']
        ), 'acknowledge FTS indexing work');
        if ($deleted === 1) {
            return true;
        }

        $released = $this->release_superseded_claim($claim, $this->timestamp($now));

        return $released === 1;
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
SET attempts = %d,
    available_at = %d,
    claim_token = '',
    claimed_generation = 0,
    claim_expires_at = 0
WHERE post_id = %d
  AND claim_token = %s
  AND claimed_generation = %d
  AND generation = %d",
            $attempts,
            $available_at,
            $claim['post_id'],
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
SET available_at = %d,
    claim_token = '',
    claimed_generation = 0,
    claim_expires_at = 0
WHERE post_id = %d
  AND claim_token = %s
  AND claimed_generation = %d",
            $this->timestamp($now),
            $claim['post_id'],
            $claim['token'],
            $claim['generation']
        ), 'release FTS indexing work');

        return $affected === 1;
    }

    /**
     * Remove all pending generations for selected posts.
     *
     * @param int[] $post_ids
     */
    public function remove(array $post_ids): void
    {
        $ids = [];
        foreach ($post_ids as $post_id) {
            $post_id = (int) $post_id;
            if ($post_id > 0) {
                $ids[$post_id] = true;
            }
        }
        $ids = array_keys($ids);
        if ($ids === []) {
            return;
        }

        $placeholders = implode(',', array_fill(0, count($ids), '%d'));
        $this->query($this->wpdb->prepare(
            "DELETE FROM {$this->table} WHERE post_id IN ({$placeholders})",
            ...$ids
        ), 'remove FTS indexing work');
    }

    /**
     * Count pending work without loading every queued post id into memory.
     */
    public function count(): int
    {
        $value = $this->get_var("SELECT COUNT(*) FROM {$this->table}", 'count pending FTS indexing work');

        return is_numeric($value) ? max(0, (int) $value) : 0;
    }

    /**
     * Clear all pending work while retaining the queue schema.
     */
    public function clear(): int
    {
        return $this->query("DELETE FROM {$this->table}", 'clear FTS indexing work');
    }

    /**
     * @param array<string,mixed> $claim
     * @return array{post_id:int,generation:int,attempts:int,token:string}|null
     */
    private function normalize_claim(array $claim): ?array
    {
        $post_id = max(0, (int) ($claim['post_id'] ?? 0));
        $generation = max(0, (int) ($claim['generation'] ?? 0));
        $token = isset($claim['token']) && is_scalar($claim['token']) ? (string) $claim['token'] : '';
        if ($post_id <= 0 || $generation <= 0 || $token === '' || strlen($token) > 64) {
            return null;
        }

        return [
            'post_id' => $post_id,
            'generation' => $generation,
            'attempts' => max(0, (int) ($claim['attempts'] ?? 0)),
            'token' => $token,
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
SET attempts = 0,
    available_at = %d,
    claim_token = '',
    claimed_generation = 0,
    claim_expires_at = 0
WHERE post_id = %d
  AND claim_token = %s
  AND claimed_generation = %d
  AND generation > %d",
            $now,
            $claim['post_id'],
            $claim['token'],
            $claim['generation'],
            $claim['generation']
        ), 'release superseded FTS indexing work');
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

    private function get_var(string $statement, string $context): mixed
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
