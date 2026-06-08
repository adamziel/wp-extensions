import { readFileSync } from "node:fs";
import { fileURLToPath } from "node:url";
import { dirname, resolve } from "node:path";
import { describe, expect, it } from "vitest";
import { parse } from "smol-toml";
import { RESERVED_WORKER_FIRST_ROUTES } from "../src/worker";

const currentDir = dirname(fileURLToPath(import.meta.url));
const packageRoot = resolve(currentDir, "..");

describe("wrangler static assets configuration", () => {
  it("configures generated static assets and Worker-first reserved routes", () => {
    const wranglerConfig = parse(
      readFileSync(resolve(packageRoot, "wrangler.toml"), "utf8"),
    ) as {
      assets?: {
        directory?: string;
        binding?: string;
        run_worker_first?: string[];
      };
    };

    expect(wranglerConfig.assets?.directory).toBe("./dist/site");
    expect(wranglerConfig.assets?.binding).toBe("ASSETS");
    expect(wranglerConfig.assets?.run_worker_first).toEqual([...RESERVED_WORKER_FIRST_ROUTES]);
  });
});
