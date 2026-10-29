import { describe, expect, it } from 'vitest';
import { generateCss } from './css';
import { createNode, emptyDocument, insertFragment, setSettings, setStyle } from './document';
import { defaultGlobals, globalColorId, resolveColor, siteContext, validateGlobals, type Globals } from './globals';
import { validateDocument } from './validate';

const globals = (): Globals => ({
  colors: [
    { id: 'brand', name: 'Brand', value: '#123456' },
    { id: 'brand', name: 'Duplicate', value: '#000000' },
    { id: 'bad', name: 'Bad', value: 'red' },
  ],
  fonts: [{ id: 'display', name: 'Display', style: { fontSize: { desktop: { value: 48, unit: 'px' }, mobile: { value: 30, unit: 'px' } } } }],
});

describe('global styles', () => {
  it('ships valid defaults', () => {
    expect(validateGlobals(defaultGlobals())).toEqual([]);
  });

  it('reports duplicates and invalid entries at their paths', () => {
    expect(validateGlobals(globals()).map((i) => i.path)).toEqual(['colors.1.id', 'colors.2.value']);
  });

  it('builds lookups from valid entries only, first id wins', () => {
    const site = siteContext(globals());
    expect([...site.colors]).toEqual([['brand', '#123456']]);
    expect(resolveColor('global:brand', site)).toBe('#123456');
    expect(resolveColor('global:bad', site)).toBeNull();
    expect(resolveColor('#fff', site)).toBe('#fff');
    expect(resolveColor('red', site)).toBeNull();
    expect(siteContext('nonsense').colors.size).toBe(0);
  });

  it('parses references', () => {
    expect(globalColorId('global:brand')).toBe('brand');
    expect(globalColorId('global:Brand')).toBeNull();
    expect(globalColorId('#fff')).toBeNull();
  });

  it('resolves colors and merges font presets per device in editor CSS', () => {
    let doc = insertFragment(emptyDocument(), { rootId: 'head0001', nodes: { head0001: createNode('heading', 'head0001') } }, null, 0);
    doc = setStyle(doc, 'head0001', 'color', 'desktop', 'global:brand');
    doc = setStyle(doc, 'head0001', 'globalFont', 'desktop', 'display');
    doc = setStyle(doc, 'head0001', 'fontSize', 'tablet', { value: 40, unit: 'px' });
    doc = setSettings(doc, 'textColor', 'global:brand');
    expect(validateDocument(doc)).toEqual([]);
    const site = siteContext(globals());
    expect(generateCss(doc, { kind: 'editor', device: 'mobile' }, { site })).toBe(
      [
        '.cvb-root{--cvb-content-width:1140px;color:#123456}',
        '.cvb-el-head0001{font-size:48px;color:#123456}',
        '.cvb-el-head0001{font-size:40px}',
        '.cvb-el-head0001{font-size:30px}',
      ].join('\n'),
    );
    // Without the globals every reference is unresolved and skipped.
    expect(generateCss(doc, { kind: 'publish' })).toBe('.cvb-root{--cvb-content-width:1140px}\n@media (max-width:1024px){\n.cvb-el-head0001{font-size:40px}\n}');
  });
});
