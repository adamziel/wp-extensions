<?php
declare(strict_types=1);

/**
 * Compact fixture slice for the opt-in Polish verified stemmer mode.
 *
 * This is not a dictionary dump and it is not a lemmatizer pack. The rows are a
 * reviewable, source-shaped stemming slice: raw Polish forms are included for
 * fixture/provenance checks, while the runtime map stores the folded terms
 * produced by WP_FTS_Normalizer before stemming.
 */
final class WP_FTS_PolishVerifiedStemmerData
{
    public const VERSION = 'task-568-polish-verified-stemmer-port-v1';

    private const REFERENCE_GROUPS = [
        [
            'id' => 'noun-masculine-samochod',
            'stem' => 'samochod',
            'note' => 'Hard masculine noun paradigm with ó/o accent folding.',
            'forms' => [
                ['source' => 'samochód', 'term' => 'samochod'],
                ['source' => 'samochodu', 'term' => 'samochodu'],
                ['source' => 'samochodowi', 'term' => 'samochodowi'],
                ['source' => 'samochodem', 'term' => 'samochodem'],
                ['source' => 'samochodzie', 'term' => 'samochodzie'],
                ['source' => 'samochody', 'term' => 'samochody'],
                ['source' => 'samochodów', 'term' => 'samochodow'],
                ['source' => 'samochodami', 'term' => 'samochodami'],
                ['source' => 'samochodach', 'term' => 'samochodach'],
            ],
        ],
        [
            'id' => 'noun-feminine-ksiazka',
            'stem' => 'ksiazk',
            'note' => 'Feminine k/g alternation boundary represented after folding.',
            'forms' => [
                ['source' => 'książka', 'term' => 'ksiazka'],
                ['source' => 'książki', 'term' => 'ksiazki'],
                ['source' => 'książkę', 'term' => 'ksiazke'],
                ['source' => 'książką', 'term' => 'ksiazka'],
                ['source' => 'książce', 'term' => 'ksiazce'],
                ['source' => 'książkom', 'term' => 'ksiazkom'],
                ['source' => 'książkami', 'term' => 'ksiazkami'],
                ['source' => 'książkach', 'term' => 'ksiazkach'],
            ],
        ],
        [
            'id' => 'noun-feminine-kobieta',
            'stem' => 'kobiet',
            'note' => 'Feminine noun forms where suffix-only stemming damages kobiecie.',
            'forms' => [
                ['source' => 'kobieta', 'term' => 'kobieta'],
                ['source' => 'kobiety', 'term' => 'kobiety'],
                ['source' => 'kobietę', 'term' => 'kobiete'],
                ['source' => 'kobietą', 'term' => 'kobieta'],
                ['source' => 'kobiecie', 'term' => 'kobiecie'],
                ['source' => 'kobietom', 'term' => 'kobietom'],
                ['source' => 'kobietami', 'term' => 'kobietami'],
                ['source' => 'kobietach', 'term' => 'kobietach'],
            ],
        ],
        [
            'id' => 'noun-neuter-miasto',
            'stem' => 'miast',
            'note' => 'Neuter noun with mieście/miast alternation.',
            'forms' => [
                ['source' => 'miasto', 'term' => 'miasto'],
                ['source' => 'miasta', 'term' => 'miasta'],
                ['source' => 'miastu', 'term' => 'miastu'],
                ['source' => 'miastem', 'term' => 'miastem'],
                ['source' => 'mieście', 'term' => 'miescie'],
                ['source' => 'miastami', 'term' => 'miastami'],
                ['source' => 'miastach', 'term' => 'miastach'],
            ],
        ],
        [
            'id' => 'noun-feminine-rzeka',
            'stem' => 'rzek',
            'note' => 'Feminine noun case endings beyond the conservative suffix list.',
            'forms' => [
                ['source' => 'rzeka', 'term' => 'rzeka'],
                ['source' => 'rzeki', 'term' => 'rzeki'],
                ['source' => 'rzekę', 'term' => 'rzeke'],
                ['source' => 'rzeką', 'term' => 'rzeka'],
                ['source' => 'rzece', 'term' => 'rzece'],
                ['source' => 'rzekom', 'term' => 'rzekom'],
                ['source' => 'rzekami', 'term' => 'rzekami'],
                ['source' => 'rzekach', 'term' => 'rzekach'],
            ],
        ],
        [
            'id' => 'adjective-dobry',
            'stem' => 'dobr',
            'note' => 'Common adjective endings grouped to one stem.',
            'forms' => [
                ['source' => 'dobry', 'term' => 'dobry'],
                ['source' => 'dobra', 'term' => 'dobra'],
                ['source' => 'dobre', 'term' => 'dobre'],
                ['source' => 'dobrego', 'term' => 'dobrego'],
                ['source' => 'dobremu', 'term' => 'dobremu'],
                ['source' => 'dobrym', 'term' => 'dobrym'],
                ['source' => 'dobrymi', 'term' => 'dobrymi'],
                ['source' => 'dobrych', 'term' => 'dobrych'],
            ],
        ],
        [
            'id' => 'verb-czytac',
            'stem' => 'czyt',
            'note' => 'Small verb-family slice for query/document parity checks.',
            'forms' => [
                ['source' => 'czytać', 'term' => 'czytac'],
                ['source' => 'czytam', 'term' => 'czytam'],
                ['source' => 'czytasz', 'term' => 'czytasz'],
                ['source' => 'czytamy', 'term' => 'czytamy'],
                ['source' => 'czytacie', 'term' => 'czytacie'],
                ['source' => 'czytają', 'term' => 'czytaja'],
                ['source' => 'czytał', 'term' => 'czytal'],
                ['source' => 'czytała', 'term' => 'czytala'],
                ['source' => 'czytanie', 'term' => 'czytanie'],
                ['source' => 'czytania', 'term' => 'czytania'],
            ],
        ],
        [
            'id' => 'verb-szukac',
            'stem' => 'szuk',
            'note' => 'Search-relevant verb-family slice kept stem-only, not lemmatized.',
            'forms' => [
                ['source' => 'szukać', 'term' => 'szukac'],
                ['source' => 'szukam', 'term' => 'szukam'],
                ['source' => 'szukasz', 'term' => 'szukasz'],
                ['source' => 'szukamy', 'term' => 'szukamy'],
                ['source' => 'szukacie', 'term' => 'szukacie'],
                ['source' => 'szukają', 'term' => 'szukaja'],
                ['source' => 'szukał', 'term' => 'szukal'],
                ['source' => 'szukała', 'term' => 'szukala'],
                ['source' => 'szukanie', 'term' => 'szukanie'],
                ['source' => 'szukania', 'term' => 'szukania'],
            ],
        ],
        [
            'id' => 'verb-wyszukac',
            'stem' => 'wyszuk',
            'note' => 'Sandbox search-family slice for nominal and imperative forms.',
            'forms' => [
                ['source' => 'wyszukać', 'term' => 'wyszukac'],
                ['source' => 'wyszukam', 'term' => 'wyszukam'],
                ['source' => 'wyszukiwanie', 'term' => 'wyszukiwanie'],
                ['source' => 'wyszukiwania', 'term' => 'wyszukiwania'],
                ['source' => 'wyszukaj', 'term' => 'wyszukaj'],
            ],
        ],
    ];

    private const PROTECTED_ROWS = [
        [
            'source' => 'danie',
            'term' => 'danie',
            'stem' => 'danie',
            'reason' => 'Ambiguous noun/verbal form; suffix-only stemming would produce dan.',
        ],
        [
            'source' => 'panie',
            'term' => 'panie',
            'stem' => 'panie',
            'reason' => 'Ambiguous plural/vocative form; a fixture slice should not choose pan or pani.',
        ],
        [
            'source' => 'linie',
            'term' => 'linie',
            'stem' => 'linie',
            'reason' => 'Alternation needs more lexical evidence than this stemmer slice carries.',
        ],
        [
            'source' => 'ramie',
            'term' => 'ramie',
            'stem' => 'ramie',
            'reason' => 'Folded spelling covers distinct Polish readings and stays a no-op.',
        ],
        [
            'source' => 'pole',
            'term' => 'pole',
            'stem' => 'pole',
            'reason' => 'Common short lemma outside the verified paradigms remains untouched.',
        ],
    ];

    private const FALLBACK_ROWS = [
        [
            'source' => 'kotami',
            'term' => 'kotami',
            'stem' => 'kot',
            'reason' => 'Unknown to the verified map, but safe under the conservative suffix fallback.',
        ],
        [
            'source' => 'Wrocławiu',
            'term' => 'wroclawiu',
            'stem' => 'wroclaw',
            'reason' => 'Unknown proper-name form keeps the existing conservative fallback behavior.',
        ],
        [
            'source' => 'kolorem',
            'term' => 'kolorem',
            'stem' => 'kolor',
            'reason' => 'Instrumental suffix fallback remains available for unmapped forms.',
        ],
        [
            'source' => 'alfabeta',
            'term' => 'alfabeta',
            'stem' => 'alfabeta',
            'reason' => 'Unknown form without a safe suffix remains unchanged.',
        ],
    ];

    /**
     * Return provenance metadata for docs and standalone fixture validation.
     *
     * @return array<string,string>
     */
    public static function manifest(): array
    {
        return [
            'version' => self::VERSION,
            'scope' => 'Fixture-backed Polish verified stemmer port slice.',
            'source' => 'Task 568 licensing is signed off; source/provenance metadata is preserved here and in docs.',
            'normalization' => 'Runtime keys are WP_FTS_Normalizer folded Polish terms; this class does not fold diacritics itself.',
            'boundary' => 'Not a full Stempel, Morfologik, PoliMorf, or dictionary lemmatizer pack.',
        ];
    }

    /**
     * @return array<int,array{id:string,stem:string,note:string,forms:array<int,array{source:string,term:string}>}>
     */
    public static function reference_groups(): array
    {
        return self::REFERENCE_GROUPS;
    }

    /**
     * @return array<int,array{source:string,term:string,stem:string,reason:string}>
     */
    public static function protected_rows(): array
    {
        return self::PROTECTED_ROWS;
    }

    /**
     * @return array<int,array{source:string,term:string,stem:string,reason:string}>
     */
    public static function fallback_rows(): array
    {
        return self::FALLBACK_ROWS;
    }

    /**
     * Return normalized term to verified stem mappings.
     *
     * @return array<string,string>
     */
    public static function stem_map(): array
    {
        /** @var array<string,string>|null $map */
        static $map = null;
        if ($map !== null) {
            return $map;
        }

        $map = [];
        foreach (self::REFERENCE_GROUPS as $group) {
            $stem = (string) $group['stem'];
            foreach ($group['forms'] as $form) {
                $term = (string) $form['term'];
                if ($term !== '') {
                    $map[$term] = $stem;
                }
            }
        }
        ksort($map, SORT_STRING);

        return $map;
    }

    /**
     * Return normalized terms that must stay unchanged in verified mode.
     *
     * @return array<string,bool>
     */
    public static function protected_term_map(): array
    {
        /** @var array<string,bool>|null $map */
        static $map = null;
        if ($map !== null) {
            return $map;
        }

        $map = [];
        foreach (self::PROTECTED_ROWS as $row) {
            $term = (string) $row['term'];
            if ($term !== '') {
                $map[$term] = true;
            }
        }
        ksort($map, SORT_STRING);

        return $map;
    }
}
