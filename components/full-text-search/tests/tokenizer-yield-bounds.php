<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';

final class WP_FTS_Tokenizer_Yield_Bounds_Probe
{
    public int $yields = 0;

    public function __construct(
        private mixed $value,
        private ?int $yieldLimit = null,
    ) {
    }

    public function __invoke(string $run, string $language): Generator
    {
        while ($this->yieldLimit === null || $this->yields < $this->yieldLimit) {
            $this->yields++;
            yield $this->value;
        }
    }
}

$wp_fts_tokenizer_yield_checks = 0;

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
$exactProbe = new WP_FTS_Tokenizer_Yield_Bounds_Probe(['ignored' => true], $limit);
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
    count($exact['terms']) === 3,
    'an exact-boundary invalid tokenizer result should retain bounded CJK fallback tokens'
);

$overProbe = new WP_FTS_Tokenizer_Yield_Bounds_Probe(['ignored' => true], $limit + 1);
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
        $attempt['error'] instanceof WP_FTS_Analysis_Limit_Exceeded
            && $attempt['error']->reason_code === 'occurrences',
        "an infinite {$label} tokenizer should raise the typed occurrence limit"
    );
    wp_fts_tokenizer_yield_check(
        $probe->yields === $limit + 1,
        "an infinite {$label} tokenizer should stop after one raw yield above the allowance"
    );
}

return $wp_fts_tokenizer_yield_checks;
