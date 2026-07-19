<?php
declare(strict_types=1);

/**
 * Request-level foreground fan-out containment contracts.
 *
 * Direct execution re-enters the shared harness with a focused filter. Normal
 * tests/run.php discovery registers these tests alongside the full suite.
 */
function wp_fts_foreground_bulk_contract_direct(): int
{
    if (!function_exists('proc_open')) {
        fwrite(STDOUT, "SKIP: proc_open() is unavailable, so the focused foreground bulk contract cannot launch tests/run.php.\n");
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
            'WP_FTS_TEST_FILTER' => 'foreground bulk mutation',
            'WP_FTS_MIN_CHECKS' => '0',
        ])
    );
    if (!is_resource($process)) {
        fwrite(STDERR, "FAIL: Could not launch the focused foreground bulk contract.\n");
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
    exit(wp_fts_foreground_bulk_contract_direct());
}

/** @return mixed */
function wp_fts_foreground_bulk_static(string $property): mixed
{
    return (new ReflectionClass(WP_FTS_Plugin::class))->getProperty($property)->getValue();
}

/** @return string[] */
function wp_fts_foreground_bulk_work_writes(WP_FTS_Test_WPDB $fake): array
{
    return array_values(array_filter(
        array_map('wp_fts_foreground_bulk_sql', $fake->queries),
        static fn(string $sql): bool => str_starts_with($sql, 'INSERT INTO wp_fts_work')
    ));
}

function wp_fts_foreground_bulk_sql(mixed $query): string
{
    return is_array($query) ? (string) ($query[0] ?? '') : (string) $query;
}

/** @return string[] */
function wp_fts_foreground_bulk_work_statements(WP_FTS_Test_WPDB $fake): array
{
    return array_values(array_filter(
        array_map('wp_fts_foreground_bulk_sql', $fake->queries),
        static fn(string $sql): bool => str_starts_with($sql, 'INSERT INTO wp_fts_work')
            || str_starts_with($sql, 'UPDATE wp_fts_work')
            || str_starts_with($sql, 'DELETE FROM wp_fts_work')
    ));
}

/** @return string[] */
function wp_fts_foreground_bulk_total_sql(WP_FTS_Test_WPDB $fake): array
{
    return array_values(array_filter(
        array_map('wp_fts_foreground_bulk_sql', $fake->queries),
        static fn(string $sql): bool => str_contains($sql, 'wp_fts_work')
    ));
}

/** @return array{process:resource,pipes:array<int,resource>,metadata:array<string,mixed>} */
function wp_fts_foreground_flock_holder(string $directory, string $mode = 'shared'): array
{
    $fixture = dirname(__DIR__) . '/fixtures/foreground-owner-flock-holder.php';
    $process = proc_open(
        [PHP_BINARY, $fixture, $directory, $mode],
        [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ],
        $pipes,
        dirname(__DIR__, 2)
    );
    if (!is_resource($process)) {
        throw new RuntimeException('Could not launch the foreground flock holder fixture.');
    }
    stream_set_timeout($pipes[1], 5);
    $line = fgets($pipes[1]);
    $metadata = is_string($line) ? json_decode($line, true) : null;
    if (!is_array($metadata) || !is_string($metadata['path'] ?? null)) {
        $stderr = stream_get_contents($pipes[2]);
        foreach ($pipes as $pipe) {
            fclose($pipe);
        }
        proc_terminate($process, 9);
        proc_close($process);
        throw new RuntimeException('Foreground flock holder did not become ready: ' . (string) $stderr);
    }

    return ['process' => $process, 'pipes' => $pipes, 'metadata' => $metadata];
}

/** @param array<string,string> $config */
function wp_fts_foreground_flock_path(array $config): string
{
    $fixture = dirname(__DIR__) . '/fixtures/foreground-owner-flock-path.php';
    $encoded = base64_encode(json_encode($config, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    $process = proc_open(
        [PHP_BINARY, $fixture, $encoded],
        [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ],
        $pipes,
        dirname(__DIR__, 2)
    );
    if (!is_resource($process)) {
        throw new RuntimeException('Could not launch the foreground flock path fixture.');
    }
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exit = proc_close($process);
    $result = is_string($stdout) ? json_decode(trim($stdout), true) : null;
    if ($exit !== 0 || !is_array($result) || !is_string($result['path'] ?? null)) {
        throw new RuntimeException("Foreground flock path probe failed.\nSTDOUT:\n{$stdout}\nSTDERR:\n{$stderr}");
    }

    return $result['path'];
}

/** @return array{path:string,acquired:bool,elapsed_ms:float,error:string} */
function wp_fts_foreground_flock_attempt(string $directory): array
{
    $fixture = dirname(__DIR__) . '/fixtures/foreground-owner-flock-holder.php';
    $process = proc_open(
        [PHP_BINARY, $fixture, $directory, 'attempt'],
        [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ],
        $pipes,
        dirname(__DIR__, 2)
    );
    if (!is_resource($process)) {
        throw new RuntimeException('Could not launch the foreground flock attempt fixture.');
    }
    fclose($pipes[0]);
    stream_set_timeout($pipes[1], 1);
    $stdout = stream_get_contents($pipes[1]);
    $timedOut = !empty(stream_get_meta_data($pipes[1])['timed_out']);
    if ($timedOut) {
        proc_terminate($process, 9);
    }
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exit = proc_close($process);
    if ($timedOut) {
        throw new RuntimeException('Foreground flock attempt exceeded the one-second subprocess kill bound.');
    }
    $result = is_string($stdout) ? json_decode(trim($stdout), true) : null;
    if ($exit !== 0 || !is_array($result)) {
        throw new RuntimeException("Foreground flock attempt failed.\nSTDOUT:\n{$stdout}\nSTDERR:\n{$stderr}");
    }

    return [
        'path' => (string) ($result['path'] ?? ''),
        'acquired' => !empty($result['acquired']),
        'elapsed_ms' => (float) ($result['elapsed_ms'] ?? INF),
        'error' => (string) ($result['error'] ?? ''),
    ];
}

/** @param array{process:resource,pipes:array<int,resource>,metadata:array<string,mixed>} $holder */
function wp_fts_foreground_stop_flock_holder(array $holder, bool $kill): void
{
    if ($kill) {
        proc_terminate($holder['process'], 9);
    } else {
        fwrite($holder['pipes'][0], "release\n");
        fflush($holder['pipes'][0]);
    }
    foreach ($holder['pipes'] as $pipe) {
        fclose($pipe);
    }
    proc_close($holder['process']);
}

function wp_fts_foreground_flock_is_exclusively_available(string $path): bool
{
    $handle = @fopen($path, 'c+');
    if (!is_resource($handle)) {
        throw new RuntimeException('Could not open the foreground flock file for a worker probe.');
    }
    $wouldBlock = 0;
    $available = @flock($handle, LOCK_EX | LOCK_NB, $wouldBlock);
    if ($available) {
        @flock($handle, LOCK_UN);
    }
    fclose($handle);

    return $available;
}

/** @return array<int,array<string,mixed>> */
function wp_fts_foreground_bulk_scope_rows(WP_FTS_Test_WPDB $fake): array
{
    return array_values(array_filter(
        $fake->queue,
        static fn(array $row): bool => (string) ($row['kind'] ?? '') === 'scope'
    ));
}

/** Count cold-cache post classification probes without changing their result. */
final class WP_FTS_Foreground_Bulk_Cold_Post_Lookups implements ArrayAccess
{
    public int $lookups = 0;

    public function offsetExists(mixed $offset): bool
    {
        $this->lookups++;
        return false;
    }

    public function offsetGet(mixed $offset): mixed
    {
        return null;
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
    }

    public function offsetUnset(mixed $offset): void
    {
    }
}

test_case('foreground bulk mutation flock supports concurrent owners and SIGKILL recovery', function (): void {
    if (!function_exists('proc_open') || !function_exists('proc_terminate')) {
        test_pending('proc_open() and proc_terminate() are required for the cross-process flock contract.');
    }

    $directory = sys_get_temp_dir() . '/wp-fts-flock-contract-' . bin2hex(random_bytes(8));
    if (!mkdir($directory, 0777, true) && !is_dir($directory)) {
        throw new RuntimeException('Could not create the foreground flock contract directory.');
    }
    $holders = [];
    $path = '';
    try {
        $holders[] = wp_fts_foreground_flock_holder($directory);
        $holders[] = wp_fts_foreground_flock_holder($directory);
        $first = $holders[0]['metadata'];
        $second = $holders[1]['metadata'];
        $path = (string) $first['path'];

        assert_same($path, $second['path'] ?? null, 'independent foreground processes must select the identical fixed lock path');
        assert_same($first['device'] ?? null, $second['device'] ?? null, 'independent foreground processes must open the same filesystem device');
        assert_same($first['inode'] ?? null, $second['inode'] ?? null, 'independent foreground processes must share one never-replaced lock inode');
        assert_same(false, wp_fts_foreground_flock_is_exclusively_available($path), 'two concurrent shared request owners must exclude a worker claim');

        $firstHolder = array_shift($holders);
        wp_fts_foreground_stop_flock_holder($firstHolder, false);
        assert_same(false, wp_fts_foreground_flock_is_exclusively_available($path), 'gracefully releasing one request must not expose another live request\'s fences');

        $secondHolder = array_shift($holders);
        wp_fts_foreground_stop_flock_holder($secondHolder, true);
        $available = false;
        $deadline = microtime(true) + 3.0;
        do {
            $available = wp_fts_foreground_flock_is_exclusively_available($path);
            if (!$available) {
                usleep(10000);
            }
        } while (!$available && microtime(true) < $deadline);
        assert_same(true, $available, 'SIGKILL must release the final request guard through kernel descriptor cleanup');
        assert_same(true, is_file($path), 'release and process death must preserve the fixed inode instead of unlinking it');
    } finally {
        foreach ($holders as $holder) {
            wp_fts_foreground_stop_flock_holder($holder, true);
        }
        if ($path !== '') {
            @unlink($path);
        }
        @rmdir($directory);
    }
});

test_case('foreground bulk mutation hostile exclusive owner fails shared acquisition within 250 milliseconds', function (): void {
    if (!function_exists('proc_open') || !function_exists('proc_terminate')) {
        test_pending('proc_open() and proc_terminate() are required for the hostile exclusive flock contract.');
    }

    $directory = sys_get_temp_dir() . '/wp-fts-flock-exclusive-' . bin2hex(random_bytes(8));
    if (!mkdir($directory, 0777, true) && !is_dir($directory)) {
        throw new RuntimeException('Could not create the hostile exclusive flock directory.');
    }
    $holder = null;
    $path = '';
    try {
        $holder = wp_fts_foreground_flock_holder($directory, 'exclusive');
        $path = (string) ($holder['metadata']['path'] ?? '');
        $attempt = wp_fts_foreground_flock_attempt($directory);
        assert_same($path, $attempt['path'], 'the hostile worker and foreground attempt must address the identical inode path');
        assert_same(false, $attempt['acquired'], 'a hostile exclusive worker must make foreground shared acquisition fail closed');
        assert_true($attempt['elapsed_ms'] <= 250.0, 'foreground shared acquisition must fail within the hard 250 ms wall bound; measured ' . $attempt['elapsed_ms'] . ' ms');
        assert_contains('held by lifecycle cleanup', $attempt['error'], 'bounded acquisition failure must identify lifecycle contention instead of hanging');
    } finally {
        if (is_array($holder)) {
            wp_fts_foreground_stop_flock_holder($holder, true);
        }
        if ($path !== '') {
            @unlink($path);
        }
        @rmdir($directory);
    }
});

test_case('foreground bulk mutation owner guard rejects path replacement and symbolic lock aliases', function (): void {
    $directory = sys_get_temp_dir() . '/wp-fts-flock-replacement-' . bin2hex(random_bytes(8));
    if (!mkdir($directory, 0777, true) && !is_dir($directory)) {
        throw new RuntimeException('Could not create the flock replacement fixture directory.');
    }
    $path = $directory . '/owner.lock';
    $replacement = $directory . '/replacement.lock';
    $symlink = $directory . '/symbolic.lock';
    $handle = null;
    try {
        file_put_contents($path, '');
        $queue = new WP_FTS_Index_Queue(new WP_FTS_Test_WPDB());
        $open = new ReflectionMethod(WP_FTS_Index_Queue::class, 'open_foreground_owner_guard');
        $matches = new ReflectionMethod(WP_FTS_Index_Queue::class, 'foreground_owner_guard_handle_matches_path');
        $handle = $open->invoke($queue, $path);
        assert_true(is_resource($handle), 'the replacement fixture must begin with one validated regular lock descriptor');

        unlink($path);
        file_put_contents($path, 'replacement inode');
        assert_same(false, $matches->invoke($queue, $handle, $path), 'a descriptor opened before path replacement must fail the final inode validation');
        fclose($handle);
        $handle = null;

        file_put_contents($replacement, '');
        if (!function_exists('symlink') || !@symlink($replacement, $symlink)) {
            test_pending('symlink() is required for the symbolic owner-guard alias contract.');
        }
        assert_same(null, $open->invoke($queue, $symlink), 'a symbolic lock path must fail closed instead of aliasing an attacker-selected inode');
    } finally {
        if (is_resource($handle)) {
            fclose($handle);
        }
        @unlink($symlink);
        @unlink($replacement);
        @unlink($path);
        @rmdir($directory);
    }
});

test_case('foreground bulk mutation path replacement after acquisition fails every later boundary closed', function (): void {
    if (getenv('WP_FTS_TEST_PATH_REPLACEMENT_CHILD') !== '1') {
        if (!function_exists('proc_open')) {
            test_pending('proc_open() is required for the isolated acquired-path replacement contract.');
        }
        $lockDirectory = sys_get_temp_dir() . '/wp-fts-acquired-replacement-' . bin2hex(random_bytes(8));
        if (!mkdir($lockDirectory, 0770, true) && !is_dir($lockDirectory)) {
            throw new RuntimeException('Could not create the acquired-path replacement lock directory.');
        }
        $environment = getenv();
        if (!is_array($environment)) {
            $environment = [];
        }
        $process = proc_open(
            [PHP_BINARY, dirname(__DIR__, 2) . '/tests/run.php'],
            [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $pipes,
            dirname(__DIR__, 2),
            array_merge($environment, [
                'WP_FTS_TEST_FILTER' => 'path replacement after acquisition fails every later boundary closed',
                'WP_FTS_MIN_CHECKS' => '0',
                'WP_FTS_TEST_PATH_REPLACEMENT_CHILD' => '1',
                'WP_FTS_TEST_FOREGROUND_LOCK_DIR' => $lockDirectory,
            ])
        );
        if (!is_resource($process)) {
            @rmdir($lockDirectory);
            throw new RuntimeException('Could not launch the acquired-path replacement contract.');
        }
        fclose($pipes[0]);
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($process);
        foreach ((array) glob($lockDirectory . '/*') as $entry) {
            @unlink((string) $entry);
        }
        @rmdir($lockDirectory);
        assert_same(0, $exit, "the acquired-path replacement subprocess must pass\nSTDOUT:\n{$stdout}\nSTDERR:\n{$stderr}");
        assert_contains(
            '[PASS] foreground bulk mutation path replacement after acquisition fails every later boundary closed',
            $stdout,
            'the subprocess must execute the acquired-path replacement assertions'
        );
        return;
    }

    global $wpdb;

    $oldWpdb = $wpdb ?? null;
    $fake = new WP_FTS_Test_WPDB();
    $fake->recordReadQueries = true;
    $wpdb = $fake;
    wp_fts_test_reset_wordpress_fakes();
    $firstPostId = 48451;
    $secondPostId = 48452;
    try {
        WP_FTS_Plugin::handle_post_pre_update($firstPostId, []);
        $first = $fake->queue[$firstPostId] ?? [];
        $owner = wp_fts_foreground_bulk_static('foreground_owner_guard');
        $guard = is_array($owner) ? ($owner['guard'] ?? null) : null;
        $path = is_array($guard) ? (string) ($guard['path'] ?? '') : '';
        assert_same('guarded', $first['state'] ?? null, 'the initial canonical boundary must be backed by the acquired inode');
        assert_true($path !== '' && is_file($path), 'the acquired request guard must expose one regular lock path');

        assert_same(true, unlink($path), 'the fixture must replace the path after the descriptor was acquired');
        assert_true(file_put_contents($path, 'replacement inode') !== false, 'the fixture must install a distinct replacement inode');

        // A second distinct target crosses the ordinary global-bulk boundary.
        // Revalidation must reject the old descriptor and stop before another
        // queue write; search remains fail-closed through the durable latch.
        $queriesBefore = count($fake->queries);
        WP_FTS_Plugin::handle_post_pre_update($secondPostId, []);
        $scopes = wp_fts_foreground_bulk_scope_rows($fake);
        assert_same([], $scopes, 'the later boundary must not persist ownerless work after inode replacement');
        assert_same($queriesBefore, count($fake->queries), 'the later boundary must execute zero queue SQL after losing its capability');
        assert_same(null, wp_fts_foreground_bulk_static('foreground_owner_guard'), 'inode replacement must retire the invalid request capability');
        $health = $GLOBALS['wp_fts_test_options'][WP_FTS_Plugin::INDEX_HEALTH_OPTION] ?? [];
        assert_same(true, !empty($health['foreground_owner_guard_blocked']), 'inode replacement must persist the explicit operator-recovery latch');
        assert_same(true, !empty($health['search_runtime_failure_latched']), 'inode replacement must revoke plugin search readiness');

        $queue = new WP_FTS_Index_Queue($fake);
        $fake->queue[$firstPostId]['available_at'] = time() - 1;
        $fake->queries = [];
        $fake->prepared = [];
        $claims = $queue->claim_batch(100, time(), 30);
        assert_same(2, count($fake->queries), 'replacement recovery must remain one bounded claim and confirmation pair');
        assert_same([$firstPostId], array_column($claims, 'post_id'), 'the pre-replacement guarded row may recover automatically');
        assert_same([], array_values(array_filter(
            $claims,
            static fn(array $claim): bool => ($claim['kind'] ?? '') === 'scope'
        )), 'capability loss must not leave any synthetic scope to recover');
        $claimStatements = array_values(array_filter(
            $fake->prepared,
            static fn(array $prepared): bool => str_contains((string) ($prepared['sql'] ?? ''), 'wp_fts:claim-batch')
        ));
        $claimSql = (string) ($claimStatements[0]['sql'] ?? '');
        assert_true(!str_contains($claimSql, "state = 'fenced'"), 'a free worker must omit the post-replacement state from candidate and CAS arms');
        assert_true(!str_contains($claimSql, 'claim_token LIKE'), 'replacement recovery must not classify ownership by scanning token text');
    } finally {
        WP_FTS_Plugin::reset_request_caches();
        $wpdb = $oldWpdb;
    }
});

test_case('foreground bulk mutation SQLite lock paths bind canonical database identity independently of cwd', function (): void {
    if (!function_exists('proc_open')) {
        test_pending('proc_open() is required for isolated SQLite path-identity contracts.');
    }

    $root = sys_get_temp_dir() . '/wp-fts-flock-path-' . bin2hex(random_bytes(8));
    $lockDirectory = $root . '/locks';
    $databaseDirectory = $root . '/databases';
    $aliasDirectory = $root . '/aliases';
    $cwdA = $root . '/cwd-a';
    $cwdB = $root . '/cwd-b';
    foreach ([$lockDirectory, $databaseDirectory, $aliasDirectory, $cwdA, $cwdB] as $directory) {
        if (!mkdir($directory, 0777, true) && !is_dir($directory)) {
            throw new RuntimeException('Could not create the SQLite path-identity fixture directory.');
        }
    }

    try {
        $overrideA = wp_fts_foreground_flock_path([
            'WP_FTS_FOREGROUND_LOCK_DIR' => $lockDirectory,
            'FQDB' => $databaseDirectory . '/site-a.sqlite',
        ]);
        $overrideB = wp_fts_foreground_flock_path([
            'WP_FTS_FOREGROUND_LOCK_DIR' => $lockDirectory,
            'FQDB' => $databaseDirectory . '/site-b.sqlite',
        ]);
        assert_same(realpath($lockDirectory), dirname($overrideA), 'an explicit shared lock directory must control placement for SQLite');
        assert_same(dirname($overrideA), dirname($overrideB), 'different SQLite databases may share the explicit lock directory');
        assert_true($overrideA !== $overrideB, 'different canonical SQLite database identities must never collide inside one override directory');

        $relativeA = wp_fts_foreground_flock_path([
            'DB_FILE' => 'site-relative.sqlite',
            'DB_DIR' => $databaseDirectory,
            'cwd' => $cwdA,
        ]);
        $relativeB = wp_fts_foreground_flock_path([
            'DB_FILE' => 'site-relative.sqlite',
            'DB_DIR' => $databaseDirectory,
            'cwd' => $cwdB,
        ]);
        assert_same($relativeA, $relativeB, 'relative DB_FILE plus DB_DIR must select one deterministic path across process working directories');
        assert_same(realpath($databaseDirectory), dirname($relativeA), 'relative SQLite database guards should live adjacent to the canonical DB_DIR');

        $canonicalDatabase = $databaseDirectory . '/canonical.sqlite';
        $databaseAlias = $aliasDirectory . '/site-alias.sqlite';
        file_put_contents($canonicalDatabase, 'sqlite identity fixture');
        if (!function_exists('symlink') || !@symlink($canonicalDatabase, $databaseAlias)) {
            test_pending('symlink() is required for the SQLite database alias identity contract.');
        }
        $canonicalPath = wp_fts_foreground_flock_path(['FQDB' => $canonicalDatabase]);
        $aliasPath = wp_fts_foreground_flock_path(['FQDB' => $databaseAlias]);
        assert_same($canonicalPath, $aliasPath, 'an existing SQLite file and a symbolic alias must share one canonical owner-guard identity');
        assert_same(realpath($databaseDirectory), dirname($aliasPath), 'a SQLite symlink alias must place its guard beside the real database rather than the alias');
    } finally {
        @unlink($databaseAlias ?? '');
        @unlink($canonicalDatabase ?? '');
        @rmdir($cwdA);
        @rmdir($cwdB);
        @rmdir($aliasDirectory);
        @rmdir($databaseDirectory);
        @rmdir($lockDirectory);
        @rmdir($root);
    }
});

test_case('foreground bulk mutation default MySQL paths use private hashed per-site directories', function (): void {
    if (!function_exists('proc_open')) {
        test_pending('proc_open() is required for isolated MySQL path-identity contracts.');
    }

    $root = sys_get_temp_dir() . '/wp-fts-mysql-flock-path-' . bin2hex(random_bytes(8));
    $contentDirectory = $root . '/wp-content';
    if (!mkdir($contentDirectory, 0777, true) && !is_dir($contentDirectory)) {
        throw new RuntimeException('Could not create the MySQL path-identity fixture directory.');
    }
    $directories = [];
    try {
        $pathA = wp_fts_foreground_flock_path([
            'DB_ENGINE' => 'mysql',
            'DB_HOST' => 'db.internal:3306',
            'DB_NAME' => 'site_a',
            'WP_CONTENT_DIR' => $contentDirectory,
        ]);
        $pathB = wp_fts_foreground_flock_path([
            'DB_ENGINE' => 'mysql',
            'DB_HOST' => 'db.internal:3306',
            'DB_NAME' => 'site_b',
            'WP_CONTENT_DIR' => $contentDirectory,
        ]);
        $directoryA = dirname($pathA);
        $directoryB = dirname($pathB);
        $directories = [$directoryA, $directoryB];
        $uploadsDirectory = realpath($contentDirectory . '/uploads');
        $uploadsMode = ((int) fileperms((string) $uploadsDirectory)) & 0777;
        assert_same($uploadsDirectory, dirname($directoryA), 'default MySQL owner guards must live below the WordPress uploads directory');
        assert_true($uploadsMode !== 0700, 'creating the private runtime child must never make a missing uploads parent private');
        assert_same(0055, $uploadsMode & 0055, 'the normal uploads parent must retain group/world read and traverse permissions under the test umask');
        assert_true(preg_match('/^\.wp-fts-runtime-([a-f0-9]{32})$/D', basename($directoryA), $directoryMatch) === 1, 'the default MySQL directory must be one private hashed site namespace');
        assert_true(preg_match('/^\.wp-fts-foreground-([a-f0-9]{32})\.lock$/D', basename($pathA), $fileMatch) === 1, 'the MySQL owner filename must carry the same bounded site identity');
        assert_same($directoryMatch[1] ?? null, $fileMatch[1] ?? null, 'the private directory and lock filename must bind the identical site identity');
        assert_true($directoryA !== $directoryB && $pathA !== $pathB, 'different MySQL database identities must use different private paths');
        assert_same(0, ((int) fileperms($directoryA)) & 0077, 'only the hashed site runtime child must deny all group/world permissions');
    } finally {
        foreach ($directories as $directory) {
            @rmdir($directory);
        }
        @rmdir($contentDirectory . '/uploads');
        @rmdir($contentDirectory);
        @rmdir($root);
    }
});

test_case('foreground bulk mutation default MySQL path rejects reused nonprivate directories', function (): void {
    if (!function_exists('proc_open') || !function_exists('symlink')) {
        test_pending('proc_open() and symlink() are required for private runtime-directory contracts.');
    }

    $root = sys_get_temp_dir() . '/wp-fts-mysql-flock-private-' . bin2hex(random_bytes(8));
    $contentDirectory = $root . '/wp-content';
    $uploadsDirectory = $contentDirectory . '/uploads';
    $symlinkTarget = $root . '/redirected-runtime';
    if (!mkdir($uploadsDirectory, 0755, true) || !mkdir($symlinkTarget, 0700)) {
        throw new RuntimeException('Could not create the private-directory rejection fixture.');
    }
    $config = [
        'DB_ENGINE' => 'mysql',
        'DB_HOST' => 'db.internal:3306',
        'DB_NAME' => 'private_reuse',
        'WP_CONTENT_DIR' => $contentDirectory,
    ];
    $runtimeDirectory = '';
    $probeFailure = static function (array $probeConfig): string {
        $fixture = dirname(__DIR__) . '/fixtures/foreground-owner-flock-path.php';
        $encoded = base64_encode(json_encode($probeConfig, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        $process = proc_open(
            [PHP_BINARY, $fixture, $encoded],
            [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $pipes,
            dirname(__DIR__, 2)
        );
        if (!is_resource($process)) {
            throw new RuntimeException('Could not launch the private-directory rejection probe.');
        }
        fclose($pipes[0]);
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($process);
        assert_true($exit !== 0, 'a nonprivate default runtime directory must fail the isolated path probe');
        assert_same('', trim($stdout), 'a failed private-directory probe must not publish a usable lock path');

        return $stderr;
    };

    try {
        $path = wp_fts_foreground_flock_path($config);
        $runtimeDirectory = dirname($path);
        assert_true(rmdir($runtimeDirectory), 'the fixture must remove the initially valid private child');

        assert_true(mkdir($runtimeDirectory, 0755), 'the fixture must install one permissive reused child');
        $permissiveError = $probeFailure($config);
        assert_contains('runtime directory is not private', $permissiveError, 'a permissive reused child must fail closed explicitly');
        assert_true(rmdir($runtimeDirectory), 'the fixture must remove the permissive child before the symlink case');

        if (!symlink($symlinkTarget, $runtimeDirectory)) {
            test_pending('Could not create the default runtime-directory symlink fixture.');
        }
        $symlinkError = $probeFailure($config);
        assert_contains('runtime directory is not private', $symlinkError, 'a symbolic default runtime directory must fail closed explicitly');
    } finally {
        if ($runtimeDirectory !== '') {
            @unlink($runtimeDirectory);
            @rmdir($runtimeDirectory);
        }
        @rmdir($symlinkTarget);
        @rmdir($uploadsDirectory);
        @rmdir($contentDirectory);
        @rmdir($root);
    }
});

test_case('foreground bulk mutation owner liveness contains no database advisory lock path', function (): void {
    $sourceDirectory = dirname(__DIR__, 2) . '/src';
    $forbidden = ['GET_LOCK', 'IS_FREE_LOCK', 'IS_USED_LOCK', 'RELEASE_LOCK'];
    $violations = [];
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(
        $sourceDirectory,
        FilesystemIterator::SKIP_DOTS
    ));
    foreach ($iterator as $file) {
        if (!$file instanceof SplFileInfo || $file->getExtension() !== 'php') {
            continue;
        }
        $source = file_get_contents($file->getPathname());
        foreach ($forbidden as $function) {
            if (is_string($source) && str_contains($source, $function)) {
                $violations[] = $file->getFilename() . ':' . $function;
            }
        }
    }

    assert_same([], $violations, 'production PHP must never issue or probe a session-scoped database advisory lock');
});

test_case('foreground bulk mutation rejects multi-scope ownership and deletes the maximum one by primary-key CAS', function (): void {
    $fake = new WP_FTS_Test_WPDB();
    $queue = new WP_FTS_Index_Queue($fake);
    $now = time();
    $oversizedTokens = [];
    for ($offset = 1; $offset <= 1000; $offset++) {
        $oversizedTokens['oversized-owned-scope-' . $offset] = hash('sha256', 'oversized-owned-token-' . $offset);
    }

    $fake->queries = [];
    $rejected = null;
    try {
        $queue->handoff_foreground_mutation_scope(
            'oversized-global-sentinel',
            str_repeat('g', 32),
            [],
            [],
            $oversizedTokens,
            true,
            [],
            $now,
            str_repeat('1', 32)
        );
    } catch (Throwable $error) {
        $rejected = $error;
    }
    assert_true($rejected instanceof InvalidArgumentException, 'an impossible 1,000-scope handoff must be rejected before SQL rather than compiled into a large delete');
    assert_same([], $fake->queries, 'rejecting ownership above the structural one-scope maximum must issue no SQL');

    $targetedKey = 'maximum-owned-targeted-scope';
    $targetedToken = str_repeat('t', 32);
    $globalKey = 'maximum-owned-global-sentinel';
    $globalToken = str_repeat('g', 32);
    $queue->fence_scope(
        $targetedKey,
        $targetedToken,
        [],
        $now + 300,
        WP_FTS_Index_Queue::SCOPE_COVERAGE_TARGETED,
        'term_taxonomy',
        91
    );
    $queue->promote_scope(
        $targetedKey,
        $targetedToken,
        [],
        $now,
        WP_FTS_Index_Queue::SCOPE_COVERAGE_TARGETED,
        'term_taxonomy',
        91
    );
    $queue->fence_scope(
        $globalKey,
        $globalToken,
        [],
        $now + 300,
        WP_FTS_Index_Queue::SCOPE_COVERAGE_GLOBAL
    );

    $fake->queries = [];
    $fake->prepared = [];
    $queue->handoff_foreground_mutation_scope(
        $globalKey,
        $globalToken,
        [],
        [],
        [$targetedKey => $targetedToken],
        true,
        [],
        $now,
        str_repeat('1', 32)
    );

    $writes = array_values(array_filter(
        array_map('wp_fts_foreground_bulk_sql', $fake->queries),
        static fn(string $sql): bool => str_contains($sql, 'wp_fts:foreground-owned-scope-delete')
    ));
    assert_same(1, count($writes), 'the maximum one owned targeted generation must retire in one SQL statement');
    assert_contains('WHERE job_key = %s AND claim_token = %s', $writes[0], 'the delete must be one primary-key plus ownership-token CAS');
    assert_true(!str_contains($writes[0], 'JOIN '), 'the one-row delete must not create a derived relation or join');
    assert_true(!str_contains($writes[0], 'UNION '), 'the one-row delete must not compile a branch per possible target');
    assert_true(strlen($writes[0]) < 512, 'the exact maximum delete should remain a tiny fixed-shape statement');
    $deletePrepared = array_values(array_filter(
        $fake->prepared,
        static fn(array $prepared): bool => str_contains((string) ($prepared['sql'] ?? ''), 'wp_fts:foreground-owned-scope-delete')
    ));
    assert_same(
        ['scope:' . hash('sha256', $targetedKey), $targetedToken],
        $deletePrepared[0]['args'] ?? null,
        'the delete must address exactly the request-owned primary key and token'
    );
    $remainingScopes = wp_fts_foreground_bulk_scope_rows($fake);
    assert_same(1, count($remainingScopes), 'the covered targeted generation and request sentinel must leave only canonical corpus debt');
    assert_same(WP_FTS_Index_Queue::SCOPE_COVERAGE_CORPUS, $remainingScopes[0]['scope_coverage'] ?? null, 'the one remaining row must be canonical corpus work');
});

test_case('foreground bulk mutation post lifecycles stay constant at 1 2 1000 and 1001 targets', function (): void {
    global $wpdb;

    $oldWpdb = $wpdb ?? null;
    try {
        foreach ([1, 2, 1000, 1001] as $cardinality) {
            $fake = new WP_FTS_Test_WPDB();
            $fake->recordReadQueries = true;
            $wpdb = $fake;
            wp_fts_test_reset_wordpress_fakes();
            // Measure the mutation frontier itself. The analyzer/profile cache
            // is request-global runtime infrastructure and has its own cold
            // peak-memory gate in the worst-case runner.
            WP_FTS_Plugin::runtime_analyzer();
            $memoryBefore = memory_get_usage(false);
            for ($offset = 1; $offset <= $cardinality; $offset++) {
                $postId = 30000 + $offset;
                WP_FTS_Plugin::handle_post_pre_update($postId, []);
                WP_FTS_Plugin::handle_post_save($postId, (object) ['ID' => $postId]);
            }
            $memoryBeforeHandoff = memory_get_usage(false) - $memoryBefore;
            $retainedTargets = wp_fts_foreground_bulk_static('foreground_mutation_targets');
            $retainedPosts = wp_fts_foreground_bulk_static('foreground_mutation_posts');
            $bulkBeforeHandoff = wp_fts_foreground_bulk_static('foreground_bulk_mutation_scope');

            assert_true(count($retainedTargets) <= 1000, "{$cardinality} post lifecycles must retain at most 1,000 target identities");
            assert_true(count($retainedPosts) <= 1000, "{$cardinality} post lifecycles must retain at most 1,000 post ids");
            assert_true($memoryBeforeHandoff < 1048576, "{$cardinality} post lifecycles must retain less than one MiB before durable handoff (measured {$memoryBeforeHandoff} bytes)");
            assert_same($cardinality > 1000, !empty($bulkBeforeHandoff['overflow']), "{$cardinality} post lifecycles should cross the exact overflow boundary only at 1,001");

            WP_FTS_Plugin::flush_foreground_bulk_mutations();
            $writes = wp_fts_foreground_bulk_work_writes($fake);
            $expectedStatements = $cardinality === 1 ? 2 : 4;
            assert_same($expectedStatements, count($writes), "{$cardinality} post lifecycles must use the exact bounded FTS statement count");
            assert_true(max(array_map('strlen', $writes)) < 1048576, "{$cardinality} post lifecycles must keep every FTS statement below one MiB");
            $totalSql = wp_fts_foreground_bulk_total_sql($fake);
            $expectedTotalSql = $cardinality === 1 ? 2 : 5;
            assert_same($expectedTotalSql, count($totalSql), "{$cardinality} post lifecycles must cap total FTS SQL without separate lock round trips");
            assert_true(max(array_map('strlen', $totalSql)) < 1048576, "{$cardinality} post lifecycles must keep every read or write below one MiB");
            assert_same(0, count(wp_fts_foreground_bulk_static('foreground_mutation_targets')), "{$cardinality} post lifecycle handoff must release retained target memory");
            assert_same(0, count(wp_fts_foreground_bulk_static('foreground_mutation_posts')), "{$cardinality} post lifecycle handoff must release retained post memory");

            if ($cardinality <= 1000) {
                assert_same($cardinality, count(wp_fts_test_queue_ids($fake)), "{$cardinality} exact post lifecycles must hand off every retained id");
                assert_same([], wp_fts_foreground_bulk_scope_rows($fake), "{$cardinality} exact post lifecycles must remove the request-global sentinel");
            } else {
                $scopeRows = wp_fts_foreground_bulk_scope_rows($fake);
                assert_same(1, count($scopeRows), 'the 1,001st post target must promote one corpus scope instead of expanding SQL or memory');
                assert_same('ready', $scopeRows[0]['state'] ?? null, 'overflow corpus reconciliation must be immediately claimable after shutdown');
            }
        }
    } finally {
        WP_FTS_Plugin::reset_request_caches();
        $wpdb = $oldWpdb;
    }
});

test_case('foreground bulk mutation taxonomy scopes stay constant at 1 2 1000 and 1001 targets', function (): void {
    global $wpdb;

    $oldWpdb = $wpdb ?? null;
    try {
        foreach ([1, 2, 1000, 1001] as $cardinality) {
            $fake = new WP_FTS_Test_WPDB();
            $fake->recordReadQueries = true;
            $wpdb = $fake;
            wp_fts_test_reset_wordpress_fakes();
            WP_FTS_Plugin::runtime_analyzer();
            $memoryBefore = memory_get_usage(false);
            for ($termId = 1; $termId <= $cardinality; $termId++) {
                WP_FTS_Plugin::handle_taxonomy_term_pre_edit(
                    $termId,
                    'category',
                    ['term_taxonomy_id' => 50000 + $termId]
                );
                WP_FTS_Plugin::handle_taxonomy_term_edit(
                    $termId,
                    50000 + $termId,
                    'category',
                    []
                );
            }
            $memoryBeforeHandoff = memory_get_usage(false) - $memoryBefore;
            $retainedTargets = wp_fts_foreground_bulk_static('foreground_mutation_targets');
            $retainedPosts = wp_fts_foreground_bulk_static('foreground_mutation_posts');
            $bulkBeforeHandoff = wp_fts_foreground_bulk_static('foreground_bulk_mutation_scope');
            $directScopeKeys = wp_fts_foreground_bulk_static('foreground_direct_scope_keys');

            assert_true(count($retainedTargets) <= 2, "{$cardinality} scope lifecycles must stop retaining target identities once the corpus fence is authoritative");
            assert_same([], $retainedPosts, "{$cardinality} scope lifecycles must not retain a PHP post-id frontier");
            assert_true($memoryBeforeHandoff < 1048576, "{$cardinality} scope lifecycles must retain less than one MiB before durable handoff (measured {$memoryBeforeHandoff} bytes)");
            assert_same(false, !empty($bulkBeforeHandoff['overflow']), "{$cardinality} scope lifecycles should never reach identity overflow after corpus coalescing");
            assert_true(count($directScopeKeys) <= WP_FTS_Index_Queue::MAX_FOREGROUND_DIRECT_SCOPES, "{$cardinality} scope lifecycles must never own more than one direct scope generation");

            WP_FTS_Plugin::flush_foreground_bulk_mutations();
            $writes = wp_fts_foreground_bulk_work_writes($fake);
            $expectedStatements = $cardinality === 1 ? 2 : 4;
            assert_same($expectedStatements, count($writes), "{$cardinality} scope lifecycles must use the exact bounded FTS statement count");
            assert_true(max(array_map('strlen', $writes)) < 1048576, "{$cardinality} scope lifecycles must keep every FTS statement below one MiB");
            $totalSql = wp_fts_foreground_bulk_total_sql($fake);
            assert_same($cardinality === 1 ? 2 : 6, count($totalSql), "{$cardinality} scope lifecycles must cap total FTS SQL without separate lock round trips");
            assert_true(max(array_map('strlen', $totalSql)) < 1048576, "{$cardinality} scope lifecycles must keep every read or write below one MiB");
            assert_same(0, count(wp_fts_foreground_bulk_static('foreground_mutation_targets')), "{$cardinality} scope lifecycle handoff must release retained target memory");
            if ($cardinality === 1) {
                assert_same(1, count(wp_fts_foreground_bulk_scope_rows($fake)), 'one scope lifecycle should retain its one targeted background job');
            } else {
                $scopeRows = wp_fts_foreground_bulk_scope_rows($fake);
                assert_same(1, count($scopeRows), "{$cardinality} scope lifecycles must replace its one owned targeted generation and sentinel with one canonical corpus job");
                $globalRows = array_values(array_filter(
                    $scopeRows,
                    static fn(array $row): bool => (string) ($row['scope_subject_type'] ?? '') === ''
                ));
                assert_same(1, count($globalRows), 'scope fan-out must create exactly one global visibility job');
                assert_same('ready', $globalRows[0]['state'] ?? null, 'scope fan-out must be immediately claimable after shutdown');
            }
        }
    } finally {
        WP_FTS_Plugin::reset_request_caches();
        $wpdb = $oldWpdb;
    }
});

test_case('foreground bulk mutation corpus authority contains 100000 heterogeneous hook lifecycles', function (): void {
    global $wpdb;

    $oldWpdb = $wpdb ?? null;
    $fake = new WP_FTS_Test_WPDB();
    $fake->recordReadQueries = true;
    $wpdb = $fake;
    wp_fts_test_reset_wordpress_fakes();

    // A filter-selected metadata dependency makes every distinct key relevant.
    // Define a counted cold get_term() only when this focused test runs; the
    // shared harness intentionally has no WordPress term API by default.
    $GLOBALS['wp_fts_test_filters']['wp_fts_post_custom_fields'] = static fn(array $keys): array => $keys;
    if (!function_exists('get_term')) {
        function get_term(int $term_id, string $taxonomy = ''): mixed
        {
            $GLOBALS['wp_fts_foreground_bulk_get_term_lookups'] =
                (int) ($GLOBALS['wp_fts_foreground_bulk_get_term_lookups'] ?? 0) + 1;
            return null;
        }
    }
    $GLOBALS['wp_fts_foreground_bulk_get_term_lookups'] = 0;
    $revisionLookups = new WP_FTS_Foreground_Bulk_Cold_Post_Lookups();
    $autosaveLookups = new WP_FTS_Foreground_Bulk_Cold_Post_Lookups();
    $GLOBALS['wp_fts_test_revisions'] = $revisionLookups;
    $GLOBALS['wp_fts_test_autosaves'] = $autosaveLookups;

    try {
        // Keep this gate about hook fan-out, not the request-global analyzer's
        // separately tested cold allocation.
        WP_FTS_Plugin::runtime_analyzer();
        $memoryBefore = memory_get_usage(false);
        if (function_exists('memory_reset_peak_usage')) {
            memory_reset_peak_usage();
        }

        $maxMetadataKeys = 0;
        $authoritySql = null;
        $authorityQueries = null;
        $authorityCoreLookups = null;
        for ($offset = 0; $offset < 100000; $offset++) {
            $metaKey = 'selected_global_bulk_key_' . $offset;
            WP_FTS_Plugin::handle_post_meta_pre_delete([], 0, $metaKey);
            $maxMetadataKeys = max(
                $maxMetadataKeys,
                count(wp_fts_foreground_bulk_static('post_meta_global_mutations'))
            );
            WP_FTS_Plugin::handle_post_meta_change(900000 + $offset, 0, $metaKey);

            if ($offset === 2) {
                assert_same([], wp_fts_foreground_bulk_static('post_meta_global_mutations'), 'the third global metadata key must publish corpus authority and clear the two-key request map');
                assert_same(true, !empty(wp_fts_foreground_bulk_static('foreground_bulk_mutation_scope')['requires_corpus']), 'the third global key must make the request-global fence corpus-authoritative');
                $authoritySql = count(wp_fts_foreground_bulk_total_sql($fake));
                $authorityQueries = count($fake->queries);
                $authorityCoreLookups = (int) $GLOBALS['wp_fts_foreground_bulk_get_term_lookups']
                    + $revisionLookups->lookups
                    + $autosaveLookups->lookups;
            }
        }

        assert_true($maxMetadataKeys <= 2, "100,000 distinct global metadata lifecycles must retain at most two metadata keys; observed {$maxMetadataKeys}");
        assert_same([], wp_fts_foreground_bulk_static('post_meta_global_mutations'), 'corpus authority must retain no metadata-key suffix after 100,000 distinct hooks');
        assert_same($authoritySql, count(wp_fts_foreground_bulk_total_sql($fake)), 'the 99,997-key metadata suffix must add zero FTS statements after corpus authority');
        assert_same($authorityQueries, count($fake->queries), 'the 99,997-key metadata suffix must add zero plugin-caused database queries after corpus authority');

        // Alternate cold-cache unmatched hooks with direct term-taxonomy ids.
        // Both paths must stop before get_term() or per-term boundary maps once
        // the canonical corpus row is already the request authority.
        for ($offset = 0; $offset < 100000; $offset++) {
            $termId = 1000000 + $offset;
            $args = ($offset & 1) === 0
                ? []
                : ['term_taxonomy_id' => 2000000 + $offset];
            WP_FTS_Plugin::handle_taxonomy_term_pre_edit($termId, 'category', $args);
        }
        assert_same([], wp_fts_foreground_bulk_static('taxonomy_term_global_pre_boundaries'), '100,000 unmatched and direct taxonomy pre-hooks must retain no per-term map behind corpus authority');
        assert_same($authoritySql, count(wp_fts_foreground_bulk_total_sql($fake)), '100,000 taxonomy pre-hooks must add zero FTS statements behind corpus authority');
        assert_same($authorityQueries, count($fake->queries), '100,000 taxonomy pre-hooks must add zero plugin-caused database queries behind corpus authority');

        // Exercise the cold post-id path rather than supplying post objects.
        // Distinct ids prove both the WordPress classifiers and queue writes
        // remain independent of fan-out; the in-memory post frontier has its
        // own explicit 1,000-identity structural bound.
        for ($offset = 0; $offset < 100000; $offset++) {
            $postId = 3000000 + $offset;
            WP_FTS_Plugin::handle_term_relationship_pre_change($postId);
            WP_FTS_Plugin::handle_term_relationship_change($postId);
            WP_FTS_Plugin::handle_post_pre_update($postId, []);
            WP_FTS_Plugin::handle_post_save($postId);
        }
        assert_same([], wp_fts_foreground_bulk_static('relationship_pre_mutations'), 'relationship pre-hooks behind the bulk fence must retain no per-post lifecycle map');
        assert_same([], wp_fts_foreground_bulk_static('relationship_post_mutations'), 'relationship post-hooks behind the bulk fence must retain no per-post lifecycle map');
        assert_true(count(wp_fts_foreground_bulk_static('foreground_mutation_targets')) <= 1000, '100,000 relationship/post lifecycles must retain at most the structural 1,000-target frontier');
        assert_true(count(wp_fts_foreground_bulk_static('foreground_mutation_posts')) <= 1000, '100,000 relationship/post lifecycles must retain at most the structural 1,000-post frontier');
        assert_same($authoritySql, count(wp_fts_foreground_bulk_total_sql($fake)), '100,000 relationship/post lifecycles must add zero FTS statements behind the bulk fence');
        assert_same($authorityQueries, count($fake->queries), '100,000 relationship/post lifecycles must add zero plugin-caused database queries behind the bulk fence');

        $getTermLookups = (int) $GLOBALS['wp_fts_foreground_bulk_get_term_lookups'];
        $coreLookups = $getTermLookups + $revisionLookups->lookups + $autosaveLookups->lookups;
        assert_true($getTermLookups <= 2, "100,000 taxonomy hooks should require at most two cold get_term() lookups; observed {$getTermLookups}");
        assert_true($revisionLookups->lookups <= 2, "100,000 relationship/post hooks should require at most two revision lookups; observed {$revisionLookups->lookups}");
        assert_true($autosaveLookups->lookups <= 2, "100,000 relationship/post hooks should require at most two autosave lookups; observed {$autosaveLookups->lookups}");
        assert_true($coreLookups <= 2, "all 200,000 cold-cache taxonomy and post lifecycles should require at most two core lookups total; observed {$coreLookups}");
        assert_same((int) $authorityQueries + (int) $authorityCoreLookups, count($fake->queries) + $coreLookups, 'the observable core-plus-FTS query frontier must remain constant after corpus authority');

        $retainedMemory = max(0, memory_get_usage(false) - $memoryBefore);
        $peakMemory = max(0, memory_get_peak_usage(false) - $memoryBefore);
        assert_true($retainedMemory <= 2 * 1024 * 1024, "300,000 worst-case hook lifecycles must retain at most 2 MiB; observed {$retainedMemory} bytes");
        assert_true($peakMemory <= 4 * 1024 * 1024, "300,000 worst-case hook lifecycles must peak within 4 MiB above the warm baseline; observed {$peakMemory} bytes");

        WP_FTS_Plugin::flush_foreground_bulk_mutations();
        assert_same((int) $authoritySql + 3, count(wp_fts_foreground_bulk_total_sql($fake)), 'the complete 300,000-lifecycle request must add exactly three fixed corpus publish, sentinel-delete, and owned-scope-delete statements');
        assert_same([], wp_fts_foreground_bulk_static('foreground_mutation_targets'), 'the corpus handoff must release the bounded target frontier');
        assert_same([], wp_fts_foreground_bulk_static('foreground_mutation_posts'), 'the corpus handoff must release the bounded post frontier');
        assert_same([], wp_fts_foreground_bulk_static('taxonomy_term_global_pre_boundaries'), 'the corpus handoff must leave the taxonomy boundary map empty');
        assert_same([], wp_fts_foreground_bulk_static('post_meta_global_mutations'), 'the corpus handoff must leave the metadata boundary map empty');
    } finally {
        $GLOBALS['wp_fts_test_revisions'] = [];
        $GLOBALS['wp_fts_test_autosaves'] = [];
        unset($GLOBALS['wp_fts_foreground_bulk_get_term_lookups']);
        WP_FTS_Plugin::reset_request_caches();
        $wpdb = $oldWpdb;
    }
});

test_case('foreground bulk mutation exact fence survives TTL and recovers current canonical content', function (): void {
    global $wpdb;

    $oldWpdb = $wpdb ?? null;
    $fake = new WP_FTS_Test_WPDB();
    $fake->recordReadQueries = true;
    $wpdb = $fake;
    wp_fts_test_reset_wordpress_fakes();
    $postId = 47001;
    $directPostId = 47002;
    $oldContent = 'OwnerGuardOldCanonicalProjection';
    $newContent = 'OwnerGuardNewCanonicalProjection';
    $GLOBALS['wp_fts_test_posts'][$postId] = (object) [
        'ID' => $postId,
        'post_title' => '',
        'post_content' => $oldContent,
        'post_excerpt' => '',
        'post_type' => 'post',
        'post_status' => 'publish',
        'post_date_gmt' => '2026-01-01 00:00:00',
        'post_password' => '',
    ];
    $GLOBALS['wp_fts_test_posts'][$directPostId] = (object) [
        'ID' => $directPostId,
        'post_title' => '',
        'post_content' => 'UnrelatedReadyProjection',
        'post_excerpt' => '',
        'post_type' => 'post',
        'post_status' => 'publish',
        'post_date_gmt' => '2026-01-01 00:00:00',
        'post_password' => '',
    ];

    try {
        WP_FTS_Plugin::handle_post_pre_update($postId, []);
        $fenced = $fake->queue[$postId] ?? [];
        $owner = wp_fts_foreground_bulk_static('foreground_owner_guard');
        $token = (string) ($fenced['claim_token'] ?? '');

        assert_same(1, count(wp_fts_foreground_bulk_total_sql($fake)), 'the first exact canonical boundary must install one durable fence statement');
        assert_same(1, count($fake->queries), 'the request owner guard must add zero database round trips');
        assert_true(is_array($owner), 'the first exact fence must retain one request-wide filesystem owner guard');
        assert_true(preg_match('/^guard:[a-f0-9]{32}$/D', $token) === 1, 'a successfully guarded exact fence must persist a typed guard token');
        assert_true((int) ($fenced['available_at'] ?? 0) >= time() + 299, 'the exact fence must retain a finite crash-recovery deadline');
        assert_true((int) ($fenced['available_at'] ?? PHP_INT_MAX) < PHP_INT_MAX, 'the exact fence must never rely on an effectively permanent timestamp');

        $farFuture = time() + 3600;
        $fake->queue[$postId]['available_at'] = $farFuture;
        $scheduleQueue = new WP_FTS_Index_Queue($fake);
        $scheduleQueriesBefore = count($fake->queries);
        $futureNextAvailable = $scheduleQueue->next_available_at();
        assert_same($scheduleQueriesBefore + 1, count($fake->queries), 'far-future guarded scheduling must remain one bounded statement');
        assert_same($farFuture, $futureNextAvailable, 'a guarded fence already later than the watchdog must retain its real due time instead of being polled early');
        $scheduleSql = wp_fts_foreground_bulk_sql($fake->queries[array_key_last($fake->queries)] ?? '');
        assert_contains('.due_at < ', $scheduleSql, 'guard watchdog projection must be conditional on the real due time being earlier');

        // Advance both clocks past the watchdog without sleeping. Duplicate
        // callbacks in the still-live request must remain in-memory only: the
        // SH owner, rather than periodic SQL, is now the liveness authority.
        $tokensProperty = new ReflectionProperty(WP_FTS_Plugin::class, 'mutation_fence_tokens');
        $tokens = $tokensProperty->getValue();
        $tokens['post:' . $postId]['expires_at'] = time() - 1;
        $tokensProperty->setValue(null, $tokens);
        $fake->queue[$postId]['available_at'] = time() - 1;
        $queriesBehindGuard = count($fake->queries);
        for ($offset = 0; $offset < 100000; $offset++) {
            WP_FTS_Plugin::handle_post_pre_update($postId, []);
        }
        assert_same($queriesBehindGuard, count($fake->queries), '100,000 duplicate exact pre-hooks beyond TTL must issue zero database queries while the request guard is live');
        assert_same($token, $fake->queue[$postId]['claim_token'] ?? null, 'the live request must retain the original exact generation rather than creating watchdog successors');

        // A worker must still make unrelated progress in exactly its ordinary
        // update+confirmation pair while excluding the overdue guarded post.
        $queue = new WP_FTS_Index_Queue($fake);
        $queue->enqueue($directPostId, time() - 1);
        $claimQueriesBefore = count($fake->queries);
        $claims = $queue->claim_batch(100, time(), 30, 1048576);
        assert_same($claimQueriesBefore + 2, count($fake->queries), 'a busy owner guard must not add a database probe beyond claim and confirmation');
        assert_same([$directPostId], array_column($claims, 'post_id'), 'the worker must claim ready unguarded work but exclude the overdue guarded exact fence');
        $claimStatements = array_values(array_filter(
            $fake->prepared,
            static fn(array $prepared): bool => str_contains((string) ($prepared['sql'] ?? ''), 'wp_fts:claim-batch')
        ));
        $claimSql = (string) ($claimStatements[array_key_last($claimStatements)]['sql'] ?? '');
        assert_true(
            substr_count($claimSql, 'wp_fts:fences-require-free-guard') >= 3,
            'a busy worker claim must exclude both protected states inside every bounded candidate and the final CAS'
        );
        assert_contains('SELECT job_key, generation FROM', $claimSql, 'the bounded claim driver must carry the observed generation with each primary key');
        assert_contains('chosen_fts_work.generation', $claimSql, 'the MySQL claim driver must preserve the selected generation through its materialized relation');
        assert_contains('claim_target.generation = claim_driver.generation', $claimSql, 'the final MySQL join must reject a generation advanced after candidate selection');
        assert_true(!str_contains($claimSql, 'GET_LOCK') && !str_contains($claimSql, 'IS_'), 'the worker claim must not call a session advisory-lock function');
        assert_true($queue->acknowledge($claims[0]), 'the unrelated ready claim should remain normally acknowledgeable');

        $watchdogStarted = time();
        $nextAvailable = $queue->next_available_at();
        $watchdogEnded = time();
        assert_true(
            is_int($nextAvailable)
                && $nextAvailable >= $watchdogStarted + WP_FTS_Index_Queue::FOREGROUND_OWNER_WATCHDOG_SECONDS
                && $nextAvailable <= $watchdogEnded + WP_FTS_Index_Queue::FOREGROUND_OWNER_WATCHDOG_SECONDS,
            'an overdue guarded exact fence must schedule one finite watchdog instead of a hot loop'
        );

        // Canonical SQL commits after the would-be watchdog. Simulate a fatal
        // request before its post-hook: reset releases the descriptor but does
        // not promote or rewrite the durable exact generation. Recovery must
        // claim that generation and snapshot the post-commit source, never the
        // value that existed when the pre-hook installed the fence.
        $GLOBALS['wp_fts_test_posts'][$postId]->post_content = $newContent;
        WP_FTS_Plugin::reset_request_caches();
        assert_same(null, wp_fts_foreground_bulk_static('foreground_owner_guard'), 'request abandonment must release its filesystem owner descriptor');
        $recoveryQueriesBefore = count($fake->queries);
        $recovered = $queue->claim_batch(100, time(), 30, 1048576);
        assert_same($recoveryQueriesBefore + 2, count($fake->queries), 'abandoned exact recovery must remain one bounded claim plus one confirmation');
        assert_same([$postId], array_column($recovered, 'post_id'), 'owner loss must expose the original exact generation without synthetic successor work');
        assert_same($newContent, $recovered[0]['source_snapshot']->post_content ?? null, 'recovery must snapshot canonical content committed after the original fence deadline');
        assert_true(($recovered[0]['source_snapshot']->post_content ?? null) !== $oldContent, 'recovery must never resurrect the stale pre-commit projection');
    } finally {
        WP_FTS_Plugin::reset_request_caches();
        unset($GLOBALS['wp_fts_test_posts'][$postId], $GLOBALS['wp_fts_test_posts'][$directPostId]);
        $wpdb = $oldWpdb;
    }
});

test_case('foreground bulk mutation second worker probe refences every interrupted claim', function (): void {
    $now = time();

    $fake = new WP_FTS_Test_WPDB();
    $fake->recordReadQueries = true;
    $queue = new WP_FTS_Index_Queue($fake);
    $queue->enqueue_many([47501], $now, ['reason' => 'batch-original']);
    $originalBatch = $fake->queue[47501];
    $guard = null;
    $fake->queries = [];
    $fake->afterQueueClaimWriteObserver = static function () use ($queue, &$guard): void {
        $guard = $queue->acquire_foreground_owner_guard();
    };
    try {
        $claims = $queue->claim_batch(10, $now, 30);
        $refencedBatch = $fake->queue[47501] ?? [];
        assert_same([], $claims, 'claim_batch must expose no work when a foreground owner starts after its update');
        assert_same(2, count($fake->queries), 'claim_batch interruption must use only its claim update and one set-oriented refence');
        assert_contains('wp_fts:refence-interrupted-claim', wp_fts_foreground_bulk_sql($fake->queries[1] ?? ''), 'claim_batch must execute the explicit interrupted-claim refence');
        assert_same('guarded', $refencedBatch['state'] ?? null, 'claim_batch must restore guarded fence state rather than leave an expiring lease');
        assert_true(preg_match('/^guard:[a-f0-9]{32}$/D', (string) ($refencedBatch['claim_token'] ?? '')) === 1, 'claim_batch refence must publish a typed guard token');
        assert_same(0, $refencedBatch['claim_expires_at'] ?? null, 'claim_batch refence must clear the obsolete worker lease expiry');
        assert_same($now, $refencedBatch['available_at'] ?? null, 'claim_batch refence must remain immediately due after owner loss');
        assert_same($originalBatch['generation'] ?? null, $refencedBatch['generation'] ?? null, 'claim_batch refence must preserve the exact generation');
        assert_same($originalBatch['payload'] ?? null, $refencedBatch['payload'] ?? null, 'claim_batch refence must preserve the exact payload');

        $fake->queries = [];
        assert_same([], $queue->claim_batch(10, $now + 31, 30), 'the live shared owner must protect the refenced batch beyond the superseded lease');
        assert_same(2, count($fake->queries), 'a busy worker must still use only one bounded claim and confirmation pair');
        assert_same('guarded', $fake->queue[47501]['state'] ?? null, 'a busy worker must not alter the refenced batch');
    } finally {
        if (is_array($guard)) {
            $queue->release_foreground_owner_guard($guard);
        }
    }
    $recoveredBatch = $queue->claim_batch(10, $now + 31, 30);
    assert_same([47501], array_column($recoveredBatch, 'post_id'), 'claim_batch refence must recover immediately after owner release');
    $queue->acknowledge_many($recoveredBatch, $now + 32);

    $fake = new WP_FTS_Test_WPDB();
    $fake->recordReadQueries = true;
    $queue = new WP_FTS_Index_Queue($fake);
    $queue->enqueue_many([47502], $now, ['reason' => 'post-original']);
    $originalPost = $fake->queue[47502];
    $guard = null;
    $fake->queries = [];
    $fake->afterQueueClaimWriteObserver = static function () use ($queue, &$guard): void {
        $guard = $queue->acquire_foreground_owner_guard();
    };
    try {
        $claims = $queue->claim(10, $now, 30);
        $refencedPost = $fake->queue[47502] ?? [];
        assert_same([], $claims, 'claim must expose no work when a foreground owner starts after its update');
        assert_same(2, count($fake->queries), 'claim interruption must use only its claim update and one set-oriented refence');
        assert_same('guarded', $refencedPost['state'] ?? null, 'claim must restore guarded fence state');
        assert_true(str_starts_with((string) ($refencedPost['claim_token'] ?? ''), 'guard:'), 'claim refence must publish a guarded token');
        assert_same(0, $refencedPost['claim_expires_at'] ?? null, 'claim refence must clear its old lease expiry');
        assert_same($originalPost['generation'] ?? null, $refencedPost['generation'] ?? null, 'claim refence must preserve generation');
        assert_same($originalPost['payload'] ?? null, $refencedPost['payload'] ?? null, 'claim refence must preserve payload');

        $fake->queries = [];
        assert_same([], $queue->claim(10, $now + 31, 30), 'the live shared owner must protect the refenced post beyond the old lease');
        assert_same(2, count($fake->queries), 'the protected refenced post must retain constant worker SQL');
    } finally {
        if (is_array($guard)) {
            $queue->release_foreground_owner_guard($guard);
        }
    }
    $recoveredPosts = $queue->claim(10, $now + 31, 30);
    assert_same([47502], array_column($recoveredPosts, 'post_id'), 'claim refence must recover immediately after owner release');
    $queue->acknowledge_many($recoveredPosts, $now + 32);

    $fake = new WP_FTS_Test_WPDB();
    $fake->recordReadQueries = true;
    $queue = new WP_FTS_Index_Queue($fake);
    $queue->enqueue_scope('late-owner-scope', ['reason' => 'scope-original'], $now);
    $scopeKey = 'scope:' . hash('sha256', 'late-owner-scope');
    $originalScope = $fake->queue[$scopeKey];
    $guard = null;
    $fake->queries = [];
    $fake->afterQueueClaimWriteObserver = static function () use ($queue, &$guard): void {
        $guard = $queue->acquire_foreground_owner_guard();
    };
    try {
        $scope = $queue->claim_scope($now, 30);
        $refencedScope = $fake->queue[$scopeKey] ?? [];
        assert_same(null, $scope, 'claim_scope must expose no work when a foreground owner starts after its update');
        assert_same(3, count($fake->queries), 'claim_scope interruption must remain selection, lease CAS, and one refence statement');
        assert_contains('wp_fts:refence-interrupted-claim', wp_fts_foreground_bulk_sql($fake->queries[2] ?? ''), 'claim_scope must execute the shared interrupted-claim refence');
        assert_same('guarded', $refencedScope['state'] ?? null, 'claim_scope must restore guarded fence state');
        assert_true(str_starts_with((string) ($refencedScope['claim_token'] ?? ''), 'guard:'), 'claim_scope refence must publish a guarded token');
        assert_same(0, $refencedScope['claim_expires_at'] ?? null, 'claim_scope refence must clear its old lease expiry');
        assert_same($originalScope['generation'] ?? null, $refencedScope['generation'] ?? null, 'claim_scope refence must preserve generation');
        assert_same($originalScope['payload'] ?? null, $refencedScope['payload'] ?? null, 'claim_scope refence must preserve payload');

        $fake->queries = [];
        assert_same(null, $queue->claim_scope($now + 31, 30), 'the live shared owner must protect the refenced scope beyond the old lease');
        assert_same(1, count($fake->queries), 'a busy scope worker must stop after its one bounded candidate read');
    } finally {
        if (is_array($guard)) {
            $queue->release_foreground_owner_guard($guard);
        }
    }
    $recoveredScope = $queue->claim_scope($now + 31, 30);
    assert_same($scopeKey, $recoveredScope['job_key'] ?? null, 'claim_scope refence must recover immediately after owner release');
    assert_true($queue->acknowledge_scope($recoveredScope, $now + 32), 'the recovered scope generation should acknowledge normally');

    // A canonical fence that wins after the interrupted lease but before the
    // synthetic refence must remain authoritative. The refence CAS is bound to
    // both the old worker token and its exact generation.
    $fake = new WP_FTS_Test_WPDB();
    $queue = new WP_FTS_Index_Queue($fake);
    $queue->enqueue_many([47503], $now, ['reason' => 'stale-worker']);
    $guard = null;
    $newToken = str_repeat('c', 32);
    $fake->afterQueueClaimWriteObserver = static function () use ($queue, &$guard): void {
        $guard = $queue->acquire_foreground_owner_guard();
    };
    $fake->queryObserver = static function (string $sql) use ($fake, $queue, $newToken, $now): void {
        if (!str_contains($sql, 'wp_fts:refence-interrupted-claim')) {
            return;
        }
        $fake->queryObserver = null;
        $queue->fence_post(47503, $newToken, $now + 300, ['reason' => 'newer-canonical']);
    };
    try {
        assert_same([], $queue->claim_batch(10, $now, 30), 'the interrupted stale claim must still return no work after a newer canonical fence wins');
        $newer = $fake->queue[47503] ?? [];
        assert_same(2, $newer['generation'] ?? null, 'the concurrent canonical fence must advance generation beyond the stale worker');
        assert_same($newToken, $newer['claim_token'] ?? null, 'the refence CAS must not replace the concurrent canonical ownership token');
        assert_same('fenced', $newer['state'] ?? null, 'the unmarked concurrent canonical generation must remain operator-only');
        assert_same(['reason' => 'newer-canonical'], json_decode((string) ($newer['payload'] ?? ''), true), 'the refence CAS must not replace the concurrent canonical payload');
        assert_same(0, $newer['claim_expires_at'] ?? null, 'the newer canonical fence must retain its non-lease state');
    } finally {
        $fake->queryObserver = null;
        if (is_array($guard)) {
            $queue->release_foreground_owner_guard($guard);
        }
    }
});

test_case('foreground bulk mutation worker latches unavailable second probe when refence persistence fails', function (): void {
    global $wpdb;

    $oldWpdb = $wpdb ?? null;
    $fake = new WP_FTS_Test_WPDB();
    $fake->recordReadQueries = true;
    $wpdb = $fake;
    wp_fts_test_reset_wordpress_fakes();
    wp_fts_test_mark_search_takeover_ready();
    $postId = 47511;
    $seedQueue = new WP_FTS_Index_Queue($fake);
    $seedQueue->enqueue_many([$postId], time() - 1, ['reason' => 'compound-second-probe-failure']);
    $original = $fake->queue[$postId] ?? [];

    // Give this destructive path-replacement adversary its own deterministic
    // site lock file. The fake queue rows are prefix-agnostic, while every SQL
    // statement and liveness probe below uses the isolated production prefix.
    $fake->prefix = 'wp_compound_guard_failure_';
    $fake->posts = $fake->prefix . 'posts';
    $fake->term_relationships = $fake->prefix . 'term_relationships';
    $probeQueue = new WP_FTS_Index_Queue($fake);
    $pathMethod = new ReflectionMethod(WP_FTS_Index_Queue::class, 'foreground_owner_guard_path');
    $pathMethod->setAccessible(true);
    $guardPath = (string) $pathMethod->invoke($probeQueue);
    if (is_dir($guardPath)) {
        @rmdir($guardPath);
    } else {
        @unlink($guardPath);
    }

    $caught = null;
    try {
        $readyBefore = WP_FTS_Plugin::search_takeover_status(false);
        assert_same(true, $readyBefore['ready'] ?? null, 'the compound worker adversary must begin with an eligible search takeover');
        $fake->queries = [];
        $fake->prepared = [];
        $fake->afterQueueClaimWriteObserver = static function () use ($fake, $guardPath): void {
            if (!@unlink($guardPath) || !@mkdir($guardPath, 0700)) {
                throw new RuntimeException('Could not replace the isolated foreground guard path after the claim write.');
            }
            $fake->failQueryPrefix = 'UPDATE /* wp_fts:refence-interrupted-claim */';
        };

        try {
            WP_FTS_Plugin::process_manual_index_batch([
                'batch_size' => 1,
                'source' => 'compound-owner-probe-failure',
            ]);
        } catch (Throwable $error) {
            $caught = $error;
        }

        $workTable = $fake->prefix . 'fts_work';
        $workSql = array_values(array_filter(
            array_map('wp_fts_foreground_bulk_sql', $fake->queries),
            static fn(string $sql): bool => str_contains($sql, $workTable)
        ));
        $claimSql = array_values(array_filter(
            $workSql,
            static fn(string $sql): bool => str_contains($sql, 'wp_fts:claim-batch')
                || str_contains($sql, 'wp_fts:refence-interrupted-claim')
        ));
        $health = $GLOBALS['wp_fts_test_options'][WP_FTS_Plugin::INDEX_HEALTH_OPTION] ?? [];
        $diagnostics = is_array($health['latest_batch_diagnostics'] ?? null)
            ? $health['latest_batch_diagnostics']
            : [];
        $takeoverAfter = WP_FTS_Plugin::search_takeover_status(false);
        $leased = $fake->queue[$postId] ?? [];

        assert_true($caught instanceof RuntimeException, 'the failed compensating refence must remain visible to the manual worker caller');
        assert_contains('Failed to refence interrupted FTS claim', $caught?->getMessage() ?? '', 'the worker must rethrow the database failure from the compensating refence');
        assert_same(2, count($claimSql), 'the compound failure path must stop after one bounded claim UPDATE and one failed set-oriented refence UPDATE');
        assert_same(2, count($workSql), 'the compound failure path must issue no work-table query beyond its two bounded UPDATE statements');
        assert_true(max(array_map('strlen', $workSql)) < 1048576, 'both compound failure statements must stay below one MiB');
        assert_contains('wp_fts:claim-batch', $workSql[0] ?? '', 'the first compound failure statement must be the bounded claim');
        assert_contains('wp_fts:refence-interrupted-claim', $workSql[1] ?? '', 'the second compound failure statement must be the compensating set-oriented refence');

        assert_same(true, $health['search_runtime_failure_latched'] ?? null, 'second-probe unavailability must persist the search runtime failure latch even when refencing throws');
        assert_contains('shared FTS foreground owner guard is unavailable', (string) ($health['last_error'] ?? ''), 'health must retain the owner-guard failure rather than letting the later database error erase it');
        assert_same('failed', $diagnostics['status'] ?? null, 'the worker must persist a failed batch diagnostic for the database error');
        assert_same('worker_storage_unavailable', $diagnostics['stop_reason'] ?? null, 'the failed refence must enter the systemic storage-unavailable retry path');
        assert_contains('Failed to refence interrupted FTS claim', (string) ($diagnostics['error_message'] ?? ''), 'batch diagnostics must record the rethrown refence database failure');
        assert_same('', $GLOBALS['wp_fts_test_options'][WP_FTS_Plugin::SEARCH_READY_INCARNATION_OPTION] ?? null, 'owner-guard unavailability must revoke the persisted search-ready capability');
        assert_same(false, $takeoverAfter['ready'] ?? null, 'owner-guard unavailability must revoke search takeover in the same worker callback');
        assert_same('index_reconciling_or_unhealthy', $takeoverAfter['reason'] ?? null, 'the revoked takeover must expose the unhealthy fail-closed reason');

        assert_same('leased', $leased['state'] ?? null, 'a failed compensating refence may leave only the original finite worker lease, never ready work');
        assert_same($original['generation'] ?? null, $leased['generation'] ?? null, 'the compound failure must not invent or lose a durable generation');
        assert_same($leased['generation'] ?? null, $leased['claimed_generation'] ?? null, 'the surviving finite lease must remain bound to its exact generation');
        assert_true((int) ($leased['claim_expires_at'] ?? 0) > time(), 'the surviving worker lease must remain finitely recoverable after the database returns');
    } finally {
        $fake->afterQueueClaimWriteObserver = null;
        $fake->failQueryPrefix = null;
        if (is_dir($guardPath)) {
            @rmdir($guardPath);
        } else {
            @unlink($guardPath);
        }
        WP_FTS_Plugin::reset_request_caches();
        $wpdb = $oldWpdb;
    }
});

test_case('foreground bulk mutation releases flock only after durable handoff and on abandonment', function (): void {
    global $wpdb;

    $oldWpdb = $wpdb ?? null;
    $activateBulk = static function (int $firstPostId, int $secondPostId): void {
        WP_FTS_Plugin::handle_post_pre_update($firstPostId, []);
        WP_FTS_Plugin::handle_post_save($firstPostId);
        WP_FTS_Plugin::handle_post_pre_update($secondPostId, []);
        WP_FTS_Plugin::handle_post_save($secondPostId);
    };

    try {
        $fake = new WP_FTS_Test_WPDB();
        $fake->recordReadQueries = true;
        $wpdb = $fake;
        wp_fts_test_reset_wordpress_fakes();
        $activateBulk(48001, 48002);
        $bulk = wp_fts_foreground_bulk_static('foreground_bulk_mutation_scope');
        $scopeKey = 'scope:' . hash('sha256', (string) ($bulk['scope_key'] ?? ''));
        $owner = wp_fts_foreground_bulk_static('foreground_owner_guard');
        $guardPath = (string) ($owner['guard']['path'] ?? '');
        assert_true($guardPath !== '', 'ordinary two-post bulk mode must hold one filesystem owner guard');
        assert_same(false, wp_fts_foreground_flock_is_exclusively_available($guardPath), 'the live bulk request must exclude worker recovery');
        assert_true((int) ($fake->queue[$scopeKey]['available_at'] ?? 0) > time(), 'ordinary bulk mode must retain a finite abandonment deadline');

        $queriesBeforeFlush = count($fake->queries);
        WP_FTS_Plugin::flush_foreground_bulk_mutations();
        $flushSql = array_slice(array_map('wp_fts_foreground_bulk_sql', $fake->queries), $queriesBeforeFlush);
        assert_same(2, count($flushSql), 'successful exact shutdown must use only its two durable handoff statements');
        assert_contains('wp_fts:foreground-post-handoff', $flushSql[0] ?? '', 'post work must become durable before owner-guard release');
        assert_contains('wp_fts:foreground-global-delete', $flushSql[1] ?? '', 'the owned global sentinel must be deleted before owner-guard release');
        assert_true(!str_contains(implode("\n", $flushSql), '_LOCK('), 'filesystem release must add no database lock statement');
        assert_same(null, wp_fts_foreground_bulk_static('foreground_owner_guard'), 'successful handoff must forget the released request owner');
        assert_same(true, wp_fts_foreground_flock_is_exclusively_available($guardPath), 'the worker guard must become free only after durable handoff completes');
        assert_true(!isset($fake->queue[$scopeKey]), 'successful handoff must leave no guarded sentinel behind');
        assert_same('ready', $fake->queue[48001]['state'] ?? null, 'the first retained post must be ready before the guard becomes free');
        assert_same('ready', $fake->queue[48002]['state'] ?? null, 'the second retained post must be ready before the guard becomes free');

        $fake = new WP_FTS_Test_WPDB();
        $fake->recordReadQueries = true;
        $wpdb = $fake;
        WP_FTS_Plugin::reset_request_caches();
        $activateBulk(48101, 48102);
        $resetBulk = wp_fts_foreground_bulk_static('foreground_bulk_mutation_scope');
        $resetScopeKey = 'scope:' . hash('sha256', (string) ($resetBulk['scope_key'] ?? ''));
        $resetOwner = wp_fts_foreground_bulk_static('foreground_owner_guard');
        $resetGuardPath = (string) ($resetOwner['guard']['path'] ?? '');
        assert_same(false, wp_fts_foreground_flock_is_exclusively_available($resetGuardPath), 'the reset fixture must begin with one live owner guard');
        $queriesBeforeReset = count($fake->queries);
        WP_FTS_Plugin::reset_request_caches();
        assert_same($queriesBeforeReset, count($fake->queries), 'request reset must release the descriptor without a database round trip');
        assert_same(true, wp_fts_foreground_flock_is_exclusively_available($resetGuardPath), 'request reset must expose abandoned finite recovery work');
        assert_same('guarded', $fake->queue[$resetScopeKey]['state'] ?? null, 'reset without handoff must leave finite durable recovery work');
        assert_true((int) ($fake->queue[$resetScopeKey]['available_at'] ?? 0) < PHP_INT_MAX, 'reset recovery work must never become a permanent fence');

        $fake = new WP_FTS_Test_WPDB();
        $fake->recordReadQueries = true;
        $wpdb = $fake;
        $activateBulk(48201, 48202);
        $switchBulk = wp_fts_foreground_bulk_static('foreground_bulk_mutation_scope');
        $switchScopeKey = 'scope:' . hash('sha256', (string) ($switchBulk['scope_key'] ?? ''));
        $switchOwner = wp_fts_foreground_bulk_static('foreground_owner_guard');
        $switchGuardPath = (string) ($switchOwner['guard']['path'] ?? '');
        assert_same(false, wp_fts_foreground_flock_is_exclusively_available($switchGuardPath), 'the blog-switch fixture must begin with one live owner guard');
        assert_same(true, wp_fts_foreground_bulk_static('foreground_owner_guard_has_ready_work'), 'the blog-switch fixture must exercise old-site ready work that would normally schedule on release');
        $queriesBeforeSwitch = count($fake->queries);
        $fake->prefix = 'wp_2_';
        $fake->posts = 'wp_2_posts';
        $newSiteCron = time() + 777;
        $GLOBALS['wp_fts_test_scheduled'][WP_FTS_Plugin::CRON_HOOK] = [
            'timestamp' => $newSiteCron,
            'hook' => WP_FTS_Plugin::CRON_HOOK,
            'args' => [],
        ];
        $scheduleCallsBeforeSwitch = count($GLOBALS['wp_fts_test_schedule_calls']);
        $clearCallsBeforeSwitch = count($GLOBALS['wp_fts_test_cleared_hooks']);
        WP_FTS_Plugin::handle_blog_switch(2, 1, 'switch');
        assert_same($queriesBeforeSwitch, count($fake->queries), 'blog switch must release the old-site descriptor without a database statement');
        assert_same($newSiteCron, $GLOBALS['wp_fts_test_scheduled'][WP_FTS_Plugin::CRON_HOOK]['timestamp'] ?? null, 'old-site abandonment must not replace the new site\'s existing cron event');
        assert_same($scheduleCallsBeforeSwitch, count($GLOBALS['wp_fts_test_schedule_calls']), 'old-site ready work must not be scheduled into the new site cron store');
        assert_same($clearCallsBeforeSwitch, count($GLOBALS['wp_fts_test_cleared_hooks']), 'old-site abandonment must not clear the new site cron singleton');
        assert_same(true, wp_fts_foreground_flock_is_exclusively_available($switchGuardPath), 'blog switch must expose old-site finite recovery work');
        assert_same(null, wp_fts_foreground_bulk_static('foreground_bulk_mutation_scope'), 'blog switch must abandon old-site in-memory ownership');
        assert_same('guarded', $fake->queue[$switchScopeKey]['state'] ?? null, 'blog switch must leave the old-site generation for finite watchdog recovery');
        assert_true((int) ($fake->queue[$switchScopeKey]['available_at'] ?? 0) < PHP_INT_MAX, 'old-site abandonment must never leave a permanent fence');
    } finally {
        WP_FTS_Plugin::reset_request_caches();
        $wpdb = $oldWpdb;
    }
});

test_case('foreground bulk mutation normal direct promotion brings cron forward after final guard release', function (): void {
    global $wpdb;

    $oldWpdb = $wpdb ?? null;
    $fake = new WP_FTS_Test_WPDB();
    $fake->recordReadQueries = true;
    $wpdb = $fake;
    wp_fts_test_reset_wordpress_fakes();
    $postId = 48251;

    try {
        WP_FTS_Plugin::handle_post_pre_update($postId, []);
        $deferredAt = time() + WP_FTS_Index_Queue::FOREGROUND_OWNER_WATCHDOG_SECONDS;
        $GLOBALS['wp_fts_test_scheduled'][WP_FTS_Plugin::CRON_HOOK] = [
            'timestamp' => $deferredAt,
            'hook' => WP_FTS_Plugin::CRON_HOOK,
            'args' => [],
        ];
        $GLOBALS['wp_fts_test_schedule_calls'] = [];
        $GLOBALS['wp_fts_test_cleared_hooks'] = [];

        WP_FTS_Plugin::handle_post_save($postId, (object) ['ID' => $postId]);
        assert_same('ready', $fake->queue[$postId]['state'] ?? null, 'the normal post-hook must durably promote its exact generation');
        assert_same($deferredAt, $GLOBALS['wp_fts_test_scheduled'][WP_FTS_Plugin::CRON_HOOK]['timestamp'] ?? null, 'normal promotion should keep the worker deferred until the request guard is released');
        assert_true(is_array(wp_fts_foreground_bulk_static('foreground_owner_guard')), 'the promoted ready generation must remain protected through the rest of the request');

        $cronWritesBeforeRelease = (int) $GLOBALS['wp_fts_test_cron_write_count'];
        $releaseStarted = time();
        WP_FTS_Plugin::flush_foreground_bulk_mutations();
        $releaseEnded = time();
        $broughtForward = (int) ($GLOBALS['wp_fts_test_scheduled'][WP_FTS_Plugin::CRON_HOOK]['timestamp'] ?? 0);
        assert_true(
            $broughtForward >= $releaseStarted + 1 && $broughtForward <= $releaseEnded + 1,
            'final guard release must replace a previously five-minute cron event with a one-second worker run'
        );
        assert_same([], $GLOBALS['wp_fts_test_cleared_hooks'], 'bringing cron forward must not clear the valid singleton before replacement');
        assert_same(
            $cronWritesBeforeRelease + 1,
            $GLOBALS['wp_fts_test_cron_write_count'],
            'bringing cron forward must replace the singleton with one cron-option write'
        );
        assert_same(null, wp_fts_foreground_bulk_static('foreground_owner_guard'), 'shutdown must release the direct request owner after its ready row is durable');
        assert_same(2, count(wp_fts_foreground_bulk_total_sql($fake)), 'normal direct pre/post promotion plus shutdown must remain exactly two FTS statements');
    } finally {
        WP_FTS_Plugin::reset_request_caches();
        $wpdb = $oldWpdb;
    }
});

test_case('foreground bulk mutation owner-guard latch survives readiness revocation failure', function (): void {
    wp_fts_test_reset_wordpress_fakes();
    $GLOBALS['wp_fts_test_options'][WP_FTS_Plugin::SEARCH_READY_INCARNATION_OPTION] = '';
    $GLOBALS['wp_fts_test_after_get_option'] = static function (string $name, mixed &$value): void {
        if ($name === WP_FTS_Plugin::SEARCH_READY_INCARNATION_OPTION && $value === '') {
            // Make the idempotent capability clear fail its verification. The
            // independent owner-guard health latch must still be attempted.
            $value = [
                'incarnation' => str_repeat('a', 32),
                'profile_hash' => str_repeat('b', 40),
            ];
        }
    };

    try {
        $latch = new ReflectionMethod(WP_FTS_Plugin::class, 'latch_search_runtime_failure');
        $latch->invoke(null, new RuntimeException('owner guard unavailable'), true);
        $health = $GLOBALS['wp_fts_test_options'][WP_FTS_Plugin::INDEX_HEALTH_OPTION] ?? [];

        assert_same(true, !empty($health['search_runtime_failure_latched']), 'a failed ready-capability revocation must not suppress the independent runtime latch');
        assert_same(true, !empty($health['foreground_owner_guard_blocked']), 'a failed ready-capability revocation must not suppress the persistent owner-guard latch');
        assert_same('unhealthy', $health['status'] ?? null, 'the independent health write must still publish an unhealthy state');
    } finally {
        unset($GLOBALS['wp_fts_test_after_get_option']);
        WP_FTS_Plugin::reset_request_caches();
    }
});

test_case('foreground bulk mutation owner-guard latch survives a stale health writer', function (): void {
    wp_fts_test_reset_wordpress_fakes();
    $stale = $GLOBALS['wp_fts_test_options'][WP_FTS_Plugin::INDEX_HEALTH_OPTION] ?? [];
    $latch = new ReflectionMethod(WP_FTS_Plugin::class, 'latch_search_runtime_failure');
    $setOption = new ReflectionMethod(WP_FTS_Plugin::class, 'set_option');

    $latch->invoke(null, new RuntimeException('owner guard unavailable'), true);
    $latched = $GLOBALS['wp_fts_test_options'][WP_FTS_Plugin::INDEX_HEALTH_OPTION] ?? [];
    assert_same(true, !empty($latched['foreground_owner_guard_blocked']), 'setup must persist the operator-only owner-guard latch');

    // Simulate a diagnostics writer that read health before the failure and
    // reaches update_option afterwards. The central health publication CAS
    // must merge the monotonic latch rather than restoring stale false bits.
    $stale['status'] = 'ready';
    $stale['search_runtime_failure_latched'] = false;
    $stale['foreground_owner_guard_blocked'] = false;
    $setOption->invoke(null, WP_FTS_Plugin::INDEX_HEALTH_OPTION, $stale);
    $afterStaleWrite = $GLOBALS['wp_fts_test_options'][WP_FTS_Plugin::INDEX_HEALTH_OPTION] ?? [];

    assert_same(true, !empty($afterStaleWrite['foreground_owner_guard_blocked']), 'a stale health writer must not erase the operator-recovery capability');
    assert_same(true, !empty($afterStaleWrite['search_runtime_failure_latched']), 'the persistent owner latch must keep search takeover revoked');
    assert_same('unhealthy', $afterStaleWrite['status'] ?? null, 'a stale health writer must not advertise a guarded-failure state as ready');
});

test_case('foreground bulk mutation unavailable flock failure requires explicit recovery', function (): void {
    if (getenv('WP_FTS_TEST_UNAVAILABLE_FLOCK_CHILD') !== '1') {
        if (!function_exists('proc_open')) {
            test_pending('proc_open() is required for the isolated unavailable-flock contract.');
        }
        $root = dirname(__DIR__, 2);
        $environment = getenv();
        if (!is_array($environment)) {
            $environment = [];
        }
        $fixtureRoot = sys_get_temp_dir() . '/wp-fts-unavailable-' . bin2hex(random_bytes(8));
        $blockedParent = $fixtureRoot . '/blocked-parent';
        $lockDirectory = $blockedParent . '/runtime';
        if (!@mkdir($fixtureRoot, 0700, true) || file_put_contents($blockedParent, 'not-a-directory') === false) {
            throw new RuntimeException('Could not create the isolated unavailable-flock path fixture.');
        }
        $process = proc_open(
            [PHP_BINARY, $root . '/tests/run.php'],
            [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $pipes,
            $root,
            array_merge($environment, [
                'WP_FTS_TEST_FILTER' => 'unavailable flock failure requires explicit recovery',
                'WP_FTS_MIN_CHECKS' => '0',
                'WP_FTS_TEST_UNAVAILABLE_FLOCK_CHILD' => '1',
                'WP_FTS_TEST_FOREGROUND_LOCK_DIR' => $lockDirectory,
            ])
        );
        if (!is_resource($process)) {
            @unlink($blockedParent);
            @rmdir($fixtureRoot);
            throw new RuntimeException('Could not launch the isolated unavailable-flock contract.');
        }
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($process);
        @rmdir($lockDirectory);
        @unlink($blockedParent);
        @rmdir($blockedParent);
        @rmdir($fixtureRoot);
        assert_same(0, $exit, "the unavailable-flock subprocess must pass\nSTDOUT:\n{$stdout}\nSTDERR:\n{$stderr}");
        assert_contains('[PASS] foreground bulk mutation unavailable flock failure requires explicit recovery', (string) $stdout, 'the subprocess must execute the isolated failure assertions');
        return;
    }

    global $wpdb;

    $oldWpdb = $wpdb ?? null;
    $fake = new WP_FTS_Test_WPDB();
    $fake->recordReadQueries = true;
    $wpdb = $fake;
    wp_fts_test_reset_wordpress_fakes();

    try {
        WP_FTS_Plugin::handle_post_pre_update(48301, []);
        $health = $GLOBALS['wp_fts_test_options'][WP_FTS_Plugin::INDEX_HEALTH_OPTION] ?? [];

        assert_same(null, wp_fts_foreground_bulk_static('foreground_owner_guard'), 'an unavailable shared path must leave no false owner capability');
        assert_same([], $fake->queue, 'owner-guard failure must stop before persisting ownerless dirty work');
        assert_same(true, !empty($health['search_runtime_failure_latched']), 'guard unavailability must latch core-search fallback instead of advertising stale FTS results');
        assert_same(true, !empty($health['foreground_owner_guard_blocked']), 'guard unavailability must persist the distinct operator-recovery latch');
        assert_same('unhealthy', $health['status'] ?? null, 'guard unavailability must be operator-visible as unhealthy');
        assert_same([], wp_fts_foreground_bulk_total_sql($fake), 'guard failure must execute zero queue SQL');
        assert_same(true, WP_FTS_Plugin::search_health()['foreground_owner_guard_blocked'] ?? null, 'the read-only Health surface must expose the operator-recovery latch');

        $queue = new WP_FTS_Index_Queue($fake);
        $nextQueriesBefore = count($fake->queries);
        $nextAvailable = $queue->next_available_at();
        assert_same(null, $nextAvailable, 'an unavailable path with no ownerless work must schedule no worker watchdog');
        assert_same($nextQueriesBefore + 1, count($fake->queries), 'unavailable-guard scheduling must remain one bounded statement');
        $claimQueriesBefore = count($fake->queries);
        $unavailableClaims = $queue->claim_batch(100, time(), 30);
        assert_same($claimQueriesBefore + 2, count($fake->queries), 'an unavailable guard probe must remain one claim update plus one confirmation read');
        assert_same([], $unavailableClaims, 'an unavailable guard must expose no ownerless foreground generation');
        $claimStatements = array_values(array_filter(
            $fake->prepared,
            static fn(array $prepared): bool => str_contains((string) ($prepared['sql'] ?? ''), 'wp_fts:claim-batch')
        ));
        assert_contains('wp_fts:fences-require-free-guard', (string) ($claimStatements[array_key_last($claimStatements)]['sql'] ?? ''), 'an unavailable worker guard must reject all fenced rows in SQL');

        $lockDirectory = (string) getenv('WP_FTS_TEST_FOREGROUND_LOCK_DIR');
        $blockedParent = dirname($lockDirectory);
        assert_true(
            is_file($blockedParent) && @unlink($blockedParent) && @mkdir($lockDirectory, 0770, true),
            'the isolated fixture must be able to restore the shared guard path'
        );
        $fake->queries = [];
        assert_same(null, $queue->next_available_at(), 'a repaired path must still observe no ownerless debt');
        assert_same(1, count($fake->queries), 'operator-only scheduling suppression must remain one bounded read');
        $fake->queries = [];
        $fake->prepared = [];
        $recovered = $queue->claim_batch(100, time(), 30);
        assert_same(2, count($fake->queries), 'a repaired path must still use only one claim update and confirmation read');
        assert_same([], $recovered, 'a repaired path must not invent work omitted by the failed canonical boundary');
        $freeClaimStatements = array_values(array_filter(
            $fake->prepared,
            static fn(array $prepared): bool => str_contains((string) ($prepared['sql'] ?? ''), 'wp_fts:claim-batch')
        ));
        assert_contains('wp_fts:only-guarded-fence-recovery', (string) ($freeClaimStatements[array_key_last($freeClaimStatements)]['sql'] ?? ''), 'a free probe may recover only typed guarded fences');

        $incarnation = str_repeat('a', 32);
        $profile = str_repeat('b', 40);
        $GLOBALS['wp_fts_test_options'][WP_FTS_Plugin::READINESS_INCARNATION_OPTION] = $incarnation;
        $blockedHealth = $GLOBALS['wp_fts_test_options'][WP_FTS_Plugin::INDEX_HEALTH_OPTION] ?? [];
        $blockedHealth['status'] = 'unhealthy';
        $blockedHealth['initial_index_status'] = 'ready';
        $blockedHealth['index_profile_hash'] = $profile;
        $blockedHealth['accepted_index_profile_hash'] = $profile;
        $blockedHealth['reconciliation_scope_completed_at'] = '2026-01-01 00:00:00';
        $blockedHealth['reconciliation_scope_completed_incarnation'] = $incarnation;
        $blockedHealth['reconciliation_scope_completed_profile_hash'] = $profile;
        $GLOBALS['wp_fts_test_options'][WP_FTS_Plugin::INDEX_HEALTH_OPTION] = $blockedHealth;
        $clearRuntimeFailure = new ReflectionMethod(WP_FTS_Plugin::class, 'clear_verified_search_runtime_failure');
        $clearRuntimeFailure->invoke(null);
        $afterMaintenance = $GLOBALS['wp_fts_test_options'][WP_FTS_Plugin::INDEX_HEALTH_OPTION] ?? [];
        assert_same(true, !empty($afterMaintenance['search_runtime_failure_latched']), 'generic verified maintenance must not clear an ownerless-fence runtime latch');
        assert_same(true, !empty($afterMaintenance['foreground_owner_guard_blocked']), 'generic verified maintenance must preserve the distinct operator-recovery latch');

        $resetHealth = new ReflectionMethod(WP_FTS_Plugin::class, 'reset_index_health_state');
        $resetHealth->invoke(null);
        $afterReset = $GLOBALS['wp_fts_test_options'][WP_FTS_Plugin::INDEX_HEALTH_OPTION] ?? [];
        assert_same(false, !empty($afterReset['foreground_owner_guard_blocked']), 'operator-authorized reset state must retire the blocked-fence latch');
        assert_same(false, !empty($afterReset['search_runtime_failure_latched']), 'operator-authorized reset state must start the new reconciliation without the old runtime latch');
    } finally {
        WP_FTS_Plugin::reset_request_caches();
        $wpdb = $oldWpdb;
    }
});

test_case('foreground bulk mutation busy flock excludes every fence but admits ready work', function (): void {
    global $wpdb;

    $oldWpdb = $wpdb ?? null;
    $fake = new WP_FTS_Test_WPDB();
    $fake->recordReadQueries = true;
    $wpdb = $fake;
    wp_fts_test_reset_wordpress_fakes();
    try {
        WP_FTS_Plugin::handle_post_pre_update(41001, []);
        WP_FTS_Plugin::handle_post_save(41001, (object) ['ID' => 41001]);
        WP_FTS_Plugin::handle_post_pre_update(41002, []);

        $scopeRows = wp_fts_foreground_bulk_scope_rows($fake);
        assert_same(1, count($scopeRows), 'the second pre-boundary must durably install one global crash fence');
        assert_same('guarded', $scopeRows[0]['state'] ?? null, 'an unflushed request-global scope must remain guarded');
        assert_true(preg_match('/^guard:[a-f0-9]{32}$/D', (string) ($scopeRows[0]['claim_token'] ?? '')) === 1, 'the global crash fence must be tied to the request-wide flock');
        $payload = json_decode((string) ($scopeRows[0]['payload'] ?? ''), true, flags: JSON_THROW_ON_ERROR);
        assert_same('foreground_bulk_mutation', $payload['reason'] ?? null, 'the crash fence must identify its recovery purpose');

        $queue = new WP_FTS_Index_Queue($fake);
        $scopeKey = (string) ($scopeRows[0]['job_key'] ?? '');
        $fake->queue[$scopeKey]['available_at'] = time() - 2;
        $unguardedPostId = 41003;
        $queue->fence_post($unguardedPostId, str_repeat('u', 32), time() - 1);
        $queriesBefore = count($fake->queries);
        $liveOwnerClaims = $queue->claim_batch(100, time(), 30);
        assert_same($queriesBefore + 2, count($fake->queries), 'mixed ready and fenced work must use one bounded update and confirmation');
        assert_same([41001], array_column($liveOwnerClaims, 'post_id'), 'the busy guard must admit ready work without guessing ownership of any fenced row');
        assert_same([], array_values(array_filter(
            $liveOwnerClaims,
            static fn(array $claim): bool => ($claim['kind'] ?? '') === 'scope'
        )), 'an overdue global fence must remain closed while its request owner guard is live');
        assert_same('fenced', $fake->queue[$unguardedPostId]['state'] ?? null, 'a busy guard must defer even an unmarked overdue fence until ownership can be probed safely');
        foreach ($liveOwnerClaims as $liveOwnerClaim) {
            assert_true($queue->acknowledge($liveOwnerClaim), 'ordinary ready work should remain normally acknowledgeable');
        }

        WP_FTS_Plugin::reset_request_caches();
        $recoveryQueriesBefore = count($fake->queries);
        $recovered = $queue->claim_batch(100, time(), 30);
        assert_same($recoveryQueriesBefore + 2, count($fake->queries), 'owner-loss recovery must stay one bounded update plus confirmation');
        $recoveredScopes = array_values(array_filter(
            $recovered,
            static fn(array $claim): bool => ($claim['kind'] ?? '') === 'scope'
        ));
        assert_same(1, count($recoveredScopes), 'request abandonment must expose the original guarded global fence');
        assert_same($scopeKey, $recoveredScopes[0]['job_key'] ?? null, 'recovery must claim the exact guarded generation rather than inventing successor work');
        assert_true($queue->acknowledge_scope($recoveredScopes[0]), 'the recovered exact generation should be acknowledgeable');
        $recoveredPosts = array_values(array_filter(
            $recovered,
            static fn(array $claim): bool => ($claim['kind'] ?? '') === 'post'
        ));
        assert_same([], $recoveredPosts, 'a free probe must not guess whether the owner of an unmarked fence exited');
        assert_same('fenced', $fake->queue[$unguardedPostId]['state'] ?? null, 'the unmarked fallback must remain fail-closed after unrelated owner release');
        assert_same(null, $queue->next_available_at(), 'an unmarked fence alone must not keep cron polling for operator-only debt');

        $queue->promote_post($unguardedPostId, str_repeat('u', 32), time());
        $promotedClaims = $queue->claim_batch(100, time(), 30);
        assert_same([$unguardedPostId], array_column($promotedClaims, 'post_id'), 'the authoritative post-SQL promotion must make the unmarked generation ordinary ready work');
        assert_true(isset($promotedClaims[0]) && $queue->acknowledge($promotedClaims[0]), 'the safely promoted fallback generation should acknowledge normally');
    } finally {
        WP_FTS_Plugin::reset_request_caches();
        $wpdb = $oldWpdb;
    }
});

test_case('foreground bulk mutation guarded state cannot be starved by more than two fenced candidate windows', function (): void {
    $fake = new WP_FTS_Test_WPDB();
    $fake->recordReadQueries = true;
    $queue = new WP_FTS_Index_Queue($fake);
    $now = time();
    $fencedIds = [];
    $guardedIds = [];

    // Operator-only rows are older and more numerous than two complete claim
    // windows. If eligibility were still a token filter outside one shared
    // state arm, these rows would permanently starve the guarded work.
    for ($offset = 0; $offset < WP_FTS_Index_Queue::MAX_CLAIM_POSTS * 2 + 1; $offset++) {
        $postId = 41600 + $offset;
        $fencedIds[] = $postId;
        $queue->fence_post($postId, str_repeat(dechex($offset % 16), 32), $now - 2000 + $offset);
    }
    for ($offset = 0; $offset < WP_FTS_Index_Queue::MAX_CLAIM_POSTS; $offset++) {
        $postId = 42000 + $offset;
        $guardedIds[] = $postId;
        $queue->fence_post(
            $postId,
            'guard:' . substr(hash('sha256', 'bounded-guarded-window-' . $offset), 0, 32),
            $now - 1000 + $offset
        );
    }

    $fake->queries = [];
    $fake->prepared = [];
    $recovered = $queue->claim(WP_FTS_Index_Queue::MAX_CLAIM_POSTS, $now, 30);
    assert_same(2, count($fake->queries), 'mixed protected debt must remain one bounded update plus one confirmation read');
    assert_same($guardedIds, array_column($recovered, 'post_id'), '201 older fenced rows must not starve one complete guarded claim window');
    $claimStatements = array_values(array_filter(
        $fake->prepared,
        static fn(array $prepared): bool => str_contains((string) ($prepared['sql'] ?? ''), 'wp_fts:claim-posts')
    ));
    $claimSql = (string) ($claimStatements[0]['sql'] ?? '');
    assert_contains("WHERE kind = 'post' AND state = 'guarded'", $claimSql, 'free recovery must use the indexed guarded state directly');
    assert_contains(') post_guarded_candidates', $claimSql, 'guarded candidates must have their own bounded index arm');
    assert_true(!str_contains($claimSql, "state = 'fenced'"), 'free automatic claims must omit operator-only fenced rows from every candidate and CAS arm');
    assert_true(!str_contains($claimSql, 'claim_token LIKE'), 'automatic eligibility must never scan or classify claim-token text');
    assert_true(substr_count($claimSql, 'LIMIT 100') === 6, 'five state arms plus the outer choice must each retain the hard 100-row limit');

    assert_same(
        ['acknowledged' => WP_FTS_Index_Queue::MAX_CLAIM_POSTS, 'superseded' => 0],
        $queue->acknowledge_many($recovered, $now + 1),
        'the complete guarded window should acknowledge normally'
    );
    foreach ($fencedIds as $postId) {
        assert_same('fenced', $fake->queue[$postId]['state'] ?? null, 'automatic guarded recovery must leave every operator-only generation untouched');
    }

    $fake->queries = [];
    $fake->prepared = [];
    assert_same(null, $queue->next_available_at(), 'operator-only debt alone must not create a recurring free-worker cron wakeup');
    assert_same(1, count($fake->queries), 'operator-only scheduling suppression must remain one bounded read');
    $scheduleSql = (string) ($fake->prepared[0]['sql'] ?? '');
    assert_true(!str_contains($scheduleSql, "state = 'fenced'"), 'a free next-available query must omit the fenced state entirely');
    assert_true(!str_contains($scheduleSql, 'claim_token LIKE'), 'free scheduling must not scan claim-token text');

    $promotedId = $fencedIds[0];
    $queue->promote_post($promotedId, str_repeat('0', 32), $now + 2);
    $promoted = $queue->claim(WP_FTS_Index_Queue::MAX_CLAIM_POSTS, $now + 2, 30);
    assert_same([$promotedId], array_column($promoted, 'post_id'), 'an authoritative post-SQL promotion must make exactly that fenced generation ready');
    assert_true(isset($promoted[0]) && $queue->acknowledge($promoted[0]), 'the safely promoted generation should acknowledge normally');
});

test_case('foreground bulk mutation concurrent scopes remove only the owned sentinel', function (): void {
    $fake = new WP_FTS_Test_WPDB();
    $queue = new WP_FTS_Index_Queue($fake);
    $tokenA = str_repeat('a', 32);
    $tokenB = str_repeat('b', 32);
    $tokenC = str_repeat('c', 32);
    $now = time();

    $queue->fence_scope('concurrent-request-a', $tokenA, ['reason' => 'A'], $now + 300);
    $queue->fence_scope('concurrent-request-b', $tokenB, ['reason' => 'B'], $now + 300);
    $before = count(wp_fts_foreground_bulk_work_statements($fake));
    $handoffA = $queue->handoff_foreground_mutation_scope(
        'concurrent-request-a',
        $tokenA,
        [42001],
        [],
        [],
        false,
        ['reason' => 'A handoff'],
        $now
    );
    assert_same(true, $handoffA['global_scope_released'] ?? null, 'request A should observe its exact sentinel deletion');
    assert_same($before + 2, count(wp_fts_foreground_bulk_work_statements($fake)), 'one concurrent exact handoff must publish posts and delete its sentinel in exactly two FTS statements');
    $remaining = wp_fts_foreground_bulk_scope_rows($fake);
    assert_same(1, count($remaining), 'request A must leave request B global visibility fence intact');
    assert_same($tokenB, $remaining[0]['claim_token'] ?? null, 'request A must not clear request B ownership token');
    assert_same('fenced', $remaining[0]['state'] ?? null, 'request B must continue hiding stale search while it is open');

    $handoffB = $queue->handoff_foreground_mutation_scope(
        'concurrent-request-b',
        $tokenB,
        [42002],
        [],
        [],
        false,
        ['reason' => 'B handoff'],
        $now
    );
    assert_same(true, $handoffB['global_scope_released'] ?? null, 'request B should observe its exact sentinel deletion');
    assert_same([], wp_fts_foreground_bulk_scope_rows($fake), 'the final concurrent owner may remove only its own global fence');
    assert_same([42001, 42002], wp_fts_test_queue_ids($fake), 'both concurrent handoffs must preserve their exact dirty posts');

    $queue->fence_scope('lost-request-token', $tokenC, ['reason' => 'C'], $now + 300);
    $lostHandoff = $queue->handoff_foreground_mutation_scope(
        'lost-request-token',
        str_repeat('d', 32),
        [42003],
        [],
        [],
        false,
        ['reason' => 'lost owner'],
        $now
    );
    assert_same(false, $lostHandoff['global_scope_released'] ?? null, 'the queue API must report a lost exact sentinel compare-and-swap');
    $lost = wp_fts_foreground_bulk_scope_rows($fake);
    assert_same(1, count($lost), 'a lost scope token must fail closed behind its existing global fence');
    assert_same($tokenC, $lost[0]['claim_token'] ?? null, 'a stale handoff token must not overwrite the current scope owner');
    assert_same('fenced', $lost[0]['state'] ?? null, 'a stale handoff must not make the current global scope claimable');
});

test_case('foreground bulk mutation successors stay on one canonical identity', function (): void {
    global $wpdb;

    $oldWpdb = $wpdb ?? null;
    $fake = new WP_FTS_Test_WPDB();
    $fake->recordReadQueries = true;
    $wpdb = $fake;
    wp_fts_test_reset_wordpress_fakes();
    try {
        $queue = new WP_FTS_Index_Queue($fake);
        $postId = 43001;
        $foreignPostToken = str_repeat('x', 32);
        $queue->fence_post($postId, $foreignPostToken, time() + 300);
        $foreignPost = $fake->queue[$postId] ?? [];
        $fake->queries = [];

        WP_FTS_Plugin::handle_post_pre_update($postId, []);
        $successorPost = $fake->queue[$postId] ?? [];
        WP_FTS_Plugin::handle_post_save($postId, (object) ['ID' => $postId]);
        $readyPost = $fake->queue[$postId] ?? [];
        assert_same([], wp_fts_foreground_bulk_scope_rows($fake), 'one successor post must never create a corpus scope');
        assert_same(1, count(array_filter(
            $fake->queue,
            static fn(array $row): bool => (string) ($row['kind'] ?? '') === 'post'
                && (int) ($row['post_id'] ?? 0) === $postId
        )), 'concurrent post requests must share one canonical queue identity');
        assert_same(2, $successorPost['generation'] ?? null, 'the successor should atomically advance the canonical generation');
        assert_true(($successorPost['claim_token'] ?? '') !== ($foreignPost['claim_token'] ?? ''), 'the successor token should revoke the abandoned owner');
        assert_same('ready', $readyPost['state'] ?? null, 'the successor post hook should release only its token');
        assert_same(2, count(wp_fts_foreground_bulk_total_sql($fake)), 'one successor post lifecycle must remain exactly fence plus promotion');

        WP_FTS_Plugin::reset_request_caches();
        $fake = new WP_FTS_Test_WPDB();
        $fake->recordReadQueries = true;
        $wpdb = $fake;
        wp_fts_test_reset_wordpress_fakes();
        $queue = new WP_FTS_Index_Queue($fake);
        $foreignScopeToken = str_repeat('y', 32);
        $scopeKey = 'taxonomy:category:9';
        $queue->fence_scope(
            $scopeKey,
            $foreignScopeToken,
            [],
            time() + 300,
            WP_FTS_Index_Queue::SCOPE_COVERAGE_TARGETED,
            'term_taxonomy',
            50009
        );
        $foreignScope = wp_fts_foreground_bulk_scope_rows($fake)[0] ?? [];
        $fake->queries = [];

        WP_FTS_Plugin::handle_taxonomy_term_pre_edit(9, 'category', ['term_taxonomy_id' => 50009]);
        $successorScope = wp_fts_foreground_bulk_scope_rows($fake)[0] ?? [];
        WP_FTS_Plugin::handle_taxonomy_term_edit(9, 50009, 'category', []);
        $readyScope = wp_fts_foreground_bulk_scope_rows($fake)[0] ?? [];
        assert_same(1, count(wp_fts_foreground_bulk_scope_rows($fake)), 'targeted successors must share one canonical scope identity');
        assert_same(2, $successorScope['generation'] ?? null, 'the targeted successor should advance one generation');
        assert_true(($successorScope['claim_token'] ?? '') !== ($foreignScope['claim_token'] ?? ''), 'the targeted successor should revoke the older token');
        assert_same(50009, $successorScope['scope_subject_id'] ?? null, 'the successor must retain narrow term-taxonomy authority');
        assert_same('ready', $readyScope['state'] ?? null, 'the matching targeted post hook should release its exact generation');
        assert_same(2, count(wp_fts_foreground_bulk_total_sql($fake)), 'one successor targeted lifecycle must remain exactly fence plus promotion');
    } finally {
        WP_FTS_Plugin::reset_request_caches();
        $wpdb = $oldWpdb;
    }
});

test_case('foreground bulk mutation persistence failure latches before worst-case fanout', function (): void {
    global $wpdb;

    $oldWpdb = $wpdb ?? null;
    $fake = new WP_FTS_Test_WPDB();
    $fake->recordReadQueries = true;
    $wpdb = $fake;
    wp_fts_test_reset_wordpress_fakes();
    try {
        WP_FTS_Plugin::handle_post_pre_update(44001, []);
        WP_FTS_Plugin::handle_post_save(44001, (object) ['ID' => 44001]);
        $fake->failQueryPrefix = 'INSERT INTO wp_fts_work';
        WP_FTS_Plugin::handle_post_pre_update(44002, []);
        $sqlAfterFirstFailure = count(wp_fts_foreground_bulk_total_sql($fake));
        assert_same(4, $sqlAfterFirstFailure, 'the first bulk activation failure should stop after one bounded durable write attempt');

        for ($offset = 0; $offset < 1001; $offset++) {
            $postId = 45000 + $offset;
            WP_FTS_Plugin::handle_post_pre_update($postId, []);
            WP_FTS_Plugin::handle_post_save($postId, (object) ['ID' => $postId]);
        }
        assert_same($sqlAfterFirstFailure, count(wp_fts_foreground_bulk_total_sql($fake)), '1,001 later hook pairs must perform zero additional FTS I/O after the failure latch');
        assert_same(true, wp_fts_foreground_bulk_static('foreground_bulk_activation_attempted'), 'the failed activation must remain attempted for the whole request');
        assert_same(true, wp_fts_foreground_bulk_static('foreground_queue_writes_disabled'), 'the first persistence failure must disable later foreground queue writes');
    } finally {
        WP_FTS_Plugin::reset_request_caches();
        $wpdb = $oldWpdb;
    }
});

test_case('foreground bulk mutation blog switch abandons request-local capabilities', function (): void {
    global $wpdb;

    $oldWpdb = $wpdb ?? null;
    $fake = new WP_FTS_Test_WPDB();
    $fake->recordReadQueries = true;
    $wpdb = $fake;
    wp_fts_test_reset_wordpress_fakes();
    try {
        WP_FTS_Plugin::handle_post_pre_update(46001, []);
        $oldRow = $fake->queue[46001] ?? [];
        assert_same([], $fake->advisoryLocks, 'portable foreground fences must create no session capabilities');
        $fake->prefix = 'wp_2_';
        $fake->posts = 'wp_2_posts';
        WP_FTS_Plugin::handle_blog_switch(2, 1, 'switch');

        assert_same([], $fake->advisoryLocks, 'blog switch should require no lock-release round trip');
        assert_same([], wp_fts_foreground_bulk_static('mutation_fence_tokens'), 'blog switch must not carry old-site ownership tokens forward');
        assert_same([], wp_fts_foreground_bulk_static('foreground_mutation_targets'), 'blog switch must not hand old-site targets to the new site');
        assert_same(null, wp_fts_foreground_bulk_static('foreground_mutation_prefix'), 'new site should start with no inherited foreground prefix');
        assert_same('guarded', $oldRow['state'] ?? null, 'old-site durable work must remain fail-closed for abandonment recovery');
        assert_true((int) ($oldRow['available_at'] ?? 0) > time(), 'blog switch must preserve the explicit abandonment deadline');
    } finally {
        WP_FTS_Plugin::reset_request_caches();
        $wpdb = $oldWpdb;
    }
});

test_case('foreground bulk mutation relationship post-hook survives switch-abandoned recovery', function (): void {
    global $wpdb, $wp_current_filter;

    $oldWpdb = $wpdb ?? null;
    $oldCurrentFilter = $wp_current_filter ?? null;
    $fake = new WP_FTS_Test_WPDB();
    $fake->recordReadQueries = true;
    $wpdb = $fake;
    wp_fts_test_reset_wordpress_fakes();
    wp_fts_test_install_multisite_switch_functions();
    $GLOBALS['wp_fts_test_is_multisite'] = true;
    $postId = 48301;
    $queue = new WP_FTS_Index_Queue($fake);

    try {
        $wp_current_filter = ['delete_term_relationships'];
        $fake->queries = [];
        WP_FTS_Plugin::handle_term_relationship_pre_change($postId, [11, 12], 'category');
        $preFence = $fake->queue[$postId] ?? [];
        $preSql = wp_fts_foreground_bulk_total_sql($fake);
        assert_same(1, count($preSql), 'the old-site relationship pre-hook must install exactly one durable fence');
        assert_same('guarded', $preFence['state'] ?? null, 'the old-site relationship pre-hook must remain hidden until canonical SQL commits');
        assert_true(str_starts_with((string) ($preFence['claim_token'] ?? ''), 'guard:'), 'the old-site relationship fence must carry request-liveness ownership');
        assert_true(max(array_map('strlen', $preSql)) < 1048576, 'the relationship pre-fence statement must stay below one MiB');

        $scheduleCallsBeforeSwitch = count($GLOBALS['wp_fts_test_schedule_calls']);
        $queriesBeforeSwitch = count($fake->queries);
        assert_true(switch_to_blog(2), 'the hostile relationship fixture must enter the second site');
        WP_FTS_Plugin::handle_blog_switch(2, 1, 'switch');
        assert_same($queriesBeforeSwitch, count($fake->queries), 'abandoning old-site relationship ownership must execute no SQL through the new-site prefix');
        assert_same($scheduleCallsBeforeSwitch, count($GLOBALS['wp_fts_test_schedule_calls']), 'abandoning old-site ready state must not write a new-site cron event');
        assert_true(restore_current_blog(), 'the hostile relationship fixture must restore the original site');
        WP_FTS_Plugin::handle_blog_switch(1, 2, 'restore');
        assert_same($queriesBeforeSwitch, count($fake->queries), 'restoring the original site must not add an ownership cleanup query');
        assert_same($scheduleCallsBeforeSwitch, count($GLOBALS['wp_fts_test_schedule_calls']), 'switch and restore callbacks must leave cron scheduling unchanged');
        assert_same(0, count(array_filter(
            array_map('wp_fts_foreground_bulk_sql', $fake->queries),
            static fn(string $sql): bool => str_contains($sql, 'wp_2_fts_work')
        )), 'a switch inside relationship SQL must never touch the new site work table');

        // Model a long canonical delete whose abandoned watchdog is recovered
        // completely before WordPress emits deleted_term_relationships.
        $fake->queue[$postId]['available_at'] = time() - 1;
        $fake->queries = [];
        $recovered = $queue->claim_batch(1, time(), 30);
        assert_same([$postId], array_column($recovered, 'post_id'), 'old-site recovery must claim the abandoned relationship fence');
        assert_true(isset($recovered[0]) && $queue->acknowledge($recovered[0]), 'old-site recovery must be able to acknowledge the exact abandoned generation');
        $recoverySql = wp_fts_foreground_bulk_total_sql($fake);
        assert_same(4, count($recoverySql), 'abandoned relationship recovery must stay one claim UPDATE, one confirmation SELECT, and one atomic epoch-advance plus acknowledgement pair');
        assert_true(max(array_map('strlen', $recoverySql)) < 1048576, 'every abandoned relationship recovery statement must stay below one MiB');
        assert_true(!isset($fake->queue[$postId]), 'the adversary must remove the old relationship fence before the canonical post-hook');

        $fake->queries = [];
        $wp_current_filter = ['deleted_term_relationships'];
        WP_FTS_Plugin::handle_term_relationship_change($postId, [11, 12], 'category');
        $successor = $fake->queue[$postId] ?? [];
        $successorSql = wp_fts_foreground_bulk_total_sql($fake);
        assert_same(1, count($successorSql), 'the authoritative relationship post-hook must publish exactly one successor after recovery removed its pre-fence');
        assert_same('post', $successor['kind'] ?? null, 'the post-SQL relationship successor must retain exact post authority');
        assert_same('ready', $successor['state'] ?? null, 'the post-SQL relationship successor must be immediately claimable');
        assert_same(1, $successor['generation'] ?? null, 'the post-hook must recreate a fresh generation after the recovered fence was acknowledged');
        assert_true((int) ($successor['available_at'] ?? PHP_INT_MAX) <= time(), 'the committed relationship successor must not inherit the abandoned watchdog delay');
        assert_true(strlen($successorSql[0] ?? '') < 1048576, 'the relationship successor statement must stay below one MiB');
    } finally {
        while (!empty($GLOBALS['wp_fts_test_blog_stack'])) {
            restore_current_blog();
        }
        if ($oldCurrentFilter === null) {
            unset($wp_current_filter);
        } else {
            $wp_current_filter = $oldCurrentFilter;
        }
        WP_FTS_Plugin::reset_request_caches();
        $wpdb = $oldWpdb;
    }
});

test_case('foreground bulk mutation global metadata post-hook survives switch-abandoned recovery', function (): void {
    global $wpdb;

    $oldWpdb = $wpdb ?? null;
    $fake = new WP_FTS_Test_WPDB();
    $fake->recordReadQueries = true;
    $wpdb = $fake;
    wp_fts_test_reset_wordpress_fakes();
    wp_fts_test_install_multisite_switch_functions();
    $GLOBALS['wp_fts_test_is_multisite'] = true;
    $GLOBALS['wp_fts_test_options'][WP_FTS_PostContentExtractor::CUSTOM_FIELDS_OPTION] = ['subtitle'];
    $queue = new WP_FTS_Index_Queue($fake);

    try {
        $fake->queries = [];
        WP_FTS_Plugin::handle_post_meta_pre_delete([], 0, 'subtitle', null);
        $preScope = wp_fts_foreground_bulk_scope_rows($fake)[0] ?? [];
        $scopeJobKey = (string) ($preScope['job_key'] ?? '');
        $preSql = wp_fts_foreground_bulk_total_sql($fake);
        assert_same(1, count($preSql), 'selected delete-all metadata must install exactly one canonical scope fence before SQL');
        assert_true($scopeJobKey !== '', 'the selected delete-all metadata fence must have one durable canonical identity');
        assert_same('corpus', $preScope['scope_coverage'] ?? null, 'selected delete-all metadata must hide the complete corpus');
        assert_same('guarded', $preScope['state'] ?? null, 'selected delete-all metadata must remain hidden during canonical SQL');
        assert_true(str_starts_with((string) ($preScope['claim_token'] ?? ''), 'guard:'), 'the metadata scope fence must carry request-liveness ownership');
        assert_true(max(array_map('strlen', $preSql)) < 1048576, 'the metadata pre-fence statement must stay below one MiB');

        $scheduleCallsBeforeSwitch = count($GLOBALS['wp_fts_test_schedule_calls']);
        $queriesBeforeSwitch = count($fake->queries);
        assert_true(switch_to_blog(2), 'the hostile metadata fixture must enter the second site');
        WP_FTS_Plugin::handle_blog_switch(2, 1, 'switch');
        assert_same($queriesBeforeSwitch, count($fake->queries), 'metadata owner abandonment must execute no SQL through the new-site prefix');
        assert_same($scheduleCallsBeforeSwitch, count($GLOBALS['wp_fts_test_schedule_calls']), 'metadata owner abandonment must not schedule old-site work in the new-site cron store');
        assert_true(restore_current_blog(), 'the hostile metadata fixture must restore the original site');
        WP_FTS_Plugin::handle_blog_switch(1, 2, 'restore');
        assert_same($queriesBeforeSwitch, count($fake->queries), 'metadata restore cleanup must not issue a database query');
        assert_same($scheduleCallsBeforeSwitch, count($GLOBALS['wp_fts_test_schedule_calls']), 'metadata switch and restore callbacks must leave cron scheduling unchanged');
        assert_same(0, count(array_filter(
            array_map('wp_fts_foreground_bulk_sql', $fake->queries),
            static fn(string $sql): bool => str_contains($sql, 'wp_2_fts_work')
        )), 'a switch inside metadata SQL must never touch the new site work table');

        $fake->queue[$scopeJobKey]['available_at'] = time() - 1;
        $fake->queries = [];
        $recovered = $queue->claim_batch(0, time(), 30);
        $recoveredScopes = array_values(array_filter(
            $recovered,
            static fn(array $claim): bool => ($claim['kind'] ?? '') === 'scope'
        ));
        assert_same([$scopeJobKey], array_column($recoveredScopes, 'job_key'), 'old-site recovery must claim the abandoned global metadata scope');
        assert_true(isset($recoveredScopes[0]) && $queue->acknowledge_scope($recoveredScopes[0]), 'old-site recovery must acknowledge the exact abandoned metadata scope');
        $recoverySql = wp_fts_foreground_bulk_total_sql($fake);
        assert_same(4, count($recoverySql), 'abandoned metadata recovery must stay one claim UPDATE, one confirmation SELECT, and one atomic epoch-advance plus acknowledgement pair');
        assert_true(max(array_map('strlen', $recoverySql)) < 1048576, 'every abandoned metadata recovery statement must stay below one MiB');
        assert_same([], wp_fts_foreground_bulk_scope_rows($fake), 'the adversary must remove the old metadata scope before its post-SQL callback');

        $fake->queries = [];
        WP_FTS_Plugin::handle_post_meta_change(701, 0, 'subtitle', null);
        $successorScopes = wp_fts_foreground_bulk_scope_rows($fake);
        $successor = $successorScopes[0] ?? [];
        $successorSql = wp_fts_foreground_bulk_total_sql($fake);
        assert_same(1, count($successorSql), 'the authoritative metadata post-hook must publish exactly one canonical successor after recovery');
        assert_same(1, count($successorScopes), 'the metadata fallback must retain one canonical scope instead of per-post work');
        assert_same($scopeJobKey, $successor['job_key'] ?? null, 'the metadata post-hook must reuse the canonical corpus identity');
        assert_same('corpus', $successor['scope_coverage'] ?? null, 'the metadata successor must retain complete-corpus authority');
        assert_same('ready', $successor['state'] ?? null, 'the committed metadata successor must be immediately claimable');
        assert_same('post', wp_fts_foreground_bulk_static('post_meta_global_mutations')['subtitle'] ?? null, 'the fallback post-hook must retain a request-local fan-out marker');
        assert_true(strlen($successorSql[0] ?? '') < 1048576, 'the metadata successor statement must stay below one MiB');

        $queriesAfterSuccessor = count($fake->queries);
        for ($offset = 0; $offset < 50000; $offset++) {
            WP_FTS_Plugin::handle_post_meta_change(100000 + $offset, 0, 'subtitle', null);
        }
        assert_same($queriesAfterSuccessor, count($fake->queries), '50,000 adapter fan-out callbacks after the canonical metadata successor must execute zero database queries');
        assert_same(1, count(wp_fts_foreground_bulk_scope_rows($fake)), '50,000 adapter fan-out callbacks must not multiply the canonical scope');
    } finally {
        while (!empty($GLOBALS['wp_fts_test_blog_stack'])) {
            restore_current_blog();
        }
        WP_FTS_Plugin::reset_request_caches();
        $wpdb = $oldWpdb;
    }
});

test_case('foreground bulk mutation repeated global metadata delete re-fences after its post marker', function (): void {
    global $wpdb;

    $oldWpdb = $wpdb ?? null;
    $fake = new WP_FTS_Test_WPDB();
    $fake->recordReadQueries = true;
    $wpdb = $fake;
    wp_fts_test_reset_wordpress_fakes();
    $GLOBALS['wp_fts_test_options'][WP_FTS_PostContentExtractor::CUSTOM_FIELDS_OPTION] = ['subtitle'];

    try {
        $fake->queries = [];
        WP_FTS_Plugin::handle_post_meta_pre_delete([], 0, 'subtitle', null);
        WP_FTS_Plugin::handle_post_meta_change(801, 0, 'subtitle', null);
        $firstReady = wp_fts_foreground_bulk_scope_rows($fake)[0] ?? [];
        $firstLifecycleSql = wp_fts_foreground_bulk_total_sql($fake);
        assert_same(2, count($firstLifecycleSql), 'the first selected delete-all lifecycle must stay one pre-fence plus one post promotion');
        assert_same('ready', $firstReady['state'] ?? null, 'the first selected delete-all post-hook must publish its committed scope');
        assert_same('post', wp_fts_foreground_bulk_static('post_meta_global_mutations')['subtitle'] ?? null, 'the first selected delete-all lifecycle must end with its fan-out marker');
        assert_true(max(array_map('strlen', $firstLifecycleSql)) < 1048576, 'every statement in the first metadata lifecycle must stay below one MiB');

        $fake->queries = [];
        WP_FTS_Plugin::handle_post_meta_pre_delete([], 0, 'subtitle', null);
        $secondFence = wp_fts_foreground_bulk_scope_rows($fake)[0] ?? [];
        $secondPreSql = wp_fts_foreground_bulk_total_sql($fake);
        assert_same(1, count($secondPreSql), 'a second same-key delete-all pre-hook must execute one fresh fence rather than reuse the prior post marker');
        assert_same('pre', wp_fts_foreground_bulk_static('post_meta_global_mutations')['subtitle'] ?? null, 'the second same-key pre-hook must replace the prior post marker before canonical SQL');
        assert_same('guarded', $secondFence['state'] ?? null, 'the second same-key pre-hook must hide the first committed projection again');
        assert_same((int) ($firstReady['generation'] ?? 0) + 1, $secondFence['generation'] ?? null, 'the second same-key pre-hook must advance the durable generation');
        assert_true(str_starts_with((string) ($secondFence['claim_token'] ?? ''), 'guard:'), 'the second same-key boundary must carry current request ownership');
        assert_true((int) ($secondFence['available_at'] ?? 0) > time(), 'the second same-key boundary must install a finite pre-SQL watchdog');
        assert_same(null, wp_fts_foreground_bulk_static('foreground_bulk_mutation_scope'), 'the second same-key lifecycle must remain a direct single-scope boundary rather than escalating early');
        assert_true(strlen($secondPreSql[0] ?? '') < 1048576, 'the repeated metadata pre-fence statement must stay below one MiB');
    } finally {
        WP_FTS_Plugin::reset_request_caches();
        $wpdb = $oldWpdb;
    }
});

test_case('foreground bulk mutation reset removes legacy handoff tombstones but retains the epoch', function (): void {
    $fake = new WP_FTS_Test_WPDB();
    $fake->queue['legacy-random-handoff'] = [
        'job_key' => 'legacy-random-handoff',
        'kind' => 'meta',
        'post_id' => 0,
        'state' => 'handoff',
    ];
    $fake->queue[WP_FTS_Index_Queue::SEARCH_EPOCH_JOB_KEY] = [
        'job_key' => WP_FTS_Index_Queue::SEARCH_EPOCH_JOB_KEY,
        'kind' => 'meta',
        'post_id' => 0,
        'state' => 'meta',
    ];
    $queue = new WP_FTS_Index_Queue($fake);
    $queue->clear();

    assert_true(!isset($fake->queue['legacy-random-handoff']), 'reset must clean tombstones left by the former random handoff encoding');
    assert_true(isset($fake->queue[WP_FTS_Index_Queue::SEARCH_EPOCH_JOB_KEY]), 'reset must retain the singleton cursor epoch');
});
