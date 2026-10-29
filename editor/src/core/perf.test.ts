import { describe, expect, it } from 'vitest';
import { generateCss } from './css';
import { createNode, emptyDocument, insertFragment, setStyle } from './document';
import { validateDocument } from './validate';
import { commit, createHistory } from './history';

function bigDoc() {
  let doc = emptyDocument();
  let n = 0;
  const id = () => `n${String(n++).padStart(7, '0')}`;
  for (let s = 0; s < 100; s++) {
    const sec = id();
    doc = insertFragment(doc, { rootId: sec, nodes: { [sec]: createNode('container', sec) } }, null, s);
    for (let w = 0; w < 19; w++) {
      const wid = id();
      const type = ['heading', 'text', 'button', 'image', 'icon'][w % 5]!;
      doc = insertFragment(doc, { rootId: wid, nodes: { [wid]: createNode(type, wid) } }, sec, w);
      doc = setStyle(doc, wid, 'margin', 'desktop', { top: 4, bottom: 4, unit: 'px' });
      doc = setStyle(doc, wid, 'padding', 'mobile', { top: 2, unit: 'px' });
    }
  }
  return doc;
}

describe('performance at the element limit', () => {
  it('handles 2000 elements quickly', () => {
    const doc = bigDoc();
    expect(Object.keys(doc.nodes)).toHaveLength(2000);
    const time = (label: string, fn: () => unknown) => {
      const start = performance.now();
      fn();
      const ms = performance.now() - start;
      console.log(`${label}: ${ms.toFixed(1)}ms`);
      return ms;
    };
    expect(time('validateDocument', () => validateDocument(doc))).toBeLessThan(150);
    const cache = new WeakMap();
    time('generateCss cold', () => generateCss(doc, { kind: 'editor', device: 'desktop' }, { cache }));
    const edited = setStyle(doc, 'n0000005', 'color', 'desktop', '#ff0000');
    expect(time('generateCss after one edit (cached)', () => generateCss(edited, { kind: 'editor', device: 'desktop' }, { cache }))).toBeLessThan(30);
    let history = createHistory(doc);
    expect(time('100 history commits', () => {
      for (let i = 0; i < 100; i++) history = commit(history, setStyle(history.present, 'n0000005', 'opacity', 'desktop', i / 100), { now: i * 2000 });
    })).toBeLessThan(100);
  });
});
