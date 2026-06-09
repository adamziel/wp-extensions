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
        return 'wp-fts-language-detector-v2:' . sha1($this->stableJson([
            'contract' => 'wp-fts-language-detector',
            'version' => 2,
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
            'ca' => ['el', 'la', 'els', 'les', 'amb', 'per', 'cerca'],
            'de' => ['der', 'die', 'das', 'und', 'ist', 'mit', 'fuer', 'suche'],
            'en' => ['the', 'and', 'with', 'from', 'this', 'that', 'search'],
            'es' => ['el', 'los', 'las', 'para', 'con', 'buscar', 'busqueda'],
            'fr' => ['le', 'les', 'des', 'est', 'avec', 'pour', 'recherche'],
            'nl' => ['de', 'het', 'een', 'van', 'voor', 'zoeken'],
            'pl' => ['oraz', 'jest', 'dla', 'nie', 'sie', 'szukaj', 'wyszukaj'],
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
            'ru' => ['pattern' => '/\p{Cyrillic}/u', 'score' => 4],
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
