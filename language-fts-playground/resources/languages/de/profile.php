<?php
declare(strict_types=1);

return [
    'id' => 'de',
    'label' => 'German',
    'order' => 30,
    'tokenizer' => [
        'id' => 'unicode_words_v1',
        'type' => 'unicode_words',
        'resources' => [],
        'capabilities' => [
            'emits_offsets' => true,
            'emits_positions' => true,
            'supports_fuzzy' => true,
            'supports_overlaps' => false,
        ],
    ],
    'normalization' => [
        'fold' => [
            'ä' => 'ae',
            'Ä' => 'ae',
            'ö' => 'oe',
            'Ö' => 'oe',
            'ü' => 'ue',
            'Ü' => 'ue',
            'ß' => 'ss',
        ],
    ],
    'language_signals' => [
        '/[äöüß]/iu',
    ],
    'resources' => [
        'stopwords' => 'stopwords.txt',
        'lexemes' => 'lexemes.tsv',
        'synonyms' => 'synonyms.tsv',
        'term_rules' => 'term_rules.tsv',
    ],
];
