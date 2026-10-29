'use client';

import { useEffect } from 'react';
import type { Saver } from './autosave';
import { copyElement, pasteElement, pasteStyleInto } from './clipboard';
import type { EditorStore } from './context';

/** Keyboard shortcuts. Native editing keys keep working inside panel inputs. */
export function useShortcuts(store: EditorStore, saver: Saver) {
  useEffect(() => {
    const onKeyDown = (event: KeyboardEvent) => {
      const target = event.target as HTMLElement | null;
      const inField = !!target?.closest('input, textarea, select, .ed-rte-area');
      const inInline = !!target?.isContentEditable;
      const mod = event.metaKey || event.ctrlKey;
      const key = event.key.toLowerCase();
      const { dispatch } = store;

      if (mod && key === 's') {
        event.preventDefault();
        void saver.flush();
        return;
      }
      // Undo/redo go through editor history even while editing text inline, so the canvas and the
      // document never disagree; panel inputs keep their native undo.
      if (mod && key === 'z' && !inField) {
        event.preventDefault();
        dispatch({ type: event.shiftKey ? 'redo' : 'undo' });
        return;
      }
      if (mod && key === 'y' && !inField) {
        event.preventDefault();
        dispatch({ type: 'redo' });
        return;
      }
      if (inField || inInline) return;

      const state = store.getState();
      const selectedId = state.selectedId;
      if (key === 'escape') {
        dispatch({ type: 'select', id: null });
        return;
      }
      if (mod && key === 'v' && event.shiftKey && state.clipboard && selectedId) {
        event.preventDefault();
        pasteStyleInto(store, selectedId);
        return;
      }
      if (mod && key === 'v' && !event.shiftKey && state.clipboard) {
        event.preventDefault();
        pasteElement(store);
        return;
      }
      if (!selectedId) return;
      if (key === 'delete' || key === 'backspace') {
        event.preventDefault();
        dispatch({ type: 'remove', id: selectedId });
      } else if (mod && key === 'd') {
        event.preventDefault();
        dispatch({ type: 'duplicate', id: selectedId });
      } else if (mod && key === 'c' && (window.getSelection()?.isCollapsed ?? true)) {
        copyElement(store, selectedId);
      } else if (event.altKey && (key === 'arrowup' || key === 'arrowdown')) {
        event.preventDefault();
        dispatch({ type: 'nudge', id: selectedId, delta: key === 'arrowup' ? -1 : 1 });
      }
    };
    window.addEventListener('keydown', onKeyDown);
    return () => window.removeEventListener('keydown', onKeyDown);
  }, [store, saver]);
}
