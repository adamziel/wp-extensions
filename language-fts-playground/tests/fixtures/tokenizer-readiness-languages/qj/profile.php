<?php
declare(strict_types=1);

return [
    'id' => 'qj',
    'label' => 'Synthetic CJK-Style Readiness',
    'order' => 910,
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
        '/[\x{4E00}-\x{4E03}\x{56DB}]/u',
    ],
    'resources' => [
        'stopwords' => 'stopwords.txt',
        'lexemes' => 'lexemes.tsv',
        'synonyms' => 'synonyms.tsv',
    ],
];
