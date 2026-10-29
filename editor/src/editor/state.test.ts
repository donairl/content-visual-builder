import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { createNode, emptyDocument } from '@/core/document';
import type { Fragment } from '@/core/types';
import { ApiError, api } from './api';
import { createSaver } from './autosave';
import { readBackup } from './backup';
import { createEditorStore } from './context';
import { editorReducer, globalsDirty, initialState, isDirty, type EditorState, type PageMeta } from './state';

const page: PageMeta = {
  kind: 'page',
  id: 7, title: 'Home', slug: 'home', status: 'draft', url: '/home',
  publishedVersion: null, hasUnpublishedChanges: false, publishedAt: null,
};
const frag = (type: string, id: string): Fragment => ({ rootId: id, nodes: { [id]: createNode(type, id) } });

function run(state: EditorState, ...actions: Parameters<typeof editorReducer>[1][]) {
  return actions.reduce(editorReducer, state);
}

describe('editorReducer', () => {
  it('inserts, selects the new element and opens its settings', () => {
    const s = run(initialState(), { type: 'insert', fragment: frag('heading', 'head0001'), parentId: null, index: 0 });
    expect(s.history.present.rootIds).toEqual(['head0001']);
    expect(s.selectedId).toBe('head0001');
    expect(s.leftTab).toBe('settings');
    expect(s.revision).toBe(1);
    expect(isDirty(s)).toBe(true);
  });

  it('undo/redo restores documents and drops a selection that no longer exists', () => {
    let s = run(initialState(), { type: 'insert', fragment: frag('heading', 'head0001'), parentId: null, index: 0 });
    s = run(s, { type: 'undo' });
    expect(s.history.present.rootIds).toEqual([]);
    expect(s.selectedId).toBeNull();
    expect(s.leftTab).toBe('elements');
    s = run(s, { type: 'redo' });
    expect(s.history.present.rootIds).toEqual(['head0001']);
    expect(s.revision).toBe(3);
  });

  it('merges typing into one undo step', () => {
    let s = run(initialState(), { type: 'insert', fragment: frag('heading', 'head0001'), parentId: null, index: 0 });
    for (const text of ['H', 'Hi', 'Hi!']) s = run(s, { type: 'setProp', id: 'head0001', key: 'text', value: text, mergeKey: 'p:head0001:text' });
    expect(s.history.present.nodes.head0001!.props.text).toBe('Hi!');
    s = run(s, { type: 'undo' });
    expect(s.history.present.nodes.head0001!.props.text).toBe('Add Your Heading Text Here');
  });

  it('ignores no-op edits without bumping the revision', () => {
    const s = run(initialState(), { type: 'insert', fragment: frag('heading', 'head0001'), parentId: null, index: 0 });
    expect(run(s, { type: 'setProp', id: 'head0001', key: 'tag', value: 'h2' })).toBe(s);
    expect(run(s, { type: 'move', id: 'head0001', parentId: null, index: 0 })).toBe(s);
  });

  it('keeps UI changes out of history', () => {
    const s = run(initialState(), { type: 'setDevice', device: 'mobile' }, { type: 'setZoom', zoom: 9 });
    expect(s.device).toBe('mobile');
    expect(s.zoom).toBe(1.5);
    expect(s.history.past).toEqual([]);
    expect(s.revision).toBe(0);
  });

  it('tracks the save cycle', () => {
    let s = run(initialState(emptyDocument(), page, 3), { type: 'insert', fragment: frag('spacer', 'spac0001'), parentId: null, index: 0 });
    s = run(s, { type: 'saveStarted' }, { type: 'saveSucceeded', revision: 1, version: 4, at: 1 });
    expect(isDirty(s)).toBe(false);
    expect(s.save.version).toBe(4);
    s = run(s, { type: 'saveFailed', status: 'conflict', message: 'x', conflict: { currentVersion: 9, updatedAt: '' } });
    s = run(s, { type: 'saveReset', version: 9 });
    expect(s.save).toMatchObject({ status: 'idle', version: 9, conflict: null });
  });
});

describe('autosave', () => {
  beforeEach(() => {
    vi.useFakeTimers();
    localStorage.clear();
  });
  afterEach(() => {
    vi.useRealTimers();
    vi.restoreAllMocks();
  });

  function setup() {
    const store = createEditorStore(initialState(emptyDocument(), page, 3));
    const saver = createSaver(store, 1000);
    const edit = (id: string) => store.dispatch({ type: 'insert', fragment: frag('spacer', id), parentId: null, index: 0 });
    return { store, saver, edit };
  }

  it('debounces edits into one save and clears the backup afterwards', async () => {
    const saveDraft = vi.spyOn(api, 'saveDraft').mockResolvedValue({ version: 4, updatedAt: 'now' });
    const { store, edit } = setup();
    edit('spac0001');
    edit('spac0002');
    await vi.advanceTimersByTimeAsync(500);
    expect(readBackup(7)?.document.rootIds).toEqual(['spac0002', 'spac0001']);
    await vi.advanceTimersByTimeAsync(1000);
    expect(saveDraft).toHaveBeenCalledTimes(1);
    expect(saveDraft.mock.calls[0]![2]).toBe(3);
    expect(isDirty(store.getState())).toBe(false);
    expect(store.getState().save.version).toBe(4);
    expect(readBackup(7)).toBeNull();
  });

  it('keeps changes and retries with backoff when the server fails', async () => {
    const saveDraft = vi
      .spyOn(api, 'saveDraft')
      .mockRejectedValueOnce(new ApiError(500, 'boom'))
      .mockResolvedValueOnce({ version: 4, updatedAt: 'now' });
    const { store, edit } = setup();
    edit('spac0001');
    await vi.advanceTimersByTimeAsync(1000);
    expect(store.getState().save.status).toBe('error');
    expect(isDirty(store.getState())).toBe(true);
    expect(readBackup(7)).not.toBeNull();
    await vi.advanceTimersByTimeAsync(2000);
    expect(saveDraft).toHaveBeenCalledTimes(2);
    expect(store.getState().save.status).toBe('idle');
    expect(isDirty(store.getState())).toBe(false);
  });

  it('stops on conflict until the user decides', async () => {
    const saveDraft = vi.spyOn(api, 'saveDraft').mockRejectedValue(new ApiError(409, 'conflict', { currentVersion: 8, updatedAt: 'x' }));
    const { store, edit } = setup();
    edit('spac0001');
    await vi.advanceTimersByTimeAsync(1000);
    expect(store.getState().save).toMatchObject({ status: 'conflict', conflict: { currentVersion: 8 } });
    edit('spac0002');
    await vi.advanceTimersByTimeAsync(10_000);
    expect(saveDraft).toHaveBeenCalledTimes(1);
  });

  it('retries an invalid document after the next edit', async () => {
    const saveDraft = vi
      .spyOn(api, 'saveDraft')
      .mockRejectedValueOnce(new ApiError(422, 'invalid', { errors: [{ path: 'nodes.spac0001', message: 'x' }] }))
      .mockResolvedValueOnce({ version: 4, updatedAt: 'now' });
    const { store, edit } = setup();
    edit('spac0001');
    await vi.advanceTimersByTimeAsync(1000);
    expect(store.getState().save.status).toBe('invalid');
    expect(store.getState().save.issues).toHaveLength(1);
    await vi.advanceTimersByTimeAsync(10_000);
    expect(saveDraft).toHaveBeenCalledTimes(1);
    edit('spac0002');
    await vi.advanceTimersByTimeAsync(1000);
    expect(saveDraft).toHaveBeenCalledTimes(2);
    expect(store.getState().save.status).toBe('idle');
  });

  it('flush saves immediately', async () => {
    vi.spyOn(api, 'saveDraft').mockResolvedValue({ version: 4, updatedAt: 'now' });
    const { store, saver, edit } = setup();
    edit('spac0001');
    await expect(saver.flush()).resolves.toBe(true);
    expect(isDirty(store.getState())).toBe(false);
  });
});

describe('style clipboard and context menu', () => {
  const base = () =>
    run(
      initialState(),
      { type: 'insert', fragment: frag('heading', 'head0001'), parentId: null, index: 0 },
      { type: 'insert', fragment: frag('heading', 'head0002'), parentId: null, index: 1 },
      { type: 'setStyle', id: 'head0001', key: 'color', device: 'desktop', value: '#123456' },
    );

  it('pastes the copied element style as one undoable step', () => {
    const copied = run(base(), { type: 'copy', fragment: { rootId: 'head0001', nodes: { head0001: base().history.present.nodes.head0001! } } });
    const pasted = run(copied, { type: 'pasteStyle', id: 'head0002' });
    expect(pasted.history.present.nodes.head0002!.style).toEqual({ color: { desktop: '#123456' } });
    expect(run(pasted, { type: 'undo' }).history.present.nodes.head0002!.style).toEqual({});
  });

  it('ignores paste style without a clipboard', () => {
    const s = base();
    expect(run(s, { type: 'pasteStyle', id: 'head0002' })).toBe(s);
  });

  it('resets style', () => {
    const s = run(base(), { type: 'resetStyle', id: 'head0001' });
    expect(s.history.present.nodes.head0001!.style).toEqual({});
  });

  it('opens the menu only for existing elements and closes it', () => {
    const s = base();
    expect(run(s, { type: 'openMenu', id: 'nope0000', x: 1, y: 2 })).toBe(s);
    const open = run(s, { type: 'openMenu', id: 'head0001', x: 1, y: 2 });
    expect(open.menu).toEqual({ id: 'head0001', x: 1, y: 2 });
    expect(run(open, { type: 'closeMenu' }).menu).toBeNull();
  });
});

describe('global styles state', () => {
  it('edits live, tracks unsaved changes and survives loading a page', () => {
    let s = run(initialState(), { type: 'loadGlobals', globals: { colors: [], fonts: [] }, version: 3 });
    expect(globalsDirty(s)).toBe(false);
    s = run(s, { type: 'editGlobals', globals: { colors: [{ id: 'a', name: 'A', value: '#000' }], fonts: [] } });
    expect(globalsDirty(s)).toBe(true);
    // Not part of the page: no revision bump, no undo step.
    expect(s.revision).toBe(0);
    expect(s.history.past).toHaveLength(0);
    s = run(s, { type: 'load', doc: emptyDocument(), page, version: 1 });
    expect(s.globals.value.colors).toHaveLength(1);
    s = run(s, { type: 'globalsSaved', version: 4 });
    expect(globalsDirty(s)).toBe(false);
    expect(s.globals.version).toBe(4);
  });
});
