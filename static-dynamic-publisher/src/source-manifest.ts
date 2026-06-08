export const SOURCE_MANIFEST_VERSION = 1;
export const SOURCE_MANIFEST_PATH = "/_static-dynamic-source/manifest.json";
export const SOURCE_ROUTE_PREFIX = "/_static-dynamic-source/";

const SHA256_HASH_PATTERN = /^sha256:[a-f0-9]{64}$/;
const PHP_TARGET_PATTERN = /^\d+\.\d+$/;
const PLUGIN_SLUG_PATTERN = /^[a-z0-9][a-z0-9._-]*$/;

export type SourceArchiveRestoreStrategy = "playground-site-archive";

export type SourceProfileType =
  | "wordpress"
  | "jekyll"
  | "astro"
  | "docusaurus"
  | "obsidian"
  | "markdown-docs";

export interface StaticDynamicEnabledPlugin {
  slug: string;
  version: string;
  entry?: string;
  name?: string;
}

export interface StaticDynamicSourceArchive {
  path: string;
  sha256: string;
  bytes?: number;
  mediaType: "application/zip";
  restoreStrategy: SourceArchiveRestoreStrategy;
}

export interface StaticDynamicSourceProfile {
  type: SourceProfileType;
  originalSourcePath?: string;
}

export interface StaticDynamicAdminChecks {
  seededContentTitles?: string[];
  pluginSlugs?: string[];
}

export interface StaticDynamicSourceManifest {
  manifestVersion: typeof SOURCE_MANIFEST_VERSION;
  createdAt: string;
  wordpressVersion: string;
  phpTarget: string;
  enabledPlugins: StaticDynamicEnabledPlugin[];
  staticExportVersion: string;
  contentSnapshotHash: string;
  sourceArchive: StaticDynamicSourceArchive;
  site?: {
    title?: string;
    homeUrl?: string;
  };
  sourceProfile?: StaticDynamicSourceProfile;
  adminChecks?: StaticDynamicAdminChecks;
}

export interface SourceManifestValidationResult {
  valid: boolean;
  manifest?: StaticDynamicSourceManifest;
  errors: string[];
}

export class SourceManifestValidationError extends Error {
  errors: string[];

  constructor(errors: string[]) {
    super(`Invalid static/dynamic source manifest: ${errors.join("; ")}`);
    this.name = "SourceManifestValidationError";
    this.errors = errors;
  }
}

function isRecord(value: unknown): value is Record<string, unknown> {
  return typeof value === "object" && value !== null && !Array.isArray(value);
}

function requiredString(
  record: Record<string, unknown>,
  key: string,
  errors: string[],
  label = key,
): string | undefined {
  const value = record[key];

  if (typeof value !== "string" || value.trim() === "") {
    errors.push(`${label} must be a non-empty string`);
    return undefined;
  }

  return value;
}

function optionalString(
  record: Record<string, unknown>,
  key: string,
  errors: string[],
  label = key,
): string | undefined {
  const value = record[key];

  if (value === undefined) {
    return undefined;
  }

  if (typeof value !== "string" || value.trim() === "") {
    errors.push(`${label} must be a non-empty string when present`);
    return undefined;
  }

  return value;
}

function isIsoTimestamp(value: string): boolean {
  if (!/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{3})?Z$/.test(value)) {
    return false;
  }

  const date = new Date(value);
  const timestamp = date.getTime();

  if (!Number.isFinite(timestamp)) {
    return false;
  }

  const normalized = value.includes(".")
    ? date.toISOString()
    : date.toISOString().replace(".000Z", "Z");

  return normalized === value;
}

function isSafeSourceArchivePath(path: string): boolean {
  return (
    path.startsWith(SOURCE_ROUTE_PREFIX) &&
    path.endsWith(".zip") &&
    !path.includes("..") &&
    !path.includes("\\") &&
    !path.includes("?") &&
    !path.includes("#")
  );
}

function readEnabledPlugin(
  value: unknown,
  index: number,
  errors: string[],
): StaticDynamicEnabledPlugin | undefined {
  if (!isRecord(value)) {
    errors.push(`enabledPlugins[${index}] must be an object`);
    return undefined;
  }

  const slug = requiredString(value, "slug", errors, `enabledPlugins[${index}].slug`);
  const version = requiredString(value, "version", errors, `enabledPlugins[${index}].version`);
  const entry = optionalString(value, "entry", errors, `enabledPlugins[${index}].entry`);
  const name = optionalString(value, "name", errors, `enabledPlugins[${index}].name`);

  if (slug && !PLUGIN_SLUG_PATTERN.test(slug)) {
    errors.push(`enabledPlugins[${index}].slug must be a plugin slug`);
  }

  if (!slug || !version) {
    return undefined;
  }

  return {
    slug,
    version,
    ...(entry ? { entry } : {}),
    ...(name ? { name } : {}),
  };
}

function readSourceArchive(
  value: unknown,
  errors: string[],
): StaticDynamicSourceArchive | undefined {
  if (!isRecord(value)) {
    errors.push("sourceArchive must be an object");
    return undefined;
  }

  const path = requiredString(value, "path", errors, "sourceArchive.path");
  const sha256 = requiredString(value, "sha256", errors, "sourceArchive.sha256");
  const mediaType = requiredString(value, "mediaType", errors, "sourceArchive.mediaType");
  const restoreStrategy = requiredString(
    value,
    "restoreStrategy",
    errors,
    "sourceArchive.restoreStrategy",
  );
  const bytes = value.bytes;

  if (path && !isSafeSourceArchivePath(path)) {
    errors.push(
      `sourceArchive.path must point to a versioned ZIP under ${SOURCE_ROUTE_PREFIX}`,
    );
  }

  if (sha256 && !SHA256_HASH_PATTERN.test(sha256)) {
    errors.push("sourceArchive.sha256 must be a sha256 hash");
  }

  if (mediaType && mediaType !== "application/zip") {
    errors.push("sourceArchive.mediaType must be application/zip");
  }

  if (restoreStrategy && restoreStrategy !== "playground-site-archive") {
    errors.push("sourceArchive.restoreStrategy must be playground-site-archive");
  }

  if (
    bytes !== undefined &&
    (typeof bytes !== "number" || !Number.isInteger(bytes) || bytes <= 0)
  ) {
    errors.push("sourceArchive.bytes must be a positive integer when present");
  }

  if (
    !path ||
    !sha256 ||
    mediaType !== "application/zip" ||
    restoreStrategy !== "playground-site-archive"
  ) {
    return undefined;
  }

  return {
    path,
    sha256,
    mediaType,
    restoreStrategy,
    ...(typeof bytes === "number" ? { bytes } : {}),
  };
}

function readSite(
  value: unknown,
  errors: string[],
): StaticDynamicSourceManifest["site"] | undefined {
  if (value === undefined) {
    return undefined;
  }

  if (!isRecord(value)) {
    errors.push("site must be an object when present");
    return undefined;
  }

  const title = optionalString(value, "title", errors, "site.title");
  const homeUrl = optionalString(value, "homeUrl", errors, "site.homeUrl");

  if (homeUrl) {
    try {
      new URL(homeUrl);
    } catch {
      errors.push("site.homeUrl must be an absolute URL");
    }
  }

  return {
    ...(title ? { title } : {}),
    ...(homeUrl ? { homeUrl } : {}),
  };
}

function readSourceProfile(
  value: unknown,
  errors: string[],
): StaticDynamicSourceProfile | undefined {
  if (value === undefined) {
    return undefined;
  }

  if (!isRecord(value)) {
    errors.push("sourceProfile must be an object when present");
    return undefined;
  }

  const type = requiredString(value, "type", errors, "sourceProfile.type");
  const originalSourcePath = optionalString(
    value,
    "originalSourcePath",
    errors,
    "sourceProfile.originalSourcePath",
  );
  const allowedTypes = new Set<SourceProfileType>([
    "wordpress",
    "jekyll",
    "astro",
    "docusaurus",
    "obsidian",
    "markdown-docs",
  ]);

  if (type && !allowedTypes.has(type as SourceProfileType)) {
    errors.push("sourceProfile.type must be a supported source profile");
  }

  if (!type || !allowedTypes.has(type as SourceProfileType)) {
    return undefined;
  }

  return {
    type: type as SourceProfileType,
    ...(originalSourcePath ? { originalSourcePath } : {}),
  };
}

function readStringArray(
  value: unknown,
  key: string,
  errors: string[],
): string[] | undefined {
  if (value === undefined) {
    return undefined;
  }

  if (!Array.isArray(value)) {
    errors.push(`${key} must be an array when present`);
    return undefined;
  }

  const strings: string[] = [];

  value.forEach((item, index) => {
    if (typeof item !== "string" || item.trim() === "") {
      errors.push(`${key}[${index}] must be a non-empty string`);
      return;
    }

    strings.push(item);
  });

  return strings;
}

function readAdminChecks(
  value: unknown,
  errors: string[],
): StaticDynamicAdminChecks | undefined {
  if (value === undefined) {
    return undefined;
  }

  if (!isRecord(value)) {
    errors.push("adminChecks must be an object when present");
    return undefined;
  }

  const seededContentTitles = readStringArray(
    value.seededContentTitles,
    "adminChecks.seededContentTitles",
    errors,
  );
  const pluginSlugs = readStringArray(value.pluginSlugs, "adminChecks.pluginSlugs", errors);

  pluginSlugs?.forEach((slug, index) => {
    if (!PLUGIN_SLUG_PATTERN.test(slug)) {
      errors.push(`adminChecks.pluginSlugs[${index}] must be a plugin slug`);
    }
  });

  return {
    ...(seededContentTitles ? { seededContentTitles } : {}),
    ...(pluginSlugs ? { pluginSlugs } : {}),
  };
}

/**
 * Validates the source manifest published at /_static-dynamic-source/manifest.json.
 *
 * This contract is intentionally small: it captures the runtime versions and
 * source bundle needed to recreate the editable WordPress site in Playground.
 */
export function validateSourceManifest(input: unknown): SourceManifestValidationResult {
  const errors: string[] = [];

  if (!isRecord(input)) {
    return {
      valid: false,
      errors: ["manifest must be an object"],
    };
  }

  if (input.manifestVersion !== SOURCE_MANIFEST_VERSION) {
    errors.push(`manifestVersion must be ${SOURCE_MANIFEST_VERSION}`);
  }

  const createdAt = requiredString(input, "createdAt", errors);
  const wordpressVersion = requiredString(input, "wordpressVersion", errors);
  const phpTarget = requiredString(input, "phpTarget", errors);
  const staticExportVersion = requiredString(input, "staticExportVersion", errors);
  const contentSnapshotHash = requiredString(input, "contentSnapshotHash", errors);
  const sourceArchive = readSourceArchive(input.sourceArchive, errors);
  const site = readSite(input.site, errors);
  const sourceProfile = readSourceProfile(input.sourceProfile, errors);
  const adminChecks = readAdminChecks(input.adminChecks, errors);
  const enabledPluginsValue = input.enabledPlugins;
  const enabledPlugins: StaticDynamicEnabledPlugin[] = [];

  if (!Array.isArray(enabledPluginsValue) || enabledPluginsValue.length === 0) {
    errors.push("enabledPlugins must be a non-empty array");
  } else {
    enabledPluginsValue.forEach((plugin, index) => {
      const enabledPlugin = readEnabledPlugin(plugin, index, errors);

      if (enabledPlugin) {
        enabledPlugins.push(enabledPlugin);
      }
    });
  }

  if (createdAt && !isIsoTimestamp(createdAt)) {
    errors.push("createdAt must be an ISO 8601 UTC timestamp");
  }

  if (phpTarget && !PHP_TARGET_PATTERN.test(phpTarget)) {
    errors.push("phpTarget must be a major.minor PHP target");
  }

  if (contentSnapshotHash && !SHA256_HASH_PATTERN.test(contentSnapshotHash)) {
    errors.push("contentSnapshotHash must be a sha256 hash");
  }

  if (
    errors.length > 0 ||
    !createdAt ||
    !wordpressVersion ||
    !phpTarget ||
    !staticExportVersion ||
    !contentSnapshotHash ||
    !sourceArchive
  ) {
    return {
      valid: false,
      errors,
    };
  }

  return {
    valid: true,
    errors: [],
    manifest: {
      manifestVersion: SOURCE_MANIFEST_VERSION,
      createdAt,
      wordpressVersion,
      phpTarget,
      enabledPlugins,
      staticExportVersion,
      contentSnapshotHash,
      sourceArchive,
      ...(site ? { site } : {}),
      ...(sourceProfile ? { sourceProfile } : {}),
      ...(adminChecks ? { adminChecks } : {}),
    },
  };
}

export function parseSourceManifest(input: unknown): StaticDynamicSourceManifest {
  const result = validateSourceManifest(input);

  if (!result.valid || !result.manifest) {
    throw new SourceManifestValidationError(result.errors);
  }

  return result.manifest;
}

export function isSourceManifest(input: unknown): input is StaticDynamicSourceManifest {
  return validateSourceManifest(input).valid;
}
