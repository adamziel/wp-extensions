<?php
declare(strict_types=1);

/**
 * Optional Chinese segmenter backed directly by the pinned Jieba submodule.
 *
 * Runtime construction validates the exact upstream dictionary hash before the
 * segmenter can be used. Lookups stream the dictionary lazily for only the
 * first characters observed in a CJK run and keep a bounded LRU cache, so the
 * full dictionary is never materialized during ordinary indexing or search.
 */
final class WP_FTS_ChineseJiebaSegmenter
{
    public const SOURCE_REPOSITORY = 'https://github.com/fxsjy/jieba';
    public const SOURCE_COMMIT = '67fa2e36e72f69d9134b8a1037b83fbb070b9775';
    public const SOURCE_FILE = 'jieba/dict.txt';
    public const SOURCE_SHA256 = '7197c3211ddd98962b036cdf40324d1ea2bfaa12bd028e68faa70111a88e12a8';
    public const SOURCE_BYTE_SIZE = 5071852;

    private const FALLBACK_MAX_NGRAM_LENGTH = 4;
    private const DEFAULT_MAX_CACHED_PREFIXES = 16;
    private const DEFAULT_MAX_CANDIDATES_PER_PREFIX = 5000;

    /** @var array<string,array{entries:array<int,array{word:string,frequency:int,length:int}>,words:array<string,bool>}> */
    private array $prefixCache = [];

    /** @var string[] */
    private array $prefixCacheOrder = [];

    private string $sourceFile;
    private string $language;
    private string $packId;
    private string $packVersion;
    private string $sourceSha256;
    private int $sourceByteSize;
    private bool $fixtureOnly;
    private int $maxCachedPrefixes;
    private int $maxCandidatesPerPrefix;
    private string $indexSignature;

    /**
     * @param array{
     *   source_file:string,
     *   language?:string,
     *   pack_id?:string,
     *   version?:string,
     *   source_repository?:string,
     *   source_commit?:string,
     *   source_path?:string,
     *   expected_sha256?:string,
     *   sha256?:string,
     *   expected_byte_size?:int,
     *   expected_bytes?:int,
     *   byte_size?:int,
     *   fixture_only?:bool,
     *   max_cached_prefixes?:int,
     *   max_candidates_per_prefix?:int
     * } $config
     */
    private function __construct(array $config)
    {
        $this->sourceFile = (string) $config['source_file'];
        $this->language = self::base_language((string) ($config['language'] ?? 'zh'));
        $this->packId = trim((string) ($config['pack_id'] ?? 'zh-jieba-dict-67fa2e36e72f'));
        $this->packVersion = trim((string) ($config['version'] ?? '67fa2e36e72f-source-v1'));
        $this->sourceSha256 = strtolower(trim((string) ($config['expected_sha256'] ?? $config['sha256'] ?? self::SOURCE_SHA256)));
        $this->sourceByteSize = (int) ($config['expected_byte_size'] ?? $config['expected_bytes'] ?? $config['byte_size'] ?? self::SOURCE_BYTE_SIZE);
        $this->fixtureOnly = (bool) ($config['fixture_only'] ?? false);
        $this->maxCachedPrefixes = max(1, (int) ($config['max_cached_prefixes'] ?? self::DEFAULT_MAX_CACHED_PREFIXES));
        $this->maxCandidatesPerPrefix = max(1, (int) ($config['max_candidates_per_prefix'] ?? self::DEFAULT_MAX_CANDIDATES_PER_PREFIX));

        if ($this->language !== 'zh') {
            throw new RuntimeException('Jieba segmenter can only be used for the zh language partition.');
        }
        if ($this->packId === '') {
            throw new RuntimeException('Jieba segmenter pack id cannot be empty.');
        }
        if ($this->packVersion === '') {
            throw new RuntimeException('Jieba segmenter version cannot be empty.');
        }
        $this->verify_source_file();
        $this->indexSignature = $this->build_index_signature($config);
    }

    /**
     * Return the default submodule dictionary path.
     */
    public static function default_source_file(): string
    {
        return dirname(__DIR__) . '/resources/sources/jieba/' . self::SOURCE_FILE;
    }

    /**
     * Return source evidence for diagnostics and result artifacts.
     *
     * @return array{repository:string,commit:string,file:string,sha256:string,byte_size:int,path:string,available:bool}
     */
    public static function default_source_evidence(): array
    {
        $path = self::default_source_file();

        return [
            'repository' => self::SOURCE_REPOSITORY,
            'commit' => self::SOURCE_COMMIT,
            'file' => self::SOURCE_FILE,
            'sha256' => self::SOURCE_SHA256,
            'byte_size' => self::SOURCE_BYTE_SIZE,
            'path' => $path,
            'available' => is_file($path)
                && filesize($path) === self::SOURCE_BYTE_SIZE
                && hash_file('sha256', $path) === self::SOURCE_SHA256,
        ];
    }

    /**
     * Load a segmenter from the public analyzer option shape.
     */
    public static function from_pack_option(mixed $option, ?string $expectedLanguage = null): ?self
    {
        $config = self::source_config_from_option($option);
        if ($config === null) {
            return null;
        }

        if ($expectedLanguage !== null && trim($expectedLanguage) !== '') {
            $config['language'] = self::base_language($expectedLanguage);
        }

        try {
            return new self($config);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Segment one Chinese CJK run and retain fallback n-grams for recall.
     *
     * @return string[]
     */
    public function __invoke(string $run, string $language): array
    {
        if (self::base_language($language) !== $this->language) {
            return [];
        }

        $chars = $this->utf8_chars($run);
        if ($chars === []) {
            return [];
        }
        $this->preload_prefixes($chars);

        $tokens = [];
        $seen = [];
        foreach ($this->dictionary_segments($chars) as $segment) {
            foreach ($this->search_subsegments($segment) as $subsegment) {
                $this->append_unique($tokens, $seen, $subsegment);
            }
            $this->append_unique($tokens, $seen, $segment);
        }
        foreach ($this->fallback_cjk_tokens($chars) as $token) {
            $this->append_unique($tokens, $seen, $token);
        }

        return $tokens;
    }

    /**
     * Return a stable analyzer signature component for stale-document checks.
     */
    public function index_signature(): string
    {
        return $this->indexSignature;
    }

    public function pack_id(): string
    {
        return $this->packId;
    }

    public function is_fixture_only(): bool
    {
        return $this->fixtureOnly;
    }

    public function source_file(): string
    {
        return $this->sourceFile;
    }

    private function verify_source_file(): void
    {
        if (!is_file($this->sourceFile)) {
            throw new RuntimeException('Jieba dictionary source file is missing.');
        }

        $size = filesize($this->sourceFile);
        if ($size !== $this->sourceByteSize) {
            throw new RuntimeException('Jieba dictionary byte size mismatch.');
        }

        $hash = hash_file('sha256', $this->sourceFile);
        if (!is_string($hash) || strtolower($hash) !== $this->sourceSha256) {
            throw new RuntimeException('Jieba dictionary SHA-256 mismatch.');
        }
    }

    /**
     * @param string[] $chars
     * @return string[]
     */
    private function dictionary_segments(array $chars): array
    {
        $segments = [];
        $count = count($chars);
        for ($offset = 0; $offset < $count;) {
            $match = $this->longest_dictionary_match($chars, $offset);
            if ($match === null) {
                $offset++;
                continue;
            }

            $segments[] = $match['word'];
            $offset += $match['length'];
        }

        return $segments;
    }

    /**
     * @param string[] $chars
     * @return array{word:string,length:int}|null
     */
    private function longest_dictionary_match(array $chars, int $offset): ?array
    {
        $first = $chars[$offset] ?? '';
        if ($first === '') {
            return null;
        }

        $remainingLength = count($chars) - $offset;
        if ($remainingLength < 2) {
            return null;
        }
        $remaining = implode('', array_slice($chars, $offset));

        foreach ($this->load_prefix($first)['entries'] as $entry) {
            if ($entry['length'] > $remainingLength) {
                continue;
            }
            if (str_starts_with($remaining, $entry['word'])) {
                return [
                    'word' => $entry['word'],
                    'length' => $entry['length'],
                ];
            }
        }

        return null;
    }

    /**
     * Return short dictionary-backed subsegments similar to Jieba search mode.
     *
     * @return string[]
     */
    private function search_subsegments(string $segment): array
    {
        $chars = $this->utf8_chars($segment);
        $count = count($chars);
        if ($count <= 2) {
            return [];
        }

        $subsegments = [];
        $seen = [];
        $maxLength = min(3, $count - 1);
        for ($length = 2; $length <= $maxLength; $length++) {
            for ($offset = 0; $offset <= $count - $length; $offset++) {
                $candidate = implode('', array_slice($chars, $offset, $length));
                if (isset($seen[$candidate]) || !$this->dictionary_contains($candidate)) {
                    continue;
                }

                $seen[$candidate] = true;
                $subsegments[] = $candidate;
            }
        }

        return $subsegments;
    }

    private function dictionary_contains(string $word): bool
    {
        $chars = $this->utf8_chars($word);
        $first = $chars[0] ?? '';
        if ($first === '') {
            return false;
        }

        return isset($this->load_prefix($first)['words'][$word]);
    }

    /**
     * @return array{entries:array<int,array{word:string,frequency:int,length:int}>,words:array<string,bool>}
     */
    private function load_prefix(string $first): array
    {
        if (isset($this->prefixCache[$first])) {
            $this->touch_prefix($first);
            return $this->prefixCache[$first];
        }

        $data = [
            'entries' => [],
            'words' => [],
        ];

        $handle = fopen($this->sourceFile, 'rb');
        if (!is_resource($handle)) {
            return $data;
        }

        try {
            while (($line = fgets($handle)) !== false) {
                $row = $this->parse_dict_line((string) $line);
                if ($row === null) {
                    continue;
                }

                $word = $row['word'];
                if (!str_starts_with($word, $first)) {
                    continue;
                }
                $chars = $this->utf8_chars($word);
                if (($chars[0] ?? '') !== $first || count($chars) < 2 || !$this->contains_han($word)) {
                    continue;
                }

                if (count($data['entries']) >= $this->maxCandidatesPerPrefix) {
                    throw new RuntimeException('Jieba prefix candidate cache cap exceeded.');
                }

                $data['words'][$word] = true;
                $data['entries'][] = [
                    'word' => $word,
                    'frequency' => $row['frequency'],
                    'length' => count($chars),
                ];
            }
        } finally {
            fclose($handle);
        }

        usort(
            $data['entries'],
            static function (array $a, array $b): int {
                $length = $b['length'] <=> $a['length'];
                if ($length !== 0) {
                    return $length;
                }
                $frequency = $b['frequency'] <=> $a['frequency'];
                if ($frequency !== 0) {
                    return $frequency;
                }

                return strcmp($a['word'], $b['word']);
            }
        );

        $this->prefixCache[$first] = $data;
        $this->touch_prefix($first);
        $this->evict_old_prefixes();

        return $data;
    }

    /**
     * Scan the source once for the uncached first characters needed by a run.
     *
     * @param string[] $chars
     */
    private function preload_prefixes(array $chars): void
    {
        $needed = [];
        foreach ($chars as $char) {
            if ($char === '' || isset($this->prefixCache[$char]) || isset($needed[$char])) {
                continue;
            }
            if (count($needed) >= $this->maxCachedPrefixes) {
                break;
            }
            $needed[$char] = [
                'entries' => [],
                'words' => [],
            ];
        }
        if ($needed === []) {
            return;
        }

        $handle = fopen($this->sourceFile, 'rb');
        if (!is_resource($handle)) {
            return;
        }

        try {
            while (($line = fgets($handle)) !== false) {
                $row = $this->parse_dict_line((string) $line);
                if ($row === null) {
                    continue;
                }

                $word = $row['word'];
                $first = null;
                foreach ($needed as $prefix => $_data) {
                    if (str_starts_with($word, (string) $prefix)) {
                        $first = (string) $prefix;
                        break;
                    }
                }
                if ($first === null) {
                    continue;
                }

                $chars = $this->utf8_chars($word);
                if (($chars[0] ?? '') !== $first || count($chars) < 2 || !$this->contains_han($word)) {
                    continue;
                }

                if (count($needed[$first]['entries']) >= $this->maxCandidatesPerPrefix) {
                    throw new RuntimeException('Jieba prefix candidate cache cap exceeded.');
                }

                $needed[$first]['words'][$word] = true;
                $needed[$first]['entries'][] = [
                    'word' => $word,
                    'frequency' => $row['frequency'],
                    'length' => count($chars),
                ];
            }
        } finally {
            fclose($handle);
        }

        foreach ($needed as $prefix => $data) {
            $this->sort_prefix_entries($data['entries']);
            $this->prefixCache[(string) $prefix] = $data;
            $this->touch_prefix((string) $prefix);
        }
        $this->evict_old_prefixes();
    }

    /**
     * @param array<int,array{word:string,frequency:int,length:int}> $entries
     */
    private function sort_prefix_entries(array &$entries): void
    {
        usort(
            $entries,
            static function (array $a, array $b): int {
                $length = $b['length'] <=> $a['length'];
                if ($length !== 0) {
                    return $length;
                }
                $frequency = $b['frequency'] <=> $a['frequency'];
                if ($frequency !== 0) {
                    return $frequency;
                }

                return strcmp($a['word'], $b['word']);
            }
        );
    }

    /**
     * @return array{word:string,frequency:int,tag:string}|null
     */
    private function parse_dict_line(string $line): ?array
    {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') {
            return null;
        }

        $offset = 0;
        $word = $this->next_field($line, $offset);
        $frequency = $this->next_field($line, $offset);
        $tag = $this->next_field($line, $offset) ?? 'x';
        if ($word === null || $frequency === null || $tag === '') {
            return null;
        }

        $frequencyValue = $this->positive_int($frequency);
        if ($frequencyValue === null) {
            return null;
        }

        return [
            'word' => $word,
            'frequency' => $frequencyValue,
            'tag' => $tag,
        ];
    }

    private function next_field(string $line, int &$offset): ?string
    {
        $length = strlen($line);
        while ($offset < $length && $this->is_ascii_whitespace($line[$offset])) {
            $offset++;
        }
        if ($offset >= $length) {
            return null;
        }

        $start = $offset;
        while ($offset < $length && !$this->is_ascii_whitespace($line[$offset])) {
            $offset++;
        }

        return substr($line, $start, $offset - $start);
    }

    private function is_ascii_whitespace(string $byte): bool
    {
        return $byte === ' ' || $byte === "\t" || $byte === "\r" || $byte === "\n";
    }

    private function positive_int(string $value): ?int
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        $length = strlen($value);
        $number = 0;
        for ($i = 0; $i < $length; $i++) {
            $byte = ord($value[$i]);
            if ($byte < 48 || $byte > 57) {
                return null;
            }
            $number = ($number * 10) + ($byte - 48);
        }

        return $number > 0 ? $number : null;
    }

    private function contains_han(string $word): bool
    {
        return preg_match('/\p{Han}/u', $word) === 1;
    }

    /**
     * @param string[] $chars
     * @return string[]
     */
    private function fallback_cjk_tokens(array $chars): array
    {
        $count = count($chars);
        if ($count <= 1) {
            return $chars;
        }

        $tokens = [];
        $maxLength = min(self::FALLBACK_MAX_NGRAM_LENGTH, $count);
        for ($length = 1; $length <= $maxLength; $length++) {
            for ($offset = 0; $offset <= $count - $length; $offset++) {
                $tokens[] = implode('', array_slice($chars, $offset, $length));
            }
        }

        return $tokens;
    }

    /**
     * @return string[]
     */
    private function utf8_chars(string $text): array
    {
        if (!preg_match_all('/./us', $text, $matches)) {
            return [];
        }

        return $matches[0];
    }

    /**
     * @param string[] $tokens
     * @param array<string,bool> $seen
     */
    private function append_unique(array &$tokens, array &$seen, string $token): void
    {
        if ($token === '' || isset($seen[$token])) {
            return;
        }

        $seen[$token] = true;
        $tokens[] = $token;
    }

    private function touch_prefix(string $prefix): void
    {
        $this->prefixCacheOrder = array_values(array_filter(
            $this->prefixCacheOrder,
            static fn(string $cached): bool => $cached !== $prefix
        ));
        $this->prefixCacheOrder[] = $prefix;
    }

    private function evict_old_prefixes(): void
    {
        while (count($this->prefixCacheOrder) > $this->maxCachedPrefixes) {
            $oldest = array_shift($this->prefixCacheOrder);
            if (is_string($oldest)) {
                unset($this->prefixCache[$oldest]);
            }
        }
    }

    /**
     * @param array<string,mixed> $config
     */
    private function build_index_signature(array $config): string
    {
        $payload = [
            'contract' => 'wp-fts-chinese-jieba-segmenter',
            'version' => 1,
            'pack_id' => $this->packId,
            'pack_version' => $this->packVersion,
            'language' => $this->language,
            'fixture_only' => $this->fixtureOnly,
            'source_repository' => (string) ($config['source_repository'] ?? self::SOURCE_REPOSITORY),
            'source_commit' => (string) ($config['source_commit'] ?? self::SOURCE_COMMIT),
            'source_path' => (string) ($config['source_path'] ?? self::SOURCE_FILE),
            'source_sha256' => $this->sourceSha256,
            'source_byte_size' => $this->sourceByteSize,
            'fallback_max_ngram_length' => self::FALLBACK_MAX_NGRAM_LENGTH,
            'max_cached_prefixes' => $this->maxCachedPrefixes,
            'max_candidates_per_prefix' => $this->maxCandidatesPerPrefix,
        ];

        return 'wp-fts-chinese-jieba-segmenter-v1:' . sha1($this->stable_json($payload));
    }

    private function stable_json(mixed $value): string
    {
        if (is_array($value)) {
            if (array_keys($value) !== range(0, count($value) - 1)) {
                ksort($value, SORT_STRING);
            }
            foreach ($value as $key => $item) {
                $value[$key] = $this->stable_json_value($item);
            }
        }

        try {
            return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return serialize($value);
        }
    }

    private function stable_json_value(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }

        if (array_keys($value) !== range(0, count($value) - 1)) {
            ksort($value, SORT_STRING);
        }
        foreach ($value as $key => $item) {
            $value[$key] = $this->stable_json_value($item);
        }

        return $value;
    }

    /**
     * @return array<string,mixed>|null
     */
    private static function source_config_from_option(mixed $option): ?array
    {
        if ($option === false || $option === null) {
            return null;
        }

        if ($option === true) {
            return self::default_source_config();
        }

        if (is_string($option)) {
            $path = trim($option);
            if ($path === '' || in_array(strtolower($path), ['0', 'false', 'no', 'off'], true)) {
                return null;
            }

            return self::default_source_config(self::source_file_from_path($path));
        }

        if (!is_array($option)) {
            return null;
        }

        $path = null;
        foreach (['source_file', 'dict_path', 'dictionary', 'path'] as $key) {
            if (isset($option[$key]) && is_scalar($option[$key])) {
                $path = self::source_file_from_path(trim((string) $option[$key]));
                break;
            }
        }
        if ($path === null) {
            foreach (['source_root', 'root', 'submodule_path'] as $key) {
                if (isset($option[$key]) && is_scalar($option[$key])) {
                    $path = self::source_file_from_path(trim((string) $option[$key]));
                    break;
                }
            }
        }

        $config = self::default_source_config($path);
        foreach ([
            'language',
            'pack_id',
            'version',
            'source_repository',
            'source_commit',
            'source_path',
            'expected_sha256',
            'sha256',
            'expected_byte_size',
            'expected_bytes',
            'byte_size',
            'fixture_only',
            'max_cached_prefixes',
            'max_candidates_per_prefix',
        ] as $key) {
            if (array_key_exists($key, $option)) {
                $config[$key] = $option[$key];
            }
        }

        return $config;
    }

    /**
     * @return array<string,mixed>
     */
    private static function default_source_config(?string $sourceFile = null): array
    {
        return [
            'source_file' => $sourceFile ?? self::default_source_file(),
            'language' => 'zh',
            'pack_id' => 'zh-jieba-dict-67fa2e36e72f',
            'version' => '67fa2e36e72f-source-v1',
            'source_repository' => self::SOURCE_REPOSITORY,
            'source_commit' => self::SOURCE_COMMIT,
            'source_path' => self::SOURCE_FILE,
            'expected_sha256' => self::SOURCE_SHA256,
            'expected_byte_size' => self::SOURCE_BYTE_SIZE,
            'fixture_only' => false,
        ];
    }

    private static function source_file_from_path(string $path): ?string
    {
        if ($path === '') {
            return null;
        }
        if (is_dir($path)) {
            return rtrim($path, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . self::SOURCE_FILE;
        }

        return $path;
    }

    private static function base_language(string $language): string
    {
        $canonical = WP_FTS_TermNamespace::canonicalize_lang($language);
        $parts = explode('-', str_replace('_', '-', $canonical));

        return strtolower((string) ($parts[0] ?? $canonical));
    }
}
