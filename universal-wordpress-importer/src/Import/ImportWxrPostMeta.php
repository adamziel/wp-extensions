<?php
/**
 * WXR postmeta normalization helpers.
 *
 * @package UniversalImporter
 */

namespace UniversalImporter\Import;

/**
 * Normalizes and filters WXR postmeta staged on prepared documents.
 */
final class ImportWxrPostMeta {
	/**
	 * Keys that should not be copied directly from WXR postmeta.
	 *
	 * These values are volatile editor state or references that need a later
	 * relationship/media remapping pass before they can be restored safely.
	 *
	 * @var array<string,bool>
	 */
	private static $reserved_keys = array(
		'_edit_last'    => true,
		'_edit_lock'    => true,
		'_thumbnail_id' => true,
	);

	/**
	 * Returns normalized WXR postmeta entries from a prepared document.
	 *
	 * @param ImportPreparedDocument $document Prepared document.
	 * @return array<int,array{key:string,value:string,source:string}>
	 */
	public static function entries_from_document( ImportPreparedDocument $document ) {
		$metadata = $document->get_metadata();

		if ( empty( $metadata['wxr_postmeta'] ) || ! is_array( $metadata['wxr_postmeta'] ) ) {
			return array();
		}

		$entries = array();

		foreach ( $metadata['wxr_postmeta'] as $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}

			$key = isset( $entry['key'] ) ? trim( (string) $entry['key'] ) : '';

			if ( '' === $key ) {
				continue;
			}

			$entries[] = array(
				'key'    => $key,
				'value'  => isset( $entry['value'] ) ? (string) $entry['value'] : '',
				'source' => isset( $entry['source'] ) ? (string) $entry['source'] : 'wxr',
			);
		}

		return $entries;
	}

	/**
	 * Returns a stable hash for the staged WXR postmeta payload.
	 *
	 * @param ImportPreparedDocument $document Prepared document.
	 * @return int|string
	 */
	public static function payload_hash( ImportPreparedDocument $document ) {
		$json = function_exists( 'wp_json_encode' )
			? wp_json_encode( self::entries_from_document( $document ) )
			// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Unit tests run without WordPress loaded.
			: json_encode( self::entries_from_document( $document ) );

		return hash( 'sha256', false === $json ? '[]' : (string) $json );
	}

	/**
	 * Returns a prepared document copy with WXR attachment ids and URLs remapped in postmeta.
	 *
	 * @param ImportPreparedDocument $document               Prepared document.
	 * @param array<int|string,int>  $attachment_id_map      Remote WXR attachment id to local attachment id map.
	 * @param array<string,string>   $attachment_url_map     Source attachment URL to local attachment URL map.
	 * @param array<int|string,bool> $pending_attachment_ids  Remote WXR attachment ids waiting for import.
	 * @param array<string,bool>     $pending_attachment_urls Source attachment URLs waiting for import.
	 * @return array{document:ImportPreparedDocument,remapped:int,deferred_ids:array<int,string>,deferred_urls:array<int,string>}
	 */
	public static function remap_document_attachment_references( ImportPreparedDocument $document, array $attachment_id_map, array $attachment_url_map, array $pending_attachment_ids = array(), array $pending_attachment_urls = array() ) {
		$metadata = $document->get_metadata();
		$entries  = self::entries_from_document( $document );
		$state    = array(
			'remapped'      => 0,
			'deferred_ids'  => array(),
			'deferred_urls' => array(),
		);
		$remapped = array();

		foreach ( $entries as $entry ) {
			if ( self::should_skip_key( $entry['key'] ) ) {
				$remapped[] = $entry;
				continue;
			}

			$entry['value'] = self::remap_meta_value(
				$entry['value'],
				$entry['key'],
				false,
				false,
				$attachment_id_map,
				$attachment_url_map,
				$pending_attachment_ids,
				$pending_attachment_urls,
				$state
			);
			$remapped[]     = $entry;
		}

		$metadata['wxr_postmeta']       = $remapped;
		$metadata['wxr_postmeta_count'] = count( $remapped );

		if ( 0 < $state['remapped'] ) {
			$metadata['wxr_postmeta_remapping'] = array(
				'complete' => true,
				'remapped' => (int) $state['remapped'],
			);
		}

		return array(
			'document'      => $document->with_metadata( $metadata ),
			'remapped'      => (int) $state['remapped'],
			'deferred_ids'  => array_values( array_unique( $state['deferred_ids'] ) ),
			'deferred_urls' => array_values( array_unique( $state['deferred_urls'] ) ),
		);
	}

	/**
	 * Returns WXR post/page ids that appear in post/page-reference contexts.
	 *
	 * @param ImportPreparedDocument $document Prepared document.
	 * @return array<int,string>
	 */
	public static function post_reference_ids_from_document( ImportPreparedDocument $document ) {
		$state = array(
			'ids' => array(),
		);

		foreach ( self::entries_from_document( $document ) as $entry ) {
			if ( self::should_skip_key( $entry['key'] ) ) {
				continue;
			}

			self::collect_post_reference_ids_from_value( $entry['value'], $entry['key'], false, false, $state );
		}

		return array_values( array_unique( $state['ids'] ) );
	}

	/**
	 * Returns a prepared document copy with WXR post/page ids remapped in postmeta.
	 *
	 * @param ImportPreparedDocument $document         Prepared document.
	 * @param array<int|string,int>  $post_id_map      Remote WXR post id to local post id map.
	 * @param array<int|string,bool> $pending_post_ids Remote WXR post ids waiting for import.
	 * @return array{document:ImportPreparedDocument,remapped:int,deferred_ids:array<int,string>,deferred_post_ids:array<int,string>}
	 */
	public static function remap_document_post_references( ImportPreparedDocument $document, array $post_id_map, array $pending_post_ids = array() ) {
		$metadata = $document->get_metadata();
		$entries  = self::entries_from_document( $document );
		$state    = array(
			'remapped'          => 0,
			'deferred_post_ids' => array(),
		);
		$remapped = array();

		foreach ( $entries as $entry ) {
			if ( self::should_skip_key( $entry['key'] ) ) {
				$remapped[] = $entry;
				continue;
			}

			$entry['value'] = self::remap_post_reference_value(
				$entry['value'],
				$entry['key'],
				false,
				false,
				$post_id_map,
				$pending_post_ids,
				$state
			);
			$remapped[]     = $entry;
		}

		$metadata['wxr_postmeta']       = $remapped;
		$metadata['wxr_postmeta_count'] = count( $remapped );

		if ( 0 < $state['remapped'] ) {
			$previous_remapping = isset( $metadata['wxr_postmeta_remapping'] ) && is_array( $metadata['wxr_postmeta_remapping'] )
				? $metadata['wxr_postmeta_remapping']
				: array();

			$metadata['wxr_postmeta_remapping'] = array_merge(
				$previous_remapping,
				array(
					'complete'          => true,
					'remapped'          => ( isset( $previous_remapping['remapped'] ) ? (int) $previous_remapping['remapped'] : 0 ) + (int) $state['remapped'],
					'post_ids_remapped' => (int) $state['remapped'],
				)
			);
		}

		return array(
			'document'          => $document->with_metadata( $metadata ),
			'remapped'          => (int) $state['remapped'],
			'deferred_ids'      => array(),
			'deferred_post_ids' => array_values( array_unique( $state['deferred_post_ids'] ) ),
		);
	}

	/**
	 * Returns a prepared document copy with confirmed first-party URLs rewritten in postmeta.
	 *
	 * @param ImportPreparedDocument $document          Prepared document.
	 * @param array<int,string>      $confirmed_domains Confirmed first-party domains.
	 * @param string                 $local_site_url    Local public site URL.
	 * @return array{document:ImportPreparedDocument,rewritten:int}
	 */
	public static function remap_document_first_party_urls( ImportPreparedDocument $document, array $confirmed_domains, $local_site_url ) {
		$domains = array();

		foreach ( $confirmed_domains as $domain ) {
			$domain = self::normalize_domain( $domain );

			if ( '' !== $domain ) {
				$domains[ $domain ] = true;
			}
		}

		if ( empty( $domains ) ) {
			return array(
				'document'  => $document,
				'rewritten' => 0,
			);
		}

		$metadata = $document->get_metadata();
		$entries  = self::entries_from_document( $document );
		$state    = array( 'rewritten' => 0 );
		$remapped = array();

		foreach ( $entries as $entry ) {
			if ( self::should_skip_key( $entry['key'] ) ) {
				$remapped[] = $entry;
				continue;
			}

			$entry['value'] = self::remap_first_party_url_value( $entry['value'], $domains, self::normalize_local_site_url( $local_site_url ), $state );
			$remapped[]     = $entry;
		}

		$metadata['wxr_postmeta']       = $remapped;
		$metadata['wxr_postmeta_count'] = count( $remapped );

		if ( 0 < $state['rewritten'] ) {
			$previous_remapping = isset( $metadata['wxr_postmeta_remapping'] ) && is_array( $metadata['wxr_postmeta_remapping'] )
				? $metadata['wxr_postmeta_remapping']
				: array();

			$metadata['wxr_postmeta_remapping'] = array_merge(
				$previous_remapping,
				array(
					'complete'                   => true,
					'remapped'                   => ( isset( $previous_remapping['remapped'] ) ? (int) $previous_remapping['remapped'] : 0 ) + (int) $state['rewritten'],
					'first_party_urls_rewritten' => (int) $state['rewritten'],
				)
			);
		}

		return array(
			'document'  => $document->with_metadata( $metadata ),
			'rewritten' => (int) $state['rewritten'],
		);
	}

	/**
	 * Whether a WXR postmeta key should be skipped by the direct persistence pass.
	 *
	 * @param string $key Meta key.
	 * @return bool
	 */
	public static function should_skip_key( $key ) {
		$key = trim( (string) $key );

		return isset( self::$reserved_keys[ $key ] ) || 0 === strpos( $key, '_universal_importer_' );
	}

	/**
	 * Converts a WXR meta value to the value WordPress should store.
	 *
	 * @param string $value Raw WXR meta value.
	 * @return mixed
	 */
	public static function maybe_unserialize_value( $value ) {
		if ( function_exists( 'maybe_unserialize' ) ) {
			return maybe_unserialize( (string) $value );
		}

		if ( self::is_serialized_value( (string) $value ) ) {
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.PHP.DiscouragedPHPFunctions -- Serialized WXR postmeta may be malformed; callers treat malformed values as raw strings.
			$unserialized = @unserialize( (string) $value, array( 'allowed_classes' => false ) );

			if ( false !== $unserialized || 'b:0;' === (string) $value ) {
				return $unserialized;
			}
		}

		return (string) $value;
	}

	/**
	 * Remaps attachment references in a meta value.
	 *
	 * @param mixed                  $value                   Meta value or nested serialized value.
	 * @param string|int             $field_key               Current meta/array key.
	 * @param bool                   $id_collection_context   Whether parent structure is an attachment id list.
	 * @param bool                   $attachment_object_context Whether parent structure is an attachment object.
	 * @param array<int|string,int>  $attachment_id_map       Remote id to local id map.
	 * @param array<string,string>   $attachment_url_map      Source URL to local URL map.
	 * @param array<int|string,bool> $pending_attachment_ids  Remote ids waiting for import.
	 * @param array<string,bool>     $pending_attachment_urls Source URLs waiting for import.
	 * @param array<string,mixed>    $state                   Mutable remap/defer state.
	 * @return mixed
	 */
	private static function remap_meta_value( $value, $field_key, $id_collection_context, $attachment_object_context, array $attachment_id_map, array $attachment_url_map, array $pending_attachment_ids, array $pending_attachment_urls, array &$state ) {
		$field_key                = (string) $field_key;
		$id_sensitive_context     = $id_collection_context || self::is_attachment_id_key( $field_key ) || ( $attachment_object_context && self::is_generic_id_key( $field_key ) );
		$child_collection_context = $id_collection_context || self::is_attachment_id_collection_key( $field_key );
		$child_attachment_context = $attachment_object_context || self::is_attachment_object_key( $field_key );

		if ( is_array( $value ) ) {
			foreach ( $value as $key => $nested_value ) {
				$value[ $key ] = self::remap_meta_value(
					$nested_value,
					$key,
					$child_collection_context,
					$child_attachment_context,
					$attachment_id_map,
					$attachment_url_map,
					$pending_attachment_ids,
					$pending_attachment_urls,
					$state
				);
			}

			return $value;
		}

		if ( is_int( $value ) || is_float( $value ) ) {
			return $id_sensitive_context ? self::remap_numeric_attachment_id( (string) (int) $value, $attachment_id_map, $pending_attachment_ids, $state ) : $value;
		}

		if ( ! is_string( $value ) ) {
			return $value;
		}

		$serialized = self::maybe_decode_serialized_value( $value );

		if ( null !== $serialized ) {
			$decoded = self::remap_meta_value(
				$serialized,
				$field_key,
				$id_collection_context,
				$attachment_object_context,
				$attachment_id_map,
				$attachment_url_map,
				$pending_attachment_ids,
				$pending_attachment_urls,
				$state
			);

			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- WXR postmeta commonly stores PHP-serialized plugin data.
			return serialize( $decoded );
		}

		$value = self::remap_attachment_urls( $value, $attachment_url_map, $pending_attachment_urls, $state );

		if ( $id_sensitive_context ) {
			$value = self::remap_attachment_id_string( $value, $attachment_id_map, $pending_attachment_ids, $state );
		}

		return $value;
	}

	/**
	 * Remaps a scalar string that may contain one or more attachment ids.
	 *
	 * @param string                 $value                  Value.
	 * @param array<int|string,int>  $attachment_id_map      Remote id to local id map.
	 * @param array<int|string,bool> $pending_attachment_ids Remote ids waiting for import.
	 * @param array<string,mixed>    $state                  Mutable remap/defer state.
	 * @return string
	 */
	private static function remap_attachment_id_string( $value, array $attachment_id_map, array $pending_attachment_ids, array &$state ) {
		if ( preg_match( '/^\s*\d+(?:\s*,\s*\d+)*\s*$/', $value ) ) {
			$ids      = preg_split( '/\s*,\s*/', trim( $value ) );
			$remapped = array();

			foreach ( false === $ids ? array() : $ids as $id ) {
				$remapped[] = self::remap_numeric_attachment_id( $id, $attachment_id_map, $pending_attachment_ids, $state );
			}

			if ( 1 === count( $remapped ) ) {
				return $remapped[0];
			}

			return implode( ',', array_map( 'strval', $remapped ) );
		}

		return $value;
	}

	/**
	 * Collects numeric WXR post/page references from a meta value.
	 *
	 * @param mixed               $value                 Meta value or nested serialized value.
	 * @param string|int          $field_key             Current meta/array key.
	 * @param bool                $id_collection_context Whether parent structure is a post/page id list.
	 * @param bool                $post_object_context   Whether parent structure is a post/page object.
	 * @param array<string,mixed> $state                 Mutable collection state.
	 * @return void
	 */
	private static function collect_post_reference_ids_from_value( $value, $field_key, $id_collection_context, $post_object_context, array &$state ) {
		$field_key                = (string) $field_key;
		$id_sensitive_context     = $id_collection_context || self::is_post_id_key( $field_key ) || ( $post_object_context && self::is_generic_id_key( $field_key ) );
		$child_collection_context = $id_collection_context || self::is_post_id_collection_key( $field_key );
		$child_post_context       = $post_object_context || self::is_post_object_key( $field_key );

		if ( is_array( $value ) ) {
			foreach ( $value as $key => $nested_value ) {
				self::collect_post_reference_ids_from_value( $nested_value, $key, $child_collection_context, $child_post_context, $state );
			}
			return;
		}

		if ( is_int( $value ) || is_float( $value ) ) {
			if ( $id_sensitive_context ) {
				self::remember_post_reference_id( (string) (int) $value, $state );
			}
			return;
		}

		if ( ! is_string( $value ) ) {
			return;
		}

		$serialized = self::maybe_decode_serialized_value( $value );

		if ( null !== $serialized ) {
			self::collect_post_reference_ids_from_value( $serialized, $field_key, $id_collection_context, $post_object_context, $state );
			return;
		}

		if ( $id_sensitive_context && preg_match( '/^\s*\d+(?:\s*,\s*\d+)*\s*$/', $value ) ) {
			$ids = preg_split( '/\s*,\s*/', trim( $value ) );

			foreach ( false === $ids ? array() : $ids as $id ) {
				self::remember_post_reference_id( $id, $state );
			}
		}
	}

	/**
	 * Remaps WXR post/page references in a meta value.
	 *
	 * @param mixed                  $value                 Meta value or nested serialized value.
	 * @param string|int             $field_key             Current meta/array key.
	 * @param bool                   $id_collection_context Whether parent structure is a post/page id list.
	 * @param bool                   $post_object_context   Whether parent structure is a post/page object.
	 * @param array<int|string,int>  $post_id_map           Remote id to local post id map.
	 * @param array<int|string,bool> $pending_post_ids      Remote ids waiting for import.
	 * @param array<string,mixed>    $state                 Mutable remap/defer state.
	 * @return mixed
	 */
	private static function remap_post_reference_value( $value, $field_key, $id_collection_context, $post_object_context, array $post_id_map, array $pending_post_ids, array &$state ) {
		$field_key                = (string) $field_key;
		$id_sensitive_context     = $id_collection_context || self::is_post_id_key( $field_key ) || ( $post_object_context && self::is_generic_id_key( $field_key ) );
		$child_collection_context = $id_collection_context || self::is_post_id_collection_key( $field_key );
		$child_post_context       = $post_object_context || self::is_post_object_key( $field_key );

		if ( is_array( $value ) ) {
			foreach ( $value as $key => $nested_value ) {
				$value[ $key ] = self::remap_post_reference_value(
					$nested_value,
					$key,
					$child_collection_context,
					$child_post_context,
					$post_id_map,
					$pending_post_ids,
					$state
				);
			}

			return $value;
		}

		if ( is_int( $value ) || is_float( $value ) ) {
			return $id_sensitive_context ? self::remap_numeric_post_id( (string) (int) $value, $post_id_map, $pending_post_ids, $state ) : $value;
		}

		if ( ! is_string( $value ) ) {
			return $value;
		}

		$serialized = self::maybe_decode_serialized_value( $value );

		if ( null !== $serialized ) {
			$decoded = self::remap_post_reference_value(
				$serialized,
				$field_key,
				$id_collection_context,
				$post_object_context,
				$post_id_map,
				$pending_post_ids,
				$state
			);

			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- WXR postmeta commonly stores PHP-serialized plugin data.
			return serialize( $decoded );
		}

		if ( $id_sensitive_context ) {
			$value = self::remap_post_id_string( $value, $post_id_map, $pending_post_ids, $state );
		}

		return $value;
	}

	/**
	 * Remaps a scalar string that may contain one or more post/page ids.
	 *
	 * @param string                 $value            Value.
	 * @param array<int|string,int>  $post_id_map      Remote id to local post id map.
	 * @param array<int|string,bool> $pending_post_ids Remote ids waiting for import.
	 * @param array<string,mixed>    $state            Mutable remap/defer state.
	 * @return string
	 */
	private static function remap_post_id_string( $value, array $post_id_map, array $pending_post_ids, array &$state ) {
		if ( preg_match( '/^\s*\d+(?:\s*,\s*\d+)*\s*$/', $value ) ) {
			$ids      = preg_split( '/\s*,\s*/', trim( $value ) );
			$remapped = array();

			foreach ( false === $ids ? array() : $ids as $id ) {
				$remapped[] = self::remap_numeric_post_id( $id, $post_id_map, $pending_post_ids, $state );
			}

			if ( 1 === count( $remapped ) ) {
				return (string) $remapped[0];
			}

			return implode( ',', array_map( 'strval', $remapped ) );
		}

		return $value;
	}

	/**
	 * Remaps one numeric post/page id if a local imported draft is known.
	 *
	 * @param string                 $value            Numeric value.
	 * @param array<int|string,int>  $post_id_map      Remote id to local post id map.
	 * @param array<int|string,bool> $pending_post_ids Remote ids waiting for import.
	 * @param array<string,mixed>    $state            Mutable remap/defer state.
	 * @return int|string
	 */
	private static function remap_numeric_post_id( $value, array $post_id_map, array $pending_post_ids, array &$state ) {
		$remote_id = (string) (int) $value;

		if ( isset( $post_id_map[ $remote_id ] ) ) {
			if ( (int) $post_id_map[ $remote_id ] !== (int) $remote_id ) {
				++$state['remapped'];
			}

			return (int) $post_id_map[ $remote_id ];
		}

		if ( isset( $pending_post_ids[ $remote_id ] ) ) {
			$state['deferred_post_ids'][] = $remote_id;
		}

		return $value;
	}

	/**
	 * Adds a normalized post/page reference id to collection state.
	 *
	 * @param string              $value Numeric value.
	 * @param array<string,mixed> $state Mutable collection state.
	 * @return void
	 */
	private static function remember_post_reference_id( $value, array &$state ) {
		$remote_id = (string) (int) $value;

		if ( '0' !== $remote_id ) {
			$state['ids'][] = $remote_id;
		}
	}

	/**
	 * Remaps one numeric attachment id if a local attachment is known.
	 *
	 * @param string                 $value                  Numeric value.
	 * @param array<int|string,int>  $attachment_id_map      Remote id to local id map.
	 * @param array<int|string,bool> $pending_attachment_ids Remote ids waiting for import.
	 * @param array<string,mixed>    $state                  Mutable remap/defer state.
	 * @return int|string
	 */
	private static function remap_numeric_attachment_id( $value, array $attachment_id_map, array $pending_attachment_ids, array &$state ) {
		$remote_id = (string) (int) $value;

		if ( isset( $attachment_id_map[ $remote_id ] ) ) {
			if ( (int) $attachment_id_map[ $remote_id ] !== (int) $remote_id ) {
				++$state['remapped'];
			}

			return (int) $attachment_id_map[ $remote_id ];
		}

		if ( isset( $pending_attachment_ids[ $remote_id ] ) ) {
			$state['deferred_ids'][] = $remote_id;
		}

		return $value;
	}

	/**
	 * Rewrites source attachment URLs to imported local attachment URLs.
	 *
	 * @param string               $value                   Meta string.
	 * @param array<string,string> $attachment_url_map     Source URL to local URL map.
	 * @param array<string,bool>   $pending_attachment_urls Source URLs waiting for import.
	 * @param array<string,mixed>  $state                   Mutable remap/defer state.
	 * @return string
	 */
	private static function remap_attachment_urls( $value, array $attachment_url_map, array $pending_attachment_urls, array &$state ) {
		foreach ( $attachment_url_map as $source_url => $attachment_url ) {
			if ( '' === $source_url || false === strpos( $value, (string) $source_url ) ) {
				continue;
			}

			$count              = 0;
			$value              = str_replace( (string) $source_url, (string) $attachment_url, $value, $count );
			$state['remapped'] += $count;
		}

		foreach ( $pending_attachment_urls as $source_url => $is_pending ) {
			if ( $is_pending && '' !== $source_url && false !== strpos( $value, (string) $source_url ) ) {
				$state['deferred_urls'][] = (string) $source_url;
			}
		}

		return $value;
	}

	/**
	 * Remaps confirmed first-party URLs inside scalar, array, or serialized meta values.
	 *
	 * @param mixed               $value          Meta value or nested serialized value.
	 * @param array<string,bool>  $domains        Confirmed domains keyed by host.
	 * @param string              $local_site_url Normalized local public site URL.
	 * @param array<string,mixed> $state          Mutable rewrite state.
	 * @return mixed
	 */
	private static function remap_first_party_url_value( $value, array $domains, $local_site_url, array &$state ) {
		if ( is_array( $value ) ) {
			foreach ( $value as $key => $nested_value ) {
				$value[ $key ] = self::remap_first_party_url_value( $nested_value, $domains, $local_site_url, $state );
			}

			return $value;
		}

		if ( ! is_string( $value ) ) {
			return $value;
		}

		$serialized = self::maybe_decode_serialized_value( $value );

		if ( null !== $serialized ) {
			$decoded = self::remap_first_party_url_value( $serialized, $domains, $local_site_url, $state );

			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- WXR postmeta commonly stores PHP-serialized plugin data.
			return serialize( $decoded );
		}

		$rewritten = preg_replace_callback(
			'#https?://[^\s<>"\'\)\]\}]+#i',
			function ( $matches ) use ( $domains, $local_site_url, &$state ) {
				$raw_url      = $matches[0];
				$trailing     = '';
				$candidate    = $raw_url;
				$last_checked = substr( $candidate, -1 );

				while ( false !== $last_checked && '' !== $candidate && preg_match( '/[.,;:]$/', $last_checked ) ) {
					$trailing     = $last_checked . $trailing;
					$candidate    = substr( $candidate, 0, -1 );
					$last_checked = substr( $candidate, -1 );
				}

				$parts = self::parse_url( html_entity_decode( $candidate, ENT_QUOTES, 'UTF-8' ) );

				if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
					return $raw_url;
				}

				$host = self::normalize_domain( $parts['host'] );

				if ( ! isset( $domains[ $host ] ) ) {
					return $raw_url;
				}

				++$state['rewritten'];

				return self::local_url_for_parts( $parts, $local_site_url ) . $trailing;
			},
			$value
		);

		return is_string( $rewritten ) ? $rewritten : $value;
	}

	/**
	 * Builds a local URL with the source path/query/fragment preserved.
	 *
	 * @param array<string,mixed> $parts          Parsed source URL parts.
	 * @param string              $local_site_url Normalized local public site URL.
	 * @return string
	 */
	private static function local_url_for_parts( array $parts, $local_site_url ) {
		$path = isset( $parts['path'] ) ? (string) $parts['path'] : '';
		$url  = rtrim( (string) $local_site_url, '/' ) . '/' . ltrim( $path, '/' );

		if ( isset( $parts['query'] ) && '' !== (string) $parts['query'] ) {
			$url .= '?' . (string) $parts['query'];
		}

		if ( isset( $parts['fragment'] ) && '' !== (string) $parts['fragment'] ) {
			$url .= '#' . (string) $parts['fragment'];
		}

		return $url;
	}

	/**
	 * Whether a meta or nested array key directly stores attachment ids.
	 *
	 * @param string $key Key.
	 * @return bool
	 */
	private static function is_attachment_id_key( $key ) {
		$key = strtolower( trim( (string) $key ) );

		if ( '' === $key ) {
			return false;
		}

		$normalized = preg_replace( '/[^a-z0-9_]+/', '_', $key );
		$normalized = is_string( $normalized ) ? trim( $normalized, '_' ) : $key;

		$exact = array(
			'_wp_image_gallery' => true,
			'attachment_id'     => true,
			'attachment_ids'    => true,
			'featured_media'    => true,
			'featured_media_id' => true,
			'gallery_id'        => true,
			'gallery_ids'       => true,
			'image_id'          => true,
			'image_ids'         => true,
			'media_id'          => true,
			'media_ids'         => true,
			'thumbnail_id'      => true,
			'thumbnail_ids'     => true,
		);

		if ( isset( $exact[ $key ] ) || isset( $exact[ $normalized ] ) ) {
			return true;
		}

		return (bool) preg_match( '/(?:attachment|featured_media|gallery|image|media|thumbnail).*_?ids?$/', $normalized );
	}

	/**
	 * Whether a key wraps a list of attachment ids.
	 *
	 * @param string $key Key.
	 * @return bool
	 */
	private static function is_attachment_id_collection_key( $key ) {
		$key        = strtolower( trim( (string) $key ) );
		$normalized = preg_replace( '/[^a-z0-9_]+/', '_', $key );
		$normalized = is_string( $normalized ) ? trim( $normalized, '_' ) : $key;

		return '_wp_image_gallery' === $key
			|| 'gallery' === $normalized
			|| 'attachments' === $normalized
			|| 'ids' === $normalized
			|| (bool) preg_match( '/(?:attachment|featured_media|gallery|image|media|thumbnail).*_?ids$/', $normalized );
	}

	/**
	 * Whether a key wraps an attachment-like object.
	 *
	 * @param string $key Key.
	 * @return bool
	 */
	private static function is_attachment_object_key( $key ) {
		$key        = strtolower( trim( (string) $key ) );
		$normalized = preg_replace( '/[^a-z0-9_]+/', '_', $key );
		$normalized = is_string( $normalized ) ? trim( $normalized, '_' ) : $key;

		return in_array( $normalized, array( 'attachment', 'featured_media', 'image', 'media', 'thumbnail' ), true );
	}

	/**
	 * Whether a key is a generic id key inside a known attachment-like object.
	 *
	 * @param string $key Key.
	 * @return bool
	 */
	private static function is_generic_id_key( $key ) {
		$key        = strtolower( trim( (string) $key ) );
		$normalized = preg_replace( '/[^a-z0-9_]+/', '_', $key );
		$normalized = is_string( $normalized ) ? trim( $normalized, '_' ) : $key;

		return in_array( $normalized, array( 'id', 'ids' ), true );
	}

	/**
	 * Whether a meta or nested array key directly stores WXR post/page ids.
	 *
	 * @param string $key Key.
	 * @return bool
	 */
	private static function is_post_id_key( $key ) {
		$key        = strtolower( trim( (string) $key ) );
		$normalized = preg_replace( '/[^a-z0-9_]+/', '_', $key );
		$normalized = is_string( $normalized ) ? trim( $normalized, '_' ) : $key;

		if ( self::is_non_post_relationship_key( $normalized ) ) {
			return false;
		}

		$exact = array(
			'child_page_id'     => true,
			'child_page_ids'    => true,
			'child_post_id'     => true,
			'child_post_ids'    => true,
			'linked_page_id'    => true,
			'linked_page_ids'   => true,
			'linked_post_id'    => true,
			'linked_post_ids'   => true,
			'page_id'           => true,
			'page_ids'          => true,
			'parent_page_id'    => true,
			'parent_page_ids'   => true,
			'parent_post_id'    => true,
			'parent_post_ids'   => true,
			'post_id'           => true,
			'post_ids'          => true,
			'related_page_id'   => true,
			'related_page_ids'  => true,
			'related_post_id'   => true,
			'related_post_ids'  => true,
			'selected_page_id'  => true,
			'selected_page_ids' => true,
			'selected_post_id'  => true,
			'selected_post_ids' => true,
		);

		if ( isset( $exact[ $normalized ] ) ) {
			return true;
		}

		return (bool) preg_match( '/(?:^|_)(?:post|page)s?_(?:ids?|parent_id|parent_ids)$/', $normalized );
	}

	/**
	 * Whether a key wraps a list of WXR post/page ids.
	 *
	 * @param string $key Key.
	 * @return bool
	 */
	private static function is_post_id_collection_key( $key ) {
		$key        = strtolower( trim( (string) $key ) );
		$normalized = preg_replace( '/[^a-z0-9_]+/', '_', $key );
		$normalized = is_string( $normalized ) ? trim( $normalized, '_' ) : $key;

		if ( self::is_non_post_relationship_key( $normalized ) ) {
			return false;
		}

		return in_array(
			$normalized,
			array(
				'child_pages',
				'child_posts',
				'linked_pages',
				'linked_posts',
				'page_ids',
				'pages',
				'post_ids',
				'posts',
				'related_pages',
				'related_posts',
				'selected_pages',
				'selected_posts',
			),
			true
		);
	}

	/**
	 * Whether a key wraps a WXR post/page-like object.
	 *
	 * @param string $key Key.
	 * @return bool
	 */
	private static function is_post_object_key( $key ) {
		$key        = strtolower( trim( (string) $key ) );
		$normalized = preg_replace( '/[^a-z0-9_]+/', '_', $key );
		$normalized = is_string( $normalized ) ? trim( $normalized, '_' ) : $key;

		if ( self::is_non_post_relationship_key( $normalized ) ) {
			return false;
		}

		return in_array(
			$normalized,
			array(
				'child_page',
				'child_post',
				'linked_page',
				'linked_post',
				'page',
				'page_object',
				'parent_page',
				'parent_post',
				'post',
				'post_object',
				'related_page',
				'related_post',
				'selected_page',
				'selected_post',
			),
			true
		);
	}

	/**
	 * Whether a normalized key is known to refer to another WordPress entity type.
	 *
	 * @param string $normalized Normalized key.
	 * @return bool
	 */
	private static function is_non_post_relationship_key( $normalized ) {
		if ( '' === $normalized ) {
			return false;
		}

		return (bool) preg_match( '/(?:^|_)(?:attachment|author|cat|category|comment|gallery|image|media|menu|tag|tax|taxonomy|term|thumbnail|user|users)(?:_|$)/', $normalized );
	}

	/**
	 * Parses a URL using WordPress' compatibility wrapper when available.
	 *
	 * @param string $url URL to parse.
	 * @return array<string,mixed>|false
	 */
	private static function parse_url( $url ) {
		if ( function_exists( 'wp_parse_url' ) ) {
			return wp_parse_url( $url );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- Unit tests run without WordPress loaded.
		return parse_url( $url );
	}

	/**
	 * Normalizes a URL host for exact-domain comparison.
	 *
	 * @param string $domain Raw domain.
	 * @return string
	 */
	private static function normalize_domain( $domain ) {
		$domain = strtolower( trim( (string) $domain ) );
		$domain = preg_replace( '/:\d+$/', '', $domain );

		return is_string( $domain ) ? $domain : '';
	}

	/**
	 * Normalizes the local public site URL.
	 *
	 * @param string $local_site_url Local public site URL.
	 * @return string
	 */
	private static function normalize_local_site_url( $local_site_url ) {
		$local_site_url = trim( (string) $local_site_url );

		if ( '' === $local_site_url ) {
			$local_site_url = 'http://example.org/';
		}

		return rtrim( $local_site_url, '/' ) . '/';
	}

	/**
	 * Decodes a serialized value if it is valid serialized PHP data.
	 *
	 * @param string $value Value.
	 * @return mixed|null Decoded value, or null when not serialized.
	 */
	private static function maybe_decode_serialized_value( $value ) {
		if ( ! self::is_serialized_value( $value ) ) {
			return null;
		}

		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.PHP.DiscouragedPHPFunctions -- Invalid serialized source data is left untouched by returning null.
		$decoded = @unserialize( $value, array( 'allowed_classes' => false ) );

		if ( false === $decoded && 'b:0;' !== $value ) {
			return null;
		}

		return $decoded;
	}

	/**
	 * Lightweight serialized-value check for unit tests without WordPress loaded.
	 *
	 * @param string $value Value.
	 * @return bool
	 */
	private static function is_serialized_value( $value ) {
		if ( function_exists( 'is_serialized' ) ) {
			return is_serialized( $value );
		}

		$value = trim( (string) $value );

		if ( 'N;' === $value || 'b:0;' === $value ) {
			return true;
		}

		if ( ! preg_match( '/^(?:a|O|s|i|d|b):/', $value ) ) {
			return false;
		}

		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.PHP.DiscouragedPHPFunctions -- This is a validation probe only.
		return false !== @unserialize( $value, array( 'allowed_classes' => false ) );
	}
}
