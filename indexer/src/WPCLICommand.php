<?php
declare(strict_types=1);

/**
 * WP-CLI command surface for managing the custom FTS index.
 *
 * The command creates MySQL tables on demand, reindexes WordPress posts, searches
 * the index, tombstones documents, and compacts deleted rows.
 */
final class WP_FTS_WPCLI_Command
{
    private const DEFAULT_REINDEX_POST_STATUSES = ['publish', 'draft', 'pending', 'future', 'private'];

    /**
     * Register the `wp fts` command when WP-CLI is loaded.
     */
    public static function register(): void
    {
        if (class_exists('WP_CLI')) {
            WP_CLI::add_command('fts', self::class);
        }
    }

    /**
     * Reindex posts into the custom FTS tables.
     *
     * ## OPTIONS
     *
     * [--post_status=<status>]
     * : Comma-separated statuses. Default: publish,draft,pending,future,private. Use --post_status=publish for public-only backfills.
     *
     * [--post_type=<type>]
     * : Comma-separated post types. Default: post.
     *
     * [--lang=<language>]
     * : Force a language partition for indexed posts. Defaults to per-post/site language resolution.
     *
     * [--limit=<n>]
     * : Maximum posts to index. Default: unlimited.
     *
     * [--batch_size=<n>]
     * : Batch size. Default: 500.
     *
     * @param string[] $args Positional arguments; unused.
     * @param array<string,mixed> $assoc_args WP-CLI options. Dashed and
     *        underscored option names are both accepted for post status/type and
     *        batch size.
     */
    public function reindex(array $args, array $assoc_args): void
    {
        $langArg = $this->assoc_arg($assoc_args, ['lang', 'language'], null);
        $lang = $langArg !== null ? $this->language_arg($langArg) : null;
        $indexer = $this->indexer();
        $options = [
            'post_status' => $this->csv_arg(
                (string) $this->assoc_arg($assoc_args, ['post_status', 'post-status'], implode(',', self::DEFAULT_REINDEX_POST_STATUSES)),
                self::DEFAULT_REINDEX_POST_STATUSES
            ),
            'post_type' => $this->csv_arg((string) $this->assoc_arg($assoc_args, ['post_type', 'post-type'], 'post'), 'post'),
            'limit' => $this->non_negative_int_arg($this->assoc_arg($assoc_args, ['limit'], 0), 0),
            'batch_size' => $this->positive_int_arg($this->assoc_arg($assoc_args, ['batch_size', 'batch-size'], 500), 500),
        ];
        if ($lang !== null) {
            $options['lang'] = $lang;
        }

        $count = $this->reindex_posts($indexer, $options);

        WP_CLI::success($lang !== null ? "Indexed {$count} posts in {$lang}." : "Indexed {$count} posts.");
    }

    /**
     * Search the FTS index.
     *
     * ## OPTIONS
     *
     * <query>
     * : Search query.
     *
     * [--mode=<OR|AND>]
     * : Boolean mode. Default: OR.
     *
     * [--limit=<n>]
     * : Result count. Default: 10.
     *
     * [--lang=<language>]
     * : Query language partition. Defaults to site locale.
     *
     * [--post_status=<status>]
     * : Comma-separated post statuses to include when document metadata is available.
     *
     * [--post_type=<type>]
     * : Comma-separated post types to include when document metadata is available.
     *
     * [--after=<date>]
     * : Include posts on or after a GMT date or datetime.
     *
     * [--before=<date>]
     * : Include posts on or before a GMT date or datetime.
     *
     * [--recency_boost=<strength>]
     * : Add a bounded query-time ranking lift for newer indexed post dates. Use 0 to disable.
     *
     * [--recency_boost_half_life_days=<days>]
     * : Positive half-life in days for the recency lift. Default: searcher default.
     *
     * [--prefix_matching]
     * : Enable word-beginning matching for this CLI search.
     *
     * [--prefix_min_length=<n>]
     * : Minimum analyzed term length before word-beginning expansion. Alias: --prefix-min-length.
     *
     * [--prefix_max_terms=<n>]
     * : Maximum stored terms added per analyzed query candidate. Alias: --prefix-max-terms.
     *
     * [--offset=<n>]
     * : Offset into filtered results for pagination. Default: 0.
     *
     * [--snippet]
     * : Include snippets from bounded extracted text.
     *
     * @param string[] $args First positional argument is the query string.
     * @param array<string,mixed> $assoc_args Options for mode, limit, and
     *        language. Missing language lets the analyzer resolve or detect the
     *        query language.
     */
    public function search(array $args, array $assoc_args): void
    {
        $query = (string) ($args[0] ?? '');
        $searcher = new WP_FTS_Searcher($this->storage(), WP_FTS_Plugin::runtime_analyzer());
        $searchOptions = [
            'mode' => (string) ($assoc_args['mode'] ?? 'OR'),
            'limit' => $this->positive_int_arg($this->assoc_arg($assoc_args, ['limit'], 10), 10),
            'offset' => $this->non_negative_int_arg($this->assoc_arg($assoc_args, ['offset'], 0), 0),
            'include_total' => true,
            'include_metadata' => true,
            'include_snippets' => array_key_exists('snippet', $assoc_args) || array_key_exists('snippets', $assoc_args),
        ];
        $langArg = $this->assoc_arg($assoc_args, ['lang', 'language'], null);
        if ($langArg !== null) {
            $searchOptions['lang'] = $this->language_arg($langArg);
        }

        $postStatus = $this->assoc_arg($assoc_args, ['post_status', 'post-status'], null);
        if ($postStatus !== null) {
            $searchOptions['post_status'] = $this->csv_arg((string) $postStatus, '');
        }
        $postType = $this->assoc_arg($assoc_args, ['post_type', 'post-type'], null);
        if ($postType !== null) {
            $searchOptions['post_type'] = $this->csv_arg((string) $postType, '');
        }
        $after = $this->assoc_arg($assoc_args, ['after', 'date_after', 'date-after'], null);
        if ($after !== null) {
            $searchOptions['date_after'] = (string) $after;
        }
        $before = $this->assoc_arg($assoc_args, ['before', 'date_before', 'date-before'], null);
        if ($before !== null) {
            $searchOptions['date_before'] = (string) $before;
        }
        $recencyBoost = $this->assoc_arg($assoc_args, ['recency_boost', 'recency-boost', 'freshness_boost', 'freshness-boost'], null);
        if ($recencyBoost !== null) {
            $searchOptions['recency_boost'] = $recencyBoost;
        }
        $recencyStrength = $this->assoc_arg($assoc_args, ['recency_boost_strength', 'recency-boost-strength', 'freshness_boost_strength', 'freshness-boost-strength'], null);
        if ($recencyStrength !== null) {
            $searchOptions['recency_boost_strength'] = $recencyStrength;
        }
        $recencyHalfLife = $this->assoc_arg($assoc_args, ['recency_boost_half_life_days', 'recency-boost-half-life-days', 'freshness_boost_half_life_days', 'freshness-boost-half-life-days'], null);
        if ($recencyHalfLife !== null) {
            $searchOptions['recency_boost_half_life_days'] = $recencyHalfLife;
        }
        $prefixMatching = $this->assoc_arg($assoc_args, ['prefix_matching', 'prefix-matching'], null);
        if ($prefixMatching !== null) {
            $searchOptions['prefix_matching'] = $this->truthy_cli_value($prefixMatching);
        }
        $prefixMinLength = $this->assoc_arg($assoc_args, ['prefix_min_length', 'prefix-min-length'], null);
        if ($prefixMinLength !== null) {
            $searchOptions['prefix_min_length'] = WP_FTS_Plugin::sanitize_prefix_min_length($prefixMinLength);
        }
        $prefixMaxTerms = $this->assoc_arg($assoc_args, ['prefix_max_terms', 'prefix-max-terms'], null);
        if ($prefixMaxTerms !== null) {
            $searchOptions['prefix_max_terms'] = WP_FTS_Plugin::sanitize_prefix_max_terms($prefixMaxTerms);
        }

        /** @var array{total:int,results:array<int,array<string,mixed>>} $payload */
        $payload = $searcher->search($query, $searchOptions);
        $results = $payload['results'];
        foreach ($results as &$row) {
            $row['total'] = $payload['total'];
            foreach (['post_id', 'post_type', 'post_status', 'post_date_gmt', 'title'] as $field) {
                $row[$field] ??= $field === 'post_id' ? 0 : '';
            }
            if ($searchOptions['include_snippets']) {
                $row['snippet'] ??= '';
            }
        }
        unset($row);

        $fields = ['doc_id', 'score', 'total', 'post_id', 'post_type', 'post_status', 'post_date_gmt', 'title'];
        if ($searchOptions['include_snippets']) {
            $fields[] = 'snippet';
        }

        $this->format_items('table', $results, $fields);
    }

    /**
     * Show read-only indexing lifecycle status.
     *
     * ## OPTIONS
     *
     * [--format=<format>]
     * : Output format. Default: table. Supports json for automation.
     *
     * @param string[] $args Positional arguments; unused.
     * @param array<string,mixed> $assoc_args WP-CLI options.
     */
    public function status(array $args, array $assoc_args): void
    {
        $this->output_assoc(WP_FTS_Plugin::operator_status(), $assoc_args);
    }

    /**
     * Repair the FTS schema without indexing content.
     *
     * ## OPTIONS
     *
     * [--format=<format>]
     * : Output format. Default: table. Supports json for automation.
     *
     * @param string[] $args Positional arguments; unused.
     * @param array<string,mixed> $assoc_args WP-CLI options.
     */
    public function repair(array $args, array $assoc_args): void
    {
        $schema = WP_FTS_Plugin::repair_schema();
        $this->output_assoc([
            'schema_status' => $schema['status'],
            'schema_version' => $schema['stored_version'],
            'expected_schema_version' => $schema['expected_version'],
        ], $assoc_args);
    }

    /**
     * Run one bounded manual indexing batch.
     *
     * ## OPTIONS
     *
     * [--batch_size=<n>]
     * : Maximum posts to process in this command. Default: plugin manual batch setting.
     *
     * [--time_budget=<seconds>]
     * : Time budget for this batch. Default: plugin manual time-budget setting.
     *
     * [--format=<format>]
     * : Output format. Default: table. Supports json for automation.
     *
     * @param string[] $args Positional arguments; unused.
     * @param array<string,mixed> $assoc_args WP-CLI options.
     */
    public function process_batch(array $args, array $assoc_args): void
    {
        $options = ['source' => 'wp-cli'];
        $batchSize = $this->assoc_arg($assoc_args, ['batch_size', 'batch-size'], null);
        if ($batchSize !== null) {
            $options['batch_size'] = $this->positive_int_arg($batchSize, WP_FTS_Plugin::DEFAULT_MANUAL_INDEX_BATCH_SIZE);
        }

        $timeBudget = $this->assoc_arg($assoc_args, ['time_budget', 'time-budget'], null);
        if ($timeBudget !== null) {
            $options['time_budget'] = $this->non_negative_float_arg($timeBudget, 0.0);
        }

        $summary = WP_FTS_Plugin::process_manual_index_batch($options);
        $health = WP_FTS_Plugin::search_health();
        $this->output_assoc([
            'mode' => is_scalar($summary['mode'] ?? null) ? (string) $summary['mode'] : 'manual',
            'batch_size' => max(0, (int) ($summary['batch_size'] ?? 0)),
            'processed' => max(0, (int) ($summary['processed'] ?? 0)),
            'queue_processed' => max(0, (int) ($summary['queue_processed'] ?? 0)),
            'backfill_processed' => max(0, (int) ($summary['backfill_processed'] ?? 0)),
            'skipped_locked' => (bool) ($summary['skipped_locked'] ?? false),
            'stopped_by_budget' => (bool) ($summary['stopped_by_budget'] ?? false),
            'has_more' => (bool) ($health['has_more'] ?? $summary['has_more'] ?? false),
            'pending_queue_count' => max(0, (int) ($health['pending_queue_count'] ?? 0)),
            'last_indexed_post_id' => max(0, (int) ($summary['last_indexed_post_id'] ?? 0)),
            'last_indexed_post_title' => is_scalar($summary['last_indexed_post_title'] ?? null) ? (string) $summary['last_indexed_post_title'] : '',
            'last_indexed_at' => is_scalar($summary['last_indexed_at'] ?? null) ? (string) $summary['last_indexed_at'] : '',
            'last_batch_failures' => max(0, (int) ($summary['last_batch_failures'] ?? 0)),
            'last_failed_post_id' => max(0, (int) ($summary['last_failed_post_id'] ?? 0)),
            'last_failed_post_title' => is_scalar($summary['last_failed_post_title'] ?? null) ? (string) $summary['last_failed_post_title'] : '',
            'last_failed_at' => is_scalar($summary['last_failed_at'] ?? null) ? (string) $summary['last_failed_at'] : '',
            'last_error' => is_scalar($summary['last_error'] ?? null) ? (string) $summary['last_error'] : '',
        ], $assoc_args);
    }

    /**
     * Tombstone a document.
     *
     * ## OPTIONS
     *
     * <doc_id>
     * : Document ID to delete.
     *
     * @param string[] $args First positional argument is the document id.
     * @param array<string,mixed> $assoc_args Unused WP-CLI options.
     */
    public function delete(array $args, array $assoc_args): void
    {
        $docId = (int) ($args[0] ?? 0);
        $deleted = $this->indexer()->delete_document($docId);
        if ($deleted) {
            WP_CLI::success("Deleted document {$docId}.");
            return;
        }

        WP_CLI::warning("Document {$docId} was not indexed.");
    }

    /**
     * Compact tombstones out of posting lists.
     *
     * @param string[] $args Positional arguments; unused.
     * @param array<string,mixed> $assoc_args Unused WP-CLI options.
     */
    public function optimize(array $args, array $assoc_args): void
    {
        $this->indexer()->optimize();
        WP_CLI::success('Optimized FTS index.');
    }

    /**
     * Import a normalized source-backed lemma TSV into a local analyzer pack.
     *
     * ## OPTIONS
     *
     * --source=<path>
     * : Normalized lemma TSV source file. Each row is surface<TAB>lemma with optional tag/source-note columns.
     *
     * --language=<language>
     * : Language tag for the generated pack. Alias: --lang.
     *
     * --pack-id=<id>
     * : Stable analyzer pack id.
     *
     * --version=<version>
     * : Generated pack version.
     *
     * --source-name=<name>
     * : Human-readable source name.
     *
     * --source-url=<url>
     * : Reviewed upstream or artifact source URL.
     *
     * --license=<spdx>
     * : Source license identifier.
     *
     * [--license-url=<url>]
     * : Optional license URL.
     *
     * [--source-version=<version>]
     * : Optional upstream source version. Defaults to --version.
     *
     * [--attribution=<text>]
     * : Optional attribution text. Defaults to --source-name.
     *
     * [--tmp-dir=<path>]
     * : Optional temporary parent directory for importer-owned chunks.
     *
     * [--max-rows-per-file=<n>]
     * : Maximum runtime rows per generated shard.
     *
     * [--chunk-rows=<n>]
     * : Number of deduplicated source pairs to sort per temporary chunk.
     *
     * [--fixture-only]
     * : Mark the generated pack as a test fixture only.
     *
     * [--out=<path>]
     * : Output pack directory. Alias: --output-dir. Defaults under uploads/wp-fts-lemma-packs/<pack-id>.
     *
     * [--enable]
     * : Enable the generated manifest for runtime indexing/search. Reindex existing content afterwards.
     *
     * @param string[] $args Positional arguments; unused.
     * @param array<string,mixed> $assoc_args WP-CLI options.
     */
    public function import_lemma_pack(array $args, array $assoc_args): void
    {
        require_once dirname(__DIR__) . '/tools/import-lemma-tsv-pack.php';

        $enable = $this->bool_flag_arg($assoc_args, ['enable'], false);
        $options = $this->lemma_pack_import_options($assoc_args);
        $summary = (new WP_FTS_LemmaTsvPackImporter())->import($options);
        $manifestPath = isset($summary['manifest']) && is_scalar($summary['manifest'])
            ? (string) $summary['manifest']
            : '';
        if ($manifestPath === '') {
            throw new RuntimeException('Lemma pack importer did not return a manifest path.');
        }

        $validation = (new WP_FTS_AnalyzerPackValidator())->validate($manifestPath, false);
        $manifest = $validation['manifest'];
        $language = (string) ($manifest['language'] ?? $summary['language'] ?? $options['language']);
        $packId = (string) ($manifest['pack_id'] ?? $summary['pack_id'] ?? $options['pack_id']);

        if ($enable) {
            WP_FTS_Plugin::set_runtime_lemma_pack_option($language, $manifestPath);
            WP_CLI::success("Imported and enabled lemma pack {$packId} for {$language}: {$manifestPath}. Reindex existing content for the pack to affect stored terms.");
            return;
        }

        WP_CLI::success("Imported lemma pack {$packId} for {$language}: {$manifestPath}. Runtime analyzer options were not changed.");
    }

    /**
     * Import CoNLL-U FORM/LEMMA rows into a local analyzer pack.
     *
     * ## OPTIONS
     *
     * --source=<path>
     * : CoNLL-U source file or directory. Directories are scanned recursively for .conllu files.
     *
     * --language=<language>
     * : Language tag for the generated pack. Alias: --lang.
     *
     * --pack-id=<id>
     * : Stable analyzer pack id.
     *
     * --version=<version>
     * : Generated pack version.
     *
     * --source-name=<name>
     * : Human-readable source name.
     *
     * --source-url=<url>
     * : Reviewed upstream or artifact source URL.
     *
     * --license=<spdx>
     * : Source license identifier.
     *
     * [--license-url=<url>]
     * : Optional license URL.
     *
     * [--source-version=<version>]
     * : Optional upstream source version. Defaults to --version.
     *
     * [--attribution=<text>]
     * : Optional attribution text. Defaults to --source-name.
     *
     * [--tmp-dir=<path>]
     * : Optional temporary parent directory for importer-owned conversion and chunk files.
     *
     * [--max-rows-per-file=<n>]
     * : Maximum runtime rows per generated shard.
     *
     * [--chunk-rows=<n>]
     * : Number of deduplicated source pairs to sort per temporary chunk.
     *
     * [--fixture-only]
     * : Mark the generated pack as a test fixture only.
     *
     * [--out=<path>]
     * : Output pack directory. Alias: --output-dir. Defaults under uploads/wp-fts-lemma-packs/<pack-id>.
     *
     * [--enable]
     * : Enable the generated manifest for runtime indexing/search. Reindex existing content afterwards.
     *
     * @param string[] $args Positional arguments; unused.
     * @param array<string,mixed> $assoc_args WP-CLI options.
     */
    public function import_conllu_lemma_pack(array $args, array $assoc_args): void
    {
        require_once dirname(__DIR__) . '/tools/import-conllu-lemma-pack.php';

        $enable = $this->bool_flag_arg($assoc_args, ['enable'], false);
        $options = $this->lemma_pack_import_options($assoc_args);
        $summary = (new WP_FTS_ConlluLemmaPackImporter())->import($options);
        $manifestPath = isset($summary['manifest']) && is_scalar($summary['manifest'])
            ? (string) $summary['manifest']
            : '';
        if ($manifestPath === '') {
            throw new RuntimeException('CoNLL-U lemma pack importer did not return a manifest path.');
        }

        $validation = (new WP_FTS_AnalyzerPackValidator())->validate($manifestPath, false);
        $manifest = $validation['manifest'];
        $language = (string) ($manifest['language'] ?? $summary['language'] ?? $options['language']);
        $packId = (string) ($manifest['pack_id'] ?? $summary['pack_id'] ?? $options['pack_id']);

        if ($enable) {
            WP_FTS_Plugin::set_runtime_lemma_pack_option($language, $manifestPath);
            WP_CLI::success("Imported and enabled CoNLL-U lemma pack {$packId} for {$language}: {$manifestPath}. Reindex existing content for the pack to affect stored terms.");
            return;
        }

        WP_CLI::success("Imported CoNLL-U lemma pack {$packId} for {$language}: {$manifestPath}. Runtime analyzer options were not changed.");
    }

    /**
     * Import UniMorph-style lemma/surface/feature rows into a local analyzer pack.
     *
     * ## OPTIONS
     *
     * --source=<path>
     * : UniMorph-style source file or directory. Directories are scanned recursively for .txt, .tsv, and .unimorph files.
     *
     * --language=<language>
     * : Language tag for the generated pack. Alias: --lang.
     *
     * --pack-id=<id>
     * : Stable analyzer pack id.
     *
     * --version=<version>
     * : Generated pack version.
     *
     * --source-name=<name>
     * : Human-readable source name.
     *
     * --source-url=<url>
     * : Reviewed upstream or artifact source URL.
     *
     * --license=<spdx>
     * : Source license identifier.
     *
     * [--license-url=<url>]
     * : Optional license URL.
     *
     * [--source-version=<version>]
     * : Optional upstream source version. Defaults to --version.
     *
     * [--attribution=<text>]
     * : Optional attribution text. Defaults to --source-name.
     *
     * [--tmp-dir=<path>]
     * : Optional temporary parent directory for importer-owned conversion and chunk files.
     *
     * [--max-rows-per-file=<n>]
     * : Maximum runtime rows per generated shard.
     *
     * [--chunk-rows=<n>]
     * : Number of deduplicated source pairs to sort per temporary chunk.
     *
     * [--fixture-only]
     * : Mark the generated pack as a test fixture only.
     *
     * [--out=<path>]
     * : Output pack directory. Alias: --output-dir. Defaults under uploads/wp-fts-lemma-packs/<pack-id>.
     *
     * [--enable]
     * : Enable the generated manifest for runtime indexing/search. Reindex existing content afterwards.
     *
     * @param string[] $args Positional arguments; unused.
     * @param array<string,mixed> $assoc_args WP-CLI options.
     */
    public function import_unimorph_lemma_pack(array $args, array $assoc_args): void
    {
        require_once dirname(__DIR__) . '/tools/import-unimorph-lemma-pack.php';

        $enable = $this->bool_flag_arg($assoc_args, ['enable'], false);
        $options = $this->lemma_pack_import_options($assoc_args);
        $summary = (new WP_FTS_UnimorphLemmaPackImporter())->import($options);
        $manifestPath = isset($summary['manifest']) && is_scalar($summary['manifest'])
            ? (string) $summary['manifest']
            : '';
        if ($manifestPath === '') {
            throw new RuntimeException('UniMorph lemma pack importer did not return a manifest path.');
        }

        $validation = (new WP_FTS_AnalyzerPackValidator())->validate($manifestPath, false);
        $manifest = $validation['manifest'];
        $language = (string) ($manifest['language'] ?? $summary['language'] ?? $options['language']);
        $packId = (string) ($manifest['pack_id'] ?? $summary['pack_id'] ?? $options['pack_id']);

        if ($enable) {
            WP_FTS_Plugin::set_runtime_lemma_pack_option($language, $manifestPath);
            WP_CLI::success("Imported and enabled UniMorph lemma pack {$packId} for {$language}: {$manifestPath}. Reindex existing content for the pack to affect stored terms.");
            return;
        }

        WP_CLI::success("Imported UniMorph lemma pack {$packId} for {$language}: {$manifestPath}. Runtime analyzer options were not changed.");
    }

    /**
     * Emit an associative summary as JSON or as a human-readable field list.
     *
     * @param array<string,mixed> $data
     * @param array<string,mixed> $assoc_args
     */
    private function output_assoc(array $data, array $assoc_args): void
    {
        $format = (string) $this->assoc_arg($assoc_args, ['format'], 'table');
        if ($format === 'json') {
            $this->line($this->json_payload($data));
            return;
        }

        $rows = [];
        foreach ($data as $field => $value) {
            $rows[] = [
                'field' => (string) $field,
                'value' => $this->format_cli_value($value),
            ];
        }

        $this->format_items($format, $rows, ['field', 'value'], false);
    }

    /**
     * Format rows through WP-CLI when available, with a small harness fallback.
     *
     * @param array<int,array<string,mixed>> $items
     * @param string[] $fields
     */
    private function format_items(string $format, array $items, array $fields, bool $allow_harness_formatter = true): void
    {
        if (
            function_exists('WP_CLI\\Utils\\format_items')
            && ($allow_harness_formatter || is_callable(['WP_CLI', 'line']))
        ) {
            WP_CLI\Utils\format_items($format, $items, $fields);
            return;
        }

        if ($format === 'json') {
            $this->line($this->json_payload($items));
            return;
        }

        $this->line(implode("\t", $fields));
        foreach ($items as $item) {
            $values = [];
            foreach ($fields as $field) {
                $values[] = $this->format_cli_value($item[$field] ?? '');
            }
            $this->line(implode("\t", $values));
        }
    }

    private function line(string $message): void
    {
        if (class_exists('WP_CLI') && is_callable(['WP_CLI', 'line'])) {
            WP_CLI::line($message);
            return;
        }

        echo $message . PHP_EOL;
    }

    /**
     * @param array<mixed> $payload
     */
    private function json_payload(array $payload): string
    {
        $json = function_exists('wp_json_encode')
            ? wp_json_encode($payload, JSON_UNESCAPED_SLASHES)
            : json_encode($payload, JSON_UNESCAPED_SLASHES);

        return is_string($json) ? $json : '{}';
    }

    private function format_cli_value(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? 'yes' : 'no';
        }
        if ($value === null) {
            return '';
        }
        if (is_scalar($value)) {
            return (string) $value;
        }
        if (is_array($value)) {
            return $this->json_payload($value);
        }

        return '';
    }

    /**
     * Build an indexer wired to MySQL storage and the plugin runtime analyzer.
     */
    private function indexer(): WP_FTS_Indexer
    {
        return new WP_FTS_Indexer(
            $this->storage(),
            WP_FTS_Plugin::runtime_analyzer(),
            new WP_FTS_PostContentExtractor()
        );
    }

    /**
     * Reindex WordPress posts in ascending ID batches.
     *
     * @param array{post_status:string[],post_type:string[],batch_size:int,limit:int,lang?:string} $options
     */
    private function reindex_posts(WP_FTS_Indexer $indexer, array $options): int
    {
        global $wpdb;

        if (!isset($wpdb) || !is_object($wpdb)) {
            throw new RuntimeException('WP-CLI reindex requires $wpdb.');
        }

        $postStatuses = $this->non_empty_string_list($options['post_status'] ?? self::DEFAULT_REINDEX_POST_STATUSES, 'post_status');
        $postTypes = $this->non_empty_string_list($options['post_type'] ?? ['post'], 'post_type');
        $batchSize = max(1, (int) ($options['batch_size'] ?? 500));
        $limit = max(0, (int) ($options['limit'] ?? 0));
        $last = 0;
        $count = 0;

        do {
            $currentBatchSize = $limit > 0 ? min($batchSize, $limit - $count) : $batchSize;
            if ($currentBatchSize <= 0) {
                break;
            }

            $statusPlaceholders = implode(',', array_fill(0, count($postStatuses), '%s'));
            $typePlaceholders = implode(',', array_fill(0, count($postTypes), '%s'));
            $args = array_merge($postStatuses, $postTypes, [$last, $currentBatchSize]);

            $sql = $wpdb->prepare(
                "SELECT ID, post_content, post_title, post_excerpt, post_type, post_status, post_date_gmt, post_date
FROM {$wpdb->posts}
WHERE post_status IN ({$statusPlaceholders})
  AND post_type IN ({$typePlaceholders})
  AND ID > %d
ORDER BY ID ASC
LIMIT %d",
                ...$args
            );

            $rows = $wpdb->get_results($sql);
            foreach ($rows ?: [] as $row) {
                $last = (int) $row->ID;
                $indexer->index_post($row, WP_FTS_Plugin::prepare_post_index_options($row, $options));
                $count++;
            }
        } while (!empty($rows) && ($limit === 0 || $count < $limit));

        $indexer->flush();

        return $count;
    }

    /**
     * Create MySQL storage and ensure required tables exist.
     *
     * @return WP_FTS_Storage_Mysql Ready-to-use storage backend.
     * @throws RuntimeException When `$wpdb` is unavailable.
     */
    private function storage(): WP_FTS_Storage_Mysql
    {
        global $wpdb;

        if (!isset($wpdb) || !is_object($wpdb)) {
            throw new RuntimeException('WP-CLI command requires $wpdb.');
        }

        return WP_FTS_Plugin::storage(true);
    }

    /**
     * Parse a comma-separated WP-CLI option into a non-empty list.
     *
     * Empty input falls back to a single default item.
     *
     * @return string[]
     */
    private function csv_arg(string $value, string|array $fallback): array
    {
        $items = array_map('trim', explode(',', $value));
        $items = array_values(array_filter($items, static fn(string $item): bool => $item !== ''));

        return $items === [] ? (is_array($fallback) ? $fallback : [$fallback]) : $items;
    }

    /**
     * Normalize an option list that must not be empty.
     *
     * @param mixed $value
     * @return string[]
     */
    private function non_empty_string_list(mixed $value, string $name): array
    {
        $items = [];
        foreach (is_array($value) ? $value : [$value] as $item) {
            foreach (explode(',', (string) $item) as $part) {
                $part = trim($part);
                if ($part !== '') {
                    $items[] = $part;
                }
            }
        }

        $items = array_values(array_unique($items));
        if ($items === []) {
            throw new InvalidArgumentException("{$name} must contain at least one value.");
        }

        return $items;
    }

    /**
     * Return the first present associated argument from a list of accepted names.
     *
     * This lets commands accept both WP-CLI's dashed names and PHP-friendly
     * underscored names.
     *
     * @param array<string,mixed> $assoc_args
     * @param string[] $names
     * @param mixed $default Value returned when none of the names is present.
     * @return mixed Matched value or `$default`.
     */
    private function assoc_arg(array $assoc_args, array $names, mixed $default): mixed
    {
        foreach ($names as $name) {
            if (array_key_exists($name, $assoc_args)) {
                return $assoc_args[$name];
            }
        }

        return $default;
    }

    /**
     * Build importer options from WP-CLI aliases without letting absent --out
     * leak into the lower-level importer as a missing required argument.
     *
     * @param array<string,mixed> $assoc_args
     * @return array<string,mixed>
     */
    private function lemma_pack_import_options(array $assoc_args): array
    {
        $packId = $this->required_assoc_string($assoc_args, ['pack-id', 'pack_id'], 'pack-id');
        $sourceName = $this->required_assoc_string($assoc_args, ['source-name', 'source_name'], 'source-name');

        $options = [
            'source' => $this->required_assoc_string($assoc_args, ['source'], 'source'),
            'language' => $this->required_assoc_string($assoc_args, ['language', 'lang'], 'language'),
            'pack_id' => $packId,
            'version' => $this->required_assoc_string($assoc_args, ['version'], 'version'),
            'source_name' => $sourceName,
            'source_url' => $this->required_assoc_string($assoc_args, ['source-url', 'source_url'], 'source-url'),
            'license' => $this->required_assoc_string($assoc_args, ['license'], 'license'),
            'attribution' => $this->optional_assoc_string($assoc_args, ['attribution'], $sourceName),
        ];

        $out = $this->assoc_arg($assoc_args, ['out', 'output-dir', 'output_dir'], null);
        $options['out'] = $out !== null && is_scalar($out) && trim((string) $out) !== ''
            ? (string) $out
            : $this->default_lemma_pack_output_dir($packId);

        foreach ([
            'license_url' => ['license-url', 'license_url'],
            'source_version' => ['source-version', 'source_version'],
            'tmp_dir' => ['tmp-dir', 'tmp_dir'],
            'max_rows_per_file' => ['max-rows-per-file', 'max_rows_per_file'],
            'chunk_rows' => ['chunk-rows', 'chunk_rows'],
            'fixture_only' => ['fixture-only', 'fixture_only'],
        ] as $importerKey => $cliNames) {
            $value = $this->assoc_arg($assoc_args, $cliNames, null);
            if ($value !== null) {
                $options[$importerKey] = $value;
            }
        }

        return $options;
    }

    /**
     * @param array<string,mixed> $assoc_args
     * @param string[] $names
     */
    private function required_assoc_string(array $assoc_args, array $names, string $displayName): string
    {
        $value = $this->assoc_arg($assoc_args, $names, null);
        if (!is_scalar($value) || trim((string) $value) === '') {
            throw new RuntimeException("Missing required option --{$displayName}.");
        }

        return (string) $value;
    }

    /**
     * @param array<string,mixed> $assoc_args
     * @param string[] $names
     */
    private function optional_assoc_string(array $assoc_args, array $names, string $default): string
    {
        $value = $this->assoc_arg($assoc_args, $names, null);
        if (!is_scalar($value) || trim((string) $value) === '') {
            return $default;
        }

        return (string) $value;
    }

    /**
     * Resolve a boolean WP-CLI flag, accepting explicit false-like values for tests.
     *
     * @param array<string,mixed> $assoc_args
     * @param string[] $names
     */
    private function bool_flag_arg(array $assoc_args, array $names, bool $default): bool
    {
        $value = $this->assoc_arg($assoc_args, $names, null);
        if ($value === null) {
            return $default;
        }
        if (is_bool($value)) {
            return $value;
        }
        if (is_scalar($value)) {
            return $this->truthy_cli_value($value);
        }

        return $default;
    }

    private function truthy_cli_value(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (!is_scalar($value)) {
            return false;
        }

        return in_array(strtolower(trim((string) $value)), ['1', 'true', 'yes', 'on'], true);
    }

    private function default_lemma_pack_output_dir(string $packId): string
    {
        $baseDir = '';
        if (function_exists('wp_upload_dir')) {
            $uploads = wp_upload_dir(null, true, false);
            if (is_array($uploads)) {
                $error = $uploads['error'] ?? false;
                if (is_string($error) && trim($error) !== '') {
                    throw new RuntimeException('Could not resolve WordPress uploads directory: ' . $error);
                }
                if (isset($uploads['basedir']) && is_scalar($uploads['basedir']) && trim((string) $uploads['basedir']) !== '') {
                    $baseDir = (string) $uploads['basedir'];
                }
            }
        }
        if ($baseDir === '') {
            $baseDir = sys_get_temp_dir();
        }

        return rtrim($baseDir, '/\\') . DIRECTORY_SEPARATOR . 'wp-fts-lemma-packs' . DIRECTORY_SEPARATOR . $this->lemma_pack_directory_name($packId);
    }

    private function lemma_pack_directory_name(string $packId): string
    {
        $directory = preg_replace('/[^A-Za-z0-9._-]+/', '-', $packId);
        $directory = is_string($directory) ? trim($directory, '.-_') : '';
        if ($directory === '') {
            throw new RuntimeException('Pack id must contain at least one filesystem-safe character for the default output directory.');
        }

        return $directory;
    }

    /**
     * Resolve and canonicalize a CLI language option.
     *
     * A scalar non-empty option wins. Otherwise the command falls back to
     * WordPress site language and finally the storage default `und`.
     *
     * @param mixed $value Raw WP-CLI option value.
     * @return string Canonical language partition.
     */
    private function language_arg(mixed $value): string
    {
        if (is_scalar($value) && trim((string) $value) !== '') {
            return WP_FTS_TermNamespace::canonicalize_lang((string) $value);
        }

        if (function_exists('get_locale')) {
            $locale = get_locale();
            if (is_string($locale) && $locale !== '') {
                return WP_FTS_TermNamespace::canonicalize_lang($locale);
            }
        }

        if (function_exists('get_bloginfo')) {
            $siteLang = get_bloginfo('language');
            if (is_string($siteLang) && $siteLang !== '') {
                return WP_FTS_TermNamespace::canonicalize_lang($siteLang);
            }
        }

        return WP_FTS_TermNamespace::DEFAULT_LANG;
    }

    /**
     * Parse an integer option and clamp it to at least one.
     */
    private function positive_int_arg(mixed $value, int $fallback): int
    {
        $number = is_numeric($value) ? (int) $value : $fallback;
        return max(1, $number);
    }

    /**
     * Parse an integer option and clamp it to zero or greater.
     */
    private function non_negative_int_arg(mixed $value, int $fallback): int
    {
        $number = is_numeric($value) ? (int) $value : $fallback;
        return max(0, $number);
    }

    /**
     * Parse a floating-point option and clamp it to zero or greater.
     */
    private function non_negative_float_arg(mixed $value, float $fallback): float
    {
        $number = is_numeric($value) ? (float) $value : $fallback;
        return max(0.0, $number);
    }
}
