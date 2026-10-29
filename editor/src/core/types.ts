/**
 * Document model shared with the backend. The normative description lives in schema/README.md;
 * these types mirror it so the editor can work with documents without runtime casts everywhere.
 */

export type Device = 'desktop' | 'tablet' | 'mobile';

export const DEVICES: readonly Device[] = ['desktop', 'tablet', 'mobile'];

/** Per-device value; `null` and absent both mean "unset" (inherit from the larger device). */
export type Responsive<T> = Partial<Record<Device, T | null>>;

export interface SizeValue {
  value: number;
  unit: string;
}

export interface BoxValue {
  top?: number | null;
  right?: number | null;
  bottom?: number | null;
  left?: number | null;
  unit: string;
}

export interface BorderValue {
  style?: string | null;
  width?: number | null;
  color?: string | null;
}

export interface ShadowValue {
  x: number;
  y: number;
  blur: number;
  spread: number;
  color: string;
  inset: boolean;
}

export type BackgroundType = 'none' | 'color' | 'gradient' | 'image';

export interface BackgroundValue {
  type: BackgroundType;
  color?: string | null;
  gradientFrom?: string | null;
  gradientTo?: string | null;
  gradientAngle?: number | null;
  imageUrl?: string | null;
  imageSize?: string | null;
  imagePosition?: string | null;
  imageRepeat?: string | null;
}

export interface LinkValue {
  url: string;
  newTab: boolean;
  nofollow: boolean;
}

export interface ImageValue {
  url: string;
  width: number | null;
  height: number | null;
  id: number | null;
}

export interface Advanced {
  cssId: string;
  cssClasses: string;
  hideOn: Device[];
  /** Optional; nested inside `.cvb-el-{id}{…}` (schema/README.md §2 "Custom CSS"). */
  customCss?: string;
}

export interface ElementNode {
  id: string;
  type: string;
  props: Record<string, unknown>;
  style: Record<string, Responsive<unknown>>;
  advanced: Advanced;
  children: string[];
}

export interface PageSettings {
  metaDescription: string;
  contentWidth: number;
  backgroundColor: string | null;
  textColor: string | null;
  fontFamily: string | null;
  /** Optional; nested inside `.cvb-root{…}`. */
  customCss?: string;
}

export interface PageDocument {
  schemaVersion: 1;
  rootIds: string[];
  nodes: Record<string, ElementNode>;
  settings: PageSettings;
}

/** A detached subtree, used for insert, duplicate and copy/paste. */
export interface Fragment {
  rootId: string;
  nodes: Record<string, ElementNode>;
}

export interface ValidationIssue {
  path: string;
  message: string;
}
