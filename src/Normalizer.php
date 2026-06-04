<?php
declare(strict_types=1);

final class WP_FTS_Normalizer
{
    private bool $foldDiacritics;

    /**
     * @param array{fold_diacritics?:bool} $options
     */
    public function __construct(array $options = [])
    {
        $this->foldDiacritics = (bool) ($options['fold_diacritics'] ?? true);
    }

    public function canonicalize_language(string $language): string
    {
        $language = trim(str_replace('_', '-', $language));
        if ($language === '') {
            return 'und';
        }

        $parts = array_values(array_filter(explode('-', $language), static fn(string $part): bool => $part !== ''));
        if ($parts === []) {
            return 'und';
        }

        $primary = strtolower($parts[0]);
        if ($primary === 'zh') {
            return $this->canonicalize_chinese($parts);
        }

        if (count($parts) === 1) {
            return $primary;
        }

        $canonical = [$primary];
        foreach (array_slice($parts, 1) as $part) {
            if (strlen($part) === 2 || strlen($part) === 3 && $this->is_ascii_digit($part)) {
                $canonical[] = strtoupper($part);
                continue;
            }

            if (strlen($part) === 4) {
                $canonical[] = ucfirst(strtolower($part));
                continue;
            }

            $canonical[] = strtolower($part);
        }

        return implode('-', $canonical);
    }

    private function is_ascii_digit(string $value): bool
    {
        return $value !== '' && preg_match('/^[0-9]+$/', $value) === 1;
    }

    public function base_language(string $language): string
    {
        $language = $this->canonicalize_language($language);
        $separator = strpos($language, '-');

        return $separator === false ? $language : substr($language, 0, $separator);
    }

    public function normalize_token(string $token, string $language): string
    {
        $language = $this->canonicalize_language($language);
        $token = $this->lowercase($token, $language);
        $token = $this->normalize_dialect($token, $language);

        if (!$this->foldDiacritics) {
            return $token;
        }

        return $this->fold_for_language($token, $language);
    }

    /**
     * Turkish casing is locale-sensitive: ASCII I lowercases to dotless i.
     * PHP has no per-call locale casing, so handle the two special letters first.
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

    private function normalize_dialect(string $token, string $language): string
    {
        $base = $this->base_language($language);
        if ($base === 'en') {
            return $this->normalize_english_dialect($token);
        }

        if ($base === 'zh') {
            return $this->normalize_chinese_dialect($token, $language);
        }

        return $token;
    }

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
     * Placeholder hook for a future Traditional/Simplified conversion table.
     * The empty map keeps v1 deterministic without pretending to do full conversion.
     */
    private function normalize_chinese_dialect(string $token, string $language): string
    {
        $maps = [
            'zh-Hans' => [],
            'zh-Hant' => [],
        ];

        return $maps[$language][$token] ?? $token;
    }

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

    /**
     * @param string[] $parts
     */
    private function canonicalize_chinese(array $parts): string
    {
        $subtags = array_map('strtolower', array_slice($parts, 1));
        foreach ($subtags as $subtag) {
            if ($subtag === 'hans') {
                return 'zh-Hans';
            }
            if ($subtag === 'hant') {
                return 'zh-Hant';
            }
        }

        foreach ($subtags as $subtag) {
            if (in_array($subtag, ['cn', 'sg'], true)) {
                return 'zh-Hans';
            }
            if (in_array($subtag, ['tw', 'hk', 'mo'], true)) {
                return 'zh-Hant';
            }
        }

        return 'zh';
    }
}
