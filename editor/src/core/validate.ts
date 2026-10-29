/**
 * Document validator. Mirrors the backend's DocumentValidator: same rules, same error paths
 * (schema/README.md §1–§3, proven by schema/fixtures/validation/cases.json). The backend is the
 * authority; this copy gives instant feedback in the editor and keeps the CSS generator safe.
 */
import {
  allowedStyleKeys,
  config,
  fonts,
  icons,
  patterns,
  styleProperties,
  widgets,
  type PropSpec,
  type SettingSpec,
  type StyleProperty,
} from './schema';
import { customCssError } from './customCss';
import { DEVICES, type ValidationIssue } from './types';

const BORDER_STYLES = ['none', 'solid', 'dashed', 'dotted', 'double', 'groove'];
const BACKGROUND_TYPES = ['none', 'color', 'gradient', 'image'];
const BACKGROUND_SIZES = ['auto', 'cover', 'contain'];
const BACKGROUND_POSITIONS = [
  'center center', 'top center', 'bottom center', 'center left', 'center right',
  'top left', 'top right', 'bottom left', 'bottom right',
];
const BACKGROUND_REPEATS = ['no-repeat', 'repeat', 'repeat-x', 'repeat-y'];
const NODE_KEYS = ['id', 'type', 'props', 'style', 'advanced', 'children'];
const DOCUMENT_KEYS = ['schemaVersion', 'rootIds', 'nodes', 'settings'];

type Obj = Record<string, unknown>;

export function isPlainObject(value: unknown): value is Obj {
  return typeof value === 'object' && value !== null && !Array.isArray(value);
}

export function codePointLength(value: string): number {
  let count = 0;
  for (const _ of value) count++;
  return count;
}

export function hasAtMostDecimals(value: number, decimals: number): boolean {
  const scale = 10 ** decimals;
  return Math.abs(value * scale - Math.round(value * scale)) < 1e-6;
}

function isNumber(value: unknown): value is number {
  return typeof value === 'number' && Number.isFinite(value);
}

/** Finite number within range, integer when required, at most `maxDecimals` places. */
function isValidNumber(value: unknown, min?: number, max?: number, integer?: boolean): value is number {
  if (!isNumber(value)) return false;
  if (min !== undefined && value < min) return false;
  if (max !== undefined && value > max) return false;
  if (integer && !Number.isInteger(value)) return false;
  return hasAtMostDecimals(value, config.limits.maxDecimals);
}

function onlyKeys(value: Obj, allowed: readonly string[]): boolean {
  return Object.keys(value).every((key) => allowed.includes(key));
}

/** A hex color or `transparent` (what global colors themselves hold). */
export function isLiteralColor(value: unknown): value is string {
  return typeof value === 'string' && patterns.color.test(value);
}

/** A color in a document: a literal or a `global:<id>` reference (README §2 "Global styles"). */
function isColor(value: unknown): value is string {
  return isLiteralColor(value) || (typeof value === 'string' && patterns.globalColor.test(value));
}

function isOptional(value: unknown, check: (v: unknown) => boolean): boolean {
  return value === undefined || value === null || check(value);
}

function matchesUrl(value: unknown, pattern: RegExp): value is string {
  return typeof value === 'string' && codePointLength(value) <= config.limits.maxUrlLength && pattern.test(value);
}

export function isValidLinkUrl(value: unknown): boolean {
  return value === '' || matchesUrl(value, patterns.linkUrl);
}

export function isValidMediaUrl(value: unknown): boolean {
  return value === '' || matchesUrl(value, patterns.mediaUrl);
}

export function isValidVideoUrl(value: unknown): boolean {
  return value === '' || matchesUrl(value, patterns.videoUrl);
}

/** Returns true when `value` is a valid device value for the style property. */
export function isValidStyleValue(prop: StyleProperty, value: unknown): boolean {
  switch (prop.type) {
    case 'color':
      return isColor(value);
    case 'number':
      return isValidNumber(value, prop.min, prop.max, prop.integer);
    case 'enum':
      return typeof value === 'string' && (prop.options ?? []).includes(value);
    case 'fontFamily':
      return typeof value === 'string' && Object.hasOwn(fonts, value);
    case 'globalFont':
      return typeof value === 'string' && patterns.globalId.test(value);
    case 'size':
      return (
        isPlainObject(value) &&
        onlyKeys(value, ['value', 'unit']) &&
        isValidNumber(value.value, prop.min, prop.max) &&
        typeof value.unit === 'string' &&
        (prop.units ?? []).includes(value.unit)
      );
    case 'box':
      return (
        isPlainObject(value) &&
        onlyKeys(value, ['top', 'right', 'bottom', 'left', 'unit']) &&
        typeof value.unit === 'string' &&
        (prop.units ?? []).includes(value.unit) &&
        (['top', 'right', 'bottom', 'left'] as const).every((side) =>
          isOptional(value[side], (v) => isValidNumber(v, prop.min, prop.max)),
        )
      );
    case 'border':
      return (
        isPlainObject(value) &&
        onlyKeys(value, ['style', 'width', 'color']) &&
        isOptional(value.style, (v) => typeof v === 'string' && BORDER_STYLES.includes(v)) &&
        isOptional(value.width, (v) => isValidNumber(v, 0, 100)) &&
        isOptional(value.color, isColor)
      );
    case 'shadow':
      return (
        isPlainObject(value) &&
        onlyKeys(value, ['x', 'y', 'blur', 'spread', 'color', 'inset']) &&
        isValidNumber(value.x, -200, 200) &&
        isValidNumber(value.y, -200, 200) &&
        isValidNumber(value.blur, 0, 300) &&
        isValidNumber(value.spread, -200, 200) &&
        isColor(value.color) &&
        typeof value.inset === 'boolean'
      );
    case 'background':
      return (
        isPlainObject(value) &&
        onlyKeys(value, [
          'type', 'color', 'gradientFrom', 'gradientTo', 'gradientAngle',
          'imageUrl', 'imageSize', 'imagePosition', 'imageRepeat',
        ]) &&
        typeof value.type === 'string' &&
        BACKGROUND_TYPES.includes(value.type) &&
        isOptional(value.color, isColor) &&
        isOptional(value.gradientFrom, isColor) &&
        isOptional(value.gradientTo, isColor) &&
        isOptional(value.gradientAngle, (v) => isValidNumber(v, 0, 360)) &&
        isOptional(value.imageUrl, isValidMediaUrl) &&
        isOptional(value.imageSize, (v) => typeof v === 'string' && BACKGROUND_SIZES.includes(v)) &&
        isOptional(value.imagePosition, (v) => typeof v === 'string' && BACKGROUND_POSITIONS.includes(v)) &&
        isOptional(value.imageRepeat, (v) => typeof v === 'string' && BACKGROUND_REPEATS.includes(v))
      );
  }
}

function isValidString(value: unknown, maxLength?: number): value is string {
  return typeof value === 'string' && (maxLength === undefined || codePointLength(value) <= maxLength);
}

function isNullableInt(value: unknown, min: number, max: number): boolean {
  return isOptional(value, (v) => isValidNumber(v, min, max, true));
}

/** Returns true when `value` is valid for the prop spec. */
export function isValidPropValue(spec: PropSpec, value: unknown): boolean {
  switch (spec.type) {
    case 'text':
    case 'richtext':
      return isValidString(value, spec.maxLength);
    case 'enum':
      return typeof value === 'string' && (spec.options ?? []).includes(value);
    case 'bool':
      return typeof value === 'boolean';
    case 'number':
      return isValidNumber(value, spec.min, spec.max, spec.integer);
    case 'icon':
      return typeof value === 'string' && Object.hasOwn(icons, value);
    case 'videoUrl':
      return isValidVideoUrl(value);
    case 'link':
      return (
        isPlainObject(value) &&
        onlyKeys(value, ['url', 'newTab', 'nofollow']) &&
        (value.url === undefined || isValidLinkUrl(value.url)) &&
        (value.newTab === undefined || typeof value.newTab === 'boolean') &&
        (value.nofollow === undefined || typeof value.nofollow === 'boolean')
      );
    case 'image':
      return (
        isPlainObject(value) &&
        onlyKeys(value, ['url', 'width', 'height', 'id']) &&
        (value.url === undefined || isValidMediaUrl(value.url)) &&
        isNullableInt(value.width, 1, 20000) &&
        isNullableInt(value.height, 1, 20000) &&
        isNullableInt(value.id, 1, Number.MAX_SAFE_INTEGER)
      );
  }
}

/** Custom CSS within `maxLength` code points that passes the structural scan. */
export function isValidCustomCss(value: unknown, maxLength: number): value is string {
  return isValidString(value, maxLength) && customCssError(value) === null;
}

function isValidSetting(spec: SettingSpec, value: unknown): boolean {
  switch (spec.type) {
    case 'text':
      return isValidString(value, spec.maxLength);
    case 'css':
      return isValidCustomCss(value, spec.maxLength ?? 0);
    case 'number':
      return isValidNumber(value, spec.min, spec.max, spec.integer);
    case 'color':
      return value === null || isColor(value);
    case 'fontFamily':
      return value === null || (typeof value === 'string' && Object.hasOwn(fonts, value));
  }
}

export function isValidAdvanced(key: string, value: unknown): boolean {
  switch (key) {
    case 'cssId':
      return value === '' || (typeof value === 'string' && patterns.cssId.test(value));
    case 'cssClasses':
      return value === '' || (typeof value === 'string' && patterns.cssClasses.test(value));
    case 'hideOn':
      return (
        Array.isArray(value) &&
        value.every((d) => typeof d === 'string' && (DEVICES as readonly string[]).includes(d)) &&
        new Set(value).size === value.length
      );
    case 'customCss':
      return isValidCustomCss(value, config.limits.maxCustomCssLength);
    default:
      return false;
  }
}

export function documentByteLength(doc: unknown): number {
  return new TextEncoder().encode(JSON.stringify(doc)).length;
}

/**
 * Validates a whole document. `byteLength` is the size of its JSON encoding; it is computed when
 * omitted. Returns an empty list for a valid document.
 */
export function validateDocument(doc: unknown, byteLength?: number): ValidationIssue[] {
  const issues: ValidationIssue[] = [];
  const add = (path: string, message: string) => issues.push({ path, message });

  if (!isPlainObject(doc)) {
    add('document', 'The document must be an object.');
    return issues;
  }
  if ((byteLength ?? documentByteLength(doc)) > config.limits.maxDocumentBytes) {
    add('document', 'The document is too large.');
  }

  for (const key of Object.keys(doc)) {
    if (!DOCUMENT_KEYS.includes(key)) add(key, 'Unknown key.');
  }
  if (doc.schemaVersion !== config.schemaVersion) {
    add('schemaVersion', `Expected schema version ${config.schemaVersion}.`);
  }

  validateSettings(doc.settings, add);

  const nodes = isPlainObject(doc.nodes) ? doc.nodes : null;
  if (!nodes) {
    add('nodes', 'Nodes must be an object.');
  } else {
    if (Object.keys(nodes).length > config.limits.maxNodes) {
      add('nodes', `A page may contain at most ${config.limits.maxNodes} elements.`);
    }
    for (const [key, node] of Object.entries(nodes)) validateNode(key, node, nodes, add);
  }

  const rootIds = Array.isArray(doc.rootIds) && doc.rootIds.every((id) => typeof id === 'string') ? doc.rootIds : null;
  if (!rootIds) {
    add('rootIds', 'rootIds must be a list of element ids.');
  } else if (nodes && rootIds.some((id) => !Object.hasOwn(nodes, id))) {
    add('rootIds', 'rootIds references an element that does not exist.');
  }

  if (nodes && rootIds) validateStructure(rootIds, nodes, add);

  return issues;
}

type Add = (path: string, message: string) => void;

function validateSettings(settings: unknown, add: Add): void {
  if (!isPlainObject(settings)) {
    add('settings', 'Settings must be an object.');
    return;
  }
  for (const [key, value] of Object.entries(settings)) {
    const spec = config.settings[key];
    if (!spec) add(`settings.${key}`, 'Unknown setting.');
    else if (!isValidSetting(spec, value)) add(`settings.${key}`, 'Invalid value.');
  }
}

function validateNode(key: string, node: unknown, nodes: Obj, add: Add): void {
  const base = `nodes.${key}`;
  if (!patterns.nodeId.test(key)) add(base, 'Invalid element id.');
  if (!isPlainObject(node)) {
    add(base, 'An element must be an object.');
    return;
  }
  for (const field of NODE_KEYS) {
    if (!Object.hasOwn(node, field)) add(`${base}.${field}`, 'Missing field.');
  }
  for (const field of Object.keys(node)) {
    if (!NODE_KEYS.includes(field)) add(`${base}.${field}`, 'Unknown field.');
  }
  if (Object.hasOwn(node, 'id') && node.id !== key) add(`${base}.id`, 'The id must match its key.');

  const manifest = typeof node.type === 'string' ? widgets[node.type] : undefined;
  if (Object.hasOwn(node, 'type') && !manifest) add(`${base}.type`, 'Unknown element type.');

  if (Object.hasOwn(node, 'children')) {
    const children = node.children;
    if (!Array.isArray(children) || !children.every((c) => typeof c === 'string')) {
      add(`${base}.children`, 'Children must be a list of element ids.');
    } else if (manifest && !manifest.acceptsChildren && children.length > 0) {
      add(`${base}.children`, 'This element cannot contain other elements.');
    } else if (children.some((c) => !Object.hasOwn(nodes, c))) {
      add(`${base}.children`, 'Children reference an element that does not exist.');
    }
  }

  if (Object.hasOwn(node, 'advanced')) validateAdvanced(base, node.advanced, add);
  if (!manifest) return;

  if (Object.hasOwn(node, 'props')) {
    if (!isPlainObject(node.props)) {
      add(`${base}.props`, 'Props must be an object.');
    } else {
      for (const [prop, value] of Object.entries(node.props)) {
        const spec = manifest.props[prop];
        if (!spec) add(`${base}.props.${prop}`, 'Unknown property.');
        else if (!isValidPropValue(spec, value)) add(`${base}.props.${prop}`, 'Invalid value.');
      }
    }
  }

  if (Object.hasOwn(node, 'style')) {
    if (!isPlainObject(node.style)) {
      add(`${base}.style`, 'Style must be an object.');
    } else {
      const allowed = allowedStyleKeys(manifest.type);
      for (const [styleKey, responsive] of Object.entries(node.style)) {
        const path = `${base}.style.${styleKey}`;
        const prop = styleProperties[styleKey];
        if (!prop || !allowed.has(styleKey)) {
          add(path, 'This style is not available for the element.');
          continue;
        }
        if (!isPlainObject(responsive) || !onlyKeys(responsive, DEVICES)) {
          add(path, 'A style value must map devices to values.');
          continue;
        }
        for (const device of DEVICES) {
          const value = responsive[device];
          if (value !== undefined && value !== null && !isValidStyleValue(prop, value)) {
            add(`${path}.${device}`, 'Invalid value.');
          }
        }
      }
    }
  }
}

function validateAdvanced(base: string, advanced: unknown, add: Add): void {
  if (!isPlainObject(advanced)) {
    add(`${base}.advanced`, 'Advanced settings must be an object.');
    return;
  }
  for (const [key, value] of Object.entries(advanced)) {
    if (!['cssId', 'cssClasses', 'hideOn', 'customCss'].includes(key)) add(`${base}.advanced.${key}`, 'Unknown field.');
    else if (!isValidAdvanced(key, value)) add(`${base}.advanced.${key}`, 'Invalid value.');
  }
}

/** Depth-first pre-order walk from rootIds: every element reachable exactly once, depth-limited. */
function validateStructure(rootIds: string[], nodes: Obj, add: Add): void {
  const visited = new Set<string>();

  const visit = (id: string, depth: number): void => {
    if (visited.has(id)) {
      add(`nodes.${id}`, 'The element is referenced more than once.');
      return;
    }
    visited.add(id);
    if (depth > config.limits.maxDepth) {
      add(`nodes.${id}`, `Elements may be nested at most ${config.limits.maxDepth} levels deep.`);
      return;
    }
    const node = nodes[id];
    const children = isPlainObject(node) && Array.isArray(node.children) ? node.children : [];
    for (const child of children) {
      if (typeof child === 'string' && Object.hasOwn(nodes, child)) visit(child, depth + 1);
    }
  };

  for (const id of rootIds) {
    if (Object.hasOwn(nodes, id)) visit(id, 1);
  }
  for (const id of Object.keys(nodes)) {
    if (!visited.has(id)) add(`nodes.${id}`, 'The element is not attached to the page.');
  }
}
