<?php
/**
 * Tests for HTML block inference.
 *
 * @package UniversalImporter\Tests\Unit\Import
 */

namespace UniversalImporter\Tests\Unit\Import;

use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use UniversalImporter\Import\ImportHtmlBlockConverter;

/**
 * Covers shared HTML-to-block conversion behavior.
 */
final class ImportHtmlBlockConverterTest extends TestCase {
	/**
	 * Document metadata copied from source heads is ignored during block inference.
	 *
	 * @return void
	 */
	public function test_document_metadata_elements_are_ignored() {
		$converter      = new ImportHtmlBlockConverter();
		$summary        = array();
		$stylesheet_rel = 'stylesheet';

		$markup = $converter->convert(
			'<base href="https://legacy.example.test/">'
			. '<title>Legacy source title</title>'
			. '<meta name="generator" content="Legacy CMS">'
			. '<meta property="og:title" content="Legacy social title">'
			. '<link rel="canonical" href="https://legacy.example.test/source">'
			. '<link rel="' . $stylesheet_rel . '" href="/assets/theme.css">'
			. '<link rel="preload" as="style" href="/assets/critical.css">'
			. '<style>.legacy-theme{color:red}</style>'
			. '<p>Body copy.</p>',
			$summary
		);

		$this->assertSame( 'structured', $summary['html_block_conversion'] );
		$this->assertSame( 1, $summary['html_inferred_block_count'] );
		$this->assertSame( 0, $summary['html_classic_block_count'] );
		$this->assertStringContainsString( '<!-- wp:paragraph -->', $markup );
		$this->assertStringContainsString( '<p>Body copy.</p>', $markup );
		$this->assertStringNotContainsString( '<base', $markup );
		$this->assertStringNotContainsString( '<title', $markup );
		$this->assertStringNotContainsString( '<meta', $markup );
		$this->assertStringNotContainsString( '<link', $markup );
		$this->assertStringNotContainsString( '<style', $markup );
		$this->assertStringNotContainsString( '<!-- wp:freeform -->', $markup );

		$metadata_only_markup = $converter->convert(
			'<title>Metadata only</title><meta name="generator" content="Legacy CMS"><style>.legacy-theme{color:red}</style>',
			$summary
		);

		$this->assertSame( '', $metadata_only_markup );
		$this->assertSame( 'structured', $summary['html_block_conversion'] );
		$this->assertSame( 0, $summary['html_inferred_block_count'] );
		$this->assertSame( 0, $summary['html_classic_block_count'] );
	}

	/**
	 * Visible noscript fallback content unwraps when it can become native blocks.
	 *
	 * @return void
	 */
	public function test_noscript_fallback_content_unwraps_when_structured() {
		$converter = new ImportHtmlBlockConverter();
		$summary   = array();

		$markup = $converter->convert(
			'<noscript><h2>Fallback heading</h2><p>Fallback copy.</p></noscript>'
			. '<p>Regular copy.</p>',
			$summary
		);

		$this->assertSame( 'structured', $summary['html_block_conversion'] );
		$this->assertSame( 3, $summary['html_inferred_block_count'] );
		$this->assertSame( 0, $summary['html_classic_block_count'] );
		$this->assertStringContainsString( '<!-- wp:heading {"level":2} -->', $markup );
		$this->assertStringContainsString( '<h2>Fallback heading</h2>', $markup );
		$this->assertStringContainsString( '<p>Fallback copy.</p>', $markup );
		$this->assertStringContainsString( '<p>Regular copy.</p>', $markup );
		$this->assertStringNotContainsString( '<noscript', $markup );
		$this->assertStringNotContainsString( '<!-- wp:freeform -->', $markup );
	}

	/**
	 * Ambiguous noscript fallback content remains a conservative Classic block.
	 *
	 * @return void
	 */
	public function test_ambiguous_noscript_fallback_content_remains_classic() {
		$converter = new ImportHtmlBlockConverter();
		$summary   = array();

		$markup = $converter->convert(
			'<noscript><custom-widget>Keep widget fallback.</custom-widget></noscript>',
			$summary
		);

		$this->assertSame( 'mixed', $summary['html_block_conversion'] );
		$this->assertSame( 0, $summary['html_inferred_block_count'] );
		$this->assertSame( 1, $summary['html_classic_block_count'] );
		$this->assertStringContainsString( '<!-- wp:freeform -->', $markup );
		$this->assertStringContainsString( '<noscript><custom-widget>Keep widget fallback.</custom-widget></noscript>', $markup );
	}

	/**
	 * Visible noframes fallback content unwraps when it can become native blocks.
	 *
	 * @return void
	 */
	public function test_noframes_fallback_content_unwraps_when_structured() {
		$converter = new ImportHtmlBlockConverter();
		$summary   = array();

		$markup = $converter->convert(
			'<noframes><h2>Frame fallback</h2><p>Fallback copy.</p></noframes>'
			. '<p>Regular copy.</p>',
			$summary
		);

		$this->assertSame( 'structured', $summary['html_block_conversion'] );
		$this->assertSame( 3, $summary['html_inferred_block_count'] );
		$this->assertSame( 0, $summary['html_classic_block_count'] );
		$this->assertStringContainsString( '<!-- wp:heading {"level":2} -->', $markup );
		$this->assertStringContainsString( '<h2>Frame fallback</h2>', $markup );
		$this->assertStringContainsString( '<p>Fallback copy.</p>', $markup );
		$this->assertStringContainsString( '<p>Regular copy.</p>', $markup );
		$this->assertStringNotContainsString( '<noframes', $markup );
		$this->assertStringNotContainsString( '<!-- wp:freeform -->', $markup );

		$ambiguous_markup = $converter->convert( '<noframes><custom-widget>Keep frame fallback.</custom-widget></noframes>', $summary );

		$this->assertSame( 'mixed', $summary['html_block_conversion'] );
		$this->assertSame( 0, $summary['html_inferred_block_count'] );
		$this->assertSame( 1, $summary['html_classic_block_count'] );
		$this->assertStringContainsString( '<!-- wp:freeform -->', $ambiguous_markup );
		$this->assertStringContainsString( '<noframes><custom-widget>Keep frame fallback.</custom-widget></noframes>', $ambiguous_markup );
	}

	/**
	 * Visible noembed fallback content unwraps when it can become native blocks.
	 *
	 * @return void
	 */
	public function test_noembed_fallback_content_unwraps_when_structured() {
		$converter = new ImportHtmlBlockConverter();
		$summary   = array();

		$markup = $converter->convert(
			'<noembed><h2>Embed fallback</h2><p>Fallback copy.</p></noembed>'
			. '<p>Regular copy.</p>',
			$summary
		);

		$this->assertSame( 'structured', $summary['html_block_conversion'] );
		$this->assertSame( 3, $summary['html_inferred_block_count'] );
		$this->assertSame( 0, $summary['html_classic_block_count'] );
		$this->assertStringContainsString( '<!-- wp:heading {"level":2} -->', $markup );
		$this->assertStringContainsString( '<h2>Embed fallback</h2>', $markup );
		$this->assertStringContainsString( '<p>Fallback copy.</p>', $markup );
		$this->assertStringContainsString( '<p>Regular copy.</p>', $markup );
		$this->assertStringNotContainsString( '<noembed', $markup );
		$this->assertStringNotContainsString( '<!-- wp:freeform -->', $markup );

		$ambiguous_markup = $converter->convert( '<noembed><custom-widget>Keep embed fallback.</custom-widget></noembed>', $summary );

		$this->assertSame( 'mixed', $summary['html_block_conversion'] );
		$this->assertSame( 0, $summary['html_inferred_block_count'] );
		$this->assertSame( 1, $summary['html_classic_block_count'] );
		$this->assertStringContainsString( '<!-- wp:freeform -->', $ambiguous_markup );
		$this->assertStringContainsString( '<noembed><custom-widget>Keep embed fallback.</custom-widget></noembed>', $ambiguous_markup );

		$empty_markup = $converter->convert( '<noembed>   </noembed>', $summary );

		$this->assertSame( 'mixed', $summary['html_block_conversion'] );
		$this->assertSame( 0, $summary['html_inferred_block_count'] );
		$this->assertSame( 1, $summary['html_classic_block_count'] );
		$this->assertStringContainsString( '<noembed>   </noembed>', $empty_markup );
	}

	/**
	 * Visible applet fallback content unwraps when it can become native blocks.
	 *
	 * @return void
	 */
	public function test_applet_fallback_content_unwraps_when_structured() {
		$converter = new ImportHtmlBlockConverter();
		$summary   = array();

		$markup = $converter->convert(
			'<applet code="Legacy.class"><param name="movie" value="legacy"><h2>Applet fallback</h2><p>Use the archive.</p></applet>'
			. '<p>Regular copy.</p>',
			$summary
		);

		$this->assertSame( 'structured', $summary['html_block_conversion'] );
		$this->assertSame( 3, $summary['html_inferred_block_count'] );
		$this->assertSame( 0, $summary['html_classic_block_count'] );
		$this->assertStringContainsString( '<!-- wp:heading {"level":2} -->', $markup );
		$this->assertStringContainsString( '<h2>Applet fallback</h2>', $markup );
		$this->assertStringContainsString( '<p>Use the archive.</p>', $markup );
		$this->assertStringContainsString( '<p>Regular copy.</p>', $markup );
		$this->assertStringNotContainsString( '<applet', $markup );
		$this->assertStringNotContainsString( '<param', $markup );
		$this->assertStringNotContainsString( '<!-- wp:freeform -->', $markup );

		$param_only_markup = $converter->convert( '<applet code="Legacy.class"><param name="movie" value="legacy"></applet>', $summary );

		$this->assertSame( 'mixed', $summary['html_block_conversion'] );
		$this->assertSame( 0, $summary['html_inferred_block_count'] );
		$this->assertSame( 1, $summary['html_classic_block_count'] );
		$this->assertStringContainsString( '<applet code="Legacy.class"><param name="movie" value="legacy"></applet>', $param_only_markup );

		$ambiguous_markup = $converter->convert( '<applet><custom-widget>Opaque</custom-widget></applet>', $summary );

		$this->assertSame( 'mixed', $summary['html_block_conversion'] );
		$this->assertSame( 0, $summary['html_inferred_block_count'] );
		$this->assertSame( 1, $summary['html_classic_block_count'] );
		$this->assertStringContainsString( '<applet><custom-widget>Opaque</custom-widget></applet>', $ambiguous_markup );
	}

	/**
	 * Fallback-only object content unwraps when it can become native blocks.
	 *
	 * @return void
	 */
	public function test_fallback_only_object_content_unwraps_when_structured() {
		$converter = new ImportHtmlBlockConverter();
		$summary   = array();

		$markup = $converter->convert(
			'<object><h2>Object fallback</h2><p>Use the accessible copy.</p></object>'
			. '<p>Regular copy.</p>',
			$summary
		);

		$this->assertSame( 'structured', $summary['html_block_conversion'] );
		$this->assertSame( 3, $summary['html_inferred_block_count'] );
		$this->assertSame( 0, $summary['html_classic_block_count'] );
		$this->assertStringContainsString( '<!-- wp:heading {"level":2} -->', $markup );
		$this->assertStringContainsString( '<h2>Object fallback</h2>', $markup );
		$this->assertStringContainsString( '<p>Use the accessible copy.</p>', $markup );
		$this->assertStringContainsString( '<p>Regular copy.</p>', $markup );
		$this->assertStringNotContainsString( '<object', $markup );
		$this->assertStringNotContainsString( '<!-- wp:freeform -->', $markup );

		$resource_markup = $converter->convert(
			'<object data="movie.swf"><p>Keep resource fallback.</p></object>',
			$summary
		);

		$this->assertSame( 'mixed', $summary['html_block_conversion'] );
		$this->assertSame( 0, $summary['html_inferred_block_count'] );
		$this->assertSame( 1, $summary['html_classic_block_count'] );
		$this->assertStringContainsString( '<object data="movie.swf"><p>Keep resource fallback.</p></object>', $resource_markup );

		$param_markup = $converter->convert(
			'<object><param name="movie" value="legacy"><p>Keep configured fallback.</p></object>',
			$summary
		);

		$this->assertSame( 'mixed', $summary['html_block_conversion'] );
		$this->assertSame( 0, $summary['html_inferred_block_count'] );
		$this->assertSame( 1, $summary['html_classic_block_count'] );
		$this->assertStringContainsString( '<param name="movie" value="legacy">', $param_markup );

		$ambiguous_markup = $converter->convert( '<object><custom-widget>Opaque</custom-widget></object>', $summary );

		$this->assertSame( 'mixed', $summary['html_block_conversion'] );
		$this->assertSame( 0, $summary['html_inferred_block_count'] );
		$this->assertSame( 1, $summary['html_classic_block_count'] );
		$this->assertStringContainsString( '<object><custom-widget>Opaque</custom-widget></object>', $ambiguous_markup );
	}

	/**
	 * Non-form fieldset content unwraps when it can become native blocks.
	 *
	 * @return void
	 */
	public function test_non_form_fieldset_content_unwraps_when_structured() {
		$converter = new ImportHtmlBlockConverter();
		$summary   = array();

		$markup = $converter->convert(
			'<fieldset><legend id="group-title">Group title</legend><p>Group copy.</p></fieldset>'
			. '<p>Regular copy.</p>',
			$summary
		);

		$this->assertSame( 'structured', $summary['html_block_conversion'] );
		$this->assertSame( 3, $summary['html_inferred_block_count'] );
		$this->assertSame( 0, $summary['html_classic_block_count'] );
		$this->assertStringContainsString( '<!-- wp:paragraph {"anchor":"group-title"} -->', $markup );
		$this->assertStringContainsString( '<p id="group-title">Group title</p>', $markup );
		$this->assertStringContainsString( '<p>Group copy.</p>', $markup );
		$this->assertStringContainsString( '<p>Regular copy.</p>', $markup );
		$this->assertStringNotContainsString( '<fieldset', $markup );
		$this->assertStringNotContainsString( '<legend', $markup );
		$this->assertStringNotContainsString( '<!-- wp:freeform -->', $markup );

		$legend_markup = $converter->convert( '<legend>Orphan legend</legend>', $summary );

		$this->assertSame( 'structured', $summary['html_block_conversion'] );
		$this->assertSame( 1, $summary['html_inferred_block_count'] );
		$this->assertSame( 0, $summary['html_classic_block_count'] );
		$this->assertStringContainsString( '<p>Orphan legend</p>', $legend_markup );
		$this->assertStringNotContainsString( '<legend', $legend_markup );

		$control_markup = $converter->convert(
			'<fieldset><legend>Contact</legend><label>Email <input type="email" name="email"></label></fieldset>',
			$summary
		);

		$this->assertSame( 'mixed', $summary['html_block_conversion'] );
		$this->assertSame( 0, $summary['html_inferred_block_count'] );
		$this->assertSame( 1, $summary['html_classic_block_count'] );
		$this->assertStringContainsString( '<fieldset><legend>Contact</legend><label>Email <input type="email" name="email"></label></fieldset>', $control_markup );

		$missing_legend_markup = $converter->convert( '<fieldset><p>Untitled group.</p></fieldset>', $summary );

		$this->assertSame( 'mixed', $summary['html_block_conversion'] );
		$this->assertSame( 0, $summary['html_inferred_block_count'] );
		$this->assertSame( 1, $summary['html_classic_block_count'] );
		$this->assertStringContainsString( '<fieldset><p>Untitled group.</p></fieldset>', $missing_legend_markup );

		$ambiguous_markup = $converter->convert( '<fieldset><legend>Group</legend><custom-widget>Opaque</custom-widget></fieldset>', $summary );

		$this->assertSame( 'mixed', $summary['html_block_conversion'] );
		$this->assertSame( 0, $summary['html_inferred_block_count'] );
		$this->assertSame( 1, $summary['html_classic_block_count'] );
		$this->assertStringContainsString( '<fieldset><legend>Group</legend><custom-widget>Opaque</custom-widget></fieldset>', $ambiguous_markup );
	}

	/**
	 * Visible dialog content unwraps when it can become native blocks.
	 *
	 * @return void
	 */
	public function test_open_dialog_content_unwraps_when_structured() {
		$converter = new ImportHtmlBlockConverter();
		$summary   = array();

		$markup = $converter->convert(
			'<dialog open><h2>Notice</h2><p>Dialog copy.</p></dialog>'
			. '<p>Regular copy.</p>',
			$summary
		);

		$this->assertSame( 'structured', $summary['html_block_conversion'] );
		$this->assertSame( 3, $summary['html_inferred_block_count'] );
		$this->assertSame( 0, $summary['html_classic_block_count'] );
		$this->assertStringContainsString( '<!-- wp:heading {"level":2} -->', $markup );
		$this->assertStringContainsString( '<h2>Notice</h2>', $markup );
		$this->assertStringContainsString( '<p>Dialog copy.</p>', $markup );
		$this->assertStringContainsString( '<p>Regular copy.</p>', $markup );
		$this->assertStringNotContainsString( '<dialog', $markup );
		$this->assertStringNotContainsString( '<!-- wp:freeform -->', $markup );

		$closed_markup = $converter->convert( '<dialog><h2>Hidden notice</h2><p>Hidden copy.</p></dialog>', $summary );

		$this->assertSame( 'mixed', $summary['html_block_conversion'] );
		$this->assertSame( 0, $summary['html_inferred_block_count'] );
		$this->assertSame( 1, $summary['html_classic_block_count'] );
		$this->assertStringContainsString( '<dialog><h2>Hidden notice</h2><p>Hidden copy.</p></dialog>', $closed_markup );

		$form_markup = $converter->convert( '<dialog open><form method="dialog"><button>Close</button></form></dialog>', $summary );

		$this->assertSame( 'mixed', $summary['html_block_conversion'] );
		$this->assertSame( 0, $summary['html_inferred_block_count'] );
		$this->assertSame( 1, $summary['html_classic_block_count'] );
		$this->assertStringContainsString( '<dialog open><form method="dialog"><button>Close</button></form></dialog>', $form_markup );

		$ambiguous_markup = $converter->convert( '<dialog open><custom-widget>Opaque</custom-widget></dialog>', $summary );

		$this->assertSame( 'mixed', $summary['html_block_conversion'] );
		$this->assertSame( 0, $summary['html_inferred_block_count'] );
		$this->assertSame( 1, $summary['html_classic_block_count'] );
		$this->assertStringContainsString( '<dialog open><custom-widget>Opaque</custom-widget></dialog>', $ambiguous_markup );
	}

	/**
	 * Legacy center wrappers unwrap when their children can become native blocks.
	 *
	 * @return void
	 */
	public function test_legacy_center_wrappers_unwrap_when_structured() {
		$converter = new ImportHtmlBlockConverter();
		$summary   = array();

		$markup = $converter->convert(
			'<center><h2>Centered heading</h2><p>Centered copy.</p></center>',
			$summary
		);

		$this->assertSame( 'structured', $summary['html_block_conversion'] );
		$this->assertSame( 2, $summary['html_inferred_block_count'] );
		$this->assertSame( 0, $summary['html_classic_block_count'] );
		$this->assertStringContainsString( '<!-- wp:heading {"level":2,"textAlign":"center"} -->', $markup );
		$this->assertStringContainsString( '<h2 class="has-text-align-center">Centered heading</h2>', $markup );
		$this->assertStringContainsString( '<!-- wp:paragraph {"align":"center"} -->', $markup );
		$this->assertStringContainsString( '<p class="has-text-align-center">Centered copy.</p>', $markup );
		$this->assertStringNotContainsString( '<center', $markup );
		$this->assertStringNotContainsString( '<!-- wp:freeform -->', $markup );
	}

	/**
	 * Ambiguous legacy center wrappers remain a conservative Classic block.
	 *
	 * @return void
	 */
	public function test_ambiguous_legacy_center_wrappers_remain_classic() {
		$converter = new ImportHtmlBlockConverter();
		$summary   = array();

		$markup = $converter->convert(
			'<center><custom-widget>Centered widget.</custom-widget></center>',
			$summary
		);

		$this->assertSame( 'mixed', $summary['html_block_conversion'] );
		$this->assertSame( 0, $summary['html_inferred_block_count'] );
		$this->assertSame( 1, $summary['html_classic_block_count'] );
		$this->assertStringContainsString( '<!-- wp:freeform -->', $markup );
		$this->assertStringContainsString( '<center><custom-widget>Centered widget.</custom-widget></center>', $markup );
	}

	/**
	 * Legacy font wrappers become Paragraph blocks when they contain inline content.
	 *
	 * @return void
	 */
	public function test_legacy_font_wrappers_become_native_paragraphs() {
		$converter = new ImportHtmlBlockConverter();
		$summary   = array();

		$markup = $converter->convert(
			'<font id="legacy-font" color="red" face="Arial">Legacy <strong>font</strong> copy.</font>',
			$summary
		);

		$this->assertSame( 'structured', $summary['html_block_conversion'] );
		$this->assertSame( 1, $summary['html_inferred_block_count'] );
		$this->assertSame( 0, $summary['html_classic_block_count'] );
		$this->assertStringContainsString( '<!-- wp:paragraph {"anchor":"legacy-font"} -->', $markup );
		$this->assertStringContainsString( '<p id="legacy-font">Legacy <strong>font</strong> copy.</p>', $markup );
		$this->assertStringNotContainsString( '<font', $markup );
		$this->assertStringNotContainsString( 'color="red"', $markup );
		$this->assertStringNotContainsString( 'face="Arial"', $markup );
		$this->assertStringNotContainsString( '<!-- wp:freeform -->', $markup );
	}

	/**
	 * Ambiguous legacy font wrappers remain a conservative Classic block.
	 *
	 * @return void
	 */
	public function test_ambiguous_legacy_font_wrappers_remain_classic() {
		$converter = new ImportHtmlBlockConverter();
		$summary   = array();

		$markup = $converter->convert(
			'<font color="red"><div>Block fallback copy.</div></font>',
			$summary
		);

		$this->assertSame( 'mixed', $summary['html_block_conversion'] );
		$this->assertSame( 0, $summary['html_inferred_block_count'] );
		$this->assertSame( 1, $summary['html_classic_block_count'] );
		$this->assertStringContainsString( '<!-- wp:freeform -->', $markup );
		$this->assertStringContainsString( '<font color="red"><div>Block fallback copy.</div></font>', $markup );
	}

	/**
	 * Inline address wrappers become valid Paragraph blocks.
	 *
	 * @return void
	 */
	public function test_inline_address_wrappers_become_native_paragraphs() {
		$converter = new ImportHtmlBlockConverter();
		$summary   = array();

		$markup = $converter->convert(
			'<address id="legacy-address">123 Legacy Street<br><a href="mailto:test@example.com">test@example.com</a></address>'
			. '<address><p>Office Suite</p><p><a href="mailto:team@example.com">team@example.com</a></p></address>',
			$summary
		);

		$this->assertSame( 'structured', $summary['html_block_conversion'] );
		$this->assertSame( 3, $summary['html_inferred_block_count'] );
		$this->assertSame( 0, $summary['html_classic_block_count'] );
		$this->assertStringContainsString( '<!-- wp:paragraph {"anchor":"legacy-address"} -->', $markup );
		$this->assertStringContainsString( '<p id="legacy-address">123 Legacy Street<br><a href="mailto:test@example.com">test@example.com</a></p>', $markup );
		$this->assertStringContainsString( '<p>Office Suite</p>', $markup );
		$this->assertStringContainsString( '<p><a href="mailto:team@example.com">team@example.com</a></p>', $markup );
		$this->assertStringNotContainsString( '<address', $markup );
		$this->assertStringNotContainsString( '<!-- wp:freeform -->', $markup );
	}

	/**
	 * Ambiguous address wrappers remain a conservative Classic block.
	 *
	 * @return void
	 */
	public function test_ambiguous_address_wrappers_remain_classic() {
		$converter = new ImportHtmlBlockConverter();
		$summary   = array();

		$markup = $converter->convert(
			'<address><div>Block address</div></address>',
			$summary
		);

		$this->assertSame( 'mixed', $summary['html_block_conversion'] );
		$this->assertSame( 0, $summary['html_inferred_block_count'] );
		$this->assertSame( 1, $summary['html_classic_block_count'] );
		$this->assertStringContainsString( '<!-- wp:freeform -->', $markup );
		$this->assertStringContainsString( '<address><div>Block address</div></address>', $markup );
	}

	/**
	 * Static obsolete inline tags can remain inside generated Paragraph blocks.
	 *
	 * @return void
	 */
	public function test_static_obsolete_inline_tags_become_native_paragraphs() {
		$converter = new ImportHtmlBlockConverter();
		$summary   = array();

		$markup = $converter->convert(
			'<big>Large legacy text.</big>'
			. '<strike>Struck legacy text.</strike>'
			. '<tt>Teletype legacy text.</tt>',
			$summary
		);

		$this->assertSame( 'structured', $summary['html_block_conversion'] );
		$this->assertSame( 3, $summary['html_inferred_block_count'] );
		$this->assertSame( 0, $summary['html_classic_block_count'] );
		$this->assertStringContainsString( '<p><big>Large legacy text.</big></p>', $markup );
		$this->assertStringContainsString( '<p><strike>Struck legacy text.</strike></p>', $markup );
		$this->assertStringContainsString( '<p><tt>Teletype legacy text.</tt></p>', $markup );
		$this->assertStringNotContainsString( '<!-- wp:freeform -->', $markup );

		$animated_markup = $converter->convert( '<blink>Animated legacy text.</blink>', $summary );

		$this->assertSame( 'mixed', $summary['html_block_conversion'] );
		$this->assertSame( 0, $summary['html_inferred_block_count'] );
		$this->assertSame( 1, $summary['html_classic_block_count'] );
		$this->assertStringContainsString( '<!-- wp:freeform -->', $animated_markup );
		$this->assertStringContainsString( '<blink>Animated legacy text.</blink>', $animated_markup );
	}

	/**
	 * Obsolete marquee wrappers unwrap when their visible content is structured.
	 *
	 * @return void
	 */
	public function test_obsolete_marquee_wrappers_unwrap_when_structured() {
		$converter = new ImportHtmlBlockConverter();
		$summary   = array();

		$markup = $converter->convert(
			'<marquee>Moving legacy text.</marquee>'
			. '<marquee><h2>Legacy alert</h2><p>Moving copy.</p></marquee>',
			$summary
		);

		$this->assertSame( 'structured', $summary['html_block_conversion'] );
		$this->assertSame( 3, $summary['html_inferred_block_count'] );
		$this->assertSame( 0, $summary['html_classic_block_count'] );
		$this->assertStringContainsString( '<p>Moving legacy text.</p>', $markup );
		$this->assertStringContainsString( '<!-- wp:heading {"level":2} -->', $markup );
		$this->assertStringContainsString( '<h2>Legacy alert</h2>', $markup );
		$this->assertStringContainsString( '<p>Moving copy.</p>', $markup );
		$this->assertStringNotContainsString( '<marquee', $markup );
		$this->assertStringNotContainsString( '<!-- wp:freeform -->', $markup );

		$empty_markup = $converter->convert( '<marquee>   </marquee>', $summary );

		$this->assertSame( 'mixed', $summary['html_block_conversion'] );
		$this->assertSame( 0, $summary['html_inferred_block_count'] );
		$this->assertSame( 1, $summary['html_classic_block_count'] );
		$this->assertStringContainsString( '<marquee>   </marquee>', $empty_markup );

		$ambiguous_markup = $converter->convert( '<marquee><custom-widget>Moving widget.</custom-widget></marquee>', $summary );

		$this->assertSame( 'mixed', $summary['html_block_conversion'] );
		$this->assertSame( 0, $summary['html_inferred_block_count'] );
		$this->assertSame( 1, $summary['html_classic_block_count'] );
		$this->assertStringContainsString( '<marquee><custom-widget>Moving widget.</custom-widget></marquee>', $ambiguous_markup );
	}

	/**
	 * Standalone inline phrasing elements become native Paragraph blocks.
	 *
	 * @return void
	 */
	public function test_standalone_inline_phrasing_elements_become_native_paragraphs() {
		$converter = new ImportHtmlBlockConverter();
		$summary   = array();

		$markup = $converter->convert(
			'<abbr title="World Health Organization">WHO</abbr>'
			. '<acronym title="As Soon As Possible">ASAP</acronym>'
			. '<kbd>Ctrl+C</kbd>'
			. '<mark>Highlighted</mark>',
			$summary
		);

		$this->assertSame( 'structured', $summary['html_block_conversion'] );
		$this->assertSame( 4, $summary['html_inferred_block_count'] );
		$this->assertSame( 0, $summary['html_classic_block_count'] );
		$this->assertStringContainsString( '<p><abbr title="World Health Organization">WHO</abbr></p>', $markup );
		$this->assertStringContainsString( '<p><acronym title="As Soon As Possible">ASAP</acronym></p>', $markup );
		$this->assertStringContainsString( '<p><kbd>Ctrl+C</kbd></p>', $markup );
		$this->assertStringContainsString( '<p><mark>Highlighted</mark></p>', $markup );
		$this->assertStringNotContainsString( '<!-- wp:freeform -->', $markup );

		$empty_markup = $converter->convert( '<abbr title="Empty"></abbr>', $summary );

		$this->assertSame( 'mixed', $summary['html_block_conversion'] );
		$this->assertSame( 0, $summary['html_inferred_block_count'] );
		$this->assertSame( 1, $summary['html_classic_block_count'] );
		$this->assertStringContainsString( '<!-- wp:freeform -->', $empty_markup );
		$this->assertStringContainsString( '<abbr title="Empty"></abbr>', $empty_markup );
	}

	/**
	 * Standalone ruby annotations become native Paragraph blocks.
	 *
	 * @return void
	 */
	public function test_standalone_ruby_annotations_become_native_paragraphs() {
		$converter = new ImportHtmlBlockConverter();
		$summary   = array();

		$markup = $converter->convert(
			'<ruby>kan<rp>(</rp><rt>reading</rt><rp>)</rp></ruby>',
			$summary
		);

		$this->assertSame( 'structured', $summary['html_block_conversion'] );
		$this->assertSame( 1, $summary['html_inferred_block_count'] );
		$this->assertSame( 0, $summary['html_classic_block_count'] );
		$this->assertStringContainsString( '<p><ruby>kan<rp>(</rp><rt>reading</rt><rp>)</rp></ruby></p>', $markup );
		$this->assertStringNotContainsString( '<!-- wp:freeform -->', $markup );

		$ambiguous_markup = $converter->convert( '<ruby><div>Block ruby</div></ruby>', $summary );

		$this->assertSame( 'mixed', $summary['html_block_conversion'] );
		$this->assertSame( 0, $summary['html_inferred_block_count'] );
		$this->assertSame( 1, $summary['html_classic_block_count'] );
		$this->assertStringContainsString( '<!-- wp:freeform -->', $ambiguous_markup );
		$this->assertStringContainsString( '<ruby><div>Block ruby</div></ruby>', $ambiguous_markup );
	}

	/**
	 * Standalone meter and progress elements become native Paragraph blocks.
	 *
	 * @return void
	 */
	public function test_standalone_meter_and_progress_elements_become_native_paragraphs() {
		$converter = new ImportHtmlBlockConverter();
		$summary   = array();

		$markup = $converter->convert(
			'<meter min="0" max="100" value="72">72%</meter>'
			. '<progress max="100" value="45">45%</progress>',
			$summary
		);

		$this->assertSame( 'structured', $summary['html_block_conversion'] );
		$this->assertSame( 2, $summary['html_inferred_block_count'] );
		$this->assertSame( 0, $summary['html_classic_block_count'] );
		$this->assertStringContainsString( '<p><meter min="0" max="100" value="72">72%</meter></p>', $markup );
		$this->assertStringContainsString( '<p><progress max="100" value="45">45%</progress></p>', $markup );
		$this->assertStringNotContainsString( '<!-- wp:freeform -->', $markup );

		$ambiguous_markup = $converter->convert( '<meter value="0.5"><div>Block meter</div></meter>', $summary );

		$this->assertSame( 'mixed', $summary['html_block_conversion'] );
		$this->assertSame( 0, $summary['html_inferred_block_count'] );
		$this->assertSame( 1, $summary['html_classic_block_count'] );
		$this->assertStringContainsString( '<!-- wp:freeform -->', $ambiguous_markup );
		$this->assertStringContainsString( '<meter value="0.5"><div>Block meter</div></meter>', $ambiguous_markup );
	}

	/**
	 * Standalone output elements become native Paragraph blocks.
	 *
	 * @return void
	 */
	public function test_standalone_output_elements_become_native_paragraphs() {
		$converter = new ImportHtmlBlockConverter();
		$summary   = array();

		$markup = $converter->convert(
			'<output name="total" for="subtotal tax">42</output>',
			$summary
		);

		$this->assertSame( 'structured', $summary['html_block_conversion'] );
		$this->assertSame( 1, $summary['html_inferred_block_count'] );
		$this->assertSame( 0, $summary['html_classic_block_count'] );
		$this->assertStringContainsString( '<p><output name="total" for="subtotal tax">42</output></p>', $markup );
		$this->assertStringNotContainsString( '<!-- wp:freeform -->', $markup );

		$ambiguous_markup = $converter->convert( '<output><div>Block output</div></output>', $summary );

		$this->assertSame( 'mixed', $summary['html_block_conversion'] );
		$this->assertSame( 0, $summary['html_inferred_block_count'] );
		$this->assertSame( 1, $summary['html_classic_block_count'] );
		$this->assertStringContainsString( '<!-- wp:freeform -->', $ambiguous_markup );
		$this->assertStringContainsString( '<output><div>Block output</div></output>', $ambiguous_markup );
	}

	/**
	 * Standalone text-only labels become native Paragraph blocks.
	 *
	 * @return void
	 */
	public function test_standalone_text_only_labels_become_native_paragraphs() {
		$converter = new ImportHtmlBlockConverter();
		$summary   = array();

		$markup = $converter->convert(
			'<label for="email">Email address</label>',
			$summary
		);

		$this->assertSame( 'structured', $summary['html_block_conversion'] );
		$this->assertSame( 1, $summary['html_inferred_block_count'] );
		$this->assertSame( 0, $summary['html_classic_block_count'] );
		$this->assertStringContainsString( '<p><label for="email">Email address</label></p>', $markup );
		$this->assertStringNotContainsString( '<!-- wp:freeform -->', $markup );

		$control_markup = $converter->convert( '<label><input type="email" name="email"> Email</label>', $summary );

		$this->assertSame( 'mixed', $summary['html_block_conversion'] );
		$this->assertSame( 0, $summary['html_inferred_block_count'] );
		$this->assertSame( 1, $summary['html_classic_block_count'] );
		$this->assertStringContainsString( '<!-- wp:freeform -->', $control_markup );
		$this->assertStringContainsString( '<label><input type="email" name="email"> Email</label>', $control_markup );
	}

	/**
	 * Standalone canvas elements with fallback text become native Paragraph blocks.
	 *
	 * @return void
	 */
	public function test_standalone_canvas_fallback_text_becomes_native_paragraphs() {
		$converter = new ImportHtmlBlockConverter();
		$summary   = array();

		$markup = $converter->convert(
			'<canvas width="300" height="150">Chart fallback</canvas>',
			$summary
		);

		$this->assertSame( 'structured', $summary['html_block_conversion'] );
		$this->assertSame( 1, $summary['html_inferred_block_count'] );
		$this->assertSame( 0, $summary['html_classic_block_count'] );
		$this->assertStringContainsString( '<p><canvas width="300" height="150">Chart fallback</canvas></p>', $markup );
		$this->assertStringNotContainsString( '<!-- wp:freeform -->', $markup );

		$empty_markup = $converter->convert( '<canvas width="300" height="150"></canvas>', $summary );

		$this->assertSame( 'mixed', $summary['html_block_conversion'] );
		$this->assertSame( 0, $summary['html_inferred_block_count'] );
		$this->assertSame( 1, $summary['html_classic_block_count'] );
		$this->assertStringContainsString( '<!-- wp:freeform -->', $empty_markup );
		$this->assertStringContainsString( '<canvas width="300" height="150"></canvas>', $empty_markup );

		$ambiguous_markup = $converter->convert( '<canvas><div>Block canvas</div></canvas>', $summary );

		$this->assertSame( 'mixed', $summary['html_block_conversion'] );
		$this->assertSame( 0, $summary['html_inferred_block_count'] );
		$this->assertSame( 1, $summary['html_classic_block_count'] );
		$this->assertStringContainsString( '<!-- wp:freeform -->', $ambiguous_markup );
		$this->assertStringContainsString( '<canvas><div>Block canvas</div></canvas>', $ambiguous_markup );
	}

	/**
	 * Semantic wrappers unwrap when all children can become native blocks.
	 *
	 * @return void
	 */
	public function test_semantic_wrappers_are_unwrapped_when_children_are_structured() {
		$converter = new ImportHtmlBlockConverter();
		$summary   = array();

		$markup = $converter->convert(
			'<article><header><h1>Story</h1></header><section><p>Body copy.</p><figure><img src="photo.jpg" alt="Photo"><figcaption>Photo <em>caption</em>.</figcaption></figure></section></article>',
			$summary
		);

		$this->assertSame( 'structured', $summary['html_block_conversion'] );
		$this->assertSame( 3, $summary['html_inferred_block_count'] );
		$this->assertSame( 0, $summary['html_classic_block_count'] );
		$this->assertStringContainsString( '<!-- wp:heading {"level":1} -->', $markup );
		$this->assertStringContainsString( '<!-- wp:paragraph -->', $markup );
		$this->assertStringContainsString( '<!-- wp:image -->', $markup );
		$this->assertStringContainsString( '<figcaption class="wp-element-caption">Photo <em>caption</em>.</figcaption>', $markup );
		$this->assertStringNotContainsString( '<article', $markup );
		$this->assertStringNotContainsString( '<section', $markup );
		$this->assertStringNotContainsString( '<!-- wp:freeform -->', $markup );
	}

	/**
	 * Hgroup wrappers unwrap only when all children can become native blocks.
	 *
	 * @return void
	 */
	public function test_hgroup_wrappers_are_unwrapped_when_children_are_structured() {
		$converter = new ImportHtmlBlockConverter();
		$summary   = array();

		$markup = $converter->convert(
			'<hgroup><h1>Main title</h1><p>Subtitle text.</p></hgroup>'
			. '<hgroup><h2>Section</h2><h3>Subtitle</h3></hgroup>',
			$summary
		);

		$this->assertSame( 'structured', $summary['html_block_conversion'] );
		$this->assertSame( 4, $summary['html_inferred_block_count'] );
		$this->assertSame( 0, $summary['html_classic_block_count'] );
		$this->assertStringContainsString( '<!-- wp:heading {"level":1} -->', $markup );
		$this->assertStringContainsString( '<h1>Main title</h1>', $markup );
		$this->assertStringContainsString( '<p>Subtitle text.</p>', $markup );
		$this->assertStringContainsString( '<!-- wp:heading {"level":2} -->', $markup );
		$this->assertStringContainsString( '<!-- wp:heading {"level":3} -->', $markup );
		$this->assertStringNotContainsString( '<hgroup', $markup );
		$this->assertStringNotContainsString( '<!-- wp:freeform -->', $markup );

		$ambiguous_markup = $converter->convert( '<hgroup><custom-title>Opaque</custom-title></hgroup>', $summary );

		$this->assertSame( 'mixed', $summary['html_block_conversion'] );
		$this->assertSame( 0, $summary['html_inferred_block_count'] );
		$this->assertSame( 1, $summary['html_classic_block_count'] );
		$this->assertStringContainsString( '<!-- wp:freeform -->', $ambiguous_markup );
		$this->assertStringContainsString( '<hgroup><custom-title>Opaque</custom-title></hgroup>', $ambiguous_markup );
	}

	/**
	 * Table captions are normalized into WordPress table block captions.
	 *
	 * @return void
	 */
	public function test_table_captions_become_wordpress_table_block_captions() {
		$converter = new ImportHtmlBlockConverter();
		$summary   = array();

		$markup = $converter->convert(
			'<figure><table><caption>Source table caption</caption><thead><tr><th>Name</th><th>Total</th></tr></thead><tbody><tr><td>Ada</td><td>4</td></tr></tbody></table><br><figcaption>Visible table caption</figcaption></figure>',
			$summary
		);

		$this->assertSame( 'structured', $summary['html_block_conversion'] );
		$this->assertSame( 1, $summary['html_inferred_block_count'] );
		$this->assertSame( 0, $summary['html_classic_block_count'] );
		$this->assertStringContainsString( '<!-- wp:table -->', $markup );
		$this->assertStringContainsString( '<thead><tr><th>Name</th><th>Total</th></tr></thead>', $markup );
		$this->assertStringContainsString( '<figcaption class="wp-element-caption">Visible table caption</figcaption>', $markup );
		$this->assertStringNotContainsString( '<caption>', $markup );
		$this->assertStringNotContainsString( '<!-- wp:freeform -->', $markup );
	}

	/**
	 * Table figures with extra direct children keep a conservative fallback.
	 *
	 * @return void
	 */
	public function test_ambiguous_table_figures_keep_classic_fallback() {
		$converter = new ImportHtmlBlockConverter();
		$summary   = array();

		$markup = $converter->convert(
			'<figure id="extended-table" class="wp-block-table alignwide">'
			. '<table><tbody><tr><td>Ada</td><td>4</td></tr></tbody></table>'
			. '<figcaption>Visible table caption.</figcaption>'
			. '<p class="table-note">Keep this source note with the imported table.</p>'
			. '</figure>',
			$summary
		);

		$this->assertSame( 'mixed', $summary['html_block_conversion'] );
		$this->assertSame( 0, $summary['html_inferred_block_count'] );
		$this->assertSame( 1, $summary['html_classic_block_count'] );
		$this->assertStringContainsString( '<!-- wp:freeform -->', $markup );
		$this->assertStringContainsString( '<p class="table-note">Keep this source note with the imported table.</p>', $markup );
		$this->assertStringNotContainsString( '<!-- wp:table', $markup );
	}

	/**
	 * Clear imported table layout classes become native Table block metadata.
	 *
	 * @return void
	 */
	public function test_table_layout_metadata_is_preserved() {
		$converter = new ImportHtmlBlockConverter();
		$summary   = array();

		$markup = $converter->convert(
			'<figure class="wp-block-table AlignWide Is-Style-Stripes"><table class="Has-Fixed-Layout"><thead><tr><th>Plan</th><th>Total</th></tr></thead><tbody><tr><td>Pro</td><td>12</td></tr></tbody></table><figcaption>Styled table caption.</figcaption></figure>',
			$summary
		);

		$this->assertSame( 'structured', $summary['html_block_conversion'] );
		$this->assertSame( 1, $summary['html_inferred_block_count'] );
		$this->assertSame( 0, $summary['html_classic_block_count'] );
		$this->assertStringContainsString( '<!-- wp:table {"align":"wide","hasFixedLayout":true,"className":"is-style-stripes"} -->', $markup );
		$this->assertStringContainsString( '<figure class="wp-block-table alignwide is-style-stripes"><table class="has-fixed-layout">', $markup );
		$this->assertStringContainsString( '<figcaption class="wp-element-caption">Styled table caption.</figcaption>', $markup );
		$this->assertStringNotContainsString( '<!-- wp:freeform -->', $markup );
	}

	/**
	 * Plain imported table metadata is preserved from table attributes and classes.
	 *
	 * @return void
	 */
	public function test_plain_table_layout_metadata_is_preserved() {
		$converter = new ImportHtmlBlockConverter();
		$summary   = array();

		$markup = $converter->convert(
			'<table id="legacy-pricing" class="table-striped" align="center" style="table-layout: fixed"><tbody><tr><td>Starter</td><td>9</td></tr></tbody></table>',
			$summary
		);

		$this->assertSame( 'structured', $summary['html_block_conversion'] );
		$this->assertSame( 1, $summary['html_inferred_block_count'] );
		$this->assertSame( 0, $summary['html_classic_block_count'] );
		$this->assertStringContainsString( '<!-- wp:table {"align":"center","hasFixedLayout":true,"className":"is-style-stripes","anchor":"legacy-pricing"} -->', $markup );
		$this->assertStringContainsString( '<figure class="wp-block-table aligncenter is-style-stripes" id="legacy-pricing"><table class="has-fixed-layout"><tbody><tr><td>Starter</td><td>9</td></tr></tbody></table></figure>', $markup );
		$this->assertStringNotContainsString( 'table-layout: fixed', $markup );
		$this->assertStringNotContainsString( '<table id="legacy-pricing"', $markup );
		$this->assertStringNotContainsString( '<!-- wp:freeform -->', $markup );
	}

	/**
	 * Consecutive top-level orphan table rows become one native Table block.
	 *
	 * @return void
	 */
	public function test_orphan_table_rows_become_native_tables() {
		$converter = new ImportHtmlBlockConverter();
		$summary   = array();

		$markup = $converter->convert(
			'<tr><td>One</td><td>Two</td></tr>'
			. "\n"
			. '<tr><td>Three</td><td>Four</td></tr>'
			. '<p>After table.</p>',
			$summary
		);

		$this->assertSame( 'structured', $summary['html_block_conversion'] );
		$this->assertSame( 2, $summary['html_inferred_block_count'] );
		$this->assertSame( 0, $summary['html_classic_block_count'] );
		$this->assertStringContainsString( '<!-- wp:table -->', $markup );
		$this->assertStringContainsString( '<figure class="wp-block-table"><table><tr><td>One</td><td>Two</td></tr><tr><td>Three</td><td>Four</td></tr></table></figure>', $markup );
		$this->assertStringContainsString( '<p>After table.</p>', $markup );
		$this->assertStringNotContainsString( '<!-- wp:freeform -->', $markup );
	}

	/**
	 * Consecutive top-level orphan table cells become a single-row native Table block.
	 *
	 * @return void
	 */
	public function test_orphan_table_cells_become_native_tables() {
		$converter = new ImportHtmlBlockConverter();
		$summary   = array();

		$markup = $converter->convert(
			'<th>Heading</th><td>Cell</td>',
			$summary
		);

		$this->assertSame( 'structured', $summary['html_block_conversion'] );
		$this->assertSame( 1, $summary['html_inferred_block_count'] );
		$this->assertSame( 0, $summary['html_classic_block_count'] );
		$this->assertStringContainsString( '<!-- wp:table -->', $markup );
		$this->assertStringContainsString( '<figure class="wp-block-table"><table><tr><th>Heading</th><td>Cell</td></tr></table></figure>', $markup );
		$this->assertStringNotContainsString( '<!-- wp:freeform -->', $markup );
	}

	/**
	 * Consecutive top-level orphan table sections become one native Table block.
	 *
	 * @return void
	 */
	public function test_orphan_table_sections_become_native_tables() {
		$converter = new ImportHtmlBlockConverter();
		$summary   = array();

		$markup = $converter->convert(
			'<thead><tr><th>Plan</th></tr></thead>'
			. "\n"
			. '<tbody><tr><td>Pro</td></tr></tbody>',
			$summary
		);

		$this->assertSame( 'structured', $summary['html_block_conversion'] );
		$this->assertSame( 1, $summary['html_inferred_block_count'] );
		$this->assertSame( 0, $summary['html_classic_block_count'] );
		$this->assertStringContainsString( '<!-- wp:table -->', $markup );
		$this->assertStringContainsString( '<figure class="wp-block-table"><table><thead><tr><th>Plan</th></tr></thead><tbody><tr><td>Pro</td></tr></tbody></table></figure>', $markup );
		$this->assertStringNotContainsString( '<!-- wp:freeform -->', $markup );

		$bad_markup = $converter->convert( '<thead><div>Bad section</div></thead>', $summary );

		$this->assertSame( 'mixed', $summary['html_block_conversion'] );
		$this->assertSame( 0, $summary['html_inferred_block_count'] );
		$this->assertSame( 1, $summary['html_classic_block_count'] );
		$this->assertStringContainsString( '<!-- wp:freeform -->', $bad_markup );
		$this->assertStringContainsString( '<thead><div>Bad section</div></thead>', $bad_markup );
	}

	/**
	 * Leading orphan table metadata joins following orphan row content.
	 *
	 * @return void
	 */
	public function test_orphan_table_metadata_joins_following_rows() {
		$converter = new ImportHtmlBlockConverter();
		$summary   = array();

		$markup = $converter->convert(
			'<caption>Quarterly results</caption>'
			. "\n"
			. '<colgroup><col span="2"></colgroup>'
			. '<tr><th>Quarter</th><th>Total</th></tr>',
			$summary
		);

		$this->assertSame( 'structured', $summary['html_block_conversion'] );
		$this->assertSame( 1, $summary['html_inferred_block_count'] );
		$this->assertSame( 0, $summary['html_classic_block_count'] );
		$this->assertStringContainsString( '<!-- wp:table -->', $markup );
		$this->assertStringContainsString( '<figure class="wp-block-table"><table><colgroup><col span="2"></colgroup><tr><th>Quarter</th><th>Total</th></tr></table><figcaption class="wp-element-caption">Quarterly results</figcaption></figure>', $markup );
		$this->assertStringNotContainsString( '<!-- wp:freeform -->', $markup );

		$metadata_only_markup = $converter->convert( '<caption>Orphan title</caption><colgroup><col></colgroup>', $summary );

		$this->assertSame( 'mixed', $summary['html_block_conversion'] );
		$this->assertSame( 0, $summary['html_inferred_block_count'] );
		$this->assertSame( 2, $summary['html_classic_block_count'] );
		$this->assertStringContainsString( '<!-- wp:freeform -->', $metadata_only_markup );
		$this->assertStringContainsString( '<caption>Orphan title</caption>', $metadata_only_markup );

		$bad_markup = $converter->convert( '<caption>Bad table</caption><thead><div>Bad section</div></thead>', $summary );

		$this->assertSame( 'mixed', $summary['html_block_conversion'] );
		$this->assertSame( 0, $summary['html_inferred_block_count'] );
		$this->assertSame( 2, $summary['html_classic_block_count'] );
		$this->assertStringContainsString( '<caption>Bad table</caption>', $bad_markup );
		$this->assertStringContainsString( '<thead><div>Bad section</div></thead>', $bad_markup );
	}

	/**
	 * Trailing orphan table captions join immediately preceding orphan table content.
	 *
	 * @return void
	 */
	public function test_trailing_orphan_table_captions_join_previous_table_content() {
		$converter = new ImportHtmlBlockConverter();
		$summary   = array();

		$markup = $converter->convert(
			'<tr><td>Q1</td></tr><caption>Quarterly results</caption>'
			. '<p>After table.</p>'
			. '<caption>Late caption</caption>',
			$summary
		);

		$this->assertSame( 'mixed', $summary['html_block_conversion'] );
		$this->assertSame( 2, $summary['html_inferred_block_count'] );
		$this->assertSame( 1, $summary['html_classic_block_count'] );
		$this->assertStringContainsString( '<figure class="wp-block-table"><table><tr><td>Q1</td></tr></table><figcaption class="wp-element-caption">Quarterly results</figcaption></figure>', $markup );
		$this->assertStringContainsString( '<p>After table.</p>', $markup );
		$this->assertStringContainsString( '<!-- wp:freeform -->', $markup );
		$this->assertStringContainsString( '<caption>Late caption</caption>', $markup );

		$cell_markup = $converter->convert( '<th>Quarter</th><td>Total</td><caption>Loose cells caption</caption>', $summary );

		$this->assertSame( 'structured', $summary['html_block_conversion'] );
		$this->assertSame( 1, $summary['html_inferred_block_count'] );
		$this->assertSame( 0, $summary['html_classic_block_count'] );
		$this->assertStringContainsString( '<figure class="wp-block-table"><table><tr><th>Quarter</th><td>Total</td></tr></table><figcaption class="wp-element-caption">Loose cells caption</figcaption></figure>', $cell_markup );
		$this->assertStringNotContainsString( '<!-- wp:freeform -->', $cell_markup );

		$section_markup = $converter->convert( '<tbody><tr><td>Body</td></tr></tbody><caption>Body caption</caption>', $summary );

		$this->assertSame( 'structured', $summary['html_block_conversion'] );
		$this->assertSame( 1, $summary['html_inferred_block_count'] );
		$this->assertSame( 0, $summary['html_classic_block_count'] );
		$this->assertStringContainsString( '<figure class="wp-block-table"><table><tbody><tr><td>Body</td></tr></tbody></table><figcaption class="wp-element-caption">Body caption</figcaption></figure>', $section_markup );
		$this->assertStringNotContainsString( '<!-- wp:freeform -->', $section_markup );
	}

	/**
	 * Imported table inline alignment styles become native Table alignment metadata.
	 *
	 * @return void
	 */
	public function test_table_inline_alignment_styles_are_preserved() {
		$converter = new ImportHtmlBlockConverter();
		$summary   = array();

		$markup = $converter->convert(
			'<table id="centered-schedule" style="margin-left: auto; margin-right: auto"><tbody><tr><td>Center</td></tr></tbody></table>'
			. '<figure id="right-table" style="float: right"><table><tbody><tr><td>Right</td></tr></tbody></table></figure>',
			$summary
		);

		$this->assertSame( 'structured', $summary['html_block_conversion'] );
		$this->assertSame( 2, $summary['html_inferred_block_count'] );
		$this->assertSame( 0, $summary['html_classic_block_count'] );
		$this->assertStringContainsString( '<!-- wp:table {"align":"center","anchor":"centered-schedule"} -->', $markup );
		$this->assertStringContainsString( '<figure class="wp-block-table aligncenter" id="centered-schedule"><table><tbody><tr><td>Center</td></tr></tbody></table></figure>', $markup );
		$this->assertStringContainsString( '<!-- wp:table {"align":"right","anchor":"right-table"} -->', $markup );
		$this->assertStringContainsString( '<figure class="wp-block-table alignright" id="right-table"><table><tbody><tr><td>Right</td></tr></tbody></table></figure>', $markup );
		$this->assertStringNotContainsString( 'margin-left', $markup );
		$this->assertStringNotContainsString( 'float: right', $markup );
		$this->assertStringNotContainsString( '<!-- wp:freeform -->', $markup );
	}

	/**
	 * Image and table fragment anchors are preserved on native block wrappers.
	 *
	 * @return void
	 */
	public function test_image_and_table_anchors_are_preserved() {
		$converter = new ImportHtmlBlockConverter();
		$summary   = array();

		$markup = $converter->convert(
			'<img id="source-image" class="alignright size-large" src="photo.jpg" alt="Photo">'
			. '<figure id="source-table" class="wp-block-table"><table><tbody><tr><td>Ada</td></tr></tbody></table></figure>'
			. '<table id="plain-source-table"><tbody><tr><td>Grace</td></tr></tbody></table>',
			$summary
		);

		$this->assertSame( 'structured', $summary['html_block_conversion'] );
		$this->assertSame( 3, $summary['html_inferred_block_count'] );
		$this->assertSame( 0, $summary['html_classic_block_count'] );
		$this->assertStringContainsString( '<!-- wp:image {"align":"right","sizeSlug":"large","anchor":"source-image"} -->', $markup );
		$this->assertStringContainsString( '<figure class="wp-block-image alignright size-large" id="source-image"><img class="alignright size-large" src="photo.jpg" alt="Photo"></figure>', $markup );
		$this->assertStringNotContainsString( '<img id="source-image"', $markup );
		$this->assertStringContainsString( '<!-- wp:table {"anchor":"source-table"} -->', $markup );
		$this->assertStringContainsString( '<figure class="wp-block-table" id="source-table"><table><tbody><tr><td>Ada</td></tr></tbody></table></figure>', $markup );
		$this->assertStringContainsString( '<!-- wp:table {"anchor":"plain-source-table"} -->', $markup );
		$this->assertStringContainsString( '<figure class="wp-block-table" id="plain-source-table"><table><tbody><tr><td>Grace</td></tr></tbody></table></figure>', $markup );
		$this->assertStringNotContainsString( '<!-- wp:freeform -->', $markup );
	}

	/**
	 * Heading ids are preserved as block anchors for fragment links.
	 *
	 * @return void
	 */
	public function test_heading_ids_are_preserved_as_block_anchors() {
		$converter = new ImportHtmlBlockConverter();
		$summary   = array();

		$markup = $converter->convert(
			'<h2 id="part-one" class="has-text-align-center">Part One</h2>'
			. '<h3 style="text-align: right !important">Right Heading</h3>'
			. '<h4 align="justify">Plain Heading</h4>'
			. '<h5 id="legacy-heading-align" class="alignright alignwide">Legacy aligned heading</h5>',
			$summary
		);

		$this->assertSame( 'structured', $summary['html_block_conversion'] );
		$this->assertStringContainsString( '<!-- wp:heading {"level":2,"textAlign":"center","anchor":"part-one"} -->', $markup );
		$this->assertStringContainsString( '<h2 class="has-text-align-center" id="part-one">Part One</h2>', $markup );
		$this->assertStringContainsString( '<!-- wp:heading {"level":3,"textAlign":"right"} -->', $markup );
		$this->assertStringContainsString( '<h3 class="has-text-align-right">Right Heading</h3>', $markup );
		$this->assertStringContainsString( '<!-- wp:heading {"level":4} -->', $markup );
		$this->assertStringContainsString( '<h4>Plain Heading</h4>', $markup );
		$this->assertStringContainsString( '<!-- wp:heading {"level":5,"textAlign":"right","anchor":"legacy-heading-align"} -->', $markup );
		$this->assertStringContainsString( '<h5 class="has-text-align-right" id="legacy-heading-align">Legacy aligned heading</h5>', $markup );
		$this->assertStringNotContainsString( 'alignwide">Legacy aligned heading', $markup );
	}

	/**
	 * Separator ids and safe core style metadata are preserved.
	 *
	 * @return void
	 */
	public function test_separator_metadata_is_preserved() {
		$converter = new ImportHtmlBlockConverter();
		$summary   = array();

		$markup = $converter->convert(
			'<hr id="section-break" class="wp-block-separator AlignWide Is-Style-Dots">'
			. '<hr id="wide-rule" class="wp-block-separator AlignFull Is-Style-Wide">'
			. '<hr id="unsafe-style" class="alignleft is-style-fancy" onclick="alert(1)">',
			$summary
		);

		$this->assertSame( 'structured', $summary['html_block_conversion'] );
		$this->assertSame( 3, $summary['html_inferred_block_count'] );
		$this->assertSame( 0, $summary['html_classic_block_count'] );
		$this->assertStringContainsString( '<!-- wp:separator {"align":"wide","className":"is-style-dots","anchor":"section-break"} -->', $markup );
		$this->assertStringContainsString( '<hr class="wp-block-separator has-alpha-channel-opacity alignwide is-style-dots" id="section-break"/>', $markup );
		$this->assertStringContainsString( '<!-- wp:separator {"align":"full","className":"is-style-wide","anchor":"wide-rule"} -->', $markup );
		$this->assertStringContainsString( '<hr class="wp-block-separator has-alpha-channel-opacity alignfull is-style-wide" id="wide-rule"/>', $markup );
		$this->assertStringContainsString( '<!-- wp:separator {"anchor":"unsafe-style"} -->', $markup );
		$this->assertStringContainsString( '<hr class="wp-block-separator has-alpha-channel-opacity" id="unsafe-style"/>', $markup );
		$this->assertStringNotContainsString( 'onclick', $markup );
		$this->assertStringNotContainsString( 'is-style-fancy', $markup );
		$this->assertStringNotContainsString( 'alignleft', $markup );
		$this->assertStringNotContainsString( '<!-- wp:freeform -->', $markup );
	}

	/**
	 * Preformatted code snippets become native code blocks.
	 *
	 * @return void
	 */
	public function test_pre_code_snippets_become_code_blocks() {
		$converter = new ImportHtmlBlockConverter();
		$summary   = array();

		$markup = $converter->convert(
			'<pre id="source-code" class="language-php"><code>&lt;?php echo "Hello";</code></pre>'
			. '<pre><code id="inner-code" class="lang-bash">composer test</code></pre>'
			. '<pre id="standalone-pre-code" class="language-js">const ready = true;</pre>'
			. '<pre id="case-language-code" class="Language-Go">fmt.Println("ok")</pre>'
			. '<pre id="data-language-code" data-language="SQL">SELECT 1;</pre>'
			. '<code id="data-lang-code" data-lang="TS">const typed = true;</code>'
			. '<code id="standalone-code">wp importer tick</code>',
			$summary
		);

		$this->assertSame( 'structured', $summary['html_block_conversion'] );
		$this->assertSame( 7, $summary['html_inferred_block_count'] );
		$this->assertStringContainsString( '<!-- wp:code {"anchor":"source-code","className":"language-php"} -->', $markup );
		$this->assertStringContainsString( '<pre class="wp-block-code language-php" id="source-code"><code>&lt;?php echo "Hello";</code></pre>', $markup );
		$this->assertStringContainsString( '<!-- wp:code {"anchor":"inner-code","className":"lang-bash"} -->', $markup );
		$this->assertStringContainsString( '<pre class="wp-block-code lang-bash" id="inner-code"><code>composer test</code></pre>', $markup );
		$this->assertStringContainsString( '<!-- wp:code {"anchor":"standalone-pre-code","className":"language-js"} -->', $markup );
		$this->assertStringContainsString( '<pre class="wp-block-code language-js" id="standalone-pre-code"><code>const ready = true;</code></pre>', $markup );
		$this->assertStringContainsString( '<!-- wp:code {"anchor":"case-language-code","className":"language-go"} -->', $markup );
		$this->assertStringContainsString( '<pre class="wp-block-code language-go" id="case-language-code"><code>fmt.Println("ok")</code></pre>', $markup );
		$this->assertStringContainsString( '<!-- wp:code {"anchor":"data-language-code","className":"language-sql"} -->', $markup );
		$this->assertStringContainsString( '<pre class="wp-block-code language-sql" id="data-language-code"><code>SELECT 1;</code></pre>', $markup );
		$this->assertStringContainsString( '<!-- wp:code {"anchor":"data-lang-code","className":"language-ts"} -->', $markup );
		$this->assertStringContainsString( '<pre class="wp-block-code language-ts" id="data-lang-code"><code>const typed = true;</code></pre>', $markup );
		$this->assertStringContainsString( '<!-- wp:code {"anchor":"standalone-code"} -->', $markup );
		$this->assertStringContainsString( '<pre class="wp-block-code" id="standalone-code"><code>wp importer tick</code></pre>', $markup );
		$this->assertStringNotContainsString( '<code id="inner-code"', $markup );
		$this->assertStringNotContainsString( '<code id="standalone-code"', $markup );
		$this->assertStringNotContainsString( '<!-- wp:preformatted -->', $markup );
		$this->assertStringNotContainsString( '<!-- wp:freeform -->', $markup );
	}

	/**
	 * Preformatted source ids are preserved as native block anchors.
	 *
	 * @return void
	 */
	public function test_preformatted_anchor_is_preserved() {
		$converter = new ImportHtmlBlockConverter();
		$summary   = array();

		$markup = $converter->convert(
			'<pre id="source-pre">Line one<br>Line two</pre>'
			. '<pre id="unsafe-language-pre" data-language="php;alert(1)">Line three</pre>',
			$summary
		);

		$this->assertSame( 'structured', $summary['html_block_conversion'] );
		$this->assertSame( 2, $summary['html_inferred_block_count'] );
		$this->assertStringContainsString( '<!-- wp:preformatted {"anchor":"source-pre"} -->', $markup );
		$this->assertStringContainsString( '<pre class="wp-block-preformatted" id="source-pre">Line one<br>Line two</pre>', $markup );
		$this->assertStringContainsString( '<!-- wp:preformatted {"anchor":"unsafe-language-pre"} -->', $markup );
		$this->assertStringContainsString( '<pre class="wp-block-preformatted" id="unsafe-language-pre">Line three</pre>', $markup );
		$this->assertStringNotContainsString( 'php;alert', $markup );
		$this->assertStringNotContainsString( 'language-php;alert', $markup );
		$this->assertStringNotContainsString( '<!-- wp:code -->', $markup );
		$this->assertStringNotContainsString( '<!-- wp:freeform -->', $markup );
	}

	/**
	 * Text-only obsolete preformatted tags become native Preformatted blocks.
	 *
	 * @return void
	 */
	public function test_text_only_obsolete_preformatted_tags_become_native_preformatted_blocks() {
		$converter = new ImportHtmlBlockConverter();
		$summary   = array();

		$markup = $converter->convert(
			'<listing id="legacy-listing">line one' . "\n" . 'line two</listing>'
			. '<xmp id="legacy-xmp">literal &lt;div&gt; markup</xmp>'
			. '<plaintext id="legacy-plaintext">plain text',
			$summary
		);

		$this->assertSame( 'structured', $summary['html_block_conversion'] );
		$this->assertSame( 3, $summary['html_inferred_block_count'] );
		$this->assertSame( 0, $summary['html_classic_block_count'] );
		$this->assertStringContainsString( '<!-- wp:preformatted {"anchor":"legacy-listing"} -->', $markup );
		$this->assertStringContainsString( '<pre class="wp-block-preformatted" id="legacy-listing">line one' . "\n" . 'line two</pre>', $markup );
		$this->assertStringContainsString( '<!-- wp:preformatted {"anchor":"legacy-xmp"} -->', $markup );
		$this->assertStringContainsString( '<pre class="wp-block-preformatted" id="legacy-xmp">literal &lt;div&gt; markup</pre>', $markup );
		$this->assertStringContainsString( '<!-- wp:preformatted {"anchor":"legacy-plaintext"} -->', $markup );
		$this->assertStringContainsString( '<pre class="wp-block-preformatted" id="legacy-plaintext">plain text</pre>', $markup );
		$this->assertStringNotContainsString( '<listing', $markup );
		$this->assertStringNotContainsString( '<xmp', $markup );
		$this->assertStringNotContainsString( '<plaintext', $markup );
		$this->assertStringNotContainsString( '<!-- wp:freeform -->', $markup );

		$ambiguous_markup = $converter->convert( '<xmp><div>Literal markup</div></xmp><plaintext>plain <b>text</b>', $summary );

		$this->assertSame( 'mixed', $summary['html_block_conversion'] );
		$this->assertSame( 0, $summary['html_inferred_block_count'] );
		$this->assertSame( 2, $summary['html_classic_block_count'] );
		$this->assertStringContainsString( '<xmp><div>Literal markup</div></xmp>', $ambiguous_markup );
		$this->assertStringContainsString( '<plaintext>plain <b>text</b></plaintext>', $ambiguous_markup );
	}

	/**
	 * Standalone imported shortcodes become native Shortcode blocks.
	 *
	 * @return void
	 */
	public function test_standalone_shortcodes_become_native_shortcode_blocks() {
		$converter = new ImportHtmlBlockConverter();
		$summary   = array();

		$markup = $converter->convert(
			'<p id="source-gallery-shortcode">[gallery ids="1,2"]</p>'
			. '[caption id="attachment_9"]Caption text[/caption]'
			. '<p id="source-shortcode-sequence">[gallery ids="4"][playlist]</p>'
			. '<p>[editor\'s note]</p>'
			. '<p>Intro [gallery ids="3"]</p>'
			. '<p>[script]alert(1)[/script]</p>',
			$summary
		);

		$this->assertSame( 'structured', $summary['html_block_conversion'] );
		$this->assertSame( 6, $summary['html_inferred_block_count'] );
		$this->assertSame( 0, $summary['html_classic_block_count'] );
		$this->assertStringContainsString( "<!-- wp:shortcode {\"anchor\":\"source-gallery-shortcode\"} -->\n[gallery ids=\"1,2\"]\n<!-- /wp:shortcode -->", $markup );
		$this->assertStringContainsString( "<!-- wp:shortcode -->\n[caption id=\"attachment_9\"]Caption text[/caption]\n<!-- /wp:shortcode -->", $markup );
		$this->assertStringContainsString( "<!-- wp:shortcode {\"anchor\":\"source-shortcode-sequence\"} -->\n[gallery ids=\"4\"][playlist]\n<!-- /wp:shortcode -->", $markup );
		$this->assertStringContainsString( '<p>[editor\'s note]</p>', $markup );
		$this->assertStringContainsString( '<p>Intro [gallery ids="3"]</p>', $markup );
		$this->assertStringContainsString( '<p>[script]alert(1)[/script]</p>', $markup );
		$this->assertStringNotContainsString( '<p id="source-gallery-shortcode">', $markup );
		$this->assertStringNotContainsString( '<!-- wp:freeform -->', $markup );
	}

	/**
	 * Classic WordPress media shortcodes become native media blocks when obvious.
	 *
	 * @return void
	 */
	public function test_classic_media_shortcodes_become_native_blocks() {
		$converter = new ImportHtmlBlockConverter();
		$summary   = array();

		$markup = $converter->convert(
			'<p id="source-embed-shortcode" class="alignwide">[embed]https://www.youtube.com/embed/dQw4w9WgXcQ[/embed]</p>'
			. '<p id="source-video-shortcode" style="margin: 0 auto">[video mp4="media/movie.mp4" poster="media/poster.jpg" preload="metadata" loop="true"]</p>'
			. '<p id="source-body-video-shortcode" class="alignwide">[video poster="javascript:alert(1)" preload="AUTO" loop="off"]media/body-movie.mp4[/video]</p>'
			. '<p id="source-audio-shortcode" class="alignwide">[audio mp3="media/song.mp3" preload="none"]</p>'
			. '<p id="source-body-audio-shortcode" class="aligncenter">[audio autoplay="yes" muted="1"]media/body-song.mp3[/audio]</p>'
			. '<p>[embed]/local/unrecognized[/embed]</p>',
			$summary
		);

		$this->assertSame( 'structured', $summary['html_block_conversion'] );
		$this->assertSame( 6, $summary['html_inferred_block_count'] );
		$this->assertSame( 0, $summary['html_classic_block_count'] );
		$this->assertStringContainsString( '<!-- wp:embed {"url":"https:\/\/www.youtube.com\/watch?v=dQw4w9WgXcQ","type":"video","providerNameSlug":"youtube","responsive":true,"align":"wide","anchor":"source-embed-shortcode"} -->', $markup );
		$this->assertStringContainsString( '<figure class="wp-block-embed is-type-video is-provider-youtube wp-block-embed-youtube alignwide" id="source-embed-shortcode"><div class="wp-block-embed__wrapper">https://www.youtube.com/watch?v=dQw4w9WgXcQ</div></figure>', $markup );
		$this->assertStringContainsString( '<!-- wp:video {"src":"media\/movie.mp4","controls":true,"loop":true,"poster":"media\/poster.jpg","preload":"metadata","align":"center","anchor":"source-video-shortcode"} -->', $markup );
		$this->assertStringContainsString( '<figure class="wp-block-video aligncenter" id="source-video-shortcode"><video controls="controls" src="media/movie.mp4" loop="loop" poster="media/poster.jpg" preload="metadata"></video></figure>', $markup );
		$this->assertStringNotContainsString( 'margin: 0 auto', $markup );
		$this->assertStringContainsString( '<!-- wp:video {"src":"media\/body-movie.mp4","controls":true,"preload":"auto","align":"wide","anchor":"source-body-video-shortcode"} -->', $markup );
		$this->assertStringContainsString( '<figure class="wp-block-video alignwide" id="source-body-video-shortcode"><video controls="controls" src="media/body-movie.mp4" preload="auto"></video></figure>', $markup );
		$this->assertStringNotContainsString( 'javascript:alert(1)', $markup );
		$this->assertStringContainsString( '<!-- wp:audio {"src":"media\/song.mp3","controls":true,"preload":"none","align":"wide","anchor":"source-audio-shortcode"} -->', $markup );
		$this->assertStringContainsString( '<figure class="wp-block-audio alignwide" id="source-audio-shortcode"><audio controls="controls" src="media/song.mp3" preload="none"></audio></figure>', $markup );
		$this->assertStringContainsString( '<!-- wp:audio {"src":"media\/body-song.mp3","controls":true,"autoplay":true,"muted":true,"align":"center","anchor":"source-body-audio-shortcode"} -->', $markup );
		$this->assertStringContainsString( '<figure class="wp-block-audio aligncenter" id="source-body-audio-shortcode"><audio controls="controls" src="media/body-song.mp3" autoplay="autoplay" muted="muted"></audio></figure>', $markup );
		$this->assertStringContainsString( "<!-- wp:shortcode -->\n[embed]/local/unrecognized[/embed]\n<!-- /wp:shortcode -->", $markup );
		$this->assertStringNotContainsString( '<p id="source-video-shortcode"', $markup );
		$this->assertStringNotContainsString( '<!-- wp:freeform -->', $markup );
	}

	/**
	 * Legacy WordPress pagination comments become native More and Page Break blocks.
	 *
	 * @return void
	 */
	public function test_legacy_wordpress_pagination_comments_become_native_blocks() {
		$converter = new ImportHtmlBlockConverter();
		$summary   = array();

		$markup = $converter->convert(
			'<p>Archive teaser.</p>'
			. '<!--more Keep reading safely--><!--noteaser-->'
			. '<p>Hidden body content.</p>'
			. '<!--nextpage-->'
			. '<p>Second page content.</p>'
			. '<!--more Keep &lt;going&gt; now-->'
			. '<!--unknown importer note--><!--noteaser-->',
			$summary
		);

		$this->assertSame( 'structured', $summary['html_block_conversion'] );
		$this->assertSame( 6, $summary['html_inferred_block_count'] );
		$this->assertSame( 0, $summary['html_classic_block_count'] );
		$this->assertStringContainsString( '<p>Archive teaser.</p>', $markup );
		$this->assertStringContainsString( '<!-- wp:more {"customText":"Keep reading safely","noTeaser":true} -->' . "\n" . '<!--more Keep reading safely-->' . "\n" . '<!--noteaser-->' . "\n" . '<!-- /wp:more -->', $markup );
		$this->assertStringContainsString( '<p>Hidden body content.</p>', $markup );
		$this->assertStringContainsString( '<!-- wp:nextpage -->' . "\n" . '<!--nextpage-->' . "\n" . '<!-- /wp:nextpage -->', $markup );
		$this->assertStringContainsString( '<p>Second page content.</p>', $markup );
		$this->assertStringContainsString( '<!-- wp:more {"customText":"Keep going now"} -->' . "\n" . '<!--more Keep going now-->' . "\n" . '<!-- /wp:more -->', $markup );
		$this->assertStringNotContainsString( '&lt;going&gt;', $markup );
		$this->assertStringNotContainsString( 'unknown importer note', $markup );
		$this->assertStringNotContainsString( '<!-- wp:freeform -->', $markup );
	}

	/**
	 * Explicit poem, lyrics, and verse pre blocks become native Verse blocks.
	 *
	 * @return void
	 */
	public function test_explicit_verse_pre_blocks_become_native_verse_blocks() {
		$converter = new ImportHtmlBlockConverter();
		$summary   = array();

		$markup = $converter->convert( "<pre id=\"source-poem\" class=\"poem imported\">\nLine one\n  Line two with indent\n</pre>", $summary );

		$this->assertSame( 'structured', $summary['html_block_conversion'] );
		$this->assertSame( 1, $summary['html_inferred_block_count'] );
		$this->assertSame( 0, $summary['html_classic_block_count'] );
		$this->assertStringContainsString( '<!-- wp:verse {"anchor":"source-poem"} -->', $markup );
		$this->assertStringContainsString( "<pre class=\"wp-block-verse\" id=\"source-poem\">\nLine one\n  Line two with indent\n</pre>", $markup );
		$this->assertStringNotContainsString( '<!-- wp:preformatted -->', $markup );
		$this->assertStringNotContainsString( '<!-- wp:freeform -->', $markup );
	}

	/**
	 * Explicit gallery containers become gallery blocks with nested images.
	 *
	 * @return void
	 */
	public function test_explicit_gallery_containers_become_gallery_blocks() {
		$converter = new ImportHtmlBlockConverter();
		$summary   = array();

		$markup = $converter->convert(
			'<div id="source-gallery" class="gallery gallery-columns-3 gallery-size-thumbnail alignwide"><figure><a href="large-one.jpg" target="_BLANK" rel="noopener"><img src="one.jpg" alt="One"></a><figcaption>One caption.</figcaption></figure><br><figure><img src="two.jpg" alt="Two"><figcaption>Two caption.</figcaption></figure></div>',
			$summary
		);

		$this->assertSame( 'structured', $summary['html_block_conversion'] );
		$this->assertSame( 1, $summary['html_inferred_block_count'] );
		$this->assertSame( 0, $summary['html_classic_block_count'] );
		$this->assertStringContainsString( '<!-- wp:gallery {"columns":3,"linkTo":"none","align":"wide","anchor":"source-gallery"} -->', $markup );
		$this->assertStringContainsString( '<figure class="wp-block-gallery has-nested-images columns-3 is-cropped alignwide" id="source-gallery">', $markup );
		$this->assertStringContainsString( '<!-- wp:image {"href":"large-one.jpg","linkDestination":"custom","linkTarget":"_blank","rel":"noopener","sizeSlug":"thumbnail"} -->', $markup );
		$this->assertStringContainsString( '<a href="large-one.jpg" target="_BLANK" rel="noopener"><img src="one.jpg" alt="One"></a>', $markup );
		$this->assertStringContainsString( '<figcaption class="wp-element-caption">One caption.</figcaption>', $markup );
		$this->assertStringContainsString( '<!-- wp:image {"sizeSlug":"thumbnail","linkDestination":"none"} -->', $markup );
		$this->assertStringContainsString( '<figure class="wp-block-image size-thumbnail"><img src="two.jpg" alt="Two"><figcaption class="wp-element-caption">Two caption.</figcaption></figure>', $markup );
		$this->assertStringContainsString( '<figcaption class="wp-element-caption">Two caption.</figcaption>', $markup );
		$this->assertStringNotContainsString( '<!-- wp:freeform -->', $markup );
	}

	/**
	 * Orphan figcaptions immediately after galleries become native gallery captions.
	 *
	 * @return void
	 */
	public function test_orphan_figcaptions_join_previous_galleries() {
		$converter = new ImportHtmlBlockConverter();
		$summary   = array();

		$markup = $converter->convert(
			'<div class="gallery gallery-columns-2"><figure><img src="one.jpg" alt="One"></figure><figure><img src="two.jpg" alt="Two"></figure></div>'
			. '<figcaption>Gallery <em>caption</em>.</figcaption>',
			$summary
		);

		$this->assertSame( 'structured', $summary['html_block_conversion'] );
		$this->assertSame( 1, $summary['html_inferred_block_count'] );
		$this->assertSame( 0, $summary['html_classic_block_count'] );
		$this->assertStringContainsString( '<!-- wp:gallery {"columns":2,"linkTo":"none"} -->', $markup );
		$this->assertStringContainsString( '<figcaption class="blocks-gallery-caption wp-element-caption">Gallery <em>caption</em>.</figcaption>', $markup );
		$this->assertStringNotContainsString( '<!-- wp:freeform -->', $markup );

		$late_markup = $converter->convert(
			'<div class="gallery"><figure><img src="one.jpg" alt="One"></figure><figure><img src="two.jpg" alt="Two"></figure></div><p>After gallery.</p><figcaption>Late caption.</figcaption>',
			$summary
		);

		$this->assertSame( 'mixed', $summary['html_block_conversion'] );
		$this->assertSame( 2, $summary['html_inferred_block_count'] );
		$this->assertSame( 1, $summary['html_classic_block_count'] );
		$this->assertStringContainsString( '<!-- wp:gallery {"linkTo":"none"} -->', $late_markup );
		$this->assertStringContainsString( '<p>After gallery.</p>', $late_markup );
		$this->assertStringContainsString( '<figcaption>Late caption.</figcaption>', $late_markup );

		$invalid_markup = $converter->convert(
			'<div class="gallery"><figure><img src="one.jpg" alt="One"></figure></div><figcaption>One-image caption.</figcaption>',
			$summary
		);

		$this->assertSame( 'mixed', $summary['html_block_conversion'] );
		$this->assertSame( 1, $summary['html_inferred_block_count'] );
		$this->assertSame( 1, $summary['html_classic_block_count'] );
		$this->assertStringContainsString( '<figure class="wp-block-image"><img src="one.jpg" alt="One"></figure>', $invalid_markup );
		$this->assertStringContainsString( '<figcaption>One-image caption.</figcaption>', $invalid_markup );
	}

	/**
	 * Gallery-looking wrappers with custom direct children keep a conservative fallback.
	 *
	 * @return void
	 */
	public function test_ambiguous_gallery_wrappers_keep_classic_fallback() {
		$converter = new ImportHtmlBlockConverter();
		$summary   = array();

		$markup = $converter->convert(
			'<div id="mixed-gallery" class="gallery gallery-columns-2">'
			. '<figure><img src="one.jpg" alt="One"><figcaption>One caption.</figcaption></figure>'
			. '<div class="gallery-promo"><custom-card>Keep this custom promotion.</custom-card></div>'
			. '<figure><img src="two.jpg" alt="Two"><figcaption>Two caption.</figcaption></figure>'
			. '</div>',
			$summary
		);

		$this->assertSame( 'mixed', $summary['html_block_conversion'] );
		$this->assertSame( 0, $summary['html_inferred_block_count'] );
		$this->assertSame( 1, $summary['html_classic_block_count'] );
		$this->assertStringContainsString( '<!-- wp:freeform -->', $markup );
		$this->assertStringContainsString( '<div id="mixed-gallery" class="gallery gallery-columns-2">', $markup );
		$this->assertStringContainsString( '<custom-card>Keep this custom promotion.</custom-card>', $markup );
		$this->assertStringContainsString( '<figure><img src="two.jpg" alt="Two"><figcaption>Two caption.</figcaption></figure>', $markup );
		$this->assertStringNotContainsString( '<!-- wp:gallery', $markup );
	}

	/**
	 * Audio, video, and known iframe embeds become native media/embed blocks.
	 *
	 * @return void
	 */
	public function test_media_and_known_iframe_embeds_become_native_blocks() {
		$converter = new ImportHtmlBlockConverter();
		$summary   = array();

		$markup = $converter->convert(
			'<figure id="video-section"><video controls playsinline poster="poster.jpg" src="movie.mp4"></video><br><figcaption>Video caption.</figcaption></figure>'
			. '<figure><audio id="audio-player" controls src="episode.mp3"></audio><figcaption>Audio caption.</figcaption></figure>'
			. '<audio id="standalone-audio" style="margin: 0 auto" controls src="standalone.mp3"></audio>'
			. '<audio id="legacy-audio-align" align="right" controls src="legacy-align.mp3"></audio>'
			. '<video id="style-video-align" style="margin-left: auto; margin-right: auto" controls src="centered.mp4"></video>'
			. '<figure id="embed-section" class="alignwide"><iframe id="embed-frame" src="https://www.youtube.com/embed/dQw4w9WgXcQ"></iframe><figcaption>Embed caption.</figcaption></figure>'
			. '<iframe id="vimeo-frame" class="aligncenter" src="https://player.vimeo.com/video/24680"></iframe>',
			$summary
		);

		$this->assertSame( 'structured', $summary['html_block_conversion'] );
		$this->assertSame( 7, $summary['html_inferred_block_count'] );
		$this->assertSame( 0, $summary['html_classic_block_count'] );
		$this->assertStringContainsString( '<!-- wp:video {"src":"movie.mp4","controls":true,"playsInline":true,"poster":"poster.jpg","anchor":"video-section"} -->', $markup );
		$this->assertStringContainsString( '<figure class="wp-block-video" id="video-section"><video controls playsinline poster="poster.jpg" src="movie.mp4"></video><figcaption class="wp-element-caption">Video caption.</figcaption></figure>', $markup );
		$this->assertStringContainsString( '<!-- wp:audio {"src":"episode.mp3","controls":true,"anchor":"audio-player"} -->', $markup );
		$this->assertStringContainsString( '<figure class="wp-block-audio" id="audio-player"><audio controls src="episode.mp3"></audio><figcaption class="wp-element-caption">Audio caption.</figcaption></figure>', $markup );
		$this->assertStringNotContainsString( '<audio id="audio-player"', $markup );
		$this->assertStringContainsString( '<!-- wp:audio {"src":"standalone.mp3","controls":true,"align":"center","anchor":"standalone-audio"} -->', $markup );
		$this->assertStringContainsString( '<figure class="wp-block-audio aligncenter" id="standalone-audio"><audio style="margin: 0 auto" controls src="standalone.mp3"></audio></figure>', $markup );
		$this->assertStringNotContainsString( '<audio id="standalone-audio"', $markup );
		$this->assertStringContainsString( '<!-- wp:audio {"src":"legacy-align.mp3","controls":true,"align":"right","anchor":"legacy-audio-align"} -->', $markup );
		$this->assertStringContainsString( '<figure class="wp-block-audio alignright" id="legacy-audio-align"><audio align="right" controls src="legacy-align.mp3"></audio></figure>', $markup );
		$this->assertStringContainsString( '<!-- wp:video {"src":"centered.mp4","controls":true,"align":"center","anchor":"style-video-align"} -->', $markup );
		$this->assertStringContainsString( '<figure class="wp-block-video aligncenter" id="style-video-align"><video style="margin-left: auto; margin-right: auto" controls src="centered.mp4"></video></figure>', $markup );
		$this->assertStringContainsString( '<!-- wp:embed {"url":"https:\/\/www.youtube.com\/watch?v=dQw4w9WgXcQ","type":"video","providerNameSlug":"youtube","responsive":true,"align":"wide","anchor":"embed-section"} -->', $markup );
		$this->assertStringContainsString( '<figure class="wp-block-embed is-type-video is-provider-youtube wp-block-embed-youtube alignwide" id="embed-section"><div class="wp-block-embed__wrapper">https://www.youtube.com/watch?v=dQw4w9WgXcQ</div><figcaption class="wp-element-caption">Embed caption.</figcaption></figure>', $markup );
		$this->assertStringContainsString( '<!-- wp:embed {"url":"https:\/\/vimeo.com\/24680","type":"video","providerNameSlug":"vimeo","responsive":true,"align":"center","anchor":"vimeo-frame"} -->', $markup );
		$this->assertStringContainsString( '<figure class="wp-block-embed is-type-video is-provider-vimeo wp-block-embed-vimeo aligncenter" id="vimeo-frame"><div class="wp-block-embed__wrapper">https://vimeo.com/24680</div></figure>', $markup );
		$this->assertStringNotContainsString( 'https://player.vimeo.com/video/24680', $markup );
		$this->assertStringNotContainsString( '<!-- wp:freeform -->', $markup );
	}

	/**
	 * Orphan figcaptions immediately after media/embed nodes become native captions.
	 *
	 * @return void
	 */
	public function test_orphan_figcaptions_join_previous_media_and_embeds() {
		$converter = new ImportHtmlBlockConverter();
		$summary   = array();

		$markup = $converter->convert(
			'<video controls src="movie.mp4"></video><figcaption>Video <em>caption</em>.</figcaption>'
			. '<audio controls src="episode.mp3"></audio><figcaption>Audio caption.</figcaption>'
			. '<iframe src="https://www.youtube.com/embed/dQw4w9WgXcQ"></iframe><figcaption>Embed caption.</figcaption>',
			$summary
		);

		$this->assertSame( 'structured', $summary['html_block_conversion'] );
		$this->assertSame( 3, $summary['html_inferred_block_count'] );
		$this->assertSame( 0, $summary['html_classic_block_count'] );
		$this->assertStringContainsString( '<figure class="wp-block-video"><video controls src="movie.mp4"></video><figcaption class="wp-element-caption">Video <em>caption</em>.</figcaption></figure>', $markup );
		$this->assertStringContainsString( '<figure class="wp-block-audio"><audio controls src="episode.mp3"></audio><figcaption class="wp-element-caption">Audio caption.</figcaption></figure>', $markup );
		$this->assertStringContainsString( '<figure class="wp-block-embed is-type-video is-provider-youtube wp-block-embed-youtube"><div class="wp-block-embed__wrapper">https://www.youtube.com/watch?v=dQw4w9WgXcQ</div><figcaption class="wp-element-caption">Embed caption.</figcaption></figure>', $markup );
		$this->assertStringNotContainsString( '<!-- wp:freeform -->', $markup );

		$late_markup = $converter->convert( '<video controls src="movie.mp4"></video><p>After</p><figcaption>Late caption.</figcaption>', $summary );

		$this->assertSame( 'mixed', $summary['html_block_conversion'] );
		$this->assertSame( 2, $summary['html_inferred_block_count'] );
		$this->assertSame( 1, $summary['html_classic_block_count'] );
		$this->assertStringContainsString( '<figure class="wp-block-video"><video controls src="movie.mp4"></video></figure>', $late_markup );
		$this->assertStringContainsString( '<p>After</p>', $late_markup );
		$this->assertStringContainsString( '<figcaption>Late caption.</figcaption>', $late_markup );

		$unknown_iframe_markup = $converter->convert( '<iframe src="https://legacy.example.test/widget"></iframe><figcaption>Widget caption.</figcaption>', $summary );

		$this->assertSame( 'mixed', $summary['html_block_conversion'] );
		$this->assertSame( 1, $summary['html_inferred_block_count'] );
		$this->assertSame( 1, $summary['html_classic_block_count'] );
		$this->assertStringContainsString( '<!-- wp:html -->', $unknown_iframe_markup );
		$this->assertStringContainsString( '<iframe src="https://legacy.example.test/widget"></iframe>', $unknown_iframe_markup );
		$this->assertStringContainsString( '<figcaption>Widget caption.</figcaption>', $unknown_iframe_markup );
	}

	/**
	 * Native media preload metadata is normalized for Audio and Video blocks.
	 *
	 * @return void
	 */
	public function test_native_media_preload_metadata_is_normalized() {
		$converter = new ImportHtmlBlockConverter();
		$summary   = array();

		$markup = $converter->convert(
			'<audio id="audio-preload" controls preload="AUTO" src="audio.mp3"></audio>'
			. '<video id="video-preload" controls preload="sometimes" src="video.mp4"></video>',
			$summary
		);

		$this->assertSame( 'structured', $summary['html_block_conversion'] );
		$this->assertSame( 2, $summary['html_inferred_block_count'] );
		$this->assertSame( 0, $summary['html_classic_block_count'] );
		$this->assertStringContainsString( '<!-- wp:audio {"src":"audio.mp3","controls":true,"preload":"auto","anchor":"audio-preload"} -->', $markup );
		$this->assertStringContainsString( '<figure class="wp-block-audio" id="audio-preload"><audio controls preload="auto" src="audio.mp3"></audio></figure>', $markup );
		$this->assertStringContainsString( '<!-- wp:video {"src":"video.mp4","controls":true,"anchor":"video-preload"} -->', $markup );
		$this->assertStringContainsString( '<figure class="wp-block-video" id="video-preload"><video controls src="video.mp4"></video></figure>', $markup );
		$this->assertStringNotContainsString( 'preload="AUTO"', $markup );
		$this->assertStringNotContainsString( 'preload="sometimes"', $markup );
		$this->assertStringNotContainsString( '<!-- wp:freeform -->', $markup );
	}

	/**
	 * Legacy inline-play video hints are preserved as native Video metadata.
	 *
	 * @return void
	 */
	public function test_native_video_webkit_playsinline_metadata_is_preserved() {
		$converter = new ImportHtmlBlockConverter();
		$summary   = array();

		$markup = $converter->convert(
			'<video id="inline-video" webkit-playsinline controls src="inline.mp4"></video>',
			$summary
		);

		$this->assertSame( 'structured', $summary['html_block_conversion'] );
		$this->assertSame( 1, $summary['html_inferred_block_count'] );
		$this->assertSame( 0, $summary['html_classic_block_count'] );
		$this->assertStringContainsString( '<!-- wp:video {"src":"inline.mp4","controls":true,"playsInline":true,"anchor":"inline-video"} -->', $markup );
		$this->assertStringContainsString( '<figure class="wp-block-video" id="inline-video"><video playsinline controls src="inline.mp4"></video></figure>', $markup );
		$this->assertStringNotContainsString( 'webkit-playsinline', $markup );
		$this->assertStringNotContainsString( '<video id="inline-video"', $markup );
		$this->assertStringNotContainsString( '<!-- wp:freeform -->', $markup );
	}

	/**
	 * Native media source selection skips incomplete source candidates.
	 *
	 * @return void
	 */
	public function test_native_media_source_selection_skips_empty_candidates() {
		$converter = new ImportHtmlBlockConverter();
		$summary   = array();

		$markup = $converter->convert(
			'<audio id="multi-source-audio" controls><source type="audio/ogg" src="javascript:alert(1)"><source type="audio/mpeg" src="fallback.mp3"></audio>',
			$summary
		);

		$this->assertSame( 'structured', $summary['html_block_conversion'] );
		$this->assertSame( 1, $summary['html_inferred_block_count'] );
		$this->assertSame( 0, $summary['html_classic_block_count'] );
		$this->assertStringContainsString( '<!-- wp:audio {"src":"fallback.mp3","controls":true,"anchor":"multi-source-audio"} -->', $markup );
		$this->assertStringContainsString( '<figure class="wp-block-audio" id="multi-source-audio"><audio controls><source type="audio/ogg"><source type="audio/mpeg" src="fallback.mp3"></audio></figure>', $markup );
		$this->assertStringNotContainsString( 'javascript:alert(1)', $markup );
		$this->assertStringNotContainsString( '<audio id="multi-source-audio"', $markup );
		$this->assertStringNotContainsString( '<!-- wp:freeform -->', $markup );
	}

	/**
	 * Media figures with extra direct children keep a conservative fallback.
	 *
	 * @return void
	 */
	public function test_ambiguous_media_figures_keep_classic_fallback() {
		$converter = new ImportHtmlBlockConverter();
		$summary   = array();

		$markup = $converter->convert(
			'<figure id="video-with-extra" class="alignwide">'
			. '<video controls src="movie.mp4"></video>'
			. '<figcaption>Video caption.</figcaption>'
			. '<div class="transcript-link"><a href="/transcript">Read transcript</a></div>'
			. '</figure>'
			. '<figure id="embed-with-extra">'
			. '<iframe src="https://www.youtube.com/embed/dQw4w9WgXcQ"></iframe>'
			. '<figcaption>Embed caption.</figcaption>'
			. '<aside>Keep this editorial embed note.</aside>'
			. '</figure>',
			$summary
		);

		$this->assertSame( 'mixed', $summary['html_block_conversion'] );
		$this->assertSame( 0, $summary['html_inferred_block_count'] );
		$this->assertSame( 2, $summary['html_classic_block_count'] );
		$this->assertStringContainsString( '<!-- wp:freeform -->', $markup );
		$this->assertStringContainsString( '<div class="transcript-link"><a href="/transcript">Read transcript</a></div>', $markup );
		$this->assertStringContainsString( '<aside>Keep this editorial embed note.</aside>', $markup );
		$this->assertStringNotContainsString( '<!-- wp:video', $markup );
		$this->assertStringNotContainsString( '<!-- wp:embed', $markup );
	}

	/**
	 * Rendered classic WordPress media wrappers become native media blocks.
	 *
	 * @return void
	 */
	public function test_classic_wordpress_media_wrappers_become_native_blocks() {
		$converter = new ImportHtmlBlockConverter();
		$summary   = array();

		$markup = $converter->convert(
			'<div id="classic-video" class="wp-video aligncenter" style="width: 640px">'
			. '<video class="wp-video-shortcode" width="640" height="360" preload="metadata" controls="controls">'
			. '<source type="video/mp4" src="media/classic.mp4?_=1">'
			. '<a href="media/classic.mp4">media/classic.mp4</a>'
			. '</video>'
			. '</div>'
			. '<div id="classic-audio" class="wp-audio alignwide">'
			. '<audio class="wp-audio-shortcode" preload="none" controls="controls">'
			. '<source type="audio/mpeg" src="media/episode.mp3">'
			. '<a href="media/episode.mp3">media/episode.mp3</a>'
			. '</audio>'
			. '<p class="wp-caption-text">Episode caption.</p>'
			. '</div>',
			$summary
		);

		$this->assertSame( 'structured', $summary['html_block_conversion'] );
		$this->assertSame( 2, $summary['html_inferred_block_count'] );
		$this->assertSame( 0, $summary['html_classic_block_count'] );
		$this->assertStringContainsString( '<!-- wp:video {"src":"media\/classic.mp4?_=1","controls":true,"preload":"metadata","align":"center","anchor":"classic-video"} -->', $markup );
		$this->assertStringContainsString( '<figure class="wp-block-video aligncenter" id="classic-video"><video class="wp-video-shortcode" width="640" height="360" preload="metadata" controls="controls">', $markup );
		$this->assertStringContainsString( '<source type="video/mp4" src="media/classic.mp4?_=1">', $markup );
		$this->assertStringContainsString( '<!-- wp:audio {"src":"media\/episode.mp3","controls":true,"preload":"none","align":"wide","anchor":"classic-audio"} -->', $markup );
		$this->assertStringContainsString( '<figure class="wp-block-audio alignwide" id="classic-audio"><audio class="wp-audio-shortcode" preload="none" controls="controls">', $markup );
		$this->assertStringContainsString( '<figcaption class="wp-element-caption">Episode caption.</figcaption>', $markup );
		$this->assertStringNotContainsString( '<!-- wp:freeform -->', $markup );
		$this->assertStringNotContainsString( '<div class="wp-video', $markup );
		$this->assertStringNotContainsString( '<div class="wp-audio', $markup );
	}

	/**
	 * Ambiguous classic media wrappers keep the whole wrapper in the classic fallback.
	 *
	 * @return void
	 */
	public function test_ambiguous_classic_media_wrappers_remain_classic_fallback() {
		$converter = new ImportHtmlBlockConverter();
		$summary   = array();

		$markup = $converter->convert(
			'<div class="wp-video"><h2>Editorial heading</h2><video src="media/movie.mp4" controls></video></div>'
			. '<div class="wp-audio"><audio src="media/one.mp3" controls></audio><audio src="media/two.mp3" controls></audio></div>',
			$summary
		);

		$this->assertSame( 'mixed', $summary['html_block_conversion'] );
		$this->assertSame( 0, $summary['html_inferred_block_count'] );
		$this->assertSame( 2, $summary['html_classic_block_count'] );
		$this->assertStringContainsString( '<!-- wp:freeform -->', $markup );
		$this->assertStringContainsString( '<h2>Editorial heading</h2>', $markup );
		$this->assertStringContainsString( '<audio src="media/two.mp3" controls></audio>', $markup );
	}

	/**
	 * Classic media widgets keep their outer widget chrome in the classic fallback.
	 *
	 * @return void
	 */
	public function test_classic_media_widget_wrappers_remain_classic_fallback() {
		$converter = new ImportHtmlBlockConverter();
		$summary   = array();

		$markup = $converter->convert(
			'<aside class="widget widget_media_video" id="media_video-2">'
			. '<h2 class="widget-title">Featured video</h2>'
			. '<div class="wp-video aligncenter"><video class="wp-video-shortcode" src="media/widget.mp4" controls></video></div>'
			. '</aside>'
			. '<div class="textwidget"><p>Player intro.</p><div class="wp-audio"><audio src="media/widget.mp3" controls></audio></div></div>',
			$summary
		);

		$this->assertSame( 'mixed', $summary['html_block_conversion'] );
		$this->assertSame( 0, $summary['html_inferred_block_count'] );
		$this->assertSame( 2, $summary['html_classic_block_count'] );
		$this->assertStringContainsString( '<!-- wp:freeform -->', $markup );
		$this->assertStringContainsString( '<aside class="widget widget_media_video" id="media_video-2">', $markup );
		$this->assertStringContainsString( '<h2 class="widget-title">Featured video</h2>', $markup );
		$this->assertStringContainsString( '<div class="textwidget"><p>Player intro.</p><div class="wp-audio">', $markup );
		$this->assertStringNotContainsString( '<!-- wp:video', $markup );
		$this->assertStringNotContainsString( '<!-- wp:audio', $markup );
	}

	/**
	 * Explicit legacy widgets keep their outer chrome in the classic fallback.
	 *
	 * @return void
	 */
	public function test_legacy_widget_wrappers_remain_classic_fallback() {
		$converter = new ImportHtmlBlockConverter();
		$summary   = array();

		$markup = $converter->convert(
			'<aside class="widget widget_media_image" id="media_image-3">'
			. '<h2 class="widget-title">Featured image</h2>'
			. '<a href="/feature"><img src="media/widget.jpg" alt="Widget image"></a>'
			. '<p class="wp-caption-text">Widget caption.</p>'
			. '</aside>'
			. '<section class="widget widget_search" id="search-2">'
			. '<h2 class="widget-title">Search archive</h2>'
			. '<form role="search" method="get" action="/"><label>Search</label><input type="search" name="s"><button type="submit">Go</button></form>'
			. '</section>',
			$summary
		);

		$this->assertSame( 'mixed', $summary['html_block_conversion'] );
		$this->assertSame( 0, $summary['html_inferred_block_count'] );
		$this->assertSame( 2, $summary['html_classic_block_count'] );
		$this->assertStringContainsString( '<!-- wp:freeform -->', $markup );
		$this->assertStringContainsString( '<aside class="widget widget_media_image" id="media_image-3">', $markup );
		$this->assertStringContainsString( '<h2 class="widget-title">Featured image</h2>', $markup );
		$this->assertStringContainsString( '<section class="widget widget_search" id="search-2">', $markup );
		$this->assertStringContainsString( '<form role="search" method="get" action="/">', $markup );
		$this->assertStringNotContainsString( '<!-- wp:image', $markup );
		$this->assertStringNotContainsString( '<!-- wp:search', $markup );
	}

	/**
	 * Figure-wrapped legacy quotes become native Quote blocks with captions preserved as citations.
	 *
	 * @return void
	 */
	public function test_figure_wrapped_quotes_become_native_quote_blocks() {
		$converter = new ImportHtmlBlockConverter();
		$summary   = array();

		$markup = $converter->convert(
			'<figure id="legacy-quote" class="quote" style="text-align: right">'
			. '<blockquote><p>Importers should preserve editorial quotes.</p></blockquote>'
			. '<br>'
			. '<figcaption>Source <strong>Author</strong></figcaption>'
			. '</figure>'
			. '<figure><blockquote><p>Existing citation stays.</p><cite>Existing Author</cite></blockquote><figcaption>Ignored caption</figcaption></figure>'
			. '<figure><blockquote id="blockquote-source" class="has-text-align-center"><p>Nested quote metadata.</p></blockquote><figcaption>Nested Source</figcaption></figure>',
			$summary
		);

		$this->assertSame( 'structured', $summary['html_block_conversion'] );
		$this->assertSame( 3, $summary['html_inferred_block_count'] );
		$this->assertSame( 0, $summary['html_classic_block_count'] );
		$this->assertStringContainsString( '<!-- wp:quote {"textAlign":"right","anchor":"legacy-quote"} -->', $markup );
		$this->assertStringContainsString( '<blockquote class="wp-block-quote has-text-align-right" id="legacy-quote"><p>Importers should preserve editorial quotes.</p><cite>Source <strong>Author</strong></cite></blockquote>', $markup );
		$this->assertStringNotContainsString( '<br>', $markup );
		$this->assertStringContainsString( '<!-- wp:quote -->', $markup );
		$this->assertStringContainsString( '<blockquote class="wp-block-quote"><p>Existing citation stays.</p><cite>Existing Author</cite></blockquote>', $markup );
		$this->assertStringContainsString( '<!-- wp:quote {"textAlign":"center","anchor":"blockquote-source"} -->', $markup );
		$this->assertStringContainsString( '<blockquote class="wp-block-quote has-text-align-center" id="blockquote-source"><p>Nested quote metadata.</p><cite>Nested Source</cite></blockquote>', $markup );
		$this->assertStringNotContainsString( 'Ignored caption', $markup );
		$this->assertStringNotContainsString( '<!-- wp:pullquote -->', $markup );
		$this->assertStringNotContainsString( '<!-- wp:freeform -->', $markup );
	}

	/**
	 * Orphan quote citations immediately after blockquotes become native citations.
	 *
	 * @return void
	 */
	public function test_orphan_quote_citations_join_previous_blockquotes() {
		$converter = new ImportHtmlBlockConverter();
		$summary   = array();

		$markup = $converter->convert(
			'<blockquote><p>Quoted text.</p></blockquote><figcaption>Quote <strong>Author</strong></figcaption>'
			. '<blockquote><p>Another quote.</p></blockquote><cite>Second Author</cite>',
			$summary
		);

		$this->assertSame( 'structured', $summary['html_block_conversion'] );
		$this->assertSame( 2, $summary['html_inferred_block_count'] );
		$this->assertSame( 0, $summary['html_classic_block_count'] );
		$this->assertStringContainsString( '<blockquote class="wp-block-quote"><p>Quoted text.</p><cite>Quote <strong>Author</strong></cite></blockquote>', $markup );
		$this->assertStringContainsString( '<blockquote class="wp-block-quote"><p>Another quote.</p><cite>Second Author</cite></blockquote>', $markup );
		$this->assertStringNotContainsString( '<!-- wp:freeform -->', $markup );

		$late_markup = $converter->convert( '<blockquote><p>Quoted text.</p></blockquote><p>After quote.</p><figcaption>Late Author</figcaption>', $summary );

		$this->assertSame( 'mixed', $summary['html_block_conversion'] );
		$this->assertSame( 2, $summary['html_inferred_block_count'] );
		$this->assertSame( 1, $summary['html_classic_block_count'] );
		$this->assertStringContainsString( '<blockquote class="wp-block-quote"><p>Quoted text.</p></blockquote>', $late_markup );
		$this->assertStringContainsString( '<p>After quote.</p>', $late_markup );
		$this->assertStringContainsString( '<figcaption>Late Author</figcaption>', $late_markup );

		$existing_markup = $converter->convert( '<blockquote><p>Quoted text.</p><cite>Existing Author</cite></blockquote><figcaption>Extra Author</figcaption>', $summary );

		$this->assertSame( 'mixed', $summary['html_block_conversion'] );
		$this->assertSame( 1, $summary['html_inferred_block_count'] );
		$this->assertSame( 1, $summary['html_classic_block_count'] );
		$this->assertStringContainsString( '<blockquote class="wp-block-quote"><p>Quoted text.</p><cite>Existing Author</cite></blockquote>', $existing_markup );
		$this->assertStringContainsString( '<figcaption>Extra Author</figcaption>', $existing_markup );
	}

	/**
	 * Quote figures with extra direct children keep a conservative fallback.
	 *
	 * @return void
	 */
	public function test_ambiguous_quote_figures_keep_classic_fallback() {
		$converter = new ImportHtmlBlockConverter();
		$summary   = array();

		$markup = $converter->convert(
			'<figure id="quote-with-note" class="quote">'
			. '<blockquote><p>Quote with extra source note.</p></blockquote>'
			. '<figcaption>Quote Source</figcaption>'
			. '<aside class="source-note">Keep this quote note.</aside>'
			. '</figure>',
			$summary
		);

		$this->assertSame( 'mixed', $summary['html_block_conversion'] );
		$this->assertSame( 0, $summary['html_inferred_block_count'] );
		$this->assertSame( 1, $summary['html_classic_block_count'] );
		$this->assertStringContainsString( '<!-- wp:freeform -->', $markup );
		$this->assertStringContainsString( '<aside class="source-note">Keep this quote note.</aside>', $markup );
		$this->assertStringNotContainsString( '<!-- wp:quote', $markup );
	}

	/**
	 * Obvious image-plus-copy split layouts become native Media & Text blocks.
	 *
	 * @return void
	 */
	public function test_media_text_split_layouts_become_native_media_text_blocks() {
		$converter = new ImportHtmlBlockConverter();
		$summary   = array();

		$markup = $converter->convert(
			'<section id="profile-split" class="media-text alignfull">'
			. '<figure><a href="/profiles/ada" target="_BLANK" rel="NOOPENER noreferrer javascript:alert(1) NOOPENER"><img src="ada.jpg" alt="Ada profile"></a></figure>'
			. '<br>'
			. '<div class="copy"><h2>Profile heading</h2><p>Profile copy.</p><a class="button" href="/profiles/ada/read">Read profile</a></div>'
			. '</section>',
			$summary
		);

		$this->assertSame( 'structured', $summary['html_block_conversion'] );
		$this->assertSame( 1, $summary['html_inferred_block_count'] );
		$this->assertSame( 0, $summary['html_classic_block_count'] );
		$this->assertStringContainsString( '<!-- wp:media-text ', $markup );
		$this->assertStringContainsString( '"mediaUrl":"ada.jpg"', $markup );
		$this->assertStringContainsString( '"mediaType":"image"', $markup );
		$this->assertStringContainsString( '"mediaAlt":"Ada profile"', $markup );
		$this->assertStringContainsString( '"anchor":"profile-split"', $markup );
		$this->assertStringContainsString( '"align":"full"', $markup );
		$this->assertStringContainsString( '"linkDestination":"custom"', $markup );
		$this->assertStringContainsString( '"href":"\/profiles\/ada"', $markup );
		$this->assertStringContainsString( '"linkTarget":"_blank"', $markup );
		$this->assertStringContainsString( '"rel":"noopener noreferrer"', $markup );
		$this->assertStringContainsString( '<div class="wp-block-media-text is-stacked-on-mobile alignfull" id="profile-split">', $markup );
		$this->assertStringContainsString( '<figure class="wp-block-media-text__media"><a href="/profiles/ada" target="_BLANK" rel="noopener noreferrer"><img src="ada.jpg" alt="Ada profile"></a></figure>', $markup );
		$this->assertStringContainsString( '<div class="wp-block-media-text__content">', $markup );
		$this->assertStringContainsString( '<!-- wp:heading {"level":2} -->', $markup );
		$this->assertStringContainsString( '<p>Profile copy.</p>', $markup );
		$this->assertStringContainsString( '<!-- wp:buttons -->', $markup );
		$this->assertStringNotContainsString( 'javascript:alert', $markup );
		$this->assertStringNotContainsString( '<!-- wp:image -->', $markup );
		$this->assertStringNotContainsString( '<!-- wp:freeform -->', $markup );
		$this->assertStringNotContainsString( '<br>', $markup );
	}

	/**
	 * Media on the second side is preserved as right-positioned Media & Text.
	 *
	 * @return void
	 */
	public function test_media_text_right_position_is_preserved() {
		$converter = new ImportHtmlBlockConverter();
		$summary   = array();

		$markup = $converter->convert(
			'<div class="text-image"><div><h2>Right image feature</h2><p>Copy stays first.</p></div><img src="right.jpg" alt="Right side"></div>',
			$summary
		);

		$this->assertSame( 'structured', $summary['html_block_conversion'] );
		$this->assertStringContainsString( '<!-- wp:media-text ', $markup );
		$this->assertStringContainsString( '"mediaPosition":"right"', $markup );
		$this->assertStringContainsString( '<div class="wp-block-media-text is-stacked-on-mobile has-media-on-the-right">', $markup );
		$this->assertStringContainsString( '<figure class="wp-block-media-text__media"><img src="right.jpg" alt="Right side"></figure>', $markup );
		$this->assertStringContainsString( '<h2>Right image feature</h2>', $markup );
		$this->assertStringContainsString( '<p>Copy stays first.</p>', $markup );
		$this->assertStringNotContainsString( '<!-- wp:freeform -->', $markup );
	}

	/**
	 * Explicit social/profile link lists become native Social Icons blocks.
	 *
	 * @return void
	 */
	public function test_social_profile_link_lists_become_native_social_icons_blocks() {
		$converter = new ImportHtmlBlockConverter();
		$summary   = array();

		$markup = $converter->convert(
			'<ul id="profile-links" class="social-links show-labels aligncenter">'
			. '<li><a href="https://github.com/example" target="_BLANK" rel="ME noopener javascript:alert(1) ME">GitHub</a></li>'
			. '<br>'
			. '<li><a href="https://www.linkedin.com/company/example" target="_BLANK" aria-label="LinkedIn profile"><svg aria-hidden="true"></svg></a></li>'
			. '<li><a href="mailto:hello@example.test" target="_BLANK">Email us</a></li>'
			. '</ul>',
			$summary
		);

		$this->assertSame( 'structured', $summary['html_block_conversion'] );
		$this->assertSame( 1, $summary['html_inferred_block_count'] );
		$this->assertSame( 0, $summary['html_classic_block_count'] );
		$this->assertStringContainsString( '<!-- wp:social-links {"anchor":"profile-links","align":"center","showLabels":true,"openInNewTab":true} -->', $markup );
		$this->assertStringContainsString( '<ul class="wp-block-social-links aligncenter has-visible-labels" id="profile-links">', $markup );
		$this->assertStringContainsString( '<!-- wp:social-link {"url":"https:\/\/github.com\/example","service":"github","label":"GitHub","rel":"me noopener"} /-->', $markup );
		$this->assertStringContainsString( '<!-- wp:social-link {"url":"https:\/\/www.linkedin.com\/company\/example","service":"linkedin","label":"LinkedIn profile"} /-->', $markup );
		$this->assertStringContainsString( '<!-- wp:social-link {"url":"mailto:hello@example.test","service":"mail","label":"Email us"} /-->', $markup );
		$this->assertStringContainsString( '<!-- /wp:social-links -->', $markup );
		$this->assertStringNotContainsString( 'javascript:alert', $markup );
		$this->assertStringNotContainsString( '<!-- wp:list -->', $markup );
		$this->assertStringNotContainsString( '<!-- wp:freeform -->', $markup );
		$this->assertStringNotContainsString( '<br>', $markup );
	}

	/**
	 * Direct-anchor social wrappers become native Social Icons blocks.
	 *
	 * @return void
	 */
	public function test_direct_anchor_social_wrappers_become_native_social_icons_blocks() {
		$converter = new ImportHtmlBlockConverter();
		$summary   = array();

		$markup = $converter->convert(
			'<div id="sidebar-social" class="social-links alignright" title="Follow us">'
			. '<a href="https://youtube.com/example" target="_BLANK" title="YouTube channel" rel="ME javascript:alert(1)">Watch</a>'
			. '<br>'
			. '<span><a href="https://bsky.app/profile/example" target="_BLANK"><i aria-hidden="true"></i></a></span>'
			. '<a href="/feed.xml" target="_BLANK" aria-label="RSS feed"><svg></svg></a>'
			. '</div>',
			$summary
		);

		$this->assertSame( 'structured', $summary['html_block_conversion'] );
		$this->assertSame( 1, $summary['html_inferred_block_count'] );
		$this->assertSame( 0, $summary['html_classic_block_count'] );
		$this->assertStringContainsString( '<!-- wp:social-links {"anchor":"sidebar-social","align":"right","showLabels":true,"openInNewTab":true} -->', $markup );
		$this->assertStringContainsString( '<ul class="wp-block-social-links alignright has-visible-labels" id="sidebar-social">', $markup );
		$this->assertStringContainsString( '<!-- wp:social-link {"url":"https:\/\/youtube.com\/example","service":"youtube","label":"YouTube channel","rel":"me"} /-->', $markup );
		$this->assertStringContainsString( '<!-- wp:social-link {"url":"https:\/\/bsky.app\/profile\/example","service":"bluesky","label":"Bluesky"} /-->', $markup );
		$this->assertStringContainsString( '<!-- wp:social-link {"url":"\/feed.xml","service":"feed","label":"RSS feed"} /-->', $markup );
		$this->assertStringContainsString( '<!-- /wp:social-links -->', $markup );
		$this->assertStringNotContainsString( 'javascript:alert', $markup );
		$this->assertStringNotContainsString( '<!-- wp:list -->', $markup );
		$this->assertStringNotContainsString( '<!-- wp:freeform -->', $markup );
		$this->assertStringNotContainsString( '<br>', $markup );
	}

	/**
	 * Social nav wrappers can promote a nested list of profile links.
	 *
	 * @return void
	 */
	public function test_social_nav_wrappers_promote_nested_social_lists() {
		$converter = new ImportHtmlBlockConverter();
		$summary   = array();

		$markup = $converter->convert(
			'<nav id="footer-social" aria-label="Social profiles"><ul>'
			. '<li><a href="https://x.com/example" aria-label="X profile"><svg></svg></a></li>'
			. '<li><a href="https://www.instagram.com/example" aria-label="Instagram profile"><svg></svg></a></li>'
			. '</ul></nav>',
			$summary
		);

		$this->assertSame( 'structured', $summary['html_block_conversion'] );
		$this->assertSame( 1, $summary['html_inferred_block_count'] );
		$this->assertSame( 0, $summary['html_classic_block_count'] );
		$this->assertStringContainsString( '<!-- wp:social-links {"anchor":"footer-social"} -->', $markup );
		$this->assertStringContainsString( '<ul class="wp-block-social-links" id="footer-social">', $markup );
		$this->assertStringContainsString( '<!-- wp:social-link {"url":"https:\/\/x.com\/example","service":"x","label":"X profile"} /-->', $markup );
		$this->assertStringContainsString( '<!-- wp:social-link {"url":"https:\/\/www.instagram.com\/example","service":"instagram","label":"Instagram profile"} /-->', $markup );
		$this->assertStringNotContainsString( 'has-visible-labels', $markup );
		$this->assertStringNotContainsString( '<!-- wp:list -->', $markup );
	}

	/**
	 * Ordinary navigation lists are not guessed as Social Icons blocks.
	 *
	 * @return void
	 */
	public function test_ordinary_navigation_lists_are_not_social_icons() {
		$converter = new ImportHtmlBlockConverter();
		$summary   = array();

		$markup = $converter->convert(
			'<ul class="nav-links"><li><a href="/about">About</a></li><li><a href="/contact">Contact</a></li></ul>',
			$summary
		);

		$this->assertSame( 'structured', $summary['html_block_conversion'] );
		$this->assertStringContainsString( '<!-- wp:list -->', $markup );
		$this->assertStringContainsString( '<ul><li><a href="/about">About</a></li><li><a href="/contact">Contact</a></li></ul>', $markup );
		$this->assertStringNotContainsString( '<!-- wp:social-links', $markup );
	}

	/**
	 * Explicit navigation wrappers become native Navigation blocks.
	 *
	 * @return void
	 */
	public function test_navigation_wrappers_become_native_navigation_blocks() {
		$converter = new ImportHtmlBlockConverter();
		$summary   = array();

		$markup = $converter->convert(
			'<nav id="primary-menu" class="alignwide" aria-label="Primary menu"><br><ul>'
			. '<li><a href="/">Home</a></li>'
			. '<br>'
			. '<li><a href="/about" title="About us">About</a></li>'
			. '<li><a href="https://example.test/docs" target="_BLANK" rel="NOOPENER noreferrer javascript:alert(1) NOOPENER">Docs</a></li>'
			. '</ul></nav>',
			$summary
		);

		$this->assertSame( 'structured', $summary['html_block_conversion'] );
		$this->assertSame( 1, $summary['html_inferred_block_count'] );
		$this->assertSame( 0, $summary['html_classic_block_count'] );
		$this->assertStringContainsString( '<!-- wp:navigation {"overlayMenu":"never","anchor":"primary-menu","align":"wide","ariaLabel":"Primary menu"} -->', $markup );
		$this->assertStringContainsString( '<!-- wp:navigation-link {"label":"Home","url":"\/","kind":"custom","isTopLevelLink":true} /-->', $markup );
		$this->assertStringContainsString( '<!-- wp:navigation-link {"label":"About","url":"\/about","kind":"custom","isTopLevelLink":true,"title":"About us"} /-->', $markup );
		$this->assertStringContainsString( '<!-- wp:navigation-link {"label":"Docs","url":"https:\/\/example.test\/docs","kind":"custom","isTopLevelLink":true,"rel":"noopener noreferrer","opensInNewTab":true} /-->', $markup );
		$this->assertStringContainsString( '<!-- /wp:navigation -->', $markup );
		$this->assertStringNotContainsString( '<!-- wp:list -->', $markup );
		$this->assertStringNotContainsString( '<!-- wp:freeform -->', $markup );
		$this->assertStringNotContainsString( 'javascript:alert', $markup );
		$this->assertStringNotContainsString( '<br>', $markup );
	}

	/**
	 * Direct-anchor navigation wrappers become native Navigation blocks.
	 *
	 * @return void
	 */
	public function test_direct_anchor_navigation_wrappers_become_native_navigation_blocks() {
		$converter = new ImportHtmlBlockConverter();
		$summary   = array();

		$markup = $converter->convert(
			'<nav id="footer-menu" aria-label="Footer menu">'
			. '<a href="/privacy" title="Privacy policy">Privacy</a>'
			. '<br>'
			. '<a href="https://example.test/support" target="_BLANK" rel="NOOPENER javascript:alert(1)">Support</a>'
			. '</nav>',
			$summary
		);

		$this->assertSame( 'structured', $summary['html_block_conversion'] );
		$this->assertSame( 1, $summary['html_inferred_block_count'] );
		$this->assertSame( 0, $summary['html_classic_block_count'] );
		$this->assertStringContainsString( '<!-- wp:navigation {"overlayMenu":"never","anchor":"footer-menu","ariaLabel":"Footer menu"} -->', $markup );
		$this->assertStringContainsString( '<!-- wp:navigation-link {"label":"Privacy","url":"\/privacy","kind":"custom","isTopLevelLink":true,"title":"Privacy policy"} /-->', $markup );
		$this->assertStringContainsString( '<!-- wp:navigation-link {"label":"Support","url":"https:\/\/example.test\/support","kind":"custom","isTopLevelLink":true,"rel":"noopener","opensInNewTab":true} /-->', $markup );
		$this->assertStringContainsString( '<!-- /wp:navigation -->', $markup );
		$this->assertStringNotContainsString( '<!-- wp:list -->', $markup );
		$this->assertStringNotContainsString( '<!-- wp:freeform -->', $markup );
		$this->assertStringNotContainsString( 'javascript:alert', $markup );
		$this->assertStringNotContainsString( '<br>', $markup );
	}

	/**
	 * Nested imported menus become Navigation Submenu blocks when the structure is clear.
	 *
	 * @return void
	 */
	public function test_nested_navigation_menus_become_navigation_submenus() {
		$converter = new ImportHtmlBlockConverter();
		$summary   = array();

		$markup = $converter->convert(
			'<nav class="site-navigation"><ul>'
			. '<li><a href="/services" title="Our services" target="_BLANK" rel="NOOPENER noreferrer javascript:alert(1)">Services</a><br><ul>'
			. '<li><a href="/services/imports">Imports</a></li>'
			. '<br>'
			. '<li><a href="/services/recovery">Recovery</a></li>'
			. '</ul></li>'
			. '<li><a href="/contact">Contact</a></li>'
			. '</ul></nav>',
			$summary
		);

		$this->assertSame( 'structured', $summary['html_block_conversion'] );
		$this->assertSame( 1, $summary['html_inferred_block_count'] );
		$this->assertSame( 0, $summary['html_classic_block_count'] );
		$this->assertStringContainsString( '<!-- wp:navigation {"overlayMenu":"never"} -->', $markup );
		$this->assertStringContainsString( '<!-- wp:navigation-submenu {"label":"Services","url":"\/services","kind":"custom","isTopLevelItem":true,"title":"Our services","rel":"noopener noreferrer","opensInNewTab":true} -->', $markup );
		$this->assertStringContainsString( '<!-- wp:navigation-link {"label":"Imports","url":"\/services\/imports","kind":"custom"} /-->', $markup );
		$this->assertStringContainsString( '<!-- wp:navigation-link {"label":"Recovery","url":"\/services\/recovery","kind":"custom"} /-->', $markup );
		$this->assertStringContainsString( '<!-- /wp:navigation-submenu -->', $markup );
		$this->assertStringContainsString( '<!-- wp:navigation-link {"label":"Contact","url":"\/contact","kind":"custom","isTopLevelLink":true} /-->', $markup );
		$this->assertStringNotContainsString( '<!-- wp:list -->', $markup );
		$this->assertStringNotContainsString( '<!-- wp:freeform -->', $markup );
		$this->assertStringNotContainsString( 'javascript:alert', $markup );
	}

	/**
	 * Ambiguous navigation wrappers keep a conservative fallback.
	 *
	 * @return void
	 */
	public function test_ambiguous_navigation_wrappers_keep_classic_fallback() {
		$converter = new ImportHtmlBlockConverter();
		$summary   = array();

		$markup = $converter->convert( '<nav><ul><li><span>Missing link</span></li><li><a href="/known">Known</a></li></ul></nav>', $summary );

		$this->assertSame( 'mixed', $summary['html_block_conversion'] );
		$this->assertSame( 0, $summary['html_inferred_block_count'] );
		$this->assertSame( 1, $summary['html_classic_block_count'] );
		$this->assertStringContainsString( '<!-- wp:freeform -->', $markup );
		$this->assertStringContainsString( '<nav><ul><li><span>Missing link</span></li><li><a href="/known">Known</a></li></ul></nav>', $markup );
		$this->assertStringNotContainsString( '<!-- wp:navigation', $markup );
	}

	/**
	 * Unknown iframe embeds are kept in a Custom HTML block instead of Classic.
	 *
	 * @return void
	 */
	public function test_unknown_iframe_embeds_become_custom_html_blocks() {
		$converter = new ImportHtmlBlockConverter();
		$summary   = array();

		$markup = $converter->convert( '<iframe src="/local/embed" title="Local embed" loading="lazy" referrerpolicy="strict-origin-when-cross-origin"></iframe>', $summary );

		$this->assertSame( 'structured', $summary['html_block_conversion'] );
		$this->assertSame( 1, $summary['html_inferred_block_count'] );
		$this->assertSame( 0, $summary['html_classic_block_count'] );
		$this->assertStringContainsString( '<!-- wp:html -->', $markup );
		$this->assertStringContainsString( '<iframe src="/local/embed" title="Local embed" loading="lazy" referrerpolicy="strict-origin-when-cross-origin"></iframe>', $markup );
		$this->assertStringNotContainsString( '<!-- wp:freeform -->', $markup );
	}

	/**
	 * Bare known-provider links become native Embed blocks without dropping labeled links.
	 *
	 * @return void
	 */
	public function test_bare_provider_links_become_native_embed_blocks() {
		$converter = new ImportHtmlBlockConverter();
		$summary   = array();

		$markup = $converter->convert(
			'<p id="soundcloud-embed" style="margin: 0 auto">https://soundcloud.com/example/import-update</p>'
			. '<p><a id="vimeo-embed" href="https://vimeo.com/12345">https://vimeo.com/12345</a></p>'
			. '<p><a id="vimeo-player-embed" class="alignwide" href="https://player.vimeo.com/video/67890">https://vimeo.com/67890</a></p>'
			. '<p><a id="youtube-privacy-embed" href="https://www.youtube-nocookie.com/embed/abc123">https://www.youtube.com/watch?v=abc123</a></p>'
			. '<p><a href="https://www.youtube.com/watch?v=dQw4w9WgXcQ">Watch video</a></p>',
			$summary
		);

		$this->assertSame( 'structured', $summary['html_block_conversion'] );
		$this->assertSame( 5, $summary['html_inferred_block_count'] );
		$this->assertSame( 0, $summary['html_classic_block_count'] );
		$this->assertStringContainsString( '<!-- wp:embed {"url":"https:\/\/soundcloud.com\/example\/import-update","type":"rich","providerNameSlug":"soundcloud","responsive":true,"align":"center","anchor":"soundcloud-embed"} -->', $markup );
		$this->assertStringContainsString( '<figure class="wp-block-embed is-type-rich is-provider-soundcloud wp-block-embed-soundcloud aligncenter" id="soundcloud-embed"><div class="wp-block-embed__wrapper">https://soundcloud.com/example/import-update</div></figure>', $markup );
		$this->assertStringContainsString( '<!-- wp:embed {"url":"https:\/\/vimeo.com\/12345","type":"video","providerNameSlug":"vimeo","responsive":true,"anchor":"vimeo-embed"} -->', $markup );
		$this->assertStringContainsString( '<figure class="wp-block-embed is-type-video is-provider-vimeo wp-block-embed-vimeo" id="vimeo-embed"><div class="wp-block-embed__wrapper">https://vimeo.com/12345</div></figure>', $markup );
		$this->assertStringContainsString( '<!-- wp:embed {"url":"https:\/\/vimeo.com\/67890","type":"video","providerNameSlug":"vimeo","responsive":true,"align":"wide","anchor":"vimeo-player-embed"} -->', $markup );
		$this->assertStringContainsString( '<figure class="wp-block-embed is-type-video is-provider-vimeo wp-block-embed-vimeo alignwide" id="vimeo-player-embed"><div class="wp-block-embed__wrapper">https://vimeo.com/67890</div></figure>', $markup );
		$this->assertStringContainsString( '<!-- wp:embed {"url":"https:\/\/www.youtube.com\/watch?v=abc123","type":"video","providerNameSlug":"youtube","responsive":true,"anchor":"youtube-privacy-embed"} -->', $markup );
		$this->assertStringContainsString( '<figure class="wp-block-embed is-type-video is-provider-youtube wp-block-embed-youtube" id="youtube-privacy-embed"><div class="wp-block-embed__wrapper">https://www.youtube.com/watch?v=abc123</div></figure>', $markup );
		$this->assertStringContainsString( '<p><a href="https://www.youtube.com/watch?v=dQw4w9WgXcQ">Watch video</a></p>', $markup );
		$this->assertStringNotContainsString( 'margin: 0 auto', $markup );
		$this->assertStringNotContainsString( 'https://player.vimeo.com/video/67890', $markup );
		$this->assertStringNotContainsString( 'https://www.youtube-nocookie.com/embed/abc123', $markup );
		$this->assertStringNotContainsString( '<!-- wp:embed {"url":"https:\/\/www.youtube.com\/watch?v=dQw4w9WgXcQ"', $markup );
		$this->assertStringNotContainsString( '<!-- wp:freeform -->', $markup );
	}

	/**
	 * Imported form markup is preserved as Custom HTML after executable attributes are stripped.
	 *
	 * @return void
	 */
	public function test_forms_become_sanitized_custom_html_blocks() {
		$converter = new ImportHtmlBlockConverter();
		$summary   = array();

		$markup = $converter->convert(
			'<form action="javascript:alert(1)" method="post" onsubmit="steal()">'
			. '<label>Email <input type="email" name="email" onclick="track()"></label>'
			. '<button formaction="vbscript:msgbox(1)" style="background:url(javascript:alert(1))">Join</button>'
			. '<span style="background-image:url(data:image/svg+xml,%3Csvg%20onload%3Dalert(1)%3E%3C/svg%3E)">Unsafe SVG background</span>'
			. '<object data="data:image/svg+xml,%3Csvg%20onload%3Dalert(1)%3E%3C/svg%3E">Unsafe object fallback</object>'
			. '<img src="/safe.jpg" srcset="/safe.jpg 1x, data:image/svg+xml,%3Csvg%20onload%3Dalert(1)%3E%3C/svg%3E 2x" alt="Unsafe srcset image">'
			. '<video src="/safe.mp4" poster="data:image/svg+xml,%3Csvg%20onload%3Dalert(1)%3E%3C/svg%3E"></video>'
			. '<span background="javascript:alert(1)">Unsafe background attribute</span>'
			. '<meta http-equiv="refresh" content="0; url=javascript:alert(1)">'
			. '<iframe src="/safe-frame" srcdoc="&lt;script&gt;alert(1)&lt;/script&gt;" title="Unsafe srcdoc"></iframe>'
			. '<img src="/fallback.jpg" dynsrc="javascript:alert(1)" lowsrc="vbscript:msgbox(1)" alt="Unsafe legacy image">'
			. '<img src="/described.jpg" longdesc="javascript:alert(1)" alt="Unsafe longdesc image">'
			. '<blockquote cite="vbscript:msgbox(1)">Unsafe cite quote.</blockquote>'
			. '<a href="/safe-target" ping="/safe-ping javascript:alert(1)">Unsafe ping link</a>'
			. '<object codebase="javascript:alert(1)" classid="vbscript:msgbox(1)" archive="data:text/html,%3Cscript%3Ealert(1)%3C/script%3E">Unsafe legacy object</object>'
			. '<style>.unsafe{background:url(javascript:alert(1))}</style>'
			. '<style>.unsafe-import{@import "javascript:alert(1)"}</style>'
			. "<style>.unsafe-split{background:url(ja\nvascript:alert(1))}</style>"
			. '<style>.unsafe-escape{background:url(\6a avascript:alert(1))}</style>'
			. '</form>',
			$summary
		);

		$this->assertSame( 'structured', $summary['html_block_conversion'] );
		$this->assertSame( 1, $summary['html_inferred_block_count'] );
		$this->assertSame( 0, $summary['html_classic_block_count'] );
		$this->assertStringContainsString( '<!-- wp:html -->', $markup );
		$this->assertStringContainsString( '<form method="post">', $markup );
		$this->assertStringContainsString( '<input type="email" name="email">', $markup );
		$this->assertStringContainsString( '<button>Join</button>', $markup );
		$this->assertStringContainsString( '<span>Unsafe SVG background</span>', $markup );
			$this->assertStringContainsString( '<object>Unsafe object fallback</object>', $markup );
			$this->assertStringContainsString( '<img src="/safe.jpg" alt="Unsafe srcset image">', $markup );
			$this->assertStringContainsString( '<video src="/safe.mp4"></video>', $markup );
			$this->assertStringContainsString( '<span>Unsafe background attribute</span>', $markup );
			$this->assertStringContainsString( '<meta http-equiv="refresh">', $markup );
			$this->assertStringContainsString( '<iframe src="/safe-frame" title="Unsafe srcdoc"></iframe>', $markup );
			$this->assertStringContainsString( '<img src="/fallback.jpg" alt="Unsafe legacy image">', $markup );
		$this->assertStringContainsString( '<img src="/described.jpg" alt="Unsafe longdesc image">', $markup );
		$this->assertStringContainsString( '<blockquote>Unsafe cite quote.</blockquote>', $markup );
		$this->assertStringContainsString( '<a href="/safe-target">Unsafe ping link</a>', $markup );
		$this->assertStringContainsString( '<object>Unsafe legacy object</object>', $markup );
		$this->assertStringNotContainsString( 'javascript:', $markup );
		$this->assertStringNotContainsString( 'vbscript:', $markup );
		$this->assertStringNotContainsString( 'data:image/svg+xml', $markup );
			$this->assertStringNotContainsString( ' data=', $markup );
			$this->assertStringNotContainsString( 'srcset=', $markup );
			$this->assertStringNotContainsString( 'poster=', $markup );
			$this->assertStringNotContainsString( 'background=', $markup );
			$this->assertStringNotContainsString( ' url=', $markup );
			$this->assertStringNotContainsString( 'srcdoc=', $markup );
			$this->assertStringNotContainsString( 'dynsrc=', $markup );
		$this->assertStringNotContainsString( 'lowsrc=', $markup );
		$this->assertStringNotContainsString( 'longdesc=', $markup );
		$this->assertStringNotContainsString( 'cite=', $markup );
		$this->assertStringNotContainsString( 'ping=', $markup );
		$this->assertStringNotContainsString( 'codebase=', $markup );
		$this->assertStringNotContainsString( 'classid=', $markup );
		$this->assertStringNotContainsString( 'archive=', $markup );
		$this->assertStringNotContainsString( '<style', $markup );
		$this->assertStringNotContainsString( 'unsafe', $markup );
		$this->assertStringNotContainsString( 'onsubmit', $markup );
		$this->assertStringNotContainsString( 'onclick', $markup );
		$this->assertStringNotContainsString( 'style=', $markup );
		$this->assertStringNotContainsString( '<!-- wp:freeform -->', $markup );
	}

	/**
	 * The no-DOM sanitizer fallback strips the same scriptable URL attributes.
	 *
	 * @return void
	 */
	public function test_regex_sanitizer_fallback_strips_scriptable_url_attributes() {
		$converter = new ImportHtmlBlockConverter();
		$method    = new ReflectionMethod( $converter, 'sanitize_executable_html_attributes_with_regex' );
		$method->setAccessible( true );

		$markup = $method->invoke(
			$converter,
			'<a href="javascript:alert(1)" data-importer-id="safe">Link</a>'
			. '<img src="/safe.jpg" srcset="/safe.jpg 1x, data:image/svg+xml,%3Csvg%20onload%3Dalert(1)%3E%3C/svg%3E 2x" alt="Unsafe srcset image">'
			. '<video src="/safe.mp4" poster="data:image/svg+xml,%3Csvg%20onload%3Dalert(1)%3E%3C/svg%3E"></video>'
			. '<span background="vbscript:msgbox(1)">Unsafe background attribute</span>'
			. '<meta http-equiv="refresh" content="0; url=javascript:alert(1)">'
			. '<iframe src="/safe-frame" srcdoc="&lt;script&gt;alert(1)&lt;/script&gt;"></iframe>'
			. '<img src="/fallback.jpg" dynsrc="javascript:alert(1)" lowsrc="vbscript:msgbox(1)" alt="Unsafe legacy image">'
			. '<img src="/described.jpg" longdesc="javascript:alert(1)" alt="Unsafe longdesc image">'
			. '<blockquote cite="vbscript:msgbox(1)">Unsafe cite quote.</blockquote>'
			. '<a href="/safe-target" ping="/safe-ping javascript:alert(1)">Unsafe ping link</a>'
			. '<object codebase="javascript:alert(1)" classid="vbscript:msgbox(1)" archive="data:text/html,%3Cscript%3Ealert(1)%3C/script%3E">Unsafe legacy object</object>'
			. '<style>.unsafe{background:url(javascript:alert(1))}</style>'
			. '<style>.unsafe-import{@import "vbscript:msgbox(1)"}</style>'
			. "<style>.unsafe-split{@import \"vb\nscript:msgbox(1)\"}</style>"
			. '<style>.unsafe-escape{@import "\76 bscript:msgbox(1)"}</style>'
			. '<object data="data:text/html,<script>alert(1)</script>">Unsafe object fallback</object>'
		);

		$this->assertStringContainsString( '<a data-importer-id="safe">Link</a>', $markup );
		$this->assertStringContainsString( '<img src="/safe.jpg" alt="Unsafe srcset image">', $markup );
		$this->assertStringContainsString( '<video src="/safe.mp4"></video>', $markup );
		$this->assertStringContainsString( '<span>Unsafe background attribute</span>', $markup );
		$this->assertStringContainsString( '<meta http-equiv="refresh">', $markup );
		$this->assertStringContainsString( '<iframe src="/safe-frame"></iframe>', $markup );
		$this->assertStringContainsString( '<img src="/fallback.jpg" alt="Unsafe legacy image">', $markup );
		$this->assertStringContainsString( '<img src="/described.jpg" alt="Unsafe longdesc image">', $markup );
		$this->assertStringContainsString( '<blockquote>Unsafe cite quote.</blockquote>', $markup );
		$this->assertStringContainsString( '<a href="/safe-target">Unsafe ping link</a>', $markup );
		$this->assertStringContainsString( '<object>Unsafe legacy object</object>', $markup );
		$this->assertStringContainsString( '<object>Unsafe object fallback</object>', $markup );
		$this->assertStringNotContainsString( 'javascript:', $markup );
		$this->assertStringNotContainsString( 'vbscript:', $markup );
		$this->assertStringNotContainsString( 'data:image/svg+xml', $markup );
		$this->assertStringNotContainsString( 'data:text/html', $markup );
		$this->assertStringNotContainsString( 'srcset=', $markup );
		$this->assertStringNotContainsString( 'poster=', $markup );
		$this->assertStringNotContainsString( 'background=', $markup );
		$this->assertStringNotContainsString( ' url=', $markup );
		$this->assertStringNotContainsString( 'srcdoc=', $markup );
		$this->assertStringNotContainsString( 'dynsrc=', $markup );
		$this->assertStringNotContainsString( 'lowsrc=', $markup );
		$this->assertStringNotContainsString( 'longdesc=', $markup );
		$this->assertStringNotContainsString( 'cite=', $markup );
		$this->assertStringNotContainsString( 'ping=', $markup );
		$this->assertStringNotContainsString( 'codebase=', $markup );
		$this->assertStringNotContainsString( 'classid=', $markup );
		$this->assertStringNotContainsString( 'archive=', $markup );
		$this->assertStringNotContainsString( '<style', $markup );
		$this->assertStringNotContainsString( 'unsafe', $markup );
		$this->assertStringNotContainsString( '@import', $markup );
		$this->assertStringNotContainsString( ' data=', $markup );
	}

	/**
	 * Safe CSS escapes and relative URLs are preserved during sanitization.
	 *
	 * @return void
	 */
	public function test_sanitizers_preserve_safe_css_escapes_and_urls() {
		$converter = new ImportHtmlBlockConverter();
		$method    = new ReflectionMethod( $converter, 'sanitize_executable_html_attributes_with_regex' );
		$method->setAccessible( true );
		$source = '<style>.safe-icon:before{content:"\2713";background:url(/safe.svg)}</style>'
			. '<span style="background:url(/safe.svg)">Safe</span>'
			. '<a href="/safe-target" ping="/safe-ping https://analytics.example.test/ping">Safe ping</a>';

		$dom_markup   = $converter->sanitize_executable_html_attributes( $source );
		$regex_markup = $method->invoke( $converter, $source );

		foreach ( array( $dom_markup, $regex_markup ) as $markup ) {
			$this->assertStringContainsString( '.safe-icon:before', $markup );
			$this->assertStringContainsString( 'content:"\2713"', $markup );
			$this->assertStringContainsString( 'background:url(/safe.svg)', $markup );
			$this->assertStringContainsString( '<span style="background:url(/safe.svg)">Safe</span>', $markup );
			$this->assertStringContainsString( '<a href="/safe-target" ping="/safe-ping https://analytics.example.test/ping">Safe ping</a>', $markup );
			$this->assertStringNotContainsString( 'javascript:', $markup );
			$this->assertStringNotContainsString( 'vbscript:', $markup );
		}
	}

	/**
	 * Clear imported search forms become native Search blocks.
	 *
	 * @return void
	 */
	public function test_search_forms_become_native_search_blocks() {
		$converter = new ImportHtmlBlockConverter();
		$summary   = array();

		$markup = $converter->convert(
			'<form id="site-search" role="search" class="search-form alignright" method="get" action="/legacy-search">'
			. '<label class="screen-reader-text" for="site-search-field">Search the archive</label>'
			. '<input id="site-search-field" type="search" name="s" placeholder="Keywords">'
			. '<button type="submit" class="search-submit"><span class="screen-reader-text">Search</span><svg viewBox="0 0 20 20"></svg></button>'
			. '</form>'
			. '<form id="hidden-style-search" role="search" method="get">'
			. '<label for="hidden-style-field" style="display: none">Hidden catalogue search</label>'
			. '<input id="hidden-style-field" type="search" name="s" placeholder="Catalogue">'
			. '<button type="submit">Go</button>'
			. '</form>'
			. '<form id="hidden-attribute-search" role="search" method="get">'
			. '<label for="hidden-attribute-field" hidden>Hidden docs search</label>'
			. '<input id="hidden-attribute-field" type="search" name="s" placeholder="Docs">'
			. '<button type="submit">Find</button>'
			. '</form>'
			. '<form id="hidden-visibility-search" role="search" method="get">'
			. '<label for="hidden-visibility-field" style="visibility: hidden">Hidden media search</label>'
			. '<input id="hidden-visibility-field" type="search" name="s" placeholder="Media">'
			. '<button type="submit">Lookup</button>'
			. '</form>',
			$summary
		);

		$this->assertSame( 'structured', $summary['html_block_conversion'] );
		$this->assertSame( 4, $summary['html_inferred_block_count'] );
		$this->assertSame( 0, $summary['html_classic_block_count'] );
		$this->assertStringContainsString( '<!-- wp:search ', $markup );
		$this->assertStringContainsString( '"label":"Search the archive"', $markup );
		$this->assertStringContainsString( '"buttonText":"Search"', $markup );
		$this->assertStringContainsString( '"placeholder":"Keywords"', $markup );
		$this->assertStringContainsString( '"showLabel":false', $markup );
		$this->assertStringContainsString( '"buttonUseIcon":true', $markup );
		$this->assertStringContainsString( '"anchor":"site-search"', $markup );
		$this->assertStringContainsString( '"align":"right"', $markup );
		$this->assertStringContainsString( '<!-- wp:search {"label":"Hidden catalogue search","buttonText":"Go","placeholder":"Catalogue","showLabel":false,"anchor":"hidden-style-search"} /-->', $markup );
		$this->assertStringContainsString( '<!-- wp:search {"label":"Hidden docs search","buttonText":"Find","placeholder":"Docs","showLabel":false,"anchor":"hidden-attribute-search"} /-->', $markup );
		$this->assertStringContainsString( '<!-- wp:search {"label":"Hidden media search","buttonText":"Lookup","placeholder":"Media","showLabel":false,"anchor":"hidden-visibility-search"} /-->', $markup );
		$this->assertStringContainsString( ' /-->', $markup );
		$this->assertStringNotContainsString( '<form', $markup );
		$this->assertStringNotContainsString( '<!-- wp:html -->', $markup );
		$this->assertStringNotContainsString( '<!-- wp:freeform -->', $markup );
	}

	/**
	 * Search forms with text query inputs and image submits keep native metadata.
	 *
	 * @return void
	 */
	public function test_search_forms_with_text_query_inputs_and_image_submits_become_native_search_blocks() {
		$converter = new ImportHtmlBlockConverter();
		$summary   = array();

		$markup = $converter->convert(
			'<form id="archive-finder" class="search-form" style="margin: 0 auto" method="get" title="Archive search">'
			. '<input type="text" name="q" aria-label="Find imported posts" placeholder="Search posts">'
			. '<input type="image" src="/search-button.png" alt="Find posts">'
			. '</form>'
			. '<form id="visible-search" class="search-form" method="get">'
			. '<label>Site search <input type="text" name="search" placeholder="Find content"></label>'
			. '<input type="submit" value="Find">'
			. '</form>'
			. '<form id="icon-only-search" role="search" method="get">'
			. '<label class="screen-reader-text" for="icon-query">Lookup docs</label>'
			. '<input id="icon-query" type="search" name="s" placeholder="Docs">'
			. '<button type="submit" aria-label="Run lookup"><svg viewBox="0 0 20 20"></svg></button>'
			. '</form>'
			. '<form id="image-aria-search" role="search" method="get">'
			. '<input type="search" name="s" aria-label="Search media" placeholder="Images">'
			. '<input type="image" src="/image-search.png" aria-label="Search images">'
			. '</form>'
			. '<form id="submit-aria-search" role="search" method="get">'
			. '<input type="search" name="s" aria-label="Search docs" placeholder="Docs">'
			. '<input type="submit" aria-label="Go docs">'
			. '</form>'
			. '<form id="title-search" role="search" method="get">'
			. '<input type="search" name="s" title="Search titles" placeholder="Titles">'
			. '<button type="submit">Filter</button>'
			. '</form>'
			. '<form id="form-title-search" role="search" method="get" title="Search archives">'
			. '<input type="search" name="s" placeholder="Archives">'
			. '<button type="submit">Browse</button>'
			. '</form>',
			$summary
		);

		$this->assertSame( 'structured', $summary['html_block_conversion'] );
		$this->assertSame( 7, $summary['html_inferred_block_count'] );
		$this->assertSame( 0, $summary['html_classic_block_count'] );
		$this->assertStringContainsString( '<!-- wp:search ', $markup );
		$this->assertStringContainsString( '"label":"Find imported posts"', $markup );
		$this->assertStringContainsString( '"buttonText":"Find posts"', $markup );
		$this->assertStringContainsString( '"placeholder":"Search posts"', $markup );
		$this->assertStringContainsString( '"showLabel":false', $markup );
		$this->assertStringContainsString( '"buttonUseIcon":true', $markup );
		$this->assertStringContainsString( '"anchor":"archive-finder"', $markup );
		$this->assertStringContainsString( '"align":"center"', $markup );
		$this->assertStringNotContainsString( 'margin: 0 auto', $markup );
		$this->assertStringContainsString( '<!-- wp:search {"label":"Site search","buttonText":"Find","placeholder":"Find content","anchor":"visible-search"} /-->', $markup );
		$this->assertStringContainsString( '<!-- wp:search {"label":"Lookup docs","buttonText":"Run lookup","placeholder":"Docs","showLabel":false,"buttonUseIcon":true,"anchor":"icon-only-search"} /-->', $markup );
		$this->assertStringContainsString( '<!-- wp:search {"label":"Search media","buttonText":"Search images","placeholder":"Images","showLabel":false,"buttonUseIcon":true,"anchor":"image-aria-search"} /-->', $markup );
		$this->assertStringContainsString( '<!-- wp:search {"label":"Search docs","buttonText":"Go docs","placeholder":"Docs","showLabel":false,"anchor":"submit-aria-search"} /-->', $markup );
		$this->assertStringContainsString( '<!-- wp:search {"label":"Search titles","buttonText":"Filter","placeholder":"Titles","showLabel":false,"anchor":"title-search"} /-->', $markup );
		$this->assertStringContainsString( '<!-- wp:search {"label":"Search archives","buttonText":"Browse","placeholder":"Archives","showLabel":false,"anchor":"form-title-search"} /-->', $markup );
		$this->assertStringContainsString( ' /-->', $markup );
		$this->assertStringNotContainsString( '<form', $markup );
		$this->assertStringNotContainsString( '<input', $markup );
		$this->assertStringNotContainsString( '<!-- wp:html -->', $markup );
		$this->assertStringNotContainsString( '<!-- wp:freeform -->', $markup );
	}

	/**
	 * Search-like forms with extra filters stay as Custom HTML.
	 *
	 * @return void
	 */
	public function test_search_forms_with_extra_filters_keep_custom_html() {
		$converter = new ImportHtmlBlockConverter();
		$summary   = array();

		$markup = $converter->convert(
			'<form role="search" method="get"><input type="search" name="s"><select name="post_type"><option>Products</option></select><button>Search</button></form>',
			$summary
		);

		$this->assertSame( 'structured', $summary['html_block_conversion'] );
		$this->assertSame( 1, $summary['html_inferred_block_count'] );
		$this->assertSame( 0, $summary['html_classic_block_count'] );
		$this->assertStringContainsString( '<!-- wp:html -->', $markup );
		$this->assertStringContainsString( '<select name="post_type">', $markup );
		$this->assertStringNotContainsString( '<!-- wp:search ', $markup );
		$this->assertStringNotContainsString( '<!-- wp:freeform -->', $markup );
	}

	/**
	 * Empty legacy spacer wrappers become native Spacer blocks.
	 *
	 * @return void
	 */
	public function test_empty_spacer_wrappers_become_native_spacer_blocks() {
		$converter = new ImportHtmlBlockConverter();
		$summary   = array();

		$markup = $converter->convert(
			'<div id="section-gap" class="spacer" style="height: 48px !important"></div>'
			. '<span class="vertical-spacer" data-height="2.5rem">&nbsp;<br></span>'
			. '<div class="wp-block-spacer" height="100"></div>'
			. '<section id="viewport-gap" role="presentation" data-spacer-height="75vh" style="height: 10px"></section>'
			. '<div id="minimum-gap" class="spacer" style="min-height: 6rem"></div>'
			. '<div id="preferred-height-gap" class="spacer" style="min-height: 6rem; height: 32px"></div>'
			. '<div id="padding-gap" class="spacer" style="padding-top: 4em"></div>'
			. '<div id="preferred-minimum-gap" class="spacer" style="padding-top: 4em; min-height: 5rem"></div>',
			$summary
		);

		$this->assertSame( 'structured', $summary['html_block_conversion'] );
		$this->assertSame( 8, $summary['html_inferred_block_count'] );
		$this->assertSame( 0, $summary['html_classic_block_count'] );
		$this->assertStringContainsString( '<!-- wp:spacer {"height":"48px","anchor":"section-gap"} -->', $markup );
		$this->assertStringContainsString( '<div style="height:48px" aria-hidden="true" class="wp-block-spacer" id="section-gap"></div>', $markup );
		$this->assertStringContainsString( '<!-- wp:spacer {"height":"2.5rem"} -->', $markup );
		$this->assertStringContainsString( '<div style="height:2.5rem" aria-hidden="true" class="wp-block-spacer"></div>', $markup );
		$this->assertStringContainsString( '<!-- wp:spacer {"height":"100px"} -->', $markup );
		$this->assertStringContainsString( '<div style="height:100px" aria-hidden="true" class="wp-block-spacer"></div>', $markup );
		$this->assertStringContainsString( '<!-- wp:spacer {"height":"75vh","anchor":"viewport-gap"} -->', $markup );
		$this->assertStringContainsString( '<div style="height:75vh" aria-hidden="true" class="wp-block-spacer" id="viewport-gap"></div>', $markup );
		$this->assertStringContainsString( '<!-- wp:spacer {"height":"6rem","anchor":"minimum-gap"} -->', $markup );
		$this->assertStringContainsString( '<div style="height:6rem" aria-hidden="true" class="wp-block-spacer" id="minimum-gap"></div>', $markup );
		$this->assertStringContainsString( '<!-- wp:spacer {"height":"32px","anchor":"preferred-height-gap"} -->', $markup );
		$this->assertStringContainsString( '<div style="height:32px" aria-hidden="true" class="wp-block-spacer" id="preferred-height-gap"></div>', $markup );
		$this->assertStringNotContainsString( '<div style="height:6rem" aria-hidden="true" class="wp-block-spacer" id="preferred-height-gap"></div>', $markup );
		$this->assertStringContainsString( '<!-- wp:spacer {"height":"4em","anchor":"padding-gap"} -->', $markup );
		$this->assertStringContainsString( '<div style="height:4em" aria-hidden="true" class="wp-block-spacer" id="padding-gap"></div>', $markup );
		$this->assertStringContainsString( '<!-- wp:spacer {"height":"5rem","anchor":"preferred-minimum-gap"} -->', $markup );
		$this->assertStringContainsString( '<div style="height:5rem" aria-hidden="true" class="wp-block-spacer" id="preferred-minimum-gap"></div>', $markup );
		$this->assertStringNotContainsString( '<div style="height:4em" aria-hidden="true" class="wp-block-spacer" id="preferred-minimum-gap"></div>', $markup );
		$this->assertStringNotContainsString( 'height:10px', $markup );
		$this->assertStringNotContainsString( '<!-- wp:freeform -->', $markup );
	}

	/**
	 * Contentful or unbounded spacer-like wrappers keep the conservative fallback.
	 *
	 * @return void
	 */
	public function test_contentful_or_unbounded_spacer_wrappers_keep_classic_fallback() {
		$converter = new ImportHtmlBlockConverter();
		$summary   = array();

		$markup = $converter->convert(
			'<div class="spacer" style="height: 40px"><span>Meaningful content</span></div>'
			. '<div class="spacer" style="height: 4000px"></div>'
			. '<div class="spacer" style="min-height: 4000px"></div>'
			. '<div class="spacer" style="padding-top: 4000px"></div>',
			$summary
		);

		$this->assertSame( 'mixed', $summary['html_block_conversion'] );
		$this->assertSame( 0, $summary['html_inferred_block_count'] );
		$this->assertSame( 4, $summary['html_classic_block_count'] );
		$this->assertStringContainsString( '<!-- wp:freeform -->', $markup );
		$this->assertStringContainsString( '<span>Meaningful content</span>', $markup );
		$this->assertStringContainsString( 'height: 4000px', $markup );
		$this->assertStringContainsString( 'min-height: 4000px', $markup );
		$this->assertStringContainsString( 'padding-top: 4000px', $markup );
		$this->assertStringNotContainsString( '<!-- wp:spacer', $markup );
	}

	/**
	 * Executable attributes are stripped before generated block markup is serialized.
	 *
	 * @return void
	 */
	public function test_generated_blocks_strip_executable_attributes() {
		$converter = new ImportHtmlBlockConverter();
		$summary   = array();

		$markup = $converter->convert(
			'<h2 id="safe" onclick="alert(1)">Safe heading</h2>'
			. '<p><a class="button" href="javascript:alert(1)" title="Unsafe">Bad button</a></p>'
			. '<img src="data:image/svg+xml,&lt;svg onload=&quot;alert(1)&quot;&gt;" alt="Bad image" onerror="alert(1)">',
			$summary
		);

		$this->assertSame( 'structured', $summary['html_block_conversion'] );
		$this->assertStringContainsString( '<h2 id="safe">Safe heading</h2>', $markup );
		$this->assertStringContainsString( '<p><a class="button" title="Unsafe">Bad button</a></p>', $markup );
		$this->assertStringContainsString( '<figure class="wp-block-image"><img alt="Bad image"></figure>', $markup );
		$this->assertStringNotContainsString( 'javascript:', $markup );
		$this->assertStringNotContainsString( 'data:image/svg', $markup );
		$this->assertStringNotContainsString( 'onclick', $markup );
		$this->assertStringNotContainsString( 'onerror', $markup );
	}

	/**
	 * Details/summary disclosures become native Details blocks.
	 *
	 * @return void
	 */
	public function test_details_summary_disclosures_become_native_details_blocks() {
		$converter = new ImportHtmlBlockConverter();
		$summary   = array();

		$markup = $converter->convert(
			'<details id="faq-one" class="alignfull" name="faq" open><summary>Can I import <strong>HTML</strong> FAQs?</summary><p>Yes, with structured content.</p><ul><li>Lists stay editable.</li></ul></details>'
			. '<details id="faq-two" class="alignwide" aria-expanded="true"><summary>Can ARIA expanded details stay open?</summary><p>Yes, imported disclosure state survives.</p></details>'
			. '<details id="faq-three" data-open="true"><summary>Can data-open details stay open?</summary><p>Yes, data-open is honored.</p></details>'
			. '<details id="faq-four" data-expanded="true"><summary>Can data-expanded details stay open?</summary><p>Yes, data-expanded is honored.</p></details>'
			. '<details id="faq-five" class="is-open"><summary>Can class metadata details stay open?</summary><p>Yes, imported class state is honored.</p></details>',
			$summary
		);

		$this->assertSame( 'structured', $summary['html_block_conversion'] );
		$this->assertSame( 5, $summary['html_inferred_block_count'] );
		$this->assertSame( 0, $summary['html_classic_block_count'] );
		$this->assertStringContainsString( '<!-- wp:details {"showContent":true,"anchor":"faq-one","align":"full"} -->', $markup );
		$this->assertStringContainsString( '<details class="wp-block-details alignfull" id="faq-one" name="faq" open><summary>Can I import <strong>HTML</strong> FAQs?</summary>', $markup );
		$this->assertStringContainsString( '<!-- wp:paragraph -->', $markup );
		$this->assertStringContainsString( '<p>Yes, with structured content.</p>', $markup );
		$this->assertStringContainsString( '<!-- wp:list -->', $markup );
		$this->assertStringContainsString( '<!-- wp:details {"showContent":true,"anchor":"faq-two","align":"wide"} -->', $markup );
		$this->assertStringContainsString( '<details class="wp-block-details alignwide" id="faq-two" open><summary>Can ARIA expanded details stay open?</summary>', $markup );
		$this->assertStringContainsString( '<p>Yes, imported disclosure state survives.</p>', $markup );
		$this->assertStringContainsString( '<!-- wp:details {"showContent":true,"anchor":"faq-three"} -->', $markup );
		$this->assertStringContainsString( '<details class="wp-block-details" id="faq-three" open><summary>Can data-open details stay open?</summary>', $markup );
		$this->assertStringContainsString( '<p>Yes, data-open is honored.</p>', $markup );
		$this->assertStringContainsString( '<!-- wp:details {"showContent":true,"anchor":"faq-four"} -->', $markup );
		$this->assertStringContainsString( '<details class="wp-block-details" id="faq-four" open><summary>Can data-expanded details stay open?</summary>', $markup );
		$this->assertStringContainsString( '<p>Yes, data-expanded is honored.</p>', $markup );
		$this->assertStringContainsString( '<!-- wp:details {"showContent":true,"anchor":"faq-five"} -->', $markup );
		$this->assertStringContainsString( '<details class="wp-block-details" id="faq-five" open><summary>Can class metadata details stay open?</summary>', $markup );
		$this->assertStringContainsString( '<p>Yes, imported class state is honored.</p>', $markup );
		$this->assertStringNotContainsString( '<!-- wp:freeform -->', $markup );
	}

	/**
	 * Orphan summaries with structured body nodes become native Details blocks.
	 *
	 * @return void
	 */
	public function test_orphan_summary_with_body_becomes_native_details_block() {
		$converter = new ImportHtmlBlockConverter();
		$summary   = array();

		$markup = $converter->convert(
			'<summary>Read <strong>more</strong></summary>'
			. "\n"
			. '<p>Hidden copy.</p>'
			. '<ul><li>Hidden list item.</li></ul>'
			. '<p>After details.</p>',
			$summary
		);

		$this->assertSame( 'structured', $summary['html_block_conversion'] );
		$this->assertSame( 1, $summary['html_inferred_block_count'] );
		$this->assertSame( 0, $summary['html_classic_block_count'] );
		$this->assertStringContainsString( '<!-- wp:details -->', $markup );
		$this->assertStringContainsString( '<details class="wp-block-details"><summary>Read <strong>more</strong></summary>', $markup );
		$this->assertStringContainsString( '<p>Hidden copy.</p>', $markup );
		$this->assertStringContainsString( '<!-- wp:list -->', $markup );
		$this->assertStringContainsString( '<ul><li>Hidden list item.</li></ul>', $markup );
		$this->assertStringContainsString( '<p>After details.</p>', $markup );
		$this->assertStringNotContainsString( '<!-- wp:freeform -->', $markup );

		$text_markup = $converter->convert(
			'<summary>Text details</summary>Hidden text &amp; details.<p>After text.</p>',
			$summary
		);

		$this->assertSame( 'structured', $summary['html_block_conversion'] );
		$this->assertSame( 1, $summary['html_inferred_block_count'] );
		$this->assertSame( 0, $summary['html_classic_block_count'] );
		$this->assertStringContainsString( '<details class="wp-block-details"><summary>Text details</summary>', $text_markup );
		$this->assertStringContainsString( '<p>Hidden text &amp; details.</p>', $text_markup );
		$this->assertStringContainsString( '<p>After text.</p>', $text_markup );
		$this->assertStringNotContainsString( '<!-- wp:freeform -->', $text_markup );

		$boundary_markup = $converter->convert(
			'<summary>Read more</summary><p>Hidden copy.</p><h2>Next section</h2><p>After heading.</p>',
			$summary
		);

		$this->assertSame( 'structured', $summary['html_block_conversion'] );
		$this->assertSame( 3, $summary['html_inferred_block_count'] );
		$this->assertSame( 0, $summary['html_classic_block_count'] );
		$this->assertStringContainsString( '<details class="wp-block-details"><summary>Read more</summary>', $boundary_markup );
		$this->assertStringContainsString( '<p>Hidden copy.</p>', $boundary_markup );
		$this->assertStringContainsString( '<!-- wp:heading {"level":2} -->', $boundary_markup );
		$this->assertStringContainsString( '<h2>Next section</h2>', $boundary_markup );
		$this->assertStringContainsString( '<p>After heading.</p>', $boundary_markup );

		$quote_markup = $converter->convert(
			'<summary>Quoted details</summary><blockquote><p>Hidden quote.</p></blockquote><p>After quote.</p>',
			$summary
		);

		$this->assertSame( 'structured', $summary['html_block_conversion'] );
		$this->assertSame( 1, $summary['html_inferred_block_count'] );
		$this->assertSame( 0, $summary['html_classic_block_count'] );
		$this->assertStringContainsString( '<details class="wp-block-details"><summary>Quoted details</summary>', $quote_markup );
		$this->assertStringContainsString( '<!-- wp:quote -->', $quote_markup );
		$this->assertStringContainsString( '<blockquote class="wp-block-quote"><p>Hidden quote.</p></blockquote>', $quote_markup );
		$this->assertStringContainsString( '<p>After quote.</p>', $quote_markup );
		$this->assertStringNotContainsString( '<!-- wp:freeform -->', $quote_markup );

		$preformatted_markup = $converter->convert(
			'<summary>Code details</summary><pre>hidden line</pre><p>After preformatted.</p>',
			$summary
		);

		$this->assertSame( 'structured', $summary['html_block_conversion'] );
		$this->assertSame( 1, $summary['html_inferred_block_count'] );
		$this->assertSame( 0, $summary['html_classic_block_count'] );
		$this->assertStringContainsString( '<details class="wp-block-details"><summary>Code details</summary>', $preformatted_markup );
		$this->assertStringContainsString( '<!-- wp:preformatted -->', $preformatted_markup );
		$this->assertStringContainsString( '<pre class="wp-block-preformatted">hidden line</pre>', $preformatted_markup );
		$this->assertStringContainsString( '<p>After preformatted.</p>', $preformatted_markup );
		$this->assertStringNotContainsString( '<!-- wp:freeform -->', $preformatted_markup );

		$table_markup = $converter->convert(
			'<summary>Table details</summary><table><tbody><tr><td>Hidden cell</td></tr></tbody></table><p>After table.</p>',
			$summary
		);

		$this->assertSame( 'structured', $summary['html_block_conversion'] );
		$this->assertSame( 1, $summary['html_inferred_block_count'] );
		$this->assertSame( 0, $summary['html_classic_block_count'] );
		$this->assertStringContainsString( '<details class="wp-block-details"><summary>Table details</summary>', $table_markup );
		$this->assertStringContainsString( '<!-- wp:table -->', $table_markup );
		$this->assertStringContainsString( '<figure class="wp-block-table"><table><tbody><tr><td>Hidden cell</td></tr></tbody></table></figure>', $table_markup );
		$this->assertStringContainsString( '<p>After table.</p>', $table_markup );
		$this->assertStringNotContainsString( '<!-- wp:freeform -->', $table_markup );

		$figure_markup = $converter->convert(
			'<summary>Image details</summary><figure><img src="hidden.jpg" alt="Hidden image"><figcaption>Hidden caption.</figcaption></figure><p>After image.</p>',
			$summary
		);

		$this->assertSame( 'structured', $summary['html_block_conversion'] );
		$this->assertSame( 1, $summary['html_inferred_block_count'] );
		$this->assertSame( 0, $summary['html_classic_block_count'] );
		$this->assertStringContainsString( '<details class="wp-block-details"><summary>Image details</summary>', $figure_markup );
		$this->assertStringContainsString( '<!-- wp:image -->', $figure_markup );
		$this->assertStringContainsString( '<figure class="wp-block-image"><img src="hidden.jpg" alt="Hidden image"><figcaption class="wp-element-caption">Hidden caption.</figcaption></figure>', $figure_markup );
		$this->assertStringContainsString( '<p>After image.</p>', $figure_markup );
		$this->assertStringNotContainsString( '<!-- wp:freeform -->', $figure_markup );

		$image_markup = $converter->convert(
			'<summary>Direct image details</summary><img src="direct-hidden.jpg" alt="Direct hidden image"><p>After direct image.</p>',
			$summary
		);

		$this->assertSame( 'structured', $summary['html_block_conversion'] );
		$this->assertSame( 1, $summary['html_inferred_block_count'] );
		$this->assertSame( 0, $summary['html_classic_block_count'] );
		$this->assertStringContainsString( '<details class="wp-block-details"><summary>Direct image details</summary>', $image_markup );
		$this->assertStringContainsString( '<!-- wp:image -->', $image_markup );
		$this->assertStringContainsString( '<figure class="wp-block-image"><img src="direct-hidden.jpg" alt="Direct hidden image"></figure>', $image_markup );
		$this->assertStringContainsString( '<p>After direct image.</p>', $image_markup );
		$this->assertStringNotContainsString( '<!-- wp:freeform -->', $image_markup );

		$picture_markup = $converter->convert(
			'<summary>Responsive image details</summary><picture><source srcset="hidden-large.jpg"><img src="hidden-small.jpg" alt="Responsive hidden image"></picture><p>After responsive image.</p>',
			$summary
		);

		$this->assertSame( 'structured', $summary['html_block_conversion'] );
		$this->assertSame( 1, $summary['html_inferred_block_count'] );
		$this->assertSame( 0, $summary['html_classic_block_count'] );
		$this->assertStringContainsString( '<details class="wp-block-details"><summary>Responsive image details</summary>', $picture_markup );
		$this->assertStringContainsString( '<!-- wp:image -->', $picture_markup );
		$this->assertStringContainsString( '<figure class="wp-block-image"><picture><source srcset="hidden-large.jpg"><img src="hidden-small.jpg" alt="Responsive hidden image"></picture></figure>', $picture_markup );
		$this->assertStringContainsString( '<p>After responsive image.</p>', $picture_markup );
		$this->assertStringNotContainsString( '<!-- wp:freeform -->', $picture_markup );

		$video_markup = $converter->convert(
			'<summary>Video details</summary><video controls src="hidden-video.mp4"></video><p>After video.</p>',
			$summary
		);

		$this->assertSame( 'structured', $summary['html_block_conversion'] );
		$this->assertSame( 1, $summary['html_inferred_block_count'] );
		$this->assertSame( 0, $summary['html_classic_block_count'] );
		$this->assertStringContainsString( '<details class="wp-block-details"><summary>Video details</summary>', $video_markup );
		$this->assertStringContainsString( '<!-- wp:video {"src":"hidden-video.mp4","controls":true} -->', $video_markup );
		$this->assertStringContainsString( '<figure class="wp-block-video"><video controls src="hidden-video.mp4"></video></figure>', $video_markup );
		$this->assertStringContainsString( '<p>After video.</p>', $video_markup );
		$this->assertStringNotContainsString( '<!-- wp:freeform -->', $video_markup );

		$audio_markup = $converter->convert(
			'<summary>Audio details</summary><audio controls src="hidden-audio.mp3"></audio><p>After audio.</p>',
			$summary
		);

		$this->assertSame( 'structured', $summary['html_block_conversion'] );
		$this->assertSame( 1, $summary['html_inferred_block_count'] );
		$this->assertSame( 0, $summary['html_classic_block_count'] );
		$this->assertStringContainsString( '<details class="wp-block-details"><summary>Audio details</summary>', $audio_markup );
		$this->assertStringContainsString( '<!-- wp:audio {"src":"hidden-audio.mp3","controls":true} -->', $audio_markup );
		$this->assertStringContainsString( '<figure class="wp-block-audio"><audio controls src="hidden-audio.mp3"></audio></figure>', $audio_markup );
		$this->assertStringContainsString( '<p>After audio.</p>', $audio_markup );
		$this->assertStringNotContainsString( '<!-- wp:freeform -->', $audio_markup );

		$file_link_markup = $converter->convert(
			'<summary>Download details</summary><a id="details-file" href="/files/hidden.pdf" download>Hidden PDF</a><p>After file.</p>',
			$summary
		);

		$this->assertSame( 'structured', $summary['html_block_conversion'] );
		$this->assertSame( 1, $summary['html_inferred_block_count'] );
		$this->assertSame( 0, $summary['html_classic_block_count'] );
		$this->assertStringContainsString( '<details class="wp-block-details"><summary>Download details</summary>', $file_link_markup );
		$this->assertStringContainsString( '<!-- wp:file {"href":"\/files\/hidden.pdf","textLinkHref":"\/files\/hidden.pdf","fileName":"hidden.pdf","anchor":"details-file"} -->', $file_link_markup );
		$this->assertStringContainsString( '<div class="wp-block-file" id="details-file"><a href="/files/hidden.pdf">Hidden PDF</a><a href="/files/hidden.pdf" class="wp-block-file__button wp-element-button" download>Download</a></div>', $file_link_markup );
		$this->assertStringContainsString( '<p>After file.</p>', $file_link_markup );
		$this->assertStringNotContainsString( '<!-- wp:freeform -->', $file_link_markup );

		$embed_markup = $converter->convert(
			'<summary>Embed details</summary><iframe src="https://www.youtube.com/embed/dQw4w9WgXcQ"></iframe><p>After embed.</p>',
			$summary
		);

		$this->assertSame( 'structured', $summary['html_block_conversion'] );
		$this->assertSame( 1, $summary['html_inferred_block_count'] );
		$this->assertSame( 0, $summary['html_classic_block_count'] );
		$this->assertStringContainsString( '<details class="wp-block-details"><summary>Embed details</summary>', $embed_markup );
		$this->assertStringContainsString( '<!-- wp:embed {"url":"https:\/\/www.youtube.com\/watch?v=dQw4w9WgXcQ","type":"video","providerNameSlug":"youtube","responsive":true} -->', $embed_markup );
		$this->assertStringContainsString( '<div class="wp-block-embed__wrapper">https://www.youtube.com/watch?v=dQw4w9WgXcQ</div>', $embed_markup );
		$this->assertStringContainsString( '<p>After embed.</p>', $embed_markup );
		$this->assertStringNotContainsString( '<!-- wp:freeform -->', $embed_markup );

		$separator_markup = $converter->convert(
			'<summary>Separator details</summary><hr class="is-style-dots"><p>After separator.</p>',
			$summary
		);

		$this->assertSame( 'structured', $summary['html_block_conversion'] );
		$this->assertSame( 1, $summary['html_inferred_block_count'] );
		$this->assertSame( 0, $summary['html_classic_block_count'] );
		$this->assertStringContainsString( '<details class="wp-block-details"><summary>Separator details</summary>', $separator_markup );
		$this->assertStringContainsString( '<!-- wp:separator {"className":"is-style-dots"} -->', $separator_markup );
		$this->assertStringContainsString( '<hr class="wp-block-separator has-alpha-channel-opacity is-style-dots"/>', $separator_markup );
		$this->assertStringContainsString( '<p>After separator.</p>', $separator_markup );
		$this->assertStringNotContainsString( '<!-- wp:freeform -->', $separator_markup );

		$code_markup = $converter->convert(
			'<summary>Code sample details</summary><code>wp importer tick</code><p>After code.</p>',
			$summary
		);

		$this->assertSame( 'structured', $summary['html_block_conversion'] );
		$this->assertSame( 1, $summary['html_inferred_block_count'] );
		$this->assertSame( 0, $summary['html_classic_block_count'] );
		$this->assertStringContainsString( '<details class="wp-block-details"><summary>Code sample details</summary>', $code_markup );
		$this->assertStringContainsString( '<!-- wp:code -->', $code_markup );
		$this->assertStringContainsString( '<pre class="wp-block-code"><code>wp importer tick</code></pre>', $code_markup );
		$this->assertStringContainsString( '<p>After code.</p>', $code_markup );
		$this->assertStringNotContainsString( '<!-- wp:freeform -->', $code_markup );

		$address_markup = $converter->convert(
			'<summary>Contact details</summary><address>123 Legacy Street<br><a href="mailto:test@example.com">test@example.com</a></address><p>After address.</p>',
			$summary
		);

		$this->assertSame( 'structured', $summary['html_block_conversion'] );
		$this->assertSame( 1, $summary['html_inferred_block_count'] );
		$this->assertSame( 0, $summary['html_classic_block_count'] );
		$this->assertStringContainsString( '<details class="wp-block-details"><summary>Contact details</summary>', $address_markup );
		$this->assertStringContainsString( '<p>123 Legacy Street<br><a href="mailto:test@example.com">test@example.com</a></p>', $address_markup );
		$this->assertStringContainsString( '<p>After address.</p>', $address_markup );
		$this->assertStringNotContainsString( '<address', $address_markup );
		$this->assertStringNotContainsString( '<!-- wp:freeform -->', $address_markup );

		$center_markup = $converter->convert(
			'<summary>Centered details</summary><center><p>Centered copy.</p></center><p>After center.</p>',
			$summary
		);

		$this->assertSame( 'structured', $summary['html_block_conversion'] );
		$this->assertSame( 1, $summary['html_inferred_block_count'] );
		$this->assertSame( 0, $summary['html_classic_block_count'] );
		$this->assertStringContainsString( '<details class="wp-block-details"><summary>Centered details</summary>', $center_markup );
		$this->assertStringContainsString( '<!-- wp:paragraph {"align":"center"} -->', $center_markup );
		$this->assertStringContainsString( '<p class="has-text-align-center">Centered copy.</p>', $center_markup );
		$this->assertStringContainsString( '<p>After center.</p>', $center_markup );
		$this->assertStringNotContainsString( '<center', $center_markup );
		$this->assertStringNotContainsString( '<!-- wp:freeform -->', $center_markup );

		$nested_details_markup = $converter->convert(
			'<summary>Outer details</summary><details open><summary>Nested details</summary><p>Nested copy.</p></details><p>After nested details.</p>',
			$summary
		);

		$this->assertSame( 'structured', $summary['html_block_conversion'] );
		$this->assertSame( 1, $summary['html_inferred_block_count'] );
		$this->assertSame( 0, $summary['html_classic_block_count'] );
		$this->assertStringContainsString( '<details class="wp-block-details"><summary>Outer details</summary>', $nested_details_markup );
		$this->assertStringContainsString( '<!-- wp:details {"showContent":true} -->', $nested_details_markup );
		$this->assertStringContainsString( '<details class="wp-block-details" open><summary>Nested details</summary>', $nested_details_markup );
		$this->assertStringContainsString( '<p>Nested copy.</p>', $nested_details_markup );
		$this->assertStringContainsString( '<p>After nested details.</p>', $nested_details_markup );
		$this->assertStringNotContainsString( '<!-- wp:freeform -->', $nested_details_markup );

		$definition_list_markup = $converter->convert(
			'<summary>FAQ details</summary><dl class="faq-list"><dt id="details-question">Can imported FAQs stay nested?</dt><dd>Yes, nested definition answers stay editable.</dd></dl><p>After FAQ.</p>',
			$summary
		);

		$this->assertSame( 'structured', $summary['html_block_conversion'] );
		$this->assertSame( 1, $summary['html_inferred_block_count'] );
		$this->assertSame( 0, $summary['html_classic_block_count'] );
		$this->assertStringContainsString( '<details class="wp-block-details"><summary>FAQ details</summary>', $definition_list_markup );
		$this->assertStringContainsString( '<!-- wp:details {"anchor":"details-question"} -->', $definition_list_markup );
		$this->assertStringContainsString( '<details class="wp-block-details" id="details-question"><summary>Can imported FAQs stay nested?</summary>', $definition_list_markup );
		$this->assertStringContainsString( '<p>Yes, nested definition answers stay editable.</p>', $definition_list_markup );
		$this->assertStringContainsString( '<p>After FAQ.</p>', $definition_list_markup );
		$this->assertStringNotContainsString( '<dl', $definition_list_markup );
		$this->assertStringNotContainsString( '<!-- wp:freeform -->', $definition_list_markup );

		$noscript_markup = $converter->convert(
			'<summary>Fallback details</summary><noscript><p>Fallback copy.</p><ul><li>Fallback item.</li></ul></noscript><p>After fallback.</p>',
			$summary
		);

		$this->assertSame( 'structured', $summary['html_block_conversion'] );
		$this->assertSame( 1, $summary['html_inferred_block_count'] );
		$this->assertSame( 0, $summary['html_classic_block_count'] );
		$this->assertStringContainsString( '<details class="wp-block-details"><summary>Fallback details</summary>', $noscript_markup );
		$this->assertStringContainsString( '<p>Fallback copy.</p>', $noscript_markup );
		$this->assertStringContainsString( '<ul><li>Fallback item.</li></ul>', $noscript_markup );
		$this->assertStringContainsString( '<p>After fallback.</p>', $noscript_markup );
		$this->assertStringNotContainsString( '<noscript', $noscript_markup );
		$this->assertStringNotContainsString( '<!-- wp:freeform -->', $noscript_markup );

		$noframes_markup = $converter->convert(
			'<summary>Frame fallback details</summary><noframes><p>Frame fallback copy.</p><ul><li>Frame fallback item.</li></ul></noframes><p>After frame fallback.</p>',
			$summary
		);

		$this->assertSame( 'structured', $summary['html_block_conversion'] );
		$this->assertSame( 1, $summary['html_inferred_block_count'] );
		$this->assertSame( 0, $summary['html_classic_block_count'] );
		$this->assertStringContainsString( '<details class="wp-block-details"><summary>Frame fallback details</summary>', $noframes_markup );
		$this->assertStringContainsString( '<p>Frame fallback copy.</p>', $noframes_markup );
		$this->assertStringContainsString( '<ul><li>Frame fallback item.</li></ul>', $noframes_markup );
		$this->assertStringContainsString( '<p>After frame fallback.</p>', $noframes_markup );
		$this->assertStringNotContainsString( '<noframes', $noframes_markup );
		$this->assertStringNotContainsString( '<!-- wp:freeform -->', $noframes_markup );

		$noembed_markup = $converter->convert(
			'<summary>Embed fallback details</summary><noembed><p>Embed fallback copy.</p><ul><li>Embed fallback item.</li></ul></noembed><p>After embed fallback.</p>',
			$summary
		);

		$this->assertSame( 'structured', $summary['html_block_conversion'] );
		$this->assertSame( 1, $summary['html_inferred_block_count'] );
		$this->assertSame( 0, $summary['html_classic_block_count'] );
		$this->assertStringContainsString( '<details class="wp-block-details"><summary>Embed fallback details</summary>', $noembed_markup );
		$this->assertStringContainsString( '<p>Embed fallback copy.</p>', $noembed_markup );
		$this->assertStringContainsString( '<ul><li>Embed fallback item.</li></ul>', $noembed_markup );
		$this->assertStringContainsString( '<p>After embed fallback.</p>', $noembed_markup );
		$this->assertStringNotContainsString( '<noembed', $noembed_markup );
		$this->assertStringNotContainsString( '<!-- wp:freeform -->', $noembed_markup );

		$marquee_markup = $converter->convert(
			'<summary>Moving details</summary><marquee><p>Moving copy.</p><ul><li>Moving item.</li></ul></marquee><p>After marquee.</p>',
			$summary
		);

		$this->assertSame( 'structured', $summary['html_block_conversion'] );
		$this->assertSame( 1, $summary['html_inferred_block_count'] );
		$this->assertSame( 0, $summary['html_classic_block_count'] );
		$this->assertStringContainsString( '<details class="wp-block-details"><summary>Moving details</summary>', $marquee_markup );
		$this->assertStringContainsString( '<p>Moving copy.</p>', $marquee_markup );
		$this->assertStringContainsString( '<ul><li>Moving item.</li></ul>', $marquee_markup );
		$this->assertStringContainsString( '<p>After marquee.</p>', $marquee_markup );
		$this->assertStringNotContainsString( '<marquee', $marquee_markup );
		$this->assertStringNotContainsString( '<!-- wp:freeform -->', $marquee_markup );

		$fieldset_markup = $converter->convert(
			'<summary>Grouped fieldset details</summary><fieldset><legend id="details-group">Details group</legend><p>Grouped fieldset copy.</p></fieldset><p>After fieldset.</p>',
			$summary
		);

		$this->assertSame( 'structured', $summary['html_block_conversion'] );
		$this->assertSame( 1, $summary['html_inferred_block_count'] );
		$this->assertSame( 0, $summary['html_classic_block_count'] );
		$this->assertStringContainsString( '<details class="wp-block-details"><summary>Grouped fieldset details</summary>', $fieldset_markup );
		$this->assertStringContainsString( '<!-- wp:paragraph {"anchor":"details-group"} -->', $fieldset_markup );
		$this->assertStringContainsString( '<p id="details-group">Details group</p>', $fieldset_markup );
		$this->assertStringContainsString( '<p>Grouped fieldset copy.</p>', $fieldset_markup );
		$this->assertStringContainsString( '<p>After fieldset.</p>', $fieldset_markup );
		$this->assertStringNotContainsString( '<fieldset', $fieldset_markup );
		$this->assertStringNotContainsString( '<legend', $fieldset_markup );
		$this->assertStringNotContainsString( '<!-- wp:freeform -->', $fieldset_markup );

		$dialog_markup = $converter->convert(
			'<summary>Dialog details</summary><dialog open><p>Dialog copy.</p><ul><li>Dialog item.</li></ul></dialog><p>After dialog.</p>',
			$summary
		);

		$this->assertSame( 'structured', $summary['html_block_conversion'] );
		$this->assertSame( 1, $summary['html_inferred_block_count'] );
		$this->assertSame( 0, $summary['html_classic_block_count'] );
		$this->assertStringContainsString( '<details class="wp-block-details"><summary>Dialog details</summary>', $dialog_markup );
		$this->assertStringContainsString( '<p>Dialog copy.</p>', $dialog_markup );
		$this->assertStringContainsString( '<ul><li>Dialog item.</li></ul>', $dialog_markup );
		$this->assertStringContainsString( '<p>After dialog.</p>', $dialog_markup );
		$this->assertStringNotContainsString( '<dialog', $dialog_markup );
		$this->assertStringNotContainsString( '<!-- wp:freeform -->', $dialog_markup );

		$object_markup = $converter->convert(
			'<summary>Object fallback details</summary><object><p>Object fallback copy.</p><ul><li>Object fallback item.</li></ul></object><p>After object fallback.</p>',
			$summary
		);

		$this->assertSame( 'structured', $summary['html_block_conversion'] );
		$this->assertSame( 1, $summary['html_inferred_block_count'] );
		$this->assertSame( 0, $summary['html_classic_block_count'] );
		$this->assertStringContainsString( '<details class="wp-block-details"><summary>Object fallback details</summary>', $object_markup );
		$this->assertStringContainsString( '<p>Object fallback copy.</p>', $object_markup );
		$this->assertStringContainsString( '<ul><li>Object fallback item.</li></ul>', $object_markup );
		$this->assertStringContainsString( '<p>After object fallback.</p>', $object_markup );
		$this->assertStringNotContainsString( '<object', $object_markup );
		$this->assertStringNotContainsString( '<!-- wp:freeform -->', $object_markup );

		$applet_markup = $converter->convert(
			'<summary>Applet fallback details</summary><applet code="Legacy.class"><param name="movie" value="legacy"><p>Applet fallback copy.</p><ul><li>Applet fallback item.</li></ul></applet><p>After applet fallback.</p>',
			$summary
		);

		$this->assertSame( 'structured', $summary['html_block_conversion'] );
		$this->assertSame( 1, $summary['html_inferred_block_count'] );
		$this->assertSame( 0, $summary['html_classic_block_count'] );
		$this->assertStringContainsString( '<details class="wp-block-details"><summary>Applet fallback details</summary>', $applet_markup );
		$this->assertStringContainsString( '<p>Applet fallback copy.</p>', $applet_markup );
		$this->assertStringContainsString( '<ul><li>Applet fallback item.</li></ul>', $applet_markup );
		$this->assertStringContainsString( '<p>After applet fallback.</p>', $applet_markup );
		$this->assertStringNotContainsString( '<applet', $applet_markup );
		$this->assertStringNotContainsString( '<param', $applet_markup );
		$this->assertStringNotContainsString( '<!-- wp:freeform -->', $applet_markup );

		$obsolete_preformatted_markup = $converter->convert(
			'<summary>Legacy preformatted details</summary><listing id="details-listing">legacy line one' . "\n" . 'legacy line two</listing><p>After legacy preformatted.</p>',
			$summary
		);

		$this->assertSame( 'structured', $summary['html_block_conversion'] );
		$this->assertSame( 1, $summary['html_inferred_block_count'] );
		$this->assertSame( 0, $summary['html_classic_block_count'] );
		$this->assertStringContainsString( '<details class="wp-block-details"><summary>Legacy preformatted details</summary>', $obsolete_preformatted_markup );
		$this->assertStringContainsString( '<!-- wp:preformatted {"anchor":"details-listing"} -->', $obsolete_preformatted_markup );
		$this->assertStringContainsString( '<pre class="wp-block-preformatted" id="details-listing">legacy line one' . "\n" . 'legacy line two</pre>', $obsolete_preformatted_markup );
		$this->assertStringContainsString( '<p>After legacy preformatted.</p>', $obsolete_preformatted_markup );
		$this->assertStringNotContainsString( '<listing', $obsolete_preformatted_markup );
		$this->assertStringNotContainsString( '<!-- wp:freeform -->', $obsolete_preformatted_markup );

		$list_item_markup = $converter->convert(
			'<summary>List item details</summary><li>First hidden item</li><li>Second hidden item</li><p>After list items.</p>',
			$summary
		);

		$this->assertSame( 'structured', $summary['html_block_conversion'] );
		$this->assertSame( 1, $summary['html_inferred_block_count'] );
		$this->assertSame( 0, $summary['html_classic_block_count'] );
		$this->assertStringContainsString( '<details class="wp-block-details"><summary>List item details</summary>', $list_item_markup );
		$this->assertStringContainsString( '<!-- wp:list -->', $list_item_markup );
		$this->assertStringContainsString( '<ul><li>First hidden item</li><li>Second hidden item</li></ul>', $list_item_markup );
		$this->assertStringContainsString( '<p>After list items.</p>', $list_item_markup );
		$this->assertStringNotContainsString( '<!-- wp:freeform -->', $list_item_markup );

		$menu_markup = $converter->convert(
			'<summary>Menu details</summary><menu id="details-menu"><li>Export content</li><li>Review media</li></menu><p>After menu.</p>',
			$summary
		);

		$this->assertSame( 'structured', $summary['html_block_conversion'] );
		$this->assertSame( 1, $summary['html_inferred_block_count'] );
		$this->assertSame( 0, $summary['html_classic_block_count'] );
		$this->assertStringContainsString( '<details class="wp-block-details"><summary>Menu details</summary>', $menu_markup );
		$this->assertStringContainsString( '<!-- wp:list {"anchor":"details-menu"} -->', $menu_markup );
		$this->assertStringContainsString( '<ul id="details-menu"><li>Export content</li><li>Review media</li></ul>', $menu_markup );
		$this->assertStringContainsString( '<p>After menu.</p>', $menu_markup );
		$this->assertStringNotContainsString( '<menu', $menu_markup );
		$this->assertStringNotContainsString( '<!-- wp:freeform -->', $menu_markup );

		$section_markup = $converter->convert(
			'<summary>Section details</summary><section><p>Section copy.</p><ul><li>Section item.</li></ul></section><p>After section.</p>',
			$summary
		);

		$this->assertSame( 'structured', $summary['html_block_conversion'] );
		$this->assertSame( 1, $summary['html_inferred_block_count'] );
		$this->assertSame( 0, $summary['html_classic_block_count'] );
		$this->assertStringContainsString( '<details class="wp-block-details"><summary>Section details</summary>', $section_markup );
		$this->assertStringContainsString( '<p>Section copy.</p>', $section_markup );
		$this->assertStringContainsString( '<ul><li>Section item.</li></ul>', $section_markup );
		$this->assertStringContainsString( '<p>After section.</p>', $section_markup );
		$this->assertStringNotContainsString( '<section', $section_markup );
		$this->assertStringNotContainsString( '<!-- wp:freeform -->', $section_markup );

		$article_markup = $converter->convert(
			'<summary>Article details</summary><article><p>Article copy.</p><figure><img src="article.jpg" alt="Article image"></figure></article><p>After article.</p>',
			$summary
		);

		$this->assertSame( 'structured', $summary['html_block_conversion'] );
		$this->assertSame( 1, $summary['html_inferred_block_count'] );
		$this->assertSame( 0, $summary['html_classic_block_count'] );
		$this->assertStringContainsString( '<details class="wp-block-details"><summary>Article details</summary>', $article_markup );
		$this->assertStringContainsString( '<p>Article copy.</p>', $article_markup );
		$this->assertStringContainsString( '<figure class="wp-block-image"><img src="article.jpg" alt="Article image"></figure>', $article_markup );
		$this->assertStringContainsString( '<p>After article.</p>', $article_markup );
		$this->assertStringNotContainsString( '<article', $article_markup );
		$this->assertStringNotContainsString( '<!-- wp:freeform -->', $article_markup );

		$aside_markup = $converter->convert(
			'<summary>Aside details</summary><aside><p>Aside copy.</p><blockquote><p>Aside quote.</p></blockquote></aside><p>After aside.</p>',
			$summary
		);

		$this->assertSame( 'structured', $summary['html_block_conversion'] );
		$this->assertSame( 1, $summary['html_inferred_block_count'] );
		$this->assertSame( 0, $summary['html_classic_block_count'] );
		$this->assertStringContainsString( '<details class="wp-block-details"><summary>Aside details</summary>', $aside_markup );
		$this->assertStringContainsString( '<p>Aside copy.</p>', $aside_markup );
		$this->assertStringContainsString( '<blockquote class="wp-block-quote"><p>Aside quote.</p></blockquote>', $aside_markup );
		$this->assertStringContainsString( '<p>After aside.</p>', $aside_markup );
		$this->assertStringNotContainsString( '<aside', $aside_markup );
		$this->assertStringNotContainsString( '<!-- wp:freeform -->', $aside_markup );

		$header_markup = $converter->convert(
			'<summary>Header details</summary><header><p>Header copy.</p><ul><li>Header item.</li></ul></header><p>After header.</p>',
			$summary
		);

		$this->assertSame( 'structured', $summary['html_block_conversion'] );
		$this->assertSame( 1, $summary['html_inferred_block_count'] );
		$this->assertSame( 0, $summary['html_classic_block_count'] );
		$this->assertStringContainsString( '<details class="wp-block-details"><summary>Header details</summary>', $header_markup );
		$this->assertStringContainsString( '<p>Header copy.</p>', $header_markup );
		$this->assertStringContainsString( '<ul><li>Header item.</li></ul>', $header_markup );
		$this->assertStringContainsString( '<p>After header.</p>', $header_markup );
		$this->assertStringNotContainsString( '<header', $header_markup );
		$this->assertStringNotContainsString( '<!-- wp:freeform -->', $header_markup );

		$footer_markup = $converter->convert(
			'<summary>Footer details</summary><footer><p>Footer copy.</p><ul><li>Footer item.</li></ul></footer><p>After footer.</p>',
			$summary
		);

		$this->assertSame( 'structured', $summary['html_block_conversion'] );
		$this->assertSame( 1, $summary['html_inferred_block_count'] );
		$this->assertSame( 0, $summary['html_classic_block_count'] );
		$this->assertStringContainsString( '<details class="wp-block-details"><summary>Footer details</summary>', $footer_markup );
		$this->assertStringContainsString( '<p>Footer copy.</p>', $footer_markup );
		$this->assertStringContainsString( '<ul><li>Footer item.</li></ul>', $footer_markup );
		$this->assertStringContainsString( '<p>After footer.</p>', $footer_markup );
		$this->assertStringNotContainsString( '<footer', $footer_markup );
		$this->assertStringNotContainsString( '<!-- wp:freeform -->', $footer_markup );

		$main_markup = $converter->convert(
			'<summary>Main details</summary><main><p>Main copy.</p><ul><li>Main item.</li></ul></main><p>After main.</p>',
			$summary
		);

		$this->assertSame( 'structured', $summary['html_block_conversion'] );
		$this->assertSame( 1, $summary['html_inferred_block_count'] );
		$this->assertSame( 0, $summary['html_classic_block_count'] );
		$this->assertStringContainsString( '<details class="wp-block-details"><summary>Main details</summary>', $main_markup );
		$this->assertStringContainsString( '<p>Main copy.</p>', $main_markup );
		$this->assertStringContainsString( '<ul><li>Main item.</li></ul>', $main_markup );
		$this->assertStringContainsString( '<p>After main.</p>', $main_markup );
		$this->assertStringNotContainsString( '<main', $main_markup );
		$this->assertStringNotContainsString( '<!-- wp:freeform -->', $main_markup );

		$navigation_markup = $converter->convert(
			'<summary>Navigation details</summary><nav id="details-nav" aria-label="Details links"><ul><li><a href="/one">One</a></li><li><a href="/two">Two</a></li></ul></nav><p>After navigation.</p>',
			$summary
		);

		$this->assertSame( 'structured', $summary['html_block_conversion'] );
		$this->assertSame( 1, $summary['html_inferred_block_count'] );
		$this->assertSame( 0, $summary['html_classic_block_count'] );
		$this->assertStringContainsString( '<details class="wp-block-details"><summary>Navigation details</summary>', $navigation_markup );
		$this->assertStringContainsString( '<!-- wp:navigation {"overlayMenu":"never","anchor":"details-nav","ariaLabel":"Details links"} -->', $navigation_markup );
		$this->assertStringContainsString( '<!-- wp:navigation-link {"label":"One","url":"\/one","kind":"custom","isTopLevelLink":true} /-->', $navigation_markup );
		$this->assertStringContainsString( '<!-- wp:navigation-link {"label":"Two","url":"\/two","kind":"custom","isTopLevelLink":true} /-->', $navigation_markup );
		$this->assertStringContainsString( '<p>After navigation.</p>', $navigation_markup );
		$this->assertStringNotContainsString( '<nav', $navigation_markup );
		$this->assertStringNotContainsString( '<!-- wp:freeform -->', $navigation_markup );

		$div_markup = $converter->convert(
			'<summary>Div details</summary><div><p>Div copy.</p><ul><li>Div item.</li></ul></div><p>After div.</p>',
			$summary
		);

		$this->assertSame( 'structured', $summary['html_block_conversion'] );
		$this->assertSame( 1, $summary['html_inferred_block_count'] );
		$this->assertSame( 0, $summary['html_classic_block_count'] );
		$this->assertStringContainsString( '<details class="wp-block-details"><summary>Div details</summary>', $div_markup );
		$this->assertStringContainsString( '<p>Div copy.</p>', $div_markup );
		$this->assertStringContainsString( '<ul><li>Div item.</li></ul>', $div_markup );
		$this->assertStringContainsString( '<p>After div.</p>', $div_markup );
		$this->assertStringNotContainsString( '<div', $div_markup );
		$this->assertStringNotContainsString( '<!-- wp:freeform -->', $div_markup );

		$search_markup = $converter->convert(
			'<summary>Search details</summary><form id="details-search" role="search" method="get"><label for="details-search-field">Search docs</label><input id="details-search-field" type="search" name="s" placeholder="Docs"><button type="submit">Find</button></form><p>After search.</p>',
			$summary
		);

		$this->assertSame( 'structured', $summary['html_block_conversion'] );
		$this->assertSame( 1, $summary['html_inferred_block_count'] );
		$this->assertSame( 0, $summary['html_classic_block_count'] );
		$this->assertStringContainsString( '<details class="wp-block-details"><summary>Search details</summary>', $search_markup );
		$this->assertStringContainsString( '<!-- wp:search {"label":"Search docs","buttonText":"Find","placeholder":"Docs","anchor":"details-search"} /-->', $search_markup );
		$this->assertStringContainsString( '<p>After search.</p>', $search_markup );
		$this->assertStringNotContainsString( '<form', $search_markup );
		$this->assertStringNotContainsString( '<!-- wp:freeform -->', $search_markup );

		$font_markup = $converter->convert(
			'<summary>Legacy font details</summary><font color="red">Legacy <strong>font</strong> copy.</font><p>After font.</p>',
			$summary
		);

		$this->assertSame( 'structured', $summary['html_block_conversion'] );
		$this->assertSame( 1, $summary['html_inferred_block_count'] );
		$this->assertSame( 0, $summary['html_classic_block_count'] );
		$this->assertStringContainsString( '<details class="wp-block-details"><summary>Legacy font details</summary>', $font_markup );
		$this->assertStringContainsString( '<p>Legacy <strong>font</strong> copy.</p>', $font_markup );
		$this->assertStringContainsString( '<p>After font.</p>', $font_markup );
		$this->assertStringNotContainsString( '<font', $font_markup );
		$this->assertStringNotContainsString( '<!-- wp:freeform -->', $font_markup );

		$abbreviation_markup = $converter->convert(
			'<summary>Abbreviation details</summary><abbr title="World Health Organization">WHO</abbr><p>After abbreviation.</p>',
			$summary
		);

		$this->assertSame( 'structured', $summary['html_block_conversion'] );
		$this->assertSame( 1, $summary['html_inferred_block_count'] );
		$this->assertSame( 0, $summary['html_classic_block_count'] );
		$this->assertStringContainsString( '<details class="wp-block-details"><summary>Abbreviation details</summary>', $abbreviation_markup );
		$this->assertStringContainsString( '<p><abbr title="World Health Organization">WHO</abbr></p>', $abbreviation_markup );
		$this->assertStringContainsString( '<p>After abbreviation.</p>', $abbreviation_markup );
		$this->assertStringNotContainsString( '<!-- wp:freeform -->', $abbreviation_markup );

		$citation_markup = $converter->convert(
			'<summary>Citation details</summary><cite>Imported source</cite><p>After citation.</p>',
			$summary
		);

		$this->assertSame( 'structured', $summary['html_block_conversion'] );
		$this->assertSame( 1, $summary['html_inferred_block_count'] );
		$this->assertSame( 0, $summary['html_classic_block_count'] );
		$this->assertStringContainsString( '<details class="wp-block-details"><summary>Citation details</summary>', $citation_markup );
		$this->assertStringContainsString( '<p><cite>Imported source</cite></p>', $citation_markup );
		$this->assertStringContainsString( '<p>After citation.</p>', $citation_markup );
		$this->assertStringNotContainsString( '<!-- wp:freeform -->', $citation_markup );

		$inline_markup = $converter->convert(
			'<summary>Inline details</summary><kbd>Ctrl+C</kbd><mark>Highlighted note</mark><p>After inline.</p>',
			$summary
		);

		$this->assertSame( 'structured', $summary['html_block_conversion'] );
		$this->assertSame( 1, $summary['html_inferred_block_count'] );
		$this->assertSame( 0, $summary['html_classic_block_count'] );
		$this->assertStringContainsString( '<details class="wp-block-details"><summary>Inline details</summary>', $inline_markup );
		$this->assertStringContainsString( '<p><kbd>Ctrl+C</kbd></p>', $inline_markup );
		$this->assertStringContainsString( '<p><mark>Highlighted note</mark></p>', $inline_markup );
		$this->assertStringContainsString( '<p>After inline.</p>', $inline_markup );
		$this->assertStringNotContainsString( '<!-- wp:freeform -->', $inline_markup );

		$obsolete_inline_markup = $converter->convert(
			'<summary>Obsolete inline details</summary><big>Large <tt>terminal</tt> copy.</big><p>After obsolete inline.</p>',
			$summary
		);

		$this->assertSame( 'structured', $summary['html_block_conversion'] );
		$this->assertSame( 1, $summary['html_inferred_block_count'] );
		$this->assertSame( 0, $summary['html_classic_block_count'] );
		$this->assertStringContainsString( '<details class="wp-block-details"><summary>Obsolete inline details</summary>', $obsolete_inline_markup );
		$this->assertStringContainsString( '<p><big>Large <tt>terminal</tt> copy.</big></p>', $obsolete_inline_markup );
		$this->assertStringContainsString( '<p>After obsolete inline.</p>', $obsolete_inline_markup );
		$this->assertStringNotContainsString( '<!-- wp:freeform -->', $obsolete_inline_markup );

		$empty_markup = $converter->convert( '<summary></summary><p>Hidden copy.</p>', $summary );

		$this->assertSame( 'mixed', $summary['html_block_conversion'] );
		$this->assertSame( 1, $summary['html_inferred_block_count'] );
		$this->assertSame( 1, $summary['html_classic_block_count'] );
		$this->assertStringContainsString( '<!-- wp:freeform -->', $empty_markup );
		$this->assertStringContainsString( '<summary></summary>', $empty_markup );

		$opaque_markup = $converter->convert( '<summary>Read more</summary><custom-panel>Opaque</custom-panel>', $summary );

		$this->assertSame( 'mixed', $summary['html_block_conversion'] );
		$this->assertSame( 0, $summary['html_inferred_block_count'] );
		$this->assertSame( 2, $summary['html_classic_block_count'] );
		$this->assertStringContainsString( '<summary>Read more</summary>', $opaque_markup );
		$this->assertStringContainsString( '<custom-panel>Opaque</custom-panel>', $opaque_markup );

		$opaque_after_body_markup = $converter->convert( '<summary>Read more</summary><p>Hidden copy.</p><custom-panel>Opaque</custom-panel>', $summary );

		$this->assertSame( 'mixed', $summary['html_block_conversion'] );
		$this->assertSame( 1, $summary['html_inferred_block_count'] );
		$this->assertSame( 1, $summary['html_classic_block_count'] );
		$this->assertStringContainsString( '<details class="wp-block-details"><summary>Read more</summary>', $opaque_after_body_markup );
		$this->assertStringContainsString( '<p>Hidden copy.</p>', $opaque_after_body_markup );
		$this->assertStringContainsString( '<custom-panel>Opaque</custom-panel>', $opaque_after_body_markup );
	}

	/**
	 * Obvious FAQ/Q&A definition lists become native Details blocks.
	 *
	 * @return void
	 */
	public function test_faq_definition_lists_become_native_details_blocks() {
		$converter = new ImportHtmlBlockConverter();
		$summary   = array();

		$markup = $converter->convert(
			'<dl class="faq-list alignwide">'
			. '<dt id="question-one">Can definition lists become Details?</dt>'
			. '<dd>Yes, with <strong>inline answers</strong>.</dd>'
			. '<br>'
			. '<dt class="open alignfull">Can answers include block content?</dt>'
			. '<dd><p>Block answers stay editable.</p><ul><li>Lists survive.</li></ul></dd>'
			. '<dt>Can description metadata drive Details output?</dt>'
			. '<dd id="answer-metadata" class="show alignfull">Yes, description metadata is preserved.</dd>'
			. '<dt id="expanded-question" aria-expanded="true">Can ARIA expanded state open Details?</dt>'
			. '<dd>Yes, expanded FAQ metadata is preserved.</dd>'
			. '<dt>Can description ARIA state open Details?</dt>'
			. '<dd id="expanded-answer" aria-expanded="true">Yes, answer expanded metadata is preserved.</dd>'
			. '<dt id="data-open-question" data-open="true">Can data-open state open Details?</dt>'
			. '<dd>Yes, data-open FAQ metadata is preserved.</dd>'
			. '<dt>Can description data-expanded state open Details?</dt>'
			. '<dd id="data-expanded-answer" data-expanded="true">Yes, data-expanded answer metadata is preserved.</dd>'
			. '</dl>',
			$summary
		);

		$this->assertSame( 'structured', $summary['html_block_conversion'] );
		$this->assertSame( 7, $summary['html_inferred_block_count'] );
		$this->assertSame( 0, $summary['html_classic_block_count'] );
		$this->assertStringContainsString( '<!-- wp:details {"anchor":"question-one","align":"wide"} -->', $markup );
		$this->assertStringContainsString( '<details class="wp-block-details alignwide" id="question-one"><summary>Can definition lists become Details?</summary>', $markup );
		$this->assertStringContainsString( '<p>Yes, with <strong>inline answers</strong>.</p>', $markup );
		$this->assertStringContainsString( '<!-- wp:details {"showContent":true,"align":"full"} -->', $markup );
		$this->assertStringContainsString( '<details class="wp-block-details alignfull" open><summary>Can answers include block content?</summary>', $markup );
		$this->assertStringContainsString( '<p>Block answers stay editable.</p>', $markup );
		$this->assertStringContainsString( '<ul><li>Lists survive.</li></ul>', $markup );
		$this->assertStringContainsString( '<!-- wp:details {"anchor":"answer-metadata","showContent":true,"align":"full"} -->', $markup );
		$this->assertStringContainsString( '<details class="wp-block-details alignfull" id="answer-metadata" open><summary>Can description metadata drive Details output?</summary>', $markup );
		$this->assertStringContainsString( '<p>Yes, description metadata is preserved.</p>', $markup );
		$this->assertStringContainsString( '<!-- wp:details {"anchor":"expanded-question","showContent":true,"align":"wide"} -->', $markup );
		$this->assertStringContainsString( '<details class="wp-block-details alignwide" id="expanded-question" open><summary>Can ARIA expanded state open Details?</summary>', $markup );
		$this->assertStringContainsString( '<p>Yes, expanded FAQ metadata is preserved.</p>', $markup );
		$this->assertStringContainsString( '<!-- wp:details {"anchor":"expanded-answer","showContent":true,"align":"wide"} -->', $markup );
		$this->assertStringContainsString( '<details class="wp-block-details alignwide" id="expanded-answer" open><summary>Can description ARIA state open Details?</summary>', $markup );
		$this->assertStringContainsString( '<p>Yes, answer expanded metadata is preserved.</p>', $markup );
		$this->assertStringContainsString( '<!-- wp:details {"anchor":"data-open-question","showContent":true,"align":"wide"} -->', $markup );
		$this->assertStringContainsString( '<details class="wp-block-details alignwide" id="data-open-question" open><summary>Can data-open state open Details?</summary>', $markup );
		$this->assertStringContainsString( '<p>Yes, data-open FAQ metadata is preserved.</p>', $markup );
		$this->assertStringContainsString( '<!-- wp:details {"anchor":"data-expanded-answer","showContent":true,"align":"wide"} -->', $markup );
		$this->assertStringContainsString( '<details class="wp-block-details alignwide" id="data-expanded-answer" open><summary>Can description data-expanded state open Details?</summary>', $markup );
		$this->assertStringContainsString( '<p>Yes, data-expanded answer metadata is preserved.</p>', $markup );
		$this->assertStringNotContainsString( '<dl class="faq-list alignwide">', $markup );
		$this->assertStringNotContainsString( '<!-- wp:freeform -->', $markup );
		$this->assertStringNotContainsString( '<br>', $markup );
	}

	/**
	 * Ordinary definition lists are not guessed as FAQ disclosures.
	 *
	 * @return void
	 */
	public function test_plain_definition_lists_keep_classic_fallback() {
		$converter = new ImportHtmlBlockConverter();
		$summary   = array();

		$markup = $converter->convert( '<dl><dt>Term</dt><dd>Definition.</dd><dt>Another term</dt><dd>Another definition.</dd></dl>', $summary );

		$this->assertSame( 'mixed', $summary['html_block_conversion'] );
		$this->assertSame( 0, $summary['html_inferred_block_count'] );
		$this->assertSame( 1, $summary['html_classic_block_count'] );
		$this->assertStringContainsString( '<!-- wp:freeform -->', $markup );
		$this->assertStringContainsString( '<dl><dt>Term</dt><dd>Definition.</dd><dt>Another term</dt><dd>Another definition.</dd></dl>', $markup );
		$this->assertStringNotContainsString( '<!-- wp:details', $markup );
	}

	/**
	 * Orphan FAQ definition pairs become native Details blocks.
	 *
	 * @return void
	 */
	public function test_orphan_faq_definition_pairs_become_native_details_blocks() {
		$converter = new ImportHtmlBlockConverter();
		$summary   = array();

		$markup = $converter->convert(
			'<dt id="orphan-question">Can orphan FAQ pairs convert?</dt>'
			. '<dd>Yes, inline answers become paragraphs.</dd>'
			. '<dt>Can orphan answers include blocks?</dt>'
			. '<dd><p>Block answers stay editable.</p><ul><li>List answer.</li></ul></dd>'
			. '<p>After FAQ.</p>',
			$summary
		);

		$this->assertSame( 'structured', $summary['html_block_conversion'] );
		$this->assertSame( 3, $summary['html_inferred_block_count'] );
		$this->assertSame( 0, $summary['html_classic_block_count'] );
		$this->assertStringContainsString( '<!-- wp:details {"anchor":"orphan-question"} -->', $markup );
		$this->assertStringContainsString( '<details class="wp-block-details" id="orphan-question"><summary>Can orphan FAQ pairs convert?</summary>', $markup );
		$this->assertStringContainsString( '<p>Yes, inline answers become paragraphs.</p>', $markup );
		$this->assertStringContainsString( '<details class="wp-block-details"><summary>Can orphan answers include blocks?</summary>', $markup );
		$this->assertStringContainsString( '<p>Block answers stay editable.</p>', $markup );
		$this->assertStringContainsString( '<ul><li>List answer.</li></ul>', $markup );
		$this->assertStringContainsString( '<p>After FAQ.</p>', $markup );
		$this->assertStringNotContainsString( '<!-- wp:freeform -->', $markup );
	}

	/**
	 * Non-FAQ orphan definition pairs remain conservative Classic fallback.
	 *
	 * @return void
	 */
	public function test_plain_orphan_definition_pairs_keep_classic_fallback() {
		$converter = new ImportHtmlBlockConverter();
		$summary   = array();

		$markup = $converter->convert( '<dt>Term</dt><dd>Definition.</dd>', $summary );

		$this->assertSame( 'mixed', $summary['html_block_conversion'] );
		$this->assertSame( 0, $summary['html_inferred_block_count'] );
		$this->assertSame( 2, $summary['html_classic_block_count'] );
		$this->assertStringContainsString( '<!-- wp:freeform -->', $markup );
		$this->assertStringContainsString( '<dt>Term</dt>', $markup );
		$this->assertStringContainsString( '<dd>Definition.</dd>', $markup );
		$this->assertStringNotContainsString( '<!-- wp:details', $markup );
	}

	/**
	 * Legacy accordion widgets become native Details blocks.
	 *
	 * @return void
	 */
	public function test_legacy_accordion_widgets_become_native_details_blocks() {
		$converter = new ImportHtmlBlockConverter();
		$summary   = array();

		$markup = $converter->convert(
			'<div id="legacy-faq" class="accordion alignfull">'
			. '<div id="panel-one" class="accordion-item active">'
			. '<h3 class="accordion-header"><button aria-expanded="true">First <strong>question</strong></button></h3>'
			. '<div class="accordion-collapse collapse show"><div class="accordion-body"><p>First answer.</p></div></div>'
			. '</div>'
			. '<br>'
			. '<div id="panel-two" class="accordion-item alignwide">'
			. '<h3 class="accordion-header"><button data-expanded="true">Second question</button></h3>'
			. '<div class="accordion-collapse collapse"><div class="accordion-body"><ul><li>Second answer.</li></ul></div></div>'
			. '</div>'
			. '</div>',
			$summary
		);

		$this->assertSame( 'structured', $summary['html_block_conversion'] );
		$this->assertSame( 2, $summary['html_inferred_block_count'] );
		$this->assertSame( 0, $summary['html_classic_block_count'] );
		$this->assertStringContainsString( '<!-- wp:details {"showContent":true,"anchor":"panel-one","align":"full"} -->', $markup );
		$this->assertStringContainsString( '<details class="wp-block-details alignfull" id="panel-one" name="legacy-faq" open><summary>First <strong>question</strong></summary>', $markup );
		$this->assertStringContainsString( '<p>First answer.</p>', $markup );
		$this->assertStringContainsString( '<!-- wp:details {"showContent":true,"anchor":"panel-two","align":"wide"} -->', $markup );
		$this->assertStringContainsString( '<details class="wp-block-details alignwide" id="panel-two" name="legacy-faq" open><summary>Second question</summary>', $markup );
		$this->assertStringContainsString( '<ul><li>Second answer.</li></ul>', $markup );
		$this->assertStringNotContainsString( '<!-- wp:freeform -->', $markup );
		$this->assertStringNotContainsString( '<div id="legacy-faq" class="accordion alignfull">', $markup );
		$this->assertStringNotContainsString( '<br>', $markup );
	}

	/**
	 * Tabbed interfaces are preserved as sanitized Custom HTML instead of flattened.
	 *
	 * @return void
	 */
	public function test_tabbed_interfaces_are_preserved_as_custom_html_blocks() {
		$converter = new ImportHtmlBlockConverter();
		$summary   = array();

		$markup = $converter->convert(
			'<div class="tabs">'
			. '<ul class="nav-tabs" role="tablist">'
			. '<li><a class="nav-link active" role="tab" href="#tab-one" onclick="track()">One</a></li>'
			. '<li><a class="nav-link" role="tab" href="#tab-two">Two</a></li>'
			. '</ul>'
			. '<div class="tab-content">'
			. '<div id="tab-one" class="tab-pane active" role="tabpanel"><p>First tab.</p></div>'
			. '<div id="tab-two" class="tab-pane" role="tabpanel"><p>Second tab.</p></div>'
			. '</div>'
			. '</div>',
			$summary
		);

		$this->assertSame( 'structured', $summary['html_block_conversion'] );
		$this->assertSame( 1, $summary['html_inferred_block_count'] );
		$this->assertSame( 0, $summary['html_classic_block_count'] );
		$this->assertStringContainsString( '<!-- wp:html -->', $markup );
		$this->assertStringContainsString( '<div class="tabs">', $markup );
		$this->assertStringContainsString( 'role="tablist"', $markup );
		$this->assertStringContainsString( 'role="tabpanel"', $markup );
		$this->assertStringNotContainsString( 'onclick', $markup );
		$this->assertStringNotContainsString( '<!-- wp:paragraph -->', $markup );
		$this->assertStringNotContainsString( '<!-- wp:freeform -->', $markup );
	}

	/**
	 * Hero wrappers with background images become native Cover blocks.
	 *
	 * @return void
	 */
	public function test_hero_wrappers_with_background_images_become_cover_blocks() {
		$converter = new ImportHtmlBlockConverter();
		$summary   = array();

		$markup = $converter->convert(
			'<section id="migration-hero" class="hero hero-large alignwide" style="background-image: url(\'hero.jpg\')">'
			. '<div class="hero-content"><h1>Migration launch</h1><p>Move the important content first.</p><a class="button" href="/start">Start import</a></div>'
			. '</section>'
			. '<section id="data-hero" class="hero alignfull" data-bg-image="data-hero.jpg"><h2>Data hero</h2><p>Data attribute background.</p></section>',
			$summary
		);

		$this->assertSame( 'structured', $summary['html_block_conversion'] );
		$this->assertSame( 2, $summary['html_inferred_block_count'] );
		$this->assertSame( 0, $summary['html_classic_block_count'] );
		$this->assertStringContainsString( '<!-- wp:cover {"url":"hero.jpg","dimRatio":50,"className":"universal-importer-hero","anchor":"migration-hero","align":"wide"} -->', $markup );
		$this->assertStringContainsString( '<div class="wp-block-cover universal-importer-hero alignwide" id="migration-hero">', $markup );
		$this->assertStringContainsString( '<img class="wp-block-cover__image-background" alt="" src="hero.jpg" data-object-fit="cover"/>', $markup );
		$this->assertStringContainsString( '<div class="wp-block-cover__inner-container">', $markup );
		$this->assertStringContainsString( '<!-- wp:heading {"level":1} -->', $markup );
		$this->assertStringContainsString( '<p>Move the important content first.</p>', $markup );
		$this->assertStringContainsString( '<!-- wp:button {"url":"\/start"} -->', $markup );
		$this->assertStringContainsString( '<!-- wp:cover {"url":"data-hero.jpg","dimRatio":50,"className":"universal-importer-hero","anchor":"data-hero","align":"full"} -->', $markup );
		$this->assertStringContainsString( '<div class="wp-block-cover universal-importer-hero alignfull" id="data-hero">', $markup );
		$this->assertStringContainsString( '<img class="wp-block-cover__image-background" alt="" src="data-hero.jpg" data-object-fit="cover"/>', $markup );
		$this->assertStringContainsString( '<h2>Data hero</h2>', $markup );
		$this->assertStringContainsString( '<p>Data attribute background.</p>', $markup );
		$this->assertStringNotContainsString( '<section id="migration-hero"', $markup );
		$this->assertStringNotContainsString( 'data-bg-image', $markup );
		$this->assertStringNotContainsString( '<!-- wp:freeform -->', $markup );
	}

	/**
	 * Hero wrappers with a leading image promote it to the Cover background.
	 *
	 * @return void
	 */
	public function test_hero_wrappers_with_leading_images_become_cover_blocks() {
		$converter = new ImportHtmlBlockConverter();
		$summary   = array();

		$markup = $converter->convert(
			'<header class="page-hero"><img src="banner.jpg" alt="Launch banner"><div><h1>Welcome</h1><p>Structured overlay copy.</p></div></header>',
			$summary
		);

		$this->assertSame( 'structured', $summary['html_block_conversion'] );
		$this->assertSame( 1, $summary['html_inferred_block_count'] );
		$this->assertSame( 0, $summary['html_classic_block_count'] );
		$this->assertStringContainsString( '<!-- wp:cover {"url":"banner.jpg","dimRatio":50,"className":"universal-importer-hero","alt":"Launch banner"} -->', $markup );
		$this->assertStringContainsString( '<img class="wp-block-cover__image-background" alt="Launch banner" src="banner.jpg" data-object-fit="cover"/>', $markup );
		$this->assertStringContainsString( '<h1>Welcome</h1>', $markup );
		$this->assertStringContainsString( '<p>Structured overlay copy.</p>', $markup );
		$this->assertStringNotContainsString( '<figure class="wp-block-image"><img src="banner.jpg"', $markup );
		$this->assertStringNotContainsString( '<!-- wp:freeform -->', $markup );
	}

	/**
	 * Opaque hero content keeps the whole wrapper in the classic fallback.
	 *
	 * @return void
	 */
	public function test_opaque_hero_wrappers_remain_classic_fallback() {
		$converter = new ImportHtmlBlockConverter();
		$summary   = array();

		$markup = $converter->convert( '<section class="hero" style="background-image:url(hero.jpg)"><custom-widget>Opaque</custom-widget></section>', $summary );

		$this->assertSame( 'mixed', $summary['html_block_conversion'] );
		$this->assertSame( 0, $summary['html_inferred_block_count'] );
		$this->assertSame( 1, $summary['html_classic_block_count'] );
		$this->assertStringContainsString( '<!-- wp:freeform -->', $markup );
		$this->assertStringContainsString( '<section class="hero" style="background-image:url(hero.jpg)"><custom-widget>Opaque</custom-widget></section>', $markup );
		$this->assertStringNotContainsString( '<!-- wp:cover', $markup );
	}

	/**
	 * Callout and card wrappers become native Group blocks.
	 *
	 * @return void
	 */
	public function test_callout_and_card_wrappers_become_group_blocks() {
		$converter = new ImportHtmlBlockConverter();
		$summary   = array();

		$markup = $converter->convert(
			'<aside id="migration-warning" class="alert alert-warning alignwide">'
			. '<h3>Before importing</h3><p>Review redirects and media paths.</p>'
			. '</aside>'
			. '<div class="card alignfull"><img src="cover.jpg" alt="Cover"><h2>Card title</h2><p>Card copy.</p></div>',
			$summary
		);

		$this->assertSame( 'structured', $summary['html_block_conversion'] );
		$this->assertSame( 2, $summary['html_inferred_block_count'] );
		$this->assertSame( 0, $summary['html_classic_block_count'] );
		$this->assertStringContainsString( '<!-- wp:group {"className":"universal-importer-callout universal-importer-callout-warning","anchor":"migration-warning","align":"wide"} -->', $markup );
		$this->assertStringContainsString( '<div class="wp-block-group universal-importer-callout universal-importer-callout-warning alignwide" id="migration-warning">', $markup );
		$this->assertStringContainsString( '<!-- wp:heading {"level":3} -->', $markup );
		$this->assertStringNotContainsString( '<aside', $markup );
		$this->assertStringContainsString( '<!-- wp:group {"className":"universal-importer-card","align":"full"} -->', $markup );
		$this->assertStringContainsString( '<div class="wp-block-group universal-importer-card alignfull">', $markup );
		$this->assertStringContainsString( '<figure class="wp-block-image"><img src="cover.jpg" alt="Cover"></figure>', $markup );
		$this->assertStringContainsString( '<p>Card copy.</p>', $markup );
		$this->assertStringNotContainsString( '<!-- wp:freeform -->', $markup );
	}

	/**
	 * Timeline and step wrappers become editable nested Group blocks.
	 *
	 * @return void
	 */
	public function test_timeline_and_step_wrappers_become_group_blocks() {
		$converter = new ImportHtmlBlockConverter();
		$summary   = array();

		$markup = $converter->convert(
			'<ol id="migration-roadmap" class="timeline alignwide">'
			. '<li id="phase-one"><time datetime="2026-01">Q1 2026</time><h3>Assess content</h3><p>Inventory pages and media.</p></li>'
			. '<br>'
			. '<li class="alignfull"><time>Q2</time><h3>Run importer</h3><ul><li>Confirm internal domains.</li></ul></li>'
			. '</ol>'
			. '<div class="steps"><div id="step-review" class="step"><h3>Review</h3><p>Check drafts.</p></div><br><div class="step"><h3>Publish</h3><p>Go live.</p></div></div>',
			$summary
		);

		$this->assertSame( 'structured', $summary['html_block_conversion'] );
		$this->assertSame( 2, $summary['html_inferred_block_count'] );
		$this->assertSame( 0, $summary['html_classic_block_count'] );
		$this->assertStringContainsString( '<!-- wp:group {"className":"universal-importer-timeline","anchor":"migration-roadmap","align":"wide"} -->', $markup );
		$this->assertStringContainsString( '<div class="wp-block-group universal-importer-timeline alignwide" id="migration-roadmap">', $markup );
		$this->assertStringContainsString( '<!-- wp:group {"className":"universal-importer-timeline-item","anchor":"phase-one"} -->', $markup );
		$this->assertStringContainsString( '<!-- wp:group {"className":"universal-importer-timeline-item","align":"full"} -->', $markup );
		$this->assertStringContainsString( '<p class="universal-importer-timeline-marker"><time datetime="2026-01">Q1 2026</time></p>', $markup );
		$this->assertStringContainsString( '<h3>Assess content</h3>', $markup );
		$this->assertStringContainsString( '<ul><li>Confirm internal domains.</li></ul>', $markup );
		$this->assertStringContainsString( '<!-- wp:group {"className":"universal-importer-steps"} -->', $markup );
		$this->assertStringContainsString( '<!-- wp:group {"className":"universal-importer-step-item","anchor":"step-review"} -->', $markup );
		$this->assertStringContainsString( '<p>Check drafts.</p>', $markup );
		$this->assertStringNotContainsString( '<!-- wp:list {"ordered":true} -->', $markup );
		$this->assertStringNotContainsString( '<!-- wp:freeform -->', $markup );
		$this->assertStringNotContainsString( '<br>', $markup );
	}

	/**
	 * Opaque timeline items keep the whole timeline in the classic fallback.
	 *
	 * @return void
	 */
	public function test_opaque_timeline_items_remain_classic_fallback() {
		$converter = new ImportHtmlBlockConverter();
		$summary   = array();

		$markup = $converter->convert( '<ol class="timeline"><li><custom-step>Opaque</custom-step></li><li><p>Known.</p></li></ol>', $summary );

		$this->assertSame( 'mixed', $summary['html_block_conversion'] );
		$this->assertSame( 0, $summary['html_inferred_block_count'] );
		$this->assertSame( 1, $summary['html_classic_block_count'] );
		$this->assertStringContainsString( '<!-- wp:freeform -->', $markup );
		$this->assertStringContainsString( '<custom-step>Opaque</custom-step>', $markup );
		$this->assertStringNotContainsString( 'universal-importer-timeline-item', $markup );
	}

	/**
	 * Opaque callout children stay in a classic fallback.
	 *
	 * @return void
	 */
	public function test_opaque_callout_children_remain_classic_fallback() {
		$converter = new ImportHtmlBlockConverter();
		$summary   = array();

		$markup = $converter->convert( '<div class="callout"><custom-card>Opaque</custom-card></div>', $summary );

		$this->assertSame( 'mixed', $summary['html_block_conversion'] );
		$this->assertSame( 0, $summary['html_inferred_block_count'] );
		$this->assertSame( 1, $summary['html_classic_block_count'] );
		$this->assertStringContainsString( '<!-- wp:freeform -->', $markup );
		$this->assertStringContainsString( '<div class="callout"><custom-card>Opaque</custom-card></div>', $markup );
		$this->assertStringNotContainsString( '<!-- wp:group', $markup );
	}

	/**
	 * Obvious legacy grid wrappers become native Columns blocks.
	 *
	 * @return void
	 */
	public function test_legacy_column_layouts_become_native_columns_blocks() {
		$converter = new ImportHtmlBlockConverter();
		$summary   = array();

		$markup = $converter->convert(
			'<div id="layout-row" class="row alignwide"><div id="left-column" class="col-md-4"><h2>Left</h2><p>Left copy.</p></div><br><div id="right-column" class="col-md-8"><p>Right copy.</p><a class="button" href="/right">Right action</a></div></div>',
			$summary
		);

		$this->assertSame( 'structured', $summary['html_block_conversion'] );
		$this->assertSame( 1, $summary['html_inferred_block_count'] );
		$this->assertSame( 0, $summary['html_classic_block_count'] );
		$this->assertStringContainsString( '<!-- wp:columns {"align":"wide","anchor":"layout-row"} -->', $markup );
		$this->assertStringContainsString( '<div class="wp-block-columns alignwide" id="layout-row">', $markup );
		$this->assertStringContainsString( '<!-- wp:column {"width":"33.33%","anchor":"left-column"} -->', $markup );
		$this->assertStringContainsString( '<div class="wp-block-column" style="flex-basis:33.33%" id="left-column">', $markup );
		$this->assertStringContainsString( '<!-- wp:heading {"level":2} -->', $markup );
		$this->assertStringContainsString( '<!-- wp:column {"width":"66.67%","anchor":"right-column"} -->', $markup );
		$this->assertStringContainsString( '<div class="wp-block-column" style="flex-basis:66.67%" id="right-column">', $markup );
		$this->assertStringContainsString( '<!-- wp:buttons -->', $markup );
		$this->assertStringContainsString( '<!-- /wp:columns -->', $markup );
		$this->assertStringNotContainsString( '<!-- wp:freeform -->', $markup );
		$this->assertStringNotContainsString( '<br>', $markup );
	}

	/**
	 * Pricing and comparison card grids become Columns with nested Group cards.
	 *
	 * @return void
	 */
	public function test_pricing_card_grids_become_columns_with_grouped_cards() {
		$converter = new ImportHtmlBlockConverter();
		$summary   = array();

		$markup = $converter->convert(
			'<section class="pricing-grid">'
			. '<article id="starter-plan" class="pricing-plan alignwide"><h2>Starter</h2><p><strong>$9</strong> per month</p><ul><li>One site</li></ul><a class="button" href="/buy-starter">Buy Starter</a></article>'
			. '<article id="pro-plan" class="pricing-plan featured alignfull"><h2>Pro</h2><p><strong>$29</strong> per month</p><ul><li>Ten sites</li></ul><a role="button" href="/buy-pro">Buy Pro</a></article>'
			. '</section>',
			$summary
		);

		$this->assertSame( 'structured', $summary['html_block_conversion'] );
		$this->assertSame( 1, $summary['html_inferred_block_count'] );
		$this->assertSame( 0, $summary['html_classic_block_count'] );
		$this->assertStringContainsString( '<!-- wp:columns -->', $markup );
		$this->assertStringContainsString( '<!-- wp:column -->', $markup );
		$this->assertStringContainsString( '<!-- wp:group {"className":"universal-importer-card","anchor":"starter-plan","align":"wide"} -->', $markup );
		$this->assertStringContainsString( '<div class="wp-block-group universal-importer-card alignwide" id="starter-plan">', $markup );
		$this->assertStringContainsString( '<h2>Starter</h2>', $markup );
		$this->assertStringContainsString( '<!-- wp:buttons -->', $markup );
		$this->assertStringContainsString( '<!-- wp:button {"url":"\/buy-pro"} -->', $markup );
		$this->assertStringContainsString( '<div class="wp-block-group universal-importer-card alignfull" id="pro-plan">', $markup );
		$this->assertStringNotContainsString( '<section class="pricing-grid">', $markup );
		$this->assertStringNotContainsString( '<!-- wp:freeform -->', $markup );
	}

	/**
	 * Pullquote-marked blockquotes become native Pullquote blocks.
	 *
	 * @return void
	 */
	public function test_pullquote_blockquotes_become_native_pullquote_blocks() {
		$converter = new ImportHtmlBlockConverter();
		$summary   = array();

		$markup = $converter->convert(
			'<blockquote id="feature-pullquote" class="pullquote has-text-align-center alignwide"><p>Imported pullquote text.</p><cite>Source Person</cite></blockquote>',
			$summary
		);

		$this->assertSame( 'structured', $summary['html_block_conversion'] );
		$this->assertSame( 1, $summary['html_inferred_block_count'] );
		$this->assertSame( 0, $summary['html_classic_block_count'] );
		$this->assertStringContainsString( '<!-- wp:pullquote {"align":"wide","textAlign":"center","anchor":"feature-pullquote"} -->', $markup );
		$this->assertStringContainsString( '<figure class="wp-block-pullquote alignwide has-text-align-center" id="feature-pullquote"><blockquote><p>Imported pullquote text.</p><cite>Source Person</cite></blockquote></figure>', $markup );
		$this->assertStringNotContainsString( '<!-- wp:quote -->', $markup );
		$this->assertStringNotContainsString( '<!-- wp:freeform -->', $markup );
	}

	/**
	 * Figure-wrapped pullquotes move figcaptions into pullquote citations.
	 *
	 * @return void
	 */
	public function test_figure_wrapped_pullquotes_preserve_figcaption_as_citation() {
		$converter = new ImportHtmlBlockConverter();
		$summary   = array();

		$markup = $converter->convert(
			'<figure class="wp-block-pullquote alignfull"><blockquote><p>Figure pullquote text.</p></blockquote><br><figcaption>Figure Citation</figcaption></figure>'
			. '<figure class="wp-block-pullquote"><blockquote id="nested-pullquote" class="has-text-align-right"><p>Nested pullquote metadata.</p></blockquote><figcaption>Nested Citation</figcaption></figure>',
			$summary
		);

		$this->assertSame( 'structured', $summary['html_block_conversion'] );
		$this->assertSame( 2, $summary['html_inferred_block_count'] );
		$this->assertSame( 0, $summary['html_classic_block_count'] );
		$this->assertStringContainsString( '<!-- wp:pullquote {"align":"full"} -->', $markup );
		$this->assertStringContainsString( '<figure class="wp-block-pullquote alignfull"><blockquote><p>Figure pullquote text.</p><cite>Figure Citation</cite></blockquote></figure>', $markup );
		$this->assertStringContainsString( '<!-- wp:pullquote {"textAlign":"right","anchor":"nested-pullquote"} -->', $markup );
		$this->assertStringContainsString( '<figure class="wp-block-pullquote has-text-align-right" id="nested-pullquote"><blockquote><p>Nested pullquote metadata.</p><cite>Nested Citation</cite></blockquote></figure>', $markup );
		$this->assertStringNotContainsString( '<figcaption>', $markup );
		$this->assertStringNotContainsString( '<br>', $markup );
		$this->assertStringNotContainsString( '<!-- wp:freeform -->', $markup );
	}

	/**
	 * Orphan pullquote citations immediately after blockquotes become native citations.
	 *
	 * @return void
	 */
	public function test_orphan_pullquote_citations_join_previous_blockquotes() {
		$converter = new ImportHtmlBlockConverter();
		$summary   = array();

		$markup = $converter->convert(
			'<blockquote class="pullquote"><p>Pullquote text.</p></blockquote><figcaption>Pullquote <strong>Author</strong></figcaption>'
			. '<blockquote class="wp-block-pullquote"><p>Another pullquote.</p></blockquote><cite>Second Author</cite>',
			$summary
		);

		$this->assertSame( 'structured', $summary['html_block_conversion'] );
		$this->assertSame( 2, $summary['html_inferred_block_count'] );
		$this->assertSame( 0, $summary['html_classic_block_count'] );
		$this->assertStringContainsString( '<figure class="wp-block-pullquote"><blockquote><p>Pullquote text.</p><cite>Pullquote <strong>Author</strong></cite></blockquote></figure>', $markup );
		$this->assertStringContainsString( '<figure class="wp-block-pullquote"><blockquote><p>Another pullquote.</p><cite>Second Author</cite></blockquote></figure>', $markup );
		$this->assertStringNotContainsString( '<!-- wp:freeform -->', $markup );

		$late_markup = $converter->convert( '<blockquote class="pullquote"><p>Pullquote text.</p></blockquote><p>After pullquote.</p><figcaption>Late Author</figcaption>', $summary );

		$this->assertSame( 'mixed', $summary['html_block_conversion'] );
		$this->assertSame( 2, $summary['html_inferred_block_count'] );
		$this->assertSame( 1, $summary['html_classic_block_count'] );
		$this->assertStringContainsString( '<figure class="wp-block-pullquote"><blockquote><p>Pullquote text.</p></blockquote></figure>', $late_markup );
		$this->assertStringContainsString( '<p>After pullquote.</p>', $late_markup );
		$this->assertStringContainsString( '<figcaption>Late Author</figcaption>', $late_markup );

		$existing_markup = $converter->convert( '<blockquote class="pullquote"><p>Pullquote text.</p><cite>Existing Author</cite></blockquote><figcaption>Extra Author</figcaption>', $summary );

		$this->assertSame( 'mixed', $summary['html_block_conversion'] );
		$this->assertSame( 1, $summary['html_inferred_block_count'] );
		$this->assertSame( 1, $summary['html_classic_block_count'] );
		$this->assertStringContainsString( '<figure class="wp-block-pullquote"><blockquote><p>Pullquote text.</p><cite>Existing Author</cite></blockquote></figure>', $existing_markup );
		$this->assertStringContainsString( '<figcaption>Extra Author</figcaption>', $existing_markup );
	}

	/**
	 * Pullquote figures with extra direct children keep a conservative fallback.
	 *
	 * @return void
	 */
	public function test_ambiguous_pullquote_figures_keep_classic_fallback() {
		$converter = new ImportHtmlBlockConverter();
		$summary   = array();

		$markup = $converter->convert(
			'<figure id="pullquote-with-note" class="wp-block-pullquote">'
			. '<blockquote><p>Pullquote with extra source note.</p></blockquote>'
			. '<figcaption>Pullquote Source</figcaption>'
			. '<aside class="source-note">Keep this source note.</aside>'
			. '</figure>',
			$summary
		);

		$this->assertSame( 'mixed', $summary['html_block_conversion'] );
		$this->assertSame( 0, $summary['html_inferred_block_count'] );
		$this->assertSame( 1, $summary['html_classic_block_count'] );
		$this->assertStringContainsString( '<!-- wp:freeform -->', $markup );
		$this->assertStringContainsString( '<aside class="source-note">Keep this source note.</aside>', $markup );
		$this->assertStringNotContainsString( '<!-- wp:pullquote', $markup );
	}

	/**
	 * Column wrappers with opaque child content keep their classic fallback.
	 *
	 * @return void
	 */
	public function test_opaque_column_layouts_remain_classic_fallback() {
		$converter = new ImportHtmlBlockConverter();
		$summary   = array();

		$markup = $converter->convert(
			'<div class="row"><div class="col-md-6"><custom-card>Opaque</custom-card></div><div class="col-md-6"><p>Known.</p></div></div>',
			$summary
		);

		$this->assertSame( 'mixed', $summary['html_block_conversion'] );
		$this->assertSame( 0, $summary['html_inferred_block_count'] );
		$this->assertSame( 1, $summary['html_classic_block_count'] );
		$this->assertStringContainsString( '<!-- wp:freeform -->', $markup );
		$this->assertStringContainsString( '<custom-card>Opaque</custom-card>', $markup );
		$this->assertStringNotContainsString( '<!-- wp:columns -->', $markup );
	}

	/**
	 * Obvious button-styled links become native Buttons/Button blocks.
	 *
	 * @return void
	 */
	public function test_button_links_become_native_button_blocks() {
		$converter = new ImportHtmlBlockConverter();
		$summary   = array();

		$markup = $converter->convert(
			'<p id="button-row" class="alignwide Is-Style-Outline is-style-danger">'
			. '<a id="start-button" class="Button primary" href="https://example.test/start" target="_BLANK" rel="NOOPENER noreferrer javascript:alert(1) NOOPENER" title="Start import" aria-label="Start importer">Get <strong>started</strong></a>'
			. '</p>'
			. '<a id="bootstrap-button" class="Btn-Outline-Primary" href="/buy">Buy now</a>',
			$summary
		);

		$this->assertSame( 'structured', $summary['html_block_conversion'] );
		$this->assertSame( 2, $summary['html_inferred_block_count'] );
		$this->assertSame( 0, $summary['html_classic_block_count'] );
		$this->assertStringContainsString( '<!-- wp:buttons {"align":"wide"} -->', $markup );
		$this->assertStringContainsString( '<div class="wp-block-buttons alignwide">', $markup );
		$this->assertStringContainsString( '<!-- wp:button {"url":"https:\/\/example.test\/start","anchor":"button-row","className":"is-style-outline","title":"Start import","linkTarget":"_blank","rel":"noopener noreferrer"} -->', $markup );
		$this->assertStringContainsString( '<div class="wp-block-button is-style-outline" id="button-row"><a class="wp-block-button__link wp-element-button" href="https://example.test/start" title="Start import" target="_blank" rel="noopener noreferrer" aria-label="Start importer">Get <strong>started</strong></a></div>', $markup );
		$this->assertStringContainsString( '<!-- wp:button {"url":"\/buy","anchor":"bootstrap-button","className":"is-style-outline"} -->', $markup );
		$this->assertStringContainsString( '<div class="wp-block-button is-style-outline" id="bootstrap-button"><a class="wp-block-button__link wp-element-button" href="/buy">Buy now</a></div>', $markup );
		$this->assertStringNotContainsString( 'javascript:alert', $markup );
		$this->assertStringNotContainsString( 'id="start-button"', $markup );
		$this->assertStringNotContainsString( 'is-style-danger', $markup );
		$this->assertStringNotContainsString( '<!-- wp:paragraph -->', $markup );
		$this->assertStringNotContainsString( '<!-- wp:freeform -->', $markup );
	}

	/**
	 * Direct downloadable document links become native File blocks.
	 *
	 * @return void
	 */
	public function test_downloadable_links_become_native_file_blocks() {
		$converter = new ImportHtmlBlockConverter();
		$summary   = array();

		$markup = $converter->convert(
			'<a id="annual-report" style="margin-left: auto; margin-right: auto" href="docs/Annual%20Report.pdf" target="_BLANK" rel="NOFOLLOW noopener javascript:alert(1) NOFOLLOW" title="Download annual report" aria-label="Download annual report PDF" type="APPLICATION/PDF" hreflang="EN-US" referrerpolicy="STRICT-ORIGIN-WHEN-CROSS-ORIGIN" download="../Annual Report 2026.pdf">Annual report</a>'
			. '<a id="spaced-url-name" href="docs/%20Board%20Minutes.pdf%20">Board minutes</a>'
			. '<a id="encoded-separator-url-name" href="docs/Board%2FMinutes.pdf">Board minutes archive</a>'
			. '<a id="encoded-backslash-url-name" href="docs/Board%5CMinutes.pdf">Board minutes backslash archive</a>'
			. '<a id="encoded-unsafe-url-name" href="docs/Board%3AMinutes.pdf">Board minutes unsafe archive</a>'
			. '<a id="encoded-unsafe-run-url-name" href="docs/Board%3A%2FMinutes.pdf">Board minutes unsafe run archive</a>'
			. '<a id="encoded-traversal-url-name" href="docs/%2E%2E%2FBoard%20Minutes.pdf">Board minutes traversal archive</a>'
			. '<a id="encoded-suffix-url-name" href="docs/Board%20Minutes.pdf%3A">Board minutes suffix archive</a>'
			. '<a id="budget-sheet" href="docs/Budget%202026.xlsx?download=1#sheet" type="text/html; charset=utf-8" hreflang="en_US" referrerpolicy="made-up" download="bad:name.xlsx">Budget sheet</a>'
			. '<a id="launch-deck" href="docs/Launch%20Deck.PPTX">Launch deck</a>'
			. '<a id="ppt-download" href="/slides/legacy-deck.ppt">Legacy deck download</a>'
			. '<a id="md-download" href="/docs/source-readme.md">Source README</a>'
			. '<a id="markdown-manual" href="/docs/manual.markdown">Markdown manual</a>'
			. '<a id="mdown-guide" href="/docs/guide.mdown">MDown guide</a>'
			. '<a id="log-download" href="/logs/import-run.log">Import run log</a>'
			. '<a id="txt-download" href="/docs/plain-readme.txt">Plain text README</a>'
			. '<a id="text-notes" href="/docs/field-notes.text">Field notes</a>'
			. '<a id="epub-download" href="/books/source-novel.epub">Source novel</a>'
			. '<a id="zip-download" href="/archives/source-export.zip">Source export archive</a>'
			. '<a id="tar-download" href="/archives/source-export.tar">Source tar archive</a>'
			. '<a id="tgz-download" href="/archives/source-export.tgz">Source TGZ archive</a>'
			. '<a id="gzip-download" href="/archives/source-export.tar.gz">Source gzip archive</a>'
			. '<a id="bzip-download" href="/archives/source-export.tar.bz2">Source bzip archive</a>'
			. '<a id="tbz-download" href="/archives/source-export.tbz">Source TBZ archive</a>'
			. '<a id="tbz2-download" href="/archives/source-export.tbz2">Source TBZ2 archive</a>'
			. '<a id="xz-download" href="/archives/source-export.tar.xz">Source xz archive</a>'
			. '<a id="zst-download" href="/archives/source-export.tar.zst">Source zstd archive</a>'
			. '<a id="csv-download" href="/data/source-export.csv">Source CSV export</a>'
			. '<a id="wxr-export" href="/exports/site-export.wxr">Site export</a>'
			. '<a id="xml-export" href="/exports/site-export.xml">XML export</a>'
			. '<a id="json-export" href="/exports/site-export.json">JSON export</a>'
			. '<a id="jsonl-export" href="/exports/site-export.jsonl">JSONL export</a>'
			. '<a id="ndjson-export" href="/exports/site-export.ndjson">NDJSON export</a>'
			. '<a id="diff-export" href="/exports/source-changes.diff">Diff export</a>'
			. '<a id="patch-export" href="/exports/source-changes.patch">Patch export</a>'
			. '<a id="env-export" href="/exports/site-export.env">ENV export</a>'
			. '<a id="ics-export" href="/exports/event-calendar.ics">Calendar export</a>'
			. '<a id="ini-export" href="/exports/site-export.ini">INI export</a>'
			. '<a id="lock-export" href="/exports/yarn.lock">Dependency lock export</a>'
			. '<a id="map-export" href="/assets/app.js.map">Source map export</a>'
			. '<a id="webmanifest-export" href="/assets/site.webmanifest">Web app manifest export</a>'
			. '<a id="css-export" href="/assets/theme-style.css">Theme stylesheet export</a>'
			. '<a id="po-export" href="/languages/site-en_US.po">Translation PO export</a>'
			. '<a id="pot-export" href="/languages/site.pot">Translation POT export</a>'
			. '<a id="mo-export" href="/languages/site-en_US.mo">Translation MO export</a>'
			. '<a id="properties-export" href="/exports/site-export.properties">Properties export</a>'
			. '<a id="toml-export" href="/exports/site-export.toml">TOML export</a>'
			. '<a id="yaml-export" href="/exports/site-export.yaml">YAML export</a>'
			. '<a id="yml-export" href="/exports/site-export.yml">YML export</a>'
			. '<a id="sql-export" href="/exports/site-export.sql">SQL export</a>'
			. '<a id="sqlite-db-export" href="/exports/site-export.db">SQLite DB export</a>'
			. '<a id="sqlite-file-export" href="/exports/site-export.sqlite">SQLite file export</a>'
			. '<a id="sqlite-export" href="/exports/site-export.sqlite3">SQLite export</a>'
			. '<a id="odt-download" href="/files/source-document.odt">Source OpenDocument text</a>'
			. '<a id="docx-download" href="/docs/board-brief.docx">Board brief</a>'
			. '<a id="doc-download" href="/docs/legacy-memo.doc">Legacy memo download</a>'
			. '<a id="xls-download" href="/sheets/legacy-budget.xls">Legacy budget</a>'
			. '<a id="ods-download" href="/sheets/source-ledger.ods">Source ledger</a>'
			. '<a id="odp-download" href="/slides/source-deck.odp">Source presentation</a>'
			. '<a id="key-download" href="/slides/source-keynote.key">Source Keynote deck</a>'
			. '<a id="rtf-download" href="/docs/source-notes.rtf">Source RTF notes</a>'
			. '<a id="signed-report" href="/download?file=99" download="Board%20Packet.pdf">Board packet</a>'
			. '<a id="spaced-download-name" href="/download?file=100" download="%20Board%20Agenda.pdf%20">Board agenda</a>'
			. '<a id="unsafe-download-name" href="/download?file=101" download="Board%3A%2FAgenda.pdf">Unsafe board agenda</a>'
			. '<a id="encoded-traversal-download-name" href="/download?file=102" download="%2E%2E%2FBoard%20Agenda.pdf">Traversal board agenda</a>'
			. '<a id="encoded-suffix-download-name" href="/download?file=103" download="Board%20Agenda.pdf%3A">Suffix board agenda</a>'
			. '<a id="sql-download-name" href="/download?file=104" type="application/octet-stream" download="Database%20Backup.sql">Database backup</a>'
			. '<a id="generated-download" href="/download" download>Generated export</a>'
			. '<a id="unsupported-download" href="/download" download="setup.exe">Unsupported export</a>'
			. '<a id="supported-download-name" href="/download/archive.bin" download="Annual%20Archive.zip">Supported download archive</a>'
			. '<a id="supported-url-unsupported-download" href="/reports/source-report.pdf" download="setup.exe">Supported source report</a>'
			. '<a id="query-download" href="?export=1" download>Query export</a>'
			. '<a id="ftp-export" href="ftp://legacy.example.test/exports/site-export.wxr">Legacy FTP export</a>'
			. '<a id="protocol-export" href="//legacy.example.test/files/source-export.pdf">Protocol export</a>'
			. '<a id="typed-report" href="/reports/latest" type="application/pdf">Latest report</a>'
			. '<a id="typed-report-charset" href="/reports/charset-latest" type="APPLICATION/PDF; charset=binary">Latest charset report</a>'
			. '<a id="typed-unsupported-url-mime" href="/download/archive.bin" type="APPLICATION/PDF">Archive PDF</a>'
			. '<a id="typed-x-pdf" href="/reports/archive" type="APPLICATION/X-PDF">Archived report</a>'
			. '<a id="typed-acrobat" href="/reports/acrobat-copy" type="APPLICATION/ACROBAT">Acrobat copy</a>'
			. '<a id="typed-brief" href="/files/brief" type="APPLICATION/VND.OPENXMLFORMATS-OFFICEDOCUMENT.WORDPROCESSINGML.DOCUMENT">Brief</a>'
			. '<a id="typed-workbook" href="/sheets/workbook" type="APPLICATION/VND.OPENXMLFORMATS-OFFICEDOCUMENT.SPREADSHEETML.SHEET">Workbook</a>'
			. '<a id="typed-open-document" href="/files/open-document" type="APPLICATION/VND.OASIS.OPENDOCUMENT.TEXT">OpenDocument text</a>'
			. '<a id="typed-open-spreadsheet" href="/sheets/open-spreadsheet" type="APPLICATION/VND.OASIS.OPENDOCUMENT.SPREADSHEET">OpenDocument spreadsheet</a>'
			. '<a id="typed-open-presentation" href="/slides/open-presentation" type="APPLICATION/VND.OASIS.OPENDOCUMENT.PRESENTATION">OpenDocument presentation</a>'
			. '<a id="typed-presentation" href="/slides/presentation" type="APPLICATION/VND.OPENXMLFORMATS-OFFICEDOCUMENT.PRESENTATIONML.PRESENTATION">Presentation</a>'
			. '<a id="typed-keynote" href="/slides/keynote" type="APPLICATION/VND.APPLE.KEYNOTE">Keynote</a>'
			. '<a id="typed-archive" href="/archives/export" type="APPLICATION/X-ZIP-COMPRESSED">Export archive</a>'
			. '<a id="typed-bundle" href="/archives/bundle" type="APPLICATION/X-ZIP">Bundle archive</a>'
			. '<a id="typed-zip" href="/archives/standard-zip" type="APPLICATION/ZIP">Standard ZIP archive</a>'
			. '<a id="typed-x-tar" href="/archives/tar-export" type="APPLICATION/X-TAR">Tar archive</a>'
			. '<a id="typed-gzip" href="/archives/gzip-export" type="APPLICATION/GZIP">Gzip archive</a>'
			. '<a id="typed-x-gzip" href="/archives/legacy-gzip-export" type="APPLICATION/X-GZIP">Legacy gzip archive</a>'
			. '<a id="typed-x-bzip2" href="/archives/bzip-export" type="APPLICATION/X-BZIP2">Bzip archive</a>'
			. '<a id="typed-x-xz" href="/archives/xz-export" type="APPLICATION/X-XZ">XZ archive</a>'
			. '<a id="typed-zstd" href="/archives/zstd-export" type="APPLICATION/ZSTD">Zstandard archive</a>'
			. '<a id="typed-x-zstd" href="/archives/legacy-zstd-export" type="APPLICATION/X-ZSTD">Legacy zstandard archive</a>'
			. '<a id="typed-csv" href="/data/export" type="APPLICATION/CSV">CSV export</a>'
			. '<a id="typed-text-csv" href="/data/text-export" type="TEXT/CSV">Text CSV export</a>'
			. '<a id="typed-legacy-csv" href="/data/legacy-export" type="TEXT/X-CSV">Legacy CSV export</a>'
			. '<a id="typed-xml" href="/exports/latest" type="APPLICATION/XML">Latest XML export</a>'
			. '<a id="typed-json" href="/exports/json-latest" type="APPLICATION/JSON">Latest JSON export</a>'
			. '<a id="typed-ndjson" href="/exports/ndjson-latest" type="APPLICATION/X-NDJSON">Latest NDJSON export</a>'
			. '<a id="typed-text-ndjson" href="/exports/text-ndjson-latest" type="TEXT/X-NDJSON">Latest text NDJSON export</a>'
			. '<a id="typed-diff" href="/exports/diff-latest" type="TEXT/X-DIFF">Latest diff export</a>'
			. '<a id="typed-patch" href="/exports/patch-latest" type="TEXT/X-PATCH">Latest patch export</a>'
			. '<a id="typed-env" href="/exports/env-latest" type="APPLICATION/X-ENV">Latest ENV export</a>'
			. '<a id="typed-text-env" href="/exports/text-env-latest" type="TEXT/X-ENV">Latest text ENV export</a>'
			. '<a id="typed-calendar" href="/exports/calendar-latest" type="TEXT/CALENDAR">Latest calendar export</a>'
			. '<a id="typed-ics" href="/exports/ics-latest" type="APPLICATION/ICS">Latest ICS export</a>'
			. '<a id="typed-ini" href="/exports/ini-latest" type="APPLICATION/INI">Latest INI export</a>'
			. '<a id="typed-text-ini" href="/exports/text-ini-latest" type="TEXT/X-INI">Latest text INI export</a>'
			. '<a id="typed-lock" href="/exports/lock-latest" type="APPLICATION/X-LOCK">Latest dependency lock export</a>'
			. '<a id="typed-text-lock" href="/exports/text-lock-latest" type="TEXT/X-LOCK">Latest text dependency lock export</a>'
			. '<a id="typed-source-map" href="/assets/app-source-map" type="APPLICATION/SOURCE-MAP">Latest source map export</a>'
			. '<a id="typed-text-source-map" href="/assets/text-source-map" type="TEXT/X-SOURCE-MAP">Latest text source map export</a>'
			. '<a id="typed-webmanifest" href="/assets/manifest-latest" type="APPLICATION/MANIFEST+JSON">Latest web app manifest export</a>'
			. '<a id="typed-css" href="/assets/css-latest" type="TEXT/CSS">Latest CSS export</a>'
			. '<a id="typed-po" href="/languages/po-latest" type="TEXT/X-GETTEXT-TRANSLATION">Latest PO export</a>'
			. '<a id="typed-pot" href="/languages/pot-latest" type="TEXT/X-GETTEXT-TRANSLATION-TEMPLATE">Latest POT export</a>'
			. '<a id="typed-mo" href="/languages/mo-latest" type="APPLICATION/X-GETTEXT">Latest MO export</a>'
			. '<a id="typed-properties" href="/exports/properties-latest" type="APPLICATION/X-JAVA-PROPERTIES">Latest properties export</a>'
			. '<a id="typed-text-properties" href="/exports/text-properties-latest" type="TEXT/X-JAVA-PROPERTIES">Latest text properties export</a>'
			. '<a id="typed-toml" href="/exports/toml-latest" type="APPLICATION/TOML">Latest TOML export</a>'
			. '<a id="typed-text-toml" href="/exports/text-toml-latest" type="TEXT/X-TOML">Latest text TOML export</a>'
			. '<a id="typed-yaml" href="/exports/yaml-latest" type="APPLICATION/YAML">Latest YAML export</a>'
			. '<a id="typed-text-yaml" href="/exports/text-yaml-latest" type="TEXT/X-YAML">Latest text YAML export</a>'
			. '<a id="typed-sql" href="/exports/sql-latest" type="APPLICATION/SQL">Latest SQL export</a>'
			. '<a id="typed-x-sql" href="/exports/legacy-sql-latest" type="APPLICATION/X-SQL">Latest legacy SQL export</a>'
			. '<a id="typed-text-sql" href="/exports/text-sql-latest" type="TEXT/X-SQL">Latest text SQL export</a>'
			. '<a id="typed-sqlite" href="/exports/sqlite-latest" type="APPLICATION/VND.SQLITE3">Latest SQLite export</a>'
			. '<a id="typed-x-sqlite" href="/exports/legacy-sqlite-file-latest" type="APPLICATION/X-SQLITE">Latest legacy SQLite file export</a>'
			. '<a id="typed-x-sqlite3" href="/exports/legacy-sqlite-latest" type="APPLICATION/X-SQLITE3">Latest legacy SQLite export</a>'
			. '<a id="typed-text-xml" href="/exports/text-latest" type="TEXT/XML">Latest text XML export</a>'
			. '<a id="typed-rss-xml" href="/exports/rss-latest" type="APPLICATION/RSS+XML">Latest RSS XML export</a>'
			. '<a id="typed-atom-xml" href="/exports/atom-latest" type="APPLICATION/ATOM+XML">Latest Atom XML export</a>'
			. '<a id="typed-rdf-xml" href="/exports/rdf-latest" type="APPLICATION/RDF+XML">Latest RDF XML export</a>'
			. '<a id="typed-excel" href="/sheets/monthly" type="APPLICATION/EXCEL">Monthly sheet</a>'
			. '<a id="typed-x-excel" href="/sheets/archive" type="APPLICATION/X-EXCEL">Archived sheet</a>'
			. '<a id="typed-x-msexcel" href="/sheets/legacy" type="APPLICATION/X-MSEXCEL">Legacy sheet</a>'
			. '<a id="typed-ms-excel" href="/sheets/msexcel" type="APPLICATION/MSEXCEL">MS Excel sheet</a>'
			. '<a id="typed-x-ms-excel" href="/sheets/ms-excel" type="APPLICATION/X-MS-EXCEL">X MS Excel sheet</a>'
			. '<a id="typed-vnd-ms-excel" href="/sheets/vnd-ms-excel" type="APPLICATION/VND.MS-EXCEL">Vendor MS Excel sheet</a>'
			. '<a id="typed-word" href="/docs/memo" type="APPLICATION/X-MSWORD">Memo</a>'
			. '<a id="typed-msword" href="/docs/msword" type="APPLICATION/MSWORD">MS Word memo</a>'
			. '<a id="typed-legacy-word" href="/docs/legacy-memo" type="APPLICATION/WORD">Legacy memo</a>'
			. '<a id="typed-rtf" href="/docs/rich-text" type="TEXT/RTF">Rich text</a>'
			. '<a id="typed-application-rtf" href="/docs/application-rtf" type="APPLICATION/RTF">Application RTF</a>'
			. '<a id="typed-richtext" href="/docs/richtext-note" type="TEXT/RICHTEXT">Richtext note</a>'
			. '<a id="typed-plain-text" href="/docs/plain-note" type="TEXT/PLAIN">Plain text note</a>'
			. '<a id="typed-log" href="/logs/import-run" type="TEXT/X-LOG">Import log</a>'
			. '<a id="typed-markdown" href="/docs/readme" type="TEXT/MARKDOWN">README</a>'
			. '<a id="typed-x-markdown" href="/docs/legacy-readme" type="TEXT/X-MARKDOWN">Legacy README</a>'
			. '<a id="typed-x-rtf" href="/docs/rtf-archive" type="APPLICATION/X-RTF">RTF archive</a>'
			. '<a id="typed-x-epub" href="/books/legacy-epub" type="APPLICATION/X-EPUB+ZIP">Legacy EPUB</a>'
			. '<a id="typed-epub" href="/books/standard-epub" type="APPLICATION/EPUB+ZIP">Standard EPUB</a>'
			. '<a id="typed-powerpoint" href="/slides/legacy" type="APPLICATION/POWERPOINT">Legacy deck</a>'
			. '<a id="typed-x-powerpoint" href="/slides/archive" type="APPLICATION/X-MSPOWERPOINT">Archived deck</a>'
			. '<a id="typed-ms-powerpoint" href="/slides/meeting" type="APPLICATION/MSPOWERPOINT">Meeting deck</a>'
			. '<a id="typed-vnd-ms-powerpoint" href="/slides/vnd-ms-powerpoint" type="APPLICATION/VND.MS-POWERPOINT">Vendor MS PowerPoint deck</a>',
			$summary
		);

		$this->assertSame( 'structured', $summary['html_block_conversion'] );
		$this->assertSame( 159, $summary['html_inferred_block_count'] );
		$this->assertSame( 0, $summary['html_classic_block_count'] );
		$this->assertStringContainsString( '<!-- wp:file {"href":"docs\/Annual%20Report.pdf","textLinkHref":"docs\/Annual%20Report.pdf","fileName":"Annual Report.pdf","align":"center","anchor":"annual-report","textLinkTarget":"_blank"} -->', $markup );
		$this->assertStringContainsString( '<div class="wp-block-file aligncenter" id="annual-report"><a href="docs/Annual%20Report.pdf" target="_blank" rel="nofollow noopener" title="Download annual report" aria-label="Download annual report PDF" type="application/pdf" hreflang="en-us" referrerpolicy="strict-origin-when-cross-origin">Annual report</a><a href="docs/Annual%20Report.pdf" class="wp-block-file__button wp-element-button" download="Annual Report 2026.pdf">Download</a></div>', $markup );
		$this->assertStringContainsString( '<!-- wp:file {"href":"docs\/%20Board%20Minutes.pdf%20","textLinkHref":"docs\/%20Board%20Minutes.pdf%20","fileName":"Board Minutes.pdf","anchor":"spaced-url-name"} -->', $markup );
		$this->assertStringContainsString( '<div class="wp-block-file" id="spaced-url-name"><a href="docs/%20Board%20Minutes.pdf%20">Board minutes</a><a href="docs/%20Board%20Minutes.pdf%20" class="wp-block-file__button wp-element-button" download>Download</a></div>', $markup );
		$this->assertStringContainsString( '<!-- wp:file {"href":"docs\/Board%2FMinutes.pdf","textLinkHref":"docs\/Board%2FMinutes.pdf","fileName":"Board-Minutes.pdf","anchor":"encoded-separator-url-name"} -->', $markup );
		$this->assertStringContainsString( '<div class="wp-block-file" id="encoded-separator-url-name"><a href="docs/Board%2FMinutes.pdf">Board minutes archive</a><a href="docs/Board%2FMinutes.pdf" class="wp-block-file__button wp-element-button" download>Download</a></div>', $markup );
		$this->assertStringContainsString( '<!-- wp:file {"href":"docs\/Board%5CMinutes.pdf","textLinkHref":"docs\/Board%5CMinutes.pdf","fileName":"Board-Minutes.pdf","anchor":"encoded-backslash-url-name"} -->', $markup );
		$this->assertStringContainsString( '<div class="wp-block-file" id="encoded-backslash-url-name"><a href="docs/Board%5CMinutes.pdf">Board minutes backslash archive</a><a href="docs/Board%5CMinutes.pdf" class="wp-block-file__button wp-element-button" download>Download</a></div>', $markup );
		$this->assertStringContainsString( '<!-- wp:file {"href":"docs\/Board%3AMinutes.pdf","textLinkHref":"docs\/Board%3AMinutes.pdf","fileName":"Board-Minutes.pdf","anchor":"encoded-unsafe-url-name"} -->', $markup );
		$this->assertStringContainsString( '<div class="wp-block-file" id="encoded-unsafe-url-name"><a href="docs/Board%3AMinutes.pdf">Board minutes unsafe archive</a><a href="docs/Board%3AMinutes.pdf" class="wp-block-file__button wp-element-button" download>Download</a></div>', $markup );
		$this->assertStringContainsString( '<!-- wp:file {"href":"docs\/Board%3A%2FMinutes.pdf","textLinkHref":"docs\/Board%3A%2FMinutes.pdf","fileName":"Board-Minutes.pdf","anchor":"encoded-unsafe-run-url-name"} -->', $markup );
		$this->assertStringContainsString( '<div class="wp-block-file" id="encoded-unsafe-run-url-name"><a href="docs/Board%3A%2FMinutes.pdf">Board minutes unsafe run archive</a><a href="docs/Board%3A%2FMinutes.pdf" class="wp-block-file__button wp-element-button" download>Download</a></div>', $markup );
		$this->assertStringContainsString( '<!-- wp:file {"href":"docs\/%2E%2E%2FBoard%20Minutes.pdf","textLinkHref":"docs\/%2E%2E%2FBoard%20Minutes.pdf","fileName":"Board Minutes.pdf","anchor":"encoded-traversal-url-name"} -->', $markup );
		$this->assertStringContainsString( '<div class="wp-block-file" id="encoded-traversal-url-name"><a href="docs/%2E%2E%2FBoard%20Minutes.pdf">Board minutes traversal archive</a><a href="docs/%2E%2E%2FBoard%20Minutes.pdf" class="wp-block-file__button wp-element-button" download>Download</a></div>', $markup );
		$this->assertStringContainsString( '<!-- wp:file {"href":"docs\/Board%20Minutes.pdf%3A","textLinkHref":"docs\/Board%20Minutes.pdf%3A","fileName":"Board Minutes.pdf","anchor":"encoded-suffix-url-name"} -->', $markup );
		$this->assertStringContainsString( '<div class="wp-block-file" id="encoded-suffix-url-name"><a href="docs/Board%20Minutes.pdf%3A">Board minutes suffix archive</a><a href="docs/Board%20Minutes.pdf%3A" class="wp-block-file__button wp-element-button" download>Download</a></div>', $markup );
		$this->assertStringContainsString( '<!-- wp:file {"href":"docs\/Budget%202026.xlsx?download=1#sheet","textLinkHref":"docs\/Budget%202026.xlsx?download=1#sheet","fileName":"Budget 2026.xlsx","anchor":"budget-sheet"} -->', $markup );
		$this->assertStringContainsString( '<div class="wp-block-file" id="budget-sheet"><a href="docs/Budget%202026.xlsx?download=1#sheet">Budget sheet</a><a href="docs/Budget%202026.xlsx?download=1#sheet" class="wp-block-file__button wp-element-button" download>Download</a></div>', $markup );
		$this->assertStringContainsString( '<!-- wp:file {"href":"docs\/Launch%20Deck.PPTX","textLinkHref":"docs\/Launch%20Deck.PPTX","fileName":"Launch Deck.PPTX","anchor":"launch-deck"} -->', $markup );
		$this->assertStringContainsString( '<div class="wp-block-file" id="launch-deck"><a href="docs/Launch%20Deck.PPTX">Launch deck</a><a href="docs/Launch%20Deck.PPTX" class="wp-block-file__button wp-element-button" download>Download</a></div>', $markup );
		$this->assertStringContainsString( '<!-- wp:file {"href":"\/slides\/legacy-deck.ppt","textLinkHref":"\/slides\/legacy-deck.ppt","fileName":"legacy-deck.ppt","anchor":"ppt-download"} -->', $markup );
		$this->assertStringContainsString( '<div class="wp-block-file" id="ppt-download"><a href="/slides/legacy-deck.ppt">Legacy deck download</a><a href="/slides/legacy-deck.ppt" class="wp-block-file__button wp-element-button" download>Download</a></div>', $markup );
		$this->assertStringContainsString( '<!-- wp:file {"href":"\/docs\/source-readme.md","textLinkHref":"\/docs\/source-readme.md","fileName":"source-readme.md","anchor":"md-download"} -->', $markup );
		$this->assertStringContainsString( '<div class="wp-block-file" id="md-download"><a href="/docs/source-readme.md">Source README</a><a href="/docs/source-readme.md" class="wp-block-file__button wp-element-button" download>Download</a></div>', $markup );
		$this->assertStringContainsString( '<!-- wp:file {"href":"\/docs\/manual.markdown","textLinkHref":"\/docs\/manual.markdown","fileName":"manual.markdown","anchor":"markdown-manual"} -->', $markup );
		$this->assertStringContainsString( '<div class="wp-block-file" id="markdown-manual"><a href="/docs/manual.markdown">Markdown manual</a><a href="/docs/manual.markdown" class="wp-block-file__button wp-element-button" download>Download</a></div>', $markup );
		$this->assertStringContainsString( '<!-- wp:file {"href":"\/docs\/guide.mdown","textLinkHref":"\/docs\/guide.mdown","fileName":"guide.mdown","anchor":"mdown-guide"} -->', $markup );
		$this->assertStringContainsString( '<div class="wp-block-file" id="mdown-guide"><a href="/docs/guide.mdown">MDown guide</a><a href="/docs/guide.mdown" class="wp-block-file__button wp-element-button" download>Download</a></div>', $markup );
		$this->assertStringContainsString( '<!-- wp:file {"href":"\/logs\/import-run.log","textLinkHref":"\/logs\/import-run.log","fileName":"import-run.log","anchor":"log-download"} -->', $markup );
		$this->assertStringContainsString( '<div class="wp-block-file" id="log-download"><a href="/logs/import-run.log">Import run log</a><a href="/logs/import-run.log" class="wp-block-file__button wp-element-button" download>Download</a></div>', $markup );
		$this->assertStringContainsString( '<!-- wp:file {"href":"\/docs\/plain-readme.txt","textLinkHref":"\/docs\/plain-readme.txt","fileName":"plain-readme.txt","anchor":"txt-download"} -->', $markup );
		$this->assertStringContainsString( '<div class="wp-block-file" id="txt-download"><a href="/docs/plain-readme.txt">Plain text README</a><a href="/docs/plain-readme.txt" class="wp-block-file__button wp-element-button" download>Download</a></div>', $markup );
		$this->assertStringContainsString( '<!-- wp:file {"href":"\/docs\/field-notes.text","textLinkHref":"\/docs\/field-notes.text","fileName":"field-notes.text","anchor":"text-notes"} -->', $markup );
		$this->assertStringContainsString( '<div class="wp-block-file" id="text-notes"><a href="/docs/field-notes.text">Field notes</a><a href="/docs/field-notes.text" class="wp-block-file__button wp-element-button" download>Download</a></div>', $markup );
		$this->assertStringContainsString( '<!-- wp:file {"href":"\/books\/source-novel.epub","textLinkHref":"\/books\/source-novel.epub","fileName":"source-novel.epub","anchor":"epub-download"} -->', $markup );
		$this->assertStringContainsString( '<div class="wp-block-file" id="epub-download"><a href="/books/source-novel.epub">Source novel</a><a href="/books/source-novel.epub" class="wp-block-file__button wp-element-button" download>Download</a></div>', $markup );
		$this->assertStringContainsString( '<!-- wp:file {"href":"\/archives\/source-export.zip","textLinkHref":"\/archives\/source-export.zip","fileName":"source-export.zip","anchor":"zip-download"} -->', $markup );
		$this->assertStringContainsString( '<div class="wp-block-file" id="zip-download"><a href="/archives/source-export.zip">Source export archive</a><a href="/archives/source-export.zip" class="wp-block-file__button wp-element-button" download>Download</a></div>', $markup );
		$this->assertStringContainsString( '<!-- wp:file {"href":"\/archives\/source-export.tar","textLinkHref":"\/archives\/source-export.tar","fileName":"source-export.tar","anchor":"tar-download"} -->', $markup );
		$this->assertStringContainsString( '<div class="wp-block-file" id="tar-download"><a href="/archives/source-export.tar">Source tar archive</a><a href="/archives/source-export.tar" class="wp-block-file__button wp-element-button" download>Download</a></div>', $markup );
		$this->assertStringContainsString( '<!-- wp:file {"href":"\/archives\/source-export.tgz","textLinkHref":"\/archives\/source-export.tgz","fileName":"source-export.tgz","anchor":"tgz-download"} -->', $markup );
		$this->assertStringContainsString( '<div class="wp-block-file" id="tgz-download"><a href="/archives/source-export.tgz">Source TGZ archive</a><a href="/archives/source-export.tgz" class="wp-block-file__button wp-element-button" download>Download</a></div>', $markup );
		$this->assertStringContainsString( '<!-- wp:file {"href":"\/archives\/source-export.tar.gz","textLinkHref":"\/archives\/source-export.tar.gz","fileName":"source-export.tar.gz","anchor":"gzip-download"} -->', $markup );
		$this->assertStringContainsString( '<div class="wp-block-file" id="gzip-download"><a href="/archives/source-export.tar.gz">Source gzip archive</a><a href="/archives/source-export.tar.gz" class="wp-block-file__button wp-element-button" download>Download</a></div>', $markup );
		$this->assertStringContainsString( '<!-- wp:file {"href":"\/archives\/source-export.tar.bz2","textLinkHref":"\/archives\/source-export.tar.bz2","fileName":"source-export.tar.bz2","anchor":"bzip-download"} -->', $markup );
		$this->assertStringContainsString( '<div class="wp-block-file" id="bzip-download"><a href="/archives/source-export.tar.bz2">Source bzip archive</a><a href="/archives/source-export.tar.bz2" class="wp-block-file__button wp-element-button" download>Download</a></div>', $markup );
		$this->assertStringContainsString( '<!-- wp:file {"href":"\/archives\/source-export.tbz","textLinkHref":"\/archives\/source-export.tbz","fileName":"source-export.tbz","anchor":"tbz-download"} -->', $markup );
		$this->assertStringContainsString( '<div class="wp-block-file" id="tbz-download"><a href="/archives/source-export.tbz">Source TBZ archive</a><a href="/archives/source-export.tbz" class="wp-block-file__button wp-element-button" download>Download</a></div>', $markup );
		$this->assertStringContainsString( '<!-- wp:file {"href":"\/archives\/source-export.tbz2","textLinkHref":"\/archives\/source-export.tbz2","fileName":"source-export.tbz2","anchor":"tbz2-download"} -->', $markup );
		$this->assertStringContainsString( '<div class="wp-block-file" id="tbz2-download"><a href="/archives/source-export.tbz2">Source TBZ2 archive</a><a href="/archives/source-export.tbz2" class="wp-block-file__button wp-element-button" download>Download</a></div>', $markup );
		$this->assertStringContainsString( '<!-- wp:file {"href":"\/archives\/source-export.tar.xz","textLinkHref":"\/archives\/source-export.tar.xz","fileName":"source-export.tar.xz","anchor":"xz-download"} -->', $markup );
		$this->assertStringContainsString( '<div class="wp-block-file" id="xz-download"><a href="/archives/source-export.tar.xz">Source xz archive</a><a href="/archives/source-export.tar.xz" class="wp-block-file__button wp-element-button" download>Download</a></div>', $markup );
		$this->assertStringContainsString( '<!-- wp:file {"href":"\/archives\/source-export.tar.zst","textLinkHref":"\/archives\/source-export.tar.zst","fileName":"source-export.tar.zst","anchor":"zst-download"} -->', $markup );
		$this->assertStringContainsString( '<div class="wp-block-file" id="zst-download"><a href="/archives/source-export.tar.zst">Source zstd archive</a><a href="/archives/source-export.tar.zst" class="wp-block-file__button wp-element-button" download>Download</a></div>', $markup );
		$this->assertStringContainsString( '<!-- wp:file {"href":"\/data\/source-export.csv","textLinkHref":"\/data\/source-export.csv","fileName":"source-export.csv","anchor":"csv-download"} -->', $markup );
		$this->assertStringContainsString( '<div class="wp-block-file" id="csv-download"><a href="/data/source-export.csv">Source CSV export</a><a href="/data/source-export.csv" class="wp-block-file__button wp-element-button" download>Download</a></div>', $markup );
		$this->assertStringContainsString( '<!-- wp:file {"href":"\/exports\/site-export.wxr","textLinkHref":"\/exports\/site-export.wxr","fileName":"site-export.wxr","anchor":"wxr-export"} -->', $markup );
		$this->assertStringContainsString( '<div class="wp-block-file" id="wxr-export"><a href="/exports/site-export.wxr">Site export</a><a href="/exports/site-export.wxr" class="wp-block-file__button wp-element-button" download>Download</a></div>', $markup );
		$this->assertStringContainsString( '<!-- wp:file {"href":"\/exports\/site-export.xml","textLinkHref":"\/exports\/site-export.xml","fileName":"site-export.xml","anchor":"xml-export"} -->', $markup );
		$this->assertStringContainsString( '<div class="wp-block-file" id="xml-export"><a href="/exports/site-export.xml">XML export</a><a href="/exports/site-export.xml" class="wp-block-file__button wp-element-button" download>Download</a></div>', $markup );
		$this->assertStringContainsString( '<!-- wp:file {"href":"\/exports\/site-export.json","textLinkHref":"\/exports\/site-export.json","fileName":"site-export.json","anchor":"json-export"} -->', $markup );
		$this->assertStringContainsString( '<div class="wp-block-file" id="json-export"><a href="/exports/site-export.json">JSON export</a><a href="/exports/site-export.json" class="wp-block-file__button wp-element-button" download>Download</a></div>', $markup );
		$this->assertStringContainsString( '<!-- wp:file {"href":"\/exports\/site-export.jsonl","textLinkHref":"\/exports\/site-export.jsonl","fileName":"site-export.jsonl","anchor":"jsonl-export"} -->', $markup );
		$this->assertStringContainsString( '<div class="wp-block-file" id="jsonl-export"><a href="/exports/site-export.jsonl">JSONL export</a><a href="/exports/site-export.jsonl" class="wp-block-file__button wp-element-button" download>Download</a></div>', $markup );
		$this->assertStringContainsString( '<!-- wp:file {"href":"\/exports\/site-export.ndjson","textLinkHref":"\/exports\/site-export.ndjson","fileName":"site-export.ndjson","anchor":"ndjson-export"} -->', $markup );
		$this->assertStringContainsString( '<div class="wp-block-file" id="ndjson-export"><a href="/exports/site-export.ndjson">NDJSON export</a><a href="/exports/site-export.ndjson" class="wp-block-file__button wp-element-button" download>Download</a></div>', $markup );
		$this->assertStringContainsString( '<!-- wp:file {"href":"\/exports\/source-changes.diff","textLinkHref":"\/exports\/source-changes.diff","fileName":"source-changes.diff","anchor":"diff-export"} -->', $markup );
		$this->assertStringContainsString( '<div class="wp-block-file" id="diff-export"><a href="/exports/source-changes.diff">Diff export</a><a href="/exports/source-changes.diff" class="wp-block-file__button wp-element-button" download>Download</a></div>', $markup );
		$this->assertStringContainsString( '<!-- wp:file {"href":"\/exports\/source-changes.patch","textLinkHref":"\/exports\/source-changes.patch","fileName":"source-changes.patch","anchor":"patch-export"} -->', $markup );
		$this->assertStringContainsString( '<div class="wp-block-file" id="patch-export"><a href="/exports/source-changes.patch">Patch export</a><a href="/exports/source-changes.patch" class="wp-block-file__button wp-element-button" download>Download</a></div>', $markup );
		$this->assertStringContainsString( '<!-- wp:file {"href":"\/exports\/site-export.env","textLinkHref":"\/exports\/site-export.env","fileName":"site-export.env","anchor":"env-export"} -->', $markup );
		$this->assertStringContainsString( '<div class="wp-block-file" id="env-export"><a href="/exports/site-export.env">ENV export</a><a href="/exports/site-export.env" class="wp-block-file__button wp-element-button" download>Download</a></div>', $markup );
		$this->assertStringContainsString( '<!-- wp:file {"href":"\/exports\/event-calendar.ics","textLinkHref":"\/exports\/event-calendar.ics","fileName":"event-calendar.ics","anchor":"ics-export"} -->', $markup );
		$this->assertStringContainsString( '<div class="wp-block-file" id="ics-export"><a href="/exports/event-calendar.ics">Calendar export</a><a href="/exports/event-calendar.ics" class="wp-block-file__button wp-element-button" download>Download</a></div>', $markup );
		$this->assertStringContainsString( '<!-- wp:file {"href":"\/exports\/site-export.ini","textLinkHref":"\/exports\/site-export.ini","fileName":"site-export.ini","anchor":"ini-export"} -->', $markup );
		$this->assertStringContainsString( '<div class="wp-block-file" id="ini-export"><a href="/exports/site-export.ini">INI export</a><a href="/exports/site-export.ini" class="wp-block-file__button wp-element-button" download>Download</a></div>', $markup );
		$this->assertStringContainsString( '<!-- wp:file {"href":"\/exports\/yarn.lock","textLinkHref":"\/exports\/yarn.lock","fileName":"yarn.lock","anchor":"lock-export"} -->', $markup );
		$this->assertStringContainsString( '<div class="wp-block-file" id="lock-export"><a href="/exports/yarn.lock">Dependency lock export</a><a href="/exports/yarn.lock" class="wp-block-file__button wp-element-button" download>Download</a></div>', $markup );
		$this->assertStringContainsString( '<!-- wp:file {"href":"\/assets\/app.js.map","textLinkHref":"\/assets\/app.js.map","fileName":"app.js.map","anchor":"map-export"} -->', $markup );
		$this->assertStringContainsString( '<div class="wp-block-file" id="map-export"><a href="/assets/app.js.map">Source map export</a><a href="/assets/app.js.map" class="wp-block-file__button wp-element-button" download>Download</a></div>', $markup );
		$this->assertStringContainsString( '<!-- wp:file {"href":"\/assets\/site.webmanifest","textLinkHref":"\/assets\/site.webmanifest","fileName":"site.webmanifest","anchor":"webmanifest-export"} -->', $markup );
		$this->assertStringContainsString( '<div class="wp-block-file" id="webmanifest-export"><a href="/assets/site.webmanifest">Web app manifest export</a><a href="/assets/site.webmanifest" class="wp-block-file__button wp-element-button" download>Download</a></div>', $markup );
		$this->assertStringContainsString( '<!-- wp:file {"href":"\/assets\/theme-style.css","textLinkHref":"\/assets\/theme-style.css","fileName":"theme-style.css","anchor":"css-export"} -->', $markup );
		$this->assertStringContainsString( '<div class="wp-block-file" id="css-export"><a href="/assets/theme-style.css">Theme stylesheet export</a><a href="/assets/theme-style.css" class="wp-block-file__button wp-element-button" download>Download</a></div>', $markup );
		$this->assertStringContainsString( '<!-- wp:file {"href":"\/languages\/site-en_US.po","textLinkHref":"\/languages\/site-en_US.po","fileName":"site-en_US.po","anchor":"po-export"} -->', $markup );
		$this->assertStringContainsString( '<div class="wp-block-file" id="po-export"><a href="/languages/site-en_US.po">Translation PO export</a><a href="/languages/site-en_US.po" class="wp-block-file__button wp-element-button" download>Download</a></div>', $markup );
		$this->assertStringContainsString( '<!-- wp:file {"href":"\/languages\/site.pot","textLinkHref":"\/languages\/site.pot","fileName":"site.pot","anchor":"pot-export"} -->', $markup );
		$this->assertStringContainsString( '<div class="wp-block-file" id="pot-export"><a href="/languages/site.pot">Translation POT export</a><a href="/languages/site.pot" class="wp-block-file__button wp-element-button" download>Download</a></div>', $markup );
		$this->assertStringContainsString( '<!-- wp:file {"href":"\/languages\/site-en_US.mo","textLinkHref":"\/languages\/site-en_US.mo","fileName":"site-en_US.mo","anchor":"mo-export"} -->', $markup );
		$this->assertStringContainsString( '<div class="wp-block-file" id="mo-export"><a href="/languages/site-en_US.mo">Translation MO export</a><a href="/languages/site-en_US.mo" class="wp-block-file__button wp-element-button" download>Download</a></div>', $markup );
		$this->assertStringContainsString( '<!-- wp:file {"href":"\/exports\/site-export.properties","textLinkHref":"\/exports\/site-export.properties","fileName":"site-export.properties","anchor":"properties-export"} -->', $markup );
		$this->assertStringContainsString( '<div class="wp-block-file" id="properties-export"><a href="/exports/site-export.properties">Properties export</a><a href="/exports/site-export.properties" class="wp-block-file__button wp-element-button" download>Download</a></div>', $markup );
		$this->assertStringContainsString( '<!-- wp:file {"href":"\/exports\/site-export.toml","textLinkHref":"\/exports\/site-export.toml","fileName":"site-export.toml","anchor":"toml-export"} -->', $markup );
		$this->assertStringContainsString( '<div class="wp-block-file" id="toml-export"><a href="/exports/site-export.toml">TOML export</a><a href="/exports/site-export.toml" class="wp-block-file__button wp-element-button" download>Download</a></div>', $markup );
		$this->assertStringContainsString( '<!-- wp:file {"href":"\/exports\/site-export.yaml","textLinkHref":"\/exports\/site-export.yaml","fileName":"site-export.yaml","anchor":"yaml-export"} -->', $markup );
		$this->assertStringContainsString( '<div class="wp-block-file" id="yaml-export"><a href="/exports/site-export.yaml">YAML export</a><a href="/exports/site-export.yaml" class="wp-block-file__button wp-element-button" download>Download</a></div>', $markup );
		$this->assertStringContainsString( '<!-- wp:file {"href":"\/exports\/site-export.yml","textLinkHref":"\/exports\/site-export.yml","fileName":"site-export.yml","anchor":"yml-export"} -->', $markup );
		$this->assertStringContainsString( '<div class="wp-block-file" id="yml-export"><a href="/exports/site-export.yml">YML export</a><a href="/exports/site-export.yml" class="wp-block-file__button wp-element-button" download>Download</a></div>', $markup );
		$this->assertStringContainsString( '<!-- wp:file {"href":"\/exports\/site-export.sql","textLinkHref":"\/exports\/site-export.sql","fileName":"site-export.sql","anchor":"sql-export"} -->', $markup );
		$this->assertStringContainsString( '<div class="wp-block-file" id="sql-export"><a href="/exports/site-export.sql">SQL export</a><a href="/exports/site-export.sql" class="wp-block-file__button wp-element-button" download>Download</a></div>', $markup );
		$this->assertStringContainsString( '<!-- wp:file {"href":"\/exports\/site-export.db","textLinkHref":"\/exports\/site-export.db","fileName":"site-export.db","anchor":"sqlite-db-export"} -->', $markup );
		$this->assertStringContainsString( '<div class="wp-block-file" id="sqlite-db-export"><a href="/exports/site-export.db">SQLite DB export</a><a href="/exports/site-export.db" class="wp-block-file__button wp-element-button" download>Download</a></div>', $markup );
		$this->assertStringContainsString( '<!-- wp:file {"href":"\/exports\/site-export.sqlite","textLinkHref":"\/exports\/site-export.sqlite","fileName":"site-export.sqlite","anchor":"sqlite-file-export"} -->', $markup );
		$this->assertStringContainsString( '<div class="wp-block-file" id="sqlite-file-export"><a href="/exports/site-export.sqlite">SQLite file export</a><a href="/exports/site-export.sqlite" class="wp-block-file__button wp-element-button" download>Download</a></div>', $markup );
		$this->assertStringContainsString( '<!-- wp:file {"href":"\/exports\/site-export.sqlite3","textLinkHref":"\/exports\/site-export.sqlite3","fileName":"site-export.sqlite3","anchor":"sqlite-export"} -->', $markup );
		$this->assertStringContainsString( '<div class="wp-block-file" id="sqlite-export"><a href="/exports/site-export.sqlite3">SQLite export</a><a href="/exports/site-export.sqlite3" class="wp-block-file__button wp-element-button" download>Download</a></div>', $markup );
		$this->assertStringContainsString( '<!-- wp:file {"href":"\/files\/source-document.odt","textLinkHref":"\/files\/source-document.odt","fileName":"source-document.odt","anchor":"odt-download"} -->', $markup );
		$this->assertStringContainsString( '<div class="wp-block-file" id="odt-download"><a href="/files/source-document.odt">Source OpenDocument text</a><a href="/files/source-document.odt" class="wp-block-file__button wp-element-button" download>Download</a></div>', $markup );
		$this->assertStringContainsString( '<!-- wp:file {"href":"\/docs\/board-brief.docx","textLinkHref":"\/docs\/board-brief.docx","fileName":"board-brief.docx","anchor":"docx-download"} -->', $markup );
		$this->assertStringContainsString( '<div class="wp-block-file" id="docx-download"><a href="/docs/board-brief.docx">Board brief</a><a href="/docs/board-brief.docx" class="wp-block-file__button wp-element-button" download>Download</a></div>', $markup );
		$this->assertStringContainsString( '<!-- wp:file {"href":"\/docs\/legacy-memo.doc","textLinkHref":"\/docs\/legacy-memo.doc","fileName":"legacy-memo.doc","anchor":"doc-download"} -->', $markup );
		$this->assertStringContainsString( '<div class="wp-block-file" id="doc-download"><a href="/docs/legacy-memo.doc">Legacy memo download</a><a href="/docs/legacy-memo.doc" class="wp-block-file__button wp-element-button" download>Download</a></div>', $markup );
		$this->assertStringContainsString( '<!-- wp:file {"href":"\/sheets\/legacy-budget.xls","textLinkHref":"\/sheets\/legacy-budget.xls","fileName":"legacy-budget.xls","anchor":"xls-download"} -->', $markup );
		$this->assertStringContainsString( '<div class="wp-block-file" id="xls-download"><a href="/sheets/legacy-budget.xls">Legacy budget</a><a href="/sheets/legacy-budget.xls" class="wp-block-file__button wp-element-button" download>Download</a></div>', $markup );
		$this->assertStringContainsString( '<!-- wp:file {"href":"\/sheets\/source-ledger.ods","textLinkHref":"\/sheets\/source-ledger.ods","fileName":"source-ledger.ods","anchor":"ods-download"} -->', $markup );
		$this->assertStringContainsString( '<div class="wp-block-file" id="ods-download"><a href="/sheets/source-ledger.ods">Source ledger</a><a href="/sheets/source-ledger.ods" class="wp-block-file__button wp-element-button" download>Download</a></div>', $markup );
		$this->assertStringContainsString( '<!-- wp:file {"href":"\/slides\/source-deck.odp","textLinkHref":"\/slides\/source-deck.odp","fileName":"source-deck.odp","anchor":"odp-download"} -->', $markup );
		$this->assertStringContainsString( '<div class="wp-block-file" id="odp-download"><a href="/slides/source-deck.odp">Source presentation</a><a href="/slides/source-deck.odp" class="wp-block-file__button wp-element-button" download>Download</a></div>', $markup );
		$this->assertStringContainsString( '<!-- wp:file {"href":"\/slides\/source-keynote.key","textLinkHref":"\/slides\/source-keynote.key","fileName":"source-keynote.key","anchor":"key-download"} -->', $markup );
		$this->assertStringContainsString( '<div class="wp-block-file" id="key-download"><a href="/slides/source-keynote.key">Source Keynote deck</a><a href="/slides/source-keynote.key" class="wp-block-file__button wp-element-button" download>Download</a></div>', $markup );
		$this->assertStringContainsString( '<!-- wp:file {"href":"\/docs\/source-notes.rtf","textLinkHref":"\/docs\/source-notes.rtf","fileName":"source-notes.rtf","anchor":"rtf-download"} -->', $markup );
		$this->assertStringContainsString( '<div class="wp-block-file" id="rtf-download"><a href="/docs/source-notes.rtf">Source RTF notes</a><a href="/docs/source-notes.rtf" class="wp-block-file__button wp-element-button" download>Download</a></div>', $markup );
		$this->assertStringContainsString( '<!-- wp:file {"href":"\/download?file=99","textLinkHref":"\/download?file=99","fileName":"Board Packet.pdf","anchor":"signed-report"} -->', $markup );
		$this->assertStringContainsString( '<div class="wp-block-file" id="signed-report"><a href="/download?file=99">Board packet</a><a href="/download?file=99" class="wp-block-file__button wp-element-button" download="Board Packet.pdf">Download</a></div>', $markup );
		$this->assertStringContainsString( '<!-- wp:file {"href":"\/download?file=100","textLinkHref":"\/download?file=100","fileName":"Board Agenda.pdf","anchor":"spaced-download-name"} -->', $markup );
		$this->assertStringContainsString( '<div class="wp-block-file" id="spaced-download-name"><a href="/download?file=100">Board agenda</a><a href="/download?file=100" class="wp-block-file__button wp-element-button" download="Board Agenda.pdf">Download</a></div>', $markup );
		$this->assertStringContainsString( '<!-- wp:file {"href":"\/download?file=101","textLinkHref":"\/download?file=101","fileName":"Board-Agenda.pdf","anchor":"unsafe-download-name"} -->', $markup );
		$this->assertStringContainsString( '<div class="wp-block-file" id="unsafe-download-name"><a href="/download?file=101">Unsafe board agenda</a><a href="/download?file=101" class="wp-block-file__button wp-element-button" download="Board-Agenda.pdf">Download</a></div>', $markup );
		$this->assertStringContainsString( '<!-- wp:file {"href":"\/download?file=102","textLinkHref":"\/download?file=102","fileName":"Board Agenda.pdf","anchor":"encoded-traversal-download-name"} -->', $markup );
		$this->assertStringContainsString( '<div class="wp-block-file" id="encoded-traversal-download-name"><a href="/download?file=102">Traversal board agenda</a><a href="/download?file=102" class="wp-block-file__button wp-element-button" download="Board Agenda.pdf">Download</a></div>', $markup );
		$this->assertStringContainsString( '<!-- wp:file {"href":"\/download?file=103","textLinkHref":"\/download?file=103","fileName":"Board Agenda.pdf","anchor":"encoded-suffix-download-name"} -->', $markup );
		$this->assertStringContainsString( '<div class="wp-block-file" id="encoded-suffix-download-name"><a href="/download?file=103">Suffix board agenda</a><a href="/download?file=103" class="wp-block-file__button wp-element-button" download="Board Agenda.pdf">Download</a></div>', $markup );
		$this->assertStringContainsString( '<!-- wp:file {"href":"\/download?file=104","textLinkHref":"\/download?file=104","fileName":"Database Backup.sql","anchor":"sql-download-name"} -->', $markup );
		$this->assertStringContainsString( '<div class="wp-block-file" id="sql-download-name"><a href="/download?file=104">Database backup</a><a href="/download?file=104" class="wp-block-file__button wp-element-button" download="Database Backup.sql">Download</a></div>', $markup );
		$this->assertStringContainsString( '<!-- wp:file {"href":"\/download","textLinkHref":"\/download","fileName":"download","anchor":"generated-download"} -->', $markup );
		$this->assertStringContainsString( '<div class="wp-block-file" id="generated-download"><a href="/download">Generated export</a><a href="/download" class="wp-block-file__button wp-element-button" download>Download</a></div>', $markup );
		$this->assertStringContainsString( '<!-- wp:file {"href":"\/download","textLinkHref":"\/download","fileName":"download","anchor":"unsupported-download"} -->', $markup );
		$this->assertStringContainsString( '<div class="wp-block-file" id="unsupported-download"><a href="/download">Unsupported export</a><a href="/download" class="wp-block-file__button wp-element-button" download>Download</a></div>', $markup );
		$this->assertStringContainsString( '<!-- wp:file {"href":"\/download\/archive.bin","textLinkHref":"\/download\/archive.bin","fileName":"Annual Archive.zip","anchor":"supported-download-name"} -->', $markup );
		$this->assertStringContainsString( '<div class="wp-block-file" id="supported-download-name"><a href="/download/archive.bin">Supported download archive</a><a href="/download/archive.bin" class="wp-block-file__button wp-element-button" download="Annual Archive.zip">Download</a></div>', $markup );
		$this->assertStringContainsString( '<!-- wp:file {"href":"\/reports\/source-report.pdf","textLinkHref":"\/reports\/source-report.pdf","fileName":"source-report.pdf","anchor":"supported-url-unsupported-download"} -->', $markup );
		$this->assertStringContainsString( '<div class="wp-block-file" id="supported-url-unsupported-download"><a href="/reports/source-report.pdf">Supported source report</a><a href="/reports/source-report.pdf" class="wp-block-file__button wp-element-button" download>Download</a></div>', $markup );
		$this->assertStringContainsString( '<!-- wp:file {"href":"?export=1","textLinkHref":"?export=1","fileName":"download","anchor":"query-download"} -->', $markup );
		$this->assertStringContainsString( '<div class="wp-block-file" id="query-download"><a href="?export=1">Query export</a><a href="?export=1" class="wp-block-file__button wp-element-button" download>Download</a></div>', $markup );
		$this->assertStringContainsString( '<!-- wp:file {"href":"ftp:\/\/legacy.example.test\/exports\/site-export.wxr","textLinkHref":"ftp:\/\/legacy.example.test\/exports\/site-export.wxr","fileName":"site-export.wxr","anchor":"ftp-export"} -->', $markup );
		$this->assertStringContainsString( '<div class="wp-block-file" id="ftp-export"><a href="ftp://legacy.example.test/exports/site-export.wxr">Legacy FTP export</a><a href="ftp://legacy.example.test/exports/site-export.wxr" class="wp-block-file__button wp-element-button" download>Download</a></div>', $markup );
		$this->assertStringContainsString( '<!-- wp:file {"href":"\/\/legacy.example.test\/files\/source-export.pdf","textLinkHref":"\/\/legacy.example.test\/files\/source-export.pdf","fileName":"source-export.pdf","anchor":"protocol-export"} -->', $markup );
		$this->assertStringContainsString( '<div class="wp-block-file" id="protocol-export"><a href="//legacy.example.test/files/source-export.pdf">Protocol export</a><a href="//legacy.example.test/files/source-export.pdf" class="wp-block-file__button wp-element-button" download>Download</a></div>', $markup );
		$this->assertStringContainsString( '<!-- wp:file {"href":"\/reports\/latest","textLinkHref":"\/reports\/latest","fileName":"latest.pdf","anchor":"typed-report"} -->', $markup );
		$this->assertStringContainsString( '<div class="wp-block-file" id="typed-report"><a href="/reports/latest" type="application/pdf">Latest report</a><a href="/reports/latest" class="wp-block-file__button wp-element-button" download>Download</a></div>', $markup );
		$this->assertStringContainsString( '<!-- wp:file {"href":"\/reports\/charset-latest","textLinkHref":"\/reports\/charset-latest","fileName":"charset-latest.pdf","anchor":"typed-report-charset"} -->', $markup );
		$this->assertStringContainsString( '<div class="wp-block-file" id="typed-report-charset"><a href="/reports/charset-latest" type="application/pdf">Latest charset report</a><a href="/reports/charset-latest" class="wp-block-file__button wp-element-button" download>Download</a></div>', $markup );
		$this->assertStringContainsString( '<!-- wp:file {"href":"\/download\/archive.bin","textLinkHref":"\/download\/archive.bin","fileName":"archive.pdf","anchor":"typed-unsupported-url-mime"} -->', $markup );
		$this->assertStringContainsString( '<div class="wp-block-file" id="typed-unsupported-url-mime"><a href="/download/archive.bin" type="application/pdf">Archive PDF</a><a href="/download/archive.bin" class="wp-block-file__button wp-element-button" download>Download</a></div>', $markup );
		$this->assertStringContainsString( '<!-- wp:file {"href":"\/reports\/archive","textLinkHref":"\/reports\/archive","fileName":"archive.pdf","anchor":"typed-x-pdf"} -->', $markup );
		$this->assertStringContainsString( '<div class="wp-block-file" id="typed-x-pdf"><a href="/reports/archive" type="application/x-pdf">Archived report</a><a href="/reports/archive" class="wp-block-file__button wp-element-button" download>Download</a></div>', $markup );
		$this->assertStringContainsString( '<!-- wp:file {"href":"\/reports\/acrobat-copy","textLinkHref":"\/reports\/acrobat-copy","fileName":"acrobat-copy.pdf","anchor":"typed-acrobat"} -->', $markup );
		$this->assertStringContainsString( '<div class="wp-block-file" id="typed-acrobat"><a href="/reports/acrobat-copy" type="application/acrobat">Acrobat copy</a><a href="/reports/acrobat-copy" class="wp-block-file__button wp-element-button" download>Download</a></div>', $markup );
		$this->assertStringContainsString( '<!-- wp:file {"href":"\/files\/brief","textLinkHref":"\/files\/brief","fileName":"brief.docx","anchor":"typed-brief"} -->', $markup );
		$this->assertStringContainsString( '<div class="wp-block-file" id="typed-brief"><a href="/files/brief" type="application/vnd.openxmlformats-officedocument.wordprocessingml.document">Brief</a><a href="/files/brief" class="wp-block-file__button wp-element-button" download>Download</a></div>', $markup );
		$this->assertStringContainsString( '<!-- wp:file {"href":"\/sheets\/workbook","textLinkHref":"\/sheets\/workbook","fileName":"workbook.xlsx","anchor":"typed-workbook"} -->', $markup );
		$this->assertStringContainsString( '<div class="wp-block-file" id="typed-workbook"><a href="/sheets/workbook" type="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet">Workbook</a><a href="/sheets/workbook" class="wp-block-file__button wp-element-button" download>Download</a></div>', $markup );
		$this->assertStringContainsString( '<!-- wp:file {"href":"\/files\/open-document","textLinkHref":"\/files\/open-document","fileName":"open-document.odt","anchor":"typed-open-document"} -->', $markup );
		$this->assertStringContainsString( '<div class="wp-block-file" id="typed-open-document"><a href="/files/open-document" type="application/vnd.oasis.opendocument.text">OpenDocument text</a><a href="/files/open-document" class="wp-block-file__button wp-element-button" download>Download</a></div>', $markup );
		$this->assertStringContainsString( '<!-- wp:file {"href":"\/sheets\/open-spreadsheet","textLinkHref":"\/sheets\/open-spreadsheet","fileName":"open-spreadsheet.ods","anchor":"typed-open-spreadsheet"} -->', $markup );
		$this->assertStringContainsString( '<div class="wp-block-file" id="typed-open-spreadsheet"><a href="/sheets/open-spreadsheet" type="application/vnd.oasis.opendocument.spreadsheet">OpenDocument spreadsheet</a><a href="/sheets/open-spreadsheet" class="wp-block-file__button wp-element-button" download>Download</a></div>', $markup );
		$this->assertStringContainsString( '<!-- wp:file {"href":"\/slides\/open-presentation","textLinkHref":"\/slides\/open-presentation","fileName":"open-presentation.odp","anchor":"typed-open-presentation"} -->', $markup );
		$this->assertStringContainsString( '<div class="wp-block-file" id="typed-open-presentation"><a href="/slides/open-presentation" type="application/vnd.oasis.opendocument.presentation">OpenDocument presentation</a><a href="/slides/open-presentation" class="wp-block-file__button wp-element-button" download>Download</a></div>', $markup );
		$this->assertStringContainsString( '<!-- wp:file {"href":"\/slides\/presentation","textLinkHref":"\/slides\/presentation","fileName":"presentation.pptx","anchor":"typed-presentation"} -->', $markup );
		$this->assertStringContainsString( '<div class="wp-block-file" id="typed-presentation"><a href="/slides/presentation" type="application/vnd.openxmlformats-officedocument.presentationml.presentation">Presentation</a><a href="/slides/presentation" class="wp-block-file__button wp-element-button" download>Download</a></div>', $markup );
		$this->assertStringContainsString( '<!-- wp:file {"href":"\/slides\/keynote","textLinkHref":"\/slides\/keynote","fileName":"keynote.key","anchor":"typed-keynote"} -->', $markup );
		$this->assertStringContainsString( '<div class="wp-block-file" id="typed-keynote"><a href="/slides/keynote" type="application/vnd.apple.keynote">Keynote</a><a href="/slides/keynote" class="wp-block-file__button wp-element-button" download>Download</a></div>', $markup );
		$this->assertStringContainsString( '<!-- wp:file {"href":"\/archives\/export","textLinkHref":"\/archives\/export","fileName":"export.zip","anchor":"typed-archive"} -->', $markup );
		$this->assertStringContainsString( '<div class="wp-block-file" id="typed-archive"><a href="/archives/export" type="application/x-zip-compressed">Export archive</a><a href="/archives/export" class="wp-block-file__button wp-element-button" download>Download</a></div>', $markup );
		$this->assertStringContainsString( '<!-- wp:file {"href":"\/archives\/bundle","textLinkHref":"\/archives\/bundle","fileName":"bundle.zip","anchor":"typed-bundle"} -->', $markup );
		$this->assertStringContainsString( '<div class="wp-block-file" id="typed-bundle"><a href="/archives/bundle" type="application/x-zip">Bundle archive</a><a href="/archives/bundle" class="wp-block-file__button wp-element-button" download>Download</a></div>', $markup );
		$this->assertStringContainsString( '<!-- wp:file {"href":"\/archives\/standard-zip","textLinkHref":"\/archives\/standard-zip","fileName":"standard-zip.zip","anchor":"typed-zip"} -->', $markup );
		$this->assertStringContainsString( '<div class="wp-block-file" id="typed-zip"><a href="/archives/standard-zip" type="application/zip">Standard ZIP archive</a><a href="/archives/standard-zip" class="wp-block-file__button wp-element-button" download>Download</a></div>', $markup );
		$this->assertStringContainsString( '<!-- wp:file {"href":"\/archives\/tar-export","textLinkHref":"\/archives\/tar-export","fileName":"tar-export.tar","anchor":"typed-x-tar"} -->', $markup );
		$this->assertStringContainsString( '<div class="wp-block-file" id="typed-x-tar"><a href="/archives/tar-export" type="application/x-tar">Tar archive</a><a href="/archives/tar-export" class="wp-block-file__button wp-element-button" download>Download</a></div>', $markup );
		$this->assertStringContainsString( '<!-- wp:file {"href":"\/archives\/gzip-export","textLinkHref":"\/archives\/gzip-export","fileName":"gzip-export.gz","anchor":"typed-gzip"} -->', $markup );
		$this->assertStringContainsString( '<div class="wp-block-file" id="typed-gzip"><a href="/archives/gzip-export" type="application/gzip">Gzip archive</a><a href="/archives/gzip-export" class="wp-block-file__button wp-element-button" download>Download</a></div>', $markup );
		$this->assertStringContainsString( '<!-- wp:file {"href":"\/archives\/legacy-gzip-export","textLinkHref":"\/archives\/legacy-gzip-export","fileName":"legacy-gzip-export.gz","anchor":"typed-x-gzip"} -->', $markup );
		$this->assertStringContainsString( '<div class="wp-block-file" id="typed-x-gzip"><a href="/archives/legacy-gzip-export" type="application/x-gzip">Legacy gzip archive</a><a href="/archives/legacy-gzip-export" class="wp-block-file__button wp-element-button" download>Download</a></div>', $markup );
		$this->assertStringContainsString( '<!-- wp:file {"href":"\/archives\/bzip-export","textLinkHref":"\/archives\/bzip-export","fileName":"bzip-export.bz2","anchor":"typed-x-bzip2"} -->', $markup );
		$this->assertStringContainsString( '<div class="wp-block-file" id="typed-x-bzip2"><a href="/archives/bzip-export" type="application/x-bzip2">Bzip archive</a><a href="/archives/bzip-export" class="wp-block-file__button wp-element-button" download>Download</a></div>', $markup );
		$this->assertStringContainsString( '<!-- wp:file {"href":"\/archives\/xz-export","textLinkHref":"\/archives\/xz-export","fileName":"xz-export.xz","anchor":"typed-x-xz"} -->', $markup );
		$this->assertStringContainsString( '<div class="wp-block-file" id="typed-x-xz"><a href="/archives/xz-export" type="application/x-xz">XZ archive</a><a href="/archives/xz-export" class="wp-block-file__button wp-element-button" download>Download</a></div>', $markup );
		$this->assertStringContainsString( '<!-- wp:file {"href":"\/archives\/zstd-export","textLinkHref":"\/archives\/zstd-export","fileName":"zstd-export.zst","anchor":"typed-zstd"} -->', $markup );
		$this->assertStringContainsString( '<div class="wp-block-file" id="typed-zstd"><a href="/archives/zstd-export" type="application/zstd">Zstandard archive</a><a href="/archives/zstd-export" class="wp-block-file__button wp-element-button" download>Download</a></div>', $markup );
		$this->assertStringContainsString( '<!-- wp:file {"href":"\/archives\/legacy-zstd-export","textLinkHref":"\/archives\/legacy-zstd-export","fileName":"legacy-zstd-export.zst","anchor":"typed-x-zstd"} -->', $markup );
		$this->assertStringContainsString( '<div class="wp-block-file" id="typed-x-zstd"><a href="/archives/legacy-zstd-export" type="application/x-zstd">Legacy zstandard archive</a><a href="/archives/legacy-zstd-export" class="wp-block-file__button wp-element-button" download>Download</a></div>', $markup );
		$this->assertStringContainsString( '<!-- wp:file {"href":"\/data\/export","textLinkHref":"\/data\/export","fileName":"export.csv","anchor":"typed-csv"} -->', $markup );
		$this->assertStringContainsString( '<div class="wp-block-file" id="typed-csv"><a href="/data/export" type="application/csv">CSV export</a><a href="/data/export" class="wp-block-file__button wp-element-button" download>Download</a></div>', $markup );
		$this->assertStringContainsString( '<!-- wp:file {"href":"\/data\/text-export","textLinkHref":"\/data\/text-export","fileName":"text-export.csv","anchor":"typed-text-csv"} -->', $markup );
		$this->assertStringContainsString( '<div class="wp-block-file" id="typed-text-csv"><a href="/data/text-export" type="text/csv">Text CSV export</a><a href="/data/text-export" class="wp-block-file__button wp-element-button" download>Download</a></div>', $markup );
		$this->assertStringContainsString( '<!-- wp:file {"href":"\/data\/legacy-export","textLinkHref":"\/data\/legacy-export","fileName":"legacy-export.csv","anchor":"typed-legacy-csv"} -->', $markup );
		$this->assertStringContainsString( '<div class="wp-block-file" id="typed-legacy-csv"><a href="/data/legacy-export" type="text/x-csv">Legacy CSV export</a><a href="/data/legacy-export" class="wp-block-file__button wp-element-button" download>Download</a></div>', $markup );
		$this->assertStringContainsString( '<!-- wp:file {"href":"\/exports\/latest","textLinkHref":"\/exports\/latest","fileName":"latest.xml","anchor":"typed-xml"} -->', $markup );
		$this->assertStringContainsString( '<div class="wp-block-file" id="typed-xml"><a href="/exports/latest" type="application/xml">Latest XML export</a><a href="/exports/latest" class="wp-block-file__button wp-element-button" download>Download</a></div>', $markup );
		$this->assertStringContainsString( '<!-- wp:file {"href":"\/exports\/json-latest","textLinkHref":"\/exports\/json-latest","fileName":"json-latest.json","anchor":"typed-json"} -->', $markup );
		$this->assertStringContainsString( '<div class="wp-block-file" id="typed-json"><a href="/exports/json-latest" type="application/json">Latest JSON export</a><a href="/exports/json-latest" class="wp-block-file__button wp-element-button" download>Download</a></div>', $markup );
		$this->assertStringContainsString( '<!-- wp:file {"href":"\/exports\/ndjson-latest","textLinkHref":"\/exports\/ndjson-latest","fileName":"ndjson-latest.ndjson","anchor":"typed-ndjson"} -->', $markup );
		$this->assertStringContainsString( '<div class="wp-block-file" id="typed-ndjson"><a href="/exports/ndjson-latest" type="application/x-ndjson">Latest NDJSON export</a><a href="/exports/ndjson-latest" class="wp-block-file__button wp-element-button" download>Download</a></div>', $markup );
		$this->assertStringContainsString( '<!-- wp:file {"href":"\/exports\/text-ndjson-latest","textLinkHref":"\/exports\/text-ndjson-latest","fileName":"text-ndjson-latest.ndjson","anchor":"typed-text-ndjson"} -->', $markup );
		$this->assertStringContainsString( '<div class="wp-block-file" id="typed-text-ndjson"><a href="/exports/text-ndjson-latest" type="text/x-ndjson">Latest text NDJSON export</a><a href="/exports/text-ndjson-latest" class="wp-block-file__button wp-element-button" download>Download</a></div>', $markup );
		$this->assertStringContainsString( '<!-- wp:file {"href":"\/exports\/diff-latest","textLinkHref":"\/exports\/diff-latest","fileName":"diff-latest.diff","anchor":"typed-diff"} -->', $markup );
		$this->assertStringContainsString( '<div class="wp-block-file" id="typed-diff"><a href="/exports/diff-latest" type="text/x-diff">Latest diff export</a><a href="/exports/diff-latest" class="wp-block-file__button wp-element-button" download>Download</a></div>', $markup );
		$this->assertStringContainsString( '<!-- wp:file {"href":"\/exports\/patch-latest","textLinkHref":"\/exports\/patch-latest","fileName":"patch-latest.patch","anchor":"typed-patch"} -->', $markup );
		$this->assertStringContainsString( '<div class="wp-block-file" id="typed-patch"><a href="/exports/patch-latest" type="text/x-patch">Latest patch export</a><a href="/exports/patch-latest" class="wp-block-file__button wp-element-button" download>Download</a></div>', $markup );
		$this->assertStringContainsString( '<!-- wp:file {"href":"\/exports\/env-latest","textLinkHref":"\/exports\/env-latest","fileName":"env-latest.env","anchor":"typed-env"} -->', $markup );
		$this->assertStringContainsString( '<div class="wp-block-file" id="typed-env"><a href="/exports/env-latest" type="application/x-env">Latest ENV export</a><a href="/exports/env-latest" class="wp-block-file__button wp-element-button" download>Download</a></div>', $markup );
		$this->assertStringContainsString( '<!-- wp:file {"href":"\/exports\/text-env-latest","textLinkHref":"\/exports\/text-env-latest","fileName":"text-env-latest.env","anchor":"typed-text-env"} -->', $markup );
		$this->assertStringContainsString( '<div class="wp-block-file" id="typed-text-env"><a href="/exports/text-env-latest" type="text/x-env">Latest text ENV export</a><a href="/exports/text-env-latest" class="wp-block-file__button wp-element-button" download>Download</a></div>', $markup );
		$this->assertStringContainsString( '<!-- wp:file {"href":"\/exports\/calendar-latest","textLinkHref":"\/exports\/calendar-latest","fileName":"calendar-latest.ics","anchor":"typed-calendar"} -->', $markup );
		$this->assertStringContainsString( '<div class="wp-block-file" id="typed-calendar"><a href="/exports/calendar-latest" type="text/calendar">Latest calendar export</a><a href="/exports/calendar-latest" class="wp-block-file__button wp-element-button" download>Download</a></div>', $markup );
		$this->assertStringContainsString( '<!-- wp:file {"href":"\/exports\/ics-latest","textLinkHref":"\/exports\/ics-latest","fileName":"ics-latest.ics","anchor":"typed-ics"} -->', $markup );
		$this->assertStringContainsString( '<div class="wp-block-file" id="typed-ics"><a href="/exports/ics-latest" type="application/ics">Latest ICS export</a><a href="/exports/ics-latest" class="wp-block-file__button wp-element-button" download>Download</a></div>', $markup );
		$this->assertStringContainsString( '<!-- wp:file {"href":"\/exports\/ini-latest","textLinkHref":"\/exports\/ini-latest","fileName":"ini-latest.ini","anchor":"typed-ini"} -->', $markup );
		$this->assertStringContainsString( '<div class="wp-block-file" id="typed-ini"><a href="/exports/ini-latest" type="application/ini">Latest INI export</a><a href="/exports/ini-latest" class="wp-block-file__button wp-element-button" download>Download</a></div>', $markup );
		$this->assertStringContainsString( '<!-- wp:file {"href":"\/exports\/text-ini-latest","textLinkHref":"\/exports\/text-ini-latest","fileName":"text-ini-latest.ini","anchor":"typed-text-ini"} -->', $markup );
		$this->assertStringContainsString( '<div class="wp-block-file" id="typed-text-ini"><a href="/exports/text-ini-latest" type="text/x-ini">Latest text INI export</a><a href="/exports/text-ini-latest" class="wp-block-file__button wp-element-button" download>Download</a></div>', $markup );
		$this->assertStringContainsString( '<!-- wp:file {"href":"\/exports\/lock-latest","textLinkHref":"\/exports\/lock-latest","fileName":"lock-latest.lock","anchor":"typed-lock"} -->', $markup );
		$this->assertStringContainsString( '<div class="wp-block-file" id="typed-lock"><a href="/exports/lock-latest" type="application/x-lock">Latest dependency lock export</a><a href="/exports/lock-latest" class="wp-block-file__button wp-element-button" download>Download</a></div>', $markup );
		$this->assertStringContainsString( '<!-- wp:file {"href":"\/exports\/text-lock-latest","textLinkHref":"\/exports\/text-lock-latest","fileName":"text-lock-latest.lock","anchor":"typed-text-lock"} -->', $markup );
		$this->assertStringContainsString( '<div class="wp-block-file" id="typed-text-lock"><a href="/exports/text-lock-latest" type="text/x-lock">Latest text dependency lock export</a><a href="/exports/text-lock-latest" class="wp-block-file__button wp-element-button" download>Download</a></div>', $markup );
		$this->assertStringContainsString( '<!-- wp:file {"href":"\/assets\/app-source-map","textLinkHref":"\/assets\/app-source-map","fileName":"app-source-map.map","anchor":"typed-source-map"} -->', $markup );
		$this->assertStringContainsString( '<div class="wp-block-file" id="typed-source-map"><a href="/assets/app-source-map" type="application/source-map">Latest source map export</a><a href="/assets/app-source-map" class="wp-block-file__button wp-element-button" download>Download</a></div>', $markup );
		$this->assertStringContainsString( '<!-- wp:file {"href":"\/assets\/text-source-map","textLinkHref":"\/assets\/text-source-map","fileName":"text-source-map.map","anchor":"typed-text-source-map"} -->', $markup );
		$this->assertStringContainsString( '<div class="wp-block-file" id="typed-text-source-map"><a href="/assets/text-source-map" type="text/x-source-map">Latest text source map export</a><a href="/assets/text-source-map" class="wp-block-file__button wp-element-button" download>Download</a></div>', $markup );
		$this->assertStringContainsString( '<!-- wp:file {"href":"\/assets\/manifest-latest","textLinkHref":"\/assets\/manifest-latest","fileName":"manifest-latest.webmanifest","anchor":"typed-webmanifest"} -->', $markup );
		$this->assertStringContainsString( '<div class="wp-block-file" id="typed-webmanifest"><a href="/assets/manifest-latest" type="application/manifest+json">Latest web app manifest export</a><a href="/assets/manifest-latest" class="wp-block-file__button wp-element-button" download>Download</a></div>', $markup );
		$this->assertStringContainsString( '<!-- wp:file {"href":"\/assets\/css-latest","textLinkHref":"\/assets\/css-latest","fileName":"css-latest.css","anchor":"typed-css"} -->', $markup );
		$this->assertStringContainsString( '<div class="wp-block-file" id="typed-css"><a href="/assets/css-latest" type="text/css">Latest CSS export</a><a href="/assets/css-latest" class="wp-block-file__button wp-element-button" download>Download</a></div>', $markup );
		$this->assertStringContainsString( '<!-- wp:file {"href":"\/languages\/po-latest","textLinkHref":"\/languages\/po-latest","fileName":"po-latest.po","anchor":"typed-po"} -->', $markup );
		$this->assertStringContainsString( '<div class="wp-block-file" id="typed-po"><a href="/languages/po-latest" type="text/x-gettext-translation">Latest PO export</a><a href="/languages/po-latest" class="wp-block-file__button wp-element-button" download>Download</a></div>', $markup );
		$this->assertStringContainsString( '<!-- wp:file {"href":"\/languages\/pot-latest","textLinkHref":"\/languages\/pot-latest","fileName":"pot-latest.pot","anchor":"typed-pot"} -->', $markup );
		$this->assertStringContainsString( '<div class="wp-block-file" id="typed-pot"><a href="/languages/pot-latest" type="text/x-gettext-translation-template">Latest POT export</a><a href="/languages/pot-latest" class="wp-block-file__button wp-element-button" download>Download</a></div>', $markup );
		$this->assertStringContainsString( '<!-- wp:file {"href":"\/languages\/mo-latest","textLinkHref":"\/languages\/mo-latest","fileName":"mo-latest.mo","anchor":"typed-mo"} -->', $markup );
		$this->assertStringContainsString( '<div class="wp-block-file" id="typed-mo"><a href="/languages/mo-latest" type="application/x-gettext">Latest MO export</a><a href="/languages/mo-latest" class="wp-block-file__button wp-element-button" download>Download</a></div>', $markup );
		$this->assertStringContainsString( '<!-- wp:file {"href":"\/exports\/properties-latest","textLinkHref":"\/exports\/properties-latest","fileName":"properties-latest.properties","anchor":"typed-properties"} -->', $markup );
		$this->assertStringContainsString( '<div class="wp-block-file" id="typed-properties"><a href="/exports/properties-latest" type="application/x-java-properties">Latest properties export</a><a href="/exports/properties-latest" class="wp-block-file__button wp-element-button" download>Download</a></div>', $markup );
		$this->assertStringContainsString( '<!-- wp:file {"href":"\/exports\/text-properties-latest","textLinkHref":"\/exports\/text-properties-latest","fileName":"text-properties-latest.properties","anchor":"typed-text-properties"} -->', $markup );
		$this->assertStringContainsString( '<div class="wp-block-file" id="typed-text-properties"><a href="/exports/text-properties-latest" type="text/x-java-properties">Latest text properties export</a><a href="/exports/text-properties-latest" class="wp-block-file__button wp-element-button" download>Download</a></div>', $markup );
		$this->assertStringContainsString( '<!-- wp:file {"href":"\/exports\/toml-latest","textLinkHref":"\/exports\/toml-latest","fileName":"toml-latest.toml","anchor":"typed-toml"} -->', $markup );
		$this->assertStringContainsString( '<div class="wp-block-file" id="typed-toml"><a href="/exports/toml-latest" type="application/toml">Latest TOML export</a><a href="/exports/toml-latest" class="wp-block-file__button wp-element-button" download>Download</a></div>', $markup );
		$this->assertStringContainsString( '<!-- wp:file {"href":"\/exports\/text-toml-latest","textLinkHref":"\/exports\/text-toml-latest","fileName":"text-toml-latest.toml","anchor":"typed-text-toml"} -->', $markup );
		$this->assertStringContainsString( '<div class="wp-block-file" id="typed-text-toml"><a href="/exports/text-toml-latest" type="text/x-toml">Latest text TOML export</a><a href="/exports/text-toml-latest" class="wp-block-file__button wp-element-button" download>Download</a></div>', $markup );
		$this->assertStringContainsString( '<!-- wp:file {"href":"\/exports\/yaml-latest","textLinkHref":"\/exports\/yaml-latest","fileName":"yaml-latest.yaml","anchor":"typed-yaml"} -->', $markup );
		$this->assertStringContainsString( '<div class="wp-block-file" id="typed-yaml"><a href="/exports/yaml-latest" type="application/yaml">Latest YAML export</a><a href="/exports/yaml-latest" class="wp-block-file__button wp-element-button" download>Download</a></div>', $markup );
		$this->assertStringContainsString( '<!-- wp:file {"href":"\/exports\/text-yaml-latest","textLinkHref":"\/exports\/text-yaml-latest","fileName":"text-yaml-latest.yaml","anchor":"typed-text-yaml"} -->', $markup );
		$this->assertStringContainsString( '<div class="wp-block-file" id="typed-text-yaml"><a href="/exports/text-yaml-latest" type="text/x-yaml">Latest text YAML export</a><a href="/exports/text-yaml-latest" class="wp-block-file__button wp-element-button" download>Download</a></div>', $markup );
		$this->assertStringContainsString( '<!-- wp:file {"href":"\/exports\/sql-latest","textLinkHref":"\/exports\/sql-latest","fileName":"sql-latest.sql","anchor":"typed-sql"} -->', $markup );
		$this->assertStringContainsString( '<div class="wp-block-file" id="typed-sql"><a href="/exports/sql-latest" type="application/sql">Latest SQL export</a><a href="/exports/sql-latest" class="wp-block-file__button wp-element-button" download>Download</a></div>', $markup );
		$this->assertStringContainsString( '<!-- wp:file {"href":"\/exports\/legacy-sql-latest","textLinkHref":"\/exports\/legacy-sql-latest","fileName":"legacy-sql-latest.sql","anchor":"typed-x-sql"} -->', $markup );
		$this->assertStringContainsString( '<div class="wp-block-file" id="typed-x-sql"><a href="/exports/legacy-sql-latest" type="application/x-sql">Latest legacy SQL export</a><a href="/exports/legacy-sql-latest" class="wp-block-file__button wp-element-button" download>Download</a></div>', $markup );
		$this->assertStringContainsString( '<!-- wp:file {"href":"\/exports\/text-sql-latest","textLinkHref":"\/exports\/text-sql-latest","fileName":"text-sql-latest.sql","anchor":"typed-text-sql"} -->', $markup );
		$this->assertStringContainsString( '<div class="wp-block-file" id="typed-text-sql"><a href="/exports/text-sql-latest" type="text/x-sql">Latest text SQL export</a><a href="/exports/text-sql-latest" class="wp-block-file__button wp-element-button" download>Download</a></div>', $markup );
		$this->assertStringContainsString( '<!-- wp:file {"href":"\/exports\/sqlite-latest","textLinkHref":"\/exports\/sqlite-latest","fileName":"sqlite-latest.sqlite3","anchor":"typed-sqlite"} -->', $markup );
		$this->assertStringContainsString( '<div class="wp-block-file" id="typed-sqlite"><a href="/exports/sqlite-latest" type="application/vnd.sqlite3">Latest SQLite export</a><a href="/exports/sqlite-latest" class="wp-block-file__button wp-element-button" download>Download</a></div>', $markup );
		$this->assertStringContainsString( '<!-- wp:file {"href":"\/exports\/legacy-sqlite-file-latest","textLinkHref":"\/exports\/legacy-sqlite-file-latest","fileName":"legacy-sqlite-file-latest.sqlite","anchor":"typed-x-sqlite"} -->', $markup );
		$this->assertStringContainsString( '<div class="wp-block-file" id="typed-x-sqlite"><a href="/exports/legacy-sqlite-file-latest" type="application/x-sqlite">Latest legacy SQLite file export</a><a href="/exports/legacy-sqlite-file-latest" class="wp-block-file__button wp-element-button" download>Download</a></div>', $markup );
		$this->assertStringContainsString( '<!-- wp:file {"href":"\/exports\/legacy-sqlite-latest","textLinkHref":"\/exports\/legacy-sqlite-latest","fileName":"legacy-sqlite-latest.sqlite3","anchor":"typed-x-sqlite3"} -->', $markup );
		$this->assertStringContainsString( '<div class="wp-block-file" id="typed-x-sqlite3"><a href="/exports/legacy-sqlite-latest" type="application/x-sqlite3">Latest legacy SQLite export</a><a href="/exports/legacy-sqlite-latest" class="wp-block-file__button wp-element-button" download>Download</a></div>', $markup );
		$this->assertStringContainsString( '<!-- wp:file {"href":"\/exports\/text-latest","textLinkHref":"\/exports\/text-latest","fileName":"text-latest.xml","anchor":"typed-text-xml"} -->', $markup );
		$this->assertStringContainsString( '<div class="wp-block-file" id="typed-text-xml"><a href="/exports/text-latest" type="text/xml">Latest text XML export</a><a href="/exports/text-latest" class="wp-block-file__button wp-element-button" download>Download</a></div>', $markup );
		$this->assertStringContainsString( '<!-- wp:file {"href":"\/exports\/rss-latest","textLinkHref":"\/exports\/rss-latest","fileName":"rss-latest.xml","anchor":"typed-rss-xml"} -->', $markup );
		$this->assertStringContainsString( '<div class="wp-block-file" id="typed-rss-xml"><a href="/exports/rss-latest" type="application/rss+xml">Latest RSS XML export</a><a href="/exports/rss-latest" class="wp-block-file__button wp-element-button" download>Download</a></div>', $markup );
		$this->assertStringContainsString( '<!-- wp:file {"href":"\/exports\/atom-latest","textLinkHref":"\/exports\/atom-latest","fileName":"atom-latest.xml","anchor":"typed-atom-xml"} -->', $markup );
		$this->assertStringContainsString( '<div class="wp-block-file" id="typed-atom-xml"><a href="/exports/atom-latest" type="application/atom+xml">Latest Atom XML export</a><a href="/exports/atom-latest" class="wp-block-file__button wp-element-button" download>Download</a></div>', $markup );
		$this->assertStringContainsString( '<!-- wp:file {"href":"\/exports\/rdf-latest","textLinkHref":"\/exports\/rdf-latest","fileName":"rdf-latest.xml","anchor":"typed-rdf-xml"} -->', $markup );
		$this->assertStringContainsString( '<div class="wp-block-file" id="typed-rdf-xml"><a href="/exports/rdf-latest" type="application/rdf+xml">Latest RDF XML export</a><a href="/exports/rdf-latest" class="wp-block-file__button wp-element-button" download>Download</a></div>', $markup );
		$this->assertStringContainsString( '<!-- wp:file {"href":"\/sheets\/monthly","textLinkHref":"\/sheets\/monthly","fileName":"monthly.xls","anchor":"typed-excel"} -->', $markup );
		$this->assertStringContainsString( '<div class="wp-block-file" id="typed-excel"><a href="/sheets/monthly" type="application/excel">Monthly sheet</a><a href="/sheets/monthly" class="wp-block-file__button wp-element-button" download>Download</a></div>', $markup );
		$this->assertStringContainsString( '<!-- wp:file {"href":"\/sheets\/archive","textLinkHref":"\/sheets\/archive","fileName":"archive.xls","anchor":"typed-x-excel"} -->', $markup );
		$this->assertStringContainsString( '<div class="wp-block-file" id="typed-x-excel"><a href="/sheets/archive" type="application/x-excel">Archived sheet</a><a href="/sheets/archive" class="wp-block-file__button wp-element-button" download>Download</a></div>', $markup );
		$this->assertStringContainsString( '<!-- wp:file {"href":"\/sheets\/legacy","textLinkHref":"\/sheets\/legacy","fileName":"legacy.xls","anchor":"typed-x-msexcel"} -->', $markup );
		$this->assertStringContainsString( '<div class="wp-block-file" id="typed-x-msexcel"><a href="/sheets/legacy" type="application/x-msexcel">Legacy sheet</a><a href="/sheets/legacy" class="wp-block-file__button wp-element-button" download>Download</a></div>', $markup );
		$this->assertStringContainsString( '<!-- wp:file {"href":"\/sheets\/msexcel","textLinkHref":"\/sheets\/msexcel","fileName":"msexcel.xls","anchor":"typed-ms-excel"} -->', $markup );
		$this->assertStringContainsString( '<div class="wp-block-file" id="typed-ms-excel"><a href="/sheets/msexcel" type="application/msexcel">MS Excel sheet</a><a href="/sheets/msexcel" class="wp-block-file__button wp-element-button" download>Download</a></div>', $markup );
		$this->assertStringContainsString( '<!-- wp:file {"href":"\/sheets\/ms-excel","textLinkHref":"\/sheets\/ms-excel","fileName":"ms-excel.xls","anchor":"typed-x-ms-excel"} -->', $markup );
		$this->assertStringContainsString( '<div class="wp-block-file" id="typed-x-ms-excel"><a href="/sheets/ms-excel" type="application/x-ms-excel">X MS Excel sheet</a><a href="/sheets/ms-excel" class="wp-block-file__button wp-element-button" download>Download</a></div>', $markup );
		$this->assertStringContainsString( '<!-- wp:file {"href":"\/sheets\/vnd-ms-excel","textLinkHref":"\/sheets\/vnd-ms-excel","fileName":"vnd-ms-excel.xls","anchor":"typed-vnd-ms-excel"} -->', $markup );
		$this->assertStringContainsString( '<div class="wp-block-file" id="typed-vnd-ms-excel"><a href="/sheets/vnd-ms-excel" type="application/vnd.ms-excel">Vendor MS Excel sheet</a><a href="/sheets/vnd-ms-excel" class="wp-block-file__button wp-element-button" download>Download</a></div>', $markup );
		$this->assertStringContainsString( '<!-- wp:file {"href":"\/docs\/memo","textLinkHref":"\/docs\/memo","fileName":"memo.doc","anchor":"typed-word"} -->', $markup );
		$this->assertStringContainsString( '<div class="wp-block-file" id="typed-word"><a href="/docs/memo" type="application/x-msword">Memo</a><a href="/docs/memo" class="wp-block-file__button wp-element-button" download>Download</a></div>', $markup );
		$this->assertStringContainsString( '<!-- wp:file {"href":"\/docs\/msword","textLinkHref":"\/docs\/msword","fileName":"msword.doc","anchor":"typed-msword"} -->', $markup );
		$this->assertStringContainsString( '<div class="wp-block-file" id="typed-msword"><a href="/docs/msword" type="application/msword">MS Word memo</a><a href="/docs/msword" class="wp-block-file__button wp-element-button" download>Download</a></div>', $markup );
		$this->assertStringContainsString( '<!-- wp:file {"href":"\/docs\/legacy-memo","textLinkHref":"\/docs\/legacy-memo","fileName":"legacy-memo.doc","anchor":"typed-legacy-word"} -->', $markup );
		$this->assertStringContainsString( '<div class="wp-block-file" id="typed-legacy-word"><a href="/docs/legacy-memo" type="application/word">Legacy memo</a><a href="/docs/legacy-memo" class="wp-block-file__button wp-element-button" download>Download</a></div>', $markup );
		$this->assertStringContainsString( '<!-- wp:file {"href":"\/docs\/rich-text","textLinkHref":"\/docs\/rich-text","fileName":"rich-text.rtf","anchor":"typed-rtf"} -->', $markup );
		$this->assertStringContainsString( '<div class="wp-block-file" id="typed-rtf"><a href="/docs/rich-text" type="text/rtf">Rich text</a><a href="/docs/rich-text" class="wp-block-file__button wp-element-button" download>Download</a></div>', $markup );
		$this->assertStringContainsString( '<!-- wp:file {"href":"\/docs\/application-rtf","textLinkHref":"\/docs\/application-rtf","fileName":"application-rtf.rtf","anchor":"typed-application-rtf"} -->', $markup );
		$this->assertStringContainsString( '<div class="wp-block-file" id="typed-application-rtf"><a href="/docs/application-rtf" type="application/rtf">Application RTF</a><a href="/docs/application-rtf" class="wp-block-file__button wp-element-button" download>Download</a></div>', $markup );
		$this->assertStringContainsString( '<!-- wp:file {"href":"\/docs\/richtext-note","textLinkHref":"\/docs\/richtext-note","fileName":"richtext-note.rtf","anchor":"typed-richtext"} -->', $markup );
		$this->assertStringContainsString( '<div class="wp-block-file" id="typed-richtext"><a href="/docs/richtext-note" type="text/richtext">Richtext note</a><a href="/docs/richtext-note" class="wp-block-file__button wp-element-button" download>Download</a></div>', $markup );
		$this->assertStringContainsString( '<!-- wp:file {"href":"\/docs\/plain-note","textLinkHref":"\/docs\/plain-note","fileName":"plain-note.txt","anchor":"typed-plain-text"} -->', $markup );
		$this->assertStringContainsString( '<div class="wp-block-file" id="typed-plain-text"><a href="/docs/plain-note" type="text/plain">Plain text note</a><a href="/docs/plain-note" class="wp-block-file__button wp-element-button" download>Download</a></div>', $markup );
		$this->assertStringContainsString( '<!-- wp:file {"href":"\/logs\/import-run","textLinkHref":"\/logs\/import-run","fileName":"import-run.log","anchor":"typed-log"} -->', $markup );
		$this->assertStringContainsString( '<div class="wp-block-file" id="typed-log"><a href="/logs/import-run" type="text/x-log">Import log</a><a href="/logs/import-run" class="wp-block-file__button wp-element-button" download>Download</a></div>', $markup );
		$this->assertStringContainsString( '<!-- wp:file {"href":"\/docs\/readme","textLinkHref":"\/docs\/readme","fileName":"readme.md","anchor":"typed-markdown"} -->', $markup );
		$this->assertStringContainsString( '<div class="wp-block-file" id="typed-markdown"><a href="/docs/readme" type="text/markdown">README</a><a href="/docs/readme" class="wp-block-file__button wp-element-button" download>Download</a></div>', $markup );
		$this->assertStringContainsString( '<!-- wp:file {"href":"\/docs\/legacy-readme","textLinkHref":"\/docs\/legacy-readme","fileName":"legacy-readme.md","anchor":"typed-x-markdown"} -->', $markup );
		$this->assertStringContainsString( '<div class="wp-block-file" id="typed-x-markdown"><a href="/docs/legacy-readme" type="text/x-markdown">Legacy README</a><a href="/docs/legacy-readme" class="wp-block-file__button wp-element-button" download>Download</a></div>', $markup );
		$this->assertStringContainsString( '<!-- wp:file {"href":"\/docs\/rtf-archive","textLinkHref":"\/docs\/rtf-archive","fileName":"rtf-archive.rtf","anchor":"typed-x-rtf"} -->', $markup );
		$this->assertStringContainsString( '<div class="wp-block-file" id="typed-x-rtf"><a href="/docs/rtf-archive" type="application/x-rtf">RTF archive</a><a href="/docs/rtf-archive" class="wp-block-file__button wp-element-button" download>Download</a></div>', $markup );
		$this->assertStringContainsString( '<!-- wp:file {"href":"\/books\/legacy-epub","textLinkHref":"\/books\/legacy-epub","fileName":"legacy-epub.epub","anchor":"typed-x-epub"} -->', $markup );
		$this->assertStringContainsString( '<div class="wp-block-file" id="typed-x-epub"><a href="/books/legacy-epub" type="application/x-epub+zip">Legacy EPUB</a><a href="/books/legacy-epub" class="wp-block-file__button wp-element-button" download>Download</a></div>', $markup );
		$this->assertStringContainsString( '<!-- wp:file {"href":"\/books\/standard-epub","textLinkHref":"\/books\/standard-epub","fileName":"standard-epub.epub","anchor":"typed-epub"} -->', $markup );
		$this->assertStringContainsString( '<div class="wp-block-file" id="typed-epub"><a href="/books/standard-epub" type="application/epub+zip">Standard EPUB</a><a href="/books/standard-epub" class="wp-block-file__button wp-element-button" download>Download</a></div>', $markup );
		$this->assertStringContainsString( '<!-- wp:file {"href":"\/slides\/legacy","textLinkHref":"\/slides\/legacy","fileName":"legacy.ppt","anchor":"typed-powerpoint"} -->', $markup );
		$this->assertStringContainsString( '<div class="wp-block-file" id="typed-powerpoint"><a href="/slides/legacy" type="application/powerpoint">Legacy deck</a><a href="/slides/legacy" class="wp-block-file__button wp-element-button" download>Download</a></div>', $markup );
		$this->assertStringContainsString( '<!-- wp:file {"href":"\/slides\/archive","textLinkHref":"\/slides\/archive","fileName":"archive.ppt","anchor":"typed-x-powerpoint"} -->', $markup );
		$this->assertStringContainsString( '<div class="wp-block-file" id="typed-x-powerpoint"><a href="/slides/archive" type="application/x-mspowerpoint">Archived deck</a><a href="/slides/archive" class="wp-block-file__button wp-element-button" download>Download</a></div>', $markup );
		$this->assertStringContainsString( '<!-- wp:file {"href":"\/slides\/meeting","textLinkHref":"\/slides\/meeting","fileName":"meeting.ppt","anchor":"typed-ms-powerpoint"} -->', $markup );
		$this->assertStringContainsString( '<div class="wp-block-file" id="typed-ms-powerpoint"><a href="/slides/meeting" type="application/mspowerpoint">Meeting deck</a><a href="/slides/meeting" class="wp-block-file__button wp-element-button" download>Download</a></div>', $markup );
		$this->assertStringContainsString( '<!-- wp:file {"href":"\/slides\/vnd-ms-powerpoint","textLinkHref":"\/slides\/vnd-ms-powerpoint","fileName":"vnd-ms-powerpoint.ppt","anchor":"typed-vnd-ms-powerpoint"} -->', $markup );
		$this->assertStringContainsString( '<div class="wp-block-file" id="typed-vnd-ms-powerpoint"><a href="/slides/vnd-ms-powerpoint" type="application/vnd.ms-powerpoint">Vendor MS PowerPoint deck</a><a href="/slides/vnd-ms-powerpoint" class="wp-block-file__button wp-element-button" download>Download</a></div>', $markup );
		$this->assertStringNotContainsString( 'download="../Annual', $markup );
		$this->assertStringNotContainsString( 'margin-left', $markup );
		$this->assertStringNotContainsString( 'bad:name.xlsx', $markup );
		$this->assertStringNotContainsString( 'setup.exe', $markup );
		$this->assertStringNotContainsString( 'application/octet-stream', $markup );
		$this->assertStringNotContainsString( 'text/html; charset', $markup );
		$this->assertStringNotContainsString( 'hreflang="en_US"', $markup );
		$this->assertStringNotContainsString( 'referrerpolicy="made-up"', $markup );
		$this->assertStringNotContainsString( 'javascript:alert', $markup );
		$this->assertStringNotContainsString( '<!-- wp:freeform -->', $markup );
	}

	/**
	 * Fragment-only download links are not treated as file downloads.
	 *
	 * @return void
	 */
	public function test_fragment_only_download_links_do_not_become_file_blocks() {
		$converter = new ImportHtmlBlockConverter();
		$summary   = array();

		$markup = $converter->convert(
			'<p>See <a id="fragment-download" href="#export-details" download>export details</a>.</p>',
			$summary
		);

		$this->assertSame( 'structured', $summary['html_block_conversion'] );
		$this->assertSame( 1, $summary['html_inferred_block_count'] );
		$this->assertSame( 0, $summary['html_classic_block_count'] );
		$this->assertStringContainsString( '<!-- wp:paragraph -->', $markup );
		$this->assertStringContainsString( '<p>See <a id="fragment-download" href="#export-details" download>export details</a>.</p>', $markup );
		$this->assertStringNotContainsString( '<!-- wp:file {"href":"#export-details"', $markup );
		$this->assertStringNotContainsString( '<div class="wp-block-file" id="fragment-download">', $markup );
	}

	/**
	 * Non-file URL schemes are not treated as File block downloads.
	 *
	 * @return void
	 */
	public function test_non_file_url_schemes_do_not_become_file_blocks() {
		$converter = new ImportHtmlBlockConverter();
		$summary   = array();

		$markup = $converter->convert(
			'<p>Contact <a id="mailto-pdf" href="mailto:report.pdf" download>Email report</a>, <a id="tel-pdf" href="tel:+15550123.pdf" download>call archive</a>, <a id="data-pdf" href="data:application/pdf;base64,JVBERi0xLjQ=" type="application/pdf" download="Inline%20Report.pdf">inline report</a>, <a id="blob-pdf" href="blob:https://legacy.example.test/550e8400-e29b-41d4-a716-446655440000" type="application/pdf" download="Blob%20Report.pdf">blob report</a>, <a id="file-pdf" href="file:///Users/importer/source-report.pdf" download>local report</a>, or <a id="cid-pdf" href="cid:source-report.pdf" download>embedded report</a>.</p>',
			$summary
		);

		$this->assertSame( 'structured', $summary['html_block_conversion'] );
		$this->assertSame( 1, $summary['html_inferred_block_count'] );
		$this->assertSame( 0, $summary['html_classic_block_count'] );
		$this->assertStringContainsString( '<!-- wp:paragraph -->', $markup );
		$this->assertStringContainsString( '<a id="mailto-pdf" href="mailto:report.pdf" download>Email report</a>', $markup );
		$this->assertStringContainsString( '<a id="tel-pdf" href="tel:+15550123.pdf" download>call archive</a>', $markup );
		$this->assertStringContainsString( '<a id="data-pdf" href="data:application/pdf;base64,JVBERi0xLjQ=" type="application/pdf" download="Inline%20Report.pdf">inline report</a>', $markup );
		$this->assertStringContainsString( '<a id="blob-pdf" href="blob:https://legacy.example.test/550e8400-e29b-41d4-a716-446655440000" type="application/pdf" download="Blob%20Report.pdf">blob report</a>', $markup );
		$this->assertStringContainsString( '<a id="file-pdf" href="file:///Users/importer/source-report.pdf" download>local report</a>', $markup );
		$this->assertStringContainsString( '<a id="cid-pdf" href="cid:source-report.pdf" download>embedded report</a>', $markup );
		$this->assertStringNotContainsString( '<!-- wp:file {"href":"mailto:report.pdf"', $markup );
		$this->assertStringNotContainsString( '<!-- wp:file {"href":"tel:+15550123.pdf"', $markup );
		$this->assertStringNotContainsString( '<!-- wp:file {"href":"data:application\/pdf', $markup );
		$this->assertStringNotContainsString( '<!-- wp:file {"href":"blob:https:\/\/legacy.example.test', $markup );
		$this->assertStringNotContainsString( '<!-- wp:file {"href":"file:\/\/\/Users\/importer\/source-report.pdf"', $markup );
		$this->assertStringNotContainsString( '<!-- wp:file {"href":"cid:source-report.pdf"', $markup );
		$this->assertStringNotContainsString( '<div class="wp-block-file" id="mailto-pdf">', $markup );
		$this->assertStringNotContainsString( '<div class="wp-block-file" id="tel-pdf">', $markup );
		$this->assertStringNotContainsString( '<div class="wp-block-file" id="data-pdf">', $markup );
		$this->assertStringNotContainsString( '<div class="wp-block-file" id="blob-pdf">', $markup );
		$this->assertStringNotContainsString( '<div class="wp-block-file" id="file-pdf">', $markup );
		$this->assertStringNotContainsString( '<div class="wp-block-file" id="cid-pdf">', $markup );
	}

	/**
	 * Mixed paragraphs split around inline block candidates without dropping text.
	 *
	 * @return void
	 */
	public function test_mixed_inline_paragraph_media_and_downloads_split_into_native_blocks() {
		$converter = new ImportHtmlBlockConverter();
		$summary   = array();

		$markup = $converter->convert(
			'<p>Intro <strong>copy</strong> <a href="docs/guide.pdf">download guide</a> between <img src="photo.jpg" alt="Photo"> <a class="button" href="/start">Start</a> done.</p>',
			$summary
		);

		$this->assertSame( 'structured', $summary['html_block_conversion'] );
		$this->assertSame( 6, $summary['html_inferred_block_count'] );
		$this->assertSame( 0, $summary['html_classic_block_count'] );
		$this->assertStringContainsString( '<p>Intro <strong>copy</strong></p>', $markup );
		$this->assertStringContainsString( '<!-- wp:file {"href":"docs\/guide.pdf","textLinkHref":"docs\/guide.pdf","fileName":"guide.pdf"} -->', $markup );
		$this->assertStringContainsString( '<div class="wp-block-file"><a href="docs/guide.pdf">download guide</a><a href="docs/guide.pdf" class="wp-block-file__button wp-element-button" download>Download</a></div>', $markup );
		$this->assertStringContainsString( '<p>between</p>', $markup );
		$this->assertStringContainsString( '<figure class="wp-block-image"><img src="photo.jpg" alt="Photo"></figure>', $markup );
		$this->assertStringContainsString( '<!-- wp:button {"url":"\/start"} -->', $markup );
		$this->assertStringContainsString( '<p>done.</p>', $markup );
		$this->assertStringNotContainsString( '<!-- wp:freeform -->', $markup );
	}

	/**
	 * Linked images become native Image blocks with custom-link attributes.
	 *
	 * @return void
	 */
	public function test_linked_images_preserve_custom_link_attributes() {
		$converter = new ImportHtmlBlockConverter();
		$summary   = array();

		$markup = $converter->convert(
			'<figure><a href="/full/photo.jpg" target="_BLANK" rel="NOOPENER javascript:alert(1) noopener"><img src="thumb.jpg" alt="Thumb"></a><figcaption>Linked caption.</figcaption></figure>'
			. '<p>Before <a id="inline-linked-image" href="/full/inline.jpg"><img src="inline.jpg" alt="Inline"></a> after.</p>',
			$summary
		);

		$this->assertSame( 'structured', $summary['html_block_conversion'] );
		$this->assertSame( 4, $summary['html_inferred_block_count'] );
		$this->assertSame( 0, $summary['html_classic_block_count'] );
		$this->assertStringContainsString( '<!-- wp:image {"href":"\/full\/photo.jpg","linkDestination":"custom","linkTarget":"_blank","rel":"noopener"} -->', $markup );
		$this->assertStringContainsString( '<a href="/full/photo.jpg" target="_BLANK" rel="noopener"><img src="thumb.jpg" alt="Thumb"></a><figcaption class="wp-element-caption">Linked caption.</figcaption>', $markup );
		$this->assertStringNotContainsString( 'javascript:alert', $markup );
		$this->assertStringContainsString( '<p>Before</p>', $markup );
		$this->assertStringContainsString( '<!-- wp:image {"href":"\/full\/inline.jpg","linkDestination":"custom","anchor":"inline-linked-image"} -->', $markup );
		$this->assertStringContainsString( '<figure class="wp-block-image" id="inline-linked-image"><a href="/full/inline.jpg"><img src="inline.jpg" alt="Inline"></a></figure>', $markup );
		$this->assertStringNotContainsString( '<a id="inline-linked-image"', $markup );
		$this->assertStringContainsString( '<p>after.</p>', $markup );
		$this->assertStringNotContainsString( '<!-- wp:freeform -->', $markup );
	}

	/**
	 * Orphan figcaptions immediately after image-like nodes become Image captions.
	 *
	 * @return void
	 */
	public function test_orphan_figcaptions_join_previous_images() {
		$converter = new ImportHtmlBlockConverter();
		$summary   = array();

		$markup = $converter->convert(
			'<img src="photo.jpg" alt="Photo"><figcaption>Photo <em>caption</em>.</figcaption>'
			. '<a href="large.jpg"><img src="thumb.jpg" alt="Thumb"></a><figcaption>Linked caption.</figcaption>'
			. '<picture><source srcset="photo.webp" type="image/webp"><img src="photo.jpg" alt="Photo"></picture><figcaption>Picture caption.</figcaption>',
			$summary
		);

		$this->assertSame( 'structured', $summary['html_block_conversion'] );
		$this->assertSame( 3, $summary['html_inferred_block_count'] );
		$this->assertSame( 0, $summary['html_classic_block_count'] );
		$this->assertStringContainsString( '<figure class="wp-block-image"><img src="photo.jpg" alt="Photo"><figcaption class="wp-element-caption">Photo <em>caption</em>.</figcaption></figure>', $markup );
		$this->assertStringContainsString( '<figure class="wp-block-image"><a href="large.jpg"><img src="thumb.jpg" alt="Thumb"></a><figcaption class="wp-element-caption">Linked caption.</figcaption></figure>', $markup );
		$this->assertStringContainsString( '<figure class="wp-block-image"><picture><source srcset="photo.webp" type="image/webp"><img src="photo.jpg" alt="Photo"></picture><figcaption class="wp-element-caption">Picture caption.</figcaption></figure>', $markup );
		$this->assertStringNotContainsString( '<!-- wp:freeform -->', $markup );

		$late_markup = $converter->convert( '<img src="photo.jpg" alt="Photo"><p>After</p><figcaption>Late caption.</figcaption>', $summary );

		$this->assertSame( 'mixed', $summary['html_block_conversion'] );
		$this->assertSame( 2, $summary['html_inferred_block_count'] );
		$this->assertSame( 1, $summary['html_classic_block_count'] );
		$this->assertStringContainsString( '<figure class="wp-block-image"><img src="photo.jpg" alt="Photo"></figure>', $late_markup );
		$this->assertStringContainsString( '<p>After</p>', $late_markup );
		$this->assertStringContainsString( '<figcaption>Late caption.</figcaption>', $late_markup );

		$invalid_picture_markup = $converter->convert( '<picture><span>Bad</span><img src="photo.jpg" alt="Photo"></picture><figcaption>Picture caption.</figcaption>', $summary );

		$this->assertSame( 'mixed', $summary['html_block_conversion'] );
		$this->assertSame( 0, $summary['html_inferred_block_count'] );
		$this->assertSame( 2, $summary['html_classic_block_count'] );
		$this->assertStringContainsString( '<picture><span>Bad</span><img src="photo.jpg" alt="Photo"></picture>', $invalid_picture_markup );
		$this->assertStringContainsString( '<figcaption>Picture caption.</figcaption>', $invalid_picture_markup );
	}

	/**
	 * Classic WordPress caption markup becomes native Image blocks.
	 *
	 * @return void
	 */
	public function test_classic_captioned_images_become_native_image_blocks() {
		$converter = new ImportHtmlBlockConverter();
		$summary   = array();

		$markup = $converter->convert(
			'[caption id="attachment_42" align="aligncenter" width="640"]'
			. '<a href="/full/photo.jpg" target="_blank" rel="noopener"><img class="size-large" src="/thumb/photo.jpg" alt="Thumb"></a>'
			. 'Classic <em>caption</em> text.'
			. '[/caption]'
			. '<div id="attachment_99" class="wp-caption alignright"><img class="size-medium" src="/classic.jpg" alt="Classic"><br><p class="wp-caption-text">Rendered caption.</p></div>',
			$summary
		);

		$this->assertSame( 'structured', $summary['html_block_conversion'] );
		$this->assertSame( 2, $summary['html_inferred_block_count'] );
		$this->assertSame( 0, $summary['html_classic_block_count'] );
		$this->assertStringContainsString( '<!-- wp:image {"align":"center","sizeSlug":"large","href":"\/full\/photo.jpg","linkDestination":"custom","linkTarget":"_blank","rel":"noopener","anchor":"attachment_42"} -->', $markup );
		$this->assertStringContainsString( '<figure class="wp-block-image aligncenter size-large" id="attachment_42"><a href="/full/photo.jpg" target="_blank" rel="noopener"><img class="size-large" src="/thumb/photo.jpg" alt="Thumb"></a><figcaption class="wp-element-caption">Classic <em>caption</em> text.</figcaption></figure>', $markup );
		$this->assertStringContainsString( '<!-- wp:image {"align":"right","sizeSlug":"medium","anchor":"attachment_99"} -->', $markup );
		$this->assertStringContainsString( '<figure class="wp-block-image alignright size-medium" id="attachment_99"><img class="size-medium" src="/classic.jpg" alt="Classic"><figcaption class="wp-element-caption">Rendered caption.</figcaption></figure>', $markup );
		$this->assertStringNotContainsString( '<br>', $markup );
		$this->assertStringNotContainsString( '<!-- wp:shortcode -->', $markup );
		$this->assertStringNotContainsString( '<!-- wp:freeform -->', $markup );
	}

	/**
	 * Captioned image wrappers with extra direct children keep a conservative fallback.
	 *
	 * @return void
	 */
	public function test_ambiguous_captioned_image_wrappers_keep_classic_fallback() {
		$converter = new ImportHtmlBlockConverter();
		$summary   = array();

		$markup = $converter->convert(
			'<div id="attachment_77" class="wp-caption alignleft">'
			. '<img src="/ambiguous.jpg" alt="Ambiguous">'
			. '<p class="wp-caption-text">Ambiguous caption.</p>'
			. '<div class="download-callout"><a href="/download">Download source file</a></div>'
			. '</div>',
			$summary
		);

		$this->assertSame( 'mixed', $summary['html_block_conversion'] );
		$this->assertSame( 0, $summary['html_inferred_block_count'] );
		$this->assertSame( 1, $summary['html_classic_block_count'] );
		$this->assertStringContainsString( '<!-- wp:freeform -->', $markup );
		$this->assertStringContainsString( '<div class="download-callout"><a href="/download">Download source file</a></div>', $markup );
		$this->assertStringNotContainsString( '<!-- wp:image', $markup );
	}

	/**
	 * Already-native image figure layout metadata is preserved.
	 *
	 * @return void
	 */
	public function test_image_figure_layout_metadata_is_preserved() {
		$converter = new ImportHtmlBlockConverter();
		$summary   = array();

		$markup = $converter->convert(
			'<figure class="wp-block-image alignwide size-full"><a href="/large-native.jpg"><img src="/native.jpg" alt="Native image"></a><figcaption>Native caption.</figcaption></figure>'
			. '<img class="alignleft size-thumbnail" src="/standalone.jpg" alt="Standalone image">'
			. '<img id="case-align-image" class="AlignRight" src="/case-align.jpg" alt="Case align">'
			. '<img id="case-size-image" class="SIZE-Large" src="/case-size.jpg" alt="Case size">'
			. '<img id="legacy-align-image" align="right" src="/legacy-align.jpg" alt="Legacy align">'
			. '<img id="style-align-image" style="margin: 0 auto" src="/centered.jpg" alt="Centered image">'
			. '<figure class="wp-block-image aligncenter"><img id="inner-figure-image" src="/inner-anchor.jpg" alt="Inner anchor"></figure>'
			. '<figure class="wp-block-image alignwide"><picture id="figure-picture-source"><source srcset="/figure-large.jpg"><br><img class="size-medium" src="/figure-small.jpg" alt="Figure picture"></picture></figure>'
			. '<figure class="wp-block-image alignright"><a id="figure-linked-source" href="/full-figure-link.jpg"><img src="/figure-linked-thumb.jpg" alt="Figure linked"></a></figure>',
			$summary
		);

		$this->assertSame( 'structured', $summary['html_block_conversion'] );
		$this->assertSame( 9, $summary['html_inferred_block_count'] );
		$this->assertSame( 0, $summary['html_classic_block_count'] );
		$this->assertStringContainsString( '<!-- wp:image {"align":"wide","sizeSlug":"full","href":"\/large-native.jpg","linkDestination":"custom"} -->', $markup );
		$this->assertStringContainsString( '<figure class="wp-block-image alignwide size-full"><a href="/large-native.jpg"><img src="/native.jpg" alt="Native image"></a><figcaption class="wp-element-caption">Native caption.</figcaption></figure>', $markup );
		$this->assertStringContainsString( '<!-- wp:image {"align":"left","sizeSlug":"thumbnail"} -->', $markup );
		$this->assertStringContainsString( '<figure class="wp-block-image alignleft size-thumbnail"><img class="alignleft size-thumbnail" src="/standalone.jpg" alt="Standalone image"></figure>', $markup );
		$this->assertStringContainsString( '<!-- wp:image {"align":"right","anchor":"case-align-image"} -->', $markup );
		$this->assertStringContainsString( '<figure class="wp-block-image alignright" id="case-align-image"><img class="AlignRight" src="/case-align.jpg" alt="Case align"></figure>', $markup );
		$this->assertStringContainsString( '<!-- wp:image {"sizeSlug":"large","anchor":"case-size-image"} -->', $markup );
		$this->assertStringContainsString( '<figure class="wp-block-image size-large" id="case-size-image"><img class="SIZE-Large" src="/case-size.jpg" alt="Case size"></figure>', $markup );
		$this->assertStringContainsString( '<!-- wp:image {"align":"right","anchor":"legacy-align-image"} -->', $markup );
		$this->assertStringContainsString( '<figure class="wp-block-image alignright" id="legacy-align-image"><img align="right" src="/legacy-align.jpg" alt="Legacy align"></figure>', $markup );
		$this->assertStringContainsString( '<!-- wp:image {"align":"center","anchor":"style-align-image"} -->', $markup );
		$this->assertStringContainsString( '<figure class="wp-block-image aligncenter" id="style-align-image"><img style="margin: 0 auto" src="/centered.jpg" alt="Centered image"></figure>', $markup );
		$this->assertStringContainsString( '<!-- wp:image {"align":"center","anchor":"inner-figure-image"} -->', $markup );
		$this->assertStringContainsString( '<figure class="wp-block-image aligncenter" id="inner-figure-image"><img src="/inner-anchor.jpg" alt="Inner anchor"></figure>', $markup );
		$this->assertStringNotContainsString( '<img id="inner-figure-image"', $markup );
		$this->assertStringContainsString( '<!-- wp:image {"align":"wide","sizeSlug":"medium","anchor":"figure-picture-source"} -->', $markup );
		$this->assertStringContainsString( '<figure class="wp-block-image alignwide size-medium" id="figure-picture-source"><picture><source srcset="/figure-large.jpg"><br><img class="size-medium" src="/figure-small.jpg" alt="Figure picture"></picture></figure>', $markup );
		$this->assertStringNotContainsString( 'picture id="figure-picture-source"', $markup );
		$this->assertStringContainsString( '<!-- wp:image {"align":"right","href":"\/full-figure-link.jpg","linkDestination":"custom","anchor":"figure-linked-source"} -->', $markup );
		$this->assertStringContainsString( '<figure class="wp-block-image alignright" id="figure-linked-source"><a href="/full-figure-link.jpg"><img src="/figure-linked-thumb.jpg" alt="Figure linked"></a></figure>', $markup );
		$this->assertStringNotContainsString( '<a id="figure-linked-source"', $markup );
		$this->assertStringNotContainsString( '<!-- wp:freeform -->', $markup );
	}

	/**
	 * Image figures with extra direct children keep a conservative fallback.
	 *
	 * @return void
	 */
	public function test_ambiguous_image_figures_keep_classic_fallback() {
		$converter = new ImportHtmlBlockConverter();
		$summary   = array();

		$markup = $converter->convert(
			'<figure id="native-but-extended" class="wp-block-image alignwide">'
			. '<img src="/extended.jpg" alt="Extended">'
			. '<figcaption>Extended caption.</figcaption>'
			. '<div class="source-credit">Photo credit that should remain outside a native Image block.</div>'
			. '</figure>',
			$summary
		);

		$this->assertSame( 'mixed', $summary['html_block_conversion'] );
		$this->assertSame( 0, $summary['html_inferred_block_count'] );
		$this->assertSame( 1, $summary['html_classic_block_count'] );
		$this->assertStringContainsString( '<!-- wp:freeform -->', $markup );
		$this->assertStringContainsString( '<div class="source-credit">Photo credit that should remain outside a native Image block.</div>', $markup );
		$this->assertStringNotContainsString( '<!-- wp:image', $markup );
	}

	/**
	 * Responsive picture elements are preserved inside native Image blocks.
	 *
	 * @return void
	 */
	public function test_picture_elements_become_native_image_blocks() {
		$converter = new ImportHtmlBlockConverter();
		$summary   = array();

		$markup = $converter->convert(
			'<picture id="responsive-picture" class="alignwide"><source media="(min-width: 800px)" srcset="/large.jpg"><br><img class="size-large" src="/small.jpg" alt="Responsive"></picture>'
			. '<a href="/full-responsive" target="_BLANK" rel="NOOPENER noreferrer javascript:alert(1)"><picture id="linked-responsive" class="aligncenter"><source srcset="/linked-large.jpg"><img class="size-medium" src="/linked-small.jpg" alt="Linked responsive"></picture></a>'
			. '<a id="linked-picture-anchor" href="/full-picture"><picture class="alignwide"><source srcset="/fallback-large.jpg"><img class="size-full" src="/fallback-small.jpg" alt="Linked picture fallback"></picture></a>',
			$summary
		);

		$this->assertSame( 'structured', $summary['html_block_conversion'] );
		$this->assertSame( 3, $summary['html_inferred_block_count'] );
		$this->assertSame( 0, $summary['html_classic_block_count'] );
		$this->assertStringContainsString( '<!-- wp:image {"align":"wide","sizeSlug":"large","anchor":"responsive-picture"} -->', $markup );
		$this->assertStringContainsString( '<figure class="wp-block-image alignwide size-large" id="responsive-picture"><picture class="alignwide"><source media="(min-width: 800px)" srcset="/large.jpg"><br><img class="size-large" src="/small.jpg" alt="Responsive"></picture></figure>', $markup );
		$this->assertStringContainsString( '<!-- wp:image {"align":"center","sizeSlug":"medium","href":"\/full-responsive","linkDestination":"custom","linkTarget":"_blank","rel":"noopener noreferrer","anchor":"linked-responsive"} -->', $markup );
		$this->assertStringContainsString( '<figure class="wp-block-image aligncenter size-medium" id="linked-responsive"><a href="/full-responsive" target="_BLANK" rel="noopener noreferrer"><picture class="aligncenter"><source srcset="/linked-large.jpg"><img class="size-medium" src="/linked-small.jpg" alt="Linked responsive"></picture></a></figure>', $markup );
		$this->assertStringContainsString( '<!-- wp:image {"align":"wide","sizeSlug":"full","href":"\/full-picture","linkDestination":"custom","anchor":"linked-picture-anchor"} -->', $markup );
		$this->assertStringContainsString( '<figure class="wp-block-image alignwide size-full" id="linked-picture-anchor"><a href="/full-picture"><picture class="alignwide"><source srcset="/fallback-large.jpg"><img class="size-full" src="/fallback-small.jpg" alt="Linked picture fallback"></picture></a></figure>', $markup );
		$this->assertStringNotContainsString( 'picture id="responsive-picture"', $markup );
		$this->assertStringNotContainsString( 'picture id="linked-responsive"', $markup );
		$this->assertStringNotContainsString( '<a id="linked-picture-anchor"', $markup );
		$this->assertStringNotContainsString( 'javascript:alert', $markup );
		$this->assertStringNotContainsString( '<!-- wp:freeform -->', $markup );
	}

	/**
	 * Picture elements with extra direct children keep a conservative fallback.
	 *
	 * @return void
	 */
	public function test_ambiguous_picture_elements_keep_classic_fallback() {
		$converter = new ImportHtmlBlockConverter();
		$summary   = array();

		$markup = $converter->convert(
			'<picture id="picture-with-credit" class="alignwide">'
			. '<source srcset="/large.jpg">'
			. '<img src="/small.jpg" alt="Responsive">'
			. '<span class="picture-credit">Keep this credit with the imported picture.</span>'
			. '</picture>',
			$summary
		);

		$this->assertSame( 'mixed', $summary['html_block_conversion'] );
		$this->assertSame( 0, $summary['html_inferred_block_count'] );
		$this->assertSame( 1, $summary['html_classic_block_count'] );
		$this->assertStringContainsString( '<!-- wp:freeform -->', $markup );
		$this->assertStringContainsString( '<span class="picture-credit">Keep this credit with the imported picture.</span>', $markup );
		$this->assertStringNotContainsString( '<!-- wp:image', $markup );
	}

	/**
	 * Ordered list numbering metadata is preserved in native List blocks.
	 *
	 * @return void
	 */
	public function test_ordered_list_numbering_metadata_is_preserved() {
		$converter = new ImportHtmlBlockConverter();
		$summary   = array();

		$markup = $converter->convert(
			'<ol id="migration-steps" start="4" reversed type="A"><li>Fourth step</li><li>Third step</li></ol>'
			. '<ol start="not-safe" type="z"><li>Plain ordered item</li></ol>'
			. '<ol id="bounded-steps" start="1000000" type="i"><li>Bounded step</li></ol>'
			. '<ol id="style-numbered" style="list-style-type: upper-roman"><li>Roman step</li></ol>'
			. '<ol id="shorthand-numbered" style="list-style: lower-alpha inside"><li>Alpha step</li></ol>',
			$summary
		);

		$this->assertSame( 'structured', $summary['html_block_conversion'] );
		$this->assertSame( 5, $summary['html_inferred_block_count'] );
		$this->assertSame( 0, $summary['html_classic_block_count'] );
		$this->assertStringContainsString( '<!-- wp:list {"ordered":true,"reversed":true,"start":4,"type":"A","anchor":"migration-steps"} -->', $markup );
		$this->assertStringContainsString( '<ol id="migration-steps" reversed="reversed" start="4" type="A"><li>Fourth step</li><li>Third step</li></ol>', $markup );
		$this->assertStringContainsString( '<!-- wp:list {"ordered":true} -->', $markup );
		$this->assertStringContainsString( '<ol><li>Plain ordered item</li></ol>', $markup );
		$this->assertStringContainsString( '<!-- wp:list {"ordered":true,"start":999999,"type":"i","anchor":"bounded-steps"} -->', $markup );
		$this->assertStringContainsString( '<ol id="bounded-steps" start="999999" type="i"><li>Bounded step</li></ol>', $markup );
		$this->assertStringContainsString( '<!-- wp:list {"ordered":true,"type":"I","anchor":"style-numbered"} -->', $markup );
		$this->assertStringContainsString( '<ol id="style-numbered" type="I"><li>Roman step</li></ol>', $markup );
		$this->assertStringContainsString( '<!-- wp:list {"ordered":true,"type":"a","anchor":"shorthand-numbered"} -->', $markup );
		$this->assertStringContainsString( '<ol id="shorthand-numbered" type="a"><li>Alpha step</li></ol>', $markup );
		$this->assertStringNotContainsString( 'start="1000000"', $markup );
		$this->assertStringNotContainsString( 'list-style-type', $markup );
		$this->assertStringNotContainsString( 'list-style:', $markup );
		$this->assertStringNotContainsString( '<!-- wp:freeform -->', $markup );
	}

	/**
	 * Legacy menu and dir list containers become native unordered List blocks.
	 *
	 * @return void
	 */
	public function test_legacy_unordered_list_containers_become_native_lists() {
		$converter = new ImportHtmlBlockConverter();
		$summary   = array();

		$markup = $converter->convert(
			'<menu id="action-menu"><li>Export content</li><li>Review media</li></menu>'
			. '<dir id="directory-list"><li>January</li><li>February</li></dir>',
			$summary
		);

		$this->assertSame( 'structured', $summary['html_block_conversion'] );
		$this->assertSame( 2, $summary['html_inferred_block_count'] );
		$this->assertSame( 0, $summary['html_classic_block_count'] );
		$this->assertStringContainsString( '<!-- wp:list {"anchor":"action-menu"} -->', $markup );
		$this->assertStringContainsString( '<ul id="action-menu"><li>Export content</li><li>Review media</li></ul>', $markup );
		$this->assertStringContainsString( '<!-- wp:list {"anchor":"directory-list"} -->', $markup );
		$this->assertStringContainsString( '<ul id="directory-list"><li>January</li><li>February</li></ul>', $markup );
		$this->assertStringNotContainsString( '<menu', $markup );
		$this->assertStringNotContainsString( '<dir', $markup );
		$this->assertStringNotContainsString( '<!-- wp:freeform -->', $markup );
	}

	/**
	 * Consecutive top-level orphan list items become one native unordered List block.
	 *
	 * @return void
	 */
	public function test_orphan_list_items_become_native_lists() {
		$converter = new ImportHtmlBlockConverter();
		$summary   = array();

		$markup = $converter->convert(
			'<li>First orphan item</li>'
			. "\n"
			. '<li>Second orphan item</li>'
			. '<p>After list.</p>',
			$summary
		);

		$this->assertSame( 'structured', $summary['html_block_conversion'] );
		$this->assertSame( 2, $summary['html_inferred_block_count'] );
		$this->assertSame( 0, $summary['html_classic_block_count'] );
		$this->assertStringContainsString( '<!-- wp:list -->', $markup );
		$this->assertStringContainsString( '<ul><li>First orphan item</li><li>Second orphan item</li></ul>', $markup );
		$this->assertStringContainsString( '<p>After list.</p>', $markup );
		$this->assertStringNotContainsString( '<!-- wp:freeform -->', $markup );
	}

	/**
	 * Orphan list item metadata is preserved with nested List Item blocks.
	 *
	 * @return void
	 */
	public function test_orphan_list_item_metadata_is_preserved() {
		$converter = new ImportHtmlBlockConverter();
		$summary   = array();

		$markup = $converter->convert(
			'<li id="third-orphan" value="3">Third orphan item</li><li>Plain orphan item</li>',
			$summary
		);

		$this->assertSame( 'structured', $summary['html_block_conversion'] );
		$this->assertSame( 1, $summary['html_inferred_block_count'] );
		$this->assertSame( 0, $summary['html_classic_block_count'] );
		$this->assertStringContainsString( '<!-- wp:list -->', $markup );
		$this->assertStringContainsString( '<!-- wp:list-item {"anchor":"third-orphan"} -->', $markup );
		$this->assertStringContainsString( '<li id="third-orphan" value="3">Third orphan item</li>', $markup );
		$this->assertStringContainsString( "<!-- wp:list-item -->\n<li>Plain orphan item</li>\n<!-- /wp:list-item -->", $markup );
		$this->assertStringNotContainsString( '<!-- wp:freeform -->', $markup );
	}

	/**
	 * List item anchors and numbering overrides are preserved as nested list-item blocks.
	 *
	 * @return void
	 */
	public function test_list_item_metadata_is_preserved() {
		$converter = new ImportHtmlBlockConverter();
		$summary   = array();

		$markup = $converter->convert(
			'<ol><li id="step-two" value="2">Second step</li><li value="not-safe">Plain step</li></ol>'
			. '<ul><li id="source-note">Source note</li><li>Plain note</li></ul>'
			. '<ol><li id="bounded-step" value="-1000000">Bounded step</li></ol>',
			$summary
		);

		$this->assertSame( 'structured', $summary['html_block_conversion'] );
		$this->assertSame( 3, $summary['html_inferred_block_count'] );
		$this->assertSame( 0, $summary['html_classic_block_count'] );
		$this->assertStringContainsString( '<!-- wp:list {"ordered":true} -->', $markup );
		$this->assertStringContainsString( '<!-- wp:list-item {"anchor":"step-two"} -->', $markup );
		$this->assertStringContainsString( '<li id="step-two" value="2">Second step</li>', $markup );
		$this->assertStringContainsString( "<!-- wp:list-item -->\n<li>Plain step</li>\n<!-- /wp:list-item -->", $markup );
		$this->assertStringContainsString( '<!-- wp:list-item {"anchor":"source-note"} -->', $markup );
		$this->assertStringContainsString( '<li id="source-note">Source note</li>', $markup );
		$this->assertStringContainsString( '<li>Plain note</li>', $markup );
		$this->assertStringContainsString( '<!-- wp:list-item {"anchor":"bounded-step"} -->', $markup );
		$this->assertStringContainsString( '<li id="bounded-step" value="-999999">Bounded step</li>', $markup );
		$this->assertStringNotContainsString( 'not-safe', $markup );
		$this->assertStringNotContainsString( 'value="-1000000"', $markup );
		$this->assertStringNotContainsString( '<!-- wp:freeform -->', $markup );
	}

	/**
	 * Paragraph alignment and anchors are preserved in native Paragraph blocks.
	 *
	 * @return void
	 */
	public function test_paragraph_alignment_and_anchors_are_preserved() {
		$converter = new ImportHtmlBlockConverter();
		$summary   = array();

		$markup = $converter->convert(
			'<p id="intro" class="Has-Text-Align-Center">Centered intro.</p>'
			. '<p style="text-align: right !important">Right aligned copy.</p>'
			. '<p align="justify">Unsupported alignment stays plain.</p>'
			. '<p id="legacy-intro" class="AlignLeft alignfull">Legacy aligned intro.</p>',
			$summary
		);

		$this->assertSame( 'structured', $summary['html_block_conversion'] );
		$this->assertSame( 4, $summary['html_inferred_block_count'] );
		$this->assertSame( 0, $summary['html_classic_block_count'] );
		$this->assertStringContainsString( '<!-- wp:paragraph {"align":"center","anchor":"intro"} -->', $markup );
		$this->assertStringContainsString( '<p class="has-text-align-center" id="intro">Centered intro.</p>', $markup );
		$this->assertStringContainsString( '<!-- wp:paragraph {"align":"right"} -->', $markup );
		$this->assertStringContainsString( '<p class="has-text-align-right">Right aligned copy.</p>', $markup );
		$this->assertStringContainsString( '<!-- wp:paragraph -->', $markup );
		$this->assertStringContainsString( '<p>Unsupported alignment stays plain.</p>', $markup );
		$this->assertStringContainsString( '<!-- wp:paragraph {"align":"left","anchor":"legacy-intro"} -->', $markup );
		$this->assertStringContainsString( '<p class="has-text-align-left" id="legacy-intro">Legacy aligned intro.</p>', $markup );
		$this->assertStringNotContainsString( 'alignfull">Legacy aligned intro', $markup );
		$this->assertStringNotContainsString( '<!-- wp:freeform -->', $markup );
	}

	/**
	 * Opaque nested structures stay in the classic fallback.
	 *
	 * @return void
	 */
	public function test_opaque_semantic_wrappers_remain_classic_fallback() {
		$converter = new ImportHtmlBlockConverter();
		$summary   = array();

		$markup = $converter->convert( '<section><custom-card>Opaque</custom-card></section>', $summary );

		$this->assertSame( 'mixed', $summary['html_block_conversion'] );
		$this->assertSame( 0, $summary['html_inferred_block_count'] );
		$this->assertSame( 1, $summary['html_classic_block_count'] );
		$this->assertStringContainsString( '<!-- wp:freeform -->', $markup );
		$this->assertStringContainsString( '<section><custom-card>Opaque</custom-card></section>', $markup );
	}
}
