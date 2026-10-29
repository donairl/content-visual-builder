'use client';

import { useDraggable } from '@dnd-kit/core';
import { memo, useCallback, useLayoutEffect, useMemo, useRef, useState, type RefObject } from 'react';
import { generateCss } from '@/core/css';
import { locate } from '@/core/document';
import { sanitizeHtml } from '@/core/sanitize';
import { config, widgets } from '@/core/schema';
import type { Device, ElementNode } from '@/core/types';
import { NodeContent, PublishedTree, type RenderContext } from '@/render/content';
import { useDispatch, useDoc, useEditor, useNode, useSite, useStore } from '../context';
import { structureFragment } from '../insert';
import type { EditorAction } from '../state';
import { contextMenuHandler } from './ContextMenu';
import type { DragData } from './DragLayer';
import { RichTextTools } from './controls/propControls';
import { handlePaste, preferParagraphs } from './richtext';
import { UiIcon } from './UiIcon';

/* ------------------------------------------------------------ inline editing */

/** What each inline-editable element currently displays, so re-renders never fight the caret. */
const shown = new WeakMap<HTMLElement, string>();

function syncContent(el: HTMLElement, kind: 'text' | 'html', value: string) {
  if (shown.get(el) === value) return;
  shown.set(el, value);
  if (kind === 'text') {
    if (el.textContent !== value) el.textContent = value;
  } else {
    // Stored HTML may come from another user: never inject it unsanitized.
    const clean = sanitizeHtml(value);
    if (el.innerHTML !== clean) el.innerHTML = clean;
  }
}

function inlineBinding(
  id: string,
  prop: string,
  kind: 'text' | 'html',
  value: string,
  editing: boolean,
  dispatch: (action: EditorAction) => void,
): Record<string, unknown> {
  return {
    ref: (el: HTMLElement | null) => {
      if (el) syncContent(el, kind, value);
    },
    contentEditable: editing ? (kind === 'text' ? 'plaintext-only' : 'true') : undefined,
    suppressContentEditableWarning: true,
    spellCheck: editing || undefined,
    'data-cvb-inline': prop,
    // While editing, pointer gestures select text instead of dragging the element or its parents.
    onPointerDown: editing ? (e: React.PointerEvent) => e.stopPropagation() : undefined,
    onFocus: kind === 'html' ? preferParagraphs : undefined,
    onInput: (e: React.FormEvent<HTMLElement>) => {
      const el = e.currentTarget;
      const next = kind === 'text' ? (el.textContent ?? '') : el.innerHTML;
      shown.set(el, next);
      dispatch({ type: 'setProp', id, key: prop, value: next, mergeKey: `inline:${id}:${prop}` });
    },
    onKeyDown: (e: React.KeyboardEvent<HTMLElement>) => {
      if (e.key === 'Escape' || (kind === 'text' && e.key === 'Enter')) {
        e.preventDefault();
        e.currentTarget.blur();
      }
    },
    onBlur: () => dispatch({ type: 'seal' }),
    onPaste: editing ? (e: React.ClipboardEvent) => handlePaste(e, kind) : undefined,
  };
}

/* ------------------------------------------------------------ node view */

export const NodeView = memo(function NodeView({ id }: { id: string }) {
  const node = useNode(id);
  const selected = useEditor((s) => s.selectedId === id);
  const dispatch = useDispatch();
  const store = useStore();
  const editing = selected && !!(node && widgets[node.type]?.inlineEditable);
  const data: DragData = { kind: 'move', id };
  const { setNodeRef, listeners, isDragging } = useDraggable({ id: `node:${id}`, data, disabled: editing });

  const ctx = useMemo<RenderContext>(
    () => ({
      mode: 'editor',
      renderChildren: (ids) => ids.map((childId) => <NodeView key={childId} id={childId} />),
      rootProps: () => ({
        ref: setNodeRef,
        'data-cvb-node': id,
        'data-cvb-selected': selected ? '' : undefined,
        'data-cvb-dragging': isDragging ? '' : undefined,
        onPointerDown: listeners?.onPointerDown,
        onClick: (e: React.MouseEvent) => {
          e.stopPropagation();
          if (!selected) dispatch({ type: 'select', id });
        },
        onContextMenu: contextMenuHandler(store, id),
      }),
      innerProps: () => ({ 'data-cvb-inner': id }),
      editable: (n, prop, kind, value) => inlineBinding(n.id, prop, kind, value, editing, dispatch),
      placeholder: (n, kind) => <Placeholder node={n} kind={kind} />,
      component: (_n, componentId) => <ComponentContent id={componentId} />,
    }),
    [id, selected, editing, isDragging, setNodeRef, listeners, dispatch, store],
  );

  if (!node) return null;
  return <NodeContent node={node} ctx={ctx} />;
});

/**
 * A component instance's content: the component's published document, rendered statically. It is
 * edited in its own editor ("Edit component"), so clicks select the instance.
 */
function ComponentContent({ id }: { id: number }) {
  const site = useSite();
  const doc = site.components.get(id);
  return doc ? <PublishedTree doc={doc} site={site} inComponent mode="editor" /> : null;
}

function Placeholder({ node, kind }: { node: ElementNode; kind: 'image' | 'video' | 'container' | 'component' }) {
  const dispatch = useDispatch();
  const component = useEditor((s) => (kind === 'component' ? s.components[Number(node.props.componentId)] : undefined));
  if (kind === 'component') {
    return (
      <span className="ed-ph">
        <UiIcon name="box" size={18} /> {component === 'loading' ? 'Loading component…' : 'This component no longer exists.'}
      </span>
    );
  }
  if (kind === 'image') {
    return (
      <span className="ed-ph">
        <UiIcon name="image" size={18} /> Choose an image in the panel
      </span>
    );
  }
  if (kind === 'video') {
    return (
      <span className="ed-ph ed-ph--video">
        <UiIcon name="video" size={18} /> Paste a YouTube, Vimeo or video file link
      </span>
    );
  }
  return (
    <div className="ed-ph" data-cvb-placeholder>
      <span>Drop elements here</span>
      <button
        type="button"
        onClick={(e) => {
          e.stopPropagation();
          dispatch({ type: 'select', id: node.id });
          dispatch({ type: 'setLeftTab', tab: 'elements' });
        }}
      >
        + Add element
      </button>
    </div>
  );
}

/* ------------------------------------------------------------ root add area */

function RootAddArea() {
  const store = useStore();
  const isEmpty = useEditor((s) => s.history.present.rootIds.length === 0);
  const add = (columns: number) => {
    const doc = store.getState().history.present;
    store.dispatch({ type: 'insert', fragment: structureFragment(doc, columns), parentId: null, index: doc.rootIds.length });
  };
  const editingComponent = useEditor((s) => s.page?.kind === 'component');
  return (
    <div className="ed-root-add" data-cvb-root-add onClick={(e) => e.stopPropagation()}>
      <p>{isEmpty ? 'Start by adding a section, or drag a widget from the panel.' : 'Add a new section'}</p>
      {editingComponent ? null : (
        <button type="button" className="ed-root-template" onClick={() => store.dispatch({ type: 'toggleTemplates', open: true })}>
          <UiIcon name="folder" size={14} /> Insert a template
        </button>
      )}
      <div className="ed-structures">
        {[1, 2, 3, 4].map((columns) => (
          <button key={columns} type="button" title={`${columns} column${columns > 1 ? 's' : ''}`} aria-label={`Add ${columns}-column section`} onClick={() => add(columns)}>
            {Array.from({ length: columns }, (_, i) => (
              <i key={i} style={{ width: `${(4 * 18) / columns}px` }} />
            ))}
          </button>
        ))}
      </div>
    </div>
  );
}

/* ------------------------------------------------------------ context toolbar */

function ContextToolbar({ stageRef }: { stageRef: RefObject<HTMLDivElement | null> }) {
  const selectedId = useEditor((s) => s.selectedId);
  const revision = useEditor((s) => s.revision);
  const device = useEditor((s) => s.device);
  const zoom = useEditor((s) => s.zoom);
  const canvasWidth = useEditor((s) => s.canvasWidth);
  const node = useNode(selectedId);
  const store = useStore();
  const dispatch = useDispatch();
  const [position, setPosition] = useState<{ top: number; left: number; inside: boolean } | null>(null);
  const data: DragData = { kind: 'move', id: selectedId ?? '' };
  const { setNodeRef, listeners, attributes } = useDraggable({ id: `handle:${selectedId ?? 'none'}`, data, disabled: !selectedId });

  const measure = useCallback(() => {
    const stage = stageRef.current;
    const el = selectedId ? stage?.querySelector<HTMLElement>(`[data-cvb-node="${selectedId}"]`) : null;
    if (!stage || !el) {
      setPosition(null);
      return;
    }
    const r = el.getBoundingClientRect();
    const s = stage.getBoundingClientRect();
    const top = r.top - s.top;
    setPosition({ top, left: r.left - s.left, inside: top < 28 });
  }, [selectedId, stageRef]);

  useLayoutEffect(() => {
    measure();
    const el = selectedId ? stageRef.current?.querySelector<HTMLElement>(`[data-cvb-node="${selectedId}"]`) : null;
    const observer = el ? new ResizeObserver(measure) : null;
    if (el) observer?.observe(el);
    window.addEventListener('resize', measure);
    return () => {
      observer?.disconnect();
      window.removeEventListener('resize', measure);
    };
  }, [measure, selectedId, revision, device, zoom, canvasWidth, stageRef]);

  if (!node || !position) return null;
  const manifest = widgets[node.type];
  const parentId = locate(store.getState().history.present, node.id)?.parentId ?? null;
  const editingRichText = node.type === 'text';

  return (
    <div
      className={`ed-toolbar ed-ui${position.inside ? ' ed-toolbar--inside' : ''}`}
      style={{ top: position.top, left: position.left }}
      onClick={(e) => e.stopPropagation()}
      role="toolbar"
      aria-label={`${manifest?.title ?? node.type} actions`}
    >
      <button type="button" ref={setNodeRef} data-handle title="Drag to move" aria-label="Drag to move" {...attributes} {...listeners}>
        <UiIcon name="move" size={14} />
      </button>
      <span>{manifest?.title ?? node.type}</span>
      {editingRichText ? <RichTextTools /> : null}
      <button type="button" title="Move up (Alt+↑)" aria-label="Move up" onClick={() => dispatch({ type: 'nudge', id: node.id, delta: -1 })}>
        <UiIcon name="arrow-up" size={14} />
      </button>
      <button type="button" title="Move down (Alt+↓)" aria-label="Move down" onClick={() => dispatch({ type: 'nudge', id: node.id, delta: 1 })}>
        <UiIcon name="arrow-down" size={14} />
      </button>
      {parentId ? (
        <button type="button" title="Select parent" aria-label="Select parent" onClick={() => dispatch({ type: 'select', id: parentId })}>
          <UiIcon name="parent" size={14} />
        </button>
      ) : null}
      <button type="button" title="Duplicate (Ctrl+D)" aria-label="Duplicate" onClick={() => dispatch({ type: 'duplicate', id: node.id })}>
        <UiIcon name="copy" size={14} />
      </button>
      <button type="button" title="Delete (Del)" aria-label="Delete" onClick={() => dispatch({ type: 'remove', id: node.id })}>
        <UiIcon name="trash" size={14} />
      </button>
      <button
        type="button"
        title="More actions"
        aria-label="More actions"
        aria-haspopup="menu"
        onClick={(e) => {
          const r = e.currentTarget.getBoundingClientRect();
          dispatch({ type: 'openMenu', id: node.id, x: r.left, y: r.bottom + 2 });
        }}
      >
        <UiIcon name="more" size={14} />
      </button>
    </div>
  );
}

/* ------------------------------------------------------------ canvas */

export function canvasWidthFor(device: Device, custom: number | null): number | null {
  return custom ?? config.previewWidths[device];
}

export function Canvas() {
  const doc = useDoc();
  const device = useEditor((s) => s.device);
  const zoom = useEditor((s) => s.zoom);
  const customWidth = useEditor((s) => s.canvasWidth);
  const dispatch = useDispatch();
  const stageRef = useRef<HTMLDivElement>(null);
  const hovered = useRef<HTMLElement | null>(null);
  const site = useSite();
  // Cached node rules depend on the global styles, so a new cache starts whenever they change.
  // eslint-disable-next-line react-hooks/exhaustive-deps
  const cache = useMemo(() => new WeakMap(), [site]);

  const css = useMemo(() => generateCss(doc, { kind: 'editor', device }, { cache, site }), [doc, device, cache, site]);
  const width = canvasWidthFor(device, customWidth);

  const setHover = (el: HTMLElement | null) => {
    if (hovered.current === el) return;
    hovered.current?.removeAttribute('data-cvb-hover');
    hovered.current = el;
    el?.setAttribute('data-cvb-hover', '');
  };

  return (
    <main className="ed-canvas" data-cvb-canvas aria-label="Page canvas" onClick={() => dispatch({ type: 'select', id: null })}>
      <div className="ed-stage" ref={stageRef}>
        <div className="ed-frame" style={{ width: width ? `${width}px` : '100%', zoom }}>
          <style>{css}</style>
          <div
            className="cvb-root ed-root"
            data-cvb-root
            // Links inside the page must not navigate away from the editor.
            onClickCapture={(e) => {
              if ((e.target as HTMLElement).closest('a')) e.preventDefault();
            }}
            onPointerOver={(e) => {
              if (document.body.dataset.cvbDragging) return;
              setHover((e.target as HTMLElement).closest<HTMLElement>('[data-cvb-node]'));
            }}
            onPointerLeave={() => setHover(null)}
          >
            {doc.rootIds.map((id) => (
              <NodeView key={id} id={id} />
            ))}
            <RootAddArea />
          </div>
        </div>
        <ContextToolbar stageRef={stageRef} />
      </div>
    </main>
  );
}
