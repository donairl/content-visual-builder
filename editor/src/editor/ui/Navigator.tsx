'use client';

/**
 * Navigator: the page as a tree. Rows select, open the context menu, and can be dragged to
 * reorder or re-nest elements (before/after a row, or inside a container). The drop rules live in
 * core/dnd.ts (resolveTreeDrop); moves go through the same `move` action as canvas drags.
 */
import { DndContext, DragOverlay, PointerSensor, useDraggable, useSensor, useSensors, type DragMoveEvent, type DragStartEvent } from '@dnd-kit/core';
import { useEffect, useRef, useState } from 'react';
import { resolveTreeDrop, type TreeDrop } from '@/core/dnd';
import { moveNode, propOf } from '@/core/document';
import { widgets } from '@/core/schema';
import type { ElementNode } from '@/core/types';
import { useDispatch, useDoc, useEditor, useStore } from '../context';
import { contextMenuHandler } from './ContextMenu';
import { UiIcon } from './UiIcon';

/** Short description of a node for the tree: its CSS id, or a snippet of its text. */
function describe(node: ElementNode): string {
  if (node.advanced.cssId) return `#${node.advanced.cssId}`;
  for (const key of ['text', 'label', 'alt', 'title']) {
    const value = propOf<unknown>(node, key);
    if (typeof value === 'string' && value.trim()) return value.trim().slice(0, 40);
  }
  return '';
}

function highlight(id: string, on: boolean) {
  document.querySelector(`[data-cvb-node="${id}"]`)?.toggleAttribute('data-cvb-hover', on);
}

/** Hovering "inside" a collapsed container this long expands it. */
const EXPAND_DELAY_MS = 600;

export function Navigator() {
  const open = useEditor((s) => s.navigatorOpen);
  const doc = useDoc();
  const store = useStore();
  const dispatch = useDispatch();
  const [collapsed, setCollapsed] = useState<Set<string>>(new Set());
  const [dragging, setDragging] = useState<string | null>(null);
  const [target, setTarget] = useState<(TreeDrop & { overId: string }) | null>(null);
  const targetRef = useRef<(TreeDrop & { overId: string }) | null>(null);
  const origin = useRef({ x: 0, y: 0 });
  const expandTimer = useRef<{ id: string; timer: ReturnType<typeof setTimeout> } | null>(null);
  const sensors = useSensors(useSensor(PointerSensor, { activationConstraint: { distance: 6 } }));

  useEffect(() => () => clearTimeout(expandTimer.current?.timer), []);
  if (!open) return null;

  const toggle = (id: string, force?: boolean) =>
    setCollapsed((current) => {
      const next = new Set(current);
      const collapse = force ?? !next.has(id);
      if (collapse) next.add(id);
      else next.delete(id);
      return next;
    });

  const scheduleExpand = (drop: (TreeDrop & { overId: string }) | null) => {
    const id = drop?.position === 'inside' && collapsed.has(drop.overId) ? drop.overId : null;
    if (expandTimer.current?.id === id) return;
    clearTimeout(expandTimer.current?.timer);
    expandTimer.current = id ? { id, timer: setTimeout(() => toggle(id, false), EXPAND_DELAY_MS) } : null;
  };

  const setDrop = (drop: (TreeDrop & { overId: string }) | null) => {
    targetRef.current = drop;
    setTarget(drop);
    scheduleExpand(drop);
  };

  const onDragStart = (event: DragStartEvent) => {
    const start = event.activatorEvent as PointerEvent;
    origin.current = { x: start.clientX, y: start.clientY };
    setDragging(String(event.active.data.current?.id ?? ''));
  };

  const onDragMove = (event: DragMoveEvent) => {
    const movingId = String(event.active.data.current?.id ?? '');
    const x = origin.current.x + event.delta.x;
    const y = origin.current.y + event.delta.y;
    const row = document.elementFromPoint(x, y)?.closest<HTMLElement>('[data-nav-id]');
    const overId = row?.dataset.navId;
    if (!row || !overId) return setDrop(null);
    const rect = row.getBoundingClientRect();
    const current = store.getState().history.present;
    const drop = resolveTreeDrop(current, movingId, overId, (y - rect.top) / rect.height);
    // Only offer drops the move would actually perform (depth limit, no-op positions).
    const valid = drop && moveNode(current, movingId, drop.parentId, drop.index) !== current;
    setDrop(valid ? { ...drop, overId } : null);
  };

  const finish = (apply: boolean) => {
    const movingId = dragging;
    const drop = targetRef.current;
    setDragging(null);
    setDrop(null);
    if (!apply || !movingId || !drop) return;
    dispatch({ type: 'move', id: movingId, parentId: drop.parentId, index: drop.index });
    dispatch({ type: 'select', id: movingId });
  };

  const rows: React.ReactNode[] = [];
  const visit = (id: string, depth: number) => {
    const node = doc.nodes[id];
    if (!node) return;
    const hasChildren = node.children.length > 0;
    rows.push(
      <NavRow
        key={id}
        node={node}
        depth={depth}
        expanded={hasChildren ? !collapsed.has(id) : undefined}
        drop={target?.overId === id ? target.position : null}
        onToggle={() => toggle(id)}
        onContextMenu={contextMenuHandler(store, id)}
      />,
    );
    if (hasChildren && !collapsed.has(id)) for (const child of node.children) visit(child, depth + 1);
  };
  for (const id of doc.rootIds) visit(id, 0);

  const draggedType = dragging ? doc.nodes[dragging]?.type : undefined;
  return (
    <section className="ed-nav ed-ui" aria-label="Navigator">
      <header>
        <span>Navigator</span>
        <button type="button" className="ed-icon-btn" aria-label="Close navigator" onClick={() => dispatch({ type: 'toggleNavigator', open: false })}>
          <UiIcon name="x" size={14} />
        </button>
      </header>
      <DndContext sensors={sensors} autoScroll onDragStart={onDragStart} onDragMove={onDragMove} onDragEnd={() => finish(true)} onDragCancel={() => finish(false)}>
        {rows.length ? <ul role="tree">{rows}</ul> : <p className="ed-empty-note">The page is empty.</p>}
        <DragOverlay dropAnimation={null} style={{ pointerEvents: 'none', width: 'auto', height: 'auto' }}>
          {draggedType ? (
            <span className="ed-drag-chip">
              <UiIcon name={widgets[draggedType]?.icon ?? 'square'} size={14} />
              {widgets[draggedType]?.title ?? draggedType}
            </span>
          ) : null}
        </DragOverlay>
      </DndContext>
      <p className="ed-nav-hint">Drag rows to reorder or nest elements.</p>
    </section>
  );
}

function NavRow({
  node,
  depth,
  expanded,
  drop,
  onToggle,
  onContextMenu,
}: {
  node: ElementNode;
  depth: number;
  expanded: boolean | undefined;
  drop: TreeDrop['position'] | null;
  onToggle: () => void;
  onContextMenu: (e: React.MouseEvent) => void;
}) {
  const dispatch = useDispatch();
  const selected = useEditor((s) => s.selectedId === node.id);
  const { setNodeRef, listeners, attributes, isDragging } = useDraggable({ id: `nav:${node.id}`, data: { id: node.id } });
  const manifest = widgets[node.type];
  return (
    <li>
      <div
        ref={setNodeRef}
        {...attributes}
        {...listeners}
        className="ed-nav-row"
        data-nav-id={node.id}
        data-drop={drop ?? undefined}
        data-dragging={isDragging ? '' : undefined}
        role="treeitem"
        aria-selected={selected}
        aria-expanded={expanded}
        aria-level={depth + 1}
        tabIndex={0}
        style={{ paddingLeft: 6 + depth * 14 }}
        onClick={() => dispatch({ type: 'select', id: node.id })}
        onContextMenu={onContextMenu}
        onKeyDown={(e) => {
          if (e.key === 'Enter' || e.key === ' ') {
            e.preventDefault();
            dispatch({ type: 'select', id: node.id });
          }
        }}
        onMouseEnter={() => highlight(node.id, true)}
        onMouseLeave={() => highlight(node.id, false)}
      >
        {expanded !== undefined ? (
          <button
            type="button"
            className="ed-mini-btn"
            aria-label={expanded ? 'Collapse' : 'Expand'}
            onPointerDown={(e) => e.stopPropagation()}
            onClick={(e) => {
              e.stopPropagation();
              onToggle();
            }}
          >
            <UiIcon name={expanded ? 'chevron-down' : 'chevron-right'} size={12} />
          </button>
        ) : (
          <span style={{ width: 20 }} />
        )}
        <UiIcon name={manifest?.icon ?? 'square'} size={13} />
        <span>{manifest?.title ?? node.type}</span>
        <em>{describe(node)}</em>
      </div>
    </li>
  );
}
