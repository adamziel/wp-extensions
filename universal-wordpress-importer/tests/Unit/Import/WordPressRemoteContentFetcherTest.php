<?php
/**
 * Tests for the WordPress remote content fetcher.
 *
 * @package UniversalImporter\Tests\Unit\Import
 */

namespace UniversalImporter\Tests\Unit\Import;

// phpcs:disable WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_putenv, WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Tests must isolate env-based runtime config and assert Basic auth encoding.

use PHPUnit\Framework\TestCase;
use RuntimeException;
use UniversalImporter\Import\ImportRemoteRateLimitException;
use UniversalImporter\Import\WordPressRemoteContentFetcher;

/**
 * Covers remote request headers and diagnostics without a full WordPress runtime.
 */
final class WordPressRemoteContentFetcherTest extends TestCase {
	/**
	 * Environment variable names touched by these tests.
	 *
	 * @var array<int,string>
	 */
	private $env_names = array(
		'UNIVERSAL_IMPORTER_REMOTE_AUTH_HOSTS',
		'UNIVERSAL_IMPORTER_REMOTE_BEARER_TOKEN',
		'UNIVERSAL_IMPORTER_REMOTE_BASIC_USER',
		'UNIVERSAL_IMPORTER_REMOTE_BASIC_PASSWORD',
		'UNIVERSAL_IMPORTER_GITHUB_TOKEN',
	);

	/**
	 * Previous environment values.
	 *
	 * @var array<string,string|false>
	 */
	private $previous_env = array();

	/**
	 * Sets up the WordPress HTTP test double.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		foreach ( $this->env_names as $name ) {
			$this->previous_env[ $name ] = getenv( $name );
			putenv( $name );
		}

		WordPressRemoteContentFetcherWpStub::reset();
	}

	/**
	 * Restores environment values changed during tests.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		foreach ( $this->previous_env as $name => $value ) {
			if ( false === $value ) {
				putenv( $name );
			} else {
				putenv( $name . '=' . $value );
			}
		}

		WordPressRemoteContentFetcherWpStub::reset();
		parent::tearDown();
	}

	/**
	 * Bearer tokens are only sent to explicitly configured hosts.
	 *
	 * @return void
	 */
	public function test_fetch_json_sends_bearer_token_to_configured_host() {
		putenv( 'UNIVERSAL_IMPORTER_REMOTE_AUTH_HOSTS=source.example.test, other.example.test' );
		putenv( 'UNIVERSAL_IMPORTER_REMOTE_BEARER_TOKEN=test-token' );
		WordPressRemoteContentFetcherWpStub::queue_response(
			array(
				'response' => array( 'code' => 200 ),
				'body'     => '{"ok":true}',
				'headers'  => array( 'content-type' => 'application/json' ),
			)
		);

		$result  = ( new WordPressRemoteContentFetcher() )->fetch_json( 'https://source.example.test/wp-json/' );
		$request = WordPressRemoteContentFetcherWpStub::last_request();

		$this->assertSame( array( 'ok' => true ), $result );
		$this->assertSame( 'application/json', $request['args']['headers']['Accept'] );
		$this->assertSame( 'Bearer test-token', $request['args']['headers']['Authorization'] );
		$this->assertSame( WordPressRemoteContentFetcher::MAX_BODY_BYTES, $request['args']['limit_response_size'] );
		$this->assertSame( 0, $request['args']['redirection'] );
	}

	/**
	 * GitHub API requests can use the dedicated GitHub token without the remote-source host allow-list.
	 *
	 * @return void
	 */
	public function test_fetch_json_sends_github_token_only_to_github_api_host() {
		putenv( 'UNIVERSAL_IMPORTER_GITHUB_TOKEN=github-token' );
		WordPressRemoteContentFetcherWpStub::queue_response(
			array(
				'response' => array( 'code' => 200 ),
				'body'     => '{"tree":[]}',
				'headers'  => array(),
			)
		);
		WordPressRemoteContentFetcherWpStub::queue_response(
			array(
				'response' => array( 'code' => 200 ),
				'body'     => '{"ok":true}',
				'headers'  => array(),
			)
		);

		( new WordPressRemoteContentFetcher() )->fetch_json( 'https://api.github.com/repos/example/repository/git/trees/main?recursive=1' );
		$github_request = WordPressRemoteContentFetcherWpStub::last_request();
		( new WordPressRemoteContentFetcher() )->fetch_json( 'https://source.example.test/wp-json/' );
		$source_request = WordPressRemoteContentFetcherWpStub::last_request();

		$this->assertSame( 'Bearer github-token', $github_request['args']['headers']['Authorization'] );
		$this->assertSame( '2022-11-28', $github_request['args']['headers']['X-GitHub-Api-Version'] );
		$this->assertSame( 0, $github_request['args']['redirection'] );
		$this->assertArrayNotHasKey( 'Authorization', $source_request['args']['headers'] );
		$this->assertArrayNotHasKey( 'X-GitHub-Api-Version', $source_request['args']['headers'] );
	}

	/**
	 * Configured credentials are not sent to hosts outside the allow-list.
	 *
	 * @return void
	 */
	public function test_fetch_text_does_not_send_credentials_to_unconfigured_host() {
		putenv( 'UNIVERSAL_IMPORTER_REMOTE_AUTH_HOSTS=source.example.test' );
		putenv( 'UNIVERSAL_IMPORTER_REMOTE_BEARER_TOKEN=test-token' );
		WordPressRemoteContentFetcherWpStub::queue_response(
			array(
				'response' => array( 'code' => 200 ),
				'body'     => '<html><body>Public</body></html>',
				'headers'  => array( 'Link' => '<https://outside.example.test/wp-json/>; rel="https://api.w.org/"' ),
			)
		);

		$result  = ( new WordPressRemoteContentFetcher() )->fetch_text( 'https://outside.example.test/' );
		$request = WordPressRemoteContentFetcherWpStub::last_request();

		$this->assertSame( '<html><body>Public</body></html>', $result['body'] );
		$this->assertArrayNotHasKey( 'Authorization', $request['args']['headers'] );
		$this->assertSame( 'text/html,application/xhtml+xml,text/plain;q=0.8,*/*;q=0.1', $request['args']['headers']['Accept'] );
		$this->assertSame( 5, $request['args']['redirection'] );
		$this->assertSame( '<https://outside.example.test/wp-json/>; rel="https://api.w.org/"', $result['headers']['link'] );
	}

	/**
	 * Basic credentials are available for application-password protected sites.
	 *
	 * @return void
	 */
	public function test_fetch_json_sends_basic_auth_when_bearer_token_is_absent() {
		putenv( 'UNIVERSAL_IMPORTER_REMOTE_AUTH_HOSTS=private.example.test' );
		putenv( 'UNIVERSAL_IMPORTER_REMOTE_BASIC_USER=editor' );
		putenv( 'UNIVERSAL_IMPORTER_REMOTE_BASIC_PASSWORD=app password' );
		WordPressRemoteContentFetcherWpStub::queue_response(
			array(
				'response' => array( 'code' => 200 ),
				'body'     => '{"private":true}',
				'headers'  => array(),
			)
		);

		( new WordPressRemoteContentFetcher() )->fetch_json( 'https://private.example.test/wp-json/' );
		$request = WordPressRemoteContentFetcherWpStub::last_request();

		$this->assertSame( 'Basic ' . base64_encode( 'editor:app password' ), $request['args']['headers']['Authorization'] );
	}

	/**
	 * Authentication failures include the configuration path operators should use.
	 *
	 * @return void
	 */
	public function test_authentication_failure_mentions_supported_configuration() {
		WordPressRemoteContentFetcherWpStub::queue_response(
			array(
				'response' => array( 'code' => 401 ),
				'body'     => '{"code":"rest_not_logged_in"}',
				'headers'  => array(),
			)
		);

		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'UNIVERSAL_IMPORTER_REMOTE_AUTH_HOSTS' );
		$this->expectExceptionMessage( 'UNIVERSAL_IMPORTER_REMOTE_BEARER_TOKEN' );
		$this->expectExceptionMessage( 'UNIVERSAL_IMPORTER_REMOTE_BASIC_USER/UNIVERSAL_IMPORTER_REMOTE_BASIC_PASSWORD' );

		( new WordPressRemoteContentFetcher() )->fetch_json( 'https://private.example.test/wp-json/' );
	}

	/**
	 * Authenticated redirects are rejected with a recovery path instead of forwarding credentials.
	 *
	 * @return void
	 */
	public function test_authenticated_redirect_mentions_final_url_guidance() {
		putenv( 'UNIVERSAL_IMPORTER_REMOTE_AUTH_HOSTS=private.example.test' );
		putenv( 'UNIVERSAL_IMPORTER_REMOTE_BEARER_TOKEN=test-token' );
		WordPressRemoteContentFetcherWpStub::queue_response(
			array(
				'response' => array( 'code' => 302 ),
				'body'     => '',
				'headers'  => array( 'Location' => 'https://login.example.test/' ),
			)
		);

		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'HTTP 302 redirect to https://login.example.test/' );
		$this->expectExceptionMessage( 'Authenticated remote requests do not follow redirects' );
		$this->expectExceptionMessage( 'import the final canonical URL' );

		( new WordPressRemoteContentFetcher() )->fetch_text( 'https://private.example.test/private/' );
	}

	/**
	 * Rate-limited responses expose retry diagnostics to the traversal layer.
	 *
	 * @return void
	 */
	public function test_rate_limited_response_throws_retryable_diagnostic() {
		WordPressRemoteContentFetcherWpStub::queue_response(
			array(
				'response' => array( 'code' => 429 ),
				'body'     => '{"code":"too_many_requests"}',
				'headers'  => array( 'Retry-After' => '120' ),
			)
		);

		try {
			( new WordPressRemoteContentFetcher() )->fetch_json( 'https://source.example.test/wp-json/wp/v2/posts' );
			$this->fail( 'Expected a rate-limit exception.' );
		} catch ( ImportRemoteRateLimitException $exception ) {
			$this->assertSame( 'https://source.example.test/wp-json/wp/v2/posts', $exception->get_url() );
			$this->assertSame( 429, $exception->get_status_code() );
			$this->assertSame( '120', $exception->get_retry_after_header() );
			$this->assertSame( 120, $exception->get_retry_after_seconds() );
			$this->assertStringContainsString( 'HTTP 429 rate limit', $exception->getMessage() );
		}
	}
}
