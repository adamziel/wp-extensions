<?php
/**
 * WXR attachment normalization helpers.
 *
 * @package UniversalImporter
 */

namespace UniversalImporter\Import;

/**
 * Normalizes WXR attachment posts for the shared media pipeline.
 */
final class ImportWxrAttachment {
	/**
	 * WXR attachment media reference scope before operator confirmation.
	 */
	const SCOPE_CANDIDATE_FIRST_PARTY = 'candidate-first-party-url';

	/**
	 * File extensions treated as importable WXR attachment media.
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
	 * Whether a WXR post entity is an attachment.
	 *
	 * @param array<string,mixed> $data WXR post data.
	 * @return bool
	 */
	public static function is_attachment_post( array $data ) {
		return isset( $data['post_type'] ) && 'attachment' === (string) $data['post_type'];
	}

	/**
	 * Builds the stable media reference key for a WXR attachment id.
	 *
	 * @param string     $source_item_key WXR source item key.
	 * @param string|int $attachment_id   WXR attachment post id.
	 * @return string
	 */
	public static function reference_key( $source_item_key, $attachment_id ) {
		return 'wxr-attachment:' . hash( 'sha256', (string) $source_item_key . "\n" . (string) $attachment_id );
	}

	/**
	 * Builds a stable source item key for a WXR attachment media reference.
	 *
	 * @param string     $source_item_key WXR source item key.
	 * @param string|int $attachment_id   WXR attachment post id.
	 * @return string
	 */
	public static function source_item_key( $source_item_key, $attachment_id ) {
		return (string) $source_item_key . ':wxr-attachment:' . (string) $attachment_id;
	}

	/**
	 * Returns the WXR attachment id from a post entity.
	 *
	 * @param array<string,mixed> $data WXR post data.
	 * @return string|null
	 */
	public static function attachment_id_from_post( array $data ) {
		$post_id = isset( $data['post_id'] ) ? trim( (string) $data['post_id'] ) : '';

		return '' === $post_id ? null : $post_id;
	}

	/**
	 * Returns the attachment source URL from a WXR attachment post.
	 *
	 * @param array<string,mixed> $data WXR post data.
	 * @return string|null
	 */
	public static function source_url_from_post( array $data ) {
		$url = isset( $data['attachment_url'] ) ? trim( (string) $data['attachment_url'] ) : '';

		if ( '' === $url && isset( $data['guid'] ) ) {
			$url = trim( (string) $data['guid'] );
		}

		return preg_match( '#^https?://#i', $url ) ? $url : null;
	}

	/**
	 * Returns the media type for an attachment URL, or null when unsupported.
	 *
	 * @param string $url Attachment URL.
	 * @return string|null
	 */
	public static function media_type_for_url( $url ) {
		$extension = self::extension_for_url( $url );

		return isset( self::$media_extensions[ $extension ] ) ? self::$media_extensions[ $extension ] : null;
	}

	/**
	 * Returns the normalized extension for an attachment URL.
	 *
	 * @param string $url Attachment URL.
	 * @return string
	 */
	public static function extension_for_url( $url ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- Unit tests run without WordPress loaded.
		$path = parse_url( (string) $url, PHP_URL_PATH );
		$path = is_string( $path ) ? $path : (string) $url;

		return strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );
	}

	/**
	 * Returns the host from an attachment URL.
	 *
	 * @param string $url Attachment URL.
	 * @return string
	 */
	public static function domain_for_url( $url ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- Unit tests run without WordPress loaded.
		$host = parse_url( (string) $url, PHP_URL_HOST );
		$host = is_string( $host ) ? strtolower( trim( $host ) ) : '';
		$host = preg_replace( '/:\d+$/', '', $host );

		return is_string( $host ) ? $host : '';
	}

	/**
	 * Returns a staged WXR thumbnail remote attachment id from a prepared document.
	 *
	 * @param ImportPreparedDocument $document Prepared document.
	 * @return int|null
	 */
	public static function thumbnail_remote_id_from_document( ImportPreparedDocument $document ) {
		foreach ( ImportWxrPostMeta::entries_from_document( $document ) as $entry ) {
			if ( '_thumbnail_id' !== $entry['key'] ) {
				continue;
			}

			$remote_id = (int) trim( (string) $entry['value'] );

			return $remote_id > 0 ? $remote_id : null;
		}

		return null;
	}

	/**
	 * Returns the root WXR source item key for a prepared WXR document.
	 *
	 * @param ImportPreparedDocument $document Prepared document.
	 * @return string
	 */
	public static function source_item_key_from_document( ImportPreparedDocument $document ) {
		$metadata = $document->get_metadata();

		if ( isset( $metadata['wxr_source_item_key'] ) && '' !== trim( (string) $metadata['wxr_source_item_key'] ) ) {
			return trim( (string) $metadata['wxr_source_item_key'] );
		}

		$parts = explode( ':wxr-post:', $document->get_source_item_key(), 2 );

		return $parts[0];
	}

	/**
	 * Returns the root WXR source item key for a queued/imported WXR attachment reference.
	 *
	 * @param ImportMediaReference $reference Media reference.
	 * @return string
	 */
	public static function source_item_key_from_reference( ImportMediaReference $reference ) {
		$source_item_key = $reference->get_source_item_key();
		$parts           = explode( ':wxr-attachment:', $source_item_key, 2 );

		return $parts[0];
	}

	/**
	 * Returns the prepared-document source key for a WXR attachment's parent post.
	 *
	 * @param ImportMediaReference $reference Media reference.
	 * @return string|null
	 */
	public static function parent_document_source_item_key( ImportMediaReference $reference ) {
		$metadata         = $reference->get_metadata();
		$remote_parent_id = isset( $metadata['wxr_post_parent'] ) ? (int) $metadata['wxr_post_parent'] : 0;

		if ( $remote_parent_id < 1 ) {
			return null;
		}

		return self::source_item_key_from_reference( $reference ) . ':wxr-post:' . (string) $remote_parent_id;
	}

	/**
	 * Extracts attachment post fields that should be restored on the local attachment.
	 *
	 * @param array<string,mixed> $data WXR attachment post data.
	 * @return array<string,mixed>
	 */
	public static function metadata_from_post( array $data ) {
		$metadata = array();
		$map      = array(
			'post_title'        => 'title',
			'post_excerpt'      => 'caption',
			'post_content'      => 'description',
			'post_name'         => 'post_name',
			'post_status'       => 'post_status',
			'post_date'         => 'post_date',
			'post_date_gmt'     => 'post_date_gmt',
			'post_modified'     => 'post_modified',
			'post_modified_gmt' => 'post_modified_gmt',
			'link'              => 'link',
			'guid'              => 'guid',
		);

		foreach ( $map as $source_key => $target_key ) {
			if ( isset( $data[ $source_key ] ) && '' !== trim( (string) $data[ $source_key ] ) ) {
				$metadata[ $target_key ] = trim( (string) $data[ $source_key ] );
			}
		}

		return $metadata;
	}

	/**
	 * Returns metadata with one WXR attachment postmeta entry merged in.
	 *
	 * @param array<string,mixed> $metadata Existing attachment metadata.
	 * @param string              $key      WXR meta key.
	 * @param string              $value    WXR meta value.
	 * @return array<string,mixed>
	 */
	public static function metadata_with_postmeta( array $metadata, $key, $value ) {
		$key   = trim( (string) $key );
		$value = (string) $value;

		if ( '' === $key ) {
			return $metadata;
		}

		if ( '_wp_attachment_image_alt' === $key ) {
			$alt = trim( self::strip_all_tags( $value ) );

			if ( '' !== $alt ) {
				$metadata['alt_text'] = $alt;
			}
		} elseif ( '_wp_attached_file' === $key && '' !== trim( $value ) ) {
			$metadata['source_attached_file'] = trim( $value );
		} elseif ( '_wp_attachment_metadata' === $key && '' !== trim( $value ) ) {
			$metadata['source_attachment_metadata'] = $value;
		}

		if ( ! isset( $metadata['source_postmeta'] ) || ! is_array( $metadata['source_postmeta'] ) ) {
			$metadata['source_postmeta'] = array();
		}

		$entry = array(
			'key'   => $key,
			'value' => $value,
		);

		foreach ( $metadata['source_postmeta'] as $index => $existing ) {
			if ( is_array( $existing ) && isset( $existing['key'] ) && (string) $existing['key'] === $key ) {
				$metadata['source_postmeta'][ $index ] = $entry;
				return $metadata;
			}
		}

		$metadata['source_postmeta'][] = $entry;

		return $metadata;
	}

	/**
	 * Returns staged attachment metadata from a media reference.
	 *
	 * @param ImportMediaReference $reference Media reference.
	 * @return array<string,mixed>
	 */
	public static function metadata_from_reference( ImportMediaReference $reference ) {
		$metadata = $reference->get_metadata();

		return isset( $metadata['wxr_attachment_metadata'] ) && is_array( $metadata['wxr_attachment_metadata'] )
			? $metadata['wxr_attachment_metadata']
			: array();
	}

	/**
	 * Strips tags in WordPress and unit-test runtimes.
	 *
	 * @param string $value Raw value.
	 * @return string
	 */
	private static function strip_all_tags( $value ) {
		$value = preg_replace( '#<script\b[^>]*>.*?</script>#is', '', (string) $value );
		$value = is_string( $value ) ? $value : '';

		if ( function_exists( 'wp_strip_all_tags' ) ) {
			return wp_strip_all_tags( $value );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags -- Fallback only for unit tests without WordPress loaded.
		return strip_tags( $value );
	}
}
