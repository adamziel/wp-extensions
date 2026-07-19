<?php
declare(strict_types=1);

/** Hard bounds shared by lemma-pack generation, validation, and lookup. */
final class WP_FTS_LemmaPackLimits
{
    public const MAX_LEMMAS_PER_SURFACE = 12;
    public const MAX_RUNTIME_LINE_BYTES = 4096;
    public const MAX_RUNTIME_LOOKUP_DECODED_BYTES = 8388608;

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
}
