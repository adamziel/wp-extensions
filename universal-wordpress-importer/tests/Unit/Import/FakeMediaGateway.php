<?php
/**
 * Fake WordPress media gateway for import tests.
 *
 * @package UniversalImporter\Tests\Unit\Import
 */

namespace UniversalImporter\Tests\Unit\Import;

use RuntimeException;
use UniversalImporter\Import\ImportMediaGatewayInterface;
use UniversalImporter\Import\ImportMediaReference;
use UniversalImporter\Import\ImportSessionId;

/**
 * In-memory media gateway for queued attachment import tests.
 */
final class FakeMediaGateway implements ImportMediaGatewayInterface {
	/**
	 * Whether media persistence is available.
	 *
	 * @var bool
	 */
	private $available = true;

	/**
	 * Stored fake attachments keyed by id.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	private $attachments = array();

	/**
	 * Next fake attachment id.
	 *
	 * @var int
	 */
	private $next_id = 100;

	/**
	 * Reference index keyed by session id and reference key.
	 *
	 * @var array<string,int>
	 */
	private $reference_index = array();

	/**
	 * Optional failure message.
	 *
	 * @var string|null
	 */
	private $failure_message;

	/**
	 * Remote response bodies keyed by URL.
	 *
	 * @var array<string,string>
	 */
	private $remote_bodies = array();

	/**
	 * Optional file path for write-through persistence across child processes.
	 *
	 * @var string|null
	 */
	private $persistence_path;

	/**
	 * Loads a fake media gateway from a persisted snapshot file.
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
	 * Makes future imports fail.
	 *
	 * @param string $message Failure message.
	 * @return void
	 */
	public function fail_imports_with( $message ) {
		$this->failure_message = (string) $message;
		$this->persist();
	}

	/**
	 * Adds a fake remote media response.
	 *
	 * @param string $url  Remote URL.
	 * @param string $body Response body.
	 * @return void
	 */
	public function add_remote_media( $url, $body ) {
		$this->remote_bodies[ (string) $url ] = (string) $body;
		$this->persist();
	}

	/**
	 * Whether media persistence is available in the current runtime.
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
		return 'Fake media gateway is unavailable.';
	}

	/**
	 * Finds an existing attachment by importer metadata.
	 *
	 * @param ImportSessionId $session_id    Session id.
	 * @param string          $reference_key Media reference key.
	 * @return int|null
	 */
	public function find_existing_attachment_id( ImportSessionId $session_id, $reference_key ) {
		$key = $this->index_key( $session_id, $reference_key );

		return isset( $this->reference_index[ $key ] ) ? $this->reference_index[ $key ] : null;
	}

	/**
	 * Imports or updates one local media file.
	 *
	 * @param ImportMediaReference $reference     Media reference.
	 * @param int|null             $attachment_id Existing attachment id.
	 * @return array{id:int,url:string,source_hash:string}
	 * @throws RuntimeException When configured to fail imports.
	 */
	public function import_local_file( ImportMediaReference $reference, $attachment_id = null ) {
		if ( null !== $this->failure_message ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Runtime diagnostics are not rendered directly.
			throw new RuntimeException( $this->failure_message );
		}

		if ( null === $attachment_id ) {
			$attachment_id = $this->next_id;
			++$this->next_id;
		}

		$attachment_id = (int) $attachment_id;
		$source_hash   = hash_file( 'sha256', $reference->get_resolved_source_uri() );

		$this->attachments[ $attachment_id ] = array(
			'ID'                  => $attachment_id,
			'url'                 => 'https://local.example.test/wp-content/uploads/' . basename( $reference->get_resolved_source_uri() ),
			'reference_key'       => $reference->get_key(),
			'source_item_key'     => $reference->get_source_item_key(),
			'resolved_source_uri' => $reference->get_resolved_source_uri(),
			'source_hash'         => is_string( $source_hash ) ? $source_hash : '',
		);

		$this->reference_index[ $this->index_key( $reference->get_session_id(), $reference->get_key() ) ] = $attachment_id;
		$this->persist();

		return array(
			'id'          => $attachment_id,
			'url'         => $this->attachments[ $attachment_id ]['url'],
			'source_hash' => $this->attachments[ $attachment_id ]['source_hash'],
		);
	}

	/**
	 * Imports or reuses one fake remote media URL.
	 *
	 * @param ImportMediaReference $reference     Media reference.
	 * @param int|null             $attachment_id Existing attachment id.
	 * @return array{id:int,url:string,source_hash:string}
	 * @throws RuntimeException When configured to fail imports.
	 */
	public function import_remote_url( ImportMediaReference $reference, $attachment_id = null ) {
		if ( null !== $this->failure_message ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Runtime diagnostics are not rendered directly.
			throw new RuntimeException( $this->failure_message );
		}

		$url = $reference->get_resolved_source_uri();

		if ( ! isset( $this->remote_bodies[ $url ] ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Runtime diagnostics are not rendered directly.
			throw new RuntimeException( 'Fake remote media URL was not registered: ' . $url );
		}

		if ( null === $attachment_id ) {
			$attachment_id = $this->next_id;
			++$this->next_id;
		}

		$attachment_id = (int) $attachment_id;
		// phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- Unit tests run without WordPress loaded.
		$path        = parse_url( $url, PHP_URL_PATH );
		$basename    = is_string( $path ) && '' !== basename( $path ) ? basename( $path ) : 'remote-media';
		$source_hash = hash( 'sha256', $this->remote_bodies[ $url ] );

		$this->attachments[ $attachment_id ] = array(
			'ID'                  => $attachment_id,
			'url'                 => 'https://local.example.test/wp-content/uploads/' . $basename,
			'reference_key'       => $reference->get_key(),
			'source_item_key'     => $reference->get_source_item_key(),
			'resolved_source_uri' => $reference->get_resolved_source_uri(),
			'source_hash'         => $source_hash,
		);

		$this->reference_index[ $this->index_key( $reference->get_session_id(), $reference->get_key() ) ] = $attachment_id;
		$this->persist();

		return array(
			'id'          => $attachment_id,
			'url'         => $this->attachments[ $attachment_id ]['url'],
			'source_hash' => $source_hash,
		);
	}

	/**
	 * Applies staged metadata to a fake attachment.
	 *
	 * @param int                  $attachment_id Local attachment id.
	 * @param ImportMediaReference $reference     Source media reference.
	 * @return void
	 * @throws RuntimeException When configured to fail imports.
	 */
	public function apply_attachment_metadata( $attachment_id, ImportMediaReference $reference ) {
		if ( null !== $this->failure_message ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Runtime diagnostics are not rendered directly.
			throw new RuntimeException( $this->failure_message );
		}

		$attachment_id = (int) $attachment_id;

		if ( ! isset( $this->attachments[ $attachment_id ] ) ) {
			throw new RuntimeException( 'Fake attachment does not exist for metadata persistence.' );
		}

		$metadata = $reference->get_metadata();
		$staged   = isset( $metadata['wxr_attachment_metadata'] ) && is_array( $metadata['wxr_attachment_metadata'] ) ? $metadata['wxr_attachment_metadata'] : array();

		foreach ( array(
			'title'       => 'post_title',
			'caption'     => 'post_excerpt',
			'description' => 'post_content',
			'alt_text'    => 'alt_text',
		) as $source_key => $target_key ) {
			if ( isset( $staged[ $source_key ] ) && '' !== trim( (string) $staged[ $source_key ] ) ) {
				$this->attachments[ $attachment_id ][ $target_key ] = trim( (string) $staged[ $source_key ] );
			}
		}

		$this->attachments[ $attachment_id ]['wxr_attachment_metadata'] = $staged;
		$this->persist();
	}

	/**
	 * Applies a remapped parent post to a fake attachment.
	 *
	 * @param int                  $attachment_id  Local attachment id.
	 * @param int                  $parent_post_id Local parent post id.
	 * @param ImportMediaReference $reference      Source media reference.
	 * @return void
	 * @throws RuntimeException When configured to fail imports.
	 */
	public function apply_attachment_parent( $attachment_id, $parent_post_id, ImportMediaReference $reference ) {
		unset( $reference );

		if ( null !== $this->failure_message ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Runtime diagnostics are not rendered directly.
			throw new RuntimeException( $this->failure_message );
		}

		$attachment_id  = (int) $attachment_id;
		$parent_post_id = (int) $parent_post_id;

		if ( ! isset( $this->attachments[ $attachment_id ] ) ) {
			throw new RuntimeException( 'Fake attachment does not exist for parent restoration.' );
		}

		$this->attachments[ $attachment_id ]['post_parent'] = $parent_post_id;
		$this->persist();
	}

	/**
	 * Returns the number of fake attachments.
	 *
	 * @return int
	 */
	public function count_attachments() {
		return count( $this->attachments );
	}

	/**
	 * Returns a stored fake attachment.
	 *
	 * @param int $attachment_id Attachment id.
	 * @return array<string,mixed>|null
	 */
	public function get_attachment( $attachment_id ) {
		return isset( $this->attachments[ $attachment_id ] ) ? $this->attachments[ $attachment_id ] : null;
	}

	/**
	 * Builds the reference index key.
	 *
	 * @param ImportSessionId $session_id    Session id.
	 * @param string          $reference_key Media reference key.
	 * @return string
	 */
	private function index_key( ImportSessionId $session_id, $reference_key ) {
		return $session_id->to_string() . ':' . (string) $reference_key;
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
