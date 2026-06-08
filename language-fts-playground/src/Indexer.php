<?php
declare(strict_types=1);

/**
 * Builds term-frequency rows for WordPress posts.
 */
final class Language_FTS_Playground_Indexer
{
    /** @var array<int,string> */
    private const INDEXED_FIELDS = ['title', 'excerpt', 'content', 'alt'];

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

        $language = $this->analyzer->resolve_post_language($post);
        $title = $this->analyzer->normalize_plain_text($this->post_string($post, 'post_title'));
        $excerpt = $this->analyzer->normalize_plain_text($this->post_string($post, 'post_excerpt'));
        $content_html = $this->post_string($post, 'post_content');
        $content_fields = $this->analyzer->extract_searchable_fields($content_html);
        $content_segments = $this->analyzer->extract_searchable_field_segments($content_html);
        $field_texts = [
            'title' => $title,
            'excerpt' => $excerpt,
            'content' => $content_fields['content'],
            'alt' => $content_fields['alt'],
        ];
        $position_segments = array_merge([$title, $excerpt], $content_segments['content'], $content_segments['alt']);
        $analyzed_document = $this->analyzer->analyze_segments_with_positions($position_segments, $language);

        $document_length = 0;
        $field_term_frequencies = [];
        foreach (self::INDEXED_FIELDS as $field) {
            $terms = $this->analyzer->analyze_text($field_texts[$field], $language);
            $document_length += count($terms);
            $field_term_frequencies[$field] = [];
            foreach ($terms as $term) {
                $field_term_frequencies[$field][$term] = ($field_term_frequencies[$field][$term] ?? 0) + 1;
            }
        }

        $this->storage->replace_document(
            $post_id,
            $language,
            $title,
            $status,
            $document_length,
            $field_term_frequencies,
            $field_texts,
            $analyzed_document['positions']
        );
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
