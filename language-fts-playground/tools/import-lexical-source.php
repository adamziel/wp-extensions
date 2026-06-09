#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Import small source-specific lexical fixtures into compact runtime packs.
 *
 * The importer is intentionally dependency-free: it uses only PHP core
 * functions, writes fixed file names inside the requested output directory,
 * and keeps line-oriented formats streaming so large pre-extracted membership
 * files do not have to be loaded into memory.
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "import-lexical-source.php must run from the command line.\n");
    exit(1);
}

final class Language_FTS_Playground_Lexical_Import_Exception extends RuntimeException
{
}

const LANGUAGE_FTS_IMPORT_METADATA_SCHEMA_V2 = 'language-fts-playground-pack-metadata-v2';
const LANGUAGE_FTS_IMPORT_INVENTORY_SCHEMA_V1 = 'language-fts-playground-pack-inventory-v1';
const LANGUAGE_FTS_IMPORTER_VERSION = 'language-fts-playground-lexical-importer-v2';

exit(language_fts_import_main($_SERVER['argv'] ?? []));

/**
 * @param string[] $argv
 */
function language_fts_import_main(array $argv): int
{
    try {
        if (isset($argv[1]) && in_array($argv[1], ['--help', '-h'], true)) {
            language_fts_import_usage(STDOUT);

            return 0;
        }

        if (count($argv) < 4) {
            language_fts_import_usage(STDERR);

            return 1;
        }

        $format = (string) $argv[1];
        $input_path = (string) $argv[2];
        $output_dir = (string) $argv[3];
        $options = language_fts_import_parse_options(array_slice($argv, 4));
        $config = language_fts_import_config($format, $input_path, $output_dir, $options);

        $state = [
            'concepts' => [],
            'lexemes' => [],
        ];

        switch ($config['format']) {
            case 'membership-tsv':
            case 'wordnet-membership-tsv':
                language_fts_import_membership_tsv($config, $state);
                break;

            case 'openthesaurus-text':
                language_fts_import_openthesaurus_text($config, $state);
                break;

            case 'wordnet-json':
                language_fts_import_wordnet_json($config, $state);
                break;

            default:
                throw new Language_FTS_Playground_Lexical_Import_Exception('Unsupported import format: ' . $config['format']);
        }

        $summary = language_fts_import_write_outputs($config, $state);

        echo 'Imported ' . $summary['synset_count'] . ' synset rows to ' . $summary['synsets_path'] . "\n";
        if ($summary['lexeme_count'] > 0) {
            echo 'Imported ' . $summary['lexeme_count'] . ' lexeme rows to ' . $summary['lexemes_path'] . "\n";
        }
        echo 'Wrote pack metadata to ' . $summary['pack_path'] . "\n";
        if ($summary['inventory_path'] !== null) {
            echo 'Wrote pack inventory lock to ' . $summary['inventory_path'] . "\n";
        }

        return 0;
    } catch (Language_FTS_Playground_Lexical_Import_Exception $exception) {
        fwrite(STDERR, $exception->getMessage() . "\n");

        return 1;
    }
}

/**
 * @param resource $stream
 */
function language_fts_import_usage($stream): void
{
    fwrite(
        $stream,
        "Usage: php import-lexical-source.php <format> <input> <output-dir> [options]\n" .
        "Formats: membership-tsv, wordnet-membership-tsv, openthesaurus-text, wordnet-json\n" .
        "Required options:\n" .
        "  --language=<id>\n" .
        "  --source-name=<name>\n" .
        "  --source-url=<url>\n" .
        "  --license-name=<name>\n" .
        "  --attribution=<text>\n" .
        "  --pack-version=<version>\n" .
        "  --pack-date=<YYYY-MM-DD>\n" .
        "  --provenance=<tsv-provenance>\n" .
        "Optional:\n" .
        "  --weight=<0..1>              Default: 0.62\n" .
        "  --data-kind=<kind>           curated_seed or imported_comprehensive. Default: curated_seed\n" .
        "  --delimiter=<character>      For openthesaurus-text. Default: ;\n" .
        "Comprehensive metadata options when --data-kind=imported_comprehensive:\n" .
        "  --source-version=<version>\n" .
        "  --source-retrieved-at=<YYYY-MM-DD>\n" .
        "  --source-artifact-name=<name>\n" .
        "  --source-artifact-url=<url>\n" .
        "  --source-artifact-sha256=<sha256>\n" .
        "  --source-artifact-bytes=<bytes>\n" .
        "  --license-id=<identifier>\n" .
        "  --license-url=<url>\n" .
        "  --license-text-url=<url>\n" .
        "  --license-text-file=<local-file>\n" .
        "  --normalization-profile-version=<version>\n" .
        "  --command-artifact-label=<label>  Optional deterministic command input label.\n"
    );
}

/**
 * @param string[] $args
 * @return array<string,string>
 */
function language_fts_import_parse_options(array $args): array
{
    $options = [];
    $allowed = [
        'language' => true,
        'source_name' => true,
        'source_url' => true,
        'license_name' => true,
        'attribution' => true,
        'attribution_text' => true,
        'pack_version' => true,
        'pack_date' => true,
        'provenance' => true,
        'weight' => true,
        'data_kind' => true,
        'delimiter' => true,
        'source_version' => true,
        'source_retrieved_at' => true,
        'source_artifact_name' => true,
        'source_artifact_url' => true,
        'source_artifact_sha256' => true,
        'source_artifact_bytes' => true,
        'license_id' => true,
        'license_url' => true,
        'license_text_url' => true,
        'license_text_file' => true,
        'normalization_profile_version' => true,
        'importer_version' => true,
        'command_artifact_label' => true,
    ];

    for ($i = 0, $count = count($args); $i < $count; $i++) {
        $arg = (string) $args[$i];
        if (!str_starts_with($arg, '--')) {
            throw new Language_FTS_Playground_Lexical_Import_Exception('Options must use --name=value syntax: ' . $arg);
        }

        $without_prefix = substr($arg, 2);
        $equals = strpos($without_prefix, '=');
        if ($equals === false) {
            $key = str_replace('-', '_', $without_prefix);
            if ($i + 1 >= $count || str_starts_with((string) $args[$i + 1], '--')) {
                throw new Language_FTS_Playground_Lexical_Import_Exception('Option requires a value: --' . str_replace('_', '-', $key));
            }
            $value = (string) $args[++$i];
        } else {
            $key = str_replace('-', '_', substr($without_prefix, 0, $equals));
            $value = substr($without_prefix, $equals + 1);
        }

        if (!isset($allowed[$key])) {
            throw new Language_FTS_Playground_Lexical_Import_Exception('Unknown option: --' . str_replace('_', '-', $key));
        }

        $options[$key] = $value;
    }

    if (isset($options['attribution_text']) && !isset($options['attribution'])) {
        $options['attribution'] = $options['attribution_text'];
    }

    return $options;
}

/**
 * @param array<string,string> $options
 * @return array<string,mixed>
 */
function language_fts_import_config(string $format, string $input_path, string $output_dir, array $options): array
{
    $supported_formats = [
        'membership-tsv' => true,
        'wordnet-membership-tsv' => true,
        'openthesaurus-text' => true,
        'wordnet-json' => true,
    ];
    if (!isset($supported_formats[$format])) {
        throw new Language_FTS_Playground_Lexical_Import_Exception('Unsupported import format: ' . $format);
    }

    if (!is_file($input_path)) {
        throw new Language_FTS_Playground_Lexical_Import_Exception('Input file does not exist: ' . $input_path);
    }

    $required = [
        'language',
        'source_name',
        'source_url',
        'license_name',
        'attribution',
        'pack_version',
        'pack_date',
        'provenance',
    ];
    foreach ($required as $key) {
        if (!isset($options[$key]) || trim($options[$key]) === '') {
            throw new Language_FTS_Playground_Lexical_Import_Exception('Missing required option: --' . str_replace('_', '-', $key));
        }
    }

    $language = strtolower(trim((string) $options['language']));
    if (preg_match('/^[a-z0-9-]+$/', $language) !== 1) {
        throw new Language_FTS_Playground_Lexical_Import_Exception('Language id must contain only lowercase letters, numbers, and hyphens.');
    }

    $pack_date = trim((string) $options['pack_date']);
    if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $pack_date, $matches) !== 1 || !checkdate((int) $matches[2], (int) $matches[3], (int) $matches[1])) {
        throw new Language_FTS_Playground_Lexical_Import_Exception('Pack date must be a valid YYYY-MM-DD date.');
    }

    $data_kind = trim((string) ($options['data_kind'] ?? 'curated_seed'));
    if (!in_array($data_kind, ['curated_seed', 'imported_comprehensive'], true)) {
        throw new Language_FTS_Playground_Lexical_Import_Exception('Data kind must be curated_seed or imported_comprehensive.');
    }

    $delimiter = (string) ($options['delimiter'] ?? ';');
    if ($delimiter === '' || strlen($delimiter) > 8 || str_contains($delimiter, "\n") || str_contains($delimiter, "\r") || str_contains($delimiter, "\t")) {
        throw new Language_FTS_Playground_Lexical_Import_Exception('Delimiter must be a short non-empty string without tabs or newlines.');
    }

    $output_dir = language_fts_import_prepare_output_dir($output_dir);
    $comprehensive = [];
    if ($data_kind === 'imported_comprehensive') {
        $comprehensive = language_fts_import_comprehensive_config($format, $input_path, $output_dir, $options);
    }

    return [
        'format' => $format,
        'input_path' => $input_path,
        'output_dir' => $output_dir,
        'language' => $language,
        'source_name' => language_fts_import_metadata_text((string) $options['source_name'], 'source name'),
        'source_url' => language_fts_import_metadata_text((string) $options['source_url'], 'source URL'),
        'license_name' => language_fts_import_metadata_text((string) $options['license_name'], 'license name'),
        'attribution_text' => language_fts_import_metadata_text((string) $options['attribution'], 'attribution'),
        'pack_version' => language_fts_import_metadata_text((string) $options['pack_version'], 'pack version'),
        'pack_date' => $pack_date,
        'data_kind' => $data_kind,
        'provenance' => language_fts_import_tsv_field((string) $options['provenance'], 'provenance'),
        'weight' => language_fts_import_validate_weight((string) ($options['weight'] ?? '0.62'), 'weight'),
        'delimiter' => $delimiter,
        'comprehensive' => $comprehensive,
    ];
}

/**
 * @param array<string,string> $options
 * @return array<string,mixed>
 */
function language_fts_import_comprehensive_config(string $format, string $input_path, string $output_dir, array $options): array
{
    $required = [
        'source_version',
        'source_retrieved_at',
        'source_artifact_name',
        'source_artifact_url',
        'source_artifact_sha256',
        'source_artifact_bytes',
        'license_id',
        'license_url',
        'license_text_url',
        'license_text_file',
        'normalization_profile_version',
    ];
    foreach ($required as $key) {
        if (!isset($options[$key]) || trim((string) $options[$key]) === '') {
            throw new Language_FTS_Playground_Lexical_Import_Exception('Missing required comprehensive metadata option: --' . str_replace('_', '-', $key));
        }
    }

    $source_retrieved_at = trim((string) $options['source_retrieved_at']);
    if (!language_fts_import_valid_date($source_retrieved_at)) {
        throw new Language_FTS_Playground_Lexical_Import_Exception('Source retrieved date must be a valid YYYY-MM-DD date.');
    }

    foreach (['source_artifact_url', 'license_url', 'license_text_url'] as $url_key) {
        $url = trim((string) $options[$url_key]);
        if (!language_fts_import_valid_url($url)) {
            throw new Language_FTS_Playground_Lexical_Import_Exception('--' . str_replace('_', '-', $url_key) . ' must be an HTTP(S) URL.');
        }
    }

    $source_artifact_sha256 = trim((string) $options['source_artifact_sha256']);
    if (!language_fts_import_valid_sha256($source_artifact_sha256)) {
        throw new Language_FTS_Playground_Lexical_Import_Exception('--source-artifact-sha256 must be 64 lowercase hex characters.');
    }

    $source_artifact_bytes = language_fts_import_positive_int((string) $options['source_artifact_bytes'], '--source-artifact-bytes');
    $actual_digest = hash_file('sha256', $input_path);
    if (!is_string($actual_digest)) {
        throw new Language_FTS_Playground_Lexical_Import_Exception('Could not compute source artifact digest: ' . $input_path);
    }
    if (strtolower($actual_digest) !== $source_artifact_sha256) {
        throw new Language_FTS_Playground_Lexical_Import_Exception('Source artifact sha256 mismatch for ' . $input_path . '.');
    }

    $actual_bytes = filesize($input_path);
    if (!is_int($actual_bytes) || $actual_bytes !== $source_artifact_bytes) {
        throw new Language_FTS_Playground_Lexical_Import_Exception('Source artifact byte count mismatch for ' . $input_path . '.');
    }

    $license_text_file = language_fts_import_local_file_name((string) $options['license_text_file'], 'license text file');
    $license_text_path = $output_dir . DIRECTORY_SEPARATOR . $license_text_file;
    if (!is_file($license_text_path)) {
        throw new Language_FTS_Playground_Lexical_Import_Exception('License text file does not exist in output directory: ' . $license_text_path);
    }

    $profile_file = $output_dir . DIRECTORY_SEPARATOR . 'profile.php';
    if (!is_file($profile_file)) {
        throw new Language_FTS_Playground_Lexical_Import_Exception('Comprehensive imports require an existing profile.php in the output directory.');
    }

    $importer_version = trim((string) ($options['importer_version'] ?? LANGUAGE_FTS_IMPORTER_VERSION));
    if ($importer_version === '') {
        throw new Language_FTS_Playground_Lexical_Import_Exception('Importer version must be non-empty.');
    }

    $command_artifact_label = trim((string) ($options['command_artifact_label'] ?? '<source-artifact>'));
    if ($command_artifact_label === '' || str_contains($command_artifact_label, "\n") || str_contains($command_artifact_label, "\r")) {
        throw new Language_FTS_Playground_Lexical_Import_Exception('Command artifact label must be non-empty and single-line.');
    }
    if (str_starts_with($command_artifact_label, '/') || str_starts_with($command_artifact_label, '\\') || preg_match('/^[A-Za-z]:[\/\\\\]/', $command_artifact_label) === 1) {
        throw new Language_FTS_Playground_Lexical_Import_Exception('Command artifact label must not be a machine-local absolute path.');
    }

    return [
        'source_version' => language_fts_import_metadata_text((string) $options['source_version'], 'source version'),
        'source_retrieved_at' => $source_retrieved_at,
        'source_artifact_name' => language_fts_import_local_artifact_name((string) $options['source_artifact_name'], 'source artifact name'),
        'source_artifact_url' => trim((string) $options['source_artifact_url']),
        'source_artifact_sha256' => $source_artifact_sha256,
        'source_artifact_bytes' => $source_artifact_bytes,
        'license_id' => language_fts_import_license_identifier((string) $options['license_id']),
        'license_url' => trim((string) $options['license_url']),
        'license_text_url' => trim((string) $options['license_text_url']),
        'license_text_file' => $license_text_file,
        'normalization_profile_version' => language_fts_import_metadata_text((string) $options['normalization_profile_version'], 'normalization profile version'),
        'importer_version' => $importer_version,
        'command_artifact_label' => $command_artifact_label,
        'format' => $format,
    ];
}

function language_fts_import_prepare_output_dir(string $output_dir): string
{
    $output_dir = trim($output_dir);
    if ($output_dir === '') {
        throw new Language_FTS_Playground_Lexical_Import_Exception('Output directory must be non-empty.');
    }

    if (is_file($output_dir)) {
        throw new Language_FTS_Playground_Lexical_Import_Exception('Output path is a file, not a directory: ' . $output_dir);
    }

    if (!is_dir($output_dir) && !mkdir($output_dir, 0777, true)) {
        throw new Language_FTS_Playground_Lexical_Import_Exception('Could not create output directory: ' . $output_dir);
    }

    $real = realpath($output_dir);
    if ($real === false || !is_dir($real)) {
        throw new Language_FTS_Playground_Lexical_Import_Exception('Could not resolve output directory: ' . $output_dir);
    }

    return rtrim($real, DIRECTORY_SEPARATOR);
}

/**
 * @param array<string,mixed> $config
 * @param array{concepts:array<string,array{weight:string,terms:array<string,bool>}>,lexemes:array<string,array<string,bool>>} $state
 */
function language_fts_import_membership_tsv(array $config, array &$state): void
{
    foreach (language_fts_import_source_lines((string) $config['input_path']) as $line_number => $line) {
        $trimmed = trim($line);
        if ($trimmed === '' || str_starts_with($trimmed, '#')) {
            continue;
        }

        $columns = explode("\t", $line);
        if (count($columns) < 2 || count($columns) > 4) {
            throw new Language_FTS_Playground_Lexical_Import_Exception(language_fts_import_source_error((string) $config['input_path'], $line_number, 'membership rows must have 2 to 4 tab-separated columns'));
        }

        $concept_id = language_fts_import_token($columns[0], (string) $config['input_path'], $line_number, 'concept id');
        $term = language_fts_import_term($columns[1], (string) $config['input_path'], $line_number, 'canonical term');
        $weight = count($columns) === 4 && trim($columns[3]) !== ''
            ? language_fts_import_validate_weight($columns[3], language_fts_import_source_error((string) $config['input_path'], $line_number, 'weight'))
            : (string) $config['weight'];

        language_fts_import_add_concept_term($state, $concept_id, $term, $weight, (string) $config['input_path'], $line_number);

        if (count($columns) >= 3 && trim($columns[2]) !== '') {
            $observed = language_fts_import_term($columns[2], (string) $config['input_path'], $line_number, 'observed term');
            language_fts_import_add_lexeme($state, $observed, $term);
        }
    }
}

/**
 * @param array<string,mixed> $config
 * @param array{concepts:array<string,array{weight:string,terms:array<string,bool>}>,lexemes:array<string,array<string,bool>>} $state
 */
function language_fts_import_openthesaurus_text(array $config, array &$state): void
{
    foreach (language_fts_import_source_lines((string) $config['input_path']) as $line_number => $line) {
        $trimmed = trim($line);
        if ($trimmed === '' || str_starts_with($trimmed, '#')) {
            continue;
        }

        $parts = explode((string) $config['delimiter'], $line);
        if (count($parts) < 2) {
            throw new Language_FTS_Playground_Lexical_Import_Exception(language_fts_import_source_error((string) $config['input_path'], $line_number, 'OpenThesaurus rows must contain at least 2 delimiter-separated terms'));
        }

        $concept_id = 'openthesaurus.line-' . str_pad((string) $line_number, 6, '0', STR_PAD_LEFT);
        foreach ($parts as $index => $part) {
            $term = language_fts_import_term($part, (string) $config['input_path'], $line_number, 'OpenThesaurus term ' . ($index + 1));
            language_fts_import_add_concept_term($state, $concept_id, $term, (string) $config['weight'], (string) $config['input_path'], $line_number);
        }
    }
}

/**
 * @param array<string,mixed> $config
 * @param array{concepts:array<string,array{weight:string,terms:array<string,bool>}>,lexemes:array<string,array<string,bool>>} $state
 */
function language_fts_import_wordnet_json(array $config, array &$state): void
{
    if (!function_exists('json_decode')) {
        throw new Language_FTS_Playground_Lexical_Import_Exception('The PHP JSON functions are required for wordnet-json imports.');
    }

    $content = file_get_contents((string) $config['input_path']);
    if (!is_string($content)) {
        throw new Language_FTS_Playground_Lexical_Import_Exception('Could not read input file: ' . $config['input_path']);
    }

    $data = json_decode($content, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new Language_FTS_Playground_Lexical_Import_Exception('JSON decode failed for ' . $config['input_path'] . ': ' . json_last_error_msg());
    }

    $document = language_fts_import_wordnet_document($data, (string) $config['input_path']);
    $records = $document['synsets'];
    if ($records === []) {
        throw new Language_FTS_Playground_Lexical_Import_Exception('wordnet-json input did not contain any synset records.');
    }

    $seen_ids = [];
    foreach ($records as $record_number => $record) {
        if (!is_array($record)) {
            throw new Language_FTS_Playground_Lexical_Import_Exception('wordnet-json synset records must be objects.');
        }

        $concept_id = language_fts_import_wordnet_record_id($record, (string) $config['input_path'], $record_number + 1);
        if (isset($seen_ids[$concept_id])) {
            throw new Language_FTS_Playground_Lexical_Import_Exception('Duplicate wordnet-json synset id: ' . $concept_id);
        }
        $seen_ids[$concept_id] = true;

        $weight = language_fts_import_wordnet_record_weight($record, (string) $config['weight'], $concept_id);
        $members = language_fts_import_wordnet_members(
            $record,
            (string) $config['input_path'],
            $concept_id,
            $document['sense_terms'],
            $document['member_ids_require_resolution']
        );
        foreach ($members as $member) {
            language_fts_import_add_concept_term($state, $concept_id, $member['canonical'], $weight, (string) $config['input_path'], $record_number + 1);
            if ($member['observed'] !== null) {
                language_fts_import_add_lexeme($state, $member['observed'], $member['canonical']);
            }
        }
    }
}

/**
 * @return Generator<int,string>
 */
function language_fts_import_source_lines(string $path): Generator
{
    $handle = fopen($path, 'rb');
    if ($handle === false) {
        throw new Language_FTS_Playground_Lexical_Import_Exception('Could not read input file: ' . $path);
    }

    try {
        $line_number = 0;
        while (($line = fgets($handle)) !== false) {
            $line_number++;
            yield $line_number => rtrim($line, "\r\n");
        }
    } finally {
        fclose($handle);
    }
}

/**
 * @param array{concepts:array<string,array{weight:string,terms:array<string,bool>}>,lexemes:array<string,array<string,bool>>} $state
 */
function language_fts_import_add_concept_term(array &$state, string $concept_id, string $term, string $weight, string $path, int $line_number): void
{
    if (!isset($state['concepts'][$concept_id])) {
        $state['concepts'][$concept_id] = [
            'weight' => $weight,
            'terms' => [],
        ];
    } elseif ($state['concepts'][$concept_id]['weight'] !== $weight) {
        throw new Language_FTS_Playground_Lexical_Import_Exception(language_fts_import_source_error($path, $line_number, 'conflicting weights for concept ' . $concept_id));
    }

    $state['concepts'][$concept_id]['terms'][$term] = true;
}

/**
 * @param array{concepts:array<string,array{weight:string,terms:array<string,bool>}>,lexemes:array<string,array<string,bool>>} $state
 */
function language_fts_import_add_lexeme(array &$state, string $observed, string $canonical): void
{
    $state['lexemes'][$observed][$canonical] = true;
}

/**
 * @param array<string,mixed> $config
 * @param array{concepts:array<string,array{weight:string,terms:array<string,bool>}>,lexemes:array<string,array<string,bool>>} $state
 * @return array{synset_count:int,lexeme_count:int,synsets_path:string,lexemes_path:string,pack_path:string,inventory_path:string|null}
 */
function language_fts_import_write_outputs(array $config, array $state): array
{
    if ($state['concepts'] === []) {
        throw new Language_FTS_Playground_Lexical_Import_Exception('No concepts were imported.');
    }

    ksort($state['concepts'], SORT_STRING);
    $synset_rows = ["# concept_id\tweight\tprovenance\tterms"];
    foreach ($state['concepts'] as $concept_id => $concept) {
        $terms = array_keys($concept['terms']);
        sort($terms, SORT_STRING);
        if ($terms === []) {
            throw new Language_FTS_Playground_Lexical_Import_Exception('Concept ' . $concept_id . ' has no usable terms.');
        }

        if (count($terms) < 2) {
            throw new Language_FTS_Playground_Lexical_Import_Exception('Concept ' . $concept_id . ' must contain at least 2 usable terms.');
        }

        $synset_rows[] = $concept_id . "\t" . $concept['weight'] . "\t" . $config['provenance'] . "\t" . implode(' ', $terms);
    }

    $synsets_path = language_fts_import_output_path((string) $config['output_dir'], 'synsets.tsv');
    language_fts_import_write_file($synsets_path, implode("\n", $synset_rows) . "\n");

    $generated_files = ['synsets.tsv'];
    $generated_resource_files = ['synsets' => 'synsets.tsv'];
    $lexeme_count = 0;
    $lexemes_path = language_fts_import_output_path((string) $config['output_dir'], 'lexemes.tsv');
    if ($state['lexemes'] !== []) {
        ksort($state['lexemes'], SORT_STRING);
        $lexeme_rows = ["# observed\tcanonical\tprovenance"];
        foreach ($state['lexemes'] as $observed => $canonical_lookup) {
            $canonical_terms = array_keys($canonical_lookup);
            sort($canonical_terms, SORT_STRING);
            foreach ($canonical_terms as $canonical) {
                $lexeme_rows[] = $observed . "\t" . $canonical . "\t" . $config['provenance'];
                $lexeme_count++;
            }
        }
        language_fts_import_write_file($lexemes_path, implode("\n", $lexeme_rows) . "\n");
        $generated_files[] = 'lexemes.tsv';
        $generated_resource_files['lexemes'] = 'lexemes.tsv';
    }

    $metadata = ((string) $config['data_kind'] === 'imported_comprehensive')
        ? language_fts_import_comprehensive_metadata($config, $generated_resource_files)
        : language_fts_import_basic_metadata($config, language_fts_import_pack_file_list((string) $config['output_dir'], $generated_files));
    $pack_path = language_fts_import_output_path((string) $config['output_dir'], 'pack.php');
    language_fts_import_write_file($pack_path, "<?php\ndeclare(strict_types=1);\n\nreturn " . var_export($metadata, true) . ";\n");

    $inventory_path = null;
    if ((string) $config['data_kind'] === 'imported_comprehensive') {
        $inventory_path = language_fts_import_output_path((string) $config['output_dir'], 'pack.lock.json');
        language_fts_import_write_file($inventory_path, language_fts_import_json(language_fts_import_pack_inventory($metadata)));
    }

    return [
        'synset_count' => count($state['concepts']),
        'lexeme_count' => $lexeme_count,
        'synsets_path' => $synsets_path,
        'lexemes_path' => $lexemes_path,
        'pack_path' => $pack_path,
        'inventory_path' => $inventory_path,
    ];
}

/**
 * @param array<string,mixed> $config
 * @param string[] $files
 * @return array<string,mixed>
 */
function language_fts_import_basic_metadata(array $config, array $files): array
{
    $files = array_values(array_unique($files));

    return [
        'language_id' => $config['language'],
        'pack_version' => $config['pack_version'],
        'pack_date' => $config['pack_date'],
        'source_name' => $config['source_name'],
        'source_url' => $config['source_url'],
        'license_name' => $config['license_name'],
        'attribution_text' => $config['attribution_text'],
        'provenance' => $config['provenance'],
        'files' => $files,
        'data_kind' => $config['data_kind'],
    ];
}

/**
 * @param array<string,mixed> $config
 * @param array<string,string> $generated_files
 * @return array<string,mixed>
 */
function language_fts_import_comprehensive_metadata(array $config, array $generated_files): array
{
    $output_dir = (string) $config['output_dir'];
    $comprehensive = (array) $config['comprehensive'];
    $runtime_resource_files = language_fts_import_profile_runtime_files($output_dir, (string) $config['language']);
    $runtime_resource_files['license'] = (string) $comprehensive['license_text_file'];

    $generated_lookup = array_fill_keys(array_values($generated_files), true);
    $files = array_values(array_unique(array_values($runtime_resource_files)));
    $files[] = 'pack.lock.json';
    $files = array_values(array_unique($files));
    sort($files, SORT_STRING);

    $runtime_files = [];
    foreach ($runtime_resource_files as $resource => $file) {
        $runtime_files[] = [
            'resource' => $resource,
            'file' => $file,
            'sha256' => language_fts_import_file_sha256($output_dir . DIRECTORY_SEPARATOR . $file),
            'bytes' => language_fts_import_file_bytes($output_dir . DIRECTORY_SEPARATOR . $file),
            'generated' => isset($generated_lookup[$file]),
        ];
    }
    usort(
        $runtime_files,
        static fn(array $a, array $b): int => strcmp((string) ($a['resource'] ?? ''), (string) ($b['resource'] ?? ''))
            ?: strcmp((string) ($a['file'] ?? ''), (string) ($b['file'] ?? ''))
    );

    $source_artifacts = [
        [
            'name' => $comprehensive['source_artifact_name'],
            'url' => $comprehensive['source_artifact_url'],
            'sha256' => $comprehensive['source_artifact_sha256'],
            'bytes' => $comprehensive['source_artifact_bytes'],
        ],
    ];

    $importer_options = [
        'attribution' => (string) $config['attribution_text'],
        'data_kind' => (string) $config['data_kind'],
        'language' => (string) $config['language'],
        'license_id' => (string) $comprehensive['license_id'],
        'license_name' => (string) $config['license_name'],
        'license_text_file' => (string) $comprehensive['license_text_file'],
        'license_text_url' => (string) $comprehensive['license_text_url'],
        'license_url' => (string) $comprehensive['license_url'],
        'normalization_profile_version' => (string) $comprehensive['normalization_profile_version'],
        'pack_date' => (string) $config['pack_date'],
        'pack_version' => (string) $config['pack_version'],
        'provenance' => (string) $config['provenance'],
        'source_artifact_bytes' => (string) $comprehensive['source_artifact_bytes'],
        'source_artifact_name' => (string) $comprehensive['source_artifact_name'],
        'source_artifact_sha256' => (string) $comprehensive['source_artifact_sha256'],
        'source_artifact_url' => (string) $comprehensive['source_artifact_url'],
        'source_name' => (string) $config['source_name'],
        'source_retrieved_at' => (string) $comprehensive['source_retrieved_at'],
        'source_url' => (string) $config['source_url'],
        'source_version' => (string) $comprehensive['source_version'],
        'weight' => (string) $config['weight'],
    ];
    if ((string) $config['format'] === 'openthesaurus-text') {
        $importer_options['delimiter'] = (string) $config['delimiter'];
    }
    ksort($importer_options, SORT_STRING);

    return [
        'metadata_schema' => LANGUAGE_FTS_IMPORT_METADATA_SCHEMA_V2,
        'language_id' => $config['language'],
        'pack_version' => $config['pack_version'],
        'pack_date' => $config['pack_date'],
        'data_kind' => $config['data_kind'],
        'source_name' => $config['source_name'],
        'source_url' => $config['source_url'],
        'license_name' => $config['license_name'],
        'attribution_text' => $config['attribution_text'],
        'provenance' => $config['provenance'],
        'files' => $files,
        'source' => [
            'name' => $config['source_name'],
            'version' => $comprehensive['source_version'],
            'retrieved_at' => $comprehensive['source_retrieved_at'],
            'artifacts' => $source_artifacts,
        ],
        'license' => [
            'identifier' => $comprehensive['license_id'],
            'name' => $config['license_name'],
            'url' => $comprehensive['license_url'],
            'text_url' => $comprehensive['license_text_url'],
            'text_file' => $comprehensive['license_text_file'],
            'attribution' => $config['attribution_text'],
        ],
        'provenance_ids' => [
            (string) $config['provenance'] => [
                'source' => $config['source_name'],
                'source_version' => $comprehensive['source_version'],
                'description' => 'Generated rows from the reviewed source artifact.',
            ],
        ],
        'normalization' => [
            'profile_id' => $config['language'],
            'profile_version' => $comprehensive['normalization_profile_version'],
            'profile_file' => 'profile.php',
            'profile_sha256' => language_fts_import_file_sha256($output_dir . DIRECTORY_SEPARATOR . 'profile.php'),
        ],
        'importer' => [
            'name' => 'language-fts-playground/tools/import-lexical-source.php',
            'version' => $comprehensive['importer_version'],
            'format' => $config['format'],
            'command' => language_fts_import_canonical_command($config, $comprehensive, $importer_options),
            'options' => $importer_options,
        ],
        'runtime_files' => $runtime_files,
    ];
}

/**
 * @param array<string,mixed> $metadata
 * @return array<string,mixed>
 */
function language_fts_import_pack_inventory(array $metadata): array
{
    $source = isset($metadata['source']) && is_array($metadata['source']) ? $metadata['source'] : [];
    $license = isset($metadata['license']) && is_array($metadata['license']) ? $metadata['license'] : [];
    $normalization = isset($metadata['normalization']) && is_array($metadata['normalization']) ? $metadata['normalization'] : [];
    $importer = isset($metadata['importer']) && is_array($metadata['importer']) ? $metadata['importer'] : [];
    $importer_options = isset($importer['options']) && is_array($importer['options']) ? $importer['options'] : [];
    ksort($importer_options, SORT_STRING);

    $provenance_ids = isset($metadata['provenance_ids']) && is_array($metadata['provenance_ids']) ? $metadata['provenance_ids'] : [];
    ksort($provenance_ids, SORT_STRING);

    $source_artifacts = [];
    foreach ((array) ($source['artifacts'] ?? []) as $artifact) {
        if (!is_array($artifact)) {
            continue;
        }
        $source_artifacts[] = [
            'name' => (string) ($artifact['name'] ?? ''),
            'url' => (string) ($artifact['url'] ?? ''),
            'sha256' => (string) ($artifact['sha256'] ?? ''),
            'bytes' => (int) ($artifact['bytes'] ?? 0),
        ];
    }
    usort(
        $source_artifacts,
        static fn(array $a, array $b): int => strcmp((string) ($a['name'] ?? ''), (string) ($b['name'] ?? ''))
            ?: strcmp((string) ($a['url'] ?? ''), (string) ($b['url'] ?? ''))
    );

    $runtime_resources = [];
    foreach ((array) ($metadata['runtime_files'] ?? []) as $runtime_file) {
        if (!is_array($runtime_file)) {
            continue;
        }
        $runtime_resources[] = [
            'resource' => (string) ($runtime_file['resource'] ?? ''),
            'file' => (string) ($runtime_file['file'] ?? ''),
            'sha256' => (string) ($runtime_file['sha256'] ?? ''),
            'bytes' => (int) ($runtime_file['bytes'] ?? 0),
            'generated' => (bool) ($runtime_file['generated'] ?? false),
        ];
    }
    usort(
        $runtime_resources,
        static fn(array $a, array $b): int => strcmp((string) ($a['resource'] ?? ''), (string) ($b['resource'] ?? ''))
            ?: strcmp((string) ($a['file'] ?? ''), (string) ($b['file'] ?? ''))
    );

    return [
        'schema' => LANGUAGE_FTS_IMPORT_INVENTORY_SCHEMA_V1,
        'language_id' => (string) ($metadata['language_id'] ?? ''),
        'data_kind' => (string) ($metadata['data_kind'] ?? ''),
        'pack' => [
            'version' => (string) ($metadata['pack_version'] ?? ''),
            'date' => (string) ($metadata['pack_date'] ?? ''),
        ],
        'source' => [
            'name' => (string) ($source['name'] ?? $metadata['source_name'] ?? ''),
            'url' => (string) ($metadata['source_url'] ?? ''),
            'version' => (string) ($source['version'] ?? $metadata['pack_version'] ?? ''),
            'date' => (string) ($source['retrieved_at'] ?? $metadata['pack_date'] ?? ''),
            'artifacts' => $source_artifacts,
        ],
        'license' => [
            'name' => (string) ($license['name'] ?? $metadata['license_name'] ?? ''),
            'identifier' => (string) ($license['identifier'] ?? ''),
            'url' => (string) ($license['url'] ?? ''),
            'text_url' => (string) ($license['text_url'] ?? ''),
            'text_file' => (string) ($license['text_file'] ?? ''),
            'attribution' => (string) ($license['attribution'] ?? $metadata['attribution_text'] ?? ''),
        ],
        'provenance' => [
            'default' => (string) ($metadata['provenance'] ?? ''),
            'ids' => $provenance_ids,
        ],
        'normalization' => [
            'profile_id' => (string) ($normalization['profile_id'] ?? $metadata['language_id'] ?? ''),
            'profile_version' => (string) ($normalization['profile_version'] ?? ''),
            'profile_file' => (string) ($normalization['profile_file'] ?? 'profile.php'),
            'profile_sha256' => (string) ($normalization['profile_sha256'] ?? ''),
        ],
        'importer' => [
            'name' => (string) ($importer['name'] ?? ''),
            'version' => (string) ($importer['version'] ?? ''),
            'format' => (string) ($importer['format'] ?? ''),
            'command' => (string) ($importer['command'] ?? ''),
            'options' => $importer_options,
        ],
        'runtime_resources' => $runtime_resources,
    ];
}

function language_fts_import_json(mixed $value): string
{
    $json = json_encode(language_fts_import_canonicalize_json_value($value), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if (!is_string($json)) {
        throw new Language_FTS_Playground_Lexical_Import_Exception('Could not encode pack inventory lock JSON.');
    }

    return $json . "\n";
}

function language_fts_import_canonicalize_json_value(mixed $value): mixed
{
    if (!is_array($value)) {
        return $value;
    }

    if (array_is_list($value)) {
        return array_map('language_fts_import_canonicalize_json_value', $value);
    }

    ksort($value, SORT_STRING);
    foreach ($value as $key => $item) {
        $value[$key] = language_fts_import_canonicalize_json_value($item);
    }

    return $value;
}

/**
 * @param string[] $generated_files
 * @return string[]
 */
function language_fts_import_pack_file_list(string $output_dir, array $generated_files): array
{
    $files = [];
    $seen = [];
    $add_file = static function (string $file) use (&$files, &$seen): void {
        $file = trim($file);
        if ($file === '' || isset($seen[$file])) {
            return;
        }

        $seen[$file] = true;
        $files[] = $file;
    };

    $profile_path = $output_dir . DIRECTORY_SEPARATOR . 'profile.php';
    if (is_file($profile_path)) {
        $add_file('profile.php');
        foreach (language_fts_import_profile_resource_files($profile_path) as $file) {
            $add_file($file);
        }
    }

    foreach ($generated_files as $file) {
        if (!language_fts_import_is_local_file_name($file)) {
            throw new Language_FTS_Playground_Lexical_Import_Exception('Generated output file name is not local: ' . $file);
        }
        $add_file($file);
    }

    return $files;
}

/**
 * @return string[]
 */
function language_fts_import_profile_resource_files(string $profile_path): array
{
    try {
        $profile = require $profile_path;
    } catch (Throwable $throwable) {
        throw new Language_FTS_Playground_Lexical_Import_Exception('Could not load output profile for pack file list: ' . $profile_path . ': ' . $throwable->getMessage());
    }

    if (!is_array($profile)) {
        throw new Language_FTS_Playground_Lexical_Import_Exception('Output profile must return an array for pack file list: ' . $profile_path);
    }

    $resources = $profile['resources'] ?? null;
    if (!is_array($resources)) {
        throw new Language_FTS_Playground_Lexical_Import_Exception('Output profile resources must be an array for pack file list: ' . $profile_path);
    }

    $files = [];
    foreach ($resources as $key => $file) {
        if (!is_string($key) || !is_string($file) || trim($file) === '') {
            throw new Language_FTS_Playground_Lexical_Import_Exception('Output profile resources must map names to non-empty file strings for pack file list: ' . $profile_path);
        }

        $file = trim($file);
        if (!language_fts_import_is_local_file_name($file)) {
            throw new Language_FTS_Playground_Lexical_Import_Exception('Output profile resource ' . $key . ' must be a local file name for pack file list: ' . $profile_path);
        }

        $files[] = $file;
    }

    return $files;
}

/**
 * @return array<string,string>
 */
function language_fts_import_profile_runtime_files(string $output_dir, string $language): array
{
    $profile_path = $output_dir . DIRECTORY_SEPARATOR . 'profile.php';
    try {
        $profile = require $profile_path;
    } catch (Throwable $throwable) {
        throw new Language_FTS_Playground_Lexical_Import_Exception('Could not load output profile for comprehensive imports: ' . $profile_path . ': ' . $throwable->getMessage());
    }
    if (!is_array($profile)) {
        throw new Language_FTS_Playground_Lexical_Import_Exception('Language profile must return an array: ' . $profile_path);
    }

    if ((string) ($profile['id'] ?? '') !== $language) {
        throw new Language_FTS_Playground_Lexical_Import_Exception('Language profile id must match --language for comprehensive imports.');
    }

    $resources = $profile['resources'] ?? null;
    if (!is_array($resources)) {
        throw new Language_FTS_Playground_Lexical_Import_Exception('Language profile resources must be an array for comprehensive imports.');
    }

    $runtime_files = ['profile' => 'profile.php'];
    foreach ($resources as $resource => $file) {
        if (!is_string($resource) || $resource === '') {
            throw new Language_FTS_Playground_Lexical_Import_Exception('Language profile resource keys must be non-empty strings for comprehensive imports.');
        }
        $file = language_fts_import_local_file_name((string) $file, 'profile resource ' . $resource);
        $path = $output_dir . DIRECTORY_SEPARATOR . $file;
        if (!is_file($path)) {
            throw new Language_FTS_Playground_Lexical_Import_Exception('Profile-declared runtime file does not exist after import: ' . $path);
        }
        $runtime_files[$resource] = $file;
    }

    ksort($runtime_files, SORT_STRING);

    return $runtime_files;
}

function language_fts_import_file_sha256(string $path): string
{
    $digest = is_file($path) ? hash_file('sha256', $path) : false;
    if (!is_string($digest)) {
        throw new Language_FTS_Playground_Lexical_Import_Exception('Could not compute file sha256: ' . $path);
    }

    return strtolower($digest);
}

function language_fts_import_file_bytes(string $path): int
{
    $bytes = is_file($path) ? filesize($path) : false;
    if (!is_int($bytes) || $bytes <= 0) {
        throw new Language_FTS_Playground_Lexical_Import_Exception('Could not compute positive file byte count: ' . $path);
    }

    return $bytes;
}

/**
 * @param array<string,mixed> $config
 * @param array<string,mixed> $comprehensive
 * @param array<string,string> $importer_options
 */
function language_fts_import_canonical_command(array $config, array $comprehensive, array $importer_options): string
{
    $parts = [
        'php',
        'language-fts-playground/tools/import-lexical-source.php',
        language_fts_import_command_value((string) $config['format']),
        language_fts_import_command_value((string) $comprehensive['command_artifact_label']),
        '<output-dir>',
    ];

    foreach ($importer_options as $key => $value) {
        $parts[] = '--' . str_replace('_', '-', $key) . '=' . language_fts_import_command_value((string) $value);
    }

    return implode(' ', $parts);
}

function language_fts_import_command_value(string $value): string
{
    if (preg_match('/^[A-Za-z0-9._:\/?#&=%+@,<>\-]+$/', $value) === 1) {
        return $value;
    }

    return "'" . str_replace("'", "'\\''", $value) . "'";
}

function language_fts_import_output_path(string $output_dir, string $file_name): string
{
    if (!language_fts_import_is_local_file_name($file_name)) {
        throw new Language_FTS_Playground_Lexical_Import_Exception('Unsafe output file name: ' . $file_name);
    }

    $path = $output_dir . DIRECTORY_SEPARATOR . $file_name;
    $parent = realpath(dirname($path));
    if ($parent === false || rtrim($parent, DIRECTORY_SEPARATOR) !== $output_dir) {
        throw new Language_FTS_Playground_Lexical_Import_Exception('Refusing to write outside the output directory: ' . $path);
    }

    return $path;
}

function language_fts_import_is_local_file_name(string $file_name): bool
{
    return $file_name !== '' && $file_name === basename($file_name) && !str_contains($file_name, '..');
}

function language_fts_import_write_file(string $path, string $contents): void
{
    $tmp_path = $path . '.tmp.' . getmypid();
    if (file_put_contents($tmp_path, $contents, LOCK_EX) === false) {
        throw new Language_FTS_Playground_Lexical_Import_Exception('Could not write temporary output file: ' . $tmp_path);
    }

    if (!rename($tmp_path, $path)) {
        if (is_file($tmp_path)) {
            unlink($tmp_path);
        }
        throw new Language_FTS_Playground_Lexical_Import_Exception('Could not move temporary output file into place: ' . $path);
    }
}

/**
 * @param mixed $data
 * @return array{synsets:array<int,mixed>,sense_terms:array<string,string>,member_ids_require_resolution:bool}
 */
function language_fts_import_wordnet_document(mixed $data, string $path): array
{
    if (!is_array($data)) {
        throw new Language_FTS_Playground_Lexical_Import_Exception('wordnet-json root must be an object or array: ' . $path);
    }

    $entry_terms = [];
    $sense_terms = [];
    $synsets = [];
    $member_ids_require_resolution = false;

    if (!array_is_list($data) && isset($data['@graph'])) {
        if (!is_array($data['@graph'])) {
            throw new Language_FTS_Playground_Lexical_Import_Exception('wordnet-json @graph must be an array or object: ' . $path);
        }

        foreach (language_fts_import_record_list_from_map($data['@graph']) as $graph_record) {
            if (!is_array($graph_record)) {
                throw new Language_FTS_Playground_Lexical_Import_Exception('wordnet-json @graph records must be objects.');
            }

            language_fts_import_wordnet_collect_lexical_mappings($graph_record, $path, $entry_terms, $sense_terms);
            array_push($synsets, ...language_fts_import_wordnet_synset_records_from_container($graph_record, $path));
        }

        return [
            'synsets' => $synsets,
            'sense_terms' => $sense_terms,
            'member_ids_require_resolution' => true,
        ];
    }

    if (array_is_list($data)) {
        foreach ($data as $record) {
            if (is_array($record) && language_fts_import_wordnet_has_structured_keys($record)) {
                language_fts_import_wordnet_collect_lexical_mappings($record, $path, $entry_terms, $sense_terms);
                $container_synsets = language_fts_import_wordnet_synset_records_from_container($record, $path);
                if ($container_synsets !== []) {
                    array_push($synsets, ...$container_synsets);
                    $member_ids_require_resolution = true;
                    continue;
                }
            }

            $synsets[] = $record;
        }

        return [
            'synsets' => $synsets,
            'sense_terms' => $sense_terms,
            'member_ids_require_resolution' => $member_ids_require_resolution || $sense_terms !== [],
        ];
    }

    language_fts_import_wordnet_collect_lexical_mappings($data, $path, $entry_terms, $sense_terms);

    $container_synsets = language_fts_import_wordnet_synset_records_from_container($data, $path);
    if ($container_synsets !== []) {
        array_push($synsets, ...$container_synsets);
        $member_ids_require_resolution = isset($data['synset']) || $sense_terms !== [];
    } elseif (language_fts_import_wordnet_has_members($data)) {
        $synsets[] = $data;
    } elseif (!language_fts_import_wordnet_has_structured_keys($data)) {
        $synsets = language_fts_import_record_list_from_map($data);
    }

    return [
        'synsets' => $synsets,
        'sense_terms' => $sense_terms,
        'member_ids_require_resolution' => $member_ids_require_resolution || $sense_terms !== [],
    ];
}

/**
 * @param array<mixed> $container
 * @return array<int,mixed>
 */
function language_fts_import_wordnet_synset_records_from_container(array $container, string $path): array
{
    $records = [];
    foreach (['synset', 'synsets'] as $key) {
        if (!isset($container[$key])) {
            continue;
        }

        if (!is_array($container[$key])) {
            throw new Language_FTS_Playground_Lexical_Import_Exception('wordnet-json ' . $key . ' must be an array or object: ' . $path);
        }

        array_push($records, ...language_fts_import_wordnet_record_list_from_value($container[$key], 'wordnet-json ' . $key));
    }

    return $records;
}

/**
 * @param array<mixed> $map
 * @return array<int,mixed>
 */
function language_fts_import_record_list_from_map(array $map): array
{
    if (array_is_list($map)) {
        return $map;
    }

    $records = [];
    foreach ($map as $id => $record) {
        if (!is_array($record)) {
            throw new Language_FTS_Playground_Lexical_Import_Exception('wordnet-json synset maps must contain object records.');
        }

        if (!language_fts_import_wordnet_record_has_id($record)) {
            $record['_source_id'] = (string) $id;
        }
        $records[] = $record;
    }

    return $records;
}

/**
 * @param array<mixed> $value
 * @return array<int,mixed>
 */
function language_fts_import_wordnet_record_list_from_value(array $value, string $label): array
{
    if (array_is_list($value)) {
        return $value;
    }

    if (language_fts_import_wordnet_looks_like_record($value)) {
        return [$value];
    }

    $records = language_fts_import_record_list_from_map($value);
    foreach ($records as $record) {
        if (!is_array($record)) {
            throw new Language_FTS_Playground_Lexical_Import_Exception($label . ' maps must contain object records.');
        }
    }

    return $records;
}

/**
 * @param array<mixed> $record
 */
function language_fts_import_wordnet_looks_like_record(array $record): bool
{
    foreach (['@id', 'id', 'synset_id', 'synsetId', '_source_id', 'members', 'words', 'lemmas', 'synonyms', 'lemma', 'writtenForm', 'sense', 'senses'] as $key) {
        if (isset($record[$key])) {
            return true;
        }
    }

    return false;
}

/**
 * @param array<mixed> $record
 */
function language_fts_import_wordnet_record_has_id(array $record): bool
{
    return language_fts_import_wordnet_optional_id($record) !== null;
}

/**
 * @param array<mixed> $record
 */
function language_fts_import_wordnet_record_id(array $record, string $path, int $record_number): string
{
    $id = language_fts_import_wordnet_optional_id($record);
    if ($id !== null) {
        return language_fts_import_token($id, $path, $record_number, 'wordnet-json synset id');
    }

    throw new Language_FTS_Playground_Lexical_Import_Exception('wordnet-json synset records must include a unique id.');
}

/**
 * @param array<mixed> $record
 */
function language_fts_import_wordnet_optional_id(array $record): ?string
{
    foreach (['@id', 'id', 'synset_id', 'synsetId', 'sense_id', 'senseId', 'ili', 'offset', '_source_id'] as $key) {
        if (isset($record[$key]) && is_scalar($record[$key]) && trim((string) $record[$key]) !== '') {
            return trim((string) $record[$key]);
        }
    }

    return null;
}

/**
 * @param array<mixed> $record
 */
function language_fts_import_wordnet_record_weight(array $record, string $default_weight, string $concept_id): string
{
    foreach (['weight', 'confidenceScore'] as $key) {
        if (isset($record[$key])) {
            return language_fts_import_validate_weight((string) $record[$key], 'wordnet-json ' . $key . ' for ' . $concept_id);
        }
    }

    return $default_weight;
}

/**
 * @param array<mixed> $record
 */
function language_fts_import_wordnet_has_structured_keys(array $record): bool
{
    foreach (['@graph', 'entry', 'entries', 'word', 'words', 'lexicalEntry', 'lexicalEntries', 'lexical_entries', 'sense', 'senses', 'synset', 'synsets'] as $key) {
        if (isset($record[$key])) {
            return true;
        }
    }

    return false;
}

/**
 * @param array<mixed> $container
 * @param array<string,string> $entry_terms
 * @param array<string,string> $sense_terms
 */
function language_fts_import_wordnet_collect_lexical_mappings(array $container, string $path, array &$entry_terms, array &$sense_terms): void
{
    $entry_records = language_fts_import_wordnet_records_for_keys(
        $container,
        ['entry', 'entries', 'word', 'words', 'lexicalEntry', 'lexicalEntries', 'lexical_entries'],
        $path,
        'wordnet-json lexical entries'
    );

    foreach ($entry_records as $entry) {
        if (!is_array($entry)) {
            throw new Language_FTS_Playground_Lexical_Import_Exception('wordnet-json lexical entry records must be objects.');
        }

        $entry_id = language_fts_import_wordnet_optional_id($entry);
        $written_form = language_fts_import_wordnet_written_form($entry);
        if ($entry_id !== null && $written_form !== null) {
            language_fts_import_wordnet_add_id_term($entry_terms, $entry_id, $written_form, 'wordnet-json lexical entry id');
        }

        foreach (language_fts_import_wordnet_records_for_keys($entry, ['sense', 'senses'], $path, 'wordnet-json entry senses') as $sense) {
            if (!is_array($sense)) {
                throw new Language_FTS_Playground_Lexical_Import_Exception('wordnet-json sense records must be objects.');
            }

            $sense_id = language_fts_import_wordnet_optional_id($sense);
            if ($sense_id !== null && $written_form !== null) {
                language_fts_import_wordnet_add_id_term($sense_terms, $sense_id, $written_form, 'wordnet-json sense id');
            }
        }
    }

    foreach (language_fts_import_wordnet_records_for_keys($container, ['sense', 'senses'], $path, 'wordnet-json senses') as $sense) {
        if (!is_array($sense)) {
            throw new Language_FTS_Playground_Lexical_Import_Exception('wordnet-json sense records must be objects.');
        }

        $sense_id = language_fts_import_wordnet_optional_id($sense);
        if ($sense_id === null) {
            continue;
        }

        $written_form = language_fts_import_wordnet_written_form($sense);
        if ($written_form === null) {
            $entry_ref = language_fts_import_first_member_field(
                $sense,
                ['entry', 'entryRef', 'entry_ref', 'word', 'wordRef', 'word_ref', 'lexicalEntry', 'lexicalEntryRef', 'lexical_entry']
            );
            if ($entry_ref !== null && isset($entry_terms[$entry_ref])) {
                $written_form = $entry_terms[$entry_ref];
            }
        }

        if ($written_form !== null) {
            language_fts_import_wordnet_add_id_term($sense_terms, $sense_id, $written_form, 'wordnet-json sense id');
        }
    }
}

/**
 * @param array<mixed> $container
 * @param string[] $keys
 * @return array<int,mixed>
 */
function language_fts_import_wordnet_records_for_keys(array $container, array $keys, string $path, string $label): array
{
    $records = [];
    foreach ($keys as $key) {
        if (!isset($container[$key])) {
            continue;
        }

        if (!is_array($container[$key])) {
            throw new Language_FTS_Playground_Lexical_Import_Exception($label . ' must be an array or object: ' . $path);
        }

        array_push($records, ...language_fts_import_wordnet_record_list_from_value($container[$key], $label));
    }

    return $records;
}

/**
 * @param array<string,string> $map
 */
function language_fts_import_wordnet_add_id_term(array &$map, string $id, string $term, string $label): void
{
    if (isset($map[$id]) && $map[$id] !== $term) {
        throw new Language_FTS_Playground_Lexical_Import_Exception($label . ' maps to conflicting lexical forms: ' . $id);
    }

    $map[$id] = $term;
}

/**
 * @param array<mixed> $record
 */
function language_fts_import_wordnet_written_form(array $record): ?string
{
    foreach (['writtenForm', 'written_form', 'canonical', 'term', 'word'] as $key) {
        if (isset($record[$key]) && is_scalar($record[$key]) && trim((string) $record[$key]) !== '') {
            return (string) $record[$key];
        }
    }

    if (isset($record['lemma'])) {
        return language_fts_import_wordnet_written_form_value($record['lemma']);
    }

    return null;
}

function language_fts_import_wordnet_written_form_value(mixed $value): ?string
{
    if (is_scalar($value) && trim((string) $value) !== '') {
        return (string) $value;
    }

    if (!is_array($value)) {
        return null;
    }

    if (isset($value['writtenForm']) && is_scalar($value['writtenForm']) && trim((string) $value['writtenForm']) !== '') {
        return (string) $value['writtenForm'];
    }

    if (isset($value['written_form']) && is_scalar($value['written_form']) && trim((string) $value['written_form']) !== '') {
        return (string) $value['written_form'];
    }

    if (array_is_list($value)) {
        foreach ($value as $item) {
            $written_form = language_fts_import_wordnet_written_form_value($item);
            if ($written_form !== null) {
                return $written_form;
            }
        }
    }

    return null;
}

/**
 * @param array<mixed> $record
 */
function language_fts_import_wordnet_has_members(array $record): bool
{
    foreach (['members', 'words', 'lemmas', 'synonyms'] as $key) {
        if (isset($record[$key])) {
            return true;
        }
    }

    return false;
}

/**
 * @param array<mixed> $record
 * @param array<string,string> $sense_terms
 * @return array<int,array{canonical:string,observed:?string}>
 */
function language_fts_import_wordnet_members(array $record, string $path, string $concept_id, array $sense_terms = [], bool $member_ids_require_resolution = false): array
{
    $members = null;
    foreach (['members', 'words', 'lemmas', 'synonyms'] as $key) {
        if (isset($record[$key])) {
            $members = $record[$key];
            break;
        }
    }

    if (!is_array($members)) {
        throw new Language_FTS_Playground_Lexical_Import_Exception('wordnet-json synset ' . $concept_id . ' must contain a member array.');
    }

    if ($members === []) {
        throw new Language_FTS_Playground_Lexical_Import_Exception('wordnet-json synset ' . $concept_id . ' has no members.');
    }

    $terms = [];
    foreach ($members as $index => $member) {
        $label = 'wordnet-json member ' . ((int) $index + 1) . ' for ' . $concept_id;
        if (is_string($member) || is_int($member) || is_float($member)) {
            $member_id = trim((string) $member);
            if (isset($sense_terms[$member_id])) {
                $terms[] = [
                    'canonical' => language_fts_import_term($sense_terms[$member_id], $path, (int) $index + 1, $label . ' lexical form'),
                    'observed' => null,
                ];
                continue;
            }

            if ($member_ids_require_resolution || language_fts_import_wordnet_member_looks_like_reference($member_id)) {
                throw language_fts_import_wordnet_unresolved_member_exception($concept_id, $member_id);
            }

            $terms[] = [
                'canonical' => language_fts_import_term((string) $member, $path, (int) $index + 1, $label),
                'observed' => null,
            ];
            continue;
        }

        if (!is_array($member)) {
            throw new Language_FTS_Playground_Lexical_Import_Exception($label . ' must be a string or object.');
        }

        $canonical_raw = language_fts_import_first_member_field($member, ['canonical', 'lemma', 'word', 'term', 'form', 'writtenForm']);
        if ($canonical_raw === null) {
            $member_id = language_fts_import_wordnet_member_reference($member);
            if ($member_id !== null && isset($sense_terms[$member_id])) {
                $terms[] = [
                    'canonical' => language_fts_import_term($sense_terms[$member_id], $path, (int) $index + 1, $label . ' lexical form'),
                    'observed' => null,
                ];
                continue;
            }

            if ($member_id !== null && ($member_ids_require_resolution || language_fts_import_wordnet_member_looks_like_reference($member_id))) {
                throw language_fts_import_wordnet_unresolved_member_exception($concept_id, $member_id);
            }

            throw new Language_FTS_Playground_Lexical_Import_Exception($label . ' must include canonical, lemma, word, term, form, writtenForm, or a resolvable sense id.');
        }

        $observed_raw = language_fts_import_first_member_field($member, ['observed', 'surface', 'source_form', 'sourceForm']);
        $canonical = language_fts_import_term($canonical_raw, $path, (int) $index + 1, $label);
        $observed = $observed_raw === null
            ? null
            : language_fts_import_term($observed_raw, $path, (int) $index + 1, $label . ' observed form');

        $terms[] = [
            'canonical' => $canonical,
            'observed' => $observed,
        ];
    }

    return $terms;
}

/**
 * @param array<mixed> $member
 */
function language_fts_import_wordnet_member_reference(array $member): ?string
{
    foreach (['@id', 'id', 'sense', 'senseRef', 'sense_ref', 'member', 'memberRef', 'member_ref', 'target'] as $key) {
        if (isset($member[$key]) && is_scalar($member[$key]) && trim((string) $member[$key]) !== '') {
            return trim((string) $member[$key]);
        }
    }

    return null;
}

function language_fts_import_wordnet_member_looks_like_reference(string $value): bool
{
    return preg_match('/[-_:][anvrs][-_:]/i', $value) === 1 && preg_match('/[-_:]\d+$/', $value) === 1;
}

function language_fts_import_wordnet_unresolved_member_exception(string $concept_id, string $member_id): Language_FTS_Playground_Lexical_Import_Exception
{
    return new Language_FTS_Playground_Lexical_Import_Exception('wordnet-json synset ' . $concept_id . ' member ' . $member_id . ' could not be resolved to a lexical written form.');
}

/**
 * @param array<mixed> $member
 * @param string[] $keys
 */
function language_fts_import_first_member_field(array $member, array $keys): ?string
{
    foreach ($keys as $key) {
        if (isset($member[$key]) && is_scalar($member[$key]) && trim((string) $member[$key]) !== '') {
            return (string) $member[$key];
        }
    }

    return null;
}

function language_fts_import_metadata_text(string $value, string $label): string
{
    $value = trim($value);
    if ($value === '') {
        throw new Language_FTS_Playground_Lexical_Import_Exception(ucfirst($label) . ' must be non-empty.');
    }

    if (str_contains($value, "\n") || str_contains($value, "\r")) {
        throw new Language_FTS_Playground_Lexical_Import_Exception(ucfirst($label) . ' must not contain newlines.');
    }

    return $value;
}

function language_fts_import_valid_date(string $date): bool
{
    return preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $matches) === 1
        && checkdate((int) $matches[2], (int) $matches[3], (int) $matches[1]);
}

function language_fts_import_valid_url(string $url): bool
{
    if ($url !== trim($url) || preg_match('/\s/u', $url) === 1) {
        return false;
    }

    $parts = parse_url($url);

    return is_array($parts)
        && isset($parts['scheme'], $parts['host'])
        && in_array(strtolower((string) $parts['scheme']), ['http', 'https'], true)
        && trim((string) $parts['host']) !== '';
}

function language_fts_import_valid_sha256(string $digest): bool
{
    return preg_match('/^[a-f0-9]{64}$/', $digest) === 1;
}

function language_fts_import_positive_int(string $value, string $label): int
{
    $value = trim($value);
    if (preg_match('/^[1-9][0-9]*$/', $value) !== 1) {
        throw new Language_FTS_Playground_Lexical_Import_Exception($label . ' must be a positive integer.');
    }

    return (int) $value;
}

function language_fts_import_local_file_name(string $value, string $label): string
{
    $value = trim($value);
    if ($value === '' || $value !== basename($value) || str_contains($value, '..')) {
        throw new Language_FTS_Playground_Lexical_Import_Exception(ucfirst($label) . ' must be a local file name.');
    }

    return $value;
}

function language_fts_import_local_artifact_name(string $value, string $label): string
{
    return language_fts_import_local_file_name($value, $label);
}

function language_fts_import_license_identifier(string $value): string
{
    $value = trim($value);
    if (preg_match('/^[A-Za-z0-9][A-Za-z0-9.+_-]*(?:-[A-Za-z0-9.+_-]+)*$/', $value) !== 1) {
        throw new Language_FTS_Playground_Lexical_Import_Exception('License id must be SPDX-like text without whitespace.');
    }

    return $value;
}

function language_fts_import_tsv_field(string $value, string $label): string
{
    $value = trim($value);
    if ($value === '') {
        throw new Language_FTS_Playground_Lexical_Import_Exception(ucfirst($label) . ' must be non-empty.');
    }

    if (str_contains($value, "\t") || str_contains($value, "\n") || str_contains($value, "\r")) {
        throw new Language_FTS_Playground_Lexical_Import_Exception(ucfirst($label) . ' must not contain tabs or newlines.');
    }

    return $value;
}

function language_fts_import_token(string $value, string $path, int $line_number, string $label): string
{
    $token = trim($value);
    if ($token === '') {
        throw new Language_FTS_Playground_Lexical_Import_Exception(language_fts_import_source_error($path, $line_number, $label . ' must be non-empty'));
    }

    $has_whitespace = preg_match('/\s/u', $token);
    if ($has_whitespace === false || $has_whitespace === 1 || str_contains($token, '#')) {
        throw new Language_FTS_Playground_Lexical_Import_Exception(language_fts_import_source_error($path, $line_number, $label . ' must not contain whitespace or #'));
    }

    if (strlen($token) > 255) {
        throw new Language_FTS_Playground_Lexical_Import_Exception(language_fts_import_source_error($path, $line_number, $label . ' must be 255 bytes or shorter'));
    }

    return $token;
}

function language_fts_import_term(string $value, string $path, int $line_number, string $label): string
{
    $term = language_fts_import_token($value, $path, $line_number, $label);

    return function_exists('mb_strtolower') ? mb_strtolower($term, 'UTF-8') : strtolower($term);
}

function language_fts_import_validate_weight(string $weight_raw, string $context): string
{
    $weight_raw = trim($weight_raw);
    if ($weight_raw === '' || !is_numeric($weight_raw)) {
        throw new Language_FTS_Playground_Lexical_Import_Exception($context . ' must be numeric.');
    }

    $weight = (float) $weight_raw;
    if ($weight <= 0.0 || $weight > 1.0) {
        throw new Language_FTS_Playground_Lexical_Import_Exception($context . ' must be greater than 0 and no more than 1.');
    }

    $formatted = rtrim(rtrim(sprintf('%.8F', $weight), '0'), '.');

    return $formatted === '' ? '0' : $formatted;
}

function language_fts_import_source_error(string $path, int $line_number, string $message): string
{
    return "{$path}:{$line_number}: {$message}";
}
