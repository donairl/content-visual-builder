'use client';

import { useEffect, useState } from 'react';
import type { Device } from '@/core/types';
import { useDispatch, useEditor } from '../context';
import { useServices } from '../services';
import { globalsDirty, isDirty, ZOOM_LEVELS, type EditorState } from '../state';
import { NumberInput } from './controls/fields';
import { UiIcon } from './UiIcon';

const DEVICES: { id: Device; label: string }[] = [
  { id: 'desktop', label: 'Desktop' },
  { id: 'tablet', label: 'Tablet (768px)' },
  { id: 'mobile', label: 'Mobile (360px)' },
];

export function TopBar() {
  const dispatch = useDispatch();
  const services = useServices();
  const page = useEditor((s) => s.page);
  const canUndo = useEditor((s) => s.history.past.length > 0);
  const canRedo = useEditor((s) => s.history.future.length > 0);
  const device = useEditor((s) => s.device);
  const zoom = useEditor((s) => s.zoom);
  const canvasWidth = useEditor((s) => s.canvasWidth);
  const navigatorOpen = useEditor((s) => s.navigatorOpen);
  const leftTab = useEditor((s) => s.leftTab);
  const publishing = useEditor((s) => s.publishing);
  const dirty = useEditor(isDirty);
  const globalsUnsaved = useEditor(globalsDirty);
  const editingComponent = page?.kind === 'component';
  const canPublish = services.can('publish_pages');

  const pill = page?.status === 'published' ? (page.hasUnpublishedChanges || dirty ? ['changes', 'Published · changes'] : ['published', 'Published']) : ['', 'Draft'];

  return (
    <header className="ed-top ed-ui">
      <a className="ed-icon-btn" href="/editor/" title="All pages" aria-label="Back to all pages">
        <UiIcon name="arrow-left" size={16} />
      </a>
      <div className="ed-top-title">
        {editingComponent ? (
          <span className="ed-pill" title="Changes show everywhere this component is used once published">
            Component
          </span>
        ) : null}
        <strong title={page?.title}>{page?.title ?? 'Untitled'}</strong>
        <span className={`ed-pill${pill[0] ? ` ed-pill--${pill[0]}` : ''}`}>{pill[1]}</span>
      </div>
      <div className="ed-top-sep" />
      <div className="ed-top-group">
        <button type="button" className="ed-icon-btn" disabled={!canUndo} title="Undo (Ctrl+Z)" aria-label="Undo" onClick={() => dispatch({ type: 'undo' })}>
          <UiIcon name="undo" />
        </button>
        <button type="button" className="ed-icon-btn" disabled={!canRedo} title="Redo (Ctrl+Shift+Z)" aria-label="Redo" onClick={() => dispatch({ type: 'redo' })}>
          <UiIcon name="redo" />
        </button>
      </div>
      <div className="ed-top-sep" />
      <div className="ed-top-group" role="group" aria-label="Preview device">
        {DEVICES.map((d) => (
          <button
            key={d.id}
            type="button"
            className="ed-icon-btn"
            aria-pressed={device === d.id}
            title={d.label}
            aria-label={d.label}
            onClick={() => dispatch({ type: 'setDevice', device: d.id })}
          >
            <UiIcon name={d.id} />
          </button>
        ))}
      </div>
      <select
        className="ed-select ed-top-hide-sm"
        aria-label="Zoom"
        value={zoom}
        onChange={(e) => dispatch({ type: 'setZoom', zoom: Number(e.target.value) })}
      >
        {ZOOM_LEVELS.map((z) => (
          <option key={z} value={z}>
            {Math.round(z * 100)}%
          </option>
        ))}
      </select>
      <div className="ed-top-hide-sm" title="Canvas width in px (empty = device default)">
        <NumberInput ariaLabel="Canvas width" value={canvasWidth} placeholder="auto" min={240} max={3000} integer onChange={(w) => dispatch({ type: 'setCanvasWidth', width: w })} />
      </div>
      <div className="ed-top-spacer" />
      <button type="button" className="ed-icon-btn" aria-pressed={navigatorOpen} title="Navigator" aria-label="Navigator" onClick={() => dispatch({ type: 'toggleNavigator' })}>
        <UiIcon name="layers" />
      </button>
      {editingComponent ? null : (
        <button type="button" className="ed-icon-btn" title="Template library" aria-label="Template library" onClick={() => dispatch({ type: 'toggleTemplates', open: true })}>
          <UiIcon name="folder" />
        </button>
      )}
      {editingComponent ? null : (
        <button
          type="button"
          className="ed-icon-btn"
          aria-pressed={leftTab === 'history'}
          title="Revision history"
          aria-label="Revision history"
          onClick={() => dispatch({ type: 'setLeftTab', tab: leftTab === 'history' ? 'elements' : 'history' })}
        >
          <UiIcon name="clock" />
        </button>
      )}
      <button
        type="button"
        className="ed-icon-btn"
        aria-pressed={leftTab === 'globals'}
        title="Global styles"
        aria-label="Global styles"
        onClick={() => dispatch({ type: 'setLeftTab', tab: leftTab === 'globals' ? 'elements' : 'globals' })}
      >
        <UiIcon name="globe" />
        {globalsUnsaved ? <span className="ed-dot" aria-label="unsaved" /> : null}
      </button>
      {editingComponent ? null : (
        <>
          <button
            type="button"
            className="ed-icon-btn"
            aria-pressed={leftTab === 'page'}
            title="Page settings"
            aria-label="Page settings"
            onClick={() => dispatch({ type: 'setLeftTab', tab: leftTab === 'page' ? 'elements' : 'page' })}
          >
            <UiIcon name="page" />
          </button>
          <button type="button" className="ed-icon-btn" title="Preview the saved draft in a new tab" aria-label="Preview" onClick={() => void services.preview()}>
            <UiIcon name="eye" />
          </button>
        </>
      )}
      <div className="ed-top-sep" />
      <SaveStatus />
      <button type="button" className="ed-btn" disabled={!dirty} onClick={() => void services.saver.flush()}>
        Save draft
      </button>
      {canPublish ? (
        <button type="button" className="ed-btn ed-btn--primary" disabled={publishing} onClick={() => void services.publish()}>
          {publishing ? 'Publishing…' : page?.status === 'published' ? 'Update' : 'Publish'}
        </button>
      ) : null}
    </header>
  );
}

function useNow(active: boolean) {
  const [now, setNow] = useState(() => Date.now());
  useEffect(() => {
    if (!active) return;
    const timer = setInterval(() => setNow(Date.now()), 1000);
    return () => clearInterval(timer);
  }, [active]);
  return now;
}

const selectSave = (s: EditorState) => s.save;

function SaveStatus() {
  const save = useEditor(selectSave);
  const dirty = useEditor(isDirty);
  const services = useServices();
  const now = useNow(save.retryAt !== null);

  let tone = '';
  let content: React.ReactNode;
  switch (save.status) {
    case 'saving':
      content = 'Saving…';
      break;
    case 'error': {
      tone = 'error';
      const seconds = save.retryAt ? Math.max(0, Math.ceil((save.retryAt - now) / 1000)) : null;
      content = (
        <>
          <UiIcon name="alert" size={14} /> Save failed{seconds !== null ? ` · retry in ${seconds}s` : ''}{' '}
          <button type="button" onClick={() => void services.saver.flush()}>
            Retry now
          </button>
        </>
      );
      break;
    }
    case 'conflict':
      tone = 'error';
      content = (
        <>
          <UiIcon name="alert" size={14} /> Edit conflict
        </>
      );
      break;
    case 'invalid':
      tone = 'warn';
      content = (
        <>
          <UiIcon name="alert" size={14} /> {save.issues.length} issue{save.issues.length === 1 ? '' : 's'}
        </>
      );
      break;
    case 'auth':
      tone = 'error';
      content = 'Signed out';
      break;
    default:
      content = dirty ? (
        'Unsaved changes'
      ) : save.savedAt ? (
        <>
          <UiIcon name="check" size={14} /> Saved {new Date(save.savedAt).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })}
        </>
      ) : (
        <>
          <UiIcon name="cloud" size={14} /> All changes saved
        </>
      );
  }
  return (
    <span className={`ed-status${tone ? ` ed-status--${tone}` : ''}`} role="status" aria-live="polite">
      {content}
    </span>
  );
}
