import { readFileSync, writeFileSync } from "node:fs";
import { createHash } from "node:crypto";
import { defineConfig } from "vite";
import react from "@vitejs/plugin-react";

const apiProxyTarget = process.env.VITE_API_PROXY_TARGET || "http://127.0.0.1:8000";

export default defineConfig({
  build: { rollupOptions: { output: { manualChunks: { react: ["react", "react-dom"] } } } },
  optimizeDeps: {
    include: ["react", "react-dom/client"],
  },
  server: {
    proxy: {
      "/api": {
        target: apiProxyTarget,
        changeOrigin: true,
      },
    },
    warmup: {
      clientFiles: ["./src/main.jsx"],
    },
  },
  plugins: [react(), { name: 'version-offline-shell', apply: 'build', closeBundle() {
    const html = readFileSync('dist/index.html', 'utf8');
    const version = createHash('sha256').update(html).digest('hex').slice(0, 16);
    writeFileSync('dist/sw.js', readFileSync('public/sw.js', 'utf8').replaceAll('__BUILD_ID__', version));
  } }],
});
