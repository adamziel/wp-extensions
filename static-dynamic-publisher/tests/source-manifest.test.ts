import { readFileSync } from "node:fs";
import { dirname, resolve } from "node:path";
import { fileURLToPath } from "node:url";
import { describe, expect, it } from "vitest";
import {
  SOURCE_MANIFEST_PATH,
  SOURCE_MANIFEST_VERSION,
  SourceManifestValidationError,
  parseSourceManifest,
  validateSourceManifest,
} from "../src/source-manifest";

const currentDir = dirname(fileURLToPath(import.meta.url));
const manifestFixturePath = resolve(
  currentDir,
  "fixtures/static-dynamic-source/manifest.json",
);

function readManifestFixture(): unknown {
  return JSON.parse(readFileSync(manifestFixturePath, "utf8")) as unknown;
}

describe("source snapshot manifest", () => {
  it("validates the reserved source manifest fixture metadata", () => {
    const manifest = parseSourceManifest(readManifestFixture());

    expect(SOURCE_MANIFEST_PATH).toBe("/_static-dynamic-source/manifest.json");
    expect(manifest.manifestVersion).toBe(SOURCE_MANIFEST_VERSION);
    expect(manifest.createdAt).toBe("2026-06-08T10:20:00.000Z");
    expect(manifest.wordpressVersion).toBe("6.8.1");
    expect(manifest.phpTarget).toBe("8.3");
    expect(manifest.staticExportVersion).toBe("0.1.0");
    expect(manifest.contentSnapshotHash).toMatch(/^sha256:[a-f0-9]{64}$/);
    expect(manifest.sourceArchive.path).toBe(
      "/_static-dynamic-source/source-2026-06-08T102000Z.zip",
    );
    expect(manifest.sourceArchive.restoreStrategy).toBe("playground-site-archive");
    expect(manifest.enabledPlugins.map((plugin) => plugin.slug)).toEqual([
      "static-site-generator",
      "universal-wordpress-importer",
    ]);
  });

  it("returns actionable validation errors for missing source snapshot fields", () => {
    const result = validateSourceManifest({
      manifestVersion: SOURCE_MANIFEST_VERSION,
      wordpressVersion: "6.8.1",
      phpTarget: "8.3",
      enabledPlugins: [],
      staticExportVersion: "0.1.0",
      contentSnapshotHash: "not-a-hash",
    });

    expect(result.valid).toBe(false);
    expect(result.errors).toEqual(
      expect.arrayContaining([
        "createdAt must be a non-empty string",
        "sourceArchive must be an object",
        "enabledPlugins must be a non-empty array",
        "contentSnapshotHash must be a sha256 hash",
      ]),
    );
  });

  it("rejects timestamps that JavaScript would otherwise normalize", () => {
    const manifest = readManifestFixture() as Record<string, unknown>;
    const result = validateSourceManifest({
      ...manifest,
      createdAt: "2026-02-30T10:20:00.000Z",
    });

    expect(result.valid).toBe(false);
    expect(result.errors).toContain("createdAt must be an ISO 8601 UTC timestamp");
  });

  it("rejects source archives outside the reserved Worker source route", () => {
    const manifest = readManifestFixture() as Record<string, unknown>;

    const result = validateSourceManifest({
      ...manifest,
      sourceArchive: {
        ...(manifest.sourceArchive as Record<string, unknown>),
        path: "https://attacker.example/source.zip",
      },
    });

    expect(result.valid).toBe(false);
    expect(result.errors).toContain(
      "sourceArchive.path must point to a versioned ZIP under /_static-dynamic-source/",
    );
  });

  it("throws a typed validation error when parsing invalid manifests", () => {
    expect(() => parseSourceManifest({ manifestVersion: 99 })).toThrow(
      SourceManifestValidationError,
    );
  });
});
