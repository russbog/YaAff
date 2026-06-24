import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';
import { resolve } from 'node:path';

// The SPA is served by admin/app.php which injects <base href=".../admin/app/">,
// so a relative base keeps hashed asset URLs resolving correctly under any
// install sub-path. Build output lands in admin/app/ (committed for deploy hosts
// without a Node toolchain).
export default defineConfig({
  base: './',
  plugins: [react()],
  resolve: {
    alias: { '@': resolve(__dirname, 'src') },
  },
  build: {
    outDir: resolve(__dirname, '../admin/app'),
    emptyOutDir: true,
    chunkSizeWarningLimit: 900,
    rollupOptions: {
      output: {
        manualChunks: {
          vendor: ['react', 'react-dom', 'react-router-dom'],
          query: ['@tanstack/react-query'],
          table: ['@tanstack/react-table', '@tanstack/react-virtual'],
        },
      },
    },
  },
  server: {
    port: 5174,
    proxy: {
      // `npm run dev` proxies API calls to the local PHP dev server.
      '/admin/spa.php': 'http://127.0.0.1:8090',
      '/admin/entityapi.php': 'http://127.0.0.1:8090',
      '/admin/campeditor.php': 'http://127.0.0.1:8090',
      '/admin/dashboard.php': 'http://127.0.0.1:8090',
      '/admin/clicksdata.php': 'http://127.0.0.1:8090',
    },
  },
});
