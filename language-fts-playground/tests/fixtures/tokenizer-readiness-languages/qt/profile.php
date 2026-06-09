<?php
declare(strict_types=1);

return [
    'id' => 'qt',
    'label' => 'Synthetic Thai-Style Readiness',
    'order' => 920,
    'tokenizer' => [
        'id' => 'synthetic_dictionary_v1',
        'type' => 'synthetic_dictionary_segmenter',
        'version' => '2026-06-09-readiness',
        'resources' => [
            'dictionary' => 'tokenizer_dictionary.tsv',
        ],
        'capabilities' => [
            'emits_offsets' => true,
            'emits_positions' => true,
            'supports_fuzzy' => false,
            'supports_overlaps' => false,
        ],
    ],
    'language_signals' => [
        '/[\x{0E01}\x{0E02}\x{0E04}\x{0E07}]/u',
    ],
    'resources' => [
        'stopwords' => 'stopwords.txt',
        'lexemes' => 'lexemes.tsv',
        'synonyms' => 'synonyms.tsv',
    ],
];
