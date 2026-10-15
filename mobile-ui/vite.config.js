import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';
import { basename, dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const projectRoot = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const projectUrlPath = `/${encodeURIComponent(basename(projectRoot))}`;

export default defineConfig({
  plugins: [react()],
  define: {
    'import.meta.env.VITE_PROJECT_PATH': JSON.stringify(projectUrlPath),
  },
  server: {
    port: 5173,
    proxy: {
      '/api': {
        target: 'http://localhost',
        changeOrigin: true,
        secure: false,
        rewrite: (path) => path.replace(/^\/api/, projectUrlPath),
      },
    },
  },
});
