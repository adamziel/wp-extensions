<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/src/bootstrap.php';

const WP_FTS_SNIPPET_BENCH_DOCS = 1000;
const WP_FTS_SNIPPET_BENCH_REPEATS = 20;

/**
 * @return array<int,object>
 */
function wp_fts_snippet_bench_posts(): array
{
    $languages = ['en', 'pl', 'de', 'es', 'fr'];
    $posts = [];
    for ($i = 1; $i <= WP_FTS_SNIPPET_BENCH_DOCS; $i++) {
        $lang = $languages[($i - 1) % count($languages)];
        $common = 'commonterm' . ($i % 8);
        $rare = 'rareterm' . $i;
        $chunks = [];
        for ($j = 0; $j < 44; $j++) {
            $chunks[] = sprintf(
                '<p data-doc="%d" data-chunk="%d">%s %s topic%d filler%d %s %s ' .
                '<a href="#doc%d-%d">linked%d</a> <span class="plain">context%d</span></p>',
                $i,
                $j,
                $common,
                $rare,
                $j % 13,
                $j % 17,
                '<strong>Word</strong>Press',
                'Szk<em>l<i><b>ar</b></i></em>nia',
                $i,
                $j,
                $j,
                $j
            );
        }
        $posts[] = (object) [
            'ID' => $i,
            'post_title' => sprintf('Synthetic %s document %d %s', $lang, $i, $rare),
            'post_content' => implode("\n", $chunks),
            'post_excerpt' => sprintf('Short excerpt %s %s WordPress Szklarnia', $common, $rare),
            'post_type' => $i % 5 === 0 ? 'page' : 'post',
            'post_status' => $i % 11 === 0 ? 'draft' : 'publish',
            'post_date_gmt' => sprintf('2026-05-%02d 12:00:00', ($i % 28) + 1),
        ];
    }

    return $posts;
}

/**
 * @return array<int,array{query:string,lang:string,mode:string,highlight:bool}>
 */
function wp_fts_snippet_bench_queries(): array
{
    return [
        ['query' => 'commonterm3', 'lang' => 'en', 'mode' => 'OR', 'highlight' => true],
        ['query' => 'commonterm5 topic7', 'lang' => 'pl', 'mode' => 'AND', 'highlight' => true],
        ['query' => 'rareterm778', 'lang' => 'de', 'mode' => 'OR', 'highlight' => true],
        ['query' => 'WordPress', 'lang' => 'es', 'mode' => 'OR', 'highlight' => true],
        ['query' => 'Szklarnia', 'lang' => 'fr', 'mode' => 'OR', 'highlight' => true],
        ['query' => 'linked14 context14', 'lang' => 'en', 'mode' => 'AND', 'highlight' => false],
    ];
}

/**
 * @return array{storage:WP_FTS_Storage_InMemory,index_ms:float,peak_memory:int}
 */
function wp_fts_snippet_bench_build_index(array $posts): array
{
    $storage = new WP_FTS_Storage_InMemory();
    $analyzer = new WP_FTS_Analyzer(['auto_detect_language' => false]);
    $extractor = new WP_FTS_PostContentExtractor();
    $indexer = new WP_FTS_Indexer($storage, $analyzer, $extractor);
    $start = hrtime(true);

    foreach ($posts as $post) {
        $lang = ['en', 'pl', 'de', 'es', 'fr'][($post->ID - 1) % 5];
        $extracted = $extractor->extract($post, [
            'lang' => $lang,
            'metadata_text_limit' => 20000,
        ]);
        $indexer->index_document_fields((int) $post->ID, $extracted['fields'], [
            'lang' => $lang,
            'metadata' => $extracted['metadata'] + ['language' => $lang],
            'field_boosts' => $extracted['field_boosts'],
        ]);
    }
    $storage->flush();

    return [
        'storage' => $storage,
        'index_ms' => (hrtime(true) - $start) / 1_000_000,
        'peak_memory' => memory_get_peak_usage(true),
    ];
}

/**
 * @return array{avg_ms:float,p95_ms:float,total_runs:int,first_snippets:array<string,string>}
 */
function wp_fts_snippet_bench_query_times(WP_FTS_Storage_InMemory $storage): array
{
    $searcher = new WP_FTS_Searcher($storage, new WP_FTS_Analyzer(['auto_detect_language' => false]));
    $times = [];
    $snippets = [];
    foreach (range(1, WP_FTS_SNIPPET_BENCH_REPEATS) as $_) {
        foreach (wp_fts_snippet_bench_queries() as $query) {
            $start = hrtime(true);
            $payload = $searcher->search($query['query'], [
                'lang' => $query['lang'],
                'mode' => $query['mode'],
                'limit' => 10,
                'include_total' => true,
                'include_metadata' => true,
                'include_snippets' => true,
                'highlight' => $query['highlight'],
                'snippet_length' => 180,
            ]);
            $times[] = (hrtime(true) - $start) / 1_000_000;
            $key = $query['lang'] . ':' . $query['query'];
            if (!isset($snippets[$key])) {
                $snippets[$key] = (string) ($payload['results'][0]['snippet'] ?? '');
            }
        }
    }
    sort($times, SORT_NUMERIC);
    $p95Index = max(0, (int) ceil(count($times) * 0.95) - 1);

    return [
        'avg_ms' => array_sum($times) / max(1, count($times)),
        'p95_ms' => $times[$p95Index] ?? 0.0,
        'total_runs' => count($times),
        'first_snippets' => $snippets,
    ];
}

/**
 * @return array<string,int|float>
 */
function wp_fts_snippet_bench_storage_metrics(WP_FTS_Storage_InMemory $storage): array
{
    $docIds = $storage->all_doc_ids(false);
    $metadata = $storage->get_doc_metadata($docIds);
    $metadataBytes = 0;
    $searchHtmlBytes = 0;
    $searchTextBytes = 0;
    foreach ($metadata as $row) {
        $metadataBytes += strlen(json_encode($row, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        $searchHtmlBytes += strlen((string) ($row['search_html'] ?? ''));
        $searchTextBytes += strlen((string) ($row['search_text'] ?? ''));
    }

    $postingCount = 0;
    $postingBytes = 0;
    $terms = $storage->all_terms();
    foreach ($storage->get_terms($terms) as $row) {
        $postingBytes += strlen($row['postings']);
        $postingCount += count(WP_FTS_PostingsCodec::decode($row['postings']));
    }

    return [
        'docs' => count($docIds),
        'metadata_total_bytes' => $metadataBytes,
        'metadata_avg_bytes_per_doc' => $metadataBytes / max(1, count($docIds)),
        'search_html_total_bytes' => $searchHtmlBytes,
        'search_text_total_bytes' => $searchTextBytes,
        'in_memory_state_bytes' => strlen(serialize(wp_fts_snippet_bench_reflected_state($storage))),
        'file_state_json_bytes' => strlen(wp_fts_snippet_bench_file_state_json($storage)),
        'term_count' => count($terms),
        'posting_count' => $postingCount,
        'postings_bytes' => $postingBytes,
    ];
}

/**
 * @return array<string,mixed>
 */
function wp_fts_snippet_bench_reflected_state(WP_FTS_Storage_InMemory $storage): array
{
    $state = [];
    foreach (['terms', 'docs', 'docMetadata', 'meta'] as $property) {
        $reflection = new ReflectionProperty($storage, $property);
        $reflection->setAccessible(true);
        $state[$property] = $reflection->getValue($storage);
    }

    return $state;
}

function wp_fts_snippet_bench_file_state_json(WP_FTS_Storage_InMemory $storage): string
{
    $state = wp_fts_snippet_bench_reflected_state($storage);
    $terms = [];
    foreach (($state['terms'] ?? []) as $term => $row) {
        $terms[(string) $term] = [
            'df' => (int) ($row['df'] ?? 0),
            'postings' => base64_encode((string) ($row['postings'] ?? '')),
        ];
    }
    $docs = [];
    foreach (($state['docs'] ?? []) as $docId => $doc) {
        $docs[(string) $docId] = $doc;
    }
    $docMetadata = [];
    foreach (($state['docMetadata'] ?? []) as $docId => $metadata) {
        $docMetadata[(string) $docId] = $metadata;
    }

    return json_encode([
        'version' => 2,
        'terms' => $terms,
        'docs' => $docs,
        'doc_meta' => $docMetadata,
        'meta' => $state['meta'] ?? [],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
}

$build = wp_fts_snippet_bench_build_index(wp_fts_snippet_bench_posts());
$storageMetrics = wp_fts_snippet_bench_storage_metrics($build['storage']);
$queryMetrics = wp_fts_snippet_bench_query_times($build['storage']);
echo json_encode([
    'docs' => WP_FTS_SNIPPET_BENCH_DOCS,
    'index_ms' => $build['index_ms'],
    'peak_memory_bytes' => $build['peak_memory'],
    'storage' => $storageMetrics,
    'queries' => $queryMetrics,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
