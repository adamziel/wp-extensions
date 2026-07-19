<?php
declare(strict_types=1);

/** Typed failure for analyzer configuration that exceeds a fixed envelope. */
final class WP_FTS_Analyzer_Config_Limit_Exceeded extends InvalidArgumentException
{
    public function __construct(
        public readonly string $reason_code,
        string $message
    ) {
        parent::__construct($message);
    }
}

/**
 * Hard limits for analyzer options and local analyzer-pack manifests.
 *
 * Analyzer configuration is read at the first search/worker boundary. Keep its
 * validation independent of database budgets: a corrupt option must fail before
 * copying a giant map, walking an unbounded graph, or probing attacker-selected
 * filesystem paths.
 */
final class WP_FTS_Analyzer_Config_Limits
{
    private const LANGUAGE_MAP_OPTION_KEYS = [
        'stemmers_by_lang',
        'stemmers',
        'lemmatizer_packs_by_lang',
        'lemma_packs_by_lang',
        'tokenizer_packs_by_lang',
        'cjk_tokenizer_packs_by_lang',
        'cjk_segmenter_packs_by_lang',
        'segmenter_packs_by_lang',
    ];
    public const MAX_CONFIGURED_LANGUAGES = 32;
    public const MAX_OPTION_GRAPH_NODES = 2048;
    public const MAX_OPTION_GRAPH_BYTES = 65536;
    public const MAX_OPTION_GRAPH_DEPTH = 8;
    public const MAX_OPTION_ARRAY_ITEMS = 256;
    public const MAX_OPTION_KEY_BYTES = 128;
    public const MAX_OPTION_SCALAR_BYTES = 4096;
    public const MAX_LANGUAGE_BYTES = 64;
    public const MAX_PATH_BYTES = 4096;
    public const MAX_PACK_OPTION_ITEMS = 32;
    public const MAX_MANIFEST_BYTES = 65536;
    public const MAX_MANIFEST_GRAPH_NODES = 2048;
    public const MAX_MANIFEST_GRAPH_DEPTH = 8;
    public const MAX_RUNTIME_FILES = 64;
    public const MAX_LOOKUP_BLOCKS_PER_FILE = 256;
    public const MAX_LOOKUP_BLOCKS_PER_PACK = 4096;
    public const MAX_CONFIGURED_RUNTIME_FILES = 128;
    public const MAX_CONFIGURED_LOOKUP_BLOCKS = 4096;

    /** Reject an option graph before downstream code copies or normalizes it. */
    public static function assert_option_graph(mixed $value, string $label = 'Analyzer options'): void
    {
        $nodes = 0;
        $bytes = 0;
        self::walk_graph(
            $value,
            0,
            $nodes,
            $bytes,
            self::MAX_OPTION_GRAPH_NODES,
            self::MAX_OPTION_GRAPH_BYTES,
            self::MAX_OPTION_GRAPH_DEPTH,
            self::MAX_OPTION_ARRAY_ITEMS,
            self::MAX_OPTION_KEY_BYTES,
            self::MAX_OPTION_SCALAR_BYTES,
            $label,
            'analyzer_option'
        );
    }

    /** Check known maps by O(1) cardinality before walking the complete graph. */
    public static function assert_analyzer_options(array $options, string $label = 'Analyzer options'): void
    {
        $configuredLanguages = [];
        foreach (self::LANGUAGE_MAP_OPTION_KEYS as $key) {
            if (!array_key_exists($key, $options)) {
                continue;
            }
            if (!is_array($options[$key])) {
                throw new WP_FTS_Analyzer_Config_Limit_Exceeded(
                    'language_map_type',
                    "{$label} {$key} must be an array."
                );
            }
            self::assert_language_map($options[$key], "{$label} {$key}");
            foreach ($options[$key] as $language => $_option) {
                $identity = strtolower(str_replace('_', '-', trim((string) $language)));
                if ($identity === '') {
                    continue;
                }
                $configuredLanguages[$identity] = true;
                if (count($configuredLanguages) > self::MAX_CONFIGURED_LANGUAGES) {
                    throw new WP_FTS_Analyzer_Config_Limit_Exceeded(
                        'configured_languages',
                        "{$label} exceeds the " . self::MAX_CONFIGURED_LANGUAGES . '-language limit across analyzer maps.'
                    );
                }
            }
        }
        foreach (['polish_lemma_pack', 'polish_lemmatizer_pack'] as $key) {
            if (array_key_exists($key, $options)) {
                self::assert_pack_option($options[$key], "{$label} {$key}");
            }
        }
        self::assert_callback_captures($options, $label);
        self::assert_option_graph($options, $label);
    }

    /** Reject a decoded manifest before shape checks iterate its file map. */
    public static function assert_manifest_graph(array $manifest): void
    {
        $nodes = 0;
        $bytes = 0;
        self::walk_graph(
            $manifest,
            0,
            $nodes,
            $bytes,
            self::MAX_MANIFEST_GRAPH_NODES,
            self::MAX_MANIFEST_BYTES,
            self::MAX_MANIFEST_GRAPH_DEPTH,
            self::MAX_OPTION_ARRAY_ITEMS,
            self::MAX_OPTION_KEY_BYTES,
            self::MAX_OPTION_SCALAR_BYTES,
            'Analyzer pack manifest',
            'analyzer_manifest'
        );
    }

    /** Assert one language-keyed configuration map without traversing an over-cap map. */
    public static function assert_language_map(mixed $map, string $label): void
    {
        if (!is_array($map)) {
            return;
        }
        if (count($map) > self::MAX_CONFIGURED_LANGUAGES) {
            throw new WP_FTS_Analyzer_Config_Limit_Exceeded(
                'configured_languages',
                "{$label} exceeds the " . self::MAX_CONFIGURED_LANGUAGES . '-language limit.'
            );
        }

        foreach ($map as $language => $option) {
            if (strlen((string) $language) > self::MAX_LANGUAGE_BYTES) {
                throw new WP_FTS_Analyzer_Config_Limit_Exceeded(
                    'language_bytes',
                    "{$label} contains a language key above " . self::MAX_LANGUAGE_BYTES . ' bytes.'
                );
            }
            self::assert_pack_option($option, "{$label} entry");
        }
    }

    /**
     * Merge precedence-ordered language maps without an unbounded array_replace().
     *
     * @param array<int,array<mixed>> $maps
     * @return array<mixed>
     */
    public static function merge_language_maps(array $maps, string $label): array
    {
        $merged = [];
        foreach ($maps as $map) {
            self::assert_language_map($map, $label);
            foreach ($map as $language => $option) {
                $merged[$language] = $option;
                if (count($merged) > self::MAX_CONFIGURED_LANGUAGES) {
                    throw new WP_FTS_Analyzer_Config_Limit_Exceeded(
                        'configured_languages',
                        "{$label} exceeds the " . self::MAX_CONFIGURED_LANGUAGES . '-language limit across aliases.'
                    );
                }
            }
        }

        return $merged;
    }

    /** Assert a supported per-language pack option before resolving any path. */
    public static function assert_pack_option(mixed $option, string $label = 'Analyzer pack option'): void
    {
        if (is_string($option)) {
            self::assert_path($option, $label);
            return;
        }
        if (!is_array($option)) {
            return;
        }
        if (count($option) > self::MAX_PACK_OPTION_ITEMS) {
            throw new WP_FTS_Analyzer_Config_Limit_Exceeded(
                'pack_option_items',
                "{$label} exceeds the " . self::MAX_PACK_OPTION_ITEMS . '-item limit.'
            );
        }
        self::assert_option_graph($option, $label);

        foreach (['manifest', 'manifest_path', 'path', 'source_file', 'dict_path', 'dictionary', 'source_root', 'root', 'submodule_path'] as $key) {
            if (isset($option[$key]) && is_scalar($option[$key])) {
                self::assert_path((string) $option[$key], "{$label} {$key}");
            }
        }
    }

    /** Assert a path before trim(), realpath(), is_file(), or a stream open. */
    public static function assert_path(string $path, string $label = 'Analyzer pack path'): void
    {
        if (strlen($path) > self::MAX_PATH_BYTES) {
            throw new WP_FTS_Analyzer_Config_Limit_Exceeded(
                'path_bytes',
                "{$label} exceeds the " . self::MAX_PATH_BYTES . '-byte path limit.'
            );
        }
    }

    /**
     * Bound closure state before a constructor opens any configured pack files.
     *
     * Reflection returns captured arrays copy-on-write, so an oversized capture
     * is rejected by the first bounded count() without duplicating or hashing it.
     * Object-backed extension points remain opaque: calling their methods here
     * would execute user code during configuration validation.
     *
     * @param array<string,mixed> $options
     */
    private static function assert_callback_captures(array $options, string $label): void
    {
        $captures = [];
        foreach ([
            'stemmer',
            'polish_stemmer',
            'cjk_tokenizer',
            'cjk_segmenter',
            'token_normalizer',
            'document_language_resolver',
            'query_language_resolver',
            'query_term_language_resolver',
            'term_language_resolver',
            'html_processor_factory',
        ] as $key) {
            if (($options[$key] ?? null) instanceof Closure) {
                $captures[$key] = (new ReflectionFunction($options[$key]))->getStaticVariables();
            }
        }

        foreach (['stemmers_by_lang', 'stemmers'] as $key) {
            if (!isset($options[$key]) || !is_array($options[$key])) {
                continue;
            }
            foreach ($options[$key] as $language => $stemmer) {
                if ($stemmer instanceof Closure) {
                    $captures[$key . ':' . (string) $language] = (new ReflectionFunction($stemmer))->getStaticVariables();
                }
            }
        }

        if ($captures !== []) {
            self::assert_option_graph($captures, "{$label} callback captured state");
        }
    }

    private static function walk_graph(
        mixed $value,
        int $depth,
        int &$nodes,
        int &$bytes,
        int $maxNodes,
        int $maxBytes,
        int $maxDepth,
        int $maxArrayItems,
        int $maxKeyBytes,
        int $maxScalarBytes,
        string $label,
        string $reasonPrefix
    ): void {
        if ($depth > $maxDepth) {
            throw new WP_FTS_Analyzer_Config_Limit_Exceeded(
                $reasonPrefix . '_depth',
                "{$label} exceeds the {$maxDepth}-level nesting limit."
            );
        }
        if (++$nodes > $maxNodes) {
            throw new WP_FTS_Analyzer_Config_Limit_Exceeded(
                $reasonPrefix . '_nodes',
                "{$label} exceeds the {$maxNodes}-node limit."
            );
        }

        if (!is_array($value)) {
            if (is_string($value)) {
                $length = strlen($value);
                if ($length > $maxScalarBytes) {
                    throw new WP_FTS_Analyzer_Config_Limit_Exceeded(
                        $reasonPrefix . '_scalar_bytes',
                        "{$label} contains a scalar above {$maxScalarBytes} bytes."
                    );
                }
                $bytes += $length;
                self::assert_graph_bytes($bytes, $maxBytes, $label, $reasonPrefix);
            }
            // Objects, resources, and iterables are opaque leaves. Supported
            // extension points validate their exact type without invoking magic
            // accessors or consuming generators here.
            return;
        }

        if (count($value) > $maxArrayItems) {
            throw new WP_FTS_Analyzer_Config_Limit_Exceeded(
                $reasonPrefix . '_array_items',
                "{$label} contains an array above {$maxArrayItems} items."
            );
        }
        foreach ($value as $key => $item) {
            $keyLength = is_string($key) ? strlen($key) : 0;
            if ($keyLength > $maxKeyBytes) {
                throw new WP_FTS_Analyzer_Config_Limit_Exceeded(
                    $reasonPrefix . '_key_bytes',
                    "{$label} contains a key above {$maxKeyBytes} bytes."
                );
            }
            $bytes += $keyLength;
            self::assert_graph_bytes($bytes, $maxBytes, $label, $reasonPrefix);
            self::walk_graph(
                $item,
                $depth + 1,
                $nodes,
                $bytes,
                $maxNodes,
                $maxBytes,
                $maxDepth,
                $maxArrayItems,
                $maxKeyBytes,
                $maxScalarBytes,
                $label,
                $reasonPrefix
            );
        }
    }

    private static function assert_graph_bytes(int $bytes, int $maxBytes, string $label, string $reasonPrefix): void
    {
        if ($bytes > $maxBytes) {
            throw new WP_FTS_Analyzer_Config_Limit_Exceeded(
                $reasonPrefix . '_bytes',
                "{$label} exceeds the {$maxBytes}-byte scalar/key limit."
            );
        }
    }
}
