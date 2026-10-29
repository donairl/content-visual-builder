/** Builders for what gets inserted where: palette clicks/drops, column structures, paste. */
import { createNode, generateId, locate, reidentify } from '@/core/document';
import { widgets } from '@/core/schema';
import type { ElementNode, Fragment, PageDocument } from '@/core/types';

export interface InsertionPoint {
  parentId: string | null;
  index: number;
}

/** Into the selected container, after the selected widget, or at the end of the page. */
export function insertionPoint(doc: PageDocument, selectedId: string | null): InsertionPoint {
  const selected = selectedId ? doc.nodes[selectedId] : undefined;
  if (selected && widgets[selected.type]?.acceptsChildren) return { parentId: selected.id, index: selected.children.length };
  if (selected) {
    const location = locate(doc, selected.id);
    if (location) return { parentId: location.parentId, index: location.index + 1 };
  }
  return { parentId: null, index: doc.rootIds.length };
}

function idSource(doc: PageDocument, extra: Set<string>) {
  return () => {
    const id = generateId((candidate) => candidate in doc.nodes || extra.has(candidate));
    extra.add(id);
    return id;
  };
}

/**
 * A new widget. Like Elementor, a widget dropped at the top level is wrapped in a container so
 * the page keeps a consistent section structure. `widgetId` is the element to select afterwards.
 */
export function widgetFragment(
  doc: PageDocument,
  type: string,
  parentId: string | null,
  props: Record<string, unknown> = {},
): { fragment: Fragment; widgetId: string } {
  const nextId = idSource(doc, new Set());
  const widget = createNode(type, nextId());
  widget.props = { ...widget.props, ...props };
  if (parentId !== null || widgets[type]?.acceptsChildren) {
    return { fragment: { rootId: widget.id, nodes: { [widget.id]: widget } }, widgetId: widget.id };
  }
  const container = { ...createNode('container', nextId()), children: [widget.id] };
  return { fragment: { rootId: container.id, nodes: { [container.id]: container, [widget.id]: widget } }, widgetId: widget.id };
}

/** A boxed row with `columns` equal columns that stack on mobile. */
export function structureFragment(doc: PageDocument, columns: number): Fragment {
  const nextId = idSource(doc, new Set());
  const row: ElementNode = createNode('container', nextId());
  const nodes: Record<string, ElementNode> = {};
  if (columns > 1) {
    row.style = {
      flexDirection: { desktop: 'row', mobile: 'column' },
      gap: { desktop: { value: 20, unit: 'px' } },
    };
    const width = Math.round((100 / columns) * 1000) / 1000;
    for (let i = 0; i < columns; i++) {
      const column = createNode('container', nextId());
      column.props = { ...column.props, contentWidth: 'full' };
      column.style = { width: { desktop: { value: width, unit: '%' }, mobile: { value: 100, unit: '%' } } };
      nodes[column.id] = column;
      row.children.push(column.id);
    }
  }
  nodes[row.id] = row;
  return { rootId: row.id, nodes };
}

export function pasteFragment(doc: PageDocument, clipboard: Fragment): Fragment {
  return reidentify(clipboard, (candidate) => candidate in doc.nodes);
}
