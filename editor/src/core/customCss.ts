/**
 * Custom CSS check (schema/README.md §2 "Custom CSS"), mirrored by the backend's
 * ValueValidator::customCss(). Both must accept and reject exactly the same strings.
 *
 * Custom CSS is emitted inside a wrapper rule (`.cvb-root{…}`, `.cvb-el-{id}{…}`) and native CSS
 * nesting scopes it, so safety comes down to one property: the browser must see the same block
 * structure as this scanner, so nothing inside can close the wrapper. The scanner accepts a subset
 * of CSS in which every bracket the browser could treat as structural is visible and balanced:
 * comments and strings are skipped like the CSS tokenizer does, unquoted url() bodies may not
 * contain brackets or quotes, and escapes (which could hide a bracket) are only allowed in strings.
 */

const OPEN: Record<string, string> = { '(': ')', '[': ']', '{': '}' };
const CLOSE = new Set([')', ']', '}']);
const WHITESPACE = new Set([' ', '\t', '\n', '\r', '\f']);
const NEWLINES = new Set(['\n', '\r', '\f']);
const URL_FORBIDDEN = new Set(['"', "'", '(', '\\', '{', '}', '[', ']']);

function isControl(ch: string): boolean {
  const code = ch.codePointAt(0)!;
  return (code < 0x20 && !WHITESPACE.has(ch)) || code === 0x7f;
}

function isUrlStart(cps: string[], i: number): boolean {
  return (
    (cps[i] === 'u' || cps[i] === 'U') &&
    (cps[i + 1] === 'r' || cps[i + 1] === 'R') &&
    (cps[i + 2] === 'l' || cps[i + 2] === 'L') &&
    cps[i + 3] === '('
  );
}

/** `null` when the CSS is acceptable, otherwise why not. Length limits are checked by callers. */
export function customCssError(css: string): string | null {
  const cps = Array.from(css);
  for (const ch of cps) {
    if (ch === '<') return 'The character "<" is not allowed.';
    if (isControl(ch)) return 'Control characters are not allowed.';
  }

  const stack: string[] = [];
  let i = 0;
  while (i < cps.length) {
    const ch = cps[i]!;

    if (ch === '/' && cps[i + 1] === '*') {
      let end = -1;
      for (let j = i + 2; j + 1 < cps.length; j++) {
        if (cps[j] === '*' && cps[j + 1] === '/') {
          end = j;
          break;
        }
      }
      if (end === -1) return 'A comment is not closed.';
      i = end + 2;
      continue;
    }

    if (ch === '"' || ch === "'") {
      let j = i + 1;
      for (;;) {
        if (j >= cps.length) return 'A string is not closed.';
        const c = cps[j]!;
        if (c === ch) break;
        if (c === '\\') {
          if (j + 1 >= cps.length) return 'A string is not closed.';
          j += 2;
          continue;
        }
        if (NEWLINES.has(c)) return 'Line breaks inside strings must be escaped.';
        j++;
      }
      i = j + 1;
      continue;
    }

    if (ch === '\\') return 'Backslash escapes are only allowed inside strings.';

    if (isUrlStart(cps, i)) {
      let j = i + 4;
      while (j < cps.length && WHITESPACE.has(cps[j]!)) j++;
      if (cps[j] === '"' || cps[j] === "'") {
        // url("…") is an ordinary function holding a string.
        stack.push(')');
        i = j;
        continue;
      }
      for (;;) {
        if (j >= cps.length) return 'A url( is not closed.';
        const c = cps[j]!;
        if (c === ')') break;
        if (WHITESPACE.has(c)) {
          let k = j;
          while (k < cps.length && WHITESPACE.has(cps[k]!)) k++;
          if (cps[k] !== ')') return 'Unquoted url() values cannot contain spaces.';
          j = k;
          break;
        }
        if (URL_FORBIDDEN.has(c)) return 'Unquoted url() values cannot contain quotes, brackets or backslashes.';
        j++;
      }
      i = j + 1;
      continue;
    }

    if (OPEN[ch]) {
      stack.push(OPEN[ch]!);
    } else if (CLOSE.has(ch)) {
      if (stack.pop() !== ch) return `Unbalanced "${ch}".`;
    }
    i++;
  }

  return stack.length === 0 ? null : 'A bracket is not closed.';
}
