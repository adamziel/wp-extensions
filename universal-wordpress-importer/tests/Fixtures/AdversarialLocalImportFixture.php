<?php
/**
 * Filesystem fixture for adversarial local import runner tests.
 *
 * @package UniversalImporter\Tests\Fixtures
 */

namespace UniversalImporter\Tests\Fixtures;

// phpcs:disable WordPress.WP.AlternativeFunctions -- Unit fixtures use isolated temporary files without WordPress loaded.

/**
 * Creates and removes a local import tree with mixed edge cases.
 */
final class AdversarialLocalImportFixture {
	/**
	 * Fixture root path.
	 *
	 * @var string
	 */
	private $root;

	/**
	 * Constructor.
	 *
	 * @param string $root Fixture root path.
	 */
	private function __construct( $root ) {
		$this->root = $root;
	}

	/**
	 * Creates a mixed local import tree.
	 *
	 * @return self
	 */
	public static function create() {
		$root = sys_get_temp_dir() . '/universal-importer-fixture-' . bin2hex( random_bytes( 6 ) );
		mkdir( $root );
		mkdir( $root . '/chapters' );
		mkdir( $root . '/duplicates' );

		file_put_contents( $root . '/index.md', "# Index\n\nWelcome." );
		file_put_contents( $root . '/ambiguous.html', '<h1>Ambiguous</h1><figure class="wp-block-image"><img src="/ambiguous.jpg" alt="Ambiguous"><figcaption>Ambiguous caption.</figcaption><div class="source-credit">Editorial credit that must stay with the imported figure.</div></figure>' );
		file_put_contents( $root . '/chapters/one.md', "# One\n\nChapter body." );
		file_put_contents( $root . '/legacy.html', '<h1>Legacy</h1><script>alert("x")</script><p><a href="javascript:alert(1)" onclick="steal()" style="background:url(javascript:alert(1))">Unsafe link</a> Safe body.</p>' );
		file_put_contents( $root . '/large-notes.txt', "# Large Notes\n\n" . str_repeat( "This paragraph makes the local text fixture cross the streamed read chunk boundary.\n\n", 1200 ) );
		file_put_contents( $root . '/malformed.html', '<h1>Malformed</h1><p><strong>Broken local HTML still imports.' );
		file_put_contents( $root . '/notes.txt', "Plain notes.\n\nSecond paragraph." );
		file_put_contents( $root . '/unsupported.bin', 'binary-ish' );
		file_put_contents( $root . '/duplicates/retry.md', "# Retry\n\nThis file should not create duplicate posts." );

		return new self( $root );
	}

	/**
	 * Returns a path guaranteed not to exist.
	 *
	 * @return string
	 */
	public static function missing_path() {
		return sys_get_temp_dir() . '/universal-importer-missing-' . bin2hex( random_bytes( 6 ) ) . '/missing.md';
	}

	/**
	 * Returns the fixture root.
	 *
	 * @return string
	 */
	public function root() {
		return $this->root;
	}

	/**
	 * Removes the fixture tree.
	 *
	 * @return void
	 */
	public function remove() {
		$this->remove_path( $this->root );
	}

	/**
	 * Recursively removes a path.
	 *
	 * @param string $path Path to remove.
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
