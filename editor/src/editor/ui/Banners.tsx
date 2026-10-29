'use client';

import { useEffect } from 'react';
import { loginUrl } from '../api';
import { useDispatch, useEditor, useStore } from '../context';
import { useServices } from '../services';
import { UiIcon } from './UiIcon';

/** Node id from a validation path like `nodes.ab12cd34.props.text`. */
const nodeIdOf = (path: string) => /^nodes\.([a-z0-9]{8})/.exec(path)?.[1] ?? null;

export function Banners({ restore }: { restore?: React.ReactNode }) {
  const save = useEditor((s) => s.save);
  const services = useServices();
  const dispatch = useDispatch();
  const store = useStore();

  return (
    <div className="ed-banners ed-ui">
      {restore}
      {save.status === 'conflict' ? (
        <div className="ed-banner ed-banner--error" role="alert">
          <UiIcon name="alert" />
          <p>
            <strong>This page was changed elsewhere</strong> (another tab or user saved version {save.conflict?.currentVersion}). Your changes are kept
            here until you choose.
          </p>
          <button type="button" className="ed-btn ed-btn--sm" onClick={() => services.download()}>
            Download mine
          </button>
          <button type="button" className="ed-btn ed-btn--sm" onClick={() => void services.loadServerVersion()}>
            Load theirs
          </button>
          <button type="button" className="ed-btn ed-btn--sm ed-btn--primary" onClick={() => void services.keepMine()}>
            Keep mine
          </button>
        </div>
      ) : null}
      {save.status === 'auth' ? (
        <div className="ed-banner ed-banner--error" role="alert">
          <UiIcon name="alert" />
          <p>Your session expired. Your changes are kept in this browser. Sign in in the new tab, then retry.</p>
          <a className="ed-btn ed-btn--sm" href={loginUrl()} target="_blank" rel="noopener">
            Sign in
          </a>
          <button
            type="button"
            className="ed-btn ed-btn--sm ed-btn--primary"
            onClick={() => {
              dispatch({ type: 'saveReset' });
              void services.saver.flush();
            }}
          >
            Retry
          </button>
        </div>
      ) : null}
      {save.status === 'invalid' && save.issues.length ? (
        <div className="ed-banner" role="alert">
          <UiIcon name="alert" />
          <div style={{ flex: 1 }}>
            <strong>{save.message ?? 'Some settings are invalid.'}</strong>
            <ul>
              {save.issues.slice(0, 6).map((issue, i) => {
                const id = nodeIdOf(issue.path);
                const node = id ? store.getState().history.present.nodes[id] : undefined;
                return (
                  <li key={i}>
                    {node ? (
                      <button type="button" className="ed-link" onClick={() => dispatch({ type: 'select', id: node.id })}>
                        {node.type} {issue.path.split('.').slice(2).join(' › ')}
                      </button>
                    ) : (
                      <code>{issue.path}</code>
                    )}{' '}
                    — {issue.message}
                  </li>
                );
              })}
              {save.issues.length > 6 ? <li>…and {save.issues.length - 6} more</li> : null}
            </ul>
          </div>
        </div>
      ) : null}
    </div>
  );
}

export function Toasts() {
  const toasts = useEditor((s) => s.toasts);
  const dispatch = useDispatch();
  useEffect(() => {
    const timers = toasts.map((t) => setTimeout(() => dispatch({ type: 'dismissToast', id: t.id }), 4500));
    return () => timers.forEach(clearTimeout);
  }, [toasts, dispatch]);
  if (!toasts.length) return null;
  return (
    <div className="ed-toasts ed-ui" role="status" aria-live="polite">
      {toasts.map((t) => (
        <div key={t.id} className={`ed-toast ed-toast--${t.kind}`}>
          {t.message}
          <button type="button" aria-label="Dismiss" onClick={() => dispatch({ type: 'dismissToast', id: t.id })}>
            <UiIcon name="x" size={14} />
          </button>
        </div>
      ))}
    </div>
  );
}
