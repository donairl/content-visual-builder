/**
 * Undo/redo history over immutable snapshots. Consecutive edits that share a merge key within
 * MERGE_WINDOW_MS (typing in a field, dragging a slider) collapse into one undo step.
 */
export const MERGE_WINDOW_MS = 1000;
export const HISTORY_LIMIT = 100;

export interface History<T> {
  past: T[];
  present: T;
  future: T[];
  mergeKey: string | null;
  lastChange: number;
}

export function createHistory<T>(present: T): History<T> {
  return { past: [], present, future: [], mergeKey: null, lastChange: 0 };
}

export interface CommitOptions {
  mergeKey?: string | null;
  now?: number;
  limit?: number;
}

export function commit<T>(history: History<T>, next: T, options: CommitOptions = {}): History<T> {
  if (Object.is(next, history.present)) return history;
  const now = options.now ?? Date.now();
  const mergeKey = options.mergeKey ?? null;
  const merges =
    mergeKey !== null && mergeKey === history.mergeKey && now - history.lastChange < MERGE_WINDOW_MS && history.future.length === 0;
  if (merges) return { ...history, present: next, lastChange: now };
  const limit = options.limit ?? HISTORY_LIMIT;
  return {
    past: [...history.past, history.present].slice(-limit),
    present: next,
    future: [],
    mergeKey,
    lastChange: now,
  };
}

export function undo<T>(history: History<T>): History<T> {
  const previous = history.past[history.past.length - 1];
  if (history.past.length === 0) return history;
  return {
    past: history.past.slice(0, -1),
    present: previous as T,
    future: [history.present, ...history.future],
    mergeKey: null,
    lastChange: 0,
  };
}

export function redo<T>(history: History<T>): History<T> {
  if (history.future.length === 0) return history;
  const [next, ...rest] = history.future;
  return {
    past: [...history.past, history.present],
    present: next as T,
    future: rest,
    mergeKey: null,
    lastChange: 0,
  };
}

/** Ends the current merge run so the next edit starts a new undo step. */
export function seal<T>(history: History<T>): History<T> {
  return history.mergeKey === null ? history : { ...history, mergeKey: null };
}
