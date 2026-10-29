'use client';

/** Naming dialog for saving an element as a component, or an element/page as a template. */
import { useEffect, useState } from 'react';
import { widgets } from '@/core/schema';
import { useDispatch, useEditor } from '../context';
import { useServices } from '../services';

const MAX_NAME = 120;

export function Dialogs() {
  const dialog = useEditor((s) => s.dialog);
  const type = useEditor((s) => (s.dialog?.id ? s.history.present.nodes[s.dialog.id]?.type : undefined));
  const dispatch = useDispatch();
  const services = useServices();
  const [name, setName] = useState('');
  const [busy, setBusy] = useState(false);

  useEffect(() => {
    if (!dialog) return;
    setName(type ? (widgets[type]?.title ?? '') : '');
    const onKey = (e: KeyboardEvent) => e.key === 'Escape' && dispatch({ type: 'closeDialog' });
    window.addEventListener('keydown', onKey);
    return () => window.removeEventListener('keydown', onKey);
  }, [dialog, type, dispatch]);

  if (!dialog) return null;
  const close = () => dispatch({ type: 'closeDialog' });
  const component = dialog.kind === 'saveComponent';

  const submit = async (event: React.FormEvent) => {
    event.preventDefault();
    setBusy(true);
    const ok = component && dialog.id ? await services.saveAsComponent(dialog.id, name.trim()) : await services.saveAsTemplate(dialog.id, name.trim());
    setBusy(false);
    if (ok) close();
  };

  return (
    <div className="ed-overlay ed-ui" role="presentation" onClick={close}>
      <form className="ed-dialog" role="dialog" aria-modal="true" aria-labelledby="ed-dialog-title" onClick={(e) => e.stopPropagation()} onSubmit={(e) => void submit(e)}>
        <h2 id="ed-dialog-title">{component ? 'Save as component' : dialog.id ? 'Save section as template' : 'Save page as template'}</h2>
        <p>
          {component
            ? 'The element becomes a reusable component. Every copy you place follows changes made to the component.'
            : 'A copy is added to the template library. Changes to this page later do not affect the template.'}
        </p>
        <label className="ed-field">
          <span className="ed-field-label">Name</span>
          <input className="ed-input" autoFocus required maxLength={MAX_NAME} value={name} onChange={(e) => setName(e.target.value)} />
        </label>
        <footer>
          <button type="button" className="ed-btn" onClick={close}>
            Cancel
          </button>
          <button type="submit" className="ed-btn ed-btn--primary" disabled={busy || !name.trim()}>
            {busy ? 'Saving…' : 'Save'}
          </button>
        </footer>
      </form>
    </div>
  );
}
