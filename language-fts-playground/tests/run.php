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

    public function install(): void
    {
    }

    public function clear(): void
    {
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

function fixture_post(int $id, string $language, string $title, string $content): object
{
    return (object) [
        'ID' => $id,
        'post_status' => 'publish',
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
    assert_same('mueller', $analyzer->normalize_term('Müller', 'de'), 'German umlauts are folded.');
    assert_same('strasse', $analyzer->normalize_term('Straße', 'de'), 'German sharp s is folded.');
});

test_case('removes English stopwords and stems common English forms conservatively', function (): void {
    $analyzer = new Language_FTS_Playground_Analyzer();

    assert_same([], $analyzer->analyze_text('the and of to in a an is are was were by for with', 'en'), 'Common English stopwords are not indexed.');
    assert_same(['runner'], $analyzer->analyze_text("the runner's and of", 'en'), 'English possessive noise and stopwords are removed.');
    assert_query_terms_overlap($analyzer, 'en', 'searching searched searches', 'search', 'English regular verb forms share a stem key.');
    assert_query_terms_overlap($analyzer, 'en', 'stories skies', 'story sky', 'English y/ies plural forms share stem keys.');
    assert_query_terms_overlap($analyzer, 'en', 'making baked boxes buses', 'make bake box bus', 'English dropped-e and es plural forms share stem keys.');
    assert_query_terms_overlap($analyzer, 'en', 'running stopped', 'run stop', 'English doubled-consonant verb forms are guarded and stemmed.');
    assert_query_terms_overlap($analyzer, 'en', 'children people', 'child person', 'Guarded English irregular examples share stem keys.');
    assert_query_terms_do_not_overlap($analyzer, 'en', 'runner', 'run', 'Agent nouns do not collapse to short verb stems.');
    assert_query_terms_do_not_overlap($analyzer, 'en', 'university', 'universe', 'English y-ending words are not broadly conflated.');
    assert_same(['news', 'bus', 'analysis'], $analyzer->analyze_text('news bus analysis', 'en'), 'Sensitive English words remain exact.');
});

test_case('removes German stopwords and stems German forms conservatively', function (): void {
    $analyzer = new Language_FTS_Playground_Analyzer();

    assert_same([], $analyzer->analyze_text('der die das und in im zu am fuer von mit ein eine einer', 'de'), 'Common German stopwords are not indexed.');
    assert_query_terms_overlap($analyzer, 'de', 'deutschen deutscher deutsche deutsches', 'deutsch', 'German adjective forms share a stem key.');
    assert_query_terms_overlap($analyzer, 'de', 'schnelle schnellen schneller schnellem', 'schnell', 'German adjective suffixes are normalized.');
    assert_query_terms_overlap($analyzer, 'de', 'Führungen Straßen Kindern', 'fuehrung strasse kind', 'German noun plurals after folding share stem keys.');
    assert_query_terms_overlap($analyzer, 'de', 'Bäume Häuser', 'baum haus', 'German umlauted noun plurals share conservative singular keys.');
    assert_query_terms_overlap($analyzer, 'de', 'spielen spielte gespielt', 'spiel', 'German common verb endings and ge- participles share stem keys.');
    assert_query_terms_do_not_overlap($analyzer, 'de', 'gespielt', 'gespiel', 'German ge-participles do not add noisy intermediate stems.');
    assert_query_terms_do_not_overlap($analyzer, 'de', 'artig', 'art', 'German ig-adjectives do not collapse to short nouns.');
    assert_same(['arm', 'arme'], $analyzer->analyze_text('arm arme', 'de'), 'Short German tokens stay guarded from broad stemming.');
});

test_case('removes Polish stopwords and stems Polish forms conservatively', function (): void {
    $analyzer = new Language_FTS_Playground_Analyzer();
    $document_text = 'polskiej polskimi partycji partiami wyszukiwania wyszukiwarkach fotografiami';

    assert_same([], $analyzer->analyze_text('w i oraz na do z ze ma pod po dla', 'pl'), 'Common Polish stopwords are not indexed.');
    assert_query_terms_overlap($analyzer, 'pl', $document_text, 'polska', 'Polish adjective form shares a key with its inflected form.');
    assert_query_terms_overlap($analyzer, 'pl', $document_text, 'partycja', 'Polish noun form shares a key with its inflected form.');
    assert_query_terms_overlap($analyzer, 'pl', $document_text, 'wyszukiwanie', 'Polish verbal noun form shares a key with its inflected form.');
    assert_query_terms_overlap($analyzer, 'pl', 'domami domach domem domu', 'dom', 'Short but meaningful Polish nouns keep guarded stems.');
    assert_query_terms_overlap($analyzer, 'pl', 'zielonymi zielonego zielonych', 'zielony', 'Polish adjective endings share conservative stem keys.');
    assert_same(['ul', 'rok'], $analyzer->analyze_text('ul w rok i', 'pl'), 'Short non-stopword Polish tokens remain exact.');
    assert_query_terms_do_not_overlap($analyzer, 'pl', 'rama', 'ram', 'Polish short stems are guarded from broad final-vowel trimming.');
});

test_case('uses one analyzer path for indexed documents and queries with stopwords', function (): void {
    $storage = new Language_FTS_Playground_Test_Storage();
    $analyzer = new Language_FTS_Playground_Analyzer();
    $indexer = new Language_FTS_Playground_Indexer($storage, $analyzer);
    $searcher = new Language_FTS_Playground_Searcher($storage, $analyzer);

    $indexer->index_post(fixture_post(20, 'en', 'Stopword path', '<p>The orchard and the running paths are visible.</p>'));

    assert_same([], $searcher->search('the and of', 'en'), 'Stopword-only English queries produce no matches.');
    assert_same([20], array_column($searcher->search('the run and orchard', 'en'), 'post_id'), 'Mixed English queries use the same stem and stopword keys as indexing.');
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
            'fold_query' => 'strasse',
            'noise_query' => 'ghostgerman',
            'content' =>
                '<article class="ghostgerman" id="ghostgerman">' .
                '<p>Die deutschen Beispiele zeigen Führungen und Straßen beim Suchen nach sichtbaren Treffern. Foreign bait: searching polskiej.</p>' .
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

test_case('keeps seeded demo sample searches aligned with analyzer behavior', function (): void {
    $storage = new Language_FTS_Playground_Test_Storage();
    $analyzer = new Language_FTS_Playground_Analyzer();
    $indexer = new Language_FTS_Playground_Indexer($storage, $analyzer);
    $searcher = new Language_FTS_Playground_Searcher($storage, $analyzer);

    foreach (Language_FTS_Playground_Demo::demo_posts() as $offset => $demo_post) {
        $indexer->index_post(fixture_post(
            300 + $offset,
            $demo_post['language'],
            $demo_post['title'],
            $demo_post['content']
        ));
    }

    foreach (Language_FTS_Playground_Demo::sample_searches() as $sample) {
        $results = $searcher->search($sample['query'], $sample['language']);
        if ($sample['query'] === 'ghostmarkup') {
            assert_same([], $results, 'The markup-noise sample remains a negative example.');
            continue;
        }

        assert_true($results !== [], "Sample search {$sample['label']} returns at least one seeded demo result.");
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
