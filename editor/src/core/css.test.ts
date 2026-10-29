import { existsSync, readFileSync } from 'node:fs';
import { join } from 'node:path';
import { describe, expect, it } from 'vitest';
import { fmt, generateCss } from './css';
import { siteContext } from './globals';
import type { PageDocument } from './types';

const docs = join(import.meta.dirname, '../../../schema/fixtures/documents');
const load = (name: string) => JSON.parse(readFileSync(join(docs, `${name}.json`), 'utf8')) as PageDocument;
const expected = (name: string) => readFileSync(join(docs, `${name}.expected.css`), 'utf8');

describe('generateCss — publish mode', () => {
  it.each(['landing', 'styles', 'edge', 'custom-css', 'globals', 'components'])('matches %s.expected.css byte for byte', (name) => {
    const path = join(docs, `${name}.context.json`);
    const context = existsSync(path) ? (JSON.parse(readFileSync(path, 'utf8')) as { globals?: unknown; components?: Record<string, PageDocument> }) : {};
    const components = new Map(Object.entries(context.components ?? {}).map(([id, doc]) => [Number(id), doc]));
    expect(generateCss(load(name), { kind: 'publish' }, { site: siteContext(context.globals, components) })).toBe(expected(name));
  });

  it('skips invalid values instead of emitting them', () => {
    const doc = load('edge');
    doc.nodes.head0003!.style = {
      color: { desktop: 'red;}body{display:none' },
      fontSize: { desktop: { value: 20, unit: 'px' } },
    };
    expect(generateCss(doc, { kind: 'publish' })).toBe(
      '.cvb-root{--cvb-content-width:1140px}\n.cvb-el-head0003{font-size:20px}',
    );
  });

  it('ignores style keys the widget does not allow', () => {
    const doc = load('edge');
    doc.nodes.head0003!.style = { hoverBackgroundColor: { desktop: '#000' } };
    expect(generateCss(doc, { kind: 'publish' })).toBe('.cvb-root{--cvb-content-width:1140px}');
  });
});

describe('generateCss — editor mode', () => {
  const landing = load('landing');

  it('desktop uses only desktop rules', () => {
    const css = generateCss(landing, { kind: 'editor', device: 'desktop' });
    expect(css).not.toContain('@media');
    expect(css).toContain('.cvb-el-head0001{font-family');
    expect(css).not.toContain('font-size:40px');
    expect(css).toContain('.cvb-el-icon0001{opacity:0.35}');
  });

  it('mobile cascades desktop, tablet, then mobile rules', () => {
    const css = generateCss(landing, { kind: 'editor', device: 'mobile' });
    const desktop = css.indexOf('font-size:56px');
    const tablet = css.indexOf('font-size:40px');
    const mobile = css.indexOf('font-size:32px');
    expect(desktop).toBeGreaterThan(-1);
    expect(tablet).toBeGreaterThan(desktop);
    expect(mobile).toBeGreaterThan(tablet);
    expect(css).toContain('.cvb-el-vid00001{opacity:0.35}');
    expect(css).not.toContain('.cvb-el-icon0001{opacity:0.35}');
  });
});

describe('fmt', () => {
  it.each([
    [10, '10'],
    [-5, '-5'],
    [0, '0'],
    [-0, '0'],
    [1.5, '1.5'],
    [0.125, '0.125'],
    [24.1, '24.1'],
    [2.0, '2'],
  ])('fmt(%s) = %s', (input, output) => {
    expect(fmt(input)).toBe(output);
  });
});
