/**
 * Editor state and reducer. Document edits go through history (undo/redo) and bump `revision`,
 * which autosave compares with `save.savedRevision`; UI state (selection, device, zoom) never
 * enters history.
 */
import {
  duplicateNode,
  insertFragment,
  moveNode,
  nudge,
  replaceNode,
  pasteStyle,
  removeNode,
  resetStyle,
  setAdvanced,
  setProp,
  setSettings,
  setStyle,
} from '@/core/document';
import { commit, createHistory, redo, seal, undo, type History } from '@/core/history';
import type { Advanced, Device, Fragment, PageDocument, PageSettings, ValidationIssue } from '@/core/types';
import { emptyDocument } from '@/core/document';
import { defaultGlobals, type Globals } from '@/core/globals';

export type LeftTab = 'elements' | 'settings' | 'page' | 'globals' | 'history';
export type SettingsTab = 'content' | 'style' | 'advanced';
export type SaveStatus = 'idle' | 'saving' | 'error' | 'conflict' | 'invalid' | 'auth';

export interface PageMeta {
  /** What is being edited: a page, or a reusable component (README §10). */
  kind: 'page' | 'component';
  id: number;
  title: string;
  slug: string;
  status: 'draft' | 'published';
  url: string;
  publishedVersion: number | null;
  hasUnpublishedChanges: boolean;
  publishedAt: string | null;
}

export interface SaveState {
  status: SaveStatus;
  /** Server draft version the editor's document is based on. */
  version: number;
  /** Revision that is known to be stored on the server. */
  savedRevision: number;
  savedAt: number | null;
  message: string | null;
  issues: ValidationIssue[];
  conflict: { currentVersion: number; updatedAt: string } | null;
  retryAt: number | null;
}

/** Site-wide global styles: edited live in the canvas, saved explicitly (never in undo history). */
export interface GlobalsState {
  value: Globals;
  /** Server version `value` is based on (0 = defaults never saved). */
  version: number;
  /** What the server holds; `value !== saved` means unsaved changes. */
  saved: Globals;
  saving: boolean;
}

/** A reusable component's published document as loaded for rendering its instances. */
export type ComponentEntry = { name: string; document: PageDocument } | 'loading' | 'missing';

export interface Toast {
  id: number;
  kind: 'info' | 'success' | 'error';
  message: string;
}

export interface EditorState {
  history: History<PageDocument>;
  revision: number;
  selectedId: string | null;
  device: Device;
  zoom: number;
  canvasWidth: number | null;
  leftTab: LeftTab;
  settingsTab: SettingsTab;
  navigatorOpen: boolean;
  templatesOpen: boolean;
  clipboard: Fragment | null;
  /** Open element context menu (right click), in viewport coordinates. */
  menu: { id: string; x: number; y: number } | null;
  /** Open naming dialog: saving an element (or, with `id` null, the page) for reuse. */
  dialog: { kind: 'saveComponent' | 'saveTemplate'; id: string | null } | null;
  save: SaveState;
  page: PageMeta | null;
  publishing: boolean;
  globals: GlobalsState;
  components: Record<number, ComponentEntry>;
  toasts: Toast[];
}

export type EditorAction =
  | { type: 'load'; doc: PageDocument; page: PageMeta; version: number }
  | { type: 'replaceDocument'; doc: PageDocument; version: number }
  | { type: 'insert'; fragment: Fragment; parentId: string | null; index: number; selectId?: string }
  | { type: 'move'; id: string; parentId: string | null; index: number }
  | { type: 'remove'; id: string }
  | { type: 'duplicate'; id: string }
  | { type: 'replace'; id: string; fragment: Fragment }
  | { type: 'nudge'; id: string; delta: -1 | 1 }
  | { type: 'pasteStyle'; id: string }
  | { type: 'resetStyle'; id: string }
  | { type: 'setProp'; id: string; key: string; value: unknown; mergeKey?: string }
  | { type: 'setStyle'; id: string; key: string; device: Device; value: unknown; mergeKey?: string }
  | { type: 'setAdvanced'; id: string; key: keyof Advanced; value: Advanced[keyof Advanced]; mergeKey?: string }
  | { type: 'setSettings'; key: keyof PageSettings; value: PageSettings[keyof PageSettings]; mergeKey?: string }
  | { type: 'restore'; doc: PageDocument }
  | { type: 'undo' }
  | { type: 'redo' }
  | { type: 'seal' }
  | { type: 'select'; id: string | null }
  | { type: 'setDevice'; device: Device }
  | { type: 'setZoom'; zoom: number }
  | { type: 'setCanvasWidth'; width: number | null }
  | { type: 'setLeftTab'; tab: LeftTab }
  | { type: 'setSettingsTab'; tab: SettingsTab }
  | { type: 'toggleNavigator'; open?: boolean }
  | { type: 'toggleTemplates'; open?: boolean }
  | { type: 'copy'; fragment: Fragment }
  | { type: 'openMenu'; id: string; x: number; y: number }
  | { type: 'closeMenu' }
  | { type: 'openDialog'; kind: 'saveComponent' | 'saveTemplate'; id: string | null }
  | { type: 'closeDialog' }
  | { type: 'saveStarted' }
  | { type: 'saveSucceeded'; revision: number; version: number; at: number }
  | { type: 'saveFailed'; status: Exclude<SaveStatus, 'idle' | 'saving'>; message: string; issues?: ValidationIssue[]; conflict?: SaveState['conflict']; retryAt?: number | null }
  | { type: 'saveReset'; version?: number }
  | { type: 'setPage'; page: Partial<PageMeta> }
  | { type: 'setPublishing'; value: boolean }
  | { type: 'loadGlobals'; globals: Globals; version: number }
  | { type: 'editGlobals'; globals: Globals }
  | { type: 'globalsSaving'; value: boolean }
  | { type: 'globalsSaved'; version: number }
  | { type: 'componentLoading'; id: number }
  | { type: 'componentLoaded'; id: number; name: string; document: PageDocument }
  | { type: 'componentMissing'; id: number }
  | { type: 'toast'; kind: Toast['kind']; message: string }
  | { type: 'dismissToast'; id: number };

export const ZOOM_LEVELS = [0.25, 0.5, 0.75, 1, 1.25, 1.5] as const;

let toastId = 0;

const initialGlobals = defaultGlobals();

export function initialState(doc: PageDocument = emptyDocument(), page: PageMeta | null = null, version = 1): EditorState {
  return {
    history: createHistory(doc),
    revision: 0,
    selectedId: null,
    device: 'desktop',
    zoom: 1,
    canvasWidth: null,
    leftTab: 'elements',
    settingsTab: 'content',
    navigatorOpen: false,
    templatesOpen: false,
    clipboard: null,
    menu: null,
    dialog: null,
    save: { status: 'idle', version, savedRevision: 0, savedAt: null, message: null, issues: [], conflict: null, retryAt: null },
    page,
    publishing: false,
    globals: { value: initialGlobals, version: 0, saved: initialGlobals, saving: false },
    components: {},
    toasts: [],
  };
}

/** Applies a document edit through history; no-ops leave the state untouched. */
function edit(state: EditorState, next: PageDocument, mergeKey?: string, selectedId = state.selectedId): EditorState {
  if (next === state.history.present) return state;
  return {
    ...state,
    history: commit(state.history, next, { mergeKey: mergeKey ?? null }),
    revision: state.revision + 1,
    selectedId: selectedId && next.nodes[selectedId] ? selectedId : null,
  };
}

function afterHistoryJump(state: EditorState, history: History<PageDocument>): EditorState {
  if (history === state.history) return state;
  const selectedId = state.selectedId && history.present.nodes[state.selectedId] ? state.selectedId : null;
  return {
    ...state,
    history,
    revision: state.revision + 1,
    selectedId,
    leftTab: selectedId === null && state.leftTab === 'settings' ? 'elements' : state.leftTab,
  };
}

export function editorReducer(state: EditorState, action: EditorAction): EditorState {
  const doc = state.history.present;
  switch (action.type) {
    case 'load':
      return { ...initialState(action.doc, action.page, action.version), device: state.device, zoom: state.zoom, globals: state.globals, components: state.components };
    case 'replaceDocument':
      return {
        ...state,
        history: createHistory(action.doc),
        revision: state.revision + 1,
        selectedId: null,
        leftTab: state.leftTab === 'settings' ? 'elements' : state.leftTab,
        save: { ...state.save, status: 'idle', version: action.version, savedRevision: state.revision + 1, conflict: null, message: null, issues: [] },
      };
    case 'insert': {
      const next = insertFragment(doc, action.fragment, action.parentId, action.index);
      if (next === doc) return state;
      return { ...edit(state, next, undefined, action.selectId ?? action.fragment.rootId), leftTab: 'settings', settingsTab: 'content' };
    }
    case 'move':
      return edit(state, moveNode(doc, action.id, action.parentId, action.index));
    case 'remove':
      return { ...edit(state, removeNode(doc, action.id)), leftTab: state.selectedId === action.id ? 'elements' : state.leftTab };
    case 'duplicate': {
      const result = duplicateNode(doc, action.id);
      return result.id ? edit(state, result.doc, undefined, result.id) : state;
    }
    case 'nudge':
      return edit(state, nudge(doc, action.id, action.delta));
    case 'replace': {
      const next = replaceNode(doc, action.id, action.fragment);
      return next === doc ? state : edit(state, next, undefined, action.fragment.rootId);
    }
    case 'pasteStyle': {
      const source = state.clipboard?.nodes[state.clipboard.rootId];
      return source ? edit(state, pasteStyle(doc, action.id, source)) : state;
    }
    case 'resetStyle':
      return edit(state, resetStyle(doc, action.id));
    case 'setProp':
      return edit(state, setProp(doc, action.id, action.key, action.value), action.mergeKey);
    case 'setStyle':
      return edit(state, setStyle(doc, action.id, action.key, action.device, action.value), action.mergeKey);
    case 'setAdvanced':
      return edit(state, setAdvanced(doc, action.id, action.key, action.value), action.mergeKey);
    case 'setSettings':
      return edit(state, setSettings(doc, action.key, action.value), action.mergeKey);
    case 'restore':
      return edit({ ...state, history: seal(state.history) }, action.doc);
    case 'undo':
      return afterHistoryJump(state, undo(state.history));
    case 'redo':
      return afterHistoryJump(state, redo(state.history));
    case 'seal': {
      const history = seal(state.history);
      return history === state.history ? state : { ...state, history };
    }
    case 'select':
      if (action.id === state.selectedId && (action.id === null || state.leftTab === 'settings')) return state;
      return {
        ...state,
        history: seal(state.history),
        selectedId: action.id && doc.nodes[action.id] ? action.id : null,
        leftTab: action.id ? 'settings' : state.leftTab === 'settings' ? 'elements' : state.leftTab,
      };
    case 'setDevice':
      return state.device === action.device ? state : { ...state, device: action.device };
    case 'setZoom':
      return { ...state, zoom: Math.min(1.5, Math.max(0.25, action.zoom)) };
    case 'setCanvasWidth':
      return { ...state, canvasWidth: action.width };
    case 'setLeftTab':
      return { ...state, leftTab: action.tab };
    case 'setSettingsTab':
      return { ...state, settingsTab: action.tab };
    case 'toggleNavigator':
      return { ...state, navigatorOpen: action.open ?? !state.navigatorOpen };
    case 'toggleTemplates':
      return { ...state, templatesOpen: action.open ?? !state.templatesOpen };
    case 'copy':
      return { ...state, clipboard: action.fragment };
    case 'openMenu':
      return doc.nodes[action.id] ? { ...state, menu: { id: action.id, x: action.x, y: action.y } } : state;
    case 'closeMenu':
      return state.menu ? { ...state, menu: null } : state;
    case 'openDialog':
      return { ...state, dialog: { kind: action.kind, id: action.id }, menu: null };
    case 'closeDialog':
      return state.dialog ? { ...state, dialog: null } : state;
    case 'saveStarted':
      return { ...state, save: { ...state.save, status: 'saving', message: null } };
    case 'saveSucceeded':
      return {
        ...state,
        save: {
          status: 'idle',
          version: action.version,
          savedRevision: action.revision,
          savedAt: action.at,
          message: null,
          issues: [],
          conflict: null,
          retryAt: null,
        },
        page: state.page ? { ...state.page, hasUnpublishedChanges: true } : state.page,
      };
    case 'saveFailed':
      return {
        ...state,
        save: {
          ...state.save,
          status: action.status,
          message: action.message,
          issues: action.issues ?? [],
          conflict: action.conflict ?? null,
          retryAt: action.retryAt ?? null,
        },
      };
    case 'saveReset':
      // After the user resolved a conflict ("keep mine") or signed in again.
      return {
        ...state,
        save: { ...state.save, status: 'idle', version: action.version ?? state.save.version, conflict: null, message: null, retryAt: null },
      };
    case 'setPage':
      return state.page ? { ...state, page: { ...state.page, ...action.page } } : state;
    case 'setPublishing':
      return { ...state, publishing: action.value };
    case 'loadGlobals':
      return { ...state, globals: { value: action.globals, version: action.version, saved: action.globals, saving: false } };
    case 'editGlobals':
      return { ...state, globals: { ...state.globals, value: action.globals } };
    case 'globalsSaving':
      return { ...state, globals: { ...state.globals, saving: action.value } };
    case 'componentLoading':
      return { ...state, components: { ...state.components, [action.id]: 'loading' } };
    case 'componentLoaded':
      return { ...state, components: { ...state.components, [action.id]: { name: action.name, document: action.document } } };
    case 'componentMissing':
      return { ...state, components: { ...state.components, [action.id]: 'missing' } };
    case 'globalsSaved':
      return { ...state, globals: { ...state.globals, version: action.version, saved: state.globals.value, saving: false } };
    case 'toast':
      return { ...state, toasts: [...state.toasts.slice(-3), { id: ++toastId, kind: action.kind, message: action.message }] };
    case 'dismissToast':
      return { ...state, toasts: state.toasts.filter((t) => t.id !== action.id) };
  }
}

/** True when the document has edits the server has not stored yet. */
export const isDirty = (state: EditorState) => state.revision !== state.save.savedRevision;

/** True when the global styles were edited but not saved. */
export const globalsDirty = (state: EditorState) => state.globals.value !== state.globals.saved;
