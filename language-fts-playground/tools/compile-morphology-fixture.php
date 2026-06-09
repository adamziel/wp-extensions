#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Compile small reviewed morphology fixtures into compact lexical pack files.
 *
 * The compiler intentionally accepts only JSON fixtures and PHP-core runtime
 * APIs so it can run under `php -n` in CI and review workflows.
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "compile-morphology-fixture.php must run from the command line.\n");
    exit(1);
}

final class Language_FTS_Playground_Morphology_Fixture_Exception extends RuntimeException
{
}

if (realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    exit(language_fts_morphology_fixture_main($_SERVER['argv'] ?? []));
}

/**
 * @param string[] $argv
 */
function language_fts_morphology_fixture_main(array $argv): int
{
    try {
        if (isset($argv[1]) && in_array($argv[1], ['--help', '-h'], true)) {
            language_fts_morphology_fixture_usage(STDOUT);

            return 0;
        }

        if (count($argv) < 3) {
            language_fts_morphology_fixture_usage(STDERR);

            return 1;
        }

        $input_path = (string) $argv[1];
        $output_dir = (string) $argv[2];
        $options = language_fts_morphology_fixture_parse_options(array_slice($argv, 3));
        $summary = language_fts_morphology_fixture_compile($input_path, $output_dir, $options);

        echo 'Compiled morphology fixture for ' . $summary['language'] . ' to ' . $summary['output_dir'] . "\n";
        echo 'Wrote ' . $summary['term_rule_count'] . ' term rule rows, ' . $summary['stopword_count'] . ' stopwords, and ' . $summary['protected_term_count'] . " protected terms.\n";

        return 0;
    } catch (Language_FTS_Playground_Morphology_Fixture_Exception $exception) {
        fwrite(STDERR, $exception->getMessage() . "\n");

        return 1;
    }
}

/**
 * @param resource $stream
 */
function language_fts_morphology_fixture_usage($stream): void
{
    fwrite(
        $stream,
        "Usage: php compile-morphology-fixture.php <fixture.json> <output-dir> [--file-only]\n" .
        "Compiles schema language-fts-playground-morphology-fixture-v1 into a minimal lexical pack.\n" .
        "Options:\n" .
        "  --file-only    Write only term_rules.tsv, protected_terms.txt, and stopwords.txt.\n"
    );
}

/**
 * @param string[] $args
 * @return array{file_only:bool}
 */
function language_fts_morphology_fixture_parse_options(array $args): array
{
    $options = ['file_only' => false];

    foreach ($args as $arg) {
        $arg = (string) $arg;
        if ($arg === '--file-only') {
            $options['file_only'] = true;
            continue;
        }

        throw new Language_FTS_Playground_Morphology_Fixture_Exception('Unknown option: ' . $arg);
    }

    return $options;
}

/**
 * @param array{file_only?:bool} $options
 * @return array{language:string,output_dir:string,term_rule_count:int,stopword_count:int,protected_term_count:int}
 */
function language_fts_morphology_fixture_compile(string $input_path, string $output_dir, array $options = []): array
{
    $fixture = language_fts_morphology_fixture_read_json($input_path);
    $compiled = language_fts_morphology_fixture_compile_data($fixture, $input_path);
    $output_dir = language_fts_morphology_fixture_prepare_output_dir($output_dir);
    $file_only = (bool) ($options['file_only'] ?? false);

    language_fts_morphology_fixture_write_file(
        $output_dir . DIRECTORY_SEPARATOR . 'term_rules.tsv',
        language_fts_morphology_fixture_render_term_rules($compiled)
    );
    language_fts_morphology_fixture_write_file(
        $output_dir . DIRECTORY_SEPARATOR . 'protected_terms.txt',
        language_fts_morphology_fixture_render_terms('protected terms', $compiled['provenance'], $compiled['protected_terms'])
    );
    language_fts_morphology_fixture_write_file(
        $output_dir . DIRECTORY_SEPARATOR . 'stopwords.txt',
        language_fts_morphology_fixture_render_terms('stopwords', $compiled['provenance'], $compiled['stopwords'])
    );

    if (!$file_only) {
        language_fts_morphology_fixture_write_file(
            $output_dir . DIRECTORY_SEPARATOR . 'lexemes.tsv',
            language_fts_morphology_fixture_render_lexemes($compiled)
        );
        language_fts_morphology_fixture_write_file(
            $output_dir . DIRECTORY_SEPARATOR . 'synonyms.tsv',
            language_fts_morphology_fixture_render_synonyms($compiled)
        );
        language_fts_morphology_fixture_write_file(
            $output_dir . DIRECTORY_SEPARATOR . 'profile.php',
            language_fts_morphology_fixture_render_profile($compiled)
        );
        language_fts_morphology_fixture_write_file(
            $output_dir . DIRECTORY_SEPARATOR . 'pack.php',
            language_fts_morphology_fixture_render_pack($compiled)
        );
    }

    return [
        'language' => $compiled['language'],
        'output_dir' => $output_dir,
        'term_rule_count' => count($compiled['term_rules']),
        'stopword_count' => count($compiled['stopwords']),
        'protected_term_count' => count($compiled['protected_terms']),
    ];
}

/**
 * @return array<string,mixed>
 */
function language_fts_morphology_fixture_read_json(string $input_path): array
{
    if (!is_file($input_path)) {
        throw new Language_FTS_Playground_Morphology_Fixture_Exception('Fixture file does not exist: ' . $input_path);
    }

    $json = file_get_contents($input_path);
    if (!is_string($json)) {
        throw new Language_FTS_Playground_Morphology_Fixture_Exception('Could not read fixture file: ' . $input_path);
    }

    $decoded = json_decode($json, true);
    if (!is_array($decoded)) {
        throw new Language_FTS_Playground_Morphology_Fixture_Exception('Fixture JSON must decode to an object: ' . $input_path);
    }

    return $decoded;
}

function language_fts_morphology_fixture_prepare_output_dir(string $output_dir): string
{
    $output_dir = trim($output_dir);
    if ($output_dir === '') {
        throw new Language_FTS_Playground_Morphology_Fixture_Exception('Output directory must be non-empty.');
    }

    if (is_file($output_dir)) {
        throw new Language_FTS_Playground_Morphology_Fixture_Exception('Output path is a file, not a directory: ' . $output_dir);
    }

    if (!is_dir($output_dir) && !mkdir($output_dir, 0777, true)) {
        throw new Language_FTS_Playground_Morphology_Fixture_Exception('Could not create output directory: ' . $output_dir);
    }

    $real = realpath($output_dir);
    if ($real === false || !is_dir($real)) {
        throw new Language_FTS_Playground_Morphology_Fixture_Exception('Could not resolve output directory: ' . $output_dir);
    }

    return rtrim($real, DIRECTORY_SEPARATOR);
}

/**
 * @param array<string,mixed> $fixture
 * @return array{language:string,label:string,order:int,folds:array<string,string>,source:array{name:string,reference_kind:string,source_url:string,license_name:string,attribution:string},provenance:string,pack_version:string,pack_date:string,term_rules:array<int,array{id:string,min_term_length:int,pattern:string,strip_prefix:string,strip_suffix:string,append:string,min_key_length:int,flags:string[],alternate_pattern:string,alternate_replacement:string,provenance:string}>,protected_terms:string[],stopwords:string[],lexemes:array<int,array{observed:string,canonical:string,provenance:string}>,synonyms:array<int,array{source:string,target:string,direction:string,weight:string,provenance:string}>}
 */
function language_fts_morphology_fixture_compile_data(array $fixture, string $path): array
{
    $schema = language_fts_morphology_fixture_required_string($fixture, 'schema', $path);
    if ($schema !== 'language-fts-playground-morphology-fixture-v1') {
        throw new Language_FTS_Playground_Morphology_Fixture_Exception($path . ': schema must be language-fts-playground-morphology-fixture-v1');
    }

    $language = language_fts_morphology_fixture_language_id(
        language_fts_morphology_fixture_required_string($fixture, 'language', $path),
        $path . ': language'
    );
    $provenance = language_fts_morphology_fixture_tsv_field(
        language_fts_morphology_fixture_required_string($fixture, 'provenance', $path),
        $path . ': provenance'
    );

    $normalization = language_fts_morphology_fixture_required_object($fixture, 'normalization', $path);
    $normalization_profile = language_fts_morphology_fixture_required_string($normalization, 'profile', $path . ': normalization');
    if ($normalization_profile !== $language) {
        throw new Language_FTS_Playground_Morphology_Fixture_Exception($path . ': normalization.profile must match language for generated packs');
    }
    $folds = language_fts_morphology_fixture_optional_string_map($normalization['fold'] ?? [], $path . ': normalization.fold');

    $source = language_fts_morphology_fixture_source($fixture, $path);
    $term_rules = language_fts_morphology_fixture_term_rules($fixture, $path, $folds, $provenance);
    $protected_terms = language_fts_morphology_fixture_terms($fixture['protected_terms'] ?? [], 'protected term', $path, $folds);
    $stopwords = language_fts_morphology_fixture_terms($fixture['stopword_excerpt'] ?? [], 'stopword', $path, $folds);
    $lexemes = language_fts_morphology_fixture_lexemes($fixture['lexemes'] ?? [], $path, $folds, $provenance);
    $synonyms = language_fts_morphology_fixture_synonyms($fixture['synonyms'] ?? [], $path, $folds, $provenance);

    language_fts_morphology_fixture_sample_pairs($fixture['stemmer_sample_pairs'] ?? [], $path, $term_rules);
    language_fts_morphology_fixture_analyzer_expectations($fixture['analyzer_expectations'] ?? [], $path, $folds);

    return [
        'language' => $language,
        'label' => language_fts_morphology_fixture_optional_string($fixture['label'] ?? null, ucfirst($language)),
        'order' => language_fts_morphology_fixture_optional_positive_int($fixture['order'] ?? 100, $path . ': order'),
        'folds' => $folds,
        'source' => $source,
        'provenance' => $provenance,
        'pack_version' => language_fts_morphology_fixture_optional_string($fixture['pack_version'] ?? null, $provenance . '-fixture-v1'),
        'pack_date' => language_fts_morphology_fixture_pack_date((string) ($fixture['pack_date'] ?? '2026-06-09'), $path),
        'term_rules' => $term_rules,
        'protected_terms' => $protected_terms,
        'stopwords' => $stopwords,
        'lexemes' => $lexemes,
        'synonyms' => $synonyms,
    ];
}

/**
 * @param array<string,mixed> $fixture
 * @return array{name:string,reference_kind:string,source_url:string,license_name:string,attribution:string}
 */
function language_fts_morphology_fixture_source(array $fixture, string $path): array
{
    $source = language_fts_morphology_fixture_required_object($fixture, 'source', $path);
    $reference_kind = language_fts_morphology_fixture_required_string($source, 'reference_kind', $path . ': source');
    if (!in_array($reference_kind, ['synthetic_project_fixture', 'reviewed_sample_behavior_only', 'external_optional_smoke'], true)) {
        throw new Language_FTS_Playground_Morphology_Fixture_Exception($path . ': source.reference_kind must be synthetic_project_fixture, reviewed_sample_behavior_only, or external_optional_smoke');
    }
    if ($reference_kind === 'snowball_compliance') {
        throw new Language_FTS_Playground_Morphology_Fixture_Exception($path . ': source.reference_kind must not claim snowball_compliance');
    }

    return [
        'name' => language_fts_morphology_fixture_metadata_text(language_fts_morphology_fixture_required_string($source, 'name', $path . ': source'), $path . ': source.name'),
        'reference_kind' => $reference_kind,
        'source_url' => language_fts_morphology_fixture_metadata_text(language_fts_morphology_fixture_required_string($source, 'source_url', $path . ': source'), $path . ': source.source_url'),
        'license_name' => language_fts_morphology_fixture_metadata_text(language_fts_morphology_fixture_required_string($source, 'license_name', $path . ': source'), $path . ': source.license_name'),
        'attribution' => language_fts_morphology_fixture_metadata_text(language_fts_morphology_fixture_required_string($source, 'attribution', $path . ': source'), $path . ': source.attribution'),
    ];
}

/**
 * @param array<string,mixed> $fixture
 * @param array<string,string> $folds
 * @return array<int,array{id:string,min_term_length:int,pattern:string,strip_prefix:string,strip_suffix:string,append:string,min_key_length:int,flags:string[],alternate_pattern:string,alternate_replacement:string,provenance:string}>
 */
function language_fts_morphology_fixture_term_rules(array $fixture, string $path, array $folds, string $fixture_provenance): array
{
    $rows = language_fts_morphology_fixture_required_list($fixture, 'term_rule_behaviors', $path);
    if ($rows === []) {
        throw new Language_FTS_Playground_Morphology_Fixture_Exception($path . ': term_rule_behaviors must contain at least one rule');
    }

    $rules = [];
    $ids = [];
    foreach ($rows as $index => $row) {
        if (!is_array($row)) {
            throw new Language_FTS_Playground_Morphology_Fixture_Exception($path . ': term_rule_behaviors[' . $index . '] must be an object');
        }

        $label = $path . ': term_rule_behaviors[' . $index . ']';
        $id = language_fts_morphology_fixture_tsv_field(
            language_fts_morphology_fixture_required_string($row, 'id', $label),
            $label . '.id'
        );
        if (isset($ids[$id])) {
            throw new Language_FTS_Playground_Morphology_Fixture_Exception($label . ': duplicate term_rule_behaviors id ' . $id);
        }
        $ids[$id] = true;

        $surface_pattern = language_fts_morphology_fixture_required_string($row, 'surface_pattern', $label);
        $pattern = '/' . $surface_pattern . '/u';
        if (@preg_match($pattern, '') === false) {
            throw new Language_FTS_Playground_Morphology_Fixture_Exception($label . ': surface_pattern regex must be valid after /.../u wrapping');
        }

        $alternate = $row['alternate'] ?? null;
        $alternate_pattern = '';
        $alternate_replacement = '';
        if ($alternate !== null) {
            if (!is_array($alternate)) {
                throw new Language_FTS_Playground_Morphology_Fixture_Exception($label . ': alternate must be null or an object');
            }
            $alternate_source_pattern = language_fts_morphology_fixture_required_string($alternate, 'pattern', $label . '.alternate');
            $alternate_pattern = '/' . $alternate_source_pattern . '/u';
            if (@preg_match($alternate_pattern, '') === false) {
                throw new Language_FTS_Playground_Morphology_Fixture_Exception($label . ': alternate.pattern regex must be valid after /.../u wrapping');
            }
            $alternate_replacement = language_fts_morphology_fixture_normalized_literal(
                language_fts_morphology_fixture_required_string($alternate, 'replacement', $label . '.alternate'),
                $label . '.alternate.replacement',
                $folds
            );
        }

        $strip_prefix = language_fts_morphology_fixture_normalized_literal((string) ($row['strip_prefix'] ?? ''), $label . '.strip_prefix', $folds);
        $strip_suffix = language_fts_morphology_fixture_normalized_literal((string) ($row['strip_suffix'] ?? ''), $label . '.strip_suffix', $folds);
        $append = language_fts_morphology_fixture_normalized_literal((string) ($row['append'] ?? ''), $label . '.append', $folds);
        if ($strip_prefix === '' && $strip_suffix === '' && $append === '') {
            throw new Language_FTS_Playground_Morphology_Fixture_Exception($label . ': rule must strip a prefix, strip a suffix, or append text');
        }

        $rules[] = [
            'id' => $id,
            'min_term_length' => language_fts_morphology_fixture_positive_int($row['min_term_length'] ?? null, $label . '.min_term_length'),
            'pattern' => $pattern,
            'strip_prefix' => $strip_prefix,
            'strip_suffix' => $strip_suffix,
            'append' => $append,
            'min_key_length' => language_fts_morphology_fixture_positive_int($row['min_key_length'] ?? null, $label . '.min_key_length'),
            'flags' => language_fts_morphology_fixture_flags($row['flags'] ?? [], $label . '.flags'),
            'alternate_pattern' => $alternate_pattern,
            'alternate_replacement' => $alternate_replacement,
            'provenance' => language_fts_morphology_fixture_tsv_field((string) ($row['provenance'] ?? $fixture_provenance), $label . '.provenance'),
        ];
    }

    $sorted_ids = array_keys($ids);
    sort($sorted_ids, SORT_STRING);
    if ($sorted_ids !== array_column($rules, 'id')) {
        throw new Language_FTS_Playground_Morphology_Fixture_Exception($path . ': term_rule_behaviors must be listed in ascending rule id order because the repository sorts rules by id');
    }

    return $rules;
}

/**
 * @param mixed $value
 * @param array<string,string> $folds
 * @return string[]
 */
function language_fts_morphology_fixture_terms(mixed $value, string $kind, string $path, array $folds): array
{
    if (!is_array($value)) {
        throw new Language_FTS_Playground_Morphology_Fixture_Exception($path . ': ' . ($kind === 'stopword' ? 'stopword_excerpt' : 'protected_terms') . ' must be an array');
    }

    $terms = [];
    $seen = [];
    foreach ($value as $index => $entry) {
        $label = $path . ': ' . ($kind === 'stopword' ? 'stopword_excerpt' : 'protected_terms') . '[' . $index . ']';
        if (is_array($entry)) {
            $term = language_fts_morphology_fixture_required_string($entry, 'term', $label);
        } elseif (is_string($entry)) {
            $term = $entry;
        } else {
            throw new Language_FTS_Playground_Morphology_Fixture_Exception($label . ' must be a string or object with term');
        }

        $term = language_fts_morphology_fixture_normalized_token($term, $label . '.term', $folds, $kind . ' term');
        if (isset($seen[$term])) {
            throw new Language_FTS_Playground_Morphology_Fixture_Exception($label . ': duplicate ' . $kind . ' after normalization: ' . $term);
        }
        $seen[$term] = true;
        $terms[] = $term;
    }

    sort($terms, SORT_STRING);

    return $terms;
}

/**
 * @param mixed $value
 * @param array<string,string> $folds
 * @return array<int,array{observed:string,canonical:string,provenance:string}>
 */
function language_fts_morphology_fixture_lexemes(mixed $value, string $path, array $folds, string $fixture_provenance): array
{
    if (!is_array($value)) {
        throw new Language_FTS_Playground_Morphology_Fixture_Exception($path . ': lexemes must be an array');
    }

    $rows = [];
    $seen = [];
    foreach ($value as $index => $row) {
        if (!is_array($row)) {
            throw new Language_FTS_Playground_Morphology_Fixture_Exception($path . ': lexemes[' . $index . '] must be an object');
        }

        $label = $path . ': lexemes[' . $index . ']';
        $observed = language_fts_morphology_fixture_normalized_token(
            language_fts_morphology_fixture_required_string($row, 'observed', $label),
            $label . '.observed',
            $folds,
            'lexeme observed'
        );
        $canonical = language_fts_morphology_fixture_normalized_token(
            language_fts_morphology_fixture_required_string($row, 'canonical', $label),
            $label . '.canonical',
            $folds,
            'lexeme canonical'
        );
        $key = $observed . "\t" . $canonical;
        if (isset($seen[$key])) {
            throw new Language_FTS_Playground_Morphology_Fixture_Exception($label . ': duplicate lexeme observed/canonical row');
        }
        $seen[$key] = true;
        $rows[] = [
            'observed' => $observed,
            'canonical' => $canonical,
            'provenance' => language_fts_morphology_fixture_tsv_field((string) ($row['provenance'] ?? $fixture_provenance), $label . '.provenance'),
        ];
    }

    usort(
        $rows,
        static fn(array $a, array $b): int => strcmp($a['observed'], $b['observed'])
            ?: strcmp($a['canonical'], $b['canonical'])
            ?: strcmp($a['provenance'], $b['provenance'])
    );

    return $rows;
}

/**
 * @param mixed $value
 * @param array<string,string> $folds
 * @return array<int,array{source:string,target:string,direction:string,weight:string,provenance:string}>
 */
function language_fts_morphology_fixture_synonyms(mixed $value, string $path, array $folds, string $fixture_provenance): array
{
    if (!is_array($value)) {
        throw new Language_FTS_Playground_Morphology_Fixture_Exception($path . ': synonyms must be an array');
    }

    $rows = [];
    $seen = [];
    foreach ($value as $index => $row) {
        if (!is_array($row)) {
            throw new Language_FTS_Playground_Morphology_Fixture_Exception($path . ': synonyms[' . $index . '] must be an object');
        }

        $label = $path . ': synonyms[' . $index . ']';
        $source = language_fts_morphology_fixture_normalized_token(language_fts_morphology_fixture_required_string($row, 'source', $label), $label . '.source', $folds, 'synonym source');
        $target = language_fts_morphology_fixture_normalized_token(language_fts_morphology_fixture_required_string($row, 'target', $label), $label . '.target', $folds, 'synonym target');
        if ($source === $target) {
            throw new Language_FTS_Playground_Morphology_Fixture_Exception($label . ': synonym source and target must differ');
        }

        $direction = language_fts_morphology_fixture_required_string($row, 'direction', $label);
        if (!in_array($direction, ['query_to_index', 'bidirectional'], true)) {
            throw new Language_FTS_Playground_Morphology_Fixture_Exception($label . ': synonym direction must be query_to_index or bidirectional');
        }

        $weight = language_fts_morphology_fixture_weight((string) ($row['weight'] ?? ''), $label . '.weight');
        $key = $source . "\t" . $target;
        if (isset($seen[$key])) {
            throw new Language_FTS_Playground_Morphology_Fixture_Exception($label . ': duplicate synonym source/target row');
        }
        $seen[$key] = true;
        $rows[] = [
            'source' => $source,
            'target' => $target,
            'direction' => $direction,
            'weight' => $weight,
            'provenance' => language_fts_morphology_fixture_tsv_field((string) ($row['provenance'] ?? $fixture_provenance), $label . '.provenance'),
        ];
    }

    usort(
        $rows,
        static fn(array $a, array $b): int => strcmp($a['source'], $b['source'])
            ?: strcmp($a['target'], $b['target'])
            ?: strcmp($a['provenance'], $b['provenance'])
    );

    return $rows;
}

/**
 * @param mixed $value
 * @param array<int,array{id:string}>
 */
function language_fts_morphology_fixture_sample_pairs(mixed $value, string $path, array $term_rules): void
{
    if (!is_array($value)) {
        throw new Language_FTS_Playground_Morphology_Fixture_Exception($path . ': stemmer_sample_pairs must be an array');
    }

    $rule_ids = array_fill_keys(array_column($term_rules, 'id'), true);
    $ids = [];
    foreach ($value as $index => $row) {
        if (!is_array($row)) {
            throw new Language_FTS_Playground_Morphology_Fixture_Exception($path . ': stemmer_sample_pairs[' . $index . '] must be an object');
        }
        $label = $path . ': stemmer_sample_pairs[' . $index . ']';
        $id = language_fts_morphology_fixture_required_string($row, 'id', $label);
        if (isset($ids[$id])) {
            throw new Language_FTS_Playground_Morphology_Fixture_Exception($label . ': duplicate stemmer_sample_pairs id ' . $id);
        }
        $ids[$id] = true;

        language_fts_morphology_fixture_required_string($row, 'surface', $label);
        language_fts_morphology_fixture_required_string($row, 'reference_key', $label);
        $policy = language_fts_morphology_fixture_required_string($row, 'policy', $label);
        if (!in_array($policy, ['must_emit', 'must_not_emit', 'documented_deviation'], true)) {
            throw new Language_FTS_Playground_Morphology_Fixture_Exception($label . ': policy must be must_emit, must_not_emit, or documented_deviation');
        }
        if (isset($row['rule_id']) && (!is_string($row['rule_id']) || !isset($rule_ids[$row['rule_id']]))) {
            throw new Language_FTS_Playground_Morphology_Fixture_Exception($label . ': rule_id must reference a generated term rule');
        }
    }
}

/**
 * @param mixed $value
 * @param array<string,string> $folds
 */
function language_fts_morphology_fixture_analyzer_expectations(mixed $value, string $path, array $folds): void
{
    if (!is_array($value)) {
        throw new Language_FTS_Playground_Morphology_Fixture_Exception($path . ': analyzer_expectations must be an array');
    }

    $ids = [];
    foreach ($value as $index => $row) {
        if (!is_array($row)) {
            throw new Language_FTS_Playground_Morphology_Fixture_Exception($path . ': analyzer_expectations[' . $index . '] must be an object');
        }

        $label = $path . ': analyzer_expectations[' . $index . ']';
        $id = language_fts_morphology_fixture_required_string($row, 'id', $label);
        if (isset($ids[$id])) {
            throw new Language_FTS_Playground_Morphology_Fixture_Exception($label . ': duplicate analyzer_expectations id ' . $id);
        }
        $ids[$id] = true;
        language_fts_morphology_fixture_required_string($row, 'text', $label);

        $has_assertion = false;
        foreach (['keys_include', 'keys_exclude', 'keys_exact'] as $key) {
            if (!array_key_exists($key, $row)) {
                continue;
            }
            $has_assertion = true;
            foreach (language_fts_morphology_fixture_string_list($row[$key], $label . '.' . $key) as $term) {
                language_fts_morphology_fixture_normalized_token($term, $label . '.' . $key, $folds, 'analyzer expectation key');
            }
        }
        if (!$has_assertion) {
            throw new Language_FTS_Playground_Morphology_Fixture_Exception($label . ': analyzer expectation must declare keys_include, keys_exclude, or keys_exact');
        }
    }
}

/**
 * @param array{term_rules:array<int,array{id:string,min_term_length:int,pattern:string,strip_prefix:string,strip_suffix:string,append:string,min_key_length:int,flags:string[],alternate_pattern:string,alternate_replacement:string,provenance:string}>} $compiled
 */
function language_fts_morphology_fixture_render_term_rules(array $compiled): string
{
    $lines = ["# id\tmin_term_length\tpattern\tstrip_prefix\tstrip_suffix\tappend\tmin_key_length\tflags\talternate_pattern\talternate_replacement\tprovenance"];
    foreach ($compiled['term_rules'] as $rule) {
        $lines[] = implode(
            "\t",
            [
                $rule['id'],
                (string) $rule['min_term_length'],
                $rule['pattern'],
                $rule['strip_prefix'],
                $rule['strip_suffix'],
                $rule['append'],
                (string) $rule['min_key_length'],
                implode(',', $rule['flags']),
                $rule['alternate_pattern'],
                $rule['alternate_replacement'],
                $rule['provenance'],
            ]
        );
    }

    return implode("\n", $lines) . "\n";
}

/**
 * @param string[] $terms
 */
function language_fts_morphology_fixture_render_terms(string $label, string $provenance, array $terms): string
{
    $lines = [
        '# Generated ' . $label . ' from morphology fixture.',
        '# provenance: ' . $provenance,
    ];

    return implode("\n", array_merge($lines, $terms)) . "\n";
}

/**
 * @param array{lexemes:array<int,array{observed:string,canonical:string,provenance:string}>} $compiled
 */
function language_fts_morphology_fixture_render_lexemes(array $compiled): string
{
    $lines = ["# observed\tcanonical\tprovenance"];
    foreach ($compiled['lexemes'] as $row) {
        $lines[] = $row['observed'] . "\t" . $row['canonical'] . "\t" . $row['provenance'];
    }

    return implode("\n", $lines) . "\n";
}

/**
 * @param array{synonyms:array<int,array{source:string,target:string,direction:string,weight:string,provenance:string}>} $compiled
 */
function language_fts_morphology_fixture_render_synonyms(array $compiled): string
{
    $lines = ["# source\ttarget\tdirection\tweight\tprovenance"];
    foreach ($compiled['synonyms'] as $row) {
        $lines[] = $row['source'] . "\t" . $row['target'] . "\t" . $row['direction'] . "\t" . $row['weight'] . "\t" . $row['provenance'];
    }

    return implode("\n", $lines) . "\n";
}

/**
 * @param array{language:string,label:string,order:int,folds:array<string,string>} $compiled
 */
function language_fts_morphology_fixture_render_profile(array $compiled): string
{
    $profile = [
        'id' => $compiled['language'],
        'label' => $compiled['label'],
        'order' => $compiled['order'],
        'resources' => [
            'stopwords' => 'stopwords.txt',
            'lexemes' => 'lexemes.tsv',
            'synonyms' => 'synonyms.tsv',
            'term_rules' => 'term_rules.tsv',
            'protected_terms' => 'protected_terms.txt',
        ],
    ];

    if ($compiled['folds'] !== []) {
        $profile = [
            'id' => $compiled['language'],
            'label' => $compiled['label'],
            'order' => $compiled['order'],
            'normalization' => [
                'fold' => $compiled['folds'],
            ],
            'resources' => $profile['resources'],
        ];
    }

    return "<?php\ndeclare(strict_types=1);\n\nreturn " . var_export($profile, true) . ";\n";
}

/**
 * @param array{language:string,pack_version:string,pack_date:string,source:array{name:string,source_url:string,license_name:string,attribution:string,reference_kind:string},provenance:string} $compiled
 */
function language_fts_morphology_fixture_render_pack(array $compiled): string
{
    $metadata = [
        'language_id' => $compiled['language'],
        'pack_version' => $compiled['pack_version'],
        'pack_date' => $compiled['pack_date'],
        'source_name' => $compiled['source']['name'],
        'source_url' => $compiled['source']['source_url'],
        'license_name' => $compiled['source']['license_name'],
        'attribution_text' => $compiled['source']['attribution'] . ' Reference scope: ' . $compiled['source']['reference_kind'] . '.',
        'provenance' => $compiled['provenance'],
        'files' => [
            'profile.php',
            'stopwords.txt',
            'lexemes.tsv',
            'synonyms.tsv',
            'term_rules.tsv',
            'protected_terms.txt',
        ],
        'data_kind' => 'curated_seed',
    ];

    return "<?php\ndeclare(strict_types=1);\n\nreturn " . var_export($metadata, true) . ";\n";
}

function language_fts_morphology_fixture_write_file(string $path, string $contents): void
{
    if (file_put_contents($path, $contents) === false) {
        throw new Language_FTS_Playground_Morphology_Fixture_Exception('Could not write output file: ' . $path);
    }
}

/**
 * @param array<string,mixed> $object
 */
function language_fts_morphology_fixture_required_string(array $object, string $key, string $label): string
{
    $value = $object[$key] ?? null;
    if (!is_string($value) || trim($value) === '') {
        throw new Language_FTS_Playground_Morphology_Fixture_Exception($label . ': ' . $key . ' must be a non-empty string');
    }

    return trim($value);
}

/**
 * @param array<string,mixed> $object
 * @return array<string,mixed>
 */
function language_fts_morphology_fixture_required_object(array $object, string $key, string $label): array
{
    $value = $object[$key] ?? null;
    if (!is_array($value)) {
        throw new Language_FTS_Playground_Morphology_Fixture_Exception($label . ': ' . $key . ' must be an object');
    }

    return $value;
}

/**
 * @param array<string,mixed> $object
 * @return array<int,mixed>
 */
function language_fts_morphology_fixture_required_list(array $object, string $key, string $label): array
{
    $value = $object[$key] ?? null;
    if (!is_array($value) || !language_fts_morphology_fixture_is_list($value)) {
        throw new Language_FTS_Playground_Morphology_Fixture_Exception($label . ': ' . $key . ' must be an array');
    }

    return $value;
}

/**
 * @return string[]
 */
function language_fts_morphology_fixture_string_list(mixed $value, string $label): array
{
    if (!is_array($value) || !language_fts_morphology_fixture_is_list($value)) {
        throw new Language_FTS_Playground_Morphology_Fixture_Exception($label . ' must be a list of strings');
    }

    $strings = [];
    foreach ($value as $index => $item) {
        if (!is_string($item) || trim($item) === '') {
            throw new Language_FTS_Playground_Morphology_Fixture_Exception($label . '[' . $index . '] must be a non-empty string');
        }
        $strings[] = trim($item);
    }

    return $strings;
}

/**
 * @return array<string,string>
 */
function language_fts_morphology_fixture_optional_string_map(mixed $value, string $label): array
{
    if ($value === null || $value === []) {
        return [];
    }
    if (!is_array($value)) {
        throw new Language_FTS_Playground_Morphology_Fixture_Exception($label . ' must be an object mapping strings to strings');
    }

    $map = [];
    foreach ($value as $from => $to) {
        if (!is_string($from) || !is_string($to) || $from === '' || $to === '') {
            throw new Language_FTS_Playground_Morphology_Fixture_Exception($label . ' must map non-empty strings to non-empty strings');
        }
        $map[$from] = $to;
    }

    return $map;
}

function language_fts_morphology_fixture_language_id(string $value, string $label): string
{
    $value = strtolower(trim($value));
    if (preg_match('/^[a-z0-9-]+$/', $value) !== 1) {
        throw new Language_FTS_Playground_Morphology_Fixture_Exception($label . ' must contain only lowercase letters, numbers, and hyphens');
    }

    return $value;
}

function language_fts_morphology_fixture_tsv_field(string $value, string $label): string
{
    $value = trim($value);
    if ($value === '' || str_contains($value, "\t") || str_contains($value, "\n") || str_contains($value, "\r")) {
        throw new Language_FTS_Playground_Morphology_Fixture_Exception($label . ' must be a non-empty TSV-safe string');
    }

    return $value;
}

function language_fts_morphology_fixture_metadata_text(string $value, string $label): string
{
    $value = trim($value);
    if ($value === '') {
        throw new Language_FTS_Playground_Morphology_Fixture_Exception($label . ' must be non-empty');
    }

    return $value;
}

function language_fts_morphology_fixture_optional_string(mixed $value, string $default): string
{
    return is_string($value) && trim($value) !== '' ? trim($value) : $default;
}

function language_fts_morphology_fixture_positive_int(mixed $value, string $label): int
{
    if (!is_int($value) || $value < 1) {
        throw new Language_FTS_Playground_Morphology_Fixture_Exception($label . ' must be a positive integer');
    }

    return $value;
}

function language_fts_morphology_fixture_optional_positive_int(mixed $value, string $label): int
{
    if (is_int($value) && $value > 0) {
        return $value;
    }

    throw new Language_FTS_Playground_Morphology_Fixture_Exception($label . ' must be a positive integer');
}

/**
 * @return string[]
 */
function language_fts_morphology_fixture_flags(mixed $value, string $label): array
{
    if (!is_array($value) || !language_fts_morphology_fixture_is_list($value)) {
        throw new Language_FTS_Playground_Morphology_Fixture_Exception($label . ' must be a list of strings');
    }

    $allowed = array_fill_keys(
        ['trim_doubled_final_consonant', 'require_vowel', 'require_vowel_or_y', 'append_e_if_cvc', 'stop_after_match'],
        true
    );
    $flags = [];
    foreach ($value as $flag) {
        if (!is_string($flag) || !isset($allowed[$flag])) {
            throw new Language_FTS_Playground_Morphology_Fixture_Exception($label . ': unknown term rule flag');
        }
        if (isset($flags[$flag])) {
            throw new Language_FTS_Playground_Morphology_Fixture_Exception($label . ': duplicate term rule flag ' . $flag);
        }
        $flags[$flag] = true;
    }

    return array_keys($flags);
}

function language_fts_morphology_fixture_is_list(array $value): bool
{
    $expected = 0;
    foreach (array_keys($value) as $key) {
        if ($key !== $expected) {
            return false;
        }
        $expected++;
    }

    return true;
}

function language_fts_morphology_fixture_normalized_literal(string $value, string $label, array $folds): string
{
    $value = trim($value);
    if ($value === '') {
        return '';
    }

    return language_fts_morphology_fixture_normalized_token($value, $label, $folds, $label);
}

/**
 * @param array<string,string> $folds
 */
function language_fts_morphology_fixture_normalized_token(string $value, string $label, array $folds, string $kind): string
{
    $token = language_fts_morphology_fixture_resource_token($value, $label);
    if ($token !== language_fts_morphology_fixture_normalize_token($token, $folds)) {
        throw new Language_FTS_Playground_Morphology_Fixture_Exception($label . ': ' . $kind . ' must be normalized lowercase resource tokens');
    }

    return $token;
}

function language_fts_morphology_fixture_resource_token(string $value, string $label): string
{
    $token = trim($value);
    if ($token === '') {
        throw new Language_FTS_Playground_Morphology_Fixture_Exception($label . ' must be non-empty');
    }
    $has_whitespace = preg_match('/\s/u', $token);
    if ($has_whitespace === false || $has_whitespace === 1 || str_contains($token, '#')) {
        throw new Language_FTS_Playground_Morphology_Fixture_Exception($label . ' must not contain whitespace or #');
    }
    if (strlen($token) > 255) {
        throw new Language_FTS_Playground_Morphology_Fixture_Exception($label . ' must be 255 bytes or shorter');
    }

    return $token;
}

/**
 * @param array<string,string> $folds
 */
function language_fts_morphology_fixture_normalize_token(string $token, array $folds): string
{
    $lowercase = function_exists('mb_strtolower') ? mb_strtolower($token, 'UTF-8') : strtolower($token);

    return $folds === [] ? $lowercase : strtr($lowercase, $folds);
}

function language_fts_morphology_fixture_weight(string $value, string $label): string
{
    $value = trim($value);
    if (!is_numeric($value)) {
        throw new Language_FTS_Playground_Morphology_Fixture_Exception($label . ' must be numeric');
    }

    $weight = (float) $value;
    if ($weight <= 0.0 || $weight > 1.0) {
        throw new Language_FTS_Playground_Morphology_Fixture_Exception($label . ' must be greater than 0 and no more than 1');
    }

    return $value;
}

function language_fts_morphology_fixture_pack_date(string $value, string $path): string
{
    if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $matches) !== 1 || !checkdate((int) $matches[2], (int) $matches[3], (int) $matches[1])) {
        throw new Language_FTS_Playground_Morphology_Fixture_Exception($path . ': pack_date must be a valid YYYY-MM-DD date');
    }

    return $value;
}
