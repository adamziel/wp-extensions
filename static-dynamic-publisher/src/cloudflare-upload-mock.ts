import {
  isReservedSystemPath,
  sha256Digest,
  validatePublishBundle,
  type PublishBundle,
  type PublishManifest,
} from "./publish-manifest";

export interface MockCloudflareDeploymentTarget {
  accountId: string;
  workerName: string;
  environment?: string;
}

export interface MockCloudflareDeploymentRequest {
  target: MockCloudflareDeploymentTarget;
  bundle: PublishBundle;
  requestedBy: "worker" | "owner-cli";
  metadata?: Record<string, string>;
}

export interface MockCloudflareDeploymentPayload {
  target: MockCloudflareDeploymentTarget;
  manifest: PublishManifest;
  assets: Array<{
    path: string;
    role: "static" | "system";
    sha256: string;
    bytes: number;
    contentType: string;
  }>;
}

export interface MockCloudflareDeploymentRecord {
  deploymentId: string;
  receivedAt: string;
  payload: MockCloudflareDeploymentPayload;
  staticAssetCount: number;
  systemAssetCount: number;
  reservedSystemAssetCount: number;
  manifestSha256: string;
}

export class CloudflareUploadMockError extends Error {
  constructor(
    public readonly code: string,
    message: string,
  ) {
    super(message);
    this.name = "CloudflareUploadMockError";
  }
}

function fail(code: string, message: string): never {
  throw new CloudflareUploadMockError(code, message);
}

function assertNonEmpty(value: string, code: string, message: string): void {
  if (!value.trim()) {
    fail(code, message);
  }
}

function assertNoCloudflareCredentialMaterial(value: unknown, path = "$"): void {
  if (Array.isArray(value)) {
    value.forEach((item, index) => assertNoCloudflareCredentialMaterial(item, `${path}[${index}]`));
    return;
  }

  if (value && typeof value === "object") {
    for (const [key, nestedValue] of Object.entries(value as Record<string, unknown>)) {
      if (/(authorization|bearer|api[_-]?token|cloudflare[_-]?api[_-]?token|cf[_-]?api[_-]?token|secret)/i.test(key)) {
        fail("cloudflare_credential_in_payload", `Deployment request payload must not include credential key ${path}.${key}.`);
      }

      assertNoCloudflareCredentialMaterial(nestedValue, `${path}.${key}`);
    }

    return;
  }

  if (typeof value === "string" && /(Bearer\s+\S+|CLOUDFLARE_API_TOKEN|CF_API_TOKEN)/i.test(value)) {
    fail("cloudflare_credential_in_payload", `Deployment request payload must not include credential material at ${path}.`);
  }
}

function toDeploymentPayload(request: MockCloudflareDeploymentRequest): MockCloudflareDeploymentPayload {
  return {
    target: { ...request.target },
    manifest: request.bundle.manifest,
    assets: request.bundle.assets.map(({ body: _body, path, role, sha256, bytes, contentType }) => ({
      path,
      role,
      sha256,
      bytes,
      contentType,
    })),
  };
}

export class MockCloudflareUploadClient {
  private readonly deploymentRecords: MockCloudflareDeploymentRecord[] = [];

  get deployments(): MockCloudflareDeploymentRecord[] {
    return [...this.deploymentRecords];
  }

  async upload(request: MockCloudflareDeploymentRequest): Promise<MockCloudflareDeploymentRecord> {
    assertNonEmpty(request.target.accountId, "missing_cloudflare_account_id", "Deployment target accountId is required.");
    assertNonEmpty(request.target.workerName, "missing_cloudflare_worker_name", "Deployment target workerName is required.");
    assertNoCloudflareCredentialMaterial({
      target: request.target,
      requestedBy: request.requestedBy,
      metadata: request.metadata,
    });

    await validatePublishBundle(request.bundle);

    const staticAssetCount = request.bundle.assets.filter((asset) => asset.role === "static").length;
    const systemAssetCount = request.bundle.assets.filter((asset) => asset.role === "system").length;
    const reservedSystemAssetCount = request.bundle.assets.filter(
      (asset) => asset.role === "system" && isReservedSystemPath(asset.path),
    ).length;

    if (reservedSystemAssetCount === 0) {
      fail(
        "reserved_system_assets_missing",
        "Deployment bundle must preserve at least one Worker-owned admin/source/API asset.",
      );
    }

    const payload = toDeploymentPayload(request);
    assertNoCloudflareCredentialMaterial(payload);

    const manifestSha256 = await sha256Digest(new TextEncoder().encode(JSON.stringify(payload.manifest)));
    const record: MockCloudflareDeploymentRecord = {
      deploymentId: `mock-cloudflare-${this.deploymentRecords.length + 1}`,
      receivedAt: new Date().toISOString(),
      payload,
      staticAssetCount,
      systemAssetCount,
      reservedSystemAssetCount,
      manifestSha256,
    };

    this.deploymentRecords.push(record);

    return record;
  }
}
