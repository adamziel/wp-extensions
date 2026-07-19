<?php
declare(strict_types=1);

test_case('morphology highlighting has a page-sized analyzer-call and memory ceiling', function (): void {
    $inner = new WP_FTS_Analyzer([
        'default_lang' => 'pl',
        'auto_detect_language' => false,
    ]);
    $analyzer = new class($inner) {
        public int $calls = 0;

        /** Retain the production analyzer behind a call-counting test decorator. */
        public function __construct(private WP_FTS_Analyzer $inner)
        {
        }

        /** Prove highlighting analyzes once per query rather than once per result. */
        public function analyze_query_occurrences(string $query, array $options = []): array
        {
            $this->calls++;

            return $this->inner->analyze_query_occurrences($query, $options);
        }
    };
    $searcher = new WP_FTS_Searcher(new WP_FTS_Storage_InMemory(), $analyzer);

    $tokens = [];
    for ($index = 0; $index < 2400; $index++) {
        $tokens[] = 'unikalnytoken' . str_pad((string) $index, 4, '0', STR_PAD_LEFT);
    }
    $source = substr(implode(' ', $tokens), 0, 20000);
    $peakBefore = memory_get_peak_usage(true);
    for ($row = 0; $row < 20; $row++) {
        $snippet = $searcher->snippet_for_text($source, 'zamek', [
            'lang' => 'pl',
            'snippet_length' => 500,
            'highlight' => true,
        ]);
        assert_true(strlen($snippet) <= 2200, 'each adversarial snippet should remain bounded to its presentation page window');
    }
    $peakDelta = max(0, memory_get_peak_usage(true) - $peakBefore);

    assert_true($analyzer->calls <= 60, 'twenty no-literal snippets may use at most query plus two bounded analyzer passes per row');
    assert_true($peakDelta <= 16 * 1024 * 1024, 'twenty adversarial snippet calls must stay within the 16 MiB page-enrichment allocation gate');
});
