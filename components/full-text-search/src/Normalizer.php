<?php
declare(strict_types=1);

/**
 * Canonicalizes language tags and normalizes token text before stemming.
 *
 * This class keeps token normalization deterministic across PHP installations.
 * It uses mbstring when available, but ships explicit lowercase and folding
 * maps so tests and indexing still work under `php -n`.
 */
final class WP_FTS_Normalizer
{
    private const CONSTRUCTOR_OPTION_KEYS = [
        'fold_diacritics',
        'token_normalizer',
        'chinese_script_map',
    ];

    private bool $foldDiacritics;
    /** @var callable|null */
    private $tokenNormalizer;
    /** @var array<string,array<string,string>> */
    private array $chineseScriptMaps;

    /**
     * Configure token normalization.
     *
     * @param array{
     *   fold_diacritics?:bool,
     *   token_normalizer?:callable|null,
     *   chinese_script_map?:array<string,array<string,string>>
     * } $options Set `fold_diacritics` false when accents and
     *        language-specific letters must remain distinct in the index.
     *        `token_normalizer` may return one unpadded non-empty string after
     *        the built-in dialect maps. `chinese_script_map` is keyed first by
     *        language and then by source character.
     */
    public function __construct(array $options = [])
    {
        if (!class_exists(\Symfony\Polyfill\Intl\Normalizer\Normalizer::class)) {
            throw new LogicException(
                'WP_FTS_Normalizer requires symfony/polyfill-intl-normalizer. Install Composer dependencies before constructing the analyzer.'
            );
        }

        WP_FTS_Analyzer_Config_Limits::assert_analyzer_options($options, 'Normalizer options');
        foreach (array_keys($options) as $key) {
            if (!is_string($key) || !in_array($key, self::CONSTRUCTOR_OPTION_KEYS, true)) {
                throw new InvalidArgumentException('Normalizer options contain an unsupported field.');
            }
        }
        if (array_key_exists('fold_diacritics', $options) && !is_bool($options['fold_diacritics'])) {
            throw new InvalidArgumentException('Normalizer option fold_diacritics must be a boolean.');
        }
        if (
            array_key_exists('token_normalizer', $options)
            && $options['token_normalizer'] !== null
            && !is_callable($options['token_normalizer'])
        ) {
            throw new InvalidArgumentException('Normalizer option token_normalizer must be callable or null.');
        }
        if (array_key_exists('chinese_script_map', $options) && !is_array($options['chinese_script_map'])) {
            throw new InvalidArgumentException('Normalizer option chinese_script_map must be a language map.');
        }

        $this->foldDiacritics = $options['fold_diacritics'] ?? true;
        $normalizer = $options['token_normalizer'] ?? null;
        $this->tokenNormalizer = $normalizer;
        $this->chineseScriptMaps = $this->normalize_chinese_script_maps($options['chinese_script_map'] ?? []);
    }

    /**
     * Convert locale-style input into a stable language tag.
     *
     * The normalizer accepts underscores, lower/upper case mixtures, and common
     * Chinese script/region hints. Empty input returns `und`.
     *
     * @param string $language User, locale, or HTML language value.
     * @return string Canonical language tag such as `en`, `en-US`, `zh-Hans`,
     *         or `und`.
     */
    public function canonicalize_language(string $language): string
    {
        return WP_FTS_TermNamespace::canonicalize_lang($language, 'und');
    }

    /**
     * Return the primary language subtag after canonicalization.
     *
     * @param string $language Language tag or locale.
     * @return string Primary language, or `und` for empty input.
     */
    public function base_language(string $language): string
    {
        $language = $this->canonicalize_language($language);
        $separator = strpos($language, '-');

        return $separator === false ? $language : substr($language, 0, $separator);
    }

    /**
     * Normalize one token for the supplied language.
     *
     * The order is intentional: lowercase first, apply dialect maps second, then
     * optionally fold diacritics. This lets dialect rules match their normalized
     * lower-case spellings.
     *
     * @param string $token Raw token text from the tokenizer.
     * @param string $language Language tag used for locale-sensitive casing and
     *        folding.
     * @return string Normalized token text.
     */
    public function normalize_token(string $token, string $language): string
    {
        $language = $this->canonicalize_language($language);
        $token = $this->normalize_unicode($token);
        $token = $this->lowercase($token, $language);
        $token = $this->normalize_dialect($token, $language);
        $token = $this->apply_token_normalizer($token, $language);
        $token = $this->normalize_unicode($token);

        if (!$this->foldDiacritics) {
            return $token;
        }

        return $this->fold_for_language($token, $language);
    }

    /**
     * Apply Unicode NFKC normalization before lexical analysis.
     *
     * Compatibility normalization includes canonical NFC composition while
     * also folding presentation variants such as full-width Latin letters and
     * ligatures. Composer's implementation is preferred even when ext-intl is
     * loaded: web and CLI SAPIs commonly ship different ICU releases, and an
     * index written by one process must produce the same terms in the other.
     */
    public function normalize_unicode(string $text): string
    {
        // Every ASCII byte is valid UTF-8 and unchanged by NFKC. Avoid the
        // table-backed normalizer for this exact fast path.
        if (preg_match('/[\x80-\xFF]/', $text) === 0) {
            return $text;
        }

        $text = WP_FTS_Utf8::repair_word_boundaries($text);
        $normalized = \Symfony\Polyfill\Intl\Normalizer\Normalizer::normalize(
            $text,
            \Symfony\Polyfill\Intl\Normalizer\Normalizer::FORM_KC
        );
        if (!is_string($normalized)) {
            throw new UnexpectedValueException('Unicode normalization must return a string.');
        }

        return $normalized;
    }

    /**
     * Identify the Unicode normalization backend for stale-index checks.
     *
     * Composer makes one NFKC data set authoritative across SAPIs. Encoding
     * its release prevents a dependency change from silently changing query
     * terms without reindexing existing documents.
     */
    public function index_signature(): string
    {
        return 'wp-fts-unicode-normalizer:nfkc-symfony-polyfill-' . $this->polyfill_version_signature();
    }

    /**
     * Resolve the installed polyfill release from Composer metadata.
     */
    private function polyfill_version_signature(): string
    {
        if (!class_exists(Composer\InstalledVersions::class)) {
            throw new LogicException('Unicode normalization requires Composer package metadata.');
        }

        $version = Composer\InstalledVersions::getPrettyVersion('symfony/polyfill-intl-normalizer')
            ?? Composer\InstalledVersions::getReference('symfony/polyfill-intl-normalizer');
        if (!is_string($version) || $version === '') {
            throw new LogicException('Could not resolve the installed Unicode normalizer release.');
        }

        return $version;
    }

    /**
     * Lowercase a token with Turkish special casing handled explicitly.
     *
     * Turkish casing is locale-sensitive: ASCII I lowercases to dotless i. PHP
     * has no per-call locale casing, so the special letters are handled before
     * `mb_strtolower()` or the bundled fallback map.
     */
    private function lowercase(string $token, string $language): string
    {
        if (function_exists('mb_strtolower')) {
            if ($this->base_language($language) === 'tr') {
                $token = strtr($token, [
                    'I' => 'ı',
                    'İ' => 'i',
                ]);
            }

            return mb_strtolower($token, 'UTF-8');
        }

        return $this->lowercase_without_mbstring($token, $language);
    }

    /**
     * Lowercase UTF-8 text without relying on mbstring.
     *
     * ASCII is handled by `strtolower()` and common non-ASCII uppercase letters
     * are mapped explicitly so `php -n` keeps analyzer output stable.
     */
    private function lowercase_without_mbstring(string $token, string $language): string
    {
        if ($this->base_language($language) === 'tr') {
            $token = strtr($token, [
                'I' => 'ı',
                'İ' => 'i',
            ]);
        }

        return strtr(strtolower($token), $this->utf8_uppercase_lowercase_map());
    }

    /**
     * Apply language-specific dialect equivalence maps.
     *
     * English maps selected British spellings to American spellings. Chinese
     * only applies caller-supplied script maps; there is no default broad script
     * conversion.
     */
    private function normalize_dialect(string $token, string $language): string
    {
        $base = $this->base_language($language);
        if ($base === 'en') {
            return $this->normalize_english_dialect($token);
        }

        if ($base === 'zh') {
            return $this->normalize_chinese_dialect($token, $language);
        }

        if ($base === 'ar' || $base === 'fa' || $base === 'ur') {
            return $this->normalize_arabic_script_marks($token);
        }

        return $token;
    }

    /**
     * Normalize a small set of common English spelling variants.
     *
     * This is not a general spellchecker; it only keeps common search pairs such
     * as color/colour in the same term bucket.
     */
    private function normalize_english_dialect(string $token): string
    {
        $map = [
            'colour' => 'color',
            'colours' => 'colors',
            'colouring' => 'coloring',
            'coloured' => 'colored',
            'flavour' => 'flavor',
            'flavours' => 'flavors',
            'honour' => 'honor',
            'honours' => 'honors',
            'behaviour' => 'behavior',
            'behaviours' => 'behaviors',
            'organise' => 'organize',
            'organises' => 'organizes',
            'organised' => 'organized',
            'organising' => 'organizing',
            'normalise' => 'normalize',
            'normalises' => 'normalizes',
            'normalised' => 'normalized',
            'normalising' => 'normalizing',
            'realise' => 'realize',
            'realises' => 'realizes',
            'realised' => 'realized',
            'realising' => 'realizing',
            'recognise' => 'recognize',
            'recognises' => 'recognizes',
            'recognised' => 'recognized',
            'recognising' => 'recognizing',
        ];

        return $map[$token] ?? $token;
    }

    /**
     * Return Chinese script-normalized token text when a conversion table exists.
     *
     * Placeholder hook for a future Traditional/Simplified conversion table. The
     * empty map keeps v1 deterministic without pretending to do full conversion.
     */
    private function normalize_chinese_dialect(string $token, string $language): string
    {
        foreach ([$language, $this->base_language($language)] as $key) {
            if (isset($this->chineseScriptMaps[$key])) {
                return strtr($token, $this->chineseScriptMaps[$key]);
            }
        }

        return $token;
    }

    /**
     * Strip Arabic-script combining marks and tatweel without changing letters.
     *
     * Arabic, Urdu, and Persian share script code ranges. This map therefore
     * removes only marks/kashida and deliberately does not rewrite letters such
     * as Urdu do-chashmi heh or Persian yeh/kaf.
     */
    private function normalize_arabic_script_marks(string $token): string
    {
        return strtr($token, $this->arabic_script_mark_map());
    }

    /**
     * @return array<string,string>
     */
    private function arabic_script_mark_map(): array
    {
        static $map = null;
        if ($map !== null) {
            return $map;
        }

        $map = [
            "\u{0610}" => '',
            "\u{0611}" => '',
            "\u{0612}" => '',
            "\u{0613}" => '',
            "\u{0614}" => '',
            "\u{0615}" => '',
            "\u{0616}" => '',
            "\u{0617}" => '',
            "\u{0618}" => '',
            "\u{0619}" => '',
            "\u{061a}" => '',
            "\u{0640}" => '',
            "\u{064b}" => '',
            "\u{064c}" => '',
            "\u{064d}" => '',
            "\u{064e}" => '',
            "\u{064f}" => '',
            "\u{0650}" => '',
            "\u{0651}" => '',
            "\u{0652}" => '',
            "\u{0653}" => '',
            "\u{0654}" => '',
            "\u{0655}" => '',
            "\u{0656}" => '',
            "\u{0657}" => '',
            "\u{0658}" => '',
            "\u{0659}" => '',
            "\u{065a}" => '',
            "\u{065b}" => '',
            "\u{065c}" => '',
            "\u{065d}" => '',
            "\u{065e}" => '',
            "\u{065f}" => '',
            "\u{0670}" => '',
            "\u{06d6}" => '',
            "\u{06d7}" => '',
            "\u{06d8}" => '',
            "\u{06d9}" => '',
            "\u{06da}" => '',
            "\u{06db}" => '',
            "\u{06dc}" => '',
            "\u{06df}" => '',
            "\u{06e0}" => '',
            "\u{06e1}" => '',
            "\u{06e2}" => '',
            "\u{06e3}" => '',
            "\u{06e4}" => '',
            "\u{06e7}" => '',
            "\u{06e8}" => '',
            "\u{06ea}" => '',
            "\u{06eb}" => '',
            "\u{06ec}" => '',
            "\u{06ed}" => '',
            "\u{08d3}" => '',
            "\u{08d4}" => '',
            "\u{08d5}" => '',
            "\u{08d6}" => '',
            "\u{08d7}" => '',
            "\u{08d8}" => '',
            "\u{08d9}" => '',
            "\u{08da}" => '',
            "\u{08db}" => '',
            "\u{08dc}" => '',
            "\u{08dd}" => '',
            "\u{08de}" => '',
            "\u{08df}" => '',
            "\u{08e0}" => '',
            "\u{08e1}" => '',
            "\u{08e2}" => '',
            "\u{08e3}" => '',
            "\u{08e4}" => '',
            "\u{08e5}" => '',
            "\u{08e6}" => '',
            "\u{08e7}" => '',
            "\u{08e8}" => '',
            "\u{08e9}" => '',
            "\u{08ea}" => '',
            "\u{08eb}" => '',
            "\u{08ec}" => '',
            "\u{08ed}" => '',
            "\u{08ee}" => '',
            "\u{08ef}" => '',
            "\u{08f0}" => '',
            "\u{08f1}" => '',
            "\u{08f2}" => '',
            "\u{08f3}" => '',
            "\u{08f4}" => '',
            "\u{08f5}" => '',
            "\u{08f6}" => '',
            "\u{08f7}" => '',
            "\u{08f8}" => '',
            "\u{08f9}" => '',
            "\u{08fa}" => '',
            "\u{08fb}" => '',
            "\u{08fc}" => '',
            "\u{08fd}" => '',
            "\u{08fe}" => '',
            "\u{08ff}" => '',
        ];

        return $map;
    }

    /**
     * Normalize user-supplied Chinese script maps without implying broad
     * Traditional/Simplified conversion support.
     *
     * @param mixed $maps
     * @return array<string,array<string,string>>
     */
    private function normalize_chinese_script_maps(mixed $maps): array
    {
        if ($maps === []) {
            return [];
        }
        if (!is_array($maps)) {
            throw new InvalidArgumentException('Chinese script maps must be keyed by language.');
        }

        $normalized = [];
        $seenLanguages = [];
        foreach ($maps as $language => $map) {
            $canonicalLanguage = WP_FTS_TermNamespace::parse_language_tag($language);
            if (isset($seenLanguages[$canonicalLanguage])) {
                throw new InvalidArgumentException("Chinese script maps contain duplicate language {$canonicalLanguage}.");
            }
            $seenLanguages[$canonicalLanguage] = true;
            if (!is_array($map)) {
                throw new InvalidArgumentException('Each Chinese script map language must contain a character map.');
            }
            $validatedMap = [];
            foreach ($map as $from => $to) {
                if (!is_string($from) || $from === '' || !is_string($to)) {
                    throw new InvalidArgumentException('Chinese script maps must contain only non-empty string keys and string values.');
                }
                $validatedMap[$from] = $to;
            }
            if ($validatedMap === []) {
                continue;
            }

            $normalized[$canonicalLanguage] = $validatedMap;
        }

        return $normalized;
    }

    /**
     * Apply an optional deterministic token-normalization callback.
     */
    private function apply_token_normalizer(string $token, string $language): string
    {
        if ($this->tokenNormalizer === null) {
            return $token;
        }

        $normalized = ($this->tokenNormalizer)($token, $language);
        if (!is_string($normalized)) {
            throw new UnexpectedValueException('A token normalizer must return a string.');
        }
        // Extension output crosses the same lexical boundary as source text.
        // Reject it before the second Unicode-normalization pass can copy or
        // scan an arbitrarily large callback result.
        WP_FTS_Analysis_Limits::assert_lexical_run_bytes(strlen($normalized));
        if ($normalized === '' || trim($normalized) !== $normalized) {
            throw new UnexpectedValueException('A token normalizer must return an unpadded non-empty string.');
        }

        return $normalized;
    }

    /**
     * Fold diacritics with language-specific exceptions.
     *
     * German keeps umlaut equivalences such as ae/oe/ue, Turkish preserves its
     * casing decisions before folding, and other Latin-script languages use the
     * broad fallback map.
     */
    private function fold_for_language(string $token, string $language): string
    {
        return match ($this->base_language($language)) {
            'pl' => strtr($token, $this->latin_fallback_fold_map()),
            'de' => strtr($token, $this->german_fold_map()),
            'tr' => strtr($token, $this->turkish_fold_map()),
            default => strtr($token, $this->latin_fallback_fold_map()),
        };
    }

    /**
     * Return German fold mappings, including combining marks.
     *
     * @return array<string,string>
     */
    private function german_fold_map(): array
    {
        return [
            "a\u{0308}" => 'ae',
            "o\u{0308}" => 'oe',
            "u\u{0308}" => 'ue',
            'ä' => 'ae',
            'ö' => 'oe',
            'ü' => 'ue',
            'ß' => 'ss',
            "\u{0300}" => '',
            "\u{0301}" => '',
            "\u{0302}" => '',
            "\u{0303}" => '',
            "\u{0304}" => '',
            "\u{0306}" => '',
            "\u{0307}" => '',
            "\u{0308}" => '',
            "\u{030a}" => '',
            "\u{030c}" => '',
            "\u{0327}" => '',
            "\u{0328}" => '',
        ];
    }

    /**
     * Return Turkish fold mappings after Turkish-aware lowercasing.
     *
     * @return array<string,string>
     */
    private function turkish_fold_map(): array
    {
        return [
            'ç' => 'c',
            'ğ' => 'g',
            'ö' => 'o',
            'ş' => 's',
            'ü' => 'u',
            "\u{0300}" => '',
            "\u{0301}" => '',
            "\u{0302}" => '',
            "\u{0303}" => '',
            "\u{0304}" => '',
            "\u{0306}" => '',
            "\u{0307}" => '',
            "\u{0308}" => '',
            "\u{030a}" => '',
            "\u{030c}" => '',
            "\u{0327}" => '',
            "\u{0328}" => '',
        ];
    }

    /**
     * Return the broad Latin fallback fold map used by most languages.
     *
     * The map includes precomposed letters and common combining marks so the
     * analyzer remains deterministic without intl Normalizer support.
     *
     * @return array<string,string>
     */
    private function latin_fallback_fold_map(): array
    {
        return [
            "\u{0300}" => '',
            "\u{0301}" => '',
            "\u{0302}" => '',
            "\u{0303}" => '',
            "\u{0304}" => '',
            "\u{0306}" => '',
            "\u{0307}" => '',
            "\u{0308}" => '',
            "\u{030a}" => '',
            "\u{030c}" => '',
            "\u{0327}" => '',
            "\u{0328}" => '',
            'à' => 'a',
            'á' => 'a',
            'â' => 'a',
            'ã' => 'a',
            'ä' => 'a',
            'å' => 'a',
            'ā' => 'a',
            'ă' => 'a',
            'ą' => 'a',
            'æ' => 'ae',
            'ç' => 'c',
            'ć' => 'c',
            'č' => 'c',
            'ď' => 'd',
            'đ' => 'd',
            'è' => 'e',
            'é' => 'e',
            'ê' => 'e',
            'ë' => 'e',
            'ē' => 'e',
            'ė' => 'e',
            'ę' => 'e',
            'ě' => 'e',
            'ğ' => 'g',
            'ì' => 'i',
            'í' => 'i',
            'î' => 'i',
            'ï' => 'i',
            'ī' => 'i',
            'ı' => 'i',
            'ł' => 'l',
            'ñ' => 'n',
            'ń' => 'n',
            'ň' => 'n',
            'ò' => 'o',
            'ó' => 'o',
            'ô' => 'o',
            'õ' => 'o',
            'ö' => 'o',
            'ø' => 'o',
            'ō' => 'o',
            'œ' => 'oe',
            'ř' => 'r',
            'ś' => 's',
            'ş' => 's',
            'š' => 's',
            'ß' => 'ss',
            'ť' => 't',
            'ù' => 'u',
            'ú' => 'u',
            'û' => 'u',
            'ü' => 'u',
            'ū' => 'u',
            'ů' => 'u',
            'ý' => 'y',
            'ÿ' => 'y',
            'ź' => 'z',
            'ż' => 'z',
            'ž' => 'z',
            'ð' => 'd',
            'þ' => 'th',
        ];
    }

    /**
     * Return explicit uppercase-to-lowercase mappings for no-mbstring runs.
     *
     * This covers the same Latin letters the folding maps understand and the
     * Cyrillic alphabets used by bundled Russian and Ukrainian packs. It keeps
     * analyzer behavior stable in minimal PHP environments.
     *
     * @return array<string,string>
     */
    private function utf8_uppercase_lowercase_map(): array
    {
        return [
            'À' => 'à',
            'Á' => 'á',
            'Â' => 'â',
            'Ã' => 'ã',
            'Ä' => 'ä',
            'Å' => 'å',
            'Ā' => 'ā',
            'Ă' => 'ă',
            'Ą' => 'ą',
            'Æ' => 'æ',
            'Ç' => 'ç',
            'Ć' => 'ć',
            'Č' => 'č',
            'Ď' => 'ď',
            'Đ' => 'đ',
            'È' => 'è',
            'É' => 'é',
            'Ê' => 'ê',
            'Ë' => 'ë',
            'Ē' => 'ē',
            'Ė' => 'ė',
            'Ę' => 'ę',
            'Ě' => 'ě',
            'Ğ' => 'ğ',
            'Ì' => 'ì',
            'Í' => 'í',
            'Î' => 'î',
            'Ï' => 'ï',
            'Ī' => 'ī',
            'İ' => 'i',
            'Ł' => 'ł',
            'Ñ' => 'ñ',
            'Ń' => 'ń',
            'Ň' => 'ň',
            'Ò' => 'ò',
            'Ó' => 'ó',
            'Ô' => 'ô',
            'Õ' => 'õ',
            'Ö' => 'ö',
            'Ø' => 'ø',
            'Ō' => 'ō',
            'Œ' => 'œ',
            'Ř' => 'ř',
            'Ś' => 'ś',
            'Ş' => 'ş',
            'Š' => 'š',
            'ẞ' => 'ß',
            'Ť' => 'ť',
            'Ù' => 'ù',
            'Ú' => 'ú',
            'Û' => 'û',
            'Ü' => 'ü',
            'Ū' => 'ū',
            'Ů' => 'ů',
            'Ý' => 'ý',
            'Ÿ' => 'ÿ',
            'Ź' => 'ź',
            'Ż' => 'ż',
            'Ž' => 'ž',
            'Ð' => 'ð',
            'Þ' => 'þ',
            'А' => 'а',
            'Б' => 'б',
            'В' => 'в',
            'Г' => 'г',
            'Д' => 'д',
            'Е' => 'е',
            'Ё' => 'ё',
            'Ж' => 'ж',
            'З' => 'з',
            'И' => 'и',
            'Й' => 'й',
            'К' => 'к',
            'Л' => 'л',
            'М' => 'м',
            'Н' => 'н',
            'О' => 'о',
            'П' => 'п',
            'Р' => 'р',
            'С' => 'с',
            'Т' => 'т',
            'У' => 'у',
            'Ф' => 'ф',
            'Х' => 'х',
            'Ц' => 'ц',
            'Ч' => 'ч',
            'Ш' => 'ш',
            'Щ' => 'щ',
            'Ъ' => 'ъ',
            'Ы' => 'ы',
            'Ь' => 'ь',
            'Э' => 'э',
            'Ю' => 'ю',
            'Я' => 'я',
            'Ґ' => 'ґ',
            'Є' => 'є',
            'І' => 'і',
            'Ї' => 'ї',
        ];
    }

}
