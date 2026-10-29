/**
 * Per-node CSS generator (schema/README.md §4). Publish mode must stay byte-identical to the
 * backend's CssGenerator; editor mode replays the same cascade for one chosen device without
 * media queries, so the canvas shows that device's styles at any canvas width.
 */
import { allowedStyleKeys, config, fonts, styleKeys, styleProperties, widgets, type StyleProperty } from './schema';
import type {
  BackgroundValue,
  BorderValue,
  BoxValue,
  Device,
  ElementNode,
  PageDocument,
  PageSettings,
  ShadowValue,
  SizeValue,
} from './types';
import { DEVICES } from './types';
import { propOf } from './document';
import { EMPTY_SITE, FONT_KEYS, resolveColor, type SiteContext } from './globals';
import { isPlainObject, isValidCustomCss, isValidStyleValue } from './validate';

export type CssMode = { kind: 'publish' } | { kind: 'editor'; device: Device };

/** Number formatting shared with the backend: integers as-is, else up to 3 decimals, trimmed. */
export function fmt(value: number): string {
  if (Number.isInteger(value)) return String(value === 0 ? 0 : value);
  return value.toFixed(3).replace(/0+$/, '').replace(/\.$/, '');
}

const isSet = (value: unknown): value is string | number => value !== undefined && value !== null && value !== '';

const BOX_SIDES = ['top', 'right', 'bottom', 'left'] as const;
const CORNERS: Record<(typeof BOX_SIDES)[number], string> = {
  top: 'border-top-left-radius',
  right: 'border-top-right-radius',
  bottom: 'border-bottom-right-radius',
  left: 'border-bottom-left-radius',
};

/**
 * Declarations for one validated value, in contract order. Global color references resolve
 * against `site`; an unresolvable one counts as unset.
 */
export function declarations(prop: StyleProperty, value: unknown, site: SiteContext = EMPTY_SITE): string[] {
  const names = Array.isArray(prop.css) ? prop.css : [prop.css];
  const css = names[0] as string;
  switch (prop.type) {
    case 'color': {
      const color = resolveColor(value, site);
      return color === null ? [] : names.map((name) => `${name}:${color}`);
    }
    case 'enum':
      return names.map((name) => `${name}:${value as string}`);
    case 'globalFont':
      return [];
    case 'number':
      return names.map((name) => `${name}:${fmt(value as number)}`);
    case 'size': {
      const size = value as SizeValue;
      return names.map((name) => `${name}:${fmt(size.value)}${size.unit}`);
    }
    case 'fontFamily':
      return names.map((name) => `${name}:${fonts[value as string]!.stack}`);
    case 'box': {
      const box = value as BoxValue;
      return BOX_SIDES.flatMap((side) => {
        const n = box[side];
        if (n === undefined || n === null) return [];
        const name = prop.corners ? CORNERS[side] : `${css}-${side}`;
        return [`${name}:${fmt(n)}${box.unit}`];
      });
    }
    case 'border': {
      const border = value as BorderValue;
      const color = isSet(border.color) ? resolveColor(border.color, site) : null;
      const out: string[] = [];
      if (isSet(border.style)) out.push(`${css}-style:${border.style}`);
      if (isSet(border.width)) out.push(`${css}-width:${fmt(border.width)}px`);
      if (color !== null) out.push(`${css}-color:${color}`);
      return out;
    }
    case 'shadow': {
      const s = value as ShadowValue;
      const color = resolveColor(s.color, site);
      if (color === null) return [];
      return [`${css}:${s.inset ? 'inset ' : ''}${fmt(s.x)}px ${fmt(s.y)}px ${fmt(s.blur)}px ${fmt(s.spread)}px ${color}`];
    }
    case 'background': {
      const bg = value as BackgroundValue;
      const color = (c: string | null | undefined) => (isSet(c) ? resolveColor(c, site) : null);
      return backgroundDeclarations({ ...bg, color: color(bg.color), gradientFrom: color(bg.gradientFrom), gradientTo: color(bg.gradientTo) });
    }
  }
}

/** `bg` with its colors already resolved (unresolvable = null). */
function backgroundDeclarations(bg: BackgroundValue): string[] {
  switch (bg.type) {
    case 'none':
      return ['background:none'];
    case 'color':
      return isSet(bg.color) ? [`background-color:${bg.color}`] : [];
    case 'gradient':
      return isSet(bg.gradientFrom) && isSet(bg.gradientTo)
        ? [`background-image:linear-gradient(${fmt(bg.gradientAngle ?? 180)}deg,${bg.gradientFrom},${bg.gradientTo})`]
        : [];
    case 'image': {
      const out: string[] = [];
      if (isSet(bg.color)) out.push(`background-color:${bg.color}`);
      if (isSet(bg.imageUrl)) {
        out.push(
          `background-image:url("${bg.imageUrl}")`,
          `background-size:${bg.imageSize ?? 'cover'}`,
          `background-position:${bg.imagePosition ?? 'center center'}`,
          `background-repeat:${bg.imageRepeat ?? 'no-repeat'}`,
        );
      }
      return out;
    }
  }
}

function settingsRule(settings: Partial<PageSettings> | undefined, site: SiteContext): string | null {
  if (!settings) return null;
  const decls: string[] = [];
  const { contentWidth, backgroundColor, textColor, fontFamily } = settings;
  if (typeof contentWidth === 'number' && isValidStyleValue({ type: 'number', css: '', min: 320, max: 3000, integer: true }, contentWidth)) {
    decls.push(`--cvb-content-width:${fmt(contentWidth)}px`);
  }
  const background = isValidStyleValue({ type: 'color', css: '' }, backgroundColor) ? resolveColor(backgroundColor, site) : null;
  const text = isValidStyleValue({ type: 'color', css: '' }, textColor) ? resolveColor(textColor, site) : null;
  if (background !== null) decls.push(`background-color:${background}`);
  if (text !== null) decls.push(`color:${text}`);
  if (typeof fontFamily === 'string' && Object.hasOwn(fonts, fontFamily)) decls.push(`font-family:${fonts[fontFamily]!.stack}`);
  return decls.length ? `.cvb-root{${decls.join(';')}}` : null;
}

type Bucket = Map<string, string[]>;

/** Per device, the node's own value, or the font preset's where the node leaves it unset. */
function withPreset(own: unknown, preset: unknown): unknown {
  if (!isPlainObject(preset)) return own;
  const mine = isPlainObject(own) ? own : {};
  const merged: Record<string, unknown> = {};
  for (const device of DEVICES) {
    const value = mine[device] ?? preset[device];
    if (value !== undefined && value !== null) merged[device] = value;
  }
  return merged;
}

/**
 * The font preset a node uses (README §9): the desktop value of `style.globalFont`, when the widget
 * allows it and the preset exists.
 */
function fontPreset(node: ElementNode, allowed: Set<string>, site: SiteContext) {
  if (!allowed.has('globalFont')) return undefined;
  const ref = node.style.globalFont;
  const id = isPlainObject(ref) ? ref.desktop : undefined;
  return typeof id === 'string' ? site.fonts.get(id) : undefined;
}

/** Appends one node's rules to the per-device buckets. Exported for per-node caching in the editor. */
export function nodeRules(node: ElementNode, site: SiteContext = EMPTY_SITE): Record<Device, Bucket> {
  const buckets: Record<Device, Bucket> = { desktop: new Map(), tablet: new Map(), mobile: new Map() };
  const manifest = widgets[node.type];
  if (!manifest || !isPlainObject(node.style)) return buckets;
  const allowed = allowedStyleKeys(node.type);
  const preset = fontPreset(node, allowed, site);

  for (const key of styleKeys) {
    if (!allowed.has(key)) continue;
    const own = Object.hasOwn(node.style, key) ? node.style[key] : undefined;
    const responsive = preset && FONT_KEYS.includes(key) ? withPreset(own, preset[key]) : own;
    if (!isPlainObject(responsive)) continue;
    const prop = styleProperties[key]!;
    const selector = `.cvb-el-${node.id}${prop.state === 'hover' ? ':hover' : ''}${manifest.styleTargets[key] ?? ''}`;
    for (const device of DEVICES) {
      const value = responsive[device];
      if (value === undefined || value === null || !isValidStyleValue(prop, value)) continue;
      const decls = declarations(prop, value, site);
      if (decls.length === 0) continue;
      const bucket = buckets[device];
      const rule = bucket.get(selector);
      if (rule) rule.push(...decls);
      else bucket.set(selector, [...decls]);
    }
  }
  return buckets;
}

/** Nodes in depth-first pre-order from rootIds; tolerates broken documents (cycles, dangling ids). */
export function walk(doc: PageDocument): ElementNode[] {
  const out: ElementNode[] = [];
  const seen = new Set<string>();
  const visit = (id: string) => {
    const node = doc.nodes[id];
    if (!node || seen.has(id)) return;
    seen.add(id);
    out.push(node);
    for (const child of node.children ?? []) visit(child);
  };
  for (const id of doc.rootIds) visit(id);
  return out;
}

const printRules = (bucket: Bucket): string[] =>
  [...bucket].map(([selector, decls]) => `${selector}{${decls.join(';')}}`);

export interface CssOptions {
  /**
   * Optional per-node rule cache (keyed by node object identity) for fast editor regeneration.
   * Rules depend on `site` too: use a new cache whenever the site context changes.
   */
  cache?: WeakMap<ElementNode, Record<Device, Bucket>>;
  /** Resolved global styles; without it every global reference is unresolved. */
  site?: SiteContext;
}

export function generateCss(doc: PageDocument, mode: CssMode, options: CssOptions = {}): string {
  const site = options.site ?? EMPTY_SITE;
  const lines: string[] = [];
  const settings = settingsRule(doc.settings, site);
  if (settings) lines.push(settings);

  const merged: Record<Device, string[]> = { desktop: [], tablet: [], mobile: [] };
  const hidden: Record<Device, string[]> = { desktop: [], tablet: [], mobile: [] };
  // Custom CSS goes last so it can override generated rules (README §4).
  const custom: string[] = [];
  const pageCss = doc.settings?.customCss;
  if (pageCss && isValidCustomCss(pageCss, config.settings.customCss?.maxLength ?? 0)) custom.push(`.cvb-root{${pageCss}}`);

  // README §10: a component's nodes follow its first instance, once per page; nodes inside a
  // component never expand further components.
  const expanded = new Set<number>();
  const nodes: ElementNode[] = [];
  for (const node of walk(doc)) {
    nodes.push(node);
    const componentId = node.type === 'component' ? propOf<unknown>(node, 'componentId') : undefined;
    const component = typeof componentId === 'number' ? site.components.get(componentId) : undefined;
    if (component && !expanded.has(componentId as number)) {
      expanded.add(componentId as number);
      nodes.push(...walk(component).filter((inner) => inner.type !== 'component'));
    }
  }

  for (const node of nodes) {
    let rules = options.cache?.get(node);
    if (!rules) {
      rules = nodeRules(node, site);
      options.cache?.set(node, rules);
    }
    for (const device of DEVICES) merged[device].push(...printRules(rules[device]));
    const hideOn = Array.isArray(node.advanced?.hideOn) ? node.advanced.hideOn : [];
    for (const device of DEVICES) if (hideOn.includes(device)) hidden[device].push(node.id);
    const nodeCss = node.advanced?.customCss;
    if (nodeCss && isValidCustomCss(nodeCss, config.limits.maxCustomCssLength)) custom.push(`.cvb-el-${node.id}{${nodeCss}}`);
  }

  const { tablet, mobile } = config.breakpoints;
  const block = (query: string, body: string[]) => {
    if (body.length) lines.push(`${query}{`, ...body, '}');
  };

  if (mode.kind === 'publish') {
    lines.push(...merged.desktop);
    block(`@media (max-width:${tablet}px)`, merged.tablet);
    block(`@media (max-width:${mobile}px)`, merged.mobile);
    block(`@media (min-width:${tablet + 1}px)`, hidden.desktop.map((id) => `.cvb-el-${id}{display:none}`));
    block(
      `@media (min-width:${mobile + 1}px) and (max-width:${tablet}px)`,
      hidden.tablet.map((id) => `.cvb-el-${id}{display:none}`),
    );
    block(`@media (max-width:${mobile}px)`, hidden.mobile.map((id) => `.cvb-el-${id}{display:none}`));
    lines.push(...custom);
  } else {
    const { device } = mode;
    lines.push(...merged.desktop);
    if (device !== 'desktop') lines.push(...merged.tablet);
    if (device === 'mobile') lines.push(...merged.mobile);
    lines.push(...hidden[device].map((id) => `.cvb-el-${id}{opacity:0.35}`));
    lines.push(...custom);
  }

  return lines.join('\n');
}
