'use client';

/**
 * Drag-and-drop wiring. dnd-kit supplies sensors, activation thresholds and the drag overlay;
 * drop targets are resolved by hit-testing the canvas (deepest [data-cvb-node] under the
 * pointer) and core/dnd.ts, which handles nesting, flex direction and edge zones exactly.
 */
import { DndContext, DragOverlay, PointerSensor, useSensor, useSensors, type DragMoveEvent, type DragStartEvent } from '@dnd-kit/core';
import { useCallback, useEffect, useRef, useState, type ReactNode } from 'react';
import { resolveDrop, type DropTarget, type Rect } from '@/core/dnd';
import { widgets } from '@/core/schema';
import { useStore } from '../context';
import { widgetFragment } from '../insert';
import { UiIcon } from './UiIcon';

/** `props` overrides the new widget's defaults (a component instance's `componentId`). */
export type DragData = { kind: 'new'; type: string; props?: Record<string, unknown> } | { kind: 'move'; id: string };

const AUTO_SCROLL_EDGE = 56;
const AUTO_SCROLL_SPEED = 14;

const rectOf = (el: Element | null): Rect | null => {
  if (!el) return null;
  const r = el.getBoundingClientRect();
  return { left: r.left, top: r.top, width: r.width, height: r.height };
};

export function DragLayer({ children }: { children: ReactNode }) {
  const store = useStore();
  const sensors = useSensors(useSensor(PointerSensor, { activationConstraint: { distance: 6 } }));
  const [active, setActive] = useState<DragData | null>(null);
  const [target, setTarget] = useState<DropTarget | null>(null);
  const targetRef = useRef<DropTarget | null>(null);
  const dataRef = useRef<DragData | null>(null);
  const origin = useRef({ x: 0, y: 0 });
  const pointer = useRef({ x: 0, y: 0 });
  const frame = useRef<number | null>(null);

  const compute = useCallback(() => {
    const data = dataRef.current;
    if (!data) return;
    const { x, y } = pointer.current;
    const hit = document.elementFromPoint(x, y);
    const canvas = hit?.closest('[data-cvb-canvas]');
    let next: DropTarget | null = null;
    if (canvas && hit && !hit.closest('.ed-toolbar')) {
      const overId = hit.closest('[data-cvb-node]')?.getAttribute('data-cvb-node') ?? null;
      const state = store.getState();
      next = resolveDrop({
        doc: state.history.present,
        device: state.device,
        overId,
        pointer: { x, y },
        movingId: data.kind === 'move' ? data.id : null,
        rectOf: (id) => rectOf(canvas.querySelector(`[data-cvb-node="${id}"]`)),
        innerRectOf: (id) => rectOf(canvas.querySelector(id === null ? '[data-cvb-root]' : `[data-cvb-inner="${id}"]`)),
      });
    }
    targetRef.current = next;
    setTarget(next);
  }, [store]);

  /** Scrolls the canvas while the pointer rests near its top/bottom edge. */
  const autoScroll = useCallback(() => {
    frame.current = null;
    if (!dataRef.current) return;
    const canvas = document.querySelector<HTMLElement>('[data-cvb-canvas]');
    if (!canvas) return;
    const r = canvas.getBoundingClientRect();
    const { x, y } = pointer.current;
    if (x < r.left || x > r.right) return;
    const delta = y < r.top + AUTO_SCROLL_EDGE ? -AUTO_SCROLL_SPEED : y > r.bottom - AUTO_SCROLL_EDGE ? AUTO_SCROLL_SPEED : 0;
    if (delta === 0) return;
    canvas.scrollTop += delta;
    compute();
    frame.current = requestAnimationFrame(autoScroll);
  }, [compute]);

  const reset = () => {
    dataRef.current = null;
    targetRef.current = null;
    setActive(null);
    setTarget(null);
    delete document.body.dataset.cvbDragging;
    if (frame.current) cancelAnimationFrame(frame.current);
    frame.current = null;
  };

  useEffect(() => () => {
    if (frame.current) cancelAnimationFrame(frame.current);
  }, []);

  const onDragStart = (event: DragStartEvent) => {
    const data = event.active.data.current as DragData | undefined;
    if (!data) return;
    const start = event.activatorEvent as PointerEvent;
    origin.current = { x: start.clientX, y: start.clientY };
    pointer.current = origin.current;
    dataRef.current = data;
    document.body.dataset.cvbDragging = '1';
    document.querySelectorAll('[data-cvb-hover]').forEach((el) => el.removeAttribute('data-cvb-hover'));
    setActive(data);
  };

  const onDragMove = (event: DragMoveEvent) => {
    pointer.current = { x: origin.current.x + event.delta.x, y: origin.current.y + event.delta.y };
    compute();
    if (frame.current === null) frame.current = requestAnimationFrame(autoScroll);
  };

  const onDragEnd = () => {
    const data = dataRef.current;
    const drop = targetRef.current;
    reset();
    if (!data || !drop) return;
    const state = store.getState();
    if (data.kind === 'new') {
      const { fragment, widgetId } = widgetFragment(state.history.present, data.type, drop.parentId, data.props);
      store.dispatch({ type: 'insert', fragment, parentId: drop.parentId, index: drop.index, selectId: widgetId });
    } else {
      store.dispatch({ type: 'move', id: data.id, parentId: drop.parentId, index: drop.index });
      store.dispatch({ type: 'select', id: data.id });
    }
  };

  return (
    <DndContext sensors={sensors} autoScroll={false} onDragStart={onDragStart} onDragMove={onDragMove} onDragEnd={onDragEnd} onDragCancel={reset}>
      {children}
      {target ? (
        <div
          className={target.indicator.kind === 'box' ? 'ed-drop-box' : 'ed-drop-line'}
          style={{ left: target.indicator.rect.left, top: target.indicator.rect.top, width: target.indicator.rect.width, height: target.indicator.rect.height }}
          aria-hidden="true"
        />
      ) : null}
      <DragOverlay dropAnimation={null} style={{ pointerEvents: 'none', width: 'auto', height: 'auto' }}>
        {active ? <DragChip data={active} /> : null}
      </DragOverlay>
    </DndContext>
  );
}

function DragChip({ data }: { data: DragData }) {
  const store = useStore();
  const type = data.kind === 'new' ? data.type : store.getState().history.present.nodes[data.id]?.type;
  const manifest = type ? widgets[type] : undefined;
  return (
    <span className="ed-drag-chip">
      <UiIcon name={manifest?.icon ?? 'square'} size={14} />
      {manifest?.title ?? 'Element'}
    </span>
  );
}
