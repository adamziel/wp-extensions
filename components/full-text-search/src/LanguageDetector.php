<?php
declare(strict_types=1);

/**
 * Deterministic lightweight language detector for FTS routing.
 *
 * This is not statistical language detection. It only fills gaps when callers
 * provide no explicit language, using script ranges, distinctive Latin letters,
 * and compact stopword/signal lists. Explicit language options, HTML lang
 * attributes, and multilingual plugin metadata still win in the analyzer.
 */
final class WP_FTS_LanguageDetector
{
    private const MINIMUM_SCORE = 3;
    private const MINIMUM_LEAD = 1;

    private WP_FTS_Normalizer $normalizer;
    /** @var array<string,array<string,bool>> */
    private array $signalTerms;

    /** Build the fixed detector used by every analyzer instance. */
    public function __construct()
    {
        if (func_num_args() !== 0) {
            throw new InvalidArgumentException('Language detector does not accept constructor options.');
        }
        $this->normalizer = new WP_FTS_Normalizer(['fold_diacritics' => true]);
        $this->signalTerms = $this->normalize_signal_terms($this->default_signal_terms());
    }

    /**
     * Detect a language for a text sample, or return null when signal is weak.
     *
     * @param string[] $candidateLanguages Optional native list of unpadded
     *        canonical or locale-style language tags. Empty means all bundled
     *        detector signal is eligible.
     * @return string|null Canonical primary language such as `pl`, or null when
     *         the best score is below threshold or tied.
     */
    public function detect_text(string $text, array $candidateLanguages = []): ?string
    {
        $allowed = $this->candidate_lookup($candidateLanguages);
        $text = trim($this->normalizer->normalize_unicode($text));
        if ($text === '') {
            return null;
        }

        $scores = [];
        $this->score_script_signal($text, $scores, $allowed);
        $this->score_distinctive_latin_signal($text, $scores, $allowed);

        foreach ($this->tokens($text) as $token) {
            foreach ($this->signalTerms as $lang => $terms) {
                if ($allowed !== [] && !isset($allowed[$lang])) {
                    continue;
                }

                $normalized = $this->normalizer->normalize_token($token, $lang);
                if (isset($terms[$normalized])) {
                    $scores[$lang] = ($scores[$lang] ?? 0) + 2;
                }
            }
        }

        if ($scores === []) {
            return null;
        }

        arsort($scores, SORT_NUMERIC);
        $ranked = array_values($scores);
        $langs = array_keys($scores);
        $bestScore = (int) $ranked[0];
        $secondScore = (int) ($ranked[1] ?? 0);
        if ($bestScore < self::MINIMUM_SCORE || ($bestScore - $secondScore) < self::MINIMUM_LEAD) {
            return null;
        }

        return $langs[0];
    }

    /**
     * Return the detector behavior signature used by analyzer stale checks.
     */
    public function index_signature(): string
    {
        return 'wp-fts-language-detector-v7:' . sha1($this->stableJson([
            'contract' => 'wp-fts-language-detector',
            'version' => 7,
            'unicode_normalizer' => $this->normalizer->index_signature(),
            'score_floor' => self::MINIMUM_SCORE,
            'lead_floor' => self::MINIMUM_LEAD,
            'signal_terms' => $this->sortedStringSetMap($this->signalTerms),
        ]));
    }

    /**
     * @return array<string,array<string,bool>>
     */
    private function normalize_signal_terms(array $termsByLanguage): array
    {
        $normalized = [];
        foreach ($termsByLanguage as $language => $terms) {
            if (!is_array($terms)) {
                continue;
            }

            $lang = $this->normalizer->base_language((string) $language);
            if ($lang === 'und') {
                continue;
            }

            foreach ($terms as $term) {
                if (!is_scalar($term)) {
                    continue;
                }

                $term = trim((string) $term);
                if ($term === '') {
                    continue;
                }

                $normalized[$lang][$this->normalizer->normalize_token($term, $lang)] = true;
            }
        }

        return $normalized;
    }

    /**
     * @return array<string,string[]>
     */
    private function default_signal_terms(): array
    {
        return [
            'ar' => ['هذا', 'هذه', 'التي', 'الذي', 'على', 'مع', 'بحث', 'للبحث', 'عربي'],
            'bn' => ['এই', 'এবং', 'জন্য', 'বাংলা', 'অনুসন্ধান', 'সূচি'],
            'ca' => ['el', 'la', 'els', 'les', 'amb', 'per', 'cerca'],
            'de' => ['der', 'die', 'das', 'und', 'ist', 'mit', 'fuer', 'suche'],
            'en' => ['the', 'and', 'with', 'from', 'this', 'that', 'search', 'index'],
            'es' => ['el', 'los', 'las', 'para', 'con', 'buscar', 'busqueda', 'datos', 'espanol'],
            'fa' => ['فارسی', 'جستجو', 'فهرست', 'داده', 'زبان', 'گزارش'],
            'fr' => ['le', 'les', 'des', 'est', 'avec', 'pour', 'recherche', 'francais', 'donnees'],
            'hi' => ['यह', 'और', 'के', 'लिए', 'हिंदी', 'खोज'],
            'id' => ['yang', 'dan', 'dengan', 'untuk', 'pencarian', 'bahasa', 'indonesia'],
            'it' => ['il', 'la', 'gli', 'con', 'per', 'ricerca', 'dati', 'italiano'],
            'ja' => ['検索', '索引', '日本語', 'できます'],
            'ko' => ['검색', '색인', '한국어', '문서'],
            'nl' => ['de', 'het', 'een', 'van', 'voor', 'zoeken'],
            'pl' => ['oraz', 'jest', 'dla', 'nie', 'sie', 'szukaj', 'wyszukaj'],
            'pt' => ['a', 'que', 'com', 'para', 'pesquisa', 'portugues', 'dados'],
            'ru' => ['и', 'это', 'для', 'поиск', 'индекс', 'русский', 'данные'],
            'te' => ['తెలుగు', 'శోధన', 'సూచిక', 'భాష', 'పత్రం'],
            'tr' => ['ve', 'ile', 'icin', 'arama', 'dizin', 'turkce', 'veri'],
            'uk' => ['і', 'для', 'пошук', 'індекс', 'українська', 'мова', 'дані'],
            'ur' => ['یہ', 'اور', 'ہے', 'میں', 'اردو', 'تلاش', 'فہرست'],
        ];
    }

    /**
     * @param string[] $candidateLanguages
     * @return array<string,bool>
     */
    private function candidate_lookup(array $candidateLanguages): array
    {
        if (!array_is_list($candidateLanguages)) {
            throw new InvalidArgumentException('Candidate languages must be a list.');
        }
        if (count($candidateLanguages) > WP_FTS_Analyzer_Config_Limits::MAX_CONFIGURED_LANGUAGES) {
            throw new InvalidArgumentException(
                'Candidate languages exceed the '
                . WP_FTS_Analyzer_Config_Limits::MAX_CONFIGURED_LANGUAGES
                . '-language limit.'
            );
        }

        $lookup = [];
        foreach ($candidateLanguages as $language) {
            if (
                !is_string($language)
                || $language === ''
                || trim($language) !== $language
                || strlen($language) > WP_FTS_Analyzer_Config_Limits::MAX_LANGUAGE_BYTES
            ) {
                throw new InvalidArgumentException(
                    'Candidate languages must be unpadded non-empty strings of at most 64 bytes.'
                );
            }

            $canonical = WP_FTS_TermNamespace::parse_language_tag($language);
            $separator = strpos($canonical, '-');
            $lang = $separator === false ? $canonical : substr($canonical, 0, $separator);
            $lookup[$lang] = true;
        }

        return $lookup;
    }

    /**
     * @param array<string,int> $scores
     * @param array<string,bool> $allowed
     */
    private function score_script_signal(string $text, array &$scores, array $allowed): void
    {
        $scriptSignal = [
            'zh' => ['pattern' => '/\p{Han}/u', 'score' => 4],
            'ja' => ['pattern' => '/[\p{Hiragana}\p{Katakana}]/u', 'score' => 6],
            'ko' => ['pattern' => '/\p{Hangul}/u', 'score' => 4],
            'uk' => ['pattern' => '/[ЄІЇҐєіїґ]/u', 'score' => 6],
            'ru' => ['pattern' => '/\p{Cyrillic}/u', 'score' => 4],
            'hi' => ['pattern' => '/[\x{0900}-\x{097F}]/u', 'score' => 4],
            'bn' => ['pattern' => '/[\x{0980}-\x{09FF}]/u', 'score' => 4],
            'te' => ['pattern' => '/[\x{0C00}-\x{0C7F}]/u', 'score' => 4],
            'fa' => ['pattern' => '/[\x{067E}\x{0686}\x{0698}\x{06AF}\x{06A9}\x{06CC}]/u', 'score' => 5],
            'ur' => ['pattern' => '/[\x{0679}\x{0688}\x{0691}\x{06BA}\x{06BE}\x{06C1}\x{06D2}\x{06D3}]/u', 'score' => 6],
            // Arabic script is shared by unsupported Perso-Arabic languages; require lexical signal too.
            'ar' => ['pattern' => '/[\x{0600}-\x{06FF}\x{0750}-\x{077F}\x{08A0}-\x{08FF}]/u', 'score' => 2],
        ];

        foreach ($scriptSignal as $lang => $signal) {
            if ($allowed !== [] && !isset($allowed[$lang])) {
                continue;
            }

            if (@preg_match($signal['pattern'], $text) === 1) {
                $scores[$lang] = ($scores[$lang] ?? 0) + $signal['score'];
            }
        }
    }

    /**
     * @param array<string,int> $scores
     * @param array<string,bool> $allowed
     */
    private function score_distinctive_latin_signal(string $text, array &$scores, array $allowed): void
    {
        $patterns = [
            'de' => ['pattern' => '/[ÄÖÜäöüß]/u', 'score' => 3],
            'es' => ['pattern' => '/[¿¡]/u', 'score' => 3],
            'es_accent' => ['lang' => 'es', 'pattern' => '/[Ññ]/u', 'score' => 2],
            'fr' => ['pattern' => '/[ÀÂÆÇÈÉÊËÎÏÔŒÙÛŸàâæçèéêëîïôœùûÿ]/u', 'score' => 2],
            'pl' => ['pattern' => '/[ĄĆĘŁŃÓŚŹŻąćęłńóśźż]/u', 'score' => 3],
            // Productive -uj- verbs and -alnia/-elnia/-ajnia nouns remain
            // distinctive after a user omits Polish diacritics. Dictionary
            // membership alone is not language signal: large morphology
            // packs contain many short words shared with other languages.
            'pl_ascii_inflection' => [
                'lang' => 'pl',
                'pattern' => '/(?:^|[^\p{L}\p{M}])(?:[\p{L}\p{M}]{3,}uj(?:e|esz|emy|ecie|a)|[\p{L}\p{M}]{2,}(?:aj|al|el)nia)(?:$|[^\p{L}\p{M}])/u',
                'score' => 3,
            ],
            'pt' => ['pattern' => '/[ÃÕãõ]/u', 'score' => 2],
            'tr' => ['pattern' => '/[ĞİŞğıış]/u', 'score' => 3],
        ];

        foreach ($patterns as $key => $signal) {
            $lang = $signal['lang'] ?? $key;
            if ($allowed !== [] && !isset($allowed[$lang])) {
                continue;
            }

            if (@preg_match($signal['pattern'], $text) === 1) {
                $scores[$lang] = ($scores[$lang] ?? 0) + $signal['score'];
            }
        }
    }

    /**
     * @return string[]
     */
    private function tokens(string $text): array
    {
        $matches = [];
        if (preg_match_all('/[\p{L}\p{M}\p{N}_]+/u', $text, $matches) === false) {
            throw new RuntimeException('Language detection could not tokenize normalized Unicode text.');
        }

        return array_values(array_filter(
            $matches[0] ?? [],
            static fn(string $token): bool => $token !== ''
        ));
    }

    /**
     * @param array<string,array<string,bool>> $sets
     * @return array<string,string[]>
     */
    private function sortedStringSetMap(array $sets): array
    {
        ksort($sets, SORT_STRING);
        $result = [];
        foreach ($sets as $key => $set) {
            $items = array_keys($set);
            sort($items, SORT_STRING);
            $result[(string) $key] = $items;
        }

        return $result;
    }

    /**
     * Encode a sanitized signature payload.
     */
    private function stableJson(mixed $payload): string
    {
        return json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
}
