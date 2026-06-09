# CommonMark Selected Cases

Backslash escapes should stay ordinary Markdown:

\*not emphasized*
\[not a link](/url)
\`not code`

Inline code keeps bracket-like text: `[[not a wikilink]]`.

Fenced code keeps docs dialect syntax:

```md
```not a close
:::note
[[Do Not Convert]]
import Example from './example';
{% callout %}
```

Nested blockquotes stay Markdown:

> outer
> > inner

Links and images stay Markdown:

[text](../guide.md)
![alt](images/chart.png)

HTML stays Markdown-compatible:

<section>
  <p>Raw HTML block.</p>
</section>

Indented code stays unchanged:

    [[Do Not Convert]]
    :::warning
