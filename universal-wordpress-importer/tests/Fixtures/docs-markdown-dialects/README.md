# Docs Markdown Dialect Fixtures

These fixtures exercise docs-flavored Markdown normalization without network access. The counted matrix lives in `case-matrix.php`; the older `*-selected.*` files remain as compact human-readable examples.

## Case Counts

- `commonmark`: 62 cases
- `obsidian`: 60 cases
- `docusaurus`: 60 cases
- `astro`: 60 cases
- `markdoc`: 60 cases
- `mdx-skip`: 60 cases

The PHPUnit matrix test enforces at least 60 cases per flavor and compares exact normalized content plus expected metadata for every case.

## Sources and Provenance

- `commonmark`: influenced by CommonMark 0.31.2 fence, escape, link, image, HTML block, list, table, and blockquote behavior from https://spec.commonmark.org/0.31.2/spec.json.
- `obsidian`: influenced by Obsidian links and callouts docs at https://obsidian.md/help/links and https://obsidian.md/help/callouts, plus wikilink edge cases from https://pulldown-cmark.github.io/pulldown-cmark/specs/wikilinks.html.
- `docusaurus`: influenced by Docusaurus admonition syntax at https://docusaurus.io/docs/markdown-features/admonitions and MDX import/component behavior from https://mdxjs.com/docs/what-is-mdx/.
- `astro`: influenced by Astro content collection and Markdown/MDX conventions in https://github.com/withastro/docs/blob/main/src/content/docs/en/guides/markdown-content.mdx, plus Astro/Starlight-style docs admonition usage.
- `markdoc`: influenced by Markdoc tag, variable, function, comment, and attribute forms from https://github.com/markdoc/markdoc.
- `mdx-skip`: influenced by MDX ESM and JSX-like component conventions from https://mdxjs.com/docs/what-is-mdx/.

## License Notes

The `case-matrix.php` fixture text is original test data written for this repository. It does not copy prose or examples verbatim from the referenced docs/specs.

The compact `commonmark-selected.md` fixture includes small examples adapted from the CommonMark 0.31.2 spec JSON, which is licensed CC-BY-SA 4.0. The compact Obsidian, Docusaurus/Astro, and Markdoc selected fixtures are original test data influenced by the public documentation above. Docusaurus, Astro docs, MDX, and Markdoc source materials are MIT-licensed where applicable.

## Selection Criteria

- Keep fenced, inline, indented, and HTML raw content byte-stable when docs dialect syntax appears inside those raw contexts.
- Cover Obsidian aliases, headings, block refs, escaped and invalid links, embeds, callout modifiers, nested callouts, tables, lists, and code-preservation cases.
- Cover Docusaurus and Astro admonition open/close variants, nested colon runs, wrong close markers, Markdown bodies, and MDX declarations/components before, inside, and after admonitions.
- Cover Markdoc standalone constructs, inline prose expressions, attributes with quotes/braces, adjacent Markdown blocks, and raw code contexts.
- Cover MDX import/export declaration scanning, nested delimiters, quoted semicolons, template literals, wrapper-only components, contentful JSX-like lines, and prose beginning with `import` or `export`.
