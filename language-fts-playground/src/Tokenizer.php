<?php
declare(strict_types=1);

interface Language_FTS_Playground_Tokenizer_Adapter
{
    /**
     * @return array<int,array{surface:string,start_byte:int,end_byte:int,type:string}>
     */
    public function tokenize(string $text): array;
}

/**
 * Closed tokenizer adapter registry for profile-declared tokenizer ids.
 */
final class Language_FTS_Playground_Tokenizer_Registry
{
    /**
     * @return string[]
     */
    public static function supported_labels(): array
    {
        return [
            Language_FTS_Playground_Unicode_Words_Tokenizer::ID . '/' . Language_FTS_Playground_Unicode_Words_Tokenizer::TYPE,
            Language_FTS_Playground_Synthetic_Dictionary_Tokenizer::ID . '/' . Language_FTS_Playground_Synthetic_Dictionary_Tokenizer::TYPE,
        ];
    }

    /**
     * @param array<string,mixed> $contract
     */
    public static function validate_contract(array $contract, string $profile_file): void
    {
        $id = (string) ($contract['id'] ?? '');
        $type = (string) ($contract['type'] ?? '');
        $resources = (array) ($contract['resources'] ?? []);
        $capabilities = (array) ($contract['capabilities'] ?? []);

        if (!self::supports($id, $type)) {
            throw new UnexpectedValueException(
                'Language profile tokenizer must use a supported registry adapter (' . implode(', ', self::supported_labels()) . ') in ' . $profile_file
            );
        }

        if ($id === Language_FTS_Playground_Unicode_Words_Tokenizer::ID) {
            if ($resources !== []) {
                throw new UnexpectedValueException('Language profile tokenizer unicode_words_v1 resources must be empty in ' . $profile_file);
            }

            if (empty($capabilities['emits_offsets']) || empty($capabilities['emits_positions']) || !empty($capabilities['supports_overlaps'])) {
                throw new UnexpectedValueException('Language profile tokenizer unicode_words_v1 capabilities must emit offsets and positions without overlaps in ' . $profile_file);
            }

            return;
        }

        if ($id === Language_FTS_Playground_Synthetic_Dictionary_Tokenizer::ID) {
            $version = (string) ($contract['version'] ?? '');
            if (trim($version) === '') {
                throw new UnexpectedValueException('Language profile tokenizer synthetic_dictionary_v1 version must be declared in ' . $profile_file);
            }

            self::validate_resource_roles($resources, ['dictionary'], $profile_file);

            if (
                empty($capabilities['emits_offsets'])
                || empty($capabilities['emits_positions'])
                || !empty($capabilities['supports_fuzzy'])
                || !empty($capabilities['supports_overlaps'])
            ) {
                throw new UnexpectedValueException('Language profile tokenizer synthetic_dictionary_v1 capabilities must emit offsets and positions, disable fuzzy, and disable overlaps in ' . $profile_file);
            }
        }
    }

    public static function supports(string $id, string $type): bool
    {
        return ($id === Language_FTS_Playground_Unicode_Words_Tokenizer::ID && $type === Language_FTS_Playground_Unicode_Words_Tokenizer::TYPE)
            || ($id === Language_FTS_Playground_Synthetic_Dictionary_Tokenizer::ID && $type === Language_FTS_Playground_Synthetic_Dictionary_Tokenizer::TYPE);
    }

    /**
     * @param array<string,mixed> $contract
     */
    public static function is_synthetic_readiness_contract(array $contract): bool
    {
        return (string) ($contract['id'] ?? '') === Language_FTS_Playground_Synthetic_Dictionary_Tokenizer::ID
            && (string) ($contract['type'] ?? '') === Language_FTS_Playground_Synthetic_Dictionary_Tokenizer::TYPE
            && empty($contract['capabilities']['supports_fuzzy'])
            && empty($contract['capabilities']['supports_overlaps']);
    }

    /**
     * @param array<string,mixed> $contract
     * @param array<string,string> $resource_paths
     */
    public static function create(array $contract, array $resource_paths): Language_FTS_Playground_Tokenizer_Adapter
    {
        $id = (string) ($contract['id'] ?? '');
        $type = (string) ($contract['type'] ?? '');
        if ($id === Language_FTS_Playground_Unicode_Words_Tokenizer::ID && $type === Language_FTS_Playground_Unicode_Words_Tokenizer::TYPE) {
            return new Language_FTS_Playground_Unicode_Words_Tokenizer();
        }

        if ($id === Language_FTS_Playground_Synthetic_Dictionary_Tokenizer::ID && $type === Language_FTS_Playground_Synthetic_Dictionary_Tokenizer::TYPE) {
            return Language_FTS_Playground_Synthetic_Dictionary_Tokenizer::from_resource_paths($resource_paths);
        }

        throw new UnexpectedValueException('Unsupported tokenizer registry adapter.');
    }

    /**
     * @param array<string,string> $resources
     * @param string[] $required
     */
    private static function validate_resource_roles(array $resources, array $required, string $profile_file): void
    {
        $required_lookup = array_fill_keys($required, true);
        foreach ($required as $role) {
            if (!isset($resources[$role])) {
                throw new UnexpectedValueException("Language profile tokenizer resource {$role} must be declared in {$profile_file}");
            }
        }

        foreach ($resources as $role => $_file) {
            if (!isset($required_lookup[(string) $role])) {
                throw new UnexpectedValueException("Language profile tokenizer resource {$role} is not supported by this tokenizer in {$profile_file}");
            }
        }
    }
}

/**
 * Default tokenizer adapter for the existing Unicode letter/number behavior.
 */
final class Language_FTS_Playground_Unicode_Words_Tokenizer implements Language_FTS_Playground_Tokenizer_Adapter
{
    public const ID = 'unicode_words_v1';
    public const TYPE = 'unicode_words';

    /**
     * @return array{id:string,type:string,resources:array<string,string>,capabilities:array{emits_offsets:bool,emits_positions:bool,supports_fuzzy:bool,supports_overlaps:bool}}
     */
    public static function default_contract(): array
    {
        return [
            'id' => self::ID,
            'type' => self::TYPE,
            'resources' => [],
            'capabilities' => [
                'emits_offsets' => true,
                'emits_positions' => true,
                'supports_fuzzy' => true,
                'supports_overlaps' => false,
            ],
        ];
    }

    /**
     * @return array<int,array{surface:string,start_byte:int,end_byte:int,type:string}>
     */
    public function tokenize(string $text): array
    {
        if ($text === '') {
            return [];
        }

        $matches = [];
        $match_count = preg_match_all('/[\p{L}\p{N}]+/u', $text, $matches, PREG_OFFSET_CAPTURE);
        if ($match_count === false || $match_count === 0) {
            return [];
        }

        $tokens = [];
        foreach ($matches[0] as $match) {
            $surface = (string) ($match[0] ?? '');
            if ($surface === '') {
                continue;
            }

            $start = (int) ($match[1] ?? 0);
            $tokens[] = [
                'surface' => $surface,
                'start_byte' => $start,
                'end_byte' => $start + strlen($surface),
                'type' => preg_match('/^\p{N}+$/u', $surface) === 1 ? 'number' : 'word',
            ];
        }

        return $tokens;
    }
}

/**
 * Synthetic test-only dictionary segmenter for non-space tokenizer readiness.
 */
final class Language_FTS_Playground_Synthetic_Dictionary_Tokenizer implements Language_FTS_Playground_Tokenizer_Adapter
{
    public const ID = 'synthetic_dictionary_v1';
    public const TYPE = 'synthetic_dictionary_segmenter';

    /** @var array<int,array{surface:string,type:string,provenance:string}> */
    private array $dictionary;

    /**
     * @param array<int,array{surface:string,type:string,provenance:string}> $dictionary
     */
    public function __construct(array $dictionary)
    {
        $this->dictionary = $dictionary;
    }

    /**
     * @param array<string,string> $resource_paths
     */
    public static function from_resource_paths(array $resource_paths): self
    {
        $path = (string) ($resource_paths['dictionary'] ?? '');
        if ($path === '') {
            throw new UnexpectedValueException('Synthetic tokenizer dictionary resource is required.');
        }

        return new self(self::load_dictionary($path)['entries']);
    }

    /**
     * @return array{entries:array<int,array{surface:string,type:string,provenance:string}>,stats:array{rows:int,resource_bytes:int,max_input_run_bytes:int,max_output_token_bytes:int}}
     */
    public static function load_dictionary(string $path): array
    {
        $contents = file_get_contents($path);
        if (!is_string($contents)) {
            throw new RuntimeException('Could not read synthetic tokenizer dictionary: ' . $path);
        }

        if (preg_match('//u', $contents) !== 1) {
            throw new UnexpectedValueException('Synthetic tokenizer dictionary must be valid UTF-8: ' . $path);
        }

        if (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $contents) === 1) {
            throw new UnexpectedValueException('Synthetic tokenizer dictionary contains unsupported control bytes: ' . $path);
        }

        $lines = preg_split('/\r\n|\n|\r/', $contents);
        if (!is_array($lines)) {
            throw new RuntimeException('Could not split synthetic tokenizer dictionary: ' . $path);
        }

        $entries = [];
        $seen_surfaces = [];
        foreach ($lines as $line_number => $line) {
            $trimmed = trim($line);
            if ($trimmed === '' || str_starts_with($trimmed, '#')) {
                continue;
            }

            $columns = explode("\t", $line);
            if (count($columns) !== 3) {
                throw new UnexpectedValueException(self::resource_error($path, $line_number + 1, 'synthetic tokenizer dictionary rows must have exactly 3 tab-separated columns'));
            }

            $surface = self::decode_surface(trim($columns[0]), $path, $line_number + 1);
            if ($surface === '') {
                throw new UnexpectedValueException(self::resource_error($path, $line_number + 1, 'synthetic tokenizer dictionary surface must be non-empty'));
            }

            if (preg_match('/\s/u', $surface) === 1) {
                throw new UnexpectedValueException(self::resource_error($path, $line_number + 1, 'synthetic tokenizer dictionary surface must not contain whitespace'));
            }

            if (strlen($surface) > 255) {
                throw new UnexpectedValueException(self::resource_error($path, $line_number + 1, 'synthetic tokenizer dictionary surface must be 255 bytes or shorter'));
            }

            $type = trim($columns[1]);
            if (!in_array($type, ['dictionary_word', 'ideograph_run', 'thai_syllable_run'], true)) {
                throw new UnexpectedValueException(self::resource_error($path, $line_number + 1, 'synthetic tokenizer dictionary type must be dictionary_word, ideograph_run, or thai_syllable_run'));
            }

            $provenance = trim($columns[2]);
            if ($provenance === '') {
                throw new UnexpectedValueException(self::resource_error($path, $line_number + 1, 'synthetic tokenizer dictionary provenance must be non-empty'));
            }

            if (isset($seen_surfaces[$surface])) {
                throw new UnexpectedValueException(self::resource_error($path, $line_number + 1, 'duplicate synthetic tokenizer dictionary surface'));
            }
            $seen_surfaces[$surface] = true;

            $entries[] = [
                'surface' => $surface,
                'type' => $type,
                'provenance' => $provenance,
            ];
        }

        self::reject_overlapping_prefix_entries($entries, $path);
        usort(
            $entries,
            static fn(array $a, array $b): int => (strlen((string) $b['surface']) <=> strlen((string) $a['surface']))
                ?: strcmp((string) $a['surface'], (string) $b['surface'])
        );

        $max_surface_bytes = 0;
        foreach ($entries as $entry) {
            $max_surface_bytes = max($max_surface_bytes, strlen((string) $entry['surface']));
        }

        return [
            'entries' => $entries,
            'stats' => [
                'rows' => count($entries),
                'resource_bytes' => strlen($contents),
                'max_input_run_bytes' => $max_surface_bytes,
                'max_output_token_bytes' => $max_surface_bytes,
            ],
        ];
    }

    /**
     * @return array<int,array{surface:string,start_byte:int,end_byte:int,type:string}>
     */
    public function tokenize(string $text): array
    {
        if ($text === '' || $this->dictionary === []) {
            return [];
        }

        $tokens = [];
        $offset = 0;
        $length = strlen($text);
        while ($offset < $length) {
            $matched = null;
            foreach ($this->dictionary as $entry) {
                $surface = (string) $entry['surface'];
                if ($surface !== '' && substr($text, $offset, strlen($surface)) === $surface) {
                    $matched = $entry;
                    break;
                }
            }

            if ($matched !== null) {
                $surface = (string) $matched['surface'];
                $tokens[] = [
                    'surface' => $surface,
                    'start_byte' => $offset,
                    'end_byte' => $offset + strlen($surface),
                    'type' => (string) $matched['type'],
                ];
                $offset += strlen($surface);
                continue;
            }

            $next = self::next_utf8_character($text, $offset);
            if ($next === '') {
                break;
            }
            $offset += strlen($next);
        }

        return $tokens;
    }

    /**
     * @param array<int,array{surface:string,type:string,provenance:string}> $entries
     */
    private static function reject_overlapping_prefix_entries(array $entries, string $path): void
    {
        $surfaces = array_values(array_map(static fn(array $entry): string => (string) $entry['surface'], $entries));
        sort($surfaces, SORT_STRING);
        $count = count($surfaces);
        for ($i = 0; $i < $count; $i++) {
            for ($j = $i + 1; $j < $count; $j++) {
                if (!str_starts_with($surfaces[$j], $surfaces[$i])) {
                    break;
                }

                throw new UnexpectedValueException('Synthetic tokenizer dictionary must not contain overlapping prefix surfaces: ' . $path);
            }
        }
    }

    private static function decode_surface(string $surface, string $path, int $line_number): string
    {
        if ($surface === '') {
            return '';
        }

        $decoded = preg_replace_callback(
            '/\\\\u\{([0-9A-Fa-f]{1,6})\}/',
            static function (array $matches) use ($path, $line_number): string {
                $codepoint = hexdec((string) $matches[1]);
                if ($codepoint <= 0 || $codepoint > 0x10FFFF || ($codepoint >= 0xD800 && $codepoint <= 0xDFFF)) {
                    throw new UnexpectedValueException(self::resource_error($path, $line_number, 'synthetic tokenizer dictionary surface escape is not a valid Unicode codepoint'));
                }

                return self::utf8_chr((int) $codepoint);
            },
            $surface
        );

        if (!is_string($decoded) || preg_match('//u', $decoded) !== 1) {
            throw new UnexpectedValueException(self::resource_error($path, $line_number, 'synthetic tokenizer dictionary surface must decode to valid UTF-8'));
        }

        if (str_contains($decoded, '\\')) {
            throw new UnexpectedValueException(self::resource_error($path, $line_number, 'synthetic tokenizer dictionary surface contains an unsupported escape'));
        }

        return $decoded;
    }

    private static function utf8_chr(int $codepoint): string
    {
        if ($codepoint <= 0x7F) {
            return chr($codepoint);
        }

        if ($codepoint <= 0x7FF) {
            return chr(0xC0 | ($codepoint >> 6))
                . chr(0x80 | ($codepoint & 0x3F));
        }

        if ($codepoint <= 0xFFFF) {
            return chr(0xE0 | ($codepoint >> 12))
                . chr(0x80 | (($codepoint >> 6) & 0x3F))
                . chr(0x80 | ($codepoint & 0x3F));
        }

        return chr(0xF0 | ($codepoint >> 18))
            . chr(0x80 | (($codepoint >> 12) & 0x3F))
            . chr(0x80 | (($codepoint >> 6) & 0x3F))
            . chr(0x80 | ($codepoint & 0x3F));
    }

    private static function next_utf8_character(string $text, int $offset): string
    {
        $slice = substr($text, $offset);
        if (!is_string($slice) || $slice === '') {
            return '';
        }

        $matches = [];
        return preg_match('/\A./us', $slice, $matches) === 1 ? (string) $matches[0] : $slice[0];
    }

    private static function resource_error(string $path, int $line_number, string $message): string
    {
        return "{$path}:{$line_number}: {$message}";
    }
}
