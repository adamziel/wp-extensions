<?php
declare(strict_types=1);

/**
 * Deterministic provider-compatibility evidence contracts.
 *
 * When executed directly, this file re-enters the shared harness with a focused
 * test filter. When discovered by tests/run.php, it registers provider-specific
 * quality tests alongside the normal suite.
 */

function wp_fts_provider_compatibility_contract_direct(): int
{
    if (!function_exists('proc_open')) {
        fwrite(STDOUT, "SKIP: proc_open() is unavailable, so the focused provider compatibility contract cannot launch tests/run.php.\n");
        return 0;
    }

    $root = dirname(__DIR__, 2);
    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $baseEnv = getenv();
    if (!is_array($baseEnv)) {
        $baseEnv = [];
    }

    $command = [PHP_BINARY];
    if (php_ini_loaded_file() === false) {
        $command[] = '-n';
    }
    $command[] = $root . '/tests/run.php';

    $process = proc_open(
        $command,
        $descriptors,
        $pipes,
        $root,
        array_merge($baseEnv, [
            'WP_FTS_TEST_FILTER' => 'provider compatibility certification',
            'WP_FTS_MIN_CHECKS' => '0',
        ])
    );
    if (!is_resource($process)) {
        fwrite(STDERR, "FAIL: Could not launch the focused provider compatibility contract.\n");
        return 1;
    }

    fclose($pipes[0]);
    $stdout = (string) stream_get_contents($pipes[1]);
    $stderr = (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exit = proc_close($process);

    if ($stdout !== '') {
        fwrite(STDOUT, $stdout);
    }
    if ($stderr !== '') {
        fwrite(STDERR, $stderr);
    }

    return is_int($exit) ? $exit : 1;
}

if (!function_exists('test_case')) {
    exit(wp_fts_provider_compatibility_contract_direct());
}

require_once dirname(__DIR__) . '/integration/provider-compatibility-wordpress.php';

if (!function_exists('wp_fts_provider_certification_post_ids')) {
    /**
     * @return int[]
     */
    function wp_fts_provider_certification_post_ids(mixed $posts): array
    {
        if (!is_array($posts)) {
            return [];
        }

        return array_values(array_map(
            static fn(mixed $post): int => is_object($post) && isset($post->ID) ? (int) $post->ID : 0,
            $posts
        ));
    }
}

if (!function_exists('wp_fts_provider_certification_enable_debug')) {
    function wp_fts_provider_certification_enable_debug(): void
    {
        $GLOBALS['wp_fts_test_filters'][WP_FTS_Plugin::DEBUG_ENABLED_FILTER] = static fn(mixed $enabled, string $context): bool => true;
    }
}

if (!function_exists('wp_fts_provider_certification_settings')) {
    /**
     * @param array<string,mixed> $overrides
     * @return array<string,mixed>
     */
    function wp_fts_provider_certification_settings(array $overrides = []): array
    {
        return array_replace(WP_FTS_Plugin::default_settings(), $overrides);
    }
}

if (!function_exists('wp_fts_provider_certification_index_post')) {
    function wp_fts_provider_certification_index_post(int $post_id, string $needle, string $title = ''): object
    {
        $post = (object) [
            'ID' => $post_id,
            'post_title' => $title !== '' ? $title : "Provider certification {$post_id}",
            'post_content' => "<p>{$needle} appears in indexed provider certification content.</p>",
            'post_excerpt' => '',
            'post_status' => 'publish',
            'post_type' => 'post',
            'post_date_gmt' => '2026-06-22 00:00:00',
        ];
        $GLOBALS['wp_fts_test_posts'][$post_id] = $post;
        wp_fts_test_index_saved_post($post_id, $post, true);

        return $post;
    }
}

if (!function_exists('wp_fts_provider_certification_trace_json')) {
    function wp_fts_provider_certification_trace_json(array $trace): string
    {
        return json_encode($trace, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }
}

if (!function_exists('wp_fts_provider_certification_assert_redacted')) {
    /**
     * @param string[] $forbidden
     */
    function wp_fts_provider_certification_assert_redacted(string $json, array $forbidden, string $context): void
    {
        foreach ($forbidden as $needle) {
            assert_true(!str_contains($json, $needle), "{$context} should not expose {$needle}");
        }
    }
}

if (!function_exists('wp_fts_provider_certification_theme_posts_pre_query')) {
    function wp_fts_provider_certification_theme_posts_pre_query(mixed $posts, mixed $query = null): mixed
    {
        return $posts;
    }
}

if (!function_exists('wp_fts_provider_certification_run_process')) {
    /**
     * @param array<int,string> $command
     * @param array<string,string> $env
     * @return array{exit:int,stdout:string,stderr:string}
     */
    function wp_fts_provider_certification_run_process(array $command, array $env = []): array
    {
        if (!function_exists('proc_open')) {
            mark_pending('proc_open() is unavailable, so the provider compatibility smoke skip contract cannot launch a subprocess.');
        }

        $baseEnv = getenv();
        if (!is_array($baseEnv)) {
            $baseEnv = [];
        }
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $process = proc_open($command, $descriptors, $pipes, dirname(__DIR__, 2), array_merge($baseEnv, $env));
        if (!is_resource($process)) {
            mark_pending('Could not start the provider compatibility smoke subprocess.');
        }

        fclose($pipes[0]);
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($process);

        return [
            'exit' => is_int($exit) ? $exit : 1,
            'stdout' => $stdout,
            'stderr' => $stderr,
        ];
    }
}

test_case('provider compatibility certification respects earlier providers in coexistence mode', function (): void {
    global $wpdb;

    $oldWpdb = $wpdb ?? null;
    $wpdb = new WP_FTS_Test_WPDB();
    wp_fts_test_reset_wordpress_fakes();
    wp_fts_provider_certification_enable_debug();
    $GLOBALS['wp_fts_test_options'][WP_FTS_Plugin::SETTINGS_OPTION] = wp_fts_provider_certification_settings([
        'search_provider_compatibility' => 'respect_existing',
    ]);

    $earlier = [
        (object) [
            'ID' => 901,
            'post_title' => 'private-provider-title .env ssh-rsa BEGIN PRIVATE KEY',
            'provider_payload' => [
                'secret' => 'provider-secret-must-not-leak',
                'pem' => '-----BEGIN PRIVATE KEY-----',
                'ssh' => '~/.ssh/id_rsa',
            ],
        ],
        (object) [
            'ID' => 902,
            'post_title' => 'second-private-provider-title',
            'provider_payload' => ['raw' => 'raw-provider-payload-must-not-leak'],
        ],
    ];

    try {
        wp_fts_test_set_posts_pre_query_filter_pipeline([
            20 => [
                'generic_earlier_provider' => [
                    'function' => static fn(mixed $posts, mixed $query = null): array => $earlier,
                    'accepted_args' => 2,
                ],
            ],
            WP_FTS_Plugin::SEARCH_REPLACEMENT_PRIORITY => [
                'language_fts' => [
                    'function' => [WP_FTS_Plugin::class, 'replace_frontend_search_posts'],
                    'accepted_args' => 2,
                ],
            ],
            WP_FTS_Plugin::SEARCH_FINAL_OWNERSHIP_OBSERVER_PRIORITY => [
                'final_observer' => [
                    'function' => [WP_FTS_Plugin::class, 'observe_final_search_posts'],
                    'accepted_args' => 2,
                ],
            ],
        ]);

        $query = new WP_FTS_Test_Query([
            's' => 'providercertstanddownneedle',
            'posts_per_page' => 10,
        ]);
        $posts = apply_filters('posts_pre_query', null, $query);
        assert_same([901, 902], wp_fts_provider_certification_post_ids($posts), 'coexistence mode should return the earlier provider result unchanged');

        $trace = WP_FTS_Plugin::debug_traces()[0] ?? [];
        assert_same('bailed', $trace['status'] ?? null, 'coexistence trace should report a stand-down bailout');
        $counts = is_array($trace['counts'] ?? null) ? $trace['counts'] : [];
        assert_same(2, (int) ($counts['incoming_provider_results'] ?? 0), 'coexistence diagnostics should count incoming provider results');
        assert_same(0, (int) ($counts['prior_provider_responses_replaced'] ?? 0), 'coexistence diagnostics should not claim a replacement');
        $ownership = is_array($trace['search_final_ownership'] ?? null) ? $trace['search_final_ownership'] : [];
        assert_same('earlier_provider_respected', $ownership['status'] ?? null, 'final ownership should report the respected earlier provider');
        assert_same('earlier_provider', $ownership['owner'] ?? null, 'final ownership should attribute the result to the earlier provider');
        assert_same(true, $ownership['observed'] ?? null, 'final ownership should record that the observer ran');
        assert_same(2, (int) ($ownership['expected_count'] ?? 0), 'final ownership should expose bounded expected count evidence');
        assert_same(2, (int) ($ownership['final_count'] ?? 0), 'final ownership should expose bounded final count evidence');
        assert_same([901, 902], $ownership['expected_post_ids'] ?? null, 'final ownership should expose bounded expected post IDs');
        assert_same([901, 902], $ownership['final_post_ids'] ?? null, 'final ownership should expose bounded final post IDs');

        $pipeline = is_array($trace['search_hook_pipeline'] ?? null) ? $trace['search_hook_pipeline'] : [];
        $pipelineCounts = is_array($pipeline['counts'] ?? null) ? $pipeline['counts'] : [];
        assert_same(1, (int) ($pipelineCounts['before'] ?? 0), 'hook pipeline should count the earlier provider before Language FTS');
        assert_same(1, (int) ($pipelineCounts['same_priority'] ?? 0), 'hook pipeline should count Language FTS at its replacement priority');

        wp_fts_provider_certification_assert_redacted(
            wp_fts_provider_certification_trace_json($trace),
            [
                'private-provider-title',
                'second-private-provider-title',
                'provider_payload',
                'provider-secret-must-not-leak',
                'raw-provider-payload-must-not-leak',
                '.env',
                '~/.ssh',
                'BEGIN PRIVATE KEY',
            ],
            'coexistence diagnostics'
        );
    } finally {
        $wpdb = $oldWpdb;
    }
});

test_case('provider compatibility certification lets FTS replace earlier providers in prefer mode', function (): void {
    global $wpdb;

    $oldWpdb = $wpdb ?? null;
    $wpdb = new WP_FTS_Test_WPDB();
    wp_fts_test_reset_wordpress_fakes();
    wp_fts_provider_certification_enable_debug();
    $GLOBALS['wp_fts_test_options'][WP_FTS_Plugin::SETTINGS_OPTION] = wp_fts_provider_certification_settings([
        'search_provider_compatibility' => 'prefer_fts',
    ]);

    try {
        wp_fts_provider_certification_index_post(912, 'providercertreplaceneedle', 'FTS replacement fixture');
        $earlier = [
            (object) ['ID' => 910, 'post_title' => 'earlier-provider-title-a'],
            (object) ['ID' => 911, 'post_title' => 'earlier-provider-title-b', 'provider_payload' => ['secret' => 'earlier-provider-secret']],
        ];
        wp_fts_test_set_posts_pre_query_filter_pipeline([
            20 => [
                'generic_earlier_provider' => [
                    'function' => static fn(mixed $posts, mixed $query = null): array => $earlier,
                    'accepted_args' => 2,
                ],
            ],
            WP_FTS_Plugin::SEARCH_REPLACEMENT_PRIORITY => [
                'language_fts' => [
                    'function' => [WP_FTS_Plugin::class, 'replace_frontend_search_posts'],
                    'accepted_args' => 2,
                ],
            ],
            1200 => [
                'later_no_result_provider' => [
                    'function' => static fn(mixed $posts, mixed $query = null): mixed => $posts,
                    'accepted_args' => 2,
                ],
            ],
            WP_FTS_Plugin::SEARCH_FINAL_OWNERSHIP_OBSERVER_PRIORITY => [
                'final_observer' => [
                    'function' => [WP_FTS_Plugin::class, 'observe_final_search_posts'],
                    'accepted_args' => 2,
                ],
            ],
        ]);

        $query = new WP_FTS_Test_Query([
            's' => 'providercertreplaceneedle',
            'posts_per_page' => 10,
        ]);
        $posts = apply_filters('posts_pre_query', null, $query);
        assert_same([912], wp_fts_provider_certification_post_ids($posts), 'prefer mode should replace earlier provider posts with FTS posts');

        $trace = WP_FTS_Plugin::debug_traces()[0] ?? [];
        assert_same('ran', $trace['status'] ?? null, 'prefer-mode replacement trace should report a completed FTS run');
        $counts = is_array($trace['counts'] ?? null) ? $trace['counts'] : [];
        assert_same(2, (int) ($counts['incoming_provider_results'] ?? 0), 'prefer-mode diagnostics should count incoming provider results');
        assert_same(1, (int) ($counts['prior_provider_responses_replaced'] ?? 0), 'prefer-mode diagnostics should count the replaced provider response');
        $ownership = is_array($trace['search_final_ownership'] ?? null) ? $trace['search_final_ownership'] : [];
        assert_same('language_fts_survived', $ownership['status'] ?? null, 'final ownership should report FTS survival after a no-result later callback');
        assert_same('language_fts', $ownership['owner'] ?? null, 'final ownership should attribute the surviving result to FTS');
        assert_same('language_fts_replaced_prior_provider', $ownership['origin'] ?? null, 'final ownership should remember that FTS replaced a prior provider');
        assert_same(1, (int) ($ownership['expected_count'] ?? 0), 'FTS final ownership should expose expected result count');
        assert_same(1, (int) ($ownership['final_count'] ?? 0), 'FTS final ownership should expose final result count');
        assert_same([912], $ownership['expected_post_ids'] ?? null, 'FTS final ownership should expose bounded expected IDs');
        assert_same([912], $ownership['final_post_ids'] ?? null, 'FTS final ownership should expose bounded final IDs');
        assert_true(is_string($ownership['expected_hash'] ?? null) && strlen((string) $ownership['expected_hash']) === 16, 'FTS final ownership should expose a compact expected hash');
        assert_true(is_string($ownership['final_hash'] ?? null) && strlen((string) $ownership['final_hash']) === 16, 'FTS final ownership should expose a compact final hash');

        wp_fts_provider_certification_assert_redacted(
            wp_fts_provider_certification_trace_json($trace),
            ['earlier-provider-title-a', 'earlier-provider-title-b', 'provider_payload', 'earlier-provider-secret'],
            'prefer-mode diagnostics'
        );
    } finally {
        $wpdb = $oldWpdb;
    }
});

test_case('provider compatibility certification reports later provider changes without payload leakage', function (): void {
    global $wpdb;

    $oldWpdb = $wpdb ?? null;
    $wpdb = new WP_FTS_Test_WPDB();
    wp_fts_test_reset_wordpress_fakes();
    wp_fts_provider_certification_enable_debug();

    try {
        wp_fts_provider_certification_index_post(921, 'providercertlaterchangeneedle', 'FTS later change fixture');
        $later = [
            (object) [
                'ID' => 922,
                'post_title' => 'later-provider-title-a',
                'provider_payload' => ['token' => 'later-provider-secret-a'],
            ],
            (object) [
                'ID' => 923,
                'post_title' => 'later-provider-title-b',
                'provider_payload' => ['token' => '-----BEGIN RSA PRIVATE KEY-----'],
            ],
        ];
        wp_fts_test_set_posts_pre_query_filter_pipeline([
            WP_FTS_Plugin::SEARCH_REPLACEMENT_PRIORITY => [
                'language_fts' => [
                    'function' => [WP_FTS_Plugin::class, 'replace_frontend_search_posts'],
                    'accepted_args' => 2,
                ],
            ],
            1200 => [
                'generic_later_provider' => [
                    'function' => static fn(mixed $posts, mixed $query = null): array => $later,
                    'accepted_args' => 2,
                ],
            ],
            WP_FTS_Plugin::SEARCH_FINAL_OWNERSHIP_OBSERVER_PRIORITY => [
                'final_observer' => [
                    'function' => [WP_FTS_Plugin::class, 'observe_final_search_posts'],
                    'accepted_args' => 2,
                ],
            ],
        ]);

        $query = new WP_FTS_Test_Query([
            's' => 'providercertlaterchangeneedle',
            'posts_per_page' => 10,
        ]);
        $posts = apply_filters('posts_pre_query', null, $query);
        assert_same([922, 923], wp_fts_provider_certification_post_ids($posts), 'a later provider should remain able to change the final posts_pre_query result');

        $trace = WP_FTS_Plugin::debug_traces()[0] ?? [];
        $ownership = is_array($trace['search_final_ownership'] ?? null) ? $trace['search_final_ownership'] : [];
        assert_same('later_provider_changed_fts', $ownership['status'] ?? null, 'final ownership should report the later provider change');
        assert_same('later_provider', $ownership['owner'] ?? null, 'final ownership should attribute changed results to a later provider');
        assert_same(true, $ownership['observed'] ?? null, 'final ownership should record the observer run');
        assert_same([921], $ownership['expected_post_ids'] ?? null, 'later-change diagnostics should expose expected FTS IDs only');
        assert_same([922, 923], $ownership['final_post_ids'] ?? null, 'later-change diagnostics should expose final provider IDs only');
        assert_same(1, (int) ($ownership['expected_count'] ?? 0), 'later-change diagnostics should expose expected count');
        assert_same(2, (int) ($ownership['final_count'] ?? 0), 'later-change diagnostics should expose final count');

        wp_fts_provider_certification_assert_redacted(
            wp_fts_provider_certification_trace_json($trace),
            ['later-provider-title-a', 'later-provider-title-b', 'provider_payload', 'later-provider-secret-a', 'BEGIN RSA PRIVATE KEY'],
            'later-provider final ownership diagnostics'
        );
    } finally {
        $wpdb = $oldWpdb;
    }
});

test_case('provider compatibility certification bounds known labels and keeps custom callbacks generic', function (): void {
    wp_fts_test_reset_wordpress_fakes();
    $GLOBALS['wp_fts_test_options']['active_plugins'] = [
        'jetpack/jetpack.php',
        'searchwp/index.php',
        'relevanssi/relevanssi.php',
        'elasticpress/elasticpress.php',
        'private-search-provider/secret-basename.php',
    ];
    $GLOBALS['wp_fts_test_options']['jetpack_active_modules'] = [
        'search',
        'raw-provider-option-payload-must-not-leak',
    ];

    $advisoryMethod = new ReflectionMethod(WP_FTS_Plugin::class, 'known_search_provider_advisory');
    $advisoryMethod->setAccessible(true);
    $advisory = $advisoryMethod->invoke(null, wp_fts_provider_certification_settings());
    $names = is_array($advisory['provider_names'] ?? null) ? $advisory['provider_names'] : [];
    assert_same(
        ['Jetpack Search / Jetpack', 'SearchWP', 'Relevanssi', 'ElasticPress'],
        $names,
        'known-provider advisory should expose only bounded certified family labels'
    );
    assert_same(4, (int) ($advisory['detected_count'] ?? 0), 'known-provider advisory should ignore unknown active plugin basenames');
    wp_fts_provider_certification_assert_redacted(
        json_encode($advisory, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        [
            'jetpack/jetpack.php',
            'searchwp/index.php',
            'relevanssi/relevanssi.php',
            'elasticpress/elasticpress.php',
            'secret-basename.php',
            'raw-provider-option-payload-must-not-leak',
        ],
        'known-provider advisory'
    );

    wp_fts_test_reset_wordpress_fakes();
    wp_fts_provider_certification_enable_debug();
    $providerObject = new class {
        public function __invoke(mixed $posts, mixed $query = null): mixed
        {
            return $posts;
        }
    };
    wp_fts_test_set_posts_pre_query_hook_state([
        10 => [
            'theme_function' => [
                'function' => 'wp_fts_provider_certification_theme_posts_pre_query',
                'accepted_args' => 2,
            ],
            'theme_closure' => [
                'function' => static fn(mixed $posts, mixed $query = null): mixed => $posts,
                'accepted_args' => 2,
            ],
            'custom_invokable' => [
                'function' => $providerObject,
                'accepted_args' => 2,
            ],
        ],
        1200 => [
            'searchwp_shaped_callback_without_provider_signal' => [
                'function' => 'SearchWP\\Shadow\\Provider::posts_pre_query',
                'accepted_args' => 2,
            ],
        ],
    ]);

    $advisoryWithoutSignals = $advisoryMethod->invoke(null, wp_fts_provider_certification_settings());
    assert_same([], $advisoryWithoutSignals['provider_names'] ?? null, 'callback names alone should not certify known provider families');

    $GLOBALS['wp_fts_test_options'][WP_FTS_Plugin::SETTINGS_OPTION] = wp_fts_provider_certification_settings([
        'search_provider_compatibility' => 'respect_existing',
    ]);
    $incoming = [(object) ['ID' => 931, 'post_title' => 'custom-callback-provider-title']];
    $query = new WP_FTS_Test_Query([
        's' => 'providercertgenericlabelneedle',
        'posts_per_page' => 10,
    ]);
    WP_FTS_Plugin::replace_frontend_search_posts($incoming, $query);
    $trace = WP_FTS_Plugin::debug_traces()[0] ?? [];
    $settings = is_array($trace['settings'] ?? null) ? $trace['settings'] : [];
    assert_same('none', $settings['known_search_providers'] ?? null, 'diagnostics should not promote custom callbacks to known-provider families');
    assert_same(0, (int) ($settings['known_search_provider_count'] ?? -1), 'diagnostics should keep custom callback provider count at zero');
    $pipelineJson = json_encode($trace['search_hook_pipeline'] ?? [], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    assert_contains('function: wp_fts_provider_certification_theme_posts_pre_query', $pipelineJson, 'theme callbacks should remain generic bounded hook labels');
    assert_contains('closure', $pipelineJson, 'closure callbacks should remain generic bounded hook labels');
    assert_contains('method: anonymous class::__invoke', $pipelineJson, 'invokable custom callbacks should hide source locations');
    wp_fts_provider_certification_assert_redacted(
        wp_fts_provider_certification_trace_json($trace),
        ['custom-callback-provider-title', 'secret-basename.php', 'raw-provider-option-payload-must-not-leak'],
        'generic callback diagnostics'
    );
});

test_case('provider compatibility certification exposes a bounded provider interference matrix contract', function (): void {
    $definitions = wp_fts_provider_compatibility_wordpress_matrix_definitions();
    $scenarioIds = array_map(
        static fn(array $scenario): string => (string) ($scenario['scenario_id'] ?? ''),
        $definitions
    );

    assert_same(
        [
            'theme_custom_earlier_respect_existing',
            'searchwp_shaped_earlier_prefer_fts',
            'relevanssi_shaped_later_provider',
            'jetpack_elasticpress_advisory_signals',
        ],
        $scenarioIds,
        'provider interference matrix should expose stable scenario IDs'
    );

    $encodedDefinitions = json_encode($definitions, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    foreach ([
        'Theme/custom',
        'SearchWP',
        'Relevanssi',
        'Jetpack Search / Jetpack',
        'ElasticPress',
        'respect_existing',
        'prefer_fts',
        'repo-owned SearchWP-shaped posts_pre_query callback',
        'repo-owned Relevanssi-shaped later posts_pre_query callback',
        'bounded active plugin signal',
        'Jetpack active module option signal',
    ] as $needle) {
        assert_contains($needle, $encodedDefinitions, "provider interference matrix definitions should include {$needle}");
    }

    wp_fts_provider_certification_assert_redacted(
        $encodedDefinitions,
        [
            'jetpack/jetpack.php',
            'elasticpress/elasticpress.php',
            'secret-basename.php',
            'raw-provider-option-payload-must-not-leak',
            'provider_payload',
            '.env',
            '~/.ssh',
            'BEGIN PRIVATE KEY',
        ],
        'provider interference matrix definitions'
    );
});

test_case('provider compatibility certification matrix evidence is structured and redacted', function (): void {
    $searchwp = wp_fts_provider_compatibility_wordpress_build_scenario_evidence(
        [
            'scenario_id' => 'searchwp_shaped_earlier_prefer_fts',
            'simulated_provider_family_labels' => ['SearchWP'],
            'simulated_signal_labels' => ['repo-owned SearchWP-shaped posts_pre_query callback'],
            'compatibility_mode' => 'prefer_fts',
        ],
        [(object) ['ID' => 912, 'post_title' => 'FTS result title']],
        [
            'status' => 'ran',
            'counts' => [
                'incoming_provider_results' => 2,
                'prior_provider_responses_replaced' => 1,
            ],
            'settings' => [
                'known_search_providers' => 'none',
                'known_search_provider_count' => 0,
                'raw_provider_payload' => 'provider-secret-must-not-leak',
            ],
            'search_final_ownership' => [
                'status' => 'language_fts_survived',
                'owner' => 'language_fts',
                'origin' => 'language_fts_replaced_prior_provider',
                'expected_count' => 1,
                'final_count' => 1,
                'expected_post_ids' => [912],
                'final_post_ids' => [912],
                'expected_hash' => '1234567890abcdef',
                'final_hash' => '1234567890abcdef',
                'raw' => '-----BEGIN PRIVATE KEY-----',
            ],
        ]
    );

    assert_same(true, $searchwp['passed'] ?? null, 'SearchWP-shaped matrix evidence fixture should pass');
    assert_same('searchwp_shaped_earlier_prefer_fts', $searchwp['scenario_id'] ?? null, 'matrix evidence should keep the scenario ID');
    assert_same(['SearchWP'], $searchwp['simulated_provider_family_labels'] ?? null, 'matrix evidence should expose bounded simulated family labels');
    assert_same('prefer_fts', $searchwp['compatibility_mode'] ?? null, 'matrix evidence should expose compatibility mode');
    $trace = is_array($searchwp['trace'] ?? null) ? $searchwp['trace'] : [];
    assert_same(2, (int) ($trace['incoming_provider_results'] ?? 0), 'matrix evidence should expose incoming provider result count');
    assert_same(1, (int) ($trace['prior_provider_responses_replaced'] ?? 0), 'matrix evidence should expose prior replacement count');
    $ownership = is_array($searchwp['final_ownership'] ?? null) ? $searchwp['final_ownership'] : [];
    assert_same('language_fts_survived', $ownership['status'] ?? null, 'matrix evidence should expose final ownership status');
    assert_same([912], $ownership['final_post_ids'] ?? null, 'matrix evidence should expose bounded final IDs');

    wp_fts_provider_certification_assert_redacted(
        json_encode($searchwp, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        [
            'FTS result title',
            'raw_provider_payload',
            'provider-secret-must-not-leak',
            'BEGIN PRIVATE KEY',
        ],
        'SearchWP-shaped matrix evidence'
    );

    $advisory = wp_fts_provider_compatibility_wordpress_build_scenario_evidence(
        [
            'scenario_id' => 'jetpack_elasticpress_advisory_signals',
            'simulated_provider_family_labels' => ['Jetpack Search / Jetpack', 'ElasticPress'],
            'simulated_signal_labels' => ['bounded active plugin signal', 'Jetpack active module option signal'],
            'compatibility_mode' => 'prefer_fts',
        ],
        [(object) ['ID' => 913, 'post_title' => 'advisory result title']],
        [
            'status' => 'ran',
            'counts' => [
                'incoming_provider_results' => 0,
                'prior_provider_responses_replaced' => 0,
            ],
            'settings' => [
                'known_search_providers' => 'Jetpack Search / Jetpack, ElasticPress',
                'known_search_provider_count' => 2,
                'active_plugins' => ['jetpack/jetpack.php', 'elasticpress/elasticpress.php'],
            ],
            'search_final_ownership' => [
                'status' => 'language_fts_survived',
                'owner' => 'language_fts',
                'origin' => 'language_fts',
                'expected_count' => 1,
                'final_count' => 1,
                'expected_post_ids' => [913],
                'final_post_ids' => [913],
                'expected_hash' => 'abcdef1234567890',
                'final_hash' => 'abcdef1234567890',
            ],
        ]
    );

    assert_same(true, $advisory['passed'] ?? null, 'Jetpack/ElasticPress advisory matrix evidence fixture should pass');
    $advisoryTrace = is_array($advisory['trace'] ?? null) ? $advisory['trace'] : [];
    assert_same(
        ['Jetpack Search / Jetpack', 'ElasticPress'],
        $advisoryTrace['known_provider_family_labels'] ?? null,
        'advisory matrix evidence should expose bounded known-provider labels'
    );
    assert_same(2, (int) ($advisoryTrace['known_provider_family_count'] ?? 0), 'advisory matrix evidence should expose bounded known-provider count');
    wp_fts_provider_certification_assert_redacted(
        json_encode($advisory, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        ['jetpack/jetpack.php', 'elasticpress/elasticpress.php', 'advisory result title'],
        'Jetpack/ElasticPress matrix evidence'
    );
});

test_case('provider compatibility certification smoke and documentation are discoverable', function (): void {
    $root = dirname(__DIR__, 2);
    $doc = (string) file_get_contents($root . '/docs/provider-compatibility.md');
    $testing = (string) file_get_contents($root . '/docs/testing.md');
    $composer = json_decode((string) file_get_contents($root . '/composer.json'), true);

    assert_true(is_array($composer), 'composer.json should decode for provider compatibility script checks');
    assert_same(
        'php tests/quality/provider-compatibility-certification-contracts.php',
        $composer['scripts']['test:provider-compatibility'] ?? null,
        'composer should expose the deterministic provider compatibility contract'
    );
    assert_same(
        'php tools/smoke-search-provider-compatibility.php',
        $composer['scripts']['test:smoke:provider-compatibility'] ?? null,
        'composer should expose the optional provider compatibility smoke'
    );
    assert_contains('php tests/quality/provider-compatibility-certification-contracts.php', $doc, 'provider compatibility docs should document the deterministic contract command');
    assert_contains('php tools/smoke-search-provider-compatibility.php', $doc, 'provider compatibility docs should document the smoke command');
    assert_contains('Jetpack Search / Jetpack, SearchWP, Relevanssi, and ElasticPress', $doc, 'provider compatibility docs should name bounded advisory labels');
    assert_contains('Provider Interference Matrix', $doc, 'provider compatibility docs should name the matrix');
    assert_contains('repo-owned provider-family simulations', $doc, 'provider compatibility docs should explain simulation scope');
    assert_contains('not a broad version-by-version certification', $doc, 'provider compatibility docs should preserve the certification boundary');
    assert_contains('not persistent telemetry', $doc, 'provider compatibility docs should state the request-local telemetry boundary');
    assert_contains('No third-party provider APIs are called by wp fts status', $doc, 'provider compatibility docs should state status/advisory provider API boundaries');
    assert_contains('Provider Compatibility Evidence', $testing, 'testing docs should link the provider compatibility evidence lane');
    assert_contains('provider interference matrix', $testing, 'testing docs should mention the provider interference matrix');

    $smokeSource = (string) file_get_contents($root . '/tests/integration/provider-compatibility-wordpress.php');
    assert_contains(
        'exit(wp_fts_provider_compatibility_wordpress_main());',
        $smokeSource,
        'provider compatibility smoke WP-CLI eval should invoke the inside smoke runner'
    );

    $result = wp_fts_provider_certification_run_process(
        [PHP_BINARY, $root . '/tools/smoke-search-provider-compatibility.php'],
        [
            'WP_FTS_WP_PATH' => '',
            'WP_FTS_PROVIDER_COMPATIBILITY_ALLOW' => '',
            'WP_FTS_PROVIDER_COMPATIBILITY_INSIDE' => '',
        ]
    );
    $output = $result['stdout'] . $result['stderr'];
    assert_same(0, $result['exit'], 'provider compatibility smoke should exit zero when skipping missing WordPress config');
    assert_contains('SKIP:', $output, 'provider compatibility smoke skip should be explicit');
    assert_contains('WP_FTS_WP_PATH', $output, 'provider compatibility smoke skip should name the WordPress path variable');
});
