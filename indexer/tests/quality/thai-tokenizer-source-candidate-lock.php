<?php
declare(strict_types=1);

/**
 * @return array<string,mixed>
 */
function wp_fts_thai_source_candidate_lock_fixture(): array
{
    $path = dirname(__DIR__) . '/..' . '/review-artifacts/source-locks/thai-tokenizer-source-candidate-preflight.json';
    $json = file_get_contents($path);
    if (!is_string($json)) {
        throw new WP_FTS_TestFailure('Could not read Thai tokenizer source-candidate lock fixture.');
    }

    $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($decoded) || array_is_list($decoded)) {
        throw new WP_FTS_TestFailure('Thai tokenizer source-candidate lock fixture must decode to an object.');
    }

    return $decoded;
}

test_case('thai tokenizer source-candidate lock passes only as pending preflight', function (): void {
    $verifier = new WP_FTS_ThaiTokenizerSourceCandidateLockVerifier();
    $fixture = wp_fts_thai_source_candidate_lock_fixture();

    $report = $verifier->verify($fixture, 'thai-preflight-fixture', true, dirname(__DIR__, 3));
    assert_same(true, $report['valid'], 'Thai tokenizer candidate preflight should pass with pending exact values allowed.');
    assert_same(true, $report['blocks_adapter'], 'Thai tokenizer candidate preflight should block adapter work.');
    assert_same([
        'dictionary.version_ref',
        'dictionary.artifact.name',
        'dictionary.artifact.url',
        'dictionary.artifact.sha256',
        'dictionary.artifact.bytes',
        'dictionary.license.identifier',
        'dictionary.license.name',
        'dictionary.license.url',
        'dictionary.license.text_url',
        'tcc_rules.variant',
        'tcc_rules.rule_artifact.name',
        'tcc_rules.rule_artifact.url',
        'tcc_rules.rule_artifact.sha256',
        'tcc_rules.rule_artifact.bytes',
    ], $report['pending_exact_values'], 'Thai tokenizer candidate preflight should enumerate every missing exact identity field.');

    $strict = $verifier->verify($fixture, 'thai-preflight-fixture');
    assert_same(false, $strict['valid'], 'Thai tokenizer candidate preflight should fail strict source-lock mode.');
    assert_contains('dictionary.artifact.sha256 must be a concrete source-lock value before adapter work.', implode("\n", $strict['errors']), 'Strict mode should require dictionary digest evidence.');
    assert_contains('tcc_rules.rule_artifact.sha256 must be a concrete source-lock value before adapter work.', implode("\n", $strict['errors']), 'Strict mode should require TCC rule digest evidence.');
});

test_case('thai tokenizer source-candidate verifier rejects support claims and copied-rule risk', function (): void {
    $verifier = new WP_FTS_ThaiTokenizerSourceCandidateLockVerifier();
    $fixture = wp_fts_thai_source_candidate_lock_fixture();
    $fixture['claim_boundaries']['real_thai_segmentation_shipped'] = true;
    $fixture['tcc_rules']['clean_room']['no_upstream_regex_or_grammar_copied'] = false;
    $fixture['license_source_chain_questions'][0]['blocks_adapter'] = false;

    $report = $verifier->verify($fixture, 'mutated-thai-preflight-fixture', true, dirname(__DIR__, 3));
    assert_same(false, $report['valid'], 'Thai tokenizer candidate verifier should reject support claims and copied-rule risk.');

    $errors = implode("\n", $report['errors']);
    assert_contains('claim_boundaries.real_thai_segmentation_shipped must be false.', $errors, 'Verifier should reject Thai support claims in preflight.');
    assert_contains('tcc_rules.clean_room.no_upstream_regex_or_grammar_copied must be true.', $errors, 'Verifier should reject copied TCC grammar risk.');
    assert_contains('license_source_chain_questions[0].blocks_adapter must be true.', $errors, 'Verifier should require license questions to block adapter work.');
});

test_case('thai tokenizer source-candidate CLI reports pending blockers', function (): void {
    $command = implode(' ', [
        escapeshellarg(PHP_BINARY),
        escapeshellarg(dirname(__DIR__) . '/../tools/verify-thai-tokenizer-source-candidate-lock.php'),
        escapeshellarg(dirname(__DIR__) . '/../review-artifacts/source-locks/thai-tokenizer-source-candidate-preflight.json'),
        '--allow-pending-exact-values',
        '--json',
    ]);

    $lines = [];
    $exit_code = 0;
    exec($command . ' 2>&1', $lines, $exit_code);
    $output = implode("\n", $lines);

    assert_same(0, $exit_code, 'Thai tokenizer source-candidate CLI should pass the preflight lock.');
    assert_contains('"valid": true', $output, 'CLI JSON should report a valid preflight.');
    assert_contains('"blocks_adapter": true', $output, 'CLI JSON should report that adapter work remains blocked.');
    assert_contains('"dictionary.artifact.sha256"', $output, 'CLI JSON should expose pending dictionary digest evidence.');
    assert_contains('"tcc_rules.rule_artifact.sha256"', $output, 'CLI JSON should expose pending TCC rule digest evidence.');
});

test_case('thai tokenizer source-candidate docs keep real segmentation unshipped', function (): void {
    $docs = (string) file_get_contents(dirname(__DIR__) . '/../docs/tokenizer-source-locks.md')
        . "\n"
        . (string) file_get_contents(dirname(__DIR__) . '/../docs/limitations.md')
        . "\n"
        . (string) file_get_contents(dirname(__DIR__) . '/../docs/testing.md');

    assert_contains('does not currently ship real Thai segmentation', $docs, 'Docs should say real Thai segmentation is not shipped.');
    assert_contains('no dictionary rows, TCC/TCC+ rules, or tokenizer adapter are committed', $docs, 'Docs should fence the candidate lock from runtime support.');
    assert_contains('verify-thai-tokenizer-source-candidate-lock.php', $docs, 'Docs should name the source-candidate verifier command.');
});
