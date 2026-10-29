/**
 * Local safety net (FR-12): every unsaved revision is mirrored to localStorage so a failed save,
 * a crash or a closed tab never loses work. Storage can be unavailable (private mode, quota), so
 * every access is guarded and failure only disables the safety net.
 */
import type { PageDocument } from '@/core/types';
import { validateDocument } from '@/core/validate';

export interface Backup {
  /** Page id, or component id when `kind` is `component` (README §10). */
  pageId: number;
  kind?: 'component';
  baseVersion: number;
  savedAt: number;
  document: PageDocument;
}

type Kind = 'page' | 'component';

const key = (pageId: number, kind: Kind = 'page') => (kind === 'component' ? `cvb:backup:component:${pageId}` : `cvb:backup:${pageId}`);

export function writeBackup(backup: Backup): boolean {
  try {
    localStorage.setItem(key(backup.pageId, backup.kind ?? 'page'), JSON.stringify(backup));
    return true;
  } catch {
    return false;
  }
}

export function readBackup(pageId: number, kind: Kind = 'page'): Backup | null {
  try {
    const raw = localStorage.getItem(key(pageId, kind));
    if (!raw) return null;
    const parsed = JSON.parse(raw) as Partial<Backup>;
    if (
      parsed.pageId !== pageId ||
      (parsed.kind ?? 'page') !== kind ||
      typeof parsed.baseVersion !== 'number' ||
      typeof parsed.savedAt !== 'number' ||
      validateDocument(parsed.document).length > 0
    ) {
      return null;
    }
    return parsed as Backup;
  } catch {
    return null;
  }
}

export function clearBackup(pageId: number, kind: Kind = 'page'): void {
  try {
    localStorage.removeItem(key(pageId, kind));
  } catch {
    // Nothing to clean up if storage is unavailable.
  }
}

/** Offers the document as a JSON download — the last resort when the server keeps refusing. */
export function downloadDocument(document: PageDocument, name: string): void {
  const blob = new Blob([JSON.stringify(document, null, 2)], { type: 'application/json' });
  const url = URL.createObjectURL(blob);
  const link = window.document.createElement('a');
  link.href = url;
  link.download = `${name || 'page'}.json`;
  link.click();
  setTimeout(() => URL.revokeObjectURL(url), 1000);
}
