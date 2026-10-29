import { describe, expect, it } from 'vitest';
import {
  ancestry,
  createNode,
  duplicateNode,
  emptyDocument,
  extractFragment,
  generateId,
  insertFragment,
  locate,
  moveNode,
  nudge,
  pasteStyle,
  propOf,
  reidentify,
  removeNode,
  resetStyle,
  setAdvanced,
  setProp,
  setStyle,
} from './document';
import { patterns } from './schema';
import type { Fragment, PageDocument } from './types';
import { validateDocument } from './validate';

const frag = (type: string, id: string): Fragment => ({ rootId: id, nodes: { [id]: createNode(type, id) } });

/** root: [c1 [h1, h2], c2 []] */
function sample(): PageDocument {
  let doc = emptyDocument();
  doc = insertFragment(doc, frag('container', 'cont0001'), null, 0);
  doc = insertFragment(doc, frag('container', 'cont0002'), null, 1);
  doc = insertFragment(doc, frag('heading', 'head0001'), 'cont0001', 0);
  doc = insertFragment(doc, frag('heading', 'head0002'), 'cont0001', 1);
  return doc;
}

describe('ids and nodes', () => {
  it('generates ids matching the contract pattern', () => {
    for (let i = 0; i < 50; i++) expect(generateId()).toMatch(patterns.nodeId);
  });

  it('avoids taken ids', () => {
    let calls = 0;
    const id = generateId(() => ++calls < 3);
    expect(calls).toBe(3);
    expect(id).toMatch(patterns.nodeId);
  });

  it('creates nodes with every default and a valid document', () => {
    const node = createNode('spacer', 'spac0001');
    expect(node.style.height).toEqual({ desktop: { value: 50, unit: 'px' } });
    expect(sample().nodes.head0001!.props.text).toBe('Add Your Heading Text Here');
    expect(validateDocument(sample())).toEqual([]);
  });

  it('createNode copies defaults instead of sharing them', () => {
    const a = createNode('heading', 'aaaaaaaa');
    (a.props.link as { url: string }).url = 'https://x.example';
    expect((createNode('heading', 'bbbbbbbb').props.link as { url: string }).url).toBe('');
  });
});

describe('insertFragment', () => {
  it('refuses children on leaf widgets', () => {
    const doc = sample();
    expect(insertFragment(doc, frag('heading', 'head0003'), 'head0001', 0)).toBe(doc);
  });

  it('refuses duplicate ids', () => {
    const doc = sample();
    expect(insertFragment(doc, frag('heading', 'head0001'), null, 0)).toBe(doc);
  });

  it('refuses nesting beyond the depth limit', () => {
    let doc = emptyDocument();
    let parent: string | null = null;
    for (let i = 0; i < 12; i++) {
      const id = `deep${String(i).padStart(4, '0')}`;
      doc = insertFragment(doc, frag('container', id), parent, 0);
      parent = id;
    }
    expect(Object.keys(doc.nodes)).toHaveLength(12);
    expect(insertFragment(doc, frag('heading', 'head0009'), parent, 0)).toBe(doc);
  });

  it('clamps the index', () => {
    const doc = insertFragment(sample(), frag('heading', 'head0003'), 'cont0001', 99);
    expect(doc.nodes.cont0001!.children).toEqual(['head0001', 'head0002', 'head0003']);
  });

  it('shares untouched nodes', () => {
    const before = sample();
    const after = insertFragment(before, frag('heading', 'head0003'), 'cont0002', 0);
    expect(after.nodes.head0001).toBe(before.nodes.head0001);
    expect(after.nodes.cont0002).not.toBe(before.nodes.cont0002);
  });
});

describe('moveNode', () => {
  it('reorders within a parent using pre-removal indices', () => {
    const doc = moveNode(sample(), 'head0001', 'cont0001', 2);
    expect(doc.nodes.cont0001!.children).toEqual(['head0002', 'head0001']);
  });

  it('is a no-op when dropped where it already is', () => {
    const doc = sample();
    expect(moveNode(doc, 'head0001', 'cont0001', 0)).toBe(doc);
    expect(moveNode(doc, 'head0001', 'cont0001', 1)).toBe(doc);
  });

  it('moves across parents', () => {
    const doc = moveNode(sample(), 'head0002', 'cont0002', 0);
    expect(doc.nodes.cont0001!.children).toEqual(['head0001']);
    expect(doc.nodes.cont0002!.children).toEqual(['head0002']);
    expect(validateDocument(doc)).toEqual([]);
  });

  it('moves to and from the root', () => {
    const doc = moveNode(sample(), 'head0001', null, 1);
    expect(doc.rootIds).toEqual(['cont0001', 'head0001', 'cont0002']);
  });

  it('refuses moving a container into itself or a descendant', () => {
    let doc = sample();
    doc = insertFragment(doc, frag('container', 'cont0003'), 'cont0001', 0);
    expect(moveNode(doc, 'cont0001', 'cont0001', 0)).toBe(doc);
    expect(moveNode(doc, 'cont0001', 'cont0003', 0)).toBe(doc);
  });

  it('nudges among siblings', () => {
    expect(nudge(sample(), 'head0001', 1).nodes.cont0001!.children).toEqual(['head0002', 'head0001']);
    expect(nudge(sample(), 'head0002', -1).nodes.cont0001!.children).toEqual(['head0002', 'head0001']);
    const doc = sample();
    expect(nudge(doc, 'head0001', -1)).toBe(doc);
  });
});

describe('remove, duplicate, fragments', () => {
  it('removes a subtree', () => {
    const doc = removeNode(sample(), 'cont0001');
    expect(doc.rootIds).toEqual(['cont0002']);
    expect(Object.keys(doc.nodes)).toEqual(['cont0002']);
    expect(validateDocument(doc)).toEqual([]);
  });

  it('duplicates a subtree right after the original with fresh ids and no css id', () => {
    let doc = sample();
    doc = { ...doc, nodes: { ...doc.nodes, cont0001: { ...doc.nodes.cont0001!, advanced: { cssId: 'hero', cssClasses: 'x', hideOn: [] } } } };
    const result = duplicateNode(doc, 'cont0001');
    expect(result.id).not.toBeNull();
    expect(result.doc.rootIds).toEqual(['cont0001', result.id, 'cont0002']);
    const copy = result.doc.nodes[result.id!]!;
    expect(copy.children).toHaveLength(2);
    expect(copy.children).not.toContain('head0001');
    expect(copy.advanced).toEqual({ cssId: '', cssClasses: 'x', hideOn: [] });
    expect(validateDocument(result.doc)).toEqual([]);
  });

  it('extracts deep copies', () => {
    const doc = sample();
    const fragment = extractFragment(doc, 'cont0001')!;
    expect(Object.keys(fragment.nodes).sort()).toEqual(['cont0001', 'head0001', 'head0002']);
    expect(fragment.nodes.head0001).not.toBe(doc.nodes.head0001);
    const fresh = reidentify(fragment, () => false);
    expect(Object.keys(fresh.nodes)).not.toContain('cont0001');
  });

  it('locates and lists ancestry', () => {
    const doc = sample();
    expect(locate(doc, 'head0002')).toEqual({ parentId: 'cont0001', index: 1 });
    expect(locate(doc, 'cont0002')).toEqual({ parentId: null, index: 1 });
    expect(ancestry(doc, 'head0002')).toEqual(['cont0001', 'head0002']);
  });
});

describe('props and styles', () => {
  it('sets props immutably and skips no-ops', () => {
    const doc = sample();
    expect(setProp(doc, 'head0001', 'text', 'Add Your Heading Text Here')).toBe(doc);
    const next = setProp(doc, 'head0001', 'text', 'Hi');
    expect(next.nodes.head0001!.props.text).toBe('Hi');
    expect(doc.nodes.head0001!.props.text).toBe('Add Your Heading Text Here');
  });

  it('sets and clears per-device styles', () => {
    let doc = setStyle(sample(), 'head0001', 'color', 'desktop', '#fff');
    doc = setStyle(doc, 'head0001', 'color', 'mobile', '#000');
    expect(doc.nodes.head0001!.style.color).toEqual({ desktop: '#fff', mobile: '#000' });
    doc = setStyle(doc, 'head0001', 'color', 'desktop', null);
    expect(doc.nodes.head0001!.style.color).toEqual({ mobile: '#000' });
    doc = setStyle(doc, 'head0001', 'color', 'mobile', null);
    expect(doc.nodes.head0001!.style).not.toHaveProperty('color');
  });

  it('reads props with manifest fallback', () => {
    const node = { ...createNode('heading', 'head0009'), props: {} };
    expect(propOf(node, 'tag')).toBe('h2');
  });
});

describe('paste style / reset style', () => {
  it('copies shared style keys and clears shared keys the source leaves unset', () => {
    let doc = sample();
    doc = setStyle(doc, 'head0001', 'color', 'desktop', '#ff0000');
    doc = setStyle(doc, 'head0001', 'margin', 'mobile', { top: 4, unit: 'px' });
    doc = setStyle(doc, 'head0002', 'textAlign', 'desktop', 'center');
    const next = pasteStyle(doc, 'head0002', doc.nodes.head0001!);
    expect(next.nodes.head0002!.style).toEqual({ color: { desktop: '#ff0000' }, margin: { mobile: { top: 4, unit: 'px' } } });
    // A deep copy: editing the target later must not touch the source.
    expect(next.nodes.head0002!.style.margin).not.toBe(doc.nodes.head0001!.style.margin);
    expect(validateDocument(next)).toEqual([]);
  });

  it('keeps target-only keys and drops source keys the target cannot carry', () => {
    let doc = sample();
    doc = setStyle(doc, 'head0001', 'color', 'desktop', '#ff0000');
    doc = setStyle(doc, 'head0001', 'padding', 'desktop', { top: 10, unit: 'px' });
    doc = setStyle(doc, 'cont0001', 'flexDirection', 'desktop', 'row');
    const next = pasteStyle(doc, 'cont0001', doc.nodes.head0001!);
    expect(next.nodes.cont0001!.style).toEqual({ flexDirection: { desktop: 'row' }, padding: { desktop: { top: 10, unit: 'px' } } });
    expect(validateDocument(next)).toEqual([]);
  });

  it('copies custom CSS along with the style', () => {
    let doc = setAdvanced(sample(), 'head0001', 'customCss', 'color: red');
    doc = pasteStyle(doc, 'head0002', doc.nodes.head0001!);
    expect(doc.nodes.head0002!.advanced.customCss).toBe('color: red');
    doc = setAdvanced(doc, 'head0001', 'customCss', '');
    expect(pasteStyle(doc, 'head0002', doc.nodes.head0001!).nodes.head0002!.advanced).not.toHaveProperty('customCss');
  });

  it('is a no-op when nothing changes', () => {
    const doc = sample();
    expect(pasteStyle(doc, 'head0002', doc.nodes.head0001!)).toBe(doc);
    expect(pasteStyle(doc, 'missing0', doc.nodes.head0001!)).toBe(doc);
  });

  it('reset restores the widget default styles', () => {
    let doc = insertFragment(sample(), frag('spacer', 'spac0001'), null, 0);
    doc = setStyle(doc, 'spac0001', 'height', 'desktop', { value: 99, unit: 'px' });
    doc = setStyle(doc, 'spac0001', 'margin', 'desktop', { top: 1, unit: 'px' });
    expect(resetStyle(doc, 'spac0001').nodes.spac0001!.style).toEqual(createNode('spacer', 'x').style);
    const clean = sample();
    expect(resetStyle(clean, 'head0001')).toBe(clean);
  });
});
