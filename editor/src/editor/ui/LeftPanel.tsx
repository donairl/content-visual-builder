'use client';

import { useDraggable } from '@dnd-kit/core';
import { useEffect, useMemo, useState } from 'react';
import { ancestry } from '@/core/document';
import {
  config,
  controlStyleKeys,
  controlsConfig,
  ownStyleKeys,
  styleProperties,
  widgetOrder,
  widgets,
  type ControlSection,
} from '@/core/schema';
import type { ElementNode } from '@/core/types';
import { api, ApiError, type ComponentSummary } from '../api';
import { useDispatch, useDoc, useEditor, useNode, useStore } from '../context';
import { insertionPoint, widgetFragment } from '../insert';
import { useServices } from '../services';
import type { SettingsTab } from '../state';
import { Control, CustomCssInput } from './controls/ControlRenderer';
import { ColorInput, Field, NumberInput, TextInput } from './controls/fields';
import { FontInput } from './controls/styleControls';
import { GlobalsPanel } from './GlobalsPanel';
import { HistoryPanel } from './HistoryPanel';
import type { DragData } from './DragLayer';
import { UiIcon } from './UiIcon';

export function LeftPanel() {
  const tab = useEditor((s) => s.leftTab);
  const hasSelection = useEditor((s) => s.selectedId !== null);
  const dispatch = useDispatch();
  return (
    <aside className="ed-side ed-ui" aria-label="Editor panel">
      <div className="ed-tabs" role="tablist">
        <button type="button" role="tab" className="ed-tab" aria-selected={tab === 'elements'} onClick={() => dispatch({ type: 'setLeftTab', tab: 'elements' })}>
          <UiIcon name="grid" size={14} /> Elements
        </button>
        <button
          type="button"
          role="tab"
          className="ed-tab"
          aria-selected={tab === 'settings'}
          disabled={!hasSelection}
          onClick={() => dispatch({ type: 'setLeftTab', tab: 'settings' })}
        >
          <UiIcon name="sliders" size={14} /> Settings
        </button>
      </div>
      <div className="ed-side-body">
        {tab === 'elements' ? (
          <ElementsPanel />
        ) : tab === 'settings' ? (
          <SettingsPanel />
        ) : tab === 'globals' ? (
          <GlobalsPanel />
        ) : tab === 'history' ? (
          <HistoryPanel />
        ) : (
          <PageSettingsPanel />
        )}
      </div>
    </aside>
  );
}

/* ---------------------------------------------------------------- Elements */

function ElementsPanel() {
  const [query, setQuery] = useState('');
  const editingComponent = useEditor((s) => s.page?.kind === 'component');
  const needle = query.trim().toLowerCase();
  const matches = widgetOrder.filter((type) => {
    const m = widgets[type]!;
    return !needle || m.title.toLowerCase().includes(needle) || m.keywords.some((k) => k.includes(needle));
  });
  return (
    <>
      <div className="ed-search">
        <UiIcon name="search" size={14} />
        <input className="ed-input" type="search" placeholder="Search widgets…" aria-label="Search widgets" value={query} onChange={(e) => setQuery(e.target.value)} />
      </div>
      {controlsConfig.categories.map((category) => {
        const types = matches.filter((t) => widgets[t]!.category === category.id);
        if (!types.length) return null;
        return (
          <section className="ed-cat" key={category.id}>
            <h3>{category.label}</h3>
            <div className="ed-tiles">
              {types.map((type) => (
                <WidgetTile key={type} type={type} />
              ))}
            </div>
          </section>
        );
      })}
      {matches.length === 0 ? <p className="ed-empty-note">No widget matches “{query}”.</p> : null}
      {/* Components do not nest, so a component's own editor does not offer them. */}
      {editingComponent ? null : <ComponentTiles needle={needle} />}
    </>
  );
}

/** Saved components (README §10): click or drag to place a linked instance. */
function ComponentTiles({ needle }: { needle: string }) {
  const [items, setItems] = useState<ComponentSummary[] | null>(null);
  const [error, setError] = useState<string | null>(null);
  useEffect(() => {
    let cancelled = false;
    api
      .listComponents()
      .then((r) => !cancelled && setItems(r.items))
      .catch((e: unknown) => !cancelled && setError(e instanceof Error ? e.message : 'Could not load components.'));
    return () => {
      cancelled = true;
    };
  }, []);
  const shown = (items ?? []).filter((c) => !needle || c.name.toLowerCase().includes(needle));
  return (
    <section className="ed-cat">
      <h3>Components</h3>
      {error ? <p className="ed-field-error">{error}</p> : null}
      {items === null && !error ? <p className="ed-empty-note">Loading…</p> : null}
      {items?.length === 0 ? <p className="ed-empty-note">No components yet. Right-click an element and choose “Save as component”.</p> : null}
      <div className="ed-tiles">
        {shown.map((c) => (
          <ComponentTile key={c.id} component={c} />
        ))}
      </div>
    </section>
  );
}

function ComponentTile({ component }: { component: ComponentSummary }) {
  const store = useStore();
  const props = { componentId: component.id };
  const data: DragData = { kind: 'new', type: 'component', props };
  const { setNodeRef, listeners, attributes } = useDraggable({ id: `component:${component.id}`, data });
  const add = () => {
    const state = store.getState();
    const at = insertionPoint(state.history.present, state.selectedId);
    const { fragment, widgetId } = widgetFragment(state.history.present, 'component', at.parentId, props);
    store.dispatch({ type: 'insert', fragment, parentId: at.parentId, index: at.index, selectId: widgetId });
  };
  return (
    <button type="button" ref={setNodeRef} className="ed-tile ed-tile--component" {...attributes} {...listeners} onClick={add} title={`Place “${component.name}”`}>
      <UiIcon name="box" size={22} />
      <span>{component.name}</span>
    </button>
  );
}

function WidgetTile({ type }: { type: string }) {
  const store = useStore();
  const manifest = widgets[type]!;
  const data: DragData = { kind: 'new', type };
  const { setNodeRef, listeners, attributes } = useDraggable({ id: `new:${type}`, data });
  const add = () => {
    const state = store.getState();
    const at = insertionPoint(state.history.present, state.selectedId);
    const { fragment, widgetId } = widgetFragment(state.history.present, type, at.parentId);
    store.dispatch({ type: 'insert', fragment, parentId: at.parentId, index: at.index, selectId: widgetId });
  };
  return (
    <button
      type="button"
      ref={setNodeRef}
      className="ed-tile"
      {...attributes}
      {...listeners}
      onClick={add}
      title={`Drag onto the page, or click to add ${manifest.title}`}
    >
      <UiIcon name={manifest.icon} size={22} />
      {manifest.title}
    </button>
  );
}

/* ---------------------------------------------------------------- Settings */

function commonSections(type: string): ControlSection[] {
  const own = ownStyleKeys(type);
  return controlsConfig.commonAdvanced
    .map((section) => ({
      ...section,
      controls: section.controls.filter((c) => !controlStyleKeys(c).some((k) => own.has(k))),
    }))
    .filter((section) => section.controls.length > 0);
}

function SettingsPanel() {
  const selectedId = useEditor((s) => s.selectedId);
  const node = useNode(selectedId);
  const tab = useEditor((s) => s.settingsTab);
  const device = useEditor((s) => s.device);
  const dispatch = useDispatch();
  if (!node) return <p className="ed-empty-note">Select an element on the canvas to edit it.</p>;
  const manifest = widgets[node.type];
  if (!manifest) return <p className="ed-empty-note">Unknown element type “{node.type}”.</p>;

  const sections =
    tab === 'content' ? manifest.controls.content : tab === 'style' ? manifest.controls.style : commonSections(node.type);
  const tabs: [SettingsTab, string][] = [
    ['content', 'Content'],
    ['style', 'Style'],
    ['advanced', 'Advanced'],
  ];

  return (
    <>
      <div className="ed-side-head">
        <UiIcon name={manifest.icon} size={16} />
        <h2>Edit {manifest.title}</h2>
        <button type="button" className="ed-icon-btn" title="Close" aria-label="Close settings" onClick={() => dispatch({ type: 'select', id: null })}>
          <UiIcon name="x" size={14} />
        </button>
      </div>
      <Breadcrumbs node={node} />
      <div className="ed-subtabs" role="tablist" aria-label="Settings tabs">
        {tabs.map(([id, label]) => (
          <button key={id} type="button" role="tab" className="ed-subtab" aria-selected={tab === id} onClick={() => dispatch({ type: 'setSettingsTab', tab: id })}>
            {label}
          </button>
        ))}
      </div>
      {sections.length === 0 ? (
        <p className="ed-empty-note">No {tab} settings for this element.</p>
      ) : (
        sections.map((section, i) => (
          <Section key={`${node.type}:${tab}:${section.section}`} title={section.section} defaultOpen={i === 0}>
            {section.controls.map((spec, j) => (
              <Control key={`${spec.prop ?? spec.style ?? spec.group ?? spec.advanced}:${j}`} node={node} spec={spec} device={device} />
            ))}
          </Section>
        ))
      )}
    </>
  );
}

function Breadcrumbs({ node }: { node: ElementNode }) {
  const doc = useDoc();
  const dispatch = useDispatch();
  const path = useMemo(() => ancestry(doc, node.id), [doc, node.id]);
  if (path.length < 2) return null;
  return (
    <nav className="ed-crumbs" aria-label="Element path">
      {path.map((id, i) => {
        const n = doc.nodes[id];
        const title = n ? (widgets[n.type]?.title ?? n.type) : id;
        return (
          <span key={id}>
            {i > 0 ? <UiIcon name="chevron-right" size={10} /> : null}
            <button type="button" aria-current={id === node.id ? 'true' : undefined} onClick={() => dispatch({ type: 'select', id })}>
              {title}
            </button>
          </span>
        );
      })}
    </nav>
  );
}

export function Section({ title, defaultOpen, children }: { title: string; defaultOpen?: boolean; children: React.ReactNode }) {
  const [open, setOpen] = useState(!!defaultOpen);
  return (
    <section className="ed-section">
      <button type="button" className="ed-section-head" aria-expanded={open} onClick={() => setOpen(!open)}>
        <UiIcon name="chevron-down" size={14} />
        {title}
      </button>
      {open ? <div className="ed-section-body">{children}</div> : null}
    </section>
  );
}

/* ---------------------------------------------------------------- Page settings */

function PageSettingsPanel() {
  const page = useEditor((s) => s.page);
  const settings = useDoc().settings;
  const dispatch = useDispatch();
  const services = useServices();
  const [title, setTitle] = useState(page?.title ?? '');
  const [slug, setSlug] = useState(page?.slug ?? '');
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState<string | null>(null);
  if (!page) return null;

  const saveMeta = async () => {
    setSaving(true);
    setError(null);
    try {
      const summary = await api.updatePage(page.id, { title: title.trim(), slug: slug.trim() });
      dispatch({ type: 'setPage', page: { title: summary.title, slug: summary.slug, url: summary.url } });
      dispatch({ type: 'toast', kind: 'success', message: 'Page details saved.' });
    } catch (e) {
      const detail = e instanceof ApiError && e.body.errors?.length ? e.body.errors.map((i) => i.message).join(' ') : null;
      setError(detail ?? (e instanceof Error ? e.message : 'Could not save.'));
    } finally {
      setSaving(false);
    }
  };

  const set = <K extends keyof typeof settings>(key: K, value: (typeof settings)[K]) =>
    dispatch({ type: 'setSettings', key, value, mergeKey: `settings:${key}` });
  const spec = config.settings;

  return (
    <>
      <div className="ed-side-head">
        <UiIcon name="page" size={16} />
        <h2>Page settings</h2>
        <button type="button" className="ed-icon-btn" aria-label="Close page settings" onClick={() => dispatch({ type: 'setLeftTab', tab: 'elements' })}>
          <UiIcon name="x" size={14} />
        </button>
      </div>
      <Section title="Page" defaultOpen>
        <Field label="Title">
          <TextInput ariaLabel="Page title" value={title} onChange={setTitle} validate={(v) => (v.trim().length >= 1 && v.length <= 255 ? null : 'Enter a title.')} />
        </Field>
        <Field label="URL slug" hint={`Public address: /${slug || '…'}`}>
          <TextInput ariaLabel="URL slug" value={slug} onChange={setSlug} validate={(v) => (/^[a-z0-9]+(?:-[a-z0-9]+)*$/.test(v) ? null : 'Lowercase letters, digits and single dashes.')} />
        </Field>
        {error ? <div className="ed-field-error" role="alert">{error}</div> : null}
        <div className="ed-field-row">
          <button type="button" className="ed-btn ed-btn--primary ed-btn--sm" disabled={saving || (title === page.title && slug === page.slug)} onClick={() => void saveMeta()}>
            {saving ? 'Saving…' : 'Save details'}
          </button>
          <button type="button" className="ed-btn ed-btn--sm" onClick={() => dispatch({ type: 'openDialog', kind: 'saveTemplate', id: null })}>
            Save as template
          </button>
          {page.status === 'published' && services.can('publish_pages') ? (
            <button type="button" className="ed-btn ed-btn--sm ed-btn--danger" onClick={() => void services.unpublish()}>
              Unpublish
            </button>
          ) : null}
        </div>
      </Section>
      <Section title="SEO" defaultOpen>
        <Field label="Meta description" hint="Shown by search engines. Up to 300 characters.">
          <TextInput ariaLabel="Meta description" multiline value={settings.metaDescription ?? ''} maxLength={spec.metaDescription?.maxLength} onChange={(v) => set('metaDescription', v)} />
        </Field>
      </Section>
      <Section title="Layout & style" defaultOpen>
        <Field label="Content width (px)" hint="Width of boxed containers.">
          <NumberInput ariaLabel="Content width" value={settings.contentWidth} min={spec.contentWidth?.min} max={spec.contentWidth?.max} integer onChange={(n) => set('contentWidth', n ?? 1140)} />
        </Field>
        <Field label="Background color">
          <ColorInput ariaLabel="Page background color" value={settings.backgroundColor} onChange={(c) => set('backgroundColor', c)} />
        </Field>
        <Field label="Text color">
          <ColorInput ariaLabel="Page text color" value={settings.textColor} onChange={(c) => set('textColor', c)} />
        </Field>
        <Field label="Font">
          <FontInput
            prop={styleProperties.fontFamily!}
            styleKey="fontFamily"
            label="Page font"
            value={settings.fontFamily ?? undefined}
            inherited={undefined}
            onChange={(f) => set('fontFamily', f)}
          />
        </Field>
      </Section>
      <Section title="Custom CSS">
        <Field label="Page CSS" hint="Applies inside this page only. Selectors are relative to the page (h2 { … }); & is the page root.">
          <CustomCssInput
            ariaLabel="Page custom CSS"
            value={settings.customCss ?? ''}
            maxLength={spec.customCss?.maxLength ?? 0}
            placeholder={'h2 { letter-spacing: .02em }\n.cta a { text-decoration: none }'}
            onChange={(v) => set('customCss', v)}
          />
        </Field>
      </Section>
    </>
  );
}

