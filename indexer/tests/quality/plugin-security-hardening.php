<?php
declare(strict_types=1);

function qpsh_private(string $method, mixed ...$args): mixed
{
    $reflection = new ReflectionMethod(WP_FTS_Plugin::class, $method);
    $reflection->setAccessible(true);

    return $reflection->invoke(null, ...$args);
}

function qpsh_valid_utf8(string $value): bool
{
    return preg_match('//u', $value) === 1;
}

function qpsh_caught(callable $callback): ?Throwable
{
    try {
        $callback();
    } catch (Throwable $error) {
        return $error;
    }

    return null;
}

test_case('quality generated request text sanitizes while REST query input stays exact', function (): void {
    $boundary = str_repeat('a', 199) . 'ż';
    $truncated = qpsh_private('request_text_value', ['q' => $boundary], 'q', 200);
    assert_same(199, strlen($truncated), 'request_text_value should avoid cutting a multibyte character at the byte boundary');
    assert_true(qpsh_valid_utf8($truncated), 'request_text_value boundary truncation should return valid UTF-8');

    for ($i = 0; $i < 220; $i++) {
        $raw = str_repeat(chr(97 + ($i % 20)), 190 + ($i % 17)) . 'ż<script>bad</script>';
        $requestValue = qpsh_private('request_text_value', ['q' => $raw], 'q', 200);
        assert_true(strlen($requestValue) <= 200, "plugin request text case {$i} should be byte bounded");
        assert_true(qpsh_valid_utf8($requestValue), "plugin request text case {$i} should remain valid UTF-8");
        assert_true(!str_contains($requestValue, '<'), "plugin request text case {$i} should strip tags");

        $restValue = qpsh_private('rest_query', ['q' => $raw]);
        assert_same($raw, $restValue, "plugin REST query case {$i} should preserve the caller's exact native string");
    }

    assert_same('   ', qpsh_private('rest_query', ['q' => '   ']), 'REST q should not trim caller bytes');
    foreach ([null, false, 1, 1.0, ['array'], new stdClass()] as $query) {
        $queryError = qpsh_caught(static fn(): string => qpsh_private('rest_query', ['q' => $query]));
        assert_true($queryError instanceof InvalidArgumentException, 'REST q should reject every present non-string value');
    }
    $queryBudgetError = qpsh_caught(static fn(): string => qpsh_private('rest_query', [
        'q' => str_repeat('q', 4097),
    ]));
    assert_same('query bytes', $queryBudgetError instanceof WP_FTS_Search_Budget_Exceeded ? $queryBudgetError->budget() : null, 'REST q byte 4,097 should use the public query budget');
    assert_same('', qpsh_private('request_text_value', ['q' => ['array']], 'q', 200), 'request_text_value should reject non-scalar input');
});

test_case('quality plugin settings sanitization clamps generated operator input', function (): void {
    wp_fts_test_reset_wordpress_fakes();
    $postTypeInputs = [
        ['post', 'page', 'secret'],
        ['page', 'post', 'post'],
        ['secret', '<script>post</script>'],
        'post,page',
        ['product', 'post'],
    ];
    $boolInputs = ['1', '0', 'true', 'false', 'yes', 'no', 'on', 'off', 1, 0, true, false, ''];
    $modes = ['OR', 'AND', 'xor', '<b>or</b>', '', 'and'];

    for ($i = 0; $i < 260; $i++) {
        $raw = [
            'index_post_types' => $postTypeInputs[$i % count($postTypeInputs)],
            'auto_index' => $boolInputs[$i % count($boolInputs)],
            'replace_frontend_search' => $boolInputs[($i + 3) % count($boolInputs)],
            'replace_admin_post_search' => $boolInputs[($i + 5) % count($boolInputs)],
            'highlight' => $boolInputs[($i + 7) % count($boolInputs)],
            'snippet_length' => (string) (-40 + $i * 3),
            'result_limit' => (string) (-5 + $i),
            'language_fallback' => $boolInputs[($i + 9) % count($boolInputs)],
            'match_mode' => $modes[$i % count($modes)],
        ];
        $settings = WP_FTS_Plugin::sanitize_settings($raw);
        assert_true($settings['index_post_types'] !== [], "plugin settings case {$i} should keep a non-empty post type allowlist");
        foreach ($settings['index_post_types'] as $postType) {
            assert_true(in_array($postType, ['attachment', 'page', 'post'], true), "plugin settings case {$i} should reject non-public post type {$postType}");
        }
        assert_true($settings['snippet_length'] >= 40 && $settings['snippet_length'] <= 500, "plugin settings case {$i} should clamp snippet length");
        assert_true($settings['result_limit'] >= 1 && $settings['result_limit'] <= WP_FTS_Plugin::MAX_SEARCH_LIMIT, "plugin settings case {$i} should clamp result limit");
        assert_true(in_array($settings['match_mode'], ['OR', 'AND'], true), "plugin settings case {$i} should canonicalize match mode");
        assert_true(is_bool($settings['auto_index']), "plugin settings case {$i} should sanitize auto_index as bool");
        assert_true(is_bool($settings['replace_frontend_search']), "plugin settings case {$i} should sanitize frontend replacement as bool");
        assert_true(is_bool($settings['replace_admin_post_search']), "plugin settings case {$i} should sanitize admin replacement as bool");
        assert_true(is_bool($settings['highlight']), "plugin settings case {$i} should sanitize highlight as bool");
        assert_true(!array_key_exists('language_fallback', $settings), "plugin settings case {$i} should discard removed language fanout state");
    }
});

test_case('quality plugin snippet sanitizer preserves trusted marks and removes unsafe bodies', function (): void {
    for ($i = 0; $i < 220; $i++) {
        $word = 'visible' . $i;
        $unsafe = '<mark onclick="alert(1)">' . $word . '</mark><script>secret' . $i . '</script><style>.x{}</style><span class="ok" onclick="bad">span</span><a href="javascript:alert(1)">link</a><img src=x onerror=bad>';
        $safe = qpsh_private('sanitize_frontend_snippet_html', $unsafe);

        assert_true(str_contains($safe, '<mark>') && str_contains($safe, '</mark>'), "plugin snippet sanitizer case {$i} should preserve mark tags");
        assert_true(str_contains($safe, $word), "plugin snippet sanitizer case {$i} should preserve visible text");
        assert_true(!str_contains($safe, 'onclick'), "plugin snippet sanitizer case {$i} should strip event attributes");
        assert_true(!str_contains($safe, 'javascript:'), "plugin snippet sanitizer case {$i} should strip unsafe href attributes in fallback mode");
        assert_true(!str_contains($safe, 'secret' . $i), "plugin snippet sanitizer case {$i} should remove hidden script bodies");
        assert_true(!str_contains(strtolower($safe), '<img'), "plugin snippet sanitizer case {$i} should remove disallowed image tags");
    }
});

test_case('quality plugin frontend and admin query gates reject unsupported generated constraints', function (): void {
    wp_fts_test_reset_wordpress_fakes();
    $unsupported = ['author', 'cat', 'meta_query', 'post__in', 'tax_query', 'year', 'post_password'];

    for ($i = 0; $i < 180; $i++) {
        $vars = ['s' => 'needle', 'posts_per_page' => 10];
        $expected = true;
        if ($i % 3 === 0) {
            $vars[$unsupported[$i % count($unsupported)]] = 'blocked';
            $expected = false;
        } elseif ($i % 5 === 0) {
            $vars['post_status'] = 'draft';
            $expected = false;
        } elseif ($i % 7 === 0) {
            $vars['suppress_filters'] = '1';
            $expected = false;
        } elseif ($i % 11 === 0) {
            $vars['post_status'] = 'publish';
        }

        $query = new WP_FTS_Test_Query($vars);
        assert_same($expected, qpsh_private('is_frontend_search_query', $query), "plugin frontend query gate case {$i}");
        WP_FTS_Plugin::prepare_frontend_search_query($query);
        assert_same($expected, !empty($query->query_vars['wp_fts_search_candidate']), "plugin frontend candidate marker case {$i}");
    }

    $GLOBALS['wp_fts_test_is_admin'] = true;
    $GLOBALS['pagenow'] = 'edit.php';
    $GLOBALS['wp_fts_test_caps']['edit_others_posts'][0] = true;
    $GLOBALS['wp_fts_test_caps']['edit_published_posts'][0] = true;
    $GLOBALS['wp_fts_test_caps']['read_private_posts'][0] = true;
    for ($i = 0; $i < 180; $i++) {
        $vars = ['s' => 'needle', 'posts_per_page' => 10, 'post_type' => 'post'];
        $expected = true;
        if ($i % 4 === 0) {
            $vars['post_type'] = 'page';
            $expected = false;
        } elseif ($i % 6 === 0) {
            $vars['perm'] = 'editable';
            $expected = false;
        } elseif ($i % 8 === 0) {
            $vars['page'] = WP_FTS_Plugin::ADMIN_PAGE_SLUG;
            $expected = false;
        } elseif ($i % 10 === 0) {
            $vars['orderby'] = 'modified';
            $vars['post_status'] = 'draft';
            $vars['order'] = 'ASC';
            $expected = false;
        } elseif ($i % 9 === 0) {
            $vars['orderby'] = 'modified';
            $vars['post_status'] = 'draft';
            $vars['order'] = 'DESC';
        }

        $query = new WP_FTS_Test_Query($vars);
        assert_same($expected, qpsh_private('is_admin_post_search_query', $query), "plugin admin query gate case {$i}");
        WP_FTS_Plugin::prepare_admin_post_search_query($query);
        assert_same($expected, !empty($query->query_vars['wp_fts_admin_post_search_candidate']), "plugin admin candidate marker case {$i}");
    }
});

test_case('quality plugin visibility scope authorizes type-wide statuses before SQL LIMIT', function (): void {
    wp_fts_test_reset_wordpress_fakes();

    $defaultSettings = WP_FTS_Plugin::default_settings();
    $typeCases = [
        ['label' => 'configured default', 'opts' => [], 'types' => ['attachment', 'page', 'post'], 'valid' => true],
        ['label' => 'single post', 'opts' => ['post_types' => ['post']], 'types' => ['post'], 'valid' => true],
        ['label' => 'plural page', 'opts' => ['post_types' => ['page']], 'types' => ['page'], 'valid' => true],
        ['label' => 'normalized configured pair', 'opts' => ['post_types' => ['post', 'page', 'post']], 'types' => ['page', 'post'], 'valid' => true],
        ['label' => 'excluded non-public type', 'opts' => ['post_types' => ['secret']], 'types' => ['secret'], 'valid' => false],
        ['label' => 'unknown type', 'opts' => ['post_types' => ['unknown']], 'types' => ['unknown'], 'valid' => false],
        ['label' => 'mixed enabled and excluded types', 'opts' => ['post_types' => ['post', 'secret']], 'types' => ['post', 'secret'], 'valid' => false],
        [
            'label' => 'type disabled in settings',
            'opts' => ['post_types' => ['page']],
            'types' => ['page'],
            'valid' => false,
            'settings' => array_replace($defaultSettings, ['index_post_types' => ['post']]),
        ],
        [
            'label' => 'restricted configured default',
            'opts' => [],
            'types' => ['post'],
            'valid' => true,
            'settings' => array_replace($defaultSettings, ['index_post_types' => ['post']]),
        ],
    ];
    $statusCases = [
        ['label' => 'published default', 'opts' => [], 'statuses' => ['publish'], 'valid' => true],
        ['label' => 'published', 'opts' => ['post_statuses' => ['publish']], 'statuses' => ['publish'], 'valid' => true],
        ['label' => 'draft', 'opts' => ['post_statuses' => ['draft']], 'statuses' => ['draft'], 'valid' => true],
        ['label' => 'pending', 'opts' => ['post_statuses' => ['pending']], 'statuses' => ['pending'], 'valid' => true],
        ['label' => 'future', 'opts' => ['post_statuses' => ['future']], 'statuses' => ['future'], 'valid' => true],
        ['label' => 'private', 'opts' => ['post_statuses' => ['private']], 'statuses' => ['private'], 'valid' => true],
        ['label' => 'published and draft', 'opts' => ['post_statuses' => ['publish', 'draft']], 'statuses' => ['draft', 'publish'], 'valid' => true],
        ['label' => 'all editable', 'opts' => ['post_statuses' => ['future', 'pending', 'draft']], 'statuses' => ['draft', 'future', 'pending'], 'valid' => true],
        ['label' => 'published and private', 'opts' => ['post_statuses' => ['private', 'publish']], 'statuses' => ['private', 'publish'], 'valid' => true],
        ['label' => 'editable and private', 'opts' => ['post_statuses' => ['private', 'draft']], 'statuses' => ['draft', 'private'], 'valid' => true],
        ['label' => 'all supported', 'opts' => ['post_statuses' => ['private', 'publish', 'future', 'pending', 'draft']], 'statuses' => ['draft', 'future', 'pending', 'private', 'publish'], 'valid' => true],
        ['label' => 'unsupported trash', 'opts' => ['post_statuses' => ['trash']], 'statuses' => ['trash'], 'valid' => false],
        ['label' => 'mixed supported and unsupported', 'opts' => ['post_statuses' => ['publish', 'inherit']], 'statuses' => ['inherit', 'publish'], 'valid' => false],
    ];
    $typeCapabilities = [
        'attachment' => ['edit' => 'edit_others_posts', 'published' => 'edit_published_posts', 'private' => 'read_private_posts'],
        'post' => ['edit' => 'edit_others_posts', 'published' => 'edit_published_posts', 'private' => 'read_private_posts'],
        'page' => ['edit' => 'edit_others_pages', 'published' => 'edit_published_pages', 'private' => 'read_private_pages'],
    ];
    $capabilityBits = [
        'edit_others_posts',
        'edit_published_posts',
        'read_private_posts',
        'edit_others_pages',
        'edit_published_pages',
        'read_private_pages',
    ];

    $caseNumber = 0;
    foreach ($typeCases as $typeCase) {
        foreach ($statusCases as $statusCase) {
            foreach (range(0, 63) as $capabilityMask) {
                $caseNumber++;
                $GLOBALS['wp_fts_test_caps'] = [
                    // Per-object grants cannot make a pre-LIMIT SQL scope safe.
                    'read_post' => [991 => true],
                    'edit_post' => [991 => true],
                ];
                foreach ($capabilityBits as $bit => $capability) {
                    if (($capabilityMask & (1 << $bit)) !== 0) {
                        $GLOBALS['wp_fts_test_caps'][$capability][0] = true;
                    }
                }

                $needsEditOthers = array_intersect($statusCase['statuses'], ['draft', 'pending', 'future']) !== [];
                $needsEditPublished = in_array('future', $statusCase['statuses'], true);
                $needsReadPrivate = in_array('private', $statusCase['statuses'], true);
                $hasRequiredCapabilities = true;
                foreach ($typeCase['types'] as $postType) {
                    if (!isset($typeCapabilities[$postType])) {
                        continue;
                    }
                    if (
                        $needsEditOthers
                        && empty($GLOBALS['wp_fts_test_caps'][$typeCapabilities[$postType]['edit']][0])
                    ) {
                        $hasRequiredCapabilities = false;
                    }
                    if (
                        $needsEditPublished
                        && empty($GLOBALS['wp_fts_test_caps'][$typeCapabilities[$postType]['published']][0])
                    ) {
                        $hasRequiredCapabilities = false;
                    }
                    if (
                        $needsReadPrivate
                        && empty($GLOBALS['wp_fts_test_caps'][$typeCapabilities[$postType]['private']][0])
                    ) {
                        $hasRequiredCapabilities = false;
                    }
                }
                $expectedAllowed = $typeCase['valid'] && $statusCase['valid'] && $hasRequiredCapabilities;
                $label = "plugin SQL visibility case {$caseNumber} ({$typeCase['label']}; {$statusCase['label']}; capability mask {$capabilityMask})";

                $threw = false;
                try {
                    $scope = qpsh_private(
                        'authorized_search_scope',
                        array_replace($typeCase['opts'], $statusCase['opts']),
                        $typeCase['settings'] ?? $defaultSettings
                    );
                } catch (InvalidArgumentException) {
                    $threw = true;
                    $scope = [];
                }

                assert_same($expectedAllowed, !$threw, $label);
                if (!$expectedAllowed) {
                    continue;
                }
                assert_same($typeCase['types'], $scope['post_types'] ?? null, $label . ' should compile sorted post types');
                assert_same($statusCase['statuses'], $scope['post_statuses'] ?? null, $label . ' should compile sorted statuses');
            }
        }
    }

    $acceptance = (string) file_get_contents(dirname(__DIR__, 2) . '/docs/relational-search-acceptance.md');
    $readme = (string) file_get_contents(dirname(__DIR__, 2) . '/README.md');
    assert_contains('7,488 capability combinations', $acceptance, 'acceptance should retain the exhaustive mapped-capability matrix');
    assert_contains('future rows require both mapped', $acceptance, 'acceptance should retain WordPress future-post meta-cap parity');
    assert_contains('future rows also require `edit_published_posts`', $readme, 'operator documentation should state the scheduled-post capability boundary');
});
