<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';
require_once __DIR__ . '/../src/WPCLICommand.php';
require_once __DIR__ . '/../tools/lib/TokenizerSourceLockVerifier.php';
require_once __DIR__ . '/../tools/lib/TokenizerSourceCandidateLockVerifier.php';

/** Reach the private production storage factory only from test fixtures. */
function wp_fts_test_storage(): WP_FTS_Relational_Storage
{
    $method = new ReflectionMethod(WP_FTS_Plugin::class, 'storage');
    $method->setAccessible(true);

    return $method->invoke(null);
}
