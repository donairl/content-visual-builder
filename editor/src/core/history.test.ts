import { describe, expect, it } from 'vitest';
import { commit, createHistory, MERGE_WINDOW_MS, redo, seal, undo } from './history';
import { inheritedValue, ownValue, resolveBox, resolveValue } from './responsive';

describe('history', () => {
  it('undoes and redoes', () => {
    let h = createHistory('a');
    h = commit(h, 'b', { now: 1 });
    h = commit(h, 'c', { now: 5000 });
    h = undo(h);
    expect(h.present).toBe('b');
    h = undo(h);
    expect(h.present).toBe('a');
    expect(undo(h)).toBe(h);
    h = redo(redo(h));
    expect(h.present).toBe('c');
    expect(redo(h)).toBe(h);
  });

  it('ignores identical snapshots', () => {
    const h = createHistory('a');
    expect(commit(h, 'a')).toBe(h);
  });

  it('merges edits with the same key inside the window', () => {
    let h = createHistory('');
    h = commit(h, 'H', { mergeKey: 'text', now: 1000 });
    h = commit(h, 'He', { mergeKey: 'text', now: 1200 });
    h = commit(h, 'Hel', { mergeKey: 'text', now: 1400 });
    expect(h.past).toEqual(['']);
    expect(undo(h).present).toBe('');
  });

  it('starts a new step after the window, another key, or a seal', () => {
    let h = createHistory('');
    h = commit(h, 'a', { mergeKey: 'k', now: 0 });
    h = commit(h, 'b', { mergeKey: 'k', now: MERGE_WINDOW_MS + 1 });
    expect(h.past).toEqual(['', 'a']);
    h = commit(h, 'c', { mergeKey: 'other', now: MERGE_WINDOW_MS + 2 });
    expect(h.past).toEqual(['', 'a', 'b']);
    h = commit(seal(h), 'd', { mergeKey: 'other', now: MERGE_WINDOW_MS + 3 });
    expect(h.past).toEqual(['', 'a', 'b', 'c']);
  });

  it('drops redo after a new edit and caps the past', () => {
    let h = createHistory(0);
    for (let i = 1; i <= 5; i++) h = commit(h, i, { now: i * 10_000, limit: 3 });
    expect(h.past).toEqual([2, 3, 4]);
    h = commit(undo(h), 99, { now: 1e9 });
    expect(h.future).toEqual([]);
  });
});

describe('responsive resolution', () => {
  const rv = { desktop: 1, tablet: null, mobile: 3 };

  it('resolves own and inherited values', () => {
    expect(ownValue(rv, 'tablet')).toBeUndefined();
    expect(inheritedValue(rv, 'tablet')).toBe(1);
    expect(resolveValue(rv, 'tablet')).toBe(1);
    expect(resolveValue(rv, 'mobile')).toBe(3);
    expect(inheritedValue(rv, 'desktop')).toBeUndefined();
  });

  it('cascades box sides individually', () => {
    const box = {
      desktop: { top: 10, right: 10, bottom: 10, left: 10, unit: 'px' },
      mobile: { top: 4, unit: 'em' },
    };
    expect(resolveBox(box, 'tablet')).toEqual({ top: 10, right: 10, bottom: 10, left: 10, unit: 'px' });
    expect(resolveBox(box, 'mobile')).toEqual({ top: 4, right: 10, bottom: 10, left: 10, unit: 'em' });
  });
});
