<?php
/**
 * Fake WordPress comment gateway for import tests.
 *
 * @package UniversalImporter\Tests\Unit\Import
 */

namespace UniversalImporter\Tests\Unit\Import;

use RuntimeException;
use UniversalImporter\Import\ImportCommentGatewayInterface;
use UniversalImporter\Import\ImportPreparedDocument;
use UniversalImporter\Import\ImportSessionId;

/**
 * In-memory comment gateway for staged comment persistence tests.
 */
final class FakeCommentGateway implements ImportCommentGatewayInterface {
	/**
	 * Whether comment persistence is available.
	 *
	 * @var bool
	 */
	private $available = true;

	/**
	 * Stored fake comments keyed by id.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	private $comments = array();

	/**
	 * Next fake comment id.
	 *
	 * @var int
	 */
	private $next_id = 1000;

	/**
	 * Comment index keyed by session, source, and remote comment id.
	 *
	 * @var array<string,int>
	 */
	private $comment_index = array();

	/**
	 * Optional failure message.
	 *
	 * @var string|null
	 */
	private $failure_message;

	/**
	 * Optional file path for write-through persistence across child processes.
	 *
	 * @var string|null
	 */
	private $persistence_path;

	/**
	 * Loads a fake comment gateway from a persisted snapshot file.
	 *
	 * @param string $path Snapshot path.
	 * @return self
	 */
	public static function from_persisted_file( $path ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions -- Test-only snapshot reads a local fake gateway file.
		$contents = is_file( $path ) ? file_get_contents( $path ) : false;

		if ( false === $contents ) {
			$instance = new self();
			$instance->persist_to_file( $path );

			return $instance;
		}

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- Test-only snapshot is written by this fake gateway in the same test process.
		$instance = unserialize( $contents, array( 'allowed_classes' => array( self::class ) ) );

		if ( ! $instance instanceof self ) {
			$instance = new self();
		}

		$instance->persist_to_file( $path );

		return $instance;
	}

	/**
	 * Enables write-through persistence to a snapshot file.
	 *
	 * @param string $path Snapshot path.
	 * @return void
	 */
	public function persist_to_file( $path ) {
		$this->persistence_path = (string) $path;
		$this->persist();
	}

	/**
	 * Marks the gateway unavailable.
	 *
	 * @return void
	 */
	public function make_unavailable() {
		$this->available = false;
		$this->persist();
	}

	/**
	 * Makes future writes fail.
	 *
	 * @param string $message Failure message.
	 * @return void
	 */
	public function fail_writes_with( $message ) {
		$this->failure_message = (string) $message;
		$this->persist();
	}

	/**
	 * Whether comment persistence is available in the current runtime.
	 *
	 * @return bool
	 */
	public function is_available() {
		return $this->available;
	}

	/**
	 * Returns a diagnostic when persistence is unavailable.
	 *
	 * @return string
	 */
	public function get_unavailable_reason() {
		return 'Fake comment gateway is unavailable.';
	}

	/**
	 * Finds an already-imported comment by importer metadata.
	 *
	 * @param ImportSessionId $session_id        Session id.
	 * @param string          $source_item_key   Source item key.
	 * @param int             $remote_comment_id Remote comment id.
	 * @return int|null
	 */
	public function find_existing_comment_id( ImportSessionId $session_id, $source_item_key, $remote_comment_id ) {
		$key = $this->index_key( $session_id, $source_item_key, $remote_comment_id );

		return isset( $this->comment_index[ $key ] ) ? $this->comment_index[ $key ] : null;
	}

	/**
	 * Inserts or updates a fake comment.
	 *
	 * @param ImportPreparedDocument $document          Prepared document.
	 * @param array<string,mixed>    $comment           Staged comment metadata.
	 * @param int                    $post_id           Local post id.
	 * @param int                    $parent_comment_id Local parent comment id.
	 * @param int|null               $comment_id        Existing comment id to update.
	 * @return int Persisted comment id.
	 * @throws RuntimeException When configured to fail writes.
	 */
	public function insert_or_update( ImportPreparedDocument $document, array $comment, $post_id, $parent_comment_id = 0, $comment_id = null ) {
		if ( null !== $this->failure_message ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Runtime diagnostics are not rendered directly.
			throw new RuntimeException( $this->failure_message );
		}

		if ( null === $comment_id ) {
			$comment_id = $this->next_id;
			++$this->next_id;
		}

		$comment_id        = (int) $comment_id;
		$remote_comment_id = isset( $comment['remote_comment_id'] ) ? (int) $comment['remote_comment_id'] : 0;

		$this->comments[ $comment_id ] = array(
			'comment_ID'        => $comment_id,
			'comment_post_ID'   => (int) $post_id,
			'comment_parent'    => (int) $parent_comment_id,
			'comment_author'    => isset( $comment['author_name'] ) ? (string) $comment['author_name'] : '',
			'comment_content'   => isset( $comment['content'] ) ? (string) $comment['content'] : '',
			'remote_comment_id' => $remote_comment_id,
			'source_item_key'   => $document->get_source_item_key(),
		);

		$this->comment_index[ $this->index_key( $document->get_session_id(), $document->get_source_item_key(), $remote_comment_id ) ] = $comment_id;
		$this->persist();

		return $comment_id;
	}

	/**
	 * Returns a stored fake comment.
	 *
	 * @param int $comment_id Comment id.
	 * @return array<string,mixed>|null
	 */
	public function get_comment( $comment_id ) {
		return isset( $this->comments[ $comment_id ] ) ? $this->comments[ $comment_id ] : null;
	}

	/**
	 * Returns the number of fake comments.
	 *
	 * @return int
	 */
	public function count_comments() {
		return count( $this->comments );
	}

	/**
	 * Builds the comment index key.
	 *
	 * @param ImportSessionId $session_id        Session id.
	 * @param string          $source_item_key   Source item key.
	 * @param int             $remote_comment_id Remote comment id.
	 * @return string
	 */
	private function index_key( ImportSessionId $session_id, $source_item_key, $remote_comment_id ) {
		return $session_id->to_string() . ':' . (string) $source_item_key . ':' . (int) $remote_comment_id;
	}

	/**
	 * Persists the gateway snapshot when write-through mode is enabled.
	 *
	 * @return void
	 */
	private function persist() {
		if ( null === $this->persistence_path ) {
			return;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions,WordPress.PHP.DiscouragedPHPFunctions -- Test-only write-through snapshot for child-process recovery tests.
		file_put_contents( $this->persistence_path, serialize( $this ) );
	}
}
