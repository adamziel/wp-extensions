<?php
declare(strict_types=1);

return [
    'id' => 'en',
    'label' => 'English',
    'order' => 10,
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
    'resources' => [
        'stopwords' => 'stopwords.txt',
        'lexemes' => 'lexemes.tsv',
        'synonyms' => 'synonyms.tsv',
        'synonym_phrases' => 'synonym_phrases.tsv',
        'term_rules' => 'term_rules.tsv',
        'protected_terms' => 'protected_terms.txt',
    ],
];
