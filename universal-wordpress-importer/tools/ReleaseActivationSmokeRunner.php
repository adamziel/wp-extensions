<?php
/**
 * Smoke-tests a release zip in a disposable WordPress install.
 *
 * @package UniversalImporter
 */

namespace UniversalImporter\Tools;

use RuntimeException;
use ZipArchive;

require_once __DIR__ . '/ReleasePackageBuilder.php';

/**
 * Builds a Playground blueprint bundle and runs activation/import smoke checks.
 */
class ReleaseActivationSmokeRunner {
	const SMOKE_PLUGIN_ZIP             = 'universal-wordpress-importer-smoke.zip';
	const ACTIVATION_MARKER            = 'UNIVERSAL_IMPORTER_ACTIVATION_SMOKE_OK';
	const IMPORT_MARKER                = 'UNIVERSAL_IMPORTER_IMPORT_SMOKE_OK';
	const WP_CLI_PHAR_URL              = 'https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar';
	const IMPORT_SMOKE_TITLE           = 'Packaged Import Smoke';
	const IMPORT_SMOKE_BODY            = 'A packaged plugin import smoke document.';
	const IMPORT_SMOKE_IMAGE           = 'assets/smoke.png';
	const HTML_SMOKE_TITLE             = 'Packaged HTML Smoke';
	const HTML_SMOKE_BODY              = 'A packaged plugin HTML import smoke document.';
	const HTML_SMOKE_FILE              = 'legacy.html';
	const HTML_SMOKE_IMAGE             = 'assets/html-smoke.png';
	const HTML_WIDGET_SMOKE_TITLE      = 'Packaged HTML Widget Smoke';
	const HTML_WIDGET_SMOKE_BODY       = 'A packaged plugin legacy widget HTML import smoke document.';
	const HTML_WIDGET_SMOKE_FILE       = 'legacy-widgets.html';
	const ARCHIVE_SMOKE_TITLE          = 'Packaged Archive Smoke';
	const ARCHIVE_SMOKE_BODY           = 'A packaged plugin archive traversal document.';
	const ARCHIVE_SMOKE_ZIP            = 'archives/archive-smoke.zip';
	const ARCHIVE_SMOKE_ENTRY          = 'archive/chapter.md';
	const WXR_SMOKE_TITLE              = 'Packaged WXR Smoke';
	const WXR_SMOKE_BODY               = 'A packaged plugin WXR import smoke document.';
	const WXR_SMOKE_FILE               = 'exports/export-smoke.wxr';
	const EPUB_SMOKE_TITLE             = 'Packaged EPUB Smoke';
	const EPUB_SMOKE_CHAPTER_ONE       = 'Packaged EPUB Chapter One';
	const EPUB_SMOKE_CHAPTER_TWO       = 'Packaged EPUB Chapter Two';
	const EPUB_SMOKE_BODY              = 'A packaged plugin EPUB import smoke document.';
	const EPUB_SMOKE_FILE              = 'books/book-smoke.epub';
	const PDF_SMOKE_TITLE              = 'Packaged PDF Smoke';
	const PDF_SMOKE_BODY               = 'A packaged plugin PDF import smoke document.';
	const PDF_SMOKE_FILE               = 'documents/document-smoke.pdf';
	const UNSUPPORTED_PDF_SMOKE_TITLE = 'Packaged Unsupported PDF Media Smoke';
	const UNSUPPORTED_PDF_SMOKE_BODY  = 'A packaged plugin PDF unsupported media diagnostic document.';
	const UNSUPPORTED_PDF_SMOKE_FILE  = 'documents/unsupported-media-smoke.pdf';
	const EXTERNAL_PDF_SMOKE_TITLE    = 'Packaged External PDF Smoke';
	const EXTERNAL_PDF_SMOKE_BODY     = 'A packaged plugin external PDF text document.';
	const EXTERNAL_PDF_SMOKE_FILE     = 'documents/external-text-smoke.pdf';
	const LAYOUT_PDF_SMOKE_TITLE      = 'Packaged Layout PDF Smoke';
	const LAYOUT_PDF_SMOKE_BODY       = 'A packaged plugin layout-aware PDF table document.';
	const LAYOUT_PDF_SMOKE_FILE       = 'documents/layout-table-smoke.pdf';
	const CORRUPT_PDF_SMOKE_TITLE     = 'Packaged Corrupt PDF Smoke';
	const CORRUPT_PDF_SMOKE_BODY      = 'A packaged plugin corrupt PDF structure diagnostic document.';
	const CORRUPT_PDF_SMOKE_FILE      = 'documents/corrupt-structure-smoke.pdf';
	const BROKEN_EXTERNAL_PDF_FILE    = 'documents/external-text-broken.pdf';
	const EXTERNAL_PDF_HELPER_FILE    = 'pdf-text-helper.php';
	const REST_SMOKE_MARKER           = 'UNIVERSAL_IMPORTER_REST_SMOKE_OK';
	const REST_SMOKE_TITLE            = 'Packaged REST Smoke';
	const REST_SMOKE_BODY             = 'A packaged plugin REST import smoke document.';
	const REST_SMOKE_IMAGE            = 'rest-featured.png';
	const REST_SMOKE_COMMENT          = 'First REST smoke comment.';
	const REST_SMOKE_REPLY            = 'Nested REST smoke reply.';
	const REST_MAPPING_SMOKE_MARKER   = 'UNIVERSAL_IMPORTER_REST_MAPPING_SMOKE_OK';
	const REST_REMOTE_AUTHOR_NAME     = 'Remote Smoke Editor';
	const REST_REMOTE_AUTHOR_SLUG     = 'remote-smoke-editor';
	const REST_REMOTE_TAXONOMY        = 'remote_series';
	const REST_REMOTE_TERM_NAME       = 'Remote Smoke Series';
	const REST_REMOTE_TERM_SLUG       = 'remote-smoke-series';
	const REST_LOCAL_TERM_NAME        = 'Mapped REST Smoke';
	const REST_LOCAL_TERM_SLUG        = 'mapped-rest-smoke';
	const REST_MAPPING_FIXTURE_HOST   = 'local-rest-mapping-smoke.example';
	const GITHUB_SMOKE_MARKER         = 'UNIVERSAL_IMPORTER_GITHUB_SMOKE_OK';
	const GITHUB_SMOKE_TITLE          = 'Packaged GitHub Smoke';
	const GITHUB_SMOKE_BODY           = 'A packaged plugin GitHub subtree import smoke document.';
	const GITHUB_SMOKE_ROOT_TITLE     = 'Packaged GitHub Root Smoke';
	const GITHUB_SMOKE_INTERNAL_TITLE = 'Packaged GitHub Internal Smoke';
	const BROWSER_UPLOAD_SMOKE_MARKER = 'UNIVERSAL_IMPORTER_BROWSER_UPLOAD_SMOKE_OK';
	const BROWSER_UPLOAD_SMOKE_TITLE  = 'Packaged Browser Upload Smoke';
	const BROWSER_UPLOAD_SMOKE_BODY   = 'A packaged plugin browser-upload import smoke document.';
	const BROWSER_UPLOAD_SESSION_OPT  = 'universal_importer_browser_upload_smoke_session';
	const IMPORT_SMOKE_MAX_TICKS      = 20;

	/**
	 * Repository root.
	 *
	 * @var string
	 */
	private $repo_root;

	/**
	 * Creates a runner.
	 *
	 * @param string $repo_root Repository root.
	 */
	public function __construct( $repo_root ) {
		$real = realpath( $repo_root );

		if ( false === $real || ! is_dir( $real ) ) {
			throw new RuntimeException( 'Repository root does not exist: ' . $repo_root );
		}

		$this->repo_root = rtrim( str_replace( '\\', '/', $real ), '/' );
	}

	/**
	 * Runs the activation smoke check.
	 *
	 * @param array $options Smoke options.
	 * @return array Smoke summary.
	 */
	public function run( array $options = array() ) {
		$options = array_merge(
			array(
				'zip_path'            => null,
				'build_release'       => false,
				'output_dir'          => 'dist',
				'allow_dirty'         => false,
				'run_build_checks'    => true,
				'use_existing_vendor' => false,
				'playground_cli'      => '@wp-playground/cli@latest',
				'runtime'             => 'auto',
				'wp_version'          => 'latest',
				'php_version'         => '8.3',
				'wp_cli_phar'         => null,
				'keep_bundle'         => false,
				'keep_workdir'        => false,
			),
			$options
		);

		$zip_path = $this->resolve_release_zip( $options );

		switch ( $options['runtime'] ) {
			case 'auto':
				try {
					return $this->run_playground_smoke( $zip_path, $options );
				} catch ( RuntimeException $error ) {
					if ( ! self::is_playground_infrastructure_failure( $error->getMessage() ) ) {
						throw $error;
					}

					return $this->run_local_wordpress_smoke( $zip_path, $options, $error->getMessage() );
				}

			case 'playground':
				return $this->run_playground_smoke( $zip_path, $options );

			case 'local':
				return $this->run_local_wordpress_smoke( $zip_path, $options );
		}

		throw new RuntimeException( 'Unsupported smoke runtime "' . $options['runtime'] . '". Use auto, playground, or local.' );
	}

	/**
	 * Runs the Playground activation smoke check.
	 *
	 * @param string $zip_path Release zip path.
	 * @param array  $options  Smoke options.
	 * @return array Smoke summary.
	 */
	private function run_playground_smoke( $zip_path, array $options ) {
		$this->assert_supported_node_runtime();
		$this->assert_npx_available();

		$bundle = $this->create_blueprint_bundle(
			$zip_path,
			array(
				'wp_version'  => $options['wp_version'],
				'php_version' => $options['php_version'],
			)
		);

		try {
			$command = $this->build_playground_command(
				$options['playground_cli'],
				$bundle['blueprint_path'],
				$options['wp_version'],
				$options['php_version']
			);
			$result  = $this->run_command( $command, $this->repo_root, 'run WordPress Playground release smoke check', true );

			return array(
				'runtime'        => 'playground',
				'zip_path'       => $zip_path,
				'blueprint_path' => $bundle['blueprint_path'],
				'bundle_path'    => $bundle['bundle_path'],
				'bundle_kept'    => (bool) $options['keep_bundle'],
				'command'        => $command,
				'stdout'         => $result['stdout'],
				'stderr'         => $result['stderr'],
			);
		} finally {
			if ( empty( $options['keep_bundle'] ) ) {
				$this->remove_tree( $bundle['bundle_path'] );
			}
		}
	}

	/**
	 * Runs the fallback clean WordPress smoke check with WP-CLI and private MariaDB.
	 *
	 * @param string      $zip_path        Release zip path.
	 * @param array       $options         Smoke options.
	 * @param string|null $fallback_reason Playground failure that triggered this fallback.
	 * @return array Smoke summary.
	 */
	private function run_local_wordpress_smoke( $zip_path, array $options, $fallback_reason = null ) {
		$workdir = $this->create_temporary_directory( 'universal-importer-local-wp-' );
		$db      = null;
		$server  = null;

		try {
			$db      = $this->start_private_database( $workdir );
			$wp_cli  = $this->resolve_wp_cli_command( $workdir, $options['wp_cli_phar'] );
			$wp_path = $workdir . '/wordpress';
			$source  = $workdir . '/smoke-source';

			$this->run_command(
				$this->build_wp_cli_command(
					$wp_cli,
					$wp_path,
					array( 'core', 'download', '--version=' . $options['wp_version'], '--skip-content', '--quiet' )
				),
				$workdir,
				'download WordPress core for local release smoke'
			);

			$this->run_command(
				$this->build_wp_cli_command(
					$wp_cli,
					$wp_path,
					array(
						'config',
						'create',
						'--dbname=' . $db['database'],
						'--dbuser=root',
						'--dbpass=',
						'--dbhost=localhost:' . $db['socket'],
						'--skip-check',
						'--quiet',
					)
				),
				$workdir,
				'create WordPress configuration for local release smoke'
			);

			$this->run_command(
				$this->build_wp_cli_command(
					$wp_cli,
					$wp_path,
					array(
						'core',
						'install',
						'--url=http://universal-importer-smoke.local',
						'--title=Universal Importer Smoke',
						'--admin_user=admin',
						'--admin_password=password',
						'--admin_email=admin@example.test',
						'--skip-email',
						'--quiet',
					)
				),
				$workdir,
				'install WordPress for local release smoke'
			);

			$this->run_command(
				$this->build_wp_cli_command(
					$wp_cli,
					$wp_path,
					array( 'plugin', 'install', $zip_path, '--activate', '--force' )
				),
				$workdir,
				'install and activate the release zip in local WordPress'
			);

			$activation = $this->run_command(
				$this->build_wp_cli_command( $wp_cli, $wp_path, array( 'eval', $this->activation_assertion_wp_cli_code() ) ),
				$workdir,
				'verify release activation in local WordPress',
				true
			);

			if ( false === strpos( $activation['stdout'] . "\n" . $activation['stderr'], self::ACTIVATION_MARKER ) ) {
				throw new RuntimeException( 'The local WordPress smoke check finished activation verification, but the activation marker was not printed.' );
			}

			$this->create_local_import_smoke_fixture( $source );
			$this->install_local_pdf_external_text_smoke_filter( $wp_path, $source );
			$this->install_local_rest_mapping_http_fixture( $wp_path );
			$this->install_local_github_http_fixture( $wp_path, $workdir );

			$this->run_command(
				$this->build_wp_cli_command(
					$wp_cli,
					$wp_path,
					array( 'universal-importer', 'import', $source, '--confirm-first-party-domains=example.test' )
				),
				$workdir,
				'create an importer session through WP-CLI in local WordPress'
			);

			$import = $this->run_local_import_ticks_until_asserted( $wp_cli, $wp_path, $workdir );

			$this->create_remote_rest_smoke_source( $wp_cli, $wp_path, $workdir );
			$server = $this->start_local_wordpress_http_server( $wp_path, $workdir );

			$this->run_command(
				$this->build_wp_cli_command(
					$wp_cli,
					$wp_path,
					array(
						'option',
						'update',
						'universal_importer_rest_smoke_port',
						(string) $server['port'],
					)
				),
				$workdir,
				'allow the local REST smoke media port for WordPress safe HTTP downloads',
				true
			);

			$this->run_command(
				$this->build_wp_cli_command(
					$wp_cli,
					$wp_path,
					array( 'universal-importer', 'import', $server['url'] . '/wp-json/', '--confirm-first-party-domains=127.0.0.1' )
				),
				$workdir,
				'create a remote REST importer session through WP-CLI in local WordPress'
			);

			$rest_import = $this->run_local_rest_import_ticks_until_asserted( $wp_cli, $wp_path, $workdir );

			$this->run_command(
				$this->build_wp_cli_command(
					$wp_cli,
					$wp_path,
					array( 'universal-importer', 'import', 'https://' . self::REST_MAPPING_FIXTURE_HOST . '/wp-json/', '--confirm-first-party-domains=' . self::REST_MAPPING_FIXTURE_HOST )
				),
				$workdir,
				'create a REST relationship mapping importer session through WP-CLI in local WordPress'
			);

			$rest_mapping = $this->run_local_rest_mapping_ticks_until_asserted( $wp_cli, $wp_path, $workdir );

			$this->run_command(
				$this->build_wp_cli_command(
					$wp_cli,
					$wp_path,
					array( 'universal-importer', 'import', 'https://github.com/example/repository/tree/main/docs' )
				),
				$workdir,
				'create a GitHub repository importer session through WP-CLI in local WordPress'
			);

			$github_import = $this->run_local_github_import_ticks_until_asserted( $wp_cli, $wp_path, $workdir );

			$this->run_command(
				$this->build_wp_cli_command( $wp_cli, $wp_path, array( 'eval', $this->browser_upload_setup_wp_cli_code() ) ),
				$workdir,
				'create a browser-upload importer session through the admin API in local WordPress',
				true
			);

			$browser_upload = $this->run_local_browser_upload_ticks_until_asserted( $wp_cli, $wp_path, $workdir );

			if ( false === strpos( $import['stdout'] . "\n" . $import['stderr'], self::IMPORT_MARKER ) ) {
				throw new RuntimeException( 'The local WordPress smoke check finished import verification, but the import marker was not printed.' );
			}

			if ( false === strpos( $rest_import['stdout'] . "\n" . $rest_import['stderr'], self::REST_SMOKE_MARKER ) ) {
				throw new RuntimeException( 'The local WordPress smoke check finished REST import verification, but the REST marker was not printed.' );
			}

			if ( false === strpos( $rest_mapping['stdout'] . "\n" . $rest_mapping['stderr'], self::REST_MAPPING_SMOKE_MARKER ) ) {
				throw new RuntimeException( 'The local WordPress smoke check finished REST relationship mapping verification, but the mapping marker was not printed.' );
			}

			if ( false === strpos( $github_import['stdout'] . "\n" . $github_import['stderr'], self::GITHUB_SMOKE_MARKER ) ) {
				throw new RuntimeException( 'The local WordPress smoke check finished GitHub import verification, but the GitHub marker was not printed.' );
			}

			if ( false === strpos( $browser_upload['stdout'] . "\n" . $browser_upload['stderr'], self::BROWSER_UPLOAD_SMOKE_MARKER ) ) {
				throw new RuntimeException( 'The local WordPress smoke check finished browser-upload verification, but the browser-upload marker was not printed.' );
			}

			return array(
				'runtime'         => 'local',
				'zip_path'        => $zip_path,
				'workdir_path'    => $workdir,
				'workdir_kept'    => (bool) $options['keep_workdir'],
				'wp_cli_command'  => $wp_cli,
				'fallback_reason' => $fallback_reason,
				'stdout'          => $activation['stdout'] . $import['stdout'] . $rest_import['stdout'] . $rest_mapping['stdout'] . $github_import['stdout'] . $browser_upload['stdout'],
				'stderr'          => $activation['stderr'] . $import['stderr'] . $rest_import['stderr'] . $rest_mapping['stderr'] . $github_import['stderr'] . $browser_upload['stderr'],
			);
		} finally {
			if ( null !== $server ) {
				$this->stop_local_wordpress_http_server( $server );
			}

			if ( null !== $db ) {
				$this->stop_private_database( $db );
			}

			if ( empty( $options['keep_workdir'] ) ) {
				$this->remove_tree( $workdir );
			}
		}
	}

	/**
	 * Builds a WP-CLI command for the local runtime.
	 *
	 * @param string[] $wp_cli  Base WP-CLI command.
	 * @param string   $wp_path WordPress path.
	 * @param string[] $args    WP-CLI arguments.
	 * @return string[] Command arguments.
	 */
	public function build_wp_cli_command( array $wp_cli, $wp_path, array $args ) {
		return array_merge( $wp_cli, array( '--path=' . $wp_path ), $args );
	}

	/**
	 * Determines whether auto runtime may fall back from Playground to local WordPress.
	 *
	 * @param string $message Failure message.
	 * @return bool Whether the failure is infrastructure-related.
	 */
	public static function is_playground_infrastructure_failure( $message ) {
		$message = strtolower( (string) $message );

		foreach ( array( 'fetch failed', 'econnreset', 'econnrefused', 'enotfound', 'etimedout', 'eai_again', 'requires node.js', 'verify npx', 'inspect node.js version' ) as $needle ) {
			if ( false !== strpos( $message, $needle ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Creates a self-contained Playground blueprint bundle for the release zip.
	 *
	 * @param string $zip_path Release zip path.
	 * @param array  $options  Blueprint options.
	 * @return array Bundle paths.
	 */
	public function create_blueprint_bundle( $zip_path, array $options = array() ) {
		$options = array_merge(
			array(
				'wp_version'  => 'latest',
				'php_version' => '8.3',
			),
			$options
		);

		$real_zip = realpath( $zip_path );

		if ( false === $real_zip || ! is_file( $real_zip ) ) {
			throw new RuntimeException( 'Release zip does not exist: ' . $zip_path );
		}

		$bundle = $this->create_temporary_directory();
		$zip    = $bundle . '/' . self::SMOKE_PLUGIN_ZIP;

		if ( ! copy( $real_zip, $zip ) ) {
			$this->remove_tree( $bundle );
			throw new RuntimeException( 'Unable to copy release zip into smoke bundle.' );
		}

		$blueprint      = $this->build_blueprint( $options['wp_version'], $options['php_version'] );
		$blueprint_path = $bundle . '/blueprint.json';

		if ( false === file_put_contents( $blueprint_path, json_encode( $blueprint, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n" ) ) {
			$this->remove_tree( $bundle );
			throw new RuntimeException( 'Unable to write Playground smoke blueprint.' );
		}

		return array(
			'bundle_path'    => $bundle,
			'blueprint_path' => $blueprint_path,
			'zip_path'       => $zip,
		);
	}

	/**
	 * Builds the Playground CLI command.
	 *
	 * @param string $playground_cli Playground npm package spec.
	 * @param string $blueprint_path Blueprint path.
	 * @param string $wp_version     WordPress version.
	 * @param string $php_version    PHP version.
	 * @return string[] Command arguments.
	 */
	public function build_playground_command( $playground_cli, $blueprint_path, $wp_version, $php_version ) {
		return array(
			'npx',
			'--yes',
			(string) $playground_cli,
			'run-blueprint',
			'--blueprint=' . $blueprint_path,
			'--blueprint-may-read-adjacent-files',
			'--wp=' . $wp_version,
			'--php=' . $php_version,
			'--verbosity=normal',
		);
	}

	/**
	 * Checks whether a Node version can run Playground CLI.
	 *
	 * @param string $version Version string such as v22.20.0.
	 * @return bool Whether the version is supported.
	 */
	public static function is_supported_node_version( $version ) {
		if ( ! preg_match( '/v?(\d+)\.(\d+)\.(\d+)/', (string) $version, $matches ) ) {
			return false;
		}

		$major = (int) $matches[1];
		$minor = (int) $matches[2];

		if ( 20 < $major ) {
			return true;
		}

		return 20 === $major && 18 <= $minor;
	}

	/**
	 * Resolves or builds the release zip.
	 *
	 * @param array $options Smoke options.
	 * @return string Release zip path.
	 */
	private function resolve_release_zip( array $options ) {
		if ( ! empty( $options['zip_path'] ) ) {
			$zip_path = $this->absolute_path( $options['zip_path'] );

			if ( ! is_file( $zip_path ) ) {
				throw new RuntimeException( 'Release zip does not exist: ' . $zip_path );
			}

			$this->verify_release_zip( $zip_path );

			return $zip_path;
		}

		if ( empty( $options['build_release'] ) ) {
			throw new RuntimeException( 'Pass --zip=<path> or --build so the smoke check has a release zip to install.' );
		}

		require_once __DIR__ . '/ReleasePackageBuilder.php';

		$builder = new ReleasePackageBuilder( $this->repo_root );
		$summary = $builder->build(
			$options['output_dir'],
			array(
				'allow_dirty'         => (bool) $options['allow_dirty'],
				'run_checks'          => (bool) $options['run_build_checks'],
				'use_existing_vendor' => (bool) $options['use_existing_vendor'],
			)
		);

		$this->verify_release_zip( $summary['zip_path'] );

		return $summary['zip_path'];
	}

	/**
	 * Verifies release zip package integrity before smoke runtime setup.
	 *
	 * @param string $zip_path Release zip path.
	 */
	private function verify_release_zip( $zip_path ) {
		$this->run_command(
			array( PHP_BINARY, __DIR__ . '/verify-release-zip.php', '--zip=' . $zip_path ),
			$this->repo_root,
			'verify release zip package integrity',
			true
		);
	}

	/**
	 * Builds the smoke blueprint payload.
	 *
	 * @param string $wp_version  WordPress version.
	 * @param string $php_version PHP version.
	 * @return array Blueprint.
	 */
	private function build_blueprint( $wp_version, $php_version ) {
		$steps = array(
			array(
				'step'       => 'installPlugin',
				'pluginData' => array(
					'resource' => 'bundled',
					'path'     => self::SMOKE_PLUGIN_ZIP,
				),
				'options'    => array(
					'activate'         => true,
					'targetFolderName' => ReleasePackageBuilder::PLUGIN_SLUG,
				),
			),
			array(
				'step' => 'runPHP',
				'code' => $this->activation_assertion_php(),
			),
			array(
				'step' => 'mkdir',
				'path' => '/tmp/universal-importer-smoke',
			),
			array(
				'step' => 'runPHP',
				'code' => $this->import_fixture_setup_php(),
			),
			array(
				'step'    => 'wp-cli',
				'command' => 'wp universal-importer import /tmp/universal-importer-smoke --confirm-first-party-domains=example.test',
			),
		);

		for ( $i = 0; $i < self::IMPORT_SMOKE_MAX_TICKS; ++$i ) {
			$steps[] = array(
				'step'    => 'wp-cli',
				'command' => 'wp universal-importer tick --max-ticks=1',
			);
		}

		$steps[] = array(
			'step' => 'runPHP',
			'code' => $this->import_assertion_php(),
		);

		$steps[] = array(
			'step' => 'runPHP',
			'code' => $this->playground_rest_fixture_setup_php(),
		);

		$steps[] = array(
			'step'    => 'wp-cli',
			'command' => 'wp universal-importer import https://playground-rest-smoke.example/wp-json/ --confirm-first-party-domains=playground-rest-smoke.example',
		);

		for ( $i = 0; $i < self::IMPORT_SMOKE_MAX_TICKS; ++$i ) {
			$steps[] = array(
				'step'    => 'wp-cli',
				'command' => 'wp universal-importer tick --max-ticks=1',
			);
		}

		$steps[] = array(
			'step' => 'runPHP',
			'code' => $this->rest_import_assertion_php(),
		);

		$steps[] = array(
			'step' => 'runPHP',
			'code' => $this->rest_relationship_mapping_resolution_php(),
		);

		$steps[] = array(
			'step'    => 'wp-cli',
			'command' => 'wp universal-importer tick',
		);

		$steps[] = array(
			'step' => 'runPHP',
			'code' => $this->rest_relationship_mapping_assertion_php(),
		);

		$steps[] = array(
			'step' => 'runPHP',
			'code' => $this->playground_github_fixture_setup_php(),
		);

		$steps[] = array(
			'step'    => 'wp-cli',
			'command' => 'wp universal-importer import https://github.com/example/repository/tree/main/docs',
		);

		for ( $i = 0; $i < self::IMPORT_SMOKE_MAX_TICKS; ++$i ) {
			$steps[] = array(
				'step'    => 'wp-cli',
				'command' => 'wp universal-importer tick --max-ticks=1',
			);
		}

		$steps[] = array(
			'step' => 'runPHP',
			'code' => $this->github_import_assertion_php(),
		);

		$steps[] = array(
			'step' => 'runPHP',
			'code' => $this->browser_upload_setup_php(),
		);

		for ( $i = 0; $i < self::IMPORT_SMOKE_MAX_TICKS; ++$i ) {
			$steps[] = array(
				'step'    => 'wp-cli',
				'command' => 'wp universal-importer tick --max-ticks=1',
			);
		}

		$steps[] = array(
			'step' => 'runPHP',
			'code' => $this->browser_upload_assertion_php(),
		);

		return array(
			'$schema'           => 'https://playground.wordpress.net/blueprint-schema.json',
			'preferredVersions' => array(
				'php' => (string) $php_version,
				'wp'  => (string) $wp_version,
			),
			'extraLibraries'    => array( 'wp-cli' ),
			'steps'             => $steps,
		);
	}

	/**
	 * Returns PHP code that verifies activation side effects.
	 *
	 * @return string PHP code.
	 */
	private function activation_assertion_php() {
		return <<<'PHP'
<?php
require_once '/wordpress/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';

$plugin = 'universal-wordpress-importer/universal-wordpress-importer.php';
if ( ! is_plugin_active( $plugin ) ) {
	throw new Exception( 'Universal WordPress Importer is not active after release zip installation.' );
}

if ( ! defined( 'UNIVERSAL_IMPORTER_VERSION' ) || ! class_exists( 'UniversalImporter\\Plugin' ) ) {
	throw new Exception( 'Universal WordPress Importer bootstrap constants or classes are unavailable after activation.' );
}

global $wpdb;
$tables = UniversalImporter\Import\WordPressImportSessionSchema::from_globals()->get_table_names();
$old_suppress = $wpdb->suppress_errors( true );
foreach ( $tables as $table ) {
	$wpdb->last_error = '';
	$wpdb->get_var( 'SELECT COUNT(*) FROM `' . str_replace( '`', '``', $table ) . '` LIMIT 1' );
	if ( '' !== $wpdb->last_error ) {
		throw new Exception( 'Importer table is missing or unreadable after activation: ' . $table . ' - ' . $wpdb->last_error );
	}
}
$wpdb->suppress_errors( $old_suppress );

echo 'UNIVERSAL_IMPORTER_ACTIVATION_SMOKE_OK' . "\n";
PHP;
	}

	/**
	 * Returns PHP code that verifies the WP-CLI smoke import persisted state.
	 *
	 * @return string PHP code.
	 */
	private function import_assertion_php() {
		return <<<'PHP'
<?php
require_once '/wordpress/wp-load.php';

global $wpdb;
$tables = UniversalImporter\Import\WordPressImportSessionSchema::from_globals()->get_table_names();
$sessions = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM `' . str_replace( '`', '``', $tables['sessions'] ) . '`' );
$events = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM `' . str_replace( '`', '``', $tables['events'] ) . '`' );

if ( 1 > $sessions ) {
	throw new Exception( 'WP-CLI smoke import did not create a durable import session.' );
}

if ( 1 > $events ) {
	throw new Exception( 'WP-CLI smoke import did not record progress events.' );
}

if ( ! function_exists( 'get_posts' ) || ! function_exists( 'get_post_meta' ) ) {
	throw new Exception( 'WordPress post APIs are unavailable during import smoke verification.' );
}

$attachments = get_posts(
	array(
		'post_type'              => 'attachment',
		'post_status'            => 'any',
		'posts_per_page'         => 10,
		'orderby'                => 'ID',
		'order'                  => 'DESC',
		'no_found_rows'          => true,
		'update_post_meta_cache' => true,
		'update_post_term_cache' => false,
		'meta_query'             => array(
			array(
				'key'   => '_universal_importer_original_url',
				'value' => 'assets/smoke.png',
			),
		),
	)
);

$attachment_id  = 0;
$attachment_url = '';
foreach ( $attachments as $attachment ) {
	$url = function_exists( 'wp_get_attachment_url' ) ? wp_get_attachment_url( $attachment->ID ) : '';
	if (
		is_string( $url )
		&& '' !== $url
		&& false !== strpos( $url, '/wp-content/uploads/' )
		&& '' !== (string) get_post_meta( $attachment->ID, '_universal_importer_session_id', true )
		&& '' !== (string) get_post_meta( $attachment->ID, '_universal_importer_media_reference_key', true )
	) {
		$attachment_id  = (int) $attachment->ID;
		$attachment_url = $url;
		break;
	}
}

if ( $attachment_id < 1 ) {
	throw new Exception( 'WP-CLI smoke import did not import the local Markdown image attachment.' );
}

$pdf_attachments = get_posts(
	array(
		'post_type'              => 'attachment',
		'post_status'            => 'any',
		'posts_per_page'         => 10,
		'orderby'                => 'ID',
		'order'                  => 'DESC',
		'no_found_rows'          => true,
		'update_post_meta_cache' => true,
		'update_post_term_cache' => false,
		'meta_query'             => array(
			array(
				'key'     => '_universal_importer_original_url',
				'value'   => 'uwi-pdf-asset://',
				'compare' => 'LIKE',
			),
		),
	)
);

$pdf_attachment_id  = 0;
$pdf_attachment_url = '';
foreach ( $pdf_attachments as $attachment ) {
	$url = function_exists( 'wp_get_attachment_url' ) ? wp_get_attachment_url( $attachment->ID ) : '';
	if (
		is_string( $url )
		&& '' !== $url
		&& false !== strpos( $url, '/wp-content/uploads/' )
		&& '' !== (string) get_post_meta( $attachment->ID, '_universal_importer_session_id', true )
		&& '' !== (string) get_post_meta( $attachment->ID, '_universal_importer_media_reference_key', true )
	) {
		$pdf_attachment_id  = (int) $attachment->ID;
		$pdf_attachment_url = $url;
		break;
	}
}

if ( $pdf_attachment_id < 1 ) {
	throw new Exception( 'WP-CLI smoke import did not import the embedded PDF image attachment.' );
}

$markdown_posts = get_posts(
	array(
		'post_type'              => 'page',
		'post_status'            => 'any',
		'posts_per_page'         => 10,
		'orderby'                => 'ID',
		'order'                  => 'DESC',
		'no_found_rows'          => true,
		'update_post_meta_cache' => true,
		'update_post_term_cache' => false,
		'meta_query'             => array(
			array(
				'key'   => '_universal_importer_document_format',
				'value' => 'markdown',
			),
		),
	)
);

$titles = array();
$found_markdown = false;
foreach ( $markdown_posts as $post ) {
	$titles[] = $post->post_title;

	if (
		'Packaged Import Smoke' === $post->post_title
		&& false !== strpos( $post->post_content, '<!-- wp:heading {"level":1} -->' )
		&& false !== strpos( $post->post_content, '<h1>Packaged Import Smoke</h1>' )
		&& false !== strpos( $post->post_content, '<!-- wp:paragraph -->' )
		&& false !== strpos( $post->post_content, 'A packaged plugin import smoke document.' )
		&& false !== strpos( $post->post_content, $attachment_url )
		&& false === strpos( $post->post_content, 'assets/smoke.png' )
		&& '' !== (string) get_post_meta( $post->ID, '_universal_importer_session_id', true )
		&& '' !== (string) get_post_meta( $post->ID, '_universal_importer_source_item_key', true )
	) {
		$found_markdown = true;
		break;
	}
}

if ( ! $found_markdown ) {
	throw new Exception( 'WP-CLI smoke import did not persist the expected imported Markdown page with rewritten local media. Imported titles found: ' . ( empty( $titles ) ? 'none' : implode( ', ', $titles ) ) . '.' );
}

$html_posts = get_posts(
	array(
		'post_type'              => 'page',
		'post_status'            => 'any',
		'posts_per_page'         => 10,
		'orderby'                => 'ID',
		'order'                  => 'DESC',
		'no_found_rows'          => true,
		'update_post_meta_cache' => true,
		'update_post_term_cache' => false,
		'meta_query'             => array(
			array(
				'key'   => '_universal_importer_document_format',
				'value' => 'html',
			),
		),
	)
);

$html_titles = array();
$html_post_id = 0;
foreach ( $html_posts as $post ) {
	$html_titles[] = $post->post_title;

	if (
		'Packaged HTML Smoke' === $post->post_title
		&& false !== strpos( $post->post_content, '<!-- wp:heading {"level":1} -->' )
		&& false !== strpos( $post->post_content, '<!-- wp:paragraph -->' )
		&& false !== strpos( $post->post_content, '<!-- wp:list -->' )
		&& false !== strpos( $post->post_content, '<!-- wp:list {"ordered":true,"reversed":true,"start":4,"type":"A","anchor":"html-smoke-ordered-list"} -->' )
		&& false !== strpos( $post->post_content, '<!-- wp:list-item {"anchor":"html-smoke-step-four"} -->' )
		&& false !== strpos( $post->post_content, '<ol id="html-smoke-ordered-list" reversed="reversed" start="4" type="A"><!-- wp:list-item {"anchor":"html-smoke-step-four"} -->' )
		&& false !== strpos( $post->post_content, '<li id="html-smoke-step-four" value="4">HTML smoke fourth step</li>' )
		&& false !== strpos( $post->post_content, "<!-- wp:list-item -->\n<li>HTML smoke third step</li>\n<!-- /wp:list-item --></ol>" )
		&& false !== strpos( $post->post_content, '<!-- wp:image {"anchor":"html-smoke-figure-image"} -->' )
		&& false !== strpos( $post->post_content, '<figure class="wp-block-image" id="html-smoke-figure-image">' )
		&& false !== strpos( $post->post_content, '<!-- wp:table {"align":"wide","hasFixedLayout":true,"className":"is-style-stripes","anchor":"html-smoke-table"} -->' )
		&& false !== strpos( $post->post_content, '<figure class="wp-block-table alignwide is-style-stripes" id="html-smoke-table"><table class="has-fixed-layout">' )
		&& false !== strpos( $post->post_content, '<!-- wp:separator {"align":"wide","className":"is-style-dots","anchor":"html-smoke-separator"} -->' )
		&& false !== strpos( $post->post_content, '<hr class="wp-block-separator has-alpha-channel-opacity alignwide is-style-dots" id="html-smoke-separator"/>' )
		&& false !== strpos( $post->post_content, '<!-- wp:code {"anchor":"html-smoke-code"} -->' )
		&& false !== strpos( $post->post_content, '<pre class="wp-block-code" id="html-smoke-code"><code>&lt;?php echo "HTML smoke";</code></pre>' )
		&& false !== strpos( $post->post_content, '<!-- wp:preformatted {"anchor":"html-smoke-preformatted"} -->' )
		&& false !== strpos( $post->post_content, '<pre class="wp-block-preformatted" id="html-smoke-preformatted">HTML smoke preformatted line.</pre>' )
		&& false !== strpos( $post->post_content, "<!-- wp:shortcode {\"anchor\":\"html-smoke-gallery-shortcode\"} -->\n[gallery ids=\"10,11\"]\n<!-- /wp:shortcode -->" )
		&& false !== strpos( $post->post_content, "<!-- wp:more {\"customText\":\"Keep reading smoke\",\"noTeaser\":true} -->\n<!--more Keep reading smoke-->\n<!--noteaser-->\n<!-- /wp:more -->" )
		&& false !== strpos( $post->post_content, "<!-- wp:nextpage -->\n<!--nextpage-->\n<!-- /wp:nextpage -->" )
		&& false !== strpos( $post->post_content, '<!-- wp:verse {"anchor":"html-smoke-verse"} -->' )
		&& false !== strpos( $post->post_content, '<pre class="wp-block-verse" id="html-smoke-verse">' )
		&& false !== strpos( $post->post_content, '<!-- wp:gallery {"columns":2,"linkTo":"none","align":"wide","anchor":"html-smoke-gallery"} -->' )
		&& false !== strpos( $post->post_content, '<figure class="wp-block-gallery has-nested-images columns-2 is-cropped alignwide" id="html-smoke-gallery">' )
		&& false !== strpos( $post->post_content, '<!-- wp:image {"href":"/html-gallery-large","linkDestination":"custom","sizeSlug":"thumbnail"} -->' )
		&& false !== strpos( $post->post_content, '<a href="/html-gallery-large"><img src="' )
		&& false !== strpos( $post->post_content, 'alt="Gallery one"></a><figcaption class="wp-element-caption">Gallery image one.</figcaption>' )
		&& false !== strpos( $post->post_content, '<!-- wp:image {"sizeSlug":"thumbnail","linkDestination":"none"} -->' )
		&& false !== strpos( $post->post_content, '<!-- wp:video {"src":"media/html-smoke.mp4","controls":true,"poster":"assets/html-smoke.png","anchor":"html-smoke-video"} -->' )
		&& false !== strpos( $post->post_content, '<figure class="wp-block-video" id="html-smoke-video">' )
		&& false !== strpos( $post->post_content, '<!-- wp:audio {"src":"media/html-smoke.mp3","controls":true,"anchor":"html-smoke-audio"} -->' )
		&& false !== strpos( $post->post_content, '<figure class="wp-block-audio" id="html-smoke-audio">' )
		&& false !== strpos( $post->post_content, '<!-- wp:video {"src":"media/html-shortcode.mp4","controls":true,"loop":true,"poster":"assets/html-smoke.png","preload":"metadata","align":"center","anchor":"html-smoke-video-shortcode"} -->' )
		&& false !== strpos( $post->post_content, '<figure class="wp-block-video aligncenter" id="html-smoke-video-shortcode"><video controls="controls" src="media/html-shortcode.mp4" loop="loop" poster="' )
		&& false !== strpos( $post->post_content, 'preload="metadata"></video></figure>' )
		&& false !== strpos( $post->post_content, '<!-- wp:audio {"src":"media/html-shortcode.mp3","controls":true,"preload":"none","align":"wide","anchor":"html-smoke-audio-shortcode"} -->' )
		&& false !== strpos( $post->post_content, '<figure class="wp-block-audio alignwide" id="html-smoke-audio-shortcode"><audio controls="controls" src="media/html-shortcode.mp3" preload="none"></audio></figure>' )
		&& false !== strpos( $post->post_content, '<!-- wp:video {"src":"media/html-wrapper.mp4","controls":true,"preload":"metadata","align":"center","anchor":"html-smoke-wrapper-video"} -->' )
		&& false !== strpos( $post->post_content, '<figure class="wp-block-video aligncenter" id="html-smoke-wrapper-video"><video class="wp-video-shortcode" preload="metadata" controls="controls">' )
		&& false !== strpos( $post->post_content, '<source type="video/mp4" src="media/html-wrapper.mp4">' )
		&& false !== strpos( $post->post_content, '<!-- wp:audio {"src":"media/html-wrapper.mp3","controls":true,"preload":"none","align":"wide","anchor":"html-smoke-wrapper-audio"} -->' )
		&& false !== strpos( $post->post_content, '<figure class="wp-block-audio alignwide" id="html-smoke-wrapper-audio"><audio class="wp-audio-shortcode" preload="none" controls="controls">' )
		&& false !== strpos( $post->post_content, '<figcaption class="wp-element-caption">HTML wrapper audio caption.</figcaption>' )
		&& false !== strpos( $post->post_content, '<!-- wp:embed {"url":"https://www.youtube.com/watch?v=dQw4w9WgXcQ","type":"video","providerNameSlug":"youtube","responsive":true} -->' )
		&& false !== strpos( $post->post_content, '<!-- wp:embed {"url":"https://vimeo.com/12345","type":"video","providerNameSlug":"vimeo","responsive":true,"align":"wide","anchor":"html-smoke-vimeo-embed"} -->' )
		&& false !== strpos( $post->post_content, '<figure class="wp-block-embed is-type-video is-provider-vimeo wp-block-embed-vimeo alignwide" id="html-smoke-vimeo-embed"><div class="wp-block-embed__wrapper">https://vimeo.com/12345</div>' )
		&& false !== strpos( $post->post_content, '<!-- wp:media-text ' )
		&& false !== strpos( $post->post_content, '"anchor":"html-smoke-media-text"' )
		&& false !== strpos( $post->post_content, '"align":"full"' )
		&& false !== strpos( $post->post_content, '<div class="wp-block-media-text is-stacked-on-mobile alignfull" id="html-smoke-media-text">' )
		&& false !== strpos( $post->post_content, '<figure class="wp-block-media-text__media">' )
		&& false !== strpos( $post->post_content, '<!-- wp:social-links {"anchor":"html-smoke-social","align":"center","showLabels":true,"openInNewTab":true} -->' )
		&& false !== strpos( $post->post_content, '<ul class="wp-block-social-links aligncenter has-visible-labels" id="html-smoke-social">' )
		&& false !== strpos( $post->post_content, '<!-- wp:social-link {"url":"https://github.com/wordpress","service":"github","label":"GitHub","rel":"me noopener"} /-->' )
		&& false !== strpos( $post->post_content, '<!-- wp:social-link {"url":"https://www.youtube.com/wordpress","service":"youtube","label":"YouTube"} /-->' )
		&& false !== strpos( $post->post_content, '<!-- wp:social-link {"url":"mailto:hello@example.test","service":"mail","label":"Email"} /-->' )
		&& false !== strpos( $post->post_content, '<!-- wp:navigation {"overlayMenu":"never","anchor":"html-smoke-nav","align":"wide","ariaLabel":"HTML smoke navigation"} -->' )
		&& false !== strpos( $post->post_content, '<!-- wp:navigation-link {"label":"HTML smoke home","url":"/html-nav-home","kind":"custom","isTopLevelLink":true} /-->' )
		&& false !== strpos( $post->post_content, '<!-- wp:navigation-submenu {"label":"HTML smoke services","url":"/html-nav-services","kind":"custom","isTopLevelItem":true} -->' )
		&& false !== strpos( $post->post_content, '<!-- wp:navigation-link {"label":"HTML smoke imports","url":"/html-nav-imports","kind":"custom"} /-->' )
		&& false !== strpos( $post->post_content, '<!-- /wp:navigation-submenu -->' )
		&& false !== strpos( $post->post_content, '<!-- wp:search {"label":"HTML smoke search","buttonText":"Search","placeholder":"Search smoke content","showLabel":false,"buttonUseIcon":true,"anchor":"html-smoke-search","align":"right"} /-->' )
		&& false !== strpos( $post->post_content, '<!-- wp:spacer {"height":"36px","anchor":"html-smoke-spacer"} -->' )
		&& false !== strpos( $post->post_content, '<div style="height:36px" aria-hidden="true" class="wp-block-spacer" id="html-smoke-spacer"></div>' )
		&& false !== strpos( $post->post_content, '<!-- wp:details {"showContent":true,"anchor":"html-faq","align":"full"} -->' )
		&& false !== strpos( $post->post_content, '<!-- wp:cover ' )
		&& false !== strpos( $post->post_content, '"className":"universal-importer-hero"' )
		&& false !== strpos( $post->post_content, '"align":"wide"' )
		&& false !== strpos( $post->post_content, '<div class="wp-block-cover universal-importer-hero alignwide" id="html-smoke-hero">' )
		&& false !== strpos( $post->post_content, 'wp-block-cover__image-background' )
		&& false !== strpos( $post->post_content, '<!-- wp:columns {"align":"wide","anchor":"html-smoke-columns"} -->' )
		&& false !== strpos( $post->post_content, '<div class="wp-block-columns alignwide" id="html-smoke-columns">' )
		&& false !== strpos( $post->post_content, '<!-- wp:column {"width":"50%","anchor":"html-smoke-left-column"} -->' )
		&& false !== strpos( $post->post_content, '<div class="wp-block-column" style="flex-basis:50%" id="html-smoke-left-column">' )
		&& false !== strpos( $post->post_content, '<!-- wp:column {"width":"50%","anchor":"html-smoke-right-column"} -->' )
		&& false !== strpos( $post->post_content, '<div class="wp-block-column" style="flex-basis:50%" id="html-smoke-right-column">' )
		&& false !== strpos( $post->post_content, '<!-- wp:group {"className":"universal-importer-timeline","anchor":"html-smoke-timeline","align":"wide"} -->' )
		&& false !== strpos( $post->post_content, '<!-- wp:group {"className":"universal-importer-timeline-item","anchor":"html-smoke-timeline-one"} -->' )
		&& false !== strpos( $post->post_content, '<!-- wp:paragraph {"className":"universal-importer-timeline-marker"} -->' )
		&& false !== strpos( $post->post_content, '<!-- wp:group {"className":"universal-importer-steps","align":"full"} -->' )
		&& false !== strpos( $post->post_content, '<!-- wp:group {"className":"universal-importer-step-item","anchor":"html-smoke-step-review"} -->' )
		&& false !== strpos( $post->post_content, '<!-- wp:group {"className":"universal-importer-callout universal-importer-callout-warning","anchor":"html-smoke-callout","align":"wide"} -->' )
		&& false !== strpos( $post->post_content, '<!-- wp:group {"className":"universal-importer-card","align":"full"} -->' )
		&& false !== strpos( $post->post_content, '<!-- wp:group {"className":"universal-importer-card","anchor":"html-smoke-starter-plan","align":"wide"} -->' )
		&& false !== strpos( $post->post_content, '<!-- wp:pullquote {"textAlign":"center","anchor":"html-smoke-pullquote"} -->' )
		&& false !== strpos( $post->post_content, '<!-- wp:quote {"textAlign":"right","anchor":"html-smoke-figure-quote"} -->' )
		&& false !== strpos( $post->post_content, '<blockquote class="wp-block-quote has-text-align-right" id="html-smoke-figure-quote"><p>HTML smoke figure quote.</p><cite>HTML smoke quoted source</cite></blockquote>' )
		&& false !== strpos( $post->post_content, '<!-- wp:html -->' )
		&& false !== strpos( $post->post_content, '<!-- wp:buttons {"align":"wide"} -->' )
		&& false !== strpos( $post->post_content, '<!-- wp:button {"url":"/html-smoke-cta","anchor":"html-smoke-button-row"} -->' )
		&& false !== strpos( $post->post_content, '<div class="wp-block-button" id="html-smoke-button-row"><a class="wp-block-button__link wp-element-button" href="/html-smoke-cta">HTML button CTA</a></div>' )
		&& false !== strpos( $post->post_content, '<!-- wp:file ' )
		&& false !== strpos( $post->post_content, '"align":"center","anchor":"html-smoke-file-row","textLinkTarget":"_blank"' )
		&& false !== strpos( $post->post_content, '<div class="wp-block-file aligncenter" id="html-smoke-file-row"><a href="' )
		&& false !== strpos( $post->post_content, 'target="_blank">HTML file download</a>' )
		&& false !== strpos( $post->post_content, '<figcaption class="wp-element-caption">HTML figure caption.</figcaption>' )
		&& false !== strpos( $post->post_content, '<!-- wp:image {"href":"/html-linked-image","linkDestination":"custom","linkTarget":"_blank","rel":"noopener"} -->' )
		&& false !== strpos( $post->post_content, '<figcaption class="wp-element-caption">HTML linked image caption.</figcaption>' )
		&& false !== strpos( $post->post_content, '<!-- wp:image {"align":"center","sizeSlug":"large","anchor":"html-smoke-caption"} -->' )
		&& false !== strpos( $post->post_content, '<figure class="wp-block-image aligncenter size-large" id="html-smoke-caption">' )
		&& false !== strpos( $post->post_content, '<figcaption class="wp-element-caption">HTML smoke classic caption.</figcaption>' )
		&& false !== strpos( $post->post_content, '<!-- wp:image {"align":"wide","sizeSlug":"large","anchor":"html-smoke-picture"} -->' )
		&& false !== strpos( $post->post_content, '<figure class="wp-block-image alignwide size-large" id="html-smoke-picture"><picture class="alignwide">' )
		&& false !== strpos( $post->post_content, '<!-- wp:image {"align":"center","sizeSlug":"medium","href":"/html-smoke-picture-full","linkDestination":"custom","anchor":"html-smoke-linked-picture"} -->' )
		&& false !== strpos( $post->post_content, '<figure class="wp-block-image aligncenter size-medium" id="html-smoke-linked-picture"><a href="/html-smoke-picture-full"><picture class="aligncenter">' )
		&& false !== strpos( $post->post_content, '<figcaption class="wp-element-caption">HTML table caption.</figcaption>' )
		&& false !== strpos( $post->post_content, '<figcaption class="wp-element-caption">HTML video caption.</figcaption>' )
		&& false !== strpos( $post->post_content, '<figcaption class="wp-element-caption">HTML audio caption.</figcaption>' )
		&& false !== strpos( $post->post_content, 'HTML smoke media text heading' )
		&& false !== strpos( $post->post_content, 'HTML smoke media text copy.' )
		&& false !== strpos( $post->post_content, 'HTML media text CTA' )
		&& false !== strpos( $post->post_content, '<details class="wp-block-details alignfull" id="html-faq" name="html-smoke-faq" open><summary>HTML smoke FAQ</summary>' )
		&& false !== strpos( $post->post_content, '<!-- wp:details {"anchor":"html-definition-faq-one","align":"wide"} -->' )
		&& false !== strpos( $post->post_content, '<details class="wp-block-details alignwide" id="html-definition-faq-one"><summary>Can definition lists become Details?</summary>' )
		&& false !== strpos( $post->post_content, '<!-- wp:details {"showContent":true,"anchor":"html-legacy-accordion-one","align":"full"} -->' )
		&& false !== strpos( $post->post_content, '<details class="wp-block-details alignfull" id="html-legacy-accordion-one" name="html-legacy-accordion" open><summary>Legacy accordion one</summary>' )
		&& false !== strpos( $post->post_content, '<!-- wp:details {"anchor":"html-legacy-accordion-two","align":"wide"} -->' )
		&& false !== strpos( $post->post_content, '<details class="wp-block-details alignwide" id="html-legacy-accordion-two" name="html-legacy-accordion"><summary>Legacy accordion two</summary>' )
		&& false !== strpos( $post->post_content, 'Details content stays structured.' )
		&& false !== strpos( $post->post_content, 'HTML definition-list answer with <strong>inline HTML</strong>.' )
		&& false !== strpos( $post->post_content, 'HTML definition-list block answer.' )
		&& false !== strpos( $post->post_content, 'HTML definition-list answer item.' )
		&& false !== strpos( $post->post_content, 'HTML smoke hero heading' )
		&& false !== strpos( $post->post_content, 'HTML smoke hero copy.' )
		&& false !== strpos( $post->post_content, 'HTML hero CTA' )
		&& false !== strpos( $post->post_content, 'Legacy accordion first answer.' )
		&& false !== strpos( $post->post_content, 'Legacy accordion second answer.' )
		&& false !== strpos( $post->post_content, 'HTML left column copy.' )
		&& false !== strpos( $post->post_content, 'HTML right column item' )
		&& false !== strpos( $post->post_content, '<!-- wp:group {"className":"universal-importer-timeline","anchor":"html-smoke-timeline","align":"wide"} -->' )
		&& false !== strpos( $post->post_content, '<div class="wp-block-group universal-importer-timeline alignwide" id="html-smoke-timeline">' )
		&& false !== strpos( $post->post_content, '<p class="universal-importer-timeline-marker"><time datetime="2026-01">Q1 2026</time></p>' )
		&& false !== strpos( $post->post_content, 'HTML smoke timeline first item.' )
		&& false !== strpos( $post->post_content, '<!-- wp:group {"className":"universal-importer-steps","align":"full"} -->' )
		&& false !== strpos( $post->post_content, '<div class="wp-block-group universal-importer-steps alignfull">' )
		&& false !== strpos( $post->post_content, 'HTML smoke review step.' )
		&& false !== strpos( $post->post_content, '<figure class="wp-block-pullquote has-text-align-center" id="html-smoke-pullquote"><blockquote><p>HTML pullquote smoke.</p><cite>HTML Citation</cite></blockquote></figure>' )
		&& false !== strpos( $post->post_content, '<iframe src="/html-smoke-frame" title="HTML smoke frame"></iframe>' )
		&& false !== strpos( $post->post_content, '<form action="/html-smoke-form" method="post">' )
		&& false !== strpos( $post->post_content, '<input type="email" name="email">' )
		&& false !== strpos( $post->post_content, '<div class="tabs">' )
		&& false !== strpos( $post->post_content, 'HTML smoke tab one' )
		&& false !== strpos( $post->post_content, 'HTML smoke tab two' )
		&& false !== strpos( $post->post_content, '<!-- wp:group {"className":"universal-importer-callout universal-importer-callout-warning","anchor":"html-smoke-callout","align":"wide"} -->' )
		&& false !== strpos( $post->post_content, '<div class="wp-block-group universal-importer-callout universal-importer-callout-warning alignwide" id="html-smoke-callout">' )
		&& false !== strpos( $post->post_content, 'HTML smoke warning callout.' )
		&& false !== strpos( $post->post_content, '<!-- wp:group {"className":"universal-importer-card","align":"full"} -->' )
		&& false !== strpos( $post->post_content, '<div class="wp-block-group universal-importer-card alignfull">' )
		&& false !== strpos( $post->post_content, 'HTML smoke feature card.' )
		&& false !== strpos( $post->post_content, '<!-- wp:group {"className":"universal-importer-card","anchor":"html-smoke-starter-plan","align":"wide"} -->' )
		&& false !== strpos( $post->post_content, '<div class="wp-block-group universal-importer-card alignwide" id="html-smoke-starter-plan">' )
		&& false !== strpos( $post->post_content, 'HTML smoke starter plan.' )
		&& false !== strpos( $post->post_content, 'HTML smoke pro plan.' )
		&& false !== strpos( $post->post_content, 'HTML button CTA' )
		&& false !== strpos( $post->post_content, 'documents/document-smoke.pdf' )
		&& false !== strpos( $post->post_content, 'Inline HTML media intro' )
		&& false !== strpos( $post->post_content, 'Inline HTML media outro' )
		&& false !== strpos( $post->post_content, 'HTML inline action' )
		&& false !== strpos( $post->post_content, 'HTML smoke verse line two' )
		&& false !== strpos( $post->post_content, '&lt;?php echo "HTML smoke";' )
		&& false !== strpos( $post->post_content, 'A packaged plugin HTML import smoke document.' )
		&& false !== strpos( $post->post_content, '<!-- wp:heading {"level":2,"textAlign":"center","anchor":"html-smoke-centered-heading"} -->' )
		&& false !== strpos( $post->post_content, '<h2 class="has-text-align-center" id="html-smoke-centered-heading">HTML smoke centered heading</h2>' )
		&& false !== strpos( $post->post_content, '<!-- wp:paragraph {"align":"center","anchor":"html-smoke-centered-paragraph"} -->' )
		&& false !== strpos( $post->post_content, '<p class="has-text-align-center" id="html-smoke-centered-paragraph">HTML smoke centered paragraph.</p>' )
		&& false !== strpos( $post->post_content, '<object>Unsafe object fallback</object>' )
		&& false !== strpos( $post->post_content, '<img src="/html-smoke-safe.jpg" alt="Unsafe srcset image">' )
		&& false !== strpos( $post->post_content, '<video src="/html-smoke-safe.mp4"></video>' )
		&& false !== strpos( $post->post_content, '<span>Unsafe background attribute</span>' )
		&& false === strpos( $post->post_content, '<!-- wp:freeform -->' )
		&& false === strpos( $post->post_content, '<script' )
		&& false === strpos( $post->post_content, '<style' )
		&& false === strpos( $post->post_content, 'javascript:' )
		&& false === strpos( $post->post_content, 'data:image/svg+xml' )
		&& false === strpos( $post->post_content, 'onsubmit' )
		&& false === strpos( $post->post_content, 'onclick' )
		&& '' !== (string) get_post_meta( $post->ID, '_universal_importer_session_id', true )
		&& '' !== (string) get_post_meta( $post->ID, '_universal_importer_source_item_key', true )
	) {
		$html_post_id = (int) $post->ID;
		break;
	}
}

if ( $html_post_id < 1 ) {
	$html_debug = '';
	foreach ( $html_posts as $post ) {
		if ( 'Packaged HTML Smoke' !== $post->post_title ) {
			continue;
		}

		$content      = (string) $post->post_content;
		$missing_html = array();
		foreach (
			array(
				'heading block'               => '<!-- wp:heading {"level":1} -->',
				'paragraph block'             => '<!-- wp:paragraph -->',
				'unordered list block'        => '<!-- wp:list -->',
				'ordered list block attrs'    => '<!-- wp:list {"ordered":true,"reversed":true,"start":4,"type":"A","anchor":"html-smoke-ordered-list"} -->',
				'ordered list wrapper'        => '<ol id="html-smoke-ordered-list" reversed="reversed" start="4" type="A"><!-- wp:list-item {"anchor":"html-smoke-step-four"} -->',
				'ordered list item value'     => '<li id="html-smoke-step-four" value="4">HTML smoke fourth step</li>',
				'figure image block'          => '<figure class="wp-block-image" id="html-smoke-figure-image">',
				'table block attrs'           => '<!-- wp:table {"align":"wide","hasFixedLayout":true,"className":"is-style-stripes","anchor":"html-smoke-table"} -->',
				'separator block'             => '<hr class="wp-block-separator has-alpha-channel-opacity alignwide is-style-dots" id="html-smoke-separator"/>',
				'code block'                  => '<pre class="wp-block-code" id="html-smoke-code"><code>&lt;?php echo "HTML smoke";</code></pre>',
				'preformatted block'          => '<pre class="wp-block-preformatted" id="html-smoke-preformatted">HTML smoke preformatted line.</pre>',
				'gallery shortcode block'     => "<!-- wp:shortcode {\"anchor\":\"html-smoke-gallery-shortcode\"} -->\n[gallery ids=\"10,11\"]\n<!-- /wp:shortcode -->",
				'more block'                  => "<!-- wp:more {\"customText\":\"Keep reading smoke\",\"noTeaser\":true} -->\n<!--more Keep reading smoke-->\n<!--noteaser-->\n<!-- /wp:more -->",
				'nextpage block'              => "<!-- wp:nextpage -->\n<!--nextpage-->\n<!-- /wp:nextpage -->",
				'verse block'                 => '<pre class="wp-block-verse" id="html-smoke-verse">',
				'gallery block'               => '<figure class="wp-block-gallery has-nested-images columns-2 is-cropped alignwide" id="html-smoke-gallery">',
				'gallery image link'          => '<a href="/html-gallery-large"><img src="',
				'gallery image caption'       => 'alt="Gallery one"></a><figcaption class="wp-element-caption">Gallery image one.</figcaption>',
				'video figure block attrs'    => '<!-- wp:video {"src":"media/html-smoke.mp4","controls":true,"poster":"assets/html-smoke.png","anchor":"html-smoke-video"} -->',
				'audio figure block attrs'    => '<!-- wp:audio {"src":"media/html-smoke.mp3","controls":true,"anchor":"html-smoke-audio"} -->',
				'video shortcode block attrs' => '<!-- wp:video {"src":"media/html-shortcode.mp4","controls":true,"loop":true,"poster":"assets/html-smoke.png","preload":"metadata","align":"center","anchor":"html-smoke-video-shortcode"} -->',
				'video shortcode figure'      => '<figure class="wp-block-video aligncenter" id="html-smoke-video-shortcode"><video controls="controls" src="media/html-shortcode.mp4" loop="loop" poster="',
				'audio shortcode figure'      => '<figure class="wp-block-audio alignwide" id="html-smoke-audio-shortcode"><audio controls="controls" src="media/html-shortcode.mp3" preload="none"></audio></figure>',
				'wrapper video source'        => '<source type="video/mp4" src="media/html-wrapper.mp4">',
				'wrapper audio caption'       => '<figcaption class="wp-element-caption">HTML wrapper audio caption.</figcaption>',
				'youtube embed block'         => '<!-- wp:embed {"url":"https://www.youtube.com/watch?v=dQw4w9WgXcQ","type":"video","providerNameSlug":"youtube","responsive":true} -->',
				'vimeo embed block'           => '<!-- wp:embed {"url":"https://vimeo.com/12345","type":"video","providerNameSlug":"vimeo","responsive":true,"align":"wide","anchor":"html-smoke-vimeo-embed"} -->',
				'social links block'          => '<!-- wp:social-links {"anchor":"html-smoke-social","align":"center","showLabels":true,"openInNewTab":true} -->',
				'navigation block'            => '<!-- wp:navigation {"overlayMenu":"never","anchor":"html-smoke-nav","align":"wide","ariaLabel":"HTML smoke navigation"} -->',
				'search block'                => '<!-- wp:search {"label":"HTML smoke search","buttonText":"Search","placeholder":"Search smoke content","showLabel":false,"buttonUseIcon":true,"anchor":"html-smoke-search","align":"right"} /-->',
				'details block'               => '<details class="wp-block-details alignfull" id="html-faq" name="html-smoke-faq" open><summary>HTML smoke FAQ</summary>',
				'cover block'                 => '<div class="wp-block-cover universal-importer-hero alignwide" id="html-smoke-hero">',
				'columns block'               => '<div class="wp-block-columns alignwide" id="html-smoke-columns">',
				'timeline group block'        => '<div class="wp-block-group universal-importer-timeline alignwide" id="html-smoke-timeline">',
				'steps group block'           => '<!-- wp:group {"className":"universal-importer-steps","align":"full"} -->',
				'pullquote block'             => '<figure class="wp-block-pullquote has-text-align-center" id="html-smoke-pullquote"><blockquote><p>HTML pullquote smoke.</p><cite>HTML Citation</cite></blockquote></figure>',
				'custom html form'            => '<form action="/html-smoke-form" method="post">',
				'callout group block'         => '<div class="wp-block-group universal-importer-callout universal-importer-callout-warning alignwide" id="html-smoke-callout">',
				'pricing card block'          => '<div class="wp-block-group universal-importer-card alignwide" id="html-smoke-starter-plan">',
				'file block'                  => '<div class="wp-block-file aligncenter" id="html-smoke-file-row"><a href="',
				'linked picture block'        => '<figure class="wp-block-image aligncenter size-medium" id="html-smoke-linked-picture"><a href="/html-smoke-picture-full"><picture class="aligncenter">',
				'safe object fallback'        => '<object>Unsafe object fallback</object>',
				'safe image srcset strip'     => '<img src="/html-smoke-safe.jpg" alt="Unsafe srcset image">',
				'safe video poster strip'     => '<video src="/html-smoke-safe.mp4"></video>',
				'ordered list second item'    => "<!-- wp:list-item -->\n<li>HTML smoke third step</li>\n<!-- /wp:list-item --></ol>",
				'figure image attrs'          => '<!-- wp:image {"anchor":"html-smoke-figure-image"} -->',
				'table wrapper'               => '<figure class="wp-block-table alignwide is-style-stripes" id="html-smoke-table"><table class="has-fixed-layout">',
				'separator attrs'             => '<!-- wp:separator {"align":"wide","className":"is-style-dots","anchor":"html-smoke-separator"} -->',
				'gallery first image attrs'   => '<!-- wp:image {"href":"/html-gallery-large","linkDestination":"custom","sizeSlug":"thumbnail"} -->',
				'gallery second image attrs'  => '<!-- wp:image {"sizeSlug":"thumbnail","linkDestination":"none"} -->',
				'video figure wrapper'        => '<figure class="wp-block-video" id="html-smoke-video">',
				'audio figure wrapper'        => '<figure class="wp-block-audio" id="html-smoke-audio">',
				'video shortcode close'       => 'preload="metadata"></video></figure>',
				'audio shortcode attrs'       => '<!-- wp:audio {"src":"media/html-shortcode.mp3","controls":true,"preload":"none","align":"wide","anchor":"html-smoke-audio-shortcode"} -->',
				'wrapper video attrs'         => '<!-- wp:video {"src":"media/html-wrapper.mp4","controls":true,"preload":"metadata","align":"center","anchor":"html-smoke-wrapper-video"} -->',
				'wrapper video figure'        => '<figure class="wp-block-video aligncenter" id="html-smoke-wrapper-video"><video class="wp-video-shortcode" preload="metadata" controls="controls">',
				'wrapper audio attrs'         => '<!-- wp:audio {"src":"media/html-wrapper.mp3","controls":true,"preload":"none","align":"wide","anchor":"html-smoke-wrapper-audio"} -->',
				'wrapper audio figure'        => '<figure class="wp-block-audio alignwide" id="html-smoke-wrapper-audio"><audio class="wp-audio-shortcode" preload="none" controls="controls">',
				'vimeo embed figure'          => '<figure class="wp-block-embed is-type-video is-provider-vimeo wp-block-embed-vimeo alignwide" id="html-smoke-vimeo-embed"><div class="wp-block-embed__wrapper">https://vimeo.com/12345</div>',
				'media text block'            => '<!-- wp:media-text ',
				'media text anchor'           => '"anchor":"html-smoke-media-text"',
				'media text align'            => '"align":"full"',
				'media text wrapper'          => '<div class="wp-block-media-text is-stacked-on-mobile alignfull" id="html-smoke-media-text">',
				'media text figure'           => '<figure class="wp-block-media-text__media">',
				'social links wrapper'        => '<ul class="wp-block-social-links aligncenter has-visible-labels" id="html-smoke-social">',
				'social github link'          => '<!-- wp:social-link {"url":"https://github.com/wordpress","service":"github","label":"GitHub","rel":"me noopener"} /-->',
				'social youtube link'         => '<!-- wp:social-link {"url":"https://www.youtube.com/wordpress","service":"youtube","label":"YouTube"} /-->',
				'social mail link'            => '<!-- wp:social-link {"url":"mailto:hello@example.test","service":"mail","label":"Email"} /-->',
				'nav home link'               => '<!-- wp:navigation-link {"label":"HTML smoke home","url":"/html-nav-home","kind":"custom","isTopLevelLink":true} /-->',
				'nav services submenu'        => '<!-- wp:navigation-submenu {"label":"HTML smoke services","url":"/html-nav-services","kind":"custom","isTopLevelItem":true} -->',
				'nav imports link'            => '<!-- wp:navigation-link {"label":"HTML smoke imports","url":"/html-nav-imports","kind":"custom"} /-->',
				'nav submenu close'           => '<!-- /wp:navigation-submenu -->',
				'spacer attrs'                => '<!-- wp:spacer {"height":"36px","anchor":"html-smoke-spacer"} -->',
				'spacer wrapper'              => '<div style="height:36px" aria-hidden="true" class="wp-block-spacer" id="html-smoke-spacer"></div>',
				'details attrs'               => '<!-- wp:details {"showContent":true,"anchor":"html-faq","align":"full"} -->',
				'cover attrs'                 => '<!-- wp:cover ',
				'cover class'                 => '"className":"universal-importer-hero"',
				'cover align'                 => '"align":"wide"',
				'cover background image'      => 'wp-block-cover__image-background',
				'columns attrs'               => '<!-- wp:columns {"align":"wide","anchor":"html-smoke-columns"} -->',
				'left column attrs'           => '<!-- wp:column {"width":"50%","anchor":"html-smoke-left-column"} -->',
				'left column wrapper'         => '<div class="wp-block-column" style="flex-basis:50%" id="html-smoke-left-column">',
				'right column attrs'          => '<!-- wp:column {"width":"50%","anchor":"html-smoke-right-column"} -->',
				'right column wrapper'        => '<div class="wp-block-column" style="flex-basis:50%" id="html-smoke-right-column">',
				'timeline attrs'              => '<!-- wp:group {"className":"universal-importer-timeline","anchor":"html-smoke-timeline","align":"wide"} -->',
				'timeline item attrs'         => '<!-- wp:group {"className":"universal-importer-timeline-item","anchor":"html-smoke-timeline-one"} -->',
				'timeline marker attrs'       => '<!-- wp:paragraph {"className":"universal-importer-timeline-marker"} -->',
				'step item attrs'             => '<!-- wp:group {"className":"universal-importer-step-item","anchor":"html-smoke-step-review"} -->',
				'callout attrs'               => '<!-- wp:group {"className":"universal-importer-callout universal-importer-callout-warning","anchor":"html-smoke-callout","align":"wide"} -->',
				'full card attrs'             => '<!-- wp:group {"className":"universal-importer-card","align":"full"} -->',
				'starter card attrs'          => '<!-- wp:group {"className":"universal-importer-card","anchor":"html-smoke-starter-plan","align":"wide"} -->',
				'pullquote attrs'             => '<!-- wp:pullquote {"textAlign":"center","anchor":"html-smoke-pullquote"} -->',
				'figure quote attrs'          => '<!-- wp:quote {"textAlign":"right","anchor":"html-smoke-figure-quote"} -->',
				'figure quote wrapper'        => '<blockquote class="wp-block-quote has-text-align-right" id="html-smoke-figure-quote"><p>HTML smoke figure quote.</p><cite>HTML smoke quoted source</cite></blockquote>',
				'custom html block'           => '<!-- wp:html -->',
				'buttons attrs'               => '<!-- wp:buttons {"align":"wide"} -->',
				'button attrs'                => '<!-- wp:button {"url":"/html-smoke-cta","anchor":"html-smoke-button-row"} -->',
				'button wrapper'              => '<div class="wp-block-button" id="html-smoke-button-row"><a class="wp-block-button__link wp-element-button" href="/html-smoke-cta">HTML button CTA</a></div>',
				'file attrs'                  => '<!-- wp:file ',
				'file target attrs'           => '"align":"center","anchor":"html-smoke-file-row","textLinkTarget":"_blank"',
				'file target link'            => 'target="_blank">HTML file download</a>',
				'figure caption'              => '<figcaption class="wp-element-caption">HTML figure caption.</figcaption>',
				'linked image attrs'          => '<!-- wp:image {"href":"/html-linked-image","linkDestination":"custom","linkTarget":"_blank","rel":"noopener"} -->',
				'linked image caption'        => '<figcaption class="wp-element-caption">HTML linked image caption.</figcaption>',
				'classic caption attrs'       => '<!-- wp:image {"align":"center","sizeSlug":"large","anchor":"html-smoke-caption"} -->',
				'classic caption wrapper'     => '<figure class="wp-block-image aligncenter size-large" id="html-smoke-caption">',
				'classic caption text'        => '<figcaption class="wp-element-caption">HTML smoke classic caption.</figcaption>',
				'picture attrs'               => '<!-- wp:image {"align":"wide","sizeSlug":"large","anchor":"html-smoke-picture"} -->',
				'picture wrapper'             => '<figure class="wp-block-image alignwide size-large" id="html-smoke-picture"><picture class="alignwide">',
				'linked picture attrs'        => '<!-- wp:image {"align":"center","sizeSlug":"medium","href":"/html-smoke-picture-full","linkDestination":"custom","anchor":"html-smoke-linked-picture"} -->',
				'table caption'               => '<figcaption class="wp-element-caption">HTML table caption.</figcaption>',
				'video caption'               => '<figcaption class="wp-element-caption">HTML video caption.</figcaption>',
				'audio caption'               => '<figcaption class="wp-element-caption">HTML audio caption.</figcaption>',
				'media text heading'          => 'HTML smoke media text heading',
				'media text copy'             => 'HTML smoke media text copy.',
				'media text cta'              => 'HTML media text CTA',
				'definition details attrs'    => '<!-- wp:details {"anchor":"html-definition-faq-one","align":"wide"} -->',
				'definition details wrapper'  => '<details class="wp-block-details alignwide" id="html-definition-faq-one"><summary>Can definition lists become Details?</summary>',
				'accordion one attrs'         => '<!-- wp:details {"showContent":true,"anchor":"html-legacy-accordion-one","align":"full"} -->',
				'accordion one wrapper'       => '<details class="wp-block-details alignfull" id="html-legacy-accordion-one" name="html-legacy-accordion" open><summary>Legacy accordion one</summary>',
				'accordion two attrs'         => '<!-- wp:details {"anchor":"html-legacy-accordion-two","align":"wide"} -->',
				'accordion two wrapper'       => '<details class="wp-block-details alignwide" id="html-legacy-accordion-two" name="html-legacy-accordion"><summary>Legacy accordion two</summary>',
				'details content'             => 'Details content stays structured.',
				'definition inline answer'    => 'HTML definition-list answer with <strong>inline HTML</strong>.',
				'definition block answer'     => 'HTML definition-list block answer.',
				'definition list answer item' => 'HTML definition-list answer item.',
				'hero heading'                => 'HTML smoke hero heading',
				'hero copy'                   => 'HTML smoke hero copy.',
				'hero cta'                    => 'HTML hero CTA',
				'accordion first answer'      => 'Legacy accordion first answer.',
				'accordion second answer'     => 'Legacy accordion second answer.',
				'left column copy'            => 'HTML left column copy.',
				'right column item'           => 'HTML right column item',
				'timeline marker paragraph'   => '<p class="universal-importer-timeline-marker"><time datetime="2026-01">Q1 2026</time></p>',
				'timeline text'               => 'HTML smoke timeline first item.',
				'steps wrapper'               => '<div class="wp-block-group universal-importer-steps alignfull">',
				'step text'                   => 'HTML smoke review step.',
				'iframe preserved'            => '<iframe src="/html-smoke-frame" title="HTML smoke frame"></iframe>',
				'email input sanitized'       => '<input type="email" name="email">',
				'tabs wrapper'                => '<div class="tabs">',
				'tab one text'                => 'HTML smoke tab one',
				'tab two text'                => 'HTML smoke tab two',
				'callout text'                => 'HTML smoke warning callout.',
				'full card wrapper'           => '<div class="wp-block-group universal-importer-card alignfull">',
				'feature card text'           => 'HTML smoke feature card.',
				'starter text'                => 'HTML smoke starter plan.',
				'pro text'                    => 'HTML smoke pro plan.',
				'file source href'            => 'documents/document-smoke.pdf',
				'inline media intro'          => 'Inline HTML media intro',
				'inline media outro'          => 'Inline HTML media outro',
				'inline action'               => 'HTML inline action',
				'verse line two'              => 'HTML smoke verse line two',
				'php code text'               => '&lt;?php echo "HTML smoke";',
				'html body text'              => 'A packaged plugin HTML import smoke document.',
				'center heading attrs'        => '<!-- wp:heading {"level":2,"textAlign":"center","anchor":"html-smoke-centered-heading"} -->',
				'center heading wrapper'      => '<h2 class="has-text-align-center" id="html-smoke-centered-heading">HTML smoke centered heading</h2>',
				'center paragraph attrs'      => '<!-- wp:paragraph {"align":"center","anchor":"html-smoke-centered-paragraph"} -->',
				'center paragraph wrapper'    => '<p class="has-text-align-center" id="html-smoke-centered-paragraph">HTML smoke centered paragraph.</p>',
				'safe span attribute strip'   => '<span>Unsafe background attribute</span>',
			) as $label => $snippet
		) {
			if ( false === strpos( $content, $snippet ) ) {
				$missing_html[] = $label;
			}
		}
		foreach (
			array(
				'unexpected freeform block' => '<!-- wp:freeform -->',
				'unexpected script tag'     => '<script',
				'unexpected style tag'      => '<style',
				'unexpected javascript URL' => 'javascript:',
				'unexpected data SVG'       => 'data:image/svg+xml',
				'unexpected onsubmit attr'  => 'onsubmit',
				'unexpected onclick attr'   => 'onclick',
			) as $label => $snippet
		) {
			if ( false !== strpos( $content, $snippet ) ) {
				$missing_html[] = $label;
			}
		}
		if ( '' === (string) get_post_meta( $post->ID, '_universal_importer_session_id', true ) ) {
			$missing_html[] = 'missing session postmeta';
		}
		if ( '' === (string) get_post_meta( $post->ID, '_universal_importer_source_item_key', true ) ) {
			$missing_html[] = 'missing source item postmeta';
		}
		$offset  = strpos( $content, 'html-shortcode' );
		$offset  = false === $offset ? strpos( $content, 'wp:social' ) : $offset;
		$offset  = false === $offset ? strpos( $content, 'html-smoke-widget' ) : $offset;
		$excerpt = false === $offset ? substr( $content, 0, 1600 ) : substr( $content, max( 0, $offset - 240 ), 1600 );
		$excerpt = str_replace( array( "\r", "\n" ), array( '\r', '\n' ), $excerpt );
		$html_debug = ' Missing HTML markers near media/embed segment: ' . ( empty( $missing_html ) ? 'none from diagnostic subset' : implode( ', ', $missing_html ) ) . '. HTML content excerpt: ' . $excerpt;
		break;
	}

	throw new Exception( 'WP-CLI smoke import did not persist the expected imported page from the HTML fixture. Imported titles found: ' . ( empty( $html_titles ) ? 'none' : implode( ', ', $html_titles ) ) . '.' . $html_debug );
}

$html_widget_post_id = 0;
foreach ( $html_posts as $post ) {
	if (
		'Packaged HTML Widget Smoke' === $post->post_title
		&& false !== strpos( $post->post_content, '<!-- wp:freeform -->' )
		&& false !== strpos( $post->post_content, '<aside class="widget widget_media_image" id="html-smoke-widget-image">' )
		&& false !== strpos( $post->post_content, '<h2 class="widget-title">HTML smoke legacy image widget</h2>' )
		&& false !== strpos( $post->post_content, '<a href="/html-widget-image"><img src="' )
		&& false !== strpos( $post->post_content, 'alt="HTML smoke widget image"></a>' )
		&& false !== strpos( $post->post_content, '<p class="wp-caption-text">HTML smoke widget caption.</p>' )
		&& false !== strpos( $post->post_content, '<section class="widget widget_search" id="html-smoke-widget-search">' )
		&& false !== strpos( $post->post_content, '<h2 class="widget-title">HTML smoke legacy search widget</h2>' )
		&& false !== strpos( $post->post_content, '<form role="search" method="get" action="/html-widget-search">' )
		&& false !== strpos( $post->post_content, 'HTML smoke widget body.' )
		&& false === strpos( $post->post_content, '<!-- wp:image' )
		&& false === strpos( $post->post_content, '<!-- wp:search' )
		&& false === strpos( $post->post_content, '<script' )
		&& '' !== (string) get_post_meta( $post->ID, '_universal_importer_session_id', true )
		&& '' !== (string) get_post_meta( $post->ID, '_universal_importer_source_item_key', true )
	) {
		$html_widget_post_id = (int) $post->ID;
		break;
	}
}

if ( $html_widget_post_id < 1 ) {
	throw new Exception( 'WP-CLI smoke import did not preserve the expected legacy widget Classic fallback page from the HTML widget fixture. Imported titles found: ' . ( empty( $html_titles ) ? 'none' : implode( ', ', $html_titles ) ) . '.' );
}

$archive_posts = get_posts(
	array(
		'post_type'              => 'page',
		'post_status'            => 'any',
		'posts_per_page'         => 10,
		'orderby'                => 'ID',
		'order'                  => 'DESC',
		'no_found_rows'          => true,
		'update_post_meta_cache' => true,
		'update_post_term_cache' => false,
		'meta_query'             => array(
			array(
				'key'   => '_universal_importer_document_format',
				'value' => 'markdown',
			),
		),
	)
);

$archive_titles = array();
$archive_post_id = 0;
foreach ( $archive_posts as $post ) {
	$archive_titles[] = $post->post_title;

	if (
		'Packaged Archive Smoke' === $post->post_title
		&& false !== strpos( $post->post_content, '<h1>Packaged Archive Smoke</h1>' )
		&& false !== strpos( $post->post_content, 'A packaged plugin archive traversal document.' )
		&& '' !== (string) get_post_meta( $post->ID, '_universal_importer_session_id', true )
		&& false !== strpos( (string) get_post_meta( $post->ID, '_universal_importer_source_item_key', true ), 'zip:' )
	) {
		$archive_post_id = (int) $post->ID;
		break;
	}
}

if ( $archive_post_id < 1 ) {
	throw new Exception( 'WP-CLI smoke import did not persist the expected imported Markdown page from the zip archive. Imported titles found: ' . ( empty( $archive_titles ) ? 'none' : implode( ', ', $archive_titles ) ) . '.' );
}

$wxr_posts = get_posts(
	array(
		'post_type'              => 'page',
		'post_status'            => 'any',
		'posts_per_page'         => 10,
		'orderby'                => 'ID',
		'order'                  => 'DESC',
		'no_found_rows'          => true,
		'update_post_meta_cache' => true,
		'update_post_term_cache' => false,
		'meta_query'             => array(
			array(
				'key'   => '_universal_importer_document_format',
				'value' => 'wxr',
			),
		),
	)
);

$wxr_titles = array();
$wxr_post_id = 0;
foreach ( $wxr_posts as $post ) {
	$wxr_titles[] = $post->post_title;

	if (
		'Packaged WXR Smoke' === $post->post_title
		&& false !== strpos( $post->post_content, '<!-- wp:paragraph -->' )
		&& false !== strpos( $post->post_content, 'A packaged plugin WXR import smoke document.' )
		&& false === strpos( $post->post_content, '<script' )
		&& '' !== (string) get_post_meta( $post->ID, '_universal_importer_session_id', true )
		&& false !== strpos( (string) get_post_meta( $post->ID, '_universal_importer_source_item_key', true ), ':wxr-post:' )
	) {
		$wxr_post_id = (int) $post->ID;
		break;
	}
}

if ( $wxr_post_id < 1 ) {
	throw new Exception( 'WP-CLI smoke import did not persist the expected imported page from the WXR export. Imported titles found: ' . ( empty( $wxr_titles ) ? 'none' : implode( ', ', $wxr_titles ) ) . '.' );
}

$epub_posts = get_posts(
	array(
		'post_type'              => 'page',
		'post_status'            => 'any',
		'posts_per_page'         => 10,
		'orderby'                => 'ID',
		'order'                  => 'DESC',
		'no_found_rows'          => true,
		'update_post_meta_cache' => true,
		'update_post_term_cache' => false,
		'meta_query'             => array(
			array(
				'key'   => '_universal_importer_document_format',
				'value' => 'epub',
			),
		),
	)
);

$epub_titles = array();
$epub_first_post_id = 0;
$epub_second_post_id = 0;
$epub_first_content = '';
foreach ( $epub_posts as $post ) {
	$epub_titles[] = $post->post_title;

	if (
		'Packaged EPUB Chapter One' === $post->post_title
		&& false !== strpos( $post->post_content, '<!-- wp:freeform -->' )
		&& false !== strpos( $post->post_content, 'A packaged plugin EPUB import smoke document.' )
		&& false === strpos( $post->post_content, '<script' )
		&& '' !== (string) get_post_meta( $post->ID, '_universal_importer_session_id', true )
		&& false !== strpos( (string) get_post_meta( $post->ID, '_universal_importer_source_item_key', true ), ':epub-spine:0' )
	) {
		$epub_first_post_id = (int) $post->ID;
		$epub_first_content = $post->post_content;
	}

	if (
		'Packaged EPUB Chapter Two' === $post->post_title
		&& false !== strpos( $post->post_content, '<h1 id="part">Packaged EPUB Chapter Two</h1>' )
		&& false !== strpos( $post->post_content, 'A packaged plugin EPUB second chapter.' )
		&& '' !== (string) get_post_meta( $post->ID, '_universal_importer_session_id', true )
		&& false !== strpos( (string) get_post_meta( $post->ID, '_universal_importer_source_item_key', true ), ':epub-spine:1' )
	) {
		$epub_second_post_id = (int) $post->ID;
	}
}

if ( $epub_first_post_id < 1 || $epub_second_post_id < 1 ) {
	throw new Exception( 'WP-CLI smoke import did not persist the expected imported pages from the EPUB spine. Imported titles found: ' . ( empty( $epub_titles ) ? 'none' : implode( ', ', $epub_titles ) ) . '.' );
}

if ( false !== strpos( $epub_first_content, 'chapter-two.xhtml' ) || false !== strpos( $epub_first_content, 'href="#universal-importer-epub-' ) || false === strpos( $epub_first_content, '#part' ) ) {
	throw new Exception( 'WP-CLI smoke import did not resolve the EPUB internal chapter link in the first imported page.' );
}

$pdf_posts = get_posts(
	array(
		'post_type'              => 'page',
		'post_status'            => 'any',
		'posts_per_page'         => 10,
		'orderby'                => 'ID',
		'order'                  => 'DESC',
		'no_found_rows'          => true,
		'update_post_meta_cache' => true,
		'update_post_term_cache' => false,
		'meta_query'             => array(
			array(
				'key'   => '_universal_importer_document_format',
				'value' => 'pdf',
			),
		),
	)
);

$pdf_titles = array();
$pdf_post_id = 0;
$unsupported_pdf_post_id = 0;
$external_pdf_post_id = 0;
$layout_pdf_post_id = 0;
$corrupt_pdf_post_id = 0;
foreach ( $pdf_posts as $post ) {
	$pdf_titles[] = $post->post_title;

	if (
		'Packaged PDF Smoke' === $post->post_title
		&& false !== strpos( $post->post_content, '<!-- wp:heading {"level":1} -->' )
		&& false !== strpos( $post->post_content, '<h1>Packaged PDF Smoke</h1>' )
		&& false !== strpos( $post->post_content, '<!-- wp:paragraph -->' )
		&& false !== strpos( $post->post_content, 'A packaged plugin PDF import smoke document.' )
		&& false !== strpos( $post->post_content, $pdf_attachment_url )
		&& false === strpos( $post->post_content, 'uwi-pdf-asset://' )
		&& '' !== (string) get_post_meta( $post->ID, '_universal_importer_session_id', true )
		&& '' !== (string) get_post_meta( $post->ID, '_universal_importer_source_item_key', true )
	) {
		$source_item_key = (string) get_post_meta( $post->ID, '_universal_importer_source_item_key', true );
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT metadata_json FROM `' . str_replace( '`', '``', $tables['source_items'] ) . '` WHERE item_key = %s LIMIT 1',
				$source_item_key
			),
			ARRAY_A
		);
		$metadata = is_array( $row ) && isset( $row['metadata_json'] ) ? json_decode( (string) $row['metadata_json'], true ) : null;
		if (
			is_array( $metadata )
			&& 'queued' === ( isset( $metadata['pdf_embedded_media_extraction_status'] ) ? (string) $metadata['pdf_embedded_media_extraction_status'] : '' )
			&& 1 === ( isset( $metadata['pdf_embedded_media_queued'] ) ? (int) $metadata['pdf_embedded_media_queued'] : 0 )
		) {
			$pdf_post_id = (int) $post->ID;
			continue;
		}
	}

	if (
		'Packaged Unsupported PDF Media Smoke' === $post->post_title
		&& false !== strpos( $post->post_content, '<!-- wp:heading {"level":1} -->' )
		&& false !== strpos( $post->post_content, '<h1>Packaged Unsupported PDF Media Smoke</h1>' )
		&& false !== strpos( $post->post_content, 'A packaged plugin PDF unsupported media diagnostic document.' )
		&& false === strpos( $post->post_content, 'uwi-pdf-asset://' )
		&& '' !== (string) get_post_meta( $post->ID, '_universal_importer_session_id', true )
		&& '' !== (string) get_post_meta( $post->ID, '_universal_importer_source_item_key', true )
	) {
		$source_item_key = (string) get_post_meta( $post->ID, '_universal_importer_source_item_key', true );
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT metadata_json FROM `' . str_replace( '`', '``', $tables['source_items'] ) . '` WHERE item_key = %s LIMIT 1',
				$source_item_key
			),
			ARRAY_A
		);
		$metadata = is_array( $row ) && isset( $row['metadata_json'] ) ? json_decode( (string) $row['metadata_json'], true ) : null;
		if (
			is_array( $metadata )
			&& 'unsupported' === ( isset( $metadata['pdf_embedded_media_extraction_status'] ) ? (string) $metadata['pdf_embedded_media_extraction_status'] : '' )
			&& 1 === ( isset( $metadata['pdf_unsupported_embedded_media_count'] ) ? (int) $metadata['pdf_unsupported_embedded_media_count'] : 0 )
			&& in_array( 'JPXDecode', isset( $metadata['pdf_unsupported_embedded_media_filters'] ) && is_array( $metadata['pdf_unsupported_embedded_media_filters'] ) ? $metadata['pdf_unsupported_embedded_media_filters'] : array(), true )
			&& in_array( 'unsupported_filter', isset( $metadata['pdf_unsupported_embedded_media_reasons'] ) && is_array( $metadata['pdf_unsupported_embedded_media_reasons'] ) ? $metadata['pdf_unsupported_embedded_media_reasons'] : array(), true )
			&& false !== strpos( isset( $metadata['pdf_embedded_media_hint'] ) ? (string) $metadata['pdf_embedded_media_hint'] : '', 'Unsupported filters: JPXDecode' )
		) {
			$unsupported_pdf_post_id = (int) $post->ID;
		}
	}

	if (
		'Packaged External PDF Smoke' === $post->post_title
		&& false !== strpos( $post->post_content, '<!-- wp:heading {"level":1} -->' )
		&& false !== strpos( $post->post_content, '<h1>Packaged External PDF Smoke</h1>' )
		&& false !== strpos( $post->post_content, '<!-- wp:paragraph -->' )
		&& false !== strpos( $post->post_content, 'A packaged plugin external PDF text document.' )
		&& '' !== (string) get_post_meta( $post->ID, '_universal_importer_session_id', true )
		&& '' !== (string) get_post_meta( $post->ID, '_universal_importer_source_item_key', true )
	) {
		$source_item_key = (string) get_post_meta( $post->ID, '_universal_importer_source_item_key', true );
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT metadata_json FROM `' . str_replace( '`', '``', $tables['source_items'] ) . '` WHERE item_key = %s LIMIT 1',
				$source_item_key
			),
			ARRAY_A
		);
		$metadata = is_array( $row ) && isset( $row['metadata_json'] ) ? json_decode( (string) $row['metadata_json'], true ) : null;
		if (
			is_array( $metadata )
			&& 'external' === ( isset( $metadata['pdf_text_engine'] ) ? (string) $metadata['pdf_text_engine'] : '' )
			&& 'succeeded' === ( isset( $metadata['pdf_external_text_status'] ) ? (string) $metadata['pdf_external_text_status'] : '' )
		) {
			$external_pdf_post_id = (int) $post->ID;
		}
	}

	if (
		'Packaged Layout PDF Smoke' === $post->post_title
		&& false !== strpos( $post->post_content, '<!-- wp:heading {"level":1} -->' )
		&& false !== strpos( $post->post_content, '<h1>Packaged Layout PDF Smoke</h1>' )
		&& false !== strpos( $post->post_content, '<!-- wp:table -->' )
		&& false !== strpos( $post->post_content, '<td>Name</td><td>Count</td><td>Total</td>' )
		&& false !== strpos( $post->post_content, '<td>Alpha</td><td>2</td><td>$10</td>' )
		&& false !== strpos( $post->post_content, '<td>Beta</td><td>3</td><td>$12</td>' )
		&& false !== strpos( $post->post_content, 'A packaged plugin layout-aware PDF table document.' )
		&& '' !== (string) get_post_meta( $post->ID, '_universal_importer_session_id', true )
		&& '' !== (string) get_post_meta( $post->ID, '_universal_importer_source_item_key', true )
	) {
		$source_item_key = (string) get_post_meta( $post->ID, '_universal_importer_source_item_key', true );
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT metadata_json FROM `' . str_replace( '`', '``', $tables['source_items'] ) . '` WHERE item_key = %s LIMIT 1',
				$source_item_key
			),
			ARRAY_A
		);
		$metadata = is_array( $row ) && isset( $row['metadata_json'] ) ? json_decode( (string) $row['metadata_json'], true ) : null;
		if (
			is_array( $metadata )
			&& 'external' === ( isset( $metadata['pdf_text_engine'] ) ? (string) $metadata['pdf_text_engine'] : '' )
			&& 'succeeded' === ( isset( $metadata['pdf_external_text_status'] ) ? (string) $metadata['pdf_external_text_status'] : '' )
			&& 1 === ( isset( $metadata['pdf_table_block_count'] ) ? (int) $metadata['pdf_table_block_count'] : 0 )
			&& 3 === ( isset( $metadata['pdf_table_row_count'] ) ? (int) $metadata['pdf_table_row_count'] : 0 )
			&& 3 === ( isset( $metadata['pdf_table_max_column_count'] ) ? (int) $metadata['pdf_table_max_column_count'] : 0 )
			&& false !== strpos( isset( $metadata['pdf_layout_warning'] ) ? (string) $metadata['pdf_layout_warning'] : '', 'converted to WordPress table blocks' )
		) {
			$layout_pdf_post_id = (int) $post->ID;
		}
	}

	if (
		'Packaged Corrupt PDF Smoke' === $post->post_title
		&& false !== strpos( $post->post_content, '<!-- wp:heading {"level":1} -->' )
		&& false !== strpos( $post->post_content, '<h1>Packaged Corrupt PDF Smoke</h1>' )
		&& false !== strpos( $post->post_content, '<!-- wp:paragraph -->' )
		&& false !== strpos( $post->post_content, 'A packaged plugin corrupt PDF structure diagnostic document.' )
		&& '' !== (string) get_post_meta( $post->ID, '_universal_importer_session_id', true )
		&& '' !== (string) get_post_meta( $post->ID, '_universal_importer_source_item_key', true )
	) {
		$source_item_key = (string) get_post_meta( $post->ID, '_universal_importer_source_item_key', true );
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT metadata_json FROM `' . str_replace( '`', '``', $tables['source_items'] ) . '` WHERE item_key = %s LIMIT 1',
				$source_item_key
			),
			ARRAY_A
		);
		$metadata = is_array( $row ) && isset( $row['metadata_json'] ) ? json_decode( (string) $row['metadata_json'], true ) : null;
		if (
			is_array( $metadata )
			&& 'native' === ( isset( $metadata['pdf_text_engine'] ) ? (string) $metadata['pdf_text_engine'] : '' )
			&& 'limited' === ( isset( $metadata['pdf_structure_status'] ) ? (string) $metadata['pdf_structure_status'] : '' )
			&& in_array( 'object_streams_present', isset( $metadata['pdf_structure_reasons'] ) && is_array( $metadata['pdf_structure_reasons'] ) ? $metadata['pdf_structure_reasons'] : array(), true )
			&& in_array( 'missing_eof_marker', isset( $metadata['pdf_structure_reasons'] ) && is_array( $metadata['pdf_structure_reasons'] ) ? $metadata['pdf_structure_reasons'] : array(), true )
			&& 1 === ( isset( $metadata['pdf_object_stream_count'] ) ? (int) $metadata['pdf_object_stream_count'] : 0 )
			&& false === ( isset( $metadata['pdf_eof_marker_seen'] ) ? (bool) $metadata['pdf_eof_marker_seen'] : true )
			&& false !== strpos( isset( $metadata['pdf_structure_warning'] ) ? (string) $metadata['pdf_structure_warning'] : '', 'Compressed object streams were detected' )
			&& false !== strpos( isset( $metadata['pdf_structure_warning'] ) ? (string) $metadata['pdf_structure_warning'] : '', 'missing a %%EOF trailer marker' )
		) {
			$corrupt_pdf_post_id = (int) $post->ID;
		}
	}
}

if ( $pdf_post_id < 1 ) {
	throw new Exception( 'WP-CLI smoke import did not persist the expected imported page, embedded image attachment rewrite, and PDF media extraction metadata from the PDF fixture. Imported titles found: ' . ( empty( $pdf_titles ) ? 'none' : implode( ', ', $pdf_titles ) ) . '.' );
}

if ( $unsupported_pdf_post_id < 1 ) {
	throw new Exception( 'WP-CLI smoke import did not preserve unsupported embedded PDF media diagnostics for the unsupported-media PDF fixture. Imported titles found: ' . ( empty( $pdf_titles ) ? 'none' : implode( ', ', $pdf_titles ) ) . '.' );
}

$unsupported_pdf_events = (int) $wpdb->get_var(
	$wpdb->prepare(
		'SELECT COUNT(*) FROM `' . str_replace( '`', '``', $tables['events'] ) . '` WHERE event_type = %s',
		'media.pdf_asset_unsupported'
	)
);
if ( 1 > $unsupported_pdf_events ) {
	throw new Exception( 'WP-CLI smoke import did not record an unsupported embedded PDF media warning event.' );
}

if ( $external_pdf_post_id < 1 ) {
	throw new Exception( 'WP-CLI smoke import did not persist the expected imported page and external text metadata from the textless PDF fixture. Imported titles found: ' . ( empty( $pdf_titles ) ? 'none' : implode( ', ', $pdf_titles ) ) . '.' );
}

if ( $layout_pdf_post_id < 1 ) {
	throw new Exception( 'WP-CLI smoke import did not persist the expected layout-aware PDF table block and pdf_table metadata from the textless PDF fixture. Imported titles found: ' . ( empty( $pdf_titles ) ? 'none' : implode( ', ', $pdf_titles ) ) . '.' );
}

if ( $corrupt_pdf_post_id < 1 ) {
	throw new Exception( 'WP-CLI smoke import did not preserve corrupt PDF structure diagnostics for the corrupt-structure PDF fixture. Imported titles found: ' . ( empty( $pdf_titles ) ? 'none' : implode( ', ', $pdf_titles ) ) . '.' );
}

$layout_pdf_events = (int) $wpdb->get_var(
	$wpdb->prepare(
		'SELECT COUNT(*) FROM `' . str_replace( '`', '``', $tables['events'] ) . '` WHERE event_type = %s',
		'document.pdf_table_blocks'
	)
);
if ( 1 > $layout_pdf_events ) {
	throw new Exception( 'WP-CLI smoke import did not record a PDF table-block conversion progress event.' );
}

$structure_pdf_events = (int) $wpdb->get_var(
	$wpdb->prepare(
		'SELECT COUNT(*) FROM `' . str_replace( '`', '``', $tables['events'] ) . '` WHERE event_type = %s',
		'document.pdf_structure_warning'
	)
);
if ( 1 > $structure_pdf_events ) {
	throw new Exception( 'WP-CLI smoke import did not record a corrupt PDF structure warning event.' );
}

$broken_pdf_row = $wpdb->get_row(
	$wpdb->prepare(
		'SELECT status, source_uri, metadata_json FROM `' . str_replace( '`', '``', $tables['source_items'] ) . '` WHERE status = %s AND source_uri LIKE %s ORDER BY id DESC LIMIT 1',
		'failed',
		'%' . $wpdb->esc_like( 'external-text-broken.pdf' )
	),
	ARRAY_A
);
$broken_pdf_metadata = is_array( $broken_pdf_row ) && isset( $broken_pdf_row['metadata_json'] ) ? json_decode( (string) $broken_pdf_row['metadata_json'], true ) : null;
$broken_pdf_error    = is_array( $broken_pdf_metadata ) && isset( $broken_pdf_metadata['pdf_external_text_error'] ) ? (string) $broken_pdf_metadata['pdf_external_text_error'] : '';

if (
	! is_array( $broken_pdf_metadata )
	|| 'pdf' !== ( isset( $broken_pdf_metadata['document_format'] ) ? (string) $broken_pdf_metadata['document_format'] : '' )
	|| 'failed' !== ( isset( $broken_pdf_metadata['pdf_external_text_status'] ) ? (string) $broken_pdf_metadata['pdf_external_text_status'] : '' )
	|| ( false === strpos( $broken_pdf_error, 'Unexpected external PDF text smoke input' ) && false === strpos( $broken_pdf_error, 'External PDF text command timed out after 5 seconds' ) )
	|| 'not_configured' !== ( isset( $broken_pdf_metadata['pdf_ocr_status'] ) ? (string) $broken_pdf_metadata['pdf_ocr_status'] : '' )
) {
	$broken_pdf_debug = is_array( $broken_pdf_row )
		? ' Matched row status=' . ( isset( $broken_pdf_row['status'] ) ? (string) $broken_pdf_row['status'] : '' ) . ' source_uri=' . ( isset( $broken_pdf_row['source_uri'] ) ? (string) $broken_pdf_row['source_uri'] : '' ) . ' metadata=' . wp_json_encode( $broken_pdf_metadata )
		: ' No failed source item row matched external-text-broken.pdf.';
	throw new Exception( 'WP-CLI smoke import did not preserve the expected failed external PDF helper diagnostics for the broken textless PDF fixture.' . $broken_pdf_debug );
}

echo 'UNIVERSAL_IMPORTER_IMPORT_SMOKE_OK attachment_id=' . $attachment_id . ' pdf_attachment_id=' . $pdf_attachment_id . ' html_post_id=' . $html_post_id . ' html_widget_post_id=' . $html_widget_post_id . ' archive_post_id=' . $archive_post_id . ' wxr_post_id=' . $wxr_post_id . ' epub_first_post_id=' . $epub_first_post_id . ' epub_second_post_id=' . $epub_second_post_id . ' pdf_post_id=' . $pdf_post_id . ' unsupported_pdf_post_id=' . $unsupported_pdf_post_id . ' external_pdf_post_id=' . $external_pdf_post_id . ' layout_pdf_post_id=' . $layout_pdf_post_id . ' corrupt_pdf_post_id=' . $corrupt_pdf_post_id . ' broken_external_pdf=failed' . "\n";
PHP;
	}

	/**
	 * Returns PHP code that verifies a REST traversal smoke import.
	 *
	 * @return string PHP code.
	 */
	private function rest_import_assertion_php() {
		return <<<'PHP'
<?php
require_once '/wordpress/wp-load.php';

global $wpdb;
$tables = UniversalImporter\Import\WordPressImportSessionSchema::from_globals()->get_table_names();

if ( ! function_exists( 'get_posts' ) || ! function_exists( 'get_post_meta' ) || ! function_exists( 'get_comments' ) || ! function_exists( 'get_comment_meta' ) ) {
	throw new Exception( 'WordPress post/comment APIs are unavailable during REST import smoke verification.' );
}

$rest_attachments = get_posts(
	array(
		'post_type'              => 'attachment',
		'post_status'            => 'any',
		'posts_per_page'         => 10,
		'orderby'                => 'ID',
		'order'                  => 'DESC',
		'no_found_rows'          => true,
		'update_post_meta_cache' => true,
		'update_post_term_cache' => false,
		'meta_query'             => array(
			array(
				'key'     => '_universal_importer_original_url',
				'value'   => 'rest-featured.png',
				'compare' => 'LIKE',
			),
		),
	)
);

$rest_attachment_id  = 0;
$rest_attachment_url = '';
$rest_original_url   = '';
foreach ( $rest_attachments as $attachment ) {
	$url = function_exists( 'wp_get_attachment_url' ) ? wp_get_attachment_url( $attachment->ID ) : '';
	if (
		is_string( $url )
		&& '' !== $url
		&& false !== strpos( $url, '/wp-content/uploads/' )
		&& '' !== (string) get_post_meta( $attachment->ID, '_universal_importer_session_id', true )
		&& '' !== (string) get_post_meta( $attachment->ID, '_universal_importer_media_reference_key', true )
	) {
		$rest_attachment_id  = (int) $attachment->ID;
		$rest_attachment_url = $url;
		$rest_original_url   = (string) get_post_meta( $attachment->ID, '_universal_importer_original_url', true );
		break;
	}
}

if ( $rest_attachment_id < 1 ) {
	$media_rows = $wpdb->get_results(
		$wpdb->prepare(
			'SELECT original_url, resolved_source_uri, status, metadata_json FROM `' . str_replace( '`', '``', $tables['media'] ) . '` WHERE original_url LIKE %s OR resolved_source_uri LIKE %s ORDER BY id DESC LIMIT 5',
			'%' . $wpdb->esc_like( 'rest-featured.png' ) . '%',
			'%' . $wpdb->esc_like( 'rest-featured.png' ) . '%'
		),
		ARRAY_A
	);
	$media_events = $wpdb->get_results(
		$wpdb->prepare(
			'SELECT event_type, message, context_json FROM `' . str_replace( '`', '``', $tables['events'] ) . '` WHERE context_json LIKE %s ORDER BY id DESC LIMIT 5',
			'%' . $wpdb->esc_like( 'rest-featured.png' ) . '%'
		),
		ARRAY_A
	);
	throw new Exception( 'WP-CLI smoke import did not import the REST featured image attachment. Media rows: ' . wp_json_encode( $media_rows ) . ' Media events: ' . wp_json_encode( $media_events ) );
}

$rest_posts = get_posts(
	array(
		'post_type'              => 'page',
		'post_status'            => 'any',
		'posts_per_page'         => 10,
		'orderby'                => 'ID',
		'order'                  => 'DESC',
		'no_found_rows'          => true,
		'update_post_meta_cache' => true,
		'update_post_term_cache' => false,
		'meta_query'             => array(
			array(
				'key'   => '_universal_importer_document_format',
				'value' => 'wp-rest',
			),
		),
	)
);

$rest_titles = array();
$rest_post_id = 0;
$rest_source_item_key = '';
foreach ( $rest_posts as $post ) {
	$rest_titles[] = $post->post_title;
	$source_item_key = (string) get_post_meta( $post->ID, '_universal_importer_source_item_key', true );

	if (
		'Packaged REST Smoke' === $post->post_title
		&& false !== strpos( $post->post_content, '<!-- wp:image ' )
		&& false !== strpos( $post->post_content, $rest_attachment_url )
		&& ( '' === $rest_original_url || $rest_original_url === $rest_attachment_url || false === strpos( $post->post_content, $rest_original_url ) )
		&& false !== strpos( $post->post_content, '<!-- wp:paragraph -->' )
		&& false === strpos( $post->post_content, '<!-- wp:freeform -->' )
		&& false !== strpos( $post->post_content, 'A packaged plugin REST import smoke document.' )
		&& false === strpos( $post->post_content, '<script' )
		&& '' !== (string) get_post_meta( $post->ID, '_universal_importer_session_id', true )
		&& false !== strpos( $source_item_key, 'remote-rest:' )
	) {
		$rest_post_id = (int) $post->ID;
		$rest_source_item_key = $source_item_key;
		break;
	}
}

if ( $rest_post_id < 1 ) {
	throw new Exception( 'WP-CLI smoke import did not persist the expected imported page from the WordPress REST traversal. Imported titles found: ' . ( empty( $rest_titles ) ? 'none' : implode( ', ', $rest_titles ) ) . '.' );
}

$rest_comments = get_comments(
	array(
		'post_id'       => $rest_post_id,
		'status'        => 'all',
		'number'        => 10,
		'orderby'       => 'comment_ID',
		'order'         => 'ASC',
		'no_found_rows' => true,
		'meta_query'    => array(
			array(
				'key'   => '_universal_importer_source_item_key',
				'value' => $rest_source_item_key,
			),
		),
	)
);

$parent_comment = null;
$child_comment  = null;
foreach ( $rest_comments as $comment ) {
	if ( false !== strpos( (string) $comment->comment_content, 'First REST smoke comment.' ) ) {
		$parent_comment = $comment;
	}

	if ( false !== strpos( (string) $comment->comment_content, 'Nested REST smoke reply.' ) ) {
		$child_comment = $comment;
	}
}

if ( null === $parent_comment || null === $child_comment ) {
	throw new Exception( 'WP-CLI smoke import did not persist the expected REST comments on the imported page.' );
}

if (
	false === strpos( $parent_comment->comment_content, 'First REST smoke comment.' )
	|| false !== strpos( $parent_comment->comment_content, '<script' )
	|| 0 !== (int) $parent_comment->comment_parent
	|| 'https://reader.example.test/' !== (string) $parent_comment->comment_author_url
) {
	throw new Exception( 'WP-CLI smoke import did not preserve sanitized top-level REST comment content and metadata.' );
}

if (
	false === strpos( $child_comment->comment_content, 'Nested REST smoke reply.' )
	|| (int) $parent_comment->comment_ID !== (int) $child_comment->comment_parent
	|| (string) $parent_comment->comment_ID !== (string) get_comment_meta( $child_comment->comment_ID, '_universal_importer_local_parent_comment_id', true )
) {
	throw new Exception( 'WP-CLI smoke import did not preserve REST comment parent mapping.' );
}

$comment_created_events = (int) $wpdb->get_var(
	$wpdb->prepare(
		'SELECT COUNT(*) FROM `' . str_replace( '`', '``', $tables['events'] ) . '` WHERE event_type = %s',
		'comment.created'
	)
);
if ( 2 > $comment_created_events ) {
	throw new Exception( 'WP-CLI smoke import did not record comment.created progress events for REST comments.' );
}

echo 'UNIVERSAL_IMPORTER_REST_SMOKE_OK rest_post_id=' . $rest_post_id . ' rest_attachment_id=' . $rest_attachment_id . ' rest_comment_parent_id=' . (int) $parent_comment->comment_ID . ' rest_comment_child_id=' . (int) $child_comment->comment_ID . "\n";
PHP;
	}

	/**
	 * Returns PHP code that resolves a REST relationship mapping decision through the admin surface.
	 *
	 * @return string PHP code.
	 */
	private function rest_relationship_mapping_resolution_php() {
		$author_name      = var_export( self::REST_REMOTE_AUTHOR_NAME, true );
		$author_slug      = var_export( self::REST_REMOTE_AUTHOR_SLUG, true );
		$taxonomy         = var_export( self::REST_REMOTE_TAXONOMY, true );
		$remote_term_name = var_export( self::REST_REMOTE_TERM_NAME, true );
		$remote_term_slug = var_export( self::REST_REMOTE_TERM_SLUG, true );
		$local_term_name  = var_export( self::REST_LOCAL_TERM_NAME, true );
		$local_term_slug  = var_export( self::REST_LOCAL_TERM_SLUG, true );

		return <<<PHP
<?php
require_once '/wordpress/wp-load.php';

global \$wpdb;
\$tables = UniversalImporter\Import\WordPressImportSessionSchema::from_globals()->get_table_names();

if ( ! function_exists( 'get_user_by' ) || ! function_exists( 'term_exists' ) || ! function_exists( 'wp_insert_term' ) ) {
	throw new Exception( 'WordPress user and taxonomy APIs are unavailable during REST relationship decision resolution.' );
}

\$decision = \$wpdb->get_row(
	\$wpdb->prepare(
		'SELECT session_id, decision_key FROM `' . str_replace( '`', '``', \$tables['decisions'] ) . '` WHERE status = %s AND decision_key LIKE %s ORDER BY id DESC LIMIT 1',
		'pending',
		'map-rest-relationships:%'
	),
	ARRAY_A
);

if ( ! is_array( \$decision ) || empty( \$decision['session_id'] ) || empty( \$decision['decision_key'] ) ) {
	throw new Exception( 'No pending REST relationship mapping decision was available to resolve.' );
}

\$user = get_user_by( 'login', 'admin' );
if ( false === \$user || empty( \$user->ID ) ) {
	\$user = get_user_by( 'id', 1 );
}

if ( false === \$user || empty( \$user->ID ) ) {
	throw new Exception( 'No local administrator user was available for REST relationship mapping.' );
}

\$term = term_exists( {$local_term_slug}, 'category' );
if ( 0 === \$term || null === \$term ) {
	\$term = wp_insert_term(
		{$local_term_name},
		'category',
		array(
			'slug' => {$local_term_slug},
		)
	);
}

if ( is_wp_error( \$term ) ) {
	throw new Exception( 'Unable to create the local REST relationship mapping term: ' . \$term->get_error_message() );
}

\$term_id = is_array( \$term ) && isset( \$term['term_id'] ) ? (int) \$term['term_id'] : (int) \$term;
if ( \$term_id < 1 ) {
	throw new Exception( 'The local REST relationship mapping term did not return a usable id.' );
}

\$answer = array(
	'author' => array(
		'local_user_id' => (int) \$user->ID,
	),
	'terms'  => array(
		{$taxonomy} => array(
			array(
				'remote_slug'    => {$remote_term_slug},
				'remote_name'    => {$remote_term_name},
				'local_taxonomy' => 'category',
				'local_term_id'  => \$term_id,
			),
		),
	),
);

\$session_id = UniversalImporter\Import\ImportSessionId::from_string( (string) \$decision['session_id'] );
\$page       = UniversalImporter\Admin\ImportAdminPage::from_globals();
\$snapshot   = \$page->resolve_import_decision( \$session_id->to_string(), (string) \$decision['decision_key'], \$answer );

if ( ! is_array( \$snapshot ) || ! empty( \$snapshot['pending_decisions'] ) ) {
	throw new Exception( 'The admin decision resolver returned an unexpected snapshot after resolving the REST relationship mapping decision.' );
}

echo 'UNIVERSAL_IMPORTER_REST_MAPPING_ADMIN_DECISION_RESOLVED session_id=' . \$session_id->to_string() . ' decision_key=' . (string) \$decision['decision_key'] . ' local_user_id=' . (int) \$user->ID . ' local_term_id=' . \$term_id . ' remote_author=' . {$author_name} . ' remote_slug=' . {$author_slug} . "\\n";
PHP;
	}

	/**
	 * Returns PHP code that verifies resolved REST relationship mapping.
	 *
	 * @return string PHP code.
	 */
	private function rest_relationship_mapping_assertion_php() {
		$marker      = var_export( self::REST_MAPPING_SMOKE_MARKER, true );
		$term_slug   = var_export( self::REST_LOCAL_TERM_SLUG, true );
		$taxonomy    = var_export( self::REST_REMOTE_TAXONOMY, true );
		$remote_slug = var_export( self::REST_REMOTE_TERM_SLUG, true );

		return <<<PHP
<?php
require_once '/wordpress/wp-load.php';

global \$wpdb;
\$tables = UniversalImporter\Import\WordPressImportSessionSchema::from_globals()->get_table_names();

if ( ! function_exists( 'get_posts' ) || ! function_exists( 'get_post_meta' ) || ! function_exists( 'wp_get_object_terms' ) || ! function_exists( 'get_term_by' ) ) {
	throw new Exception( 'WordPress post and taxonomy APIs are unavailable during REST relationship mapping smoke verification.' );
}

\$posts = get_posts(
	array(
		'post_type'              => 'page',
		'post_status'            => 'any',
		'posts_per_page'         => 10,
		'orderby'                => 'ID',
		'order'                  => 'DESC',
		'no_found_rows'          => true,
		'update_post_meta_cache' => true,
		'update_post_term_cache' => false,
		'meta_query'             => array(
			array(
				'key'   => '_universal_importer_document_format',
				'value' => 'wp-rest',
			),
		),
	)
);

\$rest_post = null;
foreach ( \$posts as \$post ) {
	\$remote_terms = get_post_meta( \$post->ID, '_universal_importer_remote_terms', true );
	if ( 'Packaged REST Smoke' === \$post->post_title && is_array( \$remote_terms ) && ! empty( \$remote_terms[ {$taxonomy} ][0]['slug'] ) ) {
		\$rest_post = \$post;
		break;
	}
}

if ( null === \$rest_post ) {
	throw new Exception( 'WP-CLI smoke import did not find the REST imported page while verifying resolved relationship mapping.' );
}

\$answer = get_post_meta( \$rest_post->ID, '_universal_importer_relationship_mapping_answer', true );
if ( ! is_array( \$answer ) || empty( \$answer['author']['local_user_id'] ) || empty( \$answer['terms'][ {$taxonomy} ][0]['local_term_id'] ) ) {
	throw new Exception( 'WP-CLI smoke import did not persist the resolved REST relationship mapping answer on the imported page.' );
}

\$local_user_id = (int) \$answer['author']['local_user_id'];
\$local_term_id = (int) \$answer['terms'][ {$taxonomy} ][0]['local_term_id'];
\$local_term    = get_term_by( 'slug', {$term_slug}, 'category' );

if ( (int) \$rest_post->post_author !== \$local_user_id ) {
	throw new Exception( 'WP-CLI smoke import did not apply the resolved REST author mapping to the imported page.' );
}

if ( false === \$local_term || (int) \$local_term->term_id !== \$local_term_id ) {
	throw new Exception( 'WP-CLI smoke import did not preserve the expected local REST mapping term.' );
}

\$assigned_terms = wp_get_object_terms(
	\$rest_post->ID,
	'category',
	array(
		'fields' => 'ids',
	)
);

if ( is_wp_error( \$assigned_terms ) ) {
	throw new Exception( 'Unable to read assigned terms for resolved REST relationship mapping: ' . \$assigned_terms->get_error_message() );
}

if ( ! in_array( \$local_term_id, array_map( 'intval', \$assigned_terms ), true ) ) {
	throw new Exception( 'WP-CLI smoke import did not assign the resolved REST term mapping to the imported page.' );
}

\$remote_terms = get_post_meta( \$rest_post->ID, '_universal_importer_remote_terms', true );
if ( ! is_array( \$remote_terms ) || empty( \$remote_terms[ {$taxonomy} ][0]['slug'] ) || {$remote_slug} !== (string) \$remote_terms[ {$taxonomy} ][0]['slug'] ) {
	throw new Exception( 'WP-CLI smoke import did not preserve remote REST term metadata for the mapping decision.' );
}

\$applied_events = (int) \$wpdb->get_var(
	\$wpdb->prepare(
		'SELECT COUNT(*) FROM `' . str_replace( '`', '``', \$tables['events'] ) . '` WHERE event_type = %s',
		'post.relationships_mapping_applied'
	)
);
if ( \$applied_events < 1 ) {
	throw new Exception( 'WP-CLI smoke import did not record a resolved REST relationship mapping applied event.' );
}

\$idempotency_records = (int) \$wpdb->get_var(
	\$wpdb->prepare(
		'SELECT COUNT(*) FROM `' . str_replace( '`', '``', \$tables['idempotency'] ) . '` WHERE resource_type = %s',
		'relationship-mapping'
	)
);
if ( \$idempotency_records < 1 ) {
	throw new Exception( 'WP-CLI smoke import did not record relationship mapping idempotency after applying the decision.' );
}

\$resolved_decisions = (int) \$wpdb->get_var(
	\$wpdb->prepare(
		'SELECT COUNT(*) FROM `' . str_replace( '`', '``', \$tables['decisions'] ) . '` WHERE status = %s AND decision_key LIKE %s',
		'resolved',
		'map-rest-relationships:%'
	)
);
if ( \$resolved_decisions < 1 ) {
	throw new Exception( 'WP-CLI smoke import did not persist the resolved REST relationship mapping decision.' );
}

\$admin_resolved_events = (int) \$wpdb->get_var(
	\$wpdb->prepare(
		'SELECT COUNT(*) FROM `' . str_replace( '`', '``', \$tables['events'] ) . '` WHERE event_type = %s AND message = %s',
		'decision.resolved',
		'Import decision was resolved from the WordPress admin page.'
	)
);
if ( \$admin_resolved_events < 1 ) {
	throw new Exception( 'WP-CLI smoke import did not resolve the REST relationship mapping decision through the admin decision surface.' );
}

echo {$marker} . ' rest_mapping_post_id=' . (int) \$rest_post->ID . ' rest_mapping_user_id=' . \$local_user_id . ' rest_mapping_term_id=' . \$local_term_id . "\\n";
PHP;
	}

	/**
	 * Returns PHP code that verifies a GitHub repository traversal smoke import.
	 *
	 * @return string PHP code.
	 */
	private function github_import_assertion_php() {
		$marker         = var_export( self::GITHUB_SMOKE_MARKER, true );
		$title          = var_export( self::GITHUB_SMOKE_TITLE, true );
		$body           = var_export( self::GITHUB_SMOKE_BODY, true );
		$root_title     = var_export( self::GITHUB_SMOKE_ROOT_TITLE, true );
		$internal_title = var_export( self::GITHUB_SMOKE_INTERNAL_TITLE, true );

		return <<<PHP
<?php
require_once '/wordpress/wp-load.php';

global \$wpdb;
\$tables = UniversalImporter\Import\WordPressImportSessionSchema::from_globals()->get_table_names();

if ( ! function_exists( 'get_posts' ) || ! function_exists( 'get_post_meta' ) ) {
	throw new Exception( 'WordPress post APIs are unavailable during GitHub import smoke verification.' );
}

\$posts = get_posts(
	array(
		'post_type'              => 'page',
		'post_status'            => 'any',
		'posts_per_page'         => 20,
		'orderby'                => 'ID',
		'order'                  => 'DESC',
		'no_found_rows'          => true,
		'update_post_meta_cache' => true,
		'update_post_term_cache' => false,
		'meta_query'             => array(
			array(
				'key'   => '_universal_importer_document_format',
				'value' => 'markdown',
			),
		),
	)
);

\$github_post = null;
\$titles      = array();
foreach ( \$posts as \$post ) {
	\$titles[] = \$post->post_title;

	if (
		{$title} === \$post->post_title
		&& false !== strpos( \$post->post_content, '<h1>' . {$title} . '</h1>' )
		&& false !== strpos( \$post->post_content, {$body} )
		&& '' !== (string) get_post_meta( \$post->ID, '_universal_importer_session_id', true )
		&& false !== strpos( (string) get_post_meta( \$post->ID, '_universal_importer_source_item_key', true ), 'zip:' )
	) {
		\$github_post = \$post;
		break;
	}
}

if ( null === \$github_post ) {
	throw new Exception( 'WP-CLI smoke import did not persist the expected imported Markdown page from the GitHub repository subtree. Imported titles found: ' . ( empty( \$titles ) ? 'none' : implode( ', ', \$titles ) ) . '.' );
}

\$session_id = (string) get_post_meta( \$github_post->ID, '_universal_importer_session_id', true );
foreach ( \$posts as \$post ) {
	if ( \$session_id !== (string) get_post_meta( \$post->ID, '_universal_importer_session_id', true ) ) {
		continue;
	}

	if ( {$root_title} === \$post->post_title || {$internal_title} === \$post->post_title ) {
		throw new Exception( 'WP-CLI smoke import included a GitHub repository sibling outside the requested subtree: ' . \$post->post_title );
	}
}

\$github_archive_rows = \$wpdb->get_results(
	\$wpdb->prepare(
		'SELECT metadata_json FROM `' . str_replace( '`', '``', \$tables['source_items'] ) . '` WHERE session_id = %s',
		\$session_id
	),
	ARRAY_A
);
\$found_github_archive_metadata = false;
foreach ( is_array( \$github_archive_rows ) ? \$github_archive_rows : array() as \$row ) {
	\$metadata = isset( \$row['metadata_json'] ) ? json_decode( (string) \$row['metadata_json'], true ) : null;
	if (
		is_array( \$metadata )
		&& 'example' === ( isset( \$metadata['github_owner'] ) ? (string) \$metadata['github_owner'] : '' )
		&& 'repository' === ( isset( \$metadata['github_repository'] ) ? (string) \$metadata['github_repository'] : '' )
		&& 'main' === ( isset( \$metadata['github_ref'] ) ? (string) \$metadata['github_ref'] : '' )
		&& 'main/docs' === ( isset( \$metadata['github_requested_ref'] ) ? (string) \$metadata['github_requested_ref'] : '' )
		&& 'docs' === ( isset( \$metadata['github_source_path'] ) ? (string) \$metadata['github_source_path'] : '' )
		&& 'docs' === ( isset( \$metadata['archive_entry_prefix'] ) ? (string) \$metadata['archive_entry_prefix'] : '' )
	) {
		\$found_github_archive_metadata = true;
		break;
	}
}
if ( ! \$found_github_archive_metadata ) {
	throw new Exception( 'WP-CLI smoke import did not preserve GitHub subtree metadata on the downloaded archive source item.' );
}

\$download_events = (int) \$wpdb->get_var(
	\$wpdb->prepare(
		'SELECT COUNT(*) FROM `' . str_replace( '`', '``', \$tables['events'] ) . '` WHERE session_id = %s AND event_type = %s',
		\$session_id,
		'github.archive_downloaded'
	)
);
if ( \$download_events < 1 ) {
	throw new Exception( 'WP-CLI smoke import did not record a GitHub archive download event.' );
}

\$archive_events = (int) \$wpdb->get_var(
	\$wpdb->prepare(
		'SELECT COUNT(*) FROM `' . str_replace( '`', '``', \$tables['events'] ) . '` WHERE session_id = %s AND event_type = %s',
		\$session_id,
		'archive.expanded'
	)
);
if ( \$archive_events < 1 ) {
	throw new Exception( 'WP-CLI smoke import did not expand the downloaded GitHub archive.' );
}

echo {$marker} . ' github_post_id=' . (int) \$github_post->ID . ' github_session_id=' . \$session_id . "\\n";
PHP;
	}

	/**
	 * Returns PHP code that creates a browser-upload smoke session through the admin API.
	 *
	 * @return string PHP code.
	 */
	private function browser_upload_setup_php() {
		$title  = var_export( self::BROWSER_UPLOAD_SMOKE_TITLE, true );
		$body   = var_export( self::BROWSER_UPLOAD_SMOKE_BODY, true );
		$option = var_export( self::BROWSER_UPLOAD_SESSION_OPT, true );

		return <<<PHP
<?php
require_once '/wordpress/wp-load.php';

if ( ! class_exists( 'UniversalImporter\Admin\ImportAdminPage' ) ) {
	throw new Exception( 'Packaged ImportAdminPage class is unavailable during browser-upload smoke setup.' );
}

\$root = rtrim( sys_get_temp_dir(), DIRECTORY_SEPARATOR ) . DIRECTORY_SEPARATOR . 'universal-importer-browser-upload-smoke';
if ( ! is_dir( \$root ) && ! wp_mkdir_p( \$root ) ) {
	throw new Exception( 'Unable to create browser-upload smoke temp directory.' );
}

\$tmp = \$root . DIRECTORY_SEPARATOR . 'chapter.md';
if ( false === file_put_contents( \$tmp, '# ' . {$title} . "\\n\\n" . {$body} . "\\n" ) ) {
	throw new Exception( 'Unable to write browser-upload smoke temp file.' );
}

\$page     = UniversalImporter\Admin\ImportAdminPage::from_globals();
\$snapshot = \$page->create_import_session_from_uploaded_files(
	array(
		array(
			'name'     => 'chapter.md',
			'tmp_name' => \$tmp,
			'size'     => filesize( \$tmp ),
			'error'    => UPLOAD_ERR_OK,
		),
	),
	array( 'uploaded-folder/chapter.md' ),
	array(),
	false
);

if ( empty( \$snapshot['id'] ) ) {
	throw new Exception( 'Browser-upload smoke setup did not create an import session.' );
}

update_option( {$option}, (string) \$snapshot['id'], false );
echo 'UNIVERSAL_IMPORTER_BROWSER_UPLOAD_SESSION_CREATED session_id=' . (string) \$snapshot['id'] . "\\n";
PHP;
	}

	/**
	 * Returns PHP code that verifies a browser-upload smoke import.
	 *
	 * @return string PHP code.
	 */
	private function browser_upload_assertion_php() {
		$marker = var_export( self::BROWSER_UPLOAD_SMOKE_MARKER, true );
		$title  = var_export( self::BROWSER_UPLOAD_SMOKE_TITLE, true );
		$body   = var_export( self::BROWSER_UPLOAD_SMOKE_BODY, true );
		$option = var_export( self::BROWSER_UPLOAD_SESSION_OPT, true );

		return <<<PHP
<?php
require_once '/wordpress/wp-load.php';

global \$wpdb;
\$tables     = UniversalImporter\Import\WordPressImportSessionSchema::from_globals()->get_table_names();
\$session_id = (string) get_option( {$option}, '' );

if ( '' === \$session_id ) {
	throw new Exception( 'Browser-upload smoke session option was not set.' );
}

if ( ! function_exists( 'get_posts' ) || ! function_exists( 'get_post_meta' ) ) {
	throw new Exception( 'WordPress post APIs are unavailable during browser-upload smoke verification.' );
}

\$posts = get_posts(
	array(
		'post_type'              => 'page',
		'post_status'            => 'any',
		'posts_per_page'         => 20,
		'orderby'                => 'ID',
		'order'                  => 'DESC',
		'no_found_rows'          => true,
		'update_post_meta_cache' => true,
		'update_post_term_cache' => false,
		'meta_query'             => array(
			array(
				'key'   => '_universal_importer_session_id',
				'value' => \$session_id,
			),
		),
	)
);

\$browser_post = null;
\$titles       = array();
foreach ( \$posts as \$post ) {
	\$titles[] = \$post->post_title;

	if (
		{$title} === \$post->post_title
		&& false !== strpos( \$post->post_content, '<h1>' . {$title} . '</h1>' )
		&& false !== strpos( \$post->post_content, {$body} )
		&& 'markdown' === (string) get_post_meta( \$post->ID, '_universal_importer_document_format', true )
	) {
		\$browser_post = \$post;
		break;
	}
}

if ( null === \$browser_post ) {
	throw new Exception( 'Admin browser-upload smoke did not persist the expected imported Markdown page. Imported titles for session ' . \$session_id . ': ' . ( empty( \$titles ) ? 'none' : implode( ', ', \$titles ) ) . '.' );
}

\$session_row = \$wpdb->get_row(
	\$wpdb->prepare(
		'SELECT source,status FROM `' . str_replace( '`', '``', \$tables['sessions'] ) . '` WHERE id = %s',
		\$session_id
	),
	ARRAY_A
);

if ( ! is_array( \$session_row ) || 'done' !== (string) \$session_row['status'] || false === strpos( (string) \$session_row['source'], '/browser-uploads/' . \$session_id . '/tree' ) ) {
	throw new Exception( 'Admin browser-upload smoke session did not finish from the managed browser upload cache. Session row: ' . wp_json_encode( \$session_row ) );
}

\$created_events = \$wpdb->get_results(
	\$wpdb->prepare(
		'SELECT context_json FROM `' . str_replace( '`', '``', \$tables['events'] ) . '` WHERE session_id = %s AND event_type = %s',
		\$session_id,
		'session.created'
	),
	ARRAY_A
);

\$saw_upload_context = false;
foreach ( \$created_events as \$event ) {
	\$context = json_decode( (string) \$event['context_json'], true );
	if ( is_array( \$context ) && 1 === (int) \$context['upload_files'] && ! empty( \$context['upload_bytes'] ) && false !== strpos( (string) \$context['source'], '/browser-uploads/' . \$session_id . '/tree' ) ) {
		\$saw_upload_context = true;
		break;
	}
}

if ( ! \$saw_upload_context ) {
	throw new Exception( 'Admin browser-upload smoke did not record browser upload session.created metadata.' );
}

echo {$marker} . ' session_id=' . \$session_id . ' post_id=' . (int) \$browser_post->ID . "\\n";
PHP;
	}

	/**
	 * Returns the sample Markdown content imported by release smokes.
	 *
	 * @return string Markdown.
	 */
	private function import_smoke_markdown() {
		return '# ' . self::IMPORT_SMOKE_TITLE . "\n\n" . self::IMPORT_SMOKE_BODY . "\n\n" . '![Smoke image](' . self::IMPORT_SMOKE_IMAGE . ')' . "\n";
	}

	/**
	 * Returns the sample HTML content imported by release smokes.
	 *
	 * @return string HTML.
	 */
	private function html_smoke_document() {
		return '<html><head><title>' . self::HTML_SMOKE_TITLE . '</title></head><body>'
			. '<main><h1>' . self::HTML_SMOKE_TITLE . '</h1>'
			. '<script>alert("html smoke")</script>'
			. '<section><p>' . self::HTML_SMOKE_BODY . '</p>'
			. '<ul><li>Structured HTML list item</li></ul>'
			. '<ol id="html-smoke-ordered-list" start="4" reversed type="A"><li id="html-smoke-step-four" value="4">HTML smoke fourth step</li><li>HTML smoke third step</li></ol>'
			. '<figure id="html-smoke-figure-image"><img src="' . self::HTML_SMOKE_IMAGE . '" alt="HTML smoke image">'
			. '<figcaption>HTML figure caption.</figcaption></figure>'
			. '<figure><a href="/html-linked-image" target="_blank" rel="noopener"><img src="' . self::HTML_SMOKE_IMAGE . '" alt="Linked HTML smoke image"></a>'
			. '<figcaption>HTML linked image caption.</figcaption></figure>'
			. '[caption id="html-smoke-caption" align="aligncenter" width="640"]<img class="size-large" src="' . self::HTML_SMOKE_IMAGE . '" alt="HTML smoke caption image">HTML smoke classic caption.[/caption]'
			. '<picture id="html-smoke-picture" class="alignwide"><source media="(min-width: 800px)" srcset="' . self::HTML_SMOKE_IMAGE . '"><img class="size-large" src="' . self::HTML_SMOKE_IMAGE . '" alt="HTML smoke responsive image"></picture>'
			. '<a href="/html-smoke-picture-full"><picture id="html-smoke-linked-picture" class="aligncenter"><source srcset="' . self::HTML_SMOKE_IMAGE . '"><img class="size-medium" src="' . self::HTML_SMOKE_IMAGE . '" alt="HTML smoke linked responsive image"></picture></a>'
			. '<figure id="html-smoke-table" class="wp-block-table alignwide is-style-stripes"><table class="has-fixed-layout"><thead><tr><th>Name</th><th>Total</th></tr></thead>'
			. '<tbody><tr><td>Alpha</td><td>2</td></tr></tbody></table>'
			. '<figcaption>HTML table caption.</figcaption></figure>'
			. '<hr id="html-smoke-separator" class="wp-block-separator alignwide is-style-dots">'
			. '<pre id="html-smoke-code"><code>&lt;?php echo "HTML smoke";</code></pre>'
			. '<pre id="html-smoke-preformatted">HTML smoke preformatted line.</pre>'
			. '<p id="html-smoke-gallery-shortcode">[gallery ids="10,11"]</p>'
			. '<!--more Keep reading smoke--><!--noteaser-->'
			. '<p>HTML smoke content after the more marker.</p>'
			. '<!--nextpage-->'
			. '<p>HTML smoke second page content.</p>'
			. '<pre id="html-smoke-verse" class="poem">HTML smoke verse line one'
			. "\n  HTML smoke verse line two</pre>"
			. '<div id="html-smoke-gallery" class="gallery gallery-columns-2 gallery-size-thumbnail alignwide"><figure><a href="/html-gallery-large"><img src="' . self::HTML_SMOKE_IMAGE . '" alt="Gallery one"></a>'
			. '<figcaption>Gallery image one.</figcaption></figure>'
			. '<figure><img src="' . self::HTML_SMOKE_IMAGE . '" alt="Gallery two">'
			. '<figcaption>Gallery image two.</figcaption></figure></div>'
			. '<figure id="html-smoke-video"><video controls poster="' . self::HTML_SMOKE_IMAGE . '" src="media/html-smoke.mp4"></video>'
			. '<figcaption>HTML video caption.</figcaption></figure>'
			. '<figure><audio id="html-smoke-audio" controls src="media/html-smoke.mp3"></audio>'
			. '<figcaption>HTML audio caption.</figcaption></figure>'
			. '<p id="html-smoke-video-shortcode" class="aligncenter">[video mp4="media/html-shortcode.mp4" poster="' . self::HTML_SMOKE_IMAGE . '" preload="metadata" loop="true"]</p>'
			. '<p id="html-smoke-audio-shortcode" class="alignwide">[audio mp3="media/html-shortcode.mp3" preload="none"]</p>'
			. '<div id="html-smoke-wrapper-video" class="wp-video aligncenter" style="width:640px"><video class="wp-video-shortcode" preload="metadata" controls="controls">'
			. '<source type="video/mp4" src="media/html-wrapper.mp4"><a href="media/html-wrapper.mp4">media/html-wrapper.mp4</a></video></div>'
			. '<div id="html-smoke-wrapper-audio" class="wp-audio alignwide"><audio class="wp-audio-shortcode" preload="none" controls="controls">'
			. '<source type="audio/mpeg" src="media/html-wrapper.mp3"><a href="media/html-wrapper.mp3">media/html-wrapper.mp3</a></audio>'
			. '<p class="wp-caption-text">HTML wrapper audio caption.</p></div>'
			. '<p>[embed]https://www.youtube.com/embed/dQw4w9WgXcQ[/embed]</p>'
			. '<p id="html-smoke-vimeo-embed" class="alignwide"><a href="https://vimeo.com/12345">https://vimeo.com/12345</a></p>'
			. '<section id="html-smoke-media-text" class="media-text alignfull"><figure><img src="' . self::HTML_SMOKE_IMAGE . '" alt="HTML media text image"></figure>'
			. '<div class="media-copy"><h2>HTML smoke media text heading</h2><p>HTML smoke media text copy.</p><a class="button" href="/html-media-text-cta">HTML media text CTA</a></div></section>'
			. '<ul id="html-smoke-social" class="social-links show-labels aligncenter">'
			. '<li><a href="https://github.com/wordpress" target="_blank" rel="me noopener">GitHub</a></li>'
			. '<li><a href="https://www.youtube.com/wordpress" target="_blank">YouTube</a></li>'
			. '<li><a href="mailto:hello@example.test" target="_blank">Email</a></li></ul>'
			. '<nav id="html-smoke-nav" class="alignwide" aria-label="HTML smoke navigation"><ul>'
			. '<li><a href="/html-nav-home">HTML smoke home</a></li>'
			. '<li><a href="/html-nav-services">HTML smoke services</a><ul>'
			. '<li><a href="/html-nav-imports">HTML smoke imports</a></li>'
			. '<li><a href="/html-nav-recovery">HTML smoke recovery</a></li>'
			. '</ul></li></ul></nav>'
			. '<form id="html-smoke-search" class="search-form alignright" role="search" method="get" action="/">'
			. '<label class="screen-reader-text" for="html-smoke-search-field">HTML smoke search</label>'
			. '<input id="html-smoke-search-field" type="search" name="s" placeholder="Search smoke content">'
			. '<button type="submit" class="search-submit"><span class="screen-reader-text">Search</span><svg viewBox="0 0 20 20"></svg></button></form>'
			. '<div id="html-smoke-spacer" class="spacer" style="height: 36px"></div>'
			. '<details id="html-faq" class="alignfull" name="html-smoke-faq" open><summary>HTML smoke FAQ</summary>'
			. '<p>Details content stays structured.</p></details>'
			. '<dl class="faq-list alignwide"><dt id="html-definition-faq-one">Can definition lists become Details?</dt>'
			. '<dd>HTML definition-list answer with <strong>inline HTML</strong>.</dd>'
			. '<dt>Can definition-list answers include blocks?</dt>'
			. '<dd><p>HTML definition-list block answer.</p><ul><li>HTML definition-list answer item.</li></ul></dd></dl>'
			. '<section id="html-smoke-hero" class="hero hero-large alignwide" style="background-image: url(\'' . self::HTML_SMOKE_IMAGE . '\')">'
			. '<div class="hero-content"><h2>HTML smoke hero heading</h2><p>HTML smoke hero copy.</p><a class="button" href="/html-hero-cta">HTML hero CTA</a></div></section>'
			. '<div id="html-legacy-accordion" class="accordion alignfull">'
			. '<div id="html-legacy-accordion-one" class="accordion-item active"><h3 class="accordion-header"><button aria-expanded="true">Legacy accordion one</button></h3>'
			. '<div class="accordion-collapse collapse show"><div class="accordion-body"><p>Legacy accordion first answer.</p></div></div></div>'
			. '<div id="html-legacy-accordion-two" class="accordion-item alignwide"><h3 class="accordion-header"><button>Legacy accordion two</button></h3>'
			. '<div class="accordion-collapse collapse"><div class="accordion-body"><p>Legacy accordion second answer.</p></div></div></div>'
			. '</div>'
			. '<div id="html-smoke-columns" class="row alignwide"><div id="html-smoke-left-column" class="col-md-6"><p>HTML left column copy.</p></div>'
			. '<div id="html-smoke-right-column" class="col-md-6"><ul><li>HTML right column item</li></ul></div></div>'
			. '<ol id="html-smoke-timeline" class="timeline alignwide">'
			. '<li id="html-smoke-timeline-one"><time datetime="2026-01">Q1 2026</time><h3>HTML smoke timeline one</h3><p>HTML smoke timeline first item.</p></li>'
			. '<li><time>Q2</time><h3>HTML smoke timeline two</h3><p>HTML smoke timeline second item.</p></li></ol>'
			. '<div class="steps alignfull"><div id="html-smoke-step-review" class="step"><h3>Review step</h3><p>HTML smoke review step.</p></div>'
			. '<div class="step"><h3>Publish step</h3><p>HTML smoke publish step.</p></div></div>'
			. '<blockquote id="html-smoke-pullquote" class="pullquote has-text-align-center"><p>HTML pullquote smoke.</p><cite>HTML Citation</cite></blockquote>'
			. '<figure id="html-smoke-figure-quote" class="quote" style="text-align: right"><blockquote><p>HTML smoke figure quote.</p></blockquote><figcaption>HTML smoke quoted source</figcaption></figure>'
			. '<iframe src="/html-smoke-frame" title="HTML smoke frame"></iframe>'
			. '<form action="/html-smoke-form" method="post" onsubmit="alert(\'x\')"><label>Email <input type="email" name="email" onclick="alert(\'x\')"></label><span style="background-image:url(data:image/svg+xml,%3Csvg%20onload%3Dalert(1)%3E%3C/svg%3E)">Unsafe SVG background</span><object data="data:image/svg+xml,%3Csvg%20onload%3Dalert(1)%3E%3C/svg%3E">Unsafe object fallback</object><img src="/html-smoke-safe.jpg" srcset="/html-smoke-safe.jpg 1x, data:image/svg+xml,%3Csvg%20onload%3Dalert(1)%3E%3C/svg%3E 2x" alt="Unsafe srcset image"><video src="/html-smoke-safe.mp4" poster="data:image/svg+xml,%3Csvg%20onload%3Dalert(1)%3E%3C/svg%3E"></video><span background="javascript:alert(1)">Unsafe background attribute</span><style>.html-smoke-unsafe{background:url(javascript:alert(1))}</style><button>Join list</button></form>'
			. '<div class="tabs"><ul class="nav-tabs" role="tablist"><li><a class="nav-link active" role="tab" href="#html-tab-one" onclick="alert(\'x\')">HTML smoke tab one</a></li>'
			. '<li><a class="nav-link" role="tab" href="#html-tab-two">HTML smoke tab two</a></li></ul>'
			. '<div class="tab-content"><div id="html-tab-one" class="tab-pane active" role="tabpanel"><p>HTML smoke first tab panel.</p></div>'
			. '<div id="html-tab-two" class="tab-pane" role="tabpanel"><p>HTML smoke second tab panel.</p></div></div></div>'
			. '<aside id="html-smoke-callout" class="callout warning alignwide"><h2>HTML smoke callout</h2><p>HTML smoke warning callout.</p></aside>'
			. '<div class="feature-card alignfull"><h2>HTML smoke card</h2><p>HTML smoke feature card.</p></div>'
			. '<section class="pricing-grid"><article id="html-smoke-starter-plan" class="pricing-plan alignwide"><h2>Starter plan</h2><p>HTML smoke starter plan.</p><a class="button" href="/html-smoke-starter">Choose starter</a></article>'
			. '<article class="pricing-plan"><h2>Pro plan</h2><p>HTML smoke pro plan.</p><a role="button" href="/html-smoke-pro">Choose pro</a></article></section>'
			. '<p id="html-smoke-button-row" class="alignwide"><a class="button primary" href="/html-smoke-cta">HTML button CTA</a></p>'
			. '<p id="html-smoke-file-row" class="aligncenter"><a href="documents/document-smoke.pdf" target="_blank">HTML file download</a></p>'
			. '<h2 id="html-smoke-centered-heading" style="text-align: center">HTML smoke centered heading</h2>'
			. '<p id="html-smoke-centered-paragraph" style="text-align: center">HTML smoke centered paragraph.</p>'
			. '<p>Inline HTML media intro <img src="' . self::HTML_SMOKE_IMAGE . '" alt="Inline smoke image"> Inline HTML media outro <a class="button" href="/html-inline-action">HTML inline action</a></p>'
			. '</section></main>'
			. '</body></html>';
	}

	/**
	 * Returns the sample legacy widget HTML content imported by release smokes.
	 *
	 * @return string HTML.
	 */
	private function html_widget_smoke_document() {
		return '<html><head><title>' . self::HTML_WIDGET_SMOKE_TITLE . '</title></head><body>'
			. '<main><h1>' . self::HTML_WIDGET_SMOKE_TITLE . '</h1>'
			. '<p>' . self::HTML_WIDGET_SMOKE_BODY . '</p>'
			. '<aside class="widget widget_media_image" id="html-smoke-widget-image"><h2 class="widget-title">HTML smoke legacy image widget</h2>'
			. '<a href="/html-widget-image"><img src="' . self::HTML_SMOKE_IMAGE . '" alt="HTML smoke widget image"></a>'
			. '<p class="wp-caption-text">HTML smoke widget caption.</p></aside>'
			. '<section class="widget widget_search" id="html-smoke-widget-search"><h2 class="widget-title">HTML smoke legacy search widget</h2>'
			. '<form role="search" method="get" action="/html-widget-search"><label>Find smoke content</label><input type="search" name="s"><button type="submit">Search widgets</button></form></section>'
			. '<p>HTML smoke widget body.</p>'
			. '</main></body></html>';
	}

	/**
	 * Returns the sample archive Markdown content imported by release smokes.
	 *
	 * @return string Markdown.
	 */
	private function archive_smoke_markdown() {
		return '# ' . self::ARCHIVE_SMOKE_TITLE . "\n\n" . self::ARCHIVE_SMOKE_BODY . "\n";
	}

	/**
	 * Returns the sample GitHub subtree Markdown content imported by release smokes.
	 *
	 * @return string Markdown.
	 */
	private function github_smoke_markdown() {
		return '# ' . self::GITHUB_SMOKE_TITLE . "\n\n" . self::GITHUB_SMOKE_BODY . "\n";
	}

	/**
	 * Returns the sample GitHub root Markdown content that must not be imported.
	 *
	 * @return string Markdown.
	 */
	private function github_smoke_root_markdown() {
		return '# ' . self::GITHUB_SMOKE_ROOT_TITLE . "\n\nThis root repository file is outside the requested docs subtree.\n";
	}

	/**
	 * Returns the sample GitHub internal Markdown content that must not be imported.
	 *
	 * @return string Markdown.
	 */
	private function github_smoke_internal_markdown() {
		return '# ' . self::GITHUB_SMOKE_INTERNAL_TITLE . "\n\nThis internal repository file is outside the requested docs subtree.\n";
	}

	/**
	 * Returns the deterministic GitHub zipball entries used by release smokes.
	 *
	 * @return array<string,string> Archive entries keyed by path.
	 */
	private function github_smoke_archive_entries() {
		return array(
			'example-repository-main/README.md'      => $this->github_smoke_root_markdown(),
			'example-repository-main/docs/page.md'  => $this->github_smoke_markdown(),
			'example-repository-main/src/notes.md'  => $this->github_smoke_internal_markdown(),
			'example-repository-main/docs/raw.json' => '{"ignored":true}',
		);
	}

	/**
	 * Writes the deterministic GitHub zipball fixture.
	 *
	 * @param string $target_path Archive target path.
	 */
	private function create_github_smoke_archive( $target_path ) {
		if ( ! class_exists( ZipArchive::class ) ) {
			throw new RuntimeException( 'The PHP zip extension is required for the GitHub repository smoke fixture.' );
		}

		$this->ensure_directory( dirname( $target_path ) );

		$zip = new ZipArchive();
		if ( true !== $zip->open( $target_path, ZipArchive::CREATE | ZipArchive::OVERWRITE ) ) {
			throw new RuntimeException( 'Unable to create local GitHub repository smoke archive fixture.' );
		}

		try {
			foreach ( $this->github_smoke_archive_entries() as $entry => $content ) {
				if ( ! $zip->addFromString( $entry, $content ) ) {
					throw new RuntimeException( 'Unable to write entry into GitHub repository smoke archive fixture: ' . $entry );
				}
			}
		} finally {
			$zip->close();
		}
	}

	/**
	 * Returns the sample WXR content imported by release smokes.
	 *
	 * @return string WXR XML.
	 */
	private function wxr_smoke_export() {
		$content = '<!-- wp:paragraph --><p>' . self::WXR_SMOKE_BODY . '</p><!-- /wp:paragraph --><script>alert("x")</script>';

		return '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
			. '<rss version="2.0"'
			. ' xmlns:content="http://purl.org/rss/1.0/modules/content/"'
			. ' xmlns:dc="http://purl.org/dc/elements/1.1/"'
			. ' xmlns:wp="http://wordpress.org/export/1.2/">'
			. "\n<channel>\n<title>Packaged WXR Export</title>\n"
			. "<wp:wxr_version>1.2</wp:wxr_version>\n"
			. "<item>\n"
			. '<title>' . htmlspecialchars( self::WXR_SMOKE_TITLE, ENT_XML1, 'UTF-8' ) . "</title>\n"
			. "<link>https://source.example.test/?p=91</link>\n"
			. "<pubDate>Wed, 05 Jun 2024 16:04:48 +0000</pubDate>\n"
			. "<dc:creator><![CDATA[admin]]></dc:creator>\n"
			. "<guid isPermaLink=\"false\">https://source.example.test/?p=91</guid>\n"
			. "<description></description>\n"
			. '<content:encoded><![CDATA[' . $content . "]]></content:encoded>\n"
			. "<wp:post_id>91</wp:post_id>\n"
			. "<wp:post_date>2024-06-05 16:04:48</wp:post_date>\n"
			. "<wp:status>publish</wp:status>\n"
			. "<wp:post_name>packaged-wxr-smoke</wp:post_name>\n"
			. "<wp:post_type>post</wp:post_type>\n"
			. "</item>\n"
			. "</channel>\n</rss>\n";
	}

	/**
	 * Returns the sample EPUB archive entries imported by release smokes.
	 *
	 * @return array<string,string> EPUB zip entries.
	 */
	private function epub_smoke_entries() {
		$title       = htmlspecialchars( self::EPUB_SMOKE_TITLE, ENT_XML1, 'UTF-8' );
		$chapter_1   = htmlspecialchars( self::EPUB_SMOKE_CHAPTER_ONE, ENT_XML1, 'UTF-8' );
		$chapter_2   = htmlspecialchars( self::EPUB_SMOKE_CHAPTER_TWO, ENT_XML1, 'UTF-8' );
		$body        = htmlspecialchars( self::EPUB_SMOKE_BODY, ENT_XML1, 'UTF-8' );
		$second_body = htmlspecialchars( 'A packaged plugin EPUB second chapter.', ENT_XML1, 'UTF-8' );

		return array(
			'mimetype'                => 'application/epub+zip',
			'META-INF/container.xml'  => '<?xml version="1.0" encoding="UTF-8"?>'
				. '<container version="1.0" xmlns="urn:oasis:names:tc:opendocument:xmlns:container">'
				. '<rootfiles><rootfile full-path="OEBPS/content.opf" media-type="application/oebps-package+xml"/></rootfiles>'
				. '</container>',
			'OEBPS/content.opf'       => '<?xml version="1.0" encoding="UTF-8"?>'
				. '<package version="3.0" xmlns="http://www.idpf.org/2007/opf" unique-identifier="book-id">'
				. '<metadata xmlns:dc="http://purl.org/dc/elements/1.1/"><dc:title>' . $title . '</dc:title></metadata>'
				. '<manifest>'
				. '<item id="chapter-one" href="chapter-one.xhtml" media-type="application/xhtml+xml"/>'
				. '<item id="chapter-two" href="chapter-two.xhtml" media-type="application/xhtml+xml"/>'
				. '<item id="nav" href="nav.xhtml" media-type="application/xhtml+xml" properties="nav"/>'
				. '</manifest>'
				. '<spine><itemref idref="chapter-one"/><itemref idref="chapter-two"/></spine>'
				. '</package>',
			'OEBPS/nav.xhtml'         => '<?xml version="1.0" encoding="UTF-8"?>'
				. '<html xmlns="http://www.w3.org/1999/xhtml" xmlns:epub="http://www.idpf.org/2007/ops"><body>'
				. '<nav epub:type="toc"><ol>'
				. '<li><a href="chapter-one.xhtml">' . $chapter_1 . '</a></li>'
				. '<li><a href="chapter-two.xhtml#part">' . $chapter_2 . '</a></li>'
				. '</ol></nav>'
				. '</body></html>',
			'OEBPS/chapter-one.xhtml' => '<?xml version="1.0" encoding="UTF-8"?>'
				. '<html xmlns="http://www.w3.org/1999/xhtml"><head><title>' . $chapter_1 . '</title></head><body>'
				. '<h1>' . $chapter_1 . '</h1>'
				. '<p>' . $body . '</p>'
				. '<p><a href="chapter-two.xhtml#part">Continue to chapter two</a></p>'
				. '<script>alert("epub")</script>'
				. '</body></html>',
			'OEBPS/chapter-two.xhtml' => '<?xml version="1.0" encoding="UTF-8"?>'
				. '<html xmlns="http://www.w3.org/1999/xhtml"><head><title>' . $chapter_2 . '</title></head><body>'
				. '<h1 id="part">' . $chapter_2 . '</h1>'
				. '<p>' . $second_body . '</p>'
				. '</body></html>',
		);
	}

	/**
	 * Returns the sample PDF bytes imported by release smokes.
	 *
	 * @return string PDF bytes.
	 */
	private function pdf_smoke_document() {
		$content_stream = "BT\n/F1 12 Tf\n72 720 Td\n(# " . self::PDF_SMOKE_TITLE . "\\n\\n" . self::PDF_SMOKE_BODY . ") Tj\nET\nq 1 0 0 1 0 0 cm /Im1 Do Q";
		$image = base64_decode( $this->pdf_smoke_jpeg_base64(), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Release smoke embeds a tiny binary JPEG fixture in a generated PDF.

		if ( ! is_string( $image ) ) {
			throw new RuntimeException( 'Unable to decode PDF smoke JPEG fixture.' );
		}

		return "%PDF-1.4\n"
			. "1 0 obj << /Type /Catalog /Pages 2 0 R >> endobj\n"
			. "2 0 obj << /Type /Pages /Kids [3 0 R] /Count 1 >> endobj\n"
			. "3 0 obj << /Type /Page /Parent 2 0 R /Resources << /XObject << /Im1 5 0 R >> >> /Contents 4 0 R >> endobj\n"
			. '4 0 obj << /Length ' . strlen( $content_stream ) . " >>\nstream\n"
			. $content_stream
			. "\nendstream\nendobj\n"
			. '5 0 obj << /Type /XObject /Subtype /Image /Width 1 /Height 1 /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /DCTDecode /Length ' . strlen( $image ) . " >>\nstream\n"
			. $image
			. "\nendstream\nendobj\n%%EOF\n";
	}

	/**
	 * Returns the sample PDF bytes with an unsupported embedded image filter.
	 *
	 * @return string PDF bytes.
	 */
	private function unsupported_pdf_smoke_document() {
		$content_stream = "BT\n/F1 12 Tf\n72 720 Td\n(# " . self::UNSUPPORTED_PDF_SMOKE_TITLE . "\\n\\n" . self::UNSUPPORTED_PDF_SMOKE_BODY . ") Tj\nET\nq 1 0 0 1 0 0 cm /Im1 Do Q";
		$image          = 'not-a-real-jpx-stream';

		return "%PDF-1.4\n"
			. "1 0 obj << /Type /Catalog /Pages 2 0 R >> endobj\n"
			. "2 0 obj << /Type /Pages /Kids [3 0 R] /Count 1 >> endobj\n"
			. "3 0 obj << /Type /Page /Parent 2 0 R /Resources << /XObject << /Im1 5 0 R >> >> /Contents 4 0 R >> endobj\n"
			. '4 0 obj << /Length ' . strlen( $content_stream ) . " >>\nstream\n"
			. $content_stream
			. "\nendstream\nendobj\n"
			. '5 0 obj << /Type /XObject /Subtype /Image /Width 1 /Height 1 /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /JPXDecode /Length ' . strlen( $image ) . " >>\nstream\n"
			. $image
			. "\nendstream\nendobj\n%%EOF\n";
	}

	/**
	 * Returns the sample embedded PDF JPEG fixture as base64.
	 *
	 * @return string Base64-encoded JPEG bytes.
	 */
	private function pdf_smoke_jpeg_base64() {
		return '/9j/4AAQSkZJRgABAQAAAQABAAD/2wBDAP//////////////////////////////////////////////////////////////////////////////////////2wBDAf//////////////////////////////////////////////////////////////////////////////////////wAARCAABAAEDASIAAhEBAxEB/8QAFQABAQAAAAAAAAAAAAAAAAAAAAX/xAAUEAEAAAAAAAAAAAAAAAAAAAAA/9oADAMBAAIQAxAAAAH/xAAUEAEAAAAAAAAAAAAAAAAAAAAA/9oACAEBAAEFAqf/xAAUEQEAAAAAAAAAAAAAAAAAAAAA/9oACAEDAQE/ASP/xAAUEQEAAAAAAAAAAAAAAAAAAAAA/9oACAECAQE/ASP/xAAUEAEAAAAAAAAAAAAAAAAAAAAA/9oACAEBAAY/Ar//xAAUEAEAAAAAAAAAAAAAAAAAAAAA/9oACAEBAAE/IV//2gAMAwEAAgADAAAAEP/EABQRAQAAAAAAAAAAAAAAAAAAABD/2gAIAQMBAT8QH//EABQRAQAAAAAAAAAAAAAAAAAAABD/2gAIAQIBAT8QH//EABQQAQAAAAAAAAAAAAAAAAAAABD/2gAIAQEAAT8QH//Z';
	}

	/**
	 * Returns a textless PDF fixture whose content must come from the helper.
	 *
	 * @return string PDF bytes.
	 */
	private function external_pdf_smoke_document() {
		$content_stream = "q 10 0 0 10 0 0 cm /Im1 Do Q";

		return "%PDF-1.4\n"
			. "1 0 obj << /Type /Catalog /Pages 2 0 R >> endobj\n"
			. "2 0 obj << /Type /Pages /Kids [3 0 R] /Count 1 >> endobj\n"
			. "3 0 obj << /Type /Page /Parent 2 0 R /Contents 4 0 R >> endobj\n"
			. '4 0 obj << /Length ' . strlen( $content_stream ) . " >>\nstream\n"
			. $content_stream
			. "\nendstream\nendobj\n%%EOF\n";
	}

	/**
	 * Returns a readable PDF fixture with intentionally suspect structure.
	 *
	 * @return string PDF bytes.
	 */
	private function corrupt_pdf_smoke_document() {
		$content_stream = "BT\n/F1 12 Tf\n72 720 Td\n(# " . self::CORRUPT_PDF_SMOKE_TITLE . "\\n\\n" . self::CORRUPT_PDF_SMOKE_BODY . ") Tj\nET\n";
		$object_stream  = '6 0 << /Type /Example /Name /SmokeNestedObject >>';

		return "%PDF-1.5\n"
			. "1 0 obj << /Type /Catalog /Pages 2 0 R >> endobj\n"
			. "2 0 obj << /Type /Pages /Kids [3 0 R] /Count 1 >> endobj\n"
			. "3 0 obj << /Type /Page /Parent 2 0 R /Contents 4 0 R >> endobj\n"
			. '4 0 obj << /Length ' . strlen( $content_stream ) . " >>\nstream\n"
			. $content_stream
			. "\nendstream\nendobj\n"
			. '5 0 obj << /Type /ObjStm /N 1 /First 4 /Length ' . strlen( $object_stream ) . " >>\nstream\n"
			. $object_stream
			. "\nendstream\nendobj\n";
	}

	/**
	 * Returns the smoke-only external PDF text helper script.
	 *
	 * @return string PHP script.
	 */
	private function external_pdf_text_helper_php() {
		$external_text = var_export( '# ' . self::EXTERNAL_PDF_SMOKE_TITLE . "\n\n" . self::EXTERNAL_PDF_SMOKE_BODY . "\n", true );
		$layout_text   = var_export(
			'# ' . self::LAYOUT_PDF_SMOKE_TITLE . "\n\n"
			. "Name      Count    Total\n"
			. "Alpha     2        \$10\n"
			. "Beta      3        \$12\n\n"
			. self::LAYOUT_PDF_SMOKE_BODY . "\n",
			true
		);
		$external_file = var_export( basename( self::EXTERNAL_PDF_SMOKE_FILE ), true );
		$layout_file   = var_export( basename( self::LAYOUT_PDF_SMOKE_FILE ), true );

		return <<<PHP
<?php
if ( 3 > \$argc || ! is_file( \$argv[1] ) ) {
	fwrite( STDERR, "Missing external PDF text smoke input.\n" );
	exit( 3 );
}
\$input = basename( (string) \$argv[1] );
\$outputs = array(
	{$external_file} => {$external_text},
	{$layout_file} => {$layout_text},
);
if ( ! isset( \$outputs[ \$input ] ) ) {
	fwrite( STDERR, "Unexpected external PDF text smoke input: " . basename( (string) \$argv[1] ) . "\n" );
	exit( 4 );
}
if ( false === file_put_contents( \$argv[2], \$outputs[ \$input ] ) ) {
	fwrite( STDERR, "Unable to write external PDF text smoke output.\n" );
	exit( 5 );
}
fwrite( STDERR, "external pdf smoke text extracted\n" );
PHP;
	}

	/**
	 * Returns a mu-plugin that configures the smoke-only external PDF helper.
	 *
	 * @param string $helper_path Absolute helper script path.
	 * @return string PHP plugin.
	 */
	private function pdf_external_text_filter_plugin_php( $helper_path ) {
		$helper_path = var_export( str_replace( '\\', '/', (string) $helper_path ), true );

		return <<<PHP
<?php
add_filter(
	'universal_importer_pdf_text_command',
	static function () {
		return escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( {$helper_path} ) . ' {input} {output}';
	}
);
add_filter(
	'universal_importer_pdf_text_timeout',
	static function () {
		return 5;
	}
);
add_filter(
	'http_allowed_safe_ports',
	static function ( \$ports ) {
		\$port = (int) get_option( 'universal_importer_rest_smoke_port', 0 );
		if ( \$port > 0 ) {
			\$ports[] = \$port;
		}

		return array_values( array_unique( array_map( 'intval', \$ports ) ) );
	}
);
add_filter(
	'http_request_host_is_external',
	static function ( \$external, \$host ) {
		if ( '127.0.0.1' === \$host ) {
			return true;
		}

		return \$external;
	},
	10,
	2
);
PHP;
	}

	/**
	 * Returns the sample PNG fixture as base64.
	 *
	 * @return string Base64-encoded PNG bytes.
	 */
	private function import_smoke_png_base64() {
		return 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAIAAACQd1PeAAAADUlEQVR4nGP4z8AAAAMBAQDJ/pLvAAAAAElFTkSuQmCC';
	}

	/**
	 * Creates the mixed local smoke import fixture.
	 *
	 * @param string $source Source directory.
	 */
	private function create_local_import_smoke_fixture( $source ) {
		$this->ensure_directory( $source . '/assets' );
		$this->ensure_directory( $source . '/archives' );
		$this->ensure_directory( $source . '/exports' );
		$this->ensure_directory( $source . '/books' );
		$this->ensure_directory( $source . '/documents' );

		if ( false === file_put_contents( $source . '/hello.md', $this->import_smoke_markdown() ) ) {
			throw new RuntimeException( 'Unable to write local smoke Markdown fixture.' );
		}

		if ( false === file_put_contents( $source . '/' . self::HTML_SMOKE_FILE, $this->html_smoke_document() ) ) {
			throw new RuntimeException( 'Unable to write local smoke HTML fixture.' );
		}

		if ( false === file_put_contents( $source . '/' . self::HTML_WIDGET_SMOKE_FILE, $this->html_widget_smoke_document() ) ) {
			throw new RuntimeException( 'Unable to write local smoke HTML widget fixture.' );
		}

		if ( false === file_put_contents( $source . '/' . self::IMPORT_SMOKE_IMAGE, base64_decode( $this->import_smoke_png_base64(), true ) ) ) {
			throw new RuntimeException( 'Unable to write local smoke image fixture.' );
		}

		if ( false === file_put_contents( $source . '/' . self::HTML_SMOKE_IMAGE, base64_decode( $this->import_smoke_png_base64(), true ) ) ) {
			throw new RuntimeException( 'Unable to write local smoke HTML image fixture.' );
		}

		if ( false === file_put_contents( $source . '/' . self::WXR_SMOKE_FILE, $this->wxr_smoke_export() ) ) {
			throw new RuntimeException( 'Unable to write local smoke WXR fixture.' );
		}

		if ( false === file_put_contents( $source . '/' . self::PDF_SMOKE_FILE, $this->pdf_smoke_document() ) ) {
			throw new RuntimeException( 'Unable to write local smoke PDF fixture.' );
		}

		if ( false === file_put_contents( $source . '/' . self::UNSUPPORTED_PDF_SMOKE_FILE, $this->unsupported_pdf_smoke_document() ) ) {
			throw new RuntimeException( 'Unable to write local smoke unsupported-media PDF fixture.' );
		}

		if ( false === file_put_contents( $source . '/' . self::EXTERNAL_PDF_SMOKE_FILE, $this->external_pdf_smoke_document() ) ) {
			throw new RuntimeException( 'Unable to write local smoke external-text PDF fixture.' );
		}

		if ( false === file_put_contents( $source . '/' . self::LAYOUT_PDF_SMOKE_FILE, $this->external_pdf_smoke_document() ) ) {
			throw new RuntimeException( 'Unable to write local smoke layout-aware PDF fixture.' );
		}

		if ( false === file_put_contents( $source . '/' . self::CORRUPT_PDF_SMOKE_FILE, $this->corrupt_pdf_smoke_document() ) ) {
			throw new RuntimeException( 'Unable to write local smoke corrupt-structure PDF fixture.' );
		}

		if ( false === file_put_contents( $source . '/' . self::BROKEN_EXTERNAL_PDF_FILE, $this->external_pdf_smoke_document() ) ) {
			throw new RuntimeException( 'Unable to write local smoke broken external-text PDF fixture.' );
		}

		if ( false === file_put_contents( $source . '/' . self::EXTERNAL_PDF_HELPER_FILE, $this->external_pdf_text_helper_php() ) ) {
			throw new RuntimeException( 'Unable to write local smoke external PDF text helper.' );
		}

		if ( ! class_exists( ZipArchive::class ) ) {
			throw new RuntimeException( 'The PHP zip extension is required for the archive traversal smoke fixture.' );
		}

		$zip = new ZipArchive();
		if ( true !== $zip->open( $source . '/' . self::ARCHIVE_SMOKE_ZIP, ZipArchive::CREATE | ZipArchive::OVERWRITE ) ) {
			throw new RuntimeException( 'Unable to create local smoke archive fixture.' );
		}

		try {
			if ( ! $zip->addFromString( self::ARCHIVE_SMOKE_ENTRY, $this->archive_smoke_markdown() ) ) {
				throw new RuntimeException( 'Unable to write Markdown entry into local smoke archive fixture.' );
			}
		} finally {
			$zip->close();
		}

		$epub = new ZipArchive();
		if ( true !== $epub->open( $source . '/' . self::EPUB_SMOKE_FILE, ZipArchive::CREATE | ZipArchive::OVERWRITE ) ) {
			throw new RuntimeException( 'Unable to create local smoke EPUB fixture.' );
		}

		try {
			foreach ( $this->epub_smoke_entries() as $entry => $content ) {
				if ( ! $epub->addFromString( $entry, $content ) ) {
					throw new RuntimeException( 'Unable to write entry into local smoke EPUB fixture: ' . $entry );
				}
			}
		} finally {
			$epub->close();
		}
	}

	/**
	 * Installs a local-runtime mu-plugin for the smoke-only PDF text helper.
	 *
	 * @param string $wp_path WordPress install path.
	 * @param string $source  Smoke source directory.
	 */
	private function install_local_pdf_external_text_smoke_filter( $wp_path, $source ) {
		$mu_plugins = rtrim( str_replace( '\\', '/', (string) $wp_path ), '/' ) . '/wp-content/mu-plugins';
		$this->ensure_directory( $mu_plugins );

		$helper_path = rtrim( str_replace( '\\', '/', (string) $source ), '/' ) . '/' . self::EXTERNAL_PDF_HELPER_FILE;
		if ( ! is_file( $helper_path ) ) {
			throw new RuntimeException( 'External PDF text smoke helper is missing: ' . $helper_path );
		}

		if ( false === file_put_contents( $mu_plugins . '/universal-importer-pdf-text-smoke.php', $this->pdf_external_text_filter_plugin_php( $helper_path ) ) ) {
			throw new RuntimeException( 'Unable to write local external PDF text smoke mu-plugin.' );
		}
	}

	/**
	 * Installs a local-runtime deterministic REST fixture for mapping decisions.
	 *
	 * @param string $wp_path WordPress install path.
	 */
	private function install_local_rest_mapping_http_fixture( $wp_path ) {
		$mu_plugins = rtrim( str_replace( '\\', '/', (string) $wp_path ), '/' ) . '/wp-content/mu-plugins';
		$this->ensure_directory( $mu_plugins );

		if ( false === file_put_contents( $mu_plugins . '/universal-importer-rest-mapping-http-smoke.php', $this->local_rest_mapping_http_fixture_plugin_php() ) ) {
			throw new RuntimeException( 'Unable to write local REST mapping HTTP smoke mu-plugin.' );
		}
	}

	/**
	 * Installs a local-runtime deterministic GitHub zipball fixture.
	 *
	 * @param string $wp_path WordPress install path.
	 * @param string $workdir Smoke work directory.
	 */
	private function install_local_github_http_fixture( $wp_path, $workdir ) {
		$mu_plugins = rtrim( str_replace( '\\', '/', (string) $wp_path ), '/' ) . '/wp-content/mu-plugins';
		$this->ensure_directory( $mu_plugins );

		$archive = rtrim( str_replace( '\\', '/', (string) $workdir ), '/' ) . '/github-smoke/repository.zip';
		$this->create_github_smoke_archive( $archive );

		if ( false === file_put_contents( $mu_plugins . '/universal-importer-github-http-smoke.php', $this->github_http_fixture_plugin_php( $archive ) ) ) {
			throw new RuntimeException( 'Unable to write local GitHub HTTP smoke mu-plugin.' );
		}
	}

	/**
	 * Returns PHP code for a deterministic GitHub zipball fixture.
	 *
	 * @param string $archive_path Absolute archive fixture path.
	 * @return string PHP code.
	 */
	private function github_http_fixture_plugin_php( $archive_path ) {
		$archive = var_export( rtrim( str_replace( '\\', '/', (string) $archive_path ), '/' ), true );

		return <<<PHP
<?php
/**
 * Smoke-only deterministic GitHub zipball HTTP fixture.
 *
 * @package UniversalImporter
 */

function universal_importer_github_smoke_response( \$body, \$code = 200, \$content_type = 'application/json' ) {
	return array(
		'headers'  => array( 'content-type' => \$content_type ),
		'body'     => \$body,
		'response' => array(
			'code'    => (int) \$code,
			'message' => 200 === (int) \$code ? 'OK' : 'Error',
		),
		'cookies'  => array(),
		'filename' => null,
	);
}

add_filter(
	'pre_http_request',
	function ( \$preempt, \$parsed_args, \$url ) {
		\$parts = parse_url( (string) \$url );
		if ( ! is_array( \$parts ) || empty( \$parts['host'] ) || 'api.github.com' !== strtolower( (string) \$parts['host'] ) ) {
			return \$preempt;
		}

		\$path = isset( \$parts['path'] ) ? '/' . trim( (string) \$parts['path'], '/' ) : '/';
		if ( '/repos/example/repository/zipball/main/docs' === \$path ) {
			return universal_importer_github_smoke_response(
				wp_json_encode(
					array(
						'message' => 'Not Found',
					)
				),
				404
			);
		}

		if ( '/repos/example/repository/zipball/main' !== \$path ) {
			return \$preempt;
		}

		\$archive = {$archive};
		if ( ! is_file( \$archive ) || ! is_readable( \$archive ) ) {
			return universal_importer_github_smoke_response( 'GitHub smoke archive fixture is missing.', 500, 'text/plain' );
		}

		if ( ! empty( \$parsed_args['stream'] ) && ! empty( \$parsed_args['filename'] ) ) {
			if ( ! copy( \$archive, (string) \$parsed_args['filename'] ) ) {
				return universal_importer_github_smoke_response( 'Unable to stream the GitHub smoke archive fixture.', 500, 'text/plain' );
			}

			return universal_importer_github_smoke_response( '', 200, 'application/zip' );
		}

		\$body = file_get_contents( \$archive );
		if ( ! is_string( \$body ) ) {
			return universal_importer_github_smoke_response( 'Unable to read the GitHub smoke archive fixture.', 500, 'text/plain' );
		}

		return universal_importer_github_smoke_response( \$body, 200, 'application/zip' );
	},
	10,
	3
);
PHP;
	}

	/**
	 * Returns PHP code for a deterministic local REST relationship mapping fixture.
	 *
	 * @return string PHP code.
	 */
	private function local_rest_mapping_http_fixture_plugin_php() {
		$host        = var_export( self::REST_MAPPING_FIXTURE_HOST, true );
		$title       = var_export( self::REST_SMOKE_TITLE, true );
		$body        = var_export( self::REST_SMOKE_BODY, true );
		$author_name = var_export( self::REST_REMOTE_AUTHOR_NAME, true );
		$author_slug = var_export( self::REST_REMOTE_AUTHOR_SLUG, true );
		$taxonomy    = var_export( self::REST_REMOTE_TAXONOMY, true );
		$term_name   = var_export( self::REST_REMOTE_TERM_NAME, true );
		$term_slug   = var_export( self::REST_REMOTE_TERM_SLUG, true );

		return <<<PHP
<?php
/**
 * Smoke-only deterministic REST mapping HTTP fixture.
 *
 * @package UniversalImporter
 */

function universal_importer_rest_mapping_smoke_response( \$body, \$code = 200 ) {
	return array(
		'headers'  => array( 'content-type' => 'application/json' ),
		'body'     => wp_json_encode( \$body ),
		'response' => array(
			'code'    => (int) \$code,
			'message' => 200 === (int) \$code ? 'OK' : 'Error',
		),
		'cookies'  => array(),
		'filename' => null,
	);
}

add_filter(
	'pre_http_request',
	function ( \$preempt, \$parsed_args, \$url ) {
		\$parts = parse_url( (string) \$url );
		if ( ! is_array( \$parts ) || empty( \$parts['host'] ) || {$host} !== strtolower( (string) \$parts['host'] ) ) {
			return \$preempt;
		}

		\$path  = isset( \$parts['path'] ) ? '/' . trim( (string) \$parts['path'], '/' ) : '/';
		\$query = array();
		if ( ! empty( \$parts['query'] ) ) {
			parse_str( (string) \$parts['query'], \$query );
		}

		if ( 0 === strpos( \$path, '/wp-json' ) ) {
			\$route = '/' . ltrim( substr( \$path, strlen( '/wp-json' ) ), '/' );
		} elseif ( isset( \$query['rest_route'] ) ) {
			\$route = '/' . ltrim( (string) \$query['rest_route'], '/' );
		} else {
			\$route = '/';
		}

		if ( '/' === \$route ) {
			return universal_importer_rest_mapping_smoke_response(
				array(
					'name'       => 'Universal Importer REST Mapping Smoke',
					'namespaces' => array( 'wp/v2' ),
					'routes'     => array(
						'/wp/v2/types'    => array(),
						'/wp/v2/pages'    => array(),
						'/wp/v2/comments' => array(),
					),
				)
			);
		}

		if ( '/wp/v2/types' === \$route ) {
			return universal_importer_rest_mapping_smoke_response(
				array(
					'page' => array(
						'slug'      => 'page',
						'rest_base' => 'pages',
						'viewable'  => true,
					),
				)
			);
		}

		if ( '/wp/v2/pages' === \$route ) {
			\$page = isset( \$query['page'] ) ? (int) \$query['page'] : 1;
			if ( 1 < \$page ) {
				return universal_importer_rest_mapping_smoke_response( array() );
			}

			return universal_importer_rest_mapping_smoke_response(
				array(
					array(
						'id'       => 991,
						'link'     => 'https://' . {$host} . '/mapped-rest-smoke/',
						'type'     => 'page',
						'slug'     => 'mapped-rest-smoke',
						'status'   => 'publish',
						'title'    => array( 'rendered' => {$title} ),
						'content'  => array(
							'rendered'  => '<p>' . {$body} . '</p>',
							'protected' => false,
						),
						'author'   => 9861,
						'_embedded' => array(
							'author'  => array(
								array(
									'id'   => 9861,
									'name' => {$author_name},
									'slug' => {$author_slug},
									'link' => 'https://' . {$host} . '/author/' . {$author_slug} . '/',
								),
							),
							'wp:term' => array(
								array(
									array(
										'id'       => 610,
										'taxonomy' => {$taxonomy},
										'name'     => {$term_name},
										'slug'     => {$term_slug},
										'link'     => 'https://' . {$host} . '/' . {$taxonomy} . '/' . {$term_slug} . '/',
									),
								),
							),
						),
					),
				)
			);
		}

		if ( '/wp/v2/comments' === \$route ) {
			return universal_importer_rest_mapping_smoke_response( array() );
		}

		return universal_importer_rest_mapping_smoke_response(
			array(
				'code'    => 'rest_no_route',
				'message' => 'No REST mapping smoke route matched.',
			),
			404
		);
	},
	10,
	3
);
PHP;
	}

	/**
	 * Returns PHP code that creates the Playground mixed import fixture.
	 *
	 * @return string PHP code.
	 */
	private function import_fixture_setup_php() {
		$markdown             = var_export( $this->import_smoke_markdown(), true );
		$html                 = var_export( $this->html_smoke_document(), true );
		$html_widgets         = var_export( $this->html_widget_smoke_document(), true );
		$archive_markdown     = var_export( $this->archive_smoke_markdown(), true );
		$wxr_export           = var_export( $this->wxr_smoke_export(), true );
		$epub_entries         = var_export( $this->epub_smoke_entries(), true );
		$pdf_document         = var_export( base64_encode( $this->pdf_smoke_document() ), true );
		$unsupported_pdf      = var_export( base64_encode( $this->unsupported_pdf_smoke_document() ), true );
		$external_pdf         = var_export( $this->external_pdf_smoke_document(), true );
		$corrupt_pdf          = var_export( $this->corrupt_pdf_smoke_document(), true );
		$pdf_helper           = var_export( $this->external_pdf_text_helper_php(), true );
		$pdf_filter           = $this->pdf_external_text_filter_plugin_php( '/tmp/universal-importer-smoke/' . self::EXTERNAL_PDF_HELPER_FILE );
		$image                = var_export( $this->import_smoke_png_base64(), true );
		$archive_zip          = var_export( self::ARCHIVE_SMOKE_ZIP, true );
		$archive_entry        = var_export( self::ARCHIVE_SMOKE_ENTRY, true );
		$wxr_file             = var_export( self::WXR_SMOKE_FILE, true );
		$epub_file            = var_export( self::EPUB_SMOKE_FILE, true );
		$pdf_file             = var_export( self::PDF_SMOKE_FILE, true );
		$unsupported_pdf_file = var_export( self::UNSUPPORTED_PDF_SMOKE_FILE, true );
		$external_pdf_file    = var_export( self::EXTERNAL_PDF_SMOKE_FILE, true );
		$layout_pdf_file      = var_export( self::LAYOUT_PDF_SMOKE_FILE, true );
		$corrupt_pdf_file     = var_export( self::CORRUPT_PDF_SMOKE_FILE, true );
		$broken_pdf_file      = var_export( self::BROKEN_EXTERNAL_PDF_FILE, true );
		$pdf_helper_file      = var_export( self::EXTERNAL_PDF_HELPER_FILE, true );

		return <<<PHP
<?php
\$source = '/tmp/universal-importer-smoke';
\$assets = \$source . '/assets';
\$archives = \$source . '/archives';
\$exports = \$source . '/exports';
\$books = \$source . '/books';
\$documents = \$source . '/documents';
if ( ! is_dir( \$assets ) && ! mkdir( \$assets, 0777, true ) ) {
	throw new Exception( 'Unable to create Playground smoke media fixture directory.' );
}
if ( ! is_dir( \$archives ) && ! mkdir( \$archives, 0777, true ) ) {
	throw new Exception( 'Unable to create Playground smoke archive fixture directory.' );
}
if ( ! is_dir( \$exports ) && ! mkdir( \$exports, 0777, true ) ) {
	throw new Exception( 'Unable to create Playground smoke WXR fixture directory.' );
}
if ( ! is_dir( \$books ) && ! mkdir( \$books, 0777, true ) ) {
	throw new Exception( 'Unable to create Playground smoke EPUB fixture directory.' );
}
if ( ! is_dir( \$documents ) && ! mkdir( \$documents, 0777, true ) ) {
	throw new Exception( 'Unable to create Playground smoke PDF fixture directory.' );
}
if ( false === file_put_contents( \$source . '/hello.md', {$markdown} ) ) {
	throw new Exception( 'Unable to write Playground smoke Markdown fixture.' );
}
if ( false === file_put_contents( \$source . '/legacy.html', {$html} ) ) {
	throw new Exception( 'Unable to write Playground smoke HTML fixture.' );
}
if ( false === file_put_contents( \$source . '/legacy-widgets.html', {$html_widgets} ) ) {
	throw new Exception( 'Unable to write Playground smoke HTML widget fixture.' );
}
if ( false === file_put_contents( \$source . '/assets/smoke.png', base64_decode( {$image}, true ) ) ) {
	throw new Exception( 'Unable to write Playground smoke image fixture.' );
}
if ( false === file_put_contents( \$source . '/assets/html-smoke.png', base64_decode( {$image}, true ) ) ) {
	throw new Exception( 'Unable to write Playground smoke HTML image fixture.' );
}
if ( false === file_put_contents( \$source . '/' . {$wxr_file}, {$wxr_export} ) ) {
	throw new Exception( 'Unable to write Playground smoke WXR fixture.' );
}
\$pdf_document = base64_decode( {$pdf_document}, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Release smoke carries a binary PDF fixture through JSON.
if ( ! is_string( \$pdf_document ) || false === file_put_contents( \$source . '/' . {$pdf_file}, \$pdf_document ) ) {
	throw new Exception( 'Unable to write Playground smoke PDF fixture.' );
}
\$unsupported_pdf = base64_decode( {$unsupported_pdf}, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Release smoke carries a binary PDF fixture through JSON.
if ( ! is_string( \$unsupported_pdf ) || false === file_put_contents( \$source . '/' . {$unsupported_pdf_file}, \$unsupported_pdf ) ) {
	throw new Exception( 'Unable to write Playground smoke unsupported-media PDF fixture.' );
}
if ( false === file_put_contents( \$source . '/' . {$external_pdf_file}, {$external_pdf} ) ) {
	throw new Exception( 'Unable to write Playground smoke external-text PDF fixture.' );
}
if ( false === file_put_contents( \$source . '/' . {$layout_pdf_file}, {$external_pdf} ) ) {
	throw new Exception( 'Unable to write Playground smoke layout-aware PDF fixture.' );
}
if ( false === file_put_contents( \$source . '/' . {$corrupt_pdf_file}, {$corrupt_pdf} ) ) {
	throw new Exception( 'Unable to write Playground smoke corrupt-structure PDF fixture.' );
}
if ( false === file_put_contents( \$source . '/' . {$broken_pdf_file}, {$external_pdf} ) ) {
	throw new Exception( 'Unable to write Playground smoke broken external-text PDF fixture.' );
}
if ( false === file_put_contents( \$source . '/' . {$pdf_helper_file}, {$pdf_helper} ) ) {
	throw new Exception( 'Unable to write Playground smoke external PDF text helper.' );
}
\$mu_plugins = '/wordpress/wp-content/mu-plugins';
if ( ! is_dir( \$mu_plugins ) && ! mkdir( \$mu_plugins, 0777, true ) ) {
	throw new Exception( 'Unable to create Playground smoke mu-plugin directory.' );
}
\$pdf_text_filter = <<<'PLUGIN'
{$pdf_filter}
PLUGIN;
if ( false === file_put_contents( \$mu_plugins . '/universal-importer-pdf-text-smoke.php', \$pdf_text_filter ) ) {
	throw new Exception( 'Unable to write Playground external PDF text smoke mu-plugin.' );
}
if ( ! class_exists( 'ZipArchive' ) ) {
	throw new Exception( 'The PHP zip extension is required for archive and EPUB smoke fixtures.' );
}
\$zip = new ZipArchive();
if ( true !== \$zip->open( \$source . '/' . {$archive_zip}, ZipArchive::CREATE | ZipArchive::OVERWRITE ) ) {
	throw new Exception( 'Unable to create Playground smoke archive fixture.' );
}
try {
	if ( ! \$zip->addFromString( {$archive_entry}, {$archive_markdown} ) ) {
		throw new Exception( 'Unable to write Markdown entry into Playground smoke archive fixture.' );
	}
} finally {
	\$zip->close();
}
\$epub = new ZipArchive();
if ( true !== \$epub->open( \$source . '/' . {$epub_file}, ZipArchive::CREATE | ZipArchive::OVERWRITE ) ) {
	throw new Exception( 'Unable to create Playground smoke EPUB fixture.' );
}
try {
	foreach ( {$epub_entries} as \$entry => \$content ) {
		if ( ! \$epub->addFromString( \$entry, \$content ) ) {
			throw new Exception( 'Unable to write entry into Playground smoke EPUB fixture: ' . \$entry );
		}
	}
} finally {
	\$epub->close();
}
PHP;
	}

	/**
	 * Returns PHP code that installs a deterministic remote REST fixture for Playground.
	 *
	 * Playground's run-blueprint command does not expose a loopback web server to
	 * WordPress HTTP requests. The temporary mu-plugin keeps the smoke fully
	 * inside WordPress' HTTP API path while avoiding external network services.
	 *
	 * @return string PHP code.
	 */
	private function playground_rest_fixture_setup_php() {
		$title       = var_export( self::REST_SMOKE_TITLE, true );
		$body        = var_export( self::REST_SMOKE_BODY, true );
		$comment     = var_export( self::REST_SMOKE_COMMENT, true );
		$reply       = var_export( self::REST_SMOKE_REPLY, true );
		$image       = var_export( $this->import_smoke_png_base64(), true );
		$author_name = var_export( self::REST_REMOTE_AUTHOR_NAME, true );
		$author_slug = var_export( self::REST_REMOTE_AUTHOR_SLUG, true );
		$taxonomy    = var_export( self::REST_REMOTE_TAXONOMY, true );
		$term_name   = var_export( self::REST_REMOTE_TERM_NAME, true );
		$term_slug   = var_export( self::REST_REMOTE_TERM_SLUG, true );

		return <<<PHP
<?php
require_once '/wordpress/wp-load.php';
\$mu_plugins = WP_CONTENT_DIR . '/mu-plugins';
if ( ! is_dir( \$mu_plugins ) && ! mkdir( \$mu_plugins, 0777, true ) ) {
	throw new Exception( 'Unable to create Playground REST smoke mu-plugin directory.' );
}
\$plugin = <<<'PLUGIN'
<?php
add_filter(
	'pre_http_request',
	function ( \$preempt, \$parsed_args, \$url ) {
		\$parts = parse_url( (string) \$url );
		if ( ! is_array( \$parts ) || empty( \$parts['host'] ) || 'playground-rest-smoke.example' !== strtolower( (string) \$parts['host'] ) ) {
			return \$preempt;
		}

		\$path = isset( \$parts['path'] ) ? '/' . trim( (string) \$parts['path'], '/' ) : '/';
		\$query = array();
		if ( ! empty( \$parts['query'] ) ) {
			parse_str( (string) \$parts['query'], \$query );
		}

		if ( 0 === strpos( \$path, '/wp-json' ) ) {
			\$route = '/' . ltrim( substr( \$path, strlen( '/wp-json' ) ), '/' );
		} elseif ( isset( \$query['rest_route'] ) ) {
			\$route = '/' . ltrim( (string) \$query['rest_route'], '/' );
		} else {
			\$route = '/';
		}

		if ( '/wp-content/uploads/rest-featured.png' === \$path ) {
			\$body = base64_decode( {$image}, true );
			if ( ! is_string( \$body ) ) {
				\$body = '';
			}
			if ( ! empty( \$parsed_args['stream'] ) && ! empty( \$parsed_args['filename'] ) ) {
				if ( false === file_put_contents( (string) \$parsed_args['filename'], \$body ) ) {
					return universal_importer_playground_rest_smoke_response(
						array(
							'code'    => 'rest_smoke_stream_failed',
							'message' => 'Unable to stream the REST smoke featured image fixture.',
						),
						500
					);
				}
				\$body = '';
			}

			return array(
				'headers'  => array( 'content-type' => 'image/png' ),
				'body'     => \$body,
				'response' => array(
					'code'    => 200,
					'message' => 'OK',
				),
				'cookies'  => array(),
				'filename' => null,
			);
		}

		if ( '/' === \$route ) {
			return universal_importer_playground_rest_smoke_response(
				array(
					'name'       => 'Universal Importer Playground REST Smoke',
					'namespaces' => array( 'wp/v2' ),
					'routes'     => array(
						'/wp/v2/types'    => array(),
						'/wp/v2/pages'    => array(),
						'/wp/v2/media/850' => array(),
						'/wp/v2/comments' => array(),
					),
				)
			);
		}

		if ( '/wp/v2/types' === \$route ) {
			return universal_importer_playground_rest_smoke_response(
				array(
					'page' => array(
						'slug'      => 'page',
						'rest_base' => 'pages',
						'viewable'  => true,
					),
				)
			);
		}

		if ( '/wp/v2/pages' === \$route ) {
			\$page = isset( \$query['page'] ) ? (int) \$query['page'] : 1;
			if ( 1 < \$page ) {
				return universal_importer_playground_rest_smoke_response( array() );
			}

			return universal_importer_playground_rest_smoke_response(
				array(
					array(
						'id'             => 731,
						'date'           => '2026-05-13T00:00:00',
						'date_gmt'       => '2026-05-13T00:00:00',
						'guid'           => array( 'rendered' => 'https://playground-rest-smoke.example/?page_id=731' ),
						'link'           => 'https://playground-rest-smoke.example/packaged-rest-smoke/',
						'type'           => 'page',
						'slug'           => 'packaged-rest-smoke',
						'status'         => 'publish',
						'title'          => array( 'rendered' => {$title} ),
						'content'        => array(
							'rendered'   => '<p>' . {$body} . '</p><script>alert("rest")</script>',
							'protected'  => false,
						),
						'excerpt'        => array(
							'rendered'  => '<p>' . {$body} . '</p>',
							'protected' => false,
						),
						'author'         => 9861,
						'featured_media' => 850,
						'_embedded'      => array(
							'author' => array(
								array(
									'id'   => 9861,
									'name' => {$author_name},
									'slug' => {$author_slug},
									'link' => 'https://playground-rest-smoke.example/author/' . {$author_slug} . '/',
								),
							),
							'wp:featuredmedia' => array(
								array(
									'id'         => 850,
									'source_url' => 'https://playground-rest-smoke.example/wp-content/uploads/rest-featured.png',
									'alt_text'   => 'REST smoke featured image',
								),
							),
							'wp:term' => array(
								array(
									array(
										'id'       => 610,
										'taxonomy' => {$taxonomy},
										'name'     => {$term_name},
										'slug'     => {$term_slug},
										'link'     => 'https://playground-rest-smoke.example/' . {$taxonomy} . '/' . {$term_slug} . '/',
									),
								),
							),
						),
					),
				)
			);
		}

		if ( '/wp/v2/media/850' === \$route ) {
			return universal_importer_playground_rest_smoke_response(
				array(
					'id'         => 850,
					'source_url' => 'https://playground-rest-smoke.example/wp-content/uploads/rest-featured.png',
					'alt_text'   => 'REST smoke featured image',
				)
			);
		}

		if ( '/wp/v2/comments' === \$route ) {
			\$page = isset( \$query['page'] ) ? (int) \$query['page'] : 1;
			\$post = isset( \$query['post'] ) ? (int) \$query['post'] : 0;
			if ( 731 !== \$post || 1 < \$page ) {
				return universal_importer_playground_rest_smoke_response( array() );
			}

			return universal_importer_playground_rest_smoke_response(
				array(
					array(
						'id'         => 901,
						'post'       => 731,
						'parent'     => 0,
						'author'     => 0,
						'author_name' => 'REST Smoke Reader',
						'author_url' => 'https://reader.example.test/',
						'date'       => '2026-05-13T00:05:00',
						'date_gmt'   => '2026-05-13T00:05:00',
						'content'    => array( 'rendered' => '<p>' . {$comment} . '</p><script>alert("comment")</script>' ),
						'link'       => 'https://playground-rest-smoke.example/packaged-rest-smoke/#comment-901',
						'status'     => 'approved',
						'type'       => 'comment',
					),
					array(
						'id'         => 902,
						'post'       => 731,
						'parent'     => 901,
						'author'     => 0,
						'author_name' => 'REST Smoke Reply',
						'author_url' => '',
						'date'       => '2026-05-13T00:06:00',
						'date_gmt'   => '2026-05-13T00:06:00',
						'content'    => array( 'rendered' => '<p>' . {$reply} . '</p>' ),
						'link'       => 'https://playground-rest-smoke.example/packaged-rest-smoke/#comment-902',
						'status'     => 'approved',
						'type'       => 'comment',
					),
				)
			);
		}

		return universal_importer_playground_rest_smoke_response(
			array(
				'code'    => 'rest_no_route',
				'message' => 'No REST smoke route matched.',
			),
			404
		);
	},
	10,
	3
);

function universal_importer_playground_rest_smoke_response( array \$body, \$status = 200 ) {
	return array(
		'headers'  => array( 'content-type' => 'application/json' ),
		'body'     => wp_json_encode( \$body ),
		'response' => array(
			'code'    => (int) \$status,
			'message' => 200 === (int) \$status ? 'OK' : 'Not Found',
		),
		'cookies'  => array(),
		'filename' => null,
	);
}
PLUGIN;
if ( false === file_put_contents( \$mu_plugins . '/universal-importer-rest-smoke.php', \$plugin ) ) {
	throw new Exception( 'Unable to write Playground REST smoke mu-plugin.' );
}
PHP;
	}

	/**
	 * Returns PHP code that installs a deterministic GitHub zipball fixture for Playground.
	 *
	 * @return string PHP code.
	 */
	private function playground_github_fixture_setup_php() {
		$entries       = var_export( $this->github_smoke_archive_entries(), true );
		$fixture_root  = '/tmp/universal-importer-github-smoke';
		$archive_path  = $fixture_root . '/repository.zip';
		$github_plugin = $this->github_http_fixture_plugin_php( $archive_path );

		return <<<PHP
<?php
require_once '/wordpress/wp-load.php';
\$fixture_root = '{$fixture_root}';
if ( ! is_dir( \$fixture_root ) && ! mkdir( \$fixture_root, 0777, true ) ) {
	throw new Exception( 'Unable to create Playground GitHub smoke fixture directory.' );
}
if ( ! class_exists( 'ZipArchive' ) ) {
	throw new Exception( 'The PHP zip extension is required for the GitHub smoke fixture.' );
}
\$zip = new ZipArchive();
if ( true !== \$zip->open( '{$archive_path}', ZipArchive::CREATE | ZipArchive::OVERWRITE ) ) {
	throw new Exception( 'Unable to create Playground GitHub smoke archive fixture.' );
}
try {
	foreach ( {$entries} as \$entry => \$content ) {
		if ( ! \$zip->addFromString( \$entry, \$content ) ) {
			throw new Exception( 'Unable to write entry into Playground GitHub smoke archive fixture: ' . \$entry );
		}
	}
} finally {
	\$zip->close();
}
\$mu_plugins = '/wordpress/wp-content/mu-plugins';
if ( ! is_dir( \$mu_plugins ) && ! mkdir( \$mu_plugins, 0777, true ) ) {
	throw new Exception( 'Unable to create Playground GitHub smoke mu-plugin directory.' );
}
\$github_plugin = <<<'PLUGIN'
{$github_plugin}
PLUGIN;
if ( false === file_put_contents( \$mu_plugins . '/universal-importer-github-http-smoke.php', \$github_plugin ) ) {
	throw new Exception( 'Unable to write Playground GitHub HTTP smoke mu-plugin.' );
}
PHP;
	}

	/**
	 * Runs bounded local WP-CLI ticks until the smoke document exists.
	 *
	 * @param string[] $wp_cli  Base WP-CLI command.
	 * @param string   $wp_path WordPress path.
	 * @param string   $workdir Smoke work directory.
	 * @return array Command output from the successful assertion.
	 */
	private function run_local_import_ticks_until_asserted( array $wp_cli, $wp_path, $workdir ) {
		$last_assertion_error = null;

		for ( $tick = 1; $tick <= self::IMPORT_SMOKE_MAX_TICKS; ++$tick ) {
			$this->run_command(
				$this->build_wp_cli_command( $wp_cli, $wp_path, array( 'universal-importer', 'tick', '--max-ticks=1' ) ),
				$workdir,
				'run importer tick ' . $tick . ' through WP-CLI in local WordPress',
				true
			);

			try {
				return $this->run_command(
					$this->build_wp_cli_command( $wp_cli, $wp_path, array( 'eval', $this->import_assertion_wp_cli_code() ) ),
					$workdir,
					'verify local WordPress imported page after tick ' . $tick,
					true
				);
			} catch ( RuntimeException $error ) {
				$last_assertion_error = $error;
			}
		}

		throw new RuntimeException(
			'Local WordPress smoke did not persist the sample imported page after '
			. self::IMPORT_SMOKE_MAX_TICKS
			. " importer ticks.\nLast assertion failure:\n"
			. ( null === $last_assertion_error ? 'No assertion was run.' : $last_assertion_error->getMessage() )
		);
	}

	/**
	 * Creates a published post exposed through the disposable site's REST API.
	 *
	 * @param string[] $wp_cli  Base WP-CLI command.
	 * @param string   $wp_path WordPress path.
	 * @param string   $workdir Smoke work directory.
	 */
	private function create_remote_rest_smoke_source( array $wp_cli, $wp_path, $workdir ) {
		$content = '<p>' . self::REST_SMOKE_BODY . '</p><script>alert("rest")</script>';
		$image   = rtrim( str_replace( '\\', '/', (string) $workdir ), '/' ) . '/' . self::REST_SMOKE_IMAGE;

		if ( false === file_put_contents( $image, base64_decode( $this->import_smoke_png_base64(), true ) ) ) {
			throw new RuntimeException( 'Unable to write the local REST featured image fixture.' );
		}

		$post = $this->run_command(
			$this->build_wp_cli_command(
				$wp_cli,
				$wp_path,
				array(
					'post',
					'create',
					'--post_type=post',
					'--post_status=publish',
					'--comment_status=open',
					'--post_title=' . self::REST_SMOKE_TITLE,
					'--post_content=' . $content,
					'--porcelain',
				)
			),
			$workdir,
			'create a published source page for the local REST smoke',
			true
		);
		$post_id = (int) trim( $post['stdout'] );

		if ( $post_id < 1 ) {
			throw new RuntimeException( 'WP-CLI did not return a source page id for the local REST smoke.' );
		}

		$attachment = $this->run_command(
			$this->build_wp_cli_command(
				$wp_cli,
				$wp_path,
				array(
					'media',
					'import',
					$image,
					'--post_id=' . $post_id,
					'--featured_image',
					'--alt=REST smoke featured image',
					'--porcelain',
				)
			),
			$workdir,
			'create a featured image for the local REST smoke source page',
			true
		);
		$attachment_id = (int) trim( $attachment['stdout'] );

		if ( $attachment_id < 1 ) {
			throw new RuntimeException( 'WP-CLI did not return a featured image attachment id for the local REST smoke.' );
		}

		$comment_code = '$parent = wp_insert_comment( array( '
			. "'comment_post_ID' => " . $post_id . ', '
			. "'comment_author' => 'REST Smoke Reader', "
			. "'comment_author_url' => 'https://reader.example.test/', "
			. "'comment_content' => '<p>" . self::REST_SMOKE_COMMENT . "</p><script>alert(\"comment\")</script>', "
			. "'comment_approved' => '1', "
			. "'comment_date' => '2026-05-13 00:05:00', "
			. "'comment_date_gmt' => '2026-05-13 00:05:00', "
			. ') ); '
			. 'if ( (int) $parent < 1 ) { throw new Exception( "Unable to create the parent REST smoke comment." ); } '
			. 'update_comment_meta( (int) $parent, "rest_smoke_marker", "parent" ); '
			. '$child = wp_insert_comment( array( '
			. "'comment_post_ID' => " . $post_id . ', '
			. "'comment_parent' => (int) \$parent, "
			. "'comment_author' => 'REST Smoke Reply', "
			. "'comment_content' => '<p>" . self::REST_SMOKE_REPLY . "</p>', "
			. "'comment_approved' => '1', "
			. "'comment_date' => '2026-05-13 00:06:00', "
			. "'comment_date_gmt' => '2026-05-13 00:06:00', "
			. ') ); '
			. 'if ( (int) $child < 1 ) { throw new Exception( "Unable to create the child REST smoke comment." ); } '
			. 'update_comment_meta( (int) $child, "rest_smoke_marker", "child" ); '
			. 'echo "REST_SMOKE_COMMENTS_OK parent=" . (int) $parent . " child=" . (int) $child . "\n";';

		$this->run_command(
			$this->build_wp_cli_command(
				$wp_cli,
				$wp_path,
				array( 'eval', $comment_code )
			),
			$workdir,
			'create comments for the local REST smoke source page',
			true
		);
	}

	/**
	 * Starts a loopback PHP server for the disposable WordPress install.
	 *
	 * @param string $wp_path WordPress path.
	 * @param string $workdir Smoke work directory.
	 * @return array{process:resource,host:string,port:int,url:string,stdout:string,stderr:string,router:string}
	 */
	private function start_local_wordpress_http_server( $wp_path, $workdir ) {
		$host   = '127.0.0.1';
		$port   = $this->find_available_tcp_port( $host );
		$router = $workdir . '/wordpress-router.php';

		if ( false === file_put_contents( $router, $this->local_wordpress_router_php( $wp_path, 'http://' . $host . ':' . $port ) ) ) {
			throw new RuntimeException( 'Unable to write local WordPress HTTP smoke router.' );
		}

		$stdout  = fopen( $workdir . '/wordpress-server.stdout.log', 'w' );
		$stderr  = fopen( $workdir . '/wordpress-server.stderr.log', 'w' );
		$command = array( PHP_BINARY, '-S', $host . ':' . $port, $router );

		if ( false === $stdout || false === $stderr ) {
			throw new RuntimeException( 'Unable to open WordPress HTTP smoke log files.' );
		}

		$process = proc_open(
			implode( ' ', array_map( 'escapeshellarg', $command ) ),
			array(
				0 => array( 'pipe', 'r' ),
				1 => $stdout,
				2 => $stderr,
			),
			$pipes,
			$wp_path
		);

		if ( ! is_resource( $process ) ) {
			fclose( $stdout );
			fclose( $stderr );
			throw new RuntimeException( 'Unable to start local WordPress HTTP server for REST smoke.' );
		}

		fclose( $pipes[0] );
		fclose( $stdout );
		fclose( $stderr );

		$server = array(
			'process' => $process,
			'host'    => $host,
			'port'    => $port,
			'url'     => 'http://' . $host . ':' . $port,
			'stdout'  => $workdir . '/wordpress-server.stdout.log',
			'stderr'  => $workdir . '/wordpress-server.stderr.log',
			'router'  => $router,
		);

		try {
			$this->wait_for_local_wordpress_http_server( $server );
		} catch ( RuntimeException $error ) {
			$this->stop_local_wordpress_http_server( $server );
			throw $error;
		}

		return $server;
	}

	/**
	 * Stops a loopback PHP server.
	 *
	 * @param array $server Server runtime details.
	 */
	private function stop_local_wordpress_http_server( array $server ) {
		$status = proc_get_status( $server['process'] );

		if ( ! empty( $status['running'] ) ) {
			proc_terminate( $server['process'] );
			usleep( 100000 );
		}

		proc_close( $server['process'] );
	}

	/**
	 * Waits for the loopback WordPress REST index.
	 *
	 * @param array $server Server runtime details.
	 */
	private function wait_for_local_wordpress_http_server( array $server ) {
		$url        = $server['url'] . '/wp-json/';
		$last_error = '';
		$last_body  = '';

		for ( $i = 0; $i < 30; ++$i ) {
			$response = $this->fetch_smoke_url( $url, $last_error );
			$last_body = false === $response ? '' : (string) $response;

			if ( false !== $response && $this->response_is_rest_index( $response ) ) {
				return;
			}

			usleep( 200000 );
		}

		throw new RuntimeException(
			"Local WordPress HTTP server did not expose a REST index for the release smoke.\n"
			. $this->format_local_wordpress_http_logs( $server )
			. ( '' === $last_error ? '' : "\nLast request error:\n" . $last_error )
			. ( '' === trim( $last_body ) ? '' : "\nLast response body:\n" . $this->truncate_diagnostic( $last_body ) )
		);
	}

	/**
	 * Fetches a smoke URL with warnings captured as diagnostics.
	 *
	 * @param string $url        URL.
	 * @param string $last_error Last error sink.
	 * @return string|false Response body, or false on failure.
	 */
	private function fetch_smoke_url( $url, &$last_error ) {
		$last_error = '';
		set_error_handler(
			static function ( $severity, $message ) use ( &$last_error ) {
				unset( $severity );
				$last_error = (string) $message;
				return true;
			}
		);

		$response = file_get_contents( $url );

		restore_error_handler();

		return $response;
	}

	/**
	 * Checks whether a response body is a REST index with wp/v2 support.
	 *
	 * @param string $response Response body.
	 * @return bool Whether the response is a REST index.
	 */
	private function response_is_rest_index( $response ) {
		$decoded = json_decode( (string) $response, true );

		return is_array( $decoded )
			&& isset( $decoded['namespaces'] )
			&& is_array( $decoded['namespaces'] )
			&& in_array( 'wp/v2', $decoded['namespaces'], true );
	}

	/**
	 * Finds an available loopback TCP port.
	 *
	 * @param string $host Host.
	 * @return int Port.
	 */
	private function find_available_tcp_port( $host ) {
		$socket = stream_socket_server( 'tcp://' . $host . ':0', $errno, $errstr );

		if ( false === $socket ) {
			throw new RuntimeException( 'Unable to reserve a local HTTP smoke port: ' . $errstr . ' (' . $errno . ')' );
		}

		$name = stream_socket_get_name( $socket, false );
		fclose( $socket );

		if ( ! is_string( $name ) || ! preg_match( '/:(\d+)$/', $name, $matches ) ) {
			throw new RuntimeException( 'Unable to determine the reserved local HTTP smoke port.' );
		}

		return (int) $matches[1];
	}

	/**
	 * Returns a PHP built-in server router for WordPress.
	 *
	 * @param string $wp_path  WordPress path.
	 * @param string $site_url Temporary loopback site URL.
	 * @return string Router PHP.
	 */
	private function local_wordpress_router_php( $wp_path, $site_url ) {
		$index    = var_export( rtrim( str_replace( '\\', '/', $wp_path ), '/' ) . '/index.php', true );
		$site_url = var_export( rtrim( (string) $site_url, '/' ), true );

		return <<<PHP
<?php
if ( ! defined( 'WP_HOME' ) ) {
	define( 'WP_HOME', {$site_url} );
}
if ( ! defined( 'WP_SITEURL' ) ) {
	define( 'WP_SITEURL', {$site_url} );
}
\$path = parse_url( isset( \$_SERVER['REQUEST_URI'] ) ? \$_SERVER['REQUEST_URI'] : '/', PHP_URL_PATH );
\$file = __DIR__ . '/wordpress' . \$path;
if ( PHP_SAPI === 'cli-server' && is_string( \$path ) && is_file( \$file ) ) {
	return false;
}
if ( is_string( \$path ) && 0 === strpos( \$path, '/wp-json' ) ) {
	\$rest_route = substr( \$path, strlen( '/wp-json' ) );
	\$_GET['rest_route'] = '' === \$rest_route ? '/' : \$rest_route;
	\$_SERVER['REQUEST_URI'] = '/index.php?rest_route=' . rawurlencode( \$_GET['rest_route'] );
	\$_SERVER['QUERY_STRING'] = 'rest_route=' . rawurlencode( \$_GET['rest_route'] );
}
require {$index};
PHP;
	}

	/**
	 * Formats local WordPress HTTP server logs for diagnostics.
	 *
	 * @param array $server Server runtime details.
	 * @return string Diagnostic text.
	 */
	private function format_local_wordpress_http_logs( array $server ) {
		$parts = array();

		foreach ( array(
			'stdout' => 'WordPress HTTP stdout',
			'stderr' => 'WordPress HTTP stderr',
		) as $key => $label ) {
			if ( is_file( $server[ $key ] ) ) {
				$contents = trim( (string) file_get_contents( $server[ $key ] ) );

				if ( '' !== $contents ) {
					$parts[] = $label . ":\n" . $this->truncate_diagnostic( $contents );
				}
			}
		}

		return empty( $parts ) ? 'WordPress HTTP server produced no logs.' : implode( "\n\n", $parts );
	}

	/**
	 * Runs bounded local WP-CLI ticks until the REST smoke document exists.
	 *
	 * @param string[] $wp_cli  Base WP-CLI command.
	 * @param string   $wp_path WordPress path.
	 * @param string   $workdir Smoke work directory.
	 * @return array Command output from the successful assertion.
	 */
	private function run_local_rest_import_ticks_until_asserted( array $wp_cli, $wp_path, $workdir ) {
		$last_assertion_error = null;

		for ( $tick = 1; $tick <= self::IMPORT_SMOKE_MAX_TICKS; ++$tick ) {
			$this->run_command(
				$this->build_wp_cli_command( $wp_cli, $wp_path, array( 'universal-importer', 'tick', '--max-ticks=1' ) ),
				$workdir,
				'run REST importer tick ' . $tick . ' through WP-CLI in local WordPress',
				true
			);

			try {
				return $this->run_command(
					$this->build_wp_cli_command( $wp_cli, $wp_path, array( 'eval', $this->rest_import_assertion_wp_cli_code() ) ),
					$workdir,
					'verify local WordPress imported REST imported page after tick ' . $tick,
					true
				);
			} catch ( RuntimeException $error ) {
				$last_assertion_error = $error;
			}
		}

		throw new RuntimeException(
			'Local WordPress smoke did not persist the REST imported page after '
			. self::IMPORT_SMOKE_MAX_TICKS
			. " importer ticks.\nLast assertion failure:\n"
			. ( null === $last_assertion_error ? 'No assertion was run.' : $last_assertion_error->getMessage() )
		);
	}

	/**
	 * Runs bounded local WP-CLI ticks until the GitHub smoke document exists.
	 *
	 * @param string[] $wp_cli  Base WP-CLI command.
	 * @param string   $wp_path WordPress path.
	 * @param string   $workdir Smoke work directory.
	 * @return array Command output from the successful assertion.
	 */
	private function run_local_github_import_ticks_until_asserted( array $wp_cli, $wp_path, $workdir ) {
		$last_assertion_error = null;

		for ( $tick = 1; $tick <= self::IMPORT_SMOKE_MAX_TICKS; ++$tick ) {
			$this->run_command(
				$this->build_wp_cli_command( $wp_cli, $wp_path, array( 'universal-importer', 'tick', '--max-ticks=1' ) ),
				$workdir,
				'run GitHub importer tick ' . $tick . ' through WP-CLI in local WordPress',
				true
			);

			try {
				return $this->run_command(
					$this->build_wp_cli_command( $wp_cli, $wp_path, array( 'eval', $this->github_import_assertion_wp_cli_code() ) ),
					$workdir,
					'verify local WordPress imported GitHub imported page after tick ' . $tick,
					true
				);
			} catch ( RuntimeException $error ) {
				$last_assertion_error = $error;
			}
		}

		throw new RuntimeException(
			'Local WordPress smoke did not persist the GitHub imported page after '
			. self::IMPORT_SMOKE_MAX_TICKS
			. " importer ticks.\nLast assertion failure:\n"
			. ( null === $last_assertion_error ? 'No assertion was run.' : $last_assertion_error->getMessage() )
		);
	}

	/**
	 * Runs bounded local WP-CLI ticks until the browser-upload smoke document exists.
	 *
	 * @param string[] $wp_cli  Base WP-CLI command.
	 * @param string   $wp_path WordPress path.
	 * @param string   $workdir Smoke work directory.
	 * @return array Command output from the successful assertion.
	 */
	private function run_local_browser_upload_ticks_until_asserted( array $wp_cli, $wp_path, $workdir ) {
		$last_assertion_error = null;

		for ( $tick = 1; $tick <= self::IMPORT_SMOKE_MAX_TICKS; ++$tick ) {
			$this->run_command(
				$this->build_wp_cli_command( $wp_cli, $wp_path, array( 'universal-importer', 'tick', '--max-ticks=1' ) ),
				$workdir,
				'run browser-upload importer tick ' . $tick . ' through WP-CLI in local WordPress',
				true
			);

			try {
				return $this->run_command(
					$this->build_wp_cli_command( $wp_cli, $wp_path, array( 'eval', $this->browser_upload_assertion_wp_cli_code() ) ),
					$workdir,
					'verify local WordPress imported browser-upload imported page after tick ' . $tick,
					true
				);
			} catch ( RuntimeException $error ) {
				$last_assertion_error = $error;
			}
		}

		throw new RuntimeException(
			'Local WordPress smoke did not persist the browser-upload imported page after '
			. self::IMPORT_SMOKE_MAX_TICKS
			. " importer ticks.\nLast assertion failure:\n"
			. ( null === $last_assertion_error ? 'No assertion was run.' : $last_assertion_error->getMessage() )
		);
	}

	/**
	 * Resolves the REST relationship decision through the admin API and runs ticks until mapping is applied.
	 *
	 * @param string[] $wp_cli  Base WP-CLI command.
	 * @param string   $wp_path WordPress path.
	 * @param string   $workdir Smoke work directory.
	 * @return array Command output from the successful assertion.
	 */
	private function run_local_rest_mapping_ticks_until_asserted( array $wp_cli, $wp_path, $workdir ) {
		$last_assertion_error = null;
		$resolved             = false;

		for ( $tick = 1; $tick <= self::IMPORT_SMOKE_MAX_TICKS; ++$tick ) {
			$this->run_command(
				$this->build_wp_cli_command( $wp_cli, $wp_path, array( 'universal-importer', 'tick', '--max-ticks=1' ) ),
				$workdir,
				'run REST mapping importer tick ' . $tick . ' through WP-CLI in local WordPress',
				true
			);

			if ( ! $resolved ) {
				try {
					$this->run_command(
						$this->build_wp_cli_command( $wp_cli, $wp_path, array( 'eval', $this->rest_relationship_mapping_resolution_wp_cli_code() ) ),
						$workdir,
						'resolve the REST relationship mapping decision through the admin API in local WordPress after tick ' . $tick,
						true
					);
					$resolved = true;
				} catch ( RuntimeException $error ) {
					$last_assertion_error = $error;
					continue;
				}
			}

			try {
				return $this->run_command(
					$this->build_wp_cli_command( $wp_cli, $wp_path, array( 'eval', $this->rest_relationship_mapping_assertion_wp_cli_code() ) ),
					$workdir,
					'verify local WordPress applied resolved REST relationship mapping after tick ' . $tick,
					true
				);
			} catch ( RuntimeException $error ) {
				$last_assertion_error = $error;
			}
		}

		throw new RuntimeException(
			'Local WordPress smoke did not apply the resolved REST relationship mapping after '
			. self::IMPORT_SMOKE_MAX_TICKS
			. " importer ticks.\nLast assertion failure:\n"
			. ( null === $last_assertion_error ? 'No assertion was run.' : $last_assertion_error->getMessage() )
		);
	}

	/**
	 * Returns WP-CLI eval code that verifies activation side effects.
	 *
	 * @return string PHP code.
	 */
	private function activation_assertion_wp_cli_code() {
		return $this->strip_php_open_tag( $this->activation_assertion_php() );
	}

	/**
	 * Returns WP-CLI eval code that verifies imported durable state.
	 *
	 * @return string PHP code.
	 */
	private function import_assertion_wp_cli_code() {
		return $this->strip_php_open_tag( $this->import_assertion_php() );
	}

	/**
	 * Returns WP-CLI eval code that verifies imported REST state.
	 *
	 * @return string PHP code.
	 */
	private function rest_import_assertion_wp_cli_code() {
		return $this->strip_php_open_tag( $this->rest_import_assertion_php() );
	}

	/**
	 * Returns WP-CLI eval code that verifies imported GitHub state.
	 *
	 * @return string PHP code.
	 */
	private function github_import_assertion_wp_cli_code() {
		return $this->strip_php_open_tag( $this->github_import_assertion_php() );
	}

	/**
	 * Returns WP-CLI eval code that creates a browser-upload smoke session.
	 *
	 * @return string PHP code.
	 */
	private function browser_upload_setup_wp_cli_code() {
		return $this->strip_php_open_tag( $this->browser_upload_setup_php() );
	}

	/**
	 * Returns WP-CLI eval code that verifies imported browser-upload state.
	 *
	 * @return string PHP code.
	 */
	private function browser_upload_assertion_wp_cli_code() {
		return $this->strip_php_open_tag( $this->browser_upload_assertion_php() );
	}

	/**
	 * Returns WP-CLI eval code that resolves the REST relationship decision.
	 *
	 * @return string PHP code.
	 */
	private function rest_relationship_mapping_resolution_wp_cli_code() {
		return $this->strip_php_open_tag( $this->rest_relationship_mapping_resolution_php() );
	}

	/**
	 * Returns WP-CLI eval code that verifies resolved REST relationship mapping.
	 *
	 * @return string PHP code.
	 */
	private function rest_relationship_mapping_assertion_wp_cli_code() {
		return $this->strip_php_open_tag( $this->rest_relationship_mapping_assertion_php() );
	}

	/**
	 * Removes the opening PHP tag and Playground-only bootstrap from eval code.
	 *
	 * @param string $code PHP code.
	 * @return string PHP code suitable for wp eval.
	 */
	private function strip_php_open_tag( $code ) {
		$code = preg_replace( '/^<\?php\s*/', '', (string) $code );
		$code = str_replace( "require_once '/wordpress/wp-load.php';\n", '', $code );

		return $code;
	}

	/**
	 * Resolves WP-CLI as a command array, downloading the official Phar if needed.
	 *
	 * @param string      $workdir      Smoke work directory.
	 * @param string|null $wp_cli_phar  Optional WP-CLI Phar path.
	 * @return string[] WP-CLI command prefix.
	 */
	private function resolve_wp_cli_command( $workdir, $wp_cli_phar = null ) {
		if ( ! empty( $wp_cli_phar ) ) {
			$phar = $this->absolute_path( $wp_cli_phar );

			if ( ! is_file( $phar ) ) {
				throw new RuntimeException( 'WP-CLI Phar does not exist: ' . $phar );
			}

			return array( PHP_BINARY, $phar );
		}

		$wp = $this->find_binary( 'wp', false );

		if ( null !== $wp ) {
			return array( $wp );
		}

		$phar = $workdir . '/wp-cli.phar';
		$this->download_file( self::WP_CLI_PHAR_URL, $phar, 'download WP-CLI Phar for local release smoke' );
		$result = $this->run_command( array( PHP_BINARY, $phar, '--info' ), $workdir, 'verify downloaded WP-CLI Phar', true );

		if ( false === strpos( $result['stdout'] . "\n" . $result['stderr'], 'WP-CLI version:' ) ) {
			throw new RuntimeException( 'Downloaded WP-CLI Phar did not report a WP-CLI version.' );
		}

		return array( PHP_BINARY, $phar );
	}

	/**
	 * Starts a private MariaDB server for an isolated WordPress install.
	 *
	 * @param string $workdir Smoke work directory.
	 * @return array Database runtime details.
	 */
	private function start_private_database( $workdir ) {
		$install_db = $this->find_binary( 'mariadb-install-db' );
		$mariadbd   = $this->find_binary( 'mariadbd', false );
		$mysql      = $this->find_binary( 'mariadb', false );

		if ( null === $mariadbd ) {
			$mariadbd = $this->find_binary( 'mysqld' );
		}

		if ( null === $mysql ) {
			$mysql = $this->find_binary( 'mysql' );
		}

		$basedir  = dirname( dirname( realpath( $mariadbd ) ) );
		$datadir  = $workdir . '/mariadb-data';
		$rundir   = $workdir . '/mariadb-run';
		$socket   = $rundir . '/mysql.sock';
		$database = 'universal_importer_smoke';

		$this->ensure_directory( $datadir );
		$this->ensure_directory( $rundir );

		$this->run_command(
			array(
				$install_db,
				'--basedir=' . $basedir,
				'--datadir=' . $datadir,
				'--auth-root-authentication-method=normal',
				'--skip-test-db',
			),
			$workdir,
			'initialize private MariaDB data directory for local release smoke',
			true
		);

		$stdout = fopen( $workdir . '/mariadb.stdout.log', 'w' );
		$stderr = fopen( $workdir . '/mariadb.stderr.log', 'w' );

		if ( false === $stdout || false === $stderr ) {
			throw new RuntimeException( 'Unable to open MariaDB smoke log files.' );
		}

		$command = array(
			$mariadbd,
			'--basedir=' . $basedir,
			'--datadir=' . $datadir,
			'--socket=' . $socket,
			'--pid-file=' . $rundir . '/mysql.pid',
			'--skip-networking',
			'--user=' . $this->current_system_user(),
		);

		$process = proc_open(
			implode( ' ', array_map( 'escapeshellarg', $command ) ),
			array(
				0 => array( 'pipe', 'r' ),
				1 => $stdout,
				2 => $stderr,
			),
			$pipes,
			$workdir
		);

		if ( ! is_resource( $process ) ) {
			fclose( $stdout );
			fclose( $stderr );
			throw new RuntimeException( 'Unable to start private MariaDB server for local release smoke.' );
		}

		fclose( $pipes[0] );

		$db = array(
			'process'  => $process,
			'mysql'    => $mysql,
			'socket'   => $socket,
			'stdout'   => $workdir . '/mariadb.stdout.log',
			'stderr'   => $workdir . '/mariadb.stderr.log',
			'database' => $database,
		);

		try {
			$this->wait_for_database( $db, $workdir );
			$this->run_command(
				array( $mysql, '--socket=' . $socket, '-uroot', '-e', 'CREATE DATABASE `' . $database . '` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci' ),
				$workdir,
				'create local release smoke WordPress database'
			);
		} catch ( RuntimeException $error ) {
			$this->stop_private_database( $db );
			throw $error;
		}

		return $db;
	}

	/**
	 * Waits until private MariaDB accepts connections.
	 *
	 * @param array  $db      Database runtime details.
	 * @param string $workdir Smoke work directory.
	 */
	private function wait_for_database( array $db, $workdir ) {
		$last_error = '';

		for ( $i = 0; $i < 30; $i++ ) {
			try {
				$this->run_command( array( $db['mysql'], '--socket=' . $db['socket'], '-uroot', '-e', 'SELECT 1' ), $workdir, 'wait for private MariaDB startup', true );
				return;
			} catch ( RuntimeException $error ) {
				$last_error = $error->getMessage();
				sleep( 1 );
			}
		}

		throw new RuntimeException(
			"Private MariaDB did not become ready for local release smoke.\n"
			. $this->format_database_logs( $db )
			. ( '' === $last_error ? '' : "\nLast connection error:\n" . $last_error )
		);
	}

	/**
	 * Stops the private database server.
	 *
	 * @param array $db Database runtime details.
	 */
	private function stop_private_database( array $db ) {
		try {
			$this->run_command( array( $db['mysql'], '--socket=' . $db['socket'], '-uroot', '-e', 'SHUTDOWN' ), $this->repo_root, 'shut down private MariaDB server', true );
		} catch ( RuntimeException $error ) {
			$status = proc_get_status( $db['process'] );

			if ( ! empty( $status['running'] ) ) {
				proc_terminate( $db['process'] );
			}
		}

		proc_close( $db['process'] );
	}

	/**
	 * Formats private database logs for startup diagnostics.
	 *
	 * @param array $db Database runtime details.
	 * @return string Diagnostic text.
	 */
	private function format_database_logs( array $db ) {
		$parts = array();

		foreach ( array(
			'stdout' => 'MariaDB stdout',
			'stderr' => 'MariaDB stderr',
		) as $key => $label ) {
			if ( is_file( $db[ $key ] ) ) {
				$contents = trim( (string) file_get_contents( $db[ $key ] ) );

				if ( '' !== $contents ) {
					$parts[] = $label . ":\n" . $this->truncate_diagnostic( $contents );
				}
			}
		}

		if ( empty( $parts ) ) {
			return 'MariaDB produced no startup logs.';
		}

		return implode( "\n\n", $parts );
	}

	/**
	 * Requires Node.js 20.18+ for Playground CLI.
	 */
	private function assert_supported_node_runtime() {
		$result  = $this->run_command( array( 'node', '-v' ), $this->repo_root, 'inspect Node.js version', true );
		$version = trim( $result['stdout'] );

		if ( ! self::is_supported_node_version( $version ) ) {
			throw new RuntimeException( 'WordPress Playground CLI requires Node.js 20.18 or newer. Detected: ' . ( '' === $version ? 'unknown' : $version ) . '.' );
		}
	}

	/**
	 * Requires npx so the pinned Playground CLI package can run.
	 */
	private function assert_npx_available() {
		$this->run_command( array( 'npx', '--version' ), $this->repo_root, 'verify npx is available', true );
	}

	/**
	 * Runs a command with bounded diagnostics.
	 *
	 * @param string[] $command Command and arguments.
	 * @param string   $cwd     Working directory.
	 * @param string   $action  Human-readable action.
	 * @param bool     $capture Whether to return output.
	 * @return array Command result.
	 */
	private function run_command( array $command, $cwd, $action, $capture = false ) {
		$descriptor_spec = array(
			0 => array( 'pipe', 'r' ),
			1 => array( 'pipe', 'w' ),
			2 => array( 'pipe', 'w' ),
		);

		$command_string = implode( ' ', array_map( 'escapeshellarg', $command ) );
		$process        = proc_open( $command_string, $descriptor_spec, $pipes, $cwd );

		if ( ! is_resource( $process ) ) {
			throw new RuntimeException( 'Unable to start command to ' . $action . ': ' . $command_string );
		}

		fclose( $pipes[0] );

		$stdout = stream_get_contents( $pipes[1] );
		$stderr = stream_get_contents( $pipes[2] );

		fclose( $pipes[1] );
		fclose( $pipes[2] );

		$exit_code = proc_close( $process );

		if ( 0 !== $exit_code ) {
			$diagnostic = $this->format_command_failure_diagnostic( $stdout, $stderr );

			throw new RuntimeException( 'Unable to ' . $action . ' (exit ' . $exit_code . "):\n" . $this->truncate_diagnostic( $diagnostic ) );
		}

		if ( ! $capture && '' !== trim( (string) $stderr ) ) {
			throw new RuntimeException( 'Command to ' . $action . ' wrote unexpected stderr output:' . "\n" . $this->truncate_diagnostic( $stderr ) );
		}

		return array(
			'stdout' => (string) $stdout,
			'stderr' => (string) $stderr,
		);
	}

	/**
	 * Converts a path to an absolute path.
	 *
	 * @param string $path Path.
	 * @return string Absolute path.
	 */
	private function absolute_path( $path ) {
		$path = str_replace( '\\', '/', (string) $path );

		if ( '' !== $path && '/' === substr( $path, 0, 1 ) ) {
			return rtrim( $path, '/' );
		}

		return $this->repo_root . '/' . trim( $path, '/' );
	}

	/**
	 * Finds an executable on PATH.
	 *
	 * @param string $binary   Binary name.
	 * @param bool   $required Whether to throw when missing.
	 * @return string|null Absolute binary path, or null when optional and missing.
	 */
	private function find_binary( $binary, $required = true ) {
		try {
			$result = $this->run_command( array( 'sh', '-lc', 'command -v ' . escapeshellarg( $binary ) ), $this->repo_root, 'find ' . $binary . ' binary', true );
		} catch ( RuntimeException $error ) {
			if ( ! $required ) {
				return null;
			}

			throw new RuntimeException( 'Required binary is not available on PATH: ' . $binary );
		}

		$path = trim( $result['stdout'] );

		if ( '' !== $path ) {
			return $path;
		}

		if ( $required ) {
			throw new RuntimeException( 'Required binary is not available on PATH: ' . $binary );
		}

		return null;
	}

	/**
	 * Downloads a file with PHP streams.
	 *
	 * @param string $url    Source URL.
	 * @param string $target Target path.
	 * @param string $action Human-readable action.
	 */
	private function download_file( $url, $target, $action ) {
		$source = fopen( $url, 'rb' );

		if ( false === $source ) {
			throw new RuntimeException( 'Unable to ' . $action . ' from ' . $url . '.' );
		}

		$dest = fopen( $target, 'wb' );

		if ( false === $dest ) {
			fclose( $source );
			throw new RuntimeException( 'Unable to write downloaded file for ' . $action . ': ' . $target );
		}

		$bytes = stream_copy_to_stream( $source, $dest );

		fclose( $source );
		fclose( $dest );

		if ( false === $bytes || 1 > $bytes ) {
			throw new RuntimeException( 'Downloaded file for ' . $action . ' was empty: ' . $url );
		}
	}

	/**
	 * Ensures a directory exists.
	 *
	 * @param string $path Directory path.
	 */
	private function ensure_directory( $path ) {
		if ( is_dir( $path ) ) {
			return;
		}

		if ( ! mkdir( $path, 0777, true ) && ! is_dir( $path ) ) {
			throw new RuntimeException( 'Unable to create directory: ' . $path );
		}
	}

	/**
	 * Creates a temporary directory.
	 *
	 * @param string $prefix Temporary directory prefix.
	 * @return string Directory path.
	 */
	private function create_temporary_directory( $prefix = 'universal-importer-playground-' ) {
		$base = tempnam( sys_get_temp_dir(), $prefix );

		if ( false === $base ) {
			throw new RuntimeException( 'Unable to create smoke temporary path.' );
		}

		if ( ! unlink( $base ) || ! mkdir( $base, 0777, true ) ) {
			throw new RuntimeException( 'Unable to create smoke temporary directory.' );
		}

		return str_replace( '\\', '/', $base );
	}

	/**
	 * Returns the current system user for the private database process.
	 *
	 * @return string User name.
	 */
	private function current_system_user() {
		if ( function_exists( 'posix_getpwuid' ) && function_exists( 'posix_geteuid' ) ) {
			$user = posix_getpwuid( posix_geteuid() );

			if ( is_array( $user ) && ! empty( $user['name'] ) ) {
				return $user['name'];
			}
		}

		$result = $this->run_command( array( 'id', '-un' ), $this->repo_root, 'detect current system user', true );
		$user   = trim( $result['stdout'] );

		if ( '' === $user ) {
			throw new RuntimeException( 'Unable to detect current system user for private MariaDB.' );
		}

		return $user;
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

			unlink( $child );
		}

		rmdir( $path );
	}

	/**
	 * Truncates command output for actionable failures.
	 *
	 * @param string $diagnostic Diagnostic text.
	 * @return string Truncated diagnostic.
	 */
	private function truncate_diagnostic( $diagnostic ) {
		$diagnostic = trim( (string) $diagnostic );

		if ( 4000 < strlen( $diagnostic ) ) {
			return substr( $diagnostic, 0, 4000 ) . "\n[truncated]";
		}

		return $diagnostic;
	}

	/**
	 * Formats command failure output with both streams preserved.
	 *
	 * @param string $stdout Standard output.
	 * @param string $stderr Standard error.
	 * @return string Diagnostic text.
	 */
	private function format_command_failure_diagnostic( $stdout, $stderr ) {
		$parts = array();

		if ( '' !== trim( (string) $stdout ) ) {
			$parts[] = "STDOUT:\n" . trim( (string) $stdout );
		}

		if ( '' !== trim( (string) $stderr ) ) {
			$parts[] = "STDERR:\n" . trim( (string) $stderr );
		}

		if ( empty( $parts ) ) {
			return 'Command exited without stdout or stderr.';
		}

		return implode( "\n\n", $parts );
	}
}
