import { existsSync } from 'node:fs';
import { join } from 'node:path';

/** Fails fast with a useful message when the editor export has not been copied into the backend. */
export default function globalSetup() {
  const index = join(import.meta.dirname, '../../backend/public/editor/index.html');
  if (!existsSync(index)) {
    throw new Error(`Editor export missing at ${index}. Run "npm run build" in editor/ first.`);
  }
}
