<?php
/**
 * Tricky PDF to WordPress block conversion tests.
 *
 * @package UniversalImporter\Tests\Unit\Import
 */

namespace UniversalImporter\Tests\Unit\Import;

// phpcs:disable WordPress.WP.AlternativeFunctions -- Unit fixtures use isolated temporary files without WordPress loaded.

use PHPUnit\Framework\TestCase;
use UniversalImporter\Import\ImportCacheDirectory;
use UniversalImporter\Import\ImportDecision;
use UniversalImporter\Import\ImportMediaReference;
use UniversalImporter\Import\ImportRunner;
use UniversalImporter\Import\ImportSession;
use UniversalImporter\Import\WordPressImportSessionStore;

/**
 * Verifies nuanced PDF fixtures become useful WordPress draft-page block markup.
 */
final class PdfBlockConversionTest extends TestCase {
	/**
	 * Session store under test.
	 *
	 * @var WordPressImportSessionStore
	 */
	private $store;

	/**
	 * Temporary paths to clean up after each test.
	 *
	 * @var array<int,string>
	 */
	private $temporary_paths = array();

	/**
	 * Sets up fake WordPress persistence.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->store = new WordPressImportSessionStore( new FakeWpdb() );
	}

	/**
	 * Removes temporary PDF/cache fixtures.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		foreach ( array_reverse( $this->temporary_paths ) as $path ) {
			$this->remove_path( $path );
		}

		parent::tearDown();
	}

	/**
	 * The CI fixture manifest covers at least ten distinct tricky PDFs.
	 *
	 * @return void
	 */
	public function test_tricky_pdf_manifest_has_at_least_ten_fixtures() {
		$this->assertGreaterThanOrEqual( 10, count( self::tricky_pdf_block_cases() ) );
	}

	/**
	 * Tricky PDF fixtures convert to WordPress draft pages with meaningful block markup.
	 *
	 * @dataProvider tricky_pdf_block_cases
	 *
	 * @param array<string,mixed> $fixture PDF fixture case.
	 * @return void
	 */
	public function test_tricky_pdf_converts_to_wordpress_page_blocks( array $fixture ) {
		$source_file = ! empty( $fixture['image'] )
			? $this->temporary_pdf_with_jpeg_image( $fixture['basename'], $fixture['streams'] )
			: $this->temporary_pdf_with_streams( $fixture['basename'], $fixture['streams'] );
		$session     = ImportSession::start_for_source( $source_file );
		$posts       = new FakePostGateway();
		$media       = new FakeMediaGateway();
		$cache       = new ImportCacheDirectory( $this->temporary_directory(), 'unit-test' );
		$runner      = new ImportRunner( $this->store, 'unit-test', 60, null, $posts, 'https://local.example.test/', $media, null, null, null, $cache );
		$this->store->save( $session );
		$this->store->save_decision(
			$session->get_id(),
			ImportDecision::pending(
				'confirm-first-party-domains',
				'Confirm first-party domains before URL rewriting.',
				array( 'domains' => array( 'source.example.test' ) )
			)->resolve( array( 'confirmed_domains' => array( 'source.example.test' ) ) )
		);

		$status = $this->store->find( $session->get_id() )->get_status();
		for ( $tick = 0; $tick < 8 && ImportSession::STATUS_DONE !== $status; ++$tick ) {
			$runner->run( $session->get_id() );
			$status = $this->store->find( $session->get_id() )->get_status();
		}

		$restored     = $this->store->find( $session->get_id() );
		$post_content = $this->combined_post_content( $posts );

		$this->assertSame( ImportSession::STATUS_DONE, $restored->get_status(), 'PDF fixture did not complete: ' . $fixture['basename'] );
		$this->assertGreaterThanOrEqual( 1, $posts->count_posts(), 'PDF fixture did not create a WordPress draft page: ' . $fixture['basename'] );
		$this->assertStringContainsString( '<!-- wp:', $post_content, 'PDF fixture did not create block markup: ' . $fixture['basename'] );

		foreach ( $fixture['contains'] as $expected ) {
			$this->assertStringContainsString( $expected, $post_content, 'Missing expected converted content for ' . $fixture['basename'] );
		}

		foreach ( isset( $fixture['not_contains'] ) ? $fixture['not_contains'] : array() as $unexpected ) {
			$this->assertStringNotContainsString( $unexpected, $post_content, 'Unexpected degraded PDF output for ' . $fixture['basename'] );
		}

		if ( ! empty( $fixture['image'] ) ) {
			$references = $this->store->list_media_references_by_statuses( $session->get_id(), array( ImportMediaReference::STATUS_IMPORTED ), 5 );

			$this->assertCount( 1, $references );
			$this->assertSame( 1, $media->count_attachments() );
			$this->assertStringContainsString( '<!-- wp:image -->', $post_content );
			$this->assertStringContainsString( 'https://local.example.test/wp-content/uploads/', $post_content );
			$this->assertStringNotContainsString( 'uwi-pdf-asset://', $post_content );
		}
	}

	/**
	 * Returns at least ten distinct tricky PDF conversion cases.
	 *
	 * @return array<string,array<int,array<string,mixed>>>
	 */
	public static function tricky_pdf_block_cases() {
		return array(
			'glyph-by-glyph-title'        => array(
				array(
					'basename'     => 'glyph-by-glyph-title.pdf',
					'streams'      => array(
						"BT\n/F1 14 Tf\n72 720 Td\n(A) Tj\n(n) Tj\n(n) Tj\n(u) Tj\n(a) Tj\n(l) Tj\n( ) Tj\n(R) Tj\n(e) Tj\n(p) Tj\n(o) Tj\n(r) Tj\n(t) Tj\n0 -18 Td\n(Body text stays readable.) Tj\nET",
					),
					'contains'     => array( 'Annual Report', 'Body text stays readable.' ),
					'not_contains' => array( 'A<br>n<br>n' ),
				),
			),
			'multi-page-two-streams'      => array(
				array(
					'basename' => 'multi-page-two-streams.pdf',
					'streams'  => array(
						self::literal_text_stream( "# Page One\n\nFirst page body." ),
						self::literal_text_stream( "# Page Two\n\nSecond page body." ),
					),
					'contains' => array( '<h1 id="page-one">Page One</h1>', 'First page body.', '<h1 id="page-two">Page Two</h1>', 'Second page body.' ),
				),
			),
			'positioned-fixed-table'      => array(
				array(
					'basename' => 'positioned-fixed-table.pdf',
					'streams'  => array(
						"BT\n/F1 12 Tf\n72 720 Td\n(Item) Tj\n110 0 Td\n(Q1) Tj\n95 0 Td\n(Q2) Tj\n0 -18 Td\n(Alpha) Tj\n110 0 Td\n(10) Tj\n95 0 Td\n(12) Tj\n0 -18 Td\n(Beta) Tj\n110 0 Td\n(7) Tj\n95 0 Td\n(9) Tj\nET",
					),
					'contains' => array( '<!-- wp:table -->', '<td>Item</td><td>Q1</td><td>Q2</td>', '<td>Alpha</td><td>10</td><td>12</td>' ),
				),
			),
			'absolute-tm-table'           => array(
				array(
					'basename' => 'absolute-tm-table.pdf',
					'streams'  => array(
						"BT\n/F1 12 Tf\n1 0 0 1 72 720 Tm\n(Product) Tj\n1 0 0 1 190 720 Tm\n(Count) Tj\n1 0 0 1 300 720 Tm\n(Total) Tj\n1 0 0 1 72 700 Tm\n(Widget) Tj\n1 0 0 1 190 700 Tm\n(4) Tj\n1 0 0 1 300 700 Tm\n($40) Tj\nET",
					),
					'contains' => array( '<!-- wp:table -->', '<td>Product</td><td>Count</td><td>Total</td>', '<td>Widget</td><td>4</td><td>$40</td>' ),
				),
			),
			'pipe-table-literal'          => array(
				array(
					'basename' => 'pipe-table-literal.pdf',
					'streams'  => array(
						self::literal_text_stream( "Region | Leads | Wins\n--- | --- | ---\nNorth | 12 | 5\nSouth | 9 | 4" ),
					),
					'contains' => array( '<!-- wp:table -->', '<td>Region</td><td>Leads</td><td>Wins</td>', '<td>North</td><td>12</td><td>5</td>' ),
				),
			),
			'bullet-glyph-list'           => array(
				array(
					'basename' => 'bullet-glyph-list.pdf',
					'streams'  => array(
						self::literal_text_stream( "\xE2\x80\xA2 Discovery workshop\n\xE2\x80\xA2 Migration plan\n\xE2\x80\xA2 Launch checklist" ),
					),
					'contains' => array( '<!-- wp:list -->', '<li>Discovery workshop</li>', '<li>Migration plan</li>', '<li>Launch checklist</li>' ),
				),
			),
			'ordered-list'                => array(
				array(
					'basename' => 'ordered-list.pdf',
					'streams'  => array(
						self::literal_text_stream( "1. Export content\n2. Review imported drafts\n3. Publish approved pages" ),
					),
					'contains' => array( '<!-- wp:list {"ordered":true} -->', '<li>Export content</li>', '<li>Review imported drafts</li>', '<li>Publish approved pages</li>' ),
				),
			),
			'embedded-image-with-caption' => array(
				array(
					'basename' => 'embedded-image-with-caption.pdf',
					'streams'  => array(
						self::literal_text_stream( "# Image Evidence\n\nFigure 1. Embedded chart extracted from the PDF." ) . "\nq 1 0 0 1 0 0 cm /Im1 Do Q",
					),
					'image'    => true,
					'contains' => array( '<h1 id="image-evidence">Image Evidence</h1>', 'Figure 1. Embedded chart extracted from the PDF.', '<!-- wp:image -->' ),
				),
			),
			'utf16-hex-text'              => array(
				array(
					'basename' => 'utf16-hex-text.pdf',
					'streams'  => array(
						"BT\n/F1 12 Tf\n72 720 Td\n<FEFF005500540046002D003100360020005400690074006C0065> Tj\n0 -18 Td\n(Body decoded after hex text.) Tj\nET",
					),
					'contains' => array( 'UTF-16 Title', 'Body decoded after hex text.' ),
				),
			),
			'tj-array-kerning-link'       => array(
				array(
					'basename' => 'tj-array-kerning-link.pdf',
					'streams'  => array(
						"BT\n/F1 12 Tf\n72 720 Td\n[(Revenue ) 120 (growth)] TJ\n0 -18 Td\n[(Read ) 80 (https://source.example.test/report)] TJ\nET",
					),
					'contains' => array( 'Revenue growth', 'https://local.example.test/report' ),
				),
			),
			'quote-operator-lines'        => array(
				array(
					'basename' => 'quote-operator-lines.pdf',
					'streams'  => array(
						"BT\n/F1 12 Tf\n72 720 Td\n(Opening paragraph) Tj\n(Second line from quote operator) '\nET",
					),
					'contains' => array( 'Opening paragraph', 'Second line from quote operator' ),
				),
			),
		);
	}

	/**
	 * Builds one text content stream from literal text.
	 *
	 * @param string $text Text to embed.
	 * @return string
	 */
	private static function literal_text_stream( $text ) {
		return "BT\n/F1 12 Tf\n72 720 Td\n(" . self::pdf_literal( $text ) . ") Tj\nET";
	}

	/**
	 * Escapes a PHP string as a PDF literal string body.
	 *
	 * @param string $text Text to escape.
	 * @return string
	 */
	private static function pdf_literal( $text ) {
		return strtr(
			(string) $text,
			array(
				'\\' => '\\\\',
				'('  => '\\(',
				')'  => '\\)',
				"\r" => '\r',
				"\n" => '\n',
				"\t" => '\t',
			)
		);
	}

	/**
	 * Creates a minimal multi-page PDF fixture from content streams.
	 *
	 * @param string            $basename Fixture basename.
	 * @param array<int,string> $streams  Page content streams.
	 * @return string
	 */
	private function temporary_pdf_with_streams( $basename, array $streams ) {
		return $this->temporary_pdf_document( $basename, $streams, false );
	}

	/**
	 * Creates a minimal PDF fixture with one embedded JPEG XObject.
	 *
	 * @param string            $basename Fixture basename.
	 * @param array<int,string> $streams  Page content streams.
	 * @return string
	 */
	private function temporary_pdf_with_jpeg_image( $basename, array $streams ) {
		return $this->temporary_pdf_document( $basename, $streams, true );
	}

	/**
	 * Creates a minimal compressed PDF fixture.
	 *
	 * @param string            $basename      Fixture basename.
	 * @param array<int,string> $streams       Page content streams.
	 * @param bool              $include_image Whether to include one JPEG image XObject.
	 * @return string
	 */
	private function temporary_pdf_document( $basename, array $streams, $include_image ) {
		$streams      = array_values( $streams );
		$page_count   = max( 1, count( $streams ) );
		$page_ids     = range( 3, 2 + $page_count );
		$content_base = 3 + $page_count;
		$image_id     = $content_base + $page_count;
		$objects      = array(
			"1 0 obj << /Type /Catalog /Pages 2 0 R >> endobj\n",
			'2 0 obj << /Type /Pages /Kids [' . implode(
				' ',
				array_map(
					function ( $page_id ) {
						return $page_id . ' 0 R';
					},
					$page_ids
				)
			) . '] /Count ' . $page_count . " >> endobj\n",
		);

		foreach ( $page_ids as $index => $page_id ) {
			$content_id = $content_base + $index;
			$resources  = $include_image ? ' /Resources << /XObject << /Im1 ' . $image_id . ' 0 R >> >>' : '';
			$objects[]  = $page_id . ' 0 obj << /Type /Page /Parent 2 0 R' . $resources . ' /Contents ' . $content_id . " 0 R >> endobj\n";
		}

		foreach ( $streams as $index => $stream ) {
			$compressed = gzcompress( (string) $stream );
			$this->assertIsString( $compressed );
			$objects[] = ( $content_base + $index ) . ' 0 obj << /Length ' . strlen( $compressed ) . " /Filter /FlateDecode >>\nstream\n"
				. $compressed
				. "\nendstream\nendobj\n";
		}

		if ( $include_image ) {
			$image = base64_decode( $this->tiny_jpeg_base64(), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Unit test embeds a tiny binary JPEG fixture in a generated PDF.
			$this->assertIsString( $image );
			$objects[] = $image_id . ' 0 obj << /Type /XObject /Subtype /Image /Width 1 /Height 1 /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /DCTDecode /Length ' . strlen( $image ) . " >>\nstream\n"
				. $image
				. "\nendstream\nendobj\n";
		}

		return $this->temporary_file( $basename, "%PDF-1.4\n" . implode( '', $objects ) . "%%EOF\n" );
	}

	/**
	 * Returns a tiny valid JPEG fixture as base64.
	 *
	 * @return string
	 */
	private function tiny_jpeg_base64() {
		return '/9j/4AAQSkZJRgABAQAAAQABAAD/2wBDAP//////////////////////////////////////////////////////////////////////////////////////2wBDAf//////////////////////////////////////////////////////////////////////////////////////wAARCAABAAEDASIAAhEBAxEB/8QAFQABAQAAAAAAAAAAAAAAAAAAAAX/xAAUEAEAAAAAAAAAAAAAAAAAAAAA/9oADAMBAAIQAxAAAAH/xAAUEAEAAAAAAAAAAAAAAAAAAAAA/9oACAEBAAEFAqf/xAAUEQEAAAAAAAAAAAAAAAAAAAAA/9oACAEDAQE/ASP/xAAUEQEAAAAAAAAAAAAAAAAAAAAA/9oACAECAQE/ASP/xAAUEAEAAAAAAAAAAAAAAAAAAAAA/9oACAEBAAY/Ar//xAAUEAEAAAAAAAAAAAAAAAAAAAAA/9oACAEBAAE/IV//2gAMAwEAAgADAAAAEP/EABQRAQAAAAAAAAAAAAAAAAAAABD/2gAIAQMBAT8QH//EABQRAQAAAAAAAAAAAAAAAAAAABD/2gAIAQIBAT8QH//EABQQAQAAAAAAAAAAAAAAAAAAABD/2gAIAQEAAT8QH//Z';
	}

	/**
	 * Writes a temporary file fixture.
	 *
	 * @param string $basename File basename.
	 * @param string $contents File contents.
	 * @return string
	 */
	private function temporary_file( $basename, $contents ) {
		$path = $this->temporary_directory() . '/' . $basename;

		file_put_contents( $path, $contents );

		return $path;
	}

	/**
	 * Creates a temporary directory fixture.
	 *
	 * @return string
	 */
	private function temporary_directory() {
		$path = sys_get_temp_dir() . '/universal-importer-pdf-blocks-' . bin2hex( random_bytes( 6 ) );

		mkdir( $path );
		chmod( $path, 0700 );
		$this->temporary_paths[] = $path;

		return $path;
	}

	/**
	 * Returns all fake post content in write order.
	 *
	 * @param FakePostGateway $posts Fake post gateway.
	 * @return string
	 */
	private function combined_post_content( FakePostGateway $posts ) {
		$content = array();
		$count   = $posts->count_posts();

		for ( $post_id = 1; $post_id <= $count; ++$post_id ) {
			$post = $posts->get_post( $post_id );
			if ( null !== $post ) {
				$content[] = $post['post_content'];
			}
		}

		return implode( "\n\n", $content );
	}

	/**
	 * Removes a fixture path recursively.
	 *
	 * @param string $path Path.
	 * @return void
	 */
	private function remove_path( $path ) {
		if ( ! file_exists( $path ) && ! is_link( $path ) ) {
			return;
		}

		if ( is_file( $path ) || is_link( $path ) ) {
			unlink( $path );
			return;
		}

		foreach ( scandir( $path ) as $child ) {
			if ( '.' === $child || '..' === $child ) {
				continue;
			}
			$this->remove_path( $path . DIRECTORY_SEPARATOR . $child );
		}

		rmdir( $path );
	}
}
