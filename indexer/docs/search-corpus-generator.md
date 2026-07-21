# Large Search Corpus Generator

`tools/generate-large-search-corpus.php` streams deterministic JSONL shards for
search benchmarking and demos. It writes one shard per language plus
`manifest.json`; generated shards are rebuildable data and must stay outside the
plugin archive.

## Default Language Scope

The default generated languages are derived from the current README language
support notes, component files under
`components/full-text-search/src/LanguagePipeline.php`,
`components/full-text-search/src/Stemmer.php`, and
`components/full-text-search/src/AnalyzerPackValidator.php`, plus committed
analyzer-pack manifests under
`resources/analyzer-packs/`.

Current defaults:

| Language | Default count | Why it is generated |
| --- | ---: | --- |
| `en` | `--english-docs` | README top routing set, bundled Snowball path, committed UniMorph analyzer pack. |
| `ar` | `--per-language-docs` | README top routing set, bundled Snowball path, committed UniMorph analyzer pack. |
| `bn` | `--per-language-docs` | README top routing set, baseline stemmer route, committed UniMorph analyzer pack. |
| `ca` | `--per-language-docs` | Snowball support through the required, verified Wamania-backed Catalan path. |
| `de` | `--per-language-docs` | README next-language set, committed UniMorph analyzer pack. |
| `es` | `--per-language-docs` | README top routing set, bundled Snowball path, committed UniMorph analyzer pack. |
| `fa` | `--per-language-docs` | README next-language set, committed UniMorph analyzer pack. |
| `fr` | `--per-language-docs` | README top routing set, bundled Snowball path, committed UniMorph analyzer pack. |
| `hi` | `--per-language-docs` | README top routing set, bundled Snowball path, committed UniMorph analyzer pack. |
| `id` | `--per-language-docs` | README top routing set, bundled Snowball path, committed UniMorph analyzer pack. |
| `it` | `--per-language-docs` | README next-language set, committed UniMorph analyzer pack. |
| `ja` | `--per-language-docs` | README next-language set and deterministic CJK fallback tokenizer lane. |
| `ko` | `--per-language-docs` | README next-language set and deterministic Hangul fallback tokenizer lane. |
| `nl` | `--per-language-docs` | README next-language set, committed UniMorph analyzer pack; Dutch Porter remains a no-pack fallback. |
| `pl` | `--per-language-docs` | README explicit partition, Polish pipeline route, committed Polish analyzer packs. |
| `pt` | `--per-language-docs` | README top routing set, bundled Snowball path, committed UniMorph analyzer pack. |
| `ru` | `--per-language-docs` | README next-language set, committed UniMorph analyzer pack. |
| `te` | `--per-language-docs` | README next-language set, committed UniMorph analyzer pack. |
| `tr` | `--per-language-docs` | README next-language set, committed UniMorph analyzer pack. |
| `uk` | `--per-language-docs` | README next-language set, committed UniMorph analyzer pack. |
| `ur` | `--per-language-docs` | README top routing set and baseline Urdu suffix route; no committed Urdu pack is claimed. |
| `zh` | `--per-language-docs` | README top routing set and deterministic CJK fallback/optional Jieba tokenizer lane. |

The generated vocabulary is only corpus text. Morphology, tokenization, and
matching behavior remain analyzer-pack, stemmer, and language-pipeline behavior.

## Document Length Distribution

Every generated record includes `approx_token_count`, a whitespace-token proxy
for document length. The generator enforces a 200-token floor for every record.
The modal target is the `medium` band: most records target roughly 750 words
with deterministic targets between 700 and 800. The smaller band covers
200-450, the mid-long band covers 1,400-2,400, and the deterministic long tail
targets 5,000-5,200 tokens; because records are emitted as whole sentences, some
long-tail records land slightly above 5,000.

## Commands

Full requested corpus:

```sh
php tools/generate-large-search-corpus.php \
  --output=/tmp/wp-fts-search-corpus-v1 \
  --seed=wp-fts-large-search-corpus-v1 \
  --english-docs=100000 \
  --per-language-docs=30000
```

Focused smoke corpus:

```sh
php tools/generate-large-search-corpus.php \
  --output=/tmp/wp-fts-search-corpus-smoke \
  --seed=wp-fts-large-search-corpus-v1 \
  --languages=en,pl,zh \
  --smoke
```

Use `--languages=...` to override the generated partitions. `--compression=auto`
uses gzip when zlib is available and plain `.jsonl` when it is not. Use
`--compression=plain` for an explicit plain JSONL run.
