import path from 'node:path';
import type { NextConfig } from 'next';

const isDev = process.env.NODE_ENV !== 'production';
const backendUrl = process.env.CVB_BACKEND_URL ?? 'http://127.0.0.1:8080';

/**
 * Production builds are a static export served by the Yii backend under `/editor`, so the editor
 * shares the backend's origin (session cookie + CSRF) and needs no Node runtime.
 *
 * In development the Next dev server proxies every non-editor path (API, login, previews, uploads)
 * to the backend, keeping the same single-origin setup.
 */
const config: NextConfig = {
  basePath: '/editor',
  trailingSlash: true,
  // Without this the dev server would also 308 proxied backend paths (/login → /login/,
  // /api/session → /api/session/), which the backend does not route.
  skipTrailingSlashRedirect: true,
  reactStrictMode: true,
  // Don't write an AGENTS.md into the project on every dev start.
  agentRules: false,
  images: { unoptimized: true },
  // The shared contract lives in ../schema; widen the roots so it can be imported.
  turbopack: { root: path.resolve(import.meta.dirname, '..') },
  outputFileTracingRoot: path.resolve(import.meta.dirname, '..'),
  ...(isDev
    ? {
        async rewrites() {
          return {
            beforeFiles: [],
            afterFiles: [],
            fallback: [{ source: '/:path*', destination: `${backendUrl}/:path*`, basePath: false }],
          };
        },
      }
    : { output: 'export' }),
};

export default config;
