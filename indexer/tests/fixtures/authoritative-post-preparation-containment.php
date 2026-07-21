<?php
declare(strict_types=1);

$GLOBALS['wp_fts_authoritative_dependency_calls'] = [
    'get_object_taxonomies' => 0,
    'wp_get_object_terms' => 0,
    'get_post_meta' => 0,
    'get_option' => 0,
];

/** Fail if authoritative preparation reopens taxonomy registration. */
function get_object_taxonomies(mixed ...$args): array
{
    $GLOBALS['wp_fts_authoritative_dependency_calls']['get_object_taxonomies']++;
    throw new RuntimeException('authoritative preparation reopened taxonomy registration');
}

/** Fail if authoritative preparation reopens taxonomy relationships. */
function wp_get_object_terms(mixed ...$args): array
{
    $GLOBALS['wp_fts_authoritative_dependency_calls']['wp_get_object_terms']++;
    throw new RuntimeException('authoritative preparation reopened taxonomy relationships');
}

/** Fail if authoritative preparation reopens canonical post metadata. */
function get_post_meta(mixed ...$args): mixed
{
    $GLOBALS['wp_fts_authoritative_dependency_calls']['get_post_meta']++;
    throw new RuntimeException('authoritative preparation reopened post metadata');
}

/** Fail if authoritative preparation reads process-global plugin options. */
function get_option(mixed ...$args): mixed
{
    $GLOBALS['wp_fts_authoritative_dependency_calls']['get_option']++;
    throw new RuntimeException('authoritative preparation reopened plugin options');
}

/** Keep unrelated filter plumbing inert inside the isolated fixture. */
function apply_filters(string $hook, mixed $value, mixed ...$args): mixed
{
    return $value;
}

require_once dirname(__DIR__, 2) . '/src/bootstrap.php';

$wpdb = (object) [
    'prefix' => 'wp_',
    'posts' => 'wp_posts',
    'term_relationships' => 'wp_term_relationships',
];
$storage = new WP_FTS_Storage_Mysql($wpdb);
$indexer = new WP_FTS_Indexer(
    $storage,
    new WP_FTS_Analyzer(['default_lang' => 'en']),
    new WP_FTS_PostContentExtractor()
);

$prepared = 0;
for ($postId = 1; $postId <= 100; $postId++) {
    $post = (object) [
        'ID' => $postId,
        'post_title' => 'Authoritative title ' . $postId,
        'post_content' => '<p>Authoritative content ' . $postId . '</p>',
        'post_excerpt' => 'Authoritative excerpt ' . $postId,
        'post_type' => 'post',
        'post_status' => 'publish',
        'post_date_gmt' => '2026-07-19 00:00:00',
        'terms' => ['category' => ['Category ' . $postId]],
        'custom_fields' => ['signal' => ['Signal ' . $postId]],
    ];
    $source = $indexer->prepare_post_source($post, ['lang' => 'en']);
    if ((int) ($source['doc_id'] ?? 0) !== $postId) {
        throw new RuntimeException('authoritative preparation returned the wrong document id');
    }
    $prepared++;
}

echo json_encode([
    'prepared' => $prepared,
    'dependency_calls' => $GLOBALS['wp_fts_authoritative_dependency_calls'],
], JSON_THROW_ON_ERROR) . PHP_EOL;
