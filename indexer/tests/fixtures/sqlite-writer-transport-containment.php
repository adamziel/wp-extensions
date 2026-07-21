<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/src/bootstrap.php';

final class WP_FTS_SQLite_Transport_Fixture_Driver
{
}

final class WP_FTS_SQLite_Transport_Fixture_WPDB
{
    public string $prefix = 'wp_';
    public object $dbh;

    /** Expose only the SQLite driver identity needed by transport preflight. */
    public function __construct()
    {
        $this->dbh = new WP_FTS_SQLite_Transport_Fixture_Driver();
    }
}

$language = str_repeat('a', 32);
$documents = [];
$identity = 0;
for ($document = 1; $document <= WP_FTS_Storage_Mysql::MAX_BATCH_DOCUMENTS; $document++) {
    $frequencies = [];
    $remainingDocuments = WP_FTS_Storage_Mysql::MAX_BATCH_DOCUMENTS - $document + 1;
    $remainingIdentities = WP_FTS_Storage_Mysql::MAX_BATCH_TERMS - $identity;
    $documentIdentities = (int) ceil($remainingIdentities / $remainingDocuments);
    for ($row = 0; $row < $documentIdentities; $row++) {
        $suffix = str_pad((string) $identity, 8, '0', STR_PAD_LEFT);
        $term = str_repeat('x', 255 - strlen($suffix)) . $suffix;
        $frequencies[WP_FTS_TermNamespace::namespace_term($language, $term)] = 1;
        $identity++;
    }
    $documents[] = [
        'post_id' => $document,
        'term_frequencies' => $frequencies,
        'surface_frequencies' => [],
    ];
}

$storage = new WP_FTS_Storage_Mysql(new WP_FTS_SQLite_Transport_Fixture_WPDB());
$preflight = new ReflectionMethod($storage, 'sqlite_prepared_transport_prefix');
$started = microtime(true);
$result = $preflight->invoke($storage, $documents);
$elapsed = microtime(true) - $started;

if (
    !is_array($result)
    || ($result['accepted_documents'] ?? 0) <= 0
    || ($result['accepted_documents'] ?? 100) >= 100
    || ($result['identity_visits'] ?? PHP_INT_MAX) > WP_FTS_Storage_Mysql::MAX_BATCH_TERMS
    || max((int) ($result['dictionary_bytes'] ?? 0), (int) ($result['resolution_bytes'] ?? 0)) <= 4194304
) {
    fwrite(STDERR, "SQLite transport preflight did not stop at the exact bounded prefix.\n");
    exit(1);
}

echo json_encode([
    ...$result,
    'input_documents' => count($documents),
    'input_identities' => $identity,
    'elapsed_seconds' => $elapsed,
    'peak_bytes' => memory_get_peak_usage(true),
], JSON_THROW_ON_ERROR) . "\n";
