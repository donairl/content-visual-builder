import { defineConfig, devices } from '@playwright/test';

/**
 * End-to-end tests run against the real stack: the Yii backend serving the static editor export
 * from backend/public/editor, with a throwaway SQLite database (see e2e/global-setup.ts).
 *
 *   npm run build            # export the editor into the backend
 *   npm run e2e
 *
 * Set PLAYWRIGHT_CHROMIUM_EXECUTABLE to use a system Chromium instead of Playwright's download.
 */
const port = Number(process.env.CVB_E2E_PORT ?? 8091);
export const baseURL = `http://127.0.0.1:${port}`;

export default defineConfig({
  testDir: 'e2e',
  timeout: 45_000,
  expect: { timeout: 8_000 },
  fullyParallel: false,
  workers: 1,
  retries: 0,
  reporter: [['list']],
  globalSetup: './e2e/global-setup.ts',
  use: {
    baseURL,
    trace: 'retain-on-failure',
    screenshot: 'only-on-failure',
    viewport: { width: 1440, height: 900 },
    launchOptions: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE ? { executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE } : {},
  },
  projects: [{ name: 'chromium', use: { ...devices['Desktop Chrome'], viewport: { width: 1440, height: 900 } } }],
  webServer: {
    command: `node e2e/serve-backend.mjs ${port}`,
    url: `${baseURL}/login`,
    reuseExistingServer: false,
    timeout: 60_000,
    stdout: 'pipe',
    stderr: 'pipe',
  },
});
