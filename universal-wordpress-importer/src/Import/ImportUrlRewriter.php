<?php
/**
 * Confirmed first-party URL rewriter.
 *
 * @package UniversalImporter
 */

namespace UniversalImporter\Import;

/**
 * Rewrites confirmed first-party absolute URLs in prepared documents.
 */
final class ImportUrlRewriter {
	/**
	 * Durable import store.
	 *
	 * @var WordPressImportSessionStore
	 */
	private $store;

	/**
	 * Local public site URL.
	 *
	 * @var string
	 */
	private $local_site_url;

	/**
	 * Whether prepared documents must have completed media detection first.
	 *
	 * @var bool
	 */
	private $require_stage_readiness;

	/**
	 * Constructor.
	 *
	 * @param WordPressImportSessionStore $store          Durable store.
	 * @param string|null                 $local_site_url Optional local public site URL.
	 * @param bool                        $require_stage_readiness Whether to require prior stage completion.
	 */
	public function __construct( WordPressImportSessionStore $store, $local_site_url = null, $require_stage_readiness = false ) {
		$this->store                   = $store;
		$this->local_site_url          = $this->normalize_local_site_url( $local_site_url );
		$this->require_stage_readiness = (bool) $require_stage_readiness;
	}

	/**
	 * Advances URL rewriting for prepared documents.
	 *
	 * @param ImportSession $session Session.
	 * @param int           $limit   Maximum prepared documents to inspect.
	 * @return array{rewritten:int,skipped:int,confirmed_domains:array<int,string>,message:string}
	 */
	public function advance( ImportSession $session, $limit = 250 ) {
		$confirmed_domains = $this->confirmed_domains( $session );
		$summary           = array(
			'rewritten'         => 0,
			'skipped'           => 0,
			'confirmed_domains' => $confirmed_domains,
			'message'           => 'No confirmed first-party domains were available for URL rewriting.',
		);

		if ( empty( $confirmed_domains ) ) {
			return $summary;
		}

		$limit = max( 1, min( 500, (int) $limit ) );

		$after_source_item_key = null;
		$inspected             = 0;

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

				if ( $this->already_rewritten_for_target( $metadata, $confirmed_domains ) ) {
					++$summary['skipped'];
					continue;
				}

				if ( $this->require_stage_readiness && ! $this->media_detection_complete_for_target( $document, $confirmed_domains ) ) {
					continue;
				}

				++$inspected;

				$rewritten = $this->rewrite_markup( $document->get_block_markup(), $confirmed_domains );

				if ( $rewritten['markup'] === $document->get_block_markup() ) {
					$metadata['url_rewriting'] = array(
						'complete'          => true,
						'local_site_url'    => $this->local_site_url,
						'confirmed_domains' => $confirmed_domains,
						'rewritten_count'   => 0,
					);
					$this->store->save_prepared_document( $document->with_metadata( $metadata ) );
					++$summary['skipped'];

					if ( $inspected >= $limit ) {
						break 2;
					}

					continue;
				}

				$metadata['url_rewriting'] = array(
					'complete'          => true,
					'local_site_url'    => $this->local_site_url,
					'confirmed_domains' => $confirmed_domains,
					'rewritten_count'   => $rewritten['count'],
				);

				$this->store->save_prepared_document(
					$document->with_rewritten_block_markup(
						$rewritten['markup'],
						$this->rewritten_hash( $document, $rewritten['markup'], $metadata ),
						$metadata
					)
				);

				$this->store->record_event(
					$session->get_id(),
					new ImportProgressEvent(
						ImportProgressEvent::LEVEL_INFO,
						'url.rewritten',
						'Confirmed first-party URLs were rewritten to the local site URL.',
						array(
							'source_item_key' => $document->get_source_item_key(),
							'rewritten_count' => $rewritten['count'],
							'local_site_url'  => $this->local_site_url,
						)
					)
				);

				++$summary['rewritten'];

				if ( $inspected >= $limit ) {
					break 2;
				}
			}
		} while ( $document_count === $limit );

		if ( 0 < $summary['rewritten'] || 0 < $summary['skipped'] ) {
			$summary['message'] = 'Confirmed first-party URL rewriting inspected prepared documents.';
		}

		return $summary;
	}

	/**
	 * Returns whether media detection has inspected this document for the current inputs.
	 *
	 * @param ImportPreparedDocument $document          Prepared document.
	 * @param array<int,string>      $confirmed_domains Confirmed first-party domains.
	 * @return bool
	 */
	private function media_detection_complete_for_target( ImportPreparedDocument $document, array $confirmed_domains ) {
		$metadata = $document->get_metadata();

		if ( empty( $metadata['media_detection'] ) || ! is_array( $metadata['media_detection'] ) ) {
			return false;
		}

		$state = $metadata['media_detection'];

		return ! empty( $state['complete'] )
			&& isset( $state['content_hash'], $state['confirmed_domains'] )
			&& $state['content_hash'] === $document->get_content_hash()
			&& array_values( $confirmed_domains ) === $state['confirmed_domains'];
	}

	/**
	 * Rewrites absolute HTTP(S) URLs whose host is confirmed first-party.
	 *
	 * @param string            $markup            Prepared markup.
	 * @param array<int,string> $confirmed_domains Confirmed first-party domains.
	 * @return array{markup:string,count:int}
	 */
	private function rewrite_markup( $markup, array $confirmed_domains ) {
		$count   = 0;
		$domains = array_fill_keys( $confirmed_domains, true );
		$markup  = preg_replace_callback(
			'#https?://[^\s<>"\'\)\]\}]+#i',
			function ( $matches ) use ( $domains, &$count ) {
				$raw_url      = $matches[0];
				$trailing     = '';
				$candidate    = $raw_url;
				$last_checked = substr( $candidate, -1 );

				while ( false !== $last_checked && '' !== $candidate && preg_match( '/[.,;:]$/', $last_checked ) ) {
					$trailing     = $last_checked . $trailing;
					$candidate    = substr( $candidate, 0, -1 );
					$last_checked = substr( $candidate, -1 );
				}

				$parts = $this->parse_url( html_entity_decode( $candidate, ENT_QUOTES, 'UTF-8' ) );

				if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
					return $raw_url;
				}

				$host = $this->normalize_domain( $parts['host'] );

				if ( ! isset( $domains[ $host ] ) ) {
					return $raw_url;
				}

				++$count;

				return $this->local_url_for_parts( $parts ) . $trailing;
			},
			(string) $markup
		);

		return array(
			'markup' => is_string( $markup ) ? $markup : '',
			'count'  => $count,
		);
	}

	/**
	 * Builds a local URL with the source path/query/fragment preserved.
	 *
	 * @param array<string,mixed> $parts Parsed source URL parts.
	 * @return string
	 */
	private function local_url_for_parts( array $parts ) {
		$path = isset( $parts['path'] ) ? (string) $parts['path'] : '';
		$url  = rtrim( $this->local_site_url, '/' ) . '/' . ltrim( $path, '/' );

		if ( isset( $parts['query'] ) && '' !== (string) $parts['query'] ) {
			$url .= '?' . (string) $parts['query'];
		}

		if ( isset( $parts['fragment'] ) && '' !== (string) $parts['fragment'] ) {
			$url .= '#' . (string) $parts['fragment'];
		}

		return $url;
	}

	/**
	 * Returns confirmed first-party domains from the resolved decision.
	 *
	 * @param ImportSession $session Session.
	 * @return array<int,string>
	 */
	private function confirmed_domains( ImportSession $session ) {
		$decision = $this->store->find_decision( $session->get_id(), ImportUrlInference::DECISION_KEY );

		if ( null === $decision || ImportDecision::STATUS_RESOLVED !== $decision->get_status() ) {
			return array();
		}

		$answer = $decision->get_answer();

		if ( ! isset( $answer['confirmed_domains'] ) || ! is_array( $answer['confirmed_domains'] ) ) {
			return array();
		}

		$domains = array();

		foreach ( $answer['confirmed_domains'] as $domain ) {
			$domain = $this->normalize_domain( $domain );

			if ( '' !== $domain && ! in_array( $domain, $domains, true ) ) {
				$domains[] = $domain;
			}
		}

		sort( $domains );

		return $domains;
	}

	/**
	 * Determines whether this document was already rewritten for the same target.
	 *
	 * @param array<string,mixed> $metadata          Document metadata.
	 * @param array<int,string>   $confirmed_domains Confirmed first-party domains.
	 * @return bool
	 */
	private function already_rewritten_for_target( array $metadata, array $confirmed_domains ) {
		if ( empty( $metadata['url_rewriting']['complete'] ) || ! is_array( $metadata['url_rewriting'] ) ) {
			return false;
		}

		$rewrite = $metadata['url_rewriting'];

		return isset( $rewrite['local_site_url'], $rewrite['confirmed_domains'] )
			&& $this->local_site_url === $rewrite['local_site_url']
			&& $confirmed_domains === $rewrite['confirmed_domains'];
	}


	/**
	 * Builds a stable hash for rewritten post persistence.
	 *
	 * @param ImportPreparedDocument $document Prepared document.
	 * @param string                 $markup   Rewritten markup.
	 * @param array<string,mixed>    $metadata Rewritten metadata.
	 * @return string
	 */
	private function rewritten_hash( ImportPreparedDocument $document, $markup, array $metadata ) {
		return hash( 'sha256', $document->get_content_hash() . "\nrewritten\n" . (string) $markup . "\n" . $this->encode_json( $metadata ) );
	}

	/**
	 * Parses a URL using WordPress' compatibility wrapper when available.
	 *
	 * @param string $url URL to parse.
	 * @return array<string,mixed>|false
	 */
	private function parse_url( $url ) {
		if ( function_exists( 'wp_parse_url' ) ) {
			return wp_parse_url( $url );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- Unit tests run without WordPress loaded.
		return parse_url( $url );
	}

	/**
	 * Encodes metadata deterministically enough for a content hash.
	 *
	 * @param array<string,mixed> $data Data to encode.
	 * @return string
	 */
	private function encode_json( array $data ) {
		if ( function_exists( 'wp_json_encode' ) ) {
			return (string) wp_json_encode( $data );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Unit tests run without WordPress loaded.
		return (string) json_encode( $data );
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

	/**
	 * Normalizes the local public site URL.
	 *
	 * @param string|null $local_site_url Optional local public site URL.
	 * @return string
	 */
	private function normalize_local_site_url( $local_site_url ) {
		if ( null === $local_site_url && function_exists( 'home_url' ) ) {
			$local_site_url = home_url( '/' );
		}

		$local_site_url = trim( (string) $local_site_url );

		if ( '' === $local_site_url ) {
			$local_site_url = 'http://example.org/';
		}

		return rtrim( $local_site_url, '/' ) . '/';
	}
}
