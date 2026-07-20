import { defineConfig } from 'vite';
import { resolve } from 'node:path';

export default defineConfig({
  root: '.',
  publicDir: false,
  build: {
    outDir: 'public/build',
    emptyOutDir: true,
    manifest: 'manifest.json',
    rollupOptions: {
      input: {
        app: resolve(__dirname, 'frontend/app.js'),
        styles: resolve(__dirname, 'frontend/app.css'),
      },
    },
  },
});
