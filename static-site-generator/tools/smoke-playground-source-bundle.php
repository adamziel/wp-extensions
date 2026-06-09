<?php
/**
 * Optional live Playground smoke runner for source-state Blueprint bundles.
 *
 * @package PlaygroundStaticSiteGenerator
 */

/**
 * Builds an assertion-injected temporary copy of a source-state bundle and can
 * run it through WordPress Playground CLI.
 */
final class SSGWP_Playground_Source_Bundle_Smoke_Runner {
	const DEFAULT_PLAYGROUND_CLI = '@wp-playground/cli@latest';
	const DEFAULT_WP_VERSION     = 'latest';
	const DEFAULT_PHP_VERSION    = '8.3';
	const SOURCE_BUNDLE_PATH     = '_playground-source/playground-blueprint-bundle.zip';
	const HANDOFF_OPTION         = 'ssgwp_playground_source_handoff';
	const SUCCESS_MARKER         = 'SSGWP_PLAYGROUND_SOURCE_BUNDLE_SMOKE_OK';
	const FAILURE_MARKER         = 'SSGWP_PLAYGROUND_SOURCE_BUNDLE_SMOKE_FAIL';
	const ASSERTION_BUNDLE_PATH  = 'ssgwp-smoke-assertion.php';
	const ASSERTION_RUNTIME_PATH = '/tmp/ssgwp-playground-source-bundle-smoke.php';
	const ASSERTION_MOUNT_PATH   = '/ssgwp-smoke';

	/**
	 * Prepare and optionally run the live smoke.
	 *
	 * @param array<string,mixed> $options Smoke options.
	 * @return array<string,mixed> Smoke summary.
	 * @throws RuntimeException When the input or live smoke fails.
	 */
	public function run( array $options ) {
		$options = array_merge(
			array(
				'input_path'           => null,
				'playground_cli'       => self::DEFAULT_PLAYGROUND_CLI,
				'wp_version'           => self::DEFAULT_WP_VERSION,
				'php_version'          => self::DEFAULT_PHP_VERSION,
				'skip_if_unavailable'  => true,
				'dry_run'              => false,
				'keep_bundle'          => false,
			),
			$options
		);

		$prepared = $this->prepare_smoke_bundle( (string) $options['input_path'], $options );

		try {
			$command = $this->build_playground_command(
				$options['playground_cli'],
				$prepared['blueprint_path'],
				$options['wp_version'],
				$options['php_version'],
				$prepared['workdir']
			);

			$prepared['command']     = $command;
			$prepared['bundle_kept'] = ! empty( $options['keep_bundle'] );

			if ( ! empty( $options['dry_run'] ) ) {
				$prepared['runtime'] = 'dry-run';
				$prepared['status']  = 'dry-run';
				return $prepared;
			}

			$this->assert_supported_node_runtime();
			$this->assert_npx_available();
			$result = $this->run_command( $command, dirname( __DIR__, 2 ), 'run WordPress Playground source bundle smoke check' );
			$output = (string) $result['stdout'] . "\n" . (string) $result['stderr'];

			$assertion_result = $this->read_assertion_result( $prepared['result_path'] );

			if (
				false !== strpos( $output, self::FAILURE_MARKER )
				|| ( is_array( $assertion_result ) && isset( $assertion_result['status'] ) && 'failed' === $assertion_result['status'] )
			) {
				throw new RuntimeException( 'Playground source bundle smoke assertion failed.' );
			}

			if (
				false === strpos( $output, self::SUCCESS_MARKER )
				&& ( ! is_array( $assertion_result ) || ! isset( $assertion_result['status'] ) || 'passed' !== $assertion_result['status'] )
			) {
				$diagnostic = trim( $output );
				throw new RuntimeException(
					'Playground source bundle smoke did not print the success marker.'
					. ( '' === $diagnostic ? ' The Playground CLI returned no output.' : "\n" . $this->truncate_diagnostic( $diagnostic ) )
				);
			}

			$prepared['runtime'] = 'playground';
			$prepared['status']  = 'passed';
			$prepared['stdout']  = $result['stdout'];
			$prepared['stderr']  = $result['stderr'];
			$prepared['assertion_result'] = $assertion_result;

			return $prepared;
		} catch ( RuntimeException $error ) {
			$assertion_result = isset( $prepared['result_path'] ) ? $this->read_assertion_result( $prepared['result_path'] ) : null;

			if ( is_array( $assertion_result ) && isset( $assertion_result['status'] ) && 'failed' === $assertion_result['status'] ) {
				$message = isset( $assertion_result['message'] ) ? (string) $assertion_result['message'] : 'unknown assertion failure';
				throw new RuntimeException( 'Playground source bundle smoke assertion failed: ' . $message );
			}

			if ( ! empty( $options['skip_if_unavailable'] ) && self::is_playground_infrastructure_failure( $error->getMessage() ) ) {
				$prepared['runtime'] = 'skipped';
				$prepared['status'] = 'skipped';
				$prepared['skip_reason'] = $error->getMessage();

				return $prepared;
			}

			throw $error;
		} finally {
			if ( empty( $options['keep_bundle'] ) && isset( $prepared['workdir'] ) ) {
				self::remove_tree( $prepared['workdir'] );
			}
		}
	}

	/**
	 * Create a temporary bundle directory with smoke assertions injected.
	 *
	 * @param string              $input_path Bundle ZIP, bundle directory, or export directory.
	 * @param array<string,mixed> $options    Smoke options.
	 * @return array<string,mixed> Prepared bundle summary.
	 */
	public function prepare_smoke_bundle( $input_path, array $options = array() ) {
		if ( '' === trim( (string) $input_path ) ) {
			throw new RuntimeException( 'Usage error: provide a bundle ZIP or export directory path.' );
		}

		if ( ! class_exists( 'ZipArchive' ) ) {
			throw new RuntimeException( 'The PHP zip extension is required to inspect Playground Blueprint bundles.' );
		}

		$source  = $this->resolve_bundle_source( $input_path );
		$workdir = $this->create_temporary_directory( 'ssgwp-playground-source-smoke-' );
		$bundle  = $workdir . '/bundle';

		if ( ! mkdir( $bundle, 0777, true ) ) {
			self::remove_tree( $workdir );
			throw new RuntimeException( 'Unable to create temporary smoke bundle directory.' );
		}

		try {
			if ( 'zip' === $source['type'] ) {
				$this->extract_zip_to_directory( $source['path'], $bundle );
			} else {
				$this->copy_directory( $source['path'], $bundle );
			}

			$blueprint_path = $bundle . '/blueprint.json';

			if ( ! is_file( $blueprint_path ) ) {
				throw new RuntimeException( 'The Playground bundle does not contain a root blueprint.json file.' );
			}

			$blueprint = $this->read_json_file( $blueprint_path, 'bundle blueprint.json' );

			if ( ! isset( $blueprint['steps'] ) || ! is_array( $blueprint['steps'] ) ) {
				throw new RuntimeException( 'The bundle blueprint.json file must contain a steps array.' );
			}

			$mode          = $this->detect_bundle_mode( $blueprint );
			$source_state  = is_file( $bundle . '/source-state.json' )
				? $this->read_json_file( $bundle . '/source-state.json', 'bundle source-state.json' )
				: array();
			$fixture_paths = 'sqlite-full-site-wordpress-files' === $mode
				? $this->detect_full_site_fixture_paths( $bundle, $source_state )
				: array();

			$result_path         = $workdir . '/smoke-result.json';
			$result_runtime_path = self::ASSERTION_MOUNT_PATH . '/smoke-result.json';
			$assertion_php       = $this->build_assertion_php( $mode, $fixture_paths, $result_runtime_path );
			$assertion_path = $bundle . '/' . self::ASSERTION_BUNDLE_PATH;

			if ( false === file_put_contents( $assertion_path, $assertion_php ) ) {
				throw new RuntimeException( 'Unable to write smoke assertion PHP file.' );
			}

			$blueprint = $this->ensure_extra_library( $blueprint, 'wp-cli' );
			$blueprint['steps'][] = array(
				'step' => 'writeFile',
				'path' => self::ASSERTION_RUNTIME_PATH,
				'data' => array(
					'resource' => 'bundled',
					'path' => '/' . self::ASSERTION_BUNDLE_PATH,
				),
			);
			$blueprint['steps'][] = array(
				'step' => 'wp-cli',
				'command' => 'wp eval-file ' . self::ASSERTION_RUNTIME_PATH,
			);

			$this->write_json_file( $blueprint_path, $blueprint );

			return array(
				'input_path'      => $source['input_path'],
				'source_type'     => $source['type'],
				'source_path'     => $source['path'],
				'workdir'         => $workdir,
				'bundle_dir'      => $bundle,
				'bundle_path'     => $bundle,
				'blueprint_path'  => $blueprint_path,
				'assertion_path'  => $assertion_path,
				'result_path'     => $result_path,
				'result_runtime_path' => $result_runtime_path,
				'mode'            => $mode,
				'fixture_paths'   => $fixture_paths,
				'assertion_count' => count( $fixture_paths ) + 2,
			);
		} catch ( RuntimeException $error ) {
			self::remove_tree( $workdir );
			throw $error;
		}
	}

	/**
	 * Build the Playground CLI command.
	 *
	 * @param string $playground_cli Playground CLI npm package spec.
	 * @param string $blueprint_path Temporary blueprint path.
	 * @param string $wp_version     WordPress version.
	 * @param string $php_version    PHP version.
	 * @param string $result_workdir Host workdir to mount for assertion result files.
	 * @return string[] Command arguments.
	 */
	public function build_playground_command( $playground_cli, $blueprint_path, $wp_version, $php_version, $result_workdir = null ) {
		$command = array(
			'npx',
			'--yes',
			(string) $playground_cli,
			'run-blueprint',
		);

		if ( null !== $result_workdir && '' !== trim( (string) $result_workdir ) ) {
			$command[] = '--mount=' . str_replace( '\\', '/', (string) $result_workdir ) . ':' . self::ASSERTION_MOUNT_PATH;
		}

		$command[] = '--blueprint=' . $blueprint_path;
		$command[] = '--blueprint-may-read-adjacent-files';
		$command[] = '--wp=' . $wp_version;
		$command[] = '--php=' . $php_version;
		$command[] = '--verbosity=normal';

		return $command;
	}

	/**
	 * Checks whether a Node version can run the current Playground CLI.
	 *
	 * @param string $version Version string such as v20.18.0.
	 * @return bool Whether the version is supported.
	 */
	public static function is_supported_node_version( $version ) {
		$parts = self::parse_node_version( $version );

		if ( null === $parts ) {
			return false;
		}

		if ( $parts['major'] > 20 ) {
			return true;
		}

		if ( 20 !== $parts['major'] ) {
			return false;
		}

		return $parts['minor'] > 18 || ( 18 === $parts['minor'] && $parts['patch'] >= 0 );
	}

	/**
	 * Decide whether a failed command is infrastructure, not a smoke assertion.
	 *
	 * @param string $message Failure diagnostic.
	 * @return bool Whether the failure can be skipped in skip-if-unavailable mode.
	 */
	public static function is_playground_infrastructure_failure( $message ) {
		$message = strtolower( (string) $message );

		if ( false !== strpos( $message, strtolower( self::FAILURE_MARKER ) ) ) {
			return false;
		}

		foreach (
			array(
				'fetch failed',
				'failed to fetch',
				'econnreset',
				'econnrefused',
				'enotfound',
				'etimedout',
				'eai_again',
				'network',
				'could not resolve',
				'certificate',
				'requires node.js',
				'inspect node.js version',
				'verify npx is available',
				'npx',
				'@wp-playground/cli',
				'playground cli runtime',
				'webassembly',
			) as $needle
		) {
			if ( false !== strpos( $message, $needle ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Remove a directory tree.
	 *
	 * @param string $path Directory or file path.
	 */
	public static function remove_tree( $path ) {
		if ( ! is_string( $path ) || '' === $path || ! file_exists( $path ) ) {
			return;
		}

		if ( is_file( $path ) || is_link( $path ) ) {
			unlink( $path );
			return;
		}

		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $path, FilesystemIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::CHILD_FIRST
		);

		foreach ( $iterator as $item ) {
			if ( $item->isDir() && ! $item->isLink() ) {
				rmdir( $item->getPathname() );
			} else {
				unlink( $item->getPathname() );
			}
		}

		rmdir( $path );
	}

	/**
	 * Resolve a CLI input path to a source bundle ZIP or directory.
	 *
	 * @param string $input_path Input path.
	 * @return array<string,string>
	 */
	private function resolve_bundle_source( $input_path ) {
		$real = realpath( $input_path );

		if ( false === $real ) {
			throw new RuntimeException( 'Input path does not exist: ' . $input_path );
		}

		$real = $this->normalize_path( $real );

		if ( is_file( $real ) ) {
			return array(
				'input_path' => $real,
				'type'       => 'zip',
				'path'       => $real,
			);
		}

		if ( is_dir( $real . '/' . self::SOURCE_BUNDLE_PATH ) ) {
			throw new RuntimeException( 'Expected a ZIP file at ' . self::SOURCE_BUNDLE_PATH . ', but found a directory.' );
		}

		$export_bundle = $real . '/' . self::SOURCE_BUNDLE_PATH;

		if ( is_file( $export_bundle ) ) {
			return array(
				'input_path' => $real,
				'type'       => 'zip',
				'path'       => $this->normalize_path( $export_bundle ),
			);
		}

		if ( is_file( $real . '/blueprint.json' ) ) {
			return array(
				'input_path' => $real,
				'type'       => 'directory',
				'path'       => $real,
			);
		}

		throw new RuntimeException( 'Input must be a bundle ZIP, a bundle directory with blueprint.json, or an export directory containing ' . self::SOURCE_BUNDLE_PATH . '.' );
	}

	/**
	 * Detect whether the bundle restores full SQLite state or WXR content only.
	 *
	 * @param array<string,mixed> $blueprint Blueprint payload.
	 * @return string Smoke restore mode.
	 */
	private function detect_bundle_mode( array $blueprint ) {
		if (
			isset( $blueprint['stillpress'] )
			&& is_array( $blueprint['stillpress'] )
			&& ! empty( $blueprint['stillpress']['full_site_restore'] )
		) {
			return 'sqlite-full-site-wordpress-files';
		}

		foreach ( $blueprint['steps'] as $step ) {
			if ( is_array( $step ) && isset( $step['step'] ) && 'importWordPressFiles' === $step['step'] ) {
				return 'sqlite-full-site-wordpress-files';
			}
		}

		return 'wxr-content-only';
	}

	/**
	 * Ensure the temporary smoke Blueprint can run WP-CLI setup steps.
	 *
	 * @param array<string,mixed> $blueprint Blueprint payload.
	 * @param string              $library   Playground extra library name.
	 * @return array<string,mixed> Blueprint payload.
	 */
	private function ensure_extra_library( array $blueprint, $library ) {
		if ( ! isset( $blueprint['extraLibraries'] ) || ! is_array( $blueprint['extraLibraries'] ) ) {
			$blueprint['extraLibraries'] = array();
		}

		if ( ! in_array( (string) $library, $blueprint['extraLibraries'], true ) ) {
			$blueprint['extraLibraries'][] = (string) $library;
		}

		return $blueprint;
	}

	/**
	 * Pick deterministic full-site paths to assert after importWordPressFiles.
	 *
	 * @param string              $bundle_dir   Prepared bundle directory.
	 * @param array<string,mixed> $source_state Bundled source-state metadata.
	 * @return string[] Paths relative to ABSPATH.
	 */
	private function detect_full_site_fixture_paths( $bundle_dir, array $source_state ) {
		$wp_files_zip = $bundle_dir . '/wordpress-files.zip';

		if ( ! is_file( $wp_files_zip ) ) {
			throw new RuntimeException( 'Full-site bundle mode requires bundled wordpress-files.zip.' );
		}

		$entries = $this->list_zip_entries( $wp_files_zip );
		$present = array_fill_keys( $entries, true );
		$paths   = array();
		$sqlite_database_path = 'wp-content/database/.ht.sqlite';

		if ( ! isset( $present[ $sqlite_database_path ] ) ) {
			throw new RuntimeException( 'Full-site bundle mode requires wp-content/database/.ht.sqlite inside wordpress-files.zip.' );
		}

		$this->add_fixture_path_if_present( $paths, $present, $sqlite_database_path );

		if ( isset( $source_state['active_plugins'] ) && is_array( $source_state['active_plugins'] ) ) {
			foreach ( $source_state['active_plugins'] as $plugin ) {
				if ( ! is_array( $plugin ) || empty( $plugin['plugin'] ) ) {
					continue;
				}

				$this->add_fixture_path_if_present( $paths, $present, 'wp-content/plugins/' . ltrim( (string) $plugin['plugin'], '/' ) );
			}
		}

		if ( ! $this->has_path_with_prefix( $paths, 'wp-content/plugins/' ) ) {
			$plugin_path = $this->first_entry_with_prefix( $entries, 'wp-content/plugins/' );

			if ( null !== $plugin_path ) {
				$this->add_fixture_path_if_present( $paths, $present, $plugin_path );
			}
		}

		if ( isset( $source_state['active_theme'] ) && is_array( $source_state['active_theme'] ) ) {
			foreach ( array( 'stylesheet', 'template' ) as $theme_key ) {
				if ( empty( $source_state['active_theme'][ $theme_key ] ) ) {
					continue;
				}

				$theme_prefix = 'wp-content/themes/' . trim( (string) $source_state['active_theme'][ $theme_key ], '/' ) . '/';
				$theme_path   = $this->first_entry_with_prefix( $entries, $theme_prefix );

				if ( null !== $theme_path ) {
					$this->add_fixture_path_if_present( $paths, $present, $theme_path );
				}
			}
		}

		if ( ! $this->has_path_with_prefix( $paths, 'wp-content/themes/' ) ) {
			$theme_path = $this->first_entry_with_prefix( $entries, 'wp-content/themes/' );

			if ( null !== $theme_path ) {
				$this->add_fixture_path_if_present( $paths, $present, $theme_path );
			}
		}

		$upload_path = $this->first_entry_with_prefix( $entries, 'wp-content/uploads/' );

		if ( null !== $upload_path ) {
			$this->add_fixture_path_if_present( $paths, $present, $upload_path );
		}

		return array_values( $paths );
	}

	/**
	 * Add one path when it is present and safe to mention in output.
	 *
	 * @param array<string,string> $paths   Current selected paths.
	 * @param array<string,bool>   $present Zip entry lookup.
	 * @param string               $path    Candidate path.
	 */
	private function add_fixture_path_if_present( array &$paths, array $present, $path ) {
		$path = $this->normalize_zip_entry_name( $path );

		if ( null === $path || ! isset( $present[ $path ] ) || $this->is_sensitive_path( $path ) ) {
			return;
		}

		$paths[ $path ] = $path;
	}

	/**
	 * Return the first non-sensitive file entry under a prefix.
	 *
	 * @param string[] $entries Zip entries.
	 * @param string   $prefix  Entry prefix.
	 * @return string|null Matching path.
	 */
	private function first_entry_with_prefix( array $entries, $prefix ) {
		foreach ( $entries as $entry ) {
			if ( 0 === strpos( $entry, $prefix ) && ! $this->is_sensitive_path( $entry ) ) {
				return $entry;
			}
		}

		return null;
	}

	/**
	 * Return whether selected paths already include a prefix.
	 *
	 * @param array<string,string> $paths  Selected paths.
	 * @param string               $prefix Prefix.
	 * @return bool Whether a selected path starts with the prefix.
	 */
	private function has_path_with_prefix( array $paths, $prefix ) {
		foreach ( $paths as $path ) {
			if ( 0 === strpos( $path, $prefix ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Generate assertion PHP for a WP-CLI eval-file step.
	 *
	 * @param string   $mode          Expected restore mode.
	 * @param string[] $fixture_paths Paths relative to ABSPATH.
	 * @param string   $result_path   Host-mounted result path.
	 * @return string PHP source code.
	 */
	private function build_assertion_php( $mode, array $fixture_paths, $result_path ) {
		$mode_json          = json_encode( $mode, JSON_UNESCAPED_SLASHES );
		$fixture_paths_json = json_encode( array_values( $fixture_paths ), JSON_UNESCAPED_SLASHES );
		$result_path_json   = json_encode( $result_path, JSON_UNESCAPED_SLASHES );
		$option_json        = json_encode( self::HANDOFF_OPTION, JSON_UNESCAPED_SLASHES );
		$success_json       = json_encode( self::SUCCESS_MARKER, JSON_UNESCAPED_SLASHES );
		$failure_json       = json_encode( self::FAILURE_MARKER, JSON_UNESCAPED_SLASHES );

		return <<<PHP
<?php
if ( ! defined( 'ABSPATH' ) ) {
	require '/wordpress/wp-load.php';
}

\$ssgwp_smoke_mode = {$mode_json};
\$ssgwp_smoke_paths = {$fixture_paths_json};
\$ssgwp_smoke_result_path = {$result_path_json};
\$ssgwp_smoke_option = {$option_json};
\$ssgwp_smoke_success = {$success_json};
\$ssgwp_smoke_failure = {$failure_json};
\$ssgwp_smoke_write_result = static function ( \$status, \$marker, \$message = '' ) use ( \$ssgwp_smoke_mode, \$ssgwp_smoke_paths, \$ssgwp_smoke_result_path ) {
	file_put_contents(
		\$ssgwp_smoke_result_path,
		json_encode(
			array(
				'status' => \$status,
				'marker' => \$marker,
				'mode' => \$ssgwp_smoke_mode,
				'path_count' => count( \$ssgwp_smoke_paths ),
				'message' => \$message,
			),
			JSON_UNESCAPED_SLASHES
		) . "\\n"
	);
};
\$ssgwp_smoke_fail = static function ( \$message ) use ( \$ssgwp_smoke_failure, \$ssgwp_smoke_write_result ) {
	\$ssgwp_smoke_write_result( 'failed', \$ssgwp_smoke_failure, \$message );
	echo \$ssgwp_smoke_failure . ': ' . \$message . "\\n";
	throw new Exception( \$message );
};
\$ssgwp_smoke_assert = static function ( \$condition, \$message ) use ( \$ssgwp_smoke_fail ) {
	if ( ! \$condition ) {
		\$ssgwp_smoke_fail( \$message );
	}
};
\$ssgwp_smoke_assert( function_exists( 'get_option' ), 'WordPress option API is not available.' );
\$ssgwp_smoke_assert( defined( 'ABSPATH' ), 'WordPress ABSPATH is not defined.' );
\$ssgwp_smoke_handoff = get_option( \$ssgwp_smoke_option );
if ( is_string( \$ssgwp_smoke_handoff ) ) {
	\$ssgwp_smoke_decoded = json_decode( \$ssgwp_smoke_handoff, true );
	if ( is_array( \$ssgwp_smoke_decoded ) ) {
		\$ssgwp_smoke_handoff = \$ssgwp_smoke_decoded;
	}
}
\$ssgwp_smoke_assert( is_array( \$ssgwp_smoke_handoff ), 'Missing restored source handoff option.' );
\$ssgwp_smoke_restore = isset( \$ssgwp_smoke_handoff['restore'] ) && is_array( \$ssgwp_smoke_handoff['restore'] ) ? \$ssgwp_smoke_handoff['restore'] : array();
\$ssgwp_smoke_assert( isset( \$ssgwp_smoke_restore['mode'] ) && \$ssgwp_smoke_mode === \$ssgwp_smoke_restore['mode'], 'Unexpected source handoff restore mode.' );
if ( 'sqlite-full-site-wordpress-files' === \$ssgwp_smoke_mode ) {
	\$ssgwp_smoke_assert( ! empty( \$ssgwp_smoke_restore['full_site_restore'] ) && empty( \$ssgwp_smoke_restore['content_only'] ), 'Source handoff does not record SQLite full-site restore.' );
} else {
	\$ssgwp_smoke_assert( ! empty( \$ssgwp_smoke_restore['content_only'] ) && empty( \$ssgwp_smoke_restore['full_site_restore'] ), 'Source handoff does not record WXR content-only restore.' );
}
if ( ! function_exists( 'is_plugin_active' ) && defined( 'ABSPATH' ) && file_exists( ABSPATH . 'wp-admin/includes/plugin.php' ) ) {
	require_once ABSPATH . 'wp-admin/includes/plugin.php';
}
\$ssgwp_smoke_assert( function_exists( 'is_plugin_active' ) && is_plugin_active( 'static-site-generator/static-site-generator.php' ), 'StillPress plugin is not active.' );
foreach ( \$ssgwp_smoke_paths as \$ssgwp_smoke_path ) {
	\$ssgwp_smoke_assert( file_exists( ABSPATH . \$ssgwp_smoke_path ), 'Restored fixture path is missing: ' . \$ssgwp_smoke_path );
}
\$ssgwp_smoke_write_result( 'passed', \$ssgwp_smoke_success, '' );
echo \$ssgwp_smoke_success . ': mode=' . \$ssgwp_smoke_mode . ' paths=' . count( \$ssgwp_smoke_paths ) . "\\n";
PHP;
	}

	/**
	 * Read the assertion result written by the Playground runtime.
	 *
	 * @param string $path Result JSON path.
	 * @return array<string,mixed>|null Result data when available.
	 */
	private function read_assertion_result( $path ) {
		if ( ! is_string( $path ) || '' === $path || ! is_file( $path ) ) {
			return null;
		}

		$contents = file_get_contents( $path );

		if ( ! is_string( $contents ) ) {
			return null;
		}

		$data = json_decode( $contents, true );

		if ( ! is_array( $data ) ) {
			return null;
		}

		return $data;
	}

	/**
	 * Extract a ZIP while rejecting unsafe root entries.
	 *
	 * @param string $zip_path ZIP path.
	 * @param string $target   Target directory.
	 */
	private function extract_zip_to_directory( $zip_path, $target ) {
		$zip = new ZipArchive();

		if ( true !== $zip->open( $zip_path ) ) {
			throw new RuntimeException( 'Could not open Playground Blueprint bundle ZIP: ' . $zip_path );
		}

		try {
			for ( $i = 0; $i < $zip->numFiles; ++$i ) {
				$stat = $zip->statIndex( $i );
				$raw_name = is_array( $stat ) && isset( $stat['name'] ) ? (string) $stat['name'] : '';
				$is_directory = $this->ends_with( str_replace( '\\', '/', $raw_name ), '/' );
				$name = $this->normalize_zip_entry_name( $is_directory ? rtrim( $raw_name, "/\\" ) : $raw_name );

				if ( null === $name ) {
					throw new RuntimeException( 'Refusing to extract an unsafe ZIP entry from the Playground bundle.' );
				}

				if ( $this->is_sensitive_path( $name ) ) {
					throw new RuntimeException( 'Refusing to extract a secret-like ZIP entry from the Playground bundle.' );
				}

				if ( $is_directory ) {
					$directory = $target . '/' . $name;

					if ( ! is_dir( $directory ) && ! mkdir( $directory, 0777, true ) ) {
						throw new RuntimeException( 'Unable to create directory while extracting the Playground bundle.' );
					}

					continue;
				}

				$destination = $target . '/' . $name;
				$directory   = dirname( $destination );

				if ( ! is_dir( $directory ) && ! mkdir( $directory, 0777, true ) ) {
					throw new RuntimeException( 'Unable to create directory while extracting the Playground bundle.' );
				}

				$source_stream = $zip->getStream( $stat['name'] );

				if ( false === $source_stream ) {
					throw new RuntimeException( 'Unable to read a ZIP entry from the Playground bundle.' );
				}

				$target_stream = fopen( $destination, 'wb' );

				if ( false === $target_stream ) {
					fclose( $source_stream );
					throw new RuntimeException( 'Unable to write an extracted Playground bundle entry.' );
				}

				stream_copy_to_stream( $source_stream, $target_stream );
				fclose( $source_stream );
				fclose( $target_stream );
			}
		} finally {
			$zip->close();
		}
	}

	/**
	 * Copy a bundle directory without following symlink targets.
	 *
	 * @param string $source Source directory.
	 * @param string $target Target directory.
	 */
	private function copy_directory( $source, $target ) {
		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $source, FilesystemIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::SELF_FIRST
		);

		foreach ( $iterator as $item ) {
			$relative = substr( $this->normalize_path( $item->getPathname() ), strlen( $this->normalize_path( $source ) ) + 1 );
			$relative = $this->normalize_zip_entry_name( $relative );

			if ( null === $relative ) {
				throw new RuntimeException( 'Refusing to copy an unsafe bundle path.' );
			}

			if ( $this->is_sensitive_path( $relative ) ) {
				throw new RuntimeException( 'Refusing to copy a secret-like bundle path.' );
			}

			$destination = $target . '/' . $relative;

			if ( $item->isLink() ) {
				throw new RuntimeException( 'Refusing to copy symlinked bundle entries.' );
			}

			if ( $item->isDir() ) {
				if ( ! is_dir( $destination ) && ! mkdir( $destination, 0777, true ) ) {
					throw new RuntimeException( 'Unable to copy a bundle directory.' );
				}

				continue;
			}

			$directory = dirname( $destination );

			if ( ! is_dir( $directory ) && ! mkdir( $directory, 0777, true ) ) {
				throw new RuntimeException( 'Unable to create a copied bundle directory.' );
			}

			if ( ! copy( $item->getPathname(), $destination ) ) {
				throw new RuntimeException( 'Unable to copy a bundle file.' );
			}
		}
	}

	/**
	 * List non-directory ZIP entries in deterministic order.
	 *
	 * @param string $zip_path ZIP path.
	 * @return string[] Entry names.
	 */
	private function list_zip_entries( $zip_path ) {
		$zip = new ZipArchive();

		if ( true !== $zip->open( $zip_path ) ) {
			throw new RuntimeException( 'Could not open bundled wordpress-files.zip.' );
		}

		$entries = array();

		try {
			for ( $i = 0; $i < $zip->numFiles; ++$i ) {
				$stat = $zip->statIndex( $i );
				$raw_name = is_array( $stat ) && isset( $stat['name'] ) ? (string) $stat['name'] : '';
				$is_directory = $this->ends_with( str_replace( '\\', '/', $raw_name ), '/' );
				$name = $this->normalize_zip_entry_name( $is_directory ? rtrim( $raw_name, "/\\" ) : $raw_name );

				if ( null === $name || $is_directory || $this->is_sensitive_path( $name ) ) {
					continue;
				}

				$entries[] = $name;
			}
		} finally {
			$zip->close();
		}

		sort( $entries, SORT_STRING );

		return $entries;
	}

	/**
	 * Read and decode a JSON file.
	 *
	 * @param string $path  File path.
	 * @param string $label Diagnostic label.
	 * @return array<string,mixed>
	 */
	private function read_json_file( $path, $label ) {
		$contents = file_get_contents( $path );

		if ( false === $contents ) {
			throw new RuntimeException( 'Unable to read ' . $label . '.' );
		}

		$data = json_decode( $contents, true );

		if ( ! is_array( $data ) ) {
			throw new RuntimeException( 'Unable to decode ' . $label . ' as JSON.' );
		}

		return $data;
	}

	/**
	 * Write a JSON file with stable formatting.
	 *
	 * @param string              $path File path.
	 * @param array<string,mixed> $data JSON data.
	 */
	private function write_json_file( $path, array $data ) {
		$json = json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );

		if ( ! is_string( $json ) ) {
			throw new RuntimeException( 'Unable to encode smoke Blueprint JSON.' );
		}

		if ( false === file_put_contents( $path, $json . "\n" ) ) {
			throw new RuntimeException( 'Unable to write smoke Blueprint JSON.' );
		}
	}

	/**
	 * Create a unique temporary directory.
	 *
	 * @param string $prefix Directory name prefix.
	 * @return string Directory path.
	 */
	private function create_temporary_directory( $prefix ) {
		$base = rtrim( sys_get_temp_dir(), '/\\' );

		for ( $i = 0; $i < 20; ++$i ) {
			$path = $base . '/' . $prefix . getmypid() . '-' . str_replace( '.', '', uniqid( '', true ) );

			if ( mkdir( $path, 0777, true ) ) {
				return $this->normalize_path( $path );
			}
		}

		throw new RuntimeException( 'Unable to create temporary smoke directory.' );
	}

	/**
	 * Requires Node.js 20.18+ for the current Playground CLI.
	 */
	private function assert_supported_node_runtime() {
		$result  = $this->run_command( array( 'node', '-v' ), dirname( __DIR__, 2 ), 'inspect Node.js version' );
		$version = trim( $result['stdout'] );

		if ( ! self::is_supported_node_version( $version ) ) {
			throw new RuntimeException( 'WordPress Playground CLI requires Node.js 20.18 or newer. Detected: ' . ( '' === $version ? 'unknown' : $version ) . '.' );
		}
	}

	/**
	 * Requires npx so the Playground CLI package can run.
	 */
	private function assert_npx_available() {
		$this->run_command( array( 'npx', '--version' ), dirname( __DIR__, 2 ), 'verify npx is available' );
	}

	/**
	 * Run a command and capture output.
	 *
	 * @param string[] $command Command argv.
	 * @param string   $cwd     Working directory.
	 * @param string   $action  Human-readable action.
	 * @return array{stdout:string,stderr:string}
	 */
	private function run_command( array $command, $cwd, $action ) {
		$descriptor_spec = array(
			0 => array( 'pipe', 'r' ),
			1 => array( 'pipe', 'w' ),
			2 => array( 'pipe', 'w' ),
		);
		$command_string   = implode( ' ', array_map( 'escapeshellarg', $command ) );
		$process          = proc_open( $command_string, $descriptor_spec, $pipes, $cwd );

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
			$diagnostic = trim( (string) $stdout . "\n" . (string) $stderr );
			throw new RuntimeException( 'Unable to ' . $action . ' (exit ' . $exit_code . "):\n" . $this->truncate_diagnostic( $diagnostic ) );
		}

		return array(
			'stdout' => (string) $stdout,
			'stderr' => (string) $stderr,
		);
	}

	/**
	 * Keep process diagnostics bounded for CI logs.
	 *
	 * @param string $diagnostic Full diagnostic.
	 * @return string Truncated diagnostic.
	 */
	private function truncate_diagnostic( $diagnostic ) {
		$diagnostic = (string) $diagnostic;

		if ( strlen( $diagnostic ) <= 6000 ) {
			return $diagnostic;
		}

		return substr( $diagnostic, 0, 6000 ) . "\n[truncated]";
	}

	/**
	 * Normalize a filesystem path for diagnostics and comparisons.
	 *
	 * @param string $path Path.
	 * @return string Normalized path.
	 */
	private function normalize_path( $path ) {
		return str_replace( '\\', '/', (string) $path );
	}

	/**
	 * Normalize and validate a ZIP entry path.
	 *
	 * @param string $name Entry name.
	 * @return string|null Safe relative entry name.
	 */
	private function normalize_zip_entry_name( $name ) {
		$name = str_replace( '\\', '/', (string) $name );
		$name = ltrim( $name, '/' );

		if ( '' === $name || false !== strpos( $name, "\0" ) ) {
			return null;
		}

		$segments = explode( '/', $name );

		foreach ( $segments as $segment ) {
			if ( '' === $segment || '.' === $segment || '..' === $segment ) {
				return null;
			}
		}

		return $name;
	}

	/**
	 * Avoid reading or printing known secret-like file paths.
	 *
	 * @param string $path Relative path.
	 * @return bool Whether the path is sensitive.
	 */
	private function is_sensitive_path( $path ) {
		$name = basename( str_replace( '\\', '/', (string) $path ) );
		$lower = strtolower( $name );

		if ( '.env' === $lower || 0 === strpos( $lower, '.env.' ) || false !== strpos( $lower, 'credential' ) || false !== strpos( $lower, 'secret' ) || false !== strpos( $lower, 'token' ) || false !== strpos( $lower, 'private-key' ) ) {
			return true;
		}

		return $this->ends_with( $lower, '.pem' );
	}

	/**
	 * Parse a Node.js version without relying on a regular expression.
	 *
	 * @param string $version Version string.
	 * @return array<string,int>|null Parsed major/minor/patch.
	 */
	private static function parse_node_version( $version ) {
		$version = trim( (string) $version );

		if ( '' !== $version && 'v' === $version[0] ) {
			$version = substr( $version, 1 );
		}

		$parts = explode( '.', $version );

		if ( count( $parts ) < 3 ) {
			return null;
		}

		for ( $i = 0; $i < 3; ++$i ) {
			if ( '' === $parts[ $i ] || ! ctype_digit( $parts[ $i ] ) ) {
				return null;
			}
		}

		return array(
			'major' => (int) $parts[0],
			'minor' => (int) $parts[1],
			'patch' => (int) $parts[2],
		);
	}

	/**
	 * Portable string suffix check for PHP 7.4.
	 *
	 * @param string $value  Value.
	 * @param string $suffix Suffix.
	 * @return bool Whether value ends with suffix.
	 */
	private function ends_with( $value, $suffix ) {
		$length = strlen( $suffix );

		if ( 0 === $length ) {
			return true;
		}

		return substr( $value, -$length ) === $suffix;
	}
}

/**
 * Parse command-line arguments.
 *
 * @param string[] $argv Raw argv.
 * @return array<string,mixed> Parsed options.
 */
function ssgwp_playground_source_smoke_parse_args( array $argv ) {
	$options = array(
		'input_path'          => null,
		'playground_cli'      => SSGWP_Playground_Source_Bundle_Smoke_Runner::DEFAULT_PLAYGROUND_CLI,
		'wp_version'          => SSGWP_Playground_Source_Bundle_Smoke_Runner::DEFAULT_WP_VERSION,
		'php_version'         => SSGWP_Playground_Source_Bundle_Smoke_Runner::DEFAULT_PHP_VERSION,
		'skip_if_unavailable' => true,
		'dry_run'             => false,
		'keep_bundle'         => false,
	);

	for ( $i = 1; $i < count( $argv ); ++$i ) {
		$arg = (string) $argv[ $i ];

		if ( '--help' === $arg || '-h' === $arg ) {
			$options['help'] = true;
			continue;
		}

		if ( '--skip-if-unavailable' === $arg ) {
			$options['skip_if_unavailable'] = true;
			continue;
		}

		if ( '--no-skip-if-unavailable' === $arg ) {
			$options['skip_if_unavailable'] = false;
			continue;
		}

		if ( '--dry-run' === $arg ) {
			$options['dry_run'] = true;
			continue;
		}

		if ( '--keep-bundle' === $arg ) {
			$options['keep_bundle'] = true;
			continue;
		}

		$value_option = ssgwp_playground_source_smoke_parse_value_option( $argv, $i );

		if ( null !== $value_option ) {
			$options[ $value_option['key'] ] = $value_option['value'];
			$i = $value_option['index'];
			continue;
		}

		if ( 0 === strpos( $arg, '--' ) ) {
			throw new RuntimeException( 'Unknown option: ' . $arg );
		}

		if ( null !== $options['input_path'] ) {
			throw new RuntimeException( 'Only one bundle or export path may be provided.' );
		}

		$options['input_path'] = $arg;
	}

	return $options;
}

/**
 * Parse an option that accepts a value.
 *
 * @param string[] $argv Raw argv.
 * @param int      $i    Current index.
 * @return array{key:string,value:string,index:int}|null Parsed option.
 */
function ssgwp_playground_source_smoke_parse_value_option( array $argv, $i ) {
	$arg = (string) $argv[ $i ];
	$map = array(
		'--playground-cli' => 'playground_cli',
		'--wp'             => 'wp_version',
		'--php'            => 'php_version',
	);

	foreach ( $map as $option => $key ) {
		if ( $arg === $option ) {
			if ( ! isset( $argv[ $i + 1 ] ) ) {
				throw new RuntimeException( 'Missing value for ' . $option . '.' );
			}

			$value = (string) $argv[ $i + 1 ];

			if ( '' === trim( $value ) || 0 === strpos( $value, '--' ) ) {
				throw new RuntimeException( 'Missing value for ' . $option . '.' );
			}

			return array(
				'key'   => $key,
				'value' => $value,
				'index' => $i + 1,
			);
		}

		$prefix = $option . '=';

		if ( 0 === strpos( $arg, $prefix ) ) {
			$value = substr( $arg, strlen( $prefix ) );

			if ( '' === trim( $value ) ) {
				throw new RuntimeException( 'Missing value for ' . $option . '.' );
			}

			return array(
				'key'   => $key,
				'value' => $value,
				'index' => $i,
			);
		}
	}

	return null;
}

/**
 * Render CLI usage.
 */
function ssgwp_playground_source_smoke_usage() {
	echo "Usage: php static-site-generator/tools/smoke-playground-source-bundle.php [options] <bundle.zip|bundle-dir|export-dir>\n";
	echo "\n";
	echo "Options:\n";
	echo "  --playground-cli=<pkg>       Playground CLI package spec. Default: @wp-playground/cli@latest\n";
	echo "  --wp=<version>               WordPress version passed to Playground CLI. Default: latest\n";
	echo "  --php=<version>              PHP version passed to Playground CLI. Default: 8.3\n";
	echo "  --skip-if-unavailable        Exit 0 with SKIP for missing Node/npx/network/CLI runtime. Default.\n";
	echo "  --no-skip-if-unavailable     Treat Playground infrastructure failures as errors.\n";
	echo "  --dry-run                    Build the smoke bundle and print the command without running it.\n";
	echo "  --keep-bundle                Keep the temporary assertion-injected bundle directory.\n";
}

/**
 * Quote argv for display.
 *
 * @param string[] $command Command argv.
 * @return string Command string.
 */
function ssgwp_playground_source_smoke_command_string( array $command ) {
	return implode( ' ', array_map( 'escapeshellarg', $command ) );
}

if ( isset( $_SERVER['SCRIPT_FILENAME'] ) && realpath( (string) $_SERVER['SCRIPT_FILENAME'] ) === __FILE__ ) {
	try {
		$options = ssgwp_playground_source_smoke_parse_args( $argv );

		if ( ! empty( $options['help'] ) ) {
			ssgwp_playground_source_smoke_usage();
			exit( 0 );
		}

		$runner = new SSGWP_Playground_Source_Bundle_Smoke_Runner();
		$result = $runner->run( $options );

		if ( 'skipped' === $result['runtime'] ) {
			echo 'SKIP: WordPress Playground source bundle smoke was not run. ' . $result['skip_reason'] . "\n";
			exit( 0 );
		}

		if ( 'dry-run' === $result['runtime'] ) {
			echo "DRY RUN: assertion-injected bundle prepared.\n";
			echo 'Mode: ' . $result['mode'] . "\n";
			echo 'Blueprint: ' . $result['blueprint_path'] . "\n";
			echo 'Command: ' . ssgwp_playground_source_smoke_command_string( $result['command'] ) . "\n";
			echo 'Temporary bundle: ' . ( ! empty( $result['bundle_kept'] ) ? 'kept' : 'removed; pass --keep-bundle to inspect it' ) . "\n";
			exit( 0 );
		}

		echo "PASS: WordPress Playground source bundle smoke ran.\n";
		echo 'Mode: ' . $result['mode'] . "\n";

		if ( isset( $result['stdout'] ) && '' !== trim( (string) $result['stdout'] ) ) {
			echo $result['stdout'];
		}

		if ( isset( $result['stderr'] ) && '' !== trim( (string) $result['stderr'] ) ) {
			fwrite( STDERR, $result['stderr'] );
		}

		exit( 0 );
	} catch ( RuntimeException $error ) {
		fwrite( STDERR, 'FAIL: ' . $error->getMessage() . "\n" );
		exit( 1 );
	}
}
