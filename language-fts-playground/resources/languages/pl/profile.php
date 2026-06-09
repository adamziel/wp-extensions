<?php
declare(strict_types=1);

return [
    'id' => 'pl',
    'label' => 'Polish',
    'order' => 20,
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
            'ą' => 'a',
            'Ą' => 'a',
            'ć' => 'c',
            'Ć' => 'c',
            'ę' => 'e',
            'Ę' => 'e',
            'ł' => 'l',
            'Ł' => 'l',
            'ń' => 'n',
            'Ń' => 'n',
            'ó' => 'o',
            'Ó' => 'o',
            'ś' => 's',
            'Ś' => 's',
            'ź' => 'z',
            'Ź' => 'z',
            'ż' => 'z',
            'Ż' => 'z',
        ],
    ],
    'language_signals' => [
        '/[ąćęłńóśźż]/iu',
    ],
    'resources' => [
        'stopwords' => 'stopwords.txt',
        'lexemes' => 'lexemes.tsv',
        'synonyms' => 'synonyms.tsv',
        'synsets' => 'synsets.tsv',
        'term_rules' => 'term_rules.tsv',
    ],
];
