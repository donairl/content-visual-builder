import { describe, expect, it } from 'vitest';
import { patterns } from '@/core/schema';
import { globalIdFor } from './GlobalsPanel';

describe('globalIdFor', () => {
  it('slugifies names into unique valid ids', () => {
    expect(globalIdFor('Brand Blue', [], 'color')).toBe('brand-blue');
    expect(globalIdFor('Brand Blue', ['brand-blue', 'brand-blue-2'], 'color')).toBe('brand-blue-3');
    expect(globalIdFor('42 Ünïcode!!', [], 'color')).toBe('unicode');
    expect(globalIdFor('***', [], 'font')).toBe('font');
    for (const id of [globalIdFor('x'.repeat(80), [], 'c'), globalIdFor('A', [], 'c')]) expect(id).toMatch(patterns.globalId);
  });
});
