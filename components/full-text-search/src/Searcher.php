<?php
declare(strict_types=1);

/**
 * Signals a caller-configured search resource limit before more work is read.
 */
final class WP_FTS_Search_Budget_Exceeded extends RuntimeException
{
    public function __construct(private string $budget)
    {
        parent::__construct("Search request exceeded its {$budget} budget.");
    }

    public function budget(): string
    {
        return $this->budget;
    }
}

/**
 * Searches a relational set-oriented index in production.
 *
 * `for_set_oriented_storage()` is the production boundary. The public
 * constructor remains only for the component's legacy File/InMemory fixtures;
 * WordPress runtime code must never use that posting-list implementation.
 */
final class WP_FTS_Searcher
{
    private const DEFAULT_FAST_MODE_CANDIDATE_CAP = 1000;
    private const DEFAULT_PREFIX_MIN_LENGTH = 4;
    private const DEFAULT_PREFIX_MAX_TERMS = 64;
    private const DEFAULT_MAX_QUERY_TERMS = 1024;
    private const DEFAULT_MAX_PREFIX_EXPANSIONS = 256;
    private const DEFAULT_MAX_CANDIDATE_ROWS = 100000;
    private const MAX_SET_ORIENTED_QUERY_BYTES = 4096;
    private const MAX_SET_ORIENTED_MODE_BYTES = 8;
    private const MAX_SET_ORIENTED_LANGUAGE_BYTES = 64;
    private const MAX_SET_ORIENTED_CURSOR_BYTES = 2048;
    private const MAX_SET_ORIENTED_OCCURRENCE_TEXT_BYTES = 4096;
    private const MAX_SET_ORIENTED_OCCURRENCE_POSITION_BYTES = 64;
    private const MAX_SET_ORIENTED_OCCURRENCE_RANK_BYTES = 32;
    private const MAX_SET_ORIENTED_SWITCH_BYTES = 16;
    private const MAX_SET_ORIENTED_NUMERIC_BYTES = 64;
    private const MAX_SET_ORIENTED_FILTER_VALUES = 32;
    private const MAX_SET_ORIENTED_FILTER_VALUE_BYTES = 64;
    private const MAX_SET_ORIENTED_FILTER_BYTES = 4096;
    private const MAX_SET_ORIENTED_SNIPPET_LENGTH = 500;
    private const MAX_SET_ORIENTED_SNIPPET_SOURCE_BYTES = 20000;
    private const MAX_PUBLIC_OPTION_KEYS = 64;
    private const MAX_PUBLIC_OPTION_NODES = 512;
    private const MAX_PUBLIC_OPTION_BYTES = 65536;
    private const MAX_SNIPPET_ANALYSIS_SOURCE_BYTES = 2048;
    private const MAX_SNIPPET_ANALYSIS_OCCURRENCES = 3072;
    private const MAX_SNIPPET_ANALYSIS_LANGUAGES = 2;
    private const BUDGET_GUARD_INTERVAL = 256;
    private const EXPLAIN_MAX_TERMS = 12;
    private const EXPLAIN_MAX_RESULT_ROWS = 20;
    private const EXPLAIN_MAX_MATCHES_PER_RESULT = 8;
    private const EXPLAIN_MAX_FIELD_MATCHES_PER_RESULT = 6;
    private const EXPLAIN_MAX_TERMS_PER_FIELD = 6;
    private const EXPLAIN_MAX_TEXT_BYTES = 96;
    private const EXPLAIN_MAX_REASON_BYTES = 240;
    private const DEFAULT_RECENCY_BOOST_STRENGTH = 0.25;
    private const MAX_RECENCY_BOOST_STRENGTH = 2.0;
    private const DEFAULT_RECENCY_BOOST_HALF_LIFE_DAYS = 30.0;
    private const MIN_RECENCY_BOOST_HALF_LIFE_DAYS = 1.0;
    private const MAX_RECENCY_BOOST_HALF_LIFE_DAYS = 3650.0;
    private const SNIPPET_TOKEN_PATTERN = '/[\p{L}\p{M}\p{N}_]+/u';
    /** @var callable|null */
    private $activeRequestBudgetGuard = null;
    /** @var array<string,mixed> */
    private array $activeSearchOptions = [];

    /**
     * Last full ranking retained for explicit same-request pagination reuse.
     *
     * @var array{key:string,results:array<int,array{doc_id:int,score:float,_rank:int}>,score_stats:array<string,int>,recency_stats:array<string,mixed>}|null
     */
    private ?array $rankedResultCache = null;

    /**
     * Construct the only searcher shape supported by production WordPress.
     *
     * The parameter type makes a future storage-factory change fail closed
     * instead of silently reactivating PHP posting-list ranking.
     */
    public static function for_set_oriented_storage(
        WP_FTS_Set_Oriented_Search_Storage $storage,
        object $analyzer,
    ): self {
        return new self($storage, $analyzer);
    }

    /**
     * @param WP_FTS_Storage|WP_FTS_Set_Oriented_Search_Storage $storage
     *        Relational production storage, or a legacy File/InMemory backend
     *        used only by component fixtures.
     * @param object $analyzer Analyzer object exposing query analysis methods.
     * @param float $k1 BM25 term-frequency saturation parameter.
     * @param float $b BM25 document-length normalization parameter.
     */
    public function __construct(
        private WP_FTS_Storage|WP_FTS_Set_Oriented_Search_Storage $storage,
        private object $analyzer,
        private float $k1 = 1.2,
        private float $b = 0.75,
    ) {
    }

    /**
     * Search the index for documents matching a query.
     *
     * `mode` may be `OR` or `AND`; `AND` requires every logical query term to
     * have a posting for a document. Legacy storage clamps `limit` to at least
     * 1 and uses `offset` for pagination. Set-oriented storage instead clamps
     * `limit` to 1..50, rejects positive offsets, and uses opaque cursors.
     * Language can be supplied with `query_lang`, `lang`, or `language` for the
     * legacy single-partition path. `langs` or
     * `languages` accepts a list of exact language partitions. Fallback search is
     * opt-in via `language_fallback`, `fallback_to_default_lang`,
     * `fallback_lang`, or `fallback_languages`, and can be disabled with
     * `disable_language_fallback`.
     *
     * Product options are opt-in to preserve the legacy return shape:
     * `include_total` returns a payload with `total`, `limit`, `offset`, and
     * `results`; `include_metadata` adds WordPress result fields; and
     * `include_snippets` builds bounded snippets from stored extracted text.
     * Snippets are safe HTML containing escaped text and internally generated
     * `<mark>` elements only; source markup is never returned.
     * `post_type`, `post_status`, `date_after`, and `date_before` filter only
     * when the storage backend exposes document metadata. Search is exact by
     * default. `fast_top_k` or `approximate_top_k` explicitly opts into a
     * document-id-ordered candidate cap that can trade recall, ranking, and
     * total-count accuracy for latency. Candidate-capped searches always return
     * a payload that exposes their incomplete-result risk, even when
     * `include_total` is omitted. Word-beginning prefix expansion can be
     * controlled with `prefix_matching`; phrase search requires a
     * `search_extension` callback for storage-specific matching.
     * `candidate_doc_ids_filter` accepts a callable that receives every exact
     * candidate document id and returns the readable subset before scoring and
     * pagination. Supplying it forces exact candidate discovery so visibility
     * cannot be applied after an approximate result window. `explain` or
     * `debug` adds bounded diagnostics to pagination or candidate-capped
     * payloads. Query-plan explain rows include the user/query surface when the
     * analyzer exposes it, plus the analyzed storage term and key used for
     * scoring. `explain_result_matches` can disable the per-result document term
     * lookup when a caller must defer that work. `reuse_ranked_results` retains
     * one full ranking on this searcher instance so subsequent pagination calls
     * with the same scoring inputs only slice and enrich another page. Callers
     * must not mutate storage between those calls. Reuse is disabled when an
     * authoritative candidate filter or request budget guard is present because
     * their mutable behavior cannot be represented in the ranking fingerprint.
     * `explain_doc_ids_filter` may limit per-result explain lookups to a subset
     * of the already-ranked page without changing ranking, totals, or retrieval
     * mode. This is useful when a bounded caller must authorize rows before
     * issuing document-level diagnostic reads.
     * `max_query_terms`, `max_prefix_expansions`, and `max_candidate_rows`
     * impose request-wide work limits. `request_budget_guard` may stop work
     * between bounded storage/scoring steps by throwing or returning false.
     * Set-oriented storage always returns an unknown-total cursor payload with
     * `total => null`, `total_relation => 'unknown'`, `has_more`,
     * `next_cursor`, `previous_cursor`, `query_lang`, and `results`. It owns
     * prefix resolution, visibility, ranking, and hydration. That path always
     * rejects more than 12 logical groups or 12 alternatives in total,
     * regardless of the larger legacy `max_query_terms` default.
     *
     * @param array<string,mixed> $opts
     * @return array<int,array<string,mixed>>|array{total:int,total_is_exact:bool,retrieval_mode:string,results_may_be_incomplete:bool,candidate_cap:?int,limit:int,offset:int,query_lang:string,results:array<int,array<string,mixed>>,explain?:array<string,mixed>}|array{total:null,total_relation:string,query_lang:string,has_more:bool,next_cursor:?string,previous_cursor:?string,results:array<int,array<string,mixed>>,explain?:array<string,mixed>}
     *         Results sorted by exact/fallback rank, descending score, and
     *         ascending doc id for ties, or a status payload when
     *         `include_total` or candidate-capped retrieval is active.
     * @throws InvalidArgumentException If `mode` is not `OR` or `AND`.
     * @throws LogicException If the analyzer does not provide a query analyzer.
     * @throws WP_FTS_Search_Budget_Exceeded If a request budget is exhausted.
     */
    public function search(string $query, array $opts = []): array
    {
        $this->assert_public_option_map_bounds($opts);
        $previousGuard = $this->activeRequestBudgetGuard;
        $previousOptions = $this->activeSearchOptions;
        $this->activeRequestBudgetGuard = is_callable($opts['request_budget_guard'] ?? null)
            ? $opts['request_budget_guard']
            : null;
        $this->activeSearchOptions = $opts;

        try {
            $this->guard_request_budget();
            return $this->search_with_active_budget($query, $opts);
        } finally {
            $this->activeRequestBudgetGuard = $previousGuard;
            $this->activeSearchOptions = $previousOptions;
        }
    }

    /**
     * Execute one search while the caller's request budget is active.
     *
     * @param array<string,mixed> $opts
     * @return array<int,array<string,mixed>>|array{total:int,total_is_exact:bool,retrieval_mode:string,results_may_be_incomplete:bool,candidate_cap:?int,limit:int,offset:int,query_lang:string,results:array<int,array<string,mixed>>,explain?:array<string,mixed>}|array{total:null,total_relation:string,query_lang:string,has_more:bool,next_cursor:?string,previous_cursor:?string,results:array<int,array<string,mixed>>,explain?:array<string,mixed>}
     */
    private function search_with_active_budget(string $query, array $opts): array
    {
        if ($this->storage instanceof WP_FTS_Set_Oriented_Search_Storage) {
            return $this->search_set_oriented_page($query, $opts);
        }

        $candidateFilter = $this->candidate_doc_ids_filter($opts);
        $extensionResults = $this->extension_results($query, $opts, $candidateFilter !== null);
        if ($extensionResults !== null) {
            $this->guard_request_budget();
            return $extensionResults;
        }

        $mode = $this->search_mode($opts);
        if (!in_array($mode, ['OR', 'AND'], true)) {
            throw new InvalidArgumentException('Search mode must be OR or AND.');
        }
        $limit = max(1, (int) ($opts['limit'] ?? 10));
        $offset = max(0, (int) ($opts['offset'] ?? 0));

        $recencyBoost = $this->recency_boost_config($opts);
        $queryPlan = $this->build_query_plan($query, $opts);
        $this->guard_request_budget();
        $queryPlan['match_mode'] = $mode;
        $groups = $queryPlan['groups'];
        $responseLang = $this->response_query_language($opts, $groups);
        $fastMode = $this->resolve_fast_mode($opts, $limit + $offset, $candidateFilter !== null);
        $explainPayloadRequested = $this->legacy_explain_payload_requested(
            $opts,
            $fastMode['candidate_cap']
        );
        if ($groups === []) {
            $explain = $explainPayloadRequested
                ? $this->build_explain_payload(
                    $queryPlan,
                    $fastMode,
                    $this->empty_score_stats(),
                    $this->empty_recency_boost_stats($recencyBoost),
                    [],
                    0,
                    $fastMode['candidate_cap'] === null
                )
                : null;

            return $this->format_response([], 0, $opts, $responseLang, $explain, $fastMode['candidate_cap']);
        }

        $metadataFilter = $this->has_metadata_filters($opts) ? $this->metadata_filter_values($opts) : null;
        $this->guard_request_budget();
        $useBoundedTopK = !$recencyBoost['enabled']
            && $fastMode['candidate_cap'] === null
            && $this->can_use_bounded_top_k($opts, $offset);
        $cacheKey = !$useBoundedTopK
            && $candidateFilter === null
            && $this->activeRequestBudgetGuard === null
            && $this->truthy_option($opts['reuse_ranked_results'] ?? false)
            ? $this->ranked_result_cache_key(
                $groups,
                $mode,
                $metadataFilter,
                $fastMode['candidate_cap'],
                $recencyBoost,
                $opts['now_gmt'] ?? ($opts['recency_now'] ?? null),
                $this->max_candidate_rows($opts)
            )
            : null;
        if ($cacheKey !== null && ($this->rankedResultCache['key'] ?? null) === $cacheKey) {
            $results = $this->rankedResultCache['results'];
            $scoreStats = $this->rankedResultCache['score_stats'];
            $scoreStats['ranked_results_reused'] = 1;
            $recencyStats = $this->rankedResultCache['recency_stats'];
        } else {
            $scoreStats = $this->empty_score_stats();
            $results = $this->score_query_groups($groups, $mode, $useBoundedTopK ? $limit : null, $metadataFilter, $candidateFilter, $fastMode['candidate_cap'], $scoreStats);
            $this->guard_request_budget();
            $recencyStats = $this->apply_recency_boost($results, $recencyBoost);
            $this->guard_request_budget();
            if (!$useBoundedTopK) {
                usort($results, [self::class, 'compare_ranked_results']);
                $this->guard_request_budget();
            }
            if ($cacheKey !== null) {
                $this->rankedResultCache = [
                    'key' => $cacheKey,
                    'results' => $results,
                    'score_stats' => $scoreStats,
                    'recency_stats' => $recencyStats,
                ];
            }
        }

        $total = count($results);
        $page = $useBoundedTopK ? $results : array_slice($results, $offset, $limit);
        $resultExplain = [];
        if ($explainPayloadRequested && $this->explain_result_matches_requested($opts)) {
            $resultExplain = $this->explain_result_matches(
                $this->filter_explain_result_page($page, $opts),
                $groups
            );
        }
        if ($this->should_enrich_results($opts) && $page !== []) {
            $pageIds = array_column($page, 'doc_id');
            $this->guard_request_budget();
            $pageMetadata = WP_FTS_StorageCompat::get_doc_metadata($this->storage, $pageIds);
            $this->guard_request_budget();
            $page = $this->enrich_results($page, $pageMetadata, $query, $opts, $groups, $responseLang);
        }

        $this->strip_internal_rank($page);

        $explain = $explainPayloadRequested
            ? $this->build_explain_payload($queryPlan, $fastMode, $scoreStats, $recencyStats, $resultExplain, $total, $fastMode['candidate_cap'] === null)
            : null;

        return $this->format_response($page, $total, $opts, $responseLang, $explain, $fastMode['candidate_cap']);
    }

    /**
     * Delegate one analyzed query plan to a set-oriented storage backend.
     *
     * This path deliberately does not reuse the legacy query-plan builder: that
     * builder can analyze once per requested/fallback language and performs one
     * storage prefix lookup per candidate. Set-oriented storage receives the
     * analyzer's original logical groups and owns the single final-prefix lookup,
     * visibility, ranking, and one-row lookahead in its database query.
     *
     * @param array<string,mixed> $opts
     * @return array<string,mixed>
     */
    private function search_set_oriented_page(string $query, array $opts): array
    {
        $this->assert_set_oriented_option_keys($opts);
        $this->assert_set_oriented_query_input($query, $opts);
        $searchReadyIncarnation = $this->set_oriented_search_ready_incarnation($opts);
        $searchReadyProfileHash = $this->set_oriented_search_ready_profile_hash($opts);
        $mode = $this->search_mode($opts);
        if (!in_array($mode, ['OR', 'AND'], true)) {
            throw new InvalidArgumentException('Search mode must be OR or AND.');
        }
        if (max(0, (int) ($opts['offset'] ?? 0)) > 0) {
            throw new InvalidArgumentException('Set-oriented search uses opaque cursors instead of offsets.');
        }
        if ($this->truthy_option($opts['phrase'] ?? false)) {
            throw new InvalidArgumentException('Phrase search is not supported by the set-oriented storage contract.');
        }

        $pageSize = max(1, min(
            WP_FTS_Set_Oriented_Search_Storage::MAX_PAGE_SIZE,
            (int) ($opts['limit'] ?? 10)
        ));
        $explicitQueryLang = WP_FTS_TermNamespace::language_from_options($opts, null, ['query_lang', 'lang', 'language']);
        try {
            $queryOccurrences = $this->analyze_query_once($query, $opts);
        } catch (WP_FTS_Analysis_Limit_Exceeded $error) {
            // Reaching an analyzer stop while planning visitor input is a
            // typed query-complexity rejection, not a corrupt runtime that
            // should latch search unavailable and schedule schema repair.
            throw new WP_FTS_Search_Budget_Exceeded(
                $error->reason_code === 'occurrences' ? 'analyzer occurrences' : 'query bytes'
            );
        }
        if (count($queryOccurrences) > WP_FTS_Set_Oriented_Search_Storage::MAX_QUERY_ALTERNATIVES) {
            throw new WP_FTS_Search_Budget_Exceeded('analyzer occurrences');
        }
        $queryLang = $explicitQueryLang ?? $this->resolve_query_language($opts, $queryOccurrences);
        $groups = $this->dedupe_query_groups($this->groups_from_occurrences(
            $queryOccurrences,
            $queryLang,
            0,
            $explicitQueryLang
        ));
        $this->assert_set_oriented_query_groups($groups);
        if ($this->query_group_term_count($groups) > min(WP_FTS_Set_Oriented_Search_Storage::MAX_QUERY_ALTERNATIVES, $this->max_query_terms($opts))) {
            throw new WP_FTS_Search_Budget_Exceeded('analyzed terms');
        }

        $responseLang = $this->response_query_language($opts, $groups);
        if ($groups === []) {
            [$cursor] = $this->set_oriented_cursor($opts);
            if ($cursor !== null) {
                throw new InvalidArgumentException('Search cursor cannot be used with an empty query plan.');
            }
            return $this->normalize_set_oriented_page([], $pageSize, $responseLang, $query, $groups, $opts, []);
        }

        $prefixSurface = $this->set_oriented_prefix_surface(
            $queryOccurrences,
            $groups[array_key_last($groups)],
            $queryLang,
            $explicitQueryLang
        );
        $prefixMatching = $this->prefix_matching_enabled($opts);
        $storageOpts = $opts;
        $storagePrefixSurface = $prefixSurface;
        if (
            $prefixMatching
            && (
                $prefixSurface === null
                || !WP_FTS_TermNamespace::term_key_fits($prefixSurface['term'], $prefixSurface['lang'])
            )
        ) {
            // A filtered final token has no exact candidate, and the writer
            // truncates long surfaces at the dictionary boundary. Falling back
            // to an earlier word or a shorter stored key would admit false
            // positives, so retain exact groups and disable only the prefix.
            $storageOpts['prefix_matching'] = false;
            $storagePrefixSurface = null;
        }
        $storageOptions = $this->set_oriented_storage_options(
            $storageOpts,
            $mode,
            $pageSize,
            $responseLang,
            count($groups) - 1,
            $storagePrefixSurface,
            $searchReadyIncarnation,
            $searchReadyProfileHash
        );
        $this->guard_request_budget();
        $page = $this->storage->search_page($groups, $storageOptions);
        $this->guard_request_budget();

        $authoritativePrefixes = $storagePrefixSurface === null ? [] : [$storagePrefixSurface];

        return $this->normalize_set_oriented_page(
            $page,
            $pageSize,
            $responseLang,
            $query,
            $groups,
            $opts,
            $authoritativePrefixes
        );
    }

    /** Reject every option that is not part of the fixed relational contract. */
    private function assert_set_oriented_option_keys(array $opts): void
    {
        $allowed = array_fill_keys([
            'mode',
            'offset',
            'limit',
            'phrase',
            'query_lang',
            'lang',
            'language',
            'default_lang',
            'locale',
            'result_lang',
            'document_lang',
            'prefix_matching',
            'prefix',
            'prefix_min_length',
            'include_metadata',
            'include_snippets',
            'snippets',
            'highlight',
            'snippet_length',
            'explain',
            'debug',
            'cursor',
            'after_cursor',
            'before_cursor',
            'direction',
            'post_type',
            'post_types',
            'post_status',
            'post_statuses',
            'date_after',
            'after',
            'post_date_after',
            'date_before',
            'before',
            'post_date_before',
            'recency_boost',
            'freshness_boost',
            'recency_boost_strength',
            'freshness_boost_strength',
            'recency_boost_half_life_days',
            'freshness_boost_half_life_days',
            'recency_boost_window_days',
            'now_gmt',
            'recency_now',
            'max_query_terms',
            'request_budget_guard',
            '_include_canonical_post_rows',
            '_search_ready_incarnation',
            '_search_ready_profile_hash',
        ], true);

        foreach ($opts as $key => $_value) {
            if (!is_string($key)) {
                throw new InvalidArgumentException('Set-oriented search option keys must be strings.');
            }
            if (!isset($allowed[$key])) {
                throw new InvalidArgumentException("Set-oriented search does not support {$key}.");
            }
        }
    }

    /** Resolve a public mode without normalizing an arbitrarily large scalar. */
    private function search_mode(array $opts): string
    {
        $rawMode = $opts['mode'] ?? 'OR';
        if (!is_string($rawMode) || strlen($rawMode) > self::MAX_SET_ORIENTED_MODE_BYTES) {
            throw new InvalidArgumentException('Search mode must be a string of at most 8 bytes.');
        }

        return strtoupper($rawMode);
    }

    /**
     * Reject obviously over-wide input before invoking an analyzer or storage.
     *
     * The analyzer remains authoritative for punctuation and Unicode token
     * boundaries. This cheap ASCII-whitespace scan catches ordinary oversized
     * visitor queries after the thirteenth lexical unit instead of materializing
     * the legacy 1,024-term plan first. Analyzer output is checked again below.
     */
    private function assert_set_oriented_query_input(string $query, array $opts): void
    {
        if (strlen($query) > self::MAX_SET_ORIENTED_QUERY_BYTES) {
            throw new WP_FTS_Search_Budget_Exceeded('query bytes');
        }

        foreach (['langs', 'languages', 'fallback_lang', 'fallback_language', 'fallback_languages', 'language_fallback', 'fallback_to_default_lang'] as $key) {
            if (!array_key_exists($key, $opts)) {
                continue;
            }
            $value = $opts[$key];
            if ($value !== null && $value !== '' && $value !== [] && $value !== false) {
                throw new InvalidArgumentException(
                    'Set-oriented search accepts one per-occurrence language plan and does not support language fanout options.'
                );
            }
        }

        foreach (['query_lang', 'lang', 'language', 'default_lang', 'locale', 'result_lang', 'document_lang'] as $key) {
            if (!array_key_exists($key, $opts)) {
                continue;
            }
            if (!is_string($opts[$key])) {
                throw new InvalidArgumentException('Set-oriented language options must be strings.');
            }
            if (strlen($opts[$key]) > self::MAX_SET_ORIENTED_LANGUAGE_BYTES) {
                throw new InvalidArgumentException('Set-oriented language options may contain at most 64 bytes.');
            }
        }
        foreach ([
            'query language' => ['query_lang', 'lang', 'language'],
            'default language' => ['default_lang', 'locale'],
            'result language' => ['result_lang', 'document_lang'],
        ] as $label => $keys) {
            $resolved = null;
            foreach ($keys as $key) {
                if (!array_key_exists($key, $opts)) {
                    continue;
                }
                $value = trim($opts[$key]);
                $value = $value === '' ? '' : WP_FTS_TermNamespace::canonicalize_lang($value);
                if ($resolved !== null && $resolved !== $value) {
                    throw new InvalidArgumentException("Set-oriented {$label} aliases must agree.");
                }
                $resolved = $value;
            }
        }

        foreach (['phrase', 'prefix_matching', 'prefix', 'include_metadata', 'include_snippets', 'snippets', '_include_canonical_post_rows', 'highlight', 'explain', 'debug'] as $key) {
            $this->assert_set_oriented_switch_option($opts, $key);
        }
        foreach ([
            'prefix matching' => ['prefix_matching', 'prefix'],
            'snippet inclusion' => ['include_snippets', 'snippets'],
        ] as $label => [$primary, $alias]) {
            if (
                array_key_exists($primary, $opts)
                && array_key_exists($alias, $opts)
                && $this->truthy_option($opts[$primary]) !== $this->truthy_option($opts[$alias])
            ) {
                throw new InvalidArgumentException("Set-oriented {$label} aliases must agree.");
            }
        }

        foreach ([
            'offset' => [0, 0],
            'limit' => [1, WP_FTS_Set_Oriented_Search_Storage::MAX_PAGE_SIZE],
            'max_query_terms' => [1, WP_FTS_Set_Oriented_Search_Storage::MAX_QUERY_ALTERNATIVES],
            'prefix_min_length' => [2, 255],
            'snippet_length' => [1, self::MAX_SET_ORIENTED_SNIPPET_LENGTH],
        ] as $key => [$minimum, $maximum]) {
            $this->assert_set_oriented_integer_option($opts, $key, $minimum, $maximum);
        }
        foreach (['recency_boost', 'freshness_boost'] as $key) {
            $this->assert_set_oriented_recency_toggle($opts, $key);
        }
        foreach ([
            'recency_boost_strength' => [0.0, self::MAX_RECENCY_BOOST_STRENGTH],
            'freshness_boost_strength' => [0.0, self::MAX_RECENCY_BOOST_STRENGTH],
            'recency_boost_half_life_days' => [self::MIN_RECENCY_BOOST_HALF_LIFE_DAYS, self::MAX_RECENCY_BOOST_HALF_LIFE_DAYS],
            'freshness_boost_half_life_days' => [self::MIN_RECENCY_BOOST_HALF_LIFE_DAYS, self::MAX_RECENCY_BOOST_HALF_LIFE_DAYS],
            'recency_boost_window_days' => [self::MIN_RECENCY_BOOST_HALF_LIFE_DAYS, self::MAX_RECENCY_BOOST_HALF_LIFE_DAYS],
        ] as $key => [$minimum, $maximum]) {
            $this->assert_set_oriented_float_option($opts, $key, $minimum, $maximum);
        }
        foreach ([
            'recency boost' => ['recency_boost', 'freshness_boost', true],
            'recency boost strength' => ['recency_boost_strength', 'freshness_boost_strength', false],
            'recency half life' => ['recency_boost_half_life_days', 'freshness_boost_half_life_days', false],
        ] as $label => [$primary, $alias, $toggle]) {
            if (!array_key_exists($primary, $opts) || !array_key_exists($alias, $opts)) {
                continue;
            }
            $primaryValue = $toggle
                ? $this->set_oriented_recency_toggle_value($opts[$primary])
                : (float) $opts[$primary];
            $aliasValue = $toggle
                ? $this->set_oriented_recency_toggle_value($opts[$alias])
                : (float) $opts[$alias];
            if ($primaryValue !== $aliasValue) {
                throw new InvalidArgumentException("Set-oriented {$label} aliases must agree.");
            }
        }
        foreach (['date_after', 'after', 'post_date_after', 'date_before', 'before', 'post_date_before', 'now_gmt', 'recency_now'] as $key) {
            if (!array_key_exists($key, $opts)) {
                continue;
            }
            if (
                !is_string($opts[$key])
                || $opts[$key] === ''
                || trim($opts[$key]) !== $opts[$key]
                || strlen($opts[$key]) > self::MAX_SET_ORIENTED_FILTER_VALUE_BYTES
                || $this->parse_gmt_timestamp($opts[$key]) === null
            ) {
                throw new InvalidArgumentException("Set-oriented {$key} must be a valid UTC date or datetime of at most 64 bytes.");
            }
        }
        foreach ([
            'lower date boundary' => [['date_after', 'after', 'post_date_after'], false],
            'upper date boundary' => [['date_before', 'before', 'post_date_before'], true],
        ] as $label => [$keys, $endOfDay]) {
            $resolved = null;
            $resolvedSet = false;
            foreach ($keys as $key) {
                if (!array_key_exists($key, $opts)) {
                    continue;
                }
                $value = $this->bounded_set_oriented_date_filter($opts[$key], $endOfDay);
                if ($resolvedSet && $resolved !== $value) {
                    throw new InvalidArgumentException("Set-oriented {$label} aliases must agree.");
                }
                $resolved = $value;
                $resolvedSet = true;
            }
        }
        if (
            array_key_exists('now_gmt', $opts)
            && array_key_exists('recency_now', $opts)
            && $this->parse_gmt_timestamp($opts['now_gmt']) !== $this->parse_gmt_timestamp($opts['recency_now'])
        ) {
            throw new InvalidArgumentException('Set-oriented recency clock aliases must agree.');
        }
        if (array_key_exists('request_budget_guard', $opts) && !is_callable($opts['request_budget_guard'])) {
            throw new InvalidArgumentException('Set-oriented request_budget_guard must be callable.');
        }
        // Cursor and filter checks belong before analyzer work. Their helpers
        // are repeated when the storage options are built, but only over the
        // already-proven 2 KiB/32-value envelopes.
        $this->set_oriented_cursor($opts);
        $this->bounded_set_oriented_filter_values($this->set_oriented_filter_value($opts, 'post_type', 'post_types'));
        $this->bounded_set_oriented_filter_values($this->set_oriented_filter_value($opts, 'post_status', 'post_statuses'));

        $inUnit = false;
        $units = 0;
        $length = strlen($query);
        for ($index = 0; $index < $length; $index++) {
            $byte = ord($query[$index]);
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
                throw new WP_FTS_Search_Budget_Exceeded('logical query groups');
            }
        }
    }

    /** Accept only explicit boolean spellings; arbitrary strings are not switches. */
    private function assert_set_oriented_switch_option(array $opts, string $key): void
    {
        if (!array_key_exists($key, $opts)) {
            return;
        }

        $value = $opts[$key];
        if (is_bool($value) || (is_int($value) && ($value === 0 || $value === 1))) {
            return;
        }
        if (
            is_string($value)
            && strlen($value) <= self::MAX_SET_ORIENTED_SWITCH_BYTES
            && in_array(strtolower($value), ['0', '1', 'true', 'false', 'yes', 'no', 'on', 'off'], true)
        ) {
            return;
        }

        throw new InvalidArgumentException("Set-oriented {$key} must be a boolean switch.");
    }

    /** Reject fractional, padded, non-finite, and implicitly cast integer inputs. */
    private function assert_set_oriented_integer_option(
        array $opts,
        string $key,
        int $minimum,
        int $maximum
    ): void {
        if (!array_key_exists($key, $opts)) {
            return;
        }

        $value = $opts[$key];
        if (
            (!is_int($value) && (!is_string($value) || preg_match('/^(0|[1-9][0-9]*)$/D', $value) !== 1))
            || (is_string($value) && strlen($value) > self::MAX_SET_ORIENTED_NUMERIC_BYTES)
            || (int) $value < $minimum
            || (int) $value > $maximum
        ) {
            throw new InvalidArgumentException("Set-oriented {$key} must be an integer from {$minimum} through {$maximum}.");
        }
    }

    /** Recency toggles may also carry a finite strength in the documented range. */
    private function assert_set_oriented_recency_toggle(array $opts, string $key): void
    {
        if (!array_key_exists($key, $opts)) {
            return;
        }

        $value = $opts[$key];
        if (is_bool($value)) {
            return;
        }
        if (is_string($value) && in_array(strtolower($value), ['true', 'false', 'yes', 'no', 'on', 'off'], true)) {
            return;
        }

        $this->assert_set_oriented_float_option($opts, $key, 0.0, self::MAX_RECENCY_BOOST_STRENGTH);
    }

    /** Convert one already-validated recency toggle to its effective strength. */
    private function set_oriented_recency_toggle_value(mixed $value): float
    {
        if (is_bool($value)) {
            return $value ? self::DEFAULT_RECENCY_BOOST_STRENGTH : 0.0;
        }
        if (is_string($value) && !preg_match('/^(0|[1-9][0-9]*)(?:\.[0-9]+)?$/D', $value)) {
            return $this->truthy_option($value) ? self::DEFAULT_RECENCY_BOOST_STRENGTH : 0.0;
        }

        return (float) $value;
    }

    /** Accept only a finite unsigned decimal inside the option's semantic range. */
    private function assert_set_oriented_float_option(
        array $opts,
        string $key,
        float $minimum,
        float $maximum
    ): void {
        if (!array_key_exists($key, $opts)) {
            return;
        }

        $value = $opts[$key];
        $validType = is_int($value) || is_float($value);
        if (is_string($value)) {
            $validType = strlen($value) <= self::MAX_SET_ORIENTED_NUMERIC_BYTES
                && preg_match('/^(0|[1-9][0-9]*)(?:\.[0-9]+)?$/D', $value) === 1;
        }
        $number = $validType ? (float) $value : NAN;
        if (!$validType || !is_finite($number) || $number < $minimum || $number > $maximum) {
            throw new InvalidArgumentException("Set-oriented {$key} must be a finite number from {$minimum} through {$maximum}.");
        }
    }

    /** Bound arbitrary public option maps before retaining or copying them. */
    private function assert_public_option_map_bounds(array $opts): void
    {
        if (count($opts) > self::MAX_PUBLIC_OPTION_KEYS) {
            throw new InvalidArgumentException('FTS search options may contain at most 64 keys.');
        }
        $nodes = 0;
        $bytes = 0;
        $stack = [[$opts, 0]];
        while ($stack !== []) {
            [$map, $depth] = array_pop($stack);
            if ($depth > 8 || count($map) > self::MAX_PUBLIC_OPTION_NODES) {
                throw new InvalidArgumentException('FTS search options exceed the bounded graph shape.');
            }
            foreach ($map as $key => $value) {
                if (++$nodes > self::MAX_PUBLIC_OPTION_NODES) {
                    throw new InvalidArgumentException('FTS search options exceed the 512-node limit.');
                }
                if (is_string($key)) {
                    if (strlen($key) > 191) {
                        throw new InvalidArgumentException('FTS search option keys may contain at most 191 bytes.');
                    }
                    $bytes += strlen($key);
                }
                if (is_string($value)) {
                    $bytes += strlen($value);
                } elseif (is_array($value)) {
                    $stack[] = [$value, $depth + 1];
                }
                if ($bytes > self::MAX_PUBLIC_OPTION_BYTES) {
                    throw new InvalidArgumentException('FTS search options exceed the 64 KiB source limit.');
                }
            }
        }
    }

    /**
     * Enforce the fixed relational query shape independently of public budgets.
     *
     * @param array<int,array<int,array{key:string,lang:string,term:string,rank:int,source?:string,surface?:string}>> $groups
     */
    private function assert_set_oriented_query_groups(array $groups): void
    {
        if (count($groups) > WP_FTS_Set_Oriented_Search_Storage::MAX_QUERY_GROUPS) {
            throw new WP_FTS_Search_Budget_Exceeded('logical query groups');
        }

        $alternatives = 0;
        foreach ($groups as $group) {
            if (count($group) > WP_FTS_Set_Oriented_Search_Storage::MAX_ALTERNATIVES_PER_GROUP) {
                throw new WP_FTS_Search_Budget_Exceeded('query alternatives per group');
            }

            $alternatives += count($group);
            if ($alternatives > WP_FTS_Set_Oriented_Search_Storage::MAX_QUERY_ALTERNATIVES) {
                throw new WP_FTS_Search_Budget_Exceeded('query alternatives');
            }
        }
    }

    /**
     * Analyze a set-oriented query with exactly one analyzer method call.
     *
     * Older analyzers receive both occurrence-format hints in the same call. The
     * legacy retry sequence remains available only to legacy storage backends.
     *
     * @return array<int,array<string,mixed>|string>
     */
    private function analyze_query_once(string $query, array $opts): array
    {
        $analysisOpts = $this->query_analysis_options($opts);
        unset(
            $analysisOpts['langs'],
            $analysisOpts['languages'],
            $analysisOpts['fallback_lang'],
            $analysisOpts['fallback_language'],
            $analysisOpts['fallback_languages'],
            $analysisOpts['language_fallback'],
            $analysisOpts['fallback_to_default_lang']
        );
        $analysisOpts['return'] = 'occurrences';
        $analysisOpts['format'] = 'occurrences';
        // Stop the analyzer at the same hard plan bound instead of first
        // materializing thousands of CJK n-grams or custom-tokenizer rows that
        // the relational backend must reject immediately afterward.
        $analysisOpts['_max_query_occurrences'] = WP_FTS_Set_Oriented_Search_Storage::MAX_QUERY_ALTERNATIVES;
        // The final prefix descriptor must come from this same analyzer pass.
        // `surface` remains presentation text; `normalized_surface` is the
        // typed lexical key and must never be inferred from a lemma later.
        $analysisOpts['_include_query_surface'] = true;

        if (method_exists($this->analyzer, 'analyze_query_occurrences')) {
            return $this->normalize_set_oriented_query_analysis(
                $this->analyzer->analyze_query_occurrences($query, $analysisOpts)
            );
        }
        if (!is_callable([$this->analyzer, 'analyze_query'])) {
            throw new LogicException('Analyzer must provide analyze_query().');
        }

        return $this->normalize_set_oriented_query_analysis($this->analyzer->analyze_query($query, $analysisOpts));
    }

    /**
     * Build the bounded backend-owned search contract.
     *
     * @param array<string,mixed> $opts
     * @return array<string,mixed>
     */
    private function set_oriented_storage_options(
        array $opts,
        string $mode,
        int $pageSize,
        string $queryLang,
        int $prefixGroupIndex,
        ?array $prefixSurface,
        ?string $searchReadyIncarnation,
        ?string $searchReadyProfileHash
    ): array {
        [$cursor, $direction] = $this->set_oriented_cursor($opts);
        $recencyBoost = $this->recency_boost_config($opts);
        $postTypes = $this->bounded_set_oriented_filter_values($this->set_oriented_filter_value($opts, 'post_type', 'post_types'));
        $postStatuses = $this->bounded_set_oriented_filter_values($this->set_oriented_filter_value($opts, 'post_status', 'post_statuses'));

        $storageOptions = [
            'mode' => $mode,
            'page_size' => $pageSize,
            'limit' => $pageSize + 1,
            'cursor' => $cursor,
            'direction' => $direction,
            'query_lang' => WP_FTS_TermNamespace::canonicalize_lang($queryLang),
            'prefix_matching' => $this->prefix_matching_enabled($opts),
            'prefix_group_index' => $prefixGroupIndex,
            'prefix_min_length' => $this->prefix_min_length($opts),
            'post_types' => $postTypes,
            'post_statuses' => $postStatuses,
            'date_after' => $this->bounded_set_oriented_date_filter(
                $opts['date_after'] ?? $opts['after'] ?? $opts['post_date_after'] ?? null,
                false
            ),
            'date_before' => $this->bounded_set_oriented_date_filter(
                $opts['date_before'] ?? $opts['before'] ?? $opts['post_date_before'] ?? null,
                true
            ),
            'include_metadata' => $this->truthy_option($opts['include_metadata'] ?? false),
            'include_snippets' => $this->truthy_option($opts['include_snippets'] ?? $opts['snippets'] ?? false),
            // The WordPress integration consumes this private transport row so
            // its bounded third statement is also the canonical WP_Post hydrate.
            'include_canonical_post_row' => $this->truthy_option($opts['_include_canonical_post_rows'] ?? false),
            'highlight' => $this->truthy_option($opts['highlight'] ?? false),
            'snippet_length' => max(1, min(
                self::MAX_SET_ORIENTED_SNIPPET_LENGTH,
                (int) ($opts['snippet_length'] ?? 180)
            )),
            'explain' => $this->explain_requested($opts),
            'recency_boost_strength' => $recencyBoost['enabled'] ? $recencyBoost['strength'] : 0.0,
            'recency_boost_half_life_days' => $recencyBoost['half_life_days'],
        ];
        if ($prefixSurface !== null) {
            $storageOptions['prefix_surface'] = $prefixSurface;
        }
        if ($recencyBoost['enabled']) {
            $storageOptions['now_gmt'] = $recencyBoost['now_gmt'];
        }
        if ($searchReadyIncarnation !== null) {
            $storageOptions['search_ready_incarnation'] = $searchReadyIncarnation;
        }
        if ($searchReadyProfileHash !== null) {
            $storageOptions['search_ready_profile_hash'] = $searchReadyProfileHash;
        }

        return $storageOptions;
    }

    /**
     * Validate the caller's private publication capability without changing it.
     *
     * The storage statement compares this exact value with the durable option;
     * trimming or case-folding here could authorize a capability the Plugin did
     * not publish. Component-only set-oriented backends may omit the value, but
     * the production MySQL backend requires it.
     */
    private function set_oriented_search_ready_incarnation(array $opts): ?string
    {
        if (!array_key_exists('_search_ready_incarnation', $opts)) {
            return null;
        }

        $incarnation = $opts['_search_ready_incarnation'];
        if (!is_string($incarnation) || preg_match('/^[a-f0-9]{32}$/D', $incarnation) !== 1) {
            throw new InvalidArgumentException('Search-ready incarnation must be exactly 32 lowercase hexadecimal bytes.');
        }

        return $incarnation;
    }

    /**
     * Keep analyzer provenance independent from the publication incarnation.
     * The backend compares these exact bytes with its durable ready profile;
     * deriving one capability from the other would admit a mixed index after a
     * profile-only rebuild.
     */
    private function set_oriented_search_ready_profile_hash(array $opts): ?string
    {
        if (!array_key_exists('_search_ready_profile_hash', $opts)) {
            return null;
        }

        $profileHash = $opts['_search_ready_profile_hash'];
        if (!is_string($profileHash) || preg_match('/^[a-f0-9]{40}$/D', $profileHash) !== 1) {
            throw new InvalidArgumentException('Search-ready profile hash must be exactly 40 lowercase hexadecimal bytes.');
        }

        return $profileHash;
    }

    /**
     * Resolve the final group's normalized typed surface from the one analysis.
     *
     * @param array<int,array<string,mixed>|string> $occurrences
     * @param array<int,array{key:string,lang:string,term:string,rank:int,source?:string,surface?:string}> $finalGroup
     * @return array{lang:string,term:string}|null
     */
    private function set_oriented_prefix_surface(
        array $occurrences,
        array $finalGroup,
        string $defaultLang,
        ?string $authoritativeLang
    ): ?array {
        $finalKeys = [];
        foreach ($finalGroup as $candidate) {
            $finalKeys[(string) $candidate['key']] = true;
        }

        for ($index = count($occurrences) - 1; $index >= 0; $index--) {
            $occurrence = $occurrences[$index];
            if (!is_array($occurrence)) {
                continue;
            }
            $hasSurface = (
                is_scalar($occurrence['normalized_surface'] ?? null)
                && (string) $occurrence['normalized_surface'] !== ''
            ) || (
                is_scalar($occurrence['surface'] ?? null)
                && (string) $occurrence['surface'] !== ''
            );
            if (!$hasSurface) {
                continue;
            }

            $candidate = $this->candidate_from_occurrence(
                $occurrence,
                $defaultLang,
                0,
                $authoritativeLang
            );
            if ($candidate === null || !isset($finalKeys[$candidate['key']])) {
                return null;
            }

            $surface = $this->normalized_occurrence_surface($occurrence, $candidate);
            if ($surface === '') {
                return null;
            }

            return ['lang' => $candidate['lang'], 'term' => $surface];
        }

        return null;
    }

    /**
     * Read the normalized typed surface without mistaking a lemma for one.
     *
     * Structured third-party analyzers may expose only the older `surface`
     * field. It is safe as a normalized identity only when it is literally the
     * analyzed term; otherwise absence disables prefix behavior instead of
     * ranging or highlighting on unnormalized presentation text.
     *
     * @param array<string,mixed> $occurrence
     * @param array{term:string} $candidate
     */
    private function normalized_occurrence_surface(array $occurrence, array $candidate): string
    {
        $surface = isset($occurrence['normalized_surface']) && is_scalar($occurrence['normalized_surface'])
            ? trim((string) $occurrence['normalized_surface'])
            : '';
        if (
            $surface === ''
            && isset($occurrence['surface'])
            && is_scalar($occurrence['surface'])
            && trim((string) $occurrence['surface']) === $candidate['term']
        ) {
            return $candidate['term'];
        }

        return $surface;
    }

    /**
     * Normalize mutually exclusive public cursor aliases.
     *
     * @param array<string,mixed> $opts
     * @return array{0:?string,1:string}
     */
    private function set_oriented_cursor(array $opts): array
    {
        $after = $this->set_oriented_cursor_value($opts['after_cursor'] ?? null);
        $before = $this->set_oriented_cursor_value($opts['before_cursor'] ?? null);
        $cursor = $this->set_oriented_cursor_value($opts['cursor'] ?? null);
        $cursorCount = ($after !== null ? 1 : 0) + ($before !== null ? 1 : 0) + ($cursor !== null ? 1 : 0);
        if ($cursorCount > 1) {
            throw new InvalidArgumentException('Pass only one of cursor, after_cursor, or before_cursor.');
        }

        $direction = null;
        if (array_key_exists('direction', $opts)) {
            if (!is_string($opts['direction']) || !in_array($opts['direction'], ['after', 'before'], true)) {
                throw new InvalidArgumentException('Cursor direction must be exactly after or before.');
            }
            $direction = $opts['direction'];
            if ($cursorCount === 0) {
                throw new InvalidArgumentException('Cursor direction requires a nonempty cursor.');
            }
        }

        if ($after !== null) {
            if ($direction !== null && $direction !== 'after') {
                throw new InvalidArgumentException('after_cursor cannot be combined with before direction.');
            }
            return [$after, 'after'];
        }
        if ($before !== null) {
            if ($direction !== null && $direction !== 'before') {
                throw new InvalidArgumentException('before_cursor cannot be combined with after direction.');
            }
            return [$before, 'before'];
        }
        if ($cursor === null) {
            return [null, 'after'];
        }

        return [$cursor, $direction ?? 'after'];
    }

    /** Validate one opaque search-after cursor before handing it to storage. */
    private function set_oriented_cursor_value(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        if (!is_string($value)) {
            throw new InvalidArgumentException('Search cursors must be strings.');
        }

        if (strlen($value) > self::MAX_SET_ORIENTED_CURSOR_BYTES) {
            throw new InvalidArgumentException('Search cursor is too long.');
        }

        if (trim($value) === '') {
            throw new InvalidArgumentException('Search cursors must be nonempty strings.');
        }

        return $value;
    }

    /** Preserve an explicitly malformed alias instead of falling through to no filter. */
    private function set_oriented_filter_value(array $opts, string $singular, string $plural): mixed
    {
        if (array_key_exists($singular, $opts) && array_key_exists($plural, $opts)) {
            throw new InvalidArgumentException("Pass only one of {$singular} or {$plural}.");
        }
        if (array_key_exists($singular, $opts)) {
            return $opts[$singular];
        }
        if (array_key_exists($plural, $opts)) {
            return $opts[$plural];
        }

        return [];
    }

    /**
     * @return string[]
     */
    private function bounded_set_oriented_filter_values(mixed $value): array
    {
        $restrictionWasSupplied = !is_array($value) || $value !== [];
        $values = is_array($value) ? $value : [$value];
        if (count($values) > self::MAX_SET_ORIENTED_FILTER_VALUES) {
            throw new InvalidArgumentException('Set-oriented search accepts at most 32 values per metadata filter.');
        }

        $inputBytes = 0;
        foreach ($values as $filterValue) {
            if (!is_scalar($filterValue)) {
                throw new InvalidArgumentException('Set-oriented metadata filter values must be scalar.');
            }

            $inputBytes += strlen((string) $filterValue);
            if ($inputBytes > self::MAX_SET_ORIENTED_FILTER_BYTES) {
                throw new InvalidArgumentException('Set-oriented metadata filters may contain at most 4096 bytes.');
            }
        }

        $bounded = [];
        foreach ($values as $value) {
            foreach (explode(',', (string) $value) as $part) {
                $part = trim($part);
                if ($part === '') {
                    if ($restrictionWasSupplied) {
                        throw new InvalidArgumentException('Set-oriented metadata filters must contain only nonempty values.');
                    }
                    continue;
                }
                if (strlen($part) > self::MAX_SET_ORIENTED_FILTER_VALUE_BYTES) {
                    throw new InvalidArgumentException('Set-oriented metadata filter values may contain at most 64 bytes.');
                }

                $bounded[$part] = true;
                if (count($bounded) > self::MAX_SET_ORIENTED_FILTER_VALUES) {
                    throw new InvalidArgumentException('Set-oriented search accepts at most 32 values per metadata filter.');
                }
            }
        }

        $bounded = array_keys($bounded);
        sort($bounded, SORT_STRING);
        if ($restrictionWasSupplied && $bounded === []) {
            throw new InvalidArgumentException('Set-oriented metadata filters must contain a nonempty scalar value.');
        }

        return $bounded;
    }

    /**
     * Normalize a date filter without accepting an unbounded parser input.
     */
    private function bounded_set_oriented_date_filter(mixed $value, bool $endOfDay): ?string
    {
        if ($value === null) {
            return null;
        }
        if (
            !is_string($value)
            || $value === ''
            || trim($value) !== $value
            || strlen($value) > self::MAX_SET_ORIENTED_FILTER_VALUE_BYTES
        ) {
            throw new InvalidArgumentException('Set-oriented date filters must be nonempty strings of at most 64 bytes.');
        }
        $timestamp = $this->parse_gmt_timestamp($value);
        if ($timestamp === null) {
            throw new InvalidArgumentException('Set-oriented date filters must contain a valid UTC date or datetime.');
        }
        if (preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/D', $value) === 1) {
            return $value . ($endOfDay ? ' 23:59:59' : ' 00:00:00');
        }

        return gmdate('Y-m-d H:i:s', $timestamp);
    }

    /**
     * Keep backend output page-sized and expose an explicitly unknown total.
     *
     * @param array<string,mixed> $page
     * @param array<string,mixed> $opts
     * @return array<string,mixed>
     */
    private function normalize_set_oriented_page(
        array $page,
        int $pageSize,
        string $queryLang,
        string $query,
        array $queryGroups,
        array $opts,
        ?array $authoritativePrefixes = null
    ): array
    {
        $rawResults = isset($page['results']) && is_array($page['results'])
            ? $page['results']
            : [];
        $results = [];
        foreach (array_slice($rawResults, 0, $pageSize + 1) as $row) {
            if (!is_array($row)) {
                continue;
            }

            $docId = $row['doc_id'] ?? ($row['post_id'] ?? null);
            if (!is_numeric($docId) || (int) $docId < 0) {
                continue;
            }
            $row['doc_id'] = (int) $docId;
            if (isset($row['score']) && is_numeric($row['score'])) {
                $row['score'] = (float) $row['score'];
            }
            unset($row['_rank']);
            $results[] = $row;
        }

        $hasMore = $this->truthy_option($page['has_more'] ?? false) || count($results) > $pageSize;
        $results = array_slice($results, 0, $pageSize);
        $results = $this->enrich_set_oriented_results(
            $results,
            $query,
            $opts,
            $queryGroups,
            $queryLang,
            $authoritativePrefixes
        );
        $resolvedLang = is_scalar($page['query_lang'] ?? null) && trim((string) $page['query_lang']) !== ''
            ? (string) $page['query_lang']
            : $queryLang;
        $payload = [
            'total' => null,
            'total_relation' => 'unknown',
            'query_lang' => WP_FTS_TermNamespace::canonicalize_lang($resolvedLang),
            'has_more' => $hasMore,
            // Reverse pages can have no more rows in the reverse direction and
            // still need a forward cursor back toward the originating page.
            'next_cursor' => $this->set_oriented_cursor_value($page['next_cursor'] ?? null),
            'previous_cursor' => $this->set_oriented_cursor_value($page['previous_cursor'] ?? null),
            'results' => $results,
        ];

        if ($this->explain_requested($opts) && isset($page['explain']) && is_array($page['explain'])) {
            $payload['explain'] = $page['explain'];
            if (isset($payload['explain']['results']) && is_array($payload['explain']['results'])) {
                $payload['explain']['results'] = array_slice($payload['explain']['results'], 0, $pageSize);
            }
        }

        return $payload;
    }

    /**
     * Enrich only the returned page from sidecars already carried by its rows.
     *
     * Set-oriented storage attaches raw `snippet_text` and optional `metadata`
     * to at most the first `page_size` rows. They are internal transport fields:
     * the raw snippet source never reaches the public payload, and this method
     * performs no storage reads or query-plan rebuilds.
     *
     * @param array<int,array<string,mixed>> $results
     * @param array<int,array<int,array{key:string,lang:string,term:string,rank:int,source?:string,surface?:string}>> $queryGroups
     * @return array<int,array<string,mixed>>
     */
    private function enrich_set_oriented_results(
        array $results,
        string $query,
        array $opts,
        array $queryGroups,
        string $queryLang,
        ?array $authoritativePrefixes
    ): array
    {
        $includeMetadata = $this->truthy_option($opts['include_metadata'] ?? false);
        $includeSnippets = $this->truthy_option($opts['include_snippets'] ?? $opts['snippets'] ?? false);
        foreach ($results as &$row) {
            $meta = is_array($row['metadata'] ?? null) ? $row['metadata'] : [];
            foreach (['post_id', 'post_type', 'post_status', 'post_date_gmt', 'title', 'excerpt', 'search_text', 'search_html', 'language', 'lang', 'primary_lang'] as $key) {
                if (array_key_exists($key, $row) && is_scalar($row[$key])) {
                    $meta[$key] = $row[$key];
                }
            }

            if ($includeMetadata) {
                foreach (['post_id', 'post_type', 'post_status', 'post_date_gmt', 'title', 'excerpt'] as $key) {
                    $row[$key] = isset($meta[$key]) && is_scalar($meta[$key])
                        ? (string) $meta[$key]
                        : ($key === 'post_id' ? 0 : '');
                }
                $row['post_id'] = max(0, (int) $row['post_id']);
                if (
                    $this->truthy_option($opts['highlight'] ?? false)
                    && is_scalar($row['title'] ?? null)
                    && (string) $row['title'] !== ''
                ) {
                    $row['highlighted_title'] = $this->highlight_analyzed_text(
                        (string) $row['title'],
                        $query,
                        $opts,
                        $queryGroups,
                        $queryLang,
                        $this->snippet_result_language($row, $meta, null, $opts, $queryLang),
                        $authoritativePrefixes
                    );
                }
            }

            if ($includeSnippets) {
                $resultLang = $this->snippet_result_language($row, $meta, null, $opts, $queryLang);
                $snippetLength = max(40, min(
                    self::MAX_SET_ORIENTED_SNIPPET_LENGTH,
                    (int) ($opts['snippet_length'] ?? 180)
                ));
                $snippetSource = is_scalar($row['snippet_text'] ?? null)
                    ? $this->bounded_set_oriented_snippet_source((string) $row['snippet_text'])
                    : '';
                if (trim($snippetSource) === '') {
                    foreach (['search_text', 'excerpt', 'title', 'search_html'] as $sourceKey) {
                        if (!is_scalar($meta[$sourceKey] ?? null)) {
                            continue;
                        }
                        $candidateSource = $this->bounded_set_oriented_snippet_source((string) $meta[$sourceKey]);
                        if (trim($candidateSource) !== '') {
                            $snippetSource = $candidateSource;
                            break;
                        }
                    }
                }
                $row['snippet'] = $this->snippet(
                    $snippetSource,
                    $query,
                    $snippetLength,
                    $this->truthy_option($opts['highlight'] ?? false),
                    $opts,
                    // Query analysis is reused; only the bounded returned-page
                    // text is analyzed for presentation, with no extra SQL.
                    $queryGroups,
                    $queryLang,
                    $resultLang,
                    $authoritativePrefixes
                );
            } else {
                unset($row['snippet']);
            }

            unset($row['metadata'], $row['snippet_text'], $row['search_text'], $row['search_html']);
        }
        unset($row);

        return $results;
    }

    /** Highlight a complete bounded field using the search's existing groups. */
    private function highlight_analyzed_text(
        string $text,
        string $query,
        array $opts,
        array $queryGroups,
        string $queryLang,
        string $resultLang,
        ?array $authoritativePrefixes = null
    ): string {
        $length = max(40, WP_FTS_Utf8::length(WP_FTS_Html_Text_Stream::visible_text($text)) + 1);

        return $this->snippet(
            $text,
            $query,
            $length,
            true,
            $opts,
            $queryGroups,
            $queryLang,
            $resultLang,
            $authoritativePrefixes
        );
    }

    /**
     * Bound a backend sidecar before running UTF-8 repair or HTML processing.
     */
    private function bounded_set_oriented_snippet_source(string $source): string
    {
        if (strlen($source) > self::MAX_SET_ORIENTED_SNIPPET_SOURCE_BYTES) {
            $source = substr($source, 0, self::MAX_SET_ORIENTED_SNIPPET_SOURCE_BYTES);
        }

        return WP_FTS_Utf8::truncate_bytes($source, self::MAX_SET_ORIENTED_SNIPPET_SOURCE_BYTES);
    }

    /**
     * Fingerprint only inputs that can change ranking or candidate membership.
     *
     * @param array<int,array<int,array{key:string,lang:string,term:string,rank:int,source?:string,surface?:string}>> $groups
     * @param array{post_types:string[],post_statuses:string[],date_after:?string,date_before:?string}|null $metadataFilter
     * @param array{enabled:bool,strength:float,half_life_days:float,now_timestamp:int,now_gmt:string} $recencyBoost
     * @param mixed $requestedNow Caller-provided stable recency clock, if any.
     * @param int $maxCandidateRows Work budget that must not be bypassed by reuse.
     */
    private function ranked_result_cache_key(array $groups, string $mode, ?array $metadataFilter, ?int $candidateCap, array $recencyBoost, mixed $requestedNow, int $maxCandidateRows): string
    {
        unset($recencyBoost['now_timestamp'], $recencyBoost['now_gmt']);

        return hash('sha256', serialize([
            'groups' => $groups,
            'mode' => $mode,
            'metadata_filter' => $metadataFilter,
            'candidate_cap' => $candidateCap,
            'recency_boost' => $recencyBoost,
            'requested_now' => is_scalar($requestedNow) ? (string) $requestedNow : null,
            'max_candidate_rows' => $maxCandidateRows,
        ]));
    }

    /**
     * Score a language-aware query plan.
     *
     * Each group represents one logical query term. A group may contain multiple
     * language alternatives, for example exact `fr` plus fallback `en`, or the
     * same untagged query term expanded across explicit `langs`.
     *
     * @param array<int,array<int,array{key:string,lang:string,term:string,rank:int,source?:string,surface?:string}>> $groups
     * @param int|null $topLimit Keep only the best K ranked rows when callers do
     *        not need totals or offsets.
     * @param array{post_types:string[],post_statuses:string[],date_after:?string,date_before:?string}|null $metadataFilter
     *        Optional exact metadata filter to apply before scoring.
     * @param callable(int[]):array<int,mixed>|null $candidateFilter Authoritative
     *        candidate visibility filter applied before ranking.
     * @param int|null $candidateCap Approximate opt-in cap on candidate ids to
     *        score. Null keeps default exact behavior.
     * @return array<int,array{doc_id:int,score:float,_rank:int}>
     */
    private function score_query_groups(
        array $groups,
        string $mode,
        ?int $topLimit = null,
        ?array $metadataFilter = null,
        ?callable $candidateFilter = null,
        ?int $candidateCap = null,
        ?array &$stats = null
    ): array
    {
        $stats = $this->empty_score_stats();
        $termsByKey = $this->terms_by_key($groups);
        $stats['query_terms'] = count($termsByKey);

        if ($termsByKey === []) {
            return [];
        }

        $candidateRowBudget = $this->max_candidate_rows($this->activeSearchOptions);
        $storageRowCap = $candidateCap === null && $candidateRowBudget < PHP_INT_MAX
            ? $candidateRowBudget + 1
            : $candidateRowBudget;
        $this->guard_request_budget();
        $postingsByTerm = WP_FTS_StorageCompat::get_postings(
            $this->storage,
            array_keys($termsByKey),
            $candidateCap,
            $storageRowCap
        );
        $this->guard_request_budget();
        $stats['posting_terms_fetched'] = count($postingsByTerm);
        $stats['candidate_rows_fetched'] = $this->posting_row_count($postingsByTerm, $termsByKey);
        if ($stats['candidate_rows_fetched'] > $candidateRowBudget) {
            throw new WP_FTS_Search_Budget_Exceeded('candidate rows');
        }
        if ($postingsByTerm === []) {
            return [];
        }

        if ($mode === 'AND') {
            foreach ($groups as $alternatives) {
                $hasAvailableTerm = false;
                foreach ($alternatives as $alternative) {
                    if (isset($postingsByTerm[$alternative['key']])) {
                        $hasAvailableTerm = true;
                        break;
                    }
                }
                if (!$hasAvailableTerm) {
                    $stats['candidate_rows_considered'] = $stats['candidate_rows_fetched'];
                    return [];
                }
            }
        }

        /** @var array<string,array<int,int>> $decodedByTerm */
        $decodedByTerm = [];
        /** @var array<int,bool> $allCandidateDocIds */
        $allCandidateDocIds = [];
        /** @var array<int,array<int,bool>> $groupDocIds */
        $groupDocIds = [];

        foreach ($postingsByTerm as $term => $postings) {
            if (!isset($termsByKey[$term])) {
                continue;
            }

            $decodedByTerm[$term] = $postings;
        }

        foreach ($decodedByTerm as $term => $postings) {
            foreach ($postings as $docId => $tf) {
                $docId = (int) $docId;
                $allCandidateDocIds[$docId] = true;
                if ($mode === 'AND') {
                    foreach ($termsByKey[$term]['groups'] as $groupId => $_rank) {
                        $groupDocIds[$groupId][$docId] = true;
                    }
                }
            }

            if ($candidateCap !== null && $mode === 'OR' && count($allCandidateDocIds) >= $candidateCap) {
                $allCandidateDocIds = $this->cap_doc_id_set($allCandidateDocIds, $candidateCap);
                break;
            }
        }
        $stats['candidate_rows_considered'] = $this->posting_row_count($decodedByTerm, $termsByKey);
        $stats['candidate_docs_considered'] = count($allCandidateDocIds);

        if ($allCandidateDocIds === []) {
            return [];
        }

        $scoringDocIds = $mode === 'AND'
            ? $this->intersect_group_doc_ids($groupDocIds, count($groups))
            : $allCandidateDocIds;
        if ($scoringDocIds === []) {
            return [];
        }
        if ($candidateCap !== null) {
            $scoringDocIds = $this->cap_doc_id_set($scoringDocIds, $candidateCap);
        }
        $stats['candidate_docs_scored'] = count($scoringDocIds);

        // Default IDF stays based on the full active posting lists, not on the
        // later AND/metadata-restricted scoring set. Explicit candidate-capped
        // retrieval uses an approximate stored posting count instead.
        $docLengthCandidateIds = $candidateCap === null ? array_keys($allCandidateDocIds) : array_keys($scoringDocIds);
        $languages = [];
        foreach ($termsByKey as $termInfo) {
            $languages[$termInfo['lang']] = true;
        }

        /** @var array<string,array<int,int>> $docLengthsByLang */
        $docLengthsByLang = [];
        /** @var array<string,array{doc_count:int,len_sum:int}> $metaByLang */
        $metaByLang = [];
        foreach (array_keys($languages) as $lang) {
            $this->guard_request_budget();
            $docLengths = WP_FTS_StorageCompat::get_doc_lengths($this->storage, $docLengthCandidateIds, $lang);
            $this->guard_request_budget();
            if ($docLengths === []) {
                continue;
            }

            $meta = WP_FTS_StorageCompat::get_meta($this->storage, $lang);
            $this->guard_request_budget();
            if ((int) $meta['doc_count'] <= 0) {
                continue;
            }

            $docLengthsByLang[$lang] = $docLengths;
            $metaByLang[$lang] = $meta;
        }

        if ($docLengthsByLang === []) {
            return [];
        }

        /** @var array<string,array<int,bool>> $activeDocIdsByLang */
        $activeDocIdsByLang = [];
        foreach ($docLengthsByLang as $lang => $docLengths) {
            $activeDocIdsByLang[$lang] = array_fill_keys(array_keys($docLengths), true);
        }

        if ($metadataFilter !== null) {
            $this->guard_request_budget();
            $matchingDocIds = WP_FTS_StorageCompat::filter_doc_ids_by_metadata(
                $this->storage,
                array_keys($scoringDocIds),
                $metadataFilter['post_types'],
                $metadataFilter['post_statuses'],
                $metadataFilter['date_after'],
                $metadataFilter['date_before']
            );
            $this->guard_request_budget();
            $scoringDocIds = array_fill_keys($matchingDocIds, true);
            if ($scoringDocIds === []) {
                $stats['candidate_docs_scored'] = 0;
                return [];
            }
            $stats['candidate_docs_scored'] = count($scoringDocIds);
        }

        if ($candidateFilter !== null) {
            $candidateDocIds = array_keys($scoringDocIds);
            $filteredDocIds = $candidateFilter($candidateDocIds);
            if (!is_array($filteredDocIds)) {
                throw new UnexpectedValueException('candidate_doc_ids_filter must return an array of document ids.');
            }
            if (count($filteredDocIds) > count($candidateDocIds)) {
                throw new UnexpectedValueException('candidate_doc_ids_filter may not return more document ids than it received.');
            }

            $allowedDocIds = [];
            foreach ($filteredDocIds as $docId) {
                $docId = (int) $docId;
                if (isset($scoringDocIds[$docId])) {
                    $allowedDocIds[$docId] = true;
                }
            }
            $scoringDocIds = $allowedDocIds;
            if ($scoringDocIds === []) {
                $stats['candidate_docs_scored'] = 0;
                return [];
            }
            $stats['candidate_docs_scored'] = count($scoringDocIds);
        }

        /** @var array<string,array{lang:string,doc_count:int,avg_doc_len:float,idf:float,groups:array<int,int>,min_rank:int,max_rank:int,multiplier:float}> $scoringTerms */
        $scoringTerms = [];
        foreach ($decodedByTerm as $term => $postings) {
            $lang = $termsByKey[$term]['lang'];
            if (!isset($docLengthsByLang[$lang], $metaByLang[$lang], $activeDocIdsByLang[$lang])) {
                continue;
            }

            if ($candidateCap === null) {
                $df = 0;
                foreach ($postings as $docId => $_tf) {
                    if (isset($activeDocIdsByLang[$lang][(int) $docId])) {
                        $df++;
                    }
                }
            } else {
                $df = count($postings);
            }
            if ($df <= 0) {
                continue;
            }

            $meta = $metaByLang[$lang];
            $docCount = max(0, (int) $meta['doc_count']);
            if ($docCount === 0) {
                continue;
            }

            $groupsForTerm = $termsByKey[$term]['groups'];
            $avgDocLen = $meta['len_sum'] > 0 ? $meta['len_sum'] / $docCount : 1.0;
            $idf = log(1.0 + (($docCount - $df + 0.5) / ($df + 0.5)));
            $scoringTerms[$term] = [
                'lang' => $lang,
                'avg_doc_len' => $avgDocLen,
                'idf' => $idf,
                'groups' => $groupsForTerm,
            ];
        }
        if ($scoringTerms === []) {
            $stats['scoring_terms'] = 0;
            return [];
        }
        $stats['scoring_terms'] = count($scoringTerms);

        /** @var array<int,array<int,array{term:string,rank:int,score:float}>> $bestGroupMatchesByDoc */
        $bestGroupMatchesByDoc = [];
        if ($this->should_score_by_doc($scoringDocIds, $decodedByTerm, $scoringTerms, $mode, $metadataFilter, $candidateCap)) {
            $this->score_candidate_docs(
                $scoringDocIds,
                $decodedByTerm,
                $docLengthsByLang,
                $scoringTerms,
                $bestGroupMatchesByDoc
            );
        } else {
            $this->score_posting_terms(
                $scoringDocIds,
                $decodedByTerm,
                $docLengthsByLang,
                $scoringTerms,
                $bestGroupMatchesByDoc
            );
        }
        [$scores, $bestRankByDoc, $matchedGroupRanksByDoc] = $this->finalize_group_match_scores($bestGroupMatchesByDoc);
        if ($scores === []) {
            return [];
        }

        $results = [];
        foreach ($scores as $docId => $score) {
            if ($mode === 'AND' && count($matchedGroupRanksByDoc[$docId] ?? []) < count($groups)) {
                continue;
            }
            if ($score > 0.0) {
                $rank = $mode === 'AND'
                    ? max($matchedGroupRanksByDoc[$docId])
                    : ($bestRankByDoc[$docId] ?? 0);
                $row = [
                    'doc_id' => (int) $docId,
                    'score' => $score,
                    '_rank' => $rank,
                ];
                if ($topLimit === null) {
                    $results[] = $row;
                } else {
                    $this->insert_bounded_top_k_result($results, $row, $topLimit);
                }
            }
        }

        $stats['scored_results'] = count($results);

        return $results;
    }

    /**
     * @return array{query_terms:int,posting_terms_fetched:int,candidate_rows_fetched:int,candidate_rows_considered:int,candidate_docs_considered:int,candidate_docs_scored:int,scoring_terms:int,scored_results:int,ranked_results_reused:int}
     */
    private function empty_score_stats(): array
    {
        return [
            'query_terms' => 0,
            'posting_terms_fetched' => 0,
            'candidate_rows_fetched' => 0,
            'candidate_rows_considered' => 0,
            'candidate_docs_considered' => 0,
            'candidate_docs_scored' => 0,
            'scoring_terms' => 0,
            'scored_results' => 0,
            'ranked_results_reused' => 0,
        ];
    }

    /**
     * Normalize query-time recency boost controls.
     *
     * `recency_boost`/`freshness_boost` may be a boolean toggle or a numeric
     * strength. `recency_boost_strength` wins when supplied explicitly. A false
     * boost toggle or non-positive strength disables the feature.
     *
     * @param array<string,mixed> $opts
     * @return array{enabled:bool,strength:float,half_life_days:float,now_timestamp:int,now_gmt:string}
     */
    private function recency_boost_config(array $opts): array
    {
        $boostOptionPresent = array_key_exists('recency_boost', $opts) || array_key_exists('freshness_boost', $opts);
        $boostOption = $opts['recency_boost'] ?? ($opts['freshness_boost'] ?? null);
        $strengthOption = $opts['recency_boost_strength'] ?? ($opts['freshness_boost_strength'] ?? null);

        $strength = null;
        if (is_numeric($boostOption)) {
            $strength = (float) $boostOption;
        }
        if (is_numeric($strengthOption)) {
            $strength = (float) $strengthOption;
        }
        if ($strength === null && $boostOptionPresent && $this->truthy_option($boostOption)) {
            $strength = self::DEFAULT_RECENCY_BOOST_STRENGTH;
        }
        if ($boostOptionPresent && !$this->truthy_option($boostOption)) {
            $strength = 0.0;
        }

        $strength = $strength === null ? 0.0 : $this->clamp_recency_float($strength, 0.0, self::MAX_RECENCY_BOOST_STRENGTH);
        if ($strength <= 0.0) {
            $strength = 0.0;
        }

        $halfLife = self::DEFAULT_RECENCY_BOOST_HALF_LIFE_DAYS;
        $halfLifeOption = $opts['recency_boost_half_life_days']
            ?? ($opts['freshness_boost_half_life_days'] ?? ($opts['recency_boost_window_days'] ?? null));
        if (is_numeric($halfLifeOption)) {
            $halfLife = $this->clamp_recency_float(
                (float) $halfLifeOption,
                self::MIN_RECENCY_BOOST_HALF_LIFE_DAYS,
                self::MAX_RECENCY_BOOST_HALF_LIFE_DAYS
            );
        }

        $now = $this->recency_now_timestamp($opts['now_gmt'] ?? ($opts['recency_now'] ?? null));

        return [
            'enabled' => $strength > 0.0,
            'strength' => $strength,
            'half_life_days' => $halfLife,
            'now_timestamp' => $now,
            'now_gmt' => gmdate('Y-m-d H:i:s', $now),
        ];
    }

    private function clamp_recency_float(float $value, float $min, float $max): float
    {
        if (!is_finite($value)) {
            return $min;
        }

        return min($max, max($min, $value));
    }

    private function recency_now_timestamp(mixed $value): int
    {
        $timestamp = $this->parse_gmt_timestamp($value);

        return $timestamp ?? time();
    }

    private function parse_gmt_timestamp(mixed $value): ?int
    {
        if (!is_scalar($value)) {
            return null;
        }

        $text = trim((string) $value);
        if ($text === '') {
            return null;
        }

        $timezone = new DateTimeZone('UTC');
        foreach (['!Y-m-d H:i:s', '!Y-m-d\TH:i:s', '!Y-m-d'] as $format) {
            $date = DateTimeImmutable::createFromFormat($format, $text, $timezone);
            $errors = DateTimeImmutable::getLastErrors();
            $warnings = is_array($errors) ? (int) ($errors['warning_count'] ?? 0) : 0;
            $errorCount = is_array($errors) ? (int) ($errors['error_count'] ?? 0) : 0;
            if ($date instanceof DateTimeImmutable && $warnings === 0 && $errorCount === 0) {
                return $date->getTimestamp();
            }
        }

        return null;
    }

    /**
     * Apply a bounded additive recency boost to already-scored candidates.
     *
     * @param array<int,array{doc_id:int,score:float,_rank:int}> $results
     * @param array{enabled:bool,strength:float,half_life_days:float,now_timestamp:int,now_gmt:string} $config
     * @return array{enabled:bool,strength:float,half_life_days:float,now_gmt:string,documents_considered:int,documents_applied:int,metadata_unavailable:bool,missing_or_invalid_dates:int}
     */
    private function apply_recency_boost(array &$results, array $config): array
    {
        $stats = $this->empty_recency_boost_stats($config);
        if (!$config['enabled'] || $results === []) {
            return $stats;
        }

        if (!WP_FTS_StorageCompat::supports_doc_metadata($this->storage)) {
            $stats['metadata_unavailable'] = true;
            return $stats;
        }

        $docIds = array_values(array_unique(array_map('intval', array_column($results, 'doc_id'))));
        $this->guard_request_budget();
        $metadata = WP_FTS_StorageCompat::get_doc_metadata($this->storage, $docIds);
        $this->guard_request_budget();
        $stats['documents_considered'] = count($docIds);
        if ($metadata === []) {
            $stats['missing_or_invalid_dates'] = count($docIds);
            return $stats;
        }

        $secondsPerDay = 86400.0;
        $halfLifeDays = max(self::MIN_RECENCY_BOOST_HALF_LIFE_DAYS, (float) $config['half_life_days']);
        foreach ($results as &$row) {
            $docId = (int) $row['doc_id'];
            $timestamp = $this->parse_gmt_timestamp($metadata[$docId]['post_date_gmt'] ?? null);
            if ($timestamp === null) {
                $stats['missing_or_invalid_dates']++;
                continue;
            }

            $ageDays = max(0.0, ((float) $config['now_timestamp'] - (float) $timestamp) / $secondsPerDay);
            $boost = (float) $config['strength'] * exp(-$ageDays / $halfLifeDays);
            if ($boost <= 0.0) {
                continue;
            }

            $row['score'] = (float) $row['score'] + $boost;
            $stats['documents_applied']++;
        }
        unset($row);

        return $stats;
    }

    /**
     * @param array{enabled:bool,strength:float,half_life_days:float,now_timestamp:int,now_gmt:string} $config
     * @return array{enabled:bool,strength:float,half_life_days:float,now_gmt:string,documents_considered:int,documents_applied:int,metadata_unavailable:bool,missing_or_invalid_dates:int}
     */
    private function empty_recency_boost_stats(array $config): array
    {
        return [
            'enabled' => (bool) $config['enabled'],
            'strength' => (float) $config['strength'],
            'half_life_days' => (float) $config['half_life_days'],
            'now_gmt' => (string) $config['now_gmt'],
            'documents_considered' => 0,
            'documents_applied' => 0,
            'metadata_unavailable' => false,
            'missing_or_invalid_dates' => 0,
        ];
    }

    /**
     * Keep the first N candidate ids in deterministic posting order.
     *
     * @param array<int,bool> $docIds
     * @return array<int,bool>
     */
    private function cap_doc_id_set(array $docIds, int $candidateCap): array
    {
        $candidateCap = max(1, $candidateCap);
        if (count($docIds) <= $candidateCap) {
            return $docIds;
        }

        return array_slice($docIds, 0, $candidateCap, true);
    }

    /**
     * Pick a scoring loop that avoids scanning irrelevant posting rows.
     *
     * @param array<int,bool> $scoringDocIds
     * @param array<string,array<int,int>> $decodedByTerm
     * @param array<string,array{lang:string,avg_doc_len:float,idf:float,groups:array<int,int>}> $scoringTerms
     * @param array{post_types:string[],post_statuses:string[],date_after:?string,date_before:?string}|null $metadataFilter
     */
    private function should_score_by_doc(
        array $scoringDocIds,
        array $decodedByTerm,
        array $scoringTerms,
        string $mode,
        ?array $metadataFilter,
        ?int $candidateCap
    ): bool {
        if ($candidateCap !== null || $mode === 'AND' || $metadataFilter !== null) {
            return true;
        }

        return count($scoringDocIds) * max(1, count($scoringTerms)) < $this->posting_row_count($decodedByTerm, $scoringTerms);
    }

    /**
     * @param array<string,array<int,int>> $decodedByTerm
     * @param array<string,array{lang:string,avg_doc_len:float,idf:float,groups:array<int,int>}> $scoringTerms
     */
    private function posting_row_count(array $decodedByTerm, array $scoringTerms): int
    {
        $count = 0;
        foreach (array_keys($scoringTerms) as $term) {
            $count += count($decodedByTerm[$term] ?? []);
        }

        return $count;
    }

    /**
     * Score by candidate document when the candidate set is narrower than the
     * fetched postings.
     *
     * @param array<int,bool> $scoringDocIds
     * @param array<string,array<int,int>> $decodedByTerm
     * @param array<string,array<int,int>> $docLengthsByLang
     * @param array<string,array{lang:string,avg_doc_len:float,idf:float,groups:array<int,int>}> $scoringTerms
     * @param array<int,array<int,array{term:string,rank:int,score:float}>> $bestGroupMatchesByDoc
     */
    private function score_candidate_docs(
        array $scoringDocIds,
        array $decodedByTerm,
        array $docLengthsByLang,
        array $scoringTerms,
        array &$bestGroupMatchesByDoc
    ): void {
        $iteration = 0;
        foreach ($scoringDocIds as $docId => $_present) {
            $this->guard_request_budget_interval($iteration++);
            $docId = (int) $docId;
            foreach ($scoringTerms as $term => $termInfo) {
                $lang = $termInfo['lang'];
                if (!isset($decodedByTerm[$term][$docId], $docLengthsByLang[$lang][$docId])) {
                    continue;
                }

                $baseScore = $this->bm25_with_idf(
                    (int) $decodedByTerm[$term][$docId],
                    $docLengthsByLang[$lang][$docId],
                    $termInfo['idf'],
                    $termInfo['avg_doc_len']
                );
                if ($baseScore <= 0.0) {
                    continue;
                }

                $this->record_best_group_matches($bestGroupMatchesByDoc, $docId, $term, $baseScore, $termInfo['groups']);
            }
        }
    }

    /**
     * Score by posting row for broad exact queries where every fetched row is a
     * real candidate.
     *
     * @param array<int,bool> $scoringDocIds
     * @param array<string,array<int,int>> $decodedByTerm
     * @param array<string,array<int,int>> $docLengthsByLang
     * @param array<string,array{lang:string,avg_doc_len:float,idf:float,groups:array<int,int>}> $scoringTerms
     * @param array<int,array<int,array{term:string,rank:int,score:float}>> $bestGroupMatchesByDoc
     */
    private function score_posting_terms(
        array $scoringDocIds,
        array $decodedByTerm,
        array $docLengthsByLang,
        array $scoringTerms,
        array &$bestGroupMatchesByDoc
    ): void {
        $iteration = 0;
        foreach ($scoringTerms as $term => $termInfo) {
            $lang = $termInfo['lang'];
            $docLengths = $docLengthsByLang[$lang];
            foreach ($decodedByTerm[$term] as $docId => $tf) {
                $this->guard_request_budget_interval($iteration++);
                $docId = (int) $docId;
                if (!isset($scoringDocIds[$docId], $docLengths[$docId])) {
                    continue;
                }

                $baseScore = $this->bm25_with_idf(
                    (int) $tf,
                    $docLengths[$docId],
                    $termInfo['idf'],
                    $termInfo['avg_doc_len']
                );
                if ($baseScore <= 0.0) {
                    continue;
                }

                $this->record_best_group_matches($bestGroupMatchesByDoc, $docId, $term, $baseScore, $termInfo['groups']);
            }
        }
    }

    /**
     * Keep one best stored-term interpretation for each logical query token.
     *
     * Lower analyzer ranks win before BM25 so an exact lemma is not displaced
     * by a rarer fallback. Equal-rank alternatives compete by their actual BM25
     * contribution instead of being added together.
     *
     * @param array<int,array<int,array{term:string,rank:int,score:float}>> $bestGroupMatchesByDoc
     * @param array<int,int> $groupRanks
     */
    private function record_best_group_matches(array &$bestGroupMatchesByDoc, int $docId, string $term, float $baseScore, array $groupRanks): void
    {
        foreach ($groupRanks as $groupId => $rank) {
            $rank = max(0, (int) $rank);
            $candidate = [
                'term' => $term,
                'rank' => $rank,
                'score' => $baseScore * $this->query_rank_score_multiplier($rank),
            ];
            $current = $bestGroupMatchesByDoc[$docId][$groupId] ?? null;
            if (
                $current === null
                || $candidate['rank'] < $current['rank']
                || $candidate['rank'] === $current['rank'] && $candidate['score'] > $current['score']
                || $candidate['rank'] === $current['rank'] && $candidate['score'] === $current['score'] && strcmp($candidate['term'], $current['term']) < 0
            ) {
                $bestGroupMatchesByDoc[$docId][$groupId] = $candidate;
            }
        }
    }

    /**
     * Collapse selected logical-group matches into public score/rank maps.
     *
     * One stored key can represent repeated query surfaces that stem to the
     * same term. It is counted once, preserving the previous query-term-frequency
     * behavior while still choosing only one alternative inside each group.
     *
     * @param array<int,array<int,array{term:string,rank:int,score:float}>> $bestGroupMatchesByDoc
     * @return array{0:array<int,float>,1:array<int,int>,2:array<int,array<int,int>>}
     */
    private function finalize_group_match_scores(array $bestGroupMatchesByDoc): array
    {
        $scores = [];
        $bestRankByDoc = [];
        $matchedGroupRanksByDoc = [];
        foreach ($bestGroupMatchesByDoc as $docId => $groupMatches) {
            $termScores = [];
            foreach ($groupMatches as $groupId => $match) {
                $matchedGroupRanksByDoc[$docId][$groupId] = $match['rank'];
                $bestRankByDoc[$docId] = min($bestRankByDoc[$docId] ?? $match['rank'], $match['rank']);
                $termScores[$match['term']] = max($termScores[$match['term']] ?? 0.0, $match['score']);
            }
            $scores[$docId] = array_sum($termScores);
        }

        return [$scores, $bestRankByDoc, $matchedGroupRanksByDoc];
    }

    /**
     * Intersect AND query candidate groups, starting with the rarest group.
     *
     * @param array<int,array<int,bool>> $groupDocIds
     * @return array<int,bool>
     */
    private function intersect_group_doc_ids(array $groupDocIds, int $groupCount): array
    {
        if (count($groupDocIds) < $groupCount) {
            return [];
        }

        uasort($groupDocIds, static fn(array $a, array $b): int => count($a) <=> count($b));
        $intersected = null;
        foreach ($groupDocIds as $docIds) {
            if ($docIds === []) {
                return [];
            }

            if ($intersected === null) {
                $intersected = $docIds;
                continue;
            }

            foreach (array_keys($intersected) as $docId) {
                if (!isset($docIds[(int) $docId])) {
                    unset($intersected[$docId]);
                }
            }
            if ($intersected === []) {
                return [];
            }
        }

        return $intersected ?? [];
    }

    /**
     * Flatten logical query groups into stored term metadata for scoring/probing.
     *
     * @param array<int,array<int,array{key:string,lang:string,term:string,rank:int}>> $groups
     * @return array<string,array{lang:string,groups:array<int,int>}>
     */
    private function terms_by_key(array $groups): array
    {
        $termsByKey = [];
        foreach ($groups as $groupId => $alternatives) {
            foreach ($alternatives as $alternative) {
                $key = $alternative['key'];
                $termsByKey[$key]['lang'] = $alternative['lang'];
                $termsByKey[$key]['groups'][$groupId] = min(
                    $termsByKey[$key]['groups'][$groupId] ?? $alternative['rank'],
                    $alternative['rank']
                );
            }
        }

        return $termsByKey;
    }

    /**
     * Use bounded top-K only when callers need the first page by ranking.
     */
    private function can_use_bounded_top_k(array $opts, int $offset): bool
    {
        return $offset === 0
            && empty($opts['include_total']);
    }

    /**
     * Resolve exact default retrieval or an explicit approximate candidate cap.
     *
     * `candidate_cap`/`max_candidates` alone are inert so callers cannot
     * accidentally degrade recall. `fast_top_k` may be boolean or an integer cap.
     */
    private function resolve_fast_mode(
        array $opts,
        int $minimumCandidates,
        bool $hasCandidateFilter = false
    ): array
    {
        if ($hasCandidateFilter) {
            return [
                'mode' => 'exact',
                'source' => 'candidate_filter',
                'estimated_candidates' => null,
                'threshold' => null,
                'candidate_cap' => null,
                'reason' => $this->fast_mode_reason('candidate_filter', null),
            ];
        }
        if ($this->explicit_exact_top_k_requested($opts)) {
            return [
                'mode' => 'exact',
                'source' => 'forced_exact',
                'estimated_candidates' => null,
                'threshold' => null,
                'candidate_cap' => null,
                'reason' => $this->fast_mode_reason('forced_exact', null),
            ];
        }

        $fastTopK = $this->explicit_fast_top_k_value($opts);
        if ($fastTopK !== null) {
            $candidateCap = $this->resolved_fast_candidate_cap($opts, $minimumCandidates, $fastTopK);

            return [
                'mode' => 'approximate',
                'source' => 'explicit_option',
                'estimated_candidates' => null,
                'threshold' => null,
                'candidate_cap' => $candidateCap,
                'reason' => $this->fast_mode_reason('explicit_option', $candidateCap),
            ];
        }

        return [
            'mode' => 'exact',
            'source' => 'default_exact',
            'estimated_candidates' => null,
            'threshold' => null,
            'candidate_cap' => null,
            'reason' => $this->fast_mode_reason('default_exact', null),
        ];
    }

    private function fast_mode_reason(string $source, ?int $candidateCap): string
    {
        if ($source === 'forced_exact') {
            return 'Exact scoring was explicitly requested, so candidate-capped retrieval was disabled.';
        }
        if ($source === 'candidate_filter') {
            return 'An authoritative candidate filter requires exact discovery before ranking, so candidate-capped retrieval was disabled.';
        }
        if ($source === 'explicit_option') {
            return sprintf(
                'Candidate-capped retrieval was explicitly requested with a cap of %d; recall, ranking, and totals may be incomplete.',
                max(1, (int) $candidateCap)
            );
        }
        if ($source === 'default_exact') {
            return 'Exact retrieval is the default because a document-id candidate cap cannot guarantee the highest-ranked results or complete totals.';
        }

        return 'Fast-mode decision used the default exact scoring path.';
    }

    /**
     * Return the optional authoritative candidate filter.
     *
     * @return callable(int[]):array<int,mixed>|null
     */
    private function candidate_doc_ids_filter(array $opts): ?callable
    {
        if (!array_key_exists('candidate_doc_ids_filter', $opts) || $opts['candidate_doc_ids_filter'] === null) {
            return null;
        }
        if (!is_callable($opts['candidate_doc_ids_filter'])) {
            throw new InvalidArgumentException('candidate_doc_ids_filter must be callable.');
        }

        return $opts['candidate_doc_ids_filter'];
    }

    /**
     * Restrict document-level explain reads without changing ranked results.
     *
     * @param array<int,array{doc_id:int,score:float,_rank:int}> $page
     * @return array<int,array{doc_id:int,score:float,_rank:int}>
     */
    private function filter_explain_result_page(array $page, array $opts): array
    {
        if (!array_key_exists('explain_doc_ids_filter', $opts) || $opts['explain_doc_ids_filter'] === null) {
            return $page;
        }
        if (!is_callable($opts['explain_doc_ids_filter'])) {
            throw new InvalidArgumentException('explain_doc_ids_filter must be callable.');
        }

        $this->guard_request_budget();
        $pageDocIds = array_map('intval', array_column($page, 'doc_id'));
        $filteredDocIds = ($opts['explain_doc_ids_filter'])($pageDocIds);
        $this->guard_request_budget();
        if (!is_array($filteredDocIds)) {
            throw new UnexpectedValueException('explain_doc_ids_filter must return an array of document ids.');
        }
        if (count($filteredDocIds) > count($pageDocIds)) {
            throw new UnexpectedValueException('explain_doc_ids_filter may not return more document ids than it received.');
        }

        $allowedDocIds = array_fill_keys(array_map('intval', $filteredDocIds), true);

        return array_values(array_filter(
            $page,
            static fn(array $row): bool => isset($allowedDocIds[(int) $row['doc_id']])
        ));
    }

    /**
     * Return a caller-requested fast option value, or null when fast mode was not
     * explicitly requested.
     */
    private function explicit_fast_top_k_value(array $opts): mixed
    {
        foreach (['fast_top_k', 'approximate_top_k'] as $key) {
            if (array_key_exists($key, $opts) && $this->truthy_option($opts[$key])) {
                return $opts[$key];
            }
        }

        return null;
    }

    /**
     * Detect public options that deliberately force exact scoring.
     */
    private function explicit_exact_top_k_requested(array $opts): bool
    {
        if ($this->truthy_option($opts['exact_top_k'] ?? false) || $this->truthy_option($opts['exact'] ?? false)) {
            return true;
        }

        $hasFastOption = false;
        foreach (['fast_top_k', 'approximate_top_k'] as $key) {
            if (array_key_exists($key, $opts)) {
                $hasFastOption = true;
                if ($this->truthy_option($opts[$key])) {
                    return false;
                }
            }
        }

        return $hasFastOption;
    }

    /**
     * Resolve the cap used once fast/approximate mode is active.
     */
    private function resolved_fast_candidate_cap(array $opts, int $minimumCandidates, mixed $fastTopK): int
    {
        $cap = $this->positive_int_option($opts['candidate_cap'] ?? $opts['max_candidates'] ?? null);
        if ($cap === null && is_numeric($fastTopK) && (int) $fastTopK > 1) {
            $cap = (int) $fastTopK;
        }
        if ($cap === null) {
            $cap = $this->default_fast_candidate_cap();
        }

        return max(max(1, $minimumCandidates), $cap);
    }

    /**
     * Default cap used after explicit approximate opt-in when no cap is supplied.
     */
    private function default_fast_candidate_cap(): int
    {
        if (defined('WP_FTS_FAST_MODE_CANDIDATE_CAP')) {
            $cap = $this->positive_int_option(constant('WP_FTS_FAST_MODE_CANDIDATE_CAP'));
            if ($cap !== null) {
                return $cap;
            }
        }

        return self::DEFAULT_FAST_MODE_CANDIDATE_CAP;
    }

    /**
     * Let a request owner stop work between bounded storage and scoring steps.
     */
    private function guard_request_budget(): void
    {
        if (!is_callable($this->activeRequestBudgetGuard)) {
            return;
        }

        if (($this->activeRequestBudgetGuard)() === false) {
            throw new WP_FTS_Search_Budget_Exceeded('request circuit breaker');
        }
    }

    private function guard_request_budget_interval(int $iteration): void
    {
        if ($iteration % self::BUDGET_GUARD_INTERVAL === 0) {
            $this->guard_request_budget();
        }
    }

    /**
     * Return a positive integer option or null for unset/invalid values.
     */
    private function positive_int_option(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value > 0 ? $value : null;
        }

        if (is_string($value) && preg_match('/^[1-9][0-9]*$/', trim($value)) === 1) {
            return (int) trim($value);
        }

        if (is_float($value) && $value >= 1.0) {
            return (int) $value;
        }

        return null;
    }

    /**
     * Return a non-negative integer option or null for unset/invalid values.
     */
    private function non_negative_int_option(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value >= 0 ? $value : null;
        }

        if (is_string($value) && preg_match('/^(0|[1-9][0-9]*)$/', trim($value)) === 1) {
            return (int) trim($value);
        }

        if (is_float($value) && $value >= 0.0) {
            return (int) $value;
        }

        return null;
    }

    /**
     * Compare rows by public search ordering: exact/fallback rank, score, doc id.
     *
     * @param array{doc_id:int,score:float,_rank?:int} $a
     * @param array{doc_id:int,score:float,_rank?:int} $b
     */
    private static function compare_ranked_results(array $a, array $b): int
    {
        $rankOrder = ($a['_rank'] ?? 0) <=> ($b['_rank'] ?? 0);
        if ($rankOrder !== 0) {
            return $rankOrder;
        }

        $scoreOrder = $b['score'] <=> $a['score'];

        return $scoreOrder !== 0 ? $scoreOrder : ($a['doc_id'] <=> $b['doc_id']);
    }

    /**
     * Keep exact query candidates strongest while allowing secondary pack
     * candidates to contribute recall.
     *
     */
    private function query_rank_score_multiplier(int $rank): float
    {
        return 1.0 / (1.0 + max(0, $rank));
    }

    /**
     * Maintain a sorted bounded result page without materializing all scored rows.
     *
     * @param array<int,array{doc_id:int,score:float,_rank:int}> $topResults
     * @param array{doc_id:int,score:float,_rank:int} $row
     */
    private function insert_bounded_top_k_result(array &$topResults, array $row, int $limit): void
    {
        $limit = max(1, $limit);
        $count = count($topResults);
        if ($count >= $limit && self::compare_ranked_results($row, $topResults[$count - 1]) >= 0) {
            return;
        }

        $insertAt = $count;
        while ($insertAt > 0 && self::compare_ranked_results($row, $topResults[$insertAt - 1]) < 0) {
            $insertAt--;
        }

        array_splice($topResults, $insertAt, 0, [$row]);
        if (count($topResults) > $limit) {
            array_pop($topResults);
        }
    }

    /**
     * Build logical query term groups from explicit languages or analyzer output.
     *
     * The legacy path analyzes once and therefore preserves existing callers'
     * single-language behavior. `langs` and `languages` intentionally analyze
     * the same query under each requested language, adding those language terms
     * as alternatives for each logical query term.
     *
     * @return array<int,array<int,array{key:string,lang:string,term:string,rank:int,source?:string,surface?:string}>>
     */
    private function build_query_groups(string $query, array $opts): array
    {
        return $this->build_query_plan($query, $opts)['groups'];
    }

    /**
     * Build the executable query groups plus a compact diagnostics summary.
     *
     * @return array{groups:array<int,array<int,array{key:string,lang:string,term:string,rank:int,source?:string,surface?:string}>>,pre_prefix_groups:array<int,array<int,array{key:string,lang:string,term:string,rank:int,source?:string,surface?:string}>>,prefix_matching:bool,prefix_added_terms:int,prefix_min_length:int,prefix_max_terms:int}
     */
    private function build_query_plan(string $query, array $opts): array
    {
        $requestedLangs = $this->languages_from_options($opts, ['langs', 'languages']);
        if ($requestedLangs !== []) {
            $groups = [];
            foreach ($requestedLangs as $lang) {
                $this->merge_language_groups($groups, $query, $opts, $lang, 0);
            }

            foreach ($this->fallback_languages($opts, $requestedLangs) as $fallbackLang) {
                $this->merge_language_groups($groups, $query, $opts, $fallbackLang, 1);
            }

            return $this->query_plan_from_base_groups($groups, $opts);
        }

        $explicitQueryLang = WP_FTS_TermNamespace::language_from_options($opts, null, ['query_lang', 'lang', 'language']);
        $queryOccurrences = $explicitQueryLang === null
            ? $this->analyze_query($query, $opts)
            : $this->analyze_query($query, $this->with_query_language($opts, $explicitQueryLang));
        $queryLang = $explicitQueryLang ?? $this->resolve_query_language($opts, $queryOccurrences);
        $groups = $this->groups_from_occurrences($queryOccurrences, $queryLang, 0, $explicitQueryLang);
        $exactLangs = $explicitQueryLang === null ? [] : [$queryLang];

        foreach ($this->fallback_languages($opts, $exactLangs) as $fallbackLang) {
            $this->merge_fallback_language_groups($groups, $query, $opts, $fallbackLang);
        }

        return $this->query_plan_from_base_groups($groups, $opts);
    }

    /**
     * @param array<int,array<int,array{key:string,lang:string,term:string,rank:int,source?:string,surface?:string}>> $groups
     * @return array{groups:array<int,array<int,array{key:string,lang:string,term:string,rank:int,source?:string,surface?:string}>>,pre_prefix_groups:array<int,array<int,array{key:string,lang:string,term:string,rank:int,source?:string,surface?:string}>>,prefix_matching:bool,prefix_added_terms:int,prefix_min_length:int,prefix_max_terms:int}
     */
    private function query_plan_from_base_groups(array $groups, array $opts): array
    {
        $prePrefixGroups = $this->dedupe_query_groups($groups);
        if ($this->query_group_term_count($prePrefixGroups) > $this->max_query_terms($opts)) {
            throw new WP_FTS_Search_Budget_Exceeded('analyzed terms');
        }
        $expandedGroups = $this->expand_prefix_query_groups($prePrefixGroups, $opts);

        return [
            'groups' => $expandedGroups,
            'pre_prefix_groups' => $prePrefixGroups,
            'prefix_matching' => $this->prefix_matching_enabled($opts),
            'prefix_added_terms' => max(0, $this->query_group_term_count($expandedGroups) - $this->query_group_term_count($prePrefixGroups)),
            'prefix_min_length' => $this->prefix_min_length($opts),
            'prefix_max_terms' => $this->prefix_max_terms($opts),
        ];
    }

    /**
     * @param array<int,array<int,array{key:string,lang:string,term:string,rank:int,source?:string,surface?:string}>> $groups
     */
    private function query_group_term_count(array $groups): int
    {
        $count = 0;
        foreach ($groups as $group) {
            $count += count($group);
        }

        return $count;
    }

    /**
     * Add indexed prefix alternatives inside each logical query group.
     *
     * Exact/analyzer candidates keep their original ranks. Prefix-expanded
     * stored terms receive a worse rank in the same group, so ranking and AND
     * semantics still prefer exact matches while allowing broader recall.
     *
     * @param array<int,array<int,array{key:string,lang:string,term:string,rank:int,source?:string,surface?:string}>> $groups
     * @return array<int,array<int,array{key:string,lang:string,term:string,rank:int,source?:string,surface?:string}>>
     */
    private function expand_prefix_query_groups(array $groups, array $opts): array
    {
        if (!$this->prefix_matching_enabled($opts) || $groups === []) {
            return $groups;
        }

        $minLength = $this->prefix_min_length($opts);
        $maxTerms = $this->prefix_max_terms($opts);
        $remainingExpansions = $this->max_prefix_expansions($opts);
        if ($remainingExpansions <= 0) {
            return $groups;
        }
        $expanded = [];
        foreach ($groups as $group) {
            $byKey = [];
            $maxRank = 0;
            foreach ($group as $candidate) {
                $byKey[$candidate['key']] = $candidate;
                $maxRank = max($maxRank, (int) $candidate['rank']);
            }

            $prefixRank = $maxRank + 1;
            foreach ($group as $candidate) {
                if ($remainingExpansions <= 0) {
                    break;
                }
                if (strlen($candidate['term']) < $minLength) {
                    continue;
                }

                $prefix = WP_FTS_TermNamespace::namespace_term($candidate['lang'], $candidate['term']);
                $this->guard_request_budget();
                $prefixTerms = WP_FTS_StorageCompat::terms_with_prefix(
                    $this->storage,
                    $prefix,
                    min($maxTerms, $remainingExpansions)
                );
                $this->guard_request_budget();
                foreach ($prefixTerms as $termKey) {
                    if (isset($byKey[$termKey])) {
                        continue;
                    }

                    $split = WP_FTS_TermNamespace::split_term($termKey);
                    if ($split === null || $split['lang'] !== $candidate['lang'] || $split['term'] === '') {
                        continue;
                    }

                    $byKey[$termKey] = [
                        'key' => $termKey,
                        'lang' => $split['lang'],
                        'term' => $split['term'],
                        'rank' => $prefixRank,
                        'source' => 'prefix_expansion',
                    ];
                    if (isset($candidate['surface']) && is_scalar($candidate['surface']) && (string) $candidate['surface'] !== '') {
                        $byKey[$termKey]['surface'] = (string) $candidate['surface'];
                    }
                    $remainingExpansions--;
                    if ($remainingExpansions <= 0) {
                        break;
                    }
                }
            }

            $expanded[] = array_values($byKey);
        }

        return $this->dedupe_query_groups($expanded);
    }

    /**
     * Native prefix matching is opt-in for direct searcher callers.
     */
    private function prefix_matching_enabled(array $opts): bool
    {
        if (array_key_exists('prefix_matching', $opts)) {
            return $this->truthy_option($opts['prefix_matching']);
        }

        if (array_key_exists('prefix', $opts)) {
            return $this->truthy_option($opts['prefix']);
        }

        if (defined('WP_FTS_PREFIX_MATCHING')) {
            return $this->truthy_option(constant('WP_FTS_PREFIX_MATCHING'));
        }

        return false;
    }

    /**
     * Minimum analyzed term length before prefix expansion is attempted.
     */
    private function prefix_min_length(array $opts): int
    {
        $value = $this->non_negative_int_option($opts['prefix_min_length'] ?? null);
        if ($value !== null) {
            return $value;
        }

        if (defined('WP_FTS_PREFIX_MIN_LENGTH')) {
            $value = $this->non_negative_int_option(constant('WP_FTS_PREFIX_MIN_LENGTH'));
            if ($value !== null) {
                return $value;
            }
        }

        return self::DEFAULT_PREFIX_MIN_LENGTH;
    }

    /**
     * Maximum stored terms added per analyzed query candidate.
     */
    private function prefix_max_terms(array $opts): int
    {
        $value = $this->positive_int_option($opts['prefix_max_terms'] ?? null);
        if ($value !== null) {
            return $value;
        }

        if (defined('WP_FTS_PREFIX_MAX_TERMS')) {
            $value = $this->positive_int_option(constant('WP_FTS_PREFIX_MAX_TERMS'));
            if ($value !== null) {
                return $value;
            }
        }

        return self::DEFAULT_PREFIX_MAX_TERMS;
    }

    /**
     * Maximum analyzed alternatives across the complete query plan.
     */
    private function max_query_terms(array $opts): int
    {
        return $this->positive_int_option($opts['max_query_terms'] ?? null)
            ?? self::DEFAULT_MAX_QUERY_TERMS;
    }

    /**
     * Maximum stored prefix alternatives added across the complete request.
     */
    private function max_prefix_expansions(array $opts): int
    {
        return $this->non_negative_int_option($opts['max_prefix_expansions'] ?? null)
            ?? self::DEFAULT_MAX_PREFIX_EXPANSIONS;
    }

    /**
     * Maximum decoded posting rows materialized for one scoring pass.
     */
    private function max_candidate_rows(array $opts): int
    {
        return $this->positive_int_option($opts['max_candidate_rows'] ?? null)
            ?? self::DEFAULT_MAX_CANDIDATE_ROWS;
    }

    /**
     * Analyze a query under one language and merge it by term position.
     *
     * @param array<int,array<int,array{key:string,lang:string,term:string,rank:int,source?:string,surface?:string}>> $groups
     */
    private function merge_language_groups(array &$groups, string $query, array $opts, string $lang, int $rank): void
    {
        $occurrences = $this->analyze_query($query, $this->with_query_language($opts, $lang));
        $newGroups = $this->groups_from_occurrences($occurrences, $lang, $rank, $lang);

        foreach ($newGroups as $index => $newGroup) {
            if (isset($groups[$index])) {
                array_push($groups[$index], ...$newGroup);
                continue;
            }

            $groups[$index] = $newGroup;
        }
    }

    /**
     * Add fallback-language alternatives without query-wide exact-language suppression.
     *
     * Inline tags and resolver-selected languages can mix partitions in one
     * query. A fallback candidate is therefore skipped only when the same
     * logical group already has the exact candidate key.
     *
     * @param array<int,array<int,array{key:string,lang:string,term:string,rank:int}>> $groups
     */
    private function merge_fallback_language_groups(array &$groups, string $query, array $opts, string $lang): void
    {
        $occurrences = $this->analyze_query($query, $this->with_query_language($opts, $lang));
        $fallbackGroups = $this->groups_from_occurrences($occurrences, $lang, 1, $lang);

        foreach ($fallbackGroups as $index => $fallbackGroup) {
            if (!isset($groups[$index])) {
                $groups[$index] = $fallbackGroup;
                continue;
            }

            $exactKeys = [];
            foreach ($groups[$index] as $candidate) {
                if ($candidate['rank'] === 0) {
                    $exactKeys[$candidate['key']] = true;
                }
            }

            foreach ($fallbackGroup as $candidate) {
                if (!isset($exactKeys[$candidate['key']])) {
                    $groups[$index][] = $candidate;
                }
            }
        }
    }

    /**
     * Force analyzer options to a specific query language.
     *
     * @return array<string,mixed>
     */
    private function with_query_language(array $opts, string $lang): array
    {
        $opts['query_lang'] = $lang;
        $opts['lang'] = $lang;
        $opts['language'] = $lang;
        $opts['_force_query_lang'] = true;

        return $opts;
    }

    /**
     * Convert analyzer occurrences into one-language alternatives.
     *
     * @param array<int,array<string,mixed>|string> $occurrences
     * @param string|null $authoritativeLang Language partition that overrides
     *        inline or analyzer-selected languages for explicit/fallback passes.
     * @return array<int,array<int,array{key:string,lang:string,term:string,rank:int,source?:string,surface?:string}>>
     */
    private function groups_from_occurrences(array $occurrences, string $defaultLang, int $rank, ?string $authoritativeLang = null): array
    {
        $groups = [];
        $groupByPosition = [];
        foreach ($occurrences as $occurrence) {
            $candidate = $this->candidate_from_occurrence($occurrence, $defaultLang, $rank, $authoritativeLang);
            if ($candidate !== null) {
                $position = $this->occurrence_position($occurrence);
                if ($position !== null && array_key_exists($position, $groupByPosition)) {
                    $groups[$groupByPosition[$position]][] = $candidate;
                    continue;
                }

                $groups[] = [$candidate];
                if ($position !== null) {
                    $groupByPosition[$position] = count($groups) - 1;
                }
            }
        }

        return $groups;
    }

    /**
     * Return the analyzer token-position marker when present.
     */
    private function occurrence_position(array|string $occurrence): ?string
    {
        if (!is_array($occurrence) || !isset($occurrence['position']) || !is_scalar($occurrence['position'])) {
            return null;
        }

        return (string) $occurrence['position'];
    }

    /**
     * Normalize one analyzer occurrence into a stored term-key candidate.
     *
     * @param array<string,mixed>|string $occurrence
     * @param string|null $authoritativeLang Language partition that must own the
     *        candidate, regardless of inline tags or namespaced legacy terms.
     * @return array{key:string,lang:string,term:string,rank:int,source:string,surface?:string}|null
     */
    private function candidate_from_occurrence(array|string $occurrence, string $defaultLang, int $rank, ?string $authoritativeLang = null): ?array
    {
        $rawTerm = is_array($occurrence) ? ($occurrence['term'] ?? '') : $occurrence;
        if (!is_scalar($rawTerm)) {
            return null;
        }
        $rawTerm = (string) $rawTerm;
        if (strlen($rawTerm) > WP_FTS_TermNamespace::MAX_TERM_KEY_BYTES) {
            throw new WP_FTS_Search_Budget_Exceeded('analyzer occurrence bytes');
        }
        $term = trim($rawTerm);
        if ($term === '') {
            return null;
        }

        if (strlen($defaultLang) > self::MAX_SET_ORIENTED_LANGUAGE_BYTES) {
            throw new WP_FTS_Search_Budget_Exceeded('analyzer language bytes');
        }
        $defaultLang = WP_FTS_TermNamespace::canonicalize_lang($defaultLang);
        if ($authoritativeLang !== null && strlen($authoritativeLang) > self::MAX_SET_ORIENTED_LANGUAGE_BYTES) {
            throw new WP_FTS_Search_Budget_Exceeded('analyzer language bytes');
        }
        $authoritativeLang = $authoritativeLang === null
            ? null
            : WP_FTS_TermNamespace::canonicalize_lang($authoritativeLang, $defaultLang);
        $split = WP_FTS_TermNamespace::split_term($term);
        if ($split !== null) {
            $lang = $authoritativeLang ?? $split['lang'];
            $term = $split['term'];
        } else {
            $occurrenceLang = is_array($occurrence) && isset($occurrence['lang'])
                ? $occurrence['lang']
                : null;
            if ($occurrenceLang !== null && (!is_scalar($occurrenceLang) || strlen((string) $occurrenceLang) > self::MAX_SET_ORIENTED_LANGUAGE_BYTES)) {
                throw new WP_FTS_Search_Budget_Exceeded('analyzer language bytes');
            }
            $lang = $authoritativeLang ?? ($occurrenceLang !== null
                ? WP_FTS_TermNamespace::canonicalize_lang((string) $occurrenceLang, $defaultLang)
                : $defaultLang);
        }

        if ($term === '') {
            return null;
        }

        if (!WP_FTS_TermNamespace::term_key_fits($term, $lang)) {
            return null;
        }

        $occurrenceRank = is_array($occurrence) && isset($occurrence['rank']) && is_numeric($occurrence['rank'])
            ? max(0, (int) $occurrence['rank'])
            : 0;

        $source = $rank > 0
            ? 'fallback_language'
            : ($occurrenceRank > 0 ? 'secondary_lemma' : 'exact');

        $candidate = [
            'key' => WP_FTS_TermNamespace::namespace_term($lang, $term),
            'lang' => $lang,
            'term' => $term,
            'rank' => $rank + $occurrenceRank,
            'source' => $source,
        ];
        if (is_array($occurrence) && isset($occurrence['surface']) && is_scalar($occurrence['surface'])) {
            $rawSurface = (string) $occurrence['surface'];
            if (strlen($rawSurface) > self::MAX_SET_ORIENTED_OCCURRENCE_TEXT_BYTES) {
                throw new WP_FTS_Search_Budget_Exceeded('analyzer occurrence bytes');
            }
            $surface = trim($rawSurface);
            if ($surface !== '') {
                $candidate['surface'] = $surface;
            }
        }

        return $candidate;
    }

    /**
     * Parse a list of exact query languages from public search options.
     *
     * @param string[] $keys
     * @return string[]
     */
    private function languages_from_options(array $opts, array $keys): array
    {
        $languages = [];
        foreach ($keys as $key) {
            if (!array_key_exists($key, $opts)) {
                continue;
            }

            foreach ($this->languages_from_value($opts[$key]) as $lang) {
                $languages[$lang] = true;
            }
        }

        return array_keys($languages);
    }

    /**
     * Normalize one scalar or array language-list value.
     *
     * @return string[]
     */
    private function languages_from_value(mixed $value): array
    {
        if ($value === false || $value === null) {
            return [];
        }

        $items = is_array($value) ? $value : [$value];
        $languages = [];
        foreach ($items as $item) {
            if (!is_scalar($item)) {
                continue;
            }

            foreach (preg_split('/[\s,|]+/', trim((string) $item)) ?: [] as $part) {
                if ($part !== '') {
                    $languages[] = WP_FTS_TermNamespace::canonicalize_lang($part);
                }
            }
        }

        return array_values(array_unique($languages));
    }

    /**
     * Resolve opt-in fallback languages, excluding exact languages.
     *
     * @param string[] $exactLangs
     * @return string[]
     */
    private function fallback_languages(array $opts, array $exactLangs): array
    {
        if ($this->truthy_option($opts['disable_language_fallback'] ?? false)) {
            return [];
        }

        $fallbacks = $this->languages_from_options($opts, ['fallback_lang', 'fallback_language', 'fallback_languages']);
        if ($fallbacks === [] && $this->truthy_option($opts['language_fallback'] ?? $opts['fallback_to_default_lang'] ?? false)) {
            $fallbacks = [WP_FTS_TermNamespace::default_language($opts)];
        }

        $exact = array_fill_keys(array_map(
            static fn(string $lang): string => WP_FTS_TermNamespace::canonicalize_lang($lang),
            $exactLangs
        ), true);

        $result = [];
        foreach ($fallbacks as $lang) {
            $lang = WP_FTS_TermNamespace::canonicalize_lang($lang);
            if (!isset($exact[$lang])) {
                $result[$lang] = true;
            }
        }

        return array_keys($result);
    }

    /**
     * Interpret public boolean-ish options without treating arbitrary strings as false.
     */
    private function truthy_option(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value) || is_float($value)) {
            return $value !== 0;
        }

        if (is_string($value)) {
            return !in_array(strtolower(trim($value)), ['', '0', 'false', 'no', 'off'], true);
        }

        return false;
    }

    /**
     * Dedupe alternatives inside groups and duplicate logical groups.
     *
     * @param array<int,array<int,array{key:string,lang:string,term:string,rank:int}>> $groups
     * @return array<int,array<int,array{key:string,lang:string,term:string,rank:int}>>
     */
    private function dedupe_query_groups(array $groups): array
    {
        $deduped = [];
        foreach ($groups as $group) {
            $byKey = [];
            foreach ($group as $candidate) {
                $key = $candidate['key'];
                if (!isset($byKey[$key]) || $candidate['rank'] < $byKey[$key]['rank']) {
                    $byKey[$key] = $candidate;
                }
            }

            if ($byKey === []) {
                continue;
            }

            ksort($byKey, SORT_STRING);
            // Every source occurrence remains a logical group. Repeated words
            // may share the same alternatives, but collapsing the groups would
            // change AND semantics and remove one occurrence's score.
            $deduped[] = array_values($byKey);
        }

        return $deduped;
    }

    /**
     * Pick a representative language for include_total response metadata.
     *
     * @param array<int,array<int,array{key:string,lang:string,term:string,rank:int}>> $groups
     * @return string Canonical language tag.
     */
    private function response_query_language(array $opts, array $groups): string
    {
        $explicitLang = WP_FTS_TermNamespace::language_from_options($opts, null, ['query_lang', 'lang', 'language']);
        if ($explicitLang !== null) {
            return $explicitLang;
        }

        foreach ($groups as $group) {
            foreach ($group as $candidate) {
                if ($candidate['rank'] === 0) {
                    return $candidate['lang'];
                }
            }
        }

        return WP_FTS_TermNamespace::default_language($opts);
    }

    /**
     * Remove exact/fallback ranking internals from public result rows.
     *
     * @param array<int,array<string,mixed>> $results
     */
    private function strip_internal_rank(array &$results): void
    {
        foreach ($results as &$result) {
            unset($result['_rank']);
        }
        unset($result);
    }

    /**
     * Call a real phrase or legacy prefix extension when requested.
     *
     * Phrase matching needs a storage-specific callback. Legacy `prefix` requests
     * may use that callback too; without one, prefix matching falls through to the
     * built-in word-beginning expansion path.
     *
     * @return array<string,mixed>|array<int,array<string,mixed>>|null
     */
    private function extension_results(string $query, array $opts, bool $hasCandidateFilter): ?array
    {
        $phraseRequested = !empty($opts['phrase']);
        $prefixRequested = !empty($opts['prefix']);
        if (!$phraseRequested && !$prefixRequested) {
            return null;
        }

        if (isset($opts['search_extension']) && is_callable($opts['search_extension'])) {
            if ($hasCandidateFilter) {
                throw new InvalidArgumentException('candidate_doc_ids_filter cannot be combined with search_extension because the extension owns candidate discovery, ranking, and pagination.');
            }
            $results = ($opts['search_extension'])($query, $opts, $this->storage, $this->analyzer);
            return is_array($results) ? $results : [];
        }

        if ($phraseRequested) {
            throw new InvalidArgumentException('Phrase search requires a search_extension callback for the active storage backend.');
        }

        return null;
    }

    /**
     * Preserve legacy list results unless callers request pagination metadata or
     * explicitly accept candidate-capped retrieval.
     *
     * @param array<int,array<string,mixed>> $results
     * @return array<int,array<string,mixed>>|array{total:int,total_is_exact:bool,retrieval_mode:string,results_may_be_incomplete:bool,candidate_cap:?int,limit:int,offset:int,query_lang:string,results:array<int,array<string,mixed>>,explain?:array<string,mixed>}
     */
    private function format_response(
        array $results,
        int $total,
        array $opts,
        string $queryLang,
        ?array $explain = null,
        ?int $candidateCap = null
    ): array
    {
        if (empty($opts['include_total']) && $candidateCap === null) {
            return $results;
        }

        $payload = [
            'total' => $total,
            'total_is_exact' => $candidateCap === null,
            'retrieval_mode' => $candidateCap === null ? 'exact' : 'candidate_capped',
            'results_may_be_incomplete' => $candidateCap !== null,
            'candidate_cap' => $candidateCap,
            'limit' => max(1, (int) ($opts['limit'] ?? 10)),
            'offset' => max(0, (int) ($opts['offset'] ?? 0)),
            'query_lang' => WP_FTS_TermNamespace::canonicalize_lang($queryLang),
            'results' => $results,
        ];

        if ($explain !== null && $this->explain_requested($opts)) {
            $payload['explain'] = $explain;
        }

        return $payload;
    }

    private function explain_requested(array $opts): bool
    {
        return $this->truthy_option($opts['explain'] ?? false)
            || $this->truthy_option($opts['debug'] ?? false);
    }

    /** Explain may decorate a legacy payload, but must not create that payload. */
    private function legacy_explain_payload_requested(array $opts, ?int $candidateCap): bool
    {
        return $this->explain_requested($opts)
            && (!empty($opts['include_total']) || $candidateCap !== null);
    }

    private function explain_result_matches_requested(array $opts): bool
    {
        if (!array_key_exists('explain_result_matches', $opts)) {
            return true;
        }

        return $this->truthy_option($opts['explain_result_matches']);
    }

    /**
     * @param array{groups:array<int,array<int,array{key:string,lang:string,term:string,rank:int,source?:string,surface?:string}>>,pre_prefix_groups:array<int,array<int,array{key:string,lang:string,term:string,rank:int,source?:string,surface?:string}>>,prefix_matching:bool,prefix_added_terms:int,prefix_min_length:int,prefix_max_terms:int} $queryPlan
     * @param array{mode:string,source:string,estimated_candidates:?int,threshold:?int,candidate_cap:?int,reason?:string} $fastMode
     * @param array<string,int> $scoreStats
     * @param array{enabled:bool,strength:float,half_life_days:float,now_gmt:string,documents_considered:int,documents_applied:int,metadata_unavailable:bool,missing_or_invalid_dates:int} $recencyStats
     * @param array<int,array<string,mixed>> $resultExplain
     * @return array<string,mixed>
     */
    private function build_explain_payload(array $queryPlan, array $fastMode, array $scoreStats, array $recencyStats, array $resultExplain, int $total, bool $exactTotal): array
    {
        return [
            'storage' => [
                'backend' => $this->bounded_explain_text(get_debug_type($this->storage)),
                'metadata' => WP_FTS_StorageCompat::supports_doc_metadata($this->storage) ? 'available' : 'unavailable',
            ],
            'query_plan' => [
                'match_mode' => $this->bounded_explain_text((string) ($queryPlan['match_mode'] ?? ''), 40),
                'logical_group_count' => count($queryPlan['groups']),
                'analyzed_languages' => $this->explain_languages($queryPlan['groups']),
                'terms' => $this->explain_query_terms($queryPlan['groups']),
                'terms_more' => $this->query_group_term_count($queryPlan['groups']) > self::EXPLAIN_MAX_TERMS,
                'prefix_matching' => !empty($queryPlan['prefix_matching']) ? 'enabled' : 'disabled',
                'prefix_added_terms' => max(0, (int) $queryPlan['prefix_added_terms']),
                'prefix_min_length' => max(0, (int) $queryPlan['prefix_min_length']),
                'prefix_max_terms' => max(1, (int) $queryPlan['prefix_max_terms']),
            ],
            'fast_mode' => [
                'mode' => $this->bounded_explain_text((string) $fastMode['mode'], 40),
                'source' => $this->bounded_explain_text((string) $fastMode['source'], 80),
                'estimated_candidates' => $fastMode['estimated_candidates'],
                'threshold' => $fastMode['threshold'],
                'candidate_cap' => $fastMode['candidate_cap'],
                'reason' => $this->bounded_explain_text(
                    (string) ($fastMode['reason'] ?? $this->fast_mode_reason(
                        (string) $fastMode['source'],
                        $fastMode['candidate_cap']
                    )),
                    self::EXPLAIN_MAX_REASON_BYTES
                ),
            ],
            'scoring' => [
                'candidate_rows_fetched' => max(0, (int) ($scoreStats['candidate_rows_fetched'] ?? 0)),
                'candidate_rows_considered' => max(0, (int) ($scoreStats['candidate_rows_considered'] ?? 0)),
                'candidate_docs_considered' => max(0, (int) ($scoreStats['candidate_docs_considered'] ?? 0)),
                'candidate_docs_scored' => max(0, (int) ($scoreStats['candidate_docs_scored'] ?? 0)),
                'scoring_terms' => max(0, (int) ($scoreStats['scoring_terms'] ?? 0)),
                'ranked_results_reused' => !empty($scoreStats['ranked_results_reused']),
                'total' => max(0, $total),
                'total_accuracy' => $exactTotal ? 'exact' : 'approximate',
            ],
            'recency_boost' => [
                'enabled' => !empty($recencyStats['enabled']),
                'strength' => max(0.0, min(self::MAX_RECENCY_BOOST_STRENGTH, (float) ($recencyStats['strength'] ?? 0.0))),
                'half_life_days' => max(
                    self::MIN_RECENCY_BOOST_HALF_LIFE_DAYS,
                    min(self::MAX_RECENCY_BOOST_HALF_LIFE_DAYS, (float) ($recencyStats['half_life_days'] ?? self::DEFAULT_RECENCY_BOOST_HALF_LIFE_DAYS))
                ),
                'now_gmt' => $this->bounded_explain_text((string) ($recencyStats['now_gmt'] ?? ''), 32),
                'documents_considered' => max(0, (int) ($recencyStats['documents_considered'] ?? 0)),
                'documents_applied' => max(0, (int) ($recencyStats['documents_applied'] ?? 0)),
                'metadata_unavailable' => !empty($recencyStats['metadata_unavailable']),
                'missing_or_invalid_dates' => max(0, (int) ($recencyStats['missing_or_invalid_dates'] ?? 0)),
            ],
            'results' => $resultExplain,
        ];
    }

    /**
     * @param array<int,array<int,array{key:string,lang:string,term:string,rank:int,source?:string,surface?:string}>> $groups
     * @return string[]
     */
    private function explain_languages(array $groups): array
    {
        $languages = [];
        foreach ($groups as $group) {
            foreach ($group as $candidate) {
                $lang = WP_FTS_TermNamespace::canonicalize_lang((string) $candidate['lang']);
                if ($lang !== '') {
                    $languages[$lang] = true;
                }
            }
        }

        return array_slice(array_keys($languages), 0, self::EXPLAIN_MAX_TERMS);
    }

    /**
     * @param array<int,array<int,array{key:string,lang:string,term:string,rank:int,source?:string,surface?:string}>> $groups
     * @return array<int,array{key:string,term:string,lang:string,rank:int,rank_class:string,surface?:string}>
     */
    private function explain_query_terms(array $groups): array
    {
        $terms = [];
        foreach ($groups as $group) {
            foreach ($group as $candidate) {
                $row = [
                    'key' => $this->bounded_explain_text((string) $candidate['key']),
                    'term' => $this->bounded_explain_text((string) $candidate['term']),
                    'lang' => WP_FTS_TermNamespace::canonicalize_lang((string) $candidate['lang']),
                    'rank' => max(0, (int) $candidate['rank']),
                    'rank_class' => $this->candidate_rank_class($candidate),
                ];
                if (isset($candidate['surface']) && is_scalar($candidate['surface']) && trim((string) $candidate['surface']) !== '') {
                    $row['surface'] = $this->bounded_explain_text((string) $candidate['surface']);
                }

                $terms[] = $row;
                if (count($terms) >= self::EXPLAIN_MAX_TERMS) {
                    return $terms;
                }
            }
        }

        return $terms;
    }

    /**
     * @param array<int,array<string,mixed>> $page
     * @param array<int,array<int,array{key:string,lang:string,term:string,rank:int,source?:string,surface?:string}>> $groups
     * @return array<int,array<string,mixed>>
     */
    private function explain_result_matches(array $page, array $groups): array
    {
        if ($page === []) {
            return [];
        }

        $candidateByKey = [];
        foreach ($groups as $group) {
            foreach ($group as $candidate) {
                $key = (string) $candidate['key'];
                if (!isset($candidateByKey[$key]) || (int) $candidate['rank'] < (int) $candidateByKey[$key]['rank']) {
                    $candidateByKey[$key] = $candidate;
                }
            }
        }
        if ($candidateByKey === []) {
            return [];
        }

        $pageDocIds = [];
        foreach ($page as $row) {
            $docId = max(0, (int) ($row['doc_id'] ?? 0));
            if ($docId > 0) {
                $pageDocIds[$docId] = true;
            }
        }
        $this->guard_request_budget();
        $metadataByDoc = WP_FTS_StorageCompat::get_doc_metadata($this->storage, array_keys($pageDocIds));
        $this->guard_request_budget();

        $rows = [];
        foreach ($page as $row) {
            $docId = max(0, (int) ($row['doc_id'] ?? 0));
            if ($docId <= 0) {
                continue;
            }

            $matches = [];
            $matchCount = 0;
            $languages = [];
            $this->guard_request_budget();
            $documentTerms = WP_FTS_StorageCompat::terms_for_doc($this->storage, $docId, 0);
            $this->guard_request_budget();
            foreach ($documentTerms as $termKey) {
                if (!isset($candidateByKey[$termKey])) {
                    continue;
                }

                $candidate = $candidateByKey[$termKey];
                $matchCount++;
                $lang = WP_FTS_TermNamespace::canonicalize_lang((string) $candidate['lang']);
                if ($lang !== '') {
                    $languages[$lang] = true;
                }
                if (count($matches) < self::EXPLAIN_MAX_MATCHES_PER_RESULT) {
                    $match = [
                        'key' => $this->bounded_explain_text($termKey),
                        'term' => $this->bounded_explain_text((string) $candidate['term']),
                        'lang' => $lang,
                        'rank_class' => $this->candidate_rank_class($candidate),
                    ];
                    if (isset($candidate['surface']) && is_scalar($candidate['surface']) && trim((string) $candidate['surface']) !== '') {
                        $match['surface'] = $this->bounded_explain_text((string) $candidate['surface']);
                    }
                    $matches[] = $match;
                }
            }

            $this->guard_request_budget();
            $document = $this->storage->get_doc($docId);
            $this->guard_request_budget();
            $fieldMatches = $this->explain_field_matches(
                is_numeric($row['score'] ?? null) ? (float) $row['score'] : 0.0,
                $candidateByKey,
                $metadataByDoc[$docId] ?? [],
                $document
            );

            $rows[] = [
                'doc_id' => $docId,
                'matches' => $matches,
                'matched_languages' => array_slice(array_keys($languages), 0, self::EXPLAIN_MAX_MATCHES_PER_RESULT),
                'matches_more' => $matchCount > count($matches),
                'field_matches' => $fieldMatches['matches'],
                'field_matches_more' => $fieldMatches['more'],
            ];
            if (count($rows) >= self::EXPLAIN_MAX_RESULT_ROWS) {
                break;
            }
        }

        return $rows;
    }

    /**
     * Build bounded field-level match diagnostics for one explained result.
     *
     * @param array<string,array{key:string,lang:string,term:string,rank:int,source?:string,surface?:string}> $candidateByKey
     * @param array<string,mixed> $metadata
     * @param array<string,mixed>|null $doc
     * @return array{matches:array<int,array<string,mixed>>,more:bool}
     */
    private function explain_field_matches(float $resultScore, array $candidateByKey, array $metadata, ?array $doc): array
    {
        $fields = $this->explain_field_sources($metadata);
        if ($fields === []) {
            return ['matches' => [], 'more' => false];
        }

        $documentLang = $this->explain_document_language($doc, $metadata);
        $fieldRows = [];
        $totalWeightedMatches = 0.0;

        foreach ($fields as $field) {
            $fieldName = $this->bounded_explain_text((string) ($field['name'] ?? ''), 80);
            $fieldWeight = is_numeric($field['boost'] ?? null)
                ? max(0.01, min(100.0, (float) $field['boost']))
                : 1.0;
            if ($fieldName === '') {
                continue;
            }

            $terms = [];
            $matchCount = 0;
            $weightedMatchCount = 0.0;
            foreach ($this->analyze_explain_field($field, $documentLang) as $occurrence) {
                $termKey = $this->explain_occurrence_key($occurrence, $documentLang);
                if ($termKey === null || !isset($candidateByKey[$termKey])) {
                    continue;
                }

                $candidate = $candidateByKey[$termKey];
                $occurrenceWeight = is_array($occurrence) && isset($occurrence['weight']) && is_numeric($occurrence['weight'])
                    ? max(0.0, (float) $occurrence['weight'])
                    : 1.0;
                $weightedHit = $occurrenceWeight * $fieldWeight;
                if ($weightedHit <= 0.0) {
                    continue;
                }

                $matchCount++;
                $weightedMatchCount += $weightedHit;
                if (!isset($terms[$termKey])) {
                    $term = [
                        'key' => $this->bounded_explain_text($termKey),
                        'term' => $this->bounded_explain_text((string) $candidate['term']),
                        'lang' => WP_FTS_TermNamespace::canonicalize_lang((string) $candidate['lang']),
                        'rank_class' => $this->candidate_rank_class($candidate),
                        'hit_count' => 0,
                        'weighted_hit_count' => 0.0,
                    ];
                    if (isset($candidate['surface']) && is_scalar($candidate['surface']) && trim((string) $candidate['surface']) !== '') {
                        $term['surface'] = $this->bounded_explain_text((string) $candidate['surface']);
                    }
                    $terms[$termKey] = $term;
                }
                $terms[$termKey]['hit_count']++;
                $terms[$termKey]['weighted_hit_count'] = round((float) $terms[$termKey]['weighted_hit_count'] + $weightedHit, 6);
            }

            if ($matchCount === 0 || $weightedMatchCount <= 0.0) {
                continue;
            }

            $termRows = array_values($terms);
            usort($termRows, static function (array $a, array $b): int {
                $weighted = ((float) ($b['weighted_hit_count'] ?? 0.0)) <=> ((float) ($a['weighted_hit_count'] ?? 0.0));
                if ($weighted !== 0) {
                    return $weighted;
                }

                return strcmp((string) ($a['key'] ?? ''), (string) ($b['key'] ?? ''));
            });

            $fieldRows[] = [
                'field' => $fieldName,
                'weight' => round($fieldWeight, 6),
                'match_count' => $matchCount,
                'weighted_match_count' => round($weightedMatchCount, 6),
                'terms' => array_slice($termRows, 0, self::EXPLAIN_MAX_TERMS_PER_FIELD),
                'terms_more' => count($termRows) > self::EXPLAIN_MAX_TERMS_PER_FIELD,
                'score_subtotal' => 0.0,
                'score_subtotal_approximate' => true,
            ];
            $totalWeightedMatches += $weightedMatchCount;
        }

        if ($fieldRows === []) {
            return ['matches' => [], 'more' => false];
        }

        foreach ($fieldRows as &$fieldRow) {
            $share = $totalWeightedMatches > 0.0
                ? (float) ($fieldRow['weighted_match_count'] ?? 0.0) / $totalWeightedMatches
                : 0.0;
            $fieldRow['score_subtotal'] = round(max(0.0, $resultScore) * $share, 12);
        }
        unset($fieldRow);

        usort($fieldRows, static function (array $a, array $b): int {
            $score = ((float) ($b['score_subtotal'] ?? 0.0)) <=> ((float) ($a['score_subtotal'] ?? 0.0));
            if ($score !== 0) {
                return $score;
            }
            $weighted = ((float) ($b['weighted_match_count'] ?? 0.0)) <=> ((float) ($a['weighted_match_count'] ?? 0.0));
            if ($weighted !== 0) {
                return $weighted;
            }

            return strcmp((string) ($a['field'] ?? ''), (string) ($b['field'] ?? ''));
        });

        return [
            'matches' => array_slice($fieldRows, 0, self::EXPLAIN_MAX_FIELD_MATCHES_PER_RESULT),
            'more' => count($fieldRows) > self::EXPLAIN_MAX_FIELD_MATCHES_PER_RESULT,
        ];
    }

    /**
     * @param array<string,mixed> $metadata
     * @return array<int,array{name:string,text:string,boost:float,html?:string}>
     */
    private function explain_field_sources(array $metadata): array
    {
        if (isset($metadata['search_fields']) && is_array($metadata['search_fields']) && $metadata['search_fields'] !== []) {
            return $metadata['search_fields'];
        }

        $boosts = is_array($metadata['field_boosts'] ?? null) ? $metadata['field_boosts'] : [];
        $fields = [];
        foreach (['title', 'excerpt'] as $name) {
            $text = is_scalar($metadata[$name] ?? null) ? trim((string) $metadata[$name]) : '';
            if ($text !== '') {
                $fields[] = [
                    'name' => $name,
                    'text' => $text,
                    'boost' => is_numeric($boosts[$name] ?? null) ? (float) $boosts[$name] : 1.0,
                ];
            }
        }

        $termsText = $this->explain_metadata_list_text($metadata['terms'] ?? []);
        if ($termsText !== '') {
            $fields[] = [
                'name' => 'terms',
                'text' => $termsText,
                'boost' => is_numeric($boosts['terms'] ?? null) ? (float) $boosts['terms'] : 1.0,
            ];
        }

        $customFieldsText = $this->explain_metadata_list_text($metadata['custom_fields'] ?? []);
        if ($customFieldsText !== '') {
            $fields[] = [
                'name' => 'custom_fields',
                'text' => $customFieldsText,
                'boost' => is_numeric($boosts['custom_fields'] ?? null) ? (float) $boosts['custom_fields'] : 1.0,
            ];
        }

        $searchText = is_scalar($metadata['search_text'] ?? null) ? trim((string) $metadata['search_text']) : '';
        $searchHtml = is_scalar($metadata['search_html'] ?? null) ? trim((string) $metadata['search_html']) : '';
        if ($searchText !== '' || $searchHtml !== '') {
            $field = [
                'name' => 'content',
                'text' => $searchText !== '' ? $searchText : WP_FTS_Html_Text_Stream::visible_text($searchHtml),
                'boost' => is_numeric($boosts['content'] ?? null) ? (float) $boosts['content'] : 1.0,
            ];
            if ($searchHtml !== '') {
                $field['html'] = $searchHtml;
            }
            $fields[] = $field;
        }

        return $fields;
    }

    private function explain_metadata_list_text(mixed $value): string
    {
        if (!is_array($value)) {
            return '';
        }

        $items = [];
        foreach ($value as $list) {
            foreach (is_array($list) ? $list : [$list] as $item) {
                if (is_scalar($item) && trim((string) $item) !== '') {
                    $items[] = (string) $item;
                }
            }
        }

        return trim(implode(' ', $items));
    }

    /**
     * @param array<string,mixed>|null $doc
     * @param array<string,mixed> $metadata
     */
    private function explain_document_language(?array $doc, array $metadata): string
    {
        foreach ([
            $this->single_document_length_language($doc),
            $doc['primary_lang'] ?? null,
            $metadata['primary_lang'] ?? null,
            $metadata['language'] ?? null,
            $metadata['lang'] ?? null,
        ] as $candidate) {
            if (is_scalar($candidate) && trim((string) $candidate) !== '') {
                return WP_FTS_TermNamespace::canonicalize_lang((string) $candidate);
            }
        }

        return WP_FTS_TermNamespace::default_language([]);
    }

    /**
     * @param array<string,mixed> $field
     * @return array<int,array<string,mixed>|string>
     */
    private function analyze_explain_field(array $field, string $documentLang): array
    {
        $opts = [
            'lang' => $documentLang,
            'language' => $documentLang,
            'document_lang' => $documentLang,
            'default_lang' => $documentLang,
        ];

        try {
            if (isset($field['html']) && is_scalar($field['html']) && trim((string) $field['html']) !== '' && is_callable([$this->analyzer, 'analyze_content'])) {
                return $this->analyzer->analyze_content((string) $field['html'], $opts);
            }

            if (!is_callable([$this->analyzer, 'analyze_plain_content'])) {
                return [];
            }

            return $this->analyzer->analyze_plain_content((string) ($field['text'] ?? ''), $opts);
        } catch (Throwable) {
            return [];
        }
    }

    private function explain_occurrence_key(array|string $occurrence, string $defaultLang): ?string
    {
        $term = is_array($occurrence)
            ? trim((string) ($occurrence['term'] ?? ''))
            : trim((string) $occurrence);
        if ($term === '') {
            return null;
        }

        $split = WP_FTS_TermNamespace::split_term($term);
        $lang = is_array($occurrence) && isset($occurrence['lang'])
            ? WP_FTS_TermNamespace::canonicalize_lang((string) $occurrence['lang'], $defaultLang)
            : WP_FTS_TermNamespace::canonicalize_lang($defaultLang);
        if ($split !== null) {
            $lang = $split['lang'];
            $term = $split['term'];
        }

        if ($term === '' || !WP_FTS_TermNamespace::term_key_fits($term, $lang)) {
            return null;
        }

        return WP_FTS_TermNamespace::namespace_term($lang, $term);
    }

    /**
     * @param array{rank:int,source?:string} $candidate
     */
    private function candidate_rank_class(array $candidate): string
    {
        $source = isset($candidate['source']) ? (string) $candidate['source'] : '';
        if (in_array($source, ['exact', 'secondary_lemma', 'fallback_language', 'prefix_expansion'], true)) {
            return $source;
        }

        $rank = max(0, (int) $candidate['rank']);
        if ($rank === 0) {
            return 'exact';
        }
        if ($rank === 1) {
            return 'fallback_language';
        }

        return 'secondary_lemma';
    }

    private function bounded_explain_text(string $value, int $maxBytes = self::EXPLAIN_MAX_TEXT_BYTES): string
    {
        $value = trim(str_replace(["\r", "\n", "\t"], ' ', WP_FTS_Utf8::repair($value)));
        $value = preg_replace('/\s+/', ' ', $value) ?? $value;
        if ($maxBytes <= 0 || strlen($value) <= $maxBytes) {
            return $value;
        }

        return rtrim(WP_FTS_Utf8::truncate_bytes($value, max(0, $maxBytes - 3))) . '...';
    }

    /**
     * Check whether result rows need metadata/snippet enrichment.
     */
    private function should_enrich_results(array $opts): bool
    {
        return !empty($opts['include_metadata']) || !empty($opts['include_snippets']) || !empty($opts['snippets']);
    }

    /**
     * Add stored metadata and optional snippets to result rows.
     *
     * @param array<int,array<string,mixed>> $results
     * @param array<int,array<string,mixed>> $metadata
     * @param array<int,array<int,array{key:string,lang:string,term:string,rank:int}>> $queryGroups
     * @return array<int,array<string,mixed>>
     */
    private function enrich_results(array $results, array $metadata, string $query, array $opts, array $queryGroups, string $queryLang): array
    {
        $includeMetadata = !empty($opts['include_metadata']);
        $includeSnippets = !empty($opts['include_snippets']) || !empty($opts['snippets']);
        foreach ($results as &$row) {
            $docId = (int) $row['doc_id'];
            $meta = $metadata[$docId] ?? [];
            $doc = $includeSnippets ? $this->storage->get_doc($docId) : null;
            if ($includeMetadata) {
                foreach (['post_id', 'post_type', 'post_status', 'post_date_gmt', 'title', 'excerpt'] as $key) {
                    $row[$key] = $meta[$key] ?? ($key === 'post_id' ? 0 : '');
                }
            }
            if ($includeSnippets) {
                $resultLang = $this->snippet_result_language($row, $meta, $doc, $opts, $queryLang);
                $snippetQueryGroups = $this->snippet_query_groups($query, $opts, $queryGroups, $queryLang, $resultLang, $doc);
                $snippetLength = max(40, (int) ($opts['snippet_length'] ?? 180));
                $highlight = !empty($opts['highlight']);
                $searchHtml = (string) ($meta['search_html'] ?? '');
                if ($highlight && $searchHtml !== '') {
                    $sidecarSnippet = $this->snippet(
                        $searchHtml,
                        $query,
                        $snippetLength,
                        true,
                        $opts,
                        $snippetQueryGroups,
                        $queryLang,
                        $resultLang
                    );
                    if (str_contains($sidecarSnippet, '<mark>')) {
                        $row['snippet'] = $sidecarSnippet;
                        continue;
                    }
                }

                $snippetSource = (string) ($meta['search_text'] ?? $meta['excerpt'] ?? $meta['title'] ?? '');
                if ($snippetSource === '') {
                    $snippetSource = $searchHtml;
                }

                $row['snippet'] = $this->snippet(
                    $snippetSource,
                    $query,
                    $snippetLength,
                    $highlight,
                    $opts,
                    $snippetQueryGroups,
                    $queryLang,
                    $resultLang
                );
            }
        }
        unset($row);

        return $results;
    }

    /**
     * Build a snippet-only query plan when callers need broad search recall but
     * bounded highlighting. Without an explicit `snippet_languages` key, result
     * enrichment keeps the search query plan for backwards compatibility.
     *
     * @param array<int,array<int,array{key:string,lang:string,term:string,rank:int}>> $queryGroups
     * @return array<int,array<int,array{key:string,lang:string,term:string,rank:int}>>
     */
    private function snippet_query_groups(string $query, array $opts, array $queryGroups, string $queryLang, string $resultLang, ?array $doc): array
    {
        if (!array_key_exists('snippet_languages', $opts)) {
            return $queryGroups;
        }

        $languages = [];
        foreach ($this->languages_from_value($opts['snippet_languages']) as $language) {
            $languages[$language] = true;
        }
        foreach ([$queryLang, $resultLang] as $language) {
            if (is_scalar($language) && trim((string) $language) !== '') {
                $languages[WP_FTS_TermNamespace::canonicalize_lang((string) $language)] = true;
            }
        }
        foreach ($this->document_length_languages($doc) as $language) {
            $languages[$language] = true;
        }

        $snippetOpts = $opts;
        unset($snippetOpts['langs'], $snippetOpts['languages']);
        if ($languages !== []) {
            $snippetOpts['languages'] = array_keys($languages);
        }

        return $this->build_query_groups($query, $snippetOpts);
    }

    /**
     * Determine whether metadata filters are present.
     */
    private function has_metadata_filters(array $opts): bool
    {
        $filter = $this->metadata_filter_values($opts);

        return $filter['post_types'] !== []
            || $filter['post_statuses'] !== []
            || $filter['date_after'] !== null
            || $filter['date_before'] !== null;
    }

    /**
     * Normalize public metadata filter options once per search path.
     *
     * @return array{post_types:string[],post_statuses:string[],date_after:?string,date_before:?string}
     */
    private function metadata_filter_values(array $opts): array
    {
        return [
            'post_types' => $this->normalize_filter_list($opts['post_type'] ?? $opts['post_types'] ?? []),
            'post_statuses' => $this->normalize_filter_list($opts['post_status'] ?? $opts['post_statuses'] ?? []),
            'date_after' => $this->date_filter($opts['date_after'] ?? $opts['after'] ?? $opts['post_date_after'] ?? null, false),
            'date_before' => $this->date_filter($opts['date_before'] ?? $opts['before'] ?? $opts['post_date_before'] ?? null, true),
        ];
    }

    /**
     * Normalize comma-separated or array filters.
     *
     * @return string[]
     */
    private function normalize_filter_list(mixed $value): array
    {
        return WP_FTS_StorageCompat::normalize_metadata_filter_values($value);
    }

    /**
     * Normalize date-only filters to lexicographic SQL datetime boundaries.
     */
    private function date_filter(mixed $value, bool $endOfDay): ?string
    {
        return WP_FTS_StorageCompat::normalize_metadata_filter_date($value, $endOfDay);
    }

    /**
     * Build a highlighted snippet from a caller-supplied source using the same
     * analyzer/query plan as normal search result enrichment.
     *
     * This is intended for WordPress render surfaces that need a field-specific
     * preview, for example highlighting only the actual post content or only the
     * title instead of the aggregate indexed metadata.
     *
     * The return value is safe HTML made only from escaped visible text and
     * internally generated `<mark>` elements. Caller-supplied tags and
     * attributes are never preserved.
     *
     * @param array<string,mixed> $opts
     */
    public function snippet_for_text(string $text, string $query, array $opts = []): string
    {
        $this->assert_public_option_map_bounds($opts);
        $this->assert_set_oriented_query_input($query, $opts);
        $text = $this->bounded_set_oriented_snippet_source($text);
        $query = trim($query);
        if ($query === '' || trim($text) === '') {
            return '';
        }

        $explicitQueryLang = WP_FTS_TermNamespace::language_from_options($opts, null, ['query_lang', 'lang', 'language']);
        try {
            $queryOccurrences = $this->analyze_query_once($query, $opts);
        } catch (WP_FTS_Analysis_Limit_Exceeded $error) {
            throw new WP_FTS_Search_Budget_Exceeded(
                $error->reason_code === 'occurrences' ? 'analyzer occurrences' : 'query bytes'
            );
        }
        $queryLang = $explicitQueryLang ?? $this->resolve_query_language($opts, $queryOccurrences);
        $groups = $this->dedupe_query_groups($this->groups_from_occurrences(
            $queryOccurrences,
            $queryLang,
            0,
            $explicitQueryLang
        ));
        $this->assert_set_oriented_query_groups($groups);
        $authoritativePrefixes = [];
        if ($groups !== []) {
            $prefixSurface = $this->set_oriented_prefix_surface(
                $queryOccurrences,
                $groups[array_key_last($groups)],
                $queryLang,
                $explicitQueryLang
            );
            if (
                $prefixSurface !== null
                && WP_FTS_TermNamespace::term_key_fits($prefixSurface['term'], $prefixSurface['lang'])
            ) {
                $authoritativePrefixes[] = $prefixSurface;
            }
        }
        $queryLang = $this->response_query_language($opts, $groups);
        $resultLang = WP_FTS_TermNamespace::language_from_options($opts, null, ['result_lang', 'document_lang', 'lang', 'language'])
            ?? $queryLang;

        return $this->snippet(
            $text,
            $query,
            max(40, min(self::MAX_SET_ORIENTED_SNIPPET_LENGTH, (int) ($opts['snippet_length'] ?? 180))),
            !empty($opts['highlight']),
            $opts,
            $groups,
            $queryLang,
            $resultLang,
            $authoritativePrefixes
        );
    }

    /**
     * Build a compact snippet from stored plain text.
     */
    private function snippet(
        string $text,
        string $query,
        int $length,
        bool $highlight,
        array $opts = [],
        array $queryGroups = [],
        string $queryLang = '',
        string $resultLang = '',
        ?array $authoritativePrefixes = null
    ): string
    {
        $text = WP_FTS_Html_Text_Stream::visible_text($text);
        if ($text === '') {
            return '';
        }

        $terms = $this->snippet_terms($query);
        $queryKeys = $highlight ? $this->snippet_query_keys($queryGroups) : [];
        $analyzedSurfaces = [];
        $start = 0;
        $literalPositionFound = false;
        foreach ($terms as $term) {
            $position = stripos($text, $term);
            if ($position !== false) {
                $characterPosition = WP_FTS_Utf8::length(substr($text, 0, $position));
                $start = max(0, $characterPosition - intdiv($length, 3));
                $literalPositionFound = true;
                break;
            }
        }
        if (!$literalPositionFound && $highlight && $queryKeys !== []) {
            $analyzedSource = WP_FTS_Utf8::truncate_bytes($text, self::MAX_SNIPPET_ANALYSIS_SOURCE_BYTES);
            $analyzedSurfaces = $this->snippet_matching_surfaces(
                $analyzedSource,
                $queryKeys,
                $opts,
                $queryGroups,
                $queryLang,
                $resultLang,
                $authoritativePrefixes
            );
            $position = $this->first_snippet_surface_position($analyzedSource, $analyzedSurfaces);
            if ($position !== null) {
                $characterPosition = WP_FTS_Utf8::length(substr($text, 0, $position));
                $start = max(0, $characterPosition - intdiv($length, 3));
            }
        }

        $textLength = WP_FTS_Utf8::length($text);
        $snippet = trim(WP_FTS_Utf8::slice($text, $start, $length));
        if ($start > 0) {
            $snippet = '...' . ltrim($snippet);
        }
        if ($start + $length < $textLength) {
            $snippet = rtrim($snippet) . '...';
        }

        if (!$highlight) {
            return $this->escape_snippet_text($snippet);
        }

        if ($queryKeys !== [] && ($start > 0 || $analyzedSurfaces === [])) {
            $analyzedSurfaces = $this->snippet_matching_surfaces(
                $snippet,
                $queryKeys,
                $opts,
                $queryGroups,
                $queryLang,
                $resultLang,
                $authoritativePrefixes
            );
        }

        return $this->highlight_snippet_terms(
            $snippet,
            array_fill_keys($terms, true),
            $analyzedSurfaces
        );
    }

    /**
     * Escape caller-controlled snippet text for an HTML rendering surface.
     */
    private function escape_snippet_text(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
    }

    /**
     * Resolve the best language hint for analyzing plain snippet tokens.
     *
     * @param array<string,mixed> $row
     * @param array<string,mixed> $meta
     * @param array<string,mixed>|null $doc
     */
    private function snippet_result_language(array $row, array $meta, ?array $doc, array $opts, string $queryLang): string
    {
        foreach ([
            $row['language'] ?? null,
            $row['lang'] ?? null,
            $row['primary_lang'] ?? null,
            $meta['language'] ?? null,
            $meta['lang'] ?? null,
            $meta['primary_lang'] ?? null,
            $this->single_document_length_language($doc),
            $doc['primary_lang'] ?? null,
            $doc['lang'] ?? null,
            $doc['language'] ?? null,
            WP_FTS_TermNamespace::language_from_options($opts, null, ['query_lang', 'lang', 'language']),
            $queryLang,
        ] as $candidate) {
            if (is_scalar($candidate) && trim((string) $candidate) !== '') {
                return WP_FTS_TermNamespace::canonicalize_lang((string) $candidate);
            }
        }

        return WP_FTS_TermNamespace::default_language($opts);
    }

    /**
     * Return the only concrete language partition represented by a stored doc.
     *
     * @param array<string,mixed>|null $doc
     */
    private function single_document_length_language(?array $doc): string
    {
        $languages = $this->document_length_languages($doc);

        return count($languages) === 1 ? $languages[0] : '';
    }

    /**
     * Return concrete language partitions represented by a stored doc.
     *
     * @param array<string,mixed>|null $doc
     * @return string[]
     */
    private function document_length_languages(?array $doc): array
    {
        if ($doc === null || !is_array($doc['lang_lengths'] ?? null)) {
            return [];
        }

        $languages = [];
        foreach ($doc['lang_lengths'] as $language => $length) {
            if (!is_numeric($length) || (int) $length <= 0) {
                continue;
            }
            $language = WP_FTS_TermNamespace::canonicalize_lang((string) $language);
            if ($language !== '') {
                $languages[$language] = true;
            }
        }

        return array_keys($languages);
    }

    /**
     * Flatten analyzed query alternatives into the stored term-key lookup.
     *
     * @param array<int,array<int,array{key:string,lang:string,term:string,rank:int}>> $queryGroups
     * @return array<string,bool>
     */
    private function snippet_query_keys(array $queryGroups): array
    {
        $keys = [];
        foreach ($queryGroups as $group) {
            foreach ($group as $candidate) {
                if (($candidate['key'] ?? '') !== '') {
                    $keys[$candidate['key']] = true;
                }
            }
        }

        return $keys;
    }

    /**
     * Escape snippet text and wrap matching surface tokens with generated marks.
     *
     * @param array<string,bool> $literalTerms
     * @param array<string,bool> $analyzedSurfaces
     */
    private function highlight_snippet_terms(string $snippet, array $literalTerms, array $analyzedSurfaces): string
    {
        $matched = preg_match_all(self::SNIPPET_TOKEN_PATTERN, $snippet, $matches, PREG_OFFSET_CAPTURE);
        if ($matched === false || $matched === 0) {
            return $this->escape_snippet_text($snippet);
        }

        $highlighted = '';
        $cursor = 0;
        foreach ($matches[0] as $match) {
            $token = (string) $match[0];
            $offset = (int) $match[1];
            $length = strlen($token);
            $matchesLiteral = isset($literalTerms[strtolower($token)]);
            $matchesAnalyzer = $this->snippet_token_has_analyzed_surface($token, $analyzedSurfaces);

            $highlighted .= $this->escape_snippet_text(substr($snippet, $cursor, $offset - $cursor));
            $escapedToken = $this->escape_snippet_text($token);
            $highlighted .= ($matchesLiteral || $matchesAnalyzer) ? '<mark>' . $escapedToken . '</mark>' : $escapedToken;
            $cursor = $offset + $length;
        }

        return $highlighted . $this->escape_snippet_text(substr($snippet, $cursor));
    }

    /**
     * Return the prefix surfaces that may have contributed to membership.
     *
     * A non-null authoritative list comes from the set-oriented query plan and
     * must not be widened with lemma/stem alternatives for presentation. Null
     * retains the legacy fixture behavior, whose membership path still expands
     * final-group candidates itself.
     *
     * @param array<int,array{lang:string,term:string}>|null $authoritativePrefixes
     * @return array<int,array{lang:string,term:string}>
     */
    private function snippet_query_prefixes(
        array $queryGroups,
        array $opts,
        ?array $authoritativePrefixes = null
    ): array
    {
        if (!$this->prefix_matching_enabled($opts)) {
            return [];
        }

        $prefixes = [];
        $minimum = $this->prefix_min_length($opts);
        if ($authoritativePrefixes !== null) {
            foreach ($authoritativePrefixes as $prefix) {
                if (!is_array($prefix)) {
                    continue;
                }
                $term = is_scalar($prefix['term'] ?? null) ? (string) $prefix['term'] : '';
                $lang = is_scalar($prefix['lang'] ?? null) ? (string) $prefix['lang'] : '';
                if ($term === '' || $lang === '' || WP_FTS_Utf8::length($term) < $minimum) {
                    continue;
                }
                $lang = WP_FTS_TermNamespace::canonicalize_lang($lang);
                $prefixes[$lang . "\0" . $term] = ['lang' => $lang, 'term' => $term];
            }

            return array_values($prefixes);
        }

        if ($queryGroups === []) {
            return [];
        }
        foreach ($queryGroups[count($queryGroups) - 1] ?? [] as $candidate) {
            $term = (string) ($candidate['term'] ?? '');
            $lang = (string) ($candidate['lang'] ?? '');
            if ($term !== '' && $lang !== '' && WP_FTS_Utf8::length($term) >= $minimum) {
                $prefixes[$lang . "\0" . $term] = [
                    'lang' => WP_FTS_TermNamespace::canonicalize_lang($lang),
                    'term' => $term,
                ];
            }
        }

        return array_values($prefixes);
    }

    /**
     * Return explicit/result languages only; do not guess every supported language.
     *
     * @param array<int,array<int,array{key:string,lang:string,term:string,rank:int}>> $queryGroups
     * @return string[]
     */
    private function snippet_analysis_languages(array $opts, array $queryGroups, string $queryLang, string $resultLang): array
    {
        $languages = [];
        foreach ([$resultLang, WP_FTS_TermNamespace::language_from_options($opts, null, ['query_lang', 'lang', 'language']), $queryLang] as $candidate) {
            if (is_scalar($candidate) && trim((string) $candidate) !== '') {
                $languages[WP_FTS_TermNamespace::canonicalize_lang((string) $candidate)] = true;
            }
        }

        foreach ($this->languages_from_options($opts, ['langs', 'languages']) as $lang) {
            $languages[$lang] = true;
        }

        foreach ($queryGroups as $group) {
            foreach ($group as $candidate) {
                if (($candidate['lang'] ?? '') !== '') {
                    $languages[WP_FTS_TermNamespace::canonicalize_lang($candidate['lang'])] = true;
                }
            }
        }

        return array_slice(array_keys($languages), 0, self::MAX_SNIPPET_ANALYSIS_LANGUAGES);
    }

    /**
     * Analyze one bounded presentation window per relevant language.
     *
     * The old implementation invoked the analyzer once for every distinct token.
     * A 20-row page containing adversarial 20-KiB snippets could therefore make
     * hundreds of thousands of analyzer calls. One bounded pass per language
     * retains morphology-aware highlighting without token-count fanout.
     *
     * @param array<string,bool> $queryKeys
     * @param array<int,array<int,array{key:string,lang:string,term:string,rank:int}>> $queryGroups
     * @return array<string,bool> Canonical analyzer surfaces that matched the query.
     */
    private function snippet_matching_surfaces(
        string $text,
        array $queryKeys,
        array $opts,
        array $queryGroups,
        string $queryLang,
        string $resultLang,
        ?array $authoritativePrefixes = null
    ): array
    {
        if ($text === '' || $queryKeys === []) {
            return [];
        }

        $prefixes = $this->snippet_query_prefixes($queryGroups, $opts, $authoritativePrefixes);
        $surfaces = [];
        foreach ($this->snippet_analysis_languages($opts, $queryGroups, $queryLang, $resultLang) as $lang) {
            $analysisOpts = $this->with_query_language($opts, $lang);
            $analysisOpts['_include_query_surface'] = true;
            $analysisOpts['_max_query_occurrences'] = self::MAX_SNIPPET_ANALYSIS_OCCURRENCES;
            try {
                $occurrences = $this->analyze_query($text, $analysisOpts);
                foreach ($occurrences as $occurrence) {
                    if (!is_array($occurrence) || !is_scalar($occurrence['surface'] ?? null)) {
                        continue;
                    }
                    $candidate = $this->candidate_from_occurrence($occurrence, $lang, 0, $lang);
                    if ($candidate === null) {
                        continue;
                    }
                    $matches = isset($queryKeys[$candidate['key']]);
                    if (!$matches) {
                        $prefixTerm = $authoritativePrefixes === null
                            ? $candidate['term']
                            : $this->normalized_occurrence_surface($occurrence, $candidate);
                        foreach ($prefixes as $prefix) {
                            if (
                                $prefixTerm !== ''
                                && $candidate['lang'] === $prefix['lang']
                                && str_starts_with($prefixTerm, $prefix['term'])
                            ) {
                                $matches = true;
                                break;
                            }
                        }
                    }
                    if ($matches) {
                        $surface = trim((string) $occurrence['surface']);
                        if ($surface !== '') {
                            $surfaces[$this->normalize_snippet_surface($surface)] = true;
                        }
                    }
                }
            } catch (WP_FTS_Analysis_Limit_Exceeded|WP_FTS_Search_Budget_Exceeded) {
                // Highlighting is presentation-only. A custom tokenizer that
                // exceeds the fixed window budget must not fail the search.
                continue;
            }
        }

        return $surfaces;
    }

    /** @param array<string,bool> $surfaces */
    private function first_snippet_surface_position(string $text, array $surfaces): ?int
    {
        // Analyzer surfaces have passed through whole-text NFKC, while the
        // rendered snippet must retain the source spelling. Scan raw tokens so
        // canonically equivalent forms such as decomposed cafe + combining
        // acute can still supply a source byte offset without trying to map
        // offsets back from a normalized copy of the whole document.
        $matched = preg_match_all(self::SNIPPET_TOKEN_PATTERN, $text, $tokens, PREG_OFFSET_CAPTURE);
        if ($matched === false || $matched === 0) {
            return null;
        }
        foreach ($tokens[0] as $token) {
            if ($this->snippet_token_has_analyzed_surface((string) $token[0], $surfaces)) {
                return (int) $token[1];
            }
        }

        return null;
    }

    /** @param array<string,bool> $surfaces */
    private function snippet_token_has_analyzed_surface(string $token, array $surfaces): bool
    {
        $normalizedToken = $this->normalize_snippet_surface($token);
        if (isset($surfaces[$normalizedToken])) {
            return true;
        }

        // The built-in CJK tokenizer emits overlapping n-grams while the
        // presentation tokenizer keeps one script run intact. Probe the same
        // fixed four-code-point window against the canonical surface set; cost
        // stays linear in the bounded snippet instead of multiplying every raw
        // token by every analyzed surface.
        if (preg_match('/[\p{Han}\p{Hiragana}\p{Katakana}\p{Hangul}]/u', $normalizedToken) === 1) {
            $window = [];
            $tokenLength = WP_FTS_Utf8::length($normalizedToken);
            for ($index = 0; $index < $tokenLength; $index++) {
                $window[] = WP_FTS_Utf8::slice($normalizedToken, $index, 1);
                if (count($window) > 4) {
                    array_shift($window);
                }
                $windowCount = count($window);
                for ($length = 1; $length <= $windowCount; $length++) {
                    if (isset($surfaces[implode('', array_slice($window, $windowCount - $length))])) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    /** Normalize canonical Unicode equivalents without changing source output. */
    private function normalize_snippet_surface(string $surface): string
    {
        static $normalizer = null;
        $normalizer ??= new WP_FTS_Normalizer(['fold_diacritics' => false]);

        return $normalizer->normalize_unicode($surface);
    }

    /**
     * Extract raw query words for snippet positioning/highlighting.
     *
     * @return string[]
     */
    private function snippet_terms(string $query): array
    {
        preg_match_all(self::SNIPPET_TOKEN_PATTERN, $query, $matches);
        $terms = [];
        foreach ($matches[0] ?? [] as $term) {
            $term = trim((string) $term);
            if (strlen($term) >= 2) {
                $terms[strtolower($term)] = true;
            }
        }

        return array_keys($terms);
    }

    /**
     * Analyze a query while supporting both current and legacy analyzer APIs.
     *
     * The preferred API is `analyze_query_occurrences()`. For older analyzers,
     * this method tries `analyze_query()` with both `return` and `format`
     * occurrence hints before falling back to whatever array that method returns.
     *
     * @return array<int,array<string,mixed>|string>
     */
    private function analyze_query(string $query, array $opts): array
    {
        $analysisOpts = $this->query_analysis_options($opts);
        $analysisOpts['return'] = 'occurrences';
        $maxOccurrences = isset($analysisOpts['_max_query_occurrences']) && is_int($analysisOpts['_max_query_occurrences'])
            ? max(1, $analysisOpts['_max_query_occurrences'])
            : null;

        if (method_exists($this->analyzer, 'analyze_query_occurrences')) {
            return $this->normalize_query_analysis(
                $this->analyzer->analyze_query_occurrences($query, $analysisOpts),
                $maxOccurrences
            );
        }

        if (!is_callable([$this->analyzer, 'analyze_query'])) {
            throw new LogicException('Analyzer must provide analyze_query().');
        }

        $returnOpts = $analysisOpts;
        $returnOpts['return'] = 'occurrences';
        $occurrences = $this->normalize_query_analysis(
            $this->analyzer->analyze_query($query, $returnOpts),
            $maxOccurrences
        );
        if ($this->has_occurrence_rows($occurrences)) {
            return $occurrences;
        }

        $formatOpts = $analysisOpts;
        $formatOpts['format'] = 'occurrences';
        $occurrences = $this->normalize_query_analysis(
            $this->analyzer->analyze_query($query, $formatOpts),
            $maxOccurrences
        );
        if ($this->has_occurrence_rows($occurrences)) {
            return $occurrences;
        }

        return $this->normalize_query_analysis(
            $this->analyzer->analyze_query($query, $analysisOpts),
            $maxOccurrences
        );
    }

    /**
     * Prepare analyzer options with explicit query language aliases filled in.
     *
     * Older analyzers may read `lang` or `language` instead of `query_lang`, so
     * an explicit query language is mirrored across all three names.
     *
     * @param array<string,mixed> $opts Public search options.
     * @return array<string,mixed> Options passed to analyzer methods.
     */
    private function query_analysis_options(array $opts): array
    {
        $analysisOpts = $opts;
        $explicitLang = WP_FTS_TermNamespace::language_from_options($opts, null, ['query_lang', 'lang', 'language']);
        if ($explicitLang !== null) {
            $analysisOpts['query_lang'] = $explicitLang;
            $analysisOpts['lang'] = $explicitLang;
            $analysisOpts['language'] = $explicitLang;
        }
        if ($this->explain_requested($opts)) {
            $analysisOpts['_include_query_surface'] = true;
        }

        return $analysisOpts;
    }

    /**
     * Normalize analyzer output to a numerically indexed array.
     *
     * Non-array analyzer returns are treated as no query terms.
     *
     * @param mixed $analysis Raw analyzer result.
     * @return array<int,array<string,mixed>|string>
     */
    private function normalize_query_analysis(mixed $analysis, ?int $maxOccurrences = null): array
    {
        if (!is_array($analysis)) {
            return [];
        }
        $maxOccurrences ??= WP_FTS_Analysis_Limits::MAX_DOCUMENT_OCCURRENCES;
        if (count($analysis) > $maxOccurrences) {
            throw new WP_FTS_Analysis_Limit_Exceeded(
                'occurrences',
                "FTS analyzer output exceeds the {$maxOccurrences}-occurrence limit."
            );
        }
        foreach ($analysis as $occurrence) {
            $this->assert_analyzer_occurrence_output($occurrence);
        }

        return array_values($analysis);
    }

    /**
     * Bound third-party analyzer output before reindexing or normalizing it.
     *
     * The analyzer receives a 12-occurrence stop hint, but a custom analyzer
     * may ignore it. Check the original array first so `array_values()` cannot
     * duplicate an arbitrarily large result only to reject it afterward.
     *
     * @return array<int,array<string,mixed>|string>
     */
    private function normalize_set_oriented_query_analysis(mixed $analysis): array
    {
        if (!is_array($analysis)) {
            return [];
        }
        if (count($analysis) > WP_FTS_Set_Oriented_Search_Storage::MAX_QUERY_ALTERNATIVES) {
            throw new WP_FTS_Search_Budget_Exceeded('analyzer occurrences');
        }

        foreach ($analysis as $occurrence) {
            $this->assert_analyzer_occurrence_output($occurrence);
        }

        return array_values($analysis);
    }

    /** Reject extension analyzer scalars before trim, casts, or array reindexing. */
    private function assert_analyzer_occurrence_output(mixed $occurrence): void
    {
        if (is_string($occurrence)) {
            if (strlen($occurrence) > WP_FTS_TermNamespace::MAX_TERM_KEY_BYTES) {
                throw new WP_FTS_Search_Budget_Exceeded('analyzer occurrence bytes');
            }
            return;
        }
        if (!is_array($occurrence)) {
            throw new InvalidArgumentException('Analyzer occurrences must be strings or arrays.');
        }

        if (
            array_key_exists('term', $occurrence)
            && (!is_scalar($occurrence['term']) || strlen((string) $occurrence['term']) > WP_FTS_TermNamespace::MAX_TERM_KEY_BYTES)
        ) {
            throw new WP_FTS_Search_Budget_Exceeded('analyzer occurrence bytes');
        }
        foreach (['surface', 'normalized_surface'] as $key) {
            if (!array_key_exists($key, $occurrence)) {
                continue;
            }
            if (!is_scalar($occurrence[$key]) || strlen((string) $occurrence[$key]) > self::MAX_SET_ORIENTED_OCCURRENCE_TEXT_BYTES) {
                throw new WP_FTS_Search_Budget_Exceeded('analyzer occurrence bytes');
            }
        }
        if (
            array_key_exists('lang', $occurrence)
            && (!is_scalar($occurrence['lang']) || strlen((string) $occurrence['lang']) > self::MAX_SET_ORIENTED_LANGUAGE_BYTES)
        ) {
            throw new WP_FTS_Search_Budget_Exceeded('analyzer language bytes');
        }
        if (
            array_key_exists('position', $occurrence)
            && (!is_scalar($occurrence['position']) || strlen((string) $occurrence['position']) > self::MAX_SET_ORIENTED_OCCURRENCE_POSITION_BYTES)
        ) {
            throw new WP_FTS_Search_Budget_Exceeded('analyzer occurrence bytes');
        }
        if (
            array_key_exists('rank', $occurrence)
            && is_string($occurrence['rank'])
            && strlen($occurrence['rank']) > self::MAX_SET_ORIENTED_OCCURRENCE_RANK_BYTES
        ) {
            throw new WP_FTS_Search_Budget_Exceeded('analyzer occurrence bytes');
        }
    }

    /**
     * Check whether analyzer output contains structured occurrence rows.
     *
     * @param array<int,array<string,mixed>|string> $analysis
     * @return bool True when at least one item is an array.
     */
    private function has_occurrence_rows(array $analysis): bool
    {
        foreach ($analysis as $occurrence) {
            if (is_array($occurrence)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Resolve the default language for untagged query occurrences.
     *
     * Explicit search options win. Otherwise the first structured occurrence
     * language or first already-namespaced term supplies the default used by
     * legacy string-only analyzer output. Mixed occurrence rows still keep their
     * own languages when query groups are built.
     *
     * @param array<int,array<string,mixed>|string> $queryOccurrences
     * @return string Canonical query language.
     */
    private function resolve_query_language(array $opts, array $queryOccurrences): string
    {
        $explicitLang = WP_FTS_TermNamespace::language_from_options($opts, null, ['query_lang', 'lang', 'language']);
        if ($explicitLang !== null) {
            return $explicitLang;
        }

        foreach ($queryOccurrences as $occurrence) {
            if (is_array($occurrence) && isset($occurrence['lang']) && trim((string) $occurrence['lang']) !== '') {
                return WP_FTS_TermNamespace::canonicalize_lang((string) $occurrence['lang']);
            }

            $term = is_array($occurrence) ? (string) ($occurrence['term'] ?? '') : (string) $occurrence;
            $split = WP_FTS_TermNamespace::split_term($term);
            if ($split !== null) {
                return $split['lang'];
            }
        }

        return WP_FTS_TermNamespace::default_language($opts);
    }

    /**
     * Compute one term's BM25 contribution for one active document.
     *
     * @param int $tf Weighted term frequency in the document.
     * @param int $docLen Document length for the query language partition.
     * @param int $docCount Number of active documents in the partition.
     * @param int $docFreq Active document frequency for the term.
     * @param float $avgDocLen Average document length in the partition.
     * @return float Positive BM25 contribution.
     */
    private function bm25(int $tf, int $docLen, int $docCount, int $docFreq, float $avgDocLen): float
    {
        $idf = log(1.0 + (($docCount - $docFreq + 0.5) / ($docFreq + 0.5)));
        $normalizer = $tf + $this->k1 * (1.0 - $this->b + $this->b * ($docLen / max(1.0, $avgDocLen)));

        return $idf * (($tf * ($this->k1 + 1.0)) / $normalizer);
    }

    /**
     * Compute a BM25 contribution when the query-level IDF is already cached.
     */
    private function bm25_with_idf(int $tf, int $docLen, float $idf, float $avgDocLen): float
    {
        $normalizer = $tf + $this->k1 * (1.0 - $this->b + $this->b * ($docLen / max(1.0, $avgDocLen)));

        return $idf * (($tf * ($this->k1 + 1.0)) / $normalizer);
    }

    /**
     * Recompute document frequencies against active documents only.
     *
     * Stored `df` values may include tombstoned documents until `optimize()`
     * compacts postings. Search scoring uses this filtered frequency so deleted
     * documents do not affect IDF.
     *
     * @param array<string,array<int,int>> $decodedByTerm
     * @param array<int,int> $activeDocLengths
     * @return array<string,int>
     */
    private function active_doc_freqs(array $decodedByTerm, array $activeDocLengths): array
    {
        $active = array_fill_keys(array_keys($activeDocLengths), true);
        $dfs = [];
        foreach ($decodedByTerm as $term => $postings) {
            $df = 0;
            foreach ($postings as $docId => $_) {
                if (isset($active[$docId])) {
                    $df++;
                }
            }
            $dfs[$term] = $df;
        }

        return $dfs;
    }
}
