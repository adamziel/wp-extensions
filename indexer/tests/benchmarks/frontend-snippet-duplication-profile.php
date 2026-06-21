<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/src/bootstrap.php';

/**
 * Deterministic profile for duplicate frontend snippet work.
 *
 * The legacy-equivalent scenarios model the pre-reuse path: search results are
 * already enriched with snippets, then visible rows separately call
 * snippet_for_text() for content and title. The reuse scenarios keep title
 * highlighting but reuse content snippets that are already sanitized and proven
 * to come from post_content.
 */
final class WP_FTS_Frontend_Snippet_Duplication_Profile
{
    /**
     * @param string[] $argv
     */
    public static function main(array $argv): int
    {
        $documents = self::int_option($argv, '--documents', 51);
        $iterations = self::int_option($argv, '--iterations', 80);
        $warmup = self::int_option($argv, '--warmup', 8);
        $json = in_array('--json', $argv, true);

        $build = self::build_fixture($documents);
        $searcher = new WP_FTS_Searcher($build['storage'], $build['analyzer']);
        $profiles = [];
        foreach (self::scenarios() as $name => $scenario) {
            $profiles[$name] = self::measure(
                static fn(): array => self::run_scenario($searcher, $build['posts'], $scenario),
                $iterations,
                $warmup
            ) + [
                'query' => $scenario['query'],
                'highlight' => (bool) ($scenario['opts']['highlight'] ?? false),
                'prefix_matching' => (bool) ($scenario['opts']['prefix_matching'] ?? false),
                'path' => $scenario['path'],
            ];
        }

        $result = [
            'schema' => 'wp-fts-frontend-snippet-duplication-profile-v1',
            'documents' => $documents,
            'visible_limit' => 10,
            'iterations' => $iterations,
            'warmup' => $warmup,
            'index_ms' => $build['index_ms'],
            'profiles' => $profiles,
            'peak_memory_bytes' => memory_get_peak_usage(true),
        ];

        if ($json) {
            echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
            return 0;
        }

        printf(
            "Indexed %d documents in %.3f ms; iterations=%d warmup=%d\n",
            $documents,
            $build['index_ms'],
            $iterations,
            $warmup
        );
        printf("%-36s %-10s %7s %7s %9s %9s %9s %9s\n", 'profile', 'path', 'rows', 'reused', 'generated', 'avg_ms', 'p95_ms', 'checksum');
        foreach ($profiles as $name => $metrics) {
            printf(
                "%-36s %-10s %7d %7d %9d %9.3f %9.3f %9s\n",
                $name,
                $metrics['path'],
                $metrics['rows'],
                $metrics['snippets_reused'],
                $metrics['snippets_generated'],
                $metrics['avg_ms'],
                $metrics['p95_ms'],
                substr((string) $metrics['checksum'], 0, 8)
            );
        }

        return 0;
    }

    /**
     * @return array<string,array{path:string,query:string,opts:array<string,mixed>}>
     */
    private static function scenarios(): array
    {
        $base = [
            'lang' => 'en',
            'mode' => 'OR',
            'limit' => 10,
            'offset' => 0,
            'include_total' => true,
            'include_metadata' => true,
            'snippet_length' => 180,
            'prefix_min_length' => 3,
            'prefix_max_terms' => 64,
        ];
        $withSnippets = $base + [
            'include_snippets' => true,
            'highlight' => true,
            'prefix_matching' => false,
        ];
        $withoutSnippets = $base + [
            'include_snippets' => false,
            'highlight' => false,
            'prefix_matching' => false,
        ];
        $noHighlight = $withSnippets;
        $noHighlight['highlight'] = false;
        $noHighlightNoSnippets = $noHighlight;
        $noHighlightNoSnippets['include_snippets'] = false;
        $prefix = $withSnippets;
        $prefix['prefix_matching'] = true;

        return [
            'direct_no_snippets_exact' => [
                'path' => 'direct',
                'query' => 'frontdupneedle',
                'opts' => $withoutSnippets,
            ],
            'direct_include_snippets_exact' => [
                'path' => 'direct',
                'query' => 'frontdupneedle',
                'opts' => $withSnippets,
            ],
            'legacy_frontend_exact' => [
                'path' => 'legacy',
                'query' => 'frontdupneedle',
                'opts' => $withSnippets,
            ],
            'reuse_frontend_exact' => [
                'path' => 'reuse',
                'query' => 'frontdupneedle',
                'opts' => $withSnippets,
            ],
            'direct_include_snippets_no_highlight' => [
                'path' => 'direct',
                'query' => 'frontdupneedle',
                'opts' => $noHighlight,
            ],
            'legacy_frontend_no_highlight' => [
                'path' => 'legacy',
                'query' => 'frontdupneedle',
                'opts' => $noHighlight,
            ],
            'reuse_frontend_no_highlight' => [
                'path' => 'reuse',
                'query' => 'frontdupneedle',
                'opts' => $noHighlightNoSnippets,
            ],
            'direct_include_snippets_prefix' => [
                'path' => 'direct',
                'query' => 'frontdupneed',
                'opts' => $prefix,
            ],
            'legacy_frontend_prefix' => [
                'path' => 'legacy',
                'query' => 'frontdupneed',
                'opts' => $prefix,
            ],
            'reuse_frontend_prefix' => [
                'path' => 'reuse',
                'query' => 'frontdupneed',
                'opts' => $prefix,
            ],
        ];
    }

    /**
     * @return array{storage:WP_FTS_Storage_InMemory,analyzer:WP_FTS_Analyzer,posts:array<int,object>,index_ms:float}
     */
    private static function build_fixture(int $documents): array
    {
        $analyzer = new WP_FTS_Analyzer([
            'default_lang' => 'en',
            'enable_stemming' => false,
            'auto_detect_language' => false,
        ]);
        $storage = new WP_FTS_Storage_InMemory();
        $extractor = new WP_FTS_PostContentExtractor();
        $indexer = new WP_FTS_Indexer($storage, $analyzer, $extractor);
        $posts = [];

        $started = hrtime(true);
        for ($id = 1; $id <= $documents; $id++) {
            $tokens = [];
            for ($i = 0; $i < 72; $i++) {
                $tokens[] = 'context' . (($id + $i) % 19);
            }
            $insert = 10 + ($id % 37);
            array_splice(
                $tokens,
                $insert,
                0,
                ['<span class="fixture" data-drop="1">frontdup<em>needle</em></span>', 'sharedtopic' . ($id % 7)]
            );
            $post = (object) [
                'ID' => $id,
                'post_title' => sprintf('Frontend profile title %02d frontdupneedle', $id),
                'post_content' => '<article><p>' . implode(' ', $tokens) . '</p></article>',
                'post_excerpt' => 'Profile excerpt frontdupneedle ' . $id,
                'post_status' => 'publish',
                'post_type' => $id % 9 === 0 ? 'page' : 'post',
                'post_date_gmt' => sprintf('2026-06-%02d 12:00:00', ($id % 28) + 1),
            ];
            $posts[$id] = $post;
            $extracted = $extractor->extract($post, [
                'lang' => 'en',
                'metadata_text_limit' => 20000,
            ]);
            $indexer->index_document_fields($id, $extracted['fields'], [
                'lang' => 'en',
                'metadata' => $extracted['metadata'] + ['language' => 'en'],
                'field_boosts' => $extracted['field_boosts'],
            ]);
        }
        $storage->flush();

        return [
            'storage' => $storage,
            'analyzer' => $analyzer,
            'posts' => $posts,
            'index_ms' => (hrtime(true) - $started) / 1_000_000,
        ];
    }

    /**
     * @param array<int,object> $posts
     * @param array{path:string,query:string,opts:array<string,mixed>} $scenario
     * @return array<string,mixed>
     */
    private static function run_scenario(WP_FTS_Searcher $searcher, array $posts, array $scenario): array
    {
        $payload = $searcher->search($scenario['query'], $scenario['opts']);
        $rows = is_array($payload['results'] ?? null) ? $payload['results'] : [];
        if ($scenario['path'] === 'direct') {
            return [
                'rows' => count($rows),
                'snippets_reused' => 0,
                'snippets_generated' => 0,
                'title_snippets_generated' => 0,
                'signature' => self::signature($rows),
            ];
        }

        $snippets = [];
        $titles = [];
        $reused = 0;
        $generated = 0;
        foreach ($rows as $row) {
            $postId = (int) ($row['post_id'] ?? $row['doc_id'] ?? 0);
            $post = $posts[$postId] ?? null;
            if (!is_object($post)) {
                continue;
            }

            $snippet = '';
            if ($scenario['path'] === 'reuse') {
                $candidate = self::sanitize_snippet((string) ($row['snippet'] ?? ''));
                if ($candidate !== '' && self::snippet_matches_content($candidate, (string) $post->post_content)) {
                    $snippet = $candidate;
                    $reused++;
                }
            }
            if ($snippet === '') {
                $snippet = self::sanitize_snippet($searcher->snippet_for_text(
                    (string) $post->post_content,
                    $scenario['query'],
                    self::snippet_options($scenario['opts'], 180)
                ));
                $generated++;
            }

            $snippets[$postId] = $snippet;
            $titles[$postId] = self::sanitize_snippet($searcher->snippet_for_text(
                (string) $post->post_title,
                $scenario['query'],
                self::snippet_options($scenario['opts'], max(180, strlen((string) $post->post_title) + 1))
            ));
        }

        return [
            'rows' => count($rows),
            'snippets_reused' => $reused,
            'snippets_generated' => $generated,
            'title_snippets_generated' => count($titles),
            'signature' => [
                'rows' => self::signature($rows),
                'snippets' => $snippets,
                'titles' => $titles,
            ],
        ];
    }

    /**
     * @param array<string,mixed> $opts
     * @return array<string,mixed>
     */
    private static function snippet_options(array $opts, int $length): array
    {
        return [
            'lang' => 'en',
            'query_lang' => 'en',
            'result_lang' => 'en',
            'highlight' => (bool) ($opts['highlight'] ?? false),
            'snippet_length' => $length,
            'prefix_matching' => (bool) ($opts['prefix_matching'] ?? false),
            'prefix_min_length' => (int) ($opts['prefix_min_length'] ?? 3),
            'prefix_max_terms' => (int) ($opts['prefix_max_terms'] ?? 64),
        ];
    }

    /**
     * @param callable():array<string,mixed> $callback
     * @return array<string,mixed>
     */
    private static function measure(callable $callback, int $iterations, int $warmup): array
    {
        for ($i = 0; $i < $warmup; $i++) {
            $callback();
        }

        $durations = [];
        $last = [];
        $checksum = hash_init('sha256');
        for ($i = 0; $i < $iterations; $i++) {
            $started = hrtime(true);
            $last = $callback();
            $durations[] = (hrtime(true) - $started) / 1_000_000;
            hash_update($checksum, json_encode($last['signature'] ?? [], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        }

        sort($durations, SORT_NUMERIC);
        $p95Index = max(0, (int) ceil(count($durations) * 0.95) - 1);

        return [
            'rows' => (int) ($last['rows'] ?? 0),
            'snippets_reused' => (int) ($last['snippets_reused'] ?? 0),
            'snippets_generated' => (int) ($last['snippets_generated'] ?? 0),
            'title_snippets_generated' => (int) ($last['title_snippets_generated'] ?? 0),
            'avg_ms' => array_sum($durations) / max(1, count($durations)),
            'p95_ms' => $durations[$p95Index] ?? 0.0,
            'min_ms' => $durations[0] ?? 0.0,
            'max_ms' => $durations[count($durations) - 1] ?? 0.0,
            'checksum' => hash_final($checksum),
        ];
    }

    /**
     * @param array<int,array<string,mixed>> $rows
     * @return array<int,array<string,mixed>>
     */
    private static function signature(array $rows): array
    {
        return array_map(
            static fn(array $row): array => [
                'doc_id' => (int) ($row['doc_id'] ?? 0),
                'score' => round((float) ($row['score'] ?? 0.0), 9),
                'snippet' => isset($row['snippet']) ? (string) $row['snippet'] : '',
            ],
            $rows
        );
    }

    private static function sanitize_snippet(string $html): string
    {
        $html = preg_replace('/<!--.*?-->/s', '', $html) ?? $html;
        $html = preg_replace('/<\s*(script|style|noscript|template|iframe|object|embed|svg|math|canvas)\b[^>]*>.*?<\s*\/\s*\1\s*>/is', '', $html) ?? $html;
        $allowed = array_fill_keys(['a', 'abbr', 'b', 'br', 'cite', 'code', 'del', 'em', 'i', 'ins', 'kbd', 'mark', 's', 'small', 'span', 'strong', 'sub', 'sup', 'time'], true);
        $placeholders = [];
        $index = 0;
        $html = preg_replace_callback(
            '/<\s*(\/?)\s*([a-zA-Z][a-zA-Z0-9]*)\b[^>]*>/',
            static function (array $match) use (&$placeholders, &$index, $allowed): string {
                $tag = strtolower((string) $match[2]);
                if (!isset($allowed[$tag])) {
                    return '';
                }

                $placeholder = '@@WPFTS_BENCH_TAG_' . $index++ . '@@';
                $placeholders[$placeholder] = ((string) $match[1] === '/' ? '</' : '<') . $tag . '>';

                return $placeholder;
            },
            $html
        );
        $html = is_string($html) ? $html : '';

        return strtr(htmlspecialchars($html, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8', false), $placeholders);
    }

    private static function snippet_matches_content(string $snippet, string $content): bool
    {
        $needle = self::normalized_visible_text($snippet);
        $haystack = self::normalized_visible_text($content);

        return $needle !== '' && $haystack !== '' && str_contains($haystack, $needle);
    }

    private static function normalized_visible_text(string $html): string
    {
        $text = WP_FTS_Html_Text_Stream::visible_text($html);
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;
        $text = preg_replace('/^\.\.\.\s*/', '', $text) ?? $text;
        $text = preg_replace('/\s*\.\.\.$/', '', $text) ?? $text;

        return trim($text);
    }

    /**
     * @param string[] $argv
     */
    private static function int_option(array $argv, string $name, int $default): int
    {
        foreach ($argv as $index => $arg) {
            if ($arg === $name && isset($argv[$index + 1])) {
                return max(1, (int) $argv[$index + 1]);
            }
            if (str_starts_with($arg, $name . '=')) {
                return max(1, (int) substr($arg, strlen($name) + 1));
            }
        }

        return $default;
    }
}

exit(WP_FTS_Frontend_Snippet_Duplication_Profile::main($argv));
