# WordPress.org Directory Assets

Language FTS Playground does not yet have approved WordPress.org Plugin
Directory assets. This document is the local inventory and runbook for a future
submission rehearsal. It does not create final marketing assets, upload to SVN,
contact WordPress.org services, or prove public directory readiness.

## Distribution Paths

Direct ZIP release candidate:

- uses `language-fts-playground/dist/language-fts-playground-0.3.0.zip`;
- does not include WordPress.org directory assets;
- remains the only current distribution path until official submission and
  review are complete.

Local SVN dry run:

- uses `tools/build-wordpress-org-svn-stage.php`;
- creates a local `trunk/`, `tags/<version>/`, and top-level `assets/` layout;
- may be verified without assets to rehearse plugin payload shape;
- performs no network requests and no SVN operations.

Actual WordPress.org submission:

- requires account/slug approval, official readme validation, approved assets,
  an SVN checkout, a real SVN commit, and Plugin Directory review;
- must not be claimed from the direct ZIP or local dry-run workflow alone.

## Required Launch Inventory

Before a real submission push, the local SVN stage should carry this top-level
asset set:

| Filename | Format | Dimensions | Source/license status |
| --- | --- | --- | --- |
| `banner-772x250.png` | PNG | 772 x 250 | Pending approved GPL-compatible source |
| `banner-1544x500.png` | PNG | 1544 x 500 | Pending approved GPL-compatible source |
| `icon-128x128.png` | PNG | 128 x 128 | Pending approved GPL-compatible source |
| `icon-256x256.png` | PNG | 256 x 256 | Pending approved GPL-compatible source |
| `screenshot-1.png` or `screenshot-1.jpg` | PNG or JPEG matching extension | Any readable image dimensions | Pending final UI capture and caption |

Additional screenshots may be added as `screenshot-2.png`,
`screenshot-3.jpg`, and so on. Submission-readiness verification requires one
image per screenshot number with contiguous numbering starting at
`screenshot-1`.

Assets belong only in the SVN root `assets/` directory. They must not be placed
inside `trunk/`, `tags/<version>/`, the direct ZIP payload, or the plugin source
package unless a future release policy explicitly changes.

## Caption Rules

Every screenshot asset must have a matching numbered caption in `readme.txt`.
When screenshot assets are staged, replace the current placeholder text in the
`== Screenshots ==` section with numbered captions such as:

```text
1. Language FTS Playground admin screen showing the local demo search controls.
```

The verifier checks both `trunk/readme.txt` and `tags/<version>/readme.txt`.
Captions must match the screenshot asset numbers exactly.

## Local Verification

Build a dry-run stage without final assets:

```sh
php language-fts-playground/tools/build-wordpress-org-svn-stage.php \
  --output=language-fts-playground/dist/wordpress-org-svn-stage
```

Verify payload shape and permissive asset rules:

```sh
php language-fts-playground/tools/verify-wordpress-org-svn-stage.php \
  --stage=language-fts-playground/dist/wordpress-org-svn-stage
```

After approved local assets exist, stage them from a separate source directory:

```sh
php language-fts-playground/tools/build-wordpress-org-svn-stage.php \
  --assets-source=/absolute/path/to/approved-wordpress-org-assets \
  --replace
```

Then run submission-readiness asset verification:

```sh
php language-fts-playground/tools/verify-wordpress-org-svn-stage.php \
  --stage=language-fts-playground/dist/wordpress-org-svn-stage \
  --submission-readiness \
  --manifest-json=/tmp/language-fts-wordpress-org-submission-assets.json
```

`--submission-readiness` is still a local verifier mode. It requires the launch
banner, high-resolution banner, both icons, at least one screenshot, matching
image formats, fixed banner/icon dimensions, top-level placement, and matching
readme screenshot captions. It does not upload to WordPress.org.

## Remaining External Steps

The repository-side readiness checks still leave these external gates open:

1. WordPress.org account and plugin slug approval.
2. Official WordPress.org readme validator result for the exact submission
   `readme.txt`.
3. Approved asset source/license inventory with retained source files.
4. Human review of final screenshots and captions.
5. Real SVN checkout status and commit plan.
6. Plugin Directory policy/security review.
7. Final release rehearsal and public support boundary confirmation.
