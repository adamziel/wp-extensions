<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/src/bootstrap.php';
require_once __DIR__ . '/lib/TokenizerSourceLockVerifier.php';

$allowTestFixtures = false;
$expectInvalid = false;
$paths = [];

foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--allow-test-fixtures') {
        $allowTestFixtures = true;
        continue;
    }

    if ($arg === '--expect-invalid') {
        $expectInvalid = true;
        continue;
    }

    if ($arg === '--help' || $arg === '-h') {
        fwrite(STDOUT, "Usage: php indexer/tools/verify-tokenizer-source-lock.php [--allow-test-fixtures] [--expect-invalid] <metadata.json> [...]\n");
        exit(0);
    }

    if (str_starts_with($arg, '-')) {
        fwrite(STDERR, "Unknown option: {$arg}\n");
        exit(2);
    }

    $paths[] = $arg;
}

if ($paths === []) {
    fwrite(STDERR, "Usage: php indexer/tools/verify-tokenizer-source-lock.php [--allow-test-fixtures] [--expect-invalid] <metadata.json> [...]\n");
    exit(2);
}

$verifier = new WP_FTS_TokenizerSourceLockVerifier();
$failed = false;

foreach ($paths as $path) {
    $errors = $verifier->validate_file($path, [
        'allow_test_fixture' => $allowTestFixtures,
    ]);

    if ($expectInvalid) {
        if ($errors === []) {
            fwrite(STDERR, "FAIL expected invalid source-lock metadata: {$path}\n");
            $failed = true;
            continue;
        }

        fwrite(STDOUT, "PASS rejected incomplete source-lock metadata: {$path}\n");
        foreach ($errors as $error) {
            fwrite(STDOUT, " - {$error}\n");
        }
        continue;
    }

    if ($errors !== []) {
        fwrite(STDERR, "FAIL source-lock metadata: {$path}\n");
        foreach ($errors as $error) {
            fwrite(STDERR, " - {$error}\n");
        }
        $failed = true;
        continue;
    }

    fwrite(STDOUT, "PASS source-lock metadata: {$path}\n");
}

exit($failed ? 1 : 0);
