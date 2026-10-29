import { describe, expect, it } from 'vitest';
import { generateCss } from './css';
import { customCssError } from './customCss';
import { createNode, emptyDocument, insertFragment, setAdvanced, setSettings } from './document';

describe('customCssError', () => {
  it.each([
    'color: red',
    '',
    'a { b: c } /**/',
    'background: url()',
    'background:URL(/a.png)',
    "content: '\\\\'",
    '@supports (display: grid) { & { display: grid } }',
  ])('accepts %j', (css) => {
    expect(customCssError(css)).toBeNull();
  });

  it.each([
    ['}', /Unbalanced/],
    ['{', /not closed/],
    ['a { b: c ] }', /Unbalanced/],
    ['/* x', /comment/],
    ['"x', /string/],
    ["'x\\", /string/],
    ['a\\{', /Backslash/],
    ['url(a', /url\(/],
    ['url(a"b)', /url\(\)/],
    ['url(a b)', /spaces/],
    ['x<y', /</],
    ['\u0007', /Control/],
    ['\u007f', /Control/],
  ])('rejects %j', (css, message) => {
    expect(customCssError(css)).toMatch(message);
  });
});

describe('custom CSS output', () => {
  it('appends page and element CSS after the generated rules, in both modes', () => {
    let doc = insertFragment(emptyDocument(), { rootId: 'head0001', nodes: { head0001: createNode('heading', 'head0001') } }, null, 0);
    doc = setAdvanced(doc, 'head0001', 'customCss', 'color: red');
    doc = setSettings(doc, 'customCss', 'h2 { margin: 0 }');
    const expected = '.cvb-root{h2 { margin: 0 }}\n.cvb-el-head0001{color: red}';
    expect(generateCss(doc, { kind: 'publish' }).endsWith(expected)).toBe(true);
    expect(generateCss(doc, { kind: 'editor', device: 'mobile' }).endsWith(expected)).toBe(true);
  });

  it('drops invalid custom CSS instead of emitting it', () => {
    let doc = insertFragment(emptyDocument(), { rootId: 'head0001', nodes: { head0001: createNode('heading', 'head0001') } }, null, 0);
    doc = setAdvanced(doc, 'head0001', 'customCss', '} body { display: none');
    doc = setSettings(doc, 'customCss', '</style>');
    expect(generateCss(doc, { kind: 'publish' })).not.toContain('body');
    expect(generateCss(doc, { kind: 'publish' })).not.toContain('style');
  });
});
