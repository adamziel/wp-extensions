<?php
declare(strict_types=1);

/** Capture the typed limit failure without obscuring an unexpected exception. */
function wp_fts_markup_caught(callable $callback): ?Throwable
{
    try {
        $callback();
    } catch (Throwable $error) {
        return $error;
    }

    return null;
}

test_case('HTML analysis publishes exact non-configurable syntax bounds', function (): void {
    $limits = [
        'MAX_HTML_MARKUP_TOKENS' => 20000,
        'MAX_HTML_ELEMENT_DEPTH' => 256,
        'MAX_HTML_TAG_BYTES' => 16384,
        'MAX_HTML_ATTRIBUTES_PER_TAG' => 128,
        'MAX_HTML_ATTRIBUTE_BYTES' => 4096,
        'MAX_HTML_LANGUAGE_ATTRIBUTE_BYTES' => 64,
        'MAX_LANGUAGE_SUBTAGS' => 8,
    ];
    $reflection = new ReflectionClass(WP_FTS_Analysis_Limits::class);
    foreach ($limits as $constant => $expected) {
        assert_same(
            $expected,
            $reflection->getReflectionConstant($constant)?->getValue(),
            "{$constant} should remain a documented low-host analysis bound"
        );
    }
});

test_case('HTML syntax boundaries accept their exact limits and reject the next unit', function (): void {
    $analyzer = new WP_FTS_Analyzer([
        'auto_detect_language' => false,
        'enable_stemming' => false,
        'default_lang' => 'en',
    ]);

    $exactDepth = str_repeat('<span>', 256) . 'boundedword' . str_repeat('</span>', 256);
    assert_same('boundedword', $analyzer->analyze_content($exactDepth)[0]['term'] ?? null, '256 nested elements should remain valid');
    $depthError = wp_fts_markup_caught(static fn(): array => $analyzer->analyze_content(
        str_repeat('<span>', 257) . 'boundedword' . str_repeat('</span>', 257)
    ));
    assert_same('html_element_depth', $depthError instanceof WP_FTS_Analysis_Limit_Exceeded ? $depthError->reason_code : null, 'element 257 should fail with the typed depth reason');

    $exactTokens = str_repeat('<br>', 20000);
    assert_same([], $analyzer->analyze_content($exactTokens), '20,000 void markup tokens should remain valid');
    $tokenError = wp_fts_markup_caught(static fn(): array => $analyzer->analyze_content($exactTokens . '<br>'));
    assert_same('html_markup_tokens', $tokenError instanceof WP_FTS_Analysis_Limit_Exceeded ? $tokenError->reason_code : null, 'markup token 20,001 should fail with the typed token reason');

    $exactTag = '<p' . str_repeat(' ', WP_FTS_Analysis_Limits::MAX_HTML_TAG_BYTES - 3) . '>boundedword</p>';
    assert_same('boundedword', $analyzer->analyze_content($exactTag)[0]['term'] ?? null, 'an exact 16-KiB element tag should remain valid');
    $tagError = wp_fts_markup_caught(static fn(): array => $analyzer->analyze_content(
        '<p' . str_repeat(' ', WP_FTS_Analysis_Limits::MAX_HTML_TAG_BYTES - 2) . '>boundedword</p>'
    ));
    assert_same('html_tag_bytes', $tagError instanceof WP_FTS_Analysis_Limit_Exceeded ? $tagError->reason_code : null, 'the first tag byte above 16 KiB should reject');

    $attributePrefixBytes = strlen('data-x=""');
    $exactAttribute = '<p data-x="'
        . str_repeat('a', WP_FTS_Analysis_Limits::MAX_HTML_ATTRIBUTE_BYTES - $attributePrefixBytes)
        . '">boundedword</p>';
    assert_same('boundedword', $analyzer->analyze_content($exactAttribute)[0]['term'] ?? null, 'an exact 4-KiB attribute should remain valid');
    $attributeError = wp_fts_markup_caught(static fn(): array => $analyzer->analyze_content(
        '<p data-x="'
        . str_repeat('a', WP_FTS_Analysis_Limits::MAX_HTML_ATTRIBUTE_BYTES - $attributePrefixBytes + 1)
        . '">boundedword</p>'
    ));
    assert_same('html_attribute_bytes', $attributeError instanceof WP_FTS_Analysis_Limit_Exceeded ? $attributeError->reason_code : null, 'the first attribute byte above 4 KiB should reject');

    $attributes = [];
    for ($number = 1; $number <= 128; $number++) {
        $attributes[] = 'data-' . $number;
    }
    $exactAttributeCount = '<p ' . implode(' ', $attributes) . '>boundedword</p>';
    assert_same('boundedword', $analyzer->analyze_content($exactAttributeCount)[0]['term'] ?? null, '128 attributes on one element should remain valid');
    $attributeCountError = wp_fts_markup_caught(static fn(): array => $analyzer->analyze_content(
        '<p ' . implode(' ', [...$attributes, 'data-129']) . '>boundedword</p>'
    ));
    assert_same('html_attributes_per_tag', $attributeCountError instanceof WP_FTS_Analysis_Limit_Exceeded ? $attributeCountError->reason_code : null, 'attribute 129 should reject before either HTML parser');
});

test_case('HTML raw-text contents cannot invent markup depth or tokens', function (): void {
    $analyzer = new WP_FTS_Analyzer([
        'auto_detect_language' => false,
        'enable_stemming' => false,
        'default_lang' => 'en',
    ]);
    $tagLookingText = str_repeat('<div>', WP_FTS_Analysis_Limits::MAX_HTML_MARKUP_TOKENS + 1);

    foreach (['script', 'style'] as $rawTextTag) {
        $html = "<{$rawTextTag}>{$tagLookingText}</{$rawTextTag}><p>visibleword</p>";
        $error = wp_fts_markup_caught(static fn(): array => $analyzer->analyze_content($html));
        assert_same(null, $error, strtoupper($rawTextTag) . ' data should not be parsed as nested markup');
        assert_same('visibleword', WP_FTS_Html_Text_Stream::visible_text($html), strtoupper($rawTextTag) . ' data should remain hidden while later visible text survives');
    }

    $exactRawTextTokens = str_repeat('<script><div></script><style><div></style>', 5000);
    assert_same(null, wp_fts_markup_caught(static function () use ($exactRawTextTokens): void {
        WP_FTS_Html_Text_Stream::assert_analysis_markup_limits($exactRawTextTokens);
    }), '10,000 raw-text elements should retain their exact 20,000 real markup tokens');
    $rawTextTokenError = wp_fts_markup_caught(static function () use ($exactRawTextTokens): void {
        WP_FTS_Html_Text_Stream::assert_analysis_markup_limits($exactRawTextTokens . '<script></script>');
    });
    assert_same('html_markup_tokens', $rawTextTokenError instanceof WP_FTS_Analysis_Limit_Exceeded ? $rawTextTokenError->reason_code : null, 'the first real raw-text tag above the 20,000-token envelope should reject');

    $rawTextDepthError = wp_fts_markup_caught(static function (): void {
        WP_FTS_Html_Text_Stream::assert_analysis_markup_limits(
            str_repeat('<div>', WP_FTS_Analysis_Limits::MAX_HTML_ELEMENT_DEPTH)
            . '<script></script>'
            . str_repeat('</div>', WP_FTS_Analysis_Limits::MAX_HTML_ELEMENT_DEPTH)
        );
    });
    assert_same('html_element_depth', $rawTextDepthError instanceof WP_FTS_Analysis_Limit_Exceeded ? $rawTextDepthError->reason_code : null, 'a SCRIPT child at real element depth 257 should reject before its opaque contents are skipped');

    $doubleEscapedScript = '<script><!--<script></script>'
        . str_repeat(' hiddenword', WP_FTS_Analysis_Limits::MAX_DOCUMENT_OCCURRENCES + 1)
        . '--></script><p>visibleword</p>';
    assert_same('visibleword', WP_FTS_Html_Text_Stream::visible_text($doubleEscapedScript), 'double-escaped SCRIPT data should remain hidden until its actual end tag');
    assert_same(['visibleword'], array_column($analyzer->analyze_content($doubleEscapedScript), 'term'), '20,001 hidden SCRIPT words should not consume the document occurrence budget');

    $doubleEscapedDashDashScript = '<script><!--<script>-->'
        . str_repeat(' hiddenword', WP_FTS_Analysis_Limits::MAX_DOCUMENT_OCCURRENCES + 1)
        . '</script>stillhidden</script><p>visibleword</p>';
    assert_same('visibleword', WP_FTS_Html_Text_Stream::visible_text($doubleEscapedDashDashScript), 'double-escaped dash-dash SCRIPT data should not close the outer element');
    assert_same(['visibleword'], array_column($analyzer->analyze_content($doubleEscapedDashDashScript), 'term'), '20,001 words after double-escaped dash-dash should remain outside the occurrence budget');

    $nestedError = wp_fts_markup_caught(static fn(): array => $analyzer->analyze_content(
        str_repeat('<div>', WP_FTS_Analysis_Limits::MAX_HTML_ELEMENT_DEPTH + 1)
        . 'visibleword'
        . str_repeat('</div>', WP_FTS_Analysis_Limits::MAX_HTML_ELEMENT_DEPTH + 1)
    ));
    assert_same('html_element_depth', $nestedError instanceof WP_FTS_Analysis_Limit_Exceeded ? $nestedError->reason_code : null, 'real nested markup must still reject at the existing depth boundary');
});

test_case('custom HTML processor tokens accept the exact envelope and contain one-over or infinite providers', function (): void {
    $processorLimit = (WP_FTS_Analysis_Limits::MAX_HTML_MARKUP_TOKENS * 2) + 1;

    $run = static function (?int $providedTokens): array {
        $processor = new class ($providedTokens) {
            public int $calls = 0;

            /** Use null to model a provider that never reports exhaustion. */
            public function __construct(private ?int $providedTokens)
            {
            }

            /** Count the exact call on which the analyzer enforces its stop. */
            public function next_token(): bool
            {
                $this->calls++;

                return $this->providedTokens === null || $this->calls <= $this->providedTokens;
            }

            /** Supply no retained ancestry; this case isolates token cardinality. */
            public function get_breadcrumbs(): array
            {
                return [];
            }

            /** Keep structural depth out of the token-count boundary. */
            public function get_current_depth(): int
            {
                return 0;
            }

            /** Use a zero-output token so source bytes cannot become the limiter. */
            public function get_token_type(): string
            {
                return '#comment';
            }

            /** Comments have no element name. */
            public function get_tag(): ?string
            {
                return null;
            }

            /** Comments never mutate the element stack as closers. */
            public function is_tag_closer(): bool
            {
                return false;
            }

            /** Match the processor method surface without creating a tag event. */
            public function expects_closer(): bool
            {
                return true;
            }

            /** Return no text so only provider calls are measured. */
            public function get_modifiable_text(): string
            {
                return '';
            }
        };
        $analyzer = new WP_FTS_Analyzer([
            'auto_detect_language' => false,
            'enable_stemming' => false,
            'html_processor_factory' => static fn(): object => $processor,
        ]);

        return [wp_fts_markup_caught(static fn(): array => $analyzer->analyze_content('<p>boundedword</p>')), $processor->calls];
    };

    [$exactError, $exactCalls] = $run($processorLimit);
    assert_same(null, $exactError, 'the exact custom-processor token envelope should remain valid');
    assert_same($processorLimit + 1, $exactCalls, 'the exact provider should be asked once more to observe normal exhaustion');

    [$overError, $overCalls] = $run($processorLimit + 1);
    assert_same('html_markup_tokens', $overError instanceof WP_FTS_Analysis_Limit_Exceeded ? $overError->reason_code : null, 'the first processor token above the envelope should fail with the existing typed token reason');
    assert_same($processorLimit + 1, $overCalls, 'the one-over provider should stop on its first excess token');

    [$infiniteError, $infiniteCalls] = $run(null);
    assert_same('html_markup_tokens', $infiniteError instanceof WP_FTS_Analysis_Limit_Exceeded ? $infiniteError->reason_code : null, 'an infinite custom processor should fail with the existing typed token reason');
    assert_same($processorLimit + 1, $infiniteCalls, 'an infinite custom processor should stop on the first excess token');
});

test_case('custom HTML processor absolute depth rejects before allocating state rows', function (): void {
    $processor = new class {
        private bool $available = true;

        /** Emit one token whose reported absolute depth is already invalid. */
        public function next_token(): bool
        {
            if (!$this->available) {
                return false;
            }
            $this->available = false;

            return true;
        }

        /** Cross the hard depth limit before the analyzer can allocate stack rows. */
        public function get_current_depth(): int
        {
            return WP_FTS_Analysis_Limits::MAX_HTML_ELEMENT_DEPTH + 4;
        }

        /** Make the sole token otherwise valid visible text. */
        public function get_token_type(): string
        {
            return '#text';
        }

        /** Text events carry no element name. */
        public function get_tag(): ?string
        {
            return null;
        }

        /** Text cannot close structural state. */
        public function is_tag_closer(): bool
        {
            return false;
        }

        /** Text cannot open structural state. */
        public function expects_closer(): bool
        {
            return false;
        }

        /** Provide valid content that must remain unread after depth rejection. */
        public function get_modifiable_text(): string
        {
            return 'boundedword';
        }
    };
    $analyzer = new WP_FTS_Analyzer([
        'html_processor_factory' => static fn(): object => $processor,
    ]);
    $error = wp_fts_markup_caught(static fn(): array => $analyzer->analyze_content('<p>boundedword</p>'));

    assert_same('html_element_depth', $error instanceof WP_FTS_Analysis_Limit_Exceeded ? $error->reason_code : null, 'the first absolute provider depth outside the structural envelope should fail before state allocation');
});

test_case('custom HTML processor output bytes accept exact limits and reject before copies', function (): void {
    $runText = static function (array $chunks): array {
        $processor = new class ($chunks) {
            public int $textCalls = 0;
            private int $cursor = -1;

            /** Retain the exact chunks used to cross the aggregate output limit. */
            public function __construct(private array $chunks)
            {
            }

            /** Advance once per supplied text chunk and then exhaust normally. */
            public function next_token(): bool
            {
                $this->cursor++;

                return array_key_exists($this->cursor, $this->chunks);
            }

            /** Keep ancestry empty because only aggregate text bytes are under test. */
            public function get_breadcrumbs(): array
            {
                return [];
            }

            /** Keep structural accounting at zero for the byte-boundary proof. */
            public function get_current_depth(): int
            {
                return 0;
            }

            /** Expose every supplied chunk as processor text. */
            public function get_token_type(): string
            {
                return '#text';
            }

            /** Text events carry no tag output. */
            public function get_tag(): ?string
            {
                return null;
            }

            /** Text events cannot close elements. */
            public function is_tag_closer(): bool
            {
                return false;
            }

            /** Satisfy the complete processor contract without changing byte work. */
            public function expects_closer(): bool
            {
                return true;
            }

            /** Count extraction so rejection is proven to occur on the excess chunk. */
            public function get_modifiable_text(): string
            {
                $this->textCalls++;

                return $this->chunks[$this->cursor];
            }
        };
        $analyzer = new WP_FTS_Analyzer([
            'auto_detect_language' => false,
            'enable_stemming' => false,
            'html_processor_factory' => static fn(): object => $processor,
        ]);

        return [wp_fts_markup_caught(static fn(): array => $analyzer->analyze_content('<p>boundedword</p>')), $processor->textCalls];
    };

    $half = intdiv(WP_FTS_Analysis_Limits::MAX_SOURCE_BYTES, 2);
    [$exactError, $exactCalls] = $runText([
        str_repeat(' ', $half),
        str_repeat(' ', WP_FTS_Analysis_Limits::MAX_SOURCE_BYTES - $half),
    ]);
    assert_same(null, $exactError, 'exactly 2 MiB of aggregate processor text should remain valid');
    assert_same(2, $exactCalls, 'the exact processor text envelope should consume both chunks');

    [$overError, $overCalls] = $runText([
        str_repeat(' ', $half),
        str_repeat(' ', WP_FTS_Analysis_Limits::MAX_SOURCE_BYTES - $half + 1),
    ]);
    assert_same('source_bytes', $overError instanceof WP_FTS_Analysis_Limit_Exceeded ? $overError->reason_code : null, 'the first aggregate processor text byte above 2 MiB should reject');
    assert_same(2, $overCalls, 'aggregate processor text should stop on the first over-limit chunk');

    $runTag = static function (int $tagBytes, int $languageBytes): ?Throwable {
        $processor = new class ($tagBytes, $languageBytes) {
            private bool $available = true;

            /** Configure independent tag-name and language-attribute adversaries. */
            public function __construct(private int $tagBytes, private int $languageBytes)
            {
            }

            /** Emit exactly one element event. */
            public function next_token(): bool
            {
                if (!$this->available) {
                    return false;
                }
                $this->available = false;

                return true;
            }

            /** Avoid ancestry retention; the returned field bytes are the boundary. */
            public function get_breadcrumbs(): array
            {
                return [];
            }

            /** Report one valid open element level. */
            public function get_current_depth(): int
            {
                return 1;
            }

            /** Route the event through tag and language accessors. */
            public function get_token_type(): string
            {
                return '#tag';
            }

            /** Exercise an opening tag, not a pop operation. */
            public function is_tag_closer(): bool
            {
                return false;
            }

            /** Keep the opening event structurally realistic. */
            public function expects_closer(): bool
            {
                return true;
            }

            /** Element events contribute no visible text. */
            public function get_modifiable_text(): string
            {
                return '';
            }

            /** Return the exact tag byte count before uppercase or trim copies. */
            public function get_tag(): string
            {
                return str_repeat('p', $this->tagBytes);
            }

            /** Return hostile bytes only for the language attribute the analyzer reads. */
            public function get_attribute(string $attribute): ?string
            {
                return $attribute === 'lang' ? str_repeat('e', $this->languageBytes) : null;
            }
        };
        $analyzer = new WP_FTS_Analyzer([
            'auto_detect_language' => false,
            'html_processor_factory' => static fn(): object => $processor,
        ]);

        return wp_fts_markup_caught(static fn(): array => $analyzer->analyze_content('<p>boundedword</p>'));
    };

    assert_same(null, $runTag(WP_FTS_Analysis_Limits::MAX_HTML_TAG_BYTES, 2), 'an exact processor tag output should remain valid');
    $tagError = $runTag(WP_FTS_Analysis_Limits::MAX_HTML_TAG_BYTES + 1, 2);
    assert_same('html_tag_bytes', $tagError instanceof WP_FTS_Analysis_Limit_Exceeded ? $tagError->reason_code : null, 'the first processor tag byte above 16 KiB should reject before trim or uppercase copies');
    assert_same(null, $runTag(1, WP_FTS_Analysis_Limits::MAX_HTML_LANGUAGE_ATTRIBUTE_BYTES), 'an exact processor language output should remain bounded even when it is not a valid tag');
    $languageError = $runTag(1, WP_FTS_Analysis_Limits::MAX_HTML_LANGUAGE_ATTRIBUTE_BYTES + 1);
    assert_same('html_language_attribute_bytes', $languageError instanceof WP_FTS_Analysis_Limit_Exceeded ? $languageError->reason_code : null, 'the first processor language byte above 64 should reject before canonicalization');

    $runRepeatedTags = static function (int $tagBytes, int $providedTokens): array {
        $processor = new class ($tagBytes, $providedTokens) {
            public int $calls = 0;

            /** Configure enough maximum-size tag outputs to test aggregate work. */
            public function __construct(private int $tagBytes, private int $providedTokens)
            {
            }

            /** Count the first provider call beyond the accepted output envelope. */
            public function next_token(): bool
            {
                $this->calls++;

                return $this->calls <= $this->providedTokens;
            }

            /** Fail if analysis regresses from event state to retained ancestry. */
            public function get_breadcrumbs(): array
            {
                throw new RuntimeException('the event-stream analyzer must never request breadcrumbs');
            }

            /** Hold depth constant so repeated tags cannot trigger another limit. */
            public function get_current_depth(): int
            {
                return 1;
            }

            /** Route every provider item through tag-output accounting. */
            public function get_token_type(): string
            {
                return '#tag';
            }

            /** Produce the configured maximum-size tag on demand. */
            public function get_tag(): string
            {
                return str_repeat('p', $this->tagBytes);
            }

            /** Avoid close-event state changes during aggregate byte measurement. */
            public function is_tag_closer(): bool
            {
                return false;
            }

            /** Avoid growing the stack while still exercising tag output. */
            public function expects_closer(): bool
            {
                return false;
            }

            /** Keep visible-text accounting out of the repeated-tag case. */
            public function get_modifiable_text(): string
            {
                return '';
            }
        };
        $analyzer = new WP_FTS_Analyzer([
            'auto_detect_language' => false,
            'html_processor_factory' => static fn(): object => $processor,
        ]);

        return [wp_fts_markup_caught(static fn(): array => $analyzer->analyze_content('<p>boundedword</p>')), $processor->calls];
    };

    $maximumTagTokens = intdiv(
        WP_FTS_Analysis_Limits::MAX_SOURCE_BYTES,
        WP_FTS_Analysis_Limits::MAX_HTML_TAG_BYTES
    );
    [$exactTagWorkError, $exactTagWorkCalls] = $runRepeatedTags(
        WP_FTS_Analysis_Limits::MAX_HTML_TAG_BYTES,
        $maximumTagTokens
    );
    assert_same(null, $exactTagWorkError, 'the exact aggregate maximum-tag output envelope should remain valid');
    assert_same($maximumTagTokens + 1, $exactTagWorkCalls, 'the exact maximum-tag provider should be observed exhausting normally');
    [$overTagWorkError, $overTagWorkCalls] = $runRepeatedTags(
        WP_FTS_Analysis_Limits::MAX_HTML_TAG_BYTES,
        $maximumTagTokens + 1
    );
    assert_same('source_bytes', $overTagWorkError instanceof WP_FTS_Analysis_Limit_Exceeded ? $overTagWorkError->reason_code : null, 'the first aggregate maximum-tag byte above 2 MiB should reject before uppercase copies');
    assert_same($maximumTagTokens + 1, $overTagWorkCalls, 'the repeated maximum-tag provider should stop on its first excess token');

    $runTokenTypes = static function (string $tokenType, int $providedTokens): array {
        $processor = new class ($tokenType, $providedTokens) {
            public int $calls = 0;

            /** Configure exact or over-limit token-type output cardinality. */
            public function __construct(private string $tokenType, private int $providedTokens)
            {
            }

            /** Observe normal exhaustion or the first aggregate excess token. */
            public function next_token(): bool
            {
                $this->calls++;
                return $this->calls <= $this->providedTokens;
            }

            /** Remove depth from the token-type output boundary. */
            public function get_current_depth(): int
            {
                return 0;
            }

            /** Return the hostile processor-controlled token type verbatim. */
            public function get_token_type(): string
            {
                return $this->tokenType;
            }

            /** Non-tag token types expose no element name. */
            public function get_tag(): ?string
            {
                return null;
            }

            /** Non-tag tokens never close elements. */
            public function is_tag_closer(): bool
            {
                return false;
            }

            /** Non-tag tokens never grow structural state. */
            public function expects_closer(): bool
            {
                return false;
            }

            /** Return no content so token-type bytes are the only output work. */
            public function get_modifiable_text(): string
            {
                return '';
            }
        };
        $analyzer = new WP_FTS_Analyzer([
            'auto_detect_language' => false,
            'html_processor_factory' => static fn(): object => $processor,
        ]);

        return [wp_fts_markup_caught(static fn(): array => $analyzer->analyze_content('<p>boundedword</p>')), $processor->calls];
    };

    [$tokenTypeError] = $runTokenTypes(str_repeat('t', 65), 1);
    assert_same('html_processor_token_type_bytes', $tokenTypeError instanceof WP_FTS_Analysis_Limit_Exceeded ? $tokenTypeError->reason_code : null, 'token type byte 65 should reject before comparisons');
    $maximumTokenTypes = intdiv(WP_FTS_Analysis_Limits::MAX_SOURCE_BYTES, 64);
    [$exactTokenWorkError, $exactTokenWorkCalls] = $runTokenTypes(str_repeat('t', 64), $maximumTokenTypes);
    assert_same(null, $exactTokenWorkError, 'the exact aggregate token-type output envelope should remain valid');
    assert_same($maximumTokenTypes + 1, $exactTokenWorkCalls, 'the exact token-type provider should be observed exhausting normally');
    [$overTokenWorkError, $overTokenWorkCalls] = $runTokenTypes(str_repeat('t', 64), $maximumTokenTypes + 1);
    assert_same('source_bytes', $overTokenWorkError instanceof WP_FTS_Analysis_Limit_Exceeded ? $overTokenWorkError->reason_code : null, 'the first aggregate token-type byte above 2 MiB should reject');
    assert_same($maximumTokenTypes + 1, $overTokenWorkCalls, 'the repeated token-type provider should stop on its first excess token');
});

test_case('processor event state does not leak atomic tags and treats BR as a lexical boundary', function (): void {
    $analyze = static function (string $source, array $tokens): array {
        $processor = new class ($tokens) {
            private int $offset = -1;

            /** Replay explicit processor events to verify stack-transition semantics. */
            public function __construct(private array $tokens)
            {
            }

            /** Advance through the exact synthetic event sequence. */
            public function next_token(): bool
            {
                $this->offset++;
                return isset($this->tokens[$this->offset]);
            }

            /** Report each event's absolute processor depth. */
            public function get_current_depth(): int
            {
                return (int) ($this->current()['depth'] ?? 0);
            }

            /** Return the current synthetic event kind. */
            public function get_token_type(): string
            {
                return (string) ($this->current()['type'] ?? '');
            }

            /** Preserve absence of a tag on text events. */
            public function get_tag(): ?string
            {
                $tag = $this->current()['tag'] ?? null;
                return is_string($tag) ? $tag : null;
            }

            /** Drive pops only for events marked as closers. */
            public function is_tag_closer(): bool
            {
                return (bool) ($this->current()['closer'] ?? false);
            }

            /** Model atomic tags separately from ordinary opening elements. */
            public function expects_closer(): bool
            {
                return (bool) ($this->current()['expects_closer'] ?? false);
            }

            /** Expose current visible text without deriving it from the source. */
            public function get_modifiable_text(): string
            {
                return (string) ($this->current()['text'] ?? '');
            }

            /** Return only attributes attached to the current synthetic event. */
            public function get_attribute(string $name): mixed
            {
                return ($this->current()['attributes'] ?? [])[$name] ?? null;
            }

            /** Fail if the event-stream path starts materializing breadcrumbs again. */
            public function get_breadcrumbs(): array
            {
                throw new RuntimeException('the event-stream analyzer must never request breadcrumbs');
            }

            /** Centralize the current event so every accessor observes one state. */
            private function current(): array
            {
                return $this->tokens[$this->offset] ?? [];
            }
        };
        $analyzer = new WP_FTS_Analyzer([
            'auto_detect_language' => false,
            'enable_stemming' => false,
            'html_processor_factory' => static fn(): object => $processor,
        ]);

        return $analyzer->analyze_content($source, ['document_lang' => 'en']);
    };

    foreach (['SCRIPT', 'STYLE', 'TITLE'] as $atomicTag) {
        $terms = $analyze(
            '<' . strtolower($atomicTag) . '>hidden</' . strtolower($atomicTag) . '>visibleword',
            [
                ['type' => '#tag', 'tag' => $atomicTag, 'depth' => 3, 'expects_closer' => false],
                ['type' => '#text', 'text' => 'visibleword', 'depth' => 3],
            ]
        );
        assert_same('visibleword', $terms[0]['term'] ?? null, "{$atomicTag} state must not hide or rename the following sibling text");
        assert_same(1.0, $terms[0]['weight'] ?? null, "{$atomicTag} state must not boost the following sibling text");
    }

    $separatorSource = '<span>foo</span><br><span>bar</span>';
    $fallback = new WP_FTS_Analyzer(['auto_detect_language' => false, 'enable_stemming' => false]);
    assert_same(
        ['foo', 'bar'],
        array_column($fallback->analyze_content($separatorSource, ['document_lang' => 'en']), 'term'),
        'fallback extraction should not join lexical words across BR'
    );
    $processorTerms = $analyze($separatorSource, [
        ['type' => '#tag', 'tag' => 'SPAN', 'depth' => 3, 'expects_closer' => true],
        ['type' => '#text', 'text' => 'foo', 'depth' => 4],
        ['type' => '#tag', 'tag' => 'SPAN', 'depth' => 2, 'closer' => true],
        ['type' => '#tag', 'tag' => 'BR', 'depth' => 3, 'expects_closer' => false],
        ['type' => '#tag', 'tag' => 'SPAN', 'depth' => 3, 'expects_closer' => true],
        ['type' => '#text', 'text' => 'bar', 'depth' => 4],
        ['type' => '#tag', 'tag' => 'SPAN', 'depth' => 2, 'closer' => true],
    ]);
    assert_same(['foo', 'bar'], array_column($processorTerms, 'term'), 'processor extraction should not join lexical words across BR');
});

test_case('processors without the WordPress 6.6 depth event contract use the fallback parser', function (): void {
    $processor = new class {
        public int $calls = 0;

        /** Fail if capability detection consumes an incomplete processor. */
        public function next_token(): bool
        {
            $this->calls++;
            throw new RuntimeException('an incomplete processor must not be consumed');
        }
    };
    $analyzer = new WP_FTS_Analyzer([
        'auto_detect_language' => false,
        'enable_stemming' => false,
        'html_processor_factory' => static fn(): object => $processor,
    ]);

    assert_same(
        ['fallbackword'],
        array_column($analyzer->analyze_content('<p>fallbackword</p>', ['document_lang' => 'en']), 'term'),
        'an incomplete processor should preserve complete analysis through the fallback parser'
    );
    assert_same(0, $processor->calls, 'processor capability checks should reject an incomplete provider before its first token');
});

test_case('HTML language attributes reject bytes and subtags before processor creation or SQL', function (): void {
    global $wpdb;

    $oldWpdb = $wpdb ?? null;
    $fake = new WP_FTS_Test_WPDB();
    $wpdb = $fake;
    $processorCalls = 0;
    $analyzer = new WP_FTS_Analyzer([
        'default_lang' => 'en',
        'html_processor_factory' => static function () use (&$processorCalls): object {
            $processorCalls++;
            throw new RuntimeException('processor must not be constructed for rejected syntax');
        },
    ]);

    try {
        $beforeQueries = $fake->num_queries;
        $languageBytesError = wp_fts_markup_caught(static fn(): array => $analyzer->analyze_content(
            '<p lang="' . str_repeat('e', 65) . '">boundedword</p>'
        ));
        assert_same('html_language_attribute_bytes', $languageBytesError instanceof WP_FTS_Analysis_Limit_Exceeded ? $languageBytesError->reason_code : null, 'language byte 65 should fail with a typed reason');

        $languageSubtagsError = wp_fts_markup_caught(static fn(): array => $analyzer->analyze_content(
            '<p xml:lang="en-a-b-c-d-e-f-g-h">boundedword</p>'
        ));
        assert_same('html_language_subtags', $languageSubtagsError instanceof WP_FTS_Analysis_Limit_Exceeded ? $languageSubtagsError->reason_code : null, 'language subtag nine should fail with a typed reason');

        assert_same(0, $processorCalls, 'both hostile language attributes must reject before the configured processor factory');
        assert_same($beforeQueries, $fake->num_queries, 'both hostile language attributes must execute zero SQL');
    } finally {
        $wpdb = $oldWpdb;
    }
});

test_case('post extraction and field preparation reject markup before either visible-text parser or a custom analyzer', function (): void {
    $invalidSources = [
        'html_element_depth' => str_repeat('<span>', 257) . 'boundedword' . str_repeat('</span>', 257),
        'html_tag_bytes' => '<p' . str_repeat(' ', WP_FTS_Analysis_Limits::MAX_HTML_TAG_BYTES - 2) . '>boundedword</p>',
        'html_attributes_per_tag' => '<p ' . implode(' ', array_map(
            static fn(int $number): string => 'data-' . $number,
            range(1, 129)
        )) . '>boundedword</p>',
    ];

    $extractor = new WP_FTS_PostContentExtractor();
    foreach ($invalidSources as $reason => $source) {
        $post = (object) [
            'ID' => 1,
            'post_type' => 'post',
            'post_title' => '',
            'post_content' => $source,
            'post_excerpt' => '',
            'post_status' => 'publish',
            'post_date_gmt' => '2025-01-01 00:00:00',
            'terms' => [],
            'custom_fields' => [],
        ];
        $error = wp_fts_markup_caught(static fn(): array => $extractor->extract($post));
        assert_same($reason, $error instanceof WP_FTS_Analysis_Limit_Exceeded ? $error->reason_code : null, "extractor should reject {$reason} before visible text is built");
    }

    $analyzer = new class {
        public int $calls = 0;

        /** Give field preparation a stable signature without invoking real analysis. */
        public function index_signature(): string
        {
            return 'markup-prevalidation-probe';
        }

        /** Record any forbidden HTML analysis after markup prevalidation should fail. */
        public function analyze_content(string $_html, array $_options = []): array
        {
            $this->calls++;
            return [];
        }

        /** Record any forbidden plain analysis after markup prevalidation should fail. */
        public function analyze_plain_content(string $_text, array $_options = []): array
        {
            $this->calls++;
            return [];
        }
    };
    $indexer = new WP_FTS_Indexer(new WP_FTS_Storage_InMemory(), $analyzer);
    foreach ($invalidSources as $reason => $source) {
        $error = wp_fts_markup_caught(static fn(): array => $indexer->prepare_document_fields(
            1,
            [['name' => 'content', 'text' => 'boundedword', 'html' => $source]],
            ['metadata' => []]
        ));
        assert_same($reason, $error instanceof WP_FTS_Analysis_Limit_Exceeded ? $error->reason_code : null, "field preparation should reject {$reason} before metadata parsing");
    }
    assert_same(0, $analyzer->calls, 'invalid field markup should reject before a custom analyzer is invoked');
});

test_case('encoded metadata extraction stays bounded until the analyzer rejects dense source', function (): void {
    $result = test_run_subprocess([
        PHP_BINARY,
        '-d',
        'memory_limit=128M',
        dirname(__DIR__) . '/fixtures/encoded-metadata-containment.php',
    ], dirname(__DIR__, 2));
    assert_same(0, $result['exit'], 'the encoded-source containment process should finish under 128 MiB: ' . $result['stderr']);

    $payload = json_decode(trim($result['stdout']), true);
    assert_true(is_array($payload), 'the encoded-source containment process should emit JSON evidence');
    assert_same(1500000, $payload['source_bytes'] ?? null, 'the fixture should retain the full valid 1.5-MiB adversarial source');
    assert_same('WP_FTS_Analysis_Limit_Exceeded', $payload['error']['class'] ?? null, 'dense encoded source should reach a typed analysis rejection instead of OOM');
    assert_same('occurrences', $payload['error']['reason_code'] ?? null, 'dense encoded source should stop at the fixed occurrence limit');
    assert_true((float) ($payload['elapsed_seconds'] ?? INF) <= 2.0, 'dense encoded metadata extraction and rejection should complete within two seconds');
    assert_true(
        (int) ($payload['php_peak_delta_bytes'] ?? PHP_INT_MAX) <= 16 * 1024 * 1024,
        'dense encoded metadata extraction should add at most 16 MiB of PHP allocation'
    );
    assert_true(
        (int) ($payload['php_peak_bytes'] ?? PHP_INT_MAX) <= 128 * 1024 * 1024,
        'dense encoded metadata extraction should remain below the 128-MiB PHP ceiling'
    );
    foreach (['VmHWM_bytes', 'VmRSS_bytes'] as $metric) {
        $value = $payload['proc_status'][$metric] ?? null;
        if (is_int($value)) {
            assert_true($value <= 128 * 1024 * 1024, "dense encoded metadata {$metric} should remain below 128 MiB");
        }
    }
});

test_case('maximum HTML depth times maximum markup tokens stays linear under 128 MiB', function (): void {
    $variants = [
        'a' => [
            'source_bytes' => 89490,
            'occurrences' => 0,
            'occurrences_sha256' => 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855',
        ],
        'aa' => [
            'source_bytes' => 99235,
            'occurrences' => 9745,
            'occurrences_sha256' => '5c49b23ca75ba14d8df9a727368bbc849c02d8dfccf709e0fa63fec7513011e3',
        ],
    ];

    foreach ($variants as $word => $expected) {
        $result = test_run_subprocess([
            PHP_BINARY,
            '-d',
            'memory_limit=128M',
            dirname(__DIR__) . '/fixtures/html-depth-token-product-containment.php',
            $word,
        ], dirname(__DIR__, 2));
        assert_same(0, $result['exit'], "the {$word} depth-by-token process should finish under 128 MiB: " . $result['stderr']);

        $payload = json_decode(trim($result['stdout']), true);
        assert_true(is_array($payload), "the {$word} depth-by-token process should emit JSON evidence");
        assert_same($expected['source_bytes'], $payload['source_bytes'] ?? null, "the {$word} fixture should retain its exact source bytes");
        assert_same(20000, $payload['markup_tokens'] ?? null, "the {$word} fixture should reach the exact markup-token ceiling");
        assert_same(256, $payload['max_element_depth'] ?? null, "the {$word} fixture should reach the exact element-depth ceiling");
        assert_same($expected['occurrences'], $payload['occurrences'] ?? null, "the {$word} fixture should preserve its complete occurrence output");
        assert_same($expected['occurrences_sha256'], $payload['occurrences_sha256'] ?? null, "the {$word} fixture should preserve every ordered term, weight, and language");
        assert_true(
            (float) ($payload['elapsed_seconds'] ?? INF) <= 2.0,
            "the {$word} depth-by-token analysis should complete within two seconds"
        );
        assert_true(
            (int) ($payload['php_peak_delta_bytes'] ?? PHP_INT_MAX) <= 16 * 1024 * 1024,
            "the {$word} depth-by-token analysis should add at most 16 MiB of PHP allocation"
        );
        assert_true(
            (int) ($payload['php_peak_bytes'] ?? PHP_INT_MAX) <= 128 * 1024 * 1024,
            "the {$word} depth-by-token analysis should remain below the 128-MiB PHP ceiling"
        );
        foreach (['VmHWM_bytes', 'VmRSS_bytes'] as $metric) {
            $value = $payload['proc_status'][$metric] ?? null;
            if (is_int($value)) {
                assert_true($value <= 128 * 1024 * 1024, "the {$word} depth-by-token {$metric} should remain below 128 MiB");
            }
        }
    }
});

test_case('inline lexical coalescing accepts 4 KiB exactly and rejects worst-case growth under 128 MiB', function (): void {
    foreach (['exact', 'worst'] as $variant) {
        $result = test_run_subprocess([
            PHP_BINARY,
            '-d',
            'memory_limit=128M',
            dirname(__DIR__) . '/fixtures/html-inline-lexical-run-containment.php',
            $variant,
        ], dirname(__DIR__, 2));
        assert_same(0, $result['exit'], "the {$variant} inline-run process should finish under 128 MiB: " . $result['stderr']);

        $payload = json_decode(trim($result['stdout']), true);
        assert_true(is_array($payload), "the {$variant} inline-run process should emit JSON evidence");
        if ($variant === 'exact') {
            assert_same(4096, $payload['provided_tokens'] ?? null, 'the exact inline run should contain 4,096 one-byte processor segments');
            assert_same(4097, $payload['processor_calls'] ?? null, 'the exact inline provider should be observed exhausting normally');
            assert_same(null, $payload['error'] ?? null, 'the exact 4-KiB cross-element lexical run should remain valid');
            assert_same(1, $payload['occurrences'] ?? null, 'the exact 4-KiB lexical run should remain one complete occurrence');
            assert_same(4096, $payload['first_term_bytes'] ?? null, 'the exact lexical occurrence must not be truncated');
        } else {
            assert_same(20000, $payload['provided_tokens'] ?? null, 'the worst inline run should retain the maximum custom-provider text-token envelope');
            assert_same(20001, $payload['processor_calls'] ?? null, 'the worst inline provider should be observed exhausting normally');
            assert_same('WP_FTS_Analysis_Limit_Exceeded', $payload['error']['class'] ?? null, 'the worst inline run should reject with a typed limit instead of exhausting memory');
            assert_same('lexical_run_bytes', $payload['error']['reason_code'] ?? null, 'the worst inline run should stop at the 4-KiB lexical invariant');
        }

        assert_true(
            (float) ($payload['elapsed_seconds'] ?? INF) <= 2.0,
            "the {$variant} inline-run analysis should complete within two seconds"
        );
        assert_true(
            (int) ($payload['php_peak_delta_bytes'] ?? PHP_INT_MAX) <= 16 * 1024 * 1024,
            "the {$variant} inline-run analysis should add at most 16 MiB of PHP allocation"
        );
        assert_true(
            (int) ($payload['php_peak_bytes'] ?? PHP_INT_MAX) <= 128 * 1024 * 1024,
            "the {$variant} inline-run analysis should remain below the 128-MiB PHP ceiling"
        );
        foreach (['VmHWM_bytes', 'VmRSS_bytes'] as $metric) {
            $value = $payload['proc_status'][$metric] ?? null;
            if (is_int($value)) {
                assert_true($value <= 128 * 1024 * 1024, "the {$variant} inline-run {$metric} should remain below 128 MiB");
            }
        }
    }
});

test_case('streamed HTML metadata preserves exact boundary output and never truncates indexed fields', function (): void {
    $indexer = new WP_FTS_Indexer(
        new WP_FTS_Storage_InMemory(),
        new WP_FTS_Analyzer([
            'auto_detect_language' => false,
            'enable_stemming' => false,
            'default_lang' => 'en',
        ])
    );

    $underBoundaryHtml = '<em data-source="first">&#97;</em> <strong data-source="second">&#98;</strong>';
    $underBoundary = $indexer->prepare_document_fields(
        1,
        [['name' => 'content', 'text' => 'a b', 'html' => $underBoundaryHtml]],
        ['metadata' => ['required_marker' => 'preserve-me'], 'metadata_html_limit' => 20000]
    );
    assert_same($underBoundaryHtml, $underBoundary['metadata']['search_html'] ?? null, 'streamed compaction should remain byte-for-byte equivalent below the sidecar boundary');
    assert_same('preserve-me', $underBoundary['metadata']['required_marker'] ?? null, 'HTML sidecar construction must preserve independent metadata fields');

    $fragments = [];
    $fragmentBytes = 0;
    for ($number = 0; ; $number++) {
        $fragment = '<i data-n="' . str_pad((string) $number, 4, '0', STR_PAD_LEFT) . '">&#97;</i>';
        $nextBytes = $fragmentBytes + ($fragments === [] ? 0 : 1) + strlen($fragment);
        if ($nextBytes > 19500) {
            break;
        }
        $fragments[] = $fragment;
        $fragmentBytes = $nextBytes;
    }
    $lastPrefix = '<i data-pad="';
    $lastSuffix = '">&#97;</i>';
    $separatorBytes = $fragments === [] ? 0 : 1;
    $paddingBytes = 20000 - $fragmentBytes - $separatorBytes - strlen($lastPrefix) - strlen($lastSuffix);
    assert_true($paddingBytes >= 0 && $paddingBytes <= 4096, 'the exact-boundary fixture should need one valid bounded attribute');
    $fragments[] = $lastPrefix . str_repeat('x', $paddingBytes) . $lastSuffix;
    $exactBoundaryHtml = implode(' ', $fragments);
    assert_same(20000, strlen($exactBoundaryHtml), 'the streamed-sidecar fixture should be exactly 20 KiB');

    $fields = [
        ['name' => 'content', 'text' => 'semantic alpha', 'html' => $exactBoundaryHtml],
        ['name' => 'second', 'text' => 'semantic omega'],
    ];
    $metadata = ['required_marker' => 'preserve-me'];
    $exactBoundary = $indexer->prepare_document_fields(2, $fields, [
        'metadata' => $metadata,
        'metadata_html_limit' => 20000,
    ]);
    $shortSidecar = $indexer->prepare_document_fields(2, $fields, [
        'metadata' => $metadata,
        'metadata_html_limit' => 19999,
    ]);

    assert_same($exactBoundaryHtml, $exactBoundary['metadata']['search_html'] ?? null, 'the exact 20-KiB sidecar boundary should be preserved without truncation or reordering');
    assert_same(20000, strlen((string) ($exactBoundary['metadata']['search_html'] ?? '')), 'the exact sidecar boundary should retain every fitting byte');
    assert_same(19999, strlen((string) ($shortSidecar['metadata']['search_html'] ?? '')), 'one smaller configured sidecar should stop at its declared presentation byte limit');
    assert_same($exactBoundary['term_frequencies'], $shortSidecar['term_frequencies'], 'a presentation-sidecar limit must not truncate or change semantic field analysis');
    assert_same($exactBoundary['content_hash'], $shortSidecar['content_hash'], 'a presentation-sidecar limit must not change the indexed-source fingerprint');
    assert_same($exactBoundary['metadata']['search_fields'], $shortSidecar['metadata']['search_fields'], 'a presentation-sidecar limit must preserve independently bounded field metadata');
    assert_same('preserve-me', $shortSidecar['metadata']['required_marker'] ?? null, 'a full sidecar must not prevent later required metadata from being retained');
});
