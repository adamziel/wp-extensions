<?php
declare(strict_types=1);

/**
 * @return array<string,mixed>
 */
function wp_fts_tsl_fixture(string $name): array
{
    $path = dirname(__DIR__) . '/fixtures/tokenizer-source-lock/' . $name;
    $json = file_get_contents($path);
    if ($json === false) {
        throw new WP_FTS_TestFailure("Could not read tokenizer source-lock fixture {$name}.");
    }

    $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($decoded)) {
        throw new WP_FTS_TestFailure("Tokenizer source-lock fixture {$name} must decode to an object.");
    }

    return $decoded;
}

test_case('thai tokenizer source-lock verifier accepts only complete fixture metadata in test mode', function (): void {
    $verifier = new WP_FTS_TokenizerSourceLockVerifier();
    $complete = wp_fts_tsl_fixture('complete-test-fixture.json');

    assert_same([], $verifier->validate($complete, ['allow_test_fixture' => true]), 'complete test fixture metadata should satisfy every source-lock field');

    $productionErrors = $verifier->validate($complete);
    assert_true($productionErrors !== [], 'test fixtures should not be accepted as production source locks');
    assert_true(
        in_array('metadata_kind marks this record as a test fixture; pass allow_test_fixture only in tests.', $productionErrors, true),
        'test fixture metadata should be explicitly fenced from production validation'
    );
});

test_case('thai tokenizer source-lock verifier rejects incomplete metadata', function (): void {
    $verifier = new WP_FTS_TokenizerSourceLockVerifier();
    $incomplete = wp_fts_tsl_fixture('incomplete-missing-approval.json');
    $errors = $verifier->validate($incomplete);

    assert_true($errors !== [], 'incomplete Thai tokenizer source-lock metadata must be rejected');
    assert_true(in_array('status must be approved.', $errors, true), 'draft source locks should fail approval status');
    assert_true(in_array('dictionary.source_name must be a non-empty string.', $errors, true), 'dictionary identity must include a source name');
    assert_true(in_array('dictionary.sha256 must be a lowercase 64-character SHA-256 hex digest.', $errors, true), 'dictionary artifact hash is required');
    assert_true(in_array('approval must be an object.', $errors, true), 'maintainer/legal approval is required');
    assert_true(in_array('no_go_conditions must be an object.', $errors, true), 'no-go conditions are required');
});
