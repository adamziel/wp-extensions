<?php
/**
 * Media reference detector.
 *
 * @package UniversalImporter
 */

namespace UniversalImporter\Import;

/**
 * Finds local and confirmed first-party media references in prepared documents.
 */
final class ImportMediaReferenceDetector {
	const DEFAULT_DOCUMENT_LIMIT = 250;

	/**
	 * File extensions treated as importable media references.
	 *
	 * @var array<string,string>
	 */
	private static $media_extensions = array(
		'jpg'  => ImportMediaReference::TYPE_IMAGE,
		'jpeg' => ImportMediaReference::TYPE_IMAGE,
		'png'  => ImportMediaReference::TYPE_IMAGE,
		'gif'  => ImportMediaReference::TYPE_IMAGE,
		'webp' => ImportMediaReference::TYPE_IMAGE,
		'avif' => ImportMediaReference::TYPE_IMAGE,
		'svg'  => ImportMediaReference::TYPE_IMAGE,
		'mp3'  => ImportMediaReference::TYPE_AUDIO,
		'm4a'  => ImportMediaReference::TYPE_AUDIO,
		'ogg'  => ImportMediaReference::TYPE_AUDIO,
		'wav'  => ImportMediaReference::TYPE_AUDIO,
		'mp4'  => ImportMediaReference::TYPE_VIDEO,
		'm4v'  => ImportMediaReference::TYPE_VIDEO,
		'webm' => ImportMediaReference::TYPE_VIDEO,
		'mov'  => ImportMediaReference::TYPE_VIDEO,
		'pdf'  => ImportMediaReference::TYPE_FILE,
	);

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
	 * Advances media detection for prepared documents.
	 *
	 * @param ImportSession $session Session.
	 * @param int           $limit   Maximum prepared documents to inspect.
	 * @return array{queued:int,skipped:int,message:string}
	 */
	public function advance( ImportSession $session, $limit = self::DEFAULT_DOCUMENT_LIMIT ) {
		$limit             = max( 1, min( 500, (int) $limit ) );
		$confirmed_domains = $this->get_confirmed_domains( $session );
		$summary           = array(
			'queued'  => 0,
			'skipped' => 0,
			'message' => 'No media references were ready to queue.',
		);

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

				if ( $this->already_inspected_document( $document, $confirmed_domains ) ) {
					continue;
				}

				++$inspected;
				$document_summary    = $this->queue_document_references( $document, $confirmed_domains );
				$summary['queued']  += $document_summary['queued'];
				$summary['skipped'] += $document_summary['skipped'];

				$this->mark_document_inspected( $document, $confirmed_domains, $document_summary );

				if ( $inspected >= $limit ) {
					break 2;
				}
			}
		} while ( $document_count === $limit );

		if ( 0 < $summary['queued'] || 0 < $summary['skipped'] ) {
			$summary['message'] = 'Media detector inspected prepared document references.';
		}

		return $summary;
	}

	/**
	 * Returns whether this document has already been inspected for the current inputs.
	 *
	 * @param ImportPreparedDocument $document          Prepared document.
	 * @param array<int,string>      $confirmed_domains Confirmed first-party domains.
	 * @return bool
	 */
	private function already_inspected_document( ImportPreparedDocument $document, array $confirmed_domains ) {
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
	 * Marks a document as inspected for media references.
	 *
	 * @param ImportPreparedDocument        $document          Prepared document.
	 * @param array<int,string>             $confirmed_domains Confirmed first-party domains.
	 * @param array{queued:int,skipped:int} $summary         Document summary.
	 * @return void
	 */
	private function mark_document_inspected( ImportPreparedDocument $document, array $confirmed_domains, array $summary ) {
		$metadata                    = $document->get_metadata();
		$metadata['media_detection'] = array(
			'complete'          => true,
			'content_hash'      => $document->get_content_hash(),
			'confirmed_domains' => array_values( $confirmed_domains ),
			'queued'            => $summary['queued'],
			'skipped'           => $summary['skipped'],
		);

		$this->store->save_prepared_document( $document->with_metadata( $metadata ) );
	}

	/**
	 * Queues references from one prepared document.
	 *
	 * @param ImportPreparedDocument $document          Prepared document.
	 * @param array<int,string>      $confirmed_domains Confirmed first-party domains.
	 * @return array{queued:int,skipped:int}
	 */
	private function queue_document_references( ImportPreparedDocument $document, array $confirmed_domains ) {
		$metadata       = $document->get_metadata();
		$queued         = 0;
		$skipped        = 0;
		$seen_in_memory = array();

		foreach ( $this->extract_candidate_urls( $document->get_block_markup() ) as $candidate ) {
			$normalized = $this->normalize_candidate_url( $candidate );

			if ( '' === $normalized || isset( $seen_in_memory[ $normalized ] ) ) {
				continue;
			}

			$seen_in_memory[ $normalized ] = true;
			$extension                     = $this->extension_for_url( $normalized );

			if ( '' === $extension || ! isset( self::$media_extensions[ $extension ] ) ) {
				++$skipped;
				continue;
			}

			$resolved = $this->resolve_reference( $normalized, $metadata, $confirmed_domains );

			if ( null === $resolved ) {
				++$skipped;
				continue;
			}

			if ( isset( $resolved['skip_reason'] ) ) {
				$this->save_skipped_reference( $document, $normalized, $resolved, self::$media_extensions[ $extension ], $extension );
				++$skipped;
				continue;
			}

			$reference = ImportMediaReference::queued(
				$document->get_session_id(),
				$this->reference_key( $document, $normalized ),
				$document->get_source_item_key(),
				$normalized,
				$resolved['source_uri'],
				self::$media_extensions[ $extension ],
				array(
					'reference_scope' => $resolved['scope'],
					'document_title'  => $document->get_title(),
					'extension'       => $extension,
				)
			);

			$existing = $this->store->find_media_reference( $document->get_session_id(), $reference->get_key() );
			$this->store->save_media_reference( $reference );

			if ( null === $existing ) {
				$this->store->record_event(
					$document->get_session_id(),
					new ImportProgressEvent(
						ImportProgressEvent::LEVEL_INFO,
						'media.reference_queued',
						'Media reference was queued for future import.',
						array(
							'source_item_key' => $document->get_source_item_key(),
							'original_url'    => $normalized,
							'media_type'      => $reference->get_media_type(),
						)
					)
				);
				++$queued;
			}
		}

		return array(
			'queued'  => $queued,
			'skipped' => $skipped,
		);
	}

	/**
	 * Extracts URL/path candidates from HTML attributes, srcset, CSS url(), and Markdown image syntax.
	 *
	 * @param string $markup Prepared block markup.
	 * @return array<int,string>
	 */
	private function extract_candidate_urls( $markup ) {
		$markup     = (string) $markup;
		$candidates = array();

		if ( preg_match_all( '/\b(?:src|href|poster)\s*=\s*(["\'])(.*?)\1/is', $markup, $matches ) ) {
			foreach ( $matches[2] as $url ) {
				$candidates[] = html_entity_decode( $url, ENT_QUOTES, 'UTF-8' );
			}
		}

		if ( preg_match_all( '/\bsrcset\s*=\s*(["\'])(.*?)\1/is', $markup, $matches ) ) {
			foreach ( $matches[2] as $srcset ) {
				foreach ( explode( ',', html_entity_decode( $srcset, ENT_QUOTES, 'UTF-8' ) ) as $candidate ) {
					$parts = preg_split( '/\s+/', trim( $candidate ) );
					if ( ! empty( $parts[0] ) ) {
						$candidates[] = $parts[0];
					}
				}
			}
		}

		if ( preg_match_all( '/url\(\s*(["\']?)(.*?)\1\s*\)/is', $markup, $matches ) ) {
			foreach ( $matches[2] as $url ) {
				$candidates[] = html_entity_decode( $url, ENT_QUOTES, 'UTF-8' );
			}
		}

		if ( preg_match_all( '/!\[[^\]]*\]\(([^)\s]+)(?:\s+["\'][^"\']*["\'])?\)/', $markup, $matches ) ) {
			foreach ( $matches[1] as $url ) {
				$candidates[] = html_entity_decode( $url, ENT_QUOTES, 'UTF-8' );
			}
		}

		return $candidates;
	}

	/**
	 * Resolves a reference to a source URI, or returns null when it is outside scope.
	 *
	 * @param string              $url               Candidate URL/path.
	 * @param array<string,mixed> $document_metadata Prepared document metadata.
	 * @param array<int,string>   $confirmed_domains Confirmed first-party domains.
	 * @return array{source_uri?:string,scope?:string,skip_reason?:string,skip_scope?:string}|null
	 */
	private function resolve_reference( $url, array $document_metadata, array $confirmed_domains ) {
		if ( preg_match( '#^https?://#i', $url ) ) {
			$host = $this->normalize_domain( $this->parse_url_part( $url, PHP_URL_HOST ) );

			if ( '' === $host || ! in_array( $host, $confirmed_domains, true ) ) {
				return null;
			}

			return array(
				'source_uri' => $url,
				'scope'      => 'confirmed-first-party-url',
			);
		}

		if ( preg_match( '#^(?:data|javascript|mailto|tel):#i', $url ) || 0 === strpos( $url, '#' ) || 0 === strpos( $url, '//' ) ) {
			return null;
		}

		if ( 0 === strpos( $url, '/' ) ) {
			return $this->resolve_local_absolute_path( $url, $document_metadata );
		}

		return $this->resolve_local_relative_path( $url, $document_metadata );
	}

	/**
	 * Resolves a relative media path against the prepared document source path.
	 *
	 * @param string              $url               Relative URL/path.
	 * @param array<string,mixed> $document_metadata Prepared document metadata.
	 * @return array{source_uri?:string,scope?:string,skip_reason?:string,skip_scope?:string}|null
	 */
	private function resolve_local_relative_path( $url, array $document_metadata ) {
		if ( ! isset( $document_metadata['source_uri'] ) || '' === (string) $document_metadata['source_uri'] ) {
			return null;
		}

		$source_uri = (string) $document_metadata['source_uri'];

		if ( preg_match( '#^[a-z][a-z0-9+.-]*://#i', $source_uri ) ) {
			return null;
		}

		$base_directory = dirname( $source_uri );
		$path           = $this->strip_url_suffix( $url );
		$resolved       = $this->normalize_local_path( $base_directory . '/' . $path );
		$source_root    = $this->local_source_root( $document_metadata );

		if ( null === $resolved ) {
			return null;
		}

		if ( null !== $source_root && ! $this->is_path_within_root( $resolved, $source_root ) ) {
			return array(
				'source_uri'  => $resolved,
				'scope'       => 'local-relative-path',
				'skip_reason' => 'Local media reference resolves outside the selected import source tree.',
			);
		}

		return array(
			'source_uri' => $resolved,
			'scope'      => 'local-relative-path',
		);
	}

	/**
	 * Resolves an absolute local filesystem path.
	 *
	 * @param string              $url               Absolute path.
	 * @param array<string,mixed> $document_metadata Prepared document metadata.
	 * @return array{source_uri?:string,scope?:string,skip_reason?:string,skip_scope?:string}|null
	 */
	private function resolve_local_absolute_path( $url, array $document_metadata ) {
		$path = $this->strip_url_suffix( $url );

		if ( '' === $path || ! file_exists( $path ) ) {
			return null;
		}

		$resolved = realpath( $path );

		if ( false === $resolved ) {
			return null;
		}

		$source_root = $this->local_source_root( $document_metadata );

		if ( null !== $source_root && ! $this->is_path_within_root( $resolved, $source_root ) ) {
			return array(
				'source_uri'  => $resolved,
				'scope'       => 'local-absolute-path',
				'skip_reason' => 'Absolute local media reference resolves outside the selected import source tree.',
			);
		}

		return array(
			'source_uri' => $resolved,
			'scope'      => 'local-absolute-path',
		);
	}

	/**
	 * Normalizes a local path without allowing traversal above the source directory.
	 *
	 * @param string $path Path.
	 * @return string|null
	 */
	private function normalize_local_path( $path ) {
		$real = realpath( $path );

		if ( false === $real || ! file_exists( $real ) ) {
			return null;
		}

		return $real;
	}

	/**
	 * Derives the local import root for a prepared document.
	 *
	 * @param array<string,mixed> $document_metadata Prepared document metadata.
	 * @return string|null Root path, or null when the source is not local.
	 */
	private function local_source_root( array $document_metadata ) {
		if ( ! isset( $document_metadata['source_uri'] ) || '' === (string) $document_metadata['source_uri'] ) {
			return null;
		}

		$source_uri = (string) $document_metadata['source_uri'];

		if ( preg_match( '#^[a-z][a-z0-9+.-]*://#i', $source_uri ) ) {
			return null;
		}

		$source_real = realpath( $source_uri );

		if ( false === $source_real ) {
			return null;
		}

		$root          = is_dir( $source_real ) ? $source_real : dirname( $source_real );
		$relative_path = isset( $document_metadata['relative_path'] ) ? trim( str_replace( '\\', '/', (string) $document_metadata['relative_path'] ), '/' ) : '';
		$relative_dir  = '' === $relative_path ? '' : dirname( $relative_path );

		if ( '' !== $relative_dir && '.' !== $relative_dir ) {
			foreach ( explode( '/', $relative_dir ) as $segment ) {
				if ( '' !== $segment && '.' !== $segment ) {
					$root = dirname( $root );
				}
			}
		}

		$root_real = realpath( $root );

		return false === $root_real ? null : $root_real;
	}

	/**
	 * Checks whether a resolved local path remains under the import root.
	 *
	 * @param string $path Resolved local path.
	 * @param string $root Resolved import root.
	 * @return bool
	 */
	private function is_path_within_root( $path, $root ) {
		$path = rtrim( str_replace( '\\', '/', (string) $path ), '/' );
		$root = rtrim( str_replace( '\\', '/', (string) $root ), '/' );

		return $path === $root || 0 === strpos( $path . '/', $root . '/' );
	}

	/**
	 * Stores a skipped media reference and records its warning once.
	 *
	 * @param ImportPreparedDocument $document   Prepared document.
	 * @param string                 $original   Original URL/path.
	 * @param array<string,mixed>    $resolved   Resolved skip details.
	 * @param string                 $media_type Media type.
	 * @param string                 $extension  Media extension.
	 * @return void
	 */
	private function save_skipped_reference( ImportPreparedDocument $document, $original, array $resolved, $media_type, $extension ) {
		$reason    = isset( $resolved['skip_reason'] ) ? (string) $resolved['skip_reason'] : 'Media reference was skipped.';
		$reference = ImportMediaReference::queued(
			$document->get_session_id(),
			$this->reference_key( $document, $original ),
			$document->get_source_item_key(),
			$original,
			isset( $resolved['source_uri'] ) ? (string) $resolved['source_uri'] : $original,
			$media_type,
			array(
				'reference_scope' => isset( $resolved['scope'] ) ? (string) $resolved['scope'] : 'unknown',
				'document_title'  => $document->get_title(),
				'extension'       => $extension,
			)
		);
		$existing  = $this->store->find_media_reference( $document->get_session_id(), $reference->get_key() );

		$this->store->save_media_reference( $reference->mark_skipped( $reason ) );

		if ( null !== $existing ) {
			return;
		}

		$this->store->record_event(
			$document->get_session_id(),
			new ImportProgressEvent(
				ImportProgressEvent::LEVEL_WARNING,
				'media.reference_skipped_outside_source',
				$reason,
				array(
					'source_item_key' => $document->get_source_item_key(),
					'original_url'    => (string) $original,
					'reference_scope' => $reference->get_metadata()['reference_scope'],
				)
			)
		);
	}

	/**
	 * Removes query strings and fragments before resolving a local path.
	 *
	 * @param string $url URL/path.
	 * @return string
	 */
	private function strip_url_suffix( $url ) {
		return preg_replace( '/[?#].*$/', '', (string) $url );
	}

	/**
	 * Normalizes a candidate URL/path.
	 *
	 * @param string $url Candidate URL/path.
	 * @return string
	 */
	private function normalize_candidate_url( $url ) {
		$url = trim( (string) $url );
		$url = trim( $url, " \t\n\r\0\x0B\"'" );

		return html_entity_decode( $url, ENT_QUOTES, 'UTF-8' );
	}

	/**
	 * Finds a media extension from a URL/path.
	 *
	 * @param string $url URL/path.
	 * @return string
	 */
	private function extension_for_url( $url ) {
		$path = $this->parse_url_part( $url, PHP_URL_PATH );

		if ( ! is_string( $path ) || '' === $path ) {
			$path = $this->strip_url_suffix( $url );
		}

		return strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );
	}

	/**
	 * Parses one URL part using WordPress' wrapper when available.
	 *
	 * @param string $url       URL/path.
	 * @param int    $component URL component constant.
	 * @return string|null
	 */
	private function parse_url_part( $url, $component ) {
		if ( function_exists( 'wp_parse_url' ) ) {
			$value = wp_parse_url( $url, $component );
		} else {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- Unit tests run without WordPress loaded.
			$value = parse_url( $url, $component );
		}

		return is_string( $value ) ? $value : null;
	}

	/**
	 * Builds a deterministic media reference key.
	 *
	 * @param ImportPreparedDocument $document Prepared document.
	 * @param string                 $url      Normalized original URL/path.
	 * @return string
	 */
	private function reference_key( ImportPreparedDocument $document, $url ) {
		return 'media:' . hash( 'sha256', $document->get_source_item_key() . "\n" . $url );
	}

	/**
	 * Gets confirmed first-party domains for a session.
	 *
	 * @param ImportSession $session Session.
	 * @return array<int,string>
	 */
	private function get_confirmed_domains( ImportSession $session ) {
		$decision = $this->store->find_decision( $session->get_id(), ImportUrlInference::DECISION_KEY );

		if ( null === $decision || ImportDecision::STATUS_RESOLVED !== $decision->get_status() ) {
			return array();
		}

		$answer = $decision->get_answer();

		if ( null === $answer || ! isset( $answer['confirmed_domains'] ) || ! is_array( $answer['confirmed_domains'] ) ) {
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
	 * Normalizes a URL host.
	 *
	 * @param mixed $domain Raw domain.
	 * @return string
	 */
	private function normalize_domain( $domain ) {
		$domain = strtolower( trim( (string) $domain ) );
		$domain = preg_replace( '/:\d+$/', '', $domain );

		return is_string( $domain ) ? $domain : '';
	}
}
