<?php

$source_root = getenv( 'GUTENBERG_DOCS_SOURCE' ) ?: '/tmp/gutenberg-docs';
$target_root = getenv( 'MARKDOWN_ROOT' ) ?: '/markdown-root';
$github_raw  = 'https://raw.githubusercontent.com/WordPress/gutenberg/trunk/docs/';
$github_blob = 'https://github.com/WordPress/gutenberg/blob/trunk/';

function gutenberg_docs_normalize_path( $path ) {
	$parts = array();
	foreach ( explode( '/', str_replace( '\\', '/', $path ) ) as $part ) {
		if ( $part === '' || $part === '.' ) {
			continue;
		}
		if ( $part === '..' ) {
			array_pop( $parts );
			continue;
		}
		$parts[] = $part;
	}
	return implode( '/', $parts );
}

function gutenberg_docs_slugify( $text ) {
	$slug = strtolower( preg_replace( '/[^a-zA-Z0-9]+/', '-', $text ) );
	$slug = trim( $slug, '-' );
	return $slug === '' ? 'page' : $slug;
}

function gutenberg_docs_title_from_markdown( $markdown, $fallback ) {
	if ( preg_match( '/^\s*#\s+(.+?)\s*#*\s*$/m', $markdown, $matches ) ) {
		return trim( strip_tags( $matches[1] ) );
	}
	return ucwords( str_replace( '-', ' ', $fallback ) );
}

function gutenberg_docs_collect_markdown_files( $root ) {
	$files = array();
	$iterator = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS )
	);
	foreach ( $iterator as $file ) {
		if ( $file->isFile() && strtolower( $file->getExtension() ) === 'md' ) {
			$files[] = gutenberg_docs_normalize_path(
				substr( $file->getPathname(), strlen( $root ) + 1 )
			);
		}
	}
	sort( $files, SORT_STRING );
	return $files;
}

function gutenberg_docs_dirname( $path ) {
	$dirname = dirname( $path );
	return $dirname === '.' ? '' : gutenberg_docs_normalize_path( $dirname );
}

function gutenberg_docs_join_path( $left, $right ) {
	return gutenberg_docs_normalize_path( $left === '' ? $right : $left . '/' . $right );
}

function gutenberg_docs_url_without_fragment( $url ) {
	$hash = strpos( $url, '#' );
	if ( $hash === false ) {
		return array( $url, '' );
	}
	return array( substr( $url, 0, $hash ), substr( $url, $hash ) );
}

function gutenberg_docs_find_target_doc( $candidate, $source_exists ) {
	$candidate = trim( gutenberg_docs_normalize_path( rawurldecode( $candidate ) ), '/' );
	if ( $candidate === '' ) {
		return 'README.md';
	}
	if ( isset( $source_exists[ $candidate ] ) ) {
		return $candidate;
	}
	if ( substr( $candidate, -1 ) === '/' ) {
		$candidate = rtrim( $candidate, '/' );
	}
	if ( isset( $source_exists[ $candidate . '.md' ] ) ) {
		return $candidate . '.md';
	}
	if ( isset( $source_exists[ $candidate . '/README.md' ] ) ) {
		return $candidate . '/README.md';
	}
	if ( basename( $candidate ) === 'README.md' && isset( $source_exists[ $candidate ] ) ) {
		return $candidate;
	}
	return null;
}

function gutenberg_docs_resolve_doc_url( $url, $base_source, $source_exists ) {
	list( $url_without_fragment, $fragment ) = gutenberg_docs_url_without_fragment( $url );
	$url_without_fragment = html_entity_decode( $url_without_fragment, ENT_QUOTES );

	if ( $url_without_fragment === '' ) {
		return null;
	}
	if ( preg_match( '/^(mailto|tel|javascript|data):/i', $url_without_fragment ) ) {
		return null;
	}

	$parts = parse_url( $url_without_fragment );
	if ( isset( $parts['scheme'] ) && isset( $parts['host'] ) ) {
		$host = strtolower( $parts['host'] );
		$path = isset( $parts['path'] ) ? $parts['path'] : '';
		if ( $host === 'developer.wordpress.org' && strpos( $path, '/block-editor/' ) === 0 ) {
			$candidate = substr( $path, strlen( '/block-editor/' ) );
			$target = gutenberg_docs_find_target_doc( $candidate, $source_exists );
			return $target === null ? null : array( $target, $fragment );
		}
		if ( $host === 'github.com' && preg_match( '#^/WordPress/gutenberg/(blob|tree)/(HEAD|trunk)/docs/(.+)$#', $path, $matches ) ) {
			$target = gutenberg_docs_find_target_doc( $matches[3], $source_exists );
			return $target === null ? null : array( $target, $fragment );
		}
		return null;
	}

	if ( $url_without_fragment[0] === '/' ) {
		if ( strpos( $url_without_fragment, '/docs/' ) === 0 ) {
			$target = gutenberg_docs_find_target_doc( substr( $url_without_fragment, 6 ), $source_exists );
			return $target === null ? null : array( $target, $fragment );
		}
		return null;
	}

	$base_dir = gutenberg_docs_dirname( $base_source );
	$target = gutenberg_docs_find_target_doc(
		gutenberg_docs_join_path( $base_dir, $url_without_fragment ),
		$source_exists
	);
	return $target === null ? null : array( $target, $fragment );
}

function gutenberg_docs_rewrite_url( $url, $base_source, $source_exists, $permalinks ) {
	global $github_raw, $github_blob;

	$resolved_doc = gutenberg_docs_resolve_doc_url( $url, $base_source, $source_exists );
	if ( $resolved_doc !== null ) {
		list( $target_source, $fragment ) = $resolved_doc;
		if ( isset( $permalinks[ $target_source ] ) ) {
			return $permalinks[ $target_source ] . $fragment;
		}
	}

	list( $url_without_fragment, $fragment ) = gutenberg_docs_url_without_fragment( $url );
	$url_without_fragment = html_entity_decode( $url_without_fragment, ENT_QUOTES );
	$parts = parse_url( $url_without_fragment );
	if ( isset( $parts['scheme'] ) || preg_match( '/^(mailto|tel|javascript|data):/i', $url_without_fragment ) ) {
		return $url;
	}

	if ( strpos( $url_without_fragment, '/docs/assets/' ) === 0 ) {
		return $github_raw . substr( $url_without_fragment, 6 ) . $fragment;
	}
	if ( $url_without_fragment !== '' && $url_without_fragment[0] !== '/' ) {
		$base_dir = gutenberg_docs_dirname( $base_source );
		$asset = gutenberg_docs_join_path( $base_dir, $url_without_fragment );
		if ( preg_match( '/\.(png|jpe?g|gif|webp|svg)$/i', $asset ) ) {
			return $github_raw . $asset . $fragment;
		}
	}
	if ( $url_without_fragment !== '' && $url_without_fragment[0] === '/' ) {
		return $github_blob . ltrim( $url_without_fragment, '/' ) . $fragment;
	}
	return $url;
}

function gutenberg_docs_rewrite_markdown_links( $markdown, $source, $source_exists, $permalinks ) {
	$markdown = preg_replace_callback(
		'/(!?)\[([^\]]*)\]\(([^)\s]+)(\s+["\'][^"\']*["\'])?\)/',
		function ( $matches ) use ( $source, $source_exists, $permalinks ) {
			$url = gutenberg_docs_rewrite_url( $matches[3], $source, $source_exists, $permalinks );
			return $matches[1] . '[' . $matches[2] . '](' . $url . ( isset( $matches[4] ) ? $matches[4] : '' ) . ')';
		},
		$markdown
	);

	return preg_replace_callback(
		'/\b(href|src)=(["\'])([^"\']+)\2/i',
		function ( $matches ) use ( $source, $source_exists, $permalinks ) {
			$url = gutenberg_docs_rewrite_url( $matches[3], $source, $source_exists, $permalinks );
			return $matches[1] . '=' . $matches[2] . htmlspecialchars( $url, ENT_QUOTES ) . $matches[2];
		},
		$markdown
	);
}

$sources = gutenberg_docs_collect_markdown_files( $source_root );
$source_exists = array_fill_keys( $sources, true );
$readme_dirs = array( '' => 'README.md' );
foreach ( $sources as $source ) {
	if ( basename( $source ) === 'README.md' ) {
		$readme_dirs[ gutenberg_docs_dirname( $source ) ] = $source;
	}
}

$records = array();
$dir_records = array();
$used_slugs = array();
$id = 100;
foreach ( $sources as $source ) {
	$markdown = file_get_contents( $source_root . '/' . $source );
	if ( $markdown === false ) {
		throw new RuntimeException( 'Could not read ' . $source );
	}
	$is_readme = basename( $source ) === 'README.md';
	$dir = $is_readme ? gutenberg_docs_dirname( $source ) : gutenberg_docs_dirname( $source );
	$parent_dir = '';
	if ( $dir !== '' ) {
		$parent_dir = gutenberg_docs_dirname( $dir );
		while ( $parent_dir !== '' && ! isset( $readme_dirs[ $parent_dir ] ) ) {
			$parent_dir = gutenberg_docs_dirname( $parent_dir );
		}
	}
	if ( ! $is_readme ) {
		$parent_dir = $dir;
		while ( $parent_dir !== '' && ! isset( $readme_dirs[ $parent_dir ] ) ) {
			$parent_dir = gutenberg_docs_dirname( $parent_dir );
		}
	}

	$fallback_slug = $is_readme
		? ( $dir === '' ? 'block-editor-handbook' : basename( $dir ) )
		: preg_replace( '/\.md$/', '', basename( $source ) );
	$title = gutenberg_docs_title_from_markdown( $markdown, $fallback_slug );
	$slug = gutenberg_docs_slugify( $fallback_slug );
	$parent_key = $parent_dir;
	$used_key = $parent_key === '' ? '__root__' : $parent_key;
	if ( ! isset( $used_slugs[ $used_key ] ) ) {
		$used_slugs[ $used_key ] = array();
	}
	$base_slug = $slug;
	$suffix = 2;
	while ( isset( $used_slugs[ $used_key ][ $slug ] ) ) {
		$slug = $base_slug . '-' . $suffix;
		$suffix++;
	}
	$used_slugs[ $used_key ][ $slug ] = true;

	$records[ $source ] = array(
		'id' => $id++,
		'source' => $source,
		'title' => $title,
		'slug' => $slug,
		'is_readme' => $is_readme,
		'dir' => $dir,
		'parent_dir' => $parent_dir,
	);
	if ( $is_readme ) {
		$dir_records[ $dir ] = $source;
	}
}

$dir_output_paths = array( '' => '' );
foreach ( $records as $source => &$record ) {
	if ( ! $record['is_readme'] ) {
		continue;
	}
	if ( $record['dir'] === '' ) {
		$record['relative_output'] = $record['id'] . '-' . $record['slug'] . '.md';
		$dir_output_paths[''] = '';
	} else {
		$parent_output = isset( $dir_output_paths[ $record['parent_dir'] ] ) ? $dir_output_paths[ $record['parent_dir'] ] : '';
		$segment = $record['id'] . '-' . $record['slug'];
		$dir_output_paths[ $record['dir'] ] = gutenberg_docs_join_path( $parent_output, $segment );
		$record['relative_output'] = gutenberg_docs_join_path( $dir_output_paths[ $record['dir'] ], 'index.md' );
	}
}
unset( $record );

foreach ( $records as $source => &$record ) {
	if ( $record['is_readme'] ) {
		continue;
	}
	$parent_output = isset( $dir_output_paths[ $record['parent_dir'] ] ) ? $dir_output_paths[ $record['parent_dir'] ] : '';
	$record['relative_output'] = gutenberg_docs_join_path(
		$parent_output,
		$record['id'] . '-' . $record['slug'] . '.md'
	);
}
unset( $record );

$permalinks = array();
foreach ( $records as $source => $record ) {
	$segments = array( $record['slug'] );
	$parent_dir = $record['is_readme'] ? $record['parent_dir'] : $record['parent_dir'];
	while ( $parent_dir !== '' && isset( $dir_records[ $parent_dir ] ) ) {
		array_unshift( $segments, $records[ $dir_records[ $parent_dir ] ]['slug'] );
		$parent_dir = $records[ $dir_records[ $parent_dir ] ]['parent_dir'];
	}
	$permalinks[ $source ] = '/' . implode( '/', $segments ) . '/';
}

foreach ( $records as $source => $record ) {
	$markdown = file_get_contents( $source_root . '/' . $source );
	$markdown = gutenberg_docs_rewrite_markdown_links( $markdown, $source, $source_exists, $permalinks );
	$target = $target_root . '/' . $record['relative_output'];
	$target_dir = dirname( $target );
	if ( ! is_dir( $target_dir ) && ! mkdir( $target_dir, 0777, true ) ) {
		throw new RuntimeException( 'Could not create ' . $target_dir );
	}
	$front_matter = sprintf(
		"---\npost_title = \"%s\"\npost_name = \"%s\"\npost_status = \"publish\"\npost_type = \"page\"\npost_date_gmt = \"2026-05-16 00:00:00\"\npost_modified_gmt = \"2026-05-16 00:00:00\"\n---\n",
		addcslashes( $record['title'], "\\\"" ),
		addcslashes( $record['slug'], "\\\"" )
	);
	file_put_contents( $target, $front_matter . ltrim( $markdown ) );
}
