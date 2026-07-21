<?php
declare(strict_types=1);

$wp_fts_alc_direct = !function_exists('test_case');
if ($wp_fts_alc_direct) {
    require_once dirname(__DIR__, 2) . '/src/bootstrap.php';

    final class WP_FTS_TestFailure extends RuntimeException
    {
    }

    if (!class_exists('WP_FTS_Fake_HTML_Processor')) {
        final class WP_FTS_Fake_HTML_Processor
        {
            private int $offset = -1;

            /**
             * @param array<int,array{type:string,tag?:string,breadcrumbs?:string[],text?:string,attrs?:array<string,string>,closing?:bool}> $tokens
             */
            public function __construct(private array $tokens)
            {
            }

            public function next_token(): bool
            {
                $this->offset++;

                return isset($this->tokens[$this->offset]);
            }

            public function get_token_type(): ?string
            {
                return $this->current()['type'] ?? null;
            }

            /**
             * @return string[]|null
             */
            public function get_breadcrumbs(): ?array
            {
                return $this->current()['breadcrumbs'] ?? [];
            }

            /** Derive stream depth without retaining a separate parser stack. */
            public function get_current_depth(): int
            {
                return max(0, count($this->current()['breadcrumbs'] ?? []) - ($this->is_tag_closer() ? 1 : 0));
            }

            public function get_modifiable_text(): string
            {
                return (string) ($this->current()['text'] ?? '');
            }

            /** Recover synthetic tags from breadcrumbs when the fixture omits one. */
            public function get_tag(): ?string
            {
                $tag = $this->current()['tag'] ?? null;
                if (!is_string($tag) || $tag === '') {
                    $breadcrumbs = $this->current()['breadcrumbs'] ?? [];
                    $tag = is_array($breadcrumbs) && $breadcrumbs !== [] ? end($breadcrumbs) : null;
                }

                return is_string($tag) && $tag !== '' && $tag[0] !== '#'
                    ? strtoupper($tag)
                    : null;
            }

            public function is_tag_closer(): bool
            {
                return (bool) ($this->current()['closing'] ?? false);
            }

            /** Match HTML void-element semantics in the synthetic event stream. */
            public function expects_closer(): bool
            {
                return !$this->is_tag_closer()
                    && !in_array($this->get_tag(), ['AREA', 'BASE', 'BR', 'COL', 'EMBED', 'HR', 'IMG', 'INPUT', 'LINK', 'META', 'PARAM', 'SOURCE', 'TRACK', 'WBR'], true);
            }

            public function get_attribute(string $name): mixed
            {
                return ($this->current()['attrs'] ?? [])[$name] ?? null;
            }

            /**
             * @return array{type?:string,tag?:string,breadcrumbs?:string[],text?:string,attrs?:array<string,string>,closing?:bool}
             */
            private function current(): array
            {
                return $this->tokens[$this->offset] ?? [];
            }
        }
    }

    /** @var array<int,array{name:string,fn:callable}> */
    $GLOBALS['wp_fts_alc_tests'] = [];
    $GLOBALS['wp_fts_alc_check_count'] = 0;

    function test_case(string $name, callable $fn): void
    {
        $GLOBALS['wp_fts_alc_tests'][] = ['name' => $name, 'fn' => $fn];
    }

    function record_check(?string $label = null, int $count = 1): void
    {
        if ($count < 1) {
            throw new WP_FTS_TestFailure('record_check() count must be at least 1.');
        }

        $GLOBALS['wp_fts_alc_check_count'] += $count;
    }

    function executed_check_count(): int
    {
        return (int) $GLOBALS['wp_fts_alc_check_count'];
    }

    function assert_true(bool $condition, string $message): void
    {
        record_check($message);
        if (!$condition) {
            throw new WP_FTS_TestFailure($message);
        }
    }

    function assert_same(mixed $expected, mixed $actual, string $message): void
    {
        record_check($message);
        if ($expected !== $actual) {
            throw new WP_FTS_TestFailure($message . "\nExpected: " . var_export($expected, true) . "\nActual: " . var_export($actual, true));
        }
    }

    /**
     * @return string[]
     */
    function test_terms(array $occurrences): array
    {
        return array_map(static fn(array|string $token): string => is_array($token) ? (string) $token['term'] : $token, $occurrences);
    }

    function test_normalize_without_mbstring(WP_FTS_Normalizer $normalizer, string $token, string $language): string
    {
        $language = $normalizer->canonicalize_language($language);

        $lowercase = new ReflectionMethod($normalizer, 'lowercase_without_mbstring');
        $lowercase->setAccessible(true);
        $fold = new ReflectionMethod($normalizer, 'fold_for_language');
        $fold->setAccessible(true);

        return (string) $fold->invoke(
            $normalizer,
            (string) $lowercase->invoke($normalizer, $token, $language),
            $language
        );
    }
}

/**
 * @param array<int,array{term:string,weight?:float,lang?:string}> $occurrences
 * @return array<string,string[]>
 */
function wp_fts_alc_langs_by_term(array $occurrences): array
{
    $langs = [];
    foreach ($occurrences as $occurrence) {
        $term = (string) $occurrence['term'];
        $lang = (string) ($occurrence['lang'] ?? '');
        if ($lang === '') {
            continue;
        }

        $langs[$term][$lang] = true;
    }

    $result = [];
    foreach ($langs as $term => $termLangs) {
        $result[$term] = array_keys($termLangs);
        sort($result[$term], SORT_STRING);
    }
    ksort($result, SORT_STRING);

    return $result;
}

/**
 * @param array<int,array{term:string,weight?:float,lang?:string}> $occurrences
 * @return string[]
 */
function wp_fts_alc_occurrence_signature(array $occurrences): array
{
    return array_map(
        static fn(array $occurrence): string => implode('|', [
            (string) $occurrence['term'],
            (string) ($occurrence['lang'] ?? ''),
            number_format((float) ($occurrence['weight'] ?? 1.0), 1, '.', ''),
        ]),
        $occurrences
    );
}

test_case('quality analyzer language corpus starts lane check accounting', function (): void {
    $GLOBALS['wp_fts_analyzer_language_corpus_start'] = executed_check_count();
    assert_true(true, 'lane check accounting should start after trunk tests have executed');
});

test_case('quality corpus routes inherited element languages across HTML shapes', function (): void {
    $analyzer = new WP_FTS_Analyzer(['default_lang' => 'en-US']);
    $cases = [
        [
            'label' => 'document default and explicit close',
            'html' => '<article><p>helloalpha</p><section lang="pl">matkapl</section><p>fatheren</p></article>',
            'expected' => [
                'helloalpha' => ['en-US'],
                'matkapl' => ['pl'],
                'fatheren' => ['en-US'],
            ],
        ],
        [
            'label' => 'xml lang on block',
            'html' => '<main xml:lang="de-DE"><p>Straße</p><p>Ärger</p></main>',
            'expected' => [
                'strasse' => ['de-DE'],
                'aerger' => ['de-DE'],
            ],
        ],
        [
            'label' => 'nested override restores parent',
            'html' => '<section lang=pl>zamekpl <span lang=en-GB>colourgb</span> lodzpl</section>',
            'expected' => [
                'zamekpl' => ['pl'],
                'colourgb' => ['en-GB'],
                'lodzpl' => ['pl'],
            ],
        ],
        [
            'label' => 'omitted paragraph close restores sibling',
            'html' => '<p lang=pl>akapitpl<p>paragraphen',
            'expected' => [
                'akapitpl' => ['pl'],
                'paragraphen' => ['en-US'],
            ],
        ],
        [
            'label' => 'explicit paragraph close restores sibling',
            'html' => '<p lang=tr-TR>İzmir</p><p>plainen</p>',
            'expected' => [
                'izmir' => ['tr-TR'],
                'plainen' => ['en-US'],
            ],
        ],
        [
            'label' => 'list item optional close restores ul language',
            'html' => '<ul lang=pl><li>pierwszy<li lang=de>straße<li>drugi</ul>',
            'expected' => [
                'pierwszy' => ['pl'],
                'strasse' => ['de'],
                'drugi' => ['pl'],
            ],
        ],
        [
            'label' => 'table cells and rows restore parent table language',
            'html' => '<table lang=pl><tr><td>komorka<td lang=tr>İstanbul<tr><td>powrot</table>',
            'expected' => [
                'komorka' => ['pl'],
                'istanbul' => ['tr'],
                'powrot' => ['pl'],
            ],
        ],
        [
            'label' => 'mixed CJK and Latin blocks',
            'html' => '<article lang=zh-Hans><p>中文搜索</p><section lang=en-GB>colour</section><p>搜索</p></article>',
            'expected' => [
                '中' => ['zh-Hans'],
                '文' => ['zh-Hans'],
                '搜' => ['zh-Hans'],
                '索' => ['zh-Hans'],
                '中文' => ['zh-Hans'],
                '文搜' => ['zh-Hans'],
                '搜索' => ['zh-Hans'],
                '中文搜' => ['zh-Hans'],
                '文搜索' => ['zh-Hans'],
                '中文搜索' => ['zh-Hans'],
                'color' => ['en-GB'],
            ],
        ],
        [
            'label' => 'malformed child language falls back to parent',
            'html' => '<section lang=pl><span lang="C.UTF-8">zamekpl</span><b>lodzpl</b></section>',
            'expected' => [
                'zamekpl' => ['pl'],
                'lodzpl' => ['pl'],
            ],
        ],
        [
            'label' => 'empty language attribute falls back to document',
            'html' => '<div lang=""><p>emptyfallback</p></div>',
            'expected' => [
                'emptyfallback' => ['en-US'],
            ],
        ],
    ];

    foreach ($cases as $case) {
        record_check('html language scenario: ' . $case['label']);
        $langs = wp_fts_alc_langs_by_term($analyzer->analyze_content($case['html']));
        foreach ($case['expected'] as $term => $expectedLangs) {
            assert_same($expectedLangs, $langs[$term] ?? [], "{$case['label']} term {$term}");
        }
    }
});

test_case('quality corpus excludes unsafe regions with language attributes', function (): void {
    $analyzer = new WP_FTS_Analyzer(['default_lang' => 'en']);
    $expectedVisible = [
        'script' => ['visiblescript', 'afterscript'],
        'style' => ['visiblestyl', 'afterstyl'],
        'template' => ['visibletempl', 'aftertempl'],
        'svg' => ['visiblesvg', 'aftersvg'],
    ];
    $expectedSecret = [
        'script' => 'secretscript',
        'style' => 'secretstyl',
        'template' => 'secrettempl',
        'svg' => 'secretsvg',
    ];

    foreach (['script', 'style', 'template', 'svg'] as $tag) {
        record_check("unsafe {$tag} language scenario");
        $html = '<article lang=en><p>visible' . $tag . '</p><' . $tag . ' lang=pl>secret' . $tag .
            '</' . $tag . '><p>after' . $tag . '</p></article>';
        $terms = test_terms($analyzer->analyze_content($html));

        assert_true(in_array($expectedVisible[$tag][0], $terms, true), "{$tag} visible prefix should be indexed");
        assert_true(in_array($expectedVisible[$tag][1], $terms, true), "{$tag} visible suffix should be indexed");
        assert_true(!in_array($expectedSecret[$tag], $terms, true), "{$tag} body should be excluded");
    }

    $terms = test_terms($analyzer->analyze_content(
        '<article><!-- <p lang=pl>secretcomment</p> --><p>visiblecomment</p></article>'
    ));
    assert_true(in_array('visiblecom', $terms, true), 'visible comment sibling should be indexed');
    assert_true(!in_array('secretcom', $terms, true), 'comment bodies should be excluded');
});

test_case('quality corpus keeps processor extraction in parity with fallback extraction', function (): void {
    $html = '<article lang=pl><h1>Wrocław</h1><p><span lang=en-GB>colour</span> Łódź</p>' .
        '<svg lang=de><text>secretvector</text></svg><p>powrót</p></article>';
    $tokens = [
        ['type' => '#tag', 'tag' => 'ARTICLE', 'breadcrumbs' => ['ARTICLE'], 'attrs' => ['lang' => 'pl']],
        ['type' => '#tag', 'tag' => 'H1', 'breadcrumbs' => ['ARTICLE', 'H1']],
        ['type' => '#text', 'breadcrumbs' => ['ARTICLE', 'H1'], 'text' => 'Wrocław'],
        ['type' => '#tag', 'tag' => 'P', 'breadcrumbs' => ['ARTICLE', 'P']],
        ['type' => '#tag', 'tag' => 'SPAN', 'breadcrumbs' => ['ARTICLE', 'P', 'SPAN'], 'attrs' => ['lang' => 'en-GB']],
        ['type' => '#text', 'breadcrumbs' => ['ARTICLE', 'P', 'SPAN'], 'text' => 'colour'],
        ['type' => '#tag', 'tag' => 'SPAN', 'breadcrumbs' => ['ARTICLE', 'P', 'SPAN'], 'closing' => true],
        ['type' => '#text', 'breadcrumbs' => ['ARTICLE', 'P'], 'text' => ' Łódź'],
        ['type' => '#tag', 'tag' => 'SVG', 'breadcrumbs' => ['ARTICLE', 'SVG'], 'attrs' => ['lang' => 'de']],
        ['type' => '#text', 'breadcrumbs' => ['ARTICLE', 'SVG', 'TEXT'], 'text' => 'secretvector'],
        ['type' => '#tag', 'tag' => 'SVG', 'breadcrumbs' => ['ARTICLE', 'SVG'], 'closing' => true],
        ['type' => '#tag', 'tag' => 'P', 'breadcrumbs' => ['ARTICLE', 'P']],
        ['type' => '#text', 'breadcrumbs' => ['ARTICLE', 'P'], 'text' => 'powrót'],
    ];

    $fallback = new WP_FTS_Analyzer(['default_lang' => 'en']);
    $processor = new WP_FTS_Analyzer([
        'default_lang' => 'en',
        'html_processor_factory' => static fn(string $unused): WP_FTS_Fake_HTML_Processor => new WP_FTS_Fake_HTML_Processor($tokens),
    ]);

    $fallbackSignature = wp_fts_alc_occurrence_signature($fallback->analyze_content($html));
    $processorSignature = wp_fts_alc_occurrence_signature($processor->analyze_content($html));
    assert_same($fallbackSignature, $processorSignature, 'fake processor and fallback parser should agree on terms, language, and boosts');
    assert_true(!str_contains(implode('|', $processorSignature), 'secretvector'), 'processor path should exclude SVG text');
});

test_case('quality corpus tokenizes mixed scripts punctuation numbers emoji and invalid bytes', function (): void {
    $cases = [
        ['ja', 3, 'abc東京def', ['abc', '東', '京', '東京', 'def'], 'mixed Latin and Japanese'],
        ['zh-Hans', 3, '中文搜索 日 x', ['中', '文', '中文', '搜', '文搜', '中文搜', '索', '搜索', '文搜索', '中文搜索', '日'], 'CJK n-grams stream in end-position order and bypass minimum length'],
        ['zh-Hans', 3, '搜索系统', ['搜', '索', '搜索', '系', '索系', '搜索系', '统', '系统', '索系统', '搜索系统'], 'CJK bounded n-grams stream while retaining longer query evidence'],
        ['en', 2, "don't-stop re-enter", ['don', 'stop', 're', 'enter'], 'apostrophe and hyphen boundaries'],
        ['en', 2, 'v2_0 release42 🚀 emoji', ['v2_0', 'release42', 'emoji'], 'numbers underscores and emoji'],
        ['fr', 2, "Cafe\u{0301} deja\u{0300}", ['caf', 'dej'], 'Latin combining marks'],
        ['pl', 2, "\u{0141}o\u{0301}d\u{017a} Za\u{017c}o\u{0301}\u{0142}c\u{0301}", ['lodz', 'zazolc'], 'Polish combining marks'],
        ['de', 2, "fu\u{0308}r stra\u{00df}e", ['fuer', 'strasse'], 'German decomposed diaeresis'],
        ['tr', 2, 'İstanbul Iğdır', ['istanbul', 'ıgdır'], 'Turkish dotted and dotless I'],
        ['en', 2, "bad\xffutf café", ['bad', 'utf', 'cafe'], 'invalid UTF-8 recovery preserves word boundaries and valid Unicode'],
    ];

    foreach ($cases as [$lang, $minLen, $input, $expected, $label]) {
        record_check('tokenization scenario: ' . $label);
        $analyzer = new WP_FTS_Analyzer(['min_term_len' => $minLen]);
        assert_same($expected, $analyzer->analyze_query($input, ['lang' => $lang]), $label);
    }
});

test_case('quality corpus canonicalizes locale dialect script and malformed languages', function (): void {
    $normalizer = new WP_FTS_Normalizer();
    $canonicalCases = [
        'en-us' => 'en-US',
        'en_US' => 'en-US',
        'EN-gb' => 'en-GB',
        'pl_pl' => 'pl-PL',
        'de-de' => 'de-DE',
        'tr_tr' => 'tr-TR',
        'zh-hans' => 'zh-Hans',
        'ZH-HANT' => 'zh-Hant',
        'zh-CN' => 'zh-Hans',
        'zh-tw' => 'zh-Hant',
        'en-latn-us' => 'en-Latn-US',
        'sl-rozaj-biske' => 'sl-rozaj-biske',
    ];
    foreach ($canonicalCases as $input => $expected) {
        record_check('canonical language scenario: ' . $input);
        assert_same($expected, $normalizer->canonicalize_language($input), "canonicalize {$input}");
    }

    $analyzer = new WP_FTS_Analyzer(['default_lang' => 'de-DE']);
    foreach (['', 'C.UTF-8', 'POSIX', '@@@', '123', 'toolongprimary'] as $language) {
        record_check('malformed analyzer language fallback: ' . ($language === '' ? 'empty' : $language));
        $occurrences = $analyzer->analyze_content('<p>fallbackterm</p>', ['lang' => $language]);
        assert_same(['de-DE'], wp_fts_alc_langs_by_term($occurrences)['fallbackterm'] ?? [], "fallback {$language}");
    }
});

test_case('quality corpus applies language-specific folding including no-mbstring paths', function (): void {
    $normalizer = new WP_FTS_Normalizer();
    $cases = [
        ['pl-PL', 'Żółć', 'zolc', 'Polish precomposed fold'],
        ['pl', "Za\u{017c}o\u{0301}\u{0142}c\u{0301}", 'zazolc', 'Polish decomposed fold'],
        ['de-DE', 'Straße', 'strasse', 'German sharp s fold'],
        ['de', 'Ärger Öl für', 'aerger oel fuer', 'German whole-token input folds German umlauts'],
        ['tr-TR', 'Iğdır', 'ıgdır', 'Turkish ASCII I maps to dotless i'],
        ['tr', 'İZMİR', 'izmir', 'Turkish dotted capital I maps to i'],
        ['fr', 'Crème', 'creme', 'Latin fallback acute fold'],
        ['pt-BR', 'São', 'sao', 'Latin fallback tilde fold'],
    ];

    foreach ($cases as [$lang, $input, $expected, $label]) {
        record_check('folding scenario: ' . $label);
        assert_same($expected, $normalizer->normalize_token($input, $lang), $label);
    }

    $pipelineCases = [
        ['de', 'Ärger Öl für', ['aerger', 'oel', 'fuer'], 'German tokenizer plus folding'],
        ['fr', 'Crème brûlée São', ['crem', 'brule', 'sao'], 'Latin fallback tokenizer plus folding'],
        ['tr', 'IĞDIR İZMİR', ['ıgdır', 'izmir'], 'Turkish tokenizer plus folding'],
    ];
    foreach ($pipelineCases as [$lang, $input, $expected, $label]) {
        record_check('pipeline folding scenario: ' . $label);
        assert_same($expected, (new WP_FTS_LanguagePipeline())->analyze($input, $lang), $label);
    }

    $fallbackCases = [
        ['pl', 'ŻÓŁĆ', 'zolc'],
        ['de', 'ÄRGER', 'aerger'],
        ['tr', 'ÇİĞ', 'cig'],
        ['fr', 'ÉCOLE', 'ecole'],
        ['ru', 'АБВГДЕЁЖЗИЙКЛМНОПРСТУФХЦЧШЩЪЫЬЭЮЯ', 'абвгдеёжзийклмнопрстуфхцчшщъыьэюя'],
        ['uk', 'ҐЄІЇ', 'ґєії'],
    ];
    foreach ($fallbackCases as [$lang, $input, $expected]) {
        record_check('no-mbstring uppercase fallback scenario: ' . $lang);
        assert_same($expected, test_normalize_without_mbstring($normalizer, $input, $lang), "fallback {$lang}");
    }
});

test_case('quality corpus exposes query occurrence output while preserving plain terms', function (): void {
    $analyzer = new WP_FTS_Analyzer();
    // Raw source-tree bootstraps preserve the Bengali precomposed letter until
    // Composer supplies the required Unicode normalization backend.
    $bengaliSchoolTerm = class_exists('Normalizer') ? 'বিদ্যালয়' : 'বিদ্যালয়';
    $cases = [
        ['en-US', 'colour apple', ['color', 'appl']],
        ['en-GB', 'organise colour', ['organiz', 'color']],
        ['pl-PL', 'Łódź Wrocław', ['lodz', 'wroclaw']],
        ['de-DE', 'Straße Öl', ['strasse', 'oel']],
        ['tr-TR', 'İstanbul Iğdır', ['istanbul', 'ıgdır']],
        ['ar', 'الات الكم مفيدة للبحث', ['الات', 'الكم', 'مفيد', 'بحث']],
        ['bn', 'বইটিকে শিক্ষকদেরকে বিদ্যালয়ের সূচিতে', ['বই', 'শিক্ষক', $bengaliSchoolTerm, 'সূচি']],
        ['ur', 'دلوں لڑکیوں لڑکیاں لڑکے حالات معلومات', ['دلوں', 'لڑکی', 'لڑکی', 'لڑک', 'حال', 'معلوم']],
        ['zh-Hans', '中文搜索', ['中', '文', '中文', '搜', '文搜', '中文搜', '索', '搜索', '文搜索', '中文搜索']],
        ['zh-Hant', '繁體搜索', ['繁', '體', '繁體', '搜', '體搜', '繁體搜', '索', '搜索', '體搜索', '繁體搜索']],
    ];

    foreach ($cases as [$lang, $query, $expectedTerms]) {
        record_check('query occurrence scenario: ' . $lang);
        $plain = $analyzer->analyze_query($query, ['lang' => $lang]);
        $occurrences = $analyzer->analyze_query_occurrences($query, ['lang' => $lang]);
        $compatOccurrences = $analyzer->analyze_query($query, ['language' => $lang, 'return' => 'occurrences']);

        assert_same($expectedTerms, $plain, "plain query terms {$lang}");
        assert_same($expectedTerms, array_column($occurrences, 'term'), "occurrence terms {$lang}");
        assert_same($occurrences, $compatOccurrences, "compat occurrence terms {$lang}");
        foreach ($occurrences as $occurrence) {
            assert_same($normalLang = (new WP_FTS_Normalizer())->canonicalize_language($lang), $occurrence['lang'], "occurrence language {$normalLang}");
        }
    }
});

test_case('quality corpus preserves short Arabic-script length guard terms', function (): void {
    $pipeline = new WP_FTS_LanguagePipeline();
    $snowball = new WP_FTS_SnowballStemmer();
    $baseline = new WP_FTS_BaselineLanguageStemmer();

    assert_same(4, WP_FTS_Utf8::length('الات'), 'Arabic short guard fixture should count Unicode characters');
    assert_same(4, WP_FTS_Utf8::length('الكم'), 'Arabic short guard fixture with pronoun-like ending should count Unicode characters');
    assert_same(4, WP_FTS_Utf8::length('دلوں'), 'Urdu short guard fixture should count Unicode characters');
    assert_same(['الات'], $pipeline->analyze('الات', 'ar'), 'Arabic pipeline should preserve الات');
    assert_same(['الكم'], $pipeline->analyze('الكم', 'ar'), 'Arabic pipeline should preserve الكم');
    assert_same(['دلوں'], $pipeline->analyze('دلوں', 'ur'), 'Urdu pipeline should preserve دلوں');
    assert_same('الات', $snowball->stem('الات', 'ar'), 'Arabic Snowball adapter should preserve الات');
    assert_same('الكم', $snowball->stem('الكم', 'ar'), 'Arabic Snowball adapter should preserve الكم');
    assert_same('دلوں', $baseline->stem('دلوں', 'ur'), 'Urdu baseline should preserve دلوں');
    assert_same('حال', $baseline->stem('حالات', 'ur'), 'Urdu baseline should still stem longer Arabic-loan plurals');
    assert_same('معلوم', $baseline->stem('معلومات', 'ur'), 'Urdu baseline should still stem longer -at plurals');
});

test_case('quality corpus exposes Bengali Urdu baseline signature changes', function (): void {
    $stemmer = new WP_FTS_BaselineLanguageStemmer();
    $pipeline = new WP_FTS_LanguagePipeline();
    $analyzer = new WP_FTS_Analyzer();

    assert_true(
        str_contains($stemmer->index_signature(), 'wp-fts-baseline-language-stemmer:v10:'),
        'baseline Bengali Urdu stemmer signature should identify v2 suffix rules'
    );
    assert_true(
        str_contains($pipeline->index_signature(), 'wp-fts-language-pipeline-v20:'),
        'language pipeline signature should bump for Bengali Urdu baseline behavior'
    );
    assert_true(
        str_contains($analyzer->index_signature(), 'wp-fts-analyzer-v6:'),
        'analyzer signature should bump for default language pipeline behavior'
    );
});

test_case('quality corpus keeps plain content and query analysis equivalent over generated dimensions', function (): void {
    $analyzer = new WP_FTS_Analyzer(['min_term_len' => 2]);
    $normalizer = new WP_FTS_Normalizer();
    $languages = ['en-US', 'en-GB', 'pl-PL', 'de-DE', 'tr-TR', 'bn', 'ur', 'zh-Hans', 'zh-Hant', 'ja', 'ko', 'fr'];
    $texts = [
        'Alpha beta',
        'Wrocław café',
        'Colour organise',
        'Straße Ärger',
        'İstanbul Iğdır',
        '中文搜索',
        '東京検索',
        '한글검색',
        "don't stop",
        'v2_0 release42',
        "Cafe\u{0301} deja\u{0300}",
        'emoji 🚀 alpha',
    ];

    foreach ($languages as $language) {
        $canonical = $normalizer->canonicalize_language($language);
        foreach ($texts as $text) {
            record_check("content/query parity {$canonical}: {$text}");
            $content = $analyzer->analyze_content($text, ['lang' => $language]);
            $query = $analyzer->analyze_query($text, ['lang' => $language]);

            assert_same($query, test_terms($content), "content/query term parity {$canonical}");
            foreach ($content as $occurrence) {
                assert_same($canonical, $occurrence['lang'], "content occurrence language {$canonical}");
            }
        }
    }
});

test_case('quality corpus keeps custom stemmer callable arities compatible', function (): void {
    $cases = [
        [
            'label' => 'internal one arg',
            'stemmer' => 'strrev',
            'input' => 'alpha',
            'lang' => 'en',
            'expected' => ['ahpla'],
        ],
        [
            'label' => 'internal optional non-language arg',
            'stemmer' => 'metaphone',
            'input' => 'testing',
            'lang' => 'en',
            'expected' => ['TSTNK'],
        ],
        [
            'label' => 'one required plus optional arg',
            'stemmer' => static fn(string $term, string $language = 'unused'): string => func_num_args() . ':' . $term,
            'input' => 'alpha',
            'lang' => 'en-GB',
            'expected' => ['1:alpha'],
        ],
        [
            'label' => 'two required args',
            'stemmer' => static fn(string $term, string $language): string => $language . ':' . $term,
            'input' => 'colour',
            'lang' => 'en-GB',
            'expected' => ['en-GB:color'],
        ],
        [
            'label' => 'variadic args',
            'stemmer' => static fn(string $term, string ...$args): string => count($args) . ':' . $term,
            'input' => 'colour',
            'lang' => 'en-GB',
            'expected' => ['1:color'],
        ],
    ];

    foreach ($cases as $case) {
        record_check('custom stemmer arity scenario: ' . $case['label']);
        $pipeline = new WP_FTS_LanguagePipeline(['stemmer' => $case['stemmer']]);
        assert_same($case['expected'], $pipeline->analyze($case['input'], $case['lang']), $case['label']);
    }
});

test_case('quality analyzer language corpus contributes at least five hundred checks', function (): void {
    $start = (int) ($GLOBALS['wp_fts_analyzer_language_corpus_start'] ?? 0);
    $contribution = executed_check_count() - $start;

    assert_true(
        $contribution >= 500,
        "analyzer language corpus should contribute at least 500 checks/scenarios; saw {$contribution}"
    );
});

if ($wp_fts_alc_direct) {
    $failures = 0;
    $start = microtime(true);
    foreach ($GLOBALS['wp_fts_alc_tests'] as $test) {
        try {
            ($test['fn'])();
            fwrite(STDOUT, "[PASS] {$test['name']}\n");
        } catch (Throwable $e) {
            $failures++;
            fwrite(STDERR, "[FAIL] {$test['name']}\n{$e->getMessage()}\n{$e->getTraceAsString()}\n");
        }
    }

    $duration = number_format(microtime(true) - $start, 3);
    $count = count($GLOBALS['wp_fts_alc_tests']);
    $passed = $count - $failures;
    $checks = (int) $GLOBALS['wp_fts_alc_check_count'];
    $summary = "{$passed}/{$count} analyzer language corpus tests passed; failures={$failures}; checks/scenarios={$checks}; duration={$duration}s\n";
    if ($failures > 0) {
        fwrite(STDERR, $summary);
        exit(1);
    }

    fwrite(STDOUT, $summary);
}
