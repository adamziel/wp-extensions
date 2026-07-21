<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/src/bootstrap.php';

/** @return array{VmHWM_bytes:?int,VmRSS_bytes:?int} */
function wp_fts_inline_run_proc_status(): array
{
    if (!is_readable('/proc/self/status')) {
        return ['VmHWM_bytes' => null, 'VmRSS_bytes' => null];
    }

    $values = [];
    $handle = fopen('/proc/self/status', 'rb');
    if (!is_resource($handle)) {
        return ['VmHWM_bytes' => null, 'VmRSS_bytes' => null];
    }
    try {
        while (($line = fgets($handle)) !== false) {
            $separator = strpos($line, ':');
            if ($separator === false) {
                continue;
            }
            $key = substr($line, 0, $separator);
            if (!in_array($key, ['VmHWM', 'VmRSS'], true)) {
                continue;
            }
            $value = trim(substr($line, $separator + 1));
            $space = strpos($value, ' ');
            if ($space === false) {
                continue;
            }
            $kilobytes = substr($value, 0, $space);
            if ($kilobytes !== '' && strspn($kilobytes, '0123456789') === strlen($kilobytes) && strtolower(trim(substr($value, $space + 1))) === 'kb') {
                $values[$key] = (int) $kilobytes * 1024;
            }
        }
    } finally {
        fclose($handle);
    }

    return [
        'VmHWM_bytes' => $values['VmHWM'] ?? null,
        'VmRSS_bytes' => $values['VmRSS'] ?? null,
    ];
}

try {
    $variant = $argv[1] ?? '';
    if (!in_array($variant, ['exact', 'worst'], true)) {
        throw new InvalidArgumentException('Expected the inline run variant exact or worst.');
    }

    $providedTokens = $variant === 'exact'
        ? WP_FTS_Analysis_Limits::MAX_LEXICAL_RUN_BYTES
        : WP_FTS_Analysis_Limits::MAX_HTML_MARKUP_TOKENS;
    $processor = new class ($providedTokens) {
        public int $calls = 0;

        /** Fix the exact event count at either admitted or rejected boundary. */
        public function __construct(private int $providedTokens)
        {
        }

        /** Stream one tiny text event per call without retaining prior events. */
        public function next_token(): bool
        {
            $this->calls++;

            return $this->calls <= $this->providedTokens;
        }

        /** Fail if the streaming analyzer requests an unbounded ancestor snapshot. */
        public function get_breadcrumbs(): array
        {
            throw new RuntimeException('the event-stream analyzer must never request breadcrumbs');
        }

        /** Keep every synthetic text event at the root depth. */
        public function get_current_depth(): int
        {
            return 0;
        }

        /** Present every event as text so one lexical run spans all tokens. */
        public function get_token_type(): string
        {
            return '#text';
        }

        /** Grow the lexical run one byte without growing each event. */
        public function get_modifiable_text(): string
        {
            return 'a';
        }

        /** Keep tag-specific analyzer branches out of the lexical-run fixture. */
        public function get_tag(): ?string
        {
            return null;
        }

        /** Model text events rather than synthetic closing tags. */
        public function is_tag_closer(): bool
        {
            return false;
        }

        /** Prevent stack growth unrelated to the lexical-run boundary. */
        public function expects_closer(): bool
        {
            return false;
        }

        /** Text events expose no attributes. */
        public function get_attribute(string $_name): ?string
        {
            return null;
        }
    };

    $peakBefore = memory_get_peak_usage(true);
    $started = microtime(true);
    $error = null;
    $terms = [];
    try {
        $analyzer = new WP_FTS_Analyzer([
            'auto_detect_language' => false,
            'enable_stemming' => false,
            'document_lang' => 'en',
            'max_term_bytes' => WP_FTS_Analysis_Limits::MAX_LEXICAL_RUN_BYTES,
            'html_processor_factory' => static fn(): object => $processor,
        ]);
        $terms = $analyzer->analyze_content('<p>aa</p>', ['document_lang' => 'en']);
    } catch (Throwable $caught) {
        $error = [
            'class' => get_class($caught),
            'reason_code' => $caught instanceof WP_FTS_Analysis_Limit_Exceeded ? $caught->reason_code : '',
            'message' => $caught->getMessage(),
        ];
    }
    $peakAfter = memory_get_peak_usage(true);

    echo json_encode([
        'variant' => $variant,
        'provided_tokens' => $providedTokens,
        'processor_calls' => $processor->calls,
        'occurrences' => count($terms),
        'first_term_bytes' => strlen((string) ($terms[0]['term'] ?? '')),
        'error' => $error,
        'elapsed_seconds' => microtime(true) - $started,
        'php_peak_bytes' => $peakAfter,
        'php_peak_delta_bytes' => max(0, $peakAfter - $peakBefore),
        'proc_status' => wp_fts_inline_run_proc_status(),
    ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), "\n";
} catch (Throwable $error) {
    fwrite(STDERR, get_class($error) . ': ' . $error->getMessage() . "\n");
    exit(1);
}
