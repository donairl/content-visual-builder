'use client';

/**
 * Autosave (FR-08/FR-12). One request in flight at a time; edits made during a save are picked
 * up by the next one. Failures never drop the local document: retryable errors back off and
 * retry, conflicts/validation/auth errors stop and wait for the user.
 */
import { useEffect } from 'react';
import { ApiError, api } from './api';
import { componentIssues } from './components';
import { clearBackup, writeBackup } from './backup';
import type { EditorStore } from './context';
import { globalsDirty, isDirty, type EditorState } from './state';

export const AUTOSAVE_DELAY_MS = 1500;
const BACKUP_THROTTLE_MS = 400;
const RETRY_DELAYS_MS = [2000, 4000, 8000, 16000, 30000];

export interface Saver {
  /** Saves immediately (if dirty, or always with `force`) and resolves once the revision is stored. */
  flush(force?: boolean): Promise<boolean>;
  dispose(): void;
}

export function createSaver(store: EditorStore, delay = AUTOSAVE_DELAY_MS): Saver {
  let timer: ReturnType<typeof setTimeout> | null = null;
  let backupTimer: ReturnType<typeof setTimeout> | null = null;
  let inflight: Promise<boolean> | null = null;
  let attempt = 0;
  let lastRevision = store.getState().revision;
  let disposed = false;

  const pageId = () => store.getState().page?.id ?? null;
  const kind = () => store.getState().page?.kind ?? 'page';

  const backupNow = () => {
    backupTimer = null;
    const state = store.getState();
    const id = pageId();
    if (id === null || !isDirty(state)) return;
    writeBackup({
      pageId: id,
      ...(kind() === 'component' ? { kind: 'component' as const } : {}),
      baseVersion: state.save.version,
      savedAt: Date.now(),
      document: state.history.present,
    });
  };

  const schedule = (ms: number) => {
    if (timer) clearTimeout(timer);
    timer = setTimeout(() => {
      timer = null;
      void save();
    }, ms);
  };

  // Conflicts and expired sessions need a decision from the user; an invalid document is retried
  // as soon as the user edits it again.
  const blocked = (state: EditorState) => state.save.status === 'conflict' || state.save.status === 'auth';

  async function save(force = false): Promise<boolean> {
    if (inflight) {
      await inflight;
      return isDirty(store.getState()) || force ? save(force) : true;
    }
    const state = store.getState();
    const id = pageId();
    if (id === null || (!isDirty(state) && !force)) return true;

    const revision = state.revision;
    const component = kind() === 'component';
    // README §10: a component is one top-level element without nested components. Saying so
    // here is clearer than the server's 422 for the same rule.
    const issues = component ? componentIssues(state.history.present) : [];
    if (issues.length) {
      store.dispatch({ type: 'saveFailed', status: 'invalid', message: issues[0]!.message, issues });
      return false;
    }
    store.dispatch({ type: 'saveStarted' });
    inflight = (async () => {
      try {
        const result = component
          ? await api.saveComponentDraft(id, state.history.present, state.save.version)
          : await api.saveDraft(id, state.history.present, state.save.version);
        attempt = 0;
        store.dispatch({ type: 'saveSucceeded', revision, version: result.version, at: Date.now() });
        if (!isDirty(store.getState())) clearBackup(id, component ? 'component' : 'page');
        return true;
      } catch (error) {
        handleFailure(error);
        return false;
      } finally {
        inflight = null;
      }
    })();
    const ok = await inflight;
    if (ok && isDirty(store.getState()) && !disposed) schedule(delay);
    return ok && !isDirty(store.getState());
  }

  function handleFailure(error: unknown) {
    const apiError = error instanceof ApiError ? error : new ApiError(0, String(error));
    if (apiError.status === 409) {
      store.dispatch({
        type: 'saveFailed',
        status: 'conflict',
        message: 'Someone else saved this page. Choose which version to keep.',
        conflict: {
          currentVersion: apiError.body.currentVersion ?? store.getState().save.version,
          updatedAt: apiError.body.updatedAt ?? '',
        },
      });
    } else if (apiError.status === 422 && apiError.body.errors) {
      store.dispatch({ type: 'saveFailed', status: 'invalid', message: 'The page has invalid settings.', issues: apiError.body.errors });
    } else if (apiError.status === 401) {
      store.dispatch({ type: 'saveFailed', status: 'auth', message: 'Your session expired. Sign in again to keep saving.' });
    } else if (apiError.retryable) {
      const wait = RETRY_DELAYS_MS[Math.min(attempt, RETRY_DELAYS_MS.length - 1)]!;
      attempt++;
      store.dispatch({ type: 'saveFailed', status: 'error', message: apiError.message, retryAt: Date.now() + wait });
      if (!disposed) schedule(wait);
    } else {
      store.dispatch({ type: 'saveFailed', status: 'error', message: apiError.message });
    }
  }

  const unsubscribe = store.subscribe(() => {
    const state = store.getState();
    if (state.revision === lastRevision) return;
    lastRevision = state.revision;
    if (!isDirty(state)) return;
    if (!backupTimer) backupTimer = setTimeout(backupNow, BACKUP_THROTTLE_MS);
    if (!blocked(state) && attempt === 0) schedule(delay);
  });

  return {
    async flush(force = false) {
      if (timer) clearTimeout(timer);
      timer = null;
      attempt = 0;
      if (backupTimer) {
        clearTimeout(backupTimer);
        backupNow();
      }
      return save(force);
    },
    dispose() {
      disposed = true;
      unsubscribe();
      if (timer) clearTimeout(timer);
      if (backupTimer) {
        clearTimeout(backupTimer);
        backupNow();
      }
    },
  };
}

/** Warns before leaving the page while edits are unsaved. */
export function useUnloadGuard(store: EditorStore) {
  useEffect(() => {
    const onBeforeUnload = (event: BeforeUnloadEvent) => {
      const state = store.getState();
      if (!isDirty(state) && !globalsDirty(state)) return;
      event.preventDefault();
    };
    window.addEventListener('beforeunload', onBeforeUnload);
    return () => window.removeEventListener('beforeunload', onBeforeUnload);
  }, [store]);
}
