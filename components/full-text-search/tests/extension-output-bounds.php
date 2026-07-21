<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';

$wp_fts_extension_output_checks = 0;

/** Records one assertion and throws when the extension-output invariant fails. */
function wp_fts_extension_output_check(bool $condition, string $message): void
{
    global $wp_fts_extension_output_checks;
    $wp_fts_extension_output_checks++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

/** Runs a boundary probe and returns the exception it produced, if any. */
function wp_fts_extension_output_caught(callable $callback): ?Throwable
{
    try {
        $callback();
    } catch (Throwable $error) {
        return $error;
    }

    return null;
}

final class WP_FTS_Extension_Input_Bounds_Probe
{
    public int $calls = 0;
    public int $receivedBytes = 0;

    /** @return string[] */
    public function __invoke(string $run, string $language): array
    {
        $this->calls++;
        $this->receivedBytes = strlen($run);

        return ['中文'];
    }
}

$lexicalLimit = WP_FTS_Analysis_Limits::MAX_LEXICAL_RUN_BYTES;

$largestValidCjkRun = str_repeat('中', intdiv($lexicalLimit, strlen('中')));
wp_fts_extension_output_check(
    strlen($largestValidCjkRun) === 4095,
    'the largest repeated valid UTF-8 CJK run below the 4-KiB byte limit should occupy 4,095 bytes'
);
$validInputProbe = new WP_FTS_Extension_Input_Bounds_Probe();
$validInputPipeline = new WP_FTS_LanguagePipeline([
    'enable_stemming' => false,
    'cjk_tokenizer' => $validInputProbe,
]);
$validInputTerms = $validInputPipeline->analyze_detailed($largestValidCjkRun, 'zh');
wp_fts_extension_output_check($validInputTerms !== [], 'the largest valid CJK run should remain analyzable');
wp_fts_extension_output_check($validInputProbe->calls === 1, 'the largest valid CJK run should reach its configured tokenizer exactly once');
wp_fts_extension_output_check($validInputProbe->receivedBytes === 4095, 'the tokenizer should receive the complete exact-boundary UTF-8 run');

$firstOversizedCjkRun = $largestValidCjkRun . '中';
wp_fts_extension_output_check(
    strlen($firstOversizedCjkRun) === 4098,
    'the first complete repeated CJK code point above the 4-KiB limit should occupy 4,098 bytes'
);
$oversizedInputProbe = new WP_FTS_Extension_Input_Bounds_Probe();
$oversizedInputPipeline = new WP_FTS_LanguagePipeline([
    'enable_stemming' => false,
    'cjk_tokenizer' => $oversizedInputProbe,
]);
$oversizedInputError = wp_fts_extension_output_caught(
    static fn(): array => $oversizedInputPipeline->analyze_detailed($firstOversizedCjkRun, 'zh')
);
wp_fts_extension_output_check(
    $oversizedInputError instanceof WP_FTS_Analysis_Limit_Exceeded
        && $oversizedInputError->reason_code === 'lexical_run_bytes',
    'the first complete CJK code point above 4 KiB should raise the shared lexical-run limit'
);
wp_fts_extension_output_check(
    $oversizedInputProbe->calls === 0,
    'an oversized CJK run must fail before invoking an extension tokenizer'
);

$tokenizerAttempt = static function (int $outputBytes): array {
    $pipeline = new WP_FTS_LanguagePipeline([
        'enable_stemming' => false,
        'cjk_tokenizer' => static fn(): array => [str_repeat(' ', $outputBytes)],
    ]);

    return $pipeline->analyze_detailed('中文', 'zh');
};
wp_fts_extension_output_check(
    count($tokenizerAttempt($lexicalLimit)) === 3,
    'a tokenizer output at the 4-KiB boundary should remain valid and fall back when empty after trim'
);
$tokenizerError = wp_fts_extension_output_caught(static fn(): array => $tokenizerAttempt($lexicalLimit + 1));
wp_fts_extension_output_check(
    $tokenizerError instanceof WP_FTS_Analysis_Limit_Exceeded && $tokenizerError->reason_code === 'lexical_run_bytes',
    'a tokenizer output one byte above 4 KiB should reject before trim'
);

$normalizerAttempt = static function (int $outputBytes): array {
    $pipeline = new WP_FTS_LanguagePipeline([
        'enable_stemming' => false,
        'token_normalizer' => static fn(): string => str_repeat(' ', $outputBytes),
    ]);

    return $pipeline->analyze_detailed('needle', 'en');
};
wp_fts_extension_output_check(
    $normalizerAttempt($lexicalLimit) === [],
    'a token normalizer output at the 4-KiB boundary should remain valid'
);
$normalizerError = wp_fts_extension_output_caught(static fn(): array => $normalizerAttempt($lexicalLimit + 1));
wp_fts_extension_output_check(
    $normalizerError instanceof WP_FTS_Analysis_Limit_Exceeded && $normalizerError->reason_code === 'lexical_run_bytes',
    'a token normalizer output one byte above 4 KiB should reject before Unicode normalization'
);

$stemmerAttempt = static function (int $outputBytes): array {
    $pipeline = new WP_FTS_LanguagePipeline([
        'enable_stemming' => true,
        'stemmer' => static fn(): string => str_repeat(' ', $outputBytes),
    ]);

    return $pipeline->analyze_detailed('needle', 'en');
};
wp_fts_extension_output_check(
    $stemmerAttempt($lexicalLimit) === [],
    'a stemmer output at the 4-KiB boundary should remain valid'
);
$stemmerError = wp_fts_extension_output_caught(static fn(): array => $stemmerAttempt($lexicalLimit + 1));
wp_fts_extension_output_check(
    $stemmerError instanceof WP_FTS_Analysis_Limit_Exceeded && $stemmerError->reason_code === 'lexical_run_bytes',
    'a stemmer output one byte above 4 KiB should reject before trim or character-length work'
);

$searcher = new WP_FTS_Searcher(
    new WP_FTS_Storage_InMemory(),
    new WP_FTS_Analyzer(['auto_detect_language' => false, 'enable_stemming' => false])
);
$normalizeQueryAnalysis = new ReflectionMethod($searcher, 'normalize_query_analysis');
$normalizeQueryAnalysis->setAccessible(true);
$occurrenceLimit = WP_FTS_Analysis_Limits::MAX_DOCUMENT_OCCURRENCES;
$exactAnalysis = array_fill(0, $occurrenceLimit, 'term');
$normalizedAnalysis = $normalizeQueryAnalysis->invoke($searcher, $exactAnalysis);
wp_fts_extension_output_check(
    is_array($normalizedAnalysis) && count($normalizedAnalysis) === $occurrenceLimit,
    'a legacy analyzer array at the 20,000-occurrence boundary should remain valid'
);
unset($normalizedAnalysis, $exactAnalysis);

$overAnalysis = array_fill(0, $occurrenceLimit + 1, 'term');
$analysisError = wp_fts_extension_output_caught(
    static fn(): array => $normalizeQueryAnalysis->invoke($searcher, $overAnalysis)
);
wp_fts_extension_output_check(
    $analysisError instanceof WP_FTS_Analysis_Limit_Exceeded && $analysisError->reason_code === 'occurrences',
    'a legacy analyzer array one occurrence above 20,000 should reject before array_values'
);
unset($overAnalysis);

foreach ([
    'term' => [
        ['term' => str_repeat('t', WP_FTS_TermNamespace::MAX_TERM_KEY_BYTES)],
        ['term' => str_repeat('t', WP_FTS_TermNamespace::MAX_TERM_KEY_BYTES + 1)],
        'analyzer occurrence bytes',
    ],
    'language' => [
        ['term' => 'term', 'lang' => str_repeat('l', 64)],
        ['term' => 'term', 'lang' => str_repeat('l', 65)],
        'analyzer language bytes',
    ],
    'surface' => [
        ['term' => 'term', 'surface' => str_repeat('s', 4096)],
        ['term' => 'term', 'surface' => str_repeat('s', 4097)],
        'analyzer occurrence bytes',
    ],
    'position' => [
        ['term' => 'term', 'position' => str_repeat('1', 64)],
        ['term' => 'term', 'position' => str_repeat('1', 65)],
        'analyzer occurrence bytes',
    ],
] as $label => [$exactOccurrence, $overOccurrence, $budget]) {
    $exactError = wp_fts_extension_output_caught(
        static fn(): array => $normalizeQueryAnalysis->invoke($searcher, [$exactOccurrence])
    );
    wp_fts_extension_output_check($exactError === null, "an exact {$label} analyzer scalar should remain valid");

    $overError = wp_fts_extension_output_caught(
        static fn(): array => $normalizeQueryAnalysis->invoke($searcher, [$overOccurrence])
    );
    wp_fts_extension_output_check(
        $overError instanceof WP_FTS_Search_Budget_Exceeded && $overError->budget() === $budget,
        "the first oversized {$label} analyzer scalar should reject before array reindexing"
    );
}

return $wp_fts_extension_output_checks;
