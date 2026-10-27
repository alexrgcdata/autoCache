import { defineConfig } from "vite";

// One IIFE plus CSS: the packager embeds both into a double-clickable HTML demo.
export default defineConfig({
  define: { "process.env.NODE_ENV": JSON.stringify("production") },
  build: {
    outDir: "../.release-build/demo",
    emptyOutDir: true,
    lib: { entry: "src/demo.tsx", name: "RgcCacheDemo", formats: ["iife"], fileName: () => "demo.js", cssFileName: "demo" },
  },
});
