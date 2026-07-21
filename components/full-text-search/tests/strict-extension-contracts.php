<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

$wp_fts_strict_extension_checks = 0;

/** Record one strict extension-contract assertion. */
$wp_fts_strict_extension_check = static function (bool $condition, string $message) use (&$wp_fts_strict_extension_checks): void {
    $wp_fts_strict_extension_checks++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

/** Require one call to throw the expected exception class. */
$wp_fts_strict_extension_throws = static function (
    callable $callback,
    string $exceptionClass,
    string $message
) use ($wp_fts_strict_extension_check): Throwable {
    try {
        $callback();
    } catch (Throwable $error) {
        $wp_fts_strict_extension_check($error instanceof $exceptionClass, $message);
        return $error;
    }

    throw new RuntimeException($message);
};

$pipeline = new WP_FTS_LanguagePipeline(['enable_stemming' => false]);
$batch = $pipeline->analyze_detailed_batch([[
    'text' => 'Needle',
    'language' => 'en_US',
    'include_surface' => true,
]]);
$wp_fts_strict_extension_check(
    ($batch[0][0]['term'] ?? null) === 'needle'
        && ($batch[0][0]['lang'] ?? null) === 'en-US'
        && ($batch[0][0]['surface'] ?? null) === 'Needle',
    'an exact analysis segment should preserve text, canonicalize its language, and include its surface'
);

foreach ([
    'outer associative array' => [1 => ['text' => 'needle', 'language' => 'en']],
    'scalar segment' => ['needle'],
    'unsupported field' => [['text' => 'needle', 'language' => 'en', 'extra' => true]],
    'missing text' => [['language' => 'en']],
    'non-string text' => [['text' => 1, 'language' => 'en']],
    'missing language' => [['text' => 'needle']],
    'non-string language' => [['text' => 'needle', 'language' => 1]],
    'padded language' => [['text' => 'needle', 'language' => ' en']],
    'malformed language' => [['text' => 'needle', 'language' => 'e!n']],
    'non-boolean include_surface' => [['text' => 'needle', 'language' => 'en', 'include_surface' => 1]],
] as $label => $segments) {
    $wp_fts_strict_extension_throws(
        static fn(): array => $pipeline->analyze_detailed_batch($segments),
        InvalidArgumentException::class,
        "an analysis batch must reject its {$label}"
    );
}
$wp_fts_strict_extension_throws(
    static fn(): array => $pipeline->analyze_detailed_batch([], -1),
    InvalidArgumentException::class,
    'an analysis batch must reject a negative term ceiling'
);
$wp_fts_strict_extension_throws(
    static fn(): array => $pipeline->analyze_detailed_batch(
        [],
        WP_FTS_Analysis_Limits::MAX_DOCUMENT_OCCURRENCES + 1
    ),
    InvalidArgumentException::class,
    'an analysis batch must reject a term ceiling above the occurrence limit'
);

$detector = new WP_FTS_LanguageDetector();
$wp_fts_strict_extension_check(
    $detector->detect_text('Zażółć gęślą jaźń oraz Łódź', ['pl']) === 'pl',
    'a native candidate-language list should constrain detection'
);
foreach ([
    'associative array' => ['pl' => 'pl'],
    'non-string item' => [1],
    'empty item' => [''],
    'padded item' => ['pl '],
    'malformed item' => ['p!l'],
    'oversized item' => [str_repeat('p', WP_FTS_Analyzer_Config_Limits::MAX_LANGUAGE_BYTES + 1)],
    'too many items' => array_fill(0, WP_FTS_Analyzer_Config_Limits::MAX_CONFIGURED_LANGUAGES + 1, 'pl'),
] as $label => $candidates) {
    $wp_fts_strict_extension_throws(
        static fn(): ?string => $detector->detect_text('', $candidates),
        InvalidArgumentException::class,
        "language detection must reject a candidate list with an {$label}"
    );
}

$normalizer = new WP_FTS_Normalizer([
    'token_normalizer' => static fn(string $_token, string $_language): string => 'replacement',
]);
$wp_fts_strict_extension_check(
    $normalizer->normalize_token('Needle', 'en') === 'replacement',
    'a token normalizer should return its exact string replacement'
);
foreach ([
    'integer' => 1,
    'empty string' => '',
    'padded string' => ' replacement',
] as $label => $result) {
    $invalidNormalizer = new WP_FTS_Normalizer([
        'token_normalizer' => static fn(string $_token, string $_language): mixed => $result,
    ]);
    $wp_fts_strict_extension_throws(
        static fn(): string => $invalidNormalizer->normalize_token('needle', 'en'),
        UnexpectedValueException::class,
        "a token normalizer must reject an {$label} result"
    );
}
$normalizerFailure = new DomainException('normalizer failure');
$throwingNormalizer = new WP_FTS_Normalizer([
    'token_normalizer' => static function (string $_token, string $_language) use ($normalizerFailure): never {
        throw $normalizerFailure;
    },
]);
$caughtNormalizerFailure = $wp_fts_strict_extension_throws(
    static fn(): string => $throwingNormalizer->normalize_token('needle', 'en'),
    DomainException::class,
    'a token-normalizer exception must reach the caller'
);
$wp_fts_strict_extension_check(
    $caughtNormalizerFailure === $normalizerFailure,
    'the token-normalizer exception must not be replaced'
);
$wp_fts_strict_extension_throws(
    static fn(): WP_FTS_Normalizer => new WP_FTS_Normalizer([
        'chinese_script_map' => ['z!h' => ['體' => '体']],
    ]),
    InvalidArgumentException::class,
    'a Chinese script map must reject a malformed language key'
);

$stemmer = new WP_FTS_CallbackStemmer(
    static fn(string $_term, string $_language): string => 'replacement'
);
$wp_fts_strict_extension_check(
    $stemmer->stem('needle', 'en') === 'replacement',
    'a stemmer callback should return its exact string replacement'
);
foreach ([
    'integer' => 1,
    'empty string' => '',
    'padded string' => ' replacement',
] as $label => $result) {
    $invalidStemmer = new WP_FTS_CallbackStemmer(
        static fn(string $_term, string $_language): mixed => $result
    );
    $wp_fts_strict_extension_throws(
        static fn(): string => $invalidStemmer->stem('needle', 'en'),
        UnexpectedValueException::class,
        "a stemmer callback must reject an {$label} result"
    );
}
$stemmerFailure = new DomainException('stemmer failure');
$throwingStemmer = new WP_FTS_CallbackStemmer(
    static function (string $_term, string $_language) use ($stemmerFailure): never {
        throw $stemmerFailure;
    }
);
$caughtStemmerFailure = $wp_fts_strict_extension_throws(
    static fn(): string => $throwingStemmer->stem('needle', 'en'),
    DomainException::class,
    'a stemmer-callback exception must reach the caller'
);
$wp_fts_strict_extension_check(
    $caughtStemmerFailure === $stemmerFailure,
    'the stemmer-callback exception must not be replaced'
);

$snowballStemmer = new WP_FTS_SnowballStemmer();
$wp_fts_strict_extension_check(
    $snowballStemmer->stem('abandonaments', 'ca') === 'abandon',
    'the required Wamania Catalan implementation should run directly'
);
$wp_fts_strict_extension_check(
    $snowballStemmer->stem('aalmoezen', 'nl') === 'aalmoez',
    'the required Wamania Dutch Porter implementation should run directly'
);

$missingSignatureStemmer = new class implements WP_FTS_Stemmer {
    public function stem(string $term, string $language): string
    {
        return $term;
    }
};
$wp_fts_strict_extension_throws(
    static fn(): WP_FTS_LanguagePipeline => new WP_FTS_LanguagePipeline([
        'stemmer' => $missingSignatureStemmer,
    ]),
    LogicException::class,
    'an injected stemmer object must provide an explicit index signature'
);

$paddedObjectStemmer = new class implements WP_FTS_Stemmer {
    public function index_signature(): string
    {
        return 'padded-object-stemmer-v1';
    }

    public function stem(string $term, string $language): string
    {
        return ' replacement ';
    }
};
$paddedObjectPipeline = new WP_FTS_LanguagePipeline(['stemmer' => $paddedObjectStemmer]);
$wp_fts_strict_extension_throws(
    static fn(): array => $paddedObjectPipeline->analyze('needles', 'en'),
    UnexpectedValueException::class,
    'an object stemmer must not have padded output repaired'
);

$signatureStemmer = static fn(mixed $signature): WP_FTS_Stemmer => new class($signature) implements WP_FTS_Stemmer {
    public function __construct(private mixed $signature)
    {
    }

    public function index_signature(): mixed
    {
        if ($this->signature instanceof Throwable) {
            throw $this->signature;
        }

        return $this->signature;
    }

    public function stem(string $term, string $language): string
    {
        return $term;
    }
};
foreach ([1, '', ' padded', str_repeat('s', 257)] as $invalidSignature) {
    $wp_fts_strict_extension_throws(
        static fn(): WP_FTS_LanguagePipeline => new WP_FTS_LanguagePipeline([
            'stemmer' => $signatureStemmer($invalidSignature),
        ]),
        UnexpectedValueException::class,
        'an injected object must return an exact bounded native string signature'
    );
}
$signatureFailure = new DomainException('signature failure');
$caughtSignatureFailure = $wp_fts_strict_extension_throws(
    static fn(): WP_FTS_LanguagePipeline => new WP_FTS_LanguagePipeline([
        'stemmer' => $signatureStemmer($signatureFailure),
    ]),
    DomainException::class,
    'an injected object signature failure must reach the caller'
);
$wp_fts_strict_extension_check(
    $caughtSignatureFailure === $signatureFailure,
    'the object signature failure must not be replaced'
);
$firstStatefulPipeline = new WP_FTS_LanguagePipeline([
    'stemmer' => $signatureStemmer('stateful-stemmer:a'),
]);
$secondStatefulPipeline = new WP_FTS_LanguagePipeline([
    'stemmer' => $signatureStemmer('stateful-stemmer:b'),
]);
$wp_fts_strict_extension_check(
    $firstStatefulPipeline->index_signature() !== $secondStatefulPipeline->index_signature(),
    'different injected object state signatures must produce different pipeline fingerprints'
);

/** Analyze one CJK run through a custom tokenizer. */
$wp_fts_analyze_with_tokenizer = static function (callable $tokenizer): array {
    return (new WP_FTS_LanguagePipeline([
        'enable_stemming' => false,
        'cjk_tokenizer' => $tokenizer,
    ]))->analyze_detailed('中文', 'zh');
};

$tokenizerTerms = $wp_fts_analyze_with_tokenizer(
    static fn(string $_run, string $_language, int $_maxTokens): array => ['中文']
);
$wp_fts_strict_extension_check(
    array_column($tokenizerTerms, 'term') === ['中文'],
    'a CJK tokenizer should emit its exact string tokens'
);
$wp_fts_strict_extension_throws(
    static fn(): array => $wp_fts_analyze_with_tokenizer(
        static fn(string $_run, string $_language, int $_maxTokens): array => []
    ),
    UnexpectedValueException::class,
    'a CJK tokenizer must reject an empty result'
);
foreach ([
    'non-iterable result' => static fn(string $_run, string $_language, int $_maxTokens): bool => false,
    'non-string item' => static fn(string $_run, string $_language, int $_maxTokens): array => [1],
    'empty string item' => static fn(string $_run, string $_language, int $_maxTokens): array => [''],
    'padded string item' => static fn(string $_run, string $_language, int $_maxTokens): array => [' 中文'],
] as $label => $tokenizer) {
    $wp_fts_strict_extension_throws(
        static fn(): array => $wp_fts_analyze_with_tokenizer($tokenizer),
        UnexpectedValueException::class,
        "a CJK tokenizer must reject a {$label}"
    );
}
$tokenizerFailureMessage = 'tokenizer failure';
$caughtTokenizerFailure = $wp_fts_strict_extension_throws(
    static fn(): array => $wp_fts_analyze_with_tokenizer(
        static function (string $_run, string $_language, int $_maxTokens) use ($tokenizerFailureMessage): never {
            throw new DomainException($tokenizerFailureMessage);
        }
    ),
    DomainException::class,
    'a CJK-tokenizer exception must reach the caller'
);
$wp_fts_strict_extension_check(
    $caughtTokenizerFailure->getMessage() === $tokenizerFailureMessage,
    'the CJK-tokenizer exception message must not be replaced'
);

foreach ([null, 'yes', 1] as $invalidJiebaOption) {
    $wp_fts_strict_extension_throws(
        static fn(): ?WP_FTS_ChineseJiebaSegmenter => WP_FTS_ChineseJiebaSegmenter::from_pack_option(
            $invalidJiebaOption,
            'zh'
        ),
        InvalidArgumentException::class,
        'the Jieba pack switch must accept only native booleans'
    );
}
$wp_fts_strict_extension_throws(
    static fn(): ?WP_FTS_ChineseJiebaSegmenter => WP_FTS_ChineseJiebaSegmenter::from_pack_option(
        false,
        ' zh'
    ),
    InvalidArgumentException::class,
    'the disabled Jieba option must still reject a malformed language'
);
$wp_fts_strict_extension_throws(
    static fn(): ?WP_FTS_ChineseJiebaSegmenter => WP_FTS_ChineseJiebaSegmenter::from_pack_option(
        true,
        'en'
    ),
    RuntimeException::class,
    'explicit Jieba enablement must reject a non-Chinese partition'
);
$wp_fts_strict_extension_throws(
    static fn(): WP_FTS_LanguagePipeline => new WP_FTS_LanguagePipeline([
        'segmenter_packs_by_lang' => ['und' => true],
    ]),
    RuntimeException::class,
    'pipeline segmenter routing must not ignore explicit enablement in an unsupported partition'
);
$strictJieba = WP_FTS_ChineseJiebaSegmenter::from_pack_option(true, 'zh');
$wp_fts_strict_extension_check(
    $strictJieba instanceof WP_FTS_ChineseJiebaSegmenter,
    'explicit Jieba enablement should construct the pinned segmenter'
);
$wp_fts_strict_extension_throws(
    static fn(): array => $strictJieba('中文', 'z!h'),
    InvalidArgumentException::class,
    'a direct Jieba call must reject a malformed language'
);

return $wp_fts_strict_extension_checks;
