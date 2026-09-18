import { resolve } from "path";
import { defineConfig } from "vite";
import vue from "@vitejs/plugin-vue";

// https://vite.dev/config/
export default defineConfig({
  target: "es2016",
  plugins: [vue()],
  // outDir lives inside public/, so disable vite's static "public" directory feature
  publicDir: false,
  build: {
    lib: {
      entry: resolve(import.meta.dirname, "resources/js/main.js"),
      name: "CrossrefPlugin",
      fileName: "build",
      // IIFE so the script runs as soon as it is loaded via a plain <script> tag,
      // registering the components before the Vue roots on the page are mounted
      formats: ["iife"],
    },
    outDir: resolve(import.meta.dirname, "public/build"),
    rolldownOptions: {
      // Vue is provided by the core frontend bundle
      external: ["vue", "pinia"],
      output: {
        globals: {
          vue: "pkp.modules.vue",
					pinia: "pkp.modules.pinia",
        },
        entryFileNames: "crossref.js",
        assetFileNames: "crossref.[ext]",
      },
    },
  },
});
