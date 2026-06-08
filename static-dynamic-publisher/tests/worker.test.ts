import { describe, expect, it, vi } from "vitest";
import { handleRequest, type Env } from "../src/worker";

interface AssetBindingMock {
  binding: Fetcher;
  fetch: ReturnType<typeof vi.fn>;
}

function createAssetBinding(responses: Record<string, Response>): AssetBindingMock {
  const fetch = vi.fn(async (request: Request) => {
    const url = new URL(request.url);
    return responses[url.pathname] ?? new Response("Not found", { status: 404 });
  });

  return {
    binding: {
      fetch,
    } as unknown as Fetcher,
    fetch,
  };
}

function createEnv(assets: Fetcher, overrides: Partial<Env> = {}): Env {
  return {
    ASSETS: assets,
    ...overrides,
  };
}

describe("static/dynamic publisher worker", () => {
  it("serves / from the static assets binding", async () => {
    const assets = createAssetBinding({
      "/": new Response("<!doctype html><h1>Static Home</h1>", {
        headers: { "Content-Type": "text/html;charset=UTF-8" },
      }),
    });

    const response = await handleRequest(
      new Request("https://example.com/"),
      createEnv(assets.binding),
    );

    await expect(response.text()).resolves.toContain("Static Home");
    expect(assets.fetch).toHaveBeenCalledOnce();
  });

  it("serves generated static assets from the assets binding", async () => {
    const assets = createAssetBinding({
      "/assets/site.css": new Response("body { color: #1d2327; }", {
        headers: { "Content-Type": "text/css" },
      }),
    });

    const response = await handleRequest(
      new Request("https://example.com/assets/site.css"),
      createEnv(assets.binding),
    );

    expect(response.headers.get("Content-Type")).toContain("text/css");
    await expect(response.text()).resolves.toContain("#1d2327");
    expect(assets.fetch).toHaveBeenCalledOnce();
  });

  it("handles /wp-admin/ in Worker code without falling through to static assets", async () => {
    const assets = createAssetBinding({
      "/wp-admin/index.html": new Response("static export conflict"),
    });

    const response = await handleRequest(
      new Request("https://example.com/wp-admin/"),
      createEnv(assets.binding),
    );

    expect(response.status).toBe(200);
    expect(response.headers.get("Content-Type")).toContain("text/html");
    await expect(response.text()).resolves.toContain('id="playground-root"');
    expect(assets.fetch).not.toHaveBeenCalled();
  });

  it("redirects /wp-admin to the Worker-owned admin shell path", async () => {
    const assets = createAssetBinding({});

    const response = await handleRequest(
      new Request("https://example.com/wp-admin"),
      createEnv(assets.binding),
    );

    expect(response.status).toBe(308);
    expect(response.headers.get("Location")).toBe("https://example.com/wp-admin/");
    expect(assets.fetch).not.toHaveBeenCalled();
  });

  it("handles publish API requests in Worker code without exposing Cloudflare tokens", async () => {
    const assets = createAssetBinding({});

    const response = await handleRequest(
      new Request("https://example.com/_static-dynamic-api/publish", { method: "POST" }),
      createEnv(assets.binding),
    );

    expect(response.status).toBe(501);
    expect(response.headers.get("Content-Type")).toContain("application/json");
    await expect(response.text()).resolves.toContain("publish_not_implemented");
    expect(assets.fetch).not.toHaveBeenCalled();
  });

  it("requires owner authorization before publish when a publish secret is configured", async () => {
    const assets = createAssetBinding({});

    const response = await handleRequest(
      new Request("https://example.com/_static-dynamic-api/publish", { method: "POST" }),
      createEnv(assets.binding, { STATIC_DYNAMIC_PUBLISH_SECRET: "owner-secret" }),
    );

    expect(response.status).toBe(401);
    await expect(response.text()).resolves.toContain("unauthorized");
    expect(assets.fetch).not.toHaveBeenCalled();
  });
});
