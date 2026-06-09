<?php
/**
 * Structured docs Markdown dialect fixture matrix.
 *
 * @package UniversalImporter\Tests\Fixtures
 */

$cases = array();

$text = function ( array $lines ) {
	return implode( "\n", $lines );
};

$metadata = function ( array $flavors = array(), array $counts = array(), $normalized = false ) {
	$flavors = array_values( $flavors );
	sort( $flavors, SORT_STRING );

	$result = array();
	if ( ! empty( $flavors ) ) {
		$result['markdown_docs_flavors'] = $flavors;
	}
	if ( ! empty( $counts['admonitions'] ) ) {
		$result['markdown_docs_admonition_count'] = (int) $counts['admonitions'];
	}
	if ( ! empty( $counts['mdx_removed'] ) ) {
		$result['markdown_mdx_lines_removed'] = (int) $counts['mdx_removed'];
	}
	if ( ! empty( $counts['wikilinks'] ) ) {
		$result['markdown_obsidian_wikilink_count'] = (int) $counts['wikilinks'];
	}
	if ( ! empty( $counts['embeds'] ) ) {
		$result['markdown_obsidian_embed_count'] = (int) $counts['embeds'];
	}
	if ( ! empty( $counts['callouts'] ) ) {
		$result['markdown_obsidian_callout_count'] = (int) $counts['callouts'];
	}
	if ( ! empty( $counts['markdoc'] ) ) {
		$result['markdown_markdoc_construct_count'] = (int) $counts['markdoc'];
	}
	if ( $normalized ) {
		$result['markdown_docs_conventions_normalized'] = true;
	}

	return $result;
};

$add_case = function ( $flavor, $id, $source_path, $input, $expected_content, array $expected_metadata, $notes ) use ( &$cases ) {
	$cases[] = array(
		'id'                => (string) $id,
		'flavor'            => (string) $flavor,
		'source_path'       => (string) $source_path,
		'input'             => (string) $input,
		'expected_content'  => (string) $expected_content,
		'expected_metadata' => $expected_metadata,
		'notes'             => (string) $notes,
	);
};

$add_identity_cases = function ( $flavor, array $definitions, $path_prefix, array $expected_metadata, $notes ) use ( $add_case, $text ) {
	foreach ( $definitions as $id => $lines ) {
		$input = $text( $lines );
		$add_case( $flavor, $id, $path_prefix . '/' . $id . '.md', $input, $input, $expected_metadata, $notes );
	}
};

$commonmark_cases = array(
	'commonmark-fence-backtick-basic' => array( '```md', '[[Do Not Convert]]', ':::note', '```' ),
	'commonmark-fence-tilde-basic' => array( '~~~md', '![[image.png]]', '{% callout %}', '~~~' ),
	'commonmark-fence-nonclosing-tail' => array( '```md', '```not a close', '[[Still Raw]]', '```' ),
	'commonmark-fence-longer-backtick' => array( '````md', '```', '[[Still Raw]]', '````' ),
	'commonmark-fence-closing-trailing-spaces' => array( '```md', '[[Still Raw]]', '```   ' ),
	'commonmark-fence-wrong-marker-then-close' => array( '```md', '~~~', '[[Still Raw]]', '```' ),
	'commonmark-fence-unclosed-eof' => array( '```md', '[[Still Raw]]', ':::warning' ),
	'commonmark-fence-three-space-indent' => array( '   ```md', '[[Still Raw]]', '   ```' ),
	'commonmark-indented-code-four-spaces' => array( '    [[Still Raw]]', '    :::tip', '    import Thing from "./x";' ),
	'commonmark-indented-code-tab' => array( "\t[[Still Raw]]", "\t![[image.png]]" ),
	'commonmark-fence-admonition-body' => array( '```', ':::danger', 'Danger body', ':::', '```' ),
	'commonmark-fence-mdx-import-body' => array( '```mdx', "import Thing from './Thing';", '<Cards>', '</Cards>', '```' ),
	'commonmark-fence-markdoc-body' => array( '```markdoc', '{% callout type="note" %}', '{{ $frontmatter.title }}', '{% /callout %}', '```' ),
	'commonmark-fence-obsidian-embed-body' => array( '```', '![[assets/diagram.png|Diagram]]', '```' ),
	'commonmark-fence-shorter-run-inside' => array( '````', '```', '[[Still Raw]]', '````' ),
	'commonmark-fence-longer-run-inside' => array( '```', '````not a close', '[[Still Raw]]', '```' ),
	'commonmark-fence-tilde-short-close' => array( '~~~~', '~~~', '[[Still Raw]]', '~~~~' ),
	'commonmark-fence-tilde-long-close' => array( '~~~', '[[Still Raw]]', '~~~~' ),
	'commonmark-fence-info-spaces' => array( '``` php start', '[[Still Raw]]', '```' ),
	'commonmark-fence-empty' => array( '```', '```' ),
	'commonmark-escape-asterisk' => array( '\*not emphasized*' ),
	'commonmark-escape-bracket-link' => array( '\[not a link](/url)' ),
	'commonmark-escape-backtick' => array( '\`not code`' ),
	'commonmark-escape-wikilink' => array( '\[[Not Obsidian]]' ),
	'commonmark-escape-embed' => array( '\![[Not Embed]]' ),
	'commonmark-escape-admonition-marker' => array( '\:::note is prose' ),
	'commonmark-escape-image-open' => array( '!\[alt](image.png)' ),
	'commonmark-escape-parens' => array( '[link]\(literal)' ),
	'commonmark-escape-hash-heading' => array( '\# Not a heading' ),
	'commonmark-escape-pipe-table' => array( 'a \| b' ),
	'commonmark-escape-braces' => array( '\{not markdoc}' ),
	'commonmark-escape-angle' => array( '\<NotAComponent>' ),
	'commonmark-inline-code-wikilink' => array( 'Inline code keeps `[[Not Obsidian]]` raw.' ),
	'commonmark-inline-code-embed' => array( 'Inline code keeps `![[image.png]]` raw.' ),
	'commonmark-inline-code-callout' => array( 'Inline code keeps `> [!NOTE] raw` text.' ),
	'commonmark-inline-code-admonition' => array( 'Inline code keeps `:::note` text.' ),
	'commonmark-inline-code-mdx' => array( 'Inline code keeps `<Cards>` raw.' ),
	'commonmark-inline-code-markdoc' => array( 'Inline code keeps `{% callout %}` raw.' ),
	'commonmark-inline-code-double-tick' => array( 'Double ticks keep ``code with ` and [[raw]]`` text.' ),
	'commonmark-inline-code-many-ticks' => array( 'Triple ticks keep ```[[raw]] and ![[raw]]``` text.' ),
	'commonmark-inline-code-with-link' => array( 'A real [link](guide.md) and `[[raw]]`.' ),
	'commonmark-inline-code-unicode-escaped' => array( 'Escaped code-ish \`\[[raw]]\` remains text.' ),
	'commonmark-link-escaped-brackets' => array( '[a \[nested\] label](../guide.md)' ),
	'commonmark-link-nested-brackets' => array( '[outer [inner] label](../guide.md)' ),
	'commonmark-link-encoded-url' => array( '[encoded](../Guide%20Name.md#Part%202)' ),
	'commonmark-link-title-double' => array( '[title](../guide.md "Guide title")' ),
	'commonmark-link-title-single' => array( "[title](../guide.md 'Guide title')" ),
	'commonmark-image-fragment' => array( '![chart](images/chart.png#xywh=1,2,3,4)' ),
	'commonmark-reference-link' => array( 'Use [reference][guide].', '', '[guide]: ../guide.md "Guide"' ),
	'commonmark-collapsed-reference' => array( 'Use [guide][].', '', '[guide]: ../guide.md' ),
	'commonmark-shortcut-reference' => array( 'Use [guide].', '', '[guide]: ../guide.md' ),
	'commonmark-autolink' => array( '<https://example.test/%5B%5Braw%5D%5D>' ),
	'commonmark-html-comment' => array( '<!-- [[Raw Link]] and ![[raw.png]] stay HTML -->' ),
	'commonmark-html-section-block' => array( '<section>', '[[Raw Link]]', '![[raw.png]]', '</section>', '', 'After block.' ),
	'commonmark-html-div-block' => array( '<div class="docs">', ':::note', '[[Raw Link]]', '</div>', '', 'After block.' ),
	'commonmark-html-uppercase-div-block' => array( '<DIV class="docs">', '[[Raw Link]]', '</DIV>', '', 'After block.' ),
	'commonmark-html-script-block' => array( '<script>', 'const note = "[[Raw Link]]";', '</script>' ),
	'commonmark-html-pre-block' => array( '<pre>', '[[Raw Link]]', '</pre>' ),
	'commonmark-html-table-block' => array( '<table>', '<tr><td>[[Raw Link]]</td></tr>', '</table>' ),
	'commonmark-blockquote-escaped' => array( '> \[[Raw Link]] stays escaped.' ),
	'commonmark-list-inline-code' => array( '- keep `[[Raw Link]]` in list code' ),
	'commonmark-table-inline-code' => array( '| Item | Raw |', '| --- | --- |', '| one | `[[Raw Link]]` |' ),
	'commonmark-list-escaped-embed' => array( '1. \![[raw.png]] stays escaped' ),
);

$add_identity_cases( 'commonmark', $commonmark_cases, 'fixtures/commonmark', array(), 'Original CommonMark-focused fixture text; expected content is byte-stable.' );

$obsidian_meta = function ( $wikilinks = 0, $embeds = 0, $callouts = 0 ) use ( $metadata ) {
	if ( 0 === $wikilinks && 0 === $embeds && 0 === $callouts ) {
		return array();
	}

	return $metadata(
		array( 'obsidian' ),
		array(
			'wikilinks' => $wikilinks,
			'embeds'   => $embeds,
			'callouts' => $callouts,
		),
		true
	);
};

$add_obsidian = function ( $id, $input, $expected, $wikilinks, $embeds, $callouts, $notes ) use ( $add_case, $obsidian_meta ) {
	$add_case( 'obsidian', $id, 'vault/' . $id . '.md', $input, $expected, $obsidian_meta( $wikilinks, $embeds, $callouts ), $notes );
};

$add_obsidian( 'obsidian-link-basic', 'See [[Page]].', 'See [Page](Page.md).', 1, 0, 0, 'Basic wikilink target.' );
$add_obsidian( 'obsidian-link-alias', 'See [[Page|Readable page]].', 'See [Readable page](Page.md).', 1, 0, 0, 'Alias label.' );
$add_obsidian( 'obsidian-link-heading', 'See [[Guide#Part Two]].', 'See [Guide](Guide.md#Part%20Two).', 1, 0, 0, 'Document heading fragment.' );
$add_obsidian( 'obsidian-link-local-heading', 'See [[#Local Heading]].', 'See [Local Heading](#Local%20Heading).', 1, 0, 0, 'Local heading fragment.' );
$add_obsidian( 'obsidian-link-block-ref', 'See [[Research Note#^block-id|saved block]].', 'See [saved block](Research%20Note.md#%5Eblock-id).', 1, 0, 0, 'Block reference alias.' );
$add_obsidian( 'obsidian-link-heading-block', 'See [[Research Note#Heading^block-id|heading block]].', 'See [heading block](Research%20Note.md#Heading%5Eblock-id).', 1, 0, 0, 'Heading and block reference.' );
$add_obsidian( 'obsidian-link-spaces', 'See [[Folder/Name With Space|space target]].', 'See [space target](Folder/Name%20With%20Space.md).', 1, 0, 0, 'Path spaces are encoded.' );
$add_obsidian( 'obsidian-link-slashes', 'See [[Guides/Setup/Install]].', 'See [Install](Guides/Setup/Install.md).', 1, 0, 0, 'Slash-separated note path.' );
$add_obsidian( 'obsidian-link-backslashes', 'See [[Guides\\Setup\\Install]].', 'See [Install](Guides/Setup/Install.md).', 1, 0, 0, 'Backslashes normalize to URL separators.' );
$add_obsidian( 'obsidian-link-parentheses', 'See [[Guide (v2)|Guide v2]].', 'See [Guide v2](Guide%20%28v2%29.md).', 1, 0, 0, 'Parentheses are percent-encoded.' );
$add_obsidian( 'obsidian-link-bracket-target', 'See [[Guide [v2]|Guide [stable]]].', 'See [Guide &#91;stable&#93;](Guide%20%5Bv2%5D.md).', 1, 0, 0, 'Bracket target and alias escaping.' );
$add_obsidian( 'obsidian-link-punctuation', 'See [[Alpha, Beta|comma note]].', 'See [comma note](Alpha%2C%20Beta.md).', 1, 0, 0, 'Punctuation in targets is encoded.' );
$add_obsidian( 'obsidian-link-existing-extension', 'See [[Docs/Page.md]].', 'See [Page](Docs/Page.md).', 1, 0, 0, 'Existing extension is preserved.' );
$add_obsidian( 'obsidian-link-pdf-target', 'See [[Files/Paper.pdf]].', 'See [Paper](Files/Paper.pdf).', 1, 0, 0, 'Non-Markdown extensions are not suffixed.' );
$add_obsidian( 'obsidian-link-http-url', 'See [[https://example.test/page|external]].', 'See [external](https://example.test/page).', 1, 0, 0, 'URI schemes are preserved.' );
$add_obsidian( 'obsidian-link-mailto-url', 'Mail [[mailto:docs@example.test|the docs team]].', 'Mail [the docs team](mailto:docs@example.test).', 1, 0, 0, 'Mailto URI scheme.' );
$add_obsidian( 'obsidian-link-empty-alias', 'See [[Target|]].', 'See [Target](Target.md).', 1, 0, 0, 'Empty alias falls back to target label.' );
$add_obsidian( 'obsidian-link-pipe-alias', 'See [[Target|Alias | Extra]].', 'See [Alias | Extra](Target.md).', 1, 0, 0, 'Alias may contain a pipe after the first separator.' );
$add_obsidian( 'obsidian-link-trailing-slash', 'See [[Folder/]].', 'See [Folder](Folder.md).', 1, 0, 0, 'Trailing slash is trimmed for note URL.' );
$add_obsidian( 'obsidian-link-unicode', "See [[\xc3\x9cberblick|\xc3\x9cbersicht]].", "See [\xc3\x9cbersicht](%C3%9Cberblick.md).", 1, 0, 0, 'UTF-8 target bytes are encoded.' );
$add_obsidian( 'obsidian-link-encoded-percent', 'See [[Already%20Encoded|encoded source]].', 'See [encoded source](Already%2520Encoded.md).', 1, 0, 0, 'Existing percent signs are encoded literally.' );
$add_obsidian( 'obsidian-link-fragment-slash', 'See [[Guide#Part/Two|part slash]].', 'See [part slash](Guide.md#Part%2FTwo).', 1, 0, 0, 'Fragment slashes are encoded.' );
$add_obsidian( 'obsidian-link-local-block', 'See [[#^block-id]].', 'See [^block id](#%5Eblock-id).', 1, 0, 0, 'Local block reference.' );
$add_obsidian( 'obsidian-link-label-brackets', 'See [[Guide (v2)|Guide [v2]]].', 'See [Guide &#91;v2&#93;](Guide%20%28v2%29.md).', 1, 0, 0, 'Alias brackets are HTML-escaped.' );
$add_obsidian( 'obsidian-link-multiple-line', 'See [[One]], [[Two|second]], and [[Three#Part|third]].', 'See [One](One.md), [second](Two.md), and [third](Three.md#Part).', 3, 0, 0, 'Multiple wikilinks in one line.' );

$add_obsidian( 'obsidian-embed-image', '![[assets/diagram.png]]', '![diagram](assets/diagram.png)', 0, 1, 0, 'Image embed.' );
$add_obsidian( 'obsidian-embed-image-alias', '![[assets/diagram.png|Architecture diagram]]', '![Architecture diagram](assets/diagram.png)', 0, 1, 0, 'Image embed alias.' );
$add_obsidian( 'obsidian-embed-image-dimensions', '![[assets/diagram.png|200x300]]', '![200x300](assets/diagram.png)', 0, 1, 0, 'Dimension-style embed alias.' );
$add_obsidian( 'obsidian-embed-pdf', '![[docs/paper.pdf]]', '![paper](docs/paper.pdf)', 0, 1, 0, 'PDF embed.' );
$add_obsidian( 'obsidian-embed-pdf-fragment', '![[docs/paper.pdf#page=2|page 2]]', '![page 2](docs/paper.pdf#page%3D2)', 0, 1, 0, 'PDF fragment embed.' );
$add_obsidian( 'obsidian-embed-note', '![[Research Note]]', '![Research Note](Research%20Note)', 0, 1, 0, 'Embedded note keeps embed URL policy.' );
$add_obsidian( 'obsidian-embed-subfolder-space', '![[assets/Chart 1.png]]', '![Chart 1](assets/Chart%201.png)', 0, 1, 0, 'Subfolder image with spaces.' );
$add_obsidian( 'obsidian-embed-external-url', '![[https://example.test/image.png|remote image]]', '![remote image](https://example.test/image.png)', 0, 1, 0, 'External image URL embed.' );
$add_obsidian( 'obsidian-embed-escaped', '\![[assets/raw.png]]', '\![[assets/raw.png]]', 0, 0, 0, 'Escaped embed remains raw.' );
$add_obsidian( 'obsidian-embed-empty', '![[ ]]', '![[ ]]', 0, 0, 0, 'Empty embed remains raw.' );

$add_obsidian( 'obsidian-callout-note-title', '> [!NOTE] Field note', '> **Note:** Field note', 0, 0, 1, 'Callout title.' );
$add_obsidian( 'obsidian-callout-tip-expanded', '> [!TIP]+ Expanded tip', '> **Tip:** Expanded tip', 0, 0, 1, 'Expanded modifier is removed.' );
$add_obsidian( 'obsidian-callout-warning-collapsed', '> [!WARNING]- Collapsed warning', '> **Warning:** Collapsed warning', 0, 0, 1, 'Collapsed modifier is removed.' );
$add_obsidian( 'obsidian-callout-no-title', '> [!INFO]', '> **Info:**', 0, 0, 1, 'Callout without title.' );
$add_obsidian( 'obsidian-callout-custom-type', '> [!abstract] Summary', '> **Abstract:** Summary', 0, 0, 1, 'Unknown callout type is title-cased.' );
$add_obsidian( 'obsidian-callout-empty-type', '> [!] Empty type', '> **Note:** Empty type', 0, 0, 1, 'Empty callout type falls back to Note.' );
$add_obsidian( 'obsidian-callout-title-wikilink', '> [!NOTE] See [[Page|page]]', '> **Note:** See [page](Page.md)', 1, 0, 1, 'Wikilink in callout title.' );
$add_obsidian( 'obsidian-callout-title-embed', '> [!TIP] ![[diagram.png]]', '> **Tip:** ![diagram](diagram.png)', 0, 1, 1, 'Embed in callout title.' );
$add_obsidian( 'obsidian-callout-body-wikilink', "> [!NOTE]\n> Body [[Page]].", "> **Note:**\n> Body [Page](Page.md).", 1, 0, 1, 'Wikilink in callout body.' );
$add_obsidian( 'obsidian-callout-nested', "> [!NOTE]\n> > [!TIP] Nested", "> **Note:**\n> > **Tip:** Nested", 0, 0, 2, 'Nested callout opener.' );
$add_obsidian( 'obsidian-callout-indented', '  > [!INFO] Indented', '  > **Info:** Indented', 0, 0, 1, 'Indented blockquote callout.' );
$add_obsidian( 'obsidian-callout-todo-alias', '> [!todo] Ship it', '> **Todo:** Ship it', 0, 0, 1, 'Alias-like callout type.' );
$add_obsidian( 'obsidian-callout-title-only', '> [!QUESTION]?', '> **Question:** ?', 0, 0, 1, 'Title-only callout.' );
$add_obsidian( 'obsidian-callout-malformed-open', '> [!NOTE Missing close', '> [!NOTE Missing close', 0, 0, 0, 'Malformed callout is preserved.' );
$add_obsidian( 'obsidian-callout-list-not-opener', '- > [!NOTE] list quote text', '- > [!NOTE] list quote text', 0, 0, 0, 'List text is not a top-level callout opener.' );

$add_obsidian( 'obsidian-preserve-inline-code', 'Code `[[Raw Link]]` stays raw.', 'Code `[[Raw Link]]` stays raw.', 0, 0, 0, 'Inline code preservation.' );
$add_obsidian( 'obsidian-preserve-double-code', 'Code ``![[raw.png]] and `ticks` `` stays raw.', 'Code ``![[raw.png]] and `ticks` `` stays raw.', 0, 0, 0, 'Double-backtick code span preservation.' );
$add_obsidian( 'obsidian-preserve-fenced-code', "```md\n[[Raw Link]]\n![[raw.png]]\n> [!NOTE] Raw\n```", "```md\n[[Raw Link]]\n![[raw.png]]\n> [!NOTE] Raw\n```", 0, 0, 0, 'Fenced code preservation.' );
$add_obsidian( 'obsidian-preserve-indented-code', "    [[Raw Link]]\n    ![[raw.png]]", "    [[Raw Link]]\n    ![[raw.png]]", 0, 0, 0, 'Indented code preservation.' );
$add_obsidian( 'obsidian-preserve-escaped-wikilink', '\[[Raw Link]]', '\[[Raw Link]]', 0, 0, 0, 'Escaped wikilink.' );
$add_obsidian( 'obsidian-preserve-escaped-embed', '\![[raw.png]]', '\![[raw.png]]', 0, 0, 0, 'Escaped embed.' );
$add_obsidian( 'obsidian-preserve-empty-wikilink', '[[]]', '[[]]', 0, 0, 0, 'Empty wikilink.' );
$add_obsidian( 'obsidian-preserve-nested-invalid', '[[Nested [[Nope]]]]', '[[Nested [[Nope]]]]', 0, 0, 0, 'Nested invalid wikilink.' );
$add_obsidian( 'obsidian-preserve-unclosed', '[[Unclosed target', '[[Unclosed target', 0, 0, 0, 'Unclosed wikilink.' );
$add_obsidian( 'obsidian-table-link', "| Item | Link |\n| --- | --- |\n| one | [[Table|table link]] |", "| Item | Link |\n| --- | --- |\n| one | [table link](Table.md) |", 1, 0, 0, 'Wikilink in table cell.' );

$docusaurus_meta = function ( array $counts = array(), $normalized = true ) use ( $metadata ) {
	$flavors = array( 'docusaurus' );
	if ( ! empty( $counts['wikilinks'] ) || ! empty( $counts['embeds'] ) || ! empty( $counts['callouts'] ) ) {
		$flavors[] = 'obsidian';
	}

	return $metadata( $flavors, $counts, $normalized );
};

$add_docusaurus = function ( $id, array $input_lines, array $expected_lines, array $counts, $normalized, $notes ) use ( $add_case, $docusaurus_meta, $text ) {
	$add_case( 'docusaurus', $id, 'docs/' . $id . '.mdx', $text( $input_lines ), $text( $expected_lines ), $docusaurus_meta( $counts, $normalized ), $notes );
};

$add_docusaurus( 'docusaurus-note-empty', array( ':::note', ':::' ), array( '> **Note:**' ), array( 'admonitions' => 1 ), true, 'Empty note admonition.' );
$add_docusaurus( 'docusaurus-tip-title', array( ':::tip Use the API', 'Body.', ':::' ), array( '> **Tip:** Use the API', '> Body.' ), array( 'admonitions' => 1 ), true, 'Plain title.' );
$add_docusaurus( 'docusaurus-info-bracket-title', array( ':::info[Release channel]', 'Stable.', ':::' ), array( '> **Info:** Release channel', '> Stable.' ), array( 'admonitions' => 1 ), true, 'Bracket title.' );
$add_docusaurus( 'docusaurus-warning-title-attribute', array( ':::warning Pay attention {#warn}', 'Read this.', ':::' ), array( '> **Warning:** Pay attention', '> Read this.' ), array( 'admonitions' => 1 ), true, 'Title attributes are stripped.' );
$add_docusaurus( 'docusaurus-danger-uppercase', array( ':::DANGER Stop', 'No deploys.', ':::' ), array( '> **Danger:** Stop', '> No deploys.' ), array( 'admonitions' => 1 ), true, 'Type matching is case-insensitive.' );
$add_docusaurus( 'docusaurus-caution-long-marker', array( ':::::caution[Long marker]', 'Careful.', ':::::' ), array( '> **Caution:** Long marker', '> Careful.' ), array( 'admonitions' => 1 ), true, 'Long colon opener and close.' );
$add_docusaurus( 'docusaurus-nested-info-warning', array( '::::info[Parent]', 'Parent body.', ':::warning[Child]', 'Child body.', ':::', '::::' ), array( '> **Info:** Parent', '> Parent body.', '> > **Warning:** Child', '> > Child body.' ), array( 'admonitions' => 2 ), true, 'Nested admonitions with different marker lengths.' );
$add_docusaurus( 'docusaurus-nested-three-levels', array( ':::::note[One]', '::::tip[Two]', ':::danger[Three]', 'Deep.', ':::', '::::', ':::::' ), array( '> **Note:** One', '> > **Tip:** Two', '> > > **Danger:** Three', '> > > Deep.' ), array( 'admonitions' => 3 ), true, 'Three-level nesting.' );
$add_docusaurus( 'docusaurus-blank-line-body', array( ':::note', '', 'After blank.', ':::' ), array( '> **Note:**', '>', '> After blank.' ), array( 'admonitions' => 1 ), true, 'Blank line inside admonition.' );
$add_docusaurus( 'docusaurus-list-body', array( ':::tip', '- first', '- second', ':::' ), array( '> **Tip:**', '> - first', '> - second' ), array( 'admonitions' => 1 ), true, 'List body.' );
$add_docusaurus( 'docusaurus-blockquote-body', array( ':::info', '> quoted', ':::' ), array( '> **Info:**', '> > quoted' ), array( 'admonitions' => 1 ), true, 'Blockquote body.' );
$add_docusaurus( 'docusaurus-table-body', array( ':::note', '| A | B |', '| --- | --- |', '| 1 | 2 |', ':::' ), array( '> **Note:**', '> | A | B |', '> | --- | --- |', '> | 1 | 2 |' ), array( 'admonitions' => 1 ), true, 'Table body.' );
$add_docusaurus( 'docusaurus-code-fence-body', array( ':::note', '```js', 'const value = 1;', '```', ':::' ), array( '> **Note:**', '> ```js', '> const value = 1;', '> ```' ), array( 'admonitions' => 1 ), true, 'Fence inside admonition stays in blockquote.' );
$add_docusaurus( 'docusaurus-link-body', array( ':::note', 'Read [guide](../guide.md).', ':::' ), array( '> **Note:**', '> Read [guide](../guide.md).' ), array( 'admonitions' => 1 ), true, 'Markdown link body.' );
$add_docusaurus( 'docusaurus-image-body', array( ':::note', '![Chart](../chart.png)', ':::' ), array( '> **Note:**', '> ![Chart](../chart.png)' ), array( 'admonitions' => 1 ), true, 'Markdown image body.' );
$add_docusaurus( 'docusaurus-wikilink-body', array( ':::note', 'Read [[Guide|the guide]].', ':::' ), array( '> **Note:**', '> Read [the guide](Guide.md).' ), array( 'admonitions' => 1, 'wikilinks' => 1 ), true, 'Obsidian-looking body content is normalized inside admonitions.' );
$add_docusaurus( 'docusaurus-embed-body', array( ':::note', '![[chart.png]]', ':::' ), array( '> **Note:**', '> ![chart](chart.png)' ), array( 'admonitions' => 1, 'embeds' => 1 ), true, 'Embed body content is normalized inside admonitions.' );
$add_docusaurus( 'docusaurus-mdx-wrapper-inside', array( ':::tip', '<Tabs>', '</Tabs>', 'Use docs.', ':::' ), array( '> **Tip:**', '> Use docs.' ), array( 'admonitions' => 1, 'mdx_removed' => 2 ), true, 'Wrapper-only MDX lines inside admonitions are skipped.' );
$add_docusaurus( 'docusaurus-mdx-import-inside', array( ':::tip', "import TabItem from '@theme/TabItem';", 'Use docs.', ':::' ), array( '> **Tip:**', '> Use docs.' ), array( 'admonitions' => 1, 'mdx_removed' => 1 ), true, 'ESM import inside admonition is skipped.' );
$add_docusaurus( 'docusaurus-contentful-component-inside', array( ':::tip', '<Note>Keep this.</Note>', ':::' ), array( '> **Tip:**', '> <Note>Keep this.</Note>' ), array( 'admonitions' => 1 ), true, 'Contentful component text inside admonition is preserved.' );
$add_docusaurus( 'docusaurus-wrong-short-close', array( '::::note', 'Body.', ':::', 'After.' ), array( '> **Note:**', '> Body.', '> :::', '> After.' ), array( 'admonitions' => 1 ), true, 'Too-short close marker is body text.' );
$add_docusaurus( 'docusaurus-close-with-trailing-text', array( ':::note', 'Body.', '::: still open', 'After.' ), array( '> **Note:**', '> Body.', '> ::: still open', '> After.' ), array( 'admonitions' => 1 ), true, 'Close marker with trailing text is body text.' );
$add_docusaurus( 'docusaurus-unclosed-eof', array( ':::warning', 'Body to EOF.' ), array( '> **Warning:**', '> Body to EOF.' ), array( 'admonitions' => 1 ), true, 'Unclosed admonition is converted through EOF.' );
$add_docusaurus( 'docusaurus-leading-space-open', array( '  :::note Indented marker', 'Body.', ':::' ), array( '> **Note:** Indented marker', '> Body.' ), array( 'admonitions' => 1 ), true, 'Leading spaces before opener are accepted.' );
$add_docusaurus( 'docusaurus-bracket-title-nested', array( ':::tip[Use [nested] title]', 'Body.', ':::' ), array( '> **Tip:** Use [nested] title', '> Body.' ), array( 'admonitions' => 1 ), true, 'Balanced bracket title.' );
$add_docusaurus( 'docusaurus-body-colons', array( ':::note', '::: not a close because text follows', ':::' ), array( '> **Note:**', '> ::: not a close because text follows' ), array( 'admonitions' => 1 ), true, 'Colon body line with text.' );
$add_docusaurus( 'docusaurus-empty-title-brackets', array( ':::note[]', 'Body.', ':::' ), array( '> **Note:**', '> Body.' ), array( 'admonitions' => 1 ), true, 'Empty bracket title.' );
$add_docusaurus( 'docusaurus-close-longer-than-open', array( ':::note', 'Body.', ':::::' ), array( '> **Note:**', '> Body.' ), array( 'admonitions' => 1 ), true, 'Longer close marker closes.' );
$add_docusaurus( 'docusaurus-tabs-in-body-text', array( ':::info', "\tTabbed text stays body.", ':::' ), array( '> **Info:**', "> \tTabbed text stays body." ), array( 'admonitions' => 1 ), true, 'Indented code line inside admonition remains raw Markdown inside the blockquote.' );
$add_docusaurus( 'docusaurus-unknown-type', array( ':::success', 'Body.', ':::' ), array( ':::success', 'Body.', ':::' ), array(), false, 'Unknown admonition type is preserved.' );
$add_docusaurus( 'docusaurus-too-short-marker', array( '::note', 'Body.' ), array( '::note', 'Body.' ), array(), false, 'Too-short marker is not an admonition.' );
$add_docusaurus( 'docusaurus-close-without-open', array( ':::', 'Body.' ), array( ':::', 'Body.' ), array(), false, 'Close marker without opener is preserved.' );
$add_docusaurus( 'docusaurus-numeric-type', array( ':::note2', 'Body.', ':::' ), array( ':::note2', 'Body.', ':::' ), array(), false, 'Unknown identifier type is preserved.' );
$add_docusaurus( 'docusaurus-import-before-heading', array( "import Tabs from '@theme/Tabs';", '', '# Page' ), array( '', '# Page' ), array( 'mdx_removed' => 1 ), true, 'Single-line import is skipped.' );
$add_docusaurus( 'docusaurus-side-effect-import', array( "import '@site/src/css/custom.css';", '', 'Body.' ), array( '', 'Body.' ), array( 'mdx_removed' => 1 ), true, 'Side-effect import is skipped.' );
$add_docusaurus( 'docusaurus-multiline-import', array( 'import {', '  Tabs,', '  TabItem,', "} from '@theme/Tabs';", '', 'Body.' ), array( '', 'Body.' ), array( 'mdx_removed' => 4 ), true, 'Multiline import is skipped.' );
$add_docusaurus( 'docusaurus-export-metadata', array( 'export const metadata = {', "  title: 'Fixture',", '  sidebar_position: 2,', '};', '', 'Body.' ), array( '', 'Body.' ), array( 'mdx_removed' => 4 ), true, 'Metadata export is skipped.' );
$add_docusaurus( 'docusaurus-export-function', array( 'export function getTitle() {', "  return 'Title';", '}', '', 'Body.' ), array( '', 'Body.' ), array( 'mdx_removed' => 3 ), true, 'Exported function block is skipped.' );
$add_docusaurus( 'docusaurus-export-class', array( 'export default class Demo {', '  render() { return null; }', '}', '', 'Body.' ), array( '', 'Body.' ), array( 'mdx_removed' => 3 ), true, 'Exported class block is skipped.' );
$add_docusaurus( 'docusaurus-wrapper-pair', array( '<Tabs>', 'Body.', '</Tabs>' ), array( 'Body.' ), array( 'mdx_removed' => 2 ), true, 'Wrapper-only pair is skipped.' );
$add_docusaurus( 'docusaurus-wrapper-self-closing', array( '<Cards />', 'Body.' ), array( 'Body.' ), array( 'mdx_removed' => 1 ), true, 'Self-closing wrapper is skipped.' );
$add_docusaurus( 'docusaurus-wrapper-attributes', array( '<Cards columns={2}>', 'Body.', '</Cards>' ), array( 'Body.' ), array( 'mdx_removed' => 2 ), true, 'Wrapper with attributes is skipped.' );
$add_docusaurus( 'docusaurus-namespaced-wrapper', array( '<API.Reference id="x" />', 'Body.' ), array( 'Body.' ), array( 'mdx_removed' => 1 ), true, 'Namespaced-style component wrapper is skipped.' );
$add_docusaurus( 'docusaurus-wrapper-around-admonition', array( '<Tabs>', ':::note', 'Body.', ':::', '</Tabs>' ), array( '> **Note:**', '> Body.' ), array( 'admonitions' => 1, 'mdx_removed' => 2 ), true, 'Wrapper lines around admonition are skipped.' );
$add_docusaurus( 'docusaurus-contentful-component-line', array( '<Note>Keep this warning.</Note>' ), array( '<Note>Keep this warning.</Note>' ), array(), false, 'Contentful JSX-like line is preserved.' );
$add_docusaurus( 'docusaurus-lowercase-html-line', array( '<div>Keep HTML.</div>' ), array( '<div>Keep HTML.</div>' ), array(), false, 'Lowercase HTML line is preserved.' );
$add_docusaurus( 'docusaurus-import-prose', array( 'import prose should survive when it is ordinary text.' ), array( 'import prose should survive when it is ordinary text.' ), array(), false, 'Import-looking prose is preserved.' );
$add_docusaurus( 'docusaurus-export-prose', array( 'export prose should survive when it is ordinary text.' ), array( 'export prose should survive when it is ordinary text.' ), array(), false, 'Export-looking prose is preserved.' );
$add_docusaurus( 'docusaurus-fenced-mdx-raw', array( '```mdx', "import Kept from './Kept';", '<Cards>', '[[Raw Link]]', '</Cards>', '```' ), array( '```mdx', "import Kept from './Kept';", '<Cards>', '[[Raw Link]]', '</Cards>', '```' ), array(), false, 'MDX syntax in fenced code is preserved.' );
$add_docusaurus( 'docusaurus-inline-code-mdx-raw', array( 'Inline `<Cards>` and `import X from "x";` stay raw.' ), array( 'Inline `<Cards>` and `import X from "x";` stay raw.' ), array(), false, 'MDX syntax in inline code is preserved.' );
$add_docusaurus( 'docusaurus-admonition-after-import', array( "import Note from './Note';", ':::note', 'Body.', ':::' ), array( '> **Note:**', '> Body.' ), array( 'admonitions' => 1, 'mdx_removed' => 1 ), true, 'Import before admonition is skipped.' );
$add_docusaurus( 'docusaurus-import-after-admonition', array( ':::note', 'Body.', ':::', "import Note from './Note';" ), array( '> **Note:**', '> Body.' ), array( 'admonitions' => 1, 'mdx_removed' => 1 ), true, 'Import after admonition is skipped.' );
$add_docusaurus( 'docusaurus-component-after-admonition', array( ':::note', 'Body.', ':::', '<Cards />' ), array( '> **Note:**', '> Body.' ), array( 'admonitions' => 1, 'mdx_removed' => 1 ), true, 'Component after admonition is skipped.' );
$add_docusaurus( 'docusaurus-empty-wrapper-only-document', array( '<Cards>', '</Cards>' ), array(), array( 'mdx_removed' => 2 ), true, 'Wrapper-only document normalizes to empty content.' );
$add_docusaurus( 'docusaurus-semicolon-in-import-string', array( 'import Thing from "./semi;colon";', 'Body.' ), array( 'Body.' ), array( 'mdx_removed' => 1 ), true, 'Semicolon inside import string is not a declaration terminator.' );
$add_docusaurus( 'docusaurus-export-object-string-semicolon', array( 'export const data = { text: "a; b" };', 'Body.' ), array( 'Body.' ), array( 'mdx_removed' => 1 ), true, 'Semicolon inside exported object string.' );
$add_docusaurus( 'docusaurus-type-import', array( "import type { Props } from './types';", 'Body.' ), array( 'Body.' ), array( 'mdx_removed' => 1 ), true, 'Type import is skipped.' );
$add_docusaurus( 'docusaurus-export-array', array( 'export const items = [', "  'one',", "  'two',", '];', 'Body.' ), array( 'Body.' ), array( 'mdx_removed' => 4 ), true, 'Exported array is skipped.' );
$add_docusaurus( 'docusaurus-dotted-wrapper', array( '<API.Reference id="intro" />', 'Body.' ), array( 'Body.' ), array( 'mdx_removed' => 1 ), true, 'Dotted component wrapper is skipped.' );
$add_docusaurus( 'docusaurus-admonition-selfclosing-component-inside', array( ':::note', '<Cards />', 'Body.', ':::' ), array( '> **Note:**', '> Body.' ), array( 'admonitions' => 1, 'mdx_removed' => 1 ), true, 'Self-closing component inside admonition is skipped.' );

$astro_meta = function ( array $counts = array(), $normalized = false, $extension = 'mdoc' ) use ( $metadata ) {
	$flavors = array( 'astro' );
	if ( 'markdoc' === $extension || ! empty( $counts['markdoc'] ) ) {
		$flavors[] = 'markdoc';
	}
	if ( ! empty( $counts['admonitions'] ) ) {
		$flavors[] = 'docusaurus';
	}
	if ( ! empty( $counts['wikilinks'] ) || ! empty( $counts['embeds'] ) || ! empty( $counts['callouts'] ) ) {
		$flavors[] = 'obsidian';
	}

	return $metadata( $flavors, $counts, $normalized );
};

$add_astro = function ( $id, array $input_lines, array $expected_lines, array $counts, $normalized, $notes, $extension = 'mdoc' ) use ( $add_case, $astro_meta, $text ) {
	$add_case( 'astro', $id, 'src/content/docs/' . $id . '.' . $extension, $text( $input_lines ), $text( $expected_lines ), $astro_meta( $counts, $normalized, $extension ), $notes );
};

$add_astro( 'astro-frontmatter-basic', array( '---', 'title: Astro Page', 'description: Content collection page', '---', '', '# Page' ), array( '---', 'title: Astro Page', 'description: Content collection page', '---', '', '# Page' ), array(), false, 'Front matter-style content is preserved.' );
$add_astro( 'astro-content-heading', array( '# Guide', '', 'Astro content body.' ), array( '# Guide', '', 'Astro content body.' ), array(), false, 'Content collection heading.' );
$add_astro( 'astro-content-link', array( 'Read [routing](../routing.md).' ), array( 'Read [routing](../routing.md).' ), array(), false, 'Markdown link is preserved.' );
$add_astro( 'astro-content-image', array( '![Diagram](../../assets/diagram.png)' ), array( '![Diagram](../../assets/diagram.png)' ), array(), false, 'Markdown image is preserved.' );
$add_astro( 'astro-content-table', array( '| Name | Value |', '| --- | --- |', '| alpha | beta |' ), array( '| Name | Value |', '| --- | --- |', '| alpha | beta |' ), array(), false, 'Markdown table is preserved.' );
$add_astro( 'astro-content-list', array( '- collection entry', '- generated slug' ), array( '- collection entry', '- generated slug' ), array(), false, 'Markdown list is preserved.' );
$add_astro( 'astro-frontmatter-tags', array( '---', 'tags:', '  - docs', '  - astro', '---', '', 'Body.' ), array( '---', 'tags:', '  - docs', '  - astro', '---', '', 'Body.' ), array(), false, 'YAML-like tags are preserved.' );
$add_astro( 'astro-mdoc-extension-plain', array( 'Plain `.mdoc` content stays Markdown.' ), array( 'Plain `.mdoc` content stays Markdown.' ), array(), false, 'Mdoc extension flavor detection without normalization.' );
$add_astro( 'astro-markdoc-extension-plain', array( 'Plain `.markdoc` content stays Markdown.' ), array( 'Plain `.markdoc` content stays Markdown.' ), array(), false, 'Markdoc extension also reports Astro flavor.', 'markdoc' );
$add_astro( 'astro-content-code-fence', array( '```ts', 'const link = "[[raw]]";', '```' ), array( '```ts', 'const link = "[[raw]]";', '```' ), array(), false, 'Code fence is preserved.' );
$add_astro( 'astro-content-inline-code', array( 'Use `import.meta.glob()` in prose.' ), array( 'Use `import.meta.glob()` in prose.' ), array(), false, 'Inline code is preserved.' );
$add_astro( 'astro-content-html-block', array( '<section>', '[[raw]]', '</section>' ), array( '<section>', '[[raw]]', '</section>' ), array(), false, 'HTML block is preserved.' );
$add_astro( 'astro-content-comment', array( '<!-- [[raw]] in comment -->' ), array( '<!-- [[raw]] in comment -->' ), array(), false, 'HTML comment is preserved.' );
$add_astro( 'astro-content-import-prose', array( 'import maps are configured elsewhere.' ), array( 'import maps are configured elsewhere.' ), array(), false, 'Import-looking prose is preserved.' );
$add_astro( 'astro-content-export-prose', array( 'export controls are documented in this paragraph.' ), array( 'export controls are documented in this paragraph.' ), array(), false, 'Export-looking prose is preserved.' );

$add_astro( 'astro-import-component', array( "import Aside from '../components/Aside.astro';", '', '# Guide' ), array( '', '# Guide' ), array( 'mdx_removed' => 1 ), true, 'Astro component import is skipped.' );
$add_astro( 'astro-import-multiline', array( 'import {', '  Card,', '  CardGrid,', "} from '@astrojs/starlight/components';", '', 'Body.' ), array( '', 'Body.' ), array( 'mdx_removed' => 4 ), true, 'Multiline component import is skipped.' );
$add_astro( 'astro-import-side-effect', array( "import '../styles/docs.css';", 'Body.' ), array( 'Body.' ), array( 'mdx_removed' => 1 ), true, 'Side-effect import is skipped.' );
$add_astro( 'astro-export-const', array( 'export const prerender = true;', 'Body.' ), array( 'Body.' ), array( 'mdx_removed' => 1 ), true, 'Export const is skipped.' );
$add_astro( 'astro-export-object', array( 'export const frontmatter = {', "  title: 'Astro',", '};', 'Body.' ), array( 'Body.' ), array( 'mdx_removed' => 3 ), true, 'Exported object is skipped.' );
$add_astro( 'astro-export-function', array( 'export function getStaticPaths() {', '  return [];', '}', 'Body.' ), array( 'Body.' ), array( 'mdx_removed' => 3 ), true, 'Exported function is skipped.' );
$add_astro( 'astro-wrapper-aside', array( '<Aside>', 'Markdown inside.', '</Aside>' ), array( 'Markdown inside.' ), array( 'mdx_removed' => 2 ), true, 'Wrapper component pair is skipped.' );
$add_astro( 'astro-wrapper-self-closing', array( '<Badge text="New" />', 'Body.' ), array( 'Body.' ), array( 'mdx_removed' => 1 ), true, 'Self-closing component is skipped.' );
$add_astro( 'astro-wrapper-attributes', array( '<CardGrid stagger>', '- item', '</CardGrid>' ), array( '- item' ), array( 'mdx_removed' => 2 ), true, 'Wrapper attributes are skipped with wrapper.' );
$add_astro( 'astro-wrapper-dotted-name', array( '<Steps.Item title="One">', 'Body.', '</Steps.Item>' ), array( 'Body.' ), array( 'mdx_removed' => 2 ), true, 'Dotted component name wrapper is skipped.' );
$add_astro( 'astro-wrapper-contentful-same-line', array( '<Aside>Keep this inline content.</Aside>' ), array( '<Aside>Keep this inline content.</Aside>' ), array(), false, 'Contentful same-line component is preserved.' );
$add_astro( 'astro-component-inside-fence', array( '```mdx', '<Aside>', '[[raw]]', '</Aside>', '```' ), array( '```mdx', '<Aside>', '[[raw]]', '</Aside>', '```' ), array(), false, 'Component syntax in fence is preserved.' );
$add_astro( 'astro-import-inside-fence', array( '```js', "import Aside from './Aside.astro';", '```' ), array( '```js', "import Aside from './Aside.astro';", '```' ), array(), false, 'Import in fence is preserved.' );
$add_astro( 'astro-import-before-prose', array( "import Aside from './Aside.astro';", 'import text starts this paragraph.' ), array( 'import text starts this paragraph.' ), array( 'mdx_removed' => 1 ), true, 'Only declaration import is skipped.' );
$add_astro( 'astro-export-before-prose', array( 'export const title = "x";', 'export text starts this paragraph.' ), array( 'export text starts this paragraph.' ), array( 'mdx_removed' => 1 ), true, 'Only declaration export is skipped.' );

$add_astro( 'astro-admonition-tip', array( ':::tip', 'Use Starlight.', ':::' ), array( '> **Tip:**', '> Use Starlight.' ), array( 'admonitions' => 1 ), true, 'Starlight-style tip admonition.' );
$add_astro( 'astro-admonition-note-title', array( ':::note[Astro note]', 'Body.', ':::' ), array( '> **Note:** Astro note', '> Body.' ), array( 'admonitions' => 1 ), true, 'Bracket title admonition.' );
$add_astro( 'astro-admonition-caution', array( ':::caution Be careful', 'Body.', ':::' ), array( '> **Caution:** Be careful', '> Body.' ), array( 'admonitions' => 1 ), true, 'Caution admonition.' );
$add_astro( 'astro-admonition-nested', array( '::::tip[Outer]', ':::warning[Inner]', 'Body.', ':::', '::::' ), array( '> **Tip:** Outer', '> > **Warning:** Inner', '> > Body.' ), array( 'admonitions' => 2 ), true, 'Nested docs admonitions.' );
$add_astro( 'astro-admonition-list', array( ':::info', '- item', ':::' ), array( '> **Info:**', '> - item' ), array( 'admonitions' => 1 ), true, 'List inside admonition.' );
$add_astro( 'astro-admonition-code', array( ':::note', '```astro', '---', '---', '```', ':::' ), array( '> **Note:**', '> ```astro', '> ---', '> ---', '> ```' ), array( 'admonitions' => 1 ), true, 'Astro code fence inside admonition.' );
$add_astro( 'astro-admonition-import-inside', array( ':::tip', "import Aside from './Aside.astro';", 'Body.', ':::' ), array( '> **Tip:**', '> Body.' ), array( 'admonitions' => 1, 'mdx_removed' => 1 ), true, 'Import inside admonition is skipped.' );
$add_astro( 'astro-admonition-wrapper-inside', array( ':::tip', '<Aside>', '</Aside>', 'Body.', ':::' ), array( '> **Tip:**', '> Body.' ), array( 'admonitions' => 1, 'mdx_removed' => 2 ), true, 'Wrapper inside admonition is skipped.' );
$add_astro( 'astro-admonition-wikilink', array( ':::note', 'See [[Astro Guide|guide]].', ':::' ), array( '> **Note:**', '> See [guide](Astro%20Guide.md).' ), array( 'admonitions' => 1, 'wikilinks' => 1 ), true, 'Wikilink inside Astro admonition.' );
$add_astro( 'astro-admonition-embed', array( ':::note', '![[assets/starlight.png]]', ':::' ), array( '> **Note:**', '> ![starlight](assets/starlight.png)' ), array( 'admonitions' => 1, 'embeds' => 1 ), true, 'Embed inside Astro admonition.' );
$add_astro( 'astro-admonition-wrong-close', array( '::::note', 'Body.', ':::', 'After.' ), array( '> **Note:**', '> Body.', '> :::', '> After.' ), array( 'admonitions' => 1 ), true, 'Wrong close length remains body.' );
$add_astro( 'astro-admonition-close-trailing-text', array( ':::note', 'Body.', '::: later' ), array( '> **Note:**', '> Body.', '> ::: later' ), array( 'admonitions' => 1 ), true, 'Close with trailing text remains body.' );
$add_astro( 'astro-admonition-unknown', array( ':::success', 'Body.', ':::' ), array( ':::success', 'Body.', ':::' ), array(), false, 'Unknown admonition type is preserved.' );
$add_astro( 'astro-admonition-empty-body', array( ':::info', ':::' ), array( '> **Info:**' ), array( 'admonitions' => 1 ), true, 'Empty info admonition.' );
$add_astro( 'astro-admonition-html-body', array( ':::note', '<div>HTML body.</div>', ':::' ), array( '> **Note:**', '> <div>HTML body.</div>' ), array( 'admonitions' => 1 ), true, 'HTML body stays inside the generated admonition blockquote.' );

$add_astro( 'astro-markdoc-tag-in-mdoc', array( '{% aside %}', 'Body.', '{% /aside %}' ), array( 'Body.' ), array( 'markdoc' => 2 ), true, 'Markdoc tags in mdoc are skipped.' );
$add_astro( 'astro-markdoc-variable-in-mdoc', array( '{{ $frontmatter.title }}', 'Body.' ), array( 'Body.' ), array( 'markdoc' => 1 ), true, 'Markdoc variable in mdoc is skipped.' );
$add_astro( 'astro-markdoc-comment-in-mdoc', array( '{# internal note #}', 'Body.' ), array( 'Body.' ), array( 'markdoc' => 1 ), true, 'Markdoc comment in mdoc is skipped.' );
$add_astro( 'astro-markdoc-extension-tag', array( '{% callout type="note" %}', 'Body.', '{% /callout %}' ), array( 'Body.' ), array( 'markdoc' => 2 ), true, 'Markdoc extension tag skipping.', 'markdoc' );
$add_astro( 'astro-markdoc-extension-variable', array( '{{ $markdoc.frontmatter.title }}', 'Body.' ), array( 'Body.' ), array( 'markdoc' => 1 ), true, 'Markdoc extension variable skipping.', 'markdoc' );
$add_astro( 'astro-markdoc-extension-inline-expression', array( 'Inline {{ variable }} remains prose.' ), array( 'Inline {{ variable }} remains prose.' ), array(), false, 'Inline Markdoc expression is preserved.', 'markdoc' );
$add_astro( 'astro-markdoc-extension-fenced', array( '```markdoc', '{% callout %}', '{{ $kept }}', '```' ), array( '```markdoc', '{% callout %}', '{{ $kept }}', '```' ), array(), false, 'Markdoc in fence is preserved.', 'markdoc' );
$add_astro( 'astro-markdoc-extension-table', array( '{% table %}', '| A | B |', '| --- | --- |', '| 1 | 2 |', '{% /table %}' ), array( '| A | B |', '| --- | --- |', '| 1 | 2 |' ), array( 'markdoc' => 2 ), true, 'Table remains after Markdoc wrapper removal.', 'markdoc' );
$add_astro( 'astro-markdoc-extension-heading-adjacent', array( '# Title', '{% partial file="intro.md" /%}', 'Body.' ), array( '# Title', 'Body.' ), array( 'markdoc' => 1 ), true, 'Heading adjacent to Markdoc tag is preserved.', 'markdoc' );
$add_astro( 'astro-markdoc-extension-list-adjacent', array( '- before', '{% if true %}', '- inside', '{% /if %}' ), array( '- before', '- inside' ), array( 'markdoc' => 2 ), true, 'List adjacent to Markdoc wrappers is preserved.', 'markdoc' );

$add_astro( 'astro-raw-mdx-inline-code', array( 'Use `<Aside />` inline.' ), array( 'Use `<Aside />` inline.' ), array(), false, 'Inline MDX-looking code is preserved.' );
$add_astro( 'astro-raw-obsidian-escaped', array( '\[[Not a note]]' ), array( '\[[Not a note]]' ), array(), false, 'Escaped wikilink is preserved.' );
$add_astro( 'astro-raw-obsidian-in-html', array( '<article>', '[[Not a note]]', '</article>' ), array( '<article>', '[[Not a note]]', '</article>' ), array(), false, 'HTML block shields wikilink-looking text.' );
$add_astro( 'astro-raw-import-indented', array( "    import Kept from './Kept.astro';" ), array( "    import Kept from './Kept.astro';" ), array(), false, 'Indented import is preserved.' );
$add_astro( 'astro-raw-component-indented', array( '    <Aside />' ), array( '    <Aside />' ), array(), false, 'Indented component is preserved.' );

$markdoc_meta = function ( $markdoc_count = 0, $normalized = false ) use ( $metadata ) {
	return $metadata(
		array( 'astro', 'markdoc' ),
		array( 'markdoc' => $markdoc_count ),
		$normalized
	);
};

$add_markdoc = function ( $id, array $input_lines, array $expected_lines, $markdoc_count, $normalized, $notes ) use ( $add_case, $markdoc_meta, $text ) {
	$add_case( 'markdoc', $id, 'docs/markdoc/' . $id . '.markdoc', $text( $input_lines ), $text( $expected_lines ), $markdoc_meta( $markdoc_count, $normalized ), $notes );
};

$add_markdoc( 'markdoc-open-tag', array( '{% callout type="note" %}', 'Body.' ), array( 'Body.' ), 1, true, 'Standalone opening tag is skipped.' );
$add_markdoc( 'markdoc-close-tag', array( 'Body.', '{% /callout %}' ), array( 'Body.' ), 1, true, 'Standalone closing tag is skipped.' );
$add_markdoc( 'markdoc-wrapper-pair', array( '{% callout type="note" %}', 'Body.', '{% /callout %}' ), array( 'Body.' ), 2, true, 'Wrapper tags are skipped and body remains.' );
$add_markdoc( 'markdoc-self-closing-tag', array( '{% image src="./image.png" /%}', 'Body.' ), array( 'Body.' ), 1, true, 'Self-closing tag is skipped.' );
$add_markdoc( 'markdoc-comment', array( '{# internal note #}', 'Body.' ), array( 'Body.' ), 1, true, 'Standalone comment is skipped.' );
$add_markdoc( 'markdoc-variable-frontmatter', array( '{{ $frontmatter.title }}', 'Body.' ), array( 'Body.' ), 1, true, 'Standalone variable is skipped.' );
$add_markdoc( 'markdoc-function-call', array( '{{ partial("intro.md") }}', 'Body.' ), array( 'Body.' ), 1, true, 'Standalone function call is skipped.' );
$add_markdoc( 'markdoc-attribute-quotes', array( '{% callout type="warning" title="Pay attention" %}', 'Body.', '{% /callout %}' ), array( 'Body.' ), 2, true, 'Quoted attributes.' );
$add_markdoc( 'markdoc-attribute-single-quotes', array( "{% link href='/docs/start' label='Start' /%}", 'Body.' ), array( 'Body.' ), 1, true, 'Single-quoted attributes.' );
$add_markdoc( 'markdoc-attribute-braces', array( '{% table widths=[1, 2, 3] %}', 'Body.', '{% /table %}' ), array( 'Body.' ), 2, true, 'Array-like attribute braces.' );
$add_markdoc( 'markdoc-nested-wrappers', array( '{% tabs %}', '{% tab label="One" %}', 'Tab body.', '{% /tab %}', '{% /tabs %}' ), array( 'Tab body.' ), 4, true, 'Nested wrappers are skipped line by line.' );
$add_markdoc( 'markdoc-if-wrapper', array( '{% if $frontmatter.show %}', 'Shown body.', '{% /if %}' ), array( 'Shown body.' ), 2, true, 'Conditional wrapper.' );
$add_markdoc( 'markdoc-else-tag', array( '{% if true %}', 'A', '{% else /%}', 'B', '{% /if %}' ), array( 'A', 'B' ), 3, true, 'Else-like standalone tag.' );
$add_markdoc( 'markdoc-partial-tag', array( '{% partial file="intro.md" /%}', 'Body.' ), array( 'Body.' ), 1, true, 'Partial tag.' );
$add_markdoc( 'markdoc-heading-anchor-tag', array( '# Title', '{% heading .level-one %}', 'Body.' ), array( '# Title', 'Body.' ), 1, true, 'Heading adjacent tag.' );
$add_markdoc( 'markdoc-table-wrapper', array( '{% table %}', '| A | B |', '| --- | --- |', '| 1 | 2 |', '{% /table %}' ), array( '| A | B |', '| --- | --- |', '| 1 | 2 |' ), 2, true, 'Table wrapper removal.' );
$add_markdoc( 'markdoc-list-wrapper', array( '{% list %}', '- one', '- two', '{% /list %}' ), array( '- one', '- two' ), 2, true, 'List wrapper removal.' );
$add_markdoc( 'markdoc-standalone-tag-with-spaces', array( '   {% callout %}   ', 'Body.' ), array( 'Body.' ), 1, true, 'Whitespace around standalone tag.' );
$add_markdoc( 'markdoc-standalone-variable-spaces', array( '   {{ $title }}   ', 'Body.' ), array( 'Body.' ), 1, true, 'Whitespace around standalone variable.' );
$add_markdoc( 'markdoc-standalone-comment-spaces', array( '   {# todo #}   ', 'Body.' ), array( 'Body.' ), 1, true, 'Whitespace around standalone comment.' );
$add_markdoc( 'markdoc-multiple-variables', array( '{{ $one }}', '{{ $two }}', 'Body.' ), array( 'Body.' ), 2, true, 'Multiple standalone variables.' );
$add_markdoc( 'markdoc-function-braces', array( '{{ equals($env, "prod") }}', 'Body.' ), array( 'Body.' ), 1, true, 'Function-style standalone expression.' );
$add_markdoc( 'markdoc-json-like-attrs', array( '{% config data={foo: "bar"} /%}', 'Body.' ), array( 'Body.' ), 1, true, 'Brace-style attributes.' );
$add_markdoc( 'markdoc-closing-with-name', array( '{% /section %}', 'Body.' ), array( 'Body.' ), 1, true, 'Named closing tag.' );
$add_markdoc( 'markdoc-empty-document-tag', array( '{% comment /%}' ), array(), 1, true, 'Construct-only document becomes empty.' );

$add_markdoc( 'markdoc-inline-variable-prose', array( 'Inline {{ variable }} remains prose.' ), array( 'Inline {{ variable }} remains prose.' ), 0, false, 'Inline variable is contentful prose.' );
$add_markdoc( 'markdoc-inline-function-prose', array( 'Inline {{ partial("intro.md") }} remains prose.' ), array( 'Inline {{ partial("intro.md") }} remains prose.' ), 0, false, 'Inline function is contentful prose.' );
$add_markdoc( 'markdoc-inline-tag-prose', array( 'Before {% callout %} after.' ), array( 'Before {% callout %} after.' ), 0, false, 'Inline tag is contentful prose.' );
$add_markdoc( 'markdoc-inline-comment-prose', array( 'Before {# comment #} after.' ), array( 'Before {# comment #} after.' ), 0, false, 'Inline comment is contentful prose.' );
$add_markdoc( 'markdoc-inline-attribute-prose', array( 'Use {% badge label="New" /%} inline.' ), array( 'Use {% badge label="New" /%} inline.' ), 0, false, 'Inline self-closing tag is preserved.' );
$add_markdoc( 'markdoc-heading-prose-expression', array( '# Title {{ suffix }}' ), array( '# Title {{ suffix }}' ), 0, false, 'Heading with inline expression is preserved.' );
$add_markdoc( 'markdoc-list-prose-expression', array( '- item {{ value }}' ), array( '- item {{ value }}' ), 0, false, 'List item with inline expression is preserved.' );
$add_markdoc( 'markdoc-table-prose-expression', array( '| A | B |', '| --- | --- |', '| {{ one }} | two |' ), array( '| A | B |', '| --- | --- |', '| {{ one }} | two |' ), 0, false, 'Table cell inline expression is preserved.' );
$add_markdoc( 'markdoc-link-prose-expression', array( '[{{ label }}](../guide.md)' ), array( '[{{ label }}](../guide.md)' ), 0, false, 'Link label inline expression is preserved.' );
$add_markdoc( 'markdoc-escaped-braces-prose', array( '\{{ not a standalone variable }}' ), array( '\{{ not a standalone variable }}' ), 0, false, 'Escaped braces are preserved.' );
$add_markdoc( 'markdoc-unclosed-tag-prose', array( '{% callout', 'Body.' ), array( '{% callout', 'Body.' ), 0, false, 'Unclosed tag line is preserved.' );
$add_markdoc( 'markdoc-unclosed-variable-prose', array( '{{ variable', 'Body.' ), array( '{{ variable', 'Body.' ), 0, false, 'Unclosed variable line is preserved.' );
$add_markdoc( 'markdoc-mismatched-comment-prose', array( '{# comment }', 'Body.' ), array( '{# comment }', 'Body.' ), 0, false, 'Malformed comment is preserved.' );
$add_markdoc( 'markdoc-html-prose-expression', array( '<div>{{ value }}</div>' ), array( '<div>{{ value }}</div>' ), 0, false, 'HTML line with expression is preserved.' );
$add_markdoc( 'markdoc-blockquote-prose-expression', array( '> {{ quote }} remains.' ), array( '> {{ quote }} remains.' ), 0, false, 'Blockquote inline expression is preserved.' );

$add_markdoc( 'markdoc-fenced-tag', array( '```markdoc', '{% callout type="warning" %}', '{{ $kept }}', '{% /callout %}', '```' ), array( '```markdoc', '{% callout type="warning" %}', '{{ $kept }}', '{% /callout %}', '```' ), 0, false, 'Fenced Markdoc constructs are preserved.' );
$add_markdoc( 'markdoc-fenced-backtick-long', array( '````markdoc', '```', '{% callout %}', '````' ), array( '````markdoc', '```', '{% callout %}', '````' ), 0, false, 'Long fence shields shorter run.' );
$add_markdoc( 'markdoc-fenced-tilde', array( '~~~markdoc', '{{ $kept }}', '~~~' ), array( '~~~markdoc', '{{ $kept }}', '~~~' ), 0, false, 'Tilde fence shields variable.' );
$add_markdoc( 'markdoc-inline-code-tag', array( 'Use `{% callout %}` inline.' ), array( 'Use `{% callout %}` inline.' ), 0, false, 'Inline code tag is preserved.' );
$add_markdoc( 'markdoc-inline-code-variable', array( 'Use `{{ $title }}` inline.' ), array( 'Use `{{ $title }}` inline.' ), 0, false, 'Inline code variable is preserved.' );
$add_markdoc( 'markdoc-indented-tag', array( '    {% callout %}', '    {{ $title }}' ), array( '    {% callout %}', '    {{ $title }}' ), 0, false, 'Indented code constructs are preserved.' );
$add_markdoc( 'markdoc-fence-nonclosing-tail', array( '```markdoc', '```not close', '{{ $kept }}', '```' ), array( '```markdoc', '```not close', '{{ $kept }}', '```' ), 0, false, 'Non-closing fence tail stays fenced.' );
$add_markdoc( 'markdoc-html-block-tag', array( '<pre>', '{% callout %}', '{{ $kept }}', '</pre>' ), array( '<pre>', '{% callout %}', '{{ $kept }}', '</pre>' ), 0, false, 'HTML pre block shields Markdoc.' );
$add_markdoc( 'markdoc-html-comment-tag', array( '<!-- {% callout %} {{ $kept }} -->' ), array( '<!-- {% callout %} {{ $kept }} -->' ), 0, false, 'HTML comment shields Markdoc.' );
$add_markdoc( 'markdoc-code-span-many-backticks', array( 'Use ``{{ $title }} and `ticks` `` inline.' ), array( 'Use ``{{ $title }} and `ticks` `` inline.' ), 0, false, 'Multi-backtick span shields Markdoc.' );

$add_markdoc( 'markdoc-heading-adjacent-wrapper', array( '# Title', '{% section %}', 'Body.', '{% /section %}', '## Next' ), array( '# Title', 'Body.', '## Next' ), 2, true, 'Headings adjacent to wrapper remain.' );
$add_markdoc( 'markdoc-list-adjacent-wrapper', array( '- before', '{% if true %}', '- inside', '{% /if %}', '- after' ), array( '- before', '- inside', '- after' ), 2, true, 'Lists adjacent to wrapper remain.' );
$add_markdoc( 'markdoc-table-adjacent-wrapper', array( '| A | B |', '| --- | --- |', '{% rowgroup %}', '| 1 | 2 |', '{% /rowgroup %}' ), array( '| A | B |', '| --- | --- |', '| 1 | 2 |' ), 2, true, 'Tables adjacent to wrapper remain.' );
$add_markdoc( 'markdoc-blockquote-adjacent-wrapper', array( '> before', '{% quote %}', '> inside', '{% /quote %}' ), array( '> before', '> inside' ), 2, true, 'Blockquotes adjacent to wrapper remain.' );
$add_markdoc( 'markdoc-paragraph-between-tags', array( '{% grid %}', 'First paragraph.', '', 'Second paragraph.', '{% /grid %}' ), array( 'First paragraph.', '', 'Second paragraph.' ), 2, true, 'Paragraph spacing remains after wrapper removal.' );
$add_markdoc( 'markdoc-link-between-tags', array( '{% card %}', '[Read more](../guide.md)', '{% /card %}' ), array( '[Read more](../guide.md)' ), 2, true, 'Links remain after wrapper removal.' );
$add_markdoc( 'markdoc-image-between-tags', array( '{% figure %}', '![Chart](chart.png)', '{% /figure %}' ), array( '![Chart](chart.png)' ), 2, true, 'Images remain after wrapper removal.' );
$add_markdoc( 'markdoc-code-between-tags', array( '{% example %}', '```js', 'const value = 1;', '```', '{% /example %}' ), array( '```js', 'const value = 1;', '```' ), 2, true, 'Code fence remains after wrapper removal.' );
$add_markdoc( 'markdoc-link-between-tags-plain', array( '{% card %}', 'See [guide](Guide.md).', '{% /card %}' ), array( 'See [guide](Guide.md).' ), 2, true, 'Plain links remain after wrapper removal.' );
$add_markdoc( 'markdoc-empty-lines-between-tags', array( '{% section %}', '', 'Body.', '', '{% /section %}' ), array( '', 'Body.', '' ), 2, true, 'Empty lines between wrappers remain.' );

$mdx_meta = function ( array $counts = array(), $normalized = true ) use ( $metadata ) {
	$flavors = array( 'docusaurus' );
	if ( ! empty( $counts['wikilinks'] ) || ! empty( $counts['embeds'] ) || ! empty( $counts['callouts'] ) ) {
		$flavors[] = 'obsidian';
	}

	return $metadata( $flavors, $counts, $normalized );
};

$add_mdx = function ( $id, array $input_lines, array $expected_lines, array $counts, $normalized, $notes ) use ( $add_case, $mdx_meta, $text ) {
	$add_case( 'mdx-skip', $id, 'docs/mdx-skip/' . $id . '.mdx', $text( $input_lines ), $text( $expected_lines ), $mdx_meta( $counts, $normalized ), $notes );
};

$add_mdx( 'mdx-import-default', array( "import Thing from './Thing';", 'Body.' ), array( 'Body.' ), array( 'mdx_removed' => 1 ), true, 'Default import is skipped.' );
$add_mdx( 'mdx-import-named', array( "import { Thing } from './Thing';", 'Body.' ), array( 'Body.' ), array( 'mdx_removed' => 1 ), true, 'Named import is skipped.' );
$add_mdx( 'mdx-import-namespace', array( "import * as Docs from './docs';", 'Body.' ), array( 'Body.' ), array( 'mdx_removed' => 1 ), true, 'Namespace import is skipped.' );
$add_mdx( 'mdx-import-type', array( "import type { Props } from './types';", 'Body.' ), array( 'Body.' ), array( 'mdx_removed' => 1 ), true, 'Type import is skipped.' );
$add_mdx( 'mdx-import-side-effect', array( "import './side-effect.css';", 'Body.' ), array( 'Body.' ), array( 'mdx_removed' => 1 ), true, 'Side-effect import is skipped.' );
$add_mdx( 'mdx-import-multiline', array( 'import {', '  A,', '  B,', "} from './components';", 'Body.' ), array( 'Body.' ), array( 'mdx_removed' => 4 ), true, 'Multiline import is skipped.' );
$add_mdx( 'mdx-import-without-semicolon', array( "import Thing from './Thing'", 'Body.' ), array( 'Body.' ), array( 'mdx_removed' => 1 ), true, 'Import without semicolon is skipped.' );
$add_mdx( 'mdx-import-string-semicolon', array( 'import Thing from "./semi;colon";', 'Body.' ), array( 'Body.' ), array( 'mdx_removed' => 1 ), true, 'Semicolon inside module string is ignored.' );
$add_mdx( 'mdx-import-quoted-from-word', array( 'import Thing from "./from";', 'Body.' ), array( 'Body.' ), array( 'mdx_removed' => 1 ), true, 'Quoted source containing from is skipped.' );
$add_mdx( 'mdx-import-before-heading', array( "import Thing from './Thing';", '', '# Heading' ), array( '', '# Heading' ), array( 'mdx_removed' => 1 ), true, 'Import before Markdown heading.' );
$add_mdx( 'mdx-export-const', array( 'export const value = true;', 'Body.' ), array( 'Body.' ), array( 'mdx_removed' => 1 ), true, 'Export const is skipped.' );
$add_mdx( 'mdx-export-let', array( 'export let value = 1;', 'Body.' ), array( 'Body.' ), array( 'mdx_removed' => 1 ), true, 'Export let is skipped.' );
$add_mdx( 'mdx-export-default-object', array( 'export default { title: "Page" };', 'Body.' ), array( 'Body.' ), array( 'mdx_removed' => 1 ), true, 'Default object export is skipped.' );
$add_mdx( 'mdx-export-object-multiline', array( 'export const meta = {', '  title: "Page",', '  text: "a; b",', '};', 'Body.' ), array( 'Body.' ), array( 'mdx_removed' => 4 ), true, 'Multiline object export with semicolon in string.' );
$add_mdx( 'mdx-export-array-multiline', array( 'export const items = [', '  "a;b",', '  "c",', '];', 'Body.' ), array( 'Body.' ), array( 'mdx_removed' => 4 ), true, 'Multiline array export.' );
$add_mdx( 'mdx-export-function', array( 'export function loader() {', '  return { title: "Page" };', '}', 'Body.' ), array( 'Body.' ), array( 'mdx_removed' => 3 ), true, 'Function export block is skipped.' );
$add_mdx( 'mdx-export-async-function', array( 'export async function loader() {', '  return null;', '}', 'Body.' ), array( 'Body.' ), array( 'mdx_removed' => 3 ), true, 'Async function export block is skipped.' );
$add_mdx( 'mdx-export-class', array( 'export class Widget {', '  render() { return null; }', '}', 'Body.' ), array( 'Body.' ), array( 'mdx_removed' => 3 ), true, 'Class export block is skipped.' );
$add_mdx( 'mdx-export-default-class-one-line', array( 'export default class Widget {}', 'Body.' ), array( 'Body.' ), array( 'mdx_removed' => 1 ), true, 'One-line default class export.' );
$add_mdx( 'mdx-export-template-literal', array( 'export const code = `a; ${value}`;', 'Body.' ), array( 'Body.' ), array( 'mdx_removed' => 1 ), true, 'Template literal export.' );

$add_mdx( 'mdx-wrapper-open-close', array( '<Cards>', 'Body.', '</Cards>' ), array( 'Body.' ), array( 'mdx_removed' => 2 ), true, 'Wrapper component pair is skipped.' );
$add_mdx( 'mdx-wrapper-self-closing', array( '<Card />', 'Body.' ), array( 'Body.' ), array( 'mdx_removed' => 1 ), true, 'Self-closing component is skipped.' );
$add_mdx( 'mdx-wrapper-attributes', array( '<Card title="Hello" />', 'Body.' ), array( 'Body.' ), array( 'mdx_removed' => 1 ), true, 'Component attributes.' );
$add_mdx( 'mdx-wrapper-quoted-greater-than', array( '<Card title="A > B" />', 'Body.' ), array( 'Body.' ), array( 'mdx_removed' => 1 ), true, 'Greater-than inside quoted attribute.' );
$add_mdx( 'mdx-wrapper-brace-attribute', array( '<Cards columns={2}>', 'Body.', '</Cards>' ), array( 'Body.' ), array( 'mdx_removed' => 2 ), true, 'Brace attribute wrapper.' );
$add_mdx( 'mdx-wrapper-dotted-name', array( '<API.Reference id="intro" />', 'Body.' ), array( 'Body.' ), array( 'mdx_removed' => 1 ), true, 'Dotted component name.' );
$add_mdx( 'mdx-wrapper-colon-name', array( '<Doc:Aside>', 'Body.', '</Doc:Aside>' ), array( 'Body.' ), array( 'mdx_removed' => 2 ), true, 'Colon component name.' );
$add_mdx( 'mdx-wrapper-hyphen-name', array( '<Docs-Aside>', 'Body.', '</Docs-Aside>' ), array( 'Body.' ), array( 'mdx_removed' => 2 ), true, 'Hyphen component name.' );
$add_mdx( 'mdx-wrapper-with-empty-line', array( '<Tabs>', '', 'Body.', '</Tabs>' ), array( '', 'Body.' ), array( 'mdx_removed' => 2 ), true, 'Blank line inside wrapper is preserved.' );
$add_mdx( 'mdx-wrapper-only-empty-output', array( '<Cards>', '</Cards>' ), array(), array( 'mdx_removed' => 2 ), true, 'Wrapper-only document becomes empty.' );
$add_mdx( 'mdx-wrapper-contentful-same-line', array( '<Note>Keep this warning.</Note>' ), array( '<Note>Keep this warning.</Note>' ), array(), false, 'Contentful same-line component is preserved.' );
$add_mdx( 'mdx-wrapper-text-before', array( 'Text before <Badge /> stays.' ), array( 'Text before <Badge /> stays.' ), array(), false, 'Inline JSX-like text is preserved.' );
$add_mdx( 'mdx-wrapper-lowercase-html', array( '<div>HTML stays.</div>' ), array( '<div>HTML stays.</div>' ), array(), false, 'Lowercase HTML is preserved.' );
$add_mdx( 'mdx-wrapper-uppercase-html-block', array( '<DIV>', '[[Raw Link]]', '</DIV>' ), array( '<DIV>', '[[Raw Link]]', '</DIV>' ), array(), false, 'Uppercase raw HTML block is preserved and shields wikilinks.' );
$add_mdx( 'mdx-wrapper-indented', array( '    <Card />' ), array( '    <Card />' ), array(), false, 'Indented component is code.' );
$add_mdx( 'mdx-wrapper-inline-code', array( 'Use `<Card />` inline.' ), array( 'Use `<Card />` inline.' ), array(), false, 'Inline code component is preserved.' );

$add_mdx( 'mdx-prose-import', array( 'import maps are configured in the browser.' ), array( 'import maps are configured in the browser.' ), array(), false, 'Import-looking prose survives.' );
$add_mdx( 'mdx-prose-export', array( 'export controls are configured in the dashboard.' ), array( 'export controls are configured in the dashboard.' ), array(), false, 'Export-looking prose survives.' );
$add_mdx( 'mdx-prose-important', array( 'important details start with import letters.' ), array( 'important details start with import letters.' ), array(), false, 'Keyword prefix is token-delimited.' );
$add_mdx( 'mdx-prose-exported', array( 'exported values are described here.' ), array( 'exported values are described here.' ), array(), false, 'Export prefix is token-delimited.' );
$add_mdx( 'mdx-fenced-import', array( '```js', "import Thing from './Thing';", 'export const value = true;', '```' ), array( '```js', "import Thing from './Thing';", 'export const value = true;', '```' ), array(), false, 'ESM inside fence is preserved.' );
$add_mdx( 'mdx-fenced-component', array( '```mdx', '<Cards>', '[[Raw Link]]', '</Cards>', '```' ), array( '```mdx', '<Cards>', '[[Raw Link]]', '</Cards>', '```' ), array(), false, 'Component syntax inside fence is preserved.' );
$add_mdx( 'mdx-inline-code-import', array( 'Use `import Thing from "x";` inline.' ), array( 'Use `import Thing from "x";` inline.' ), array(), false, 'Import inside inline code is preserved.' );
$add_mdx( 'mdx-inline-code-export', array( 'Use `export const value = true;` inline.' ), array( 'Use `export const value = true;` inline.' ), array(), false, 'Export inside inline code is preserved.' );
$add_mdx( 'mdx-indented-import', array( "    import Thing from './Thing';" ), array( "    import Thing from './Thing';" ), array(), false, 'Indented import is code.' );
$add_mdx( 'mdx-html-comment-import', array( '<!-- import Thing from "./Thing"; -->' ), array( '<!-- import Thing from "./Thing"; -->' ), array(), false, 'HTML comment is preserved.' );

$add_mdx( 'mdx-admonition-basic', array( ':::note', 'Body.', ':::' ), array( '> **Note:**', '> Body.' ), array( 'admonitions' => 1 ), true, 'Admonition still normalizes in MDX.' );
$add_mdx( 'mdx-admonition-import-before', array( "import Thing from './Thing';", ':::note', 'Body.', ':::' ), array( '> **Note:**', '> Body.' ), array( 'admonitions' => 1, 'mdx_removed' => 1 ), true, 'Import before admonition is skipped.' );
$add_mdx( 'mdx-admonition-import-inside', array( ':::note', "import Thing from './Thing';", 'Body.', ':::' ), array( '> **Note:**', '> Body.' ), array( 'admonitions' => 1, 'mdx_removed' => 1 ), true, 'Import inside admonition is skipped.' );
$add_mdx( 'mdx-admonition-import-after', array( ':::note', 'Body.', ':::', "import Thing from './Thing';" ), array( '> **Note:**', '> Body.' ), array( 'admonitions' => 1, 'mdx_removed' => 1 ), true, 'Import after admonition is skipped.' );
$add_mdx( 'mdx-admonition-wrapper-inside', array( ':::tip', '<Cards>', '</Cards>', 'Body.', ':::' ), array( '> **Tip:**', '> Body.' ), array( 'admonitions' => 1, 'mdx_removed' => 2 ), true, 'Wrapper inside admonition is skipped.' );
$add_mdx( 'mdx-admonition-component-after', array( ':::tip', 'Body.', ':::', '<Cards />' ), array( '> **Tip:**', '> Body.' ), array( 'admonitions' => 1, 'mdx_removed' => 1 ), true, 'Component after admonition is skipped.' );
$add_mdx( 'mdx-admonition-contentful-component', array( ':::tip', '<Note>Keep this.</Note>', ':::' ), array( '> **Tip:**', '> <Note>Keep this.</Note>' ), array( 'admonitions' => 1 ), true, 'Contentful component inside admonition is preserved.' );
$add_mdx( 'mdx-admonition-code-fence', array( ':::note', '```jsx', '<Cards />', '```', ':::' ), array( '> **Note:**', '> ```jsx', '> <Cards />', '> ```' ), array( 'admonitions' => 1 ), true, 'Code fence inside admonition is blockquoted and preserved.' );
$add_mdx( 'mdx-admonition-wikilink', array( ':::note', 'See [[Guide|guide]].', ':::' ), array( '> **Note:**', '> See [guide](Guide.md).' ), array( 'admonitions' => 1, 'wikilinks' => 1 ), true, 'Wikilink inside MDX admonition.' );
$add_mdx( 'mdx-admonition-unknown', array( ':::success', 'Body.', ':::' ), array( ':::success', 'Body.', ':::' ), array(), false, 'Unknown admonition type is preserved.' );

$add_mdx( 'mdx-export-nested-braces', array( 'export const data = {', '  nested: { value: "{not a brace}" },', '};', 'Body.' ), array( 'Body.' ), array( 'mdx_removed' => 3 ), true, 'Nested braces and quoted braces.' );
$add_mdx( 'mdx-export-template-multiline', array( 'export const text = `first line', 'second; line`;', 'Body.' ), array( 'Body.' ), array( 'mdx_removed' => 2 ), true, 'Multiline template literal export.' );
$add_mdx( 'mdx-export-parens', array( 'export default (', '  <Component />', ');', 'Body.' ), array( 'Body.' ), array( 'mdx_removed' => 3 ), true, 'Parenthesized default export.' );
$add_mdx( 'mdx-import-interrupted-by-heading', array( 'import {', '# Heading' ), array( '# Heading' ), array( 'mdx_removed' => 1 ), true, 'Markdown heading interrupts incomplete import.' );
$add_mdx( 'mdx-import-interrupted-by-list', array( 'import {', '- item' ), array( '- item' ), array( 'mdx_removed' => 1 ), true, 'Markdown list interrupts incomplete import.' );

return $cases;
