<?php
/**
 * Relationship mapping decision helpers.
 *
 * @package UniversalImporter
 */

namespace UniversalImporter\Import;

/**
 * Builds operator decisions and summaries for partially mapped REST relationships.
 */
final class ImportRelationshipMappingDecision {
	const DECISION_PREFIX = 'map-rest-relationships:';
	const WARNING_EVENT   = 'post.relationships_partially_mapped';

	/**
	 * Builds a stable decision key for one prepared document.
	 *
	 * @param string $source_item_key Source item key.
	 * @return string
	 */
	public static function decision_key( $source_item_key ) {
		return self::DECISION_PREFIX . substr( sha1( (string) $source_item_key ), 0, 16 );
	}

	/**
	 * Whether gateway diagnostics include relationships that need operator mapping.
	 *
	 * @param array<string,mixed> $diagnostics Relationship diagnostics.
	 * @return bool
	 */
	public static function has_unmapped_relationships( array $diagnostics ) {
		if ( isset( $diagnostics['author']['status'] ) && 'unmapped' === $diagnostics['author']['status'] ) {
			return true;
		}

		if ( empty( $diagnostics['terms'] ) || ! is_array( $diagnostics['terms'] ) ) {
			return false;
		}

		foreach ( $diagnostics['terms'] as $taxonomy_diagnostics ) {
			if ( is_array( $taxonomy_diagnostics ) && isset( $taxonomy_diagnostics['status'] ) && in_array( $taxonomy_diagnostics['status'], array( 'taxonomy_missing', 'unmapped' ), true ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Builds a pending operator decision for an imported draft with partial relationship mappings.
	 *
	 * @param int                    $post_id     Imported post id.
	 * @param ImportPreparedDocument $document    Prepared document.
	 * @param array<string,mixed>    $diagnostics Relationship diagnostics.
	 * @return ImportDecision
	 */
	public static function pending_decision( $post_id, ImportPreparedDocument $document, array $diagnostics ) {
		$source_item_key = $document->get_source_item_key();

		return ImportDecision::pending(
			self::decision_key( $source_item_key ),
			'Map the remote REST author and taxonomy relationships for imported draft post ' . (int) $post_id . '.',
			self::decision_options( (int) $post_id, $document, $diagnostics )
		);
	}

	/**
	 * Builds decision options for the admin and CLI surfaces.
	 *
	 * @param int                    $post_id     Imported post id.
	 * @param ImportPreparedDocument $document    Prepared document.
	 * @param array<string,mixed>    $diagnostics Relationship diagnostics.
	 * @return array<string,mixed>
	 */
	public static function decision_options( $post_id, ImportPreparedDocument $document, array $diagnostics ) {
		$metadata = $document->get_metadata();

		return array(
			'post_id'         => (int) $post_id,
			'source_item_key' => $document->get_source_item_key(),
			'remote_author'   => isset( $metadata['remote_author'] ) && is_array( $metadata['remote_author'] ) ? $metadata['remote_author'] : null,
			'remote_terms'    => isset( $metadata['remote_terms'] ) && is_array( $metadata['remote_terms'] ) ? $metadata['remote_terms'] : array(),
			'diagnostics'     => $diagnostics,
			'answer_template' => self::answer_template( $metadata ),
			'answer_contract' => 'Set author.local_user_id to an existing local user id. For each term, set local_term_id and optionally local_taxonomy when the remote taxonomy differs locally.',
		);
	}

	/**
	 * Builds a structured answer template from remote metadata.
	 *
	 * @param array<string,mixed> $metadata Prepared document metadata.
	 * @return array<string,mixed>
	 */
	public static function answer_template( array $metadata ) {
		$template = array(
			'author' => array(
				'local_user_id' => 0,
			),
			'terms'  => array(),
		);

		if ( empty( $metadata['remote_author'] ) || ! is_array( $metadata['remote_author'] ) ) {
			unset( $template['author'] );
		}

		if ( empty( $metadata['remote_terms'] ) || ! is_array( $metadata['remote_terms'] ) ) {
			return $template;
		}

		foreach ( $metadata['remote_terms'] as $taxonomy => $terms ) {
			if ( ! is_array( $terms ) ) {
				continue;
			}

			$term_mappings = array();

			foreach ( $terms as $term ) {
				if ( ! is_array( $term ) ) {
					continue;
				}

				$term_mappings[] = array(
					'remote_id'      => isset( $term['id'] ) ? (int) $term['id'] : null,
					'remote_slug'    => isset( $term['slug'] ) ? (string) $term['slug'] : '',
					'remote_name'    => isset( $term['name'] ) ? (string) $term['name'] : '',
					'local_taxonomy' => (string) $taxonomy,
					'local_term_id'  => 0,
				);
			}

			if ( ! empty( $term_mappings ) ) {
				$template['terms'][ (string) $taxonomy ] = $term_mappings;
			}
		}

		return $template;
	}

	/**
	 * Builds a concise relationship warning summary from an event.
	 *
	 * @param ImportProgressEvent $event           Warning event.
	 * @param bool                $capitalize_post Whether to capitalize the post prefix.
	 * @return string
	 */
	public static function summarize_warning_event( ImportProgressEvent $event, $capitalize_post = false ) {
		$context       = $event->get_context();
		$post_id       = isset( $context['post_id'] ) ? (int) $context['post_id'] : 0;
		$source_key    = isset( $context['source_item_key'] ) ? (string) $context['source_item_key'] : '';
		$relationships = isset( $context['relationships'] ) && is_array( $context['relationships'] ) ? $context['relationships'] : array();
		$parts         = array();

		if ( isset( $relationships['author']['status'] ) && 'unmapped' === $relationships['author']['status'] ) {
			$parts[] = 'author unmapped';
		}

		if ( isset( $relationships['terms'] ) && is_array( $relationships['terms'] ) ) {
			foreach ( $relationships['terms'] as $taxonomy => $diagnostics ) {
				if ( ! is_array( $diagnostics ) || ! isset( $diagnostics['status'] ) ) {
					continue;
				}

				if ( in_array( $diagnostics['status'], array( 'taxonomy_missing', 'unmapped' ), true ) ) {
					$parts[] = (string) $taxonomy . ' ' . $diagnostics['status'];
				}
			}
		}

		if ( empty( $parts ) ) {
			$parts[] = $event->get_message();
		}

		return ( $capitalize_post ? 'Post ' : 'post ' ) . ( 0 < $post_id ? (string) $post_id : '?' ) . ' from ' . ( '' === $source_key ? 'unknown source' : $source_key ) . ': ' . implode( '; ', $parts );
	}
}
