import { defineConfig } from "vite";

export default defineConfig({
  base: "./",
  build: { outDir: "../public/admin", emptyOutDir: true },
  plugins: [
    {
      name: "apache-directory-index",
      generateBundle() {
        this.emitFile({
          type: "asset",
          fileName: ".htaccess",
          source: "DirectoryIndex index.html\n",
        });
      },
    },
  ],
});
