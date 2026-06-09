<?php
declare(strict_types=1);

/**
 * Builds term-frequency rows for WordPress posts.
 */
final class Language_FTS_Playground_Indexer
{
    /** @var array<int,string> */
    private const INDEXED_FIELDS = ['title', 'excerpt', 'content', 'alt'];

    /** @var array<string,bool> */
    private const FALLBACK_SKIPPED_ELEMENTS = [
        'script' => true,
        'style' => true,
        'template' => true,
        'noscript' => true,
        'svg' => true,
        'math' => true,
    ];

    /** @var array<string,bool> */
    private const FALLBACK_VOID_ELEMENTS = [
        'area' => true,
        'base' => true,
        'br' => true,
        'col' => true,
        'embed' => true,
        'hr' => true,
        'img' => true,
        'input' => true,
        'link' => true,
        'meta' => true,
        'param' => true,
        'source' => true,
        'track' => true,
        'wbr' => true,
    ];

    public function __construct(
        private Language_FTS_Playground_Storage_Interface $storage,
        private Language_FTS_Playground_Analyzer $analyzer
    ) {
    }

    public function index_post(object $post): void
    {
        $post_id = $this->post_id($post);
        if ($post_id <= 0) {
            return;
        }

        $status = $this->post_string($post, 'post_status');
        if ($status !== 'publish' || $this->post_string($post, 'post_password') !== '') {
            $this->storage->delete_document($post_id);
            return;
        }

        $fallback_language = $this->analyzer->resolve_post_language($post);
        $title = $this->analyzer->normalize_plain_text($this->post_string($post, 'post_title'));
        $excerpt = $this->analyzer->normalize_plain_text($this->post_string($post, 'post_excerpt'));
        $content_html = $this->post_string($post, 'post_content');

        $segments = [
            [
                'field' => 'title',
                'text' => $title,
                'language' => $fallback_language,
                'language_provenance' => 'post',
            ],
            [
                'field' => 'excerpt',
                'text' => $excerpt,
                'language' => $fallback_language,
                'language_provenance' => 'post',
            ],
        ];
        foreach ($this->extract_content_segment_details($content_html, $fallback_language) as $segment) {
            $field = (string) ($segment['field'] ?? '');
            if (!in_array($field, self::INDEXED_FIELDS, true)) {
                continue;
            }

            $segments[] = [
                'field' => $field,
                'text' => (string) ($segment['text'] ?? ''),
                'language' => (string) ($segment['language'] ?? $fallback_language),
                'language_provenance' => (string) ($segment['language_provenance'] ?? 'post'),
            ];
        }

        $segments_by_language = [];
        foreach ($segments as $segment) {
            $language = $this->analyzer->canonical_language((string) $segment['language']);
            $segments_by_language[$language][] = [
                'field' => (string) $segment['field'],
                'text' => (string) $segment['text'],
                'language_provenance' => (string) ($segment['language_provenance'] ?? 'post'),
            ];
        }

        $partitions = [];
        foreach ($segments_by_language as $language => $language_segments) {
            $field_segments = [];
            $field_metadata = [];
            foreach (self::INDEXED_FIELDS as $field) {
                $field_segments[$field] = [];
            }

            $position_segments = [];
            foreach ($language_segments as $segment) {
                $field = (string) $segment['field'];
                if (!isset($field_segments[$field])) {
                    continue;
                }

                $text = $this->analyzer->normalize_plain_text((string) $segment['text']);
                if ($text === '') {
                    continue;
                }

                $field_segments[$field][] = $text;
                $field_metadata[$field] = $this->select_field_metadata(
                    $field_metadata[$field] ?? null,
                    $language,
                    (string) ($segment['language_provenance'] ?? 'fallback')
                );
                $position_segments[] = $text;
            }

            $document_length = 0;
            $field_texts = [];
            $field_term_frequencies = [];
            foreach (self::INDEXED_FIELDS as $field) {
                $field_texts[$field] = $this->analyzer->normalize_plain_text(implode(' ', $field_segments[$field]));
                $terms = $this->analyzer->analyze_text($field_texts[$field], $language);
                $document_length += count($terms);
                $field_term_frequencies[$field] = [];
                foreach ($terms as $term) {
                    $field_term_frequencies[$field][$term] = ($field_term_frequencies[$field][$term] ?? 0) + 1;
                }
                $field_metadata[$field] = $field_metadata[$field] ?? [
                    'language' => $language,
                    'language_provenance' => 'fallback',
                ];
            }

            $analyzed_document = $this->analyzer->analyze_segments_with_positions($position_segments, $language);
            $partitions[] = [
                'language' => $language,
                'title' => $title,
                'status' => $status,
                'document_length' => $document_length,
                'field_term_frequencies' => $field_term_frequencies,
                'field_texts' => $field_texts,
                'field_metadata' => $field_metadata,
                'term_positions' => $analyzed_document['positions'],
            ];
        }

        $this->storage->replace_document_partitions(
            $post_id,
            array_values($partitions)
        );
    }

    /**
     * @return array<int,array{field:string,text:string,language:string,language_provenance:string}>
     */
    private function extract_content_segment_details(string $html, string $fallback_language): array
    {
        if ($this->has_structured_html_language_reader() || !$this->html_may_contain_language_attributes($html)) {
            return $this->analyzer->extract_searchable_field_segment_details($html, $fallback_language, 'post');
        }

        return $this->extract_language_aware_segments_without_dom($html, $fallback_language);
    }

    /**
     * @return array<int,array{field:string,text:string,language:string,language_provenance:string}>
     */
    private function extract_language_aware_segments_without_dom(string $html, string $fallback_language): array
    {
        if (trim($html) === '') {
            return [];
        }

        $fallback_context = [
            'language' => $this->analyzer->canonical_language($fallback_language),
            'language_provenance' => 'post',
        ];
        $context_stack = [
            [
                'tag' => '',
                'language' => $fallback_context['language'],
                'language_provenance' => $fallback_context['language_provenance'],
            ],
        ];
        $skip_stack = [];
        $segments = [];
        $current_text = '';
        $current_context = $fallback_context;

        $tokens = preg_split('/(<!--.*?-->|<[^>]+>)/s', $html, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);
        foreach (is_array($tokens) ? $tokens : [$html] as $token) {
            $token = (string) $token;
            if ($token === '') {
                continue;
            }

            if ($token[0] !== '<') {
                if ($skip_stack === []) {
                    $this->append_fallback_content_text($segments, $current_text, $current_context, $this->current_fallback_context($context_stack), $token);
                }
                continue;
            }

            if (str_starts_with($token, '<!--')) {
                if ($skip_stack === []) {
                    $this->flush_fallback_content_segment($segments, $current_text, $current_context);
                }
                continue;
            }

            $tag = $this->parse_fallback_tag($token);
            if ($tag === null) {
                continue;
            }

            if ($skip_stack !== []) {
                if (!$tag['is_closing'] && isset(self::FALLBACK_SKIPPED_ELEMENTS[$tag['name']]) && !$tag['self_closing']) {
                    $skip_stack[] = $tag['name'];
                    continue;
                }

                if ($tag['is_closing'] && $tag['name'] === end($skip_stack)) {
                    array_pop($skip_stack);
                }
                continue;
            }

            if ($tag['is_closing']) {
                $this->pop_fallback_context($context_stack, $tag['name']);
                continue;
            }

            if (isset(self::FALLBACK_SKIPPED_ELEMENTS[$tag['name']])) {
                $this->flush_fallback_content_segment($segments, $current_text, $current_context);
                if (!$tag['self_closing']) {
                    $skip_stack[] = $tag['name'];
                }
                continue;
            }

            $context = $this->fallback_context_for_tag($token, $this->current_fallback_context($context_stack));
            if ($tag['name'] === 'img') {
                $attributes = $this->fallback_tag_attributes($token);
                if (array_key_exists('alt', $attributes)) {
                    $this->flush_fallback_content_segment($segments, $current_text, $current_context);
                    $this->append_fallback_segment($segments, 'alt', $attributes['alt'], $context);
                }
            }

            if (!$tag['self_closing']) {
                $context_stack[] = [
                    'tag' => $tag['name'],
                    'language' => $context['language'],
                    'language_provenance' => $context['language_provenance'],
                ];
            }
        }

        $this->flush_fallback_content_segment($segments, $current_text, $current_context);

        return $segments;
    }

    private function has_structured_html_language_reader(): bool
    {
        return class_exists(DOMDocument::class) || class_exists('WP_HTML_Processor');
    }

    private function html_may_contain_language_attributes(string $html): bool
    {
        return preg_match('/\s(?:lang|xml:lang)\s*=/i', $html) === 1;
    }

    /**
     * @return array{name:string,is_closing:bool,self_closing:bool}|null
     */
    private function parse_fallback_tag(string $token): ?array
    {
        if (preg_match('/^<\s*(\/)?\s*([a-zA-Z][a-zA-Z0-9:-]*)\b(.*?)>$/s', $token, $matches) !== 1) {
            return null;
        }

        $name = strtolower($matches[2]);
        $is_closing = ($matches[1] ?? '') === '/';
        $suffix = rtrim((string) ($matches[3] ?? ''));
        $self_closing = !$is_closing && (str_ends_with($suffix, '/') || isset(self::FALLBACK_VOID_ELEMENTS[$name]));

        return [
            'name' => $name,
            'is_closing' => $is_closing,
            'self_closing' => $self_closing,
        ];
    }

    /**
     * @return array<string,string>
     */
    private function fallback_tag_attributes(string $token): array
    {
        $attributes = [];
        if (preg_match_all('/([^\s\/=<>"\']+)\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s"\'<>]+))/u', $token, $matches, PREG_SET_ORDER) !== false) {
            foreach ($matches as $match) {
                $name = strtolower((string) $match[1]);
                $value = (string) ($match[2] ?? '');
                if ($value === '' && array_key_exists(3, $match)) {
                    $value = (string) $match[3];
                }
                if ($value === '' && array_key_exists(4, $match)) {
                    $value = (string) $match[4];
                }
                $attributes[$name] = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            }
        }

        return $attributes;
    }

    /**
     * @param array{language:string,language_provenance:string} $parent_context
     * @return array{language:string,language_provenance:string}
     */
    private function fallback_context_for_tag(string $token, array $parent_context): array
    {
        $attributes = $this->fallback_tag_attributes($token);
        $language = $attributes['lang'] ?? $attributes['xml:lang'] ?? null;
        if ($language === null) {
            return $parent_context;
        }

        $canonical = $this->fallback_canonical_language_or_null($language);
        if ($canonical === null) {
            return $parent_context;
        }

        return [
            'language' => $canonical,
            'language_provenance' => 'html_lang',
        ];
    }

    private function fallback_canonical_language_or_null(string $language): ?string
    {
        $candidate = strtolower(trim($language));
        if ($candidate === '') {
            return null;
        }

        $candidate = str_replace('_', '-', $candidate);
        $primary = explode('-', $candidate, 2)[0];

        return in_array($primary, $this->analyzer->enabled_languages(), true) ? $primary : null;
    }

    /**
     * @param array<int,array{tag:string,language:string,language_provenance:string}> $context_stack
     * @return array{language:string,language_provenance:string}
     */
    private function current_fallback_context(array $context_stack): array
    {
        $context = $context_stack[count($context_stack) - 1] ?? ['language' => 'en', 'language_provenance' => 'fallback'];

        return [
            'language' => (string) $context['language'],
            'language_provenance' => (string) $context['language_provenance'],
        ];
    }

    /**
     * @param array<int,array{tag:string,language:string,language_provenance:string}> $context_stack
     */
    private function pop_fallback_context(array &$context_stack, string $tag): void
    {
        for ($i = count($context_stack) - 1; $i > 0; $i--) {
            $entry = array_pop($context_stack);
            if (($entry['tag'] ?? '') === $tag) {
                break;
            }
        }
    }

    /**
     * @param array<int,array{field:string,text:string,language:string,language_provenance:string}> $segments
     * @param array{language:string,language_provenance:string} $current_context
     */
    private function append_fallback_content_text(
        array &$segments,
        string &$current_text,
        array &$current_context,
        array $context,
        string $text
    ): void {
        if ($this->analyzer->normalize_plain_text($current_text) !== ''
            && (
                $current_context['language'] !== $context['language']
                || $current_context['language_provenance'] !== $context['language_provenance']
            )
        ) {
            $this->flush_fallback_content_segment($segments, $current_text, $current_context);
        }

        if ($this->analyzer->normalize_plain_text($current_text) === '') {
            $current_context = $context;
        }

        $current_text .= ' ' . $text;
    }

    /**
     * @param array<int,array{field:string,text:string,language:string,language_provenance:string}> $segments
     * @param array{language:string,language_provenance:string} $current_context
     */
    private function flush_fallback_content_segment(array &$segments, string &$current_text, array $current_context): void
    {
        $this->append_fallback_segment($segments, 'content', $current_text, $current_context);
        $current_text = '';
    }

    /**
     * @param array<int,array{field:string,text:string,language:string,language_provenance:string}> $segments
     * @param array{language:string,language_provenance:string} $context
     */
    private function append_fallback_segment(array &$segments, string $field, string $text, array $context): void
    {
        $text = $this->analyzer->normalize_plain_text($text);
        if ($text === '') {
            return;
        }

        $segments[] = [
            'field' => $field,
            'text' => $text,
            'language' => $context['language'],
            'language_provenance' => $context['language_provenance'],
        ];
    }

    /**
     * @param array{language:string,language_provenance:string}|null $current
     * @return array{language:string,language_provenance:string}
     */
    private function select_field_metadata(?array $current, string $language, string $provenance): array
    {
        $provenance = trim($provenance);
        if ($provenance === '') {
            $provenance = 'fallback';
        }

        $candidate = [
            'language' => $language,
            'language_provenance' => $provenance,
        ];
        if ($current === null || (string) ($current['language_provenance'] ?? '') === '') {
            return $candidate;
        }

        return (string) $current['language_provenance'] !== 'html_lang' && $provenance === 'html_lang'
            ? $candidate
            : $current;
    }

    /**
     * @param iterable<object> $posts
     */
    public function rebuild(iterable $posts): void
    {
        $this->storage->clear();
        foreach ($posts as $post) {
            $this->index_post($post);
        }
    }

    private function post_id(object $post): int
    {
        return max(0, (int) ($post->ID ?? $post->id ?? $post->post_id ?? 0));
    }

    private function post_string(object $post, string $property): string
    {
        return isset($post->{$property}) && is_scalar($post->{$property}) ? (string) $post->{$property} : '';
    }
}
