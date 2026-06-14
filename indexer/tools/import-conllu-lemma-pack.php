<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/tools/import-lemma-tsv-pack.php';

/**
 * Converts source-approved CoNLL-U FORM/LEMMA rows into the normalized lemma TSV
 * importer contract, then delegates analyzer-pack generation to that importer.
 */
final class WP_FTS_ConlluLemmaPackImporter
{
    /**
     * @param array<string,mixed> $options
     * @return array<string,mixed>
     */
    public function import(array $options): array
    {
        $sourcePath = $this->required_source_path($options, 'source');
        $language = $this->required_language($options, 'language');
        $tmpDir = $this->prepare_temp_directory($options['tmp_dir'] ?? null);
        try {
            $normalizedTsv = $tmpDir . DIRECTORY_SEPARATOR . 'normalized-lemma.tsv';
            $stats = $this->write_normalized_tsv($sourcePath, $normalizedTsv, $language);
            if ((int) $stats['accepted_rows'] < 1) {
                throw new RuntimeException('CoNLL-U source did not yield any normalized runtime rows.');
            }

            $tsvOptions = $options;
            $tsvOptions['source'] = $normalizedTsv;
            $tsvOptions['language'] = $language;
            $summary = (new WP_FTS_LemmaTsvPackImporter())->import($tsvOptions);
            $summary['conllu'] = $stats;

            return $summary;
        } finally {
            $this->remove_tree($tmpDir);
        }
    }

    /**
     * @param string[] $argv
     * @return array<string,mixed>
     */
    public static function parse_cli_options(array $argv): array
    {
        return WP_FTS_LemmaTsvPackImporter::parse_cli_options($argv);
    }

    /**
     * @param array<string,mixed> $options
     */
    private function required_source_path(array $options, string $key): string
    {
        $path = $this->required_string($options, $key);
        if (!is_file($path) && !is_dir($path)) {
            throw new RuntimeException("Required path --{$key} does not exist: {$path}");
        }

        return $path;
    }

    /**
     * @param array<string,mixed> $options
     */
    private function required_string(array $options, string $key): string
    {
        if (!isset($options[$key]) || !is_scalar($options[$key]) || trim((string) $options[$key]) === '') {
            throw new RuntimeException("Missing required option --" . str_replace('_', '-', $key) . '.');
        }

        return (string) $options[$key];
    }

    /**
     * @param array<string,mixed> $options
     */
    private function required_language(array $options, string $key): string
    {
        $language = $this->required_string($options, $key);
        if (preg_match('/^[A-Za-z]{2,3}(?:[-_][A-Za-z0-9]{2,8}){0,3}$/', $language) !== 1) {
            throw new RuntimeException("Invalid language tag for --{$key}: {$language}");
        }

        return (new WP_FTS_Normalizer())->canonicalize_language($language);
    }

    /**
     * @return array<string,mixed>
     */
    private function write_normalized_tsv(string $sourcePath, string $tsvPath, string $language): array
    {
        $sources = $this->discover_source_files($sourcePath);
        $handle = fopen($tsvPath, 'wb');
        if (!is_resource($handle)) {
            throw new RuntimeException("Could not write normalized TSV: {$tsvPath}");
        }

        $normalizer = new WP_FTS_Normalizer();
        $stats = [
            'source_path' => $sourcePath,
            'source_files' => count($sources),
            'files' => array_map(fn(string $path): string => $this->source_label($path, $sourcePath), $sources),
            'source_lines' => 0,
            'blank_lines' => 0,
            'comment_lines' => 0,
            'multiword_token_rows' => 0,
            'empty_node_rows' => 0,
            'placeholder_rows' => 0,
            'invalid_runtime_token_rows' => 0,
            'accepted_rows' => 0,
        ];

        try {
            foreach ($sources as $file) {
                $this->append_source_file($file, $sourcePath, $language, $normalizer, $handle, $stats);
            }
        } finally {
            fclose($handle);
        }

        return $stats;
    }

    /**
     * @return string[]
     */
    private function discover_source_files(string $sourcePath): array
    {
        if (is_file($sourcePath)) {
            return [$sourcePath];
        }

        $files = [];
        try {
            $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($sourcePath, FilesystemIterator::SKIP_DOTS));
            foreach ($iterator as $file) {
                if (!$file->isFile()) {
                    continue;
                }
                $path = $file->getPathname();
                $lower = strtolower($path);
                if (str_ends_with($lower, '.conllu') || str_ends_with($lower, '.conllu.gz')) {
                    $files[] = $path;
                }
            }
        } catch (UnexpectedValueException $e) {
            throw new RuntimeException("Could not read CoNLL-U source directory: {$sourcePath}", 0, $e);
        }
        sort($files, SORT_STRING);
        if ($files === []) {
            throw new RuntimeException("Source directory did not contain any .conllu files: {$sourcePath}");
        }

        return $files;
    }

    /**
     * @param resource $tsvHandle
     * @param array<string,mixed> $stats
     */
    private function append_source_file(
        string $file,
        string $sourceRoot,
        string $language,
        WP_FTS_Normalizer $normalizer,
        $tsvHandle,
        array &$stats
    ): void {
        $label = $this->source_label($file, $sourceRoot);
        $reader = $this->open_source($file);
        $lineNumber = 0;
        try {
            while (($line = $this->read_source_line($reader)) !== false) {
                $lineNumber++;
                $stats['source_lines']++;
                $line = rtrim((string) $line, "\n");
                $line = rtrim($line, "\r");
                if ($lineNumber === 1) {
                    $line = preg_replace('/^\xEF\xBB\xBF/', '', $line) ?? $line;
                }
                if ($line === '') {
                    $stats['blank_lines']++;
                    continue;
                }
                if ($line[0] === '#') {
                    $stats['comment_lines']++;
                    continue;
                }
                if (preg_match('//u', $line) !== 1) {
                    throw new RuntimeException("CoNLL-U row {$label}:{$lineNumber} is not valid UTF-8.");
                }

                $columns = explode("\t", $line);
                $id = trim((string) ($columns[0] ?? ''));
                if (str_contains($id, '-')) {
                    $stats['multiword_token_rows']++;
                    continue;
                }
                if (str_contains($id, '.')) {
                    $stats['empty_node_rows']++;
                    continue;
                }
                if (count($columns) < 10) {
                    throw new RuntimeException("CoNLL-U row {$label}:{$lineNumber} has too few columns; expected 10 tab-separated columns, found " . count($columns) . '.');
                }

                $form = trim($columns[1]);
                $lemma = trim($columns[2]);
                if ($form === '' || $lemma === '' || $form === '_' || $lemma === '_') {
                    $stats['placeholder_rows']++;
                    continue;
                }

                $surface = $normalizer->normalize_token($form, $language);
                $normalizedLemma = $normalizer->normalize_token($lemma, $language);
                if (!$this->is_single_runtime_token($surface) || !$this->is_single_runtime_token($normalizedLemma)) {
                    $stats['invalid_runtime_token_rows']++;
                    continue;
                }

                $tag = trim($columns[3] ?? '');
                $tag = $tag === '_' ? '' : $this->clean_tsv_note($tag);
                $sourceNote = $this->clean_tsv_note($label . ':' . $lineNumber . '#' . $id);
                $row = $surface . "\t" . $normalizedLemma . "\t" . $tag . "\t" . $sourceNote . "\n";
                if (fwrite($tsvHandle, $row) === false) {
                    throw new RuntimeException("Could not append normalized CoNLL-U row for {$label}:{$lineNumber}.");
                }
                $stats['accepted_rows']++;
            }
        } finally {
            $this->close_source($reader);
        }
    }

    private function is_single_runtime_token(string $token): bool
    {
        if ($token === '' || strpbrk($token, " \t\r\n") !== false || str_contains($token, WP_FTS_TermNamespace::SEPARATOR)) {
            return false;
        }

        $unicodeMatch = @preg_match('/^[\p{L}\p{M}\p{N}_]+$/u', $token);
        if ($unicodeMatch === 1) {
            return true;
        }
        if ($unicodeMatch === 0) {
            return false;
        }

        return preg_match('/^[A-Za-z0-9_]+$/', $token) === 1;
    }

    private function source_label(string $file, string $sourceRoot): string
    {
        if (is_dir($sourceRoot)) {
            $root = rtrim($sourceRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
            if (str_starts_with($file, $root)) {
                return str_replace(DIRECTORY_SEPARATOR, '/', substr($file, strlen($root)));
            }
        }

        return basename($file);
    }

    private function clean_tsv_note(string $value): string
    {
        return str_replace(["\t", "\r", "\n"], ' ', $value);
    }

    /**
     * @return array{type:string,handle:resource}
     */
    private function open_source(string $sourcePath): array
    {
        if (str_ends_with(strtolower($sourcePath), '.gz')) {
            if (!function_exists('gzopen')) {
                throw new RuntimeException('Reading gzip CoNLL-U sources requires the PHP zlib extension; use extracted .conllu files under php -n.');
            }
            $handle = gzopen($sourcePath, 'rb');
            if (!is_resource($handle)) {
                throw new RuntimeException("Could not open gzip CoNLL-U source: {$sourcePath}");
            }

            return ['type' => 'gzip', 'handle' => $handle];
        }

        $handle = fopen($sourcePath, 'rb');
        if (!is_resource($handle)) {
            throw new RuntimeException("Could not open CoNLL-U source: {$sourcePath}");
        }

        return ['type' => 'plain', 'handle' => $handle];
    }

    /**
     * @param array{type:string,handle:resource} $reader
     */
    private function read_source_line(array $reader): string|false
    {
        if ($reader['type'] === 'gzip') {
            return gzgets($reader['handle']);
        }

        return fgets($reader['handle']);
    }

    /**
     * @param array{type:string,handle:resource} $reader
     */
    private function close_source(array $reader): void
    {
        if ($reader['type'] === 'gzip') {
            gzclose($reader['handle']);
            return;
        }

        fclose($reader['handle']);
    }

    private function prepare_temp_directory(mixed $requested): string
    {
        $parent = sys_get_temp_dir();
        if (is_scalar($requested) && trim((string) $requested) !== '') {
            $parent = (string) $requested;
        }

        if (is_file($parent)) {
            throw new RuntimeException("Temporary parent path is a file: {$parent}");
        }
        if (!is_dir($parent) && !mkdir($parent, 0777, true) && !is_dir($parent)) {
            throw new RuntimeException("Could not create temporary parent directory: {$parent}");
        }

        for ($attempt = 0; $attempt < 10; $attempt++) {
            $tmpDir = $parent . DIRECTORY_SEPARATOR . 'wp-fts-conllu-lemma-import-' . getmypid() . '-' . bin2hex(random_bytes(8));
            if (mkdir($tmpDir, 0700)) {
                return $tmpDir;
            }
            if (!file_exists($tmpDir)) {
                throw new RuntimeException("Could not create importer temporary directory: {$tmpDir}");
            }
        }

        throw new RuntimeException("Could not create a unique importer temporary directory under: {$parent}");
    }

    private function remove_tree(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $path) {
            if ($path->isDir()) {
                rmdir($path->getPathname());
            } else {
                unlink($path->getPathname());
            }
        }
        rmdir($directory);
    }
}

if (PHP_SAPI === 'cli' && isset($argv[0]) && realpath((string) $argv[0]) === __FILE__) {
    try {
        $options = WP_FTS_ConlluLemmaPackImporter::parse_cli_options(array_slice($argv, 1));
        $summary = (new WP_FTS_ConlluLemmaPackImporter())->import($options);
        echo json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), "\n";
    } catch (Throwable $e) {
        fwrite(STDERR, "CoNLL-U lemma import failed: {$e->getMessage()}\n");
        exit(1);
    }
}
