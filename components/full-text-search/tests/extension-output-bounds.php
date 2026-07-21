<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

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
    public ?int $receivedCeiling = null;

    /** Identify this stateless probe in analyzer fingerprints. */
    public function index_signature(): string
    {
        return 'wp-fts-extension-input-bounds-probe-v1';
    }

    /** @return string[] */
    public function __invoke(string $run, string $language, int $maxTokens): array
    {
        $this->calls++;
        $this->receivedBytes = strlen($run);
        $this->receivedCeiling = $maxTokens;

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
wp_fts_extension_output_check(
    $validInputProbe->receivedCeiling === WP_FTS_Analysis_Limits::MAX_DOCUMENT_OCCURRENCES + 1,
    'the tokenizer should receive the document producer ceiling'
);

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
        'cjk_tokenizer' => static fn(string $_run, string $_language, int $_maxTokens): array => [
            str_repeat('a', $outputBytes),
        ],
    ]);

    return $pipeline->analyze_detailed('中文', 'zh');
};
wp_fts_extension_output_check(
    $tokenizerAttempt($lexicalLimit) === [],
    'a tokenizer output at the 4-KiB boundary should remain valid before the term-key filter'
);
$tokenizerError = wp_fts_extension_output_caught(static fn(): array => $tokenizerAttempt($lexicalLimit + 1));
wp_fts_extension_output_check(
    $tokenizerError instanceof WP_FTS_Analysis_Limit_Exceeded && $tokenizerError->reason_code === 'lexical_run_bytes',
    'a tokenizer output one byte above 4 KiB should reject before trim'
);

$normalizerAttempt = static function (int $outputBytes): array {
    $pipeline = new WP_FTS_LanguagePipeline([
        'enable_stemming' => false,
        'token_normalizer' => static fn(): string => str_repeat('a', $outputBytes),
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
        'stemmer' => static fn(string $_term, string $_language): string => str_repeat('a', $outputBytes),
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
    new WP_FTS_Test_Set_Oriented_Search_Storage(),
    new WP_FTS_Analyzer(['auto_detect_language' => false, 'enable_stemming' => false])
);
$normalizeQueryAnalysis = new ReflectionMethod($searcher, 'normalize_query_analysis');
$normalizeQueryAnalysis->setAccessible(true);
$occurrenceLimit = WP_FTS_Analysis_Limits::MAX_DOCUMENT_OCCURRENCES;
$exactAnalysis = array_fill(0, $occurrenceLimit, ['term' => 'term', 'lang' => 'en']);
$normalizedAnalysis = $normalizeQueryAnalysis->invoke($searcher, $exactAnalysis);
wp_fts_extension_output_check(
    is_array($normalizedAnalysis) && count($normalizedAnalysis) === $occurrenceLimit,
    'an analyzer array at the 20,000-occurrence boundary should remain valid'
);
unset($normalizedAnalysis, $exactAnalysis);

$overAnalysis = array_fill(0, $occurrenceLimit + 1, ['term' => 'term', 'lang' => 'en']);
$analysisError = wp_fts_extension_output_caught(
    static fn(): array => $normalizeQueryAnalysis->invoke($searcher, $overAnalysis)
);
wp_fts_extension_output_check(
    $analysisError instanceof WP_FTS_Analysis_Limit_Exceeded && $analysisError->reason_code === 'occurrences',
    'an analyzer array one occurrence above 20,000 should reject before array_values'
);
unset($overAnalysis);

foreach ([
    'term' => [
        ['term' => str_repeat('t', WP_FTS_TermNamespace::MAX_TERM_KEY_BYTES), 'lang' => 'en'],
        ['term' => str_repeat('t', WP_FTS_TermNamespace::MAX_TERM_KEY_BYTES + 1), 'lang' => 'en'],
    ],
    'language' => [
        ['term' => 'term', 'lang' => 'aa' . str_repeat('-aaaaaaaa', 6) . '-aaaaaaa'],
        ['term' => 'term', 'lang' => 'aa' . str_repeat('-aaaaaaaa', 7)],
    ],
    'canonical language' => [
        ['term' => 'term', 'lang' => 'en'],
        ['term' => 'term', 'lang' => 'EN'],
    ],
    'term whitespace' => [
        ['term' => 'term', 'lang' => 'en'],
        ['term' => 'two terms', 'lang' => 'en'],
    ],
    'term namespace separator' => [
        ['term' => 'term', 'lang' => 'en'],
        ['term' => 'en' . WP_FTS_TermNamespace::SEPARATOR . 'term', 'lang' => 'en'],
    ],
    'surface' => [
        ['term' => 'term', 'lang' => 'en', 'surface' => str_repeat('s', 4096)],
        ['term' => 'term', 'lang' => 'en', 'surface' => str_repeat('s', 4097)],
    ],
    'normalized surface' => [
        ['term' => 'term', 'lang' => 'en', 'normalized_surface' => str_repeat('s', 4096)],
        ['term' => 'term', 'lang' => 'en', 'normalized_surface' => str_repeat('s', 4097)],
    ],
    'normalized surface whitespace' => [
        ['term' => 'term', 'lang' => 'en', 'normalized_surface' => 'surface'],
        ['term' => 'term', 'lang' => 'en', 'normalized_surface' => 'two surfaces'],
    ],
    'normalized surface namespace separator' => [
        ['term' => 'term', 'lang' => 'en', 'normalized_surface' => 'surface'],
        ['term' => 'term', 'lang' => 'en', 'normalized_surface' => 'en' . WP_FTS_TermNamespace::SEPARATOR . 'surface'],
    ],
    'padded surface' => [
        ['term' => 'term', 'lang' => 'en', 'surface' => 'surface'],
        ['term' => 'term', 'lang' => 'en', 'surface' => ' surface'],
    ],
    'source' => [
        ['term' => 'term', 'lang' => 'en', 'source' => str_repeat('s', 256)],
        ['term' => 'term', 'lang' => 'en', 'source' => str_repeat('s', 257)],
    ],
    'padded source' => [
        ['term' => 'term', 'lang' => 'en', 'source' => 'analyzer'],
        ['term' => 'term', 'lang' => 'en', 'source' => ' analyzer'],
    ],
    'position' => [
        ['term' => 'term', 'lang' => 'en', 'position' => PHP_INT_MAX],
        ['term' => 'term', 'lang' => 'en', 'position' => '1'],
    ],
] as $label => [$exactOccurrence, $invalidOccurrence]) {
    $exactError = wp_fts_extension_output_caught(
        static fn(): array => $normalizeQueryAnalysis->invoke($searcher, [$exactOccurrence])
    );
    wp_fts_extension_output_check($exactError === null, "an exact {$label} analyzer scalar should remain valid");

    $invalidError = wp_fts_extension_output_caught(
        static fn(): array => $normalizeQueryAnalysis->invoke($searcher, [$invalidOccurrence])
    );
    wp_fts_extension_output_check(
        $invalidError instanceof InvalidArgumentException,
        "the first invalid {$label} analyzer scalar should reject before array reindexing"
    );
}

WP_FTS_Analyzer_Occurrence_Validator::assert_document([
    'term' => 'term',
    'lang' => 'en',
    'weight' => 100,
]);
foreach ([100.01, PHP_FLOAT_MAX] as $invalidWeight) {
    $weightError = wp_fts_extension_output_caught(
        static function () use ($invalidWeight): void {
            WP_FTS_Analyzer_Occurrence_Validator::assert_document([
                'term' => 'term',
                'lang' => 'en',
                'weight' => $invalidWeight,
            ]);
        }
    );
    wp_fts_extension_output_check(
        $weightError instanceof InvalidArgumentException,
        'document analyzer weights above the fixed ceiling must reject before field multiplication'
    );
}

return $wp_fts_extension_output_checks;
