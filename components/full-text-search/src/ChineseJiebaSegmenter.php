<?php
declare(strict_types=1);

/**
 * Optional Chinese segmenter backed by the pinned Jieba dictionary.
 *
 * Runtime construction validates custom source hashes eagerly. The pinned
 * dictionary instead requires an attested, compact first-codepoint range index
 * and verifies each source range when it is read. Ordinary lookups therefore
 * avoid rescanning the 5 MiB dictionary. Source-only custom dictionaries are
 * limited to fixtures: they build the same range shape in one bounded source
 * pass and must pass complete-cache admission before any result is returned.
 */
final class WP_FTS_ChineseJiebaSegmenter
{
    public const SOURCE_REPOSITORY = 'https://github.com/fxsjy/jieba';
    public const SOURCE_COMMIT = '67fa2e36e72f69d9134b8a1037b83fbb070b9775';
    public const SOURCE_FILE = 'jieba/dict.txt';
    public const SOURCE_SHA256 = '7197c3211ddd98962b036cdf40324d1ea2bfaa12bd028e68faa70111a88e12a8';
    public const SOURCE_BYTE_SIZE = 5071852;
    public const LOOKUP_SHA256 = '4c979fd244e59b8343c2e584dbd5ba062deb1f836b8ae9ca2b56b54f130b9046';
    public const LOOKUP_BYTE_SIZE = 329972;
    public const LOOKUP_RANGE_COUNT = 11783;

    // A dictionary word may use the complete 4-KiB lexical-run allowance;
    // another 4 KiB leaves room for its frequency and tag without permitting
    // one valid custom dictionary row to allocate the complete 16-MiB source.
    public const MAX_DICTIONARY_LINE_BYTES = 8192;

    // The public segmenter definition admits 337,461 pinned rows and 3,013,799
    // bytes of candidate words; LanguagePipeline can reach the 337,399 rows and
    // 3,013,489 bytes whose first codepoint is Han. Compact word-membership and
    // length maps therefore keep every populated pinned prefix resident after
    // its first indexed read. Fixture dictionaries must fit the same complete
    // cache at admission; there is no eviction/re-read path.
    public const MAX_RETAINED_DICTIONARY_CANDIDATES = 350000;
    public const MAX_RETAINED_DICTIONARY_CANDIDATE_BYTES = 8388608;

    private const FALLBACK_MAX_NGRAM_LENGTH = 4;
    private const DEFAULT_MAX_CANDIDATES_PER_PREFIX = 5000;
    private const MAX_CANDIDATES_PER_PREFIX = 5000;
    private const MAX_SOURCE_FILE_BYTES = 16777216;
    private const LOOKUP_MAGIC = "WPFTSJ2\0";
    private const LOOKUP_HEADER_BYTES = 48;
    private const LOOKUP_RECORD_BYTES = 28;
    private const DYNAMIC_LOOKUP_RECORD_BYTES = 12;
    private const UNICODE_CODEPOINT_COUNT = 1114112;
    private const NO_DYNAMIC_RANGE = 0xFFFFFFFF;
    private const LOADED_PREFIX_BYTES = 139264;
    private const MAX_RETAINED_RUNS = 256;
    private const MAX_RETAINED_RUN_TOKENS = 4096;
    private const MAX_RETAINED_RUN_BYTES = 262144;

    /** @var array<string,array{words:array<string,bool>,lengths:array<int,bool>,candidate_count:int,candidate_bytes:int}> */
    private array $prefixCache = [];
    private ?string $loadedPrefixBits = null;

    /** Number of complete dictionary scans, retained for bounded-work tests. */
    private int $dictionaryScanCount = 0;
    /** Number of complete source hashes performed by this instance. */
    private int $sourceHashScanCount = 0;

    /** Aggregate retained entries and logical word bytes across complete prefixes. */
    private int $cachedCandidateCount = 0;
    private int $cachedCandidateBytes = 0;

    /** @var array<string,string[]> */
    private array $runCache = [];
    /** @var string[] */
    private array $runCacheOrder = [];
    private int $cachedRunTokenCount = 0;
    private int $cachedRunBytes = 0;
    /** Number of source ranges read, retained for bounded-work tests. */
    private int $indexedRangeReadCount = 0;

    private string $sourceFile;
    private string $language;
    private string $packId;
    private string $packVersion;
    private string $sourceSha256;
    private int $sourceByteSize;
    private bool $fixtureOnly;
    private int $maxCandidatesPerPrefix;
    private string $indexSignature;
    private ?string $lookupFile = null;
    /** @var resource|null */
    private $lookupHandle = null;
    /** @var resource|null */
    private $dynamicLookupHandle = null;
    private ?string $dynamicLookupHeads = null;
    private int $dynamicLookupRangeCount = 0;
    private ?Throwable $dynamicLookupFailure = null;

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
     *   max_candidates_per_prefix?:int
     * } $config
     */
    private function __construct(array $config)
    {
        WP_FTS_Analyzer_Config_Limits::assert_option_graph($config, 'Jieba segmenter configuration');
        WP_FTS_Analyzer_Config_Limits::assert_path((string) ($config['source_file'] ?? ''), 'Jieba dictionary path');
        $this->sourceFile = (string) $config['source_file'];
        $this->language = self::base_language((string) ($config['language'] ?? 'zh'));
        $this->packId = trim((string) ($config['pack_id'] ?? 'zh-jieba-dict-67fa2e36e72f'));
        $this->packVersion = trim((string) ($config['version'] ?? '67fa2e36e72f-source-v1'));
        $this->sourceSha256 = strtolower(trim((string) ($config['expected_sha256'] ?? $config['sha256'] ?? self::SOURCE_SHA256)));
        $this->sourceByteSize = (int) ($config['expected_byte_size'] ?? $config['expected_bytes'] ?? $config['byte_size'] ?? self::SOURCE_BYTE_SIZE);
        $this->fixtureOnly = (bool) ($config['fixture_only'] ?? false);
        $this->maxCandidatesPerPrefix = self::bounded_positive_int(
            $config['max_candidates_per_prefix'] ?? self::DEFAULT_MAX_CANDIDATES_PER_PREFIX,
            self::MAX_CANDIDATES_PER_PREFIX,
            'Jieba prefix-candidate limit'
        );

        if ($this->language !== 'zh') {
            throw new RuntimeException('Jieba segmenter can only be used for the zh language partition.');
        }
        if ($this->packId === '') {
            throw new RuntimeException('Jieba segmenter pack id cannot be empty.');
        }
        if ($this->packVersion === '') {
            throw new RuntimeException('Jieba segmenter version cannot be empty.');
        }
        if (strlen($this->packId) > WP_FTS_Analyzer_Config_Limits::MAX_OPTION_KEY_BYTES
            || strlen($this->packVersion) > WP_FTS_Analyzer_Config_Limits::MAX_OPTION_KEY_BYTES
        ) {
            throw new WP_FTS_Analyzer_Config_Limit_Exceeded(
                'pack_identity_bytes',
                'Jieba pack identity exceeds the 128-byte limit.'
            );
        }
        if (preg_match('/^[a-f0-9]{64}$/', $this->sourceSha256) !== 1) {
            throw new RuntimeException('Jieba dictionary SHA-256 must be a 64-character hex digest.');
        }
        if ($this->sourceByteSize < 1 || $this->sourceByteSize > self::MAX_SOURCE_FILE_BYTES) {
            throw new WP_FTS_Analyzer_Config_Limit_Exceeded(
                'source_file_bytes',
                'Jieba dictionary exceeds the 16 MiB source-file limit.'
            );
        }
        $this->lookupFile = $this->resolve_lookup_file();
        if ($this->lookupFile === null && !$this->fixtureOnly) {
            throw new RuntimeException(
                'Source-only custom Jieba dictionaries are fixture-only and production custom dictionaries are not supported.'
            );
        }
        $this->verify_source_file();
        $this->indexSignature = $this->build_index_signature($config);
    }

    /** Return the curated runtime dictionary, or its source checkout in development. */
    public static function default_source_file(): string
    {
        $runtime = dirname(__DIR__) . '/resources/runtime/jieba/dict.txt';

        return is_file($runtime)
            ? $runtime
            : dirname(__DIR__) . '/resources/sources/jieba/' . self::SOURCE_FILE;
    }

    /** Return the compact lookup shipped next to the curated runtime source. */
    public static function default_lookup_file(): string
    {
        return dirname(__DIR__) . '/resources/runtime/jieba/dict.idx';
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
     * Return attestation evidence for the pinned dictionary range index.
     *
     * @return array{path:string,sha256:string,byte_size:int,range_count:int,available:bool}
     */
    public static function default_lookup_evidence(): array
    {
        $path = self::default_lookup_file();

        return [
            'path' => $path,
            'sha256' => self::LOOKUP_SHA256,
            'byte_size' => self::LOOKUP_BYTE_SIZE,
            'range_count' => self::LOOKUP_RANGE_COUNT,
            'available' => is_file($path)
                && filesize($path) === self::LOOKUP_BYTE_SIZE
                && hash_file('sha256', $path) === self::LOOKUP_SHA256,
        ];
    }

    /**
     * Load a segmenter from the public analyzer option shape.
     */
    public static function from_pack_option(mixed $option, ?string $expectedLanguage = null): ?self
    {
        WP_FTS_Analyzer_Config_Limits::assert_pack_option($option, 'Jieba segmenter pack option');
        if ($expectedLanguage !== null && strlen($expectedLanguage) > WP_FTS_Analyzer_Config_Limits::MAX_LANGUAGE_BYTES) {
            throw new WP_FTS_Analyzer_Config_Limit_Exceeded(
                'language_bytes',
                'Expected Jieba language exceeds the 64-byte limit.'
            );
        }
        $config = self::source_config_from_option($option);
        if ($config === null) {
            return null;
        }

        if ($expectedLanguage !== null && trim($expectedLanguage) !== '') {
            $config['language'] = self::base_language($expectedLanguage);
        }

        try {
            return new self($config);
        } catch (WP_FTS_Analyzer_Config_Limit_Exceeded $error) {
            throw $error;
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

        // LanguagePipeline enforces this before invoking any extension. Keep
        // the public segmenter safe when it is called directly as well: both
        // UTF-8 character materialization and candidate-by-offset matching are
        // otherwise proportional to an unchecked complete run.
        WP_FTS_Analysis_Limits::assert_lexical_run_bytes(strlen($run));
        if (array_key_exists($run, $this->runCache)) {
            $this->touch_run($run);
            return $this->runCache[$run];
        }
        $chars = $this->utf8_chars($run);
        if ($chars === []) {
            return [];
        }
        if (count($chars) === 1) {
            $this->store_run_tokens($run, $chars);
            return $chars;
        }
        $preloaded = $this->preload_prefixes($chars);
        $activePrefixes = $preloaded['active'];
        $runLookup = $preloaded['lookup'];

        $tokens = [];
        $seen = [];
        foreach ($this->dictionary_segments($chars, $activePrefixes, $runLookup['matches'] ?? null) as $segment) {
            foreach ($this->search_subsegments($segment, $activePrefixes, $runLookup['words'] ?? null) as $subsegment) {
                $this->append_unique($tokens, $seen, $subsegment);
            }
            $this->append_unique($tokens, $seen, $segment);
        }
        foreach ($this->fallback_cjk_tokens($chars) as $token) {
            $this->append_unique($tokens, $seen, $token);
        }

        $this->store_run_tokens($run, $tokens);

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

        // Every indexed range carries a digest anchored by the attested lookup
        // file, so the immutable bundled source is verified lazily as ranges
        // are read. Custom paths keep the eager complete hash contract.
        if ($this->lookupFile !== null) {
            return;
        }

        $this->sourceHashScanCount++;
        $hash = hash_file('sha256', $this->sourceFile);
        if (!is_string($hash) || strtolower($hash) !== $this->sourceSha256) {
            throw new RuntimeException('Jieba dictionary SHA-256 mismatch.');
        }
    }

    /**
     * Use the committed range index only for the exact pinned source bytes.
     *
     * The index is deliberately not trusted from path or filename. Its own
     * digest and header bind every byte range to the declared source digest and
     * size. Per-range digests verify bytes lazily when they are read. A custom
     * path therefore keeps eager source hashing and generated indexed lookup.
     */
    private function resolve_lookup_file(): ?string
    {
        if ($this->sourceSha256 !== self::SOURCE_SHA256
            || $this->sourceByteSize !== self::SOURCE_BYTE_SIZE
            || !$this->is_curated_source_path()
        ) {
            return null;
        }

        $path = self::default_lookup_file();
        if (!$this->lookup_file_is_attested($path)) {
            throw new RuntimeException('The curated Jieba dictionary requires its attested range index.');
        }

        return $path;
    }

    private function lookup_file_is_attested(string $path): bool
    {
        if (!is_file($path)
            || filesize($path) !== self::LOOKUP_BYTE_SIZE
            || hash_file('sha256', $path) !== self::LOOKUP_SHA256
        ) {
            return false;
        }

        $header = file_get_contents($path, false, null, 0, self::LOOKUP_HEADER_BYTES);
        if (!is_string($header) || strlen($header) !== self::LOOKUP_HEADER_BYTES) {
            return false;
        }
        $counts = unpack('Nsource_size/Nrange_count', substr($header, 40, 8));
        if (substr($header, 0, 8) !== self::LOOKUP_MAGIC
            || !hash_equals(hex2bin(self::SOURCE_SHA256), substr($header, 8, 32))
            || (int) ($counts['source_size'] ?? 0) !== self::SOURCE_BYTE_SIZE
            || (int) ($counts['range_count'] ?? 0) !== self::LOOKUP_RANGE_COUNT
        ) {
            return false;
        }

        return true;
    }

    private function is_curated_source_path(): bool
    {
        $source = realpath($this->sourceFile);
        if (!is_string($source)) {
            return false;
        }
        $runtime = realpath(dirname(__DIR__) . '/resources/runtime/jieba/dict.txt');
        $checkout = realpath(dirname(__DIR__) . '/resources/sources/jieba/' . self::SOURCE_FILE);

        return (is_string($runtime) && $source === $runtime)
            || (is_string($checkout) && $source === $checkout);
    }

    public function __destruct()
    {
        if (is_resource($this->lookupHandle)) {
            fclose($this->lookupHandle);
        }
        if (is_resource($this->dynamicLookupHandle)) {
            fclose($this->dynamicLookupHandle);
        }
    }

    /**
     * @param string[] $chars
     * @param array<string,bool> $activePrefixes
     * @param array<int,array{word:string,length:int}>|null $runMatches
     * @return string[]
     */
    private function dictionary_segments(array $chars, array $activePrefixes, ?array $runMatches = null): array
    {
        $segments = [];
        $count = count($chars);
        for ($offset = 0; $offset < $count;) {
            $match = $runMatches[$offset] ?? $this->longest_dictionary_match($chars, $offset, $activePrefixes);
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
     * @param array<string,bool> $activePrefixes
     * @return array{word:string,length:int}|null
     */
    private function longest_dictionary_match(array $chars, int $offset, array $activePrefixes): ?array
    {
        $first = $chars[$offset] ?? '';
        if ($first === '') {
            return null;
        }

        $remainingLength = count($chars) - $offset;
        if ($remainingLength < 2) {
            return null;
        }
        $prefix = $this->cached_prefix($first, $activePrefixes);
        if ($prefix['lengths'] === []) {
            return null;
        }
        foreach ($prefix['lengths'] as $length => $_available) {
            if ($length > $remainingLength) {
                continue;
            }
            $word = implode('', array_slice($chars, $offset, $length));
            if (isset($prefix['words'][$word])) {
                return [
                    'word' => $word,
                    'length' => $length,
                ];
            }
        }

        return null;
    }

    /**
     * Return short dictionary-backed subsegments similar to Jieba search mode.
     *
     * @param array<string,bool> $activePrefixes
     * @param array<string,bool>|null $runWords
     * @return string[]
     */
    private function search_subsegments(string $segment, array $activePrefixes, ?array $runWords = null): array
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
                $contained = isset($runWords[$candidate])
                    || $this->dictionary_contains($candidate, $activePrefixes);
                if (isset($seen[$candidate]) || !$contained) {
                    continue;
                }

                $seen[$candidate] = true;
                $subsegments[] = $candidate;
            }
        }

        return $subsegments;
    }

    /** @param array<string,bool> $activePrefixes */
    private function dictionary_contains(string $word, array $activePrefixes): bool
    {
        $chars = $this->utf8_chars($word);
        $first = $chars[0] ?? '';
        if ($first === '') {
            return false;
        }

        return isset($this->cached_prefix($first, $activePrefixes)['words'][$word]);
    }

    /**
     * Read only complete prefixes selected for this run.
     *
     * A run may contain every distinct Han codepoint that fits in the 4-KiB
     * lexical envelope. Indexed ranges avoid a complete dictionary pass, while
     * the compact cache makes each populated pinned prefix a once-per-instance
     * read rather than an LRU entry that an adversarial rotation can evict.
     *
     * @param array<string,bool> $activePrefixes
     * @return array{words:array<string,bool>,lengths:array<int,bool>,candidate_count:int,candidate_bytes:int}
     */
    private function cached_prefix(string $first, array $activePrefixes): array
    {
        if (!isset($activePrefixes[$first], $this->prefixCache[$first])) {
            return [
                'words' => [],
                'lengths' => [],
                'candidate_count' => 0,
                'candidate_bytes' => 0,
            ];
        }

        return $this->prefixCache[$first];
    }

    /**
     * Match one run without retaining uncontrolled dictionary fanout.
     *
     * Indexed ranges record only the longest match at each source offset plus
     * the two- and three-character words needed for search-mode output. The
     * pinned source identity or fixture admission guarantees that the complete
     * compact prefix maps fit the retained-cache bounds. Every requested map is
     * installed completely; there is no partial-cache or eviction branch.
     *
     * @param string[] $chars
     * @param array<string,bool> $cachePrefixes Missing prefixes to retain.
     * @return array{matches:array<int,array{word:string,length:int}>,words:array<string,bool>}
     */
    private function scan_run(array $chars, array $cachePrefixes): array
    {
        $prefixOffsets = [];
        $byteOffsets = [];
        $run = '';
        foreach ($chars as $offset => $char) {
            $byteOffsets[$offset] = strlen($run);
            $run .= $char;
            if (isset($cachePrefixes[$char])) {
                $prefixOffsets[$char][] = $offset;
            }
        }

        $matches = [];
        $words = [];
        $prefixCandidateCounts = [];
        $retainedCandidates = [];
        foreach ($cachePrefixes as $prefix => $_enabled) {
            $retainedCandidates[(string) $prefix] = [
                'words' => [],
                'lengths' => [],
                'candidate_count' => 0,
                'candidate_bytes' => 0,
            ];
        }
        $retainedCandidateCount = 0;
        $retainedCandidateBytes = 0;
        foreach ($this->dictionary_lines_for_prefixes(array_keys($prefixOffsets)) as $line) {
            $row = $this->parse_dict_line((string) $line);
            if ($row === null) {
                continue;
            }

            $word = $row['word'];
            $first = $this->first_utf8_character($word);
            if ($first === '' || !isset($prefixOffsets[$first])) {
                continue;
            }
            $wordChars = $this->utf8_chars($word);
            $wordLength = count($wordChars);
            if ($wordLength < 2 || !$this->contains_han($word)) {
                continue;
            }

            $prefixCandidateCounts[$first] = ($prefixCandidateCounts[$first] ?? 0) + 1;
            if ($prefixCandidateCounts[$first] > $this->maxCandidatesPerPrefix) {
                throw new WP_FTS_Analysis_Limit_Exceeded(
                    'jieba_dictionary_candidates',
                    'A Jieba dictionary prefix exceeds the 5,000-candidate allowance.'
                );
            }

            if (isset($retainedCandidates[$first])) {
                $wordBytes = strlen($word);
                $retainedCandidates[$first]['words'][$word] = true;
                $retainedCandidates[$first]['lengths'][$wordLength] = true;
                $retainedCandidates[$first]['candidate_count']++;
                $retainedCandidates[$first]['candidate_bytes'] += $wordBytes;
                $retainedCandidateCount++;
                $retainedCandidateBytes += $wordBytes;
            }

            foreach ($prefixOffsets[$first] as $offset) {
                if ($wordLength > count($chars) - $offset) {
                    continue;
                }
                $byteOffset = $byteOffsets[$offset];
                if (substr_compare($run, $word, $byteOffset, strlen($word)) !== 0) {
                    continue;
                }

                if ($wordLength <= 3) {
                    $words[$word] = true;
                }
                $current = $matches[$offset] ?? null;
                if ($current === null || $this->dictionary_entry_precedes([
                    'word' => $word,
                    'frequency' => $row['frequency'],
                    'length' => $wordLength,
                ], $current)) {
                    $matches[$offset] = [
                        'word' => $word,
                        'frequency' => $row['frequency'],
                        'length' => $wordLength,
                    ];
                }
            }
        }

        $this->store_prefix_candidates(
            $retainedCandidates,
            $retainedCandidateCount,
            $retainedCandidateBytes
        );

        foreach ($matches as $offset => $match) {
            $matches[$offset] = [
                'word' => $match['word'],
                'length' => $match['length'],
            ];
        }

        return ['matches' => $matches, 'words' => $words];
    }

    /**
     * Stream dictionary rows for the requested first characters.
     *
     * The attested index turns a pinned-source lookup into a handful of byte
     * ranges. Fixture dictionaries have no trusted offsets and build their
     * source-local index in one complete scan. `dictionaryScanCount`
     * intentionally counts only those complete scans, which makes the
     * non-rescan invariant observable.
     *
     * @param string[] $prefixes
     * @return iterable<int,string>
     */
    private function dictionary_lines_for_prefixes(array $prefixes): iterable
    {
        $ranges = $this->indexed_ranges_for_prefixes($prefixes);
        if ($ranges === []) {
            return;
        }

        $handle = fopen($this->sourceFile, 'rb');
        if (!is_resource($handle)) {
            throw new RuntimeException('Could not open the Jieba dictionary for indexed lookup.');
        }
        try {
            foreach ($ranges as $range) {
                $this->indexedRangeReadCount++;
                if (fseek($handle, $range['offset']) !== 0) {
                    throw new RuntimeException('Could not seek an indexed Jieba dictionary range.');
                }
                if (isset($range['digest'])) {
                    $bytes = '';
                    while (strlen($bytes) < $range['length']) {
                        $chunk = fread($handle, $range['length'] - strlen($bytes));
                        if (!is_string($chunk) || $chunk === '') {
                            throw new RuntimeException('Could not read an attested Jieba dictionary range.');
                        }
                        $bytes .= $chunk;
                    }
                    $digest = substr(hash('sha256', $bytes, true), 0, 16);
                    if (!hash_equals($range['digest'], $digest)) {
                        throw new RuntimeException('Jieba dictionary range digest mismatch.');
                    }
                    yield from $this->dictionary_lines_from_buffer($bytes);
                    continue;
                }

                $end = $range['offset'] + $range['length'];
                while (ftell($handle) < $end) {
                    $line = $this->read_dictionary_line($handle);
                    if ($line === false) {
                        throw new RuntimeException('Could not read an indexed Jieba dictionary range.');
                    }
                    yield $line;
                }
            }
        } finally {
            fclose($handle);
        }
    }

    /** @return iterable<int,string> */
    private function dictionary_lines_from_buffer(string $buffer): iterable
    {
        $offset = 0;
        $length = strlen($buffer);
        while ($offset < $length) {
            $newline = strpos($buffer, "\n", $offset);
            $end = $newline === false ? $length : $newline + 1;
            $line = substr($buffer, $offset, $end - $offset);
            $offset = $end;

            $payloadBytes = strlen($line);
            if ($payloadBytes > 0 && $line[$payloadBytes - 1] === "\n") {
                $payloadBytes--;
                if ($payloadBytes > 0 && $line[$payloadBytes - 1] === "\r") {
                    $payloadBytes--;
                }
            }
            if ($payloadBytes > self::MAX_DICTIONARY_LINE_BYTES) {
                $this->throw_dictionary_line_limit();
            }
            yield $line;
        }
    }

    /**
     * @param string[] $prefixes
     * @return array<int,array{offset:int,length:int,digest?:string}>
     */
    private function indexed_ranges_for_prefixes(array $prefixes): array
    {
        if ($this->lookupFile === null) {
            $this->ensure_dynamic_lookup();

            $ranges = [];
            $seen = [];
            foreach ($prefixes as $prefix) {
                $codepoint = $this->utf8_codepoint((string) $prefix);
                if ($codepoint === null || isset($seen[$codepoint])) {
                    continue;
                }
                $seen[$codepoint] = true;
                foreach ($this->dynamic_ranges_for_codepoint($codepoint) as $range) {
                    $ranges[] = $range;
                }
            }
            usort(
                $ranges,
                static fn(array $a, array $b): int => $a['offset'] <=> $b['offset']
            );

            return $ranges;
        }
        if (!is_resource($this->lookupHandle)) {
            $this->lookupHandle = fopen($this->lookupFile, 'rb');
            if (!is_resource($this->lookupHandle)) {
                throw new RuntimeException('Could not open the attested Jieba range index.');
            }
        }

        $ranges = [];
        $seen = [];
        foreach ($prefixes as $prefix) {
            $codepoint = $this->utf8_codepoint((string) $prefix);
            if ($codepoint === null || isset($seen[$codepoint])) {
                continue;
            }
            $seen[$codepoint] = true;
            $prefixRanges = $this->lookup_ranges_for_codepoint($codepoint);
            if ($prefixRanges === null) {
                throw new RuntimeException('Could not read the attested Jieba range index.');
            }
            foreach ($prefixRanges as $range) {
                $ranges[] = $range;
            }
        }
        usort(
            $ranges,
            static fn(array $a, array $b): int => $a['offset'] <=> $b['offset']
        );

        return $ranges;
    }

    /**
     * Build a compact source-local range index once for a fixture dictionary.
     *
     * A 4.25 MiB packed head table and transient 2.13 MiB prefix-count table
     * cover the complete Unicode range without PHP arrays per codepoint.
     * Twelve-byte range records spill to the system temporary stream above
     * 1 MiB. During the same scan, complete-cache admission rejects a source
     * above 350,000 eligible rows, 8 MiB of word bytes, or the configured
     * per-prefix bound. Failure is permanent for the instance, so neither
     * accepted nor rejected fixtures can trigger repeated complete scans or
     * alternating-prefix cache thrash.
     */
    private function ensure_dynamic_lookup(): void
    {
        if ($this->dynamicLookupHeads !== null && is_resource($this->dynamicLookupHandle)) {
            return;
        }
        if ($this->dynamicLookupFailure !== null) {
            throw $this->dynamicLookupFailure;
        }

        $source = fopen($this->sourceFile, 'rb');
        $index = fopen('php://temp/maxmemory:1048576', 'w+b');
        if (!is_resource($source) || !is_resource($index)) {
            if (is_resource($source)) {
                fclose($source);
            }
            if (is_resource($index)) {
                fclose($index);
            }
            throw new RuntimeException('Could not create the Jieba custom-dictionary range index.');
        }

        $heads = str_repeat("\xFF", self::UNICODE_CODEPOINT_COUNT * 4);
        $prefixCandidateCounts = str_repeat("\0", self::UNICODE_CODEPOINT_COUNT * 2);
        $rangeCount = 0;
        $rangeCodepoint = null;
        $rangeOffset = 0;
        $rangeEnd = 0;
        $candidateCount = 0;
        $candidateBytes = 0;
        $this->dictionaryScanCount++;
        try {
            while (true) {
                $lineOffset = ftell($source);
                $line = $this->read_dictionary_line($source);
                if ($line === false) {
                    break;
                }
                $lineEnd = ftell($source);
                $row = $this->parse_dict_line($line);
                $first = $row === null ? '' : $this->first_utf8_character($row['word']);
                $codepoint = $this->utf8_codepoint($first);
                if ($row === null || $codepoint === null || $codepoint >= self::UNICODE_CODEPOINT_COUNT) {
                    if ($rangeCodepoint !== null) {
                        $this->append_dynamic_range(
                            $index,
                            $heads,
                            $rangeCount,
                            $rangeCodepoint,
                            $rangeOffset,
                            $rangeEnd - $rangeOffset
                        );
                        $rangeCodepoint = null;
                    }
                    continue;
                }

                $word = $row['word'];
                if (strlen($word) > strlen($first) && $this->contains_han($word)) {
                    $candidateCount++;
                    $candidateBytes += strlen($word);
                    $countOffset = $codepoint * 2;
                    $packedCount = unpack('nvalue', substr($prefixCandidateCounts, $countOffset, 2));
                    $prefixCandidateCount = (int) ($packedCount['value'] ?? 0) + 1;
                    $encodedCount = pack('n', $prefixCandidateCount);
                    $prefixCandidateCounts[$countOffset] = $encodedCount[0];
                    $prefixCandidateCounts[$countOffset + 1] = $encodedCount[1];
                    if ($candidateCount > self::MAX_RETAINED_DICTIONARY_CANDIDATES
                        || $candidateBytes > self::MAX_RETAINED_DICTIONARY_CANDIDATE_BYTES
                        || $prefixCandidateCount > $this->maxCandidatesPerPrefix
                    ) {
                        throw new WP_FTS_Analysis_Limit_Exceeded(
                            'jieba_dictionary_candidates',
                            'Custom Jieba dictionaries must fit the complete 350,000-row, 8-MiB, and configured per-prefix cache admission.'
                        );
                    }
                }

                if ($rangeCodepoint !== $codepoint) {
                    if ($rangeCodepoint !== null) {
                        $this->append_dynamic_range(
                            $index,
                            $heads,
                            $rangeCount,
                            $rangeCodepoint,
                            $rangeOffset,
                            $rangeEnd - $rangeOffset
                        );
                    }
                    $rangeCodepoint = $codepoint;
                    $rangeOffset = (int) $lineOffset;
                }
                $rangeEnd = (int) $lineEnd;
            }
            if ($rangeCodepoint !== null) {
                $this->append_dynamic_range(
                    $index,
                    $heads,
                    $rangeCount,
                    $rangeCodepoint,
                    $rangeOffset,
                    $rangeEnd - $rangeOffset
                );
            }
        } catch (Throwable $error) {
            $this->dynamicLookupFailure = $error;
            fclose($index);
            throw $error;
        } finally {
            fclose($source);
        }

        $this->dynamicLookupHandle = $index;
        $this->dynamicLookupHeads = $heads;
        $this->dynamicLookupRangeCount = $rangeCount;
    }

    /**
     * @param resource $index
     */
    private function append_dynamic_range(
        $index,
        string &$heads,
        int &$rangeCount,
        int $codepoint,
        int $offset,
        int $length
    ): void {
        $headOffset = $codepoint * 4;
        $previous = unpack('Nvalue', substr($heads, $headOffset, 4));
        $previousRange = (int) ($previous['value'] ?? self::NO_DYNAMIC_RANGE);
        $record = pack('NNN', $offset, $length, $previousRange);
        if (fwrite($index, $record) !== self::DYNAMIC_LOOKUP_RECORD_BYTES) {
            throw new RuntimeException('Could not build the Jieba custom-dictionary range index.');
        }
        $head = pack('N', $rangeCount);
        for ($byte = 0; $byte < 4; $byte++) {
            $heads[$headOffset + $byte] = $head[$byte];
        }
        $rangeCount++;
    }

    /** @return array<int,array{offset:int,length:int}> */
    private function dynamic_ranges_for_codepoint(int $codepoint): array
    {
        if ($this->dynamicLookupHeads === null || !is_resource($this->dynamicLookupHandle)) {
            return [];
        }
        $headOffset = $codepoint * 4;
        $head = unpack('Nvalue', substr($this->dynamicLookupHeads, $headOffset, 4));
        $recordNumber = (int) ($head['value'] ?? self::NO_DYNAMIC_RANGE);
        $ranges = [];
        while ($recordNumber !== self::NO_DYNAMIC_RANGE) {
            if ($recordNumber < 0 || $recordNumber >= $this->dynamicLookupRangeCount) {
                throw new RuntimeException('Jieba custom-dictionary range index is corrupt.');
            }
            if (fseek($this->dynamicLookupHandle, $recordNumber * self::DYNAMIC_LOOKUP_RECORD_BYTES) !== 0) {
                throw new RuntimeException('Could not seek the Jieba custom-dictionary range index.');
            }
            $bytes = fread($this->dynamicLookupHandle, self::DYNAMIC_LOOKUP_RECORD_BYTES);
            $record = is_string($bytes) && strlen($bytes) === self::DYNAMIC_LOOKUP_RECORD_BYTES
                ? unpack('Noffset/Nlength/Nnext', $bytes)
                : false;
            if (!is_array($record)) {
                throw new RuntimeException('Could not read the Jieba custom-dictionary range index.');
            }
            $ranges[] = [
                'offset' => (int) $record['offset'],
                'length' => (int) $record['length'],
            ];
            $recordNumber = (int) $record['next'];
        }

        return $ranges;
    }

    /**
     * @return array<int,array{offset:int,length:int,digest:string}>|null
     */
    private function lookup_ranges_for_codepoint(int $codepoint): ?array
    {
        $low = 0;
        $high = self::LOOKUP_RANGE_COUNT;
        while ($low < $high) {
            $middle = intdiv($low + $high, 2);
            $record = $this->read_lookup_record($middle);
            if ($record === null) {
                return null;
            }
            if ($record['codepoint'] < $codepoint) {
                $low = $middle + 1;
            } else {
                $high = $middle;
            }
        }

        $ranges = [];
        for ($recordNumber = $low; $recordNumber < self::LOOKUP_RANGE_COUNT; $recordNumber++) {
            $record = $this->read_lookup_record($recordNumber);
            if ($record === null) {
                return null;
            }
            if ($record['codepoint'] !== $codepoint) {
                break;
            }
            $ranges[] = [
                'offset' => $record['offset'],
                'length' => $record['length'],
                'digest' => $record['digest'],
            ];
        }

        return $ranges;
    }

    /** @return array{codepoint:int,offset:int,length:int,digest:string}|null */
    private function read_lookup_record(int $recordNumber): ?array
    {
        if (!is_resource($this->lookupHandle)) {
            return null;
        }
        $offset = self::LOOKUP_HEADER_BYTES + ($recordNumber * self::LOOKUP_RECORD_BYTES);
        if (fseek($this->lookupHandle, $offset) !== 0) {
            return null;
        }
        $bytes = fread($this->lookupHandle, self::LOOKUP_RECORD_BYTES);
        if (!is_string($bytes) || strlen($bytes) !== self::LOOKUP_RECORD_BYTES) {
            return null;
        }
        $record = unpack('Ncodepoint/Noffset/Nlength', substr($bytes, 0, 12));
        if (!is_array($record)) {
            return null;
        }

        return [
            'codepoint' => (int) $record['codepoint'],
            'offset' => (int) $record['offset'],
            'length' => (int) $record['length'],
            'digest' => substr($bytes, 12, 16),
        ];
    }

    private function utf8_codepoint(string $character): ?int
    {
        if ($character === '') {
            return null;
        }
        $first = ord($character[0]);
        if ($first <= 0x7F) {
            return $first;
        }
        if (($first & 0xE0) === 0xC0 && strlen($character) >= 2) {
            return (($first & 0x1F) << 6) | (ord($character[1]) & 0x3F);
        }
        if (($first & 0xF0) === 0xE0 && strlen($character) >= 3) {
            return (($first & 0x0F) << 12)
                | ((ord($character[1]) & 0x3F) << 6)
                | (ord($character[2]) & 0x3F);
        }
        if (($first & 0xF8) === 0xF0 && strlen($character) >= 4) {
            return (($first & 0x07) << 18)
                | ((ord($character[1]) & 0x3F) << 12)
                | ((ord($character[2]) & 0x3F) << 6)
                | (ord($character[3]) & 0x3F);
        }

        return null;
    }

    /**
     * @param array{word:string,frequency:int,length:int} $candidate
     * @param array{word:string,frequency:int,length:int} $current
     */
    private function dictionary_entry_precedes(array $candidate, array $current): bool
    {
        return $candidate['length'] > $current['length']
            || (
                $candidate['length'] === $current['length']
                && (
                    $candidate['frequency'] > $current['frequency']
                    || (
                        $candidate['frequency'] === $current['frequency']
                        && strcmp($candidate['word'], $current['word']) < 0
                    )
                )
            );
    }

    /**
     * Fetch every first-character range needed by one bounded lexical run.
     *
     * @param string[] $chars
     * @return array{
     *   active:array<string,bool>,
     *   lookup:?array{matches:array<int,array{word:string,length:int}>,words:array<string,bool>}
     * }
     */
    private function preload_prefixes(array $chars): array
    {
        $active = [];
        $needed = [];
        foreach ($chars as $char) {
            if ($char === '' || isset($active[$char])) {
                continue;
            }
            $active[$char] = true;
            if ($this->prefix_is_loaded($char)) {
                continue;
            }
            $needed[$char] = true;
        }
        if ($needed === []) {
            return ['active' => $active, 'lookup' => null];
        }

        return [
            'active' => $active,
            'lookup' => $this->scan_run($chars, $needed),
        ];
    }

    /**
     * @param array<string,array{words:array<string,bool>,lengths:array<int,bool>,candidate_count:int,candidate_bytes:int}> $candidates
     */
    private function store_prefix_candidates(array $candidates, int $candidateCount, int $candidateBytes): void
    {
        if ($this->cachedCandidateCount + $candidateCount > self::MAX_RETAINED_DICTIONARY_CANDIDATES
            || $this->cachedCandidateBytes + $candidateBytes > self::MAX_RETAINED_DICTIONARY_CANDIDATE_BYTES
        ) {
            throw new RuntimeException('The admitted Jieba dictionary exceeds its complete-cache invariant.');
        }

        foreach ($candidates as $prefix => $data) {
            if ($data['candidate_count'] !== 0) {
                krsort($data['lengths'], SORT_NUMERIC);
                $this->prefixCache[$prefix] = $data;
            }
            $this->mark_prefix_loaded((string) $prefix);
        }
        $this->cachedCandidateCount += $candidateCount;
        $this->cachedCandidateBytes += $candidateBytes;
    }

    private function prefix_is_loaded(string $prefix): bool
    {
        if ($this->loadedPrefixBits === null) {
            return false;
        }
        $codepoint = $this->utf8_codepoint($prefix);
        if ($codepoint === null) {
            return false;
        }
        $byte = intdiv($codepoint, 8);
        $bit = 1 << ($codepoint % 8);

        return (ord($this->loadedPrefixBits[$byte]) & $bit) !== 0;
    }

    private function mark_prefix_loaded(string $prefix): void
    {
        $codepoint = $this->utf8_codepoint($prefix);
        if ($codepoint === null) {
            return;
        }
        if ($this->loadedPrefixBits === null) {
            $this->loadedPrefixBits = str_repeat("\0", self::LOADED_PREFIX_BYTES);
        }
        $byte = intdiv($codepoint, 8);
        $bit = 1 << ($codepoint % 8);
        $this->loadedPrefixBits[$byte] = chr(ord($this->loadedPrefixBits[$byte]) | $bit);
    }

    private function first_utf8_character(string $text): string
    {
        if ($text === '') {
            return '';
        }

        $byte = ord($text[0]);
        $length = 1;
        if (($byte & 0xE0) === 0xC0) {
            $length = 2;
        } elseif (($byte & 0xF0) === 0xE0) {
            $length = 3;
        } elseif (($byte & 0xF8) === 0xF0) {
            $length = 4;
        }

        return substr($text, 0, $length);
    }

    /**
     * Read one bounded dictionary row without materializing an oversized line.
     *
     * @param resource $handle
     */
    private function read_dictionary_line($handle): string|false
    {
        // fgets() reads at most length - 1 bytes. Two extra bytes admit both
        // LF and CRLF after an exact-boundary payload while exposing byte 8,193.
        $line = fgets($handle, self::MAX_DICTIONARY_LINE_BYTES + 3);
        if ($line === false) {
            return false;
        }

        $payloadBytes = strlen($line);
        if ($payloadBytes > 0 && $line[$payloadBytes - 1] === "\n") {
            $payloadBytes--;
            if ($payloadBytes > 0 && $line[$payloadBytes - 1] === "\r") {
                $payloadBytes--;
            }
        } elseif (!feof($handle)) {
            $this->throw_dictionary_line_limit();
        }

        if ($payloadBytes > self::MAX_DICTIONARY_LINE_BYTES) {
            $this->throw_dictionary_line_limit();
        }

        return $line;
    }

    private function throw_dictionary_line_limit(): never
    {
        throw new WP_FTS_Analysis_Limit_Exceeded(
            'jieba_dictionary_line_bytes',
            'Jieba dictionary rows may contain at most 8 KiB.'
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

    /** @param string[] $tokens */
    private function store_run_tokens(string $run, array $tokens): void
    {
        $tokenBytes = strlen($run);
        foreach ($tokens as $token) {
            $tokenBytes += strlen($token);
        }
        $tokenCount = count($tokens);
        if ($tokenCount > self::MAX_RETAINED_RUN_TOKENS || $tokenBytes > self::MAX_RETAINED_RUN_BYTES) {
            return;
        }

        while ($this->runCacheOrder !== [] && (
            count($this->runCacheOrder) >= self::MAX_RETAINED_RUNS
            || $this->cachedRunTokenCount + $tokenCount > self::MAX_RETAINED_RUN_TOKENS
            || $this->cachedRunBytes + $tokenBytes > self::MAX_RETAINED_RUN_BYTES
        )) {
            $this->evict_oldest_run();
        }
        $this->runCache[$run] = $tokens;
        $this->runCacheOrder[] = $run;
        $this->cachedRunTokenCount += $tokenCount;
        $this->cachedRunBytes += $tokenBytes;
    }

    private function touch_run(string $run): void
    {
        $this->runCacheOrder = array_values(array_filter(
            $this->runCacheOrder,
            static fn(string $cached): bool => $cached !== $run
        ));
        $this->runCacheOrder[] = $run;
    }

    private function evict_oldest_run(): void
    {
        $oldest = array_shift($this->runCacheOrder);
        if (!is_string($oldest) || !isset($this->runCache[$oldest])) {
            return;
        }
        $tokens = $this->runCache[$oldest];
        $this->cachedRunTokenCount -= count($tokens);
        $this->cachedRunBytes -= strlen($oldest);
        foreach ($tokens as $token) {
            $this->cachedRunBytes -= strlen($token);
        }
        unset($this->runCache[$oldest]);
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
        WP_FTS_Analyzer_Config_Limits::assert_path($path, 'Jieba dictionary path');
        if ($path === '') {
            return null;
        }
        if (is_dir($path)) {
            return rtrim($path, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . self::SOURCE_FILE;
        }

        return $path;
    }

    private static function bounded_positive_int(mixed $value, int $maximum, string $label): int
    {
        if (!is_int($value) && (!is_string($value) || preg_match('/^[1-9][0-9]*$/', $value) !== 1)) {
            throw new InvalidArgumentException("{$label} must be a positive integer.");
        }
        $value = (int) $value;
        if ($value < 1 || $value > $maximum) {
            throw new WP_FTS_Analyzer_Config_Limit_Exceeded(
                'segmenter_numeric_limit',
                "{$label} exceeds its hard maximum of {$maximum}."
            );
        }

        return $value;
    }

    private static function base_language(string $language): string
    {
        $canonical = WP_FTS_TermNamespace::canonicalize_lang($language);
        $parts = explode('-', str_replace('_', '-', $canonical));

        return strtolower((string) ($parts[0] ?? $canonical));
    }
}
