<?php
declare(strict_types=1);

/**
 * Disposable release smoke runner for the direct-install Indexer ZIP.
 *
 * The public CLI entry point is intentionally skip-first. It performs no
 * WordPress writes until WP_FTS_DISPOSABLE_SMOKE_ALLOW=1 is set and the target
 * WordPress root is explicitly marked as disposable.
 */
final class WP_FTS_DisposableReleaseSmokeRunner
{
    public const ALLOW_ENV = 'WP_FTS_DISPOSABLE_SMOKE_ALLOW';
    public const CONFIRM_PATH_ENV = 'WP_FTS_DISPOSABLE_SMOKE_CONFIRM_PATH';
    public const MARKER_FILE = '.wp-fts-disposable-smoke';
    public const RELEASE_ZIP_ENV = 'WP_FTS_RELEASE_ZIP';
    public const WP_CLI_ENV = 'WP_FTS_WP_CLI';
    public const WP_PATH_ENV = 'WP_FTS_WP_PATH';
    public const WP_URL_ENV = 'WP_FTS_WP_URL';

    private const INDEX_BATCH_SIZE = 1;
    private const INDEX_TIME_BUDGET = '5';
    private const REPORT_SCHEMA = 'wp-fts-disposable-release-smoke-v1';
    private const OUTPUT_EXCERPT_BYTES = 900;

    /** @var callable(array<int,string>, array<string,string>): array{exit:int,stdout:string,stderr:string} */
    private $processRunner;

    /** @var callable(array<string,mixed>): array{build_dir:string,zip_path:string,sha256:string,removed_paths:string[],prohibited_paths:string[]} */
    private $releaseBuilder;

    /** @var callable(string): void */
    private $removePath;

    /** @var array<string,string> */
    private array $env;

    /**
     * @var string[]
     */
    private array $createdTempBuildDirs = [];

    private bool $usesDefaultProcessRunner;

    /**
     * @param callable(array<int,string>, array<string,string>): array{exit:int,stdout:string,stderr:string}|null $processRunner
     * @param callable(array<string,mixed>): array{build_dir:string,zip_path:string,sha256:string,removed_paths:string[],prohibited_paths:string[]}|null $releaseBuilder
     * @param callable(string): void|null $removePath
     * @param array<string,string>|null $env
     */
    public function __construct(
        ?callable $processRunner = null,
        ?callable $releaseBuilder = null,
        ?callable $removePath = null,
        ?array $env = null
    ) {
        $this->usesDefaultProcessRunner = $processRunner === null;
        $this->processRunner = $processRunner ?? [$this, 'default_process_runner'];
        $this->releaseBuilder = $releaseBuilder ?? [$this, 'default_release_builder'];
        $this->removePath = $removePath ?? [$this, 'remove_path'];
        $this->env = $env ?? self::current_environment();
    }

    /**
     * @param array<int,string> $args
     * @return array<string,mixed>
     */
    public static function parse_cli_options(array $args): array
    {
        $options = [];
        foreach ($args as $arg) {
            if ($arg === '--help' || $arg === '-h') {
                $options['help'] = true;
                continue;
            }

            foreach (['zip', 'release-zip', 'build-dir', 'plugin-src', 'monorepo-root'] as $name) {
                $prefix = "--{$name}=";
                if (str_starts_with($arg, $prefix)) {
                    $key = str_replace('-', '_', $name);
                    if ($key === 'release_zip') {
                        $key = 'zip';
                    }
                    $options[$key] = substr($arg, strlen($prefix));
                    continue 2;
                }
            }

            throw new InvalidArgumentException("Unknown option: {$arg}");
        }

        return $options;
    }

    public static function usage(): string
    {
        return implode("\n", [
            'Usage: php indexer/tools/smoke-disposable-wordpress-release.php [options]',
            '',
            'Required environment for WordPress writes:',
            '  WP_FTS_WP_PATH=/path/to/disposable-wordpress',
            '  WP_FTS_DISPOSABLE_SMOKE_ALLOW=1',
            '  touch /path/to/disposable-wordpress/' . self::MARKER_FILE,
            '',
            'Options:',
            '  --zip=PATH             Existing release ZIP to install. Env: ' . self::RELEASE_ZIP_ENV . '.',
            '  --build-dir=PATH       Optional build directory when no ZIP is supplied.',
            '  --plugin-src=PATH      Plugin source for the release builder.',
            '  --monorepo-root=PATH   Monorepo root for the release builder.',
            '  -h, --help             Show this help.',
            '',
        ]);
    }

    /**
     * @param array<string,mixed> $options
     * @return array{exit:int,status:string,message:string,report:array<string,mixed>}
     */
    public function run(array $options = []): array
    {
        $report = [
            'schema' => self::REPORT_SCHEMA,
            'status' => 'running',
            'commands' => [],
            'cleanup' => [],
        ];

        if ($this->usesDefaultProcessRunner && !function_exists('proc_open')) {
            return $this->result('skipped', 'proc_open() is unavailable; cannot launch WP-CLI.', $report);
        }

        $wpPath = $this->validated_wp_path();
        if ($wpPath === null) {
            return $this->result(
                'skipped',
                'Set WP_FTS_WP_PATH to an installed disposable WordPress root with wp-load.php.',
                $report
            );
        }

        if ($this->env_value(self::ALLOW_ENV) !== '1') {
            return $this->result(
                'skipped',
                'Set WP_FTS_DISPOSABLE_SMOKE_ALLOW=1 only for a disposable, non-production WordPress site.',
                $report
            );
        }

        if (!$this->is_explicitly_disposable_path($wpPath)) {
            return $this->result(
                'skipped',
                'Refusing to write: create ' . self::MARKER_FILE . ' in WP_FTS_WP_PATH or set WP_FTS_DISPOSABLE_SMOKE_CONFIRM_PATH to that exact root.',
                $report
            );
        }

        $baseCommand = $this->wp_cli_base_command($wpPath);
        $postId = 0;
        $autoBuildDir = '';
        $releaseZip = '';

        try {
            $installed = $this->run_process('core is-installed', array_merge($baseCommand, ['core', 'is-installed']), [], $report);
            if ($installed['exit'] !== 0) {
                $detail = self::sanitize_output(trim($installed['stderr'] . "\n" . $installed['stdout']));
                return $this->result(
                    'skipped',
                    'WP-CLI is unavailable or WordPress is not installed at WP_FTS_WP_PATH.'
                        . ($detail !== '' ? " Detail: {$detail}" : ''),
                    $report
                );
            }

            $release = $this->release_zip($options);
            if (isset($release['skip'])) {
                return $this->result('skipped', (string) $release['skip'], $report);
            }

            $releaseZip = $release['zip_path'];
            $autoBuildDir = $release['auto_build_dir'];
            $report['release_zip_sha256'] = $release['sha256'] ?? null;
            $report['release_zip_source'] = $release['source'];

            $this->require_success(
                'install and activate release ZIP',
                array_merge($baseCommand, ['plugin', 'install', $releaseZip, '--force', '--activate']),
                $report
            );

            $statusBefore = $this->require_json_success(
                'status before repair',
                array_merge($baseCommand, ['fts', 'status', '--format=json']),
                $report
            );
            $repair = $this->require_json_success(
                'repair schema',
                array_merge($baseCommand, ['fts', 'repair', '--format=json']),
                $report
            );
            $statusAfterRepair = $this->require_json_success(
                'status after repair',
                array_merge($baseCommand, ['fts', 'status', '--format=json']),
                $report
            );

            $token = $this->token();
            $report['token'] = $token;
            $created = $this->require_success(
                'create disposable fixture post',
                array_merge($baseCommand, [
                    'post',
                    'create',
                    '--post_type=post',
                    '--post_status=publish',
                    '--post_title=WP FTS disposable release smoke ' . $token,
                    '--post_content=<p lang="en">wp fts disposable release smoke ' . $token . '</p>',
                    '--porcelain',
                ]),
                $report
            );
            $postId = (int) trim($created['stdout']);
            if ($postId <= 0) {
                throw new RuntimeException('Fixture post creation did not return a positive post id.');
            }
            $report['fixture_post_id'] = $postId;

            $indexing = $this->require_json_success(
                'process bounded index batch',
                array_merge($baseCommand, [
                    'fts',
                    'process-batch',
                    '--batch_size=' . self::INDEX_BATCH_SIZE,
                    '--time_budget=' . self::INDEX_TIME_BUDGET,
                    '--format=json',
                ]),
                $report
            );
            $search = $this->require_json_success(
                'search disposable fixture token',
                array_merge($baseCommand, [
                    'fts',
                    'search',
                    $token,
                    '--post_type=post',
                    '--post_status=publish',
                    '--limit=5',
                    '--format=json',
                ]),
                $report
            );
            if (!$this->search_payload_contains_post($search, $postId)) {
                throw new RuntimeException('Search JSON did not include the disposable fixture post.');
            }

            $statusAfter = $this->require_json_success(
                'status after search',
                array_merge($baseCommand, ['fts', 'status', '--format=json']),
                $report
            );

            $cleanupFailure = $this->cleanup($baseCommand, $postId, $autoBuildDir, $report);
            $postId = 0;
            $autoBuildDir = '';
            if ($cleanupFailure !== null) {
                throw $cleanupFailure;
            }

            $report['status_evidence'] = [
                'before_repair' => $this->compact_payload($statusBefore),
                'repair' => $this->compact_payload($repair),
                'after_repair' => $this->compact_payload($statusAfterRepair),
                'after_search' => $this->compact_payload($statusAfter),
            ];
            $report['indexing_evidence'] = $this->compact_payload($indexing);
            $report['search_evidence'] = [
                'matched_post_id' => $postId > 0 ? $postId : (int) ($report['fixture_post_id'] ?? 0),
                'result_count' => $this->search_result_count($search),
            ];

            return $this->result('passed', 'Disposable WordPress release smoke completed.', $report);
        } catch (Throwable $e) {
            $cleanupFailure = $this->cleanup($baseCommand, $postId, $autoBuildDir, $report);
            if ($cleanupFailure !== null) {
                $report['cleanup_error'] = self::sanitize_output($cleanupFailure->getMessage());
            }

            return $this->result('failed', self::sanitize_output($e->getMessage()), $report);
        }
    }

    /**
     * @param array<string,mixed> $report
     * @return array{exit:int,status:string,message:string,report:array<string,mixed>}
     */
    private function result(string $status, string $message, array $report): array
    {
        $report['status'] = $status;
        $report['message'] = $message;

        return [
            'exit' => $status === 'failed' ? 1 : 0,
            'status' => $status,
            'message' => $message,
            'report' => $report,
        ];
    }

    private function validated_wp_path(): ?string
    {
        $raw = trim($this->env_value(self::WP_PATH_ENV));
        if ($raw === '') {
            return null;
        }

        $real = realpath($raw);
        if (!is_string($real) || !is_dir($real) || !is_file($real . '/wp-load.php')) {
            return null;
        }

        return rtrim($real, DIRECTORY_SEPARATOR);
    }

    private function is_explicitly_disposable_path(string $wpPath): bool
    {
        if (is_file($wpPath . DIRECTORY_SEPARATOR . self::MARKER_FILE)) {
            return true;
        }

        $confirmed = trim($this->env_value(self::CONFIRM_PATH_ENV));
        if ($confirmed === '') {
            return false;
        }

        $real = realpath($confirmed);

        return is_string($real) && rtrim($real, DIRECTORY_SEPARATOR) === $wpPath;
    }

    /**
     * @return array<int,string>
     */
    private function wp_cli_base_command(string $wpPath): array
    {
        $wpCli = trim($this->env_value(self::WP_CLI_ENV));
        if ($wpCli === '') {
            $wpCli = 'wp';
        }

        $command = [$wpCli, '--path=' . $wpPath];
        $url = trim($this->env_value(self::WP_URL_ENV));
        if ($url !== '') {
            $command[] = '--url=' . $url;
        }

        return $command;
    }

    /**
     * @param array<string,mixed> $options
     * @return array{zip_path?:string,sha256?:string,source?:string,auto_build_dir?:string,skip?:string}
     */
    private function release_zip(array $options): array
    {
        $explicitZip = trim((string) ($options['zip'] ?? $this->env_value(self::RELEASE_ZIP_ENV)));
        if ($explicitZip !== '') {
            $real = realpath($explicitZip);
            if (!is_string($real) || !is_file($real)) {
                return ['skip' => 'Release ZIP path does not exist or is not a file.'];
            }

            return [
                'zip_path' => $real,
                'sha256' => hash_file('sha256', $real) ?: null,
                'source' => 'explicit',
                'auto_build_dir' => '',
            ];
        }

        $autoBuildDir = '';
        $buildDir = trim((string) ($options['build_dir'] ?? ''));
        if ($buildDir === '') {
            $buildDir = $this->create_temp_build_dir();
            $autoBuildDir = $buildDir;
        }

        $buildOptions = [
            'build_dir' => $buildDir,
        ];
        foreach (['plugin_src', 'monorepo_root'] as $key) {
            if (isset($options[$key]) && trim((string) $options[$key]) !== '') {
                $buildOptions[$key] = (string) $options[$key];
            }
        }

        $result = ($this->releaseBuilder)($buildOptions);
        $zipPath = (string) ($result['zip_path'] ?? '');
        $real = realpath($zipPath);
        if (!is_string($real) || !is_file($real)) {
            throw new RuntimeException('Release ZIP builder did not create a readable ZIP.');
        }

        return [
            'zip_path' => $real,
            'sha256' => (string) ($result['sha256'] ?? (hash_file('sha256', $real) ?: '')),
            'source' => 'built',
            'auto_build_dir' => $autoBuildDir,
        ];
    }

    /**
     * @param array<int,string> $command
     * @param array<string,mixed> $report
     * @return array{exit:int,stdout:string,stderr:string}
     */
    private function require_success(string $label, array $command, array &$report): array
    {
        $result = $this->run_process($label, $command, [], $report);
        if ($result['exit'] !== 0) {
            throw new RuntimeException($this->failed_command_message($label, $result));
        }

        return $result;
    }

    /**
     * @param array<int,string> $command
     * @param array<string,mixed> $report
     * @return array<string,mixed>
     */
    private function require_json_success(string $label, array $command, array &$report): array
    {
        $result = $this->require_success($label, $command, $report);
        $json = trim($result['stdout']);
        try {
            $decoded = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new RuntimeException("Command {$label} did not return valid JSON: " . self::sanitize_output($json));
        }

        if (!is_array($decoded)) {
            throw new RuntimeException("Command {$label} returned non-object JSON.");
        }

        return $decoded;
    }

    /**
     * @param array<int,string> $command
     * @param array<string,string> $env
     * @param array<string,mixed> $report
     * @return array{exit:int,stdout:string,stderr:string}
     */
    private function run_process(string $label, array $command, array $env, array &$report, bool $cleanup = false): array
    {
        $result = ($this->processRunner)($command, $env);
        $normalized = [
            'exit' => max(0, (int) ($result['exit'] ?? 1)),
            'stdout' => is_string($result['stdout'] ?? null) ? $result['stdout'] : '',
            'stderr' => is_string($result['stderr'] ?? null) ? $result['stderr'] : '',
        ];

        $summary = [
            'label' => $label,
            'exit' => $normalized['exit'],
            'command' => $this->sanitized_command_string($command),
            'stdout_excerpt' => self::sanitize_output($normalized['stdout'], self::OUTPUT_EXCERPT_BYTES),
            'stderr_excerpt' => self::sanitize_output($normalized['stderr'], self::OUTPUT_EXCERPT_BYTES),
        ];
        $report[$cleanup ? 'cleanup' : 'commands'][] = $summary;

        return $normalized;
    }

    /**
     * @param array<int,string> $baseCommand
     * @param array<string,mixed> $report
     */
    private function cleanup(array $baseCommand, int $postId, string $autoBuildDir, array &$report): ?Throwable
    {
        $failure = null;

        if ($postId > 0) {
            $deleted = $this->run_process(
                'delete disposable fixture post',
                array_merge($baseCommand, ['post', 'delete', (string) $postId, '--force']),
                [],
                $report,
                true
            );
            if ($deleted['exit'] !== 0) {
                $failure = new RuntimeException($this->failed_command_message('delete disposable fixture post', $deleted));
            }

            $this->run_process(
                'tombstone disposable fixture document',
                array_merge($baseCommand, ['fts', 'delete', (string) $postId]),
                [],
                $report,
                true
            );
        }

        $tempDirs = [];
        if ($autoBuildDir !== '') {
            $tempDirs[] = $autoBuildDir;
        }
        foreach ($this->createdTempBuildDirs as $dir) {
            $tempDirs[] = $dir;
        }
        $tempDirs = array_values(array_unique(array_filter($tempDirs, static fn(string $dir): bool => $dir !== '')));

        foreach ($tempDirs as $tempDir) {
            try {
                ($this->removePath)($tempDir);
                $report['cleanup'][] = [
                    'label' => 'remove temporary release build directory',
                    'exit' => 0,
                    'command' => 'local remove [build-dir]',
                    'stdout_excerpt' => '',
                    'stderr_excerpt' => '',
                ];
            } catch (Throwable $e) {
                $report['cleanup'][] = [
                    'label' => 'remove temporary release build directory',
                    'exit' => 1,
                    'command' => 'local remove [build-dir]',
                    'stdout_excerpt' => '',
                    'stderr_excerpt' => self::sanitize_output($e->getMessage()),
                ];
                $failure ??= $e;
            }
        }
        $this->createdTempBuildDirs = array_values(array_diff($this->createdTempBuildDirs, $tempDirs));

        return $failure;
    }

    /**
     * @param array{exit:int,stdout:string,stderr:string} $result
     */
    private function failed_command_message(string $label, array $result): string
    {
        $detail = trim($result['stderr'] . "\n" . $result['stdout']);

        return "Command failed during {$label} with exit {$result['exit']}."
            . ($detail !== '' ? ' Detail: ' . self::sanitize_output($detail) : '');
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function search_payload_contains_post(array $payload, int $postId): bool
    {
        $results = $payload['results'] ?? (array_is_list($payload) ? $payload : []);
        if (!is_array($results)) {
            return false;
        }

        foreach ($results as $row) {
            if (!is_array($row)) {
                continue;
            }

            foreach (['post_id', 'doc_id', 'ID'] as $field) {
                if ((int) ($row[$field] ?? 0) === $postId) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function search_result_count(array $payload): int
    {
        $results = $payload['results'] ?? (array_is_list($payload) ? $payload : []);

        return is_array($results) ? count($results) : 0;
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    private function compact_payload(array $payload): array
    {
        $encoded = json_encode($payload, JSON_UNESCAPED_SLASHES);
        if (!is_string($encoded) || strlen($encoded) <= 1200) {
            return $payload;
        }

        return [
            'truncated' => true,
            'excerpt' => self::sanitize_output($encoded, 1200),
        ];
    }

    private function token(): string
    {
        return 'wpftssmoke' . substr(hash('sha256', getmypid() . ':' . microtime(true) . ':' . random_int(1, PHP_INT_MAX)), 0, 12);
    }

    private function env_value(string $key): string
    {
        return (string) ($this->env[$key] ?? '');
    }

    /**
     * @return array<string,string>
     */
    private static function current_environment(): array
    {
        $env = getenv();
        if (!is_array($env)) {
            return [];
        }

        $normalized = [];
        foreach ($env as $key => $value) {
            if (is_string($key) && is_scalar($value)) {
                $normalized[$key] = (string) $value;
            }
        }

        return $normalized;
    }

    /**
     * @param array<int,string> $command
     * @param array<string,string> $env
     * @return array{exit:int,stdout:string,stderr:string}
     */
    private function default_process_runner(array $command, array $env): array
    {
        if (!function_exists('proc_open')) {
            return [
                'exit' => 127,
                'stdout' => '',
                'stderr' => 'proc_open() is unavailable.',
            ];
        }

        $baseEnv = getenv();
        if (!is_array($baseEnv)) {
            $baseEnv = [];
        }

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $process = @proc_open($command, $descriptors, $pipes, dirname(__DIR__), array_merge($baseEnv, $env));
        if (!is_resource($process)) {
            return [
                'exit' => 127,
                'stdout' => '',
                'stderr' => 'Could not start process: ' . $this->sanitized_command_string($command),
            ];
        }

        fclose($pipes[0]);
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($process);

        return [
            'exit' => is_int($exit) ? $exit : 1,
            'stdout' => $stdout,
            'stderr' => $stderr,
        ];
    }

    /**
     * @param array<string,mixed> $options
     * @return array{build_dir:string,zip_path:string,sha256:string,removed_paths:string[],prohibited_paths:string[]}
     */
    private function default_release_builder(array $options): array
    {
        require_once __DIR__ . '/build-release-zip.php';
        if (!class_exists('WP_FTS_ReleasePackageBuilder')) {
            throw new RuntimeException('Release package builder class is unavailable.');
        }

        return (new WP_FTS_ReleasePackageBuilder())->build($options);
    }

    private function create_temp_build_dir(): string
    {
        for ($i = 0; $i < 10; $i++) {
            $dir = sys_get_temp_dir() . '/wp-fts-disposable-release-smoke-' . getmypid() . '-' . bin2hex(random_bytes(4));
            if (mkdir($dir, 0777, true)) {
                $this->createdTempBuildDirs[] = $dir;
                return $dir;
            }
        }

        throw new RuntimeException('Could not create a temporary release build directory.');
    }

    private function remove_path(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            if (!unlink($path)) {
                throw new RuntimeException('Could not remove temporary file.');
            }
            return;
        }

        if (!is_dir($path)) {
            return;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $item) {
            if ($item->isDir() && !$item->isLink()) {
                if (!rmdir($item->getPathname())) {
                    throw new RuntimeException('Could not remove temporary directory.');
                }
                continue;
            }

            if (!unlink($item->getPathname())) {
                throw new RuntimeException('Could not remove temporary file.');
            }
        }

        if (!rmdir($path)) {
            throw new RuntimeException('Could not remove temporary directory.');
        }
    }

    /**
     * @param array<int,string> $command
     */
    private function sanitized_command_string(array $command): string
    {
        $parts = [];
        foreach ($command as $arg) {
            $redacted = $arg;
            if (str_starts_with($arg, '--path=')) {
                $redacted = '--path=[wp-path]';
            } elseif (str_starts_with($arg, '--url=')) {
                $redacted = '--url=[wp-url]';
            } elseif (str_starts_with($arg, '/') && str_ends_with(strtolower($arg), '.zip')) {
                $redacted = '[release-zip]';
            } elseif (str_starts_with($arg, '/')) {
                $redacted = '[path]';
            }
            $parts[] = escapeshellarg($redacted);
        }

        return implode(' ', $parts);
    }

    public static function sanitize_output(string $text, int $maxBytes = self::OUTPUT_EXCERPT_BYTES): string
    {
        $text = str_replace(["\r\n", "\r", "\0"], ["\n", "\n", ''], $text);
        $patterns = [
            '/\b([A-Z0-9_]*(?:TOKEN|SECRET|PASSWORD|PASS|KEY|COOKIE|NONCE|AUTH)[A-Z0-9_]*)\s*=\s*([^\s]+)/i'
                => '$1=[redacted]',
            '/(Authorization:\s*(?:Bearer|Basic)\s+)[^\s]+/i'
                => '$1[redacted]',
            '/(api[_-]?key["\']?\s*[:=]\s*["\']?)[^"\'\s,}]+/i'
                => '$1[redacted]',
            '#(?<![\w])/(?:home|Users|tmp|var|private|workspace|mnt|opt)/[^\s"\'<>]+#'
                => '[path]',
        ];

        foreach ($patterns as $pattern => $replacement) {
            $replaced = preg_replace($pattern, $replacement, $text);
            if (is_string($replaced)) {
                $text = $replaced;
            }
        }

        if (strlen($text) <= $maxBytes) {
            return $text;
        }

        return substr($text, 0, max(0, $maxBytes)) . "\n[truncated]";
    }
}

/**
 * @param array{exit:int,status:string,message:string,report:array<string,mixed>} $result
 */
function wp_fts_disposable_release_smoke_write_cli_result(array $result): void
{
    if ($result['status'] === 'skipped') {
        fwrite(STDOUT, "SKIP: {$result['message']}\n");
        return;
    }

    $json = json_encode($result['report'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    $json = is_string($json) ? $json : '{}';
    if ($result['status'] === 'failed') {
        fwrite(STDERR, "FAIL: {$result['message']}\n{$json}\n");
        return;
    }

    fwrite(STDOUT, $json . "\nPASS: {$result['message']}\n");
}

if (PHP_SAPI === 'cli' && realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    try {
        $options = WP_FTS_DisposableReleaseSmokeRunner::parse_cli_options(array_slice($argv, 1));
        if (!empty($options['help'])) {
            fwrite(STDOUT, WP_FTS_DisposableReleaseSmokeRunner::usage());
            exit(0);
        }

        $result = (new WP_FTS_DisposableReleaseSmokeRunner())->run($options);
        wp_fts_disposable_release_smoke_write_cli_result($result);
        exit($result['exit']);
    } catch (Throwable $e) {
        fwrite(STDERR, 'FAIL: ' . WP_FTS_DisposableReleaseSmokeRunner::sanitize_output($e->getMessage()) . "\n");
        exit(1);
    }
}
