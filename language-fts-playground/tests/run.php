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

function assert_query_terms_overlap(Language_FTS_Playground_Analyzer $analyzer, string $document_text, string $query, string $message): void
{
    $document_terms = $analyzer->analyze_text($document_text, 'pl');
    $query_terms = $analyzer->analyze_query($query, 'pl');

    assert_true(
        array_values(array_intersect($document_terms, $query_terms)) !== [],
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
    assert_same(['fuer', 'fuehrung', 'strasse'], $analyzer->analyze_text('für Führung Straße', 'de'), 'German umlauts are folded.');
});

test_case('adds conservative Polish inflection keys without broad short-token stems', function (): void {
    $analyzer = new Language_FTS_Playground_Analyzer();
    $document_text = 'polskiej partycji wyszukiwania';

    assert_query_terms_overlap($analyzer, $document_text, 'polska', 'Polish adjective form shares a key with its inflected form.');
    assert_query_terms_overlap($analyzer, $document_text, 'partycja', 'Polish noun form shares a key with its inflected form.');
    assert_query_terms_overlap($analyzer, $document_text, 'wyszukiwanie', 'Polish verbal noun form shares a key with its inflected form.');
    assert_same(['ma', 'w', 'do'], $analyzer->analyze_text('ma w do', 'pl'), 'Very short Polish tokens are not broadened into noisy stems.');
});

test_case('indexes alt text and searches only the requested language partition', function (): void {
    $storage = new Language_FTS_Playground_Test_Storage();
    $analyzer = new Language_FTS_Playground_Analyzer();
    $indexer = new Language_FTS_Playground_Indexer($storage, $analyzer);
    $searcher = new Language_FTS_Playground_Searcher($storage, $analyzer);

    $indexer->index_post(fixture_post(1, 'en', 'English', '<p>The orchard is visible.</p><img alt="falconalt" />'));
    $indexer->index_post(fixture_post(2, 'pl', 'Polish', '<p>Łódź jest widoczna.</p>'));

    assert_same([1], array_column($searcher->search('falconalt', 'en'), 'post_id'), 'Image alt text is searchable.');
    assert_same([2], array_column($searcher->search('lodz', 'pl'), 'post_id'), 'Folded Polish query matches Polish content.');
    assert_same([], $searcher->search('lodz', 'en'), 'English partition does not return Polish content.');
    assert_same([], $searcher->search('orchard', 'pl'), 'Polish partition does not return English content.');
});

test_case('matches Polish demo inflections only inside the Polish language partition', function (): void {
    $storage = new Language_FTS_Playground_Test_Storage();
    $analyzer = new Language_FTS_Playground_Analyzer();
    $indexer = new Language_FTS_Playground_Indexer($storage, $analyzer);
    $searcher = new Language_FTS_Playground_Searcher($storage, $analyzer);

    $indexer->index_post(
        fixture_post(20, 'pl', 'Polish demo', '<p>Łódź ma widoczny akapit w polskiej partycji wyszukiwania.</p>')
    );

    foreach (['polska', 'partycja', 'wyszukiwanie', 'lodz'] as $query) {
        assert_same([20], array_column($searcher->search($query, 'pl'), 'post_id'), "{$query} matches the Polish demo post.");
        assert_same([], $searcher->search($query, 'en'), "{$query} does not leak into the English partition.");
    }
});

test_case('applies Polish inflection keys to image alt text', function (): void {
    $storage = new Language_FTS_Playground_Test_Storage();
    $analyzer = new Language_FTS_Playground_Analyzer();
    $indexer = new Language_FTS_Playground_Indexer($storage, $analyzer);
    $searcher = new Language_FTS_Playground_Searcher($storage, $analyzer);

    $indexer->index_post(fixture_post(30, 'pl', 'Polish alt text', '<figure><img alt="polskiej fotografii" /></figure>'));

    assert_same([30], array_column($searcher->search('polska', 'pl'), 'post_id'), 'Inflected Polish alt adjective is searchable by base query.');
    assert_same([30], array_column($searcher->search('fotografia', 'pl'), 'post_id'), 'Inflected Polish alt noun is searchable by base query.');
    assert_same([], $searcher->search('fotografia', 'en'), 'Alt-text Polish inflection keys remain language-partitioned.');
});

test_case('does not match Polish inflections that appear only in markup noise', function (): void {
    $storage = new Language_FTS_Playground_Test_Storage();
    $analyzer = new Language_FTS_Playground_Analyzer();
    $indexer = new Language_FTS_Playground_Indexer($storage, $analyzer);
    $searcher = new Language_FTS_Playground_Searcher($storage, $analyzer);

    $indexer->index_post(
        fixture_post(
            40,
            'pl',
            'Markup noise',
            '<article class="partycji" id="polskiej">' .
            '<p>Neutralny widoczny tekst.</p>' .
            '<style>.partycji{content:"polskiej";}</style>' .
            '<script>const partycji = "polskiej";</script>' .
            '<!-- wyszukiwania partycji polskiej -->' .
            '</article>'
        )
    );

    assert_same([], $searcher->search('partycja', 'pl'), 'Class, id, CSS, script, and comment text are not searchable.');
    assert_same([], $searcher->search('polska', 'pl'), 'Polish normalization does not reintroduce excluded markup noise.');
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
