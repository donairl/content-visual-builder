'use client';

import { createContext, useContext, useMemo, type ReactNode } from 'react';
import { siteContext, type SiteContext } from '@/core/globals';
import { createStore, useSelector, type Store } from '@/core/store';
import type { ElementNode, PageDocument } from '@/core/types';
import { editorReducer, initialState, type EditorAction, type EditorState } from './state';

export type EditorStore = Store<EditorState, EditorAction>;

const StoreContext = createContext<EditorStore | null>(null);

export function createEditorStore(state: EditorState = initialState()): EditorStore {
  return createStore(editorReducer, state);
}

export function EditorProvider({ store, children }: { store: EditorStore; children: ReactNode }) {
  return <StoreContext.Provider value={store}>{children}</StoreContext.Provider>;
}

export function useStore(): EditorStore {
  const store = useContext(StoreContext);
  if (!store) throw new Error('useStore must be used inside <EditorProvider>');
  return store;
}

export function useEditor<T>(selector: (state: EditorState) => T, isEqual?: (a: T, b: T) => boolean): T {
  return useSelector(useStore(), selector, isEqual);
}

export function useDispatch(): (action: EditorAction) => void {
  return useStore().dispatch;
}

const selectDoc = (s: EditorState) => s.history.present;
export const useDoc = (): PageDocument => useEditor(selectDoc);

const selectGlobals = (s: EditorState) => s.globals.value;
const selectComponents = (s: EditorState) => s.components;

/**
 * Render lookups for the global styles being edited and the loaded component documents
 * (rebuilt only when either changes).
 */
export function useSite(): SiteContext {
  const globals = useEditor(selectGlobals);
  const components = useEditor(selectComponents);
  return useMemo(() => {
    const documents = new Map<number, PageDocument>();
    for (const [id, entry] of Object.entries(components)) if (typeof entry === 'object') documents.set(Number(id), entry.document);
    return siteContext(globals, documents);
  }, [globals, components]);
}

/** Subscribes to one node; re-renders only when that node object changes. */
export function useNode(id: string | null): ElementNode | null {
  return useEditor((s) => (id ? (s.history.present.nodes[id] ?? null) : null));
}
