/**
 * Global styles (schema/README.md §9): site-wide colors and typography presets that documents
 * reference instead of repeating values. Mirrors the backend's GlobalsValidator / SiteContext.
 *
 * Documents store references (`"global:primary"` for a color, `style.globalFont` for a font
 * preset); renderers resolve them against the current globals, so changing a global restyles every
 * page that uses it. A reference to a global that no longer exists resolves to nothing, exactly
 * like an invalid value: the declaration is skipped.
 */
import defaultsJson from '@schema/globals.json';
import { config, controlsConfig, patterns, styleProperties } from './schema';
import type { PageDocument, Responsive, ValidationIssue } from './types';
import { DEVICES } from './types';
import { codePointLength, isLiteralColor, isPlainObject, isValidStyleValue } from './validate';

export interface GlobalColor {
  id: string;
  name: string;
  value: string;
}

export interface GlobalFont {
  id: string;
  name: string;
  style: Record<string, Responsive<unknown>>;
}

export interface Globals {
  colors: GlobalColor[];
  fonts: GlobalFont[];
}

export const GLOBAL_COLOR_PREFIX = 'global:';

/** Style keys a font preset may set: the typography group without the preset reference itself. */
export const FONT_KEYS: readonly string[] = (controlsConfig.groups.typography ?? []).filter((k) => k !== 'globalFont');

export function defaultGlobals(): Globals {
  return structuredClone(defaultsJson) as unknown as Globals;
}

export const globalColorRef = (id: string) => `${GLOBAL_COLOR_PREFIX}${id}`;

/** The id of a `global:<id>` reference, or null for anything else. */
export function globalColorId(value: unknown): string | null {
  return typeof value === 'string' && patterns.globalColor.test(value) ? value.slice(GLOBAL_COLOR_PREFIX.length) : null;
}

type Add = (path: string, message: string) => void;

function isValidName(value: unknown): value is string {
  return typeof value === 'string' && value.trim() !== '' && codePointLength(value) <= config.limits.maxGlobalNameLength;
}

function checkEntry(entry: unknown, path: string, keys: readonly string[], add: Add): entry is Record<string, unknown> {
  if (!isPlainObject(entry)) {
    add(path, 'Must be an object.');
    return false;
  }
  for (const key of Object.keys(entry)) if (!keys.includes(key)) add(`${path}.${key}`, 'Unknown key.');
  for (const key of keys) if (!Object.hasOwn(entry, key)) add(`${path}.${key}`, 'This key is required.');
  return true;
}

/** Problems with one font preset's style; empty when valid. */
function checkFontStyle(style: unknown, path: string, add: Add): void {
  if (!isPlainObject(style)) {
    add(path, 'Must be an object.');
    return;
  }
  for (const [key, responsive] of Object.entries(style)) {
    const prop = styleProperties[key];
    if (!prop || !FONT_KEYS.includes(key)) {
      add(`${path}.${key}`, 'Not a typography style.');
      continue;
    }
    if (!isPlainObject(responsive) || !Object.keys(responsive).every((d) => (DEVICES as readonly string[]).includes(d))) {
      add(`${path}.${key}`, 'A style value must map devices to values.');
      continue;
    }
    for (const device of DEVICES) {
      const value = responsive[device];
      if (value !== undefined && value !== null && !isValidStyleValue(prop, value)) add(`${path}.${key}.${device}`, 'Invalid value.');
    }
  }
}

/**
 * Validates a globals object. Paths: `globals` (not an object), `{key}` (unknown/missing),
 * `colors` / `fonts` (not a list, too many), `colors.{i}` and `colors.{i}.{key}`,
 * `fonts.{i}.style.{key}[.{device}]`. A repeated id is reported at `{list}.{i}.id`.
 */
export function validateGlobals(globals: unknown): ValidationIssue[] {
  const issues: ValidationIssue[] = [];
  const add: Add = (path, message) => issues.push({ path, message });
  if (!isPlainObject(globals)) {
    add('globals', 'Must be an object.');
    return issues;
  }
  for (const key of Object.keys(globals)) if (key !== 'colors' && key !== 'fonts') add(key, 'Unknown key.');

  const lists = [
    ['colors', config.limits.maxGlobalColors],
    ['fonts', config.limits.maxGlobalFonts],
  ] as const;
  for (const [list, max] of lists) {
    const entries = globals[list];
    if (!Array.isArray(entries)) {
      add(list, 'Must be a list.');
      continue;
    }
    if (entries.length > max) add(list, `At most ${max} entries.`);
    const seen = new Set<string>();
    entries.forEach((entry, i) => {
      const path = `${list}.${i}`;
      if (!checkEntry(entry, path, list === 'colors' ? ['id', 'name', 'value'] : ['id', 'name', 'style'], add)) return;
      if (typeof entry.id !== 'string' || !patterns.globalId.test(entry.id)) add(`${path}.id`, 'Lowercase letters, digits and "-", starting with a letter.');
      else if (seen.has(entry.id)) add(`${path}.id`, 'Ids must be unique.');
      else seen.add(entry.id);
      if (Object.hasOwn(entry, 'name') && !isValidName(entry.name)) add(`${path}.name`, 'Enter a name (up to 60 characters).');
      if (list === 'colors' && Object.hasOwn(entry, 'value') && !isLiteralColor(entry.value)) add(`${path}.value`, 'Must be a hex color or "transparent".');
      if (list === 'fonts' && Object.hasOwn(entry, 'style')) checkFontStyle(entry.style, `${path}.style`, add);
    });
  }
  return issues;
}

/**
 * What renderers need from the site: global style lookups built from the valid entries only, and
 * the published documents of the reusable components (README §10) the page may reference.
 */
export interface SiteContext {
  colors: ReadonlyMap<string, string>;
  fonts: ReadonlyMap<string, Record<string, Responsive<unknown>>>;
  components: ReadonlyMap<number, PageDocument>;
}

export const EMPTY_SITE: SiteContext = { colors: new Map(), fonts: new Map(), components: new Map() };

/**
 * Builds the render lookups. Never trusts its input: an entry that fails validation is skipped,
 * as is any later entry repeating an id, so the output never depends on unvalidated data.
 */
export function siteContext(globals: unknown, components: ReadonlyMap<number, PageDocument> = new Map()): SiteContext {
  if (!isPlainObject(globals)) return { ...EMPTY_SITE, components };
  const colors = new Map<string, string>();
  const fonts = new Map<string, Record<string, Responsive<unknown>>>();
  const valid = (entry: unknown, list: 'colors' | 'fonts') =>
    validateGlobals({ colors: [], fonts: [], [list]: [entry] }).length === 0;
  if (Array.isArray(globals.colors)) {
    for (const entry of globals.colors.slice(0, config.limits.maxGlobalColors)) {
      if (!valid(entry, 'colors')) continue;
      const { id, value } = entry as GlobalColor;
      if (!colors.has(id)) colors.set(id, value);
    }
  }
  if (Array.isArray(globals.fonts)) {
    for (const entry of globals.fonts.slice(0, config.limits.maxGlobalFonts)) {
      if (!valid(entry, 'fonts')) continue;
      const { id, style } = entry as GlobalFont;
      if (!fonts.has(id)) fonts.set(id, style);
    }
  }
  return { colors, fonts, components };
}

/** A literal color for a document color value, or null when it cannot be resolved. */
export function resolveColor(value: unknown, site: SiteContext): string | null {
  const id = globalColorId(value);
  if (id !== null) return site.colors.get(id) ?? null;
  return isLiteralColor(value) ? value : null;
}
