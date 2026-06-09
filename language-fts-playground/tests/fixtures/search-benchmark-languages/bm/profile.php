<?php
declare(strict_types=1);

return [
    'id' => 'bm',
    'label' => 'Benchmark Fixture',
    'order' => 10,
    'resources' => [
        'stopwords' => 'stopwords.txt',
        'lexemes' => 'lexemes.tsv',
        'synonyms' => 'synonyms.tsv',
        'synonym_phrases' => 'synonym_phrases.tsv',
    ],
];
