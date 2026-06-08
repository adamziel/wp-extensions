<?php
declare(strict_types=1);

return [
    'id' => 'de',
    'label' => 'German',
    'order' => 30,
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
    ],
];
