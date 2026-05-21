<?php
/**
 * Tests for release activation smoke tooling.
 *
 * @package UniversalImporter
 */

namespace UniversalImporter\Tests\Unit\Release;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use UniversalImporter\Tools\ReleaseActivationSmokeRunner;

require_once dirname( __DIR__, 3 ) . '/tools/ReleaseActivationSmokeRunner.php';

/**
 * Tests release activation smoke runner behavior.
 *
 * @covers \UniversalImporter\Tools\ReleaseActivationSmokeRunner
 */
class ReleaseActivationSmokeRunnerTest extends TestCase {
	/**
	 * Temporary paths to clean up.
	 *
	 * @var string[]
	 */
	private $temporary_paths = array();

	/**
	 * Cleans temporary files.
	 */
	protected function tearDown(): void {
		foreach ( array_reverse( $this->temporary_paths ) as $path ) {
			$this->remove_tree( $path );
		}

		$this->temporary_paths = array();

		parent::tearDown();
	}

	/**
	 * The generated blueprint installs the bundled release and exercises WP-CLI.
	 */
	public function test_creates_playground_blueprint_bundle_for_release_zip() {
		$repo   = dirname( __DIR__, 3 );
		$zip    = $this->temporary_file( 'release.zip', 'zip-placeholder' );
		$runner = new ReleaseActivationSmokeRunner( $repo );

		$bundle                  = $runner->create_blueprint_bundle(
			$zip,
			array(
				'wp_version'  => '6.9',
				'php_version' => '8.3',
			)
		);
		$this->temporary_paths[] = $bundle['bundle_path'];

		$this->assertFileExists( $bundle['blueprint_path'] );
		$this->assertFileExists( $bundle['zip_path'] );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Unit test reads an isolated temporary fixture file.
		$blueprint = json_decode( file_get_contents( $bundle['blueprint_path'] ), true );

		$this->assertSame( '6.9', $blueprint['preferredVersions']['wp'] );
		$this->assertSame( '8.3', $blueprint['preferredVersions']['php'] );
		$this->assertContains( 'wp-cli', $blueprint['extraLibraries'] );
		$this->assertSame( 'installPlugin', $blueprint['steps'][0]['step'] );
		$this->assertSame( 'bundled', $blueprint['steps'][0]['pluginData']['resource'] );
		$this->assertSame( ReleaseActivationSmokeRunner::SMOKE_PLUGIN_ZIP, $blueprint['steps'][0]['pluginData']['path'] );
		$this->assertTrue( $blueprint['steps'][0]['options']['activate'] );
		$this->assertSame( 'universal-wordpress-importer', $blueprint['steps'][0]['options']['targetFolderName'] );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Unit test inspects generated JSON outside WordPress.
		$encoded = json_encode( $blueprint );
		$this->assertStringContainsString( ReleaseActivationSmokeRunner::ACTIVATION_MARKER, $encoded );
		$this->assertStringContainsString( ReleaseActivationSmokeRunner::IMPORT_MARKER, $encoded );
		$this->assertStringContainsString( ReleaseActivationSmokeRunner::REST_SMOKE_MARKER, $encoded );
		$this->assertStringContainsString( ReleaseActivationSmokeRunner::REST_MAPPING_SMOKE_MARKER, $encoded );
		$this->assertStringContainsString( ReleaseActivationSmokeRunner::GITHUB_SMOKE_MARKER, $encoded );
		$this->assertStringContainsString( ReleaseActivationSmokeRunner::BROWSER_UPLOAD_SMOKE_MARKER, $encoded );
		$this->assertStringContainsString( 'Packaged Import Smoke', $encoded );
		$this->assertStringContainsString( 'Packaged HTML Smoke', $encoded );
		$this->assertStringContainsString( 'Packaged HTML Widget Smoke', $encoded );
		$this->assertStringContainsString( 'Packaged Archive Smoke', $encoded );
		$this->assertStringContainsString( 'Packaged WXR Smoke', $encoded );
		$this->assertStringContainsString( 'Packaged EPUB Chapter One', $encoded );
		$this->assertStringContainsString( 'Packaged EPUB Chapter Two', $encoded );
		$this->assertStringContainsString( 'Packaged PDF Smoke', $encoded );
		$this->assertStringContainsString( 'Packaged Unsupported PDF Media Smoke', $encoded );
		$this->assertStringContainsString( 'Packaged External PDF Smoke', $encoded );
		$this->assertStringContainsString( 'Packaged Layout PDF Smoke', $encoded );
		$this->assertStringContainsString( 'Packaged Corrupt PDF Smoke', $encoded );
		$this->assertStringContainsString( 'Packaged REST Smoke', $encoded );
		$this->assertStringContainsString( 'First REST smoke comment.', $encoded );
		$this->assertStringContainsString( 'Nested REST smoke reply.', $encoded );
		$this->assertStringContainsString( 'Remote Smoke Editor', $encoded );
		$this->assertStringContainsString( 'remote_series', $encoded );
		$this->assertStringContainsString( 'Mapped REST Smoke', $encoded );
		$this->assertStringContainsString( 'playground-rest-smoke.example', $encoded );
		$this->assertStringContainsString( 'rest-featured.png', $encoded );
		$this->assertStringContainsString( 'Packaged GitHub Smoke', $encoded );
		$this->assertStringContainsString( 'Packaged GitHub Root Smoke', $encoded );
		$this->assertStringContainsString( 'Packaged GitHub Internal Smoke', $encoded );
		$this->assertStringContainsString( 'Packaged Browser Upload Smoke', $encoded );
		$this->assertStringContainsString( 'A packaged plugin browser-upload import smoke document.', $encoded );
		$this->assertStringContainsString( 'create_import_session_from_uploaded_files', $encoded );
		$this->assertStringContainsString( 'ImportAdminPage::from_globals', $encoded );
		$this->assertStringContainsString( 'uploaded-folder\/chapter.md', $encoded );
		$this->assertStringContainsString( 'browser-uploads', $encoded );
		$this->assertStringContainsString( 'https:\/\/github.com\/example\/repository\/tree\/main\/docs', $encoded );
		$this->assertStringContainsString( 'repos\/example\/repository\/zipball\/main\/docs', $encoded );
		$this->assertStringContainsString( 'github.archive_downloaded', $encoded );
		$this->assertStringContainsString( 'pre_http_request', $encoded );
		$this->assertStringContainsString( "\$parsed_args['stream']", $encoded );
		$this->assertStringContainsString( "\$parsed_args['filename']", $encoded );
		$this->assertStringContainsString( 'archive-smoke.zip', $encoded );
		$this->assertStringContainsString( 'legacy.html', $encoded );
		$this->assertStringContainsString( 'legacy-widgets.html', $encoded );
		$this->assertStringContainsString( 'export-smoke.wxr', $encoded );
		$this->assertStringContainsString( 'book-smoke.epub', $encoded );
		$this->assertStringContainsString( 'document-smoke.pdf', $encoded );
		$this->assertStringContainsString( 'unsupported-media-smoke.pdf', $encoded );
		$this->assertStringContainsString( 'external-text-smoke.pdf', $encoded );
		$this->assertStringContainsString( 'layout-table-smoke.pdf', $encoded );
		$this->assertStringContainsString( 'corrupt-structure-smoke.pdf', $encoded );
		$this->assertStringContainsString( 'external-text-broken.pdf', $encoded );
		$this->assertStringContainsString( 'universal_importer_pdf_text_command', $encoded );
		$this->assertStringContainsString( 'attachment_id=', $encoded );
		$this->assertStringContainsString( 'html_post_id=', $encoded );
		$this->assertStringContainsString( 'html_widget_post_id=', $encoded );
		$this->assertStringContainsString( 'archive_post_id=', $encoded );
		$this->assertStringContainsString( 'wxr_post_id=', $encoded );
		$this->assertStringContainsString( 'epub_first_post_id=', $encoded );
		$this->assertStringContainsString( 'epub_second_post_id=', $encoded );
		$this->assertStringContainsString( 'pdf_post_id=', $encoded );
		$this->assertStringContainsString( 'pdf_attachment_id=', $encoded );
		$this->assertStringContainsString( 'unsupported_pdf_post_id=', $encoded );
		$this->assertStringContainsString( 'external_pdf_post_id=', $encoded );
		$this->assertStringContainsString( 'layout_pdf_post_id=', $encoded );
		$this->assertStringContainsString( 'corrupt_pdf_post_id=', $encoded );
		$this->assertStringContainsString( 'broken_external_pdf=failed', $encoded );
		$this->assertStringContainsString( 'rest_post_id=', $encoded );
		$this->assertStringContainsString( 'rest_attachment_id=', $encoded );
		$this->assertStringContainsString( 'rest_comment_parent_id=', $encoded );
		$this->assertStringContainsString( 'rest_comment_child_id=', $encoded );
		$this->assertStringContainsString( 'rest_mapping_post_id=', $encoded );
		$this->assertStringContainsString( 'rest_mapping_term_id=', $encoded );
		$this->assertStringContainsString( 'github_post_id=', $encoded );
		$this->assertStringContainsString( 'UNIVERSAL_IMPORTER_REST_MAPPING_ADMIN_DECISION_RESOLVED', $encoded );
		$this->assertStringContainsString( 'UniversalImporter\\\\Admin\\\\ImportAdminPage::from_globals', $encoded );
		$this->assertStringContainsString( 'Import decision was resolved from the WordPress admin page.', $encoded );
		$this->assertStringContainsString( '_universal_importer_media_reference_key', $encoded );
		$this->assertSame( 'mkdir', $blueprint['steps'][2]['step'] );
		$this->assertSame( '/tmp/universal-importer-smoke', $blueprint['steps'][2]['path'] );
		$this->assertSame( 'runPHP', $blueprint['steps'][3]['step'] );
		$this->assertStringContainsString( "# Packaged Import Smoke\n\nA packaged plugin import smoke document.\n\n![Smoke image](assets/smoke.png)\n", $blueprint['steps'][3]['code'] );
		$this->assertStringContainsString( 'Packaged HTML Smoke', $blueprint['steps'][3]['code'] );
		$this->assertStringContainsString( 'assets/html-smoke.png', $blueprint['steps'][3]['code'] );
		$this->assertStringContainsString( 'HTML figure caption.', $blueprint['steps'][3]['code'] );
		$this->assertStringContainsString( 'HTML linked image caption.', $blueprint['steps'][3]['code'] );
		$this->assertStringContainsString( 'HTML table caption.', $blueprint['steps'][3]['code'] );
		$this->assertStringContainsString( 'html-smoke-separator', $blueprint['steps'][3]['code'] );
		$this->assertStringContainsString( 'html-smoke-code', $blueprint['steps'][3]['code'] );
		$this->assertStringContainsString( 'html-smoke-preformatted', $blueprint['steps'][3]['code'] );
		$this->assertStringContainsString( '&lt;?php echo "HTML smoke";', $blueprint['steps'][3]['code'] );
		$this->assertStringContainsString( 'Gallery image one.', $blueprint['steps'][3]['code'] );
		$this->assertStringContainsString( 'HTML video caption.', $blueprint['steps'][3]['code'] );
		$this->assertStringContainsString( 'HTML audio caption.', $blueprint['steps'][3]['code'] );
		$this->assertStringContainsString( 'media/html-wrapper.mp4', $blueprint['steps'][3]['code'] );
		$this->assertStringContainsString( 'HTML wrapper audio caption.', $blueprint['steps'][3]['code'] );
		$this->assertStringContainsString( 'HTML pullquote smoke.', $blueprint['steps'][3]['code'] );
		$this->assertStringContainsString( 'Legacy accordion one', $blueprint['steps'][3]['code'] );
		$this->assertStringContainsString( 'html-smoke-columns', $blueprint['steps'][3]['code'] );
		$this->assertStringContainsString( 'html-smoke-left-column', $blueprint['steps'][3]['code'] );
		$this->assertStringContainsString( 'HTML smoke tab one', $blueprint['steps'][3]['code'] );
		$this->assertStringContainsString( 'HTML smoke legacy image widget', $blueprint['steps'][3]['code'] );
		$this->assertStringContainsString( 'HTML smoke legacy search widget', $blueprint['steps'][3]['code'] );
		$this->assertStringContainsString( 'HTML smoke warning callout.', $blueprint['steps'][3]['code'] );
		$this->assertStringContainsString( 'HTML smoke feature card.', $blueprint['steps'][3]['code'] );
		$this->assertStringContainsString( '/html-smoke-form', $blueprint['steps'][3]['code'] );
		$this->assertStringContainsString( '/html-smoke-frame', $blueprint['steps'][3]['code'] );
		$this->assertStringContainsString( 'HTML button CTA', $blueprint['steps'][3]['code'] );
		$this->assertStringContainsString( 'HTML file download', $blueprint['steps'][3]['code'] );
		$this->assertStringContainsString( 'Inline HTML media intro', $blueprint['steps'][3]['code'] );
		$this->assertStringContainsString( 'HTML inline action', $blueprint['steps'][3]['code'] );
		$this->assertStringContainsString( 'archive/chapter.md', $blueprint['steps'][3]['code'] );
		$this->assertStringContainsString( '<wp:post_id>91</wp:post_id>', $blueprint['steps'][3]['code'] );
		$this->assertStringContainsString( 'chapter-two.xhtml#part', $blueprint['steps'][3]['code'] );
		$this->assertStringContainsString( 'documents/document-smoke.pdf', $blueprint['steps'][3]['code'] );
		$this->assertStringContainsString( 'documents/unsupported-media-smoke.pdf', $blueprint['steps'][3]['code'] );
		$this->assertStringContainsString( 'documents/corrupt-structure-smoke.pdf', $blueprint['steps'][3]['code'] );
		$this->assertStringContainsString( 'Release smoke carries a binary PDF fixture through JSON', $blueprint['steps'][3]['code'] );
		$this->assertStringContainsString( 'Packaged External PDF Smoke', $blueprint['steps'][3]['code'] );
		$this->assertStringContainsString( 'Packaged Layout PDF Smoke', $blueprint['steps'][3]['code'] );
		$this->assertStringContainsString( 'Name      Count    Total', $blueprint['steps'][3]['code'] );
		$this->assertStringContainsString( 'pdf-text-helper.php', $blueprint['steps'][3]['code'] );
		$this->assertStringContainsString( '/wordpress/wp-content/mu-plugins', $blueprint['steps'][3]['code'] );
		$this->assertStringContainsString( "base64_decode( 'iVBORw0KGgo", $blueprint['steps'][3]['code'] );
		$this->assertStringContainsString( 'new ZipArchive()', $blueprint['steps'][3]['code'] );
		$this->assertSame( 'wp universal-importer import /tmp/universal-importer-smoke --confirm-first-party-domains=example.test', $blueprint['steps'][4]['command'] );
		$rest_import_steps    = array_values(
			array_filter(
				$blueprint['steps'],
				static function ( $step ) {
					return isset( $step['command'] ) && 'wp universal-importer import https://playground-rest-smoke.example/wp-json/ --confirm-first-party-domains=playground-rest-smoke.example' === $step['command'];
				}
			)
		);
		$github_import_steps  = array_values(
			array_filter(
				$blueprint['steps'],
				static function ( $step ) {
					return isset( $step['command'] ) && 'wp universal-importer import https://github.com/example/repository/tree/main/docs' === $step['command'];
				}
			)
		);
		$tick_steps           = array_values(
			array_filter(
				$blueprint['steps'],
				static function ( $step ) {
					return isset( $step['command'] ) && 'wp universal-importer tick --max-ticks=1' === $step['command'];
				}
			)
		);
		$unbounded_tick_steps = array_values(
			array_filter(
				$blueprint['steps'],
				static function ( $step ) {
					return isset( $step['command'] ) && 'wp universal-importer tick' === $step['command'];
				}
			)
		);
		$last_step            = $blueprint['steps'][ count( $blueprint['steps'] ) - 1 ];

		$this->assertCount( 1, $rest_import_steps );
		$this->assertCount( 1, $github_import_steps );
		$this->assertCount( ReleaseActivationSmokeRunner::IMPORT_SMOKE_MAX_TICKS * 4, $tick_steps );
		$this->assertCount( 1, $unbounded_tick_steps );
		$this->assertSame( 'runPHP', $last_step['step'] );
		$this->assertStringContainsString( 'WP-CLI smoke import did not persist the expected imported Markdown page with rewritten local media', $encoded );
		$this->assertStringContainsString( 'WP-CLI smoke import did not persist the expected imported page from the HTML fixture', $encoded );
		$this->assertStringContainsString( 'WP-CLI smoke import did not preserve the expected legacy widget Classic fallback page from the HTML widget fixture', $encoded );
		$this->assertStringContainsString( 'WP-CLI smoke import did not persist the expected imported Markdown page from the GitHub repository subtree', $encoded );
		$this->assertStringContainsString( 'wp-element-caption', $encoded );
		$this->assertStringContainsString( 'HTML figure caption.', $encoded );
		$this->assertStringContainsString( 'HTML linked image caption.', $encoded );
		$this->assertStringContainsString( 'linkDestination', $encoded );
		$this->assertStringContainsString( 'html-smoke-figure-image', $encoded );
		$this->assertStringContainsString( 'html-smoke-table', $encoded );
		$this->assertStringContainsString( 'html-smoke-separator', $encoded );
		$this->assertStringContainsString( 'is-style-dots', $encoded );
		$this->assertStringContainsString( 'html-smoke-code', $encoded );
		$this->assertStringContainsString( 'html-smoke-preformatted', $encoded );
		$this->assertStringContainsString( 'html-smoke-step-four', $encoded );
		$this->assertStringContainsString( '<!-- wp:list-item', $encoded );
		$this->assertStringContainsString( 'HTML table caption.', $encoded );
		$this->assertStringContainsString( '<!-- wp:code', $encoded );
		$this->assertStringContainsString( 'anchor\\":\\"html-smoke-code', $encoded );
		$this->assertStringContainsString( '<!-- wp:preformatted', $encoded );
		$this->assertStringContainsString( 'anchor\\":\\"html-smoke-preformatted', $encoded );
		$this->assertStringContainsString( 'wp:gallery', $encoded );
		$this->assertStringContainsString( 'linkTo', $encoded );
		$this->assertStringContainsString( '<!-- wp:video', $encoded );
		$this->assertStringContainsString( '<!-- wp:audio', $encoded );
		$this->assertStringContainsString( 'html-wrapper.mp4', $encoded );
		$this->assertStringContainsString( 'HTML wrapper audio caption.', $encoded );
		$this->assertStringContainsString( '<!-- wp:html -->', $encoded );
		$this->assertStringContainsString( '<!-- wp:freeform -->', $encoded );
		$this->assertStringContainsString( 'widget_media_image', $encoded );
		$this->assertStringContainsString( 'html-smoke-widget-image', $encoded );
		$this->assertStringContainsString( 'widget_search', $encoded );
		$this->assertStringContainsString( 'html-smoke-widget-search', $encoded );
		$this->assertStringContainsString( 'HTML smoke legacy search widget', $encoded );
		$this->assertStringContainsString( 'html-smoke-pullquote', $encoded );
		$this->assertStringContainsString( 'html-smoke-figure-quote', $encoded );
		$this->assertStringContainsString( 'has-text-align-center', $encoded );
		$this->assertStringContainsString( 'html-smoke-form', $encoded );
		$this->assertStringContainsString( 'html-legacy-accordion-one', $encoded );
		$this->assertStringContainsString( 'HTML smoke tab one', $encoded );
		$this->assertStringContainsString( 'universal-importer-callout universal-importer-callout-warning', $encoded );
		$this->assertStringContainsString( 'universal-importer-card', $encoded );
		$this->assertStringContainsString( 'wp:buttons', $encoded );
		$this->assertStringContainsString( 'html-smoke-button-row', $encoded );
		$this->assertStringContainsString( '<!-- wp:file ', $encoded );
		$this->assertStringContainsString( 'html-smoke-file-row', $encoded );
		$this->assertStringContainsString( 'html-smoke-vimeo-embed', $encoded );
		$this->assertStringContainsString( 'html-smoke-columns', $encoded );
		$this->assertStringContainsString( 'html-smoke-left-column', $encoded );
		$this->assertStringContainsString( 'html-smoke-right-column', $encoded );
		$this->assertStringContainsString( 'Inline HTML media intro', $encoded );
		$this->assertStringContainsString( 'Inline HTML media outro', $encoded );
		$this->assertStringContainsString( 'HTML inline action', $encoded );
		$this->assertStringContainsString( 'WP-CLI smoke import did not persist the expected imported Markdown page from the zip archive', $encoded );
		$this->assertStringContainsString( 'WP-CLI smoke import did not persist the expected imported page from the WXR export', $encoded );
		$this->assertStringContainsString( 'WP-CLI smoke import did not persist the expected imported pages from the EPUB spine', $encoded );
		$this->assertStringContainsString( 'WP-CLI smoke import did not resolve the EPUB internal chapter link', $encoded );
		$this->assertStringContainsString( 'embedded image attachment rewrite, and PDF media extraction metadata', $encoded );
		$this->assertStringContainsString( 'WP-CLI smoke import did not import the embedded PDF image attachment', $encoded );
		$this->assertStringContainsString( 'unsupported embedded PDF media diagnostics', $encoded );
		$this->assertStringContainsString( 'media.pdf_asset_unsupported', $encoded );
		$this->assertStringContainsString( 'external text metadata from the textless PDF fixture', $encoded );
		$this->assertStringContainsString( 'layout-aware PDF table block and pdf_table metadata', $encoded );
		$this->assertStringContainsString( 'document.pdf_table_blocks', $encoded );
		$this->assertStringContainsString( 'corrupt PDF structure diagnostics', $encoded );
		$this->assertStringContainsString( 'document.pdf_structure_warning', $encoded );
		$this->assertStringContainsString( 'failed external PDF helper diagnostics', $encoded );
		$this->assertStringContainsString( 'WP-CLI smoke import did not persist the expected imported page from the WordPress REST traversal', $encoded );
		$this->assertStringContainsString( 'WP-CLI smoke import did not import the REST featured image attachment', $encoded );
		$this->assertStringContainsString( 'WP-CLI smoke import did not persist the expected REST comments', $encoded );
		$this->assertStringContainsString( 'pending REST relationship mapping decision', $encoded );
		$this->assertStringContainsString( 'comment.created', $encoded );
		$this->assertStringContainsString( 'resolved REST relationship mapping', $encoded );
		$this->assertStringContainsString( 'admin decision surface', $encoded );
		$this->assertStringContainsString( 'relationship-mapping', $encoded );
		$this->assertStringContainsString( 'GitHub repository subtree', $encoded );
		$this->assertStringContainsString( 'archive.expanded', $encoded );
		$this->assertStringContainsString( 'Admin browser-upload smoke did not persist the expected imported Markdown page', $last_step['code'] );
		$this->assertStringContainsString( 'session.created', $last_step['code'] );
	}

	/**
	 * The local smoke fixture installs a deterministic external PDF helper filter.
	 */
	public function test_local_external_pdf_helper_filter_uses_smoke_helper() {
		$repo    = dirname( __DIR__, 3 );
		$runner  = new ReleaseActivationSmokeRunner( $repo );
		$wp_dir  = $this->temporary_directory();
		$source  = $this->temporary_directory();
		$workdir = $this->temporary_directory();

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Unit test creates isolated WordPress fixture directories.
		$this->assertTrue( mkdir( $wp_dir . '/wp-content', 0777, true ) );

		$this->invoke_private_method( $runner, 'create_local_import_smoke_fixture', array( $source ) );
		$this->invoke_private_method( $runner, 'install_local_pdf_external_text_smoke_filter', array( $wp_dir, $source ) );
		$this->invoke_private_method( $runner, 'install_local_rest_mapping_http_fixture', array( $wp_dir ) );
		$this->invoke_private_method( $runner, 'install_local_github_http_fixture', array( $wp_dir, $workdir ) );

		$helper         = $source . '/' . ReleaseActivationSmokeRunner::EXTERNAL_PDF_HELPER_FILE;
		$plugin         = $wp_dir . '/wp-content/mu-plugins/universal-importer-pdf-text-smoke.php';
		$rest_plugin    = $wp_dir . '/wp-content/mu-plugins/universal-importer-rest-mapping-http-smoke.php';
		$github_plugin  = $wp_dir . '/wp-content/mu-plugins/universal-importer-github-http-smoke.php';
		$github_archive = $workdir . '/github-smoke/repository.zip';

		$this->assertFileExists( $source . '/' . ReleaseActivationSmokeRunner::EXTERNAL_PDF_SMOKE_FILE );
		$this->assertFileExists( $source . '/' . ReleaseActivationSmokeRunner::HTML_SMOKE_FILE );
		$this->assertFileExists( $source . '/' . ReleaseActivationSmokeRunner::HTML_WIDGET_SMOKE_FILE );
		$this->assertFileExists( $source . '/' . ReleaseActivationSmokeRunner::HTML_SMOKE_IMAGE );
		$this->assertFileExists( $source . '/' . ReleaseActivationSmokeRunner::UNSUPPORTED_PDF_SMOKE_FILE );
		$this->assertFileExists( $source . '/' . ReleaseActivationSmokeRunner::LAYOUT_PDF_SMOKE_FILE );
		$this->assertFileExists( $source . '/' . ReleaseActivationSmokeRunner::CORRUPT_PDF_SMOKE_FILE );
		$this->assertFileExists( $source . '/' . ReleaseActivationSmokeRunner::BROKEN_EXTERNAL_PDF_FILE );
		$this->assertFileExists( $helper );
		$this->assertFileExists( $plugin );
		$this->assertFileExists( $rest_plugin );
		$this->assertFileExists( $github_plugin );
		$this->assertFileExists( $github_archive );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Unit test reads isolated temporary fixture files.
		$plugin_code = file_get_contents( $plugin );
		$this->assertStringContainsString( 'universal_importer_pdf_text_command', $plugin_code );
		$this->assertStringContainsString( 'http_allowed_safe_ports', $plugin_code );
		$this->assertStringContainsString( 'http_request_host_is_external', $plugin_code );
		$this->assertStringContainsString( 'universal_importer_rest_smoke_port', $plugin_code );
		$this->assertStringContainsString( $helper, $plugin_code );
		$this->assertStringContainsString( '{input} {output}', $plugin_code );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Unit test reads an isolated generated mu-plugin.
		$rest_plugin_code = file_get_contents( $rest_plugin );
		$this->assertStringContainsString( 'pre_http_request', $rest_plugin_code );
		$this->assertStringContainsString( 'local-rest-mapping-smoke.example', $rest_plugin_code );
		$this->assertStringContainsString( 'Remote Smoke Editor', $rest_plugin_code );
		$this->assertStringContainsString( 'remote_series', $rest_plugin_code );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Unit test reads an isolated generated mu-plugin.
		$github_plugin_code = file_get_contents( $github_plugin );
		$this->assertStringContainsString( 'pre_http_request', $github_plugin_code );
		$this->assertStringContainsString( 'api.github.com', $github_plugin_code );
		$this->assertStringContainsString( '/repos/example/repository/zipball/main/docs', $github_plugin_code );
		$this->assertStringContainsString( "\$parsed_args['stream']", $github_plugin_code );
		$this->assertStringContainsString( $github_archive, $github_plugin_code );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Unit test reads an isolated temporary helper fixture.
		$helper_code = file_get_contents( $helper );
		$this->assertStringContainsString( 'external-text-smoke.pdf', $helper_code );
		$this->assertStringContainsString( 'layout-table-smoke.pdf', $helper_code );
		$this->assertStringContainsString( 'Name      Count    Total', $helper_code );
	}

	/**
	 * Browser-upload release smoke uses the packaged admin upload staging path.
	 *
	 * @return void
	 */
	public function test_browser_upload_smoke_uses_admin_upload_session_path() {
		$runner    = new ReleaseActivationSmokeRunner( dirname( __DIR__, 3 ) );
		$setup     = $this->invoke_private_method( $runner, 'browser_upload_setup_php' );
		$assertion = $this->invoke_private_method( $runner, 'browser_upload_assertion_php' );

		$this->assertStringContainsString( 'ImportAdminPage::from_globals', $setup );
		$this->assertStringContainsString( 'create_import_session_from_uploaded_files', $setup );
		$this->assertStringContainsString( 'UPLOAD_ERR_OK', $setup );
		$this->assertStringContainsString( 'uploaded-folder/chapter.md', $setup );
		$this->assertStringContainsString( ReleaseActivationSmokeRunner::BROWSER_UPLOAD_SESSION_OPT, $setup );
		$this->assertStringContainsString( ReleaseActivationSmokeRunner::BROWSER_UPLOAD_SMOKE_TITLE, $setup );
		$this->assertStringContainsString( ReleaseActivationSmokeRunner::BROWSER_UPLOAD_SMOKE_BODY, $setup );

		$this->assertStringContainsString( ReleaseActivationSmokeRunner::BROWSER_UPLOAD_SMOKE_MARKER, $assertion );
		$this->assertStringContainsString( ReleaseActivationSmokeRunner::BROWSER_UPLOAD_SMOKE_TITLE, $assertion );
		$this->assertStringContainsString( ReleaseActivationSmokeRunner::BROWSER_UPLOAD_SMOKE_BODY, $assertion );
		$this->assertStringContainsString( '/browser-uploads/', $assertion );
		$this->assertStringContainsString( 'session.created', $assertion );
		$this->assertStringContainsString( 'upload_files', $assertion );
	}

	/**
	 * The local clean-site smoke includes a focused REST traversal assertion.
	 */
	public function test_local_rest_smoke_assertion_checks_rest_output() {
		$runner = new ReleaseActivationSmokeRunner( dirname( __DIR__, 3 ) );

		$assertion = $this->invoke_private_method( $runner, 'rest_import_assertion_php' );
		$router    = $this->invoke_private_method( $runner, 'local_wordpress_router_php', array( '/tmp/wordpress', 'http://127.0.0.1:49152' ) );
		$fixture   = $this->invoke_private_method( $runner, 'playground_rest_fixture_setup_php' );

		$this->assertStringContainsString( ReleaseActivationSmokeRunner::REST_SMOKE_MARKER, $assertion );
		$this->assertStringContainsString( 'Packaged REST Smoke', $assertion );
		$this->assertStringContainsString( '_universal_importer_document_format', $assertion );
		$this->assertStringContainsString( 'wp-rest', $assertion );
		$this->assertStringContainsString( 'remote-rest:', $assertion );
		$this->assertStringContainsString( 'rest-featured.png', $assertion );
		$this->assertStringContainsString( 'rest_attachment_id=', $assertion );
		$this->assertStringContainsString( 'rest_comment_parent_id=', $assertion );
		$this->assertStringContainsString( 'rest_comment_child_id=', $assertion );
		$this->assertStringContainsString( 'First REST smoke comment.', $assertion );
		$this->assertStringContainsString( 'Nested REST smoke reply.', $assertion );
		$this->assertStringContainsString( 'comment.created', $assertion );
		$this->assertStringContainsString( 'false !== strpos( $post->post_content, \'<!-- wp:image \' )', $assertion );
		$this->assertStringContainsString( 'false !== strpos( $post->post_content, \'<!-- wp:paragraph -->\' )', $assertion );
		$this->assertStringContainsString( 'false === strpos( $post->post_content, \'<!-- wp:freeform -->\' )', $assertion );
		$this->assertStringContainsString( 'false === strpos( $post->post_content, \'<script\' )', $assertion );
		$this->assertStringContainsString( "\$_GET['rest_route']", $router );
		$this->assertStringContainsString( '/wp-json', $router );
		$this->assertStringContainsString( "define( 'WP_HOME'", $router );
		$this->assertStringContainsString( 'http://127.0.0.1:49152', $router );
		$this->assertStringContainsString( 'pre_http_request', $fixture );
		$this->assertStringContainsString( 'playground-rest-smoke.example', $fixture );
		$this->assertStringContainsString( '/wp/v2/pages', $fixture );
		$this->assertStringContainsString( '/wp/v2/media/850', $fixture );
		$this->assertStringContainsString( '/wp/v2/comments', $fixture );
		$this->assertStringContainsString( '/wp-content/uploads/rest-featured.png', $fixture );
		$this->assertStringContainsString( 'First REST smoke comment.', $fixture );
		$this->assertStringContainsString( 'Nested REST smoke reply.', $fixture );
		$this->assertStringContainsString( 'Packaged REST Smoke', $fixture );
		$this->assertTrue( $this->invoke_private_method( $runner, 'response_is_rest_index', array( '{"namespaces":["wp\/v2"]}' ) ) );
		$this->assertFalse( $this->invoke_private_method( $runner, 'response_is_rest_index', array( '{"namespaces":["oembed\/1.0"]}' ) ) );
	}

	/**
	 * The Playground command pins the requested runtime versions.
	 */
	public function test_builds_playground_command_with_runtime_versions() {
		$runner  = new ReleaseActivationSmokeRunner( dirname( __DIR__, 3 ) );
		$command = $runner->build_playground_command( '@wp-playground/cli@1.2.3', '/tmp/blueprint.json', '6.8', '8.2' );

		$this->assertSame(
			array(
				'npx',
				'--yes',
				'@wp-playground/cli@1.2.3',
				'run-blueprint',
				'--blueprint=/tmp/blueprint.json',
				'--blueprint-may-read-adjacent-files',
				'--wp=6.8',
				'--php=8.2',
				'--verbosity=normal',
			),
			$command
		);
	}

	/**
	 * Local runtime commands keep WP-CLI global flags before the command.
	 */
	public function test_builds_wp_cli_command_with_path_before_subcommand() {
		$runner  = new ReleaseActivationSmokeRunner( dirname( __DIR__, 3 ) );
		$command = $runner->build_wp_cli_command( array( 'php', '/tmp/wp-cli.phar' ), '/tmp/wp', array( 'plugin', 'activate', 'universal-wordpress-importer' ) );

		$this->assertSame(
			array(
				'php',
				'/tmp/wp-cli.phar',
				'--path=/tmp/wp',
				'plugin',
				'activate',
				'universal-wordpress-importer',
			),
			$command
		);
	}

	/**
	 * Auto runtime only falls back for environment-level Playground failures.
	 */
	public function test_classifies_playground_infrastructure_failures() {
		$this->assertTrue( ReleaseActivationSmokeRunner::is_playground_infrastructure_failure( 'Error: fetch failed' ) );
		$this->assertTrue( ReleaseActivationSmokeRunner::is_playground_infrastructure_failure( 'WordPress Playground CLI requires Node.js 20.18 or newer.' ) );
		$this->assertFalse( ReleaseActivationSmokeRunner::is_playground_infrastructure_failure( 'Importer table is missing after activation.' ) );
	}

	/**
	 * Node version validation follows Playground CLI's documented minimum.
	 */
	public function test_node_version_support_check() {
		$this->assertTrue( ReleaseActivationSmokeRunner::is_supported_node_version( 'v20.18.0' ) );
		$this->assertTrue( ReleaseActivationSmokeRunner::is_supported_node_version( 'v22.20.0' ) );
		$this->assertFalse( ReleaseActivationSmokeRunner::is_supported_node_version( 'v20.17.9' ) );
		$this->assertFalse( ReleaseActivationSmokeRunner::is_supported_node_version( 'not-a-version' ) );
	}

	/**
	 * Missing release zips fail before the Playground runtime is invoked.
	 */
	public function test_missing_zip_has_actionable_error() {
		$runner = new ReleaseActivationSmokeRunner( dirname( __DIR__, 3 ) );

		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'Release zip does not exist' );

		$runner->run( array( 'zip_path' => '/tmp/universal-importer-missing-release.zip' ) );
	}

	/**
	 * Existing but invalid release zips fail integrity checks before runtime setup.
	 */
	public function test_corrupt_zip_fails_integrity_check_before_runtime_setup() {
		$zip_path = $this->temporary_file( 'corrupt-release.zip', 'not a zip archive' );
		$runner   = new ReleaseActivationSmokeRunner( dirname( __DIR__, 3 ) );

		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'Unable to verify release zip package integrity' );
		$this->expectExceptionMessage( 'Release zip integrity failed: Unable to open release zip' );

		$runner->run( array( 'zip_path' => $zip_path ) );
	}

	/**
	 * Creates a temporary file.
	 *
	 * @param string $name    File name.
	 * @param string $content File content.
	 * @return string File path.
	 */
	private function temporary_file( $name, $content ) {
		$dir  = $this->temporary_directory();
		$path = $dir . '/' . $name;

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Unit test writes isolated temporary fixture files.
		$this->assertNotFalse( file_put_contents( $path, $content ) );

		return $path;
	}

	/**
	 * Invokes a private runner method.
	 *
	 * @param ReleaseActivationSmokeRunner $runner Runner.
	 * @param string                       $method Method name.
	 * @param array<int,mixed>             $args   Arguments.
	 * @return mixed
	 */
	private function invoke_private_method( ReleaseActivationSmokeRunner $runner, $method, array $args = array() ) {
		$reflection = new \ReflectionMethod( $runner, $method );
		$reflection->setAccessible( true );

		return $reflection->invokeArgs( $runner, $args );
	}

	/**
	 * Creates a temporary directory.
	 *
	 * @return string Directory path.
	 */
	private function temporary_directory() {
		$path = tempnam( sys_get_temp_dir(), 'universal-importer-smoke-test-' );
		$this->assertNotFalse( $path );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Unit test owns this temporary fixture path.
		$this->assertTrue( unlink( $path ) );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Unit test creates isolated temporary fixture directories.
		$this->assertTrue( mkdir( $path, 0777, true ) );

		$this->temporary_paths[] = $path;

		return $path;
	}

	/**
	 * Removes a directory tree.
	 *
	 * @param string $path Directory path.
	 */
	private function remove_tree( $path ) {
		if ( ! is_dir( $path ) ) {
			return;
		}

		$items = scandir( $path );

		if ( false === $items ) {
			return;
		}

		foreach ( $items as $item ) {
			if ( '.' === $item || '..' === $item ) {
				continue;
			}

			$child = $path . '/' . $item;

			if ( is_dir( $child ) && ! is_link( $child ) ) {
				$this->remove_tree( $child );
				continue;
			}

			// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Unit test owns this temporary fixture path.
			unlink( $child );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Unit test owns this temporary fixture path.
		rmdir( $path );
	}
}
