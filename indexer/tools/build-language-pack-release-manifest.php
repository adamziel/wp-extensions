<?php
declare(strict_types=1);

/**
 * Builds and signs the release manifest consumed by the in-plugin language-pack
 * downloader.
 */
final class WP_FTS_LanguagePackReleaseManifestBuilder
{
    public const SCHEMA = 'language-fts-language-pack-release-manifest-v1';
    public const ASSET_NAME = 'language-fts-extended-language-packs.zip';
    public const MANIFEST_NAME = 'language-fts-extended-language-packs.manifest.json';
    public const SIGNATURE_NAME = 'language-fts-extended-language-packs.manifest.json.sig';

    private const DEFAULT_SIGNING_KEY_ENV = 'LANGUAGE_FTS_LANGUAGE_PACK_MANIFEST_SIGNING_KEY';

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

            foreach (['zip', 'asset-url', 'output', 'signature-output', 'version', 'signing-key-env'] as $name) {
                $prefix = "--{$name}=";
                if (str_starts_with($arg, $prefix)) {
                    $options[str_replace('-', '_', $name)] = substr($arg, strlen($prefix));
                    continue 2;
                }
            }

            throw new InvalidArgumentException("Unknown option: {$arg}");
        }

        return $options;
    }

    /**
     * @param array<string,mixed> $options
     * @return array<string,mixed>
     */
    public function build(array $options): array
    {
        if (!function_exists('sodium_crypto_sign_detached') || !function_exists('sodium_crypto_sign_publickey_from_secretkey')) {
            throw new RuntimeException('The PHP sodium extension is required to sign the language-pack release manifest.');
        }

        $zipPath = self::existing_file((string) ($options['zip'] ?? ''), 'language-pack ZIP');
        $assetUrl = self::required_url((string) ($options['asset_url'] ?? ''), 'asset URL');
        $version = self::required_value((string) ($options['version'] ?? ''), 'release version');
        $manifestPath = (string) ($options['output'] ?? (dirname($zipPath) . '/' . self::MANIFEST_NAME));
        $signaturePath = (string) ($options['signature_output'] ?? (dirname($zipPath) . '/' . self::SIGNATURE_NAME));
        $signingKeyEnv = (string) ($options['signing_key_env'] ?? self::DEFAULT_SIGNING_KEY_ENV);

        if (basename($zipPath) !== self::ASSET_NAME) {
            throw new InvalidArgumentException('The language-pack ZIP must be named ' . self::ASSET_NAME . '.');
        }
        if (!str_ends_with($assetUrl, '/' . self::ASSET_NAME)) {
            throw new InvalidArgumentException('The release asset URL must point to ' . self::ASSET_NAME . '.');
        }
        if (preg_match('/\Alanguage-fts-v[A-Za-z0-9._-]+\z/', $version) !== 1) {
            throw new InvalidArgumentException('The release version must match language-fts-v[A-Za-z0-9._-]+.');
        }
        if (!str_contains($assetUrl, '/releases/download/' . $version . '/')) {
            throw new InvalidArgumentException('The release asset URL must use the same release version recorded in the manifest.');
        }

        $sha256 = hash_file('sha256', $zipPath);
        if (!is_string($sha256)) {
            throw new RuntimeException("Could not hash language-pack ZIP: {$zipPath}");
        }

        $bytes = filesize($zipPath);
        if (!is_int($bytes) || $bytes < 1) {
            throw new RuntimeException("Could not determine language-pack ZIP size: {$zipPath}");
        }

        $secretKey = self::signing_key_from_environment($signingKeyEnv);
        $publicKey = sodium_crypto_sign_publickey_from_secretkey($secretKey);

        $manifest = [
            'schema' => self::SCHEMA,
            'version' => $version,
            'generated_at' => gmdate('Y-m-d\TH:i:s\Z'),
            'asset' => [
                'name' => self::ASSET_NAME,
                'url' => $assetUrl,
                'sha256' => $sha256,
                'bytes' => $bytes,
            ],
            'verification' => [
                'signature_algorithm' => 'ed25519',
                'public_key_sha256' => hash('sha256', $publicKey),
            ],
        ];

        $json = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if (!is_string($json)) {
            throw new RuntimeException('Could not encode language-pack release manifest JSON.');
        }
        $json .= "\n";

        $signature = sodium_crypto_sign_detached($json, $secretKey);
        if (function_exists('sodium_memzero')) {
            sodium_memzero($secretKey);
        }

        self::write_file($manifestPath, $json);
        self::write_file($signaturePath, base64_encode($signature) . "\n");

        return [
            'schema' => self::SCHEMA,
            'version' => $version,
            'manifest_path' => $manifestPath,
            'signature_path' => $signaturePath,
            'asset_name' => self::ASSET_NAME,
            'asset_url' => $assetUrl,
            'sha256' => $sha256,
            'bytes' => $bytes,
            'public_key_sha256' => hash('sha256', $publicKey),
        ];
    }

    public static function usage(): string
    {
        return implode("\n", [
            'Usage: php indexer/tools/build-language-pack-release-manifest.php [options]',
            '',
            'Options:',
            '  --zip=PATH                 Built language-fts-extended-language-packs.zip path.',
            '  --asset-url=URL            Final GitHub release asset URL for the ZIP.',
            '  --version=VALUE            Release tag/version recorded in the manifest.',
            '  --output=PATH              Manifest path. Defaults next to the ZIP.',
            '  --signature-output=PATH    Detached Ed25519 signature path. Defaults next to the ZIP.',
            '  --signing-key-env=NAME     Base64 Ed25519 secret-key env var name.',
            '  -h, --help                 Show this help.',
            '',
        ]);
    }

    private static function existing_file(string $path, string $label): string
    {
        $path = trim($path);
        if ($path === '' || !is_file($path)) {
            throw new RuntimeException("Missing {$label}: {$path}");
        }

        return $path;
    }

    private static function required_value(string $value, string $label): string
    {
        $value = trim($value);
        if ($value === '') {
            throw new InvalidArgumentException("Missing {$label}.");
        }

        return $value;
    }

    private static function required_url(string $value, string $label): string
    {
        $url = self::required_value($value, $label);
        if (!str_starts_with($url, 'https://')) {
            throw new InvalidArgumentException("The {$label} must use HTTPS.");
        }

        return $url;
    }

    private static function signing_key_from_environment(string $envName): string
    {
        $envName = self::required_value($envName, 'signing-key environment variable name');
        $encoded = getenv($envName);
        if (!is_string($encoded) || trim($encoded) === '') {
            throw new RuntimeException("Missing language-pack release manifest signing key in {$envName}.");
        }

        $secretKey = base64_decode(trim($encoded), true);
        $expectedBytes = defined('SODIUM_CRYPTO_SIGN_SECRETKEYBYTES') ? SODIUM_CRYPTO_SIGN_SECRETKEYBYTES : 64;
        if (!is_string($secretKey) || strlen($secretKey) !== $expectedBytes) {
            throw new RuntimeException("Invalid Ed25519 secret key in {$envName}.");
        }

        return $secretKey;
    }

    private static function write_file(string $path, string $contents): void
    {
        $directory = dirname($path);
        if (!is_dir($directory) && !mkdir($directory, 0777, true) && !is_dir($directory)) {
            throw new RuntimeException("Could not create directory: {$directory}");
        }
        if (file_put_contents($path, $contents, LOCK_EX) === false) {
            throw new RuntimeException("Could not write file: {$path}");
        }
    }
}

if (PHP_SAPI === 'cli' && realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    try {
        $options = WP_FTS_LanguagePackReleaseManifestBuilder::parse_cli_options(array_slice($argv, 1));
        if (!empty($options['help'])) {
            fwrite(STDOUT, WP_FTS_LanguagePackReleaseManifestBuilder::usage());
            exit(0);
        }

        $result = (new WP_FTS_LanguagePackReleaseManifestBuilder())->build($options);
        fwrite(STDOUT, json_encode(['status' => 'ok'] + $result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
    } catch (Throwable $e) {
        fwrite(STDERR, "Language-pack release manifest build failed: {$e->getMessage()}\n");
        exit(1);
    }
}
