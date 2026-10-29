import { describe, expect, it } from 'vitest';
import { createNode, emptyDocument, insertFragment } from '@/core/document';
import { validateDocument } from '@/core/validate';
import { insertionPoint, pasteFragment, structureFragment, widgetFragment } from './insert';

describe('insert helpers', () => {
  it('wraps top-level widgets in a container and reports the widget to select', () => {
    const doc = emptyDocument();
    const { fragment, widgetId } = widgetFragment(doc, 'heading', null);
    const root = fragment.nodes[fragment.rootId]!;
    expect(root.type).toBe('container');
    expect(root.children).toEqual([widgetId]);
    expect(validateDocument(insertFragment(doc, fragment, null, 0))).toEqual([]);
  });

  it('does not wrap inside containers or for containers', () => {
    expect(Object.keys(widgetFragment(emptyDocument(), 'heading', 'cont0001').fragment.nodes)).toHaveLength(1);
    expect(Object.keys(widgetFragment(emptyDocument(), 'container', null).fragment.nodes)).toHaveLength(1);
  });

  it.each([1, 2, 3, 4])('builds a valid %i-column structure', (columns) => {
    const doc = emptyDocument();
    const fragment = structureFragment(doc, columns);
    const next = insertFragment(doc, fragment, null, 0);
    expect(validateDocument(next)).toEqual([]);
    expect(next.nodes[fragment.rootId]!.children).toHaveLength(columns > 1 ? columns : 0);
  });

  it('chooses where a clicked widget goes', () => {
    let doc = emptyDocument();
    doc = insertFragment(doc, { rootId: 'cont0001', nodes: { cont0001: createNode('container', 'cont0001') } }, null, 0);
    doc = insertFragment(doc, { rootId: 'head0001', nodes: { head0001: createNode('heading', 'head0001') } }, 'cont0001', 0);
    expect(insertionPoint(doc, null)).toEqual({ parentId: null, index: 1 });
    expect(insertionPoint(doc, 'cont0001')).toEqual({ parentId: 'cont0001', index: 1 });
    expect(insertionPoint(doc, 'head0001')).toEqual({ parentId: 'cont0001', index: 1 });
  });

  it('pastes copies with fresh ids', () => {
    const doc = insertFragment(emptyDocument(), { rootId: 'head0001', nodes: { head0001: createNode('heading', 'head0001') } }, null, 0);
    const copy = pasteFragment(doc, { rootId: 'head0001', nodes: { head0001: createNode('heading', 'head0001') } });
    expect(copy.rootId).not.toBe('head0001');
    expect(validateDocument(insertFragment(doc, copy, null, 1))).toEqual([]);
  });
});
