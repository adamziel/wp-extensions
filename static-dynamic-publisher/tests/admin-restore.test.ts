import { readFileSync } from "node:fs";
import { dirname, resolve } from "node:path";
import { fileURLToPath } from "node:url";
import { describe, expect, it } from "vitest";
import {
  buildPlaygroundAdminRestoreBlueprint,
  describeAdminRestoreTarget,
} from "../src/admin-restore";
import { parseSourceManifest } from "../src/source-manifest";

const currentDir = dirname(fileURLToPath(import.meta.url));
const manifestFixturePath = resolve(
  currentDir,
  "fixtures/static-dynamic-source/manifest.json",
);

function readManifestFixture(): unknown {
  return JSON.parse(readFileSync(manifestFixturePath, "utf8")) as unknown;
}

describe("admin restore target", () => {
  it("describes the Playground boot target from a source manifest", () => {
    const target = describeAdminRestoreTarget(readManifestFixture(), {
      origin: "https://example.com",
    });

    expect(target.kind).toBe("wordpress-playground-admin-restore");
    expect(target.adminPath).toBe("/wp-admin/");
    expect(target.manifestUrl).toBe(
      "https://example.com/_static-dynamic-source/manifest.json",
    );
    expect(target.sourceArchiveUrl).toBe(
      "https://example.com/_static-dynamic-source/source-2026-06-08T102000Z.zip",
    );
    expect(target.sourceArchiveHash).toBe(
      "sha256:2222222222222222222222222222222222222222222222222222222222222222",
    );
    expect(target.expectedRuntime).toEqual({
      wordpressVersion: "6.8.1",
      phpTarget: "8.3",
    });
    expect(target.expectedAdminChecks.seededContentTitles).toEqual([
      "About the Static Demo",
    ]);
    expect(target.expectedAdminChecks.pluginSlugs).toEqual([
      "static-site-generator",
      "universal-wordpress-importer",
    ]);
  });

  it("builds a Playground Blueprint that restores the preserved WordPress archive", () => {
    const manifest = parseSourceManifest(readManifestFixture());
    const blueprint = buildPlaygroundAdminRestoreBlueprint(
      manifest,
      "https://example.com/_static-dynamic-source/source-2026-06-08T102000Z.zip",
    );

    expect(blueprint.landingPage).toBe("/wp-admin/");
    expect(blueprint.preferredVersions).toEqual({
      php: "8.3",
      wp: "6.8.1",
    });
    expect(blueprint.steps).toEqual([
      {
        step: "unzip",
        zipFile: {
          resource: "url",
          url: "https://example.com/_static-dynamic-source/source-2026-06-08T102000Z.zip",
        },
        extractToPath: "/wordpress",
      },
      {
        step: "login",
      },
    ]);
  });

  it("can describe relative Worker paths for an admin shell running on the same origin", () => {
    const target = describeAdminRestoreTarget(readManifestFixture());

    expect(target.manifestUrl).toBe("/_static-dynamic-source/manifest.json");
    expect(target.sourceArchiveUrl).toBe(
      "/_static-dynamic-source/source-2026-06-08T102000Z.zip",
    );
    expect(target.blueprint.steps[0].zipFile.url).toBe(target.sourceArchiveUrl);
  });
});
