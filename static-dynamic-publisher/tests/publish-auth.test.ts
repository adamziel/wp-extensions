import { readFileSync } from "node:fs";
import { dirname, resolve } from "node:path";
import { fileURLToPath } from "node:url";
import { describe, expect, it } from "vitest";
import { authenticatePublishRequest, publishAuthFailureResponse } from "../src/publish-auth";

const currentDir = dirname(fileURLToPath(import.meta.url));
const packageRoot = resolve(currentDir, "..");

describe("publish authentication contract", () => {
  it("returns 401 when owner authorization is missing", async () => {
    const result = authenticatePublishRequest(new Request("https://example.com/_static-dynamic-api/publish"), {
      secret: "owner-secret",
    });

    expect(result).toMatchObject({
      authorized: false,
      code: "missing_authorization",
      status: 401,
    });

    if (result.authorized) {
      throw new Error("Expected request to be rejected.");
    }

    const response = publishAuthFailureResponse(result);

    expect(response.status).toBe(401);
    expect(response.headers.get("WWW-Authenticate")).toContain("Bearer");
    await expect(response.text()).resolves.toContain("missing_authorization");
  });

  it("returns 403 when the owner bearer secret is invalid", () => {
    const result = authenticatePublishRequest(
      new Request("https://example.com/_static-dynamic-api/publish", {
        headers: {
          Authorization: "Bearer wrong-secret",
        },
      }),
      {
        secret: "owner-secret",
      },
    );

    expect(result).toMatchObject({
      authorized: false,
      code: "invalid_publish_secret",
      status: 403,
    });
  });

  it("authorizes a valid owner bearer secret without echoing credential material", () => {
    const result = authenticatePublishRequest(
      new Request("https://example.com/_static-dynamic-api/publish", {
        method: "POST",
        headers: {
          Authorization: "Bearer owner-secret",
        },
      }),
      {
        secret: "owner-secret",
      },
    );

    expect(result).toEqual({
      authorized: true,
    });
    expect(JSON.stringify(result)).not.toContain("owner-secret");
  });

  it("keeps Cloudflare API token material out of browser admin code", () => {
    const adminHtml = readFileSync(resolve(packageRoot, "admin/index.html"), "utf8");

    expect(adminHtml).toContain("/_static-dynamic-api/publish");
    expect(adminHtml).not.toMatch(/CLOUDFLARE_API_TOKEN|CF_API_TOKEN|apiToken|Authorization|Bearer/i);
  });
});
