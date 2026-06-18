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

test_case('quality plugin request and REST sanitization preserve valid bounded UTF-8', function (): void {
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

        $restValue = qpsh_private('rest_query', ['q' => '', 'query' => $raw]);
        assert_true(strlen($restValue) <= 200, "plugin REST query case {$i} should be byte bounded");
        assert_true(qpsh_valid_utf8($restValue), "plugin REST query case {$i} should remain valid UTF-8");
        assert_true(!str_contains($restValue, '<'), "plugin REST query case {$i} should strip tags");
    }

    assert_same('fallback', qpsh_private('rest_query', ['q' => '   ', 'query' => 'fallback']), 'REST query alias should not let an empty q mask query');
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
        if ($i % 11 === 0) {
            $raw['replace_search_scope'] = ['frontend-admin', 'frontend', 'admin', 'none', 'junk'][$i % 5];
        }

        $settings = WP_FTS_Plugin::sanitize_settings($raw);
        assert_true($settings['index_post_types'] !== [], "plugin settings case {$i} should keep a non-empty post type allowlist");
        foreach ($settings['index_post_types'] as $postType) {
            assert_true(in_array($postType, ['page', 'post'], true), "plugin settings case {$i} should reject non-public post type {$postType}");
        }
        assert_true($settings['snippet_length'] >= 40 && $settings['snippet_length'] <= 500, "plugin settings case {$i} should clamp snippet length");
        assert_true($settings['result_limit'] >= 1 && $settings['result_limit'] <= WP_FTS_Plugin::MAX_SEARCH_LIMIT, "plugin settings case {$i} should clamp result limit");
        assert_true(in_array($settings['match_mode'], ['OR', 'AND'], true), "plugin settings case {$i} should canonicalize match mode");
        assert_true(is_bool($settings['auto_index']), "plugin settings case {$i} should sanitize auto_index as bool");
        assert_true(is_bool($settings['replace_frontend_search']), "plugin settings case {$i} should sanitize frontend replacement as bool");
        assert_true(is_bool($settings['replace_admin_post_search']), "plugin settings case {$i} should sanitize admin replacement as bool");
        assert_true(is_bool($settings['highlight']), "plugin settings case {$i} should sanitize highlight as bool");
        assert_true(is_bool($settings['language_fallback']), "plugin settings case {$i} should sanitize language fallback as bool");
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

test_case('quality plugin visibility gates protect generated private draft and password states', function (): void {
    wp_fts_test_reset_wordpress_fakes();
    $statuses = ['publish', 'draft', 'pending', 'future', 'private', 'trash'];

    for ($postId = 1; $postId <= 220; $postId++) {
        $status = $statuses[$postId % count($statuses)];
        $type = $postId % 13 === 0 ? 'secret' : ($postId % 5 === 0 ? 'page' : 'post');
        $password = $postId % 17 === 0 ? 'pw' : '';
        $GLOBALS['wp_fts_test_posts'][$postId] = (object) [
            'ID' => $postId,
            'post_title' => "Visibility {$postId}",
            'post_content' => 'needle visible',
            'post_excerpt' => '',
            'post_status' => $status,
            'post_type' => $type,
            'post_password' => $password,
            'post_date_gmt' => '2026-03-01 00:00:00',
        ];
        if ($postId % 4 === 0) {
            $GLOBALS['wp_fts_test_caps']['read_post'][$postId] = true;
        }
        if ($postId % 9 === 0) {
            $GLOBALS['wp_fts_test_caps']['edit_post'][$postId] = true;
        }

        $frontendExpected = $status === 'publish' && $password === '' && in_array($type, ['post', 'page'], true);
        $readableNonPublic = in_array($status, ['draft', 'pending', 'future', 'private'], true)
            && $password === ''
            && in_array($type, ['post', 'page'], true)
            && (!empty($GLOBALS['wp_fts_test_caps']['read_post'][$postId]) || !empty($GLOBALS['wp_fts_test_caps']['edit_post'][$postId]));
        $adminExpected = $frontendExpected || $readableNonPublic;

        assert_same($frontendExpected, qpsh_private('frontend_post_result_visible', $postId, ['post', 'page']), "plugin frontend visibility case {$postId}");
        assert_same($adminExpected, qpsh_private('admin_post_result_visible', $postId, ['post', 'page']), "plugin admin visibility case {$postId}");
        assert_same($frontendExpected || $readableNonPublic, qpsh_private('can_read_post_result', $postId), "plugin REST/public visibility case {$postId}");
    }
});
