import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';
import { existsSync, readFileSync } from 'node:fs';
import { basename, dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const projectRoot = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const projectUrlPath = `/${encodeURIComponent(basename(projectRoot))}`;

const findXamppRoot = () => {
  let directory = projectRoot;
  while (true) {
    const certificatePath = resolve(directory, 'apache', 'conf', 'ssl.crt', 'ceit-logbook-lan.crt');
    const keyPath = resolve(directory, 'apache', 'conf', 'ssl.key', 'ceit-logbook-lan.key');
    if (existsSync(certificatePath) && existsSync(keyPath)) {
      return { certificatePath, keyPath };
    }

    const parent = dirname(directory);
    if (parent === directory) {
      throw new Error('LAN HTTPS certificate setup did not complete. Start the app with "npm run dev:lan" so its setup step can create the local certificate.');
    }
    directory = parent;
  }
};

export default defineConfig(({ mode }) => {
  const lanServer = mode === 'lan';
  const https = lanServer
    ? (() => {
      const { certificatePath, keyPath } = findXamppRoot();
      return {
        cert: readFileSync(certificatePath),
        key: readFileSync(keyPath),
      };
    })()
    : undefined;

  return {
    plugins: [react()],
    define: {
      'import.meta.env.VITE_PROJECT_PATH': JSON.stringify(projectUrlPath),
    },
    server: {
      host: lanServer ? '0.0.0.0' : undefined,
      port: 5173,
      strictPort: lanServer,
      https,
      proxy: {
        '/api': {
          target: 'http://localhost',
          changeOrigin: true,
          secure: false,
          rewrite: (path) => path.replace(/^\/api/, projectUrlPath),
        },
      },
    },
  };
});
