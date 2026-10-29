/**
 * Canonical DOM form used by parity tests: element names, attributes sorted by name, text kept
 * verbatim, whitespace-only text nodes dropped. Two HTML strings that parse to the same DOM give
 * the same canonical string regardless of attribute order or entity spelling.
 */
export function canonicalHtml(html: string): string {
  const doc = new DOMParser().parseFromString(`<!DOCTYPE html><body>${html}`, 'text/html');
  return canonicalChildren(doc.body);
}

function canonicalChildren(node: Node): string {
  return Array.from(node.childNodes).map(canonicalNode).join('');
}

function canonicalNode(node: Node): string {
  if (node.nodeType === Node.TEXT_NODE) {
    const text = node.textContent ?? '';
    return text.trim() === '' ? '' : JSON.stringify(text);
  }
  if (node.nodeType !== Node.ELEMENT_NODE) return '';
  const element = node as Element;
  const attrs = Array.from(element.attributes)
    .map((a) => `${a.name}=${JSON.stringify(a.value)}`)
    .sort()
    .join(' ');
  return `<${element.localName}${attrs ? ` ${attrs}` : ''}>${canonicalChildren(element)}</${element.localName}>`;
}
