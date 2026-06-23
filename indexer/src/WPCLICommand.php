<?php
declare(strict_types=1);

/**
 * WP-CLI command surface for managing the custom FTS index.
 *
 * The command creates MySQL tables on demand, reindexes WordPress posts, searches
 * the index, schedules background queue recovery, tombstones documents, and
 * compacts deleted rows.
 */
final class WP_FTS_WPCLI_Command
{
    private const DEFAULT_REINDEX_POST_STATUSES = ['publish', 'draft', 'pending', 'future', 'private'];
    private const EXPLAIN_SUMMARY_MAX_ITEMS = 5;
    private const EXPLAIN_SUMMARY_MAX_BYTES = 800;
    private const DIAGNOSTIC_BUNDLE_SCHEMA = 'wp-fts-query-diagnostic-bundle-v1';
    private const DIAGNOSTIC_QUERY_MAX_BYTES = 512;
    private const DIAGNOSTIC_SUMMARY_MAX_BYTES = 240;

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

        $locked = WP_FTS_Plugin::run_index_writer_with_lock(
            'wp-cli-reindex',
            function () use ($options): int {
                return $this->reindex_posts($this->indexer(), $options);
            },
            ['batch_size' => $options['batch_size']]
        );
        if (empty($locked['acquired'])) {
            $this->warn_index_writer_locked('reindex');
            return;
        }

        $count = max(0, (int) ($locked['result'] ?? 0));

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
     * [--format=<format>]
     * : Output format. Default: table. Supports json for automation.
     *
     * [--explain]
     * : Include bounded read-only search diagnostics. JSON output includes the structured explain payload; table output appends a concise summary.
     *
     * @param string[] $args First positional argument is the query string.
     * @param array<string,mixed> $assoc_args Options for mode, limit, and
     *        language. Missing language lets the analyzer resolve or detect the
     *        query language.
     */
    public function search(array $args, array $assoc_args): void
    {
        $query = (string) ($args[0] ?? '');
        $format = (string) $this->assoc_arg($assoc_args, ['format'], 'table');
        $searcher = new WP_FTS_Searcher($this->storage(), WP_FTS_Plugin::runtime_analyzer());
        $searchOptions = $this->search_options_from_cli_args($assoc_args);
        $explain = !empty($searchOptions['explain']);

        /** @var array{total:int,results:array<int,array<string,mixed>>} $payload */
        $payload = $searcher->search($query, $searchOptions);
        if ($format === 'json') {
            $this->line($this->json_payload($payload));
            return;
        }

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

        $this->format_items($format, $results, $fields);
        if ($explain && $format === 'table' && isset($payload['explain']) && is_array($payload['explain'])) {
            $summaryRows = $this->search_explain_summary_rows($payload['explain']);
            if ($summaryRows !== []) {
                $this->format_items('table', $summaryRows, ['field', 'value']);
            }
        }
    }

    /**
     * Capture a bounded read-only support bundle for one search query.
     *
     * ## OPTIONS
     *
     * <query>
     * : Search query. The diagnostic path truncates very long query strings
     *   before searching to keep the support payload bounded.
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
     * [--format=<format>]
     * : Output format. Default: json. Table-like formats emit only the summary.
     *
     * @param string[] $args First positional argument is the query string.
     * @param array<string,mixed> $assoc_args Options shared with `wp fts search`.
     */
    public function diagnose(array $args, array $assoc_args): void
    {
        $rawQuery = (string) ($args[0] ?? '');
        $normalizedQuery = $this->bounded_cli_text($rawQuery, 0);
        $query = $this->bounded_cli_text($normalizedQuery, self::DIAGNOSTIC_QUERY_MAX_BYTES);
        $format = (string) $this->assoc_arg($assoc_args, ['format'], 'json');
        $searchOptions = $this->search_options_from_cli_args($assoc_args, true);
        $searcher = new WP_FTS_Searcher($this->storage(false), WP_FTS_Plugin::runtime_analyzer());
        $operatorStatus = WP_FTS_Plugin::operator_status();

        /** @var array{total:int,limit:int,offset:int,query_lang:string,results:array<int,array<string,mixed>>,explain?:array<string,mixed>} $searchPayload */
        $searchPayload = $searcher->search($query, $searchOptions);
        $queryArgs = $this->diagnostic_query_args($searchOptions, $searchPayload);
        $bundle = [
            'schema' => self::DIAGNOSTIC_BUNDLE_SCHEMA,
            'tool' => 'wp fts diagnose',
            'query' => $query,
            'query_truncated' => $query !== $normalizedQuery,
            'query_args' => $queryArgs,
            'operator_status' => $operatorStatus,
            'search' => $searchPayload,
            'summary' => $this->diagnostic_summary($operatorStatus, $searchPayload),
        ];

        if ($format === 'json') {
            $this->line($this->json_payload($bundle));
            return;
        }

        $this->format_items($format, $this->diagnostic_summary_rows($bundle['summary']), ['field', 'value']);
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
     * Show bounded failed-item recovery records.
     *
     * ## OPTIONS
     *
     * [--post_id=<id>]
     * : Inspect one failed post record. Alias: --post-id.
     *
     * [--limit=<n>]
     * : Maximum recent records to include. Default: 10.
     *
     * [--format=<format>]
     * : Output format. Default: table. Supports json for automation.
     *
     * @param string[] $args Positional arguments; unused.
     * @param array<string,mixed> $assoc_args WP-CLI options.
     */
    public function failed_items(array $args, array $assoc_args): void
    {
        $postId = $this->non_negative_int_arg($this->assoc_arg($assoc_args, ['post_id', 'post-id'], 0), 0);
        $limit = $this->positive_int_arg($this->assoc_arg($assoc_args, ['limit'], 10), 10);

        $this->output_assoc(WP_FTS_Plugin::failure_recovery_status($limit, $postId), $assoc_args);
    }

    /**
     * Mark failed items retryable and enqueue them for a later bounded pass.
     *
     * ## OPTIONS
     *
     * [<post_id>]
     * : Failed post ID to retry.
     *
     * [--all]
     * : Retry a bounded set of recent failed records when no post ID is supplied.
     *
     * [--limit=<n>]
     * : Maximum records to retry with --all. Default: 10.
     *
     * [--format=<format>]
     * : Output format. Default: table. Supports json for automation.
     *
     * @param string[] $args Optional first positional argument is the post id.
     * @param array<string,mixed> $assoc_args WP-CLI options.
     */
    public function retry_failed_item(array $args, array $assoc_args): void
    {
        $postId = isset($args[0]) ? $this->non_negative_int_arg($args[0], 0) : 0;
        $all = $this->bool_flag_arg($assoc_args, ['all'], false);
        $limit = $all ? $this->positive_int_arg($this->assoc_arg($assoc_args, ['limit'], 10), 10) : 1;
        if ($postId <= 0 && !$all) {
            $this->output_assoc([
                'schema' => 'wp-fts-failure-recovery-v1',
                'action' => 'retry',
                'status' => 'post_id_required',
                'matched_count' => 0,
                'updated_count' => 0,
                'queued_count' => 0,
                'items' => [],
                'message' => 'Pass a failed post ID or --all with a bounded --limit.',
            ], $assoc_args);
            return;
        }

        $this->output_assoc(WP_FTS_Plugin::retry_failed_item_recovery($postId, $limit), $assoc_args);
    }

    /**
     * Clear failed-item recovery metadata without deleting content or index rows.
     *
     * ## OPTIONS
     *
     * [<post_id>]
     * : Failed post ID whose recovery metadata should be cleared.
     *
     * [--all]
     * : Clear a bounded set of recent failed records when no post ID is supplied.
     *
     * [--limit=<n>]
     * : Maximum records to clear with --all. Default: 10.
     *
     * [--format=<format>]
     * : Output format. Default: table. Supports json for automation.
     *
     * @param string[] $args Optional first positional argument is the post id.
     * @param array<string,mixed> $assoc_args WP-CLI options.
     */
    public function clear_failed_item(array $args, array $assoc_args): void
    {
        $postId = isset($args[0]) ? $this->non_negative_int_arg($args[0], 0) : 0;
        $all = $this->bool_flag_arg($assoc_args, ['all'], false);
        $limit = $all ? $this->positive_int_arg($this->assoc_arg($assoc_args, ['limit'], 10), 10) : 1;
        if ($postId <= 0 && !$all) {
            $this->output_assoc([
                'schema' => 'wp-fts-failure-recovery-v1',
                'action' => 'clear',
                'status' => 'post_id_required',
                'matched_count' => 0,
                'updated_count' => 0,
                'queued_count' => 0,
                'items' => [],
                'message' => 'Pass a failed post ID or --all with a bounded --limit.',
            ], $assoc_args);
            return;
        }

        $this->output_assoc(WP_FTS_Plugin::clear_failed_item_recovery($postId, $limit), $assoc_args);
    }

    /**
     * Schedule the background queue processor when pending work exists.
     *
     * This only restores the future WP-Cron event. It does not process, index,
     * repair, reset, or clear any content in the current command.
     *
     * ## OPTIONS
     *
     * [--format=<format>]
     * : Output format. Default: table. Supports json for automation.
     *
     * @param string[] $args Positional arguments; unused.
     * @param array<string,mixed> $assoc_args WP-CLI options.
     */
    public function schedule_queue(array $args, array $assoc_args): void
    {
        $this->output_assoc(WP_FTS_Plugin::schedule_queue_processor_for_operator(), $assoc_args);
    }

    /**
     * Clear FTS index data and runtime indexing state without deleting posts.
     *
     * ## OPTIONS
     *
     * --yes
     * : Required destructive confirmation. Without it, no storage or options are mutated.
     *
     * [--format=<format>]
     * : Output format. Default: table. Supports json for automation.
     *
     * @param string[] $args Positional arguments; unused.
     * @param array<string,mixed> $assoc_args WP-CLI options.
     */
    public function reset_index(array $args, array $assoc_args): void
    {
        if (!$this->bool_flag_arg($assoc_args, ['yes'], false)) {
            $this->output_assoc([
                'status' => 'confirmation_required',
                'reset' => false,
                'message' => 'Pass --yes to clear FTS index data.',
            ], $assoc_args);
            if (class_exists('WP_CLI') && is_callable(['WP_CLI', 'warning'])) {
                WP_CLI::warning('Confirmation required: pass --yes to clear FTS index data. WordPress posts and plugin settings will be preserved.');
            }
            return;
        }

        $locked = WP_FTS_Plugin::run_index_writer_with_lock(
            'wp-cli-reset-index',
            static fn(): array => WP_FTS_Plugin::reset_index(),
            [
                'batch_size' => 0,
                'processed' => 0,
                'record_skip' => false,
            ]
        );
        if (empty($locked['acquired'])) {
            $this->warn_index_writer_locked('reset-index');
            $this->output_assoc([
                'status' => 'skipped_locked',
                'reset' => false,
                'lock_active' => true,
                'message' => 'Another index writer is already running; no reset was performed.',
            ], $assoc_args);
            return;
        }

        $result = is_array($locked['result'] ?? null) ? $locked['result'] : [];
        $this->output_assoc($result, $assoc_args);
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
            'stale_processed' => max(0, (int) ($summary['stale_processed'] ?? 0)),
            'skipped_locked' => (bool) ($summary['skipped_locked'] ?? false),
            'stopped_by_budget' => (bool) ($summary['stopped_by_budget'] ?? false),
            'has_more' => (bool) ($health['has_more'] ?? $summary['has_more'] ?? false),
            'pending_queue_count' => max(0, (int) ($health['pending_queue_count'] ?? 0)),
            'stale_debt_active' => (bool) ($health['stale_debt_active'] ?? false),
            'stale_debt_cursor_post_id' => max(0, (int) ($health['stale_debt_cursor_post_id'] ?? 0)),
            'stale_debt_processed_count' => max(0, (int) ($health['stale_debt_processed_count'] ?? 0)),
            'stale_debt_remaining_count' => max(0, (int) ($health['stale_debt_remaining_count'] ?? 0)),
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
        $locked = WP_FTS_Plugin::run_index_writer_with_lock(
            'wp-cli-delete',
            function () use ($docId): bool {
                return $this->indexer()->delete_document($docId);
            },
            ['batch_size' => 1]
        );
        if (empty($locked['acquired'])) {
            $this->warn_index_writer_locked('delete');
            return;
        }

        $deleted = (bool) ($locked['result'] ?? false);
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
        $locked = WP_FTS_Plugin::run_index_writer_with_lock(
            'wp-cli-optimize',
            function (): int {
                $this->indexer()->optimize();

                return 1;
            },
            ['batch_size' => 1]
        );
        if (empty($locked['acquired'])) {
            $this->warn_index_writer_locked('optimize');
            return;
        }

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
        array_push($rows, ...$this->search_provider_compatibility_status_rows($data));
        array_push($rows, ...$this->language_pack_status_rows($data));

        $this->format_items($format, $rows, ['field', 'value'], false);
    }

    /**
     * Add concise human-table rows for the nested status block while preserving
     * the original nested key for JSON and existing table consumers.
     *
     * @param array<string,mixed> $data
     * @return array<int,array{field:string,value:string}>
     */
    private function search_provider_compatibility_status_rows(array $data): array
    {
        $compatibility = $data['search_provider_compatibility'] ?? null;
        if (!is_array($compatibility)) {
            return [];
        }

        $providerNames = [];
        if (isset($compatibility['known_provider_names']) && is_array($compatibility['known_provider_names'])) {
            foreach ($compatibility['known_provider_names'] as $name) {
                $bounded = $this->bounded_cli_text($name, 80);
                if ($bounded !== '') {
                    $providerNames[] = $bounded;
                }
            }
        }

        $fields = [
            'search_provider_compatibility_mode' => $compatibility['mode'] ?? '',
            'search_provider_compatibility_label' => $compatibility['mode_label'] ?? '',
            'search_provider_compatibility_debug_value' => $compatibility['mode_debug_value'] ?? '',
            'search_provider_compatibility_public_site_replacement' => $compatibility['public_site_replacement'] ?? '',
            'search_provider_compatibility_admin_posts_replacement' => $compatibility['admin_posts_replacement'] ?? '',
            'search_provider_compatibility_known_provider_count' => $compatibility['known_provider_count'] ?? 0,
            'search_provider_compatibility_known_provider_names' => implode(', ', $providerNames),
            'search_provider_compatibility_recommendation' => $compatibility['recommendation'] ?? '',
        ];

        $rows = [];
        foreach ($fields as $field => $value) {
            $rows[] = [
                'field' => $field,
                'value' => $this->bounded_cli_text($value, 240),
            ];
        }

        return $rows;
    }

    /**
     * Add concise human-table rows for the nested language-pack status block
     * while preserving the original nested row for JSON and existing consumers.
     *
     * @param array<string,mixed> $data
     * @return array<int,array{field:string,value:string}>
     */
    private function language_pack_status_rows(array $data): array
    {
        $status = $data['language_pack_status'] ?? null;
        if (!is_array($status)) {
            return [];
        }

        $activeLanguages = [];
        if (isset($status['active_runtime_languages']) && is_array($status['active_runtime_languages'])) {
            foreach ($status['active_runtime_languages'] as $summary) {
                $bounded = $this->bounded_cli_text($summary, 120);
                if ($bounded !== '') {
                    $activeLanguages[] = $bounded;
                }
            }
        }

        $runtimeSupport = $this->bounded_cli_text($status['runtime_support_label'] ?? '', 120);
        $matchedLanguage = $this->bounded_cli_text($status['matched_runtime_language_label'] ?? '', 80);
        if ($matchedLanguage !== '') {
            $runtimeSupport = trim($runtimeSupport . ' via ' . $matchedLanguage);
        }

        $gzipStatus = $this->bounded_cli_text($status['gzip_status'] ?? '', 40);
        $availability = $this->bounded_cli_text($status['runtime_pack_availability'] ?? '', 180);
        if ($availability !== '') {
            $gzipStatus = trim($gzipStatus . ': ' . $availability);
        }

        $fields = [
            'language_pack_site_language' => $status['site_language_label'] ?? $status['site_language'] ?? '',
            'language_pack_runtime_support' => $runtimeSupport,
            'language_pack_fallback_languages' => $status['fallback_summary'] ?? '',
            'language_pack_active_runtime_pack_count' => $status['active_runtime_pack_count'] ?? 0,
            'language_pack_active_runtime_languages' => implode(', ', $activeLanguages),
            'language_pack_gzip_status' => $gzipStatus,
            'language_pack_recommendation' => $status['recommendation'] ?? '',
        ];

        $rows = [];
        foreach ($fields as $field => $value) {
            $rows[] = [
                'field' => $field,
                'value' => $this->bounded_cli_text($value, 240),
            ];
        }

        return $rows;
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

    private function warn_index_writer_locked(string $operation): void
    {
        $message = sprintf(
            'Skipped FTS %s: another index writer is already running. No overlapping writer was started; run `wp fts status` for lock details and try again shortly.',
            $operation
        );
        if (class_exists('WP_CLI') && is_callable(['WP_CLI', 'warning'])) {
            WP_CLI::warning($message);
            return;
        }

        $this->line('WARNING: ' . $message);
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
     * Convert the structured searcher explain payload into a compact table.
     *
     * @param array<string,mixed> $explain
     * @return array<int,array{field:string,value:string}>
     */
    private function search_explain_summary_rows(array $explain): array
    {
        $summaries = [
            'storage' => $this->explain_storage_summary($explain['storage'] ?? null),
            'query_plan' => $this->explain_query_plan_summary($explain['query_plan'] ?? null),
            'fast_mode' => $this->explain_fast_mode_summary($explain['fast_mode'] ?? null),
            'scoring' => $this->explain_scoring_summary($explain['scoring'] ?? null),
            'recency_boost' => $this->explain_recency_boost_summary($explain['recency_boost'] ?? null),
            'result_matches' => $this->explain_result_matches_summary($explain['results'] ?? null),
            'field_matches' => $this->explain_field_matches_summary($explain['results'] ?? null),
        ];

        $rows = [];
        foreach ($summaries as $field => $summary) {
            if ($summary !== '') {
                $rows[] = [
                    'field' => $field,
                    'value' => $this->bounded_cli_text($summary, self::EXPLAIN_SUMMARY_MAX_BYTES),
                ];
            }
        }

        return $rows;
    }

    /**
     * Parse the shared `wp fts search` / `wp fts diagnose` query options.
     *
     * @param array<string,mixed> $assoc_args
     * @return array<string,mixed>
     */
    private function search_options_from_cli_args(array $assoc_args, bool $forceExplain = false): array
    {
        $searchOptions = [
            'mode' => (string) ($assoc_args['mode'] ?? 'OR'),
            'limit' => $this->positive_int_arg($this->assoc_arg($assoc_args, ['limit'], 10), 10),
            'offset' => $this->non_negative_int_arg($this->assoc_arg($assoc_args, ['offset'], 0), 0),
            'include_total' => true,
            'include_metadata' => true,
            'include_snippets' => array_key_exists('snippet', $assoc_args) || array_key_exists('snippets', $assoc_args),
        ];
        if ($forceExplain || $this->bool_flag_arg($assoc_args, ['explain', 'debug'], false)) {
            $searchOptions['explain'] = true;
        }
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

        return $searchOptions;
    }

    /**
     * @param array<string,mixed> $searchOptions
     * @param array<string,mixed> $searchPayload
     * @return array<string,mixed>
     */
    private function diagnostic_query_args(array $searchOptions, array $searchPayload): array
    {
        $queryArgs = [
            'lang' => is_scalar($searchOptions['lang'] ?? null)
                ? (string) $searchOptions['lang']
                : (is_scalar($searchPayload['query_lang'] ?? null) ? (string) $searchPayload['query_lang'] : ''),
            'mode' => is_scalar($searchOptions['mode'] ?? null) ? (string) $searchOptions['mode'] : 'OR',
            'limit' => max(1, (int) ($searchOptions['limit'] ?? 10)),
            'offset' => max(0, (int) ($searchOptions['offset'] ?? 0)),
            'snippet' => !empty($searchOptions['include_snippets']),
            'explain' => true,
        ];

        foreach ([
            'post_status' => 'post_status',
            'post_type' => 'post_type',
            'date_after' => 'after',
            'date_before' => 'before',
            'recency_boost' => 'recency_boost',
            'recency_boost_strength' => 'recency_boost_strength',
            'recency_boost_half_life_days' => 'recency_boost_half_life_days',
            'prefix_matching' => 'prefix_matching',
            'prefix_min_length' => 'prefix_min_length',
            'prefix_max_terms' => 'prefix_max_terms',
        ] as $searchKey => $payloadKey) {
            if (array_key_exists($searchKey, $searchOptions)) {
                $queryArgs[$payloadKey] = $searchOptions[$searchKey];
            }
        }

        return $queryArgs;
    }

    /**
     * @param array<string,mixed> $operatorStatus
     * @param array<string,mixed> $searchPayload
     * @return array<string,mixed>
     */
    private function diagnostic_summary(array $operatorStatus, array $searchPayload): array
    {
        $results = is_array($searchPayload['results'] ?? null) ? $searchPayload['results'] : [];
        $explain = is_array($searchPayload['explain'] ?? null) ? $searchPayload['explain'] : [];
        $fastMode = is_array($explain['fast_mode'] ?? null) ? $explain['fast_mode'] : [];
        $scoring = is_array($explain['scoring'] ?? null) ? $explain['scoring'] : [];
        $compatibility = is_array($operatorStatus['search_provider_compatibility'] ?? null)
            ? $operatorStatus['search_provider_compatibility']
            : [];
        $languagePack = is_array($operatorStatus['language_pack_status'] ?? null)
            ? $operatorStatus['language_pack_status']
            : [];
        $runtimeSupport = is_array($languagePack['runtime_support'] ?? null)
            ? $languagePack['runtime_support']
            : [];
        $schedule = is_array($operatorStatus['queue_processor_schedule'] ?? null)
            ? $operatorStatus['queue_processor_schedule']
            : [];
        $pendingWork = !empty($operatorStatus['has_more'])
            || max(0, (int) ($operatorStatus['pending_queue_count'] ?? 0)) > 0
            || max(0, (int) ($operatorStatus['remaining_count'] ?? 0)) > 0
            || max(0, (int) ($operatorStatus['stale_debt_remaining_count'] ?? 0)) > 0
            || !empty($schedule['pending_work']);
        $schemaStatus = is_scalar($operatorStatus['schema_status'] ?? null) ? (string) $operatorStatus['schema_status'] : '';

        return [
            'returned_count' => count($results),
            'visible_total' => max(0, (int) ($searchPayload['total'] ?? 0)),
            'storage_backend' => $this->bounded_cli_text($operatorStatus['storage_backend'] ?? ($explain['storage']['backend'] ?? ''), 80),
            'schema_status' => $this->bounded_cli_text($schemaStatus, 40),
            'fast_mode' => [
                'mode' => $this->bounded_cli_text($fastMode['mode'] ?? '', 40),
                'source' => $this->bounded_cli_text($fastMode['source'] ?? '', 80),
                'reason' => $this->bounded_cli_text($fastMode['reason'] ?? '', self::DIAGNOSTIC_SUMMARY_MAX_BYTES),
            ],
            'candidate_rows_fetched' => $this->optional_non_negative_int($scoring['candidate_rows_fetched'] ?? null),
            'candidate_rows_considered' => $this->optional_non_negative_int($scoring['candidate_rows_considered'] ?? null),
            'candidate_docs_considered' => $this->optional_non_negative_int($scoring['candidate_docs_considered'] ?? null),
            'candidate_docs_scored' => $this->optional_non_negative_int($scoring['candidate_docs_scored'] ?? null),
            'matched_languages' => $this->diagnostic_matched_languages($searchPayload),
            'provider_compatibility' => [
                'mode' => $this->bounded_cli_text($compatibility['mode'] ?? '', 80),
                'known_provider_count' => max(0, (int) ($compatibility['known_provider_count'] ?? 0)),
                'known_provider_summary' => $this->bounded_cli_text($compatibility['known_provider_summary'] ?? '', self::DIAGNOSTIC_SUMMARY_MAX_BYTES),
            ],
            'runtime_language_pack_support' => [
                'status' => $this->bounded_cli_text($runtimeSupport['status'] ?? ($languagePack['runtime_support_status'] ?? ''), 80),
                'label' => $this->bounded_cli_text($runtimeSupport['label'] ?? ($languagePack['runtime_support_label'] ?? ''), 120),
                'full' => (bool) ($runtimeSupport['full'] ?? ($languagePack['runtime_support_full'] ?? false)),
                'reason' => $this->bounded_cli_text($runtimeSupport['reason'] ?? ($languagePack['runtime_support_reason'] ?? ''), self::DIAGNOSTIC_SUMMARY_MAX_BYTES),
                'matched_language' => $this->bounded_cli_text($runtimeSupport['matched_language'] ?? ($languagePack['matched_runtime_language'] ?? ''), 40),
            ],
            'lock_state' => $this->bounded_cli_text($operatorStatus['lock_state'] ?? '', 40),
            'lock_active' => (bool) ($operatorStatus['lock_active'] ?? false),
            'index_stale' => $schemaStatus !== 'current' || !empty($operatorStatus['stale_debt_active']),
            'pending_work' => $pendingWork,
            'pending_queue_count' => max(0, (int) ($operatorStatus['pending_queue_count'] ?? 0)),
            'remaining_count' => max(0, (int) ($operatorStatus['remaining_count'] ?? 0)),
            'stale_debt_active' => (bool) ($operatorStatus['stale_debt_active'] ?? false),
            'stale_debt_remaining_count' => max(0, (int) ($operatorStatus['stale_debt_remaining_count'] ?? 0)),
        ];
    }

    /**
     * @param array<string,mixed> $summary
     * @return array<int,array{field:string,value:string}>
     */
    private function diagnostic_summary_rows(array $summary): array
    {
        $fastMode = is_array($summary['fast_mode'] ?? null) ? $summary['fast_mode'] : [];
        $provider = is_array($summary['provider_compatibility'] ?? null) ? $summary['provider_compatibility'] : [];
        $languagePack = is_array($summary['runtime_language_pack_support'] ?? null) ? $summary['runtime_language_pack_support'] : [];

        $rows = [
            'returned_count' => $summary['returned_count'] ?? 0,
            'visible_total' => $summary['visible_total'] ?? 0,
            'storage_backend' => $summary['storage_backend'] ?? '',
            'schema_status' => $summary['schema_status'] ?? '',
            'fast_mode' => $this->summary_parts([
                'mode' => $fastMode['mode'] ?? '',
                'source' => $fastMode['source'] ?? '',
                'reason' => $fastMode['reason'] ?? '',
            ]),
            'scoring' => $this->summary_parts([
                'candidate_rows_fetched' => $summary['candidate_rows_fetched'] ?? '',
                'candidate_rows_considered' => $summary['candidate_rows_considered'] ?? '',
                'candidate_docs_considered' => $summary['candidate_docs_considered'] ?? '',
                'candidate_docs_scored' => $summary['candidate_docs_scored'] ?? '',
            ]),
            'matched_languages' => is_array($summary['matched_languages'] ?? null) ? implode(',', $summary['matched_languages']) : '',
            'provider_compatibility' => $this->summary_parts([
                'mode' => $provider['mode'] ?? '',
                'known_provider_count' => $provider['known_provider_count'] ?? '',
                'known_provider_summary' => $provider['known_provider_summary'] ?? '',
            ]),
            'runtime_language_pack_support' => $this->summary_parts([
                'status' => $languagePack['status'] ?? '',
                'label' => $languagePack['label'] ?? '',
                'full' => $languagePack['full'] ?? false,
                'matched_language' => $languagePack['matched_language'] ?? '',
            ]),
            'lock_state' => $summary['lock_state'] ?? '',
            'lock_active' => $summary['lock_active'] ?? false,
            'index_stale' => $summary['index_stale'] ?? false,
            'pending_work' => $summary['pending_work'] ?? false,
            'pending_queue_count' => $summary['pending_queue_count'] ?? 0,
            'remaining_count' => $summary['remaining_count'] ?? 0,
            'stale_debt_active' => $summary['stale_debt_active'] ?? false,
            'stale_debt_remaining_count' => $summary['stale_debt_remaining_count'] ?? 0,
        ];

        $formatted = [];
        foreach ($rows as $field => $value) {
            $formatted[] = [
                'field' => $field,
                'value' => $this->bounded_cli_text($value, self::DIAGNOSTIC_SUMMARY_MAX_BYTES),
            ];
        }

        return $formatted;
    }

    /**
     * @param array<string,mixed> $searchPayload
     * @return string[]
     */
    private function diagnostic_matched_languages(array $searchPayload): array
    {
        $languages = [];
        $explain = is_array($searchPayload['explain'] ?? null) ? $searchPayload['explain'] : [];
        $queryPlan = is_array($explain['query_plan'] ?? null) ? $explain['query_plan'] : [];
        if (isset($queryPlan['analyzed_languages']) && is_array($queryPlan['analyzed_languages'])) {
            foreach ($queryPlan['analyzed_languages'] as $language) {
                $bounded = $this->bounded_cli_text($language, 40);
                if ($bounded !== '') {
                    $languages[$bounded] = true;
                }
            }
        }

        $results = is_array($explain['results'] ?? null) ? $explain['results'] : [];
        foreach ($results as $row) {
            if (!is_array($row)) {
                continue;
            }
            if (isset($row['matched_languages']) && is_array($row['matched_languages'])) {
                foreach ($row['matched_languages'] as $language) {
                    $bounded = $this->bounded_cli_text($language, 40);
                    if ($bounded !== '') {
                        $languages[$bounded] = true;
                    }
                }
            }
            if (count($languages) >= self::EXPLAIN_SUMMARY_MAX_ITEMS) {
                break;
            }
        }

        return array_slice(array_keys($languages), 0, self::EXPLAIN_SUMMARY_MAX_ITEMS);
    }

    private function optional_non_negative_int(mixed $value): ?int
    {
        return is_numeric($value) ? max(0, (int) $value) : null;
    }

    private function explain_storage_summary(mixed $value): string
    {
        if (!is_array($value)) {
            return '';
        }

        return $this->summary_parts([
            'backend' => $value['backend'] ?? '',
            'metadata' => $value['metadata'] ?? '',
        ]);
    }

    private function explain_query_plan_summary(mixed $value): string
    {
        if (!is_array($value)) {
            return '';
        }

        $parts = [
            $this->summary_parts([
                'match_mode' => $value['match_mode'] ?? '',
                'logical_groups' => $value['logical_group_count'] ?? '',
                'prefix_matching' => $value['prefix_matching'] ?? '',
                'prefix_added_terms' => $value['prefix_added_terms'] ?? '',
                'prefix_min_length' => $value['prefix_min_length'] ?? '',
                'prefix_max_terms' => $value['prefix_max_terms'] ?? '',
            ]),
        ];

        if (isset($value['analyzed_languages']) && is_array($value['analyzed_languages'])) {
            $parts[] = 'languages=' . $this->summary_list($value['analyzed_languages']);
        }

        $terms = [];
        if (isset($value['terms']) && is_array($value['terms'])) {
            foreach ($value['terms'] as $term) {
                if (is_array($term)) {
                    $terms[] = $this->explain_term_summary($term);
                }
                if (count($terms) >= self::EXPLAIN_SUMMARY_MAX_ITEMS) {
                    break;
                }
            }
        }
        if ($terms !== []) {
            $parts[] = 'terms=' . implode(' | ', array_filter($terms, static fn(string $term): bool => $term !== ''))
                . (!empty($value['terms_more']) ? ' | ...' : '');
        }

        return implode(', ', array_filter($parts, static fn(string $part): bool => $part !== ''));
    }

    private function explain_fast_mode_summary(mixed $value): string
    {
        if (!is_array($value)) {
            return '';
        }

        return $this->summary_parts([
            'mode' => $value['mode'] ?? '',
            'source' => $value['source'] ?? '',
            'estimated_candidates' => $value['estimated_candidates'] ?? '',
            'threshold' => $value['threshold'] ?? '',
            'candidate_cap' => $value['candidate_cap'] ?? '',
            'reason' => $value['reason'] ?? '',
        ]);
    }

    private function explain_scoring_summary(mixed $value): string
    {
        if (!is_array($value)) {
            return '';
        }

        return $this->summary_parts([
            'candidate_rows_fetched' => $value['candidate_rows_fetched'] ?? '',
            'candidate_rows_considered' => $value['candidate_rows_considered'] ?? '',
            'candidate_docs_considered' => $value['candidate_docs_considered'] ?? '',
            'candidate_docs_scored' => $value['candidate_docs_scored'] ?? '',
            'scoring_terms' => $value['scoring_terms'] ?? '',
            'total' => $value['total'] ?? '',
            'total_accuracy' => $value['total_accuracy'] ?? '',
        ]);
    }

    private function explain_recency_boost_summary(mixed $value): string
    {
        if (!is_array($value)) {
            return '';
        }

        return $this->summary_parts([
            'enabled' => $value['enabled'] ?? '',
            'strength' => $value['strength'] ?? '',
            'half_life_days' => $value['half_life_days'] ?? '',
            'documents_considered' => $value['documents_considered'] ?? '',
            'documents_applied' => $value['documents_applied'] ?? '',
            'metadata_unavailable' => $value['metadata_unavailable'] ?? '',
            'missing_or_invalid_dates' => $value['missing_or_invalid_dates'] ?? '',
        ]);
    }

    private function explain_result_matches_summary(mixed $value): string
    {
        if (!is_array($value)) {
            return '';
        }

        $rows = [];
        foreach ($value as $row) {
            if (!is_array($row)) {
                continue;
            }
            $matches = [];
            if (isset($row['matches']) && is_array($row['matches'])) {
                foreach ($row['matches'] as $match) {
                    if (is_array($match)) {
                        $matches[] = $this->explain_term_summary($match);
                    }
                    if (count($matches) >= self::EXPLAIN_SUMMARY_MAX_ITEMS) {
                        break;
                    }
                }
            }
            $rows[] = 'doc ' . $this->bounded_cli_text($row['doc_id'] ?? '?', 40) . '=' . ($matches !== [] ? implode(' | ', array_filter($matches)) : '-')
                . (!empty($row['matches_more']) ? ' | ...' : '');
            if (count($rows) >= self::EXPLAIN_SUMMARY_MAX_ITEMS) {
                break;
            }
        }

        return implode('; ', $rows);
    }

    private function explain_field_matches_summary(mixed $value): string
    {
        if (!is_array($value)) {
            return '';
        }

        $rows = [];
        foreach ($value as $row) {
            if (!is_array($row)) {
                continue;
            }

            $fields = [];
            if (isset($row['field_matches']) && is_array($row['field_matches'])) {
                foreach ($row['field_matches'] as $field) {
                    if (!is_array($field)) {
                        continue;
                    }

                    $terms = [];
                    if (isset($field['terms']) && is_array($field['terms'])) {
                        foreach ($field['terms'] as $term) {
                            if (is_array($term)) {
                                $terms[] = $this->explain_term_summary($term);
                            }
                            if (count($terms) >= 2) {
                                break;
                            }
                        }
                    }

                    $fieldName = $this->bounded_cli_text($field['field'] ?? '?', 60);
                    $fields[] = $fieldName . '(' . $this->summary_parts([
                        'weight' => $field['weight'] ?? '',
                        'hits' => $field['match_count'] ?? '',
                        'weighted_hits' => $field['weighted_match_count'] ?? '',
                        'score' => $field['score_subtotal'] ?? '',
                    ]) . ($terms !== [] ? ', terms=' . implode(' | ', array_filter($terms)) : '') . (!empty($field['terms_more']) ? ' | ...' : '') . ')';
                    if (count($fields) >= self::EXPLAIN_SUMMARY_MAX_ITEMS) {
                        break;
                    }
                }
            }

            $rows[] = 'doc ' . $this->bounded_cli_text($row['doc_id'] ?? '?', 40) . '=' . ($fields !== [] ? implode(' ; ', $fields) : '-')
                . (!empty($row['field_matches_more']) ? ' ; ...' : '');
            if (count($rows) >= self::EXPLAIN_SUMMARY_MAX_ITEMS) {
                break;
            }
        }

        return implode('; ', $rows);
    }

    /**
     * @param array<string,mixed> $term
     */
    private function explain_term_summary(array $term): string
    {
        $lang = $this->bounded_cli_text($term['lang'] ?? '', 40);
        $surface = $this->bounded_cli_text($term['surface'] ?? '', 80);
        $analyzed = $this->bounded_cli_text($term['term'] ?? '', 80);
        $rank = $this->bounded_cli_text($term['rank_class'] ?? '', 40);

        $text = $analyzed;
        if ($surface !== '' && $analyzed !== '' && $surface !== $analyzed) {
            $text = $surface . '->' . $analyzed;
        } elseif ($surface !== '') {
            $text = $surface;
        }

        return trim($lang . ':' . $text . ($rank !== '' ? ' ' . $rank : ''));
    }

    /**
     * @param array<string,mixed> $parts
     */
    private function summary_parts(array $parts): string
    {
        $summary = [];
        foreach ($parts as $key => $value) {
            $formatted = $this->bounded_cli_text($value, 120);
            if ($formatted !== '') {
                $summary[] = $key . '=' . $formatted;
            }
        }

        return implode(', ', $summary);
    }

    /**
     * @param array<int,mixed> $values
     */
    private function summary_list(array $values): string
    {
        $items = [];
        foreach ($values as $value) {
            $items[] = $this->bounded_cli_text($value, 80);
            if (count($items) >= self::EXPLAIN_SUMMARY_MAX_ITEMS) {
                break;
            }
        }

        $items = array_values(array_filter($items, static fn(string $item): bool => $item !== ''));
        if (count($values) > count($items)) {
            $items[] = '...';
        }

        return implode(',', $items);
    }

    private function bounded_cli_text(mixed $value, int $maxBytes): string
    {
        if (is_bool($value)) {
            $text = $value ? 'yes' : 'no';
        } elseif ($value === null) {
            $text = '';
        } elseif (is_scalar($value)) {
            $text = (string) $value;
        } else {
            $text = $this->json_payload(is_array($value) ? $value : []);
        }

        $text = class_exists('WP_FTS_Utf8') ? WP_FTS_Utf8::repair($text) : $text;
        $text = trim(str_replace(["\r", "\n", "\t"], ' ', $text));
        $text = preg_replace('/\s+/', ' ', $text) ?? $text;
        if ($maxBytes <= 0 || strlen($text) <= $maxBytes) {
            return $text;
        }

        if (class_exists('WP_FTS_Utf8')) {
            return rtrim(WP_FTS_Utf8::truncate_bytes($text, max(0, $maxBytes - 3))) . '...';
        }

        return rtrim(substr($text, 0, max(0, $maxBytes - 3))) . '...';
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
     * Create MySQL storage, optionally ensuring required tables exist.
     *
     * @return WP_FTS_Storage_Mysql Ready-to-use storage backend.
     * @throws RuntimeException When `$wpdb` is unavailable.
     */
    private function storage(bool $ensureSchema = true): WP_FTS_Storage_Mysql
    {
        global $wpdb;

        if (!isset($wpdb) || !is_object($wpdb)) {
            throw new RuntimeException('WP-CLI command requires $wpdb.');
        }

        return WP_FTS_Plugin::storage($ensureSchema);
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
