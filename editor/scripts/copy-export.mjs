// Copies the static export (out/) into the backend's public/editor/ so Yii serves it under /editor.
import { cpSync, existsSync, rmSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = join(dirname(fileURLToPath(import.meta.url)), '..');
const source = join(root, 'out');
const target = join(root, '..', 'backend', 'public', 'editor');

if (!existsSync(source)) {
  console.error(`No export found at ${source}; did "next build" run with output: "export"?`);
  process.exit(1);
}
rmSync(target, { recursive: true, force: true });
cpSync(source, target, { recursive: true });
console.log(`Copied editor export to ${target}`);
