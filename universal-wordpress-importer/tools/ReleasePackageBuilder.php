<?php
/**
 * Release package builder for the Universal WordPress Importer source tree.
 *
 * @package UniversalImporter
 */

namespace UniversalImporter\Tools;

use RuntimeException;
use ZipArchive;

/**
 * Builds installable plugin zip files from the repository checkout.
 */
class ReleasePackageBuilder {
	const PLUGIN_SLUG = 'universal-wordpress-importer';

	/**
	 * Repository root.
	 *
	 * @var string
	 */
	private $repo_root;

	/**
	 * Creates a builder.
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
	 * Builds a release zip.
	 *
	 * @param string $output_dir Output directory.
	 * @param array  $options {
	 *     Build options.
	 *
	 *     @type bool $allow_dirty         Whether to allow a dirty git checkout.
	 *     @type bool $run_checks          Whether to run composer validation, tests, lint, and diff checks.
	 *     @type bool $use_existing_vendor Whether to copy the current vendor tree instead of running composer install in staging.
	 * }
	 * @return array Build summary.
	 */
	public function build( $output_dir, array $options = array() ) {
		$options = array_merge(
			array(
				'allow_dirty'         => false,
				'run_checks'          => true,
				'use_existing_vendor' => false,
			),
			$options
		);

		$metadata = $this->inspect_release_metadata();
		$this->assert_release_metadata_is_consistent( $metadata );

		if ( ! $options['allow_dirty'] ) {
			$this->assert_clean_git_status();
		}

		if ( $options['run_checks'] ) {
			$this->run_preflight_checks();
		}

		if ( ! class_exists( ZipArchive::class ) ) {
			throw new RuntimeException( 'Release zip cannot be created because the PHP zip extension is not loaded.' );
		}

		$output_dir = $this->absolute_path( $output_dir );
		$this->ensure_directory( $output_dir );

		$zip_path = $output_dir . '/' . self::PLUGIN_SLUG . '-' . $metadata['version'] . '.zip';
		$staging  = $this->create_temporary_directory();
		$payload  = $staging . '/' . self::PLUGIN_SLUG;

		try {
			$excludes = $this->read_distignore_patterns();

			if ( ! $options['use_existing_vendor'] ) {
				$excludes[] = 'vendor';
			}

			$this->copy_tree( $this->repo_root, $payload, $excludes );

			if ( $options['use_existing_vendor'] ) {
				if ( ! is_file( $payload . '/vendor/autoload.php' ) ) {
					throw new RuntimeException( 'Existing vendor tree was requested, but vendor/autoload.php is missing.' );
				}
			} else {
				$this->run_command(
					array( 'composer', 'install', '--no-dev', '--classmap-authoritative', '--no-interaction' ),
					$payload,
					'install production Composer dependencies in the release staging directory',
					true
				);
			}

			$this->prune_staged_vendor_development_trees( $payload );
			$this->create_zip( $payload, $zip_path );

			return array(
				'zip_path'     => $zip_path,
				'version'      => $metadata['version'],
				'slug'         => self::PLUGIN_SLUG,
				'vendor_mode'  => $options['use_existing_vendor'] ? 'existing' : 'composer-no-dev',
				'checks_ran'   => (bool) $options['run_checks'],
				'allow_dirty'  => (bool) $options['allow_dirty'],
				'staging_path' => $payload,
			);
		} finally {
			$this->remove_tree( $staging );
		}
	}

	/**
	 * Inspects version metadata that must stay in sync for a release.
	 *
	 * @return array Release metadata.
	 */
	public function inspect_release_metadata() {
		$main_file = $this->read_required_file( 'universal-wordpress-importer.php' );
		$readme    = $this->read_required_file( 'readme.txt' );

		return array(
			'version'          => $this->match_required( '/^\s*\*\s*Version:\s*([^\r\n]+)/mi', $main_file, 'plugin header Version' ),
			'constant_version' => $this->match_required( "/define\\(\\s*'UNIVERSAL_IMPORTER_VERSION'\\s*,\\s*'([^']+)'\\s*\\)/", $main_file, 'UNIVERSAL_IMPORTER_VERSION constant' ),
			'stable_tag'      => $this->match_required( '/^Stable tag:\s*([^\r\n]+)/mi', $readme, 'readme.txt Stable tag' ),
		);
	}

	/**
	 * Asserts release metadata values are consistent.
	 *
	 * @param array $metadata Release metadata.
	 */
	private function assert_release_metadata_is_consistent( array $metadata ) {
		$version = $metadata['version'];

		if ( $version !== $metadata['constant_version'] ) {
			throw new RuntimeException( 'Release version mismatch: plugin header Version is ' . $version . ', but UNIVERSAL_IMPORTER_VERSION is ' . $metadata['constant_version'] . '.' );
		}

		if ( $version !== $metadata['stable_tag'] ) {
			throw new RuntimeException( 'Release version mismatch: plugin header Version is ' . $version . ', but readme.txt Stable tag is ' . $metadata['stable_tag'] . '.' );
		}
	}

	/**
	 * Runs release preflight checks.
	 */
	private function run_preflight_checks() {
		$this->run_command( array( 'composer', 'validate', '--strict' ), $this->repo_root, 'validate composer.json' );
		$this->run_command( array( 'composer', 'test' ), $this->repo_root, 'run PHPUnit tests' );
		$this->run_command( array( 'composer', 'lint' ), $this->repo_root, 'run PHPCS linting' );
		$this->run_command( array( 'git', 'diff', '--check' ), $this->repo_root, 'check for whitespace errors' );
	}

	/**
	 * Requires a clean git worktree.
	 */
	private function assert_clean_git_status() {
		$result = $this->run_command( array( 'git', 'status', '--short' ), $this->repo_root, 'inspect git status', true );

		if ( '' !== trim( $result['stdout'] ) ) {
			throw new RuntimeException( "Release packaging requires a clean working tree. Commit or stash changes, or pass --allow-dirty.\n" . trim( $result['stdout'] ) );
		}
	}

	/**
	 * Reads .distignore patterns.
	 *
	 * @return string[] Patterns.
	 */
	private function read_distignore_patterns() {
		$contents = $this->read_required_file( '.distignore' );
		$patterns = array();

		foreach ( preg_split( "/\r\n|\n|\r/", $contents ) as $line ) {
			$line = trim( $line );

			if ( '' === $line || '#' === substr( $line, 0, 1 ) ) {
				continue;
			}

			$patterns[] = trim( str_replace( '\\', '/', $line ), '/' );
		}

		return $patterns;
	}

	/**
	 * Copies a directory tree into a staging directory.
	 *
	 * @param string   $source   Source directory.
	 * @param string   $target   Target directory.
	 * @param string[] $patterns Exclusion patterns.
	 */
	private function copy_tree( $source, $target, array $patterns ) {
		$this->ensure_directory( $target );

		$items = scandir( $source );

		if ( false === $items ) {
			throw new RuntimeException( 'Unable to read source directory: ' . $source );
		}

		foreach ( $items as $item ) {
			if ( '.' === $item || '..' === $item ) {
				continue;
			}

			$source_path = $source . '/' . $item;
			$relative    = ltrim( str_replace( $this->repo_root, '', str_replace( '\\', '/', $source_path ) ), '/' );

			if ( $this->is_excluded( $relative, $patterns ) ) {
				continue;
			}

			$target_path = $target . '/' . $item;

			if ( is_dir( $source_path ) ) {
				$this->copy_tree( $source_path, $target_path, $patterns );
				continue;
			}

			if ( is_link( $source_path ) ) {
				continue;
			}

			if ( ! copy( $source_path, $target_path ) ) {
				throw new RuntimeException( 'Unable to copy release file: ' . $relative );
			}
		}
	}

	/**
	 * Checks whether a relative path is excluded by a pattern.
	 *
	 * @param string   $relative Relative path.
	 * @param string[] $patterns Exclusion patterns.
	 * @return bool Whether the path is excluded.
	 */
	private function is_excluded( $relative, array $patterns ) {
		$relative = trim( str_replace( '\\', '/', $relative ), '/' );

		foreach ( $patterns as $pattern ) {
			if ( '' === $pattern ) {
				continue;
			}

			if ( $relative === $pattern || 0 === strpos( $relative, $pattern . '/' ) ) {
				return true;
			}

			if ( fnmatch( $pattern, $relative ) || fnmatch( $pattern, basename( $relative ) ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Removes development-only test trees that may ship inside production Composer packages.
	 *
	 * @param string $payload Staged plugin directory.
	 */
	private function prune_staged_vendor_development_trees( $payload ) {
		$vendor = rtrim( (string) $payload, '/\\' ) . '/vendor';

		if ( ! is_dir( $vendor ) ) {
			return;
		}

		$this->prune_vendor_directory( $vendor );
	}

	/**
	 * Recursively removes vendor directories named tests.
	 *
	 * @param string $directory Directory to scan.
	 */
	private function prune_vendor_directory( $directory ) {
		$items = scandir( $directory );

		if ( false === $items ) {
			throw new RuntimeException( 'Unable to read staged vendor directory: ' . $directory );
		}

		foreach ( $items as $item ) {
			if ( '.' === $item || '..' === $item ) {
				continue;
			}

			$path = $directory . '/' . $item;

			if ( ! is_dir( $path ) || is_link( $path ) ) {
				continue;
			}

			if ( 'tests' === strtolower( $item ) ) {
				$this->remove_tree( $path );
				continue;
			}

			$this->prune_vendor_directory( $path );
		}
	}

	/**
	 * Creates a zip archive containing the staged plugin directory.
	 *
	 * @param string $payload  Staged plugin directory.
	 * @param string $zip_path Zip path.
	 */
	private function create_zip( $payload, $zip_path ) {
		$zip = new ZipArchive();

		if ( is_file( $zip_path ) && ! unlink( $zip_path ) ) {
			throw new RuntimeException( 'Unable to replace existing release zip: ' . $zip_path );
		}

		if ( true !== $zip->open( $zip_path, ZipArchive::CREATE | ZipArchive::OVERWRITE ) ) {
			throw new RuntimeException( 'Unable to create release zip: ' . $zip_path );
		}

		$this->add_directory_to_zip( $zip, dirname( $payload ), basename( $payload ) );

		if ( ! $zip->close() ) {
			throw new RuntimeException( 'Unable to finalize release zip: ' . $zip_path );
		}
	}

	/**
	 * Adds a directory recursively to a zip.
	 *
	 * @param ZipArchive $zip       Zip archive.
	 * @param string     $base_path Base filesystem path.
	 * @param string     $relative  Relative directory path.
	 */
	private function add_directory_to_zip( ZipArchive $zip, $base_path, $relative ) {
		$absolute = $base_path . '/' . $relative;

		if ( ! $zip->addEmptyDir( $relative ) ) {
			throw new RuntimeException( 'Unable to add release directory to zip: ' . $relative );
		}

		$items = scandir( $absolute );

		if ( false === $items ) {
			throw new RuntimeException( 'Unable to read staged directory: ' . $absolute );
		}

		foreach ( $items as $item ) {
			if ( '.' === $item || '..' === $item ) {
				continue;
			}

			$item_relative = $relative . '/' . $item;
			$item_absolute = $base_path . '/' . $item_relative;

			if ( is_dir( $item_absolute ) ) {
				$this->add_directory_to_zip( $zip, $base_path, $item_relative );
				continue;
			}

			if ( ! $zip->addFile( $item_absolute, $item_relative ) ) {
				throw new RuntimeException( 'Unable to add release file to zip: ' . $item_relative );
			}
		}
	}

	/**
	 * Runs a command with bounded diagnostics.
	 *
	 * @param string[] $command Command and arguments.
	 * @param string   $cwd     Working directory.
	 * @param string   $action  Human-readable action.
	 * @param bool     $capture Whether to return output without treating stdout as diagnostic.
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
			$diagnostic = trim( (string) $stderr );
			if ( '' === $diagnostic ) {
				$diagnostic = trim( (string) $stdout );
			}

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
	 * Reads a required file.
	 *
	 * @param string $relative Relative path.
	 * @return string File contents.
	 */
	private function read_required_file( $relative ) {
		$path = $this->repo_root . '/' . ltrim( $relative, '/' );

		if ( ! is_file( $path ) ) {
			throw new RuntimeException( 'Required release file is missing: ' . $relative );
		}

		$contents = file_get_contents( $path );

		if ( false === $contents ) {
			throw new RuntimeException( 'Unable to read required release file: ' . $relative );
		}

		return $contents;
	}

	/**
	 * Matches a required value.
	 *
	 * @param string $pattern Pattern.
	 * @param string $subject Subject.
	 * @param string $label   Label.
	 * @return string Matched value.
	 */
	private function match_required( $pattern, $subject, $label ) {
		if ( ! preg_match( $pattern, $subject, $matches ) ) {
			throw new RuntimeException( 'Unable to find ' . $label . ' for release packaging.' );
		}

		return trim( $matches[1] );
	}

	/**
	 * Converts a path to an absolute path.
	 *
	 * @param string $path Path.
	 * @return string Absolute path.
	 */
	private function absolute_path( $path ) {
		$path = str_replace( '\\', '/', $path );

		if ( '' !== $path && '/' === substr( $path, 0, 1 ) ) {
			return rtrim( $path, '/' );
		}

		return $this->repo_root . '/' . trim( $path, '/' );
	}

	/**
	 * Creates a directory when needed.
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
	 * @return string Directory path.
	 */
	private function create_temporary_directory() {
		$base = tempnam( sys_get_temp_dir(), 'universal-importer-release-' );

		if ( false === $base ) {
			throw new RuntimeException( 'Unable to create release staging path.' );
		}

		if ( ! unlink( $base ) || ! mkdir( $base, 0777, true ) ) {
			throw new RuntimeException( 'Unable to create release staging directory.' );
		}

		return str_replace( '\\', '/', $base );
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
}
