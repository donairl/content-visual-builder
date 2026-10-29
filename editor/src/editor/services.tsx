'use client';

/**
 * Editor operations that talk to the server (publish, preview, conflict resolution). They live
 * outside the reducer because they are asynchronous and need the saver.
 */
import { createContext, useContext, type ReactNode } from 'react';
import { createNode, extractFragment, generateId } from '@/core/document';
import { validateGlobals } from '@/core/globals';
import { validateDocument } from '@/core/validate';
import { ApiError, api, type SessionInfo } from './api';
import type { Saver } from './autosave';
import { clearBackup, downloadDocument } from './backup';
import { componentDocument, componentIssues, detachedFragment, loadComponent } from './components';
import type { EditorStore } from './context';

export interface EditorServices {
  session: SessionInfo;
  saver: Saver;
  can(capability: string): boolean;
  publish(): Promise<void>;
  unpublish(): Promise<void>;
  /** Opens the server-rendered draft preview after saving. */
  preview(): Promise<void>;
  /** Conflict: discard local edits and load the server's version. */
  loadServerVersion(): Promise<void>;
  /** Conflict: overwrite the server's version with ours. */
  keepMine(): Promise<void>;
  download(): void;
  /** Saves the edited global styles; the server re-renders every published page. */
  saveGlobals(): Promise<void>;
  /** Drops unsaved global style edits and loads the server's version. */
  reloadGlobals(): Promise<void>;
  /** Turns element `id` into a new component and puts an instance in its place (README §10). */
  saveAsComponent(id: string, name: string): Promise<boolean>;
  /** Replaces a component instance with an editable copy of the component's content. */
  detachComponent(id: string): void;
  /** Opens the component's own editor in a new tab. */
  editComponent(componentId: number): void;
  /** Saves element `id` as a section template, or (null) the whole page as a page template (README §11). */
  saveAsTemplate(id: string | null, name: string): Promise<boolean>;
}

const ServicesContext = createContext<EditorServices | null>(null);

export function ServicesProvider({ services, children }: { services: EditorServices; children: ReactNode }) {
  return <ServicesContext.Provider value={services}>{children}</ServicesContext.Provider>;
}

export function useServices(): EditorServices {
  const services = useContext(ServicesContext);
  if (!services) throw new Error('useServices must be used inside <ServicesProvider>');
  return services;
}

export function createServices(store: EditorStore, saver: Saver, session: SessionInfo): EditorServices {
  const pageId = () => store.getState().page?.id ?? 0;
  const toast = (kind: 'info' | 'success' | 'error', message: string) => store.dispatch({ type: 'toast', kind, message });

  const applySummary = (summary: Awaited<ReturnType<typeof api.publish>>) =>
    store.dispatch({
      type: 'setPage',
      page: {
        status: summary.status,
        url: summary.url,
        publishedVersion: summary.publishedVersion,
        hasUnpublishedChanges: summary.hasUnpublishedChanges,
        publishedAt: summary.publishedAt,
      },
    });

  return {
    session,
    saver,
    can: (capability) => session.capabilities.includes(capability),

    async publish() {
      const state = store.getState();
      const component = state.page?.kind === 'component';
      const issues = component ? componentIssues(state.history.present) : validateDocument(state.history.present);
      if (issues.length) {
        store.dispatch({ type: 'saveFailed', status: 'invalid', message: 'Fix these problems before publishing.', issues });
        return;
      }
      store.dispatch({ type: 'setPublishing', value: true });
      try {
        if (!(await saver.flush())) {
          toast('error', 'The page could not be saved, so it was not published.');
          return;
        }
        if (component) {
          const summary = await api.publishComponent(pageId(), store.getState().save.version);
          store.dispatch({
            type: 'setPage',
            page: { status: 'published', publishedVersion: summary.publishedVersion, hasUnpublishedChanges: summary.hasUnpublishedChanges, publishedAt: summary.publishedAt },
          });
          toast('success', `Component published.${summary.rerendered ? ` ${summary.rerendered} published page(s) updated.` : ''}`);
          return;
        }
        const summary = await api.publish(pageId(), store.getState().save.version);
        applySummary(summary);
        toast('success', 'Published. The live page is updated.');
      } catch (error) {
        if (error instanceof ApiError && error.status === 422 && error.body.errors) {
          store.dispatch({ type: 'saveFailed', status: 'invalid', message: error.message, issues: error.body.errors });
        } else if (error instanceof ApiError && error.status === 409) {
          store.dispatch({
            type: 'saveFailed',
            status: 'conflict',
            message: 'Someone else saved this page. Choose which version to keep.',
            conflict: { currentVersion: error.body.currentVersion ?? 0, updatedAt: error.body.updatedAt ?? '' },
          });
        } else {
          toast('error', error instanceof Error ? error.message : 'Publishing failed.');
        }
      } finally {
        store.dispatch({ type: 'setPublishing', value: false });
      }
    },

    async unpublish() {
      try {
        applySummary(await api.unpublish(pageId()));
        toast('info', 'The page is no longer public.');
      } catch (error) {
        toast('error', error instanceof Error ? error.message : 'Could not unpublish.');
      }
    },

    async preview() {
      // Open synchronously (inside the click) so popup blockers allow it, navigate after saving.
      const tab = window.open('about:blank', '_blank');
      if (tab) tab.opener = null;
      const saved = await saver.flush();
      if (!saved) toast('info', 'Preview shows the last saved version; your latest changes are not saved yet.');
      const url = `/preview/${pageId()}`;
      if (tab) tab.location.href = url;
      else window.open(url, '_blank', 'noopener');
    },

    async loadServerVersion() {
      try {
        const component = store.getState().page?.kind === 'component';
        const page = component ? await api.getComponent(pageId()) : await api.getPage(pageId());
        clearBackup(page.id, component ? 'component' : 'page');
        store.dispatch({ type: 'replaceDocument', doc: page.document, version: page.version });
        toast('info', 'Loaded the latest saved version.');
      } catch (error) {
        toast('error', error instanceof Error ? error.message : 'Could not load the page.');
      }
    },

    async keepMine() {
      const conflict = store.getState().save.conflict;
      store.dispatch({ type: 'saveReset', version: conflict?.currentVersion });
      if (await saver.flush(true)) toast('success', 'Your version was saved.');
    },

    download() {
      const state = store.getState();
      downloadDocument(state.history.present, state.page?.slug ?? 'page');
    },

    async saveGlobals() {
      const { globals } = store.getState();
      const issues = validateGlobals(globals.value);
      if (issues.length) {
        toast('error', `Global styles: ${issues[0]!.message}`);
        return;
      }
      store.dispatch({ type: 'globalsSaving', value: true });
      try {
        const saved = await api.saveGlobals(globals.value, globals.version);
        store.dispatch({ type: 'globalsSaved', version: saved.version });
        toast('success', `Global styles saved.${saved.rerendered ? ` ${saved.rerendered} published page(s) updated.` : ''}`);
      } catch (error) {
        store.dispatch({ type: 'globalsSaving', value: false });
        if (error instanceof ApiError && error.status === 409) {
          toast('error', 'Someone else changed the global styles. Discard your changes to load theirs.');
        } else if (error instanceof ApiError && error.body.errors?.length) {
          toast('error', `Global styles: ${error.body.errors[0]!.message}`);
        } else {
          toast('error', error instanceof Error ? error.message : 'The global styles could not be saved.');
        }
      }
    },

    async saveAsComponent(id, name) {
      const doc = store.getState().history.present;
      const fragment = extractFragment(doc, id);
      if (!fragment) return false;
      const document = componentDocument(doc, fragment);
      const issues = componentIssues(document);
      if (issues.length) {
        toast('error', issues[0]!.message);
        return false;
      }
      try {
        const created = await api.createComponent(name, document);
        store.dispatch({ type: 'componentLoaded', id: created.id, name: created.name, document: created.publishedDocument });
        const current = store.getState().history.present;
        const instance = createNode('component', generateId((candidate) => candidate in current.nodes));
        instance.props = { componentId: created.id };
        store.dispatch({ type: 'replace', id, fragment: { rootId: instance.id, nodes: { [instance.id]: instance } } });
        toast('success', `Saved as component “${created.name}”. Edit it once to change it everywhere it is used.`);
        return true;
      } catch (error) {
        const detail = error instanceof ApiError && error.body.errors?.length ? error.body.errors[0]!.message : null;
        toast('error', detail ?? (error instanceof Error ? error.message : 'The component could not be created.'));
        return false;
      }
    },

    detachComponent(id) {
      const state = store.getState();
      const node = state.history.present.nodes[id];
      const componentId = node?.props.componentId;
      const entry = typeof componentId === 'number' ? state.components[componentId] : undefined;
      if (!node || typeof entry !== 'object') {
        toast('error', 'The component is not loaded, so it cannot be detached.');
        return;
      }
      const doc = state.history.present;
      const fragment = detachedFragment(entry.document, (candidate) => candidate in doc.nodes);
      const before = doc;
      if (fragment) store.dispatch({ type: 'replace', id, fragment });
      if (store.getState().history.present === before) toast('error', 'The component content does not fit here (nesting too deep).');
      else toast('info', 'Detached: these elements no longer follow the component.');
    },

    editComponent(componentId) {
      window.open(`/editor/edit/?component=${componentId}`, '_blank', 'noopener');
      // Coming back to this tab later shows the latest published version.
      const refresh = () => {
        if (document.visibilityState === 'visible') {
          document.removeEventListener('visibilitychange', refresh);
          void loadComponent(store, componentId, true);
        }
      };
      document.addEventListener('visibilitychange', refresh);
    },

    async saveAsTemplate(id, name) {
      const doc = store.getState().history.present;
      let document = doc;
      if (id !== null) {
        const fragment = extractFragment(doc, id);
        if (!fragment) return false;
        document = componentDocument(doc, fragment);
      }
      try {
        await api.createTemplate(name, id === null ? 'page' : 'section', document);
        toast('success', `Saved “${name}” to the template library.`);
        return true;
      } catch (error) {
        const detail = error instanceof ApiError && error.body.errors?.length ? error.body.errors[0]!.message : null;
        toast('error', detail ?? (error instanceof Error ? error.message : 'The template could not be saved.'));
        return false;
      }
    },

    async reloadGlobals() {
      try {
        const loaded = await api.getGlobals();
        store.dispatch({ type: 'loadGlobals', globals: loaded.globals, version: loaded.version });
      } catch (error) {
        toast('error', error instanceof Error ? error.message : 'Could not load the global styles.');
      }
    },
  };
}
