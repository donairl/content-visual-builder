/**
 * Template library (README §11): built-in templates shipped in schema/templates, and inserting a
 * template's content into the page as fresh copies.
 */
import callToAction from '@schema/templates/call-to-action.json';
import features from '@schema/templates/features.json';
import hero from '@schema/templates/hero.json';
import landing from '@schema/templates/landing.json';
import { ancestry, insertFragment, reidentify } from '@/core/document';
import type { PageDocument } from '@/core/types';

export interface LibraryTemplate {
  /** `builtin:{slug}` or `saved:{id}`. */
  key: string;
  name: string;
  kind: 'page' | 'section';
  description?: string;
  /** Built-ins carry their document; saved ones are fetched when chosen. */
  document?: PageDocument;
  savedId?: number;
  authorName?: string;
}

const builtIn = { hero, features, 'call-to-action': callToAction, landing } as unknown as Record<
  string,
  { name: string; kind: 'page' | 'section'; description: string; document: PageDocument }
>;

export const BUILT_IN_TEMPLATES: LibraryTemplate[] = Object.entries(builtIn).map(([slug, t]) => ({
  key: `builtin:${slug}`,
  name: t.name,
  kind: t.kind,
  description: t.description,
  document: t.document,
}));

/**
 * `doc` with the template's top-level elements copied in (fresh ids, CSS ids dropped like any
 * copy) at `index` of the page's top level; null when they do not fit (node limit).
 */
export function withTemplate(doc: PageDocument, template: PageDocument, index: number): PageDocument | null {
  let next = doc;
  let at = index;
  for (const rootId of template.rootIds) {
    if (!template.nodes[rootId]) continue;
    const fragment = reidentify({ rootId, nodes: template.nodes }, (candidate) => candidate in next.nodes);
    // reidentify maps every node of the template; keep only this root's subtree.
    const subtree: typeof fragment.nodes = {};
    const visit = (id: string) => {
      const node = fragment.nodes[id];
      if (!node || subtree[id]) return;
      subtree[id] = node;
      node.children.forEach(visit);
    };
    visit(fragment.rootId);
    const inserted = insertFragment(next, { rootId: fragment.rootId, nodes: subtree }, null, at);
    if (inserted === next) return null;
    next = inserted;
    at++;
  }
  return next;
}

/** Where a section goes: after the top-level section holding the selection, else at the end. */
export function sectionIndex(doc: PageDocument, selectedId: string | null): number {
  const root = selectedId ? ancestry(doc, selectedId)[0] : undefined;
  const index = root ? doc.rootIds.indexOf(root) : -1;
  return index === -1 ? doc.rootIds.length : index + 1;
}
