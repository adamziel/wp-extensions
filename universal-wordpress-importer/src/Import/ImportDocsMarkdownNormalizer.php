<?php
/**
 * Docs-flavored Markdown convention normalizer.
 *
 * @package UniversalImporter
 */

namespace UniversalImporter\Import;

/**
 * Normalizes Markdown-compatible documentation-site conventions before block parsing.
 */
final class ImportDocsMarkdownNormalizer {
	/**
	 * Normalizes conservative docs-site Markdown conventions before block parsing.
	 *
	 * This intentionally handles only Markdown-compatible documentation idioms.
	 * Full MDX/React component evaluation remains outside the first-pass import.
	 *
	 * @param string           $content Markdown content without leading front matter.
	 * @param ImportSourceItem $item    Source item.
	 * @return array{content:string,metadata:array<string,mixed>}
	 */
	public function normalize( $content, ImportSourceItem $item ) {
		$content       = str_replace( array( "\r\n", "\r" ), "\n", (string) $content );
		$item_metadata = $item->get_metadata();
		$extension     = isset( $item_metadata['extension'] ) ? strtolower( (string) $item_metadata['extension'] ) : strtolower( pathinfo( $item->get_source_uri(), PATHINFO_EXTENSION ) );
		$allow_docs_dialect_normalization = in_array( $extension, array( 'mdx', 'mdoc', 'markdoc' ), true );

		$parser = new ImportDocsMarkdownDialectParser();
		$result = $parser->normalize(
			$content,
			array(
				'allow_mdx'     => $allow_docs_dialect_normalization,
				'allow_markdoc' => $allow_docs_dialect_normalization,
			)
		);

		$normalized = $result['content'];
		$counts     = $result['metadata'];
		$metadata   = array();
		$flavors    = $this->detect_docs_markdown_flavors(
			$item,
			$content,
			isset( $counts['docusaurus_admonitions'] ) ? (int) $counts['docusaurus_admonitions'] : 0,
			isset( $counts['obsidian_wikilinks'] ) ? (int) $counts['obsidian_wikilinks'] : 0,
			isset( $counts['obsidian_embeds'] ) ? (int) $counts['obsidian_embeds'] : 0,
			isset( $counts['obsidian_callouts'] ) ? (int) $counts['obsidian_callouts'] : 0,
			isset( $counts['markdoc_constructs'] ) ? (int) $counts['markdoc_constructs'] : 0
		);

		if ( ! empty( $flavors ) ) {
			$metadata['markdown_docs_flavors'] = $flavors;
		}
		if ( ! empty( $counts['docusaurus_admonitions'] ) ) {
			$metadata['markdown_docs_admonition_count'] = (int) $counts['docusaurus_admonitions'];
		}
		if ( ! empty( $counts['mdx_removed'] ) ) {
			$metadata['markdown_mdx_lines_removed'] = (int) $counts['mdx_removed'];
		}
		if ( ! empty( $counts['obsidian_wikilinks'] ) ) {
			$metadata['markdown_obsidian_wikilink_count'] = (int) $counts['obsidian_wikilinks'];
		}
		if ( ! empty( $counts['obsidian_embeds'] ) ) {
			$metadata['markdown_obsidian_embed_count'] = (int) $counts['obsidian_embeds'];
		}
		if ( ! empty( $counts['obsidian_callouts'] ) ) {
			$metadata['markdown_obsidian_callout_count'] = (int) $counts['obsidian_callouts'];
		}
		if ( ! empty( $counts['markdoc_constructs'] ) ) {
			$metadata['markdown_markdoc_construct_count'] = (int) $counts['markdoc_constructs'];
		}
		if ( $normalized !== $content ) {
			$metadata['markdown_docs_conventions_normalized'] = true;
		}

		return array(
			'content'  => $normalized,
			'metadata' => $metadata,
		);
	}

	/**
	 * Detect conservative docs-site flavors for metadata.
	 *
	 * @param ImportSourceItem $item             Source item.
	 * @param string           $content          Markdown content.
	 * @param int              $admonitions      Docusaurus/Astro admonition count.
	 * @param int              $wikilinks        Obsidian wikilink count.
	 * @param int              $embeds           Obsidian embed count.
	 * @param int              $obsidian_callout Obsidian callout count.
	 * @param int              $markdoc          Markdoc construct count.
	 * @return string[]
	 */
	private function detect_docs_markdown_flavors( ImportSourceItem $item, $content, $admonitions, $wikilinks, $embeds, $obsidian_callout, $markdoc ) {
		$metadata  = $item->get_metadata();
		$extension = isset( $metadata['extension'] ) ? strtolower( (string) $metadata['extension'] ) : strtolower( pathinfo( $item->get_source_uri(), PATHINFO_EXTENSION ) );
		$path      = str_replace( '\\', '/', $item->get_relative_path() );
		$source    = str_replace( '\\', '/', $item->get_source_uri() );
		$haystack  = (string) $content;
		$flavors   = array();

		if ( 'mdx' === $extension || false !== strpos( $haystack, 'sidebar_position:' ) || 0 < $admonitions ) {
			$flavors['docusaurus'] = true;
		}

		if ( in_array( $extension, array( 'mdoc', 'markdoc' ), true ) || false !== strpos( $path, 'src/content/' ) || false !== strpos( $source, 'src/content/' ) ) {
			$flavors['astro'] = true;
		}

		if ( false !== strpos( '/' . $path, '/_posts/' ) || false !== strpos( '/' . $source, '/_posts/' ) || false !== strpos( $haystack, 'layout:' ) || false !== strpos( $haystack, 'permalink:' ) ) {
			$flavors['jekyll'] = true;
		}

		if ( 0 < $wikilinks || 0 < $embeds || 0 < $obsidian_callout ) {
			$flavors['obsidian'] = true;
		}

		if ( 'markdoc' === $extension || 0 < $markdoc ) {
			$flavors['markdoc'] = true;
		}

		$flavors = array_keys( $flavors );
		sort( $flavors, SORT_STRING );

		return $flavors;
	}
}
