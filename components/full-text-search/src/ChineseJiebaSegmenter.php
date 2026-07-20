<?php
declare(strict_types=1);

/**
 * Optional Chinese segmenter backed by the pinned Jieba dictionary.
 *
 * The dictionary requires an attested, compact first-codepoint range index and
 * verifies each source range when it is read. Ordinary lookups therefore avoid
 * rescanning the 5 MiB dictionary.
 */
final class WP_FTS_ChineseJiebaSegmenter
{
    // A dictionary word may use the complete 4-KiB lexical-run allowance;
    // another 4 KiB leaves room for its frequency and tag without permitting
    // one valid dictionary row to allocate the complete 16-MiB source.
    public const MAX_DICTIONARY_LINE_BYTES = 8192;

    // The public segmenter definition admits 337,461 pinned rows and 3,013,799
    // bytes of candidate words; LanguagePipeline can reach the 337,399 rows and
    // 3,013,489 bytes whose first codepoint is Han. Compact word-membership and
    // length maps therefore keep every populated pinned prefix resident after
    // its first indexed read. There is no eviction/re-read path.
    public const MAX_RETAINED_DICTIONARY_CANDIDATES = 350000;
    public const MAX_RETAINED_DICTIONARY_CANDIDATE_BYTES = 8388608;

    private const FALLBACK_MAX_NGRAM_LENGTH = 4;
    private const MAX_CANDIDATES_PER_PREFIX = 5000;
    private const MAX_SOURCE_FILE_BYTES = 16777216;
    private const LOOKUP_MAGIC = "WPFTSJ2\0";
    private const LOOKUP_HEADER_BYTES = 48;
    private const LOOKUP_RECORD_BYTES = 28;
    private const LOADED_PREFIX_BYTES = 139264;
    private const MAX_RETAINED_RUNS = 256;
    private const MAX_RETAINED_RUN_TOKENS = 4096;
    private const MAX_RETAINED_RUN_BYTES = 262144;
    private const RUNTIME_MANIFEST_SCHEMA = 'wp-fts-jieba-runtime-v1';
    private const MAX_RUNTIME_MANIFEST_BYTES = 8192;
    private const LANGUAGE = 'zh';
    private const PACK_ID = 'zh-jieba-dict-67fa2e36e72f';
    private const PACK_VERSION = '67fa2e36e72f-source-v1';

    /** @var array<string,mixed>|null */
    private static ?array $runtimeManifest = null;

    /** @var array<string,array{words:array<string,bool>,lengths:array<int,bool>,candidate_count:int,candidate_bytes:int}> */
    private array $prefixCache = [];
    private ?string $loadedPrefixBits = null;

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
    private string $indexSignature;
    private string $lookupFile;
    /** @var resource|null */
    private $lookupHandle = null;

    private function __construct()
    {
        $manifest = self::runtime_manifest();
        $dictionary = $manifest['artifacts']['dictionary'];
        $this->sourceFile = self::default_source_file();
        $this->lookupFile = self::default_lookup_file();
        if (!$this->lookup_file_is_attested($this->lookupFile)) {
            throw new RuntimeException('The curated Jieba dictionary requires its attested range index.');
        }
        if (!is_file($this->sourceFile)) {
            throw new RuntimeException('Jieba dictionary source file is missing.');
        }
        if (filesize($this->sourceFile) !== $dictionary['bytes']) {
            throw new RuntimeException('Jieba dictionary byte size mismatch.');
        }
        $this->indexSignature = $this->build_index_signature();
    }

    /** Return the manifest that owns the bundled source and runtime artifact identities. */
    public static function runtime_manifest_path(): string
    {
        return dirname(__DIR__) . '/resources/runtime/jieba/manifest.json';
    }

    /** @return array<string,mixed> */
    public static function runtime_manifest(): array
    {
        if (self::$runtimeManifest !== null) {
            return self::$runtimeManifest;
        }

        $json = file_get_contents(self::runtime_manifest_path(), false, null, 0, self::MAX_RUNTIME_MANIFEST_BYTES + 1);
        if (!is_string($json) || $json === '' || strlen($json) > self::MAX_RUNTIME_MANIFEST_BYTES) {
            throw new RuntimeException('Jieba runtime manifest is missing or oversized.');
        }
        try {
            $manifest = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
        } catch (Throwable $error) {
            throw new RuntimeException('Jieba runtime manifest is not valid JSON.', 0, $error);
        }
        $manifest = self::validate_runtime_manifest($manifest);

        self::$runtimeManifest = $manifest;

        return $manifest;
    }

    /** Validate the manifest contract shared by runtime and release packaging. */
    public static function validate_runtime_manifest(mixed $manifest): array
    {
        if (!is_array($manifest)
            || !self::has_exact_keys($manifest, ['schema', 'upstream', 'artifacts'])
            || ($manifest['schema'] ?? null) !== self::RUNTIME_MANIFEST_SCHEMA
        ) {
            throw new RuntimeException('Jieba runtime manifest schema is invalid.');
        }

        $upstream = $manifest['upstream'] ?? null;
        $artifacts = $manifest['artifacts'] ?? null;
        if (!is_array($upstream)
            || !self::has_exact_keys($upstream, ['repository', 'commit', 'dictionary_path', 'license_path'])
            || !is_array($artifacts)
            || !self::has_exact_keys($artifacts, ['dictionary', 'license', 'lookup'])
        ) {
            throw new RuntimeException('Jieba runtime manifest sections are invalid.');
        }
        foreach (['repository', 'commit', 'dictionary_path', 'license_path'] as $key) {
            if (!is_string($upstream[$key] ?? null) || trim($upstream[$key]) === '') {
                throw new RuntimeException("Jieba runtime manifest upstream {$key} is invalid.");
            }
        }
        foreach (['dictionary_path', 'license_path'] as $key) {
            $path = (string) $upstream[$key];
            if (str_starts_with($path, '/') || in_array('..', explode('/', str_replace('\\', '/', $path)), true)) {
                throw new RuntimeException("Jieba runtime manifest upstream {$key} must be relative.");
            }
        }

        foreach (['dictionary', 'license', 'lookup'] as $name) {
            $artifact = $artifacts[$name] ?? null;
            $artifactKeys = $name === 'lookup'
                ? ['runtime_path', 'sha256', 'bytes', 'ranges']
                : ['runtime_path', 'sha256', 'bytes'];
            if (!is_array($artifact)
                || !self::has_exact_keys($artifact, $artifactKeys)
                || !is_string($artifact['runtime_path'] ?? null)
                || basename($artifact['runtime_path']) !== $artifact['runtime_path']
                || preg_match('/^[a-f0-9]{64}$/', (string) ($artifact['sha256'] ?? '')) !== 1
                || !is_int($artifact['bytes'] ?? null)
                || $artifact['bytes'] < 1
            ) {
                throw new RuntimeException("Jieba runtime manifest {$name} artifact is invalid.");
            }
        }
        if ($artifacts['dictionary']['bytes'] > self::MAX_SOURCE_FILE_BYTES
            || !is_int($artifacts['lookup']['ranges'] ?? null)
            || $artifacts['lookup']['ranges'] < 1
        ) {
            throw new RuntimeException('Jieba runtime manifest artifact bounds are invalid.');
        }

        return $manifest;
    }

    /** Return whether an object-like array has exactly the documented keys. */
    private static function has_exact_keys(array $value, array $keys): bool
    {
        return count($value) === count($keys)
            && array_diff_key($value, array_fill_keys($keys, true)) === [];
    }

    /** Return the curated runtime dictionary, or its source checkout in development. */
    public static function default_source_file(): string
    {
        $manifest = self::runtime_manifest();
        $runtime = dirname(self::runtime_manifest_path()) . '/' . $manifest['artifacts']['dictionary']['runtime_path'];

        return is_file($runtime)
            ? $runtime
            : dirname(__DIR__) . '/resources/sources/jieba/' . $manifest['upstream']['dictionary_path'];
    }

    /** Return the compact lookup shipped next to the curated runtime source. */
    public static function default_lookup_file(): string
    {
        $manifest = self::runtime_manifest();

        return dirname(self::runtime_manifest_path()) . '/' . $manifest['artifacts']['lookup']['runtime_path'];
    }

    /**
     * Load the pinned segmenter when the public analyzer option is enabled.
     */
    public static function from_pack_option(mixed $option, ?string $expectedLanguage = null): ?self
    {
        $expectedLanguage = $expectedLanguage === null
            ? null
            : WP_FTS_TermNamespace::parse_language_tag($expectedLanguage);
        if ($option === false) {
            return null;
        }
        if ($option !== true) {
            throw new InvalidArgumentException('Jieba segmenter pack option must be true or false.');
        }
        if (
            $expectedLanguage !== null
            && self::base_language($expectedLanguage) !== self::LANGUAGE
        ) {
            throw new RuntimeException('The Jieba segmenter can only be enabled for Chinese language partitions.');
        }

        return new self();
    }

    /**
     * Segment one Chinese CJK run and retain fallback n-grams for recall.
     *
     * `$maxTokens` is a producer ceiling used by bounded query analysis. The
     * caller passes its occurrence allowance plus one so it can observe and
     * reject the first excess item. When fallback n-grams alone reach that
     * ceiling, they prove the query cannot be accepted and return before any
     * dictionary prefix is read. Calls without a ceiling retain complete direct
     * segmentation; the analyzer's ceiling is one item above its accepted
     * indexing output, so accepted documents retain the same complete order.
     *
     * @return string[]
     */
    public function __invoke(string $run, string $language, ?int $maxTokens = null): array
    {
        $language = WP_FTS_TermNamespace::parse_language_tag($language);
        if (self::base_language($language) !== self::LANGUAGE) {
            return [];
        }

        // LanguagePipeline enforces this before invoking any extension. Keep
        // the public segmenter safe when it is called directly as well: both
        // UTF-8 character materialization and candidate-by-offset matching are
        // otherwise proportional to an unchecked complete run.
        WP_FTS_Analysis_Limits::assert_lexical_run_bytes(strlen($run));
        if ($maxTokens !== null && $maxTokens <= 0) {
            return [];
        }
        if (array_key_exists($run, $this->runCache)) {
            $this->touch_run($run);
            return $maxTokens === null
                ? $this->runCache[$run]
                : array_slice($this->runCache[$run], 0, $maxTokens);
        }
        $chars = $this->utf8_chars($run);
        if ($chars === []) {
            return [];
        }
        if (count($chars) === 1) {
            $this->store_run_tokens($run, $chars);
            return $chars;
        }

        if ($maxTokens !== null) {
            $boundedFallbackTokens = [];
            $fallbackSeen = [];
            foreach ($this->fallback_cjk_tokens($chars) as $token) {
                $this->append_unique($boundedFallbackTokens, $fallbackSeen, $token);
                if (count($boundedFallbackTokens) >= $maxTokens) {
                    return $boundedFallbackTokens;
                }
            }
            unset($boundedFallbackTokens, $fallbackSeen);
        }

        $preloaded = $this->preload_prefixes($chars);
        $activePrefixes = $preloaded['active'];
        $runLookup = $preloaded['lookup'];

        $tokens = [];
        $seen = [];
        foreach ($this->dictionary_segments($chars, $activePrefixes, $runLookup['matches'] ?? null) as $segment) {
            foreach ($this->search_subsegments($segment, $activePrefixes, $runLookup['words'] ?? null) as $subsegment) {
                $this->append_unique($tokens, $seen, $subsegment);
                if ($maxTokens !== null && count($tokens) >= $maxTokens) {
                    return $tokens;
                }
            }
            $this->append_unique($tokens, $seen, $segment);
            if ($maxTokens !== null && count($tokens) >= $maxTokens) {
                return $tokens;
            }
        }
        foreach ($this->fallback_cjk_tokens($chars) as $token) {
            $this->append_unique($tokens, $seen, $token);
            if ($maxTokens !== null && count($tokens) >= $maxTokens) {
                return $tokens;
            }
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
        return self::PACK_ID;
    }

    /** Validate the curated lookup header from its exact bounded byte string. */
    private function lookup_file_is_attested(string $path): bool
    {
        $manifest = self::runtime_manifest();
        $dictionary = $manifest['artifacts']['dictionary'];
        $lookup = $manifest['artifacts']['lookup'];
        $contents = self::attested_lookup_contents($path);
        if ($contents === null) {
            return false;
        }
        $header = substr($contents, 0, self::LOOKUP_HEADER_BYTES);
        $counts = unpack('Nsource_size/Nrange_count', substr($header, 40, 8));
        if (substr($header, 0, 8) !== self::LOOKUP_MAGIC
            || !hash_equals(hex2bin($dictionary['sha256']), substr($header, 8, 32))
            || (int) ($counts['source_size'] ?? 0) !== $dictionary['bytes']
            || (int) ($counts['range_count'] ?? 0) !== $lookup['ranges']
        ) {
            return false;
        }

        return true;
    }

    /** Return exact curated lookup bytes only when size and digest both match. */
    private static function attested_lookup_contents(string $path): ?string
    {
        $lookup = self::runtime_manifest()['artifacts']['lookup'];
        if (!is_file($path)) {
            return null;
        }
        $contents = file_get_contents($path, false, null, 0, $lookup['bytes'] + 1);
        if (!is_string($contents)
            || strlen($contents) !== $lookup['bytes']
            || hash('sha256', $contents) !== $lookup['sha256']
        ) {
            return null;
        }

        return $contents;
    }

    /** Close the retained range index. */
    public function __destruct()
    {
        if (is_resource($this->lookupHandle)) {
            fclose($this->lookupHandle);
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
     * pinned source identity guarantees that the complete compact prefix maps
     * fit the retained-cache bounds. Every requested map is installed
     * completely; there is no partial-cache or eviction branch.
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
            if ($prefixCandidateCounts[$first] > self::MAX_CANDIDATES_PER_PREFIX) {
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
     * ranges, each bound to the pinned source by its digest.
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
     * @return array<int,array{offset:int,length:int,digest:string}>
     */
    private function indexed_ranges_for_prefixes(array $prefixes): array
    {
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
     * @return array<int,array{offset:int,length:int,digest:string}>|null
     */
    private function lookup_ranges_for_codepoint(int $codepoint): ?array
    {
        $rangeCount = self::runtime_manifest()['artifacts']['lookup']['ranges'];
        $low = 0;
        $high = $rangeCount;
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
        for ($recordNumber = $low; $recordNumber < $rangeCount; $recordNumber++) {
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

    /** Decode one already-split UTF-8 character for packed lookup addressing. */
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

    /** Test one prefix bit in the fixed-size loaded-prefix bitmap. */
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

    /** Mark one prefix in the fixed-size loaded-prefix bitmap. */
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

    /** Return one complete leading UTF-8 character without optional extensions. */
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

    /** Raise the stable typed failure for an oversized attested range row. */
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
     * @return iterable<int,string>
     */
    private function fallback_cjk_tokens(array $chars): iterable
    {
        $count = count($chars);
        if ($count <= 1) {
            yield from $chars;
            return;
        }

        $maxLength = min(self::FALLBACK_MAX_NGRAM_LENGTH, $count);
        for ($length = 1; $length <= $maxLength; $length++) {
            for ($offset = 0; $offset <= $count - $length; $offset++) {
                yield implode('', array_slice($chars, $offset, $length));
            }
        }
    }

    /**
     * @return string[]
     */
    private function utf8_chars(string $text): array
    {
        if (preg_match_all('/./us', $text, $matches) === false) {
            throw new RuntimeException('Jieba Unicode character tokenization failed.');
        }

        return $matches[0] ?? [];
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

    /** Move one bounded run-cache entry to the most-recent position. */
    private function touch_run(string $run): void
    {
        $this->runCacheOrder = array_values(array_filter(
            $this->runCacheOrder,
            static fn(string $cached): bool => $cached !== $run
        ));
        $this->runCacheOrder[] = $run;
    }

    /** Evict the least-recent run and subtract its retained byte accounting. */
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

    private function build_index_signature(): string
    {
        $manifest = self::runtime_manifest();
        $upstream = $manifest['upstream'];
        $dictionary = $manifest['artifacts']['dictionary'];
        $payload = [
            'contract' => 'wp-fts-chinese-jieba-segmenter',
            'version' => 1,
            'pack_id' => self::PACK_ID,
            'pack_version' => self::PACK_VERSION,
            'language' => self::LANGUAGE,
            'source_repository' => $upstream['repository'],
            'source_commit' => $upstream['commit'],
            'source_path' => $upstream['dictionary_path'],
            'source_sha256' => $dictionary['sha256'],
            'source_byte_size' => $dictionary['bytes'],
            'fallback_max_ngram_length' => self::FALLBACK_MAX_NGRAM_LENGTH,
            'max_candidates_per_prefix' => self::MAX_CANDIDATES_PER_PREFIX,
        ];

        return 'wp-fts-chinese-jieba-segmenter-v1:' . sha1($this->stable_json($payload));
    }

    private function stable_json(mixed $value): string
    {
        if (is_array($value)) {
            if (!array_is_list($value)) {
                ksort($value, SORT_STRING);
            }
            foreach ($value as $key => $item) {
                $value[$key] = $this->stable_json_value($item);
            }
        }

        return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    private function stable_json_value(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }

        if (!array_is_list($value)) {
            ksort($value, SORT_STRING);
        }
        foreach ($value as $key => $item) {
            $value[$key] = $this->stable_json_value($item);
        }

        return $value;
    }

    private static function base_language(string $language): string
    {
        $parts = explode('-', $language);

        return strtolower($parts[0]);
    }
}
