<?php
/**
 * Tests for release package building.
 *
 * @package UniversalImporter
 */

namespace UniversalImporter\Tests\Unit\Release;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use UniversalImporter\Tools\ReleasePackageBuilder;
use ZipArchive;

require_once dirname( __DIR__, 3 ) . '/tools/ReleasePackageBuilder.php';

/**
 * Tests release package builder behavior.
 *
 * @covers \UniversalImporter\Tools\ReleasePackageBuilder
 */
class ReleasePackageBuilderTest extends TestCase {
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
	 * Release metadata must stay in sync across plugin header, constant, and readme.
	 */
	public function test_inspects_consistent_release_metadata() {
		$builder  = new ReleasePackageBuilder( dirname( __DIR__, 3 ) );
		$metadata = $builder->inspect_release_metadata();

		$this->assertSame( '0.1.0', $metadata['version'] );
		$this->assertSame( $metadata['version'], $metadata['constant_version'] );
		$this->assertSame( $metadata['version'], $metadata['stable_tag'] );
	}

	/**
	 * The builder creates a single-directory install zip and honors .distignore.
	 */
	public function test_builds_release_zip_with_expected_contents() {
		if ( ! class_exists( ZipArchive::class ) ) {
			$this->markTestSkipped( 'The PHP zip extension is required for release packaging.' );
		}

		$repo    = $this->create_release_fixture_repo();
		$output  = $repo . '/build-output';
		$builder = new ReleasePackageBuilder( $repo );
		$summary = $builder->build(
			$output,
			array(
				'allow_dirty'         => true,
				'run_checks'          => false,
				'use_existing_vendor' => true,
			)
		);

		$this->assertFileExists( $summary['zip_path'] );
		$this->assertSame( '0.1.0', $summary['version'] );
		$this->assertSame( 'existing', $summary['vendor_mode'] );

		$paths = $this->zip_paths( $summary['zip_path'] );

		$this->assertContains( 'universal-wordpress-importer/universal-wordpress-importer.php', $paths );
		$this->assertContains( 'universal-wordpress-importer/src/Plugin.php', $paths );
		$this->assertContains( 'universal-wordpress-importer/vendor/autoload.php', $paths );
		$this->assertContains( 'universal-wordpress-importer/README.md', $paths );
		$this->assertContains( 'universal-wordpress-importer/readme.txt', $paths );
		$this->assertContains( 'universal-wordpress-importer/docs/release-packaging.md', $paths );
		$this->assertContains( 'universal-wordpress-importer/CHANGELOG.md', $paths );

		$this->assertNotContains( 'universal-wordpress-importer/.distignore', $paths );
		$this->assertNotContains( 'universal-wordpress-importer/.autonomous-loop/goal.md', $paths );
		$this->assertNotContains( 'universal-wordpress-importer/tests/bootstrap.php', $paths );
		$this->assertNotContains( 'universal-wordpress-importer/phpunit.xml.dist', $paths );
		$this->assertNotContains( 'universal-wordpress-importer/phpcs.xml.dist', $paths );
		$this->assertNotContains( 'universal-wordpress-importer/run_autonomous_loop.sh', $paths );
		$this->assertNotContains( 'universal-wordpress-importer/scripts/codex-loop.sh', $paths );
		$this->assertNotContains( 'universal-wordpress-importer/tools/build-release.php', $paths );
		$this->assertNotContains( 'universal-wordpress-importer/vendor/bin/phpunit', $paths );
		$this->assertNotContains( 'universal-wordpress-importer/vendor/example/package/tests/FixtureTest.php', $paths );

		foreach ( $paths as $path ) {
			$this->assertStringStartsWith( 'universal-wordpress-importer/', $path );
		}
	}

	/**
	 * The release zip verifier accepts a valid package produced by the builder.
	 */
	public function test_verify_release_zip_script_accepts_valid_release_zip() {
		if ( ! class_exists( ZipArchive::class ) ) {
			$this->markTestSkipped( 'The PHP zip extension is required for release packaging.' );
		}

		$repo    = $this->create_release_fixture_repo();
		$output  = $repo . '/build-output';
		$builder = new ReleasePackageBuilder( $repo );
		$summary = $builder->build(
			$output,
			array(
				'allow_dirty'         => true,
				'run_checks'          => false,
				'use_existing_vendor' => true,
			)
		);

		$result = $this->run_verify_release_zip_script( array( '--zip=' . $summary['zip_path'] ) );

		$this->assertSame( 0, $result['exit_code'], $result['stderr'] );
		$this->assertStringContainsString( 'Release zip integrity passed:', $result['stdout'] );
		$this->assertStringContainsString( 'Entries:', $result['stdout'] );
	}

	/**
	 * The release zip verifier prints help without requiring a zip path.
	 */
	public function test_verify_release_zip_script_prints_help() {
		$result = $this->run_verify_release_zip_script( array( '--help' ) );

		$this->assertSame( 0, $result['exit_code'], $result['stderr'] );
		$this->assertStringContainsString( 'Usage: php tools/verify-release-zip.php --zip=', $result['stdout'] );
		$this->assertStringContainsString( 'maintained release exclusions', $result['stdout'] );
	}

	/**
	 * The release build script documents package integrity verification.
	 */
	public function test_build_release_script_help_mentions_package_integrity_verification() {
		$result = $this->run_build_release_script( array( '--help' ) );

		$this->assertSame( 0, $result['exit_code'], $result['stderr'] );
		$this->assertStringContainsString( 'Usage: php tools/build-release.php', $result['stdout'] );
		$this->assertStringContainsString( 'verifies package integrity before reporting success', $result['stdout'] );
	}

	/**
	 * The release build script verifies package integrity before reporting success.
	 */
	public function test_build_release_script_reports_package_integrity_verification() {
		if ( ! class_exists( ZipArchive::class ) ) {
			$this->markTestSkipped( 'The PHP zip extension is required for release packaging.' );
		}

		$output = $this->temporary_directory();
		$result = $this->run_build_release_script(
			array(
				'--allow-dirty',
				'--skip-checks',
				'--use-existing-vendor',
				'--output=' . $output,
			)
		);

		$this->assertSame( 0, $result['exit_code'], $result['stderr'] );
		$this->assertStringContainsString( 'Package integrity: verified', $result['stdout'] );
		$this->assertFileExists( $output . '/universal-wordpress-importer-0.1.0.zip' );
	}

	/**
	 * The release zip verifier rejects missing required CLI options.
	 */
	public function test_verify_release_zip_script_rejects_missing_zip_option() {
		$result = $this->run_verify_release_zip_script( array() );

		$this->assertSame( 2, $result['exit_code'] );
		$this->assertStringContainsString( 'Usage: php tools/verify-release-zip.php --zip=', $result['stdout'] );
	}

	/**
	 * The release zip verifier rejects paths that do not exist.
	 */
	public function test_verify_release_zip_script_rejects_missing_zip() {
		$missing_zip = $this->temporary_directory() . '/missing.zip';

		$result = $this->run_verify_release_zip_script( array( '--zip=' . $missing_zip ) );

		$this->assertSame( 1, $result['exit_code'] );
		$this->assertStringContainsString( 'Release zip does not exist:', $result['stderr'] );
	}

	/**
	 * The release zip verifier rejects files that are not readable zip archives.
	 */
	public function test_verify_release_zip_script_rejects_corrupt_zip() {
		$zip_path = $this->temporary_directory() . '/corrupt.zip';
		$this->write_file( $zip_path, 'not a zip archive' );

		$result = $this->run_verify_release_zip_script( array( '--zip=' . $zip_path ) );

		$this->assertSame( 1, $result['exit_code'] );
		$this->assertStringContainsString( 'Unable to open release zip:', $result['stderr'] );
	}

	/**
	 * The release zip verifier rejects packages with files outside the plugin root.
	 */
	public function test_verify_release_zip_script_rejects_paths_outside_plugin_root() {
		if ( ! class_exists( ZipArchive::class ) ) {
			$this->markTestSkipped( 'The PHP zip extension is required for release packaging.' );
		}

		$zip_path = $this->create_zip_file(
			array(
				'README.md' => '# Wrong root',
			)
		);

		$result = $this->run_verify_release_zip_script( array( '--zip=' . $zip_path ) );

		$this->assertSame( 1, $result['exit_code'] );
		$this->assertStringContainsString( 'Release zip contains a path outside the plugin root: README.md', $result['stderr'] );
	}

	/**
	 * The release zip verifier rejects traversal-style zip entry paths.
	 */
	public function test_verify_release_zip_script_rejects_unsafe_entry_paths() {
		if ( ! class_exists( ZipArchive::class ) ) {
			$this->markTestSkipped( 'The PHP zip extension is required for release packaging.' );
		}

		$zip_path = $this->create_zip_file(
			array(
				ReleasePackageBuilder::PLUGIN_SLUG . '/../evil.php' => "<?php\n",
			)
		);

		$result = $this->run_verify_release_zip_script( array( '--zip=' . $zip_path ) );

		$this->assertSame( 1, $result['exit_code'] );
		$this->assertStringContainsString( 'Release zip contains an unsafe entry path: universal-wordpress-importer/../evil.php', $result['stderr'] );

		$zip_path = $this->create_zip_file(
			array(
				ReleasePackageBuilder::PLUGIN_SLUG . '/./Plugin.php' => "<?php\n",
			)
		);

		$result = $this->run_verify_release_zip_script( array( '--zip=' . $zip_path ) );

		$this->assertSame( 1, $result['exit_code'] );
		$this->assertStringContainsString( 'Release zip contains an unsafe entry path: universal-wordpress-importer/./Plugin.php', $result['stderr'] );

		$zip_path = $this->create_zip_file(
			array(
				ReleasePackageBuilder::PLUGIN_SLUG . '//Plugin.php' => "<?php\n",
			)
		);

		$result = $this->run_verify_release_zip_script( array( '--zip=' . $zip_path ) );

		$this->assertSame( 1, $result['exit_code'] );
		$this->assertStringContainsString( 'Release zip contains an unsafe entry path: universal-wordpress-importer//Plugin.php', $result['stderr'] );
	}

	/**
	 * The release zip verifier rejects duplicate zip entry paths.
	 */
	public function test_verify_release_zip_script_rejects_duplicate_entry_paths() {
		if ( ! class_exists( ZipArchive::class ) ) {
			$this->markTestSkipped( 'The PHP zip extension is required for release packaging.' );
		}

		$path     = ReleasePackageBuilder::PLUGIN_SLUG . '/README.md';
		$zip_path = $this->create_zip_file_with_duplicate_entry( $path );

		$result = $this->run_verify_release_zip_script( array( '--zip=' . $zip_path ) );

		$this->assertSame( 1, $result['exit_code'] );
		$this->assertStringContainsString( 'Release zip contains duplicate entry path: universal-wordpress-importer/README.md', $result['stderr'] );
	}

	/**
	 * The release zip verifier rejects symlink entries.
	 */
	public function test_verify_release_zip_script_rejects_symlink_entries() {
		if ( ! class_exists( ZipArchive::class ) || ! method_exists( ZipArchive::class, 'setExternalAttributesName' ) ) {
			$this->markTestSkipped( 'The PHP zip extension must support external attributes for this release verifier test.' );
		}

		$path     = ReleasePackageBuilder::PLUGIN_SLUG . '/linked-readme.md';
		$zip_path = $this->create_zip_file_with_symlink_entry( $path );

		$result = $this->run_verify_release_zip_script( array( '--zip=' . $zip_path ) );

		$this->assertSame( 1, $result['exit_code'] );
		$this->assertStringContainsString( 'Release zip contains symlink entry path: universal-wordpress-importer/linked-readme.md', $result['stderr'] );
	}

	/**
	 * The release zip verifier rejects packages missing required runtime files.
	 */
	public function test_verify_release_zip_script_rejects_missing_required_runtime_paths() {
		if ( ! class_exists( ZipArchive::class ) ) {
			$this->markTestSkipped( 'The PHP zip extension is required for release packaging.' );
		}

		$root     = ReleasePackageBuilder::PLUGIN_SLUG . '/';
		$zip_path = $this->create_zip_file(
			array(
				$root . 'universal-wordpress-importer.php' => "<?php\n",
				$root . 'composer.json'                    => "{}\n",
				$root . 'composer.lock'                    => "{}\n",
				$root . 'src/Plugin.php'                   => "<?php\n",
				$root . 'README.md'                        => "# Universal WordPress Importer\n",
				$root . 'readme.txt'                       => "=== Universal WordPress Importer ===\n",
				$root . 'CHANGELOG.md'                     => "# Changelog\n",
				$root . 'docs/release-packaging.md'        => "# Release Packaging\n",
			)
		);

		$result = $this->run_verify_release_zip_script( array( '--zip=' . $zip_path ) );

		$this->assertSame( 1, $result['exit_code'] );
		$this->assertStringContainsString( 'Release zip is missing required runtime path: universal-wordpress-importer/vendor/autoload.php', $result['stderr'] );
	}

	/**
	 * The release zip verifier rejects packages that include excluded release tooling.
	 */
	public function test_verify_release_zip_script_rejects_excluded_paths() {
		if ( ! class_exists( ZipArchive::class ) ) {
			$this->markTestSkipped( 'The PHP zip extension is required for release packaging.' );
		}

		$root     = ReleasePackageBuilder::PLUGIN_SLUG . '/';
		$zip_path = $this->create_zip_file(
			array(
				$root . 'universal-wordpress-importer.php' => "<?php\n",
				$root . 'composer.json'                    => "{}\n",
				$root . 'composer.lock'                    => "{}\n",
				$root . 'vendor/autoload.php'              => "<?php\n",
				$root . 'src/Plugin.php'                   => "<?php\n",
				$root . 'README.md'                        => "# Universal WordPress Importer\n",
				$root . 'readme.txt'                       => "=== Universal WordPress Importer ===\n",
				$root . 'CHANGELOG.md'                     => "# Changelog\n",
				$root . 'docs/release-packaging.md'        => "# Release Packaging\n",
				$root . 'tools/build-release.php'          => "<?php\n",
			)
		);

		$result = $this->run_verify_release_zip_script( array( '--zip=' . $zip_path ) );

		$this->assertSame( 1, $result['exit_code'] );
		$this->assertStringContainsString( 'Release zip contains excluded path: universal-wordpress-importer/tools/build-release.php', $result['stderr'] );
	}

	/**
	 * The release zip verifier rejects excluded development trees.
	 */
	public function test_verify_release_zip_script_rejects_excluded_tree_paths() {
		if ( ! class_exists( ZipArchive::class ) ) {
			$this->markTestSkipped( 'The PHP zip extension is required for release packaging.' );
		}

		$root     = ReleasePackageBuilder::PLUGIN_SLUG . '/';
		$zip_path = $this->create_zip_file(
			array(
				$root . 'universal-wordpress-importer.php' => "<?php\n",
				$root . 'composer.json'                    => "{}\n",
				$root . 'composer.lock'                    => "{}\n",
				$root . 'vendor/autoload.php'              => "<?php\n",
				$root . 'src/Plugin.php'                   => "<?php\n",
				$root . 'README.md'                        => "# Universal WordPress Importer\n",
				$root . 'readme.txt'                       => "=== Universal WordPress Importer ===\n",
				$root . 'CHANGELOG.md'                     => "# Changelog\n",
				$root . 'docs/release-packaging.md'        => "# Release Packaging\n",
				$root . 'tests/fixtures/sample.txt'        => "dev fixture\n",
			)
		);

		$result = $this->run_verify_release_zip_script( array( '--zip=' . $zip_path ) );

		$this->assertSame( 1, $result['exit_code'] );
		$this->assertStringContainsString( 'Release zip contains an excluded tree path: universal-wordpress-importer/tests/fixtures/sample.txt', $result['stderr'] );
	}

	/**
	 * Version mismatches fail before a release artifact is produced.
	 */
	public function test_build_fails_when_release_versions_diverge() {
		$repo = $this->temporary_directory();
		$this->write_file(
			$repo . '/universal-wordpress-importer.php',
			"<?php\n/**\n * Plugin Name: Universal WordPress Importer\n * Version: 0.1.0\n */\ndefine( 'UNIVERSAL_IMPORTER_VERSION', '0.2.0' );\n"
		);
		$this->write_file( $repo . '/readme.txt', "=== Universal WordPress Importer ===\nStable tag: 0.1.0\n" );

		$builder = new ReleasePackageBuilder( $repo );

		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'Release version mismatch' );

		$builder->build(
			$repo . '/dist',
			array(
				'allow_dirty'         => true,
				'run_checks'          => false,
				'use_existing_vendor' => true,
			)
		);
	}

	/**
	 * Reads all paths in a zip.
	 *
	 * @param string $zip_path Zip path.
	 * @return string[] Paths.
	 */
	private function zip_paths( $zip_path ) {
		$zip = new ZipArchive();
		$this->assertTrue( true === $zip->open( $zip_path ) );

		$paths = array();

		for ( $index = 0; $index < $zip->numFiles; ++$index ) {
			$paths[] = $zip->getNameIndex( $index );
		}

		$zip->close();
		sort( $paths );

		return $paths;
	}

	/**
	 * Creates a zip fixture with the provided entries.
	 *
	 * @param array<string,string> $entries Entries keyed by zip path.
	 * @return string Zip path.
	 */
	private function create_zip_file( array $entries ) {
		$directory = $this->temporary_directory();
		$zip_path  = $directory . '/fixture.zip';
		$zip       = new ZipArchive();

		$this->assertTrue( true === $zip->open( $zip_path, ZipArchive::CREATE ) );

		foreach ( $entries as $path => $content ) {
			$this->assertTrue( $zip->addFromString( $path, $content ) );
		}

		$this->assertTrue( $zip->close() );

		return $zip_path;
	}

	/**
	 * Creates a zip fixture with the same entry path written twice.
	 *
	 * @param string $path Duplicate zip path.
	 * @return string Zip path.
	 */
	private function create_zip_file_with_duplicate_entry( $path ) {
		$directory = $this->temporary_directory();
		$zip_path  = $directory . '/duplicate.zip';
		$entries   = array(
			array(
				'name'    => $path,
				'content' => "first\n",
			),
			array(
				'name'    => $path,
				'content' => "second\n",
			),
		);
		$body      = '';
		$central   = '';

		foreach ( $entries as $entry ) {
			$name    = $entry['name'];
			$content = $entry['content'];
			$offset  = strlen( $body );
			$crc     = crc32( $content );
			$size    = strlen( $content );

			$body .= pack( 'VvvvvvVVVvv', 0x04034b50, 20, 0, 0, 0, 0, $crc, $size, $size, strlen( $name ), 0 );
			$body .= $name . $content;

			$central .= pack( 'VvvvvvvVVVvvvvvVV', 0x02014b50, 20, 20, 0, 0, 0, 0, $crc, $size, $size, strlen( $name ), 0, 0, 0, 0, 0, $offset );
			$central .= $name;
		}

		$zip_body = $body . $central . pack( 'VvvvvVVv', 0x06054b50, 0, 0, count( $entries ), count( $entries ), strlen( $central ), strlen( $body ), 0 );

		$this->write_file( $zip_path, $zip_body );

		return $zip_path;
	}

	/**
	 * Creates a zip fixture with one Unix symlink entry.
	 *
	 * @param string $path Symlink zip path.
	 * @return string Zip path.
	 */
	private function create_zip_file_with_symlink_entry( $path ) {
		$directory = $this->temporary_directory();
		$zip_path  = $directory . '/symlink.zip';
		$zip       = new ZipArchive();

		$this->assertTrue( true === $zip->open( $zip_path, ZipArchive::CREATE ) );
		$this->assertTrue( $zip->addFromString( $path, 'README.md' ) );
		$this->assertTrue( $zip->setExternalAttributesName( $path, ZipArchive::OPSYS_UNIX, ( 0120777 << 16 ) ) );
		$this->assertTrue( $zip->close() );

		return $zip_path;
	}

	/**
	 * Runs the release zip verifier script.
	 *
	 * @param string[] $args Script arguments.
	 * @return array{exit_code:int,stdout:string,stderr:string} Process result.
	 */
	private function run_verify_release_zip_script( array $args ) {
		return $this->run_php_tool_script( 'verify-release-zip.php', $args );
	}

	/**
	 * Runs the release builder script.
	 *
	 * @param string[] $args Script arguments.
	 * @return array{exit_code:int,stdout:string,stderr:string} Process result.
	 */
	private function run_build_release_script( array $args ) {
		return $this->run_php_tool_script( 'build-release.php', $args );
	}

	/**
	 * Runs a PHP tool script from the repository tools directory.
	 *
	 * @param string   $script Tool script filename.
	 * @param string[] $args   Script arguments.
	 * @return array{exit_code:int,stdout:string,stderr:string} Process result.
	 */
	private function run_php_tool_script( $script, array $args ) {
		$command = array_merge(
			array(
				PHP_BINARY,
				dirname( __DIR__, 3 ) . '/tools/' . $script,
			),
			$args
		);
		$command = implode( ' ', array_map( 'escapeshellarg', $command ) );
		$pipes   = array();

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_proc_open -- Unit test exercises the release verifier as a CLI script.
		$process = proc_open(
			$command,
			array(
				1 => array( 'pipe', 'w' ),
				2 => array( 'pipe', 'w' ),
			),
			$pipes,
			dirname( __DIR__, 3 )
		);

		$this->assertIsResource( $process );

		$stdout = stream_get_contents( $pipes[1] );
		$stderr = stream_get_contents( $pipes[2] );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Unit test closes process pipes it owns.
		fclose( $pipes[1] );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Unit test closes process pipes it owns.
		fclose( $pipes[2] );

		return array(
			'exit_code' => proc_close( $process ),
			'stdout'    => false === $stdout ? '' : $stdout,
			'stderr'    => false === $stderr ? '' : $stderr,
		);
	}

	/**
	 * Creates a temporary directory.
	 *
	 * @return string Directory path.
	 */
	private function temporary_directory() {
		$path = tempnam( sys_get_temp_dir(), 'universal-importer-release-test-' );
		$this->assertNotFalse( $path );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Unit test owns this temporary fixture path.
		$this->assertTrue( unlink( $path ) );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Unit test creates isolated temporary fixture directories.
		$this->assertTrue( mkdir( $path, 0777, true ) );

		$this->temporary_paths[] = $path;

		return $path;
	}

	/**
	 * Creates a small repository fixture for package content assertions.
	 *
	 * @return string Repository path.
	 */
	private function create_release_fixture_repo() {
		$repo = $this->temporary_directory();

		$this->write_file(
			$repo . '/universal-wordpress-importer.php',
			"<?php\n/**\n * Plugin Name: Universal WordPress Importer\n * Version: 0.1.0\n */\ndefine( 'UNIVERSAL_IMPORTER_VERSION', '0.1.0' );\n"
		);
		$this->write_file( $repo . '/readme.txt', "=== Universal WordPress Importer ===\nStable tag: 0.1.0\n" );
		$this->write_file( $repo . '/README.md', "# Universal WordPress Importer\n" );
		$this->write_file( $repo . '/CHANGELOG.md', "# Changelog\n" );
		$this->write_file( $repo . '/composer.json', "{}\n" );
		$this->write_file( $repo . '/composer.lock', "{}\n" );
		$this->write_file( $repo . '/src/Plugin.php', "<?php\n" );
		$this->write_file( $repo . '/vendor/autoload.php', "<?php\n" );
		$this->write_file( $repo . '/vendor/bin/phpunit', "#!/usr/bin/env php\n" );
		$this->write_file( $repo . '/vendor/example/package/tests/FixtureTest.php', "<?php\n" );
		$this->write_file( $repo . '/docs/release-packaging.md', "# Release Packaging\n" );
		$this->write_file( $repo . '/tests/bootstrap.php', "<?php\n" );
		$this->write_file( $repo . '/phpunit.xml.dist', "<phpunit />\n" );
		$this->write_file( $repo . '/phpcs.xml.dist', "<ruleset />\n" );
		$this->write_file( $repo . '/run_autonomous_loop.sh', "#!/usr/bin/env bash\n" );
		$this->write_file( $repo . '/scripts/codex-loop.sh', "#!/usr/bin/env bash\n" );
		$this->write_file( $repo . '/tools/build-release.php', "<?php\n" );
		$this->write_file( $repo . '/.autonomous-loop/goal.md', "# Goal\n" );
		$this->write_file(
			$repo . '/.distignore',
			".git\n.gitignore\n.distignore\n.autonomous-loop\n.codex-loop\ndist\ntests\ntools\nvendor/bin\nphpunit.xml.dist\nphpcs.xml.dist\nrun_autonomous_loop.sh\nscripts/codex-loop.sh\n"
		);

		return $repo;
	}

	/**
	 * Writes a fixture file.
	 *
	 * @param string $path    File path.
	 * @param string $content File content.
	 */
	private function write_file( $path, $content ) {
		$directory = dirname( $path );

		if ( ! is_dir( $directory ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Unit test creates isolated temporary fixture directories.
			$this->assertTrue( mkdir( $directory, 0777, true ) );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Unit test writes isolated temporary fixture files.
		$this->assertNotFalse( file_put_contents( $path, $content ) );
	}

	/**
	 * Removes a file tree.
	 *
	 * @param string $path Path.
	 */
	private function remove_tree( $path ) {
		if ( ! file_exists( $path ) ) {
			return;
		}

		if ( is_file( $path ) || is_link( $path ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Unit test owns this temporary fixture path.
			unlink( $path );
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

			$this->remove_tree( $path . '/' . $item );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Unit test removes isolated temporary fixture directories.
		rmdir( $path );
	}
}
