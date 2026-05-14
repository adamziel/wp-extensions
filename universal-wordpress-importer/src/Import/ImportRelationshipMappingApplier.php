<?php
/**
 * Applies resolved REST relationship mapping decisions.
 *
 * @package UniversalImporter
 */

namespace UniversalImporter\Import;

use RuntimeException;

/**
 * Applies operator-approved relationship mappings to already-imported drafts.
 */
final class ImportRelationshipMappingApplier {
	const DEFAULT_DECISION_LIMIT = 100;

	/**
	 * Durable import store.
	 *
	 * @var WordPressImportSessionStore
	 */
	private $store;

	/**
	 * WordPress post gateway.
	 *
	 * @var ImportPostGatewayInterface
	 */
	private $posts;

	/**
	 * Constructor.
	 *
	 * @param WordPressImportSessionStore     $store Durable store.
	 * @param ImportPostGatewayInterface|null $posts Optional post gateway.
	 */
	public function __construct( WordPressImportSessionStore $store, ImportPostGatewayInterface $posts = null ) {
		$this->store = $store;
		$this->posts = null === $posts ? new WordPressPostGateway() : $posts;
	}

	/**
	 * Advances resolved relationship mapping decisions.
	 *
	 * @param ImportSession $session Session.
	 * @param int           $limit   Maximum decisions to inspect.
	 * @return array{applied:int,skipped:int,failed:int,message:string}
	 */
	public function advance( ImportSession $session, $limit = self::DEFAULT_DECISION_LIMIT ) {
		$limit   = max( 1, min( 250, (int) $limit ) );
		$summary = array(
			'applied' => 0,
			'skipped' => 0,
			'failed'  => 0,
			'message' => 'No resolved relationship mapping decisions were ready to apply.',
		);

		if ( ! $this->posts->is_available() ) {
			$summary['message'] = $this->posts->get_unavailable_reason();
			return $summary;
		}

		foreach ( $this->store->list_unapplied_resolved_decisions_by_key_prefix( $session->get_id(), ImportRelationshipMappingDecision::DECISION_PREFIX, 'relationship-mapping:', $limit ) as $decision ) {
			$result = $this->apply_decision( $session, $decision );
			++$summary[ $result ];
		}

		if ( 0 < $summary['applied'] || 0 < $summary['skipped'] || 0 < $summary['failed'] ) {
			$summary['message'] = 'Resolved relationship mapping decisions were inspected.';
		}

		return $summary;
	}

	/**
	 * Applies one resolved decision if it has not already been applied.
	 *
	 * @param ImportSession  $session  Session.
	 * @param ImportDecision $decision Resolved relationship mapping decision.
	 * @return string Summary bucket.
	 */
	private function apply_decision( ImportSession $session, ImportDecision $decision ) {
		$options         = $decision->get_options();
		$answer          = $decision->get_answer();
		$idempotency_key = 'relationship-mapping:' . $decision->get_key();
		$payload_hash    = $this->mapping_hash( $options, $answer );
		$record          = $this->store->find_idempotency_record( $session->get_id(), $idempotency_key );

		if ( null !== $record && $record->get_payload_hash() === $payload_hash ) {
			return 'skipped';
		}

		if ( null === $answer ) {
			$this->record_failure( $session, $decision, 'Resolved relationship mapping decision is missing its answer.' );
			return 'failed';
		}

		$post_id         = isset( $options['post_id'] ) ? (int) $options['post_id'] : 0;
		$source_item_key = isset( $options['source_item_key'] ) ? (string) $options['source_item_key'] : '';

		if ( $post_id < 1 ) {
			$this->record_failure( $session, $decision, 'Resolved relationship mapping decision is missing its imported post id.' );
			return 'failed';
		}

		try {
			$this->posts->apply_relationship_mapping( $post_id, $answer );
			$diagnostics = $this->posts->get_last_relationship_diagnostics();

			if ( $this->has_incomplete_mapping( $diagnostics ) ) {
				$this->store->record_event(
					$session->get_id(),
					new ImportProgressEvent(
						ImportProgressEvent::LEVEL_WARNING,
						'post.relationships_mapping_incomplete',
						'Resolved REST relationship mapping could not be fully applied; verify the local user, taxonomy, and term ids.',
						array(
							'decision_key'    => $decision->get_key(),
							'post_id'         => $post_id,
							'source_item_key' => $source_item_key,
							'relationships'   => $diagnostics,
						)
					)
				);

				return 'failed';
			}

			$this->store->remember_idempotency_record(
				$session->get_id(),
				new ImportIdempotencyRecord(
					$idempotency_key,
					'relationship-mapping',
					(string) $post_id,
					$payload_hash
				)
			);

			$this->store->record_event(
				$session->get_id(),
				new ImportProgressEvent(
					ImportProgressEvent::LEVEL_INFO,
					'post.relationships_mapping_applied',
					'Resolved REST relationship mapping was applied to the imported draft.',
					array(
						'decision_key'    => $decision->get_key(),
						'post_id'         => $post_id,
						'source_item_key' => $source_item_key,
						'relationships'   => $diagnostics,
					)
				)
			);

			return 'applied';
		} catch ( RuntimeException $exception ) {
			$this->record_failure( $session, $decision, $exception->getMessage() );
			return 'failed';
		}
	}

	/**
	 * Records an actionable mapping failure.
	 *
	 * @param ImportSession  $session  Session.
	 * @param ImportDecision $decision Decision.
	 * @param string         $message  Failure message.
	 * @return void
	 */
	private function record_failure( ImportSession $session, ImportDecision $decision, $message ) {
		$this->store->record_event(
			$session->get_id(),
			new ImportProgressEvent(
				ImportProgressEvent::LEVEL_ERROR,
				'post.relationships_mapping_failed',
				(string) $message,
				array(
					'decision_key' => $decision->get_key(),
					'options'      => $decision->get_options(),
				)
			)
		);
	}

	/**
	 * Whether relationship diagnostics show that an explicit mapping still needs attention.
	 *
	 * @param array<string,mixed> $diagnostics Relationship diagnostics.
	 * @return bool
	 */
	private function has_incomplete_mapping( array $diagnostics ) {
		if ( isset( $diagnostics['author']['status'] ) && in_array( $diagnostics['author']['status'], array( 'unmapped', 'local_user_missing' ), true ) ) {
			return true;
		}

		if ( empty( $diagnostics['terms'] ) || ! is_array( $diagnostics['terms'] ) ) {
			return false;
		}

		foreach ( $diagnostics['terms'] as $taxonomy_diagnostics ) {
			if ( ! is_array( $taxonomy_diagnostics ) || ! isset( $taxonomy_diagnostics['status'] ) ) {
				continue;
			}

			if ( in_array( $taxonomy_diagnostics['status'], array( 'taxonomy_missing', 'unmapped' ), true ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Builds a stable hash for an applied decision payload.
	 *
	 * @param array<string,mixed>      $options Decision options.
	 * @param array<string,mixed>|null $answer  Decision answer.
	 * @return string
	 */
	private function mapping_hash( array $options, array $answer = null ) {
		$payload = array(
			'post_id' => isset( $options['post_id'] ) ? (int) $options['post_id'] : 0,
			'answer'  => null === $answer ? array() : $answer,
		);

		if ( function_exists( 'wp_json_encode' ) ) {
			$json = wp_json_encode( $payload );
		} else {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Unit tests run without WordPress.
			$json = json_encode( $payload );
		}

		return hash( 'sha256', is_string( $json ) ? $json : '' );
	}
}
