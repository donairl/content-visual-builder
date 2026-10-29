/**
 * Drop-position resolution, kept pure so it is testable without a browser. The canvas finds the
 * deepest element under the pointer and measures rects; this decides where the drop lands and
 * where to draw the indicator. Rects are viewport coordinates (getBoundingClientRect), which
 * already include canvas zoom and scroll.
 */
import { childrenOf, isSelfOrAncestor, parentIndex } from './document';
import { resolveValue } from './responsive';
import { widgets } from './schema';
import type { Device, PageDocument, Responsive } from './types';

export interface Rect {
  left: number;
  top: number;
  width: number;
  height: number;
}

export type Axis = 'vertical' | 'horizontal';

export type Indicator = { kind: 'line'; axis: Axis; rect: Rect } | { kind: 'box'; rect: Rect };

export interface DropTarget {
  parentId: string | null;
  /** Index in the parent's current children list (before any removal). */
  index: number;
  indicator: Indicator;
}

export interface DropQuery {
  doc: PageDocument;
  device: Device;
  /** Deepest element under the pointer, or null for the canvas root area. */
  overId: string | null;
  pointer: { x: number; y: number };
  /** Element being moved, or null when a new widget is dropped. */
  movingId: string | null;
  rectOf: (id: string) => Rect | null;
  /** Inner (children) area of a container, or the canvas root for null. */
  innerRectOf: (parentId: string | null) => Rect | null;
}

/** Edge band (px, capped at a quarter of the size) where a container drop means "beside it". */
const EDGE_PX = 20;
const EDGE_RATIO = 0.25;
const LINE = 4;

export function flowOf(doc: PageDocument, device: Device, parentId: string | null): { axis: Axis; reverse: boolean } {
  if (parentId === null) return { axis: 'vertical', reverse: false };
  const style = doc.nodes[parentId]?.style.flexDirection as Responsive<string> | undefined;
  const direction = resolveValue(style, device) ?? 'column';
  return { axis: direction.startsWith('row') ? 'horizontal' : 'vertical', reverse: direction.endsWith('reverse') };
}

function lineAt(rect: Rect, axis: Axis, leading: boolean): Indicator {
  if (axis === 'vertical') {
    const y = (leading ? rect.top : rect.top + rect.height) - LINE / 2;
    return { kind: 'line', axis, rect: { left: rect.left, top: y, width: rect.width, height: LINE } };
  }
  const x = (leading ? rect.left : rect.left + rect.width) - LINE / 2;
  return { kind: 'line', axis, rect: { left: x, top: rect.top, width: LINE, height: rect.height } };
}

/** Pointer position along the axis relative to the rect, and the rect's size on that axis. */
function along(rect: Rect, pointer: { x: number; y: number }, axis: Axis): [number, number] {
  return axis === 'vertical' ? [pointer.y - rect.top, rect.height] : [pointer.x - rect.left, rect.width];
}

/**
 * Drop next to `id`. `visuallyFirst` is whether the pointer is on the element's leading side as
 * seen on screen; with a reversed flex direction that is the *later* position in DOM order.
 */
function beside(q: DropQuery, id: string, parentId: string | null, rect: Rect, visuallyFirst: boolean): DropTarget {
  const { axis, reverse } = flowOf(q.doc, q.device, parentId);
  const index = childrenOf(q.doc, parentId).indexOf(id);
  const domBefore = visuallyFirst !== reverse;
  return { parentId, index: domBefore ? index : index + 1, indicator: lineAt(rect, axis, visuallyFirst) };
}

function inside(q: DropQuery, parentId: string | null): DropTarget | null {
  const children = childrenOf(q.doc, parentId);
  const area = q.innerRectOf(parentId);
  const measured = children
    .map((id) => ({ id, rect: q.rectOf(id) }))
    .filter((c): c is { id: string; rect: Rect } => c.rect !== null && c.id !== q.movingId);

  if (measured.length === 0) {
    if (!area) return null;
    // Empty (or only containing the dragged element): drop as the only child.
    const index = children.length === 1 && children[0] === q.movingId ? 0 : children.length;
    return { parentId, index, indicator: { kind: 'box', rect: area } };
  }

  // Nearest child by distance handles wrapped rows; then decide which side of it.
  const distance = (r: Rect) => {
    const dx = Math.max(r.left - q.pointer.x, 0, q.pointer.x - (r.left + r.width));
    const dy = Math.max(r.top - q.pointer.y, 0, q.pointer.y - (r.top + r.height));
    return dx * dx + dy * dy;
  };
  const nearest = measured.reduce((best, c) => (distance(c.rect) < distance(best.rect) ? c : best));
  const { axis } = flowOf(q.doc, q.device, parentId);
  const [position, size] = along(nearest.rect, q.pointer, axis);
  return beside(q, nearest.id, parentId, nearest.rect, position < size / 2);
}

export function resolveDrop(q: DropQuery): DropTarget | null {
  const parents = parentIndex(q.doc);
  let overId = q.overId;
  // Never drop into the dragged subtree: climb out of it first.
  if (q.movingId) {
    while (overId !== null && isSelfOrAncestor(q.doc, q.movingId, overId)) overId = parents.get(overId) ?? null;
  }
  if (overId === null || !q.doc.nodes[overId]) return inside(q, null);

  const node = q.doc.nodes[overId]!;
  const parentId = parents.get(overId) ?? null;
  const rect = q.rectOf(overId);
  if (!rect) return null;
  const { axis } = flowOf(q.doc, q.device, parentId);
  const [position, size] = along(rect, q.pointer, axis);

  if (widgets[node.type]?.acceptsChildren && overId !== q.movingId) {
    const edge = Math.min(EDGE_PX, size * EDGE_RATIO);
    if (position < edge) return beside(q, overId, parentId, rect, true);
    if (position > size - edge) return beside(q, overId, parentId, rect, false);
    return inside(q, overId);
  }
  return beside(q, overId, parentId, rect, position < size / 2);
}

/* ------------------------------------------------------------ navigator (tree) drops */

export type TreePosition = 'before' | 'after' | 'inside';

export interface TreeDrop {
  parentId: string | null;
  /** Index in the parent's current children list, as moveNode expects. */
  index: number;
  position: TreePosition;
}

/**
 * Where a navigator row drop lands. `offset` is the pointer's vertical position within the row
 * (0 = top, 1 = bottom): containers take the middle half as "inside", their outer quarters and
 * any other row's halves mean before/after. Dropping onto the dragged element or into its own
 * subtree is refused (null).
 */
export function resolveTreeDrop(doc: PageDocument, movingId: string, overId: string, offset: number): TreeDrop | null {
  const over = doc.nodes[overId];
  if (!over || !doc.nodes[movingId] || isSelfOrAncestor(doc, movingId, overId)) return null;
  const parentId = parentIndex(doc).get(overId);
  if (parentId === undefined) return null;
  const container = !!widgets[over.type]?.acceptsChildren;
  const position: TreePosition = container
    ? offset < 0.25
      ? 'before'
      : offset > 0.75
        ? 'after'
        : 'inside'
    : offset < 0.5
      ? 'before'
      : 'after';
  if (position === 'inside') return { parentId: overId, index: over.children.length, position };
  const index = childrenOf(doc, parentId).indexOf(overId);
  return { parentId, index: position === 'before' ? index : index + 1, position };
}
