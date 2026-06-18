<?php
declare(strict_types=1);

/**
 * Deterministic lightweight language detector for FTS routing.
 *
 * This is not statistical language detection. It only fills gaps when callers
 * provide no explicit language, using script ranges, distinctive Latin letters,
 * and compact stopword/evidence lists. Explicit language options, HTML lang
 * attributes, and multilingual plugin metadata still win in the analyzer.
 */
final class WP_FTS_LanguageDetector
{
    private WP_FTS_Normalizer $normalizer;
    private int $minimumScore;
    private int $minimumLead;
    /** @var array<string,array<string,bool>> */
    private array $evidenceTerms;

    /**
     * @param array{
     *   minimum_score?:int,
     *   minimum_lead?:int,
     *   evidence_terms?:array<string,string[]>
     * } $options
     */
    public function __construct(array $options = [])
    {
        $this->normalizer = new WP_FTS_Normalizer(['fold_diacritics' => true]);
        $this->minimumScore = max(1, (int) ($options['minimum_score'] ?? 3));
        $this->minimumLead = max(0, (int) ($options['minimum_lead'] ?? 1));
        $this->evidenceTerms = $this->normalize_evidence_terms($options['evidence_terms'] ?? $this->default_evidence_terms());
    }

    /**
     * Detect a language for a text sample, or return null when evidence is weak.
     *
     * @param string[] $candidateLanguages Optional allowlist of canonical or
     *        locale-style language tags. Empty means all bundled detector
     *        evidence is eligible.
     * @return string|null Canonical primary language such as `pl`, or null when
     *         the best score is below threshold or tied.
     */
    public function detect_text(string $text, array $candidateLanguages = []): ?string
    {
        $text = trim(WP_FTS_Utf8::repair($text));
        if ($text === '') {
            return null;
        }

        $allowed = $this->candidate_lookup($candidateLanguages);
        $scores = [];
        $this->score_script_evidence($text, $scores, $allowed);
        $this->score_distinctive_latin_evidence($text, $scores, $allowed);

        foreach ($this->tokens($text) as $token) {
            foreach ($this->evidenceTerms as $lang => $terms) {
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
        if ($bestScore < $this->minimumScore || ($bestScore - $secondScore) < $this->minimumLead) {
            return null;
        }

        return $langs[0];
    }

    /**
     * Return the detector behavior signature used by analyzer stale checks.
     */
    public function index_signature(): string
    {
        return 'wp-fts-language-detector-v5:' . sha1($this->stableJson([
            'contract' => 'wp-fts-language-detector',
            'version' => 5,
            'minimum_score' => $this->minimumScore,
            'minimum_lead' => $this->minimumLead,
            'evidence_terms' => $this->sortedStringSetMap($this->evidenceTerms),
        ]));
    }

    /**
     * @return array<string,array<string,bool>>
     */
    private function normalize_evidence_terms(array $termsByLanguage): array
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
    private function default_evidence_terms(): array
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
        $lookup = [];
        foreach ($candidateLanguages as $language) {
            if (!is_scalar($language)) {
                continue;
            }

            $lang = $this->normalizer->base_language((string) $language);
            if ($lang !== 'und') {
                $lookup[$lang] = true;
            }
        }

        return $lookup;
    }

    /**
     * @param array<string,int> $scores
     * @param array<string,bool> $allowed
     */
    private function score_script_evidence(string $text, array &$scores, array $allowed): void
    {
        $scriptEvidence = [
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
            // Arabic script is shared by unsupported Perso-Arabic languages; require lexical evidence too.
            'ar' => ['pattern' => '/[\x{0600}-\x{06FF}\x{0750}-\x{077F}\x{08A0}-\x{08FF}]/u', 'score' => 2],
        ];

        foreach ($scriptEvidence as $lang => $evidence) {
            if ($allowed !== [] && !isset($allowed[$lang])) {
                continue;
            }

            if (@preg_match($evidence['pattern'], $text) === 1) {
                $scores[$lang] = ($scores[$lang] ?? 0) + $evidence['score'];
            }
        }
    }

    /**
     * @param array<string,int> $scores
     * @param array<string,bool> $allowed
     */
    private function score_distinctive_latin_evidence(string $text, array &$scores, array $allowed): void
    {
        $patterns = [
            'de' => ['pattern' => '/[ÄÖÜäöüß]/u', 'score' => 3],
            'es' => ['pattern' => '/[¿¡]/u', 'score' => 3],
            'es_accent' => ['lang' => 'es', 'pattern' => '/[Ññ]/u', 'score' => 2],
            'fr' => ['pattern' => '/[ÀÂÆÇÈÉÊËÎÏÔŒÙÛŸàâæçèéêëîïôœùûÿ]/u', 'score' => 2],
            'pl' => ['pattern' => '/[ĄĆĘŁŃÓŚŹŻąćęłńóśźż]/u', 'score' => 3],
            'pt' => ['pattern' => '/[ÃÕãõ]/u', 'score' => 2],
            'tr' => ['pattern' => '/[ĞİŞğıış]/u', 'score' => 3],
        ];

        foreach ($patterns as $key => $evidence) {
            $lang = $evidence['lang'] ?? $key;
            if ($allowed !== [] && !isset($allowed[$lang])) {
                continue;
            }

            if (@preg_match($evidence['pattern'], $text) === 1) {
                $scores[$lang] = ($scores[$lang] ?? 0) + $evidence['score'];
            }
        }
    }

    /**
     * @return string[]
     */
    private function tokens(string $text): array
    {
        $matches = [];
        if (@preg_match_all('/[\p{L}\p{M}\p{N}_]+/u', $text, $matches) !== false) {
            return array_values(array_filter(
                $matches[0] ?? [],
                static fn(string $token): bool => $token !== ''
            ));
        }

        $ascii = preg_replace('/[^\x20-\x7E]+/', ' ', $text) ?? '';
        preg_match_all('/[A-Za-z0-9_]+/', $ascii, $matches);

        return $matches[0] ?? [];
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
        try {
            return json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return serialize($payload);
        }
    }
}
