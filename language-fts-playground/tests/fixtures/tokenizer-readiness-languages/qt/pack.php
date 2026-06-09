<?php
declare(strict_types=1);

return [
    'language_id' => 'qt',
    'pack_version' => '2026-06-09-readiness',
    'pack_date' => '2026-06-09',
    'source_name' => 'Language FTS Playground synthetic tokenizer readiness fixture',
    'source_url' => 'https://github.com/adamziel/wp-extensions/tree/main/language-fts-playground/tests/fixtures/tokenizer-readiness-languages/qt',
    'license_name' => 'GPL-2.0-or-later',
    'attribution_text' => 'Invented synthetic readiness codepoint sequences; not real language data.',
    'provenance' => 'language-fts-playground-synthetic-tokenizer-readiness',
    'files' => [
        'profile.php',
        'stopwords.txt',
        'lexemes.tsv',
        'synonyms.tsv',
        'tokenizer_dictionary.tsv',
    ],
    'data_kind' => 'synthetic_readiness_fixture',
];
