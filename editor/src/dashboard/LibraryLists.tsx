'use client';

/**
 * Dashboard lists of site-wide reusable things: components (README §10) and templates (§11).
 * Every action is re-checked by the server; capabilities here only hide what would be refused.
 */
import { useCallback, useEffect, useState, type ReactNode } from 'react';
import { ApiError, api, type ComponentSummary, type SessionInfo, type TemplateSummary } from '@/editor/api';
import { UiIcon } from '@/editor/ui/UiIcon';

export function messageOf(error: unknown): string {
  if (error instanceof ApiError) {
    return error.body.errors?.length ? error.body.errors.map((e) => e.message).join(' ') : error.message;
  }
  return error instanceof Error ? error.message : 'Something went wrong.';
}

type Owned = { authorId: number };

function useOwnership(session: SessionInfo | null) {
  const can = (capability: string) => session?.capabilities.includes(capability) ?? false;
  const own = (item: Owned) => item.authorId === session?.user.id;
  return {
    canEdit: (item: Owned) => can('edit_pages') && (own(item) || can('edit_others_pages')),
    canDelete: (item: Owned) => can('delete_pages') && (own(item) || can('delete_others_pages')),
  };
}

type Action<T> = { kind: 'rename'; item: T } | { kind: 'delete'; item: T } | null;

export function ComponentsList({ session }: { session: SessionInfo | null }) {
  const [items, setItems] = useState<ComponentSummary[] | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [action, setAction] = useState<Action<ComponentSummary>>(null);
  const { canEdit, canDelete } = useOwnership(session);

  const load = useCallback(async () => {
    try {
      setItems((await api.listComponents()).items);
      setError(null);
    } catch (e) {
      setError(messageOf(e));
    }
  }, []);
  useEffect(() => void load(), [load]);

  return (
    <>
      <div className="pg-head">
        <h1>Components</h1>
      </div>
      <p className="pg-note">
        Reusable elements: editing and publishing a component updates every page that uses it. Create one from the editor: right-click an element and
        choose “Save as component”.
      </p>
      {error ? (
        <p className="pg-error" role="alert">
          {error}
        </p>
      ) : null}
      {items === null ? (
        <p className="pg-note">Loading…</p>
      ) : items.length === 0 ? (
        <Empty icon="box">No components yet.</Empty>
      ) : (
        <table className="pg-table">
          <thead>
            <tr>
              <th>Name</th>
              <th>Status</th>
              <th>Author</th>
              <th>Updated</th>
              <th aria-label="Actions" />
            </tr>
          </thead>
          <tbody>
            {items.map((item) => (
              <tr key={item.id}>
                <td>
                  <span className="pg-title">{item.name}</span>
                </td>
                <td>
                  <span className="pg-status pg-status--published">Published</span>
                  {item.hasUnpublishedChanges ? <span className="pg-changes">unpublished changes</span> : null}
                </td>
                <td>{item.authorName || '—'}</td>
                <td>{new Date(item.updatedAt).toLocaleString()}</td>
                <td className="pg-actions">
                  {canEdit(item) ? (
                    <a className="ed-btn ed-btn--sm" href={`/editor/edit/?component=${item.id}`}>
                      Edit
                    </a>
                  ) : null}
                  {canEdit(item) ? (
                    <button type="button" className="ed-btn ed-btn--sm" onClick={() => setAction({ kind: 'rename', item })}>
                      Rename
                    </button>
                  ) : null}
                  {canDelete(item) ? (
                    <button type="button" className="ed-btn ed-btn--sm ed-btn--danger" onClick={() => setAction({ kind: 'delete', item })}>
                      Delete
                    </button>
                  ) : null}
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      )}
      {action?.kind === 'rename' ? (
        <NameDialog
          title="Rename component"
          initial={action.item.name}
          onClose={() => setAction(null)}
          onSubmit={async (name) => {
            await api.renameComponent(action.item.id, name);
            await load();
          }}
        />
      ) : null}
      {action?.kind === 'delete' ? (
        <ConfirmDialog
          title="Delete component?"
          onClose={() => setAction(null)}
          onConfirm={async () => {
            await api.deleteComponent(action.item.id);
            await load();
          }}
        >
          “{action.item.name}” will be deleted. Pages that use it show nothing in its place, including published pages. This cannot be undone.
        </ConfirmDialog>
      ) : null}
    </>
  );
}

export function TemplatesList({ session }: { session: SessionInfo | null }) {
  const [items, setItems] = useState<TemplateSummary[] | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [action, setAction] = useState<Action<TemplateSummary>>(null);
  const { canEdit, canDelete } = useOwnership(session);

  const load = useCallback(async () => {
    try {
      setItems((await api.listTemplates()).items);
      setError(null);
    } catch (e) {
      setError(messageOf(e));
    }
  }, []);
  useEffect(() => void load(), [load]);

  return (
    <>
      <div className="pg-head">
        <h1>Templates</h1>
      </div>
      <p className="pg-note">
        Saved sections and pages. Insert them from the editor’s template library; the built-in templates are always available there too.
      </p>
      {error ? (
        <p className="pg-error" role="alert">
          {error}
        </p>
      ) : null}
      {items === null ? (
        <p className="pg-note">Loading…</p>
      ) : items.length === 0 ? (
        <Empty icon="layers">No saved templates yet. In the editor, right-click a section and choose “Save as template”.</Empty>
      ) : (
        <table className="pg-table">
          <thead>
            <tr>
              <th>Name</th>
              <th>Type</th>
              <th>Author</th>
              <th>Created</th>
              <th aria-label="Actions" />
            </tr>
          </thead>
          <tbody>
            {items.map((item) => (
              <tr key={item.id}>
                <td>
                  <span className="pg-title">{item.name}</span>
                </td>
                <td>
                  <span className="pg-status">{item.kind === 'page' ? 'Page' : 'Section'}</span>
                </td>
                <td>{item.authorName || '—'}</td>
                <td>{new Date(item.createdAt).toLocaleString()}</td>
                <td className="pg-actions">
                  {canEdit(item) ? (
                    <button type="button" className="ed-btn ed-btn--sm" onClick={() => setAction({ kind: 'rename', item })}>
                      Rename
                    </button>
                  ) : null}
                  {canDelete(item) ? (
                    <button type="button" className="ed-btn ed-btn--sm ed-btn--danger" onClick={() => setAction({ kind: 'delete', item })}>
                      Delete
                    </button>
                  ) : null}
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      )}
      {action?.kind === 'rename' ? (
        <NameDialog
          title="Rename template"
          initial={action.item.name}
          onClose={() => setAction(null)}
          onSubmit={async (name) => {
            await api.renameTemplate(action.item.id, name);
            await load();
          }}
        />
      ) : null}
      {action?.kind === 'delete' ? (
        <ConfirmDialog
          title="Delete template?"
          onClose={() => setAction(null)}
          onConfirm={async () => {
            await api.deleteTemplate(action.item.id);
            await load();
          }}
        >
          “{action.item.name}” will be removed from the library. Pages made from it are not affected.
        </ConfirmDialog>
      ) : null}
    </>
  );
}

export function Empty({ icon, children }: { icon: string; children: ReactNode }) {
  return (
    <div className="pg-empty">
      <UiIcon name={icon} size={32} />
      <p>{children}</p>
    </div>
  );
}

function useEscape(onClose: () => void) {
  useEffect(() => {
    const onKey = (e: KeyboardEvent) => e.key === 'Escape' && onClose();
    window.addEventListener('keydown', onKey);
    return () => window.removeEventListener('keydown', onKey);
  }, [onClose]);
}

function DialogShell({
  title,
  onClose,
  onSubmit,
  submitLabel,
  danger,
  disabled,
  children,
}: {
  title: string;
  onClose: () => void;
  onSubmit: () => Promise<void>;
  submitLabel: string;
  danger?: boolean;
  disabled?: boolean;
  children: ReactNode;
}) {
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  useEscape(onClose);
  const submit = async (event: React.FormEvent) => {
    event.preventDefault();
    setBusy(true);
    setError(null);
    try {
      await onSubmit();
      onClose();
    } catch (e) {
      setError(messageOf(e));
    } finally {
      setBusy(false);
    }
  };
  return (
    <div className="ed-overlay" role="presentation" onClick={onClose}>
      <form className="ed-dialog" role="dialog" aria-modal="true" aria-labelledby="pg-lib-dialog" onClick={(e) => e.stopPropagation()} onSubmit={(e) => void submit(e)}>
        <h2 id="pg-lib-dialog">{title}</h2>
        {children}
        {error ? (
          <p className="pg-error" role="alert">
            {error}
          </p>
        ) : null}
        <footer>
          <button type="button" className="ed-btn" onClick={onClose}>
            Cancel
          </button>
          <button type="submit" className={`ed-btn ${danger ? 'ed-btn--danger' : 'ed-btn--primary'}`} disabled={busy || disabled}>
            {busy ? 'Working…' : submitLabel}
          </button>
        </footer>
      </form>
    </div>
  );
}

function NameDialog({ title, initial, onClose, onSubmit }: { title: string; initial: string; onClose: () => void; onSubmit: (name: string) => Promise<void> }) {
  const [name, setName] = useState(initial);
  return (
    <DialogShell title={title} onClose={onClose} onSubmit={() => onSubmit(name.trim())} submitLabel="Save" disabled={!name.trim()}>
      <label className="ed-field">
        <span className="ed-field-label">Name</span>
        <input className="ed-input" autoFocus required maxLength={120} value={name} onChange={(e) => setName(e.target.value)} />
      </label>
    </DialogShell>
  );
}

function ConfirmDialog({ title, onClose, onConfirm, children }: { title: string; onClose: () => void; onConfirm: () => Promise<void>; children: ReactNode }) {
  return (
    <DialogShell title={title} onClose={onClose} onSubmit={onConfirm} submitLabel="Delete" danger>
      <p>{children}</p>
    </DialogShell>
  );
}
