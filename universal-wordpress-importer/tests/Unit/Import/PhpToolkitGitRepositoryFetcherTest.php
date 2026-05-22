<?php
/**
 * Tests for the php-toolkit GitHub repository fetcher.
 *
 * @package UniversalImporter\Tests\Unit\Import
 */

namespace UniversalImporter\Tests\Unit\Import;

// phpcs:disable WordPress.WP.AlternativeFunctions -- Unit fixtures use isolated temporary files without WordPress loaded.

use PHPUnit\Framework\TestCase;
use RuntimeException;
use UniversalImporter\Import\ImportCacheDirectory;
use UniversalImporter\Import\ImportSession;
use UniversalImporter\Import\PhpToolkitGitRepositoryFetcher;

/**
 * Guards the php-toolkit Git fetcher against refs that would crash the
 * filesystem layer (e.g. branch names containing a slash).
 */
final class PhpToolkitGitRepositoryFetcherTest extends TestCase {
	/**
	 * Temporary paths to remove after tests.
	 *
	 * @var array<int,string>
	 */
	private $temporary_paths = array();

	/**
	 * Cleans temporary filesystem fixtures.
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
	 * The directory listing rejects refs containing a slash with a RuntimeException
	 * so the admin's candidate-fallback loop reliably catches it. Without this
	 * guard, the underlying GitRepository writes "refs/heads/trunk/docs" while a
	 * sibling step writes "trunk" as a file, producing a fatal
	 * WordPress\Filesystem\FilesystemException that bypasses the admin's
	 * RuntimeException catch and surfaces as a 500.
	 *
	 * @return void
	 */
	public function test_list_root_directories_rejects_ref_with_slash() {
		$cache_directory = new ImportCacheDirectory( $this->temporary_directory() . '/cache' );
		$fetcher         = new PhpToolkitGitRepositoryFetcher();

		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'Invalid Git ref: branch names cannot contain a slash.' );

		$fetcher->list_root_directories(
			array(
				'owner'       => 'WordPress',
				'name'        => 'gutenberg',
				'ref'         => 'trunk/docs',
				'source_path' => '',
				'source_url'  => 'https://github.com/WordPress/gutenberg/tree/trunk/docs',
			),
			$cache_directory
		);
	}

	/**
	 * The same guard applies to the actual-import path so a manual import of
	 * "tree/trunk/docs" surfaces a recoverable RuntimeException rather than a
	 * filesystem write failure.
	 *
	 * @return void
	 */
	public function test_fetch_rejects_ref_with_slash() {
		$cache_directory = new ImportCacheDirectory( $this->temporary_directory() . '/cache' );
		$session         = ImportSession::start_for_source( 'https://github.com/WordPress/gutenberg/tree/trunk/docs' );
		$fetcher         = new PhpToolkitGitRepositoryFetcher();

		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'Invalid Git ref: branch names cannot contain a slash.' );

		$fetcher->fetch(
			$session,
			array(
				'owner'       => 'WordPress',
				'name'        => 'gutenberg',
				'ref'         => 'trunk/docs',
				'source_path' => '',
				'source_url'  => 'https://github.com/WordPress/gutenberg/tree/trunk/docs',
			),
			$cache_directory
		);
	}

	/**
	 * Refs without a slash (the common case) continue past the guard and reach
	 * the toolkit/network layer. We don't have a live remote in unit tests, so
	 * the call must fail for a different reason than the slash guard — the
	 * point is that the slash guard does not poison legitimate refs.
	 *
	 * @return void
	 */
	public function test_list_root_directories_accepts_ref_without_slash() {
		$cache_directory = new ImportCacheDirectory( $this->temporary_directory() . '/cache' );
		$fetcher         = new PhpToolkitGitRepositoryFetcher();

		try {
			$fetcher->list_root_directories(
				array(
					'owner'       => 'example',
					'name'        => 'unreachable-repository-for-unit-test',
					'ref'         => 'trunk',
					'source_path' => '',
					'source_url'  => 'https://example.invalid/example/unreachable',
				),
				$cache_directory
			);

			$this->fail( 'Expected the unreachable-remote call to fail at the network or filesystem layer.' );
		} catch ( RuntimeException $exception ) {
			$this->assertStringNotContainsString(
				'branch names cannot contain a slash',
				$exception->getMessage(),
				'The slash guard must not fire for legitimate refs without a slash.'
			);
		}
	}

	/**
	 * Creates a temporary directory fixture.
	 *
	 * @return string
	 */
	private function temporary_directory() {
		$path = sys_get_temp_dir() . '/universal-importer-php-toolkit-git-test-' . bin2hex( random_bytes( 6 ) );

		mkdir( $path );
		chmod( $path, 0777 );
		$this->temporary_paths[] = $path;

		return $path;
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

		foreach ( scandir( $path ) as $entry ) {
			if ( '.' === $entry || '..' === $entry ) {
				continue;
			}

			$this->remove_path( rtrim( $path, DIRECTORY_SEPARATOR ) . DIRECTORY_SEPARATOR . $entry );
		}

		rmdir( $path );
	}
}
