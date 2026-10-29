/**
 * Rich-text allowlist sanitizer (schema/README.md §6), the browser twin of the backend's
 * RichTextSanitizer. Used before stored HTML enters the canvas — another user may have written it —
 * and on paste. Parsing happens in an inert DOMParser document, so nothing executes or loads.
 */
import { isValidLinkUrl } from './validate';

const ALLOWED = new Set(['p', 'br', 'strong', 'b', 'em', 'i', 'u', 's', 'a', 'ul', 'ol', 'li', 'blockquote', 'code']);
const DROPPED = new Set([
  'script', 'style', 'iframe', 'object', 'embed', 'template', 'noscript', 'svg', 'math',
  'form', 'input', 'textarea', 'select', 'button',
]);
const HTML_NS = 'http://www.w3.org/1999/xhtml';

function copyClean(source: Node, target: Node, doc: Document): void {
  for (const child of Array.from(source.childNodes)) {
    if (child.nodeType === Node.TEXT_NODE) {
      target.appendChild(doc.createTextNode(child.textContent ?? ''));
      continue;
    }
    if (child.nodeType !== Node.ELEMENT_NODE) continue;
    const element = child as Element;
    const tag = element.localName;
    if (element.namespaceURI !== HTML_NS || DROPPED.has(tag)) continue;
    if (!ALLOWED.has(tag)) {
      copyClean(element, target, doc);
      continue;
    }
    const clean = doc.createElement(tag);
    if (tag === 'a') {
      const href = element.getAttribute('href');
      if (href && isValidLinkUrl(href)) clean.setAttribute('href', href);
      if (element.getAttribute('target') === '_blank') {
        clean.setAttribute('target', '_blank');
        clean.setAttribute('rel', 'noopener noreferrer');
      }
    }
    copyClean(element, clean, doc);
    target.appendChild(clean);
  }
}

export function sanitizeHtml(html: string): string {
  const parsed = new DOMParser().parseFromString(`<!DOCTYPE html><body>${html}`, 'text/html');
  const output = document.implementation.createHTMLDocument('');
  const container = output.createElement('div');
  copyClean(parsed.body, container, output);
  return container.innerHTML;
}

/** Plain text of an HTML snippet (used when pasting into plain-text fields). */
export function htmlToText(html: string): string {
  return new DOMParser().parseFromString(`<!DOCTYPE html><body>${html}`, 'text/html').body.textContent ?? '';
}
