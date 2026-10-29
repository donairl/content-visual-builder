'use client';

/**
 * Reusable components in the page editor (README §10): loading the published documents that
 * instances render, turning an element into a component, and detaching an instance back into
 * plain elements.
 */
import { useEffect } from 'react';
import { emptyDocument, reidentify } from '@/core/document';
import type { ElementNode, Fragment, PageDocument } from '@/core/types';
import { validateDocument } from '@/core/validate';
import { api } from './api';
import type { EditorStore } from './context';

/** Component ids referenced by instances in `doc`. */
export function componentIds(doc: PageDocument): number[] {
  const ids = new Set<number>();
  for (const node of Object.values(doc.nodes)) {
    const id = node.type === 'component' ? node.props.componentId : undefined;
    if (typeof id === 'number' && Number.isInteger(id) && id > 0) ids.add(id);
  }
  return [...ids];
}

export async function loadComponent(store: EditorStore, id: number, force = false): Promise<void> {
  const current = store.getState().components[id];
  if (!force && current !== undefined) return;
  store.dispatch({ type: 'componentLoading', id });
  try {
    const component = await api.getComponent(id);
    store.dispatch({ type: 'componentLoaded', id, name: component.name, document: component.publishedDocument });
  } catch {
    // Deleted (404) or unreachable: the instance shows a placeholder instead of content.
    store.dispatch({ type: 'componentMissing', id });
  }
}

/** Keeps the published documents of every component the page uses loaded. */
export function useComponentLoader(store: EditorStore) {
  useEffect(() => {
    let last: PageDocument | null = null;
    const sync = () => {
      const state = store.getState();
      if (state.history.present === last) return;
      last = state.history.present;
      for (const id of componentIds(last)) if (state.components[id] === undefined) void loadComponent(store, id);
    };
    sync();
    return store.subscribe(sync);
  }, [store]);
}

/** A one-root document holding the subtree of `id`, as a component is stored. */
export function componentDocument(doc: PageDocument, fragment: Fragment): PageDocument {
  return { ...emptyDocument(), settings: doc.settings, rootIds: [fragment.rootId], nodes: fragment.nodes };
}

/** Problems that keep a document from being a component, at the paths the server reports. */
export function componentIssues(doc: PageDocument): { path: string; message: string }[] {
  const issues = validateDocument(doc);
  if (issues.length) return issues;
  const out: { path: string; message: string }[] = [];
  if (doc.rootIds.length !== 1) out.push({ path: 'rootIds', message: 'A component must have exactly one top-level element. Put everything in one container.' });
  for (const node of Object.values(doc.nodes)) {
    if (node.type === 'component') out.push({ path: `nodes.${node.id}.type`, message: 'Components cannot contain other components.' });
  }
  return out;
}

/** The component's content as fresh page elements, for "Detach". */
export function detachedFragment(component: PageDocument, taken: (id: string) => boolean): Fragment | null {
  const rootId = component.rootIds[0];
  if (!rootId || !component.nodes[rootId]) return null;
  const nodes: Record<string, ElementNode> = {};
  for (const [id, node] of Object.entries(component.nodes)) if (node.type !== 'component') nodes[id] = node;
  return reidentify({ rootId, nodes }, taken);
}
