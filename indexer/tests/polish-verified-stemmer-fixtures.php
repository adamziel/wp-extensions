<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';

function wp_fts_pvs_fail(string $message): never
{
    fwrite(STDERR, "FAIL: {$message}\n");
    exit(1);
}

function wp_fts_pvs_assert(bool $condition, string $message): void
{
    if (!$condition) {
        wp_fts_pvs_fail($message);
    }
}

$manifest = WP_FTS_PolishVerifiedStemmerData::manifest();
$normalizer = new WP_FTS_Normalizer();
$verified = new WP_FTS_PolishStemmer('verified');
$baseline = new WP_FTS_PolishStemmer('conservative');
$stemMap = WP_FTS_PolishVerifiedStemmerData::stem_map();
$rows = 0;
$improvements = 0;

wp_fts_pvs_assert(
    ($manifest['version'] ?? '') === WP_FTS_PolishVerifiedStemmerData::VERSION,
    'manifest version must match runtime data version'
);
wp_fts_pvs_assert(
    str_contains(strtolower((string) ($manifest['boundary'] ?? '')), 'not a full'),
    'manifest must document that this is not full dictionary lemmatization'
);
wp_fts_pvs_assert(count($stemMap) < 100, 'fixture slice must remain compact');

foreach (WP_FTS_PolishVerifiedStemmerData::reference_groups() as $group) {
    wp_fts_pvs_assert((string) $group['id'] !== '', 'fixture group id is required');
    wp_fts_pvs_assert((string) $group['stem'] !== '', "fixture group {$group['id']} stem is required");
    foreach ($group['forms'] as $form) {
        $rows++;
        wp_fts_pvs_assert(
            $form['term'] === $normalizer->normalize_token($form['source'], 'pl'),
            "source {$form['source']} must normalize to {$form['term']}"
        );
        wp_fts_pvs_assert(
            $verified->stem($form['term'], 'pl-PL') === $group['stem'],
            "verified stem for {$form['source']} must be {$group['stem']}"
        );
        if ($baseline->stem($form['term'], 'pl') !== $group['stem']) {
            $improvements++;
        }
    }
}

foreach (WP_FTS_PolishVerifiedStemmerData::protected_rows() as $row) {
    wp_fts_pvs_assert(
        $row['term'] === $normalizer->normalize_token($row['source'], 'pl'),
        "protected source {$row['source']} must normalize to {$row['term']}"
    );
    wp_fts_pvs_assert(
        $verified->stem($row['term'], 'pl') === $row['stem'],
        "protected row {$row['source']} must remain {$row['stem']}"
    );
    wp_fts_pvs_assert((string) $row['reason'] !== '', "protected row {$row['source']} must keep provenance reason");
}

foreach (WP_FTS_PolishVerifiedStemmerData::fallback_rows() as $row) {
    wp_fts_pvs_assert(
        $row['term'] === $normalizer->normalize_token($row['source'], 'pl'),
        "fallback source {$row['source']} must normalize to {$row['term']}"
    );
    wp_fts_pvs_assert(
        $verified->stem($row['term'], 'pl') === $row['stem'],
        "fallback row {$row['source']} must stem to {$row['stem']}"
    );
}

wp_fts_pvs_assert($rows >= 60, "fixture row count should be reviewable but meaningful; saw {$rows}");
wp_fts_pvs_assert($improvements >= 25, "fixture should improve suffix-only baseline; saw {$improvements}");

echo "PASS: Polish verified stemmer fixtures validated ({$rows} rows, {$improvements} suffix-baseline improvements).\n";
