# Agent Instructions

- Prefer streamed structured data processing over regular expressions for parsing or transforming source formats such as Markdown, HTML, XML, JSON, SQL, and PHP.
- Use the parser, AST, tokenizer, or event stream that matches the format. For Markdown, use CommonMark nodes and source positions; for HTML, use `WP_HTML_Tag_Processor` or `WP_HTML_Processor`; for line-oriented edits, scan lines explicitly.
- Avoid regex-based parsing for nested, escaped, quoted, or markup-like data. A regexp is acceptable only for a narrow lexical check after confirming the input is not structured, and the reason should be obvious from the surrounding code.
