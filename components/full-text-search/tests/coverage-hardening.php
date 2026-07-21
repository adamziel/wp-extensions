<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

$wp_fts_component_hardening_checks = 0;

function wp_fts_component_hardening_check(bool $condition, string $message): void
{
    global $wp_fts_component_hardening_checks;
    $wp_fts_component_hardening_checks++;

    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function wp_fts_component_hardening_same(mixed $expected, mixed $actual, string $message): void
{
    wp_fts_component_hardening_check(
        $expected === $actual,
        $message . "\nExpected: " . var_export($expected, true) . "\nActual: " . var_export($actual, true)
    );
}

function wp_fts_component_hardening_caught(callable $operation): ?Throwable
{
    try {
        $operation();
    } catch (Throwable $error) {
        return $error;
    }

    return null;
}

/** @return string[] */
function wp_fts_component_hardening_terms(array $occurrences): array
{
    $terms = [];
    foreach ($occurrences as $occurrence) {
        $terms[] = is_array($occurrence) ? (string) ($occurrence['term'] ?? '') : (string) $occurrence;
    }
    sort($terms, SORT_STRING);

    return $terms;
}

$analyzer = new WP_FTS_Analyzer([
    'auto_detect_language' => false,
    'enable_stemming' => false,
    'default_lang' => 'en',
]);

wp_fts_component_hardening_same(
    'zh-Hans',
    WP_FTS_TermNamespace::canonicalize_lang('zh-CN'),
    'Chinese mainland regions should share the Simplified partition'
);
wp_fts_component_hardening_same(
    'zh-Hant',
    WP_FTS_TermNamespace::canonicalize_lang('zh_TW'),
    'Chinese Taiwan regions should share the Traditional partition'
);
wp_fts_component_hardening_same(
    'Alpha beta',
    WP_FTS_Html_Text_Stream::visible_text('<article><p>Alpha <em>beta</em></p><script>hidden</script></article>'),
    'HTML extraction should exclude hidden script text'
);
$htmlTerms = wp_fts_component_hardening_terms($analyzer->analyze_content(
    '<p>Portable <strong>full text</strong> search</p><style>.hidden{}</style>',
    ['document_lang' => 'en']
));
wp_fts_component_hardening_check(in_array('portable', $htmlTerms, true), 'HTML analysis should retain visible words');
wp_fts_component_hardening_check(!in_array('hidden', $htmlTerms, true), 'HTML analysis should drop hidden style text');

$indexer = new WP_FTS_Indexer($analyzer);
$fields = [
    ['name' => 'title', 'text' => 'Searchable Library', 'boost' => 3.0],
    ['name' => 'content', 'text' => 'Portable full text search works.', 'boost' => 1.0],
];
$prepared = $indexer->prepare_document_fields(41, $fields, ['document_lang' => 'en']);
wp_fts_component_hardening_same(
    ['doc_id', 'primary_lang', 'content_hash', 'snippet_text', 'term_frequencies', 'surface_frequencies'],
    array_keys($prepared),
    'field preparation should return the fixed relational writer payload'
);
wp_fts_component_hardening_same(41, $prepared['doc_id'], 'field preparation should preserve the document id');
wp_fts_component_hardening_same('en', $prepared['primary_lang'], 'field preparation should canonicalize the document language');
wp_fts_component_hardening_same(40, strlen($prepared['content_hash']), 'field preparation should return a SHA-1 content hash');
wp_fts_component_hardening_same(
    3,
    $prepared['term_frequencies'][WP_FTS_TermNamespace::namespace_term('en', 'searchable')] ?? null,
    'field preparation should apply the title boost to term frequency'
);
wp_fts_component_hardening_same(
    $prepared,
    $indexer->prepare_document_fields(41, $fields, ['document_lang' => 'en']),
    'field preparation should be deterministic'
);
$changed = $indexer->prepare_document_fields(41, [
    ['name' => 'title', 'text' => 'Changed Library', 'boost' => 3.0],
], ['document_lang' => 'en']);
wp_fts_component_hardening_check(
    $prepared['content_hash'] !== $changed['content_hash'],
    'changing normalized source fields should change the content hash'
);
wp_fts_component_hardening_check(
    wp_fts_component_hardening_caught(
        static fn(): array => $indexer->prepare_document_fields(0, $fields, ['document_lang' => 'en'])
    ) instanceof InvalidArgumentException,
    'field preparation should reject nonpositive document ids'
);
wp_fts_component_hardening_check(
    wp_fts_component_hardening_caught(
        static fn(): array => $indexer->prepare_document_fields(41, [
            ['name' => ' title ', 'text' => 'Searchable Library'],
        ], ['document_lang' => 'en'])
    ) instanceof InvalidArgumentException,
    'field preparation should reject padded field names instead of rewriting them'
);

$extractor = new class {
    /** @return array{fields:array<int,array<string,mixed>>,snippet_text:string} */
    public function extract(object $post, array $options): array
    {
        return [
            'fields' => [
                ['name' => 'title', 'text' => (string) $post->post_title, 'boost' => 3.0],
                ['name' => 'content', 'text' => (string) $post->post_content, 'boost' => 1.0],
            ],
            'snippet_text' => (string) $post->post_content,
        ];
    }
};
$postIndexer = new WP_FTS_Indexer($analyzer, $extractor);
$post = (object) [
    'ID' => 42,
    'post_title' => 'Prepared post',
    'post_content' => 'Portable relational search.',
    'post_excerpt' => '',
    'terms' => [],
    'custom_fields' => [],
];
$source = $postIndexer->prepare_post_source($post, ['document_lang' => 'en']);
wp_fts_component_hardening_same(
    ['doc_id', 'primary_lang', 'content_hash', 'fields', 'analysis_options', 'snippet_text'],
    array_keys($source),
    'post extraction should return the fixed deferred-analysis payload'
);
$preparedPost = $postIndexer->prepare_post_from_source($source);
wp_fts_component_hardening_same(42, $preparedPost['doc_id'], 'deferred post analysis should preserve the post id');
wp_fts_component_hardening_same(
    'Portable relational search.',
    $preparedPost['snippet_text'],
    'deferred post analysis should preserve the bounded snippet source'
);
$invalidSource = $source;
$invalidSource['unknown'] = true;
wp_fts_component_hardening_check(
    wp_fts_component_hardening_caught(
        static fn(): array => $postIndexer->prepare_post_from_source($invalidSource)
    ) instanceof InvalidArgumentException,
    'deferred post analysis should reject payload extensions'
);
$languageMismatchSource = $source;
$languageMismatchSource['analysis_options']['document_lang'] = 'fr';
wp_fts_component_hardening_check(
    wp_fts_component_hardening_caught(
        static fn(): array => $postIndexer->prepare_post_from_source($languageMismatchSource)
    ) instanceof InvalidArgumentException,
    'deferred post analysis should reject a language that differs from its primary partition'
);

$signatureAnalyzer = static fn(mixed $signature): object => new class($signature) {
    public int $analysisCalls = 0;

    public function __construct(private mixed $signature)
    {
    }

    public function index_signature(): mixed
    {
        if ($this->signature instanceof Throwable) {
            throw $this->signature;
        }

        return $this->signature;
    }

    public function analyze_document_fields(array $fields, array $options): array
    {
        $this->analysisCalls++;

        return array_fill(0, count($fields), []);
    }
};
foreach ([1, '', ' padded-signature '] as $invalidSignature) {
    $invalidAnalyzer = $signatureAnalyzer($invalidSignature);
    $invalidIndexer = new WP_FTS_Indexer($invalidAnalyzer);
    wp_fts_component_hardening_check(
        wp_fts_component_hardening_caught(
            static fn(): array => $invalidIndexer->prepare_document_fields(1, $fields, ['document_lang' => 'en'])
        ) instanceof LogicException,
        'index preparation should reject a non-native, empty, or padded analyzer signature'
    );
    wp_fts_component_hardening_same(
        0,
        $invalidAnalyzer->analysisCalls,
        'an invalid analyzer signature should reject before document analysis'
    );
}
$analyzerSignatureFailure = new DomainException('index signature failure');
$throwingSignatureAnalyzer = $signatureAnalyzer($analyzerSignatureFailure);
$caughtAnalyzerSignatureFailure = wp_fts_component_hardening_caught(
    static fn(): array => (new WP_FTS_Indexer($throwingSignatureAnalyzer))->prepare_document_fields(
        1,
        $fields,
        ['document_lang' => 'en']
    )
);
wp_fts_component_hardening_check(
    $caughtAnalyzerSignatureFailure === $analyzerSignatureFailure,
    'an analyzer signature exception should reach the indexing caller unchanged'
);

foreach (['EN', 'e!n'] as $invalidOccurrenceLanguage) {
    $occurrenceAnalyzer = new class($invalidOccurrenceLanguage) {
        public function __construct(private string $language)
        {
        }

        public function index_signature(): string
        {
            return 'invalid-document-occurrence-language-v1';
        }

        public function analyze_document_fields(array $fields, array $options): array
        {
            return array_map(
                fn(): array => [[
                    'term' => 'term',
                    'weight' => 1,
                    'lang' => $this->language,
                ]],
                $fields
            );
        }
    };
    wp_fts_component_hardening_check(
        wp_fts_component_hardening_caught(
            static fn(): array => (new WP_FTS_Indexer($occurrenceAnalyzer))->prepare_document_fields(
                1,
                $fields,
                ['document_lang' => 'en']
            )
        ) instanceof InvalidArgumentException,
        'document analyzer output should reject malformed or noncanonical languages'
    );
}
wp_fts_component_hardening_check(
    !method_exists(WP_FTS_Indexer::class, 'index_document')
        && !method_exists(WP_FTS_Indexer::class, 'delete_document'),
    'the component indexer should prepare data without owning persistence'
);

$explain = [
    'storage' => 'set_oriented',
    'logical_group_count' => 2,
    'resolved_alternatives' => 2,
    'anchor_group' => 0,
    'prefix_range' => true,
    'prefix_strategy' => 'surface_range',
    'query_statements' => 3,
    'interactive_total' => 'unknown',
    'recency_boost' => [
        'enabled' => false,
        'strength' => 0.0,
        'half_life_days' => 30.0,
        'scoring_now_gmt' => '',
    ],
    'canonical_page_bytes' => 0,
];
$storage = new WP_FTS_Test_Set_Oriented_Search_Storage([
    'results' => [[
        'doc_id' => 41,
        'score' => 4.25,
        'post_id' => 41,
        'post_type' => 'post',
        'post_status' => 'publish',
        'post_date_gmt' => '2026-01-02 03:04:05',
        'title' => 'Portable Search',
        'excerpt' => 'A library',
        'primary_lang' => 'en',
        'snippet_text' => 'Portable full text search works.',
    ]],
    'has_more' => false,
    'next_cursor' => null,
    'previous_cursor' => null,
    'explain' => $explain,
]);
$searcher = new WP_FTS_Searcher($storage, $analyzer);
$payload = $searcher->search('portable search', [
    'mode' => 'AND',
    'limit' => 1,
    'query_lang' => 'en',
    'prefix_matching' => true,
    'include_metadata' => true,
    'include_snippets' => true,
    'highlight' => true,
    'explain' => true,
]);
wp_fts_component_hardening_same(1, $storage->call_count, 'search should execute one relational storage call');
wp_fts_component_hardening_same('AND', $storage->last_options['mode'] ?? null, 'search should pass match mode to storage');
wp_fts_component_hardening_same(1, $storage->last_options['page_size'] ?? null, 'search should pass the requested page size');
wp_fts_component_hardening_check(
    !array_key_exists('limit', $storage->last_options),
    'storage should own its lookahead from page_size without a second limit option'
);
wp_fts_component_hardening_same(
    ['lang' => 'en', 'term' => 'search'],
    $storage->last_options['prefix_surface'] ?? null,
    'search should pass one final-token prefix surface to storage'
);
wp_fts_component_hardening_check(
    !array_key_exists('query_lang', $storage->last_options),
    'storage options should not duplicate language already carried by query groups'
);
wp_fts_component_hardening_same(2, count($storage->last_groups), 'search should preserve two logical query groups');
wp_fts_component_hardening_same(
    WP_FTS_TermNamespace::namespace_term('en', 'portable'),
    $storage->last_groups[0][0]['key'] ?? null,
    'search should namespace analyzed terms before storage'
);
wp_fts_component_hardening_check(
    !array_key_exists('total', $payload) && !array_key_exists('total_relation', $payload),
    'relational search should not publish unavailable total fields'
);
wp_fts_component_hardening_same(41, $payload['results'][0]['doc_id'] ?? null, 'search should return the backend document id');
wp_fts_component_hardening_same(4.25, $payload['results'][0]['score'] ?? null, 'search should preserve finite backend scores');
wp_fts_component_hardening_check(
    str_contains((string) ($payload['results'][0]['snippet'] ?? ''), '<mark>Portable</mark>'),
    'search should highlight bounded snippet sidecars'
);
wp_fts_component_hardening_check(
    !array_key_exists('snippet_text', $payload['results'][0]),
    'search should not expose raw snippet sidecars'
);
wp_fts_component_hardening_same(
    $explain,
    $payload['explain'] ?? null,
    'search should expose the exact backend explain data when requested'
);

$callsBeforeInvalidOption = $storage->call_count;
$unsupportedOptionError = wp_fts_component_hardening_caught(
    static fn(): array => $searcher->search('portable', ['query_lang' => 'en', 'offset' => 2])
);
wp_fts_component_hardening_check(
    $unsupportedOptionError instanceof InvalidArgumentException
        && str_contains($unsupportedOptionError->getMessage(), 'does not support offset'),
    'search should reject options outside the relational contract'
);
wp_fts_component_hardening_same(
    $callsBeforeInvalidOption,
    $storage->call_count,
    'invalid options should be rejected before storage'
);

$emptyStorage = new WP_FTS_Test_Set_Oriented_Search_Storage();
$emptyPayload = (new WP_FTS_Searcher($emptyStorage, $analyzer))->search('', ['query_lang' => 'en']);
wp_fts_component_hardening_same(0, $emptyStorage->call_count, 'an empty query plan should not call storage');
wp_fts_component_hardening_same([], $emptyPayload['results'], 'an empty query plan should return an empty cursor page');

$badStorage = new WP_FTS_Test_Set_Oriented_Search_Storage([
    'results' => [['doc_id' => 0, 'score' => 1.0]],
    'has_more' => false,
    'next_cursor' => null,
    'previous_cursor' => null,
]);
wp_fts_component_hardening_check(
    wp_fts_component_hardening_caught(
        static fn(): array => (new WP_FTS_Searcher($badStorage, $analyzer))->search('portable', ['query_lang' => 'en'])
    ) instanceof LogicException,
    'search should reject malformed backend result rows'
);

$snippet = $searcher->snippet_for_text(
    '<p><strong onclick="alert(1)">portable</strong> text <img src=x onerror=alert(1)></p>',
    'portable',
    ['query_lang' => 'en', 'highlight' => true, 'snippet_length' => 80]
);
wp_fts_component_hardening_check(str_contains($snippet, '<mark>portable</mark>'), 'direct snippets should highlight visible matches');
wp_fts_component_hardening_check(
    !str_contains($snippet, '<strong')
        && !str_contains($snippet, '<img')
        && !str_contains($snippet, 'onclick')
        && !str_contains($snippet, 'onerror'),
    'direct snippets should not preserve source tags or attributes'
);

return $wp_fts_component_hardening_checks;
