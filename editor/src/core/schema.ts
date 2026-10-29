/**
 * Typed access to the shared contract in ../schema. The JSON files are the source of truth; this
 * module only gives them types and precomputes lookups (allowed style keys, compiled patterns).
 */
import configJson from '@schema/config.json';
import controlsJson from '@schema/controls.json';
import fontsJson from '@schema/fonts.json';
import iconsJson from '@schema/icons.json';
import stylePropertiesJson from '@schema/style-properties.json';
import button from '@schema/widgets/button.json';
import component from '@schema/widgets/component.json';
import container from '@schema/widgets/container.json';
import divider from '@schema/widgets/divider.json';
import heading from '@schema/widgets/heading.json';
import icon from '@schema/widgets/icon.json';
import image from '@schema/widgets/image.json';
import spacer from '@schema/widgets/spacer.json';
import text from '@schema/widgets/text.json';
import video from '@schema/widgets/video.json';
import type { Device, Responsive } from './types';

export type StyleType =
  | 'color'
  | 'size'
  | 'number'
  | 'enum'
  | 'fontFamily'
  | 'box'
  | 'border'
  | 'shadow'
  | 'background'
  | 'globalFont';

export interface StyleProperty {
  type: StyleType;
  css: string | string[];
  units?: string[];
  min?: number;
  max?: number;
  options?: string[];
  integer?: boolean;
  corners?: boolean;
  state?: 'hover';
}

export type PropType = 'text' | 'richtext' | 'enum' | 'bool' | 'number' | 'link' | 'image' | 'videoUrl' | 'icon';

export interface PropSpec {
  type: PropType;
  default: unknown;
  maxLength?: number;
  options?: string[];
  min?: number;
  max?: number;
  integer?: boolean;
}

export type AdvancedKey = 'cssId' | 'cssClasses' | 'hideOn' | 'customCss';

export interface ControlSpec {
  prop?: string;
  style?: string;
  group?: string;
  advanced?: AdvancedKey;
  label: string;
  control?: string;
  responsive?: boolean;
  showIf?: { prop: string; equals: unknown };
}

export interface ControlSection {
  section: string;
  controls: ControlSpec[];
}

export interface WidgetManifest {
  type: string;
  title: string;
  category: string;
  icon: string;
  keywords: string[];
  acceptsChildren: boolean;
  inlineEditable?: string;
  props: Record<string, PropSpec>;
  styleTargets: Record<string, string>;
  defaultStyle: Record<string, Responsive<unknown>>;
  controls: { content: ControlSection[]; style: ControlSection[] };
}

export interface SettingSpec {
  type: 'text' | 'number' | 'color' | 'fontFamily' | 'css';
  default: unknown;
  maxLength?: number;
  min?: number;
  max?: number;
  integer?: boolean;
}

interface SchemaConfig {
  schemaVersion: number;
  devices: Device[];
  breakpoints: { tablet: number; mobile: number };
  previewWidths: Record<Device, number | null>;
  limits: {
    maxDocumentBytes: number;
    maxNodes: number;
    maxDepth: number;
    maxUrlLength: number;
    maxDecimals: number;
    maxCustomCssLength: number;
    maxGlobalColors: number;
    maxGlobalFonts: number;
    maxGlobalNameLength: number;
  };
  patterns: Record<'nodeId' | 'cssId' | 'cssClasses' | 'color' | 'linkUrl' | 'mediaUrl' | 'videoUrl' | 'globalId' | 'globalColor', string>;
  settings: Record<string, SettingSpec>;
}

interface ControlsConfig {
  categories: { id: string; label: string }[];
  widgets: string[];
  groups: Record<string, string[]>;
  responsiveByDefault: StyleType[];
  commonAdvanced: ControlSection[];
}

export const config = configJson as unknown as SchemaConfig;
export const controlsConfig = controlsJson as unknown as ControlsConfig;
export const styleProperties = stylePropertiesJson as unknown as Record<string, StyleProperty>;
/** Style keys in contract order; this order is also the CSS declaration order. */
export const styleKeys: readonly string[] = Object.keys(styleProperties);
export const fonts = fontsJson as Record<string, { label: string; stack: string }>;
export const icons = (iconsJson as { icons: Record<string, { label: string; svg: string }> }).icons;

const manifestList = [container, heading, text, button, divider, spacer, image, video, icon, component] as unknown as WidgetManifest[];
export const widgets: Record<string, WidgetManifest> = Object.fromEntries(manifestList.map((m) => [m.type, m]));
/** Palette order as declared by the contract. */
export const widgetOrder: readonly string[] = controlsConfig.widgets.filter((t) => t in widgets);

export const patterns = Object.fromEntries(
  Object.entries(config.patterns).map(([name, source]) => [name, new RegExp(source, 'u')]),
) as Record<keyof SchemaConfig['patterns'], RegExp>;

/** Expands `group` entries into the style keys they stand for. */
export function controlStyleKeys(control: ControlSpec): string[] {
  if (control.style) return [control.style];
  if (control.group) return controlsConfig.groups[control.group] ?? [];
  return [];
}

function sectionStyleKeys(sections: ControlSection[]): string[] {
  return sections.flatMap((s) => s.controls.flatMap(controlStyleKeys));
}

const ownStyleKeyCache = new Map<string, Set<string>>();
const allowedStyleKeyCache = new Map<string, Set<string>>();

/** Style keys referenced by the widget's own Content/Style controls. */
export function ownStyleKeys(type: string): Set<string> {
  let keys = ownStyleKeyCache.get(type);
  if (!keys) {
    const manifest = widgets[type];
    keys = new Set(manifest ? sectionStyleKeys([...manifest.controls.content, ...manifest.controls.style]) : []);
    ownStyleKeyCache.set(type, keys);
  }
  return keys;
}

/** Style keys a node of this type may carry: its own controls plus the common Advanced controls. */
export function allowedStyleKeys(type: string): Set<string> {
  let keys = allowedStyleKeyCache.get(type);
  if (!keys) {
    keys = new Set(widgets[type] ? [...ownStyleKeys(type), ...sectionStyleKeys(controlsConfig.commonAdvanced)] : []);
    allowedStyleKeyCache.set(type, keys);
  }
  return keys;
}

/** Whether a control edits a per-device value (explicit flag wins over the type default). */
export function isResponsiveControl(control: ControlSpec): boolean {
  if (control.responsive !== undefined) return control.responsive;
  if (control.group) return true;
  const prop = control.style ? styleProperties[control.style] : undefined;
  return prop ? controlsConfig.responsiveByDefault.includes(prop.type) : false;
}
