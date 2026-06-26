<?php
declare(strict_types=1);

if (!defined('ABSPATH')) {
    require '/wordpress/wp-load.php';
}

require_once ABSPATH . 'wp-admin/includes/post.php';

$option = 'wp_fts_playground_dashboard_demo_post_ids';
$existing = get_option($option, []);
if (is_array($existing)) {
    foreach ($existing as $post_id) {
        $post_id = (int) $post_id;
        if ($post_id > 0) {
            wp_delete_post($post_id, true);
        }
    }
}

$posts = [
    [
        'lang' => 'en',
        'title' => 'English search dashboard demo',
        'content' => 'Search tokens should find English spending, indexing, and dashboard examples quickly.',
    ],
    [
        'lang' => 'pl',
        'title' => 'Polski demonstracyjny wpis wyszukiwania',
        'content' => 'Wyszukiwanie powinno odnajdywac polskie wpisy, odmiany slow oraz przyklady indeksowania.',
    ],
    [
        'lang' => 'fr',
        'title' => 'Article francais de demonstration',
        'content' => 'La recherche doit trouver les contenus francais, les formes de mots et les exemples du tableau de bord.',
    ],
    [
        'lang' => 'ar',
        'title' => 'مقال عربي للتجربة',
        'content' => 'يجب ان يجد البحث المحتوى العربي وكلمات الفهرسة وامثلة لوحة التحكم.',
    ],
];

$created = [];
foreach ($posts as $post) {
    $post_id = wp_insert_post([
        'post_type' => 'post',
        'post_status' => 'publish',
        'post_title' => (string) $post['title'],
        'post_content' => (string) $post['content'],
    ], true);

    if (is_wp_error($post_id) || (int) $post_id <= 0) {
        continue;
    }

    $post_id = (int) $post_id;
    $created[] = $post_id;
    if (class_exists('WP_FTS_Plugin')) {
        update_post_meta($post_id, WP_FTS_Plugin::LANGUAGE_META_KEY, (string) $post['lang']);
    }
}

update_option($option, $created, false);

if (class_exists('WP_FTS_Plugin')) {
    WP_FTS_Plugin::process_manual_index_batch([
        'batch_size' => max(10, count($created) + 4),
        'source' => 'playground-dashboard-demo',
    ]);
}
