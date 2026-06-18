<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/tools/generate-large-search-corpus.php';

/**
 * @return array<string,mixed>
 */
function wp_fts_lscg_manifest(string $directory): array
{
    $json = file_get_contents($directory . '/manifest.json');
    if (!is_string($json)) {
        throw new WP_FTS_TestFailure("Could not read generated manifest from {$directory}.");
    }
    $manifest = json_decode($json, true);
    if (!is_array($manifest)) {
        throw new WP_FTS_TestFailure("Generated manifest is not valid JSON in {$directory}.");
    }

    return $manifest;
}

/**
 * @return array<string,mixed>
 */
function wp_fts_lscg_first_jsonl_record(string $path, bool $gzip): array
{
    $line = false;
    if ($gzip) {
        $handle = gzopen($path, 'rb');
        if (is_resource($handle)) {
            $line = gzgets($handle);
            gzclose($handle);
        }
    } else {
        $handle = fopen($path, 'rb');
        if (is_resource($handle)) {
            $line = fgets($handle);
            fclose($handle);
        }
    }
    if (!is_string($line)) {
        throw new WP_FTS_TestFailure("Could not read first generated JSONL record from {$path}.");
    }
    $record = json_decode($line, true);
    if (!is_array($record)) {
        throw new WP_FTS_TestFailure("First generated JSONL record is not valid JSON in {$path}.");
    }

    return $record;
}

/**
 * @return array<int,array<string,mixed>>
 */
function wp_fts_lscg_jsonl_records(string $path, bool $gzip): array
{
    $records = [];
    if ($gzip) {
        $handle = gzopen($path, 'rb');
        if (!is_resource($handle)) {
            throw new WP_FTS_TestFailure("Could not open generated JSONL shard from {$path}.");
        }
        while (!gzeof($handle)) {
            $line = gzgets($handle);
            if ($line === false) {
                break;
            }
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $record = json_decode($line, true);
            if (!is_array($record)) {
                gzclose($handle);
                throw new WP_FTS_TestFailure("Generated JSONL record is not valid JSON in {$path}.");
            }
            $records[] = $record;
        }
        gzclose($handle);

        return $records;
    }

    $handle = fopen($path, 'rb');
    if (!is_resource($handle)) {
        throw new WP_FTS_TestFailure("Could not open generated JSONL shard from {$path}.");
    }
    while (($line = fgets($handle)) !== false) {
        $line = trim($line);
        if ($line === '') {
            continue;
        }
        $record = json_decode($line, true);
        if (!is_array($record)) {
            fclose($handle);
            throw new WP_FTS_TestFailure("Generated JSONL record is not valid JSON in {$path}.");
        }
        $records[] = $record;
    }
    fclose($handle);

    return $records;
}

test_case('large search corpus generator parses CLI options', function (): void {
    $options = WP_FTS_LargeSearchCorpusGenerator::parse_cli_options([
        '--output=/tmp/wp-fts-corpus',
        '--seed=alpha',
        '--english-docs=7',
        '--per-language-docs=3',
        '--languages=en,pl,zh',
        '--smoke',
        '--compression=plain',
    ]);

    assert_same('/tmp/wp-fts-corpus', $options['output'], 'corpus parser keeps output path');
    assert_same('alpha', $options['seed'], 'corpus parser keeps seed');
    assert_same(7, $options['english_docs'], 'corpus parser reads english count');
    assert_same(3, $options['per_language_docs'], 'corpus parser reads per-language count');
    assert_same(['en', 'pl', 'zh'], $options['languages'], 'corpus parser canonicalizes language list');
    assert_same(true, $options['smoke'], 'corpus parser reads smoke flag');
    assert_same('plain', $options['compression'], 'corpus parser reads compression mode');

    $failed = false;
    try {
        WP_FTS_LargeSearchCorpusGenerator::parse_cli_options(['--english-docs=-1']);
    } catch (InvalidArgumentException) {
        $failed = true;
    }
    assert_true($failed, 'corpus parser rejects invalid document counts');
});

test_case('large search corpus generator derives default language scope from repo state', function (): void {
    $scope = WP_FTS_LargeSearchCorpusGenerator::derive_supported_language_scope(dirname(__DIR__, 2));
    assert_same(
        ['en', 'ar', 'bn', 'ca', 'de', 'es', 'fa', 'fr', 'hi', 'id', 'it', 'ja', 'ko', 'nl', 'pl', 'pt', 'ru', 'te', 'tr', 'uk', 'ur', 'zh'],
        $scope['languages'],
        'default corpus languages follow README, pipeline, stemmer, and pack manifests'
    );
    assert_contains('README baseline selectable/detectable routing set', implode(' | ', $scope['reasons']['zh']), 'Chinese reason comes from README routing support');
    assert_contains('WP_FTS_SnowballStemmer supported language', implode(' | ', $scope['reasons']['ca']), 'Catalan reason comes from Snowball support');
    assert_contains('committed analyzer pack manifest', implode(' | ', $scope['reasons']['pl']), 'Polish reason includes committed analyzer pack');
});

test_case('large search corpus generator writes deterministic plain shards', function (): void {
    $outA = temp_directory_path('large_corpus_a');
    $outB = temp_directory_path('large_corpus_b');
    try {
        $generator = new WP_FTS_LargeSearchCorpusGenerator(['gzip_available' => false, 'clock' => static fn(): float => 123.0]);
        $options = [
            'output' => $outA,
            'seed' => 'same-seed',
            'languages' => ['en', 'pl'],
            'english_docs' => 3,
            'per_language_docs' => 2,
            'compression' => 'plain',
        ];
        $manifestA = $generator->generate($options);
        $options['output'] = $outB;
        $manifestB = $generator->generate($options);

        assert_same($manifestA['shards'][0]['sha256'], $manifestB['shards'][0]['sha256'], 'English shard hash is deterministic');
        assert_same($manifestA['shards'][1]['sha256'], $manifestB['shards'][1]['sha256'], 'Polish shard hash is deterministic');
        assert_same(
            hash_file('sha256', $outA . '/search-corpus-en.jsonl'),
            hash_file('sha256', $outB . '/search-corpus-en.jsonl'),
            'plain JSONL file bytes are deterministic'
        );
    } finally {
        remove_directory_tree($outA);
        remove_directory_tree($outB);
    }
});

test_case('large search corpus generator manifest and smoke records have benchmark metadata', function (): void {
    $out = temp_directory_path('large_corpus_smoke');
    try {
        $manifest = (new WP_FTS_LargeSearchCorpusGenerator(['gzip_available' => false, 'clock' => static fn(): float => 123.0]))->generate([
            'output' => $out,
            'seed' => 'smoke-seed',
            'languages' => ['en', 'zh'],
            'smoke' => true,
            'compression' => 'plain',
        ]);

        assert_same(1, $manifest['schema_version'], 'manifest schema version is present');
        assert_same('plain', $manifest['output_format']['compression'], 'manifest records plain compression');
        assert_same(['en', 'zh'], $manifest['languages'], 'manifest records generated languages');
        assert_same(12, $manifest['shards'][0]['documents'], 'smoke mode uses small English count');
        assert_same(4, $manifest['shards'][1]['documents'], 'smoke mode uses small per-language count');
        assert_true(isset($manifest['shards'][0]['sha256']), 'manifest records shard sha256');
        assert_true(isset($manifest['language_scope']['reasons']['zh']), 'manifest records language scope reasons');

        $record = wp_fts_lscg_first_jsonl_record($out . '/search-corpus-en.jsonl', false);
        foreach (['id', 'language', 'title', 'content_html', 'plain_content', 'expected_visible_text', 'topic', 'size_class', 'approx_token_count'] as $key) {
            assert_true(array_key_exists($key, $record), "generated record includes {$key}");
        }
        assert_contains('<article lang="en">', $record['content_html'], 'content HTML carries document language');
        assert_contains('split<em>benchmark</em>', $record['content_html'], 'content HTML includes split-token inline markup case');
        assert_contains('<span lang="pl">', $record['content_html'], 'content HTML includes language-tagged span');
        assert_contains('splitbenchmark', $record['expected_visible_text'], 'visible text includes split token surface');

        $records = array_merge(
            wp_fts_lscg_jsonl_records($out . '/search-corpus-en.jsonl', false),
            wp_fts_lscg_jsonl_records($out . '/search-corpus-zh.jsonl', false)
        );
        $minTokens = min(array_map(static fn(array $generated): int => (int) $generated['approx_token_count'], $records));
        assert_true($minTokens >= 200, 'smoke corpus records keep approx_token_count at or above 200');
    } finally {
        remove_directory_tree($out);
    }
});

test_case('large search corpus generator document length distribution matches corpus brief', function (): void {
    $out = temp_directory_path('large_corpus_distribution');
    try {
        (new WP_FTS_LargeSearchCorpusGenerator(['gzip_available' => false, 'clock' => static fn(): float => 123.0]))->generate([
            'output' => $out,
            'seed' => 'distribution-seed',
            'languages' => ['en'],
            'english_docs' => 240,
            'per_language_docs' => 0,
            'compression' => 'plain',
        ]);

        $records = wp_fts_lscg_jsonl_records($out . '/search-corpus-en.jsonl', false);
        assert_same(240, count($records), 'distribution sample writes the requested full-style document count');

        $minTokens = PHP_INT_MAX;
        $maxTokens = 0;
        $buckets = [
            'floor_to_649' => 0,
            'around_750' => 0,
            'mid_long' => 0,
            'long_tail' => 0,
        ];
        $around750Total = 0;
        foreach ($records as $record) {
            $tokens = (int) $record['approx_token_count'];
            $minTokens = min($minTokens, $tokens);
            $maxTokens = max($maxTokens, $tokens);
            if ($tokens < 650) {
                $buckets['floor_to_649']++;
                continue;
            }
            if ($tokens <= 900) {
                $buckets['around_750']++;
                $around750Total += $tokens;
                continue;
            }
            if ($tokens < 3000) {
                $buckets['mid_long']++;
                continue;
            }
            $buckets['long_tail']++;
        }

        assert_true($minTokens >= 200, 'full-style distribution sample keeps approx_token_count at or above 200');
        assert_true(
            $buckets['around_750'] > max($buckets['floor_to_649'], $buckets['mid_long'], $buckets['long_tail']),
            'the roughly 750-token bucket is the modal generated length bucket'
        );
        assert_true($buckets['around_750'] > 0, 'distribution sample includes records around 750 tokens');
        $around750Average = $around750Total / max(1, $buckets['around_750']);
        assert_true($around750Average >= 725.0 && $around750Average <= 825.0, 'modal length bucket averages around 750 tokens');
        assert_true($maxTokens >= 5000, 'deterministic long-tail sample reaches at least 5000 tokens');
        assert_true($maxTokens > 5000, 'deterministic long-tail sample includes a record above 5000 tokens');
    } finally {
        remove_directory_tree($out);
    }
});

test_case('large search corpus generator falls back to plain JSONL without zlib', function (): void {
    $out = temp_directory_path('large_corpus_php_n');
    try {
        $result = test_run_subprocess([
            PHP_BINARY,
            '-n',
            dirname(__DIR__, 2) . '/tools/generate-large-search-corpus.php',
            '--output=' . $out,
            '--seed=no-zlib',
            '--languages=en',
            '--english-docs=1',
            '--per-language-docs=0',
            '--compression=auto',
        ], dirname(__DIR__, 2));

        assert_same(0, $result['exit'], 'CLI generator exits successfully under php -n');
        $manifest = wp_fts_lscg_manifest($out);
        assert_same('plain', $manifest['output_format']['compression'], 'auto compression falls back to plain without zlib');
        assert_true(is_file($out . '/search-corpus-en.jsonl'), 'plain fallback writes .jsonl shard');
        $record = wp_fts_lscg_first_jsonl_record($out . '/search-corpus-en.jsonl', false);
        assert_same('en', $record['language'], 'plain fallback shard contains JSONL records');
    } finally {
        remove_directory_tree($out);
    }
});

test_case('large search corpus generator writes gzip shards when zlib is available', function (): void {
    if (!WP_FTS_AnalyzerPackValidator::gzip_available() || !function_exists('gzwrite')) {
        assert_true(true, 'gzip corpus branch skipped because zlib is unavailable');
        return;
    }

    $out = temp_directory_path('large_corpus_gzip');
    try {
        $manifest = (new WP_FTS_LargeSearchCorpusGenerator(['clock' => static fn(): float => 123.0]))->generate([
            'output' => $out,
            'seed' => 'gzip-seed',
            'languages' => ['en'],
            'english_docs' => 1,
            'per_language_docs' => 0,
            'compression' => 'auto',
        ]);

        assert_same('gzip', $manifest['output_format']['compression'], 'auto compression uses gzip when available');
        assert_true(is_file($out . '/search-corpus-en.jsonl.gz'), 'gzip branch writes .jsonl.gz shard');
        $record = wp_fts_lscg_first_jsonl_record($out . '/search-corpus-en.jsonl.gz', true);
        assert_same('en', $record['language'], 'gzip shard contains JSONL records');
        assert_same(hash_file('sha256', $out . '/search-corpus-en.jsonl.gz'), $manifest['shards'][0]['sha256'], 'manifest sha matches gzip file bytes');
    } finally {
        remove_directory_tree($out);
    }
});
