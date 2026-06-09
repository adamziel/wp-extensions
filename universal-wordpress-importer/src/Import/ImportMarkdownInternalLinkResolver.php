<?php
/**
 * Markdown internal link resolver.
 *
 * @package UniversalImporter
 */

namespace UniversalImporter\Import;

/**
 * Rewrites local Markdown document links to imported draft permalinks.
 */
final class ImportMarkdownInternalLinkResolver {
	const DEFAULT_DOCUMENT_LIMIT = 100;

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
	 * Advances Markdown internal link resolution for prepared documents.
	 *
	 * @param ImportSession $session Session.
	 * @param int           $limit   Maximum documents to inspect.
	 * @return array{resolved:int,deferred:int,skipped:int,failed:int,message:string}
	 */
	public function advance( ImportSession $session, $limit = self::DEFAULT_DOCUMENT_LIMIT ) {
		$limit   = max( 1, min( 250, (int) $limit ) );
		$summary = array(
			'resolved' => 0,
			'deferred' => 0,
			'skipped'  => 0,
			'failed'   => 0,
			'message'  => 'No Markdown internal links were ready for permalink resolution.',
		);

		if ( ! $this->posts->is_available() ) {
			$summary['message'] = $this->posts->get_unavailable_reason();
			return $summary;
		}

		$after_source_item_key = null;
		$processed_documents   = 0;

		do {
			$documents      = $this->store->list_prepared_documents_after_source_item_key(
				$session->get_id(),
				$after_source_item_key,
				$limit
			);
			$document_count = count( $documents );

			foreach ( $documents as $document ) {
				$after_source_item_key = $document->get_source_item_key();
				$result                = $this->resolve_document_links( $session, $document );

				if ( 'skipped' === $result ) {
					++$summary['skipped'];
					continue;
				}

				++$summary[ $result ];
				++$processed_documents;

				if ( $processed_documents >= $limit ) {
					break 2;
				}
			}
		} while ( $document_count === $limit );

		if ( 0 < $summary['resolved'] || 0 < $summary['deferred'] || 0 < $summary['failed'] ) {
			$summary['message'] = 'Markdown internal link resolution inspected staged documents.';
		}

		return $summary;
	}

	/**
	 * Returns whether prepared Markdown documents still contain resolvable local Markdown links.
	 *
	 * @param ImportSession $session Session.
	 * @return bool
	 */
	public function has_unresolved_links( ImportSession $session ) {
		$limit                 = 500;
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

				if ( 'markdown' !== $document->get_format() ) {
					continue;
				}

				foreach ( $this->local_markdown_links( $document ) as $link ) {
					$target_key = $this->target_document_key( $document, $link );

					if ( null === $target_key ) {
						continue;
					}

					if ( null === $this->store->find_idempotency_record( $session->get_id(), 'post:' . $target_key ) ) {
						return true;
					}
				}
			}
		} while ( $document_count === $limit );

		return false;
	}

	/**
	 * Resolves links for one prepared Markdown document.
	 *
	 * @param ImportSession          $session  Session.
	 * @param ImportPreparedDocument $document Prepared document.
	 * @return string Summary bucket.
	 */
	private function resolve_document_links( ImportSession $session, ImportPreparedDocument $document ) {
		if ( 'markdown' !== $document->get_format() ) {
			return 'skipped';
		}

		$links = $this->local_markdown_links( $document );

		if ( empty( $links ) ) {
			return 'skipped';
		}

		$metadata        = $document->get_metadata();
		$block_markup    = $document->get_block_markup();
		$resolved_links  = isset( $metadata['markdown_internal_links_resolved'] ) && is_array( $metadata['markdown_internal_links_resolved'] ) ? $metadata['markdown_internal_links_resolved'] : array();
		$deferred_links  = array();
		$rewritten_count = 0;

		foreach ( $links as $link ) {
			$target_key = $this->target_document_key( $document, $link );

			if ( null === $target_key ) {
				continue;
			}

			$record = $this->store->find_idempotency_record( $session->get_id(), 'post:' . $target_key );

			if ( null === $record || 'post' !== $record->get_resource_type() ) {
				$deferred_links[] = $link;
				continue;
			}

			$permalink = $this->posts->get_permalink( (int) $record->get_resource_id() );

			if ( null === $permalink ) {
				$deferred_links[] = $link;
				continue;
			}

			$resolved_href = $this->resolved_href( $permalink, $link );
			$next_markup   = $this->replace_href( $block_markup, (string) $link['href'], $resolved_href );

			if ( $next_markup === $block_markup ) {
				continue;
			}

			$block_markup                   = $next_markup;
			$link['target_source_item_key'] = $target_key;
			$link['resolved_href']          = $resolved_href;
			$link['resolved_post_id']       = (int) $record->get_resource_id();
			$link['resolved_at_unix_time']  = time();
			$resolved_links[]               = $link;
			++$rewritten_count;
		}

		if ( 0 === $rewritten_count ) {
			$this->record_deferred_event( $session, $document, $deferred_links );
			return empty( $deferred_links ) ? 'skipped' : 'deferred';
		}

		$metadata['markdown_internal_links']          = array_values( $deferred_links );
		$metadata['markdown_internal_links_resolved'] = array_values( $resolved_links );
		$metadata['markdown_internal_links_status']   = empty( $deferred_links ) ? 'resolved' : 'partial';

		$content_hash = hash( 'sha256', 'markdown-link-resolved' . "\n" . $document->get_source_item_key() . "\n" . $block_markup );
		$this->store->save_prepared_document( $document->with_rewritten_block_markup( $block_markup, $content_hash, $metadata ) );

		$this->store->record_event(
			$session->get_id(),
			new ImportProgressEvent(
				ImportProgressEvent::LEVEL_INFO,
				'markdown.internal_links_resolved',
				'Markdown document links were resolved to imported draft permalinks.',
				array(
					'source_item_key' => $document->get_source_item_key(),
					'resolved'        => $rewritten_count,
					'deferred'        => count( $deferred_links ),
				)
			)
		);

		return 'resolved';
	}

	/**
	 * Finds local Markdown document hrefs in one prepared document.
	 *
	 * @param ImportPreparedDocument $document Prepared document.
	 * @return array<int,array{href:string,path:string,fragment:string}>
	 */
	private function local_markdown_links( ImportPreparedDocument $document ) {
		$links = array();

		preg_match_all( '/\bhref\s*=\s*(["\'])(.*?)\1/is', $document->get_block_markup(), $matches, PREG_SET_ORDER );

		foreach ( $matches as $match ) {
			$href = html_entity_decode( $match[2], ENT_QUOTES, 'UTF-8' );

			if ( ! $this->is_local_markdown_href( $href ) ) {
				continue;
			}

			// phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- WordPress is not loaded in importer unit tests.
			$parts = parse_url( $href );

			if ( false === $parts || empty( $parts['path'] ) ) {
				continue;
			}

			$links[] = array(
				'href'     => $href,
				'path'     => $parts['path'],
				'fragment' => isset( $parts['fragment'] ) ? (string) $parts['fragment'] : '',
			);
		}

		return $links;
	}

	/**
	 * Returns whether a link points to another local Markdown document.
	 *
	 * @param string $href Link href.
	 * @return bool
	 */
	private function is_local_markdown_href( $href ) {
		$href = trim( (string) $href );

		if ( '' === $href || 0 === strpos( $href, '#' ) || 0 === strpos( $href, '//' ) ) {
			return false;
		}

		if ( preg_match( '/^[a-z][a-z0-9+.-]*:/i', $href ) ) {
			return false;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- WordPress is not loaded in importer unit tests.
		$path = parse_url( $href, PHP_URL_PATH );

		if ( ! is_string( $path ) || '' === $path ) {
			return false;
		}

		return (bool) preg_match( '/\.(?:md|markdown|mdown|mdx|mdoc|markdoc)$/i', $path );
	}

	/**
	 * Builds the prepared document key for a target local Markdown file.
	 *
	 * @param ImportPreparedDocument                         $document Source document.
	 * @param array{href:string,path:string,fragment:string} $link Link metadata.
	 * @return string|null
	 */
	private function target_document_key( ImportPreparedDocument $document, array $link ) {
		$metadata = $document->get_metadata();
		$github   = $this->github_target_document_key( $document, $link, $metadata );

		if ( null !== $github ) {
			return $github;
		}

		$source = isset( $metadata['source_uri'] ) ? (string) $metadata['source_uri'] : '';

		if ( '' === $source || preg_match( '#^[a-z][a-z0-9+.-]*://#i', $source ) ) {
			return null;
		}

		$link_path   = rawurldecode( (string) $link['path'] );
		$target_base = '/' === substr( $link_path, 0, 1 )
			? $this->local_import_root( $source, $metadata )
			: dirname( $source );
		$target_path = str_replace( '/', DIRECTORY_SEPARATOR, ltrim( $link_path, '/' ) );
		$target      = $this->normalize_local_path( $target_base . DIRECTORY_SEPARATOR . $target_path );
		$real        = realpath( $target );

		if ( false === $real || ! is_file( $real ) ) {
			return null;
		}

		$item_key = 'local:' . hash( 'sha256', $this->normalize_local_path( $real ) );

		return null === $this->store->find_source_item( $document->get_session_id(), $item_key ) ? null : $item_key;
	}

	/**
	 * Builds a prepared document key for a target GitHub Markdown file.
	 *
	 * @param ImportPreparedDocument                         $document Source document.
	 * @param array{href:string,path:string,fragment:string} $link Link metadata.
	 * @param array<string,mixed>                            $metadata Prepared document metadata.
	 * @return string|null
	 */
	private function github_target_document_key( ImportPreparedDocument $document, array $link, array $metadata ) {
		foreach ( array( 'github_owner', 'github_repository', 'github_ref', 'github_tree_path' ) as $required ) {
			if ( empty( $metadata[ $required ] ) ) {
				return null;
			}
		}

		$current_path = $this->normalize_repository_path( (string) $metadata['github_tree_path'] );
		$link_path    = $this->decode_repository_link_path( (string) $link['path'] );
		$target_path  = '/' === substr( $link_path, 0, 1 )
			? $this->normalize_repository_path( ltrim( $link_path, '/' ) )
			: $this->normalize_repository_path( dirname( $current_path ) . '/' . $link_path );

		if ( '' === $target_path ) {
			return null;
		}

		$hash = hash(
			'sha256',
			strtolower( (string) $metadata['github_owner'] ) . '/' . strtolower( (string) $metadata['github_repository'] )
				. "\n" . (string) $metadata['github_ref']
				. "\n" . $target_path
		);

		foreach ( array( 'github-blob:' . $hash, 'github-git-blob:' . $hash ) as $item_key ) {
			if ( null !== $this->store->find_source_item( $document->get_session_id(), $item_key ) ) {
				return $item_key;
			}
		}

		return null;
	}

	/**
	 * Decodes URL-encoded path segments without changing path separators.
	 *
	 * @param string $path Link path.
	 * @return string
	 */
	private function decode_repository_link_path( $path ) {
		$segments = explode( '/', str_replace( '\\', '/', (string) $path ) );

		foreach ( $segments as $index => $segment ) {
			$segments[ $index ] = rawurldecode( $segment );
		}

		return implode( '/', $segments );
	}

	/**
	 * Normalizes a repository-relative path.
	 *
	 * @param string $path Path.
	 * @return string
	 */
	private function normalize_repository_path( $path ) {
		$segments = array();
		foreach ( explode( '/', str_replace( '\\', '/', (string) $path ) ) as $segment ) {
			if ( '' === $segment || '.' === $segment ) {
				continue;
			}
			if ( '..' === $segment ) {
				array_pop( $segments );
				continue;
			}
			$segments[] = $segment;
		}

		return implode( '/', $segments );
	}

	/**
	 * Returns the local import root for root-relative Markdown document links.
	 *
	 * @param string              $source   Source file path.
	 * @param array<string,mixed> $metadata Prepared document metadata.
	 * @return string
	 */
	private function local_import_root( $source, array $metadata ) {
		$relative_path = isset( $metadata['relative_path'] ) ? str_replace( '/', DIRECTORY_SEPARATOR, (string) $metadata['relative_path'] ) : '';
		$source        = $this->normalize_local_path( $source );

		if ( '' === $relative_path ) {
			return dirname( $source );
		}

		$suffix = DIRECTORY_SEPARATOR . ltrim( $relative_path, DIRECTORY_SEPARATOR );

		if ( substr( $source, -strlen( $suffix ) ) === $suffix ) {
			return rtrim( substr( $source, 0, -strlen( $suffix ) ), DIRECTORY_SEPARATOR );
		}

		return dirname( $source );
	}

	/**
	 * Normalizes a local filesystem path.
	 *
	 * @param string $path Path.
	 * @return string
	 */
	private function normalize_local_path( $path ) {
		$real = realpath( $path );

		return false === $real ? (string) $path : $real;
	}

	/**
	 * Builds the final href from a post permalink and original Markdown fragment.
	 *
	 * @param string                                         $permalink Imported post permalink.
	 * @param array{href:string,path:string,fragment:string} $link Link metadata.
	 * @return string
	 */
	private function resolved_href( $permalink, array $link ) {
		if ( '' === $link['fragment'] ) {
			return (string) $permalink;
		}

		return preg_replace( '/#.*$/', '', (string) $permalink ) . '#' . ltrim( $link['fragment'], '#' );
	}

	/**
	 * Replaces one href value in block markup.
	 *
	 * @param string $block_markup Prepared block markup.
	 * @param string $from_href    Original href.
	 * @param string $to_href      Resolved href.
	 * @return string
	 */
	private function replace_href( $block_markup, $from_href, $to_href ) {
		return preg_replace_callback(
			'/\bhref\s*=\s*(["\'])(.*?)\1/is',
			function ( $matches ) use ( $from_href, $to_href ) {
				$current = html_entity_decode( $matches[2], ENT_QUOTES, 'UTF-8' );

				if ( $current !== $from_href ) {
					return $matches[0];
				}

				return 'href=' . $matches[1] . htmlspecialchars( $to_href, ENT_QUOTES, 'UTF-8' ) . $matches[1];
			},
			(string) $block_markup
		);
	}

	/**
	 * Records a bounded deferred diagnostic when pending links are blocked.
	 *
	 * @param ImportSession                  $session        Session.
	 * @param ImportPreparedDocument         $document       Prepared document.
	 * @param array<int,array<string,mixed>> $deferred_links Deferred links.
	 * @return void
	 */
	private function record_deferred_event( ImportSession $session, ImportPreparedDocument $document, array $deferred_links ) {
		if ( empty( $deferred_links ) ) {
			return;
		}

		$this->store->record_event(
			$session->get_id(),
			new ImportProgressEvent(
				ImportProgressEvent::LEVEL_WARNING,
				'markdown.internal_links_deferred',
				'Markdown document links are waiting for target pages or permalinks.',
				array(
					'source_item_key' => $document->get_source_item_key(),
					'deferred'        => count( $deferred_links ),
				)
			)
		);
	}
}
