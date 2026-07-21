<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/src/bootstrap.php';

final class WP_FTS_Lemma_Bounded_Hash_Growth_Stream
{
    public mixed $context = null;
    public static int $bytesRead = 0;
    private int $position = 0;

    /** Reset the synthetic opened generation for one validator read. */
    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool
    {
        $this->position = 0;
        return true;
    }

    /** Emit exactly one byte beyond the physical cap despite a size-one stat. */
    public function stream_read(int $count): string
    {
        $total = WP_FTS_Analyzer_Config_Limits::MAX_RUNTIME_LOOKUP_BYTES_PER_PACK + 1;
        $bytes = min($count, $total - $this->position);
        if ($bytes < 1) {
            return '';
        }
        $this->position += $bytes;
        self::$bytesRead += $bytes;
        return str_repeat('x', $bytes);
    }

    /** End only after the validator has had a chance to observe the excess byte. */
    public function stream_eof(): bool
    {
        return $this->position > WP_FTS_Analyzer_Config_Limits::MAX_RUNTIME_LOOKUP_BYTES_PER_PACK;
    }

    /** @return array<string|int,int> */
    public function stream_stat(): array
    {
        return self::stat_row();
    }

    /** @return array<string|int,int> */
    public function url_stat(string $path, int $flags): array
    {
        return self::stat_row();
    }

    /** @return array<string|int,int> */
    private static function stat_row(): array
    {
        $time = time() + 5;
        $values = [1, 1, 0100644, 1, 0, 0, 0, 1, $time, $time, $time, 4096, 1];
        return $values + array_combine(
            ['dev', 'ino', 'mode', 'nlink', 'uid', 'gid', 'rdev', 'size', 'atime', 'mtime', 'ctime', 'blksize', 'blocks'],
            $values
        );
    }
}

$scheme = 'wpftsgrowth';
if (!stream_wrapper_register($scheme, WP_FTS_Lemma_Bounded_Hash_Growth_Stream::class)) {
    fwrite(STDERR, "Could not register growth stream.\n");
    exit(1);
}

$started = microtime(true);
$error = null;
try {
    $validator = new WP_FTS_AnalyzerPackValidator();
    $method = new ReflectionMethod($validator, 'attest_file_digest');
    $method->invoke($validator, $scheme . '://runtime', str_repeat('0', 64), 'digest mismatch');
} catch (Throwable $caught) {
    $error = $caught;
} finally {
    stream_wrapper_unregister($scheme);
}

echo json_encode([
    'error_class' => is_object($error) ? get_class($error) : null,
    'reason_code' => $error instanceof WP_FTS_Analyzer_Config_Limit_Exceeded ? $error->reason_code : null,
    'bytes_read' => WP_FTS_Lemma_Bounded_Hash_Growth_Stream::$bytesRead,
    'elapsed_seconds' => microtime(true) - $started,
    'php_peak_bytes' => memory_get_peak_usage(true),
], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), "\n";
