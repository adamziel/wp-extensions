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
 * Searches a relational set-oriented index.
 */
final class WP_FTS_Searcher
{
    private const DEFAULT_PREFIX_MIN_LENGTH = 4;
    private const DEFAULT_MAX_QUERY_TERMS = WP_FTS_Set_Oriented_Search_Storage::MAX_QUERY_ALTERNATIVES;
    private const MAX_SET_ORIENTED_QUERY_BYTES = 4096;
    private const MAX_SET_ORIENTED_MODE_BYTES = 8;
    private const MAX_SET_ORIENTED_LANGUAGE_BYTES = 64;
    private const MAX_SET_ORIENTED_CURSOR_BYTES = 2048;
    private const MAX_SET_ORIENTED_OCCURRENCE_TEXT_BYTES = 4096;
    private const MAX_SET_ORIENTED_OCCURRENCE_POSITION_BYTES = 64;
    private const MAX_SET_ORIENTED_OCCURRENCE_RANK_BYTES = 32;
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
    private const MAX_RECENCY_BOOST_STRENGTH = 2.0;
    private const DEFAULT_RECENCY_BOOST_HALF_LIFE_DAYS = 30.0;
    private const MIN_RECENCY_BOOST_HALF_LIFE_DAYS = 1.0;
    private const MAX_RECENCY_BOOST_HALF_LIFE_DAYS = 3650.0;
    private const SNIPPET_TOKEN_PATTERN = '/[\p{L}\p{M}\p{N}_]+/u';
    /** @var callable|null */
    private $activeRequestBudgetGuard = null;
    public function __construct(
        private WP_FTS_Set_Oriented_Search_Storage $storage,
        private object $analyzer,
    ) {
    }

    /**
     * Search the index for documents matching a query.
     *
     * `mode` may be `OR` or `AND`; `AND` requires every logical query term.
     * Storage owns prefix resolution, visibility, ranking, cursor pagination,
     * and bounded result hydration. `include_metadata` adds WordPress result
     * fields and `include_snippets` builds bounded snippets from extracted text.
     * Snippets are safe HTML containing escaped text and internally generated
     * `<mark>` elements only; source markup is never returned.
     * Search always returns an unknown-total cursor payload with
     * `total => null`, `total_relation => 'unknown'`, `has_more`,
     * `next_cursor`, `previous_cursor`, `query_lang`, and `results`. It owns
     * prefix resolution, visibility, ranking, and hydration, and rejects more
     * than 12 logical groups or 12 alternatives in total.
     *
     * @param array<string,mixed> $opts
     * @return array{total:null,total_relation:string,query_lang:string,has_more:bool,next_cursor:?string,previous_cursor:?string,results:array<int,array<string,mixed>>,explain?:array<string,mixed>}
     * @throws InvalidArgumentException If `mode` is not `OR` or `AND`.
     * @throws LogicException If the analyzer does not provide a query analyzer.
     * @throws WP_FTS_Search_Budget_Exceeded If a request budget is exhausted.
     */
    public function search(string $query, array $opts = []): array
    {
        $this->assert_public_option_map_bounds($opts);
        $previousGuard = $this->activeRequestBudgetGuard;
        $this->activeRequestBudgetGuard = is_callable($opts['request_budget_guard'] ?? null)
            ? $opts['request_budget_guard']
            : null;

        try {
            $this->guard_request_budget();
            return $this->search_set_oriented_page($query, $opts);
        } finally {
            $this->activeRequestBudgetGuard = $previousGuard;
        }
    }

    /**
     * Delegate one analyzed query plan to a set-oriented storage backend.
     *
     * Storage receives the analyzer's original logical groups and owns the
     * single final-prefix lookup, visibility, ranking, and one-row lookahead.
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
        $pageSize = max(1, min(
            WP_FTS_Set_Oriented_Search_Storage::MAX_PAGE_SIZE,
            (int) ($opts['limit'] ?? 10)
        ));
        $explicitQueryLang = WP_FTS_TermNamespace::language_from_options($opts, null, ['query_lang']);
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
            $page = [
                'results' => [],
                'has_more' => false,
                'next_cursor' => null,
                'previous_cursor' => null,
            ];
            if ($this->explain_requested($opts)) {
                $page['explain'] = [
                    'storage' => 'set_oriented',
                    'logical_group_count' => 0,
                    'resolved_alternatives' => 0,
                    'anchor_group' => null,
                    'prefix_range' => false,
                    'prefix_strategy' => 'none',
                    'query_statements' => 0,
                    'interactive_total' => 'unknown',
                    'canonical_page_bytes' => 0,
                ];
            }
            return $this->normalize_set_oriented_page(
                $page,
                $pageSize,
                $responseLang,
                $query,
                $groups,
                $opts,
                []
            );
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
            'limit',
            'query_lang',
            'default_lang',
            'result_lang',
            'prefix_matching',
            'prefix_min_length',
            'include_metadata',
            'include_snippets',
            'highlight',
            'snippet_length',
            'explain',
            'cursor',
            'direction',
            'post_types',
            'post_statuses',
            'date_after',
            'date_before',
            'recency_boost_strength',
            'recency_boost_half_life_days',
            'now_gmt',
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
     * visitor queries after the thirteenth lexical unit. Analyzer output is
     * checked again below.
     */
    private function assert_set_oriented_query_input(string $query, array $opts): void
    {
        if (strlen($query) > self::MAX_SET_ORIENTED_QUERY_BYTES) {
            throw new WP_FTS_Search_Budget_Exceeded('query bytes');
        }

        foreach (['query_lang', 'default_lang', 'result_lang'] as $key) {
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
        foreach (['prefix_matching', 'include_metadata', 'include_snippets', '_include_canonical_post_rows', 'highlight', 'explain'] as $key) {
            $this->assert_set_oriented_switch_option($opts, $key);
        }

        foreach ([
            'limit' => [1, WP_FTS_Set_Oriented_Search_Storage::MAX_PAGE_SIZE],
            'max_query_terms' => [1, WP_FTS_Set_Oriented_Search_Storage::MAX_QUERY_ALTERNATIVES],
            'prefix_min_length' => [2, 255],
            'snippet_length' => [1, self::MAX_SET_ORIENTED_SNIPPET_LENGTH],
        ] as $key => [$minimum, $maximum]) {
            $this->assert_set_oriented_integer_option($opts, $key, $minimum, $maximum);
        }
        foreach ([
            'recency_boost_strength' => [0.0, self::MAX_RECENCY_BOOST_STRENGTH],
            'recency_boost_half_life_days' => [self::MIN_RECENCY_BOOST_HALF_LIFE_DAYS, self::MAX_RECENCY_BOOST_HALF_LIFE_DAYS],
        ] as $key => [$minimum, $maximum]) {
            $this->assert_set_oriented_float_option($opts, $key, $minimum, $maximum);
        }
        foreach (['date_after', 'date_before', 'now_gmt'] as $key) {
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
        if (array_key_exists('request_budget_guard', $opts) && !is_callable($opts['request_budget_guard'])) {
            throw new InvalidArgumentException('Set-oriented request_budget_guard must be callable.');
        }
        // Cursor and filter checks belong before analyzer work. Their helpers
        // are repeated when the storage options are built, but only over the
        // already-proven 2 KiB/32-value envelopes.
        $this->set_oriented_cursor($opts);
        $this->bounded_set_oriented_filter_values($opts['post_types'] ?? []);
        $this->bounded_set_oriented_filter_values($opts['post_statuses'] ?? []);

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

    /** Accept only booleans at the component boundary. */
    private function assert_set_oriented_switch_option(array $opts, string $key): void
    {
        if (!array_key_exists($key, $opts)) {
            return;
        }

        if (is_bool($opts[$key])) {
            return;
        }

        throw new InvalidArgumentException("Set-oriented {$key} must be a boolean.");
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

        if (
            !is_int($opts[$key])
            || $opts[$key] < $minimum
            || $opts[$key] > $maximum
        ) {
            throw new InvalidArgumentException("Set-oriented {$key} must be an integer from {$minimum} through {$maximum}.");
        }
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
        if ((!is_int($value) && !is_float($value)) || !is_finite((float) $value) || $value < $minimum || $value > $maximum) {
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

    /** @return array<int,array<string,mixed>> */
    private function analyze_query_once(string $query, array $opts): array
    {
        $analysisOpts = $this->query_analysis_options($opts);
        // Stop the analyzer at the same hard plan bound instead of first
        // materializing thousands of CJK n-grams or custom-tokenizer rows that
        // the relational backend must reject immediately afterward.
        $analysisOpts['_max_query_occurrences'] = WP_FTS_Set_Oriented_Search_Storage::MAX_QUERY_ALTERNATIVES;
        // The final prefix descriptor must come from this same analyzer pass.
        // `surface` remains presentation text; `normalized_surface` is the
        // typed lexical key and must never be inferred from a lemma later.
        $analysisOpts['_include_query_surface'] = true;

        if (!is_callable([$this->analyzer, 'analyze_query_occurrences'])) {
            throw new LogicException('Analyzer must provide analyze_query_occurrences().');
        }

        return $this->normalize_set_oriented_query_analysis(
            $this->analyzer->analyze_query_occurrences($query, $analysisOpts)
        );
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
        $postTypes = $this->bounded_set_oriented_filter_values($opts['post_types'] ?? []);
        $postStatuses = $this->bounded_set_oriented_filter_values($opts['post_statuses'] ?? []);

        $storageOptions = [
            'mode' => $mode,
            'page_size' => $pageSize,
            'limit' => $pageSize + 1,
            'cursor' => $cursor,
            'direction' => $direction,
            'prefix_matching' => $this->prefix_matching_enabled($opts),
            'prefix_group_index' => $prefixGroupIndex,
            'prefix_min_length' => $this->prefix_min_length($opts),
            'post_types' => $postTypes,
            'post_statuses' => $postStatuses,
            'date_after' => $this->bounded_set_oriented_date_filter(
                $opts['date_after'] ?? null,
                false
            ),
            'date_before' => $this->bounded_set_oriented_date_filter(
                $opts['date_before'] ?? null,
                true
            ),
            'include_metadata' => $opts['include_metadata'] ?? false,
            'include_snippets' => $opts['include_snippets'] ?? false,
            // The WordPress integration consumes this private transport row so
            // its bounded third statement is also the canonical WP_Post hydrate.
            'include_canonical_post_row' => $opts['_include_canonical_post_rows'] ?? false,
            'highlight' => $opts['highlight'] ?? false,
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
     * @param array<int,array<string,mixed>> $occurrences
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
                $authoritativeLang
            );
            if ($candidate === null || !isset($finalKeys[$candidate['key']])) {
                return null;
            }

            $surface = $this->normalized_occurrence_surface($occurrence);
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
     * @param array<string,mixed> $occurrence
     */
    private function normalized_occurrence_surface(array $occurrence): string
    {
        return isset($occurrence['normalized_surface']) && is_scalar($occurrence['normalized_surface'])
            ? trim((string) $occurrence['normalized_surface'])
            : '';
    }

    /** @return array{0:?string,1:string} */
    private function set_oriented_cursor(array $opts): array
    {
        $cursor = $this->set_oriented_cursor_value($opts['cursor'] ?? null);
        $direction = $opts['direction'] ?? 'after';
        if (!is_string($direction) || !in_array($direction, ['after', 'before'], true)) {
            throw new InvalidArgumentException('Cursor direction must be exactly after or before.');
        }
        if ($cursor === null && array_key_exists('direction', $opts)) {
            throw new InvalidArgumentException('Cursor direction requires a nonempty cursor.');
        }

        return [$cursor, $direction];
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
        array $authoritativePrefixes = []
    ): array
    {
        foreach (['results', 'has_more', 'next_cursor', 'previous_cursor'] as $key) {
            if (!array_key_exists($key, $page)) {
                throw new LogicException("Relational storage page is missing {$key}.");
            }
        }
        if (!is_array($page['results']) || !is_bool($page['has_more'])) {
            throw new LogicException('Relational storage returned an invalid page shape.');
        }
        if (count($page['results']) > $pageSize + 1) {
            throw new LogicException('Relational storage returned more than one lookahead row.');
        }

        $results = [];
        foreach ($page['results'] as $row) {
            if (
                !is_array($row)
                || !is_int($row['doc_id'] ?? null)
                || $row['doc_id'] <= 0
                || (!is_int($row['score'] ?? null) && !is_float($row['score'] ?? null))
                || !is_finite((float) $row['score'])
            ) {
                throw new LogicException('Relational storage returned an invalid result row.');
            }
            $row['score'] = (float) $row['score'];
            $results[] = $row;
        }

        $hasMore = $page['has_more'] || count($results) > $pageSize;
        $results = $this->enrich_set_oriented_results(
            array_slice($results, 0, $pageSize),
            $query,
            $opts,
            $queryGroups,
            $queryLang,
            $authoritativePrefixes
        );
        $payload = [
            'total' => null,
            'total_relation' => 'unknown',
            'query_lang' => WP_FTS_TermNamespace::canonicalize_lang($queryLang),
            'has_more' => $hasMore,
            // Reverse pages can have no more rows in the reverse direction and
            // still need a forward cursor back toward the originating page.
            'next_cursor' => $this->set_oriented_cursor_value($page['next_cursor']),
            'previous_cursor' => $this->set_oriented_cursor_value($page['previous_cursor']),
            'results' => $results,
        ];

        if ($this->explain_requested($opts)) {
            if (!isset($page['explain']) || !is_array($page['explain'])) {
                throw new LogicException('Relational storage omitted requested explain data.');
            }
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
     * Set-oriented storage attaches raw `snippet_text` and optional flattened
     * post columns to at most the first `page_size` rows. They are internal transport fields:
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
        array $authoritativePrefixes
    ): array
    {
        $includeMetadata = $opts['include_metadata'] ?? false;
        $includeSnippets = $opts['include_snippets'] ?? false;
        foreach ($results as &$row) {
            if ($includeMetadata) {
                foreach (['post_id', 'post_type', 'post_status', 'post_date_gmt', 'title', 'excerpt'] as $key) {
                    $row[$key] = isset($row[$key]) && is_scalar($row[$key])
                        ? (string) $row[$key]
                        : ($key === 'post_id' ? 0 : '');
                }
                $row['post_id'] = max(0, (int) $row['post_id']);
                if (
                    ($opts['highlight'] ?? false)
                    && is_scalar($row['title'] ?? null)
                    && (string) $row['title'] !== ''
                ) {
                    $row['highlighted_title'] = $this->highlight_analyzed_text(
                        (string) $row['title'],
                        $query,
                        $opts,
                        $queryGroups,
                        $queryLang,
                        $this->snippet_result_language($row, $opts, $queryLang),
                        $authoritativePrefixes
                    );
                }
            }

            if ($includeSnippets) {
                $resultLang = $this->snippet_result_language($row, $opts, $queryLang);
                $snippetLength = max(40, min(
                    self::MAX_SET_ORIENTED_SNIPPET_LENGTH,
                    (int) ($opts['snippet_length'] ?? 180)
                ));
                $snippetSource = is_scalar($row['snippet_text'] ?? null)
                    ? $this->bounded_set_oriented_snippet_source((string) $row['snippet_text'])
                    : '';
                $row['snippet'] = $this->snippet(
                    $snippetSource,
                    $query,
                    $snippetLength,
                    ($opts['highlight'] ?? false),
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

            unset($row['snippet_text']);
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
        array $authoritativePrefixes = []
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

    /** @return array{enabled:bool,strength:float,half_life_days:float,now_gmt:string} */
    private function recency_boost_config(array $opts): array
    {
        $strength = (float) ($opts['recency_boost_strength'] ?? 0.0);
        $halfLife = (float) ($opts['recency_boost_half_life_days'] ?? self::DEFAULT_RECENCY_BOOST_HALF_LIFE_DAYS);
        $now = $this->parse_gmt_timestamp($opts['now_gmt'] ?? null) ?? time();

        return [
            'enabled' => $strength > 0.0,
            'strength' => $strength,
            'half_life_days' => $halfLife,
            'now_gmt' => gmdate('Y-m-d H:i:s', $now),
        ];
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
     * Native prefix matching is opt-in for direct searcher callers.
     */
    private function prefix_matching_enabled(array $opts): bool
    {
        return $opts['prefix_matching'] ?? false;
    }

    /**
     * Minimum analyzed term length before prefix expansion is attempted.
     */
    private function prefix_min_length(array $opts): int
    {
        return $opts['prefix_min_length'] ?? self::DEFAULT_PREFIX_MIN_LENGTH;
    }

    /**
     * Maximum analyzed alternatives across the complete query plan.
     */
    private function max_query_terms(array $opts): int
    {
        return $opts['max_query_terms'] ?? self::DEFAULT_MAX_QUERY_TERMS;
    }

    /**
     * Force analyzer options to a specific query language.
     *
     * @return array<string,mixed>
     */
    private function with_query_language(array $opts, string $lang): array
    {
        unset($opts['default_lang']);
        $opts['query_lang'] = $lang;
        $opts['_force_query_lang'] = true;

        return $opts;
    }

    /**
     * Convert analyzer occurrences into one-language alternatives.
     *
     * @param array<int,array<string,mixed>> $occurrences
     * @param string|null $authoritativeLang Language partition that overrides
     *        analyzer-selected languages for an explicit query language.
     * @return array<int,array<int,array{key:string,lang:string,term:string,rank:int,source?:string,surface?:string}>>
     */
    private function groups_from_occurrences(array $occurrences, string $defaultLang, ?string $authoritativeLang = null): array
    {
        $groups = [];
        $groupByPosition = [];
        foreach ($occurrences as $occurrence) {
            $candidate = $this->candidate_from_occurrence($occurrence, $defaultLang, $authoritativeLang);
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
    private function occurrence_position(array $occurrence): ?string
    {
        if (!isset($occurrence['position']) || !is_scalar($occurrence['position'])) {
            return null;
        }

        return (string) $occurrence['position'];
    }

    /**
     * Normalize one analyzer occurrence into a stored term-key candidate.
     *
     * @param array<string,mixed> $occurrence
     * @param string|null $authoritativeLang Language partition that must own the
     *        candidate regardless of analyzer-selected language.
     * @return array{key:string,lang:string,term:string,rank:int,source:string,surface?:string}|null
     */
    private function candidate_from_occurrence(array $occurrence, string $defaultLang, ?string $authoritativeLang = null): ?array
    {
        $rawTerm = $occurrence['term'] ?? '';
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
        $occurrenceLang = $occurrence['lang'] ?? null;
        if ($occurrenceLang !== null && (!is_scalar($occurrenceLang) || strlen((string) $occurrenceLang) > self::MAX_SET_ORIENTED_LANGUAGE_BYTES)) {
            throw new WP_FTS_Search_Budget_Exceeded('analyzer language bytes');
        }
        $lang = $authoritativeLang ?? ($occurrenceLang !== null
            ? WP_FTS_TermNamespace::canonicalize_lang((string) $occurrenceLang, $defaultLang)
            : $defaultLang);

        if ($term === '') {
            return null;
        }

        if (!WP_FTS_TermNamespace::term_key_fits($term, $lang)) {
            return null;
        }

        $occurrenceRank = isset($occurrence['rank']) && is_numeric($occurrence['rank'])
            ? max(0, (int) $occurrence['rank'])
            : 0;

        $source = $occurrenceRank > 0 ? 'secondary_lemma' : 'exact';

        $candidate = [
            'key' => WP_FTS_TermNamespace::namespace_term($lang, $term),
            'lang' => $lang,
            'term' => $term,
            'rank' => $occurrenceRank,
            'source' => $source,
        ];
        if (isset($occurrence['surface']) && is_scalar($occurrence['surface'])) {
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
     * Pick the canonical query language reported with the cursor page.
     *
     * @param array<int,array<int,array{key:string,lang:string,term:string,rank:int}>> $groups
     * @return string Canonical language tag.
     */
    private function response_query_language(array $opts, array $groups): string
    {
        $explicitLang = WP_FTS_TermNamespace::language_from_options($opts, null, ['query_lang']);
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

    private function explain_requested(array $opts): bool
    {
        return $opts['explain'] ?? false;
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
        $this->assert_set_oriented_option_keys($opts);
        $this->assert_set_oriented_query_input($query, $opts);
        $text = $this->bounded_set_oriented_snippet_source($text);
        $query = trim($query);
        if ($query === '' || trim($text) === '') {
            return '';
        }

        $explicitQueryLang = WP_FTS_TermNamespace::language_from_options($opts, null, ['query_lang']);
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
        $resultLang = WP_FTS_TermNamespace::language_from_options($opts, null, ['result_lang'])
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
        array $authoritativePrefixes = []
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
     */
    private function snippet_result_language(array $row, array $opts, string $queryLang): string
    {
        foreach ([
            $row['primary_lang'] ?? null,
            WP_FTS_TermNamespace::language_from_options($opts, null, ['query_lang']),
            $queryLang,
        ] as $candidate) {
            if (is_scalar($candidate) && trim((string) $candidate) !== '') {
                return WP_FTS_TermNamespace::canonicalize_lang((string) $candidate);
            }
        }

        return WP_FTS_TermNamespace::default_language($opts);
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
     * The list comes from the set-oriented query plan and must not be widened
     * with lemma or stem alternatives for presentation.
     *
     * @param array<int,array{lang:string,term:string}> $authoritativePrefixes
     * @return array<int,array{lang:string,term:string}>
     */
    private function snippet_query_prefixes(
        array $opts,
        array $authoritativePrefixes
    ): array
    {
        if (!$this->prefix_matching_enabled($opts)) {
            return [];
        }

        $prefixes = [];
        $minimum = $this->prefix_min_length($opts);
        foreach ($authoritativePrefixes as $prefix) {
            if (!is_array($prefix)) {
                continue;
            }
            $term = is_scalar($prefix['term'] ?? null) ? (string) $prefix['term'] : '';
            $lang = is_scalar($prefix['lang'] ?? null) ? (string) $prefix['lang'] : '';
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
     * @return string[]
     */
    private function snippet_analysis_languages(array $queryGroups, string $queryLang, string $resultLang): array
    {
        $languages = [];
        foreach ([$resultLang, $queryLang] as $candidate) {
            if (is_scalar($candidate) && trim((string) $candidate) !== '') {
                $languages[WP_FTS_TermNamespace::canonicalize_lang((string) $candidate)] = true;
            }
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
        array $authoritativePrefixes = []
    ): array
    {
        if ($text === '' || $queryKeys === []) {
            return [];
        }

        $prefixes = $this->snippet_query_prefixes($opts, $authoritativePrefixes);
        $surfaces = [];
        foreach ($this->snippet_analysis_languages($queryGroups, $queryLang, $resultLang) as $lang) {
            $analysisOpts = $this->with_query_language($opts, $lang);
            $analysisOpts['_include_query_surface'] = true;
            $analysisOpts['_max_query_occurrences'] = self::MAX_SNIPPET_ANALYSIS_OCCURRENCES;
            try {
                $occurrences = $this->analyze_query($text, $analysisOpts);
                foreach ($occurrences as $occurrence) {
                    if (!is_array($occurrence) || !is_scalar($occurrence['surface'] ?? null)) {
                        continue;
                    }
                    $candidate = $this->candidate_from_occurrence($occurrence, $lang, $lang);
                    if ($candidate === null) {
                        continue;
                    }
                    $matches = isset($queryKeys[$candidate['key']]);
                    if (!$matches) {
                        $prefixTerm = $this->normalized_occurrence_surface($occurrence);
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

    /** @return array<int,array<string,mixed>> */
    private function analyze_query(string $query, array $opts): array
    {
        $analysisOpts = $this->query_analysis_options($opts);
        $maxOccurrences = isset($analysisOpts['_max_query_occurrences']) && is_int($analysisOpts['_max_query_occurrences'])
            ? max(1, $analysisOpts['_max_query_occurrences'])
            : null;

        if (!is_callable([$this->analyzer, 'analyze_query_occurrences'])) {
            throw new LogicException('Analyzer must provide analyze_query_occurrences().');
        }

        return $this->normalize_query_analysis(
            $this->analyzer->analyze_query_occurrences($query, $analysisOpts),
            $maxOccurrences
        );
    }

    /**
     * @param array<string,mixed> $opts Public search options.
     * @return array<string,mixed> Options passed to analyzer methods.
     */
    private function query_analysis_options(array $opts): array
    {
        $analysisOpts = $opts;
        $explicitLang = WP_FTS_TermNamespace::language_from_options($opts, null, ['query_lang']);
        $defaultLang = WP_FTS_TermNamespace::language_from_options($opts, null, ['default_lang']);
        unset($analysisOpts['default_lang']);
        if ($explicitLang !== null) {
            $analysisOpts['query_lang'] = $explicitLang;
        } elseif ($defaultLang !== null) {
            $analysisOpts['_default_query_lang'] = $defaultLang;
        }
        if ($this->explain_requested($opts)) {
            $analysisOpts['_include_query_surface'] = true;
        }

        return $analysisOpts;
    }

    /**
     * Normalize analyzer output to a numerically indexed array.
     *
     * @param mixed $analysis Raw analyzer result.
     * @return array<int,array<string,mixed>>
     */
    private function normalize_query_analysis(mixed $analysis, ?int $maxOccurrences = null): array
    {
        if (!is_array($analysis)) {
            throw new InvalidArgumentException('Analyzer output must be an array of occurrences.');
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
     * @return array<int,array<string,mixed>>
     */
    private function normalize_set_oriented_query_analysis(mixed $analysis): array
    {
        if (!is_array($analysis)) {
            throw new InvalidArgumentException('Analyzer output must be an array of occurrences.');
        }
        if (count($analysis) > WP_FTS_Set_Oriented_Search_Storage::MAX_QUERY_ALTERNATIVES) {
            throw new WP_FTS_Search_Budget_Exceeded('analyzer occurrences');
        }

        foreach ($analysis as $occurrence) {
            $this->assert_analyzer_occurrence_output($occurrence);
        }

        return array_values($analysis);
    }

    /** Reject extension analyzer output before trim, casts, or array reindexing. */
    private function assert_analyzer_occurrence_output(mixed $occurrence): void
    {
        if (!is_array($occurrence)) {
            throw new InvalidArgumentException('Analyzer occurrences must be arrays.');
        }

        if (
            !array_key_exists('term', $occurrence)
            || !is_scalar($occurrence['term'])
            || strlen((string) $occurrence['term']) > WP_FTS_TermNamespace::MAX_TERM_KEY_BYTES
        ) {
            throw new InvalidArgumentException('Analyzer occurrences must contain one bounded term.');
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
     * Resolve the default language for untagged query occurrences.
     *
     * Explicit search options win. Otherwise the first structured occurrence
     * language supplies the default. Occurrence rows keep their own languages
     * when query groups are built.
     *
     * @param array<int,array<string,mixed>> $queryOccurrences
     * @return string Canonical query language.
     */
    private function resolve_query_language(array $opts, array $queryOccurrences): string
    {
        $explicitLang = WP_FTS_TermNamespace::language_from_options($opts, null, ['query_lang']);
        if ($explicitLang !== null) {
            return $explicitLang;
        }

        foreach ($queryOccurrences as $occurrence) {
            if (isset($occurrence['lang']) && trim((string) $occurrence['lang']) !== '') {
                return WP_FTS_TermNamespace::canonicalize_lang((string) $occurrence['lang']);
            }
        }

        return WP_FTS_TermNamespace::default_language($opts);
    }
}
