<?php
/**
 * Tests for the WordPress remote archive fetcher.
 *
 * @package UniversalImporter\Tests\Unit\Import
 */

namespace UniversalImporter\Tests\Unit\Import;

// phpcs:disable WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_putenv, WordPress.WP.AlternativeFunctions -- Tests must isolate env-based runtime config and temporary archive files.

use PHPUnit\Framework\TestCase;
use UniversalImporter\Import\WordPressRemoteArchiveFetcher;

/**
 * Covers remote archive request headers without a full WordPress runtime.
 */
final class WordPressRemoteArchiveFetcherTest extends TestCase {
	/**
	 * Previous GitHub token environment value.
	 *
	 * @var string|false
	 */
	private $previous_token;

	/**
	 * Temporary files to remove.
	 *
	 * @var array<int,string>
	 */
	private $temporary_paths = array();

	/**
	 * Sets up the WordPress HTTP test double.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->previous_token = getenv( 'UNIVERSAL_IMPORTER_GITHUB_TOKEN' );
		putenv( 'UNIVERSAL_IMPORTER_GITHUB_TOKEN' );
		WordPressRemoteContentFetcherWpStub::reset();
	}

	/**
	 * Restores environment and temporary files.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		foreach ( $this->temporary_paths as $path ) {
			if ( is_file( $path ) ) {
				unlink( $path );
			}
		}

		if ( false === $this->previous_token ) {
			putenv( 'UNIVERSAL_IMPORTER_GITHUB_TOKEN' );
		} else {
			putenv( 'UNIVERSAL_IMPORTER_GITHUB_TOKEN=' . $this->previous_token );
		}

		WordPressRemoteContentFetcherWpStub::reset();
		parent::tearDown();
	}

	/**
	 * GitHub archive downloads send the dedicated GitHub token to api.github.com.
	 *
	 * @return void
	 */
	public function test_fetch_sends_github_token_to_github_api_host() {
		putenv( 'UNIVERSAL_IMPORTER_GITHUB_TOKEN=github-token' );
		WordPressRemoteContentFetcherWpStub::queue_response(
			array(
				'response' => array( 'code' => 200 ),
				'body'     => 'zip-fixture',
				'headers'  => array(),
			)
		);
		$target = $this->temporary_file_path();

		$result  = ( new WordPressRemoteArchiveFetcher() )->fetch( 'https://api.github.com/repos/example/repository/zipball/main', $target );
		$request = WordPressRemoteContentFetcherWpStub::last_request();

		$this->assertSame( strlen( 'zip-fixture' ), $result['bytes'] );
		$this->assertSame( 'Bearer github-token', $request['args']['headers']['Authorization'] );
		$this->assertSame( 'application/vnd.github+json', $request['args']['headers']['Accept'] );
		$this->assertSame( '2022-11-28', $request['args']['headers']['X-GitHub-Api-Version'] );
	}

	/**
	 * GitHub-specific credentials and API headers are not sent to other archive hosts.
	 *
	 * @return void
	 */
	public function test_fetch_does_not_send_github_token_to_other_archive_hosts() {
		putenv( 'UNIVERSAL_IMPORTER_GITHUB_TOKEN=github-token' );
		WordPressRemoteContentFetcherWpStub::queue_response(
			array(
				'response' => array( 'code' => 200 ),
				'body'     => 'zip-fixture',
				'headers'  => array(),
			)
		);
		$target = $this->temporary_file_path();

		( new WordPressRemoteArchiveFetcher() )->fetch( 'https://downloads.example.test/archive.zip', $target );
		$request = WordPressRemoteContentFetcherWpStub::last_request();

		$this->assertArrayNotHasKey( 'Authorization', $request['args']['headers'] );
		$this->assertArrayNotHasKey( 'Accept', $request['args']['headers'] );
		$this->assertArrayNotHasKey( 'X-GitHub-Api-Version', $request['args']['headers'] );
	}

	/**
	 * Creates a temporary target path.
	 *
	 * @return string
	 */
	private function temporary_file_path() {
		$path = tempnam( sys_get_temp_dir(), 'uwi-archive-' );
		$this->assertIsString( $path );
		$this->temporary_paths[] = $path;

		return $path;
	}
}
