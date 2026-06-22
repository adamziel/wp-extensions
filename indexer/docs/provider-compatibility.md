# Provider Compatibility Evidence

Pure PHP FTS Indexer has two provider compatibility evidence lanes:

- a deterministic PHP quality contract with simulated `posts_pre_query`
  providers, and
- an optional live WordPress provider interference matrix smoke for disposable
  environments.

Both lanes are evidence for the plugin's compatibility controls and diagnostics.
They are not a claim that every third-party provider version or site-specific
callback has been certified end to end.

## Provider Interference Matrix

The disposable WordPress smoke emits a structured provider interference matrix.
The scenarios are repo-owned provider-family simulations, not real third-party
plugin installs, downloads, service calls, or API integrations:

- `theme_custom_earlier_respect_existing`: a theme/custom earlier
  `posts_pre_query` provider returns results in `respect_existing` mode. The
  matrix expects Language FTS to stand down, preserve the earlier result, and
  attribute final ownership to the earlier provider.
- `searchwp_shaped_earlier_prefer_fts`: a repo-owned SearchWP-shaped callback
  returns earlier results in `prefer_fts` mode. The matrix expects Language FTS
  to replace that result and report incoming provider counts plus the prior
  replacement count.
- `relevanssi_shaped_later_provider`: a repo-owned Relevanssi-shaped callback
  runs after the Language FTS priority. The matrix expects final ownership to
  report that a later provider changed the FTS output.
- `jetpack_elasticpress_advisory_signals`: safe option/plugin-signal
  simulations exercise bounded Jetpack Search / Jetpack and ElasticPress
  advisory labels. The report includes only family labels and counts, not raw
  plugin basenames or option payloads.

Each scenario reports a stable scenario id, simulated provider-family labels,
compatibility mode, trace status, final ownership status, bounded counts,
bounded post IDs and compact hashes, and a pass flag. Raw provider objects,
content titles, raw plugin basenames, option payloads, `.env`, SSH paths, and
PEM-like values are intentionally excluded from the JSON evidence.

## Deterministic Quality Contract

Run the focused contract from the `indexer` directory:

```sh
php tests/quality/provider-compatibility-certification-contracts.php
php -n tests/quality/provider-compatibility-certification-contracts.php
composer test:provider-compatibility
```

The direct command re-enters the shared PHP harness with a focused provider
compatibility filter. The normal full harness also discovers the contract
through `tests/quality/*.php`:

```sh
php tests/run.php
php -n tests/run.php
```

The contract uses the repository's fake WordPress hook, option, query, and WPDB
objects. It does not need a WordPress install, a database server, WP-CLI,
network access, or real third-party search plugins.

The contract proves these deterministic cases and the matrix output contract:

- `respect_existing` mode returns an earlier non-null provider result unchanged.
- `prefer_fts` mode lets Language FTS replace an earlier provider result.
- A later callback after the Language FTS priority can change the final result,
  and final ownership diagnostics report that bounded state.
- Later callbacks that preserve the incoming result do not cause diagnostics to
  overclaim provider ownership.
- Final ownership evidence is bounded to status, owner, counts, post ID samples,
  and compact hashes. It does not dump raw post objects.
- Diagnostics do not expose raw provider payloads, raw plugin basenames,
  unknown provider option payloads, private post titles, `.env`, SSH material,
  or PEM-like values.
- Advisory labels remain bounded to Jetpack Search / Jetpack, SearchWP,
  Relevanssi, and ElasticPress.
- Theme, custom, unknown, and callback-name-only providers remain generic hook
  callback labels. They are not promoted to certified provider families.
- The provider interference matrix exposes stable scenario IDs, bounded family
  labels, and redacted evidence without requiring a live WordPress install.

## Optional WordPress Smoke

The optional smoke exits `0` with an explicit `SKIP:` report when no WordPress
environment is configured:

```sh
php tools/smoke-search-provider-compatibility.php
composer test:smoke:provider-compatibility
```

The release evidence collector includes this smoke as the
`provider_compatibility_smoke` lane:

```sh
php tools/collect-release-evidence.php
```

Without a disposable WordPress root, that lane reports `skip` in the evidence
bundle. With the same disposable-site guard variables below, the delegated smoke
can produce `pass` or `fail` evidence while the collector still redacts and
bounds command output.

To run it against a disposable WordPress root, configure WP-CLI and explicitly
confirm that writes are allowed:

```sh
touch /path/to/wordpress/.wp-fts-provider-compatibility-smoke
WP_FTS_WP_PATH=/path/to/wordpress \
WP_FTS_WP_CLI=wp \
WP_FTS_PROVIDER_COMPATIBILITY_ALLOW=1 \
php tools/smoke-search-provider-compatibility.php
```

For disposable roots where a marker file is inconvenient, set
`WP_FTS_PROVIDER_COMPATIBILITY_CONFIRM_PATH` to the exact same real path as
`WP_FTS_WP_PATH`.

When configured, the smoke creates one generated post fixture, repairs FTS
schema, processes the bounded indexing queue for that fixture, and runs the
provider interference matrix described above. It captures replacement,
stand-down, final ownership, known-provider family labels, and bounded result
evidence. It restores request-local hook state, deletes the generated fixture,
and restores the plugin settings and simulated advisory options before exiting.

Do not run the write-enabled smoke against production or shared staging data.
It does not download plugins, make external network calls, or require real
Jetpack Search, Jetpack, SearchWP, Relevanssi, or ElasticPress installs.

## Certification Boundary

Known-provider detection is advisory. It recognizes bounded family labels for
Jetpack Search / Jetpack, SearchWP, Relevanssi, and ElasticPress from safe
activation, network-active plugin, option, class, and function signals. Unknown
plugins, themes, and custom callbacks appear as generic bounded hook labels.

The matrix is not a broad version-by-version certification of Jetpack Search,
Jetpack, SearchWP, Relevanssi, ElasticPress, themes, or custom providers. It is
repo-owned evidence that the plugin's precedence controls, advisory labels, and
diagnostics behave correctly for representative conflict shapes.

No third-party provider APIs are called by wp fts status or by the
provider-status/advisory path. Status and advisory code does not run searches,
invoke provider callbacks, call remote services, mutate provider options, scan
content, or expose raw provider basenames or payloads.

Request-local traces and the optional smoke are not persistent telemetry. They
help explain the current request or disposable smoke run, but they are not
historical conflict logs and do not prove that every provider interaction on a
site has been observed.
