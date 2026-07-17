<?php
declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/components/full-text-search/src/bootstrap.php';

try {
    if (count($argv) !== 6) {
        throw new InvalidArgumentException('Expected state path, barrier path, worker id, document id, and lock delay.');
    }

    [, $path, $barrier, $workerId, $rawDocId, $rawDelay] = $argv;
    $docId = (int) $rawDocId;
    $delay = max(0, (int) $rawDelay);
    $storage = new WP_FTS_Storage_File($path);

    if (file_put_contents($barrier . '.ready.' . $workerId, 'ready') === false) {
        throw new RuntimeException('Could not publish worker readiness.');
    }

    $deadline = microtime(true) + 10.0;
    while (!is_file($barrier . '.go')) {
        if (microtime(true) >= $deadline) {
            throw new RuntimeException('Timed out waiting for the contention barrier.');
        }
        usleep(10000);
    }

    $storage->begin_transaction();
    try {
        usleep($delay);
        $term = WP_FTS_TermNamespace::namespace_term('en', 'worker-' . $workerId);
        $storage->put_doc($docId, 'en', ['en' => 1], 'hash-' . $workerId);
        $storage->put_term($term, 1, WP_FTS_PostingsCodec::encode([$docId => 1]));
        $storage->commit();
    } catch (Throwable $error) {
        $storage->rollback();
        throw $error;
    }

    fwrite(STDOUT, "committed {$workerId}\n");
} catch (Throwable $error) {
    fwrite(STDERR, $error::class . ': ' . $error->getMessage() . "\n");
    exit(1);
}
