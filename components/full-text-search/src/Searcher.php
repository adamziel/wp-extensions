<?php
declare(strict_types=1);

/**
 * Signals a fixed search resource limit before more work is read.
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
    private const MAX_SNIPPET_ANALYSIS_SOURCE_BYTES = 2048;
    private const MAX_SNIPPET_ANALYSIS_OCCURRENCES = 3072;
    private const MAX_SNIPPET_ANALYSIS_LANGUAGES = 2;
    private const SNIPPET_TOKEN_PATTERN = '/[\p{L}\p{M}\p{N}_]+/u';
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
     * Search returns `has_more`, cursors, `query_lang`, and `results`. It owns
     * prefix resolution, visibility, ranking, and hydration, and rejects more
     * than 12 logical groups or 12 alternatives in total.
     *
     * @param array<string,mixed> $opts
     * @return array{query_lang:string,has_more:bool,next_cursor:?string,previous_cursor:?string,results:array<int,array<string,mixed>>,explain?:array<string,mixed>}
     * @throws InvalidArgumentException If `mode` is not `OR` or `AND`.
     * @throws LogicException If the analyzer does not provide a query analyzer.
     * @throws WP_FTS_Search_Budget_Exceeded If a request budget is exhausted.
     */
    public function search(string $query, array $opts = []): array
    {
        return $this->search_set_oriented_page($query, $opts);
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
        $pageSize = $opts['limit'] ?? 10;
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
        $groups = $this->dedupe_query_groups($this->groups_from_occurrences(
            $queryOccurrences,
            $explicitQueryLang
        ));
        $this->assert_set_oriented_query_groups($groups);
        if ($this->query_group_term_count($groups) > WP_FTS_Set_Oriented_Search_Storage::MAX_QUERY_ALTERNATIVES) {
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
                $recencyBoost = $this->recency_boost_config($opts);
                $page['explain'] = [
                    'storage' => 'set_oriented',
                    'logical_group_count' => 0,
                    'resolved_alternatives' => 0,
                    'anchor_group' => null,
                    'prefix_range' => false,
                    'prefix_strategy' => 'none',
                    'query_statements' => 0,
                    'interactive_total' => 'unknown',
                    'recency_boost' => [
                        'enabled' => $recencyBoost['enabled'],
                        'strength' => $recencyBoost['enabled'] ? $recencyBoost['strength'] : 0.0,
                        'half_life_days' => $recencyBoost['half_life_days'],
                        'scoring_now_gmt' => '',
                    ],
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
            count($groups) - 1,
            $storagePrefixSurface,
            $searchReadyIncarnation,
            $searchReadyProfileHash
        );
        $page = $this->storage->search_page($this->storage_query_groups($groups), $storageOptions);

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

    /** Resolve the exact component search mode. */
    private function search_mode(array $opts): string
    {
        $rawMode = $opts['mode'] ?? 'OR';
        if (!is_string($rawMode) || strlen($rawMode) > WP_FTS_Set_Oriented_Search_Storage::MAX_MODE_BYTES) {
            throw new InvalidArgumentException('Search mode must be a string of at most 8 bytes.');
        }

        return $rawMode;
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
        foreach (['query_lang', 'default_lang'] as $key) {
            if (!array_key_exists($key, $opts)) {
                continue;
            }
            WP_FTS_TermNamespace::parse_language_tag($opts[$key]);
        }
        foreach (['prefix_matching', 'include_metadata', 'include_snippets', '_include_canonical_post_rows', 'highlight', 'explain'] as $key) {
            $this->assert_set_oriented_switch_option($opts, $key);
        }

        foreach ([
            'limit' => [1, WP_FTS_Set_Oriented_Search_Storage::MAX_PAGE_SIZE],
            'prefix_min_length' => [WP_FTS_Set_Oriented_Search_Storage::MIN_PREFIX_LENGTH, WP_FTS_Set_Oriented_Search_Storage::MAX_PREFIX_LENGTH],
            'snippet_length' => [WP_FTS_Set_Oriented_Search_Storage::MIN_SNIPPET_LENGTH, WP_FTS_Set_Oriented_Search_Storage::MAX_SNIPPET_LENGTH],
        ] as $key => [$minimum, $maximum]) {
            $this->assert_set_oriented_integer_option($opts, $key, $minimum, $maximum);
        }
        foreach ([
            'recency_boost_strength' => [0.0, WP_FTS_Set_Oriented_Search_Storage::MAX_RECENCY_BOOST_STRENGTH],
            'recency_boost_half_life_days' => [WP_FTS_Set_Oriented_Search_Storage::MIN_RECENCY_BOOST_HALF_LIFE_DAYS, WP_FTS_Set_Oriented_Search_Storage::MAX_RECENCY_BOOST_HALF_LIFE_DAYS],
        ] as $key => [$minimum, $maximum]) {
            $this->assert_set_oriented_float_option($opts, $key, $minimum, $maximum);
        }
        foreach (['date_after', 'date_before'] as $key) {
            if (!array_key_exists($key, $opts)) {
                continue;
            }
            if (
                !is_string($opts[$key])
                || $opts[$key] === ''
                || trim($opts[$key]) !== $opts[$key]
                || strlen($opts[$key]) > WP_FTS_Set_Oriented_Search_Storage::MAX_FILTER_VALUE_BYTES
                || $this->parse_gmt_timestamp($opts[$key]) === null
            ) {
                throw new InvalidArgumentException("Set-oriented {$key} must be a valid UTC date or datetime of at most 64 bytes.");
            }
        }
        // Cursor and filter checks belong before analyzer work. Their helpers
        // are repeated when the storage options are built, but only over the
        // already-proven 2 KiB/32-value envelopes.
        $this->set_oriented_cursor($opts);
        $this->bounded_set_oriented_filter_values($opts['post_types'] ?? []);
        $this->bounded_set_oriented_filter_values($opts['post_statuses'] ?? []);

        $this->assert_query_group_envelope($query);
    }

    /** Reject an ordinary query before a tokenizer can widen it into a plan. */
    private function assert_query_group_envelope(string $query): void
    {
        if (strlen($query) > WP_FTS_Set_Oriented_Search_Storage::MAX_QUERY_BYTES) {
            throw new WP_FTS_Search_Budget_Exceeded('query bytes');
        }
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

    /** Reject options unrelated to caller-supplied snippet rendering. */
    private function assert_snippet_option_keys(array $opts): void
    {
        $allowed = array_fill_keys([
            'query_lang',
            'default_lang',
            'result_lang',
            'prefix_matching',
            'prefix_min_length',
            'highlight',
            'snippet_length',
        ], true);

        foreach ($opts as $key => $_value) {
            if (!is_string($key) || !isset($allowed[$key])) {
                throw new InvalidArgumentException('Snippet options contain an unsupported field.');
            }
        }
    }

    /** Validate the exact public snippet contract before analyzer work. */
    private function assert_snippet_input(string $query, array $opts): void
    {
        foreach (['query_lang', 'default_lang', 'result_lang'] as $key) {
            if (!array_key_exists($key, $opts)) {
                continue;
            }
            WP_FTS_TermNamespace::parse_language_tag($opts[$key]);
        }
        foreach (['prefix_matching', 'highlight'] as $key) {
            $this->assert_set_oriented_switch_option($opts, $key);
        }
        $this->assert_set_oriented_integer_option(
            $opts,
            'prefix_min_length',
            WP_FTS_Set_Oriented_Search_Storage::MIN_PREFIX_LENGTH,
            WP_FTS_Set_Oriented_Search_Storage::MAX_PREFIX_LENGTH
        );
        $this->assert_set_oriented_integer_option(
            $opts,
            'snippet_length',
            40,
            WP_FTS_Set_Oriented_Search_Storage::MAX_SNIPPET_LENGTH
        );
        $this->assert_query_group_envelope($query);
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
            'prefix_matching' => $this->prefix_matching_enabled($opts),
            'prefix_group_index' => $prefixGroupIndex,
            'prefix_min_length' => $this->prefix_min_length($opts),
            'post_types' => $postTypes,
            'post_statuses' => $postStatuses,
            'include_metadata' => $opts['include_metadata'] ?? false,
            'include_snippets' => $opts['include_snippets'] ?? false,
            // The WordPress integration consumes this private transport row so
            // its bounded third statement is also the canonical WP_Post hydrate.
            'include_canonical_post_row' => $opts['_include_canonical_post_rows'] ?? false,
            'explain' => $this->explain_requested($opts),
            'recency_boost_strength' => $recencyBoost['enabled'] ? $recencyBoost['strength'] : 0.0,
            'recency_boost_half_life_days' => $recencyBoost['half_life_days'],
        ];
        if ($cursor !== null) {
            $storageOptions['cursor'] = $cursor;
            $storageOptions['direction'] = $direction;
        }
        foreach ([
            'date_after' => false,
            'date_before' => true,
        ] as $key => $endOfDay) {
            if (array_key_exists($key, $opts)) {
                $storageOptions[$key] = $this->bounded_set_oriented_date_filter($opts[$key], $endOfDay);
            }
        }
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
     * the production relational backend requires it.
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
        ?string $authoritativeLang
    ): ?array {
        $finalKeys = [];
        foreach ($finalGroup as $candidate) {
            $finalKeys[$candidate['key']] = true;
        }

        for ($index = count($occurrences) - 1; $index >= 0; $index--) {
            $occurrence = $occurrences[$index];
            $hasSurface = (
                isset($occurrence['normalized_surface'])
                && $occurrence['normalized_surface'] !== ''
            ) || (
                isset($occurrence['surface'])
                && $occurrence['surface'] !== ''
            );
            if (!$hasSurface) {
                continue;
            }

            $candidate = $this->candidate_from_occurrence(
                $occurrence,
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
        return isset($occurrence['normalized_surface'])
            ? $occurrence['normalized_surface']
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

        if (strlen($value) > WP_FTS_Set_Oriented_Search_Storage::MAX_CURSOR_BYTES) {
            throw new InvalidArgumentException('Search cursor is too long.');
        }

        if ($value === '' || trim($value) !== $value) {
            throw new InvalidArgumentException('Search cursors must be nonempty unpadded strings.');
        }

        return $value;
    }

    /**
     * @return string[]
     */
    private function bounded_set_oriented_filter_values(mixed $value): array
    {
        if (!is_array($value) || !array_is_list($value)) {
            throw new InvalidArgumentException('Set-oriented metadata filters must be lists of strings.');
        }
        if (count($value) > WP_FTS_Set_Oriented_Search_Storage::MAX_FILTER_VALUES) {
            throw new InvalidArgumentException('Set-oriented search accepts at most 32 values per metadata filter.');
        }

        $inputBytes = 0;
        $bounded = [];
        foreach ($value as $filterValue) {
            if (!is_string($filterValue)
                || $filterValue === ''
                || trim($filterValue) !== $filterValue
            ) {
                throw new InvalidArgumentException('Set-oriented metadata filter values must be nonempty strings.');
            }
            if (strlen($filterValue) > WP_FTS_Set_Oriented_Search_Storage::MAX_FILTER_VALUE_BYTES) {
                throw new InvalidArgumentException('Set-oriented metadata filter values may contain at most 64 bytes.');
            }
            $inputBytes += strlen($filterValue);
            if ($inputBytes > WP_FTS_Set_Oriented_Search_Storage::MAX_FILTER_BYTES) {
                throw new InvalidArgumentException('Set-oriented metadata filters may contain at most 4096 bytes.');
            }
            $bounded[$filterValue] = true;
        }

        $bounded = array_keys($bounded);
        sort($bounded, SORT_STRING);

        return $bounded;
    }

    /**
     * Normalize a date filter without accepting an unbounded parser input.
     */
    private function bounded_set_oriented_date_filter(string $value, bool $endOfDay): string
    {
        if (
            $value === ''
            || trim($value) !== $value
            || strlen($value) > WP_FTS_Set_Oriented_Search_Storage::MAX_FILTER_VALUE_BYTES
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
     * Validate one exact backend page before presentation enrichment.
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
        $expectedPageKeys = ['results', 'has_more', 'next_cursor', 'previous_cursor'];
        if ($this->explain_requested($opts)) {
            $expectedPageKeys[] = 'explain';
        }
        if (array_keys($page) !== $expectedPageKeys) {
            throw new LogicException('Relational storage returned an invalid page shape.');
        }
        if (!is_array($page['results']) || !array_is_list($page['results']) || !is_bool($page['has_more'])) {
            throw new LogicException('Relational storage returned invalid page field types.');
        }
        if (count($page['results']) > $pageSize) {
            throw new LogicException('Relational storage returned more rows than the requested page size.');
        }

        $results = [];
        $docIds = [];
        $metadataPageBytes = 0;
        $canonicalPageBytes = 0;
        foreach ($page['results'] as $row) {
            $sidecarBytes = $this->assert_set_oriented_result_row($row, $opts);
            if (isset($docIds[$row['doc_id']])) {
                throw new LogicException('Relational storage returned a duplicate document ID.');
            }
            $docIds[$row['doc_id']] = true;
            if (
                $metadataPageBytes > WP_FTS_Set_Oriented_Search_Storage::MAX_SIDECAR_PAGE_BYTES - $sidecarBytes['metadata']
                || $canonicalPageBytes > WP_FTS_Set_Oriented_Search_Storage::MAX_SIDECAR_PAGE_BYTES - $sidecarBytes['canonical']
            ) {
                throw new LogicException('Relational storage returned an oversized result sidecar page.');
            }
            $metadataPageBytes += $sidecarBytes['metadata'];
            $canonicalPageBytes += $sidecarBytes['canonical'];
            $results[] = $row;
        }

        $nextCursor = $this->set_oriented_output_cursor($page['next_cursor']);
        $previousCursor = $this->set_oriented_output_cursor($page['previous_cursor']);
        $continuationCursor = ($opts['direction'] ?? 'after') === 'before'
            ? $previousCursor
            : $nextCursor;
        if ($page['has_more'] !== ($continuationCursor !== null)) {
            throw new LogicException('Relational storage returned contradictory continuation state.');
        }
        if ($this->explain_requested($opts)) {
            $this->assert_set_oriented_explain(
                $page['explain'],
                $queryGroups,
                $opts,
                $canonicalPageBytes
            );
        }

        $results = $this->enrich_set_oriented_results(
            $results,
            $query,
            $opts,
            $queryGroups,
            $queryLang,
            $authoritativePrefixes
        );
        $payload = [
            'query_lang' => $this->set_oriented_output_language($queryLang),
            'has_more' => $page['has_more'],
            // Reverse pages can have no more rows in the reverse direction and
            // still need a forward cursor back toward the originating page.
            'next_cursor' => $nextCursor,
            'previous_cursor' => $previousCursor,
            'results' => $results,
        ];

        if ($this->explain_requested($opts)) {
            $payload['explain'] = $page['explain'];
        }

        return $payload;
    }

    /** Require the one fixed relational diagnostic shape. */
    private function assert_set_oriented_explain(
        mixed $explain,
        array $queryGroups,
        array $opts,
        int $canonicalPageBytes
    ): void {
        $expectedKeys = [
            'storage',
            'logical_group_count',
            'resolved_alternatives',
            'anchor_group',
            'prefix_range',
            'prefix_strategy',
            'query_statements',
            'interactive_total',
            'recency_boost',
            'canonical_page_bytes',
        ];
        if (!is_array($explain) || array_keys($explain) !== $expectedKeys) {
            throw new LogicException('Relational storage returned an invalid explain shape.');
        }
        if ($explain['storage'] !== 'set_oriented') {
            throw new LogicException('Relational storage returned an invalid explain storage path.');
        }

        $logicalGroupCount = count($queryGroups);
        $alternativeCount = $this->query_group_term_count($queryGroups);
        if (!is_int($explain['logical_group_count']) || $explain['logical_group_count'] !== $logicalGroupCount) {
            throw new LogicException('Relational storage returned an invalid explain logical-group count.');
        }
        if (
            !is_int($explain['resolved_alternatives'])
            || $explain['resolved_alternatives'] < 0
            || $explain['resolved_alternatives'] > $alternativeCount
        ) {
            throw new LogicException('Relational storage returned an invalid explain alternative count.');
        }
        if (
            $explain['anchor_group'] !== null
            && (
                !is_int($explain['anchor_group'])
                || $explain['anchor_group'] < 0
                || $explain['anchor_group'] >= $logicalGroupCount
            )
        ) {
            throw new LogicException('Relational storage returned an invalid explain anchor group.');
        }
        if (!is_bool($explain['prefix_range'])) {
            throw new LogicException('Relational storage returned an invalid explain prefix flag.');
        }
        if (
            !is_string($explain['prefix_strategy'])
            || !in_array($explain['prefix_strategy'], ['none', 'surface_range', 'candidate_first'], true)
            || ($explain['prefix_strategy'] === 'none') !== ($explain['prefix_range'] === false)
        ) {
            throw new LogicException('Relational storage returned an invalid explain prefix strategy.');
        }
        if (
            !is_int($explain['query_statements'])
            || $explain['query_statements'] < 0
            || $explain['query_statements'] > WP_FTS_Set_Oriented_Search_Storage::MAX_QUERY_STATEMENTS
            || $explain['interactive_total'] !== 'unknown'
        ) {
            throw new LogicException('Relational storage returned invalid explain query diagnostics.');
        }

        $recency = $explain['recency_boost'];
        $expectedRecency = $this->recency_boost_config($opts);
        if (
            !is_array($recency)
            || array_keys($recency) !== ['enabled', 'strength', 'half_life_days', 'scoring_now_gmt']
            || !is_bool($recency['enabled'])
            || !is_float($recency['strength'])
            || !is_finite($recency['strength'])
            || !is_float($recency['half_life_days'])
            || !is_finite($recency['half_life_days'])
            || !is_string($recency['scoring_now_gmt'])
            || $recency['enabled'] !== $expectedRecency['enabled']
            || $recency['strength'] !== ($expectedRecency['enabled'] ? $expectedRecency['strength'] : 0.0)
            || $recency['half_life_days'] !== $expectedRecency['half_life_days']
            || (
                $recency['scoring_now_gmt'] !== ''
                && !$this->is_exact_gmt_datetime($recency['scoring_now_gmt'])
            )
            || (!$recency['enabled'] && $recency['scoring_now_gmt'] !== '')
        ) {
            throw new LogicException('Relational storage returned invalid explain recency diagnostics.');
        }
        if (
            !is_int($explain['canonical_page_bytes'])
            || $explain['canonical_page_bytes'] !== $canonicalPageBytes
        ) {
            throw new LogicException('Relational storage returned invalid explain canonical page bytes.');
        }
    }

    /**
     * Require one exact result and its requested relational sidecars.
     *
     * @return array{metadata:int,canonical:int}
     */
    private function assert_set_oriented_result_row(mixed $row, array $opts): array
    {
        if (!is_array($row)) {
            throw new LogicException('Relational storage result rows must be arrays.');
        }

        $expectedKeys = ['doc_id', 'score'];
        if ($opts['include_metadata'] ?? false) {
            array_push(
                $expectedKeys,
                'post_id',
                'post_type',
                'post_status',
                'post_date_gmt',
                'title',
                'excerpt',
                'primary_lang'
            );
        }
        if ($opts['include_snippets'] ?? false) {
            $expectedKeys[] = 'snippet_text';
            if (!in_array('primary_lang', $expectedKeys, true)) {
                $expectedKeys[] = 'primary_lang';
            }
        }
        if ($opts['_include_canonical_post_rows'] ?? false) {
            $expectedKeys[] = '_canonical_post_row';
        }
        if (array_keys($row) !== $expectedKeys) {
            throw new LogicException('Relational storage returned an invalid result row shape.');
        }
        if (!is_int($row['doc_id']) || $row['doc_id'] <= 0) {
            throw new LogicException('Relational storage document IDs must be native positive integers.');
        }
        if (!is_float($row['score']) || !is_finite($row['score'])) {
            throw new LogicException('Relational storage scores must be native finite floats.');
        }

        if (($opts['include_metadata'] ?? false) || ($opts['include_snippets'] ?? false)) {
            $this->set_oriented_output_language($row['primary_lang']);
        }

        $metadataBytes = 0;
        if ($opts['include_metadata'] ?? false) {
            if (!is_int($row['post_id']) || $row['post_id'] !== $row['doc_id']) {
                throw new LogicException('Relational storage post IDs must exactly match document IDs.');
            }
            foreach (['post_type', 'post_status'] as $key) {
                $this->assert_set_oriented_name_sidecar($row[$key], $key);
            }
            if (!is_string($row['post_date_gmt']) || !$this->is_wordpress_gmt_datetime($row['post_date_gmt'])) {
                throw new LogicException('Relational storage returned invalid post_date_gmt text.');
            }
            $this->assert_set_oriented_text_sidecar(
                $row['title'],
                'title',
                WP_FTS_Set_Oriented_Search_Storage::MAX_METADATA_TITLE_BYTES
            );
            $this->assert_set_oriented_text_sidecar($row['excerpt'], 'excerpt', null);
            $metadataBytes = strlen($row['post_type'])
                + strlen($row['post_status'])
                + strlen($row['post_date_gmt'])
                + strlen($row['title'])
                + strlen($row['excerpt'])
                + strlen($row['primary_lang']);
            if ($metadataBytes > WP_FTS_Set_Oriented_Search_Storage::MAX_SIDECAR_PAGE_BYTES) {
                throw new LogicException('Relational storage returned oversized metadata sidecars.');
            }
        }
        if ($opts['include_snippets'] ?? false) {
            $this->assert_set_oriented_text_sidecar(
                $row['snippet_text'],
                'snippet_text',
                WP_FTS_Set_Oriented_Search_Storage::MAX_SNIPPET_SOURCE_BYTES
            );
        }
        $canonicalBytes = ($opts['_include_canonical_post_rows'] ?? false)
            ? $this->set_oriented_canonical_row_bytes($row['_canonical_post_row'], $row['doc_id'])
            : 0;

        return ['metadata' => $metadataBytes, 'canonical' => $canonicalBytes];
    }

    /** Require one bounded native WordPress type or status value. */
    private function assert_set_oriented_name_sidecar(mixed $value, string $name): void
    {
        $this->assert_set_oriented_text_sidecar($value, $name, WP_FTS_Set_Oriented_Search_Storage::MAX_METADATA_NAME_BYTES);
        if ($value === '' || trim($value) !== $value) {
            throw new LogicException("Relational storage returned invalid {$name} text.");
        }
    }

    /** Allow an exact UTC datetime or WordPress' native zero-date sentinel. */
    private function is_wordpress_gmt_datetime(string $value): bool
    {
        return $value === '0000-00-00 00:00:00' || $this->is_exact_gmt_datetime($value);
    }

    /** Require a calendar-valid UTC datetime without normalizing it. */
    private function is_exact_gmt_datetime(string $value): bool
    {
        if (strlen($value) !== 19) {
            return false;
        }
        $timestamp = $this->parse_gmt_timestamp($value);

        return $timestamp !== null && gmdate('Y-m-d H:i:s', $timestamp) === $value;
    }

    /** Measure one bounded canonical row without knowing adapter-specific fields. */
    private function set_oriented_canonical_row_bytes(mixed $row, int $docId): int
    {
        if (
            !is_array($row)
            || $row === []
            || array_is_list($row)
            || count($row) > WP_FTS_Set_Oriented_Search_Storage::MAX_CANONICAL_FIELDS
            || array_key_first($row) !== 'ID'
            || !is_int($row['ID'])
            || $row['ID'] !== $docId
        ) {
            throw new LogicException('Relational storage returned an invalid canonical post sidecar.');
        }

        $bytes = 8;
        foreach ($row as $key => $value) {
            if (
                !is_string($key)
                || $key === ''
                || trim($key) !== $key
                || strlen($key) > WP_FTS_Set_Oriented_Search_Storage::MAX_CANONICAL_KEY_BYTES
                || preg_match('//u', $key) !== 1
            ) {
                throw new LogicException('Relational storage returned an invalid canonical post field name.');
            }
            $bytes += strlen($key);
            if ($key === 'ID') {
                continue;
            }
            if (!is_string($value) || preg_match('//u', $value) !== 1) {
                throw new LogicException('Relational storage returned invalid canonical post text.');
            }
            $bytes += strlen($value);
            if ($bytes > WP_FTS_Set_Oriented_Search_Storage::MAX_SIDECAR_PAGE_BYTES) {
                throw new LogicException('Relational storage returned an oversized canonical post sidecar.');
            }
        }

        return $bytes;
    }

    /** Require one native UTF-8 text sidecar without rewriting it. */
    private function assert_set_oriented_text_sidecar(
        mixed $value,
        string $name,
        ?int $maximumBytes
    ): void
    {
        if (
            !is_string($value)
            || ($maximumBytes !== null && strlen($value) > $maximumBytes)
            || preg_match('//u', $value) !== 1
        ) {
            throw new LogicException("Relational storage returned invalid {$name} text.");
        }
    }

    /** Require one canonical native language tag from relational storage. */
    private function set_oriented_output_language(mixed $language): string
    {
        if (!is_string($language) || strlen($language) > WP_FTS_Set_Oriented_Search_Storage::MAX_LANGUAGE_BYTES) {
            throw new LogicException('Relational storage languages must be bounded native strings.');
        }
        try {
            $canonical = WP_FTS_TermNamespace::parse_language_tag($language);
        } catch (Throwable $error) {
            throw new LogicException('Relational storage returned an invalid language tag.', 0, $error);
        }
        if ($canonical !== $language) {
            throw new LogicException('Relational storage languages must already be canonical.');
        }

        return $language;
    }

    /** Require one exact opaque cursor from relational storage. */
    private function set_oriented_output_cursor(mixed $cursor): ?string
    {
        if ($cursor === null) {
            return null;
        }
        if (
            !is_string($cursor)
            || $cursor === ''
            || trim($cursor) !== $cursor
            || strlen($cursor) > WP_FTS_Set_Oriented_Search_Storage::MAX_CURSOR_BYTES
        ) {
            throw new LogicException('Relational storage returned an invalid cursor.');
        }

        return $cursor;
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
                if (
                    ($opts['highlight'] ?? false)
                    && $row['title'] !== ''
                ) {
                    $row['highlighted_title'] = $this->highlight_analyzed_text(
                        $row['title'],
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
                $snippetLength = $opts['snippet_length'] ?? 180;
                $row['snippet'] = $this->snippet(
                    $row['snippet_text'],
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
        $length = max(
            WP_FTS_Set_Oriented_Search_Storage::MIN_SNIPPET_LENGTH,
            WP_FTS_Utf8::length(WP_FTS_Html_Text_Stream::visible_text($text)) + 1
        );

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
        if (strlen($source) > WP_FTS_Set_Oriented_Search_Storage::MAX_SNIPPET_SOURCE_BYTES) {
            $source = substr($source, 0, WP_FTS_Set_Oriented_Search_Storage::MAX_SNIPPET_SOURCE_BYTES);
        }

        return WP_FTS_Utf8::truncate_bytes(
            $source,
            WP_FTS_Set_Oriented_Search_Storage::MAX_SNIPPET_SOURCE_BYTES
        );
    }

    /** @return array{enabled:bool,strength:float,half_life_days:float,now_gmt:string} */
    private function recency_boost_config(array $opts): array
    {
        $strength = (float) ($opts['recency_boost_strength'] ?? 0.0);
        $halfLife = (float) ($opts['recency_boost_half_life_days'] ?? WP_FTS_Set_Oriented_Search_Storage::DEFAULT_RECENCY_BOOST_HALF_LIFE_DAYS);

        return [
            'enabled' => $strength > 0.0,
            'strength' => $strength,
            'half_life_days' => $halfLife,
            'now_gmt' => gmdate('Y-m-d H:i:s'),
        ];
    }

    private function parse_gmt_timestamp(string $value): ?int
    {
        $text = trim($value);
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
     * Project semantic query candidates to the only fields storage consumes.
     *
     * @param array<int,array<int,array{key:string,lang:string,term:string,rank:int,source?:string,surface?:string}>> $groups
     * @return array<int,array<int,array{key:string,rank:int}>>
     */
    private function storage_query_groups(array $groups): array
    {
        $storageGroups = [];
        foreach ($groups as $group) {
            $storageGroup = [];
            foreach ($group as $candidate) {
                $storageGroup[] = [
                    'key' => $candidate['key'],
                    'rank' => $candidate['rank'],
                ];
            }
            $storageGroups[] = $storageGroup;
        }

        return $storageGroups;
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
        return $opts['prefix_min_length'] ?? WP_FTS_Set_Oriented_Search_Storage::DEFAULT_PREFIX_LENGTH;
    }

    /**
     * Force analyzer options to a specific query language.
     *
     * @return array<string,mixed>
     */
    private function with_query_language(string $lang): array
    {
        return [
            'query_lang' => $lang,
            '_force_query_lang' => true,
        ];
    }

    /**
     * Convert analyzer occurrences into one-language alternatives.
     *
     * @param array<int,array<string,mixed>> $occurrences
     * @param string|null $authoritativeLang Language partition that overrides
     *        analyzer-selected languages for an explicit query language.
     * @return array<int,array<int,array{key:string,lang:string,term:string,rank:int,source?:string,surface?:string}>>
     */
    private function groups_from_occurrences(array $occurrences, ?string $authoritativeLang = null): array
    {
        $groups = [];
        $groupByPosition = [];
        foreach ($occurrences as $occurrence) {
            $candidate = $this->candidate_from_occurrence($occurrence, $authoritativeLang);
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
    private function occurrence_position(array $occurrence): ?int
    {
        if (!array_key_exists('position', $occurrence)) {
            return null;
        }

        return $occurrence['position'];
    }

    /**
     * Normalize one analyzer occurrence into a stored term-key candidate.
     *
     * @param array<string,mixed> $occurrence
     * @param string|null $authoritativeLang Language partition that must own the
     *        candidate regardless of analyzer-selected language.
     * @return array{key:string,lang:string,term:string,rank:int,source:string,surface?:string}|null
     */
    private function candidate_from_occurrence(array $occurrence, ?string $authoritativeLang = null): ?array
    {
        $term = $occurrence['term'];
        if ($term === '') {
            return null;
        }

        $lang = $authoritativeLang ?? $occurrence['lang'];

        if (!WP_FTS_TermNamespace::term_key_fits($term, $lang)) {
            throw new WP_FTS_Search_Budget_Exceeded('analyzer occurrence bytes');
        }

        $occurrenceRank = $occurrence['rank'] ?? 0;

        $source = $occurrenceRank > 0 ? 'secondary_lemma' : 'exact';

        $candidate = [
            'key' => WP_FTS_TermNamespace::namespace_term($lang, $term),
            'lang' => $lang,
            'term' => $term,
            'rank' => $occurrenceRank,
            'source' => $source,
        ];
        if (isset($occurrence['surface'])) {
            $candidate['surface'] = $occurrence['surface'];
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
        $this->assert_snippet_option_keys($opts);
        $this->assert_snippet_input($query, $opts);
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
            $explicitQueryLang
        ));
        $this->assert_set_oriented_query_groups($groups);
        $authoritativePrefixes = [];
        if ($groups !== []) {
            $prefixSurface = $this->set_oriented_prefix_surface(
                $queryOccurrences,
                $groups[array_key_last($groups)],
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
            $opts['snippet_length'] ?? 180,
            $opts['highlight'] ?? false,
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
        if (array_key_exists('primary_lang', $row)) {
            return $this->set_oriented_output_language($row['primary_lang']);
        }

        return WP_FTS_TermNamespace::language_from_options($opts, null, ['query_lang'])
            ?? $this->set_oriented_output_language($queryLang);
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
                $keys[$candidate['key']] = true;
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
            if (WP_FTS_Utf8::length($prefix['term']) >= $minimum) {
                $prefixes[$prefix['lang'] . "\0" . $prefix['term']] = $prefix;
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
            $languages[$candidate] = true;
        }

        foreach ($queryGroups as $group) {
            foreach ($group as $candidate) {
                $languages[$candidate['lang']] = true;
            }
        }

        return array_slice(array_keys($languages), 0, self::MAX_SNIPPET_ANALYSIS_LANGUAGES);
    }

    /**
     * Analyze one bounded presentation window per relevant language.
     *
     * Invoking the analyzer once for every distinct token would let a 20-row
     * page containing adversarial 20-KiB snippets make
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
            $analysisOpts = $this->with_query_language($lang);
            $analysisOpts['_include_query_surface'] = true;
            $analysisOpts['_max_query_occurrences'] = self::MAX_SNIPPET_ANALYSIS_OCCURRENCES;
            try {
                $occurrences = $this->analyze_query($text, $analysisOpts);
                foreach ($occurrences as $occurrence) {
                    if (!array_key_exists('surface', $occurrence)) {
                        continue;
                    }
                    $candidate = $this->candidate_from_occurrence($occurrence, $lang);
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
                        $surfaces[$this->normalize_snippet_surface($occurrence['surface'])] = true;
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
            ? $analysisOpts['_max_query_occurrences']
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
     * @param array<string,mixed> $opts Search or internal snippet options.
     * @return array<string,mixed> Options passed to analyzer methods.
     */
    private function query_analysis_options(array $opts): array
    {
        $analysisOpts = [];
        $explicitLang = WP_FTS_TermNamespace::language_from_options($opts, null, ['query_lang']);
        $defaultLang = WP_FTS_TermNamespace::language_from_options($opts, null, ['default_lang']);
        if ($explicitLang !== null) {
            $analysisOpts['query_lang'] = $explicitLang;
        } elseif ($defaultLang !== null) {
            $analysisOpts['_default_query_lang'] = $defaultLang;
        }

        foreach (['_force_query_lang', '_include_query_surface'] as $key) {
            if (array_key_exists($key, $opts)) {
                if (!is_bool($opts[$key])) {
                    throw new InvalidArgumentException("Analyzer {$key} must be a boolean.");
                }
                $analysisOpts[$key] = $opts[$key];
            }
        }
        if (array_key_exists('_max_query_occurrences', $opts)) {
            if (!is_int($opts['_max_query_occurrences']) || $opts['_max_query_occurrences'] <= 0) {
                throw new InvalidArgumentException('Analyzer _max_query_occurrences must be a positive integer.');
            }
            $analysisOpts['_max_query_occurrences'] = $opts['_max_query_occurrences'];
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
        if (!is_array($analysis) || !array_is_list($analysis)) {
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
            WP_FTS_Analyzer_Occurrence_Validator::assert_query($occurrence);
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
        if (!is_array($analysis) || !array_is_list($analysis)) {
            throw new InvalidArgumentException('Analyzer output must be an array of occurrences.');
        }
        if (count($analysis) > WP_FTS_Set_Oriented_Search_Storage::MAX_QUERY_ALTERNATIVES) {
            throw new WP_FTS_Search_Budget_Exceeded('analyzer occurrences');
        }

        foreach ($analysis as $occurrence) {
            WP_FTS_Analyzer_Occurrence_Validator::assert_query($occurrence);
        }

        return array_values($analysis);
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
            return $occurrence['lang'];
        }

        return WP_FTS_TermNamespace::default_language($opts);
    }
}
