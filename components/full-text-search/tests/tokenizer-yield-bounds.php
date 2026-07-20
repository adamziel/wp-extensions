<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

final class WP_FTS_Tokenizer_Yield_Bounds_Probe
{
    public int $yields = 0;
    public int $receivedArguments = 0;
    public ?int $receivedCeiling = null;

    /** Configures the yielded value and optional finite yield ceiling. */
    public function __construct(
        private mixed $value,
        private ?int $yieldLimit = null,
    ) {
    }

    /** Identify the configured probe output and ceiling in analyzer fingerprints. */
    public function index_signature(): string
    {
        return 'wp-fts-tokenizer-yield-probe-v1:' . sha1(serialize([
            $this->value,
            $this->yieldLimit,
        ]));
    }

    /** Yields fixture values until the consumer or configured ceiling stops it. */
    public function __invoke(string $run, string $language, int $maxTokens): Generator
    {
        $this->receivedArguments = max($this->receivedArguments, func_num_args());
        $this->receivedCeiling = $maxTokens;
        while ($this->yieldLimit === null || $this->yields < $this->yieldLimit) {
            $this->yields++;
            yield $this->value;
        }
    }
}

$wp_fts_tokenizer_yield_checks = 0;

/** Records one assertion and throws when a tokenizer-yield invariant fails. */
function wp_fts_tokenizer_yield_check(bool $condition, string $message): void
{
    global $wp_fts_tokenizer_yield_checks;
    $wp_fts_tokenizer_yield_checks++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

/** @return array{terms:array<int,array<string,mixed>>,error:?Throwable} */
function wp_fts_tokenizer_yield_analyze(WP_FTS_Tokenizer_Yield_Bounds_Probe $probe, int $limit): array
{
    $analyzer = new WP_FTS_Analyzer([
        'auto_detect_language' => false,
        'default_lang' => 'zh',
        'query_lang' => 'zh',
        'enable_stemming' => false,
        'cjk_tokenizer' => $probe,
    ]);

    try {
        return [
            'terms' => $analyzer->analyze_query_occurrences('中文', [
                'query_lang' => 'zh',
                '_max_query_occurrences' => $limit,
            ]),
            'error' => null,
        ];
    } catch (Throwable $error) {
        return ['terms' => [], 'error' => $error];
    }
}

$limit = WP_FTS_Set_Oriented_Search_Storage::MAX_QUERY_ALTERNATIVES;
$exactProbe = new WP_FTS_Tokenizer_Yield_Bounds_Probe('中文', $limit);
$exact = wp_fts_tokenizer_yield_analyze($exactProbe, $limit);
wp_fts_tokenizer_yield_check(
    $exact['error'] === null,
    'an extension tokenizer may inspect exactly the analyzer occurrence allowance'
);
wp_fts_tokenizer_yield_check(
    $exactProbe->yields === $limit,
    'the exact-boundary tokenizer should be consumed completely'
);
wp_fts_tokenizer_yield_check(
    $exact['terms'] !== [],
    'an exact-boundary tokenizer should emit its valid token output'
);
wp_fts_tokenizer_yield_check(
    $exactProbe->receivedArguments === 3 && $exactProbe->receivedCeiling === $limit + 1,
    'a custom tokenizer should receive the canonical producer ceiling as its third argument'
);

$overProbe = new WP_FTS_Tokenizer_Yield_Bounds_Probe('中文', $limit + 1);
$over = wp_fts_tokenizer_yield_analyze($overProbe, $limit);
wp_fts_tokenizer_yield_check(
    $over['error'] instanceof WP_FTS_Analysis_Limit_Exceeded
        && $over['error']->reason_code === 'occurrences',
    'the first raw yield above the analyzer allowance should raise the typed occurrence limit'
);
wp_fts_tokenizer_yield_check(
    $overProbe->yields === $limit + 1,
    'the over-boundary tokenizer should stop on its first excess raw yield'
);

foreach ([
    'null' => null,
    'empty string' => '',
    'invalid array' => ['ignored' => true],
] as $label => $value) {
    $probe = new WP_FTS_Tokenizer_Yield_Bounds_Probe($value);
    $attempt = wp_fts_tokenizer_yield_analyze($probe, $limit);
    wp_fts_tokenizer_yield_check(
        $attempt['error'] instanceof UnexpectedValueException,
        "an invalid {$label} tokenizer output should reject immediately"
    );
    wp_fts_tokenizer_yield_check(
        $probe->yields === 1,
        "an invalid {$label} tokenizer output should stop on its first item"
    );
}

return $wp_fts_tokenizer_yield_checks;
