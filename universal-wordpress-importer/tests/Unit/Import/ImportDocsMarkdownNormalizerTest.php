<?php
/**
 * Tests for docs-flavored Markdown normalization.
 *
 * @package UniversalImporter\Tests\Unit\Import
 */

namespace UniversalImporter\Tests\Unit\Import;

use PHPUnit\Framework\TestCase;
use UniversalImporter\Import\ImportDocsMarkdownDialectParser;
use UniversalImporter\Import\ImportDocsMarkdownNormalizer;
use UniversalImporter\Import\ImportSessionId;
use UniversalImporter\Import\ImportSourceItem;

/**
 * Covers Markdown-compatible docs conventions before block parsing.
 */
final class ImportDocsMarkdownNormalizerTest extends TestCase {
	/**
	 * Docusaurus-style admonitions and MDX wrapper lines normalize conservatively.
	 *
	 * @return void
	 */
	public function test_normalizer_handles_docusaurus_admonitions_and_mdx_wrappers() {
		$result = $this->normalize(
			implode(
				"\n",
				array(
					"import {",
					"  Tabs,",
					"  TabItem,",
					"} from '@theme/Tabs';",
					"",
					"# Docusaurus API",
					"",
					":::note Stable API",
					"Use the stable docs path.",
					":::",
					"",
					"<Tabs>",
					"</Tabs>",
					"",
					"Continue to [Astro](../src/content/docs/overview.mdoc).",
				)
			),
			'docs/api.mdx'
		);

		$this->assertSame(
			"\n# Docusaurus API\n\n> **Note:** Stable API\n> Use the stable docs path.\n\n\nContinue to [Astro](../src/content/docs/overview.mdoc).",
			$result['content']
		);
		$this->assertSame( array( 'docusaurus' ), $result['metadata']['markdown_docs_flavors'] );
		$this->assertSame( 1, $result['metadata']['markdown_docs_admonition_count'] );
		$this->assertSame( 6, $result['metadata']['markdown_mdx_lines_removed'] );
		$this->assertTrue( $result['metadata']['markdown_docs_conventions_normalized'] );
	}

	/**
	 * MDX stripping preserves fences, prose, indented code, and contentful components.
	 *
	 * @return void
	 */
	public function test_normalizer_preserves_fenced_markdown_and_prose_import_export_lines() {
		$result = $this->normalize(
			implode(
				"\n",
				array(
					"# MDX Fence Fixture",
					"",
					"````mdx",
					"```js",
					"import Sample from './sample';",
					"```",
					"",
					"export controls are configured in Wrangler for the sample.",
					"[[Guide (v2)|Guide inside fence]]",
					"````",
					"",
					"import RealComponent from './RealComponent';",
					"export const metadata = {",
					"  title: 'Docs page',",
					"  sidebar_position: 2,",
					"};",
					"",
					"import controls are configured by prose.",
					"export controls are configured in Wrangler.",
					"    import indentedCode from './kept';",
					"",
					"<Note>Keep this warning.</Note>",
					"",
					"<Cards>",
					"</Cards>",
				)
			),
			'docs/fenced-sample.mdx'
		);
		$fenced_block = implode(
			"\n",
			array(
				"````mdx",
				"```js",
				"import Sample from './sample';",
				"```",
				"",
				"export controls are configured in Wrangler for the sample.",
				"[[Guide (v2)|Guide inside fence]]",
				"````",
			)
		);

		$this->assertStringContainsString( $fenced_block, $result['content'] );
		$this->assertStringContainsString( "import controls are configured by prose.", $result['content'] );
		$this->assertStringContainsString( "export controls are configured in Wrangler.", $result['content'] );
		$this->assertStringContainsString( "    import indentedCode from './kept';", $result['content'] );
		$this->assertStringContainsString( "<Note>Keep this warning.</Note>", $result['content'] );
		$this->assertStringNotContainsString( "RealComponent", $result['content'] );
		$this->assertStringNotContainsString( "metadata =", $result['content'] );
		$this->assertStringNotContainsString( "Docs page", $result['content'] );
		$this->assertStringNotContainsString( "sidebar_position", $result['content'] );
		$this->assertStringNotContainsString( "<Cards", $result['content'] );
		$this->assertSame( 7, $result['metadata']['markdown_mdx_lines_removed'] );
		$this->assertTrue( $result['metadata']['markdown_docs_conventions_normalized'] );
	}

	/**
	 * Fenced code closes only on same-marker lines with whitespace-only tails.
	 *
	 * @return void
	 */
	public function test_normalizer_preserves_docs_syntax_after_non_closing_fence_line() {
		$result = $this->normalize(
			implode(
				"\n",
				array(
					"# Fence Close",
					"",
					"```mdx",
					"```not a close",
					"[[Should Stay Raw]]",
					"<Cards>",
					"> [!NOTE] Raw callout",
					":::note Raw admonition",
					"```",
					"",
					"After [[Real Link]].",
				)
			),
			'docs/fence-close.mdx'
		);

		$this->assertStringContainsString(
			implode(
				"\n",
				array(
					"```mdx",
					"```not a close",
					"[[Should Stay Raw]]",
					"<Cards>",
					"> [!NOTE] Raw callout",
					":::note Raw admonition",
					"```",
				)
			),
			$result['content']
		);
		$this->assertStringContainsString( 'After [Real Link](Real%20Link.md).', $result['content'] );
		$this->assertSame( 1, $result['metadata']['markdown_obsidian_wikilink_count'] );
		$this->assertArrayNotHasKey( 'markdown_mdx_lines_removed', $result['metadata'] );
		$this->assertArrayNotHasKey( 'markdown_docs_admonition_count', $result['metadata'] );
	}

	/**
	 * Obsidian wikilinks, embeds, and callouts become Markdown-compatible syntax.
	 *
	 * @return void
	 */
	public function test_normalizer_converts_obsidian_links_with_escaping_and_encoding() {
		$result = $this->normalize(
			implode(
				"\n",
				array(
					"# Concepts",
					"",
					"See [[Guides/Setup|Setup guide]], [[Guide (v2)|Guide [v2]]], and [[Guide [v2]|Guide [stable]]].",
					"",
					"![[assets/diagram.png]]",
					"",
					"> [!NOTE] Field note",
					"> Works offline.",
					"",
					"See [[#Local Heading]] and [[Folder/Name With Space#Part Two|Space target]].",
				)
			),
			'vault/Concepts.md'
		);

		$this->assertSame(
			"# Concepts\n\nSee [Setup guide](Guides/Setup.md), [Guide &#91;v2&#93;](Guide%20%28v2%29.md), and [Guide &#91;stable&#93;](Guide%20%5Bv2%5D.md).\n\n![diagram](assets/diagram.png)\n\n> **Note:** Field note\n> Works offline.\n\nSee [Local Heading](#Local%20Heading) and [Space target](Folder/Name%20With%20Space.md#Part%20Two).",
			$result['content']
		);
		$this->assertSame( array( 'obsidian' ), $result['metadata']['markdown_docs_flavors'] );
		$this->assertSame( 5, $result['metadata']['markdown_obsidian_wikilink_count'] );
		$this->assertSame( 1, $result['metadata']['markdown_obsidian_embed_count'] );
		$this->assertSame( 1, $result['metadata']['markdown_obsidian_callout_count'] );
		$this->assertTrue( $result['metadata']['markdown_docs_conventions_normalized'] );
	}

	/**
	 * CommonMark backslash escapes prevent Obsidian wikilink conversion.
	 *
	 * @return void
	 */
	public function test_normalizer_preserves_backslash_escaped_obsidian_wikilinks() {
		$result = $this->normalize(
			implode(
				"\n",
				array(
					'Escaped literal: \[[Do Not Convert]].',
					'Escaped embed literal: \![[Do Not Embed]].',
					'Converted: [[Convert Me]].',
				)
			),
			'vault/escaped.md'
		);

		$this->assertSame(
			"Escaped literal: \\[[Do Not Convert]].\nEscaped embed literal: \\![[Do Not Embed]].\nConverted: [Convert Me](Convert%20Me.md).",
			$result['content']
		);
		$this->assertSame( 1, $result['metadata']['markdown_obsidian_wikilink_count'] );
		$this->assertArrayNotHasKey( 'markdown_obsidian_embed_count', $result['metadata'] );
	}

	/**
	 * Obsidian callout titles normalize wikilinks through the same scanner as body lines.
	 *
	 * @return void
	 */
	public function test_normalizer_converts_obsidian_wikilinks_in_callout_titles() {
		$result = $this->normalize(
			implode(
				"\n",
				array(
					"> [!NOTE]+ See [[Page|title page]]",
					"> Body [[Other]].",
				)
			),
			'vault/callout-title.md'
		);

		$this->assertSame(
			"> **Note:** See [title page](Page.md)\n> Body [Other](Other.md).",
			$result['content']
		);
		$this->assertSame( 2, $result['metadata']['markdown_obsidian_wikilink_count'] );
		$this->assertSame( 1, $result['metadata']['markdown_obsidian_callout_count'] );
	}

	/**
	 * Static docs flavor detection does not mark unchanged content as normalized.
	 *
	 * @return void
	 */
	public function test_normalizer_detects_docs_flavors_without_forcing_normalized_flag() {
		$jekyll = $this->normalize( "# Release Notes\n\nJekyll body.", '_posts/2026-06-08-release.md' );
		$astro  = $this->normalize( "# Astro Overview\n\nAstro docs body.", 'src/content/docs/overview.mdoc' );

		$this->assertSame( "# Release Notes\n\nJekyll body.", $jekyll['content'] );
		$this->assertSame( array( 'jekyll' ), $jekyll['metadata']['markdown_docs_flavors'] );
		$this->assertArrayNotHasKey( 'markdown_docs_conventions_normalized', $jekyll['metadata'] );
		$this->assertSame( "# Astro Overview\n\nAstro docs body.", $astro['content'] );
		$this->assertSame( array( 'astro' ), $astro['metadata']['markdown_docs_flavors'] );
		$this->assertArrayNotHasKey( 'markdown_docs_conventions_normalized', $astro['metadata'] );
	}

	/**
	 * Plain Markdown does not strip top-level import/export-looking prose.
	 *
	 * @return void
	 */
	public function test_normalizer_does_not_strip_plain_markdown_import_export_lines() {
		$content = "import Thing from './Thing';\nexport const value = true;\n\n# Kept";
		$result  = $this->normalize( $content, 'notes/plain.md' );

		$this->assertSame( $content, $result['content'] );
		$this->assertSame( array(), $result['metadata'] );
	}

	/**
	 * Parser-backed fixture matrix has at least sixty cases per docs flavor.
	 *
	 * @return void
	 */
	public function test_docs_markdown_dialect_fixture_matrix_has_at_least_sixty_cases_per_flavor() {
		$cases    = $this->docs_markdown_fixture_cases();
		$minimums = array(
			'commonmark' => 60,
			'obsidian'   => 60,
			'docusaurus' => 60,
			'astro'      => 60,
			'markdoc'    => 60,
			'mdx-skip'   => 60,
		);
		$counts   = array();

		foreach ( $cases as $case ) {
			$this->assertArrayHasKey( 'id', $case );
			$this->assertArrayHasKey( 'flavor', $case );
			$this->assertArrayHasKey( 'source_path', $case );
			$this->assertArrayHasKey( 'input', $case );
			$this->assertArrayHasKey( 'expected_content', $case );
			$this->assertArrayHasKey( 'expected_metadata', $case );
			$this->assertArrayHasKey( 'notes', $case );
			$this->assertArrayHasKey( $case['flavor'], $minimums, 'Unexpected fixture flavor for ' . $case['id'] );
			if ( ! isset( $counts[ $case['flavor'] ] ) ) {
				$counts[ $case['flavor'] ] = 0;
			}
			++$counts[ $case['flavor'] ];
		}

		foreach ( $minimums as $flavor => $minimum ) {
			$this->assertGreaterThanOrEqual(
				$minimum,
				isset( $counts[ $flavor ] ) ? $counts[ $flavor ] : 0,
				'Fixture matrix must keep at least ' . $minimum . ' ' . $flavor . ' cases.'
			);
		}

		foreach ( $cases as $case ) {
			$result = $this->normalize( $case['input'], $case['source_path'] );
			$this->assertSame( $case['expected_content'], $result['content'], $case['id'] . ' content' );
			$this->assertSame(
				$this->sort_metadata_for_assertion( $case['expected_metadata'] ),
				$this->sort_metadata_for_assertion( $result['metadata'] ),
				$case['id'] . ' metadata'
			);
		}
	}

	/**
	 * Docs dialect implementation avoids parser-layer regular expression functions.
	 *
	 * @return void
	 */
	public function test_docs_markdown_dialect_parser_layer_has_no_regular_expression_calls() {
		$files = array(
			dirname( __DIR__, 3 ) . '/src/Import/ImportDocsMarkdownNormalizer.php',
			dirname( __DIR__, 3 ) . '/src/Import/ImportDocsMarkdownDialectParser.php',
		);
		$blocked_names = array(
			'ereg',
			'ereg_replace',
			'eregi',
			'eregi_replace',
			'mb_ereg',
			'mb_ereg_match',
			'mb_ereg_replace',
			'mb_ereg_replace_callback',
			'mb_ereg_search',
			'mb_ereg_search_getpos',
			'mb_ereg_search_getregs',
			'mb_ereg_search_init',
			'mb_ereg_search_pos',
			'mb_ereg_search_regs',
			'mb_ereg_search_setpos',
			'mb_eregi',
			'mb_eregi_replace',
			'preg_filter',
			'preg_grep',
			'preg_last_error',
			'preg_match',
			'preg_match_all',
			'preg_quote',
			'preg_replace',
			'preg_replace_callback',
			'preg_split',
		);

		foreach ( $files as $file ) {
			$source = file_get_contents( $file );
			$this->assertIsString( $source );
			$tokens = token_get_all( $source );

			foreach ( $tokens as $token ) {
				if ( ! is_array( $token ) || T_STRING !== $token[0] ) {
					continue;
				}

				$this->assertNotContains( $token[1], $blocked_names, $file . ' calls ' . $token[1] );
			}
		}
	}

	/**
	 * The dialect parser emits named tokens with source offsets.
	 *
	 * @return void
	 */
	public function test_docs_markdown_dialect_parser_exposes_token_stream() {
		$counts = array();
		$tokens = ( new ImportDocsMarkdownDialectParser() )->tokenize(
			":::note[Title]\nBody [[Target|label]].\n:::\n",
			array(),
			$counts
		);
		$types  = array();

		foreach ( $tokens as $token ) {
			$types[] = $token['type'];
			$this->assertArrayHasKey( 'line', $token );
			$this->assertArrayHasKey( 'offset', $token );
			$this->assertArrayHasKey( 'length', $token );
		}

		$this->assertSame( array( 'admonition_open', 'admonition_line', 'admonition_close', 'markdown_line' ), $types );
		$this->assertSame( 1, $counts['docusaurus_admonitions'] );
		$this->assertSame( 1, $counts['obsidian_wikilinks'] );
	}

	/**
	 * Normalizes content for a relative source path.
	 *
	 * @param string $content       Markdown content.
	 * @param string $relative_path Source relative path.
	 * @return array{content:string,metadata:array<string,mixed>}
	 */
	private function normalize( $content, $relative_path ) {
		$extension = pathinfo( (string) $relative_path, PATHINFO_EXTENSION );
		$item      = ImportSourceItem::queued(
			ImportSessionId::from_string( 'import_00000000000000000000000000000000' ),
			'local:' . sha1( (string) $relative_path ),
			null,
			'/tmp/import-root/' . str_replace( '\\', '/', (string) $relative_path ),
			(string) $relative_path,
			ImportSourceItem::TYPE_FILE,
			array( 'extension' => $extension )
		);

		return ( new ImportDocsMarkdownNormalizer() )->normalize( $content, $item );
	}

	/**
	 * Reads the docs dialect fixture case matrix.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function docs_markdown_fixture_cases() {
		$cases = require dirname( __DIR__, 2 ) . '/Fixtures/docs-markdown-dialects/case-matrix.php';
		$this->assertIsArray( $cases );

		return $cases;
	}

	/**
	 * Sorts associative metadata recursively before exact comparison.
	 *
	 * @param mixed $metadata Metadata value.
	 * @return mixed Sorted metadata value.
	 */
	private function sort_metadata_for_assertion( $metadata ) {
		if ( ! is_array( $metadata ) ) {
			return $metadata;
		}

		foreach ( $metadata as $key => $value ) {
			$metadata[ $key ] = $this->sort_metadata_for_assertion( $value );
		}
		ksort( $metadata );

		return $metadata;
	}
}
