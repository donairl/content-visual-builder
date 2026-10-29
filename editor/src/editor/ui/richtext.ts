/**
 * Shared helpers for contentEditable rich text (inline canvas editing and the panel editor).
 * execCommand is deprecated but remains the only dependency-free way to apply formatting to a
 * live selection in every browser; output is sanitized before it is stored or rendered anyway.
 */
import { htmlToText, sanitizeHtml } from '@/core/sanitize';
import { isValidLinkUrl } from '@/core/validate';

export type RichCommand = 'bold' | 'italic' | 'underline' | 'insertUnorderedList' | 'insertOrderedList' | 'link' | 'unlink';

export function runCommand(command: RichCommand): void {
  if (command === 'link') {
    const url = window.prompt('Link URL (https://…, /path, mailto:…, tel:…)');
    if (url === null) return;
    const trimmed = url.trim();
    if (trimmed === '' || !isValidLinkUrl(trimmed)) {
      window.alert('That link is not allowed. Use http(s)://, a /relative path, #anchor, mailto: or tel:.');
      return;
    }
    document.execCommand('createLink', false, trimmed);
    return;
  }
  document.execCommand(command);
}

/** Paste handler: rich text keeps allowed markup only; plain-text fields get text only. */
export function handlePaste(event: ClipboardEvent | React.ClipboardEvent, kind: 'text' | 'html'): void {
  const data = event.clipboardData;
  if (!data) return;
  event.preventDefault();
  const html = data.getData('text/html');
  const text = data.getData('text/plain');
  if (kind === 'html') {
    const clean = html ? sanitizeHtml(html) : escapeText(text).replace(/\n/g, '<br>');
    document.execCommand('insertHTML', false, clean);
  } else {
    document.execCommand('insertText', false, (text || htmlToText(html)).replace(/\s*\n\s*/g, ' '));
  }
}

function escapeText(text: string): string {
  return text.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
}

/** Make Enter create <p> instead of <div> (which the sanitizer would unwrap). */
export function preferParagraphs(): void {
  try {
    document.execCommand('defaultParagraphSeparator', false, 'p');
  } catch {
    // Older engines ignore this; the sanitizer still produces valid markup.
  }
}
