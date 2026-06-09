<?php
/**
 * Token stream parser for docs-flavored Markdown dialect conventions.
 *
 * @package UniversalImporter
 */

namespace UniversalImporter\Import;

/**
 * Parses and normalizes Markdown-compatible docs dialect constructs.
 */
final class ImportDocsMarkdownDialectParser {
	/**
	 * Normalizes a Markdown document through a docs-dialect token stream.
	 *
	 * @param string              $content Markdown content with normalized newlines.
	 * @param array<string,bool>  $options Parser options.
	 * @return array{content:string,metadata:array<string,int>,tokens:array<int,array<string,mixed>>}
	 */
	public function normalize( $content, $options = array() ) {
		$counts = array(
			'docusaurus_admonitions' => 0,
			'mdx_removed'            => 0,
			'obsidian_wikilinks'     => 0,
			'obsidian_embeds'        => 0,
			'obsidian_callouts'      => 0,
			'markdoc_constructs'     => 0,
		);
		$tokens = $this->tokenize( (string) $content, $options, $counts );
		$output = array();

		foreach ( $tokens as $token ) {
			if ( array_key_exists( 'output', $token ) ) {
				$output[] = (string) $token['output'];
			}
		}

		return array(
			'content'  => implode( "\n", $output ),
			'metadata' => $counts,
			'tokens'   => $tokens,
		);
	}

	/**
	 * Converts source lines into named docs-dialect tokens.
	 *
	 * @param string             $content Markdown content.
	 * @param array<string,bool> $options Parser options.
	 * @param array<string,int>  $counts  Running metadata counters.
	 * @return array<int,array<string,mixed>>
	 */
	public function tokenize( $content, $options = array(), &$counts = array() ) {
		$allow_mdx     = ! empty( $options['allow_mdx'] );
		$allow_markdoc = ! empty( $options['allow_markdoc'] );
		$lines         = explode( "\n", (string) $content );
		$tokens            = array();
		$active_fence      = null;
		$active_html_block = null;
		$mdx_esm_state     = null;
		$admonitions       = array();
		$offset            = 0;

		foreach ( $lines as $line_number => $line ) {
			$line             = (string) $line;
			$line_length      = strlen( $line );
			$trimmed          = trim( $line );
			$fence            = $this->markdown_fence_line( $line );
			$is_indented_code = $this->is_indented_code_line( $line );

			if ( null !== $active_fence ) {
				$output = $line;
				if ( ! empty( $active_fence['admonition_depth'] ) ) {
					$output = $this->blockquote_prefix( (int) $active_fence['admonition_depth'] ) . $line;
				}
				$tokens[] = $this->token( 'fence_line', $line, $line_number + 1, $offset, array( 'output' => $output ) );
				if ( $this->markdown_closing_fence_line( $line, $active_fence ) ) {
					$active_fence = null;
				}
				$offset += $line_length + 1;
				continue;
			}

			if ( null !== $mdx_esm_state && $this->should_stop_mdx_esm_declaration_before_line( $mdx_esm_state, $line, $fence ) ) {
				$mdx_esm_state = null;
			}

			$admonition_close = null;
			if ( null === $active_html_block || 'blank' === ( isset( $active_html_block['close'] ) ? (string) $active_html_block['close'] : '' ) ) {
				$admonition_close = $this->docusaurus_admonition_close( $trimmed, $admonitions );
			}
			if ( null !== $admonition_close ) {
				array_pop( $admonitions );
				if ( null !== $active_html_block && ! empty( $active_html_block['admonition_depth'] ) && (int) $active_html_block['admonition_depth'] > count( $admonitions ) ) {
					$active_html_block = null;
				}
				$tokens[] = $this->token( 'admonition_close', $line, $line_number + 1, $offset, array( 'marker_length' => $admonition_close ) );
				$offset  += $line_length + 1;
				continue;
			}

			if ( null !== $active_html_block ) {
				$output = $line;
				if ( ! empty( $active_html_block['admonition_depth'] ) ) {
					$output = $this->admonition_body_output( $line, (int) $active_html_block['admonition_depth'], false, $counts );
				}
				$tokens[] = $this->token( 'html_block_line', $line, $line_number + 1, $offset, array( 'output' => $output ) );
				if ( $this->markdown_html_block_state_closes( $active_html_block, $line ) ) {
					$active_html_block = null;
				}
				$offset += $line_length + 1;
				continue;
			}

			if ( null !== $fence ) {
				$active_fence = $fence;
				$output       = $line;
				if ( ! empty( $admonitions ) ) {
					$active_fence['admonition_depth'] = count( $admonitions );
					$output = $this->blockquote_prefix( count( $admonitions ) ) . $line;
				}
				$tokens[]     = $this->token( 'fence_open', $line, $line_number + 1, $offset, array( 'output' => $output ) );
				$offset      += $line_length + 1;
				continue;
			}

			if ( null !== $mdx_esm_state ) {
				$this->increment_count( $counts, 'mdx_removed' );
				$tokens[]      = $this->token( 'mdx_esm', $line, $line_number + 1, $offset );
				$mdx_esm_state = $this->advance_mdx_esm_declaration_state( $mdx_esm_state, $line );
				if ( ! empty( $mdx_esm_state['complete'] ) ) {
					$mdx_esm_state = null;
				}
				$offset += $line_length + 1;
				continue;
			}

			if ( ! $is_indented_code ) {
				$admonition = $this->docusaurus_admonition_open( $trimmed );
				if ( null !== $admonition ) {
					$depth      = count( $admonitions );
					$prefix     = $this->blockquote_prefix( $depth + 1 );
					$headline   = $prefix . '**' . $admonition['label'] . ':**' . ( '' === $admonition['title'] ? '' : ' ' . $admonition['title'] );
					$tokens[]   = $this->token( 'admonition_open', $line, $line_number + 1, $offset, array( 'output' => $headline ) + $admonition );
					$admonitions[] = $admonition;
					$this->increment_count( $counts, 'docusaurus_admonitions' );
					$offset += $line_length + 1;
					continue;
				}
			}

			if ( ! empty( $admonitions ) && $is_indented_code ) {
				$output   = $this->admonition_body_output( $line, count( $admonitions ), false, $counts );
				$tokens[] = $this->token( 'indented_code_line', $line, $line_number + 1, $offset, array( 'output' => $output ) );
				$offset  += $line_length + 1;
				continue;
			}

			if ( $is_indented_code ) {
				$tokens[] = $this->token( 'indented_code_line', $line, $line_number + 1, $offset, array( 'output' => $line ) );
				$offset  += $line_length + 1;
				continue;
			}

			if ( $allow_mdx ) {
				$mdx_esm_state = $this->start_mdx_esm_declaration_state( $line );
				if ( null !== $mdx_esm_state ) {
					$this->increment_count( $counts, 'mdx_removed' );
					$tokens[] = $this->token( 'mdx_esm', $line, $line_number + 1, $offset );
					if ( ! empty( $mdx_esm_state['complete'] ) ) {
						$mdx_esm_state = null;
					}
					$offset += $line_length + 1;
					continue;
				}

				if ( $this->is_mdx_component_wrapper_line( $line ) ) {
					$this->increment_count( $counts, 'mdx_removed' );
					$tokens[] = $this->token( 'mdx_component_wrapper', $line, $line_number + 1, $offset );
					$offset  += $line_length + 1;
					continue;
				}
			}

			if ( $allow_markdoc && $this->is_standalone_markdoc_construct( $line ) ) {
				$this->increment_count( $counts, 'markdoc_constructs' );
				$tokens[] = $this->token( 'markdoc_construct', $line, $line_number + 1, $offset );
				$offset  += $line_length + 1;
				continue;
			}

			if ( ! empty( $admonitions ) ) {
				$html_block = $this->markdown_html_block_state( $line );
				if ( null !== $html_block ) {
					$html_block['admonition_depth'] = count( $admonitions );
					$output = $this->admonition_body_output( $line, count( $admonitions ), false, $counts );
					$tokens[] = $this->token( 'html_block_line', $line, $line_number + 1, $offset, array( 'output' => $output ) );
					if ( ! $this->markdown_html_block_state_closes( $html_block, $line ) ) {
						$active_html_block = $html_block;
					}
					$offset += $line_length + 1;
					continue;
				}

				$output = $this->admonition_body_output( $line, count( $admonitions ), true, $counts );
				$tokens[] = $this->token( 'admonition_line', $line, $line_number + 1, $offset, array( 'output' => $output ) );
				$offset  += $line_length + 1;
				continue;
			}

			$html_block = $this->markdown_html_block_state( $line );
			if ( null !== $html_block ) {
				$tokens[] = $this->token( 'html_block_line', $line, $line_number + 1, $offset, array( 'output' => $line ) );
				if ( ! $this->markdown_html_block_state_closes( $html_block, $line ) ) {
					$active_html_block = $html_block;
				}
				$offset += $line_length + 1;
				continue;
			}

			$callout = $this->obsidian_callout_line( $line, $counts );
			if ( null !== $callout ) {
				$this->increment_count( $counts, 'obsidian_callouts' );
				$tokens[] = $this->token( 'obsidian_callout', $line, $line_number + 1, $offset, array( 'output' => $callout ) );
				$offset  += $line_length + 1;
				continue;
			}

			$tokens[] = $this->token(
				'markdown_line',
				$line,
				$line_number + 1,
				$offset,
				array( 'output' => $this->normalize_obsidian_wikilinks_in_line( $line, $counts ) )
			);
			$offset += $line_length + 1;
		}

		return $tokens;
	}

	/**
	 * Creates a token with source offsets.
	 *
	 * @param string              $type        Token type.
	 * @param string              $line        Source line.
	 * @param int                 $line_number One-based source line.
	 * @param int                 $offset      Source offset.
	 * @param array<string,mixed> $extra       Extra token fields.
	 * @return array<string,mixed>
	 */
	private function token( $type, $line, $line_number, $offset, $extra = array() ) {
		return array_merge(
			array(
				'type'   => (string) $type,
				'line'   => (int) $line_number,
				'offset' => (int) $offset,
				'length' => strlen( (string) $line ),
				'source' => (string) $line,
			),
			$extra
		);
	}

	/**
	 * Increments a named counter.
	 *
	 * @param array<string,int> $counts Counts.
	 * @param string            $key    Counter key.
	 * @return void
	 */
	private function increment_count( &$counts, $key ) {
		if ( ! isset( $counts[ $key ] ) ) {
			$counts[ $key ] = 0;
		}

		++$counts[ $key ];
	}

	/**
	 * Parses a fenced Markdown line marker.
	 *
	 * @param string $line Source line.
	 * @return array{marker:string,length:int}|null
	 */
	private function markdown_fence_line( $line ) {
		if ( $this->is_indented_code_line( $line ) ) {
			return null;
		}

		$trimmed = ltrim( (string) $line, " \t" );
		if ( '' === $trimmed ) {
			return null;
		}

		$marker = $trimmed[0];
		if ( '`' !== $marker && '~' !== $marker ) {
			return null;
		}

		$length = 0;
		$total  = strlen( $trimmed );
		while ( $length < $total && $marker === $trimmed[ $length ] ) {
			++$length;
		}

		if ( $length < 3 ) {
			return null;
		}

		return array(
			'marker' => $marker,
			'length' => $length,
		);
	}

	/**
	 * Returns whether a line closes the currently active fenced code block.
	 *
	 * @param string                   $line         Source line.
	 * @param array{marker:string,length:int} $active_fence Active fence marker.
	 * @return bool
	 */
	private function markdown_closing_fence_line( $line, $active_fence ) {
		if ( $this->is_indented_code_line( $line ) ) {
			return false;
		}

		$trimmed = ltrim( (string) $line, " \t" );
		if ( '' === $trimmed ) {
			return false;
		}

		$marker = (string) $active_fence['marker'];
		if ( $marker !== $trimmed[0] ) {
			return false;
		}

		$length = $this->leading_repeated_character_count( $trimmed, $marker );
		if ( $length < (int) $active_fence['length'] ) {
			return false;
		}

		$total = strlen( $trimmed );
		for ( $i = $length; $i < $total; ++$i ) {
			if ( ' ' !== $trimmed[ $i ] && "\t" !== $trimmed[ $i ] ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Returns whether a line is an indented code line.
	 *
	 * @param string $line Source line.
	 * @return bool
	 */
	private function is_indented_code_line( $line ) {
		$line = (string) $line;
		if ( '' === $line ) {
			return false;
		}

		if ( "\t" === $line[0] ) {
			return true;
		}

		for ( $i = 0; $i < 4; ++$i ) {
			if ( ! isset( $line[ $i ] ) || ' ' !== $line[ $i ] ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Starts CommonMark-style raw HTML block tracking when a block opener is present.
	 *
	 * @param string $line Source line.
	 * @return array<string,string>|null HTML block state.
	 */
	private function markdown_html_block_state( $line ) {
		if ( $this->is_indented_code_line( $line ) ) {
			return null;
		}

		$trimmed = ltrim( (string) $line, " \t" );
		if ( '' === $trimmed || '<' !== $trimmed[0] ) {
			return null;
		}

		if ( $this->starts_with( $trimmed, '<!--' ) ) {
			return array( 'close' => '-->' );
		}

		if ( $this->starts_with( $trimmed, '<![CDATA[' ) ) {
			return array( 'close' => ']]>' );
		}

		if ( $this->starts_with( $trimmed, '<?' ) ) {
			return array( 'close' => '?>' );
		}

		if ( $this->starts_with( $trimmed, '<!' ) ) {
			return array( 'close' => '>' );
		}

		$tag = $this->markdown_html_block_tag_name( $trimmed );
		if ( null === $tag ) {
			return null;
		}

		if ( in_array( $tag, array( 'script', 'pre', 'style' ), true ) ) {
			return array( 'close' => '</' . $tag . '>' );
		}

		if ( $this->is_commonmark_html_block_tag( $tag ) ) {
			return array( 'close' => 'blank' );
		}

		return null;
	}

	/**
	 * Returns whether a raw HTML block state closes on the current line.
	 *
	 * @param array<string,string> $state HTML block state.
	 * @param string               $line  Source line.
	 * @return bool
	 */
	private function markdown_html_block_state_closes( $state, $line ) {
		$close = isset( $state['close'] ) ? (string) $state['close'] : '';
		if ( 'blank' === $close ) {
			return '' === trim( (string) $line );
		}

		if ( '' === $close ) {
			return true;
		}

		return false !== strpos( strtolower( (string) $line ), strtolower( $close ) );
	}

	/**
	 * Scans an HTML tag name from a potential block opener.
	 *
	 * @param string $trimmed Line without leading whitespace.
	 * @return string|null Lowercase tag name.
	 */
	private function markdown_html_block_tag_name( $trimmed ) {
		if ( ! isset( $trimmed[0], $trimmed[1] ) || '<' !== $trimmed[0] ) {
			return null;
		}

		$offset = 1;
		if ( '/' === $trimmed[ $offset ] ) {
			++$offset;
		}

		if ( ! isset( $trimmed[ $offset ] ) || ! $this->is_ascii_alpha( $trimmed[ $offset ] ) ) {
			return null;
		}

		$length = strlen( $trimmed );
		$end    = $offset + 1;
		while ( $end < $length && ( $this->is_ascii_alpha( $trimmed[ $end ] ) || ( $trimmed[ $end ] >= '0' && $trimmed[ $end ] <= '9' ) || '-' === $trimmed[ $end ] ) ) {
			++$end;
		}

		if ( isset( $trimmed[ $end ] ) && ! $this->is_ascii_whitespace( $trimmed[ $end ] ) && '>' !== $trimmed[ $end ] && '/' !== $trimmed[ $end ] ) {
			return null;
		}

		return strtolower( substr( $trimmed, $offset, $end - $offset ) );
	}

	/**
	 * Returns whether a tag is a CommonMark block-level HTML tag.
	 *
	 * @param string $tag Lowercase HTML tag.
	 * @return bool
	 */
	private function is_commonmark_html_block_tag( $tag ) {
		static $tags = array(
			'address'    => true,
			'article'    => true,
			'aside'      => true,
			'base'       => true,
			'basefont'   => true,
			'blockquote' => true,
			'body'       => true,
			'caption'    => true,
			'center'     => true,
			'col'        => true,
			'colgroup'   => true,
			'dd'         => true,
			'details'    => true,
			'dialog'     => true,
			'dir'        => true,
			'div'        => true,
			'dl'         => true,
			'dt'         => true,
			'fieldset'   => true,
			'figcaption' => true,
			'figure'     => true,
			'footer'     => true,
			'form'       => true,
			'frame'      => true,
			'frameset'   => true,
			'h1'         => true,
			'h2'         => true,
			'h3'         => true,
			'h4'         => true,
			'h5'         => true,
			'h6'         => true,
			'head'       => true,
			'header'     => true,
			'hr'         => true,
			'html'       => true,
			'iframe'     => true,
			'legend'     => true,
			'li'         => true,
			'link'       => true,
			'main'       => true,
			'menu'       => true,
			'menuitem'   => true,
			'nav'        => true,
			'noframes'   => true,
			'ol'         => true,
			'optgroup'   => true,
			'option'     => true,
			'p'          => true,
			'param'      => true,
			'section'    => true,
			'source'     => true,
			'summary'    => true,
			'table'      => true,
			'tbody'      => true,
			'td'         => true,
			'tfoot'      => true,
			'th'         => true,
			'thead'      => true,
			'title'      => true,
			'tr'         => true,
			'track'      => true,
			'ul'         => true,
		);

		return isset( $tags[ (string) $tag ] );
	}

	/**
	 * Parse a Docusaurus/Astro-style admonition opener.
	 *
	 * @param string $trimmed Trimmed line.
	 * @return array{label:string,title:string,marker_length:int}|null
	 */
	private function docusaurus_admonition_open( $trimmed ) {
		$trimmed = (string) $trimmed;
		if ( '' === $trimmed || ':' !== $trimmed[0] ) {
			return null;
		}

		$marker_length = $this->leading_repeated_character_count( $trimmed, ':' );
		if ( $marker_length < 3 ) {
			return null;
		}

		$offset = $this->skip_ascii_whitespace( $trimmed, $marker_length );
		$type  = $this->scan_ascii_identifier( $trimmed, $offset );
		if ( null === $type ) {
			return null;
		}

		$type_value = strtolower( $type['value'] );
		$labels     = array(
			'note'    => 'Note',
			'tip'     => 'Tip',
			'info'    => 'Info',
			'warning' => 'Warning',
			'danger'  => 'Danger',
			'caution' => 'Caution',
		);

		if ( ! isset( $labels[ $type_value ] ) ) {
			return null;
		}

		$title_offset = $this->skip_ascii_whitespace( $trimmed, $type['end'] );
		$title        = $this->docusaurus_admonition_title( substr( $trimmed, $title_offset ) );

		return array(
			'label'         => $labels[ $type_value ],
			'title'         => $title,
			'marker_length' => $marker_length,
		);
	}

	/**
	 * Parses a Docusaurus bracket or plain admonition title.
	 *
	 * @param string $tail Text after admonition type.
	 * @return string
	 */
	private function docusaurus_admonition_title( $tail ) {
		$tail = trim( (string) $tail );
		if ( '' === $tail ) {
			return '';
		}

		if ( '[' === $tail[0] ) {
			$close = $this->balanced_bracket_end( $tail, 0, '[', ']' );
			if ( null !== $close ) {
				return trim( substr( $tail, 1, $close - 1 ) );
			}
		}

		$brace = strpos( $tail, '{' );
		if ( false !== $brace ) {
			$tail = substr( $tail, 0, $brace );
		}

		return trim( $tail );
	}

	/**
	 * Returns a matching bracket close offset.
	 *
	 * @param string $text  Text.
	 * @param int    $start Opening bracket offset.
	 * @param string $open  Opening bracket.
	 * @param string $close Closing bracket.
	 * @return int|null
	 */
	private function balanced_bracket_end( $text, $start, $open, $close ) {
		$depth  = 0;
		$length = strlen( (string) $text );

		for ( $i = $start; $i < $length; ++$i ) {
			if ( '\\' === $text[ $i ] ) {
				++$i;
				continue;
			}

			if ( $open === $text[ $i ] ) {
				++$depth;
				continue;
			}

			if ( $close === $text[ $i ] ) {
				--$depth;
				if ( 0 === $depth ) {
					return $i;
				}
			}
		}

		return null;
	}

	/**
	 * Parse a Docusaurus/Astro-style admonition close.
	 *
	 * @param string                    $trimmed     Trimmed line.
	 * @param array<int,array<string,mixed>> $admonitions Active admonitions.
	 * @return int|null Marker length, or null when not a close.
	 */
	private function docusaurus_admonition_close( $trimmed, $admonitions ) {
		if ( empty( $admonitions ) || '' === $trimmed || ':' !== $trimmed[0] ) {
			return null;
		}

		$length = strlen( (string) $trimmed );
		for ( $i = 0; $i < $length; ++$i ) {
			if ( ':' !== $trimmed[ $i ] ) {
				return null;
			}
		}

		$top = $admonitions[ count( $admonitions ) - 1 ];
		return $length >= (int) $top['marker_length'] ? $length : null;
	}

	/**
	 * Returns a blockquote prefix for an admonition depth.
	 *
	 * @param int $depth Admonition depth.
	 * @return string
	 */
	private function blockquote_prefix( $depth ) {
		$prefix = '';
		for ( $i = 0; $i < $depth; ++$i ) {
			$prefix .= '> ';
		}

		return $prefix;
	}

	/**
	 * Prefixes an admonition body line and optionally normalizes inline docs syntax.
	 *
	 * @param string            $line      Source line.
	 * @param int               $depth     Active admonition nesting depth.
	 * @param bool              $normalize Whether to normalize inline Obsidian syntax.
	 * @param array<string,int> $counts    Running metadata counters.
	 * @return string
	 */
	private function admonition_body_output( $line, $depth, $normalize, &$counts ) {
		$prefix = $this->blockquote_prefix( $depth );
		if ( '' === trim( (string) $line ) ) {
			return rtrim( $prefix );
		}

		if ( $normalize ) {
			return $prefix . $this->normalize_obsidian_wikilinks_in_line( $line, $counts );
		}

		return $prefix . (string) $line;
	}

	/**
	 * Starts state tracking for a top-level MDX import/export declaration.
	 *
	 * @param string $line Source line.
	 * @return array<string,mixed>|null Declaration state, or null when not ESM.
	 */
	private function start_mdx_esm_declaration_state( $line ) {
		$line = rtrim( (string) $line );
		if ( ! $this->is_top_level_mdx_line( $line ) ) {
			return null;
		}

		$tail = $this->line_keyword_tail( $line, 'import' );
		if ( null !== $tail ) {
			if ( ! $this->is_mdx_import_declaration_tail( $tail ) ) {
				return null;
			}

			return $this->advance_mdx_esm_declaration_state(
				array(
					'kind'              => 'import',
					'brace_depth'       => 0,
					'bracket_depth'     => 0,
					'paren_depth'       => 0,
					'quote'             => null,
					'escaped'           => false,
					'lines'             => 0,
					'has_import_source' => $this->mdx_import_tail_has_module_source( $tail ),
					'needs_block'       => false,
					'saw_block_brace'   => false,
					'last_significant'  => '',
					'complete'          => false,
				),
				$line
			);
		}

		$tail = $this->line_keyword_tail( $line, 'export' );
		if ( null !== $tail ) {
			if ( ! $this->is_mdx_export_declaration_tail( $tail ) ) {
				return null;
			}

			return $this->advance_mdx_esm_declaration_state(
				array(
					'kind'              => 'export',
					'brace_depth'       => 0,
					'bracket_depth'     => 0,
					'paren_depth'       => 0,
					'quote'             => null,
					'escaped'           => false,
					'lines'             => 0,
					'has_import_source' => false,
					'needs_block'       => $this->mdx_export_tail_needs_block_termination( $tail ),
					'saw_block_brace'   => false,
					'last_significant'  => '',
					'complete'          => false,
				),
				$line
			);
		}

		return null;
	}

	/**
	 * Advances a simple MDX ESM declaration scanner by one source line.
	 *
	 * @param array<string,mixed> $state Current declaration state.
	 * @param string              $line  Source line.
	 * @return array<string,mixed> Updated declaration state.
	 */
	private function advance_mdx_esm_declaration_state( $state, $line ) {
		$line                      = (string) $line;
		$state['complete']         = false;
		$state['last_significant'] = '';
		$state['lines']            = (int) $state['lines'] + 1;

		if ( 'import' === $state['kind'] && empty( $state['has_import_source'] ) ) {
			$state['has_import_source'] = $this->mdx_import_tail_has_module_source( $line );
		}

		$length = strlen( $line );
		for ( $i = 0; $i < $length; ++$i ) {
			$char = $line[ $i ];

			if ( null !== $state['quote'] ) {
				if ( ! empty( $state['escaped'] ) ) {
					$state['escaped'] = false;
					continue;
				}

				if ( '\\' === $char ) {
					$state['escaped'] = true;
					continue;
				}

				if ( $state['quote'] === $char ) {
					$state['quote']            = null;
					$state['last_significant'] = $char;
				}
				continue;
			}

			if ( $this->is_ascii_whitespace( $char ) ) {
				continue;
			}

			$state['last_significant'] = $char;

			if ( "'" === $char || '"' === $char || '`' === $char ) {
				$state['quote']   = $char;
				$state['escaped'] = false;
				continue;
			}

			if ( '{' === $char ) {
				++$state['brace_depth'];
				if ( ! empty( $state['needs_block'] ) ) {
					$state['saw_block_brace'] = true;
				}
				continue;
			}

			if ( '[' === $char ) {
				++$state['bracket_depth'];
				continue;
			}

			if ( '(' === $char ) {
				++$state['paren_depth'];
				continue;
			}

			if ( '}' === $char && 0 < $state['brace_depth'] ) {
				--$state['brace_depth'];
				continue;
			}

			if ( ']' === $char && 0 < $state['bracket_depth'] ) {
				--$state['bracket_depth'];
				continue;
			}

			if ( ')' === $char && 0 < $state['paren_depth'] ) {
				--$state['paren_depth'];
				continue;
			}

			if ( ';' === $char && $this->mdx_esm_declaration_state_is_balanced( $state ) ) {
				$state['complete'] = true;
				return $state;
			}
		}

		if ( $this->mdx_esm_declaration_state_is_balanced( $state )
			&& ! $this->mdx_esm_declaration_line_is_incomplete( $state )
			&& ( 'export' === $state['kind'] || ! empty( $state['has_import_source'] ) )
			&& ( empty( $state['needs_block'] ) || ! empty( $state['saw_block_brace'] ) )
		) {
			$state['complete'] = true;
		}

		return $state;
	}

	/**
	 * Returns whether an active ESM declaration should stop before this line.
	 *
	 * @param array<string,mixed>           $state Current declaration state.
	 * @param string                        $line  Source line.
	 * @param array{marker:string,length:int}|null $fence Current fence marker, when present.
	 * @return bool
	 */
	private function should_stop_mdx_esm_declaration_before_line( $state, $line, $fence ) {
		if ( null !== $fence ) {
			return true;
		}

		if ( '' === trim( (string) $line ) ) {
			return $this->mdx_esm_declaration_state_is_balanced( $state );
		}

		if ( $this->is_mdx_esm_markdown_interrupt_line( $line ) ) {
			return true;
		}

		return $this->mdx_esm_declaration_state_is_balanced( $state )
			&& ! $this->mdx_esm_declaration_line_is_incomplete( $state );
	}

	/**
	 * Returns whether a declaration state has no open delimiter or quote.
	 *
	 * @param array<string,mixed> $state Current declaration state.
	 * @return bool
	 */
	private function mdx_esm_declaration_state_is_balanced( $state ) {
		return null === $state['quote']
			&& 0 === $state['brace_depth']
			&& 0 === $state['bracket_depth']
			&& 0 === $state['paren_depth'];
	}

	/**
	 * Returns whether the last scanned line needs a continuation.
	 *
	 * @param array<string,mixed> $state Current declaration state.
	 * @return bool
	 */
	private function mdx_esm_declaration_line_is_incomplete( $state ) {
		if ( null !== $state['quote'] ) {
			return true;
		}

		$last = (string) $state['last_significant'];
		if ( '' === $last ) {
			return true;
		}

		return in_array(
			$last,
			array( '=', ',', ':', '?', '+', '-', '*', '/', '%', '&', '|', '^', '!', '.', '(', '[', '{' ),
			true
		);
	}

	/**
	 * Returns whether a top-level line clearly starts a Markdown block.
	 *
	 * @param string $line Source line.
	 * @return bool
	 */
	private function is_mdx_esm_markdown_interrupt_line( $line ) {
		$line = (string) $line;
		if ( ! $this->is_top_level_mdx_line( $line ) ) {
			return false;
		}

		$length = strlen( $line );
		$first  = $line[0];

		if ( '>' === $first ) {
			return true;
		}

		if ( ':' === $first && isset( $line[1], $line[2] ) && ':' === $line[1] && ':' === $line[2] ) {
			return true;
		}

		if ( '#' === $first ) {
			$index = 0;
			while ( $index < $length && '#' === $line[ $index ] ) {
				++$index;
			}

			return $index <= 6 && isset( $line[ $index ] ) && $this->is_ascii_whitespace( $line[ $index ] );
		}

		if ( ( '-' === $first || '*' === $first || '+' === $first ) && isset( $line[1] ) && $this->is_ascii_whitespace( $line[1] ) ) {
			return true;
		}

		if ( $first >= '0' && $first <= '9' ) {
			$index = 1;
			while ( $index < $length && $line[ $index ] >= '0' && $line[ $index ] <= '9' ) {
				++$index;
			}

			return isset( $line[ $index ], $line[ $index + 1 ] )
				&& ( '.' === $line[ $index ] || ')' === $line[ $index ] )
				&& $this->is_ascii_whitespace( $line[ $index + 1 ] );
		}

		return false;
	}

	/**
	 * Returns whether a line is a simple MDX component wrapper.
	 *
	 * @param string $line Source line.
	 * @return bool
	 */
	private function is_mdx_component_wrapper_line( $line ) {
		$line = rtrim( (string) $line );
		if ( ! $this->is_top_level_mdx_line( $line ) ) {
			return false;
		}

		$trimmed = trim( $line );
		if ( strlen( $trimmed ) < 3 || '<' !== $trimmed[0] || '>' !== substr( $trimmed, -1 ) ) {
			return false;
		}

		$index = 1;
		if ( '/' === $trimmed[ $index ] ) {
			++$index;
		}

		if ( ! isset( $trimmed[ $index ] ) ) {
			return false;
		}

		$char = $trimmed[ $index ];

		if ( $char < 'A' || $char > 'Z' ) {
			return false;
		}

		$name_end = $index;
		$length   = strlen( $trimmed );
		while ( $name_end < $length && $this->is_ascii_identifier_char( $trimmed[ $name_end ] ) ) {
			++$name_end;
		}

		if ( $this->is_all_uppercase_commonmark_html_block_tag( substr( $trimmed, $index, $name_end - $index ) ) ) {
			return false;
		}

		$tag_end = $this->scan_mdx_tag_end( $trimmed, $index );
		if ( null === $tag_end ) {
			return false;
		}

		for ( $i = $tag_end + 1, $length = strlen( $trimmed ); $i < $length; ++$i ) {
			if ( ' ' !== $trimmed[ $i ] && "\t" !== $trimmed[ $i ] ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Returns whether an uppercase MDX-looking tag name is actually a known HTML block tag.
	 *
	 * @param string $tag Tag name.
	 * @return bool
	 */
	private function is_all_uppercase_commonmark_html_block_tag( $tag ) {
		$tag       = (string) $tag;
		$has_alpha = false;
		$length    = strlen( $tag );
		for ( $i = 0; $i < $length; ++$i ) {
			$char = $tag[ $i ];
			if ( $char >= 'a' && $char <= 'z' ) {
				return false;
			}

			if ( $this->is_ascii_alpha( $char ) ) {
				$has_alpha = true;
				continue;
			}

			if ( $char < '0' || $char > '9' ) {
				return false;
			}
		}

		$tag = strtolower( $tag );
		return $has_alpha
			&& ( in_array( $tag, array( 'script', 'pre', 'style' ), true ) || $this->is_commonmark_html_block_tag( $tag ) );
	}

	/**
	 * Returns whether a docs MDX line is at top level.
	 *
	 * @param string $line Source line.
	 * @return bool
	 */
	private function is_top_level_mdx_line( $line ) {
		$line = (string) $line;

		return '' !== $line && ' ' !== $line[0] && "\t" !== $line[0];
	}

	/**
	 * Returns the text after a top-level keyword when it is token-delimited.
	 *
	 * @param string $line    Source line.
	 * @param string $keyword Keyword.
	 * @return string|null
	 */
	private function line_keyword_tail( $line, $keyword ) {
		$keyword = (string) $keyword;
		$length  = strlen( $keyword );

		if ( substr( (string) $line, 0, $length ) !== $keyword ) {
			return null;
		}

		if ( ! isset( $line[ $length ] ) || ! $this->is_ascii_whitespace( $line[ $length ] ) ) {
			return null;
		}

		return ltrim( substr( (string) $line, $length ), " \t" );
	}

	/**
	 * Returns whether text after import is an ESM declaration shape.
	 *
	 * @param string $tail Text after import.
	 * @return bool
	 */
	private function is_mdx_import_declaration_tail( $tail ) {
		$tail = ltrim( (string) $tail, " \t" );
		if ( '' === $tail ) {
			return false;
		}

		if ( $this->mdx_import_tail_has_module_source( $tail ) ) {
			return true;
		}

		if ( '{' === $tail[0] || '*' === $tail[0] ) {
			return true;
		}

		$token = $this->scan_ascii_identifier( $tail, 0 );
		if ( null === $token ) {
			return false;
		}

		$after = $this->skip_ascii_whitespace( $tail, $token['end'] );
		if ( isset( $tail[ $after ] ) && ( ',' === $tail[ $after ] || '{' === $tail[ $after ] ) ) {
			return true;
		}

		if ( 'type' !== $token['value'] ) {
			return false;
		}

		if ( isset( $tail[ $after ] ) && ( '*' === $tail[ $after ] || '{' === $tail[ $after ] ) ) {
			return true;
		}

		$type_token = $this->scan_ascii_identifier( $tail, $after );
		if ( null === $type_token ) {
			return false;
		}

		$type_after = $this->skip_ascii_whitespace( $tail, $type_token['end'] );
		return isset( $tail[ $type_after ] ) && ( ',' === $tail[ $type_after ] || '{' === $tail[ $type_after ] );
	}

	/**
	 * Returns whether text after export is an ESM declaration shape.
	 *
	 * @param string $tail Text after export.
	 * @return bool
	 */
	private function is_mdx_export_declaration_tail( $tail ) {
		$tail = ltrim( (string) $tail, " \t" );
		if ( '' === $tail ) {
			return false;
		}

		if ( '{' === $tail[0] || '*' === $tail[0] ) {
			return true;
		}

		$token = $this->scan_ascii_identifier( $tail, 0 );
		if ( null === $token ) {
			return false;
		}

		return in_array(
			$token['value'],
			array( 'default', 'const', 'let', 'var', 'function', 'class', 'async', 'type', 'interface', 'enum' ),
			true
		);
	}

	/**
	 * Returns whether an import tail contains a quoted module source.
	 *
	 * @param string $tail Import tail or continuation line.
	 * @return bool
	 */
	private function mdx_import_tail_has_module_source( $tail ) {
		$tail = ltrim( (string) $tail, " \t" );
		if ( '' === $tail ) {
			return false;
		}

		if ( "'" === $tail[0] || '"' === $tail[0] ) {
			return $this->mdx_tail_has_quoted_module_specifier( $tail, 0 );
		}

		return $this->mdx_tail_has_from_clause( $tail );
	}

	/**
	 * Returns whether an export tail needs a following function/class block.
	 *
	 * @param string $tail Text after export.
	 * @return bool
	 */
	private function mdx_export_tail_needs_block_termination( $tail ) {
		$offset = 0;
		$tail   = ltrim( (string) $tail, " \t" );
		$token  = $this->scan_ascii_identifier( $tail, $offset );
		if ( null === $token ) {
			return false;
		}

		if ( 'default' === $token['value'] ) {
			$offset = $this->skip_ascii_whitespace( $tail, $token['end'] );
			$token  = $this->scan_ascii_identifier( $tail, $offset );
			if ( null === $token ) {
				return false;
			}
		}

		if ( 'async' === $token['value'] ) {
			$offset = $this->skip_ascii_whitespace( $tail, $token['end'] );
			$token  = $this->scan_ascii_identifier( $tail, $offset );
			if ( null === $token ) {
				return false;
			}
		}

		return 'function' === $token['value'] || 'class' === $token['value'];
	}

	/**
	 * Returns whether an import/export tail contains a from "module" clause.
	 *
	 * @param string $tail Import/export tail.
	 * @return bool
	 */
	private function mdx_tail_has_from_clause( $tail ) {
		$length = strlen( (string) $tail );
		for ( $i = 0; $i < $length; ++$i ) {
			if ( "'" === $tail[ $i ] || '"' === $tail[ $i ] ) {
				$i = $this->skip_quoted_segment( $tail, $i );
				continue;
			}

			$token = $this->scan_ascii_identifier( $tail, $i );
			if ( null === $token ) {
				continue;
			}

			if ( 'from' === $token['value'] ) {
				$specifier = $this->skip_ascii_whitespace( $tail, $token['end'] );
				return $this->mdx_tail_has_quoted_module_specifier( $tail, $specifier );
			}

			$i = $token['end'] - 1;
		}

		return false;
	}

	/**
	 * Returns whether a quoted module specifier starts at the supplied offset.
	 *
	 * @param string $tail   Import/export tail.
	 * @param int    $offset Offset.
	 * @return bool
	 */
	private function mdx_tail_has_quoted_module_specifier( $tail, $offset ) {
		$tail = (string) $tail;
		if ( ! isset( $tail[ $offset ] ) || ( "'" !== $tail[ $offset ] && '"' !== $tail[ $offset ] ) ) {
			return false;
		}

		return $this->skip_quoted_segment( $tail, $offset ) > $offset;
	}

	/**
	 * Scans an MDX tag and returns the index of its closing ">".
	 *
	 * @param string $line       Trimmed MDX line.
	 * @param int    $name_start Tag name start index.
	 * @return int|null
	 */
	private function scan_mdx_tag_end( $line, $name_start ) {
		$length = strlen( (string) $line );
		$index  = $name_start;

		while ( $index < $length && $this->is_ascii_identifier_char( $line[ $index ] ) ) {
			++$index;
		}

		if ( $index === $name_start ) {
			return null;
		}

		for ( ; $index < $length; ++$index ) {
			$char = $line[ $index ];

			if ( "'" === $char || '"' === $char ) {
				$next = $this->skip_quoted_segment( $line, $index );
				if ( $next === $index ) {
					return null;
				}
				$index = $next;
				continue;
			}

			if ( '>' === $char ) {
				return $index;
			}
		}

		return null;
	}

	/**
	 * Returns whether a line is a standalone Markdoc construct.
	 *
	 * @param string $line Source line.
	 * @return bool
	 */
	private function is_standalone_markdoc_construct( $line ) {
		$trimmed = trim( (string) $line );
		$length  = strlen( $trimmed );

		if ( $length < 4 ) {
			return false;
		}

		return ( '{%' === substr( $trimmed, 0, 2 ) && '%}' === substr( $trimmed, -2 ) )
			|| ( '{{' === substr( $trimmed, 0, 2 ) && '}}' === substr( $trimmed, -2 ) )
			|| ( '{#' === substr( $trimmed, 0, 2 ) && '#}' === substr( $trimmed, -2 ) );
	}

	/**
	 * Normalize an Obsidian callout opener to ordinary blockquote Markdown.
	 *
	 * @param string $line Source line.
	 * @return string|null Normalized line, or null when not a callout opener.
	 */
	private function obsidian_callout_line( $line, &$counts ) {
		$line   = (string) $line;
		$length = strlen( $line );
		$index  = 0;
		$prefix = '';

		while ( $index < $length && $this->is_ascii_whitespace( $line[ $index ] ) ) {
			$prefix .= $line[ $index ];
			++$index;
		}

		if ( ! isset( $line[ $index ] ) || '>' !== $line[ $index ] ) {
			return null;
		}

		while ( isset( $line[ $index ] ) && '>' === $line[ $index ] ) {
			$prefix .= '>';
			++$index;
			if ( isset( $line[ $index ] ) && $this->is_ascii_whitespace( $line[ $index ] ) ) {
				$prefix .= $line[ $index ];
				++$index;
			}
		}

		if ( ! isset( $line[ $index ], $line[ $index + 1 ] ) || '[' !== $line[ $index ] || '!' !== $line[ $index + 1 ] ) {
			return null;
		}

		$close = strpos( $line, ']', $index + 2 );
		if ( false === $close ) {
			return null;
		}

		$type  = substr( $line, $index + 2, $close - ( $index + 2 ) );
		$title = trim( substr( $line, $close + 1 ) );
		if ( '' !== $title && ( '+' === $title[0] || '-' === $title[0] ) ) {
			$title = trim( substr( $title, 1 ) );
		}
		$title = $this->normalize_obsidian_wikilinks_in_line( $title, $counts );
		$label = ucfirst( strtolower( trim( str_replace( array( '+', '-' ), '', $type ) ) ) );

		if ( '' === $label ) {
			$label = 'Note';
		}

		return $prefix . '**' . $label . ':**' . ( '' === $title ? '' : ' ' . $title );
	}

	/**
	 * Normalize Obsidian wikilinks and embeds in one line.
	 *
	 * @param string            $line   Source line.
	 * @param array<string,int> $counts Running metadata counters.
	 * @return string Normalized line.
	 */
	private function normalize_obsidian_wikilinks_in_line( $line, &$counts ) {
		$output = '';
		$length = strlen( $line );

		for ( $i = 0; $i < $length; ++$i ) {
			$escaped_wikilink_end = $this->escaped_obsidian_wikilink_end( $line, $i );
			if ( false !== $escaped_wikilink_end ) {
				$output .= substr( $line, $i, $escaped_wikilink_end + 2 - $i );
				$i       = $escaped_wikilink_end + 1;
				continue;
			}

			if ( '\\' === $line[ $i ] && isset( $line[ $i + 1 ] ) && $this->is_commonmark_escapable_punctuation( $line[ $i + 1 ] ) ) {
				$output .= substr( $line, $i, 2 );
				++$i;
				continue;
			}

			if ( '`' === $line[ $i ] ) {
				$code_end = $this->markdown_code_span_end( $line, $i );
				if ( null !== $code_end ) {
					$output .= substr( $line, $i, $code_end - $i + 1 );
					$i       = $code_end;
					continue;
				}
			}

			$is_embed = '!' === $line[ $i ] && isset( $line[ $i + 2 ] ) && '[' === $line[ $i + 1 ] && '[' === $line[ $i + 2 ];
			$is_link  = '[' === $line[ $i ] && isset( $line[ $i + 1 ] ) && '[' === $line[ $i + 1 ];

			if ( ! $is_embed && ! $is_link ) {
				$output .= $line[ $i ];
				continue;
			}

			$start = $is_embed ? $i + 3 : $i + 2;
			$end   = $this->obsidian_wikilink_end( $line, $start );
			if ( false === $end ) {
				$output .= $line[ $i ];
				continue;
			}

			$raw_target = substr( $line, $start, $end - $start );
			$target     = trim( $raw_target );
			if ( '' === $target || false !== strpos( $target, '[[' ) ) {
				$output .= substr( $line, $i, $end + 2 - $i );
				$i       = $end + 1;
				continue;
			}

			$parts  = $this->obsidian_wikilink_target_parts( $target );
			$target = $parts['target'];
			$label  = $parts['label'];

			if ( '' === $target ) {
				$output .= substr( $line, $i, $end + 2 - $i );
				$i       = $end + 1;
				continue;
			}

			$url = $this->obsidian_wikilink_url( $target, $is_embed );
			if ( '' === $label ) {
				$label = $this->obsidian_wikilink_label( $target );
			}

			if ( $is_embed ) {
				$this->increment_count( $counts, 'obsidian_embeds' );
				$output .= '![' . $this->obsidian_markdown_label( $label ) . '](' . $url . ')';
			} else {
				$this->increment_count( $counts, 'obsidian_wikilinks' );
				$output .= '[' . $this->obsidian_markdown_label( $label ) . '](' . $url . ')';
			}

			$i = $end + 1;
		}

		return $output;
	}

	/**
	 * Finds the closing delimiter for a Markdown code span.
	 *
	 * @param string $line  Source line.
	 * @param int    $start Opening backtick offset.
	 * @return int|null Closing offset, or null when not closed.
	 */
	private function markdown_code_span_end( $line, $start ) {
		$marker_length = $this->leading_repeated_character_count( substr( (string) $line, $start ), '`' );
		$length        = strlen( (string) $line );

		for ( $i = $start + $marker_length; $i < $length; ++$i ) {
			if ( '`' !== $line[ $i ] ) {
				continue;
			}

			$run = $this->leading_repeated_character_count( substr( $line, $i ), '`' );
			if ( $run === $marker_length ) {
				return $i + $run - 1;
			}

			$i += $run - 1;
		}

		return null;
	}

	/**
	 * Finds the closing delimiter for an Obsidian wikilink.
	 *
	 * @param string $line  Source line.
	 * @param int    $start Wikilink content start offset.
	 * @return int|false Offset of closing delimiter, or false when not found.
	 */
	private function obsidian_wikilink_end( $line, $start ) {
		$line   = (string) $line;
		$length = strlen( $line );

		for ( $i = $start; $i < $length - 1; ++$i ) {
			if ( ']' !== $line[ $i ] || ']' !== $line[ $i + 1 ] ) {
				continue;
			}

			while ( isset( $line[ $i + 2 ] ) && ']' === $line[ $i + 2 ] ) {
				++$i;
			}

			return $i;
		}

		return false;
	}

	/**
	 * Finds an Obsidian wikilink/embed whose opening delimiter is escaped.
	 *
	 * @param string $line  Source line.
	 * @param int    $start Potential backslash offset.
	 * @return int|false Offset of closing delimiter, or false when not escaped.
	 */
	private function escaped_obsidian_wikilink_end( $line, $start ) {
		$line = (string) $line;
		if ( ! isset( $line[ $start ], $line[ $start + 1 ] ) || '\\' !== $line[ $start ] ) {
			return false;
		}

		if ( '[' === $line[ $start + 1 ] && isset( $line[ $start + 2 ] ) && '[' === $line[ $start + 2 ] ) {
			return $this->obsidian_wikilink_end( $line, $start + 3 );
		}

		if ( '!' === $line[ $start + 1 ] && isset( $line[ $start + 2 ], $line[ $start + 3 ] ) && '[' === $line[ $start + 2 ] && '[' === $line[ $start + 3 ] ) {
			return $this->obsidian_wikilink_end( $line, $start + 4 );
		}

		return false;
	}

	/**
	 * Splits an Obsidian wikilink target and alias.
	 *
	 * @param string $target Wikilink contents.
	 * @return array{target:string,label:string}
	 */
	private function obsidian_wikilink_target_parts( $target ) {
		$target = trim( (string) $target );
		$length = strlen( $target );

		for ( $i = 0; $i < $length; ++$i ) {
			if ( '|' !== $target[ $i ] ) {
				continue;
			}

			return array(
				'target' => trim( substr( $target, 0, $i ) ),
				'label'  => trim( substr( $target, $i + 1 ) ),
			);
		}

		return array(
			'target' => $target,
			'label'  => '',
		);
	}

	/**
	 * Convert an Obsidian wikilink target to a Markdown URL.
	 *
	 * @param string $target   Wikilink target.
	 * @param bool   $is_embed Whether the source was an embed.
	 * @return string Markdown URL.
	 */
	private function obsidian_wikilink_url( $target, $is_embed ) {
		$target = trim( (string) $target );
		if ( '' === $target ) {
			return '';
		}

		if ( '#' === $target[0] ) {
			return '#' . rawurlencode( substr( $target, 1 ) );
		}

		if ( $this->has_uri_scheme( $target ) ) {
			return $target;
		}

		$fragment = '';
		$hash     = strpos( $target, '#' );
		if ( false !== $hash ) {
			$fragment = substr( $target, $hash );
			$target   = substr( $target, 0, $hash );
		}

		$target = rtrim( $target, "/\\" );
		if ( ! $is_embed && '' === pathinfo( $target, PATHINFO_EXTENSION ) ) {
			$target .= '.md';
		}

		return $this->obsidian_markdown_url_path( $target ) . $this->obsidian_markdown_url_fragment( $fragment );
	}

	/**
	 * Escapes an Obsidian label for the importer Markdown inline parser.
	 *
	 * @param string $label Label.
	 * @return string Markdown-safe label.
	 */
	private function obsidian_markdown_label( $label ) {
		return str_replace(
			array( '[', ']' ),
			array( '&#91;', '&#93;' ),
			(string) $label
		);
	}

	/**
	 * Percent-encodes an Obsidian target path while preserving path separators.
	 *
	 * @param string $path Target path.
	 * @return string Markdown-safe URL path.
	 */
	private function obsidian_markdown_url_path( $path ) {
		$segments = explode( '/', str_replace( '\\', '/', (string) $path ) );
		foreach ( $segments as $index => $segment ) {
			if ( '' === $segment ) {
				continue;
			}
			$segments[ $index ] = rawurlencode( $segment );
		}

		return implode( '/', $segments );
	}

	/**
	 * Percent-encodes an Obsidian target fragment.
	 *
	 * @param string $fragment Target fragment with optional leading "#".
	 * @return string Markdown-safe URL fragment.
	 */
	private function obsidian_markdown_url_fragment( $fragment ) {
		$fragment = (string) $fragment;
		if ( '' === $fragment ) {
			return '';
		}

		if ( '#' === $fragment[0] ) {
			$fragment = substr( $fragment, 1 );
		}

		return '#' . rawurlencode( $fragment );
	}

	/**
	 * Infer a human label for an Obsidian wikilink target.
	 *
	 * @param string $target Wikilink target.
	 * @return string Label.
	 */
	private function obsidian_wikilink_label( $target ) {
		$target = trim( (string) $target );
		$parts  = $this->obsidian_wikilink_target_parts( $target );
		$target = $parts['target'];

		$hash = strpos( $target, '#' );
		if ( false !== $hash ) {
			$target = 0 === $hash ? substr( $target, 1 ) : substr( $target, 0, $hash );
		}

		$label = basename( str_replace( '\\', '/', $target ) );
		$ext   = pathinfo( $label, PATHINFO_EXTENSION );
		if ( '' !== $ext ) {
			$label = substr( $label, 0, -1 * ( strlen( $ext ) + 1 ) );
		}

		return '' === $label ? 'Link' : str_replace( array( '-', '_' ), ' ', $label );
	}

	/**
	 * Returns whether a URL-like target starts with a URI scheme.
	 *
	 * @param string $target Link target.
	 * @return bool
	 */
	private function has_uri_scheme( $target ) {
		$target = (string) $target;
		if ( '' === $target || ! $this->is_ascii_alpha( $target[0] ) ) {
			return false;
		}

		$length = strlen( $target );
		for ( $i = 1; $i < $length; ++$i ) {
			$char = $target[ $i ];
			if ( ':' === $char ) {
				return true;
			}

			if ( ! $this->is_ascii_alpha( $char ) && ! ( $char >= '0' && $char <= '9' ) && '+' !== $char && '-' !== $char && '.' !== $char ) {
				return false;
			}
		}

		return false;
	}

	/**
	 * Skips over a single- or double-quoted segment.
	 *
	 * @param string $text  Text.
	 * @param int    $start Opening quote index.
	 * @return int Index of the closing quote, or start when unterminated.
	 */
	private function skip_quoted_segment( $text, $start ) {
		$quote  = $text[ $start ];
		$length = strlen( (string) $text );

		for ( $i = $start + 1; $i < $length; ++$i ) {
			if ( '\\' === $text[ $i ] ) {
				++$i;
				continue;
			}

			if ( $quote === $text[ $i ] ) {
				return $i;
			}
		}

		return $start;
	}

	/**
	 * Scans an ASCII identifier token.
	 *
	 * @param string $text  Text.
	 * @param int    $start Start offset.
	 * @return array{value:string,end:int}|null
	 */
	private function scan_ascii_identifier( $text, $start ) {
		if ( ! isset( $text[ $start ] ) || ! $this->is_ascii_identifier_start( $text[ $start ] ) ) {
			return null;
		}

		$length = strlen( (string) $text );
		$end    = $start + 1;
		while ( $end < $length && $this->is_ascii_identifier_char( $text[ $end ] ) ) {
			++$end;
		}

		return array(
			'value' => substr( (string) $text, $start, $end - $start ),
			'end'   => $end,
		);
	}

	/**
	 * Skips ASCII spaces and tabs.
	 *
	 * @param string $text  Text.
	 * @param int    $start Start offset.
	 * @return int Offset after spaces and tabs.
	 */
	private function skip_ascii_whitespace( $text, $start ) {
		$length = strlen( (string) $text );
		for ( $i = $start; $i < $length; ++$i ) {
			if ( ! $this->is_ascii_whitespace( $text[ $i ] ) ) {
				return $i;
			}
		}

		return $length;
	}

	/**
	 * Counts a repeated leading character in a string.
	 *
	 * @param string $text Text.
	 * @param string $char Character.
	 * @return int
	 */
	private function leading_repeated_character_count( $text, $char ) {
		$length = strlen( (string) $text );
		for ( $i = 0; $i < $length; ++$i ) {
			if ( $char !== $text[ $i ] ) {
				return $i;
			}
		}

		return $length;
	}

	/**
	 * Returns whether a string starts with a prefix.
	 *
	 * @param string $text   Text.
	 * @param string $prefix Prefix.
	 * @return bool
	 */
	private function starts_with( $text, $prefix ) {
		$prefix = (string) $prefix;

		return substr( (string) $text, 0, strlen( $prefix ) ) === $prefix;
	}

	/**
	 * Returns whether a character is an ASCII identifier start.
	 *
	 * @param string $char Character.
	 * @return bool
	 */
	private function is_ascii_identifier_start( $char ) {
		return $this->is_ascii_alpha( $char )
			|| '_' === $char
			|| '$' === $char;
	}

	/**
	 * Returns whether a character is an ASCII identifier character.
	 *
	 * @param string $char Character.
	 * @return bool
	 */
	private function is_ascii_identifier_char( $char ) {
		return $this->is_ascii_identifier_start( $char )
			|| ( $char >= '0' && $char <= '9' )
			|| '-' === $char
			|| ':' === $char
			|| '.' === $char;
	}

	/**
	 * Returns whether a character is an ASCII letter.
	 *
	 * @param string $char Character.
	 * @return bool
	 */
	private function is_ascii_alpha( $char ) {
		return ( $char >= 'A' && $char <= 'Z' )
			|| ( $char >= 'a' && $char <= 'z' );
	}

	/**
	 * Returns whether a character is an ASCII space or tab.
	 *
	 * @param string $char Character.
	 * @return bool
	 */
	private function is_ascii_whitespace( $char ) {
		return ' ' === $char || "\t" === $char;
	}

	/**
	 * Returns whether a character can be backslash-escaped in CommonMark.
	 *
	 * @param string $char Character.
	 * @return bool
	 */
	private function is_commonmark_escapable_punctuation( $char ) {
		$ordinal = ord( (string) $char );

		return ( 33 <= $ordinal && 47 >= $ordinal )
			|| ( 58 <= $ordinal && 64 >= $ordinal )
			|| ( 91 <= $ordinal && 96 >= $ordinal )
			|| ( 123 <= $ordinal && 126 >= $ordinal );
	}
}
