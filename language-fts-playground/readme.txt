=== Language FTS Playground ===
Contributors: adamziel
Tags: search, full-text, multilingual, playground, lexical
Requires at least: 6.5
Tested up to: 6.5
Stable tag: 0.3.0
Requires PHP: 8.1
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Demo/seed-pack full-text search playground for testing language-partitioned
WordPress search behavior with bundled English, Polish, and German resources.

== Description ==

Language FTS Playground is a small WordPress plugin that demonstrates
language-partitioned full-text search in WordPress Playground or disposable
WordPress test sites. It indexes simple document and posting rows, then runs
query analysis, language routing, phrase filtering, fuzzy expansion, snippets,
highlighting, and field-aware ranking in PHP.

This 0.3.0 package is still a demo/seed-pack release candidate. The bundled
English, Polish, and German lexical resources are curated seed data for trying
the workflow; they are not comprehensive linguistic databases and do not
provide production-scale relevance guarantees.

This P0 readme prepares the plugin metadata and package shape for a future
WordPress.org/plugin-directory submission. The current 0.3.0 release candidate
is distributed as a direct ZIP artifact and is not a WordPress.org/plugin-directory release.
WordPress.org submission, SVN packaging, directory assets, screenshots, and
policy review remain future work.

Features available in the demo:

* Automatic language routing across the bundled seed profiles.
* Explicit English, Polish, and German search modes.
* Curated stopwords, lexemes, term rules, synonyms, Polish concept synsets,
  and English phrase synonyms.
* Phrase search, opt-in one-edit fuzzy matching, escaped snippets, and
  generated mark highlighting.
* Search diagnostics for routing evidence, analyzed terms, expansions,
  candidates, matched fields, matched terms, and score contributions.
* Admin controls for seeding demo posts, rebuilding the index, processing the
  queue, and clearing index or queue state.

== Installation ==

For the current direct-ZIP release candidate:

1. Download the verified `language-fts-playground-0.3.0.zip` artifact from the
   release channel that provided its SHA-256 checksum.
2. In a disposable WordPress site or WordPress Playground instance, open
   Plugins > Add New Plugin > Upload Plugin.
3. Upload the ZIP, install it, and activate Language FTS Playground.
4. Open Tools > Language FTS.
5. Seed demo content if needed, rebuild or process the search index, then try
   Automatic plus explicit English, Polish, and German search modes.

This plugin is not yet installed from the WordPress.org plugin directory.

== Frequently Asked Questions ==

= Is this a production search plugin? =

No. This release candidate is a demo/seed-pack package for testing portable
language-partitioned full-text search behavior. It is not a production-scale
search guarantee.

= Are the bundled lexical resources comprehensive? =

No. The bundled English, Polish, and German resources are small curated seed
packs. They exercise the plugin workflow and search behavior, but they are not
full dictionaries, lemmatizers, morphology databases, or broad synonym
databases.

= Is automatic mode statistical language detection? =

No. Automatic mode is deterministic routing based on available profile,
lexical-resource, and storage-backed evidence.

= Does this package mean the plugin has a WordPress.org directory listing? =

No. This P0 readme is readiness work for a future plugin-directory submission.
The current artifact remains direct-ZIP distribution only until a separate
WordPress.org submission and review path is completed.

== Screenshots ==

No screenshot image assets are bundled in this P0 readiness branch. Future
WordPress.org submission work should add directory screenshot assets and update
this section with matching captions before publication.

== Changelog ==

= 0.3.0 =

* Adds the Language FTS Playground demo/seed-pack release candidate.
* Ships English, Polish, and German curated lexical seed resources.
* Adds automatic and explicit language search modes.
* Adds query diagnostics, phrase synonym handling, opt-in fuzzy matching,
  escaped snippets, and admin maintenance controls.
* Adds direct-ZIP release packaging with deterministic archive verification.
* Adds P0 WordPress.org readme metadata while keeping current distribution
  scoped to direct ZIP only.
