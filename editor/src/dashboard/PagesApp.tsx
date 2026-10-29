'use client';

/**
 * Dashboard: page management (FR-01) — list, create, rename, delete — plus the components and
 * templates libraries. Every action is re-checked by the server.
 */
import { useCallback, useEffect, useState } from 'react';
import { ApiError, api, getSession, loginUrl, type PageSummary, type SessionInfo } from '@/editor/api';
import { UiIcon } from '@/editor/ui/UiIcon';
import { ComponentsList, TemplatesList } from './LibraryLists';

type Dialog =
  | { kind: 'create' }
  | { kind: 'rename'; page: PageSummary }
  | { kind: 'delete'; page: PageSummary }
  | null;

const SLUG = /^[a-z0-9]+(?:-[a-z0-9]+)*$/;

type Section = 'pages' | 'components' | 'templates';
const SECTIONS: [Section, string, string][] = [
  ['pages', 'Pages', 'page'],
  ['components', 'Components', 'box'],
  ['templates', 'Templates', 'layers'],
];
const sectionFromHash = (): Section => {
  const hash = typeof window === 'undefined' ? '' : window.location.hash.slice(1);
  return SECTIONS.some(([id]) => id === hash) ? (hash as Section) : 'pages';
};

function messageOf(error: unknown): string {
  if (error instanceof ApiError) {
    return error.body.errors?.length ? error.body.errors.map((e) => e.message).join(' ') : error.message;
  }
  return error instanceof Error ? error.message : 'Something went wrong.';
}

export function PagesApp() {
  const [session, setSession] = useState<SessionInfo | null>(null);
  const [pages, setPages] = useState<PageSummary[] | null>(null);
  const [query, setQuery] = useState('');
  const [error, setError] = useState<string | null>(null);
  const [dialog, setDialog] = useState<Dialog>(null);
  const [section, setSection] = useState<Section>('pages');

  useEffect(() => {
    const sync = () => setSection(sectionFromHash());
    sync();
    window.addEventListener('hashchange', sync);
    return () => window.removeEventListener('hashchange', sync);
  }, []);

  const load = useCallback(async (q: string) => {
    try {
      setPages((await api.listPages(q)).items);
      setError(null);
    } catch (e) {
      if (e instanceof ApiError && e.status === 401) window.location.href = loginUrl();
      else setError(messageOf(e));
    }
  }, []);

  useEffect(() => {
    getSession()
      .then(setSession)
      .catch((e: unknown) => {
        if (e instanceof ApiError && e.status === 401) window.location.href = loginUrl();
        else setError(messageOf(e));
      });
  }, []);

  useEffect(() => {
    const timer = setTimeout(() => void load(query), 200);
    return () => clearTimeout(timer);
  }, [query, load]);

  const can = (capability: string) => session?.capabilities.includes(capability) ?? false;
  const canEdit = (page: PageSummary) => can('edit_pages') && (page.authorId === session?.user.id || can('edit_others_pages'));
  const canDelete = (page: PageSummary) => can('delete_pages') && (page.authorId === session?.user.id || can('delete_others_pages'));

  const logout = async () => {
    try {
      const { csrfToken } = await getSession();
      await fetch('/logout', { method: 'POST', headers: { 'X-CSRF-Token': csrfToken }, credentials: 'same-origin' });
    } finally {
      window.location.href = '/login';
    }
  };

  return (
    <div className="pg ed-ui">
      <header className="pg-top">
        <strong className="pg-brand">
          <UiIcon name="grid" size={18} /> Content Visual Builder
        </strong>
        <span className="pg-spacer" />
        {session ? (
          <span className="pg-user">
            {session.user.name} · {session.user.role}
          </span>
        ) : null}
        <button type="button" className="ed-btn ed-btn--sm" onClick={() => void logout()}>
          Sign out
        </button>
      </header>
      <nav className="pg-tabs" aria-label="Sections">
        {SECTIONS.map(([id, label, icon]) => (
          <a key={id} href={`#${id}`} aria-current={section === id ? 'page' : undefined} onClick={() => setSection(id)}>
            <UiIcon name={icon} size={14} /> {label}
          </a>
        ))}
      </nav>
      <main className="pg-main">
        {section === 'components' ? (
          <ComponentsList session={session} />
        ) : section === 'templates' ? (
          <TemplatesList session={session} />
        ) : (
          <>
            <div className="pg-head">
              <h1>Pages</h1>
              <div className="pg-search">
                <UiIcon name="search" size={14} />
                <input className="ed-input" type="search" placeholder="Search pages…" aria-label="Search pages" value={query} onChange={(e) => setQuery(e.target.value)} />
              </div>
              {can('edit_pages') ? (
                <button type="button" className="ed-btn ed-btn--primary" onClick={() => setDialog({ kind: 'create' })}>
                  <UiIcon name="plus" size={14} /> New page
                </button>
              ) : null}
            </div>
            {error ? (
              <p className="pg-error" role="alert">
                {error}
              </p>
            ) : null}
            {pages === null ? (
              <p className="pg-note">Loading…</p>
            ) : pages.length === 0 ? (
              <div className="pg-empty">
                <UiIcon name="page" size={32} />
                <p>{query ? `No page matches “${query}”.` : 'No pages yet. Create your first landing page.'}</p>
              </div>
            ) : (
              <table className="pg-table">
                <thead>
                  <tr>
                    <th>Title</th>
                    <th>Status</th>
                    <th>Author</th>
                    <th>Updated</th>
                    <th aria-label="Actions" />
                  </tr>
                </thead>
                <tbody>
                  {pages.map((page) => (
                    <tr key={page.id}>
                      <td>
                        {canEdit(page) ? (
                          <a className="pg-title" href={`/editor/edit/?page=${page.id}`}>
                            {page.title}
                          </a>
                        ) : (
                          <span className="pg-title">{page.title}</span>
                        )}
                        <div className="pg-slug">/{page.slug}</div>
                      </td>
                      <td>
                        <span className={`pg-status pg-status--${page.status}`}>{page.status === 'published' ? 'Published' : 'Draft'}</span>
                        {page.status === 'published' && page.hasUnpublishedChanges ? <span className="pg-changes">unpublished changes</span> : null}
                      </td>
                      <td>{page.authorName ?? '—'}</td>
                      <td>{new Date(page.updatedAt).toLocaleString()}</td>
                      <td className="pg-actions">
                        {canEdit(page) ? (
                          <a className="ed-btn ed-btn--sm" href={`/editor/edit/?page=${page.id}`}>
                            Edit
                          </a>
                        ) : null}
                        {page.status === 'published' ? (
                          <a className="ed-btn ed-btn--sm" href={page.url} target="_blank" rel="noopener">
                            View <UiIcon name="external" size={12} />
                          </a>
                        ) : null}
                        {canEdit(page) ? (
                          <button type="button" className="ed-btn ed-btn--sm" onClick={() => setDialog({ kind: 'rename', page })}>
                            Rename
                          </button>
                        ) : null}
                        {canDelete(page) ? (
                          <button type="button" className="ed-btn ed-btn--sm ed-btn--danger" onClick={() => setDialog({ kind: 'delete', page })}>
                            Delete
                          </button>
                        ) : null}
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            )}
          </>
        )}
      </main>
      {dialog ? <PageDialog dialog={dialog} onClose={() => setDialog(null)} onDone={() => void load(query)} /> : null}
    </div>
  );
}

function PageDialog({ dialog, onClose, onDone }: { dialog: NonNullable<Dialog>; onClose: () => void; onDone: () => void }) {
  const page = dialog.kind === 'create' ? null : dialog.page;
  const [title, setTitle] = useState(page?.title ?? '');
  const [slug, setSlug] = useState(page?.slug ?? '');
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    const onKey = (e: KeyboardEvent) => e.key === 'Escape' && onClose();
    window.addEventListener('keydown', onKey);
    return () => window.removeEventListener('keydown', onKey);
  }, [onClose]);

  const submit = async (event: React.FormEvent) => {
    event.preventDefault();
    if (dialog.kind !== 'delete' && slug && !SLUG.test(slug)) {
      setError('The slug may contain lowercase letters, digits and single dashes.');
      return;
    }
    setBusy(true);
    setError(null);
    try {
      if (dialog.kind === 'create') {
        const created = await api.createPage(title.trim(), slug.trim() || undefined);
        window.location.href = `/editor/edit/?page=${created.id}`;
        return;
      }
      if (dialog.kind === 'rename') await api.updatePage(dialog.page.id, { title: title.trim(), slug: slug.trim() });
      if (dialog.kind === 'delete') await api.deletePage(dialog.page.id);
      onDone();
      onClose();
    } catch (e) {
      setError(messageOf(e));
    } finally {
      setBusy(false);
    }
  };

  const heading = dialog.kind === 'create' ? 'New page' : dialog.kind === 'rename' ? 'Rename page' : 'Delete page?';
  return (
    <div className="ed-overlay" role="presentation" onClick={onClose}>
      <form className="ed-dialog" role="dialog" aria-modal="true" aria-labelledby="pg-dialog-title" onClick={(e) => e.stopPropagation()} onSubmit={(e) => void submit(e)}>
        <h2 id="pg-dialog-title">{heading}</h2>
        {dialog.kind === 'delete' ? (
          <p>
            “{dialog.page.title}” will be deleted permanently{dialog.page.status === 'published' ? ' and its public URL will stop working' : ''}. This cannot be undone.
          </p>
        ) : (
          <>
            <label className="ed-field">
              <span className="ed-field-label">Title</span>
              <input className="ed-input" autoFocus required maxLength={255} value={title} onChange={(e) => setTitle(e.target.value)} />
            </label>
            <label className="ed-field">
              <span className="ed-field-label">URL slug {dialog.kind === 'create' ? '(optional)' : ''}</span>
              <input className="ed-input" value={slug} placeholder="generated from the title" onChange={(e) => setSlug(e.target.value.toLowerCase())} />
            </label>
          </>
        )}
        {error ? (
          <p className="pg-error" role="alert">
            {error}
          </p>
        ) : null}
        <footer>
          <button type="button" className="ed-btn" onClick={onClose}>
            Cancel
          </button>
          <button type="submit" className={`ed-btn ${dialog.kind === 'delete' ? 'ed-btn--danger' : 'ed-btn--primary'}`} disabled={busy || (dialog.kind !== 'delete' && !title.trim())}>
            {busy ? 'Working…' : dialog.kind === 'create' ? 'Create & edit' : dialog.kind === 'rename' ? 'Save' : 'Delete'}
          </button>
        </footer>
      </form>
    </div>
  );
}
