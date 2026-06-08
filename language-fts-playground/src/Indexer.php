<?php
declare(strict_types=1);

/**
 * Builds term-frequency rows for WordPress posts.
 */
final class Language_FTS_Playground_Indexer
{
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
        $content = $this->analyzer->extract_searchable_text($this->post_string($post, 'post_content'));
        $document_text = trim($title . ' ' . $excerpt . ' ' . $content);
        $terms = $this->analyzer->analyze_text($document_text, $language);

        $term_frequencies = [];
        foreach ($terms as $term) {
            $term_frequencies[$term] = ($term_frequencies[$term] ?? 0) + 1;
        }

        $this->storage->replace_document(
            $post_id,
            $language,
            $title,
            $status,
            count($terms),
            $term_frequencies
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
