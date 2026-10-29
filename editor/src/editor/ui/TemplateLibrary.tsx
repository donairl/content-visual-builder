'use client';

/**
 * Template library (README §11): built-in and saved sections and pages, with a live preview.
 * Inserting copies the template (fresh ids) as one undoable edit; the template itself is never
 * linked to the page.
 */
import { useEffect, useLayoutEffect, useMemo, useRef, useState } from 'react';
import { generateCss } from '@/core/css';
import type { PageDocument } from '@/core/types';
import { PublishedTree } from '@/render/content';
import { api } from '../api';
import { useDispatch, useEditor, useSite, useStore } from '../context';
import { BUILT_IN_TEMPLATES, sectionIndex, withTemplate, type LibraryTemplate } from '../templates';
import { UiIcon } from './UiIcon';

const PREVIEW_WIDTH = 1200;

export function TemplateLibrary() {
  const open = useEditor((s) => s.templatesOpen);
  return open ? <LibraryDialog /> : null;
}

function LibraryDialog() {
  const dispatch = useDispatch();
  const store = useStore();
  const [tab, setTab] = useState<'section' | 'page'>('section');
  const [saved, setSaved] = useState<LibraryTemplate[] | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [selected, setSelected] = useState<LibraryTemplate | null>(null);
  const [document, setDocument] = useState<PageDocument | null>(null);
  const close = () => dispatch({ type: 'toggleTemplates', open: false });

  useEffect(() => {
    api
      .listTemplates()
      .then((r) => setSaved(r.items.map((t) => ({ key: `saved:${t.id}`, name: t.name, kind: t.kind, savedId: t.id, authorName: t.authorName }))))
      .catch((e: unknown) => setError(e instanceof Error ? e.message : 'Could not load saved templates.'));
    const onKey = (e: KeyboardEvent) => e.key === 'Escape' && dispatch({ type: 'toggleTemplates', open: false });
    window.addEventListener('keydown', onKey);
    return () => window.removeEventListener('keydown', onKey);
  }, [dispatch]);

  // Load the chosen template's document (built-ins already have it).
  useEffect(() => {
    setDocument(selected?.document ?? null);
    if (!selected || selected.document || selected.savedId === undefined) return;
    let cancelled = false;
    api
      .getTemplate(selected.savedId)
      .then((t) => !cancelled && setDocument(t.document))
      .catch((e: unknown) => !cancelled && setError(e instanceof Error ? e.message : 'Could not load the template.'));
    return () => {
      cancelled = true;
    };
  }, [selected]);

  const builtIns = BUILT_IN_TEMPLATES.filter((t) => t.kind === tab);
  const mine = (saved ?? []).filter((t) => t.kind === tab);

  const insert = (replace: boolean) => {
    if (!document) return;
    const state = store.getState();
    const doc = state.history.present;
    const base = replace ? { ...doc, rootIds: [], nodes: {} } : doc;
    const index = replace ? 0 : selected?.kind === 'page' ? doc.rootIds.length : sectionIndex(doc, state.selectedId);
    const next = withTemplate(base, document, index);
    if (!next) {
      dispatch({ type: 'toast', kind: 'error', message: 'The template does not fit on this page (element limit).' });
      return;
    }
    dispatch({ type: 'restore', doc: next });
    dispatch({ type: 'select', id: next.rootIds[index] ?? null });
    dispatch({ type: 'toast', kind: 'success', message: `Inserted “${selected?.name}”. Press Ctrl+Z to undo.` });
    close();
  };

  const tile = (t: LibraryTemplate) => (
    <li key={t.key}>
      <button type="button" aria-pressed={selected?.key === t.key} onClick={() => setSelected(t)}>
        <strong>{t.name}</strong>
        <small>{t.description ?? (t.authorName ? `Saved by ${t.authorName}` : 'Saved template')}</small>
      </button>
    </li>
  );

  return (
    <div className="ed-overlay ed-ui" role="presentation" onClick={close}>
      <div className="ed-library" role="dialog" aria-modal="true" aria-labelledby="ed-library-title" onClick={(e) => e.stopPropagation()}>
        <header>
          <UiIcon name="folder" size={18} />
          <h2 id="ed-library-title">Template library</h2>
          <div className="ed-subtabs" role="tablist" aria-label="Template type">
            {(['section', 'page'] as const).map((kind) => (
              <button
                key={kind}
                type="button"
                role="tab"
                className="ed-subtab"
                aria-selected={tab === kind}
                onClick={() => {
                  setTab(kind);
                  setSelected(null);
                }}
              >
                {kind === 'section' ? 'Sections' : 'Pages'}
              </button>
            ))}
          </div>
          <button type="button" className="ed-icon-btn" aria-label="Close template library" onClick={close}>
            <UiIcon name="x" size={16} />
          </button>
        </header>
        <div className="ed-library-body">
          <nav aria-label="Templates">
            <h3>Built-in</h3>
            <ul>{builtIns.map(tile)}</ul>
            <h3>Saved</h3>
            {error ? <p className="ed-field-error">{error}</p> : null}
            {saved === null && !error ? <p className="ed-empty-note">Loading…</p> : null}
            {saved !== null && mine.length === 0 ? (
              <p className="ed-empty-note">
                {tab === 'section' ? 'Right-click a section and choose “Save as template”.' : 'Use “Save page as template” in Page settings.'}
              </p>
            ) : null}
            <ul>{mine.map(tile)}</ul>
          </nav>
          <section className="ed-library-preview" aria-label="Preview">
            {document ? <TemplatePreview doc={document} /> : <p className="ed-empty-note">Choose a template to preview it.</p>}
          </section>
        </div>
        <footer>
          {selected?.kind === 'page' ? (
            <button type="button" className="ed-btn" disabled={!document} onClick={() => insert(true)} title="Removes the page content first (undoable)">
              Replace page content
            </button>
          ) : null}
          <button type="button" className="ed-btn ed-btn--primary" disabled={!document} onClick={() => insert(false)}>
            {selected?.kind === 'page' ? 'Add to page' : 'Insert section'}
          </button>
        </footer>
      </div>
    </div>
  );
}

/** The template rendered at desktop width and scaled down to fit the preview pane. */
function TemplatePreview({ doc }: { doc: PageDocument }) {
  const site = useSite();
  const outer = useRef<HTMLDivElement>(null);
  const inner = useRef<HTMLDivElement>(null);
  const [box, setBox] = useState({ scale: 0.4, height: 0 });
  // The page-level rules (settings, page custom CSS) target `.cvb-root`, which would also restyle
  // the canvas behind the dialog: render without them.
  const css = useMemo(() => generateCss({ ...doc, settings: {} as PageDocument['settings'] }, { kind: 'editor', device: 'desktop' }, { site }), [doc, site]);

  useLayoutEffect(() => {
    const measure = () => {
      const width = outer.current?.clientWidth ?? 0;
      const scale = width / PREVIEW_WIDTH;
      setBox({ scale, height: (inner.current?.offsetHeight ?? 0) * scale });
    };
    measure();
    const observer = new ResizeObserver(measure);
    if (outer.current) observer.observe(outer.current);
    if (inner.current) observer.observe(inner.current);
    return () => observer.disconnect();
  }, [doc]);

  return (
    <div className="ed-tpl-frame" ref={outer} style={{ height: box.height }}>
      <style>{css}</style>
      <div className="cvb-root ed-tpl-scale" ref={inner} style={{ width: PREVIEW_WIDTH, transform: `scale(${box.scale})` }} inert>
        <PublishedTree doc={doc} site={site} mode="editor" />
      </div>
    </div>
  );
}
