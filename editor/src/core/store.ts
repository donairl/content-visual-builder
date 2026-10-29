/**
 * Minimal external store (local state, no library). Components subscribe through selectors via
 * useSyncExternalStore, so an edit re-renders only the components whose selected slice changed —
 * plain Context would re-render every widget on every keystroke.
 */
import { useCallback, useRef, useSyncExternalStore } from 'react';

export interface Store<S, A> {
  getState(): S;
  dispatch(action: A): void;
  subscribe(listener: () => void): () => void;
}

export function createStore<S, A>(reducer: (state: S, action: A) => S, initial: S): Store<S, A> {
  let state = initial;
  const listeners = new Set<() => void>();
  return {
    getState: () => state,
    dispatch(action) {
      const next = reducer(state, action);
      if (Object.is(next, state)) return;
      state = next;
      for (const listener of [...listeners]) listener();
    },
    subscribe(listener) {
      listeners.add(listener);
      return () => listeners.delete(listener);
    },
  };
}

/**
 * Subscribes to a derived slice. The selection is cached per (state, selector) and compared with
 * `isEqual`, so selectors may build new arrays/objects without causing render loops.
 *
 * The selector is part of the cache key on purpose: React re-checks the snapshot with the
 * previous render's selector when the store changes, so a cache keyed on state alone would hand
 * a stale slice to the next render when the selector's inputs changed (e.g. a new selected id).
 */
export function useSelector<S, A, T>(
  store: Store<S, A>,
  selector: (state: S) => T,
  isEqual: (a: T, b: T) => boolean = Object.is,
): T {
  const cache = useRef<{ state: S; selector: (state: S) => T; value: T } | null>(null);
  const getSnapshot = useCallback(() => {
    const state = store.getState();
    const cached = cache.current;
    if (cached && Object.is(cached.state, state) && cached.selector === selector) return cached.value;
    const value = selector(state);
    const stable = cached && isEqual(cached.value, value) ? cached.value : value;
    cache.current = { state, selector, value: stable };
    return stable;
  }, [store, selector, isEqual]);
  return useSyncExternalStore(store.subscribe, getSnapshot, getSnapshot);
}

export function shallowEqualArray<T>(a: readonly T[], b: readonly T[]): boolean {
  return a.length === b.length && a.every((value, i) => Object.is(value, b[i]));
}
