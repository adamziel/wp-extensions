#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Compile concept membership rows into the runtime synsets.tsv format.
 *
 * Input format:
 *   concept_id<TAB>canonical_term
 *
 * Terms must already be normalized canonical keys for the target language.
 * This helper is intentionally small so larger WordNet/plWordNet-style import
 * pipelines can perform license review, normalization, and canonicalization
 * before writing this intermediate source.
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "compile-synsets.php must run from the command line.\n");
    exit(1);
}

$args = $_SERVER['argv'] ?? [];
if (count($args) !== 5) {
    fwrite(
        STDERR,
        "Usage: php compile-synsets.php <source.tsv> <output.tsv> <weight> <provenance>\n" .
        "Source rows: concept_id<TAB>canonical_term\n"
    );
    exit(1);
}

[, $source_path, $output_path, $weight_raw, $provenance] = $args;
$weight = validate_weight($weight_raw);
$provenance = trim($provenance);
if ($provenance === '') {
    fwrite(STDERR, "Provenance must be non-empty.\n");
    exit(1);
}

$lines = file($source_path, FILE_IGNORE_NEW_LINES);
if ($lines === false) {
    fwrite(STDERR, "Could not read source file: {$source_path}\n");
    exit(1);
}

$concepts = [];
foreach ($lines as $line_number => $line) {
    $line = trim((string) $line);
    if ($line === '' || str_starts_with($line, '#')) {
        continue;
    }

    $columns = explode("\t", $line);
    if (count($columns) !== 2) {
        fwrite(STDERR, source_error($source_path, $line_number + 1, 'rows must have exactly 2 tab-separated columns') . "\n");
        exit(1);
    }

    $concept_id = validate_token($columns[0], $source_path, $line_number + 1, 'concept id');
    $term = validate_token($columns[1], $source_path, $line_number + 1, 'canonical term');
    validate_lowercase($term, $source_path, $line_number + 1, 'canonical term');
    $concepts[$concept_id][$term] = true;
}

ksort($concepts, SORT_STRING);
$output = ["# concept_id\tweight\tprovenance\tterms"];
foreach ($concepts as $concept_id => $term_lookup) {
    $terms = array_keys($term_lookup);
    sort($terms, SORT_STRING);
    if (count($terms) < 2) {
        fwrite(STDERR, "Concept {$concept_id} must contain at least 2 terms.\n");
        exit(1);
    }

    $output[] = $concept_id . "\t" . $weight . "\t" . $provenance . "\t" . implode(' ', $terms);
}

if (file_put_contents($output_path, implode("\n", $output) . "\n") === false) {
    fwrite(STDERR, "Could not write output file: {$output_path}\n");
    exit(1);
}

echo 'Compiled ' . count($concepts) . " synset rows to {$output_path}\n";

function validate_weight(string $weight_raw): string
{
    $weight_raw = trim($weight_raw);
    if (!is_numeric($weight_raw)) {
        fwrite(STDERR, "Weight must be numeric.\n");
        exit(1);
    }

    $weight = (float) $weight_raw;
    if ($weight <= 0.0 || $weight > 1.0) {
        fwrite(STDERR, "Weight must be greater than 0 and no more than 1.\n");
        exit(1);
    }

    return $weight_raw;
}

function validate_token(string $value, string $path, int $line_number, string $label): string
{
    $token = trim($value);
    if ($token === '') {
        fwrite(STDERR, source_error($path, $line_number, "{$label} must be non-empty") . "\n");
        exit(1);
    }

    $has_whitespace = preg_match('/\s/u', $token);
    if ($has_whitespace === false || $has_whitespace === 1 || str_contains($token, '#')) {
        fwrite(STDERR, source_error($path, $line_number, "{$label} must not contain whitespace or #") . "\n");
        exit(1);
    }

    if (strlen($token) > 255) {
        fwrite(STDERR, source_error($path, $line_number, "{$label} must be 255 bytes or shorter") . "\n");
        exit(1);
    }

    return $token;
}

function validate_lowercase(string $token, string $path, int $line_number, string $label): void
{
    $lowercase = function_exists('mb_strtolower') ? mb_strtolower($token, 'UTF-8') : strtolower($token);
    if ($token !== $lowercase) {
        fwrite(STDERR, source_error($path, $line_number, "{$label} must be normalized lowercase") . "\n");
        exit(1);
    }
}

function source_error(string $path, int $line_number, string $message): string
{
    return "{$path}:{$line_number}: {$message}";
}
