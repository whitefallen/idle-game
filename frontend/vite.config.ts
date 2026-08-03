import { fileURLToPath, URL } from 'node:url';
// From vitest/config rather than vite, so the `test` block is typed.
import { defineConfig } from 'vitest/config';
import react from '@vitejs/plugin-react';
import tailwindcss from '@tailwindcss/vite';

export default defineConfig({
  plugins: [react(), tailwindcss()],

  // The tsconfig paths entry only teaches the type checker; the bundler and
  // the test runner need the alias declared here too.
  resolve: {
    alias: { '@': fileURLToPath(new URL('./src', import.meta.url)) },
  },

  server: {
    host: '0.0.0.0',
    port: 5173,
    // The Windows bind mount does not deliver filesystem events reliably, so
    // the watcher polls rather than silently missing changes.
    watch: { usePolling: true, interval: 300 },

    proxy: {
      // The API is proxied so the browser sees a single origin. That means no
      // CORS configuration in any environment, and session cookies work
      // without SameSite exceptions.
      '/api': {
        target: process.env.VITE_API_PROXY_TARGET ?? 'http://localhost:8080',
        changeOrigin: false,
      },
    },
  },

  test: {
    environment: 'jsdom',
    globals: true,
    setupFiles: ['./src/test/setup.ts'],
  },
});
