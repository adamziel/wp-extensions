<?php
declare(strict_types=1);

/**
 * WP-CLI command surface for managing the custom FTS index.
 *
 * The command creates relational index tables on demand, reindexes WordPress posts,
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
    private const SEARCH_CURSOR_MAX_BYTES = 2048;
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
     * @param array<string,mixed> $assoc_args WP-CLI options.
     */
    public function reindex(array $args, array $assoc_args): void
    {
        $lang = array_key_exists('lang', $assoc_args)
            ? $this->language_arg($assoc_args['lang'])
            : null;
        $postStatuses = array_key_exists('post_status', $assoc_args)
            ? $this->csv_arg($assoc_args['post_status'], 'post status')
            : self::DEFAULT_REINDEX_POST_STATUSES;
        $postTypes = array_key_exists('post_type', $assoc_args)
            ? $this->csv_arg($assoc_args['post_type'], 'post type')
            : ['post'];
        sort($postStatuses, SORT_STRING);
        sort($postTypes, SORT_STRING);
        $requestedLimit = $this->non_negative_int_arg($this->assoc_arg($assoc_args, 'limit', 0), '--limit');
        $options = [
            'post_status' => $postStatuses,
            'post_type' => $postTypes,
            'limit' => $requestedLimit,
        ];
        if ($lang !== null) {
            $options['document_lang'] = $lang;
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
     * [--recency_boost_strength=<strength>]
     * : Add a bounded query-time ranking lift for newer indexed post dates. Use 0 to disable.
     *
     * [--recency_boost_half_life_days=<days>]
     * : Positive half-life in days for the recency lift. Default: searcher default.
     *
     * [--prefix_matching]
     * : Enable word-beginning matching for this CLI search.
     *
     * [--prefix_min_length=<n>]
     * : Minimum analyzed term length before word-beginning expansion.
     *
     * [--cursor=<cursor>]
     * : Opaque cursor returned by an earlier page.
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
        $query = $this->query_arg($args);
        $format = (string) $this->assoc_arg($assoc_args, 'format', 'table');
        $searchOptions = $this->search_options_from_cli_args($assoc_args);
        $explain = $this->bool_flag_arg($assoc_args, 'explain', false);

        /** @var array{has_more:bool,next_cursor:?string,previous_cursor:?string,results:array<int,array<string,mixed>>} $payload */
        $payload = $explain
            ? WP_FTS_Plugin::search_with_explain($query, $searchOptions)
            : WP_FTS_Plugin::search_page($query, $searchOptions);
        if ($format === 'json') {
            $this->line($this->json_payload($payload));
            return;
        }

        $results = $payload['results'];
        foreach ($results as &$row) {
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

        $fields = ['doc_id', 'score', 'post_id', 'post_type', 'post_status', 'post_date_gmt', 'title', 'has_more', 'next_cursor', 'previous_cursor'];
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
     * [--recency_boost_strength=<strength>]
     * : Add a bounded query-time ranking lift for newer indexed post dates. Use 0 to disable.
     *
     * [--recency_boost_half_life_days=<days>]
     * : Positive half-life in days for the recency lift. Default: searcher default.
     *
     * [--prefix_matching]
     * : Enable word-beginning matching for this CLI search.
     *
     * [--prefix_min_length=<n>]
     * : Minimum analyzed term length before word-beginning expansion.
     *
     * [--cursor=<cursor>]
     * : Opaque cursor returned by an earlier page.
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
        $rawQuery = $this->query_arg($args);
        $normalizedQuery = $this->bounded_cli_text($rawQuery, 0);
        $query = $this->bounded_diagnostic_query($normalizedQuery);
        $format = (string) $this->assoc_arg($assoc_args, 'format', 'json');
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
            /** @var array{has_more:bool,next_cursor:?string,previous_cursor:?string,query_lang:string,results:array<int,array<string,mixed>>} $searchPayload */
            $searchPayload = WP_FTS_Plugin::search_with_explain($query, $searchOptions);
        } catch (WP_FTS_Search_Budget_Exceeded $error) {
            // A diagnostic command must still describe an input that the
            // fixed relational plan rejects. The rejection happens before an
            // over-wide query reaches storage, and the bundle records the
            // stable bound instead of silently searching a different query.
            $searchPayload = [
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
     * : Inspect one failed post record.
     *
     * [--limit=<n>]
     * : Maximum recent records to include. Default: 10.
     *
     * [--format=<format>]
     * : Output format. Default: table. Supports json for automation.
     *
     * @subcommand failed-items
     * @param string[] $args Positional arguments; unused.
     * @param array<string,mixed> $assoc_args WP-CLI options.
     */
    public function failed_items(array $args, array $assoc_args): void
    {
        $postId = $this->non_negative_int_arg($this->assoc_arg($assoc_args, 'post_id', 0), '--post_id');
        $limit = $this->positive_int_arg($this->assoc_arg($assoc_args, 'limit', 10), '--limit');

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
     * @param string[] $args Optional first positional argument is the post id.
     * @param array<string,mixed> $assoc_args WP-CLI options.
     */
    public function retry_failed_item(array $args, array $assoc_args): void
    {
        $postId = array_key_exists(0, $args) ? $this->non_negative_int_arg($args[0], 'post_id') : 0;
        $all = $this->bool_flag_arg($assoc_args, 'all', false);
        $limit = $all ? $this->positive_int_arg($this->assoc_arg($assoc_args, 'limit', 10), '--limit') : 1;
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
     * @param string[] $args Optional first positional argument is the post id.
     * @param array<string,mixed> $assoc_args WP-CLI options.
     */
    public function clear_failed_item(array $args, array $assoc_args): void
    {
        $postId = array_key_exists(0, $args) ? $this->non_negative_int_arg($args[0], 'post_id') : 0;
        $all = $this->bool_flag_arg($assoc_args, 'all', false);
        $limit = $all ? $this->positive_int_arg($this->assoc_arg($assoc_args, 'limit', 10), '--limit') : 1;
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
     * @param string[] $args Positional arguments; unused.
     * @param array<string,mixed> $assoc_args WP-CLI options.
     */
    public function reset_index(array $args, array $assoc_args): void
    {
        if (!$this->bool_flag_arg($assoc_args, 'yes', false)) {
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

        $locked = WP_FTS_Plugin::reset_index_for_operator();
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
     * : Maximum posts to process in this command. Default: 100. Maximum: 1000.
     *
     * [--time_budget=<seconds>]
     * : Optional positive wall-clock budget. Maximum: 300. Omit it to supply no CLI deadline.
     *
     * [--format=<format>]
     * : Output format. Default: table. Supports json for automation.
     *
     * @subcommand process-batch
     * @param string[] $args Positional arguments; unused.
     * @param array<string,mixed> $assoc_args WP-CLI options.
     */
    public function process_batch(array $args, array $assoc_args): void
    {
        $options = ['source' => 'wp-cli'];
        if (array_key_exists('batch_size', $assoc_args)) {
            $batchSize = $this->positive_int_arg($assoc_args['batch_size'], '--batch_size');
            if ($batchSize > WP_FTS_Plugin::MAX_MANUAL_INDEX_BATCH_SIZE) {
                throw new InvalidArgumentException('--batch_size must be at most 1000.');
            }
            $options['batch_size'] = $batchSize;
        }

        if (array_key_exists('time_budget', $assoc_args)) {
            $timeBudget = $this->positive_float_arg($assoc_args['time_budget'], '--time_budget');
            if ($timeBudget > WP_FTS_Plugin::MAX_MANUAL_INDEX_TIME_BUDGET_SECONDS) {
                throw new InvalidArgumentException('--time_budget must be at most 300 seconds.');
            }
            $options['time_budget'] = $timeBudget;
        }

        $summary = WP_FTS_Plugin::process_manual_index_batch($options);
        $health = WP_FTS_Plugin::search_health();
        $this->output_assoc([
            'mode' => is_scalar($summary['mode'] ?? null) ? (string) $summary['mode'] : 'manual',
            'batch_size' => max(0, (int) ($summary['batch_size'] ?? 0)),
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
        $docId = array_key_exists(0, $args) ? $this->positive_int_arg($args[0], 'doc_id') : 0;
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
        $locked = WP_FTS_Plugin::optimize_for_operator();
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
     * : Language tag for the generated pack.
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
     * [--out=<path>]
     * : Output pack directory. Defaults under uploads/wp-fts-lemma-packs/<pack-id>.
     *
     * [--enable]
     * : Enable the generated manifest for runtime indexing/search. Reindex existing content afterwards.
     *
     * @subcommand import-lemma-pack
     * @param string[] $args Positional arguments; unused.
     * @param array<string,mixed> $assoc_args WP-CLI options.
     */
    public function import_lemma_pack(array $args, array $assoc_args): void
    {
        require_once dirname(__DIR__) . '/tools/import-lemma-tsv-pack.php';

        $enable = $this->bool_flag_arg($assoc_args, 'enable', false);
        $options = $this->lemma_pack_import_options($assoc_args);
        $summary = (new WP_FTS_LemmaTsvPackImporter())->import($options);
        $manifestPath = isset($summary['manifest']) && is_scalar($summary['manifest'])
            ? (string) $summary['manifest']
            : '';
        if ($manifestPath === '') {
            throw new RuntimeException('Lemma pack importer did not return a manifest path.');
        }

        $validation = (new WP_FTS_AnalyzerPackValidator())->validate($manifestPath);
        $manifest = $validation['manifest'];
        $language = (string) $manifest['language'];
        $packId = (string) $manifest['pack_id'];

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
     * : Language tag for the generated pack.
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
     * [--out=<path>]
     * : Output pack directory. Defaults under uploads/wp-fts-lemma-packs/<pack-id>.
     *
     * [--enable]
     * : Enable the generated manifest for runtime indexing/search. Reindex existing content afterwards.
     *
     * @subcommand import-conllu-lemma-pack
     * @param string[] $args Positional arguments; unused.
     * @param array<string,mixed> $assoc_args WP-CLI options.
     */
    public function import_conllu_lemma_pack(array $args, array $assoc_args): void
    {
        require_once dirname(__DIR__) . '/tools/import-conllu-lemma-pack.php';

        $enable = $this->bool_flag_arg($assoc_args, 'enable', false);
        $options = $this->lemma_pack_import_options($assoc_args);
        $summary = (new WP_FTS_ConlluLemmaPackImporter())->import($options);
        $manifestPath = isset($summary['manifest']) && is_scalar($summary['manifest'])
            ? (string) $summary['manifest']
            : '';
        if ($manifestPath === '') {
            throw new RuntimeException('CoNLL-U lemma pack importer did not return a manifest path.');
        }

        $validation = (new WP_FTS_AnalyzerPackValidator())->validate($manifestPath);
        $manifest = $validation['manifest'];
        $language = (string) $manifest['language'];
        $packId = (string) $manifest['pack_id'];

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
     * : Language tag for the generated pack.
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
     * [--out=<path>]
     * : Output pack directory. Defaults under uploads/wp-fts-lemma-packs/<pack-id>.
     *
     * [--enable]
     * : Enable the generated manifest for runtime indexing/search. Reindex existing content afterwards.
     *
     * @subcommand import-unimorph-lemma-pack
     * @param string[] $args Positional arguments; unused.
     * @param array<string,mixed> $assoc_args WP-CLI options.
     */
    public function import_unimorph_lemma_pack(array $args, array $assoc_args): void
    {
        require_once dirname(__DIR__) . '/tools/import-unimorph-lemma-pack.php';

        $enable = $this->bool_flag_arg($assoc_args, 'enable', false);
        $options = $this->lemma_pack_import_options($assoc_args);
        $summary = (new WP_FTS_UnimorphLemmaPackImporter())->import($options);
        $manifestPath = isset($summary['manifest']) && is_scalar($summary['manifest'])
            ? (string) $summary['manifest']
            : '';
        if ($manifestPath === '') {
            throw new RuntimeException('UniMorph lemma pack importer did not return a manifest path.');
        }

        $validation = (new WP_FTS_AnalyzerPackValidator())->validate($manifestPath);
        $manifest = $validation['manifest'];
        $language = (string) $manifest['language'];
        $packId = (string) $manifest['pack_id'];

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
        $format = (string) $this->assoc_arg($assoc_args, 'format', 'table');
        if ($format === 'json') {
            $this->line($this->json_payload($data));
            return;
        }

        $rows = [];
        foreach ($data as $field => $value) {
            if (
                is_array($value)
                && in_array($field, ['search_provider_compatibility', 'language_pack_status'], true)
            ) {
                continue;
            }
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
     * Add concise human-table rows for the nested provider status block.
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
            'search_provider_compatibility_public_site_replacement' => !empty($compatibility['public_site_replacement_enabled']) ? 'enabled' : 'disabled',
            'search_provider_compatibility_admin_posts_replacement' => !empty($compatibility['admin_posts_replacement_enabled']) ? 'enabled' : 'disabled',
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
     * Add concise human-table rows for the nested language-pack status block.
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

        $runtime = is_array($status['runtime_support'] ?? null) ? $status['runtime_support'] : [];
        $runtimeSupport = $this->bounded_cli_text($runtime['label'] ?? '', 120);
        $matchedLanguage = $this->bounded_cli_text($runtime['matched_language_label'] ?? '', 80);
        if ($matchedLanguage !== '') {
            $runtimeSupport = trim($runtimeSupport . ' via ' . $matchedLanguage);
        }

        $gzipStatus = $this->bounded_cli_text($status['gzip_status'] ?? '', 40);
        $availability = $this->bounded_cli_text($status['runtime_pack_availability'] ?? '', 180);
        if ($availability !== '') {
            $gzipStatus = trim($gzipStatus . ': ' . $availability);
        }

        $fields = [
            'language_pack_site_language' => $status['site_language_label'] ?? '',
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
        $modeArg = $this->assoc_arg($assoc_args, 'mode', 'OR');
        if (!is_string($modeArg) || !in_array($modeArg, ['OR', 'AND'], true)) {
            throw new InvalidArgumentException('Search mode must be exactly OR or AND.');
        }
        $searchOptions = [
            'mode' => $modeArg,
            'limit' => $this->search_integer_arg(
                $this->assoc_arg($assoc_args, 'limit', 10),
                'limit',
                1,
                WP_FTS_Plugin::MAX_SEARCH_LIMIT
            ),
            'include_metadata' => true,
            'include_snippets' => $this->bool_flag_arg($assoc_args, 'snippet', false),
        ];
        $this->bool_flag_arg($assoc_args, 'explain', false);
        if (array_key_exists('lang', $assoc_args)) {
            $searchOptions['lang'] = $this->language_arg($assoc_args['lang']);
        }

        if (array_key_exists('post_status', $assoc_args)) {
            $searchOptions['post_statuses'] = $this->search_csv_arg($assoc_args['post_status'], 'post status');
        }
        if (array_key_exists('post_type', $assoc_args)) {
            $searchOptions['post_types'] = $this->search_csv_arg($assoc_args['post_type'], 'post type');
        }
        if (array_key_exists('after', $assoc_args)) {
            $after = $assoc_args['after'];
            if (!is_string($after) || trim($after) === '' || trim($after) !== $after || strlen($after) > self::SEARCH_FILTER_VALUE_MAX_BYTES) {
                throw new InvalidArgumentException('Search after dates must be non-empty strings containing at most 64 bytes.');
            }
            $searchOptions['date_after'] = $after;
        }
        if (array_key_exists('before', $assoc_args)) {
            $before = $assoc_args['before'];
            if (!is_string($before) || trim($before) === '' || trim($before) !== $before || strlen($before) > self::SEARCH_FILTER_VALUE_MAX_BYTES) {
                throw new InvalidArgumentException('Search before dates must be non-empty strings containing at most 64 bytes.');
            }
            $searchOptions['date_before'] = $before;
        }
        if (array_key_exists('recency_boost_strength', $assoc_args)) {
            $searchOptions['recency_boost_strength'] = $this->search_float_arg($assoc_args['recency_boost_strength'], 'recency boost strength');
        }
        if (array_key_exists('recency_boost_half_life_days', $assoc_args)) {
            $searchOptions['recency_boost_half_life_days'] = $this->search_float_arg($assoc_args['recency_boost_half_life_days'], 'recency boost half-life');
        }
        if (array_key_exists('prefix_matching', $assoc_args)) {
            $searchOptions['prefix_matching'] = $this->explicit_cli_boolean($assoc_args['prefix_matching'], 'Search prefix matching');
        }
        if (array_key_exists('prefix_min_length', $assoc_args)) {
            $prefixMinLength = $assoc_args['prefix_min_length'];
            $prefixMinLength = $this->search_integer_arg(
                $prefixMinLength,
                'prefix minimum length',
                1,
                PHP_INT_MAX
            );
            if (WP_FTS_Plugin::sanitize_prefix_min_length($prefixMinLength) !== $prefixMinLength) {
                throw new InvalidArgumentException('Search prefix minimum length is outside the supported range.');
            }
            $searchOptions['prefix_min_length'] = $prefixMinLength;
        }
        if (array_key_exists('cursor', $assoc_args)) {
            $cursor = $assoc_args['cursor'];
            if (!is_string($cursor)
                || trim($cursor) === ''
                || trim($cursor) !== $cursor
                || strlen($cursor) > self::SEARCH_CURSOR_MAX_BYTES
            ) {
                throw new InvalidArgumentException('Search cursor must be an unpadded non-empty string containing at most 2,048 bytes.');
            }
            $searchOptions['cursor'] = $cursor;
        }
        $directionSupplied = array_key_exists('direction', $assoc_args);
        if ($directionSupplied && !isset($searchOptions['cursor'])) {
            throw new InvalidArgumentException('--direction requires --cursor.');
        }
        $direction = $directionSupplied ? $assoc_args['direction'] : 'after';
        if (!is_string($direction) || !in_array($direction, ['after', 'before'], true)) {
            throw new InvalidArgumentException('Search cursor direction must be exactly after or before.');
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
            'post_statuses' => 'post_status',
            'post_types' => 'post_type',
            'date_after' => 'after',
            'date_before' => 'before',
            'recency_boost_strength' => 'recency_boost_strength',
            'recency_boost_half_life_days' => 'recency_boost_half_life_days',
            'prefix_matching' => 'prefix_matching',
            'prefix_min_length' => 'prefix_min_length',
            'cursor' => 'cursor',
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
        $lock = is_array($operatorStatus['lock'] ?? null) ? $operatorStatus['lock'] : [];
        $schedule = is_array($operatorStatus['queue_processor_schedule'] ?? null)
            ? $operatorStatus['queue_processor_schedule']
            : [];
        $explainStorage = is_scalar($explain['storage'] ?? null) ? (string) $explain['storage'] : '';
        $pendingWork = !empty($operatorStatus['has_more'])
            || max(0, (int) ($operatorStatus['pending_queue_count'] ?? 0)) > 0
            || max(0, (int) ($operatorStatus['pending_post_work_count'] ?? 0)) > 0
            || max(0, (int) ($operatorStatus['pending_scope_work_count'] ?? 0)) > 0
            || !empty($operatorStatus['reconciliation_active'])
            || !empty($operatorStatus['profile_reconciliation_pending'])
            || !empty($schedule['pending_work']);
        $schemaStatus = is_scalar($operatorStatus['schema_status'] ?? null) ? (string) $operatorStatus['schema_status'] : '';

        return [
            'returned_count' => count($results),
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
                'status' => $this->bounded_cli_text($runtimeSupport['status'] ?? '', 80),
                'label' => $this->bounded_cli_text($runtimeSupport['label'] ?? '', 120),
                'full' => (bool) ($runtimeSupport['full'] ?? false),
                'reason' => $this->bounded_cli_text($runtimeSupport['reason'] ?? '', self::DIAGNOSTIC_SUMMARY_MAX_BYTES),
                'matched_language' => $this->bounded_cli_text($runtimeSupport['matched_language'] ?? '', 40),
            ],
            'lock_state' => $this->bounded_cli_text($lock['state'] ?? '', 40),
            'lock_active' => (bool) ($lock['active'] ?? false),
            'index_stale' => $schemaStatus !== 'current' || !empty($operatorStatus['reconciliation_active']),
            'pending_work' => $pendingWork,
            'pending_queue_count' => max(0, (int) ($operatorStatus['pending_queue_count'] ?? 0)),
            'pending_queue_count_relation' => $this->bounded_cli_text($operatorStatus['pending_queue_count_relation'] ?? 'exact', 20),
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

        $text = WP_FTS_Utf8::repair($text);
        $text = trim(str_replace(["\r", "\n", "\t"], ' ', $text));
        $text = preg_replace('/\s+/', ' ', $text) ?? $text;
        if ($maxBytes <= 0 || strlen($text) <= $maxBytes) {
            return $text;
        }

        return rtrim(WP_FTS_Utf8::truncate_bytes($text, max(0, $maxBytes - 3))) . '...';
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

    /** Parse a supplied comma-separated reindex filter into a non-empty list. */
    private function csv_arg(mixed $value, string $name): array
    {
        if (!is_string($value) || trim($value) === '') {
            throw new InvalidArgumentException("Reindex {$name} filters must be a non-empty string.");
        }
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
                throw new InvalidArgumentException("Reindex {$name} filters must not contain empty values.");
            }
            $items[$item] = true;
            if (count($items) > self::REINDEX_FILTER_MAX_VALUES) {
                throw new InvalidArgumentException('Reindex filters accept at most 32 values.');
            }
        }

        return array_keys($items);
    }

    /** Parse a public search filter without first expanding an unbounded CSV. */
    private function search_csv_arg(mixed $value, string $name): array
    {
        if (!is_string($value) || trim($value) === '' || strlen($value) > self::SEARCH_FILTER_MAX_BYTES) {
            throw new InvalidArgumentException("Search {$name} filters may contain at most 4,096 bytes.");
        }

        $items = [];
        foreach (explode(',', $value) as $item) {
            if (strlen($item) > self::SEARCH_FILTER_VALUE_MAX_BYTES) {
                throw new InvalidArgumentException("Each search {$name} filter may contain at most 64 bytes.");
            }
            $item = trim($item);
            if ($item === '') {
                throw new InvalidArgumentException("Search {$name} filters must not contain empty values.");
            }
            $items[$item] = true;
            if (count($items) > self::SEARCH_FILTER_MAX_VALUES) {
                throw new InvalidArgumentException("Search {$name} filters accept at most 32 values.");
            }
        }

        return array_keys($items);
    }

    /** Parse a finite numeric WP-CLI search option before entering the PHP facade. */
    private function search_float_arg(mixed $value, string $name): float
    {
        if (is_string($value)) {
            if (strlen($value) > 64 || !$this->is_canonical_decimal($value)) {
                throw new InvalidArgumentException("Search {$name} must be a finite number.");
            }
            $number = (float) $value;
        } elseif (is_int($value) || is_float($value)) {
            $number = (float) $value;
        } else {
            throw new InvalidArgumentException("Search {$name} must be a finite number.");
        }
        if (!is_finite($number)) {
            throw new InvalidArgumentException("Search {$name} must be a finite number.");
        }

        return $number;
    }

    /** Parse one exact integer for the strict PHP search facade. */
    private function search_integer_arg(mixed $value, string $name, int $minimum, int $maximum): int
    {
        if (is_string($value)) {
            if (
                $value === ''
                || strlen($value) > 64
                || strspn($value, '0123456789') !== strlen($value)
                || (strlen($value) > 1 && $value[0] === '0')
            ) {
                throw new InvalidArgumentException("Search {$name} must be a canonical decimal integer.");
            }
            $value = (int) $value;
        }
        if (!is_int($value) || $value < $minimum || $value > $maximum) {
            throw new InvalidArgumentException("Search {$name} must be an integer from {$minimum} through {$maximum}.");
        }

        return $value;
    }

    /** @param array<string,mixed> $assoc_args */
    private function bool_flag_arg(array $assoc_args, string $name, bool $default): bool
    {
        if (!array_key_exists($name, $assoc_args)) {
            return $default;
        }

        return $this->explicit_cli_boolean($assoc_args[$name], "--{$name}");
    }

    /** Parse only explicit boolean spellings accepted by WP-CLI. */
    private function explicit_cli_boolean(mixed $value, string $name): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_int($value) && ($value === 0 || $value === 1)) {
            return $value === 1;
        }
        if (is_string($value) && strlen($value) <= 16) {
            $value = strtolower($value);
            if (in_array($value, ['1', 'true', 'yes', 'on'], true)) {
                return true;
            }
            if (in_array($value, ['0', 'false', 'no', 'off'], true)) {
                return false;
            }
        }

        throw new InvalidArgumentException("{$name} must be an explicit boolean.");
    }

    /** Return one associated argument or its default. */
    private function assoc_arg(array $assoc_args, string $name, mixed $default): mixed
    {
        return array_key_exists($name, $assoc_args) ? $assoc_args[$name] : $default;
    }

    /** Require exactly one native-string query positional argument. */
    private function query_arg(array $args): string
    {
        if (array_keys($args) !== [0] || !is_string($args[0])) {
            throw new InvalidArgumentException('Search commands require exactly one string query argument.');
        }

        return $args[0];
    }

    /**
     * Build importer options without letting absent --out
     * leak into the lower-level importer as a missing required argument.
     *
     * @param array<string,mixed> $assoc_args
     * @return array<string,mixed>
     */
    private function lemma_pack_import_options(array $assoc_args): array
    {
        $packId = $this->required_assoc_string($assoc_args, 'pack-id', 'pack-id');
        $sourceName = $this->required_assoc_string($assoc_args, 'source-name', 'source-name');

        $options = [
            'source' => $this->required_assoc_string($assoc_args, 'source', 'source'),
            'language' => $this->required_assoc_string($assoc_args, 'language', 'language'),
            'pack_id' => $packId,
            'version' => $this->required_assoc_string($assoc_args, 'version', 'version'),
            'source_name' => $sourceName,
            'source_url' => $this->required_assoc_string($assoc_args, 'source-url', 'source-url'),
            'license' => $this->required_assoc_string($assoc_args, 'license', 'license'),
            'attribution' => $this->optional_assoc_string($assoc_args, 'attribution', $sourceName),
        ];

        $options['out'] = array_key_exists('out', $assoc_args)
            ? $this->optional_assoc_string($assoc_args, 'out', '')
            : $this->default_lemma_pack_output_dir($packId);

        foreach ([
            'license_url' => 'license-url',
            'source_version' => 'source-version',
            'tmp_dir' => 'tmp-dir',
        ] as $importerKey => $cliName) {
            if (array_key_exists($cliName, $assoc_args)) {
                $options[$importerKey] = $this->optional_assoc_string($assoc_args, $cliName, '');
            }
        }
        foreach ([
            'max_rows_per_file' => 'max-rows-per-file',
            'chunk_rows' => 'chunk-rows',
        ] as $importerKey => $cliName) {
            if (array_key_exists($cliName, $assoc_args)) {
                $options[$importerKey] = $this->positive_int_arg($assoc_args[$cliName], "--{$cliName}");
            }
        }

        return $options;
    }

    /**
     * @param array<string,mixed> $assoc_args
     */
    private function required_assoc_string(array $assoc_args, string $name, string $displayName): string
    {
        $value = $this->assoc_arg($assoc_args, $name, null);
        if (!is_string($value) || trim($value) === '') {
            throw new RuntimeException("Missing required option --{$displayName}.");
        }

        return $value;
    }

    /**
     * @param array<string,mixed> $assoc_args
     */
    private function optional_assoc_string(array $assoc_args, string $name, string $default): string
    {
        if (!array_key_exists($name, $assoc_args)) {
            return $default;
        }
        $value = $assoc_args[$name];
        if (!is_string($value) || trim($value) === '') {
            throw new InvalidArgumentException("--{$name} must be a non-empty string.");
        }

        return $value;
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
     * @param mixed $value Raw WP-CLI option value.
     * @return string Canonical language partition.
     */
    private function language_arg(mixed $value): string
    {
        return WP_FTS_TermNamespace::parse_language_tag($value);
    }

    /** Parse a canonical positive WP-CLI integer. */
    private function positive_int_arg(mixed $value, string $name): int
    {
        return $this->unsigned_int_arg($value, $name, 1);
    }

    /** Parse a canonical nonnegative WP-CLI integer. */
    private function non_negative_int_arg(mixed $value, string $name): int
    {
        return $this->unsigned_int_arg($value, $name, 0);
    }

    /** Parse only a finite positive WP-CLI decimal. */
    private function positive_float_arg(mixed $value, string $name): float
    {
        if (is_string($value)) {
            if (strlen($value) > 64 || !$this->is_canonical_decimal($value)) {
                throw new InvalidArgumentException("{$name} must be a canonical positive decimal.");
            }
            $number = (float) $value;
        } elseif (is_int($value) || is_float($value)) {
            $number = (float) $value;
        } else {
            throw new InvalidArgumentException("{$name} must be a canonical positive decimal.");
        }
        if (!is_finite($number) || $number <= 0.0) {
            throw new InvalidArgumentException("{$name} must be a canonical positive decimal.");
        }

        return $number;
    }

    /** Accept unsigned decimal notation without signs, exponents, or padding. */
    private function is_canonical_decimal(string $value): bool
    {
        if ($value === '') {
            return false;
        }
        $dot = strpos($value, '.');
        if ($dot === false) {
            $integer = $value;
            $fraction = null;
        } else {
            if ($dot === 0 || $dot === strlen($value) - 1 || strpos($value, '.', $dot + 1) !== false) {
                return false;
            }
            $integer = substr($value, 0, $dot);
            $fraction = substr($value, $dot + 1);
        }
        if (strspn($integer, '0123456789') !== strlen($integer)
            || ($fraction !== null && strspn($fraction, '0123456789') !== strlen($fraction))
        ) {
            return false;
        }

        return strlen($integer) === 1 || $integer[0] !== '0';
    }

    /** Parse a canonical unsigned decimal string or native integer. */
    private function unsigned_int_arg(mixed $value, string $name, int $minimum): int
    {
        if (is_string($value)) {
            if ($value === ''
                || strlen($value) > 20
                || strspn($value, '0123456789') !== strlen($value)
                || (strlen($value) > 1 && $value[0] === '0')
            ) {
                throw new InvalidArgumentException("{$name} must be a canonical unsigned decimal integer.");
            }
            $number = (int) $value;
            if ((string) $number !== $value) {
                throw new InvalidArgumentException("{$name} exceeds the supported integer range.");
            }
        } elseif (is_int($value)) {
            $number = $value;
        } else {
            throw new InvalidArgumentException("{$name} must be a canonical unsigned decimal integer.");
        }
        if ($number < $minimum) {
            throw new InvalidArgumentException("{$name} must be at least {$minimum}.");
        }

        return $number;
    }
}
