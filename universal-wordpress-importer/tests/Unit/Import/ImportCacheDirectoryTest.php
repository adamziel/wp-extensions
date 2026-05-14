<?php
/**
 * Tests for importer-managed cache directory resolution.
 *
 * @package UniversalImporter\Tests\Unit\Import
 */

namespace UniversalImporter\Tests\Unit\Import;

// phpcs:disable WordPress.WP.AlternativeFunctions -- Unit fixtures use isolated temporary files without WordPress loaded.

use PHPUnit\Framework\TestCase;
use RuntimeException;
use UniversalImporter\Import\ImportCacheDirectory;
use UniversalImporter\Import\ImportSessionId;

/**
 * Covers managed cache pathing without a full WordPress runtime.
 */
final class ImportCacheDirectoryTest extends TestCase {
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
	 * WordPress upload data resolves to an importer-managed cache root.
	 *
	 * @return void
	 */
	public function test_wordpress_uploads_cache_paths_are_namespaced_by_session() {
		$uploads = $this->temporary_directory();
		$cache   = ImportCacheDirectory::from_wordpress_upload_dir(
			array(
				'basedir' => $uploads,
				'error'   => false,
			)
		);
		$id      = ImportSessionId::from_string( 'import_1234567890abcdef1234567890abcdef' );
		$path    = $cache->path_for( $id, 'github', array( 'repository.zip' ) );

		$this->assertSame( $uploads . '/universal-importer-cache', $cache->get_root() );
		$this->assertSame( $uploads . '/universal-importer-cache/github/import_1234567890abcdef1234567890abcdef/repository.zip', $path );
		$this->assertSame(
			array(
				'cache_backend'   => 'wordpress-uploads',
				'cache_namespace' => 'github',
				'cache_root'      => $uploads . '/universal-importer-cache',
				'cache_path'      => $path,
			),
			$cache->metadata_for( 'github', $path )
		);
	}

	/**
	 * Upload-directory failures remain actionable at cache use time.
	 *
	 * @return void
	 */
	public function test_wordpress_upload_errors_are_actionable() {
		$cache = ImportCacheDirectory::from_wordpress_upload_dir(
			array(
				'error' => 'Disk is full.',
			)
		);

		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'WordPress upload directory is unavailable for importer cache: Disk is full.' );

		$cache->path_for( ImportSessionId::from_string( 'import_1234567890abcdef1234567890abcdef' ), 'github', array( 'repository.zip' ) );
	}

	/**
	 * Cleanup removes importer-owned cache files for a session only.
	 *
	 * @return void
	 */
	public function test_session_cleanup_removes_known_cache_namespaces() {
		$root  = $this->temporary_directory() . '/cache';
		$cache = new ImportCacheDirectory( $root );
		$id    = ImportSessionId::from_string( 'import_1234567890abcdef1234567890abcdef' );

		$github_path  = $cache->path_for( $id, 'github', array( 'repository.zip' ) );
		$archive_path = $cache->path_for( $id, 'archives', array( 'parent', 'entry.md' ) );
		$epub_path    = $cache->path_for( $id, 'epub', array( 'asset', 'image.jpg' ) );
		$browser_path = $cache->path_for( $id, 'browser-uploads', array( 'tree', 'entry.md' ) );
		$other_path   = $cache->path_for( ImportSessionId::from_string( 'import_fedcba0987654321fedcba0987654321' ), 'github', array( 'repository.zip' ) );

		$cache->ensure_parent_directory( $github_path );
		$cache->ensure_parent_directory( $archive_path );
		$cache->ensure_parent_directory( $epub_path );
		$cache->ensure_parent_directory( $browser_path );
		$cache->ensure_parent_directory( $other_path );
		file_put_contents( $github_path, 'zip' );
		file_put_contents( $archive_path, 'markdown' );
		file_put_contents( $epub_path, 'image' );
		file_put_contents( $browser_path, 'browser' );
		file_put_contents( $other_path, 'other' );

		$cache->remove_session( $id );

		$this->assertFalse( file_exists( $github_path ) );
		$this->assertFalse( file_exists( $archive_path ) );
		$this->assertFalse( file_exists( $epub_path ) );
		$this->assertFalse( file_exists( $browser_path ) );
		$this->assertTrue( file_exists( $other_path ) );
	}

	/**
	 * Nested cache creation restores traversal after restrictive process umasks.
	 *
	 * @return void
	 */
	public function test_nested_cache_directories_are_traversable_after_restrictive_umask() {
		$root = $this->temporary_directory() . '/cache';
		$id   = ImportSessionId::from_string( 'import_1234567890abcdef1234567890abcdef' );
		$path = ( new ImportCacheDirectory( $root ) )->path_for( $id, 'browser-uploads', array( 'tree', 'folder', 'entry.md' ) );
		$mask = umask( 0117 );

		try {
			( new ImportCacheDirectory( $root ) )->ensure_parent_directory( $path );
		} finally {
			umask( $mask );
		}

		$this->assertDirectoryExists( dirname( $path ) );
		$this->assertTrue( is_readable( dirname( $path ) ) );
		$this->assertTrue( is_writable( dirname( $path ) ) );
		$this->assertTrue( is_executable( dirname( $path ) ) );
	}

	/**
	 * Creates a temporary directory fixture.
	 *
	 * @return string
	 */
	private function temporary_directory() {
		$path = sys_get_temp_dir() . '/universal-importer-cache-test-' . bin2hex( random_bytes( 6 ) );

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
