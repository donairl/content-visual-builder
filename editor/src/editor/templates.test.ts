import { describe, expect, it } from 'vitest';
import { createNode, emptyDocument, insertFragment } from '@/core/document';
import { validateDocument } from '@/core/validate';
import { BUILT_IN_TEMPLATES, sectionIndex, withTemplate } from './templates';

const page = () => {
  let doc = emptyDocument();
  for (const [id, index] of [['cont0001', 0], ['cont0002', 1]] as const) {
    doc = insertFragment(doc, { rootId: id, nodes: { [id]: createNode('container', id) } }, null, index);
  }
  return insertFragment(doc, { rootId: 'head0001', nodes: { head0001: createNode('heading', 'head0001') } }, 'cont0001', 0);
};

describe('template library', () => {
  it('ships valid built-in templates; sections have one root', () => {
    expect(BUILT_IN_TEMPLATES.length).toBeGreaterThanOrEqual(4);
    for (const t of BUILT_IN_TEMPLATES) {
      expect(validateDocument(t.document)).toEqual([]);
      if (t.kind === 'section') expect(t.document!.rootIds).toHaveLength(1);
    }
  });

  it('inserts copies with fresh ids at the given top-level index', () => {
    const landing = BUILT_IN_TEMPLATES.find((t) => t.kind === 'page')!.document!;
    const doc = page();
    const next = withTemplate(doc, landing, 1)!;
    expect(next.rootIds).toHaveLength(2 + landing.rootIds.length);
    expect(next.rootIds[0]).toBe('cont0001');
    expect(next.rootIds.at(-1)).toBe('cont0002');
    expect(Object.keys(next.nodes)).toHaveLength(3 + Object.keys(landing.nodes).length);
    for (const id of Object.keys(landing.nodes)) expect(next.nodes[id]).toBeUndefined();
    expect(validateDocument(next)).toEqual([]);
    // Inserting twice still gives unique ids.
    expect(validateDocument(withTemplate(next, landing, 0)!)).toEqual([]);
  });

  it('puts sections after the top-level section holding the selection', () => {
    const doc = page();
    expect(sectionIndex(doc, 'head0001')).toBe(1);
    expect(sectionIndex(doc, 'cont0002')).toBe(2);
    expect(sectionIndex(doc, null)).toBe(2);
  });
});
