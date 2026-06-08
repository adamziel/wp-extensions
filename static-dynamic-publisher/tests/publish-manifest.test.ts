import { describe, expect, it } from "vitest";
import { MockCloudflareUploadClient } from "../src/cloudflare-upload-mock";
import {
  createPublishBundle,
  isReservedSystemPath,
  normalizeAssetPath,
  sha256Digest,
  validatePublishBundle,
  type PublishBundle,
} from "../src/publish-manifest";

describe("publish manifest and deployment bundle", () => {
  it("merges static export assets with Worker-owned system assets and records hashes", async () => {
    const bundle = await createPublishBundle({
      createdAt: "2026-06-08T00:00:00.000Z",
      systemAssets: [
        {
          path: "/wp-admin/index.html",
          body: "<!doctype html><main>Admin shell</main>",
          contentType: "text/html;charset=UTF-8",
        },
        {
          path: "/_static-dynamic-source/manifest.json",
          body: JSON.stringify({ manifestVersion: 1, source: "fixture.zip" }),
          contentType: "application/json;charset=UTF-8",
        },
      ],
      staticAssets: [
        {
          path: "/index.html",
          body: "<!doctype html><h1>Published site</h1>",
        },
        {
          path: "assets/site.css",
          body: "body { color: #1d2327; }",
        },
      ],
    });

    expect(bundle.manifest).toMatchObject({
      manifestVersion: 1,
      createdAt: "2026-06-08T00:00:00.000Z",
      assetCount: 4,
      reservedRouteRoots: [
        "/wp-admin",
        "/_static-dynamic-admin",
        "/_static-dynamic-source",
        "/_static-dynamic-api",
      ],
    });
    expect(bundle.manifest.assets.map((asset) => asset.path)).toEqual([
      "/_static-dynamic-source/manifest.json",
      "/assets/site.css",
      "/index.html",
      "/wp-admin/index.html",
    ]);

    const indexAsset = bundle.assets.find((asset) => asset.path === "/index.html");

    expect(indexAsset?.role).toBe("static");
    expect(indexAsset?.contentType).toBe("text/html;charset=UTF-8");
    await expect(sha256Digest(indexAsset?.body ?? new Uint8Array())).resolves.toBe(indexAsset?.sha256);
    await expect(validatePublishBundle(bundle)).resolves.toBeUndefined();
  });

  it("refuses static export assets that would overwrite Worker-owned routes", async () => {
    await expect(
      createPublishBundle({
        systemAssets: [
          {
            path: "/wp-admin/index.html",
            body: "admin shell",
          },
        ],
        staticAssets: [
          {
            path: "/wp-admin/index.html",
            body: "static export conflict",
          },
        ],
      }),
    ).rejects.toMatchObject({
      code: "reserved_static_asset_path",
    });
  });

  it("normalizes encoded paths before applying reserved route checks", async () => {
    expect(isReservedSystemPath("/%77p-admin/index.html")).toBe(true);

    await expect(
      createPublishBundle({
        staticAssets: [
          {
            path: "/%77p-admin/index.html",
            body: "encoded conflict",
          },
        ],
      }),
    ).rejects.toMatchObject({
      code: "reserved_static_asset_path",
    });
  });

  it("rejects path traversal instead of resolving it into a deployment path", () => {
    expect(() => normalizeAssetPath("assets/../wp-admin/index.html")).toThrowError(/traverse/);
  });

  it("mock uploads verify manifest shape, hashes, reserved merge behavior, and payload fields", async () => {
    const bundle = await createPublishBundle({
      createdAt: "2026-06-08T00:00:00.000Z",
      systemAssets: [
        {
          path: "/_static-dynamic-admin/admin.js",
          body: "window.StaticDynamicPublisher = {};",
          contentType: "text/javascript;charset=UTF-8",
        },
      ],
      staticAssets: [
        {
          path: "/index.html",
          body: "<!doctype html><h1>Published docs</h1>",
        },
      ],
    });
    const client = new MockCloudflareUploadClient();

    const deployment = await client.upload({
      target: {
        accountId: "mock-account",
        workerName: "static-dynamic-publisher",
      },
      requestedBy: "worker",
      bundle,
      metadata: {
        commit: "fixture",
      },
    });

    expect(deployment.deploymentId).toBe("mock-cloudflare-1");
    expect(deployment.staticAssetCount).toBe(1);
    expect(deployment.systemAssetCount).toBe(1);
    expect(deployment.reservedSystemAssetCount).toBe(1);
    expect(deployment.payload.assets).toEqual(bundle.manifest.assets);
    expect(client.deployments).toHaveLength(1);
  });

  it("mock uploads reject Cloudflare credential material in deployment payloads", async () => {
    const bundle = await createPublishBundle({
      systemAssets: [
        {
          path: "/wp-admin/index.html",
          body: "admin shell",
        },
      ],
      staticAssets: [
        {
          path: "/index.html",
          body: "home",
        },
      ],
    });
    const client = new MockCloudflareUploadClient();

    await expect(
      client.upload({
        target: {
          accountId: "mock-account",
          workerName: "static-dynamic-publisher",
        },
        requestedBy: "owner-cli",
        bundle,
        metadata: {
          CLOUDFLARE_API_TOKEN: "must-not-travel-in-payload",
        },
      }),
    ).rejects.toMatchObject({
      code: "cloudflare_credential_in_payload",
    });
  });

  it("mock uploads reject tampered asset bodies that no longer match manifest hashes", async () => {
    const bundle = await createPublishBundle({
      systemAssets: [
        {
          path: "/wp-admin/index.html",
          body: "admin shell",
        },
      ],
      staticAssets: [
        {
          path: "/index.html",
          body: "home",
        },
      ],
    });
    const tamperedBundle: PublishBundle = {
      manifest: bundle.manifest,
      assets: bundle.assets.map((asset) =>
        asset.path === "/index.html"
          ? {
              ...asset,
              body: new TextEncoder().encode("tampered home"),
            }
          : asset,
      ),
    };
    const client = new MockCloudflareUploadClient();

    await expect(
      client.upload({
        target: {
          accountId: "mock-account",
          workerName: "static-dynamic-publisher",
        },
        requestedBy: "worker",
        bundle: tamperedBundle,
      }),
    ).rejects.toMatchObject({
      code: "asset_hash_mismatch",
    });
  });

  it("mock uploads reject duplicate uploaded bodies even when the manifest is unique", async () => {
    const bundle = await createPublishBundle({
      systemAssets: [
        {
          path: "/wp-admin/index.html",
          body: "admin shell",
        },
      ],
      staticAssets: [
        {
          path: "/index.html",
          body: "home",
        },
      ],
    });
    const duplicatedBodyBundle: PublishBundle = {
      manifest: bundle.manifest,
      assets: [bundle.assets[0], bundle.assets[0]],
    };
    const client = new MockCloudflareUploadClient();

    await expect(
      client.upload({
        target: {
          accountId: "mock-account",
          workerName: "static-dynamic-publisher",
        },
        requestedBy: "worker",
        bundle: duplicatedBodyBundle,
      }),
    ).rejects.toMatchObject({
      code: "duplicate_uploaded_asset_path",
    });
  });
});
