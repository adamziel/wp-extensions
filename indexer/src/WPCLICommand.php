<?php
declare(strict_types=1);

/**
 * WP-CLI command surface for managing the custom FTS index.
 *
 * The command creates MySQL tables on demand, reindexes WordPress posts,
 * searches the index, schedules background queue recovery, reconciles missing
 * documents, and prunes bounded pages of empty dictionary rows.
 */
final class WP_FTS_WPCLI_Command
{
    private const DEFAULT_REINDEX_POST_STATUSES = ['publish', 'draft', 'pending', 'future', 'private'];
    private const EXPLAIN_SUMMARY_MAX_BYTES = 800;
    private const DIAGNOSTIC_BUNDLE_SCHEMA = 'wp-fts-query-diagnostic-bundle-v2';
    private const DIAGNOSTIC_QUERY_MAX_BYTES = 512;
    private const DIAGNOSTIC_SUMMARY_MAX_BYTES = 240;
    private const SEARCH_LANGUAGE_MAX_BYTES = 64;
    private const SEARCH_CURSOR_MAX_BYTES = 2048;
    private const SEARCH_DIRECTION_MAX_BYTES = 8;
    private const SEARCH_FILTER_MAX_BYTES = 4096;
    private const SEARCH_FILTER_MAX_VALUES = 32;
    private const SEARCH_FILTER_VALUE_MAX_BYTES = 64;
    private const REINDEX_FILTER_MAX_BYTES = 4096;
    private const REINDEX_FILTER_MAX_VALUES = 32;
    private const REINDEX_FILTER_VALUE_MAX_BYTES = 64;

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
     * : Maximum posts for the background scope to index. Default: unlimited.
     *
     * [--format=<format>]
     * : Output format. Default: table. Supports json for automation.
     *
     * @param string[] $args Positional arguments; unused.
     * @param array<string,mixed> $assoc_args WP-CLI options. Dashed and
     *        underscored option names are both accepted for post status/type.
     */
    public function reindex(array $args, array $assoc_args): void
    {
        if (array_key_exists('batch_size', $assoc_args) || array_key_exists('batch-size', $assoc_args)) {
            throw new InvalidArgumentException(
                '`wp fts reindex` queues background work and no longer accepts --batch_size; '
                . 'use `wp fts process-batch --batch_size=...` for one bounded worker pass.'
            );
        }

        $langArg = $this->assoc_arg($assoc_args, ['lang', 'language'], null);
        $lang = $langArg !== null ? $this->language_arg($langArg) : null;
        $postStatuses = $this->csv_arg(
            (string) $this->assoc_arg($assoc_args, ['post_status', 'post-status'], implode(',', self::DEFAULT_REINDEX_POST_STATUSES)),
            self::DEFAULT_REINDEX_POST_STATUSES
        );
        $postTypes = $this->csv_arg(
            (string) $this->assoc_arg($assoc_args, ['post_type', 'post-type'], 'post'),
            'post'
        );
        sort($postStatuses, SORT_STRING);
        sort($postTypes, SORT_STRING);
        $requestedLimit = $this->non_negative_int_arg($this->assoc_arg($assoc_args, ['limit'], 0), 0);
        $options = [
            'post_status' => $postStatuses,
            'post_type' => $postTypes,
            'limit' => $requestedLimit,
        ];
        if ($lang !== null) {
            $options['lang'] = $lang;
        }

        WP_FTS_Plugin::enqueue_reindex_scope($options);
        $this->output_assoc([
            'status' => 'queued',
            'post_status' => $postStatuses,
            'post_type' => $postTypes,
            'language' => $lang ?? '',
            'requested_limit' => $requestedLimit,
            'has_more' => true,
            'message' => 'Queued one durable filtered reindex scope. WP-Cron will process it in bounded batches; '
                . 'use `wp fts status` to monitor progress or `wp fts process-batch --batch_size=100 --time_budget=20` '
                . 'for one bounded manual pass.',
        ], $assoc_args);
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
     * : Result count. Default: 10. Maximum: 50.
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
     * [--cursor=<cursor>]
     * : Opaque cursor returned by an earlier page.
     *
     * [--after_cursor=<cursor>]
     * : Return the page after this cursor. Alias: --after-cursor.
     *
     * [--before_cursor=<cursor>]
     * : Return the page before this cursor. Alias: --before-cursor.
     *
     * [--direction=<after|before>]
     * : Direction for --cursor. Default: after.
     *
     * [--snippet]
     * : Include snippets from bounded extracted text.
     *
     * [--format=<format>]
     * : Output format. Default: table. Supports json for automation.
     *
     * [--explain]
     * : Include bounded read-only search diagnostics. JSON output includes the structured explain payload; table output appends a concise summary. Without the FTS operator capability, output explicitly reports that explain is unavailable.
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
        $searchOptions = $this->search_options_from_cli_args($assoc_args);
        $explain = $this->bool_flag_arg($assoc_args, ['explain', 'debug'], false);

        /** @var array{total:null,total_relation:string,has_more:bool,next_cursor:?string,previous_cursor:?string,results:array<int,array<string,mixed>>} $payload */
        $payload = $explain
            ? WP_FTS_Plugin::search_with_explain($query, $searchOptions)
            : WP_FTS_Plugin::search_page($query, $searchOptions);
        if ($format === 'json') {
            $this->line($this->json_payload($payload));
            return;
        }

        $results = $payload['results'];
        foreach ($results as &$row) {
            $row['total_relation'] = (string) ($payload['total_relation'] ?? 'unknown');
            $row['has_more'] = !empty($payload['has_more']) ? 'yes' : 'no';
            $row['next_cursor'] = is_scalar($payload['next_cursor'] ?? null) ? (string) $payload['next_cursor'] : '';
            $row['previous_cursor'] = is_scalar($payload['previous_cursor'] ?? null) ? (string) $payload['previous_cursor'] : '';
            foreach (['post_id', 'post_type', 'post_status', 'post_date_gmt', 'title'] as $field) {
                $row[$field] ??= $field === 'post_id' ? 0 : '';
            }
            if ($searchOptions['include_snippets']) {
                $row['snippet'] ??= '';
            }
        }
        unset($row);

        $fields = ['doc_id', 'score', 'post_id', 'post_type', 'post_status', 'post_date_gmt', 'title', 'total_relation', 'has_more', 'next_cursor', 'previous_cursor'];
        if ($searchOptions['include_snippets']) {
            $fields[] = 'snippet';
        }

        $this->format_items($format, $results, $fields);
        if ($explain && $format === 'table' && isset($payload['explain']) && is_array($payload['explain'])) {
            $summaryRows = $this->search_explain_summary_rows($payload['explain']);
            if ($summaryRows !== []) {
                $this->format_items('table', $summaryRows, ['field', 'value']);
            }
        } elseif ($explain && $format === 'table' && empty($payload['explain_available'])) {
            $this->format_items('table', [[
                'field' => 'explain',
                'value' => 'unavailable: ' . $this->bounded_cli_text($payload['explain_unavailable_reason'] ?? 'not_available', 80),
            ]], ['field', 'value']);
        }
    }

    /**
     * Capture a bounded read-only support bundle for one search query. Internal
     * explain data requires the FTS operator capability; ordinary page data and
     * an explicit unavailable marker are returned otherwise.
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
     * : Result count. Default: 10. Maximum: 50.
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
     * [--cursor=<cursor>]
     * : Opaque cursor returned by an earlier page.
     *
     * [--after_cursor=<cursor>]
     * : Return the page after this cursor. Alias: --after-cursor.
     *
     * [--before_cursor=<cursor>]
     * : Return the page before this cursor. Alias: --before-cursor.
     *
     * [--direction=<after|before>]
     * : Direction for --cursor. Default: after.
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
        $query = $this->bounded_diagnostic_query($normalizedQuery);
        $format = (string) $this->assoc_arg($assoc_args, ['format'], 'json');
        $searchOptions = $this->search_options_from_cli_args($assoc_args);
        $operatorStatus = WP_FTS_Plugin::operator_status(true);

        try {
            if (
                ($operatorStatus['schema_status'] ?? '') !== 'current'
                || empty($operatorStatus['physical_schema_usable'])
            ) {
                throw new WP_FTS_Search_Unavailable(
                    'Full-text search was not run because physical schema verification did not pass.'
                );
            }
            /** @var array{total:null,total_relation:string,has_more:bool,next_cursor:?string,previous_cursor:?string,query_lang:string,results:array<int,array<string,mixed>>} $searchPayload */
            $searchPayload = WP_FTS_Plugin::search_with_explain($query, $searchOptions);
        } catch (WP_FTS_Search_Budget_Exceeded $error) {
            // A diagnostic command must still describe an input that the
            // fixed relational plan rejects. The rejection happens before an
            // over-wide query reaches storage, and the bundle records the
            // stable bound instead of silently searching a different query.
            $searchPayload = [
                'total' => null,
                'total_relation' => 'unknown',
                'has_more' => false,
                'next_cursor' => null,
                'previous_cursor' => null,
                'query_lang' => is_scalar($searchOptions['lang'] ?? null) ? (string) $searchOptions['lang'] : '',
                'results' => [],
                'explain_available' => false,
                'explain_unavailable_reason' => 'budget_exceeded: ' . $this->bounded_cli_text($error->budget(), 80),
            ];
        } catch (WP_FTS_Search_Unavailable $error) {
            // A damaged schema must remain a read-only diagnostic. Do not let
            // the search adapter discover a missing table and schedule repair.
            $searchPayload = [
                'total' => null,
                'total_relation' => 'unknown',
                'has_more' => false,
                'next_cursor' => null,
                'previous_cursor' => null,
                'query_lang' => is_scalar($searchOptions['lang'] ?? null) ? (string) $searchOptions['lang'] : '',
                'results' => [],
                'explain_available' => false,
                'explain_unavailable_reason' => $this->bounded_cli_text(
                    $error->getMessage(),
                    self::DIAGNOSTIC_SUMMARY_MAX_BYTES
                ),
            ];
        }
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
     * @subcommand failed-items
     * @alias failed_items
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
     * @subcommand retry-failed-item
     * @alias retry_failed_item
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
     * @subcommand clear-failed-item
     * @alias clear_failed_item
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
     * @subcommand schedule-queue
     * @alias schedule_queue
     * @param string[] $args Positional arguments; unused.
     * @param array<string,mixed> $assoc_args WP-CLI options.
     */
    public function schedule_queue(array $args, array $assoc_args): void
    {
        $this->output_assoc(WP_FTS_Plugin::schedule_queue_processor_for_operator(), $assoc_args);
    }

    /**
     * Replace FTS index data and queue one complete background reconciliation.
     *
     * ## OPTIONS
     *
     * [--yes]
     * : Required destructive confirmation. Without it, no storage or options are mutated.
     *
     * [--format=<format>]
     * : Output format. Default: table. Supports json for automation.
     *
     * @subcommand reset-index
     * @alias reset_index
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
            'skipped_locked' => !empty($schema['skipped_locked']),
            'lock_active' => !empty($schema['lock_active']),
            'message' => !empty($schema['skipped_locked'])
                ? 'Another index writer is already running; no schema repair was performed.'
                : 'Schema repair completed under the shared index-writer lease.',
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
     * @subcommand process-batch
     * @alias process_batch
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
            'committed' => max(0, (int) ($summary['committed'] ?? 0)),
            'superseded' => max(0, (int) ($summary['superseded'] ?? 0)),
            'indexed' => max(0, (int) ($summary['indexed'] ?? 0)),
            'queue_processed' => max(0, (int) ($summary['queue_processed'] ?? 0)),
            'unchanged' => max(0, (int) ($summary['unchanged'] ?? 0)),
            'deleted' => max(0, (int) ($summary['deleted'] ?? 0)),
            'permanently_rejected' => max(0, (int) ($summary['permanently_rejected'] ?? 0)),
            'retryable_failures' => max(0, (int) ($summary['retryable_failures'] ?? 0)),
            'deferred' => max(0, (int) ($summary['deferred'] ?? 0)),
            'empty_terms_cleaned' => max(0, (int) ($summary['empty_terms_cleaned'] ?? 0)),
            'cleanup_pending' => (bool) ($summary['cleanup_pending'] ?? false),
            'backfill_processed' => max(0, (int) ($summary['backfill_processed'] ?? 0)),
            'skipped_locked' => (bool) ($summary['skipped_locked'] ?? false),
            'stopped_by_budget' => (bool) ($summary['stopped_by_budget'] ?? false),
            'has_more' => (bool) ($health['has_more'] ?? $summary['has_more'] ?? false),
            'pending_queue_count' => max(0, (int) ($health['pending_queue_count'] ?? 0)),
            'pending_queue_count_relation' => is_scalar($health['pending_queue_count_relation'] ?? null) ? (string) $health['pending_queue_count_relation'] : 'exact',
            'pending_post_work_count' => max(0, (int) ($health['pending_post_work_count'] ?? 0)),
            'pending_post_work_count_relation' => is_scalar($health['pending_post_work_count_relation'] ?? null) ? (string) $health['pending_post_work_count_relation'] : 'exact',
            'pending_scope_work_count' => max(0, (int) ($health['pending_scope_work_count'] ?? 0)),
            'pending_scope_work_count_relation' => is_scalar($health['pending_scope_work_count_relation'] ?? null) ? (string) $health['pending_scope_work_count_relation'] : 'exact',
            'reconciliation_cursor_post_id' => isset($health['reconciliation_cursor_post_id'])
                ? max(0, (int) $health['reconciliation_cursor_post_id'])
                : null,
            'reconciliation_active' => (bool) ($health['reconciliation_active'] ?? false),
            'profile_reconciliation_pending' => (bool) ($health['profile_reconciliation_pending'] ?? false),
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
     * Reconcile a missing or ineligible canonical post out of the index.
     *
     * ## OPTIONS
     *
     * <doc_id>
     * : Canonical WordPress post ID to reconcile. Eligible posts are rejected.
     *
     * @param string[] $args First positional argument is the document id.
     * @param array<string,mixed> $assoc_args Unused WP-CLI options.
     */
    public function delete(array $args, array $assoc_args): void
    {
        $docId = (int) ($args[0] ?? 0);
        $result = WP_FTS_Plugin::reconcile_cli_delete($docId);
        if (($result['status'] ?? '') === 'rejected_eligible') {
            WP_CLI::warning(
                "Document {$docId} belongs to an eligible canonical WordPress post and was not removed. Change its searchable status/type/password or delete the post, then let the bounded FTS worker reconcile it."
            );
            return;
        }
        if (max(0, (int) ($result['queued'] ?? 0)) > 0) {
            WP_CLI::success("Queued document {$docId} for canonical FTS reconciliation.");
            return;
        }

        WP_CLI::warning('Pass a positive canonical WordPress post ID.');
    }

    /**
     * Remove one bounded page of zero-frequency dictionary rows.
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

        WP_CLI::success('Completed one bounded empty-term cleanup pass; document frequencies remain writer-maintained.');
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
     * [--runtime-compression=<compression>]
     * : Runtime storage (`gzip` or `none`). Non-fixture packs default to and require indexed gzip; `none` is limited to fixtures with at most 50,000 rows and 8 MiB decoded.
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
     * @subcommand import-lemma-pack
     * @alias import_lemma_pack
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
     * [--runtime-compression=<compression>]
     * : Runtime storage (`gzip` or `none`). Non-fixture packs default to and require indexed gzip; `none` is limited to fixtures with at most 50,000 rows and 8 MiB decoded.
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
     * @subcommand import-conllu-lemma-pack
     * @alias import_conllu_lemma_pack
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
     * [--runtime-compression=<compression>]
     * : Runtime storage (`gzip` or `none`). Non-fixture packs default to and require indexed gzip; `none` is limited to fixtures with at most 50,000 rows and 8 MiB decoded.
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
     * @subcommand import-unimorph-lemma-pack
     * @alias import_unimorph_lemma_pack
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
            'relational_plan' => $this->summary_parts($this->relational_plan_summary($explain)),
            'recency_boost' => $this->summary_parts($this->relational_recency_summary($explain['recency_boost'] ?? null)),
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
    private function search_options_from_cli_args(array $assoc_args): array
    {
        $offset = $this->assoc_arg($assoc_args, ['offset'], null);
        if ($offset !== null && (!is_scalar($offset) || (is_string($offset) && strlen($offset) > 64))) {
            throw new InvalidArgumentException('Full-text search offsets must be bounded scalar values.');
        }
        if ($offset !== null && (!is_numeric($offset) || (float) $offset !== 0.0)) {
            throw new InvalidArgumentException('Full-text search no longer supports offsets; omit --offset or pass 0, then use an opaque cursor.');
        }
        $modeArg = $this->assoc_arg($assoc_args, ['mode'], 'OR');
        if (!is_scalar($modeArg) || strlen((string) $modeArg) > self::SEARCH_DIRECTION_MAX_BYTES) {
            throw new InvalidArgumentException('Search mode may contain at most 8 bytes.');
        }
        $limitArg = $this->assoc_arg($assoc_args, ['limit'], 10);
        if (!is_scalar($limitArg) || (is_string($limitArg) && strlen($limitArg) > 64)) {
            throw new InvalidArgumentException('Search limit must be a bounded scalar value.');
        }
        $searchOptions = [
            'mode' => (string) $modeArg,
            'limit' => min(
                WP_FTS_Plugin::MAX_SEARCH_LIMIT,
                $this->positive_int_arg($limitArg, 10)
            ),
            'include_metadata' => true,
            'include_snippets' => array_key_exists('snippet', $assoc_args) || array_key_exists('snippets', $assoc_args),
        ];
        foreach (['explain', 'debug'] as $switchKey) {
            if (!array_key_exists($switchKey, $assoc_args)) {
                continue;
            }
            $switchValue = $assoc_args[$switchKey];
            if (!is_scalar($switchValue) || (is_string($switchValue) && strlen($switchValue) > 16)) {
                throw new InvalidArgumentException("Search {$switchKey} options must be bounded scalar values.");
            }
        }
        $langArg = $this->assoc_arg($assoc_args, ['lang', 'language'], null);
        if ($langArg !== null) {
            $searchOptions['lang'] = $this->language_arg($langArg);
        }

        $postStatus = $this->assoc_arg($assoc_args, ['post_status', 'post-status'], null);
        if ($postStatus !== null) {
            $searchOptions['post_status'] = $this->search_csv_arg($postStatus, 'post status');
        }
        $postType = $this->assoc_arg($assoc_args, ['post_type', 'post-type'], null);
        if ($postType !== null) {
            $searchOptions['post_type'] = $this->search_csv_arg($postType, 'post type');
        }
        $after = $this->assoc_arg($assoc_args, ['after', 'date_after', 'date-after'], null);
        if ($after !== null) {
            if (!is_scalar($after) || strlen((string) $after) > self::SEARCH_FILTER_VALUE_MAX_BYTES) {
                throw new InvalidArgumentException('Search after dates may contain at most 64 bytes.');
            }
            $searchOptions['date_after'] = (string) $after;
        }
        $before = $this->assoc_arg($assoc_args, ['before', 'date_before', 'date-before'], null);
        if ($before !== null) {
            if (!is_scalar($before) || strlen((string) $before) > self::SEARCH_FILTER_VALUE_MAX_BYTES) {
                throw new InvalidArgumentException('Search before dates may contain at most 64 bytes.');
            }
            $searchOptions['date_before'] = (string) $before;
        }
        $recencyBoost = $this->assoc_arg($assoc_args, ['recency_boost', 'recency-boost', 'freshness_boost', 'freshness-boost'], null);
        if ($recencyBoost !== null) {
            $searchOptions['recency_boost_strength'] = $recencyBoost;
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
            if (!is_scalar($prefixMatching) || (is_string($prefixMatching) && strlen($prefixMatching) > 16)) {
                throw new InvalidArgumentException('Search prefix matching options must be bounded scalar values.');
            }
            $searchOptions['prefix_matching'] = $this->truthy_cli_value($prefixMatching);
        }
        $prefixMinLength = $this->assoc_arg($assoc_args, ['prefix_min_length', 'prefix-min-length'], null);
        if ($prefixMinLength !== null) {
            if (!is_scalar($prefixMinLength) || (is_string($prefixMinLength) && strlen($prefixMinLength) > 64)) {
                throw new InvalidArgumentException('Search prefix minimum options must be bounded scalar values.');
            }
            $searchOptions['prefix_min_length'] = WP_FTS_Plugin::sanitize_prefix_min_length($prefixMinLength);
        }
        $cursorCount = 0;
        foreach ([
            ['keys' => ['cursor'], 'option' => 'cursor'],
            ['keys' => ['after_cursor', 'after-cursor'], 'option' => 'after_cursor'],
            ['keys' => ['before_cursor', 'before-cursor'], 'option' => 'before_cursor'],
        ] as $cursorOption) {
            $value = $this->assoc_arg($assoc_args, $cursorOption['keys'], null);
            if ($value !== null) {
                if (!is_scalar($value) || strlen((string) $value) > self::SEARCH_CURSOR_MAX_BYTES) {
                    throw new InvalidArgumentException('Search cursors must be scalar values containing at most 2,048 bytes.');
                }
                $value = trim((string) $value);
                if ($value === '') {
                    throw new InvalidArgumentException('Search cursors must be non-empty scalar values.');
                }
                $searchOptions[$cursorOption['option']] = $value;
                $cursorCount++;
            }
        }
        if ($cursorCount > 1) {
            throw new InvalidArgumentException('Pass only one of --cursor, --after-cursor, or --before-cursor.');
        }
        $directionArg = $this->assoc_arg($assoc_args, ['direction'], null);
        if (!is_scalar($directionArg ?? 'after') || strlen((string) ($directionArg ?? 'after')) > self::SEARCH_DIRECTION_MAX_BYTES) {
            throw new InvalidArgumentException('Search cursor direction may contain at most 8 bytes.');
        }
        $direction = strtolower(trim((string) ($directionArg ?? 'after')));
        if (!in_array($direction, ['after', 'before'], true)) {
            throw new InvalidArgumentException('Search cursor direction must be after or before.');
        }
        if ($directionArg !== null && !isset($searchOptions['cursor'])) {
            throw new InvalidArgumentException('--direction requires --cursor; --after-cursor and --before-cursor already encode their direction.');
        }
        if (isset($searchOptions['cursor'])) {
            $searchOptions['direction'] = $direction;
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
            'snippet' => !empty($searchOptions['include_snippets']),
            'explain' => true,
        ];

        foreach ([
            'post_status' => 'post_status',
            'post_type' => 'post_type',
            'date_after' => 'after',
            'date_before' => 'before',
            'recency_boost_strength' => 'recency_boost_strength',
            'recency_boost_half_life_days' => 'recency_boost_half_life_days',
            'prefix_matching' => 'prefix_matching',
            'prefix_min_length' => 'prefix_min_length',
            'cursor' => 'cursor',
            'after_cursor' => 'after_cursor',
            'before_cursor' => 'before_cursor',
            'direction' => 'direction',
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
        $explainStorage = is_scalar($explain['storage'] ?? null) ? (string) $explain['storage'] : '';
        $pendingWork = !empty($operatorStatus['has_more'])
            || max(0, (int) ($operatorStatus['pending_queue_count'] ?? 0)) > 0
            || max(0, (int) ($operatorStatus['remaining_count'] ?? 0)) > 0
            || !empty($schedule['pending_work']);
        $schemaStatus = is_scalar($operatorStatus['schema_status'] ?? null) ? (string) $operatorStatus['schema_status'] : '';

        return [
            'returned_count' => count($results),
            'total_relation' => $this->bounded_cli_text($searchPayload['total_relation'] ?? 'unknown', 40),
            'has_more' => !empty($searchPayload['has_more']),
            'next_cursor_available' => is_scalar($searchPayload['next_cursor'] ?? null) && (string) $searchPayload['next_cursor'] !== '',
            'previous_cursor_available' => is_scalar($searchPayload['previous_cursor'] ?? null) && (string) $searchPayload['previous_cursor'] !== '',
            'explain_available' => isset($searchPayload['explain']) && is_array($searchPayload['explain']),
            'explain_unavailable_reason' => $this->bounded_cli_text($searchPayload['explain_unavailable_reason'] ?? '', 80),
            'storage_backend' => $this->bounded_cli_text($operatorStatus['storage_backend'] ?? $explainStorage, 80),
            'schema_status' => $this->bounded_cli_text($schemaStatus, 40),
            'query_lang' => $this->bounded_cli_text($searchPayload['query_lang'] ?? '', 40),
            'relational_plan' => $this->relational_plan_summary($explain),
            'recency_boost' => $this->relational_recency_summary($explain['recency_boost'] ?? null),
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
            'index_stale' => $schemaStatus !== 'current' || !empty($operatorStatus['reconciliation_active']),
            'pending_work' => $pendingWork,
            'pending_queue_count' => max(0, (int) ($operatorStatus['pending_queue_count'] ?? 0)),
            'pending_queue_count_relation' => $this->bounded_cli_text($operatorStatus['pending_queue_count_relation'] ?? 'exact', 20),
            'remaining_count' => null,
            'reconciliation_active' => (bool) ($operatorStatus['reconciliation_active'] ?? false),
            'profile_reconciliation_pending' => (bool) ($operatorStatus['profile_reconciliation_pending'] ?? false),
            'pending_post_work_count' => max(0, (int) ($operatorStatus['pending_post_work_count'] ?? 0)),
            'pending_post_work_count_relation' => $this->bounded_cli_text($operatorStatus['pending_post_work_count_relation'] ?? 'exact', 20),
            'pending_scope_work_count' => max(0, (int) ($operatorStatus['pending_scope_work_count'] ?? 0)),
            'pending_scope_work_count_relation' => $this->bounded_cli_text($operatorStatus['pending_scope_work_count_relation'] ?? 'exact', 20),
            'reconciliation_cursor_post_id' => isset($operatorStatus['reconciliation_cursor_post_id'])
                ? max(0, (int) $operatorStatus['reconciliation_cursor_post_id'])
                : null,
        ];
    }

    /**
     * Summarize the bounded explain contract returned by relational search.
     *
     * @param array<string,mixed> $explain
     * @return array<string,mixed>
     */
    private function relational_plan_summary(array $explain): array
    {
        if (!is_scalar($explain['storage'] ?? null) || (string) $explain['storage'] === '') {
            return [];
        }

        $summary = [
            'storage' => $this->bounded_cli_text($explain['storage'], 80),
        ];
        foreach (['logical_group_count', 'resolved_alternatives', 'anchor_group', 'query_statements', 'canonical_page_bytes'] as $field) {
            if (is_numeric($explain[$field] ?? null)) {
                $summary[$field] = max(0, (int) $explain[$field]);
            }
        }
        if (array_key_exists('prefix_range', $explain)) {
            $summary['prefix_range'] = (bool) $explain['prefix_range'];
        }
        if (is_scalar($explain['prefix_strategy'] ?? null)) {
            $summary['prefix_strategy'] = $this->bounded_cli_text($explain['prefix_strategy'], 40);
        }
        if (is_scalar($explain['interactive_total'] ?? null)) {
            $summary['interactive_total'] = $this->bounded_cli_text($explain['interactive_total'], 40);
        }

        return $summary;
    }

    /**
     * @return array<string,mixed>
     */
    private function relational_recency_summary(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $summary = [];
        if (array_key_exists('enabled', $value)) {
            $summary['enabled'] = (bool) $value['enabled'];
        }
        foreach (['strength', 'half_life_days'] as $field) {
            if (is_numeric($value[$field] ?? null)) {
                $summary[$field] = (float) $value[$field];
            }
        }
        if (is_scalar($value['scoring_now_gmt'] ?? null)) {
            $scoringNow = $this->bounded_cli_text($value['scoring_now_gmt'], 40);
            if ($scoringNow !== '') {
                $summary['scoring_now_gmt'] = $scoringNow;
            }
        }

        return $summary;
    }

    /**
     * @param array<string,mixed> $summary
     * @return array<int,array{field:string,value:string}>
     */
    private function diagnostic_summary_rows(array $summary): array
    {
        $relationalPlan = is_array($summary['relational_plan'] ?? null) ? $summary['relational_plan'] : [];
        $recencyBoost = is_array($summary['recency_boost'] ?? null) ? $summary['recency_boost'] : [];
        $provider = is_array($summary['provider_compatibility'] ?? null) ? $summary['provider_compatibility'] : [];
        $languagePack = is_array($summary['runtime_language_pack_support'] ?? null) ? $summary['runtime_language_pack_support'] : [];

        $rows = [
            'returned_count' => $summary['returned_count'] ?? 0,
            'total_relation' => $summary['total_relation'] ?? 'unknown',
            'has_more' => $summary['has_more'] ?? false,
            'next_cursor_available' => $summary['next_cursor_available'] ?? false,
            'previous_cursor_available' => $summary['previous_cursor_available'] ?? false,
            'explain_available' => $summary['explain_available'] ?? false,
            'explain_unavailable_reason' => $summary['explain_unavailable_reason'] ?? '',
            'storage_backend' => $summary['storage_backend'] ?? '',
            'schema_status' => $summary['schema_status'] ?? '',
            'query_lang' => $summary['query_lang'] ?? '',
            'relational_plan' => $this->summary_parts($relationalPlan),
            'recency_boost' => $this->summary_parts($recencyBoost),
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
            'pending_queue_count_relation' => $summary['pending_queue_count_relation'] ?? 'exact',
            'remaining_count' => $summary['remaining_count'] ?? 0,
            'reconciliation_active' => $summary['reconciliation_active'] ?? false,
            'profile_reconciliation_pending' => $summary['profile_reconciliation_pending'] ?? false,
            'pending_post_work_count' => $summary['pending_post_work_count'] ?? 0,
            'pending_post_work_count_relation' => $summary['pending_post_work_count_relation'] ?? 'exact',
            'pending_scope_work_count' => $summary['pending_scope_work_count'] ?? 0,
            'pending_scope_work_count_relation' => $summary['pending_scope_work_count_relation'] ?? 'exact',
            'reconciliation_cursor_post_id' => $summary['reconciliation_cursor_post_id'] ?? null,
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

    /** Trim ordinary support queries before analyzer-level limits run. */
    private function bounded_diagnostic_query(string $query): string
    {
        $query = $this->bounded_cli_text($query, self::DIAGNOSTIC_QUERY_MAX_BYTES);
        $units = 0;
        $inUnit = false;
        $length = strlen($query);
        for ($offset = 0; $offset < $length; $offset++) {
            $byte = ord($query[$offset]);
            $isAsciiWhitespace = $byte === 32 || ($byte >= 9 && $byte <= 13);
            if ($isAsciiWhitespace) {
                $inUnit = false;
                continue;
            }
            if ($inUnit) {
                continue;
            }

            $inUnit = true;
            $units++;
            if ($units > WP_FTS_Set_Oriented_Search_Storage::MAX_QUERY_GROUPS) {
                return rtrim(substr($query, 0, $offset));
            }
        }

        return $query;
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
        if (strlen($value) > self::REINDEX_FILTER_MAX_BYTES) {
            throw new InvalidArgumentException('Reindex filters may contain at most 4,096 bytes.');
        }
        $items = [];
        foreach (explode(',', $value) as $item) {
            if (strlen($item) > self::REINDEX_FILTER_VALUE_MAX_BYTES) {
                throw new InvalidArgumentException('Each reindex filter value may contain at most 64 bytes.');
            }
            $item = trim($item);
            if ($item === '') {
                continue;
            }
            $items[$item] = true;
            if (count($items) > self::REINDEX_FILTER_MAX_VALUES) {
                throw new InvalidArgumentException('Reindex filters accept at most 32 values.');
            }
        }

        return $items === [] ? (is_array($fallback) ? $fallback : [$fallback]) : array_keys($items);
    }

    /** Parse a public search filter without first expanding an unbounded CSV. */
    private function search_csv_arg(mixed $value, string $name): array
    {
        if (!is_scalar($value) || strlen((string) $value) > self::SEARCH_FILTER_MAX_BYTES) {
            throw new InvalidArgumentException("Search {$name} filters may contain at most 4,096 bytes.");
        }

        $items = [];
        foreach (explode(',', (string) $value) as $item) {
            if (strlen($item) > self::SEARCH_FILTER_VALUE_MAX_BYTES) {
                throw new InvalidArgumentException("Each search {$name} filter may contain at most 64 bytes.");
            }
            $item = trim($item);
            if ($item === '') {
                continue;
            }
            $items[$item] = true;
            if (count($items) > self::SEARCH_FILTER_MAX_VALUES) {
                throw new InvalidArgumentException("Search {$name} filters accept at most 32 values.");
            }
        }

        return $items === [] ? [''] : array_keys($items);
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
            'runtime_compression' => ['runtime-compression', 'runtime_compression'],
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
        if (!is_scalar($value)) {
            throw new InvalidArgumentException('Language options must be scalar values.');
        }
        $language = (string) $value;
        if (strlen($language) > self::SEARCH_LANGUAGE_MAX_BYTES) {
            throw new InvalidArgumentException('Language options may contain at most 64 bytes.');
        }
        if (trim($language) !== '') {
            return WP_FTS_TermNamespace::canonicalize_lang($language);
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
