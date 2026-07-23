<?php
declare(strict_types=1);

const WP_FTS_READER_MAX_UNAVAILABLE_RETRIES = 3;

/**
 * Lightweight REST client for the relational concurrency workload.
 *
 * Reader clients run as ephemeral load generators on the same Docker network,
 * but do not bootstrap WordPress merely to issue an HTTP request.
 */

try {
    $worker = wp_fts_reader_non_negative_int_env('WP_FTS_WC_WORKER');
    $seconds = wp_fts_reader_positive_int_env('WP_FTS_WC_CONCURRENCY_SECONDS');
    $outputDirectory = wp_fts_reader_required_env('WP_FTS_WC_OUTPUT_DIR');
    $expectedHarnessHash = wp_fts_reader_required_env('WP_FTS_WC_CONCURRENT_READER_SHA256');
    $actualHarnessHash = hash_file('sha256', __FILE__);
    wp_fts_reader_assert(
        is_string($actualHarnessHash) && hash_equals($expectedHarnessHash, $actualHarnessHash),
        'Mounted concurrent-reader harness does not match its source digest.'
    );

    $baseline = wp_fts_reader_json($outputDirectory . '/concurrency-baseline.json');
    $runId = is_string($baseline['concurrency_run_id'] ?? null) ? $baseline['concurrency_run_id'] : '';
    wp_fts_reader_assert(
        ($baseline['schema'] ?? null) === 'relational-fts-concurrency-baseline-v5'
            && ($baseline['status'] ?? null) === 'PASS'
            && strlen($runId) === 32
            && ctype_xdigit($runId)
            && ($baseline['reader_harness_sha256'] ?? null) === $expectedHarnessHash,
        'Concurrent-reader baseline is missing or invalid.'
    );

    $window = wp_fts_reader_window($outputDirectory, $worker, $seconds, $runId, $expectedHarnessHash);
    $cases = is_array($baseline['cases'] ?? null) ? $baseline['cases'] : [];
    $mix = [
        'common_or', 'common_or', 'common_or', 'common_or', 'common_or',
        'common_or', 'common_or', 'common_or', 'common_or', 'common_or',
        'prefix_fanout', 'prefix_fanout', 'prefix_fanout',
        'rare_anchor_and', 'rare_anchor_and',
        'surface_rarest_exact_anchor_and', 'surface_rarest_exact_anchor_and',
        'common_or', 'prefix_fanout', 'max_valid_or_prefix',
    ];
    $samples = [];
    $errors = [];
    $requests = 0;
    $httpAttempts = 0;
    $unavailableRetries = 0;
    $startedNs = hrtime(true);
    $deadlineNs = (int) $window['deadline_monotonic_ns'];

    while (hrtime(true) < $deadlineNs) {
        $caseId = $mix[($requests + $worker) % count($mix)];
        try {
            $expected = is_array($cases[$caseId] ?? null) ? $cases[$caseId] : [];
            $sample = wp_fts_reader_request($caseId, $expected);
            $samples[] = $sample;
            $httpAttempts += $sample['attempts'];
            $unavailableRetries += $sample['unavailable_retries'];
        } catch (Throwable $error) {
            $errors[] = ['case' => $caseId, 'message' => $error->getMessage()];
        }
        $requests++;
    }

    $finishedNs = hrtime(true);
    $result = [
        'schema' => 'relational-fts-concurrent-reader-v3',
        'status' => $errors === [] ? 'PASS' : 'FAIL',
        'phase' => 'concurrent-reader',
        'worker' => $worker,
        'harness_sha256' => $expectedHarnessHash,
        'elapsed_seconds' => max(0.0, ($finishedNs - $startedNs) / 1000000000),
        'started_monotonic_ns' => $startedNs,
        'finished_monotonic_ns' => $finishedNs,
        'shared_window' => $window,
        'measured_overlap_seconds' => max(
            0.0,
            (min($finishedNs, $deadlineNs) - max($startedNs, (int) $window['start_monotonic_ns'])) / 1000000000
        ),
        'requests' => $requests,
        'http_attempts' => $httpAttempts,
        'unavailable_retries' => $unavailableRetries,
        'samples' => $samples,
        'errors' => $errors,
        'rss_peak_bytes' => wp_fts_reader_rss_peak_bytes(),
        'php_peak_bytes' => memory_get_peak_usage(true),
    ];
    wp_fts_reader_write_json($outputDirectory . "/concurrent-reader-{$worker}.json", $result);
    if ($result['status'] !== 'PASS') {
        throw new RuntimeException("Concurrent reader {$worker} recorded " . count($errors) . ' failed requests.');
    }

    echo json_encode(
        [
            'status' => 'PASS',
            'phase' => 'concurrent-reader',
            'worker' => $worker,
            'requests' => $requests,
            'unavailable_retries' => $unavailableRetries,
            'errors' => 0,
        ],
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
    ) . "\n";
} catch (Throwable $error) {
    fwrite(STDERR, 'FAIL: relational FTS concurrent reader: ' . $error->getMessage() . "\n");
    exit(1);
}

/** @return array<string,mixed> */
function wp_fts_reader_window(
    string $outputDirectory,
    int $worker,
    int $seconds,
    string $runId,
    string $harnessHash
): array {
    wp_fts_reader_write_json($outputDirectory . "/concurrency-ready-reader-{$worker}.json", [
        'schema' => 'relational-fts-concurrency-ready-v1',
        'run_id' => $runId,
        'role' => 'reader',
        'worker' => $worker,
        'harness_sha256' => $harnessHash,
        'ready_monotonic_ns' => hrtime(true),
    ]);

    $coordinatorPath = $outputDirectory . '/concurrency-window.json';
    $waitDeadline = hrtime(true) + 180000000000;
    while (!is_file($coordinatorPath)) {
        wp_fts_reader_assert(
            hrtime(true) < $waitDeadline,
            "Concurrent reader {$worker} waited more than 180 seconds for the shared start barrier."
        );
        usleep(20000);
        clearstatcache(true, $coordinatorPath);
    }

    $window = wp_fts_reader_json($coordinatorPath);
    wp_fts_reader_assert(
        ($window['schema'] ?? null) === 'relational-fts-concurrency-window-v1'
            && ($window['run_id'] ?? null) === $runId
            && ($window['minimum_overlap_seconds'] ?? null) === $seconds
            && is_int($window['start_monotonic_ns'] ?? null)
            && is_int($window['deadline_monotonic_ns'] ?? null)
            && (int) $window['deadline_monotonic_ns'] - (int) $window['start_monotonic_ns'] >= ($seconds * 1000000000),
        "Concurrent reader {$worker} received an invalid shared window."
    );
    while (hrtime(true) < (int) $window['start_monotonic_ns']) {
        usleep(1000);
    }

    return $window;
}

/**
 * @param array<string,mixed> $expected
 * @return array{case:string,duration_ms:float,count:int,hash:string,attempts:int,unavailable_retries:int}
 */
function wp_fts_reader_request(string $caseId, array $expected): array
{
    $url = is_string($expected['request_url'] ?? null) ? $expected['request_url'] : '';
    $urlParts = parse_url($url);
    wp_fts_reader_assert(
        is_array($urlParts)
            && ($urlParts['scheme'] ?? null) === 'http'
            && ($urlParts['host'] ?? null) === 'wordpress'
            && !isset($urlParts['user'])
            && !isset($urlParts['pass'])
            && (!isset($urlParts['port']) || $urlParts['port'] === 80),
        "Concurrent reader {$caseId} has an invalid request URL."
    );

    $started = hrtime(true);
    $attempts = 0;
    $unavailableRetries = 0;
    while (true) {
        $attempts++;
        $handle = curl_init($url);
        wp_fts_reader_assert($handle !== false, "Concurrent reader {$caseId} could not initialize cURL.");
        curl_setopt_array($handle, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 120,
            CURLOPT_HTTPHEADER => ['Accept: application/json'],
        ]);
        try {
            $body = curl_exec($handle);
            $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
            $curlError = curl_error($handle);
        } finally {
            curl_close($handle);
        }
        if (
            $unavailableRetries < WP_FTS_READER_MAX_UNAVAILABLE_RETRIES
            && wp_fts_reader_is_publication_retry($body, $status, $curlError)
        ) {
            $unavailableRetries++;
            // Do not send the same broad read straight back into the epoch
            // transition that rejected it.
            usleep(250000);
            continue;
        }
        break;
    }
    $durationMs = max(0.0, (hrtime(true) - $started) / 1000000);
    $bodyPreview = is_string($body) ? substr(str_replace(["\r", "\n"], ' ', $body), 0, 300) : '';
    wp_fts_reader_assert(
        is_string($body) && $curlError === '' && $status === 200 && strlen($body) <= 4194304,
        "Concurrent reader {$caseId} received HTTP {$status}: {$curlError} {$bodyPreview}"
    );
    $payload = json_decode($body, true, 64, JSON_THROW_ON_ERROR);
    wp_fts_reader_assert(is_array($payload) && is_array($payload['results'] ?? null), "Concurrent reader {$caseId} received malformed JSON.");

    $signature = [];
    $ids = [];
    foreach ($payload['results'] as $row) {
        wp_fts_reader_assert(
            is_array($row) && is_numeric($row['doc_id'] ?? null) && is_numeric($row['score'] ?? null),
            "Concurrent reader {$caseId} received a malformed result row."
        );
        $id = (int) $row['doc_id'];
        $ids[] = $id;
        $signature[] = ['doc_id' => $id, 'score' => round((float) $row['score'], 8)];
    }
    wp_fts_reader_assert(
        $ids !== []
            && count($ids) <= 20
            && count($ids) === count(array_unique($ids))
            && !array_key_exists('retrieval_mode', $payload)
            && !array_key_exists('total_is_exact', $payload)
            && !array_key_exists('results_may_be_incomplete', $payload),
        "Concurrent reader {$caseId} received a payload outside the bounded current contract."
    );
    $hash = hash('sha256', json_encode($signature, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    $expectedIds = is_array($expected['ids'] ?? null) ? array_map('intval', $expected['ids']) : [];
    wp_fts_reader_assert(
        $ids === $expectedIds && is_string($expected['hash'] ?? null) && hash_equals($expected['hash'], $hash),
        "{$caseId} differs from the frozen construction-known concurrency baseline."
    );

    return [
        'case' => $caseId,
        'duration_ms' => $durationMs,
        'count' => count($ids),
        'hash' => $hash,
        'attempts' => $attempts,
        'unavailable_retries' => $unavailableRetries,
    ];
}

function wp_fts_reader_is_publication_retry(mixed $body, int $status, string $curlError): bool
{
    if (!is_string($body) || $curlError !== '' || $status !== 503 || strlen($body) > 4194304) {
        return false;
    }
    try {
        $payload = json_decode($body, true, 64, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        return false;
    }

    return is_array($payload)
        && ($payload['code'] ?? null) === 'wp_fts_search_unavailable'
        && is_array($payload['data'] ?? null)
        && ($payload['data']['status'] ?? null) === 503;
}

/** @return array<string,mixed> */
function wp_fts_reader_json(string $path): array
{
    $bytes = file_get_contents($path);
    wp_fts_reader_assert(is_string($bytes) && strlen($bytes) <= 4194304, "Could not read bounded JSON artifact: {$path}");
    $value = json_decode($bytes, true, 64, JSON_THROW_ON_ERROR);
    wp_fts_reader_assert(is_array($value), "JSON artifact is not an object: {$path}");

    return $value;
}

/** @param array<string,mixed> $value */
function wp_fts_reader_write_json(string $path, array $value): void
{
    $json = json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
    $temporary = $path . '.tmp.' . getmypid();
    $written = file_put_contents($temporary, $json, LOCK_EX);
    if ($written !== strlen($json) || !rename($temporary, $path)) {
        @unlink($temporary);
        throw new RuntimeException("Could not atomically write JSON artifact: {$path}");
    }
}

function wp_fts_reader_rss_peak_bytes(): int
{
    $lines = file('/proc/self/status', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (!is_array($lines)) {
        return 0;
    }
    foreach ($lines as $line) {
        $parts = explode(':', $line, 2);
        if (count($parts) !== 2 || trim($parts[0]) !== 'VmHWM') {
            continue;
        }
        $fields = explode(' ', trim($parts[1]));
        $fields = array_values(array_filter($fields, static fn(string $field): bool => $field !== ''));
        if (count($fields) === 2 && ctype_digit($fields[0]) && strtolower($fields[1]) === 'kb') {
            return (int) $fields[0] * 1024;
        }
    }

    return 0;
}

function wp_fts_reader_required_env(string $name): string
{
    $value = getenv($name);
    if (!is_string($value) || $value === '') {
        throw new RuntimeException("Missing required environment variable: {$name}");
    }

    return $value;
}

function wp_fts_reader_non_negative_int_env(string $name): int
{
    $value = wp_fts_reader_required_env($name);
    wp_fts_reader_assert(ctype_digit($value), "{$name} must be a non-negative integer.");

    return (int) $value;
}

function wp_fts_reader_positive_int_env(string $name): int
{
    $value = wp_fts_reader_non_negative_int_env($name);
    wp_fts_reader_assert($value > 0, "{$name} must be a positive integer.");

    return $value;
}

function wp_fts_reader_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
