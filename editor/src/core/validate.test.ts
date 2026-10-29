import { readFileSync } from 'node:fs';
import { join } from 'node:path';
import { describe, expect, it } from 'vitest';
import { codePointLength, hasAtMostDecimals, isValidStyleValue, validateDocument } from './validate';
import { styleProperties } from './schema';

const fixtures = join(import.meta.dirname, '../../../schema/fixtures');
const read = (path: string) => JSON.parse(readFileSync(join(fixtures, path), 'utf8'));

interface Case {
  name: string;
  valid: boolean;
  errorPath?: string;
  document: unknown;
}

describe('validateDocument — shared cases', () => {
  const cases = read('validation/cases.json') as Case[];

  it.each(cases.filter((c) => c.valid).map((c) => [c.name, c] as const))('accepts: %s', (_name, c) => {
    expect(validateDocument(c.document)).toEqual([]);
  });

  it.each(cases.filter((c) => !c.valid).map((c) => [c.name, c] as const))('rejects: %s', (_name, c) => {
    const paths = validateDocument(c.document).map((issue) => issue.path);
    expect(paths).toContain(c.errorPath);
  });

  it.each(['landing', 'styles', 'edge', 'custom-css', 'globals', 'components'])('accepts parity document %s', (name) => {
    expect(validateDocument(read(`documents/${name}.json`))).toEqual([]);
  });
});

describe('validateDocument — limits', () => {
  it('rejects documents above the byte limit', () => {
    const doc = read('documents/edge.json');
    expect(validateDocument(doc, 3 * 1024 * 1024).map((i) => i.path)).toContain('document');
  });

  it('rejects non-objects without throwing', () => {
    expect(validateDocument(null).map((i) => i.path)).toEqual(['document']);
    expect(validateDocument([]).map((i) => i.path)).toEqual(['document']);
  });
});

describe('value helpers', () => {
  it('counts code points, not UTF-16 units', () => {
    expect(codePointLength('a😀')).toBe(2);
  });

  it('checks decimal places', () => {
    expect(hasAtMostDecimals(24.125, 3)).toBe(true);
    expect(hasAtMostDecimals(1.2345, 3)).toBe(false);
    expect(hasAtMostDecimals(-0.5, 3)).toBe(true);
  });

  it('validates style values by type', () => {
    expect(isValidStyleValue(styleProperties.color!, '#abc')).toBe(true);
    expect(isValidStyleValue(styleProperties.color!, 'red')).toBe(false);
    expect(isValidStyleValue(styleProperties.fontSize!, { value: 12, unit: 'px' })).toBe(true);
    expect(isValidStyleValue(styleProperties.fontSize!, { value: 12, unit: 'px', extra: 1 })).toBe(false);
    expect(isValidStyleValue(styleProperties.padding!, { top: null, unit: 'px' })).toBe(true);
    expect(isValidStyleValue(styleProperties.background!, { type: 'image', imageUrl: '' })).toBe(true);
    expect(isValidStyleValue(styleProperties.background!, { type: 'image', imageUrl: 'javascript:x' })).toBe(false);
    expect(isValidStyleValue(styleProperties.zIndex!, 5.0)).toBe(true);
    expect(isValidStyleValue(styleProperties.opacity!, true)).toBe(false);
  });
});
