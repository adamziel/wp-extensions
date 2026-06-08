import type { PluginOption } from "vite";
import { defineConfig } from "vitest/config";

function htmlTextModulePlugin(): PluginOption {
  return {
    name: "html-text-module",
    enforce: "pre",
    transform(source: string, id: string) {
      if (!id.endsWith(".html")) {
        return null;
      }

      return {
        code: `export default ${JSON.stringify(source)};`,
        map: null,
      };
    },
  };
}

export default defineConfig({
  plugins: [htmlTextModulePlugin()],
  test: {
    environment: "node",
    include: ["tests/**/*.test.ts"],
  },
});
