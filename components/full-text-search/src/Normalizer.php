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
     *   chinese_script_map?:array<string,string>|array<string,array<string,string>>
     * } $options Set `fold_diacritics` false when accents and
     *        language-specific letters must remain distinct in the index.
     *        `token_normalizer` may perform deterministic language-specific
     *        rewrites after the built-in dialect maps. `chinese_script_map`
     *        accepts either a flat character map or a language-keyed map.
     */
    public function __construct(array $options = [])
    {
        $this->foldDiacritics = (bool) ($options['fold_diacritics'] ?? true);
        $normalizer = $options['token_normalizer'] ?? null;
        $this->tokenNormalizer = is_callable($normalizer) ? $normalizer : null;
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
     * ligatures. Composer installs the pure-PHP intl normalizer polyfill when
     * the extension is unavailable; the guarded fallback keeps source-tree
     * bootstraps usable when dependencies have not been installed yet.
     */
    public function normalize_unicode(string $text): string
    {
        $text = WP_FTS_Utf8::repair_word_boundaries($text);
        if (!class_exists('Normalizer')) {
            return $text;
        }

        try {
            $normalized = Normalizer::normalize($text, Normalizer::FORM_KC);
        } catch (Throwable) {
            return $text;
        }

        return is_string($normalized) ? $normalized : $text;
    }

    /**
     * Identify the Unicode normalization backend for stale-index checks.
     *
     * Composer makes NFKC available through the Symfony polyfill, while raw
     * source-tree bootstraps may temporarily run without it. Encoding that
     * distinction prevents installing the dependency later from silently
     * changing query terms without reindexing existing documents.
     */
    public function index_signature(): string
    {
        if (!class_exists('Normalizer')) {
            return 'wp-fts-unicode-normalizer:none';
        }

        $backend = defined('INTL_ICU_VERSION')
            ? 'intl-' . (string) constant('INTL_ICU_VERSION')
            : 'symfony-polyfill-' . $this->polyfill_version_signature();

        return 'wp-fts-unicode-normalizer:nfkc-' . $backend;
    }

    /**
     * Resolve the installed polyfill release, with a data hash fallback for
     * source trees that load the Symfony class without Composer metadata.
     */
    private function polyfill_version_signature(): string
    {
        if (class_exists('Composer\\InstalledVersions')) {
            try {
                $version = Composer\InstalledVersions::getPrettyVersion('symfony/polyfill-intl-normalizer')
                    ?? Composer\InstalledVersions::getReference('symfony/polyfill-intl-normalizer');
                if (is_string($version) && $version !== '') {
                    return $version;
                }
            } catch (Throwable) {
                // Fall through to hashing the loaded normalization tables.
            }
        }

        if (!class_exists('Symfony\\Polyfill\\Intl\\Normalizer\\Normalizer')) {
            return 'unknown';
        }

        try {
            $classFile = (new ReflectionClass('Symfony\\Polyfill\\Intl\\Normalizer\\Normalizer'))->getFileName();
            if (!is_string($classFile) || !is_file($classFile)) {
                return 'unknown';
            }

            $files = array_merge(
                [$classFile],
                glob(dirname($classFile) . '/Resources/unidata/*.php') ?: []
            );
            sort($files, SORT_STRING);
            $hash = hash_init('sha256');
            foreach ($files as $file) {
                hash_update($hash, basename($file) . "\0" . hash_file('sha256', $file) . "\n");
            }

            return 'data-' . substr(hash_final($hash), 0, 16);
        } catch (Throwable) {
            return 'unknown';
        }
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
        if (!is_array($maps) || $maps === []) {
            return [];
        }

        $isFlatMap = true;
        foreach ($maps as $value) {
            if (!is_scalar($value)) {
                $isFlatMap = false;
                break;
            }
        }

        if ($isFlatMap) {
            return ['zh' => $this->normalize_string_map($maps)];
        }

        $normalized = [];
        foreach ($maps as $language => $map) {
            $map = $this->normalize_string_map($map);
            if ($map === []) {
                continue;
            }

            $normalized[$this->canonicalize_language((string) $language)] = $map;
        }

        return $normalized;
    }

    /**
     * Keep only scalar-to-scalar entries in a normalization map.
     *
     * @param mixed $map
     * @return array<string,string>
     */
    private function normalize_string_map(mixed $map): array
    {
        if (!is_array($map)) {
            return [];
        }

        $normalized = [];
        foreach ($map as $from => $to) {
            if (is_scalar($from) && is_scalar($to) && (string) $from !== '') {
                $normalized[(string) $from] = (string) $to;
            }
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

        try {
            $normalized = ($this->tokenNormalizer)($token, $language);
        } catch (Throwable) {
            return $token;
        }

        return is_scalar($normalized) ? (string) $normalized : $token;
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
     * Return Polish-specific fold mappings.
     *
     * Kept separate for tests and future language-specific tuning.
     *
     * @return array<string,string>
     */
    private function polish_fold_map(): array
    {
        return [
            'ą' => 'a',
            'ć' => 'c',
            'ę' => 'e',
            'ł' => 'l',
            'ń' => 'n',
            'ó' => 'o',
            'ś' => 's',
            'ź' => 'z',
            'ż' => 'z',
        ];
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
     * This covers the same Latin letters the folding maps understand and keeps
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
        ];
    }

}
