/**
 * Pure, immutable document operations. Every function returns a new document that shares
 * untouched nodes with the input (structural sharing), which keeps undo snapshots cheap and lets
 * React/CSS caches key on node identity. Invalid operations return the input unchanged.
 */
import { allowedStyleKeys, config, widgets } from './schema';
import type { Device, ElementNode, Fragment, PageDocument, PageSettings, Responsive } from './types';
import { DEVICES } from './types';

const ID_ALPHABET = 'abcdefghijklmnopqrstuvwxyz0123456789';

/** Random 8-character id matching patterns.nodeId, unique among `taken`. */
export function generateId(taken: (id: string) => boolean = () => false): string {
  const bytes = new Uint8Array(8);
  for (;;) {
    crypto.getRandomValues(bytes);
    let id = '';
    for (const b of bytes) id += ID_ALPHABET[b % ID_ALPHABET.length];
    if (!taken(id)) return id;
  }
}

export function emptyDocument(): PageDocument {
  const settings = Object.fromEntries(
    Object.entries(config.settings).map(([key, spec]) => [key, spec.default]),
  ) as unknown as PageSettings;
  return { schemaVersion: 1, rootIds: [], nodes: {}, settings };
}

const clone = <T>(value: T): T => structuredClone(value);

/** A new element of `type` with every prop default and the manifest's default styles. */
export function createNode(type: string, id: string): ElementNode {
  const manifest = widgets[type];
  if (!manifest) throw new Error(`Unknown widget type "${type}"`);
  const props = Object.fromEntries(Object.entries(manifest.props).map(([key, spec]) => [key, clone(spec.default)]));
  return {
    id,
    type,
    props,
    style: clone(manifest.defaultStyle),
    advanced: { cssId: '', cssClasses: '', hideOn: [] },
    children: [],
  };
}

export function nodeFragment(type: string, taken: (id: string) => boolean): Fragment {
  const id = generateId(taken);
  return { rootId: id, nodes: { [id]: createNode(type, id) } };
}

export interface Location {
  parentId: string | null;
  index: number;
}

/** Parent and index of `id`, or null when the node is not attached. */
export function locate(doc: PageDocument, id: string): Location | null {
  const rootIndex = doc.rootIds.indexOf(id);
  if (rootIndex !== -1) return { parentId: null, index: rootIndex };
  for (const node of Object.values(doc.nodes)) {
    const index = node.children.indexOf(id);
    if (index !== -1) return { parentId: node.id, index };
  }
  return null;
}

/** Builds a child → parent lookup in one pass (use when many lookups are needed). */
export function parentIndex(doc: PageDocument): Map<string, string | null> {
  const parents = new Map<string, string | null>();
  for (const id of doc.rootIds) parents.set(id, null);
  for (const node of Object.values(doc.nodes)) for (const child of node.children) parents.set(child, node.id);
  return parents;
}

/** Ids from the root down to (and including) `id`. */
export function ancestry(doc: PageDocument, id: string): string[] {
  const parents = parentIndex(doc);
  const path: string[] = [];
  let current: string | null | undefined = id;
  while (current) {
    path.unshift(current);
    current = parents.get(current);
    if (path.length > config.limits.maxNodes) break;
  }
  return path;
}

/** Whether `ancestorId` is `id` itself or one of its ancestors. */
export function isSelfOrAncestor(doc: PageDocument, ancestorId: string, id: string): boolean {
  return ancestry(doc, id).includes(ancestorId);
}

export function childrenOf(doc: PageDocument, parentId: string | null): string[] {
  return parentId === null ? doc.rootIds : (doc.nodes[parentId]?.children ?? []);
}

export function acceptsChildren(doc: PageDocument, parentId: string | null): boolean {
  if (parentId === null) return true;
  const node = doc.nodes[parentId];
  return !!node && !!widgets[node.type]?.acceptsChildren;
}

/** Depth of an attached node (root = 1) and the height of the subtree below it (leaf = 1). */
function subtreeHeight(nodes: Record<string, ElementNode>, id: string): number {
  const node = nodes[id];
  if (!node || node.children.length === 0) return 1;
  return 1 + Math.max(...node.children.map((c) => subtreeHeight(nodes, c)));
}

function depthOf(doc: PageDocument, parentId: string | null): number {
  return parentId === null ? 0 : ancestry(doc, parentId).length;
}

function withChildren(doc: PageDocument, parentId: string | null, children: string[]): PageDocument {
  if (parentId === null) return { ...doc, rootIds: children };
  const parent = doc.nodes[parentId]!;
  return { ...doc, nodes: { ...doc.nodes, [parentId]: { ...parent, children } } };
}

const clampIndex = (index: number, length: number) => Math.max(0, Math.min(index, length));

/** Inserts a detached subtree. Fails (returns `doc`) on id clashes, bad targets or depth overflow. */
export function insertFragment(doc: PageDocument, fragment: Fragment, parentId: string | null, index: number): PageDocument {
  if (!fragment.nodes[fragment.rootId]) return doc;
  if (!acceptsChildren(doc, parentId)) return doc;
  if (Object.keys(fragment.nodes).some((id) => doc.nodes[id])) return doc;
  if (depthOf(doc, parentId) + subtreeHeight(fragment.nodes, fragment.rootId) > config.limits.maxDepth) return doc;
  if (Object.keys(doc.nodes).length + Object.keys(fragment.nodes).length > config.limits.maxNodes) return doc;

  const siblings = [...childrenOf(doc, parentId)];
  siblings.splice(clampIndex(index, siblings.length), 0, fragment.rootId);
  const next = { ...doc, nodes: { ...doc.nodes, ...fragment.nodes } };
  return withChildren(next, parentId, siblings);
}

function collectSubtree(doc: PageDocument, id: string, into: string[] = []): string[] {
  const node = doc.nodes[id];
  if (!node || into.includes(id)) return into;
  into.push(id);
  for (const child of node.children) collectSubtree(doc, child, into);
  return into;
}

export function removeNode(doc: PageDocument, id: string): PageDocument {
  const location = locate(doc, id);
  if (!location) return doc;
  const siblings = childrenOf(doc, location.parentId).filter((c) => c !== id);
  const next = withChildren(doc, location.parentId, siblings);
  const nodes = { ...next.nodes };
  for (const removed of collectSubtree(doc, id)) delete nodes[removed];
  return { ...next, nodes };
}

/**
 * Moves `id` under `parentId` at `index`, where `index` is a position in the target's children
 * list *as it is now* (what a drop indicator measures). Moving into its own subtree is refused.
 */
export function moveNode(doc: PageDocument, id: string, parentId: string | null, index: number): PageDocument {
  const from = locate(doc, id);
  if (!from) return doc;
  if (parentId !== null && isSelfOrAncestor(doc, id, parentId)) return doc;
  if (!acceptsChildren(doc, parentId)) return doc;

  let target = index;
  if (from.parentId === parentId && from.index < index) target -= 1;
  if (from.parentId === parentId && from.index === target) return doc;

  if (from.parentId !== parentId) {
    const height = subtreeHeight(doc.nodes, id);
    if (depthOf(doc, parentId) + height > config.limits.maxDepth) return doc;
  }

  const removed = withChildren(doc, from.parentId, childrenOf(doc, from.parentId).filter((c) => c !== id));
  const siblings = [...childrenOf(removed, parentId)];
  siblings.splice(clampIndex(target, siblings.length), 0, id);
  return withChildren(removed, parentId, siblings);
}

/** Deep copy of the subtree rooted at `id`, keeping ids. */
export function extractFragment(doc: PageDocument, id: string): Fragment | null {
  if (!doc.nodes[id]) return null;
  const nodes: Record<string, ElementNode> = {};
  for (const nodeId of collectSubtree(doc, id)) nodes[nodeId] = clone(doc.nodes[nodeId]!);
  return { rootId: id, nodes };
}

/** Same subtree with fresh ids (for duplicate/paste). */
export function reidentify(fragment: Fragment, taken: (id: string) => boolean): Fragment {
  const mapping = new Map<string, string>();
  const used = new Set<string>();
  for (const oldId of Object.keys(fragment.nodes)) {
    const newId = generateId((candidate) => taken(candidate) || used.has(candidate));
    used.add(newId);
    mapping.set(oldId, newId);
  }
  const nodes: Record<string, ElementNode> = {};
  for (const [oldId, node] of Object.entries(fragment.nodes)) {
    const newId = mapping.get(oldId)!;
    nodes[newId] = {
      ...clone(node),
      id: newId,
      children: node.children.map((c) => mapping.get(c)).filter((c): c is string => c !== undefined),
      // CSS ids must stay unique on the page, so copies drop them.
      advanced: { ...clone(node.advanced), cssId: '' },
    };
  }
  return { rootId: mapping.get(fragment.rootId)!, nodes };
}

/** Puts `fragment` where `id` is (same parent and position), as one operation. */
export function replaceNode(doc: PageDocument, id: string, fragment: Fragment): PageDocument {
  const location = locate(doc, id);
  if (!location) return doc;
  const removed = removeNode(doc, id);
  const next = insertFragment(removed, fragment, location.parentId, location.index);
  return next === removed ? doc : next;
}

export function duplicateNode(doc: PageDocument, id: string): { doc: PageDocument; id: string | null } {
  const location = locate(doc, id);
  const fragment = extractFragment(doc, id);
  if (!location || !fragment) return { doc, id: null };
  const copy = reidentify(fragment, (candidate) => candidate in doc.nodes);
  const next = insertFragment(doc, copy, location.parentId, location.index + 1);
  return next === doc ? { doc, id: null } : { doc: next, id: copy.rootId };
}

function updateNode(doc: PageDocument, id: string, update: (node: ElementNode) => ElementNode): PageDocument {
  const node = doc.nodes[id];
  if (!node) return doc;
  const next = update(node);
  return next === node ? doc : { ...doc, nodes: { ...doc.nodes, [id]: next } };
}

export function setProp(doc: PageDocument, id: string, key: string, value: unknown): PageDocument {
  return updateNode(doc, id, (node) =>
    Object.is(node.props[key], value) ? node : { ...node, props: { ...node.props, [key]: value } },
  );
}

/** Sets (or with `null` clears) one device value; drops the key once no device has a value. */
export function setStyle(doc: PageDocument, id: string, key: string, device: Device, value: unknown): PageDocument {
  return updateNode(doc, id, (node) => {
    const current: Responsive<unknown> = node.style[key] ?? {};
    if (value === null || value === undefined) {
      if (current[device] === undefined) return node;
      const rest: Responsive<unknown> = { ...current };
      delete rest[device];
      const style = { ...node.style };
      if (DEVICES.some((d) => rest[d] !== undefined && rest[d] !== null)) style[key] = rest;
      else delete style[key];
      return { ...node, style };
    }
    return { ...node, style: { ...node.style, [key]: { ...current, [device]: value } } };
  });
}

const sameJson = (a: unknown, b: unknown) => JSON.stringify(a) === JSON.stringify(b);

/**
 * "Paste style": the target takes the source's value for every style key both widget types can
 * carry; a shared key the source leaves unset is cleared, so the result looks like the source.
 * Keys only the target supports (a container's flex layout under a heading's style) are kept.
 * Custom CSS is part of the look and is copied too.
 */
export function pasteStyle(doc: PageDocument, id: string, source: ElementNode): PageDocument {
  return updateNode(doc, id, (node) => {
    const targetKeys = allowedStyleKeys(node.type);
    const sourceKeys = allowedStyleKeys(source.type);
    const style: ElementNode['style'] = {};
    for (const [key, value] of Object.entries(node.style)) {
      if (!sourceKeys.has(key)) style[key] = value;
    }
    for (const [key, value] of Object.entries(source.style)) {
      if (targetKeys.has(key)) style[key] = clone(value);
    }
    const advanced = { ...node.advanced };
    if (source.advanced.customCss) advanced.customCss = source.advanced.customCss;
    else delete advanced.customCss;
    if (sameJson(style, node.style) && sameJson(advanced, node.advanced)) return node;
    return { ...node, style, advanced };
  });
}

/** Back to the widget's default styles. */
export function resetStyle(doc: PageDocument, id: string): PageDocument {
  return updateNode(doc, id, (node) => {
    const style = clone(widgets[node.type]?.defaultStyle ?? {});
    return sameJson(style, node.style) ? node : { ...node, style };
  });
}

export function setAdvanced<K extends keyof ElementNode['advanced']>(
  doc: PageDocument,
  id: string,
  key: K,
  value: ElementNode['advanced'][K],
): PageDocument {
  return updateNode(doc, id, (node) => ({ ...node, advanced: { ...node.advanced, [key]: value } }));
}

export function setSettings<K extends keyof PageSettings>(doc: PageDocument, key: K, value: PageSettings[K]): PageDocument {
  return Object.is(doc.settings[key], value) ? doc : { ...doc, settings: { ...doc.settings, [key]: value } };
}

/** Prop value with the manifest default as fallback (mirrors the renderer). */
export function propOf<T>(node: ElementNode, key: string): T {
  if (Object.hasOwn(node.props, key)) return node.props[key] as T;
  return widgets[node.type]?.props[key]?.default as T;
}

/** Moves `id` one step among its siblings (keyboard reordering). */
export function nudge(doc: PageDocument, id: string, delta: -1 | 1): PageDocument {
  const location = locate(doc, id);
  if (!location) return doc;
  const siblings = childrenOf(doc, location.parentId);
  const target = location.index + delta;
  if (target < 0 || target >= siblings.length) return doc;
  return moveNode(doc, id, location.parentId, delta > 0 ? target + 1 : target);
}
