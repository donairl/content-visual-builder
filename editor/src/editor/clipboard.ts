/**
 * Element clipboard, shared by keyboard shortcuts, the context menu and the toolbar. One copy
 * serves both "paste" (a new element) and "paste style" (onto the selected element), as in
 * Elementor.
 */
import { extractFragment } from '@/core/document';
import { widgets } from '@/core/schema';
import type { EditorStore } from './context';
import { insertionPoint, pasteFragment } from './insert';

export function copyElement(store: EditorStore, id: string): void {
  const fragment = extractFragment(store.getState().history.present, id);
  if (!fragment) return;
  store.dispatch({ type: 'copy', fragment });
  store.dispatch({ type: 'toast', kind: 'info', message: 'Element copied. Ctrl+V pastes it, Ctrl+Shift+V pastes its style.' });
}

/** Pastes after the selected element (or into the selected container). */
export function pasteElement(store: EditorStore): void {
  const state = store.getState();
  if (!state.clipboard) return;
  const doc = state.history.present;
  const at = insertionPoint(doc, state.selectedId);
  store.dispatch({ type: 'insert', fragment: pasteFragment(doc, state.clipboard), parentId: at.parentId, index: at.index });
}

export function pasteStyleInto(store: EditorStore, id: string): void {
  const state = store.getState();
  if (!state.clipboard) return;
  const before = state.history.present;
  store.dispatch({ type: 'pasteStyle', id });
  if (store.getState().history.present === before) {
    const source = state.clipboard.nodes[state.clipboard.rootId];
    const title = source ? (widgets[source.type]?.title ?? source.type) : 'copied element';
    store.dispatch({ type: 'toast', kind: 'info', message: `Nothing to paste: the ${title} has no style this element can use.` });
  }
}
