import adminShellHtml from "../admin/index.html";

export interface Env {
  ASSETS: Fetcher;
  STATIC_DYNAMIC_PUBLISH_SECRET?: string;
}

type JsonValue =
  | null
  | boolean
  | number
  | string
  | JsonValue[]
  | { [key: string]: JsonValue };

export const RESERVED_WORKER_FIRST_ROUTES = [
  "/wp-admin",
  "/wp-admin/*",
  "/_static-dynamic-admin",
  "/_static-dynamic-admin/*",
  "/_static-dynamic-source",
  "/_static-dynamic-source/*",
  "/_static-dynamic-api",
  "/_static-dynamic-api/*",
] as const;

const RESERVED_ROUTE_ROOTS = [
  "/wp-admin",
  "/_static-dynamic-admin",
  "/_static-dynamic-source",
  "/_static-dynamic-api",
] as const;

const JSON_HEADERS = {
  "Content-Type": "application/json;charset=UTF-8",
  "Cache-Control": "no-store",
};

const HTML_HEADERS = {
  "Content-Type": "text/html;charset=UTF-8",
  "Cache-Control": "no-store",
};

function isRoute(pathname: string, routeRoot: (typeof RESERVED_ROUTE_ROOTS)[number]): boolean {
  return pathname === routeRoot || pathname.startsWith(`${routeRoot}/`);
}

function jsonResponse(body: JsonValue, init?: ResponseInit): Response {
  return new Response(JSON.stringify(body, null, 2), {
    ...init,
    headers: {
      ...JSON_HEADERS,
      ...init?.headers,
    },
  });
}

function redirectToTrailingSlash(request: Request): Response {
  const url = new URL(request.url);
  url.pathname = `${url.pathname}/`;
  return Response.redirect(url.toString(), 308);
}

function serveAdminShell(): Response {
  return new Response(adminShellHtml, {
    status: 200,
    headers: HTML_HEADERS,
  });
}

function serveSourceRoute(request: Request): Response {
  const url = new URL(request.url);

  if (url.pathname === "/_static-dynamic-source/manifest.json") {
    return jsonResponse({
      status: "source_snapshot_not_configured",
      manifestVersion: 1,
      message: "A later publishing lane will preserve the editable WordPress source snapshot here.",
    });
  }

  return jsonResponse(
    {
      status: "source_route_reserved",
      message: "This route is reserved for preserved WordPress source snapshots.",
    },
    { status: 404 },
  );
}

function isAuthorizedPublishRequest(request: Request, env: Env): boolean {
  if (!env.STATIC_DYNAMIC_PUBLISH_SECRET) {
    return false;
  }

  return request.headers.get("Authorization") === `Bearer ${env.STATIC_DYNAMIC_PUBLISH_SECRET}`;
}

function servePublishRoute(request: Request, env: Env): Response {
  if (request.method !== "POST") {
    return jsonResponse(
      {
        status: "method_not_allowed",
        message: "Publish requests must use POST.",
      },
      {
        status: 405,
        headers: {
          "Allow": "POST",
        },
      },
    );
  }

  if (env.STATIC_DYNAMIC_PUBLISH_SECRET && !isAuthorizedPublishRequest(request, env)) {
    return jsonResponse(
      {
        status: "unauthorized",
        message: "Publish requests must be authorized by the site owner.",
      },
      { status: 401 },
    );
  }

  return jsonResponse(
    {
      status: "publish_not_implemented",
      message:
        "The publish API route is reserved for Worker-side redeploy code. Cloudflare credentials must remain in Worker secrets or owner-local tooling.",
    },
    { status: 501 },
  );
}

async function serveAsset(request: Request, env: Env): Promise<Response> {
  if (!env.ASSETS) {
    return jsonResponse(
      {
        status: "asset_binding_missing",
        message: "The Cloudflare ASSETS binding is required to serve the static site.",
      },
      { status: 500 },
    );
  }

  return env.ASSETS.fetch(request);
}

export async function handleRequest(request: Request, env: Env): Promise<Response> {
  const url = new URL(request.url);

  if (url.pathname === "/wp-admin") {
    return redirectToTrailingSlash(request);
  }

  if (isRoute(url.pathname, "/wp-admin") || isRoute(url.pathname, "/_static-dynamic-admin")) {
    return serveAdminShell();
  }

  if (isRoute(url.pathname, "/_static-dynamic-source")) {
    return serveSourceRoute(request);
  }

  if (url.pathname === "/_static-dynamic-api/publish") {
    return servePublishRoute(request, env);
  }

  if (isRoute(url.pathname, "/_static-dynamic-api")) {
    return jsonResponse(
      {
        status: "api_route_not_found",
        message: "Unknown static/dynamic publisher API route.",
      },
      { status: 404 },
    );
  }

  return serveAsset(request, env);
}

export default {
  fetch(request, env): Promise<Response> {
    return handleRequest(request, env);
  },
} satisfies ExportedHandler<Env>;

