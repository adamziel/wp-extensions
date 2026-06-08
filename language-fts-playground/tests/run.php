<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';

final class Language_FTS_Playground_Test_Failure extends RuntimeException
{
}

final class Language_FTS_Playground_Test_Storage implements Language_FTS_Playground_Storage_Interface
{
    /** @var array<int,array{post_id:int,language:string,title:string,status:string,document_length:int,updated_at:string}> */
    private array $documents = [];

    /** @var array<string,array<string,array<int,int>>> */
    private array $postings = [];

    public int $install_count = 0;
    public int $clear_count = 0;
    public int $delete_count = 0;

    public function install(): void
    {
        $this->install_count++;
    }

    public function clear(): void
    {
        $this->clear_count++;
        $this->documents = [];
        $this->postings = [];
    }

    public function replace_document(
        int $post_id,
        string $language,
        string $title,
        string $status,
        int $document_length,
        array $term_frequencies
    ): void {
        $this->delete_document($post_id);
        $this->documents[$post_id] = [
            'post_id' => $post_id,
            'language' => $language,
            'title' => $title,
            'status' => $status,
            'document_length' => max(1, $document_length),
            'updated_at' => 'test',
        ];

        foreach ($term_frequencies as $term => $tf) {
            $this->postings[$language][(string) $term][$post_id] = max(1, (int) $tf);
        }
    }

    public function delete_document(int $post_id): void
    {
        $this->delete_count++;
        unset($this->documents[$post_id]);
        foreach ($this->postings as $language => $terms) {
            foreach ($terms as $term => $postings) {
                unset($postings[$post_id]);
                if ($postings === []) {
                    unset($this->postings[$language][$term]);
                } else {
                    $this->postings[$language][$term] = $postings;
                }
            }
        }
    }

    public function fetch_postings(string $language, array $terms): array
    {
        $result = [];
        foreach ($terms as $term) {
            $term = (string) $term;
            if (isset($this->postings[$language][$term])) {
                $result[$term] = $this->postings[$language][$term];
            }
        }

        return $result;
    }

    public function fetch_document_lengths(string $language, array $post_ids): array
    {
        $lengths = [];
        foreach ($post_ids as $post_id) {
            $post_id = (int) $post_id;
            if (($this->documents[$post_id]['language'] ?? null) === $language) {
                $lengths[$post_id] = $this->documents[$post_id]['document_length'];
            }
        }

        return $lengths;
    }

    public function document_count(string $language): int
    {
        $count = 0;
        foreach ($this->documents as $document) {
            if ($document['language'] === $language) {
                $count++;
            }
        }

        return $count;
    }

    public function all_documents(): array
    {
        return array_values($this->documents);
    }
}

final class Language_FTS_Playground_Test_Failing_Storage implements Language_FTS_Playground_Storage_Interface
{
    public function __construct(private string $message = 'Simulated storage failure.')
    {
    }

    public function install(): void
    {
    }

    public function clear(): void
    {
        throw new RuntimeException($this->message);
    }

    public function replace_document(
        int $post_id,
        string $language,
        string $title,
        string $status,
        int $document_length,
        array $term_frequencies
    ): void {
        unset($post_id, $language, $title, $status, $document_length, $term_frequencies);
        throw new RuntimeException($this->message);
    }

    public function delete_document(int $post_id): void
    {
        unset($post_id);
        throw new RuntimeException($this->message);
    }

    public function fetch_postings(string $language, array $terms): array
    {
        unset($language, $terms);
        throw new RuntimeException($this->message);
    }

    public function fetch_document_lengths(string $language, array $post_ids): array
    {
        unset($language, $post_ids);
        throw new RuntimeException($this->message);
    }

    public function document_count(string $language): int
    {
        unset($language);
        throw new RuntimeException($this->message);
    }

    public function all_documents(): array
    {
        throw new RuntimeException($this->message);
    }
}

/**
 * @var array<int,array{name:string,fn:callable}>
 */
$tests = [];

function test_case(string $name, callable $fn): void
{
    global $tests;
    $tests[] = ['name' => $name, 'fn' => $fn];
}

function assert_true(bool $condition, string $message): void
{
    if (!$condition) {
        throw new Language_FTS_Playground_Test_Failure($message);
    }
}

function assert_same(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new Language_FTS_Playground_Test_Failure(
            $message . "\nExpected: " . var_export($expected, true) . "\nActual: " . var_export($actual, true)
        );
    }
}

function assert_contains_text(string $needle, string $haystack, string $message): void
{
    assert_true(str_contains($haystack, $needle), $message . "\nMissing: {$needle}\nText: {$haystack}");
}

function assert_not_contains_text(string $needle, string $haystack, string $message): void
{
    assert_true(!str_contains($haystack, $needle), $message . "\nUnexpected: {$needle}\nText: {$haystack}");
}

function reset_language_fts_wp_state(): void
{
    $GLOBALS['language_fts_test_options'] = [];
    $GLOBALS['language_fts_test_posts'] = [];
    $GLOBALS['language_fts_test_post_meta'] = [];
    $GLOBALS['language_fts_test_revisions'] = [];
    $GLOBALS['language_fts_test_autosaves'] = [];
    $GLOBALS['language_fts_test_actions'] = [];
    $GLOBALS['language_fts_test_scheduled'] = [];
    $GLOBALS['language_fts_test_option_reads'] = [];
    $GLOBALS['language_fts_test_get_option_interceptor'] = null;
    $GLOBALS['language_fts_test_current_user_can'] = true;
    $GLOBALS['language_fts_test_last_redirect'] = null;
    $_GET = [];
    $_POST = [];
}

function set_language_fts_plugin_runtime(Language_FTS_Playground_Storage_Interface $storage): void
{
    $storage_property = new ReflectionProperty(Language_FTS_Playground_Plugin::class, 'storage');
    $storage_property->setAccessible(true);
    $storage_property->setValue(null, $storage);

    $analyzer_property = new ReflectionProperty(Language_FTS_Playground_Plugin::class, 'analyzer');
    $analyzer_property->setAccessible(true);
    $analyzer_property->setValue(null, new Language_FTS_Playground_Analyzer());

    $queue_lock_token_property = new ReflectionProperty(Language_FTS_Playground_Plugin::class, 'queue_lock_token');
    $queue_lock_token_property->setAccessible(true);
    $queue_lock_token_property->setValue(null, null);

    $queue_lock_depth_property = new ReflectionProperty(Language_FTS_Playground_Plugin::class, 'queue_lock_depth');
    $queue_lock_depth_property->setAccessible(true);
    $queue_lock_depth_property->setValue(null, 0);
}

function reset_language_fts_plugin_runtime(Language_FTS_Playground_Storage_Interface|null $storage = null): Language_FTS_Playground_Storage_Interface
{
    reset_language_fts_wp_state();
    $storage = $storage ?? new Language_FTS_Playground_Test_Storage();
    set_language_fts_plugin_runtime($storage);

    return $storage;
}

if (!function_exists('get_option')) {
    function get_option(string $name, mixed $default = false): mixed
    {
        $GLOBALS['language_fts_test_option_reads'][$name] = (int) ($GLOBALS['language_fts_test_option_reads'][$name] ?? 0) + 1;
        $interceptor = $GLOBALS['language_fts_test_get_option_interceptor'] ?? null;
        if (is_callable($interceptor)) {
            $interceptor($name, $GLOBALS['language_fts_test_option_reads'][$name]);
        }

        return array_key_exists($name, $GLOBALS['language_fts_test_options'] ?? [])
            ? $GLOBALS['language_fts_test_options'][$name]
            : $default;
    }
}

if (!function_exists('update_option')) {
    function update_option(string $name, mixed $value, mixed $autoload = null): bool
    {
        unset($autoload);
        $old_value = get_option($name, null);
        $GLOBALS['language_fts_test_options'][$name] = $value;

        return $old_value !== $value;
    }
}

if (!function_exists('add_option')) {
    function add_option(string $name, mixed $value = '', mixed $deprecated = '', mixed $autoload = null): bool
    {
        unset($deprecated, $autoload);
        if (array_key_exists($name, $GLOBALS['language_fts_test_options'] ?? [])) {
            return false;
        }

        $GLOBALS['language_fts_test_options'][$name] = $value;

        return true;
    }
}

if (!function_exists('delete_option')) {
    function delete_option(string $name): bool
    {
        $existed = array_key_exists($name, $GLOBALS['language_fts_test_options'] ?? []);
        unset($GLOBALS['language_fts_test_options'][$name]);

        return $existed;
    }
}

if (!function_exists('add_action')) {
    function add_action(string $hook, callable|array|string $callback, int $priority = 10, int $accepted_args = 1): bool
    {
        $GLOBALS['language_fts_test_actions'][$hook][] = [
            'callback' => $callback,
            'priority' => $priority,
            'accepted_args' => $accepted_args,
        ];

        return true;
    }
}

if (!function_exists('wp_next_scheduled')) {
    function wp_next_scheduled(string $hook): int|false
    {
        return isset($GLOBALS['language_fts_test_scheduled'][$hook]) ? 1 : false;
    }
}

if (!function_exists('wp_schedule_single_event')) {
    function wp_schedule_single_event(int $timestamp, string $hook): bool
    {
        $GLOBALS['language_fts_test_scheduled'][$hook][] = $timestamp;

        return true;
    }
}

if (!function_exists('wp_is_post_revision')) {
    function wp_is_post_revision(int $post_id): int|false
    {
        return !empty($GLOBALS['language_fts_test_revisions'][$post_id]) ? $post_id : false;
    }
}

if (!function_exists('wp_is_post_autosave')) {
    function wp_is_post_autosave(int $post_id): int|false
    {
        return !empty($GLOBALS['language_fts_test_autosaves'][$post_id]) ? $post_id : false;
    }
}

if (!function_exists('get_post')) {
    function get_post(int|object|null $post = null): object|null
    {
        if (is_object($post)) {
            return $post;
        }

        $post_id = (int) $post;

        return $GLOBALS['language_fts_test_posts'][$post_id] ?? null;
    }
}

if (!function_exists('get_posts')) {
    function get_posts(array $args = []): array
    {
        $posts = array_values($GLOBALS['language_fts_test_posts'] ?? []);
        $statuses = array_map('strval', (array) ($args['post_status'] ?? []));
        $types = array_map('strval', (array) ($args['post_type'] ?? []));

        $posts = array_values(array_filter(
            $posts,
            static function (object $post) use ($statuses, $types): bool {
                $status_matches = $statuses === [] || in_array((string) ($post->post_status ?? ''), $statuses, true);
                $type_matches = $types === [] || in_array((string) ($post->post_type ?? 'post'), $types, true);

                return $status_matches && $type_matches;
            }
        ));

        usort($posts, static fn(object $a, object $b): int => ((int) ($a->ID ?? 0)) <=> ((int) ($b->ID ?? 0)));

        if (($args['fields'] ?? '') === 'ids') {
            return array_map(static fn(object $post): int => (int) $post->ID, $posts);
        }

        return $posts;
    }
}

if (!function_exists('get_post_type')) {
    function get_post_type(int|object|null $post = null): string|false
    {
        $post = is_object($post) ? $post : get_post((int) $post);

        return is_object($post) ? (string) ($post->post_type ?? 'post') : false;
    }
}

if (!function_exists('get_post_type_object')) {
    function get_post_type_object(string $post_type): object|null
    {
        $public = !in_array($post_type, ['revision', 'nav_menu_item', 'private_type'], true);

        return (object) [
            'name' => $post_type,
            'public' => $public,
            'publicly_queryable' => $public,
        ];
    }
}

if (!function_exists('get_post_meta')) {
    function get_post_meta(int $post_id, string $key = '', bool $single = false): mixed
    {
        unset($single);

        return $GLOBALS['language_fts_test_post_meta'][$post_id][$key] ?? '';
    }
}

if (!function_exists('current_user_can')) {
    function current_user_can(string $capability): bool
    {
        unset($capability);

        return (bool) ($GLOBALS['language_fts_test_current_user_can'] ?? false);
    }
}

if (!function_exists('wp_die')) {
    function wp_die(string $message): never
    {
        throw new RuntimeException($message);
    }
}

if (!function_exists('check_admin_referer')) {
    function check_admin_referer(string $action): bool
    {
        unset($action);

        return true;
    }
}

if (!function_exists('__')) {
    function __(string $text, string $domain = 'default'): string
    {
        unset($domain);

        return $text;
    }
}

if (!function_exists('esc_html__')) {
    function esc_html__(string $text, string $domain = 'default'): string
    {
        unset($domain);

        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

if (!function_exists('esc_html')) {
    function esc_html(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

if (!function_exists('esc_attr')) {
    function esc_attr(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

if (!function_exists('esc_url')) {
    function esc_url(string $url): string
    {
        return $url;
    }
}

if (!function_exists('sanitize_text_field')) {
    function sanitize_text_field(string $text): string
    {
        return trim(strip_tags($text));
    }
}

if (!function_exists('sanitize_key')) {
    function sanitize_key(string $key): string
    {
        return preg_replace('/[^a-z0-9_\-]/', '', strtolower($key)) ?? '';
    }
}

if (!function_exists('wp_unslash')) {
    function wp_unslash(string $value): string
    {
        return stripslashes($value);
    }
}

if (!function_exists('admin_url')) {
    function admin_url(string $path = ''): string
    {
        return '/wp-admin/' . ltrim($path, '/');
    }
}

if (!function_exists('wp_nonce_url')) {
    function wp_nonce_url(string $url, string $action): string
    {
        return $url . (str_contains($url, '?') ? '&' : '?') . '_wpnonce=' . rawurlencode($action);
    }
}

if (!function_exists('add_query_arg')) {
    function add_query_arg(array $args, string $url): string
    {
        return $url . (str_contains($url, '?') ? '&' : '?') . http_build_query($args);
    }
}

if (!function_exists('selected')) {
    function selected(mixed $selected, mixed $current = true, bool $display = true): string
    {
        $result = (string) $selected === (string) $current ? ' selected="selected"' : '';
        if ($display) {
            echo $result;
        }

        return $result;
    }
}

if (!function_exists('submit_button')) {
    function submit_button(string $text, string $type = 'primary', string $name = 'submit', bool $wrap = true): void
    {
        unset($type, $name, $wrap);
        echo '<button type="submit">' . esc_html($text) . '</button>';
    }
}

if (!function_exists('number_format_i18n')) {
    function number_format_i18n(float $number, int $decimals = 0): string
    {
        return number_format($number, $decimals, '.', ',');
    }
}

if (!function_exists('get_edit_post_link')) {
    function get_edit_post_link(int $post_id): string
    {
        return '/wp-admin/post.php?post=' . $post_id . '&action=edit';
    }
}

if (!function_exists('get_the_title')) {
    function get_the_title(int|object $post): string
    {
        $post = is_object($post) ? $post : get_post((int) $post);

        return is_object($post) ? (string) ($post->post_title ?? '') : '';
    }
}

if (!function_exists('wp_safe_redirect')) {
    function wp_safe_redirect(string $location): bool
    {
        $GLOBALS['language_fts_test_last_redirect'] = $location;

        return true;
    }
}

function assert_query_terms_overlap(
    Language_FTS_Playground_Analyzer $analyzer,
    string $language,
    string $document_text,
    string $query,
    string $message
): void
{
    $document_terms = $analyzer->analyze_text($document_text, $language);
    $query_terms = $analyzer->analyze_query($query, $language);

    assert_true(
        array_values(array_intersect($document_terms, $query_terms)) !== [],
        $message .
        "\nDocument terms: " . var_export($document_terms, true) .
        "\nQuery terms: " . var_export($query_terms, true)
    );
}

function assert_query_terms_do_not_overlap(
    Language_FTS_Playground_Analyzer $analyzer,
    string $language,
    string $document_text,
    string $query,
    string $message
): void {
    $document_terms = $analyzer->analyze_text($document_text, $language);
    $query_terms = $analyzer->analyze_query($query, $language);

    assert_same(
        [],
        array_values(array_intersect($document_terms, $query_terms)),
        $message .
        "\nDocument terms: " . var_export($document_terms, true) .
        "\nQuery terms: " . var_export($query_terms, true)
    );
}

function fixture_post(
    int $id,
    string $language,
    string $title,
    string $content,
    string $status = 'publish',
    string $post_type = 'post',
    string $password = ''
): object
{
    return (object) [
        'ID' => $id,
        'post_status' => $status,
        'post_type' => $post_type,
        'post_password' => $password,
        'post_title' => $title,
        'post_excerpt' => '',
        'post_content' => $content,
        'language' => $language,
    ];
}

test_case('extracts visible text and image alt while excluding markup noise', function (): void {
    $analyzer = new Language_FTS_Playground_Analyzer();
    $text = $analyzer->extract_searchable_text(
        '<article class="ghostmarkup" id="ghostmarkup">' .
        '<h2>Visible orchard</h2>' .
        '<img src="x.jpg" alt="falconalt from image" />' .
        '<style>.x{content:"ghostmarkup";}</style>' .
        '<script>const ghostmarkup = true;</script>' .
        '<!-- ghostmarkup -->' .
        '<template>ghostmarkup</template>' .
        '</article>'
    );

    assert_contains_text('Visible orchard', $text, 'Visible nodes are indexed.');
    assert_contains_text('falconalt from image', $text, 'Image alt text is indexed.');
    assert_not_contains_text('ghostmarkup', $text, 'Markup, CSS, script, comments, and templates are excluded.');
});

test_case('normalizes supported languages deterministically', function (): void {
    $analyzer = new Language_FTS_Playground_Analyzer();

    assert_same(['orchard'], $analyzer->analyze_text('ORCHARD', 'en'), 'English terms are lowercased.');
    assert_same(['lodz'], $analyzer->analyze_text('Łódź', 'pl'), 'Polish diacritics are folded.');
    assert_same(['fuer', 'fuehrung', 'strasse'], $analyzer->analyze_text('für Führung Straße', 'de'), 'German umlauts are folded.');
});

test_case('adds conservative English inflection keys without noisy stems', function (): void {
    $analyzer = new Language_FTS_Playground_Analyzer();

    assert_query_terms_overlap($analyzer, 'en', 'searching searched searches', 'search', 'English search forms share a demo suffix key.');
    assert_query_terms_overlap($analyzer, 'en', 'stories', 'story', 'English y/ies plural forms share a demo suffix key.');
    assert_query_terms_overlap($analyzer, 'en', 'opening opened', 'open', 'English long regular verb forms share a demo suffix key.');
    assert_query_terms_do_not_overlap($analyzer, 'en', 'running runner', 'run', 'Doubled-consonant run forms stay exact without a stem lexicon.');
    assert_same(['news', 'bus', 'analysis'], $analyzer->analyze_text('news bus analysis', 'en'), 'Sensitive English words remain exact.');
});

test_case('adds conservative German inflection keys without broad short-token stems', function (): void {
    $analyzer = new Language_FTS_Playground_Analyzer();

    assert_query_terms_overlap($analyzer, 'de', 'deutschen deutscher deutsche', 'deutsch', 'German adjective forms share a demo suffix key.');
    assert_query_terms_overlap($analyzer, 'de', 'Führungen', 'fuehrung', 'German plural after umlaut folding shares a demo suffix key.');
    assert_query_terms_overlap($analyzer, 'de', 'suchen', 'suche', 'German safe n-suffix form shares a demo suffix key.');
    assert_same(['der', 'die', 'das', 'im', 'zu', 'am'], $analyzer->analyze_text('der die das im zu am', 'de'), 'Short German function words remain exact.');
});

test_case('adds conservative Polish inflection keys without broad short-token stems', function (): void {
    $analyzer = new Language_FTS_Playground_Analyzer();
    $document_text = 'polskiej partycji wyszukiwania';

    assert_query_terms_overlap($analyzer, 'pl', $document_text, 'polska', 'Polish adjective form shares a key with its inflected form.');
    assert_query_terms_overlap($analyzer, 'pl', $document_text, 'partycja', 'Polish noun form shares a key with its inflected form.');
    assert_query_terms_overlap($analyzer, 'pl', $document_text, 'wyszukiwanie', 'Polish verbal noun form shares a key with its inflected form.');
    assert_same(['ma', 'w', 'do'], $analyzer->analyze_text('ma w do', 'pl'), 'Very short Polish tokens are not broadened into noisy stems.');
});

test_case('covers visible, alt, markup, and partition behavior across supported languages', function (): void {
    $storage = new Language_FTS_Playground_Test_Storage();
    $analyzer = new Language_FTS_Playground_Analyzer();
    $indexer = new Language_FTS_Playground_Indexer($storage, $analyzer);
    $searcher = new Language_FTS_Playground_Searcher($storage, $analyzer);

    $matrix = [
        'en' => [
            'post_id' => 101,
            'title' => 'English matrix',
            'visible_query' => 'search',
            'alt_query' => 'story',
            'fold_query' => null,
            'noise_query' => 'ghostenglish',
            'content' =>
                '<article class="ghostenglish" id="ghostenglish">' .
                '<p>Searching through visible content shows searched and searches forms. Foreign bait: polskiej deutschen.</p>' .
                '<img alt="stories in an English image" />' .
                '<style>.ghostenglish{content:"ghostenglish";}</style>' .
                '<script>const ghostenglish = true;</script>' .
                '<!-- ghostenglish -->' .
                '<template>ghostenglish</template>' .
                '</article>',
        ],
        'pl' => [
            'post_id' => 102,
            'title' => 'Polish matrix',
            'visible_query' => 'polska',
            'alt_query' => 'fotografia',
            'fold_query' => 'lodz',
            'noise_query' => 'ghostpolish',
            'content' =>
                '<article class="ghostpolish" id="ghostpolish">' .
                '<p>Łódź ma widoczny akapit w polskiej partycji wyszukiwania. Foreign bait: searching deutschen.</p>' .
                '<img alt="polskiej fotografii" />' .
                '<style>.ghostpolish{content:"ghostpolish";}</style>' .
                '<script>const ghostpolish = true;</script>' .
                '<!-- ghostpolish -->' .
                '<template>ghostpolish</template>' .
                '</article>',
        ],
        'de' => [
            'post_id' => 103,
            'title' => 'German matrix',
            'visible_query' => 'deutsch',
            'alt_query' => 'fuehrung',
            'fold_query' => 'fuer',
            'noise_query' => 'ghostgerman',
            'content' =>
                '<article class="ghostgerman" id="ghostgerman">' .
                '<p>Die deutschen Beispiele zeigen Führungen und suchen nach sichtbaren Treffern. Foreign bait: searching polskiej.</p>' .
                '<img alt="deutscher Hinweis für Führung" />' .
                '<style>.ghostgerman{content:"ghostgerman";}</style>' .
                '<script>const ghostgerman = true;</script>' .
                '<!-- ghostgerman -->' .
                '<template>ghostgerman</template>' .
                '</article>',
        ],
    ];

    foreach ($matrix as $language => $case) {
        $indexer->index_post(fixture_post($case['post_id'], $language, $case['title'], $case['content']));
    }

    foreach ($matrix as $language => $case) {
        assert_same([$case['post_id']], array_column($searcher->search($case['visible_query'], $language), 'post_id'), "{$language} visible content query matches its document.");
        assert_same([$case['post_id']], array_column($searcher->search($case['alt_query'], $language), 'post_id'), "{$language} image alt query matches its document.");
        if ($case['fold_query'] !== null) {
            assert_same([$case['post_id']], array_column($searcher->search($case['fold_query'], $language), 'post_id'), "{$language} folded query matches its document.");
        }

        foreach (array_keys($matrix) as $partition) {
            assert_same([], $searcher->search($case['noise_query'], $partition), "{$case['noise_query']} markup noise does not match in {$partition}.");
        }

        foreach (array_keys($matrix) as $partition) {
            if ($partition === $language) {
                continue;
            }

            assert_same([], $searcher->search($case['visible_query'], $partition), "{$language} visible query does not leak into {$partition}.");
            assert_same([], $searcher->search($case['alt_query'], $partition), "{$language} alt query does not leak into {$partition}.");
        }
    }
});

test_case('ranks higher term frequency first', function (): void {
    $storage = new Language_FTS_Playground_Test_Storage();
    $analyzer = new Language_FTS_Playground_Analyzer();
    $indexer = new Language_FTS_Playground_Indexer($storage, $analyzer);
    $searcher = new Language_FTS_Playground_Searcher($storage, $analyzer);

    $indexer->index_post(fixture_post(10, 'en', 'Dense', '<p>orchard orchard orchard orchard</p>'));
    $indexer->index_post(fixture_post(11, 'en', 'Sparse', '<p>orchard meadow river stone</p>'));

    $results = $searcher->search('orchard', 'en');

    assert_true(count($results) >= 2, 'Both English documents match.');
    assert_same(10, $results[0]['post_id'], 'The denser document ranks first.');
    assert_true($results[0]['score'] > $results[1]['score'], 'BM25 score reflects term frequency.');
});

test_case('stores lifecycle versions and flags rebuilds on schema or analyzer change', function (): void {
    $storage = reset_language_fts_plugin_runtime();
    assert_true($storage instanceof Language_FTS_Playground_Test_Storage, 'Test storage is available.');
    update_option('language_fts_playground_schema_version', 'old-schema');
    update_option('language_fts_playground_analyzer_version', 'old-analyzer');

    Language_FTS_Playground_Plugin::ensure_schema();

    assert_same(1, $storage->install_count, 'Upgrade installs schema idempotently once.');
    assert_same(LANGUAGE_FTS_PLAYGROUND_SCHEMA_VERSION, get_option('language_fts_playground_schema_version'), 'Schema version is stored separately.');
    assert_same(LANGUAGE_FTS_PLAYGROUND_ANALYZER_VERSION, get_option('language_fts_playground_analyzer_version'), 'Analyzer version is stored separately.');
    assert_same(true, get_option('language_fts_playground_rebuild_required'), 'Analyzer/schema changes mark a rebuild as required.');
});

test_case('queues saved public posts and processes bounded batches', function (): void {
    $storage = reset_language_fts_plugin_runtime();
    assert_true($storage instanceof Language_FTS_Playground_Test_Storage, 'Test storage is available.');
    $first = fixture_post(201, 'en', 'Queued orchard one', '<p>orchard one</p>');
    $second = fixture_post(202, 'en', 'Queued orchard two', '<p>orchard two</p>');
    $GLOBALS['language_fts_test_posts'][201] = $first;
    $GLOBALS['language_fts_test_posts'][202] = $second;

    Language_FTS_Playground_Plugin::index_saved_post(201, $first, true);
    Language_FTS_Playground_Plugin::index_saved_post(202, $second, true);

    assert_same([], $storage->all_documents(), 'Save hooks queue work instead of indexing synchronously.');
    assert_same(2, Language_FTS_Playground_Plugin::queued_count(), 'Both changed posts are queued.');
    assert_true($GLOBALS['language_fts_test_scheduled'] !== [], 'Queueing schedules a cron processor.');

    $first_batch = Language_FTS_Playground_Plugin::process_index_queue(1);
    assert_same(1, $first_batch['processed'], 'Only one queued post is processed in a one-item batch.');
    assert_same(1, count($storage->all_documents()), 'One document is indexed after the first bounded batch.');
    assert_same(1, Language_FTS_Playground_Plugin::queued_count(), 'One queued post remains after the first bounded batch.');

    $second_batch = Language_FTS_Playground_Plugin::process_index_queue(10);
    assert_same(1, $second_batch['processed'], 'The remaining queued post is processed by a later batch.');
    assert_same(2, count($storage->all_documents()), 'Both documents are indexed after the second batch.');
    assert_same(0, Language_FTS_Playground_Plugin::queued_count(), 'The queue is empty after all items are processed.');
});

test_case('contended public-post enqueue reports failure instead of claiming success', function (): void {
    reset_language_fts_plugin_runtime();
    $post = fixture_post(801, 'en', 'Contended public orchard', '<p>orchard queue contention</p>');
    $GLOBALS['language_fts_test_posts'][801] = $post;
    update_option(
        'language_fts_playground_index_queue_lock',
        [
            'token' => 'external-lock-token',
            'expires_at' => microtime(true) + 10,
        ]
    );

    Language_FTS_Playground_Plugin::index_saved_post(801, $post, true);
    $queue = get_option('language_fts_playground_index_queue', []);
    $status = Language_FTS_Playground_Plugin::index_status();

    assert_same([], $queue, 'The active lock prevents an unlocked queue write.');
    assert_same(0, Language_FTS_Playground_Plugin::queued_count(), 'No queue item is falsely counted after the failed enqueue.');
    assert_contains_text('Could not update the Language FTS queue for a saved post.', (string) ($status['last_status'] ?? ''), 'The failed enqueue is reported in status.');
    assert_contains_text('Could not acquire the Language FTS queue lock', (string) ($status['last_error'] ?? ''), 'The lock contention reason remains visible.');
    assert_not_contains_text('Queued a changed post for Language FTS indexing.', (string) ($status['last_status'] ?? ''), 'Status does not claim the post was queued.');
    assert_same([], $GLOBALS['language_fts_test_scheduled'], 'A failed enqueue does not schedule an empty queue.');
});

test_case('contended private save removes a previously indexed document', function (): void {
    $storage = reset_language_fts_plugin_runtime();
    assert_true($storage instanceof Language_FTS_Playground_Test_Storage, 'Test storage is available.');
    $indexer = new Language_FTS_Playground_Indexer($storage, new Language_FTS_Playground_Analyzer());
    $indexer->index_post(fixture_post(900, 'en', 'Published orchard', '<p>orchard public</p>'));
    update_option(
        'language_fts_playground_index_queue_lock',
        [
            'token' => 'external-lock-token',
            'expires_at' => microtime(true) + 10,
        ]
    );

    $private = fixture_post(900, 'en', 'Private orchard', '<p>orchard hidden</p>', 'private');
    Language_FTS_Playground_Plugin::index_saved_post(900, $private, true);
    $status = Language_FTS_Playground_Plugin::index_status();

    assert_same([], $storage->all_documents(), 'A contended queue cleanup does not leave the private post indexed.');
    assert_same([], get_option('language_fts_playground_index_queue', []), 'The contended private save does not perform an unlocked queue write.');
    assert_contains_text('Removed a non-public post from the Language FTS index.', (string) ($status['last_status'] ?? ''), 'The storage removal remains visible in status.');
});

test_case('contended delete removes a previously indexed document', function (): void {
    $storage = reset_language_fts_plugin_runtime();
    assert_true($storage instanceof Language_FTS_Playground_Test_Storage, 'Test storage is available.');
    $indexer = new Language_FTS_Playground_Indexer($storage, new Language_FTS_Playground_Analyzer());
    $indexer->index_post(fixture_post(901, 'en', 'Deleted orchard', '<p>orchard deleted</p>'));
    update_option(
        'language_fts_playground_index_queue_lock',
        [
            'token' => 'external-lock-token',
            'expires_at' => microtime(true) + 10,
        ]
    );

    Language_FTS_Playground_Plugin::delete_post(901);
    $status = Language_FTS_Playground_Plugin::index_status();

    assert_same([], $storage->all_documents(), 'A contended queue cleanup does not leave the deleted post indexed.');
    assert_same([], get_option('language_fts_playground_index_queue', []), 'The contended delete does not perform an unlocked queue write.');
    assert_contains_text('Removed a deleted post from the Language FTS index.', (string) ($status['last_status'] ?? ''), 'The storage removal remains visible in status.');
});

test_case('completion preserves queue IDs added between read and write', function (): void {
    $storage = reset_language_fts_plugin_runtime();
    assert_true($storage instanceof Language_FTS_Playground_Test_Storage, 'Test storage is available.');
    $first = fixture_post(501, 'en', 'Interleaved orchard one', '<p>orchard one</p>');
    $second = fixture_post(502, 'en', 'Interleaved orchard two', '<p>orchard two</p>');
    $GLOBALS['language_fts_test_posts'][501] = $first;
    $GLOBALS['language_fts_test_posts'][502] = $second;

    Language_FTS_Playground_Plugin::index_saved_post(501, $first, true);
    $queue_reads = 0;
    $interleaved = false;
    $GLOBALS['language_fts_test_get_option_interceptor'] = static function (string $name, int $read_count) use (&$queue_reads, &$interleaved): void {
        unset($read_count);
        if ($name !== 'language_fts_playground_index_queue') {
            return;
        }

        $queue_reads++;
        if ($queue_reads !== 3) {
            return;
        }

        $interleaved = true;
        $GLOBALS['language_fts_test_get_option_interceptor'] = null;
        Language_FTS_Playground_Plugin::enqueue_posts([502]);
    };

    $result = Language_FTS_Playground_Plugin::process_index_queue(1);
    $queue = get_option('language_fts_playground_index_queue', []);

    assert_same(1, $result['processed'], 'The original queued post is processed.');
    assert_same(true, $interleaved, 'The regression injected a queue write before completion wrote back.');
    assert_true(!array_key_exists(501, $queue), 'The completed post is removed from the queue.');
    assert_true(array_key_exists(502, $queue), 'The concurrently queued post remains queued.');
    assert_same(1, Language_FTS_Playground_Plugin::queued_count(), 'Only the interleaved queue item remains.');
});

test_case('completion preserves same-post requeues with newer tokens', function (): void {
    $storage = reset_language_fts_plugin_runtime();
    assert_true($storage instanceof Language_FTS_Playground_Test_Storage, 'Test storage is available.');
    $post = fixture_post(503, 'en', 'Retokened orchard', '<p>orchard token</p>');
    $GLOBALS['language_fts_test_posts'][503] = $post;

    Language_FTS_Playground_Plugin::index_saved_post(503, $post, true);
    $queue_reads = 0;
    $GLOBALS['language_fts_test_get_option_interceptor'] = static function (string $name, int $read_count) use (&$queue_reads): void {
        unset($read_count);
        if ($name !== 'language_fts_playground_index_queue') {
            return;
        }

        $queue_reads++;
        if ($queue_reads !== 3) {
            return;
        }

        $GLOBALS['language_fts_test_get_option_interceptor'] = null;
        $queue = get_option('language_fts_playground_index_queue', []);
        $queue[503] = 'newer-token';
        update_option('language_fts_playground_index_queue', $queue);
    };

    $result = Language_FTS_Playground_Plugin::process_index_queue(1);
    $queue = get_option('language_fts_playground_index_queue', []);

    assert_same(1, $result['processed'], 'The queued post is processed once.');
    assert_same('newer-token', $queue[503] ?? null, 'A same-post requeue with a newer token is not completed by the stale token.');
    assert_same(1, Language_FTS_Playground_Plugin::queued_count(), 'The requeued post remains queued.');
});

test_case('completion skips queue writes when the queue lock is contended', function (): void {
    $storage = reset_language_fts_plugin_runtime();
    assert_true($storage instanceof Language_FTS_Playground_Test_Storage, 'Test storage is available.');
    $processed = fixture_post(701, 'en', 'Contended orchard one', '<p>orchard one</p>');
    $interleaved = fixture_post(702, 'en', 'Contended orchard two', '<p>orchard two</p>');
    $GLOBALS['language_fts_test_posts'][701] = $processed;
    $GLOBALS['language_fts_test_posts'][702] = $interleaved;

    Language_FTS_Playground_Plugin::enqueue_posts([701]);
    update_option(
        'language_fts_playground_index_queue_lock',
        [
            'token' => 'external-lock-token',
            'expires_at' => microtime(true) + 10,
        ]
    );
    $GLOBALS['language_fts_test_scheduled'] = [];

    $lock_reads = 0;
    $interleaved_enqueue = false;
    $GLOBALS['language_fts_test_get_option_interceptor'] = static function (string $name, int $read_count) use (&$lock_reads, &$interleaved_enqueue): void {
        unset($read_count);
        if ($name !== 'language_fts_playground_index_queue_lock') {
            return;
        }

        $lock_reads++;
        if ($lock_reads !== 2) {
            return;
        }

        $interleaved_enqueue = true;
        $GLOBALS['language_fts_test_get_option_interceptor'] = null;

        // Simulate the external lock owner committing a queue append while this completion waits.
        $queue_lock_token_property = new ReflectionProperty(Language_FTS_Playground_Plugin::class, 'queue_lock_token');
        $queue_lock_token_property->setAccessible(true);
        $queue_lock_depth_property = new ReflectionProperty(Language_FTS_Playground_Plugin::class, 'queue_lock_depth');
        $queue_lock_depth_property->setAccessible(true);
        $queue_lock_token_property->setValue(null, 'external-lock-token');
        $queue_lock_depth_property->setValue(null, 1);
        try {
            Language_FTS_Playground_Plugin::enqueue_posts([702]);
        } finally {
            $queue_lock_token_property->setValue(null, null);
            $queue_lock_depth_property->setValue(null, 0);
        }
    };

    $result = Language_FTS_Playground_Plugin::process_index_queue(1);
    $queue = get_option('language_fts_playground_index_queue', []);
    $status = Language_FTS_Playground_Plugin::index_status();

    assert_same(1, $result['processed'], 'The original queued post is processed.');
    assert_same(true, $interleaved_enqueue, 'The regression injected a queue write while completion was blocked by the active lock.');
    assert_true(array_key_exists(701, $queue), 'The completed post remains queued because completion could not acquire the queue lock.');
    assert_true(array_key_exists(702, $queue), 'The externally queued post remains queued after the skipped completion attempt.');
    assert_same(2, $result['remaining'], 'Processing reports the remaining locked work instead of clearing the queue.');
    assert_same(2, $status['last_result']['remaining'] ?? null, 'Status records the remaining queue count for retry.');
    assert_true($GLOBALS['language_fts_test_scheduled'] !== [], 'Remaining work is scheduled for a later retry.');
});

test_case('idle queue processing does not clear required rebuild before rebuild is queued', function (): void {
    reset_language_fts_plugin_runtime();
    update_option('language_fts_playground_rebuild_required', true);
    update_option('language_fts_playground_rebuild_in_progress', false);

    $result = Language_FTS_Playground_Plugin::process_index_queue(10);

    assert_same(0, $result['processed'], 'No queue items are processed.');
    assert_same(0, $result['remaining'], 'The queue remains empty.');
    assert_same(true, get_option('language_fts_playground_rebuild_required'), 'A version-required rebuild is not cleared before queue_rebuild() runs.');
});

test_case('contended rebuild stays required after empty queue processing', function (): void {
    $storage = reset_language_fts_plugin_runtime();
    assert_true($storage instanceof Language_FTS_Playground_Test_Storage, 'Test storage is available.');
    $post = fixture_post(902, 'en', 'Rebuild contention orchard', '<p>orchard rebuild lock</p>');
    $GLOBALS['language_fts_test_posts'][902] = $post;
    $indexer = new Language_FTS_Playground_Indexer($storage, new Language_FTS_Playground_Analyzer());
    $indexer->index_post($post);
    update_option(
        'language_fts_playground_index_queue_lock',
        [
            'token' => 'external-lock-token',
            'expires_at' => microtime(true) + 10,
        ]
    );

    Language_FTS_Playground_Plugin::rebuild_index();

    assert_same(0, $storage->clear_count, 'The index is not cleared until rebuild queue replacement can acquire the lock.');
    assert_same(1, count($storage->all_documents()), 'Existing indexed documents remain active until a rebuild queue is durably written.');
    assert_same([], get_option('language_fts_playground_index_queue', []), 'The contended rebuild does not perform an unlocked queue replacement.');
    assert_same(true, get_option('language_fts_playground_rebuild_required'), 'The failed rebuild queue replacement remains visibly required.');
    assert_same(false, get_option('language_fts_playground_rebuild_in_progress', false), 'A rebuild is not marked in progress before its queue is written.');
    assert_same([], $GLOBALS['language_fts_test_scheduled'], 'A contended rebuild does not schedule an empty queue.');

    delete_option('language_fts_playground_index_queue_lock');
    $result = Language_FTS_Playground_Plugin::process_index_queue(10);

    assert_same(0, $result['processed'], 'No queue items are processed after the contended rebuild failed to queue work.');
    assert_same(0, $result['indexed'], 'No posts are falsely indexed by empty queue processing.');
    assert_same(true, get_option('language_fts_playground_rebuild_required'), 'Empty queue processing does not clear the required rebuild.');
    assert_same(false, get_option('language_fts_playground_rebuild_in_progress', false), 'Empty queue processing does not fabricate or complete an in-progress rebuild.');
});

test_case('version rebuild stays required until bounded queue drains', function (): void {
    $storage = reset_language_fts_plugin_runtime();
    assert_true($storage instanceof Language_FTS_Playground_Test_Storage, 'Test storage is available.');
    $GLOBALS['language_fts_test_posts'][601] = fixture_post(601, 'en', 'Rebuild orchard one', '<p>orchard one</p>');
    $GLOBALS['language_fts_test_posts'][602] = fixture_post(602, 'en', 'Rebuild orchard two', '<p>orchard two</p>');

    $queued = Language_FTS_Playground_Plugin::queue_rebuild(true);
    assert_same(2, $queued, 'Both published posts are queued for rebuild.');
    assert_same(true, get_option('language_fts_playground_rebuild_required'), 'A queued rebuild remains marked as required.');
    assert_same(true, get_option('language_fts_playground_rebuild_in_progress'), 'A queued rebuild is tracked as in progress.');

    $first_batch = Language_FTS_Playground_Plugin::process_index_queue(1);
    assert_same(1, $first_batch['processed'], 'A bounded rebuild batch processes one item.');
    assert_same(1, $first_batch['remaining'], 'One rebuild item remains after the bounded batch.');
    assert_same(true, get_option('language_fts_playground_rebuild_required'), 'The rebuild-required flag remains true while queued work remains.');
    assert_same(true, get_option('language_fts_playground_rebuild_in_progress'), 'The rebuild remains in progress while queued work remains.');

    $second_batch = Language_FTS_Playground_Plugin::process_index_queue(10);
    assert_same(1, $second_batch['processed'], 'The final rebuild item is processed.');
    assert_same(0, $second_batch['remaining'], 'The rebuild queue drains.');
    assert_same(false, get_option('language_fts_playground_rebuild_required'), 'The rebuild-required flag clears only after a successful drain.');
    assert_same(false, get_option('language_fts_playground_rebuild_in_progress'), 'The in-progress marker clears after a successful drain.');
});

test_case('version rebuild stays required when queue processing fails', function (): void {
    reset_language_fts_plugin_runtime(new Language_FTS_Playground_Test_Failing_Storage('Index writes are temporarily unavailable.'));
    $GLOBALS['language_fts_test_posts'][603] = fixture_post(603, 'en', 'Failing rebuild orchard', '<p>orchard failure</p>');

    $queued = Language_FTS_Playground_Plugin::queue_rebuild(false);
    assert_same(1, $queued, 'The published post is queued for rebuild without clearing storage.');
    assert_same(true, get_option('language_fts_playground_rebuild_required'), 'A queued rebuild starts as required.');
    assert_same(true, get_option('language_fts_playground_rebuild_in_progress'), 'A queued rebuild starts in progress.');

    $result = Language_FTS_Playground_Plugin::process_index_queue(1);
    $status = Language_FTS_Playground_Plugin::index_status();

    assert_same(1, $result['failed'], 'The failing storage path reports a failed queue item.');
    assert_same(1, $result['remaining'], 'The failed queue item remains queued for retry.');
    assert_same(true, get_option('language_fts_playground_rebuild_required'), 'The rebuild-required flag stays true after a failed rebuild item.');
    assert_same(true, get_option('language_fts_playground_rebuild_in_progress'), 'The in-progress marker stays true after a failed rebuild item.');
    assert_contains_text('Index writes are temporarily unavailable.', (string) ($status['last_error'] ?? ''), 'The rebuild failure remains visible in status.');
});

test_case('removes non-public lifecycle states and ignores revisions and autosaves', function (): void {
    $storage = reset_language_fts_plugin_runtime();
    assert_true($storage instanceof Language_FTS_Playground_Test_Storage, 'Test storage is available.');
    $indexer = new Language_FTS_Playground_Indexer($storage, new Language_FTS_Playground_Analyzer());

    foreach (['draft', 'private', 'trash'] as $status) {
        $published = fixture_post(300, 'en', 'Published orchard', '<p>orchard visible</p>');
        $indexer->index_post($published);
        assert_same(1, count($storage->all_documents()), 'The fixture starts indexed.');

        $non_public = fixture_post(300, 'en', 'Hidden orchard', '<p>orchard hidden</p>', $status);
        Language_FTS_Playground_Plugin::transition_post_status($status, 'publish', $non_public);

        assert_same([], $storage->all_documents(), "{$status} posts are removed from the index.");
        assert_same(0, Language_FTS_Playground_Plugin::queued_count(), "{$status} posts are not left in the queue.");
    }

    $indexer->index_post(fixture_post(301, 'en', 'Password orchard', '<p>orchard protected</p>'));
    $passworded = fixture_post(301, 'en', 'Password orchard', '<p>orchard protected</p>', 'publish', 'post', 'secret');
    Language_FTS_Playground_Plugin::index_saved_post(301, $passworded, true);
    assert_same([], $storage->all_documents(), 'Password-protected published posts are removed from the index.');

    $revision = fixture_post(302, 'en', 'Revision orchard', '<p>orchard revision</p>');
    $GLOBALS['language_fts_test_revisions'][302] = true;
    Language_FTS_Playground_Plugin::index_saved_post(302, $revision, true);
    assert_same(0, Language_FTS_Playground_Plugin::queued_count(), 'Revisions are ignored.');

    $autosave = fixture_post(303, 'en', 'Autosave orchard', '<p>orchard autosave</p>');
    $GLOBALS['language_fts_test_autosaves'][303] = true;
    Language_FTS_Playground_Plugin::index_saved_post(303, $autosave, true);
    assert_same(0, Language_FTS_Playground_Plugin::queued_count(), 'Autosaves are ignored.');

    $indexer->index_post(fixture_post(304, 'en', 'Deleted orchard', '<p>orchard deleted</p>'));
    Language_FTS_Playground_Plugin::delete_post(304);
    assert_same([], $storage->all_documents(), 'Deleted posts are removed from the index.');
});

test_case('renders admin lifecycle controls and protects destructive actions', function (): void {
    $storage = reset_language_fts_plugin_runtime();
    assert_true($storage instanceof Language_FTS_Playground_Test_Storage, 'Test storage is available.');
    Language_FTS_Playground_Plugin::register_hooks();

    assert_true(isset($GLOBALS['language_fts_test_actions']['language_fts_playground_process_queue']), 'Cron queue processor hook is registered.');
    assert_true(isset($GLOBALS['language_fts_test_actions']['admin_post_language_fts_playground_process_queue']), 'Admin process queue action is registered.');
    assert_true(isset($GLOBALS['language_fts_test_actions']['admin_post_language_fts_playground_clear_index']), 'Admin clear index action is registered.');

    $post = fixture_post(401, 'en', 'Queued admin orchard', '<p>orchard admin</p>');
    $GLOBALS['language_fts_test_posts'][401] = $post;
    Language_FTS_Playground_Plugin::index_saved_post(401, $post, true);

    ob_start();
    Language_FTS_Playground_Plugin::render_admin_page();
    $html = ob_get_clean();

    assert_contains_text('Queued posts', $html, 'Admin status shows queued count.');
    assert_contains_text('Process queue', $html, 'Admin page exposes manual queue processing.');
    assert_contains_text('Clear index', $html, 'Admin page exposes a clear-index control.');
    assert_contains_text('Rebuild index', $html, 'Admin page keeps a rebuild control.');

    $GLOBALS['language_fts_test_current_user_can'] = false;
    try {
        Language_FTS_Playground_Plugin::handle_clear_action();
    } catch (RuntimeException $exception) {
        assert_contains_text('permission', strtolower($exception->getMessage()), 'Capability failure is surfaced.');
    }

    assert_same(0, $storage->clear_count, 'Clear index does not run without the required capability.');
});

test_case('surfaces storage failures in admin without fataling the page', function (): void {
    reset_language_fts_plugin_runtime(new Language_FTS_Playground_Test_Failing_Storage('Stored rows are temporarily unavailable.'));

    ob_start();
    Language_FTS_Playground_Plugin::render_admin_page();
    $html = ob_get_clean();

    assert_contains_text('Language FTS Playground', $html, 'The admin page still renders its shell.');
    assert_contains_text('Stored rows are temporarily unavailable.', $html, 'Storage errors are shown as admin notices.');
});

$failures = 0;
foreach ($tests as $test) {
    try {
        $test['fn']();
        echo "PASS {$test['name']}\n";
    } catch (Throwable $throwable) {
        $failures++;
        echo "FAIL {$test['name']}\n{$throwable->getMessage()}\n";
    }
}

if ($failures > 0) {
    exit(1);
}

echo 'All ' . count($tests) . " Language FTS Playground tests passed.\n";
