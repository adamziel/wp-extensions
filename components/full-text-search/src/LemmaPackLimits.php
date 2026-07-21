<?php
declare(strict_types=1);

/** Hard bounds shared by lemma-pack generation, validation, and lookup. */
final class WP_FTS_LemmaPackLimits
{
    public const MAX_LEMMAS_PER_SURFACE = 12;
    public const MAX_RUNTIME_TOKEN_BYTES = 255;
    public const MAX_RUNTIME_LINE_BYTES = 4096;
    public const MAX_RUNTIME_LOOKUP_DECODED_BYTES = 8388608;
    public const MAX_EAGER_FIXTURE_ROWS = 50000;
    public const MAX_EAGER_FIXTURE_RUNTIME_BYTES = 8388608;
    public const MAX_EAGER_FIXTURE_RUNTIME_FRAMING_BYTES = 65536;
    /** Eager maps amplify decoded rows into PHP arrays, so both caps are shared. */
    public const MAX_CONFIGURED_EAGER_FIXTURE_ROWS = self::MAX_EAGER_FIXTURE_ROWS;
    public const MAX_CONFIGURED_EAGER_FIXTURE_RUNTIME_BYTES = self::MAX_EAGER_FIXTURE_RUNTIME_BYTES;

    /**
     * Open and hash one file while enforcing a hard physical byte ceiling.
     *
     * @return array{sha256:string,bytes:int,stat:array<string|int,mixed>}
     */
    public static function hash_file_bounded(
        string $path,
        int $maxBytes,
        string $reasonCode,
        string $limitMessage
    ): array {
        $handle = @fopen($path, 'rb');
        if (!is_resource($handle)) {
            throw new RuntimeException("Could not open file for bounded hashing: {$path}");
        }

        try {
            return self::hash_open_file_bounded($handle, $maxBytes, $reasonCode, $limitMessage);
        } finally {
            fclose($handle);
        }
    }

    /**
     * Hash one already-opened file generation without trusting its initial size
     * to remain stable. An optional destination receives the exact bytes that
     * were hashed, allowing a builder to parse the attested snapshot rather than
     * reopening a mutable source path.
     *
     * @param resource $source
     * @param resource|null $copy
     * @return array{sha256:string,bytes:int,stat:array<string|int,mixed>}
     */
    public static function hash_open_file_bounded(
        mixed $source,
        int $maxBytes,
        string $reasonCode,
        string $limitMessage,
        mixed $copy = null
    ): array {
        if (!is_resource($source) || ($copy !== null && !is_resource($copy))) {
            throw new InvalidArgumentException('Bounded file hashing requires open stream resources.');
        }
        if ($maxBytes < 1) {
            throw new InvalidArgumentException('Bounded file hashing requires a positive byte limit.');
        }

        $stat = fstat($source);
        if (!is_array($stat)) {
            throw new RuntimeException('Could not identify the file generation before hashing.');
        }
        $initialSize = $stat['size'] ?? null;
        if (!is_int($initialSize) || $initialSize < 0) {
            throw new RuntimeException('Could not size the file before hashing.');
        }
        if ($initialSize > $maxBytes) {
            throw new WP_FTS_Analyzer_Config_Limit_Exceeded($reasonCode, $limitMessage);
        }

        $hash = hash_init('sha256');
        $bytes = 0;
        while (!feof($source)) {
            $chunk = fread($source, min(8192, $maxBytes - $bytes + 1));
            if (!is_string($chunk)) {
                throw new RuntimeException('Could not read a file while hashing it.');
            }
            if ($chunk === '') {
                if (feof($source)) {
                    break;
                }
                throw new RuntimeException('File hashing made no progress before end-of-file.');
            }

            $bytes += strlen($chunk);
            if ($bytes > $maxBytes) {
                throw new WP_FTS_Analyzer_Config_Limit_Exceeded($reasonCode, $limitMessage);
            }
            hash_update($hash, $chunk);
            if ($copy !== null && !self::write_all($copy, $chunk)) {
                throw new RuntimeException('Could not write the bounded file snapshot.');
            }
        }

        return [
            'sha256' => hash_final($hash),
            'bytes' => $bytes,
            'stat' => $stat,
        ];
    }

    /**
     * Read one runtime TSV/comment line without allowing a compressed or plain
     * malformed line to allocate the rest of a local pack in one call.
     *
     * @param resource $handle
     */
    public static function read_runtime_line(mixed $handle, ?string $compression): string|false
    {
        $line = $compression === 'gzip'
            ? @gzgets($handle, self::MAX_RUNTIME_LINE_BYTES + 3)
            : fgets($handle, self::MAX_RUNTIME_LINE_BYTES + 3);
        if ($line === false) {
            return false;
        }

        $terminated = str_ends_with($line, "\n");
        $payloadBytes = strlen(rtrim($line, "\r\n"));
        $atEnd = $compression === 'gzip' ? gzeof($handle) : feof($handle);
        self::assert_runtime_line_bytes($payloadBytes);
        if (!$terminated && !$atEnd) {
            throw new WP_FTS_Analyzer_Config_Limit_Exceeded(
                'runtime_line_bytes',
                'Analyzer-pack runtime lines may contain at most 4 KiB.'
            );
        }

        return $line;
    }

    /** Reject an already-delimited decoded line before allocating its payload. */
    public static function assert_runtime_line_bytes(int $bytes): void
    {
        if ($bytes <= self::MAX_RUNTIME_LINE_BYTES) {
            return;
        }

        throw new WP_FTS_Analyzer_Config_Limit_Exceeded(
            'runtime_line_bytes',
            'Analyzer-pack runtime lines may contain at most 4 KiB.'
        );
    }

    /** Validate every delimited line in an already bounded decoded buffer. */
    public static function assert_runtime_buffer_lines(string $data): void
    {
        $length = strlen($data);
        $start = 0;
        while ($start < $length) {
            $end = strpos($data, "\n", $start);
            if ($end === false) {
                $end = $length;
            }
            $payloadEnd = $end > $start && $data[$end - 1] === "\r" ? $end - 1 : $end;
            self::assert_runtime_line_bytes($payloadEnd - $start);
            if ($end === $length) {
                return;
            }
            $start = $end + 1;
        }
    }

    /**
     * Keep the same deterministic rank order used by lemma-pack analysis while
     * bounding one source token to the complete relational-plan allowance. An
     * exact normalized lemma remains first; all other candidates use bytewise
     * lexical order.
     *
     * @param string[] $lemmas
     * @return string[]
     */
    public static function ordered_lemmas_for_surface(string $surface, array $lemmas): array
    {
        $lemmas = array_values(array_unique(array_map('strval', $lemmas)));
        self::assert_surface_lemma_count($surface, count($lemmas));
        sort($lemmas, SORT_STRING);
        if (in_array($surface, $lemmas, true)) {
            $lemmas = array_values(array_merge(
                [$surface],
                array_filter($lemmas, static fn(string $lemma): bool => $lemma !== $surface)
            ));
        }

        return $lemmas;
    }

    /** Reject corrupt runtime data before it can expand one source occurrence. */
    public static function assert_surface_lemma_count(string $surface, int $count): void
    {
        if ($count <= self::MAX_LEMMAS_PER_SURFACE) {
            return;
        }

        throw new RuntimeException(
            "Lemma-pack surface {$surface} exceeds the " . self::MAX_LEMMAS_PER_SURFACE . '-lemma ambiguity limit.'
        );
    }

    /** Write one complete chunk without assuming a single fwrite() is complete. */
    private static function write_all(mixed $handle, string $data): bool
    {
        $offset = 0;
        $length = strlen($data);
        while ($offset < $length) {
            $written = fwrite($handle, substr($data, $offset));
            if (!is_int($written) || $written < 1) {
                return false;
            }
            $offset += $written;
        }

        return true;
    }
}
