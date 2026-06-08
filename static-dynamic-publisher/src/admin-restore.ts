import {
  SOURCE_MANIFEST_PATH,
  type StaticDynamicEnabledPlugin,
  type StaticDynamicSourceManifest,
  parseSourceManifest,
} from "./source-manifest";

export const PLAYGROUND_BLUEPRINT_SCHEMA_URL =
  "https://playground.wordpress.net/blueprint-schema.json";

export interface PlaygroundUrlResource {
  resource: "url";
  url: string;
}

export interface PlaygroundUnzipStep {
  step: "unzip";
  zipFile: PlaygroundUrlResource;
  extractToPath: "/wordpress";
}

export interface PlaygroundLoginStep {
  step: "login";
}

export interface PlaygroundAdminRestoreBlueprint {
  $schema: typeof PLAYGROUND_BLUEPRINT_SCHEMA_URL;
  landingPage: "/wp-admin/";
  preferredVersions: {
    php: string;
    wp: string;
  };
  features: {
    networking: true;
  };
  steps: [PlaygroundUnzipStep, PlaygroundLoginStep];
}

export interface AdminRestoreTarget {
  kind: "wordpress-playground-admin-restore";
  adminPath: "/wp-admin/";
  manifestUrl: string;
  sourceArchiveUrl: string;
  sourceArchiveHash: string;
  contentSnapshotHash: string;
  createdAt: string;
  expectedRuntime: {
    wordpressVersion: string;
    phpTarget: string;
  };
  enabledPlugins: StaticDynamicEnabledPlugin[];
  expectedAdminChecks: {
    seededContentTitles: string[];
    pluginSlugs: string[];
  };
  blueprint: PlaygroundAdminRestoreBlueprint;
}

export interface AdminRestoreTargetOptions {
  origin?: string | URL;
  manifestPath?: string;
}

function resolveWorkerPath(path: string, origin?: string | URL): string {
  if (!origin) {
    return path;
  }

  return new URL(path, origin).toString();
}

export function buildPlaygroundAdminRestoreBlueprint(
  manifest: StaticDynamicSourceManifest,
  sourceArchiveUrl: string,
): PlaygroundAdminRestoreBlueprint {
  return {
    $schema: PLAYGROUND_BLUEPRINT_SCHEMA_URL,
    landingPage: "/wp-admin/",
    preferredVersions: {
      php: manifest.phpTarget,
      wp: manifest.wordpressVersion,
    },
    features: {
      networking: true,
    },
    steps: [
      {
        step: "unzip",
        zipFile: {
          resource: "url",
          url: sourceArchiveUrl,
        },
        extractToPath: "/wordpress",
      },
      {
        step: "login",
      },
    ],
  };
}

/**
 * Converts a preserved source manifest into the data an admin shell needs to
 * boot Playground back into the editable WordPress admin.
 */
export function describeAdminRestoreTarget(
  input: unknown,
  options: AdminRestoreTargetOptions = {},
): AdminRestoreTarget {
  const manifest = parseSourceManifest(input);
  const manifestPath = options.manifestPath ?? SOURCE_MANIFEST_PATH;
  const manifestUrl = resolveWorkerPath(manifestPath, options.origin);
  const sourceArchiveUrl = resolveWorkerPath(manifest.sourceArchive.path, options.origin);
  const pluginSlugsFromManifest = manifest.enabledPlugins.map((plugin) => plugin.slug);
  const expectedPluginSlugs = manifest.adminChecks?.pluginSlugs ?? pluginSlugsFromManifest;

  return {
    kind: "wordpress-playground-admin-restore",
    adminPath: "/wp-admin/",
    manifestUrl,
    sourceArchiveUrl,
    sourceArchiveHash: manifest.sourceArchive.sha256,
    contentSnapshotHash: manifest.contentSnapshotHash,
    createdAt: manifest.createdAt,
    expectedRuntime: {
      wordpressVersion: manifest.wordpressVersion,
      phpTarget: manifest.phpTarget,
    },
    enabledPlugins: manifest.enabledPlugins,
    expectedAdminChecks: {
      seededContentTitles: manifest.adminChecks?.seededContentTitles ?? [],
      pluginSlugs: expectedPluginSlugs,
    },
    blueprint: buildPlaygroundAdminRestoreBlueprint(manifest, sourceArchiveUrl),
  };
}
