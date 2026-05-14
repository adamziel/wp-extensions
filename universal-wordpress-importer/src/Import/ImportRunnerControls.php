<?php
/**
 * Hidden controls for exercising import runner failure paths.
 *
 * @package UniversalImporter
 */

namespace UniversalImporter\Import;

use InvalidArgumentException;

/**
 * Captures bounded test controls for one runner tick.
 */
final class ImportRunnerControls {
	const DEFAULT_MEMORY_PRESSURE_BYTES = 1048576;
	const MAX_MEMORY_PRESSURE_BYTES     = 16777216;

	/**
	 * Whether to throw a controlled crash after the session lock is acquired.
	 *
	 * @var bool
	 */
	private $simulate_crash;

	/**
	 * Whether to terminate the PHP process after durable lock/event writes.
	 *
	 * @var bool
	 */
	private $simulate_fatal_exit;

	/**
	 * Whether to terminate PHP after a durable Markdown byte cursor is written.
	 *
	 * @var bool
	 */
	private $simulate_fatal_after_markdown_cursor;

	/**
	 * Whether to terminate PHP after a durable WXR cursor is written.
	 *
	 * @var bool
	 */
	private $simulate_fatal_after_wxr_cursor;

	/**
	 * Whether to terminate PHP after a durable EPUB spine cursor is written.
	 *
	 * @var bool
	 */
	private $simulate_fatal_after_epub_spine_cursor;

	/**
	 * Whether to terminate PHP after a durable zip entry cursor is written.
	 *
	 * @var bool
	 */
	private $simulate_fatal_after_zip_entry_cursor;

	/**
	 * Whether to terminate PHP after a durable REST page cursor is written.
	 *
	 * @var bool
	 */
	private $simulate_fatal_after_rest_page_cursor;

	/**
	 * Whether to terminate PHP after a durable GitHub tree cursor is written.
	 *
	 * @var bool
	 */
	private $simulate_fatal_after_github_tree_cursor;

	/**
	 * Whether to terminate PHP after a post write but before idempotency is recorded.
	 *
	 * @var bool
	 */
	private $simulate_fatal_after_post_write;

	/**
	 * Whether to terminate PHP after an attachment write but before idempotency is recorded.
	 *
	 * @var bool
	 */
	private $simulate_fatal_after_media_write;

	/**
	 * Whether to terminate PHP after a comment write but before idempotency is recorded.
	 *
	 * @var bool
	 */
	private $simulate_fatal_after_comment_write;

	/**
	 * Whether to terminate PHP after a durable PDF structure cursor is written.
	 *
	 * @var bool
	 */
	private $simulate_fatal_after_pdf_structure_cursor;

	/**
	 * Whether to throw after a post write but before idempotency is recorded.
	 *
	 * @var bool
	 */
	private $simulate_post_idempotency_crash;

	/**
	 * Whether to throw after an attachment write but before idempotency is recorded.
	 *
	 * @var bool
	 */
	private $simulate_media_idempotency_crash;

	/**
	 * Whether to throw after a comment write but before idempotency is recorded.
	 *
	 * @var bool
	 */
	private $simulate_comment_idempotency_crash;

	/**
	 * Whether to stop after recording a timeout-budget event.
	 *
	 * @var bool
	 */
	private $simulate_timeout;

	/**
	 * Bytes to allocate briefly before continuing.
	 *
	 * @var int
	 */
	private $memory_pressure_bytes;

	/**
	 * Maximum sessions to inspect in this tick.
	 *
	 * @var int|null
	 */
	private $max_ticks;

	/**
	 * Constructor.
	 *
	 * @param bool     $simulate_crash                   Whether to simulate a crash.
	 * @param bool     $simulate_timeout                   Whether to simulate a timeout.
	 * @param int      $memory_pressure_bytes              Bytes to allocate briefly.
	 * @param int|null $max_ticks                          Optional maximum sessions to inspect.
	 * @param bool     $simulate_post_idempotency_crash    Whether to simulate a post/idempotency crash gap.
	 * @param bool     $simulate_media_idempotency_crash   Whether to simulate a media/idempotency crash gap.
	 * @param bool     $simulate_comment_idempotency_crash Whether to simulate a comment/idempotency crash gap.
	 * @param bool     $simulate_fatal_exit                Whether to terminate the PHP process after lock acquisition.
	 * @param bool     $simulate_fatal_after_markdown_cursor Whether to terminate after a Markdown cursor write.
	 * @param bool     $simulate_fatal_after_post_write      Whether to terminate after a post write.
	 * @param bool     $simulate_fatal_after_media_write     Whether to terminate after an attachment write.
	 * @param bool     $simulate_fatal_after_comment_write   Whether to terminate after a comment write.
	 * @param bool     $simulate_fatal_after_pdf_structure_cursor Whether to terminate after a PDF structure cursor write.
	 * @param bool     $simulate_fatal_after_wxr_cursor     Whether to terminate after a WXR cursor write.
	 * @param bool     $simulate_fatal_after_epub_spine_cursor Whether to terminate after an EPUB spine cursor write.
	 * @param bool     $simulate_fatal_after_zip_entry_cursor Whether to terminate after a zip entry cursor write.
	 * @param bool     $simulate_fatal_after_rest_page_cursor Whether to terminate after a REST page cursor write.
	 * @param bool     $simulate_fatal_after_github_tree_cursor Whether to terminate after a GitHub tree cursor write.
	 * @throws InvalidArgumentException When numeric controls are invalid.
	 */
	public function __construct( $simulate_crash = false, $simulate_timeout = false, $memory_pressure_bytes = 0, $max_ticks = null, $simulate_post_idempotency_crash = false, $simulate_media_idempotency_crash = false, $simulate_comment_idempotency_crash = false, $simulate_fatal_exit = false, $simulate_fatal_after_markdown_cursor = false, $simulate_fatal_after_post_write = false, $simulate_fatal_after_media_write = false, $simulate_fatal_after_comment_write = false, $simulate_fatal_after_pdf_structure_cursor = false, $simulate_fatal_after_wxr_cursor = false, $simulate_fatal_after_epub_spine_cursor = false, $simulate_fatal_after_zip_entry_cursor = false, $simulate_fatal_after_rest_page_cursor = false, $simulate_fatal_after_github_tree_cursor = false ) {
		$memory_pressure_bytes = (int) $memory_pressure_bytes;

		if ( $memory_pressure_bytes < 0 ) {
			throw new InvalidArgumentException( 'Memory pressure simulation bytes cannot be negative.' );
		}

		if ( self::MAX_MEMORY_PRESSURE_BYTES < $memory_pressure_bytes ) {
			throw new InvalidArgumentException( 'Memory pressure simulation is capped at 16777216 bytes.' );
		}

		if ( null !== $max_ticks ) {
			$max_ticks = (int) $max_ticks;

			if ( $max_ticks < 1 ) {
				throw new InvalidArgumentException( 'Maximum runner ticks must be at least one.' );
			}
		}

		$this->simulate_crash                            = (bool) $simulate_crash;
		$this->simulate_timeout                          = (bool) $simulate_timeout;
		$this->memory_pressure_bytes                     = $memory_pressure_bytes;
		$this->max_ticks                                 = $max_ticks;
		$this->simulate_post_idempotency_crash           = (bool) $simulate_post_idempotency_crash;
		$this->simulate_media_idempotency_crash          = (bool) $simulate_media_idempotency_crash;
		$this->simulate_comment_idempotency_crash        = (bool) $simulate_comment_idempotency_crash;
		$this->simulate_fatal_exit                       = (bool) $simulate_fatal_exit;
		$this->simulate_fatal_after_markdown_cursor      = (bool) $simulate_fatal_after_markdown_cursor;
		$this->simulate_fatal_after_post_write           = (bool) $simulate_fatal_after_post_write;
		$this->simulate_fatal_after_media_write          = (bool) $simulate_fatal_after_media_write;
		$this->simulate_fatal_after_comment_write        = (bool) $simulate_fatal_after_comment_write;
		$this->simulate_fatal_after_pdf_structure_cursor = (bool) $simulate_fatal_after_pdf_structure_cursor;
		$this->simulate_fatal_after_wxr_cursor           = (bool) $simulate_fatal_after_wxr_cursor;
		$this->simulate_fatal_after_epub_spine_cursor    = (bool) $simulate_fatal_after_epub_spine_cursor;
		$this->simulate_fatal_after_zip_entry_cursor     = (bool) $simulate_fatal_after_zip_entry_cursor;
		$this->simulate_fatal_after_rest_page_cursor     = (bool) $simulate_fatal_after_rest_page_cursor;
		$this->simulate_fatal_after_github_tree_cursor   = (bool) $simulate_fatal_after_github_tree_cursor;
	}

	/**
	 * Creates default no-op controls.
	 *
	 * @return self
	 */
	public static function none() {
		return new self();
	}

	/**
	 * Parses controls from hidden WP-CLI associative arguments.
	 *
	 * @param array<string,string|bool> $assoc_args Associative command arguments.
	 * @return self
	 */
	public static function from_cli_args( array $assoc_args ) {
		return new self(
			array_key_exists( 'simulate-crash', $assoc_args ),
			array_key_exists( 'simulate-timeout', $assoc_args ),
			self::parse_memory_pressure_bytes( $assoc_args ),
			array_key_exists( 'max-ticks', $assoc_args ) ? (int) $assoc_args['max-ticks'] : null,
			array_key_exists( 'simulate-post-idempotency-crash', $assoc_args ),
			array_key_exists( 'simulate-media-idempotency-crash', $assoc_args ),
			array_key_exists( 'simulate-comment-idempotency-crash', $assoc_args ),
			array_key_exists( 'simulate-fatal-exit', $assoc_args ),
			array_key_exists( 'simulate-fatal-after-markdown-cursor', $assoc_args ),
			array_key_exists( 'simulate-fatal-after-post-write', $assoc_args ),
			array_key_exists( 'simulate-fatal-after-media-write', $assoc_args ),
			array_key_exists( 'simulate-fatal-after-comment-write', $assoc_args ),
			array_key_exists( 'simulate-fatal-after-pdf-structure-cursor', $assoc_args ),
			array_key_exists( 'simulate-fatal-after-wxr-cursor', $assoc_args ),
			array_key_exists( 'simulate-fatal-after-epub-spine-cursor', $assoc_args ),
			array_key_exists( 'simulate-fatal-after-zip-entry-cursor', $assoc_args ),
			array_key_exists( 'simulate-fatal-after-rest-page-cursor', $assoc_args ),
			array_key_exists( 'simulate-fatal-after-github-tree-cursor', $assoc_args )
		);
	}

	/**
	 * Whether controlled crash simulation is enabled.
	 *
	 * @return bool
	 */
	public function should_simulate_crash() {
		return $this->simulate_crash;
	}

	/**
	 * Whether real process termination is enabled for recovery proof tests.
	 *
	 * @return bool
	 */
	public function should_simulate_fatal_exit() {
		return $this->simulate_fatal_exit;
	}

	/**
	 * Whether real process termination is enabled after a Markdown cursor write.
	 *
	 * @return bool
	 */
	public function should_simulate_fatal_after_markdown_cursor() {
		return $this->simulate_fatal_after_markdown_cursor;
	}

	/**
	 * Whether real process termination is enabled after a WXR cursor write.
	 *
	 * @return bool
	 */
	public function should_simulate_fatal_after_wxr_cursor() {
		return $this->simulate_fatal_after_wxr_cursor;
	}

	/**
	 * Whether real process termination is enabled after an EPUB spine cursor write.
	 *
	 * @return bool
	 */
	public function should_simulate_fatal_after_epub_spine_cursor() {
		return $this->simulate_fatal_after_epub_spine_cursor;
	}

	/**
	 * Whether real process termination is enabled after a zip entry cursor write.
	 *
	 * @return bool
	 */
	public function should_simulate_fatal_after_zip_entry_cursor() {
		return $this->simulate_fatal_after_zip_entry_cursor;
	}

	/**
	 * Whether real process termination is enabled after a REST page cursor write.
	 *
	 * @return bool
	 */
	public function should_simulate_fatal_after_rest_page_cursor() {
		return $this->simulate_fatal_after_rest_page_cursor;
	}

	/**
	 * Whether real process termination is enabled after a GitHub tree cursor write.
	 *
	 * @return bool
	 */
	public function should_simulate_fatal_after_github_tree_cursor() {
		return $this->simulate_fatal_after_github_tree_cursor;
	}

	/**
	 * Whether real process termination is enabled after a post write.
	 *
	 * @return bool
	 */
	public function should_simulate_fatal_after_post_write() {
		return $this->simulate_fatal_after_post_write;
	}

	/**
	 * Whether real process termination is enabled after an attachment write.
	 *
	 * @return bool
	 */
	public function should_simulate_fatal_after_media_write() {
		return $this->simulate_fatal_after_media_write;
	}

	/**
	 * Whether real process termination is enabled after a comment write.
	 *
	 * @return bool
	 */
	public function should_simulate_fatal_after_comment_write() {
		return $this->simulate_fatal_after_comment_write;
	}

	/**
	 * Whether real process termination is enabled after a PDF structure cursor write.
	 *
	 * @return bool
	 */
	public function should_simulate_fatal_after_pdf_structure_cursor() {
		return $this->simulate_fatal_after_pdf_structure_cursor;
	}

	/**
	 * Whether timeout simulation is enabled.
	 *
	 * @return bool
	 */
	public function should_simulate_timeout() {
		return $this->simulate_timeout;
	}

	/**
	 * Whether to crash after writing a post but before recording idempotency.
	 *
	 * @return bool
	 */
	public function should_simulate_post_idempotency_crash() {
		return $this->simulate_post_idempotency_crash;
	}

	/**
	 * Whether to crash after writing an attachment but before recording idempotency.
	 *
	 * @return bool
	 */
	public function should_simulate_media_idempotency_crash() {
		return $this->simulate_media_idempotency_crash;
	}

	/**
	 * Whether to crash after writing a comment but before recording idempotency.
	 *
	 * @return bool
	 */
	public function should_simulate_comment_idempotency_crash() {
		return $this->simulate_comment_idempotency_crash;
	}

	/**
	 * Bytes to allocate briefly during this tick.
	 *
	 * @return int
	 */
	public function get_memory_pressure_bytes() {
		return $this->memory_pressure_bytes;
	}

	/**
	 * Returns the effective batch limit.
	 *
	 * @param int $default_limit Default runner limit.
	 * @return int
	 */
	public function get_effective_limit( $default_limit ) {
		$default_limit = max( 1, (int) $default_limit );

		if ( null === $this->max_ticks ) {
			return $default_limit;
		}

		return min( $default_limit, $this->max_ticks );
	}

	/**
	 * Parses bounded memory pressure from CLI args.
	 *
	 * @param array<string,string|bool> $assoc_args Associative command arguments.
	 * @return int
	 */
	private static function parse_memory_pressure_bytes( array $assoc_args ) {
		if ( ! array_key_exists( 'simulate-memory-pressure', $assoc_args ) ) {
			return 0;
		}

		$value = $assoc_args['simulate-memory-pressure'];

		if ( true === $value || '' === $value ) {
			return self::DEFAULT_MEMORY_PRESSURE_BYTES;
		}

		return (int) $value;
	}
}
