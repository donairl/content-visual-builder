import { describe, expect, it } from 'vitest';
import { createNode, emptyDocument, insertFragment, moveNode, setStyle } from './document';
import { resolveDrop, resolveTreeDrop, type DropQuery, type Rect } from './dnd';
import type { Fragment, PageDocument } from './types';

const frag = (type: string, id: string): Fragment => ({ rootId: id, nodes: { [id]: createNode(type, id) } });

/**
 * Layout (viewport px):
 *   root area            0..1000 x, 0..1000 y
 *   cont0001 (column)    0..1000 x, 0..400 y   inner 10..990 x, 10..390 y
 *     head0001           10..990 x, 10..60 y
 *     head0002           10..990 x, 80..130 y
 *   cont0002 (row)       0..1000 x, 400..600 y inner 10..990 x, 410..590 y
 *     butn0001           10..110 x, 410..460 y
 *     butn0002           130..230 x, 410..460 y
 *   cont0003 (empty)     0..1000 x, 600..700 y inner 10..990 x, 610..690 y
 */
function setup(): { doc: PageDocument; rects: Record<string, Rect>; inner: Record<string, Rect> } {
  let doc = emptyDocument();
  doc = insertFragment(doc, frag('container', 'cont0001'), null, 0);
  doc = insertFragment(doc, frag('container', 'cont0002'), null, 1);
  doc = insertFragment(doc, frag('container', 'cont0003'), null, 2);
  doc = insertFragment(doc, frag('heading', 'head0001'), 'cont0001', 0);
  doc = insertFragment(doc, frag('heading', 'head0002'), 'cont0001', 1);
  doc = insertFragment(doc, frag('button', 'butn0001'), 'cont0002', 0);
  doc = insertFragment(doc, frag('button', 'butn0002'), 'cont0002', 1);
  doc = setStyle(doc, 'cont0002', 'flexDirection', 'desktop', 'row');
  const r = (left: number, top: number, right: number, bottom: number): Rect => ({ left, top, width: right - left, height: bottom - top });
  return {
    doc,
    rects: {
      cont0001: r(0, 0, 1000, 400),
      head0001: r(10, 10, 990, 60),
      head0002: r(10, 80, 990, 130),
      cont0002: r(0, 400, 1000, 600),
      butn0001: r(10, 410, 110, 460),
      butn0002: r(130, 410, 230, 460),
      cont0003: r(0, 600, 1000, 700),
    },
    inner: {
      root: r(0, 0, 1000, 1000),
      cont0001: r(10, 10, 990, 390),
      cont0002: r(10, 410, 990, 590),
      cont0003: r(10, 610, 990, 690),
    },
  };
}

function drop(overId: string | null, x: number, y: number, movingId: string | null = null, mutate?: (d: PageDocument) => PageDocument) {
  const { doc, rects, inner } = setup();
  const query: DropQuery = {
    doc: mutate ? mutate(doc) : doc,
    device: 'desktop',
    overId,
    pointer: { x, y },
    movingId,
    rectOf: (id) => rects[id] ?? null,
    innerRectOf: (id) => inner[id ?? 'root'] ?? null,
  };
  return resolveDrop(query);
}

describe('resolveDrop', () => {
  it('drops before/after a leaf by its midpoint in a column', () => {
    expect(drop('head0002', 500, 90)).toMatchObject({ parentId: 'cont0001', index: 1, indicator: { kind: 'line', axis: 'vertical' } });
    expect(drop('head0002', 500, 125)).toMatchObject({ parentId: 'cont0001', index: 2 });
  });

  it('uses the horizontal axis inside a row', () => {
    expect(drop('butn0002', 140, 430)).toMatchObject({ parentId: 'cont0002', index: 1, indicator: { axis: 'horizontal' } });
    expect(drop('butn0002', 220, 430)).toMatchObject({ parentId: 'cont0002', index: 2 });
  });

  it('flips DOM order for reversed rows', () => {
    const target = drop('butn0002', 140, 430, null, (d) => setStyle(d, 'cont0002', 'flexDirection', 'desktop', 'row-reverse'));
    expect(target).toMatchObject({ parentId: 'cont0002', index: 2 });
  });

  it('drops into the middle of a container, near the closest child', () => {
    expect(drop('cont0001', 500, 370)).toMatchObject({ parentId: 'cont0001', index: 2 });
    expect(drop('cont0002', 600, 500)).toMatchObject({ parentId: 'cont0002', index: 2 });
  });

  it('treats the edge band of a container as beside it', () => {
    expect(drop('cont0002', 500, 405)).toMatchObject({ parentId: null, index: 1 });
    expect(drop('cont0002', 500, 595)).toMatchObject({ parentId: null, index: 2 });
  });

  it('highlights an empty container as a box', () => {
    expect(drop('cont0003', 500, 650)).toMatchObject({ parentId: 'cont0003', index: 0, indicator: { kind: 'box' } });
  });

  it('falls back to the root area', () => {
    expect(drop(null, 500, 900)).toMatchObject({ parentId: null, index: 3 });
  });

  it('never targets the dragged subtree', () => {
    const target = drop('head0001', 500, 20, 'cont0001');
    expect(target?.parentId).toBeNull();
  });

  it('can drop a moved element into an otherwise empty container', () => {
    const target = drop('cont0003', 500, 650, 'head0001');
    expect(target).toMatchObject({ parentId: 'cont0003', index: 0 });
  });
});

describe('resolveTreeDrop', () => {
  /** [c1 [h1, c2 [h2]], h3] */
  function tree() {
    let doc = emptyDocument();
    const add = (type: string, id: string, parent: string | null, index: number) => {
      doc = insertFragment(doc, { rootId: id, nodes: { [id]: createNode(type, id) } }, parent, index);
    };
    add('container', 'cont0001', null, 0);
    add('heading', 'head0003', null, 1);
    add('heading', 'head0001', 'cont0001', 0);
    add('container', 'cont0002', 'cont0001', 1);
    add('heading', 'head0002', 'cont0002', 0);
    return doc;
  }

  it('drops before/after a widget by halves', () => {
    const doc = tree();
    expect(resolveTreeDrop(doc, 'head0003', 'head0001', 0.2)).toEqual({ parentId: 'cont0001', index: 0, position: 'before' });
    expect(resolveTreeDrop(doc, 'head0003', 'head0001', 0.6)).toEqual({ parentId: 'cont0001', index: 1, position: 'after' });
  });

  it('drops into a container through its middle, beside it through its edges', () => {
    const doc = tree();
    expect(resolveTreeDrop(doc, 'head0003', 'cont0002', 0.5)).toEqual({ parentId: 'cont0002', index: 1, position: 'inside' });
    expect(resolveTreeDrop(doc, 'head0003', 'cont0002', 0.1)).toEqual({ parentId: 'cont0001', index: 1, position: 'before' });
    expect(resolveTreeDrop(doc, 'head0003', 'cont0001', 0.9)).toEqual({ parentId: null, index: 1, position: 'after' });
  });

  it('refuses drops onto itself or into its own subtree', () => {
    const doc = tree();
    expect(resolveTreeDrop(doc, 'cont0001', 'cont0001', 0.5)).toBeNull();
    expect(resolveTreeDrop(doc, 'cont0001', 'head0002', 0.5)).toBeNull();
    expect(resolveTreeDrop(doc, 'cont0001', 'missing0', 0.5)).toBeNull();
  });

  it('produces targets moveNode applies', () => {
    const doc = tree();
    const drop = resolveTreeDrop(doc, 'head0001', 'head0003', 0.9)!;
    const moved = moveNode(doc, 'head0001', drop.parentId, drop.index);
    expect(moved.rootIds).toEqual(['cont0001', 'head0003', 'head0001']);
  });
});
