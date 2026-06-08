<?php
declare(strict_types=1);

final class Language_FTS_Playground_Demo
{
    /**
     * @return array<int,array{slug:string,title:string,language:string,excerpt:string,content:string}>
     */
    public static function demo_posts(): array
    {
        return [
            [
                'slug' => 'language-fts-english-orchard',
                'title' => 'Language FTS demo: English orchard',
                'language' => 'en',
                'excerpt' => 'Orchard summary text demonstrates the indexed excerpt field.',
                'content' =>
                    '<!-- wp:paragraph -->' .
                    '<p class="ghostmarkup" id="ghostmarkup">The orchard path is visible content for the English partition. Searching searched pages and searches stay in the English demo.</p>' .
                    '<!-- /wp:paragraph -->' .
                    '<!-- wp:image -->' .
                    '<figure class="wp-block-image"><img src="data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///ywAAAAAAQABAAACAUwAOw==" alt="falconalt stories beside the orchard" /></figure>' .
                    '<!-- /wp:image -->' .
                    '<style>.ghostmarkup::before{content:"ghostmarkup";}</style>' .
                    '<script>window.ghostmarkup = "ghostmarkup";</script>' .
                    '<!-- ghostmarkup -->' .
                    '<template>ghostmarkup</template>',
            ],
            [
                'slug' => 'language-fts-polish-lodz',
                'title' => 'Language FTS demo: Polish Lodz',
                'language' => 'pl',
                'excerpt' => 'Polski opis demo dla partycji wyszukiwania.',
                'content' =>
                    '<!-- wp:paragraph -->' .
                    '<p>Łódź ma widoczny akapit w polskiej partycji wyszukiwania.</p>' .
                    '<!-- /wp:paragraph -->' .
                    '<!-- wp:image -->' .
                    '<figure class="wp-block-image"><img src="data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///ywAAAAAAQABAAACAUwAOw==" alt="Łódź na mapie" /></figure>' .
                    '<!-- /wp:image -->',
            ],
            [
                'slug' => 'language-fts-german-fuer',
                'title' => 'Language FTS demo: German Fuehrung',
                'language' => 'de',
                'excerpt' => 'Deutscher Demo-Auszug fuer die Suche.',
                'content' =>
                    '<!-- wp:paragraph -->' .
                    '<p>Dieses Beispiel ist für die deutschen Führungen gedacht und hilft beim Suchen in der deutschen Partition.</p>' .
                    '<!-- /wp:paragraph -->' .
                    '<!-- wp:image -->' .
                    '<figure class="wp-block-image"><img src="data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///ywAAAAAAQABAAACAUwAOw==" alt="deutscher Hinweis für Führung" /></figure>' .
                    '<!-- /wp:image -->',
            ],
        ];
    }

    /**
     * @return int[]
     */
    public static function seed_posts(): array
    {
        if (!function_exists('wp_insert_post')) {
            return [];
        }

        $post_ids = [];
        foreach (self::demo_posts() as $demo_post) {
            $existing = function_exists('get_page_by_path') ? get_page_by_path($demo_post['slug'], OBJECT, 'post') : null;
            $post_data = [
                'post_type' => 'post',
                'post_status' => 'publish',
                'post_title' => $demo_post['title'],
                'post_name' => $demo_post['slug'],
                'post_excerpt' => $demo_post['excerpt'],
                'post_content' => $demo_post['content'],
            ];

            if (is_object($existing) && isset($existing->ID)) {
                $post_data['ID'] = (int) $existing->ID;
                $result = wp_update_post($post_data, true);
            } else {
                $result = wp_insert_post($post_data, true);
            }

            if (function_exists('is_wp_error') && is_wp_error($result)) {
                continue;
            }

            $post_id = (int) $result;
            if ($post_id > 0 && function_exists('update_post_meta')) {
                update_post_meta($post_id, '_language_fts_language', $demo_post['language']);
                update_post_meta($post_id, '_language_fts_demo', '1');
                $post_ids[] = $post_id;
            }
        }

        return $post_ids;
    }

    /**
     * @return array<int,array{query:string,language:string,label:string}>
     */
    public static function sample_searches(): array
    {
        return [
            ['query' => 'orchard', 'language' => 'en', 'label' => 'English visible: orchard'],
            ['query' => 'summary', 'language' => 'en', 'label' => 'English excerpt: summary'],
            ['query' => 'search', 'language' => 'en', 'label' => 'English inflection: search'],
            ['query' => '"search pages"', 'language' => 'en', 'label' => 'English phrase: "search pages"'],
            ['query' => 'orchrd~', 'language' => 'en', 'label' => 'English fuzzy typo: orchrd~'],
            ['query' => 'story', 'language' => 'en', 'label' => 'English alt inflection: story'],
            ['query' => 'falconalt', 'language' => 'en', 'label' => 'English alt: falconalt'],
            ['query' => 'ghostmarkup', 'language' => 'en', 'label' => 'Markup noise: ghostmarkup'],
            ['query' => 'lodz', 'language' => 'pl', 'label' => 'Polish fold: lodz'],
            ['query' => 'polska', 'language' => 'pl', 'label' => 'Polish inflection: polska'],
            ['query' => 'fuehrung', 'language' => 'de', 'label' => 'German fold: fuehrung'],
            ['query' => 'deutsch', 'language' => 'de', 'label' => 'German inflection: deutsch'],
            ['query' => 'suche', 'language' => 'de', 'label' => 'German inflection: suche'],
        ];
    }
}
