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
	 * PDFs with embedded ToUnicode font maps decode glyph IDs into readable text.
	 *
	 * @return void
	 */
	public function test_pdf_to_unicode_cmap_decodes_glyph_codes_to_text() {
		if ( ! function_exists( 'mb_convert_encoding' ) ) {
			$this->markTestSkipped( 'mbstring is required to build UTF-16BE CMap fixtures.' );
		}

		$source_file = $this->temporary_pdf_with_to_unicode_cmap(
			'to-unicode-screenplay.pdf',
			'KRAN ZAWSZE KAPIE',
			"INT. KUCHNIA - NOC\nZażółć gęślą jaźń."
		);
		$session     = ImportSession::start_for_source( $source_file );
		$posts       = new FakePostGateway();
		$runner      = new ImportRunner( $this->store, 'unit-test', 60, null, $posts, 'https://local.example.test/' );
		$this->store->save( $session );

		$status = $this->store->find( $session->get_id() )->get_status();
		for ( $tick = 0; $tick < 8 && ImportSession::STATUS_DONE !== $status; ++$tick ) {
			$runner->run( $session->get_id() );
			$status = $this->store->find( $session->get_id() )->get_status();
		}

		$post_content = $this->combined_post_content( $posts );

		$this->assertSame( ImportSession::STATUS_DONE, $this->store->find( $session->get_id() )->get_status() );
		$this->assertSame( 1, $posts->count_posts() );
		$this->assertStringContainsString( 'KRAN ZAWSZE KAPIE', $post_content );
		$this->assertStringContainsString( 'INT. KUCHNIA - NOC', $post_content );
		$this->assertStringContainsString( 'Zażółć gęślą jaźń.', $post_content );
		$this->assertStringNotContainsString( '$<br>', $post_content );
	}

	/**
	 * PDFs with custom Encoding Differences decode speaker labels from glyph names.
	 *
	 * @return void
	 */
	public function test_pdf_encoding_differences_decode_custom_font_bytes() {
		$source_file = $this->temporary_pdf_with_encoding_differences(
			'encoding-differences-screenplay.pdf',
			array(
				'MATKA',
				'PANI GENOWEFA',
				'Zażółć gęślą jaźń.',
			)
		);
		$session     = ImportSession::start_for_source( $source_file );
		$posts       = new FakePostGateway();
		$runner      = new ImportRunner( $this->store, 'unit-test', 60, null, $posts, 'https://local.example.test/' );
		$this->store->save( $session );

		$status = $this->store->find( $session->get_id() )->get_status();
		for ( $tick = 0; $tick < 8 && ImportSession::STATUS_DONE !== $status; ++$tick ) {
			$runner->run( $session->get_id() );
			$status = $this->store->find( $session->get_id() )->get_status();
		}

		$post_content = $this->combined_post_content( $posts );

		$this->assertSame( ImportSession::STATUS_DONE, $this->store->find( $session->get_id() )->get_status() );
		$this->assertSame( 1, $posts->count_posts() );
		$this->assertStringContainsString( 'MATKA', $post_content );
		$this->assertStringContainsString( 'PANI GENOWEFA', $post_content );
		$this->assertStringContainsString( 'Zażółć gęślą jaźń.', $post_content );
		$this->assertStringNotContainsString( 'DdK', $post_content );
	}

	/**
	 * Page-local PDF font resources may reuse the same name for different ToUnicode maps.
	 *
	 * @return void
	 */
	public function test_pdf_page_local_to_unicode_font_resources_do_not_bleed_between_pages() {
		if ( ! function_exists( 'mb_convert_encoding' ) ) {
			$this->markTestSkipped( 'mbstring is required to build UTF-16BE CMap fixtures.' );
		}

		$source_file = $this->temporary_pdf_with_page_local_to_unicode_resources( 'page-local-font-resources.pdf' );
		$session     = ImportSession::start_for_source( $source_file );
		$posts       = new FakePostGateway();
		$runner      = new ImportRunner( $this->store, 'unit-test', 60, null, $posts, 'https://local.example.test/' );
		$this->store->save( $session );

		$status = $this->store->find( $session->get_id() )->get_status();
		for ( $tick = 0; $tick < 8 && ImportSession::STATUS_DONE !== $status; ++$tick ) {
			$runner->run( $session->get_id() );
			$status = $this->store->find( $session->get_id() )->get_status();
		}

		$post_content = $this->combined_post_content( $posts );

		$this->assertSame( ImportSession::STATUS_DONE, $this->store->find( $session->get_id() )->get_status() );
		$this->assertSame( 1, $posts->count_posts() );
		$this->assertStringContainsString( 'PAGE ONE', $post_content );
		$this->assertStringContainsString( 'PAGE TWO', $post_content );
		$this->assertStringContainsString( 'MATKA', $post_content );
		$this->assertStringContainsString( 'KUCHARKA', $post_content );
		$this->assertLessThan( strpos( $post_content, 'PAGE TWO' ), strpos( $post_content, 'PAGE ONE' ) );
	}

	/**
	 * PDF font weight and vertical spacing become block emphasis and paragraph gaps.
	 *
	 * @return void
	 */
	public function test_pdf_font_weight_and_vertical_spacing_become_block_markup() {
		$source_file = $this->temporary_pdf_with_font_weight_and_vertical_gap( 'font-weight-layout-gap.pdf' );
		$session     = ImportSession::start_for_source( $source_file );
		$posts       = new FakePostGateway();
		$runner      = new ImportRunner( $this->store, 'unit-test', 60, null, $posts, 'https://local.example.test/' );
		$this->store->save( $session );

		$status = $this->store->find( $session->get_id() )->get_status();
		for ( $tick = 0; $tick < 8 && ImportSession::STATUS_DONE !== $status; ++$tick ) {
			$runner->run( $session->get_id() );
			$status = $this->store->find( $session->get_id() )->get_status();
		}

		$post_content = $this->combined_post_content( $posts );

		$this->assertSame( ImportSession::STATUS_DONE, $this->store->find( $session->get_id() )->get_status() );
		$this->assertSame( 1, $posts->count_posts() );
		$this->assertStringContainsString( '<p>Introductory paragraph.</p>', $post_content );
		$this->assertStringContainsString( "<p><strong>Important Label</strong><br>\nBody after label.</p>", $post_content );
		$this->assertStringContainsString( "</p>\n<!-- /wp:paragraph -->\n\n<!-- wp:paragraph -->\n<p><strong>Important Label</strong>", $post_content );
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
	 * Creates a PDF fixture whose visible text is encoded as font glyph IDs plus ToUnicode.
	 *
	 * @param string $basename Fixture basename.
	 * @param string $title    Title text.
	 * @param string $body     Body text.
	 * @return string
	 */
	private function temporary_pdf_with_to_unicode_cmap( $basename, $title, $body ) {
		$all_text   = (string) $title . "\n" . (string) $body;
		$characters = preg_split( '//u', $all_text, -1, PREG_SPLIT_NO_EMPTY );
		$this->assertIsArray( $characters );
		$character_map = array();
		$next_code     = 1;
		$cmap_entries  = array();
		$encoded_title = '';
		$encoded_body  = '';
		$encode_text   = function ( $text ) use ( &$character_map, &$next_code, &$cmap_entries ) {
			$encoded = '';
			$chars   = preg_split( '//u', (string) $text, -1, PREG_SPLIT_NO_EMPTY );
			$this->assertIsArray( $chars );

			foreach ( $chars as $char ) {
				if ( ! isset( $character_map[ $char ] ) ) {
					$code                   = strtoupper( str_pad( dechex( $next_code ), 4, '0', STR_PAD_LEFT ) );
					$character_map[ $char ] = $code;
					$cmap_entries[]         = '<' . $code . '> <' . $this->utf16be_hex( $char ) . '>';
					++$next_code;
				}
				$encoded .= $character_map[ $char ];
			}

			return $encoded;
		};

		unset( $characters );

		$encoded_title = $encode_text( $title );
		$encoded_body  = $encode_text( $body );
		$content       = "BT\n/F1 12 Tf\n72 720 Td\n<" . $encoded_title . "> Tj\n0 -18 Td\n<" . $encoded_body . "> Tj\nET";
		$cmap          = "/CIDInit /ProcSet findresource begin\n12 dict begin\nbegincmap\n/CIDSystemInfo << /Registry (Adobe) /Ordering (Identity) /Supplement 0 >> def\n/CMapName /UnitTest def\n/CMapType 2 def\n1 begincodespacerange\n<0000> <FFFF>\nendcodespacerange\n" . count( $cmap_entries ) . " beginbfchar\n" . implode( "\n", $cmap_entries ) . "\nendbfchar\nendcmap\nCMapName currentdict /CMap defineresource pop\nend\nend\n";

		$objects = array(
			"1 0 obj << /Type /Catalog /Pages 2 0 R >> endobj\n",
			"2 0 obj << /Type /Pages /Kids [3 0 R] /Count 1 >> endobj\n",
			"3 0 obj << /Type /Page /Parent 2 0 R /Resources << /Font << /F1 5 0 R >> >> /Contents 4 0 R >> endobj\n",
			'4 0 obj << /Length ' . strlen( $content ) . " >>\nstream\n" . $content . "\nendstream\nendobj\n",
			"5 0 obj << /Type /Font /Subtype /Type0 /BaseFont /UnitTest /Encoding /Identity-H /DescendantFonts [7 0 R] /ToUnicode 6 0 R >> endobj\n",
			'6 0 obj << /Length ' . strlen( $cmap ) . " >>\nstream\n" . $cmap . "\nendstream\nendobj\n",
			"7 0 obj << /Type /Font /Subtype /CIDFontType2 /BaseFont /UnitTest /CIDSystemInfo << /Registry (Adobe) /Ordering (Identity) /Supplement 0 >> >> endobj\n",
		);

		return $this->temporary_file( $basename, "%PDF-1.4\n" . implode( '', $objects ) . "%%EOF\n" );
	}

	/**
	 * Encodes text as UTF-16BE hex for ToUnicode fixtures.
	 *
	 * @param string $text UTF-8 text.
	 * @return string
	 */
	private function utf16be_hex( $text ) {
		$converted = mb_convert_encoding( (string) $text, 'UTF-16BE', 'UTF-8' );
		$this->assertNotFalse( $converted );

		return strtoupper( bin2hex( $converted ) );
	}

	/**
	 * Creates a PDF fixture with a custom one-byte font Encoding Differences map.
	 *
	 * @param string            $basename Fixture basename.
	 * @param array<int,string> $lines    Text lines.
	 * @return string
	 */
	private function temporary_pdf_with_encoding_differences( $basename, array $lines ) {
		$character_map = array();
		$glyph_names   = array();
		$next_code     = 65;
		$content_lines = array( 'BT', '/F1 12 Tf', '72 720 Td' );

		foreach ( $lines as $line_index => $line ) {
			$encoded = '';
			$chars   = preg_split( '//u', (string) $line, -1, PREG_SPLIT_NO_EMPTY );
			$this->assertIsArray( $chars );

			foreach ( $chars as $char ) {
				if ( ! isset( $character_map[ $char ] ) ) {
					$this->assertLessThanOrEqual( 255, $next_code );
					$character_map[ $char ]    = $next_code;
					$glyph_names[ $next_code ] = $this->fixture_pdf_glyph_name( $char );
					++$next_code;
				}
				$encoded .= str_pad( dechex( $character_map[ $char ] ), 2, '0', STR_PAD_LEFT );
			}

			if ( 0 < $line_index ) {
				$content_lines[] = '0 -18 Td';
			}
			$content_lines[] = '<' . strtoupper( $encoded ) . '> Tj';
		}

		$content_lines[] = 'ET';
		$content         = implode( "\n", $content_lines );
		$differences     = array( '65' );
		for ( $code = 65; $code < $next_code; ++$code ) {
			$differences[] = '/' . $glyph_names[ $code ];
		}
		$encoding = '<< /Type /Encoding /Differences [' . implode( ' ', $differences ) . '] >>';
		$objects  = array(
			"1 0 obj << /Type /Catalog /Pages 2 0 R >> endobj\n",
			"2 0 obj << /Type /Pages /Kids [3 0 R] /Count 1 >> endobj\n",
			"3 0 obj << /Type /Page /Parent 2 0 R /Resources << /Font << /F1 5 0 R >> >> /Contents 4 0 R >> endobj\n",
			'4 0 obj << /Length ' . strlen( $content ) . " >>\nstream\n" . $content . "\nendstream\nendobj\n",
			'5 0 obj << /Type /Font /Subtype /Type1 /BaseFont /UnitTest /Encoding ' . $encoding . " >> endobj\n",
		);

		return $this->temporary_file( $basename, "%PDF-1.4\n" . implode( '', $objects ) . "%%EOF\n" );
	}

	/**
	 * Creates a two-page PDF whose pages reuse /F2 with different font objects.
	 *
	 * @param string $basename Fixture basename.
	 * @return string
	 */
	private function temporary_pdf_with_page_local_to_unicode_resources( $basename ) {
		$first_text     = 'PAGE ONE MATKA';
		$second_text    = 'PAGE TWO KUCHARKA';
		$first_encoded  = $this->encode_fixture_text_to_cmap_hex( $first_text, 1 );
		$second_encoded = $this->encode_fixture_text_to_cmap_hex( $second_text, 30 );
		$first_content  = "BT\n/F2 12 Tf\n72 720 Td\n<" . $first_encoded['hex'] . "> Tj\nET";
		$second_content = "BT\n/F2 12 Tf\n72 720 Td\n<" . $second_encoded['hex'] . "> Tj\nET";
		$objects        = array(
			"1 0 obj << /Type /Catalog /Pages 2 0 R >> endobj\n",
			"2 0 obj << /Type /Pages /Kids [3 0 R 4 0 R] /Count 2 >> endobj\n",
			"3 0 obj << /Type /Page /Parent 2 0 R /Resources << /Font << /F2 7 0 R >> >> /Contents 5 0 R >> endobj\n",
			"4 0 obj << /Type /Page /Parent 2 0 R /Resources << /Font << /F2 9 0 R >> >> /Contents 6 0 R >> endobj\n",
			'5 0 obj << /Length ' . strlen( $first_content ) . " >>\nstream\n" . $first_content . "\nendstream\nendobj\n",
			'6 0 obj << /Length ' . strlen( $second_content ) . " >>\nstream\n" . $second_content . "\nendstream\nendobj\n",
			"7 0 obj << /Type /Font /Subtype /Type0 /BaseFont /FirstPageFont /Encoding /Identity-H /DescendantFonts [11 0 R] /ToUnicode 8 0 R >> endobj\n",
			'8 0 obj << /Length ' . strlen( $first_encoded['cmap'] ) . " >>\nstream\n" . $first_encoded['cmap'] . "\nendstream\nendobj\n",
			"9 0 obj << /Type /Font /Subtype /Type0 /BaseFont /SecondPageFont /Encoding /Identity-H /DescendantFonts [12 0 R] /ToUnicode 10 0 R >> endobj\n",
			'10 0 obj << /Length ' . strlen( $second_encoded['cmap'] ) . " >>\nstream\n" . $second_encoded['cmap'] . "\nendstream\nendobj\n",
			"11 0 obj << /Type /Font /Subtype /CIDFontType2 /BaseFont /FirstPageFont /CIDSystemInfo << /Registry (Adobe) /Ordering (Identity) /Supplement 0 >> >> endobj\n",
			"12 0 obj << /Type /Font /Subtype /CIDFontType2 /BaseFont /SecondPageFont /CIDSystemInfo << /Registry (Adobe) /Ordering (Identity) /Supplement 0 >> >> endobj\n",
		);

		return $this->temporary_file( $basename, "%PDF-1.4\n" . implode( '', $objects ) . "%%EOF\n" );
	}

	/**
	 * Creates a PDF fixture with a bold font run separated by a vertical gap.
	 *
	 * @param string $basename Fixture basename.
	 * @return string
	 */
	private function temporary_pdf_with_font_weight_and_vertical_gap( $basename ) {
		$content = "BT\n/F1 12 Tf\n1 0 0 1 72 720 Tm\n(Introductory paragraph.) Tj\n/F2 12 Tf\n1 0 0 1 72 680 Tm\n(Important Label) Tj\n/F1 12 Tf\n1 0 0 1 72 664 Tm\n(Body after label.) Tj\nET";
		$objects = array(
			"1 0 obj << /Type /Catalog /Pages 2 0 R >> endobj\n",
			"2 0 obj << /Type /Pages /Kids [3 0 R] /Count 1 >> endobj\n",
			"3 0 obj << /Type /Page /Parent 2 0 R /Resources << /Font << /F1 5 0 R /F2 6 0 R >> >> /Contents 4 0 R >> endobj\n",
			'4 0 obj << /Length ' . strlen( $content ) . " >>\nstream\n" . $content . "\nendstream\nendobj\n",
			"5 0 obj << /Type /Font /Subtype /Type1 /BaseFont /Helvetica >> endobj\n",
			"6 0 obj << /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold >> endobj\n",
		);

		return $this->temporary_file( $basename, "%PDF-1.4\n" . implode( '', $objects ) . "%%EOF\n" );
	}

	/**
	 * Encodes fixture text as glyph IDs plus a matching ToUnicode CMap.
	 *
	 * @param string $text       Text to encode.
	 * @param int    $first_code First glyph code.
	 * @return array{hex:string,cmap:string}
	 */
	private function encode_fixture_text_to_cmap_hex( $text, $first_code ) {
		$characters   = preg_split( '//u', (string) $text, -1, PREG_SPLIT_NO_EMPTY );
		$encoded      = '';
		$cmap_entries = array();
		$code         = (int) $first_code;
		$seen         = array();

		$this->assertIsArray( $characters );

		foreach ( $characters as $char ) {
			if ( ! isset( $seen[ $char ] ) ) {
				$seen[ $char ]  = strtoupper( str_pad( dechex( $code ), 4, '0', STR_PAD_LEFT ) );
				$cmap_entries[] = '<' . $seen[ $char ] . '> <' . $this->utf16be_hex( $char ) . '>';
				++$code;
			}
			$encoded .= $seen[ $char ];
		}

		$cmap = "/CIDInit /ProcSet findresource begin\n12 dict begin\nbegincmap\n/CIDSystemInfo << /Registry (Adobe) /Ordering (Identity) /Supplement 0 >> def\n/CMapName /PageLocalUnitTest def\n/CMapType 2 def\n1 begincodespacerange\n<0000> <FFFF>\nendcodespacerange\n" . count( $cmap_entries ) . " beginbfchar\n" . implode( "\n", $cmap_entries ) . "\nendbfchar\nendcmap\nCMapName currentdict /CMap defineresource pop\nend\nend\n";

		return array(
			'hex'  => $encoded,
			'cmap' => $cmap,
		);
	}

	/**
	 * Returns a PDF glyph name for a fixture character.
	 *
	 * @param string $char Character.
	 * @return string
	 */
	private function fixture_pdf_glyph_name( $char ) {
		$glyphs = array(
			' ' => 'space',
			'.' => 'period',
			'ą' => 'aogonek',
			'ć' => 'cacute',
			'ę' => 'eogonek',
			'ł' => 'lslash',
			'ń' => 'nacute',
			'ó' => 'oacute',
			'ś' => 'sacute',
			'ź' => 'zacute',
			'ż' => 'zdotaccent',
		);

		return isset( $glyphs[ $char ] ) ? $glyphs[ $char ] : $char;
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
			$objects[] = $image_id . ' 0 obj << /Type /XObject /Subtype /Image /Width 64 /Height 64 /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /DCTDecode /Length ' . strlen( $image ) . " >>\nstream\n"
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
