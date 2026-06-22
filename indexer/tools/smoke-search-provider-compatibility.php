<?php
declare(strict_types=1);

/**
 * CLI wrapper for the optional provider-compatibility WordPress smoke.
 */

require_once dirname(__DIR__) . '/tests/integration/provider-compatibility-wordpress.php';

try {
    exit(wp_fts_provider_compatibility_wordpress_main());
} catch (Throwable $e) {
    fwrite(STDERR, "FAIL: {$e->getMessage()}\n");
    exit(1);
}
