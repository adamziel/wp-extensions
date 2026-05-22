<?php
/**
 * Converts imported HTML fragments into WordPress block markup.
 *
 * @package UniversalImporter
 */

namespace UniversalImporter\Import;

/**
 * Maps obvious HTML structures to native blocks with classic fallback.
 */
final class ImportHtmlBlockConverter {
	/**
	 * Converts HTML into inferred block markup with classic fallback.
	 *
	 * @param string                   $content HTML content.
	 * @param array<string,mixed>|null $summary Optional conversion summary.
	 * @return string
	 */
	public function convert( $content, &$summary = null ) {
		$content = trim( $this->sanitize_executable_html_attributes( $this->strip_scripts( $content ) ) );
		$content = $this->normalize_classic_caption_shortcodes( $content );
		$summary = array(
			'html_block_conversion'     => 'classic',
			'html_inferred_block_count' => 0,
			'html_classic_block_count'  => 0,
		);

		if ( '' === $content ) {
			return '';
		}

		if ( ! class_exists( 'DOMDocument' ) ) {
			$summary['html_classic_block_count'] = 1;
			return $this->classic_block( $content );
		}

		$blocks = $this->fragment_to_blocks( $content, $summary );

		if ( empty( $blocks ) ) {
			$summary['html_classic_block_count'] = 1;
			return $this->classic_block( $content );
		}

		$summary['html_block_conversion'] = 0 < $summary['html_classic_block_count'] ? 'mixed' : 'structured';

		return implode( "\n\n", $blocks );
	}

	/**
	 * Extracts body contents from XHTML/HTML when possible.
	 *
	 * @param string $content Raw HTML.
	 * @return string
	 */
	public function extract_body( $content ) {
		if ( preg_match( '#<body\b[^>]*>(.*)</body>#is', (string) $content, $matches ) ) {
			return trim( $matches[1] );
		}

		return trim( (string) $content );
	}

	/**
	 * Removes script blocks from imported content.
	 *
	 * @param string $content Source content.
	 * @return string
	 */
	public function strip_scripts( $content ) {
		return preg_replace( '#<script\b[^>]*>.*?</script>#is', '', (string) $content );
	}

	/**
	 * Converts classic WordPress caption shortcodes into captioned figure HTML.
	 *
	 * @param string $content Sanitized HTML content.
	 * @return string
	 */
	private function normalize_classic_caption_shortcodes( $content ) {
		$normalized = preg_replace_callback(
			'#\[caption\b([^\]]*)\]\s*((?:<a\b[^>]*>\s*)?(?:<picture\b[^>]*>.*?</picture>|<img\b[^>]*>)(?:\s*</a>)?)\s*(.*?)\s*\[/caption\]#is',
			function ( $matches ) {
				$attributes = array( 'class' => 'wp-caption' );
				$id         = $this->classic_caption_shortcode_attribute( $matches[1], 'id' );
				$align      = $this->classic_caption_shortcode_attribute( $matches[1], 'align' );
				$visual     = trim( $matches[2] );
				$caption    = trim( $matches[3] );

				if ( '' !== $id && preg_match( '/^[A-Za-z][A-Za-z0-9_-]*$/', $id ) ) {
					$attributes['id'] = $id;
				}

				if ( in_array( $align, array( 'alignnone', 'alignleft', 'aligncenter', 'alignright' ), true ) ) {
					$attributes['class'] .= ' ' . $align;
				}

				return '<figure' . $this->html_attributes( $attributes ) . '>' . $visual
					. ( '' === $caption ? '' : '<figcaption>' . $caption . '</figcaption>' )
					. '</figure>';
			},
			(string) $content
		);

		return is_string( $normalized ) ? $normalized : (string) $content;
	}

	/**
	 * Extracts one attribute from a classic caption shortcode opening tag.
	 *
	 * @param string $attributes Raw shortcode attributes.
	 * @param string $name       Attribute name.
	 * @return string
	 */
	private function classic_caption_shortcode_attribute( $attributes, $name ) {
		if ( 1 !== preg_match( '/(?:^|\s)' . preg_quote( (string) $name, '/' ) . '\s*=\s*("[^"]*"|\'[^\']*\'|[^\s\]]+)/i', (string) $attributes, $matches ) ) {
			return '';
		}

		return trim( $matches[1], "\"' \t\n\r\0\x0B" );
	}

	/**
	 * Removes executable event handlers and script URLs from imported markup.
	 *
	 * @param string $content HTML content after script-tag stripping.
	 * @return string
	 */
	public function sanitize_executable_html_attributes( $content ) {
		$content = (string) $content;

		if ( '' === trim( $content ) ) {
			return '';
		}

		if ( ! class_exists( 'DOMDocument' ) ) {
			return $this->sanitize_executable_html_attributes_with_regex( $content );
		}

		$document = new \DOMDocument();
		$previous = libxml_use_internal_errors( true );
		$loaded   = $document->loadHTML(
			'<!DOCTYPE html><html><body><div id="universal-importer-sanitizer-root">' . $this->encode_html_for_libxml( $content ) . '</div></body></html>',
			LIBXML_NONET
		);
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );

		if ( ! $loaded ) {
			return $this->sanitize_executable_html_attributes_with_regex( $content );
		}

		$root = $document->getElementById( 'universal-importer-sanitizer-root' );

		if ( null === $root ) {
			return $this->sanitize_executable_html_attributes_with_regex( $content );
		}

		$this->sanitize_dom_node( $root );

		return trim( $this->inner_html( $root ) );
	}

	/**
	 * Encodes non-ASCII bytes as numeric HTML entities for DOMDocument::loadHTML.
	 *
	 * PHP's libxml-backed loadHTML() assumes the input is ISO-8859-1 unless a
	 * <meta charset> tag is present near the top of the document. Imported
	 * content is UTF-8 (e.g. curly quotes, em-dashes, accented letters) but
	 * the source pages can also embed their own <meta charset> declarations
	 * which would conflict with one we inject. Converting non-ASCII codepoints
	 * to &#NNNN; entities makes loadHTML treat the payload as pure ASCII and
	 * yields byte-for-byte UTF-8 round-tripping without relying on libxml
	 * charset guessing.
	 *
	 * @param string $content UTF-8 HTML fragment.
	 * @return string ASCII-only HTML where non-ASCII bytes are numeric entities.
	 */
	private function encode_html_for_libxml( $content ) {
		$content = (string) $content;

		if ( '' === $content ) {
			return '';
		}

		if ( function_exists( 'mb_encode_numericentity' ) ) {
			$encoded = mb_encode_numericentity(
				$content,
				array( 0x80, 0x10FFFF, 0, 0x1FFFFF ),
				'UTF-8'
			);

			if ( is_string( $encoded ) ) {
				return $encoded;
			}
		}

		return $content;
	}

	/**
	 * Sanitizes executable attributes for the rare no-DOM fallback path.
	 *
	 * @param string $content HTML content.
	 * @return string
	 */
	private function sanitize_executable_html_attributes_with_regex( $content ) {
		$content = preg_replace( '/\s+on[a-z0-9_-]+\s*=\s*(?:"[^"]*"|\'[^\']*\'|[^\s>]+)/i', '', (string) $content );
		$content = preg_replace( '/\s+srcdoc\s*=\s*(?:"[^"]*"|\'[^\']*\'|[^\s>]+)/i', '', is_string( $content ) ? $content : '' );
		$content = preg_replace_callback(
			'#<style\b[^>]*>(.*?)</style>#is',
			function ( $matches ) {
				return $this->is_executable_style( $matches[1] ) ? '' : $matches[0];
			},
			is_string( $content ) ? $content : ''
		);

		$content = preg_replace_callback(
			'/\s+(href|src|dynsrc|lowsrc|poster|data|background|longdesc|cite|codebase|classid|archive|action|formaction|xlink:href)\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)/i',
			function ( $matches ) {
				$value = trim( $matches[2], "\"' \t\n\r\0\x0B" );

				if ( $this->is_scriptable_url( $value ) ) {
					return '';
				}

				return $matches[0];
			},
			is_string( $content ) ? $content : ''
		);

		$content = preg_replace_callback(
			'/\s+ping\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)/i',
			function ( $matches ) {
				$value = trim( $matches[1], "\"' \t\n\r\0\x0B" );

				return $this->contains_scriptable_url_token( $value ) ? '' : $matches[0];
			},
			is_string( $content ) ? $content : ''
		);

		$content = preg_replace_callback(
			'/\s+srcset\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)/i',
			function ( $matches ) {
				$value = trim( $matches[1], "\"' \t\n\r\0\x0B" );

				return $this->is_scriptable_srcset( $value ) ? '' : $matches[0];
			},
			is_string( $content ) ? $content : ''
		);

		$content = preg_replace_callback(
			'/\s+content\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)/i',
			function ( $matches ) {
				$value = trim( $matches[1], "\"' \t\n\r\0\x0B" );

				return $this->is_scriptable_refresh_content( $value ) ? '' : $matches[0];
			},
			is_string( $content ) ? $content : ''
		);

		$content = preg_replace_callback(
			'/\s+style\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)/i',
			function ( $matches ) {
				$value = trim( $matches[1], "\"' \t\n\r\0\x0B" );

				return $this->is_executable_style( $value ) ? '' : $matches[0];
			},
			is_string( $content ) ? $content : ''
		);

		return is_string( $content ) ? $content : '';
	}

	/**
	 * Removes executable attributes from a DOM subtree.
	 *
	 * @param \DOMNode $node DOM node.
	 * @return void
	 */
	private function sanitize_dom_node( \DOMNode $node ) {
		if ( $node instanceof \DOMElement ) {
			if ( 'style' === strtolower( $node->nodeName ) && $this->is_executable_style( (string) $node->textContent ) ) {
				if ( null !== $node->parentNode ) {
					$node->parentNode->removeChild( $node );
				}

				return;
			}

			$remove = array();

			foreach ( $node->attributes as $attribute ) {
				$name  = strtolower( $attribute->nodeName );
				$value = (string) $attribute->nodeValue;

				if (
					0 === strpos( $name, 'on' )
					|| 'srcdoc' === $name
					|| ( in_array( $name, array( 'href', 'src', 'dynsrc', 'lowsrc', 'poster', 'data', 'background', 'longdesc', 'cite', 'codebase', 'classid', 'archive', 'action', 'formaction', 'xlink:href' ), true ) && $this->is_scriptable_url( $value ) )
					|| ( 'ping' === $name && $this->contains_scriptable_url_token( $value ) )
					|| ( 'srcset' === $name && $this->is_scriptable_srcset( $value ) )
					|| ( 'content' === $name && $this->is_scriptable_refresh_content( $value ) )
					|| ( 'style' === $name && $this->is_executable_style( $value ) )
				) {
					$remove[] = $attribute->nodeName;
				}
			}

			foreach ( $remove as $attribute_name ) {
				$node->removeAttribute( $attribute_name );
			}
		}

		foreach ( iterator_to_array( $node->childNodes ) as $child ) {
			$this->sanitize_dom_node( $child );
		}
	}

	/**
	 * Returns whether a URL can execute script if preserved in markup.
	 *
	 * @param string $url URL or attribute value.
	 * @return bool
	 */
	private function is_scriptable_url( $url ) {
		$normalized = html_entity_decode( (string) $url, ENT_QUOTES, 'UTF-8' );
		$normalized = preg_replace( '/[\x00-\x20]+/', '', $normalized );
		$normalized = strtolower( is_string( $normalized ) ? $normalized : '' );

		return 1 === preg_match( '/^(?:javascript|vbscript):/', $normalized )
			|| 1 === preg_match( '#^data:(?:text/html|image/svg\+xml)\b#', $normalized );
	}

	/**
	 * Returns whether a whitespace-separated URL list contains a scriptable URL.
	 *
	 * @param string $value Attribute value.
	 * @return bool
	 */
	private function contains_scriptable_url_token( $value ) {
		$tokens = preg_split( '/\s+/', trim( html_entity_decode( (string) $value, ENT_QUOTES, 'UTF-8' ) ) );

		if ( ! is_array( $tokens ) ) {
			return false;
		}

		foreach ( $tokens as $token ) {
			if ( '' !== $token && $this->is_scriptable_url( $token ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Returns whether a srcset contains a candidate that can execute script.
	 *
	 * @param string $srcset Source set attribute value.
	 * @return bool
	 */
	private function is_scriptable_srcset( $srcset ) {
		$normalized = html_entity_decode( (string) $srcset, ENT_QUOTES, 'UTF-8' );
		$normalized = preg_replace( '/[\x00-\x20]+/', '', $normalized );
		$normalized = strtolower( is_string( $normalized ) ? $normalized : '' );

		return 1 === preg_match( '/(?:^|,)(?:javascript|vbscript):/', $normalized )
			|| 1 === preg_match( '#(?:^|,)data:(?:text/html|image/svg\+xml)\b#', $normalized );
	}

	/**
	 * Returns whether a refresh-style content attribute targets a scriptable URL.
	 *
	 * @param string $content Refresh content attribute value.
	 * @return bool
	 */
	private function is_scriptable_refresh_content( $content ) {
		$decoded = html_entity_decode( (string) $content, ENT_QUOTES, 'UTF-8' );

		if ( 1 !== preg_match( '/(?:^|;)\s*url\s*=\s*(.+)$/i', $decoded, $matches ) ) {
			return false;
		}

		$url = trim( $matches[1], "\"' \t\n\r\0\x0B" );

		return $this->is_scriptable_url( $url );
	}

	/**
	 * Returns whether an inline style can execute script in legacy browsers.
	 *
	 * @param string $style Style attribute value.
	 * @return bool
	 */
	private function is_executable_style( $style ) {
		$normalized = html_entity_decode( (string) $style, ENT_QUOTES, 'UTF-8' );
		$compact    = $this->compact_css_for_protocol_detection( $normalized );

		return 1 === preg_match( '/expression\s*\(/i', $normalized )
			|| 1 === preg_match( '/url\s*\(\s*[\'"]?\s*(?:javascript|vbscript|data:(?:text\/html|image\/svg\+xml))/i', $normalized )
			|| 1 === preg_match( '/@import\s+(?:url\s*\(\s*)?[\'"]?\s*(?:javascript|vbscript|data:(?:text\/html|image\/svg\+xml))/i', $normalized )
			|| 1 === preg_match( '/url\([\'"]?(?:javascript|vbscript|data:(?:text\/html|image\/svg\+xml))/', $compact )
			|| 1 === preg_match( '/@import(?:url\()?[\'"]?(?:javascript|vbscript|data:(?:text\/html|image\/svg\+xml))/', $compact );
	}

	/**
	 * Compacts CSS for scriptable protocol detection, including simple escapes.
	 *
	 * @param string $style CSS text.
	 * @return string
	 */
	private function compact_css_for_protocol_detection( $style ) {
		$decoded = preg_replace_callback(
			'/\\\\([0-9a-fA-F]{1,6}\s?|.)/s',
			function ( $matches ) {
				$escape = $matches[1];

				if ( 1 === preg_match( '/^[0-9a-fA-F]{1,6}\s?$/', $escape ) ) {
					$code = hexdec( trim( $escape ) );

					return 0 < $code && 128 > $code ? chr( $code ) : '';
				}

				return $escape;
			},
			(string) $style
		);

		$compact = preg_replace( '/[\x00-\x20]+/', '', is_string( $decoded ) ? $decoded : (string) $style );

		return strtolower( is_string( $compact ) ? $compact : '' );
	}

	/**
	 * Builds a classic block for HTML that cannot be safely inferred.
	 *
	 * @param string $content HTML content.
	 * @return string
	 */
	public function classic_block( $content ) {
		return "<!-- wp:freeform -->\n" . trim( (string) $content ) . "\n<!-- /wp:freeform -->";
	}

	/**
	 * Converts one HTML fragment into top-level WordPress blocks.
	 *
	 * @param string              $content HTML content.
	 * @param array<string,mixed> $summary Mutable conversion summary.
	 * @return array<int,string>
	 */
	private function fragment_to_blocks( $content, array &$summary ) {
		$document = new \DOMDocument();
		$previous = libxml_use_internal_errors( true );
		$loaded   = $document->loadHTML(
			'<!DOCTYPE html><html><body><div id="universal-importer-html-root">' . $this->encode_html_for_libxml( (string) $content ) . '</div></body></html>',
			LIBXML_NONET
		);
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );

		if ( ! $loaded ) {
			return array();
		}

		$root = $document->getElementById( 'universal-importer-html-root' );

		if ( null === $root ) {
			return array();
		}

		$blocks = $this->node_list_to_blocks( $root->childNodes );

		if ( empty( $blocks ) && $this->node_list_contains_only_ignored_nodes( $root->childNodes ) ) {
			return array( '' );
		}

		foreach ( $blocks as $block ) {
			if ( false !== strpos( $block, '<!-- wp:freeform -->' ) ) {
				++$summary['html_classic_block_count'];
			} else {
				++$summary['html_inferred_block_count'];
			}
		}

		return $blocks;
	}

	/**
	 * Converts a DOM node into block markup when the structure is obvious.
	 *
	 * @param \DOMNode $node DOM node.
	 * @return array<int,string>
	 */
	private function node_to_blocks( \DOMNode $node ) {
		if ( XML_COMMENT_NODE === $node->nodeType ) {
			$comment_block = $this->legacy_content_comment_to_block( $node );

			return null === $comment_block ? array() : array( $comment_block );
		}

		if ( XML_TEXT_NODE === $node->nodeType ) {
			$text = trim( (string) $node->textContent );

			if ( '' === $text ) {
				return array();
			}

			$shortcode = $this->shortcode_text_to_block( $text );
			if ( null !== $shortcode ) {
				return array( $shortcode );
			}

			if ( null !== $this->embed_provider_for_url( $text ) ) {
				return array( $this->url_to_embed_block( $text ) );
			}

			return array(
				'<!-- wp:paragraph -->' . "\n"
				. '<p>' . $this->escape_html( $text ) . '</p>' . "\n"
				. '<!-- /wp:paragraph -->',
			);
		}

		if ( XML_ELEMENT_NODE !== $node->nodeType ) {
			return array();
		}

		$name       = strtolower( $node->nodeName );
		$inner_html = trim( $this->inner_html( $node ) );

		if ( $node instanceof \DOMElement && $this->is_document_metadata_element( $node ) ) {
			return array();
		}

		if ( in_array( $name, array( 'noembed', 'noframes', 'noscript' ), true ) ) {
			$child_blocks = $this->child_nodes_to_blocks( $node );

			if ( ! empty( $child_blocks ) && ! $this->contains_classic_block( $child_blocks ) ) {
				return $child_blocks;
			}
		}

		if ( 'applet' === $name && $node instanceof \DOMElement ) {
			$fallback_blocks = $this->applet_fallback_blocks( $node );

			if ( null !== $fallback_blocks ) {
				return $fallback_blocks;
			}

			return array( $this->classic_block( trim( $this->outer_html( $node ) ) ) );
		}

		if ( 'object' === $name && $node instanceof \DOMElement ) {
			$fallback_blocks = $this->object_fallback_blocks( $node );

			if ( null !== $fallback_blocks ) {
				return $fallback_blocks;
			}

			return array( $this->classic_block( trim( $this->outer_html( $node ) ) ) );
		}

		if ( 'legend' === $name && $node instanceof \DOMElement ) {
			return array( $this->legend_to_paragraph_block( $node ) );
		}

		if ( 'fieldset' === $name && $node instanceof \DOMElement ) {
			$field_blocks = $this->fieldset_content_blocks( $node );

			if ( null !== $field_blocks ) {
				return $field_blocks;
			}

			return array( $this->classic_block( trim( $this->outer_html( $node ) ) ) );
		}

		if ( 'dialog' === $name && $node instanceof \DOMElement ) {
			$dialog_blocks = $this->dialog_content_blocks( $node );

			if ( null !== $dialog_blocks ) {
				return $dialog_blocks;
			}

			return array( $this->classic_block( trim( $this->outer_html( $node ) ) ) );
		}

		if ( in_array( $name, array( 'hgroup', 'marquee' ), true ) ) {
			$child_blocks = $this->child_nodes_to_blocks( $node );

			if ( ! empty( $child_blocks ) && ! $this->contains_classic_block( $child_blocks ) ) {
				return $child_blocks;
			}

			return array( $this->classic_block( trim( $this->outer_html( $node ) ) ) );
		}

		if ( 'center' === $name && $node instanceof \DOMElement ) {
			return $this->center_to_blocks( $node );
		}

		if ( 'font' === $name && $node instanceof \DOMElement ) {
			return array( $this->font_to_paragraph_block( $node ) );
		}

		if ( 'address' === $name && $node instanceof \DOMElement ) {
			return $this->address_to_blocks( $node );
		}

		if ( $node instanceof \DOMElement && $this->is_static_obsolete_inline_element( $node ) ) {
			return array( $this->obsolete_inline_to_paragraph_block( $node ) );
		}

		if ( preg_match( '/^h([1-6])$/', $name, $matches ) ) {
			$level      = (int) $matches[1];
			$anchor     = $this->element_attribute( $node, 'id' );
			$text_align = $node instanceof \DOMElement ? $this->text_alignment( $node ) : '';
			$attributes = array( 'level' => $level );
			$html_attrs = array();

			if ( '' !== $text_align ) {
				$attributes['textAlign'] = $text_align;
				$html_attrs['class']     = 'has-text-align-' . $text_align;
			}

			if ( '' !== $anchor ) {
				$attributes['anchor'] = $anchor;
				$html_attrs['id']     = $anchor;
			}

			return array(
				'<!-- wp:heading ' . $this->encode_block_attributes( $attributes ) . ' -->' . "\n"
				. '<h' . $level . $this->html_attributes( $html_attrs ) . '>' . $inner_html . '</h' . $level . '>' . "\n"
				. '<!-- /wp:heading -->',
			);
		}

		if ( 'a' === $name && $node instanceof \DOMElement && $this->is_button_link_candidate( $node ) ) {
			return array( $this->link_to_button_block( $node ) );
		}

		if ( 'a' === $name && $node instanceof \DOMElement && $this->is_file_link_candidate( $node ) ) {
			return array( $this->link_to_file_block( $node ) );
		}

		if ( 'a' === $name && $node instanceof \DOMElement ) {
			$embed = $this->standalone_embed_link_to_block( $node );

			if ( null !== $embed ) {
				return array( $embed );
			}

			$linked_image = $this->single_direct_child_element_by_tag( $node, 'img' );

			if ( null !== $linked_image ) {
				return array( $this->image_to_block( $linked_image, null, $node ) );
			}

			$linked_picture = $this->single_direct_child_element_by_tag( $node, 'picture' );

			if ( null !== $linked_picture ) {
				$block = $this->linked_picture_to_image_block( $linked_picture, $node );

				if ( null !== $block ) {
					return array( $block );
				}
			}
		}

		if ( 'p' === $name ) {
			$shortcode = $this->shortcode_text_to_block( (string) $node->textContent, $node instanceof \DOMElement ? $node : null );
			if ( null !== $shortcode ) {
				return array( $shortcode );
			}

			$embed = $node instanceof \DOMElement ? $this->paragraph_to_embed_block( $node ) : null;

			if ( null !== $embed ) {
				return array( $embed );
			}

			$link = $this->single_direct_child_element_by_tag( $node, 'a' );

			if ( null !== $link && $this->is_button_link_candidate( $link ) ) {
				return array( $this->link_to_button_block( $link, $node ) );
			}

			if ( null !== $link && $this->is_file_link_candidate( $link ) ) {
				return array( $this->link_to_file_block( $link, $node ) );
			}

			$inline_blocks = $this->paragraph_inline_children_to_blocks( $node );

			if ( null !== $inline_blocks ) {
				return $inline_blocks;
			}

			return array( $this->paragraph_to_block( $node, $inner_html ) );
		}

		if ( $this->is_spacer_candidate( $node ) ) {
			return array( $this->spacer_to_block( $node ) );
		}

		if ( $node instanceof \DOMElement && $this->is_captioned_image_wrapper_candidate( $node ) ) {
			return array( $this->captioned_image_wrapper_to_block( $node ) );
		}

		if ( $node instanceof \DOMElement && $this->is_legacy_media_wrapper_candidate( $node ) ) {
			return array( $this->legacy_media_wrapper_to_block( $node ) );
		}

		if ( $node instanceof \DOMElement && $this->has_legacy_media_wrapper_signal( $node ) ) {
			return array( $this->classic_block( trim( $this->outer_html( $node ) ) ) );
		}

		if ( $node instanceof \DOMElement && $this->is_legacy_media_widget_wrapper_candidate( $node ) ) {
			return array( $this->classic_block( trim( $this->outer_html( $node ) ) ) );
		}

		if ( $node instanceof \DOMElement && $this->is_legacy_widget_wrapper_candidate( $node ) ) {
			return array( $this->classic_block( trim( $this->outer_html( $node ) ) ) );
		}

		if ( $this->is_media_text_wrapper_candidate( $node ) ) {
			return array( $this->media_text_wrapper_to_block( $node ) );
		}

		if ( $this->is_social_links_wrapper_candidate( $node ) ) {
			return array( $this->social_links_wrapper_to_block( $node ) );
		}

		if (
			$node instanceof \DOMElement
			&& $this->has_navigation_wrapper_signal( $node )
			&& ! $this->is_tabbed_interface_candidate( $node )
		) {
			return array( $this->navigation_wrapper_to_block( $node ) );
		}

		if ( $this->is_cover_wrapper_candidate( $node ) ) {
			return array( $this->cover_wrapper_to_block( $node ) );
		}

		if ( $this->is_timeline_wrapper_candidate( $node ) ) {
			return array( $this->timeline_wrapper_to_block( $node ) );
		}

		if ( in_array( $name, array( 'dir', 'menu', 'ol', 'ul' ), true ) ) {
			return array( $this->list_to_block( $node ) );
		}

		if ( $node instanceof \DOMElement && $this->is_pullquote_candidate( $node ) ) {
			return array( $this->pullquote_to_block( $node ) );
		}

		if ( $node instanceof \DOMElement && $this->is_figure_quote_candidate( $node ) ) {
			return array( $this->figure_quote_to_block( $node ) );
		}

		if ( 'blockquote' === $name ) {
			return array( $this->quote_to_block( $node ) );
		}

		if ( $node instanceof \DOMElement && in_array( $name, array( 'listing', 'plaintext', 'xmp' ), true ) ) {
			return array( $this->obsolete_preformatted_to_block( $node ) );
		}

		if ( 'pre' === $name ) {
			if ( $node instanceof \DOMElement && $this->is_verse_candidate( $node ) ) {
				return array( $this->verse_to_block( $node ) );
			}

			$code = $this->single_direct_child_element_by_tag( $node, 'code' );

			if ( null !== $code ) {
				return array( $this->code_to_block( $code, $node ) );
			}

			if ( '' !== $this->code_language_class( $node ) ) {
				return array( $this->code_to_block( $node ) );
			}

			return array( $this->preformatted_to_block( $node, $inner_html ) );
		}

		if ( 'code' === $name ) {
			return array( $this->code_to_block( $node ) );
		}

		if ( 'table' === $name ) {
			return array( $this->table_to_block( $node ) );
		}

		if ( 'hr' === $name ) {
			return array( $this->separator_to_block( $node ) );
		}

		if ( 'img' === $name ) {
			return array( $this->image_to_block( $node ) );
		}

		if ( 'picture' === $name && $node instanceof \DOMElement ) {
			return array( $this->picture_to_image_block( $node ) );
		}

		if ( $this->is_legacy_accordion_candidate( $node ) ) {
			return $this->legacy_accordion_to_blocks( $node );
		}

		if ( $this->is_tabbed_interface_candidate( $node ) ) {
			return array( $this->custom_html_block( trim( $this->outer_html( $node ) ) ) );
		}

		if ( $this->is_gallery_candidate( $node ) ) {
			return array( $this->gallery_to_block( $node ) );
		}

		if ( $this->is_columns_candidate( $node ) ) {
			return array( $this->columns_to_block( $node ) );
		}

		if ( $this->is_group_wrapper_candidate( $node ) ) {
			return array( $this->group_wrapper_to_block( $node ) );
		}

		if ( 'video' === $name ) {
			return array( $this->media_element_to_block( $node, 'video' ) );
		}

		if ( 'audio' === $name ) {
			return array( $this->media_element_to_block( $node, 'audio' ) );
		}

		if ( 'iframe' === $name ) {
			return array( $this->iframe_to_block( $node ) );
		}

		if ( 'form' === $name && $node instanceof \DOMElement && $this->is_search_form_candidate( $node ) ) {
			return array( $this->search_form_to_block( $node ) );
		}

		if ( 'form' === $name ) {
			return array( $this->custom_html_block( trim( $this->outer_html( $node ) ) ) );
		}

		if ( 'details' === $name && $node instanceof \DOMElement ) {
			return array( $this->details_to_block( $node ) );
		}

		if ( 'dl' === $name && $node instanceof \DOMElement && $this->is_faq_definition_list_candidate( $node ) ) {
			return $this->definition_list_to_details_blocks( $node );
		}

		if ( 'figure' === $name && $this->node_contains_tag( $node, 'video' ) ) {
			return array( $this->figure_media_to_block( $node, 'video' ) );
		}

		if ( 'figure' === $name && $this->node_contains_tag( $node, 'audio' ) ) {
			return array( $this->figure_media_to_block( $node, 'audio' ) );
		}

		if ( 'figure' === $name && $this->node_contains_tag( $node, 'iframe' ) ) {
			return array( $this->figure_iframe_to_block( $node ) );
		}

		if ( 'figure' === $name && $this->node_contains_tag( $node, 'img' ) ) {
			return array( $this->figure_image_to_block( $node ) );
		}

		if ( 'figure' === $name && $this->node_contains_tag( $node, 'table' ) ) {
			return array( $this->figure_table_to_block( $node ) );
		}

		if ( $this->is_semantic_wrapper( $name ) ) {
			$child_blocks = $this->child_nodes_to_blocks( $node );

			if ( ! empty( $child_blocks ) && ! $this->contains_classic_block( $child_blocks ) ) {
				return $child_blocks;
			}
		}

		if ( $node instanceof \DOMElement && $this->is_standalone_inline_paragraph_element( $node ) ) {
			return array( $this->standalone_inline_to_paragraph_block( $node ) );
		}

		return array( $this->classic_block( trim( $this->outer_html( $node ) ) ) );
	}

	/**
	 * Returns whether an imported element is document metadata, not body content.
	 *
	 * @param \DOMElement $node Element node.
	 * @return bool
	 */
	private function is_document_metadata_element( \DOMElement $node ) {
		return in_array( strtolower( $node->nodeName ), array( 'base', 'link', 'meta', 'style', 'title' ), true );
	}

	/**
	 * Returns whether a node list contains only ignored metadata or whitespace.
	 *
	 * @param \DOMNodeList $nodes DOM nodes.
	 * @return bool
	 */
	private function node_list_contains_only_ignored_nodes( \DOMNodeList $nodes ) {
		foreach ( $nodes as $node ) {
			if ( XML_TEXT_NODE === $node->nodeType || XML_CDATA_SECTION_NODE === $node->nodeType ) {
				if ( '' !== trim( (string) $node->textContent ) ) {
					return false;
				}

				continue;
			}

			if ( XML_COMMENT_NODE === $node->nodeType ) {
				continue;
			}

			if ( ! $node instanceof \DOMElement || ! $this->is_document_metadata_element( $node ) ) {
				return false;
			}
		}

		return 0 < $nodes->length;
	}

	/**
	 * Converts legacy center wrappers when their children are otherwise structured.
	 *
	 * @param \DOMElement $node Center element.
	 * @return array<int,string>
	 */
	private function center_to_blocks( \DOMElement $node ) {
		foreach ( $node->childNodes as $child ) {
			if ( $child instanceof \DOMElement && preg_match( '/^(?:p|h[1-6])$/', strtolower( $child->nodeName ) ) && '' === $this->text_alignment( $child ) ) {
				$child->setAttribute( 'align', 'center' );
			}
		}

		$child_blocks = $this->child_nodes_to_blocks( $node );

		if ( ! empty( $child_blocks ) && ! $this->contains_classic_block( $child_blocks ) ) {
			return $child_blocks;
		}

		return array( $this->classic_block( trim( $this->outer_html( $node ) ) ) );
	}

	/**
	 * Converts legacy font wrappers when they contain only paragraph-safe inline content.
	 *
	 * @param \DOMElement $node Font element.
	 * @return string
	 */
	private function font_to_paragraph_block( \DOMElement $node ) {
		$inner_html = trim( $this->inner_html( $node ) );

		if ( '' !== $inner_html && $this->node_has_only_inline_children( $node ) && $this->has_visible_inline_content( $inner_html ) ) {
			return $this->paragraph_to_block( $node, $inner_html );
		}

		return $this->classic_block( trim( $this->outer_html( $node ) ) );
	}

	/**
	 * Converts address wrappers when the contact content is structurally inferable.
	 *
	 * @param \DOMElement $node Address element.
	 * @return array<int,string>
	 */
	private function address_to_blocks( \DOMElement $node ) {
		$inner_html = trim( $this->inner_html( $node ) );

		if ( '' !== $inner_html && $this->node_has_only_inline_children( $node ) && $this->has_visible_inline_content( $inner_html ) ) {
			return array( $this->paragraph_to_block( $node, $inner_html ) );
		}

		if ( $this->address_has_only_paragraph_children( $node ) ) {
			$child_blocks = $this->child_nodes_to_blocks( $node );

			if ( ! empty( $child_blocks ) && ! $this->contains_classic_block( $child_blocks ) ) {
				return $child_blocks;
			}
		}

		return array( $this->classic_block( trim( $this->outer_html( $node ) ) ) );
	}

	/**
	 * Returns whether an address wrapper contains only paragraph child blocks.
	 *
	 * @param \DOMElement $node Address element.
	 * @return bool
	 */
	private function address_has_only_paragraph_children( \DOMElement $node ) {
		$has_paragraph = false;

		foreach ( $node->childNodes as $child ) {
			if ( XML_TEXT_NODE === $child->nodeType && '' === trim( (string) $child->textContent ) ) {
				continue;
			}

			if ( XML_COMMENT_NODE === $child->nodeType ) {
				continue;
			}

			if ( ! $child instanceof \DOMElement || 'p' !== strtolower( $child->nodeName ) ) {
				return false;
			}

			$has_paragraph = true;
		}

		return $has_paragraph;
	}

	/**
	 * Returns whether an obsolete inline wrapper can safely stay in a paragraph.
	 *
	 * @param \DOMElement $node Element.
	 * @return bool
	 */
	private function is_static_obsolete_inline_element( \DOMElement $node ) {
		return in_array( strtolower( $node->nodeName ), array( 'big', 'strike', 'tt' ), true );
	}

	/**
	 * Converts a standalone obsolete inline wrapper into a Paragraph block.
	 *
	 * @param \DOMElement $node Element.
	 * @return string
	 */
	private function obsolete_inline_to_paragraph_block( \DOMElement $node ) {
		$html = trim( $this->outer_html( $node ) );

		if ( '' !== $html && $this->node_has_only_inline_children( $node ) && $this->has_visible_inline_content( $html ) ) {
			return $this->block_open_comment( 'paragraph', array() ) . "\n"
				. '<p>' . $html . '</p>' . "\n"
				. '<!-- /wp:paragraph -->';
		}

		return $this->classic_block( $html );
	}

	/**
	 * Returns whether a standalone inline wrapper can safely become a Paragraph block.
	 *
	 * @param \DOMElement $node Element.
	 * @return bool
	 */
	private function is_standalone_inline_paragraph_element( \DOMElement $node ) {
		return in_array(
			strtolower( $node->nodeName ),
			array(
				'abbr',
				'acronym',
				'b',
				'bdi',
				'bdo',
				'canvas',
				'cite',
				'data',
				'del',
				'dfn',
				'em',
				'i',
				'ins',
				'kbd',
				'label',
				'mark',
				'meter',
				'output',
				'progress',
				'q',
				'ruby',
				's',
				'samp',
				'small',
				'strong',
				'sub',
				'sup',
				'time',
				'u',
				'var',
			),
			true
		);
	}

	/**
	 * Converts a standalone inline wrapper into a Paragraph block.
	 *
	 * @param \DOMElement $node Element.
	 * @return string
	 */
	private function standalone_inline_to_paragraph_block( \DOMElement $node ) {
		$html = trim( $this->outer_html( $node ) );

		if ( '' !== $html && $this->node_has_only_inline_children( $node ) && $this->has_visible_inline_content( $html ) ) {
			return $this->block_open_comment( 'paragraph', array() ) . "\n"
				. '<p>' . $html . '</p>' . "\n"
				. '<!-- /wp:paragraph -->';
		}

		return $this->classic_block( $html );
	}

	/**
	 * Converts an orphan fieldset legend into a Paragraph block.
	 *
	 * @param \DOMElement $node Legend element.
	 * @return string
	 */
	private function legend_to_paragraph_block( \DOMElement $node ) {
		$html = trim( $this->inner_html( $node ) );

		if ( '' === $html || ! $this->node_has_only_inline_children( $node ) || ! $this->has_visible_inline_content( $html ) ) {
			return $this->classic_block( trim( $this->outer_html( $node ) ) );
		}

		$anchor     = $this->element_attribute( $node, 'id' );
		$attributes = array();
		$html_attrs = array();
		$text_align = $this->text_alignment( $node );

		if ( '' !== $text_align ) {
			$attributes['align'] = $text_align;
			$html_attrs['class'] = 'has-text-align-' . $text_align;
		}

		if ( '' !== $anchor ) {
			$attributes['anchor'] = $anchor;
			$html_attrs['id']     = $anchor;
		}

		return $this->block_open_comment( 'paragraph', $attributes ) . "\n"
			. '<p' . $this->html_attributes( $html_attrs ) . '>' . $html . '</p>' . "\n"
			. '<!-- /wp:paragraph -->';
	}

	/**
	 * Converts a DOM node list into blocks while preserving legacy content comments.
	 *
	 * @param \DOMNodeList $nodes DOM nodes.
	 * @return array<int,string>
	 */
	private function node_list_to_blocks( \DOMNodeList $nodes ) {
		$blocks     = array();
		$node_array = iterator_to_array( $nodes );
		$count      = count( $node_array );

		for ( $i = 0; $i < $count; ++$i ) {
			$node = $node_array[ $i ];
			$next = $i + 1 < $count ? $node_array[ $i + 1 ] : null;

			$more_block = $this->legacy_more_comment_to_block( $node, $next );

			if ( null !== $more_block ) {
				$blocks[] = $more_block;

				if ( null !== $next && $this->is_legacy_noteaser_comment( $next ) ) {
					++$i;
				}

				continue;
			}

			if ( $this->is_legacy_noteaser_comment( $node ) ) {
				continue;
			}

			if ( $node instanceof \DOMElement && $this->is_gallery_candidate( $node ) ) {
				$caption = null;
				$last    = $i;

				for ( $j = $i + 1; $j < $count; ++$j ) {
					$candidate = $node_array[ $j ];

					if ( XML_TEXT_NODE === $candidate->nodeType && '' === trim( (string) $candidate->textContent ) ) {
						$last = $j;
						continue;
					}

					if ( $candidate instanceof \DOMElement && 'figcaption' === strtolower( $candidate->nodeName ) ) {
						$caption = $candidate;
						$last    = $j;
					}

					break;
				}

				if ( null !== $caption ) {
					$gallery_block = $this->orphan_figcaption_to_gallery_block( $node, $caption );

					if ( null !== $gallery_block ) {
						$blocks[] = $gallery_block;
						$i        = $last;
						continue;
					}
				}
			}

			if ( $node instanceof \DOMElement && 'blockquote' === strtolower( $node->nodeName ) ) {
				$citation = null;
				$last     = $i;

				for ( $j = $i + 1; $j < $count; ++$j ) {
					$candidate = $node_array[ $j ];

					if ( XML_TEXT_NODE === $candidate->nodeType && '' === trim( (string) $candidate->textContent ) ) {
						$last = $j;
						continue;
					}

					if ( $candidate instanceof \DOMElement && in_array( strtolower( $candidate->nodeName ), array( 'cite', 'figcaption' ), true ) ) {
						$citation = $candidate;
						$last     = $j;
					}

					break;
				}

				if ( null !== $citation ) {
					$quote_block = $this->orphan_citation_to_pullquote_block( $node, $citation );

					if ( null === $quote_block ) {
						$quote_block = $this->orphan_citation_to_quote_block( $node, $citation );
					}

					if ( null !== $quote_block ) {
						$blocks[] = $quote_block;
						$i        = $last;
						continue;
					}
				}
			}

			if ( $node instanceof \DOMElement && in_array( strtolower( $node->nodeName ), array( 'a', 'img', 'picture' ), true ) ) {
				$caption = null;
				$last    = $i;

				for ( $j = $i + 1; $j < $count; ++$j ) {
					$candidate = $node_array[ $j ];

					if ( XML_TEXT_NODE === $candidate->nodeType && '' === trim( (string) $candidate->textContent ) ) {
						$last = $j;
						continue;
					}

					if ( $candidate instanceof \DOMElement && 'figcaption' === strtolower( $candidate->nodeName ) ) {
						$caption = $candidate;
						$last    = $j;
					}

					break;
				}

				if ( null !== $caption ) {
					$image_block = $this->orphan_figcaption_to_image_block( $node, $caption );

					if ( null !== $image_block ) {
						$blocks[] = $image_block;
						$i        = $last;
						continue;
					}
				}
			}

			if ( $node instanceof \DOMElement && in_array( strtolower( $node->nodeName ), array( 'audio', 'iframe', 'video' ), true ) ) {
				$caption = null;
				$last    = $i;

				for ( $j = $i + 1; $j < $count; ++$j ) {
					$candidate = $node_array[ $j ];

					if ( XML_TEXT_NODE === $candidate->nodeType && '' === trim( (string) $candidate->textContent ) ) {
						$last = $j;
						continue;
					}

					if ( $candidate instanceof \DOMElement && 'figcaption' === strtolower( $candidate->nodeName ) ) {
						$caption = $candidate;
						$last    = $j;
					}

					break;
				}

				if ( null !== $caption ) {
					$media_block = $this->orphan_figcaption_to_media_block( $node, $caption );

					if ( null !== $media_block ) {
						$blocks[] = $media_block;
						$i        = $last;
						continue;
					}
				}
			}

			if ( $node instanceof \DOMElement && 'summary' === strtolower( $node->nodeName ) ) {
				$body_nodes = array();
				$last       = $i;

				for ( $j = $i + 1; $j < $count; ++$j ) {
					$candidate = $node_array[ $j ];

					if ( XML_TEXT_NODE === $candidate->nodeType && '' === trim( (string) $candidate->textContent ) ) {
						$last = $j;
						continue;
					}

					if ( XML_TEXT_NODE === $candidate->nodeType ) {
						$body_nodes[] = $candidate;
						$last         = $j;
						continue;
					}

					if ( $candidate instanceof \DOMElement && $this->is_orphan_summary_body_node( $candidate ) ) {
						$body_nodes[] = $candidate;
						$last         = $j;
						continue;
					}

					break;
				}

				if ( ! empty( $body_nodes ) ) {
					$details_block = $this->orphan_summary_to_details_block( $node, $body_nodes );

					if ( null !== $details_block ) {
						$blocks[] = $details_block;
						$i        = $last;
						continue;
					}
				}
			}

			if ( $node instanceof \DOMElement && in_array( strtolower( $node->nodeName ), array( 'caption', 'col', 'colgroup' ), true ) ) {
				$items = array( $node );
				$last  = $i;

				for ( $j = $i + 1; $j < $count; ++$j ) {
					$candidate = $node_array[ $j ];

					if ( XML_TEXT_NODE === $candidate->nodeType && '' === trim( (string) $candidate->textContent ) ) {
						$last = $j;
						continue;
					}

					if ( $candidate instanceof \DOMElement && in_array( strtolower( $candidate->nodeName ), array( 'caption', 'col', 'colgroup', 'tbody', 'td', 'tfoot', 'th', 'thead', 'tr' ), true ) ) {
						$items[] = $candidate;
						$last    = $j;
						continue;
					}

					break;
				}

				$table_block = $this->orphan_table_metadata_items_to_block( $items );

				if ( null !== $table_block ) {
					$blocks[] = $table_block;
					$i        = $last;
					continue;
				}
			}

			if ( $node instanceof \DOMElement && in_array( strtolower( $node->nodeName ), array( 'tbody', 'tfoot', 'thead' ), true ) ) {
				$items = array( $node );
				$last  = $i;

				for ( $j = $i + 1; $j < $count; ++$j ) {
					$candidate = $node_array[ $j ];

					if ( XML_TEXT_NODE === $candidate->nodeType && '' === trim( (string) $candidate->textContent ) ) {
						$last = $j;
						continue;
					}

					if ( $candidate instanceof \DOMElement && in_array( strtolower( $candidate->nodeName ), array( 'tbody', 'tfoot', 'thead' ), true ) ) {
						$items[] = $candidate;
						$last    = $j;
						continue;
					}

					if ( $candidate instanceof \DOMElement && 'caption' === strtolower( $candidate->nodeName ) ) {
						$items[] = $candidate;
						$last    = $j;
					}

					break;
				}

				$table_block = $this->orphan_table_sections_to_block( $items );

				if ( null !== $table_block ) {
					$blocks[] = $table_block;
					$i        = $last;
					continue;
				}
			}

			if ( $node instanceof \DOMElement && in_array( strtolower( $node->nodeName ), array( 'td', 'th', 'tr' ), true ) ) {
				$name  = strtolower( $node->nodeName );
				$items = array( $node );
				$last  = $i;

				for ( $j = $i + 1; $j < $count; ++$j ) {
					$candidate = $node_array[ $j ];

					if ( XML_TEXT_NODE === $candidate->nodeType && '' === trim( (string) $candidate->textContent ) ) {
						$last = $j;
						continue;
					}

					if ( $candidate instanceof \DOMElement ) {
						$candidate_name = strtolower( $candidate->nodeName );

						if (
							( 'tr' === $name && 'tr' === $candidate_name )
							|| ( in_array( $name, array( 'td', 'th' ), true ) && in_array( $candidate_name, array( 'td', 'th' ), true ) )
						) {
							$items[] = $candidate;
							$last    = $j;
							continue;
						}

						if ( 'caption' === $candidate_name ) {
							$items[] = $candidate;
							$last    = $j;
						}
					}

					break;
				}

				$blocks[] = $this->orphan_table_items_to_block( $items );
				$i        = $last;
				continue;
			}

			if ( $node instanceof \DOMElement && in_array( strtolower( $node->nodeName ), array( 'dd', 'dt' ), true ) ) {
				$items = array( $node );
				$last  = $i;

				for ( $j = $i + 1; $j < $count; ++$j ) {
					$candidate = $node_array[ $j ];

					if ( XML_TEXT_NODE === $candidate->nodeType && '' === trim( (string) $candidate->textContent ) ) {
						$last = $j;
						continue;
					}

					if ( $candidate instanceof \DOMElement && in_array( strtolower( $candidate->nodeName ), array( 'dd', 'dt' ), true ) ) {
						$items[] = $candidate;
						$last    = $j;
						continue;
					}

					break;
				}

				$definition_blocks = $this->orphan_definition_items_to_blocks( $items );

				if ( null !== $definition_blocks ) {
					foreach ( $definition_blocks as $block ) {
						$blocks[] = $block;
					}

					$i = $last;
					continue;
				}
			}

			if ( $node instanceof \DOMElement && 'li' === strtolower( $node->nodeName ) ) {
				$items = array( $node );
				$last  = $i;

				for ( $j = $i + 1; $j < $count; ++$j ) {
					$candidate = $node_array[ $j ];

					if ( XML_TEXT_NODE === $candidate->nodeType && '' === trim( (string) $candidate->textContent ) ) {
						$last = $j;
						continue;
					}

					if ( $candidate instanceof \DOMElement && 'li' === strtolower( $candidate->nodeName ) ) {
						$items[] = $candidate;
						$last    = $j;
						continue;
					}

					break;
				}

				$blocks[] = $this->orphan_list_items_to_block( $items );
				$i        = $last;
				continue;
			}

			foreach ( $this->node_to_blocks( $node ) as $block ) {
				if ( '' !== $block ) {
					$blocks[] = $block;
				}
			}
		}

		return $blocks;
	}

	/**
	 * Converts child nodes into blocks.
	 *
	 * @param \DOMNode $node DOM node.
	 * @return array<int,string>
	 */
	private function child_nodes_to_blocks( \DOMNode $node ) {
		return $this->node_list_to_blocks( $node->childNodes );
	}

	/**
	 * Converts structured applet fallback content into native blocks.
	 *
	 * @param \DOMElement $node Applet element.
	 * @return array<int,string>|null
	 */
	private function applet_fallback_blocks( \DOMElement $node ) {
		$blocks = array();

		foreach ( $node->childNodes as $child ) {
			if ( XML_TEXT_NODE === $child->nodeType && '' === trim( (string) $child->textContent ) ) {
				continue;
			}

			if ( $child instanceof \DOMElement && 'param' === strtolower( $child->nodeName ) ) {
				continue;
			}

			foreach ( $this->node_to_blocks( $child ) as $block ) {
				if ( '' !== $block ) {
					$blocks[] = $block;
				}
			}
		}

		if ( empty( $blocks ) || $this->contains_classic_block( $blocks ) ) {
			return null;
		}

		return $blocks;
	}

	/**
	 * Converts fallback-only object content into native blocks.
	 *
	 * @param \DOMElement $node Object element.
	 * @return array<int,string>|null
	 */
	private function object_fallback_blocks( \DOMElement $node ) {
		if ( 0 < $node->attributes->length ) {
			return null;
		}

		$blocks = array();

		foreach ( $node->childNodes as $child ) {
			foreach ( $this->node_to_blocks( $child ) as $block ) {
				if ( '' !== $block ) {
					$blocks[] = $block;
				}
			}
		}

		if ( empty( $blocks ) || $this->contains_classic_block( $blocks ) ) {
			return null;
		}

		return $blocks;
	}

	/**
	 * Converts non-form fieldset content into native blocks.
	 *
	 * @param \DOMElement $node Fieldset element.
	 * @return array<int,string>|null
	 */
	private function fieldset_content_blocks( \DOMElement $node ) {
		if ( ! $this->fieldset_has_visible_legend( $node ) || $this->fieldset_has_form_controls( $node ) ) {
			return null;
		}

		$blocks = $this->child_nodes_to_blocks( $node );

		if ( empty( $blocks ) || $this->contains_classic_block( $blocks ) ) {
			return null;
		}

		return $blocks;
	}

	/**
	 * Returns whether a fieldset has a visible direct legend child.
	 *
	 * @param \DOMElement $node Fieldset element.
	 * @return bool
	 */
	private function fieldset_has_visible_legend( \DOMElement $node ) {
		foreach ( $node->childNodes as $child ) {
			if ( $child instanceof \DOMElement && 'legend' === strtolower( $child->nodeName ) ) {
				$html = trim( $this->inner_html( $child ) );

				return '' !== $html && $this->node_has_only_inline_children( $child ) && $this->has_visible_inline_content( $html );
			}
		}

		return false;
	}

	/**
	 * Returns whether a fieldset still contains real form controls.
	 *
	 * @param \DOMElement $node Fieldset element.
	 * @return bool
	 */
	private function fieldset_has_form_controls( \DOMElement $node ) {
		foreach ( array( 'button', 'input', 'select', 'textarea' ) as $tag ) {
			if ( 0 < $node->getElementsByTagName( $tag )->length ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Converts visible dialog content into native blocks.
	 *
	 * @param \DOMElement $node Dialog element.
	 * @return array<int,string>|null
	 */
	private function dialog_content_blocks( \DOMElement $node ) {
		if ( ! $node->hasAttribute( 'open' ) || $this->dialog_has_form_controls( $node ) ) {
			return null;
		}

		$blocks = $this->child_nodes_to_blocks( $node );

		if ( empty( $blocks ) || $this->contains_classic_block( $blocks ) ) {
			return null;
		}

		return $blocks;
	}

	/**
	 * Returns whether a dialog contains form UI that should preserve the wrapper.
	 *
	 * @param \DOMElement $node Dialog element.
	 * @return bool
	 */
	private function dialog_has_form_controls( \DOMElement $node ) {
		foreach ( array( 'button', 'form', 'input', 'select', 'textarea' ) as $tag ) {
			if ( 0 < $node->getElementsByTagName( $tag )->length ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Converts orphan gallery figcaptions into native Gallery captions.
	 *
	 * @param \DOMElement $gallery Gallery wrapper.
	 * @param \DOMElement $caption Figcaption element.
	 * @return string|null
	 */
	private function orphan_figcaption_to_gallery_block( \DOMElement $gallery, \DOMElement $caption ) {
		$caption_html = trim( $this->inner_html( $caption ) );

		if (
			'' === $caption_html
			|| ! $this->node_has_only_inline_children( $caption )
			|| ! $this->has_visible_inline_content( $caption_html )
		) {
			return null;
		}

		$size_slug    = $this->gallery_size_slug( $gallery );
		$image_blocks = $this->gallery_image_blocks( $gallery, $size_slug );

		if ( null === $image_blocks || count( $image_blocks ) < 2 ) {
			return null;
		}

		return $this->gallery_to_block( $gallery, $caption_html );
	}

	/**
	 * Converts orphan quote citations into native Quote citations.
	 *
	 * @param \DOMElement $blockquote Blockquote element.
	 * @param \DOMElement $citation   Cite or figcaption element.
	 * @return string|null
	 */
	private function orphan_citation_to_quote_block( \DOMElement $blockquote, \DOMElement $citation ) {
		$citation_html = trim( $this->inner_html( $citation ) );

		if (
			'' === $citation_html
			|| $this->node_contains_tag( $blockquote, 'cite' )
			|| $this->is_pullquote_candidate( $blockquote )
			|| ! $this->node_has_only_inline_children( $citation )
			|| ! $this->has_visible_inline_content( $citation_html )
		) {
			return null;
		}

		return $this->quote_to_block( $blockquote, $citation_html );
	}

	/**
	 * Converts orphan pullquote citations into native Pullquote citations.
	 *
	 * @param \DOMElement $blockquote Pullquote blockquote element.
	 * @param \DOMElement $citation   Cite or figcaption element.
	 * @return string|null
	 */
	private function orphan_citation_to_pullquote_block( \DOMElement $blockquote, \DOMElement $citation ) {
		$citation_html = trim( $this->inner_html( $citation ) );

		if (
			'' === $citation_html
			|| ! $this->is_pullquote_candidate( $blockquote )
			|| $this->node_contains_tag( $blockquote, 'cite' )
			|| ! $this->node_has_only_inline_children( $citation )
			|| ! $this->has_visible_inline_content( $citation_html )
		) {
			return null;
		}

		return $this->pullquote_to_block( $blockquote, $citation_html );
	}

	/**
	 * Converts image-like orphan nodes followed by figcaption into Image blocks.
	 *
	 * @param \DOMElement $node    Image, picture, or linked image node.
	 * @param \DOMElement $caption Figcaption element.
	 * @return string|null
	 */
	private function orphan_figcaption_to_image_block( \DOMElement $node, \DOMElement $caption ) {
		$caption_html = trim( $this->inner_html( $caption ) );

		if (
			'' === $caption_html
			|| ! $this->node_has_only_inline_children( $caption )
			|| ! $this->has_visible_inline_content( $caption_html )
		) {
			return null;
		}

		$name = strtolower( $node->nodeName );

		if ( 'img' === $name ) {
			return $this->image_to_block( $node, $caption_html );
		}

		if ( 'picture' === $name ) {
			$image = $this->first_descendant_by_tag( $node, 'img' );

			if ( null === $image || '' === $this->element_attribute( $image, 'src' ) || $this->picture_has_unexpected_direct_children( $node ) ) {
				return null;
			}

			return $this->picture_to_image_block( $node, $caption_html );
		}

		if ( 'a' === $name ) {
			$linked_image = $this->single_direct_child_element_by_tag( $node, 'img' );

			if ( null !== $linked_image ) {
				return $this->image_to_block( $linked_image, $caption_html, $node );
			}

			$linked_picture = $this->single_direct_child_element_by_tag( $node, 'picture' );

			if ( null !== $linked_picture ) {
				return $this->linked_picture_to_image_block( $linked_picture, $node, $caption_html );
			}
		}

		return null;
	}

	/**
	 * Converts orphan media/embed nodes followed by figcaption into captioned blocks.
	 *
	 * @param \DOMElement $node    Media or iframe node.
	 * @param \DOMElement $caption Figcaption element.
	 * @return string|null
	 */
	private function orphan_figcaption_to_media_block( \DOMElement $node, \DOMElement $caption ) {
		$caption_html = trim( $this->inner_html( $caption ) );

		if (
			'' === $caption_html
			|| ! $this->node_has_only_inline_children( $caption )
			|| ! $this->has_visible_inline_content( $caption_html )
		) {
			return null;
		}

		$name = strtolower( $node->nodeName );

		if ( in_array( $name, array( 'audio', 'video' ), true ) ) {
			return $this->media_element_to_block( $node, $name, $caption_html );
		}

		if ( 'iframe' === $name && null !== $this->embed_provider_for_url( $this->element_attribute( $node, 'src' ) ) ) {
			return $this->iframe_to_block( $node, $caption_html );
		}

		return null;
	}

	/**
	 * Converts an orphan summary plus structured body nodes into Details.
	 *
	 * @param \DOMElement         $summary    Summary element.
	 * @param array<int,\DOMNode> $body_nodes Body nodes.
	 * @return string|null
	 */
	private function orphan_summary_to_details_block( \DOMElement $summary, array $body_nodes ) {
		$summary_html = trim( $this->inner_html( $summary ) );

		if (
			'' === $summary_html
			|| ! $this->node_has_only_inline_children( $summary )
			|| ! $this->has_visible_inline_content( $summary_html )
		) {
			return null;
		}

		$body_blocks = array();

		$body_count = count( $body_nodes );

		for ( $i = 0; $i < $body_count; ++$i ) {
			$body = $body_nodes[ $i ];

			if ( $body instanceof \DOMElement && 'li' === strtolower( $body->nodeName ) ) {
				$items = array( $body );

				for ( $j = $i + 1; $j < $body_count; ++$j ) {
					$candidate = $body_nodes[ $j ];

					if ( $candidate instanceof \DOMElement && 'li' === strtolower( $candidate->nodeName ) ) {
						$items[] = $candidate;
						$i       = $j;
						continue;
					}

					break;
				}

				$body_blocks[] = $this->orphan_list_items_to_block( $items );
				continue;
			}

			foreach ( $this->node_to_blocks( $body ) as $block ) {
				if ( '' !== $block ) {
					$body_blocks[] = $block;
				}
			}
		}

		if ( empty( $body_blocks ) || $this->contains_classic_block( $body_blocks ) ) {
			return null;
		}

		return $this->block_open_comment( 'details', array() ) . "\n"
			. '<details class="wp-block-details"><summary>' . $summary_html . '</summary>' . "\n"
			. implode( "\n\n", $body_blocks ) . '</details>' . "\n"
			. '<!-- /wp:details -->';
	}

	/**
	 * Returns whether a sibling can be grouped into an orphan Summary body.
	 *
	 * @param \DOMElement $node Candidate body node.
	 * @return bool
	 */
	private function is_orphan_summary_body_node( \DOMElement $node ) {
		return in_array( strtolower( $node->nodeName ), array( 'a', 'address', 'applet', 'article', 'aside', 'audio', 'blockquote', 'center', 'code', 'details', 'dialog', 'dir', 'div', 'dl', 'fieldset', 'figure', 'font', 'footer', 'form', 'header', 'hr', 'iframe', 'img', 'li', 'listing', 'main', 'marquee', 'menu', 'nav', 'noembed', 'noframes', 'noscript', 'object', 'ol', 'p', 'picture', 'plaintext', 'pre', 'section', 'table', 'ul', 'video', 'xmp' ), true )
			|| $this->is_static_obsolete_inline_element( $node )
			|| $this->is_standalone_inline_paragraph_element( $node );
	}

	/**
	 * Converts leading orphan table metadata plus row content into one Table block.
	 *
	 * @param array<int,\DOMElement> $items Table metadata and content elements.
	 * @return string|null
	 */
	private function orphan_table_metadata_items_to_block( array $items ) {
		$table             = $items[0]->ownerDocument->createElement( 'table' );
		$pending_colgroup  = null;
		$pending_cell_row  = null;
		$has_table_content = false;

		foreach ( $items as $item ) {
			$name = strtolower( $item->nodeName );

			if ( 'col' === $name ) {
				if ( null === $pending_colgroup ) {
					$pending_colgroup = $items[0]->ownerDocument->createElement( 'colgroup' );
				}

				$pending_colgroup->appendChild( $item->cloneNode( true ) );
				continue;
			}

			if ( null !== $pending_colgroup ) {
				$table->appendChild( $pending_colgroup );
				$pending_colgroup = null;
			}

			if ( in_array( $name, array( 'td', 'th' ), true ) ) {
				if ( null === $pending_cell_row ) {
					$pending_cell_row = $items[0]->ownerDocument->createElement( 'tr' );
				}

				$pending_cell_row->appendChild( $item->cloneNode( true ) );
				$has_table_content = true;
				continue;
			}

			if ( null !== $pending_cell_row ) {
				$table->appendChild( $pending_cell_row );
				$pending_cell_row = null;
			}

			if ( in_array( $name, array( 'tbody', 'tfoot', 'thead' ), true ) && ! $this->table_section_has_only_rows( $item ) ) {
				return null;
			}

			if ( in_array( $name, array( 'tbody', 'td', 'tfoot', 'th', 'thead', 'tr' ), true ) ) {
				$has_table_content = true;
			}

			$table->appendChild( $item->cloneNode( true ) );
		}

		if ( null !== $pending_colgroup ) {
			$table->appendChild( $pending_colgroup );
		}

		if ( null !== $pending_cell_row ) {
			$table->appendChild( $pending_cell_row );
		}

		if ( ! $has_table_content ) {
			return null;
		}

		return $this->table_to_block( $table );
	}

	/**
	 * Converts consecutive top-level orphan table sections into one Table block.
	 *
	 * @param array<int,\DOMElement> $items Table section elements.
	 * @return string|null
	 */
	private function orphan_table_sections_to_block( array $items ) {
		$table = $items[0]->ownerDocument->createElement( 'table' );

		foreach ( $items as $item ) {
			if ( 'caption' === strtolower( $item->nodeName ) ) {
				$table->appendChild( $item->cloneNode( true ) );
				continue;
			}

			if ( ! $this->table_section_has_only_rows( $item ) ) {
				return null;
			}

			$table->appendChild( $item->cloneNode( true ) );
		}

		return $this->table_to_block( $table );
	}

	/**
	 * Returns whether a table section contains only rows and whitespace.
	 *
	 * @param \DOMElement $section Table section element.
	 * @return bool
	 */
	private function table_section_has_only_rows( \DOMElement $section ) {
		$has_rows = false;

		foreach ( $section->childNodes as $child ) {
			if ( XML_TEXT_NODE === $child->nodeType && '' === trim( (string) $child->textContent ) ) {
				continue;
			}

			if ( ! $child instanceof \DOMElement || 'tr' !== strtolower( $child->nodeName ) ) {
				return false;
			}

			$has_rows = true;
		}

		return $has_rows;
	}

	/**
	 * Converts consecutive top-level orphan table rows/cells into one Table block.
	 *
	 * @param array<int,\DOMElement> $items Table row or cell elements.
	 * @return string
	 */
	private function orphan_table_items_to_block( array $items ) {
		$table = $items[0]->ownerDocument->createElement( 'table' );
		$name  = strtolower( $items[0]->nodeName );

		if ( in_array( $name, array( 'td', 'th' ), true ) ) {
			$row = $items[0]->ownerDocument->createElement( 'tr' );

			foreach ( $items as $item ) {
				if ( 'caption' === strtolower( $item->nodeName ) ) {
					$table->appendChild( $item->cloneNode( true ) );
				} else {
					$row->appendChild( $item->cloneNode( true ) );
				}
			}

			$table->appendChild( $row );
		} else {
			foreach ( $items as $item ) {
				$table->appendChild( $item->cloneNode( true ) );
			}
		}

		return $this->table_to_block( $table );
	}

	/**
	 * Converts consecutive top-level orphan FAQ definition items into Details blocks.
	 *
	 * @param array<int,\DOMElement> $items Definition term/description elements.
	 * @return array<int,string>|null
	 */
	private function orphan_definition_items_to_blocks( array $items ) {
		$list = $items[0]->ownerDocument->createElement( 'dl' );

		foreach ( $items as $item ) {
			$list->appendChild( $item->cloneNode( true ) );
		}

		$pairs = $this->definition_list_pairs( $list );

		if ( empty( $pairs ) || ! $this->is_faq_definition_list_candidate( $list ) ) {
			return null;
		}

		$blocks = array();

		foreach ( $pairs as $pair ) {
			$body_blocks = $this->definition_description_blocks( $pair['definitions'] );

			if ( '' === trim( $this->inner_html( $pair['term'] ) ) || empty( $body_blocks ) || $this->contains_classic_block( $body_blocks ) ) {
				return null;
			}

			$blocks[] = $this->definition_pair_to_details_block( $list, $pair['term'], $pair['definitions'], $body_blocks );
		}

		return empty( $blocks ) ? null : $blocks;
	}

	/**
	 * Converts consecutive top-level orphan list items into one unordered List block.
	 *
	 * @param array<int,\DOMElement> $items List item elements.
	 * @return string
	 */
	private function orphan_list_items_to_block( array $items ) {
		$has_metadata = false;

		foreach ( $items as $item ) {
			if ( '' !== $this->element_attribute( $item, 'id' ) || null !== $this->list_item_value( $item ) ) {
				$has_metadata = true;
				break;
			}
		}

		if ( $has_metadata ) {
			$list_html = implode(
				"\n",
				array_map(
					function ( \DOMElement $item ) {
						return $this->list_item_to_block( $item );
					},
					$items
				)
			);
		} else {
			$list_html = implode(
				'',
				array_map(
					function ( \DOMElement $item ) {
						return trim( $this->outer_html( $item ) );
					},
					$items
				)
			);
		}

		return $this->block_open_comment( 'list', array() ) . "\n"
			. '<ul>' . $list_html . '</ul>' . "\n"
			. '<!-- /wp:list -->';
	}

	/**
	 * Returns whether a block list contains classic fallback markup.
	 *
	 * @param array<int,string> $blocks Block markup.
	 * @return bool
	 */
	private function contains_classic_block( array $blocks ) {
		foreach ( $blocks as $block ) {
			if ( false !== strpos( $block, '<!-- wp:freeform -->' ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Returns whether an element is clearly meant to be a pullquote.
	 *
	 * @param \DOMElement $node Element.
	 * @return bool
	 */
	private function is_pullquote_candidate( \DOMElement $node ) {
		$name = strtolower( $node->nodeName );

		if ( 'blockquote' === $name ) {
			return $this->has_any_class_token( $node, array( 'pullquote', 'wp-block-pullquote' ) );
		}

		if ( 'figure' !== $name || ! $this->has_any_class_token( $node, array( 'pullquote', 'wp-block-pullquote' ) ) ) {
			return false;
		}

		return null !== $this->direct_pullquote_blockquote( $node );
	}

	/**
	 * Converts a legacy or already-native pullquote wrapper into a Pullquote block.
	 *
	 * @param \DOMElement $node         Pullquote element.
	 * @param string|null $caption_html Optional caption HTML.
	 * @return string
	 */
	private function pullquote_to_block( \DOMElement $node, $caption_html = null ) {
		$blockquote = 'blockquote' === strtolower( $node->nodeName ) ? $node : $this->direct_pullquote_blockquote( $node );

		if ( null === $blockquote ) {
			return $this->classic_block( trim( $this->outer_html( $node ) ) );
		}

		$quote_inner       = trim( $this->inner_html( $blockquote ) );
		$caption           = null === $caption_html ? $this->direct_child_inner_html( $node, 'figcaption' ) : $caption_html;
		$block_attributes  = array();
		$figure_attributes = array( 'class' => 'wp-block-pullquote' );
		$metadata_source   = $node;
		$layout_align      = $this->wide_or_full_alignment( $metadata_source );
		$text_align        = $this->text_alignment( $metadata_source );
		$anchor            = $this->element_attribute( $metadata_source, 'id' );
		$blockquote_align  = $this->text_alignment( $blockquote );
		$blockquote_anchor = $this->element_attribute( $blockquote, 'id' );

		if ( '' !== $layout_align ) {
			$block_attributes['align']   = $layout_align;
			$figure_attributes['class'] .= ' align' . $layout_align;
		}

		if ( '' === $text_align ) {
			$text_align = $blockquote_align;
		}

		if ( '' === $anchor ) {
			$anchor = $blockquote_anchor;
		}

		if ( '' !== $text_align ) {
			$block_attributes['textAlign'] = $text_align;
			$figure_attributes['class']   .= ' has-text-align-' . $text_align;
		}

		if ( '' !== $anchor ) {
			$block_attributes['anchor'] = $anchor;
			$figure_attributes['id']    = $anchor;
		}

		if ( null !== $caption && '' !== $caption && ! $this->node_contains_tag( $blockquote, 'cite' ) ) {
			$quote_inner .= '<cite>' . $caption . '</cite>';
		}

		return $this->block_open_comment( 'pullquote', $block_attributes ) . "\n"
			. '<figure' . $this->html_attributes( $figure_attributes ) . '><blockquote>' . $quote_inner . '</blockquote></figure>' . "\n"
			. '<!-- /wp:pullquote -->';
	}

	/**
	 * Returns the direct blockquote child from a clean pullquote figure.
	 *
	 * @param \DOMElement $node Pullquote figure.
	 * @return \DOMElement|null
	 */
	private function direct_pullquote_blockquote( \DOMElement $node ) {
		$blockquote = null;

		foreach ( $node->childNodes as $child ) {
			if ( XML_TEXT_NODE === $child->nodeType && '' === trim( (string) $child->textContent ) ) {
				continue;
			}

			if ( ! $child instanceof \DOMElement ) {
				return null;
			}

			$name = strtolower( $child->nodeName );

			if ( 'br' === $name || 'figcaption' === $name ) {
				continue;
			}

			if ( 'blockquote' !== $name || null !== $blockquote ) {
				return null;
			}

			$blockquote = $child;
		}

		return $blockquote;
	}

	/**
	 * Returns whether a figure is a plain quoted passage with an optional caption.
	 *
	 * @param \DOMElement $node Element.
	 * @return bool
	 */
	private function is_figure_quote_candidate( \DOMElement $node ) {
		if ( 'figure' !== strtolower( $node->nodeName ) ) {
			return false;
		}

		$blockquote = null;

		foreach ( $node->childNodes as $child ) {
			if ( XML_TEXT_NODE === $child->nodeType && '' === trim( (string) $child->textContent ) ) {
				continue;
			}

			if ( ! $child instanceof \DOMElement ) {
				return false;
			}

			$name = strtolower( $child->nodeName );

			if ( 'br' === $name ) {
				continue;
			}

			if ( 'blockquote' === $name ) {
				if ( null !== $blockquote ) {
					return false;
				}

				$blockquote = $child;
				continue;
			}

			if ( 'figcaption' === $name ) {
				continue;
			}

			return false;
		}

		return null !== $blockquote;
	}

	/**
	 * Converts a figure-wrapped quoted passage into a native Quote block.
	 *
	 * @param \DOMElement $node Figure element.
	 * @return string
	 */
	private function figure_quote_to_block( \DOMElement $node ) {
		$blockquote = null;

		foreach ( $node->childNodes as $child ) {
			if ( $child instanceof \DOMElement && 'blockquote' === strtolower( $child->nodeName ) ) {
				$blockquote = $child;
				break;
			}
		}

		if ( null === $blockquote ) {
			return $this->classic_block( trim( $this->outer_html( $node ) ) );
		}

		return $this->quote_to_block( $blockquote, $this->direct_child_inner_html( $node, 'figcaption' ), $node );
	}

	/**
	 * Converts a quoted passage into a native Quote block.
	 *
	 * @param \DOMElement      $blockquote      Blockquote element.
	 * @param string|null      $caption_html    Optional figure caption HTML.
	 * @param \DOMElement|null $metadata_source Optional wrapper to read source metadata from.
	 * @return string
	 */
	private function quote_to_block( \DOMElement $blockquote, $caption_html = null, \DOMElement $metadata_source = null ) {
		$quote_inner       = trim( $this->inner_html( $blockquote ) );
		$metadata_source   = null === $metadata_source ? $blockquote : $metadata_source;
		$block_attributes  = array();
		$html_attributes   = array( 'class' => 'wp-block-quote' );
		$text_align        = $this->text_alignment( $metadata_source );
		$anchor            = $this->element_attribute( $metadata_source, 'id' );
		$blockquote_align  = $this->text_alignment( $blockquote );
		$blockquote_anchor = $this->element_attribute( $blockquote, 'id' );

		if ( '' === $text_align ) {
			$text_align = $blockquote_align;
		}

		if ( '' === $anchor ) {
			$anchor = $blockquote_anchor;
		}

		if ( '' !== $text_align ) {
			$block_attributes['textAlign'] = $text_align;
			$html_attributes['class']     .= ' has-text-align-' . $text_align;
		}

		if ( '' !== $anchor ) {
			$block_attributes['anchor'] = $anchor;
			$html_attributes['id']      = $anchor;
		}

		if ( null !== $caption_html && '' !== $caption_html && ! $this->node_contains_tag( $blockquote, 'cite' ) ) {
			$quote_inner .= '<cite>' . $caption_html . '</cite>';
		}

		return $this->block_open_comment( 'quote', $block_attributes ) . "\n"
			. '<blockquote' . $this->html_attributes( $html_attributes ) . '>' . $quote_inner . '</blockquote>' . "\n"
			. '<!-- /wp:quote -->';
	}

	/**
	 * Converts a paragraph node into a native Paragraph block.
	 *
	 * @param \DOMElement $node       Paragraph element.
	 * @param string      $inner_html Paragraph inner HTML.
	 * @return string
	 */
	private function paragraph_to_block( \DOMElement $node, $inner_html ) {
		$attributes = array();
		$html_attrs = array();
		$align      = $this->paragraph_alignment( $node );
		$anchor     = $this->element_attribute( $node, 'id' );

		if ( '' !== $align ) {
			$attributes['align'] = $align;
			$html_attrs['class'] = 'has-text-align-' . $align;
		}

		if ( '' !== $anchor ) {
			$attributes['anchor'] = $anchor;
			$html_attrs['id']     = $anchor;
		}

		return $this->block_open_comment( 'paragraph', $attributes ) . "\n"
			. '<p' . $this->html_attributes( $html_attrs ) . '>' . $inner_html . '</p>' . "\n"
			. '<!-- /wp:paragraph -->';
	}

	/**
	 * Returns a safe Paragraph block text alignment from imported markup.
	 *
	 * @param \DOMElement $node Paragraph element.
	 * @return string
	 */
	private function paragraph_alignment( \DOMElement $node ) {
		return $this->text_alignment( $node );
	}

	/**
	 * Returns a safe block text alignment from imported markup.
	 *
	 * @param \DOMElement $node Element.
	 * @return string
	 */
	private function text_alignment( \DOMElement $node ) {
		$classes = array_map( 'strtolower', $this->class_tokens( $node ) );

		foreach ( array( 'left', 'center', 'right' ) as $align ) {
			if (
				in_array( 'has-text-align-' . $align, $classes, true )
				|| in_array( 'align' . $align, $classes, true )
			) {
				return $align;
			}
		}

		$style = $this->element_attribute( $node, 'style' );
		if ( 1 === preg_match( '/(?:^|;)\s*text-align\s*:\s*(left|center|right)\s*(?:!important)?\s*(?:;|$)/i', $style, $matches ) ) {
			return strtolower( $matches[1] );
		}

		$align = strtolower( $this->element_attribute( $node, 'align' ) );
		if ( in_array( $align, array( 'left', 'center', 'right' ), true ) ) {
			return $align;
		}

		return '';
	}

	/**
	 * Returns whether a tag is a semantic container that can be unwrapped.
	 *
	 * @param string $name Tag name.
	 * @return bool
	 */
	private function is_semantic_wrapper( $name ) {
		return in_array( $name, array( 'article', 'aside', 'div', 'footer', 'header', 'main', 'nav', 'section' ), true );
	}

	/**
	 * Converts an ordered or unordered list into a native List block.
	 *
	 * @param \DOMNode $node List node.
	 * @return string
	 */
	private function list_to_block( \DOMNode $node ) {
		$name       = 'ol' === strtolower( $node->nodeName ) ? 'ol' : 'ul';
		$attributes = $this->list_block_attributes( $node );
		$html_attrs = $this->list_html_attributes( $node );
		$list_html  = $this->list_inner_html( $node );

		return $this->block_open_comment( 'list', $attributes ) . "\n"
			. '<' . $name . $this->html_attributes( $html_attrs ) . '>' . $list_html . '</' . $name . '>' . "\n"
			. '<!-- /wp:list -->';
	}

	/**
	 * Builds list inner markup, using list-item blocks when item metadata exists.
	 *
	 * @param \DOMNode $node List node.
	 * @return string
	 */
	private function list_inner_html( \DOMNode $node ) {
		if ( ! $node instanceof \DOMElement ) {
			return trim( $this->inner_html( $node ) );
		}

		$items        = array();
		$has_metadata = false;

		foreach ( $node->childNodes as $child ) {
			if ( XML_TEXT_NODE === $child->nodeType && '' === trim( (string) $child->textContent ) ) {
				continue;
			}

			if ( ! $child instanceof \DOMElement || 'li' !== strtolower( $child->nodeName ) ) {
				return trim( $this->inner_html( $node ) );
			}

			$items[] = $child;

			if ( '' !== $this->element_attribute( $child, 'id' ) || null !== $this->list_item_value( $child ) ) {
				$has_metadata = true;
			}
		}

		if ( empty( $items ) || ! $has_metadata ) {
			return trim( $this->inner_html( $node ) );
		}

		$item_blocks = array();

		foreach ( $items as $item ) {
			$item_blocks[] = $this->list_item_to_block( $item );
		}

		return implode( "\n", $item_blocks );
	}

	/**
	 * Converts one list item into a nested core/list-item block.
	 *
	 * @param \DOMElement $item List item element.
	 * @return string
	 */
	private function list_item_to_block( \DOMElement $item ) {
		return $this->block_open_comment( 'list-item', $this->list_item_block_attributes( $item ) ) . "\n"
			. '<li' . $this->html_attributes( $this->list_item_html_attributes( $item ) ) . '>' . trim( $this->inner_html( $item ) ) . '</li>' . "\n"
			. '<!-- /wp:list-item -->';
	}

	/**
	 * Builds List Item block attributes from safe imported item metadata.
	 *
	 * @param \DOMElement $item List item element.
	 * @return array<string,mixed>
	 */
	private function list_item_block_attributes( \DOMElement $item ) {
		$attributes = array();
		$anchor     = $this->element_attribute( $item, 'id' );

		if ( '' !== $anchor ) {
			$attributes['anchor'] = $anchor;
		}

		return $attributes;
	}

	/**
	 * Builds saved li attributes for metadata represented in list item markup.
	 *
	 * @param \DOMElement $item List item element.
	 * @return array<string,string>
	 */
	private function list_item_html_attributes( \DOMElement $item ) {
		$attributes = array();
		$anchor     = $this->element_attribute( $item, 'id' );
		$value      = $this->list_item_value( $item );

		if ( '' !== $anchor ) {
			$attributes['id'] = $anchor;
		}

		if ( null !== $value ) {
			$attributes['value'] = (string) $value;
		}

		return $attributes;
	}

	/**
	 * Returns a bounded list-item value override.
	 *
	 * @param \DOMElement $item List item element.
	 * @return int|null
	 */
	private function list_item_value( \DOMElement $item ) {
		$value = $this->element_attribute( $item, 'value' );

		if ( '' === $value || 1 !== preg_match( '/^-?\d+$/', $value ) ) {
			return null;
		}

		return max( -999999, min( 999999, (int) $value ) );
	}

	/**
	 * Builds List block attributes from safe imported list metadata.
	 *
	 * @param \DOMNode $node List node.
	 * @return array<string,mixed>
	 */
	private function list_block_attributes( \DOMNode $node ) {
		$attributes = array();

		if ( 'ol' === strtolower( $node->nodeName ) ) {
			$attributes['ordered'] = true;

			if ( $node instanceof \DOMElement && $node->hasAttribute( 'reversed' ) ) {
				$attributes['reversed'] = true;
			}

			$start = $this->ordered_list_start( $node );
			if ( null !== $start ) {
				$attributes['start'] = $start;
			}

			$type = $this->ordered_list_type( $node );
			if ( '' !== $type ) {
				$attributes['type'] = $type;
			}
		}

		$anchor = $this->element_attribute( $node, 'id' );
		if ( '' !== $anchor ) {
			$attributes['anchor'] = $anchor;
		}

		return $attributes;
	}

	/**
	 * Builds saved list tag attributes for metadata represented in block attributes.
	 *
	 * @param \DOMNode $node List node.
	 * @return array<string,string>
	 */
	private function list_html_attributes( \DOMNode $node ) {
		$attributes = array();
		$anchor     = $this->element_attribute( $node, 'id' );

		if ( '' !== $anchor ) {
			$attributes['id'] = $anchor;
		}

		if ( 'ol' !== strtolower( $node->nodeName ) ) {
			return $attributes;
		}

		if ( $node instanceof \DOMElement && $node->hasAttribute( 'reversed' ) ) {
			$attributes['reversed'] = 'reversed';
		}

		$start = $this->ordered_list_start( $node );
		if ( null !== $start ) {
			$attributes['start'] = (string) $start;
		}

		$type = $this->ordered_list_type( $node );
		if ( '' !== $type ) {
			$attributes['type'] = $type;
		}

		return $attributes;
	}

	/**
	 * Returns a bounded ordered-list start value.
	 *
	 * @param \DOMNode $node List node.
	 * @return int|null
	 */
	private function ordered_list_start( \DOMNode $node ) {
		$start = $this->element_attribute( $node, 'start' );

		if ( '' === $start || 1 !== preg_match( '/^-?\d+$/', $start ) ) {
			return null;
		}

		return max( -999999, min( 999999, (int) $start ) );
	}

	/**
	 * Returns a safe ordered-list type value.
	 *
	 * @param \DOMNode $node List node.
	 * @return string
	 */
	private function ordered_list_type( \DOMNode $node ) {
		$type = $this->element_attribute( $node, 'type' );

		if ( in_array( $type, array( '1', 'A', 'a', 'I', 'i' ), true ) ) {
			return $type;
		}

		if ( ! $node instanceof \DOMElement ) {
			return '';
		}

		$types = array(
			'decimal'     => '1',
			'lower-alpha' => 'a',
			'lower-roman' => 'i',
			'upper-alpha' => 'A',
			'upper-roman' => 'I',
		);
		$style = $this->element_attribute( $node, 'style' );

		if ( 1 === preg_match( '/(?:^|;)\s*list-style-type\s*:\s*([a-z-]+)\s*(?:!important)?\s*(?:;|$)/i', $style, $matches ) ) {
			$key = strtolower( $matches[1] );

			return isset( $types[ $key ] ) ? $types[ $key ] : '';
		}

		if ( 1 !== preg_match( '/(?:^|;)\s*list-style\s*:\s*([^;]+?)\s*(?:!important)?\s*(?:;|$)/i', $style, $matches ) ) {
			return '';
		}

		foreach ( preg_split( '/\s+/', strtolower( trim( $matches[1] ) ) ) as $token ) {
			if ( isset( $types[ $token ] ) ) {
				return $types[ $token ];
			}
		}

		return '';
	}

	/**
	 * Converts a horizontal rule into a native Separator block.
	 *
	 * @param \DOMNode $node Separator element.
	 * @return string
	 */
	private function separator_to_block( \DOMNode $node ) {
		$attributes = array();
		$html_attrs = array( 'class' => 'wp-block-separator has-alpha-channel-opacity' );

		if ( $node instanceof \DOMElement ) {
			$align = $this->image_alignment( $node );
			if ( in_array( $align, array( 'wide', 'full' ), true ) ) {
				$attributes['align']  = $align;
				$html_attrs['class'] .= ' align' . $align;
			}

			$style_class = $this->separator_style_class( $node );
			if ( '' !== $style_class ) {
				$attributes['className'] = $style_class;
				$html_attrs['class']    .= ' ' . $style_class;
			}

			$anchor = $this->element_attribute( $node, 'id' );
			if ( '' !== $anchor ) {
				$attributes['anchor'] = $anchor;
				$html_attrs['id']     = $anchor;
			}
		}

		return $this->block_open_comment( 'separator', $attributes ) . "\n"
			. '<hr' . $this->html_attributes( $html_attrs ) . '/>' . "\n"
			. '<!-- /wp:separator -->';
	}

	/**
	 * Returns the safe core Separator style class from imported markup.
	 *
	 * @param \DOMElement $node Separator element.
	 * @return string
	 */
	private function separator_style_class( \DOMElement $node ) {
		foreach ( array( 'is-style-wide', 'is-style-dots' ) as $class ) {
			if ( $this->has_class_token( $node, $class ) ) {
				return $class;
			}
		}

		return '';
	}

	/**
	 * Converts a preformatted element into a native Preformatted block.
	 *
	 * @param \DOMNode $node       Preformatted element.
	 * @param string   $inner_html Sanitized inner HTML.
	 * @return string
	 */
	private function preformatted_to_block( \DOMNode $node, $inner_html ) {
		$attributes = array();
		$html_attrs = array( 'class' => 'wp-block-preformatted' );

		if ( $node instanceof \DOMElement ) {
			$anchor = $this->element_attribute( $node, 'id' );
			if ( '' !== $anchor ) {
				$attributes['anchor'] = $anchor;
				$html_attrs['id']     = $anchor;
			}
		}

		return $this->block_open_comment( 'preformatted', $attributes ) . "\n"
			. '<pre' . $this->html_attributes( $html_attrs ) . '>' . $inner_html . '</pre>' . "\n"
			. '<!-- /wp:preformatted -->';
	}

	/**
	 * Converts obsolete text-only preformatted elements into Preformatted blocks.
	 *
	 * @param \DOMElement $node Obsolete preformatted element.
	 * @return string
	 */
	private function obsolete_preformatted_to_block( \DOMElement $node ) {
		foreach ( $node->childNodes as $child ) {
			if ( XML_TEXT_NODE !== $child->nodeType ) {
				return $this->classic_block( trim( $this->outer_html( $node ) ) );
			}
		}

		$text = (string) $node->textContent;

		if ( '' === trim( $text ) ) {
			return $this->classic_block( trim( $this->outer_html( $node ) ) );
		}

		return $this->preformatted_to_block( $node, $this->escape_html( $text ) );
	}

	/**
	 * Converts a code element into a code block.
	 *
	 * @param \DOMNode    $node    Code node.
	 * @param \DOMElement $context Optional wrapper that contributes block metadata.
	 * @return string
	 */
	private function code_to_block( \DOMNode $node, \DOMElement $context = null ) {
		$attributes = array();
		$html_attrs = array( 'class' => 'wp-block-code' );
		$anchor     = $this->source_anchor( $node, $context );
		$language   = $this->code_language_class( $node, $context );

		if ( '' !== $anchor ) {
			$attributes['anchor'] = $anchor;
			$html_attrs['id']     = $anchor;
		}

		if ( '' !== $language ) {
			$attributes['className'] = $language;
			$html_attrs['class']    .= ' ' . $language;
		}

		return $this->block_open_comment( 'code', $attributes ) . "\n"
			. '<pre' . $this->html_attributes( $html_attrs ) . '><code>' . $this->inner_html( $node ) . '</code></pre>' . "\n"
			. '<!-- /wp:code -->';
	}

	/**
	 * Returns a safe imported code language class.
	 *
	 * @param \DOMNode         $node    Code node.
	 * @param \DOMElement|null $context Optional wrapper element.
	 * @return string
	 */
	private function code_language_class( \DOMNode $node, \DOMElement $context = null ) {
		foreach ( array( $context, $node instanceof \DOMElement ? $node : null ) as $element ) {
			if ( ! $element instanceof \DOMElement ) {
				continue;
			}

			foreach ( $this->class_tokens( $element ) as $class ) {
				$language_class = strtolower( $class );

				if ( 1 === preg_match( '/^(?:language|lang)-[a-z0-9_-]+$/', $language_class ) ) {
					return $language_class;
				}
			}

			foreach ( array( 'data-language', 'data-lang' ) as $attribute ) {
				$language = strtolower( $this->element_attribute( $element, $attribute ) );

				if ( 1 === preg_match( '/^[a-z0-9_-]+$/', $language ) ) {
					return 'language-' . $language;
				}
			}
		}

		return '';
	}

	/**
	 * Converts legacy WordPress pagination comments into native blocks.
	 *
	 * @param \DOMNode $node Comment node.
	 * @return string|null
	 */
	private function legacy_content_comment_to_block( \DOMNode $node ) {
		$more = $this->legacy_more_comment_to_block( $node );

		if ( null !== $more ) {
			return $more;
		}

		if ( $this->is_legacy_nextpage_comment( $node ) ) {
			return "<!-- wp:nextpage -->\n<!--nextpage-->\n<!-- /wp:nextpage -->";
		}

		return null;
	}

	/**
	 * Converts a legacy More marker comment into a More block.
	 *
	 * @param \DOMNode      $node Comment node.
	 * @param \DOMNode|null $next Optional next sibling, used for noteaser.
	 * @return string|null
	 */
	private function legacy_more_comment_to_block( \DOMNode $node, \DOMNode $next = null ) {
		if ( XML_COMMENT_NODE !== $node->nodeType ) {
			return null;
		}

		$text = trim( (string) $node->nodeValue );

		if ( 1 !== preg_match( '/^more(?:\s+(.*))?$/is', $text, $matches ) ) {
			return null;
		}

		$custom_text = isset( $matches[1] ) ? $this->sanitize_more_custom_text( $matches[1] ) : '';
		$no_teaser   = null !== $next && $this->is_legacy_noteaser_comment( $next );
		$attributes  = array();

		if ( '' !== $custom_text ) {
			$attributes['customText'] = $custom_text;
		}

		if ( $no_teaser ) {
			$attributes['noTeaser'] = true;
		}

		return $this->block_open_comment( 'more', $attributes ) . "\n"
			. '<!--more' . ( '' === $custom_text ? '' : ' ' . $custom_text ) . '-->'
			. ( $no_teaser ? "\n<!--noteaser-->" : '' ) . "\n"
			. '<!-- /wp:more -->';
	}

	/**
	 * Returns whether a comment is a legacy WordPress noteaser marker.
	 *
	 * @param \DOMNode $node Comment node.
	 * @return bool
	 */
	private function is_legacy_noteaser_comment( \DOMNode $node ) {
		return XML_COMMENT_NODE === $node->nodeType
			&& 'noteaser' === strtolower( trim( (string) $node->nodeValue ) );
	}

	/**
	 * Returns whether a comment is a legacy WordPress page-break marker.
	 *
	 * @param \DOMNode $node Comment node.
	 * @return bool
	 */
	private function is_legacy_nextpage_comment( \DOMNode $node ) {
		return XML_COMMENT_NODE === $node->nodeType
			&& 'nextpage' === strtolower( trim( (string) $node->nodeValue ) );
	}

	/**
	 * Sanitizes legacy More custom text for safe comment and block serialization.
	 *
	 * @param string $text Source custom text.
	 * @return string
	 */
	private function sanitize_more_custom_text( $text ) {
		$text = html_entity_decode( (string) $text, ENT_QUOTES, 'UTF-8' );
		$text = preg_replace( '/\s+/', ' ', $text );
		$text = str_replace( array( '<', '>', '--' ), '', is_string( $text ) ? $text : '' );

		return trim( $text );
	}

	/**
	 * Converts standalone shortcode text into a native Shortcode block.
	 *
	 * @param string           $text    Text content.
	 * @param \DOMElement|null $context Optional source wrapper element.
	 * @return string|null
	 */
	private function shortcode_text_to_block( $text, \DOMElement $context = null ) {
		$shortcode = $this->normalized_standalone_shortcode_text( $text );

		if ( null === $shortcode ) {
			return null;
		}

		$media_block = $this->classic_media_shortcode_to_block( $shortcode, $context );

		if ( null !== $media_block ) {
			return $media_block;
		}

		$attributes = array();
		$anchor     = null === $context ? '' : $this->element_attribute( $context, 'id' );

		if ( '' !== $anchor ) {
			$attributes['anchor'] = $anchor;
		}

		return $this->block_open_comment( 'shortcode', $attributes ) . "\n" . $shortcode . "\n<!-- /wp:shortcode -->";
	}

	/**
	 * Converts safe standalone classic media shortcodes into native blocks.
	 *
	 * @param string           $shortcode Normalized shortcode text.
	 * @param \DOMElement|null $context   Optional source wrapper element.
	 * @return string|null
	 */
	private function classic_media_shortcode_to_block( $shortcode, \DOMElement $context = null ) {
		$shortcode = trim( (string) $shortcode );

		if ( 1 === preg_match( '/^\[embed(?:\s[^\]\r\n<>]*)?\]\s*(.*?)\s*\[\/\s*embed\s*\]$/is', $shortcode, $matches ) ) {
			$url      = trim( html_entity_decode( $matches[1], ENT_QUOTES, 'UTF-8' ) );
			$provider = $this->embed_provider_for_url( $url );

			if ( '' !== $url && null !== $provider && ! $this->is_scriptable_url( $url ) ) {
				return $this->url_to_embed_block(
					$url,
					$provider,
					null,
					null === $context ? '' : $this->element_attribute( $context, 'id' ),
					null === $context ? '' : $this->image_alignment( $context )
				);
			}

			return null;
		}

		if ( 1 === preg_match( '/^\[(audio|video)\b([^\]\r\n<>]*?)\]\s*(.*?)\s*\[\/\s*\1\s*\]$/is', $shortcode, $matches ) ) {
			return $this->media_shortcode_to_block( $matches[1], $matches[2], $matches[3], $context );
		}

		if ( 1 === preg_match( '/^\[(audio|video)\b([^\]\r\n<>]*?)(?:\/)?\]$/i', $shortcode, $matches ) ) {
			return $this->media_shortcode_to_block( $matches[1], $matches[2], '', $context );
		}

		return null;
	}

	/**
	 * Converts an audio/video shortcode into the matching native media block.
	 *
	 * @param string           $tag           Shortcode tag.
	 * @param string           $attribute_raw Raw shortcode attributes.
	 * @param string           $body          Optional enclosing body.
	 * @param \DOMElement|null $context       Optional source wrapper element.
	 * @return string|null
	 */
	private function media_shortcode_to_block( $tag, $attribute_raw, $body = '', \DOMElement $context = null ) {
		$block_name = 'audio' === strtolower( (string) $tag ) ? 'audio' : 'video';
		$attributes = $this->parse_shortcode_attributes( $attribute_raw );
		$src        = $this->media_shortcode_source( $block_name, $attributes, $body );

		if ( '' === $src || $this->is_scriptable_url( $src ) ) {
			return null;
		}

		$block_attributes = array(
			'src'      => $src,
			'controls' => true,
		);
		$html_attributes  = array(
			'controls' => 'controls',
			'src'      => $src,
		);

		foreach ( array( 'autoplay', 'loop', 'muted' ) as $boolean_attribute ) {
			if ( $this->shortcode_boolean_attribute_is_enabled( $attributes, $boolean_attribute ) ) {
				$block_attributes[ $boolean_attribute ] = true;
				$html_attributes[ $boolean_attribute ]  = $boolean_attribute;
			}
		}

		if ( 'video' === $block_name ) {
			$poster = isset( $attributes['poster'] ) ? $attributes['poster'] : '';

			if ( '' !== $poster && ! $this->is_scriptable_url( $poster ) ) {
				$block_attributes['poster'] = $poster;
				$html_attributes['poster']  = $poster;
			}
		}

		$preload = isset( $attributes['preload'] ) ? strtolower( $attributes['preload'] ) : '';

		if ( in_array( $preload, array( 'auto', 'metadata', 'none' ), true ) ) {
			$block_attributes['preload'] = $preload;
			$html_attributes['preload']  = $preload;
		}

		$class_name = 'audio' === $block_name ? 'wp-block-audio' : 'wp-block-video';
		$anchor     = null === $context ? '' : $this->element_attribute( $context, 'id' );
		$align      = null === $context ? '' : $this->image_alignment( $context );

		if ( '' !== $align ) {
			$block_attributes['align'] = $align;
			$class_name               .= ' align' . $align;
		}

		if ( '' !== $anchor ) {
			$block_attributes['anchor'] = $anchor;
		}

		$figure_attributes = array( 'class' => $class_name );
		if ( '' !== $anchor ) {
			$figure_attributes['id'] = $anchor;
		}

		return $this->block_open_comment( $block_name, $block_attributes ) . "\n"
			. '<figure' . $this->html_attributes( $figure_attributes ) . '><' . $block_name . $this->html_attributes( $html_attributes ) . '></' . $block_name . '></figure>' . "\n"
			. '<!-- /wp:' . $block_name . ' -->';
	}

	/**
	 * Finds the primary media URL from shortcode attributes or body text.
	 *
	 * @param string               $block_name Media block name.
	 * @param array<string,string> $attributes Shortcode attributes.
	 * @param string               $body       Optional enclosing body.
	 * @return string
	 */
	private function media_shortcode_source( $block_name, array $attributes, $body ) {
		$candidates = 'audio' === $block_name
			? array( 'src', 'mp3', 'm4a', 'ogg', 'wav', 'wma' )
			: array( 'src', 'mp4', 'm4v', 'webm', 'ogv', 'flv' );

		foreach ( $candidates as $name ) {
			if ( isset( $attributes[ $name ] ) && '' !== $attributes[ $name ] ) {
				return $attributes[ $name ];
			}
		}

		$body = trim( html_entity_decode( (string) $body, ENT_QUOTES, 'UTF-8' ) );

		if ( '' === $body || false !== strpos( $body, '[' ) || false !== strpos( $body, '<' ) || false !== strpos( $body, '>' ) ) {
			return '';
		}

		return $body;
	}

	/**
	 * Parses simple shortcode key/value attributes.
	 *
	 * @param string $attribute_raw Raw shortcode attributes.
	 * @return array<string,string>
	 */
	private function parse_shortcode_attributes( $attribute_raw ) {
		$attributes = array();

		if (
			false === preg_match_all(
				'/([A-Za-z][A-Za-z0-9_-]*)\s*=\s*("[^"]*"|\'[^\']*\'|[^\s\]]+)/',
				(string) $attribute_raw,
				$matches,
				PREG_SET_ORDER
			)
		) {
			return $attributes;
		}

		foreach ( $matches as $match ) {
			$name  = strtolower( $match[1] );
			$value = trim( $match[2], "\"' \t\n\r\0\x0B" );

			$attributes[ $name ] = html_entity_decode( $value, ENT_QUOTES, 'UTF-8' );
		}

		return $attributes;
	}

	/**
	 * Returns whether a shortcode boolean-like attribute is enabled.
	 *
	 * @param array<string,string> $attributes Shortcode attributes.
	 * @param string               $name       Attribute name.
	 * @return bool
	 */
	private function shortcode_boolean_attribute_is_enabled( array $attributes, $name ) {
		if ( ! isset( $attributes[ $name ] ) ) {
			return false;
		}

		return ! in_array( strtolower( $attributes[ $name ] ), array( '', '0', 'false', 'no', 'off' ), true );
	}

	/**
	 * Returns normalized shortcode text only when the whole string is shortcode markup.
	 *
	 * @param string $text Text content.
	 * @return string|null
	 */
	private function normalized_standalone_shortcode_text( $text ) {
		$text = html_entity_decode( (string) $text, ENT_QUOTES, 'UTF-8' );
		$text = str_replace( array( "\r\n", "\r" ), "\n", $text );
		$text = trim( $text );

		if ( '' === $text || '[' !== $text[0] || false !== strpos( $text, '<' ) || false !== strpos( $text, '>' ) ) {
			return null;
		}

		$remaining = $text;
		$matched   = false;

		while ( '' !== trim( $remaining ) ) {
			$remaining = ltrim( $remaining );

			if ( 0 !== strpos( $remaining, '[' ) || 0 === strpos( $remaining, '[/' ) ) {
				return null;
			}

			if ( 1 !== preg_match( '/^\[([A-Za-z][A-Za-z0-9_-]*)([^\]\r\n<>]*?)(\/?)\]/', $remaining, $matches ) ) {
				return null;
			}

			$tag         = $matches[1];
			$attributes  = trim( $matches[2] );
			$self_closed = '/' === $matches[3];

			if ( $this->is_dangerous_shortcode_tag( $tag ) ) {
				return null;
			}

			$opening_length  = strlen( $matches[0] );
			$after_opening   = substr( $remaining, $opening_length );
			$closing_pattern = '/\[\s*\/\s*' . preg_quote( $tag, '/' ) . '\s*\]/i';

			if ( 1 === preg_match( $closing_pattern, $after_opening, $closing, PREG_OFFSET_CAPTURE ) ) {
				$closing_offset = (int) $closing[0][1];
				$closing_length = strlen( $closing[0][0] );
				$remaining      = substr( $remaining, $opening_length + $closing_offset + $closing_length );
				$matched        = true;
				continue;
			}

			if (
				$self_closed
				|| false !== strpos( $attributes, '=' )
				|| ( '' === $attributes && $this->is_common_attrless_shortcode_tag( $tag ) )
			) {
				$remaining = substr( $remaining, $opening_length );
				$matched   = true;
				continue;
			}

			return null;
		}

		return $matched ? $text : null;
	}

	/**
	 * Returns whether a shortcode tag is too risky to preserve as executable content.
	 *
	 * @param string $tag Shortcode tag.
	 * @return bool
	 */
	private function is_dangerous_shortcode_tag( $tag ) {
		return in_array( strtolower( (string) $tag ), array( 'javascript', 'script', 'style' ), true );
	}

	/**
	 * Returns whether an attribute-free shortcode tag is common enough to infer safely.
	 *
	 * @param string $tag Shortcode tag.
	 * @return bool
	 */
	private function is_common_attrless_shortcode_tag( $tag ) {
		return in_array(
			strtolower( (string) $tag ),
			array( 'audio', 'caption', 'embed', 'gallery', 'playlist', 'video', 'wp_caption' ),
			true
		);
	}

	/**
	 * Returns whether a preformatted element is explicitly verse-like.
	 *
	 * @param \DOMElement $node Element.
	 * @return bool
	 */
	private function is_verse_candidate( \DOMElement $node ) {
		return $this->has_any_class_token(
			$node,
			array(
				'lyrics',
				'poem',
				'poetry',
				'stanza',
				'verse',
				'wp-block-verse',
			)
		);
	}

	/**
	 * Converts an obvious verse/poem/lyrics pre block into a native Verse block.
	 *
	 * @param \DOMElement $node Verse-like pre element.
	 * @return string
	 */
	private function verse_to_block( \DOMElement $node ) {
		$attributes = array();
		$html_attrs = array( 'class' => 'wp-block-verse' );
		$anchor     = $this->element_attribute( $node, 'id' );

		if ( '' !== $anchor ) {
			$attributes['anchor'] = $anchor;
			$html_attrs['id']     = $anchor;
		}

		return $this->block_open_comment( 'verse', $attributes ) . "\n"
			. '<pre' . $this->html_attributes( $html_attrs ) . '>' . $this->inner_html( $node ) . '</pre>' . "\n"
			. '<!-- /wp:verse -->';
	}

	/**
	 * Converts an image element into an image block.
	 *
	 * @param \DOMNode         $node         Image node.
	 * @param string|null      $caption_html Optional caption HTML.
	 * @param \DOMElement|null $link         Optional link wrapping the image.
	 * @return string
	 */
	private function image_to_block( \DOMNode $node, $caption_html = null, \DOMElement $link = null ) {
		$context    = $node instanceof \DOMElement ? $node : null;
		$anchor     = null === $context ? '' : $this->image_source_anchor( $context, $link );
		$visual     = null === $link ? $this->outer_html( $node ) : $this->normalize_link_rel_attribute_in_html( $this->outer_html( $link ), $this->safe_link_rel( $link ) );
		$inner_html = $this->remove_id_attribute_from_html( $visual, $anchor )
			. $this->caption_to_html( $caption_html );

		return $this->block_open_comment( 'image', $this->image_block_attributes( $link, $context ) ) . "\n"
			. '<figure' . $this->html_attributes( $this->image_figure_attributes( $context, $link ) ) . '>' . $inner_html . '</figure>' . "\n"
			. '<!-- /wp:image -->';
	}

	/**
	 * Returns whether a wrapper is clearly a classic captioned image.
	 *
	 * @param \DOMNode $node Wrapper node.
	 * @return bool
	 */
	private function is_captioned_image_wrapper_candidate( \DOMNode $node ) {
		if ( ! $node instanceof \DOMElement || ! in_array( strtolower( $node->nodeName ), array( 'div', 'figure', 'p' ), true ) ) {
			return false;
		}

		if (
			! $this->has_any_class_token( $node, array( 'caption', 'captioned-image', 'image-caption', 'image-with-caption', 'wp-caption', 'wp-block-image' ) )
			&& ! $this->has_class_token_with_prefix( $node, 'wp-caption-' )
		) {
			return false;
		}

		if ( 1 !== $node->getElementsByTagName( 'img' )->length ) {
			return false;
		}

		if ( $this->captioned_image_wrapper_has_unexpected_direct_children( $node ) ) {
			return false;
		}

		foreach ( array( 'audio', 'iframe', 'table', 'video' ) as $tag ) {
			if ( 0 < $node->getElementsByTagName( $tag )->length ) {
				return false;
			}
		}

		return '' !== $this->captioned_image_caption_html( $node );
	}

	/**
	 * Returns whether a captioned image wrapper has direct content that native Image conversion would drop.
	 *
	 * @param \DOMElement $node Captioned image wrapper.
	 * @return bool
	 */
	private function captioned_image_wrapper_has_unexpected_direct_children( \DOMElement $node ) {
		foreach ( $node->childNodes as $child ) {
			if ( XML_TEXT_NODE === $child->nodeType && '' === trim( (string) $child->textContent ) ) {
				continue;
			}

			if ( ! $child instanceof \DOMElement ) {
				continue;
			}

			$tag = strtolower( $child->nodeName );

			if ( 'br' === $tag || 'img' === $tag || 'figcaption' === $tag ) {
				continue;
			}

			if ( in_array( $tag, array( 'a', 'picture' ), true ) && 1 === $child->getElementsByTagName( 'img' )->length ) {
				continue;
			}

			if ( $this->has_any_class_token( $child, array( 'caption-text', 'figcaption', 'image-caption', 'wp-caption-text' ) ) ) {
				continue;
			}

			if ( 'p' === $tag && ! $this->node_contains_tag( $child, 'img' ) && $this->node_has_only_inline_children( $child ) && $this->has_visible_inline_content( $this->inner_html( $child ) ) ) {
				continue;
			}

			return true;
		}

		return false;
	}

	/**
	 * Converts a classic captioned image wrapper into an Image block.
	 *
	 * @param \DOMElement $node Captioned image wrapper.
	 * @return string
	 */
	private function captioned_image_wrapper_to_block( \DOMElement $node ) {
		$image = $this->first_descendant_by_tag( $node, 'img' );

		if ( null === $image ) {
			return $this->classic_block( trim( $this->outer_html( $node ) ) );
		}

		$link       = $this->image_wrapping_link( $image, $node );
		$attributes = $this->image_block_attributes( $link, $node );
		$anchor     = $this->element_attribute( $node, 'id' );
		$html_attrs = array( 'class' => $this->image_figure_class( $node ) );

		if ( '' !== $anchor ) {
			$attributes['anchor'] = $anchor;
			$html_attrs['id']     = $anchor;
		}

		return $this->block_open_comment( 'image', $attributes ) . "\n"
			. '<figure' . $this->html_attributes( $html_attrs ) . '>'
			. $this->image_visual_html( $image, $node )
			. $this->caption_to_html( $this->captioned_image_caption_html( $node ) )
			. '</figure>' . "\n"
			. '<!-- /wp:image -->';
	}

	/**
	 * Converts a standalone picture element into an Image block while preserving sources.
	 *
	 * @param \DOMElement $node         Picture element.
	 * @param string|null $caption_html Optional caption HTML.
	 * @return string
	 */
	private function picture_to_image_block( \DOMElement $node, $caption_html = null ) {
		$image = $this->first_descendant_by_tag( $node, 'img' );

		if ( null === $image || '' === $this->element_attribute( $image, 'src' ) || $this->picture_has_unexpected_direct_children( $node ) ) {
			return $this->classic_block( trim( $this->outer_html( $node ) ) );
		}

		$anchor = $this->element_attribute( $node, 'id' );
		$visual = $this->remove_id_attribute_from_html( $this->normalize_picture_source_html( $this->outer_html( $node ) ), $anchor );

		return $this->block_open_comment( 'image', $this->image_block_attributes( null, $node ) ) . "\n"
			. '<figure' . $this->html_attributes( $this->image_figure_attributes( $node ) ) . '>' . $visual . $this->caption_to_html( $caption_html ) . '</figure>' . "\n"
			. '<!-- /wp:image -->';
	}

	/**
	 * Converts a linked picture element into an Image block while preserving safe metadata.
	 *
	 * @param \DOMElement $picture      Picture element.
	 * @param \DOMElement $link         Link wrapping the picture.
	 * @param string|null $caption_html Optional caption HTML.
	 * @return string|null
	 */
	private function linked_picture_to_image_block( \DOMElement $picture, \DOMElement $link, $caption_html = null ) {
		$image = $this->first_descendant_by_tag( $picture, 'img' );

		if ( null === $image || '' === $this->element_attribute( $image, 'src' ) || $this->picture_has_unexpected_direct_children( $picture ) ) {
			return null;
		}

		$anchor = $this->image_source_anchor( $picture, $link );
		$visual = $this->remove_id_attribute_from_html( $this->normalize_picture_source_html( $this->normalize_link_rel_attribute_in_html( $this->outer_html( $link ), $this->safe_link_rel( $link ) ) ), $anchor );

		return $this->block_open_comment( 'image', $this->image_block_attributes( $link, $picture ) ) . "\n"
			. '<figure' . $this->html_attributes( $this->image_figure_attributes( $picture, $link ) ) . '>' . $visual . $this->caption_to_html( $caption_html ) . '</figure>' . "\n"
			. '<!-- /wp:image -->';
	}

	/**
	 * Returns whether a picture element has direct content that native Image conversion would preserve invalidly.
	 *
	 * @param \DOMElement $picture Picture element.
	 * @return bool
	 */
	private function picture_has_unexpected_direct_children( \DOMElement $picture ) {
		foreach ( $picture->childNodes as $child ) {
			if ( $this->picture_node_is_unexpected( $child ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Returns whether a parsed picture child is outside the supported responsive image shape.
	 *
	 * @param \DOMNode $node Picture descendant.
	 * @return bool
	 */
	private function picture_node_is_unexpected( \DOMNode $node ) {
		if ( XML_TEXT_NODE === $node->nodeType ) {
			return '' !== trim( (string) $node->textContent );
		}

		if ( ! $node instanceof \DOMElement ) {
			return false;
		}

		if ( ! in_array( strtolower( $node->nodeName ), array( 'br', 'img', 'source' ), true ) ) {
			return true;
		}

		foreach ( $node->childNodes as $child ) {
			if ( $this->picture_node_is_unexpected( $child ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Returns the caption text from a classic caption wrapper.
	 *
	 * @param \DOMElement $node Captioned image wrapper.
	 * @return string
	 */
	private function captioned_image_caption_html( \DOMElement $node ) {
		$caption = $this->direct_child_inner_html( $node, 'figcaption' );

		if ( null !== $caption && '' !== trim( $caption ) ) {
			return trim( $caption );
		}

		foreach ( $node->childNodes as $child ) {
			if ( ! $child instanceof \DOMElement ) {
				continue;
			}

			if ( $this->has_any_class_token( $child, array( 'caption-text', 'figcaption', 'image-caption', 'wp-caption-text' ) ) ) {
				return trim( $this->inner_html( $child ) );
			}
		}

		if ( ! $this->has_any_class_token( $node, array( 'caption', 'wp-caption' ) ) ) {
			return '';
		}

		foreach ( $node->childNodes as $child ) {
			if ( ! $child instanceof \DOMElement || 'p' !== strtolower( $child->nodeName ) || $this->node_contains_tag( $child, 'img' ) ) {
				continue;
			}

			if ( $this->node_has_only_inline_children( $child ) && $this->has_visible_inline_content( $this->inner_html( $child ) ) ) {
				return trim( $this->inner_html( $child ) );
			}
		}

		return '';
	}

	/**
	 * Returns the image or responsive picture markup to preserve in an Image block.
	 *
	 * @param \DOMElement $image     Image element.
	 * @param \DOMElement $container Containing wrapper.
	 * @return string
	 */
	private function image_visual_html( \DOMElement $image, \DOMElement $container ) {
		$link = $this->image_wrapping_link( $image, $container );

		if ( null !== $link ) {
			return $this->normalize_picture_source_html( $this->normalize_link_rel_attribute_in_html( $this->outer_html( $link ), $this->safe_link_rel( $link ) ) );
		}

		$picture = $this->image_wrapping_picture( $image, $container );

		if ( null !== $picture ) {
			return $this->normalize_picture_source_html( $this->outer_html( $picture ) );
		}

		return $this->outer_html( $image );
	}

	/**
	 * Normalizes DOMDocument's serialized picture/source markup back to HTML void elements.
	 *
	 * @param string $html Serialized HTML.
	 * @return string
	 */
	private function normalize_picture_source_html( $html ) {
		return str_replace( '</source>', '', (string) $html );
	}

	/**
	 * Normalizes or removes a serialized link rel attribute.
	 *
	 * @param string $html Serialized link HTML.
	 * @param string $rel  Normalized rel value.
	 * @return string
	 */
	private function normalize_link_rel_attribute_in_html( $html, $rel ) {
		$replacement = '' === $rel ? '' : ' rel="' . $this->escape_html( $rel ) . '"';

		return preg_replace( '/\s+rel=(["\'])(.*?)\1/i', $replacement, (string) $html, 1 );
	}

	/**
	 * Removes a duplicated source ID after it has been moved to the block wrapper.
	 *
	 * @param string $html   Serialized HTML.
	 * @param string $anchor Anchor value moved to the block wrapper.
	 * @return string
	 */
	private function remove_id_attribute_from_html( $html, $anchor ) {
		if ( '' === $anchor ) {
			return (string) $html;
		}

		$html = preg_replace(
			'/\s+id=(["\'])' . preg_quote( $anchor, '/' ) . '\1/i',
			'',
			(string) $html
		);

		return is_string( $html ) ? $html : '';
	}

	/**
	 * Converts a figure that contains an image into an image block.
	 *
	 * @param \DOMNode $node Figure node.
	 * @return string
	 */
	private function figure_image_to_block( \DOMNode $node ) {
		$image = $this->first_descendant_by_tag( $node, 'img' );

		if ( null === $image ) {
			return $this->classic_block( trim( $this->outer_html( $node ) ) );
		}

		if ( $node instanceof \DOMElement && $this->figure_image_has_unexpected_direct_children( $node ) ) {
			return $this->classic_block( trim( $this->outer_html( $node ) ) );
		}

		$link   = $this->image_parent_link( $image, $node );
		$anchor = '';

		$attributes = $node instanceof \DOMElement ? $this->image_block_attributes( $link, $node ) : $this->image_block_attributes( $link );
		$html_attrs = $node instanceof \DOMElement ? $this->image_figure_attributes( $node ) : array( 'class' => 'wp-block-image' );

		if ( $node instanceof \DOMElement && isset( $attributes['anchor'] ) && ! isset( $html_attrs['id'] ) ) {
			$anchor           = (string) $attributes['anchor'];
			$html_attrs['id'] = $anchor;
		} elseif ( $node instanceof \DOMElement && ! isset( $attributes['anchor'] ) ) {
			$anchor = $this->figure_image_source_anchor( $image, $node );
			if ( '' !== $anchor ) {
				$attributes['anchor'] = $anchor;
				$html_attrs['id']     = $anchor;
			}
		}

		return $this->block_open_comment( 'image', $attributes ) . "\n"
			. '<figure' . $this->html_attributes( $html_attrs ) . '>'
			. $this->remove_id_attribute_from_html( $this->figure_inner_html_with_caption_class( $node ), $anchor )
			. '</figure>' . "\n"
			. '<!-- /wp:image -->';
	}

	/**
	 * Returns the source anchor for a figure-wrapped image when the figure has no id.
	 *
	 * @param \DOMElement $image  Image element.
	 * @param \DOMElement $figure Figure element.
	 * @return string
	 */
	private function figure_image_source_anchor( \DOMElement $image, \DOMElement $figure ) {
		$picture = $this->image_wrapping_picture( $image, $figure );

		if ( null !== $picture ) {
			$anchor = $this->element_attribute( $picture, 'id' );

			if ( '' !== $anchor ) {
				return $anchor;
			}
		}

		$anchor = $this->element_attribute( $image, 'id' );
		if ( '' !== $anchor ) {
			return $anchor;
		}

		$link = $this->image_parent_link( $image, $figure );

		return null === $link ? '' : $this->element_attribute( $link, 'id' );
	}

	/**
	 * Returns whether a figure contains direct content that does not belong in a native Image block.
	 *
	 * @param \DOMElement $node Figure node.
	 * @return bool
	 */
	private function figure_image_has_unexpected_direct_children( \DOMElement $node ) {
		foreach ( $node->childNodes as $child ) {
			if ( XML_TEXT_NODE === $child->nodeType && '' === trim( (string) $child->textContent ) ) {
				continue;
			}

			if ( ! $child instanceof \DOMElement ) {
				continue;
			}

			$tag = strtolower( $child->nodeName );

			if ( in_array( $tag, array( 'br', 'figcaption', 'img' ), true ) ) {
				continue;
			}

			if ( in_array( $tag, array( 'a', 'picture' ), true ) && 1 === $child->getElementsByTagName( 'img' )->length ) {
				continue;
			}

			return true;
		}

		return false;
	}

	/**
	 * Builds Image block attributes from an optional wrapping link.
	 *
	 * @param \DOMElement|null $link    Optional link wrapping the image.
	 * @param \DOMElement|null $context Optional source image or wrapper element.
	 * @return array<string,mixed>
	 */
	private function image_block_attributes( \DOMElement $link = null, \DOMElement $context = null ) {
		$attributes = array();
		$align      = $this->image_alignment( $context );
		$size_slug  = $this->image_size_slug( $context );
		$anchor     = null === $context ? '' : $this->element_attribute( $context, 'id' );

		if ( '' !== $align ) {
			$attributes['align'] = $align;
		}

		if ( '' !== $size_slug ) {
			$attributes['sizeSlug'] = $size_slug;
		}

		if ( null === $link ) {
			if ( '' !== $anchor ) {
				$attributes['anchor'] = $anchor;
			}

			return $attributes;
		}

		$href = $this->element_attribute( $link, 'href' );

		if ( '' === $href ) {
			return $attributes;
		}

		if ( '' === $anchor ) {
			$anchor = $this->element_attribute( $link, 'id' );
		}

		$attributes['href']            = $href;
		$attributes['linkDestination'] = 'custom';

		$target = $this->normalized_reserved_link_target( $link );
		if ( '' !== $target ) {
			$attributes['linkTarget'] = $target;
		}

		$rel = $this->safe_link_rel( $link );
		if ( '' !== $rel ) {
			$attributes['rel'] = $rel;
		}

		if ( '' !== $anchor ) {
			$attributes['anchor'] = $anchor;
		}

		return $attributes;
	}

	/**
	 * Builds saved figure attributes for native Image block markup.
	 *
	 * @param \DOMElement|null $context Optional source image or wrapper element.
	 * @param \DOMElement|null $link    Optional link wrapping the image.
	 * @return array<string,string>
	 */
	private function image_figure_attributes( \DOMElement $context = null, \DOMElement $link = null ) {
		$attributes = array(
			'class' => $this->image_figure_class( $context ),
		);
		$anchor     = $this->image_source_anchor( $context, $link );

		if ( '' !== $anchor ) {
			$attributes['id'] = $anchor;
		}

		return $attributes;
	}

	/**
	 * Returns the source anchor for an image block, falling back to a wrapping link id.
	 *
	 * @param \DOMElement|null $context Optional source image or wrapper element.
	 * @param \DOMElement|null $link    Optional link wrapping the image.
	 * @return string
	 */
	private function image_source_anchor( \DOMElement $context = null, \DOMElement $link = null ) {
		$anchor = null === $context ? '' : $this->element_attribute( $context, 'id' );

		if ( '' === $anchor && null !== $link ) {
			$anchor = $this->element_attribute( $link, 'id' );
		}

		return $anchor;
	}

	/**
	 * Builds the saved figure class for an Image block.
	 *
	 * @param \DOMElement|null $context Optional source image or wrapper element.
	 * @return string
	 */
	private function image_figure_class( \DOMElement $context = null ) {
		$classes   = array( 'wp-block-image' );
		$align     = $this->image_alignment( $context );
		$size_slug = $this->image_size_slug( $context );

		if ( '' !== $align ) {
			$classes[] = 'align' . $align;
		}

		if ( '' !== $size_slug ) {
			$classes[] = 'size-' . $size_slug;
		}

		return implode( ' ', $classes );
	}

	/**
	 * Returns a safe Image block alignment from WordPress alignment classes.
	 *
	 * @param \DOMElement|null $context Optional source image or wrapper element.
	 * @return string
	 */
	private function image_alignment( \DOMElement $context = null ) {
		if ( null === $context ) {
			return '';
		}

		foreach ( $this->class_tokens( $context ) as $class ) {
			$align_class = strtolower( $class );

			if ( in_array( $align_class, array( 'alignleft', 'aligncenter', 'alignright', 'alignwide', 'alignfull' ), true ) ) {
				return substr( $align_class, 5 );
			}
		}

		$align = strtolower( $this->element_attribute( $context, 'align' ) );
		if ( in_array( $align, array( 'left', 'center', 'right' ), true ) ) {
			return $align;
		}

		$style = $this->element_attribute( $context, 'style' );
		if ( 1 === preg_match( '/(?:^|;)\s*float\s*:\s*(left|right)\s*(?:!important)?\s*(?:;|$)/i', $style, $matches ) ) {
			return strtolower( $matches[1] );
		}

		if (
			1 === preg_match( '/(?:^|;)\s*margin-left\s*:\s*auto\s*(?:!important)?\s*(?:;|$)/i', $style )
			&& 1 === preg_match( '/(?:^|;)\s*margin-right\s*:\s*auto\s*(?:!important)?\s*(?:;|$)/i', $style )
		) {
			return 'center';
		}

		if ( 1 === preg_match( '/(?:^|;)\s*margin\s*:\s*(?:0|0px|0em|0rem)?\s*auto(?:\s+(?:0|0px|0em|0rem))?\s*(?:!important)?\s*(?:;|$)/i', $style ) ) {
			return 'center';
		}

		return '';
	}

	/**
	 * Returns a safe wide/full layout alignment for blocks that only support layout width.
	 *
	 * @param \DOMElement|null $context Optional source wrapper element.
	 * @return string
	 */
	private function wide_or_full_alignment( \DOMElement $context = null ) {
		$align = $this->image_alignment( $context );

		return in_array( $align, array( 'wide', 'full' ), true ) ? $align : '';
	}

	/**
	 * Returns a safe horizontal alignment for blocks that support left/center/right only.
	 *
	 * @param \DOMElement|null $context Optional source wrapper element.
	 * @return string
	 */
	private function horizontal_alignment( \DOMElement $context = null ) {
		$align = $this->image_alignment( $context );

		return in_array( $align, array( 'left', 'center', 'right' ), true ) ? $align : '';
	}

	/**
	 * Returns a safe Image block size slug from WordPress image size classes.
	 *
	 * @param \DOMElement|null $context Optional source image or wrapper element.
	 * @return string
	 */
	private function image_size_slug( \DOMElement $context = null ) {
		if ( null === $context ) {
			return '';
		}

		$slug = $this->image_size_slug_from_element( $context );
		if ( '' !== $slug ) {
			return $slug;
		}

		if ( 'img' !== strtolower( $context->nodeName ) ) {
			$image = $this->first_descendant_by_tag( $context, 'img' );

			if ( null !== $image ) {
				return $this->image_size_slug_from_element( $image );
			}
		}

		return '';
	}

	/**
	 * Returns a safe Image block size slug from one element's class tokens.
	 *
	 * @param \DOMElement $element Source element.
	 * @return string
	 */
	private function image_size_slug_from_element( \DOMElement $element ) {
		foreach ( $this->class_tokens( $element ) as $class ) {
			if ( 1 !== preg_match( '/^size-([A-Za-z0-9_-]+)$/i', $class, $matches ) ) {
				continue;
			}

			$slug = strtolower( $matches[1] );
			if ( in_array( $slug, array( 'thumbnail', 'medium', 'large', 'full' ), true ) ) {
				return $slug;
			}
		}

		return '';
	}

	/**
	 * Converts a table element into a table block.
	 *
	 * @param \DOMNode         $node         Table node.
	 * @param string|null      $caption_html Optional caption HTML.
	 * @param \DOMElement|null $wrapper      Optional figure/wrapper element.
	 * @return string
	 */
	private function table_to_block( \DOMNode $node, $caption_html = null, \DOMElement $wrapper = null ) {
		$caption_html     = null === $caption_html ? $this->direct_child_inner_html( $node, 'caption' ) : $caption_html;
		$table_html       = $this->table_inner_html_without_caption( $node );
		$block_attributes = $this->table_block_attributes( $node, $wrapper );
		$figure_attrs     = $this->table_figure_attributes( $node, $wrapper );
		$table_attrs      = $this->table_html_attributes( $node, $wrapper );

		return $this->block_open_comment( 'table', $block_attributes ) . "\n"
			. '<figure' . $this->html_attributes( $figure_attrs ) . '><table' . $this->html_attributes( $table_attrs ) . '>' . $table_html . '</table>'
			. $this->caption_to_html( $caption_html )
			. '</figure>' . "\n"
			. '<!-- /wp:table -->';
	}

	/**
	 * Converts a figure that contains a table into a table block.
	 *
	 * @param \DOMNode $node Figure node.
	 * @return string
	 */
	private function figure_table_to_block( \DOMNode $node ) {
		$table = $this->first_descendant_by_tag( $node, 'table' );

		if ( null === $table ) {
			return $this->classic_block( trim( $this->outer_html( $node ) ) );
		}

		if ( $node instanceof \DOMElement && $this->figure_table_has_unexpected_direct_children( $node ) ) {
			return $this->classic_block( trim( $this->outer_html( $node ) ) );
		}

		return $node instanceof \DOMElement
			? $this->table_to_block( $table, $this->direct_child_inner_html( $node, 'figcaption' ), $node )
			: $this->table_to_block( $table, $this->direct_child_inner_html( $node, 'figcaption' ) );
	}

	/**
	 * Returns whether a table figure has direct content that native Table conversion would drop.
	 *
	 * @param \DOMElement $node Figure node.
	 * @return bool
	 */
	private function figure_table_has_unexpected_direct_children( \DOMElement $node ) {
		foreach ( $node->childNodes as $child ) {
			if ( XML_TEXT_NODE === $child->nodeType && '' === trim( (string) $child->textContent ) ) {
				continue;
			}

			if ( ! $child instanceof \DOMElement ) {
				continue;
			}

			if ( in_array( strtolower( $child->nodeName ), array( 'br', 'figcaption', 'table' ), true ) ) {
				continue;
			}

			return true;
		}

		return false;
	}

	/**
	 * Builds Table block attributes from clear imported table layout hints.
	 *
	 * @param \DOMNode         $table   Table element.
	 * @param \DOMElement|null $wrapper Optional figure/wrapper element.
	 * @return array<string,mixed>
	 */
	private function table_block_attributes( \DOMNode $table, \DOMElement $wrapper = null ) {
		$attributes = array();
		$align      = $this->table_alignment( $table, $wrapper );
		$anchor     = $this->table_anchor( $table, $wrapper );

		if ( '' !== $align ) {
			$attributes['align'] = $align;
		}

		if ( $this->table_has_fixed_layout( $table, $wrapper ) ) {
			$attributes['hasFixedLayout'] = true;
		}

		if ( $this->table_has_stripes_style( $table, $wrapper ) ) {
			$attributes['className'] = 'is-style-stripes';
		}

		if ( '' !== $anchor ) {
			$attributes['anchor'] = $anchor;
		}

		return $attributes;
	}

	/**
	 * Builds saved figure attributes for native Table block markup.
	 *
	 * @param \DOMNode         $table   Table element.
	 * @param \DOMElement|null $wrapper Optional figure/wrapper element.
	 * @return array<string,string>
	 */
	private function table_figure_attributes( \DOMNode $table, \DOMElement $wrapper = null ) {
		$attributes = array(
			'class' => $this->table_figure_class( $table, $wrapper ),
		);
		$anchor     = $this->table_anchor( $table, $wrapper );

		if ( '' !== $anchor ) {
			$attributes['id'] = $anchor;
		}

		return $attributes;
	}

	/**
	 * Builds the saved figure class for a Table block.
	 *
	 * @param \DOMNode         $table   Table element.
	 * @param \DOMElement|null $wrapper Optional figure/wrapper element.
	 * @return string
	 */
	private function table_figure_class( \DOMNode $table, \DOMElement $wrapper = null ) {
		$classes = array( 'wp-block-table' );
		$align   = $this->table_alignment( $table, $wrapper );

		if ( '' !== $align ) {
			$classes[] = 'align' . $align;
		}

		if ( $this->table_has_stripes_style( $table, $wrapper ) ) {
			$classes[] = 'is-style-stripes';
		}

		return implode( ' ', $classes );
	}

	/**
	 * Builds saved table attributes for native Table block markup.
	 *
	 * @param \DOMNode         $table   Table element.
	 * @param \DOMElement|null $wrapper Optional figure/wrapper element.
	 * @return array<string,string>
	 */
	private function table_html_attributes( \DOMNode $table, \DOMElement $wrapper = null ) {
		if ( ! $this->table_has_fixed_layout( $table, $wrapper ) ) {
			return array();
		}

		return array( 'class' => 'has-fixed-layout' );
	}

	/**
	 * Returns a native Table block alignment from imported classes or attributes.
	 *
	 * @param \DOMNode         $table   Table element.
	 * @param \DOMElement|null $wrapper Optional figure/wrapper element.
	 * @return string
	 */
	private function table_alignment( \DOMNode $table, \DOMElement $wrapper = null ) {
		foreach ( $this->table_source_elements( $table, $wrapper ) as $element ) {
			foreach ( $this->class_tokens( $element ) as $class ) {
				$align_class = strtolower( $class );

				if ( in_array( $align_class, array( 'alignleft', 'aligncenter', 'alignright', 'alignwide', 'alignfull' ), true ) ) {
					return substr( $align_class, 5 );
				}
			}

			$align = strtolower( $this->element_attribute( $element, 'align' ) );
			if ( in_array( $align, array( 'left', 'center', 'right' ), true ) ) {
				return $align;
			}

			$style_align = $this->table_style_alignment( $element );
			if ( '' !== $style_align ) {
				return $style_align;
			}
		}

		return '';
	}

	/**
	 * Returns a native Table block alignment from imported inline styles.
	 *
	 * @param \DOMElement $element Source table or wrapper element.
	 * @return string
	 */
	private function table_style_alignment( \DOMElement $element ) {
		$style = $this->element_attribute( $element, 'style' );

		if ( 1 === preg_match( '/(?:^|;)\s*float\s*:\s*(left|right)\s*(?:!important)?\s*(?:;|$)/i', $style, $matches ) ) {
			return strtolower( $matches[1] );
		}

		if (
			1 === preg_match( '/(?:^|;)\s*margin-left\s*:\s*auto\s*(?:!important)?\s*(?:;|$)/i', $style )
			&& 1 === preg_match( '/(?:^|;)\s*margin-right\s*:\s*auto\s*(?:!important)?\s*(?:;|$)/i', $style )
		) {
			return 'center';
		}

		if ( 1 === preg_match( '/(?:^|;)\s*margin\s*:\s*(?:0|0px|0em|0rem)?\s*auto(?:\s+(?:0|0px|0em|0rem))?\s*(?:!important)?\s*(?:;|$)/i', $style ) ) {
			return 'center';
		}

		return '';
	}

	/**
	 * Returns whether imported table classes/style signal fixed-width cells.
	 *
	 * @param \DOMNode         $table   Table element.
	 * @param \DOMElement|null $wrapper Optional figure/wrapper element.
	 * @return bool
	 */
	private function table_has_fixed_layout( \DOMNode $table, \DOMElement $wrapper = null ) {
		foreach ( $this->table_source_elements( $table, $wrapper ) as $element ) {
			foreach ( $this->class_tokens( $element ) as $class ) {
				if ( in_array( strtolower( $class ), array( 'has-fixed-layout', 'fixed-layout', 'table-fixed' ), true ) ) {
					return true;
				}
			}

			if ( 1 === preg_match( '/(?:^|;)\s*table-layout\s*:\s*fixed\s*(?:;|$)/i', $this->element_attribute( $element, 'style' ) ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Returns whether imported table classes signal the core striped style.
	 *
	 * @param \DOMNode         $table   Table element.
	 * @param \DOMElement|null $wrapper Optional figure/wrapper element.
	 * @return bool
	 */
	private function table_has_stripes_style( \DOMNode $table, \DOMElement $wrapper = null ) {
		foreach ( $this->table_source_elements( $table, $wrapper ) as $element ) {
			foreach ( $this->class_tokens( $element ) as $class ) {
				if ( in_array( strtolower( $class ), array( 'is-style-stripes', 'striped', 'stripes', 'table-striped' ), true ) ) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * Returns table-related source elements in precedence order.
	 *
	 * @param \DOMNode         $table   Table element.
	 * @param \DOMElement|null $wrapper Optional figure/wrapper element.
	 * @return array<int,\DOMElement>
	 */
	private function table_source_elements( \DOMNode $table, \DOMElement $wrapper = null ) {
		$elements = array();

		if ( null !== $wrapper ) {
			$elements[] = $wrapper;
		}

		if ( $table instanceof \DOMElement ) {
			$elements[] = $table;
		}

		return $elements;
	}

	/**
	 * Returns the first source anchor that can be represented by a Table block.
	 *
	 * @param \DOMNode         $table   Table element.
	 * @param \DOMElement|null $wrapper Optional figure/wrapper element.
	 * @return string
	 */
	private function table_anchor( \DOMNode $table, \DOMElement $wrapper = null ) {
		foreach ( $this->table_source_elements( $table, $wrapper ) as $element ) {
			$anchor = $this->element_attribute( $element, 'id' );

			if ( '' !== $anchor ) {
				return $anchor;
			}
		}

		return '';
	}

	/**
	 * Converts an audio or video element into a native media block.
	 *
	 * @param \DOMNode    $node         Media element.
	 * @param string      $block_name   Block name.
	 * @param string|null $caption_html Optional caption HTML.
	 * @param \DOMElement $context      Optional source wrapper element.
	 * @return string
	 */
	private function media_element_to_block( \DOMNode $node, $block_name, $caption_html = null, \DOMElement $context = null ) {
		$block_name = 'audio' === $block_name ? 'audio' : 'video';
		$class_name = 'audio' === $block_name ? 'wp-block-audio' : 'wp-block-video';
		$attributes = $this->media_block_attributes( $node );
		$align      = $this->image_alignment( $context );
		$anchor     = $this->media_anchor( $node, $context );

		if ( '' === $align && $node instanceof \DOMElement ) {
			$align = $this->image_alignment( $node );
		}

		if ( '' !== $align ) {
			$attributes['align'] = $align;
			$class_name         .= ' align' . $align;
		}

		if ( '' !== $anchor ) {
			$attributes['anchor'] = $anchor;
		}

		$figure_attributes = array( 'class' => $class_name );
		if ( '' !== $anchor ) {
			$figure_attributes['id'] = $anchor;
		}

		$media_html = $this->normalize_media_playsinline_attribute_in_html(
			$this->normalize_media_preload_attribute_in_html(
				$this->normalize_media_source_html( $this->remove_id_attribute_from_html( $this->outer_html( $node ), $anchor ) )
			)
		);

		return $this->block_open_comment( $block_name, $attributes ) . "\n"
			. '<figure' . $this->html_attributes( $figure_attributes ) . '>' . $media_html . $this->caption_to_html( $caption_html ) . '</figure>' . "\n"
			. '<!-- /wp:' . $block_name . ' -->';
	}

	/**
	 * Returns the first source anchor that can be represented by an Audio/Video block.
	 *
	 * @param \DOMNode         $node    Media element.
	 * @param \DOMElement|null $context Optional source wrapper element.
	 * @return string
	 */
	private function media_anchor( \DOMNode $node, \DOMElement $context = null ) {
		return $this->source_anchor( $node, $context );
	}

	/**
	 * Returns the first source anchor that can be represented by a native block.
	 *
	 * @param \DOMNode         $node    Source element.
	 * @param \DOMElement|null $context Optional source wrapper element.
	 * @return string
	 */
	private function source_anchor( \DOMNode $node, \DOMElement $context = null ) {
		if ( null !== $context ) {
			$anchor = $this->element_attribute( $context, 'id' );

			if ( '' !== $anchor ) {
				return $anchor;
			}
		}

		return $this->element_attribute( $node, 'id' );
	}

	/**
	 * Returns the first safe source alignment that can be represented by a native block.
	 *
	 * @param \DOMNode         $node    Source element.
	 * @param \DOMElement|null $context Optional source wrapper element.
	 * @return string
	 */
	private function source_alignment( \DOMNode $node, \DOMElement $context = null ) {
		$align = $this->image_alignment( $context );

		if ( '' !== $align ) {
			return $align;
		}

		return $node instanceof \DOMElement ? $this->image_alignment( $node ) : '';
	}

	/**
	 * Returns whether a wrapper is clearly WordPress's rendered classic media shortcode chrome.
	 *
	 * @param \DOMElement $node Wrapper element.
	 * @return bool
	 */
	private function is_legacy_media_wrapper_candidate( \DOMElement $node ) {
		if ( ! in_array( strtolower( $node->nodeName ), array( 'div', 'figure' ), true ) ) {
			return false;
		}

		if ( ! $this->has_legacy_media_wrapper_signal( $node ) ) {
			return false;
		}

		$media = $this->legacy_media_wrapper_media_element( $node );

		if ( null === $media || '' === $this->media_element_source_url( $media ) ) {
			return false;
		}

		foreach ( $node->childNodes as $child ) {
			if ( XML_TEXT_NODE === $child->nodeType && '' === trim( (string) $child->textContent ) ) {
				continue;
			}

			if ( XML_COMMENT_NODE === $child->nodeType ) {
				continue;
			}

			if ( $child instanceof \DOMElement && $child->isSameNode( $media ) ) {
				continue;
			}

			if ( $child instanceof \DOMElement && $this->is_legacy_media_wrapper_caption_element( $child ) ) {
				continue;
			}

			return false;
		}

		return true;
	}

	/**
	 * Returns whether a wrapper has WordPress classic/native media wrapper classes.
	 *
	 * @param \DOMElement $node Wrapper element.
	 * @return bool
	 */
	private function has_legacy_media_wrapper_signal( \DOMElement $node ) {
		return $this->has_any_class_token( $node, array( 'wp-video', 'wp-audio', 'wp-block-video', 'wp-block-audio' ) );
	}

	/**
	 * Returns whether a wrapper is a WordPress widget around legacy media chrome.
	 *
	 * @param \DOMElement $node Wrapper element.
	 * @return bool
	 */
	private function is_legacy_media_widget_wrapper_candidate( \DOMElement $node ) {
		if ( $this->has_legacy_media_wrapper_signal( $node ) ) {
			return false;
		}

		$has_explicit_media_widget_signal = $this->has_any_class_token(
			$node,
			array( 'widget_media_audio', 'widget_media_video' )
		);
		$has_widget_signal                = $has_explicit_media_widget_signal
			|| $this->has_any_class_token( $node, array( 'textwidget', 'widget', 'wp-block-legacy-widget' ) );

		if ( ! $has_widget_signal ) {
			return false;
		}

		foreach ( $node->getElementsByTagName( '*' ) as $element ) {
			if ( ! $element instanceof \DOMElement || $element->isSameNode( $node ) ) {
				continue;
			}

			if ( $this->has_legacy_media_wrapper_signal( $element ) ) {
				return true;
			}

			if (
				$has_explicit_media_widget_signal
				&& in_array( strtolower( $element->nodeName ), array( 'audio', 'video' ), true )
				&& '' !== $this->media_element_source_url( $element )
			) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Returns whether an element is an explicit legacy WordPress widget wrapper.
	 *
	 * @param \DOMElement $node Wrapper element.
	 * @return bool
	 */
	private function is_legacy_widget_wrapper_candidate( \DOMElement $node ) {
		if ( ! in_array( strtolower( $node->nodeName ), array( 'aside', 'div', 'section' ), true ) ) {
			return false;
		}

		if ( $this->has_legacy_media_wrapper_signal( $node ) ) {
			return false;
		}

		if ( $this->is_legacy_accordion_candidate( $node ) || $this->is_tabbed_interface_candidate( $node ) ) {
			return false;
		}

		$has_widget_class = $this->has_any_class_token( $node, array( 'widget', 'wp-block-legacy-widget' ) );
		$has_widget_type  = $this->has_class_token_with_prefix( $node, 'widget_' );

		if ( ! $has_widget_class && ! $has_widget_type ) {
			return false;
		}

		if ( $has_widget_type || $this->has_class_token( $node, 'wp-block-legacy-widget' ) ) {
			return true;
		}

		return null !== $this->first_descendant_by_any_class( $node, array( 'widget-title', 'wp-block-heading' ) );
	}

	/**
	 * Converts a rendered classic WordPress media wrapper into a native media block.
	 *
	 * @param \DOMElement $node Wrapper element.
	 * @return string
	 */
	private function legacy_media_wrapper_to_block( \DOMElement $node ) {
		$media = $this->legacy_media_wrapper_media_element( $node );

		if ( null === $media ) {
			return $this->classic_block( trim( $this->outer_html( $node ) ) );
		}

		return $this->media_element_to_block(
			$media,
			strtolower( $media->nodeName ),
			$this->legacy_media_wrapper_caption_html( $node ),
			$node
		);
	}

	/**
	 * Returns the single media element inside a legacy wrapper.
	 *
	 * @param \DOMElement $node Wrapper element.
	 * @return \DOMElement|null
	 */
	private function legacy_media_wrapper_media_element( \DOMElement $node ) {
		$videos = $node->getElementsByTagName( 'video' );
		$audios = $node->getElementsByTagName( 'audio' );

		if ( 1 === $videos->length && 0 === $audios->length ) {
			return $videos->item( 0 );
		}

		if ( 1 === $audios->length && 0 === $videos->length ) {
			return $audios->item( 0 );
		}

		return null;
	}

	/**
	 * Returns whether an element is an obvious caption for a legacy media wrapper.
	 *
	 * @param \DOMElement $node Possible caption element.
	 * @return bool
	 */
	private function is_legacy_media_wrapper_caption_element( \DOMElement $node ) {
		return 'figcaption' === strtolower( $node->nodeName )
			|| $this->has_any_class_token( $node, array( 'audio-caption', 'caption-text', 'media-caption', 'video-caption', 'wp-caption-text' ) );
	}

	/**
	 * Returns direct caption HTML from a legacy media wrapper.
	 *
	 * @param \DOMElement $node Wrapper element.
	 * @return string|null
	 */
	private function legacy_media_wrapper_caption_html( \DOMElement $node ) {
		foreach ( $node->childNodes as $child ) {
			if ( $child instanceof \DOMElement && $this->is_legacy_media_wrapper_caption_element( $child ) ) {
				$caption = trim( $this->inner_html( $child ) );

				return '' === $caption ? null : $caption;
			}
		}

		return null;
	}

	/**
	 * Converts a figure-wrapped audio or video element into a native media block.
	 *
	 * @param \DOMNode $node       Figure node.
	 * @param string   $block_name Block name.
	 * @return string
	 */
	private function figure_media_to_block( \DOMNode $node, $block_name ) {
		$media = $this->first_descendant_by_tag( $node, $block_name );

		if ( null === $media ) {
			return $this->classic_block( trim( $this->outer_html( $node ) ) );
		}

		if ( $node instanceof \DOMElement && $this->figure_embedded_media_has_unexpected_direct_children( $node, $block_name ) ) {
			return $this->classic_block( trim( $this->outer_html( $node ) ) );
		}

		return $this->media_element_to_block( $media, $block_name, $this->direct_child_inner_html( $node, 'figcaption' ), $node instanceof \DOMElement ? $node : null );
	}

	/**
	 * Returns whether a media figure has direct content that native media/embed conversion would drop.
	 *
	 * @param \DOMElement $node       Figure node.
	 * @param string      $media_tag  Direct media tag expected in the figure.
	 * @return bool
	 */
	private function figure_embedded_media_has_unexpected_direct_children( \DOMElement $node, $media_tag ) {
		$media_tag = strtolower( (string) $media_tag );

		foreach ( $node->childNodes as $child ) {
			if ( XML_TEXT_NODE === $child->nodeType && '' === trim( (string) $child->textContent ) ) {
				continue;
			}

			if ( ! $child instanceof \DOMElement ) {
				continue;
			}

			$tag = strtolower( $child->nodeName );

			if ( in_array( $tag, array( 'br', 'figcaption', $media_tag ), true ) ) {
				continue;
			}

			return true;
		}

		return false;
	}

	/**
	 * Converts an iframe into an Embed block when the provider is known, otherwise Custom HTML.
	 *
	 * @param \DOMNode         $node         Iframe node.
	 * @param string|null      $caption_html Optional caption HTML.
	 * @param \DOMElement|null $context      Optional source wrapper element.
	 * @return string
	 */
	private function iframe_to_block( \DOMNode $node, $caption_html = null, \DOMElement $context = null ) {
		$src      = $this->element_attribute( $node, 'src' );
		$provider = $this->embed_provider_for_url( $src );

		if ( null === $provider ) {
			return $this->custom_html_block( trim( $this->outer_html( $node ) . $this->caption_to_html( $caption_html ) ) );
		}

		$anchor = $this->source_anchor( $node, $context );
		$align  = $this->source_alignment( $node, $context );

		return $this->url_to_embed_block(
			$this->normalized_embed_url( $src, $provider['slug'] ),
			$provider,
			$caption_html,
			$anchor,
			$align
		);
	}

	/**
	 * Converts a known provider URL into an Embed block.
	 *
	 * @param string                         $url          Source URL.
	 * @param array{slug:string,type:string} $provider     Optional provider details.
	 * @param string|null                    $caption_html Optional caption HTML.
	 * @param string                         $anchor       Optional source anchor.
	 * @param string                         $align        Optional source alignment.
	 * @return string
	 */
	private function url_to_embed_block( $url, array $provider = null, $caption_html = null, $anchor = '', $align = '' ) {
		if ( null === $provider ) {
			$provider = $this->embed_provider_for_url( $url );
		}

		if ( null === $provider ) {
			return '<!-- wp:paragraph -->' . "\n"
				. '<p>' . $this->escape_html( $url ) . '</p>' . "\n"
				. '<!-- /wp:paragraph -->';
		}

		$url        = $this->normalized_embed_url( $url, $provider['slug'] );
		$attributes = array(
			'url'              => $url,
			'type'             => $provider['type'],
			'providerNameSlug' => $provider['slug'],
			'responsive'       => true,
		);
		$class_name = 'wp-block-embed is-type-' . $provider['type'] . ' is-provider-' . $provider['slug'] . ' wp-block-embed-' . $provider['slug'];

		if ( '' !== $align ) {
			$attributes['align'] = $align;
			$class_name         .= ' align' . $align;
		}

		if ( '' !== $anchor ) {
			$attributes['anchor'] = $anchor;
		}

		$figure_attrs = array( 'class' => $class_name );
		if ( '' !== $anchor ) {
			$figure_attrs['id'] = $anchor;
		}

		return $this->block_open_comment( 'embed', $attributes ) . "\n"
			. '<figure' . $this->html_attributes( $figure_attrs ) . '>'
			. '<div class="wp-block-embed__wrapper">' . $this->escape_html( $url ) . '</div>'
			. $this->caption_to_html( $caption_html )
			. '</figure>' . "\n"
			. '<!-- /wp:embed -->';
	}

	/**
	 * Converts a paragraph that only contains a known provider URL into an Embed block.
	 *
	 * @param \DOMElement $node Paragraph element.
	 * @return string|null
	 */
	private function paragraph_to_embed_block( \DOMElement $node ) {
		$link = $this->single_direct_child_element_by_tag( $node, 'a' );

		if ( null !== $link ) {
			return $this->standalone_embed_link_to_block( $link, $node );
		}

		foreach ( $node->childNodes as $child ) {
			if ( XML_TEXT_NODE === $child->nodeType || XML_CDATA_SECTION_NODE === $child->nodeType ) {
				continue;
			}

			return null;
		}

		$text = trim( preg_replace( '/\s+/', ' ', (string) $node->textContent ) );

		if ( '' === $text || null === $this->embed_provider_for_url( $text ) ) {
			return null;
		}

		return $this->url_to_embed_block(
			$text,
			null,
			null,
			$this->element_attribute( $node, 'id' ),
			$this->image_alignment( $node )
		);
	}

	/**
	 * Converts a standalone provider URL link into an Embed block when no label would be lost.
	 *
	 * @param \DOMElement      $node    Link element.
	 * @param \DOMElement|null $context Optional source wrapper element.
	 * @return string|null
	 */
	private function standalone_embed_link_to_block( \DOMElement $node, \DOMElement $context = null ) {
		if ( 'a' !== strtolower( $node->nodeName ) || $this->is_button_link_candidate( $node ) || $this->is_file_link_candidate( $node ) ) {
			return null;
		}

		if ( null !== $this->single_direct_child_element_by_tag( $node, 'img' ) ) {
			return null;
		}

		$href     = $this->element_attribute( $node, 'href' );
		$provider = $this->embed_provider_for_url( $href );

		if ( '' === $href || null === $provider ) {
			return null;
		}

		$label      = trim( preg_replace( '/\s+/', ' ', (string) $node->textContent ) );
		$normalized = $this->normalized_embed_url( $href, $provider['slug'] );

		if ( $label !== $href && $label !== $normalized ) {
			return null;
		}

		return $this->url_to_embed_block(
			$normalized,
			$provider,
			null,
			$this->source_anchor( $node, $context ),
			$this->source_alignment( $node, $context )
		);
	}

	/**
	 * Returns whether a link is likely intended to be a button.
	 *
	 * @param \DOMElement $node Link element.
	 * @return bool
	 */
	private function is_button_link_candidate( \DOMElement $node ) {
		if ( '' === $this->element_attribute( $node, 'href' ) ) {
			return false;
		}

		if ( 'button' === strtolower( $this->element_attribute( $node, 'role' ) ) ) {
			return true;
		}

		return $this->has_any_class_token( $node, array( 'button', 'btn', 'wp-block-button__link' ) )
			|| $this->has_class_token_with_prefix( $node, 'btn-' );
	}

	/**
	 * Converts an obvious button-style link into a Buttons/Button block pair.
	 *
	 * @param \DOMElement      $node    Link element.
	 * @param \DOMElement|null $context Optional source wrapper element.
	 * @return string
	 */
	private function link_to_button_block( \DOMElement $node, \DOMElement $context = null ) {
		$href       = $this->element_attribute( $node, 'href' );
		$attributes = array( 'url' => $href );
		$link_attrs = array(
			'class' => 'wp-block-button__link wp-element-button',
			'href'  => $href,
		);
		$anchor     = $this->source_anchor( $node, $context );
		$align      = $this->source_alignment( $node, $context );
		$button_div = array( 'class' => 'wp-block-button' );
		$style      = $this->button_style_class( $node, $context );

		if ( '' !== $anchor ) {
			$attributes['anchor'] = $anchor;
			$button_div['id']     = $anchor;
		}

		if ( '' !== $style ) {
			$attributes['className'] = $style;
			$button_div['class']    .= ' ' . $style;
		}

		foreach (
			array(
				'title' => 'title',
			) as $link_attribute => $block_attribute
		) {
			$value = $this->element_attribute( $node, $link_attribute );

			if ( '' !== $value ) {
				$attributes[ $block_attribute ] = $value;
				$link_attrs[ $link_attribute ]  = $value;
			}
		}

		$target = $this->normalized_reserved_link_target( $node );
		if ( '' !== $target ) {
			$attributes['linkTarget'] = $target;
			$link_attrs['target']     = $target;
		}

		$rel = $this->safe_link_rel( $node );
		if ( '' !== $rel ) {
			$attributes['rel'] = $rel;
			$link_attrs['rel'] = $rel;
		}

		$aria_label = $this->element_attribute( $node, 'aria-label' );
		if ( '' !== $aria_label ) {
			$link_attrs['aria-label'] = $aria_label;
		}

		$buttons_attributes = array();
		$buttons_class      = 'wp-block-buttons';
		if ( in_array( $align, array( 'wide', 'full' ), true ) ) {
			$buttons_attributes['align'] = $align;
			$buttons_class              .= ' align' . $align;
		}

		return $this->block_open_comment( 'buttons', $buttons_attributes ) . "\n"
			. '<div class="' . $buttons_class . '">' . $this->block_open_comment( 'button', $attributes ) . "\n"
			. '<div' . $this->html_attributes( $button_div ) . '><a' . $this->html_attributes( $link_attrs ) . '>' . $this->inner_html( $node ) . '</a></div>' . "\n"
			. '<!-- /wp:button --></div>' . "\n"
			. '<!-- /wp:buttons -->';
	}

	/**
	 * Returns a safe core Button style class from imported button links.
	 *
	 * @param \DOMElement      $node    Link element.
	 * @param \DOMElement|null $context Optional source wrapper element.
	 * @return string
	 */
	private function button_style_class( \DOMElement $node, \DOMElement $context = null ) {
		foreach ( array( 'is-style-outline', 'is-style-fill' ) as $class ) {
			if (
				$this->has_class_token( $node, $class )
				|| ( null !== $context && $this->has_class_token( $context, $class ) )
			) {
				return $class;
			}
		}

		if (
			$this->has_class_token_with_prefix( $node, 'btn-outline-' )
			|| ( null !== $context && $this->has_class_token_with_prefix( $context, 'btn-outline-' ) )
		) {
			return 'is-style-outline';
		}

		return '';
	}

	/**
	 * Returns whether a direct link points to a downloadable document/archive file.
	 *
	 * @param \DOMElement $node Link element.
	 * @return bool
	 */
	private function is_file_link_candidate( \DOMElement $node ) {
		$href = $this->element_attribute( $node, 'href' );

		if ( '' === $href || $this->is_button_link_candidate( $node ) ) {
			return false;
		}

		if ( ! $this->is_file_link_scheme_supported( $href ) ) {
			return false;
		}

		$path      = (string) $this->parse_url_part( $href, PHP_URL_PATH );
		$extension = strtolower( pathinfo( $this->file_name_from_url( $href ), PATHINFO_EXTENSION ) );

		if ( $this->is_supported_file_extension( $extension ) ) {
			return true;
		}

		$download_name = $this->file_supported_download_name( $node );

		if ( '' !== $download_name ) {
			return true;
		}

		if (
			$node->hasAttribute( 'download' )
			&& ( '' !== $path || '' !== (string) $this->parse_url_part( $href, PHP_URL_QUERY ) )
		) {
			return true;
		}

		return '' !== $this->file_extension_from_type( $this->file_link_type( $node ) );
	}

	/**
	 * Returns whether a URL scheme can point to an imported downloadable file.
	 *
	 * @param string $href Link href.
	 * @return bool
	 */
	private function is_file_link_scheme_supported( $href ) {
		$scheme = strtolower( (string) $this->parse_url_part( $href, PHP_URL_SCHEME ) );

		return '' === $scheme || in_array( $scheme, array( 'http', 'https', 'ftp' ), true );
	}

	/**
	 * Returns whether a file extension is suitable for a native File block.
	 *
	 * @param string $extension File extension without the dot.
	 * @return bool
	 */
	private function is_supported_file_extension( $extension ) {
		return in_array(
			strtolower( (string) $extension ),
			array( 'bz2', 'css', 'csv', 'db', 'diff', 'doc', 'docx', 'env', 'epub', 'gz', 'ics', 'ini', 'json', 'jsonl', 'key', 'lock', 'log', 'map', 'markdown', 'md', 'mdown', 'mo', 'ndjson', 'odp', 'ods', 'odt', 'patch', 'pdf', 'po', 'pot', 'ppt', 'pptx', 'properties', 'rtf', 'sql', 'sqlite', 'sqlite3', 'tar', 'tbz', 'tbz2', 'text', 'tgz', 'toml', 'txt', 'webmanifest', 'wxr', 'xls', 'xlsx', 'xml', 'xz', 'yaml', 'yml', 'zip', 'zst' ),
			true
		);
	}

	/**
	 * Converts an obvious downloadable file link into a File block.
	 *
	 * @param \DOMElement      $node    Link element.
	 * @param \DOMElement|null $context Optional source wrapper element.
	 * @return string
	 */
	private function link_to_file_block( \DOMElement $node, \DOMElement $context = null ) {
		$href       = $this->element_attribute( $node, 'href' );
		$file_name  = $this->file_name_from_url( $href );
		$file_name  = $this->file_block_file_name( $file_name, $node );
		$attributes = array(
			'href'         => $href,
			'textLinkHref' => $href,
			'fileName'     => $file_name,
		);
		$link_attrs = array( 'href' => $href );
		$anchor     = $this->source_anchor( $node, $context );
		$align      = $this->source_alignment( $node, $context );
		$div_attrs  = array( 'class' => 'wp-block-file' );

		if ( '' !== $align ) {
			$attributes['align'] = $align;
			$div_attrs['class'] .= ' align' . $align;
		}

		if ( '' !== $anchor ) {
			$attributes['anchor'] = $anchor;
			$div_attrs['id']      = $anchor;
		}

		$target = $this->normalized_reserved_link_target( $node );
		if ( '' !== $target ) {
			$attributes['textLinkTarget'] = $target;
			$link_attrs['target']         = $target;
		}

		$rel = $this->safe_link_rel( $node );
		if ( '' !== $rel ) {
			$link_attrs['rel'] = $rel;
		}

		$title = $this->element_attribute( $node, 'title' );
		if ( '' !== $title ) {
			$link_attrs['title'] = $title;
		}

		$aria_label = $this->element_attribute( $node, 'aria-label' );
		if ( '' !== $aria_label ) {
			$link_attrs['aria-label'] = $aria_label;
		}

		$type = $this->file_link_type( $node );
		if ( '' !== $type ) {
			$link_attrs['type'] = $type;
		}

		$hreflang = $this->file_link_hreflang( $node );
		if ( '' !== $hreflang ) {
			$link_attrs['hreflang'] = $hreflang;
		}

		$referrer_policy = $this->file_link_referrer_policy( $node );
		if ( '' !== $referrer_policy ) {
			$link_attrs['referrerpolicy'] = $referrer_policy;
		}

		$download_name      = $this->file_supported_download_name( $node );
		$download_attribute = '' === $download_name ? ' download' : ' download="' . $this->escape_html( $download_name ) . '"';

		return $this->block_open_comment( 'file', $attributes ) . "\n"
			. '<div' . $this->html_attributes( $div_attrs ) . '><a' . $this->html_attributes( $link_attrs ) . '>' . $this->inner_html( $node ) . '</a>'
			. '<a href="' . $this->escape_html( $href ) . '" class="wp-block-file__button wp-element-button"' . $download_attribute . '>Download</a></div>' . "\n"
			. '<!-- /wp:file -->';
	}

	/**
	 * Returns the best native File block filename from URL, download, or MIME hints.
	 *
	 * @param string      $url_name Basename derived from the href path.
	 * @param \DOMElement $node     Link element.
	 * @return string
	 */
	private function file_block_file_name( $url_name, \DOMElement $node ) {
		$url_extension = pathinfo( $url_name, PATHINFO_EXTENSION );

		if ( '' !== $url_extension && $this->is_supported_file_extension( $url_extension ) ) {
			return $url_name;
		}

		$download_name = $this->file_supported_download_name( $node );
		if ( '' !== $download_name ) {
			return $download_name;
		}

		$extension = $this->file_extension_from_type( $this->file_link_type( $node ) );
		if ( '' === $extension ) {
			return '' === $url_name && $node->hasAttribute( 'download' ) ? 'download' : $url_name;
		}

		$base           = '' === $url_name ? 'download' : $url_name;
		$base_extension = pathinfo( $base, PATHINFO_EXTENSION );

		if ( '' !== $base_extension && ! $this->is_supported_file_extension( $base_extension ) ) {
			$base_name = pathinfo( $base, PATHINFO_FILENAME );
			$base      = '' === $base_name ? 'download' : $base_name;
		}

		return $base . '.' . $extension;
	}

	/**
	 * Returns a safe imported download filename when its extension is supported.
	 *
	 * @param \DOMElement $node Link element.
	 * @return string
	 */
	private function file_supported_download_name( \DOMElement $node ) {
		$download_name = $this->file_download_name( $node );

		if ( '' === $download_name ) {
			return '';
		}

		return $this->is_supported_file_extension( pathinfo( $download_name, PATHINFO_EXTENSION ) ) ? $download_name : '';
	}

	/**
	 * Returns a safe filename from an imported download attribute.
	 *
	 * @param \DOMElement $node Link element.
	 * @return string
	 */
	private function file_download_name( \DOMElement $node ) {
		if ( ! $node->hasAttribute( 'download' ) ) {
			return '';
		}

		$download = str_replace( '\\', '/', $this->element_attribute( $node, 'download' ) );
		$download = basename( $download );

		if ( preg_match( '/[<>:"|?*\x00-\x1F]+/', $download ) ) {
			return '';
		}

		$download = trim( rawurldecode( $download ) );
		$download = preg_replace( '/[\/\\\\<>:"|?*\x00-\x1F]+/', '-', $download );
		$download = trim( $download, ".- \t\n\r\0\x0B" );

		return preg_match( '/^[^\/\\\\<>:"|?*\x00-\x1F]+$/', $download ) ? $download : '';
	}

	/**
	 * Returns a safe imported MIME type for File block text links.
	 *
	 * @param \DOMElement $node Link element.
	 * @return string
	 */
	private function file_link_type( \DOMElement $node ) {
		$type  = strtolower( $this->element_attribute( $node, 'type' ) );
		$parts = explode( ';', $type, 2 );
		$type  = trim( $parts[0] );

		if ( ! preg_match( '/^[a-z0-9.+_-]+\/[a-z0-9.+_-]+$/', $type ) ) {
			return '';
		}

		return '' === $this->file_extension_from_type( $type ) ? '' : $type;
	}

	/**
	 * Returns a supported file extension implied by a safe MIME type.
	 *
	 * @param string $type MIME type.
	 * @return string
	 */
	private function file_extension_from_type( $type ) {
		$extensions = array(
			'application/acrobat'                     => 'pdf',
			'application/atom+xml'                    => 'xml',
			'application/csv'                         => 'csv',
			'application/env'                         => 'env',
			'application/excel'                       => 'xls',
			'application/diff'                        => 'diff',
			'application/epub+zip'                    => 'epub',
			'application/gzip'                        => 'gz',
			'application/ics'                         => 'ics',
			'application/ini'                         => 'ini',
			'application/json'                        => 'json',
			'application/lock'                        => 'lock',
			'application/manifest+json'               => 'webmanifest',
			'application/ndjson'                      => 'ndjson',
			'application/patch'                       => 'patch',
			'application/toml'                        => 'toml',
			'application/yaml'                        => 'yaml',
			'application/msword'                      => 'doc',
			'application/msexcel'                     => 'xls',
			'application/pdf'                         => 'pdf',
			'application/mspowerpoint'                => 'ppt',
			'application/powerpoint'                  => 'ppt',
			'application/properties'                  => 'properties',
			'application/rdf+xml'                     => 'xml',
			'application/rss+xml'                     => 'xml',
			'application/rtf'                         => 'rtf',
			'application/source-map'                  => 'map',
			'application/sql'                         => 'sql',
			'application/zstd'                        => 'zst',
			'application/xml'                         => 'xml',
			'application/vnd.apple.keynote'           => 'key',
			'application/vnd.ms-excel'                => 'xls',
			'application/vnd.ms-powerpoint'           => 'ppt',
			'application/vnd.oasis.opendocument.presentation' => 'odp',
			'application/vnd.oasis.opendocument.spreadsheet' => 'ods',
			'application/vnd.oasis.opendocument.text' => 'odt',
			'application/vnd.openxmlformats-officedocument.presentationml.presentation' => 'pptx',
			'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
			'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
			'application/vnd.sqlite3'                 => 'sqlite3',
			'application/word'                        => 'doc',
			'application/x-bzip2'                     => 'bz2',
			'application/x-diff'                      => 'diff',
			'application/x-env'                       => 'env',
			'application/x-excel'                     => 'xls',
			'application/x-gzip'                      => 'gz',
			'application/x-gettext'                   => 'mo',
			'application/x-ini'                       => 'ini',
			'application/x-lock'                      => 'lock',
			'application/x-ndjson'                    => 'ndjson',
			'application/x-ms-excel'                  => 'xls',
			'application/x-msexcel'                   => 'xls',
			'application/x-msword'                    => 'doc',
			'application/x-mspowerpoint'              => 'ppt',
			'application/x-epub+zip'                  => 'epub',
			'application/x-pdf'                       => 'pdf',
			'application/x-java-properties'           => 'properties',
			'application/x-patch'                     => 'patch',
			'application/x-properties'                => 'properties',
			'application/x-rtf'                       => 'rtf',
			'application/x-sql'                       => 'sql',
			'application/x-sqlite'                    => 'sqlite',
			'application/x-sqlite3'                   => 'sqlite3',
			'application/x-source-map'                => 'map',
			'application/x-tar'                       => 'tar',
			'application/x-xz'                        => 'xz',
			'application/x-yaml'                      => 'yaml',
			'application/x-zip'                       => 'zip',
			'application/x-zip-compressed'            => 'zip',
			'application/x-zstd'                      => 'zst',
			'application/zip'                         => 'zip',
			'text/calendar'                           => 'ics',
			'text/css'                                => 'css',
			'text/csv'                                => 'csv',
			'text/diff'                               => 'diff',
			'text/env'                                => 'env',
			'text/markdown'                           => 'md',
			'text/patch'                              => 'patch',
			'text/properties'                         => 'properties',
			'text/richtext'                           => 'rtf',
			'text/ini'                                => 'ini',
			'text/lock'                               => 'lock',
			'text/x-csv'                              => 'csv',
			'text/x-diff'                             => 'diff',
			'text/x-env'                              => 'env',
			'text/x-gettext-translation'              => 'po',
			'text/x-gettext-translation-template'     => 'pot',
			'text/x-ini'                              => 'ini',
			'text/x-java-properties'                  => 'properties',
			'text/x-lock'                             => 'lock',
			'text/x-log'                              => 'log',
			'text/x-markdown'                         => 'md',
			'text/x-ndjson'                           => 'ndjson',
			'text/x-patch'                            => 'patch',
			'text/x-properties'                       => 'properties',
			'text/x-sql'                              => 'sql',
			'text/x-source-map'                       => 'map',
			'text/x-toml'                             => 'toml',
			'text/x-yaml'                             => 'yaml',
			'text/toml'                               => 'toml',
			'text/xml'                                => 'xml',
			'text/yaml'                               => 'yaml',
			'text/plain'                              => 'txt',
			'text/rtf'                                => 'rtf',
		);

		return isset( $extensions[ $type ] ) ? $extensions[ $type ] : '';
	}

	/**
	 * Returns a safe imported hreflang value for File block text links.
	 *
	 * @param \DOMElement $node Link element.
	 * @return string
	 */
	private function file_link_hreflang( \DOMElement $node ) {
		$hreflang = strtolower( $this->element_attribute( $node, 'hreflang' ) );

		return preg_match( '/^[a-z]{2,3}(?:-[a-z0-9]{2,8})*$/', $hreflang ) ? $hreflang : '';
	}

	/**
	 * Returns safe imported rel tokens for native link metadata.
	 *
	 * @param \DOMElement $node Link element.
	 * @return string
	 */
	private function safe_link_rel( \DOMElement $node ) {
		$tokens = preg_split( '/\s+/', strtolower( $this->element_attribute( $node, 'rel' ) ) );
		$safe   = array();

		foreach ( is_array( $tokens ) ? $tokens : array() as $token ) {
			if ( '' !== $token && preg_match( '/^[a-z0-9_-]+$/', $token ) ) {
				$safe[ $token ] = true;
			}
		}

		return implode( ' ', array_keys( $safe ) );
	}

	/**
	 * Returns a canonical reserved link target value.
	 *
	 * @param \DOMElement $node Link element.
	 * @return string
	 */
	private function normalized_reserved_link_target( \DOMElement $node ) {
		$target = strtolower( $this->element_attribute( $node, 'target' ) );

		return in_array( $target, array( '_blank', '_self', '_parent', '_top' ), true ) ? $target : '';
	}

	/**
	 * Returns a safe imported referrer policy for File block text links.
	 *
	 * @param \DOMElement $node Link element.
	 * @return string
	 */
	private function file_link_referrer_policy( \DOMElement $node ) {
		$policy = strtolower( $this->element_attribute( $node, 'referrerpolicy' ) );

		return in_array(
			$policy,
			array(
				'no-referrer',
				'no-referrer-when-downgrade',
				'origin',
				'origin-when-cross-origin',
				'same-origin',
				'strict-origin',
				'strict-origin-when-cross-origin',
				'unsafe-url',
			),
			true
		) ? $policy : '';
	}

	/**
	 * Converts a figure-wrapped iframe into an embed or custom HTML block.
	 *
	 * @param \DOMNode $node Figure node.
	 * @return string
	 */
	private function figure_iframe_to_block( \DOMNode $node ) {
		$iframe = $this->first_descendant_by_tag( $node, 'iframe' );

		if ( null === $iframe ) {
			return $this->classic_block( trim( $this->outer_html( $node ) ) );
		}

		if ( $node instanceof \DOMElement && $this->figure_embedded_media_has_unexpected_direct_children( $node, 'iframe' ) ) {
			return $this->classic_block( trim( $this->outer_html( $node ) ) );
		}

		return $this->iframe_to_block( $iframe, $this->direct_child_inner_html( $node, 'figcaption' ), $node instanceof \DOMElement ? $node : null );
	}

	/**
	 * Returns whether a form can be represented as a native Search block.
	 *
	 * @param \DOMElement $node Form element.
	 * @return bool
	 */
	private function is_search_form_candidate( \DOMElement $node ) {
		if ( 'form' !== strtolower( $node->nodeName ) ) {
			return false;
		}

		$method = strtolower( $this->element_attribute( $node, 'method' ) );
		if ( '' !== $method && 'get' !== $method ) {
			return false;
		}

		$query_input = $this->search_form_query_input( $node );
		if ( null === $query_input ) {
			return false;
		}

		if ( ! $this->has_search_form_signal( $node, $query_input ) ) {
			return false;
		}

		return $this->search_form_controls_are_supported( $node, $query_input );
	}

	/**
	 * Converts a search form into a native Search block.
	 *
	 * @param \DOMElement $node Search form.
	 * @return string
	 */
	private function search_form_to_block( \DOMElement $node ) {
		$input = $this->search_form_query_input( $node );

		if ( null === $input ) {
			return $this->custom_html_block( trim( $this->outer_html( $node ) ) );
		}

		$label_info  = $this->search_form_label_info( $node, $input );
		$attributes  = array(
			'label'      => $label_info['label'],
			'buttonText' => $this->search_form_button_text( $node ),
		);
		$placeholder = $this->element_attribute( $input, 'placeholder' );
		$anchor      = $this->element_attribute( $node, 'id' );

		if ( '' !== $placeholder ) {
			$attributes['placeholder'] = $placeholder;
		}

		if ( ! $label_info['visible'] ) {
			$attributes['showLabel'] = false;
		}

		if ( $this->search_form_button_uses_icon( $node ) ) {
			$attributes['buttonUseIcon'] = true;
		}

		if ( '' !== $anchor ) {
			$attributes['anchor'] = $anchor;
		}

		$align = $this->horizontal_alignment( $node );
		if ( '' !== $align ) {
			$attributes['align'] = $align;
		}

		return '<!-- wp:search ' . $this->encode_block_attributes( $attributes ) . ' /-->';
	}

	/**
	 * Finds the single query input in a search form.
	 *
	 * @param \DOMElement $form Form element.
	 * @return \DOMElement|null
	 */
	private function search_form_query_input( \DOMElement $form ) {
		$candidate = null;

		foreach ( $form->getElementsByTagName( 'input' ) as $input ) {
			if ( ! $this->is_search_form_query_input_candidate( $input ) ) {
				continue;
			}

			if ( null !== $candidate ) {
				return null;
			}

			$candidate = $input;
		}

		return $candidate;
	}

	/**
	 * Returns whether an input is the search query input.
	 *
	 * @param \DOMElement $input Input element.
	 * @return bool
	 */
	private function is_search_form_query_input_candidate( \DOMElement $input ) {
		$type = strtolower( $this->element_attribute( $input, 'type' ) );
		$name = strtolower( $this->element_attribute( $input, 'name' ) );

		if ( '' === $type ) {
			$type = 'text';
		}

		if ( 'search' === $type ) {
			return true;
		}

		return in_array( $type, array( 'text', 'search' ), true )
			&& in_array( $name, array( 's', 'q', 'query', 'search' ), true );
	}

	/**
	 * Returns whether the form has explicit search semantics.
	 *
	 * @param \DOMElement $form        Form element.
	 * @param \DOMElement $query_input Query input.
	 * @return bool
	 */
	private function has_search_form_signal( \DOMElement $form, \DOMElement $query_input ) {
		if ( 'search' === strtolower( $this->element_attribute( $form, 'role' ) ) ) {
			return true;
		}

		if (
			$this->has_any_class_token( $form, array( 'search', 'search-form', 'wp-block-search' ) )
			|| $this->has_class_token_with_prefix( $form, 'search-' )
		) {
			return true;
		}

		$label = strtolower( $this->element_attribute( $form, 'aria-label' ) . ' ' . $this->element_attribute( $form, 'title' ) );
		if ( false !== strpos( $label, 'search' ) ) {
			return true;
		}

		return 'search' === strtolower( $this->element_attribute( $query_input, 'type' ) );
	}

	/**
	 * Returns whether a search form only uses controls represented by core/search.
	 *
	 * @param \DOMElement $form        Form element.
	 * @param \DOMElement $query_input Query input.
	 * @return bool
	 */
	private function search_form_controls_are_supported( \DOMElement $form, \DOMElement $query_input ) {
		if ( 0 < $form->getElementsByTagName( 'textarea' )->length ) {
			return false;
		}

		if ( 0 < $form->getElementsByTagName( 'select' )->length ) {
			return false;
		}

		foreach ( $form->getElementsByTagName( 'input' ) as $input ) {
			if ( $input->isSameNode( $query_input ) ) {
				continue;
			}

			$type = strtolower( $this->element_attribute( $input, 'type' ) );
			if ( '' === $type ) {
				$type = 'text';
			}

			if ( in_array( $type, array( 'button', 'image', 'reset', 'submit' ), true ) ) {
				continue;
			}

			return false;
		}

		foreach ( $form->getElementsByTagName( 'button' ) as $button ) {
			$type = strtolower( $this->element_attribute( $button, 'type' ) );

			if ( '' !== $type && 'submit' !== $type ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Returns label text plus whether it was visible in the source form.
	 *
	 * @param \DOMElement $form  Form element.
	 * @param \DOMElement $input Query input.
	 * @return array{label:string,visible:bool}
	 */
	private function search_form_label_info( \DOMElement $form, \DOMElement $input ) {
		$label = $this->search_form_label_element( $form, $input );

		if ( null !== $label ) {
			$text = trim( preg_replace( '/\s+/', ' ', (string) $label->textContent ) );

			if ( '' !== $text ) {
				return array(
					'label'   => $text,
					'visible' => ! $this->search_form_label_is_hidden( $label ),
				);
			}
		}

		foreach (
			array(
				$this->element_attribute( $input, 'aria-label' ),
				$this->element_attribute( $input, 'title' ),
				$this->element_attribute( $form, 'aria-label' ),
				$this->element_attribute( $form, 'title' ),
			) as $label_text
		) {
			if ( '' !== $label_text ) {
				return array(
					'label'   => $label_text,
					'visible' => false,
				);
			}
		}

		return array(
			'label'   => 'Search',
			'visible' => false,
		);
	}

	/**
	 * Finds a label element associated with a query input.
	 *
	 * @param \DOMElement $form  Form element.
	 * @param \DOMElement $input Query input.
	 * @return \DOMElement|null
	 */
	private function search_form_label_element( \DOMElement $form, \DOMElement $input ) {
		$input_id = $this->element_attribute( $input, 'id' );

		if ( '' !== $input_id ) {
			foreach ( $form->getElementsByTagName( 'label' ) as $label ) {
				if ( $this->element_attribute( $label, 'for' ) === $input_id ) {
					return $label;
				}
			}
		}

		$node = $input->parentNode;
		while ( $node instanceof \DOMElement && ! $node->isSameNode( $form ) ) {
			if ( 'label' === strtolower( $node->nodeName ) ) {
				return $node;
			}

			$node = $node->parentNode;
		}

		return null;
	}

	/**
	 * Returns whether a label was visually hidden in common imported markup.
	 *
	 * @param \DOMElement $label Label element.
	 * @return bool
	 */
	private function search_form_label_is_hidden( \DOMElement $label ) {
		if (
			$this->has_any_class_token(
				$label,
				array( 'screen-reader-text', 'sr-only', 'visually-hidden', 'visuallyhidden' )
			)
		) {
			return true;
		}

		if ( $label->hasAttribute( 'hidden' ) || 'true' === strtolower( $this->element_attribute( $label, 'aria-hidden' ) ) ) {
			return true;
		}

		$style = strtolower( preg_replace( '/\s+/', '', $this->element_attribute( $label, 'style' ) ) );

		return false !== strpos( $style, 'display:none' ) || false !== strpos( $style, 'visibility:hidden' );
	}

	/**
	 * Returns the submit button text for a Search block.
	 *
	 * @param \DOMElement $form Search form.
	 * @return string
	 */
	private function search_form_button_text( \DOMElement $form ) {
		foreach ( $form->getElementsByTagName( 'button' ) as $button ) {
			$text = trim( preg_replace( '/\s+/', ' ', (string) $button->textContent ) );

			if ( '' !== $text ) {
				return $text;
			}

			foreach ( array( 'aria-label', 'title' ) as $attribute ) {
				$text = $this->element_attribute( $button, $attribute );

				if ( '' !== $text ) {
					return $text;
				}
			}
		}

		foreach ( $form->getElementsByTagName( 'input' ) as $input ) {
			if ( 'submit' !== strtolower( $this->element_attribute( $input, 'type' ) ) ) {
				continue;
			}

			foreach ( array( 'value', 'aria-label', 'title' ) as $attribute ) {
				$value = $this->element_attribute( $input, $attribute );

				if ( '' !== $value ) {
					return $value;
				}
			}
		}

		foreach ( $form->getElementsByTagName( 'input' ) as $input ) {
			if ( 'image' !== strtolower( $this->element_attribute( $input, 'type' ) ) ) {
				continue;
			}

			foreach ( array( 'alt', 'aria-label', 'title', 'value' ) as $attribute ) {
				$text = $this->element_attribute( $input, $attribute );

				if ( '' !== $text ) {
					return $text;
				}
			}
		}

		return 'Search';
	}

	/**
	 * Returns whether the imported submit button was icon-oriented.
	 *
	 * @param \DOMElement $form Search form.
	 * @return bool
	 */
	private function search_form_button_uses_icon( \DOMElement $form ) {
		foreach ( $form->getElementsByTagName( 'button' ) as $button ) {
			if (
				$this->has_any_class_token( $button, array( 'icon', 'icon-search', 'search-icon' ) )
				|| $this->has_class_token_with_prefix( $button, 'icon-' )
				|| 0 < $button->getElementsByTagName( 'svg' )->length
			) {
				return true;
			}
		}

		foreach ( $form->getElementsByTagName( 'input' ) as $input ) {
			if ( 'image' === strtolower( $this->element_attribute( $input, 'type' ) ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Converts a details/summary disclosure into a native Details block.
	 *
	 * @param \DOMElement $node Details element.
	 * @return string
	 */
	private function details_to_block( \DOMElement $node ) {
		$summary_html = null;
		$body_blocks  = array();

		foreach ( $node->childNodes as $child ) {
			if ( $child instanceof \DOMElement && 'summary' === strtolower( $child->nodeName ) && null === $summary_html ) {
				$summary_html = trim( $this->inner_html( $child ) );
				continue;
			}

			foreach ( $this->node_to_blocks( $child ) as $block ) {
				if ( '' !== $block ) {
					$body_blocks[] = $block;
				}
			}
		}

		$summary_html = null === $summary_html || '' === $summary_html ? 'Details' : $summary_html;
		$body_html    = empty( $body_blocks ) ? '' : "\n" . implode( "\n\n", $body_blocks );

		return $this->block_open_comment( 'details', $this->details_block_attributes( $node ) ) . "\n"
			. '<details' . $this->details_html_attributes( $node ) . '><summary>' . $summary_html . '</summary>' . $body_html . '</details>' . "\n"
			. '<!-- /wp:details -->';
	}

	/**
	 * Builds Details block attributes from a details element.
	 *
	 * @param \DOMElement $node Details element.
	 * @return array<string,mixed>
	 */
	private function details_block_attributes( \DOMElement $node ) {
		$attributes = array();

		if ( $this->details_is_open( $node ) ) {
			$attributes['showContent'] = true;
		}

		$anchor = $this->element_attribute( $node, 'id' );
		if ( '' !== $anchor ) {
			$attributes['anchor'] = $anchor;
		}

		$align = $this->wide_or_full_alignment( $node );
		if ( '' !== $align ) {
			$attributes['align'] = $align;
		}

		return $attributes;
	}

	/**
	 * Builds the saved details element attributes for a Details block.
	 *
	 * @param \DOMElement $node Details element.
	 * @return string
	 */
	private function details_html_attributes( \DOMElement $node ) {
		$attributes = array( 'class' => 'wp-block-details' );
		$align      = $this->wide_or_full_alignment( $node );

		if ( '' !== $align ) {
			$attributes['class'] .= ' align' . $align;
		}

		foreach ( array( 'id', 'name' ) as $name ) {
			$value = $this->element_attribute( $node, $name );

			if ( '' !== $value ) {
				$attributes[ $name ] = $value;
			}
		}

		$html = $this->html_attributes( $attributes );

		if ( $this->details_is_open( $node ) ) {
			$html .= ' open';
		}

		return $html;
	}

	/**
	 * Returns whether a Details disclosure should start open.
	 *
	 * @param \DOMElement $node Details element.
	 * @return bool
	 */
	private function details_is_open( \DOMElement $node ) {
		return $node->hasAttribute( 'open' )
			|| $this->has_disclosure_open_class( $node )
			|| $this->element_has_expanded_state( $node );
	}

	/**
	 * Returns whether a definition list is clearly an FAQ/Q&A list.
	 *
	 * @param \DOMElement $node Definition list element.
	 * @return bool
	 */
	private function is_faq_definition_list_candidate( \DOMElement $node ) {
		if ( 'dl' !== strtolower( $node->nodeName ) || empty( $this->definition_list_pairs( $node ) ) ) {
			return false;
		}

		if (
			$this->has_any_class_token( $node, array( 'faq', 'faqs', 'faq-list', 'qa', 'q-a', 'q-and-a', 'qna', 'questions', 'question-list' ) )
			|| $this->has_class_token_with_prefix( $node, 'faq-' )
			|| $this->has_class_token_with_prefix( $node, 'qa-' )
			|| false !== stripos( $this->element_attribute( $node, 'itemtype' ), 'FAQPage' )
		) {
			return true;
		}

		foreach ( $this->definition_list_pairs( $node ) as $pair ) {
			$question = trim( (string) $pair['term']->textContent );

			if ( '' !== $question && '?' === substr( $question, -1 ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Converts an obvious FAQ/Q&A definition list into Details blocks.
	 *
	 * @param \DOMElement $node Definition list element.
	 * @return array<int,string>
	 */
	private function definition_list_to_details_blocks( \DOMElement $node ) {
		$blocks = array();

		foreach ( $this->definition_list_pairs( $node ) as $pair ) {
			$body_blocks = $this->definition_description_blocks( $pair['definitions'] );

			if ( '' === trim( $this->inner_html( $pair['term'] ) ) || empty( $body_blocks ) || $this->contains_classic_block( $body_blocks ) ) {
				return array( $this->classic_block( trim( $this->outer_html( $node ) ) ) );
			}

			$blocks[] = $this->definition_pair_to_details_block( $node, $pair['term'], $pair['definitions'], $body_blocks );
		}

		return empty( $blocks ) ? array( $this->classic_block( trim( $this->outer_html( $node ) ) ) ) : $blocks;
	}

	/**
	 * Builds Details block body content from one or more dd elements.
	 *
	 * @param array<int,\DOMElement> $definitions Definition description elements.
	 * @return array<int,string>
	 */
	private function definition_description_blocks( array $definitions ) {
		$blocks = array();

		foreach ( $definitions as $definition ) {
			if ( $this->node_has_only_inline_children( $definition ) ) {
				$inline_html = trim( $this->inner_html( $definition ) );

				if ( '' !== $inline_html && $this->has_visible_inline_content( $inline_html ) ) {
					$blocks[] = '<!-- wp:paragraph -->' . "\n"
						. '<p>' . $inline_html . '</p>' . "\n"
						. '<!-- /wp:paragraph -->';
				}

				continue;
			}

			foreach ( $this->child_nodes_to_blocks( $definition ) as $block ) {
				if ( '' !== $block ) {
					$blocks[] = $block;
				}
			}
		}

		return $blocks;
	}

	/**
	 * Converts one definition-list pair into a Details block.
	 *
	 * @param \DOMElement            $source_list Source definition list.
	 * @param \DOMElement            $term        Definition term element.
	 * @param array<int,\DOMElement> $definitions Definition description elements.
	 * @param array<int,string>      $body_blocks Nested block markup.
	 * @return string
	 */
	private function definition_pair_to_details_block( \DOMElement $source_list, \DOMElement $term, array $definitions, array $body_blocks ) {
		$attributes = array();
		$html_attrs = array( 'class' => 'wp-block-details' );
		$anchor     = $this->element_attribute( $term, 'id' );

		if ( '' === $anchor && ! empty( $definitions ) ) {
			$anchor = $this->element_attribute( $definitions[0], 'id' );
		}

		if ( '' !== $anchor ) {
			$attributes['anchor'] = $anchor;
			$html_attrs['id']     = $anchor;
		}

		if ( $this->definition_pair_is_open( $term, $definitions ) ) {
			$attributes['showContent'] = true;
		}

		$align = $this->definition_pair_alignment( $source_list, $term, $definitions );
		if ( '' !== $align ) {
			$attributes['align']  = $align;
			$html_attrs['class'] .= ' align' . $align;
		}

		$body_html = empty( $body_blocks ) ? '' : "\n" . implode( "\n\n", $body_blocks );

		return $this->block_open_comment( 'details', $attributes ) . "\n"
			. '<details' . $this->html_attributes( $html_attrs ) . $this->definition_pair_open_attribute( $term, $definitions ) . '><summary>' . trim( $this->inner_html( $term ) ) . '</summary>'
			. $body_html . '</details>' . "\n"
			. '<!-- /wp:details -->';
	}

	/**
	 * Returns safe wide/full alignment for a converted FAQ definition pair.
	 *
	 * @param \DOMElement            $source_list Source definition list.
	 * @param \DOMElement            $term        Definition term.
	 * @param array<int,\DOMElement> $definitions Definition descriptions.
	 * @return string
	 */
	private function definition_pair_alignment( \DOMElement $source_list, \DOMElement $term, array $definitions ) {
		foreach ( array( $term, isset( $definitions[0] ) ? $definitions[0] : null, $source_list ) as $element ) {
			if ( ! $element instanceof \DOMElement ) {
				continue;
			}

			$align = $this->wide_or_full_alignment( $element );
			if ( '' !== $align ) {
				return $align;
			}
		}

		return '';
	}

	/**
	 * Returns well-formed dt/dd pairs from a definition list.
	 *
	 * @param \DOMElement $node Definition list element.
	 * @return array<int,array{term:\DOMElement,definitions:array<int,\DOMElement>}>
	 */
	private function definition_list_pairs( \DOMElement $node ) {
		$pairs               = array();
		$current_term        = null;
		$current_definitions = array();

		foreach ( $node->childNodes as $child ) {
			if ( XML_TEXT_NODE === $child->nodeType && '' === trim( (string) $child->textContent ) ) {
				continue;
			}

			if ( $child instanceof \DOMElement && 'br' === strtolower( $child->nodeName ) ) {
				continue;
			}

			if ( ! $child instanceof \DOMElement ) {
				return array();
			}

			$name = strtolower( $child->nodeName );

			if ( 'dt' === $name ) {
				if ( null !== $current_term ) {
					if ( empty( $current_definitions ) ) {
						return array();
					}

					$pairs[] = array(
						'term'        => $current_term,
						'definitions' => $current_definitions,
					);
				}

				$current_term        = $child;
				$current_definitions = array();
				continue;
			}

			if ( 'dd' === $name && null !== $current_term ) {
				$current_definitions[] = $child;
				continue;
			}

			return array();
		}

		if ( null === $current_term || empty( $current_definitions ) ) {
			return array();
		}

		$pairs[] = array(
			'term'        => $current_term,
			'definitions' => $current_definitions,
		);

		return $pairs;
	}

	/**
	 * Returns whether a definition pair should start open.
	 *
	 * @param \DOMElement            $term        Definition term.
	 * @param array<int,\DOMElement> $definitions Definition descriptions.
	 * @return bool
	 */
	private function definition_pair_is_open( \DOMElement $term, array $definitions ) {
		if ( $this->has_disclosure_open_class( $term ) ) {
			return true;
		}

		if (
			$this->element_has_expanded_state( $term )
		) {
			return true;
		}

		foreach ( $definitions as $definition ) {
			if ( $this->has_disclosure_open_class( $definition ) ) {
				return true;
			}

			if (
				$this->element_has_expanded_state( $definition )
			) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Builds the saved open attribute for a definition-list Details block.
	 *
	 * @param \DOMElement            $term        Definition term.
	 * @param array<int,\DOMElement> $definitions Definition descriptions.
	 * @return string
	 */
	private function definition_pair_open_attribute( \DOMElement $term, array $definitions ) {
		return $this->definition_pair_is_open( $term, $definitions ) ? ' open' : '';
	}

	/**
	 * Returns whether a node's meaningful children can stay inside one paragraph.
	 *
	 * @param \DOMNode $node DOM node.
	 * @return bool
	 */
	private function node_has_only_inline_children( \DOMNode $node ) {
		foreach ( $node->childNodes as $child ) {
			if ( XML_TEXT_NODE === $child->nodeType || XML_CDATA_SECTION_NODE === $child->nodeType ) {
				continue;
			}

			if ( $child instanceof \DOMElement && $this->is_inline_paragraph_element( $child ) ) {
				continue;
			}

			return false;
		}

		return true;
	}

	/**
	 * Returns whether a node is an obvious legacy accordion wrapper.
	 *
	 * @param \DOMNode $node DOM node.
	 * @return bool
	 */
	private function is_legacy_accordion_candidate( \DOMNode $node ) {
		if ( ! $node instanceof \DOMElement ) {
			return false;
		}

		if ( ! $this->has_any_class_token( $node, array( 'accordion', 'panel-group' ) ) ) {
			return false;
		}

		return ! empty( $this->direct_accordion_panels( $node ) );
	}

	/**
	 * Converts an obvious legacy accordion wrapper into native Details blocks.
	 *
	 * @param \DOMNode $node Accordion wrapper.
	 * @return array<int,string>
	 */
	private function legacy_accordion_to_blocks( \DOMNode $node ) {
		if ( ! $node instanceof \DOMElement ) {
			return array( $this->classic_block( trim( $this->outer_html( $node ) ) ) );
		}

		$blocks = array();

		foreach ( $this->direct_accordion_panels( $node ) as $panel ) {
			$title_html = $this->accordion_panel_title_html( $panel );
			$body       = $this->accordion_panel_body_element( $panel );

			if ( '' === $title_html || null === $body ) {
				return array( $this->custom_html_block( trim( $this->outer_html( $node ) ) ) );
			}

			$body_blocks = $this->child_nodes_to_blocks( $body );

			if ( $this->contains_classic_block( $body_blocks ) ) {
				return array( $this->custom_html_block( trim( $this->outer_html( $node ) ) ) );
			}

			$blocks[] = $this->legacy_accordion_panel_to_details_block(
				$node,
				$panel,
				$body,
				$title_html,
				$body_blocks
			);
		}

		return $blocks;
	}

	/**
	 * Converts one legacy accordion panel into a Details block.
	 *
	 * @param \DOMElement       $wrapper    Accordion wrapper.
	 * @param \DOMElement       $panel      Accordion panel.
	 * @param \DOMElement       $body       Accordion body.
	 * @param string            $title_html Summary HTML.
	 * @param array<int,string> $body_blocks Body block markup.
	 * @return string
	 */
	private function legacy_accordion_panel_to_details_block(
		\DOMElement $wrapper,
		\DOMElement $panel,
		\DOMElement $body,
		$title_html,
		array $body_blocks
	) {
		$attributes = array();
		$html_attrs = array( 'class' => 'wp-block-details' );
		$anchor     = $this->accordion_panel_anchor( $panel, $body );

		if ( $this->accordion_panel_is_open( $panel ) ) {
			$attributes['showContent'] = true;
		}

		if ( '' !== $anchor ) {
			$attributes['anchor'] = $anchor;
			$html_attrs['id']     = $anchor;
		}

		$align = $this->accordion_panel_alignment( $wrapper, $panel, $body );
		if ( '' !== $align ) {
			$attributes['align']  = $align;
			$html_attrs['class'] .= ' align' . $align;
		}

		$group_name = $this->element_attribute( $wrapper, 'id' );
		if ( '' !== $group_name ) {
			$html_attrs['name'] = $group_name;
		}

		$body_html = empty( $body_blocks ) ? '' : "\n" . implode( "\n\n", $body_blocks );
		$open      = $this->accordion_panel_is_open( $panel ) ? ' open' : '';

		return $this->block_open_comment( 'details', $attributes ) . "\n"
			. '<details' . $this->html_attributes( $html_attrs ) . $open . '><summary>' . $title_html . '</summary>'
			. $body_html . '</details>' . "\n"
			. '<!-- /wp:details -->';
	}

	/**
	 * Returns safe wide/full alignment for a converted legacy accordion panel.
	 *
	 * @param \DOMElement $wrapper Accordion wrapper.
	 * @param \DOMElement $panel   Accordion panel.
	 * @param \DOMElement $body    Accordion body.
	 * @return string
	 */
	private function accordion_panel_alignment( \DOMElement $wrapper, \DOMElement $panel, \DOMElement $body ) {
		foreach ( array( $panel, $body, $wrapper ) as $element ) {
			$align = $this->wide_or_full_alignment( $element );
			if ( '' !== $align ) {
				return $align;
			}
		}

		return '';
	}

	/**
	 * Returns direct child elements that look like accordion panels.
	 *
	 * @param \DOMElement $node Accordion wrapper.
	 * @return array<int,\DOMElement>
	 */
	private function direct_accordion_panels( \DOMElement $node ) {
		$panels = array();

		foreach ( $node->childNodes as $child ) {
			if ( XML_TEXT_NODE === $child->nodeType && '' === trim( (string) $child->textContent ) ) {
				continue;
			}

			if ( $child instanceof \DOMElement && 'br' === strtolower( $child->nodeName ) ) {
				continue;
			}

			if ( ! $child instanceof \DOMElement || ! $this->is_accordion_panel_candidate( $child ) ) {
				return array();
			}

			$panels[] = $child;
		}

		return $panels;
	}

	/**
	 * Returns whether an element is a legacy accordion item/panel.
	 *
	 * @param \DOMElement $node Possible panel.
	 * @return bool
	 */
	private function is_accordion_panel_candidate( \DOMElement $node ) {
		return $this->has_any_class_token(
			$node,
			array( 'accordion-item', 'accordion-panel', 'accordion-section', 'panel' )
		);
	}

	/**
	 * Extracts the summary HTML from an accordion panel.
	 *
	 * @param \DOMElement $panel Accordion panel.
	 * @return string
	 */
	private function accordion_panel_title_html( \DOMElement $panel ) {
		$header = $this->first_descendant_by_any_class( $panel, array( 'accordion-header', 'panel-heading' ) );

		if ( null === $header ) {
			foreach ( array( 'h2', 'h3', 'h4', 'h5', 'h6' ) as $tag ) {
				$header = $this->first_descendant_by_tag( $panel, $tag );

				if ( null !== $header ) {
					break;
				}
			}
		}

		if ( null === $header ) {
			return '';
		}

		foreach ( array( 'button', 'a' ) as $tag ) {
			$control = $this->first_descendant_by_tag( $header, $tag );

			if ( null !== $control ) {
				return trim( $this->inner_html( $control ) );
			}
		}

		return trim( $this->inner_html( $header ) );
	}

	/**
	 * Returns the content element for an accordion panel.
	 *
	 * @param \DOMElement $panel Accordion panel.
	 * @return \DOMElement|null
	 */
	private function accordion_panel_body_element( \DOMElement $panel ) {
		$body = $this->first_descendant_by_any_class(
			$panel,
			array( 'accordion-body', 'panel-body', 'accordion-content' )
		);

		if ( null !== $body ) {
			return $body;
		}

		return $this->first_descendant_by_any_class( $panel, array( 'accordion-collapse', 'panel-collapse', 'collapse' ) );
	}

	/**
	 * Returns whether a legacy accordion panel should start open.
	 *
	 * @param \DOMElement $panel Accordion panel.
	 * @return bool
	 */
	private function accordion_panel_is_open( \DOMElement $panel ) {
		if (
			$this->has_disclosure_open_class( $panel, true )
			|| $this->element_has_expanded_state( $panel )
		) {
			return true;
		}

		foreach ( $panel->getElementsByTagName( '*' ) as $element ) {
			if (
				$this->has_disclosure_open_class( $element, true )
				|| $this->element_has_expanded_state( $element )
			) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Returns whether an element carries safe disclosure open-state class metadata.
	 *
	 * @param \DOMElement $node       Element.
	 * @param bool        $include_in Whether legacy accordion `in` classes should be honored.
	 * @return bool
	 */
	private function has_disclosure_open_class( \DOMElement $node, $include_in = false ) {
		$classes = array( 'active', 'open', 'show', 'expanded', 'is-active', 'is-open', 'is-show', 'is-expanded' );

		if ( $include_in ) {
			$classes[] = 'in';
			$classes[] = 'is-in';
		}

		return $this->has_any_class_token( $node, $classes );
	}

	/**
	 * Returns whether an element carries imported expanded/open state metadata.
	 *
	 * @param \DOMElement $node Element.
	 * @return bool
	 */
	private function element_has_expanded_state( \DOMElement $node ) {
		return 'true' === strtolower( $this->element_attribute( $node, 'aria-expanded' ) )
			|| 'true' === strtolower( $this->element_attribute( $node, 'data-expanded' ) )
			|| 'true' === strtolower( $this->element_attribute( $node, 'data-open' ) );
	}

	/**
	 * Builds a stable anchor for a converted accordion panel.
	 *
	 * @param \DOMElement $panel Accordion panel.
	 * @param \DOMElement $body  Accordion body.
	 * @return string
	 */
	private function accordion_panel_anchor( \DOMElement $panel, \DOMElement $body ) {
		$anchor = $this->element_attribute( $body, 'id' );

		if ( '' === $anchor ) {
			$anchor = $this->element_attribute( $panel, 'id' );
		}

		return $anchor;
	}

	/**
	 * Returns whether a node is a tabbed interface that should not be flattened.
	 *
	 * @param \DOMNode $node DOM node.
	 * @return bool
	 */
	private function is_tabbed_interface_candidate( \DOMNode $node ) {
		if ( ! $node instanceof \DOMElement ) {
			return false;
		}

		$has_container_signal = $this->has_any_class_token(
			$node,
			array( 'tabs', 'tabbed', 'tab-container', 'tab-wrapper', 'nav-tabs' )
		) || 'tablist' === strtolower( $this->element_attribute( $node, 'role' ) );

		if ( ! $has_container_signal ) {
			return false;
		}

		return 2 <= $this->count_tab_controls( $node ) || 2 <= $this->count_tab_panels( $node );
	}

	/**
	 * Returns whether an empty legacy spacer wrapper can become a Spacer block.
	 *
	 * @param \DOMNode $node DOM node.
	 * @return bool
	 */
	private function is_spacer_candidate( \DOMNode $node ) {
		if ( ! $node instanceof \DOMElement ) {
			return false;
		}

		if ( ! in_array( strtolower( $node->nodeName ), array( 'div', 'section', 'span' ), true ) ) {
			return false;
		}

		if ( ! $this->has_spacer_signal( $node ) || ! $this->spacer_has_no_visible_content( $node ) ) {
			return false;
		}

		return '' !== $this->spacer_height( $node );
	}

	/**
	 * Converts a legacy spacer wrapper into a native Spacer block.
	 *
	 * @param \DOMNode $node Spacer node.
	 * @return string
	 */
	private function spacer_to_block( \DOMNode $node ) {
		if ( ! $node instanceof \DOMElement ) {
			return $this->classic_block( trim( $this->outer_html( $node ) ) );
		}

		$height = $this->spacer_height( $node );

		if ( '' === $height ) {
			return $this->classic_block( trim( $this->outer_html( $node ) ) );
		}

		$attributes = array( 'height' => $height );
		$html_attrs = array(
			'style'       => 'height:' . $height,
			'aria-hidden' => 'true',
			'class'       => 'wp-block-spacer',
		);
		$anchor     = $this->element_attribute( $node, 'id' );

		if ( '' !== $anchor ) {
			$attributes['anchor'] = $anchor;
			$html_attrs['id']     = $anchor;
		}

		return $this->block_open_comment( 'spacer', $attributes ) . "\n"
			. '<div' . $this->html_attributes( $html_attrs ) . '></div>' . "\n"
			. '<!-- /wp:spacer -->';
	}

	/**
	 * Returns whether an element carries explicit spacer intent.
	 *
	 * @param \DOMElement $node Element.
	 * @return bool
	 */
	private function has_spacer_signal( \DOMElement $node ) {
		if (
			$this->has_any_class_token(
				$node,
				array(
					'gap',
					'spacer',
					'vertical-spacer',
					'white-space',
					'whitespace',
					'wp-block-spacer',
				)
			)
			|| $this->has_class_token_with_prefix( $node, 'gap-' )
			|| $this->has_class_token_with_prefix( $node, 'spacer-' )
			|| $this->has_class_token_with_prefix( $node, 'vertical-spacer-' )
		) {
			return true;
		}

		if ( 'true' === strtolower( $this->element_attribute( $node, 'aria-hidden' ) ) ) {
			return true;
		}

		return 'presentation' === strtolower( $this->element_attribute( $node, 'role' ) );
	}

	/**
	 * Returns whether a spacer candidate has no content that would be dropped.
	 *
	 * @param \DOMElement $node Element.
	 * @return bool
	 */
	private function spacer_has_no_visible_content( \DOMElement $node ) {
		foreach ( $node->childNodes as $child ) {
			if ( XML_TEXT_NODE === $child->nodeType || XML_CDATA_SECTION_NODE === $child->nodeType ) {
				$text = preg_replace( '/[\s\x{00a0}\x{00c2}]+/u', '', (string) $child->textContent );

				if ( '' !== $text ) {
					return false;
				}

				continue;
			}

			if ( $child instanceof \DOMElement && 'br' === strtolower( $child->nodeName ) ) {
				continue;
			}

			if ( XML_COMMENT_NODE === $child->nodeType ) {
				continue;
			}

			return false;
		}

		return true;
	}

	/**
	 * Extracts and normalizes a bounded CSS height for a Spacer block.
	 *
	 * @param \DOMElement $node Spacer candidate.
	 * @return string
	 */
	private function spacer_height( \DOMElement $node ) {
		foreach ( array( 'data-spacer-height', 'data-height', 'height' ) as $attribute ) {
			$height = $this->normalize_spacer_height( $this->element_attribute( $node, $attribute ) );

			if ( '' !== $height ) {
				return $height;
			}
		}

		return $this->spacer_height_from_style( $this->element_attribute( $node, 'style' ) );
	}

	/**
	 * Extracts a height declaration from inline style.
	 *
	 * @param string $style Inline CSS.
	 * @return string
	 */
	private function spacer_height_from_style( $style ) {
		if ( preg_match( '/(?:^|;)\s*height\s*:\s*([^;]+)/i', (string) $style, $matches ) ) {
			return $this->normalize_spacer_height( $matches[1] );
		}

		if ( preg_match( '/(?:^|;)\s*min-height\s*:\s*([^;]+)/i', (string) $style, $matches ) ) {
			return $this->normalize_spacer_height( $matches[1] );
		}

		if ( preg_match( '/(?:^|;)\s*padding-top\s*:\s*([^;]+)/i', (string) $style, $matches ) ) {
			return $this->normalize_spacer_height( $matches[1] );
		}

		return '';
	}

	/**
	 * Normalizes a spacer height while rejecting unbounded or executable CSS.
	 *
	 * @param string $height Raw height.
	 * @return string
	 */
	private function normalize_spacer_height( $height ) {
		$height = trim( preg_replace( '/\s*!important\s*$/i', '', (string) $height ) );

		if ( '' === $height || $this->is_executable_style( 'height:' . $height ) ) {
			return '';
		}

		if ( preg_match( '/^(\d{1,4})(?:\.0+)?$/', $height, $matches ) ) {
			$height = $matches[1] . 'px';
		}

		if ( ! preg_match( '/^(\d+(?:\.\d+)?)(px|em|rem|vh|vw)$/i', $height, $matches ) ) {
			return '';
		}

		$value = (float) $matches[1];
		$unit  = strtolower( $matches[2] );

		if ( $value <= 0 ) {
			return '';
		}

		if ( 'px' === $unit && $value > 2000 ) {
			return '';
		}

		if ( in_array( $unit, array( 'em', 'rem' ), true ) && $value > 120 ) {
			return '';
		}

		if ( in_array( $unit, array( 'vh', 'vw' ), true ) && $value > 100 ) {
			return '';
		}

		$number = $matches[1];

		if ( false !== strpos( $number, '.' ) ) {
			$number = rtrim( rtrim( $number, '0' ), '.' );
		}

		return $number . $unit;
	}

	/**
	 * Returns whether a node is an obvious image-plus-copy split layout.
	 *
	 * @param \DOMNode $node DOM node.
	 * @return bool
	 */
	private function is_media_text_wrapper_candidate( \DOMNode $node ) {
		if ( ! $node instanceof \DOMElement ) {
			return false;
		}

		if ( ! in_array( strtolower( $node->nodeName ), array( 'article', 'aside', 'div', 'section' ), true ) ) {
			return false;
		}

		if (
			! $this->has_any_class_token(
				$node,
				array(
					'image-text',
					'media-object',
					'media-text',
					'split-content',
					'split-media',
					'text-image',
					'wp-block-media-text',
				)
			)
			&& ! $this->has_class_token_with_prefix( $node, 'image-text-' )
			&& ! $this->has_class_token_with_prefix( $node, 'media-text-' )
			&& ! $this->has_class_token_with_prefix( $node, 'split-media-' )
			&& ! $this->has_class_token_with_prefix( $node, 'text-image-' )
		) {
			return false;
		}

		return null !== $this->media_text_pair( $node );
	}

	/**
	 * Converts an obvious image-plus-copy split layout into a Media & Text block.
	 *
	 * @param \DOMNode $node Media/text wrapper node.
	 * @return string
	 */
	private function media_text_wrapper_to_block( \DOMNode $node ) {
		if ( ! $node instanceof \DOMElement ) {
			return $this->classic_block( trim( $this->outer_html( $node ) ) );
		}

		$pair = $this->media_text_pair( $node );

		if ( null === $pair ) {
			return $this->classic_block( trim( $this->outer_html( $node ) ) );
		}

		$image = $this->media_text_image_element( $pair['media'] );

		if ( null === $image || '' === $this->element_attribute( $image, 'src' ) ) {
			return $this->classic_block( trim( $this->outer_html( $node ) ) );
		}

		$content_blocks = $this->media_text_content_blocks( $pair['content'] );

		if ( empty( $content_blocks ) || $this->contains_classic_block( $content_blocks ) ) {
			return $this->classic_block( trim( $this->outer_html( $node ) ) );
		}

		$attributes = $this->media_text_block_attributes( $node, $image, $pair['media'], $pair['media_position'] );
		$html_attrs = array( 'class' => 'wp-block-media-text is-stacked-on-mobile' );
		$anchor     = $this->element_attribute( $node, 'id' );
		$align      = $this->wide_or_full_alignment( $node );

		if ( 'right' === $pair['media_position'] ) {
			$html_attrs['class'] .= ' has-media-on-the-right';
		}

		if ( '' !== $align ) {
			$html_attrs['class'] .= ' align' . $align;
		}

		if ( '' !== $anchor ) {
			$html_attrs['id'] = $anchor;
		}

		return $this->block_open_comment( 'media-text', $attributes ) . "\n"
			. '<div' . $this->html_attributes( $html_attrs ) . '>'
			. '<figure class="wp-block-media-text__media">' . $this->media_text_visual_html( $image, $pair['media'] ) . '</figure>'
			. '<div class="wp-block-media-text__content">' . "\n"
			. implode( "\n\n", $content_blocks ) . "\n"
			. '</div></div>' . "\n"
			. '<!-- /wp:media-text -->';
	}

	/**
	 * Finds the media and text sides of a two-column media/text wrapper.
	 *
	 * @param \DOMElement $node Wrapper element.
	 * @return array{media:\DOMElement,content:\DOMElement,media_position:string}|null
	 */
	private function media_text_pair( \DOMElement $node ) {
		$children = $this->direct_meaningful_child_elements( $node, true );

		if ( 2 !== count( $children ) ) {
			return null;
		}

		$first_is_media  = $this->is_media_text_media_child_candidate( $children[0] );
		$second_is_media = $this->is_media_text_media_child_candidate( $children[1] );

		if ( $first_is_media === $second_is_media ) {
			return null;
		}

		return array(
			'media'          => $first_is_media ? $children[0] : $children[1],
			'content'        => $first_is_media ? $children[1] : $children[0],
			'media_position' => $first_is_media ? 'left' : 'right',
		);
	}

	/**
	 * Returns direct child elements, ignoring whitespace-only text.
	 *
	 * @param \DOMElement $node      Element.
	 * @param bool        $ignore_br Whether to ignore direct line-break separators.
	 * @return array<int,\DOMElement>
	 */
	private function direct_meaningful_child_elements( \DOMElement $node, $ignore_br = false ) {
		$children = array();

		foreach ( $node->childNodes as $child ) {
			if ( XML_TEXT_NODE === $child->nodeType && '' === trim( (string) $child->textContent ) ) {
				continue;
			}

			if ( $ignore_br && $child instanceof \DOMElement && 'br' === strtolower( $child->nodeName ) ) {
				continue;
			}

			if ( ! $child instanceof \DOMElement ) {
				return array();
			}

			$children[] = $child;
		}

		return $children;
	}

	/**
	 * Returns whether a direct child is the visual side of a media/text layout.
	 *
	 * @param \DOMElement $node Possible media child.
	 * @return bool
	 */
	private function is_media_text_media_child_candidate( \DOMElement $node ) {
		$name = strtolower( $node->nodeName );

		if ( in_array( $name, array( 'a', 'figure', 'img', 'picture' ), true ) ) {
			return null !== $this->media_text_image_element( $node );
		}

		if (
			! $this->has_any_class_token( $node, array( 'image', 'media', 'photo', 'thumbnail', 'visual' ) )
			&& ! $this->has_class_token_with_prefix( $node, 'image-' )
			&& ! $this->has_class_token_with_prefix( $node, 'media-' )
			&& ! $this->has_class_token_with_prefix( $node, 'visual-' )
		) {
			return false;
		}

		return null !== $this->media_text_image_element( $node );
	}

	/**
	 * Returns whether a wrapper is clearly an imported social/profile link list.
	 *
	 * @param \DOMNode $node DOM node.
	 * @return bool
	 */
	private function is_social_links_wrapper_candidate( \DOMNode $node ) {
		if ( ! $node instanceof \DOMElement ) {
			return false;
		}

		if ( ! in_array( strtolower( $node->nodeName ), array( 'div', 'nav', 'ol', 'section', 'ul' ), true ) ) {
			return false;
		}

		if ( ! $this->has_social_links_wrapper_signal( $node ) ) {
			return false;
		}

		$links            = $this->social_link_elements( $node );
		$recognized_links = 0;
		$recognized_hosts = array();

		foreach ( $links as $link ) {
			$service = $this->social_link_service_for_url( $this->element_attribute( $link, 'href' ) );

			if ( 'chain' !== $service ) {
				++$recognized_links;
				$recognized_hosts[ $service ] = true;
			}
		}

		return 2 <= count( $links ) && 2 <= $recognized_links && 2 <= count( $recognized_hosts );
	}

	/**
	 * Converts an obvious social/profile link list into a native Social Icons block.
	 *
	 * @param \DOMNode $node Social links wrapper.
	 * @return string
	 */
	private function social_links_wrapper_to_block( \DOMNode $node ) {
		if ( ! $node instanceof \DOMElement ) {
			return $this->classic_block( trim( $this->outer_html( $node ) ) );
		}

		$links = $this->social_link_elements( $node );

		if ( count( $links ) < 2 ) {
			return $this->classic_block( trim( $this->outer_html( $node ) ) );
		}

		$attributes = array();
		$html_attrs = array( 'class' => 'wp-block-social-links' );
		$anchor     = $this->element_attribute( $node, 'id' );
		$align      = $this->horizontal_alignment( $node );

		if ( '' !== $anchor ) {
			$attributes['anchor'] = $anchor;
			$html_attrs['id']     = $anchor;
		}

		if ( '' !== $align ) {
			$attributes['align']  = $align;
			$html_attrs['class'] .= ' align' . $align;
		}

		if ( $this->social_links_show_labels( $node, $links ) ) {
			$attributes['showLabels'] = true;
			$html_attrs['class']     .= ' has-visible-labels';
		}

		if ( $this->social_links_open_in_new_tab( $links ) ) {
			$attributes['openInNewTab'] = true;
		}

		$link_blocks = array();

		foreach ( $links as $link ) {
			$link_blocks[] = $this->social_link_to_block_comment( $link );
		}

		return $this->block_open_comment( 'social-links', $attributes ) . "\n"
			. '<ul' . $this->html_attributes( $html_attrs ) . '>' . "\n"
			. implode( "\n", $link_blocks ) . "\n"
			. '</ul>' . "\n"
			. '<!-- /wp:social-links -->';
	}

	/**
	 * Returns whether a wrapper has an explicit social-links signal.
	 *
	 * @param \DOMElement $node Wrapper element.
	 * @return bool
	 */
	private function has_social_links_wrapper_signal( \DOMElement $node ) {
		if (
			$this->has_any_class_token(
				$node,
				array(
					'follow',
					'follow-links',
					'follow-us',
					'profiles',
					'share-links',
					'social',
					'social-icons',
					'social-links',
					'social-media',
					'socials',
					'wp-block-social-links',
				)
			)
			|| $this->has_class_token_with_prefix( $node, 'follow-' )
			|| $this->has_class_token_with_prefix( $node, 'social-' )
		) {
			return true;
		}

		$label = strtolower( $this->element_attribute( $node, 'aria-label' ) . ' ' . $this->element_attribute( $node, 'title' ) );

		return false !== strpos( $label, 'social' ) || false !== strpos( $label, 'follow' );
	}

	/**
	 * Returns social link anchors from a wrapper, or an empty list when mixed.
	 *
	 * @param \DOMElement $node Wrapper element.
	 * @return array<int,\DOMElement>
	 */
	private function social_link_elements( \DOMElement $node ) {
		$links = array();

		if ( ! in_array( strtolower( $node->nodeName ), array( 'ol', 'ul' ), true ) ) {
			$children = $this->direct_meaningful_child_elements( $node );

			if ( 1 === count( $children ) && in_array( strtolower( $children[0]->nodeName ), array( 'ol', 'ul' ), true ) ) {
				return $this->social_link_elements( $children[0] );
			}
		}

		foreach ( $node->childNodes as $child ) {
			if ( XML_TEXT_NODE === $child->nodeType && '' === trim( (string) $child->textContent ) ) {
				continue;
			}

			if ( $child instanceof \DOMElement && 'br' === strtolower( $child->nodeName ) ) {
				continue;
			}

			if ( ! $child instanceof \DOMElement ) {
				return array();
			}

			$link = $this->social_link_from_child( $child );

			if ( null === $link || '' === $this->element_attribute( $link, 'href' ) ) {
				return array();
			}

			$links[] = $link;
		}

		return $links;
	}

	/**
	 * Extracts one social link anchor from a direct child element.
	 *
	 * @param \DOMElement $child Direct child.
	 * @return \DOMElement|null
	 */
	private function social_link_from_child( \DOMElement $child ) {
		if ( 'a' === strtolower( $child->nodeName ) ) {
			return $child;
		}

		if ( ! in_array( strtolower( $child->nodeName ), array( 'div', 'li', 'span' ), true ) ) {
			return null;
		}

		if ( 1 !== $this->count_descendant_links( $child ) ) {
			return null;
		}

		return $this->first_descendant_by_tag( $child, 'a' );
	}

	/**
	 * Counts descendant anchors.
	 *
	 * @param \DOMElement $node Element.
	 * @return int
	 */
	private function count_descendant_links( \DOMElement $node ) {
		return $node->getElementsByTagName( 'a' )->length;
	}

	/**
	 * Builds one Social Icon inner block comment.
	 *
	 * @param \DOMElement $link Link element.
	 * @return string
	 */
	private function social_link_to_block_comment( \DOMElement $link ) {
		$href       = $this->element_attribute( $link, 'href' );
		$service    = $this->social_link_service_for_url( $href );
		$attributes = array(
			'url'     => $href,
			'service' => $service,
			'label'   => $this->social_link_label( $link, $service ),
		);
		$rel        = $this->safe_link_rel( $link );

		if ( '' !== $rel ) {
			$attributes['rel'] = $rel;
		}

		return '<!-- wp:social-link ' . $this->encode_block_attributes( $attributes ) . ' /-->';
	}

	/**
	 * Infers a Social Icon service slug from a URL.
	 *
	 * @param string $url Link URL.
	 * @return string
	 */
	private function social_link_service_for_url( $url ) {
		$url = trim( (string) $url );

		if ( '' === $url ) {
			return 'chain';
		}

		if ( false !== strpos( $url, '@' ) && ! preg_match( '#^[a-z][a-z0-9+.-]*:#i', $url ) ) {
			return 'mail';
		}

		$scheme = strtolower( (string) $this->parse_url_part( $url, PHP_URL_SCHEME ) );

		if ( 'mailto' === $scheme ) {
			return 'mail';
		}

		$host = strtolower( (string) $this->parse_url_part( $url, PHP_URL_HOST ) );
		$path = strtolower( (string) $this->parse_url_part( $url, PHP_URL_PATH ) );

		if ( '' === $host ) {
			return false !== strpos( $path, 'feed' ) || preg_match( '/\.(?:rss|xml)$/', $path ) ? 'feed' : 'chain';
		}

		$host = preg_replace( '/^www\./', '', $host );
		$map  = array(
			'500px.com'       => 'fivehundredpx',
			'bandcamp.com'    => 'bandcamp',
			'behance.net'     => 'behance',
			'bsky.app'        => 'bluesky',
			'codepen.io'      => 'codepen',
			'discord.com'     => 'discord',
			'dribbble.com'    => 'dribbble',
			'facebook.com'    => 'facebook',
			'github.com'      => 'github',
			'instagram.com'   => 'instagram',
			'linkedin.com'    => 'linkedin',
			'mastodon.social' => 'mastodon',
			'medium.com'      => 'medium',
			'pinterest.com'   => 'pinterest',
			'reddit.com'      => 'reddit',
			'soundcloud.com'  => 'soundcloud',
			'spotify.com'     => 'spotify',
			't.me'            => 'telegram',
			'telegram.me'     => 'telegram',
			'threads.net'     => 'threads',
			'tiktok.com'      => 'tiktok',
			'tumblr.com'      => 'tumblr',
			'twitch.tv'       => 'twitch',
			'twitter.com'     => 'twitter',
			'vimeo.com'       => 'vimeo',
			'wordpress.org'   => 'wordpress',
			'wordpress.com'   => 'wordpress',
			'x.com'           => 'x',
			'yelp.com'        => 'yelp',
			'youtube.com'     => 'youtube',
			'youtu.be'        => 'youtube',
		);

		foreach ( $map as $domain => $service ) {
			if ( $host === $domain || substr( $host, -strlen( '.' . $domain ) ) === '.' . $domain ) {
				return $service;
			}
		}

		if ( false !== strpos( $path, 'feed' ) || preg_match( '/\.(?:rss|xml)$/', $path ) ) {
			return 'feed';
		}

		return 'chain';
	}

	/**
	 * Returns a stable label for a Social Icon block.
	 *
	 * @param \DOMElement $link    Link element.
	 * @param string      $service Inferred service.
	 * @return string
	 */
	private function social_link_label( \DOMElement $link, $service ) {
		foreach ( array( 'aria-label', 'title' ) as $attribute ) {
			$value = $this->element_attribute( $link, $attribute );

			if ( '' !== $value ) {
				return $value;
			}
		}

		$text = trim( preg_replace( '/\s+/', ' ', (string) $link->textContent ) );

		if ( '' !== $text ) {
			return $text;
		}

		$labels = array(
			'chain'         => 'Link',
			'feed'          => 'RSS Feed',
			'fivehundredpx' => '500px',
			'github'        => 'GitHub',
			'linkedin'      => 'LinkedIn',
			'mail'          => 'Mail',
			'soundcloud'    => 'SoundCloud',
			'tiktok'        => 'TikTok',
			'twitter'       => 'Twitter',
			'wordpress'     => 'WordPress',
			'youtube'       => 'YouTube',
		);

		if ( isset( $labels[ $service ] ) ) {
			return $labels[ $service ];
		}

		return ucfirst( (string) $service );
	}

	/**
	 * Returns whether imported social labels were probably visible text.
	 *
	 * @param \DOMElement            $wrapper Wrapper element.
	 * @param array<int,\DOMElement> $links   Social links.
	 * @return bool
	 */
	private function social_links_show_labels( \DOMElement $wrapper, array $links ) {
		if ( $this->has_any_class_token( $wrapper, array( 'has-visible-labels', 'show-labels', 'social-labels', 'with-labels' ) ) ) {
			return true;
		}

		foreach ( $links as $link ) {
			if ( '' !== trim( preg_replace( '/\s+/', ' ', (string) $link->textContent ) ) && ! $this->link_text_is_only_iconish( $link ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Returns whether a link's text appears to come only from icon markup.
	 *
	 * @param \DOMElement $link Link element.
	 * @return bool
	 */
	private function link_text_is_only_iconish( \DOMElement $link ) {
		foreach ( $link->childNodes as $child ) {
			if ( XML_TEXT_NODE === $child->nodeType && '' !== trim( (string) $child->textContent ) ) {
				return false;
			}

			if ( $child instanceof \DOMElement && ! in_array( strtolower( $child->nodeName ), array( 'i', 'svg' ), true ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Returns whether all imported social links open in a new tab.
	 *
	 * @param array<int,\DOMElement> $links Social links.
	 * @return bool
	 */
	private function social_links_open_in_new_tab( array $links ) {
		if ( empty( $links ) ) {
			return false;
		}

		foreach ( $links as $link ) {
			if ( '_blank' !== strtolower( $this->element_attribute( $link, 'target' ) ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Converts an obvious navigation wrapper into a native Navigation block.
	 *
	 * @param \DOMNode $node Navigation wrapper.
	 * @return string
	 */
	private function navigation_wrapper_to_block( \DOMNode $node ) {
		if ( ! $node instanceof \DOMElement ) {
			return $this->classic_block( trim( $this->outer_html( $node ) ) );
		}

		$link_blocks = $this->navigation_link_blocks( $node, true );

		if ( empty( $link_blocks ) ) {
			return $this->classic_block( trim( $this->outer_html( $node ) ) );
		}

		$attributes = array( 'overlayMenu' => 'never' );
		$anchor     = $this->element_attribute( $node, 'id' );
		$aria_label = $this->element_attribute( $node, 'aria-label' );
		$align      = $this->wide_or_full_alignment( $node );

		if ( '' !== $anchor ) {
			$attributes['anchor'] = $anchor;
		}

		if ( '' !== $align ) {
			$attributes['align'] = $align;
		}

		if ( '' !== $aria_label ) {
			$attributes['ariaLabel'] = $aria_label;
		}

		return $this->block_open_comment( 'navigation', $attributes ) . "\n"
			. implode( "\n", $link_blocks ) . "\n"
			. '<!-- /wp:navigation -->';
	}

	/**
	 * Returns whether a wrapper has explicit navigation semantics.
	 *
	 * @param \DOMElement $node Wrapper element.
	 * @return bool
	 */
	private function has_navigation_wrapper_signal( \DOMElement $node ) {
		if ( 'nav' === strtolower( $node->nodeName ) || 'navigation' === strtolower( $this->element_attribute( $node, 'role' ) ) ) {
			return true;
		}

		return $this->has_any_class_token(
			$node,
			array(
				'main-menu',
				'main-nav',
				'main-navigation',
				'menu',
				'nav',
				'navigation',
				'primary-menu',
				'primary-nav',
				'primary-navigation',
				'site-menu',
				'site-nav',
				'site-navigation',
			)
		);
	}

	/**
	 * Builds inner Navigation block comments from a wrapper or list.
	 *
	 * @param \DOMElement $node         Navigation wrapper or list element.
	 * @param bool        $is_top_level Whether links are top-level items.
	 * @return array<int,string>
	 */
	private function navigation_link_blocks( \DOMElement $node, $is_top_level ) {
		$list = $this->navigation_list_element( $node );

		if ( null === $list ) {
			$links = $this->navigation_direct_anchor_elements( $node );

			if ( empty( $links ) ) {
				return array();
			}

			$blocks = array();

			foreach ( $links as $link ) {
				$block = $this->navigation_anchor_to_link_block( $link, $is_top_level );

				if ( '' === $block ) {
					return array();
				}

				$blocks[] = $block;
			}

			return $blocks;
		}

		$blocks = array();

		foreach ( $this->navigation_list_item_elements( $list ) as $item ) {
			$block = $this->navigation_list_item_to_block( $item, $is_top_level );

			if ( '' === $block ) {
				return array();
			}

			$blocks[] = $block;
		}

		return $blocks;
	}

	/**
	 * Returns the single direct menu list represented by a wrapper.
	 *
	 * @param \DOMElement $node Wrapper or list.
	 * @return \DOMElement|null
	 */
	private function navigation_list_element( \DOMElement $node ) {
		if ( in_array( strtolower( $node->nodeName ), array( 'ol', 'ul' ), true ) ) {
			return $node;
		}

		$children = $this->direct_meaningful_child_elements( $node, true );

		if ( 1 !== count( $children ) || ! in_array( strtolower( $children[0]->nodeName ), array( 'ol', 'ul' ), true ) ) {
			return null;
		}

		return $children[0];
	}

	/**
	 * Returns direct anchors when a nav uses links without a list.
	 *
	 * @param \DOMElement $node Navigation wrapper.
	 * @return array<int,\DOMElement>
	 */
	private function navigation_direct_anchor_elements( \DOMElement $node ) {
		$links = array();

		foreach ( $node->childNodes as $child ) {
			if ( XML_TEXT_NODE === $child->nodeType && '' === trim( (string) $child->textContent ) ) {
				continue;
			}

			if ( $child instanceof \DOMElement && 'br' === strtolower( $child->nodeName ) ) {
				continue;
			}

			if ( ! $child instanceof \DOMElement || 'a' !== strtolower( $child->nodeName ) ) {
				return array();
			}

			$links[] = $child;
		}

		return $links;
	}

	/**
	 * Returns direct list-item elements from a navigation list.
	 *
	 * @param \DOMElement $list_node List element.
	 * @return array<int,\DOMElement>
	 */
	private function navigation_list_item_elements( \DOMElement $list_node ) {
		$items = array();

		foreach ( $list_node->childNodes as $child ) {
			if ( XML_TEXT_NODE === $child->nodeType && '' === trim( (string) $child->textContent ) ) {
				continue;
			}

			if ( $child instanceof \DOMElement && 'br' === strtolower( $child->nodeName ) ) {
				continue;
			}

			if ( ! $child instanceof \DOMElement || 'li' !== strtolower( $child->nodeName ) ) {
				return array();
			}

			$items[] = $child;
		}

		return $items;
	}

	/**
	 * Converts one navigation list item into a link or submenu block.
	 *
	 * @param \DOMElement $item         List item.
	 * @param bool        $is_top_level Whether links are top-level items.
	 * @return string
	 */
	private function navigation_list_item_to_block( \DOMElement $item, $is_top_level ) {
		$link       = null;
		$nested     = null;
		$unexpected = false;

		foreach ( $item->childNodes as $child ) {
			if ( XML_TEXT_NODE === $child->nodeType && '' === trim( (string) $child->textContent ) ) {
				continue;
			}

			if ( $child instanceof \DOMElement && 'br' === strtolower( $child->nodeName ) ) {
				continue;
			}

			if ( $child instanceof \DOMElement && 'a' === strtolower( $child->nodeName ) && null === $link ) {
				$link = $child;
				continue;
			}

			if ( $child instanceof \DOMElement && in_array( strtolower( $child->nodeName ), array( 'ol', 'ul' ), true ) && null === $nested ) {
				$nested = $child;
				continue;
			}

			$unexpected = true;
		}

		if ( $unexpected || null === $link ) {
			return '';
		}

		if ( null === $nested ) {
			return $this->navigation_anchor_to_link_block( $link, $is_top_level );
		}

		$child_blocks = $this->navigation_link_blocks( $nested, false );

		if ( empty( $child_blocks ) ) {
			return '';
		}

		return $this->navigation_anchor_to_submenu_block( $link, $is_top_level, $child_blocks );
	}

	/**
	 * Converts an anchor to a self-closing Navigation Link block comment.
	 *
	 * @param \DOMElement $link         Link element.
	 * @param bool        $is_top_level Whether the link is top-level.
	 * @return string
	 */
	private function navigation_anchor_to_link_block( \DOMElement $link, $is_top_level ) {
		$attributes = $this->navigation_link_attributes( $link, $is_top_level ? 'isTopLevelLink' : '' );

		if ( empty( $attributes ) ) {
			return '';
		}

		return '<!-- wp:navigation-link ' . $this->encode_block_attributes( $attributes ) . ' /-->';
	}

	/**
	 * Converts an anchor plus nested items to a Navigation Submenu block.
	 *
	 * @param \DOMElement       $link         Link element.
	 * @param bool              $is_top_level Whether the submenu is top-level.
	 * @param array<int,string> $child_blocks Nested Navigation Link comments.
	 * @return string
	 */
	private function navigation_anchor_to_submenu_block( \DOMElement $link, $is_top_level, array $child_blocks ) {
		$attributes = $this->navigation_link_attributes( $link, $is_top_level ? 'isTopLevelItem' : '' );

		if ( empty( $attributes ) ) {
			return '';
		}

		return $this->block_open_comment( 'navigation-submenu', $attributes ) . "\n"
			. implode( "\n", $child_blocks ) . "\n"
			. '<!-- /wp:navigation-submenu -->';
	}

	/**
	 * Builds common Navigation Link/Submenu attributes from an anchor.
	 *
	 * @param \DOMElement $link               Link element.
	 * @param string      $top_level_flag_key Optional top-level boolean key.
	 * @return array<string,mixed>
	 */
	private function navigation_link_attributes( \DOMElement $link, $top_level_flag_key ) {
		$href  = $this->element_attribute( $link, 'href' );
		$label = $this->navigation_link_label( $link );

		if ( '' === $href || '' === $label ) {
			return array();
		}

		$attributes = array(
			'label' => $label,
			'url'   => $href,
			'kind'  => 'custom',
		);

		if ( '' !== $top_level_flag_key ) {
			$attributes[ $top_level_flag_key ] = true;
		}

		$title = $this->element_attribute( $link, 'title' );
		if ( '' !== $title ) {
			$attributes['title'] = $title;
		}

		$rel = $this->safe_link_rel( $link );
		if ( '' !== $rel ) {
			$attributes['rel'] = $rel;
		}

		if ( '_blank' === strtolower( $this->element_attribute( $link, 'target' ) ) ) {
			$attributes['opensInNewTab'] = true;
		}

		return $attributes;
	}

	/**
	 * Returns the visible label for an imported navigation link.
	 *
	 * @param \DOMElement $link Link element.
	 * @return string
	 */
	private function navigation_link_label( \DOMElement $link ) {
		$text = trim( preg_replace( '/\s+/', ' ', (string) $link->textContent ) );

		if ( '' !== $text ) {
			return $text;
		}

		foreach ( array( 'aria-label', 'title' ) as $attribute ) {
			$value = $this->element_attribute( $link, $attribute );

			if ( '' !== $value ) {
				return $value;
			}
		}

		return '';
	}

	/**
	 * Returns the image element used by the media side.
	 *
	 * @param \DOMElement $node Media side element.
	 * @return \DOMElement|null
	 */
	private function media_text_image_element( \DOMElement $node ) {
		if ( 'img' === strtolower( $node->nodeName ) ) {
			return '' === $this->element_attribute( $node, 'src' ) ? null : $node;
		}

		$image = $this->first_descendant_by_tag( $node, 'img' );

		return null === $image || '' === $this->element_attribute( $image, 'src' ) ? null : $image;
	}

	/**
	 * Converts the text side of a media/text layout to inner blocks.
	 *
	 * @param \DOMElement $node Content side element.
	 * @return array<int,string>
	 */
	private function media_text_content_blocks( \DOMElement $node ) {
		if ( in_array( strtolower( $node->nodeName ), array( 'article', 'aside', 'div', 'footer', 'header', 'main', 'section' ), true ) ) {
			return $this->child_nodes_to_blocks( $node );
		}

		return $this->node_to_blocks( $node );
	}

	/**
	 * Builds Media & Text block attributes.
	 *
	 * @param \DOMElement $wrapper        Wrapper element.
	 * @param \DOMElement $image          Image element.
	 * @param \DOMElement $media          Media-side element.
	 * @param string      $media_position Media position.
	 * @return array<string,mixed>
	 */
	private function media_text_block_attributes( \DOMElement $wrapper, \DOMElement $image, \DOMElement $media, $media_position ) {
		$src        = $this->element_attribute( $image, 'src' );
		$attributes = array(
			'mediaUrl'  => $src,
			'mediaType' => 'image',
			'mediaLink' => $src,
		);
		$alt        = $this->element_attribute( $image, 'alt' );
		$anchor     = $this->element_attribute( $wrapper, 'id' );
		$align      = $this->wide_or_full_alignment( $wrapper );
		$link       = $this->image_parent_link( $image, $media );

		if ( '' !== $alt ) {
			$attributes['mediaAlt'] = $alt;
		}

		if ( 'right' === $media_position ) {
			$attributes['mediaPosition'] = 'right';
		}

		if ( '' !== $anchor ) {
			$attributes['anchor'] = $anchor;
		}

		if ( '' !== $align ) {
			$attributes['align'] = $align;
		}

		if ( null !== $link ) {
			$href = $this->element_attribute( $link, 'href' );

			if ( '' !== $href ) {
				$attributes['linkDestination'] = 'custom';
				$attributes['href']            = $href;
			}

			$target = $this->normalized_reserved_link_target( $link );
			if ( '' !== $target ) {
				$attributes['linkTarget'] = $target;
			}

			$rel = $this->safe_link_rel( $link );
			if ( '' !== $rel ) {
				$attributes['rel'] = $rel;
			}
		}

		return $attributes;
	}

	/**
	 * Returns the saved media-side HTML for a Media & Text block.
	 *
	 * @param \DOMElement $image Image element.
	 * @param \DOMElement $media Media-side element.
	 * @return string
	 */
	private function media_text_visual_html( \DOMElement $image, \DOMElement $media ) {
		return $this->image_visual_html( $image, $media );
	}

	/**
	 * Returns whether a wrapper is clearly a hero/banner with a visual background.
	 *
	 * @param \DOMNode $node DOM node.
	 * @return bool
	 */
	private function is_cover_wrapper_candidate( \DOMNode $node ) {
		if ( ! $node instanceof \DOMElement ) {
			return false;
		}

		if ( ! in_array( strtolower( $node->nodeName ), array( 'article', 'div', 'header', 'section' ), true ) ) {
			return false;
		}

		if (
			! $this->has_any_class_token(
				$node,
				array(
					'banner',
					'hero',
					'jumbotron',
					'page-hero',
					'wp-block-cover',
				)
			)
			&& ! $this->has_class_token_with_prefix( $node, 'banner-' )
			&& ! $this->has_class_token_with_prefix( $node, 'hero-' )
		) {
			return false;
		}

		if ( '' === $this->cover_image_url( $node ) || ! $this->cover_wrapper_has_content( $node ) ) {
			return false;
		}

		return true;
	}

	/**
	 * Converts an obvious hero/banner wrapper into a native Cover block.
	 *
	 * @param \DOMNode $node Cover wrapper node.
	 * @return string
	 */
	private function cover_wrapper_to_block( \DOMNode $node ) {
		if ( ! $node instanceof \DOMElement ) {
			return $this->classic_block( trim( $this->outer_html( $node ) ) );
		}

		$url          = $this->cover_image_url( $node );
		$image_source = $this->cover_direct_image_source( $node, $url );
		$child_blocks = $this->cover_child_blocks( $node, $image_source );

		if ( '' === $url || empty( $child_blocks ) || $this->contains_classic_block( $child_blocks ) ) {
			return $this->classic_block( trim( $this->outer_html( $node ) ) );
		}

		$attributes = array(
			'url'       => $url,
			'dimRatio'  => 50,
			'className' => 'universal-importer-hero',
		);
		$anchor     = $this->element_attribute( $node, 'id' );
		$align      = $this->wide_or_full_alignment( $node );

		if ( '' !== $anchor ) {
			$attributes['anchor'] = $anchor;
		}

		if ( '' !== $align ) {
			$attributes['align'] = $align;
		}

		$alt = null === $image_source ? '' : $this->element_attribute( $image_source, 'alt' );

		if ( '' !== $alt ) {
			$attributes['alt'] = $alt;
		}

		$html_attrs = array( 'class' => 'wp-block-cover universal-importer-hero' );

		if ( '' !== $align ) {
			$html_attrs['class'] .= ' align' . $align;
		}

		if ( '' !== $anchor ) {
			$html_attrs['id'] = $anchor;
		}

		return $this->block_open_comment( 'cover', $attributes ) . "\n"
			. '<div' . $this->html_attributes( $html_attrs ) . '>'
			. '<span aria-hidden="true" class="wp-block-cover__background has-background-dim"></span>'
			. '<img class="wp-block-cover__image-background" alt="' . $this->escape_html( $alt ) . '" src="' . $this->escape_html( $url ) . '" data-object-fit="cover"/>'
			. '<div class="wp-block-cover__inner-container">' . "\n"
			. implode( "\n\n", $child_blocks ) . "\n"
			. '</div></div>' . "\n"
			. '<!-- /wp:cover -->';
	}

	/**
	 * Returns whether a hero/banner has meaningful non-image content.
	 *
	 * @param \DOMElement $node Cover wrapper.
	 * @return bool
	 */
	private function cover_wrapper_has_content( \DOMElement $node ) {
		foreach ( $node->childNodes as $child ) {
			if ( XML_TEXT_NODE === $child->nodeType && '' !== trim( (string) $child->textContent ) ) {
				return true;
			}

			if ( ! $child instanceof \DOMElement ) {
				continue;
			}

			if ( 'img' === strtolower( $child->nodeName ) ) {
				continue;
			}

			if ( '' !== trim( (string) $child->textContent ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Converts Cover wrapper children while skipping the image promoted to background.
	 *
	 * @param \DOMElement      $node         Cover wrapper.
	 * @param \DOMElement|null $image_source Direct image promoted to background.
	 * @return array<int,string>
	 */
	private function cover_child_blocks( \DOMElement $node, \DOMElement $image_source = null ) {
		$blocks = array();

		foreach ( $node->childNodes as $child ) {
			if ( null !== $image_source && $child->isSameNode( $image_source ) ) {
				continue;
			}

			foreach ( $this->node_to_blocks( $child ) as $block ) {
				if ( '' !== $block ) {
					$blocks[] = $block;
				}
			}
		}

		return $blocks;
	}

	/**
	 * Finds the image URL used by an imported hero/banner wrapper.
	 *
	 * @param \DOMElement $node Cover wrapper.
	 * @return string
	 */
	private function cover_image_url( \DOMElement $node ) {
		$style_url = $this->background_image_url_from_style( $this->element_attribute( $node, 'style' ) );

		if ( '' !== $style_url ) {
			return $style_url;
		}

		foreach ( array( 'data-background', 'data-bg', 'data-bg-image' ) as $attribute ) {
			$value = $this->element_attribute( $node, $attribute );

			if ( '' !== $value && ! $this->is_scriptable_url( $value ) ) {
				return $value;
			}
		}

		$image = $this->direct_cover_image_element( $node );

		return null === $image ? '' : $this->element_attribute( $image, 'src' );
	}

	/**
	 * Returns the direct image element that supplied the Cover background URL.
	 *
	 * @param \DOMElement $node Cover wrapper.
	 * @param string      $url  Selected image URL.
	 * @return \DOMElement|null
	 */
	private function cover_direct_image_source( \DOMElement $node, $url ) {
		$image = $this->direct_cover_image_element( $node );

		if ( null === $image || '' === (string) $url || $this->element_attribute( $image, 'src' ) !== (string) $url ) {
			return null;
		}

		return $image;
	}

	/**
	 * Finds a direct image child suitable for promotion to a Cover background.
	 *
	 * @param \DOMElement $node Cover wrapper.
	 * @return \DOMElement|null
	 */
	private function direct_cover_image_element( \DOMElement $node ) {
		foreach ( $node->childNodes as $child ) {
			if ( XML_TEXT_NODE === $child->nodeType && '' === trim( (string) $child->textContent ) ) {
				continue;
			}

			if ( $child instanceof \DOMElement && 'img' === strtolower( $child->nodeName ) ) {
				$src = $this->element_attribute( $child, 'src' );

				return '' === $src ? null : $child;
			}

			return null;
		}

		return null;
	}

	/**
	 * Extracts a URL from a CSS background-image declaration.
	 *
	 * @param string $style Inline CSS.
	 * @return string
	 */
	private function background_image_url_from_style( $style ) {
		if ( ! preg_match( '/background(?:-image)?\s*:[^;]*url\(\s*([^)]+?)\s*\)/i', (string) $style, $matches ) ) {
			return '';
		}

		$url = trim( $matches[1], " \t\n\r\0\x0B\"'" );

		return $this->is_scriptable_url( $url ) ? '' : $url;
	}

	/**
	 * Counts obvious tab controls inside a tabbed interface.
	 *
	 * @param \DOMElement $node Tab wrapper.
	 * @return int
	 */
	private function count_tab_controls( \DOMElement $node ) {
		$count = 0;

		foreach ( $node->getElementsByTagName( '*' ) as $element ) {
			if ( 'tab' === strtolower( $this->element_attribute( $element, 'role' ) ) ) {
				++$count;
				continue;
			}

			if (
				'a' === strtolower( $element->nodeName )
				&& '' !== $this->element_attribute( $element, 'href' )
				&& $this->has_any_class_token( $element, array( 'tab', 'nav-link', 'tab-link' ) )
			) {
				++$count;
			}
		}

		return $count;
	}

	/**
	 * Counts obvious tab panels inside a tabbed interface.
	 *
	 * @param \DOMElement $node Tab wrapper.
	 * @return int
	 */
	private function count_tab_panels( \DOMElement $node ) {
		$count = 0;

		foreach ( $node->getElementsByTagName( '*' ) as $element ) {
			if (
				'tabpanel' === strtolower( $this->element_attribute( $element, 'role' ) )
				|| $this->has_any_class_token( $element, array( 'tab-pane', 'tab-panel' ) )
			) {
				++$count;
			}
		}

		return $count;
	}

	/**
	 * Returns whether a node is an obvious timeline or step-list wrapper.
	 *
	 * @param \DOMNode $node DOM node.
	 * @return bool
	 */
	private function is_timeline_wrapper_candidate( \DOMNode $node ) {
		if ( ! $node instanceof \DOMElement ) {
			return false;
		}

		if ( ! in_array( strtolower( $node->nodeName ), array( 'article', 'div', 'ol', 'section', 'ul' ), true ) ) {
			return false;
		}

		if (
			! $this->has_any_class_token(
				$node,
				array(
					'roadmap',
					'steps',
					'timeline',
				)
			)
			&& ! $this->has_class_token_with_prefix( $node, 'roadmap-' )
			&& ! $this->has_class_token_with_prefix( $node, 'steps-' )
			&& ! $this->has_class_token_with_prefix( $node, 'timeline-' )
		) {
			return false;
		}

		return 2 <= count( $this->direct_timeline_item_elements( $node ) );
	}

	/**
	 * Converts an obvious timeline or step-list into nested Group blocks.
	 *
	 * @param \DOMNode $node Timeline wrapper node.
	 * @return string
	 */
	private function timeline_wrapper_to_block( \DOMNode $node ) {
		if ( ! $node instanceof \DOMElement ) {
			return $this->classic_block( trim( $this->outer_html( $node ) ) );
		}

		$item_blocks = array();
		$item_class  = $this->timeline_item_class_name( $node );

		foreach ( $this->direct_timeline_item_elements( $node ) as $item ) {
			$item_child_blocks = $this->timeline_item_child_blocks( $item );

			if ( empty( $item_child_blocks ) || $this->contains_classic_block( $item_child_blocks ) ) {
				return $this->classic_block( trim( $this->outer_html( $node ) ) );
			}

			$item_blocks[] = $this->timeline_item_to_group_block( $item, $item_class, $item_child_blocks );
		}

		$class_name = $this->timeline_wrapper_class_name( $node );
		$attributes = array( 'className' => $class_name );
		$html_attrs = array( 'class' => 'wp-block-group ' . $class_name );
		$anchor     = $this->element_attribute( $node, 'id' );
		$align      = $this->wide_or_full_alignment( $node );

		if ( '' !== $anchor ) {
			$attributes['anchor'] = $anchor;
			$html_attrs['id']     = $anchor;
		}

		if ( '' !== $align ) {
			$attributes['align']  = $align;
			$html_attrs['class'] .= ' align' . $align;
		}

		return $this->block_open_comment( 'group', $attributes ) . "\n"
			. '<div' . $this->html_attributes( $html_attrs ) . '>' . "\n"
			. implode( "\n\n", $item_blocks ) . "\n"
			. '</div>' . "\n"
			. '<!-- /wp:group -->';
	}

	/**
	 * Converts one timeline item into a nested Group block.
	 *
	 * @param \DOMElement       $item       Timeline item element.
	 * @param string            $class_name Stable item class name.
	 * @param array<int,string> $blocks     Nested item blocks.
	 * @return string
	 */
	private function timeline_item_to_group_block( \DOMElement $item, $class_name, array $blocks ) {
		$attributes = array( 'className' => $class_name );
		$html_attrs = array( 'class' => 'wp-block-group ' . $class_name );
		$anchor     = $this->element_attribute( $item, 'id' );
		$align      = $this->wide_or_full_alignment( $item );

		if ( '' !== $anchor ) {
			$attributes['anchor'] = $anchor;
			$html_attrs['id']     = $anchor;
		}

		if ( '' !== $align ) {
			$attributes['align']  = $align;
			$html_attrs['class'] .= ' align' . $align;
		}

		return $this->block_open_comment( 'group', $attributes ) . "\n"
			. '<div' . $this->html_attributes( $html_attrs ) . '>' . "\n"
			. implode( "\n\n", $blocks ) . "\n"
			. '</div>' . "\n"
			. '<!-- /wp:group -->';
	}

	/**
	 * Converts direct timeline item children to blocks.
	 *
	 * @param \DOMElement $item Timeline item.
	 * @return array<int,string>
	 */
	private function timeline_item_child_blocks( \DOMElement $item ) {
		$blocks = array();

		foreach ( $item->childNodes as $child ) {
			if ( XML_TEXT_NODE === $child->nodeType && '' === trim( (string) $child->textContent ) ) {
				continue;
			}

			if ( $child instanceof \DOMElement && 'time' === strtolower( $child->nodeName ) ) {
				$blocks[] = $this->timeline_time_to_paragraph_block( $child );
				continue;
			}

			foreach ( $this->node_to_blocks( $child ) as $block ) {
				if ( '' !== $block ) {
					$blocks[] = $block;
				}
			}
		}

		return $blocks;
	}

	/**
	 * Converts a direct time marker into a styled paragraph block.
	 *
	 * @param \DOMElement $time Time element.
	 * @return string
	 */
	private function timeline_time_to_paragraph_block( \DOMElement $time ) {
		return '<!-- wp:paragraph {"className":"universal-importer-timeline-marker"} -->' . "\n"
			. '<p class="universal-importer-timeline-marker">' . $this->outer_html( $time ) . '</p>' . "\n"
			. '<!-- /wp:paragraph -->';
	}

	/**
	 * Returns direct timeline/step child elements, or an empty list when mixed.
	 *
	 * @param \DOMElement $node Timeline wrapper.
	 * @return array<int,\DOMElement>
	 */
	private function direct_timeline_item_elements( \DOMElement $node ) {
		$items = array();

		foreach ( $node->childNodes as $child ) {
			if ( XML_TEXT_NODE === $child->nodeType && '' === trim( (string) $child->textContent ) ) {
				continue;
			}

			if ( $child instanceof \DOMElement && 'br' === strtolower( $child->nodeName ) ) {
				continue;
			}

			if ( ! $child instanceof \DOMElement || ! $this->is_timeline_item_candidate( $child ) ) {
				return array();
			}

			$items[] = $child;
		}

		return $items;
	}

	/**
	 * Returns whether an element is clearly a timeline/step item.
	 *
	 * @param \DOMElement $node Possible item.
	 * @return bool
	 */
	private function is_timeline_item_candidate( \DOMElement $node ) {
		if ( 'li' === strtolower( $node->nodeName ) ) {
			return true;
		}

		return $this->has_any_class_token(
			$node,
			array(
				'milestone',
				'process-step',
				'roadmap-item',
				'step',
				'steps-item',
				'timeline-item',
			)
		);
	}

	/**
	 * Builds the stable class name for a timeline or steps wrapper.
	 *
	 * @param \DOMElement $node Timeline wrapper.
	 * @return string
	 */
	private function timeline_wrapper_class_name( \DOMElement $node ) {
		return $this->is_steps_wrapper( $node ) ? 'universal-importer-steps' : 'universal-importer-timeline';
	}

	/**
	 * Builds the stable class name for timeline or steps items.
	 *
	 * @param \DOMElement $node Timeline wrapper.
	 * @return string
	 */
	private function timeline_item_class_name( \DOMElement $node ) {
		return $this->is_steps_wrapper( $node ) ? 'universal-importer-step-item' : 'universal-importer-timeline-item';
	}

	/**
	 * Returns whether an imported timeline wrapper is specifically step-like.
	 *
	 * @param \DOMElement $node Timeline wrapper.
	 * @return bool
	 */
	private function is_steps_wrapper( \DOMElement $node ) {
		return $this->has_any_class_token( $node, array( 'steps', 'process' ) )
			|| $this->has_class_token_with_prefix( $node, 'steps-' )
			|| $this->has_class_token_with_prefix( $node, 'process-' );
	}

	/**
	 * Returns whether a node is an explicit gallery container.
	 *
	 * @param \DOMNode $node DOM node.
	 * @return bool
	 */
	private function is_gallery_candidate( \DOMNode $node ) {
		if ( ! $node instanceof \DOMElement || $this->node_contains_tag( $node, 'table' ) ) {
			return false;
		}

		if ( ! $this->has_any_class_token( $node, array( 'gallery', 'wp-block-gallery', 'blocks-gallery-grid' ) ) ) {
			return false;
		}

		return 2 <= $node->getElementsByTagName( 'img' )->length;
	}

	/**
	 * Returns whether a node is an obvious legacy/native columns wrapper.
	 *
	 * @param \DOMNode $node DOM node.
	 * @return bool
	 */
	private function is_columns_candidate( \DOMNode $node ) {
		if ( ! $node instanceof \DOMElement ) {
			return false;
		}

		if (
			! $this->has_any_class_token( $node, array( 'wp-block-columns', 'columns', 'row', 'layout-columns' ) )
			&& ! $this->has_class_token_with_prefix( $node, 'columns-' )
			&& ! $this->is_card_grid_wrapper_candidate( $node )
		) {
			return false;
		}

		return 2 <= count( $this->direct_column_elements( $node ) );
	}

	/**
	 * Converts an obvious columns wrapper into native Columns/Column blocks.
	 *
	 * @param \DOMNode $node Columns wrapper node.
	 * @return string
	 */
	private function columns_to_block( \DOMNode $node ) {
		$columns = array();

		foreach ( $this->direct_column_elements( $node ) as $column ) {
			$preserve_column_anchor = ! $this->is_group_wrapper_candidate( $column );
			$column_blocks          = ! $preserve_column_anchor
				? array( $this->group_wrapper_to_block( $column ) )
				: $this->child_nodes_to_blocks( $column );

			if ( empty( $column_blocks ) || $this->contains_classic_block( $column_blocks ) ) {
				return $this->classic_block( trim( $this->outer_html( $node ) ) );
			}

			$columns[] = $this->column_to_block( $column, $column_blocks, $preserve_column_anchor );
		}

		$attributes = array();
		$html_attrs = array( 'class' => 'wp-block-columns' );

		if ( $node instanceof \DOMElement ) {
			$align  = $this->image_alignment( $node );
			$anchor = $this->element_attribute( $node, 'id' );

			if ( in_array( $align, array( 'wide', 'full' ), true ) ) {
				$attributes['align']  = $align;
				$html_attrs['class'] .= ' align' . $align;
			}

			if ( '' !== $anchor ) {
				$attributes['anchor'] = $anchor;
				$html_attrs['id']     = $anchor;
			}
		}

		return $this->block_open_comment( 'columns', $attributes ) . "\n"
			. '<div' . $this->html_attributes( $html_attrs ) . '>' . implode( "\n", $columns ) . '</div>' . "\n"
			. '<!-- /wp:columns -->';
	}

	/**
	 * Returns whether a wrapper should be preserved as an editable Group block.
	 *
	 * @param \DOMNode $node DOM node.
	 * @return bool
	 */
	private function is_group_wrapper_candidate( \DOMNode $node ) {
		if ( ! $node instanceof \DOMElement ) {
			return false;
		}

		if ( ! in_array( strtolower( $node->nodeName ), array( 'aside', 'article', 'div', 'section' ), true ) ) {
			return false;
		}

		return '' !== $this->group_wrapper_class_name( $node );
	}

	/**
	 * Converts a callout/card-style wrapper into a native Group block.
	 *
	 * @param \DOMNode $node Wrapper node.
	 * @return string
	 */
	private function group_wrapper_to_block( \DOMNode $node ) {
		if ( ! $node instanceof \DOMElement ) {
			return $this->classic_block( trim( $this->outer_html( $node ) ) );
		}

		$child_blocks = $this->child_nodes_to_blocks( $node );

		if ( empty( $child_blocks ) || $this->contains_classic_block( $child_blocks ) ) {
			return $this->classic_block( trim( $this->outer_html( $node ) ) );
		}

		$class_name = $this->group_wrapper_class_name( $node );
		$attributes = array( 'className' => $class_name );
		$html_attrs = array( 'class' => 'wp-block-group ' . $class_name );
		$anchor     = $this->element_attribute( $node, 'id' );
		$align      = $this->wide_or_full_alignment( $node );

		if ( '' !== $anchor ) {
			$attributes['anchor'] = $anchor;
			$html_attrs['id']     = $anchor;
		}

		if ( '' !== $align ) {
			$attributes['align']  = $align;
			$html_attrs['class'] .= ' align' . $align;
		}

		return $this->block_open_comment( 'group', $attributes ) . "\n"
			. '<div' . $this->html_attributes( $html_attrs ) . '>' . "\n"
			. implode( "\n\n", $child_blocks ) . "\n"
			. '</div>' . "\n"
			. '<!-- /wp:group -->';
	}

	/**
	 * Builds the stable custom class name for a callout/card wrapper.
	 *
	 * @param \DOMElement $node Wrapper element.
	 * @return string
	 */
	private function group_wrapper_class_name( \DOMElement $node ) {
		if ( $this->is_callout_wrapper_candidate( $node ) ) {
			$classes = array( 'universal-importer-callout' );
			$tone    = $this->callout_tone( $node );

			if ( '' !== $tone ) {
				$classes[] = 'universal-importer-callout-' . $tone;
			}

			return implode( ' ', $classes );
		}

		if ( $this->is_card_wrapper_candidate( $node ) ) {
			return 'universal-importer-card';
		}

		return '';
	}

	/**
	 * Returns whether a wrapper has explicit callout/notice/alert semantics.
	 *
	 * @param \DOMElement $node Wrapper element.
	 * @return bool
	 */
	private function is_callout_wrapper_candidate( \DOMElement $node ) {
		return $this->has_any_class_token(
			$node,
			array(
				'alert',
				'callout',
				'notice',
				'note',
				'tip',
				'warning',
				'important',
				'wp-block-notice',
			)
		)
			|| $this->has_class_token_with_prefix( $node, 'alert-' )
			|| $this->has_class_token_with_prefix( $node, 'callout-' )
			|| $this->has_class_token_with_prefix( $node, 'notice-' );
	}

	/**
	 * Returns whether a wrapper is explicitly card-like.
	 *
	 * @param \DOMElement $node Wrapper element.
	 * @return bool
	 */
	private function is_card_wrapper_candidate( \DOMElement $node ) {
		return $this->has_any_class_token(
			$node,
			array(
				'card',
				'content-card',
				'feature-card',
				'price-card',
				'pricing-card',
				'pricing-plan',
				'comparison-card',
				'plan',
				'plan-card',
				'tier',
				'tier-card',
				'panel',
				'box',
			)
		);
	}

	/**
	 * Infers a stable callout tone from common imported CSS class names.
	 *
	 * @param \DOMElement $node Wrapper element.
	 * @return string
	 */
	private function callout_tone( \DOMElement $node ) {
		$tones = array(
			'warning',
			'danger',
			'error',
			'success',
			'info',
			'note',
			'tip',
			'important',
		);

		foreach ( $this->class_tokens( $node ) as $class ) {
			foreach ( $tones as $tone ) {
				if ( $class === $tone || false !== strpos( $class, '-' . $tone ) ) {
					return 'danger' === $tone ? 'error' : $tone;
				}
			}
		}

		return '';
	}

	/**
	 * Converts one direct column child into a native Column block.
	 *
	 * @param \DOMElement       $column Column element.
	 * @param array<int,string> $blocks Nested block markup.
	 * @param bool              $preserve_anchor Whether to move the source id to the Column wrapper.
	 * @return string
	 */
	private function column_to_block( \DOMElement $column, array $blocks, $preserve_anchor = true ) {
		$width      = $this->column_width( $column );
		$anchor     = $preserve_anchor ? $this->element_attribute( $column, 'id' ) : '';
		$attributes = array();
		$div_attrs  = array( 'class' => 'wp-block-column' );

		if ( '' !== $width ) {
			$attributes['width'] = $width;
			$div_attrs['style']  = 'flex-basis:' . $width;
		}

		if ( '' !== $anchor ) {
			$attributes['anchor'] = $anchor;
			$div_attrs['id']      = $anchor;
		}

		return $this->block_open_comment( 'column', $attributes ) . "\n"
			. '<div' . $this->html_attributes( $div_attrs ) . '>' . "\n"
			. implode( "\n\n", $blocks ) . "\n"
			. '</div>' . "\n"
			. '<!-- /wp:column -->';
	}

	/**
	 * Returns direct child elements that are clearly columns, or an empty list.
	 *
	 * @param \DOMNode $node Columns wrapper node.
	 * @return array<int,\DOMElement>
	 */
	private function direct_column_elements( \DOMNode $node ) {
		$columns      = array();
		$is_card_grid = $node instanceof \DOMElement && $this->is_card_grid_wrapper_candidate( $node );

		foreach ( $node->childNodes as $child ) {
			if ( XML_TEXT_NODE === $child->nodeType && '' === trim( (string) $child->textContent ) ) {
				continue;
			}

			if ( $child instanceof \DOMElement && 'br' === strtolower( $child->nodeName ) ) {
				continue;
			}

			if (
				! $child instanceof \DOMElement
				|| ( ! $this->is_column_child_candidate( $child ) && ( ! $is_card_grid || ! $this->is_card_grid_child_candidate( $child ) ) )
			) {
				return array();
			}

			$columns[] = $child;
		}

		return $columns;
	}

	/**
	 * Returns whether a wrapper is clearly a card/pricing/comparison grid.
	 *
	 * @param \DOMElement $node Wrapper element.
	 * @return bool
	 */
	private function is_card_grid_wrapper_candidate( \DOMElement $node ) {
		return $this->has_any_class_token(
			$node,
			array(
				'card-grid',
				'cards',
				'comparison',
				'comparison-grid',
				'feature-grid',
				'features-grid',
				'package-grid',
				'packages',
				'plan-grid',
				'plans',
				'pricing',
				'pricing-grid',
				'pricing-table',
				'tier-grid',
				'tiers',
			)
		)
			|| $this->has_class_token_with_prefix( $node, 'cards-' )
			|| $this->has_class_token_with_prefix( $node, 'comparison-' )
			|| $this->has_class_token_with_prefix( $node, 'features-' )
			|| $this->has_class_token_with_prefix( $node, 'pricing-' );
	}

	/**
	 * Returns whether a direct card-grid child should become a Column.
	 *
	 * @param \DOMElement $node Possible card-grid child.
	 * @return bool
	 */
	private function is_card_grid_child_candidate( \DOMElement $node ) {
		return $this->is_card_wrapper_candidate( $node ) || $this->is_column_child_candidate( $node );
	}

	/**
	 * Returns whether a direct child element is clearly a column.
	 *
	 * @param \DOMElement $node Possible column element.
	 * @return bool
	 */
	private function is_column_child_candidate( \DOMElement $node ) {
		if ( $this->has_any_class_token( $node, array( 'wp-block-column', 'column', 'columns', 'col' ) ) ) {
			return true;
		}

		foreach ( $this->class_tokens( $node ) as $class ) {
			if (
				preg_match( '/^col(?:-(?:xs|sm|md|lg|xl|xxl))?-(?:auto|\d{1,2})$/', $class )
				|| preg_match( '/^(?:small|medium|large)-\d{1,2}$/', $class )
			) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Infers a column width from common Bootstrap/Foundation/legacy classes.
	 *
	 * @param \DOMElement $node Column element.
	 * @return string
	 */
	private function column_width( \DOMElement $node ) {
		$style_width = $this->column_width_from_style( $this->element_attribute( $node, 'style' ) );

		if ( '' !== $style_width ) {
			return $style_width;
		}

		$legacy = array(
			'one-half'       => '50%',
			'one-third'      => '33.33%',
			'two-thirds'     => '66.67%',
			'one-fourth'     => '25%',
			'one-quarter'    => '25%',
			'three-fourths'  => '75%',
			'three-quarters' => '75%',
		);

		foreach ( $this->class_tokens( $node ) as $class ) {
			if ( isset( $legacy[ $class ] ) ) {
				return $legacy[ $class ];
			}

			if ( preg_match( '/^col(?:-(?:xs|sm|md|lg|xl|xxl))?-(\d{1,2})$/', $class, $matches ) ) {
				return $this->grid_width_to_percent( (int) $matches[1] );
			}

			if ( preg_match( '/^(?:small|medium|large)-(\d{1,2})$/', $class, $matches ) ) {
				return $this->grid_width_to_percent( (int) $matches[1] );
			}
		}

		return '';
	}

	/**
	 * Extracts a percent flex-basis/width value from inline styles.
	 *
	 * @param string $style Style attribute.
	 * @return string
	 */
	private function column_width_from_style( $style ) {
		if ( preg_match( '/(?:flex-basis|width)\s*:\s*([0-9]+(?:\.[0-9]+)?%)/i', (string) $style, $matches ) ) {
			return $matches[1];
		}

		return '';
	}

	/**
	 * Converts a 12-column grid unit into a percentage width.
	 *
	 * @param int $span Grid span.
	 * @return string
	 */
	private function grid_width_to_percent( $span ) {
		if ( $span < 1 || $span > 12 ) {
			return '';
		}

		$percent = round( ( $span / 12 ) * 100, 2 );

		if ( (float) (int) $percent === $percent ) {
			return (string) (int) $percent . '%';
		}

		return rtrim( rtrim( sprintf( '%.2F', $percent ), '0' ), '.' ) . '%';
	}

	/**
	 * Splits paragraph content around obvious inline media or download blocks.
	 *
	 * @param \DOMNode $node Paragraph node.
	 * @return array<int,string>|null
	 */
	private function paragraph_inline_children_to_blocks( \DOMNode $node ) {
		$blocks    = array();
		$inline    = '';
		$converted = false;

		foreach ( $node->childNodes as $child ) {
			if ( XML_TEXT_NODE === $child->nodeType || XML_CDATA_SECTION_NODE === $child->nodeType ) {
				$inline .= $this->outer_html( $child );
				continue;
			}

			if ( XML_ELEMENT_NODE !== $child->nodeType || ! $child instanceof \DOMElement ) {
				continue;
			}

			$name = strtolower( $child->nodeName );

			if ( 'img' === $name ) {
				$this->flush_paragraph_inline_html( $blocks, $inline );
				$blocks[]  = $this->image_to_block( $child );
				$converted = true;
				continue;
			}

			if ( 'a' === $name && $this->is_button_link_candidate( $child ) ) {
				$this->flush_paragraph_inline_html( $blocks, $inline );
				$blocks[]  = $this->link_to_button_block( $child );
				$converted = true;
				continue;
			}

			if ( 'a' === $name && $this->is_file_link_candidate( $child ) ) {
				$this->flush_paragraph_inline_html( $blocks, $inline );
				$blocks[]  = $this->link_to_file_block( $child );
				$converted = true;
				continue;
			}

			if ( 'a' === $name ) {
				$linked_image = $this->single_direct_child_element_by_tag( $child, 'img' );

				if ( null !== $linked_image ) {
					$this->flush_paragraph_inline_html( $blocks, $inline );
					$blocks[]  = $this->image_to_block( $linked_image, null, $child );
					$converted = true;
					continue;
				}
			}

			if ( ! $this->is_inline_paragraph_element( $child ) ) {
				return null;
			}

			$inline .= $this->outer_html( $child );
		}

		if ( ! $converted ) {
			return null;
		}

		$this->flush_paragraph_inline_html( $blocks, $inline );

		return $blocks;
	}

	/**
	 * Appends buffered inline paragraph HTML as a paragraph block.
	 *
	 * @param array<int,string> $blocks Block list.
	 * @param string            $inline Inline HTML buffer.
	 * @return void
	 */
	private function flush_paragraph_inline_html( array &$blocks, &$inline ) {
		$inline = trim( (string) $inline );

		if ( '' !== $inline && $this->has_visible_inline_content( $inline ) ) {
			$blocks[] = '<!-- wp:paragraph -->' . "\n"
				. '<p>' . $inline . '</p>' . "\n"
				. '<!-- /wp:paragraph -->';
		}

		$inline = '';
	}

	/**
	 * Returns whether inline HTML has visible content worth preserving.
	 *
	 * @param string $html Inline HTML.
	 * @return bool
	 */
	private function has_visible_inline_content( $html ) {
		$text = preg_replace( '/<[^>]+>/', '', (string) $html );
		$text = trim( preg_replace( '/(?:\s|&nbsp;)+/i', '', is_string( $text ) ? $text : '' ) );

		return '' !== $text || (bool) preg_match( '/<br\b/i', (string) $html );
	}

	/**
	 * Returns whether an element can remain inside a generated paragraph.
	 *
	 * @param \DOMElement $node Element.
	 * @return bool
	 */
	private function is_inline_paragraph_element( \DOMElement $node ) {
		return in_array(
			strtolower( $node->nodeName ),
			array(
				'a',
				'abbr',
				'acronym',
				'b',
				'bdi',
				'bdo',
				'big',
				'br',
				'canvas',
				'cite',
				'code',
				'data',
				'del',
				'dfn',
				'em',
				'i',
				'ins',
				'kbd',
				'label',
				'mark',
				'meter',
				'output',
				'progress',
				'q',
				'rp',
				'rt',
				'ruby',
				's',
				'samp',
				'small',
				'strike',
				'span',
				'strong',
				'sub',
				'sup',
				'tt',
				'time',
				'u',
				'var',
				'wbr',
			),
			true
		);
	}

	/**
	 * Converts an explicit gallery container into a gallery block.
	 *
	 * @param \DOMNode    $node         Gallery node.
	 * @param string|null $caption_html Optional caption HTML.
	 * @return string
	 */
	private function gallery_to_block( \DOMNode $node, $caption_html = null ) {
		$columns      = $this->gallery_column_count( $node );
		$size_slug    = $this->gallery_size_slug( $node );
		$image_blocks = $this->gallery_image_blocks( $node, $size_slug );
		$align        = $this->image_alignment( $node instanceof \DOMElement ? $node : null );
		$anchor       = $node instanceof \DOMElement ? $this->element_attribute( $node, 'id' ) : '';

		if ( null === $image_blocks || count( $image_blocks ) < 2 ) {
			return $this->classic_block( trim( $this->outer_html( $node ) ) );
		}

		$attributes = array();

		if ( null !== $columns ) {
			$attributes['columns'] = $columns;
		}

		$attributes['linkTo'] = 'none';

		if ( '' !== $align ) {
			$attributes['align'] = $align;
		}

		if ( '' !== $anchor ) {
			$attributes['anchor'] = $anchor;
		}

		$figure_attrs = array(
			'class' => 'wp-block-gallery has-nested-images ' . ( null === $columns ? 'columns-default' : 'columns-' . $columns ) . ' is-cropped',
		);

		if ( '' !== $align ) {
			$figure_attrs['class'] .= ' align' . $align;
		}

		if ( '' !== $anchor ) {
			$figure_attrs['id'] = $anchor;
		}

		return $this->block_open_comment( 'gallery', $attributes ) . "\n"
			. '<figure' . $this->html_attributes( $figure_attrs ) . '>' . "\n"
			. implode( "\n", $image_blocks ) . "\n"
			. $this->gallery_caption_to_html( $caption_html )
			. '</figure>' . "\n"
			. '<!-- /wp:gallery -->';
	}

	/**
	 * Converts gallery caption HTML into the native gallery caption element.
	 *
	 * @param string|null $caption_html Caption HTML.
	 * @return string
	 */
	private function gallery_caption_to_html( $caption_html ) {
		$caption_html = trim( (string) $caption_html );

		if ( '' === $caption_html ) {
			return '';
		}

		return '<figcaption class="blocks-gallery-caption wp-element-caption">' . $caption_html . '</figcaption>' . "\n";
	}

	/**
	 * Returns a safe gallery column count from legacy WordPress classes.
	 *
	 * @param \DOMNode $node Gallery node.
	 * @return int|null
	 */
	private function gallery_column_count( \DOMNode $node ) {
		if ( ! $node instanceof \DOMElement ) {
			return null;
		}

		foreach ( $this->class_tokens( $node ) as $class ) {
			if ( 1 === preg_match( '/^(?:gallery-)?columns-([1-8])$/', $class, $matches ) ) {
				return (int) $matches[1];
			}
		}

		return null;
	}

	/**
	 * Returns a safe nested Image block size from legacy WordPress gallery classes.
	 *
	 * @param \DOMNode $node Gallery node.
	 * @return string
	 */
	private function gallery_size_slug( \DOMNode $node ) {
		if ( ! $node instanceof \DOMElement ) {
			return 'large';
		}

		foreach ( $this->class_tokens( $node ) as $class ) {
			if ( 1 !== preg_match( '/^gallery-size-([A-Za-z0-9_-]+)$/', $class, $matches ) ) {
				continue;
			}

			$size = strtolower( $matches[1] );

			if ( in_array( $size, array( 'thumbnail', 'medium', 'large', 'full' ), true ) ) {
				return $size;
			}
		}

		return 'large';
	}

	/**
	 * Converts direct gallery children into nested image blocks.
	 *
	 * @param \DOMNode $node Gallery node.
	 * @param string   $size_slug Nested Image block size slug.
	 * @return array<int,string>|null Null when direct non-gallery content would be dropped.
	 */
	private function gallery_image_blocks( \DOMNode $node, $size_slug ) {
		$blocks = array();

		foreach ( $node->childNodes as $child ) {
			if ( XML_TEXT_NODE === $child->nodeType && '' === trim( (string) $child->textContent ) ) {
				continue;
			}

			if ( ! $child instanceof \DOMElement ) {
				continue;
			}

			$block = $this->gallery_child_to_image_block( $child, $size_slug );

			if ( null !== $block ) {
				$blocks[] = $block;
				continue;
			}

			if ( 'br' === strtolower( $child->nodeName ) ) {
				continue;
			}

			return null;
		}

		return $blocks;
	}

	/**
	 * Converts one gallery child into a nested image block.
	 *
	 * @param \DOMElement $node Gallery child.
	 * @param string      $size_slug Nested Image block size slug.
	 * @return string|null
	 */
	private function gallery_child_to_image_block( \DOMElement $node, $size_slug ) {
		$image = 'img' === strtolower( $node->nodeName ) ? $node : $this->first_descendant_by_tag( $node, 'img' );

		if ( null === $image ) {
			return null;
		}

		$visual = $this->outer_html( $image );
		$link   = $this->image_parent_link( $image, $node );

		if ( null !== $link ) {
			$visual = $this->normalize_link_rel_attribute_in_html( $this->outer_html( $link ), $this->safe_link_rel( $link ) );
		}

		$caption = $this->direct_child_inner_html( $node, 'figcaption' );

		if ( null === $caption ) {
			$caption = $this->first_descendant_inner_html_by_class( $node, 'gallery-caption' );
		}

		$attributes             = $this->image_block_attributes( $link );
		$attributes['sizeSlug'] = $size_slug;

		if ( ! isset( $attributes['linkDestination'] ) ) {
			$attributes['linkDestination'] = 'none';
		}

		return $this->block_open_comment( 'image', $attributes ) . "\n"
			. '<figure class="wp-block-image size-' . $this->escape_html( $size_slug ) . '">' . $visual . $this->caption_to_html( $caption ) . '</figure>' . "\n"
			. '<!-- /wp:image -->';
	}

	/**
	 * Returns node inner HTML with direct figcaptions normalized for block markup.
	 *
	 * @param \DOMNode $node Figure node.
	 * @return string
	 */
	private function figure_inner_html_with_caption_class( \DOMNode $node ) {
		$html = '';

		foreach ( $node->childNodes as $child ) {
			if ( $child instanceof \DOMElement && 'figcaption' === strtolower( $child->nodeName ) ) {
				$html .= $this->caption_to_html( $this->inner_html( $child ) );
				continue;
			}

			if ( $child instanceof \DOMElement && 'a' === strtolower( $child->nodeName ) ) {
				$html .= $this->normalize_picture_source_html( $this->normalize_link_rel_attribute_in_html( $this->outer_html( $child ), $this->safe_link_rel( $child ) ) );
				continue;
			}

			if ( $child instanceof \DOMElement && 'picture' === strtolower( $child->nodeName ) ) {
				$html .= $this->normalize_picture_source_html( $this->outer_html( $child ) );
				continue;
			}

			$html .= $this->outer_html( $child );
		}

		return trim( $html );
	}

	/**
	 * Returns table contents without a direct caption element.
	 *
	 * @param \DOMNode $node Table node.
	 * @return string
	 */
	private function table_inner_html_without_caption( \DOMNode $node ) {
		$html = '';

		foreach ( $node->childNodes as $child ) {
			if ( $child instanceof \DOMElement && 'caption' === strtolower( $child->nodeName ) ) {
				continue;
			}

			$html .= $this->outer_html( $child );
		}

		return trim( $html );
	}

	/**
	 * Returns a direct child element's inner HTML by tag name.
	 *
	 * @param \DOMNode $node DOM node.
	 * @param string   $tag  Child tag.
	 * @return string|null
	 */
	private function direct_child_inner_html( \DOMNode $node, $tag ) {
		foreach ( $node->childNodes as $child ) {
			if ( $child instanceof \DOMElement && strtolower( $child->nodeName ) === strtolower( (string) $tag ) ) {
				return trim( $this->inner_html( $child ) );
			}
		}

		return null;
	}

	/**
	 * Returns the first descendant by tag name.
	 *
	 * @param \DOMNode $node DOM node.
	 * @param string   $tag  Tag name.
	 * @return \DOMElement|null
	 */
	private function first_descendant_by_tag( \DOMNode $node, $tag ) {
		if ( ! $node instanceof \DOMElement ) {
			return null;
		}

		$matches = $node->getElementsByTagName( strtolower( (string) $tag ) );

		return 0 < $matches->length ? $matches->item( 0 ) : null;
	}

	/**
	 * Builds attributes for audio/video block comments from the media element.
	 *
	 * @param \DOMNode $node Media element.
	 * @return array<string,mixed>
	 */
	private function media_block_attributes( \DOMNode $node ) {
		$attributes = array();
		$src        = $this->media_element_source_url( $node );

		if ( '' !== $src ) {
			$attributes['src'] = $src;
		}

		foreach ( array( 'autoplay', 'controls', 'loop', 'muted' ) as $name ) {
			if ( $node instanceof \DOMElement && $node->hasAttribute( $name ) ) {
				$attributes[ $name ] = true;
			}
		}

		if ( $node instanceof \DOMElement && ( $node->hasAttribute( 'playsinline' ) || $node->hasAttribute( 'webkit-playsinline' ) ) ) {
			$attributes['playsInline'] = true;
		}

		$poster = $this->element_attribute( $node, 'poster' );
		if ( '' !== $poster ) {
			$attributes['poster'] = $poster;
		}

		$preload = $this->media_preload_value( $node );
		if ( '' !== $preload ) {
			$attributes['preload'] = $preload;
		}

		return $attributes;
	}

	/**
	 * Returns a safe normalized preload value for native media blocks.
	 *
	 * @param \DOMNode $node Media element.
	 * @return string
	 */
	private function media_preload_value( \DOMNode $node ) {
		$preload = strtolower( $this->element_attribute( $node, 'preload' ) );

		return in_array( $preload, array( 'auto', 'metadata', 'none' ), true ) ? $preload : '';
	}

	/**
	 * Normalizes or removes media preload attributes in saved media markup.
	 *
	 * @param string $html Serialized media HTML.
	 * @return string
	 */
	private function normalize_media_preload_attribute_in_html( $html ) {
		$html = preg_replace_callback(
			'/\s+preload=(["\'])(.*?)\1/i',
			function ( $matches ) {
				$preload = strtolower( trim( $matches[2] ) );

				return in_array( $preload, array( 'auto', 'metadata', 'none' ), true ) ? ' preload="' . $preload . '"' : '';
			},
			(string) $html
		);

		return is_string( $html ) ? $html : '';
	}

	/**
	 * Normalizes legacy inline-play video hints to core Video markup.
	 *
	 * @param string $html Serialized media HTML.
	 * @return string
	 */
	private function normalize_media_playsinline_attribute_in_html( $html ) {
		$html                 = (string) $html;
		$has_playsinline      = 1 === preg_match( '/\s+playsinline(?:\s*=\s*(?:"[^"]*"|\'[^\']*\'|[^\s>]+))?/i', $html );
		$had_webkit_attribute = 1 === preg_match( '/\s+webkit-playsinline(?:\s*=\s*(?:"[^"]*"|\'[^\']*\'|[^\s>]+))?/i', $html );

		$html = preg_replace( '/\s+webkit-playsinline(?:\s*=\s*(?:"[^"]*"|\'[^\']*\'|[^\s>]+))?/i', '', $html );
		$html = is_string( $html ) ? $html : '';

		if ( $had_webkit_attribute && ! $has_playsinline ) {
			$html = preg_replace( '/^<([a-z0-9:-]+)\b/i', '<$1 playsinline', $html );
			$html = is_string( $html ) ? $html : '';
		}

		return $html;
	}

	/**
	 * Normalizes DOMDocument's serialized media/source markup back to HTML void elements.
	 *
	 * @param string $html Serialized media HTML.
	 * @return string
	 */
	private function normalize_media_source_html( $html ) {
		return str_replace( '</source>', '', (string) $html );
	}

	/**
	 * Returns the primary URL from a media element or its first source child.
	 *
	 * @param \DOMNode $node Media element.
	 * @return string
	 */
	private function media_element_source_url( \DOMNode $node ) {
		$src = $this->element_attribute( $node, 'src' );

		if ( '' !== $src ) {
			return $src;
		}

		if ( ! $node instanceof \DOMElement ) {
			return '';
		}

		foreach ( $node->getElementsByTagName( 'source' ) as $source ) {
			$src = $this->element_attribute( $source, 'src' );

			if ( '' !== $src ) {
				return $src;
			}
		}

		return '';
	}

	/**
	 * Returns the known oEmbed provider for a URL.
	 *
	 * @param string $url Source URL.
	 * @return array{slug:string,type:string}|null
	 */
	private function embed_provider_for_url( $url ) {
		$host = strtolower( (string) $this->parse_url_part( (string) $url, PHP_URL_HOST ) );

		if ( '' === $host ) {
			return null;
		}

		if ( 'youtu.be' === $host || preg_match( '/(^|\.)youtube(?:-nocookie)?\.com$/', $host ) ) {
			return array(
				'slug' => 'youtube',
				'type' => 'video',
			);
		}

		if ( preg_match( '/(^|\.)vimeo\.com$/', $host ) ) {
			return array(
				'slug' => 'vimeo',
				'type' => 'video',
			);
		}

		if ( preg_match( '/(^|\.)soundcloud\.com$/', $host ) ) {
			return array(
				'slug' => 'soundcloud',
				'type' => 'rich',
			);
		}

		if ( preg_match( '/(^|\.)spotify\.com$/', $host ) ) {
			return array(
				'slug' => 'spotify',
				'type' => 'rich',
			);
		}

		return null;
	}

	/**
	 * Normalizes provider iframe URLs into canonical embed URLs when possible.
	 *
	 * @param string $url           Source URL.
	 * @param string $provider_slug Provider slug.
	 * @return string
	 */
	private function normalized_embed_url( $url, $provider_slug ) {
		$url  = (string) $url;
		$path = (string) $this->parse_url_part( $url, PHP_URL_PATH );

		if ( 'youtube' === $provider_slug && preg_match( '~/embed/([^/?#]+)~', $path, $matches ) ) {
			return 'https://www.youtube.com/watch?v=' . rawurlencode( $matches[1] );
		}

		if ( 'vimeo' === $provider_slug && preg_match( '#/video/(\d+)#', $path, $matches ) ) {
			return 'https://vimeo.com/' . $matches[1];
		}

		return $url;
	}

	/**
	 * Parses one URL component, preferring WordPress' compatibility wrapper.
	 *
	 * @param string $url       URL.
	 * @param int    $component PHP_URL_* component.
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
	 * Returns a single direct child element when it is the only meaningful child.
	 *
	 * @param \DOMNode $node DOM node.
	 * @param string   $tag  Tag name.
	 * @return \DOMElement|null
	 */
	private function single_direct_child_element_by_tag( \DOMNode $node, $tag ) {
		$element = null;

		foreach ( $node->childNodes as $child ) {
			if ( XML_TEXT_NODE === $child->nodeType && '' === trim( (string) $child->textContent ) ) {
				continue;
			}

			if ( ! $child instanceof \DOMElement || strtolower( $child->nodeName ) !== strtolower( (string) $tag ) ) {
				return null;
			}

			if ( null !== $element ) {
				return null;
			}

			$element = $child;
		}

		return $element;
	}

	/**
	 * Returns an image's parent link when it stays inside the gallery child.
	 *
	 * @param \DOMElement $image     Image element.
	 * @param \DOMElement $container Gallery child container.
	 * @return \DOMElement|null
	 */
	private function image_parent_link( \DOMElement $image, \DOMElement $container ) {
		$parent = $image->parentNode;

		if ( ! $parent instanceof \DOMElement || 'a' !== strtolower( $parent->nodeName ) ) {
			return null;
		}

		return $this->node_contains_node( $container, $parent ) ? $parent : null;
	}

	/**
	 * Returns an image's wrapping link, including links around picture elements.
	 *
	 * @param \DOMElement $image     Image element.
	 * @param \DOMElement $container Containing wrapper.
	 * @return \DOMElement|null
	 */
	private function image_wrapping_link( \DOMElement $image, \DOMElement $container ) {
		$parent = $image->parentNode;

		if ( $parent instanceof \DOMElement && 'a' === strtolower( $parent->nodeName ) && $this->node_contains_node( $container, $parent ) ) {
			return $parent;
		}

		if ( $parent instanceof \DOMElement && 'picture' === strtolower( $parent->nodeName ) ) {
			$grandparent = $parent->parentNode;

			if ( $grandparent instanceof \DOMElement && 'a' === strtolower( $grandparent->nodeName ) && $this->node_contains_node( $container, $grandparent ) ) {
				return $grandparent;
			}
		}

		return null;
	}

	/**
	 * Returns an image's wrapping picture element.
	 *
	 * @param \DOMElement $image     Image element.
	 * @param \DOMElement $container Containing wrapper.
	 * @return \DOMElement|null
	 */
	private function image_wrapping_picture( \DOMElement $image, \DOMElement $container ) {
		$parent = $image->parentNode;

		while ( $parent instanceof \DOMElement && ! $parent->isSameNode( $container ) ) {
			if ( 'picture' === strtolower( $parent->nodeName ) && $this->node_contains_node( $container, $parent ) ) {
				return $parent;
			}

			$parent = $parent->parentNode;
		}

		return null;
	}

	/**
	 * Returns whether one node contains another node.
	 *
	 * @param \DOMNode $container Possible container.
	 * @param \DOMNode $candidate Possible descendant.
	 * @return bool
	 */
	private function node_contains_node( \DOMNode $container, \DOMNode $candidate ) {
		$node = $candidate;

		while ( null !== $node ) {
			if ( $node->isSameNode( $container ) ) {
				return true;
			}

			$node = $node->parentNode;
		}

		return false;
	}

	/**
	 * Returns the first descendant's inner HTML by class token.
	 *
	 * @param \DOMNode $node        DOM node.
	 * @param string   $class_token Class token.
	 * @return string|null
	 */
	private function first_descendant_inner_html_by_class( \DOMNode $node, $class_token ) {
		if ( ! $node instanceof \DOMElement ) {
			return null;
		}

		foreach ( $node->getElementsByTagName( '*' ) as $element ) {
			if ( $this->has_class_token( $element, $class_token ) ) {
				return trim( $this->inner_html( $element ) );
			}
		}

		return null;
	}

	/**
	 * Returns the first descendant matching any class token.
	 *
	 * @param \DOMNode          $node   DOM node.
	 * @param array<int,string> $tokens Class tokens.
	 * @return \DOMElement|null
	 */
	private function first_descendant_by_any_class( \DOMNode $node, array $tokens ) {
		if ( ! $node instanceof \DOMElement ) {
			return null;
		}

		foreach ( $node->getElementsByTagName( '*' ) as $element ) {
			if ( $this->has_any_class_token( $element, $tokens ) ) {
				return $element;
			}
		}

		return null;
	}

	/**
	 * Returns whether an element has any class token from a list.
	 *
	 * @param \DOMElement       $element Element.
	 * @param array<int,string> $tokens Class tokens.
	 * @return bool
	 */
	private function has_any_class_token( \DOMElement $element, array $tokens ) {
		foreach ( $tokens as $token ) {
			if ( $this->has_class_token( $element, $token ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Returns whether an element has a class token with a prefix.
	 *
	 * @param \DOMElement $element Element.
	 * @param string      $prefix  Class prefix.
	 * @return bool
	 */
	private function has_class_token_with_prefix( \DOMElement $element, $prefix ) {
		$prefix = strtolower( (string) $prefix );

		foreach ( $this->class_tokens( $element ) as $class ) {
			if ( 0 === strpos( strtolower( $class ), $prefix ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Returns whether an element has a class token.
	 *
	 * @param \DOMElement $element Element.
	 * @param string      $token   Class token.
	 * @return bool
	 */
	private function has_class_token( \DOMElement $element, $token ) {
		return in_array( strtolower( (string) $token ), array_map( 'strtolower', $this->class_tokens( $element ) ), true );
	}

	/**
	 * Returns normalized class tokens for an element.
	 *
	 * @param \DOMElement $element Element.
	 * @return array<int,string>
	 */
	private function class_tokens( \DOMElement $element ) {
		$classes = preg_split( '/\s+/', trim( $element->getAttribute( 'class' ) ) );

		return array_values( array_filter( is_array( $classes ) ? $classes : array(), 'strlen' ) );
	}

	/**
	 * Converts caption HTML into the block caption element.
	 *
	 * @param string|null $caption_html Caption HTML.
	 * @return string
	 */
	private function caption_to_html( $caption_html ) {
		$caption_html = trim( (string) $caption_html );

		if ( '' === $caption_html ) {
			return '';
		}

		return '<figcaption class="wp-element-caption">' . $caption_html . '</figcaption>';
	}

	/**
	 * Returns a basename suitable for the File block metadata.
	 *
	 * @param string $url File URL or path.
	 * @return string
	 */
	private function file_name_from_url( $url ) {
		$path = (string) $this->parse_url_part( (string) $url, PHP_URL_PATH );
		$name = rawurldecode( basename( $path ) );
		$name = preg_replace( '/[\/\\\\<>:"|?*\x00-\x1F]+/', '-', $name );
		$name = trim( $name, ".- \t\n\r\0\x0B" );

		return is_string( $name ) ? $name : '';
	}

	/**
	 * Serializes HTML attributes.
	 *
	 * @param array<string,string> $attributes Attributes.
	 * @return string
	 */
	private function html_attributes( array $attributes ) {
		$html = '';

		foreach ( $attributes as $name => $value ) {
			if ( '' === $value ) {
				continue;
			}

			$html .= ' ' . $name . '="' . $this->escape_html( $value ) . '"';
		}

		return $html;
	}

	/**
	 * Builds a Custom HTML block.
	 *
	 * @param string $content HTML content.
	 * @return string
	 */
	private function custom_html_block( $content ) {
		return "<!-- wp:html -->\n" . trim( (string) $content ) . "\n<!-- /wp:html -->";
	}

	/**
	 * Builds a block opening comment, including attributes when present.
	 *
	 * @param string              $name       Block name without namespace.
	 * @param array<string,mixed> $attributes Block attributes.
	 * @return string
	 */
	private function block_open_comment( $name, array $attributes = array() ) {
		if ( empty( $attributes ) ) {
			return '<!-- wp:' . $name . ' -->';
		}

		return '<!-- wp:' . $name . ' ' . $this->encode_block_attributes( $attributes ) . ' -->';
	}

	/**
	 * Returns one element attribute value.
	 *
	 * @param \DOMNode $node DOM node.
	 * @param string   $name Attribute name.
	 * @return string
	 */
	private function element_attribute( \DOMNode $node, $name ) {
		if ( ! $node instanceof \DOMElement ) {
			return '';
		}

		return trim( $node->getAttribute( (string) $name ) );
	}

	/**
	 * Encodes block comment attributes.
	 *
	 * @param array<string,mixed> $attributes Block attributes.
	 * @return string
	 */
	private function encode_block_attributes( array $attributes ) {
		if ( function_exists( 'wp_json_encode' ) ) {
			return (string) wp_json_encode( $attributes );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Unit tests run without WordPress loaded.
		return (string) json_encode( $attributes );
	}

	/**
	 * Returns a node's inner HTML.
	 *
	 * @param \DOMNode $node DOM node.
	 * @return string
	 */
	private function inner_html( \DOMNode $node ) {
		$html = '';

		foreach ( $node->childNodes as $child ) {
			$html .= $this->outer_html( $child );
		}

		return $html;
	}

	/**
	 * Returns a node's outer HTML.
	 *
	 * @param \DOMNode $node DOM node.
	 * @return string
	 */
	private function outer_html( \DOMNode $node ) {
		$html = $node->ownerDocument instanceof \DOMDocument ? $node->ownerDocument->saveHTML( $node ) : '';

		return is_string( $html ) ? $html : '';
	}

	/**
	 * Returns whether a node contains a tag name.
	 *
	 * @param \DOMNode $node DOM node.
	 * @param string   $tag  Tag name.
	 * @return bool
	 */
	private function node_contains_tag( \DOMNode $node, $tag ) {
		if ( ! $node instanceof \DOMElement ) {
			return false;
		}

		return 0 < $node->getElementsByTagName( strtolower( (string) $tag ) )->length;
	}

	/**
	 * Escapes text for simple generated HTML.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	private function escape_html( $text ) {
		return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
	}
}
