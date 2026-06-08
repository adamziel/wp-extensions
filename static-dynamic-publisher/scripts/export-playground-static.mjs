import { execFileSync, spawnSync } from "node:child_process";
import { chmodSync, existsSync, mkdirSync, rmSync, statSync } from "node:fs";
import { dirname, resolve } from "node:path";
import { fileURLToPath } from "node:url";

const currentFile = fileURLToPath(import.meta.url);
const packageRoot = resolve(dirname(currentFile), "..");
const repoRoot = resolve(packageRoot, "..");
const defaultPlaygroundTimeoutMs = parsePositiveInteger(
  process.env.PLAYGROUND_EXPORT_TIMEOUT_MS,
  300000,
);

export const defaultPipelinePaths = {
  repoRoot,
  packageRoot,
  pluginDir: resolve(repoRoot, "static-site-generator"),
  exportsDir: resolve(packageRoot, ".exports"),
  zipPath: resolve(packageRoot, ".exports/static-site.zip"),
  assetsDir: resolve(packageRoot, "dist/site"),
  blueprintPath: resolve(packageRoot, "blueprints/export.json"),
};

export function buildPlaygroundCommand({
  cliPackage = process.env.WP_PLAYGROUND_CLI_PACKAGE || "@wp-playground/cli@latest",
  pluginDir = defaultPipelinePaths.pluginDir,
  exportsDir = defaultPipelinePaths.exportsDir,
  blueprintPath = defaultPipelinePaths.blueprintPath,
} = {}) {
  return {
    command: "npx",
    args: [
      "--yes",
      cliPackage,
      "run-blueprint",
      `--mount=${pluginDir}:/wordpress/wp-content/plugins/static-site-generator`,
      `--mount=${exportsDir}:/exports`,
      `--blueprint=${blueprintPath}`,
    ],
  };
}

export function listZipEntries(zipPath) {
  const output = execFileSync("unzip", ["-Z1", zipPath], {
    encoding: "utf8",
    maxBuffer: 1024 * 1024 * 20,
  });

  return output
    .split(/\r?\n/)
    .map((entry) => entry.trim())
    .filter(Boolean);
}

export function normalizeZipEntry(entry) {
  const normalized = entry.replace(/\\/g, "/").replace(/\/+$/, "");

  if (
    "" === normalized ||
    normalized.startsWith("/") ||
    normalized.startsWith("//") ||
    /^[A-Za-z]:\//.test(normalized)
  ) {
    throw new Error(`Unsafe ZIP entry path: ${entry}`);
  }

  const parts = normalized.split("/");

  if (parts.some((part) => "" === part || "." === part || ".." === part)) {
    throw new Error(`Unsafe ZIP entry path: ${entry}`);
  }

  return normalized;
}

export function getSafeZipFileEntries(zipPath) {
  const entries = listZipEntries(zipPath);

  entries.forEach(normalizeZipEntry);

  return entries.filter((entry) => !entry.endsWith("/")).map(normalizeZipEntry);
}

export function findLinkedStaticEntry(indexHtml, entries) {
  const entrySet = new Set(entries);
  const localReferences = indexHtml.matchAll(/\b(?:href|src)=["']([^"']+)["']/gi);

  for (const match of localReferences) {
    const candidate = normalizeLocalReference(match[1]);

    if (null === candidate || "index.html" === candidate) {
      continue;
    }

    if (entrySet.has(candidate)) {
      return candidate;
    }
  }

  return null;
}

export function verifyStaticZip(zipPath) {
  if (!existsSync(zipPath) || !statSync(zipPath).isFile()) {
    throw new Error(`Static export ZIP does not exist: ${zipPath}`);
  }

  const entries = getSafeZipFileEntries(zipPath);

  if (!entries.includes("index.html")) {
    throw new Error("Static export ZIP must contain index.html at the ZIP root.");
  }

  const indexHtml = execFileSync("unzip", ["-p", zipPath, "index.html"], {
    encoding: "utf8",
    maxBuffer: 1024 * 1024 * 20,
  });
  const linkedEntry = findLinkedStaticEntry(indexHtml, entries);

  if (null === linkedEntry) {
    throw new Error(
      "Static export ZIP must contain index.html with at least one linked generated page or asset.",
    );
  }

  return {
    zipPath,
    entries,
    linkedEntry,
  };
}

export function extractStaticZip(zipPath, assetsDir) {
  const verification = verifyStaticZip(zipPath);

  rmSync(assetsDir, { recursive: true, force: true });
  mkdirSync(assetsDir, { recursive: true });
  execFileSync("unzip", ["-q", zipPath, "-d", assetsDir], {
    stdio: "pipe",
    maxBuffer: 1024 * 1024 * 20,
  });

  const extractedIndex = resolve(assetsDir, "index.html");

  if (!existsSync(extractedIndex)) {
    throw new Error(`Static export extraction did not produce ${extractedIndex}`);
  }

  return verification;
}

export function runPlaygroundExport(options = {}) {
  const exportsDir = options.exportsDir || defaultPipelinePaths.exportsDir;
  const zipPath = options.zipPath || resolve(exportsDir, "static-site.zip");
  const pluginDir = options.pluginDir || defaultPipelinePaths.pluginDir;
  const blueprintPath = options.blueprintPath || defaultPipelinePaths.blueprintPath;
  const cwd = options.cwd || defaultPipelinePaths.repoRoot;
  const timeoutMs = options.timeoutMs || defaultPlaygroundTimeoutMs;

  mkdirSync(exportsDir, { recursive: true });
  chmodSync(exportsDir, 0o777);
  rmSync(zipPath, { force: true });

  const { command, args } = buildPlaygroundCommand({
    pluginDir,
    exportsDir,
    blueprintPath,
  });
  const result = spawnSync(command, args, {
    cwd,
    stdio: "inherit",
    shell: false,
    timeout: timeoutMs,
  });

  if (null !== result.error && undefined !== result.error) {
    throw result.error;
  }

  if (0 !== result.status) {
    throw new Error(`Playground CLI export failed with exit code ${result.status}.`);
  }

  return verifyStaticZip(zipPath);
}

export function runPipeline(options = {}) {
  const exportsDir = options.exportsDir || defaultPipelinePaths.exportsDir;
  const zipPath = options.zipPath || resolve(exportsDir, "static-site.zip");
  const assetsDir = options.assetsDir || defaultPipelinePaths.assetsDir;

  if (!options.skipPlayground) {
    runPlaygroundExport({ ...options, exportsDir, zipPath });
  }

  if (options.verifyOnly) {
    return verifyStaticZip(zipPath);
  }

  return extractStaticZip(zipPath, assetsDir);
}

function normalizeLocalReference(reference) {
  const trimmed = reference.trim();

  if (
    "" === trimmed ||
    "#" === trimmed[0] ||
    trimmed.startsWith("//") ||
    /^[A-Za-z][A-Za-z0-9+.-]*:/.test(trimmed)
  ) {
    return null;
  }

  const withoutFragment = trimmed.split("#", 1)[0];
  const withoutQuery = withoutFragment.split("?", 1)[0];
  let relativePath = withoutQuery.replace(/^\/+/, "").replace(/^\.\//, "");

  if ("" === relativePath) {
    return "index.html";
  }

  try {
    relativePath = decodeURIComponent(relativePath);
  } catch {
    return null;
  }

  if (relativePath.endsWith("/")) {
    relativePath += "index.html";
  }

  return normalizeZipEntry(relativePath);
}

function parseArgs(args) {
  const options = {};

  for (const arg of args) {
    if ("--skip-playground" === arg) {
      options.skipPlayground = true;
    } else if ("--verify-only" === arg) {
      options.verifyOnly = true;
      options.skipPlayground = true;
    } else if (arg.startsWith("--zip=")) {
      options.zipPath = resolve(arg.slice("--zip=".length));
    } else if (arg.startsWith("--assets-dir=")) {
      options.assetsDir = resolve(arg.slice("--assets-dir=".length));
    } else if (arg.startsWith("--exports-dir=")) {
      options.exportsDir = resolve(arg.slice("--exports-dir=".length));
    } else if (arg.startsWith("--blueprint=")) {
      options.blueprintPath = resolve(arg.slice("--blueprint=".length));
    } else if (arg.startsWith("--plugin-dir=")) {
      options.pluginDir = resolve(arg.slice("--plugin-dir=".length));
    } else if (arg.startsWith("--timeout-ms=")) {
      options.timeoutMs = parsePositiveInteger(arg.slice("--timeout-ms=".length), 0);
    } else {
      throw new Error(`Unknown option: ${arg}`);
    }
  }

  if (options.exportsDir && !options.zipPath) {
    options.zipPath = resolve(options.exportsDir, "static-site.zip");
  }

  return options;
}

function parsePositiveInteger(value, fallback) {
  const parsed = Number.parseInt(value || "", 10);

  if (!Number.isFinite(parsed) || parsed <= 0) {
    return fallback;
  }

  return parsed;
}

function printResult(result, assetsDir, verifyOnly) {
  const destination = verifyOnly ? "ZIP" : assetsDir;
  console.log(
    `Verified ${result.entries.length} static export files and linked entry ${result.linkedEntry}.`,
  );
  console.log(`Static export is ready at ${destination}.`);
}

if (process.argv[1] && resolve(process.argv[1]) === currentFile) {
  try {
    const options = parseArgs(process.argv.slice(2));
    const result = runPipeline(options);
    printResult(
      result,
      options.assetsDir || defaultPipelinePaths.assetsDir,
      Boolean(options.verifyOnly),
    );
  } catch (error) {
    console.error(error instanceof Error ? error.message : error);
    process.exitCode = 1;
  }
}
