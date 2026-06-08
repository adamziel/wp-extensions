import { existsSync, mkdtempSync, readFileSync, rmSync, writeFileSync } from "node:fs";
import { tmpdir } from "node:os";
import { join, resolve } from "node:path";
import { describe, expect, it } from "vitest";
import {
  buildPlaygroundCommand,
  extractStaticZip,
  findLinkedStaticEntry,
  verifyStaticZip,
} from "../scripts/export-playground-static.mjs";

describe("Playground static export pipeline", () => {
  it("builds the Playground CLI command with the plugin and export mounts", () => {
    const command = buildPlaygroundCommand({
      cliPackage: "@wp-playground/cli@latest",
      pluginDir: "/repo/static-site-generator",
      exportsDir: "/repo/static-dynamic-publisher/.exports",
      blueprintPath: "/repo/static-dynamic-publisher/blueprints/export.json",
    });

    expect(command).toEqual({
      command: "npx",
      args: [
        "--yes",
        "@wp-playground/cli@latest",
        "run-blueprint",
        "--mount=/repo/static-site-generator:/wordpress/wp-content/plugins/static-site-generator",
        "--mount=/repo/static-dynamic-publisher/.exports:/exports",
        "--blueprint=/repo/static-dynamic-publisher/blueprints/export.json",
      ],
    });
  });

  it("verifies and extracts a ZIP with index.html and a linked generated page", () => {
    const workspace = mkdtempSync(join(tmpdir(), "static-export-pipeline-"));
    const zipPath = join(workspace, "static-site.zip");
    const assetsDir = join(workspace, "dist/site");

    writeFileSync(
      zipPath,
      createStoredZip({
        "index.html":
          '<!doctype html><a href="/docs/">Docs</a><link rel="stylesheet" href="./assets/site.css">',
        "docs/index.html": "<!doctype html><h1>Docs</h1>",
        "assets/site.css": "body { color: #1d2327; }",
      }),
    );
    writeFileSync(join(workspace, "stale.txt"), "outside dist");

    const result = extractStaticZip(zipPath, assetsDir);

    expect(result.linkedEntry).toBe("docs/index.html");
    expect(existsSync(resolve(assetsDir, "index.html"))).toBe(true);
    expect(readFileSync(resolve(assetsDir, "docs/index.html"), "utf8")).toContain("Docs");

    rmSync(workspace, { recursive: true, force: true });
  });

  it("accepts a linked static asset when the homepage links no generated pages", () => {
    const workspace = mkdtempSync(join(tmpdir(), "static-export-pipeline-"));
    const zipPath = join(workspace, "static-site.zip");

    writeFileSync(
      zipPath,
      createStoredZip({
        "index.html": '<!doctype html><link rel="stylesheet" href="assets/site.css">',
        "assets/site.css": "body { color: #1d2327; }",
      }),
    );

    expect(verifyStaticZip(zipPath).linkedEntry).toBe("assets/site.css");

    rmSync(workspace, { recursive: true, force: true });
  });

  it("rejects a ZIP when index.html has no linked generated page or asset", () => {
    const workspace = mkdtempSync(join(tmpdir(), "static-export-pipeline-"));
    const zipPath = join(workspace, "static-site.zip");

    writeFileSync(
      zipPath,
      createStoredZip({
        "index.html": "<!doctype html><h1>Only a homepage</h1>",
      }),
    );

    expect(() => verifyStaticZip(zipPath)).toThrow(/linked generated page or asset/);

    rmSync(workspace, { recursive: true, force: true });
  });

  it("rejects unsafe ZIP entry paths before extraction", () => {
    const workspace = mkdtempSync(join(tmpdir(), "static-export-pipeline-"));
    const zipPath = join(workspace, "static-site.zip");

    writeFileSync(
      zipPath,
      createStoredZip({
        "index.html": '<!doctype html><link rel="stylesheet" href="assets/site.css">',
        "../assets/site.css": "body { color: #1d2327; }",
      }),
    );

    expect(() => verifyStaticZip(zipPath)).toThrow(/Unsafe ZIP entry path/);

    rmSync(workspace, { recursive: true, force: true });
  });

  it("maps local homepage references to exported ZIP entries", () => {
    const entries = ["index.html", "docs/index.html", "assets/site.css"];

    expect(findLinkedStaticEntry('<a href="/docs/">Docs</a>', entries)).toBe(
      "docs/index.html",
    );
    expect(findLinkedStaticEntry('<link href="./assets/site.css" rel="stylesheet">', entries)).toBe(
      "assets/site.css",
    );
    expect(findLinkedStaticEntry('<a href="https://example.com/docs/">Docs</a>', entries)).toBe(
      null,
    );
  });
});

function createStoredZip(entries: Record<string, string>): Buffer {
  const localParts: Buffer[] = [];
  const centralParts: Buffer[] = [];
  let offset = 0;

  for (const [name, contents] of Object.entries(entries)) {
    const nameBuffer = Buffer.from(name);
    const data = Buffer.from(contents);
    const crc = crc32(data);
    const localHeader = Buffer.alloc(30);

    localHeader.writeUInt32LE(0x04034b50, 0);
    localHeader.writeUInt16LE(20, 4);
    localHeader.writeUInt16LE(0, 6);
    localHeader.writeUInt16LE(0, 8);
    localHeader.writeUInt16LE(0, 10);
    localHeader.writeUInt16LE(0, 12);
    localHeader.writeUInt32LE(crc, 14);
    localHeader.writeUInt32LE(data.length, 18);
    localHeader.writeUInt32LE(data.length, 22);
    localHeader.writeUInt16LE(nameBuffer.length, 26);
    localHeader.writeUInt16LE(0, 28);

    localParts.push(localHeader, nameBuffer, data);

    const centralHeader = Buffer.alloc(46);

    centralHeader.writeUInt32LE(0x02014b50, 0);
    centralHeader.writeUInt16LE(20, 4);
    centralHeader.writeUInt16LE(20, 6);
    centralHeader.writeUInt16LE(0, 8);
    centralHeader.writeUInt16LE(0, 10);
    centralHeader.writeUInt16LE(0, 12);
    centralHeader.writeUInt16LE(0, 14);
    centralHeader.writeUInt32LE(crc, 16);
    centralHeader.writeUInt32LE(data.length, 20);
    centralHeader.writeUInt32LE(data.length, 24);
    centralHeader.writeUInt16LE(nameBuffer.length, 28);
    centralHeader.writeUInt16LE(0, 30);
    centralHeader.writeUInt16LE(0, 32);
    centralHeader.writeUInt16LE(0, 34);
    centralHeader.writeUInt16LE(0, 36);
    centralHeader.writeUInt32LE(0, 38);
    centralHeader.writeUInt32LE(offset, 42);

    centralParts.push(centralHeader, nameBuffer);
    offset += localHeader.length + nameBuffer.length + data.length;
  }

  const centralDirectory = Buffer.concat(centralParts);
  const endOfCentralDirectory = Buffer.alloc(22);

  endOfCentralDirectory.writeUInt32LE(0x06054b50, 0);
  endOfCentralDirectory.writeUInt16LE(0, 4);
  endOfCentralDirectory.writeUInt16LE(0, 6);
  endOfCentralDirectory.writeUInt16LE(Object.keys(entries).length, 8);
  endOfCentralDirectory.writeUInt16LE(Object.keys(entries).length, 10);
  endOfCentralDirectory.writeUInt32LE(centralDirectory.length, 12);
  endOfCentralDirectory.writeUInt32LE(offset, 16);
  endOfCentralDirectory.writeUInt16LE(0, 20);

  return Buffer.concat([...localParts, centralDirectory, endOfCentralDirectory]);
}

function crc32(data: Buffer): number {
  let crc = 0xffffffff;

  for (const byte of data) {
    crc ^= byte;

    for (let bit = 0; bit < 8; bit++) {
      crc = 0 !== (crc & 1) ? (crc >>> 1) ^ 0xedb88320 : crc >>> 1;
    }
  }

  return (crc ^ 0xffffffff) >>> 0;
}
