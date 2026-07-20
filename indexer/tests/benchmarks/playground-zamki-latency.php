<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';

/**
 * Focused benchmark for the Playground/admin Polish full-pack search path.
 *
 * This intentionally mirrors the sandbox demo shape: construct the bundled full
 * Polish analyzer pack, index one demo-style document, and run a highlighted
 * single-word query for "Zamki".
 */
final class WP_FTS_Playground_Zamki_Latency_Benchmark
{
    public static function main(): int
    {
        if (!WP_FTS_AnalyzerPackValidator::gzip_available() || !function_exists('gzdecode')) {
            fwrite(STDERR, "gzip/zlib support is required for the compressed Polish pack benchmark.\n");
            return 2;
        }

        $result = [
            'schema' => 'wp-fts-playground-zamki-latency-benchmark-v1',
            'query' => 'Zamki',
            'normalized_query' => 'zamki',
            'phases_ms' => [],
        ];

        $started = hrtime(true);
        $manifest = WP_FTS_AnalyzerPackValidator::default_polish_playground_full_manifest();
        $pack = WP_FTS_LanguageLemmaPack::from_manifest_file($manifest);
        $result['phases_ms']['pack_construct'] = self::elapsed_ms($started);

        $started = hrtime(true);
        $analyses = $pack->analyze('zamki', 'pl');
        $result['phases_ms']['direct_pack_lookup'] = self::elapsed_ms($started);
        $result['direct_pack_terms'] = array_map(static fn(array $row): string => $row['term'], $analyses);
        $result['direct_pack_lookup_stats'] = $pack->last_lookup_stats();

        $started = hrtime(true);
        $analyzer = new WP_FTS_Analyzer([
            'default_lang' => 'pl',
            'lemma_packs_by_lang' => [
                'pl' => $manifest,
            ],
        ]);
        $result['phases_ms']['analyzer_construct'] = self::elapsed_ms($started);

        $storage = new WP_FTS_Storage_InMemory();
        $indexer = new WP_FTS_Indexer($storage, $analyzer);
        $text = 'W książkach i zamkach wyszukujemy wpisy oraz kierujemy katalog.';

        $started = hrtime(true);
        $indexer->index_document_fields(1, [['name' => 'content', 'text' => $text]], [
            'lang' => 'pl',
            'metadata' => [
                'post_id' => 1,
                'post_type' => 'post',
                'post_status' => 'publish',
                'title' => 'FTS Sandbox: Polish Lemmatizer Demo',
                'search_text' => $text,
                'language' => 'pl',
            ],
        ]);
        $result['phases_ms']['index_demo_doc'] = self::elapsed_ms($started);

        $searcher = new WP_FTS_Searcher($storage, $analyzer);
        $started = hrtime(true);
        $payload = $searcher->search('Zamki', [
            'query_lang' => 'pl',
            'mode' => 'AND',
            'include_total' => true,
            'include_metadata' => true,
            'include_snippets' => true,
            'highlight' => true,
            'snippet_length' => 180,
        ]);
        $result['phases_ms']['search_with_snippet'] = self::elapsed_ms($started);
        $result['search_total'] = $payload['total'] ?? null;
        $result['first_snippet'] = $payload['results'][0]['snippet'] ?? '';
        $result['peak_memory_bytes'] = memory_get_peak_usage(true);

        echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";

        return 0;
    }

    private static function elapsed_ms(int $started): float
    {
        return (hrtime(true) - $started) / 1_000_000;
    }
}

exit(WP_FTS_Playground_Zamki_Latency_Benchmark::main());
