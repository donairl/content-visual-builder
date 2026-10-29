/**
 * Cross-language parity: the backend's PHP renderer (backend/bin/render-fixture.php) and the
 * editor's TS renderer must produce the same DOM and byte-identical CSS for every fixture.
 * Skipped when PHP or the backend CLI is unavailable (e.g. a frontend-only checkout).
 */
import { execFileSync } from 'node:child_process';
import { existsSync, readdirSync, readFileSync } from 'node:fs';
import { join } from 'node:path';
import { renderToStaticMarkup } from 'react-dom/server';
import { describe, expect, it } from 'vitest';
import { generateCss } from '@/core/css';
import { siteContext, validateGlobals } from '@/core/globals';
import type { PageDocument } from '@/core/types';
import { validateDocument } from '@/core/validate';
import { PublishedTree } from '@/render/content';
import { canonicalHtml } from './dom';

const root = join(import.meta.dirname, '../..');
const script = join(root, 'backend/bin/render-fixture.php');
const fixtures = join(root, 'schema/fixtures/documents');

function phpAvailable(): boolean {
  try {
    execFileSync('php', ['-v'], { stdio: 'ignore' });
    return existsSync(script);
  } catch {
    return false;
  }
}

const available = phpAvailable();
const names = readdirSync(fixtures)
  .filter((f) => f.endsWith('.json') && !f.endsWith('.context.json'))
  .map((f) => f.replace(/\.json$/, ''));

describe.skipIf(!available)('PHP ↔ TS rendering parity', () => {
  it.each(names)('%s renders identically on both sides', (name) => {
    const path = join(fixtures, `${name}.json`);
    // Optional site data the fixture renders against (README §9).
    const contextPath = join(fixtures, `${name}.context.json`);
    const context = existsSync(contextPath)
      ? (JSON.parse(readFileSync(contextPath, 'utf8')) as { globals?: unknown; components?: Record<string, PageDocument> })
      : null;
    const site = siteContext(context?.globals, new Map(Object.entries(context?.components ?? {}).map(([id, d]) => [Number(id), d])));
    const args = context ? [`--context=${contextPath}`, path] : [path];
    const output = execFileSync('php', [script, ...args], { encoding: 'utf8', stdio: ['ignore', 'pipe', 'ignore'] });
    const php = JSON.parse(output) as { html: string; css: string };
    const doc = JSON.parse(readFileSync(path, 'utf8')) as PageDocument;

    expect(php.css).toBe(generateCss(doc, { kind: 'publish' }, { site }));
    expect(canonicalHtml(php.html)).toBe(canonicalHtml(renderToStaticMarkup(PublishedTree({ doc, site }))));
  });
});

interface ValidationCase {
  name: string;
  valid: boolean;
  errorPath?: string;
  document: unknown;
}

describe.skipIf(!available)('PHP ↔ TS validation parity', () => {
  const cases = JSON.parse(readFileSync(join(root, 'schema/fixtures/validation/cases.json'), 'utf8')) as ValidationCase[];

  it.each(cases.map((c) => [c.name, c] as const))('%s', (_name, c) => {
    const output = execFileSync('php', [script, '--validate', '-'], {
      input: JSON.stringify(c.document),
      encoding: 'utf8',
      stdio: ['pipe', 'pipe', 'ignore'],
    });
    const php = (JSON.parse(output) as { errors: { path: string }[] }).errors.map((e) => e.path);
    const ts = validateDocument(c.document).map((issue) => issue.path);
    if (c.valid) {
      expect(php).toEqual([]);
      expect(ts).toEqual([]);
    } else {
      expect(php).toContain(c.errorPath);
      expect(ts).toContain(c.errorPath);
    }
  });
});

describe.skipIf(!available)('PHP ↔ TS globals validation parity', () => {
  const cases = JSON.parse(readFileSync(join(root, 'schema/fixtures/validation/globals-cases.json'), 'utf8')) as (Omit<ValidationCase, 'document'> & { globals: unknown })[];

  it.each(cases.map((c) => [c.name, c] as const))('%s', (_name, c) => {
    const output = execFileSync('php', [script, '--validate-globals', '-'], {
      input: JSON.stringify(c.globals),
      encoding: 'utf8',
      stdio: ['pipe', 'pipe', 'ignore'],
    });
    const php = (JSON.parse(output) as { errors: { path: string }[] }).errors.map((e) => e.path);
    const ts = validateGlobals(c.globals).map((issue) => issue.path);
    if (c.valid) {
      expect(php).toEqual([]);
      expect(ts).toEqual([]);
    } else {
      expect(php).toContain(c.errorPath);
      expect(ts).toContain(c.errorPath);
    }
  });
});
