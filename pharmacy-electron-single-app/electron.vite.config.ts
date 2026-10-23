import { resolve } from 'node:path';

import tailwindcss from '@tailwindcss/vite';
import react from '@vitejs/plugin-react';
import { defineConfig } from 'electron-vite';

export default defineConfig({
  // node:sqlite is built into Electron's Node; it is never bundled.
  main: { build: { rollupOptions: { external: ['node:sqlite'] } } },
  preload: {},
  renderer: {
    resolve: {
      alias: { '@': resolve(__dirname, 'src/renderer/src') },
    },
    // The shared theme lives in ../packages/ui.
    server: { fs: { allow: [resolve(__dirname, '..')] } },
    plugins: [react(), tailwindcss()],
  },
});
