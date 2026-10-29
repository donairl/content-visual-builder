'use client';

/**
 * Element context menu (right click on the canvas or a navigator row, or the toolbar's "more"
 * button). Every entry also has a keyboard shortcut or a panel equivalent; the menu only gathers
 * them in one place.
 */
import { useEffect, useLayoutEffect, useRef, useState, type KeyboardEvent as ReactKeyboardEvent } from 'react';
import { widgets } from '@/core/schema';
import { copyElement, pasteElement, pasteStyleInto } from '../clipboard';
import { useEditor, useStore, type EditorStore } from '../context';
import { useServices, type EditorServices } from '../services';
import { UiIcon } from './UiIcon';

interface MenuItem {
  label: string;
  icon: string;
  shortcut?: string;
  danger?: boolean;
  disabled?: boolean;
  run: (store: EditorStore, id: string, services: EditorServices) => void;
}

const MARGIN = 8;

export function ContextMenu() {
  const menu = useEditor((s) => s.menu);
  const hasClipboard = useEditor((s) => s.clipboard !== null);
  const type = useEditor((s) => (s.menu ? s.history.present.nodes[s.menu.id]?.type : undefined));
  const componentId = useEditor((s) => (s.menu ? s.history.present.nodes[s.menu.id]?.props.componentId : undefined));
  const editingComponent = useEditor((s) => s.page?.kind === 'component');
  const store = useStore();
  const services = useServices();
  const ref = useRef<HTMLDivElement>(null);
  const [position, setPosition] = useState<{ left: number; top: number } | null>(null);

  const close = () => store.dispatch({ type: 'closeMenu' });

  // Keep the menu inside the viewport, then move focus into it.
  useLayoutEffect(() => {
    const el = ref.current;
    if (!menu || !el) {
      setPosition(null);
      return;
    }
    const { width, height } = el.getBoundingClientRect();
    setPosition({
      left: Math.max(MARGIN, Math.min(menu.x, window.innerWidth - width - MARGIN)),
      top: Math.max(MARGIN, Math.min(menu.y, window.innerHeight - height - MARGIN)),
    });
    el.querySelector<HTMLButtonElement>('button:not(:disabled)')?.focus();
  }, [menu]);

  useEffect(() => {
    if (!menu) return;
    const onPointerDown = (e: PointerEvent) => {
      if (!ref.current?.contains(e.target as Node)) store.dispatch({ type: 'closeMenu' });
    };
    const onDismiss = () => store.dispatch({ type: 'closeMenu' });
    window.addEventListener('pointerdown', onPointerDown, true);
    window.addEventListener('resize', onDismiss);
    window.addEventListener('blur', onDismiss);
    document.addEventListener('scroll', onDismiss, true);
    return () => {
      window.removeEventListener('pointerdown', onPointerDown, true);
      window.removeEventListener('resize', onDismiss);
      window.removeEventListener('blur', onDismiss);
      document.removeEventListener('scroll', onDismiss, true);
    };
  }, [menu, store]);

  if (!menu || !type) return null;

  const items: (MenuItem | 'separator')[] = [
    { label: 'Copy', icon: 'copy', shortcut: 'Ctrl+C', run: (s, id) => copyElement(s, id) },
    { label: 'Paste', icon: 'clipboard', shortcut: 'Ctrl+V', disabled: !hasClipboard, run: (s) => pasteElement(s) },
    { label: 'Paste style', icon: 'droplet', shortcut: 'Ctrl+Shift+V', disabled: !hasClipboard, run: (s, id) => pasteStyleInto(s, id) },
    { label: 'Reset style', icon: 'reset', run: (s, id) => s.dispatch({ type: 'resetStyle', id }) },
    'separator',
    ...(type === 'component'
      ? [
          { label: 'Edit component', icon: 'external', run: () => typeof componentId === 'number' && services.editComponent(componentId) },
          { label: 'Detach from component', icon: 'box', run: (_s: EditorStore, id: string, sv: EditorServices) => sv.detachComponent(id) },
        ]
      : editingComponent
        ? []
        : [{ label: 'Save as component…', icon: 'box', run: (s: EditorStore, id: string) => s.dispatch({ type: 'openDialog', kind: 'saveComponent', id }) }]),
    ...(editingComponent
      ? []
      : [{ label: 'Save as template…', icon: 'layers', run: (s: EditorStore, id: string) => s.dispatch({ type: 'openDialog', kind: 'saveTemplate', id }) }]),
    'separator',
    { label: 'Duplicate', icon: 'copy', shortcut: 'Ctrl+D', run: (s, id) => s.dispatch({ type: 'duplicate', id }) },
    { label: 'Delete', icon: 'trash', shortcut: 'Del', danger: true, run: (s, id) => s.dispatch({ type: 'remove', id }) },
  ];

  const activate = (item: MenuItem) => {
    close();
    // Actions like "paste" work relative to the selection, so the menu's element becomes selected.
    store.dispatch({ type: 'select', id: menu.id });
    item.run(store, menu.id, services);
  };

  const onKeyDown = (e: ReactKeyboardEvent<HTMLDivElement>) => {
    const buttons = [...(ref.current?.querySelectorAll<HTMLButtonElement>('button:not(:disabled)') ?? [])];
    const index = buttons.indexOf(document.activeElement as HTMLButtonElement);
    const focus = (i: number) => buttons[(i + buttons.length) % buttons.length]?.focus();
    if (e.key === 'Escape' || e.key === 'Tab') {
      e.preventDefault();
      e.stopPropagation();
      close();
    } else if (e.key === 'ArrowDown') {
      e.preventDefault();
      focus(index + 1);
    } else if (e.key === 'ArrowUp') {
      e.preventDefault();
      focus(index - 1);
    } else if (e.key === 'Home') {
      e.preventDefault();
      focus(0);
    } else if (e.key === 'End') {
      e.preventDefault();
      focus(buttons.length - 1);
    }
  };

  return (
    <div
      ref={ref}
      className="ed-menu ed-ui"
      role="menu"
      aria-label={`${widgets[type]?.title ?? type} actions`}
      style={{ left: position?.left ?? menu.x, top: position?.top ?? menu.y, visibility: position ? 'visible' : 'hidden' }}
      onKeyDown={onKeyDown}
      onContextMenu={(e) => e.preventDefault()}
    >
      {items
        // Sections can be empty for some elements: never show two separators in a row.
        .filter((item, i) => item !== 'separator' || (i > 0 && items[i - 1] !== 'separator'))
        .map((item, i) =>
        item === 'separator' ? (
          <hr key={`sep:${i}`} role="separator" />
        ) : (
          <button
            key={item.label}
            type="button"
            role="menuitem"
            className={item.danger ? 'ed-menu-danger' : undefined}
            disabled={item.disabled}
            onClick={() => activate(item)}
          >
            <UiIcon name={item.icon} size={14} />
            <span>{item.label}</span>
            {item.shortcut ? <kbd>{item.shortcut}</kbd> : null}
          </button>
        ),
      )}
    </div>
  );
}

/**
 * Opens the menu for `id` on right click. With text selected inside the element (inline editing)
 * the browser's own menu is kept, for copying and spelling suggestions.
 */
export function contextMenuHandler(store: EditorStore, id: string) {
  return (e: React.MouseEvent) => {
    const selection = window.getSelection();
    if (selection && !selection.isCollapsed && selection.anchorNode && e.currentTarget.contains(selection.anchorNode)) {
      e.stopPropagation();
      return;
    }
    e.preventDefault();
    e.stopPropagation();
    store.dispatch({ type: 'select', id });
    store.dispatch({ type: 'openMenu', id, x: e.clientX, y: e.clientY });
  };
}
