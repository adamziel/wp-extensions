# Current Progress And Metrics

Last verified: 2026-06-09 for Task 502.

Read this file first for current routing. It supersedes older release
dashboards, gate monitors, stale status pointers, and incomplete stale-artifact
indexes unless a future update to this file says otherwise.

## Current Status

| Area | Status |
| --- | --- |
| Direct-ZIP RC | `GO` for the fixed direct installable ZIP RC only. |
| Current `github/main` | Post-RC integration promoted; live SHA `e838c84408d81fff553d503b89f462a69580471a`. |
| Long-term goal | `OPEN`. |
| WordPress.org distribution | `NOT READY / future work`. |

Direct-ZIP RC control remains Task 443 plus Review 444. The approved direct-ZIP
RC source is `d37c723ff1d23a192fb46e3d11b3fa13234ec9c0`, and the fixed release
artifact remains the ZIP listed below. Current `github/main` has moved beyond
that RC source to the promoted post-RC integration SHA; this does not mutate or
replace the approved direct-ZIP RC.

Live refs verified with `git ls-remote`:

| Ref | Verified SHA |
| --- | --- |
| `github/main` | `e838c84408d81fff553d503b89f462a69580471a` |
| `github/indexer/integration-post-rc-approved` | `e838c84408d81fff553d503b89f462a69580471a` |
| `github/indexer/final-direct-zip-rc` | `d37c723ff1d23a192fb46e3d11b3fa13234ec9c0` |

The post-RC integration promoted by Task 494 contains the five approved
post-RC lanes:

- WordPress.org P0/readme readiness.
- Production benchmark gates.
- Non-space tokenizer readiness fixtures.
- Lexical pack governance hardening.
- Evaluator coverage expansion.

## Direct-ZIP Artifact Metrics

| Field | Value |
| --- | --- |
| Artifact path | `/home/claude/indexer/.cao/tasks/final-rc-artifacts/language-fts-playground-0.3.0.zip` |
| Approved RC source SHA | `d37c723ff1d23a192fb46e3d11b3fa13234ec9c0` |
| Current RC ref | `github/indexer/final-direct-zip-rc` |
| Version | `0.3.0` |
| Size | `104799 bytes` |
| SHA-256 | `6c21065819d0dbff6a7f0c0eb89e103c4161071ee4ebc1d845690206ff877845` |
| Entry count | `49` |
| ZIP root | `language-fts-playground` |

Task 443 and Review 444 approve this exact identity. The ZIP verifier reports
release ZIP integrity passed, root `language-fts-playground`, version `0.3.0`,
and `49` entries. Package scans exclude `.cao`, `tools`, `tests`, `dist`,
generated ZIPs, logs, and smoke/static artifacts.

## Source And Test Metrics

Final RC evidence for source `d37c723ff1d23a192fb46e3d11b3fa13234ec9c0`:

- Task 378 final build: normal PHP tests passed, all `191` tests; `php -n`
  tests passed, all `191` tests; lexical validators, JSON validator,
  release build, release ZIP verifier, packaging regression, Blueprint JSON
  decode, PHP lint, source ancestry checks, and `git diff --check` passed.
- Task 388 exact final package smoke: normal PHP and `php -n` tests passed,
  all `191` tests; verifier passed for the exact final ZIP; clean rebuild was
  byte-identical to the final ZIP; WordPress Playground installed and activated
  the exact copied ZIP; admin/status checks and `16` query probes passed.
- Task 420 stable-main smoke, run while `github/main` was still the RC source:
  normal PHP and `php -n` tests passed, all `191` tests; bundled-pack validator,
  stable main Blueprint, admin/status checks, unsafe snippet check, tokenizer
  and provenance checks, and `16` query probes passed.
- Task 443 plus Review 444: final direct-ZIP RC decision is `GO` and
  `APPROVED`.

Current post-RC `github/main` evidence for
`e838c84408d81fff553d503b89f462a69580471a`:

- Task 494 pre-push scratch worktree: normal PHP tests passed, all `205`
  tests; `php -n` tests passed, all `205` tests; normal and `php -n` lexical
  validators, validator JSON, demo/phrase/coverage evaluator suites,
  PR-smoke benchmark gates, `--languages=3` benchmark gate, WordPress.org
  readme verifier, release packaging regression, Blueprint JSON decode, PHP
  lint, `git diff --check`, and clean detached status passed.
- Task 494 post-push focused checks from detached `github/main`: normal and
  `php -n` tests, validators, PR-smoke benchmark gates, WordPress.org readme
  verifier, Blueprint JSON decode, `git diff --check`, and clean detached
  status passed.
- Task 472 plus Review 500: pre-promotion live smoke for the same integration
  SHA is `PASS` and `APPROVED`; normal and `php -n` tests passed, all `205`
  tests; lexical validators and readme verifier passed; a rebuilt integration
  package installed in WordPress Playground; admin/status checks and `16` query
  probes passed.

## Package And Live Smoke Status

- Direct-ZIP RC package/live evidence is complete for the fixed artifact:
  Task 388 exact package smoke is `PASS`, Review 402 approved it, Task 420
  stable-main smoke is `PASS`, Review 435 approved it, Task 443 is `GO`, and
  Review 444 approved the final direct-ZIP go/no-go.
- Current post-RC main has source/package/live evidence from Task 472,
  Review 500, and Task 494. Task 472 rebuilt an integration package with SHA
  `6bc2b87152e979a32f8b0c405094e53bc9dd6a6633c14daf682878f01508a440`, size
  `120096` bytes, and `50` entries for smoke use only. That rebuilt package is
  not the approved direct-ZIP RC artifact.
- Task 494 did not create, publish, or modify a release ZIP. There is no new
  post-promotion public stable Blueprint live smoke after `github/main` moved
  to `e838c84408d81fff553d503b89f462a69580471a`; do not treat that as a
  direct-ZIP RC blocker, but require fresh evidence before claiming a new
  post-RC release artifact.

## Benchmark, Evaluator, And Validator Status

- Validators: final RC bundled lexical validators pass; current post-RC main
  bundled lexical validators pass under normal PHP and `php -n`, including JSON
  mode.
- Benchmarks: current post-RC main passes deterministic pure-PHP PR-smoke
  benchmark gates under normal PHP and `php -n`, including a generated
  multi-language `--languages=3` gate. This is fixture-sized pure-PHP evidence,
  not live WordPress, MySQL query-plan, production traffic, memory-ceiling, or
  production-scale relevance proof.
- Evaluators: current post-RC main passes demo, phrase, and coverage evaluator
  suites under normal PHP and `php -n`. This improves fixture coverage, but it
  remains committed demo/fixture evidence rather than broad real-corpus proof.
- WordPress.org readme verification passes on current post-RC main, but
  WordPress.org distribution is still `NOT READY / future work` because SVN,
  assets, policy, account/workflow, stable-tag, and final submission rehearsal
  evidence remain incomplete.

## Supersession Rules

- Older `NO-GO`, `NOT READY`, `BLOCKED`, `FAIL`, and missing-result gate
  artifacts before Task 443/Review 444 are historical unless this file names
  them as current.
- Task 425, Task 426, and similar readiness monitors or dashboard refreshes are
  stale snapshots, not current direct-ZIP release blockers.
- Task 429 is explicitly superseded. It was an evidence-consistency failure,
  not a final go/no-go decision; later Review 435, Review 434, Task 436/Review
  439, Task 440, Task 443, and Review 444 resolved the direct-ZIP gate chain.
- Task 482 and Review 491 must not be used as a complete stale-artifact index.
  Review 491 rejected Task 482 because it omitted Task 429. Use this file for
  current stale-artifact routing.
- Task 481/Review 490 and Task 493/Review 501 are superseded by this file for
  current routing. Task 493's `CURRENT_STATUS.md` pointer is stale because live
  `github/main` is now `e838c84408d81fff553d503b89f462a69580471a` and because
  it relied on Task 482 as a complete stale-artifact index.

## Still Missing

- WordPress.org/SVN/plugin-directory publication readiness, including SVN
  trunk/tag/assets packaging, banners, icons, screenshots, stable-tag and
  public-copy alignment, account/ownership/credential workflow, and policy
  review.
- Comprehensive real lexical pack import plus source, legal, attribution,
  provenance, license, deterministic import, evaluator, fanout, size, and
  performance locks for full datasets.
- Real non-space tokenizer implementation beyond readiness fixtures, including
  reviewed resources, production adapter behavior, quality gates, provenance,
  licensing, and scale proof.
- Stronger production-scale, custom-root, and many-language benchmark evidence,
  including scheduled or retained baselines, memory ceilings, live WordPress or
  MySQL profiling, query plans, sustained latency, and realistic lexical
  diversity.
- Broader real-corpus relevance and evaluator proof, including source-backed
  corpora, many-language/custom-pack matrices, retained thresholds, recall,
  precision, MRR, nDCG, and false-positive budgets.
- Any release artifact decision after post-RC main must open an explicit new
  release cycle and must not overwrite the already approved direct-ZIP RC
  identity above.

## Process Notes

- Stop creating duplicate release-gate dashboards unless this file is being
  updated.
- Do not assign reviews before the target result file exists.
- Maintain `10` work lanes only with non-duplicative implementation, review, or
  design work.
- Keep direct-ZIP RC evidence separate from post-RC `github/main` progress.
