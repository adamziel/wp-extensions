<?php
/**
 * First-party URL confirmation coordinator.
 *
 * @package UniversalImporter
 */

namespace UniversalImporter\Import;

/**
 * Creates and maintains URL-domain confirmation decisions from prepared documents.
 */
final class ImportUrlInference {
	const DECISION_KEY = 'confirm-first-party-domains';

	/**
	 * Durable import store.
	 *
	 * @var WordPressImportSessionStore
	 */
	private $store;

	/**
	 * Constructor.
	 *
	 * @param WordPressImportSessionStore $store Durable store.
	 */
	public function __construct( WordPressImportSessionStore $store ) {
		$this->store = $store;
	}

	/**
	 * Advances URL inference and returns whether post persistence should wait.
	 *
	 * @param ImportSession $session Session.
	 * @param int           $limit   Maximum prepared documents to inspect.
	 * @return array{domains:array<int,string>,blocked:bool,message:string}
	 */
	public function advance( ImportSession $session, $limit = 250 ) {
		$context  = $this->collect_candidate_domain_context( $session, $limit );
		$domains  = $context['domains'];
		$examples = $context['examples'];
		$decision = $this->store->find_decision( $session->get_id(), self::DECISION_KEY );

		if ( empty( $domains ) ) {
			return array(
				'domains' => array(),
				'blocked' => false,
				'message' => 'No absolute URL domains were found in prepared local documents.',
			);
		}

		if ( null !== $decision && ImportDecision::STATUS_RESOLVED === $decision->get_status() ) {
			return array(
				'domains' => $domains,
				'blocked' => false,
				'message' => 'First-party domains were already confirmed.',
			);
		}

		$existing_domains  = null === $decision ? array() : $this->domains_from_decision_options( $decision );
		$existing_examples = null === $decision ? array() : $this->examples_from_decision_options( $decision );
		$merged_domains    = $this->merge_domains( $existing_domains, $domains );
		$merged_examples   = $this->merge_domain_examples( $existing_examples, $examples );

		if ( null === $decision || $merged_domains !== $existing_domains || $merged_examples !== $existing_examples ) {
			$this->store->save_decision(
				$session->get_id(),
				ImportDecision::pending(
					self::DECISION_KEY,
					'Choose which discovered source URLs should be rewritten to this WordPress site.',
					array(
						'domains'  => $merged_domains,
						'examples' => $merged_examples,
					)
				)
			);
			$this->store->record_event(
				$session->get_id(),
				new ImportProgressEvent(
					ImportProgressEvent::LEVEL_WARNING,
					'url.confirmation_required',
					'Absolute URL domains were found and need first-party confirmation before posts are written.',
					array( 'domains' => $merged_domains )
				)
			);
		}

		return array(
			'domains' => $merged_domains,
			'blocked' => true,
			'message' => 'Post persistence is waiting for first-party domain confirmation.',
		);
	}

	/**
	 * Collects candidate domains from prepared document metadata.
	 *
	 * @param ImportSession $session Session.
	 * @param int           $limit   Maximum prepared documents to inspect.
	 * @return array{domains:array<int,string>,examples:array<string,array<int,string>>}
	 */
	private function collect_candidate_domain_context( ImportSession $session, $limit ) {
		$domains  = array();
		$examples = array();
		$limit    = max( 1, min( 500, (int) $limit ) );

		$after_source_item_key = null;

		do {
			$documents      = $this->store->list_prepared_documents_after_source_item_key(
				$session->get_id(),
				$after_source_item_key,
				$limit
			);
			$document_count = count( $documents );

			foreach ( $documents as $document ) {
				$after_source_item_key = $document->get_source_item_key();
				$metadata              = $document->get_metadata();

				if ( ! isset( $metadata['absolute_url_domains'] ) || ! is_array( $metadata['absolute_url_domains'] ) ) {
					continue;
				}

				$source_domains = $this->source_domains_for_metadata( $session, $metadata );
				$url_examples   = isset( $metadata['absolute_url_examples'] ) && is_array( $metadata['absolute_url_examples'] )
					? $metadata['absolute_url_examples']
					: array();

				foreach ( $metadata['absolute_url_domains'] as $domain ) {
					$domain = $this->normalize_domain( $domain );

					if (
						'' !== $domain
						&& $this->is_suggested_first_party_domain( $domain, $source_domains )
					) {
						$domains             = $this->merge_domains( $domains, array( $domain ) );
						$matching_examples   = $this->examples_for_domain( $domain, $url_examples );
						$examples[ $domain ] = $this->merge_example_list(
							isset( $examples[ $domain ] ) ? $examples[ $domain ] : array(),
							$matching_examples
						);
					}
				}
			}
		} while ( $document_count === $limit );

		$after_reference_key = null;

		do {
			$references      = $this->store->list_media_references_by_statuses_after_reference_key(
				$session->get_id(),
				array( ImportMediaReference::STATUS_QUEUED ),
				$after_reference_key,
				$limit
			);
			$reference_count = count( $references );

			foreach ( $references as $reference ) {
				$after_reference_key = $reference->get_key();
				$metadata            = $reference->get_metadata();

				if ( ! isset( $metadata['reference_scope'] ) || ImportWxrAttachment::SCOPE_CANDIDATE_FIRST_PARTY !== $metadata['reference_scope'] ) {
					continue;
				}

				$domain         = isset( $metadata['absolute_url_domain'] )
					? (string) $metadata['absolute_url_domain']
					: ImportWxrAttachment::domain_for_url( $reference->get_resolved_source_uri() );
				$source_domains = $this->source_domains_for_media_reference( $session, $reference );

				$domain = $this->normalize_domain( $domain );
				if (
					'' !== $domain
					&& $this->is_suggested_first_party_domain( $domain, $source_domains )
				) {
					$domains             = $this->merge_domains( $domains, array( $domain ) );
					$examples[ $domain ] = $this->merge_example_list(
						isset( $examples[ $domain ] ) ? $examples[ $domain ] : array(),
						array( $reference->get_resolved_source_uri() )
					);
				}
			}
		} while ( $reference_count === $limit );

		foreach ( $this->store->list_source_items_by_statuses( $session->get_id(), array( ImportSourceItem::STATUS_DISCOVERED, ImportSourceItem::STATUS_IMPORTED ), $limit ) as $item ) {
			$metadata = $item->get_metadata();

			if ( empty( $metadata['wxr_nav_menu_absolute_url_domains'] ) || ! is_array( $metadata['wxr_nav_menu_absolute_url_domains'] ) ) {
				continue;
			}

			$source_domains = $this->source_domains_for_metadata( $session, $metadata );
			$url_examples   = isset( $metadata['absolute_url_examples'] ) && is_array( $metadata['absolute_url_examples'] )
				? $metadata['absolute_url_examples']
				: array();

			foreach ( $metadata['wxr_nav_menu_absolute_url_domains'] as $domain ) {
				$domain = $this->normalize_domain( $domain );

				if (
					'' !== $domain
					&& $this->is_suggested_first_party_domain( $domain, $source_domains )
				) {
					$domains             = $this->merge_domains( $domains, array( $domain ) );
					$matching_examples   = $this->examples_for_domain( $domain, $url_examples );
					$examples[ $domain ] = $this->merge_example_list(
						isset( $examples[ $domain ] ) ? $examples[ $domain ] : array(),
						$matching_examples
					);
				}
			}
		}

		$domains = $this->merge_domains( array(), $domains );
		ksort( $examples );

		return array(
			'domains'  => $domains,
			'examples' => array_intersect_key( $examples, array_flip( $domains ) ),
		);
	}

	/**
	 * Returns source-domain clues from a session and metadata payload.
	 *
	 * @param ImportSession       $session  Session.
	 * @param array<string,mixed> $metadata Metadata payload.
	 * @return array<int,string>
	 */
	private function source_domains_for_metadata( ImportSession $session, array $metadata ) {
		$domains = $this->source_domains_for_url( $session->get_source() );

		foreach ( array( 'remote_source_url', 'wxr_link', 'wxr_guid', 'source_uri' ) as $key ) {
			if ( isset( $metadata[ $key ] ) ) {
				$domains = $this->merge_domains(
					$domains,
					$this->source_domains_for_url( (string) $metadata[ $key ] )
				);
			}
		}

		return $domains;
	}

	/**
	 * Returns source-domain clues for a media reference.
	 *
	 * @param ImportSession        $session   Session.
	 * @param ImportMediaReference $reference Media reference.
	 * @return array<int,string>
	 */
	private function source_domains_for_media_reference( ImportSession $session, ImportMediaReference $reference ) {
		$domains  = $this->source_domains_for_url( $session->get_source() );
		$metadata = $reference->get_metadata();

		foreach ( array( 'wxr_guid', 'attachment_url' ) as $key ) {
			if ( isset( $metadata[ $key ] ) ) {
				$domains = $this->merge_domains(
					$domains,
					$this->source_domains_for_url( (string) $metadata[ $key ] )
				);
			}
		}

		return $domains;
	}

	/**
	 * Returns host clues from a URL-like value.
	 *
	 * @param string $url URL-like value.
	 * @return array<int,string>
	 */
	private function source_domains_for_url( $url ) {
		$host = function_exists( 'wp_parse_url' ) ? wp_parse_url( (string) $url, PHP_URL_HOST ) : null;

		if ( ! is_string( $host ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- Fallback used only outside WordPress bootstrap.
			$parts = parse_url( (string) $url );
			$host  = is_array( $parts ) && isset( $parts['host'] ) ? (string) $parts['host'] : '';
		}

		$host = $this->normalize_domain( $host );

		return '' === $host ? array() : array( $host );
	}

	/**
	 * Returns whether a discovered domain should be suggested as first-party.
	 *
	 * @param string            $domain         Candidate domain.
	 * @param array<int,string> $source_domains Source-domain clues.
	 * @return bool
	 */
	private function is_suggested_first_party_domain( $domain, array $source_domains ) {
		if ( empty( $source_domains ) ) {
			return true;
		}

		foreach ( $source_domains as $source_domain ) {
			$is_exact_source_domain = $domain === $source_domain;
			$is_source_subdomain    = substr( $domain, -1 * ( strlen( $source_domain ) + 1 ) ) === '.' . $source_domain;

			if ( $is_exact_source_domain || $is_source_subdomain ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Reads domain options from an existing decision.
	 *
	 * @param ImportDecision $decision Decision.
	 * @return array<int,string>
	 */
	private function domains_from_decision_options( ImportDecision $decision ) {
		$options = $decision->get_options();

		if ( ! isset( $options['domains'] ) || ! is_array( $options['domains'] ) ) {
			return array();
		}

		return $this->merge_domains( array(), $options['domains'] );
	}

	/**
	 * Reads URL examples from an existing decision.
	 *
	 * @param ImportDecision $decision Decision.
	 * @return array<string,array<int,string>>
	 */
	private function examples_from_decision_options( ImportDecision $decision ) {
		$options = $decision->get_options();

		if ( ! isset( $options['examples'] ) || ! is_array( $options['examples'] ) ) {
			return array();
		}

		return $this->merge_domain_examples( array(), $options['examples'] );
	}

	/**
	 * Merges and sorts domain lists.
	 *
	 * @param array<int,string> $left  First domain list.
	 * @param array<int,string> $right Second domain list.
	 * @return array<int,string>
	 */
	private function merge_domains( array $left, array $right ) {
		$domains = array();

		foreach ( array_merge( $left, $right ) as $domain ) {
			$domain = $this->normalize_domain( $domain );

			if ( '' !== $domain && ! in_array( $domain, $domains, true ) ) {
				$domains[] = $domain;
			}
		}

		sort( $domains );

		return $domains;
	}

	/**
	 * Merges domain-keyed example URL lists.
	 *
	 * @param array<string,array<int,string>> $left  First example map.
	 * @param array<string,array<int,string>> $right Second example map.
	 * @return array<string,array<int,string>>
	 */
	private function merge_domain_examples( array $left, array $right ) {
		$examples = array();

		foreach ( array( $left, $right ) as $map ) {
			foreach ( $map as $domain => $urls ) {
				$domain = $this->normalize_domain( $domain );

				if ( '' === $domain || ! is_array( $urls ) ) {
					continue;
				}

				$examples[ $domain ] = $this->merge_example_list(
					isset( $examples[ $domain ] ) ? $examples[ $domain ] : array(),
					$urls
				);
			}
		}

		ksort( $examples );

		return $examples;
	}

	/**
	 * Returns example URLs for a normalized domain from a metadata examples map.
	 *
	 * @param string                     $domain   Normalized domain.
	 * @param array<string,array<mixed>> $examples Domain-keyed examples.
	 * @return array<int,string>
	 */
	private function examples_for_domain( $domain, array $examples ) {
		$urls = array();

		foreach ( $examples as $example_domain => $domain_examples ) {
			if ( $this->normalize_domain( $example_domain ) !== $domain || ! is_array( $domain_examples ) ) {
				continue;
			}

			$urls = $this->merge_example_list( $urls, $domain_examples );
		}

		return $urls;
	}

	/**
	 * Merges a bounded URL example list.
	 *
	 * @param array<int,string> $left  First URL list.
	 * @param array<int,mixed>  $right Second URL list.
	 * @return array<int,string>
	 */
	private function merge_example_list( array $left, array $right ) {
		$urls = array();

		foreach ( array_merge( $left, $right ) as $url ) {
			$url = trim( (string) $url );

			if ( '' !== $url && ! in_array( $url, $urls, true ) ) {
				$urls[] = $url;
			}

			if ( 3 <= count( $urls ) ) {
				break;
			}
		}

		return $urls;
	}

	/**
	 * Normalizes a candidate domain.
	 *
	 * @param string $domain Raw domain.
	 * @return string
	 */
	private function normalize_domain( $domain ) {
		$domain = strtolower( trim( (string) $domain ) );
		$domain = preg_replace( '/:\d+$/', '', $domain );

		return is_string( $domain ) ? $domain : '';
	}
}
