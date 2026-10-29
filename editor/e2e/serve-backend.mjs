// Starts the backend for end-to-end tests on a fresh SQLite database with seeded users.
// Usage: node e2e/serve-backend.mjs <port>   (Playwright's webServer runs this)
import { execFileSync, spawn } from 'node:child_process';
import { mkdirSync, rmSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const port = process.argv[2] ?? '8091';
const backend = join(dirname(fileURLToPath(import.meta.url)), '..', '..', 'backend');
const dataDir = join(backend, 'runtime', 'e2e');
const dbPath = join(dataDir, 'database.sqlite');

rmSync(dataDir, { recursive: true, force: true });
mkdirSync(dataDir, { recursive: true });

const env = {
  ...process.env,
  APP_ENV: 'dev',
  APP_DEBUG: 'false',
  DB_DRIVER: 'sqlite',
  DB_PATH: dbPath,
};

const yii = (...args) => execFileSync('php', ['yii', ...args, '--no-interaction'], { cwd: backend, env, stdio: ['ignore', 'inherit', 'inherit'] });

yii('migrate:up');
yii('user:create', 'admin@e2e.test', 'E2E Admin', '--role=admin', '--password=e2e-admin-password');
yii('user:create', 'author@e2e.test', 'E2E Author', '--role=author', '--password=e2e-author-password');

const server = spawn('php', ['-S', `127.0.0.1:${port}`, '-t', 'public', 'public/index.php'], { cwd: backend, env, stdio: 'inherit' });
const stop = () => server.kill('SIGTERM');
process.on('SIGTERM', stop);
process.on('SIGINT', stop);
server.on('exit', (code) => process.exit(code ?? 0));
