export const PUBLISH_MANIFEST_VERSION = 1;

export const RESERVED_SYSTEM_ROUTE_ROOTS = [
  "/wp-admin",
  "/_static-dynamic-admin",
  "/_static-dynamic-source",
  "/_static-dynamic-api",
] as const;

export type PublishAssetRole = "static" | "system";

export type PublishAssetBody = string | Uint8Array | ArrayBuffer;

export interface PublishAssetInput {
  path: string;
  body: PublishAssetBody;
  contentType?: string;
}

export interface CreatePublishBundleOptions {
  staticAssets: PublishAssetInput[];
  systemAssets?: PublishAssetInput[];
  createdAt?: string;
}

export interface PublishManifestAsset {
  path: string;
  role: PublishAssetRole;
  sha256: string;
  bytes: number;
  contentType: string;
}

export interface PublishManifest {
  manifestVersion: typeof PUBLISH_MANIFEST_VERSION;
  createdAt: string;
  assetCount: number;
  reservedRouteRoots: string[];
  assets: PublishManifestAsset[];
}

export interface PublishBundleAsset extends PublishManifestAsset {
  body: Uint8Array;
}

export interface PublishBundle {
  manifest: PublishManifest;
  assets: PublishBundleAsset[];
}

export class PublishManifestError extends Error {
  constructor(
    public readonly code: string,
    message: string,
  ) {
    super(message);
    this.name = "PublishManifestError";
  }
}

function fail(code: string, message: string): never {
  throw new PublishManifestError(code, message);
}

export function normalizeAssetPath(assetPath: string): string {
  const trimmed = assetPath.trim();

  if (!trimmed) {
    fail("empty_asset_path", "Asset paths must not be empty.");
  }

  if (/^[a-z][a-z0-9+.-]*:/i.test(trimmed)) {
    fail("absolute_asset_url", `Asset path must be relative to the deployment root: ${assetPath}`);
  }

  if (trimmed.includes("?") || trimmed.includes("#")) {
    fail("asset_path_query_or_hash", `Asset path must not include query strings or hashes: ${assetPath}`);
  }

  let decoded: string;

  try {
    decoded = decodeURIComponent(trimmed.startsWith("/") ? trimmed : `/${trimmed}`);
  } catch (error) {
    fail("invalid_asset_path_encoding", `Asset path must use valid URL encoding: ${assetPath}`);
  }

  if (decoded.includes("\0") || decoded.includes("\\")) {
    fail("invalid_asset_path_character", `Asset path contains unsupported characters: ${assetPath}`);
  }

  const segments: string[] = [];

  for (const segment of decoded.split("/")) {
    if (!segment || segment === ".") {
      continue;
    }

    if (segment === "..") {
      fail("asset_path_traversal", `Asset path must not traverse directories: ${assetPath}`);
    }

    segments.push(segment);
  }

  return `/${segments.join("/")}`;
}

export function isReservedSystemPath(assetPath: string): boolean {
  const normalizedPath = normalizeAssetPath(assetPath);

  return RESERVED_SYSTEM_ROUTE_ROOTS.some(
    (routeRoot) => normalizedPath === routeRoot || normalizedPath.startsWith(`${routeRoot}/`),
  );
}

function isReservedNormalizedPath(normalizedPath: string): boolean {
  return RESERVED_SYSTEM_ROUTE_ROOTS.some(
    (routeRoot) => normalizedPath === routeRoot || normalizedPath.startsWith(`${routeRoot}/`),
  );
}

function toBytes(body: PublishAssetBody): Uint8Array {
  if (typeof body === "string") {
    return new TextEncoder().encode(body);
  }

  if (body instanceof Uint8Array) {
    return body;
  }

  return new Uint8Array(body);
}

function inferContentType(assetPath: string): string {
  if (assetPath.endsWith(".html")) {
    return "text/html;charset=UTF-8";
  }

  if (assetPath.endsWith(".css")) {
    return "text/css;charset=UTF-8";
  }

  if (assetPath.endsWith(".js")) {
    return "text/javascript;charset=UTF-8";
  }

  if (assetPath.endsWith(".json")) {
    return "application/json;charset=UTF-8";
  }

  if (assetPath.endsWith(".svg")) {
    return "image/svg+xml";
  }

  if (assetPath.endsWith(".png")) {
    return "image/png";
  }

  if (assetPath.endsWith(".jpg") || assetPath.endsWith(".jpeg")) {
    return "image/jpeg";
  }

  return "application/octet-stream";
}

export async function sha256Digest(body: Uint8Array): Promise<string> {
  const digestInput = new ArrayBuffer(body.byteLength);
  new Uint8Array(digestInput).set(body);
  const digest = await crypto.subtle.digest("SHA-256", digestInput);
  const digestBytes = new Uint8Array(digest);
  const hex = [...digestBytes].map((byte) => byte.toString(16).padStart(2, "0")).join("");

  return `sha256:${hex}`;
}

async function materializeAsset(
  input: PublishAssetInput,
  role: PublishAssetRole,
): Promise<PublishBundleAsset> {
  const path = normalizeAssetPath(input.path);
  const body = toBytes(input.body);

  return {
    path,
    role,
    body,
    bytes: body.byteLength,
    contentType: input.contentType ?? inferContentType(path),
    sha256: await sha256Digest(body),
  };
}

function insertUniqueAsset(
  assetsByPath: Map<string, PublishBundleAsset>,
  asset: PublishBundleAsset,
): void {
  if (assetsByPath.has(asset.path)) {
    fail("duplicate_asset_path", `Deployment payload contains multiple assets for ${asset.path}.`);
  }

  assetsByPath.set(asset.path, asset);
}

export async function createPublishBundle(options: CreatePublishBundleOptions): Promise<PublishBundle> {
  const assetsByPath = new Map<string, PublishBundleAsset>();

  for (const systemAsset of options.systemAssets ?? []) {
    insertUniqueAsset(assetsByPath, await materializeAsset(systemAsset, "system"));
  }

  for (const staticAsset of options.staticAssets) {
    const materializedStaticAsset = await materializeAsset(staticAsset, "static");

    if (isReservedNormalizedPath(materializedStaticAsset.path)) {
      fail(
        "reserved_static_asset_path",
        `Static export payloads cannot overwrite Worker-owned route ${materializedStaticAsset.path}.`,
      );
    }

    insertUniqueAsset(assetsByPath, materializedStaticAsset);
  }

  const assets = [...assetsByPath.values()].sort((left, right) => left.path.localeCompare(right.path));
  const manifestAssets = assets.map(({ body: _body, ...manifestAsset }) => manifestAsset);

  return {
    manifest: {
      manifestVersion: PUBLISH_MANIFEST_VERSION,
      createdAt: options.createdAt ?? new Date().toISOString(),
      assetCount: manifestAssets.length,
      reservedRouteRoots: [...RESERVED_SYSTEM_ROUTE_ROOTS],
      assets: manifestAssets,
    },
    assets,
  };
}

export async function validatePublishBundle(bundle: PublishBundle): Promise<void> {
  if (bundle.manifest.manifestVersion !== PUBLISH_MANIFEST_VERSION) {
    fail("invalid_manifest_version", "Publish manifest version is not supported.");
  }

  if (!Number.isFinite(Date.parse(bundle.manifest.createdAt))) {
    fail("invalid_manifest_timestamp", "Publish manifest createdAt must be an ISO-compatible timestamp.");
  }

  if (bundle.manifest.assetCount !== bundle.manifest.assets.length) {
    fail("invalid_manifest_asset_count", "Publish manifest assetCount does not match manifest assets.");
  }

  if (bundle.manifest.assets.length !== bundle.assets.length) {
    fail("manifest_asset_body_mismatch", "Publish manifest assets do not match uploaded asset bodies.");
  }

  const manifestAssetsByPath = new Map<string, PublishManifestAsset>();

  for (const manifestAsset of bundle.manifest.assets) {
    const normalizedPath = normalizeAssetPath(manifestAsset.path);

    if (normalizedPath !== manifestAsset.path) {
      fail("manifest_asset_path_not_normalized", `Manifest asset path is not normalized: ${manifestAsset.path}`);
    }

    if (manifestAssetsByPath.has(manifestAsset.path)) {
      fail("duplicate_manifest_asset_path", `Manifest contains multiple assets for ${manifestAsset.path}.`);
    }

    if (manifestAsset.role === "static" && isReservedNormalizedPath(manifestAsset.path)) {
      fail("reserved_static_asset_path", `Static asset ${manifestAsset.path} targets a reserved system route.`);
    }

    manifestAssetsByPath.set(manifestAsset.path, manifestAsset);
  }

  const uploadedAssetPaths = new Set<string>();

  for (const asset of bundle.assets) {
    const normalizedPath = normalizeAssetPath(asset.path);

    if (normalizedPath !== asset.path) {
      fail("uploaded_asset_path_not_normalized", `Uploaded asset path is not normalized: ${asset.path}`);
    }

    if (uploadedAssetPaths.has(asset.path)) {
      fail("duplicate_uploaded_asset_path", `Uploaded assets contain multiple bodies for ${asset.path}.`);
    }

    uploadedAssetPaths.add(asset.path);

    const manifestAsset = manifestAssetsByPath.get(asset.path);

    if (!manifestAsset) {
      fail("asset_missing_from_manifest", `Uploaded asset ${asset.path} is missing from the manifest.`);
    }

    const expectedSha256 = await sha256Digest(asset.body);

    if (asset.sha256 !== expectedSha256 || manifestAsset.sha256 !== expectedSha256) {
      fail("asset_hash_mismatch", `Uploaded asset ${asset.path} does not match its manifest hash.`);
    }

    if (asset.bytes !== asset.body.byteLength || manifestAsset.bytes !== asset.body.byteLength) {
      fail("asset_size_mismatch", `Uploaded asset ${asset.path} does not match its manifest byte size.`);
    }

    if (asset.role !== manifestAsset.role || asset.contentType !== manifestAsset.contentType) {
      fail("asset_metadata_mismatch", `Uploaded asset ${asset.path} does not match manifest metadata.`);
    }
  }
}
