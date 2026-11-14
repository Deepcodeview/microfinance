import react from '@vitejs/plugin-react'
import { defineConfig } from 'vite'

// https://vite.dev/config/
export default defineConfig({
  plugins: [react()],
  server: {
    port: 5180,
    strictPort: true,
    // Same policy as production: pages are never kept in the back/forward cache (banking data;
    // also stops a frozen page from holding the offline IndexedDB open).
    headers: { 'Cache-Control': 'no-store' },
    // Same-origin in development, matching the cPanel deployment where web and API share a domain.
    proxy: {
      '/api': process.env.API_PROXY ?? 'http://127.0.0.1:8095',
    },
  },
})
