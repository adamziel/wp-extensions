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
        "  --data-kind=<kind>           curated_seed or imported_comprehensive. Default: imported_comprehensive\n" .
        "  --delimiter=<character>      For openthesaurus-text. Default: ;\n"
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

    $data_kind = trim((string) ($options['data_kind'] ?? 'imported_comprehensive'));
    if (!in_array($data_kind, ['curated_seed', 'imported_comprehensive'], true)) {
        throw new Language_FTS_Playground_Lexical_Import_Exception('Data kind must be curated_seed or imported_comprehensive.');
    }

    $delimiter = (string) ($options['delimiter'] ?? ';');
    if ($delimiter === '' || strlen($delimiter) > 8 || str_contains($delimiter, "\n") || str_contains($delimiter, "\r") || str_contains($delimiter, "\t")) {
        throw new Language_FTS_Playground_Lexical_Import_Exception('Delimiter must be a short non-empty string without tabs or newlines.');
    }

    return [
        'format' => $format,
        'input_path' => $input_path,
        'output_dir' => language_fts_import_prepare_output_dir($output_dir),
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

    $records = language_fts_import_wordnet_records($data, (string) $config['input_path']);
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

        $weight = isset($record['weight'])
            ? language_fts_import_validate_weight((string) $record['weight'], 'wordnet-json weight for ' . $concept_id)
            : (string) $config['weight'];
        $members = language_fts_import_wordnet_members($record, (string) $config['input_path'], $concept_id);
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
 * @return array{synset_count:int,lexeme_count:int,synsets_path:string,lexemes_path:string,pack_path:string}
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

    $files = ['synsets.tsv'];
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
        $files[] = 'lexemes.tsv';
    }

    $metadata = [
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
    $pack_path = language_fts_import_output_path((string) $config['output_dir'], 'pack.php');
    language_fts_import_write_file($pack_path, "<?php\ndeclare(strict_types=1);\n\nreturn " . var_export($metadata, true) . ";\n");

    return [
        'synset_count' => count($state['concepts']),
        'lexeme_count' => $lexeme_count,
        'synsets_path' => $synsets_path,
        'lexemes_path' => $lexemes_path,
        'pack_path' => $pack_path,
    ];
}

function language_fts_import_output_path(string $output_dir, string $file_name): string
{
    if ($file_name !== basename($file_name) || str_contains($file_name, '..')) {
        throw new Language_FTS_Playground_Lexical_Import_Exception('Unsafe output file name: ' . $file_name);
    }

    $path = $output_dir . DIRECTORY_SEPARATOR . $file_name;
    $parent = realpath(dirname($path));
    if ($parent === false || rtrim($parent, DIRECTORY_SEPARATOR) !== $output_dir) {
        throw new Language_FTS_Playground_Lexical_Import_Exception('Refusing to write outside the output directory: ' . $path);
    }

    return $path;
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
 * @return array<int,mixed>
 */
function language_fts_import_wordnet_records(mixed $data, string $path): array
{
    if (!is_array($data)) {
        throw new Language_FTS_Playground_Lexical_Import_Exception('wordnet-json root must be an object or array: ' . $path);
    }

    if (array_is_list($data)) {
        return $data;
    }

    if (isset($data['synsets'])) {
        if (!is_array($data['synsets'])) {
            throw new Language_FTS_Playground_Lexical_Import_Exception('wordnet-json synsets must be an array or object: ' . $path);
        }

        return language_fts_import_record_list_from_map($data['synsets']);
    }

    if (language_fts_import_wordnet_has_members($data)) {
        return [$data];
    }

    return language_fts_import_record_list_from_map($data);
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
 * @param array<mixed> $record
 */
function language_fts_import_wordnet_record_has_id(array $record): bool
{
    foreach (['id', 'synset_id', 'synsetId', 'ili', 'offset', '_source_id'] as $key) {
        if (isset($record[$key]) && is_scalar($record[$key]) && trim((string) $record[$key]) !== '') {
            return true;
        }
    }

    return false;
}

/**
 * @param array<mixed> $record
 */
function language_fts_import_wordnet_record_id(array $record, string $path, int $record_number): string
{
    foreach (['id', 'synset_id', 'synsetId', 'ili', 'offset', '_source_id'] as $key) {
        if (isset($record[$key]) && is_scalar($record[$key]) && trim((string) $record[$key]) !== '') {
            return language_fts_import_token((string) $record[$key], $path, $record_number, 'wordnet-json synset id');
        }
    }

    throw new Language_FTS_Playground_Lexical_Import_Exception('wordnet-json synset records must include a unique id.');
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
 * @return array<int,array{canonical:string,observed:?string}>
 */
function language_fts_import_wordnet_members(array $record, string $path, string $concept_id): array
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
            throw new Language_FTS_Playground_Lexical_Import_Exception($label . ' must include canonical, lemma, word, term, form, or writtenForm.');
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
