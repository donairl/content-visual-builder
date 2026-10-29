'use client';

import { useSearchParams } from 'next/navigation';
import { useEffect, useState } from 'react';
import { ApiError, api, getSession, loginUrl, type ComponentDetail, type PageDetail } from './api';
import { createSaver, useUnloadGuard, type Saver } from './autosave';
import { clearBackup, readBackup, type Backup } from './backup';
import { useComponentLoader } from './components';
import { createEditorStore, EditorProvider, useEditor, type EditorStore } from './context';
import { createServices, ServicesProvider, type EditorServices } from './services';
import { useShortcuts } from './shortcuts';
import type { PageMeta } from './state';
import { Banners, Toasts } from './ui/Banners';
import { GlobalColorsContext } from './ui/controls/fields';
import { Canvas } from './ui/Canvas';
import { ContextMenu } from './ui/ContextMenu';
import { Dialogs } from './ui/Dialogs';
import { TemplateLibrary } from './ui/TemplateLibrary';
import { DragLayer } from './ui/DragLayer';
import { LeftPanel } from './ui/LeftPanel';
import { Navigator } from './ui/Navigator';
import { TopBar } from './ui/TopBar';
import { UiIcon } from './ui/UiIcon';

export function toPageMeta(page: Omit<PageDetail, 'document'>): PageMeta {
  return {
    kind: 'page',
    id: page.id,
    title: page.title,
    slug: page.slug,
    status: page.status,
    url: page.url,
    publishedVersion: page.publishedVersion,
    hasUnpublishedChanges: page.hasUnpublishedChanges,
    publishedAt: page.publishedAt,
  };
}

/** A component edited like a page (README §10): no slug or URL, always published. */
export function toComponentMeta(component: Omit<ComponentDetail, 'document' | 'publishedDocument'>): PageMeta {
  return {
    kind: 'component',
    id: component.id,
    title: component.name,
    slug: '',
    status: 'published',
    url: '',
    publishedVersion: component.publishedVersion,
    hasUnpublishedChanges: component.hasUnpublishedChanges,
    publishedAt: component.publishedAt,
  };
}

type Phase =
  | { kind: 'loading' }
  | { kind: 'error'; message: string }
  | { kind: 'ready'; services: EditorServices; backup: Backup | null; serverVersion: number };

export function EditorApp() {
  const params = useSearchParams();
  const componentId = Number(params.get('component'));
  const kind = Number.isInteger(componentId) && componentId > 0 ? 'component' : 'page';
  const pageId = kind === 'component' ? componentId : Number(params.get('page'));
  const [store] = useState(() => createEditorStore());
  const [phase, setPhase] = useState<Phase>({ kind: 'loading' });

  useEffect(() => {
    if (!Number.isInteger(pageId) || pageId <= 0) {
      setPhase({ kind: 'error', message: 'No page was selected.' });
      return;
    }
    let cancelled = false;
    let saver: Saver | null = null;
    void (async () => {
      try {
        const session = await getSession();
        const [loaded, globals] = await Promise.all([kind === 'component' ? api.getComponent(pageId) : api.getPage(pageId), api.getGlobals()]);
        if (cancelled) return;
        const meta = 'name' in loaded ? toComponentMeta(loaded) : toPageMeta(loaded);
        store.dispatch({ type: 'loadGlobals', globals: globals.globals, version: globals.version });
        store.dispatch({ type: 'load', doc: loaded.document, page: meta, version: loaded.version });
        saver = createSaver(store);
        setPhase({ kind: 'ready', services: createServices(store, saver, session), backup: readBackup(pageId, kind), serverVersion: loaded.version });
      } catch (error) {
        if (cancelled) return;
        if (error instanceof ApiError && error.status === 401) {
          window.location.href = loginUrl();
          return;
        }
        setPhase({ kind: 'error', message: error instanceof Error ? error.message : 'The page could not be loaded.' });
      }
    })();
    return () => {
      cancelled = true;
      saver?.dispose();
    };
  }, [pageId, kind, store]);

  if (phase.kind === 'loading') return <div className="ed-center">Loading editor…</div>;
  if (phase.kind === 'error') {
    return (
      <div className="ed-center">
        <p>{phase.message}</p>
        <a href="/editor/">Back to pages</a>
      </div>
    );
  }
  return (
    <EditorProvider store={store}>
      <ServicesProvider services={phase.services}>
        <EditorShell store={store} services={phase.services} backup={phase.backup} serverVersion={phase.serverVersion} />
      </ServicesProvider>
    </EditorProvider>
  );
}

function EditorShell({ store, services, backup, serverVersion }: { store: EditorStore; services: EditorServices; backup: Backup | null; serverVersion: number }) {
  const [pendingBackup, setPendingBackup] = useState(backup);
  useShortcuts(store, services.saver);
  useUnloadGuard(store);
  useComponentLoader(store);

  const restore = pendingBackup ? (
    <div className="ed-banner" role="alert">
      <UiIcon name="refresh" />
      <p>
        <strong>Unsaved changes from {new Date(pendingBackup.savedAt).toLocaleString()} were found in this browser.</strong>{' '}
        {pendingBackup.baseVersion !== serverVersion ? 'The page was saved elsewhere since then — restoring will replace that version when saved.' : ''}
      </p>
      <button
        type="button"
        className="ed-btn ed-btn--sm"
        onClick={() => {
          clearBackup(pendingBackup.pageId, pendingBackup.kind ?? 'page');
          setPendingBackup(null);
        }}
      >
        Discard
      </button>
      <button
        type="button"
        className="ed-btn ed-btn--sm ed-btn--primary"
        onClick={() => {
          store.dispatch({ type: 'restore', doc: pendingBackup.document });
          setPendingBackup(null);
        }}
      >
        Restore
      </button>
    </div>
  ) : null;

  return (
    <GlobalColorsProvider>
      <div className="ed">
        <DragLayer>
          <TopBar />
          <Banners restore={restore} />
          <LeftPanel />
          <Canvas />
        </DragLayer>
        <Navigator />
        <ContextMenu />
        <Dialogs />
        <TemplateLibrary />
        <Toasts />
      </div>
    </GlobalColorsProvider>
  );
}

function GlobalColorsProvider({ children }: { children: React.ReactNode }) {
  const colors = useEditor((s) => s.globals.value.colors);
  return <GlobalColorsContext.Provider value={colors}>{children}</GlobalColorsContext.Provider>;
}
