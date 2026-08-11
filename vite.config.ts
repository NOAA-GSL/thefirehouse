import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';

// `base` is intentionally configurable. Hosting is still open (see creative brief §8):
// the site may end up at sites.gsl/firehouse, so it must be able to build for a
// sub-path without code changes. Set VITE_BASE_PATH at build time.
export default defineConfig({
  base: process.env.VITE_BASE_PATH ?? '/',
  plugins: [react()],
  server: { port: 5173 },
});
