<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

$checks = 0;

/** Assert one constructor rejects a malformed option immediately. */
function wp_fts_constructor_options_rejects(callable $construct, string $label): void
{
    global $checks;

    try {
        $construct();
    } catch (InvalidArgumentException) {
        $checks++;
        return;
    }

    throw new RuntimeException("Expected constructor rejection: {$label}");
}

/** Require one analyzer boundary call to throw the requested exception class. */
function wp_fts_analyzer_contract_catches(callable $call, string $class, string $label): Throwable
{
    global $checks;

    try {
        $call();
    } catch (Throwable $error) {
        if (!$error instanceof $class) {
            throw new RuntimeException("Expected {$class} for {$label}, got " . get_debug_type($error));
        }
        $checks++;

        return $error;
    }

    throw new RuntimeException("Expected analyzer rejection: {$label}");
}

/** Build one configurable HTML processor contract probe. */
function wp_fts_analyzer_processor_probe(array $outputs = []): object
{
    return new class ($outputs) {
        private bool $emitted = false;

        public function __construct(private array $outputs)
        {
        }

        public function next_token(): mixed
        {
            if (array_key_exists('next_token', $this->outputs)) {
                return $this->output('next_token');
            }
            if ($this->emitted) {
                return false;
            }

            $this->emitted = true;
            return true;
        }

        public function get_current_depth(): mixed
        {
            return $this->output('get_current_depth', 0);
        }

        public function get_token_type(): mixed
        {
            return $this->output('get_token_type', '#text');
        }

        public function get_tag(): mixed
        {
            return $this->output('get_tag', 'P');
        }

        public function is_tag_closer(): mixed
        {
            return $this->output('is_tag_closer', false);
        }

        public function expects_closer(): mixed
        {
            return $this->output('expects_closer', true);
        }

        public function get_modifiable_text(): mixed
        {
            return $this->output('get_modifiable_text', 'Needle');
        }

        public function get_attribute(string $name): mixed
        {
            $value = $this->outputs['get_attribute'] ?? null;

            return $value instanceof Closure ? $value($name) : $value;
        }

        private function output(string $method, mixed $default = null): mixed
        {
            $value = $this->outputs[$method] ?? $default;

            return $value instanceof Closure ? $value() : $value;
        }
    };
}

/** Build an invokable processor factory with an explicit index signature. */
function wp_fts_analyzer_signature_factory(mixed $signature, ?Throwable $failure = null): object
{
    return new class ($signature, $failure) {
        public function __construct(
            private mixed $signature,
            private ?Throwable $failure
        ) {
        }

        public function __invoke(string $_html): object
        {
            return wp_fts_analyzer_processor_probe();
        }

        public function index_signature(): mixed
        {
            if ($this->failure !== null) {
                throw $this->failure;
            }

            return $this->signature;
        }
    };
}

wp_fts_constructor_options_rejects(
    static fn(): WP_FTS_Analyzer => new WP_FTS_Analyzer(['unknown_option' => true]),
    'analyzer unknown key'
);
wp_fts_constructor_options_rejects(
    static fn(): WP_FTS_Analyzer => new WP_FTS_Analyzer(['auto_detect_language' => 1]),
    'analyzer boolean coercion'
);
wp_fts_constructor_options_rejects(
    static fn(): WP_FTS_Analyzer => new WP_FTS_Analyzer(['boosts' => ['H1' => '4']]),
    'analyzer boost coercion'
);
wp_fts_constructor_options_rejects(
    static fn(): WP_FTS_Analyzer => new WP_FTS_Analyzer(['boosts' => [' H1' => 4]]),
    'analyzer padded boost name'
);
wp_fts_constructor_options_rejects(
    static fn(): WP_FTS_Analyzer => new WP_FTS_Analyzer(['boosts' => ['H@1' => 4]]),
    'analyzer impossible boost name'
);
wp_fts_constructor_options_rejects(
    static fn(): WP_FTS_Analyzer => new WP_FTS_Analyzer(['boosts' => ['h1' => 2, 'H1' => 4]]),
    'analyzer duplicate canonical boost name'
);
wp_fts_constructor_options_rejects(
    static fn(): WP_FTS_Analyzer => new WP_FTS_Analyzer(['skip_ancestors' => [' SCRIPT']]),
    'analyzer padded skipped ancestor'
);
wp_fts_constructor_options_rejects(
    static fn(): WP_FTS_Analyzer => new WP_FTS_Analyzer(['skip_ancestors' => ['!!!']]),
    'analyzer impossible skipped ancestor'
);
wp_fts_constructor_options_rejects(
    static fn(): WP_FTS_Analyzer => new WP_FTS_Analyzer(['skip_ancestors' => ['script', 'SCRIPT']]),
    'analyzer duplicate canonical skipped ancestor'
);
wp_fts_constructor_options_rejects(
    static fn(): WP_FTS_Analyzer => new WP_FTS_Analyzer(['stopwords' => [' the']]),
    'analyzer padded stopword'
);
wp_fts_constructor_options_rejects(
    static fn(): WP_FTS_Analyzer => new WP_FTS_Analyzer(['default_lang' => 'C']),
    'analyzer invalid language'
);
foreach (['default_lang', 'document_lang', 'query_lang'] as $key) {
    foreach ([' en', 'e!n', 'en-a'] as $language) {
        wp_fts_constructor_options_rejects(
            static fn(): WP_FTS_Analyzer => new WP_FTS_Analyzer([$key => $language]),
            "analyzer {$key} strict language"
        );
    }
}
foreach ([' en', 'e!n', 'en-a'] as $language) {
    wp_fts_constructor_options_rejects(
        static fn(): WP_FTS_Analyzer => new WP_FTS_Analyzer([
            'stopwords_by_lang' => [$language => ['the']],
        ]),
        'analyzer stopword strict language'
    );
}
wp_fts_constructor_options_rejects(
    static fn(): WP_FTS_Analyzer => new WP_FTS_Analyzer([
        'stopwords_by_lang' => [
            'en_US' => ['the'],
            'en-US' => ['a'],
        ],
    ]),
    'analyzer duplicate canonical stopword language'
);
wp_fts_constructor_options_rejects(
    static fn(): WP_FTS_Analyzer => new WP_FTS_Analyzer(['query_language_resolver' => new stdClass()]),
    'analyzer invalid resolver'
);
wp_fts_constructor_options_rejects(
    static fn(): WP_FTS_Analyzer => new WP_FTS_Analyzer([
        'language_pipeline' => new WP_FTS_LanguagePipeline(),
        'min_term_len' => 2,
    ]),
    'analyzer ignored pipeline option'
);
foreach ([null, true, [], 1, '', ' manifest.json '] as $value) {
    wp_fts_constructor_options_rejects(
        static fn(): WP_FTS_Analyzer => new WP_FTS_Analyzer([
            'lemma_packs_by_lang' => ['en' => $value],
        ]),
        'analyzer exact lemma-pack value'
    );
}
foreach ([null, 'yes', 1, []] as $value) {
    wp_fts_constructor_options_rejects(
        static fn(): WP_FTS_Analyzer => new WP_FTS_Analyzer([
            'segmenter_packs_by_lang' => ['zh' => $value],
        ]),
        'analyzer exact segmenter-pack value'
    );
}

$documentResolverCalls = 0;
$strictAnalyzer = new WP_FTS_Analyzer([
    'auto_detect_language' => false,
    'enable_stemming' => false,
    'document_language_resolver' => static function () use (&$documentResolverCalls): string {
        $documentResolverCalls++;
        return 'en';
    },
]);
$documentCalls = [
    'HTML' => static fn(array $options): array => $strictAnalyzer->analyze_content('<p>Needle</p>', $options),
    'plain text' => static fn(array $options): array => $strictAnalyzer->analyze_plain_content('Needle', $options),
    'field batch' => static fn(array $options): array => $strictAnalyzer->analyze_document_fields([[
        'name' => 'content',
        'text' => 'Needle',
        'boost' => 1.0,
    ]], $options),
];
foreach ($documentCalls as $surface => $call) {
    foreach ([
        'unknown option' => ['unknown' => true],
        'numeric option key' => [0 => 'document_lang'],
        'coercible language' => ['document_lang' => 1],
        'padded language' => ['document_lang' => ' en'],
        'malformed language' => ['document_lang' => 'e!n'],
        'coercible post ID' => ['post_id' => '1'],
        'coercible surface flag' => ['_include_document_surface' => 1],
        'coercible occurrence ceiling' => ['_max_document_occurrences' => '1'],
        'negative occurrence ceiling' => ['_max_document_occurrences' => -1],
        'oversized occurrence ceiling' => [
            '_max_document_occurrences' => WP_FTS_Analysis_Limits::MAX_DOCUMENT_OCCURRENCES + 1,
        ],
    ] as $description => $options) {
        wp_fts_analyzer_contract_catches(
            static fn(): array => $call($options),
            InvalidArgumentException::class,
            "{$surface} {$description}"
        );
    }
}
if ($documentResolverCalls !== 0) {
    throw new RuntimeException('Malformed document calls must reject before a language resolver runs.');
}
$checks++;

$queryResolverCalls = 0;
$strictQueryAnalyzer = new WP_FTS_Analyzer([
    'auto_detect_language' => false,
    'enable_stemming' => false,
    'query_language_resolver' => static function () use (&$queryResolverCalls): string {
        $queryResolverCalls++;
        return 'en';
    },
]);
$queryCalls = [
    'term list' => static fn(array $options): array => $strictQueryAnalyzer->analyze_query('Needle', $options),
    'occurrence list' => static fn(array $options): array => $strictQueryAnalyzer->analyze_query_occurrences('Needle', $options),
];
foreach ($queryCalls as $surface => $call) {
    foreach ([
        'unknown option' => ['unknown' => true],
        'document option' => ['document_lang' => 'en'],
        'coercible language' => ['query_lang' => 1],
        'padded language' => ['query_lang' => ' en'],
        'malformed language' => ['query_lang' => 'e!n'],
        'coercible force flag' => ['_force_query_lang' => 1],
        'coercible surface flag' => ['_include_query_surface' => 'yes'],
        'coercible occurrence ceiling' => ['_max_query_occurrences' => '1'],
        'negative occurrence ceiling' => ['_max_query_occurrences' => -1],
        'oversized occurrence ceiling' => [
            '_max_query_occurrences' => WP_FTS_Analysis_Limits::MAX_DOCUMENT_OCCURRENCES + 1,
        ],
    ] as $description => $options) {
        wp_fts_analyzer_contract_catches(
            static fn(): array => $call($options),
            InvalidArgumentException::class,
            "{$surface} {$description}"
        );
    }
}
if ($queryResolverCalls !== 0) {
    throw new RuntimeException('Malformed query calls must reject before a language resolver runs.');
}
$checks++;

$fieldResolverCalls = 0;
$fieldAnalyzer = new WP_FTS_Analyzer([
    'auto_detect_language' => false,
    'enable_stemming' => false,
    'document_language_resolver' => static function () use (&$fieldResolverCalls): string {
        $fieldResolverCalls++;
        return 'en';
    },
]);
foreach ([
    'associative field collection' => ['content' => ['name' => 'content', 'text' => 'Needle', 'boost' => 1.0]],
    'non-array field' => ['Needle'],
    'unknown field key' => [['name' => 'content', 'text' => 'Needle', 'boost' => 1.0, 'unknown' => true]],
    'missing field boost' => [['name' => 'content', 'text' => 'Needle']],
    'coercible field name' => [['name' => 1, 'text' => 'Needle', 'boost' => 1.0]],
    'coercible field text' => [['name' => 'content', 'text' => 1, 'boost' => 1.0]],
    'coercible field HTML' => [['name' => 'content', 'text' => 'Needle', 'html' => 1, 'boost' => 1.0]],
    'integer normalized boost' => [['name' => 'content', 'text' => 'Needle', 'boost' => 1]],
    'fractional normalized boost' => [['name' => 'content', 'text' => 'Needle', 'boost' => 1.5]],
] as $description => $fields) {
    wp_fts_analyzer_contract_catches(
        static fn(): array => $fieldAnalyzer->analyze_document_fields($fields),
        InvalidArgumentException::class,
        $description
    );
}
if ($fieldResolverCalls !== 0) {
    throw new RuntimeException('Malformed normalized fields must reject before a language resolver runs.');
}
$checks++;

$validFields = $fieldAnalyzer->analyze_document_fields([[
    'name' => 'content',
    'text' => 'Needle',
    'boost' => 1.0,
]]);
if (($validFields[0][0]['term'] ?? null) !== 'needle') {
    throw new RuntimeException('An exact normalized field row must remain accepted.');
}
$checks++;

$documentCallbackAnalyzer = new WP_FTS_Analyzer([
    'auto_detect_language' => false,
    'enable_stemming' => false,
    'document_language_resolver' => static fn(): string => 'fr_FR',
]);
if (($documentCallbackAnalyzer->analyze_plain_content('Needle')[0]['lang'] ?? null) !== 'fr-FR') {
    throw new RuntimeException('A document resolver must accept one exact locale-style language string.');
}
$checks++;

$queryCallbackAnalyzer = new WP_FTS_Analyzer([
    'auto_detect_language' => false,
    'enable_stemming' => false,
    'query_language_resolver' => static fn(): string => 'nl_NL',
]);
if (($queryCallbackAnalyzer->analyze_query_occurrences('Needle')[0]['lang'] ?? null) !== 'nl-NL') {
    throw new RuntimeException('A query resolver must accept one exact locale-style language string.');
}
$checks++;

$termCallbackAnalyzer = new WP_FTS_Analyzer([
    'auto_detect_language' => false,
    'enable_stemming' => false,
    'query_term_language_resolver' => static fn(string $_token, array $_options, string $_default): string => 'pl_PL',
]);
if (($termCallbackAnalyzer->analyze_query_occurrences('Needle')[0]['lang'] ?? null) !== 'pl-PL') {
    throw new RuntimeException('A per-token query resolver must accept one exact locale-style language string.');
}
$checks++;

foreach (['plain text', 'HTML', 'field batch'] as $surface) {
    $calls = 0;
    $analyzer = new WP_FTS_Analyzer([
        'enable_stemming' => false,
        'document_language_resolver' => static function () use (&$calls): string {
            $calls++;
            return 'fr';
        },
    ]);
    match ($surface) {
        'plain text' => $analyzer->analyze_plain_content('Needle'),
        'HTML' => $analyzer->analyze_content('<p>Needle</p>'),
        default => $analyzer->analyze_document_fields([[
            'name' => 'content',
            'text' => 'Needle',
            'boost' => 1.0,
        ]]),
    };
    if ($calls !== 1) {
        throw new RuntimeException("{$surface} analysis must call its document language resolver exactly once.");
    }
    $checks++;
}

foreach (['untagged' => 'Needle bridge', 'tagged' => 'pl:zamek bridge'] as $surface => $query) {
    $calls = 0;
    $analyzer = new WP_FTS_Analyzer([
        'enable_stemming' => false,
        'query_language_resolver' => static function () use (&$calls): string {
            $calls++;
            return 'nl';
        },
    ]);
    $analyzer->analyze_query_occurrences($query);
    if ($calls !== 1) {
        throw new RuntimeException("A {$surface} query must call its query language resolver exactly once.");
    }
    $checks++;
}

foreach ([1, '', ' en', 'e!n'] as $malformedLanguage) {
    foreach ([
        'document' => static fn(): array => (new WP_FTS_Analyzer([
            'auto_detect_language' => false,
            'document_language_resolver' => static fn(): mixed => $malformedLanguage,
        ]))->analyze_plain_content('Needle'),
        'query' => static fn(): array => (new WP_FTS_Analyzer([
            'auto_detect_language' => false,
            'query_language_resolver' => static fn(): mixed => $malformedLanguage,
        ]))->analyze_query_occurrences('Needle'),
        'per-token query' => static fn(): array => (new WP_FTS_Analyzer([
            'auto_detect_language' => false,
            'query_term_language_resolver' => static fn(): mixed => $malformedLanguage,
        ]))->analyze_query_occurrences('Needle'),
    ] as $surface => $call) {
        wp_fts_analyzer_contract_catches(
            $call,
            UnexpectedValueException::class,
            "{$surface} resolver malformed output"
        );
    }
}

foreach (['document', 'query', 'per-token query'] as $surface) {
    $failure = new DomainException("{$surface} resolver failure");
    $call = match ($surface) {
        'document' => static fn(): array => (new WP_FTS_Analyzer([
            'auto_detect_language' => false,
            'document_language_resolver' => static function () use ($failure): never {
                throw $failure;
            },
        ]))->analyze_plain_content('Needle'),
        'query' => static fn(): array => (new WP_FTS_Analyzer([
            'auto_detect_language' => false,
            'query_language_resolver' => static function () use ($failure): never {
                throw $failure;
            },
        ]))->analyze_query_occurrences('Needle'),
        default => static fn(): array => (new WP_FTS_Analyzer([
            'auto_detect_language' => false,
            'query_term_language_resolver' => static function () use ($failure): never {
                throw $failure;
            },
        ]))->analyze_query_occurrences('Needle'),
    };
    $caught = wp_fts_analyzer_contract_catches($call, DomainException::class, "{$surface} resolver failure");
    if ($caught !== $failure) {
        throw new RuntimeException("The {$surface} resolver failure must reach its caller unchanged.");
    }
    $checks++;
}

$factoryFailure = new DomainException('processor factory failure');
$caughtFactoryFailure = wp_fts_analyzer_contract_catches(
    static fn(): array => (new WP_FTS_Analyzer([
        'auto_detect_language' => false,
        'html_processor_factory' => static function () use ($factoryFailure): never {
            throw $factoryFailure;
        },
    ]))->analyze_content('<p>Needle</p>', ['document_lang' => 'en']),
    DomainException::class,
    'processor factory failure'
);
if ($caughtFactoryFailure !== $factoryFailure) {
    throw new RuntimeException('An HTML processor factory failure must reach its caller unchanged.');
}
$checks++;

foreach ([
    'null' => null,
    'scalar' => 'processor',
    'partial object' => new class {
        public int $calls = 0;

        public function next_token(): bool
        {
            $this->calls++;
            return false;
        }
    },
] as $description => $processor) {
    wp_fts_analyzer_contract_catches(
        static fn(): array => (new WP_FTS_Analyzer([
            'auto_detect_language' => false,
            'html_processor_factory' => static fn(): mixed => $processor,
        ]))->analyze_content('<p>Needle</p>', ['document_lang' => 'en']),
        UnexpectedValueException::class,
        "processor factory {$description} output"
    );
    if (is_object($processor) && property_exists($processor, 'calls') && $processor->calls !== 0) {
        throw new RuntimeException('A partial HTML processor must reject before its first token call.');
    }
    $checks++;
}

foreach ([
    'next_token integer' => ['next_token' => 1],
    'token type integer' => ['get_token_type' => 1],
    'padded token type' => ['get_token_type' => ' #text'],
    'depth numeric string' => ['get_current_depth' => '0'],
    'text integer' => ['get_modifiable_text' => 1],
    'tag integer' => ['get_token_type' => '#tag', 'get_current_depth' => 1, 'get_tag' => 1],
    'padded tag' => ['get_token_type' => '#tag', 'get_current_depth' => 1, 'get_tag' => ' P'],
    'lowercase tag' => ['get_token_type' => '#tag', 'get_current_depth' => 1, 'get_tag' => 'p'],
    'impossible tag' => ['get_token_type' => '#tag', 'get_current_depth' => 1, 'get_tag' => 'H@1'],
    'closer integer' => ['get_token_type' => '#tag', 'get_current_depth' => 1, 'is_tag_closer' => 0],
    'closer expectation integer' => [
        'get_token_type' => '#tag',
        'get_current_depth' => 1,
        'expects_closer' => 1,
    ],
    'attribute integer' => [
        'get_token_type' => '#tag',
        'get_current_depth' => 1,
        'get_attribute' => 1,
    ],
] as $description => $outputs) {
    wp_fts_analyzer_contract_catches(
        static fn(): array => (new WP_FTS_Analyzer([
            'auto_detect_language' => false,
            'enable_stemming' => false,
            'html_processor_factory' => static fn(): object => wp_fts_analyzer_processor_probe($outputs),
        ]))->analyze_content('<p>Needle</p>', ['document_lang' => 'en']),
        UnexpectedValueException::class,
        "processor {$description} output"
    );
}

$processorMethodFailure = new DomainException('processor attribute failure');
$caughtProcessorMethodFailure = wp_fts_analyzer_contract_catches(
    static fn(): array => (new WP_FTS_Analyzer([
        'auto_detect_language' => false,
        'enable_stemming' => false,
        'html_processor_factory' => static fn(): object => wp_fts_analyzer_processor_probe([
            'get_token_type' => '#tag',
            'get_current_depth' => 1,
            'get_attribute' => static function () use ($processorMethodFailure): never {
                throw $processorMethodFailure;
            },
        ]),
    ]))->analyze_content('<p lang="en">Needle</p>', ['document_lang' => 'en']),
    DomainException::class,
    'processor method failure'
);
if ($caughtProcessorMethodFailure !== $processorMethodFailure) {
    throw new RuntimeException('An HTML processor method failure must reach its caller unchanged.');
}
$checks++;

foreach ([
    'integer' => 1,
    'empty string' => '',
    'padded string' => ' processor-v1',
    'oversized string' => str_repeat('s', WP_FTS_Analyzer_Config_Limits::MAX_OPTION_SCALAR_BYTES + 1),
] as $description => $signature) {
    wp_fts_analyzer_contract_catches(
        static fn(): WP_FTS_Analyzer => new WP_FTS_Analyzer([
            'auto_detect_language' => false,
            'html_processor_factory' => wp_fts_analyzer_signature_factory($signature),
        ]),
        UnexpectedValueException::class,
        "processor index signature {$description}"
    );
}

$signatureFailure = new DomainException('processor signature failure');
$caughtSignatureFailure = wp_fts_analyzer_contract_catches(
    static fn(): WP_FTS_Analyzer => new WP_FTS_Analyzer([
        'auto_detect_language' => false,
        'html_processor_factory' => wp_fts_analyzer_signature_factory('unused', $signatureFailure),
    ]),
    DomainException::class,
    'processor index signature failure'
);
if ($caughtSignatureFailure !== $signatureFailure) {
    throw new RuntimeException('An extension index-signature failure must reach its caller unchanged.');
}
$checks++;

new WP_FTS_Analyzer([
    'auto_detect_language' => false,
    'html_processor_factory' => wp_fts_analyzer_signature_factory(
        str_repeat('s', WP_FTS_Analyzer_Config_Limits::MAX_OPTION_SCALAR_BYTES)
    ),
]);
$checks++;

$builtInParserTerms = (new WP_FTS_Analyzer([
    'auto_detect_language' => false,
    'enable_stemming' => false,
]))->analyze_content('<p>Plain <b>text</b></p><script>ignored</script>', ['document_lang' => 'en']);
if (array_column($builtInParserTerms, 'term') !== ['plain', 'text']) {
    throw new RuntimeException('The built-in parser must remain the path used when no processor factory is configured.');
}
$checks++;

wp_fts_constructor_options_rejects(
    static fn(): WP_FTS_LanguagePipeline => new WP_FTS_LanguagePipeline(['unknown_option' => true]),
    'pipeline unknown key'
);
wp_fts_constructor_options_rejects(
    static fn(): WP_FTS_LanguagePipeline => new WP_FTS_LanguagePipeline(['min_term_len' => '2']),
    'pipeline integer coercion'
);
wp_fts_constructor_options_rejects(
    static fn(): WP_FTS_LanguagePipeline => new WP_FTS_LanguagePipeline(['max_term_bytes' => 0]),
    'pipeline non-positive byte bound'
);
wp_fts_constructor_options_rejects(
    static fn(): WP_FTS_LanguagePipeline => new WP_FTS_LanguagePipeline([
        'max_term_bytes' => WP_FTS_Analysis_Limits::MAX_LEXICAL_RUN_BYTES + 1,
    ]),
    'pipeline byte bound above source ceiling'
);
wp_fts_constructor_options_rejects(
    static fn(): WP_FTS_LanguagePipeline => new WP_FTS_LanguagePipeline(['stemmer' => new stdClass()]),
    'pipeline invalid stemmer'
);
wp_fts_constructor_options_rejects(
    static fn(): WP_FTS_LanguagePipeline => new WP_FTS_LanguagePipeline(['cjk_tokenizer' => false]),
    'pipeline invalid tokenizer'
);
wp_fts_constructor_options_rejects(
    static fn(): WP_FTS_LanguagePipeline => new WP_FTS_LanguagePipeline([
        'lemma_packs_by_lang' => ['pl' => true],
    ]),
    'pipeline invalid lemma pack option'
);
wp_fts_constructor_options_rejects(
    static fn(): WP_FTS_LanguagePipeline => new WP_FTS_LanguagePipeline([
        'lemma_packs_by_lang' => ['!!!' => false],
    ]),
    'pipeline invalid language key'
);
wp_fts_constructor_options_rejects(
    static fn(): WP_FTS_LanguagePipeline => new WP_FTS_LanguagePipeline([
        'segmenter_packs_by_lang' => ['zh' => 'yes'],
    ]),
    'pipeline invalid segmenter pack option'
);
wp_fts_constructor_options_rejects(
    static fn(): WP_FTS_LanguagePipeline => new WP_FTS_LanguagePipeline([
        'chinese_script_map' => ['zh-Hant' => ['體' => 1]],
    ]),
    'pipeline invalid script-map value'
);

foreach ([
    [['unknown_option' => true], 'normalizer unknown key'],
    [[0 => 'fold_diacritics'], 'normalizer numeric key'],
    [['fold_diacritics' => 1], 'normalizer boolean coercion'],
    [['token_normalizer' => false], 'normalizer false callback'],
    [['token_normalizer' => 'not_a_callable'], 'normalizer string callback'],
    [['token_normalizer' => new stdClass()], 'normalizer object callback'],
    [['chinese_script_map' => null], 'normalizer null script map'],
    [['chinese_script_map' => [0 => ['體' => '体']]], 'normalizer numeric language key'],
    [['chinese_script_map' => ['' => ['體' => '体']]], 'normalizer empty language key'],
    [['chinese_script_map' => [' zh' => ['體' => '体']]], 'normalizer padded language key'],
    [['chinese_script_map' => ['zh ' => ['體' => '体']]], 'normalizer trailing language padding'],
    [['chinese_script_map' => [str_repeat('z', 65) => ['體' => '体']]], 'normalizer oversized language key'],
    [['chinese_script_map' => ['!!!' => ['體' => '体']]], 'normalizer invalid language key'],
    [['chinese_script_map' => ['zh' => 'not_a_map']], 'normalizer scalar character map'],
    [['chinese_script_map' => ['zh' => [0 => '体']]], 'normalizer numeric character key'],
    [['chinese_script_map' => ['zh' => ['' => '体']]], 'normalizer empty character key'],
    [['chinese_script_map' => ['zh' => ['體' => 1]]], 'normalizer non-string replacement'],
] as [$options, $label]) {
    wp_fts_constructor_options_rejects(
        static fn(): WP_FTS_Normalizer => new WP_FTS_Normalizer($options),
        $label
    );
}

wp_fts_constructor_options_rejects(
    static fn(): WP_FTS_LanguageDetector => new WP_FTS_LanguageDetector(['custom_threshold' => 1]),
    'detector constructor option'
);

new WP_FTS_Analyzer([
    'auto_detect_language' => false,
    'boosts' => ['H1' => 4, 'EM' => 1.5],
    'default_lang' => 'en-US',
    'stopwords_by_lang' => ['en' => ['the']],
]);
new WP_FTS_LanguagePipeline([
    'enable_stemming' => false,
    'min_term_len' => 1,
    'max_term_bytes' => WP_FTS_Analysis_Limits::MAX_LEXICAL_RUN_BYTES,
    'lemma_packs_by_lang' => ['pl' => false],
    'segmenter_packs_by_lang' => ['zh' => false],
]);
new WP_FTS_Normalizer([
    'fold_diacritics' => false,
    'token_normalizer' => static fn(string $token, string $_language): string => $token,
    'chinese_script_map' => ['zh-Hant' => ['體' => '体']],
]);
new WP_FTS_Normalizer(['token_normalizer' => null]);
$checks += 4;

return $checks;
