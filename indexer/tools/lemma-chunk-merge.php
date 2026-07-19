<?php
declare(strict_types=1);

/** Min-heap for bytewise-sorted normalized runtime pairs. */
final class WP_FTS_LemmaChunkMinHeap extends SplPriorityQueue
{
    /** Invert SplPriorityQueue ordering so the bytewise-smallest row wins. */
    public function compare(mixed $priority1, mixed $priority2): int
    {
        return -strcmp((string) $priority1, (string) $priority2);
    }
}

/**
 * Maintains a bounded fan-in hierarchy of sorted importer chunks.
 *
 * A caller may deliberately request one source row per chunk. Compacting each
 * full level immediately prevents both the temporary-file set and the final
 * merge from growing linearly with the source row count.
 */
final class WP_FTS_LemmaChunkSet
{
    public const MAX_MERGE_INPUTS = 64;
    public const MAX_INITIAL_FILES = 16384;

    /** @var array<int,string[]> */
    private array $levels = [];
    private int $initialFiles = 0;
    private int $mergeOutputs = 0;
    private int $mergePasses = 0;
    private int $liveFiles = 0;
    private int $maxLiveFiles = 0;
    private int $maxMergeInputs = 0;

    /** Keep all compaction artifacts inside one importer-owned directory. */
    public function __construct(private readonly string $tmpDir)
    {
    }

    /** Register one sorted leaf chunk and compact a full hierarchy level. */
    public function add(string $path): void
    {
        if ($this->initialFiles >= self::MAX_INITIAL_FILES) {
            throw new RuntimeException('Lemma import exceeds the 16,384 initial-chunk file limit.');
        }
        $this->initialFiles++;
        $this->add_at_level($path, 0);
    }

    /**
     * @return array{files:string[],initial_files:int,merge_outputs:int,merge_passes:int,max_live_files:int,max_merge_inputs:int}
     */
    public function finish(): array
    {
        $files = [];
        foreach ($this->levels as $levelFiles) {
            foreach ($levelFiles as $path) {
                $files[] = $path;
            }
        }

        while (count($files) > self::MAX_MERGE_INPUTS) {
            $next = [];
            foreach (array_chunk($files, self::MAX_MERGE_INPUTS) as $group) {
                if (count($group) === 1) {
                    $next[] = $group[0];
                    continue;
                }
                $next[] = $this->merge_group($group, $this->mergePasses + 1);
            }
            $this->mergePasses++;
            $files = $next;
        }
        $this->maxMergeInputs = max($this->maxMergeInputs, count($files));

        return [
            'files' => $files,
            'initial_files' => $this->initialFiles,
            'merge_outputs' => $this->mergeOutputs,
            'merge_passes' => $this->mergePasses,
            'max_live_files' => $this->maxLiveFiles,
            'max_merge_inputs' => $this->maxMergeInputs,
        ];
    }

    /** @return Generator<int,string> */
    public static function unique_lines(array $paths): Generator
    {
        if (count($paths) > self::MAX_MERGE_INPUTS) {
            throw new RuntimeException('Lemma chunk merge exceeded its bounded 64-file fan-in.');
        }

        $handles = [];
        $heap = new WP_FTS_LemmaChunkMinHeap();
        $heap->setExtractFlags(SplPriorityQueue::EXTR_DATA);
        try {
            foreach ($paths as $index => $path) {
                $handle = fopen($path, 'rb');
                if (!is_resource($handle)) {
                    throw new RuntimeException("Could not read lemma importer chunk: {$path}");
                }
                $handles[$index] = $handle;
                $line = self::read_line($handle, $path);
                if ($line !== null) {
                    $heap->insert(['index' => $index, 'line' => $line], $line);
                }
            }

            $previous = null;
            while (!$heap->isEmpty()) {
                $entry = $heap->extract();
                $line = (string) $entry['line'];
                if ($line !== $previous) {
                    yield $line;
                    $previous = $line;
                }
                $index = (int) $entry['index'];
                $next = self::read_line($handles[$index], $paths[$index]);
                if ($next !== null) {
                    $heap->insert(['index' => $index, 'line' => $next], $next);
                }
            }
        } finally {
            foreach ($handles as $handle) {
                if (is_resource($handle)) {
                    fclose($handle);
                }
            }
        }
    }

    /** Cascade one live chunk upward while preserving exact live-file counts. */
    private function add_at_level(string $path, int $level, bool $alreadyLive = false): void
    {
        $this->levels[$level] ??= [];
        $this->levels[$level][] = $path;
        if (!$alreadyLive) {
            $this->liveFiles++;
            $this->maxLiveFiles = max($this->maxLiveFiles, $this->liveFiles);
        }
        if (count($this->levels[$level]) < self::MAX_MERGE_INPUTS) {
            return;
        }

        $inputs = $this->levels[$level];
        $this->levels[$level] = [];
        $output = $this->merge_group($inputs, $level + 1);
        $this->mergePasses = max($this->mergePasses, $level + 1);
        $this->add_at_level($output, $level + 1, true);
    }

    /** @param string[] $paths */
    private function merge_group(array $paths, int $pass): string
    {
        $this->maxMergeInputs = max($this->maxMergeInputs, count($paths));
        $this->mergeOutputs++;
        $output = $this->tmpDir . DIRECTORY_SEPARATOR . sprintf(
            'merged-%03d-%08d.tsv',
            $pass,
            $this->mergeOutputs
        );
        $handle = fopen($output, 'xb');
        if (!is_resource($handle)) {
            throw new RuntimeException("Could not create merged lemma importer chunk: {$output}");
        }
        try {
            foreach (self::unique_lines($paths) as $line) {
                $encoded = $line . "\n";
                if (fwrite($handle, $encoded) !== strlen($encoded)) {
                    throw new RuntimeException("Could not write merged lemma importer chunk: {$output}");
                }
            }
        } catch (Throwable $error) {
            fclose($handle);
            @unlink($output);
            throw $error;
        }
        fclose($handle);

        $this->liveFiles++;
        $this->maxLiveFiles = max($this->maxLiveFiles, $this->liveFiles);
        foreach ($paths as $path) {
            if (!@unlink($path) && is_file($path)) {
                @unlink($output);
                throw new RuntimeException("Could not remove compacted lemma importer chunk: {$path}");
            }
            $this->liveFiles--;
        }

        return $output;
    }

    /** @param resource $handle */
    private static function read_line($handle, string $path): ?string
    {
        $line = fgets($handle, WP_FTS_LemmaPackLimits::MAX_RUNTIME_LINE_BYTES + 3);
        if ($line === false) {
            return null;
        }
        $terminated = str_ends_with($line, "\n");
        $payload = rtrim(rtrim($line, "\n"), "\r");
        if (!$terminated && !feof($handle)) {
            throw new RuntimeException("Lemma importer chunk contains an oversized line: {$path}");
        }
        WP_FTS_LemmaPackLimits::assert_runtime_line_bytes(strlen($payload));

        return $payload;
    }
}
