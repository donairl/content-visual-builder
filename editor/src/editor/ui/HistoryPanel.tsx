'use client';

/**
 * Revision history (README §8 "Revisions"). Restoring loads a revision as a normal edit: it can be
 * undone with Ctrl+Z, and autosave stores it as the next draft version (itself a new revision).
 */
import { useCallback, useEffect, useState } from 'react';
import { validateDocument } from '@/core/validate';
import { api, type RevisionSummary } from '../api';
import { useDispatch, useEditor, useStore } from '../context';
import { UiIcon } from './UiIcon';

export function HistoryPanel() {
  const pageId = useEditor((s) => s.page?.id ?? 0);
  const version = useEditor((s) => s.save.version);
  const publishedVersion = useEditor((s) => s.page?.publishedVersion ?? null);
  const dispatch = useDispatch();
  const store = useStore();
  const [items, setItems] = useState<RevisionSummary[] | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [restoring, setRestoring] = useState<number | null>(null);

  const load = useCallback(async () => {
    try {
      setItems((await api.listRevisions(pageId)).items);
      setError(null);
    } catch (e) {
      setError(e instanceof Error ? e.message : 'The history could not be loaded.');
    }
  }, [pageId]);

  // Reload whenever a save or publish created or updated a revision.
  useEffect(() => {
    if (pageId) void load();
  }, [load, pageId, version, publishedVersion]);

  const restore = async (revision: RevisionSummary) => {
    setRestoring(revision.id);
    try {
      const { document } = await api.getRevision(pageId, revision.id);
      const issues = validateDocument(document);
      if (issues.length) {
        dispatch({ type: 'toast', kind: 'error', message: `This revision no longer passes validation (${issues[0]!.path}); it was not restored.` });
        return;
      }
      const before = store.getState().history.present;
      dispatch({ type: 'restore', doc: document });
      const changed = store.getState().history.present !== before;
      dispatch({
        type: 'toast',
        kind: 'info',
        message: changed ? `Restored version ${revision.version}. Press Ctrl+Z to undo.` : 'The page already matches that revision.',
      });
    } catch (e) {
      dispatch({ type: 'toast', kind: 'error', message: e instanceof Error ? e.message : 'The revision could not be loaded.' });
    } finally {
      setRestoring(null);
    }
  };

  return (
    <>
      <div className="ed-side-head">
        <UiIcon name="clock" size={16} />
        <h2>Revision history</h2>
        <button type="button" className="ed-icon-btn" title="Refresh" aria-label="Refresh history" onClick={() => void load()}>
          <UiIcon name="refresh" size={14} />
        </button>
        <button type="button" className="ed-icon-btn" aria-label="Close history" onClick={() => dispatch({ type: 'setLeftTab', tab: 'elements' })}>
          <UiIcon name="x" size={14} />
        </button>
      </div>
      <p className="ed-side-note">
        Saves within ten minutes by the same person are grouped; every publish is kept as its own revision. The newest 50 are kept. For
        recent changes in this session, use Undo.
      </p>
      {error ? (
        <p className="ed-field-error" role="alert" style={{ margin: 12 }}>
          {error}
        </p>
      ) : items === null ? (
        <p className="ed-empty-note">Loading…</p>
      ) : items.length === 0 ? (
        <p className="ed-empty-note">No revisions yet. They appear once the page is saved.</p>
      ) : (
        <ol className="ed-revisions">
          {items.map((item) => {
            const current = item.kind === 'draft' && item.version === version;
            return (
              <li key={item.id} data-current={current ? '' : undefined}>
                <div>
                  <strong>{new Date(item.updatedAt).toLocaleString([], { dateStyle: 'medium', timeStyle: 'short' })}</strong>
                  <span className={`ed-rev-kind ed-rev-kind--${item.kind}`}>{item.kind === 'published' ? 'Published' : 'Draft'}</span>
                  {current ? <span className="ed-rev-kind">Current</span> : null}
                </div>
                <small>
                  Version {item.version} · {item.authorName || 'Unknown author'}
                </small>
                <button
                  type="button"
                  className="ed-btn ed-btn--sm"
                  disabled={current || restoring !== null}
                  onClick={() => void restore(item)}
                >
                  {restoring === item.id ? 'Restoring…' : 'Restore'}
                </button>
              </li>
            );
          })}
        </ol>
      )}
    </>
  );
}
