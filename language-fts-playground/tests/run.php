<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';

final class Language_FTS_Playground_Test_Failure extends RuntimeException
{
}

final class Language_FTS_Playground_Test_Storage implements Language_FTS_Playground_Storage_Interface
{
    /** @var array<int,array{post_id:int,language:string,title:string,status:string,document_length:int,field_texts:array<string,string>,updated_at:string}> */
    private array $documents = [];

    /** @var array<string,array<string,array<int,array<string,int>>>> */
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
        array $field_term_frequencies,
        array $field_texts
    ): void {
        $this->delete_document($post_id);
        $this->documents[$post_id] = [
            'post_id' => $post_id,
            'language' => $language,
            'title' => $title,
            'status' => $status,
            'document_length' => max(1, $document_length),
            'field_texts' => $field_texts,
            'updated_at' => 'test',
        ];

        foreach ($field_term_frequencies as $field => $term_frequencies) {
            foreach ($term_frequencies as $term => $tf) {
                $term = (string) $term;
                $field = (string) $field;
                $this->postings[$language][$term][$post_id][$field] = max(1, (int) $tf);
            }
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

    public function fetch_document_fields(string $language, array $post_ids): array
    {
        $fields = [];
        foreach ($post_ids as $post_id) {
            $post_id = (int) $post_id;
            if (($this->documents[$post_id]['language'] ?? null) === $language) {
                $fields[$post_id] = $this->documents[$post_id]['field_texts'];
            }
        }

        return $fields;
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

function fixture_post(int $id, string $language, string $title, string $content, string $excerpt = ''): object
{
    return (object) [
        'ID' => $id,
        'post_status' => 'publish',
        'post_title' => $title,
        'post_excerpt' => $excerpt,
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

test_case('ranks title hits above equal content hits', function (): void {
    $storage = new Language_FTS_Playground_Test_Storage();
    $analyzer = new Language_FTS_Playground_Analyzer();
    $indexer = new Language_FTS_Playground_Indexer($storage, $analyzer);
    $searcher = new Language_FTS_Playground_Searcher($storage, $analyzer);

    $indexer->index_post(fixture_post(41, 'en', 'Body match', '<p>orchard plain visible text</p>'));
    $indexer->index_post(fixture_post(42, 'en', 'Orchard title', '<p>plain visible text only</p>'));

    $results = $searcher->search('orchard', 'en');

    assert_true(count($results) >= 2, 'Both English documents match.');
    assert_same(42, $results[0]['post_id'], 'A title hit outranks an equal content hit even with the higher post ID.');
    assert_same(['title'], $results[0]['matched_fields'], 'The top result reports the title field.');
    assert_same(['content'], $results[1]['matched_fields'], 'The second result reports the content field.');
});

test_case('reports excerpt field matches with highlighted excerpt snippets', function (): void {
    $storage = new Language_FTS_Playground_Test_Storage();
    $analyzer = new Language_FTS_Playground_Analyzer();
    $indexer = new Language_FTS_Playground_Indexer($storage, $analyzer);
    $searcher = new Language_FTS_Playground_Searcher($storage, $analyzer);

    $indexer->index_post(fixture_post(45, 'en', 'Excerpt match', '<p>Visible meadow only</p>', 'Orchard summary text'));

    $results = $searcher->search('orchard', 'en');

    assert_same(45, $results[0]['post_id'], 'The query matches the indexed excerpt field.');
    assert_same(['excerpt'], $results[0]['matched_fields'], 'The result reports the excerpt field.');
    assert_contains_text('<mark>Orchard</mark>', $results[0]['snippet'], 'The excerpt snippet highlights the matched term.');
});

test_case('returns escaped highlighted snippets for stem-key matches', function (): void {
    $storage = new Language_FTS_Playground_Test_Storage();
    $analyzer = new Language_FTS_Playground_Analyzer();
    $indexer = new Language_FTS_Playground_Indexer($storage, $analyzer);
    $searcher = new Language_FTS_Playground_Searcher($storage, $analyzer);

    $indexer->index_post(fixture_post(
        50,
        'en',
        'Safe snippet',
        '<p>Stories keep unsafe &lt;script&gt;alert(1)&lt;/script&gt; text visible.</p>'
    ));

    $results = $searcher->search('story', 'en');

    assert_same(50, $results[0]['post_id'], 'The English suffix key matches an inflected visible term.');
    assert_contains_text('<mark>Stories</mark>', $results[0]['snippet'], 'The snippet highlights the raw inflected source term.');
    assert_contains_text('&lt;script&gt;alert(1)&lt;/script&gt;', $results[0]['snippet'], 'Unsafe-looking source text is escaped in snippets.');
    assert_not_contains_text('<script>', $results[0]['snippet'], 'Snippets do not emit unsafe raw HTML from post content.');
});

test_case('reports alt field matches with highlighted alt snippets', function (): void {
    $storage = new Language_FTS_Playground_Test_Storage();
    $analyzer = new Language_FTS_Playground_Analyzer();
    $indexer = new Language_FTS_Playground_Indexer($storage, $analyzer);
    $searcher = new Language_FTS_Playground_Searcher($storage, $analyzer);

    $indexer->index_post(fixture_post(60, 'en', 'Alt only', '<p>Visible meadow</p><img alt="falcon stories beside the image" />'));

    $results = $searcher->search('story', 'en');

    assert_same(60, $results[0]['post_id'], 'The English suffix key matches image alt text.');
    assert_same(['alt'], $results[0]['matched_fields'], 'The result reports the alt field.');
    assert_contains_text('<mark>stories</mark>', $results[0]['snippet'], 'The alt snippet highlights the inflected alt term.');
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
