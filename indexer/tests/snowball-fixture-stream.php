<?php
declare(strict_types=1);

/**
 * Streaming readers for Snowball fixture files.
 *
 * The Arabic Snowball data is only available in compressed form in this
 * workspace, and `php -n` does not load zlib. For gzip files, prefer PHP's zlib
 * stream when available; otherwise stream through a local `gzip -dc` process
 * without invoking a shell.
 */
final class WP_FTS_SnowballFixtureLineReader
{
    /** @var resource|null */
    private $handle = null;

    /** @var resource|null */
    private $process = null;

    /** @var array<int,resource> */
    private array $pipes = [];

    private string $mode = 'plain';
    private bool $closed = false;

    private function __construct(private string $path)
    {
    }

    public static function open(string $path): self
    {
        if (!is_file($path)) {
            throw new RuntimeException("Unable to read Snowball fixture {$path}");
        }

        $reader = new self($path);
        if (str_ends_with($path, '.gz')) {
            $reader->open_gzip();
            return $reader;
        }

        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            throw new RuntimeException("Unable to open Snowball fixture {$path}");
        }

        $reader->handle = $handle;
        $reader->mode = 'plain';

        return $reader;
    }

    public function read_line(): ?string
    {
        if ($this->closed || $this->handle === null) {
            return null;
        }

        $line = $this->mode === 'gz-zlib'
            ? gzgets($this->handle)
            : fgets($this->handle);

        if ($line === false) {
            return null;
        }

        return rtrim($line, "\r\n");
    }

    public function close(bool $allowProcessFailure = false): void
    {
        if ($this->closed) {
            return;
        }

        $this->closed = true;

        if ($this->handle !== null) {
            if ($this->mode === 'gz-zlib') {
                gzclose($this->handle);
            } else {
                fclose($this->handle);
            }
            $this->handle = null;
        }

        if ($this->process !== null) {
            $stderr = '';
            if (isset($this->pipes[2]) && is_resource($this->pipes[2])) {
                $stderr = (string) stream_get_contents($this->pipes[2]);
                fclose($this->pipes[2]);
            }

            $exit = proc_close($this->process);
            $this->process = null;
            $this->pipes = [];

            if (!$allowProcessFailure && $exit !== 0) {
                $detail = trim($stderr);
                $suffix = $detail === '' ? '' : ": {$detail}";
                throw new RuntimeException("gzip fixture stream failed for {$this->path} with exit {$exit}{$suffix}");
            }
        }
    }

    private function open_gzip(): void
    {
        if (function_exists('gzopen') && function_exists('gzgets')) {
            $handle = @gzopen($this->path, 'rb');
            if ($handle === false) {
                throw new RuntimeException("Unable to open gzip Snowball fixture {$this->path}");
            }

            $this->handle = $handle;
            $this->mode = 'gz-zlib';
            return;
        }

        if (!function_exists('proc_open')) {
            throw new RuntimeException('Reading gzip Snowball fixtures without zlib requires proc_open().');
        }

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $process = @proc_open(['gzip', '-dc', '--', $this->path], $descriptors, $pipes);
        if (!is_resource($process)) {
            throw new RuntimeException("Unable to start local gzip reader for {$this->path}");
        }

        fclose($pipes[0]);
        $this->process = $process;
        $this->pipes = $pipes;
        $this->handle = $pipes[1];
        $this->mode = 'gz-process';
    }
}

function wp_fts_snowball_fixture_file(string $dir, string $basename): ?string
{
    $plain = $dir . DIRECTORY_SEPARATOR . $basename;
    if (is_file($plain)) {
        return $plain;
    }

    $gzip = $plain . '.gz';
    if (is_file($gzip)) {
        return $gzip;
    }

    return null;
}

/**
 * @param callable(int,string,string):void $callback
 */
function wp_fts_snowball_fixture_for_each_pair(string $inputPath, string $outputPath, callable $callback): int
{
    $inputs = WP_FTS_SnowballFixtureLineReader::open($inputPath);
    $outputs = WP_FTS_SnowballFixtureLineReader::open($outputPath);
    $line = 0;
    $complete = false;

    try {
        while (true) {
            $input = $inputs->read_line();
            $expected = $outputs->read_line();
            if ($input === null && $expected === null) {
                $complete = true;
                break;
            }

            ++$line;
            if ($input === null || $expected === null) {
                throw new RuntimeException("Snowball fixture input/output line counts differ at line {$line}");
            }

            $callback($line, $input, $expected);
        }
    } finally {
        $inputs->close(!$complete);
        $outputs->close(!$complete);
    }

    return $line;
}

/**
 * @param int[] $lineNumbers
 * @return array<int,array{input:string,output:string}>
 */
function wp_fts_snowball_fixture_read_rows(string $inputPath, string $outputPath, array $lineNumbers): array
{
    $targets = array_values(array_unique(array_map('intval', $lineNumbers)));
    sort($targets, SORT_NUMERIC);
    if ($targets === []) {
        return [];
    }

    $targetMap = array_fill_keys($targets, true);
    $maxTarget = max($targets);
    $rows = [];
    $complete = false;

    $inputs = WP_FTS_SnowballFixtureLineReader::open($inputPath);
    $outputs = WP_FTS_SnowballFixtureLineReader::open($outputPath);
    try {
        for ($line = 1; $line <= $maxTarget; ++$line) {
            $input = $inputs->read_line();
            $expected = $outputs->read_line();
            if ($input === null || $expected === null) {
                $complete = true;
                break;
            }

            if (isset($targetMap[$line])) {
                $rows[$line] = [
                    'input' => $input,
                    'output' => $expected,
                ];
            }
        }
    } finally {
        $inputs->close(!$complete && count($rows) === count($targets));
        $outputs->close(!$complete && count($rows) === count($targets));
    }

    foreach ($targets as $target) {
        if (!isset($rows[$target])) {
            throw new RuntimeException("Snowball fixture row {$target} was not found in {$inputPath}");
        }
    }

    return $rows;
}
